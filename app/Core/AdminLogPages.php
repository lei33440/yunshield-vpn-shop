<?php

function register_admin_log_routes($router, $pdo, $config)
{
    $router->get('/admin/logs', function() use ($pdo, $config) {
        Security::requireAdmin();
        try {
            $history = (new AdminLogService($pdo))->listing($_GET);
        } catch (InvalidArgumentException $exception) {
            // 保留非法日期区间，不以放宽筛选的方式重查。
            $input = $_GET;
            unset($input['from'], $input['to']);
            $filters = AdminLogService::filters($input);
            foreach (array('from', 'to') as $key) {
                $filters[$key] = isset($_GET[$key]) && is_string($_GET[$key]) ? $_GET[$key] : '';
            }
            $history = array('filters'=>$filters, 'items'=>array(), 'total'=>0, 'pages'=>1, 'page'=>1, 'error'=>$exception->getMessage());
            http_response_code(400);
        }
        page('系统日志', admin_log_body($history), $config);
    });
}

function admin_log_url($filters, $changes = array())
{
    $params = array_intersect_key(array_merge($filters, $changes), array_flip(array('tab','source','from','to','admin_id','status','action','event','order_no','id','page')));
    return '/admin/logs?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function admin_log_select($name, $label, $options, $value)
{
    $html = '<label>'.e($label).'<select name="'.e($name).'"><option value="">全部</option>';
    foreach ($options as $key=>$text) {
        $html .= '<option value="'.e($key).'"'.((string)$value === (string)$key ? ' selected' : '').'>'.e($text).'</option>';
    }
    return $html.'</select></label>';
}

function admin_log_input($name, $label, $value, $type = 'text')
{
    return '<label>'.e($label).'<input type="'.e($type).'" name="'.e($name).'" value="'.e($value).'"'.(in_array($name, array('admin_id','id'), true) ? ' inputmode="numeric" pattern="[0-9]{1,9}"' : '').($name === 'order_no' ? ' maxlength="40"' : '').'></label>';
}

function admin_log_body($history)
{
    $f = array_merge(array('tab'=>'audit','source'=>'queue','from'=>'','to'=>'','admin_id'=>'','status'=>'','action'=>'','event'=>'','order_no'=>'','id'=>'','page'=>1), $history['filters']);
    $tabs = array('audit'=>'操作审计','login'=>'登录日志','delivery'=>'发货日志','email'=>'邮件日志');
    $tab = isset($tabs[$f['tab']]) ? $f['tab'] : 'audit';
    $test = $tab === 'email' && $f['source'] === 'test';
    $body = '<div class="admin-log-page">'.admin_heading('系统日志', '统一查看管理操作、登录、发货与邮件记录；本页仅供查询。');
    $body .= '<nav class="admin-log-tabs" aria-label="日志分类">';
    foreach ($tabs as $key=>$label) {
        // 切换分类只保留公共日期，避免隐藏的跨分类限制。
        $href = admin_log_url(array('tab'=>$key, 'from'=>$f['from'], 'to'=>$f['to']));
        $body .= '<a href="'.e($href).'"'.($tab === $key ? ' class="selected" aria-current="page"' : '').'>'.e($label).'</a>';
    }
    $body .= '</nav>';
    if ($tab === 'email') {
        $body .= '<nav class="admin-log-tabs admin-log-sources" aria-label="邮件日志来源">';
        foreach (array('queue'=>'通知队列','test'=>'SMTP 测试') as $key=>$label) {
            $href = admin_log_url(array('tab'=>'email','source'=>$key,'from'=>$f['from'],'to'=>$f['to']));
            $body .= '<a href="'.e($href).'"'.($f['source'] === $key ? ' class="selected" aria-current="page"' : '').'>'.e($label).'</a>';
        }
        $body .= '</nav>';
    }
    $notes = array(
        'audit'=>'仅展示安全摘要，不展示原始 JSON、密码、密钥或邮件正文。操作审计不等于每项业务的最终执行结果。',
        'login'=>'仅展示启用登录审计后的记录，旧登录未记录，无法据此回溯；未识别账号不代表一个已存在的管理员。',
        'delivery'=>'展示发货、更正发货和退回待发货事件及状态变化，不展示交付凭据或订阅地址。',
        'email'=>$test ? 'SMTP 测试记录与通知队列分开展示；SMTP 调用成功仅表示服务器接受，不保证收信。历史成功记录缺少完整结果信息；结果未确认不代表失败，请勿据此重复发送。' : '这里展示通知队列当前状态，并非逐次发送尝试历史。重试次数不是完整尝试明细；SMTP 已接受不保证收信。失败 / 已跳过也可能是链接失效等业务条件导致，不一定是 SMTP 故障。本页不会启动发送任务。'
    );
    $body .= '<div class="admin-info-banner"><p>'.e($notes[$tab]).'</p></div>';
    $body .= '<section class="admin-panel"><h2>筛选记录</h2><form class="admin-log-filter" method="get" action="/admin/logs"><input type="hidden" name="tab" value="'.e($tab).'">';
    if ($tab === 'email') $body .= '<input type="hidden" name="source" value="'.e($f['source']).'">';
    $body .= admin_log_input('from','开始日期（含）',$f['from'],'date').admin_log_input('to','结束日期（含）',$f['to'],'date');
    if ($tab !== 'email' || $test) $body .= admin_log_input('admin_id','管理员 ID（精确）',$f['admin_id']);
    if ($tab === 'audit') $body .= admin_log_select('action','操作类型',AdminLogService::actions(),$f['action']);
    if ($tab === 'login') $body .= admin_log_select('status','登录结果',array('success'=>'成功','failed'=>'失败'),$f['status']);
    if ($tab === 'delivery') {
        $events = array_intersect_key(AdminLogService::events(), array_flip(array('delivered','delivery_updated','delivery_reverted')));
        $body .= admin_log_select('event','发货事件',$events,$f['event']).admin_log_input('order_no','订单号（精确）',$f['order_no']);
    }
    if ($tab === 'email') {
        $body .= admin_log_select('status',$test ? '测试结果' : '队列状态',$test ? AdminLogService::results() : AdminLogService::statuses(),$f['status']);
        if (!$test) $body .= admin_log_select('event','邮件事件',AdminLogService::events(),$f['event']).admin_log_input('id','队列 ID（精确）',$f['id']);
    }
    $reset = admin_log_url(array('tab'=>$tab,'source'=>$f['source']));
    $body .= '<div class="admin-log-filter-actions"><button class="button" type="submit">查询</button><a class="secondary-button" href="'.e($reset).'">重置筛选</a></div></form></section>';
    if (!empty($history['error'])) {
        return $body.'<div class="admin-info-banner admin-log-error" role="alert"><strong>筛选条件无效</strong><p>'.e($history['error']).'</p><p>未查询任何记录。请修改日期或使用“重置筛选”。</p></div></div>';
    }
    $columns = array('id'=>'记录 ID','time'=>'记录时间','admin'=>'管理员','action'=>'操作','target'=>'操作对象','summary'=>'安全摘要');
    if ($tab === 'login') $columns = array('id'=>'记录 ID','time'=>'登录时间','admin'=>'管理员','result'=>'结果','ip'=>'来源 IP','reason'=>'结果说明');
    if ($tab === 'delivery') $columns = array('id'=>'事件 ID','time'=>'事件时间','admin'=>'操作者','order_no'=>'订单号','event'=>'发货事件','change'=>'状态变化');
    if ($tab === 'email') {
        $columns = $test
            ? array('id'=>'记录 ID','time'=>'开始时间','admin'=>'管理员','recipient'=>'收件人（脱敏）','result'=>'测试结果','finished'=>'完成时间','reason'=>'结果说明')
            : array('id'=>'队列 ID','time'=>'创建时间','event'=>'邮件事件','recipient'=>'收件人（脱敏）','result'=>'当前状态','retries'=>'重试次数','available'=>'可重试时间','locked'=>'锁定时间','sent'=>'SMTP 接受时间','updated'=>'更新时间','reason'=>'结果说明');
    }
    $total = max(0, (int)$history['total']);
    $pages = max(1, (int)$history['pages']);
    $page = max(1, min($pages, (int)$history['page']));
    $body .= '<section class="admin-panel admin-log-results"><div class="admin-panel-head"><div><h2>'.e($tabs[$tab]).'</h2><p>'.e('共 '.$total.' 条 · 每页 50 条 · 第 '.$page.' / '.$pages.' 页').'</p></div></div>';
    if (empty($history['items'])) {
        $body .= '<div class="admin-module-empty">'.e('暂无符合当前条件的'.$tabs[$tab].'，可调整筛选或重置后查看。').'</div>';
    } else {
        $body .= '<div class="table-wrap admin-log-table" role="region" aria-label="'.e($tabs[$tab].'记录，可横向滚动').'" tabindex="0"><table><caption class="admin-log-caption">'.e($tabs[$tab].'查询结果').'</caption><thead><tr>';
        foreach ($columns as $label) $body .= '<th scope="col">'.e($label).'</th>';
        $body .= '</tr></thead><tbody>';
        foreach ($history['items'] as $item) {
            $body .= '<tr>';
            // 仅展示明确列出的安全字段，绝不输出原始详情或邮件载荷。
            foreach ($columns as $key=>$label) {
                $value = isset($item[$key]) && is_scalar($item[$key]) && (string)$item[$key] !== '' ? (string)$item[$key] : '—';
                if($key==='result'){
                    $colors=array('success'=>'delivered','accepted'=>'delivered','sent'=>'delivered','failed'=>'cancelled','started'=>'pending_payment','legacy'=>'pending_payment','pending'=>'pending_payment','processing'=>'pending_delivery','retry'=>'pending_delivery');
                    $color=isset($item['status'],$colors[$item['status']])?$colors[$item['status']]:'pending_payment';
                    $body.='<td><span class="admin-status '.$color.'">'.e($value).'</span></td>';
                }else $body .= '<td>'.e($value).'</td>';
            }
            $body .= '</tr>';
        }
        $body .= '</tbody></table></div>';
    }
    $body .= '<nav class="admin-log-pagination" aria-label="日志分页">';
    if ($page > 1) {
        $body .= '<a class="secondary-button" href="'.e(admin_log_url($f,array('page'=>1))).'">首页</a><a class="secondary-button" rel="prev" href="'.e(admin_log_url($f,array('page'=>$page-1))).'">上一页</a>';
    }
    $body .= '<span aria-current="page">'.e('第 '.$page.' / '.$pages.' 页').'</span>';
    if ($page < $pages) {
        $body .= '<a class="secondary-button" rel="next" href="'.e(admin_log_url($f,array('page'=>$page+1))).'">下一页</a><a class="secondary-button" href="'.e(admin_log_url($f,array('page'=>$pages))).'">末页</a>';
    }
    return $body.'</nav></section></div>';
}
