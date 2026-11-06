<?php
// 错误仅包含固定代码，禁止记录响应正文、链接、curl 诊断或凭证。
class SubBoostClient
{
    private $url;
    private $secret;
    private $connectTo=null;
    public static function settings($config=array())
    {
        $fallback=isset($config['subboost'])?$config['subboost']:array();
        $url=getenv('SUBBOOST_URL');$secret=getenv('SUBBOOST_API_SECRET');
        return array('url'=>rtrim(trim($url!==false?$url:(isset($fallback['url'])?$fallback['url']:'')),'/'),
            'api_secret'=>$secret!==false?$secret:(isset($fallback['api_secret'])?$fallback['api_secret']:''));
    }
    public static function enabled($config=array())
    {
        $s=self::settings($config);return $s['url']!==''&&$s['api_secret']!=='';
    }
    public function __construct($config=array())
    {
        $s=self::settings($config);$this->url=$s['url'];$this->secret=$s['api_secret'];
        $p=parse_url($this->url);
        if(!$p||empty($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])||
            !isset($p['scheme'])||($p['scheme']!=='https'&&!($p['scheme']==='http'&&in_array(strtolower($p['host']),array('127.0.0.1','[::1]','localhost'),true)))||
            (isset($p['path'])&&$p['path']!==''&&$p['path']!=='/')||$this->secret===''||preg_match('/[\r\n]/',$this->secret))throw new RuntimeException('invalid_configuration');
        $host=getenv('SUBBOOST_CONNECT_HOST');
        if($host!==false&&$host!==''){
            // 只接受裸 IP 或 DNS hostname，禁止 URL、端口、凭证、空白和控制字符。
            $ip=filter_var($host,FILTER_VALIDATE_IP);
            if($ip===false&&(strlen($host)>253||!preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*\.?\z/D',$host)))throw new RuntimeException('invalid_configuration');
            $destination=strpos($host,':')!==false?'['.$host.']':$host;
            $port=isset($p['port'])?$p['port']:($p['scheme']==='https'?443:80);
            $this->connectTo=array($p['host'].':'.$port.':'.$destination.':'.$port);
        }
    }
    public function sourceHash(){return hash('sha256',$this->url);}
    public function create($request){return $this->request('POST','/api/shop/subscriptions',$request);}
    public function status($no){return $this->request('GET','/api/shop/subscriptions/'.rawurlencode($no));}
    private function request($method,$path,$body=null)
    {
        if($this->connectTo!==null&&!defined('CURLOPT_CONNECT_TO'))throw new RuntimeException('invalid_configuration');
        $ch=curl_init($this->url.$path);$response='';
        // CONNECT_TO 不改 URL、HTTP Host 或 TLS 身份，端口沿用公开 URL。
        if($this->connectTo!==null)curl_setopt($ch,CURLOPT_CONNECT_TO,$this->connectTo);
        curl_setopt_array($ch,array(CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>array('Authorization: Bearer '.$this->secret,'Accept: application/json','Content-Type: application/json'),
            CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_WRITEFUNCTION=>function($ch,$chunk)use(&$response){if(strlen($response)+strlen($chunk)>65536)return 0;$response.=$chunk;return strlen($chunk);}));
        if($body!==null){$json=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json===false)throw new RuntimeException('invalid_request');curl_setopt($ch,CURLOPT_POSTFIELDS,$json);}
        $ok=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($ok===false)throw new RuntimeException('http_transport_failed');
        if($code!==200)throw new RuntimeException('http_'.$code);
        $data=json_decode($response,true);
        if(!is_array($data))throw new RuntimeException('invalid_response');
        return $data;
    }
    public function validate($data,$orderNo)
    {
        if(!is_array($data)||!isset($data['orderNo'])||$data['orderNo']!==$orderNo||!isset($data['subscriptionId'])||
            (!is_string($data['subscriptionId'])&&!is_int($data['subscriptionId']))||!preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D',(string)$data['subscriptionId'])||
            !isset($data['status'])||!in_array($data['status'],array('pending_configuration','ready'),true))throw new RuntimeException('invalid_response');
        foreach(array('subscriptionUrl','openedAt','expiresAt') as $field)if(!array_key_exists($field,$data))throw new RuntimeException('invalid_response');
        if($data['status']==='pending_configuration'){
            if($data['subscriptionUrl']!==null||$data['openedAt']!==null||$data['expiresAt']!==null)throw new RuntimeException('invalid_response');
        }else{
            $this->validateSubscriptionUrl($data['subscriptionUrl']);
            $opened=$this->parseInstant($data['openedAt']);$expires=$this->parseInstant($data['expiresAt']);
            if($expires<=$opened)throw new RuntimeException('invalid_dates');
        }
        $data['subscriptionId']=(string)$data['subscriptionId'];return $data;
    }
    public function validateSubscriptionUrl($url)
    {
        if(!is_string($url)||strlen($url)>4096||preg_match('/[\x00-\x20\x7f\\\\]/',$url))throw new RuntimeException('invalid_subscription_url');
        $p=parse_url($url);$base=parse_url($this->url);
        if(!$p||isset($p['user'])||isset($p['pass'])||isset($p['fragment'])||isset($p['query'])||
            !isset($p['scheme'],$p['host'],$p['path'])||strtolower($p['host'])!==strtolower($base['host'])||$p['scheme']!==$base['scheme']||
            (isset($p['port'])?$p['port']:($p['scheme']==='https'?443:80))!==(isset($base['port'])?$base['port']:($base['scheme']==='https'?443:80))||
            !preg_match('~\A/api/subscriptions/[A-Za-z0-9_-]+/config\.yaml\z~D',$p['path']))throw new RuntimeException('invalid_subscription_url');
    }
    // 仅为当前配置的规范订阅地址生成客户端链接，历史链接原样保留。
    public static function clientUrls($url,$config=array())
    {
        // PHP 7 的 parse_url 会忽略空查询或片段，额外拒绝分隔符，避免改坏历史地址。
        if(!is_string($url)||strpbrk($url,'?#')!==false)return array('legacy'=>$url);
        try{$client=new self($config);$client->validateSubscriptionUrl($url);}catch(RuntimeException $e){return array('legacy'=>$url);}
        $base=substr($url,0,-strlen('/config.yaml'));
        $urls=array('auto'=>$base.'/auto');
        foreach(array('mihomo','clash','sing-box','v2ray','surge','surfboard','quantumult-x','uri','base64') as $format)$urls[$format]=$base.'/config?format='.$format;
        $urls['legacy']=$url;return $urls;
    }
    public function parseInstant($value)
    {
        if(!is_string($value)||!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/D',$value))throw new RuntimeException('invalid_dates');
        try{$date=new DateTimeImmutable($value);$errors=DateTimeImmutable::getLastErrors();if($errors!==false&&($errors['warning_count']||$errors['error_count']))throw new RuntimeException('invalid_dates');return $date;}catch(Exception $e){throw new RuntimeException('invalid_dates');}
    }
}
