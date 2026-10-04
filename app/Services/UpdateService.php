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
        $release = $this->latestRelease(true);
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
            $state = array('id'=>$id,'status'=>'verified','version'=>$manifest['version'],'release_url'=>$release['release_url'],'release_name'=>$release['release_name'],'prepared_at'=>date('c'),'manifest'=>$manifest);
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
        $state = $action === 'install' ? $this->readState($id) : array('id'=>$id);
        if (!$state || ($action === 'install' && $state['status'] !== 'verified')) throw new RuntimeException('更新任务不存在或状态无效。');
        $worker = $this->root . '/scripts/update_worker.php';
        $php = PHP_BINARY;
        if (stripos(PHP_OS, 'WIN') === 0) {
            $command = 'start "" /B ' . escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg('--'.$action.'='.$id);
            @pclose(@popen($command, 'r'));
        } else {
            $command = escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg('--'.$action.'='.$id) . ' >/dev/null 2>&1 &';
            @exec($command);
        }
        return $id;
    }

    public function status($id = '')
    {
        if ($id !== '') return $this->readState($id);
        $dir = $this->updateRoot . '/staging';
        $files = is_dir($dir) ? glob($dir . '/*/state.json') : array();
        $latest = null;
        foreach ($files as $file) { $item = json_decode((string)@file_get_contents($file), true); if (is_array($item) && (!$latest || strcmp($item['prepared_at'], $latest['prepared_at']) > 0)) $latest = $item; }
        return $latest;
    }

    public function performInstall($id)
    {
        $this->requireCli();
        $state = $this->readState($id);
        if (!$state || $state['status'] !== 'verified') throw new RuntimeException('更新任务状态无效。');
        $lock = $this->lock();
        try {
            $dir = $this->updateRoot . '/staging/' . $id;
            $zipPath = $dir . '/package.zip';
            $manifest = $state['manifest'];
            $this->validatePackage($zipPath, $manifest);
            $backup = $this->backup($manifest['version'], $id);
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) throw new RuntimeException('无法打开更新包。');
            $rootPrefix = $this->packageRootPrefix($zip);
            $installed = array();
            for ($i=0; $i<$zip->numFiles; $i++) {
                $entry = str_replace('\\','/',$zip->getNameIndex($i));
                if ($entry === '' || substr($entry,-1)==='/') continue;
                $relative = $rootPrefix !== '' && strpos($entry,$rootPrefix)===0 ? substr($entry,strlen($rootPrefix)) : $entry;
                $relative = ltrim($relative,'/');
                if (!$this->allowedPath($relative)) throw new RuntimeException('更新包包含禁止覆盖的文件。');
                $target = $this->root . '/' . $relative;
                $parent = dirname($target);
                if (!is_dir($parent) && !@mkdir($parent,0750,true) && !is_dir($parent)) throw new RuntimeException('无法创建更新目录。');
                $contents = $zip->getFromIndex($i);
                if ($contents === false || @file_put_contents($target . '.update-tmp', $contents, LOCK_EX) === false) throw new RuntimeException('写入更新文件失败。');
                if (!@rename($target . '.update-tmp', $target)) throw new RuntimeException('替换更新文件失败。');
                $installed[] = $relative;
            }
            $zip->close();
            $this->markBackupInstalled($backup['id'], $installed);
            $this->writeState($id, array_merge($state,array('status'=>'installed','installed_at'=>date('c'),'backup_id'=>$backup['id'],'files'=>$installed)));
            $this->trimBackups();
        } catch (Exception $e) {
            if (isset($backup)) $this->restoreBackup($backup['id']);
            $this->writeState($id, array_merge($state,array('status'=>'failed','error'=>'更新失败，已尝试恢复备份。','failed_at'=>date('c'))));
            throw $e;
        } finally { $this->unlock($lock); }
    }

    public function rollback($backupId)
    {
        $this->requireCli();
        $lock = $this->lock();
        try { $this->restoreBackup($backupId); } finally { $this->unlock($lock); }
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
        if (isset($manifest['min_php']) && version_compare(PHP_VERSION,(string)$manifest['min_php'],'<')) throw new RuntimeException('当前 PHP 版本不满足更新要求。');
    }

    private function validatePackage($path, $manifest)
    {
        if (!is_file($path) || !hash_equals(strtolower($manifest['sha256']),hash_file('sha256',$path))) throw new RuntimeException('更新包校验失败。');
        $zip = new ZipArchive(); if ($zip->open($path)!==true) throw new RuntimeException('更新包无法打开。');
        $this->packageRootPrefix($zip); for($i=0;$i<$zip->numFiles;$i++){ $name=str_replace('\\','/',$zip->getNameIndex($i)); if(substr($name,-1)!=='/' && !$this->allowedPath($this->stripPackagePrefix($name,$zip))) { $zip->close(); throw new RuntimeException('更新包包含不允许的路径。'); } }
        $zip->close();
    }

    private function packageRootPrefix($zip)
    {
        $first=''; for($i=0;$i<$zip->numFiles;$i++){ $n=str_replace('\\','/',$zip->getNameIndex($i)); if($n!==''){ $slash=strpos($n,'/'); $first=$slash===false?'':substr($n,0,$slash+1); break; } }
        if ($first === '' || in_array(rtrim($first,'/'),array('app','public','config','database','scripts'),true)) return '';
        return $first;
    }
    private function stripPackagePrefix($name,$zip){$name=ltrim(str_replace('\\','/',$name),'/');$prefix=$this->packageRootPrefix($zip);return $prefix!==''&&strpos($name,$prefix)===0?substr($name,strlen($prefix)):$name;}
    private function allowedPath($path){$path=ltrim(str_replace('\\','/',$path),'/'); if($path===''||strpos($path,'..')!==false||preg_match('#^(config/config\.php|\.env|storage/|\.git/)#i',$path)) return false; return (bool)preg_match('#^(app/|public/|config/config\.example\.php$|database/|scripts/|README\.md$|CHANGELOG\.md$|VERSION$|\.gitignore$)#i',$path);}
    private function download($url,$path,$limit){$data=$this->request($url,$limit); if(@file_put_contents($path,$data,LOCK_EX)===false)throw new RuntimeException('无法保存更新文件。');return $data;}
    private function request($url,$limit=1048576){if(!$this->validGithubUrl($url))throw new RuntimeException('更新下载地址无效。');$accept=parse_url($url,PHP_URL_HOST)==='api.github.com'?'application/vnd.github+json':'application/octet-stream';$ctx=stream_context_create(array('http'=>array('method'=>'GET','timeout'=>10,'ignore_errors'=>true,'header'=>"Accept: ".$accept."\r\nUser-Agent: yunshield-vpn-shop-updater\r\n"),'ssl'=>array('verify_peer'=>true,'verify_peer_name'=>true)));$fp=@fopen($url,'rb',false,$ctx);if(!$fp)throw new RuntimeException('无法连接 GitHub。');$data='';while(!feof($fp)){$chunk=fread($fp,8192);if($chunk===false||strlen($data)+strlen($chunk)>$limit){fclose($fp);throw new RuntimeException('更新响应过大或读取失败。');}$data.=$chunk;}fclose($fp);$status=0;if(isset($http_response_header[0])&&preg_match('/\s(\d{3})\s/',$http_response_header[0],$m))$status=(int)$m[1];if($status<200||$status>=300)throw new RuntimeException('GitHub 更新资源不可用。');return $data;}
    private function validGithubUrl($url){$parts=is_string($url)?parse_url($url):false;return is_array($parts)&&isset($parts['scheme'],$parts['host'])&&$parts['scheme']==='https'&&in_array(strtolower($parts['host']),array('github.com','api.github.com'),true)&&empty($parts['user'])&&!isset($parts['port']);}
    private function updateConfig(){return isset($this->config['update'])&&is_array($this->config['update'])?$this->config['update']:array();}
    private function installEnabled(){return !empty($this->updateConfig()['install_enabled']);}
    private function packagePrefix(){return isset($this->updateConfig()['package_name_prefix'])&&preg_match('/^[A-Za-z0-9_.-]+$/',$this->updateConfig()['package_name_prefix'])?$this->updateConfig()['package_name_prefix']:'yunshield-vpn-shop-';}
    private function cacheSeconds(){return max(60,min(86400,(int)(isset($this->updateConfig()['cache_seconds'])?$this->updateConfig()['cache_seconds']:900)));}
    private function isVersion($v){return (bool)preg_match('/^\d+(?:\.\d+){1,3}$/',$v);}
    private function result($current,$status,$message){return $this->withCurrentVersion(array('status'=>$status,'message'=>$message,'latest_version'=>'','release_name'=>'','published_at'=>'','release_url'=>'','has_update'=>false,'checked_at'=>date('c'),'assets_ready'=>false));}
    private function withCurrentVersion($r){$r['current_version']=$this->currentVersion();return $r;}
    private function store($r){$path=$this->root.'/storage/cache/update-check.json';$dir=dirname($path);if(!is_dir($dir)&&!@mkdir($dir,0750,true)&&!is_dir($dir))return $r;$tmp=$path.'.'.getmypid().'.tmp';if(@file_put_contents($tmp,json_encode(array('checked_at'=>time(),'result'=>$r),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX)!==false)@rename($tmp,$path);return $r;}
    private function writeState($id,$state){$dir=$this->updateRoot.'/staging/'.$id;if(!is_dir($dir)&&!@mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('无法保存更新状态。');@file_put_contents($dir.'/state.json',json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX);}
    private function readState($id){if(!preg_match('/^[a-f0-9]{32}$/',$id))return null;$raw=@file_get_contents($this->updateRoot.'/staging/'.$id.'/state.json');$s=$raw===false?null:json_decode($raw,true);return is_array($s)?$s:null;}
    private function lock(){$dir=$this->updateRoot;if(!is_dir($dir)&&!@mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('无法创建更新目录。');$fp=@fopen($dir.'/update.lock','c');if(!$fp||!flock($fp,LOCK_EX|LOCK_NB))throw new RuntimeException('已有更新任务正在执行。');return $fp;}
    private function unlock($fp){if($fp){flock($fp,LOCK_UN);fclose($fp);}}
    private function backup($version,$id){$backupId=date('YmdHis').'-'.$id;$dir=$this->updateRoot.'/backups/'.$backupId;if(!@mkdir($dir,0750,true))throw new RuntimeException('无法创建更新备份。');$files=array();foreach(array('app','public','config/config.example.php','database','scripts','README.md','CHANGELOG.md','VERSION','.gitignore') as $item){$src=$this->root.'/'.$item;if(is_dir($src))$this->copyTree($src,$dir.'/'.$item,$dir,$files);elseif(is_file($src)){if(!@mkdir(dirname($dir.'/'.$item),0750,true)&&!is_dir(dirname($dir.'/'.$item)))throw new RuntimeException('无法创建备份目录。');if(!copy($src,$dir.'/'.$item))throw new RuntimeException('备份文件失败。');$files[]=$item;}}@file_put_contents($dir.'/manifest.json',json_encode(array('id'=>$backupId,'files'=>$files,'created_at'=>date('c')),JSON_UNESCAPED_UNICODE));return array('id'=>$backupId,'files'=>$files);}
    private function copyTree($src,$dst,$backupRoot,&$files){if(!is_dir($dst)&&!@mkdir($dst,0750,true)&&!is_dir($dst))throw new RuntimeException('无法创建备份目录。');foreach(scandir($src) as $name){if($name==='.'||$name==='..')continue;$a=$src.'/'.$name;$b=$dst.'/'.$name;if(is_dir($a))$this->copyTree($a,$b,$backupRoot,$files);else{if(!copy($a,$b))throw new RuntimeException('备份文件失败。');$files[]=str_replace('\\','/',substr($b,strlen($backupRoot)+1));}}}
    private function restoreBackup($id){if(!preg_match('/^[0-9]{14}-[a-f0-9]{32}$/',$id))throw new RuntimeException('备份编号无效。');$dir=$this->updateRoot.'/backups/'.$id;$raw=@file_get_contents($dir.'/manifest.json');$m=$raw===false?null:json_decode($raw,true);if(!is_array($m)||empty($m['files']))throw new RuntimeException('备份不存在或已损坏。');$original=array();foreach($m['files'] as $file){if(!$this->allowedPath($file))continue;$original[$file]=true;$src=$dir.'/'.$file;$dst=$this->root.'/'.$file;if(is_file($src)){if(!is_dir(dirname($dst))&&!@mkdir(dirname($dst),0750,true)&&!is_dir(dirname($dst)))throw new RuntimeException('无法创建回滚目录。');if(!copy($src,$dst))throw new RuntimeException('回滚文件失败。');}}foreach(isset($m['installed_files'])&&is_array($m['installed_files'])?$m['installed_files']:array() as $file){if(!$this->allowedPath($file)||isset($original[$file]))continue;$target=$this->root.'/'.$file;if(is_file($target)&&!@unlink($target))throw new RuntimeException('清理新增文件失败。');}}
    private function markBackupInstalled($id,$files){$path=$this->updateRoot.'/backups/'.$id.'/manifest.json';$raw=@file_get_contents($path);$manifest=$raw===false?array():json_decode($raw,true);if(!is_array($manifest))$manifest=array();$manifest['installed_files']=$files;@file_put_contents($path,json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX);}
    private function trimBackups(){ $dir=$this->updateRoot.'/backups'; if(!is_dir($dir))return; $items=array_filter(glob($dir.'/*'), 'is_dir'); usort($items,function($a,$b){return strcmp($b,$a);}); $keep=max(1,min(10,(int)(isset($this->updateConfig()['backup_keep'])?$this->updateConfig()['backup_keep']:3))); foreach(array_slice($items,$keep) as $item)$this->removeTree($item); }
    private function removeTree($dir){if(!is_dir($dir))return;foreach(scandir($dir) as $n){if($n==='.'||$n==='..')continue;$p=$dir.'/'.$n;if(is_dir($p))$this->removeTree($p);else@unlink($p);}@rmdir($dir);}
    private function requireCli(){if(PHP_SAPI!=='cli')throw new RuntimeException('该操作仅允许由更新 worker 执行。');}
}
