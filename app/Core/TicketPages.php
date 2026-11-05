<?php
function register_ticket_routes($router, $pdo, $config)
{
    $labels = array('open'=>'待客服回复', 'waiting_customer'=>'等待用户回复', 'closed'=>'已关闭');
    $scalar = function($source, $key, $default='') { return isset($source[$key]) && is_string($source[$key]) ? $source[$key] : $default; };
    $pagination = function($path, $current, $pages, $parameter, $filters=array()) {
        $html='<nav class="ticket-pagination'.(strpos($path,'/admin/')===0?'':' service-ui-pagination').'"'.(strpos($path,'/admin/')===0?'':' aria-label="工单分页"').'>第 '.(int)$current.' / '.(int)$pages.' 页';
        foreach(array(-1=>'上一页',1=>'下一页') as $step=>$label) {
            $next=$current+$step;
            if($next<1 || $next>$pages)continue;
            $query=$filters;$query[$parameter]=$next;
            $html.=' <a href="'.e($path.'?'.http_build_query($query)).'">'.$label.'</a>';
        }
        return $html.'</nav>';
    };
    $list = function($admin) use($pdo,$config,$labels,$scalar,$pagination) {
        $actor=$admin?Security::requireAdmin():Security::requireUser();
        $status=$scalar($_GET,'status');if(!isset($labels[$status]))$status='';
        $path=$admin?'/admin/tickets':'/tickets';
        $service=new TicketService($pdo);
        $data=$service->listing($admin?null:$actor,array('status'=>$status),max(1,(int)$scalar($_GET,'page','1')),20);
        if($admin) {
            $body='<div class="tickets-page"><h1>售后工单</h1><form method="get"><label>状态<select name="status"><option value="">全部状态</option>';
            foreach($labels as $value=>$label)$body.='<option value="'.$value.'"'.($status===$value?' selected':'').'>'.$label.'</option>';
            $body.='</select></label><button class="secondary-button">筛选</button></form>';
            $body.='<section class="table-wrap"><table><thead><tr><th>编号</th><th>用户</th><th>主题</th><th>状态</th><th>更新时间</th></tr></thead><tbody>';
            foreach($data['items'] as $ticket) {
                $body.='<tr><td><a href="'.$path.'/'.(int)$ticket['id'].'">'.e($ticket['ticket_no']).'</a></td><td>#'.(int)$ticket['user_id'].'</td><td>'.e($ticket['subject']).'</td><td>'.e(isset($labels[$ticket['status']])?$labels[$ticket['status']]:$ticket['status']).'</td><td>'.e($ticket['updated_at']).'</td></tr>';
            }
            if(!$data['items'])$body.='<tr><td colspan="5">暂无工单。</td></tr>';
            $body.='</tbody></table></section>'.$pagination($path,$data['page'],$data['pages'],'page',array('status'=>$status)).'</div>';
            return admin_page('售后工单',$body,'','/admin/tickets',$pdo,$config);
        }
        $draft=isset($_SESSION['_ticket_create'][$actor])?$_SESSION['_ticket_create'][$actor]:array();
        $orderId=isset($draft['order_id'])?$draft['order_id']:$scalar($_GET,'order_id');
        $orders=$pdo->prepare('SELECT id,order_no,product_name_snapshot FROM orders WHERE user_id=? ORDER BY id DESC LIMIT 50');$orders->execute(array($actor));$options='<option value="">不关联订单</option>';$found=false;
        foreach($orders as $order) {
            $selected=(string)$order['id']===$orderId;$found=$found||$selected;
            $options.='<option value="'.(int)$order['id'].'"'.($selected?' selected':'').'>'.e($order['order_no'].' / '.$order['product_name_snapshot']).'</option>';
        }
        // 从旧订单详情带入时仍按本人归属读取，不受最近五十条列表限制。
        if(!$found && preg_match('/^[1-9][0-9]{0,17}$/D',$orderId)) {
            $query=$pdo->prepare('SELECT id,order_no,product_name_snapshot FROM orders WHERE id=? AND user_id=?');$query->execute(array($orderId,$actor));$order=$query->fetch(PDO::FETCH_ASSOC);
            if($order)$options.='<option value="'.(int)$order['id'].'" selected>'.e($order['order_no'].' / '.$order['product_name_snapshot']).'</option>';
        }
        $counts=$service->statusCounts($actor);
        $tabs='<nav class="pc-ticket-tabs" aria-label="工单状态筛选">';
        foreach(array(''=>'全部')+$labels as $value=>$label) {
            $href='/tickets'.($value===''?'':'?'.http_build_query(array('status'=>$value)));
            $tabs.='<a href="'.e($href).'"'.($status===$value?' class="is-active" aria-current="page"':'').'>'.e($label).' <span>('.(int)($value===''?$counts['all']:$counts[$value]).')</span></a>';
        }
        $tabs.='</nav>';
        $rows='';
        foreach($data['items'] as $ticket) {
            $orderCell=$ticket['order_no']?'<a href="/orders/'.rawurlencode($ticket['order_no']).'" aria-label="'.e('查看订单 '.$ticket['order_no']).'">'.e($ticket['order_no']).'</a>':'<span class="pc-muted">未关联</span>';
            $rows.='<tr><td data-label="编号"><a class="pc-ticket-no" href="/tickets/'.(int)$ticket['id'].'">'.e($ticket['ticket_no']).'</a></td><td data-label="提交时间">'.e($ticket['created_at']).'</td><td data-label="主题"><a href="/tickets/'.(int)$ticket['id'].'">'.e($ticket['subject']).'</a></td><td data-label="关联订单">'.$orderCell.'</td><td data-label="状态"><span class="ticket-status-badge ticket-status-'.e(isset($labels[$ticket['status']])?$ticket['status']:'unknown').'">'.e(isset($labels[$ticket['status']])?$labels[$ticket['status']]:$ticket['status']).'</span></td><td data-label="更新时间">'.e($ticket['updated_at']).'</td><td data-label="操作"><a class="ticket-view-link" href="/tickets/'.(int)$ticket['id'].'" aria-label="'.e('查看工单 '.$ticket['ticket_no']).'">查看 →</a></td></tr>';
        }
        if(!$data['items'])$rows.='<tr><td colspan="7" class="ticket-empty-cell" data-label="工单列表"><div class="empty service-ui-empty"><h3>'.($status!==''?'该状态下暂无工单':'暂无售后工单').'</h3><p>'.($status!==''?'可以查看全部工单，或新建工单说明问题。':'遇到使用或订单问题时，可以新建工单并在此查看回复。').'</p><div class="product-form-actions">'.($status!==''?'<a class="secondary-button" href="/tickets">查看全部工单</a>':'<a class="button" href="#new-ticket">新建工单</a><a class="secondary-button" href="/orders">查看我的订单</a>').'</div></div></td></tr>';
        $createForm='<aside class="pc-ticket-create" id="new-ticket" aria-labelledby="ticket-create-title"><h2 id="ticket-create-title">新建工单</h2><p class="pc-muted">请说明遇到的具体问题，提交后可在左侧记录中查看处理进度。</p><form method="post" action="/tickets" data-submit-lock>'.csrf_field().'<input type="hidden" name="request_key" value="'.bin2hex(random_bytes(24)).'"><label>主题<input name="subject" maxlength="200" required value="'.e(isset($draft['subject'])?$draft['subject']:'').'"></label><label for="ticket-order-id">关联本人订单（可选）</label><select id="ticket-order-id" name="order_id" aria-describedby="ticket-order-help">'.$options.'</select><p class="pc-field-help" id="ticket-order-help">订单问题请选择对应订单；其他问题可保留“不关联订单”。下拉列表展示最近 50 条本人订单，更早的订单可从订单详情进入工单。</p><label for="ticket-create-body">问题描述</label><p class="pc-field-help" id="ticket-create-help">请说明遇到的问题、发生时间及已尝试的操作，最多 5000 字。请勿填写密码或支付凭据。</p><textarea id="ticket-create-body" name="body" maxlength="5000" required aria-describedby="ticket-create-help">'.e(isset($draft['body'])?$draft['body']:'').'</textarea><button class="button" type="submit" data-idle-label="提交工单">提交工单</button><p class="pc-submit-hint" hidden>正在提交，请勿重复点击。</p></form></aside>';
        $body='<div class="tickets-page pc-tickets-page"><header class="ux-page-head"><div><h1>我的工单</h1><p class="pc-muted">查看售后进度或提交问题，订单相关问题可在新建工单时关联本人订单。</p></div><nav class="product-form-actions" aria-label="工单导航"><a href="#new-ticket">新建工单</a><a href="/orders">我的订单</a><a href="/">返回商城</a></nav></header>'.$tabs.'<div class="pc-ticket-layout"><section class="pc-ticket-list" aria-labelledby="ticket-list-title"><div class="pc-section-head"><div><h2 id="ticket-list-title">工单记录</h2><p class="pc-muted">选择工单查看客服回复，也可按状态筛选处理进度。</p></div><span class="pc-count">共 '.(int)$data['total'].' 条</span></div><div class="table-wrap"><table><thead><tr><th>编号</th><th>提交时间</th><th>主题</th><th>关联订单</th><th>状态</th><th>更新时间</th><th>操作</th></tr></thead><tbody>'.$rows.'</tbody></table></div>'.$pagination($path,$data['page'],$data['pages'],'page',array('status'=>$status)).'</section>'.$createForm.'</div></div>';
        return page('售后工单',$body,$config);
    };
    $detail = function($id,$admin) use($pdo,$config,$labels,$scalar,$pagination) {
        $actor=$admin?Security::requireAdmin():Security::requireUser();$path=($admin?'/admin/tickets/':'/tickets/').(int)$id;
        try{if(!is_string($id)||!preg_match('/^[1-9][0-9]*$/D',$id)||strlen($id)>strlen((string)PHP_INT_MAX)||(strlen($id)===strlen((string)PHP_INT_MAX)&&strcmp($id,(string)PHP_INT_MAX)>0))throw new RuntimeException('工单不存在。');$ticket=(new TicketService($pdo))->detail($id,$admin?null:$actor,max(1,(int)$scalar($_GET,'message_page','1')),20);}
        catch(PDOException $error){throw $error;}
        catch(RuntimeException $error){http_response_code(404);$body=$admin?'<p class="empty">工单不存在或无权查看。</p>':'<section class="empty service-ui-empty"><h1>无法查看此工单</h1><p>工单不存在或无权查看，请返回列表查看本人的工单。</p><a class="button" href="/tickets">返回工单列表</a></section>';return $admin?admin_page('工单不存在',$body,'','/admin/tickets',$pdo,$config):page('工单不存在',$body,$config);}
        $scope=($admin?'admin:':'user:').$actor.':'.(int)$ticket['id'];
        if($admin)$draftScope='admin:'.$actor.':'.(int)$ticket['id']; else $draftScope=$scope;
        $draft=isset($_SESSION['_ticket_reply'][$draftScope])?$_SESSION['_ticket_reply'][$draftScope]:'';
        $statusLabel=isset($labels[$ticket['status']])?$labels[$ticket['status']]:$ticket['status'];
        if($admin) {
            $body='<div class="ticket-detail"><a href="/admin/tickets">返回工单列表</a><h1>'.e($ticket['subject']).'</h1><p>状态：'.e($statusLabel).'</p>';
            if($ticket['order_no'])$body.='<p>关联订单：<a href="/admin/orders/'.rawurlencode($ticket['order_no']).'">'.e($ticket['order_no']).'</a> · '.e($ticket['product_name_snapshot']).'</p>';
            foreach($ticket['messages'] as $message)$body.='<article><b>'.($message['sender_type']==='admin'?'客服':'用户').'</b><time>'.e($message['created_at']).'</time><p>'.nl2br(e($message['body'])).'</p></article>';
            $body.=$pagination($path,$ticket['message_page'],$ticket['message_pages'],'message_page');
            if($ticket['status']==='closed')$body.='<p class="empty">工单已关闭，不能继续回复。</p>';
            else {
                $body.='<form method="post" action="'.$path.'/reply">'.csrf_field().'<input type="hidden" name="version_no" value="'.(int)$ticket['version_no'].'"><input type="hidden" name="request_key" value="'.bin2hex(random_bytes(24)).'"><label>回复内容<textarea name="body" maxlength="5000" required>'.e($draft).'</textarea></label><button class="button">回复</button></form>';
                $body.='<form method="post" action="'.$path.'/close">'.csrf_field().'<input type="hidden" name="version_no" value="'.(int)$ticket['version_no'].'"><input type="hidden" name="request_key" value="'.bin2hex(random_bytes(24)).'"><button class="secondary-button">关闭工单</button></form>';
            }
            $body.='</div>';
            return admin_page('处理工单',$body,'','/admin/tickets',$pdo,$config);
        }
        $statusLabel=isset($labels[$ticket['status']])?$labels[$ticket['status']]:$ticket['status'];
        $amount=isset($ticket['total_amount_cents'])&&$ticket['total_amount_cents']!==null?'¥'.number_format(((int)$ticket['total_amount_cents'])/100,2):'';
        $thread='';
        if(!$ticket['messages'])$thread='<div class="empty service-ui-empty"><h3>当前页暂无沟通记录</h3><p>可通过下方分页查看其他页；如需补充问题，请在工单开放时使用回复框。</p><a href="/tickets">返回工单列表</a></div>';
        foreach($ticket['messages'] as $message) {
            $isStaff=$message['sender_type']==='admin';
            $thread.='<article class="pc-bubble '.($isStaff?'is-staff':'is-user').'"><span class="pc-bubble-avatar" aria-hidden="true">'.($isStaff?'客':'我').'</span><div class="pc-bubble-body"><header><b>'.($isStaff?'客服':'我').'</b><time>'.e($message['created_at']).'</time></header><p>'.nl2br(e($message['body'])).'</p></div></article>';
        }
        $thread.=$pagination($path,$ticket['message_page'],$ticket['message_pages'],'message_page');
        if($ticket['status']==='closed')$thread.='<section class="pc-ticket-closed" aria-label="工单已关闭"><h2>工单已关闭</h2><p>历史沟通记录仍可查看，此工单不能继续回复。如有其他问题，可另建工单。</p><div class="product-form-actions"><a href="/tickets">返回工单列表</a><a class="secondary-button" href="/tickets#new-ticket">新建工单</a></div></section>';
        else $thread.='<form class="pc-reply-form" method="post" action="'.$path.'/reply" data-submit-lock>'.csrf_field().'<input type="hidden" name="version_no" value="'.(int)$ticket['version_no'].'"><input type="hidden" name="request_key" value="'.bin2hex(random_bytes(24)).'"><p class="pc-field-help" id="ticket-reply-help">补充问题或回复客服，最多 5000 字。请勿填写密码或支付凭据。</p><label for="ticket-reply-body">回复内容</label><textarea id="ticket-reply-body" name="body" maxlength="5000" required aria-describedby="ticket-reply-help">'.e($draft).'</textarea><button class="button" type="submit" data-idle-label="回复">回复</button><p class="pc-submit-hint" hidden>正在提交，请勿重复点击。</p></form>';
        $orderCard='<section class="pc-side-card" aria-labelledby="ticket-order-title"><h2 id="ticket-order-title">关联订单</h2>';
        if($ticket['order_no'])$orderCard.='<a class="pc-order-card" href="/orders/'.rawurlencode($ticket['order_no']).'"><span class="pc-order-card-no">'.e($ticket['order_no']).'</span><span class="pc-order-card-name">'.e($ticket['product_name_snapshot']).'</span>'.($amount!==''?'<span class="pc-order-card-amount">'.$amount.'</span>':'').'<span class="pc-order-card-link">查看订单 →</span></a>';
        else $orderCard.='<div class="pc-side-empty"><p>此工单未关联订单。</p><a href="/orders">查看我的订单 →</a></div>';
        $orderCard.='</section>';
        $info='<section class="pc-side-card" aria-labelledby="ticket-info-title"><h2 id="ticket-info-title">工单信息</h2><dl class="pc-info-list"><div><dt>工单编号</dt><dd>'.e(isset($ticket['ticket_no'])?$ticket['ticket_no']:'').'</dd></div><div><dt>当前状态</dt><dd><span class="ticket-status-badge ticket-status-'.e(isset($labels[$ticket['status']])?$ticket['status']:'unknown').'">'.e($statusLabel).'</span></dd></div><div><dt>提交时间</dt><dd>'.e(isset($ticket['created_at'])?$ticket['created_at']:'').'</dd></div><div><dt>更新时间</dt><dd>'.e(isset($ticket['updated_at'])?$ticket['updated_at']:'').'</dd></div>'.(!empty($ticket['closed_at'])?'<div><dt>关闭时间</dt><dd>'.e($ticket['closed_at']).'</dd></div>':'').'</dl><p class="pc-info-note">工单内容仅本人与客服可见。</p></section>';
        $body='<div class="ticket-detail pc-ticket-detail"><header class="ux-page-head"><div><p class="pc-eyebrow">工单 '.e(isset($ticket['ticket_no'])?$ticket['ticket_no']:'').'</p><h1>'.e($ticket['subject']).'</h1><p>状态：<span class="ticket-status-badge ticket-status-'.e(isset($labels[$ticket['status']])?$ticket['status']:'unknown').'">'.e($statusLabel).'</span></p></div><nav class="product-form-actions" aria-label="工单导航"><a href="/tickets">返回工单列表</a><a href="/tickets#new-ticket">新建工单</a><a href="/orders">我的订单</a></nav></header><div class="pc-ticket-detail-layout"><section class="pc-ticket-thread" aria-label="工单沟通记录"><div class="pc-section-head"><div><h2>沟通记录</h2><p class="pc-muted">'.(isset($ticket['message_total'])?'共 '.(int)$ticket['message_total'].' 条':'按时间顺序展示').'</p></div></div>'.$thread.'</section><aside class="pc-ticket-side">'.$orderCard.$info.'</aside></div></div>';
        return page('工单详情',$body,$config);
    };
    $router->get('/tickets',function()use($list){return $list(false);});
    $router->get('/admin/tickets',function()use($list){return $list(true);});
    $router->get('/tickets/{id}',function($id)use($detail){return $detail($id,false);});
    $router->get('/admin/tickets/{id}',function($id)use($detail){return $detail($id,true);});
    $router->post('/tickets',function()use($pdo,$scalar){
        $uid=Security::requireUser();post_csrf();
        try {
            $result=(new TicketService($pdo))->create($uid,isset($_POST['subject'])?$_POST['subject']:'',isset($_POST['body'])?$_POST['body']:'',isset($_POST['order_id'])&&$_POST['order_id']!==''?$_POST['order_id']:null,$scalar($_POST,'request_key'));
            unset($_SESSION['_ticket_create'][$uid]);flash('success','工单已提交。');redirect('/tickets/'.(int)$result['id']);
        } catch(PDOException $error){throw $error;}
        catch(Exception $error){
            $_SESSION['_ticket_create'][$uid]=array('subject'=>$scalar($_POST,'subject'),'body'=>$scalar($_POST,'body'),'order_id'=>$scalar($_POST,'order_id'));
            flash('error',$error->getMessage());redirect('/tickets#new-ticket');
        }
    });
    $change=function($id,$admin,$close=false)use($pdo,$scalar){
        $actor=$admin?Security::requireAdmin():Security::requireUser();post_csrf();$scope=($admin?'admin:':'user:').$actor.':'.(int)$id;$draftScope=$scope;$path=($admin?'/admin/tickets/':'/tickets/').(int)$id;
        try {
            $version=$scalar($_POST,'version_no');if(!preg_match('/^[1-9][0-9]{0,9}$/D',$version))throw new InvalidArgumentException('版本号无效，请刷新后重试。');
            $service=new TicketService($pdo);$key=$scalar($_POST,'request_key');
            if($close)$service->close($id,$actor,$key,(int)$version);
            elseif($admin)$service->replyAdmin($id,$actor,isset($_POST['body'])?$_POST['body']:'',$key,(int)$version);
            else $service->replyUser($id,$actor,isset($_POST['body'])?$_POST['body']:'',$key,(int)$version);
            unset($_SESSION['_ticket_reply'][$scope]);if($admin)unset($_SESSION['_ticket_reply']['admin:'.$actor.':'.(int)$id]);flash('success',$close?'工单已关闭。':'回复已提交。');
        } catch(PDOException $error){throw $error;}
        catch(Exception $error){if(!$close)$_SESSION['_ticket_reply'][$draftScope]=$scalar($_POST,'body');flash('error',$error->getMessage());}
        redirect($path);
    };
    $router->post('/tickets/{id}/reply',function($id)use($change){return $change($id,false);});
    $router->post('/admin/tickets/{id}/reply',function($id)use($change){return $change($id,true);});
    $router->post('/admin/tickets/{id}/close',function($id)use($change){return $change($id,true,true);});
}
