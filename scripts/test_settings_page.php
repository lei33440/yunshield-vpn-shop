<?php
// 仅 SQLite :memory: 页面回归；不加载启动器/凭据，不注册或执行任何业务 POST。
// --html 静默执行全部断言后，仅输出首次 fixture 的完整共享壳，可用于本地 8081 截图。
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require $root.'/app/Core/Router.php';
require $root.'/app/Core/Security.php';
function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function flash($type, $text) {}
function redirect($url) { throw new RuntimeException('REDIRECT '.$url); }
function page($title, $body, $config) { $GLOBALS['settings_body'] = $body; $GLOBALS['settings_title'] = $title; }
$htmlMode = in_array('--html', $_SERVER['argv'], true);
$tests = 0;
function settings_ok($ok, $label) {
    if (!$ok) throw new RuntimeException('FAIL '.$label);
    $GLOBALS['tests']++;
    if (!$GLOBALS['htmlMode']) echo 'PASS '.$label.PHP_EOL;
}
function settings_function($source, $name) {
    $tokens = token_get_all($source);
    $capture = false; $opened = false; $depth = 0; $result = '';
    foreach ($tokens as $i=>$token) {
        if (!$capture && is_array($token) && $token[0] === T_FUNCTION) {
            $j = $i + 1;
            while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
            if (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING && $tokens[$j][1] === $name) $capture = true;
        }
        if (!$capture) continue;
        $result .= is_array($token) ? $token[1] : $token;
        if ($token === '{' || (is_array($token) && in_array($token[0], array(T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES), true))) { $opened = true; $depth++; }
        elseif ($token === '}' && --$depth === 0 && $opened) return $result;
    }
    throw new RuntimeException('函数缺失 '.$name);
}
function settings_xpath($html) {
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    return new DOMXPath($doc);
}
function settings_readonly($callback) {
    global $pdo;
    $before = $pdo->query('SELECT total_changes()')->fetchColumn();
    $pdo->exec('PRAGMA query_only=ON');
    try { return $callback(); }
    finally {
        $pdo->exec('PRAGMA query_only=OFF');
        if ($before != $pdo->query('SELECT total_changes()')->fetchColumn()) throw new RuntimeException('GET changed database');
    }
}
function settings_run($path) {
    $_GET = array(); $_SERVER['REQUEST_URI'] = $path;
    http_response_code(200); $GLOBALS['settings_body'] = ''; $GLOBALS['settings_title'] = '';
    return settings_readonly(function() use($path) {
        $GLOBALS['router']->dispatch('GET', $path);
        return $GLOBALS['settings_body'];
    });
}
set_error_handler(function($severity,$message,$file,$line) { throw new ErrorException($message,0,$severity,$file,$line); });
try {
    $pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));
    $pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,display_name TEXT,email TEXT,created_at TEXT,password_changed_at TEXT,password_hash TEXT,remember_token TEXT,reset_token TEXT)');
    $hash = password_hash('PRIVATE-FIXTURE-PASSWORD', PASSWORD_DEFAULT);
    $insert = $pdo->prepare('INSERT INTO users VALUES(?,?,?,?,NULL,?,?,?)');
    $insert->execute(array(1,'云间旅人','owner@example.test','2026-01-02 03:04:05',$hash,'PRIVATE-REMEMBER-1','PRIVATE-RESET-1'));
    $insert->execute(array(2,'PRIVATE-OTHER-NAME','PRIVATE-OTHER@example.test','1999-01-01 00:00:00',password_hash('PRIVATE-OTHER-PASSWORD',PASSWORD_DEFAULT),'PRIVATE-REMEMBER-2','PRIVATE-RESET-2'));
    $_SESSION = array('user_id'=>1,'user_password_changed_at'=>null,'_csrf'=>'isolated-settings-token');
    // 即使请求含敏感旧输入，GET 也不得将其回显。
    $_POST = array('old_password'=>'PRIVATE-OLD','new_password'=>'PRIVATE-NEW','confirm_password'=>'PRIVATE-CONFIRM');
    $config = array('app'=>array('name'=>'小云店','base_url'=>'http://localhost:8081'));
    $source = file_get_contents($root.'/public/index.php');
    $settingsSource = settings_function($source,'account_settings_page');
    $shellSource = settings_function($source,'account_overview_shell');
    foreach (array('account_settings_page','user_nav_icon','home_outline_icon','account_overview_shell') as $name) {
        eval(str_replace('__DIR__',var_export($root.'/public',true),settings_function($source,$name)));
    }
    $startMarker = '$accountPage=function'; $endMarker = '$router->post(\'/account/profile\'';
    $start = strpos($source,$startMarker); $end = $start === false ? false : strpos($source,$endMarker,$start);
    if ($start === false || $end === false || substr_count($source,$startMarker) !== 1) throw new RuntimeException('账户 GET 提取边界缺失或歧义');
    $route = trim(substr($source,$start,$end-$start));
    // 精确限制闭包后的内容为三个原 GET 注册；绝不 eval POST 注册或顶层启动代码。
    $tail = '$router->get(\'/account\',function() use($accountPage){$accountPage(\'profile\');});'.
        '$router->get(\'/account/security\',function() use($accountPage){$accountPage(\'security\');});'.
        '$router->get(\'/account/notifications\',function() use($accountPage){$accountPage(\'notifications\');});';
    $compact = preg_replace('/\s+/', '', $route);
    $compactTail = preg_replace('/\s+/', '', $tail);
    if (substr($compact,-strlen($compactTail)) !== $compactTail || preg_match_all('/\$router\s*->/', $route) !== 3 || preg_match('/->\s*post\s*\(/', $route)) throw new RuntimeException('提取段必须仅包含 accountPage 闭包与三个 GET');
    $router = new Router(); eval($route);
    $property = new ReflectionProperty(Router::class,'routes'); $property->setAccessible(true);
    $routes = $property->getValue($router);
    settings_ok(array_keys($routes) === array('GET') && array_keys($routes['GET']) === array('/account','/account/security','/account/notifications'),'仅注册真实闭包及三个 GET，无业务 POST');
    settings_ok(strpos($settingsSource,'SELECT display_name,email,created_at FROM users WHERE id=?') !== false && strpos($shellSource,'SELECT display_name,email FROM users WHERE id=?') !== false && !preg_match('/SELECT\s+(?:\*|[^\'\n]*(?:password_hash|token))/i',$settingsSource.$shellSource),'页面与共享壳只 SELECT 本人公开资料');
    settings_ok(strpos($route,"\$section!=='notifications'") !== false && strpos($route,'(new NotificationService($pdo))->recent($uid,5)') !== false && strpos($route,'href="/notifications"') !== false,'旧通知分支与查看全部入口保留（仅源码检查，不执行通知服务）');
    // HEAD 5af4cf5 的完整单行路由 SHA-256（去除行尾 CR/LF）；运行脚本不调用 git。
    $postHashes = array('/account/profile'=>'04985863268de890cfa77864cfb592b12b6b732c35a910fc6f0655bc5d032f79','/account/password'=>'1626e8cc7106d1ecab3c7b33c0dd1bee5d563e85ad25fdebdc3213e91693d41f');
    foreach ($postHashes as $path=>$expected) {
        $matches = array();
        preg_match_all('/^'.preg_quote('$router->post(\''.$path.'\'', '/').'.*$/m',$source,$matches);
        settings_ok(count($matches[0]) === 1 && hash('sha256',rtrim($matches[0][0],"\r\n")) === $expected,'原 POST 精确匹配 HEAD 路由源码 '.$path);
    }
    $before = $pdo->query('SELECT total_changes()')->fetchColumn();
    $html = '';
    foreach (array('/account'=>'/account#settings-profile','/account/security'=>'/account/security#settings-password') as $path=>$tab) {
        $body = settings_run($path); $xp = settings_xpath($body);
        settings_ok(http_response_code() === 200 && $GLOBALS['settings_title'] === '账户与安全' && $xp->query('//h1')->length === 1 && strpos($body,'PROFILE &amp; SETTINGS') !== false,'正常入口新布局与唯一主标题 '.$path);
        settings_ok($xp->query('//nav[@class="as-tabs"]/a[@aria-current="page"]')->length === 1 && $xp->query('//nav[@class="as-tabs"]/a[@aria-current="page"]')->item(0)->getAttribute('href') === $tab,'profile/security 正确 tab aria-current '.$path);
        settings_ok($xp->query('//form')->length === 2 && $xp->query('//form[@method="post"][@action="/account/profile"]')->length === 1 && $xp->query('//form[@method="post"][@action="/account/password"]')->length === 1,'两张原 actions 表单同时存在 '.$path);
        settings_ok($xp->query('//form/input[@type="hidden"][@name="_csrf"][@value="isolated-settings-token"]')->length === 2,'两表单使用真实 Security CSRF '.$path);
        settings_ok($xp->query('//input[@name="old_password"][@type="password"][@autocomplete="current-password"][@required]')->length === 1 && $xp->query('//input[@name="new_password"][@type="password"][@autocomplete="new-password"][@minlength="8"][@required]')->length === 1,'原密码新密码字段、自动填充与八字符约束 '.$path);
        settings_ok($xp->query('//input[@id="settings-confirm_password"][@type="password"][@autocomplete="new-password"][@required][not(@name)]')->length === 1 && $xp->query('//form[@action="/account/password"]//input[@name]')->length === 3,'确认仅浏览器校验无 name，不改变提交协议 '.$path);
        settings_ok($xp->query('//input[@type="password"]')->length === 3 && $xp->query('//input[@type="password"][@value and string-length(@value)>0]')->length === 0 && strpos($body,'PRIVATE-') === false && strpos($body,$hash) === false,'密码值、hash、令牌与他人邮箱全部不回显 '.$path);
        $revealOK = $xp->query('//button[@class="as-reveal"]')->length === 3;
        foreach ($xp->query('//button[@class="as-reveal"]') as $button) {
            $id = $button->getAttribute('data-password');
            $revealOK = $revealOK && $button->getAttribute('type') === 'button' && $button->getAttribute('aria-controls') === $id && $button->getAttribute('aria-pressed') === 'false' && strpos($button->getAttribute('aria-label'),'显示') === 0 && $xp->query('//input[@type="password"][@id="'.$id.'"]')->length === 1;
        }
        settings_ok($revealOK,'实际三个 reveal 按钮各自关联密码输入及 aria 状态 '.$path);
        settings_ok(strpos($body,'owner@example.test') !== false && strpos($body,'不可修改') !== false && $xp->query('//input[@name="email" or @type="email"]')->length === 0 && $xp->query('//time[@datetime="2026-01-02T03:04:05"]')->length === 1 && strpos($body,'2026.01.02 03:04') !== false,'本人邮箱只读与真实注册时间 '.$path);
        settings_ok($xp->query('//input[@name="display_name"][@value="云间旅人"][@maxlength="100"][@required]')->length === 1 && $xp->query('//div[@class="as-identity"]/strong')->item(0)->textContent === '云间旅人','真实昵称与原昵称输入限制 '.$path);
        settings_ok($xp->query('//a[@href="/password/forgot"]')->length === 1 && $xp->query('//a[@href="/account/notifications"]')->length === 1 && $xp->query('//a[@href="/articles"]')->length === 1 && $xp->query('//a[@href="/tickets#new-ticket"]')->length === 1,'忘记密码、最近通知、帮助与工单真实链接 '.$path);
        if ($html === '') {
            $html = settings_readonly(function() use($body,$pdo,$config) {
                ob_start();
                try { account_overview_shell('账户与安全',$body,'',0,$pdo,$config,'/account/security'); return ob_get_contents(); }
                finally { ob_end_clean(); }
            });
        }
    }
    $pageSource = settings_function($source,'page');
    settings_ok(strpos($pageSource,"'/account/overview','/account','/account/security'") !== false && strpos($pageSource,"(\$path==='/account'?'/account/security':\$path)") !== false,'真实 page 接共享壳且 /account canonical active 为 /account/security');
    $shell = settings_xpath($html);
    settings_ok(strpos($html,'account-settings-editorial.css?v=') !== false && $shell->query('//body[contains(@class,"account-settings-editorial")]')->length === 1 && $shell->query('//main//nav[@class="as-tabs"]/a[@href="/account#settings-profile"][@aria-current="page"]')->length === 1 && strpos($html,'<main class="ao-main"><div class="as-page">') !== false,'首次 profile fixture 完整共享壳与独立 CSS 类');
    settings_ok($shell->query('//aside[@id="ao-sidebar"]//a[@href="/account/security"][@aria-current="page"]')->length === 1 && $shell->query('//aside[@id="ao-sidebar"]//a[@aria-current="page"]')->length === 1,'共享 sidebar 唯一账户与安全 current');
    settings_ok($shell->query('//form[@method="post"][@action="/logout"]/input[@name="_csrf"][@value="isolated-settings-token"]')->length === 1,'共享壳退出使用真实 CSRF');
    $css = file_get_contents($root.'/public/assets/account-settings-editorial.css');
    settings_ok(strpos($css,'.ao-layout') === false && strpos($css,'body.account-settings-editorial .as-page input') !== false && strpos($css,'.as-page :is(input,button,summary,a):focus-visible') !== false && !preg_match('/(?:^|})\s*(?:input|button|form|body|\.ao-layout)\s*\{/m',$css),'独立 CSS 继承总览布局，仅使用 settings/as 作用域调整页面内容');
    settings_ok(strpos($settingsSource,'beforeunload') !== false && strpos($settingsSource,'input.value!==input.defaultValue') !== false && strpos($settingsSource,'other!==form&&other.isDirty()') !== false && strpos($settingsSource,'event.returnValue=""') !== false,'源码保留未保存提示与跨表单离开确认');
    settings_ok(strpos($settingsSource,'confirm.setCustomValidity(confirm.value!==password.value?') !== false && strpos($settingsSource,'password.addEventListener("input",validateConfirm)') !== false && strpos($settingsSource,'confirm.addEventListener("input",validateConfirm)') !== false && strpos($settingsSource,'new TextEncoder().encode(name.value.trim()).length>100') !== false,'源码保留输入一致性 customValidity 与昵称字节校验');
    settings_ok(strpos($settingsSource,'if(form.dataset.busy==="true"){event.preventDefault();return}') !== false && strpos($settingsSource,'window.addEventListener("pageshow"') !== false && strpos($settingsSource,'form.dataset.busy="false"') !== false && strpos($settingsSource,'.disabled=false') !== false && strpos($settingsSource,'.type="password"') !== false && strpos($settingsSource,'button.setAttribute("aria-pressed",String(show))') !== false,'源码保留防重复提交、pageshow 恢复与 reveal aria 切换');
    settings_ok(strpos($GLOBALS['settings_body'],'修改密码后当前会话将更新') !== false && !preg_match('/(?:注销|退出|失效).{0,10}(?:其他|所有).{0,10}(?:会话|设备)|(?:其他|所有).{0,10}(?:会话|设备).{0,10}(?:注销|退出|失效)/u',$GLOBALS['settings_body']),'安全说明不冒充注销其他 sessions');
    settings_ok($before == $pdo->query('SELECT total_changes()')->fetchColumn(),'两入口 GET 与共享壳 query_only 保护且 total_changes 不变');
    $payload = '\"><img src=x onerror=alert(1)>';
    $pdo->prepare('UPDATE users SET display_name=? WHERE id=1')->execute(array($payload));
    $escaped = settings_run('/account'); $xp = settings_xpath($escaped);
    settings_ok(strpos($escaped,e($payload)) !== false && strpos($escaped,$payload) === false && $xp->query('//input[@name="display_name"]')->item(0)->getAttribute('value') === $payload && $xp->query('//div[@class="as-identity"]/strong')->item(0)->textContent === $payload,'昵称文本与属性安全转义');
    $pdo->exec("UPDATE users SET display_name='' WHERE id=1");
    $empty = settings_run('/account/security'); $xp = settings_xpath($empty);
    settings_ok($xp->query('//div[@class="as-identity"]/strong')->item(0)->textContent === 'owner@example.test' && $xp->query('//input[@name="display_name"]')->item(0)->getAttribute('value') === '' && strpos($empty,'PRIVATE-') === false,'空昵称仅显示 fallback 本人邮箱，输入仍为空');
    $_SESSION = array(); $redirects = array();
    foreach (array('/account','/account/security') as $path) {
        try { settings_run($path); $redirects[] = 'NO REDIRECT'; }
        catch (RuntimeException $ex) { $redirects[] = $ex->getMessage(); }
    }
    settings_ok($redirects === array('REDIRECT /login','REDIRECT /login'),'两个入口访客均经真实 Security 跳转 /login');
    if ($htmlMode) echo $html;
    else echo 'SETTINGS PAGE TESTS PASSED ('.$tests.")\n";
} catch (Throwable $ex) {
    fwrite(STDERR,'SETTINGS PAGE TESTS FAILED after '.$tests.' passed: '.$ex->getMessage().' ['.$ex->getFile().':'.$ex->getLine()."]\n");
    exit(1);
}
