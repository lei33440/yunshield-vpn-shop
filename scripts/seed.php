<?php
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$config = require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/app/Core/Database.php';
try {
    $pdo = Database::connect($config);
    foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', file_get_contents(dirname(__DIR__) . '/database/seed.sql')))) as $statement) $pdo->exec($statement);
    echo "演示套餐导入完成。\n";
} catch (Exception $e) { fwrite(STDERR, "种子失败：" . $e->getMessage() . "\n"); exit(1); }
