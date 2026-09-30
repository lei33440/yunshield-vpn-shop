<?php
class PaymentService
{
    private $pdo;
    private $config;
    public function __construct($pdo, $config)
    {
        $this->pdo = $pdo;
        $this->config = $config;
    }
    public function settings()
    {
        $row = $this->pdo->query('SELECT * FROM payment_settings ORDER BY id LIMIT 1')->fetch();
        if (!$row) return array('gateway_url'=>'https://pae.fksqy.cn/','pid'=>'','md5_key'=>'','enabled'=>0,'alipay_enabled'=>1,'wechat_enabled'=>1,'notify_base_url'=>'');
        $row['md5_key'] = '';
        if (!empty($row['md5_key_ciphertext'])) {
            $row['md5_key'] = (new CryptoService($this->config))->decrypt($row['md5_key_ciphertext'],$row['md5_key_nonce'],$row['md5_key_tag']);
        }
        return $row;
    }
    public static function sign($params, $key)
    {
        $items = array();
        foreach ($params as $name=>$value) {
            if ($name==='sign'||$name==='sign_type'||$value===''||$value===null) continue;
            $items[$name] = (string)$value;
        }
        ksort($items, SORT_STRING);
        $parts=array(); foreach($items as $name=>$value)$parts[]=$name.'='.$value;
        return strtolower(md5(implode('&',$parts).$key));
    }
    private function baseUrl($url)
    {
        $url=rtrim(trim($url),'/');
        if (!filter_var($url,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),array('http','https'),true)) throw new RuntimeException('支付网关必须使用 HTTP 或 HTTPS 地址。');
        return $url;
    }
    private function callbackUrl($base,$path)
    {
        $base=rtrim(trim($base),'/');
        if (!filter_var($base,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($base,PHP_URL_SCHEME)),array('http','https'),true)) throw new RuntimeException('请先在支付设置中填写公网 HTTP 或 HTTPS 回调地址。');
        $host=strtolower((string)parse_url($base,PHP_URL_HOST));
        if ($host==='localhost'||$host==='127.0.0.1'||$host==='::1'||strpos($host,'192.168.')===0||strpos($host,'10.')===0) throw new RuntimeException('回调地址不能使用本地或内网地址。');
        return $base.$path;
    }
    public function createPayment($orderNo,$userId,$type)
    {
        if (!in_array($type,array('alipay','wxpay'),true)) throw new InvalidArgumentException('不支持的支付方式。');
        $settings=$this->settings();
        if (empty($settings['enabled'])||$settings['pid']===''||$settings['md5_key']==='') throw new RuntimeException('在线支付尚未配置完成，请联系管理员。');
        if ($type==='alipay'&&!$settings['alipay_enabled'] || $type==='wxpay'&&!$settings['wechat_enabled']) throw new RuntimeException('该支付方式暂未启用。');
        $notify=$this->callbackUrl($settings['notify_base_url'],'/payment/notify');
        $return=$this->callbackUrl($settings['notify_base_url'],'/payment/return');
        $s=$this->pdo->prepare('SELECT * FROM orders WHERE order_no=? AND user_id=? FOR UPDATE');
        $this->pdo->beginTransaction();
        try {
            $s->execute(array($orderNo,$userId)); $order=$s->fetch();
            if (!$order||$order['status']!=='pending_payment') throw new RuntimeException('当前订单不能发起在线支付。');
            $money=number_format(((int)$order['total_amount_cents'])/100,2,'.','');
            $gatewayOrderNo=substr($orderNo.'-'.strtolower($type).'-'.date('His').strtoupper(bin2hex(random_bytes(3))),0,64);
            $params=array('pid'=>$settings['pid'],'type'=>$type,'out_trade_no'=>$gatewayOrderNo,'notify_url'=>$notify,'return_url'=>$return.'?payment_order='.rawurlencode($gatewayOrderNo),'name'=>substr($order['product_name_snapshot'].' '.$order['plan_name_snapshot'],0,120),'money'=>$money,'sign_type'=>'MD5');
            $params['sign']=self::sign($params,$settings['md5_key']);
            $pageUrl=$this->baseUrl($settings['gateway_url']).'/submit.php?'.http_build_query($params,'','&');
            $paymentUrl=$pageUrl;$qrcode='';$scheme='';$result=array('code'=>1);
            $now=date('Y-m-d H:i:s');$displayUrl=$paymentUrl?:($qrcode?:$scheme);
            $this->pdo->prepare('INSERT INTO payment_records(order_id,method,amount_cents,status,gateway_trade_no,gateway_order_no,gateway_type,payment_url,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute(array($order['id'],'epay_'.$type,$order['total_amount_cents'],'pending',isset($result['trade_no'])?$result['trade_no']:'',$gatewayOrderNo,$type,$displayUrl,$now,$now));
            $this->pdo->prepare('UPDATE orders SET payment_started_at=?,updated_at=? WHERE id=?')->execute(array($now,$now,$order['id']));
            $this->pdo->commit();return array('url'=>$paymentUrl,'qrcode'=>$qrcode,'urlscheme'=>$scheme,'trade_no'=>isset($result['trade_no'])?$result['trade_no']:'');
        }catch(Exception $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function handleNotify($params)
    {
        $settings=$this->settings();
        if (!$settings['pid']||!$settings['md5_key']||empty($settings['enabled'])) throw new RuntimeException('payment disabled');
        if (!isset($params['pid'],$params['out_trade_no'],$params['money'],$params['trade_status'],$params['sign'])|| (string)$params['pid']!==(string)$settings['pid']) throw new RuntimeException('invalid payment notification');
        if ((string)$params['trade_status']!=='TRADE_SUCCESS'||self::sign($params,$settings['md5_key'])!==strtolower((string)$params['sign'])) throw new RuntimeException('invalid payment notification');
        $gatewayOrderNo=(string)$params['out_trade_no'];$money=$this->moneyCents($params['money']);
        $s=$this->pdo->prepare('SELECT o.*,p.id payment_id,p.status payment_status,p.amount_cents,p.gateway_trade_no,p.gateway_order_no FROM orders o JOIN payment_records p ON p.order_id=o.id AND p.gateway_order_no=? LIMIT 1');$s->execute(array($gatewayOrderNo));$row=$s->fetch();
        if(!$row||$money!==(int)$row['total_amount_cents']||!$row['payment_id']||$money!==(int)$row['amount_cents'])throw new RuntimeException('invalid payment notification');
        if($row['status']!=='pending_payment') return $row['payment_status']==='confirmed' ? true : false;
        if(!in_array(isset($params['type'])?$params['type']:'',array('alipay','wxpay'),true))throw new RuntimeException('invalid payment type');
        $raw=substr(json_encode($params,JSON_UNESCAPED_UNICODE),0,60000);$trade=isset($params['trade_no'])?(string)$params['trade_no']:'';
        (new OrderService($this->pdo))->confirmPaymentSystem($row['order_no'],(string)$params['type'],$trade,$raw);
        return true;
    }
    private function moneyCents($value)
    {
        $value=trim((string)$value);if(!preg_match('/^\d+(?:\.\d{1,2})?$/',$value))throw new RuntimeException('invalid payment amount');$parts=explode('.',$value);return ((int)$parts[0])*100+(int)str_pad(isset($parts[1])?$parts[1]:'',2,'0');
    }
}
