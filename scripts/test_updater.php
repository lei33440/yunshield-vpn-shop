<?php
// 仅在一次性目录演练，不加载站点配置或数据库。
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/app/Services/UpdateService.php';
function ut_ok($yes,$label){if(!$yes)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
function ut_call($object,$method,$args=array()){$r=new ReflectionMethod($object,$method);$r->setAccessible(true);return $r->invokeArgs($object,$args);}
function ut_reject($fn,$label){try{$fn();}catch(RuntimeException $e){ut_ok(true,$label);return;}throw new LogicException('FAIL '.$label);}
$root=sys_get_temp_dir().'/chaoxi-updater-'.bin2hex(random_bytes(8));mkdir($root,0700);
foreach(array('app','scripts','config','storage/uploads','storage/update/staging') as $dir)mkdir($root.'/'.$dir,0700,true);
file_put_contents($root.'/VERSION',"1.0.0\n");file_put_contents($root.'/app/original.php','<?php return "old";');
file_put_contents($root.'/config/config.php','PROTECTED_CONFIG');file_put_contents($root.'/storage/uploads/user.txt','PROTECTED_USER_FILE');
// 此替身只验证更新器的新进程协议；真实数据库/Web健康需另行验收。
file_put_contents($root.'/scripts/update_health.php','<?php $v=trim(file_get_contents(dirname(__DIR__)."/VERSION"));echo json_encode(array("ok"=>true,"version"=>$v));');
$pair=sodium_crypto_sign_keypair();$public=sodium_crypto_sign_publickey($pair);$secret=sodium_crypto_sign_secretkey($pair);
$config=array('update'=>array('install_enabled'=>true,'public_key'=>base64_encode($public),'php_cli'=>PHP_BINARY));$s=new UpdateService($config);
foreach(array('root'=>$root,'updateRoot'=>$root.'/storage/update') as $key=>$value){$r=new ReflectionProperty($s,$key);$r->setAccessible(true);$r->setValue($s,$value);}
function ut_task($s,$root,$secret,$version,$bad=false){
    $id=bin2hex(random_bytes(16));$dir=$root.'/storage/update/staging/'.$id;mkdir($dir,0700,true);
    $zip=new ZipArchive();$zip->open($dir.'/package.zip',ZipArchive::CREATE);
    $files=array('VERSION'=>$version."\n",'app/original.php'=>'<?php return "new";','app/added.php'=>'<?php return true;');
    if($bad)$files['app/broken.php']='<?php syntax error !';
    foreach($files as $path=>$bytes)$zip->addFromString($path,$bytes);$zip->close();
    $m=array('version'=>$version,'package'=>'yunshield-vpn-shop-'.$version.'.zip','sha256'=>hash_file('sha256',$dir.'/package.zip'),'min_php'=>'7.4.0','migration_required'=>false);
    $json=json_encode($m);file_put_contents($dir.'/update-manifest.json',$json);file_put_contents($dir.'/update-manifest.json.sig',base64_encode(sodium_crypto_sign_detached($json,$secret)));
    ut_call($s,'writeState',array($id,array('id'=>$id,'status'=>'verified','version'=>$version,'manifest'=>$m)));return $id;
}
try{
    $id=ut_task($s,$root,$secret,'1.0.1');$s->performInstall($id);
    ut_ok($s->status($id)['status']==='installed'&&trim(file_get_contents($root.'/VERSION'))==='1.0.1','安装成功及目标版本一致');
    ut_ok(is_file($root.'/app/added.php'),'新增文件已安装');
    ut_ok(!is_file($root.'/storage/update/maintenance.flag'),'成功后解除维护');
    ut_reject(function()use($s,$id){$s->performInstall($id);},'已执行任务拒绝重复安装');
    $backup=$s->backups()[0]['id'];$s->rollback($backup);
    ut_ok(trim(file_get_contents($root.'/VERSION'))==='1.0.0'&&file_get_contents($root.'/app/original.php')==='<?php return "old";','回滚恢复原版本与文件');
    ut_ok(!is_file($root.'/app/added.php'),'回滚清理新增文件');
    $id=ut_task($s,$root,$secret,'1.0.2',true);
    ut_reject(function()use($s,$id){$s->performInstall($id);},'语法错误阻止安装');
    ut_ok(trim(file_get_contents($root.'/VERSION'))==='1.0.0','失败不改变原版本');
    $id=ut_task($s,$root,$secret,'1.0.3');file_put_contents($root.'/storage/update/staging/'.$id.'/update-manifest.json','{}');
    ut_reject(function()use($s,$id){$s->performInstall($id);},'暂存后清单篡改被拒绝');
    ut_ok(file_get_contents($root.'/config/config.php')==='PROTECTED_CONFIG'&&file_get_contents($root.'/storage/uploads/user.txt')==='PROTECTED_USER_FILE','配置与用户文件保持不变');
    $id=ut_task($s,$root,$secret,'1.0.4');$extract=$root.'/storage/update/staging/'.$id.'/extracted';mkdir($extract);file_put_contents($extract.'/unsigned.php','<?php return true;');
    ut_reject(function()use($s,$id){$s->performInstall($id);},'拒绝复用残留解压目录');
    ut_ok(!is_file($root.'/unsigned.php'),'未签名残留文件不会安装');
    $id=ut_task($s,$root,$secret,'1.0.5');
    $queuedConfig=$config;$queuedConfig['update']['queue_enabled']=true;$property=new ReflectionProperty($s,'config');$property->setAccessible(true);$property->setValue($s,$queuedConfig);
    ut_reject(function()use($s,$id){$s->startWorker($id);},'无调度心跳拒绝队列提交');
    mkdir($root.'/storage/update/queue');file_put_contents($root.'/storage/update/queue/heartbeat',date('c'));
    $s->startWorker($id);ut_ok(is_file($root.'/storage/update/queue/install-'.$id.'.json'),'后台队列持久化且不调用进程启动');
    ut_ok($s->status($id)['status']==='queued','队列提交后状态明确为等待执行');
    ut_reject(function()use($s,$id){$s->startWorker($id);},'重复队列提交拒绝');
    $property->setValue($s,$config);
    $s->performInstall($id);$backup=$s->status($id)['backup_id'];
    ut_ok(is_file($root.'/storage/update/backups/'.$backup.'/app/original.php'),'清理历史备份不删除当前安装备份');
    unlink($root.'/storage/update/backups/'.$backup.'/app/original.php');
    ut_reject(function()use($s,$backup){$s->rollback($backup);},'备份缺失拒绝回滚');
    ut_ok(is_file($root.'/storage/update/maintenance.flag'),'恢复失败保持维护');
    unlink($root.'/storage/update/maintenance.flag');
    file_put_contents($root.'/scripts/update_health.php','<?php echo json_encode(array("ok"=>false,"version"=>trim(file_get_contents(dirname(__DIR__)."/VERSION"))));');
    $id=ut_task($s,$root,$secret,'1.0.6');
    ut_reject(function()use($s,$id){$s->performInstall($id);},'安装后及恢复后不健康拒绝开放');
    ut_ok($s->status($id)['status']==='recovery_required'&&is_file($root.'/storage/update/maintenance.flag'),'自动恢复不健康保持维护并明确告警');
    ut_ok(!empty($s->status($id)['backup_id']),'失败任务保留可追溯备份编号');
    $id=ut_task($s,$root,$secret,'1.0.7');
    $s->reportInterrupted($id);
    ut_ok($s->status($id)['status']==='recovery_required','中断更新明确进入人工恢复状态');
    ut_ok(is_file($root.'/storage/update/maintenance.flag'),'中断更新保持维护');
    mkdir($root.'/app/Services');mkdir($root.'/app/Core');
    copy(dirname(__DIR__).'/app/Services/UpdateService.php',$root.'/app/Services/UpdateService.php');
    copy(__DIR__.'/update_worker.php',$root.'/scripts/update_worker.php');
    file_put_contents($root.'/app/Core/Database.php','<?php class Database {}');
    file_put_contents($root.'/config/config.php','<?php return '.var_export(array('update'=>array('queue_enabled'=>true)),true).';');
    file_put_contents($root.'/storage/update/queue/install-'.$id.'.json.running',json_encode(array('action'=>'install','id'=>$id)));
    $out=array();$code=0;exec(escapeshellarg(PHP_BINARY).' -d disable_functions= '.escapeshellarg($root.'/scripts/update_worker.php').' --drain 2>&1',$out,$code);
    clearstatcache();
    ut_ok($code!==0&&is_file($root.'/storage/update/queue/install-'.$id.'.json.running.interrupted'),'真实consumer重启识别中断并返回非零');
    ut_ok($s->status($id)['status']==='recovery_required'&&is_file($root.'/storage/update/maintenance.flag'),'consumer重启不静默开放业务');
    echo 'UPDATER ISOLATED TESTS PASSED'.PHP_EOL;
}finally{sodium_memzero($secret);sodium_memzero($pair);}
echo '临时演练目录：'.$root.PHP_EOL;
