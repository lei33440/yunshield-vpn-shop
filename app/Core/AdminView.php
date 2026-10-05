<?php

function admin_icon($name)
{
    $paths = array(
        'overview' => '<path d="m3 10 9-7 9 7v10H3z"/><path d="M9 20v-7h6v7"/>',
        'products' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'subscriptions' => '<circle cx="6" cy="6" r="2"/><circle cx="18" cy="7" r="2"/><circle cx="12" cy="18" r="2"/><path d="m8 7 8 0m1 2-4 7m-3 0L7 8"/>',
        'orders' => '<path d="M5 8h14l-1 13H6L5 8Z"/><path d="M9 9V6a3 3 0 0 1 6 0v3"/>',
        'users' => '<circle cx="9" cy="8" r="3"/><path d="M3 20v-2a6 6 0 0 1 12 0v2"/><path d="M17 5a3 3 0 0 1 0 6m1 3a5 5 0 0 1 3 5v1"/>',
        'charity' => '<path d="M4 7a8 8 0 0 1 14-2l2 2M20 17a8 8 0 0 1-14 2l-2-2"/><path d="M20 3v4h-4M4 21v-4h4"/>',
        'tickets' => '<circle cx="12" cy="12" r="9"/><path d="M9 10a3 3 0 1 1 4 2.8c-.8.4-1 1-1 2M12 18h.01"/>',
        'events' => '<path d="m12 2 3 6 6.5 1-4.7 4.7 1.1 6.6L12 17.2l-5.9 3.1 1.1-6.6L2.5 9 9 8z"/>',
        'finance' => '<rect x="3" y="6" width="18" height="14" rx="2"/><path d="M3 10h18M7 16h3"/>',
        'content' => '<path d="M4 20h16M6 16l10-10 2 2-10 10-3 1z"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1 1 0 0 0 .2 1.1l.1.1-2 2-.1-.1a1 1 0 0 0-1.1-.2l-1 .4V21h-3v-1.7l-1-.4a1 1 0 0 0-1.1.2l-.1.1-2-2 .1-.1a1 1 0 0 0 .2-1.1l-.4-1H6v-3h1.7l.4-1a1 1 0 0 0-.2-1.1l-.1-.1 2-2 .1.1a1 1 0 0 0 1.1.2l1-.4V6h3v1.7l1 .4a1 1 0 0 0 1.1-.2l.1-.1 2 2-.1.1a1 1 0 0 0-.2 1.1l.4 1H21v3h-1.7z" transform="translate(-1 -1)"/>',
    );
    return '<svg aria-hidden="true" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">'.(isset($paths[$name]) ? $paths[$name] : '').'</svg>';
}

function admin_nav_link($href, $label, $icon, $active, $badge = 0)
{
    return '<a class="admin-nav-item'.($active ? ' active' : '').'" href="'.e($href).'"'.($active ? ' aria-current="page"' : '').'>'.admin_icon($icon).'<span>'.e($label).'</span>'.($badge ? '<em>'.(int)$badge.'</em>' : '').'</a>';
}

function admin_page($title, $body, $notice, $path, $pdo, $config)
{
    $active = 'overview';
    $routes = array(
        '/admin/products' => 'products', '/admin/subscriptions' => 'subscriptions',
        '/admin/orders' => 'orders', '/admin/users' => 'users', '/admin/charity' => 'charity',
        '/admin/tickets' => 'tickets', '/admin/events' => 'events', '/admin/finance' => 'finance',
        '/admin/content' => 'content', '/admin/articles' => 'content', '/admin/downloads' => 'content',
        '/admin/settings' => 'settings', '/admin/payment' => 'settings',
        '/admin/temporary-subscription' => 'charity',
    );
    foreach ($routes as $prefix => $section) {
        if (strpos($path, $prefix) === 0) { $active = $section; break; }
    }
    $pending = (int)$pdo->query('SELECT COUNT(*) FROM orders WHERE status="pending_delivery"')->fetchColumn();
    $tickets = (int)$pdo->query('SELECT COUNT(*) FROM support_tickets WHERE status<>"closed"')->fetchColumn();
    $adminName = isset($_SESSION['admin_name']) && $_SESSION['admin_name'] !== '' ? $_SESSION['admin_name'] : '管理员';
    $avatar = mb_substr($adminName, 0, 1, 'UTF-8');
    $nav = '<div class="admin-nav-group"><div class="admin-label">工作台</div>'.admin_nav_link('/admin','运营概览','overview',$active==='overview').'</div>';
    $nav .= '<div class="admin-nav-group"><div class="admin-label">业务管理</div>'.admin_nav_link('/admin/products','订阅套餐','products',$active==='products').admin_nav_link('/admin/subscriptions','订阅管理','subscriptions',$active==='subscriptions').admin_nav_link('/admin/orders','订单管理','orders',$active==='orders',$pending).admin_nav_link('/admin/users','用户管理','users',$active==='users').admin_nav_link('/admin/charity','公益订阅','charity',$active==='charity').'</div>';
    $nav .= '<div class="admin-nav-group"><div class="admin-label">运营与服务</div>'.admin_nav_link('/admin/tickets','售后工单','tickets',$active==='tickets',$tickets).admin_nav_link('/admin/events','活动运营','events',$active==='events').admin_nav_link('/admin/finance','财务中心','finance',$active==='finance').admin_nav_link('/admin/content','内容管理','content',$active==='content').'</div>';
    $nav .= '<div class="admin-nav-group"><div class="admin-label">系统</div>'.admin_nav_link('/admin/settings','系统设置','settings',$active==='settings').'</div>';
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="icon" type="image/png" href="/assets/site-icon.png"><title>'.e($title).' - '.e($config['app']['name']).' · 管理后台</title><link rel="stylesheet" href="/assets/style.css?v=figma-menu-20261001"><link rel="stylesheet" href="/assets/admin-figma.css?v=20261001-admin-final"></head><body class="admin-body"><div class="admin-shell"><aside class="admin-sidebar" aria-label="后台导航"><a class="admin-brand" href="/admin"><img class="admin-brand-logo" src="/assets/xiaoyun-logo.png" alt="'.e($config['app']['name']).'"><span>管理后台</span></a><nav>'.$nav.'</nav><div class="admin-profile"><span class="avatar">'.e($avatar).'</span><span><b>'.e($adminName).'</b><small>系统权限：全部</small></span><form method="post" action="/admin/logout">'.csrf_field().'<button type="submit" title="退出登录" aria-label="退出登录">↪</button></form></div></aside><div class="admin-content"><header class="admin-topbar"><button class="admin-menu" type="button" aria-label="打开后台菜单" aria-expanded="false">☰</button><form class="admin-global-search" method="get" action="/admin/search" role="search"><span aria-hidden="true">⌕</span><input name="q" type="search" placeholder="搜索订单、用户或订阅..." aria-label="搜索订单、用户或订阅"><kbd>⌘ K</kbd></form><div class="admin-top-actions"><a class="admin-bell" href="/admin/tickets" aria-label="待处理工单">♧'.($tickets ? '<i></i>' : '').'</a><span class="admin-top-avatar">'.e($avatar).'</span><span class="admin-top-user"><b>'.e($adminName).'</b><small>管理后台</small></span></div></header><main class="admin-main">'.$notice.$body.'</main></div></div><script>document.querySelectorAll("[data-confirm]").forEach(function(e){e.addEventListener("click",function(t){if(!confirm(e.dataset.confirm))t.preventDefault()})});var menu=document.querySelector(".admin-menu");if(menu)menu.addEventListener("click",function(){var open=document.body.classList.toggle("sidebar-open");menu.setAttribute("aria-expanded",String(open));menu.setAttribute("aria-label",open?"关闭后台菜单":"打开后台菜单")});document.addEventListener("click",function(e){if(document.body.classList.contains("sidebar-open")&&!e.target.closest(".admin-sidebar")&&!e.target.closest(".admin-menu")){document.body.classList.remove("sidebar-open");menu.setAttribute("aria-expanded","false")}});document.addEventListener("keydown",function(e){if(e.key==="Escape"&&document.body.classList.contains("sidebar-open")){document.body.classList.remove("sidebar-open");menu.setAttribute("aria-expanded","false");menu.focus()}});document.addEventListener("keydown",function(e){if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==="k"){e.preventDefault();var search=document.querySelector(".admin-global-search input");if(search)search.focus()}});</script></body></html>';
}

function admin_login_page($body, $notice, $config)
{
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="icon" type="image/png" href="/assets/site-icon.png"><title>管理员登录 - '.e($config['app']['name']).'</title><link rel="stylesheet" href="/assets/style.css?v=figma-menu-20261001"><link rel="stylesheet" href="/assets/admin-figma.css?v=20261001-admin-final"></head><body class="admin-auth-body"><main class="admin-auth-main"><a class="admin-auth-brand" href="/"><img src="/assets/xiaoyun-logo.png" alt="'.e($config['app']['name']).'"></a><div class="admin-auth-caption">管理后台 · ADMIN CONSOLE</div>'.$notice.$body.'<p class="admin-auth-back"><a href="/">← 返回用户前台</a></p></main></body></html>';
}
