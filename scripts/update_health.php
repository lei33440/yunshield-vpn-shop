<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
$root=dirname(__DIR__); $config=require $root.'/config/config.php'; require $root.'/app/Core/Database.php'; require $root.'/app/Services/UpdateService.php';
try {
    $s=new UpdateService($config);$health=$s->healthCheck();
    $expected=isset($argv[1])?$argv[1]:null;
    if(empty($health['ok'])||($expected!==null&&$health['version']!==$expected))throw new RuntimeException('更新健康检查或目标版本核对失败。');
    echo json_encode($health,JSON_UNESCAPED_UNICODE)."\n";exit(0);
} catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
