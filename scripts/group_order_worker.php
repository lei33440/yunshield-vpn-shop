<?php
// CLI 单轮任务；仅入站内通知/outbox，不主动发送外部消息。建议每分钟由调度器执行。
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);$config=require $root.'/config/config.php';require $root.'/app/Core/Bootstrap.php';require $root.'/app/Core/Database.php';
try{$pdo=Database::connect($config);$limit=isset($argv[1])?(int)$argv[1]:100;$result=(new GroupService($pdo))->runOnce($limit);echo json_encode($result,JSON_UNESCAPED_UNICODE).PHP_EOL;exit(empty($result['errors'])?0:1);}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
