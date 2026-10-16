<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
$root=dirname(__DIR__); $config=require $root.'/config/config.php'; require $root.'/app/Core/Database.php'; require $root.'/app/Services/UpdateService.php';
try { $s=new UpdateService($config); $v=$s->currentVersion(); if (is_file($root.'/storage/update/maintenance.flag')) throw new RuntimeException('maintenance'); echo json_encode(array('ok'=>true,'version'=>$v),JSON_UNESCAPED_UNICODE)."\n"; exit(0); } catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
