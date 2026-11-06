<?php
// 只读页面回归：只创建 SQLite :memory:，不加载启动器、凭据或业务库，不调用资金方法。
// --html 仅在全部断言通过后输出首次五条佣金 fixture 的真实共享壳，后续测试照常执行。
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require $root.'/app/Core/Router.php';
require $root.'/app/Core/Security.php';
require $root.'/app/Services/ActivityService.php';
require $root.'/app/Services/WalletService.php';
function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function flash($type, $text) {}
function page($title, $body, $config) { $GLOBALS['referral_body'] = $body; $GLOBALS['referral_title'] = $title; }
function redirect($url) { throw new RuntimeException('REDIRECT '.$url); }
$tests = 0;
$htmlMode = in_array('--html', $_SERVER['argv'], true);
function referral_ok($ok, $label) {
    if (!$ok) throw new RuntimeException('FAIL '.$label);
    $GLOBALS['tests']++;
    if (!$GLOBALS['htmlMode']) echo 'PASS '.$label.PHP_EOL;
}
// 不执行 index.php 顶层启动代码；token 提取具名函数，字符串中的括号不计深度。
function referral_function($source, $name) {
    $tokens = token_get_all($source);
    $capture = false; $opened = false; $depth = 0; $result = '';
    foreach ($tokens as $i => $token) {
        if (!$capture && is_array($token) && $token[0] === T_FUNCTION) {
            $j = $i + 1;
            while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
            if (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING && $tokens[$j][1] === $name) $capture = true;
        }
        if (!$capture) continue;
        $result .= is_array($token) ? $token[1] : $token;
        if ($token === '{' || (is_array($token) && in_array($token[0], array(T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES), true))) { $opened = true; $depth++; }
        elseif ($token === '}' && --$depth === 0 && $opened) return $result;
    }
    throw new RuntimeException('函数缺失 '.$name);
}
function referral_xpath($html) {
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    return new DOMXPath($doc);
}
function referral_numbers($html) {
    $result = array();
    foreach (referral_xpath($html)->query('//tr[@class="ri-record"]/td[@class="ri-number"]') as $node) $result[] = $node->textContent;
    return $result;
}
function referral_stats($html) {
    $result = array();
    foreach (referral_xpath($html)->query('//section[@class="ri-stats"]/div/strong') as $node) $result[] = trim($node->textContent);
    return $result;
}
function referral_run($get = array()) {
    global $pdo, $router;
    $_GET = array_merge(array('view'=>'referral'), $get);
    $_SERVER['REQUEST_URI'] = '/events?'.http_build_query($_GET);
    http_response_code(200);
    $GLOBALS['referral_body'] = '';
    $before = $pdo->query('SELECT total_changes()')->fetchColumn();
    // 即使页面意外新增写入，也只能失败，不会更改内存 fixture。
    $pdo->exec('PRAGMA query_only=ON');
    try { $router->dispatch('GET', '/events'); }
    finally { $pdo->exec('PRAGMA query_only=OFF'); }
    if ($before != $pdo->query('SELECT total_changes()')->fetchColumn()) throw new RuntimeException('FAIL GET changed database');
    return $GLOBALS['referral_body'];
}
function referral_add($id, $no, $status='pending_credit', $mode='wallet', $recovery='none', $days=1, $owner=1, $amount=1000, $createdDays=null) {
    global $pdo;
    $stmt = $pdo->prepare('INSERT INTO referral_commissions(id,commission_no,referrer_user_id,referred_user_id,order_id,amount_cents,status,settlement_mode,recovery_status,created_at,updated_at,idempotency_key) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute(array($id,$no,$owner,2+$id%2,'PRIVATE-ORDER-'.$id,$amount,$status,$mode,$recovery,date('Y-m-d H:i:s',time()-($createdDays === null ? $days : $createdDays)*86400),date('Y-m-d H:i:s',time()-$days*86400),'PRIVATE-KEY-'.$id));
}
set_error_handler(function($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
try {
$pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,display_name TEXT,email TEXT,status TEXT,referral_code TEXT,password_changed_at TEXT);
CREATE TABLE activity_settings(id INTEGER PRIMARY KEY,activity_key TEXT UNIQUE,name TEXT,description TEXT,status TEXT,config_json TEXT,start_at TEXT,end_at TEXT,created_at TEXT,updated_at TEXT);
CREATE TABLE referral_commissions(id INTEGER PRIMARY KEY,commission_no TEXT,referrer_user_id INTEGER,referred_user_id INTEGER,order_id TEXT,amount_cents INTEGER,status TEXT,settlement_mode TEXT,recovery_status TEXT,created_at TEXT,updated_at TEXT,idempotency_key TEXT);
CREATE TABLE referral_relations(id INTEGER PRIMARY KEY,user_id INTEGER,referrer_user_id INTEGER,status TEXT,source TEXT,created_at TEXT,updated_at TEXT);
CREATE TABLE withdrawal_requests(id INTEGER PRIMARY KEY,user_id INTEGER,request_no TEXT,amount_cents INTEGER,status TEXT,channel TEXT,account_name TEXT,account_no TEXT,reject_reason TEXT,created_at TEXT);');
$pdo->exec("INSERT INTO users VALUES(1,'云间旅人','owner@example.test','active','R0123456789ABCDEF0123',NULL),(2,'好友甲','alice.private@example.test','active','RFRIEND2',NULL),(3,'好友乙','bob.private@example.test','active','RFRIEND3',NULL),(99,'其他用户','PRIVATE-OTHER@example.test','active','PRIVATE-CODE',NULL);");
$service = new ActivityService($pdo);
$setting = $pdo->prepare('INSERT INTO activity_settings VALUES(?,?,?,?,?,?,NULL,NULL,?,?)');
foreach (array('lottery','referral','group','leader') as $i=>$key) {
    $default = $service->defaults($key);
    $setting->execute(array($i+1,$key,$default['name'],$default['description'],$key==='referral'?'enabled':'disabled',json_encode($default['config']),date('Y-m-d H:i:s'),date('Y-m-d H:i:s')));
}
$relation = $pdo->prepare('INSERT INTO referral_relations VALUES(?,?,?,?,?,?,?)');
foreach (array(array(1,2,1,'active'),array(2,3,1,'active'),array(99,99,99,'active')) as $r) $relation->execute(array_merge($r,array('PRIVATE-SOURCE',date('Y-m-d H:i:s',time()-86400*$r[0]),date('Y-m-d H:i:s'))));
referral_add(1,'CM202611060001','pending_credit','wallet','none',1,1,1250);
referral_add(2,'CM202611060002','credited','wallet','none',2,1,2800);
referral_add(3,'CM202611060003','credited','wallet','none',3,1,3600);
referral_add(4,'CM202611060004','reversed','wallet','pending',4,1,1800);
referral_add(5,'CM202611060005','reversed','wallet','recovered',5,1,900);
referral_add(999,'PRIVATE-COMMISSION','credited','wallet','none',1,99,999999);
$withdraw = $pdo->prepare('INSERT INTO withdrawal_requests VALUES(?,?,?,?,?,?,?,?,?,?)');
$withdraw->execute(array(1,1,'PRIVATE-WITHDRAWAL',500,'pending','PRIVATE-CHANNEL','PRIVATE-NAME','PRIVATE-ACCOUNT','PRIVATE-REASON',date('Y-m-d H:i:s')));
$withdraw->execute(array(99,99,'PRIVATE-OTHER-WITHDRAWAL',999999,'paid','PRIVATE-OTHER-CHANNEL','PRIVATE-OTHER-NAME','PRIVATE-OTHER-ACCOUNT','PRIVATE-OTHER-REASON','1999-01-01 00:00:00'));
$_SESSION = array('user_id'=>1,'user_password_changed_at'=>null,'_csrf'=>'isolated-referral-token'); // 不启动持久 session。
$config = array('app'=>array('name'=>'小云店','base_url'=>'http://localhost:8081'));
$source = file_get_contents($root.'/public/index.php');
foreach (array('referral_panel','user_nav_icon','home_outline_icon','account_overview_shell') as $name) eval(str_replace('__DIR__',var_export($root.'/public',true),referral_function($source,$name)));
$startMarker = '$router->get(\'/events\',';
$endMarker = '$router->post(\'/events/lottery\',';
$start = strpos($source,$startMarker); $end = $start === false ? false : strpos($source,$endMarker,$start);
if ($start === false || $end === false || substr_count($source,$startMarker)!==1) throw new RuntimeException('events route boundary missing or ambiguous');
$route = trim(substr($source,$start,$end-$start));
if (preg_match_all('/\$router\s*->\s*(?:get|post)\s*\(/',$route)!==1 || substr($route,-3)!=='});') throw new RuntimeException('Extraction must contain only events GET route');
$router = new Router();
eval($route);
$registered = new ReflectionProperty(Router::class,'routes'); $registered->setAccessible(true);
$routes = $registered->getValue($router);
referral_ok(array_keys($routes)===array('GET') && array_keys($routes['GET'])===array('/events'),'仅注册真实 /events GET，未注册提现或发奖 POST');
$before = $pdo->query('SELECT total_changes()')->fetchColumn();
$body = referral_run();
referral_ok(http_response_code()===200 && $GLOBALS['referral_title']==='拉人返佣' && strpos($body,'INVITE &amp; EARN')!==false && count(referral_numbers($body))===5,'正常导航 /events?view=referral 默认新布局与五条真实佣金');
referral_ok(referral_numbers($body)===array('CM202611060005','CM202611060004','CM202611060003','CM202611060002','CM202611060001'),'默认按 ID 倒序呈现参考图五条状态记录');
referral_ok(referral_stats($body)===array('2 人','¥12.50','¥64.00','¥18.00'),'四统计沿用 referralSummary 全局旧口径');
$xp = referral_xpath($body);
referral_ok($xp->query('//h1')->length===1 && $xp->query('//nav[@aria-label="返佣状态筛选"]/a[@aria-current="page"]')->length===1,'唯一主标题与默认状态选中态');
referral_ok($xp->query('//input[@id="referral-invite-link"][@readonly]')->item(0)->getAttribute('value')==='http://localhost:8081/register?ref=R0123456789ABCDEF0123' && $xp->query('//input[@id="referral-invite-code"][@readonly]')->item(0)->getAttribute('value')==='R0123456789ABCDEF0123','真实本人邀请链接和邀请码');
referral_ok($xp->query('//button[@data-copy="referral-invite-link"]')->length===1 && $xp->query('//button[@data-copy="referral-invite-code"]')->length===1 && strpos($body,'navigator.clipboard.writeText(input.value)')!==false && strpos($body,'input.select()')!==false && $xp->query('//*[@id="referral-copy-status"][@role="status"]')->length===1,'复制链接与邀请码保留剪贴板、失败降级和状态提示');
referral_ok($xp->query('//tr[@class="ri-record"]//details[@class="ri-detail"]')->length===3 && $xp->query('//tr[@class="ri-record"]//a[@href="/wallet?type=referral_credit&range=all"]')->length===2 && $xp->query('//section[@class="ri-records"]//form[@method="post"]')->length===0,'本人佣金只读详情、两条已入账入口，无资金操作表单');
referral_ok(substr_count($body,'class="ri-friend"')===2 && strpos($body,'a***@example.test')!==false && strpos($body,'b***@example.test')!==false && strpos($body,'alice.private')===false && strpos($body,'bob.private')===false,'两位真实邀请好友脱敏');
referral_ok(strpos($body,'PRIVATE-')===false && strpos($body,'order_id')===false && strpos($body,'idempotency_key')===false && strpos($body,'referrer_user_id')===false,'不泄露好友订单、内部键、他人佣金邀请与任何提现收款信息');
$pdo->exec('PRAGMA query_only=ON');
try { ob_start(); account_overview_shell('拉人返佣',$body,'',0,$pdo,$config,'/events?view=referral'); $html = ob_get_clean(); }
finally { $pdo->exec('PRAGMA query_only=OFF'); }
referral_ok(strpos($html,'account-referral-editorial.css')!==false && strpos($html,'href="/events?view=referral" aria-current="page"')!==false && strpos($html,'<main class="ao-main">'.$body)!==false,'首次 fixture 真实共享壳、独立 CSS 与默认侧栏入口高亮');
$pageSource = referral_function($source,'page');
referral_ok(strpos($pageSource,"(\$path==='/events' && (\$_GET['view']??null)==='referral')")!==false && strpos($pageSource,"\$path==='/events'?'/events?view=referral':(\$path==='/account'?'/account/security':\$path)")!==false,'真实 page 默认入口接共享壳，不依赖额外预览参数');
referral_ok(referral_xpath($html)->query('//form[@method="post"][@action="/logout"]/input[@name="_csrf"][@value="isolated-referral-token"]')->length===1,'共享退出表单使用真实 Security CSRF');
referral_ok($before==$pdo->query('SELECT total_changes()')->fetchColumn(),'预置配置邀请码后正常 GET 与共享壳 total_changes 不变');
// 第六条历史记录只用 fixture 插入，绝不提交表单。
referral_add(6,'CMLEGACY','available','legacy','pending',1,1,10000);
$r = referral_run();
referral_ok(count(referral_numbers($r))===6 && referral_stats($r)===referral_stats($body),'六条记录含 legacy，但钱包三统计不混入历史佣金');
referral_ok($service->referralSummary(1)===array('invited'=>2,'earned'=>10000,'available'=>9500,'code'=>'R0123456789ABCDEF0123','wallet_credited'=>6400,'wallet_pending'=>1250,'wallet_recovery_pending'=>1800),'真实 referralSummary 历史有效佣金减预留提现，钱包口径不变');
referral_ok(strpos($r,'历史累计有效佣金 ¥100.00，可申请提现 ¥95.00')!==false && referral_xpath($r)->query('//form[@action="/events/withdraw"][@method="post"]/input[@name="_csrf"][@value="isolated-referral-token"]')->length===1 && strpos($r,'PRIVATE-')===false,'保留历史提现表单和真实 CSRF，不回显收款账号与内部提现信息');
$expected = array('pending_credit'=>array('CM202611060001'),'credited'=>array('CM202611060003','CM202611060002'),'recovery'=>array('CM202611060004'),'reversed'=>array('CM202611060005'),'legacy'=>array('CMLEGACY'));
foreach ($expected as $status=>$numbers) {
    $r = referral_run(array('status'=>$status));
    referral_ok(referral_numbers($r)===$numbers && referral_stats($r)===referral_stats($body) && referral_xpath($r)->query('//nav[@aria-label="返佣状态筛选"]/a[@aria-current="page"]')->length===1,'真实状态筛选、全局统计不变 '.$status);
}
$pdo->exec('SAVEPOINT precedence');
$pdo->exec('UPDATE referral_commissions SET status="credited" WHERE id=4; UPDATE referral_commissions SET status="reversed" WHERE id=6');
referral_ok(referral_numbers(referral_run(array('status'=>'legacy')))===array('CMLEGACY') && referral_numbers(referral_run(array('status'=>'recovery')))===array('CM202611060004') && referral_numbers(referral_run(array('status'=>'credited')))===array('CM202611060003','CM202611060002'),'legacy 优先于 recovery，recovery 优先于 credited/reversed');
referral_ok(referral_stats(referral_run())===array('2 人','¥12.50','¥82.00','¥18.00'),'展示状态优先级不改变 referralSummary 旧 status 聚合口径');
$pdo->exec('ROLLBACK TO precedence; RELEASE precedence');
$pdo->exec('SAVEPOINT ranges');
referral_add(7,'CMOLD-CREATED-RECENT-UPDATED','pending_credit','wallet','none',2,1,1000,120);
referral_add(8,'CM60-DAYS','pending_credit','wallet','none',60,1,1000,1);
referral_add(9,'CM120-DAYS','pending_credit','wallet','none',120,1,1000,1);
foreach (array('30'=>array('CMOLD-CREATED-RECENT-UPDATED'),'90'=>array('CM60-DAYS','CMOLD-CREATED-RECENT-UPDATED'),'all'=>array('CM120-DAYS','CM60-DAYS','CMOLD-CREATED-RECENT-UPDATED')) as $range=>$numbers) {
    $r = referral_run(array('range'=>(string)$range,'q'=>'CM'));
    $actual = referral_numbers($r);
    referral_ok(array_slice($actual,0,count($numbers))===$numbers && strpos($r,'共 '.(6+count($numbers)).' 条')!==false,'时间范围按 updated_at 而非 created_at '.$range);
}
referral_ok(referral_stats(referral_run(array('range'=>'30','q'=>'not-found')))===array('2 人','¥42.50','¥64.00','¥18.00'),'时间和搜索不影响旧口径全局统计');
$pdo->exec('ROLLBACK TO ranges; RELEASE ranges');
$pdo->exec('SAVEPOINT searching');
referral_add(7,'CMSPECIAL%_!literal');
referral_add(8,'CMSPECIALabcX!literal');
foreach (array('%_!','%','_','!literal','CMSPECIAL%_!literal') as $q) {
    $expectedSearch = $q==='!literal' ? array('CMSPECIALabcX!literal','CMSPECIAL%_!literal') : array('CMSPECIAL%_!literal');
    referral_ok(referral_numbers(referral_run(array('q'=>$q)))===$expectedSearch,'commission_no 字面 LIKE 转义 '.$q);
}
foreach (array("' OR 1=1 --", "%' OR '1'='1",'PRIVATE-ORDER','alice.private','PRIVATE-KEY') as $q) referral_ok(referral_numbers(referral_run(array('q'=>$q)))===array(),'搜索不接受 SQL 注入且不搜索好友订单或内部键 '.$q);
referral_ok(referral_numbers(referral_run(array('q'=>'  CM202611060001  ')))===array('CM202611060001'),'搜索按编号并 trim');
$pdo->exec('ROLLBACK TO searching; RELEASE searching');
$pdo->exec('SAVEPOINT paging');
for ($i=7;$i<=14;$i++) referral_add($i,'CMPAGE'.$i);
$r = referral_run(array('range'=>'all','status'=>'pending_credit','q'=>'CM'));
referral_ok(referral_numbers($r)===array('CMPAGE14','CMPAGE13','CMPAGE12','CMPAGE11','CMPAGE10','CMPAGE9') && strpos($r,'共 9 条 · 第 1 / 2 页')!==false,'六条佣金分页、相同时间按 ID 稳定倒序');
$xp = referral_xpath($r); $next = $xp->query('//nav[@aria-label="返佣记录分页"]/a[@rel="next"]')->item(0)->getAttribute('href');
parse_str(parse_url($next,PHP_URL_QUERY),$nextGet);
referral_ok($nextGet===array('view'=>'referral','status'=>'pending_credit','range'=>'all','q'=>'CM','page'=>'2','invite_page'=>'1') && parse_url($next,PHP_URL_FRAGMENT)==='referral-records','分页链接保留状态时间搜索邀请页及锚点');
referral_ok(referral_numbers(referral_run($nextGet))===array('CMPAGE8','CMPAGE7','CM202611060001'),'真实下一页链接剩余三条且无重复');
referral_ok(referral_numbers(referral_run(array_merge($nextGet,array('page'=>'999999999'))))===array('CMPAGE8','CMPAGE7','CM202611060001'),'佣金越界页收敛末页');
$pdo->exec('ROLLBACK TO paging; RELEASE paging');
$pdo->exec('SAVEPOINT invitations');
for ($i=4;$i<=10;$i++) {
    $pdo->prepare('INSERT INTO users VALUES(?,?,?,"active",?,NULL)')->execute(array($i,'好友'.$i,'friend'.$i.'@example.test','RFIXTURE'.$i));
    $relation->execute(array($i,$i,1,$i===10?'disabled':'active','PRIVATE-SOURCE',sprintf('2026-01-%02d 00:00:00',$i),date('Y-m-d H:i:s')));
}
$r = referral_run(array('status'=>'credited','range'=>'all','q'=>'CM','page'=>'1'));
$xp = referral_xpath($r); $friends = $xp->query('//tr[@class="ri-friend"]');
referral_ok($friends->length===5 && strpos($r,'已绑定 8 人')!==false && strpos($friends->item(0)->textContent,'2026-01-09')!==false && strpos($friends->item(4)->textContent,'2026-01-05')!==false,'邀请五条分页、active 过滤和 ID 稳定倒序');
$inviteHref = $xp->query('//nav[@aria-label="邀请记录分页"]/a')->item(0)->getAttribute('href');
parse_str(parse_url($inviteHref,PHP_URL_QUERY),$inviteGet);
referral_ok($inviteGet===array('view'=>'referral','status'=>'credited','range'=>'all','q'=>'CM','page'=>'1','invite_page'=>'2') && parse_url($inviteHref,PHP_URL_FRAGMENT)==='referral-invitations','邀请分页链接保留佣金筛选及页码');
$r = referral_run($inviteGet);
referral_ok(substr_count($r,'class="ri-friend"')===3 && strpos($r,'2026-01-04')!==false && strpos($r,'2026-01-10')===false && strpos($r,'PRIVATE-')===false,'邀请第二页真实三条，不包含 inactive 或他人邀请');
referral_ok(referral_run(array_merge($inviteGet,array('invite_page'=>'999999999')))===$r,'邀请越界收敛末页');
$pdo->exec('ROLLBACK TO invitations; RELEASE invitations');
$bad = array(array('status'=>'unknown'),array('range'=>'7'),array('q'=>str_repeat('中',81)));
foreach (array('status','range','page','invite_page','q') as $key) $bad[] = array($key=>array('1'));
foreach (array('page','invite_page') as $key) foreach (array('0','-1','1e2','1.5',' 1','1000000000','1 OR 1=1') as $value) $bad[] = array($key=>$value);
foreach ($bad as $i=>$get) {
    $r = referral_run($get);
    referral_ok(http_response_code()===400 && strpos($r,'返佣筛选参数无效')!==false && referral_numbers($r)===array(),'坏数组/状态/range/page/超长q 返回400 #'.($i+1));
}
referral_ok(strpos(referral_run(array('q'=>str_repeat('中',80))),'返佣筛选参数无效')===false && http_response_code()===200,'q 八十字符边界有效');
$pdo->exec('SAVEPOINT xss');
$payload = '<img src=x onerror=alert(1)>';
$pdo->prepare('UPDATE referral_commissions SET commission_no=?,created_at=?,updated_at=? WHERE id=6')->execute(array($payload,'<script>created</script>','9999-01-01 <svg onload=alert(2)>'));
$pdo->prepare('UPDATE users SET email=? WHERE id=2')->execute(array('alice@<img src=x onerror=alert(3)>'));
$r = referral_run(array('q'=>$payload,'range'=>'all'));
referral_ok(referral_numbers($r)===array($payload) && strpos($r,e($payload))!==false && strpos($r,$payload)===false && strpos($r,'&lt;script&gt;created&lt;/script&gt;')!==false && strpos($r,'<script>created')===false && strpos($r,'<svg onload')===false,'搜索编号、详情和日期 XSS 安全转义');
referral_ok(strpos($r,'a***@&lt;img src=x onerror=alert(3)&gt;')!==false && strpos($r,'alice@')===false && strpos($r,'<img src=x')===false,'脱敏好友域名仍做 XSS 转义');
$pdo->prepare('UPDATE users SET referral_code=? WHERE id=1')->execute(array('R"<script>code</script>'));
$xconfig = $config; $config['app']['base_url']='http://localhost:8081/"<svg>';
// 路由闭包按值捕获配置，重新注册后核验新的边界输入。
eval($route);
$r = referral_run();
referral_ok(strpos($r,'value="R&quot;&lt;script&gt;code&lt;/script&gt;"')!==false && strpos($r,'R%22%3Cscript%3Ecode%3C%2Fscript%3E')!==false && strpos($r,'8081/&quot;&lt;svg&gt;/register')!==false,'邀请码及链接属性转义和 URL 编码');
$config = $xconfig;
eval($route);
$pdo->exec('ROLLBACK TO xss; RELEASE xss');
$pdo->exec('SAVEPOINT unknown_state');
referral_add(7,'CMUNKNOWN','future_state');
$r = referral_run(array('q'=>'CMUNKNOWN'));
referral_ok(strpos($r,'class="ri-state is-unknown">待核对')!==false && strpos($r,'记录状态：待核对')!==false && strpos($r,'历史状态：待核对')!==false && strpos($r,'is-reversed')===false && strpos($r,'查看入账')===false,'未知状态显示待核对，不伪装撤回或已到账');
$pdo->exec('ROLLBACK TO unknown_state; RELEASE unknown_state');
$pdo->exec('SAVEPOINT disabled_activity; UPDATE activity_settings SET status="disabled" WHERE activity_key="referral"');
$r = referral_run();
referral_ok(strpos($r,'活动暂未开启，当前不产生新返佣')!==false && strpos($r,'活动进行中')===false && strpos($r,'活动开启期间，好友首笔符合条件')!==false && strpos($r,'分享链接本身不代表获得奖励')!==false && strpos($r,'保证')===false && strpos($r,'立即到账')===false && count(referral_numbers($r))===6,'活动 disabled 明示不产生新返佣，保留历史事实、不承诺发奖');
$pdo->exec('ROLLBACK TO disabled_activity; RELEASE disabled_activity');
$pdo->exec('SAVEPOINT empty_state; DELETE FROM referral_commissions WHERE referrer_user_id=1; DELETE FROM referral_relations WHERE referrer_user_id=1; DELETE FROM withdrawal_requests WHERE user_id=1');
$r = referral_run();
referral_ok(referral_numbers($r)===array() && substr_count($r,'class="ri-friend"')===0 && referral_stats($r)===array('0 人','¥0.00','¥0.00','¥0.00') && strpos($r,'暂无匹配的返佣记录')!==false && strpos($r,'暂无已绑定的邀请好友')!==false && strpos($r,'CM2026')===false && strpos($r,'PRIVATE-')===false && strpos($r,'action="/events/withdraw"')===false,'空态无假佣金、好友或提现表单，不借用他人数据');
$pdo->exec('ROLLBACK TO empty_state; RELEASE empty_state');
$_SESSION = array();
try { referral_run(); throw new LogicException('访客未鉴权'); }
catch (RuntimeException $ex) { referral_ok($ex->getMessage()==='REDIRECT /login','访客真实 GET route 通过 Security::requireUser 跳转 /login'); }
if ($htmlMode) { fwrite(STDERR,'REFERRAL PAGE TESTS PASSED ('.$tests.")\n"); echo $html; }
else echo 'REFERRAL PAGE TESTS PASSED ('.$tests.")\n";
} catch (Throwable $ex) {
    fwrite(STDERR, 'REFERRAL PAGE TESTS FAILED after '.$tests.' passed: '.$ex->getMessage().' ['.$ex->getFile().':'.$ex->getLine()."]\n");
    exit(1);
}
