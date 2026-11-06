<?php
// 仅接受显式指定的隔离 MySQL 库，不读取真实 config.php、不请求支付网关。
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);
$dsn=getenv('CHAOXI_TEST_DSN');$names=array();
foreach($dsn&&strpos($dsn,'mysql:')===0?explode(';',substr($dsn,6)):array() as $part)if(preg_match('/^\s*dbname\s*=(.*)$/i',$part,$m))$names[]=$m[1];
if(count($names)!==1||!preg_match('/\Achaoxi_wallet_test_[A-Za-z0-9_]+\z/D',$names[0]))throw new RuntimeException('Explicit isolated dbname required');
$pdo=new PDO($dsn,getenv('CHAOXI_TEST_USER'),getenv('CHAOXI_TEST_PASSWORD'),array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false));
if($pdo->query('SELECT DATABASE()')->fetchColumn()!==$names[0])throw new RuntimeException('Actual database mismatch');
spl_autoload_register(function($class)use($root){foreach(array('/app/Services/','/app/Core/') as $dir)if(is_file($f=$root.$dir.$class.'.php')){require_once $f;return;}});
// 固定的测试专用密钥支持分进程执行 --finish，生产环境绝不使用。
$config=array('security'=>array('encryption_key'=>hash('sha256','isolated-subboost-fixture:'.$names[0])),'app'=>array('timezone'=>'Asia/Shanghai'));
date_default_timezone_set('Asia/Shanghai');
function sb_live_assert($condition,$name){if(!$condition)throw new RuntimeException('FAIL '.$name);}
$mode=isset($argv[1])?$argv[1]:'';
if($mode==='--prepare'){
    sb_live_assert(SubBoostClient::enabled(),'SubBoost configuration required');
    sb_live_assert((int)$pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn()===0,'prepare requires empty database');
    require $root.'/scripts/migrate.php';run_migrations($pdo,$root);run_migrations($pdo,$root);
    $pdo->exec('INSERT INTO users(email,password_hash,created_at,updated_at) VALUES("subboost-test@example.test","fixture",NOW(),NOW())');$uid=$pdo->lastInsertId();
    $pdo->exec('INSERT INTO admin_users(email,password_hash,created_at,updated_at) VALUES("admin-test@example.test","fixture",NOW(),NOW())');$aid=$pdo->lastInsertId();
    $pdo->exec('INSERT INTO products(name,description,traffic,region,duration_days,price_cents,created_at,updated_at) VALUES("SubBoost integration fixture","fixture","10G","test",30,100,NOW(),NOW())');$pid=$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO product_plans(product_id,name,duration_days,price_cents,created_at,updated_at) VALUES(?,"30 days",30,100,NOW(),NOW())')->execute(array($pid));$plan=$pdo->lastInsertId();
    $svc=new OrderService($pdo);$no=$svc->create($uid,$pid,$plan,'isolated integration test');$svc->confirmPayment($no,$aid);$svc->confirmPayment($no,$aid);
    $worker=new SubBoostSyncService($pdo,$config);$result=$worker->runOnce();sb_live_assert($result['errors']===0,'initial sync');
    $task=$pdo->query('SELECT * FROM subboost_sync_tasks')->fetch();sb_live_assert($task&&$task['subscription_id']!==null,'subscription mapped');
    $request=json_decode($task['request_json'],true);$request['subscriptionId']=$task['subscription_id'];echo json_encode($request,JSON_UNESCAPED_UNICODE).PHP_EOL;
}elseif($mode==='--finish'&&isset($argv[2])){
    $s=$pdo->prepare('SELECT o.*,t.id task_id,t.subscription_id,t.opened_at sync_opened,t.expires_at sync_expires FROM orders o JOIN subboost_sync_tasks t ON t.order_id=o.id WHERE o.order_no=?');$s->execute(array($argv[2]));$o=$s->fetch();sb_live_assert((bool)$o,'fixture order exists');
    $pdo->prepare('UPDATE subboost_sync_tasks SET available_at=UTC_TIMESTAMP() WHERE id=?')->execute(array($o['task_id']));
    $worker=new SubBoostSyncService($pdo,$config);$result=$worker->runOnce();sb_live_assert($result['errors']===0,'ready sync');
    $svc=new OrderService($pdo);$payload=$svc->getDeliveryForAdmin($o['order_no'],$config);sb_live_assert(is_array($payload),'encrypted delivery exists');
    $client=new SubBoostClient();$remote=$client->validate($client->status($o['order_no']),$o['order_no']);
    sb_live_assert($remote['status']==='ready'&&$remote['subscriptionId']===$o['subscription_id'],'remote mapping matches');
    $tz=new DateTimeZone($config['app']['timezone']);
    sb_live_assert($payload['subscription']===$remote['subscriptionUrl'],'delivery URL matches');
    sb_live_assert($payload['opened_at']===$client->parseInstant($remote['openedAt'])->setTimezone($tz)->format('Y-m-d H:i:s')&&$payload['expires_at']===$client->parseInstant($remote['expiresAt'])->setTimezone($tz)->format('Y-m-d H:i:s'),'confirmation timestamps preserved');
    $worker->runOnce();$worker->runOnce();
    $s=$pdo->prepare('SELECT * FROM delivery_records WHERE order_id=?');$s->execute(array($o['id']));$rows=$s->fetchAll();sb_live_assert(count($rows)===1&&$rows[0]['created_by']===null&&strpos($rows[0]['content_ciphertext'],$payload['subscription'])===false,'single encrypted system delivery');
    $s=$pdo->prepare('SELECT status,subscription_id FROM subboost_sync_tasks WHERE id=?');$s->execute(array($o['task_id']));$task=$s->fetch();sb_live_assert($task['status']==='completed'&&$task['subscription_id']===$o['subscription_id'],'task completed with unchanged identity');
    echo json_encode(array('status'=>'delivered','deliveryCount'=>count($rows))).PHP_EOL;
}else{fwrite(STDERR,"Usage: --prepare | --finish <orderNo>\n");exit(1);}
