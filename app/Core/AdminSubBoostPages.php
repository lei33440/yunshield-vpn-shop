<?php

function register_admin_subboost_routes($router, $pdo, $config)
{
    $router->get('/admin/subboost-tasks', function() use ($pdo, $config) {
        Security::requireAdmin();
        try {
            $listing = (new SubBoostTaskService($pdo, $config))->listing($_GET);
        } catch (InvalidArgumentException $exception) {
            // 不重查、不回显原始输入或异常，避免非法条件被放宽和敏感信息泄露。
            http_response_code(400);
            return page('SubBoost任务', admin_subboost_invalid(), $config);
        }
        page('SubBoost任务', admin_subboost_listing($listing), $config);
    });
    $router->get('/admin/subboost-tasks/{id}', function($id) use ($pdo, $config) {
        Security::requireAdmin();
        try {
            $id = admin_subboost_positive_int($id);
            $historyPage = admin_subboost_positive_int(isset($_GET['history_page']) ? $_GET['history_page'] : '1');
            $detail = (new SubBoostTaskService($pdo, $config))->detail($id, $historyPage);
        } catch (InvalidArgumentException $exception) {
            http_response_code(400);
            return page('SubBoost任务', admin_subboost_invalid(), $config);
        }
        if ($detail === null) {
            http_response_code(404);
            return page('SubBoost任务不存在', '<div class="admin-log-page">'.admin_heading('SubBoost任务不存在', '未找到该任务。').'<a class="secondary-button" href="/admin/subboost-tasks">返回任务列表</a></div>', $config);
        }
        page('SubBoost任务详情', admin_subboost_detail($detail), $config);
    });
}

function admin_subboost_positive_int($value)
{
    if (!is_scalar($value) || !preg_match('/\A[1-9][0-9]*\z/D', (string)$value)) throw new InvalidArgumentException('invalid');
    $number = filter_var($value, FILTER_VALIDATE_INT, array('options'=>array('min_range'=>1)));
    if ($number === false) throw new InvalidArgumentException('invalid');
    return $number;
}

function admin_subboost_invalid()
{
    return '<div class="admin-log-page">'.admin_heading('SubBoost任务', '本页仅查询，不会启动或重试任务。').'<div class="admin-info-banner admin-log-error" role="alert"><strong>筛选条件无效</strong><p>请检查任务 ID、订单号、状态、错误码、日期范围或页码。未查询任何记录。</p></div><a class="secondary-button" href="/admin/subboost-tasks">重置筛选</a></div>';
}

function admin_subboost_value($item, $key)
{
    return isset($item[$key]) && is_scalar($item[$key]) && (string)$item[$key] !== '' ? (string)$item[$key] : '未记录';
}

function admin_subboost_note()
{
    return '<div class="admin-info-banner"><p>本页仅查询，不会启动或重试任务。waiting 表示等待上游配置，不是失败。order_ineligible 表示订单状态或人工交付导致暂不自动交付，请核对实际交付情况，不要强行修改订单状态。</p><p>启用诊断前的逐次执行历史无法追溯，重试次数不是完整尝试次数。未知或未记录的信息不代表成功或失败。</p></div>';
}

function admin_subboost_url($filters, $changes = array())
{
    $params = array_intersect_key(array_merge($filters, $changes), array_flip(array('status','error','order_no','id','from','to','page')));
    return '/admin/subboost-tasks?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function admin_subboost_pagination($page, $pages, $url)
{
    $pages = max(1, (int)$pages);
    $page = max(1, min($pages, (int)$page));
    $html = '<nav class="admin-log-pagination" aria-label="任务记录分页">';
    if ($page > 1) $html .= '<a class="secondary-button" href="'.e($url(1)).'">首页</a><a class="secondary-button" rel="prev" href="'.e($url($page - 1)).'">上一页</a>';
    $html .= '<span aria-current="page">'.e('第 '.$page.' / '.$pages.' 页').'</span>';
    if ($page < $pages) $html .= '<a class="secondary-button" rel="next" href="'.e($url($page + 1)).'">下一页</a><a class="secondary-button" href="'.e($url($pages)).'">末页</a>';
    return $html.'</nav>';
}

function admin_subboost_cell($item, $key, $detailLink = false)
{
    $value = admin_subboost_value($item, $key);
    if ($key === 'transport_errno') {
        // 保留安全编号，并使用查询服务的固定映射解释；缺失不视为零或成功。
        $errno = isset($item[$key]) && is_numeric($item[$key]) ? (int)$item[$key] : null;
        $reason = SubBoostTaskService::transportReason($errno);
        return $errno === null ? e($reason) : e($errno.' · '.$reason);
    }
    if ($key === 'order_no' && $value !== '未记录') return '<a href="'.e('/admin/orders/'.rawurlencode($value)).'">'.e($value).'</a>';
    if (($key === 'id' || $key === 'error_text') && $detailLink) return '<a href="'.e('/admin/subboost-tasks/'.rawurlencode(admin_subboost_value($item, 'id'))).'">'.e($value).'</a>';
    return e($value);
}

function admin_subboost_table($items, $columns, $caption, $detailLink = false)
{
    $html = '<div class="table-wrap admin-log-table" role="region" aria-label="'.e($caption.'，可横向滚动').'" tabindex="0"><table><caption class="admin-log-caption">'.e($caption).'</caption><thead><tr>';
    foreach ($columns as $label) $html .= '<th scope="col">'.e($label).'</th>';
    $html .= '</tr></thead><tbody>';
    foreach ($items as $item) {
        $html .= '<tr>';
        // 只按显式安全列渲染，不遍历服务数据中的其他字段。
        foreach ($columns as $key=>$label) $html .= '<td>'.admin_subboost_cell($item, $key, $detailLink).'</td>';
        $html .= '</tr>';
    }
    return $html.'</tbody></table></div>';
}

function admin_subboost_listing($listing)
{
    $f = array_merge(array('status'=>'','error'=>'','order_no'=>'','id'=>'','from'=>'','to'=>'','page'=>1), $listing['filters']);
    $html = '<div class="admin-log-page">'.admin_heading('SubBoost任务', '查询自动交付任务的当前状态、失败原因与安全排查建议。').'<nav class="admin-log-tabs" aria-label="任务导航"><a href="/admin/logs">系统日志</a><a class="selected" aria-current="page" href="/admin/subboost-tasks">SubBoost任务</a></nav>'.admin_subboost_note();
    $html .= '<section class="admin-panel"><h2>筛选任务</h2><form class="admin-log-filter" method="get" action="/admin/subboost-tasks">';
    $html .= admin_log_input('id','任务 ID（精确）',$f['id']).admin_log_input('order_no','订单号（精确）',$f['order_no']);
    $html .= admin_log_select('status','任务状态',SubBoostTaskService::statuses(),$f['status']).admin_log_select('error','错误码',SubBoostTaskService::errors(),$f['error']);
    $html .= admin_log_input('from','创建开始日期（含）',$f['from'],'date').admin_log_input('to','创建结束日期（含）',$f['to'],'date');
    $html .= '<div class="admin-log-filter-actions"><button class="button" type="submit">查询</button><a class="secondary-button" href="/admin/subboost-tasks">重置筛选</a></div></form></section>';
    $html .= '<section class="admin-panel admin-log-results"><div class="admin-panel-head"><div><h2>任务记录</h2><p>'.e('共 '.(int)$listing['total'].' 条 · 每页 50 条').'</p></div></div>';
    if (empty($listing['items'])) $html .= '<div class="admin-module-empty">暂无符合当前条件的任务，可调整筛选或重置后查看。</div>';
    else $html .= admin_subboost_table($listing['items'], array('id'=>'任务 ID','order_no'=>'订单号','order_status_label'=>'订单状态','status_label'=>'任务状态','retry_count'=>'重试次数（非完整尝试次数）','error_code'=>'安全错误码','error_text'=>'失败原因 / 状态说明（查看详情）','suggestion'=>'安全建议','available_at'=>'可执行时间','created_at'=>'创建时间','updated_at'=>'更新时间','completed_at'=>'完成时间'), 'SubBoost任务查询结果', true);
    $html .= admin_subboost_pagination($listing['page'], $listing['pages'], function($page) use ($f) { return admin_subboost_url($f, array('page'=>$page)); });
    return $html.'</section></div>';
}

function admin_subboost_detail($detail)
{
    $task = $detail['task'];
    $html = '<div class="admin-log-page">'.admin_heading('SubBoost任务详情', '当前任务摘要与已记录的逐次执行历史。').'<a class="secondary-button" href="/admin/subboost-tasks">返回任务列表</a>'.admin_subboost_note();
    $html .= '<section class="admin-panel"><h2>任务摘要</h2><div class="table-wrap admin-log-table"><table><caption class="admin-log-caption">任务安全摘要</caption><tbody>';
    $fields = array('id'=>'任务 ID','order_id'=>'订单 ID','order_no'=>'关联订单','order_status_label'=>'订单状态','status_label'=>'任务状态','retry_count'=>'重试次数（非完整尝试次数）','error_code'=>'安全错误码','error_text'=>'失败原因 / 状态说明','suggestion'=>'安全排查建议','available_at'=>'可执行时间','created_at'=>'创建时间','updated_at'=>'更新时间','completed_at'=>'完成时间','opened_at'=>'开通时间','expires_at'=>'到期时间');
    foreach ($fields as $key=>$label) $html .= '<tr><th scope="row">'.e($label).'</th><td>'.admin_subboost_cell($task, $key).'</td></tr>';
    $html .= '</tbody></table></div></section><section class="admin-panel admin-log-results"><div class="admin-panel-head"><div><h2>逐次执行历史</h2><p>'.e('共 '.(int)$detail['history_total'].' 条已记录历史；重试次数不是完整尝试次数。').'</p></div></div>';
    if (empty($detail['history'])) $html .= '<div class="admin-module-empty">暂无已记录的执行历史。启用诊断前的逐次执行历史无法追溯，重试次数不是完整尝试次数。</div>';
    else $html .= admin_subboost_table($detail['history'], array('id'=>'历史 ID','result_label'=>'执行结果','stage_label'=>'执行阶段','error_code'=>'安全错误码','error_text'=>'原因说明','suggestion'=>'安全排查建议','http_status'=>'HTTP 状态（0 表示无响应状态）','transport_errno'=>'传输错误号（0 表示未报告传输错误）','duration_ms'=>'耗时（毫秒）','started_at'=>'开始时间','finished_at'=>'结束时间'), 'SubBoost逐次执行历史');
    $html .= admin_subboost_pagination($detail['history_page'], $detail['history_pages'], function($page) use ($task) { return '/admin/subboost-tasks/'.rawurlencode(admin_subboost_value($task, 'id')).'?history_page='.$page; });
    return $html.'</section></div>';
}
