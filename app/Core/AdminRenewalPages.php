<?php

function register_admin_renewal_routes($router, $pdo, $config)
{
    $router->get('/admin/renewals', function() use ($pdo, $config) {
        Security::requireAdmin();
        if (PHP_SAPI !== 'cli') header('Cache-Control: no-store, private');
        try {
            $listing = (new RenewalTodoService($pdo, $config))->listing($_GET);
        } catch (InvalidArgumentException $exception) {
            http_response_code(400);
            return page('续费待办', '<div class="admin-log-page">'.admin_heading('续费待办', '集中查看人工续费订单。').'<div class="admin-info-banner" role="alert">筛选条件无效，请检查订单号、状态、付款日期范围或页码。未查询任何记录。</div><a class="secondary-button" href="/admin/renewals">重置筛选</a></div>', $config);
        }
        return page('续费待办', admin_renewal_listing($listing), $config);
    });
}

function admin_renewal_url($filters, $page)
{
    $params = array_intersect_key($filters, array_flip(array('status','q','from','to')));
    $params['page'] = $page;
    return '/admin/renewals?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function admin_renewal_listing($listing)
{
    $f = $listing['filters'];
    $html = '<div class="admin-log-page">'.admin_heading('续费待办', '默认优先展示最早付款、等待人工续费的订单。').'<div class="admin-info-banner"><p>请先在上游手动续费，再进入订单详情核对并标记续费成功。本页仅查询，不会发起支付、调用 SubBoost 或自动延长订阅。</p><p>预计到期仅为当前预览，最终以详情页人工确认时的交付版本为准。列表不代表已验证实际付款凭证或上游操作。</p></div>';
    $html .= '<section class="admin-panel"><h2>筛选续费订单</h2><form class="admin-log-filter" method="get" action="/admin/renewals">';
    $html .= admin_log_input('q', '原单号 / 续费单号（包含）', $f['q']);
    $html .= '<label>续费状态<select name="status">';
    foreach (RenewalTodoService::statuses() as $key=>$label) $html .= '<option value="'.e($key).'"'.($f['status'] === $key ? ' selected' : '').'>'.e($label).'</option>';
    $html .= '</select></label>'.admin_log_input('from', '付款开始日期（含）', $f['from'], 'date').admin_log_input('to', '付款结束日期（含）', $f['to'], 'date');
    $html .= '<div class="admin-log-filter-actions"><button class="button" type="submit">查询</button><a class="secondary-button" href="/admin/renewals">重置筛选</a></div></form></section>';
    $html .= '<section class="admin-panel admin-log-results"><div class="admin-panel-head"><div><h2>续费订单</h2><p>'.e('共 '.$listing['total'].' 条 · 每页 50 条 · 等待时长截至 '.$listing['as_of']).'</p></div></div>';
    if (!$listing['items']) $html .= '<div class="admin-module-empty">暂无符合条件的续费订单。</div>';
    else {
        $columns = array('order_no'=>'续费单号', 'parent_order_no'=>'原订单', 'customer_name'=>'客户昵称', 'customer_email'=>'客户邮箱', 'status_label'=>'续费状态', 'amount_cents'=>'金额', 'duration_days'=>'续费天数', 'paid_at'=>'付款时间', 'waiting'=>'等待时长', 'expires_at'=>'当前到期', 'preview'=>'预计新到期', 'issues'=>'核对提示');
        $html .= '<div class="table-wrap admin-log-table" role="region" aria-label="续费待办，可横向滚动" tabindex="0"><table><caption class="admin-log-caption">续费订单查询结果</caption><thead><tr>';
        foreach ($columns as $label) $html .= '<th scope="col">'.e($label).'</th>';
        $html .= '<th scope="col">操作</th></tr></thead><tbody>';
        foreach ($listing['items'] as $row) {
            $html .= '<tr>';
            foreach ($columns as $key=>$label) {
                $value = $row[$key];
                if ($key === 'amount_cents') $value = money($value);
                elseif ($key === 'duration_days' && $value !== null) $value .= ' 天';
                if (($key === 'order_no' || $key === 'parent_order_no') && $value) $cell = '<a href="'.e('/admin/orders/'.rawurlencode($value)).'">'.e($value).'</a>';
                else $cell = e($value === null || $value === '' ? ($key === 'issues' ? '—' : '未记录') : $value);
                $html .= '<td>'.$cell.'</td>';
            }
            $html .= '<td><a class="secondary-button" href="'.e('/admin/orders/'.rawurlencode($row['order_no'])).'">'.($f['status'] === 'pending_delivery' ? '去处理' : '查看详情').'</a></td></tr>';
        }
        $html .= '</tbody></table></div>';
    }
    $html .= '<nav class="admin-log-pagination" aria-label="续费订单分页">';
    if ($listing['page'] > 1) $html .= '<a class="secondary-button" href="'.e(admin_renewal_url($f, 1)).'">首页</a><a class="secondary-button" rel="prev" href="'.e(admin_renewal_url($f, $listing['page']-1)).'">上一页</a>';
    $html .= '<span aria-current="page">'.e('第 '.$listing['page'].' / '.$listing['pages'].' 页').'</span>';
    if ($listing['page'] < $listing['pages']) $html .= '<a class="secondary-button" rel="next" href="'.e(admin_renewal_url($f, $listing['page']+1)).'">下一页</a><a class="secondary-button" href="'.e(admin_renewal_url($f, $listing['pages'])).'">末页</a>';
    return $html.'</nav></section></div>';
}
