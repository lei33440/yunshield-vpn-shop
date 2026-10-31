<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

function notification_worker_password_reset_mode($args){
    if($args===array())return false;
    if($args===array('--password-reset-only'))return true;
    throw new InvalidArgumentException('invalid_arguments');
}

function notification_worker_run($pdo,$config,$passwordResetOnly=false,$email=null,$wecom=null){
    $outbox=new NotificationOutboxService($pdo);
    if($email===null)$email=new EmailService($pdo,$config);
    if($wecom===null)$wecom=new WeComWebhookService($pdo,$config);
    foreach($outbox->claim(20,$passwordResetOnly) as $row){
        try{
            $payload=json_decode($row['payload_json'],true);
            if($row['channel']==='email'&&$row['event_type']==='password_reset'){
                $valid=false;
                if(is_array($payload)&&isset($payload['to'])&&is_string($payload['to'])&&preg_match('/\Apassword_reset:([a-f0-9]{64})\z/D',$row['dedupe_key'],$match)){
                    $s=$pdo->prepare('SELECT u.email FROM password_reset_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=? AND t.used_at IS NULL AND t.expires_at>NOW() AND u.status="active"');
                    $s->execute(array($match[1]));$recipient=$s->fetchColumn();
                    $valid=is_string($recipient)&&strcasecmp($recipient,$payload['to'])===0;
                }
                if(!$valid){
                    $outbox->failed($row['id'],'password_reset_invalid_or_expired',false);
                    echo 'skipped '.(int)$row['id']." password_reset\n";
                    continue;
                }
            }
            if(!is_array($payload))throw new RuntimeException('通知内容无效。');
            if($row['channel']==='email'){
                $args=array(isset($payload['to'])?$payload['to']:'',isset($payload['subject'])?$payload['subject']:'',isset($payload['body'])?$payload['body']:'');
                if($row['event_type']==='password_reset'&&isset($payload['html'])&&is_string($payload['html'])&&$payload['html']!=='')$args[]=$payload['html'];
                if(call_user_func_array(array($email,'send'),$args)===false)throw new RuntimeException('SMTP 未启用。');
            }elseif($row['channel']==='wecom'){
                $wecom->sendPayload(array_merge($payload,array('event_type'=>$row['event_type'])));
            }else throw new RuntimeException('通知渠道无效。');
            $outbox->sent($row['id']);
            echo 'sent '.(int)$row['id']."\n";
        }catch(PDOException $e){
            // 保留领取状态，由超时回收恢复，不把数据库故障误判为无效链接。
            throw $e;
        }catch(Exception $e){
            $message=$e->getMessage();
            $retryable=strpos($message,'网络')!==false||strpos($message,'TLS 握手')!==false||preg_match('/HTTP 5[0-9]{2}/',$message)===1;
            $reason=$retryable?'notification_transport_retryable':'notification_delivery_failed';
            $outbox->failed($row['id'],$reason,$retryable);
            echo 'failed '.(int)$row['id'].' '.$reason."\n";
        }
    }
}

if(realpath(isset($_SERVER['SCRIPT_FILENAME'])?$_SERVER['SCRIPT_FILENAME']:'')===realpath(__FILE__)){
    try{$passwordResetOnly=notification_worker_password_reset_mode(array_slice($argv,1));}
    catch(InvalidArgumentException $e){fwrite(STDERR,"invalid_arguments\n");exit(2);}
    try{
        $root=dirname(__DIR__);$config=require $root.'/config/config.php';require_once $root.'/app/Core/Bootstrap.php';require_once $root.'/app/Core/Database.php';
        date_default_timezone_set($config['app']['timezone']);
        spl_autoload_register(function($class)use($root){foreach(array('/app/Core/','/app/Services/')as$directory){$path=$root.$directory.$class.'.php';if(is_file($path)){require_once $path;return;}}});
        notification_worker_run(Database::connect($config),$config,$passwordResetOnly);
    }catch(Throwable $e){fwrite(STDERR,"notification_worker_error\n");exit(1);}
}
