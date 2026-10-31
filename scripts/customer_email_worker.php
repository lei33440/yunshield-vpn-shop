<?php
/** 客户邮件专用发送器；不调用旧全量 worker，不接管历史邮件。 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
function customer_email_worker_mode($args){
    if($args===array('--once'))return 'once';if($args===array('--loop'))return 'loop';
    throw new InvalidArgumentException('invalid_arguments');
}
function customer_email_worker_ready($email){$cfg=$email->settings();return !empty($cfg['enabled'])&&!empty($cfg['configured']);}
function customer_email_worker_release($pdo,$rows){
    $s=$pdo->prepare('UPDATE notification_outbox SET status="retry",locked_at=NULL,updated_at=NOW() WHERE id=? AND status="processing" AND channel="email" AND event_type IN ("customer_order_created","customer_order_delivered","broadcast_notice")');
    foreach($rows as $row)$s->execute(array($row['id']));
}
/** 测试可注入 SMTP stub 与等待函数；生产入口不接受任意文件注入参数。 */
function customer_email_worker_run($pdo,$config,$email=null,$wait=null){
    if($email===null)$email=new EmailService($pdo,$config);if($wait===null)$wait=function($seconds){sleep($seconds);};
    $result=array('paused'=>false,'enqueued'=>0,'claimed'=>0,'sent'=>0,'skipped'=>0,'failed'=>0);
    // SMTP关闭或不完整时不入队、不领取、不回收，更不消耗重试。
    if(!customer_email_worker_ready($email)){$result['paused']=true;return $result;}
    $campaigns=new EmailCampaignService($pdo,$config);$customers=new CustomerEmailService($pdo,$config);$outbox=new NotificationOutboxService($pdo);
    $result['enqueued']=$campaigns->enqueueBatch(20);$rows=$outbox->claimCustomerEmails(20);$result['claimed']=count($rows);
    foreach($rows as $i=>$row){
        if(!customer_email_worker_ready($email)){customer_email_worker_release($pdo,array_slice($rows,$i));$result['paused']=true;break;}
        try{
            // 群发限速等待之后才做最后资格核对，减少取消/退订竞态窗口。
            if($row['event_type']==='broadcast_notice')call_user_func($wait,1);
            if(!customer_email_worker_ready($email)){customer_email_worker_release($pdo,array_slice($rows,$i));$result['paused']=true;break;}
            $payload=json_decode($row['payload_json'],true);$reason=$customers->eligibility($row,$payload);
            if($reason!==null){$outbox->failed($row['id'],$reason,false);$result['skipped']++;continue;}
            $prepared=$customers->prepare($row,$payload);
            if($prepared['reason']!==null){$outbox->failed($row['id'],$prepared['reason'],false);$result['skipped']++;continue;}
            $message=$prepared['message'];
            // 渲染/解密后、SMTP 调用前再次复核。网络调用期间不持有数据库事务锁。
            $reason=$customers->revalidate($row,$payload);
            if($reason!==null){$outbox->failed($row['id'],$reason,false);$result['skipped']++;continue;}
            if($pdo->inTransaction())throw new LogicException('customer_email_transaction_active');
            if($email->send($payload['to'],$message['subject'],$message['body'],$message['html'])===false){customer_email_worker_release($pdo,array_slice($rows,$i));$result['paused']=true;break;}
            $outbox->sent($row['id']);$result['sent']++;
        }catch(PDOException $e){
            // 数据库错误保持processing，留给专用范围的超时回收；不误判为永久跳过。
            throw $e;
        }catch(Throwable $e){$outbox->failed($row['id'],'customer_transport_retryable',true);$result['failed']++;}
    }
    $campaigns->refreshCompleted();return $result;
}
if(realpath(isset($_SERVER['SCRIPT_FILENAME'])?$_SERVER['SCRIPT_FILENAME']:'')===realpath(__FILE__)){
    try{$mode=customer_email_worker_mode(array_slice($argv,1));}catch(InvalidArgumentException $e){fwrite(STDERR,"usage: php scripts/customer_email_worker.php --once|--loop\n");exit(2);}
    $lock=null;
    try{
        $root=dirname(__DIR__);$lock=fopen(sys_get_temp_dir().'/chaoxi-customer-email-'.hash('sha256',strtolower(str_replace('\\','/',realpath($root)))).'.lock','c');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('customer_email_worker_already_running');
        $config=require $root.'/config/config.php';require_once $root.'/app/Core/Bootstrap.php';require_once $root.'/app/Core/Database.php';date_default_timezone_set($config['app']['timezone']);
        spl_autoload_register(function($class)use($root){foreach(array('/app/Core/','/app/Services/') as $dir){$path=$root.$dir.$class.'.php';if(is_file($path)){require_once $path;return;}}});
        $pdo=Database::connect($config);
        do{$result=customer_email_worker_run($pdo,$config);fwrite(STDOUT,json_encode($result,JSON_UNESCAPED_UNICODE)."\n");if($mode==='loop')sleep(5);}while($mode==='loop');
    }catch(Throwable $e){fwrite(STDERR,"customer_email_worker_error\n");exit(1);}
    finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
}
