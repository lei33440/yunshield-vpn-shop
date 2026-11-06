<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$mode=isset($argv[1])?$argv[1]:'--once';
if(!in_array($mode,array('--once','--loop'),true)){fwrite(STDERR,"Usage: php scripts/subboost_worker.php --once|--loop\n");exit(1);}
$root=dirname(__DIR__);
$config=require $root.(is_file($root.'/config/config.php')?'/config/config.php':'/config/config.example.php');
// CLI 无须 Web 启动流程、会话或新增路由。
spl_autoload_register(function($class)use($root){foreach(array('/app/Core/','/app/Services/') as $dir)if(is_file($file=$root.$dir.$class.'.php')){require_once $file;return;}});
date_default_timezone_set($config['app']['timezone']);
try{
    if(!SubBoostClient::enabled($config)){echo json_encode(array('disabled'=>true)).PHP_EOL;exit(0);}
    $pdo=Database::connect($config);$service=new SubBoostSyncService($pdo,$config);
    do{$result=$service->runOnce();echo json_encode($result).PHP_EOL;if($mode==='--once')exit($result['errors']?1:0);sleep(5);}while(true);
}catch(Throwable $e){fwrite(STDERR,"SubBoost worker failed; check configuration/database availability.\n");exit(1);}
