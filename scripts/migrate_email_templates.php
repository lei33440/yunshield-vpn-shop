<?php
/** 专用幂等迁移：仅创建 email_templates，不更新任何业务数据。 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
function email_templates_migrate($pdo){
    $schema=file_get_contents(dirname(__DIR__).'/database/schema.sql');
    if(!preg_match('/CREATE TABLE IF NOT EXISTS email_templates\s*\([\s\S]*?\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/',$schema,$m))throw new RuntimeException('email_templates_schema_missing');
    $pdo->exec($m[0]);return 1;
}
if(realpath(isset($_SERVER['SCRIPT_FILENAME'])?$_SERVER['SCRIPT_FILENAME']:'')===realpath(__FILE__)){
    if(array_slice($argv,1)!==array('--apply')){fwrite(STDERR,"usage: php scripts/migrate_email_templates.php --apply\n请先备份目标数据库；此脚本仅创建 email_templates 表。\n");exit(2);}
    try{$root=dirname(__DIR__);$config=require $root.'/config/config.php';require_once $root.'/app/Core/Database.php';email_templates_migrate(Database::connect($config));fwrite(STDOUT,"email_templates_ready\n");}
    catch(Throwable $e){fwrite(STDERR,"email_templates_migration_failed\n");exit(1);}
}
