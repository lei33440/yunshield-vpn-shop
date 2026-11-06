<?php
function account_tickets_page($pdo,$uid){
    $labels=array('open'=>'待客服回复','waiting_customer'=>'等待用户回复','closed'=>'已关闭');
    $filters=array('status'=>'','q'=>'','sort'=>'updated_desc','page'=>'1');$valid=true;
    foreach($filters as $key=>$default){if(!isset($_GET[$key]))continue;$value=$_GET[$key];$ok=is_string($value)&&mb_check_encoding($value,'UTF-8');if($ok){$value=trim($value);if($key==='status')$ok=$value===''||isset($labels[$value]);elseif($key==='sort')$ok=in_array($value,array('updated_desc','updated_asc'),true);elseif($key==='page')$ok=preg_match('/^[1-9][0-9]{0,8}$/D',$value)===1;else $ok=mb_strlen($value,'UTF-8')<=100;}if($ok)$filters[$key]=$value;else $valid=false;}
    if(!$valid){http_response_code(400);return '<section class="mt-empty"><h1>工单筛选参数无效</h1><p>请选择有效状态、排序或搜索条件。</p><a href="/tickets">返回我的工单</a></section>';}
    $url=function($changes=array())use($filters){return '/tickets?'.http_build_query(array_merge($filters,$changes),'','&',PHP_QUERY_RFC3986);};
    $counts=(new TicketService($pdo))->statusCounts($uid);$where='t.user_id=?';$args=array($uid);
    if($filters['q']!==''){$where.=" AND (t.ticket_no LIKE ? ESCAPE '!' OR t.subject LIKE ? ESCAPE '!')";$like='%'.str_replace(array('!','%','_'),array('!!','!%','!_'),$filters['q']).'%';$args[]=$like;$args[]=$like;}
    $q=$pdo->prepare('SELECT t.status,COUNT(*) total FROM support_tickets t WHERE '.$where.' GROUP BY t.status');$q->execute($args);$matches=array('all'=>0,'open'=>0,'waiting_customer'=>0,'closed'=>0);foreach($q->fetchAll() as $row){$matches['all']+=(int)$row['total'];if(isset($matches[$row['status']]))$matches[$row['status']]=(int)$row['total'];}
    if($filters['status']!==''){$where.=' AND t.status=?';$args[]=$filters['status'];}
    $total=$matches[$filters['status']?:'all'];$pages=max(1,(int)ceil($total/20));$current=min((int)$filters['page'],$pages);
    $q=$pdo->prepare('SELECT t.id,t.ticket_no,t.subject,t.status,t.updated_at,o.order_no FROM support_tickets t LEFT JOIN orders o ON o.id=t.order_id AND o.user_id=t.user_id WHERE '.$where.' ORDER BY t.updated_at '.($filters['sort']==='updated_asc'?'ASC':'DESC').',t.id DESC LIMIT 20 OFFSET '.(($current-1)*20));$q->execute($args);$items=$q->fetchAll();
    $stats='';foreach($labels as $key=>$label)$stats.='<a href="'.e($url(array('status'=>$key,'q'=>'','page'=>'1'))).'"><span>'.$label.'</span><strong>'.(int)$counts[$key].'</strong></a>';
    $tabs='';foreach(array(''=>'全部')+$labels as $key=>$label)$tabs.='<a href="'.e($url(array('status'=>$key,'page'=>'1'))).'"'.($filters['status']===$key?' aria-current="page"':'').'>'.$label.' ('.$matches[$key?:'all'].')</a>';
    $icon='<svg aria-hidden="true" viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 24 4 30l8-4c17 4 23-17 9-21C6 0-4 15 6 24Z"/><circle cx="10" cy="15" r=".8"/><circle cx="16" cy="15" r=".8"/><circle cx="22" cy="15" r=".8"/></svg>';
    $rows='';foreach($items as $ticket){$status=$ticket['status'];$waiting=$status==='waiting_customer';$description=$waiting?'客服已回复，请进入工单查看并补充信息。':($status==='closed'?'工单已关闭，可查看历史沟通记录。':'已收到你的问题，请等待客服回复。');$rows.='<article class="mt-ticket'.($waiting?' is-waiting':'').'"><span class="mt-icon">'.$icon.'</span><div class="mt-copy"><div class="mt-title"><h2><a href="/tickets/'.(int)$ticket['id'].'">'.e($ticket['subject']).'</a></h2><span class="mt-status">'.e(isset($labels[$status])?$labels[$status]:'状态待确认').'</span></div><p>'.$description.'</p><p class="mt-meta">工单 '.e($ticket['ticket_no']).' · '.($ticket['order_no']?'关联订单 <a href="/orders/'.e(rawurlencode($ticket['order_no'])).'">'.e($ticket['order_no']).'</a>':'未关联订单').' · 更新于 <time datetime="'.e(str_replace(' ','T',$ticket['updated_at'])).'">'.e(substr($ticket['updated_at'],0,16)).'</time></p></div><a class="'.($status==='closed'?'mt-history':'ao-button'.($waiting?' ao-primary':'')).'" href="/tickets/'.(int)$ticket['id'].'">'.($waiting?'查看并回复':($status==='closed'?'查看记录':'查看工单')).' ↗</a></article>';}
    $pagination='<span>共 '.$total.' 条 · 第 '.$current.' / '.$pages.' 页</span>';foreach(array(-1=>'上一页',1=>'下一页') as $step=>$text){$enabled=$step<0?$current>1:$current<$pages;$pagination.=$enabled?'<a rel="'.($step<0?'prev':'next').'" href="'.e($url(array('page'=>(string)($current+$step)))).'">'.$text.'</a>':'<span aria-disabled="true">'.$text.'</span>';if($step<0)$pagination.='<b aria-current="page">'.$current.'</b>';}
    $draft=isset($_SESSION['_ticket_create'][$uid])?$_SESSION['_ticket_create'][$uid]:array();$orderId=isset($draft['order_id'])?$draft['order_id']:(isset($_GET['order_id'])&&is_string($_GET['order_id'])?$_GET['order_id']:'');
    $orders=$pdo->prepare('SELECT id,order_no,product_name_snapshot FROM orders WHERE user_id=? ORDER BY id DESC LIMIT 50');$orders->execute(array($uid));$options='<option value="">不关联订单</option>';$found=false;
    foreach($orders as $order){$selected=(string)$order['id']===$orderId;$found=$found||$selected;$options.='<option value="'.(int)$order['id'].'"'.($selected?' selected':'').'>'.e($order['order_no'].' / '.$order['product_name_snapshot']).'</option>';}
    if(!$found&&preg_match('/^[1-9][0-9]{0,17}$/D',$orderId)){$q=$pdo->prepare('SELECT id,order_no,product_name_snapshot FROM orders WHERE id=? AND user_id=?');$q->execute(array($orderId,$uid));$order=$q->fetch();if($order)$options.='<option value="'.(int)$order['id'].'" selected>'.e($order['order_no'].' / '.$order['product_name_snapshot']).'</option>';}
    $create='<details class="mt-create" id="new-ticket"'.($draft||$orderId!==''?' open':'').'><summary>新建工单 <span>展开填写问题描述</span></summary><form method="post" action="/tickets" data-submit-lock>'.csrf_field().'<input type="hidden" name="request_key" value="'.bin2hex(random_bytes(24)).'"><label>主题<input name="subject" maxlength="200" required value="'.e(isset($draft['subject'])?$draft['subject']:'').'"></label><label for="ticket-order-id">关联本人订单（可选）</label><select id="ticket-order-id" name="order_id" aria-describedby="ticket-order-help">'.$options.'</select><p id="ticket-order-help">展示最近 50 条本人订单，更早订单可从订单详情进入工单。</p><label for="ticket-create-body">问题描述</label><p id="ticket-create-help">请说明问题、发生时间及已尝试的操作，最多 5000 字。请勿填写密码或支付凭据。</p><textarea id="ticket-create-body" name="body" maxlength="5000" required aria-describedby="ticket-create-help">'.e(isset($draft['body'])?$draft['body']:'').'</textarea><button class="ao-button ao-primary" type="submit" data-idle-label="提交工单">提交工单</button><p class="pc-submit-hint" hidden>正在提交，请勿重复点击。</p></form></details>';
    $empty='<div class="mt-empty"><h2>暂无匹配的工单</h2><p>可以调整筛选条件，或新建工单说明遇到的问题。</p><a href="/tickets">查看全部工单</a></div>';
    return '<div class="mt-page"><header class="ao-welcome mt-welcome"><div><p class="ao-kicker">SUPPORT TICKETS</p><h1>我的工单</h1><p>查看问题处理进度，与客服保持沟通。</p></div><a class="ao-button ao-primary" href="#new-ticket">＋ 新建工单</a></header><section class="mt-stats" aria-label="工单状态统计">'.$stats.'</section><div class="mt-toolbar"><nav class="mt-tabs" aria-label="工单状态筛选">'.$tabs.'</nav><form class="mt-search" method="get" action="/tickets"><input type="hidden" name="status" value="'.e($filters['status']).'"><label><span class="mt-sr-only">搜索工单编号或标题</span><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="10" cy="10" r="7"/><path d="m15 15 6 6"/></svg><input type="search" name="q" maxlength="100" placeholder="搜索工单编号或标题…" value="'.e($filters['q']).'"></label><select name="sort" aria-label="工单排序"><option value="updated_desc"'.($filters['sort']==='updated_desc'?' selected':'').'>最近更新</option><option value="updated_asc"'.($filters['sort']==='updated_asc'?' selected':'').'>最早更新</option></select><button type="submit">筛选</button></form></div>'.($filters['q']!==''?'<p class="mt-results">搜索“'.e($filters['q']).'” · 状态标签为搜索匹配数，顶部统计为全部本人工单。<a href="/tickets">清除筛选</a></p>':'').'<section class="mt-list" aria-label="我的工单列表">'.($rows?:$empty).'</section><nav class="mt-pagination" aria-label="工单分页">'.$pagination.'</nav><div class="mt-help-grid"><section><h2>提交问题前，先准备这些信息。</h2><ol><li><span>01</span>问题描述与发生时间：简要说明问题，并提供大致发生时间。</li><li><span>02</span>相关订单号（如有）：选择对应订单，便于客服快速定位。</li><li><span>03</span>客户端名称与报错信息：说明使用的客户端及错误提示。</li></ol></section><section><h2>也许这里已有答案。</h2><nav aria-label="工单帮助"><a href="/articles">查看常见问题 ↗</a><a href="/articles?q=使用教程">打开使用教程 ↗</a><a href="/downloads">前往软件下载 ↗</a></nav></section></div><p class="mt-note">请勿在工单中填写密码或完整订阅链接；同一问题请在原工单内继续回复，避免重复提交。等待用户回复表示当前处理状态，不代表回复未读。</p>'.$create.'</div><script>(function(){var form=document.getElementById("new-ticket");function show(){if(location.hash==="#new-ticket")form.open=true}window.addEventListener("hashchange",show);show();document.querySelectorAll("a[href=\"#new-ticket\"]").forEach(function(a){a.addEventListener("click",function(){form.open=true})});var create=form.querySelector("form"),button=create.querySelector("button[type=submit]"),hint=create.querySelector(".pc-submit-hint"),busy=false;create.addEventListener("submit",function(event){if(busy){event.preventDefault();return}busy=true;button.disabled=true;button.textContent="正在提交…";hint.hidden=false});window.addEventListener("pageshow",function(){busy=false;button.disabled=false;button.textContent="提交工单";hint.hidden=true})})();</script>';
}
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
        if(!$admin)return page('我的工单',account_tickets_page($pdo,$actor),$config);
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
