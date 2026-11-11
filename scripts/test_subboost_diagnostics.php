<?php
/**
 * PHP 7.4, standalone: php scripts/test_subboost_diagnostics.php
 * No bootstrap/config/business database/network/mail/worker entry point is loaded.
 * Only sqlite::memory: is opened. MySQL locking/time syntax is translated below;
 * this tests diagnostics/control flow, not MySQL locking/concurrency semantics.
 */
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "FAIL: pdo_sqlite is required\n");
    exit(1);
}

// Load the actual client under a test-only name so its validators/diagnostics are
// exercised, while every client constructed by runOnce is a no-network fake.
$clientSource = file_get_contents(__DIR__.'/../app/Services/SubBoostClient.php');
if (substr_count($clientSource, 'class SubBoostClient') !== 1) {
    throw new RuntimeException('Client declaration changed; review test isolation');
}
eval(substr(str_replace('class SubBoostClient', 'class DiagnosticsOriginalClient', $clientSource), 5));
// A second, namespaced copy runs the real private request() with cURL doubles.
// Namespace resolution guarantees these functions, never native cURL, are used.
foreach (array('CURLOPT_CONNECT_TO','CURLOPT_CUSTOMREQUEST','CURLOPT_HTTPHEADER','CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT','CURLOPT_FOLLOWLOCATION','CURLOPT_MAXREDIRS','CURLOPT_PROTOCOLS','CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLOPT_WRITEFUNCTION','CURLOPT_POSTFIELDS','CURLINFO_HTTP_CODE','CURLPROTO_HTTP','CURLPROTO_HTTPS') as $i=>$name) {
    if (!defined($name)) define($name, 10000+$i);
}
eval(<<<'PHP'
namespace DiagnosticsOfflineTransport;
function curl_init($url) { return (object) array('options'=>array()); }
function curl_setopt($ch,$key,$value) { $ch->options[$key]=$value; return true; }
function curl_setopt_array($ch,$options) { $ch->options=$options+$ch->options; return true; }
function curl_exec($ch) {
    $scenario=$GLOBALS['diagnostics_transport'];
    if ($scenario['errno']) return false;
    $body=$scenario['body'];
    $write=$ch->options[CURLOPT_WRITEFUNCTION];
    return $write($ch,$body)===strlen($body);
}
function curl_getinfo($ch,$key) { return $GLOBALS['diagnostics_transport']['http']; }
function curl_errno($ch) { return $GLOBALS['diagnostics_transport']['errno']; }
function curl_close($ch) {}
PHP
);
eval('namespace DiagnosticsOfflineTransport; use RuntimeException; use DateTimeImmutable; use Exception;'.substr($clientSource,5));
require __DIR__.'/../app/Services/SubBoostTaskService.php';
require __DIR__.'/../app/Services/SubBoostSyncService.php';

class SubBoostClient extends DiagnosticsOriginalClient
{
    public static $mode = '';
    public static $calls = array();
    public static $afterRequest;
    private $meta = array();
    public static function settings($config = array()) { return $config['subboost']; }
    public static function enabled($config = array()) { return !empty($config['subboost']['api_secret']); }
    public function __construct($config = array()) {
        if (self::$mode === 'configuration') throw new RuntimeException('invalid_configuration');
        parent::__construct($config);
    }
    public function create($request) { return $this->answer('create', $request['orderNo']); }
    public function status($no) { return $this->answer('status', $no); }
    private function answer($method, $no) {
        self::$calls[] = $method;
        $this->meta = array('http_status'=>200, 'transport_errno'=>0);
        if (self::$mode === 'transport') {
            $this->meta = array('http_status'=>0, 'transport_errno'=>28);
            throw new RuntimeException('http_transport_failed');
        }
        if (self::$mode === 'http') {
            $this->meta['http_status'] = 503;
            throw new RuntimeException('http_503');
        }
        if (self::$afterRequest) call_user_func(self::$afterRequest);
        if (self::$mode === 'response') return array('raw'=>'SECRET_RESPONSE');
        $data = array('orderNo'=>$no, 'subscriptionId'=>'sub-test', 'status'=>'ready',
            'subscriptionUrl'=>'https://diagnostics.invalid/api/subscriptions/SECRET_TOKEN/config.yaml',
            'openedAt'=>'2026-01-01T00:00:00Z', 'expiresAt'=>'2026-01-31T00:00:00Z');
        if (self::$mode === 'waiting') {
            $data['status'] = 'pending_configuration';
            $data['subscriptionUrl'] = $data['openedAt'] = $data['expiresAt'] = null;
        }
        if (self::$mode === 'identity') $data['subscriptionId'] = 'different';
        if (self::$mode === 'dates') $data['expiresAt'] = '2026-02-01T00:00:00Z';
        if (self::$mode === 'url') $data['subscriptionUrl'] = 'https://evil.invalid/SECRET_TOKEN';
        return $data;
    }
    public function diagnostics() {
        if (self::$mode === 'diagnostics_throw') throw new RuntimeException('SECRET_DIAGNOSTICS');
        return $this->meta;
    }
}

// A local delivery double, deliberately never loading OrderService/mail services.
class OrderService
{
    public static $failure = false;
    public static $payload;
    private $pdo;
    public function __construct($pdo) { $this->pdo = $pdo; }
    public function deliverSystem($no, $payload, $config) {
        self::$payload = $payload;
        if (self::$failure) throw new RuntimeException('SECRET_DELIVERY password=SECRET_PASSWORD');
        $this->pdo->prepare('UPDATE orders SET status="delivered",delivered_at=? WHERE order_no=?')
            ->execute(array(gmdate('Y-m-d H:i:s'), $no));
        $this->pdo->prepare('INSERT INTO delivery_records(order_id) SELECT id FROM orders WHERE order_no=?')->execute(array($no));
    }
}

class DiagnosticsMemoryPdo
{
    private $db;
    public $fail = '';
    public $lock = 1;
    public $released = 0;
    public $beforeTransaction;
    public function __construct() {
        $this->db = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));
        $this->db->exec('CREATE TABLE orders(id INTEGER PRIMARY KEY,order_no TEXT,status TEXT,product_type_snapshot TEXT,duration_days_snapshot INTEGER,paid_payment_record_id INTEGER,delivered_at TEXT)');
        $this->db->exec('CREATE TABLE delivery_records(id INTEGER PRIMARY KEY,order_id INTEGER)');
        $this->db->exec('CREATE TABLE group_orders(id INTEGER PRIMARY KEY,order_id INTEGER)');
        $this->db->exec('CREATE TABLE subboost_sync_tasks(id INTEGER PRIMARY KEY,order_id INTEGER,source_hash TEXT,request_json TEXT,subscription_id TEXT,status TEXT,retry_count INTEGER DEFAULT 0,last_error TEXT DEFAULT "",available_at TEXT,created_at TEXT,updated_at TEXT,completed_at TEXT,opened_at TEXT,expires_at TEXT)');
        $this->db->exec('CREATE TABLE subboost_sync_attempts(id INTEGER PRIMARY KEY AUTOINCREMENT,task_id INTEGER,result TEXT,stage TEXT,error_code TEXT,http_status INTEGER,transport_errno INTEGER,duration_ms INTEGER,started_at TEXT,finished_at TEXT)');
    }
    public function prepare($sql) {
        if ($this->fail !== '' && strpos($sql, $this->fail) !== false) throw new PDOException('SECRET_DATABASE dsn=SECRET_DSN');
        if (strpos($sql, 'SELECT GET_LOCK') === 0) $sql = 'SELECT '.$this->lock;
        if (strpos($sql, 'SELECT RELEASE_LOCK') === 0) { $this->released++; $sql = 'SELECT 1'; }
        $sql = str_replace('SELECT DATABASE()', "SELECT 'isolated-diagnostics-memory'", $sql);
        $sql = preg_replace('/DATE_ADD\(UTC_TIMESTAMP\(\),INTERVAL (\?|[0-9]+) SECOND\)/', "datetime('now', '+' || $1 || ' seconds')", $sql);
        $sql = str_replace(array('UTC_TIMESTAMP()', ' FOR UPDATE'), array("datetime('now')", ''), $sql);
        // Lock statements have one unused parameter; SQLite accepts unused binds
        // only with a corresponding placeholder, so retain one in a tautology.
        if ($sql === 'SELECT '.$this->lock || $sql === 'SELECT 1') $sql .= ' WHERE ? IS NOT NULL';
        return $this->db->prepare($sql);
    }
    public function query($sql) { $s = $this->prepare($sql); $s->execute(); return $s; }
    public function exec($sql) { return $this->db->exec($sql); }
    public function lastInsertId() { return $this->db->lastInsertId(); }
    public function inTransaction() { return $this->db->inTransaction(); }
    public function beginTransaction() {
        if ($this->beforeTransaction) { $f=$this->beforeTransaction; $this->beforeTransaction=null; $f(); }
        return $this->db->beginTransaction();
    }
    public function commit() { return $this->db->commit(); }
    public function rollBack() { return $this->db->rollBack(); }
}

$checks = 0;
$groups = 0;
function same($expected, $actual, $label) {
    global $checks;
    $checks++;
    if ($expected !== $actual) throw new RuntimeException($label.' expected '.var_export($expected,true).' got '.var_export($actual,true));
}
function rejects($fn, $class, $label) {
    try { $fn(); } catch (Throwable $e) { same(true, $e instanceof $class, $label); return; }
    throw new RuntimeException($label.' did not reject');
}
function testGroup($label, $fn) {
    global $groups;
    $fn(); $groups++; print "PASS $label\n";
}
function cfg() { return array('app'=>array('timezone'=>'Asia/Shanghai'), 'subboost'=>array('url'=>'https://diagnostics.invalid','api_secret'=>'SECRET_API_KEY')); }
function seed($pdo, $id=1, $overrides=array()) {
    $pdo->prepare('INSERT INTO orders VALUES(?,?,"pending_delivery","vpn",30,1,NULL)')->execute(array($id,'ORDER_'.$id));
    $row=array('id'=>$id,'order_id'=>$id,'source_hash'=>hash('sha256',cfg()['subboost']['url']),
        'request_json'=>json_encode(array('orderNo'=>'ORDER_'.$id,'durationDays'=>30,'name'=>'SECRET_REQUEST')),
        'status'=>'pending','available_at'=>'2000-01-01 00:00:00','created_at'=>'2026-01-01 00:00:00','updated_at'=>'2026-01-01 00:00:00');
    $row=array_merge($row,$overrides);
    $pdo->prepare('INSERT INTO subboost_sync_tasks('.implode(',',array_keys($row)).') VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));
}
function fixture($overrides=array()) {
    SubBoostClient::$mode=''; SubBoostClient::$calls=array(); SubBoostClient::$afterRequest=null;
    OrderService::$failure=false; OrderService::$payload=null;
    $pdo=new DiagnosticsMemoryPdo(); seed($pdo,1,$overrides); return $pdo;
}
function runSync($pdo) { return (new SubBoostSyncService($pdo,cfg()))->runOnce(); }
function detail($pdo) { return (new SubBoostTaskService($pdo,cfg()))->detail(1); }
function attempt($pdo,$stage,$result,$code) {
    $d=detail($pdo); $h=$d['history'][0];
    same($stage,$h['stage'],'attempt stage'); same($result,$h['result'],'attempt result'); same($code,$h['error_code'],'safe code');
    same(true,$h['duration_ms']>=0,'duration saved'); same(true,$h['finished_at']!=='','finish time saved');
    same(false,$pdo->inTransaction(),'transaction closed'); same(1,$pdo->released,'lock released');
    same(false,strpos(json_encode($d),'SECRET_')!==false,'no secrets in diagnostic DTO');
    return $h;
}

// Restore environment even on failure; inherited production credentials are never used.
$environment=array();
foreach(array('SUBBOOST_URL','SUBBOOST_API_SECRET','SUBBOOST_CONNECT_HOST') as $name) { $environment[$name]=getenv($name); putenv($name); }
try {
    testGroup('query filters, exact order lookup, pagination and missing rows', function () {
        $p=new DiagnosticsMemoryPdo();
        for($i=1;$i<=105;$i++) seed($p,$i,array('status'=>$i%2?'retry':'waiting','last_error'=>$i%2?'http_503':''));
        $s=new SubBoostTaskService($p,cfg()); $r=$s->listing(array());
        same(105,$r['total'],'total'); same(3,$r['pages'],'pages'); same(50,count($r['items']),'page size'); same(105,(int)$r['items'][0]['id'],'descending');
        $r=$s->listing(array('page'=>'999999999999999999')); same(3,$r['page'],'clamp'); same(5,count($r['items']),'last page');
        same(53,$s->listing(array('status'=>'retry','error'=>'http_503'))['total'],'combined filters');
        same(1,$s->listing(array('order_no'=>' ORDER_1 ','id'=>'1'))['total'],'trim exact filters');
        same(0,$s->listing(array('order_no'=>'ORDER_'))['total'],'not substring');
        $r=$s->listing(array('id'=>'999')); same(1,$r['pages'],'empty pages'); same(array(),$r['items'],'empty items');
        same(null,$s->detail('999'),'missing detail');
    });
    testGroup('timezone boundaries, inclusive local day and DST', function () {
        $p=new DiagnosticsMemoryPdo();
        foreach(array('2026-01-01 15:59:59','2026-01-01 16:00:00','2026-01-02 15:59:59','2026-01-02 16:00:00') as $i=>$t) seed($p,$i+1,array('created_at'=>$t));
        $s=new SubBoostTaskService($p,cfg()); $r=$s->listing(array('from'=>'2026-01-02','to'=>'2026-01-02'));
        same(2,$r['total'],'UTC date boundaries'); same('2026-01-02 23:59:59',$r['items'][0]['created_at'],'UTC to Shanghai');
        same(3,$s->listing(array('from'=>'2026-01-02'))['total'],'from only'); same(3,$s->listing(array('to'=>'2026-01-02'))['total'],'to only');
        $p=new DiagnosticsMemoryPdo();
        foreach(array('2026-03-08 04:59:59','2026-03-08 05:00:00','2026-03-09 03:59:59','2026-03-09 04:00:00') as $i=>$t) seed($p,$i+1,array('created_at'=>$t));
        $c=cfg(); $c['app']['timezone']='America/New_York';
        same(2,(new SubBoostTaskService($p,$c))->listing(array('from'=>'2026-03-08','to'=>'2026-03-08'))['total'],'23 hour DST local day');
    });
    testGroup('unknown legacy secrets hidden and safe labels', function () {
        $p=fixture(array('last_error'=>'SECRET_LEGACY https://secret.invalid/?key=SECRET_API_KEY','status'=>'SECRET_STATUS','opened_at'=>'bad-date'));
        $p->exec('UPDATE orders SET status="SECRET_ORDER_STATE"');
        $p->exec('INSERT INTO subboost_sync_attempts(task_id,result,stage,error_code,started_at) VALUES(1,"SECRET_RESULT","SECRET_STAGE","SECRET_ERROR","bad-date")');
        $s=new SubBoostTaskService($p,cfg()); $d=$s->detail(1);
        same('unknown_error',$d['task']['error_code'],'legacy error'); same('unknown',$d['task']['status'],'unknown status'); same('unknown',$d['task']['order_status'],'unknown order status');
        same('unknown',$d['history'][0]['stage'],'unknown stage'); same('unknown',$d['history'][0]['result'],'unknown result'); same('',$d['history'][0]['started_at'],'invalid time hidden'); same('',$d['task']['opened_at'],'invalid task time');
        same(false,strpos(json_encode($d),'SECRET_')!==false,'DTO no unknown raw fields'); same(1,$s->listing(array('error'=>'unknown_error'))['total'],'unknown filter');
        $p->exec('UPDATE subboost_sync_tasks SET last_error="http_418"'); same(0,$s->listing(array('error'=>'unknown_error'))['total'],'generic HTTP not unknown');
        same(1,$s->listing(array('error'=>'http_418'))['total'],'generic HTTP filter');
        same('http_418',SubBoostTaskService::safeCode('http_418'),'HTTP whitelist'); same('unknown_error',SubBoostTaskService::safeCode("http_503\nSECRET"),'anchored code');
        same('database_error',SubBoostTaskService::exceptionCode(new PDOException('SECRET'),'delivery'),'PDO priority');
        same('delivery_failed',SubBoostTaskService::exceptionCode(new RuntimeException('SECRET'),'delivery'),'delivery fallback');
        same('sync_failed',SubBoostTaskService::exceptionCode(new RuntimeException('SECRET'),'source_check'),'generic fallback');
        same('未记录',SubBoostTaskService::transportReason(null),'null transport'); same('连接或请求超时',SubBoostTaskService::transportReason(28),'timeout label');
        same('其他传输错误（仅保留编号）',SubBoostTaskService::transportReason(999),'unknown transport');
        $p->exec('DELETE FROM orders'); same('',$s->detail(1)['task']['order_no'],'orphan preserved');
    });
    testGroup('invalid query/detail parameters rejected', function () {
        $s=new SubBoostTaskService(new DiagnosticsMemoryPdo(),cfg());
        $cases=array(array('status'=>'bad'),array('status'=>array()),array('error'=>'SECRET'),array('error'=>'http_600'),array('order_no'=>'x%'),array('order_no'=>str_repeat('x',41)),array('order_no'=>"x' OR 1=1"),array('id'=>'0'),array('id'=>'01'),array('id'=>'-1'),array('id'=>'1e2'),array('id'=>str_repeat('9',19)),array('from'=>'2026-02-30'),array('from'=>'0999-12-31'),array('to'=>'9999-01-01'),array('from'=>'2026-1-01'),array('from'=>'2026-02-01','to'=>'2026-01-01'),array('page'=>'0'),array('page'=>'1.0'),array('page'=>array()),array('page'=>1));
        foreach($cases as $c) rejects(function() use($s,$c){$s->listing($c);},'InvalidArgumentException','invalid filter '.json_encode($c));
        foreach(array(0,-1,'01','1 OR 1=1',array(),1.5) as $id) rejects(function() use($s,$id){$s->detail($id);},'InvalidArgumentException','invalid detail id');
        rejects(function() use($s){$s->detail(1,0);},'InvalidArgumentException','invalid history page');
        same('2024-02-29',SubBoostTaskService::filters(array('from'=>'2024-02-29'))['from'],'leap day valid');
    });
    testGroup('attempt whitelist, bounded metadata, immutable finished history and pagination', function () {
        $p=fixture(); $s=new SubBoostTaskService($p,cfg()); $id=$s->startAttempt(1); $h=$s->detail(1)['history'][0];
        same('running',$h['result'],'running recorded'); same('eligibility',$h['stage'],'initial stage'); same(null,$h['http_status'],'unset HTTP');
        $s->finishAttempt($id,'retry','create_subscription','SECRET_RAW',array('http_status'=>999,'transport_errno'=>-1,'body'=>'SECRET_BODY','url'=>'SECRET_URL'),PHP_INT_MAX);
        $h=$s->detail(1)['history'][0]; same(599,$h['http_status'],'HTTP clamp'); same(0,$h['transport_errno'],'errno lower clamp'); same(2147483647,$h['duration_ms'],'duration clamp'); same('unknown_error',$h['error_code'],'write whitelist');
        $s->finishAttempt($id,'completed','complete','',array(),0); same($h,$s->detail(1)['history'][0],'finished immutable');
        $id=$s->startAttempt(1); $s->finishAttempt($id,'waiting','identity_check','',array('http_status'=>-4,'transport_errno'=>10000),-5);
        $h=$s->detail(1)['history'][0]; same(0,$h['http_status'],'HTTP lower clamp'); same(9999,$h['transport_errno'],'errno clamp'); same(0,$h['duration_ms'],'duration lower clamp');
        rejects(function() use($s,$id){$s->finishAttempt($id,'SECRET','complete','',array(),0);},'LogicException','bad result');
        rejects(function() use($s,$id){$s->finishAttempt($id,'retry','SECRET','',array(),0);},'LogicException','bad stage');
        for($i=0;$i<51;$i++) { $id=$s->startAttempt(1); $s->finishAttempt($id,'completed','complete','',array(),1); }
        $d=$s->detail(1); same(53,$d['history_total'],'history retained'); same(50,count($d['history']),'history size'); same(2,$d['history_pages'],'history pages');
        $d=$s->detail(1,'999999999999999999'); same(2,$d['history_page'],'history clamp'); same(3,count($d['history']),'history tail');
    });
    testGroup('actual client diagnostics getter has no credential fields', function () {
        $c=new DiagnosticsOriginalClient(cfg()); same(array(),$c->diagnostics(),'initial diagnostics');
        $prop=new ReflectionProperty('DiagnosticsOriginalClient','diagnostic'); $prop->setAccessible(true);
        $prop->setValue($c,array('http_status'=>503,'transport_errno'=>28));
        same(array('http_status'=>503,'transport_errno'=>28),$c->diagnostics(),'getter only transport metadata');
        $copy=$c->diagnostics(); $copy['http_status']=401; same(503,$c->diagnostics()['http_status'],'getter copy');
    });
    testGroup('real request diagnostics: HTTP and cURL failures, body hiding and metadata reset', function () {
        $client=new DiagnosticsOfflineTransport\SubBoostClient(cfg());
        $cases=array(
            array(503,0,'SECRET_BODY SECRET_API_KEY https://private.invalid/SECRET_TOKEN','http_503'),
            array(0,28,'SECRET_CURL DNS password=SECRET_PASSWORD','http_transport_failed'),
            array(200,0,'SECRET_NON_JSON','invalid_response'),
            array(200,0,'{}','')
        );
        foreach($cases as $case) {
            $GLOBALS['diagnostics_transport']=array('http'=>$case[0],'errno'=>$case[1],'body'=>$case[2]);
            if($case[3]==='') same(array(),$client->status('ORDER_1'),'successful decode');
            else {
                try { $client->status('ORDER_1'); throw new LogicException('Expected request failure'); }
                catch(RuntimeException $e) { same($case[3],$e->getMessage(),'fixed transport error code'); }
            }
            same(array('http_status'=>$case[0],'transport_errno'=>$case[1]),$client->diagnostics(),'exact two diagnostic fields');
            same(false,strpos(json_encode($client->diagnostics()),'SECRET_')!==false,'no response/credential/URL leakage');
        }
        // Failed JSON encoding occurs before curl_exec; stale prior HTTP data must vanish.
        $recursive=array(); $recursive['self']=&$recursive;
        try { $client->create($recursive); throw new LogicException('Expected invalid request'); }
        catch(RuntimeException $e) { same('invalid_request',$e->getMessage(),'encoding failure'); }
        same(array(),$client->diagnostics(),'per request metadata reset');
        unset($GLOBALS['diagnostics_transport']);
    });
    testGroup('sync failures at configuration, source, request, HTTP, response, identity and dates', function () {
        $cases=array(
            array('configuration',array(),'source_check','invalid_configuration',null),
            array('',array('source_hash'=>'wrong'),'source_check','source_mismatch',null),
            array('',array('request_json'=>'{}'),'request_validation','invalid_duration',null),
            array('',array('request_json'=>'{"durationDays":30,"orderNo":"WRONG"}'),'request_validation','invalid_request',null),
            array('transport',array(),'create_subscription','http_transport_failed',0),
            array('http',array(),'create_subscription','http_503',503),
            array('http',array('subscription_id'=>'sub-test'),'status_query','http_503',503),
            array('response',array(),'response_validation','invalid_response',200),
            array('url',array(),'response_validation','invalid_subscription_url',200),
            array('identity',array('subscription_id'=>'sub-test'),'identity_check','identity_mismatch',200),
            array('dates',array(),'dates_check','invalid_dates',200),
            array('',array('opened_at'=>'2025-01-01 00:00:00','expires_at'=>'2025-01-31 00:00:00'),'dates_check','dates_changed',200)
        );
        foreach($cases as $case) {
            $p=fixture($case[1]); SubBoostClient::$mode=$case[0]; $r=runSync($p);
            same(1,$r['errors'],'one business failure'); same(0,$r['completed'],'not complete');
            $h=attempt($p,$case[2],'retry',$case[3]); same($case[4],$h['http_status'],'HTTP retained');
            if($case[0]==='transport') same(28,$h['transport_errno'],'transport errno retained');
            $d=detail($p); same('retry',$d['task']['status'],'retry task'); same(1,$d['task']['retry_count'],'retry increment'); same($case[3],$d['task']['error_code'],'task safe code');
            same(0,(int)$p->query('SELECT COUNT(*) FROM delivery_records')->fetchColumn(),'no delivery');
        }
    });
    testGroup('database persist and local delivery failures keep safe diagnostics and fixed dates', function () {
        $p=fixture(); $p->fail='SET opened_at'; same(1,runSync($p)['errors'],'database failure'); attempt($p,'persist_dates','retry','database_error');
        $p=fixture(); OrderService::$failure=true; same(1,runSync($p)['errors'],'delivery failed'); attempt($p,'delivery','retry','delivery_failed');
        same('2026-01-01 08:00:00',detail($p)['task']['opened_at'],'confirmed date survives rollback');
        same('pending_delivery',$p->query('SELECT status FROM orders')->fetchColumn(),'delivery rollback');
        same(0,(int)$p->query('SELECT COUNT(*) FROM delivery_records')->fetchColumn(),'no partial delivery');
    });
    testGroup('续费入队与误建任务都不请求上游', function () {
        SubBoostSyncService::enqueuePaidOrder(null,array('order_kind'=>'renewal'));
        $p=fixture();$p->exec('ALTER TABLE orders ADD order_kind TEXT DEFAULT "purchase"');$p->exec('UPDATE orders SET order_kind="renewal"');
        $result=runSync($p);same(0,$result['completed'],'续费误建任务不交付');same(0,$result['errors'],'资格拒绝不是网络错误');
        attempt($p,'eligibility','skipped','order_ineligible');same(array(),SubBoostClient::$calls,'续费没有创建或查询HTTP调用');
        same(0,(int)$p->query('SELECT COUNT(*) FROM delivery_records')->fetchColumn(),'续费无独立交付');
    });
    testGroup('eligibility skip, deferred race and locked identity recheck', function () {
        foreach(array('UPDATE orders SET status="refunding"','UPDATE orders SET product_type_snapshot="other"','UPDATE orders SET paid_payment_record_id=NULL','UPDATE orders SET delivered_at="2026-01-01"','INSERT INTO delivery_records(order_id) VALUES(1)','INSERT INTO group_orders(order_id) VALUES(1)','DELETE FROM orders') as $sql) {
            $p=fixture(); $p->exec($sql); $r=runSync($p); same(0,$r['errors'],'skip no error'); attempt($p,'eligibility','skipped','order_ineligible'); same(array(),SubBoostClient::$calls,'skip no HTTP');
        }
        $p=fixture(); $p->beforeTransaction=function() use($p){$p->exec('UPDATE orders SET status="refunding"');};
        same(0,runSync($p)['completed'],'race not delivered'); attempt($p,'eligibility','deferred','order_ineligible');
        $p=fixture(); $p->beforeTransaction=function() use($p){$p->exec('UPDATE subboost_sync_tasks SET source_hash="changed"');};
        same(1,runSync($p)['errors'],'locked identity failure'); attempt($p,'identity_check','retry','identity_mismatch');
    });
    testGroup('retry -> waiting -> success retains all history and avoids duplicate create', function () {
        $p=fixture(); SubBoostClient::$mode='http'; runSync($p);
        $first=detail($p)['history'][0];
        $p->exec('UPDATE subboost_sync_tasks SET available_at="2000-01-01"'); SubBoostClient::$mode='waiting';
        same(0,runSync($p)['errors'],'waiting not failure'); $d=detail($p); same('waiting',$d['task']['status'],'waiting task');
        same('waiting',$d['history'][0]['result'],'waiting attempt'); same('identity_check',$d['history'][0]['stage'],'waiting stage'); same('',$d['task']['error_code'],'old task error cleared');
        same($first,$d['history'][1],'original failure preserved');
        $p->exec('UPDATE subboost_sync_tasks SET available_at="2000-01-01"'); SubBoostClient::$mode=''; same(1,runSync($p)['completed'],'success');
        $d=detail($p); same(3,$d['history_total'],'all attempts preserved'); same('completed',$d['task']['status'],'task completed'); same('complete',$d['history'][0]['stage'],'complete stage'); same('completed',$d['history'][0]['result'],'complete result');
        same($first,$d['history'][2],'failure still preserved'); same(array('create','create','status'),SubBoostClient::$calls,'status after identity saved');
        same('2026-01-01 08:00:00',OrderService::$payload['opened_at'],'delivery local opening'); same('2026-01-31 08:00:00',OrderService::$payload['expires_at'],'delivery local expiry');
        same(0,runSync($p)['processed'],'completed not rerun'); same(1,(int)$p->query('SELECT COUNT(*) FROM delivery_records')->fetchColumn(),'one delivery only');
    });
    testGroup('diagnostic start/finish/getter faults do not change business completion', function () {
        foreach(array('INSERT INTO subboost_sync_attempts','UPDATE subboost_sync_attempts','getter') as $fault) {
            $p=fixture();
            if($fault==='getter') SubBoostClient::$mode='diagnostics_throw'; else $p->fail=$fault;
            $r=runSync($p); same(1,$r['completed'],'business succeeds despite diagnostic fault'); same(0,$r['errors'],'no business error');
            same('completed',detail($p)['task']['status'],'completed state'); same(1,$p->released,'lock released after journal fault'); same(false,$p->inTransaction(),'no open transaction');
            same(1,(int)$p->query('SELECT COUNT(*) FROM delivery_records')->fetchColumn(),'delivery retained');
            $d=detail($p); same($fault==='INSERT INTO subboost_sync_attempts'?0:1,$d['history_total'],'best effort history');
            if($d['history_total']) same('running',$d['history'][0]['result'],'unfinished journal honest');
        }
        $p=fixture(); $p->fail='INSERT INTO subboost_sync_attempts'; SubBoostClient::$mode='http';
        same(1,runSync($p)['errors'],'business failure still counted with broken journal'); same('http_503',detail($p)['task']['error_code'],'business safe error preserved');
    });
    testGroup('disabled, lock contention, transaction guard, limits and per-task reset', function () {
        $p=fixture(); $c=cfg(); $c['subboost']['api_secret']=''; same(true,(new SubBoostSyncService($p,$c))->runOnce()['disabled'],'disabled'); same(0,detail($p)['history_total'],'disabled no journal');
        $p=fixture(); $p->lock=0; same(true,runSync($p)['locked'],'lock contention'); same(0,detail($p)['history_total'],'locked no journal');
        $p=fixture(); $p->beginTransaction(); rejects(function() use($p){runSync($p);},'LogicException','nested worker prohibited'); $p->rollBack();
        $p=fixture(array('source_hash'=>'wrong')); seed($p,2); $r=runSync($p); same(2,$r['processed'],'two tasks'); same(1,$r['errors'],'first error'); same(1,$r['completed'],'second success');
        $d=(new SubBoostTaskService($p,cfg()))->detail(2); same('',$d['history'][0]['error_code'],'per task code reset'); same('completed',$d['history'][0]['result'],'per task result reset');
        $p=fixture(); seed($p,2); same(1,(new SubBoostSyncService($p,cfg()))->runOnce(0)['processed'],'minimum limit');
    });
    print "OK: $groups groups, $checks assertions (PHP ".PHP_VERSION.", SQLite memory only).\n";
    print "Expected diagnostic-fault messages on stderr are from deliberate fault injection.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: '.$e->getMessage()."\n".$e->getTraceAsString()."\n");
    exit(1);
} finally {
    foreach($environment as $name=>$value) { if($value===false) putenv($name); else putenv($name.'='.$value); }
    restore_error_handler();
}
