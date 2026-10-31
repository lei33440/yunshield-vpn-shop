<?php
/** Offline reproducible release builder. Never loads live config or modifies VERSION. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
function releaseBuild($root, $output, $version, $migration, $minPhp = '7.4.0')
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('ZipArchive is required.');
    if (!preg_match('/^\d+(?:\.\d+){1,3}$/D', $version) || !preg_match('/^\d+(?:\.\d+){1,3}$/D', $minPhp)) throw new RuntimeException('Invalid version.');
    if (!is_bool($migration)) throw new RuntimeException('Explicit migration_required boolean is required.');
    $root = realpath($root);
    if (!$root || !is_dir($output)) throw new RuntimeException('Source and existing output directory are required.');
    $output = realpath($output);
    if (strpos(str_replace('\\', '/', $output) . '/', str_replace('\\', '/', $root) . '/') === 0) throw new RuntimeException('Output must be outside the source tree.');
    $paths = array();
    foreach (array('app', 'public') as $tree) {
        if (!is_dir($root . '/' . $tree) || is_link($root . '/' . $tree)) throw new RuntimeException('Required source tree missing or linked.');
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $tree, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $file) {
            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if ($file->isLink()) throw new RuntimeException('Symlinks are forbidden: ' . $path);
            if (!$file->isFile()) continue;
            if (preg_match('#(^|/)(?:\.git|\.env(?:\..*)?|storage|uploads?|exports?|backups?|local\.sql|Dockerfile)(/|$)|\.(?:pem|key|p12|pfx|sql|zip|tar|gz|bak|log)$#i', $path)) throw new RuntimeException('Sensitive or generated file in code tree: ' . $path);
            $paths[$path] = $file->getPathname();
        }
    }
    // Only runtime workers and schema reference; no migrations, seeders or admin utilities execute on update.
    foreach (array('config/config.example.php', 'database/schema.sql', 'scripts/update_worker.php', 'scripts/update_health.php', 'scripts/group_order_worker.php', 'scripts/wecom_notification_worker.php', 'scripts/customer_email_worker.php') as $path) {
        if (is_link($root . '/' . $path) || !is_file($root . '/' . $path)) throw new RuntimeException('Required runtime file missing or linked: ' . $path);
        $paths[$path] = $root . '/' . $path;
    }
    $paths['VERSION'] = null;
    ksort($paths, SORT_STRING);
    $package = 'yunshield-vpn-shop-' . $version . '.zip';
    foreach (array($package, 'update-manifest.json', 'update-files.json') as $name) if (file_exists($output . '/' . $name)) throw new RuntimeException('Refusing to overwrite existing release artifacts.');
    $temp = $output . '/.release-' . bin2hex(random_bytes(8)) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($temp, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('Cannot create release ZIP.');
    $hashes = array();
    try {
        if (!method_exists($zip, 'setMtimeName')) throw new RuntimeException('发布构建需要支持 ZipArchive::setMtimeName 的 PHP/libzip 环境；网站运行仍支持 PHP 7.4。');
        foreach ($paths as $path => $source) {
            $bytes = $source === null ? $version . "\n" : file_get_contents($source);
            if ($bytes === false || !$zip->addFromString($path, $bytes)) throw new RuntimeException('Cannot add release file: ' . $path);
            // STORE avoids zlib-version-dependent compressed output. Fixed DOS timestamp and Unix mode.
            if (!$zip->setCompressionName($path, ZipArchive::CM_STORE) || !$zip->setMtimeName($path, 946684800) || !$zip->setExternalAttributesName($path, ZipArchive::OPSYS_UNIX, 0100644 << 16)) throw new RuntimeException('Cannot normalize ZIP metadata.');
            $hashes[$path] = hash('sha256', $bytes);
        }
        if (!$zip->close()) throw new RuntimeException('Cannot finish release ZIP.');
        $manifest = array('version'=>$version, 'package'=>$package, 'sha256'=>hash_file('sha256', $temp), 'min_php'=>$minPhp, 'migration_required'=>$migration, 'files'=>$hashes);
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        if (!rename($temp, $output . '/' . $package)) throw new RuntimeException('Cannot save package.');
        foreach (array('update-manifest.json'=>$json, 'update-files.json'=>json_encode($hashes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") as $name=>$bytes) {
            $fp = fopen($output . '/' . $name, 'xb');
            if (!$fp || fwrite($fp, $bytes) !== strlen($bytes)) throw new RuntimeException('Cannot save release metadata.');
            fclose($fp);
        }
        return $manifest;
    } catch (Throwable $e) { @$zip->close(); @unlink($temp); throw $e; }
}
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        $o = getopt('', array('source:', 'output:', 'version:', 'migration-required:', 'min-php:'));
        if (!isset($o['output'], $o['version'], $o['migration-required']) || !in_array($o['migration-required'], array('true','false'), true)) throw new RuntimeException('Usage: php scripts/build_release.php --output=EXISTING_EXTERNAL_DIR --version=X.Y.Z --migration-required=true|false [--source=DIR] [--min-php=7.4.0]. Declare true when schema changes are needed; updater will refuse automatic installation.');
        $m = releaseBuild(isset($o['source'])?$o['source']:dirname(__DIR__), $o['output'], $o['version'], $o['migration-required']==='true', isset($o['min-php'])?$o['min-php']:'7.4.0');
        echo json_encode(array('package'=>$m['package'],'sha256'=>$m['sha256'],'files'=>count($m['files']),'migration_required'=>$m['migration_required']), JSON_UNESCAPED_SLASHES) . "\n";
    } catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
}
