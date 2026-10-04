<?php
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$root = dirname(__DIR__);
$config = require $root . '/config/config.php';
require $root . '/app/Services/UpdateService.php';
$action = isset($argv[1]) ? $argv[1] : '';
if (!preg_match('/^--(install|rollback)=([a-f0-9]{32}|[0-9]{14}-[a-f0-9]{32})$/', $action, $matches)) exit("Invalid update action\n");
try {
    $service = new UpdateService($config);
    if ($matches[1] === 'install') $service->performInstall($matches[2]);
    else $service->rollback($matches[2]);
    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, "更新失败：" . $e->getMessage() . "\n");
    exit(1);
}
