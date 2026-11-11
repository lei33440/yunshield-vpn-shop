<?php

function admin_icon($name)
{
    $paths = array(
        'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>',
        'chevron' => '<path d="m9 5 7 7-7 7"/>',
        'collapse' => '<path d="m11 6-6 6 6 6m8-12-6 6 6 6"/>',
        'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
        'external' => '<path d="M14 3h7v7m0-7L10 14M10 3H4v17h17v-6"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 11h18m-12 4h.01m6 0h.01"/>',
        'refresh' => '<path d="M20 7v5h-5M4 17v-5h5M5 7a8 8 0 0 1 13-2l2 2M4 17l2 2a8 8 0 0 0 13-2"/>',
        'cube' => '<path d="m12 2 9 5v10l-9 5-9-5V7l9-5Zm0 10 9-5M3 7l9 5v10M7.5 4.5l9 5"/>',
        'campaign' => '<path d="m3 10 17-6v16L3 14v-4Zm4 5 1 6h4l-2-5M20 8h2m-2 8h2"/>',
        'logout' => '<path d="M9 4H4v16h5m6-12 5 4-5 4m-7-4h12"/>',
        'overview' => '<path d="m3 10 9-7 9 7v10H3z"/><path d="M9 20v-7h6v7"/>',
        'products' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'accounts' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M6 16v-1a3 3 0 0 1 6 0v1M15 9h3M15 13h3"/>',
        'feedback' => '<path d="M21 11a8 8 0 0 1-8 8H7l-5 3 2-6a8 8 0 0 1-1-4V5a3 3 0 0 1 3-3h12a3 3 0 0 1 3 3z"/><path d="M7 7h10M7 11h7"/>',
        'subscriptions' => '<circle cx="6" cy="6" r="2"/><circle cx="18" cy="7" r="2"/><circle cx="12" cy="18" r="2"/><path d="m8 7 8 0m1 2-4 7m-3 0L7 8"/>',
        'orders' => '<path d="M5 8h14l-1 13H6L5 8Z"/><path d="M9 9V6a3 3 0 0 1 6 0v3"/>',
        'users' => '<circle cx="9" cy="8" r="3"/><path d="M3 20v-2a6 6 0 0 1 12 0v2"/><path d="M17 5a3 3 0 0 1 0 6m1 3a5 5 0 0 1 3 5v1"/>',
        'charity' => '<path d="M4 7a8 8 0 0 1 14-2l2 2M20 17a8 8 0 0 1-14 2l-2-2"/><path d="M20 3v4h-4M4 21v-4h4"/>',
        'tickets' => '<circle cx="12" cy="12" r="9"/><path d="M9 10a3 3 0 1 1 4 2.8c-.8.4-1 1-1 2M12 18h.01"/>',
        'events' => '<path d="m12 2 3 6 6.5 1-4.7 4.7 1.1 6.6L12 17.2l-5.9 3.1 1.1-6.6L2.5 9 9 8z"/>',
        'finance' => '<rect x="3" y="6" width="18" height="14" rx="2"/><path d="M3 10h18M7 16h3"/>',
        'content' => '<path d="M4 20h16M6 16l10-10 2 2-10 10-3 1z"/>',
        'email' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'send' => '<path d="m3 11 18-8-8 18-2-7-8-3Z"/><path d="m11 14 4-4"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1 1 0 0 0 .2 1.1l.1.1-2 2-.1-.1a1 1 0 0 0-1.1-.2l-1 .4V21h-3v-1.7l-1-.4a1 1 0 0 0-1.1.2l-.1.1-2-2 .1-.1a1 1 0 0 0 .2-1.1l-.4-1H6v-3h1.7l.4-1a1 1 0 0 0-.2-1.1l-.1-.1 2-2 .1.1a1 1 0 0 0 1.1.2l1-.4V6h3v1.7l1 .4a1 1 0 0 0 1.1-.2l.1-.1 2 2-.1.1a1 1 0 0 0-.2 1.1l.4 1H21v3h-1.7z" transform="translate(-1 -1)"/>',
    );
    return '<svg aria-hidden="true" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">'.(isset($paths[$name]) ? $paths[$name] : '').'</svg>';
}

function admin_nav_link($href, $label, $icon, $active, $badge = 0)
{
    return '<a class="admin-nav-item'.($active ? ' active' : '').'" href="'.e($href).'"'.($active ? ' aria-current="page"' : '').'>'.admin_icon($icon).'<span>'.e($label).'</span>'.($badge ? '<em>'.(int)$badge.'</em>' : '').'</a>';
}

function admin_nav_section($label, $icon, $links, $active, $badge = 0)
{
    $items = '';
    $open = false;
    foreach ($links as $link) {
        $selected = $active === $link[2];
        $open = $open || $selected;
        $items .= admin_nav_link($link[0], $link[1], $icon, $selected, isset($link[3]) ? $link[3] : 0);
    }
    return '<details class="admin-nav-section"'.($open ? ' open' : '').'><summary title="'.e($label).'">'.admin_icon($icon).'<span>'.e($label).'</span>'.($badge ? '<em>'.(int)$badge.'</em>' : '').admin_icon('chevron').'</summary><div class="admin-subnav">'.$items.'</div></details>';
}

function admin_page($title, $body, $notice, $path, $pdo, $config)
{
    $active = 'overview';
    $routes = array(
        '/admin/vpn-categories' => 'vpn-categories', '/admin/products' => 'products', '/admin/accounts' => 'accounts', '/admin/account-platforms' => 'account-platforms', '/admin/subscriptions' => 'subscriptions',
        '/admin/orders' => 'orders', '/admin/renewals' => 'renewals', '/admin/users' => 'users', '/admin/charity' => 'charity',
        '/admin/tickets' => 'tickets', '/admin/feedback' => 'feedback', '/admin/promotions' => 'promotions', '/admin/coupons' => 'coupons', '/admin/events' => 'events', '/admin/finance' => 'finance',
        '/admin/announcements' => 'announcements', '/admin/content' => 'content', '/admin/articles' => 'content', '/admin/downloads' => 'content',
        '/admin/email-campaigns' => 'email-campaigns', '/admin/email-templates' => 'email-templates',
        '/admin/settings' => 'settings', '/admin/payment' => 'payment', '/admin/logs' => 'logs', '/admin/subboost-tasks' => 'subboost-tasks',
        '/admin/temporary-subscription' => 'charity',
        '/admin/feedback' => 'feedback',
    );
    foreach ($routes as $prefix => $section) {
        if (strpos($path, $prefix) === 0) { $active = $section; break; }
    }
    $pending = (int)$pdo->query('SELECT COUNT(*) FROM orders WHERE status="pending_delivery"')->fetchColumn();
    $renewalPending = (int)$pdo->query('SELECT COUNT(*) FROM orders WHERE order_kind="renewal" AND status="pending_delivery" AND paid_at IS NOT NULL')->fetchColumn();
    $tickets = (int)$pdo->query('SELECT COUNT(*) FROM support_tickets WHERE status="open"')->fetchColumn();
    $adminName = isset($_SESSION['admin_name']) && $_SESSION['admin_name'] !== '' ? $_SESSION['admin_name'] : '管理员';
    $avatar = mb_substr($adminName, 0, 1, 'UTF-8');
    $nav = '<div class="admin-nav-group admin-reference-nav">';
    $nav .= admin_nav_section('工作台', 'overview', array(array('/admin','运营概览','overview'), array('/admin/finance','财务中心','finance')), $active);
    $nav .= admin_nav_section('商品与订单', 'orders', array(array('/admin/products','订阅套餐','products'), array('/admin/accounts','海外账号','accounts'), array('/admin/vpn-categories','订阅分类','vpn-categories'), array('/admin/account-platforms','账号平台','account-platforms'), array('/admin/orders','订单管理','orders',$pending), array('/admin/renewals','续费待办','renewals',$renewalPending), array('/admin/subscriptions','订阅管理','subscriptions')), $active);
    $nav .= admin_nav_section('用户与财务', 'finance', array(array('/admin/users','用户管理与余额','users'), array('/admin/payment','支付设置','payment')), $active);
    $nav .= admin_nav_section('营销活动', 'campaign', array(array('/admin/promotions','优惠活动','promotions'), array('/admin/coupons','优惠码管理','coupons'), array('/admin/events','活动运营','events')), $active);
    $nav .= admin_nav_section('客服与内容', 'feedback', array(array('/admin/tickets','售后工单','tickets',$tickets), array('/admin/feedback','反馈建议','feedback'), array('/admin/content','文章与资源','content'), array('/admin/announcements','系统与维护通知','announcements'), array('/admin/charity','公益订阅','charity')), $active);
    $nav .= admin_nav_section('通知与系统', 'settings', array(array('/admin/email-templates','邮件模板','email-templates'), array('/admin/email-campaigns','通知邮件群发','email-campaigns'), array('/admin/settings','系统设置','settings'), array('/admin/logs','操作日志','logs'), array('/admin/subboost-tasks','SubBoost任务','subboost-tasks')), $active).'</div>';
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="icon" type="image/png" href="/assets/site-icon.png"><title>'.e($title).' - '.e($config['app']['name']).' · 管理后台</title><link rel="stylesheet" href="/assets/style.css?v=release-1.0.17"><link rel="stylesheet" href="/assets/admin-figma.css?v=catalog-20261110"></head><body class="admin-body"><div class="admin-shell"><aside class="admin-sidebar" aria-label="后台导航"><div class="admin-brand-row"><a class="admin-brand" href="/admin"><img class="admin-brand-logo" src="/assets/xiaoyun-logo.png" alt="'.e($config['app']['name']).'"><span>管理控制台</span></a><button class="admin-collapse" type="button" aria-label="收起侧栏" aria-expanded="true">'.admin_icon('collapse').'</button></div><label class="admin-menu-search">'.admin_icon('search').'<input type="search" placeholder="搜索菜单" aria-label="搜索后台菜单"></label><nav aria-label="管理菜单">'.$nav.'<p class="admin-nav-empty" hidden>没有匹配的菜单</p></nav><div class="admin-profile"><span class="avatar">'.e($avatar).'</span><span><b>'.e($adminName).'</b><small>全权限管理员</small></span><form method="post" action="/admin/logout">'.csrf_field().'<button type="submit" title="退出登录" aria-label="退出登录">'.admin_icon('logout').'</button></form></div></aside><div class="admin-content"><header class="admin-topbar"><button class="admin-menu" type="button" aria-label="打开后台菜单" aria-expanded="false">☰</button><div class="admin-breadcrumb"><a href="/admin">工作台</a><span>/</span><b>'.e($title).'</b></div><form class="admin-global-search" method="get" action="/admin/search" role="search">'.admin_icon('search').'<input name="q" type="search" placeholder="搜索订单号、用户邮箱..." aria-label="搜索订单、用户或订阅"></form><div class="admin-top-actions"><a class="admin-visit" href="/" target="_blank" rel="noopener">'.admin_icon('external').'<span>访问前台</span></a><a class="admin-bell" href="/admin/tickets" aria-label="待处理工单">'.admin_icon('bell').($tickets ? '<i></i>' : '').'</a><span class="admin-top-avatar">'.admin_icon('users').'</span><span class="admin-top-user"><b>'.e($adminName).'</b></span></div></header><main class="admin-main">'.$notice.$body.'</main></div></div><script>document.querySelectorAll("[data-confirm]").forEach(function(e){e.addEventListener("click",function(t){if(!confirm(e.dataset.confirm))t.preventDefault()})});var menu=document.querySelector(".admin-menu");if(menu)menu.addEventListener("click",function(){var open=document.body.classList.toggle("sidebar-open");menu.setAttribute("aria-expanded",String(open));menu.setAttribute("aria-label",open?"关闭后台菜单":"打开后台菜单")});document.addEventListener("click",function(e){if(document.body.classList.contains("sidebar-open")&&!e.target.closest(".admin-sidebar")&&!e.target.closest(".admin-menu")){document.body.classList.remove("sidebar-open");menu.setAttribute("aria-expanded","false");menu.setAttribute("aria-label","打开后台菜单")}});document.addEventListener("keydown",function(e){if(e.key==="Escape"&&document.body.classList.contains("sidebar-open")){document.body.classList.remove("sidebar-open");menu.setAttribute("aria-expanded","false");menu.setAttribute("aria-label","打开后台菜单");menu.focus()}});document.addEventListener("keydown",function(e){if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==="k"){e.preventDefault();var search=document.querySelector(".admin-global-search input");if(search)search.focus()}});var collapse=document.querySelector(".admin-collapse");collapse.addEventListener("click",function(){if(window.innerWidth<=900){document.body.classList.remove("sidebar-open");menu.setAttribute("aria-expanded","false");menu.setAttribute("aria-label","打开后台菜单");return}var closed=document.body.classList.toggle("sidebar-collapsed");collapse.setAttribute("aria-expanded",String(!closed));collapse.setAttribute("aria-label",closed?"展开侧栏":"收起侧栏")});document.querySelectorAll(".admin-nav-section>summary").forEach(function(summary){summary.addEventListener("click",function(event){if(document.body.classList.contains("sidebar-collapsed")){event.preventDefault();summary.parentElement.open=true;document.body.classList.remove("sidebar-collapsed");collapse.setAttribute("aria-expanded","true");collapse.setAttribute("aria-label","收起侧栏")}})});var navSearch=document.querySelector(".admin-menu-search input");var sections=Array.from(document.querySelectorAll(".admin-nav-section"));navSearch.addEventListener("input",function(){var q=navSearch.value.trim().toLowerCase();var hits=0;sections.forEach(function(section){if(q&&!section.hasAttribute("data-search-open"))section.dataset.searchOpen=String(section.open);var match=section.querySelector("summary span").textContent.toLowerCase().includes(q);var visible=0;section.querySelectorAll(".admin-nav-item").forEach(function(link){link.hidden=!(match||link.textContent.toLowerCase().includes(q));if(!link.hidden)visible++});section.hidden=!visible;if(q)section.open=!!visible;else if(section.hasAttribute("data-search-open")){section.open=section.dataset.searchOpen==="true";delete section.dataset.searchOpen}hits+=visible});document.querySelectorAll(".admin-nav-group>.admin-nav-item").forEach(function(link){link.hidden=!link.textContent.toLowerCase().includes(q);if(!link.hidden)hits++});document.querySelectorAll(".admin-nav-group").forEach(function(group){group.hidden=!Array.from(group.querySelectorAll(".admin-nav-item")).some(function(link){return !link.hidden})});document.querySelector(".admin-nav-empty").hidden=hits>0});document.querySelectorAll("[data-order-tab]").forEach(function(tab){tab.addEventListener("click",function(){var pending=tab.dataset.orderTab==="pending";document.querySelectorAll("[data-order-status]").forEach(function(row){row.hidden=pending&&row.dataset.orderStatus!=="pending_payment"});document.querySelectorAll("[data-order-tab]").forEach(function(button){button.setAttribute("aria-pressed",String(button===tab))});var empty=document.querySelector(".admin-recent-filter-empty");if(empty)empty.hidden=!pending||!!document.querySelector("[data-order-status=pending_payment]")})});</script><script src="/assets/admin-ui.js?v=warm-20261108"></script></body></html>';
}

function admin_login_page($body, $notice, $config)
{
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="icon" type="image/png" href="/assets/site-icon.png"><title>管理员登录 - '.e($config['app']['name']).'</title><link rel="stylesheet" href="/assets/style.css?v=release-1.0.17"><link rel="stylesheet" href="/assets/admin-figma.css?v=catalog-20261110"></head><body class="admin-auth-body"><main class="admin-auth-main"><a class="admin-auth-brand" href="/"><img src="/assets/xiaoyun-logo.png" alt="'.e($config['app']['name']).'"></a><div class="admin-auth-caption">管理后台 · ADMIN CONSOLE</div>'.$notice.$body.'<p class="admin-auth-back"><a href="/">← 返回用户前台</a></p></main></body></html>';
}
