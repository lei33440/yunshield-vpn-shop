<?php
// 纯离线：cURL 替身捕获实际 request 参数；不加载配置、不联网、不连接数据库。
namespace SubBoostTransportTest;
if(PHP_SAPI!=='cli')exit(1);
function ok($condition,$label){if(!$condition)throw new \RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
$root=dirname(__DIR__);
foreach(array('CURLOPT_CONNECT_TO','CURLOPT_CUSTOMREQUEST','CURLOPT_HTTPHEADER','CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT','CURLOPT_FOLLOWLOCATION','CURLOPT_MAXREDIRS','CURLOPT_PROTOCOLS','CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLOPT_WRITEFUNCTION','CURLOPT_POSTFIELDS','CURLINFO_HTTP_CODE','CURLPROTO_HTTP','CURLPROTO_HTTPS') as $i=>$name)if(!defined($name))define($name,10000+$i);
function curl_init($url){$ch=(object)array('url'=>$url,'options'=>array());$GLOBALS['transport_capture']=$ch;return $ch;}
function curl_setopt($ch,$key,$value){$ch->options[$key]=$value;return true;}
function curl_setopt_array($ch,$options){foreach($options as $key=>$value)curl_setopt($ch,$key,$value);return true;}
function curl_exec($ch){$response=json_encode($GLOBALS['transport_response']);$write=$ch->options[CURLOPT_WRITEFUNCTION];return $write($ch,$response)===strlen($response);}
function curl_getinfo($ch,$key){return 200;}
function curl_close($ch){}
// 仅为测试给原始类加命名空间，调用真实构造器、request、校验及链接生成逻辑。
$source=file_get_contents($root.'/app/Services/SubBoostClient.php');
eval('namespace SubBoostTransportTest; use RuntimeException; use DateTimeImmutable; use Exception;'.substr($source,5));
$names=array('SUBBOOST_URL','SUBBOOST_API_SECRET','SUBBOOST_CONNECT_HOST');$saved=array();
foreach($names as $name){$saved[$name]=getenv($name);putenv($name);}
try{
    $url='http://127.0.0.1:9022';$public=$url.'/api/subscriptions/fixture_token/config.yaml';
    $config=array('subboost'=>array('url'=>$url,'api_secret'=>'fixture-only'));
    $GLOBALS['transport_response']=array('orderNo'=>'fixture-order','subscriptionId'=>'fixture-id','status'=>'ready','subscriptionUrl'=>$public,'openedAt'=>'2026-01-01T00:00:00Z','expiresAt'=>'2026-02-01T00:00:00Z');
    $original=new SubBoostClient($config);$hash=$original->sourceHash();$links=SubBoostClient::clientUrls($public,$config);
    foreach(array(null,'','host.docker.internal','127.0.0.2','::1','2001:db8::1','worker.example.test') as $host){
        putenv($host===null?'SUBBOOST_CONNECT_HOST':'SUBBOOST_CONNECT_HOST='.$host);
        $client=new SubBoostClient($config);
        foreach(array('create','status') as $method){
            $data=$method==='create'?$client->create(array('orderNo'=>'fixture-order')):$client->status('fixture-order');
            $ch=$GLOBALS['transport_capture'];$options=$ch->options;
            $path='/api/shop/subscriptions'.($method==='status'?'/fixture-order':'');
            ok($ch->url===$url.$path,'original public request URL '.$method);
            if($host===null||$host==='')ok(!isset($options[CURLOPT_CONNECT_TO]),'unset/empty keeps original transport');
            else{
                $destination=strpos($host,':')!==false?'['.$host.']':$host;
                ok($options[CURLOPT_CONNECT_TO]===array('127.0.0.1:9022:'.$destination.':9022'),'only TCP hostname overridden; original port retained');
            }
            ok($options[CURLOPT_HTTPHEADER]===array('Authorization: Bearer fixture-only','Accept: application/json','Content-Type: application/json'),'no Host override; headers unchanged');
            ok($options[CURLOPT_FOLLOWLOCATION]===false&&$options[CURLOPT_SSL_VERIFYPEER]===true&&$options[CURLOPT_SSL_VERIFYHOST]===2,'redirect/TLS protections retained');
            ok($client->sourceHash()===$hash&&$hash===hash('sha256',$url),'public source hash unchanged');
            ok($client->validate($data,'fixture-order')['subscriptionUrl']===$public&&SubBoostClient::clientUrls($public,$config)===$links,'public subscription identity and all client URLs unchanged');
        }
    }
    putenv('SUBBOOST_CONNECT_HOST=host.docker.internal');
    foreach(array('https://public.example.test','http://localhost','http://[::1]:9022') as $base){
        $case=array('subboost'=>array('url'=>$base,'api_secret'=>'fixture-only'));
        $client=new SubBoostClient($case);$client->status('fixture-order');$p=parse_url($base);$port=$p['port']??($p['scheme']==='https'?443:80);
        ok($GLOBALS['transport_capture']->options[CURLOPT_CONNECT_TO]===array($p['host'].':'.$port.':host.docker.internal:'.$port),'default/IPv6 source ports retained');
        ok($client->sourceHash()===hash('sha256',$base),'HTTPS/IPv6 public identity retained');
    }
    $invalid=array('http://host.docker.internal','https://example.test','host/path','host\\path','user@host','user:pass@host','host:9022','127.0.0.1:9022','[::1]:9022','[::1]',' host','host ',"host\n","host\r", "host\t", "host\x01", "host\x1f", "host\x7f",'host?x','host#x','host,other','host_name','-host','host-','a..b','.',str_repeat('a',64).'.test',str_repeat('a.',127).'test');
    foreach($invalid as $i=>$host){
        putenv('SUBBOOST_CONNECT_HOST='.$host);
        try{new SubBoostClient($config);throw new \LogicException('Expected invalid_configuration');}
        catch(\RuntimeException $e){ok($e->getMessage()==='invalid_configuration','unsafe host rejected with fixed error '.$i);}
    }
    putenv('SUBBOOST_CONNECT_HOST=host.docker.internal');
    try{(new SubBoostClient($config))->validateSubscriptionUrl('http://host.docker.internal:9022/api/subscriptions/fixture_token/config.yaml');throw new \LogicException('Expected invalid_subscription_url');}
    catch(\RuntimeException $e){ok($e->getMessage()==='invalid_subscription_url','transport destination never accepted as public identity');}
    // Compose 只读解析；捕获完整输出而不打印（展开结果含本地环境值）。
    $process=proc_open(array('docker','compose','-f',$root.'/docker-compose.yml','config','--format','json'),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,$root);
    if(!is_resource($process))throw new \RuntimeException('Compose validation unavailable');
    fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
    ok($code===0,'docker compose config parses successfully (output suppressed)');
    $compose=json_decode($stdout,true);$app=$compose['services']['app'];$worker=$compose['services']['subboost-worker'];
    ok($worker['build']===$app['build']&&$worker['volumes']===$app['volumes'],'worker reuses app build and directory mount');
    $env=$worker['environment'];unset($env['SUBBOOST_CONNECT_HOST']);
    ok($env===$app['environment'],'worker inherits app environment without duplicated credentials');
    ok($worker['environment']['SUBBOOST_CONNECT_HOST']==='host.docker.internal'&&!isset($worker['environment']['SUBBOOST_URL']),'transport-only Docker hostname; public config untouched');
    ok($worker['command']===array('php','scripts/subboost_worker.php','--loop')&&$worker['restart']==='unless-stopped','persistent direct PHP worker with Docker restart');
    ok($worker['depends_on']['db']['condition']==='service_healthy'&&!isset($worker['ports']),'healthy database dependency and no published worker port');
    echo "SUBBOOST TRANSPORT AND COMPOSE TESTS PASSED\n";
}finally{
    foreach($saved as $name=>$value)putenv($value===false?$name:$name.'='.$value);
}
