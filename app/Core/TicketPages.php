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
        $data=(new TicketService($pdo))->listing($admin?null:$actor,array('status'=>$status),max(1,(int)$scalar($_GET,'page','1')),20);
        $body='<div class="tickets-page">'.($admin?'<h1>售后工单</h1>':'<header class="ux-page-head"><div><h1>售后工单</h1><p class="muted">查看售后进度或提交问题，订单相关问题可在新建工单时关联本人订单。</p></div><nav class="product-form-actions" aria-label="工单导航"><a href="#new-ticket">新建工单</a><a href="/orders">我的订单</a><a href="/">返回商城</a></nav></header><section class="ticket-list-section" aria-labelledby="ticket-list-title"><h2 id="ticket-list-title">我的工单</h2><p class="muted">选择工单查看客服回复，也可按状态筛选处理进度。</p>').'<form method="get"><label>状态<select name="status"><option value="">全部状态</option>';
        $createForm='';
        foreach($labels as $value=>$label)$body.='<option value="'.$value.'"'.($status===$value?' selected':'').'>'.$label.'</option>';
        $body.='</select></label><button class="secondary-button">筛选</button></form>';
        if(!$admin) {
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
            $createForm='<section class="form-card ticket-create-section" id="new-ticket" aria-labelledby="ticket-create-title"><h2 id="ticket-create-title">新建工单</h2><p class="muted">请填写主题与问题描述，提交后可在上方工单列表查看处理进度。</p><form class="ticket-create-layout" method="post" action="/tickets">'.csrf_field().'<input type="hidden" name="request_key" value="'.bin2hex(random_bytes(24)).'"><div class="ticket-create-fields"><label>主题<input name="subject" maxlength="200" required value="'.e(isset($draft['subject'])?$draft['subject']:'').'"></label><label for="ticket-order-id">关联本人订单（可选）</label><select id="ticket-order-id" name="order_id" aria-describedby="ticket-order-help">'.$options.'</select><p class="field-help" id="ticket-order-help">订单问题请选择对应订单；其他问题可保留“不关联订单”。下拉列表展示最近 50 条本人订单，更早的订单可从订单详情进入工单。</p></div><div class="ticket-create-message"><label for="ticket-create-body">问题描述</label><p class="field-help" id="ticket-create-help">请说明遇到的问题、发生时间及已尝试的操作，最多 5000 字。请勿填写密码或支付凭据。</p><textarea id="ticket-create-body" name="body" maxlength="5000" required aria-describedby="ticket-create-help">'.e(isset($draft['body'])?$draft['body']:'').'</textarea></div><div class="product-form-actions ticket-create-actions"><button class="button">提交工单</button></div></form></section>';
        }
        $body.='<section class="table-wrap"><table><thead><tr><th>编号</th>'.($admin?'<th>用户</th>':'').'<th>主题</th><th>状态</th><th>更新时间</th>'.($admin?'':'<th>操作</th>').'</tr></thead><tbody>';
        foreach($data['items'] as $ticket) {
            if($admin)$body.='<tr><td><a href="'.$path.'/'.(int)$ticket['id'].'">'.e($ticket['ticket_no']).'</a></td><td>#'.(int)$ticket['user_id'].'</td><td>'.e($ticket['subject']).'</td><td>'.e(isset($labels[$ticket['status']])?$labels[$ticket['status']]:$ticket['status']).'</td><td>'.e($ticket['updated_at']).'</td></tr>';
            else $body.='<tr><td data-label="编号"><a href="'.$path.'/'.(int)$ticket['id'].'">'.e($ticket['ticket_no']).'</a></td><td data-label="主题">'.e($ticket['subject']).'</td><td data-label="状态"><span class="ticket-status-badge ticket-status-'.e(isset($labels[$ticket['status']])?$ticket['status']:'unknown').'">'.e(isset($labels[$ticket['status']])?$labels[$ticket['status']]:$ticket['status']).'</span></td><td data-label="更新时间">'.e($ticket['updated_at']).'</td><td data-label="操作"><a class="ticket-view-link" href="'.$path.'/'.(int)$ticket['id'].'" aria-label="'.e('查看工单 '.$ticket['ticket_no']).'">查看工单</a></td></tr>';
        }
        if(!$data['items'])$body.='<tr><td colspan="5"'.($admin?'':' class="ticket-empty-cell" data-label="工单列表"').'>'.($admin?'暂无工单。':'<div class="empty service-ui-empty"><h2>'.($status!==''?'该状态下暂无工单':'暂无售后工单').'</h2><p>'.($status!==''?'可以查看全部工单，或新建工单说明问题。':'遇到使用或订单问题时，可以新建工单并在此查看回复。').'</p><div class="product-form-actions"><a class="button" href="#new-ticket">新建工单</a>'.($status!==''?'<a class="secondary-button" href="/tickets">查看全部工单</a>':'<a class="secondary-button" href="/orders">查看我的订单</a>').'</div></div>').'</td></tr>';
        $body.='</tbody></table></section>'.$pagination($path,$data['page'],$data['pages'],'page',array('status'=>$status)).($admin?'':'</section>'.$createForm).'</div>';
        if($admin)return admin_page('售后工单',$body,'','/admin/tickets',$pdo,$config);
        return page('售后工单',$body,$config);
    };
    $detail = function($id,$admin) use($pdo,$config,$labels,$scalar,$pagination) {
        $actor=$admin?Security::requireAdmin():Security::requireUser();$path=($admin?'/admin/tickets/':'/tickets/').(int)$id;
        try{if(!is_string($id)||!preg_match('/^[1-9][0-9]*$/D',$id)||strlen($id)>strlen((string)PHP_INT_MAX)||(strlen($id)===strlen((string)PHP_INT_MAX)&&strcmp($id,(string)PHP_INT_MAX)>0))throw new RuntimeException('工单不存在。');$ticket=(new TicketService($pdo))->detail($id,$admin?null:$actor,max(1,(int)$scalar($_GET,'message_page','1')),20);}
        catch(PDOException $error){throw $error;}
        catch(RuntimeException $error){http_response_code(404);$body=$admin?'<p class="empty">工单不存在或无权查看。</p>':'<section class="empty service-ui-empty"><h1>无法查看此工单</h1><p>工单不存在或无权查看，请返回列表查看本人的工单。</p><a class="button" href="/tickets">返回工单列表</a></section>';return $admin?admin_page('工单不存在',$body,'','/admin/tickets',$pdo,$config):page('工单不存在',$body,$config);}
        $scope=($admin?'admin:':'user:').$actor.':'.(int)$ticket['id'];
        $draft=isset($_SESSION['_ticket_reply'][$scope])?$_SESSION['_ticket_reply'][$scope]:'';
        $body='<div class="ticket-detail">'.($admin?'<a href="/admin/tickets">返回工单列表</a><h1>'.e($ticket['subject']).'</h1><p>状态：'.e(isset($labels[$ticket['status']])?$labels[$ticket['status']]:$ticket['status']).'</p>':'<header class="ux-page-head"><div><h1>'.e($ticket['subject']).'</h1><p>状态：<span class="ticket-status-badge ticket-status-'.e(isset($labels[$ticket['status']])?$ticket['status']:'unknown').'">'.e(isset($labels[$ticket['status']])?$labels[$ticket['status']]:$ticket['status']).'</span></p></div><nav class="product-form-actions" aria-label="工单导航"><a href="/tickets">返回工单列表</a><a href="/tickets#new-ticket">新建工单</a><a href="/orders">我的订单</a></nav></header>');
        if($ticket['order_no'])$body.='<p>关联订单：<a href="'.($admin?'/admin/orders/':'/orders/').rawurlencode($ticket['order_no']).'">'.e($ticket['order_no']).'</a> · '.e($ticket['product_name_snapshot']).'</p>';
        if(!$admin)$body.='<section class="service-ui-content" aria-label="工单沟通记录"><h2>沟通记录</h2>';
        if(!$admin && !$ticket['messages'])$body.='<div class="empty service-ui-empty"><h3>当前页暂无沟通记录</h3><p>可通过下方分页查看其他页；如需补充问题，请在工单开放时使用回复框。</p><a href="/tickets">返回工单列表</a></div>';
        foreach($ticket['messages'] as $message)$body.='<article><b>'.($message['sender_type']==='admin'?'客服':($admin?'用户':'我')).'</b><time>'.e($message['created_at']).'</time><p>'.nl2br(e($message['body'])).'</p></article>';
        if(!$admin)$body.='</section>';
        $body.=$pagination($path,$ticket['message_page'],$ticket['message_pages'],'message_page');
        if($ticket['status']==='closed')$body.=$admin?'<p class="empty">工单已关闭，不能继续回复。</p>':'<section class="empty service-ui-empty"><h2>工单已关闭</h2><p>历史沟通记录仍可查看，此工单不能继续回复。如有其他问题，可另建工单。</p><div class="product-form-actions"><a href="/tickets">返回工单列表</a><a class="secondary-button" href="/tickets#new-ticket">新建工单</a></div></section>';
        else {
            $body.='<form method="post" action="'.$path.'/reply">'.csrf_field().'<input type="hidden" name="version_no" value="'.(int)$ticket['version_no'].'"><input type="hidden" name="request_key" value="'.bin2hex(random_bytes(24)).'">'.($admin?'<label>回复内容<textarea name="body" maxlength="5000" required>':'<p class="field-help" id="ticket-reply-help">补充问题或回复客服，最多 5000 字。请勿填写密码或支付凭据。</p><label>回复内容<textarea name="body" maxlength="5000" required aria-describedby="ticket-reply-help">').e($draft).'</textarea></label><button class="button">回复</button></form>';
            if($admin)$body.='<form method="post" action="'.$path.'/close">'.csrf_field().'<input type="hidden" name="version_no" value="'.(int)$ticket['version_no'].'"><input type="hidden" name="request_key" value="'.bin2hex(random_bytes(24)).'"><button class="secondary-button">关闭工单</button></form>';
        }
        $body.='</div>';
        return $admin?admin_page('处理工单',$body,'','/admin/tickets',$pdo,$config):page('工单详情',$body,$config);
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
        $actor=$admin?Security::requireAdmin():Security::requireUser();post_csrf();$scope=($admin?'admin:':'user:').$actor.':'.(int)$id;$path=($admin?'/admin/tickets/':'/tickets/').(int)$id;
        try {
            $version=$scalar($_POST,'version_no');if(!preg_match('/^[1-9][0-9]{0,9}$/D',$version))throw new InvalidArgumentException('版本号无效，请刷新后重试。');
            $service=new TicketService($pdo);$key=$scalar($_POST,'request_key');
            if($close)$service->close($id,$actor,$key,(int)$version);
            elseif($admin)$service->replyAdmin($id,$actor,isset($_POST['body'])?$_POST['body']:'',$key,(int)$version);
            else $service->replyUser($id,$actor,isset($_POST['body'])?$_POST['body']:'',$key,(int)$version);
            unset($_SESSION['_ticket_reply'][$scope]);flash('success',$close?'工单已关闭。':'回复已提交。');
        } catch(PDOException $error){throw $error;}
        catch(Exception $error){if(!$close)$_SESSION['_ticket_reply'][$scope]=$scalar($_POST,'body');flash('error',$error->getMessage());}
        redirect($path);
    };
    $router->post('/tickets/{id}/reply',function($id)use($change){return $change($id,false);});
    $router->post('/admin/tickets/{id}/reply',function($id)use($change){return $change($id,true);});
    $router->post('/admin/tickets/{id}/close',function($id)use($change){return $change($id,true,true);});
}
