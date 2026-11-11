<?php
// 只创建安全诊断表，不执行历史订单或资金回填。执行前应先备份数据库。
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);
$config=require $root.(is_file($root.'/config/config.php')?'/config/config.php':'/config/config.example.php');
require_once $root.'/app/Core/Database.php';
$sql=file_get_contents($root.'/database/schema.sql');
if(!preg_match('/^CREATE TABLE IF NOT EXISTS subboost_sync_attempts \([^;]+;/m',$sql,$matches))throw new RuntimeException('诊断表定义不存在。');
Database::connect($config)->exec($matches[0]);
echo "SubBoost 诊断表已就绪。\n";
