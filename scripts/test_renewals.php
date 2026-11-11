<?php
// 默认仅用 SQLite 内存库；--mysql 模式只接受显式指定的空隔离测试库。
// 不载入站点配置、不连接业务库、不运行迁移、任务进程或发起外网请求。
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
set_error_handler(function($n,$s,$f,$l){throw new ErrorException($s,0,$n,$f,$l);});
date_default_timezone_set('UTC');
$root=dirname(__DIR__);
foreach(array('RenewalService','WalletService','NotificationService','CryptoService') as $class)require_once $root.'/app/Services/'.$class.'.php';
spl_autoload_register(function($class)use($root){foreach(array('/app/Services/','/app/Core/') as $dir){$path=$root.$dir.$class.'.php';if(is_file($path)){require_once $path;return;}}});
class RenewalMemoryPDO extends PDO
{
    public $queries=array(),$failCommit=false;
    public function __construct(){parent::__construct('sqlite::memory:',null,null,array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));}
    public function prepare($query,$options=array()){$this->queries[]=$query;return parent::prepare(str_replace(' FOR UPDATE','',$query),$options);}
    public function commit(){if($this->failCommit){$this->failCommit=false;throw new RuntimeException('injected commit failure');}return parent::commit();}
}
function rn_ok($value,$label){if(!$value)throw new RuntimeException('FAIL '.$label);$GLOBALS['rn_count']++;print 'PASS '.$label.PHP_EOL;}
function rn_reject($fn,$label){try{$fn();}catch(PDOException $e){throw $e;}catch(RuntimeException $e){rn_ok(true,$label);return;}catch(LogicException $e){rn_ok(true,$label);return;}throw new RuntimeException('FAIL expected rejection: '.$label);}
function rn_row($p,$sql,$args=array()){$s=$p->prepare($sql);$s->execute($args);return $s->fetch();}
function rn_count($p,$table){return (int)$p->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();}
function rn_config(){return array('app'=>array('timezone'=>'America/New_York'),'security'=>array('encryption_key'=>'isolated-renewal-test-only-key'));}
function rn_delivery($p,$payload,$version=1,$current=1){
    list($cipher,$nonce,$tag)=(new CryptoService(rn_config()))->encrypt(json_encode($payload));
    $p->prepare('INSERT INTO delivery_records(order_id,version_no,action,content_ciphertext,content_nonce,content_tag,content_preview,is_current,opened_at,expires_at,created_by,created_at) VALUES(1,?,"created",?,?,?,"encrypted",?,?,?,1,?)')->execute(array($version,$cipher,$nonce,$tag,$current,$payload['opened_at'],$payload['expires_at'],$payload['opened_at']));return (int)$p->lastInsertId();
}
function rn_db($expired=false){
    $p=new RenewalMemoryPDO;
    foreach(array(
        'CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT)',
        'CREATE TABLE products(id INTEGER PRIMARY KEY,product_type TEXT,status TEXT)',
        'CREATE TABLE product_plans(id INTEGER PRIMARY KEY,product_id INTEGER,name TEXT,duration_days INTEGER,price_cents INTEGER,status TEXT,public_description TEXT)',
        'CREATE TABLE orders(id INTEGER PRIMARY KEY AUTOINCREMENT,order_no TEXT UNIQUE,user_id INTEGER,product_id INTEGER,product_plan_id INTEGER,order_kind TEXT DEFAULT "purchase",parent_order_id INTEGER,request_key TEXT,product_type_snapshot TEXT,product_name_snapshot TEXT,plan_name_snapshot TEXT,plan_description_snapshot TEXT,product_description_snapshot TEXT,traffic_snapshot TEXT,region_snapshot TEXT,device_limit_snapshot INTEGER,duration_days_snapshot INTEGER,unit_price_cents_snapshot INTEGER,original_amount_cents INTEGER,discount_amount_cents INTEGER,promotion_code TEXT,total_amount_cents INTEGER,status TEXT,customer_note TEXT,created_at TEXT,updated_at TEXT,paid_at TEXT,paid_payment_record_id INTEGER,delivered_at TEXT,UNIQUE(user_id,request_key))',
        'CREATE TABLE order_renewals(order_id INTEGER PRIMARY KEY,source_delivery_id INTEGER,source_delivery_version INTEGER,duration_days INTEGER,price_source TEXT,price_cents INTEGER,completed_delivery_id INTEGER,previous_expires_at TEXT,renewal_starts_at TEXT,new_expires_at TEXT,completed_by INTEGER,completed_at TEXT,created_at TEXT)',
        'CREATE TABLE payment_records(id INTEGER PRIMARY KEY AUTOINCREMENT,order_id INTEGER,method TEXT,gateway_type TEXT,amount_cents INTEGER,status TEXT,created_at TEXT,updated_at TEXT,confirmed_by INTEGER,confirmed_at TEXT,gateway_trade_no TEXT,paid_at TEXT,notify_payload TEXT)',
        'CREATE TABLE delivery_records(id INTEGER PRIMARY KEY AUTOINCREMENT,order_id INTEGER,version_no INTEGER,action TEXT,content_ciphertext TEXT,content_nonce BLOB,content_tag BLOB,content_preview TEXT,is_current INTEGER,opened_at TEXT,expires_at TEXT,created_by INTEGER,created_at TEXT)',
        'CREATE TABLE order_events(id INTEGER PRIMARY KEY AUTOINCREMENT,order_id INTEGER,event_type TEXT,old_status TEXT,new_status TEXT,description TEXT,actor_type TEXT,actor_id INTEGER,created_at TEXT)',
        'CREATE TABLE notifications(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,type TEXT,title TEXT,content TEXT,order_no TEXT,target_type TEXT,target_id INTEGER,dedupe_key TEXT,created_at TEXT,UNIQUE(user_id,dedupe_key))',
        'CREATE TABLE admin_logs(id INTEGER PRIMARY KEY AUTOINCREMENT,admin_id INTEGER,actor_type TEXT,action TEXT,target_type TEXT,target_id INTEGER,details_json TEXT,created_at TEXT)'
    ) as $sql)$p->exec($sql);
    $p->exec('INSERT INTO users VALUES(1,"active"),(2,"active");INSERT INTO products VALUES(1,"vpn","inactive");INSERT INTO product_plans VALUES(10,1,"Current monthly",30,9999,"active","new monthly"),(11,1,"Quarter",90,6600,"active","new quarter"),(12,1,"Disabled",60,100,"inactive","");');
    $p->exec('INSERT INTO orders(id,order_no,user_id,product_id,product_plan_id,product_type_snapshot,product_name_snapshot,plan_name_snapshot,plan_description_snapshot,product_description_snapshot,traffic_snapshot,region_snapshot,device_limit_snapshot,duration_days_snapshot,unit_price_cents_snapshot,original_amount_cents,discount_amount_cents,promotion_code,total_amount_cents,status,created_at,updated_at,delivered_at) VALUES(1,"PARENT",1,1,10,"vpn","Original VPN","Old monthly","old description","original rights","100GB","Original region",3,30,2500,2500,500,"OLD",2000,"delivered","2026-01-01 00:00:00","2026-01-01 00:00:00","2026-01-01 00:00:00")');
    $now=new DateTimeImmutable('now',new DateTimeZone(rn_config()['app']['timezone']));
    rn_delivery($p,array('subscription'=>'https://subscription.example.test/SECRET_TOKEN','account'=>'SECRET_ACCOUNT','instructions'=>'preserve instructions','custom'=>array('traffic'=>100,'flags'=>array('a','b')),'opened_at'=>$now->modify('-60 days')->format('Y-m-d H:i:s'),'expires_at'=>$now->modify($expired?'-1 day':'+5 days')->format('Y-m-d H:i:s'),'duration_preset'=>'1m'));
    return $p;
}
function rn_invalid_delivery($p,$parentId,$case,$config){
    $d=rn_row($p,'SELECT * FROM delivery_records WHERE order_id=? AND is_current=1',array($parentId));$crypto=new CryptoService($config);
    if($case==='damaged_cipher'){$raw=base64_decode($d['content_ciphertext']);$raw[0]=chr(ord($raw[0])^1);$p->prepare('UPDATE delivery_records SET content_ciphertext=? WHERE id=?')->execute(array(base64_encode($raw),$d['id']));return;}
    $payload=json_decode($crypto->decrypt($d['content_ciphertext'],$d['content_nonce'],$d['content_tag']),true);
    if($case==='empty_url')$payload['subscription']='';
    elseif($case==='blank_url')$payload['subscription']='   ';
    elseif($case==='date_mismatch')$payload['expires_at']='2001-01-01 00:00:00';
    else throw new LogicException('未知交付异常测试类型');
    list($cipher,$nonce,$tag)=$crypto->encrypt(json_encode($payload));$p->prepare('UPDATE delivery_records SET content_ciphertext=?,content_nonce=?,content_tag=? WHERE id=?')->execute(array($cipher,$nonce,$tag,$d['id']));
}
function rn_service($p){return new RenewalService($p,rn_config());}
function rn_create($p,$key='original',$request='a'){return rn_service($p)->create('PARENT',1,$key,str_repeat($request,64));}
function rn_paid($p,$no,$actor='user'){
    $s=rn_service($p);$p->beginTransaction();
    try{
        $o=rn_row($p,'SELECT * FROM orders WHERE order_no=?',array($no));$payment=rn_row($p,'SELECT * FROM payment_records WHERE order_id=? ORDER BY id DESC',array($o['id']));
        $s->settlePayment($o,$payment,$actor,$actor==='system'?null:1,array('gateway_trade_no'=>'synthetic-trade','notify_payload'=>'synthetic-payload'));$p->commit();
    }catch(Throwable $e){if($p->inTransaction())$p->rollBack();throw $e;}
}
class RenewalMysqlPDO extends PDO
{
    public $pauseParent=false,$forceSnapshot=false;
    public function __construct($dsn,$user,$password){parent::__construct($dsn,$user,$password,array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false));$this->setAttribute(PDO::ATTR_STATEMENT_CLASS,array('RenewalMysqlStatement',array($this)));}
    public function beginTransaction(){ $result=parent::beginTransaction();if($this->forceSnapshot){$this->query('SELECT COUNT(*) FROM orders')->fetchColumn();print 'SNAPSHOT '.(int)$this->query('SELECT CONNECTION_ID()')->fetchColumn().PHP_EOL;flush();}return $result; }
}
class RenewalMysqlStatement extends PDOStatement
{
    private $connection;
    protected function __construct($connection){$this->connection=$connection;}
    public function execute($params=null){
        $parentSelect=$this->queryString==='SELECT * FROM orders WHERE order_no=? AND user_id=? FOR UPDATE';
        if($this->connection->forceSnapshot&&$parentSelect){print 'PARENT_SELECT_ENTER'.PHP_EOL;flush();}
        $result=parent::execute($params);
        if($this->connection->forceSnapshot&&$parentSelect){print 'PARENT_SELECT_RETURNED'.PHP_EOL;flush();}
        if($this->connection->pauseParent&&$this->queryString==='SELECT * FROM orders WHERE order_no=? AND user_id=? FOR UPDATE'){
            $this->connection->pauseParent=false;print 'PARENT_LOCKED'.PHP_EOL;flush();
            if(trim((string)fgets(STDIN))!=='RELEASE')throw new RuntimeException('并发父锁测试未获得释放指令');
        }
        return $result;
    }
}
function rn_mysql_connection(){
    $dsn=getenv('CHAOXI_TEST_DSN');$names=array();$hosts=array();
    foreach($dsn&&strpos($dsn,'mysql:')===0?explode(';',substr($dsn,6)):array() as $part){if(preg_match('/^\s*dbname\s*=(.*)$/i',$part,$m))$names[]=$m[1];if(preg_match('/^\s*host\s*=(.*)$/i',$part,$m))$hosts[]=$m[1];}
    if(count($names)!==1||!preg_match('/\Achaoxi_renewal_test_[A-Za-z0-9_]+\z/D',$names[0])||count($hosts)!==1||!in_array($hosts[0],array('127.0.0.1','localhost','::1'),true))throw new RuntimeException('必须显式配置本机 chaoxi_renewal_test_* 隔离库的 CHAOXI_TEST_DSN');
    $p=new RenewalMysqlPDO($dsn,getenv('CHAOXI_TEST_USER'),getenv('CHAOXI_TEST_PASSWORD'));
    if($p->query('SELECT DATABASE()')->fetchColumn()!==$names[0])throw new RuntimeException('实际数据库不符');
    $p->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');$p->exec('SET SESSION innodb_lock_wait_timeout=15');return $p;
}
function rn_mysql_config(){ $config=rn_config();$config['app']['timezone']='UTC';$config['subboost']=array('url'=>'https://upstream.example.test','api_secret'=>'isolated-upstream-not-used');return $config; }
function rn_mysql_fixture($p,$price=2500){
    $tag=bin2hex(random_bytes(5));$now=date('Y-m-d H:i:s');
    $p->prepare('INSERT INTO users(email,password_hash,balance_cents,created_at,updated_at) VALUES(?,"isolated",100000,?,?)')->execute(array($tag.'@example.test',$now,$now));$uid=(int)$p->lastInsertId();
    $p->prepare('INSERT INTO products(name,description,product_type,traffic,region,device_limit,duration_days,price_cents,created_at,updated_at) VALUES("测试VPN","原权益","vpn","100GB","原地区",3,30,?,?,?)')->execute(array($price,$now,$now));$pid=(int)$p->lastInsertId();
    $p->prepare('INSERT INTO product_plans(product_id,name,duration_days,price_cents,created_at,updated_at) VALUES(?,"原方案",30,?,?,?)')->execute(array($pid,$price,$now,$now));$plan=(int)$p->lastInsertId();
    $no='FIXTURE-'.$tag;
    $p->prepare('INSERT INTO orders(order_no,user_id,product_id,product_plan_id,product_name_snapshot,plan_name_snapshot,product_description_snapshot,traffic_snapshot,region_snapshot,device_limit_snapshot,duration_days_snapshot,unit_price_cents_snapshot,original_amount_cents,total_amount_cents,status,created_at,updated_at,delivered_at) VALUES(?,?,?,?,"测试VPN","原方案","原权益","100GB","原地区",3,30,?,?,?,"delivered",?,?,?)')->execute(array($no,$uid,$pid,$plan,$price,$price,$price,$now,$now,$now));$id=(int)$p->lastInsertId();
    $payload=array('subscription'=>'https://subscription.example.test/SECRET_'.$tag,'account'=>'SECRET_ACCOUNT','instructions'=>'保留说明','custom'=>array('rights'=>true),'opened_at'=>date('Y-m-d H:i:s',time()-86400*30),'expires_at'=>date('Y-m-d H:i:s',time()+86400*5));
    list($cipher,$nonce,$authTag)=(new CryptoService(rn_mysql_config()))->encrypt(json_encode($payload));
    $p->prepare('INSERT INTO delivery_records(order_id,version_no,action,content_ciphertext,content_nonce,content_tag,content_preview,is_current,opened_at,expires_at,created_by,created_at) VALUES(?,1,"created",?,?,?,"已加密",1,?,?,1,?)')->execute(array($id,$cipher,$nonce,$authTag,$payload['opened_at'],$payload['expires_at'],$now));
    return array('id'=>$id,'no'=>$no,'uid'=>$uid,'pid'=>$pid,'plan'=>$plan,'payload'=>$payload);
}
function rn_mysql_child($mode,$parentNo,$uid,$request){
    $p=rn_mysql_connection();$p->pauseParent=$mode==='lock';$p->forceSnapshot=$mode==='snapshot';
    $no=(new RenewalService($p,rn_mysql_config()))->create($parentNo,$uid,'original',$request);print 'RESULT '.$no.PHP_EOL;
}
function rn_mysql_process($mode,$fixture,$request){
    $pipes=array();$process=proc_open(array(PHP_BINARY,__FILE__,'--mysql-child',$mode,$fixture['no'],(string)$fixture['uid'],$request),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
    if(!is_resource($process))throw new RuntimeException('无法启动并发测试子进程');stream_set_timeout($pipes[1],20);return array($process,$pipes);
}
function rn_mysql_result($process,$pipes){$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);foreach($pipes as $pipe)fclose($pipe);$code=proc_close($process);if($code!==0||!preg_match('/RESULT ([A-Za-z0-9_-]+)/',$out,$m))throw new RuntimeException('并发子进程失败 '.$out.' '.$error);return $m[1];}
function rn_mysql_run($root){
    $GLOBALS['rn_count']=0;$p=rn_mysql_connection();$config=rn_mysql_config();$GLOBALS['config']=$config;
    putenv('SUBBOOST_URL=https://upstream.example.test');putenv('SUBBOOST_API_SECRET=isolated-upstream-not-used');
    rn_ok((int)$p->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn()===0,'真实MySQL集成测试只接受完全空库');
    // 只从当前正式 schema 提取建表语句；不调用任何迁移、站点引导或种子脚本。
    preg_match_all('/CREATE TABLE IF NOT EXISTS [^;]+;/s',file_get_contents($root.'/database/schema.sql'),$matches);
    foreach($matches[0] as $ddl)$p->exec($ddl);
    $p->exec('INSERT INTO admin_users(id,email,password_hash,created_at,updated_at) VALUES(1,"isolated-admin@example.test","isolated",NOW(),NOW())');
    list($cipher,$nonce,$tag)=(new CryptoService($config))->encrypt('isolated-payment-signing-key');
    $p->prepare('INSERT INTO payment_settings(gateway_url,pid,md5_key_ciphertext,md5_key_nonce,md5_key_tag,enabled,alipay_enabled,wechat_enabled,notify_base_url,updated_at) VALUES("https://gateway.example.test","isolated-pid",?,?,?,1,1,1,"https://shop.example.test",NOW())')->execute(array($cipher,$nonce,$tag));
    $p->exec('INSERT INTO system_settings(setting_key,setting_value,updated_at) VALUES("temporary_subscription_url","https://temporary.example.test/SECRET",NOW())');
    $orders=new OrderService($p);$payments=new PaymentService($p,$config);$renewals=new RenewalService($p,$config);
    foreach(array('manual','balance','online','zero') as $channel){
        $f=rn_mysql_fixture($p,$channel==='zero'?0:2500);$before=rn_row($p,'SELECT * FROM orders WHERE id=?',array($f['id']));
        $no=$renewals->create($f['no'],$f['uid'],'original',bin2hex(random_bytes(32)));
        if($channel==='manual'){$orders->confirmPayment($no,1);$orders->confirmPayment($no,1);}
        elseif($channel==='balance'||$channel==='zero'){
            $orders->payWithBalance($no,$f['uid']);
            $orders->payWithBalance($no,$f['uid']);
            if($channel==='zero')rn_ok(true,'零元续费重复付款必须幂等返回，异常直接使测试失败');
        }
        else{
            $form=$payments->createPayment($no,$f['uid'],'alipay');$formAgain=$payments->createPayment($no,$f['uid'],'alipay');rn_ok($form['url']===$formAgain['url'],'PaymentService续费在线支付链接幂等且只生成表单不请求网络');
            $child=$orders->getForUser($no,$f['uid']);$attempt=rn_row($p,'SELECT * FROM payment_records WHERE order_id=? AND gateway_order_no<>""',array($child['id']));
            $params=array('pid'=>'isolated-pid','out_trade_no'=>$attempt['gateway_order_no'],'money'=>'25.00','trade_status'=>'TRADE_SUCCESS','type'=>'alipay','trade_no'=>'isolated-'.$child['id']);$params['sign']=PaymentService::sign($params,'isolated-payment-signing-key');
            $payments->handleNotify($params);$payments->handleNotify($params);
        }
        $d=$renewals->detail($no,$f['uid']);$child=$d['order'];
        rn_ok($child['status']==='pending_delivery'&&$child['temporary_subscription_url']===''&&$child['temporary_subscription_status']==='none','主OrderService '.$channel.' 分支进入人工续费且没有临时订阅');
        rn_ok(rn_row($p,'SELECT COUNT(*) AS n FROM payment_records WHERE order_id=? AND status="confirmed"',array($child['id']))['n']==1,'主OrderService '.$channel.' 重复付款不重复确认');
        rn_ok((int)rn_row($p,'SELECT COUNT(*) AS n FROM delivery_records WHERE order_id=?',array($child['id']))['n']===0,'主OrderService '.$channel.' 子单没有交付记录');
        rn_ok((int)rn_row($p,'SELECT balance_cents FROM users WHERE id=?',array($f['uid']))['balance_cents']===($channel==='balance'?97500:100000),'主OrderService '.$channel.' 只按实际渠道扣款一次');
        $renewals->complete($no,1,1);rn_ok($renewals->detail($no)['order']['status']==='delivered','主付款→人工完成 '.$channel.' 集成流程');
        $after=rn_row($p,'SELECT * FROM orders WHERE id=?',array($f['id']));unset($before['updated_at'],$after['updated_at']);rn_ok($before===$after,'主付款与续费完成不更改父订单历史字段 '.$channel);
        rn_reject(function()use($orders,$f){$orders->requestRefund($f['no'],$f['uid'],'测试整单退款');},'主OrderService拒绝已续费父单整笔退款 '.$channel);
        rn_reject(function()use($orders,$f){$orders->revertToPendingDelivery($f['no'],1,'测试退回');},'主OrderService拒绝已续费父单退回待发货 '.$channel);
    }
    foreach(array('subboost_sync_tasks','subboost_sync_attempts','notification_outbox','order_promotions','referral_commissions') as $table)rn_ok(rn_count($p,$table)===0,'主OrderService实际续费分支零副作用 '.$table);
    // 本地付款失效须在余额扣款或在线付款记录创建之前拦截；真实在线回调仍保留到账。
    $f=rn_mysql_fixture($p);$no=$renewals->create($f['no'],$f['uid'],'original',bin2hex(random_bytes(32)));$form=$payments->createPayment($no,$f['uid'],'alipay');
    $child=$orders->getForUser($no,$f['uid']);$attempt=rn_row($p,'SELECT * FROM payment_records WHERE order_id=? AND gateway_order_no<>""',array($child['id']));$p->prepare('UPDATE orders SET status="refunded" WHERE id=?')->execute(array($f['id']));
    rn_reject(function()use($payments,$no,$f){$payments->createPayment($no,$f['uid'],'alipay');},'主PaymentService原单失效后禁止发起或复用在线付款');
    $params=array('pid'=>'isolated-pid','out_trade_no'=>$attempt['gateway_order_no'],'money'=>'25.00','trade_status'=>'TRADE_SUCCESS','type'=>'alipay','trade_no'=>'isolated-late');$params['sign']=PaymentService::sign($params,'isolated-payment-signing-key');$payments->handleNotify($params);
    rn_ok($renewals->detail($no)['order']['status']==='pending_delivery'&&rn_row($p,'SELECT status FROM payment_records WHERE id=?',array($attempt['id']))['status']==='confirmed','主PaymentService原单失效后真实成功回调不丢收款');
    rn_reject(function()use($renewals,$no){$renewals->complete($no,1,1);},'真实失效到账无法人工完成权益');
    foreach(array('manual','balance') as $channel){$f=rn_mysql_fixture($p);$no=$renewals->create($f['no'],$f['uid'],'original',bin2hex(random_bytes(32)));$p->prepare('UPDATE orders SET status="refunded" WHERE id=?')->execute(array($f['id']));
        rn_reject(function()use($orders,$no,$f,$channel){if($channel==='manual')$orders->confirmPayment($no,1);else $orders->payWithBalance($no,$f['uid']);},'主OrderService父单失效拦截 '.$channel);
        rn_ok((int)rn_row($p,'SELECT balance_cents FROM users WHERE id=?',array($f['uid']))['balance_cents']===100000,'父单失效无余额扣款 '.$channel);
    }
    // 真实退款流程只退尚未完成的续费款，父单原金额和当前交付保持逐字段不变。
    $f=rn_mysql_fixture($p);$no=$renewals->create($f['no'],$f['uid'],'original',bin2hex(random_bytes(32)));$orders->payWithBalance($no,$f['uid']);
    $parentBefore=rn_row($p,'SELECT * FROM orders WHERE id=?',array($f['id']));$deliveryBefore=rn_row($p,'SELECT * FROM delivery_records WHERE order_id=? AND is_current=1',array($f['id']));
    $parentEventBefore=(int)rn_row($p,'SELECT COUNT(*) AS n FROM order_events WHERE order_id=?',array($f['id']))['n'];
    $orders->requestRefund($no,$f['uid'],'隔离续费退款申请');rn_ok($renewals->detail($no)['order']['status']==='refunding','主OrderService续费退款申请状态');
    $orders->processRefund($no,1,false,'隔离驳回');rn_ok($renewals->detail($no)['order']['status']==='pending_delivery','主OrderService续费退款驳回恢复人工处理');
    $orders->requestRefund($no,$f['uid'],'再次申请隔离退款');$orders->processRefund($no,1,true,'隔离退款通过');
    rn_ok($renewals->detail($no)['order']['status']==='refunded'&&(int)rn_row($p,'SELECT balance_cents FROM users WHERE id=?',array($f['uid']))['balance_cents']===100000,'主OrderService续费余额退款恢复原余额');
    rn_ok($parentBefore===rn_row($p,'SELECT * FROM orders WHERE id=?',array($f['id']))&&$deliveryBefore===rn_row($p,'SELECT * FROM delivery_records WHERE order_id=? AND is_current=1',array($f['id'])),'续费退款完整保留父订单及原加密交付记录');
    $child=$orders->getForUser($no,$f['uid']);rn_ok((int)rn_row($p,'SELECT COUNT(*) AS n FROM order_events WHERE order_id=? AND event_type IN ("refund_requested","refund_rejected","refund_processed")',array($child['id']))['n']===4,'真实续费子单退款时间线含申请驳回再次申请及退款完成');
    $parentEventAfter=(int)rn_row($p,'SELECT COUNT(*) AS n FROM order_events WHERE order_id=?',array($f['id']))['n'];
    rn_ok($parentEventAfter-$parentEventBefore===4,'父订单同步记录四条子续费退款时间线');
    $timeline=$p->prepare('SELECT event_type,old_status,new_status,description FROM order_events WHERE order_id=? AND event_type IN ("renewal_refund_requested","renewal_refund_rejected","renewal_refund_processed") ORDER BY id');$timeline->execute(array($f['id']));$events=$timeline->fetchAll();
    rn_ok(array_column($events,'event_type')===array('renewal_refund_requested','renewal_refund_rejected','renewal_refund_requested','renewal_refund_processed'),'父单退款事件按申请→驳回→再次申请→通过排序');
    foreach($events as $event)rn_ok($event['old_status']==='delivered'&&$event['new_status']==='delivered'&&strpos($event['description'],$no)!==false&&strpos($event['description'],'SECRET')===false,'父单退款关联事件保留原状态、标明子单且不含秘密');
    // 必须在收款之前识别无效的真实交付；真实网关到账则仍保留并提示人工核对。
    foreach(array('empty_url','blank_url','damaged_cipher','date_mismatch') as $case){
        $f=rn_mysql_fixture($p);rn_invalid_delivery($p,$f['id'],$case,$config);$orderCount=rn_count($p,'orders');$paymentCount=rn_count($p,'payment_records');
        rn_reject(function()use($renewals,$f){$renewals->quote($f['no'],$f['uid']);},'真实MySQL异常交付禁止报价 '.$case);
        rn_reject(function()use($renewals,$f){$renewals->create($f['no'],$f['uid'],'original',bin2hex(random_bytes(32)));},'真实MySQL异常交付禁止创建续费 '.$case);
        rn_ok(rn_count($p,'orders')===$orderCount&&rn_count($p,'payment_records')===$paymentCount&&!$p->inTransaction(),'异常交付创建回滚且不留下续费锁单 '.$case);
        foreach(array('manual','balance') as $channel){
            $f=rn_mysql_fixture($p);$no=$renewals->create($f['no'],$f['uid'],'original',bin2hex(random_bytes(32)));rn_invalid_delivery($p,$f['id'],$case,$config);
            $child=$orders->getForUser($no,$f['uid']);$recordBefore=rn_row($p,'SELECT * FROM payment_records WHERE order_id=?',array($child['id']));$walletCount=rn_count($p,'wallet_transactions');
            rn_reject(function()use($orders,$no,$f,$channel){if($channel==='manual')$orders->confirmPayment($no,1);else $orders->payWithBalance($no,$f['uid']);},'主OrderService异常交付在付款前拒绝 '.$channel.' '.$case);
            rn_ok($orders->getForUser($no,$f['uid'])===$child&&rn_row($p,'SELECT * FROM payment_records WHERE order_id=?',array($child['id']))===$recordBefore&&(int)rn_row($p,'SELECT balance_cents FROM users WHERE id=?',array($f['uid']))['balance_cents']===100000&&rn_count($p,'wallet_transactions')===$walletCount,'异常交付不改变订单付款记录及钱包 '.$channel.' '.$case);
        }
        $f=rn_mysql_fixture($p);$no=$renewals->create($f['no'],$f['uid'],'original',bin2hex(random_bytes(32)));$payments->createPayment($no,$f['uid'],'alipay');$child=$orders->getForUser($no,$f['uid']);
        $attempt=rn_row($p,'SELECT * FROM payment_records WHERE order_id=? AND gateway_order_no<>""',array($child['id']));rn_invalid_delivery($p,$f['id'],$case,$config);
        rn_reject(function()use($payments,$no,$f){$payments->createPayment($no,$f['uid'],'alipay');},'主PaymentService异常交付禁止继续本地发起付款 '.$case);
        $params=array('pid'=>'isolated-pid','out_trade_no'=>$attempt['gateway_order_no'],'money'=>'25.00','trade_status'=>'TRADE_SUCCESS','type'=>'alipay','trade_no'=>'invalid-delivery-'.$child['id']);$params['sign']=PaymentService::sign($params,'isolated-payment-signing-key');
        $payments->handleNotify($params);$payments->handleNotify($params);
        rn_ok($renewals->detail($no)['order']['status']==='pending_delivery'&&rn_row($p,'SELECT status FROM payment_records WHERE id=?',array($attempt['id']))['status']==='confirmed','主PaymentService异常交付真实到账仍确认并幂等保留 '.$case);
        $notice=rn_row($p,'SELECT content FROM notifications WHERE target_id=? AND type="renewal_paid"',array($child['id']));rn_ok($notice&&strpos($notice['content'],'禁止完成续费')!==false&&strpos($notice['content'],'SECRET')===false,'真实异常到账写不含秘密的人工核对提示 '.$case);
        rn_reject(function()use($renewals,$no){$renewals->complete($no,1,1);},'异常交付到账后仍不能完成权益 '.$case);
    }
    // 两个真实进程都调用 create：第二进程先建立旧快照，再阻塞于第一进程持有的父锁。
    $f=rn_mysql_fixture($p);list($a,$ap)=rn_mysql_process('lock',$f,bin2hex(random_bytes(32)));$b=null;$bp=null;
    try{
        rn_ok(trim((string)fgets($ap[1]))==='PARENT_LOCKED','并发进程A实际持有父单行锁');
        list($b,$bp)=rn_mysql_process('snapshot',$f,bin2hex(random_bytes(32)));$line=trim((string)fgets($bp[1]));rn_ok((bool)preg_match('/^SNAPSHOT ([0-9]+)$/',$line,$match),'并发进程B事务先建立RR旧快照');$thread=(int)$match[1];
        rn_ok(trim((string)fgets($bp[1]))==='PARENT_SELECT_ENTER','进程B在旧快照建立后发出父单FOR UPDATE执行前信号');
        // 无 PROCESS 权限也可查看同账号连接。只证明父单锁查询正在服务器执行，
        // 不将 PROCESSLIST 的通用 STATE 解释成 InnoDB 锁等待图或具体阻塞关系。
        $executing=false;$deadline=microtime(true)+8;
        do{$process=rn_row($p,'SELECT ID,COMMAND,STATE,INFO FROM information_schema.PROCESSLIST WHERE ID=?',array($thread));if($process&&$process['COMMAND']!=='Sleep'&&preg_match('/\\ASELECT \\* FROM orders WHERE order_no=.* AND user_id=.* FOR UPDATE\\z/s',(string)$process['INFO'])){$executing=true;break;}}while(microtime(true)<$deadline);
        rn_ok($executing,'同账号PROCESSLIST观测B父单FOR UPDATE仍在执行；不声称取得InnoDB锁等待图证据');
        fwrite($ap[0],"RELEASE\n");fflush($ap[0]);
        $an=rn_mysql_result($a,$ap);$a=null;$bn=rn_mysql_result($b,$bp);$b=null;
        rn_ok($an===$bn&&(int)rn_row($p,'SELECT COUNT(*) AS n FROM orders WHERE parent_order_id=?',array($f['id']))['n']===1,'父锁释放后active FOR UPDATE越过旧快照返回同一子单，无静默双单');
    }finally{if(is_resource($a)){fwrite($ap[0],"RELEASE\n");foreach($ap as $pipe)if(is_resource($pipe))fclose($pipe);proc_terminate($a);proc_close($a);}if(is_resource($b)){foreach($bp as $pipe)if(is_resource($pipe))fclose($pipe);proc_terminate($b);proc_close($b);}}
    rn_ok(rn_count($p,'subboost_sync_tasks')===0&&rn_count($p,'subboost_sync_attempts')===0,'所有主调用与退款并发完成后仍为零SubBoost任务');
    print 'MySQL集成测试通过：'.$GLOBALS['rn_count'].' 项；隔离库保留结果供核查，不自动删除。'.PHP_EOL;
}
if(isset($argv[1])&&$argv[1]==='--mysql-child'){rn_mysql_child($argv[2],$argv[3],$argv[4],$argv[5]);exit;}
if(isset($argv[1])&&$argv[1]==='--mysql'){rn_mysql_run($root);exit;}
$GLOBALS['rn_count']=0;
rn_ok(!RenewalService::isRenewal(array('status'=>'delivered')),'missing kind is purchase');
rn_ok(RenewalService::statusLabel(array('order_kind'=>'renewal','status'=>'pending_delivery'))==='续费处理中','renewal status labels');
$p=rn_db();$s=rn_service($p);$parent=rn_row($p,'SELECT * FROM orders WHERE id=1');$q=$s->quote('PARENT',1);
rn_ok(count($q['options'])===2&&$q['options'][0]['price_cents']===2500&&$q['options'][1]['price_cents']===6600,'snapshot original price, deduplicated original duration and active current plan');
rn_ok(array_keys($q['delivery'])===array('id','version_no','opened_at','expires_at')&&strpos(json_encode($q),'SECRET')===false,'quote exposes safe metadata only');
rn_reject(function()use($s){$s->quote('PARENT',2);},'foreign parent denied');
rn_reject(function()use($s){$s->create('PARENT',1,'original',array());},'invalid request type denied');
rn_reject(function()use($s){$s->create('PARENT',1,'plan:12',str_repeat('b',64));},'inactive plan denied');
rn_reject(function()use($s){$s->create('PARENT',1,'plan:10',str_repeat('b',64));},'same duration cannot override snapshot pricing');
$no=rn_create($p);$detail=$s->detail($no,1);$o=$detail['order'];
rn_ok(preg_match('/\APARENT-[A-Z0-9]{5}\z/D',$no)===1,'续费单号保留原订单号并追加五位随机大写字母或数字');
rn_ok($o['order_kind']==='renewal'&&(int)$o['parent_order_id']===1&&(int)$o['total_amount_cents']===2500&&(int)$o['discount_amount_cents']===0&&$o['promotion_code']==='','child is standalone undiscounted renewal');
rn_ok($o['traffic_snapshot']===$parent['traffic_snapshot']&&$o['region_snapshot']===$parent['region_snapshot']&&$o['plan_description_snapshot']===$parent['plan_description_snapshot'],'original rights snapshots preserved');
rn_ok(rn_create($p)===$no&&rn_create($p,'plan:11','b')===$no&&rn_count($p,'orders')===2,'same request and different active request return one existing child');
rn_reject(function()use($p){rn_create($p,'plan:11');},'request key cannot change selected option');
rn_ok($s->detail($no,2)===null&&$s->detail('PARENT',1)===null,'detail ownership and renewal kind checks');
rn_reject(function()use($s,$parent){$s->assertParentMutable($parent);},'active renewal blocks parent mutation');
rn_reject(function()use($s,$o){$s->assertPayable($o);},'payability requires caller transaction');
$p->exec('INSERT INTO payment_records(order_id,method,gateway_type,amount_cents,status) VALUES(2,"manual","",2500,"pending")');
rn_paid($p,$no);$o=$s->detail($no,1)['order'];
rn_ok($o['status']==='pending_delivery'&&rn_row($p,'SELECT status FROM payment_records WHERE id=1')['status']==='superseded'&&rn_row($p,'SELECT status FROM payment_records WHERE id=2')['status']==='confirmed','settlement confirms main payment, supersedes other pending and queues manual work');
rn_ok(rn_count($p,'delivery_records')===1,'payment never creates delivery or temporary subscription');
rn_reject(function()use($s,$o){$s->assertCancellable($o);},'paid renewal cannot cancel');
$before=rn_row($p,'SELECT * FROM delivery_records WHERE is_current=1');
rn_reject(function()use($s,$no){$s->complete($no,1,2);},'stale expected version rejected');
rn_ok(rn_count($p,'delivery_records')===1&&!$p->inTransaction(),'failed complete rolls back');
$p->failCommit=true;
rn_reject(function()use($s,$no){$s->complete($no,1,1);},'injected commit failure surfaces');
rn_ok(rn_count($p,'delivery_records')===1&&rn_row($p,'SELECT status FROM orders WHERE id=2')['status']==='pending_delivery'&&rn_count($p,'admin_logs')===0,'commit failure rolls back delivery, status and audit');
$s->complete($no,1,1);$d=$s->detail($no,1);$after=rn_row($p,'SELECT * FROM delivery_records WHERE is_current=1');$crypto=new CryptoService(rn_config());
$payload=json_decode($crypto->decrypt($before['content_ciphertext'],$before['content_nonce'],$before['content_tag']),true);$renewed=json_decode($crypto->decrypt($after['content_ciphertext'],$after['content_nonce'],$after['content_tag']),true);$oldExpiry=$payload['expires_at'];unset($payload['expires_at'],$renewed['expires_at']);
rn_ok($payload===$renewed,'encrypted payload preserves all fields except expiry');
$tz=new DateTimeZone(rn_config()['app']['timezone']);
rn_ok((new DateTimeImmutable($after['expires_at'],$tz))->getTimestamp()-(new DateTimeImmutable($oldExpiry,$tz))->getTimestamp()===30*86400,'future expiry extended by exact seconds');
rn_ok($d['order']['status']==='delivered'&&$d['renewal']['completed_delivery_id']==$after['id']&&$after['order_id']==1&&$after['action']==='renewed','completion records metadata and delivery belongs only to parent');
rn_ok(rn_row($p,'SELECT * FROM orders WHERE id=1')['total_amount_cents']===$parent['total_amount_cents']&&rn_row($p,'SELECT * FROM orders WHERE id=1')['unit_price_cents_snapshot']===$parent['unit_price_cents_snapshot']&&rn_row($p,'SELECT status FROM orders WHERE id=1')['status']==='delivered','parent status and historical money untouched');
$s->complete($no,1,1);rn_ok(rn_count($p,'delivery_records')===2&&rn_count($p,'admin_logs')===1,'repeat completion idempotent even old expected version');
rn_reject(function()use($s,$d){$s->assertRefundable($d['order']);},'completed renewal rejects automatic refund');
rn_reject(function()use($s,$parent){$s->assertParentMutable($parent,'refund');},'completed renewal blocks whole-parent refund');
rn_reject(function()use($s,$parent){$s->assertParentMutable($parent,'revert');},'completed renewal blocks parent reverting to pending delivery');
foreach(array('change','deliver','update','revert','cancel','refund') as $action){$s->assertParentMutable($d['order'],$action);rn_ok(true,'renewal itself is no-op for parent guard '.$action);}
$s->assertParentMutable($parent);rn_ok(true,'completed renewal allows ordinary parent updates');
$logs=json_encode($p->query('SELECT * FROM order_events')->fetchAll()).json_encode($p->query('SELECT * FROM notifications')->fetchAll()).json_encode($p->query('SELECT * FROM admin_logs')->fetchAll());rn_ok(strpos($logs,'SECRET')===false,'events notices and audit contain no subscription credentials');
foreach($p->queries as $sql)if(preg_match('/(?:FROM|INTO|UPDATE|JOIN)\s+[^\s(]*(?:outbox|subboost|coupon|promotion|referral|email)/i',$sql))throw new RuntimeException('Unexpected side effect SQL: '.$sql);
rn_ok(true,'no outbox upstream promotion referral or email queries');
$p=rn_db(true);$s=rn_service($p);$no=rn_create($p,'plan:11');rn_paid($p,$no);$s->complete($no,1,1);$d=$s->detail($no);
rn_ok((new DateTimeImmutable($d['renewal']['new_expires_at'],$tz))->getTimestamp()-(new DateTimeImmutable($d['renewal']['completed_at'],$tz))->getTimestamp()===90*86400&&$d['renewal']['renewal_starts_at']===$d['renewal']['completed_at'],'expired subscription starts at confirmation time');
rn_ok($d['order']['plan_name_snapshot']==='Quarter'&&$d['order']['plan_description_snapshot']==='new quarter'&&(int)$d['order']['unit_price_cents_snapshot']===6600,'changed duration copies current selected plan snapshots and price');
$p=rn_db();$s=rn_service($p);$no=rn_create($p);rn_paid($p,$no);$original=rn_row($p,'SELECT * FROM delivery_records WHERE is_current=1');$payload=json_decode((new CryptoService(rn_config()))->decrypt($original['content_ciphertext'],$original['content_nonce'],$original['content_tag']),true);$p->exec('UPDATE delivery_records SET is_current=0');$payload['subscription']='https://subscription.example.test/DIFFERENT_SECRET';rn_delivery($p,$payload,2);
rn_reject(function()use($s,$no){$s->complete($no,1,2);},'different subscription in newer version rejected');
$payload['subscription']='https://subscription.example.test/SECRET_TOKEN';$p->exec('UPDATE delivery_records SET is_current=0');rn_delivery($p,$payload,3);$s->complete($no,1,3);rn_ok($s->detail($no)['order']['status']==='delivered','same subscription in newer version allowed');
$p=rn_db();$s=rn_service($p);$no=rn_create($p);$p->exec('UPDATE orders SET status="refunded" WHERE id=1');
rn_reject(function()use($p,$no){rn_paid($p,$no);},'local payment rejects ineligible parent');
rn_paid($p,$no,'system');rn_ok(rn_row($p,'SELECT status FROM payment_records WHERE order_id=2')['status']==='confirmed'&&$s->detail($no)['order']['status']==='pending_delivery','real callback records funds despite ineligible parent');
rn_reject(function()use($s,$no){$s->complete($no,1,1);},'ineligible parent cannot complete after real receipt');
rn_ok(strpos(rn_row($p,'SELECT content FROM notifications WHERE type="renewal_paid"')['content'],'禁止完成续费')!==false,'ineligible real receipt has manual handling warning');
foreach(array('invalid-date','duplicate-current','payload-date-mismatch') as $case){
    $p=rn_db();$s=rn_service($p);$no=rn_create($p);rn_paid($p,$no);
    if($case==='invalid-date')$p->exec('UPDATE delivery_records SET expires_at="2026-02-30 00:00:00"');
    if($case==='duplicate-current')$p->exec('INSERT INTO delivery_records(order_id,version_no,is_current,opened_at,expires_at) SELECT order_id,2,1,opened_at,expires_at FROM delivery_records WHERE id=1');
    if($case==='payload-date-mismatch')$p->exec('UPDATE delivery_records SET opened_at="2020-01-01 00:00:00"');
    rn_reject(function()use($s,$no){$s->complete($no,1,1);},$case.' rejected');
}
$p=rn_db();$s=rn_service($p);$p->failCommit=true;rn_reject(function()use($p){rn_create($p);},'create commit failure surfaces');rn_ok(rn_count($p,'orders')===1&&rn_count($p,'order_renewals')===0&&rn_count($p,'notifications')===0,'create rollback leaves no child or side effects');
$p->exec('UPDATE products SET product_type="account"');rn_ok(count($s->quote('PARENT',1)['options'])===1,'changed product type only permits original snapshot');
$p->exec('UPDATE delivery_records SET is_current=0');rn_reject(function()use($s){$s->quote('PARENT',1);},'missing current delivery rejected');
foreach(array('empty_url','blank_url','damaged_cipher','date_mismatch') as $case){
    $p=rn_db();$s=rn_service($p);rn_invalid_delivery($p,1,$case,rn_config());
    rn_reject(function()use($s){$s->quote('PARENT',1);},'异常交付报价前拒绝 '.$case);
    rn_reject(function()use($p){rn_create($p);},'异常交付创建前拒绝 '.$case);
    rn_ok(rn_count($p,'orders')===1&&rn_count($p,'order_renewals')===0&&!$p->inTransaction(),'异常交付无新续费及残留事务 '.$case);
    $p=rn_db();$s=rn_service($p);$no=rn_create($p);rn_invalid_delivery($p,1,$case,rn_config());
    foreach(array('admin','user') as $actor)rn_reject(function()use($p,$no,$actor){rn_paid($p,$no,$actor);},'异常交付本地结算前拒绝 '.$actor.' '.$case);
    rn_ok($s->detail($no)['order']['status']==='pending_payment'&&rn_row($p,'SELECT status FROM payment_records WHERE order_id=2')['status']==='pending','异常本地结算不确认付款 '.$case);
    rn_paid($p,$no,'system');rn_ok($s->detail($no)['order']['status']==='pending_delivery'&&rn_row($p,'SELECT status FROM payment_records WHERE order_id=2')['status']==='confirmed','异常交付真实到账仍保留 '.$case);
    rn_reject(function()use($s,$no){$s->complete($no,1,1);},'异常交付真实到账禁止完成 '.$case);
    rn_ok(strpos(rn_row($p,'SELECT content FROM notifications WHERE type="renewal_paid"')['content'],'禁止完成续费')!==false,'异常交付真实到账提示人工核对 '.$case);
}
print 'Renewal isolated tests passed: '.$GLOBALS['rn_count'].PHP_EOL;
