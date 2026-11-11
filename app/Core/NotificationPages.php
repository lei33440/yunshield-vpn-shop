<?php
function notification_page_icon($name){
    $paths=array('orders'=>'<rect x="5" y="4" width="14" height="18" rx="2"/><rect x="9" y="2" width="6" height="4" rx="1"/><path d="M9 11h6M9 16h6"/>','tickets'=>'<path d="M4 13v-2a8 8 0 0 1 16 0v2M20 17c0 4-3 4-6 4"/><rect x="2" y="11" width="4" height="7" rx="2"/><rect x="18" y="11" width="4" height="7" rx="2"/>','wallet'=>'<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 4 16 4 16 0V5M4 10c0 4 16 4 16 0M4 15c0 4 16 4 16 0"/>','gift'=>'<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M5 12v9h14v-9M12 8v13M12 8C2 7 7-2 12 8c5-10 10-1 0 0Z"/>','help'=>'<path d="m15 4 5 5M10 19H4V4h8M20 13v7H4M9 15l1-5L18 2l4 4-8 8Z"/>','check'=>'<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>');
    return isset($paths[$name])?'<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">'.$paths[$name].'</svg>':user_nav_icon('bell');
}
function notification_page_types(){
    return array('renewal_created'=>'续费订单','renewal_paid'=>'续费处理中','renewal_completed'=>'续费成功','order_paid'=>'付款通知','payment_confirmed'=>'付款确认','subscription_expiring'=>'续费提醒','order_delivered'=>'交付通知','delivered'=>'正式交付','delivery_updated'=>'交付更新','delivery_reverted'=>'交付处理中','cancelled'=>'订单取消','refund_requested'=>'退款申请','refund_processed'=>'退款处理','refund_rejected'=>'退款未通过','temporary_subscription_revoked'=>'临时订阅失效','temporary_subscription_issued'=>'临时订阅','order_refunded'=>'退款通知','group_released'=>'拼团成功','ticket_update'=>'工单更新','ticket_reply'=>'工单回复','ticket_closed'=>'工单关闭','feedback_review'=>'反馈审核','feedback_progress'=>'反馈进度','lottery'=>'抽奖通知','referral_commission'=>'邀请返佣','referral_reversal'=>'返佣撤回','leader_reward'=>'团长奖励','withdrawal_approved'=>'提现审核通过','withdrawal_rejected'=>'提现驳回','withdrawal_paid'=>'提现完成','system'=>'系统消息');
}
function notification_page_filters($input){
    $types=notification_page_types();$values=array('read'=>'','type'=>'','range'=>'30','page'=>'1');$valid=true;
    foreach($values as $key=>$default){
        if(!isset($input[$key]))continue;
        $value=$input[$key];$ok=is_string($value);
        if($ok){
            if($key==='read')$ok=in_array($value,array('','unread','read'),true);
            elseif($key==='type')$ok=$value===''||isset($types[$value]);
            elseif($key==='range')$ok=in_array($value,array('30','90','all'),true);
            else $ok=preg_match('/^[1-9][0-9]{0,8}$/D',$value)===1;
        }
        if($ok)$values[$key]=$value;else $valid=false;
    }
    return array($values,$valid);
}
function notification_page_url($filters){
    return '/notifications?'.http_build_query($filters,'','&',PHP_QUERY_RFC3986);
}
function account_notifications_page($pdo,$uid){
    list($filters,$valid)=notification_page_filters($_GET);
    if(!$valid){http_response_code(400);return '<section class="mn-empty"><h1>消息筛选参数无效</h1><p>请选择有效的消息状态、类型和时间范围。</p><a href="/notifications">返回消息中心</a></section>';}
    $types=notification_page_types();$svc=new NotificationService($pdo);$now=time();
    $global=$pdo->prepare('SELECT COUNT(*) total,COALESCE(SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END),0) unread FROM notifications WHERE user_id=?');$global->execute(array($uid));$totals=$global->fetch();
    $where='user_id=?';$args=array($uid);
    if($filters['type']!==''){$where.=' AND type=?';$args[]=$filters['type'];}
    if($filters['range']!=='all'){$where.=' AND created_at>=? AND created_at<=?';$args[]=date('Y-m-d H:i:s',$now-(int)$filters['range']*86400);$args[]=date('Y-m-d H:i:s',$now);}
    $count=$pdo->prepare('SELECT COUNT(*) total,COALESCE(SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END),0) unread FROM notifications WHERE '.$where);$count->execute($args);$counts=$count->fetch();
    if($filters['read']!=='')$where.=$filters['read']==='unread'?' AND read_at IS NULL':' AND read_at IS NOT NULL';
    $total=$filters['read']==='unread'?(int)$counts['unread']:($filters['read']==='read'?(int)$counts['total']-(int)$counts['unread']:(int)$counts['total']);
    $pages=max(1,(int)ceil($total/20));$current=min((int)$filters['page'],$pages);$filters['page']=(string)$current;
    $query=$pdo->prepare('SELECT id,user_id,type,title,content,order_no,target_type,target_id,read_at,created_at FROM notifications WHERE '.$where.' ORDER BY created_at DESC,id DESC LIMIT 20 OFFSET '.(($current-1)*20));$query->execute($args);$items=$query->fetchAll();
    $fields='';foreach($filters as $key=>$value)$fields.='<input type="hidden" name="'.$key.'" value="'.e($value).'">';
    $tabs='';foreach(array(''=>'全部','unread'=>'未读','read'=>'已读') as $key=>$label){$f=$filters;$f['read']=$key;$f['page']='1';$n=$key==='unread'?(int)$counts['unread']:($key==='read'?(int)$counts['total']-(int)$counts['unread']:(int)$counts['total']);$tabs.='<a href="'.e(notification_page_url($f)).'"'.($filters['read']===$key?' aria-current="page"':'').'>'.$label.' ('.$n.')</a>';}
    $options='<option value="">类型：全部</option>';foreach($types as $key=>$label)$options.='<option value="'.$key.'"'.($filters['type']===$key?' selected':'').'>'.e($label).'</option>';
    $ranges='';foreach(array('30'=>'最近30天','90'=>'最近90天','all'=>'全部时间') as $key=>$label)$ranges.='<option value="'.$key.'"'.($filters['range']===(string)$key?' selected':'').'>'.$label.'</option>';
    $rows='';foreach($items as $n){
        $type=$n['type'];$unread=$n['read_at']===null;$path=$svc->targetPath($n);$label=isset($types[$type])?$types[$type]:'账户消息';$icon='bell';$action='查看详情';
        if(strpos($type,'ticket_')===0){$icon='tickets';$action='查看工单';}
        elseif(strpos($type,'feedback_')===0){$icon='help';$action='查看反馈';}
        elseif(strpos($type,'referral_')===0||strpos($type,'withdrawal_')===0||$type==='order_refunded')$icon='wallet';
        elseif($type==='lottery'||$type==='group_released'||$type==='leader_reward')$icon='gift';
        elseif(strpos($type,'order_')===0||strpos($type,'renewal_')===0||in_array($type,array('payment_confirmed','temporary_subscription_issued','temporary_subscription_revoked','delivered','delivery_updated','delivery_reverted','cancelled','refund_requested','refund_processed','refund_rejected','subscription_expiring'),true)){$icon='orders';$action='查看订单';}
        // 关联目标仍使用现有受鉴权保护的详情入口，不在列表公开关联订单号。
        $rows.='<article id="message-'.(int)$n['id'].'" class="mn-message'.($unread?' is-unread':'').'"><div class="mn-symbol" aria-hidden="true"><i></i>'.notification_page_icon($icon).'</div><div class="mn-copy"><h2>'.e($n['title']).'</h2><p>'.nl2br(e($n['content'])).'</p></div><div class="mn-meta"><b>'.e($label).'</b><time datetime="'.e(str_replace(' ','T',$n['created_at'])).'">'.e(substr($n['created_at'],0,16)).'</time><span class="mn-sr-only">'.($unread?'未读':'已读').'</span></div><div class="mn-actions">'.($path?'<a class="'.($unread?'ao-button'.(in_array($type,array('order_delivered','delivered'),true)?' ao-primary':''):'mn-detail-link').'" href="'.e($path).'">'.$action.' ↗</a>':'<span class="mn-no-target">站内提醒</span>').($unread?'<form method="post" action="/notifications/'.(int)$n['id'].'/read">'.csrf_field().$fields.'<button type="submit" aria-label="将消息 '.(int)$n['id'].' 标为已读">标为已读</button></form>':'').'</div></article>';
    }
    $pagination='<span>共 '.$total.' 条 · 第 '.$current.' / '.$pages.' 页</span>';
    foreach(array(-1=>'上一页',1=>'下一页') as $step=>$text){$f=$filters;$f['page']=(string)($current+$step);$enabled=$step<0?$current>1:$current<$pages;$link=$enabled?'<a rel="'.($step<0?'prev':'next').'" href="'.e(notification_page_url($f)).'">'.$text.'</a>':'<span aria-disabled="true">'.$text.'</span>';if($step<0)$pagination.=$link.'<b aria-current="page">'.$current.'</b>';else $pagination.=$link;}
    $empty='<div class="mn-empty"><h2>暂无匹配的站内消息</h2><p>订单进度、服务回复与账户提醒将在这里展示。</p><a href="/notifications?range=all">查看全部时间的消息</a></div>';
    return '<div class="mn-page"><header class="ao-welcome mn-welcome"><div><p class="ao-kicker">MESSAGE CENTER</p><h1>消息中心</h1><p>订单进度、服务回复与账户提醒，在这里及时查看。</p><p class="mn-total">共 '.(int)$totals['total'].' 条站内消息 · '.(int)$totals['unread'].' 条未读</p></div><form class="mn-read-all" method="post" action="/notifications/read-all">'.csrf_field().$fields.'<button class="ao-button" type="submit"'.(!(int)$totals['unread']?' disabled':'').'>'.notification_page_icon('check').'全部标为已读</button></form></header><div class="mn-toolbar"><nav class="mn-tabs" aria-label="已读状态">'.$tabs.'</nav><form class="mn-filters" method="get" action="/notifications"><input type="hidden" name="read" value="'.e($filters['read']).'"><label><span class="mn-sr-only">消息类型</span><select name="type" aria-label="消息类型">'.$options.'</select></label><label><span class="mn-sr-only">时间范围</span><select name="range" aria-label="时间范围">'.$ranges.'</select></label><button type="submit">筛选</button></form></div><p class="mn-scope">这里只展示站内消息，公告与邮件请分别查看。状态标签计数随类型与时间筛选变化。</p><section class="mn-list" aria-label="站内消息">'.($rows?:$empty).'</section><nav class="mn-pagination" aria-label="消息分页">'.$pagination.'</nav><div class="mn-bottom-grid"><section><h2>消息类型说明</h2><p>订单通知：付款确认、订阅交付与订单状态更新。<br>工单回复：售后工单的处理与回复消息。<br>反馈通知：建议审核与后续处理进展。<br>活动与账户提醒：拼团、邀请奖励及其他站内提示。</p></section><section><h2>没有找到需要的消息？</h2><p>可前往订单或工单查看最新进度。</p><nav aria-label="消息相关帮助"><a class="ao-button" href="/orders">我的订单 ↗</a><a class="ao-button" href="/tickets">我的工单 ↗</a></nav></section></div><p class="mn-help">更多帮助：<a href="/announcements">查看站点公告 ↗</a><a href="/tickets#new-ticket">提交问题工单 ↗</a></p></div>';
}
function register_notification_routes($router,$pdo,$config){
    $router->get('/notifications',function()use($pdo,$config){$uid=Security::requireUser();page('消息中心',account_notifications_page($pdo,$uid),$config);});
    $redirectBack=function(){list($filters)=notification_page_filters($_POST);$path='/notifications?read='.rawurlencode($filters['read']).'&type='.rawurlencode($filters['type']).'&page='.(int)$filters['page'];if(isset($_POST['range']))$path.='&range='.rawurlencode($filters['range']);redirect($path);};
    $router->post('/notifications/{id}/read',function($id)use($pdo,$redirectBack){$uid=Security::requireUser();post_csrf();(new NotificationService($pdo))->markRead($uid,(int)$id);$redirectBack();});
    $router->post('/notifications/read-all',function()use($pdo,$redirectBack){$uid=Security::requireUser();post_csrf();(new NotificationService($pdo))->markAllRead($uid);$redirectBack();});
}
