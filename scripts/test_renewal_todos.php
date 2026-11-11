<?php
// 仅限 CLI：真实服务、路由、AdminView 和 Security；不加载站点配置、真实会话或网络。
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
set_error_handler(function($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
$root = dirname(__DIR__);
foreach (array('Core/Router','Core/Security','Core/AdminView','Core/AdminExtraPages','Core/AdminLogPages','Core/AdminRenewalPages','Services/RenewalTodoService') as $file) require $root.'/app/'.$file.'.php';
function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function money($value) { return '¥'.number_format($value / 100, 2, '.', ''); }
function flash($type, $message) { $_SESSION['_flash'][$type] = $message; }
class RenewalTodoRedirect extends RuntimeException {}
function redirect($url) { throw new RenewalTodoRedirect($url); }
function page($title, $body, $config) {
    admin_page($title, $body, '', $_SERVER['REQUEST_URI'], $GLOBALS['db'], $config);
}
// 透明 SQL 观察器：所有语句仍由真实内存 PDO 执行。
class RenewalTodoObservedDB {
    public $pdo;
    public $sql = array();
    public $forbid = false;
    public function __construct($pdo) { $this->pdo = $pdo; }
    private function observe($sql) {
        $this->sql[] = $sql;
        if ($this->forbid) throw new LogicException('Database accessed before authentication');
    }
    public function query($sql) { $this->observe($sql); return $this->pdo->query($sql); }
    public function prepare($sql) { $this->observe($sql); return $this->pdo->prepare($sql); }
}
$assertions = 0; $failures = array();
function rt_ok($condition, $label) {
    $GLOBALS['assertions']++;
    if (!$condition) { $GLOBALS['failures'][] = $label; print('FAIL '.$label.PHP_EOL); }
}
function rt_same($actual, $expected, $label) { rt_ok($actual === $expected, $label); }
function rt_nosql($call, $label) {
    global $db;
    $before = count($db->sql);
    try { $call(); } finally { rt_same(count($db->sql), $before, $label); }
}
function rt_read($call) {
    global $pdo, $baseline;
    try { return $call(); }
    finally {
        rt_same((int)$pdo->query('SELECT total_changes()')->fetchColumn(), $baseline, 'total_changes unchanged');
        rt_same((int)$pdo->query('PRAGMA query_only')->fetchColumn(), 1, 'query_only remains ON');
    }
}
function rt_request($query = array(), $method = 'GET', $path = '/admin/renewals') {
    global $router;
    $_GET = $query; $_POST = array(); $_SERVER['REQUEST_URI'] = $path; $_SERVER['REQUEST_METHOD'] = $method;
    http_response_code(200); ob_start();
    try {
        rt_read(function() use ($router, $method, $path) { $router->dispatch($method, $path); });
        $html = ob_get_clean();
    } catch (Throwable $exception) { ob_end_clean(); throw $exception; }
    return array(http_response_code(), $html);
}
function rt_list($filters = array()) {
    global $service;
    return rt_read(function() use ($service, $filters) { return $service->listing($filters); });
}
function rt_names($listing) { return array_column($listing['items'], 'order_no'); }
function rt_one($name) {
    $list = rt_list(array('q'=>$name));
    rt_same($list['total'], 1, 'unique anomaly fixture '.$name);
    return $list['items'][0];
}
$pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,display_name TEXT,email TEXT,password_hash TEXT);
CREATE TABLE orders(id INTEGER PRIMARY KEY,order_no TEXT,user_id INTEGER,product_id INTEGER,order_kind TEXT,parent_order_id INTEGER,status TEXT,total_amount_cents INTEGER,duration_days_snapshot INTEGER,paid_at TEXT,created_at TEXT,payment_token TEXT);
CREATE TABLE order_renewals(order_id INTEGER UNIQUE,duration_days INTEGER,price_cents INTEGER);
CREATE TABLE delivery_records(id INTEGER PRIMARY KEY,order_id INTEGER,is_current INTEGER,version_no INTEGER,opened_at TEXT,expires_at TEXT,content_ciphertext TEXT,content_nonce TEXT,content_tag TEXT,subscription_url TEXT);
CREATE TABLE support_tickets(id INTEGER PRIMARY KEY,status TEXT);');
$xss = '<img src=x onerror=alert(1)>';
$secrets = array('PRIVATE_PASSWORD_HASH','PRIVATE_PAYMENT_TOKEN','PRIVATE_CIPHERTEXT','PRIVATE_NONCE','PRIVATE_TAG','https://private.example.test/SECRET_SUBSCRIPTION');
$pdo->prepare('INSERT INTO users VALUES(1,?,?,?)')->execute(array($xss, 'mail'.$xss.'@example.test', $secrets[0]));
$pdo->exec("INSERT INTO support_tickets VALUES(1,'open'),(2,'open'),(3,'closed')");
$expected = array(); $pendingAll = 0;
function rt_fixture($id, $no, $changes = array(), $metadata = true) {
    global $pdo, $expected, $pendingAll, $secrets;
    $row = array('id'=>$id,'order_no'=>$no,'user_id'=>1,'product_id'=>1,'order_kind'=>'renewal','parent_order_id'=>1,'status'=>'pending_delivery','total_amount_cents'=>1200,'duration_days_snapshot'=>30,'paid_at'=>'2001-01-01 12:00:00','created_at'=>'1990-01-01 00:00:00','payment_token'=>$secrets[1]);
    $row = array_replace($row, $changes);
    $pdo->prepare('INSERT INTO orders('.implode(',',array_keys($row)).') VALUES('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));
    if ($metadata && $row['order_kind'] === 'renewal') $pdo->prepare('INSERT INTO order_renewals VALUES(?,?,?)')->execute(array($id,30,1200));
    if ($row['status'] === 'pending_delivery') $pendingAll++;
    if ($row['order_kind'] === 'renewal' && $row['status'] === 'pending_delivery' && $row['paid_at'] !== null) $expected[] = $row;
}
function rt_delivery($id, $parent, $opened = '2000-01-01 00:00:00', $expiry = '2998-01-01 00:00:00', $current = 1) {
    global $pdo, $secrets;
    $pdo->prepare('INSERT INTO delivery_records VALUES(?,?,?,?,?,?,?,?,?,?)')->execute(array($id,$parent,$current,7,$opened,$expiry,$secrets[2],$secrets[3],$secrets[4],$secrets[5]));
}
rt_fixture(1,'PARENT-BASE',array('order_kind'=>'purchase','status'=>'delivered','parent_order_id'=>null));
rt_delivery(1,1);
rt_fixture(10,'FIRST-PAID',array('paid_at'=>'2000-01-02 00:00:00','created_at'=>'2020-01-01 00:00:00'));
rt_fixture(11,'SECOND-PAID',array('paid_at'=>'2000-01-03 00:00:00','created_at'=>'1980-01-01 00:00:00'));
rt_fixture(12,'UNPAID-PROCESSING',array('paid_at'=>null));
rt_fixture(13,'PURCHASE-PAID',array('order_kind'=>'purchase'));
rt_fixture(14,'UNPAID-RENEWAL',array('status'=>'pending_payment','paid_at'=>null));
$id = 20;
foreach (array('delivered','refunding','refunded','cancelled') as $status) rt_fixture($id++,'STATE-'.strtoupper($status),array('status'=>$status));
foreach (array('2005-06-09 23:59:59','2005-06-10 00:00:00','2005-06-10 23:59:59','2005-06-11 00:00:00') as $i=>$paid) rt_fixture(30+$i,'RANGE-'.($i+1),array('paid_at'=>$paid));
rt_fixture(40,'MISSING-PARENT',array('parent_order_id'=>99999));
rt_fixture(41,'MISSING-USER',array('user_id'=>99999));
rt_fixture(42,'MISSING-META',array(),false);
rt_fixture(43,'BAD-DAYS'); $pdo->exec('UPDATE order_renewals SET duration_days=31 WHERE order_id=43');
rt_fixture(44,'BAD-PRICE'); $pdo->exec('UPDATE order_renewals SET price_cents=1 WHERE order_id=44');
rt_fixture(45,'FUTURE-PAID',array('paid_at'=>'2999-01-01 00:00:00'));
rt_fixture(46,'BAD-PAID',array('paid_at'=>'2001-02-30 00:00:00'));
foreach (array(50=>'MULTI-CURRENT',51=>'BAD-DATE',52=>'NO-CURRENT',53=>'FUTURE-OPEN',54=>'REVERSED-DATE',55=>'PARENT-STATUS',56=>'WRONG-PRODUCT') as $i=>$name) {
    rt_fixture($i,'PARENT-'.$i,array('order_kind'=>'purchase','status'=>$i===55?'cancelled':'delivered','product_id'=>$i===56?2:1,'parent_order_id'=>null));
    rt_fixture($i+100,$name,array('parent_order_id'=>$i));
    if ($i!==52) rt_delivery($i,$i,$i===53?'2997-01-01 00:00:00':'2000-01-01 00:00:00',$i===51?'2000-02-30 00:00:00':($i===54?'1999-01-01 00:00:00':'2998-01-01 00:00:00'));
    if ($i===50) rt_delivery(150,50);
}
rt_fixture(170,'XSS-'.$xss);
// 倒序插入，确认同时间按 SQL id 升序，而非插入顺序或截断为 200 条。
for ($i=214; $i>=0; $i--) rt_fixture(1000+$i, 'BULK-'.str_pad((string)$i,3,'0',STR_PAD_LEFT));
rt_fixture(180,str_repeat('A',40));
rt_fixture(181,'LEGACY_PARENT',array('order_kind'=>'purchase','status'=>'delivered','parent_order_id'=>null));
rt_fixture(182,'LEGACY_PARENT-ABCDE',array('parent_order_id'=>181));
rt_fixture(183,'LEGACYXPARENT-ABCDE');
usort($expected,function($a,$b) { $cmp=strcmp($a['paid_at'],$b['paid_at']); return $cmp ?: ($a['id'] <=> $b['id']); });
$config = array('app'=>array('name'=>'isolated renewal todos','timezone'=>'Asia/Shanghai'));
$db = new RenewalTodoObservedDB($pdo);
$service = new RenewalTodoService($db,$config);
$router = new Router(); register_admin_renewal_routes($router,$db,$config);
$_SESSION = array('admin_id'=>9,'admin_name'=>$xss,'_csrf'=>'isolated-test-token');
rt_same(session_status(),PHP_SESSION_NONE,'no real PHP session');
if (in_array('--html', $_SERVER['argv'], true)) {
    $pdo->exec("UPDATE orders SET order_no='RENEWAL' WHERE id=10; UPDATE users SET display_name='隔离验收客户',email='fixture@example.test' WHERE id=1");
    $_SESSION['admin_name'] = '隔离验收管理员';
}
$pdo->exec('PRAGMA query_only=ON');
$baseline = (int)$pdo->query('SELECT total_changes()')->fetchColumn();
if (in_array('--html', $_SERVER['argv'], true)) {
    $query = array(); $home = false;
    foreach ($_SERVER['argv'] as $arg) {
        if (strpos($arg, '--query=') === 0) parse_str(substr($arg, 8), $query);
        if ($arg === '--home') $home = true;
    }
    if ($home) admin_page('隔离后台导航验收', '<div class="admin-info-banner">内存 SQLite 隔离验收，不使用真实管理员会话；请从侧栏进入续费待办。</div>', '', '/admin', $db, $config);
    else { list($code, $html) = rt_request($query); print($html); }
    exit;
}
try {
    $pdo->exec("UPDATE orders SET status='cancelled' WHERE id=10");
    rt_ok(false,'SQLite rejects writes');
} catch (PDOException $exception) { rt_ok(stripos($exception->getMessage(),'readonly')!==false,'SQLite query_only rejects actual write'); }
$all = rt_list();
rt_same($all['filters'],array('status'=>'pending_delivery','q'=>'','from'=>'','to'=>'','page'=>'1'),'default filters and string contract');
rt_same($all['total'],count($expected),'default only paid pending-delivery renewals, including flagged anomalies');
rt_same(rt_names($all),array_column(array_slice($expected,0,50),'order_no'),'earliest paid first, not created_at');
rt_same(array_slice(rt_names($all),0,2),array('FIRST-PAID','SECOND-PAID'),'first paid first handled');
rt_same(rt_read(function() use ($db) { return RenewalTodoService::pendingCount($db); }),count($expected),'independent pendingCount matches default collection');
$seen = array();
for ($p=1; $p<=$all['pages']; $p++) {
    $part = rt_list(array('page'=>(string)$p));
    rt_same($part['page'],$p,'requested page '.$p);
    rt_same(rt_names($part),array_column(array_slice($expected,($p-1)*50,50),'order_no'),'stable global page sequence '.$p);
    $seen = array_merge($seen,rt_names($part));
}
rt_same($seen,array_column($expected,'order_no'),'>200 rows all reachable without truncation, duplicates or gaps');
$bulk = array();
for ($p=1; $p<=5; $p++) $bulk=array_merge($bulk,rt_names(rt_list(array('q'=>'BULK-','page'=>(string)$p))));
rt_same($bulk,array_map(function($i){return 'BULK-'.str_pad((string)$i,3,'0',STR_PAD_LEFT);},range(0,214)),'215 identical paid timestamps sort by numeric id');
rt_same(rt_list(array('page'=>'999999999'))['page'],$all['pages'],'out-of-range page clamps to last');
rt_same(rt_list(array('q'=>'NO-MATCH'))['pages'],1,'empty results retain page one');
rt_same(rt_list(array('q'=>str_repeat('A',40)))['total'],1,'40-character alphanumeric query accepted');
rt_same(rt_list(array('q'=>' PARENT-BASE '))['filters']['q'],'PARENT-BASE','string filters trimmed');
rt_same(rt_names(rt_list(array('q'=>'LEGACY_PARENT'))),array('LEGACY_PARENT-ABCDE'),'原订单下划线按字面量匹配，不匹配单字符替代');
rt_same(rt_names(rt_list(array('q'=>'LEGACY_PARENT-ABCDE'))),array('LEGACY_PARENT-ABCDE'),'续费单号下划线可完整查询');
list($underscoreCode,$underscoreHtml)=rt_request(array('q'=>'LEGACY_PARENT'));
rt_same($underscoreCode,200,'下划线搜索真实GET成功');
rt_ok(strpos($underscoreHtml,'LEGACY_PARENT-ABCDE')!==false && strpos($underscoreHtml,'LEGACYXPARENT-ABCDE')===false,'页面仅展示字面量下划线对应订单');
$range = array('q'=>'RANGE-','from'=>'2005-06-10','to'=>'2005-06-10');
rt_same(rt_names(rt_list($range)),array('RANGE-2','RANGE-3'),'child substring plus inclusive paid date intersection');
rt_same(rt_names(rt_list(array_replace($range,array('q'=>'PARENT-BASE')))),array('RANGE-2','RANGE-3'),'parent substring and paid date intersection');
rt_same(rt_list(array_replace($range,array('q'=>'FIRST-PAID')))['total'],0,'keyword/date combine with AND');
rt_same(rt_list(array('q'=>'RANGE-','from'=>'2005-06-11'))['total'],1,'from only inclusive');
rt_same(rt_list(array('q'=>'RANGE-','to'=>'2005-06-09'))['total'],1,'to only includes end of day');
rt_same(array_keys(RenewalTodoService::statuses()),array('pending_delivery','pending_payment','delivered','refunding','refunded','cancelled'),'exact supported status whitelist, no all');
foreach (RenewalTodoService::statuses() as $status=>$label) {
    $listing = rt_list(array('status'=>$status));
    if ($status==='pending_payment') rt_same(rt_names($listing),array('UNPAID-RENEWAL'),'pending_payment explicitly finds unpaid renewal');
    elseif ($status!=='pending_delivery') rt_same(rt_names($listing),array('STATE-'.strtoupper($status)),'other status filter '.$status);
    list($code,$html)=rt_request(array('status'=>$status));
    rt_same($code,200,'real GET status '.$status);
    rt_ok(strpos($html,'value="'.$status.'" selected')!==false,'selected status '.$status);
    rt_ok(strpos($html,$status==='pending_delivery'?'>去处理</a>':'>查看详情</a>')!==false,'status-specific detail action '.$status);
}
$invalid = array();
foreach (array('status','q','from','to','page') as $key) foreach (array(null,1,true,array('1'),new stdClass()) as $value) $invalid[]=array($key=>$value);
foreach (array('', 'all','pending','PENDING_DELIVERY','unknown') as $value) $invalid[]=array('status'=>$value);
foreach (array('0','-1','1.5','1e2','01','1000000000','') as $value) $invalid[]=array('page'=>$value);
foreach (array(str_repeat('A',41),'A%B','A B','中文','A/B',"A\nB","A\0B",$xss,"' OR 1=1--") as $value) $invalid[]=array('q'=>$value);
foreach (array('2026-02-30','2025-02-29','2026-13-01','2026-1-01','0000-01-01','0999-01-01','2026-01-01 00:00:00') as $value) {
    $invalid[]=array('from'=>$value); $invalid[]=array('to'=>$value);
}
$invalid[]=array('from'=>'2026-11-11','to'=>'2026-11-10'); $invalid[]=array('to'=>'9999-12-31');
foreach ($invalid as $i=>$filters) {
    rt_nosql(function() use ($filters,$i) {
        $caught=false;
        try { rt_list($filters); } catch (InvalidArgumentException $exception) { $caught=true; }
        rt_ok($caught,'invalid service filters rejected '.$i);
    },'invalid service input never queries '.$i);
    $before=count($db->sql);
    list($code,$html)=rt_request($filters);
    rt_same($code,400,'real GET invalid filters 400 '.$i);
    rt_ok(strpos($html,'未查询任何记录')!==false && strpos($html,$xss)===false,'safe invalid-filter response '.$i);
    $queries=array_slice($db->sql,$before);
    rt_same(count($queries),3,'invalid GET only three real AdminView badge counts '.$i);
    rt_ok(!array_filter($queries,function($sql){return strpos($sql,' FROM orders o')!==false;}),'invalid GET no listing/fallback query '.$i);
}
foreach (array('MISSING-PARENT'=>'原订单关联异常','MISSING-USER'=>'客户资料缺失','MISSING-META'=>'续费金额元数据异常','BAD-DAYS'=>'续费时长元数据异常','BAD-PRICE'=>'续费金额元数据异常','MULTI-CURRENT'=>'当前交付缺失或不唯一','NO-CURRENT'=>'当前交付缺失或不唯一','BAD-DATE'=>'当前交付日期异常','FUTURE-OPEN'=>'当前交付日期异常','REVERSED-DATE'=>'当前交付日期异常','PARENT-STATUS'=>'原订单非已交付状态','WRONG-PRODUCT'=>'原订单关联异常','FUTURE-PAID'=>'付款时间异常','BAD-PAID'=>'付款时间异常') as $name=>$issue) {
    $row=rt_one($name);
    rt_ok(strpos($row['issues'],$issue)!==false,'anomaly reason '.$name);
    rt_same($row['preview'],'无法预览，请人工核对','fail-closed preview '.$name);
    if (in_array($name,array('FUTURE-PAID','BAD-PAID'),true)) rt_same($row['waiting'],'无法计算，请核对付款时间','unsafe paid date no negative/fabricated wait '.$name);
}
rt_same(rt_one('MULTI-CURRENT')['expires_at'],null,'multiple current records do not select arbitrary expiry');
rt_same(rt_one('BAD-DATE')['expires_at'],null,'invalid date not normalized');
// 使用固定时钟验证真实私有展示逻辑，不修改生产 API。
$present = new ReflectionMethod(RenewalTodoService::class,'present'); $present->setAccessible(true);
$dstService = new RenewalTodoService($db,array('app'=>array('timezone'=>'America/New_York')));
$raw = array('order_no'=>'DST','parent_order_no'=>'PARENT','user_id'=>1,'parent_user_id'=>1,'product_id'=>1,'parent_product_id'=>1,'parent_status'=>'delivered','display_name'=>'DST','email'=>'dst@example.test','price_cents'=>1200,'total_amount_cents'=>1200,'duration_days'=>2,'duration_days_snapshot'=>2,'current_count'=>1,'version_no'=>7,'opened_at'=>'2029-01-01 00:00:00','expires_at'=>'2030-03-09 12:00:00','status'=>'pending_delivery','paid_at'=>'2030-03-09 12:00:00');
$zone = new DateTimeZone('America/New_York');
$fixed = new DateTimeImmutable('2030-03-10 12:00:00',$zone);
$dst=rt_read(function() use ($present,$dstService,$raw,$fixed) { return $present->invoke($dstService,$raw,$fixed); });
rt_same($dst['waiting'],'0 天 23 小时 0 分钟','DST spring wait uses elapsed seconds, not calendar days');
rt_same($dst['preview'],'2030-03-12 12:00:00','expired preview starts at fixed now');
$beforeDst=new DateTimeImmutable('2030-03-08 12:00:00',$zone);
$raw['paid_at']='2030-03-07 12:00:00';
$dst=$present->invoke($dstService,$raw,$beforeDst);
rt_same($dst['preview'],'2030-03-11 13:00:00','unexpired spring DST renewal adds exactly 2*86400 seconds');
$raw['expires_at']='2030-11-02 12:00:00'; $raw['paid_at']='2030-11-01 12:00:00';
$dst=$present->invoke($dstService,$raw,new DateTimeImmutable('2030-11-03 12:00:00',$zone));
rt_same($dst['waiting'],'2 天 1 小时 0 分钟','DST fall wait includes repeated hour');
$dst=$present->invoke($dstService,$raw,new DateTimeImmutable('2030-11-01 13:00:00',$zone));
rt_same($dst['preview'],'2030-11-04 11:00:00','unexpired fall DST renewal adds fixed seconds');
$validRaw=$raw;
foreach (array(0,-1,'not-days',PHP_INT_MAX) as $days) {
    $badRaw=$validRaw; $badRaw['duration_days']=$days; $badRaw['duration_days_snapshot']=$days;
    $result=$present->invoke($dstService,$badRaw,new DateTimeImmutable('2030-11-01 13:00:00',$zone));
    rt_same($result['preview'],'无法预览，请人工核对','invalid/overflow duration cannot produce preview '.(string)$days);
    rt_ok(strpos($result['issues'],'续费时长元数据异常')!==false,'invalid duration flagged '.(string)$days);
}
$badRaw=$validRaw; $badRaw['expires_at']='9999-12-30 12:00:00';
$result=$present->invoke($dstService,$badRaw,new DateTimeImmutable('2030-11-01 13:00:00',$zone));
rt_same($result['preview'],'无法预览，请人工核对','expiry beyond year 9999 safely rejected');
rt_ok(strpos($result['issues'],'预计到期超出范围')!==false,'out-of-range expiry warning');
$badRaw=$validRaw; $badRaw['parent_user_id']=2;
$result=$present->invoke($dstService,$badRaw,new DateTimeImmutable('2030-11-01 13:00:00',$zone));
rt_same($result['preview'],'无法预览，请人工核对','cross-user parent safely rejected');
rt_ok(strpos($result['issues'],'原订单关联异常')!==false,'cross-user association warning');
$raw['status']='pending_payment'; $dst=$present->invoke($dstService,$raw,$fixed);
rt_same(array($dst['waiting'],$dst['preview']),array('不适用','不适用'),'unpaid status never estimates handling wait or expiry');
list($code,$html)=rt_request();
rt_same($code,200,'default real GET succeeds');
rt_ok(strpos($html,'class="admin-nav-item active" href="/admin/renewals" aria-current="page"')!==false,'normal real sidebar highlights renewals');
rt_ok((bool)preg_match('~href="/admin/renewals"[^>]*>.*?<span>续费待办</span><em>'.count($expected).'</em></a>~s',$html),'renewal badge independent paid renewal count');
rt_ok((bool)preg_match('~href="/admin/orders"[^>]*>.*?<span>订单管理</span><em>'.$pendingAll.'</em></a>~s',$html),'existing order badge preserved independently');
rt_ok($pendingAll!==count($expected),'badge fixture distinguishes unpaid/purchase orders');
rt_ok(strpos($html,'<span>售后工单</span><em>2</em>')!==false,'real ticket count');
rt_ok(strpos($html,'<form class="admin-log-filter" method="get" action="/admin/renewals">')!==false,'query form GET only');
rt_ok(strpos($html,'href="/admin/orders/FIRST-PAID">去处理</a>')!==false,'handle action points at existing admin order detail');
rt_ok(strpos($html,'href="/admin/orders/PARENT-BASE"')!==false,'parent order detail link');
rt_ok(strpos($html,e($xss))!==false && strpos($html,$xss)===false,'real customer/admin name and email escaped');
foreach (array('不会发起支付','调用 SubBoost 或自动延长订阅','不代表已验证实际付款凭证或上游操作','以详情页人工确认时的交付版本为准') as $text) rt_ok(strpos($html,$text)!==false,'read-only warning '.$text);
// 遍历真实分页：恶意存储单号不能通过已校验的 q 条件直接搜索。
$xssRow=null; $escapedOrderLinkSeen=false;
foreach ($seen as $name) if (strpos($name,'XSS-')===0) $xssRow=$name;
rt_ok($xssRow!==null,'malicious stored order fixture participates in real service');
foreach (range(1,$all['pages']) as $p) {
    list($code,$pageHtml)=rt_request(array('page'=>(string)$p));
    rt_ok(strpos($pageHtml,$xss)===false,'stored XSS escaped on page '.$p);
    if (strpos($pageHtml,e('XSS-'.$xss))!==false) {
        $escapedOrderLinkSeen=true;
        rt_ok(strpos($pageHtml,e('/admin/orders/'.rawurlencode('XSS-'.$xss)))!==false,'stored order number URL encoded and attribute escaped');
    }
    foreach (array_merge($secrets,array('password_hash','payment_token','content_ciphertext','content_nonce','content_tag','subscription_url','sourcehash','request_json','response_body')) as $secret) rt_ok(strpos($pageHtml,$secret)===false,'sensitive field/value omitted '.$secret.' page '.$p);
    preg_match_all('~<form\b[^>]*>~i',$pageHtml,$forms);
    foreach ($forms[0] as $form) if (stripos($form,'method="post"')!==false) rt_ok(strpos($form,'action="/admin/logout"')!==false,'only existing AdminView logout POST, no renewal mutations');
}
rt_ok($escapedOrderLinkSeen,'stored malicious order verified through a real GET, not only synthetic rendering');
// 即使 DTO 额外包含敏感字段，页面也仅渲染白名单。
$dto=rt_list(array('q'=>'FIRST-PAID'));
foreach ($secrets as $i=>$secret) $dto['items'][0]['unexpected_secret_'.$i]=$secret;
$dto['filters']['q']=$xss;
$rendered=admin_renewal_listing($dto);
rt_ok(strpos($rendered,'value="'.e($xss).'"')!==false && strpos($rendered,$xss)===false,'renderer independently escapes query input');
foreach ($secrets as $secret) rt_ok(strpos($rendered,$secret)===false,'additional DTO secret omitted '.$secret);
list($code,$html)=rt_request(array('q'=>'BULK-','from'=>'2001-01-01','to'=>'2001-01-01','page'=>'2'));
rt_ok(strpos($html,'status=pending_delivery&amp;q=BULK-&amp;from=2001-01-01&amp;to=2001-01-01&amp;page=3')!==false,'real next-page URL preserves filter intersection');
rt_ok(strpos($html,'rel="prev"')!==false && strpos($html,'第 2 / 5 页')!==false,'real pagination controls');
list($code,$html)=rt_request(array('q'=>'NO-MATCH'));
rt_ok(strpos($html,'暂无符合条件的续费订单')!==false,'real empty-state GET');
foreach (array(array(),array('user_id'=>1),array('admin_id'=>0)) as $session) {
    $_SESSION=$session; $db->forbid=true; $target='';
    rt_nosql(function() use (&$target) {
        try { rt_request(array('status'=>'all')); } catch (RenewalTodoRedirect $exception) { $target=$exception->getMessage(); }
    },'unauthorized request no service or AdminView SQL');
    $db->forbid=false;
    rt_same($target,'/admin/login','real Security redirects guest/customer before even invalid filters');
}
$_SESSION=array('admin_id'=>9,'_csrf'=>'isolated-test-token');
foreach (array('/admin/renewals','/admin/renewals/retry','/admin/renewals/FIRST-PAID') as $path) {
    rt_nosql(function() use ($path) { list($code,$body)=rt_request(array(),'POST',$path); rt_same($code,404,'no renewal POST route '.$path); },'POST never accesses DB '.$path);
}
$routesProperty=new ReflectionProperty(Router::class,'routes'); $routesProperty->setAccessible(true);
$routes=$routesProperty->getValue($router);
rt_same(array_keys($routes['GET']),array('/admin/renewals'),'registrar exposes only actual list GET');
rt_ok(empty($routes['POST']),'registrar exposes zero POST handlers');
$index=file_get_contents($root.'/public/index.php');
rt_ok(strpos($index,"require dirname(__DIR__) . '/app/Core/AdminRenewalPages.php';")!==false,'real public entry loads renewal admin pages');
rt_same(substr_count($index,'register_admin_renewal_routes($router, $pdo, $config);'),1,'real public entry registers route once');
rt_same((int)$pdo->query('SELECT total_changes()')->fetchColumn(),$baseline,'final total_changes unchanged across full suite');
rt_same((int)$pdo->query('PRAGMA query_only')->fetchColumn(),1,'never disable query_only after fixture preparation');
rt_same(session_status(),PHP_SESSION_NONE,'no session opened by suite or production code');
print(($failures?'FAIL ':'PASS ').$assertions.' isolated renewal todo assertions; '.count($failures).' failures; real service/routes/AdminView/Security, SQLite memory only.'.PHP_EOL);
exit($failures ? 1 : 0);
