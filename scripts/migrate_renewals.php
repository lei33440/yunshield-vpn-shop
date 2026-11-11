<?php
// 定向结构升级：不回填历史订单金额，不改变订单或资金状态；执行前先备份。
function migrate_renewal_schema($pdo,$root){
    $column=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="orders" AND COLUMN_NAME=?');
    foreach(array('order_kind'=>"VARCHAR(16) NOT NULL DEFAULT 'purchase'",'parent_order_id'=>'BIGINT UNSIGNED NULL') as $name=>$definition){
        $column->execute(array($name));if(!(int)$column->fetchColumn())$pdo->exec('ALTER TABLE orders ADD '.$name.' '.$definition);
    }
    $index=$pdo->query('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="orders" AND INDEX_NAME="idx_order_parent_kind"')->fetchColumn();
    if(!(int)$index)$pdo->exec('ALTER TABLE orders ADD KEY idx_order_parent_kind(parent_order_id,order_kind,status)');
    $sql=file_get_contents($root.'/database/schema.sql');
    if(!preg_match('/^CREATE TABLE IF NOT EXISTS order_renewals \([^;]+;/m',$sql,$matches))throw new RuntimeException('续费结构定义不存在。');
    $pdo->exec($matches[0]);
}
if(PHP_SAPI==='cli'&&isset($_SERVER['SCRIPT_FILENAME'])&&realpath($_SERVER['SCRIPT_FILENAME'])===__FILE__){
    $root=dirname(__DIR__);$config=require $root.(is_file($root.'/config/config.php')?'/config/config.php':'/config/config.example.php');
    require_once $root.'/app/Core/Database.php';migrate_renewal_schema(Database::connect($config),$root);echo "订阅续费结构已就绪。\n";
}
