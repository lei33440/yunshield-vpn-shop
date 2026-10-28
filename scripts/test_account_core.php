<?php
// Explicit isolated DSN only; no site configuration, worker or network calls.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$dsn=getenv('CHAOXI_TEST_DSN');$parts=$dsn&&strpos($dsn,'mysql:')===0?explode(';',substr($dsn,6)):array();$names=array();
foreach($parts as $part){if(preg_match('/^\s*dbname\s*=(.*)$/i',$part,$m))$names[]=$m[1];}
if(count($names)!==1||!preg_match('/\Achaoxi_wallet_test_[A-Za-z0-9_]+\z/D',$names[0]))throw new RuntimeException('Explicit unique isolated dbname required');
$pdo=new PDO($dsn,getenv('CHAOXI_TEST_USER'),getenv('CHAOXI_TEST_PASSWORD'),array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false));
if($pdo->query('SELECT DATABASE()')->fetchColumn()!==$names[0])throw new RuntimeException('Actual database mismatch');
date_default_timezone_set('UTC');set_error_handler(function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
$root=dirname(__DIR__);spl_autoload_register(function($class)use($root){foreach(array('/app/Services/','/app/Core/') as $dir)if(is_file($f=$root.$dir.$class.'.php')){require_once $f;return;}});require $root.'/scripts/migrate.php';
function ac_ok($yes,$name){if(!$yes)throw new RuntimeException('FAIL '.$name);echo 'PASS '.$name.PHP_EOL;}
function ac_contains_secret($value,$marker){if(is_string($value))return strpos($value,$marker)!==false;if(is_array($value))foreach($value as $item)if(ac_contains_secret($item,$marker))return true;return false;}
function ac_reject($fn,$name){try{$fn();}catch(PDOException $e){throw $e;}catch(InvalidArgumentException $e){ac_ok(true,$name);return;}catch(RuntimeException $e){ac_ok(true,$name);return;}throw new RuntimeException('FAIL '.$name);}
if(isset($argv[1])&&$argv[1]==='--upgrade'){
    ac_ok((int)$pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn()===0,'upgrade requires untouched empty database');
    // Reconstruct the exact pre-account schema read before this change. Only these
    // three literal account additions/nullability edits differ; all existing wallet,
    // group and service-center tables remain the pre-change definitions.
    $schema=file_get_contents($root.'/database/schema.sql');
    $legacyDefinitions=array(
        'products'=>"CREATE TABLE IF NOT EXISTS products (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(160) NOT NULL,description TEXT NOT NULL,traffic VARCHAR(100) NOT NULL,region VARCHAR(100) NOT NULL,device_limit INT UNSIGNED NOT NULL DEFAULT 1,duration_days INT UNSIGNED NOT NULL,price_cents BIGINT UNSIGNED NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'active',sort_order INT NOT NULL DEFAULT 0,created_by BIGINT UNSIGNED NULL,updated_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,KEY idx_product_status_sort(status,sort_order)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'product_plans'=>"CREATE TABLE IF NOT EXISTS product_plans (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,product_id BIGINT UNSIGNED NOT NULL,name VARCHAR(100) NOT NULL,duration_days INT UNSIGNED NOT NULL,price_cents BIGINT UNSIGNED NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'active',sort_order INT NOT NULL DEFAULT 0,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,UNIQUE KEY uk_product_plan_name(product_id,name),KEY idx_product_plan_status(product_id,status,sort_order)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'orders'=>"CREATE TABLE IF NOT EXISTS orders (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_no VARCHAR(40) NOT NULL UNIQUE,user_id BIGINT UNSIGNED NOT NULL,product_id BIGINT UNSIGNED NULL,product_plan_id BIGINT UNSIGNED NULL,product_name_snapshot VARCHAR(160) NOT NULL,plan_name_snapshot VARCHAR(100) NOT NULL DEFAULT '',product_description_snapshot TEXT NOT NULL,traffic_snapshot VARCHAR(100) NOT NULL,region_snapshot VARCHAR(100) NOT NULL,device_limit_snapshot INT UNSIGNED NOT NULL,duration_days_snapshot INT UNSIGNED NOT NULL,unit_price_cents_snapshot BIGINT UNSIGNED NOT NULL,original_amount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,discount_amount_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,promotion_code VARCHAR(100) NOT NULL DEFAULT '',total_amount_cents BIGINT UNSIGNED NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'pending_payment',customer_note VARCHAR(500) NOT NULL DEFAULT '',payment_started_at DATETIME NULL,paid_payment_record_id BIGINT UNSIGNED NULL,refund_previous_status VARCHAR(30) NULL,cancelled_at DATETIME NULL,cancelled_by_type VARCHAR(20) NULL,cancelled_by_id BIGINT UNSIGNED NULL,cancel_reason VARCHAR(500) NOT NULL DEFAULT '',refund_requested_at DATETIME NULL,refund_processed_at DATETIME NULL,refund_reason VARCHAR(500) NOT NULL DEFAULT '',temporary_subscription_url VARCHAR(500) NOT NULL DEFAULT '',temporary_subscription_status VARCHAR(20) NOT NULL DEFAULT 'none',temporary_subscription_issued_at DATETIME NULL,temporary_subscription_revoked_at DATETIME NULL,created_at DATETIME NOT NULL,paid_at DATETIME NULL,delivered_at DATETIME NULL,updated_at DATETIME NOT NULL,KEY idx_order_user(user_id,created_at),KEY idx_order_status(status,created_at),KEY idx_order_plan(product_plan_id),KEY idx_order_promotion_code(promotion_code),UNIQUE KEY uk_order_paid_payment(paid_payment_record_id),KEY idx_order_paid_at(paid_at),KEY idx_order_refund_time(status,refund_processed_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    foreach($legacyDefinitions as $table=>$ddl)$schema=preg_replace('/CREATE TABLE IF NOT EXISTS '.$table.' \\([^;]+;/',$ddl.';',$schema,1);
    foreach(array_filter(array_map('trim',preg_split('/;\s*(?:\r?\n|$)/',$schema))) as $sql)$pdo->exec($sql);
    $pdo->exec('INSERT INTO products(name,description,traffic,region,duration_days,price_cents,created_at,updated_at) VALUES("legacy","legacy","10G","legacy",30,1234,NOW(),NOW())');$legacyProduct=$pdo->lastInsertId();
    $pdo->exec('INSERT INTO orders(order_no,user_id,product_id,product_name_snapshot,product_description_snapshot,traffic_snapshot,region_snapshot,device_limit_snapshot,duration_days_snapshot,unit_price_cents_snapshot,total_amount_cents,created_at,updated_at) VALUES("LEGACY_CORE",1,'.$legacyProduct.',"legacy","legacy","10G","legacy",1,30,1234,1234,NOW(),NOW())');$legacyOrder=$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO delivery_records(order_id,version_no,action,content_ciphertext,content_nonce,content_tag,created_by,created_at) VALUES(?,1,"created",?,?,?,1,NOW())')->execute(array($legacyOrder,'unchanged-random-'.bin2hex(random_bytes(16)),random_bytes(12),random_bytes(16)));
    $before=$pdo->query('SELECT * FROM delivery_records')->fetchAll();
    ac_ok((int)$pdo->query('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME IN ("product_type","product_type_snapshot")')->fetchColumn()===0,'pre-upgrade has no product type');
    ac_ok((int)$pdo->query('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="products" AND COLUMN_NAME IN ("traffic","region","device_limit","duration_days") AND IS_NULLABLE="NO"')->fetchColumn()===4,'legacy VPN columns NOT NULL');
    // Real migration throws immediately after executing its first account ALTER.
    class AccountInterruptedPDO extends PDO {public function exec($sql){$result=parent::exec($sql);if(strpos($sql,'ALTER TABLE products ADD product_type ')===0)throw new RuntimeException('intentional account DDL interruption');return $result;}}
    $interrupted=new AccountInterruptedPDO($dsn,getenv('CHAOXI_TEST_USER'),getenv('CHAOXI_TEST_PASSWORD'),array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));
    try{run_migrations($interrupted,$root);throw new LogicException('Expected interruption');}catch(RuntimeException $e){if($e->getMessage()!=='intentional account DDL interruption')throw $e;ac_ok(true,'real migration interrupted after first account DDL');}
    run_migrations($pdo,$root);run_migrations($pdo,$root);
    ac_ok($before===$pdo->query('SELECT * FROM delivery_records')->fetchAll(),'upgrade preserves delivery ciphertext and versions');
    $o=$pdo->query('SELECT * FROM orders WHERE order_no="LEGACY_CORE"')->fetch();ac_ok($o['product_type_snapshot']==='vpn'&&(int)$o['total_amount_cents']===1234&&$o['traffic_snapshot']==='10G','legacy VPN type and money preserved');
    ac_ok((int)$o['product_plan_id']>0&&$o['plan_name_snapshot']==='默认方案','VPN only legacy plan reconstruction');echo "UPGRADE COMPLETE\n";exit;
}
ac_ok((int)$pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn()===0,'fresh run requires empty database');
run_migrations($pdo,$root);run_migrations($pdo,$root);ac_ok(true,'fresh and repeated migration');
$tag=bin2hex(random_bytes(5));$pdo->prepare('INSERT INTO users(email,password_hash,balance_cents,created_at,updated_at) VALUES(?,"test",10000,NOW(),NOW())')->execute(array($tag.'@example.test'));$uid=$pdo->lastInsertId();
$pdo->prepare('INSERT INTO admin_users(email,password_hash,created_at,updated_at) VALUES(?,"test",NOW(),NOW())')->execute(array('admin'.$tag.'@example.test'));$aid=$pdo->lastInsertId();
$pdo->exec('INSERT INTO products(name,description,product_type,account_platform,account_specification,account_after_sales,price_cents,created_at,updated_at) VALUES("account fixture","public","account","telegram","public spec","public aftercare",1000,NOW(),NOW())');$pid=$pdo->lastInsertId();
$pdo->prepare('INSERT INTO product_plans(product_id,name,price_cents,created_at,updated_at) VALUES(?,"account spec",1000,NOW(),NOW())')->execute(array($pid));$plan=$pdo->lastInsertId();
$pdo->prepare('UPDATE products SET device_limit=NULL WHERE id=?')->execute(array($pid));
$svc=new OrderService($pdo);
foreach(array(true,1.0,array(),0,'01') as $bad)ac_reject(function()use($svc,$uid,$pid,$plan,$bad){$svc->create($uid,$pid,$plan,'','',$bad);},'invalid quantity type rejected');
ac_reject(function()use($svc,$uid,$pid,$plan){$svc->create($uid,$pid,$plan,array());},'array note rejected');
ac_reject(function()use($svc,$uid,$pid,$plan){$svc->create($uid,$pid,$plan,'',array());},'array promotion rejected');
foreach(array('COUPON','group:1') as $code)ac_reject(function()use($svc,$uid,$pid,$plan,$code){$svc->create($uid,$pid,$plan,'',$code);},'account marketing rejected '.$code);
ac_reject(function()use($svc,$uid,$pid,$plan){$svc->create($uid,$pid,$plan,'','',2);},'quantity tampering rejected');
ac_reject(function()use($svc,$uid,$pid){$svc->create($uid,$pid,999999,'');},'plan ownership enforced');
$pdo->exec('INSERT INTO system_settings(setting_key,setting_value,updated_at) VALUES("temporary_subscription_url","https://example.test/temporary",NOW())');
$config=array('security'=>array('encryption_key'=>bin2hex(random_bytes(32))),'app'=>array('timezone'=>'UTC'));
foreach(array('manual','balance','online','zero') as $channel){
    $pdo->prepare('UPDATE product_plans SET price_cents=? WHERE id=?')->execute(array($channel==='zero'?0:1000,$plan));$no=$svc->create($uid,$pid,$plan,'');$o=$svc->getForUser($no,$uid);
    if($channel==='manual'){$svc->confirmPayment($no,$aid);$svc->confirmPayment($no,$aid);}
    elseif($channel==='balance'){$svc->payWithBalance($no,$uid);$svc->payWithBalance($no,$uid);}
    elseif($channel==='online'){$pdo->prepare('INSERT INTO payment_records(order_id,method,gateway_type,amount_cents,status,created_at,updated_at) VALUES(?,"epay_alipay","alipay",1000,"pending",NOW(),NOW())')->execute(array($o['id']));$payment=$pdo->lastInsertId();$svc->confirmPaymentSystem($no,'alipay','fixture-'.$tag,'{}',$payment);$svc->confirmPaymentSystem($no,'alipay','fixture-'.$tag,'{}',$payment);}
    $o=$svc->getForUser($no,$uid);ac_ok($o['status']==='pending_delivery'&&$o['temporary_subscription_url']===''&&$o['temporary_subscription_status']==='none'&&$o['temporary_subscription_issued_at']===null,$channel.' never issues VPN subscription');
    ac_ok($o['product_type_snapshot']==='account'&&$o['traffic_snapshot']===null&&$o['region_snapshot']===null&&$o['device_limit_snapshot']===null&&$o['duration_days_snapshot']===null&&$o['account_specification_snapshot']==='public spec','account public snapshots and NULL VPN fields');
    if($channel==='manual'){
        ac_reject(function()use($svc,$no,$aid,$config){$svc->deliver($no,$aid,array('account'=>''),$config);},'empty account delivery rejected');
        $marker='ACCOUNT_SECRET_'.bin2hex(random_bytes(12));$secret=$marker.'<script>alert(1)</script>';
        ac_ok(ac_contains_secret(array(array('polluted'=>$secret)),$marker)&&ac_contains_secret(array(array('polluted'=>$secret)),$secret),'raw scanner detects intentionally polluted plaintext including closing slash');
        ac_ok(!ac_contains_secret(array(array('clean'=>'public text')),$marker),'raw scanner rejects clean control');
        $svc->deliver($no,$aid,array('account'=>$secret),$config);$payload=$svc->getDeliveryForAdmin($no,$config);ac_ok($payload['account']===$secret&&$payload['opened_at']===null&&$payload['expires_at']===null,'encrypted dateless account delivery');
        ac_reject(function()use($svc,$no,$aid,$config){$svc->updateDelivery($no,$aid,array('account'=>'valid','expires_at'=>'2026-02-30T12:00'),$config);},'invalid optional date rejected');
        $snapshot=function()use($pdo){$out=array();foreach(array('orders','delivery_records','notifications','admin_logs','order_events','notification_outbox') as $table)$out[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();return $out;};$beforeInvalid=$snapshot();
        foreach(array(array('account'=>array()),array('account'=>'valid','opened_at'=>array())) as $bad)ac_reject(function()use($svc,$no,$aid,$config,$bad){$svc->updateDelivery($no,$aid,$bad,$config);},'array delivery field rejected');
        ac_ok($beforeInvalid===$snapshot(),'invalid delivery preserves state versions notifications audit');
        $svc->updateDelivery($no,$aid,array('account'=>$secret.' revised'),$config);
        $s=$pdo->prepare('SELECT * FROM delivery_records WHERE order_id=? ORDER BY version_no');$s->execute(array($o['id']));$versions=$s->fetchAll();ac_ok(count($versions)===2&&(int)$versions[0]['is_current']===0&&(int)$versions[1]['is_current']===1,'delivery versions retained');
        foreach(array('delivery_records','admin_logs','notifications','notification_outbox','order_events','orders') as $table){$rows=$pdo->query('SELECT * FROM '.$table)->fetchAll();ac_ok(!ac_contains_secret($rows,$marker)&&!ac_contains_secret($rows,$secret),'secret absent from '.$table);}
        ac_ok($svc->getForUser($no,$uid+1)===false,'other user cannot read order');
    }
}
$metrics=(new OperatingMetricsService($pdo))->snapshot(date('Y-m-d').' 00:00:00',date('Y-m-d',strtotime('+1 day')).' 00:00:00');
ac_ok($metrics['totals']['settlement_cents']===3000&&$metrics['totals']['settlement_count']===4&&$metrics['totals']['manual_cents']===1000&&$metrics['totals']['balance_cents']===1000&&$metrics['totals']['online_cents']===1000&&$metrics['totals']['zero_count']===1,'account payments included once in operating sales by actual primary channel');
ac_ok($metrics['todos']['pending_delivery']===3,'account pending delivery included in operating todos');
run_migrations($pdo,$root);ac_ok((int)$pdo->query('SELECT COUNT(*) FROM product_plans WHERE product_id='.$pid)->fetchColumn()===1,'repeat migration does not invent account days plans');
$pdo->prepare('UPDATE products SET product_type="unknown" WHERE id=?')->execute(array($pid));ac_reject(function()use($svc,$uid,$pid,$plan){$svc->create($uid,$pid,$plan,'');},'unknown database type rejected');$pdo->prepare('UPDATE products SET product_type="account" WHERE id=?')->execute(array($pid));
$pdo->exec('INSERT INTO products(name,description,traffic,region,duration_days,price_cents,created_at,updated_at) VALUES("vpn fixture","vpn","10G","test",30,1000,NOW(),NOW())');$vpn=$pdo->lastInsertId();$pdo->prepare('INSERT INTO product_plans(product_id,name,duration_days,price_cents,created_at,updated_at) VALUES(?,"month",30,1000,NOW(),NOW())')->execute(array($vpn));$vpnPlan=$pdo->lastInsertId();$no=$svc->create($uid,$vpn,$vpnPlan,'');$svc->confirmPayment($no,$aid);ac_ok($svc->getForUser($no,$uid)['temporary_subscription_status']==='active','VPN retains temporary subscription');ac_reject(function()use($svc,$no,$aid,$config){$svc->deliver($no,$aid,array('account'=>'VPN'),$config);},'VPN still requires expiration');
$pdo->prepare('UPDATE product_plans SET duration_days=NULL WHERE id=?')->execute(array($vpnPlan));ac_reject(function()use($svc,$uid,$vpn,$vpnPlan){$svc->create($uid,$vpn,$vpnPlan,'');},'VPN plan duration remains required');echo "CORE COMPLETE\n";
