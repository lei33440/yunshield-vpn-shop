<?php
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$root = dirname(__DIR__);
$config = require $root . '/config/config.php';
require $root . '/app/Core/Database.php';
require $root . '/app/Services/UpdateService.php';
$action = isset($argv[1]) ? $argv[1] : '';
if($action==='--drain'){
    $queue=$root.'/storage/update/queue';
    if(empty($config['update']['queue_enabled']))exit(0);
    if(!is_dir($queue)&&!mkdir($queue,0750,true))throw new RuntimeException('无法创建更新队列。');
    if(file_put_contents($queue.'/heartbeat',date('c'),LOCK_EX)===false)throw new RuntimeException('无法记录更新调度心跳。');
    $lock=fopen($queue.'/consumer.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit(0);
    try{
        $interrupted=glob($queue.'/*.json.running');
        if($interrupted){
            foreach($interrupted as $running){
                $data=json_decode((string)file_get_contents($running),true);
                if(!is_array($data)||!isset($data['id'])||!preg_match('/\A(?:[a-f0-9]{32}|[0-9]{14}-[a-f0-9]{32})\z/D',$data['id']))throw new RuntimeException('中断任务编号无效，需人工检查。');
                $service=new UpdateService($config);
                $id=strlen($data['id'])===32?$data['id']:substr($data['id'],15);
                $service->reportInterrupted($id);
                if(!rename($running,$running.'.interrupted'))throw new RuntimeException('无法记录中断任务。');
            }
            throw new RuntimeException('发现中断更新，已保持维护并标记需要人工恢复。');
        }
        foreach(glob($queue.'/*.json') as $job){
            $data=json_decode((string)file_get_contents($job),true);
            if(!is_array($data)||!isset($data['action'],$data['id'])||!in_array($data['action'],array('install','rollback'),true)||!preg_match('/\A(?:[a-f0-9]{32}|[0-9]{14}-[a-f0-9]{32})\z/D',$data['id']))throw new RuntimeException('更新队列格式无效。');
            if(basename($job)!==$data['action'].'-'.$data['id'].'.json')throw new RuntimeException('更新队列编号不一致。');
            if(!rename($job,$job.'.running'))throw new RuntimeException('无法领取更新任务。');
            try{$service=new UpdateService($config);if($data['action']==='install')$service->performInstall($data['id']);else $service->rollback($data['id']);rename($job.'.running',$job.'.done');}
            catch(Throwable $e){rename($job.'.running',$job.'.failed');throw $e;}
            break;
        }
    }catch(Throwable $e){fwrite(STDERR,'更新队列执行失败：'.$e->getMessage().PHP_EOL);exit(1);}
    finally{flock($lock,LOCK_UN);fclose($lock);}
    exit(0);
}
if (!preg_match('/^--(install|rollback)=([a-f0-9]{32}|[0-9]{14}-[a-f0-9]{32})$/', $action, $matches)) exit("Invalid update action\n");
try {
    $service = new UpdateService($config);
    if ($matches[1] === 'install') $service->performInstall($matches[2]);
    else $service->rollback($matches[2]);
    exit(0);
} catch (Throwable $e) {
    $log = $root . '/storage/logs/update.log'; if (!is_dir(dirname($log))) @mkdir(dirname($log), 0750, true);
    $line = date('c') . ' ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    @file_put_contents($log, $line, FILE_APPEND | LOCK_EX); fwrite(STDERR, $line); exit(1);
}
