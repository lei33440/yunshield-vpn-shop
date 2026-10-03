<?php

function admin_scalar($pdo, $sql, $params = array())
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchColumn();
}

function admin_metric($label, $value, $detail, $icon, $tone)
{
    return '<article class="admin-stat-card"><span class="admin-stat-icon '.$tone.'">'.admin_icon($icon).'</span><span class="admin-stat-copy"><small>'.e($label).'</small><strong>'.e($value).'</strong><em>'.e($detail).'</em></span></article>';
}

function admin_dashboard($pdo, $config)
{
    $today = date('Y-m-d');
    $days = isset($_GET['days']) && in_array((int)$_GET['days'], array(14,30,90), true) ? (int)$_GET['days'] : 14;
    $todayRevenue = (int)admin_scalar($pdo, 'SELECT COALESCE(SUM(total_amount_cents),0) FROM orders WHERE paid_at>=? AND paid_at<? AND status IN ("pending_delivery","delivered","refunding")', array($today.' 00:00:00', date('Y-m-d', strtotime('+1 day')).' 00:00:00'));
    $todayOrders = (int)admin_scalar($pdo, 'SELECT COUNT(*) FROM orders WHERE created_at>=? AND created_at<?', array($today.' 00:00:00', date('Y-m-d', strtotime('+1 day')).' 00:00:00'));
    $todayUsers = (int)admin_scalar($pdo, 'SELECT COUNT(*) FROM users WHERE created_at>=? AND created_at<?', array($today.' 00:00:00', date('Y-m-d', strtotime('+1 day')).' 00:00:00'));
    $totalUsers = (int)admin_scalar($pdo, 'SELECT COUNT(*) FROM users');
    $activeSubscriptions = (int)admin_scalar($pdo, 'SELECT COUNT(DISTINCT o.id) FROM orders o JOIN delivery_records d ON d.order_id=o.id AND d.is_current=1 WHERE o.status="delivered" AND (d.expires_at IS NULL OR d.expires_at>=NOW())');
    $pending = (int)admin_scalar($pdo, 'SELECT COUNT(*) FROM orders WHERE status="pending_delivery"');
    $waiting = (int)admin_scalar($pdo, 'SELECT COUNT(*) FROM orders WHERE status="pending_payment"');
    $refunds = (int)admin_scalar($pdo, 'SELECT COUNT(*) FROM orders WHERE status="refunding"');
    $tickets = (int)admin_scalar($pdo, 'SELECT COUNT(*) FROM support_tickets WHERE status<>"closed"');
    $drafts = (int)admin_scalar($pdo, 'SELECT COUNT(*) FROM products WHERE status="draft"');
    $since = date('Y-m-d', strtotime('-'.($days-1).' days'));
    $sales = array();
    $statement = $pdo->prepare('SELECT DATE(paid_at) AS day, SUM(total_amount_cents) AS cents FROM orders WHERE paid_at>=? AND status IN ("pending_delivery","delivered","refunding") GROUP BY DATE(paid_at)');
    $statement->execute(array($since.' 00:00:00'));
    foreach ($statement as $row) $sales[$row['day']] = (int)$row['cents'];
    $periodCents = array_sum($sales);
    $dailyMax = max(1, count($sales) ? max($sales) : 1);
    $bars = '';
    for ($i=$days-1; $i>=0; $i--) {
        $day = date('Y-m-d', strtotime('-'.$i.' days'));
        $cents = isset($sales[$day]) ? $sales[$day] : 0;
        $height = max(3, round($cents/$dailyMax*100, 1));
        $bars .= '<div class="admin-chart-day" title="'.e($day).'：'.e(money($cents)).'"><span style="height:'.$height.'%"></span><small>'.($i % max(1,(int)floor($days/5))===0 || $i===0 ? e(date('m/d',strtotime($day))) : '&nbsp;').'</small></div>';
    }
    $recent = '';
    $orders = $pdo->query('SELECT o.order_no,o.product_name_snapshot,o.total_amount_cents,o.status,o.created_at,u.email,u.display_name FROM orders o JOIN users u ON u.id=o.user_id ORDER BY o.id DESC LIMIT 5');
    foreach ($orders as $row) {
        $name = $row['display_name'] !== '' ? $row['display_name'] : $row['email'];
        $recent .= '<tr><td><a href="/admin/orders/'.e($row['order_no']).'">'.e($row['order_no']).'</a></td><td><span class="admin-user-cell"><i>'.e(mb_substr($name,0,1,'UTF-8')).'</i>'.e($name).'</span></td><td>'.e($row['product_name_snapshot']).'</td><td><b>'.e(money($row['total_amount_cents'])).'</b><small>'.e(date('H:i',strtotime($row['created_at']))).'</small></td><td><span class="admin-status '.e($row['status']).'">'.e(status_text($row['status'])).'</span></td></tr>';
    }
    if ($recent==='') $recent='<tr><td colspan="5" class="admin-empty">暂无订单。新订单会显示在这里。</td></tr>';
    $month = (int)date('n'); $dayNum = (int)date('j');
    $weekday = array('日','一','二','三','四','五','六')[(int)date('w')];
    $header = '<div class="admin-page-heading"><div><h1>运营概览</h1><p>掌握平台实时经营数据与服务状态</p></div></div>';
    $hero = '<section class="admin-hero"><div><small>'.date('Y').' 年 '.$month.' 月 '.$dayNum.' 日 · 星期'.$weekday.'</small><h2>您好，管理员</h2><p>平台运行正常，今天已有 '.$todayOrders.' 笔新订单。</p></div><div class="admin-hero-actions"><span>●　后台服务正常</span><a href="/admin/finance">↓　查看财务数据</a></div></section>';
    $stats = '<section class="admin-stats">'.admin_metric('今日实收',money($todayRevenue),'已确认付款订单','finance','violet').admin_metric('今日订单',(string)$todayOrders,'待处理 '.$pending.' 笔','orders','blue').admin_metric('新增用户',(string)$todayUsers,'总用户 '.number_format($totalUsers),'users','green').admin_metric('有效订阅',(string)$activeSubscriptions,'已交付且未到期','subscriptions','amber').'</section>';
    $tabs = ''; foreach (array(14,30,90) as $period) $tabs.='<a href="/admin?days='.$period.'"'.($days===$period?' class="selected"':'').'>'.$period.' 天</a>';
    $chart = '<section class="admin-panel admin-chart-panel"><div class="admin-panel-head"><div><h2>营收趋势</h2><p>最近 '.$days.' 天实收金额</p></div><div class="admin-period-tabs">'.$tabs.'</div></div><div class="admin-chart-total"><small>周期总收入</small><strong>'.e(money($periodCents)).'</strong></div><div class="admin-chart-legend"><i></i>实收金额</div><div class="admin-chart-plot">'.$bars.'</div></section>';
    $services = '<section class="admin-panel admin-service-panel"><div class="admin-panel-head"><div><h2>服务状态</h2><p>关键业务服务概况</p></div><a href="/admin/settings">查看设置</a></div><div class="admin-service-ring"><strong>运行中</strong><small>管理后台</small></div><div class="admin-service-list"><a href="/admin/orders?status=pending_delivery"><span>订单交付服务</span><em class="ok">正常</em></a><a href="/admin/orders?status=pending_payment"><span>付款确认</span><em class="'.($waiting?'warn':'ok').'">'.($waiting?$waiting.' 笔待处理':'正常').'</em></a><a href="/admin/tickets"><span>售后工单</span><em class="'.($tickets?'warn':'ok').'">'.($tickets?$tickets.' 笔待处理':'正常').'</em></a><a href="/admin/charity"><span>公益订阅</span><em>查看</em></a></div></section>';
    $table = '<section class="admin-panel admin-recent-panel"><div class="admin-panel-head"><div><h2>最新订单</h2><p>实时更新的平台交易</p></div><a href="/admin/orders">查看全部 →</a></div><div class="table-wrap"><table class="admin-table"><thead><tr><th>订单号</th><th>用户</th><th>订阅套餐</th><th>金额</th><th>状态</th></tr></thead><tbody>'.$recent.'</tbody></table></div></section>';
    $todo = '<section class="admin-panel admin-todo-panel"><div class="admin-panel-head"><div><h2>待办事项</h2><p>需要你处理的业务</p></div></div><a href="/admin/tickets"><span class="todo-dot violet"></span><span><b>'.$tickets.' 个售后工单待回复</b><small>查看并回复用户问题</small></span><em>立即处理 →</em></a><a href="/admin/orders?status=pending_delivery"><span class="todo-dot amber"></span><span><b>'.$pending.' 笔订单待发货</b><small>确认付款后交付订阅信息</small></span><em>处理订单 →</em></a><a href="/admin/orders?status=refunding"><span class="todo-dot blue"></span><span><b>'.$refunds.' 笔退款待处理</b><small>审核用户退款申请</small></span><em>去审核 →</em></a><a href="/admin/products?status=draft"><span class="todo-dot green"></span><span><b>'.$drafts.' 个套餐草稿</b><small>检查套餐信息并决定是否上架</small></span><em>查看详情 →</em></a></section>';
    page('运营概览',$header.$hero.$stats.'<div class="admin-dashboard-grid"><div class="admin-main-column">'.$chart.$table.'</div><div class="admin-side-column">'.$services.$todo.'</div></div>',$config);
}
