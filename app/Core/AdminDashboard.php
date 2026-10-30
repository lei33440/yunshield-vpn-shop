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
    $operatingPanel = admin_operating_metrics_panel($pdo, $_GET, '/admin');
    $recent = '';
    $orders = $pdo->query('SELECT o.order_no,o.product_name_snapshot,o.total_amount_cents,o.status,o.created_at,u.email,u.display_name FROM orders o JOIN users u ON u.id=o.user_id ORDER BY o.id DESC LIMIT 5');
    foreach ($orders as $row) {
        $name = $row['display_name'] !== '' ? $row['display_name'] : $row['email'];
        $recent .= '<tr><td><a href="/admin/orders/'.e($row['order_no']).'">'.e($row['order_no']).'</a></td><td><span class="admin-user-cell"><i>'.e(mb_substr($name,0,1,'UTF-8')).'</i>'.e($name).'</span></td><td>'.e($row['product_name_snapshot']).'</td><td><b>'.e(money($row['total_amount_cents'])).'</b><small>'.e(date('H:i',strtotime($row['created_at']))).'</small></td><td><span class="admin-status '.e($row['status']).'">'.e(status_text($row['status'])).'</span></td></tr>';
    }
    if ($recent==='') $recent='<tr><td colspan="5" class="admin-empty">暂无订单。新订单会显示在这里。</td></tr>';
    $month = (int)date('n'); $dayNum = (int)date('j');
    $weekday = array('日','一','二','三','四','五','六')[(int)date('w')];
    $header = '<div class="admin-page-heading"><div><h1>运营概览</h1><p>查看订单付款事实、余额发生额与业务待办</p></div></div>';
    $hero = '<section class="admin-hero"><div><small>'.date('Y').' 年 '.$month.' 月 '.$dayNum.' 日 · 星期'.$weekday.'</small><h2>您好，管理员</h2><p>今天已有'.$todayOrders.' 笔新订单。</p></div><div class="admin-hero-actions"><span>业务待办（非健康监控）</span><a href="/admin/finance">↓　查看财务数据</a></div></section>';
    $stats = '<section class="admin-stats">'.admin_metric('今日订单结算',WalletService::formatAmount($todayRevenue),'含余额，不代表现金','finance','violet').admin_metric('今日订单',(string)$todayOrders,'待处理 '.$pending.' 笔','orders','blue').admin_metric('新增用户',(string)$todayUsers,'总用户 '.number_format($totalUsers),'users','green').admin_metric('有效订阅',(string)$activeSubscriptions,'已交付且未到期','subscriptions','amber').'</section>';
    $chart = $operatingPanel;
    $services = '<section class="admin-panel admin-service-panel"><div class="admin-panel-head"><div><h2>业务状态说明</h2><p>业务队列，不代表系统健康探测</p></div></div><div class="admin-service-list"><a href="/admin/orders?status=paid_waiting_group"><span>付款后待成团</span><em>'.$todayMetrics['todos']['paid_waiting_group'].' 笔</em></a><a href="/admin/feedback?status=pending"><span>反馈待审核</span><em>'.$feedback.' 条</em></a><a href="/admin/users"><span>用户余额合计（非现金）</span><em>'.e(WalletService::formatAmount($todayMetrics['todos']['balance_cents'])).'</em></a></div></section>';
    $table = '<section class="admin-panel admin-recent-panel"><div class="admin-panel-head"><div><h2>最新订单</h2><p>实时更新的平台交易</p></div><a href="/admin/orders">查看全部 →</a></div><div class="table-wrap"><table class="admin-table"><thead><tr><th>订单号</th><th>用户</th><th>订阅套餐</th><th>金额</th><th>状态</th></tr></thead><tbody>'.$recent.'</tbody></table></div></section>';
    $todo = '<section class="admin-panel admin-todo-panel"><div class="admin-panel-head"><div><h2>待办事项</h2><p>需要你处理的业务</p></div></div><a href="/admin/tickets"><span class="todo-dot violet"></span><span><b>'.$tickets.' 个售后工单待回复</b><small>查看并回复用户问题</small></span><em>立即处理 →</em></a><a href="/admin/orders?status=pending_delivery"><span class="todo-dot amber"></span><span><b>'.$pending.' 笔订单待发货</b><small>确认付款后交付订阅信息</small></span><em>处理订单 →</em></a><a href="/admin/orders?status=refunding"><span class="todo-dot blue"></span><span><b>'.$refunds.' 笔退款待处理</b><small>审核用户退款申请</small></span><em>去审核 →</em></a><a href="/admin/products?status=draft"><span class="todo-dot green"></span><span><b>'.$drafts.' 个套餐草稿</b><small>检查套餐信息并决定是否上架</small></span><em>查看详情 →</em></a></section>';
    page('运营概览',$header.$hero.$stats.'<div class="admin-dashboard-grid"><div class="admin-main-column">'.$chart.$table.'</div><div class="admin-side-column">'.$services.$todo.'</div></div>',$config);
}
