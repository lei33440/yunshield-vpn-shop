<?php
$projectRoot = dirname(__DIR__);
// Fail closed before loading business code, including for administrator sessions and payment callbacks.
$requestPath = rtrim((string)parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH), '/') ?: '/';
$requestMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$maintenanceFlag = $projectRoot . '/storage/update/maintenance.flag';
$maintenanceActive = is_file($maintenanceFlag);
$updateControl = ($requestMethod === 'GET' && in_array($requestPath, array('/admin/system/update','/admin/system/update/status'), true))
    || ($requestMethod === 'POST' && $requestPath === '/admin/system/update/rollback');
$updateProbe = $requestMethod === 'GET' && $requestPath === '/health' && isset($_SERVER['HTTP_X_UPDATE_HEALTH_TOKEN']);
if ($maintenanceActive && !$updateControl && !$updateProbe) {
    http_response_code(503);
    header('Retry-After: 60');
    header('Cache-Control: no-store');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>系统维护中</title><h1>系统维护中</h1><p>系统正在更新或恢复，请稍后重试。支付通知请稍后重试。</p>';
    exit;
}
if (!is_file($projectRoot . '/config/config.php')) { header('Location: /install.php', true, 302); exit; }
if ($updateProbe || ($maintenanceActive && $updateControl)) {
    require_once $projectRoot . '/app/Core/Bootstrap.php';
    $config = Bootstrap::init();
    header('Cache-Control: no-store, no-cache, must-revalidate');
    if ($updateProbe) {
        header('Content-Type: application/json; charset=utf-8');
        $expected = isset($config['update']['health_token']) ? (string)$config['update']['health_token'] : '';
        $provided = (string)$_SERVER['HTTP_X_UPDATE_HEALTH_TOKEN'];
        if (strlen($expected) < 32 || !hash_equals($expected, $provided)) {
            http_response_code(403); echo '{"ok":false,"error":"forbidden"}'; exit;
        }
        try {
            if(function_exists('opcache_get_configuration')){
                $opcache=opcache_get_configuration();
                if(!empty($opcache['directives']['opcache.enable'])&&empty($opcache['directives']['opcache.validate_timestamps']))throw new RuntimeException('OPcache 必须启用时间戳验证。');
                if(function_exists('opcache_reset'))opcache_reset();
            }
            $health = (new UpdateService($config))->healthCheck();
            if (empty($health['ok'])) http_response_code(503);
            echo json_encode($health, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            http_response_code(503); echo '{"ok":false,"error":"health_check_failed"}';
        }
        exit;
    }
    if (empty($_SESSION['admin_id'])) {
        http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); echo '维护期间仅已登录管理员可查看更新状态。'; exit;
    }
    require_once $projectRoot . '/app/Core/AdminExtraPages.php';
    if ($requestMethod === 'GET' && $requestPath === '/admin/system/update/status') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(update_status_payload($config), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
    }
    if ($requestMethod === 'POST') {
        Security::checkCsrf(isset($_POST['_csrf']) && is_string($_POST['_csrf']) ? $_POST['_csrf'] : '');
        try {
            if (!isset($_POST['confirm_rollback']) || $_POST['confirm_rollback'] !== '1') throw new RuntimeException('Confirmation required');
            $backupId = isset($_POST['backup_id']) && is_string($_POST['backup_id']) ? trim($_POST['backup_id']) : '';
            (new UpdateService($config))->startWorker($backupId, 'rollback');
            flash('success', '恢复任务已提交，正在等待执行反馈。');
        } catch (Throwable $e) { flash('error', '恢复请求未完成，请查看任务状态；如仍需人工恢复，请联系运维。'); }
        redirect('/admin/system/update');
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>更新维护控制台</title><body><h1>更新维护控制台</h1><p>业务写入已暂停。只有状态查询及受控恢复开放；不要关闭维护标记。</p>';
    foreach (array('success','error') as $kind) { $message = get_flash($kind); if ($message) echo '<p>'.e($message).'</p>'; }
    echo update_status_panel($config).'</body></html>'; exit;
}
require $projectRoot . '/app/Core/Bootstrap.php';
require dirname(__DIR__) . '/app/Core/AccountProductPages.php';
require dirname(__DIR__) . '/app/Services/AccountPlatformService.php';
require dirname(__DIR__) . '/app/Core/AdminView.php';
require dirname(__DIR__) . '/app/Core/AdminDashboard.php';
require dirname(__DIR__) . '/app/Core/AdminExtraPages.php';
require dirname(__DIR__) . '/app/Core/AdminLogPages.php';
require dirname(__DIR__) . '/app/Core/AdminEmailCampaignPages.php';
require dirname(__DIR__) . '/app/Core/AdminEmailTemplatePages.php';
require dirname(__DIR__) . '/app/Core/AdminCouponPages.php';
require dirname(__DIR__) . '/app/Core/AdminVpnCategoryPages.php';
require dirname(__DIR__) . '/app/Core/AdminWalletPages.php';
require dirname(__DIR__) . '/app/Core/FeedbackPages.php';
require dirname(__DIR__) . '/app/Core/AdminFeedbackPages.php';
require dirname(__DIR__) . '/app/Services/FeedbackService.php';
require dirname(__DIR__) . '/app/Core/NotificationPages.php';
require dirname(__DIR__) . '/app/Core/HelpCenterPages.php';
require dirname(__DIR__) . '/app/Core/AnnouncementPages.php';
require dirname(__DIR__) . '/app/Core/TicketPages.php';
$config = Bootstrap::init();
$pdo = Database::connect($config);
$catalogSchemaReady=account_catalog_ready($pdo);
if (!$catalogSchemaReady && $requestPath!=='/health') {
    http_response_code(503); header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>商城暂不可用</title><h1>商城等待结构升级</h1><p>请管理员完成本轮数据库备份及结构升级后重试。现有数据不会被页面自动迁移。</p>'; exit;
}
$settings = $pdo->query('SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ("site_name", "site_base_url")');
foreach ($settings as $setting) {
    if ($setting['setting_key'] === 'site_name') $config['app']['name'] = $setting['setting_value'];
    if ($setting['setting_key'] === 'site_base_url') $config['app']['base_url'] = $setting['setting_value'];
}
$GLOBALS['config'] = $config;
$router = new Router();
account_catalog_routes($router, $pdo, $config);
$router->get('/health', function() use ($config) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    try {
        $version = trim((string)@file_get_contents(dirname(__DIR__) . '/VERSION'));
        $pdo = Database::connect($config);
        $pdo->query('SELECT 1');
        echo json_encode(array('ok'=>true,'version'=>$version,'database'=>'ok'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(503);
        echo json_encode(array('ok'=>false,'error'=>'health_check_failed'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
});
function user_nav_icon($name) {
    $paths = array(
        'home'=>'<path d="m3 11 9-8 9 8"/><path d="M5 10v10h14V10M9 20v-6h6v6"/>',
        'shop'=>'<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.3 3 14.7 0 18M12 3c-3 3.3-3 14.7 0 18"/>',
        'benefits'=>'<path d="m13 2-8 12h7l-1 8 8-12h-7l1-8Z"/>',
        'downloads'=>'<circle cx="6" cy="6" r="2"/><circle cx="18" cy="6" r="2"/><circle cx="12" cy="18" r="2"/><path d="m7.7 7.1 3.2 8.8M16.3 7.1l-3.2 8.8M8 6h8"/>',
        'help'=>'<circle cx="12" cy="12" r="9"/><path d="M9.7 9a2.5 2.5 0 1 1 3.5 2.3c-.8.4-1.2.9-1.2 1.7M12 17h.01"/>',
        'orders'=>'<path d="M6 8h12l1 12H5L6 8Z"/><path d="M9 9V6a3 3 0 0 1 6 0v3"/>',
        'tickets'=>'<path d="M4 12h3l2-7 4 14 2-7h5"/>',
        'account'=>'<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'events'=>'<path d="m12 3 2.7 5.5 6.1.9-4.4 4.3 1 6-5.4-2.9-5.4 2.9 1-6-4.4-4.3 6.1-.9L12 3Z"/>',
        'logout'=>'<path d="M9 4H4v16h5M10 12h11m-4-4 4 4-4 4"/>',
        'bell'=>'<path d="M18 9a6 6 0 1 0-12 0c0 4.2-1.5 5.4-1.5 5.4h15S18 13.2 18 9Z"/><path d="M10.3 18a2 2 0 0 0 3.4 0"/>'
    );
    return '<svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'.(isset($paths[$name])?$paths[$name]:'').'</svg>';
}
function user_nav_item($href, $label, $icon, $active=false, $badge='', $sub=false) {
    return '<a class="user-nav-link'.($active?' active':'').($sub?' user-nav-sub':'').'" href="'.e($href).'"'.($active?' aria-current="page"':'').'>'.($sub?'<i aria-hidden="true"></i>':user_nav_icon($icon)).'<span>'.e($label).'</span>'.($badge!==''?'<em class="user-nav-chip'.($badge==='NEW'?' user-nav-new':'').'">'.e($badge).'</em>':'').'</a>';
}
function home_outline_icon($name)
{
    $paths = array(
        'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>',
        'cloud' => '<path d="M6 19a4 4 0 0 1-.7-7.9A6.5 6.5 0 0 1 18 9a5 5 0 0 1 0 10H6Z"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><path d="M3 12h18M5 7.5h14M5 16.5h14"/>',
        'apple' => '<path d="M12.5 6c.1-2.4 1.5-4 3.6-4-.1 2.2-1.5 3.8-3.6 4ZM12 8c-2.5-1.8-6.4-1-7 3.5-.5 3.6 2.3 9.5 4.2 9.5 1.2 0 1.5-.7 2.8-.7s1.7.7 2.8.7c1.5 0 3.3-2.5 4.2-4.8-3.4-1.7-3.4-5.6-.5-7.3-2.1-2-4.3-2-6.5-.9Z"/>',
        'send' => '<path d="m3 10 18-7-5 18-5-7-8-4Zm8 4L21 3m-10 11-1 6 4-3"/>',
        'cube' => '<path d="m12 2 9 5v10l-9 5-9-5V7l9-5Zm0 10 9-5M3 7l9 5v10M7.5 4.5l9 5"/>',
        'layers' => '<path d="m12 3 10 5-10 5L2 8l10-5Zm-9 9 9 5 9-5M3 16l9 5 9-5"/>',
        'windows' => '<path d="m3 5 8-1v7H3V5Zm10-1 8-1v8h-8V4ZM3 13h8v7l-8-1v-6Zm10 0h8v8l-8-1v-7Z"/>',
        'android' => '<path d="m7 5-2-3m12 3 2-3M5 11a7 7 0 0 1 14 0H5Zm0 2h14v7H5v-7Zm3 7v2m8-2v2M2 13v5m20-5v5"/><path d="M9 8h.01M15 8h.01"/>',
        'linux' => '<path d="M8 10c-2 2-3 5-2 8m10-8c2 2 3 5 2 8M9 10V6a3 3 0 0 1 6 0v4c0 3 3 5 2 9-2 2-8 2-10 0-1-4 2-6 2-9Z"/><path d="m10 10 2 2 2-2M7 18l-4 3h6m8-3 4 3h-6M11 7h.01M13 7h.01"/>',
    );
    return '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round">'.(isset($paths[$name]) ? $paths[$name] : $paths['globe']).'</svg>';
}

function page($title, $body, $config) {
    global $pdo;
    $user = !empty($_SESSION['user_id']); $admin = !empty($_SESSION['admin_id']);
    $unread = 0;
    if ($user) $unread=(new NotificationService($pdo))->unreadCount((int)$_SESSION['user_id']);
    $ok = get_flash('success'); $err = get_flash('error');
    $path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
    $isVpnDetail = (bool)preg_match('~^/products/[0-9]+$~', $path);
    $isAccountDetail = (bool)preg_match('~^/accounts/[0-9]+$~', $path);
    $isEditorialDetail = $isVpnDetail || $isAccountDetail;
    $isEditorialDownloads = $path === '/downloads';
    $isEditorialHelp = $path === '/articles';
    $notice = ($ok ? '<div class="notice success">'.e($ok).'</div>' : '').($err ? '<div class="notice error">'.e($err).'</div>' : '');
    if ($path === '/admin/login') {
        admin_login_page($body, $notice, $config);
        return;
    }
    if (strpos($path, '/admin') === 0 && $path !== '/admin/login') {
        admin_page($title, $body, $notice, $path, $pdo, $config);
        return;
    }
    if (in_array($path, array('/login','/register','/password/forgot','/password/reset'), true)) {
        auth_page($title, $body, $notice, $config);
        return;
    }
    if (preg_match('~^/orders/[^/]+$~', $path)) {
        account_overview_shell($title, $body, $notice, $unread, $pdo, $config, '/orders', 'order-detail');
        return;
    }
    if (in_array($path, array('/account/overview','/account','/account/security','/orders','/account/subscriptions','/wallet','/notifications','/tickets','/feedback'), true) || ($path==='/events' && ($_GET['view']??null)==='referral')) {
        account_overview_shell($title, $body, $notice, $unread, $pdo, $config, $path==='/events'?'/events?view=referral':($path==='/account'?'/account/security':$path));
        return;
    }
    $pageClasses = array('/'=>'home-page','/products'=>'products-page','/order-confirm'=>'checkout-body','/downloads'=>'downloads-page','/orders'=>'orders-page','/articles'=>'articles-page','/tickets'=>'tickets-page','/account'=>'account-page-body','/account/security'=>'account-page-body','/account/notifications'=>'account-page-body','/benefits'=>'benefits-page','/events'=>'events-page','/login'=>'auth-page','/register'=>'auth-page','/admin/login'=>'admin-login-body');
    $pageClass = isset($pageClasses[$path]) ? $pageClasses[$path] : (strpos($path, '/products/') === 0 ? 'products-page' : '');
    $isHelpCenter = in_array($path,array('/articles','/announcements','/downloads'),true) || preg_match('~^/(articles|announcements)/[^/]+$~',$path);
    if ($isHelpCenter && !$isEditorialDownloads && !$isEditorialHelp) $pageClass .= ' help-reference-body';
    $desktopEvents = $path === '/events' && (!isset($_GET['view']) || !is_string($_GET['view']) || !in_array($_GET['view'], array('lottery','referral','group','free'), true));
    if (in_array($path, array('/','/products','/accounts'), true) || $desktopEvents || $isEditorialDownloads || $isEditorialHelp) $pageClass .= ' pc-storefront';
    if ($path === '/accounts') $pageClass .= ' accounts-page';
    $desktopDetail = preg_match('~^/(products|accounts)/[0-9]+$~', $path);
    $desktopAccount = in_array($path, array('/account/overview','/account/subscriptions','/wallet','/orders','/notifications','/account','/account/security','/account/notifications'), true) || preg_match('~^/orders/[^/]+$~', $path) || preg_match('~^/(tickets|feedback)(/|$)~', $path);
    if ($desktopDetail || $path === '/order-confirm') $pageClass .= ' pc-commerce';
    if ($isEditorialDetail) $pageClass .= ' pc-storefront '.($isAccountDetail ? 'account-detail-page' : 'vpn-detail-page');
    if ($desktopAccount) $pageClass .= ' pc-account';
    if ($path === '/account/overview') $pageClass .= ' pc-overview';
    if ($path === '/account/subscriptions') $pageClass .= ' pc-subscriptions';
    if ($desktopEvents) $pageClass .= ' events-overview-page';
    if ($isEditorialHelp) $pageClass .= ' help-overview-page';
    if (preg_match('~^/orders/[^/]+/payment-result$~', $path)) $pageClass = 'payment-result-body';
    if ($path === '/tickets' || strpos($path, '/tickets/') === 0) $pageClass .= ' pc-tickets';
    if ($path === '/feedback' || strpos($path, '/feedback/') === 0) $pageClass .= ' pc-feedback';
    if ($path !== '/' && $path !== '/products' && $path !== '/accounts' && !$isEditorialDetail && !$desktopEvents && !$isEditorialDownloads && !$isEditorialHelp && $path !== '/order-confirm' && $pageClass !== 'payment-result-body') $pageClass .= ' user-ui';
    $productCount = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE status="active" AND '.catalog_vpn_condition($pdo))->fetchColumn();
    $sectionNav = '<span class="user-nav-label">服务</span>'
        .user_nav_item('/','首页','home',$path==='/')
        .user_nav_item('/products','商城','shop',strpos($path,'/products')===0,(string)$productCount)
        .user_nav_item('/accounts','海外账号','account',strpos($path,'/accounts')===0)
        .user_nav_item('/benefits','每日公益节点','benefits',$path==='/benefits','NEW')
        .user_nav_item('/downloads','软件下载','downloads',$path==='/downloads')
        .user_nav_item('/articles','使用教程','help',strpos($path,'/articles')===0);
    $accountNav = '<span class="user-nav-label user-nav-label-space">账户与支持</span>'
        .user_nav_item('/orders','订单管理','orders',strpos($path,'/orders')===0)
        .user_nav_item('/wallet','我的余额','account',$path==='/wallet')
        .user_nav_item('/tickets','售后工单','tickets',strpos($path,'/tickets')===0)
        .user_nav_item('/notifications','消息中心','help',strpos($path,'/notifications')===0,$unread?(string)$unread:'')
        .user_nav_item('/announcements','系统与维护通知','help',strpos($path,'/announcements')===0)
        .user_nav_item('/feedback','反馈建议','help',strpos($path,'/feedback')===0)
        .user_nav_item('/account','账户设置','account',in_array($path,array('/account','/account/security','/account/notifications'),true));
    $eventView = isset($_GET['view']) ? $_GET['view'] : '';
    $eventNav = '<span class="user-nav-label user-nav-label-space">会员活动</span>'
        .user_nav_item('/events','活动中心','events',$path==='/events'&&$eventView==='')
        .user_nav_item('/events?view=lottery','活动抽奖','',$path==='/events'&&$eventView==='lottery','',true)
        .user_nav_item('/events?view=referral','拉人返佣','',$path==='/events'&&$eventView==='referral','',true)
        .user_nav_item('/events?view=group','好友拼单','',$path==='/events'&&$eventView==='group','',true)
        .user_nav_item('/events?view=free','团长免单','',$path==='/events'&&$eventView==='free','',true);
    $identity = $user ? (isset($_SESSION['user_name']) ? $_SESSION['user_name'] : '我的账户') : '访客';
    $authAction = $user ? '<form method="post" action="/logout" class="inline"><input type="hidden" name="_csrf" value="'.e(Security::csrfToken()).'"><button class="user-logout" type="submit">退出登录</button></form>' : '<a class="user-top-login" href="/login">登录 / 注册</a>';
    $adminLink = $admin ? '<a class="user-admin-link" href="/admin">管理后台</a>' : '';
    $announcementBanner='';
    foreach((new AnnouncementService($pdo))->listActive() as $announcement){
        $announcementBanner.='<a class="service-announcement-banner" href="/announcements/'.(int)$announcement['id'].'"><span>'.($announcement['type']==='maintenance'?'维护通知':'系统公告').' · 生效中</span><b>'.e($announcement['title']).'</b><small>查看详情 →</small></a>';
    }
    $body=$announcementBanner.$body;
    $isShopPath = $path === '/products' || strpos($path, '/products/') === 0;
    $isPublic = $isHelpCenter || $desktopEvents || $path === '/' || $isShopPath || $path === '/accounts' || strpos($path,'/accounts/') === 0 || in_array($path,array('/order-confirm','/downloads','/benefits'),true) || preg_match('~^/orders/[^/]+/payment-result$~',$path);
    $pageClass .= $isPublic ? ' public-page' : ' workspace-page';
    $breadcrumb = '';
    $isHome = $isPublic;
    $homeNav = '';
    $homeItems = array(
        array('/', '首页', $path === '/'),
        array('/products', 'VPN 订阅', strpos($path, '/products') === 0),
        array('/accounts', '海外账号', strpos($path, '/accounts') === 0),
        array('/events', '活动', $path === '/events'),
        array('/downloads', '软件下载', $path === '/downloads'),
        array('/articles', '帮助中心', strpos($path, '/articles') === 0 || strpos($path, '/announcements') === 0),
    );
    foreach ($homeItems as $item) {
        $homeNav .= '<a class="home-nav-link'.($item[2] ? ' is-active' : '').'" href="'.e($item[0]).'"'.($item[2] ? ' aria-current="page"' : '').'>'.e($item[1]).'</a>';
    }
    $isAccountSearch=$path==='/accounts'||strpos($path,'/accounts/')===0;
    $homeSearchTerm=$isAccountSearch&&isset($_GET['q'])&&is_string($_GET['q'])&&mb_check_encoding($_GET['q'],'UTF-8')&&mb_strlen(trim($_GET['q']),'UTF-8')<=100?trim($_GET['q']):'';
    $homeSearchPlatform=$path==='/accounts'&&isset($_GET['platform'])&&is_string($_GET['platform'])&&strlen($_GET['platform'])<=32?trim($_GET['platform']):'';
    $homeSearchLabel=$isAccountSearch?'搜索海外账号':'搜索订阅套餐';
    $homeRight = '<form class="home-nav-search" action="'.($isAccountSearch?'/accounts':'/products').'" method="get" role="search"><button type="submit" aria-label="'.($isAccountSearch?'搜索海外账号':'搜索套餐').'"><svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/></svg></button><input type="search" name="q" placeholder="'.$homeSearchLabel.'" aria-label="'.$homeSearchLabel.'"'.($isAccountSearch?' value="'.e($homeSearchTerm).'" maxlength="100"':'').'>'.($homeSearchPlatform!==''?'<input type="hidden" name="platform" value="'.e($homeSearchPlatform).'">':'').'</form>';
    if ((in_array($path, array('/', '/products', '/accounts'), true) || $isEditorialDetail || $desktopEvents || $isEditorialDownloads || $isEditorialHelp)) $homeRight = '<details class="editorial-nav-search"><summary aria-label="展开套餐搜索">'.home_outline_icon('search').'</summary><form action="/products" method="get" role="search"><label for="editorial-search">搜索订阅套餐</label><div><input id="editorial-search" type="search" name="q" placeholder="输入套餐名称" maxlength="100"><button type="submit">搜索 →</button></div></form></details>';
    $homeSearchSort=$path==='/accounts'&&isset($_GET['sort'])&&is_string($_GET['sort'])&&in_array($_GET['sort'],array('price_asc','price_desc'),true)?$_GET['sort']:'';
    if ($path === '/accounts' || $isAccountDetail) $homeRight = '<details class="editorial-nav-search"><summary aria-label="展开账号搜索">'.home_outline_icon('search').'</summary><form action="/accounts" method="get" role="search">'.($homeSearchPlatform!==''?'<input type="hidden" name="platform" value="'.e($homeSearchPlatform).'">':'').($homeSearchSort!==''?'<input type="hidden" name="sort" value="'.e($homeSearchSort).'">':'').'<label for="editorial-account-search">搜索海外账号</label><div><input id="editorial-account-search" type="search" name="q" value="'.e($homeSearchTerm).'" placeholder="输入账号商品名称" maxlength="100"><button type="submit">搜索 →</button></div></form></details>';
    if ($isHelpCenter && !$isEditorialDownloads && !$isEditorialHelp) $homeRight = '<form class="home-nav-search" action="/articles" method="get" role="search"><button type="submit" aria-label="搜索帮助文章">'.help_icon('search').'</button><input type="search" name="q" maxlength="100" placeholder="搜索帮助文章" aria-label="搜索帮助文章"></form>';
    if ($isEditorialHelp) {
        $helpNavTerm=isset($_GET['q'])&&is_string($_GET['q'])&&mb_check_encoding($_GET['q'],'UTF-8')&&mb_strlen($_GET['q'],'UTF-8')<=100?trim($_GET['q']):'';
        $helpNavCategory=isset($_GET['category'])&&is_string($_GET['category'])&&isset(help_categories()[$_GET['category']])?$_GET['category']:'';
        $homeRight='<details class="editorial-nav-search"><summary aria-label="展开帮助搜索">'.home_outline_icon('search').'</summary><form action="/articles" method="get" role="search"><input type="hidden" name="view" value="all">'.($helpNavCategory!==''?'<input type="hidden" name="category" value="'.e($helpNavCategory).'">':'').'<label for="editorial-help-search">搜索帮助文章</label><div><input id="editorial-help-search" type="search" name="q" value="'.e($helpNavTerm).'" placeholder="输入问题或关键词" maxlength="100"><button type="submit">搜索 →</button></div></form></details>';
    }
    if ($user) {
        $homeRight .= '<a class="home-nav-bell" href="/notifications" aria-label="消息中心">'.user_nav_icon('bell').($unread ? '<b>'.$unread.'</b>' : '').'</a>';
        $homeRight .= '<a class="home-nav-user" href="/account"><span>'.e(mb_substr($identity, 0, 1, 'UTF-8')).'</span><b>'.e($identity).'</b></a>';
        $homeRight .= '<form method="post" action="/logout" class="home-nav-logout"><input type="hidden" name="_csrf" value="'.e(Security::csrfToken()).'"><button type="submit">退出</button></form>';
        $homeRight .= '<a class="home-nav-cta" href="/products">立即选购</a>';
    } else {
        $homeRight .= '<a class="home-nav-login" href="/login">登录 / 注册</a>';
        $homeRight .= '<a class="home-nav-cta" href="/register">免费注册</a>';
    }
    $homeBrand = (in_array($path, array('/', '/products', '/accounts'), true) || $isEditorialDetail || $desktopEvents || $isEditorialDownloads || $isEditorialHelp) ? home_outline_icon('cloud').'<span>'.e($config['app']['name']).'</span>' : '<img src="/assets/xiaoyun-logo.png" alt="'.e($config['app']['name']).'">';
    $homeShell = '<header class="home-topnav"><div class="home-topnav-inner"><a class="home-nav-brand" href="/">'.$homeBrand.'</a><nav class="home-nav" aria-label="主导航">'.$homeNav.'</nav><div class="home-nav-actions">'.$homeRight.'<button class="home-nav-toggle" type="button" aria-label="打开导航" aria-expanded="false">☰</button></div></div><div class="home-nav-drawer"><nav class="home-drawer-nav">'.$sectionNav.$accountNav.$eventNav.'</nav></div></header>';
    $workspaceNav='';
    foreach(array(array('/account/overview','用户控制台','dashboard',$path==='/account/overview'),array('/account/subscriptions','我的订阅','radio',$path==='/account/subscriptions'),array('/events','活动中心','events',$path==='/events'),array('/orders','订单与交付','receipt',strpos($path,'/orders')===0),array('/articles','帮助与工单','help',strpos($path,'/articles')===0||strpos($path,'/tickets')===0)) as $item){
        $workspaceNav.='<a class="workspace-nav-link'.($item[3]?' is-active':'').'" href="'.$item[0].'"'.($item[3]?' aria-current="page"':'').'>'.($item[2]==='events'?user_nav_icon('events'):'<img src="/assets/space-'.$item[2].'.svg" width="18" height="18" alt="">').$item[1].'</a>';
    }
    $workspaceMore='<details class="workspace-more"'.(!in_array($path,array('/orders','/account/overview','/account/subscriptions','/articles','/tickets'),true)&&strpos($path,'/orders/')!==0?' open':'').'><summary>更多账户服务</summary><nav aria-label="其他账户服务">'.$accountNav.str_replace(user_nav_item('/events','活动中心','events',$path==='/events'&&$eventView===''),'',$eventNav).'</nav></details>';
    $shellOpen = $isHome
        ? '<div class="user-shell home-shell">'.$homeShell.'<div class="user-main-column home-main-column">'
        : '<div class="user-shell workspace-shell"><aside class="user-sidebar"><a class="user-brand" href="/"><img src="/assets/xiaoyun-logo.png" alt="'.e($config['app']['name']).'"></a><nav class="workspace-primary-nav" aria-label="个人空间"><span class="user-nav-label">个人空间</span>'.$workspaceNav.'</nav><div class="workspace-shop-note"><b>需要新的订阅？</b><p>按需选择套餐，在一个地方管理购买与交付。</p><a href="/products">浏览套餐 →</a></div>'.$workspaceMore.'<div class="workspace-sidebar-bottom"><a class="workspace-return" href="/">← 返回官网</a><a class="user-sidebar-profile" href="'.($user?'/account':'/login').'"><span class="user-profile-avatar">'.e(mb_substr($identity,0,1,'UTF-8')).'</span><span class="user-profile-copy"><b>'.e($identity).'</b><small>'.($user?'个人账户':'欢迎访问').'</small></span></a></div></aside><div class="user-main-column"><header class="user-topbar"><a class="workspace-mobile-brand" href="/"><img src="/assets/xiaoyun-logo.png" alt="'.e($config['app']['name']).'"></a><div class="workspace-breadcrumb"><a href="/account/overview">个人中心</a><span>/</span><span>'.e($title).'</span></div><div class="user-top-actions"><span class="workspace-service-label">人工交付服务</span><a class="workspace-bell" href="/notifications" aria-label="消息中心"><img src="/assets/space-bell.svg" width="20" height="20" alt="">'.($unread?'<b>'.$unread.'</b>':'').'</a><time datetime="'.date('Y-m-d').'">'.date('Y-m-d').'</time>'.$adminLink.$authAction.'</div><button class="user-menu-toggle" type="button" aria-label="打开导航" aria-expanded="false">☰</button></header>';
    if ($desktopAccount) {
        $desktopNav = '';
        foreach (array(array('/account/overview','个人总览','home'),array('/orders','我的订单','orders'),array('/account/subscriptions','我的订阅','shop'),array('/wallet','我的余额','account'),array('/notifications','消息中心','bell'),array('/tickets','工单支持','tickets'),array('/feedback','意见反馈','help'),array('/account','个人资料','account')) as $item) {
            $selected = $path === $item[0] || (in_array($item[0],array('/orders','/tickets','/feedback'),true) && strpos($path,$item[0].'/') === 0);
            $desktopNav .= user_nav_item($item[0],$item[1],$item[2],$selected);
        }
        $shellOpen = '<div class="desktop-account-header">'.$homeShell.'</div>'.str_replace('<nav class="workspace-primary-nav"', '<nav class="desktop-account-nav" aria-label="账户导航"><span class="user-nav-label">个人中心</span>'.$desktopNav.'</nav><nav class="workspace-primary-nav"', $shellOpen);
    }
    if ($desktopEvents && !$isHome) $shellOpen = '<div class="desktop-events-header">'.$homeShell.'</div>'.$shellOpen;
    $publicMobileNav='<nav class="public-mobile-nav" aria-label="快捷导航"><a href="/"'.($path==='/'?' aria-current="page"':'').'><img src="/assets/mobile-home.svg" alt="">首页</a><a href="/products"'.($isShopPath?' aria-current="page"':'').'><img src="/assets/mobile-shop.svg" alt="">套餐商店</a><a class="mobile-event-entry" href="/events"'.($path==='/events'?' aria-current="page"':'').'><span class="mobile-event-icon">'.user_nav_icon('events').'</span>活动</a><a href="/accounts"'.(strpos($path,'/accounts')===0?' aria-current="page"':'').'><img src="/assets/mobile-globe.svg" alt="">海外账号</a><a href="/account/overview"'.(!$isPublic&&$path!=='/events'?' aria-current="page"':'').'><img src="/assets/mobile-user.svg" alt="">个人中心</a></nav>';
    $mobileNav=$path==='/order-confirm'||($isShopPath&&$path!=='/products')?'':$publicMobileNav;
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="icon" type="image/png" href="/assets/site-icon.png?v=xiaoyun-icon-20261001"><link rel="apple-touch-icon" href="/assets/site-icon.png?v=xiaoyun-icon-20261001"><title>'.e($title).' - '.e($config['app']['name']).'</title><link rel="stylesheet" href="/assets/style.css?v='.filemtime(__DIR__.'/assets/style.css').'">'.($path==='/'?'<link rel="stylesheet" href="/assets/home-editorial.css?v='.filemtime(__DIR__.'/assets/home-editorial.css').'">':'').($path==='/products'||$path==='/accounts'||$isEditorialDetail||$desktopEvents||$isEditorialDownloads||$isEditorialHelp?'<link rel="stylesheet" href="/assets/products-editorial.css?v='.filemtime(__DIR__.'/assets/products-editorial.css').'">':'').($isEditorialHelp?'<link rel="stylesheet" href="/assets/help-editorial.css?v='.filemtime(__DIR__.'/assets/help-editorial.css').'">':'').($isEditorialDownloads?'<link rel="stylesheet" href="/assets/downloads-editorial.css?v='.filemtime(__DIR__.'/assets/downloads-editorial.css').'">':'').($desktopEvents?'<link rel="stylesheet" href="/assets/events-editorial.css?v='.filemtime(__DIR__.'/assets/events-editorial.css').'">':'').($isEditorialDetail?'<link rel="stylesheet" href="/assets/vpn-detail-editorial.css?v='.filemtime(__DIR__.'/assets/vpn-detail-editorial.css').'">':'').'<link rel="stylesheet" href="/assets/customer-warm.css?v='.filemtime(__DIR__.'/assets/customer-warm.css').'"></head><body class="user-page '.e($pageClass).'" data-page-title="'.e($title).'">'.$shellOpen.'<main class="user-main">'.$notice.$breadcrumb.$body.'</main><footer class="user-footer">人工确认付款 · 人工交付 · 请勿在公开场合分享交付信息</footer></div></div>'.$mobileNav.'<script>document.querySelectorAll(".public-mobile-nav a,.home-nav a").forEach(function(link){link.addEventListener("click",function(event){if(!event.ctrlKey&&!event.metaKey&&!event.shiftKey&&!event.altKey&&event.button===0&&link.href===location.href)event.preventDefault()})});document.querySelectorAll("[data-confirm]").forEach(function(e){e.addEventListener("click",function(t){if(!confirm(e.dataset.confirm))t.preventDefault()})});var userMenu=document.querySelector(".user-menu-toggle");if(userMenu)userMenu.addEventListener("click",function(){var opened=document.body.classList.toggle("user-sidebar-open");userMenu.setAttribute("aria-expanded",String(opened));userMenu.setAttribute("aria-label",opened?"关闭导航":"打开导航")});document.addEventListener("click",function(event){if(!document.body.classList.contains("user-sidebar-open")||event.target.closest(".user-sidebar")||event.target.closest(".user-menu-toggle"))return;document.body.classList.remove("user-sidebar-open");userMenu.setAttribute("aria-expanded","false");userMenu.setAttribute("aria-label","打开导航")});document.addEventListener("keydown",function(event){if(event.key!=="Escape"||!document.body.classList.contains("user-sidebar-open"))return;document.body.classList.remove("user-sidebar-open");userMenu.setAttribute("aria-expanded","false");userMenu.setAttribute("aria-label","打开导航");userMenu.focus()});var homeMenu=document.querySelector(".home-nav-toggle");function closeHomeNav(){document.body.classList.remove("home-nav-open");if(homeMenu){homeMenu.setAttribute("aria-expanded","false");homeMenu.setAttribute("aria-label","打开导航")}}if(homeMenu){homeMenu.addEventListener("click",function(){var opened=document.body.classList.toggle("home-nav-open");homeMenu.setAttribute("aria-expanded",String(opened));homeMenu.setAttribute("aria-label",opened?"关闭导航":"打开导航")});document.addEventListener("click",function(event){if(!document.body.classList.contains("home-nav-open")||event.target.closest(".home-topnav"))return;closeHomeNav()});document.addEventListener("keydown",function(event){if(event.key==="Escape"&&document.body.classList.contains("home-nav-open")){closeHomeNav();homeMenu.focus()}});document.querySelectorAll(".home-nav-drawer a").forEach(function(link){link.addEventListener("click",closeHomeNav)})}document.querySelectorAll("form[data-submit-lock]").forEach(function(form){var button=form.querySelector("[type=submit]");if(!button)return;var label=button.getAttribute("data-idle-label")||button.textContent;var hint=form.querySelector(".pc-submit-hint");form.addEventListener("submit",function(){if(!form.checkValidity())return;button.disabled=true;button.setAttribute("aria-busy","true");button.textContent="提交中…";if(hint)hint.hidden=false});window.addEventListener("pageshow",function(){button.disabled=false;button.removeAttribute("aria-busy");button.textContent=label;if(hint)hint.hidden=true})})</script></body></html>';
}
function account_overview_shell($title, $body, $notice, $unread, $pdo, $config, $active='/account/overview', $variant='') {
    $uid=(int)$_SESSION['user_id'];
    $s=$pdo->prepare('SELECT display_name,email FROM users WHERE id=?'); $s->execute(array($uid)); $profile=$s->fetch();
    $name=$profile['display_name']?:$profile['email'];
    $brand=home_outline_icon('cloud').'<b>'.e($config['app']['name']).'</b>';
    $nav='';
    $navPath = parse_url($active, PHP_URL_PATH);
    foreach (array('/'=>'首页','/products'=>'VPN 订阅','/accounts'=>'海外账号','/events'=>'活动','/downloads'=>'软件下载','/articles'=>'帮助中心') as $url=>$label) $nav.='<a href="'.$url.'"'.($navPath===$url?' aria-current="page"':'').'>'.$label.'</a>';
    $sidebar='<aside class="ao-sidebar" id="ao-sidebar"><h2>个人中心</h2><div class="ao-profile"><span class="ao-avatar" aria-hidden="true">'.user_nav_icon('account').'</span><div><b>'.e($name).'</b><small>'.e($profile['email']).'</small><small>UID：'.$uid.'</small></div></div>';
    foreach (array('我的账户'=>array('/account/overview'=>'个人总览','/orders'=>'我的订单','/account/subscriptions'=>'我的订阅','/wallet'=>'我的余额'),'消息与服务'=>array('/notifications'=>'消息中心','/tickets'=>'售后工单','/feedback'=>'反馈建议'),'活动与安全'=>array('/events?view=referral'=>'拉人返佣','/account/security'=>'账户与安全')) as $label=>$links) {
        $sidebar.='<nav class="ao-account-nav" aria-label="'.$label.'"><span>'.$label.'</span>';
        foreach ($links as $url=>$text) $sidebar.='<a href="'.e($url).'"'.($url===$active?' aria-current="page"':'').'>'.$text.($url==='/notifications'&&$unread?'<b class="ao-count">'.(int)$unread.'</b>':'').'</a>';
        $sidebar.='</nav>';
    }
    $sidebar.='<nav class="ao-site-links" aria-label="网站导航">'.$nav.'</nav><form class="ao-logout" method="post" action="/logout">'.csrf_field().'<button type="submit">'.user_nav_icon('logout').'退出登录</button></form></aside>';
    $accountMenu='<details class="ao-account-menu"><summary>'.user_nav_icon('account').'<span>我的账户</span><i aria-hidden="true"></i></summary><nav aria-label="账户快捷入口"><a href="/account/overview"'.($active==='/account/overview'?' aria-current="page"':'').'>个人总览</a><a href="/account">个人资料</a><a href="/account/security">账户安全</a><a href="/orders"'.($active==='/orders'?' aria-current="page"':'').'>我的订单</a></nav></details>';
    $mobileNav='<nav class="public-mobile-nav" aria-label="快捷导航"><a href="/"><img src="/assets/mobile-home.svg" alt="">首页</a><a href="/products"><img src="/assets/mobile-shop.svg" alt="">套餐商店</a><a class="mobile-event-entry" href="/events"'.(strpos($active,'/events')===0?' aria-current="page"':'').'><span class="mobile-event-icon">'.user_nav_icon('events').'</span>活动</a><a href="/accounts"><img src="/assets/mobile-globe.svg" alt="">海外账号</a><a href="/account/overview"'.(strpos($active,'/events')!==0?' aria-current="page"':'').'><img src="/assets/mobile-user.svg" alt="">个人中心</a></nav>';
    $footer='<footer class="ao-footer"><a href="/">'.$brand.'</a><p>数字服务，简单一点。</p><nav aria-label="页脚导航"><a href="/announcements">系统公告</a><a href="/articles">帮助中心</a></nav><small>© '.date('Y').' '.e($config['app']['name']).'</small></footer>';
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="icon" type="image/png" href="/assets/site-icon.png"><title>'.e($title).' - '.e($config['app']['name']).'</title><link rel="stylesheet" href="/assets/style.css?v='.filemtime(__DIR__.'/assets/style.css').'"><link rel="stylesheet" href="/assets/account-overview-editorial.css?v='.filemtime(__DIR__.'/assets/account-overview-editorial.css').'">'.($active==='/orders'&&$variant!=='order-detail'?'<link rel="stylesheet" href="/assets/account-orders-editorial.css?v='.filemtime(__DIR__.'/assets/account-orders-editorial.css').'">':'').($active==='/account/subscriptions'?'<link rel="stylesheet" href="/assets/account-subscriptions-editorial.css?v='.filemtime(__DIR__.'/assets/account-subscriptions-editorial.css').'">':'').($active==='/wallet'?'<link rel="stylesheet" href="/assets/account-wallet-editorial.css?v='.filemtime(__DIR__.'/assets/account-wallet-editorial.css').'">':'').($active==='/notifications'?'<link rel="stylesheet" href="/assets/account-messages-editorial.css?v='.filemtime(__DIR__.'/assets/account-messages-editorial.css').'">':'').($active==='/tickets'?'<link rel="stylesheet" href="/assets/account-tickets-editorial.css?v='.filemtime(__DIR__.'/assets/account-tickets-editorial.css').'">':'').($active==='/feedback'?'<link rel="stylesheet" href="/assets/account-feedback-editorial.css?v='.filemtime(__DIR__.'/assets/account-feedback-editorial.css').'">':'').($active==='/events?view=referral'?'<link rel="stylesheet" href="/assets/account-referral-editorial.css?v='.filemtime(__DIR__.'/assets/account-referral-editorial.css').'">':'').($active==='/account/security'?'<link rel="stylesheet" href="/assets/account-settings-editorial.css?v='.filemtime(__DIR__.'/assets/account-settings-editorial.css').'">':'').($variant==='order-detail'?'<link rel="stylesheet" href="/assets/order-detail-editorial.css?v='.filemtime(__DIR__.'/assets/order-detail-editorial.css').'">':'').'<link rel="stylesheet" href="/assets/customer-warm.css?v='.filemtime(__DIR__.'/assets/customer-warm.css').'"></head><body class="user-page account-overview-editorial'.($active==='/orders'&&$variant!=='order-detail'?' account-orders-editorial':'').($active==='/account/subscriptions'?' account-subscriptions-editorial':'').($active==='/wallet'?' account-wallet-editorial':'').($active==='/notifications'?' account-messages-editorial':'').($active==='/tickets'?' account-tickets-editorial':'').($active==='/feedback'?' account-feedback-editorial':'').($active==='/events?view=referral'?' account-referral-editorial':'').($active==='/account/security'?' account-settings-editorial':'').($variant==='order-detail'?' order-detail-editorial':'').'"><header class="ao-header"><div><a class="ao-brand" href="/">'.$brand.'</a><nav class="ao-main-nav" aria-label="主导航">'.$nav.'</nav><div class="ao-header-actions"><a class="ao-bell" href="/notifications" aria-label="消息中心'.($unread?'，'.(int)$unread.'条未读':'').'">'.user_nav_icon('bell').($unread?'<i aria-hidden="true"></i>':'').'</a>'.$accountMenu.'<button class="ao-menu-toggle" type="button" aria-controls="ao-sidebar" aria-expanded="false" aria-label="打开个人导航">☰</button></div></div></header><div class="ao-layout">'.$sidebar.'<main class="ao-main">'.$notice.$body.'</main></div>'.$footer.$mobileNav.'<script>(function(){var button=document.querySelector(".ao-menu-toggle");function close(){document.body.classList.remove("ao-navigation-open");button.setAttribute("aria-expanded","false");button.setAttribute("aria-label","打开个人导航")}button.addEventListener("click",function(){var open=document.body.classList.toggle("ao-navigation-open");button.setAttribute("aria-expanded",String(open));button.setAttribute("aria-label",open?"关闭个人导航":"打开个人导航")});document.addEventListener("keydown",function(event){if(event.key==="Escape"){if(document.body.classList.contains("ao-navigation-open")){close();button.focus()}document.querySelector(".ao-account-menu").open=false}})})();</script></body></html>';
}
function auth_icon($name, $size = 20) {
    return '<img src="/assets/auth-'.$name.'.svg" width="'.$size.'" height="'.$size.'" alt="" aria-hidden="true">';
}
function auth_page($title, $body, $notice, $config) {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $register = $path === '/register';
    $forgot = $path === '/password/forgot';
    $reset = $path === '/password/reset';
    $authFlow = $register ? 'register' : ($forgot ? 'forgot' : ($reset ? 'reset' : 'login'));
    $heading = $register ? '创建'.e($config['app']['name']).'账户' : ($forgot ? '找回账户密码' : ($reset ? '设置新密码' : '欢迎回到'.e($config['app']['name'])));
    $nav = '<a href="/">首页</a><a href="/products">VPN 订阅</a><a href="/accounts">海外账号</a><a href="/events">活动</a><a href="/downloads">软件下载</a><a href="/articles">帮助中心</a>';
    // 复用真实表单，保留 CSRF 字段、推荐关系字段及浏览器校验约束。
    $start = strpos($body, '<form ');
    $end = strpos($body, '</form>', $start);
    $form = substr($body, $start, $end + 7 - $start);
    $form = str_replace('<form method="post">', '<form method="post" action="'.($register ? '/register' : ($forgot ? '/password/forgot' : ($reset ? '/password/reset' : '/login'))).'">', $form);
    $form = str_replace('<label>邮箱', '<label>'.auth_icon('mail',17).'邮箱', $form);
    $form = str_replace('<label>密码', '<label class="auth-password-label">'.auth_icon('lock',17).'密码', $form);
    $form = str_replace('<label>确认新密码', '<label class="auth-password-label">'.auth_icon('lock',17).'确认新密码', $form);
    $form = str_replace('<label>新密码', '<label class="auth-password-label">'.auth_icon('lock',17).'新密码', $form);
    $form = preg_replace('~(<label\b[^>]*>(?:(?!</label>).)*type="password"(?:(?!</label>).)*)</label>~s', '$1<button class="auth-password-toggle" type="button" aria-label="显示密码" aria-pressed="false">'.auth_icon('eye',17).'</button></label>', $form);
    $form = str_replace('<button class="button">', '<button class="button" type="submit">', $form);
    $steps = ($forgot || $reset) ? '<ol class="auth-reset-steps" aria-label="密码找回步骤"><li'.($forgot?' aria-current="step"':'').'><b>1</b>提交请求</li><li'.($reset?' aria-current="step"':'').'><b>2</b>设置新密码</li><li><b>3</b>返回登录</li></ol>' : '';
    $resumeNote = !empty($_SESSION['purchase_return']) && in_array($authFlow, array('login','register'), true) ? '<p class="auth-resume-note" role="status">已保留你选择的方案，登录后继续核对订单；此时不会扣款。</p>' : '';
    $brandFeatures = '<div class="auth-desktop-features"><h2>'.e($config['app']['name']).'</h2><p>一个账户，管理你的数字服务</p><ul><li>'.auth_icon('radio').'<div><b>选购 VPN 订阅</b><span>选择适合自己的方案与周期</span></div></li><li>'.auth_icon('package').'<div><b>购买海外账号</b><span>查看真实规格及售后说明</span></div></li><li>'.auth_icon('key').'<div><b>管理订单与交付</b><span>关注进度，妥善保存交付内容</span></div></li><li>'.auth_icon('shield').'<div><b>获取售后支持</b><span>关联订单提交工单，人工跟进</span></div></li></ul><img class="auth-desktop-art" src="/assets/home-preview-orbit.svg" alt=""></div>';
    $brand = '<aside class="auth-brand-card">'.$brandFeatures.'<span class="auth-badge">你的订阅从这里开始</span><h2>一个账户<br>管理每一次连接</h2><p>轻松管理你的订阅与账号商品，<br>让每一次购买与交付都清晰有序。</p><div class="auth-mobile-tags"><span>便捷购买</span><span>查看交付</span><span>统一管理</span></div><div class="auth-account-preview"><div class="auth-preview-top">'.auth_icon('shield',26).'<span>ACCOUNT</span></div><div class="auth-preview-heading"><strong>你的专属账户中心</strong></div><ul><li>'.auth_icon('package').'购买</li><li>'.auth_icon('key').'交付</li><li>'.auth_icon('radio').'管理</li></ul></div><p class="auth-brand-summary">从订阅选购到订单交付，<br>在账户中心查看进度与售后服务。</p><div class="auth-brand-note">'.auth_icon('shield-small',16).'<span>交付信息仅供本人使用，请妥善保管。</span></div></aside>';
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($title).' - '.e($config['app']['name']).'</title><link rel="icon" href="/assets/site-icon.png"><link rel="stylesheet" href="/assets/style.css?v='.filemtime(__DIR__.'/assets/style.css').'"><link rel="stylesheet" href="/assets/customer-warm.css?v='.filemtime(__DIR__.'/assets/customer-warm.css').'"></head><body class="auth-page auth-flow-'.$authFlow.'"><header class="auth-header"><a class="auth-logo" href="/"><img src="/assets/xiaoyun-logo.png" alt="'.e($config['app']['name']).'"></a><nav aria-label="主导航">'.$nav.'</nav><a class="auth-header-account" href="'.($register?'/login':($authFlow==='login'?'/register':'/login')).'">'.($register?'登录账户':($authFlow==='login'?'免费注册':'返回登录')).'</a><button class="auth-menu-toggle" type="button" aria-label="打开导航" aria-expanded="false" aria-controls="auth-mobile-menu">'.auth_icon('menu').'</button><nav id="auth-mobile-menu" class="auth-mobile-menu" aria-label="移动导航" hidden>'.$nav.'</nav></header><main class="auth-main">'.$steps.$brand.'<section class="auth-form-column"><header class="auth-form-heading"><h1>'.$heading.'</h1><p>'.($register?'使用邮箱创建账户，统一管理订阅与订单。':($forgot?'填写注册邮箱，我们会在账户存在时发送重置链接。':($reset?'设置新的登录密码，重置链接在 30 分钟内有效。':'登录你的账户，管理订阅、订单与交付内容。'))).'</p></header><div class="auth-form-panel"><nav class="auth-tabs" aria-label="账户入口"><a href="/login"'.($authFlow==='login'?' aria-current="page"':'').'>登录账户</a><a href="/register"'.($authFlow==='register'?' aria-current="page"':'').'>注册新账户</a></nav>'.$resumeNote.$notice.$form.'<p class="auth-switch">'.($register?'已有账户？<a href="/login">立即登录</a>':($forgot?'想起密码了？<a href="/login">返回登录</a>':($reset?'需要重新申请？<a href="/password/forgot">找回密码</a>':'忘记密码？<a href="/password/forgot">立即找回</a>　还没有账户？<a href="/register">立即注册</a>'))).'</p></div><div class="auth-info">'.auth_icon('info').'<div><strong>账户使用提示</strong><p>请使用购买时的邮箱登录，查看订单、交付内容及售后进度。</p></div></div><div class="auth-security">'.auth_icon('shield-small',16).'<div><strong>账户安全保障</strong><p>请妥善保管账户密码，勿向他人透露密码或交付信息。</p></div></div></section></main><footer class="auth-footer"><span>© '.date('Y').' '.e($config['app']['name']).'</span><span>人工确认付款 · 人工交付 · 安心管理</span></footer><nav class="auth-bottom-nav" aria-label="快捷导航"><a href="/">首页</a><a href="/products">商城</a><a href="/orders">订单</a><a href="/account">我的</a></nav><script>document.querySelectorAll(".auth-password-toggle").forEach(function(button){button.addEventListener("click",function(){var input=button.parentNode.querySelector("input");var show=input.type==="password";input.type=show?"text":"password";button.setAttribute("aria-pressed",String(show));button.setAttribute("aria-label",show?"隐藏密码":"显示密码")})});var menu=document.querySelector(".auth-menu-toggle"),drawer=document.querySelector(".auth-mobile-menu");function closeMenu(){drawer.hidden=true;menu.setAttribute("aria-expanded","false");menu.setAttribute("aria-label","打开导航")}menu.addEventListener("click",function(){var opened=drawer.hidden;drawer.hidden=!opened;menu.setAttribute("aria-expanded",String(opened));menu.setAttribute("aria-label",opened?"关闭导航":"打开导航")});document.addEventListener("keydown",function(event){if(event.key==="Escape"&&!drawer.hidden){closeMenu();menu.focus()}});document.addEventListener("click",function(event){if(!event.target.closest(".auth-header"))closeMenu()});</script></body></html>';
}
function money($cents){return '¥'.number_format($cents/100,2);}
function plan_description_html($description, $class = 'plan-description')
{
    $lines = preg_split('/\r\n|\r|\n/', (string)$description);
    $html = '';
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $html .= '<span class="plan-description-line">'.e($line).'</span>';
    }
    return '<div class="'.e($class).'">'.($html ?: '<span class="plan-description-line">暂无套餐说明</span>').'</div>';
}
function shop_plan_period($days) {
    $days = (int)$days;
    if ($days <= 0) return 'other';
    if ($days <= 45) return 'monthly';
    if ($days <= 120) return 'quarterly';
    if ($days <= 400) return 'yearly';
    return 'other';
}
function shop_plan_key($plan) {
    return shop_plan_period($plan['duration_days']).':'.(int)$plan['duration_days'];
}
function shop_plan_daily($plan) {
    $days = (int)$plan['duration_days'];
    return $days > 0 ? (int)$plan['price_cents'] / $days : (int)$plan['price_cents'];
}
function shop_traffic_gb($traffic) {
    if (preg_match('/(\d+(?:\.\d+)?)/', (string)$traffic, $m)) return (float)$m[1];
    return 0;
}
function shop_tagline($description, $name) {
    $lines = preg_split('/\r\n|\r|\n/', (string)$description);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $line = trim(preg_replace('/^[\p{So}\p{Sk}\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}\s]+/u', '', $line));
        if ($line === '' || $line === $name) continue;
        if (mb_strlen($line, 'UTF-8') > 60) $line = mb_substr($line, 0, 60, 'UTF-8').'…';
        return $line;
    }
    return '灵活选择流量与周期，人工确认付款后开始交付。';
}
function status_text($s){$m=array('pending_payment'=>'待付款','paid_waiting_group'=>'已付款，待成团','pending_delivery'=>'待人工交付','delivered'=>'已交付','cancelled'=>'已取消','refunding'=>'退款中','refunded'=>'已退款');return isset($m[$s])?$m[$s]:$s;}
function order_timeline($events,$order){if(!$events){$events=array(array('description'=>'订单已创建。','created_at'=>$order['created_at'],'actor_type'=>'user'));if(!empty($order['paid_at']))$events[]=array('description'=>'付款已确认。','created_at'=>$order['paid_at'],'actor_type'=>'admin');if(!empty($order['delivered_at']))$events[]=array('description'=>'订单已发货。','created_at'=>$order['delivered_at'],'actor_type'=>'admin');if(!empty($order['cancelled_at']))$events[]=array('description'=>'订单已取消：'.$order['cancel_reason'],'created_at'=>$order['cancelled_at'],'actor_type'=>$order['cancelled_by_type']);if(!empty($order['refund_requested_at']))$events[]=array('description'=>'退款申请：'.$order['refund_reason'],'created_at'=>$order['refund_requested_at'],'actor_type'=>'user');if(!empty($order['refund_processed_at']))$events[]=array('description'=>'退款处理：'.$order['refund_reason'],'created_at'=>$order['refund_processed_at'],'actor_type'=>'admin');} $html='<section class="order-timeline"><h2>订单状态时间线</h2>';foreach($events as $event)$html.='<div class="timeline-item"><i></i><div><b>'.e($event['description']).'</b><small>'.e($event['created_at']).'</small></div></div>';return $html.'</section>';}
function post_csrf(){Security::checkCsrf(isset($_POST['_csrf'])?$_POST['_csrf']:null);}
function copy_subscription_script(){return '<script>document.querySelectorAll(".copy-subscription").forEach(function(button){button.addEventListener("click",function(){var target=document.getElementById(button.dataset.copyTarget);if(!target)return;var text=target.textContent;navigator.clipboard?navigator.clipboard.writeText(text).then(function(){button.textContent="已复制";setTimeout(function(){button.textContent="复制"},1600)}):button.textContent="请手动复制";});});</script>';}
function product_form($product, $action, $title){
    global $pdo;
    if (($product && catalog_type($product)==='account') || (!$product && isset($_GET['product_type']) && $_GET['product_type']==='account')) return account_product_form($product,$action,$title,$pdo);
    $p = $product ?: array('name'=>'','description'=>'','traffic'=>'','region'=>'','device_limit'=>1,'status'=>'draft','sort_order'=>0);
    $categories = (new VpnCategoryService($pdo))->all();
    $categorySelect = '<label>VPN 分类<select name="vpn_category_id"><option value=""'.(!isset($p['vpn_category_id']) ? ' selected' : '').'>未分类</option>';
    foreach ($categories as $category) {
        $categorySelect .= '<option value="'.(int)$category['id'].'"'.(isset($p['vpn_category_id']) && (string)$p['vpn_category_id'] === (string)$category['id'] ? ' selected' : '').'>'.e($category['name']).'</option>';
    }
    $categorySelect .= '</select></label>';
    $plans = isset($p['plans']) && $p['plans'] ? $p['plans'] : array(array('id'=>'','name'=>'月卡','duration_days'=>30,'price_cents'=>2999,'status'=>'active','sort_order'=>0));
    $plans=array_values($plans);
    $planRows=''; foreach($plans as $i=>$plan){
        $cost=isset($plan['cost_cents'])?(int)$plan['cost_cents']:null;
        $costText=$cost===null?'':intdiv($cost,100).'.'.str_pad((string)($cost%100),2,'0',STR_PAD_LEFT);
        $profit=$cost===null?null:(int)$plan['price_cents']-$cost;
        $profitText=$profit===null?'未知':($profit<0?'-':'').intdiv(abs($profit),100).'.'.str_pad((string)(abs($profit)%100),2,'0',STR_PAD_LEFT).' 元';
        $planRows.='<div class="plan-row"><input type="hidden" name="plans['.$i.'][id]" value="'.(int)$plan['id'].'"><label class="vpn-plan-field">方案名称<input name="plans['.$i.'][name]" value="'.e($plan['name']).'" placeholder="月卡 / 季卡 / 年卡" required></label><label class="vpn-plan-field">有效期（天）<input type="number" name="plans['.$i.'][duration_days]" value="'.(int)$plan['duration_days'].'" min="1" max="36500" required></label><label class="vpn-plan-field">售价（元）<input class="plan-price-input" type="number" name="plans['.$i.'][price]" value="'.number_format($plan['price_cents']/100,2,'.','').'" min="0.01" step="0.01" required></label><label class="vpn-plan-field">成本（元）<input class="plan-cost-input" type="text" inputmode="decimal" name="plans['.$i.'][cost]" value="'.$costText.'" pattern="[0-9]{1,8}([.][0-9]{1,2})?" placeholder="空=未知，0有效"></label><label class="vpn-plan-field">预估毛利<output class="plan-profit'.($profit!==null&&$profit<0?' is-negative':'').'"'.($profit!==null&&$profit<0?' style="color:#b91c1c;font-weight:700"':'').'>'.$profitText.'</output></label><label class="vpn-plan-field">状态<select name="plans['.$i.'][status]"><option value="active"'.($plan['status']==='active'?' selected':'').'>启用</option><option value="inactive"'.($plan['status']==='inactive'?' selected':'').'>下架</option></select></label><label class="vpn-plan-field">排序<input type="number" name="plans['.$i.'][sort_order]" value="'.(int)$plan['sort_order'].'" min="0"></label><button type="button" class="plan-remove" title="移除方案">×</button></div>';
    }
    $planScript=<<<'JS'
<script>(function(){
    var root=document.querySelector(".vpn-cost-form"),list=root&&root.querySelector("#plan-list"),add=root&&root.querySelector("#add-plan");
    if(!list||!add)return;
    var nextIndex=0;
    list.querySelectorAll("[name]").forEach(function(field){var m=/^plans\[(\d+)\]/.exec(field.name);if(m)nextIndex=Math.max(nextIndex,Number(m[1])+1);});
    function cents(text){var m=/^(\d{1,8})(?:\.(\d{1,2}))?$/.exec(text.trim());return m?Number(m[1])*100+Number(((m[2]||"")+"00").slice(0,2)):null;}
    function update(row){
        var price=cents(row.querySelector(".plan-price-input").value),cost=cents(row.querySelector(".plan-cost-input").value),out=row.querySelector(".plan-profit"),profit=price===null||cost===null?null:price-cost,negative=profit!==null&&profit<0;
        out.textContent=profit===null?"未知":(negative?"-":"")+Math.floor(Math.abs(profit)/100)+"."+String(Math.abs(profit)%100).padStart(2,"0")+" 元";
        out.classList.toggle("is-negative",negative);out.style.color=negative?"#b91c1c":"";out.style.fontWeight=negative?"700":"";
    }
    add.onclick=function(){
        var i=nextIndex++,row=document.createElement("div");row.className="plan-row";
        row.innerHTML='<input type="hidden" name="plans['+i+'][id]" value="0"><label class="vpn-plan-field">方案名称<input name="plans['+i+'][name]" placeholder="月卡 / 季卡 / 年卡" required></label><label class="vpn-plan-field">有效期（天）<input type="number" name="plans['+i+'][duration_days]" value="30" min="1" max="36500" required></label><label class="vpn-plan-field">售价（元）<input class="plan-price-input" type="number" name="plans['+i+'][price]" min="0.01" step="0.01" required></label><label class="vpn-plan-field">成本（元）<input class="plan-cost-input" type="text" inputmode="decimal" name="plans['+i+'][cost]" pattern="[0-9]{1,8}([.][0-9]{1,2})?" placeholder="空=未知，0有效"></label><label class="vpn-plan-field">预估毛利<output class="plan-profit">未知</output></label><label class="vpn-plan-field">状态<select name="plans['+i+'][status]"><option value="active">启用</option><option value="inactive">下架</option></select></label><label class="vpn-plan-field">排序<input type="number" name="plans['+i+'][sort_order]" value="'+(i+1)+'" min="0"></label><button type="button" class="plan-remove" title="移除方案">×</button>';
        list.appendChild(row);
    };
    list.addEventListener("input",function(event){if(event.target.matches(".plan-price-input,.plan-cost-input"))update(event.target.closest(".plan-row"));});
    list.onclick=function(event){if(event.target.classList.contains("plan-remove")&&list.querySelectorAll(".plan-row").length>1)event.target.closest(".plan-row").remove();};
    list.querySelectorAll(".plan-row").forEach(update);
})();</script>
JS;
    return '<div class="product-form-card vpn-cost-form"><div class="product-form-heading"><div><span class="admin-kicker">CATALOG / 套餐管理</span><h1>'.e($title).'</h1><p>基础套餐可以添加多个时长和价格方案。成本与预估毛利仅管理员可见；预估毛利=当前售价−成本，非订单实际利润，未计优惠、佣金，不计算历史利润。</p></div><a class="secondary-button" href="/admin/products">返回列表</a></div><form method="post" action="'.e($action).'">'.csrf_field().'<div class="product-form-grid"><div class="product-form-section"><h2>基本信息</h2><p>用户在套餐页会看到这些内容。</p>'.$categorySelect.'<label>套餐名称 <em>*</em><input name="name" value="'.e($p['name']).'" maxlength="160" required placeholder="例如：体验套餐"></label><label>套餐描述 <em>*</em><textarea name="description" maxlength="5000" required placeholder="介绍套餐适合的使用场景">'.e($p['description']).'</textarea></label><div class="form-two"><label>流量规格 <em>*</em><input name="traffic" value="'.e($p['traffic']).'" maxlength="100" required placeholder="例如：100GB"></label><label>地区/线路 <em>*</em><input name="region" value="'.e($p['region']).'" maxlength="100" required placeholder="例如：香港 / 新加坡"></label></div></div><div class="product-form-section"><h2>设备与发布</h2><p>套餐上架后，用户可在多个方案中选择购买。</p><div class="form-two"><label>设备数 <em>*</em><input type="number" name="device_limit" value="'.(int)$p['device_limit'].'" min="1" max="999" required></label><label>排序值<input type="number" name="sort_order" value="'.(int)$p['sort_order'].'" min="0" max="999999"></label></div><label>发布状态<select name="status"><option value="draft"'.($p['status']==='draft'?' selected':'').'>草稿</option><option value="active"'.($p['status']==='active'?' selected':'').'>已上架</option><option value="inactive"'.($p['status']==='inactive'?' selected':'').'>已下架</option></select></label></div></div><div class="product-form-section plans-section"><div class="plans-heading"><div><h2>时长与价格方案</h2><p>例如月卡 30 天 ¥29.99、季卡 90 天、年卡 365 天 ¥288。</p></div><button type="button" class="secondary-button" id="add-plan">＋ 添加方案</button></div><div class="plan-head"><span>方案名称</span><span>有效期（天）</span><span>售价（元）</span><span>成本（元）</span><span>预估毛利</span><span>状态</span><span>排序</span><span></span></div><div id="plan-list">'.$planRows.'</div></div><div class="product-form-actions"><a class="secondary-button" href="/admin/products">取消</a><button class="button" type="submit">保存套餐</button></div></form></div>'.$planScript;
}
$router->get('/', function() use($config,$pdo){
    $products=$pdo->query('SELECT * FROM products WHERE status="active" AND '.catalog_vpn_condition($pdo).' ORDER BY sort_order,id')->fetchAll();
    $articleRows=$pdo->query('SELECT * FROM articles WHERE status="published" ORDER BY published_at DESC,id DESC LIMIT 3')->fetchAll();
    $cards=''; $shownPlans=0;
    foreach($products as $product){
        $planQuery=$pdo->prepare('SELECT name,duration_days,price_cents FROM product_plans WHERE product_id=? AND status="active" ORDER BY price_cents,sort_order,id LIMIT 1');
        $planQuery->execute(array($product['id']));
        $plan=$planQuery->fetch();
        if(!$plan)continue;
        $amount=explode('.',number_format($plan['price_cents']/100,2,'.',','));
        $cards.='<article class="editorial-plan"><div class="editorial-plan-category">'.home_outline_icon('globe').'<span>'.e(trim((string)$product['region'])!==''?$product['region']:'VPN 订阅').'</span></div><div class="editorial-plan-title"><h3 title="'.e($product['name']).'">'.e($product['name']).'</h3><span>'.e($plan['name']).'</span></div><p class="editorial-plan-caption">'.e($plan['name']).' · '.(int)$plan['duration_days'].' 天 · 人工交付</p><div class="editorial-price"><span>¥</span><strong>'.e($amount[0]).'</strong><b>.'.e($amount[1]).'</b><small>/ '.e($plan['name']).'</small></div><dl><div><dt>套餐流量</dt><dd>'.e(trim((string)$product['traffic'])!==''?$product['traffic']:'以商品说明为准').'</dd></div><div><dt>同时使用设备</dt><dd>'.(int)$product['device_limit'].' 台</dd></div></dl><a class="editorial-outline" href="/products/'.(int)$product['id'].'">查看套餐 <span aria-hidden="true">↗</span></a></article>';
        if(++$shownPlans===2)break;
    }
    if($cards==='')$cards='<div class="editorial-empty">暂无在售套餐，欢迎稍后再来。<a href="/products">查看套餐商店 →</a></div>';
    $articleHtml='';
    foreach($articleRows as $article)$articleHtml.='<a href="/articles/'.(int)$article['id'].'"><span>'.e($article['title']).'</span><span aria-hidden="true">↗</span></a>';
    if($articleHtml==='')$articleHtml='<p class="editorial-list-empty">暂时没有已发布指南。</p><a href="/articles">前往帮助中心 <span aria-hidden="true">↗</span></a>';
    $activeAnnouncements=(new AnnouncementService($pdo))->listActive(2);
    $homeNotice='';
    foreach($activeAnnouncements as $announcement)$homeNotice.='<a href="/announcements/'.(int)$announcement['id'].'"><span>'.e($announcement['title']).'</span><span aria-hidden="true">↗</span></a>';
    if($homeNotice==='')$homeNotice='<p class="editorial-list-empty">暂无生效公告。</p><a href="/announcements">查看全部公告 <span aria-hidden="true">↗</span></a>';
    $appName=e($config['app']['name']);
    $platformCards=''; $shownPlatforms=0;
    if(account_catalog_ready($pdo)){
        $platforms=(new AccountPlatformService($pdo))->all();
        $platformProducts=$pdo->query('SELECT account_platform,COUNT(*) AS product_count FROM products WHERE product_type="account" AND status="active" GROUP BY account_platform')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach($platforms as $platform){
            $mark=home_outline_icon('cube');
            if(stripos($platform['code'],'apple')!==false)$mark=home_outline_icon('apple');
            elseif(stripos($platform['code'],'google')!==false)$mark='<span class="editorial-google" aria-hidden="true">G</span>';
            elseif($platform['code']==='telegram')$mark=home_outline_icon('send');
            elseif($platform['code']==='x')$mark='<span class="editorial-google" aria-hidden="true">X</span>';
            $platformCards.='<a href="/accounts?platform='.rawurlencode($platform['code']).'">'.$mark.'<span>'.e($platform['name']).'</span>'.(empty($platformProducts[$platform['code']])?'<small>暂无在售</small>':'').'</a>';
            if(++$shownPlatforms===3)break;
        }
    }
    foreach(array(array('/articles','选购指南','cube'),array('/tickets','售后支持','cloud'),array('/accounts','浏览海外账号','globe')) as $entry){
        if($shownPlatforms>=3)break;
        $platformCards.='<a href="'.$entry[0].'">'.home_outline_icon($entry[2]).'<span>'.$entry[1].'</span></a>';
        $shownPlatforms++;
    }
    $platformCards.='<a href="/accounts">'.home_outline_icon('layers').'<span>更多平台</span></a>';
    $accountPlatformSection='<section class="editorial-container editorial-accounts" aria-labelledby="editorial-accounts-title"><div class="editorial-platforms">'.$platformCards.'</div><div class="editorial-account-copy"><span class="editorial-kicker">OVERSEAS ACCOUNTS</span><h2 id="editorial-accounts-title">更多数字服务，<br>一个入口就够了。</h2><p>按平台浏览账号商品，查看方案与售后说明；付款后由管理员人工交付。</p><a class="editorial-text-link" href="/accounts">浏览海外账号 <span aria-hidden="true">↗</span></a></div></section>';

    $hero='<section class="editorial-container editorial-hero" id="home"><div class="editorial-hero-copy"><span class="editorial-kicker">DIGITAL SERVICES</span><h1>连接世界，<br>简单一点。</h1><p>精选VPN订阅与海外账号，从选购到交付，<br>让数字服务清晰，省心。</p><div class="editorial-actions"><a class="editorial-primary" href="/products">浏览订阅套餐 <span aria-hidden="true">↗</span></a><a class="editorial-text-link" href="/accounts">探索海外账号 <span aria-hidden="true">→</span></a></div></div><div class="editorial-hero-art"><img src="/assets/home-editorial-globe.svg" alt="环绕轨道的地球经纬线插画" width="600" height="520" fetchpriority="high"></div></section><div class="editorial-container editorial-trust" aria-label="服务特点"><span>方案清晰</span><span>交付可查</span><span>售后有回应</span></div>';

    $services='<section class="editorial-container editorial-why" aria-labelledby="editorial-why-title"><span class="editorial-kicker">WHY XIAOYUNPU</span><h2 id="editorial-why-title">把复杂留给我们，<br>把简单交给你。</h2><div class="editorial-benefits"><article><span>01</span><h3>清晰选购</h3><p>价格、周期与规格，购买前一目了然。</p></article><article><span>02</span><h3>私密交付</h3><p>交付信息仅本人登录后可见，<br>在订单中随时查看。</p></article><article><span>03</span><h3>持续支持</h3><p>使用教程与售后工单，<br>遇到问题有处可问。</p></article></div></section>';

    $plans='<section class="editorial-band" id="plans" aria-labelledby="editorial-plans-title"><div class="editorial-container editorial-plans"><div class="editorial-plans-copy"><span class="editorial-kicker">VPN SUBSCRIPTIONS</span><h2 id="editorial-plans-title">不同需求，<br>都有合适的选择。</h2><p>从日常浏览到多设备使用，<br>按流量与设备需求挑选。</p><a class="editorial-text-link" href="/products">查看全部套餐 <span aria-hidden="true">↗</span></a></div><div class="editorial-plan-grid">'.$cards.'</div></div></section>';

    $journey='<section class="editorial-container editorial-journey" aria-labelledby="editorial-journey-title"><h2 id="editorial-journey-title">从选购到交付，每一步都清楚。</h2><ol><li><span>01</span><b>选择商品与方案</b></li><li><span>02</span><b>下单并完成付款</b></li><li><span>03</span><b>等待人工确认与交付</b></li></ol><p>订阅开通与到期时间，以正式交付信息为准。</p></section>';

    $downloadCards='';
    foreach(array('windows'=>'Windows','apple'=>'macOS','android'=>'Android','linux'=>'Linux') as $icon=>$label)$downloadCards.='<a href="/downloads" aria-label="查看 '.e($label).' 下载与使用教程">'.home_outline_icon($icon).'<span>'.e($label).'</span></a>';
    $downloads='<section class="editorial-band editorial-downloads" aria-labelledby="editorial-downloads-title"><div class="editorial-container"><span class="editorial-kicker">DOWNLOADS &amp; GUIDES</span><h2 id="editorial-downloads-title">在熟悉的设备上，轻松开始。</h2><p>下载适用客户端，按教程完成配置。</p><div class="editorial-download-links">'.$downloadCards.'</div><a class="editorial-text-link" href="/articles">查看使用教程 <span aria-hidden="true">→</span></a></div></section>';

    $news='<section class="editorial-container editorial-news" id="guide" aria-label="公告与指南"><div><h2>最新公告</h2><div class="editorial-link-list">'.$homeNotice.'</div></div><div><h2>使用指南</h2><div class="editorial-link-list">'.$articleHtml.'</div></div></section>';
    $accountAction=!empty($_SESSION['user_id'])?'<a class="editorial-text-link" href="/account/overview">前往我的账户 <span aria-hidden="true">→</span></a>':'<a class="editorial-text-link" href="/login">已有账号？登录 <span aria-hidden="true">→</span></a>';
    $cta='<section class="editorial-container editorial-cta"><h2>下一次连接，从这里开始。</h2><div class="editorial-actions"><a class="editorial-primary" href="/products">选择你的订阅 <span aria-hidden="true">↗</span></a>'.$accountAction.'</div></section>';
    $footer='<footer class="editorial-container editorial-footer"><div class="editorial-footer-grid"><div class="editorial-footer-brand"><a href="/">'.home_outline_icon('cloud').'<b>'.$appName.'</b></a><p>数字服务，简单一点。</p></div><div><h3>产品服务</h3><a href="/products">VPN订阅</a><a href="/accounts">海外账号</a><a href="/downloads">软件下载</a><a href="/benefits">公益节点</a></div><div><h3>帮助支持</h3><a href="/articles">使用教程</a><a href="/announcements">平台公告</a><a href="/tickets">售后工单</a><a href="/feedback">意见反馈</a></div><div><h3>账户服务</h3><a href="/account/overview">个人中心</a><a href="/orders">我的订单</a><a href="/wallet">我的余额</a><a href="/events">活动中心</a></div></div><div class="editorial-copyright">©'.date('Y').' '.$appName.'<span>人工确认付款 · 人工交付 · 请勿公开分享交付信息</span></div></footer>';
    $body='<div class="editorial-home">'.$hero.$services.$plans.$accountPlatformSection.$journey.$downloads.$news.$cta.$footer.'</div>';
    page('首页',$body,$config);
});
$router->get('/products', function() use($config,$pdo){
    $categoryService = new VpnCategoryService($pdo);
    $categoryFilter = '';
    $categoryId = null;
    $categoryName = '全部分类';
    try {
        if (array_key_exists('q', $_GET) && (!is_string($_GET['q']) || !mb_check_encoding($_GET['q'], 'UTF-8') || mb_strlen($_GET['q'], 'UTF-8') > 100)) throw new InvalidArgumentException('搜索参数无效。');
        foreach (array('period'=>array('monthly','quarterly','yearly','all'), 'sort'=>array('default','price_asc','price_desc')) as $key => $allowed) {
            if (array_key_exists($key, $_GET) && (!is_string($_GET[$key]) || !in_array($_GET[$key], $allowed, true))) {
                throw new InvalidArgumentException('筛选参数无效。');
            }
        }
        if (array_key_exists('category', $_GET)) {
            $rawCategory = $_GET['category'];
            if ($rawCategory === 'uncategorized') {
                $categoryFilter = 'uncategorized';
                $categoryName = '未分类';
            } else {
                $categoryId = VpnCategoryService::parseId($rawCategory);
                if ($categoryId !== null) $categoryFilter = (string)$categoryId;
            }
        }
    } catch (InvalidArgumentException $exception) {
        http_response_code(400);
        return page('参数无效', '<div class="ep-empty">分类、搜索或筛选参数无效。</div>', $config);
    }
    if ($categoryId !== null) {
        try {
            $selectedCategory = $categoryService->requireId($categoryId);
            $categoryName = $selectedCategory['name'];
        } catch (InvalidArgumentException $exception) {
            http_response_code(404);
            return page('分类不存在', '<div class="empty">所选 VPN 分类不存在。</div>', $config);
        }
    }
    $categories = $categoryService->all();
    $categoryNames = array();
    $categoryLinks = array(array('value'=>'','name'=>'全部套餐'), array('value'=>'uncategorized','name'=>'未分类'));
    foreach ($categories as $category) {
        $categoryNames[(string)$category['id']] = $category['name'];
        $categoryLinks[] = array('value'=>(string)$category['id'],'name'=>$category['name']);
    }
    $categoryIntro = '';
    if ($categoryId !== null && $selectedCategory['description'] !== null && trim($selectedCategory['description']) !== '') {
        $categoryIntro = '<p class="ep-category-description">'.e($selectedCategory['description']).'</p>';
    }
    $categoryCondition = '';
    $categoryParams = array();
    if ($categoryFilter === 'uncategorized') {
        $categoryCondition = ' AND vpn_category_id IS NULL';
    } elseif ($categoryId !== null) {
        $categoryCondition = ' AND vpn_category_id=?';
        $categoryParams[] = $categoryId;
    }
    $productQuery = $pdo->prepare('SELECT * FROM products WHERE status="active" AND '.catalog_vpn_condition($pdo).$categoryCondition.' ORDER BY sort_order,id');
    $productQuery->execute($categoryParams);
    $products = $productQuery->fetchAll();
    $items = array();
    $periodCounts = array('monthly'=>0,'quarterly'=>0,'yearly'=>0);
    $search = trim($_GET['q'] ?? '');
    foreach ($products as $product) {
        $stmt = $pdo->prepare('SELECT * FROM product_plans WHERE product_id=? AND status="active" ORDER BY sort_order,id');
        $stmt->execute(array($product['id']));
        $plans = $stmt->fetchAll();
        if (!$plans) continue;
        if ($search !== '' && mb_stripos((string)$product['name'].' '.(string)$product['description'].' '.(string)$product['region'], $search, 0, 'UTF-8') === false) continue;
        foreach ($plans as $index => $plan) {
            $period = shop_plan_period($plan['duration_days']);
            $plans[$index]['period'] = $period;
            if (isset($periodCounts[$period])) $periodCounts[$period]++;
        }
        $items[] = array('product'=>$product,'plans'=>$plans);
    }

    $defaultPeriod = 'all';
    $defaultCount = 0;
    foreach (array('yearly','quarterly','monthly') as $period) {
        if ($periodCounts[$period] > $defaultCount) { $defaultCount = $periodCounts[$period]; $defaultPeriod = $period; }
    }

    $activePeriod = $_GET['period'] ?? $defaultPeriod;
    $sort = $_GET['sort'] ?? 'default';
    $visibleItems = array();
    foreach ($items as $item) {
        $selectedPlan = null;
        foreach ($item['plans'] as $plan) {
            if ($activePeriod !== 'all' && $plan['period'] !== $activePeriod) continue;
            if ($selectedPlan === null || (int)$plan['price_cents'] < (int)$selectedPlan['price_cents']) $selectedPlan = $plan;
        }
        if ($selectedPlan !== null) $visibleItems[] = array('product'=>$item['product'],'plan'=>$selectedPlan,'position'=>count($visibleItems));
    }
    $total = count($visibleItems);
    $highlight = $total >= 3 ? intdiv($total - 1, 2) : ($total === 2 ? 1 : -1);
    $featuredProductId = $highlight >= 0 ? (int)$visibleItems[$highlight]['product']['id'] : null;
    if ($sort !== 'default') {
        usort($visibleItems, function($a, $b) use ($sort) {
            $comparison = (int)$a['plan']['price_cents'] <=> (int)$b['plan']['price_cents'];
            if ($comparison === 0) return $a['position'] <=> $b['position'];
            return $sort === 'price_desc' ? -$comparison : $comparison;
        });
    }
    $filterParams = array('period'=>$activePeriod,'sort'=>$sort);
    if ($categoryFilter !== '') $filterParams['category'] = $categoryFilter;
    if (array_key_exists('q', $_GET)) $filterParams['q'] = $_GET['q'];
    $filterUrl = function($params) {
        $queryString = http_build_query($params);
        return e('/products'.($queryString !== '' ? '?'.$queryString : ''));
    };
    $categoryNav = '<nav class="ep-categories" aria-label="按 VPN 分类筛选">';
    foreach ($categoryLinks as $categoryLink) {
        $params = $filterParams;
        unset($params['category']);
        if ($categoryLink['value'] !== '') $params['category'] = $categoryLink['value'];
        $categoryNav .= '<a href="'.$filterUrl($params).'"'.($categoryFilter === $categoryLink['value'] ? ' aria-current="page"' : '').'>'.e($categoryLink['name']).'</a>';
    }
    $categoryNav .= '</nav>';
    $periodLabels = array('monthly'=>'月付','quarterly'=>'季付','yearly'=>'年付','all'=>'全部周期');
    $periodNav = '<nav class="ep-periods" aria-label="按购买周期筛选">';
    foreach ($periodLabels as $period => $label) {
        if ($period !== 'all' && !$periodCounts[$period]) {
            $periodNav .= '<span aria-disabled="true"'.($activePeriod === $period ? ' aria-current="page"' : '').'>'.e($label).'</span>';
        } else {
            $params = $filterParams;
            $params['period'] = $period;
            $periodNav .= '<a href="'.$filterUrl($params).'"'.($activePeriod === $period ? ' aria-current="page"' : '').'>'.e($label).'</a>';
        }
    }
    $periodNav .= '</nav>';
    $sortForm = '<form class="ep-sort" method="get" action="/products">';
    foreach ($filterParams as $key => $value) {
        if ($key !== 'sort') $sortForm .= '<input type="hidden" name="'.e($key).'" value="'.e($value).'">';
    }
    $sortForm .= '<select name="sort" aria-label="价格排序" onchange="this.form.submit()">';
    foreach (array('default'=>'默认排序','price_asc'=>'价格从低到高','price_desc'=>'价格从高到低') as $value => $label) {
        $sortForm .= '<option value="'.e($value).'"'.($sort === $value ? ' selected' : '').'>'.e($label).'</option>';
    }
    $sortForm .= '</select><noscript><button type="submit">排序</button></noscript></form>';

    $cards = '';
    foreach ($visibleItems as $item) {
        $product = $item['product'];
        $featured = (int)$product['id'] === $featuredProductId;
        $plan = $item['plan'];
        $days = (int)$plan['duration_days'];
        $priceCents = (int)$plan['price_cents'];
        $priceInteger = (string)intdiv($priceCents, 100);
        $priceFraction = str_pad((string)abs($priceCents % 100), 2, '0', STR_PAD_LEFT);
        $units = array('monthly'=>'/月','quarterly'=>'/季','yearly'=>'/年');
        $priceUnit = $units[$plan['period']] ?? ($days > 0 ? '/'.$days.'天' : '/按交付说明');
        $productCategory = isset($product['vpn_category_id'], $categoryNames[(string)$product['vpn_category_id']]) ? $categoryNames[(string)$product['vpn_category_id']] : '未分类';
        $specs = array(
            '每30天流量'=>trim((string)$product['traffic']) !== '' ? $product['traffic'] : '按方案说明',
            '同时设备数'=>(int)$product['device_limit'] > 0 ? (int)$product['device_limit'].' 台' : '按方案说明',
            '有效期'=>$days > 0 ? $days.' 天' : '按交付说明',
            '线路地区'=>trim((string)$product['region']) !== '' ? $product['region'] : '按方案说明'
        );
        $specHtml = '';
        foreach ($specs as $label => $value) $specHtml .= '<div><dt>'.e($label).'</dt><dd>'.e($value).'</dd></div>';
        $cards .= '<article class="ep-card'.($featured ? ' is-featured' : '').'">'
            .'<div class="ep-card-category">'.home_outline_icon('globe').'<span>'.e($productCategory).'</span>'.($featured ? '<em>精选</em>' : '').'</div>'
            .'<div class="ep-card-title"><h2 title="'.e($product['name']).'">'.e($product['name']).'</h2><span title="'.e($plan['name']).'">'.e($plan['name']).'</span></div>'
            .'<p class="ep-caption">'.e(shop_tagline($product['description'], $product['name'])).'</p>'
            .'<div class="ep-price"><span>¥</span><strong>'.e($priceInteger).'</strong><b>.'.e($priceFraction).'</b><small>'.e($priceUnit).'</small></div>'
            .'<dl>'.$specHtml.'</dl><a class="ep-button" href="'.e('/products/'.(int)$product['id']).'">查看套餐 ↗</a></article>';
    }
    $results = '<p class="ep-results" role="status">共 '.e((string)$total).' 款套餐 · 当前周期：'.e($periodLabels[$activePeriod]).' · '.($search !== '' ? '搜索：'.e($search) : '未限定搜索');
    if (array_key_exists('q', $_GET)) {
        $params = $filterParams;
        unset($params['q']);
        $results .= ' <a href="'.$filterUrl($params).'">清除搜索</a>';
    }
    $results .= '</p>';
    $allParams = $filterParams;
    $allParams['period'] = 'all';
    $empty = '<div class="ep-empty"><h2>暂无符合条件的套餐</h2><p>'.e($categoryName).'：请调整分类、搜索或周期。</p><a href="'.$filterUrl($allParams).'">查看全部周期</a></div>';
    $purchaseFlow = '<section class="ep-flow" id="purchase-guide"><h2>从选购到交付，每一步都清楚。</h2><ol>'
        .'<li><span>01</span><h3>选择方案并下单</h3><p>查看套餐详情，选择适合的方案并创建订单。</p></li>'
        .'<li><span>02</span><h3>完成付款</h3><p>按照订单提示完成付款，等待确认。</p></li>'
        .'<li><span>03</span><h3>管理员人工交付</h3><p>登录后在订单详情中查看交付内容。</p></li>'
        .'</ol><div class="ep-flow-bottom"><p>订阅开通时间、有效期与使用信息，以正式交付信息为准。</p><a href="/articles">了解购买流程 ↗</a></div></section>';
    $faq = '<section class="ep-faq"><h2>购买前，<br>你可能想了解</h2><div>'
        .'<details><summary>付款后在哪里查看交付内容？</summary><p>付款确认后由管理员人工交付，请登录本人账户，在订单详情中查看交付内容与处理进度。</p></details>'
        .'<details><summary>每个套餐支持多少台设备？</summary><p>同时在线设备数以商品详情及正式交付说明为准，请按实际设备需求选择方案。</p></details>'
        .'<details><summary>订阅有效期从什么时候开始计算？</summary><p>方案天数请查看套餐详情，实际开通时间与到期时间以正式交付信息为准。</p></details>'
        .'</div></section>';
    $help = '<section class="ep-help"><div><h2>还需要一点帮助？</h2><p>查看购买与使用说明，或通过工单咨询。</p></div><a href="/articles">帮助中心 ↗</a><a href="/tickets">提交工单 ↗</a></section>';
    $appName = e($config['app']['name']);
    $footer = '<footer class="ep-footer"><div><a href="/">'.home_outline_icon('cloud').'<span>'.$appName.'</span></a><p>数字服务，简单一点。</p></div><nav aria-label="页脚导航"><a href="/articles">帮助中心</a><a href="/announcements">系统公告</a></nav><p>© '.e(date('Y')).' '.$appName.' 版权所有</p></footer>';
    $body = '<div class="ep-page"><div class="ep-crumb"><a href="/">首页</a><span>/</span><span>VPN订阅</span></div>'
        .'<header class="ep-intro"><div><span class="ep-kicker">SUBSCRIPTION PLANS</span><h1>好的连接，恰到好处。</h1><p>按照流量、设备数量与使用场景，选择适合你的订阅。</p></div><a href="#purchase-guide">购买与交付说明 ↗</a></header>'
        .'<div class="ep-toolbar">'.$categoryNav.'<div class="ep-tools">'.$periodNav.$sortForm.'</div></div>'
        .$results.$categoryIntro.'<div class="ep-grid" data-remainder="'.($total % 3).'">'.($cards !== '' ? $cards : $empty).$purchaseFlow.'</div>'
        .$faq.$help.$footer.'</div>';
    page('套餐商店',$body,$config);
});
$router->get('/products/{id}', function($id) use($config,$pdo){
    $s = $pdo->prepare('SELECT * FROM products WHERE id=? AND status="active" AND '.catalog_vpn_condition($pdo));
    $s->execute(array((int)$id));
    $p = $s->fetch();
    if (!$p) {
        http_response_code(404);
        return page('不存在', '<div class="empty">套餐不存在或已下架。</div>', $config);
    }
    $s = $pdo->prepare('SELECT * FROM product_plans WHERE product_id=? AND status="active" ORDER BY sort_order,id');
    $s->execute(array($p['id']));
    $plans = $s->fetchAll();
    if (!$plans) return page('暂不可购买', '<div class="empty">此套餐暂无可购买的时长方案。</div>', $config);

    $categoryName = '未分类';
    if ($p['vpn_category_id'] !== null) {
        $categoryQuery = $pdo->prepare('SELECT name FROM vpn_categories WHERE id=?');
        $categoryQuery->execute(array($p['vpn_category_id']));
        $actualCategoryName = $categoryQuery->fetchColumn();
        if ($actualCategoryName !== false) $categoryName = $actualCategoryName;
    }
    $region = trim((string)$p['region']) !== '' ? trim((string)$p['region']) : '多地区';
    $traffic = trim((string)$p['traffic']);
    if ($traffic === '') {
        $trafficLabel = '按方案说明';
    } elseif (preg_match('/[a-z]/i', $traffic)) {
        $trafficLabel = $traffic;
    } else {
        $trafficLabel = $traffic.'GB';
    }
    $devices = (int)$p['device_limit'];
    $deviceLabel = $devices > 0 ? $devices.' 台' : '按方案说明';
    $first = $plans[0];
    $firstDays = (int)$first['duration_days'];
    $daysLabel = $firstDays > 0 ? $firstDays.' 天' : '长期有效';
    $tagline = shop_tagline($p['description'], $p['name']);

    $choices = '';
    foreach ($plans as $i => $plan) {
        $planDays = (int)$plan['duration_days'];
        $choices .= '<label class="ed-choice"><input type="radio" name="plan_id" value="'.(int)$plan['id'].'" data-days="'.$planDays.'" data-price="'.(int)$plan['price_cents'].'" data-name="'.e($plan['name']).'"'.($i === 0 ? ' checked' : '').' required>'
            .'<span class="ed-choice-copy"><b>'.e($plan['name']).'</b><small>/ '.($planDays > 0 ? $planDays.'天' : '按交付说明').'</small></span>'
            .'<strong class="ed-choice-price">'.money($plan['price_cents']).'</strong></label>';
    }
    $description = trim((string)$p['description']) !== ''
        ? '<section class="ed-description"><details><summary>商品说明</summary>'.plan_description_html($p['description'], 'ed-description-content').'</details></section>' : '';
    $firstCents = (int)$first['price_cents'];
    $priceInteger = intdiv($firstCents, 100);
    $priceFraction = '.'.str_pad((string)abs($firstCents % 100), 2, '0', STR_PAD_LEFT);
    $loggedIn = !empty($_SESSION['user_id']);
    $appName = e($config['app']['name']);
    $footer = '<footer class="ed-footer"><div><a href="/">'.home_outline_icon('cloud').'<span>'.$appName.'</span></a><p>数字服务，简单一点。</p></div><nav aria-label="页脚导航"><a href="/articles">帮助中心</a><a href="/announcements">系统公告</a></nav><p>© '.e(date('Y')).' '.$appName.' 版权所有</p></footer>';

    $body = '<div class="ed-page">'
        .'<nav class="ed-crumb" aria-label="面包屑"><a href="/">首页</a><span>/</span><a href="/products">VPN订阅</a><span>/</span><span>'.e($p['name']).'</span></nav>'
        .'<div class="ed-layout"><div class="ed-main">'
        .'<header class="ed-hero"><span class="ed-kicker">VPN SUBSCRIPTION · '.e($categoryName).'</span>'
        .'<div class="ed-title"><h1>'.e($p['name']).'</h1><span data-ed-plan-name>'.e($first['name']).'</span></div>'
        .'<p class="ed-tagline">'.e($tagline).'</p><p class="ed-intro">按流量与设备需求选购，购买前请确认套餐规格与使用说明。</p>'
        .'<div class="ed-stats"><div><strong>'.e($trafficLabel).'</strong><span>每30天流量</span></div><div><strong>'.e($deviceLabel).'</strong><span>同时使用设备</span></div><div><strong data-ed-days>'.e($daysLabel).'</strong><span>订阅时长</span></div></div>'
        .'<p class="ed-stat-note">以上为套餐额度与设备限制。</p></header>'
        .'<nav class="ed-tabs" aria-label="套餐内容"><a href="#ed-specs" aria-current="location">套餐详情</a><a href="#ed-notes">购买须知</a><a href="#ed-faq">常见问题</a></nav>'
        .'<section class="ed-specs" id="ed-specs"><h2>套餐规格</h2><div class="ed-table-wrap"><table><tbody>'
        .'<tr><th scope="row">套餐类型</th><td>VPN订阅</td></tr><tr><th scope="row">线路范围</th><td>'.e($region).'</td></tr>'
        .'<tr><th scope="row">流量额度</th><td>每30天 '.e($trafficLabel).'</td></tr><tr><th scope="row">设备限制</th><td>'.($devices > 0 ? e($deviceLabel).'设备同时使用' : '按方案说明').'</td></tr>'
        .'<tr><th scope="row">交付方式</th><td>管理员人工交付</td></tr><tr><th scope="row">有效期</th><td>以正式交付的开通与到期时间为准。</td></tr>'
        .'</tbody></table></div></section>'.$description
        .'<section class="ed-delivery" id="ed-notes"><h2>使用与交付说明</h2><ol>'
        .'<li><span>01</span><h3>下单并付款</h3><p>付款确认后，订单进入待发货状态。</p></li>'
        .'<li><span>02</span><h3>等待人工交付</h3><p>交付后，可在本人订单中查看订阅链接与交付信息。</p></li>'
        .'<li><span>03</span><h3>按教程配置</h3><p>在客户端中按使用指南完成配置。</p></li></ol>'
        .'<p class="ed-delivery-note">仅订单支持临时订阅时才可获取临时订阅；正式交付后，请使用正式交付信息。</p>'
        .'<details class="ed-purchase-notes"><summary>购买与使用须知</summary><ul>'
        .'<li>付款由人工确认，确认成功后按订单顺序交付。</li>'
        .'<li>VPN 正式订阅地址、使用说明、开通及到期时间可能发送至您的登录邮箱，也可登录本人订单查看；请妥善保管，勿转发。</li>'
        .'<li>已发送或在途邮件无法撤回，交付更新以订单详情为准。</li>'
        .'<li>到期后不会自动续费，需要继续使用时请重新下单。</li>'
        .'<li>如需更换设备、线路或处理使用问题，请提交售后工单。</li>'
        .'</ul></details></section></div><aside class="ed-aside">'
        .'<form class="ed-buy" id="ed-purchase-form" method="get" action="/order-confirm"><input type="hidden" name="product_id" value="'.(int)$p['id'].'">'
        .'<h2>选择购买方案</h2><div class="ed-choices">'.$choices.'</div>'
        .'<div class="ed-total"><span>金额</span><div class="ed-money"><span>¥</span><strong data-ed-price-integer>'.$priceInteger.'</strong><b data-ed-price-fraction>'.$priceFraction.'</b></div></div>'
        .'<p class="ed-selected">方案 <span data-ed-selected-name>'.e($first['name']).'</span> · 数量1件</p>'
        .'<p class="ed-quantity-note">每笔订单购买1件，可分开下单购买多个订阅。</p>'
        .'<button class="ed-buy-button" type="submit">'.($loggedIn ? '确认方案，继续下单 ↗' : '登录后购买 ↗').'</button>'
        .'<p class="ed-login-note">'.($loggedIn ? '已登录，核对方案后进入确认订单页。' : '已有账号？请先登录后下单。').'</p>'
        .'<a class="ed-existing-order" href="/orders">已有待付款订单？前往我的订单 →</a>'
        .'<div class="ed-mobile-action"><div><strong>¥<span data-ed-price-integer>'.$priceInteger.'</span><span data-ed-price-fraction>'.$priceFraction.'</span></strong><small><span data-ed-selected-name>'.e($first['name']).'</span> · <span data-ed-days>'.e($daysLabel).'</span></small></div><button type="submit">'.($loggedIn?'继续下单 →':'登录购买 →').'</button></div>'
        .'<div class="ed-pay-notes"><ul><li>支持渠道以订单实际可用为准。</li><li>付款确认后人工交付。</li></ul><p>余额不足请选择可用在线渠道。</p><a href="/tickets">购买前咨询 ↗</a></div></form>'
        .'<section class="ed-aside-help"><h2>需要帮助选择？</h2><a href="/articles">查看使用教程 ↗</a><a href="/tickets">提交咨询工单 ↗</a></section></aside></div>'
        .'<section class="ed-faq" id="ed-faq"><h2>购买前，<br>你可能想了解</h2><div>'
        .'<details open><summary>订阅时长从什么时候起算？</summary><p>以正式交付记录中的开通与到期时间为准，请在交付后核对本人订单。</p></details>'
        .'<details><summary>在哪里查看订阅链接？</summary><p>正式交付后，请登录本人订单查看订阅链接与使用说明；相关信息也可能发送至您的登录邮箱。</p></details>'
        .'<details><summary>如何申请售后或退款？</summary><p>请通过本人订单中的可用入口或提交工单申请，具体处理以订单情况与售后规则为准，不承诺无条件退款。</p></details>'
        .'</div></section><a class="ed-back" href="/products">返回全部套餐</a>'.$footer.'</div>'
        .'<script>(function(){var root=document.querySelector(".ed-page");if(!root)return;var form=root.querySelector("#ed-purchase-form");if(!form)return;function setText(selector,value){root.querySelectorAll(selector).forEach(function(el){el.textContent=value;});}function sync(){var radio=form.querySelector("input[name=plan_id]:checked");if(!radio)return;var days=Number(radio.dataset.days),cents=Number(radio.dataset.price);if(!Number.isSafeInteger(cents))return;setText("[data-ed-days]",days>0?days+" 天":"长期有效");setText("[data-ed-plan-name]",radio.dataset.name);setText("[data-ed-selected-name]",radio.dataset.name);setText("[data-ed-price-integer]",(cents<0?"-":"")+Math.floor(Math.abs(cents)/100));setText("[data-ed-price-fraction]","."+String(Math.abs(cents)%100).padStart(2,"0"));}form.addEventListener("change",function(event){if(event.target.matches("input[type=radio][name=plan_id]"))sync();});sync();var tabs=root.querySelectorAll(".ed-tabs a");tabs.forEach(function(tab){tab.addEventListener("click",function(){tabs.forEach(function(link){link.removeAttribute("aria-current");});tab.setAttribute("aria-current","location");});});})();</script>';

    page($p['name'], $body, $config);
});
$router->get('/register', function() use($config){$ref=isset($_GET['ref'])?trim($_GET['ref']):'';$body='<div class="auth-layout"><section class="auth-card"><span class="auth-eyebrow">开始使用</span><h1>创建账户</h1><p>使用邮箱创建账户，管理订阅、账号商品与交付进度。</p><form method="post">'.csrf_field().'<input type="hidden" name="ref" value="'.e($ref).'"/><label>邮箱<input type="email" name="email" autocomplete="email" inputmode="email" autocapitalize="none" spellcheck="false" placeholder="请输入邮箱地址" required></label><label>昵称（选填）<input name="display_name" maxlength="100" autocomplete="nickname" placeholder="怎么称呼你"></label><label>密码<input type="password" name="password" minlength="8" autocomplete="new-password" placeholder="至少 8 位字符" required></label><button class="button">创建账户　→</button></form><p class="auth-switch">已有账户？<a href="/login">立即登录</a></p></section><aside class="auth-side"><span>'.e($config['app']['name']).'</span><h2>连接世界，<br>从这里开始。</h2><p>选择合适的方案，按步骤完成订阅。</p><div class="auth-orbit" aria-hidden="true">✦</div></aside></div>';page('注册',$body,$config);});
$router->post('/register', function() use($config,$pdo){post_csrf();$email=trim($_POST['email']);$pass=$_POST['password'];$ref=trim(isset($_POST['ref'])?$_POST['ref']:'');if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($pass)<8){flash('error','请输入有效邮箱和至少 8 位密码。');return redirect('/register');}$s=$pdo->prepare('INSERT INTO users(email,password_hash,display_name,created_at,updated_at) VALUES(?,?,?,?,?)');try{$now=date('Y-m-d H:i:s');$s->execute(array($email,password_hash($pass,PASSWORD_DEFAULT),substr(trim($_POST['display_name']),0,100),$now,$now));$uid=(int)$pdo->lastInsertId();if($ref!=='')(new ActivityService($pdo))->bindReferral($uid,$ref);flash('success','注册成功，请登录。');redirect('/login');}catch(PDOException $e){flash('error','注册失败，邮箱可能已存在。');redirect('/register');}});
$router->get('/login', function() use($config){$body='<div class="auth-layout"><section class="auth-card"><span class="auth-eyebrow">欢迎回来</span><h1>登录账户</h1><p>登录后管理订单、查看交付内容和售后工单。</p><form method="post">'.csrf_field().'<label>邮箱<input type="email" name="email" autocomplete="email" inputmode="email" autocapitalize="none" spellcheck="false" placeholder="请输入邮箱地址" required></label><label>密码<input type="password" name="password" autocomplete="current-password" placeholder="请输入密码" required></label><button class="button">登录账户　→</button></form><p class="auth-switch"><a href="/password/forgot">忘记密码？</a>　还没有账户？<a href="/register">立即注册</a></p></section><aside class="auth-side"><span>'.e($config['app']['name']).'</span><h2>轻松连接，<br>自在探索。</h2><p>一个账户，管理订阅、订单与服务支持。</p><div class="auth-orbit" aria-hidden="true">✦</div></aside></div>';page('登录',$body,$config);});
$router->get('/password/forgot', function() use($config){$body='<div class="auth-flow-card"><h1>找回密码</h1><p>输入注册邮箱，如账号存在，我们会发送重置链接。</p><form method="post" action="/password/forgot">'.csrf_field().'<label>邮箱<input type="email" name="email" autocomplete="email" required></label><button class="button">发送重置链接</button></form></div>';page('找回密码',$body,$config);});
$router->post('/password/forgot', function() use($config,$pdo){post_csrf();header('Cache-Control: no-store');$email=strtolower(trim(isset($_POST['email'])?(string)$_POST['email']:''));$ip=substr(isset($_SERVER['REMOTE_ADDR'])?(string)$_SERVER['REMOTE_ADDR']:'',0,45);$limited=false;$q=$pdo->prepare('SELECT COUNT(*) FROM password_reset_tokens WHERE request_ip=? AND created_at>DATE_SUB(NOW(),INTERVAL 1 HOUR)');$q->execute(array($ip));$limited=(int)$q->fetchColumn()>=10;if(!$limited&&filter_var($email,FILTER_VALIDATE_EMAIL)){$s=$pdo->prepare('SELECT id,email FROM users WHERE email=? AND status="active"');$s->execute(array($email));$u=$s->fetch();if($u){$q=$pdo->prepare('SELECT COUNT(*) FROM password_reset_tokens WHERE user_id=? AND created_at>DATE_SUB(NOW(),INTERVAL 1 HOUR)');$q->execute(array($u['id']));if((int)$q->fetchColumn()<3){$raw=bin2hex(random_bytes(32));$hash=hash('sha256',$raw);$now=date('Y-m-d H:i:s');$pdo->prepare('UPDATE password_reset_tokens SET used_at=? WHERE user_id=? AND used_at IS NULL')->execute(array($now,$u['id']));$pdo->prepare('INSERT INTO password_reset_tokens(user_id,token_hash,expires_at,request_ip,created_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE),?,?)')->execute(array($u['id'],$hash,$ip,$now));$base=rtrim((string)$config['app']['base_url'],'/');$link=$base.'/password/reset?token='.rawurlencode($raw);$message=(new EmailTemplateService($pdo,$config))->render('password_reset',array('brand'=>$config['app']['name']),array('reset_link'=>$link));(new NotificationOutboxService($pdo))->enqueue('password_reset','password_reset:'.$hash,array_merge($message,array('to'=>$u['email'],'expires_minutes'=>30)),'email');}}}flash('success','如该邮箱对应有效账户，我们将发送密码重置说明。');redirect('/password/forgot');});
$router->get('/password/reset', function() use($config){$token=isset($_GET['token'])?(string)$_GET['token']:'';$body='<div class="auth-flow-card"><h1>设置新密码</h1><p>请输入并确认你的新密码。</p><form method="post" action="/password/reset">'.csrf_field().'<input type="hidden" name="token" value="'.e($token).'"><label>新密码<input type="password" name="password" minlength="8" autocomplete="new-password" required></label><label>确认新密码<input type="password" name="password_confirm" minlength="8" autocomplete="new-password" required></label><button class="button">重置密码</button></form></div>';page('重置密码',$body,$config);});
$router->post('/password/reset', function() use($config,$pdo){post_csrf();header('Cache-Control: no-store');$token=isset($_POST['token'])?(string)$_POST['token']:'';$password=isset($_POST['password'])?(string)$_POST['password']:'';$confirm=isset($_POST['password_confirm'])?(string)$_POST['password_confirm']:'';if(strlen($password)<8||strlen($password)>4096||!hash_equals($password,$confirm)){flash('error','密码至少 8 位，且两次输入必须一致。');return redirect('/password/reset?token='.rawurlencode($token));}try{$pdo->beginTransaction();$s=$pdo->prepare('SELECT id,user_id FROM password_reset_tokens WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW() FOR UPDATE');$s->execute(array(hash('sha256',$token)));$row=$s->fetch();if(!$row)throw new RuntimeException('invalid');$now=date('Y-m-d H:i:s');$u=$pdo->prepare('UPDATE users SET password_hash=?,password_changed_at=?,updated_at=? WHERE id=?');$u->execute(array(password_hash($password,PASSWORD_DEFAULT),$now,$now,$row['user_id']));$pdo->prepare('UPDATE password_reset_tokens SET used_at=? WHERE user_id=? AND used_at IS NULL')->execute(array($now,$row['user_id']));$pdo->commit();$_SESSION=array();session_destroy();flash('success','密码已重置，请使用新密码登录。');redirect('/login');}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error','重置链接无效或已过期，请重新申请。');redirect('/password/forgot');}});
$router->post('/login', function() use($config,$pdo){post_csrf();$s=$pdo->prepare('SELECT * FROM users WHERE email=? AND status="active"');$s->execute(array(trim($_POST['email'])));$u=$s->fetch();if(!$u||!password_verify($_POST['password'],$u['password_hash'])){flash('error','邮箱或密码错误。');return redirect('/login');}session_regenerate_id(true);$_SESSION['user_id']=$u['id'];$_SESSION['user_name']=$u['display_name']?:$u['email'];$_SESSION['user_password_changed_at']=isset($u['password_changed_at'])?(string)$u['password_changed_at']:'';$return=isset($_SESSION['purchase_return'])?(string)$_SESSION['purchase_return']:'';unset($_SESSION['purchase_return']);redirect(preg_match('~\A/order-confirm\?product_id=[1-9][0-9]*&plan_id=[1-9][0-9]*(?:&group_id=[1-9][0-9]*)?\z~D',$return)?$return:'/orders');});
$router->post('/logout', function(){post_csrf();$_SESSION=array();session_destroy();redirect('/');});
$router->get('/order-confirm', function() use($config,$pdo){$uid=Security::requireUser();$productId=isset($_GET['product_id'])?(int)$_GET['product_id']:0;$planId=isset($_GET['plan_id'])?(int)$_GET['plan_id']:0;$groupId=isset($_GET['group_id'])?(int)$_GET['group_id']:0;$s=$pdo->prepare('SELECT p.*,pp.id plan_id,pp.name plan_name,pp.duration_days plan_days,pp.price_cents plan_price FROM products p JOIN product_plans pp ON pp.product_id=p.id WHERE p.id=? AND pp.id=? AND p.status="active" AND pp.status="active"');$s->execute(array($productId,$planId));$row=$s->fetch();if(!$row)return page('方案不可用','<div class="empty">套餐或期限方案不存在、已下架。</div>',$config);$isAccount=catalog_type($row)==='account';if($isAccount && ($groupId || !empty($_GET['promotion_code']) || (isset($_GET['quantity'])&&(string)$_GET['quantity']!=='1'))){http_response_code(400);return page('不支持活动','<div class="empty">账号商品不参与优惠或拼团。</div>',$config);}$promotion='';if($groupId){$s=$pdo->prepare('SELECT 1 FROM group_members gm JOIN group_campaign_plans gp ON gp.campaign_id=gm.campaign_id AND gp.product_plan_id=? JOIN group_campaigns gc ON gc.id=gm.campaign_id WHERE gm.campaign_id=? AND gm.user_id=? AND gm.status="pending" AND gc.status="forming" AND (gc.expires_at IS NULL OR gc.expires_at>=NOW())');$s->execute(array($planId,$groupId,$uid));if(!$s->fetch())return page('拼团方案不可用','<div class="empty">你不是该拼团待付款成员，或所选方案不适用于此拼团。</div>',$config);$promotion='group:'.$groupId;}$body='<div class="checkout-page"><a class="checkout-back" href="/'.($isAccount?'accounts':'products').'">← 返回选购</a><header class="checkout-heading"><h1>确认订单，完成支付</h1><p>请核对所选方案，提交订单后，选择适合你的支付方式。</p></header><section class="checkout-progress"><h2>购买进度</h2><ol class="checkout-steps" aria-label="购买进度"><li><span>1</span><img src="/assets/progress-check.svg" alt=""><b>选择套餐</b><small>已完成</small></li><li aria-current="step"><span>2</span><img src="/assets/progress-wallet.svg" alt=""><b>确认订单</b><small>当前步骤</small></li><li><span>3</span><img src="/assets/progress-key.svg" alt=""><b>付款与交付</b><small>订单创建后</small></li></ol></section><form class="checkout-layout" method="post" action="/orders">'.csrf_field().'<input type="hidden" name="product_id" value="'.(int)$row['id'].'"><input type="hidden" name="plan_id" value="'.(int)$row['plan_id'].'"><div class="checkout-main"><section class="checkout-card"><div class="checkout-card-head"><h2>套餐与订单信息</h2><a href="/'.($isAccount?'accounts':'products').'/'.(int)$row['id'].'">重新选择</a></div><div class="checkout-product"><span class="checkout-product-icon" aria-hidden="true"><img src="/assets/checkout-layers.svg" alt=""></span><div><h3>'.e($row['name']).'</h3><p>'.e($row['plan_name']).' · '.($isAccount?'单个账号':(int)$row['plan_days'].' 天').'</p><span class="checkout-tag">'.($isAccount?'账号商品':'订阅服务').'</span></div><strong>'.money($row['plan_price']).'</strong></div><dl class="checkout-details"><div><dt>购买数量</dt><dd>1 份</dd></div><div><dt>商品说明</dt><dd class="checkout-description">'.e($row['description']).'</dd></div><div><dt>订单编号</dt><dd>提交订单后生成</dd></div></dl></section>'.($isAccount?'<input type="hidden" name="quantity" value="1">'.account_public_details($row):'<section class="checkout-card"><h2>优惠券</h2>'.($groupId?'<input type="hidden" name="promotion_code" value="'.e($promotion).'"><p class="checkout-help">已参与拼团，优惠将在创建订单时由系统自动计算。</p>':'<label for="checkout-promotion">优惠码 / 优惠券代码（可选）</label><div class="checkout-coupon-input"><img src="/assets/checkout-ticket.svg" alt=""><input id="checkout-promotion" name="promotion_code" maxlength="100" placeholder="请输入优惠码或优惠券代码"></div><p class="checkout-help">优惠有效性与实际抵扣金额将在提交订单时核算。</p>').'</section>').'<section class="checkout-card checkout-note-card"><details><summary>订单备注 <span>选填</span></summary><label for="checkout-note">备注（可选）</label><textarea id="checkout-note" name="note" maxlength="500" placeholder="如有需要说明的事项，请在这里填写"></textarea></details></section><section class="checkout-card checkout-payment-card"><h2>支付方式说明</h2><p class="checkout-help">提交订单后选择支付方式，本页不会发起扣款。</p><div class="checkout-payment-options"><div class="checkout-payment-info"><img src="/assets/checkout-wallet.svg" alt=""><div><b>在线支付</b><span>以订单详情的可用渠道为准</span></div></div><div class="checkout-payment-info"><img src="/assets/checkout-scan.svg" alt=""><div><b>消费余额</b><span>余额足够时可全额支付</span></div></div></div></section></div><aside class="checkout-sidebar"><section class="checkout-card checkout-total"><h2>金额明细</h2><div class="checkout-summary-status"><span>待提交</span><small>核对方案后创建订单</small></div><dl><div><dt>商品金额</dt><dd>'.money($row['plan_price']).'</dd></div><div><dt>购买数量</dt><dd>× 1</dd></div><div><dt>优惠抵扣</dt><dd>提交后核算</dd></div></dl><div class="checkout-total-amount"><span>优惠前金额</span><strong>'.money($row['plan_price']).'</strong></div><p class="checkout-help">最终应付金额以创建后的订单为准；全额优惠订单自动完成零元结算。</p><button class="button" type="submit"><img src="/assets/checkout-lock.svg" alt="">提交订单，前往支付</button><a class="checkout-continue" href="/'.($isAccount?'accounts':'products').'">继续选购</a></section><section class="checkout-safe"><img src="/assets/checkout-info.svg" alt=""><div><b>安全支付提醒</b><p>请在订单创建后确认实际金额，通过订单详情完成付款，勿向陌生账户转账。</p></div></section><p class="checkout-footnote">提交订单不会立即扣款，请勿重复下单。</p></aside><div class="purchase-mobile-action"><div><strong>'.money($row['plan_price']).'</strong><small>优惠前金额 · 提交后核算</small></div><button type="submit"><img src="/assets/purchase-arrow.svg" alt="">提交订单</button></div></form></div>';page('确认订单',$body,$config);});
$router->post('/orders', function() use($config,$pdo){$uid=Security::requireUser();post_csrf();try{$no=(new OrderService($pdo))->create($uid,(int)$_POST['product_id'],(int)$_POST['plan_id'],trim($_POST['note']),trim(isset($_POST['promotion_code'])?$_POST['promotion_code']:''),isset($_POST['quantity'])?$_POST['quantity']:1);flash('success','订单已创建，请选择支付方式或等待人工确认。');redirect('/orders/'.$no);}catch(Exception $e){flash('error',$e->getMessage());redirect('/products');}});
$router->post('/payment/start/{no}/{type}', function($no,$type) use($config,$pdo){$uid=Security::requireUser();post_csrf();try{$payment=(new PaymentService($pdo,$config))->createPayment($no,$uid,$type);if($payment['url']!=='')redirect($payment['url']);if($payment['urlscheme']!=='')redirect($payment['urlscheme']);$body='<div class="form-card"><h1>请使用客户端完成支付</h1><p>订单 '.e($no).' 的二维码内容：</p><code>'.e($payment['qrcode']).'</code><p><a href="/orders/'.e($no).'">返回订单</a></p></div>';page('等待支付',$body,$config);}catch(Exception $e){flash('error',$e->getMessage());redirect('/orders/'.$no);}});
$router->get('/payment/return', function() use($config,$pdo){$gatewayNo=isset($_GET['payment_order'])?preg_replace('/[^A-Za-z0-9_-]/','',$_GET['payment_order']):'';$no='';if($gatewayNo!==''){$s=$pdo->prepare('SELECT o.order_no FROM orders o JOIN payment_records p ON p.order_id=o.id AND p.gateway_order_no=? LIMIT 1');$s->execute(array($gatewayNo));$no=(string)$s->fetchColumn();}if($no==='')$no=isset($_GET['order_no'])?preg_replace('/[^A-Za-z0-9_-]/','',$_GET['order_no']):'';if($no!=='')flash('success','已返回支付页面，最终结果以异步通知为准。');redirect($no!==''?'/orders/'.$no.'/payment-result':'/orders');});
$router->get('/payment/notify', function() use($config,$pdo){try{(new PaymentService($pdo,$config))->handleNotify($_GET);header('Content-Type: text/plain; charset=utf-8');echo 'success';}catch(Exception $e){http_response_code(400);header('Content-Type: text/plain; charset=utf-8');echo 'fail';}});
$router->get('/orders/{no}/payment-result', function($no) use($config,$pdo){
    $uid=Security::requireUser();
    if(PHP_SAPI!=='cli'){
        header('Cache-Control: no-store, private');
        header('Referrer-Policy: no-referrer');
    }
    $o=(new OrderService($pdo))->getForUser($no,$uid);
    if(!$o){http_response_code(404);return page('订单不存在','<div class="empty">订单不存在或无权查看。</div>',$config);}
    return redirect('/orders/'.rawurlencode($no));
});
function account_orders_page($pdo,$uid) {
    $filters=array('product_type'=>'','status'=>'','q'=>'','sort'=>'newest');
    $statuses=array('pending_payment'=>'待付款','paid_waiting_group'=>'待成团','pending_delivery'=>'待发货','delivered'=>'已发货','refunding'=>'退款中','cancelled'=>'已取消','refunded'=>'已退款');
    try {
        foreach ($filters as $key=>$default) {
            if (!isset($_GET[$key])) continue;
            $value=$_GET[$key];
            if (!is_string($value)||!mb_check_encoding($value,'UTF-8')||mb_strlen($value,'UTF-8')>80) throw new InvalidArgumentException('筛选参数无效。');
            $filters[$key]=trim($value);
        }
        if (!in_array($filters['product_type'],array('','vpn','account'),true)||($filters['status']!==''&&!isset($statuses[$filters['status']]))||!in_array($filters['sort'],array('newest','oldest'),true)) throw new InvalidArgumentException('筛选选项无效。');
        $requested=isset($_GET['page'])?$_GET['page']:'1';
        if (!is_string($requested)||!preg_match('/\A[1-9][0-9]{0,8}\z/D',$requested)) throw new InvalidArgumentException('页码无效。');
    } catch (InvalidArgumentException $ex) {
        http_response_code(400);
        return '<div class="mo-page"><section class="mo-empty"><h1>查询参数无效</h1><p>'.e($ex->getMessage()).'</p><a href="/orders">返回我的订单 →</a></section></div>';
    }
    $where='o.user_id=?'; $args=array($uid);
    if ($filters['product_type']!=='') {$where.=' AND o.product_type_snapshot=?';$args[]=$filters['product_type'];}
    if ($filters['status']!=='') {$where.=' AND o.status=?';$args[]=$filters['status'];}
    if ($filters['q']!=='') {$where.=" AND o.order_no LIKE ? ESCAPE '!'";$args[]='%'.str_replace(array('!','%','_'),array('!!','!%','!_'),$filters['q']).'%';}
    $s=$pdo->prepare('SELECT COUNT(*) FROM orders o WHERE '.$where);$s->execute($args);$total=(int)$s->fetchColumn();
    $perPage=10;$pages=max(1,(int)ceil($total/$perPage));$page=min((int)$requested,$pages);$direction=$filters['sort']==='oldest'?'ASC':'DESC';
    $s=$pdo->prepare('SELECT o.id,o.order_no,o.product_name_snapshot,o.plan_name_snapshot,o.product_type_snapshot,o.duration_days_snapshot,o.total_amount_cents,o.status,o.created_at,o.payment_started_at,d.opened_at,d.expires_at FROM orders o LEFT JOIN delivery_records d ON d.id=(SELECT latest.id FROM delivery_records latest WHERE latest.order_id=o.id AND latest.is_current=1 ORDER BY latest.id DESC LIMIT 1) WHERE '.$where.' ORDER BY o.created_at '.$direction.',o.id '.$direction.' LIMIT '.$perPage.' OFFSET '.(($page-1)*$perPage));$s->execute($args);$orders=$s->fetchAll();
    $url=function($changes=array())use($filters){return '/orders?'.http_build_query(array_merge($filters,$changes),'','&',PHP_QUERY_RFC3986);};
    $hidden=function($exclude)use($filters){$html='';foreach($filters as $key=>$value)if($key!==$exclude&&$value!=='')$html.='<input type="hidden" name="'.$key.'" value="'.e($value).'">';return $html;};
    $html='<div class="mo-page"><header class="ao-welcome mo-welcome"><div><span class="ao-kicker">MY ORDERS</span><h1>我的订单</h1><p>追踪购买进度，查看交付内容与售后记录。</p></div><a class="ao-button ao-primary" href="/products">继续选购 ↗</a></header><div class="mo-toolbar"><nav class="mo-type-tabs" aria-label="订单商品分类">';
    foreach(array(''=>'全部商品','vpn'=>'VPN订阅','account'=>'海外账号') as $key=>$label)$html.='<a href="'.e($url(array('product_type'=>$key))).'"'.($filters['product_type']===$key?' aria-current="page"':'').'>'.$label.'</a>';
    $html.='</nav><form class="mo-search" action="/orders" method="get" role="search">'.$hidden('q').'<label><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="10" cy="10" r="7"/><path d="m15 15 6 6"/></svg><input type="search" name="q" maxlength="80" value="'.e($filters['q']).'" placeholder="搜索订单号…" aria-label="搜索订单号"></label><button type="submit">搜索</button></form></div><nav class="mo-status-tabs" aria-label="订单状态"><a href="'.e($url(array('status'=>''))).'"'.($filters['status']===''?' aria-current="page"':'').'>全部</a>';
    foreach(array_slice($statuses,0,5,true) as $key=>$label)$html.='<a href="'.e($url(array('status'=>$key))).'"'.($filters['status']===$key?' aria-current="page"':'').'>'.$label.'</a>';
    $html.='<details class="mo-more"'.(in_array($filters['status'],array('cancelled','refunded'),true)?' open':'').'><summary>更多 <span aria-hidden="true">⌄</span></summary><div>';
    foreach(array_slice($statuses,5,null,true) as $key=>$label)$html.='<a href="'.e($url(array('status'=>$key))).'"'.($filters['status']===$key?' aria-current="page"':'').'>'.$label.'</a>';
    $html.='</div></details></nav><div class="mo-results-bar"><p>共 <strong>'.$total.'</strong> 笔订单'.($filters['q']!==''?' · 订单号包含「'.e($filters['q']).'」':'').'</p><form class="mo-sort" action="/orders" method="get">'.$hidden('sort').'<select name="sort" aria-label="订单创建时间排序"><option value="newest"'.($filters['sort']==='newest'?' selected':'').'>创建时间：最新</option><option value="oldest"'.($filters['sort']==='oldest'?' selected':'').'>创建时间：最早</option></select><button type="submit">应用</button></form></div><section class="mo-list" aria-label="订单列表">';
    foreach($orders as $o) {
        $isAccount=$o['product_type_snapshot']==='account';$path='/orders/'.rawurlencode($o['order_no']);
        $info='';$action='查看进度';$actionPath=$path;
        switch($o['status']) {
            case 'pending_payment':$action='去付款';$info='付款确认后进入后续流程。';break;
            case 'paid_waiting_group':$action='查看成团进度';$info='正在等待成团，成团后进入人工交付流程。';break;
            case 'pending_delivery':$info='管理员人工交付中，请进入详情查看进度。';break;
            case 'delivered':
                $action='查看交付';$actionPath.='#order-delivery';
                $info=$isAccount?'账号交付内容仅本人可见。':'开通 '.($o['opened_at']?substr($o['opened_at'],0,10):'未设置').' · 到期 '.($o['expires_at']?substr($o['expires_at'],0,10):'未设置');
                if(!$isAccount&&!empty($o['expires_at'])&&strtotime($o['expires_at'])!==false)$info.=(strtotime($o['expires_at'])<=time()?' · 已到期':' · 有效期内');
                break;
            case 'refunding':$info='退款申请已提交，等待管理员审核处理。';break;
            case 'cancelled':$info='订单已取消。';break;
            case 'refunded':$info='订单已退款，明细以实际处理记录为准。';break;
        }
        $confirming=$o['status']==='pending_payment'&&!empty($o['payment_started_at']);
        if($confirming){$action='查看付款结果';$info='已发起在线支付，等待确认到账，请勿重复付款。';}
        $html.='<article class="mo-order"><div class="mo-order-content"><header class="mo-order-meta"><p>订单号 <a href="'.e($path).'">'.e($o['order_no']).'</a><span> · 创建于 '.e(substr($o['created_at'],0,16)).'</span></p><span class="mo-order-state'.($o['status']==='pending_payment'?' mo-unpaid':'').'">'.e($confirming?'付款确认中':status_text($o['status'])).'</span></header><div class="mo-order-summary"><div class="mo-product"><h2><a href="'.e($path).'">'.e($o['product_name_snapshot']).'</a>'.(!$isAccount?'<small>'.e($o['plan_name_snapshot']).'</small>':'').'</h2><p>'.($isAccount?'海外账号':'VPN订阅').' · '.e($o['plan_name_snapshot']).' · 数量 1 件</p></div><p class="mo-order-amount">'.e(money($o['total_amount_cents'])).'</p><p class="mo-order-info">'.e($info).'</p></div></div><nav class="mo-order-actions" aria-label="订单 '.e($o['order_no']).' 的操作"><a class="ao-button ao-primary" href="'.e($actionPath).'">'.$action.' ↗</a><a class="ao-button" href="'.e($path).'">查看详情</a></nav></article>';
    }
    if(!$orders)$html.='<div class="mo-empty"><h2>'.($filters['q']!==''||$filters['status']!==''||$filters['product_type']!==''?'暂无匹配订单':'暂无订单').'</h2><p>请尝试其他筛选条件，或选购商品后在这里跟进订单。</p><a href="/orders">清除筛选 →</a><a href="/products">浏览订阅 ↗</a><a href="/accounts">选购海外账号 ↗</a></div>';
    $html.='</section><footer class="mo-list-footer"><p>需要订单帮助？ <a href="/tickets#new-ticket">提交关联订单工单 ↗</a><a href="/articles">查看购买与交付说明 ↗</a></p><nav class="mo-pagination" aria-label="订单分页"><span>共 '.$total.' 笔</span>';
    $html.=($page>1?'<a href="'.e($url(array('page'=>$page-1))).'">‹ 上一页</a>':'<span aria-disabled="true">‹ 上一页</span>').'<b aria-current="page">'.$page.' / '.$pages.'</b>'.($page<$pages?'<a href="'.e($url(array('page'=>$page+1))).'">下一页 ›</a>':'<span aria-disabled="true">下一页 ›</span>');
    return $html.'</nav></footer></div>';
}
$router->get('/orders', function() use($config,$pdo){$uid=Security::requireUser();page('我的订单',account_orders_page($pdo,$uid),$config);});
function order_detail_secret($id,$label,$value,$multiline=false) {
    $tag=$multiline?'div':'code';
    return '<div class="order-secret-row'.($id==='subscription-link'?' order-secret-subscription':'').'"><span class="order-secret-label" id="'.e($id).'-label">'.e($label).'</span><span class="order-secret-mask" aria-hidden="true">•••• •••• •••• •••• ••••</span><'.$tag.' class="order-secret-value" id="'.e($id).'" aria-labelledby="'.e($id).'-label" hidden>'.e($value).'</'.$tag.'><div class="order-secret-controls"><button type="button" data-detail-reveal="'.e($id).'" aria-controls="'.e($id).'" aria-pressed="false">显示</button><button type="button" data-detail-copy="'.e($id).'" aria-controls="'.e($id).'">'.($id==='subscription-link'?'<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="8" y="8" width="13" height="13" rx="2"/><path d="M16 5V3H3v13h2"/></svg>复制订阅链接':'复制').'</button><span class="order-secret-feedback" role="status" aria-live="polite"></span></div></div>';
}
function order_detail_subscription($url,$config) {
    $urls=SubBoostClient::clientUrls($url,$config);
    if(!isset($urls['auto']))return order_detail_secret('subscription-link','正式订阅链接',$url).'<p>本链接不支持多客户端切换，已保留原交付链接。</p>';
    $labels=array('auto'=>'自动适配客户端','mihomo'=>'Mihomo','clash'=>'Clash（经典版）','sing-box'=>'sing-box（1.13+，含 ECH）','v2ray'=>'V2Ray（节点订阅）','surge'=>'Surge','surfboard'=>'Surfboard','quantumult-x'=>'Quantumult X（节点订阅）','uri'=>'URI（明文节点）','base64'=>'Base64（节点订阅）','legacy'=>'旧链接（保持原配置）');
    // 链接只写入转义后的选项属性，浏览器切换不请求外部服务。
    $html='<div class="order-client-choice"><label for="subscription-client">订阅客户端</label><select id="subscription-client" data-detail-client="subscription-link" aria-controls="subscription-link" aria-describedby="subscription-client-help">';
    foreach($urls as $format=>$link)$html.='<option value="'.e($format).'" data-url="'.e($link).'"'.($format==='auto'?' selected':'').'>'.e($labels[$format]).'</option>';
    return order_detail_secret('subscription-link','正式订阅链接',$urls['auto']).'<details class="order-client-settings"><summary>切换客户端格式（高级）</summary>'.$html.'</select></div><p id="subscription-client-help">默认自动适配由订阅服务端按客户端 User-Agent 识别，无法识别时返回 Mihomo；商城不会按浏览器猜测。Mihomo 和旧链接保留原配置，其他格式仅兼容节点与基础配置，不完整迁移分流规则或 DNS；节点订阅格式仅含节点。</p></details>';
}
function order_detail_script() {
    return <<<'HTML'
<script>(function(){var root=document.querySelector('.order-reference-page');if(!root)return;var panel=root.querySelector('.order-payment-panel');if(!panel)return;var cards=Array.from(panel.querySelectorAll('.payment-method-card')),selected=null;var bar=document.createElement('div');bar.className='od-mobile-pay';var amount=document.createElement('div'),caption=document.createElement('small'),price=document.createElement('strong'),pay=document.createElement('button');caption.textContent='应付金额';price.textContent=panel.querySelector('.order-payment-heading>strong').textContent;amount.append(caption,price);pay.type='button';pay.disabled=true;pay.textContent='暂无可用支付方式';bar.append(amount,pay);panel.appendChild(bar);function sync(){selected=cards.find(function(card){return card.querySelector('input[name=mobile_payment_method]').checked;});pay.disabled=!selected;pay.textContent=selected?selected.dataset.payLabel:'暂无可用支付方式';cards.forEach(function(card){card.classList.toggle('is-selected',card===selected);});}cards.forEach(function(card,index){var button=card.querySelector('button[type=submit]'),radio=document.createElement('input'),label=document.createElement('label');var name=card.classList.contains('payment-method-alipay')?'支付宝':(card.classList.contains('payment-method-wechat')?'微信支付':'余额支付');card.dataset.payLabel=name==='支付宝'?'支付宝付款':(name==='微信支付'?'微信付款':'余额付款');radio.type='radio';radio.name='mobile_payment_method';radio.value=String(index);radio.id='od-payment-method-'+index;radio.disabled=button.disabled;radio.className='od-payment-radio';label.htmlFor=radio.id;label.className='od-payment-label';label.textContent=name;card.insertBefore(radio,card.firstChild);card.appendChild(label);card.classList.toggle('is-unavailable',radio.disabled);if(!radio.disabled&&!cards.some(function(item){var input=item.querySelector('input[name=mobile_payment_method]');return input&&input.checked;}))radio.checked=true;radio.addEventListener('change',sync);card.addEventListener('click',function(event){if(!window.matchMedia('(max-width:720px)').matches||radio.disabled||event.target.closest('form'))return;radio.checked=true;radio.focus();sync();});});pay.addEventListener('click',function(){if(!selected)return;var form=selected.querySelector('form'),button=form.querySelector('button[type=submit]');if(!button.disabled)form.requestSubmit(button);});panel.classList.add('od-payment-enhanced');sync();})();</script>
<script>(function(){var root=document.querySelector('.order-reference-page');if(!root)return;function reveal(id,show){var value=document.getElementById(id),button=root.querySelector('[data-detail-reveal="'+id+'"]');if(!value||!root.contains(value)||!button)return;value.hidden=!show;button.setAttribute('aria-pressed',show?'true':'false');button.textContent=show?'隐藏':'显示';value.closest('.order-secret-row').classList.toggle('is-revealed',show);}root.querySelectorAll('[data-detail-reveal]').forEach(function(button){button.addEventListener('click',function(){reveal(button.dataset.detailReveal,button.getAttribute('aria-pressed')!=='true');});reveal(button.dataset.detailReveal,false);});root.querySelectorAll('[data-detail-copy]').forEach(function(button){button.addEventListener('click',function(){var id=button.dataset.detailCopy,value=document.getElementById(id),feedback=button.closest('.order-secret-row').querySelector('.order-secret-feedback');if(!value||!root.contains(value))return;function manual(){reveal(id,true);feedback.textContent='复制未完成，请选中原文手动复制。';}if(!navigator.clipboard||!navigator.clipboard.writeText){manual();return;}try{navigator.clipboard.writeText(value.textContent).then(function(){feedback.textContent='已复制，请妥善保管。';}).catch(manual);}catch(error){manual();}});});root.querySelectorAll('[data-detail-client]').forEach(function(select){select.addEventListener('change',function(){var value=document.getElementById(select.dataset.detailClient),option=select.options[select.selectedIndex];if(!value||!root.contains(value)||!option||!option.hasAttribute('data-url'))return;value.textContent=option.dataset.url;value.closest('.order-secret-row').querySelector('.order-secret-feedback').textContent='当前选择：'+option.textContent+'。链接已更新，请复制当前链接。';});});root.classList.add('order-secrets-ready');})();</script>
HTML;
}
$router->get('/orders/{no}', function($no) use($config,$pdo){$uid=Security::requireUser();$service=new OrderService($pdo);$o=$service->getForUser($no,$uid);if(!$o)return page('订单不存在','<div class="empty">订单不存在或无权查看。</div>',$config);$isAccount=catalog_type($o,true)==='account';
    if(PHP_SAPI!=='cli'){
        header('Cache-Control: no-store, private');
        header('Referrer-Policy: no-referrer');
    }
    $events=order_timeline($service->events($o['id']),$o);
    $tempSubscription='';
    if(!$isAccount&&$o['status']==='pending_delivery'&&!empty($o['temporary_subscription_url'])&&$o['temporary_subscription_status']==='active'){
        $tempSubscription='<section class="order-reference-card order-reference-temporary" id="temporary-subscription"><header class="order-reference-card-heading"><h2>临时订阅 <small>（当前可用）</small></h2><span class="delivery-safe">临时可用</span></header><p>付款已确认，人工发货前可临时使用。正式发货后此地址会自动失效。</p>'.order_detail_secret('temporary-subscription-link','服务地址（HTTPS）',$o['temporary_subscription_url']).'<div class="order-reference-note"><h3>使用说明</h3><ol><li>临时订阅仅供等待人工发货期间使用，与正式方案的可用内容可能不同。</li><li>正式发货后临时地址会失效，请改用正式订阅。</li><li>退款或取消后，临时地址也可能被回收，请勿公开分享。</li></ol></div></section>';
    }elseif(!$isAccount&&$o['status']==='delivered'&&isset($o['temporary_subscription_status'])&&$o['temporary_subscription_status']==='revoked'){
        $tempSubscription='<section class="order-reference-card order-reference-temporary-revoked"><header class="order-reference-card-heading"><h2>临时订阅已失效</h2><span>已失效</span></header><p>临时地址不再展示，请使用当前正式交付的订阅链接。</p></section>';
    }
    $delivery='';$deliveryDates='';$instructions='';
    if($o['status']==='delivered'){
        $s=$pdo->prepare('SELECT * FROM delivery_records WHERE order_id=(SELECT id FROM orders WHERE order_no=?) AND is_current=1');$s->execute(array($no));$d=$s->fetch();
        if($d){
            $c=new CryptoService($config);$x=json_decode($c->decrypt($d['content_ciphertext'],$d['content_nonce'],$d['content_tag']),true);
            if($isAccount){$delivery=order_detail_secret('order-account-content','完整账号交付内容',isset($x['account'])?$x['account']:'',true);}
            else{$delivery=order_detail_subscription(isset($x['subscription'])?$x['subscription']:'',$config);if(!empty($x['account']))$delivery.='<details class="od-extra-account"><summary>查看附加账号信息</summary>'.order_detail_secret('order-vpn-account','账号信息',$x['account'],true).'</details>';}
            foreach(array('opened_at'=>'开通时间','expires_at'=>'到期时间') as $key=>$label)if(!empty($x[$key]))$deliveryDates.='<div><dt>'.$label.'</dt><dd>'.e($x[$key]).'</dd></div>';
            if(!empty($x['instructions']))$instructions='<div class="order-reference-note"><h3>使用说明</h3><p>'.nl2br(e($x['instructions'])).'</p></div>';
            $delivery='<section class="order-reference-card order-reference-delivery" id="order-delivery"><header class="order-reference-card-heading"><h2>'.($isAccount?'账号信息':'正式订阅链接').'</h2><span class="delivery-safe">仅本人可见</span></header>'.$delivery.(!$isAccount?'<p class="od-secret-note">ⓘ 订阅链接仅本人可见，请勿分享给他人。</p>':'').$instructions.(!$isAccount?'<div class="order-reference-usage">'.($instructions===''?'<h3>使用说明</h3><p>下载对应客户端后，粘贴订阅链接并按教程完成导入。</p>':'').'<nav aria-label="订阅使用帮助"><a href="/downloads">下载客户端 ↗</a><a href="/articles?q='.rawurlencode('订阅导入').'">查看导入教程 ↗</a></nav></div>':'').'</section>';
        }
    }
    $paymentBox='';if($o['status']==='pending_payment'){$ps=(new PaymentService($pdo,$config))->settings();$paymentBox='<section class="order-payment-panel"><div class="order-payment-heading"><div><span class="order-section-label">SECURE CHECKOUT</span><h2>选择支付方式</h2><p>应付金额将以订单实际支付记录为准。</p></div><strong>'.e(money($o['total_amount_cents'])).'</strong></div>';if(!empty($ps['enabled'])){$paymentBox.='<div class="payment-methods">'.(!empty($ps['alipay_enabled'])?'<article class="payment-method-card payment-method-alipay"><span class="payment-method-icon">支</span><div class="payment-method-copy"><b>支付宝支付</b><small>安全快捷，支付成功后自动确认</small></div><form method="post" action="/payment/start/'.e($no).'/alipay">'.csrf_field().'<button class="button" type="submit">立即支付</button></form></article>' : '').(!empty($ps['wechat_enabled'])?'<article class="payment-method-card payment-method-wechat"><span class="payment-method-icon">微</span><div class="payment-method-copy"><b>微信支付</b><small>使用微信完成订单付款</small></div><form method="post" action="/payment/start/'.e($no).'/wxpay">'.csrf_field().'<button class="secondary-button" type="submit">立即支付</button></form></article>':'').'</div>'; } $balance=(new WalletService($pdo))->balance($uid);$canPay=empty($o['payment_started_at'])&&$balance>=(int)$o['total_amount_cents'];$paymentBox.='<div class="payment-method-divider"><span>或使用消费余额</span></div><article class="payment-method-card payment-method-balance"><span class="payment-method-icon">¥</span><div class="payment-method-copy"><b>消费余额</b><small>当前可用 ¥'.e(WalletService::formatAmount($balance)).' · '.(!empty($o['payment_started_at'])?'已发起在线支付，请继续原渠道付款。':($canPay?'余额足够，可全额支付。':'余额不足，请选择其他支付方式。')).'</small></div><form method="post" action="/orders/'.e($no).'/balance-pay">'.csrf_field().'<button class="button" type="submit"'.($canPay?'':' disabled').'>余额支付 '.e(money($o['total_amount_cents'])).'</button></form></article><a class="payment-balance-link" href="/wallet">查看余额明细与流水 →</a><p class="payment-security-note">支付完成后，系统会自动更新订单状态；请勿重复提交不同支付方式。</p></section>';}
    $confirming=$o['status']==='pending_payment'&&!empty($o['payment_started_at']);
    $statusLabel=$confirming?'付款确认中':status_text($o['status']);
    $descriptions=array('pending_payment'=>'当前订单尚未付款，完成付款并确认后进入交付流程。','paid_waiting_group'=>'付款已确认，等待成团后进入交付流程。','pending_delivery'=>'付款已确认，正在等待人工交付。','delivered'=>'订单已发货，请核对当前交付内容并妥善保管。','cancelled'=>'订单已取消，当前不展示交付内容。','refunding'=>'退款申请处理中，实际进度以处理记录为准。','refunded'=>'订单已退款，当前不再展示交付内容。');
    $description=$confirming?'已发起在线支付，正在等待付款结果确认；尚未确认到账，请勿重复付款。':(isset($descriptions[$o['status']])?$descriptions[$o['status']]:$statusLabel);
    if ($confirming) $paymentBox = '<div class="customer-payment-feedback" role="status"><strong>付款结果等待确认</strong><p>'.e($description).'</p><a class="secondary-button" href="/orders/'.rawurlencode($no).'/payment-result">刷新付款状态</a></div>'.$paymentBox;
    $deliveryLabel=$o['status']==='delivered'?($isAccount?'账号已交付':'正式订阅已交付'):($o['status']==='pending_delivery'?'等待人工发货':(in_array($o['status'],array('refunding','refunded'),true)?'以实际处理记录为准':''));
    $actions='';
    if($o['status']==='pending_payment')$actions='<section class="order-reference-card order-reference-actions"><header class="order-reference-card-heading"><h2>取消订单</h2></header><form method="post" action="/orders/'.e($no).'/cancel">'.csrf_field().'<label>取消原因<input name="reason" maxlength="500" required></label><button class="secondary-button" type="submit">取消未付款订单</button></form></section>';
    $history='';$versions=$pdo->prepare('SELECT version_no,is_current,created_at FROM delivery_records WHERE order_id=? ORDER BY version_no DESC');$versions->execute(array($o['id']));
    foreach($versions as $version)$history.='<tr><td>v'.(int)$version['version_no'].'</td><td>'.e($version['created_at']).'</td><td>'.($version['is_current']&&$o['status']==='delivered'?'当前交付版本':'历史记录').'</td></tr>';
    if($history)$history='<section class="order-reference-card order-reference-history"><header class="order-reference-card-heading"><h2>交付版本记录</h2></header><div class="table-wrap"><table><thead><tr><th>版本</th><th>记录时间</th><th>说明</th></tr></thead><tbody>'.$history.'</tbody></table></div><p>仅展示版本记录，交付内容以当前有效订单状态为准。</p></section>';
    $publicDetails=$isAccount?'<section class="order-reference-card order-reference-public"><header class="order-reference-card-heading"><h2>其他信息</h2></header>'.account_public_details($o,true).($deliveryDates?'<dl class="order-reference-facts">'.$deliveryDates.'</dl>':'').'</section>':'';
    $primary='';$aside='';$wide=false;
    if(in_array($o['status'],array('refunding','refunded'),true)){
        $primary='<section class="order-reference-card order-reference-refund"><header class="order-reference-card-heading"><h2>'.($o['status']==='refunding'?'退款申请处理中':'退款已处理').'</h2><span>'.e($statusLabel).'</span></header><dl class="order-reference-facts"><div><dt>申请时间</dt><dd>'.e($o['refund_requested_at']?:'未记录').'</dd></div><div><dt>处理时间</dt><dd>'.e($o['refund_processed_at']?:'尚未处理').'</dd></div></dl><div class="order-reference-refund-reason"><h3>退款原因</h3><p>'.nl2br(e($o['refund_reason']?:'未记录')).'</p></div><p>具体退款方式以实际处理说明为准。</p></section>';
        $refundTimeline='';
        foreach(array('refund_requested_at'=>'已记录退款申请','refund_processed_at'=>'已记录退款处理') as $key=>$label)if(!empty($o[$key]))$refundTimeline.='<div class="timeline-item"><i></i><div><b>'.$label.'</b><time>'.e($o[$key]).'</time></div></div>';
        if($o['status']==='refunding')$refundTimeline.='<div class="order-reference-refund-pending"><b>等待处理</b><p>当前等待管理员处理，尚无后续处理记录。</p></div>';
        if($refundTimeline==='')$refundTimeline='<p>暂无已记录的退款时间，当前状态以订单状态为准。</p>';
        $aside='<section class="order-reference-card"><header class="order-reference-card-heading"><h2>退款进度</h2></header><div class="order-reference-refund-timeline">'.$refundTimeline.'</div></section>'.$history;
    }elseif($o['status']==='delivered'){
        $primary=($delivery?:'<section class="order-reference-card"><header class="order-reference-card-heading"><h2>交付记录待核对</h2></header><p>未找到当前交付内容，请联系支持核对。</p></section>');
        $aside=$publicDetails.$tempSubscription.$history.$actions;
    }elseif($o['status']==='pending_delivery'){
        $primary=$tempSubscription?:'<section class="order-reference-card"><header class="order-reference-card-heading"><h2>等待交付</h2></header><p>'.e($description).'</p></section>';
        $wide=!$isAccount;
        if($wide)$primary.=$history.$actions;else $aside=$publicDetails.$history.$actions;
    }else{
        $wide=true;
        $primary=$paymentBox?'<section class="order-reference-card order-reference-payment">'.$paymentBox.'</section>':'<section class="order-reference-card"><header class="order-reference-card-heading"><h2>'.e($statusLabel).'</h2></header><p>'.e($description).'</p>'.($o['status']==='cancelled'&&!empty($o['cancel_reason'])?'<p>取消原因：'.nl2br(e($o['cancel_reason'])).'</p>':'').'</section>';
        $primary.=$publicDetails.$history.$actions;
    }
    $facts='<div><dt>订单编号</dt><dd>'.e($o['order_no']).'</dd></div><div><dt>购买方案</dt><dd>'.e($o['plan_name_snapshot']).'</dd></div><div><dt>订单金额</dt><dd>'.money($o['total_amount_cents']).'</dd></div><div><dt>创建时间</dt><dd>'.e($o['created_at']).'</dd></div>';
    if(!$isAccount)$facts.='<div><dt>订阅周期</dt><dd>'.(int)$o['duration_days_snapshot'].' 天</dd></div>';
    if(!empty($o['paid_at']))$facts.='<div><dt>付款确认时间</dt><dd>'.e($o['paid_at']).'</dd></div>';
    $summaryFacts='<dl class="order-reference-facts">'.$facts.'</dl>';
    if($o['status']==='pending_payment')$summaryFacts='<dl class="order-reference-facts od-full-facts">'.$facts.'</dl><dl class="order-reference-facts od-compact-facts"><div><dt>订单编号</dt><dd>'.e($o['order_no']).'</dd></div>'.(!$isAccount?'<div><dt>订阅周期</dt><dd>'.(int)$o['duration_days_snapshot'].' 天</dd></div>':'').'</dl><details class="od-more-facts"><summary>更多订单信息</summary><dl class="order-reference-facts">'.$facts.'</dl></details>';
    $support='<footer class="order-reference-support"><p>交付信息仅本人可见，请勿公开分享账号或订阅地址。 <a href="/tickets?order_id='.(int)$o['id'].'#new-ticket">提交售后工单</a></p></footer>';
    if(!$isAccount&&$o['status']==='delivered'){
        $moreFacts='<dl class="order-reference-facts">'.$facts.'</dl>';
        $body='<div class="order-detail-page order-reference-page od-delivered"><header class="order-reference-heading"><nav aria-label="面包屑"><a href="/orders">我的订单</a><span aria-hidden="true"> / </span><span>订阅交付</span></nav><div><h1>订阅交付</h1><a class="ao-button" href="/orders">← 返回我的订单 ↗</a></div></header><section class="od-delivery-card"><header class="od-product"><div><h2>'.e($o['product_name_snapshot']).'</h2><span class="od-plan">'.e($o['plan_name_snapshot']).'</span><span class="od-status">已交付</span></div><p>订单编号 <span>'.e($o['order_no']).'</span></p>'.($deliveryDates?'<dl class="od-dates">'.$deliveryDates.'</dl>':'').'</header>'.($delivery?:'<section class="order-reference-card" id="order-delivery"><h2>交付记录待核对</h2><p>未找到当前交付内容，请联系支持核对。</p></section>').'</section>'.($delivery&&($o['temporary_subscription_status']??'')==='revoked'?'<p class="od-revoked">正式订阅已交付，临时订阅已撤销。</p>':'').'<details class="od-order-records"><summary>订单信息与交付记录</summary>'.$moreFacts.'<div class="order-reference-timeline">'.$events.'</div>'.$history.'</details>'.$support.order_detail_script().'</div>';
        return page('订阅交付',$body,$config);
    }
    page('订单详情','<div class="order-detail-page order-reference-page order-reference-'.e($o['status']).($confirming?' order-reference-confirming':'').($isAccount?' order-reference-account':'').'"><header class="order-reference-heading"><nav aria-label="面包屑"><a href="/orders">我的订单</a><span aria-hidden="true"> / </span><span>订单详情</span></nav><div><h1>订单详情</h1><a class="ao-button" href="/orders">← 返回我的订单 ↗</a></div></header><section class="order-reference-summary"><div class="order-reference-summary-top"><div class="order-reference-product"><span class="order-reference-icon" aria-hidden="true">'.($isAccount?user_nav_icon('account'):'VPN').'</span><div><h2>'.e($o['product_name_snapshot']).'<small>'.e($o['plan_name_snapshot']).'</small></h2>'.$summaryFacts.'</div></div><div class="order-reference-status"><span class="order-status-badge order-status-'.e($o['status']).'"><i></i>'.e($statusLabel).'</span>'.($deliveryLabel!==''?'<span class="order-reference-delivery-label">'.e($deliveryLabel).'</span>':'').'<p>'.e($description).'</p></div></div><div class="order-reference-timeline">'.($o['status']==='pending_payment'?'<h2 class="od-timeline-title">订单状态时间线</h2>':'').$events.'</div></section><div class="order-reference-content'.($wide?' order-reference-wide':'').'"><div class="order-reference-primary">'.$primary.'</div>'.($wide?'':'<aside class="order-reference-aside">'.$aside.'</aside>').'</div>'.$support.order_detail_script().'</div>',$config);
});
function account_subscriptions_page($items,$now) {
    $filters=array('status'=>'','q'=>'','sort'=>'expiry_desc');
    try {
        foreach($filters as $key=>$default) {
            if(!isset($_GET[$key]))continue;
            $value=$_GET[$key];
            if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||mb_strlen($value,'UTF-8')>100)throw new InvalidArgumentException('筛选参数无效。');
            $filters[$key]=trim($value);
        }
        if(!in_array($filters['status'],array('','active','soon','expired','unknown'),true)||!in_array($filters['sort'],array('expiry_desc','expiry_asc'),true))throw new InvalidArgumentException('筛选选项无效。');
        $requested=isset($_GET['page'])?$_GET['page']:'1';
        if(!is_string($requested)||!preg_match('/\A[1-9][0-9]{0,8}\z/D',$requested))throw new InvalidArgumentException('页码无效。');
    } catch(InvalidArgumentException $ex) {
        http_response_code(400);
        return '<div class="ms-page"><section class="ms-empty"><h1>查询参数无效</h1><p>'.e($ex->getMessage()).'</p><a href="/account/subscriptions">返回我的订阅 →</a></section></div>';
    }
    $counts=array(''=>0,'active'=>0,'soon'=>0,'expired'=>0,'unknown'=>0);$matched=array();$totalActive=0;$totalExpired=0;$totalUnknown=0;
    foreach($items as $item) {
        $expiry=!empty($item['expires_at'])?strtotime($item['expires_at']):false;
        $state=$expiry===false?'unknown':($expiry<=$now?'expired':'active');
        $soon=$state==='active'&&$expiry-$now<=7*86400;
        if($state==='active')$totalActive++;elseif($state==='expired')$totalExpired++;else $totalUnknown++;
        if($filters['q']!==''&&mb_stripos($item['product_name_snapshot'].' '.$item['plan_name_snapshot'].' '.$item['order_no'],$filters['q'],0,'UTF-8')===false)continue;
        $counts['']++;$counts[$state]++;if($soon)$counts['soon']++;
        if($filters['status']!==''&&($filters['status']==='soon'?!$soon:$filters['status']!==$state))continue;
        $item['_expiry']=$expiry;$item['_state']=$state;$item['_soon']=$soon;$matched[]=$item;
    }
    usort($matched,function($a,$b)use($filters){if($a['_expiry']===false&&$b['_expiry']!==false)return 1;if($b['_expiry']===false&&$a['_expiry']!==false)return -1;$compare=$a['_expiry']<=>$b['_expiry'];if($compare!==0)return $filters['sort']==='expiry_asc'?$compare:-$compare;return (int)$b['id']<=>(int)$a['id'];});
    $total=count($matched);$perPage=10;$pages=max(1,(int)ceil($total/$perPage));$page=min((int)$requested,$pages);
    $url=function($changes=array())use($filters){return '/account/subscriptions?'.http_build_query(array_merge($filters,$changes),'','&',PHP_QUERY_RFC3986);};
    $html='<div class="ms-page"><header class="ao-welcome ms-welcome"><div><span class="ao-kicker">MY SUBSCRIPTIONS</span><h1>我的订阅</h1><p>集中查看已交付订阅的有效期与交付内容。</p></div><a class="ao-button ao-primary" href="/products">选购新订阅 ↗</a></header><section class="ao-stats ms-stats" aria-label="全部已交付订阅统计">';
    foreach(array(array('','全部订阅',count($items)),array('active','有效订阅',$totalActive),array('expired','已到期',$totalExpired)) as $stat)$html.='<a href="'.e($url(array('status'=>$stat[0],'q'=>''))).'"><span>'.$stat[1].'</span><strong>'.$stat[2].'</strong></a>';
    $html.='</section><div class="ms-toolbar"><nav class="ms-tabs" aria-label="订阅状态">';
    $tabs=array(''=>'全部','active'=>'有效','soon'=>'即将到期','expired'=>'已到期');if($totalUnknown>0||$filters['status']==='unknown')$tabs['unknown']='期限未设置';
    foreach($tabs as $key=>$label)$html.='<a href="'.e($url(array('status'=>$key))).'"'.($filters['status']===$key?' aria-current="page"':'').'>'.$label.'<span>('.$counts[$key].')</span></a>';
    $html.='</nav><form class="ms-search" method="get" action="/account/subscriptions" role="search"><input type="hidden" name="status" value="'.e($filters['status']).'"><label><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="10" cy="10" r="7"/><path d="m15 15 6 6"/></svg><input type="search" name="q" value="'.e($filters['q']).'" maxlength="100" aria-label="搜索套餐或订单号" placeholder="搜索套餐或订单号…"></label><select name="sort" aria-label="到期时间排序"><option value="expiry_desc"'.($filters['sort']==='expiry_desc'?' selected':'').'>到期：从晚到早</option><option value="expiry_asc"'.($filters['sort']==='expiry_asc'?' selected':'').'>到期：从早到晚</option></select><button type="submit">搜索</button></form></div>';
    if($filters['q']!==''||$filters['status']!=='')$html.='<p class="ms-results-note">筛选匹配 '.$total.' 项'.($filters['q']!==''?' · 关键词「'.e($filters['q']).'」':'').' · <a href="/account/subscriptions">清除筛选</a></p>';
    $html.='<section class="ms-list" aria-label="已交付VPN订阅列表">';
    foreach(array_slice($matched,($page-1)*$perPage,$perPage) as $item) {
        $state=$item['_state'];$expired=$state==='expired';$soon=$item['_soon'];$path='/orders/'.rawurlencode($item['order_no']);
        if($state==='unknown')$stateText='期限未设置';elseif($expired){$elapsed=$now-$item['_expiry'];$stateText='已到期 · '.($elapsed<86400?'到期不足 1 天':'已过期 '.(int)floor($elapsed/86400).' 天');}else $stateText=($soon?'即将到期':'有效').' · 剩余 '.(int)ceil(($item['_expiry']-$now)/86400).' 天';
        $html.='<article class="ms-subscription'.($expired?' is-expired':($soon?' is-soon':($state==='unknown'?' is-unknown':''))).'"><div class="ms-product"><h2><a href="'.e($path).'">'.e($item['product_name_snapshot']).'</a><small>'.e($item['plan_name_snapshot']).'</small></h2><p>订单号 <a href="'.e($path).'">'.e($item['order_no']).'</a></p><p class="ms-dates"><span>开通时间 '.e($item['opened_at']?str_replace('-','.',substr($item['opened_at'],0,10)):'未设置').'</span><i aria-hidden="true">/</i><span>到期时间 '.e($item['expires_at']?str_replace('-','.',substr($item['expires_at'],0,10)):'未设置').'</span></p></div><div class="ms-state"><strong>'.e($stateText).'</strong>'.($soon?'<p>到期后请按需重新选购。</p>':($state==='unknown'?'<p>请以订单交付说明为准。</p>':'')).'</div><nav class="ms-actions" aria-label="订阅 '.e($item['order_no']).' 的操作"><div><a class="ao-button'.(!$expired?' ao-primary':'').'" href="'.e($path.'#order-delivery').'">查看交付 ↗</a><a class="ao-button" href="'.e($path).'">查看订单</a></div>'.($expired?'<a class="ms-repurchase" href="/products">重新选购 ↗</a>':'').'</nav></article>';
    }
    if(!$matched)$html.='<div class="ms-empty"><h2>'.(count($items)?'暂无匹配订阅':'还没有已交付订阅').'</h2><p>'.(count($items)?'请尝试其他状态或关键词。':'购买并完成正式交付后，可在这里查看 VPN 订阅。').'</p><a href="/account/subscriptions">查看全部订阅 →</a><a href="/orders">查看我的订单 ↗</a></div>';
    $html.='</section><p class="ms-private-note"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><rect x="4" y="10" width="16" height="12" rx="2"/><path d="M7 10V7a5 5 0 0 1 10 0v3m-5 5v3"/></svg>订阅链接与使用说明，请进入交付内容查看或复制。</p><p class="ms-scope-note">即将到期指未来 7 天内到期，包含在有效订阅中。有效状态仅按登记的到期时间判断，不代表节点在线状态。'.($totalUnknown?'另有 '.$totalUnknown.' 项期限未设置，不计入有效或已到期统计。':'').'</p><section class="ms-help"><div><h2>拿到订阅后，如何开始使用？</h2><p>按对应平台教程导入正式订阅信息，开通与到期时间以交付信息为准。</p></div><nav aria-label="订阅使用指引"><a href="/downloads">下载适用客户端 ↗</a><a href="/articles?category='.rawurlencode('使用教程').'">查看导入教程 ↗</a></nav></section><footer class="ms-list-footer"><p>订阅有疑问？ <a href="/tickets#new-ticket">提交关联订单工单 ↗</a></p><nav class="ms-pagination" aria-label="订阅分页"><span>共 '.$total.' 项</span>';
    $html.=($page>1?'<a href="'.e($url(array('page'=>$page-1))).'">‹ 上一页</a>':'<span aria-disabled="true">‹ 上一页</span>').'<b aria-current="page">'.$page.' / '.$pages.'</b>'.($page<$pages?'<a href="'.e($url(array('page'=>$page+1))).'">下一页 ›</a>':'<span aria-disabled="true">下一页 ›</span>');
    return $html.'</nav></footer></div>';
}
$workspacePage = function($subscriptions = false) use($config,$pdo) {
    $uid=Security::requireUser();
    if($subscriptions){
        $query=$pdo->prepare('SELECT o.id,o.order_no,o.product_name_snapshot,o.plan_name_snapshot,d.opened_at,d.expires_at FROM orders o JOIN delivery_records d ON d.id=(SELECT latest.id FROM delivery_records latest WHERE latest.order_id=o.id AND latest.is_current=1 ORDER BY latest.id DESC LIMIT 1) WHERE o.user_id=? AND o.product_type_snapshot="vpn" AND o.status="delivered" ORDER BY o.id DESC');
        $query->execute(array($uid));
        return page('我的订阅',account_subscriptions_page($query->fetchAll(),time()),$config);
    }
    $query=$pdo->prepare('SELECT o.id,o.order_no,o.product_name_snapshot,o.plan_name_snapshot,o.duration_days_snapshot,o.traffic_snapshot,d.opened_at,d.expires_at FROM orders o JOIN delivery_records d ON d.order_id=o.id AND d.is_current=1 WHERE o.user_id=? AND o.product_type_snapshot="vpn" AND o.status="delivered" ORDER BY o.id DESC');
    $query->execute(array($uid));$items=$query->fetchAll();$active=0;$now=time();
    foreach($items as $item){
        $expiresAt=!empty($item['expires_at'])?strtotime($item['expires_at']):false;
        if($expiresAt!==false&&$expiresAt>$now)$active++;
    }
    $query=$pdo->prepare('SELECT COUNT(*) total,COALESCE(SUM(status="pending_payment"),0) pending,COALESCE(SUM(status="pending_delivery"),0) waiting,COALESCE(SUM(status="paid_waiting_group"),0) grouping FROM orders WHERE user_id=?');
    $query->execute(array($uid)); $stats=$query->fetch();
    $balance=(new WalletService($pdo))->balance($uid);
    $query=$pdo->prepare('SELECT display_name,email FROM users WHERE id=?'); $query->execute(array($uid)); $profile=$query->fetch(); $displayName=$profile['display_name']?:$profile['email'];
    $hour=(int)date('G'); $greeting=$hour<6?'夜深了':($hour<12?'上午好':($hour<18?'下午好':'晚上好'));
    $subscriptionRows='';
    foreach (array_slice($items,0,2) as $item) {
        $expiry=!empty($item['expires_at'])?strtotime($item['expires_at']):false;
        $label=$expiry===false?'期限未设置':($expiry<=time()?'已到期':'有效');
        $subscriptionRows.='<article class="ao-subscription"><div><h3>'.e($item['product_name_snapshot']).'<small>'.e($item['plan_name_snapshot']).'</small></h3><p>开通 '.e($item['opened_at']?substr($item['opened_at'],0,10):'未设置').'　到期 '.e($item['expires_at']?substr($item['expires_at'],0,10):'未设置').'</p></div><span class="ao-state">'.$label.'</span><a class="ao-button" href="'.e('/orders/'.rawurlencode($item['order_no']).'#order-delivery').'">查看交付 ↗</a></article>';
    }
    $query=$pdo->prepare('SELECT order_no,product_name_snapshot,plan_name_snapshot,total_amount_cents,status FROM orders WHERE user_id=? ORDER BY id DESC LIMIT 5'); $query->execute(array($uid)); $recent='';
    foreach ($query as $order) {
        $path='/orders/'.rawurlencode($order['order_no']);
        $action=$order['status']==='pending_payment'?'去付款':($order['status']==='delivered'?'查看交付':($order['status']==='paid_waiting_group'?'查看成团进度':'查看进度'));
        $recent.='<tr><td><a class="ao-order-name" href="'.e($path).'">'.e($order['product_name_snapshot']).' · '.e($order['plan_name_snapshot']).'</a><small>'.e($order['order_no']).'</small></td><td>'.e(money($order['total_amount_cents'])).'</td><td>'.e(status_text($order['status'])).'</td><td><a href="'.e($path.($order['status']==='delivered'?'#order-delivery':'')).'">'.$action.'</a></td></tr>';
    }
    $notifications=new NotificationService($pdo); $notes='';
    foreach ($notifications->recent($uid,3) as $note) $notes.='<a class="ao-message" href="'.e($notifications->targetPath($note)?:'/notifications').'"><h3>'.e($note['title']).'</h3><p>'.e(mb_substr($note['content'],0,60,'UTF-8')).' <span aria-hidden="true">›</span></p></a>';
    $body='<div class="ao-overview"><header class="ao-welcome"><div><span class="ao-kicker">ACCOUNT OVERVIEW</span><h1>'.e($greeting).'，'.e($displayName).'。</h1><p>在这里管理订单、交付内容与账户信息。</p></div><a class="ao-button ao-primary" href="/products">浏览订阅 ↗</a></header><section class="ao-stats" aria-label="账户状态统计">';
    foreach (array(array('/orders','待付款',(int)$stats['pending']),array('/orders','等待交付',(int)$stats['waiting']),array('/orders','等待成团',(int)$stats['grouping']),array('/account/subscriptions','有效订阅',$active)) as $stat) $body.='<a href="'.$stat[0].'"><span>'.$stat[1].'</span><strong>'.$stat[2].'</strong></a>';
    $body.='</section><div class="ao-subscription-balance"><section><header class="ao-section-head"><h2>我的订阅</h2><a href="/account/subscriptions">查看全部 ↗</a></header><div class="ao-subscription-list">'.($subscriptionRows?:'<div class="ao-empty"><h3>暂无已交付订阅</h3><p>购买并完成交付后可在此查看。</p><a href="/products">浏览订阅 ↗</a></div>').'</div><p class="ao-subscription-note">'.(count($items)>2?'仅显示最近 2 项，共 '.count($items).' 项已交付 VPN 订阅。':'').'有效状态仅按登记的到期时间判断，不代表节点在线状态。</p></section><aside class="ao-balance"><h2>我的余额</h2><p class="ao-amount"><span>¥</span>'.e(WalletService::formatAmount($balance)).'</p><p>可用于订单全额支付</p><a href="/wallet">查看余额明细 ↗</a><small>余额不足时请选择订单提供的其他支付方式，不支持拆分付款。</small></aside></div><section class="ao-recent-orders"><header class="ao-section-head"><div><h2>最近订单</h2><p>共 '.(int)$stats['total'].' 笔订单 · 显示最近 5 笔</p></div><a href="/orders">查看全部 ↗</a></header><div class="ao-table-wrap"><table class="ao-orders-table"><thead><tr><th scope="col">订单商品</th><th scope="col">金额</th><th scope="col">状态</th><th scope="col">操作</th></tr></thead><tbody>'.($recent?:'<tr><td colspan="4"><div class="ao-empty">暂无订单，可先<a href="/products">浏览订阅 ↗</a>或<a href="/accounts">选购海外账号 ↗</a>。</div></td></tr>').'</tbody></table></div></section><div class="ao-bottom-grid"><section><header class="ao-section-head"><h2>最近消息</h2><a href="/notifications">查看全部 ↗</a></header><div class="ao-messages">'.($notes?:'<div class="ao-empty">暂无新消息。</div>').'</div></section><section class="ao-help"><h2>服务与帮助</h2><nav aria-label="服务与帮助"><a href="/tickets">我的工单 ↗</a><a href="/articles">查看使用教程 ↗</a><a href="/feedback">提交反馈 ↗</a></nav></section></div><p class="ao-security-note">交付内容仅本人登录可见，查看或复制订阅链接时请妥善保管。</p></div>';
    page('个人总览',$body,$config);
};
$router->get('/account/overview',function()use($workspacePage){return $workspacePage(false);});
$router->get('/account/subscriptions',function()use($workspacePage){return $workspacePage(true);});
function account_wallet_page($pdo,$uid) {
    $types=array('admin_adjust'=>'管理员调账','order_payment'=>'订单余额支付','order_refund'=>'余额退款','referral_credit'=>'邀请返佣','referral_reversal'=>'返佣撤回','feedback_reward'=>'反馈奖励');
    $filters=array('direction'=>'','type'=>'','q'=>'','range'=>'30');
    try {
        foreach($filters as $key=>$default){if(!isset($_GET[$key]))continue;$value=$_GET[$key];if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||mb_strlen($value,'UTF-8')>80)throw new InvalidArgumentException('账本筛选参数无效。');$filters[$key]=trim($value);}
        if(!in_array($filters['direction'],array('','income','expense'),true)||($filters['type']!==''&&!isset($types[$filters['type']]))||!in_array($filters['range'],array('30','90','all'),true))throw new InvalidArgumentException('账本筛选选项无效。');
        $requested=isset($_GET['page'])?$_GET['page']:'1';if(!is_string($requested)||!preg_match('/\A[1-9][0-9]{0,8}\z/D',$requested))throw new InvalidArgumentException('页码无效。');
    } catch(InvalidArgumentException $ex) {
        http_response_code(400);return '<div class="mw-page"><section class="mw-empty"><h1>查询参数无效</h1><p>'.e($ex->getMessage()).'</p><a href="/wallet">返回我的余额 →</a></section></div>';
    }
    $now=time();$until=date('Y-m-d H:i:s',$now);$since=date('Y-m-d H:i:s',$now-30*86400);$balance=(new WalletService($pdo))->balance($uid);
    $s=$pdo->prepare('SELECT COALESCE(SUM(CASE WHEN amount_cents>0 THEN amount_cents ELSE 0 END),0) income,COALESCE(SUM(CASE WHEN amount_cents<0 THEN -amount_cents ELSE 0 END),0) expense FROM wallet_transactions WHERE user_id=? AND created_at>=? AND created_at<=?');$s->execute(array($uid,$since,$until));$summary=$s->fetch();
    // 返佣订单属于被邀请人，只关联本人付款/退款订单；反馈也必须属于本人。
    $join=' FROM wallet_transactions w LEFT JOIN orders o ON o.id=w.order_id AND o.user_id=w.user_id AND w.type IN ("order_payment","order_refund") LEFT JOIN feedback_submissions f ON f.reward_transaction_id=w.id AND f.user_id=w.user_id AND w.type="feedback_reward"';
    $where='w.user_id=?';$args=array($uid);
    if($filters['range']!=='all'){$where.=' AND w.created_at>=? AND w.created_at<=?';$args[]=date('Y-m-d H:i:s',$now-(int)$filters['range']*86400);$args[]=$until;}
    if($filters['direction']!=='')$where.=$filters['direction']==='income'?' AND w.amount_cents>0':' AND w.amount_cents<0';
    if($filters['type']!==''){$where.=' AND w.type=?';$args[]=$filters['type'];}
    if($filters['q']!==''){$where.=" AND o.order_no LIKE ? ESCAPE '!'";$args[]='%'.str_replace(array('!','%','_'),array('!!','!%','!_'),$filters['q']).'%';}
    $s=$pdo->prepare('SELECT COUNT(*)'.$join.' WHERE '.$where);$s->execute($args);$total=(int)$s->fetchColumn();$perPage=20;$pages=max(1,(int)ceil($total/$perPage));$page=min((int)$requested,$pages);
    $s=$pdo->prepare('SELECT w.id,w.amount_cents,w.balance_before_cents,w.balance_after_cents,w.type,w.created_at,o.order_no,f.id feedback_id,f.feedback_no'.$join.' WHERE '.$where.' ORDER BY w.created_at DESC,w.id DESC LIMIT '.$perPage.' OFFSET '.(($page-1)*$perPage));$s->execute($args);$rows='';
    foreach($s as $item){
        $amount=(int)$item['amount_cents'];$signed=($amount>0?'+¥':($amount<0?'-¥':'¥')).WalletService::formatAmount(abs($amount));$record='余额变动';$target='';
        if(!empty($item['order_no'])){$record='订单 '.$item['order_no'];$target='/orders/'.rawurlencode($item['order_no']);}
        elseif($item['type']==='feedback_reward'&&!empty($item['feedback_id'])){$record='反馈 '.$item['feedback_no'];$target='/feedback/'.(int)$item['feedback_id'];}
        elseif($item['type']==='admin_adjust')$record='人工调整';
        elseif($item['type']==='referral_credit')$record='返佣入账';
        elseif($item['type']==='referral_reversal')$record='返佣撤回';
        elseif($item['type']==='feedback_reward')$record='反馈奖励';
        $action=$target!==''?'<a href="'.e($target).'" aria-label="查看'.e($record).'">查看</a>':'<details class="mw-record-details"><summary aria-label="查看余额流水 '.(int)$item['id'].'">查看</summary><div><b>余额流水 #'.(int)$item['id'].'</b><p>变动前余额 ¥'.e(WalletService::formatAmount($item['balance_before_cents'])).'</p><p>收支金额 '.e($signed).'</p><p>变动后余额 ¥'.e(WalletService::formatAmount($item['balance_after_cents'])).'</p></div></details>';
        $rows.='<tr><td data-label="时间">'.e(str_replace('-','.',substr($item['created_at'],0,16))).'</td><td data-label="变动类型">'.e(isset($types[$item['type']])?$types[$item['type']]:'其他变动').'</td><td data-label="收支金额" class="'.($amount>0?'mw-credit':'mw-debit').'">'.e($signed).'</td><td data-label="变动后余额">¥'.e(WalletService::formatAmount($item['balance_after_cents'])).'</td><td data-label="关联记录" class="mw-record">'.e($record).'</td><td data-label="操作">'.$action.'</td></tr>';
    }
    $url=function($changes=array())use($filters){return '/wallet?'.http_build_query(array_merge($filters,$changes),'','&',PHP_QUERY_RFC3986);};
    $hidden=function($exclude)use($filters){$html='';foreach($filters as $key=>$value)if(!in_array($key,$exclude,true)&&$value!=='')$html.='<input type="hidden" name="'.$key.'" value="'.e($value).'">';return $html;};
    $html='<div class="mw-page"><header class="ao-welcome mw-welcome"><div><span class="ao-kicker">MY BALANCE</span><h1>我的余额</h1><p>查看可用余额与每一笔收支记录。</p></div><a class="mw-text-link" href="/articles?q='.rawurlencode('余额').'">查看支付说明 ↗</a></header><div class="mw-top-grid"><section class="mw-balance"><h2>当前可用余额</h2><div class="mw-balance-main"><div><p class="mw-amount"><span>¥</span>'.e(WalletService::formatAmount($balance)).'</p><p>可用于订单全额支付</p></div><a class="ao-button ao-primary" href="/products">浏览商品 ↗</a></div><dl class="mw-summary"><div><dt>近30天入账</dt><dd>¥'.e(WalletService::formatAmount($summary['income'])).'</dd></div><div><dt>近30天支出</dt><dd>¥'.e(WalletService::formatAmount($summary['expense'])).'</dd></div></dl></section><section class="mw-usage"><h2>余额使用说明</h2><ol><li><span>01</span>仅支持余额全额支付</li><li><span>02</span>余额不足时，选择订单提供的其他方式</li><li><span>03</span>不能与在线支付组合使用</li></ol></section></div><p class="mw-balance-note">余额变动可在下方账本中查询。近30天统计不受账本筛选影响。</p><p class="mw-source-strip">余额来源：管理员调账、订单退款、邀请返佣及反馈奖励。不支持在线充值或提现。</p><section class="mw-ledger" aria-label="余额账本"><header class="mw-ledger-head"><h2>余额账本</h2><form class="mw-range" method="get" action="/wallet">'.$hidden(array('range')).'<select name="range" aria-label="账本时间范围"><option value="30"'.($filters['range']==='30'?' selected':'').'>最近30天</option><option value="90"'.($filters['range']==='90'?' selected':'').'>最近90天</option><option value="all"'.($filters['range']==='all'?' selected':'').'>全部时间</option></select><button type="submit">应用</button></form></header><div class="mw-toolbar"><nav class="mw-tabs" aria-label="账本收支筛选">';
    foreach(array(''=>'全部','income'=>'收入','expense'=>'支出') as $key=>$label)$html.='<a href="'.e($url(array('direction'=>$key))).'"'.($filters['direction']===$key?' aria-current="page"':'').'>'.$label.'</a>';
    $html.='</nav><form class="mw-filter" method="get" action="/wallet">'.$hidden(array('type','q')).'<select name="type" aria-label="账本来源"><option value="">来源：全部</option>';
    foreach($types as $key=>$label)$html.='<option value="'.$key.'"'.($filters['type']===$key?' selected':'').'>'.$label.'</option>';
    $html.='</select><label><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="10" cy="10" r="7"/><path d="m15 15 6 6"/></svg><input type="search" name="q" maxlength="80" value="'.e($filters['q']).'" placeholder="搜索关联订单号…" aria-label="搜索本人关联订单号"></label><button type="submit">筛选</button></form></div>';
    if($filters['q']!==''||$filters['type']!==''||$filters['direction']!=='')$html.='<p class="mw-results-note">筛选匹配 '.$total.' 条'.($filters['q']!==''?' · 订单号包含「'.e($filters['q']).'」':'').' · <a href="/wallet">清除筛选</a></p>';
    $html.='<div class="mw-table-wrap"><table class="mw-table"><thead><tr><th scope="col">时间</th><th scope="col">变动类型</th><th scope="col">收支金额</th><th scope="col">变动后余额</th><th scope="col">关联记录</th><th scope="col"><span class="mw-sr-only">操作</span></th></tr></thead><tbody>'.($rows?:'<tr><td colspan="6"><div class="mw-empty"><h3>暂无匹配的余额变动记录</h3><p>可尝试其他时间范围或筛选条件；发生余额支付、退款或奖励后，可在这里查询。</p><a href="/wallet?range=all">查看全部账本 →</a></div></td></tr>').'</tbody></table></div><footer class="mw-ledger-footer"><p>明细按发生时间排序，历史余额为该笔变动后的记录。关联记录仅展示本人订单或反馈。</p><nav class="mw-pagination" aria-label="账本分页"><span>共 '.$total.' 条</span>';
    $html.=($page>1?'<a href="'.e($url(array('page'=>$page-1))).'">‹ 上一页</a>':'<span aria-disabled="true">‹ 上一页</span>').'<b aria-current="page">'.$page.' / '.$pages.'</b>'.($page<$pages?'<a href="'.e($url(array('page'=>$page+1))).'">下一页 ›</a>':'<span aria-disabled="true">下一页 ›</span>');
    return $html.'</nav></footer></section><div class="mw-bottom-grid"><section class="mw-sources"><h2>余额从哪里来？</h2><p><span>管理员调账</span><span>订单退款</span><span>邀请返佣</span><span>反馈奖励</span></p></section><section class="mw-help"><div><h2>账目有疑问？</h2><p>可关联订单提交工单，补充变动时间与问题说明。</p></div><nav aria-label="账本帮助"><a class="ao-button" href="/tickets#new-ticket">提交工单 ↗</a><a class="ao-button" href="/articles">查看帮助 ↗</a></nav></section></div></div>';
}
$router->get('/wallet', function() use($config,$pdo){$uid=Security::requireUser();page('我的余额',account_wallet_page($pdo,$uid),$config);});
$router->post('/orders/{no}/balance-pay',function($no) use($config,$pdo){$uid=Security::requireUser();post_csrf();try{(new OrderService($pdo))->payWithBalance($no,$uid);flash('success','余额支付已完成，请查看订单状态。');return redirect('/orders/'.rawurlencode($no).'/payment-result');}catch(Exception $e){flash('error',$e->getMessage());}redirect('/orders/'.rawurlencode($no));});
$router->post('/orders/{no}/cancel', function($no) use($config,$pdo){$uid=Security::requireUser();post_csrf();try{(new OrderService($pdo))->cancel($no,'user',$uid,isset($_POST['reason'])?$_POST['reason']:'');flash('success','订单已取消。');}catch(Exception $e){flash('error',$e->getMessage());}redirect('/orders/'.$no);});
function account_settings_page($pdo,$uid,$section='profile'){
    $q=$pdo->prepare('SELECT display_name,email,created_at FROM users WHERE id=?');$q->execute(array($uid));$u=$q->fetch();$name=$u['display_name']?:$u['email'];
    $eye='<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
    $fields='';foreach(array('old_password'=>array('当前密码','请输入当前密码','current-password'),'new_password'=>array('新密码','至少8个字符','new-password'),'confirm_password'=>array('确认新密码','再次输入新密码','new-password')) as $field=>$info)$fields.='<div class="as-field"><label for="settings-'.$field.'">'.$info[0].'</label><div class="as-password-input"><input id="settings-'.$field.'" type="password" '.($field!=='confirm_password'?'name="'.$field.'" ':'').'autocomplete="'.$info[2].'" placeholder="'.$info[1].'"'.($field==='new_password'?' minlength="8"':'').' required><button type="button" class="as-reveal" data-password="settings-'.$field.'" aria-controls="settings-'.$field.'" aria-pressed="false" aria-label="显示'.$info[0].'">'.$eye.'</button></div></div>';
    return '<div class="as-page"><header class="ao-welcome as-welcome"><div><p class="ao-kicker">PROFILE &amp; SETTINGS</p><h1>个人资料</h1><p>查看账户信息，管理昵称与登录密码。</p></div></header><nav class="as-tabs" aria-label="账户设置分类"><a href="/account#settings-profile"'.($section==='profile'?' aria-current="page"':'').'>个人资料</a><a href="/account/security#settings-password"'.($section==='security'?' aria-current="page"':'').'>密码设置</a></nav><div class="as-grid"><section class="as-profile as-card" id="settings-profile" aria-labelledby="settings-profile-title"><h2 id="settings-profile-title">基本资料</h2><p>公开昵称可修改，登录邮箱暂不支持自行更改。</p><div class="as-identity"><span aria-hidden="true">'.user_nav_icon('account').'</span><strong>'.e($name).'</strong></div><dl class="as-facts"><div><dt>登录邮箱</dt><dd>'.e($u['email']).' <span>不可修改</span></dd></div><div><dt>注册时间</dt><dd><time datetime="'.e(str_replace(' ','T',$u['created_at'])).'">'.e(str_replace('-','.',substr($u['created_at'],0,16))).'</time></dd></div></dl><form method="post" action="/account/profile" class="as-profile-form" data-settings-form>'.csrf_field().'<div class="as-field"><label for="settings-name">昵称</label><div><input id="settings-name" name="display_name" maxlength="100" value="'.e($u['display_name']).'" aria-describedby="settings-name-hint" required><p id="settings-name-hint" class="as-hint">用于站内显示，可随时修改；最多100字节。</p></div></div><div class="as-form-actions"><button class="ao-button ao-primary" type="submit">保存昵称</button><span class="as-submit-status" role="status"></span></div></form></section><aside class="as-account-info"><h2>账户信息说明</h2><ul><li>邮箱用于登录与接收交易通知。</li><li>昵称修改不会影响已有订单与交付记录。</li><li>如需帮助，请提交工单联系管理员。</li></ul><a class="ao-button" href="/tickets#new-ticket">提交账户问题工单 ↗</a><a class="as-notifications" href="/account/notifications">查看最近通知 ↗</a></aside><section class="as-password as-card" id="settings-password" aria-labelledby="settings-password-title"><h2 id="settings-password-title">修改登录密码</h2><p>请先验证当前密码，再设置新密码。</p><form method="post" action="/account/password" data-settings-form>'.csrf_field().$fields.'<div class="as-form-actions"><button class="ao-button ao-primary" type="submit">保存新密码</button><a href="/password/forgot">忘记当前密码？ ↗</a><span class="as-submit-status" role="status"></span><p class="as-hint">如忘记密码，可通过邮箱找回密码。</p></div></form><p class="as-security-note">ⓘ 修改密码后当前会话将更新，请妥善保管新密码。</p></section><aside class="as-password-tips"><h2>密码设置建议</h2><ol><li><span>01</span>使用至少8个字符的密码。</li><li><span>02</span>避免与其他网站重复使用。</li><li><span>03</span>请勿向他人透露登录密码。</li></ol><a href="/articles?q='.rawurlencode('账户安全').'">查看账户安全帮助 ↗</a></aside></div><p class="as-unsaved-note">ⓘ 保存前请确认填写内容；离开有未保存内容的页面时，浏览器将提示确认。</p><div class="as-help"><strong>需要帮助？</strong><a class="ao-button" href="/articles">前往帮助中心 ↗</a><a class="ao-button" href="/tickets">我的工单 ↗</a></div></div><script>(function(){var forms=Array.from(document.querySelectorAll("[data-settings-form]")),leaving=false;forms.forEach(function(form){var fields=Array.from(form.querySelectorAll("input:not([type=hidden])"));form.isDirty=function(){return fields.some(function(input){return input.value!==input.defaultValue})};form.addEventListener("submit",function(event){if(form.dataset.busy==="true"){event.preventDefault();return}if(forms.some(function(other){return other!==form&&other.isDirty()})&&!window.confirm("另一张表单有未保存内容，继续保存会离开页面，是否继续？")){event.preventDefault();return}form.dataset.busy="true";leaving=true;form.querySelector("button[type=submit]").disabled=true;form.querySelector(".as-submit-status").textContent="正在保存，请勿重复提交。"})});var name=document.getElementById("settings-name"),password=document.getElementById("settings-new_password"),confirm=document.getElementById("settings-confirm_password");function validateName(){name.setCustomValidity(new TextEncoder().encode(name.value.trim()).length>100?"昵称最多100字节，请缩短后重试。":"")}function validateConfirm(){confirm.setCustomValidity(confirm.value!==password.value?"两次输入的新密码不一致。":"")}name.addEventListener("input",validateName);password.addEventListener("input",validateConfirm);confirm.addEventListener("input",validateConfirm);document.querySelectorAll("[data-password]").forEach(function(button){var input=document.getElementById(button.dataset.password),label=button.getAttribute("aria-label").slice(2);button.addEventListener("click",function(){var show=input.type==="password";input.type=show?"text":"password";button.setAttribute("aria-pressed",String(show));button.setAttribute("aria-label",(show?"隐藏":"显示")+label)})});window.addEventListener("beforeunload",function(event){if(!leaving&&forms.some(function(form){return form.isDirty()})){event.preventDefault();event.returnValue=""}});window.addEventListener("pageshow",function(){leaving=false;forms.forEach(function(form){form.dataset.busy="false";form.querySelector("button[type=submit]").disabled=false;form.querySelector(".as-submit-status").textContent=""});document.querySelectorAll("[data-password]").forEach(function(button){document.getElementById(button.dataset.password).type="password";button.setAttribute("aria-pressed","false");button.setAttribute("aria-label","显示"+button.getAttribute("aria-label").slice(2))});validateName();validateConfirm()})})();</script>';
}
$accountPage=function($section='profile') use($config,$pdo){$uid=Security::requireUser();if($section!=='notifications')return page('账户与安全',account_settings_page($pdo,$uid,$section),$config);$s=$pdo->prepare('SELECT display_name,email FROM users WHERE id=?');$s->execute(array($uid));$u=$s->fetch();$displayName=$u['display_name']?:$u['email'];$avatar=e(mb_substr($displayName,0,1,'UTF-8'));$content='';if($section==='notifications'){$n=(new NotificationService($pdo))->recent($uid,5);$notes='';foreach($n as $item)$notes.='<div class="notification-item '.($item['read_at']?'':'unread').'">'.e($item['title']).'<small>'.e($item['created_at']).'</small><p>'.e($item['content']).'</p></div>';$content.='<section class="form-card account-notices-card" id="notifications"><div class="section-head"><div><span class="account-section-kicker">UPDATES</span><h2>最近通知</h2></div><a href="/notifications">查看全部　→</a></div>'.($notes?:'<div class="empty">暂无通知。</div>').'</section>';}$nav='';foreach(array('profile'=>array('/account','◎　基本信息'),'security'=>array('/account/security','♢　账户安全'),'notifications'=>array('/account/notifications','♧　消息通知')) as $key=>$item)$nav.='<a href="'.$item[0].'"'.($section===$key?' class="active" aria-current="page"':'').'>'.$item[1].'</a>';$body='<div class="account-page"><header class="account-page-heading"><div><span class="account-eyebrow">ACCOUNT CENTER</span><h1>账户设置</h1><p>管理你的个人资料与账户安全。</p></div></header><section class="account-identity-card"><span class="account-identity-avatar">'.$avatar.'</span><div class="account-identity-copy"><span>欢迎回来</span><h2>'.e($displayName).'</h2><p>'.e($u['email']).'</p></div><span class="account-member-badge">个人账户</span></section><nav class="account-settings-nav" aria-label="账户设置分类">'.$nav.'</nav><div class="account-settings-layout"><div class="account-settings-content">'.$content.'</div><aside class="pc-security-tips"><h2>安全提示</h2><ul><li>请定期更新密码，避免与其他网站重复使用。</li><li>登录邮箱仅供查看，如有问题请联系支持。</li><li>不要向他人透露密码或分享交付内容。</li><li>遇到订单问题，可关联本人订单提交工单。</li></ul><a href="/tickets">获取帮助 →</a></aside></div></div>';page('个人中心',$body,$config);};
$router->get('/account',function() use($accountPage){$accountPage('profile');});
$router->get('/account/security',function() use($accountPage){$accountPage('security');});
$router->get('/account/notifications',function() use($accountPage){$accountPage('notifications');});
$router->post('/account/profile', function() use($config,$pdo){$uid=Security::requireUser();post_csrf();$name=trim(isset($_POST['display_name'])?$_POST['display_name']:'');if($name===''||strlen($name)>100){flash('error','昵称长度必须为 1-100 个字符。');}else{$pdo->prepare('UPDATE users SET display_name=?,updated_at=? WHERE id=?')->execute(array($name,date('Y-m-d H:i:s'),$uid));$_SESSION['user_name']=$name;flash('success','资料已更新。');}redirect('/account');});
$router->post('/account/password', function() use($config,$pdo){$uid=Security::requireUser();post_csrf();$s=$pdo->prepare('SELECT password_hash FROM users WHERE id=?');$s->execute(array($uid));$u=$s->fetch();if(!$u||!password_verify(isset($_POST['old_password'])?$_POST['old_password']:'',$u['password_hash'])||strlen(isset($_POST['new_password'])?$_POST['new_password']:'')<8){flash('error','旧密码不正确或新密码少于 8 位。');}else{$pdo->prepare('UPDATE users SET password_hash=?,updated_at=? WHERE id=?')->execute(array(password_hash($_POST['new_password'],PASSWORD_DEFAULT),date('Y-m-d H:i:s'),$uid));session_regenerate_id(true);$_SESSION['user_id']=$uid;$_SESSION['user_name']=isset($_SESSION['user_name'])?$_SESSION['user_name']:'';flash('success','密码已更新，请妥善保管。');}redirect('/account/security');});
$router->get('/admin/login', function() use($config){page('管理员登录','<div class="form-card"><h1>管理员登录</h1><form method="post">'.csrf_field().'<label>邮箱<input type="email" name="email" required></label><label>密码<input type="password" name="password" required></label><button class="button">登录后台</button></form></div>',$config);});
$router->post('/admin/login', function() use($config,$pdo){
    post_csrf();
    $email=isset($_POST['email'])&&is_string($_POST['email'])?trim($_POST['email']):'';
    $password=isset($_POST['password'])&&is_string($_POST['password'])?$_POST['password']:'';
    try{$a=(new AdminLogService($pdo))->authenticate($email,$password,isset($_SERVER['REMOTE_ADDR'])?$_SERVER['REMOTE_ADDR']:'');}
    catch(Throwable $e){error_log('admin_login_unavailable');flash('error','管理员登录暂不可用，请稍后重试。');return redirect('/admin/login');}
    if(!$a){flash('error','管理员账号或密码错误。');return redirect('/admin/login');}
    session_regenerate_id(true);$_SESSION['admin_id']=$a['id'];$_SESSION['admin_name']=$a['display_name'];redirect('/admin');
});
$router->post('/admin/logout', function(){post_csrf();unset($_SESSION['admin_id'],$_SESSION['admin_name']);redirect('/admin/login');});
$router->get('/admin/temporary-subscription', function() use($config,$pdo){Security::requireAdmin();$s=$pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key="temporary_subscription_url"');$s->execute();$url=(string)$s->fetchColumn();$body='<div class="product-form-card"><div class="product-form-heading"><div><span class="admin-kicker">DELIVERY / 临时订阅</span><h1>临时订阅设置</h1><p>付款确认后自动展示给用户，正式人工发货后自动标记失效。</p></div></div><form method="post" action="/admin/temporary-subscription"><input type="hidden" name="_csrf" value="'.e(Security::csrfToken()).'"><div class="product-form-section"><h2>临时订阅地址</h2><p>固定地址会保存到新确认付款的订单中，修改后不会影响旧订单。</p><label>订阅地址<input type="url" name="temporary_subscription_url" value="'.e($url).'" placeholder="https://example.com/temporary-subscription" maxlength="500"><small class="field-help">留空表示关闭临时订阅；仅支持 HTTPS 地址。</small></label></div><div class="product-form-actions"><a class="secondary-button" href="/admin">返回工作台</a><button class="button" type="submit">保存设置</button></div></form></div>';page('临时订阅',$body,$config);});
$router->post('/admin/temporary-subscription', function() use($config,$pdo){$aid=Security::requireAdmin();post_csrf();$url=trim(isset($_POST['temporary_subscription_url'])?$_POST['temporary_subscription_url']:'');if($url!==''&&(!filter_var($url,FILTER_VALIDATE_URL)||parse_url($url,PHP_URL_SCHEME)!=='https'||strlen($url)>500)){flash('error','请输入有效的 HTTPS 临时订阅地址，或留空关闭。');return redirect('/admin/temporary-subscription');}$now=date('Y-m-d H:i:s');$pdo->prepare('INSERT INTO system_settings(setting_key,setting_value,updated_by,updated_at) VALUES("temporary_subscription_url",?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)')->execute(array($url,$aid,$now));$pdo->prepare('INSERT INTO admin_logs(admin_id,action,target_type,details_json,created_at) VALUES(?,?,?,?,?)')->execute(array($aid,'update_temporary_subscription','system',json_encode(array('enabled'=>$url!=='')),$now));flash('success',$url?'临时订阅地址已更新。':'临时订阅已关闭。');redirect('/admin/temporary-subscription');});
$router->get('/admin/payment', function() use($config,$pdo){Security::requireAdmin();$service=new PaymentService($pdo,$config);$s=$pdo->query('SELECT * FROM payment_settings WHERE id=1');$r=$s->fetch();$stats=$pdo->query('SELECT COUNT(*) attempts,COALESCE(SUM(status="confirmed"),0) paid,COALESCE(SUM(CASE WHEN status="confirmed" THEN amount_cents ELSE 0 END),0) cents FROM payment_records WHERE method LIKE "epay_%"')->fetch();$recent='';foreach($pdo->query('SELECT p.*,o.order_no FROM payment_records p JOIN orders o ON o.id=p.order_id WHERE p.method LIKE "epay_%" ORDER BY p.id DESC LIMIT 10') as $p)$recent.='<tr><td><a href="/admin/orders/'.e($p['order_no']).'">'.e($p['order_no']).'</a></td><td>'.($p['gateway_type']==='alipay'?'支付宝':'微信支付').'</td><td>'.money($p['amount_cents']).'</td><td>'.($p['status']==='confirmed'?'已支付':'待支付').'</td><td>'.e($p['created_at']).'</td></tr>';$keyNotice=$r&&!empty($r['md5_key_ciphertext'])?'<p class="field-help">密钥已加密保存。留空表示保持当前密钥不变；不会向页面显示密钥。</p>':'<p class="field-help">请输入商户 MD5 密钥。密钥将使用应用加密密钥加密保存。</p>';$body='<div class="product-form-card"><div class="product-form-heading"><div><span class="admin-kicker">PAYMENT / 易支付</span><h1>在线支付设置</h1><p>维护易支付网关、商户信息、支付方式与公网回调地址。</p></div></div><form method="post" action="/admin/payment">'.csrf_field().'<div class="product-form-grid"><div class="product-form-section"><h2>网关信息</h2><label>网关地址<input type="url" name="gateway_url" maxlength="500" value="'.e($r?$r['gateway_url']:'https://pae.fksqy.cn/'). '" required><small class="field-help">例如易支付服务商提供的 HTTPS 根地址，不要填写 submit.php。</small></label><label>商户 ID<input name="pid" maxlength="40" value="'.e($r?$r['pid']:''). '" required></label><label>MD5 密钥<input type="password" name="md5_key" maxlength="500" autocomplete="new-password">'.$keyNotice.'</label><label>公网回调根地址<input type="url" name="notify_base_url" maxlength="500" value="'.e($r?$r['notify_base_url']:''). '" placeholder="https://your-domain.example" required><small class="field-help">用于生成 /payment/notify 与 /payment/return，需为公网可访问的 HTTP 或 HTTPS 地址；HTTP 适合 IP 测试，正式环境推荐 HTTPS。</small></label></div><div class="product-form-section"><h2>启用方式</h2><label><input type="checkbox" name="enabled" value="1"'.($r&&!empty($r['enabled'])?' checked':'').'> 启用在线支付</label><label><input type="checkbox" name="alipay_enabled" value="1"'.(!$r||!empty($r['alipay_enabled'])?' checked':'').'> 启用支付宝</label><label><input type="checkbox" name="wechat_enabled" value="1"'.(!$r||!empty($r['wechat_enabled'])?' checked':'').'> 启用微信支付</label><p class="field-help">安全提示：密钥只保存在服务器并加密存储；勿通过截图或公开渠道分享。</p></div></div><div class="product-form-actions"><a class="secondary-button" href="/admin">返回工作台</a><button class="button" type="submit">保存设置</button></div></form></div><section class="admin-panel"><h2>支付概览</h2><p>累计支付尝试：'.(int)$stats['attempts'].'　已支付：'.(int)$stats['paid'].' 笔　成功金额：'.money($stats['cents']).'</p><div class="table-wrap"><table><thead><tr><th>订单号</th><th>方式</th><th>金额</th><th>状态</th><th>时间</th></tr></thead><tbody>'.($recent?:'<tr><td colspan="5">暂无在线支付记录。</td></tr>').'</tbody></table></div></section>';page('支付设置',$body,$config);});
$router->post('/admin/payment', function() use($config,$pdo){$aid=Security::requireAdmin();post_csrf();try{$gateway=rtrim(trim(isset($_POST['gateway_url'])?$_POST['gateway_url']:''),'/');$pid=trim(isset($_POST['pid'])?$_POST['pid']:'');$base=rtrim(trim(isset($_POST['notify_base_url'])?$_POST['notify_base_url']:''),'/');$key=trim(isset($_POST['md5_key'])?$_POST['md5_key']:'');foreach(array($gateway,$base) as $url){if(!filter_var($url,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),array('http','https'),true))throw new InvalidArgumentException('网关和回调地址必须是有效的 HTTP 或 HTTPS URL。');}$host=strtolower((string)parse_url($base,PHP_URL_HOST));if($host==='localhost'||$host==='127.0.0.1'||$host==='::1'||strpos($host,'192.168.')===0||strpos($host,'10.')===0)throw new InvalidArgumentException('回调地址必须是公网域名，不能填写本地或内网地址。');if($pid===''||strlen($pid)>40||strlen($key)>500)throw new InvalidArgumentException('请检查商户 ID 和密钥长度。');$old=$pdo->query('SELECT * FROM payment_settings WHERE id=1')->fetch();if($key!==''){list($cipher,$nonce,$tag)=(new CryptoService($config))->encrypt($key);}elseif($old&&!empty($old['md5_key_ciphertext'])){$cipher=$old['md5_key_ciphertext'];$nonce=$old['md5_key_nonce'];$tag=$old['md5_key_tag'];}else throw new InvalidArgumentException('首次配置必须填写 MD5 密钥。');$enabled=isset($_POST['enabled'])?1:0;if($enabled&&(!isset($_POST['alipay_enabled'])&&!isset($_POST['wechat_enabled'])))throw new InvalidArgumentException('启用在线支付时至少选择一种支付方式。');$now=date('Y-m-d H:i:s');$pdo->prepare('INSERT INTO payment_settings(id,gateway_url,pid,md5_key_ciphertext,md5_key_nonce,md5_key_tag,enabled,alipay_enabled,wechat_enabled,notify_base_url,updated_by,updated_at) VALUES(1,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE gateway_url=VALUES(gateway_url),pid=VALUES(pid),md5_key_ciphertext=VALUES(md5_key_ciphertext),md5_key_nonce=VALUES(md5_key_nonce),md5_key_tag=VALUES(md5_key_tag),enabled=VALUES(enabled),alipay_enabled=VALUES(alipay_enabled),wechat_enabled=VALUES(wechat_enabled),notify_base_url=VALUES(notify_base_url),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)')->execute(array($gateway,$pid,$cipher,$nonce,$tag,$enabled,isset($_POST['alipay_enabled'])?1:0,isset($_POST['wechat_enabled'])?1:0,$base,$aid,$now));$pdo->prepare('INSERT INTO admin_logs(admin_id,action,target_type,details_json,created_at) VALUES(?,?,?,?,?)')->execute(array($aid,'update_payment_settings','system',json_encode(array('enabled'=>(bool)$enabled,'pid'=>$pid,'gateway_url'=>$gateway)), $now));flash('success','支付设置已保存。');}catch(Exception $e){flash('error',$e->getMessage());}redirect('/admin/payment');});
$router->get('/admin/orders', function() use($config,$pdo){Security::requireAdmin();$status=isset($_GET['status'])?$_GET['status']:'';$q=isset($_GET['q'])?trim($_GET['q']):'';$from=isset($_GET['from'])?trim($_GET['from']):'';$to=isset($_GET['to'])?trim($_GET['to']):'';$min=isset($_GET['min_amount'])?trim($_GET['min_amount']):'';$max=isset($_GET['max_amount'])?trim($_GET['max_amount']):'';$allowed=array('pending_payment','pending_delivery','delivered','cancelled','refunding','refunded');$where=array('1=1');$args=array();if(in_array($status,$allowed,true)){$where[]='o.status=?';$args[]=$status;}if($q){$where[]='(o.order_no LIKE ? OR o.product_name_snapshot LIKE ? OR u.email LIKE ?)';$args[]='%'.$q.'%';$args[]='%'.$q.'%';$args[]='%'.$q.'%';}if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)){$where[]='o.created_at>=?';$args[]=$from.' 00:00:00';}if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)){$where[]='o.created_at<=?';$args[]=$to.' 23:59:59';}if(is_numeric($min)){$where[]='o.total_amount_cents>=?';$args[]=(int)round($min*100);}if(is_numeric($max)){$where[]='o.total_amount_cents<=?';$args[]=(int)round($max*100);}$s=$pdo->prepare('SELECT o.*,u.email FROM orders o JOIN users u ON u.id=o.user_id WHERE '.implode(' AND ',$where).' ORDER BY o.id DESC');$s->execute($args);$rows='';foreach($s as $o){$can=$o['status']==='pending_payment'||$o['status']==='pending_delivery'||$o['status']==='refunding';$rows.='<tr><td><input type="checkbox" name="order_ids[]" value="'.(int)$o['id'].'"'.($can?'':' disabled').'></td><td><a href="/admin/orders/'.e($o['order_no']).'">'.e($o['order_no']).'</a></td><td>'.e($o['email']).'</td><td>'.e($o['product_name_snapshot']).'<small class="table-sub">'.e(order_plan_summary($o)).'</small></td><td>'.money($o['total_amount_cents']).'</td><td><span class="status">'.status_text($o['status']).'</span></td></tr>';}$filters='<form class="filter admin-order-filter" method="get"><input name="q" placeholder="订单号 / 套餐 / 用户邮箱" value="'.e($q).'" ><select name="status"><option value="">全部状态</option><option value="pending_payment"'.($status==='pending_payment'?' selected':'').'>待付款确认</option><option value="pending_delivery"'.($status==='pending_delivery'?' selected':'').'>待发货</option><option value="delivered"'.($status==='delivered'?' selected':'').'>已发货</option><option value="refunding"'.($status==='refunding'?' selected':'').'>退款中</option><option value="refunded"'.($status==='refunded'?' selected':'').'>已退款</option><option value="cancelled"'.($status==='cancelled'?' selected':'').'>已取消</option></select><input type="date" name="from" value="'.e($from).'" title="开始日期"><input type="date" name="to" value="'.e($to).'" title="结束日期"><input type="number" name="min_amount" step="0.01" min="0" placeholder="最低金额" value="'.e($min).'" ><input type="number" name="max_amount" step="0.01" min="0" placeholder="最高金额" value="'.e($max).'" ><button>筛选</button></form>';$body='<div class="section-head"><div><h1>订单管理</h1><p>支持按订单、套餐、用户、状态、日期和金额筛选，并批量处理。</p></div></div>'.$filters.'<form method="post" action="/admin/orders/batch" onsubmit="return document.querySelectorAll(\"input[name=\\\"order_ids[]\\\"]:checked\").length>0"><input type="hidden" name="_csrf" value="'.e(Security::csrfToken()).'"><div class="batch-toolbar"><label><input type="checkbox" id="check-all"> 全选</label><select name="action" required><option value="">批量操作</option><option value="confirm_payment">确认付款</option><option value="cancel">取消订单</option><option value="refund_approve">同意退款</option><option value="refund_reject">拒绝退款</option></select><input name="reason" placeholder="取消/退款处理说明（批量时必填）" maxlength="500"><button class="button" type="submit">执行</button></div><div class="table-wrap"><table><tr><th></th><th>订单号</th><th>用户</th><th>套餐</th><th>金额</th><th>状态</th></tr>'.$rows.'</table></div></form><script>var all=document.getElementById("check-all");if(all)all.onclick=function(){document.querySelectorAll("input[name=\"order_ids[]\"]:not(:disabled)").forEach(function(x){x.checked=all.checked;});};</script>';page('订单管理',$body,$config);});
$router->post('/admin/orders/batch', function() use($config,$pdo){$aid=Security::requireAdmin();post_csrf();$ids=isset($_POST['order_ids'])&&is_array($_POST['order_ids'])?array_values(array_unique(array_map('intval',$_POST['order_ids']))):array();$action=isset($_POST['action'])?$_POST['action']:'';$reason=isset($_POST['reason'])?$_POST['reason']:'';if(!$ids||count($ids)>100||!in_array($action,array('confirm_payment','cancel','refund_approve','refund_reject'),true)){flash('error','请选择有效订单和批量操作（最多 100 笔）。');return redirect('/admin/orders');}$service=new OrderService($pdo);$ok=0;$errors=array();foreach($ids as $id){$s=$pdo->prepare('SELECT order_no FROM orders WHERE id=?');$s->execute(array($id));$row=$s->fetch();if(!$row){$errors[]='订单 #'.$id.' 不存在';continue;}try{if($action==='confirm_payment')$service->confirmPayment($row['order_no'],$aid);elseif($action==='cancel')$service->cancel($row['order_no'],'admin',$aid,$reason);else$service->processRefund($row['order_no'],$aid,$action==='refund_approve',$reason);$ok++;}catch(Exception $e){$errors[]=$row['order_no'].'：'.$e->getMessage();}}flash('success','批量处理完成，成功 '.$ok.' 笔。'.($errors?'失败 '.count($errors).' 笔：'.implode('；',array_slice($errors,0,3)):''));redirect('/admin/orders');});
$router->get('/admin/orders/{no}', function($no) use($config,$pdo){$aid=Security::requireAdmin();$s=$pdo->prepare('SELECT o.*,u.email FROM orders o JOIN users u ON u.id=o.user_id WHERE o.order_no=?');$s->execute(array($no));$o=$s->fetch();if(!$o)return page('不存在','<div class="empty">订单不存在。</div>',$config);$isAccount=catalog_type($o,true)==='account';$service=new OrderService($pdo);$actions='';$editDelivery='';if($o['status']==='delivered'){$d=$service->getDeliveryForAdmin($no,$config);if($d){$editDelivery='<form class="delivery-edit-form" method="post" action="/admin/orders/'.$no.'/update-delivery"><h3>修改发货内容</h3>'.delivery_email_notice($isAccount,true).csrf_field().($isAccount?account_delivery_fields($d):'<label>订阅链接<input name="subscription" value="'.e(isset($d['subscription'])?$d['subscription']:'').'" required></label><label>账号信息（可选）<textarea name="account">'.e(isset($d['account'])?$d['account']:'').'</textarea></label><label>开通时间<input type="datetime-local" name="opened_at" value="'.e(isset($d['opened_at'])?str_replace(' ','T',$d['opened_at']):'').'" required></label>'.delivery_duration_presets().'<label>到期时间<input type="datetime-local" name="expires_at" value="'.e(isset($d['expires_at'])?str_replace(' ','T',$d['expires_at']):'').'" required></label><label>使用说明（可选）<textarea name="instructions">'.e(isset($d['instructions'])?$d['instructions']:'').'</textarea></label>').'<button class="button">保存修改</button></form>'.delivery_duration_script();}$editDelivery.='<form class="delivery-revert-form" method="post" action="/admin/orders/'.$no.'/revert-delivery"><h3>退回待发货</h3>'.csrf_field().'<label>退回原因<textarea name="reason" required maxlength="500" placeholder="例如：订阅配置需要重新生成"></textarea></label><button class="secondary-button">退回待发货</button></form>';}if($o['status']==='pending_payment')$actions.='<form method="post" action="/admin/orders/'.$no.'/confirm-payment">'.csrf_field().'<button class="button">确认已付款</button></form>';if(in_array($o['status'],array('pending_payment','pending_delivery'),true))$actions.='<form method="post" action="/admin/orders/'.$no.'/cancel">'.csrf_field().'<label>取消原因<textarea name="reason" required></textarea></label><button class="secondary-button">取消订单</button></form>';if($o['status']==='pending_delivery')$actions.='<form class="delivery-create-form" method="post" action="/admin/orders/'.$no.'/deliver"><h3>人工发货</h3>'.delivery_email_notice($isAccount,false).csrf_field().($isAccount?account_delivery_fields():'<label>订阅链接<input name="subscription" required></label><label>账号信息（可选）<textarea name="account"></textarea></label><label>开通时间<input type="datetime-local" name="opened_at" value="'.e(date('Y-m-d\\TH:i')).'" required></label>'.delivery_duration_presets().'<label>到期时间<input type="datetime-local" name="expires_at" required></label><label>使用说明（可选）<textarea name="instructions"></textarea></label>').'<button class="button">保存并发货</button></form>'.delivery_duration_script();if($o['status']==='refunding')$actions.='<form method="post" action="/admin/orders/'.$no.'/refund"><h3>处理退款申请</h3>'.csrf_field().'<label>处理说明<textarea name="reason" required></textarea></label><button class="button" name="decision" value="approve">同意退款</button> <button class="secondary-button" name="decision" value="reject">拒绝退款</button></form>';page('订单处理','<div class="detail"><span class="eyebrow">'.e($o['order_no']).'</span><h1>'.e($o['product_name_snapshot']).'</h1><p>用户：'.e($o['email']).'</p><p>购买方案：'.e(order_plan_summary($o)).'</p><p>金额：'.money($o['total_amount_cents']).'</p><p>状态：'.status_text($o['status']).'</p><hr>'.$actions.$editDelivery.order_timeline($service->events($o['id']),$o).'</div>',$config);});
$router->post('/admin/orders/{no}/cancel', function($no) use($config,$pdo){$aid=Security::requireAdmin();post_csrf();try{(new OrderService($pdo))->cancel($no,'admin',$aid,isset($_POST['reason'])?$_POST['reason']:'');flash('success','订单已取消。');}catch(Exception $e){flash('error',$e->getMessage());}redirect('/admin/orders/'.$no);});
$router->post('/admin/orders/{no}/refund', function($no) use($config,$pdo){$aid=Security::requireAdmin();post_csrf();try{(new OrderService($pdo))->processRefund($no,$aid,isset($_POST['decision'])&&$_POST['decision']==='approve',isset($_POST['reason'])?$_POST['reason']:'');flash('success','退款处理已保存。');}catch(Exception $e){flash('error',$e->getMessage());}redirect('/admin/orders/'.$no);});
$router->post('/admin/orders/{no}/confirm-payment', function($no) use($config,$pdo){$aid=Security::requireAdmin();post_csrf();try{(new OrderService($pdo))->confirmPayment($no,$aid);flash('success','已记录人工付款确认。');}catch(Exception $e){flash('error',$e->getMessage());}redirect('/admin/orders/'.$no);});
$router->post('/admin/orders/{no}/deliver', function($no) use($config,$pdo){$aid=Security::requireAdmin();post_csrf();try{(new OrderService($pdo))->deliver($no,$aid,array('subscription'=>trim(isset($_POST['subscription'])?$_POST['subscription']:''),'account'=>trim($_POST['account']),'opened_at'=>trim($_POST['opened_at']),'expires_at'=>trim($_POST['expires_at']),'instructions'=>trim(isset($_POST['instructions'])?$_POST['instructions']:''),'duration_preset'=>trim(isset($_POST['duration_preset'])?$_POST['duration_preset']:'')),$config);flash('success','订单已发货，交付内容已加密保存。');}catch(Exception $e){flash('error',$e->getMessage());}redirect('/admin/orders/'.$no);});
$router->post('/admin/orders/{no}/update-delivery', function($no) use($config,$pdo){$aid=Security::requireAdmin();post_csrf();try{(new OrderService($pdo))->updateDelivery($no,$aid,array('subscription'=>trim(isset($_POST['subscription'])?$_POST['subscription']:''),'account'=>trim($_POST['account']),'opened_at'=>trim($_POST['opened_at']),'expires_at'=>trim($_POST['expires_at']),'instructions'=>trim(isset($_POST['instructions'])?$_POST['instructions']:''),'duration_preset'=>trim(isset($_POST['duration_preset'])?$_POST['duration_preset']:'')),$config);flash('success','发货内容已更新，并保留原交付版本。');}catch(Exception $e){flash('error',$e->getMessage());}redirect('/admin/orders/'.$no);});
$router->post('/admin/orders/{no}/revert-delivery', function($no) use($config,$pdo){$aid=Security::requireAdmin();post_csrf();try{(new OrderService($pdo))->revertToPendingDelivery($no,$aid,isset($_POST['reason'])?$_POST['reason']:'');flash('success','订单已退回待发货，可重新填写交付内容。');}catch(Exception $e){flash('error',$e->getMessage());}redirect('/admin/orders/'.$no);});
function delivery_email_notice($isAccount,$isUpdate=false){ return '<p class="delivery-email-note">'.($isAccount?'账号商品：邮件只通知用户登录订单查看，不发送此表单中的账号密码或自由文字。':'VPN 商品：启用发货通知后，正式订阅地址、使用说明、开通及到期时间可能随邮件发送。使用说明请勿填写不应通过邮件传递的密码或其他秘密。').($isUpdate?' 修改交付不会新增邮件，旧版本未发送任务将失效。':' 请核对交付内容后再保存。').' 已发送或 SMTP 在途邮件无法撤回，最新交付以订单详情为准。</p>'; }
function delivery_duration_presets($selected=''){ $items=array('1m'=>'一月','3m'=>'三个月','6m'=>'半年','1y'=>'一年');$html='<div class="delivery-duration-presets"><span>快捷时长</span><div>';foreach($items as $value=>$label)$html.='<button type="button" class="delivery-duration-button'.($selected===$value?' is-active':'').'" data-duration-preset="'.e($value).'">'.e($label).'</button>';return $html.'</div><small>选择后将按开通时间自动计算到期时间；最终以服务器时间为准。</small></div><input type="hidden" name="duration_preset" value="'.e($selected).'">'; }
function delivery_duration_script(){ return '<script>(function(){document.querySelectorAll(".delivery-edit-form,.delivery-create-form").forEach(function(form){var opened=form.querySelector("[name=opened_at]"),expires=form.querySelector("[name=expires_at]"),preset=form.querySelector("[name=duration_preset]"),buttons=form.querySelectorAll("[data-duration-preset]");if(!opened||!expires||!preset)return;function calc(){if(!preset.value||!opened.value)return;var d=new Date(opened.value);if(isNaN(d.getTime()))return;var months={"1m":1,"3m":3,"6m":6,"1y":12}[preset.value];var day=d.getDate();d.setDate(1);d.setMonth(d.getMonth()+months);d.setDate(Math.min(day,new Date(d.getFullYear(),d.getMonth()+1,0).getDate()));var pad=function(n){return String(n).padStart(2,"0")};expires.value=d.getFullYear()+"-"+pad(d.getMonth()+1)+"-"+pad(d.getDate())+"T"+pad(d.getHours())+":"+pad(d.getMinutes());}buttons.forEach(function(button){button.addEventListener("click",function(){preset.value=button.dataset.durationPreset;buttons.forEach(function(item){item.classList.toggle("is-active",item===button)});calc();});});opened.addEventListener("change",calc);});})();</script>'; }
function product_plans_input($type='vpn'){
    $input=isset($_POST['plans'])&&is_array($_POST['plans'])?$_POST['plans']:array();if(!$input)throw new InvalidArgumentException('请至少添加一个时长价格方案。');$plans=array();$names=array();
    foreach($input as $item){if(!is_array($item))throw new InvalidArgumentException('方案格式无效。');$name=trim(isset($item['name'])?$item['name']:'');$days=filter_var(isset($item['duration_days'])?$item['duration_days']:'',FILTER_VALIDATE_INT);$priceText=trim(isset($item['price'])?$item['price']:'');$sort=filter_var(isset($item['sort_order'])?$item['sort_order']:'0',FILTER_VALIDATE_INT);$status=isset($item['status'])?$item['status']:'inactive';if($name===''||strlen($name)>100||($type==='vpn'&&($days===false||$days<1||$days>36500))||!preg_match('/^\\d{1,8}(?:\\.\\d{1,2})?$/',$priceText)||$sort===false||$sort<0||!in_array($status,array('active','inactive'),true))throw new InvalidArgumentException('请检查方案名称、有效天数和价格。');$parts=explode('.',$priceText);$cents=((int)$parts[0])*100+(int)str_pad(isset($parts[1])?$parts[1]:'',2,'0');if($cents<1)throw new InvalidArgumentException('方案价格必须大于 0。');$key=function_exists('mb_strtolower')?mb_strtolower($name,'UTF-8'):strtolower($name);if(isset($names[$key]))throw new InvalidArgumentException('同一套餐内的方案名称不能重复。');$names[$key]=true;$plan=array('id'=>isset($item['id'])?(int)$item['id']:0,'name'=>$name,'duration_days'=>$type==='account'?null:$days,'price_cents'=>$cents,'status'=>$status,'sort_order'=>$sort);
        if($type==='vpn'&&array_key_exists('cost',$item)){
            if(!is_string($item['cost']))throw new InvalidArgumentException('成本必须是金额字符串。');
            $costText=trim($item['cost']);
            if($costText==='')$plan['cost_cents']=null;
            else{
                if(!preg_match('/^[0-9]{1,8}(?:\.[0-9]{1,2})?$/D',$costText))throw new InvalidArgumentException('成本最多为 8 位整数、2 位小数，且不能为负数。');
                $costParts=explode('.',$costText);
                $plan['cost_cents']=((int)$costParts[0])*100+(int)str_pad(isset($costParts[1])?$costParts[1]:'',2,'0');
            }
        }
        $plans[]=$plan;
    }
    return $plans;
}
function save_product_plans($pdo,$productId,$plans,$now){
    if(!$pdo->inTransaction())throw new LogicException('保存方案必须在调用方事务内执行。');
    $s=$pdo->prepare('SELECT product_type FROM products WHERE id=? FOR UPDATE');$s->execute(array($productId));$productType=$s->fetchColumn();
    if($productType===false)throw new InvalidArgumentException('套餐不存在。');
    $seen=array();$existing=array();$s=$pdo->prepare('SELECT id FROM product_plans WHERE product_id=? FOR UPDATE');$s->execute(array($productId));foreach($s->fetchAll() as $row)$existing[(int)$row['id']]=true;
    $submitted=array();
    foreach($plans as $plan){
        if($plan['id']>0){
            if(!isset($existing[$plan['id']]))throw new InvalidArgumentException('提交的方案不属于当前套餐。');
            if(isset($submitted[$plan['id']]))throw new InvalidArgumentException('不能重复提交同一个方案。');
            $submitted[$plan['id']]=true;
        }
    }
    if($existing){$temporaryPrefix='__plan_edit_'.str_replace('.','',microtime(true)).'_';$s=$pdo->prepare('UPDATE product_plans SET name=CONCAT(?,id) WHERE product_id=?');$s->execute(array($temporaryPrefix,$productId));}
    foreach($plans as $plan){
        if($plan['id']>0){
            $s=$pdo->prepare('UPDATE product_plans SET name=?,duration_days=?,price_cents=?,status=?,sort_order=?,updated_at=? WHERE id=? AND product_id=?');$s->execute(array($plan['name'],$plan['duration_days'],$plan['price_cents'],$plan['status'],$plan['sort_order'],$now,$plan['id'],$productId));$planId=(int)$plan['id'];
        }else{
            $pdo->prepare('INSERT INTO product_plans(product_id,name,duration_days,price_cents,status,sort_order,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)')->execute(array($productId,$plan['name'],$plan['duration_days'],$plan['price_cents'],$plan['status'],$plan['sort_order'],$now,$now));$planId=(int)$pdo->lastInsertId();
        }
        $seen[]=$planId;
        if($productType==='vpn'&&array_key_exists('cost_cents',$plan)){
            if($plan['cost_cents']===null)$pdo->prepare('DELETE FROM product_plan_costs WHERE product_plan_id=?')->execute(array($planId));
            else $pdo->prepare('INSERT INTO product_plan_costs(product_plan_id,cost_cents,updated_at) VALUES(?,?,?) ON DUPLICATE KEY UPDATE cost_cents=VALUES(cost_cents),updated_at=VALUES(updated_at)')->execute(array($planId,$plan['cost_cents'],$now));
        }
    }
    $s=$pdo->prepare('SELECT id FROM product_plans WHERE product_id=?');$s->execute(array($productId));foreach($s->fetchAll() as $row){if(!in_array((int)$row['id'],$seen,true)){$c=$pdo->prepare('SELECT COUNT(*) FROM orders WHERE product_plan_id=?');$c->execute(array($row['id']));if((int)$c->fetchColumn())$pdo->prepare('UPDATE product_plans SET status="inactive",updated_at=? WHERE id=?')->execute(array($now,$row['id']));else{$pdo->prepare('DELETE FROM product_plan_costs WHERE product_plan_id=?')->execute(array($row['id']));$pdo->prepare('DELETE FROM product_plans WHERE id=? AND product_id=?')->execute(array($row['id'],$productId));}}}
    $first=$pdo->prepare('SELECT duration_days,price_cents FROM product_plans WHERE product_id=? ORDER BY status="active" DESC,sort_order,id LIMIT 1');$first->execute(array($productId));$mirror=$first->fetch();if($mirror)$pdo->prepare('UPDATE products SET duration_days=?,price_cents=? WHERE id=?')->execute(array($mirror['duration_days'],$mirror['price_cents'],$productId));
}
function product_input(){
    $type=isset($_POST['product_type'])?$_POST['product_type']:'vpn';
    if(!in_array($type,array('vpn','account'),true))throw new InvalidArgumentException('未知商品类型。');
    if($type==='account'){
        global $pdo;if(!account_catalog_ready($pdo))throw new InvalidArgumentException('海外账号商城尚未启用，请先完成结构升级。');
        $p=array('product_type'=>'account','traffic'=>null,'region'=>null,'device_limit'=>null);
        foreach(array('name'=>160,'description'=>5000,'account_specification'=>10000,'account_after_sales'=>10000) as $key=>$max){$p[$key]=trim(isset($_POST[$key])?$_POST[$key]:'');if($p[$key]===''||strlen($p[$key])>$max)throw new InvalidArgumentException('请完整填写账号商品公开信息，不要填写真实凭据。');}
        $p['account_platform']=isset($_POST['account_platform'])?$_POST['account_platform']:'';
        (new AccountPlatformService($pdo))->requireCode($p['account_platform']);
        $p['sort_order']=filter_var(isset($_POST['sort_order'])?$_POST['sort_order']:0,FILTER_VALIDATE_INT);$p['status']=isset($_POST['status'])?$_POST['status']:'draft';
        if($p['sort_order']===false||$p['sort_order']<0||!in_array($p['status'],array('draft','active','inactive'),true))throw new InvalidArgumentException('状态或排序无效。');return $p;
    }
    $name=trim(isset($_POST['name'])?$_POST['name']:''); $description=trim(isset($_POST['description'])?$_POST['description']:''); $traffic=trim(isset($_POST['traffic'])?$_POST['traffic']:''); $region=trim(isset($_POST['region'])?$_POST['region']:''); $devices=filter_var(isset($_POST['device_limit'])?$_POST['device_limit']:'',FILTER_VALIDATE_INT); $sort=filter_var(isset($_POST['sort_order'])?$_POST['sort_order']:'0',FILTER_VALIDATE_INT); $status=isset($_POST['status'])?$_POST['status']:'draft';
    if($name===''||strlen($name)>160||$description===''||strlen($description)>5000||$traffic===''||strlen($traffic)>100||$region===''||strlen($region)>100||$devices===false||$devices<1||$devices>999||$sort===false||$sort<0||!in_array($status,array('draft','active','inactive'),true)) throw new InvalidArgumentException('请完整填写套餐信息，并检查设备数和排序范围。');
    $p = array('product_type'=>'vpn','account_platform'=>null,'account_specification'=>null,'account_after_sales'=>null,'name'=>$name,'description'=>$description,'traffic'=>$traffic,'region'=>$region,'device_limit'=>$devices,'sort_order'=>$sort,'status'=>$status);
    if (array_key_exists('vpn_category_id', $_POST)) $p['vpn_category_id'] = VpnCategoryService::parseId($_POST['vpn_category_id']);
    return $p;
}
$router->get('/admin/products/create', function() use($config){Security::requireAdmin();page('新建套餐',product_form(null,'/admin/products/create','新建套餐'),$config);});
$router->post('/admin/products/create', function() use($config,$pdo){$aid=Security::requireAdmin();post_csrf();try{$p=product_input();$plans=product_plans_input($p['product_type']);if($p['status']==='active'&&!count(array_filter($plans,function($x){return $x['status']==='active';})))throw new InvalidArgumentException('上架套餐至少需要一个启用的时长方案。');$pdo->beginTransaction();if($p['product_type']==='vpn'&&isset($p['vpn_category_id']))(new VpnCategoryService($pdo))->requireId($p['vpn_category_id'],true);if($p['product_type']==='account')(new AccountPlatformService($pdo))->requireCode($p['account_platform'],true);$now=date('Y-m-d H:i:s');$first=$plans[0];$pdo->prepare('INSERT INTO products(name,description,traffic,region,device_limit,duration_days,price_cents,status,sort_order,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute(array($p['name'],$p['description'],$p['traffic'],$p['region'],$p['device_limit'],$first['duration_days'],$first['price_cents'],$p['status'],$p['sort_order'],$aid,$aid,$now,$now));$id=$pdo->lastInsertId();if($p['product_type']==='vpn'&&array_key_exists('vpn_category_id',$p))$pdo->prepare('UPDATE products SET vpn_category_id=? WHERE id=?')->execute(array($p['vpn_category_id'],$id));if(account_catalog_ready($pdo))$pdo->prepare('UPDATE products SET product_type=?,account_platform=?,account_specification=?,account_after_sales=? WHERE id=?')->execute(array($p['product_type'],$p['account_platform'],$p['account_specification'],$p['account_after_sales'],$id));save_product_plans($pdo,$id,$plans,$now);$pdo->commit();flash('success','套餐及期限价格方案已创建。');redirect('/admin/products');}catch(Exception $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());redirect('/admin/products/create');}});
$router->get('/admin/products/{id}/edit', function($id) use($config,$pdo){Security::requireAdmin();$s=$pdo->prepare('SELECT * FROM products WHERE id=?');$s->execute(array((int)$id));$p=$s->fetch();if(!$p)return page('套餐不存在','<div class="empty">套餐不存在。</div>',$config);$s=$pdo->prepare(catalog_type($p)==='vpn'?'SELECT pp.*,pc.cost_cents FROM product_plans pp LEFT JOIN product_plan_costs pc ON pc.product_plan_id=pp.id WHERE pp.product_id=? ORDER BY pp.sort_order,pp.id':'SELECT * FROM product_plans WHERE product_id=? ORDER BY sort_order,id');$s->execute(array((int)$id));$p['plans']=$s->fetchAll();page('编辑套餐',product_form($p,'/admin/products/'.$p['id'].'/edit','编辑套餐'),$config);});
$router->post('/admin/products/{id}/edit', function($id) use($config,$pdo){$aid=Security::requireAdmin();post_csrf();try{$p=product_input();$plans=product_plans_input($p['product_type']);if($p['status']==='active'&&!count(array_filter($plans,function($x){return $x['status']==='active';})))throw new InvalidArgumentException('上架套餐至少需要一个启用的时长方案。');$pdo->beginTransaction();if($p['product_type']==='vpn'&&isset($p['vpn_category_id']))(new VpnCategoryService($pdo))->requireId($p['vpn_category_id'],true);$lock=$pdo->prepare('SELECT * FROM products WHERE id=? FOR UPDATE');$lock->execute(array((int)$id));$existing=$lock->fetch();if(!$existing)throw new InvalidArgumentException('套餐不存在。');if(catalog_type($existing)!==$p['product_type'])throw new InvalidArgumentException('商品类型创建后不可更改，请新建商品。');$now=date('Y-m-d H:i:s');if($p['product_type']==='account')(new AccountPlatformService($pdo))->requireCode($p['account_platform'],true);$s=$pdo->prepare('UPDATE products SET name=?,description=?,traffic=?,region=?,device_limit=?,status=?,sort_order=?,updated_by=?,updated_at=? WHERE id=?');$s->execute(array($p['name'],$p['description'],$p['traffic'],$p['region'],$p['device_limit'],$p['status'],$p['sort_order'],$aid,$now,(int)$id));if(account_catalog_ready($pdo))$pdo->prepare('UPDATE products SET account_platform=?,account_specification=?,account_after_sales=? WHERE id=?')->execute(array($p['account_platform'],$p['account_specification'],$p['account_after_sales'],(int)$id));if(catalog_type($existing)==='vpn'&&array_key_exists('vpn_category_id',$p))$pdo->prepare('UPDATE products SET vpn_category_id=? WHERE id=?')->execute(array($p['vpn_category_id'],(int)$id));save_product_plans($pdo,(int)$id,$plans,$now);$pdo->commit();flash('success','套餐和期限方案已更新，历史订单快照不受影响。');redirect('/admin/products');}catch(Exception $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());redirect('/admin/products/'.$id.'/edit');}});
$router->post('/admin/products/{id}/toggle', function($id) use($config,$pdo){$aid=Security::requireAdmin();post_csrf();$s=$pdo->prepare('SELECT * FROM products WHERE id=?');$s->execute(array((int)$id));$p=$s->fetch();if(!$p){flash('error','套餐不存在。');return redirect('/admin/products');}$next=$p['status']==='active'?'inactive':'active';try{validate_catalog_publication($pdo,$p,$next);}catch(Exception $e){flash('error',$e->getMessage());return redirect('/admin/products');}$pdo->prepare('UPDATE products SET status=?,updated_by=?,updated_at=? WHERE id=?')->execute(array($next,$aid,date('Y-m-d H:i:s'),(int)$id));flash('success',$next==='active'?'套餐已上架。':'套餐已下架，历史订单不受影响。');redirect('/admin/products');});
$router->post('/admin/products/{id}/delete', function($id) use($config,$pdo){
    Security::requireAdmin();post_csrf();
    try{
        $pdo->beginTransaction();
        $s=$pdo->prepare('SELECT id FROM products WHERE id=? FOR UPDATE');$s->execute(array((int)$id));
        if($s->fetchColumn()===false)throw new InvalidArgumentException('套餐不存在。');
        $s=$pdo->prepare('SELECT COUNT(*) FROM orders WHERE product_id=?');$s->execute(array((int)$id));
        if((int)$s->fetchColumn()>0)throw new InvalidArgumentException('该套餐已有订单使用，不能删除；如需停止销售请下架。');
        $pdo->prepare('DELETE pc FROM product_plan_costs pc INNER JOIN product_plans pp ON pp.id=pc.product_plan_id WHERE pp.product_id=?')->execute(array((int)$id));
        $pdo->prepare('DELETE FROM product_plans WHERE product_id=?')->execute(array((int)$id));
        $pdo->prepare('DELETE FROM products WHERE id=?')->execute(array((int)$id));
        $pdo->commit();flash('success','套餐已删除。');
    }catch(Exception $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());}
    redirect('/admin/products');
});
function article_input(){
    $title=trim(isset($_POST['title'])?$_POST['title']:'');$category=trim(isset($_POST['category'])?$_POST['category']:'使用教程');$summary=trim(isset($_POST['summary'])?$_POST['summary']:'');$content=trim(isset($_POST['content'])?$_POST['content']:'');$cover=isset($_POST['cover_style'])?$_POST['cover_style']:'blue';$status=isset($_POST['status'])?$_POST['status']:'draft';
    if($title===''||strlen($title)>200||!in_array($category,array('公益节点','使用教程','常见问题','活动公告'),true)||strlen($summary)>500||$content===''||strlen($content)>60000||!in_array($cover,array('blue','purple','orange'),true)||!in_array($status,array('draft','published','offline'),true))throw new InvalidArgumentException('请完整填写文章内容并检查字段范围。');
    return array('title'=>$title,'category'=>$category,'summary'=>$summary,'content'=>$content,'cover_style'=>$cover,'status'=>$status);
}
function article_form($article,$action,$title){$a=$article?:array('title'=>'','category'=>'使用教程','summary'=>'','content'=>'','cover_style'=>'blue','status'=>'draft');return '<div class="product-form-card article-form-card"><div class="product-form-heading"><div><span class="admin-kicker">CONTENT / 文章管理</span><h1>'.e($title).'</h1><p>发布教程、公益节点和常见问题，首页会展示最新已发布文章。</p></div><a class="secondary-button" href="/admin/articles">返回列表</a></div><form method="post" action="'.e($action).'">'.csrf_field().'<div class="product-form-grid"><div class="product-form-section"><h2>文章内容</h2><label>文章标题 <em>*</em><input name="title" maxlength="200" value="'.e($a['title']).'" required></label><label>文章摘要<textarea name="summary" maxlength="500">'.e($a['summary']).'</textarea></label><label>正文内容 <em>*</em><textarea class="article-content-input" name="content" maxlength="60000" required>'.e($a['content']).'</textarea></label></div><div class="product-form-section"><h2>发布设置</h2><label>文章分类<select name="category"><option value="公益节点"'.($a['category']==='公益节点'?' selected':'').'>公益节点</option><option value="使用教程"'.($a['category']==='使用教程'?' selected':'').'>使用教程</option><option value="常见问题"'.($a['category']==='常见问题'?' selected':'').'>常见问题</option><option value="活动公告"'.($a['category']==='活动公告'?' selected':'').'>活动公告</option></select></label><label>封面风格<select name="cover_style"><option value="blue"'.($a['cover_style']==='blue'?' selected':'').'>天空蓝</option><option value="purple"'.($a['cover_style']==='purple'?' selected':'').'>梦幻紫</option><option value="orange"'.($a['cover_style']==='orange'?' selected':'').'>活力橙</option></select></label><label>发布状态<select name="status"><option value="draft"'.($a['status']==='draft'?' selected':'').'>草稿</option><option value="published"'.($a['status']==='published'?' selected':'').'>已发布</option><option value="offline"'.($a['status']==='offline'?' selected':'').'>已下架</option></select></label></div></div><div class="product-form-actions"><a class="secondary-button" href="/admin/articles">取消</a><button class="button" type="submit">保存文章</button></div></form></div>';}
function downloads_editorial($software, $config, $mirror) {
    $platforms = array('Windows'=>array('windows','DESKTOP','Windows 电脑'),'macOS'=>array('apple','DESKTOP','Mac 电脑'),'Android'=>array('android','MOBILE','Android 手机与平板'),'Linux'=>array('linux','DESKTOP','Linux 设备'));
    $filters = '<button type="button" class="download-filter is-active" data-platform="全部" aria-pressed="true">全部平台</button>';
    $cards = '';
    foreach ($platforms as $platform=>$info) {
        $filters .= '<button type="button" class="download-filter" data-platform="'.e($platform).'" aria-pressed="false">'.e($platform).'</button>';
        $resources = ''; $options = ''; $count = 0;
        foreach ($software as $app) {
            if (empty($app['platforms'][$platform])) continue;
            foreach ($app['platforms'][$platform] as $resource) {
                $url = is_array($resource) ? $resource[1] : $resource;
                $filename = is_array($resource) ? $resource[0] : '';
                $version = isset($resource[2]) ? $resource[2] : $app['version'];
                $description = isset($resource[3]) ? $resource[3] : $app['description'];
                $project = isset($resource[4]) ? $resource[4] : $app['project'];
                $id = 'dl-'.strtolower($platform).'-'.(++$count);
                $options .= '<option value="'.$id.'">'.e($app['name'].' · '.$version.' · '.($filename ?: '资源暂未配置')).'</option>';
                $resources .= '<div class="download-resource" id="'.$id.'" data-platform="'.e($platform).'" data-search="'.e($app['name'].' '.$version.' '.$filename.' '.$description).'"'.($url ? '' : ' data-unavailable="1"').'><h3>'.e($app['name']).' <small>'.e($version).'</small></h3><p>'.e($description).'</p><p class="dl-filename">'.($filename ? e($filename) : '资源暂未配置，请访问项目页').'</p><div class="dl-resource-links">'.($url ? '<a class="download-button" href="'.e($mirror.$url).'" target="_blank" rel="noopener noreferrer">镜像下载 ↗</a>' : '<span class="download-disabled">暂未配置</span>').'<a class="download-project" href="'.e($project).'" target="_blank" rel="noopener noreferrer">项目主页 ↗</a></div></div>';
            }
        }
        $cards .= '<article class="dl-card" data-platform="'.e($platform).'"><header>'.home_outline_icon($info[0]).'<div><span class="ep-kicker">'.$info[1].'</span><h2>'.e($platform).'客户端</h2><p>适用于'.e($info[2]).'。</p></div></header><dl><div><dt>版本信息</dt><dd>以下载资源说明为准</dd></div><div><dt>系统架构</dt><dd>'.($count ? '查看可用版本与文件名' : '暂无可用资源').'</dd></div></dl>'.($count ? '<label class="dl-select-label" for="dl-select-'.strtolower($platform).'">选择安装版本 / 系统架构</label><select id="dl-select-'.strtolower($platform).'" class="dl-version"><option value="">选择安装版本 / 系统架构</option>'.$options.'</select>' : '<p class="dl-unavailable">当前平台资源暂未配置。</p>').'<div class="dl-card-actions"><a class="ep-button dl-view'.($platform==='Windows' ? ' dl-primary' : '').'" href="#dl-versions-'.strtolower($platform).'">查看下载版本 ↗</a><a href="/articles?q='.rawurlencode($platform).'">安装教程 →</a></div><details class="dl-versions" id="dl-versions-'.strtolower($platform).'"><summary>可用下载资源 <span>'.$count.' 项</span></summary><div>'.$resources.(!$count ? '<p>暂无下载资源，请稍后再来。</p>' : '').'</div></details></article>';
    }
    $body = '<div class="ep-page dl-page"><nav class="ep-crumb" aria-label="面包屑"><a href="/">首页</a><span>/</span><span>软件下载</span></nav><header class="ep-intro"><div><span class="ep-kicker">DOWNLOADS &amp; GUIDES</span><h1><span>在熟悉的设备上，</span><span>轻松开始。</span></h1><p>选择适合你设备的客户端，按教程完成安装与订阅配置。</p></div><a href="/articles">查看使用教程 ↗</a></header><div class="ep-toolbar dl-toolbar"><nav class="dl-filters" aria-label="选择设备平台">'.$filters.'</nav><form class="dl-search" role="search">'.home_outline_icon('search').'<input type="search" maxlength="100" aria-label="搜索客户端" placeholder="搜索客户端…"><button type="submit" aria-label="搜索">→</button></form></div><p class="dl-note" role="status">下载前请确认设备平台与系统架构。</p><div class="dl-grid">'.$cards.'</div><div class="download-no-match" hidden role="status">没有匹配的客户端资源，请更换关键词或平台。</div><section class="dl-steps"><h2><span>三步配置，</span><br><span>开始使用。</span></h2><div><ol><li><span>01</span><div><h3>下载并安装</h3><p>选择与你设备匹配的客户端版本。</p></div></li><li><span>02</span><div><h3>获取订阅信息</h3><p>登录后在订单详情查看已交付的订阅链接。</p></div></li><li><span>03</span><div><h3>按教程配置</h3><p>参考对应平台教程，完成订阅导入。</p></div></li></ol><p>软件安装不代表订阅已开通，订阅有效期以正式交付信息为准。</p></div></section><section class="dl-guides"><div><h2>下载与安装须知</h2><ul><li>请通过本页提供的资源入口下载。</li><li>安装前查看客户端说明与平台要求。</li><li>镜像下载使用第三方代理，失败时可查看项目主页或提交工单。</li></ul></div><div><h2>常用教程</h2><a href="/articles?q='.rawurlencode('订阅链接').'">如何查看正式交付的订阅链接 <span>↗</span></a><a href="/articles?q='.rawurlencode('订阅').'">如何导入订阅配置 <span>↗</span></a><a href="/articles?q='.rawurlencode('安装').'">客户端安装遇到问题怎么办 <span>↗</span></a></div></section><section class="ep-faq"><h2>你可能想了解</h2><div><details open><summary>下载完成后可以直接使用吗？</summary><p>下载并安装客户端后，还需按教程导入有效订阅信息。请妥善保管订阅地址与账号凭据。</p></details><details><summary>如何选择设备对应的系统架构？</summary><p>先确认设备操作系统，再在系统信息中查看处理器架构。请核对安装文件名中的 amd64 / x86_64、arm64 或 armeabi-v7a 等标记，兼容要求以项目发布说明为准；不确定时请先查看项目主页。</p></details><details><summary>下载链接失效了怎么办？</summary><p>可通过对应资源的项目主页查看发布说明。若仍无法获取，请前往帮助中心或提交工单，并说明设备平台、客户端名称与安装文件名。</p></details></div></section><section class="ep-help"><h2>需要帮助？</h2><a href="/articles">前往帮助中心 ↗</a><a href="/tickets">提交工单 ↗</a></section><footer class="ep-footer"><div><a href="/">'.home_outline_icon('cloud').'<b>'.e(isset($config['app']['name']) ? $config['app']['name'] : '小云店').'</b></a><p>数字服务，简单一点。</p></div><nav><a href="/announcements">系统公告</a><a href="/articles">帮助中心</a></nav><small>© '.date('Y').' '.e(isset($config['app']['name']) ? $config['app']['name'] : '小云店').'</small></footer></div>';
    return $body.'<script>(function(){var root=document.querySelector(".dl-page"),platform="全部",input=root.querySelector(".dl-search input");root.classList.add("is-enhanced");function filter(){var q=input.value.trim().toLocaleLowerCase(),visible=0;root.querySelectorAll(".dl-card").forEach(function(card){var matched=0;card.querySelectorAll(".download-resource").forEach(function(row){var show=!q||row.dataset.search.toLocaleLowerCase().includes(q);row.hidden=!show;if(show)matched++});card.hidden=(platform!=="全部"&&card.dataset.platform!==platform)||(q!==""&&matched===0);if(!card.hidden)visible++;card.querySelector(".dl-versions").open=q!==""&&!card.hidden;card.querySelectorAll(".dl-version option[value]:not([value=\"\"])").forEach(function(option){var row=document.getElementById(option.value);option.hidden=row.hidden;option.disabled=row.hidden});var select=card.querySelector(".dl-version");if(select&&select.value&&document.getElementById(select.value).hidden)select.value=""});root.querySelector(".download-no-match").hidden=visible!==0}root.querySelectorAll(".download-filter").forEach(function(button){button.addEventListener("click",function(){platform=button.dataset.platform;root.querySelectorAll(".download-filter").forEach(function(item){var active=item===button;item.classList.toggle("is-active",active);item.setAttribute("aria-pressed",String(active))});filter()})});root.querySelector(".dl-search").addEventListener("submit",function(event){event.preventDefault();filter()});input.addEventListener("input",filter);root.querySelectorAll(".dl-view").forEach(function(link){link.addEventListener("click",function(){var card=link.closest(".dl-card"),select=card.querySelector(".dl-version"),details=card.querySelector(".dl-versions");details.open=true;link.href="#"+(select&&select.value?select.value:details.id)})})})();</script>';
}
$router->get('/downloads', function() use($config,$pdo){$mirror='https://gh-proxy.com/';$software=array();$rows=$pdo->query('SELECT * FROM download_resources WHERE status="active" ORDER BY app_name,sort_order,id')->fetchAll();foreach($rows as $row){if(!isset($software[$row['app_name']]))$software[$row['app_name']]=array('name'=>$row['app_name'],'version'=>$row['version'],'description'=>$row['description'],'project'=>$row['project_url'],'platforms'=>array());$software[$row['app_name']]['platforms'][$row['platform']][]=array($row['filename'],$row['source_url'],$row['version'],$row['description'],$row['project_url']);}$software=array_values($software);/* legacy fallback data follows */if(!$software)$software=array(array('name'=>'FlClash','version'=>'v0.8.98','description'=>'跨平台的 Clash 代理客户端，界面简洁，适合日常使用。','project'=>'https://github.com/chen08209/FlClash','platforms'=>array('Windows'=>array(array('FlClash-0.8.98-windows-amd64-setup.exe','https://github.com/chen08209/FlClash/releases/download/v0.8.98/FlClash-0.8.98-windows-amd64-setup.exe')),'macOS'=>array(array('FlClash-0.8.98-macos-amd64.dmg','https://github.com/chen08209/FlClash/releases/download/v0.8.98/FlClash-0.8.98-macos-amd64.dmg')),'Linux'=>array(array('FlClash-0.8.98-linux-amd64.AppImage','https://github.com/chen08209/FlClash/releases/download/v0.8.98/FlClash-0.8.98-linux-amd64.AppImage')),'Android'=>array(array('FlClash-0.8.98-android-arm64-v8a.apk','https://github.com/chen08209/FlClash/releases/download/v0.8.98/FlClash-0.8.98-android-arm64-v8a.apk'),array('FlClash-0.8.98-android-armeabi-v7a.apk','https://github.com/chen08209/FlClash/releases/download/v0.8.98/FlClash-0.8.98-android-armeabi-v7a.apk'),array('FlClash-0.8.98-android-x86_64.apk','https://github.com/chen08209/FlClash/releases/download/v0.8.98/FlClash-0.8.98-android-x86_64.apk')))),array('name'=>'Clash Verge Rev','version'=>'请以项目页最新版本为准','description'=>'基于 Clash Meta 的桌面客户端，支持主流桌面系统。','project'=>'https://github.com/clash-verge-rev/clash-verge-rev','platforms'=>array('Windows'=>array(null),'macOS'=>array(null),'Linux'=>array(null),'Android'=>array(null))),array('name'=>'v2rayN','version'=>'请以项目页最新版本为准','description'=>'Windows 平台常用的代理客户端，支持多种协议配置。','project'=>'https://github.com/2dust/v2rayN','platforms'=>array('Windows'=>array(null),'macOS'=>array(null),'Linux'=>array(null),'Android'=>array(null))),array('name'=>'v2rayNG','version'=>'请以项目页最新版本为准','description'=>'Android 平台代理客户端，适合移动设备使用。','project'=>'https://github.com/2dust/v2rayNG','platforms'=>array('Windows'=>array(null),'macOS'=>array(null),'Linux'=>array(null),'Android'=>array(null))));page('软件下载',downloads_editorial($software,$config,$mirror),$config);});
$router->get('/articles', function() use($config,$pdo){page('帮助中心',help_article_list($pdo,$config),$config);});
$router->get('/articles/{id}', function($id) use($config,$pdo){list($title,$body)=help_article_detail($pdo,$id);page($title,$body,$config);});
function lottery_panel($pdo,$service,$uid){
    $setting=$service->setting('lottery');$cfg=$service->config('lottery');$enabled=$service->enabled('lottery');$prizes=array();$used=0;
    if($setting){
        $s=$pdo->prepare('SELECT name,value_cents FROM lottery_prizes WHERE activity_id=? AND status="active" AND weight>0 AND (remaining_quantity<0 OR remaining_quantity>0) ORDER BY sort_order,id');$s->execute(array($setting['id']));$prizes=$s->fetchAll();
        $s=$pdo->prepare('SELECT COUNT(*) FROM lottery_draws WHERE activity_id=? AND user_id=? AND created_at>=? AND created_at<?');$s->execute(array($setting['id'],$uid,date('Y-m-d').' 00:00:00',date('Y-m-d',strtotime('+1 day')).' 00:00:00'));$used=(int)$s->fetchColumn();
    }
    $limit=max(0,(int)$cfg['daily_limit']);$remaining=max(0,$limit-$used);
    $result=isset($_SESSION['lottery_result'])?$_SESSION['lottery_result']:null;unset($_SESSION['lottery_result']);
    $labels=array('谢谢参与');foreach($prizes as $prize)$labels[]=$prize['name'];
    $target=0;if($result&&$result['won']){$target=array_search($result['prize'],$labels,true);if($target===false){$labels[]=$result['prize'];$target=count($labels)-1;}}
    $count=count($labels);$stops=array();$segments='';$palette=array('#eeeaff','#dceafa','#fae8d2','#d9f1e9');
    foreach($labels as $i=>$label){$start=360*$i/$count;$end=360*($i+1)/$count;$stops[]=$palette[$i%count($palette)].' '.$start.'deg '.$end.'deg';$segments.='<span class="lottery-label" style="--angle:'.($start+180/$count).'deg"><b>'.e($label).'</b></span>';}
    $disabled=!$enabled||!$prizes||!$remaining;$status=!$enabled?'活动暂未开启':(!$prizes?'奖池暂未配置可用奖品':(!$remaining?'今日次数已用完，明天再来':'今日还有 '.$remaining.' 次机会'));
    $message=$result?($result['won']?'恭喜获得「'.$result['prize'].'」，优惠券 '.$result['coupon'].' 已发放。':'谢谢参与，本次未中奖。'):'';
    $html='<section class="lottery-card" id="lottery-panel" data-target="'.(int)$target.'" data-count="'.$count.'" data-result="'.($result?'1':'0').'"><div class="lottery-play"><span class="eyebrow">DAILY LUCKY DRAW</span><h2>每日幸运抽奖</h2><p>转动幸运转盘，领取站内优惠券</p><div class="lottery-stage"><div class="lottery-pointer" aria-hidden="true"></div><div class="lottery-wheel" aria-hidden="true" style="background:conic-gradient('.implode(',',$stops).')">'.$segments.'</div><span class="lottery-hub" aria-hidden="true">幸运<br>转盘</span></div><form method="post" action="/events/lottery" id="lottery-form">'.csrf_field().'<button class="button lottery-start" type="submit"'.($disabled?' disabled':'').'>'.($disabled?e($status):'立即抽奖').'</button></form><p class="lottery-status">'.e($status).'</p><div class="lottery-result" role="status" aria-live="polite">'.e($message).'</div><noscript><p>关闭 JavaScript 也可正常抽奖，结果将直接显示。</p></noscript></div><aside class="lottery-rules"><h3>活动规则</h3><ol><li>每位用户每日最多参与 '.(int)$limit.' 次，剩余 '.(int)$remaining.' 次。</li><li>中奖后优惠券自动发放到当前账户，券码可在站内中奖通知中查看。</li><li>转盘扇区仅展示奖项，不代表实际中奖概率；结果以服务端判定为准。</li><li>优惠券使用门槛与有效期以实际发放的券为准。</li></ol><a class="secondary-button" href="/notifications">查看中奖通知</a><h3>当前奖池</h3><ul class="lottery-prizes">';
    foreach($prizes as $prize)$html.='<li><span>'.e($prize['name']).'</span><b>'.e(money($prize['value_cents'])).'</b></li>';
    $html.=(!$prizes?'<li>暂无可用奖品</li>':'').'</ul></aside></section>';
    $html.= <<<'HTML'
<script>
(function(){
    var panel=document.getElementById("lottery-panel"),form=document.getElementById("lottery-form"),button=form.querySelector("button"),wheel=panel.querySelector(".lottery-wheel"),result=panel.querySelector(".lottery-result"),busy=false;
    form.addEventListener("submit",function(event){if(busy){event.preventDefault();return;}busy=true;button.disabled=true;button.textContent="正在获取抽奖结果…";});
    window.addEventListener("pageshow",function(event){if(event.persisted){busy=false;button.disabled=true;button.textContent="请刷新查看剩余次数";}});
    if(panel.dataset.result!=="1")return;
    var angle=1800+360-(Number(panel.dataset.target)+0.5)*360/Number(panel.dataset.count);
    if(window.matchMedia("(prefers-reduced-motion: reduce)").matches){wheel.style.transform="rotate("+angle+"deg)";return;}
    var wasDisabled=button.disabled;button.disabled=true;result.hidden=true;panel.setAttribute("aria-busy","true");
    function finish(){result.hidden=false;button.disabled=wasDisabled;panel.removeAttribute("aria-busy");}
    wheel.addEventListener("transitionend",function(event){if(event.propertyName==="transform")finish();},{once:true});
    requestAnimationFrame(function(){requestAnimationFrame(function(){wheel.style.transform="rotate("+angle+"deg)";});});
    window.setTimeout(finish,4600);
})();
</script>
HTML;
    return $html;
}
function group_panel($pdo,$uid,$config)
{
    $service=new GroupService($pdo);$activity=new ActivityService($pdo);$cfg=$activity->config('group');$enabled=$activity->enabled('group');
    $gid=isset($_GET['group_id'])?(int)$_GET['group_id']:0;
    $labels=array('forming'=>'拼团中','successful'=>'已成团','failed'=>'拼团失败');
    $html='<div class="group-page"><header class="group-heading"><div><span class="group-kicker">好友同行 · 一起享优惠</span><h1>好友拼单</h1><p>付款才计入成团人数，成团后进入交付流程。</p></div><a class="secondary-button" href="/events?view=group">拼团大厅</a></header>';
    if($gid){
        $detail=$service->detail($gid,$uid);if(!$detail)return $html.'<div class="group-empty">拼团不存在。</div></div>';
        $g=$detail['group'];$base=rtrim(isset($config['app']['base_url'])?$config['app']['base_url']:'','/');$link=$base.'/events?view=group&group_id='.(int)$g['id'];
        $html.='<section class="group-detail-card"><span class="group-state">'.e(isset($labels[$g['status']])?$labels[$g['status']]:$g['status']).'</span><h2>'.e($g['title']).'</h2><div class="group-facts"><div><small>已付款人数</small><b>'.(int)$g['current_members'].' / '.(int)$g['target_members'].'</b></div><div><small>剩余可下单名额</small><b>'.(int)$g['remaining_seats'].'</b></div><div><small>实付比例</small><b>'.(int)$g['discount_percent_snapshot'].'%</b></div></div><p>截止时间：'.e($g['expires_at']?:'未设置').'。下单占位 '.(int)$g['reservation_minutes'].' 分钟，最迟不超过拼团截止时间；超时不可继续付款。</p>';
        if((int)$g['flow_version']!==2)$html.='<p class="group-small-note">此团属于历史流程，仅保留记录，不支持继续加入。</p>';
        if($detail['order'])$html.='<a class="button" href="/orders/'.rawurlencode($detail['order']['order_no']).'">查看我的订单 · '.e(status_text($detail['order']['status'])).'</a>';
        elseif($g['can_join']&&$detail['plans']){$html.='<form method="post" action="/events/group/join">'.csrf_field().'<input type="hidden" name="campaign_id" value="'.(int)$g['id'].'"><label>选择参与方案<select name="plan_id" required>';foreach($detail['plans'] as $p)$html.='<option value="'.(int)$p['id'].'">'.e($p['product_name'].' / '.$p['name'].' / '.$p['duration_days'].'天 / '.money($p['group_price_cents'])).'</option>';$html.='</select></label><button class="button" type="submit">加入并确认订单</button></form>';}
        else $html.='<p class="group-small-note">当前不可加入：活动关闭、团已结束、名额已满或方案不可用。已有订单请从订单管理查看。</p>';
        $html.='<div class="group-members">';$memberLabels=array('pending'=>'待下单或付款','paid'=>'已付款待成团','released'=>'已进入交付','cancelled'=>'已取消','refund_pending'=>'退款处理中','refunded'=>'已退款');foreach($detail['members'] as $m)$html.='<div class="group-member"><i aria-hidden="true">人</i><b>'.($m['role']==='leader'?'团长':'拼友').'</b><small>'.e(isset($memberLabels[$m['status']])?$memberLabels[$m['status']]:$m['status']).'</small></div>';
        $html.='</div><div class="group-share"><input id="group-share-link" readonly aria-label="拼团邀请链接" value="'.e($link).'"><button id="group-copy" class="secondary-button" type="button">复制拼团链接</button></div><small id="group-copy-status" class="group-share-status" role="status"></small></section>';
    }else{
        $list=$service->listing($uid,isset($_GET['page'])?(int)$_GET['page']:1,9);$mine=$service->mine($uid,isset($_GET['mine_page'])?(int)$_GET['mine_page']:1,10);
        $html.='<section class="group-hero"><div><span class="group-pill">'.($enabled?'活动进行中':'活动暂未开启').'</span><h2>好友一起拼<br>体验更划算</h2><p>新团 '.(int)$cfg['target_members'].' 人成团，实付比例 '.(int)$cfg['discount_percent'].'%。每个团保留创建时的人数与折扣规则，付款后的优惠以订单为准。</p></div><div class="group-hero-art" aria-hidden="true"><span class="group-avatar">你</span><span class="group-avatar">友</span><span class="group-avatar">友</span></div></section><div class="group-layout"><section><div class="group-section-head"><h2>正在拼团</h2><span>共 '.(int)$list['total'].' 个</span></div><div class="group-grid">';
        foreach($list['items'] as $g){$html.='<article class="group-card"><div class="group-card-top"><span class="group-state">拼团中</span><span class="group-number">#'.(int)$g['id'].'</span></div><h3>'.e($g['title']).'</h3><div class="group-count"><strong>'.(int)$g['current_members'].'</strong> / '.(int)$g['target_members'].' 人已付款</div><div class="group-progress"><span style="width:'.min(100,100*(int)$g['current_members']/max(1,(int)$g['target_members'])).'%"></span></div><p>实付比例 '.(int)$g['discount_percent_snapshot'].'% · 剩余 '.(int)$g['remaining_seats'].' 个下单名额</p><div class="group-card-footer"><span>截止 '.e($g['expires_at']).'</span><a href="/events?view=group&amp;group_id='.(int)$g['id'].'">查看拼团 →</a></div></article>';}
        if(!$list['items'])$html.='<div class="group-empty"><b>暂无进行中的拼团</b><p>活动开启后，可选择套餐发起新团。</p></div>';
        $html.='</div><div class="group-pagination"><span>第 '.(int)$list['page'].' / '.(int)$list['pages'].' 页</span>';if($list['page']>1)$html.='<a href="/events?view=group&amp;page='.($list['page']-1).'">上一页</a>';if($list['page']<$list['pages'])$html.='<a href="/events?view=group&amp;page='.($list['page']+1).'">下一页</a>';
        $html.='</div></section><aside><section class="group-create"><h2>发起好友拼单</h2><p>选择活动套餐，邀请好友一起参与。</p>';$ids=array_values(array_filter(array_map('intval',isset($cfg['plan_ids'])?(array)$cfg['plan_ids']:array())));$plans=array();if($ids){$s=$pdo->prepare('SELECT pp.*,p.name product_name FROM product_plans pp JOIN products p ON p.id=pp.product_id WHERE pp.id IN ('.implode(',',array_fill(0,count($ids),'?')).') AND pp.status="active" AND p.status="active"');$s->execute($ids);$plans=$s->fetchAll();}
        if($enabled&&$plans){$html.='<form method="post" action="/events/group/create">'.csrf_field().'<label>参与套餐<select name="plan_id" required>';foreach($plans as $p)$html.='<option value="'.(int)$p['id'].'">'.e($p['product_name'].' / '.$p['name'].' / '.$p['duration_days'].'天').'</option>';$html.='</select></label><button class="button" type="submit">创建拼团</button></form>';}else $html.='<p class="group-small-note">当前活动未开启或暂无可参与套餐。</p>';
        $html.='</section></aside></div><section class="group-history group-section"><h2>我的拼团</h2><div class="table-wrap"><table><thead><tr><th>拼团</th><th>人数 / 状态</th><th>操作</th></tr></thead><tbody>';foreach($mine['items'] as $g)$html.='<tr><td>'.e($g['title']).'<small>'.e($g['created_at']).'</small></td><td>'.(int)$g['current_members'].' / '.(int)$g['target_members'].' · '.e(isset($labels[$g['status']])?$labels[$g['status']]:$g['status']).'</td><td><a href="/events?view=group&amp;group_id='.(int)$g['id'].'">查看详情</a></td></tr>';if(!$mine['items'])$html.='<tr><td colspan="3">还没有参与记录。</td></tr>';$html.='</tbody></table></div><div class="group-pagination"><span>第 '.(int)$mine['page'].' / '.(int)$mine['pages'].' 页</span>';if($mine['page']>1)$html.='<a href="/events?view=group&amp;mine_page='.($mine['page']-1).'">上一页</a>';if($mine['page']<$mine['pages'])$html.='<a href="/events?view=group&amp;mine_page='.($mine['page']+1).'">下一页</a>';$html.='</div></section>';
    }
    return $html.'<section class="group-rules"><h2>拼单规则</h2><ol><li>加入不占名额，创建订单后占位15分钟；实付人数达到要求才成团。</li><li>成团前不交付订阅，不提前结算返佣。成团后由后台任务逐单进入交付流程。</li><li>未成团到期后，余额实付金额自动原路退回余额；余额容量不足时等待重试。外部付款由管理员核对原渠道退款。</li><li>拼团不可叠加优惠券。折扣比例按建团时规则，具体套餐价格及实付金额以下单时订单为准。</li><li>支付超时请勿继续付款；晚到款项需经系统记录并核对退款，进度可在订单及售后中查询。</li></ol></section></div><script>(function(){var b=document.getElementById("group-copy");if(!b)return;b.addEventListener("click",function(){var i=document.getElementById("group-share-link"),s=document.getElementById("group-copy-status");function fallback(){i.focus();i.select();s.textContent="链接已选中，请手动复制"}if(navigator.clipboard&&window.isSecureContext)navigator.clipboard.writeText(i.value).then(function(){s.textContent="拼团链接已复制"},fallback);else fallback()})})();</script>';
}
function leader_panel($service,$uid,$config)
{
    $sum=$service->leaderSummary($uid);$plan=$sum['plan'];$reward=$sum['reward'];$coupon=$sum['coupon'];$actual=$sum['reward_plan'];
    $states=array('enabled'=>'活动进行中','disabled'=>'活动暂未开启','upcoming'=>'活动尚未开始','ended'=>'活动已结束');
    $base=rtrim(isset($config['app']['base_url'])?$config['app']['base_url']:'','/');$link=$base.'/register?ref='.rawurlencode($sum['code']);
    $html='<div class="leader-page"><header class="leader-heading"><div><span class="leader-eyebrow">分享好体验 · 解锁套餐奖励</span><h1>团长免单</h1><p>邀请好友，一起体验；达标奖励与进度在这里清晰可见。</p></div><a class="secondary-button" href="/events">返回活动中心</a></header><section class="leader-hero"><div><span class="leader-pill">'.e($states[$sum['activity_state']]).'</span><h2>邀请 <strong>'.(int)$sum['required'].' 位</strong>有效好友<br>解锁指定套餐抵扣券</h2><p>好友首笔有效付款产生返佣后计入人数，同一好友只计算一次。</p></div><div class="leader-hero-reward"><span>达标奖励</span><b>'.($plan?e($plan['product_name'].' / '.$plan['name']):'奖励套餐待配置').'</b><small>'.($plan?e($plan['duration_days']).' 天套餐 · 固定面额券':'请以活动配置与实际发券记录为准').'</small></div></section>';
    if($sum['activity_state']!=='enabled')$html.='<p class="leader-notice">当前活动不发放新奖励，已有进度与奖励记录仍可查看。</p>';
    if(!$sum['plan_available'])$html.='<p class="leader-notice">'.($plan?'当前奖励套餐已下架或不可用，请联系站点客服核实。':'当前尚未配置有效奖励套餐，请留意活动更新。').' 已发奖励以实际券信息为准。</p>';
    if(!$sum['referral_ready'])$html.='<p class="leader-notice">当前返佣未有效开启或比例为零，新邀请可能不累计达标人数；已有关联好友不等于有效达标好友。</p>';
    $html.='<div class="leader-layout"><section class="leader-card"><div class="leader-section-head"><h2>我的达标进度</h2><span>'.($sum['remaining']===0?'人数已达标':'继续邀请好友').'</span></div><div class="leader-count"><strong>'.(int)$sum['qualified'].'</strong><span>/ '.(int)$sum['required'].' 人</span></div><div class="leader-progress" role="progressbar" aria-label="有效好友达标进度" aria-valuemin="0" aria-valuemax="'.(int)$sum['required'].'" aria-valuenow="'.min($sum['qualified'],$sum['required']).'" aria-valuetext="有效好友 '.(int)$sum['qualified'].' 人，要求 '.(int)$sum['required'].' 人"><span style="width:'.(float)$sum['progress'].'%"></span></div><p>'.($sum['remaining']>0?'还差 <b>'.(int)$sum['remaining'].' 位</b>有效好友达到人数要求。':'已达到当前人数要求。').'</p><div class="leader-associated"><span>已关联好友 <b>'.(int)$sum['invited'].' 人</b></span><small>仅表示邀请关系，不代表已完成有效付款。</small></div></section><section class="leader-card leader-reward"><div class="leader-section-head"><h2>我的奖励</h2><span>'.($reward?'已查到发奖记录':'尚无发奖记录').'</span></div>';
    if($reward){
        $labels=array('available'=>'抵扣券可用','used'=>'已被订单占用','expired'=>'抵扣券已过期','inactive'=>'抵扣券暂不可用','missing'=>'奖励券记录待核实');
        $html.='<div class="leader-ticket"><span>'.e($labels[$sum['coupon_state']]).'</span><strong>'.e(money($coupon?$coupon['amount_cents']:$reward['reward_cents'])).'<small> 固定抵扣额度</small></strong><b>'.($actual?e($actual['product_name'].' / '.$actual['name']):'适用套餐信息待核实').'</b>'.($actual?'<small>'.(int)$actual['duration_days'].' 天套餐</small>':'').'</div>';
        if($coupon){$html.='<p class="leader-meta">有效期至：'.e($coupon['expires_at']?:'未设置截止时间').'</p>';if($sum['coupon_state']==='available'){$html.='<div class="leader-copy-row"><input id="leader-coupon" readonly aria-label="奖励券码" value="'.e($coupon['code']).'"><button class="secondary-button" type="button" data-leader-copy="leader-coupon">复制券码</button></div><p>下单时填写券码，仅适用于券指定套餐；套餐涨价时可能需要补差。</p>';}elseif($sum['coupon_state']==='used'){$html.='<p>券已被订单占用，不表示该订单已付款。</p>';if($sum['coupon_order_no'])$html.='<a class="secondary-button" href="/orders/'.rawurlencode($sum['coupon_order_no']).'">查看占用订单</a>';}else $html.='<p>当前不能使用该券；如有疑问，请联系站点客服核实。</p>';}
        else $html.='<p>已存在发奖记录，但暂未查到属于你的对应券，请联系站点客服核实。</p>';
    }else $html.='<div class="leader-empty"><b>'.($sum['remaining']===0?'已达到人数要求，暂未查到奖励记录':'奖励等待你解锁').'</b><p>'.($sum['remaining']===0?'请核对活动状态，查看站内通知或联系站点客服查询。':'达标后由现有付款结算流程自动发券，无需手动领取。').'</p>'.($sum['remaining']===0?'<a class="secondary-button" href="/tickets">联系售后查询</a>':'').'</div>';
    $html.='</section></div><section class="leader-card leader-invite"><div><span class="leader-eyebrow">专属邀请链接</span><h2>把好体验，分享给好友</h2><p>好友通过此链接注册后，才能建立你的邀请关系。</p></div><div><div class="leader-copy-row"><input id="leader-invite" readonly aria-label="专属邀请链接" value="'.e($link).'"><button class="button" type="button" data-leader-copy="leader-invite">复制邀请链接</button></div><small>专属邀请码：'.e($sum['code']).'</small></div></section><p id="leader-copy-status" class="leader-copy-status" role="status" aria-live="polite"></p><section class="leader-card leader-rules"><h2>活动规则与说明</h2><ol><li>通过专属链接邀请好友注册，建立邀请关系；仅注册不会增加达标人数。</li><li>按首笔有效付款产生的有效返佣记录去重统计，同一好友只计一次；并非所有付款都会计入。</li><li>活动开启且达到要求时，由付款结算流程自动发放指定套餐固定面额券，当前活动设置下每人仅发一次。</li><li>实际券面额、适用套餐与有效期以已发券为准，当前奖励配置变更不会替换已发券。</li><li>订单退款撤销返佣后，当前有效人数可能减少；已发奖励仍会显示，能否使用以券状态为准。</li></ol></section></div><script>(function(){var s=document.getElementById("leader-copy-status");document.querySelectorAll("[data-leader-copy]").forEach(function(b){b.addEventListener("click",function(){var i=document.getElementById(b.getAttribute("data-leader-copy"));function fallback(){i.focus();i.select();s.textContent="内容已选中，请手动复制"}if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(i.value).then(function(){s.textContent="复制成功"},fallback)}else fallback()})})})();</script>';
    return $html;
}
function referral_panel($pdo,$service,$uid,$config)
{
    $labels=array(''=>'全部','pending_credit'=>'待入账','credited'=>'已入账','recovery'=>'待追回','reversed'=>'已撤回','legacy'=>'历史佣金');
    $status=$_GET['status']??'';$range=$_GET['range']??'30';$search=$_GET['q']??'';$page=$_GET['page']??'1';$invitePage=$_GET['invite_page']??'1';
    if(!is_string($status)||!isset($labels[$status])||!is_string($range)||!in_array($range,array('30','90','all'),true)||!is_string($search)||mb_strlen($search,'UTF-8')>80||!is_string($page)||!preg_match('/^[1-9][0-9]{0,8}$/D',$page)||!is_string($invitePage)||!preg_match('/^[1-9][0-9]{0,8}$/D',$invitePage)){http_response_code(400);return '<section class="ri-empty"><h1>返佣筛选参数无效</h1><p>请选择有效状态、时间范围和页码。</p><a href="/events?view=referral">返回拉人返佣</a></section>';}
    $search=trim($search);$sum=$service->referralSummary($uid);$cfg=$service->config('referral');$enabled=$service->enabled('referral');$withdrawals=$service->withdrawals($uid);
    $base=rtrim($config['app']['base_url']??'','/');$link=$base.'/register?ref='.rawurlencode($sum['code']);$rate=rtrim(rtrim(number_format((int)$cfg['rate_bps']/100,2,'.',''),'0'),'.');
    $stateSql='CASE WHEN c.settlement_mode="legacy" THEN "legacy" WHEN c.recovery_status="pending" THEN "recovery" WHEN c.status="credited" THEN "credited" WHEN c.status="pending_credit" THEN "pending_credit" WHEN c.status="reversed" THEN "reversed" ELSE "unknown" END';
    $where='c.referrer_user_id=?';$args=array($uid);if($status!==''){$where.=' AND ('.$stateSql.')=?';$args[]=$status;}if($range!=='all'){$where.=' AND c.updated_at>=?';$args[]=date('Y-m-d H:i:s',time()-(int)$range*86400);}if($search!==''){$where.=' AND c.commission_no LIKE ? ESCAPE \'!\'';$args[]='%'.strtr($search,array('!'=>'!!','%'=>'!%','_'=>'!_')).'%';}
    $q=$pdo->prepare('SELECT COUNT(*) FROM referral_commissions c WHERE '.$where);$q->execute($args);$total=(int)$q->fetchColumn();$pages=max(1,(int)ceil($total/6));$current=min((int)$page,$pages);
    $q=$pdo->prepare('SELECT c.commission_no,c.amount_cents,c.status,c.settlement_mode,c.recovery_status,c.created_at,c.updated_at,u.email,'.$stateSql.' ui_state FROM referral_commissions c LEFT JOIN users u ON u.id=c.referred_user_id WHERE '.$where.' ORDER BY c.id DESC LIMIT 6 OFFSET '.(($current-1)*6));$q->execute($args);$items=$q->fetchAll();
    $invitePages=max(1,(int)ceil($sum['invited']/5));$inviteCurrent=min((int)$invitePage,$invitePages);$q=$pdo->prepare('SELECT u.email,r.created_at FROM referral_relations r LEFT JOIN users u ON u.id=r.user_id WHERE r.referrer_user_id=? AND r.status="active" ORDER BY r.id DESC LIMIT 5 OFFSET '.(($inviteCurrent-1)*5));$q->execute(array($uid));$invites=$q->fetchAll();
    $url=function($changes=array(),$anchor='')use($status,$range,$search,$current,$inviteCurrent){return '/events?'.http_build_query(array_merge(array('view'=>'referral','status'=>$status,'range'=>$range,'q'=>$search,'page'=>$current,'invite_page'=>$inviteCurrent),$changes),'','&',PHP_QUERY_RFC3986).$anchor;};
    $mask=function($email){$parts=explode('@',(string)$email,2);return $parts[0]!==''?mb_substr($parts[0],0,1,'UTF-8').'***'.(isset($parts[1])?'@'.$parts[1]:''):'已注销用户';};
    $html='<div class="ri-page"><header class="ao-welcome ri-welcome"><div><p class="ao-kicker">INVITE &amp; EARN</p><h1>拉人返佣</h1><p>分享你的邀请链接，按规则获得好友购买返佣。</p></div><a href="#referral-rules">查看返佣规则 ↗</a></header><div class="ri-top-grid"><section class="ri-invite"><h2>我的专属邀请链接</h2><p>通过此链接注册的好友将绑定邀请关系。</p><div class="ri-link"><input id="referral-invite-link" readonly aria-label="专属邀请链接" value="'.e($link).'"><button type="button" class="ao-primary" data-copy="referral-invite-link">复制链接</button></div><div class="ri-code"><label for="referral-invite-code">邀请码</label><input id="referral-invite-code" readonly value="'.e($sum['code']).'"><button type="button" data-copy="referral-invite-code">复制邀请码</button></div><p class="ri-rate">当前返佣比例 <b>'.e($rate).'%</b> · '.($enabled?'活动进行中':'活动暂未开启，当前不产生新返佣').'。</p><p id="referral-copy-status" role="status" class="ri-copy-status"></p></section><aside class="ri-guide" id="referral-rules"><h2>好服务，值得分享。</h2><ol><li><span>01</span>好友通过邀请链接注册</li><li><span>02</span>好友完成首笔符合规则的 VPN 付款</li><li><span>03</span>佣金按规则入账账户余额</li></ol><p>订单取消或退款可能触发佣金撤回；具体以系统记录及活动规则为准。</p></aside></div><section class="ri-stats" aria-label="我的返佣统计"><div><span>邀请人数</span><strong>'.(int)$sum['invited'].'<small> 人</small></strong></div><div><span>待入账佣金</span><strong>¥'.e(WalletService::formatAmount($sum['wallet_pending'])).'</strong></div><div><span>已入账佣金</span><strong>¥'.e(WalletService::formatAmount($sum['wallet_credited'])).'</strong></div><div><span>待追回佣金</span><strong>¥'.e(WalletService::formatAmount($sum['wallet_recovery_pending'])).'</strong></div></section><div class="ri-balance"><p>佣金入账后可用于订单全额支付。</p><a href="/wallet?type=referral_credit&amp;range=all">查看余额账本 ↗</a></div>';
    if($sum['wallet_recovery_pending']>0)$html.='<p class="ri-alert">待追回佣金由管理员核对处理，不会自动抵扣后续奖励。</p>';
    $tabs='';foreach($labels as $key=>$label)$tabs.='<a href="'.e($url(array('status'=>$key,'page'=>1),'#referral-records')).'"'.($status===$key?' aria-current="page"':'').'>'.$label.'</a>';
    $options='';foreach(array('30'=>'最近30天','90'=>'最近90天','all'=>'全部时间') as $value=>$label)$options.='<option value="'.$value.'"'.($range===(string)$value?' selected':'').'>'.$label.'</option>';
    $html.='<section class="ri-records" id="referral-records"><h2>返佣记录</h2><div class="ri-toolbar"><nav class="ri-tabs" aria-label="返佣状态筛选">'.$tabs.'</nav><form method="get" action="/events#referral-records"><input type="hidden" name="view" value="referral"><input type="hidden" name="status" value="'.e($status).'"><label class="ri-sr" for="referral-search">搜索返佣编号</label><input type="search" id="referral-search" name="q" maxlength="80" placeholder="搜索返佣编号…" value="'.e($search).'"><label class="ri-sr" for="referral-range">更新时间范围</label><select id="referral-range" name="range">'.$options.'</select><button type="submit">筛选</button></form></div><p class="ri-privacy">仅展示脱敏好友与本人返佣编号，不公开好友订单。时间范围按返佣更新时间筛选；上方统计为全部记录。</p><div class="ri-table-wrap"><table class="ri-table"><thead><tr><th scope="col">好友</th><th scope="col">返佣编号</th><th scope="col">佣金金额</th><th scope="col">状态</th><th scope="col">更新时间</th><th scope="col">操作</th></tr></thead><tbody>';
    foreach($items as $row){$state=$row['ui_state'];$stateLabel=$labels[$state]??'待核对';$html.='<tr class="ri-record"><td>'.e($mask($row['email'])).'</td><td class="ri-number">'.e($row['commission_no']).'</td><td class="ri-amount">¥'.e(WalletService::formatAmount((int)$row['amount_cents'])).'</td><td><span class="ri-state is-'.e($state).'">'.e($stateLabel).'</span></td><td><time datetime="'.e(str_replace(' ','T',$row['updated_at'])).'">'.e(substr($row['updated_at'],0,16)).'</time></td><td>'.($state==='credited'?'<a href="/wallet?type=referral_credit&amp;range=all">查看入账</a>':'<details class="ri-detail"><summary>查看详情</summary><div><b>'.e($row['commission_no']).'</b><p>结算方式：'.($row['settlement_mode']==='legacy'?'历史佣金':'账户余额').'</p><p>记录状态：'.e($stateLabel).'</p><p>创建时间：'.e($row['created_at']).'</p><p>历史状态：'.e(array('available'=>'可申请提现','approved'=>'已批准','paid'=>'已打款','reversed'=>'已撤回','pending'=>'待处理')[$row['status']]??$stateLabel).'</p></div></details>').'</td></tr>';}
    if(!$items)$html.='<tr><td colspan="6" class="ri-empty">暂无匹配的返佣记录，分享后可在这里查看真实进度。</td></tr>';
    $html.='</tbody></table></div><nav class="ri-pagination" aria-label="返佣记录分页"><span>共 '.$total.' 条 · 第 '.$current.' / '.$pages.' 页</span>'.($current>1?'<a rel="prev" href="'.e($url(array('page'=>$current-1),'#referral-records')).'">上一页</a>':'').'<b aria-current="page">'.$current.'</b>'.($current<$pages?'<a rel="next" href="'.e($url(array('page'=>$current+1),'#referral-records')).'">下一页 ›</a>':'<span aria-disabled="true">下一页 ›</span>').'</nav></section><div class="ri-bottom-grid"><section class="ri-friends" id="referral-invitations"><header><h2>我的邀请</h2><span>已绑定 '.(int)$sum['invited'].' 人</span></header><div class="ri-table-wrap"><table class="ri-table"><thead><tr><th scope="col">好友</th><th scope="col">绑定时间</th><th scope="col">绑定状态</th></tr></thead><tbody>';
    foreach($invites as $friend)$html.='<tr class="ri-friend"><td>'.e($mask($friend['email'])).'</td><td>'.e(substr($friend['created_at'],0,16)).'</td><td><span class="ri-bound">已绑定</span></td></tr>';
    if(!$invites)$html.='<tr><td colspan="3" class="ri-empty">暂无已绑定的邀请好友。</td></tr>';
    $html.='</tbody></table></div>';if($invitePages>1){$html.='<nav class="ri-pagination" aria-label="邀请记录分页"><span>第 '.$inviteCurrent.' / '.$invitePages.' 页</span>';foreach(array(-1=>'上一页',1=>'下一页') as $step=>$label)if($inviteCurrent+$step>=1&&$inviteCurrent+$step<=$invitePages)$html.='<a href="'.e($url(array('invite_page'=>$inviteCurrent+$step),'#referral-invitations')).'">'.$label.'</a>';$html.='</nav>';}
    $html.='</section><section class="ri-faq"><h2>常见问题</h2><details open><summary>佣金何时入账？</summary><p>活动开启期间，好友首笔符合条件的 VPN 付款按规则返佣；余额达到上限时待人工入账，处理状态以记录为准。</p></details><details><summary>好友退款后佣金如何处理？</summary><p>订单取消或退款会撤回返佣；余额不足时记为待追回，由管理员核对，不会自动抵扣后续奖励。</p></details><details><summary>返佣余额可以怎样使用？</summary><p>新佣金入消费余额，可用于站内订单全额支付，不支持提现。历史佣金仍按原流程人工审核，不自动转换为余额。</p></details><details><summary>如何确认邀请是否成功？</summary><p>好友通过专属链接完成注册后建立邀请关系，可在“我的邀请”查看已绑定记录。分享链接本身不代表获得奖励。</p></details></section></div>';
    if($sum['earned']>0||$withdrawals){$html.='<details class="ri-legacy"><summary>历史佣金与提现记录 <small>与新返余额奖励分别记账</small></summary><p>历史累计有效佣金 ¥'.e(WalletService::formatAmount($sum['earned'])).'，可申请提现 ¥'.e(WalletService::formatAmount($sum['available'])).'。历史资金不自动转入余额。</p>';if($sum['available']>0)$html.='<form method="post" action="/events/withdraw">'.csrf_field().'<label>历史提现金额（元）<input name="amount" inputmode="decimal" required></label><label>收款方式<input name="channel" maxlength="30" required></label><label>收款账号<input name="account_no" maxlength="190" required></label><button type="submit">提交历史提现审核</button></form>';$withdrawLabels=array('pending'=>'待审核','approved'=>'已批准','paid'=>'已打款','rejected'=>'已驳回');foreach($withdrawals as $w)$html.='<p>'.e($w['created_at']).' · ¥'.e(WalletService::formatAmount((int)$w['amount_cents'])).' · '.e($withdrawLabels[$w['status']]??'待核对').'</p>';$html.='</details>';}
    return $html.'<div class="ri-help"><p>ⓘ 请真实分享，邀请绑定与返佣结果以系统记录和活动规则为准。</p><strong>记录有疑问？</strong><a class="ao-button" href="/tickets#new-ticket">提交工单 ↗</a></div></div><script>(function(){var status=document.getElementById("referral-copy-status");document.querySelectorAll("[data-copy]").forEach(function(button){button.addEventListener("click",function(){var input=document.getElementById(button.dataset.copy);function fallback(){input.focus();input.select();status.textContent="内容已选中，请手动复制"}if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(input.value).then(function(){status.textContent=button.dataset.copy==="referral-invite-code"?"邀请码已复制":"邀请链接已复制"},fallback)}else fallback()})})})();</script>';
}
function events_overview($pdo, $config) {
    $category = isset($_GET['category']) && is_string($_GET['category']) ? $_GET['category'] : 'lottery';
    if (!in_array($category, array('lottery','group','referral'), true)) $category = 'lottery';
    $categoryTabs = '';
    foreach (array('lottery'=>'每日抽奖','group'=>'拼团活动','referral'=>'邀请奖励') as $key=>$label) {
        $categoryTabs .= '<a href="/events?category='.$key.'"'.($category===$key?' aria-current="page"':'').'>'.$label.'</a>';
    }
    $service = new ActivityService($pdo);
    $settings = array();
    foreach ($pdo->query('SELECT *, (status="enabled" AND (start_at IS NULL OR start_at<=NOW()) AND (end_at IS NULL OR end_at>=NOW())) AS is_live FROM activity_settings')->fetchAll() as $row) $settings[$row['activity_key']] = $row;
    $configs = array();
    foreach (array('lottery','referral','group','leader') as $key) {
        $saved = isset($settings[$key]) ? json_decode($settings[$key]['config_json'], true) : array();
        $configs[$key] = array_merge($service->defaults($key)['config'], is_array($saved) ? $saved : array());
    }
    $prizes = array();
    if (isset($settings['lottery'])) {
        $s = $pdo->prepare('SELECT name,value_cents,min_amount_cents,valid_days FROM lottery_prizes WHERE activity_id=? AND status="active" AND weight>0 AND (remaining_quantity<0 OR remaining_quantity>0) ORDER BY sort_order,id');
        $s->execute(array($settings['lottery']['id']));
        $prizes = $s->fetchAll();
    }
    $plans = array();
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)(isset($configs['group']['plan_ids']) ? $configs['group']['plan_ids'] : array())))));
    if ($ids) {
        $s = $pdo->prepare('SELECT pp.id,pp.name plan_name,pp.duration_days,pp.price_cents,p.name product_name FROM product_plans pp JOIN products p ON p.id=pp.product_id WHERE pp.id IN ('.implode(',',array_fill(0,count($ids),'?')).') AND pp.status="active" AND p.status="active" AND '.catalog_vpn_condition($pdo,'p').' ORDER BY p.sort_order,p.id,pp.sort_order,pp.id');
        $s->execute($ids);
        $plans = $s->fetchAll();
    }
    $user = !empty($_SESSION['user_id']);
    $lotteryLive = !empty($settings['lottery']['is_live']);
    $groupLive = !empty($settings['group']['is_live']) && (!$settings['group']['end_at'] || strtotime($settings['group']['end_at']) > time());
    $groupPlansReady = count($plans) === count($ids) && count($ids) > 0;
    $referralLive = !empty($settings['referral']['is_live']);
    $leaderLive = !empty($settings['leader']['is_live']);
    $html = '<div class="ep-page ev-page ev-category-'.e($category).'"><nav class="ep-crumb" aria-label="面包屑"><a href="/">首页</a><span>/</span><span>活动中心</span></nav><header class="ep-intro"><div><span class="ep-kicker">EVENTS &amp; REWARDS</span><h1>一起参与，让惊喜发生。</h1><p>发现订阅活动、邀请奖励与每日抽奖，查看规则后再参与。</p></div><a href="#my-event-records">我的活动记录 ↗</a></header><div class="ep-toolbar ev-tabs"><nav class="ep-categories" aria-label="活动分类">'.$categoryTabs.'</nav><a href="#event-rules">活动规则 ↗</a></div>';
    if ($category === 'lottery') {
    $html .= '<div class="ev-featured"><section class="ev-draw" id="daily-draw"><span class="ep-kicker">01 DAILY DRAW</span><div class="ev-card-heading"><h2>每日抽奖</h2><span class="ev-state">'.($lotteryLive ? ($prizes ? '活动已开启' : '奖池待配置') : '暂未开启').'</span></div><p>获得优惠券的机会，从今天开始。</p><div class="ev-prize-grid" aria-label="奖项预览，不代表中奖概率">';
    $prizeIndex = 0;
    for ($i=0; $i<9; $i++) {
        if ($i===4) {
            $html .= '<a class="ev-draw-entry" href="/events?view=lottery">'.(!$lotteryLive || !$prizes ? '查看抽奖 ↗' : ($user ? '前往抽奖 ↗' : '登录抽奖 ↗')).'</a>';
        } else {
            $prize = $prizes && $i%3!==1 ? $prizes[$prizeIndex++ % count($prizes)] : null;
            $html .= '<div class="ev-prize">'.($prize ? '<span>'.e($prize['name']).'</span><small>抵扣券 · '.e(money($prize['value_cents'])).'</small>' : '<span>'.($prizes ? '谢谢参与' : '奖池待配置').'</span>').'</div>';
        }
    }
    $html .= '</div><div class="ev-draw-bottom"><p>每日最多 '.(int)$configs['lottery']['daily_limit'].' 次；奖项展示不代表概率。</p><div><a href="#prize-rules">查看奖品规则 ↗</a><a href="/events?view=lottery">我的中奖记录 ↗</a></div></div></section></div>';
    }
    if ($category === 'referral') {
    $html .= '<div class="ev-featured"><section class="ev-invite" id="invite-friends"><span class="ep-kicker">02 INVITE FRIENDS</span><h2>好服务，<br>值得分享。</h2><p>邀请好友，按规则获得返佣奖励。</p><ol><li><span>01</span><b>登录获取邀请链接</b></li><li><span>02</span><b>好友通过链接注册并购买</b></li><li><span>03</span><b>符合规则后返佣入账余额</b></li></ol><a class="ep-button" href="/events?view=referral">'.($user ? '查看邀请链接' : '登录查看邀请链接').' ↗</a><small>'.($referralLive ? '活动已开启' : '暂未开启').' · 配置返佣比例 '.e(rtrim(rtrim(number_format(max(0,min(10000,(int)$configs['referral']['rate_bps']))/100,2,'.',''),'0'),'.')).'%。首笔有效 VPN 付款按实付金额返佣，退款撤销，余额不足待追回。</small></section></div>';
    }
    if ($category === 'group') {
    $html .= '<section id="group-events" class="ev-groups"><header><h2>拼团活动</h2><a href="#group-rules">查看拼团规则 ↗</a></header><div class="ev-group-grid">';
    foreach ($plans as $plan) {
        $percent = max(0,min(100,(int)$configs['group']['discount_percent']));
        $cents = (int)$plan['price_cents'];
        $price = intdiv($cents,100)*$percent + intdiv(($cents%100)*$percent,100);
        $html .= '<article class="ev-group-card"><div class="ev-group-title">'.home_outline_icon('globe').'<div><h3>'.e($plan['product_name']).' <small>'.e($plan['plan_name']).'</small></h3><p>'.(int)$plan['duration_days'].' 天 · '.(!$groupLive ? '配置方案 · 活动暂未开启' : ($groupPlansReady ? '可选活动方案' : '配置方案 · 部分方案不可用，暂不可开团')).'</p></div></div><div class="ev-group-meta"><dl><div><dt>成团人数</dt><dd>'.max(2,min(100,(int)$configs['group']['target_members'])).' 人</dd></div><div><dt>活动价格</dt><dd>'.e(money($price)).'<small> / 实付比例 '.$percent.'%</small></dd></div></dl><a class="ep-button" href="/events?view=group">查看拼团 ↗</a></div></article>';
    }
    if (!$plans) $html .= '<div class="ev-group-empty"><h3>新的同行计划，敬请期待。</h3><p>当前暂无可参与的拼团方案。开放后会在这里展示真实套餐与活动价格。</p><a href="/events?view=group">查看拼团详情 ↗</a></div>';
    $html .= '</div><p class="ev-group-note">付款后等待成团，成团成功后进入人工交付；未成团不交付。活动价格与资格以下单校验为准，不叠加优惠券。</p></section>';
    }
    if ($category === 'referral') {
    $html .= '<aside class="ev-leader"><h2>团长免单，邀请一起参与。</h2><p>'.($leaderLive ? '活动已开启' : '暂未开启').' · 达到 '.(int)$configs['leader']['required_referrals'].' 名有效邀请后，按规则获指定方案固定面额抵扣券。</p><a class="ep-button" href="/events?view=free">查看活动详情 ↗</a></aside>';
    }
    $html .= '<section class="ep-faq" id="event-rules"><h2>参与之前，<br>这些规则请先了解。</h2><div>';
    if ($category === 'lottery') {
    $html .= '<details open id="prize-rules"><summary>哪些商品可以使用优惠券？</summary><p>优惠券按适用范围、使用门槛与有效期使用，海外账号商品不参与优惠券与拼团。抽奖页登录后可查看本人剩余次数与中奖记录。';
    if ($prizes) {
        $html .= '当前可用奖品：';
        foreach ($prizes as $prize) $html .= e($prize['name']).'（面额 '.e(money($prize['value_cents'])).'，满 '.e(money($prize['min_amount_cents'])).' 可用，有效 '.(int)$prize['valid_days'].' 天）；';
        $html .= '配置中奖概率 '.max(0,min(100,(int)$configs['lottery']['win_weight'])).'%，具体奖项按权重抽取，库存及状态以参与时为准。';
    } else $html .= '当前奖池暂无可用奖品。';
    $html .= '</p></details>';
    }
    if ($category === 'group') {
    $html .= '<details open id="group-rules"><summary>拼团失败后如何处理退款？</summary><p>只有确认付款才计入成团人数，成团后逐单人工交付。未成团不交付；余额支付退回余额，余额容量不足时等待重试；外部支付由管理员核对后按原渠道处理，不承诺即时到账。</p></details>';
    }
    if ($category === 'referral') {
    $html .= '<details open><summary>邀请返佣在哪里查看？</summary><p>登录邀请返佣页面查看本人邀请与奖励记录。好友首笔有效 VPN 付款符合规则后返消费余额；取消或退款将撤销返佣，余额不足时待追回，余额容量不足时待人工入账。</p><a href="/events?view=referral">查看邀请奖励 ↗</a></details><details><summary>团长奖励券如何领取？</summary><p>有效邀请达到当前配置门槛后，由现有付款结算流程自动发放指定方案固定面额抵扣券，无需手动领取；每人当前配置下发放一次。奖励不是直接开通服务，退款撤销返佣可能影响有效人数。</p><a href="/events?view=free">查看奖励规则 ↗</a></details>';
    }
    $html .= '</div></section><section class="ev-records" id="my-event-records"><p>登录后可查看自己的参与记录、中奖结果与邀请奖励。</p><nav aria-label="本人活动记录"><a href="/events?view=lottery">中奖记录 ↗</a><a href="/events?view=group">我的拼团 ↗</a><a href="/events?view=referral">邀请奖励 ↗</a></nav></section><section class="ep-help"><h2>需要帮助？</h2><a href="/articles">查看使用说明 ↗</a><a href="/tickets">提交工单 ↗</a></section><footer class="ep-footer"><a class="ep-footer-brand" href="/">'.home_outline_icon('cloud').'<b>'.e($config['app']['name']).'</b></a><p>数字服务，简单一点。</p><nav><a href="/announcements">系统公告</a><a href="/articles">帮助中心</a></nav><small>© '.date('Y').' '.e($config['app']['name']).'</small></footer></div>';
    return $html;
}
$router->get('/events', function() use($config,$pdo){if(!isset($_GET['view']) || !is_string($_GET['view']) || !in_array($_GET['view'],array('lottery','referral','group','free'),true)) return page('活动中心',events_overview($pdo,$config),$config);$views=array(''=>'活动中心','lottery'=>'活动抽奖','referral'=>'拉人返佣','group'=>'好友拼单','free'=>'团长免单');$view=isset($_GET['view'])&&isset($views[$_GET['view']])?$_GET['view']:'';$service=new ActivityService($pdo);$title=$views[$view];if($view==='group'){$uid=Security::requireUser();return page($title,group_panel($pdo,$uid,$config),$config);}if($view==='free'){$uid=Security::requireUser();return page($title,leader_panel($service,$uid,$config),$config);}if($view==='referral'){$uid=Security::requireUser();return page($title,referral_panel($pdo,$service,$uid,$config),$config);}$body='<div class="benefits-wrap events-wrap"><header class="ux-page-head"><div><h1>'.e($title).'</h1><p>查看活动规则、参与记录与奖励状态。</p></div></header><section class="events-hero"><span>MEMBER ACTIVITIES</span><h2>'.($view===''?'发现更多会员活动':e($title)).'</h2><p>活动默认关闭，具体规则以后台配置和页面状态为准。</p></section>';if($view===''){$eventSettings=$pdo->query('SELECT activity_key FROM activity_settings WHERE status="enabled" AND (start_at IS NULL OR start_at<=NOW()) AND (end_at IS NULL OR end_at>=NOW())')->fetchAll(PDO::FETCH_COLUMN);$body.='<div class="event-grid">';foreach(array('lottery'=>array('幸运抽奖','按活动规则参与抽奖，领取站内优惠券。','优惠门槛与有效期以实际发券为准。','events'),'referral'=>array('邀请返佣','分享专属邀请链接，好友首笔有效付款后获得奖励。','符合规则的佣金自动返消费余额。','account'),'group'=>array('VPN 拼团','邀请好友一起选购，达到活动人数后享受拼团优惠。','只有确认付款才计入人数，成团后进入交付。','shop'),'free'=>array('团长优惠券','按活动规则邀请好友，达到有效邀请门槛后获券。','奖励方案与使用条件以活动详情为准。','orders')) as $eventKey=>$eventInfo){$body.='<a class="event-tile event-'.$eventKey.'" href="/events?view='.$eventKey.'"><span class="event-small-icon">'.user_nav_icon($eventInfo[3]).'</span><strong>'.e($eventInfo[0]).'</strong><small>'.e($eventInfo[1]).'</small><p class="event-rule">'.e($eventInfo[2]).'</p><em class="event-state">'.(in_array($eventKey==='free'?'leader':$eventKey,$eventSettings,true)?'活动已开启':'暂未开启').'</em><span class="event-art" aria-hidden="true">'.user_nav_icon($eventInfo[3]).'</span><b class="event-entry">查看活动详情 →</b></a>';}$body.='</div>';}elseif($view==='lottery'){$uid=Security::requireUser();$body.=lottery_panel($pdo,$service,$uid);}elseif($view==='group'){Security::requireUser();$groups=$service->groupCampaigns();$list='';foreach($groups as $g){$plans=$service->allowedGroupPlans($g['id']);$planText='';foreach($plans as $plan)$planText.='<option value="'.(int)$plan['id'].'">'.e($plan['product_name'].' / '.$plan['name'].' / '.$plan['duration_days'].' 天 / '.money($plan['price_cents'])).'</option>';$list.='<div class="event-group-row"><b>'.e($g['title']).'</b><span>'.(int)$g['current_members'].' / '.(int)$g['target_members'].' 人</span><small>截止 '.e($g['expires_at']).'</small><form method="post" action="/events/group/join">'.csrf_field().'<input type="hidden" name="campaign_id" value="'.(int)$g['id'].'"><select name="plan_id" required>'.$planText.'</select><button class="secondary-button" type="submit">加入并下单</button></form></div>';}$groupCfg=$service->config('group');$allowedPlanIds=array_map('intval',isset($groupCfg['plan_ids'])&&is_array($groupCfg['plan_ids'])?$groupCfg['plan_ids']:array());$plans=array();if($allowedPlanIds){$in=implode(',',array_fill(0,count($allowedPlanIds),'?'));$s=$pdo->prepare('SELECT pp.id,pp.name plan_name,pp.duration_days,pp.price_cents,p.name product_name FROM product_plans pp JOIN products p ON p.id=pp.product_id WHERE pp.id IN ('.$in.') AND pp.status="active" AND p.status="active" AND '.catalog_vpn_condition($pdo,'p').' ORDER BY p.sort_order,p.id,pp.sort_order,pp.id');$s->execute($allowedPlanIds);$plans=$s->fetchAll();}$options='';foreach($plans as $plan)$options.='<option value="'.(int)$plan['id'].'">'.e($plan['product_name'].' / '.$plan['plan_name'].' / '.$plan['duration_days'].' 天 / '.money($plan['price_cents'])).'</option>';$body.='<section class="event-detail-card"><h2>好友拼单</h2><p>选择后台开放的具体套餐方案创建或加入拼团；只有付款成功才计入成团人数。</p><form method="post" action="/events/group/create">'.csrf_field().'<label>选择套餐方案<select name="plan_id" required>'.$options.'</select></label><button class="button" type="submit">创建拼团</button></form>'.($list?:'<div class="empty">当前没有进行中的拼团。</div>').'</section>';}$body.='</div>';page($title,$body,$config);});
$router->post('/events/lottery', function() use($config,$pdo){$uid=Security::requireUser();post_csrf();unset($_SESSION['lottery_result']);try{$r=(new ActivityService($pdo))->lottery($uid);$_SESSION['lottery_result']=$r;flash('success',$r['won']?'恭喜中奖，优惠券 '.$r['coupon'].' 已发放。':'谢谢参与，明天再来试试。');}catch(Exception $e){flash('error',$e->getMessage());}redirect('/events?view=lottery');});
$router->post('/events/withdraw', function() use($config,$pdo){$uid=Security::requireUser();post_csrf();try{$raw=trim((string)$_POST['amount']);if(!preg_match('/^\d+(?:\.\d{1,2})?$/',$raw))throw new InvalidArgumentException('提现金额格式不正确。');$parts=explode('.',$raw);$cents=((int)$parts[0])*100+(int)str_pad(isset($parts[1])?$parts[1]:'',2,'0');(new ActivityService($pdo))->requestWithdrawal($uid,$cents,trim($_POST['channel']),trim(isset($_POST['account_name'])?$_POST['account_name']:''),trim($_POST['account_no']));flash('success','提现申请已提交，等待管理员人工审核。');}catch(Exception $e){flash('error',$e->getMessage());}redirect('/events?view=referral');});
$router->post('/events/group/create', function() use($config,$pdo){$uid=Security::requireUser();post_csrf();try{$planId=(int)$_POST['plan_id'];$id=(new ActivityService($pdo))->createGroup($uid,$planId);$s=$pdo->prepare('SELECT product_id FROM product_plans WHERE id=?');$s->execute(array($planId));$productId=(int)$s->fetchColumn();redirect('/order-confirm?product_id='.$productId.'&plan_id='.$planId.'&group_id='.(int)$id);}catch(Exception $e){flash('error',$e->getMessage());redirect('/events?view=group');}});
$router->post('/events/group/join', function() use($config,$pdo){$uid=Security::requireUser();post_csrf();try{$campaignId=(int)$_POST['campaign_id'];$planId=(int)$_POST['plan_id'];$result=(new ActivityService($pdo))->joinGroup($campaignId,$uid,$planId);redirect('/order-confirm?product_id='.(int)$result['product_id'].'&plan_id='.$planId.'&group_id='.$campaignId);}catch(Exception $e){flash('error',$e->getMessage());}redirect('/events?view=group');});
$router->get('/events-legacy', function(){header('Location: /events'.(!empty($_SERVER['QUERY_STRING'])?'?'.$_SERVER['QUERY_STRING']:''),true,301);exit;});
/* legacy activity view removed: use /events */
$router->get('/events-legacy-disabled', function() use($config,$pdo){$views=array(''=>'活动中心','lottery'=>'活动抽奖','referral'=>'拉人返佣','group'=>'好友拼单','free'=>'0 元购买');$view=isset($_GET['view'])?$_GET['view']:'';if(!isset($views[$view]))$view='';$title=$views[$view];$s=$pdo->query('SELECT id,title,summary,published_at FROM articles WHERE status="published" AND (category LIKE "%活动%" OR title LIKE "%活动%") ORDER BY published_at DESC,id DESC');$rows='';foreach($s as $a)$rows.='<a class="benefits-article" href="/articles/'.(int)$a['id'].'"><span>✦</span><div><strong>'.e($a['title']).'</strong><small>'.e($a['summary']).'</small></div><b>→</b></a>';$links='';foreach($views as $key=>$name){if($key==='')continue;$links.='<a class="event-tile" href="/events?view='.e($key).'"><span>✦</span><strong>'.e($name).'</strong><small>查看活动详情　→</small></a>';}$body='<div class="benefits-wrap events-wrap"><header class="ux-page-head"><div><h1>'.e($title).'</h1><p>查看当前发布的会员活动与公告。</p></div></header><section class="events-hero"><span>MEMBER ACTIVITIES</span><h2>'.($view===''?'发现更多会员活动':e($title)).'</h2><p>'.($view===''?'活动信息以实际发布内容为准。':'该活动当前尚未开放，请留意后续公告。').'</p></section>'.($view===''?'<div class="tutorial-section-head"><div><h2>活动栏目</h2><p>浏览各类活动</p></div></div><div class="event-grid">'.$links.'</div>':'<a class="events-back" href="/events">← 返回活动中心</a>').'<div class="tutorial-section-head"><div><h2>最新活动公告</h2><p>管理员发布的正式内容</p></div></div><div class="benefits-list">'.($rows?:'<div class="empty">当前没有已发布的活动公告。</div>').'</div></div>';page($title,$body,$config);});
$router->get('/benefits', function() use($config,$pdo){$s=$pdo->query('SELECT * FROM articles WHERE status="published" AND (category LIKE "%公益%" OR title LIKE "%公益%") ORDER BY published_at DESC,id DESC');$items=$s->fetchAll();$latest=$items?$items[0]:null;$cards='';foreach($items as $a)$cards.='<a class="benefits-article" href="/articles/'.(int)$a['id'].'"><span>◈</span><div><strong>'.e($a['title']).'</strong><small>'.e($a['summary']).'</small></div><b>→</b></a>';$body='<div class="benefits-wrap"><header class="ux-page-head"><div><h1>每日公益节点</h1><p>查看最新发布的公益内容与使用说明。</p></div></header><section class="benefits-hero"><div><span>每日公益 · 使用分享</span><h2>'.($latest?'最新公益内容已发布':'公益内容即将更新').'</h2><p>'.($latest?e($latest['summary']):'有可用内容时，会在这里显示由管理员发布的最新文章。').'</p>'.($latest?'<a href="/articles/'.(int)$latest['id'].'">查看最新内容　↗</a>':'<a href="/articles">浏览使用教程　↗</a>').'</div><div class="benefits-orbit" aria-hidden="true">✦</div></section><div class="benefits-note">请以实际发布内容为准；订阅或节点信息仅在对应文章及订单中查看。</div><div class="tutorial-section-head"><div><h2>往期内容</h2><p>管理员发布的公益文章</p></div></div><div class="benefits-list">'.($cards?:'<div class="empty">当前还没有已发布的公益内容。</div>').'</div></div>';page('每日公益节点',$body,$config);});
$router->get('/admin/articles', function() use($config,$pdo){Security::requireAdmin();$q=trim(isset($_GET['q'])?$_GET['q']:'');$status=isset($_GET['status'])?$_GET['status']:'';$where=array('1=1');$args=array();if($q){$where[]='(title LIKE ? OR summary LIKE ?)';$args[]='%'.$q.'%';$args[]='%'.$q.'%';}if(in_array($status,array('draft','published','offline'),true)){$where[]='status=?';$args[]=$status;}$s=$pdo->prepare('SELECT * FROM articles WHERE '.implode(' AND ',$where).' ORDER BY updated_at DESC,id DESC');$s->execute($args);$rows='';foreach($s as $a){$label=$a['status']==='published'?'已发布':($a['status']==='offline'?'已下架':'草稿');$rows.='<tr><td><b>'.e($a['title']).'</b><small class="table-sub">'.e($a['category']).' · '.e($a['updated_at']).'</small></td><td>'.e($a['summary']).'</td><td><span class="admin-status article-'.$a['status'].'">'.$label.'</span></td><td><div class="row-actions"><a class="row-edit" href="/admin/articles/'.$a['id'].'/edit">编辑</a><form method="post" action="/admin/articles/'.$a['id'].'/toggle" class="inline">'.csrf_field().'<button class="row-link" type="submit">'.($a['status']==='published'?'下架':'发布').'</button></form><form method="post" action="/admin/articles/'.$a['id'].'/delete" class="inline" data-confirm="确定删除这篇文章吗？">'.csrf_field().'<button class="row-danger" type="submit">删除</button></form></div></td></tr>';}$body='<div class="catalog-heading"><div><h1>文章管理</h1><p>发布和维护首页展示的教程、公益节点与帮助文章。</p></div><a class="button" href="/admin/articles/create">＋ 发布文章</a></div><nav class="admin-content-tabs admin-glass-tabs" aria-label="内容分类导航"><a href="/admin/content?tab=downloads">客户端版本</a><a href="/admin/articles" class="selected" aria-current="page">文章管理</a><a href="/admin/content?tab=announcements">文章公告</a></nav><section class="admin-panel catalog-panel"><form class="catalog-toolbar" method="get"><div class="catalog-search">⌕<input name="q" value="'.e($q).'" placeholder="搜索文章标题或摘要"></div><select name="status"><option value="">全部状态</option><option value="published"'.($status==='published'?' selected':'').'>已发布</option><option value="draft"'.($status==='draft'?' selected':'').'>草稿</option><option value="offline"'.($status==='offline'?' selected':'').'>已下架</option></select><button class="filter-button" type="submit">筛选</button></form><div class="table-wrap catalog-table-wrap"><table class="admin-table catalog-table article-admin-table"><thead><tr><th>文章标题</th><th>摘要</th><th>状态</th><th>操作</th></tr></thead><tbody>'.($rows?:'<tr><td colspan="4" class="admin-empty">还没有文章。</td></tr>').'</tbody></table></div></section>';page('文章管理',$body,$config);});
$router->get('/admin/articles/create', function() use($config){Security::requireAdmin();page('发布文章',article_form(null,'/admin/articles/create','发布文章'),$config);});
$router->post('/admin/articles/create', function() use($config,$pdo){$aid=Security::requireAdmin();post_csrf();try{$a=article_input();$now=date('Y-m-d H:i:s');$published=$a['status']==='published'?$now:null;$pdo->prepare('INSERT INTO articles(title,category,summary,content,cover_style,status,published_at,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute(array($a['title'],$a['category'],$a['summary'],$a['content'],$a['cover_style'],$a['status'],$published,$aid,$aid,$now,$now));flash('success','文章已保存。');redirect('/admin/articles');}catch(Exception $e){flash('error',$e->getMessage());redirect('/admin/articles/create');}});
$router->get('/admin/articles/{id}/edit', function($id) use($config,$pdo){Security::requireAdmin();$s=$pdo->prepare('SELECT * FROM articles WHERE id=?');$s->execute(array((int)$id));$a=$s->fetch();if(!$a)return page('文章不存在','<div class="empty">文章不存在。</div>',$config);page('编辑文章',article_form($a,'/admin/articles/'.$id.'/edit','编辑文章'),$config);});
$router->post('/admin/articles/{id}/edit', function($id) use($config,$pdo){$aid=Security::requireAdmin();post_csrf();try{$a=article_input();$s=$pdo->prepare('SELECT published_at FROM articles WHERE id=?');$s->execute(array((int)$id));$old=$s->fetch();if(!$old)throw new InvalidArgumentException('文章不存在。');$now=date('Y-m-d H:i:s');$published=$a['status']==='published'?($old['published_at']?:$now):null;$pdo->prepare('UPDATE articles SET title=?,category=?,summary=?,content=?,cover_style=?,status=?,published_at=?,updated_by=?,updated_at=? WHERE id=?')->execute(array($a['title'],$a['category'],$a['summary'],$a['content'],$a['cover_style'],$a['status'],$published,$aid,$now,(int)$id));flash('success','文章已更新。');redirect('/admin/articles');}catch(Exception $e){flash('error',$e->getMessage());redirect('/admin/articles/'.$id.'/edit');}});
$router->post('/admin/articles/{id}/toggle', function($id) use($config,$pdo){$aid=Security::requireAdmin();post_csrf();$s=$pdo->prepare('SELECT status FROM articles WHERE id=?');$s->execute(array((int)$id));$a=$s->fetch();if(!$a){flash('error','文章不存在。');return redirect('/admin/articles');}$status=$a['status']==='published'?'offline':'published';$pdo->prepare('UPDATE articles SET status=?,published_at=?,updated_by=?,updated_at=? WHERE id=?')->execute(array($status,$status==='published'?date('Y-m-d H:i:s'):null,$aid,date('Y-m-d H:i:s'),(int)$id));flash('success',$status==='published'?'文章已发布。':'文章已下架。');redirect('/admin/articles');});
$router->post('/admin/articles/{id}/delete', function($id) use($config,$pdo){Security::requireAdmin();post_csrf();$pdo->prepare('DELETE FROM articles WHERE id=?')->execute(array((int)$id));flash('success','文章已删除。');redirect('/admin/articles');});
function download_input(){
    $app=trim(isset($_POST['app_name'])?$_POST['app_name']:'');$version=trim(isset($_POST['version'])?$_POST['version']:'');$desc=trim(isset($_POST['description'])?$_POST['description']:'');$project=trim(isset($_POST['project_url'])?$_POST['project_url']:'');$platform=isset($_POST['platform'])?$_POST['platform']:'';$filename=trim(isset($_POST['filename'])?$_POST['filename']:'');$source=trim(isset($_POST['source_url'])?$_POST['source_url']:'');$status=isset($_POST['status'])?$_POST['status']:'active';$sort=filter_var(isset($_POST['sort_order'])?$_POST['sort_order']:'0',FILTER_VALIDATE_INT);
    if($app===''||strlen($app)>100||strlen($version)>60||strlen($desc)>500||!filter_var($project,FILTER_VALIDATE_URL)||parse_url($project,PHP_URL_SCHEME)!=='https'||!in_array($platform,array('Windows','macOS','Linux','Android'),true)||$filename===''||strlen($filename)>255||!filter_var($source,FILTER_VALIDATE_URL)||parse_url($source,PHP_URL_SCHEME)!=='https'||(strpos($source,'https://github.com/')!==0&&strpos($source,'https://gh-proxy.com/https://github.com/')!==0)||!in_array($status,array('active','inactive'),true)||$sort===false||$sort<0)throw new InvalidArgumentException('请检查软件、平台、HTTPS 地址和排序值。');
    if(strpos($source,'https://gh-proxy.com/https://github.com/')===0)$source=substr($source,strlen('https://gh-proxy.com/'));return array($app,$version,$desc,$project,$platform,$filename,$source,$status,$sort);
}
function download_form($row,$action,$title){$r=$row?:array('app_name'=>'','version'=>'','description'=>'','project_url'=>'','platform'=>'Windows','filename'=>'','source_url'=>'','status'=>'active','sort_order'=>0);$opts='';foreach(array('Windows','macOS','Linux','Android') as $x)$opts.='<option value="'.$x.'"'.($r['platform']===$x?' selected':'').'>'.$x.'</option>';return '<div class="product-form-card article-form-card"><div class="product-form-heading"><div><span class="admin-kicker">DOWNLOADS / 资源管理</span><h1>'.e($title).'</h1><p>维护代理软件的版本、平台和镜像下载地址。</p></div><a class="secondary-button" href="/admin/downloads">返回列表</a></div><form method="post" action="'.e($action).'">'.csrf_field().'<div class="product-form-grid"><div class="product-form-section"><h2>软件信息</h2><label>软件名称<input name="app_name" maxlength="100" value="'.e($r['app_name']).'" required></label><label>版本<input name="version" maxlength="60" value="'.e($r['version']).'" required></label><label>简介<textarea name="description" maxlength="500">'.e($r['description']).'</textarea></label><label>项目主页<input type="url" name="project_url" value="'.e($r['project_url']).'" required></label></div><div class="product-form-section"><h2>下载资源</h2><label>平台<select name="platform">'.$opts.'</select></label><label>文件名<input name="filename" maxlength="255" value="'.e($r['filename']).'" required></label><label>原始下载地址<input type="url" name="source_url" value="'.e($r['source_url']).'" required><small class="field-help">仅支持 GitHub Release 地址，前台自动加 gh-proxy.com 镜像。</small></label><div class="form-two"><label>排序<input type="number" name="sort_order" min="0" value="'.(int)$r['sort_order'].'"></label><label>状态<select name="status"><option value="active"'.($r['status']==='active'?' selected':'').'>启用</option><option value="inactive"'.($r['status']==='inactive'?' selected':'').'>停用</option></select></label></div></div></div><div class="product-form-actions"><a class="secondary-button" href="/admin/downloads">取消</a><button class="button" type="submit">保存资源</button></div></form></div>';}
$router->get('/admin/downloads', function() use($config,$pdo){Security::requireAdmin();$rows='';foreach($pdo->query('SELECT * FROM download_resources ORDER BY app_name,sort_order,id') as $r){$label=$r['status']==='active'?'启用':'停用';$rows.='<tr><td><b>'.e($r['app_name']).'</b><small class="table-sub">'.e($r['version']).' · '.e($r['platform']).'</small></td><td>'.e($r['filename']).'</td><td class="download-source-cell">'.e($r['source_url']).'</td><td><span class="admin-status '.($r['status']==='active'?'product-active':'product-inactive').'">'.$label.'</span></td><td><div class="row-actions"><a class="row-edit" href="/admin/downloads/'.$r['id'].'/edit">编辑</a><form method="post" action="/admin/downloads/'.$r['id'].'/toggle" class="inline">'.csrf_field().'<button class="row-link" type="submit">'.($r['status']==='active'?'停用':'启用').'</button></form><form method="post" action="/admin/downloads/'.$r['id'].'/delete" class="inline" data-confirm="确定删除这条资源吗？">'.csrf_field().'<button class="row-danger" type="submit">删除</button></form></div></td></tr>';}$body='<div class="catalog-heading"><div><span class="admin-kicker">DOWNLOADS / 资源管理</span><h1>软件下载</h1><p>管理软件版本和各平台下载地址。</p></div><a class="button" href="/admin/downloads/create">＋ 添加资源</a></div><section class="admin-panel catalog-panel"><div class="table-wrap catalog-table-wrap"><table class="admin-table catalog-table download-admin-table"><thead><tr><th>软件 / 平台</th><th>文件名</th><th>原始地址</th><th>状态</th><th>操作</th></tr></thead><tbody>'.($rows?:'<tr><td colspan="5" class="admin-empty">还没有下载资源。</td></tr>').'</tbody></table></div></section>';page('软件下载',$body,$config);});
$router->get('/admin/downloads/create', function() use($config){Security::requireAdmin();page('添加下载资源',download_form(null,'/admin/downloads/create','添加下载资源'),$config);});
$router->post('/admin/downloads/create', function() use($config,$pdo){Security::requireAdmin();post_csrf();try{$r=download_input();$now=date('Y-m-d H:i:s');$pdo->prepare('INSERT INTO download_resources(app_name,version,description,project_url,platform,filename,source_url,status,sort_order,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute(array($r[0],$r[1],$r[2],$r[3],$r[4],$r[5],$r[6],$r[7],$r[8],$now,$now));flash('success','下载资源已添加。');redirect('/admin/downloads');}catch(Exception $e){flash('error',$e->getMessage());redirect('/admin/downloads/create');}});
$router->get('/admin/downloads/{id}/edit', function($id) use($config,$pdo){Security::requireAdmin();$s=$pdo->prepare('SELECT * FROM download_resources WHERE id=?');$s->execute(array((int)$id));$r=$s->fetch();if(!$r)return page('资源不存在','<div class="empty">下载资源不存在。</div>',$config);page('编辑下载资源',download_form($r,'/admin/downloads/'.$id.'/edit','编辑下载资源'),$config);});
$router->post('/admin/downloads/{id}/edit', function($id) use($config,$pdo){Security::requireAdmin();post_csrf();try{$r=download_input();$now=date('Y-m-d H:i:s');$pdo->prepare('UPDATE download_resources SET app_name=?,version=?,description=?,project_url=?,platform=?,filename=?,source_url=?,status=?,sort_order=?,updated_at=? WHERE id=?')->execute(array($r[0],$r[1],$r[2],$r[3],$r[4],$r[5],$r[6],$r[7],$r[8],$now,(int)$id));flash('success','下载资源已更新。');redirect('/admin/downloads');}catch(Exception $e){flash('error',$e->getMessage());redirect('/admin/downloads/'.$id.'/edit');}});
$router->post('/admin/downloads/{id}/toggle', function($id) use($config,$pdo){Security::requireAdmin();post_csrf();$pdo->prepare('UPDATE download_resources SET status=IF(status="active","inactive","active"),updated_at=? WHERE id=?')->execute(array(date('Y-m-d H:i:s'),(int)$id));flash('success','下载资源状态已更新。');redirect('/admin/downloads');});
$router->post('/admin/downloads/{id}/delete', function($id) use($config,$pdo){Security::requireAdmin();post_csrf();$pdo->prepare('DELETE FROM download_resources WHERE id=?')->execute(array((int)$id));flash('success','下载资源已删除。');redirect('/admin/downloads');});
register_admin_extra_routes($router, $pdo, $config);
register_admin_log_routes($router, $pdo, $config);
register_admin_email_campaign_routes($router, $pdo, $config);
register_admin_email_template_routes($router, $pdo, $config);
register_email_unsubscribe_routes($router, $pdo, $config);
register_account_platform_routes($router, $pdo, $config);
register_admin_coupon_routes($router, $pdo, $config);
register_vpn_category_routes($router, $pdo, $config);
register_admin_wallet_routes($router, $pdo, $config);
register_notification_routes($router, $pdo, $config);
register_announcement_routes($router, $pdo, $config);
register_ticket_routes($router, $pdo, $config);
register_feedback_routes($router, $pdo, $config);
register_admin_feedback_routes($router, $pdo, $config);
$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
