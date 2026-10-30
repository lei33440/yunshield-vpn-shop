<?php
class UpdateService
{
    private $config;
    private $root;
    private $updateRoot;

    public function __construct($config)
    {
        $this->config = $config;
        $this->root = dirname(dirname(__DIR__));
        $this->updateRoot = $this->root . '/storage/update';
    }

    public function currentVersion()
    {
        $path = $this->root . '/VERSION';
        if (!is_file($path)) throw new RuntimeException('本地版本文件不存在。');
        $version = trim((string)file_get_contents($path));
        if (!$this->isVersion($version)) throw new RuntimeException('本地版本号格式无效。');
        return $version;
    }

    public function cachedResult()
    {
        $path = $this->root . '/storage/cache/update-check.json';
        if (!is_file($path)) return null;
        $raw = @file_get_contents($path);
        $cache = $raw === false ? null : json_decode($raw, true);
        if (!is_array($cache) || !isset($cache['checked_at'],$cache['result']) || !is_array($cache['result'])) return null;
        if ((int)$cache['checked_at'] + $this->cacheSeconds() < time()) return null;
        return $this->withCurrentVersion($cache['result']);
    }

    public function checkLatest($force = false)
    {
        $current = $this->currentVersion();
        $update = $this->updateConfig();
        if (isset($update['enabled']) && !$update['enabled']) return $this->result($current, 'disabled', '更新检测已关闭。');
        if (!$force) {
            $cached = $this->cachedResult();
            if ($cached !== null) return $cached;
        }
        try {
            $release = $this->fetchRelease(false);
            $tag = $release['version'];
            $result = array('status'=>'ok','message'=>version_compare($tag,$current,'>')?'发现新版本。':'当前已是最新版本。','latest_version'=>$tag,'release_name'=>$release['release_name'],'published_at'=>$release['published_at'],'release_url'=>$release['release_url'],'has_update'=>version_compare($tag,$current,'>'),'checked_at'=>date('c'),'assets_ready'=>$release['assets_ready']);
            return $this->store($this->withCurrentVersion($result));
        } catch (Exception $e) {
            $message = $e->getMessage();
            $status = $message === 'GitHub 尚未发布 Release。' ? 'no_release' : 'error';
            return $this->store($this->result($current, $status, $message));
        }
    }

    public function latestRelease($force = false)
    {
        $release = $this->fetchRelease(true);
        if (!$force && version_compare($release['version'], $this->currentVersion(), '<=')) throw new RuntimeException('当前已是最新版本，无需更新。');
        return $release;
    }

    public function prepareUpdate()
    {
        if (!$this->installEnabled()) throw new RuntimeException('在线安装功能尚未启用。');
        if (!class_exists('ZipArchive')) throw new RuntimeException('服务器未启用 ZipArchive，无法安装更新。');
        $this->assertInstallable();
        $release = $this->latestRelease(false);
        $id = bin2hex(random_bytes(16));
        $dir = $this->updateRoot . '/staging/' . $id;
        if (!@mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('无法创建更新暂存目录。');
        try {
            $manifestJson = $this->download($release['manifest_url'], $dir . '/update-manifest.json', 1048576);
            $signature = $this->download($release['signature_url'], $dir . '/update-manifest.json.sig', 16384);
            $manifest = json_decode($manifestJson, true);
            if (!is_array($manifest) || !$this->verifyManifest($manifestJson, $signature)) throw new RuntimeException('更新清单签名验证失败。');
            $this->validateManifest($manifest, $release);
            $this->download($release['package_url'], $dir . '/package.zip', 52428800);
            if (!hash_equals(strtolower($manifest['sha256']), hash_file('sha256', $dir . '/package.zip'))) throw new RuntimeException('更新包校验失败。');
            $this->validatePackage($dir . '/package.zip', $manifest);
            $state = array('id'=>$id,'status'=>'verified','version'=>$manifest['version'],'release_url'=>$release['release_url'],'release_name'=>$release['release_name'],'prepared_at'=>date('c'),'updated_at'=>date('c'),'manifest'=>$manifest);
            $this->writeState($id, $state);
            return $state;
        } catch (Exception $e) {
            $this->removeTree($dir);
            throw $e;
        }
    }

    public function startWorker($id, $action = 'install')
    {
        if (!in_array($action, array('install','rollback'), true)) throw new RuntimeException('更新操作无效。');
        if($action==='install')$this->assertInstallable();
        if($action==='rollback'&&!preg_match('/^[0-9]{14}-[a-f0-9]{32}$/',$id))throw new RuntimeException('备份编号无效。');
        $state = $action === 'install' ? $this->readState($id) : array('id'=>$id);
        if($action==='install'&&$state&&version_compare($state['version'],$this->currentVersion(),'<=') )throw new RuntimeException('更新任务已过期，请重新检测版本。');
        if (!$state || ($action === 'install' && $state['status'] !== 'verified')) throw new RuntimeException('更新任务不存在或状态无效。');
        if(!empty($this->updateConfig()['queue_enabled'])){
            $lock=$this->lock();
            try{
                $heartbeat=$this->updateRoot.'/queue/heartbeat';
                if(!is_file($heartbeat)||time()-filemtime($heartbeat)>180)throw new RuntimeException('独立更新调度器未就绪，请运维检查计划任务。');
                $queue=$this->updateRoot.'/queue';
                if(!is_dir($queue)&&!mkdir($queue,0750,true))throw new RuntimeException('无法创建更新队列。');
                $job=$queue.'/'.$action.'-'.$id.'.json';
                if(is_file($job)||is_file($job.'.running'))throw new RuntimeException('该更新任务已排队。');
                if($action==='install'){
                    $state=$this->readState($id);
                    if(!$state||$state['status']!=='verified')throw new RuntimeException('更新任务已提交或状态已变化。');
                }
                $json=json_encode(array('action'=>$action,'id'=>$id,'queued_at'=>date('c')));
                $tmp=$job.'.tmp';if(file_put_contents($tmp,$json,LOCK_EX)===false)throw new RuntimeException('无法提交更新队列。');
                if($action==='install'){$state['status']='queued';$state['updated_at']=date('c');$this->writeState($id,$state);}
                if(!rename($tmp,$job)){
                    if($action==='install'){$state['status']='verified';$this->writeState($id,$state);}
                    throw new RuntimeException('无法提交更新队列。');
                }
            }finally{$this->unlock($lock);}
            return $id;
        }
        $worker = $this->root . '/scripts/update_worker.php';
        $php = $this->cliBinary();
        if (stripos(PHP_OS, 'WIN') === 0) {
            $command = 'start "" /B ' . escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg('--'.$action.'='.$id);
            @pclose(@popen($command, 'r'));
        } else {
            $command = escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg('--'.$action.'='.$id) . ' >/dev/null 2>&1 &';
            @exec($command);
        }
        return $id;
    }

    public function reportInterrupted($id)
    {
        $this->requireCli();$lock=$this->lock();
        try{
            $this->setMaintenance(true);
            $state=$this->readState($id);
            if(!$state)$state=array('id'=>$id);
            $state['status']='recovery_required';$state['updated_at']=date('c');$state['error']='更新执行中断，禁止自动重试，请检查备份后恢复。';
            $this->writeState($id,$state);
        }finally{$this->unlock($lock);}
    }
    public function status($id = '')
    {
        if ($id !== '') return $this->readState($id);
        $dir = $this->updateRoot . '/staging';
        $files = is_dir($dir) ? glob($dir . '/*/state.json') : array();
        $latest = null;
        foreach ($files as $file) { $item = json_decode((string)@file_get_contents($file), true); $time = isset($item['updated_at']) ? $item['updated_at'] : (isset($item['prepared_at']) ? $item['prepared_at'] : ''); if (is_array($item) && (!$latest || strcmp($time, isset($latest['updated_at']) ? $latest['updated_at'] : (isset($latest['prepared_at']) ? $latest['prepared_at'] : '')) > 0)) $latest = $item; }
        return $latest;
    }

    public function backups()
    {
        $dir = $this->updateRoot . '/backups';
        $items = array();
        foreach (is_dir($dir) ? glob($dir . '/*/manifest.json') : array() as $path) {
            $raw = @file_get_contents($path);
            $manifest = $raw === false ? null : json_decode($raw, true);
            if (is_array($manifest) && !empty($manifest['id'])) {
                $items[] = array('id'=>(string)$manifest['id'], 'created_at'=>isset($manifest['created_at'])?(string)$manifest['created_at']:'', 'files'=>isset($manifest['files'])&&is_array($manifest['files'])?count($manifest['files']):0);
            }
        }
        usort($items, function($a, $b) { return strcmp($b['id'], $a['id']); });
        return $items;
    }

    public function maintenance()
    {
        $path = $this->updateRoot . '/maintenance.flag';
        return array('enabled'=>is_file($path), 'at'=>is_file($path)?trim((string)@file_get_contents($path)):'');
    }

    public function healthCheck()
    {
        $checks=array('version'=>false,'database'=>false,'zip'=>class_exists('ZipArchive'),'pdo_mysql'=>extension_loaded('pdo_mysql'));
        try {
            $checks['version']=$this->isVersion($this->currentVersion());$pdo=Database::connect($this->config);$pdo->query('SELECT 1');$checks['database']=true;
            require_once $this->root.'/app/Core/AccountProductPages.php';
            $checks['catalog_schema']=account_catalog_ready($pdo);
            foreach(array('OrderService','PaymentService','WalletService','GroupService','AccountPlatformService','NotificationService') as $class){require_once $this->root.'/app/Services/'.$class.'.php';$checks[$class]=class_exists($class,false);}
        } catch(Throwable $e){$checks['application']=false;}
        return array('ok'=>!in_array(false,$checks,true),'checks'=>$checks,'version'=>isset($checks['version'])&&$checks['version']?$this->currentVersion():'');
    }

    public function performInstall($id)
    {
        $this->requireCli();
        $lock = $this->lock();
        $state=array('id'=>$id);
        try {
            $this->assertInstallable();
            if($this->maintenance()['enabled'])throw new RuntimeException('站点处于维护或待恢复状态，拒绝启动新安装。');
            $state=$this->readState($id);
            if(!$state||!in_array($state['status'],array('verified','queued'),true))throw new RuntimeException('更新任务状态无效。');
            if(version_compare($state['version'],$this->currentVersion(),'<=') )throw new RuntimeException('更新任务已过期。');
            $dir = $this->updateRoot . '/staging/' . $id;
            $zipPath = $dir . '/package.zip';
            $json=@file_get_contents($dir.'/update-manifest.json');
            $signature=@file_get_contents($dir.'/update-manifest.json.sig');
            if($json===false||$signature===false||!$this->verifyManifest($json,$signature))throw new RuntimeException('更新清单重新验签失败。');
            $manifest=json_decode($json,true);
            $this->validateManifest($manifest,array('version'=>$state['version']));
            $this->validatePackage($zipPath, $manifest);
            $state['status']='installing'; $state['started_at']=date('c'); $state['updated_at']=date('c'); $this->writeState($id,$state);
            $this->setMaintenance(true);
            $backup = $this->backup($manifest['version'], $id);
            $state['backup_id']=$backup['id'];$state['updated_at']=date('c');$this->writeState($id,$state);
            $extracted = $dir . '/extracted';
            $this->extractPackage($zipPath, $extracted);
            $this->lintTree($extracted);
            $planned=array();
            foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extracted,FilesystemIterator::SKIP_DOTS)) as $file)if($file->isFile())$planned[]=str_replace('\\','/',substr($file->getPathname(),strlen($extracted)+1));
            $this->markBackupInstalled($backup['id'],$planned);
            $installed = $this->installTree($extracted);
            if (!$installed) throw new RuntimeException('更新包没有可安装的文件。');
            $this->markBackupInstalled($backup['id'], $installed);
            $state=array_merge($state,array('status'=>'health_check','updated_at'=>date('c'),'backup_id'=>$backup['id'],'files'=>$installed)); $this->writeState($id,$state);
            $out=array();$code=1;
            exec(escapeshellarg($this->cliBinary()).' '.escapeshellarg($this->root.'/scripts/update_health.php').' '.escapeshellarg($manifest['version']),$out,$code);
            $health=json_decode(implode("\n",$out),true);
            if($code!==0||!is_array($health)||empty($health['ok'])||$health['version']!==$manifest['version'])throw new RuntimeException('更新后独立进程健康检查失败。');
            if(!empty($this->updateConfig()['queue_enabled']))$this->webHealth($manifest['version']);
            $this->writeState($id, array_merge($state,array('status'=>'installed','installed_at'=>date('c'),'updated_at'=>date('c'),'health_check'=>$health)));
            $this->trimBackups($backup['id']);
            $this->setMaintenance(false);
        } catch (Throwable $e) {
            $restored=true;
            if (isset($backup)) {
                try {
                    $this->restoreBackup($backup['id']);
                    $out=array();$code=1;
                    exec(escapeshellarg($this->cliBinary()).' '.escapeshellarg($this->root.'/scripts/update_health.php'),$out,$code);
                    $recovered=json_decode(implode("\n",$out),true);
                    if($code!==0||!is_array($recovered)||empty($recovered['ok'])||!isset($recovered['version'])||$recovered['version']!==$this->currentVersion())throw new RuntimeException('自动恢复后健康检查失败。');
                    if(!empty($this->updateConfig()['queue_enabled']))$this->webHealth($recovered['version']);
                } catch (Throwable $restore) { $restored=false;$this->log('恢复备份失败: '.$restore->getMessage()); }
            }
            try { $this->writeState($id, array_merge((array)$state,array('status'=>$restored?'failed':'recovery_required','error'=>$e->getMessage(),'failed_at'=>date('c')))); } catch (Throwable $ignore) {}
            if($restored&&isset($backup))$this->setMaintenance(false);
            throw $e;
        } finally { $this->unlock($lock); }
    }

    public function rollback($backupId)
    {
        $this->requireCli();
        $lock = $this->lock();
        try {
            $this->setMaintenance(true);
            $this->restoreBackup($backupId);
            $out=array();$code=1;
            exec(escapeshellarg($this->cliBinary()).' '.escapeshellarg($this->root.'/scripts/update_health.php'),$out,$code);
            $health=json_decode(implode("\n",$out),true);
            if($code!==0||!is_array($health)||empty($health['ok'])||!isset($health['version'])||$health['version']!==$this->currentVersion())throw new RuntimeException('回滚健康检查失败，维护模式已保留。');
            if(!empty($this->updateConfig()['queue_enabled']))$this->webHealth($health['version']);
            $this->setMaintenance(false);
        } finally { $this->unlock($lock); }
    }

    private function fetchRelease($requireAssets = false)
    {
        $repo = isset($this->updateConfig()['repository']) ? trim($this->updateConfig()['repository']) : '';
        if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/',$repo)) throw new RuntimeException('更新仓库配置无效。');
        $response = $this->request('https://api.github.com/repos/'.$repo.'/releases/latest');
        $data = json_decode($response,true);
        if (!is_array($data) || !isset($data['tag_name'])) throw new RuntimeException('GitHub 返回的版本信息无效。');
        $version = ltrim(trim((string)$data['tag_name']),'vV');
        if (!$this->isVersion($version)) throw new RuntimeException('GitHub Release 版本号无效。');
        $assets = array(); foreach (isset($data['assets']) && is_array($data['assets']) ? $data['assets'] : array() as $asset) if (isset($asset['name'],$asset['browser_download_url']) && $this->validGithubUrl($asset['browser_download_url'])) $assets[$asset['name']] = $asset;
        $prefix = $this->packagePrefix();
        $package = $prefix.$version.'.zip';
        $assetsReady=isset($assets['update-manifest.json'],$assets['update-manifest.json.sig'],$assets[$package]);
        if ($requireAssets && !$assetsReady) throw new RuntimeException('该 Release 缺少签名更新资产。');
        return array('version'=>$version,'release_name'=>isset($data['name'])?(string)$data['name']:'','published_at'=>isset($data['published_at'])?(string)$data['published_at']:'','release_url'=>$this->validGithubUrl(isset($data['html_url'])?$data['html_url']:'')?$data['html_url']:'','manifest_url'=>isset($assets['update-manifest.json'])?$assets['update-manifest.json']['browser_download_url']:'','signature_url'=>isset($assets['update-manifest.json.sig'])?$assets['update-manifest.json.sig']['browser_download_url']:'','package_url'=>isset($assets[$package])?$assets[$package]['browser_download_url']:'','assets_ready'=>$assetsReady);
    }

    private function verifyManifest($json, $signature)
    {
        $key = trim((string)$this->updateConfig()['public_key']);
        if ($key === '' || !function_exists('sodium_crypto_sign_verify_detached')) return false;
        $rawKey = base64_decode($key, true); if ($rawKey === false || strlen($rawKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) return false;
        $rawSig = base64_decode(trim($signature), true); if ($rawSig === false) $rawSig = $signature;
        return strlen($rawSig) === SODIUM_CRYPTO_SIGN_BYTES && sodium_crypto_sign_verify_detached($rawSig,$json,$rawKey);
    }

    private function validateManifest($manifest, $release)
    {
        if (!isset($manifest['version'],$manifest['package'],$manifest['sha256']) || $manifest['version'] !== $release['version'] || !preg_match('/^'.$this->packagePrefix().preg_quote($release['version'],'/').'\.zip$/',$manifest['package']) || !preg_match('/^[a-f0-9]{64}$/i',$manifest['sha256'])) throw new RuntimeException('更新清单内容无效。');
        if(!array_key_exists('migration_required',$manifest)||$manifest['migration_required']!==false)throw new RuntimeException('该更新未声明无需数据库迁移，已拒绝在线安装。');
        if (isset($manifest['min_php']) && version_compare(PHP_VERSION,(string)$manifest['min_php'],'<')) throw new RuntimeException('当前 PHP 版本不满足更新要求。');
    }

    private function validatePackage($path, $manifest)
    {
        if (!is_file($path) || !hash_equals(strtolower($manifest['sha256']),hash_file('sha256',$path))) throw new RuntimeException('更新包校验失败。');
        $zip = new ZipArchive(); if ($zip->open($path)!==true) throw new RuntimeException('更新包无法打开。');
        try{
            $seen=array();$total=0;
            if($zip->numFiles>10000)throw new RuntimeException('更新包文件过多。');
            for($i=0;$i<$zip->numFiles;$i++){
                $name=$zip->getNameIndex($i);$stat=$zip->statIndex($i);$total+=$stat['size'];
                if($total>209715200||strpos($name,'\\')!==false||preg_match('#(^/|:|\x00|(^|/)\.{1,2}(/|$))#',$name))throw new RuntimeException('更新包路径或解压大小无效。');
                $ops=0;$attr=0;$zip->getExternalAttributesIndex($i,$ops,$attr);if((($attr>>16)&0170000)===0120000)throw new RuntimeException('更新包不允许符号链接。');
                if(substr($name,-1)==='/')continue;
                $key=strtolower($name);if(isset($seen[$key])||!$this->allowedPath($name))throw new RuntimeException('更新包包含重复或不允许的路径。');$seen[$key]=true;
                if(isset($manifest['files'])){if(!is_array($manifest['files'])||!isset($manifest['files'][$name])||!is_string($manifest['files'][$name])||!hash_equals($manifest['files'][$name],hash('sha256',$zip->getFromIndex($i))))throw new RuntimeException('签名文件清单与压缩包不一致。');}
            }
            if(isset($manifest['files'])&&count($manifest['files'])!==count($seen))throw new RuntimeException('签名文件清单数量不一致。');
            $version=$zip->getFromName('VERSION');if($version===false||trim($version)!==$manifest['version'])throw new RuntimeException('更新包版本文件与签名版本不一致。');
        }finally{$zip->close();}
    }

    private function packageRootPrefix($zip)
    {
        $first=''; for($i=0;$i<$zip->numFiles;$i++){ $n=str_replace('\\','/',$zip->getNameIndex($i)); if($n!==''){ $slash=strpos($n,'/'); $first=$slash===false?'':substr($n,0,$slash+1); break; } }
        if ($first === '' || in_array(rtrim($first,'/'),array('app','public','config','database','scripts'),true)) return '';
        return $first;
    }
    private function stripPackagePrefix($name,$zip){$name=ltrim(str_replace('\\','/',$name),'/');$prefix=$this->packageRootPrefix($zip);return $prefix!==''&&strpos($name,$prefix)===0?substr($name,strlen($prefix)):$name;}
    private function allowedPath($path){$path=ltrim(str_replace('\\','/',$path),'/'); if($path===''||strpos($path,'..')!==false||preg_match('#^(config/config\.php|\.env|storage/|\.git/)#i',$path)) return false; return (bool)preg_match('#^(app/|public/|config/config\.example\.php$|database/|scripts/|README\.md$|CHANGELOG\.md$|VERSION$|\.gitignore$)#i',$path);}
    private function download($url,$path,$limit){
        if (!function_exists('curl_init')) throw new RuntimeException('服务器未启用 cURL，无法下载更新。');
        if (!$this->validGithubUrl($url)) throw new RuntimeException('更新下载地址无效。');
        $fp=@fopen($path,'wb'); if (!$fp) throw new RuntimeException('无法保存更新文件。');
        $size=0;
        $ch=curl_init($url);
        curl_setopt_array($ch,array(CURLOPT_FILE=>$fp,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>300,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERAGENT=>'yunshield-vpn-shop-updater',CURLOPT_HTTPHEADER=>array('Accept: application/octet-stream'),CURLOPT_NOPROGRESS=>false,CURLOPT_PROGRESSFUNCTION=>function($resource,$downloadSize,$downloaded) use (&$size,$limit){$size=(int)$downloaded;return $size>$limit?1:0;}));
        $ok=curl_exec($ch); $error=curl_error($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $final=(string)curl_getinfo($ch,CURLINFO_EFFECTIVE_URL); curl_close($ch); fclose($fp);
        if (!$ok || $code<200 || $code>=300 || !$this->validGithubUrl($final)) { @unlink($path); throw new RuntimeException($error!==''?'更新下载失败：'.$error:'GitHub 更新资源不可用。'); }
        if ($size>$limit) { @unlink($path); throw new RuntimeException('更新响应过大。'); }
        return (string)@file_get_contents($path);
    }
    private function setMaintenance($on){$path=$this->root.'/storage/update/maintenance.flag'; if($on){if(!is_dir(dirname($path))&&!@mkdir(dirname($path),0750,true)&&!is_dir(dirname($path)))throw new RuntimeException('无法创建维护模式目录。');if(@file_put_contents($path,date('c'),LOCK_EX)===false)throw new RuntimeException('无法启用维护模式。');}elseif(is_file($path))@unlink($path);}
    private function log($message){$dir=$this->root.'/storage/logs';if(!is_dir($dir))@mkdir($dir,0750,true);@file_put_contents($dir.'/update.log',date('c').' '.$message.PHP_EOL,FILE_APPEND|LOCK_EX);}
    private function extractPackage($zipPath,$dir){if(file_exists($dir)||is_link($dir))throw new RuntimeException('解压目录已存在，拒绝使用残留文件。');if(!is_dir($dir)&&!@mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('无法创建解压目录。');$zip=new ZipArchive();if($zip->open($zipPath)!==true)throw new RuntimeException('更新包无法打开。');if(!$zip->extractTo($dir)){ $zip->close();throw new RuntimeException('更新包解压失败。');}$zip->close();}
    private function lintTree($dir){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS)) as $file){if(substr($file->getFilename(),-4)==='.php'){exec(escapeshellarg($this->cliBinary()).' -l '.escapeshellarg($file->getPathname()),$out,$code);if($code!==0)throw new RuntimeException('更新 PHP 语法检查失败。');}}}
    private function installTree($dir){$files=array();$base=$dir;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS));foreach($it as $file){if(!$file->isFile())continue;$relative=str_replace('\\','/',substr($file->getPathname(),strlen($base)+1));if(!$this->allowedPath($relative))continue;$target=$this->safeTarget($relative);if(!is_dir(dirname($target))&&!@mkdir(dirname($target),0750,true)&&!is_dir(dirname($target)))throw new RuntimeException('无法创建更新目录。');$tmp=$target.'.update-tmp';if(!copy($file->getPathname(),$tmp)||!@rename($tmp,$target))throw new RuntimeException('替换更新文件失败。');$files[]=$relative;}return $files;}
    private function request($url,$limit=1048576){
        if (!function_exists('curl_init')) throw new RuntimeException('服务器未启用 cURL，无法连接 GitHub。');
        if (!$this->validGithubUrl($url)) throw new RuntimeException('更新下载地址无效。');
        $ch=curl_init($url); curl_setopt_array($ch,array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>60,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERAGENT=>'yunshield-vpn-shop-updater',CURLOPT_HTTPHEADER=>array('Accept: application/vnd.github+json'))); $data=curl_exec($ch);$error=curl_error($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$final=(string)curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);curl_close($ch);if($data===false||$code<200||$code>=300||!$this->validGithubUrl($final))throw new RuntimeException($error!==''?'GitHub 请求失败：'.$error:'GitHub 更新资源不可用。');if(strlen($data)>$limit)throw new RuntimeException('更新响应过大。');return $data;
    }
    private function validGithubUrl($url){$parts=is_string($url)?parse_url($url):false;return is_array($parts)&&isset($parts['scheme'],$parts['host'])&&$parts['scheme']==='https'&&in_array(strtolower($parts['host']),array('github.com','api.github.com','release-assets.githubusercontent.com','objects.githubusercontent.com'),true)&&empty($parts['user'])&&!isset($parts['port']);}
    private function updateConfig(){return isset($this->config['update'])&&is_array($this->config['update'])?$this->config['update']:array();}
    private function webHealth($expected)
    {
        $u=$this->updateConfig();
        if(empty($u['health_url'])||empty($u['health_token'])||strlen($u['health_token'])<32)throw new RuntimeException('请配置真实站点健康检查地址和专用令牌。');
        $parts=parse_url($u['health_url']);$base=isset($this->config['app']['base_url'])?parse_url($this->config['app']['base_url']):false;
        if(!$parts||!$base||!isset($parts['host'],$base['host'])||strtolower($parts['host'])!==strtolower($base['host'])||$parts['scheme']!=='https'||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment']))throw new RuntimeException('健康检查必须使用本站 HTTPS 地址。');
        $h=curl_init($u['health_url']);curl_setopt_array($h,array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>array('X-Update-Health-Token: '.$u['health_token'])));
        $raw=curl_exec($h);$code=curl_getinfo($h,CURLINFO_HTTP_CODE);curl_close($h);$data=is_string($raw)?json_decode($raw,true):null;
        if($code!==200||!is_array($data)||empty($data['ok'])||!isset($data['version'])||$data['version']!==$expected)throw new RuntimeException('真实 Web 健康检查未通过。');
    }
    private function safeTarget($relative)
    {
        if(!$this->allowedPath($relative))throw new RuntimeException('更新目标路径无效。');
        $target=$this->root;foreach(explode('/',$relative) as $part){$target.='/'.$part;if(is_link($target))throw new RuntimeException('更新目标不允许符号链接。');}
        return $target;
    }
    private function cliBinary()
    {
        $u=$this->updateConfig();$php=isset($u['php_cli'])&&$u['php_cli']!==''?$u['php_cli']:(PHP_SAPI==='cli'?PHP_BINARY:'');
        if(!is_string($php)||!is_file($php)||!is_executable($php))throw new RuntimeException('请配置可执行的 PHP CLI 绝对路径。');
        $out=array();$code=1;exec(escapeshellarg($php).' -r '.escapeshellarg('echo PHP_SAPI;'),$out,$code);
        if($code!==0||trim(implode("\n",$out))!=='cli')throw new RuntimeException('更新执行器必须为 PHP CLI。');
        return $php;
    }
    private function installEnabled(){return !empty($this->updateConfig()['install_enabled']);}
    private function assertInstallable()
    {
        if (!$this->installEnabled()) throw new RuntimeException('在线安装功能尚未启用。');
        if (!class_exists('ZipArchive') || !function_exists('sodium_crypto_sign_verify_detached')) throw new RuntimeException('在线更新需要 ZIP 与 Sodium 扩展。');
        $key=isset($this->updateConfig()['public_key'])?base64_decode($this->updateConfig()['public_key'],true):false;
        if($key===false||strlen($key)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)throw new RuntimeException('请先配置有效的更新签名公钥。');
    }
    private function packagePrefix(){return isset($this->updateConfig()['package_name_prefix'])&&preg_match('/^[A-Za-z0-9_.-]+$/',$this->updateConfig()['package_name_prefix'])?$this->updateConfig()['package_name_prefix']:'yunshield-vpn-shop-';}
    private function cacheSeconds(){return max(60,min(86400,(int)(isset($this->updateConfig()['cache_seconds'])?$this->updateConfig()['cache_seconds']:900)));}
    private function isVersion($v){return (bool)preg_match('/^\d+(?:\.\d+){1,3}$/',$v);}
    private function result($current,$status,$message){return $this->withCurrentVersion(array('status'=>$status,'message'=>$message,'latest_version'=>'','release_name'=>'','published_at'=>'','release_url'=>'','has_update'=>false,'checked_at'=>date('c'),'assets_ready'=>false));}
    private function withCurrentVersion($r){$r['current_version']=$this->currentVersion();$r['has_update']=isset($r['latest_version'])&&$this->isVersion($r['latest_version'])&&version_compare($r['latest_version'],$r['current_version'],'>');return $r;}
    private function store($r){$path=$this->root.'/storage/cache/update-check.json';$dir=dirname($path);if(!is_dir($dir)&&!@mkdir($dir,0750,true)&&!is_dir($dir))return $r;$tmp=$path.'.'.getmypid().'.tmp';if(@file_put_contents($tmp,json_encode(array('checked_at'=>time(),'result'=>$r),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX)!==false)@rename($tmp,$path);return $r;}
    private function writeState($id,$state){$dir=$this->updateRoot.'/staging/'.$id;if(!is_dir($dir)&&!@mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('无法保存更新状态。');$tmp=$dir.'/state.json.'.getmypid().'.tmp';if(@file_put_contents($tmp,json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX)===false||!@rename($tmp,$dir.'/state.json'))throw new RuntimeException('无法保存更新状态。');}
    private function readState($id){if(!preg_match('/^[a-f0-9]{32}$/',$id))return null;$raw=@file_get_contents($this->updateRoot.'/staging/'.$id.'/state.json');$s=$raw===false?null:json_decode($raw,true);return is_array($s)?$s:null;}
    private function lock(){$dir=$this->updateRoot;if(!is_dir($dir)&&!@mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('无法创建更新目录。');$fp=@fopen($dir.'/update.lock','c');if(!$fp||!flock($fp,LOCK_EX|LOCK_NB))throw new RuntimeException('已有更新任务正在执行。');return $fp;}
    private function unlock($fp){if($fp){flock($fp,LOCK_UN);fclose($fp);}}
    private function backup($version,$id){$backupId=date('YmdHis').'-'.$id;$dir=$this->updateRoot.'/backups/'.$backupId;if(!@mkdir($dir,0750,true))throw new RuntimeException('无法创建更新备份。');$files=array();foreach(array('app','public','config/config.example.php','database','scripts','README.md','CHANGELOG.md','VERSION','.gitignore') as $item){$src=$this->root.'/'.$item;if(is_dir($src))$this->copyTree($src,$dir.'/'.$item,$dir,$files);elseif(is_file($src)){if(!@mkdir(dirname($dir.'/'.$item),0750,true)&&!is_dir(dirname($dir.'/'.$item)))throw new RuntimeException('无法创建备份目录。');if(!copy($src,$dir.'/'.$item))throw new RuntimeException('备份文件失败。');$files[]=$item;}}$hashes=array();foreach($files as $file)$hashes[$file]=hash_file('sha256',$dir.'/'.$file);$json=json_encode(array('id'=>$backupId,'files'=>$files,'hashes'=>$hashes,'created_at'=>date('c')),JSON_UNESCAPED_UNICODE);if($json===false||file_put_contents($dir.'/manifest.json',$json,LOCK_EX)===false)throw new RuntimeException('无法保存完整备份清单。');return array('id'=>$backupId,'files'=>$files);}
    private function copyTree($src,$dst,$backupRoot,&$files){if(!is_dir($dst)&&!@mkdir($dst,0750,true)&&!is_dir($dst))throw new RuntimeException('无法创建备份目录。');foreach(scandir($src) as $name){if($name==='.'||$name==='..')continue;$a=$src.'/'.$name;$b=$dst.'/'.$name;if(is_link($a))throw new RuntimeException('备份目录包含符号链接，请先人工处理。');if(is_dir($a))$this->copyTree($a,$b,$backupRoot,$files);else{if(!copy($a,$b))throw new RuntimeException('备份文件失败。');$files[]=str_replace('\\','/',substr($b,strlen($backupRoot)+1));}}}
    private function restoreBackup($id){if(!preg_match('/^[0-9]{14}-[a-f0-9]{32}$/',$id))throw new RuntimeException('备份编号无效。');$dir=$this->updateRoot.'/backups/'.$id;$raw=@file_get_contents($dir.'/manifest.json');$m=$raw===false?null:json_decode($raw,true);if(!is_array($m)||empty($m['files']))throw new RuntimeException('备份不存在或已损坏。');foreach($m['files'] as $file){if(!$this->allowedPath($file)||!is_file($dir.'/'.$file)||is_link($dir.'/'.$file))throw new RuntimeException('备份文件缺失或不安全，已停止恢复并保留维护。');if(!isset($m['hashes'][$file])||!hash_equals($m['hashes'][$file],hash_file('sha256',$dir.'/'.$file)))throw new RuntimeException('备份内容校验失败，已保持维护。');}
        $original=array();foreach($m['files'] as $file){if(!$this->allowedPath($file))continue;$original[$file]=true;$src=$dir.'/'.$file;$dst=$this->safeTarget($file);if(is_file($src)){if(!is_dir(dirname($dst))&&!@mkdir(dirname($dst),0750,true)&&!is_dir(dirname($dst)))throw new RuntimeException('无法创建回滚目录。');$tmp=$dst.'.restore-tmp';if(!copy($src,$tmp)||!rename($tmp,$dst))throw new RuntimeException('回滚文件失败。');}}foreach(isset($m['installed_files'])&&is_array($m['installed_files'])?$m['installed_files']:array() as $file){if(!$this->allowedPath($file)||isset($original[$file]))continue;$target=$this->safeTarget($file);if(is_file($target)&&!@unlink($target))throw new RuntimeException('清理新增文件失败。');}}
    private function markBackupInstalled($id,$files){$path=$this->updateRoot.'/backups/'.$id.'/manifest.json';$raw=@file_get_contents($path);$manifest=$raw===false?array():json_decode($raw,true);if(!is_array($manifest))$manifest=array();$manifest['installed_files']=$files;$json=json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$tmp=$path.'.tmp';if($json===false||file_put_contents($tmp,$json,LOCK_EX)===false||!rename($tmp,$path))throw new RuntimeException('无法持久保存安装恢复清单。');}
    private function trimBackups($protectedId){ $dir=$this->updateRoot.'/backups'; if(!is_dir($dir))return; $items=array_filter(glob($dir.'/*'), 'is_dir'); usort($items,function($a,$b){return strcmp($b,$a);}); $keep=max(1,min(10,(int)(isset($this->updateConfig()['backup_keep'])?$this->updateConfig()['backup_keep']:3))); $others=array_values(array_filter($items,function($item)use($protectedId){return basename($item)!==$protectedId;}));foreach(array_slice($others,max(0,$keep-1)) as $item)$this->removeTree($item); }
    private function removeTree($dir){if(!is_dir($dir))return;foreach(scandir($dir) as $n){if($n==='.'||$n==='..')continue;$p=$dir.'/'.$n;if(is_dir($p))$this->removeTree($p);else@unlink($p);}@rmdir($dir);}
    private function requireCli(){if(PHP_SAPI!=='cli')throw new RuntimeException('该操作仅允许由更新 worker 执行。');}
}
