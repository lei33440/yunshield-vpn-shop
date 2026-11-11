<?php
// SQLite :memory: only. No application config, site DB, networking or real SMTP.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
set_error_handler(function($n,$s,$f,$l){throw new ErrorException($s,0,$n,$f,$l);});
date_default_timezone_set('UTC');
$root=dirname(__DIR__);
spl_autoload_register(function($class)use($root){$f=$root.'/app/Services/'.$class.'.php';if(is_file($f))require_once $f;});
class ReminderMemoryPDO extends PDO
{
    public $queries=array(),$beforeOrderLock=null,$failCommit=false,$beforeTemplateRead=null;
    public function __construct(){parent::__construct('sqlite::memory:',null,null,array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));}
    private function sql($q){
        $this->queries[]=$q;
        if(preg_match('/SELECT.*(?:content_ciphertext|content_nonce|content_tag|SELECT \* FROM delivery_records)/i',$q))throw new LogicException('Reminder read secret delivery fields');
        if($this->beforeOrderLock&&strpos($q,'FROM orders WHERE id=? FOR UPDATE')!==false){$f=$this->beforeOrderLock;$this->beforeOrderLock=null;$f($this);}
        if($this->beforeTemplateRead&&strpos($q,'FROM email_templates')!==false){$f=$this->beforeTemplateRead;$this->beforeTemplateRead=null;$f($this);}
        $q=str_replace('DATE_SUB(NOW(),INTERVAL 10 MINUTE)',"datetime('now','-10 minutes')",$q);
        return str_replace(array(' FOR UPDATE','INSERT IGNORE','NOW()',' AS UNSIGNED'),array('','INSERT OR IGNORE',"datetime('now')",' AS INTEGER'),$q);
    }
    public function prepare($q,$options=array()){return parent::prepare($this->sql($q),$options);}
    public function query($q,...$args){return parent::query($this->sql($q),...$args);}
    public function exec($q){return parent::exec($this->sql($q));}
    public function commit(){if($this->failCommit){$this->failCommit=false;throw new RuntimeException('injected commit failure');}return parent::commit();}
}
// Worker campaign collaborator is irrelevant to reminder tests; explicitly stubbed, no campaign work.
class EmailCampaignService {public function __construct($p,$c){}public function enqueueBatch($n){return 0;}public function refreshCompleted(){}}
class ReminderSMTPStub {
    public $enabled=false,$configured=false,$calls=0,$hook=null;
    public function settings(){return array('enabled'=>$this->enabled,'configured'=>$this->configured);}
    public function send($to,$subject,$body,$html){if(substr($to,-13)!=='@example.test')throw new LogicException('invalid fixture recipient');$this->calls++;return true;}
}
require_once $root.'/scripts/customer_email_worker.php';
function sr_ok($value,$label){if(!$value)throw new RuntimeException('FAIL '.$label);$GLOBALS['sr_count']++;print 'PASS '.$label.PHP_EOL;}
function sr_db(){
    $p=new ReminderMemoryPDO;
    foreach(array(
        'CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT,status TEXT)',
        'CREATE TABLE orders(id INTEGER PRIMARY KEY,user_id INTEGER,order_no TEXT,status TEXT,product_type_snapshot TEXT,product_name_snapshot TEXT,plan_name_snapshot TEXT,temporary_subscription_status TEXT,temporary_subscription_url TEXT)',
        'CREATE TABLE delivery_records(id INTEGER PRIMARY KEY,order_id INTEGER,version_no INTEGER,is_current INTEGER,opened_at TEXT,expires_at TEXT,content_ciphertext TEXT)',
        'CREATE TABLE notifications(id INTEGER PRIMARY KEY,user_id INTEGER,type TEXT,title TEXT,content TEXT,order_no TEXT,target_type TEXT,target_id INTEGER,dedupe_key TEXT,created_at TEXT,UNIQUE(user_id,dedupe_key))',
        'CREATE TABLE system_settings(setting_key TEXT PRIMARY KEY,setting_value TEXT,updated_by INTEGER,updated_at TEXT)',
        'CREATE TABLE notification_outbox(id INTEGER PRIMARY KEY,channel TEXT,event_type TEXT,dedupe_key TEXT UNIQUE,payload_json TEXT,status TEXT,retry_count INTEGER,available_at TEXT,created_at TEXT,updated_at TEXT,locked_at TEXT,sent_at TEXT,last_error TEXT)',
        'CREATE TABLE email_templates(template_key TEXT PRIMARY KEY,config_json TEXT,format_version INTEGER,version_no INTEGER)'
    ) as $q)$p->exec($q);
    $p->exec('INSERT INTO users VALUES(1,"recipient@example.test","active"),(2,"other@example.test","active")');return $p;
}
function sr_fixture($p,$id,$seconds=172800,$config=null){
    $tz=new DateTimeZone($config===null?'Asia/Shanghai':$config['app']['timezone']);$now=new DateTimeImmutable('now',$tz);
    $p->prepare('INSERT INTO orders VALUES(?,1,?,"delivered","vpn","Synthetic VPN","Monthly","active","SECRET_TEMP_URL")')->execute(array($id,'REM-'.$id));
    $p->prepare('INSERT INTO delivery_records VALUES(?,?,1,1,?,?,"SECRET_CIPHERTEXT")')->execute(array($id,$id,$now->modify('-30 days')->format('Y-m-d H:i:s'),$now->setTimestamp($now->getTimestamp()+$seconds)->format('Y-m-d H:i:s')));
}
function sr_count($p,$table){return (int)$p->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();}
function sr_latest($p){$r=$p->query('SELECT * FROM notification_outbox ORDER BY id DESC LIMIT 1')->fetch();return array($r,json_decode($r['payload_json'],true));}
$GLOBALS['sr_count']=0;
$config=array('app'=>array('timezone'=>'Asia/Shanghai','base_url'=>'https://example.test','name'=>'Synthetic shop'));
$p=sr_db();$s=new SubscriptionReminderService($p,$config);$c=new CustomerEmailService($p,$config);
$now=new DateTimeImmutable('2026-11-10 12:00:00',new DateTimeZone('Asia/Shanghai'));
foreach(array(-1=>null,0=>null,1=>1,86400=>1,86401=>3,259200=>3,259201=>7,604800=>7,604801=>null) as $seconds=>$expected){
    $expires=$now->setTimestamp($now->getTimestamp()+$seconds)->format('Y-m-d H:i:s');
    sr_ok($s->tier('2026-10-01 12:00:00',$expires,$now)===$expected,'remaining boundary '.$seconds);
}
foreach(array(array('2026-02-30 00:00:00','2026-11-11 12:00:00'),array('2026-11-11 00:00:00','2026-11-12 00:00:00'),array('2026-11-10 12:00:00','2026-11-10 12:00:00'),array('','2026-11-11 00:00:00'),array('2026-10-01 00:00:00','not-a-date')) as $i=>$d)sr_ok($s->tier($d[0],$d[1],$now)===null,'invalid/future metadata '.$i);
sr_ok(date_default_timezone_get()==='UTC','application timezone does not mutate global timezone');
sr_ok(!$c->settings()['customer_subscription_expiring_enabled'],'reminder email defaults off');
sr_fixture($p,1);$r=$s->scan();sr_ok($r===array('notified'=>1,'enqueued'=>0),'site reminder independent of default-off email');
$r=$s->scan();sr_ok($r===array('notified'=>0,'enqueued'=>0)&&sr_count($p,'notifications')===1,'repeat scan deduplication');
$c->saveSettings(array('customer_subscription_expiring_enabled'=>1));$s->scan();sr_ok(sr_count($p,'notification_outbox')===0,'enabling does not backfill already reminded tier');
sr_fixture($p,2,5*86400);$s->scan();list($row,$payload)=sr_latest($p);
sr_ok($payload['reminder_tier']===7&&$c->eligibility($row,$payload)===null,'enabled event binds current delivery');
sr_ok(strpos(json_encode($payload),'SECRET')===false,'allowlisted outbox payload has no secrets');
$msg=$c->message($row,$payload);sr_ok(strpos($msg['body'],$payload['expires_at'])!==false&&strpos($msg['body'],'原订单申请续费')!==false&&strpos($msg['body'],'不会自动扣款')!==false&&strpos($msg['body'],'管理员人工处理')!==false,'fixed expiry and manual renewal safety text');
sr_ok(strpos($msg['html'],'https://example.test/orders/REM-2')!==false&&strpos(json_encode($msg),'SECRET')===false,'existing order link, no secrets, no decryption');
$notice=$p->query('SELECT * FROM notifications WHERE target_id=2')->fetch();sr_ok($notice['target_type']==='order'&&$notice['order_no']==='REM-2'&&strpos($notice['content'],'SECRET')===false,'site notice targets order without secrets');
// Same expiry replacement: queued old delivery invalid, no duplicate reminder for the same tier.
$p->exec('UPDATE delivery_records SET version_no=2 WHERE order_id=2');
sr_ok($c->eligibility($row,$payload)==='customer_subscription_version_expired','old delivery version rejected');
$s->scan();sr_ok(sr_count($p,'notifications')===2&&sr_count($p,'notification_outbox')===1,'same-expiry edit does not notify again');
$p->exec('UPDATE delivery_records SET version_no=1,is_current=0 WHERE order_id=2');sr_ok($c->eligibility($row,$payload)==='customer_subscription_version_expired','noncurrent delivery rejected');$p->exec('UPDATE delivery_records SET is_current=1 WHERE order_id=2');
foreach(array('refunded','cancelled','pending_delivery') as $status){$p->prepare('UPDATE orders SET status=? WHERE id=2')->execute(array($status));sr_ok($c->eligibility($row,$payload)==='customer_subscription_inactive','order '.$status.' invalidates email');}$p->exec('UPDATE orders SET status="delivered" WHERE id=2');
$p->exec('UPDATE orders SET product_type_snapshot="account" WHERE id=2');sr_ok($c->eligibility($row,$payload)==='customer_subscription_inactive','account product never qualifies');$p->exec('UPDATE orders SET product_type_snapshot="vpn",user_id=2 WHERE id=2');sr_ok($c->eligibility($row,$payload)==='customer_order_invalid','ownership changed rejected');$p->exec('UPDATE orders SET user_id=1 WHERE id=2');
$p->exec('UPDATE users SET status="disabled" WHERE id=1');sr_ok($c->eligibility($row,$payload)==='customer_user_inactive','inactive user rejected');$p->exec('UPDATE users SET status="active",email="changed@example.test" WHERE id=1');sr_ok($c->eligibility($row,$payload)==='customer_email_changed','email change rejected');$p->exec('UPDATE users SET email="recipient@example.test" WHERE id=1');
$expires=$payload['expires_at'];$changed=(new DateTimeImmutable('now',new DateTimeZone('Asia/Shanghai')))->modify('+12 hours')->format('Y-m-d H:i:s');$p->prepare('UPDATE delivery_records SET expires_at=? WHERE order_id=2')->execute(array($changed));sr_ok($c->eligibility($row,$payload)==='customer_subscription_version_expired','changed expiry rejected');
$p->prepare('UPDATE delivery_records SET expires_at=? WHERE order_id=2')->execute(array($expires));$bad=$payload;$bad['reminder_tier']=3;sr_ok($c->eligibility($row,$bad)==='customer_subscription_tier_expired','stale tier rejected');
$expired=(new DateTimeImmutable('now',new DateTimeZone('Asia/Shanghai')))->modify('-1 second')->format('Y-m-d H:i:s');$p->prepare('UPDATE delivery_records SET expires_at=? WHERE order_id=2')->execute(array($expired));$bad=$payload;$bad['expires_at']=$expired;sr_ok($c->eligibility($row,$bad)==='customer_subscription_tier_expired','expired task rejected');$p->prepare('UPDATE delivery_records SET expires_at=? WHERE order_id=2')->execute(array($expires));
$c->saveSettings(array('customer_subscription_expiring_enabled'=>0));sr_ok($c->eligibility($row,$payload)==='customer_event_disabled','independent switch disables queued mail');$c->saveSettings(array('customer_subscription_expiring_enabled'=>1));sr_ok($c->eligibility($row,$payload)==='customer_generation_expired','off/on invalidates old generation');sr_ok(!$c->settings()['customer_order_created_enabled'],'reminder switch does not enable other events');
// Scan filters and rollback on a failed atomic commit.
$p2=sr_db();$s2=new SubscriptionReminderService($p2,$config);
for($i=1;$i<=9;$i++)sr_fixture($p2,$i);
$p2->exec('UPDATE orders SET status="refunded" WHERE id=1');$p2->exec('UPDATE orders SET product_type_snapshot="account" WHERE id=2');$p2->exec('UPDATE delivery_records SET is_current=0 WHERE order_id=3');$p2->exec('UPDATE delivery_records SET opened_at="2026-02-30 00:00:00" WHERE order_id=4');$p2->exec('UPDATE delivery_records SET expires_at="2000-01-01 00:00:00" WHERE order_id=5');$p2->exec('UPDATE orders SET user_id=2 WHERE id=6');$p2->exec('UPDATE users SET status="disabled" WHERE id=2');$p2->exec('INSERT INTO delivery_records VALUES(99,7,2,1,"2020-01-01 00:00:00","2099-01-01 00:00:00","SECRET")');$p2->exec('UPDATE delivery_records SET opened_at="2099-01-01 00:00:00" WHERE order_id=8');
$r=$s2->scan();sr_ok($r['notified']===1&&$p2->query('SELECT target_id FROM notifications')->fetchColumn()==9,'scan excludes refund/account/noncurrent/invalid/expired/inactive/ambiguous/future');
sr_fixture($p2,10);$p2->beforeOrderLock=function($p){$p->exec('UPDATE orders SET status="refunded" WHERE id=10');};$s2->scan();sr_ok(sr_count($p2,'notifications')===1,'state change before order lock is rechecked');
(new CustomerEmailService($p2,$config))->saveSettings(array('customer_subscription_expiring_enabled'=>1));sr_fixture($p2,11);$p2->failCommit=true;
try{$s2->scan();throw new LogicException('expected commit failure');}catch(RuntimeException $e){sr_ok($e->getMessage()==='injected commit failure','commit failure surfaced');}
sr_ok(sr_count($p2,'notifications')===1&&sr_count($p2,'notification_outbox')===0,'site notification and outbox roll back together');$s2->scan();sr_ok(sr_count($p2,'notifications')===2&&sr_count($p2,'notification_outbox')===1,'next scan safely retries after rollback');
// Traversal must pass a full page of previously reminded orders.
$p3=sr_db();$s3=new SubscriptionReminderService($p3,$config);for($i=1;$i<=205;$i++)sr_fixture($p3,$i);$s3->scan();sr_fixture($p3,206);$r=$s3->scan();sr_ok($r['notified']===1&&sr_count($p3,'notifications')===206,'keyset traversal reaches beyond first 200 already-reminded orders');
// SMTP paused: scan still runs, and no existing queue fields/retries are touched.
$p4=sr_db();$smtp=new ReminderSMTPStub;sr_fixture($p4,1);$r=customer_email_worker_run($p4,$config,$smtp);sr_ok($r['paused']&&$r['reminded']===1&&$r['claimed']===0&&$smtp->calls===0,'worker SMTP off still creates site reminder');
(new CustomerEmailService($p4,$config))->saveSettings(array('customer_subscription_expiring_enabled'=>1));sr_fixture($p4,2);$r=customer_email_worker_run($p4,$config,$smtp);$snapshot=$p4->query('SELECT * FROM notification_outbox')->fetchAll();customer_email_worker_run($p4,$config,$smtp);sr_ok($r['enqueued']===1&&$snapshot===$p4->query('SELECT * FROM notification_outbox')->fetchAll(),'SMTP off permits independently-enabled reminder enqueue but preserves retries');
$smtp->enabled=true;$smtp->configured=true;$r=customer_email_worker_run($p4,$config,$smtp);sr_ok($r['sent']===1&&$smtp->calls===1,'worker whitelist claims and sends reminder through stub');
sr_fixture($p4,3);$p4->beforeTemplateRead=function($p){$p->exec('UPDATE orders SET status="refunded" WHERE id=3');};$r=customer_email_worker_run($p4,$config,$smtp);sr_ok($r['skipped']===1&&$smtp->calls===1,'refund during rendering blocked by final worker revalidation');
// Release path includes new event and does not consume retries.
$p4->exec('UPDATE notification_outbox SET status="processing",locked_at="2026-01-01 00:00:00" WHERE id=1');customer_email_worker_release($p4,array(array('id'=>1)));sr_ok($p4->query('SELECT status FROM notification_outbox WHERE id=1')->fetchColumn()==='retry','worker release whitelist includes reminder');
$template=new EmailTemplateService($p4,$config);$defaults=$template->defaults('customer_subscription_expiring');$defaults['body']='自定义正文';$preview=$template->preview('customer_subscription_expiring',$defaults);sr_ok(strpos($preview['body'],'2099-01-01 12:00:00')!==false&&strpos($preview['body'],'不会自动扣款')!==false,'custom template cannot remove fixed reminder safety module');
// The old generic worker must neither reclaim nor claim this dedicated event.
$p5=sr_db();$out5=new NotificationOutboxService($p5);
$out5->enqueue('customer_subscription_expiring','exclusive:pending',array(),'email');
$out5->enqueue('customer_subscription_expiring','exclusive:processing',array(),'email');
$p5->exec('UPDATE notification_outbox SET status="processing",locked_at="2000-01-01 00:00:00" WHERE id=2');
$out5->enqueue('legacy_event','legacy:untouched',array(),'wecom');
$before5=$p5->query('SELECT * FROM notification_outbox WHERE id<=2 ORDER BY id')->fetchAll();$claimed5=$out5->claim();
sr_ok(count($claimed5)===1&&$claimed5[0]['event_type']==='legacy_event'&&$before5===$p5->query('SELECT * FROM notification_outbox WHERE id<=2 ORDER BY id')->fetchAll(),'generic worker excludes reminder from claim and stale recovery, preserves legacy scope');
sr_ok($out5->claim(20,true)===array(),'password-reset-only worker cannot claim reminder');
$claimed5=$out5->claimCustomerEmails();sr_ok(count($claimed5)===2,'dedicated worker recovers stale reminder and claims both');
// Changed expiry is a new subscription boundary, but editing the version with unchanged expiry is not.
$p6=sr_db();sr_fixture($p6,1,5*86400);$s6=new SubscriptionReminderService($p6,$config);$s6->scan();
$nextExpiry=(new DateTimeImmutable('now',new DateTimeZone('Asia/Shanghai')))->modify('+6 days')->format('Y-m-d H:i:s');
$p6->prepare('UPDATE delivery_records SET expires_at=? WHERE order_id=1')->execute(array($nextExpiry));$s6->scan();
sr_ok(sr_count($p6,'notifications')===2,'new expiry produces a distinct dedupe boundary');
$p6->exec('UPDATE delivery_records SET is_current=0 WHERE order_id=1');
$p6->prepare('INSERT INTO delivery_records VALUES(2,1,2,1,"2020-01-01 00:00:00",?,"SECRET_NEW_VERSION")')->execute(array($nextExpiry));$s6->scan();
sr_ok(sr_count($p6,'notifications')===2,'replacement delivery ID with same expiry does not repeat reminder');
print 'COMPLETE '.$GLOBALS['sr_count']." isolated reminder assertions\n";
