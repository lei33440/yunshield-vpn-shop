<?php
// Isolated integration test. No site config, real sender, worker or business bootstrap.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$alAssertions = 0;
function al_ok($condition, $name) {
    if (!$condition) throw new RuntimeException('FAIL '.$name);
    $GLOBALS['alAssertions']++;
    echo 'PASS '.$name.PHP_EOL;
}
function al_query($pdo, $sql, $args = array()) {
    $s = $pdo->prepare($sql); $s->execute($args); return $s;
}
function al_log($pdo, $admin, $action, $details, $date = '2025-01-15 12:00:00', $target = 'system') {
    al_query($pdo, 'INSERT INTO admin_logs(admin_id,action,target_type,details_json,created_at) VALUES(?,?,?,?,?)', array($admin, $action, $target, is_array($details) ? json_encode($details) : $details, $date));
    return $pdo->lastInsertId();
}
function al_details($pdo, $id) {
    return json_decode(al_query($pdo, 'SELECT details_json FROM admin_logs WHERE id=?', array($id))->fetchColumn(), true);
}
function al_clean($value, $name) {
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    foreach (array('FIXTURE_SECRET', 'fixture-password', 'private-recipient@example.test', 'payload_json', 'details_json', 'password_hash') as $needle) {
        al_ok(strpos($json, $needle) === false, $name.' hides '.$needle);
    }
}
$root = dirname(__DIR__);
$dsn = getenv('CHAOXI_TEST_DSN');
$keys = array();
if (!$dsn || strpos($dsn, 'mysql:') !== 0) throw new RuntimeException('Explicit mysql test DSN required');
foreach (explode(';', substr($dsn, 6)) as $part) {
    if ($part === '') continue;
    if (!preg_match('/\A(host|dbname|port|charset)=([^;\s]+)\z/D', $part, $m) || isset($keys[$m[1]])) throw new RuntimeException('Unsupported or duplicate DSN field');
    $keys[$m[1]] = $m[2];
}
if (!isset($keys['dbname']) || !preg_match('/\Achaoxi_wallet_test_[A-Za-z0-9_]+\z/D', $keys['dbname'])) throw new RuntimeException('Isolated chaoxi_wallet_test_* dbname required');
if (!isset($keys['host']) || !in_array($keys['host'], array('db', 'localhost', '127.0.0.1', '::1'), true)) throw new RuntimeException('Local test host required');
if (isset($keys['port']) && (!ctype_digit($keys['port']) || (int)$keys['port'] < 1 || (int)$keys['port'] > 65535)) throw new RuntimeException('Invalid port');
if (isset($keys['charset']) && $keys['charset'] !== 'utf8mb4') throw new RuntimeException('Use utf8mb4');
$user = getenv('CHAOXI_TEST_USER'); $password = getenv('CHAOXI_TEST_PASSWORD');
if (!$user || in_array(strtolower($user), array('root', 'mysql', 'admin'), true)) throw new RuntimeException('Explicit restricted test user required');
date_default_timezone_set('UTC');
set_error_handler(function($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
spl_autoload_register(function($class) use ($root) {
    if (!preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/D', $class)) throw new LogicException('Invalid class');
    foreach (array('/app/Core/', '/app/Services/') as $directory) {
        $path = $root.$directory.$class.'.php';
        if (is_file($path)) { require_once $path; return; }
    }
});
$options = array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false);
$pdo = new PDO($dsn, $user, $password, $options);
al_ok($pdo->query('SELECT DATABASE()')->fetchColumn() === $keys['dbname'], 'actual isolated database');
$identity = $pdo->query('SELECT CURRENT_USER()')->fetchColumn();
al_ok(!in_array(strtolower(explode('@', $identity)[0]), array('root', 'mysql', 'admin'), true), 'effective account is not privileged');
// Fail closed: no roles, global privileges, other schemas, grant option or wildcard schema grants.
$schemaGrant = false;
foreach ($pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN) as $grant) {
    if (preg_match('/\AGRANT USAGE ON \*\.\* TO /i', $grant) && stripos($grant, 'WITH GRANT OPTION') === false) continue;
    if (!preg_match('/\AGRANT (.+) ON `([^`]+)`\.\* TO /i', $grant, $m) || stripos($grant, 'WITH GRANT OPTION') !== false) throw new RuntimeException('Account must have only isolated-schema grants');
    $scope = str_replace(array('\\_', '\\%'), array('_', '%'), $m[2]);
    if ($scope !== $keys['dbname']) throw new RuntimeException('Grant outside explicit test database');
    // An unescaped underscore can be a MySQL grant wildcard: require escaped underscores.
    if (preg_match('/(?<!\\\\)[_%]/', $m[2])) throw new RuntimeException('Wildcard schema grants are not allowed');
    foreach (explode(',', strtoupper($m[1])) as $privilege) {
        if (!in_array(trim($privilege), array('ALL PRIVILEGES','SELECT','INSERT','UPDATE','DELETE','CREATE','DROP','ALTER','INDEX','REFERENCES','CREATE TEMPORARY TABLES','LOCK TABLES'), true)) throw new RuntimeException('Unexpected test privilege');
    }
    $schemaGrant = true;
}
al_ok($schemaGrant, 'restricted explicit schema grants');
al_ok((int)$pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn() === 0, 'empty dedicated database before migration');
require $root.'/scripts/migrate.php';
run_migrations($pdo, $root);
$pdo->exec("SET time_zone='+00:00'");
$pdo->exec('SET NAMES utf8mb4');
$service = new AdminLogService($pdo);
$xss = '<img src=x onerror=alert(1)>';
$secret = 'FIXTURE_SECRET smtp-password token body subscription';
$hash = password_hash('fixture-password', PASSWORD_DEFAULT);
foreach (array(array('active@example.test', 'active', $xss), array('disabled@example.test', 'disabled', 'Disabled'), array('second@example.test', 'active', 'Second')) as $a) {
    al_query($pdo, 'INSERT INTO admin_users(email,password_hash,display_name,status,created_at,updated_at) VALUES(?,?,?,?,NOW(),NOW())', array($a[0], $hash, $a[2], $a[1]));
}
$admins = $pdo->query('SELECT id FROM admin_users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$admin = $admins[0]; $disabled = $admins[1]; $second = $admins[2];
$before = time();
$authenticated = $service->authenticate('active@example.test', 'fixture-password', '127.0.0.1');
al_ok((string)$authenticated['id'] === (string)$admin && !isset($authenticated['password_hash']), 'authenticate success without hash');
$last = al_query($pdo, 'SELECT last_login_at FROM admin_users WHERE id=?', array($admin))->fetchColumn();
al_ok(strtotime($last.' UTC') >= $before && strtotime($last.' UTC') <= time(), 'last_login_at updated');
foreach (array(array('active@example.test','wrong-password',$admin), array('unknown@example.test','fixture-password',0), array('disabled@example.test','fixture-password',$disabled)) as $case) {
    al_ok($service->authenticate($case[0], $case[1], $xss) === false, 'reject '.$case[0]);
    $row = $pdo->query('SELECT * FROM admin_logs ORDER BY id DESC LIMIT 1')->fetch(); $d = json_decode($row['details_json'], true);
    al_ok((int)$row['admin_id'] === (int)$case[2] && $row['action'] === 'admin_login_failed' && $d['ip'] === '' && $d['reason'] === 'credentials_invalid', 'failed audit actor and safe IP');
    al_clean($d, 'login audit');
}
al_ok(al_query($pdo, 'SELECT last_login_at FROM admin_users WHERE id=?', array($admin))->fetchColumn() === $last, 'failed login preserves last_login_at');
al_ok(al_query($pdo, 'SELECT last_login_at FROM admin_users WHERE id=?', array($disabled))->fetchColumn() === null, 'disabled last_login_at untouched');
al_ok($service->authenticate('active@example.test','fixture-password','2001:db8::1') !== false, 'IPv6 accepted');
$d = json_decode($pdo->query('SELECT details_json FROM admin_logs ORDER BY id DESC LIMIT 1')->fetchColumn(), true);
al_ok($d['ip'] === '2001:db8::1' && $d['result'] === 'success', 'IPv6 persisted');
$loginAudit = json_encode($pdo->query('SELECT * FROM admin_logs ORDER BY id')->fetchAll());
foreach (array('fixture-password','wrong-password',$hash,'unknown@example.test','password_hash') as $needle) al_ok(strpos($loginAudit,$needle) === false,'persisted login audits omit credentials '.$needle);
class AdminLogsFaultPDO extends PDO {
    public $fail = ''; public $rollbacks = 0; public $insertInTransaction = null;
    #[\ReturnTypeWillChange]
    public function prepare($query, $options = array()) {
        if (strpos($query, 'INSERT INTO admin_logs') === 0) {
            $this->insertInTransaction = $this->inTransaction();
            if ($this->fail === 'insert') throw new PDOException('FIXTURE_SECRET insert fault');
        }
        if ($this->fail === 'finish' && strpos($query, 'UPDATE admin_logs SET details_json=') === 0) throw new PDOException('FIXTURE_SECRET finish fault');
        if ($this->fail === 'login_update' && strpos($query, 'UPDATE admin_users SET last_login_at=') === 0) throw new PDOException('FIXTURE_SECRET login update fault');
        return parent::prepare($query, $options);
    }
    public function rollBack(): bool { $this->rollbacks++; return parent::rollBack(); }
}
$fault = new AdminLogsFaultPDO($dsn, $user, $password, $options);
al_ok($fault->query('SELECT DATABASE()')->fetchColumn() === $keys['dbname'], 'fault PDO same isolated schema');
$faultService = new AdminLogService($fault);
foreach (array('insert', 'login_update') as $mode) {
    al_query($pdo, 'UPDATE admin_users SET last_login_at=? WHERE id=?', array('2001-01-01 00:00:00', $admin));
    $count = $pdo->query('SELECT COUNT(*) FROM admin_logs')->fetchColumn(); $rollbacks = $fault->rollbacks; $fault->fail = $mode; $caught = null;
    try { $faultService->authenticate('active@example.test', 'fixture-password', '127.0.0.1'); } catch (RuntimeException $e) { $caught = $e; }
    al_ok($caught && $caught->getMessage() === '管理员登录暂不可用，请稍后重试。', $mode.' safe login error');
    al_ok($fault->rollbacks === $rollbacks + 1 && !$fault->inTransaction() && $fault->insertInTransaction === true, $mode.' rollback required');
    al_ok($pdo->query('SELECT COUNT(*) FROM admin_logs')->fetchColumn() === $count && al_query($pdo, 'SELECT last_login_at FROM admin_users WHERE id=?', array($admin))->fetchColumn() === '2001-01-01 00:00:00', $mode.' atomic audit and login timestamp');
}
class AdminLogsEmailStub {
    private $pdo; public $calls = array(); public $result = true; public $error = null;
    public function __construct($pdo) { $this->pdo = $pdo; }
    public function sendTest($recipient) {
        if (!is_string($recipient) || !preg_match('/\A[^@\s]+@example\.test\z/iD', $recipient)) throw new LogicException('Only example.test fixtures');
        $this->calls[] = $this->pdo->inTransaction();
        if ($this->error !== null) throw new RuntimeException($this->error);
        return $this->result;
    }
}
$fault->fail = 'insert';
al_ok($faultService->authenticate('unknown@example.test','fixture-password','127.0.0.1') === false && !$fault->inTransaction(), 'failed authentication remains denied on audit fault');
$fault->fail = '';
foreach (array(null,array('fixture@example.test'),$xss,'invalid-address') as $invalidAddress) al_ok(AdminLogService::maskEmail($invalidAddress) === '未记录 / 无效地址','invalid recipient safely masked without SMTP');
$email = new AdminLogsEmailStub($pdo);
$cases = array(array(true,null,'accepted',''), array(false,null,'failed','smtp_disabled'));
foreach (array('SMTP 未启用。'=>'smtp_disabled','SMTP 尚未完整配置。'=>'smtp_config_incomplete','收件地址无效。'=>'recipient_invalid','SMTP 网络连接失败。'=>'smtp_connect_failed','SMTP TLS 握手失败。'=>'smtp_tls_failed','SMTP 响应异常。'=>'smtp_response_unexpected', $secret=>'send_failed') as $message=>$code) $cases[] = array(true,$message,'failed',$code);
foreach ($cases as $case) {
    $email->result = $case[0]; $email->error = $case[1];
    $r = $service->testEmail($email, $admin, 'private-recipient@example.test'); $d = al_details($pdo, $r['id']);
    al_ok($r['result'] === $case[2] && $r['error_code'] === $case[3] && $r['log_saved'] === true, 'testEmail '.$case[3].' '.$case[2]);
    al_ok($d['result'] === $case[2] && $d['error_code'] === $case[3] && isset($d['started_at'], $d['finished_at']) && $d['recipient_masked'] === 'p***@example.test', 'email safe final audit');
    al_clean(array($r,$d), 'email outcome');
}
al_ok(count($email->calls) === count($cases) && array_unique($email->calls) === array(false), 'all SMTP stub calls outside transaction');
$faultEmail = new AdminLogsEmailStub($fault); $fault->fail = 'insert'; $caught = false;
try { $faultService->testEmail($faultEmail,$admin,'fixture@example.test'); } catch (PDOException $e) { $caught = true; }
al_ok($caught && $faultEmail->calls === array(), 'initial audit insert failure prevents SMTP');
$fault->fail = 'finish';
$r = $faultService->testEmail($faultEmail,$admin,'fixture@example.test'); $d = al_details($pdo,$r['id']);
$unfinished = $r['id'];
al_ok($r['result'] === 'accepted' && !$r['log_saved'] && $d['result'] === 'started' && !isset($d['finished_at']) && $faultEmail->calls === array(false) && $fault->insertInTransaction === false, 'final update fault retains started without transaction or resend');
$fault->fail = '';
$legacy = al_log($pdo,$admin,'test_smtp_email',array('recipient'=>'private-recipient@example.test','body'=>$secret));
$started = al_log($pdo,$admin,'test_smtp_email',array('result'=>'started','recipient_masked'=>'p***@example.test','body'=>$secret));
$invalidEmailLog = al_log($pdo,$admin,'test_smtp_email',array('result'=>array('accepted'),'recipient_masked'=>$xss,'finished_at'=>$xss,'error_code'=>$secret));
al_log($pdo,$admin,'admin_login_failed',array('ip'=>$xss,'password'=>$secret));
al_log($pdo,$admin,'update_smtp_settings',array('password'=>$secret,'json'=>$xss));
al_log($pdo,$admin,'unknown_'.$xss,'invalid FIXTURE_SECRET JSON', '2025-01-15 12:00:00', $xss);
// Direct event fixtures only: no real OrderService or money/delivery side effects.
al_query($pdo,'INSERT INTO users(email,password_hash,created_at,updated_at) VALUES(?,?,NOW(),NOW())',array('buyer@example.test',$hash)); $uid = $pdo->lastInsertId();
$orderIds = array();
foreach (array('LOG-EXACT','LOG-EXACT-EXTRA',$xss) as $number) {
    al_query($pdo,'INSERT INTO orders(order_no,user_id,product_name_snapshot,product_description_snapshot,unit_price_cents_snapshot,total_amount_cents,status,temporary_subscription_url,created_at,updated_at) VALUES(?,?,?,?,0,0,?,?,NOW(),NOW())',array($number,$uid,'Fixture',$secret,'delivered','https://example.test/FIXTURE_SECRET'));
    $orderIds[] = $pdo->lastInsertId();
}
for ($i=0; $i<105; $i++) {
    $actor = $i % 2 ? $admin : $second;
    $date = $i % 2 ? '2025-01-15 23:59:59' : '2025-01-16 00:00:00';
    al_log($pdo,$actor,'update_product',array('secret'=>$secret),$date,'product');
    al_log($pdo,$actor,$i % 2 ? 'admin_login_success' : 'admin_login_failed',array('ip'=>'127.0.0.1','secret'=>$secret),$date,'admin');
    al_log($pdo,$actor,'test_smtp_email',array('result'=>$i % 2 ? 'accepted' : 'failed','recipient'=>'private-recipient@example.test','error_code'=>$secret),$date);
    $event = array('delivered','delivery_updated','delivery_reverted')[$i % 3];
    al_query($pdo,'INSERT INTO order_events(order_id,event_type,old_status,new_status,description,actor_type,actor_id,created_at) VALUES(?,?,?,?,?,?,?,?)',array($orderIds[$i % 3],$event,'pending_delivery','delivered',$secret,'admin',$actor,$date));
    $status = array('pending','processing','retry','failed','sent')[$i % 5];
    al_query($pdo,'INSERT INTO notification_outbox(channel,event_type,dedupe_key,payload_json,status,retry_count,available_at,locked_at,sent_at,last_error,created_at,updated_at) VALUES(?,?,?,?,?,2,?,?,?,?,?,?)',array('email',$i % 2 ? 'password_reset':'order_delivered','log-fixture-'.$i,json_encode(array('to'=>'private-recipient@example.test','body'=>$secret,'token'=>$secret)),$status,$date,$status==='processing'?$date:null,$status==='sent'?$date:null,$secret,$date,$date));
}
al_query($pdo,'INSERT INTO order_events(order_id,event_type,description,actor_type,actor_id,created_at) VALUES(?,?,?,?,?,?)',array($orderIds[0],'payment_confirmed',$secret,'admin',$admin,'2025-01-15 00:00:00'));
foreach (array('system','user') as $actorType) al_query($pdo,'INSERT INTO order_events(order_id,event_type,description,actor_type,actor_id,created_at) VALUES(?,?,?,?,?,?)',array($orderIds[0],'delivered',$secret,$actorType,$admin,'2025-01-15 00:00:00'));
al_query($pdo,'INSERT INTO notification_outbox(channel,event_type,dedupe_key,payload_json,status,available_at,last_error,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)',array('wecom','password_reset','log-wecom',json_encode(array('to'=>'private-recipient@example.test','secret'=>$secret)),'pending','2025-01-15 00:00:00',$secret,'2025-01-15 00:00:00','2025-01-15 00:00:00'));
al_query($pdo,'INSERT INTO notification_outbox(channel,event_type,dedupe_key,payload_json,status,available_at,last_error,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)',array('email',$xss,'log-invalid','invalid FIXTURE_SECRET JSON','unknown','2025-01-15 00:00:00',$secret,'2025-01-15 00:00:00','2025-01-15 00:00:00'));
$invalidQueue = $pdo->lastInsertId();
$outboxBefore = $pdo->query('SELECT * FROM notification_outbox ORDER BY id')->fetchAll();
// Only helper e is shimmed because Bootstrap executes config-dependent code on include.
function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
require_once $root.'/app/Core/AdminExtraPages.php';
require_once $root.'/app/Core/AdminLogPages.php';
$views = array(array('tab'=>'audit'),array('tab'=>'login'),array('tab'=>'delivery'),array('tab'=>'email'),array('tab'=>'email','source'=>'test'));
foreach ($views as $view) {
    $label = $view['tab'].(isset($view['source'])?'-test':'');
    $first = $service->listing($view); $next = $service->listing($view+array('page'=>'2')); $end = $service->listing($view+array('page'=>'999999999'));
    al_ok(count($first['items']) === 50 && count($next['items']) === 50 && $first['total'] > 100 && $first['pages'] === (int)ceil($first['total']/50), $label.' 50-row pagination');
    $ids = array_map('intval',array_column($first['items'],'id')); $sorted = $ids; rsort($sorted);
    al_ok($ids === $sorted && !array_intersect($ids,array_column($next['items'],'id')), $label.' stable descending non-overlap');
    al_ok($end['page'] === $end['pages'] && count($end['items']) === $end['total']-50*($end['pages']-1), $label.' page clamped');
    al_clean($first['items'],$label.' projection');
    $html = admin_log_body($first); al_clean($html,$label.' HTML');
    al_ok(strpos($html,'<table>') !== false && strpos($html,'rel="next"') !== false && strpos($html,$xss) === false, $label.' render and XSS escaping');
    if ($view['tab'] !== 'email' || isset($view['source'])) al_ok(strpos($html,e($xss)) !== false, $label.' escaped administrator rendered');
    $empty = $first; $empty['items']=array(); $empty['total']=0; $empty['pages']=1; $empty['page']=1;
    al_ok(strpos(admin_log_body($empty),'暂无符合') !== false,$label.' empty view');
    $error = $empty; $error['error']=$xss; $error['filters']['from']=$xss;
    $errorHtml = admin_log_body($error);
    al_ok(strpos($errorHtml,'role="alert"') !== false && strpos($errorHtml,$xss) === false && strpos($errorHtml,e($xss)) !== false && strpos($errorHtml,'<table>') === false,$label.' invalid interval view escapes and shows no results');
    // Renderer must ignore raw/unprojected extras even when callers accidentally attach them.
    $raw = $first; foreach ($raw['items'] as &$item) { $item['details_json']=$secret; $item['payload_json']=$secret; $item['password_hash']=$hash; } unset($item);
    al_ok(admin_log_body($raw) === $html,$label.' explicit renderer column whitelist');
    $injected = $first;
    foreach ($injected['items'][0] as $key=>$value) $injected['items'][0][$key] = $xss;
    $injectedHtml = admin_log_body($injected);
    al_ok(strpos($injectedHtml,$xss) === false && strpos($injectedHtml,e($xss)) !== false,$label.' every displayed field escapes hostile text');
    if (in_array($view['tab'],array('login','email'),true)) al_ok(strpos($injectedHtml,'class="admin-status pending_payment"') !== false,$label.' hostile status uses safe badge class');
    $dated = $service->listing($view+array('from'=>'2025-01-15','to'=>'2025-01-15'));
    foreach ($dated['items'] as $item) al_ok(substr($item['time'],0,10) === '2025-01-15',$label.' inclusive date');
    $future = $service->listing($view+array('from'=>'2099-01-01','to'=>'2099-01-01'));
    al_ok($future['total'] === 0,$label.' date excludes rows');
}
// Compare exact filter totals with independent SQL, not only the first projected page.
$filterCases = array(
    array(array('tab'=>'audit','admin_id'=>(string)$second,'action'=>'update_product'),'SELECT COUNT(*) FROM admin_logs WHERE admin_id=? AND action=?',array($second,'update_product')),
    array(array('tab'=>'login','admin_id'=>(string)$second,'status'=>'failed'),'SELECT COUNT(*) FROM admin_logs WHERE admin_id=? AND action=?',array($second,'admin_login_failed')),
    array(array('tab'=>'login','status'=>'success'),'SELECT COUNT(*) FROM admin_logs WHERE action=?',array('admin_login_success')),
    array(array('tab'=>'delivery','admin_id'=>(string)$admin,'event'=>'delivered'),'SELECT COUNT(*) FROM order_events WHERE actor_type="admin" AND actor_id=? AND event_type=?',array($admin,'delivered')),
    array(array('tab'=>'delivery','order_no'=>'LOG-EXACT'),'SELECT COUNT(*) FROM order_events WHERE order_id=? AND event_type IN ("delivered","delivery_updated","delivery_reverted")',array($orderIds[0])),
    array(array('tab'=>'email','status'=>'retry','event'=>'password_reset'),'SELECT COUNT(*) FROM notification_outbox WHERE channel="email" AND status=? AND event_type=?',array('retry','password_reset')),
    array(array('tab'=>'email','id'=>(string)$invalidQueue),'SELECT COUNT(*) FROM notification_outbox WHERE id=? AND channel="email"',array($invalidQueue)),
    array(array('tab'=>'email','source'=>'test','admin_id'=>(string)$second,'status'=>'failed'),'SELECT COUNT(*) FROM admin_logs WHERE admin_id=? AND action="test_smtp_email" AND JSON_UNQUOTE(JSON_EXTRACT(details_json,"$.result"))=?',array($second,'failed'))
);
foreach ($filterCases as $n=>$case) {
    $r = $service->listing($case[0]); $expected = (int)al_query($pdo,$case[1],$case[2])->fetchColumn();
    al_ok($expected > 0 && $r['total'] === $expected,'exact combined filters '.$n);
}
foreach (AdminLogService::statuses() as $status=>$unused) {
    $r = $service->listing(array('tab'=>'email','status'=>$status));
    al_ok($r['total'] > 0 && array_unique(array_column($r['items'],'status')) === array($status),'queue status '.$status);
}
foreach (AdminLogService::results() as $status=>$unused) {
    $r = $service->listing(array('tab'=>'email','source'=>'test','status'=>$status));
    al_ok($r['total'] > 0 && array_unique(array_column($r['items'],'status')) === array($status),'test status '.$status);
}
foreach (array($legacy=>'legacy',$started=>'started',$invalidEmailLog=>'legacy',$unfinished=>'started') as $id=>$status) {
    // admin-log ID is not a supported test-source filter: inspect every page instead.
    $found = null; $p = 1;
    do { $h=$service->listing(array('tab'=>'email','source'=>'test','page'=>(string)$p)); foreach ($h['items'] as $item) if ((string)$item['id'] === (string)$id) $found=$item; $p++; } while ($p <= $h['pages']);
    al_ok($found && $found['status'] === $status,'historical/started projection #'.$id);
    if ((string)$id === (string)$legacy) al_ok($found['recipient'] === 'p***@example.test','historical recipient masked');
    al_clean($found,'historical projection');
}
$unknownQueue = $service->listing(array('tab'=>'email','id'=>(string)$invalidQueue));
al_ok($unknownQueue['items'][0]['status'] === 'unknown' && $unknownQueue['items'][0]['recipient'] === '未记录 / 无效地址' && $unknownQueue['items'][0]['reason'] === '历史错误详情已隐藏','invalid queue JSON and unknown status safely projected');
$bad = array('tab'=>array('login'),'source'=>array('test'),'from'=>array('2025-02-30'),'to'=>'','admin_id'=>'1 OR 1=1','id'=>'-1','page'=>'0','status'=>$xss,'action'=>$xss,'event'=>array('delivered'),'order_no'=>str_repeat('x',41));
$f = AdminLogService::filters($bad);
al_ok($f === AdminLogService::filters(array()),'invalid filter values normalize to safe defaults');
foreach (array(array('from'=>'2025-02-01','to'=>'2025-01-01'),array('from'=>'2025-01-16','to'=>'2025-01-15','tab'=>'email'),array('from'=>'2025-02-30'),array('to'=>'9999-01-01'),array('from'=>$xss),array('to'=>'not-a-date')) as $input) {
    $caught=false; try { $service->listing($input); } catch (InvalidArgumentException $e) { $caught=true; }
    al_ok($caught,'invalid or reversed date rejected without broad listing');
}
foreach (array("' OR 1=1 --",'LOG-%','LOG-EXACT-EX') as $order) al_ok($service->listing(array('tab'=>'delivery','order_no'=>$order))['total'] === 0,'order filter is exact bound value '.$order);
$xssHistory = $service->listing(array('tab'=>'delivery','order_no'=>$xss));
al_ok($xssHistory['total'] > 0 && strpos(admin_log_body($xssHistory),e($xss)) !== false && strpos(admin_log_body($xssHistory),$xss) === false,'order number input and cells escaped');
$loginHistory=$service->listing(array('tab'=>'login','admin_id'=>'0'));
al_ok($loginHistory['total'] === 1 && $loginHistory['items'][0]['admin'] === '未识别账号' && $loginHistory['items'][0]['ip'] === '未记录','unknown admin zero filter and unsafe IP projection');
$url = admin_log_url(array('tab'=>'delivery','order_no'=>$xss,'secret'=>$secret,'page'=>1),array('page'=>2));
al_ok(strpos($url,$secret) === false && strpos($url,$xss) === false && strpos($url,'page=2') !== false,'URL whitelist and encoding');
al_ok($pdo->query('SELECT * FROM notification_outbox ORDER BY id')->fetchAll() === $outboxBefore,'ALL outbox rows and every field unchanged by listing/render');
al_ok(!$pdo->inTransaction() && !$fault->inTransaction(),'no leaked transactions');
foreach (get_included_files() as $included) {
    $path = str_replace('\\','/',$included);
    al_ok(!preg_match('~/(?:config/[^/]+\.php|app/Core/(?:Bootstrap|Database)\.php|scripts/[^/]*worker\.php)$~i',$path),'no config/bootstrap/database/worker include '.basename($path));
}
echo 'ADMIN LOGS COMPLETE ASSERTIONS='.$alAssertions.PHP_EOL;
