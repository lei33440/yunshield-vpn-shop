<?php
// 本地 HTTP mock 与隔离 MySQL 集成测试，不读取真实配置、不触发真实支付。
if(PHP_SAPI==='cli-server'){
    $file=getenv('CHAOXI_SUBBOOST_MOCK_STATE');$state=json_decode(file_get_contents($file),true);
    if(($_SERVER['HTTP_AUTHORIZATION']??'')!=='Bearer fixture-subboost-secret'){http_response_code(401);echo 'unauthorized';return;}
    $mode=$state['mode'];
    if($mode==='failure'){http_response_code(503);echo 'private-response-token';return;}
    if($mode==='redirect'){http_response_code(302);header('Location: http://127.0.0.1:1/do-not-follow');return;}
    if($mode==='bad_json'){echo '{private-response-token';return;}
    $request=$_SERVER['REQUEST_METHOD']==='POST'?json_decode(file_get_contents('php://input'),true):null;
    $no=$request?$request['orderNo']:rawurldecode(basename(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)));
    if($request){
        if(mb_strlen($request['name'],'UTF-8')>100){http_response_code(400);return;}
        if(isset($state['orders'][$no])&&$state['orders'][$no]['request']!==$request){http_response_code(409);return;}
        if(!isset($state['orders'][$no]))$state['orders'][$no]=array('id'=>(string)(1000+count($state['orders'])),'request'=>$request);
    }
    if(!isset($state['orders'][$no])){http_response_code(404);return;}
    $id=$state['orders'][$no]['id'];$days=$state['orders'][$no]['request']['durationDays'];
    $state['calls']++;file_put_contents($file,json_encode($state));
    $ready=$mode!=='pending';$opened=new DateTimeImmutable('2026-01-31T20:30:00Z');
    $result=array('orderNo'=>$no,'subscriptionId'=>$mode==='identity'?'other-id':$id,'status'=>$ready?'ready':'pending_configuration',
        'subscriptionUrl'=>$ready?'http://127.0.0.1:'.$state['port'].'/api/subscriptions/fixture_token_'.$id.'/config.yaml':null,
        'openedAt'=>$ready?$opened->format('c'):null,'expiresAt'=>$ready?$opened->modify('+'.$days.' days')->format('c'):null);
    if($mode==='evil_url')$result['subscriptionUrl']='https://evil.example/api/sub/stolen';
    if($mode==='wrong_path')$result['subscriptionUrl']='http://127.0.0.1:'.$state['port'].'/api/admin/token';
    if($mode==='wrong_days')$result['expiresAt']=$opened->modify('+1 day')->format('c');
    if($mode==='changed_dates'){$result['openedAt']=$opened->modify('+1 hour')->format('c');$result['expiresAt']=$opened->modify('+'.$days.' days +1 hour')->format('c');}
    if($mode==='cancel_during'){
        $db=new PDO(getenv('CHAOXI_TEST_DSN'),getenv('CHAOXI_TEST_USER'),getenv('CHAOXI_TEST_PASSWORD'));
        $db->prepare('UPDATE orders SET status="cancelled" WHERE order_no=?')->execute(array($no));
    }
    header('Content-Type: application/json');echo json_encode($result);return;
}
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);
// 启动 mock 或创建文件前先验证隔离库参数。
$dsn=getenv('CHAOXI_TEST_DSN');$dbNames=array();
foreach($dsn&&strpos($dsn,'mysql:')===0?explode(';',substr($dsn,6)):array() as $part)if(preg_match('/^\s*dbname\s*=(.*)$/i',$part,$match))$dbNames[]=$match[1];
if(count($dbNames)!==1||!preg_match('/\Achaoxi_wallet_test_[A-Za-z0-9_]+\z/D',$dbNames[0]))throw new RuntimeException('Explicit isolated MySQL DSN required');
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errstr);if(!$socket)throw new RuntimeException('No local test port');
$address=stream_socket_get_name($socket,false);$port=(int)substr(strrchr($address,':'),1);fclose($socket);
$stateFile=tempnam($root.'/storage','sb-mock-');
$state=array('port'=>$port,'mode'=>'pending','orders'=>array(),'calls'=>0);file_put_contents($stateFile,json_encode($state));
putenv('CHAOXI_SUBBOOST_MOCK_STATE='.$stateFile);putenv('SUBBOOST_URL=http://127.0.0.1:'.$port);putenv('SUBBOOST_API_SECRET=fixture-subboost-secret');
$process=proc_open(array(PHP_BINARY,'-S','127.0.0.1:'.$port,__FILE__),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes,$root);
if(!is_resource($process))throw new RuntimeException('Cannot start local mock');
try{
    $started=false;for($i=0;$i<100;$i++){$conn=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$errstr,0.05);if($conn){fclose($conn);$started=true;break;}usleep(20000);}
    if(!$started)throw new RuntimeException('Mock did not start');
    $argv=array(__FILE__,'--prepare');require $root.'/scripts/test_subboost_live.php';
    function sb_ok($yes,$name){if(!$yes)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name.PHP_EOL;}
    function sb_mode($mode){global $stateFile;$s=json_decode(file_get_contents($stateFile),true);$s['mode']=$mode;file_put_contents($stateFile,json_encode($s));}
    function sb_due(){global $pdo;$pdo->exec('UPDATE subboost_sync_tasks SET available_at=UTC_TIMESTAMP() WHERE status<>"completed"');}
    function sb_new(){global $svc,$uid,$pid,$plan,$aid;$no=$svc->create($uid,$pid,$plan,'');$svc->confirmPayment($no,$aid);return $no;}
    function sb_count($no){global $pdo;$s=$pdo->prepare('SELECT COUNT(*) FROM delivery_records d JOIN orders o ON o.id=d.order_id WHERE o.order_no=?');$s->execute(array($no));return (int)$s->fetchColumn();}
    function sb_task($no){global $pdo;$s=$pdo->prepare('SELECT t.* FROM subboost_sync_tasks t JOIN orders o ON o.id=t.order_id WHERE o.order_no=?');$s->execute(array($no));return $s->fetch();}
    function sb_run(){global $worker;sb_due();return $worker->runOnce();}
    sb_ok((int)$pdo->query('SELECT COUNT(*) FROM subboost_sync_tasks')->fetchColumn()===1,'ordinary payment creates unique durable task; repeated payment does not duplicate');
    sb_ok(sb_count($no)===0&&sb_task($no)['status']==='waiting','pending configuration never delivers');
    sb_run();sb_ok(sb_count($no)===0,'repeated pending sync never delivers');
    $client=new SubBoostClient();$firstTask=sb_task($no);$request=json_decode($firstTask['request_json'],true);$response=$client->create($request);sb_ok((string)$response['subscriptionId']===$firstTask['subscription_id'],'POST same order is idempotent');
    $bad=$request;$bad['durationDays']=31;try{$client->create($bad);throw new LogicException('Expected 409');}catch(RuntimeException $e){sb_ok($e->getMessage()==='http_409','different input conflicts');}
    (new CustomerEmailService($pdo,$config))->saveSettings(array('customer_order_delivered_enabled'=>1),$aid);
    sb_mode('ready');sb_run();$payload=$svc->getDeliveryForAdmin($no,$config);
    sb_ok(sb_count($no)===1&&sb_task($no)['status']==='completed','ready completes encrypted delivery and task');
    sb_ok($payload['opened_at']==='2026-02-01 04:30:00'&&$payload['expires_at']==='2026-03-03 04:30:00'&&$payload['duration_preset']==='','dates use confirmed instant and snapshot days in shop timezone');
    sb_run();sb_run();sb_ok(sb_count($no)===1,'repeated ready sync never duplicates delivery');
    $event=$pdo->query('SELECT * FROM order_events WHERE event_type="delivered"')->fetch();$audit=$pdo->query('SELECT * FROM admin_logs WHERE action="deliver_order"')->fetch();$delivery=$pdo->query('SELECT * FROM delivery_records')->fetch();
    sb_ok($event['actor_type']==='system'&&$event['actor_id']===null&&$audit['actor_type']==='system'&&$audit['admin_id']===null&&$delivery['created_by']===null,'system/null delivery and complete audit');
    sb_ok(strpos($delivery['content_ciphertext'],'fixture_token')===false&&(int)$pdo->query('SELECT COUNT(*) FROM notifications WHERE type="delivered"')->fetchColumn()===1,'delivery encrypted and user notified');
    sb_ok((int)$pdo->query('SELECT COUNT(*) FROM notification_outbox WHERE event_type="customer_order_delivered"')->fetchColumn()===1,'shared delivery queues version-bound customer email exactly once');
    // 验证所有普通订单付款路径均在同一事务内入队。
    foreach(array('balance','online','zero') as $channel){
        $pdo->prepare('UPDATE product_plans SET price_cents=? WHERE id=?')->execute(array($channel==='zero'?0:100,$plan));
        $channelNo=$svc->create($uid,$pid,$plan,'');$channelOrder=$svc->getForUser($channelNo,$uid);
        if($channel==='balance'){$pdo->prepare('UPDATE users SET balance_cents=10000 WHERE id=?')->execute(array($uid));$svc->payWithBalance($channelNo,$uid);$svc->payWithBalance($channelNo,$uid);}
        elseif($channel==='online'){
            $pdo->prepare('INSERT INTO payment_records(order_id,method,gateway_type,amount_cents,status,created_at,updated_at) VALUES(?,"epay_alipay","alipay",100,"pending",NOW(),NOW())')->execute(array($channelOrder['id']));$paymentId=$pdo->lastInsertId();
            $svc->confirmPaymentSystem($channelNo,'alipay','fixture-online','{}',$paymentId);$svc->confirmPaymentSystem($channelNo,'alipay','fixture-online','{}',$paymentId);
        }
        sb_ok(sb_task($channelNo)!==false,$channel.' payment creates one sync task');
    }
    $pdo->prepare('UPDATE product_plans SET price_cents=100 WHERE id=?')->execute(array($plan));sb_run();
    $pdo->prepare('UPDATE products SET name=? WHERE id=?')->execute(array(str_repeat('长商品',50),$pid));
    $pdo->prepare('UPDATE product_plans SET name=? WHERE id=?')->execute(array(str_repeat('长期方案',24),$plan));
    $longNameOrder=sb_new();$longRequest=json_decode(sb_task($longNameOrder)['request_json'],true);
    sb_ok(mb_strlen($longRequest['name'],'UTF-8')===100&&preg_match('//u',$longRequest['name'])===1,'long Unicode name truncated deterministically to API limit');sb_run();sb_ok(sb_count($longNameOrder)===1,'long-name order synchronizes successfully');
    $pdo->prepare('UPDATE products SET name="SubBoost integration fixture" WHERE id=?')->execute(array($pid));$pdo->prepare('UPDATE product_plans SET name="30 days" WHERE id=?')->execute(array($plan));
    // 同步任务写入失败必须回滚付款，不得静默丢任务。
    $atomic=$svc->create($uid,$pid,$plan,'');$pdo->exec('RENAME TABLE subboost_sync_tasks TO subboost_sync_tasks_hidden');
    try{try{$svc->confirmPayment($atomic,$aid);throw new LogicException('Expected payment rollback');}catch(PDOException $e){sb_ok($svc->getForUser($atomic,$uid)['status']==='pending_payment','payment and task insertion are atomic');}}finally{$pdo->exec('RENAME TABLE subboost_sync_tasks_hidden TO subboost_sync_tasks');}
    $svc->cancel($atomic,'admin',$aid,'test cleanup');
    // 未启用集成时，保留既有付款及人工发货流程。
    putenv('SUBBOOST_API_SECRET=');$disabled=sb_new();sb_ok(sb_task($disabled)===false,'disabled configuration does not affect payment or enqueue');putenv('SUBBOOST_API_SECRET=fixture-subboost-secret');
    // 账号订单不进入 VPN 同步队列。
    $pdo->exec('INSERT INTO products(name,description,product_type,account_platform,account_specification,account_after_sales,price_cents,created_at,updated_at) VALUES("account","fixture","account","telegram","spec","after",100,NOW(),NOW())');$account=$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO product_plans(product_id,name,price_cents,created_at,updated_at) VALUES(?,"account",100,NOW(),NOW())')->execute(array($account));$ap=$pdo->lastInsertId();
    $an=$svc->create($uid,$account,$ap,'');$svc->confirmPayment($an,$aid);sb_ok(sb_task($an)===false,'account payment excluded');
    // 入队检查排除所有拼团关联，不区分历史版和 v2 流程。
    $group=$svc->create($uid,$pid,$plan,'');$go=$svc->getForUser($group,$uid);
    $pdo->prepare('INSERT INTO group_orders(campaign_id,order_id,user_id,status,created_at,updated_at) VALUES(999,?,?,"pending",NOW(),NOW())')->execute(array($go['id'],$uid));
    $pdo->beginTransaction();SubBoostSyncService::enqueuePaidOrder($pdo,$go);$pdo->commit();sb_ok(sb_task($group)===false,'group orders excluded');
    $pdo->prepare('DELETE FROM group_orders WHERE order_id=?')->execute(array($go['id']));$svc->cancel($group,'admin',$aid,'fixture cleanup');
    $errorOrder=sb_new();sb_mode('pending');sb_run();
    foreach(array('failure'=>'http_503','redirect'=>'http_302','bad_json'=>'invalid_response','evil_url'=>'invalid_subscription_url','wrong_path'=>'invalid_subscription_url','identity'=>'identity_mismatch','wrong_days'=>'invalid_dates') as $mode=>$expected){
        sb_mode($mode);$r=sb_run();sb_ok($r['errors']===1&&sb_count($errorOrder)===0&&sb_task($errorOrder)['last_error']===$expected,'reject '.$mode.' and keep retry task');
    }
    putenv('SUBBOOST_API_SECRET=wrong-credential');$r=sb_run();sb_ok($r['errors']===1&&sb_task($errorOrder)['last_error']==='http_401'&&sb_count($errorOrder)===0,'wrong credentials never deliver');putenv('SUBBOOST_API_SECRET=fixture-subboost-secret');
    // 共享发货流程失败后仍固定开通日期，拒绝重试时更换日期。
    sb_mode('ready');$pdo->exec("CREATE TRIGGER subboost_fixture_fail BEFORE INSERT ON delivery_records FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture delivery failure'");
    try{$r=sb_run();}finally{$pdo->exec('DROP TRIGGER subboost_fixture_fail');}
    sb_ok($r['errors']===1&&sb_task($errorOrder)['opened_at']==='2026-01-31 20:30:00'&&sb_count($errorOrder)===0,'failed delivery preserves confirmation dates and rolls back all delivery writes');
    sb_mode('changed_dates');sb_run();sb_ok(sb_task($errorOrder)['last_error']==='dates_changed'&&sb_count($errorOrder)===0,'retry never restarts subscription clock');
    sb_mode('ready');sb_run();sb_ok(sb_count($errorOrder)===1,'retry delivers once with original confirmation dates');
    $cancelled=sb_new();$svc->cancel($cancelled,'admin',$aid,'test cancellation');sb_run();sb_ok(sb_count($cancelled)===0,'cancelled order never delivers');
    $refund=sb_new();$svc->requestRefund($refund,$uid,'test refund');sb_run();sb_ok(sb_count($refund)===0,'refund request never delivers');
    $manual=sb_new();$svc->deliver($manual,$aid,array('subscription'=>'https://manual.example/sub','opened_at'=>'2026-01-01 00:00:00','expires_at'=>'2026-02-01 00:00:00'),$config);sb_run();sb_ok(sb_count($manual)===1,'existing manual delivery is never replaced');
    $race=sb_new();sb_mode('cancel_during');sb_run();sb_ok(sb_count($race)===0,'order recheck under lock blocks cancellation during HTTP');
    $invalid=sb_new();$badOrder=$svc->getForUser($invalid,$uid);$pdo->prepare('UPDATE orders SET duration_days_snapshot=0 WHERE id=?')->execute(array($badOrder['id']));
    $pdo->prepare('UPDATE subboost_sync_tasks SET request_json=? WHERE order_id=?')->execute(array(json_encode(array('orderNo'=>$invalid,'name'=>'invalid','durationDays'=>null)),$badOrder['id']));sb_run();sb_ok(sb_count($invalid)===0&&sb_task($invalid)['last_error']==='invalid_duration','invalid duration never defaults to delivery');
    // 连接级锁阻止其他 worker 同时执行 HTTP 或发货。
    $other=new PDO($dsn,getenv('CHAOXI_TEST_USER'),getenv('CHAOXI_TEST_PASSWORD'));$key='subboost:'.substr(hash('sha256',$pdo->query('SELECT DATABASE()')->fetchColumn()),0,48);$s=$other->prepare('SELECT GET_LOCK(?,0)');$s->execute(array($key));
    sb_ok($worker->runOnce()['locked']===true,'worker database singleton lock');$s=$other->prepare('SELECT RELEASE_LOCK(?)');$s->execute(array($key));
    foreach(array('subboost_sync_tasks','admin_logs','order_events','notifications') as $table){$raw=json_encode($pdo->query('SELECT * FROM '.$table)->fetchAll());sb_ok(strpos($raw,'fixture-subboost-secret')===false&&strpos($raw,'fixture_token')===false&&strpos($raw,'private-response-token')===false,'no secret/token leak in '.$table);}
    echo "SUBBOOST TESTS COMPLETE\n";
}finally{
    proc_terminate($process);foreach($pipes as $pipe)fclose($pipe);proc_close($process);if(is_file($stateFile))unlink($stateFile);
}
