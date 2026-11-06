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

function admin_operating_metrics_panel($pdo, $query, $path = '/admin')
{
    try {
        $range = OperatingMetricsService::range($query);
        $data = (new OperatingMetricsService($pdo))->snapshot($range['start'], $range['end']);
    } catch (InvalidArgumentException $error) {
        return '<section class="admin-panel"><p>'.e($error->getMessage()).'</p><a href="'.e($path).'">重置日期筛选</a></section>';
    }
    $tabs = '';
    foreach (array(14,30,90) as $days) $tabs .= '<a href="'.e($path).'?days='.$days.'">'.$days.' 天</a>';
    $html = '<section class="admin-panel admin-operating-panel"><div class="admin-panel-head"><div><h2>经营事实概览</h2><p>'.e($range['start_date']).' 至 '.e($range['end_date']).'（结束日含当日）</p></div><div class="admin-period-tabs">'.$tabs.'</div></div>';
    $html .= '<form method="get" action="'.e($path).'" class="admin-metrics-filter"><label>起始日期 <input type="date" name="start" value="'.e($range['start_date']).'" required></label><label>结束日期 <input type="date" name="end" value="'.e($range['end_date']).'" required></label><button type="submit">查询</button></form>';
    $labels = array('online_cents'=>'在线渠道确认付款额','manual_cents'=>'人工确认付款额','balance_cents'=>'余额支付额（非现金）','settlement_cents'=>'订单结算总额（含余额）','balance_refund_cents'=>'余额退回额（账本）','external_refund_cents'=>'外部退款登记额','feedback_reward_cents'=>'反馈奖励余额发生额','referral_credit_cents'=>'返佣余额入账额','referral_reversal_cents'=>'返佣余额追回发生额','admin_adjust_cents'=>'人工调账余额净发生额');
    $html .= '<div class="table-wrap"><table class="admin-table"><tbody>';
    foreach ($labels as $key=>$label) $html .= '<tr><th>'.e($label).'</th><td>'.e(WalletService::formatAmount($data['totals'][$key])).' 元</td></tr>';
    $html .= '<tr><th>零元结算数</th><td>'.$data['totals']['zero_count'].' 笔</td></tr></tbody></table></div><p>付款按订单主付款关联与原付款日期归属，不因后来退款消失。退款按处理日期另计；不推算净现金、银行实收或利润。余额无本金/赠送分桶，无法还原赠送余额消费比例。</p><p>日期按原数据库字段值及应用时区 '.e(date_default_timezone_get()).' 筛选；历史字段若混用数据库与应用时区，边界可能存在偏差，本报表不擅自换算或修复。</p>';
    $max = max(1, max($data['trend']));
    $bars = '';
    $i=0; $count=count($data['trend']);
    foreach ($data['trend'] as $day=>$cents) {
        $height = $cents===0 ? 0 : max(1, round($cents/$max*100, 1));
        $bars .= '<div class="admin-chart-day" title="'.e($day.'：'.WalletService::formatAmount($cents)).'"><span style="height:'.$height.'%"></span><small>'.($i % max(1,(int)ceil($count/6))===0 || $i===$count-1 ? e(substr($day,5)) : '&nbsp;').'</small></div>';
        $i++;
    }
    $html .= '<h3>订单结算趋势（含余额，非现金）</h3><div class="admin-chart-plot">'.$bars.'</div><h3>全历史异常待核对</h3><p>分类可重叠，不纳入商品结算或相减为现金。</p><div class="table-wrap"><table class="admin-table"><tbody>';
    $labels = array('missing_primary'=>'缺主付款关联','invalid_primary'=>'主付款错单/金额/渠道/状态/日期异常','extra_payment'=>'非主晚款、冲突或额外确认付款','undated_refund'=>'缺退款处理日期','invalid_group_refund'=>'拼团外部退款来源/金额/日期异常','nonprimary_refund'=>'非主付款的拼团退款（单独核对）','paid_cancelled_without_refund'=>'外部付款后取消但缺有效退款凭证');
    foreach ($labels as $key=>$label) $html .= '<tr><th>'.e($label).'</th><td>'.$data['anomalies'][$key].' 条</td></tr>';
    return $html.'</tbody></table></div><p>拼团外部退款待登记：'.$data['todos']['group_external_refunds'].' 条。存在拼团外部退款记录时，以该记录完成状态为准，不回退普通订单状态推算退款。</p><p>统计仅读取已登记事实，不修改历史资金或订单。</p></section>';
}

function admin_dashboard($pdo, $config)
{
    $today = date('Y-m-d');
    $metrics = new OperatingMetricsService($pdo);
    $todayMetrics = $metrics->snapshot($today.' 00:00:00', date('Y-m-d', strtotime('+1 day')).' 00:00:00');
    $todayRevenue = $todayMetrics['totals']['settlement_cents'];
    $todayOrders = (int)admin_scalar($pdo, 'SELECT COUNT(*) FROM orders WHERE created_at>=? AND created_at<?', array($today.' 00:00:00', date('Y-m-d', strtotime('+1 day')).' 00:00:00'));
    $todayUsers = (int)admin_scalar($pdo, 'SELECT COUNT(*) FROM users WHERE created_at>=? AND created_at<?', array($today.' 00:00:00', date('Y-m-d', strtotime('+1 day')).' 00:00:00'));
    $totalUsers = (int)admin_scalar($pdo, 'SELECT COUNT(*) FROM users');
    $activeSubscriptions = (int)admin_scalar($pdo, 'SELECT COUNT(DISTINCT o.id) FROM orders o JOIN delivery_records d ON d.order_id=o.id AND d.is_current=1 WHERE o.status="delivered" AND o.product_type_snapshot="vpn" AND (d.expires_at IS NULL OR d.expires_at>=?)', array(date('Y-m-d H:i:s')));
    $pending = (int)admin_scalar($pdo, 'SELECT COUNT(*) FROM orders WHERE status="pending_delivery"');
    $waiting = (int)admin_scalar($pdo, 'SELECT COUNT(*) FROM orders WHERE status="pending_payment"');
    $refunds = (int)admin_scalar($pdo, 'SELECT COUNT(*) FROM orders WHERE status="refunding"');
    $tickets = $todayMetrics['todos']['tickets'];
    $feedback = $todayMetrics['todos']['feedback'];
    $drafts = (int)admin_scalar($pdo, 'SELECT COUNT(*) FROM products WHERE status="draft"');
    try {
        $range = OperatingMetricsService::range($_GET);
    } catch (InvalidArgumentException $error) {
        page('运营概览', '<section class="admin-panel"><p>'.e($error->getMessage()).'</p><a href="/admin">重置日期筛选</a></section>', $config);
        return;
    }
    $period = $metrics->snapshot($range['start'], $range['end']);
    $recent = '';
    $orders = $pdo->query('SELECT order_no,product_name_snapshot,total_amount_cents,status,created_at FROM orders ORDER BY id DESC LIMIT 5');
    foreach ($orders as $row) {
        $href = '/admin/orders/'.rawurlencode($row['order_no']);
        $recent .= '<tr data-order-status="'.e($row['status']).'"><td><a title="'.e($row['order_no']).'" href="'.e($href).'">…'.e(substr($row['order_no'],-8)).'</a></td><td class="admin-recent-product" title="'.e($row['product_name_snapshot']).'">'.e($row['product_name_snapshot']).'</td><td><b>'.e(money($row['total_amount_cents'])).'</b></td><td><span class="admin-status '.e($row['status']).'">'.e(status_text($row['status'])).'</span></td><td>'.e(date('m-d H:i',strtotime($row['created_at']))).'</td><td><a href="'.e($href).'">查看详情 ›</a></td></tr>';
    }
    if ($recent==='') $recent='<tr data-order-status="empty"><td colspan="6" class="admin-empty">暂无订单，新订单会显示在这里。</td></tr>';
    $recent .= '<tr class="admin-recent-filter-empty" hidden><td colspan="6" class="admin-empty">最近五笔订单中暂无待付款订单。<a href="/admin/orders?status=pending_payment">查看全部待付款订单 →</a></td></tr>';
    $header = '<div class="admin-page-heading admin-overview-heading"><div><h1>运营概览</h1><p>你好，管理员。这里是今天的业务概况。</p></div><div class="admin-heading-actions"><span>'.admin_icon('calendar').date('Y年n月j日').'</span><a class="admin-refresh" href="'.e('/admin'.($_GET ? '?'.http_build_query($_GET) : '')).'">'.admin_icon('refresh').'刷新数据</a></div></div>';
    $hero = '<section class="admin-today-strip" aria-label="今日待办"><h2>今日待办</h2><a href="/admin/orders?status=pending_delivery">'.admin_icon('orders').'<b>'.$pending.'</b> 笔订单待交付</a><a href="/admin/tickets">'.admin_icon('feedback').'<b>'.$tickets.'</b> 个待回复工单</a><a href="/admin/orders?status=refunding">'.admin_icon('finance').'<b>'.$refunds.'</b> 笔退款待审核</a><a class="admin-strip-action" href="/admin/orders?status=pending_delivery">前往处理 →</a></section>';
    $stats = '<section class="admin-stats">'.admin_metric('今日订单结算',money($todayRevenue),'含余额支付，非现金实收','finance','violet').admin_metric('今日订单',(string)$todayOrders,'待交付 '.$pending.' 笔','orders','blue').admin_metric('注册用户',(string)$totalUsers,'今日新增 '.$todayUsers.' 人','users','green').admin_metric('有效订阅',(string)$activeSubscriptions,'已交付且未到期','cube','amber').'</section>';
    $tabs = '';
    foreach (array(14,30,90) as $days) {
        $selected = !isset($_GET['start']) && (isset($_GET['days']) ? (string)$_GET['days'] : '14') === (string)$days;
        $tabs .= '<a'.($selected ? ' class="selected" aria-current="true"' : '').' href="/admin?days='.$days.'">近'.$days.'天</a>';
    }
    $max = max(100, (int)(ceil(max($period['trend']) / 10000) * 10000));
    $bars = ''; $i = 0; $count = count($period['trend']);
    foreach ($period['trend'] as $day => $cents) {
        $showLabel = $count <= 14 || $i % max(1,(int)ceil($count/8)) === 0 || $i === $count-1;
        $bars .= '<div class="admin-chart-day" title="'.e($day.' 订单结算：'.money($cents)).'"><span style="height:'.round($cents/$max*100,2).'%"></span><small>'.($showLabel ? e(substr($day,5)) : '').'</small></div>';
        $i++;
    }
    $axis = '';
    for ($tick=4;$tick>=0;$tick--) $axis .= '<span>'.number_format($max/100*$tick/4,2).'</span>';
    $chart = '<section class="admin-panel admin-trend-panel"><div class="admin-panel-head"><h2>经营趋势</h2><div class="admin-period-tabs">'.$tabs.'</div></div><div class="admin-trend-toolbar"><div class="admin-trend-totals"><div><span class="admin-mini-icon violet">'.admin_icon('finance').'</span><span><small>订单结算（含余额）</small><strong>'.e(money($period['totals']['settlement_cents'])).'</strong></span></div><div><span class="admin-mini-icon amber">'.admin_icon('charity').'</span><span><small>外部退款登记额</small><strong>'.e(money($period['totals']['external_refund_cents'])).'</strong></span></div></div><details class="admin-date-picker"><summary>'.admin_icon('calendar').e($range['start_date'].' — '.$range['end_date']).'</summary><form method="get" action="/admin" class="admin-metrics-filter"><label>起始日期<input type="date" name="start" value="'.e($range['start_date']).'" required></label><label>结束日期<input type="date" name="end" value="'.e($range['end_date']).'" required></label><button type="submit">查询</button></form></details></div><div class="admin-chart-unit">金额（元）</div><div class="admin-trend-chart"><div class="admin-chart-axis">'.$axis.'</div><div class="admin-chart-plot">'.$bars.'</div></div><div class="admin-trend-legend"><i></i>订单结算金额（含余额，非现金）</div></section>';
    $table = '<section class="admin-panel admin-recent-panel"><div class="admin-panel-head"><h2>最近订单 <small>实时数据</small></h2><a href="/admin/orders">查看全部 →</a></div><div class="admin-recent-tabs" aria-label="最近订单筛选"><button type="button" data-order-tab="all" aria-pressed="true">全部</button><button type="button" data-order-tab="pending" aria-pressed="false">待付款</button><a href="/admin/orders?status=pending_payment">全部待付款（'.$waiting.'）</a></div><div class="table-wrap"><table class="admin-table"><thead><tr><th>订单号</th><th>商品</th><th>订单金额</th><th>状态</th><th>创建时间</th><th>操作</th></tr></thead><tbody>'.$recent.'</tbody></table></div></section>';
    $todo = '<section class="admin-panel admin-todo-panel"><div class="admin-panel-head"><h2>待办事项</h2></div><a href="/admin/orders?status=pending_delivery"><span class="admin-stat-icon amber">'.admin_icon('cube').'</span><span><b>待人工交付</b><small>'.($pending ? '有 '.$pending.' 笔订单需要交付' : '暂无需要交付的订单').'</small></span><em>去处理 ›</em></a><a href="/admin/orders?status=refunding"><span class="admin-stat-icon green">'.admin_icon('finance').'</span><span><b>待审核退款</b><small>'.($refunds ? '有 '.$refunds.' 笔退款待审核' : '暂无待审核的退款').'</small></span><em>查看 ›</em></a><a href="/admin/tickets"><span class="admin-stat-icon blue">'.admin_icon('feedback').'</span><span><b>待回复工单</b><small>'.($tickets ? '有 '.$tickets.' 个工单待回复' : '暂无待回复的工单').'</small></span><em>查看 ›</em></a><h3>快捷操作</h3><div class="admin-quick-actions"><a href="/admin/products/create">'.admin_icon('cube').'新建商品</a><a href="/admin/orders?status=pending_delivery">'.admin_icon('send').'手动交付</a><a href="/admin/users">'.admin_icon('users').'用户查询</a><a href="/admin/announcements/new">'.admin_icon('campaign').'发布公告</a></div></section>';
    $funds = '<section class="admin-panel admin-funds-panel"><div class="admin-panel-head"><h2>资金概览</h2><a href="/admin/finance?'.e(http_build_query(array('start'=>$range['start_date'],'end'=>$range['end_date']))).'">查看财务报表 →</a></div><dl>';
    foreach (array('online_cents'=>'在线渠道确认付款','manual_cents'=>'人工确认付款','balance_cents'=>'余额支付（非现金）','balance_refund_cents'=>'余额退回（账本）') as $key=>$label) $funds .= '<div><dt>'.e($label).'</dt><dd>'.e(money($period['totals'][$key])).'</dd></div>';
    $funds .= '</dl><small>与趋势图同一日期区间 · 非银行实收或利润</small></section>';
    $services = '<section class="admin-panel admin-service-panel"><div class="admin-panel-head"><h2>业务与服务</h2><a href="/admin/settings">系统设置 →</a></div><div class="admin-service-links"><a href="/admin/feedback?status=pending">反馈待审核 <b>'.$feedback.'</b></a><a href="/admin/orders?status=paid_waiting_group">待成团 <b>'.$todayMetrics['todos']['paid_waiting_group'].'</b></a><a href="/admin/logs">操作日志 →</a></div><div class="admin-service-meta"><a href="/admin/products?status=draft">商品草稿 '.$drafts.' 个</a><a href="/admin/users">用户余额合计 '.e(money($todayMetrics['todos']['balance_cents'])).'（非现金）</a></div></section>';
    page('运营概览','<div class="admin-overview">'.$header.$hero.$stats.'<div class="admin-dashboard-grid"><div class="admin-main-column">'.$chart.$table.'</div><div class="admin-side-column">'.$todo.$funds.$services.'</div></div><footer class="admin-overview-footer">数据统计时区：'.e(date_default_timezone_get()).'<span>统计仅读取已登记事实，不修改历史资金或订单</span></footer></div>',$config);
}
