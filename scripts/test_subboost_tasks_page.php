<?php
// 仅 CLI 隔离测试：服务替身、导航计数替身；不加载站点配置、真实库或业务 worker。
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
set_error_handler(function($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
$root = dirname(__DIR__);
require $root.'/app/Core/Router.php';
require $root.'/app/Core/Security.php';
require $root.'/app/Core/AdminView.php';
require $root.'/app/Core/AdminExtraPages.php';
require $root.'/app/Core/AdminLogPages.php';
require $root.'/app/Services/AdminLogService.php';
require $root.'/app/Core/AdminSubBoostPages.php';
function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function flash($type, $message) { $_SESSION['_flash'][$type] = $message; }
class SubBoostPageRedirect extends RuntimeException {}
function redirect($url) { throw new SubBoostPageRedirect($url); }
class SubBoostPageCount {
    public function fetchColumn() { return 0; }
}
class SubBoostPagePDO {
    public function query($sql) {
        if (!in_array($sql, array('SELECT COUNT(*) FROM orders WHERE status="pending_delivery"', 'SELECT COUNT(*) FROM orders WHERE order_kind="renewal" AND status="pending_delivery" AND paid_at IS NOT NULL', 'SELECT COUNT(*) FROM support_tickets WHERE status="open"'), true)) throw new LogicException('Unexpected query');
        return new SubBoostPageCount();
    }
}
function page($title, $body, $config) {
    admin_page($title, $body, '', $_SERVER['REQUEST_URI'], $GLOBALS['sbPDO'], $config);
}
// 使用约定的安全平铺返回结构；额外注入秘密字段验证 UI 白名单，不模拟任何外部调用。
class SubBoostTaskService {
    public static $calls = array();
    public static $rows = array();
    public static $history = array();
    public static $transportCalls = array();
    public static $transportLabel = null;
    public static function transportReason($errno) {
        self::$transportCalls[] = $errno;
        if (self::$transportLabel !== null) return self::$transportLabel;
        if ($errno === null) return '未记录';
        $labels = array(0=>'未报告 cURL 错误',28=>'连接或请求超时',60=>'TLS 证书校验失败');
        return isset($labels[$errno]) ? $labels[$errno] : '其他传输错误（仅保留编号）';
    }
    public function __construct($pdo, $config) {
        if (!($pdo instanceof SubBoostPagePDO) || $config['app']['timezone'] !== 'Asia/Shanghai') throw new LogicException('Not isolated');
    }
    public static function statuses() { return array('pending'=>'待处理','waiting'=>'等待上游配置','retry'=>'等待重试','completed'=>'已完成'); }
    public static function errors() { return array(''=>'无错误','unknown_error'=>'未知错误','order_ineligible'=>'订单暂不自动交付'); }
    public static function stages() { return array('create'=>'创建上游订阅','unknown'=>'未记录'); }
    private static function invalid() { throw new InvalidArgumentException('PRIVATE_INVALID_RAW <script>secret</script>'); }
    public function listing($input) {
        self::$calls[] = array('listing', $input);
        $f = array('status'=>'','error'=>'','order_no'=>'','id'=>'','from'=>'','to'=>'','page'=>1);
        foreach ($f as $key=>$default) {
            if (isset($input[$key])) {
                if (!is_string($input[$key])) self::invalid();
                $f[$key] = $input[$key];
            }
        }
        foreach (array('status'=>self::statuses(), 'error'=>self::errors()) as $key=>$options) if ($f[$key] !== '' && !isset($options[$f[$key]])) self::invalid();
        foreach (array('id','page') as $key) if ($f[$key] !== '' && (!preg_match('/\A[1-9][0-9]{0,8}\z/D', (string)$f[$key]))) self::invalid();
        if (strlen($f['order_no']) > 40) self::invalid();
        foreach (array('from','to') as $key) {
            if ($f[$key] === '') continue;
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $f[$key]);
            if (!$date || $date->format('Y-m-d') !== $f[$key]) self::invalid();
        }
        if ($f['from'] !== '' && $f['to'] !== '' && $f['from'] > $f['to']) self::invalid();
        $items = array_values(array_filter(self::$rows, function($row) use ($f) {
            foreach (array('id'=>'id','order_no'=>'order_no','status'=>'status','error'=>'error_code') as $key=>$field) if ($f[$key] !== '' && (string)$row[$field] !== (string)$f[$key]) return false;
            $day = substr($row['created_at'], 0, 10);
            return ($f['from'] === '' || $day >= $f['from']) && ($f['to'] === '' || $day <= $f['to']);
        }));
        $total = count($items); $pages = max(1, (int)ceil($total / 50)); $page = min($pages, (int)$f['page']);
        return array('filters'=>$f, 'items'=>array_slice($items, ($page-1)*50, 50), 'total'=>$total, 'pages'=>$pages, 'page'=>$page);
    }
    public function detail($id, $page = 1) {
        self::$calls[] = array('detail', $id, $page);
        foreach (self::$rows as $task) if ((int)$task['id'] === (int)$id) {
            $history = (int)$id === 1 ? self::$history : array();
            $pages = max(1, (int)ceil(count($history)/50)); $page = min($page, $pages);
            return array('task'=>$task, 'history'=>array_slice($history, ($page-1)*50, 50), 'history_total'=>count($history), 'history_page'=>$page, 'history_pages'=>$pages);
        }
        return null;
    }
}
$sbPDO = new SubBoostPagePDO();
$config = array('app'=>array('name'=>'隔离测试','timezone'=>'Asia/Shanghai'));
$router = new Router();
register_admin_subboost_routes($router, $sbPDO, $config);
$_SESSION = array('admin_id'=>1, 'admin_name'=>'测试管理员');
$xss = '<img src=x onerror=alert(1)>';
$private = array('request_json'=>'PRIVATE_REQUEST','sourcehash'=>'PRIVATE_HASH','subscription_id'=>'PRIVATE_SUBSCRIPTION','key'=>'PRIVATE_KEY','secret'=>'PRIVATE_SECRET','response_body'=>'PRIVATE_RESPONSE_BODY');
for ($i=1; $i<=105; $i++) {
    SubBoostTaskService::$rows[] = array_merge(array('id'=>$i,'order_id'=>$i,'order_no'=>$i === 1 ? 'ORD-EXACT' : 'ORD-EXACT-EXTRA','order_status'=>'pending_delivery','order_status_label'=>'待发货','status'=>$i === 1 ? 'retry' : 'waiting','status_label'=>$i === 1 ? '等待重试' : '等待上游配置','retry_count'=>3,'error_code'=>$i === 1 ? 'unknown_error' : 'order_ineligible','error_text'=>$xss,'suggestion'=>$xss,'available_at'=>null,'created_at'=>$i === 1 ? '2026-11-09 23:59:59' : '2026-11-10 00:00:00','updated_at'=>'2026-11-10 08:00:00','completed_at'=>null,'opened_at'=>null,'expires_at'=>null), $private);
}
for ($i=1; $i<=51; $i++) SubBoostTaskService::$history[] = array_merge(array('id'=>$i,'result'=>'failed','result_label'=>$xss,'stage'=>'create','stage_label'=>$xss,'error_code'=>'unknown_error','error_text'=>$xss,'suggestion'=>$xss,'http_status'=>503,'transport_errno'=>0,'duration_ms'=>321,'started_at'=>'2026-11-10 07:59:59','finished_at'=>null), $private);
$assertions = 0;
function sb_ok($condition, $name) {
    if (!$condition) throw new RuntimeException('FAIL '.$name);
    $GLOBALS['assertions']++;
}
function sb_request($router, $path, $query = array(), $method = 'GET') {
    $_GET = $query; $_SERVER['REQUEST_URI'] = $path; http_response_code(200);
    ob_start();
    try { $router->dispatch($method, $path); $body = ob_get_clean(); }
    catch (Throwable $exception) { ob_end_clean(); throw $exception; }
    return array(http_response_code(), $body);
}
function sb_clean($html) {
    foreach (array('request_json','sourcehash','subscription_id','PRIVATE_REQUEST','PRIVATE_HASH','PRIVATE_SUBSCRIPTION','PRIVATE_KEY','PRIVATE_SECRET','PRIVATE_RESPONSE_BODY','response_body','name="key"','name="secret"') as $needle) sb_ok(strpos($html, $needle) === false, 'secret field or value omitted '.$needle);
    sb_ok(strpos($html, 'action="/admin/subboost-tasks') === false || strpos($html, 'method="get" action="/admin/subboost-tasks"') !== false, 'only GET task query form');
    sb_ok(!preg_match('/<form[^>]*method="post"[^>]*action="\/admin\/subboost/i', $html), 'no task POST form');
}
// 浏览器通过请求拦截取得夹具 HTML，仅静态资源访问本地 8081，不接触真实会话。
if (isset($argv[1]) && $argv[1] === '--html') {
    $url = isset($argv[2]) ? $argv[2] : '/admin/logs';
    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path) || !preg_match('#\A/admin/(?:logs|subboost-tasks(?:/[1-9][0-9]*)?)\z#D', $path)) throw new InvalidArgumentException('Invalid fixture path');
    $query = array();
    parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
    $router->get('/admin/logs', function() use ($config) {
        Security::requireAdmin();
        page('系统日志', admin_log_body(array('filters'=>array(), 'items'=>array(), 'total'=>0, 'pages'=>1, 'page'=>1)), $config);
    });
    list($status, $html) = sb_request($router, $path, $query);
    if ($status !== 200) throw new RuntimeException('Fixture request failed');
    echo $html;
    exit;
}
list($status, $html) = sb_request($router, '/admin/subboost-tasks');
sb_ok($status === 200 && strpos($html, '共 105 条 · 每页 50 条') !== false, 'real list route and 50-row pagination');
sb_ok(substr_count($html, '<td><a href="/admin/subboost-tasks/') === 100, 'exactly 50 task rows with ID and reason links');
sb_ok(strpos($html, 'class="admin-nav-item active" href="/admin/subboost-tasks" aria-current="page"') !== false, 'normal sidebar active');
sb_ok(strpos($html, 'href="/admin/orders/ORD-EXACT"') !== false && strpos($html, '&lt;img src=x onerror=alert(1)&gt;') !== false && strpos($html, $xss) === false, 'order link and escaped safe fields');
sb_ok(strpos($html, 'waiting 表示等待上游配置，不是失败') !== false && strpos($html, '不要强行修改订单状态') !== false, 'waiting and order_ineligible guidance');
sb_clean($html);
$filter = array('id'=>'1','order_no'=>'ORD-EXACT','status'=>'retry','error'=>'unknown_error','from'=>'2026-11-09','to'=>'2026-11-09');
list($status, $html) = sb_request($router, '/admin/subboost-tasks', $filter);
sb_ok($status === 200 && strpos($html, '共 1 条') !== false && strpos($html, '2026-11-09 23:59:59') !== false && strpos($html, 'ORD-EXACT-EXTRA') === false, 'exact ID/order/status/error and inclusive date filters');
sb_ok(end(SubBoostTaskService::$calls) === array('listing', $filter), 'passes query unchanged to contracted service');
list($status, $html) = sb_request($router, '/admin/subboost-tasks', array('status'=>'waiting','page'=>'2'));
sb_ok(strpos($html, 'status=waiting&amp;') !== false && strpos($html, 'page=3') !== false, 'pagination preserves filters');
list($status, $html) = sb_request($router, '/admin/subboost-tasks', array('id'=>'999'));
sb_ok($status === 200 && strpos($html, '暂无符合当前条件的任务') !== false, 'empty results');
list($status, $html) = sb_request($router, '/admin/subboost-tasks', array('order_no'=>$xss));
sb_ok(strpos($html, 'value="'.e($xss).'"') !== false && strpos($html, $xss) === false, 'filter input escaped');
foreach (array(array('id'=>'0'),array('id'=>array('1')),array('status'=>'PRIVATE_INVALID_RAW'),array('error'=>'PRIVATE_INVALID_RAW'),array('from'=>'2026-02-30'),array('from'=>'2026-11-10','to'=>'2026-11-09'),array('page'=>'-1'),array('order_no'=>str_repeat('X',41))) as $bad) {
    $before = count(SubBoostTaskService::$calls);
    list($status, $html) = sb_request($router, '/admin/subboost-tasks', $bad);
    sb_ok($status === 400 && strpos($html, '筛选条件无效') !== false && strpos($html, '未查询任何记录') !== false, 'invalid list fails closed');
    sb_ok(count(SubBoostTaskService::$calls) === $before + 1 && strpos($html, 'PRIVATE_INVALID_RAW') === false, 'invalid list no fallback query or exception reflection');
    sb_clean($html);
}
list($status, $html) = sb_request($router, '/admin/subboost-tasks/1');
sb_ok($status === 200 && strpos($html, '共 51 条已记录历史') !== false && strpos($html, 'history_page=2') !== false, 'detail history and pagination');
foreach (array('执行阶段','HTTP 状态','传输错误号','耗时（毫秒）','321','503','安全排查建议','未记录','重试次数不是完整尝试次数') as $needle) sb_ok(strpos($html, $needle) !== false, 'detail shows '.$needle);
sb_ok(strpos($html, $xss) === false && strpos($html, e($xss)) !== false, 'history values escaped');
sb_clean($html);
list($status, $html) = sb_request($router, '/admin/subboost-tasks/1', array('history_page'=>'2'));
sb_ok(end(SubBoostTaskService::$calls) === array('detail',1,2) && strpos($html, '第 2 / 2 页') !== false, 'detail page passed to service');
// 每次只保留一条历史，核对服务翻译、编号及缺失值，避免被其他行干扰。
$savedHistory = SubBoostTaskService::$history;
SubBoostTaskService::$history = array($savedHistory[0]);
foreach (array(array(0,'0 · 未报告 cURL 错误'),array(28,'28 · 连接或请求超时'),array(60,'60 · TLS 证书校验失败'),array(9999,'9999 · 其他传输错误（仅保留编号）'),array(null,'未记录')) as $case) {
    SubBoostTaskService::$history[0]['transport_errno'] = $case[0];
    SubBoostTaskService::$transportCalls = array();
    list($status, $html) = sb_request($router, '/admin/subboost-tasks/1');
    sb_ok($status === 200 && strpos($html, '<td>'.e($case[1]).'</td>') !== false, 'transport number and safe Chinese explanation');
    sb_ok(SubBoostTaskService::$transportCalls === array($case[0]), 'transport explanation delegated to service with nullable errno');
    if ($case[0] === null) sb_ok(strpos($html, '0 · 未报告 cURL 错误') === false, 'unrecorded errno not fabricated as zero');
    sb_clean($html);
}
unset(SubBoostTaskService::$history[0]['transport_errno']);
SubBoostTaskService::$transportCalls = array();
list($status, $html) = sb_request($router, '/admin/subboost-tasks/1');
sb_ok(SubBoostTaskService::$transportCalls === array(null) && strpos($html, '0 · 未报告 cURL 错误') === false, 'missing errno stays unrecorded');
SubBoostTaskService::$transportLabel = $xss;
SubBoostTaskService::$history[0]['transport_errno'] = 28;
list($status, $html) = sb_request($router, '/admin/subboost-tasks/1');
sb_ok(strpos($html, '<td>28 · '.e($xss).'</td>') !== false && strpos($html, $xss) === false, 'service transport label escaped');
SubBoostTaskService::$transportLabel = null;
SubBoostTaskService::$history = $savedHistory;
list($status, $html) = sb_request($router, '/admin/subboost-tasks/2');
sb_ok(strpos($html, '暂无已记录的执行历史。启用诊断前的逐次执行历史无法追溯') !== false, 'legacy history explicitly unavailable');
list($status, $html) = sb_request($router, '/admin/subboost-tasks/999');
sb_ok($status === 404 && strpos($html, '未找到该任务') !== false, 'missing detail 404');
foreach (array(array('/admin/subboost-tasks/0',array()),array('/admin/subboost-tasks/<script>PRIVATE_INVALID_RAW',array()),array('/admin/subboost-tasks/999999999999999999999999',array()),array('/admin/subboost-tasks/1',array('history_page'=>'0')),array('/admin/subboost-tasks/1',array('history_page'=>array('1')))) as $bad) {
    $before = count(SubBoostTaskService::$calls);
    list($status, $html) = sb_request($router, $bad[0], $bad[1]);
    sb_ok($status === 400 && count(SubBoostTaskService::$calls) === $before && strpos($html, 'PRIVATE_INVALID_RAW') === false, 'invalid detail input rejected before service');
}
// 真实 requireAdmin，redirect 替身只截获跳转，确认服务未被调用。
$_SESSION = array();
foreach (array('/admin/subboost-tasks','/admin/subboost-tasks/1') as $path) {
    $before = count(SubBoostTaskService::$calls); $target = '';
    try { sb_request($router, $path); } catch (SubBoostPageRedirect $exception) { $target = $exception->getMessage(); }
    sb_ok($target === '/admin/login' && count(SubBoostTaskService::$calls) === $before, 'guest redirected before query');
}
$_SESSION = array('admin_id'=>1);
foreach (array('/admin/subboost-tasks','/admin/subboost-tasks/1','/admin/subboost-tasks/1/retry') as $path) {
    $before = count(SubBoostTaskService::$calls);
    list($status) = sb_request($router, $path, array(), 'POST');
    sb_ok($status === 404 && count(SubBoostTaskService::$calls) === $before, 'no POST or retry route');
}
// 日志默认分类直达任务列表，不增加或改变现有 tab。
$html = admin_log_body(array('filters'=>array(), 'items'=>array(), 'total'=>0, 'pages'=>1, 'page'=>1));
sb_ok(strpos($html, '<a href="/admin/subboost-tasks">SubBoost任务</a>') !== false, 'normal logs navigation link');
$index = file_get_contents($root.'/public/index.php');
sb_ok(strpos($index, "require dirname(__DIR__) . '/app/Core/AdminSubBoostPages.php';") !== false && strpos($index, 'register_admin_subboost_routes($router, $pdo, $config);') !== false, 'application entry requires and registers routes');
echo 'PASS '.$assertions.' isolated SubBoost task UI assertions (service stub; no database or external calls).'.PHP_EOL;
