<?php
/** 专用幂等增量：仅创建三张客户群发表，不执行总迁移或历史数据更新。 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
function customer_email_migrate($pdo){
    $schema=file_get_contents(dirname(__DIR__).'/database/schema.sql');$ddl=array();
    foreach(array('email_campaigns','email_campaign_recipients','email_preferences') as $table){
        if(!preg_match('/CREATE TABLE IF NOT EXISTS '.preg_quote($table,'/').'\s*\([\s\S]*?\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/',$schema,$m))throw new RuntimeException('客户邮件表定义缺失。');
        $ddl[]=$m[0];
    }
    foreach($ddl as $sql)$pdo->exec($sql);
    return count($ddl);
}
if(realpath(isset($_SERVER['SCRIPT_FILENAME'])?$_SERVER['SCRIPT_FILENAME']:'')===realpath(__FILE__)){
    if(array_slice($argv,1)!==array('--apply')){fwrite(STDERR,"usage: php scripts/migrate_customer_email.php --apply\n请先备份目标数据库；此脚本仅创建三个新表。\n");exit(2);}
    try{$root=dirname(__DIR__);$config=require $root.'/config/config.php';require_once $root.'/app/Core/Database.php';customer_email_migrate(Database::connect($config));fwrite(STDOUT,"customer_email_tables_ready\n");}
    catch(Throwable $e){fwrite(STDERR,"customer_email_migration_failed\n");exit(1);}
}
