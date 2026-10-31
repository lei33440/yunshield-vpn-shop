<?php
// Local isolated integration test only. Never load site config/bootstrap or real senders.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$prwAssertions = 0;
function prw_ok($condition, $name) {
    if (!$condition) throw new RuntimeException('FAIL '.$name);
    $GLOBALS['prwAssertions']++;
    echo 'PASS '.$name.PHP_EOL;
}
$root = dirname(__DIR__);
$dsn = getenv('CHAOXI_TEST_DSN');
$parts = $dsn && strpos($dsn, 'mysql:') === 0 ? explode(';', substr($dsn, 6)) : array();
$names = array(); $hosts = array();
foreach ($parts as $part) {
    if (preg_match('/^\s*dbname\s*=(.*)$/i', $part, $m)) $names[] = $m[1];
    if (preg_match('/^\s*host\s*=(.*)$/i', $part, $m)) $hosts[] = $m[1];
    if (preg_match('/^\s*(unix_socket|port)\s*=/i', $part)) {
        if (stripos(trim($part), 'unix_socket') === 0) throw new RuntimeException('Socket DSNs are not allowed');
    }
}
if (count($names) !== 1 || !preg_match('/\Achaoxi_wallet_test_[A-Za-z0-9_]+\z/D', $names[0])) throw new RuntimeException('Explicit unique isolated dbname required');
if (count($hosts) !== 1 || !in_array($hosts[0], array('db', 'localhost', '127.0.0.1', '::1'), true)) throw new RuntimeException('Local test database host required');
date_default_timezone_set('UTC');
set_error_handler(function($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
spl_autoload_register(function($class) use ($root) {
    foreach (array('/app/Core/', '/app/Services/') as $directory) {
        $path = $root.$directory.$class.'.php';
        if (is_file($path)) { require_once $path; return; }
    }
});
// Fail closed if invoked against the old executable-on-include worker.
$worker = $root.'/scripts/wecom_notification_worker.php';
$source = file_get_contents($worker);
prw_ok(strpos($source, 'function notification_worker_run(') !== false && strpos($source, 'function notification_worker_password_reset_mode(') !== false, 'include-safe worker interface present');
$argvBefore = $argv; $rootBefore = $root; $cwdBefore = getcwd(); $includedBefore = get_included_files();
ob_start();
try {
    // A different script filename must keep the worker direct-execution guard closed.
    (function($path) { require $path; })($worker);
} finally { $includeOutput = ob_get_clean(); }
$newIncludes = array_diff(get_included_files(), $includedBefore);
prw_ok($includeOutput === '' && $argv === $argvBefore && $root === $rootBefore && getcwd() === $cwdBefore, 'include has no output or argv/root/cwd mutation');
$forbidden = array('/config/config.php', '/app/Core/Bootstrap.php', '/app/Core/Database.php');
$cleanIncludes = true;
foreach ($newIncludes as $path) foreach ($forbidden as $suffix) if (substr(str_replace('\\', '/', $path), -strlen($suffix)) === $suffix) $cleanIncludes = false;
prw_ok($cleanIncludes, 'include does not load site configuration/bootstrap/database');
prw_ok(notification_worker_password_reset_mode(array()) === false, 'empty arguments preserve default mode');
prw_ok(notification_worker_password_reset_mode(array('--password-reset-only')) === true, 'explicit password-reset-only mode');
foreach (array(array('--unknown'), array('--password-reset-only', '--unknown'), array('--password-reset-only', '--password-reset-only')) as $args) {
    $rejected = false;
    try { notification_worker_password_reset_mode($args); } catch (InvalidArgumentException $e) { $rejected = true; }
    prw_ok($rejected, 'reject unsupported arguments '.implode(' ', $args));
}
$options = array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false);
$pdo = new PDO($dsn, getenv('CHAOXI_TEST_USER'), getenv('CHAOXI_TEST_PASSWORD'), $options);
prw_ok($pdo->query('SELECT DATABASE()')->fetchColumn() === $names[0], 'actual database matches explicit isolated name');
prw_ok((int)$pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn() === 0, 'dedicated database is empty before migrations');
require $root.'/scripts/migrate.php';
run_migrations($pdo, $root);
$pdo->exec("SET time_zone='+00:00'");
// Freeze this session's SQL NOW(): exact expiry equality is deterministic, not a timing race.
$clock = (int)$pdo->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();
$pdo->exec('SET timestamp='.$clock);
class PasswordResetEmailStub {
    public $calls = array(); public $result = true; public $error = null;
    public function send($to, $subject, $body, $html = null) {
        if (!is_string($to) || !preg_match('/@example\.test\z/iD', $to)) throw new LogicException('Non-test recipient');
        // 同时记录可选内容和实际实参数量，区分三参调用与显式传入空第四参。
        $this->calls[] = array($to, $subject, $body, $html, func_num_args());
        if ($this->error !== null) throw new RuntimeException($this->error);
        return $this->result;
    }
}
class PasswordResetWecomStub {
    public $calls = array();
    public function sendPayload($payload) { $this->calls[] = $payload; return true; }
}
function prw_row($pdo, $id) {
    $s = $pdo->prepare('SELECT * FROM notification_outbox WHERE id=?'); $s->execute(array($id)); return $s->fetch();
}
function prw_enqueue($pdo, $channel, $event, $key, $to, $status = 'pending') {
    $payload = array('to' => $to, 'subject' => 'Isolated reset fixture', 'body' => 'https://example.test/reset?token=fixture', 'msgtype' => 'text', 'text' => array('content' => 'Isolated fixture'));
    $s = $pdo->prepare('INSERT INTO notification_outbox(channel,event_type,dedupe_key,payload_json,status,retry_count,available_at,locked_at,last_error,created_at,updated_at) VALUES(?,?,?,?,?,2,DATE_SUB(NOW(),INTERVAL 1 HOUR),IF(?="processing",DATE_SUB(NOW(),INTERVAL 11 MINUTE),NULL),"fixture marker",NOW(),DATE_SUB(NOW(),INTERVAL 1 HOUR))');
    $s->execute(array($channel, $event, $key, json_encode($payload), $status, $status));
    return $pdo->lastInsertId();
}
function prw_token($pdo, $uid, $expiry = 'DATE_ADD(NOW(),INTERVAL 1 HOUR)', $used = 'NULL') {
    $hash = hash('sha256', random_bytes(32));
    $s = $pdo->prepare('INSERT INTO password_reset_tokens(user_id,token_hash,expires_at,used_at,created_at) VALUES(?,?,'.$expiry.','.$used.',NOW())');
    $s->execute(array($uid, $hash)); return $hash;
}
function prw_reset($pdo, $uid, $to, $status = 'pending') {
    return prw_enqueue($pdo, 'email', 'password_reset', 'password_reset:'.prw_token($pdo, $uid), $to, $status);
}
$email = new PasswordResetEmailStub(); $wecom = new PasswordResetWecomStub(); $config = array();
$address = 'reset-fixture@example.test';
$pdo->prepare('INSERT INTO users(email,password_hash,status,created_at,updated_at) VALUES(?,"unused-test-hash","active",NOW(),NOW())')->execute(array($address));
$uid = $pdo->lastInsertId();
$pdo->prepare('INSERT INTO users(email,password_hash,status,created_at,updated_at) VALUES(?,"unused-test-hash","disabled",NOW(),NOW())')->execute(array('inactive@example.test'));
$inactive = $pdo->lastInsertId();
// Insert 24 older unrelated rows: an unfiltered LIMIT 20 must not starve resets.
$untouched = array();
foreach (array('pending', 'retry', 'processing') as $status) {
    foreach (array(array('wecom', 'order_paid'), array('wecom', 'password_reset'), array('email', 'order_paid'), array('email', 'ticket_reply')) as $type) {
        for ($i = 0; $i < 2; $i++) {
            $id = prw_enqueue($pdo, $type[0], $type[1], 'unrelated:'.bin2hex(random_bytes(10)), $address, $status);
            $untouched[$id] = prw_row($pdo, $id);
        }
    }
}
$valid = prw_reset($pdo, $uid, strtoupper($address));
$stale = prw_reset($pdo, $uid, $address, 'processing');
$outbox = new NotificationOutboxService($pdo);
$claimed = $outbox->claim(20, true);
prw_ok(count($claimed) === 2 && array_map('strval', array_column($claimed, 'id')) === array((string)$valid, (string)$stale), 'claim20 filters candidates and recovers only stale reset despite 24 older unrelated rows');
foreach ($untouched as $id => $before) prw_ok(prw_row($pdo, $id) === $before, 'filtered claim preserves every unrelated field #'.$id);
prw_ok($outbox->claim(20, true) === array(), 'fresh processing reset cannot be claimed twice');
// Exercise worker recovery of both real claims, not fabricated SELECT results.
$pdo->exec('UPDATE notification_outbox SET locked_at=DATE_SUB(NOW(),INTERVAL 11 MINUTE) WHERE id IN ('.(int)$valid.','.(int)$stale.')');
notification_worker_run($pdo, $config, true, $email, $wecom);
prw_ok(count($email->calls) === 2 && count($wecom->calls) === 0 && $email->calls[0][0] === strtoupper($address), 'valid case-insensitive recipient and reclaimed reset each send once');
prw_ok(prw_row($pdo, $valid)['status'] === 'sent' && prw_row($pdo, $stale)['status'] === 'sent', 'valid resets marked sent');
notification_worker_run($pdo, $config, true, $email, $wecom);
prw_ok(count($email->calls) === 2, 'repeat worker run never resends sent resets');
foreach ($untouched as $id => $before) prw_ok(prw_row($pdo, $id) === $before, 'reset worker preserves all unrelated fields #'.$id);
// Nine invalid rows fit in a single claim20 batch; each gets an individual assertion.
$bad = array();
$bad['expired'] = prw_enqueue($pdo, 'email', 'password_reset', 'password_reset:'.prw_token($pdo, $uid, 'DATE_SUB(NOW(),INTERVAL 1 SECOND)'), $address);
$bad['used'] = prw_enqueue($pdo, 'email', 'password_reset', 'password_reset:'.prw_token($pdo, $uid, 'DATE_ADD(NOW(),INTERVAL 1 HOUR)', 'NOW()'), $address);
$bad['exact-now'] = prw_enqueue($pdo, 'email', 'password_reset', 'password_reset:'.prw_token($pdo, $uid, 'NOW()'), $address);
$bad['missing-token'] = prw_enqueue($pdo, 'email', 'password_reset', 'password_reset:'.hash('sha256', 'missing-token'), $address);
$bad['invalid-dedupe'] = prw_enqueue($pdo, 'email', 'password_reset', 'password_reset:'.str_repeat('G', 64), $address);
$bad['uppercase-hex'] = prw_enqueue($pdo, 'email', 'password_reset', 'password_reset:'.strtoupper(prw_token($pdo, $uid)), $address);
$bad['mismatch'] = prw_reset($pdo, $uid, 'other@example.test');
$bad['inactive'] = prw_reset($pdo, $inactive, 'inactive@example.test');
$bad['non-string-recipient'] = prw_reset($pdo, $uid, array($address));
notification_worker_run($pdo, $config, true, $email, $wecom);
foreach ($bad as $name => $id) {
    $row = prw_row($pdo, $id);
    prw_ok($row['status'] === 'failed' && (int)$row['retry_count'] === 2 && $row['locked_at'] === null && $row['sent_at'] === null, $name.' permanently failed without retry');
}
prw_ok(count($email->calls) === 2 && count($wecom->calls) === 0, 'all invalid resets send nothing');
foreach (array('网络连接失败', 'TLS 握手失败') as $error) {
    $id = prw_reset($pdo, $uid, $address); $email->error = $error;
    notification_worker_run($pdo, $config, true, $email, $wecom);
    $row = prw_row($pdo, $id);
    prw_ok($row['status'] === 'retry' && (int)$row['retry_count'] === 3 && $row['locked_at'] === null && $row['sent_at'] === null && $row['last_error'] === 'notification_transport_retryable' && strtotime($row['available_at'].' UTC') === $clock + 900, $error.' retains original retry schedule');
}
$email->error = null; $email->result = false;
$id = prw_reset($pdo, $uid, $address);
notification_worker_run($pdo, $config, true, $email, $wecom);
$row = prw_row($pdo, $id);
prw_ok($row['status'] === 'failed' && (int)$row['retry_count'] === 2 && $row['sent_at'] === null, 'SMTP disabled false is permanent failure, not retry');
$email->result = true;
// Actual PDO connection, actual claims; inject only a validation prepare failure.
class PasswordResetFaultPDO extends PDO {
    #[\ReturnTypeWillChange]
    public function prepare($query, $options = array()) {
        if (stripos($query, 'password_reset_tokens') !== false) throw new PDOException('isolated validation database fault');
        return parent::prepare($query, $options);
    }
}
$id = prw_reset($pdo, $uid, $address); $callsBeforeFault = count($email->calls);
$faultPdo = new PasswordResetFaultPDO($dsn, getenv('CHAOXI_TEST_USER'), getenv('CHAOXI_TEST_PASSWORD'), $options);
prw_ok($faultPdo->query('SELECT DATABASE()')->fetchColumn() === $names[0], 'fault connection targets same isolated database');
$thrown = false;
try { notification_worker_run($faultPdo, $config, true, $email, $wecom); }
catch (PDOException $e) { if ($e->getMessage() !== 'isolated validation database fault') throw $e; $thrown = true; }
$row = prw_row($pdo, $id);
prw_ok($thrown && $row['status'] === 'processing' && $row['locked_at'] !== null && (int)$row['retry_count'] === 2 && count($email->calls) === $callsBeforeFault, 'database validation failure propagates and retains recoverable processing without send');
$pdo->prepare('UPDATE notification_outbox SET locked_at=DATE_SUB(NOW(),INTERVAL 11 MINUTE) WHERE id=?')->execute(array($id));
notification_worker_run($pdo, $config, true, $email, $wecom);
prw_ok(prw_row($pdo, $id)['status'] === 'sent' && count($email->calls) === $callsBeforeFault + 1, 'database fault processing recovers on next healthy stale claim');
// Default mode still claims and sends unrelated email/wecom through stubs.
$emailBefore = count($email->calls); $wecomBefore = count($wecom->calls);
notification_worker_run($pdo, $config, false, $email, $wecom);
notification_worker_run($pdo, $config, false, $email, $wecom);
$allSent = true;
foreach ($untouched as $id => $before) if (prw_row($pdo, $id)['status'] !== 'sent') $allSent = false;
prw_ok($allSent && count($email->calls) === $emailBefore + 12 && count($wecom->calls) === $wecomBefore + 12, 'default mode sends all 24 unrelated rows including stale processing across claim20 batches');
// 将 HTML 回归批次放在原有全部断言之后，避免影响原有调用计数。
$htmlBefore = count($email->calls); $htmlWecomBefore = count($wecom->calls);
$htmlFixture = '<!doctype html><html><body><a href="https://example.test/reset?token=testtoken&amp;source=email">重置密码</a></body></html>';
$htmlCases = array(
    'html' => array('html' => $htmlFixture),
    'legacy' => array(),
    'empty' => array('html' => ''),
    'array' => array('html' => array('unexpected' => $htmlFixture)),
    'expired-html' => array('html' => $htmlFixture)
);
$htmlIds = array(); $htmlPayloads = array();
foreach ($htmlCases as $name => $extra) {
    // 每封重置邮件均关联真实隔离测试令牌；过期样本只改变有效期。
    $expiry = $name === 'expired-html' ? 'DATE_SUB(NOW(),INTERVAL 1 SECOND)' : 'DATE_ADD(NOW(),INTERVAL 30 MINUTE)';
    $id = prw_enqueue($pdo, 'email', 'password_reset', 'password_reset:'.prw_token($pdo, $uid, $expiry), $address);
    $payload = array_merge(array('to' => $address, 'subject' => 'HTML reset '.$name, 'body' => "重置密码\nhttps://example.test/reset?token=testtoken\n链接自申请起 30 分钟内有效。"), $extra);
    $pdo->prepare('UPDATE notification_outbox SET payload_json=? WHERE id=?')->execute(array(json_encode($payload), $id));
    $htmlIds[$name] = $id; $htmlPayloads[$name] = $payload;
}
notification_worker_run($pdo, $config, true, $email, $wecom);
prw_ok(count($email->calls) === $htmlBefore + 4 && count($wecom->calls) === $htmlWecomBefore, 'HTML batch sends only four valid resets and no wecom');
foreach (array('html', 'legacy', 'empty', 'array') as $offset => $name) {
    $call = $email->calls[$htmlBefore + $offset]; $payload = $htmlPayloads[$name];
    prw_ok(array_slice($call, 0, 3) === array($payload['to'], $payload['subject'], $payload['body']), $name.' reset preserves recipient subject and plain body');
    prw_ok($call[4] === ($name === 'html' ? 4 : 3) && $call[3] === ($name === 'html' ? $htmlFixture : null), $name.' reset preserves HTML only for nonempty string and uses exact send arity');
    prw_ok(prw_row($pdo, $htmlIds[$name])['status'] === 'sent', $name.' reset marked sent');
}
$expiredHtml = prw_row($pdo, $htmlIds['expired-html']);
prw_ok($expiredHtml['status'] === 'failed' && $expiredHtml['sent_at'] === null && (int)$expiredHtml['retry_count'] === 2 && $expiredHtml['last_error'] === 'password_reset_invalid_or_expired', 'expired HTML reset fails token validation without send or retry');
prw_ok($email->calls[0][4] === 3 && $email->calls[0][3] === null, 'original legacy reset used exactly three send arguments');

// 默认模式中的非重置事件即使携带 HTML，也只能维持旧三参发送。
$defaultHtmlBefore = count($email->calls); $defaultHtmlIds = array();
foreach (array('order_paid', 'ticket_reply') as $event) {
    $id = prw_enqueue($pdo, 'email', $event, 'html-nonreset:'.bin2hex(random_bytes(10)), $address);
    $payload = array('to' => $address, 'subject' => 'Non-reset '.$event, 'body' => 'Plain '.$event, 'html' => $htmlFixture);
    $pdo->prepare('UPDATE notification_outbox SET payload_json=? WHERE id=?')->execute(array(json_encode($payload), $id));
    $defaultHtmlIds[$event] = $id;
}
notification_worker_run($pdo, $config, false, $email, $wecom);
prw_ok(count($email->calls) === $defaultHtmlBefore + 2 && count($wecom->calls) === $htmlWecomBefore, 'default HTML batch sends exactly two unrelated emails');
$offset = 0;
foreach ($defaultHtmlIds as $event => $id) {
    $call = $email->calls[$defaultHtmlBefore + $offset++];
    prw_ok($call === array($address, 'Non-reset '.$event, 'Plain '.$event, null, 3) && prw_row($pdo, $id)['status'] === 'sent', $event.' ignores payload HTML and sends exactly three unchanged arguments');
}
$pdo->exec('SET timestamp=0');
echo 'PASSWORD RESET WORKER COMPLETE ('.$prwAssertions.' assertions)'.PHP_EOL;
