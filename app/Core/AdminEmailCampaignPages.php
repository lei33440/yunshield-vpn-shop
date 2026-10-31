<?php

function email_campaign_labels()
{
    return array('draft'=>'草稿','ready'=>'待确认快照','queued'=>'发送中 / 等待发送器','completed'=>'已结束','cancelled'=>'已取消','not_queued'=>'未入队','pending'=>'待发送','retry'=>'等待自动重试','processing'=>'处理中','sent'=>'SMTP已接受','failed'=>'失败','skipped'=>'已跳过');
}

function email_campaign_text($key)
{
    $labels=email_campaign_labels();
    return isset($labels[$key])?$labels[$key]:'未知状态';
}

function email_campaign_pager($data,$url,$key='page')
{
    $html='<nav class="campaign-pagination" aria-label="分页"><span>共 '.(int)$data['total'].' 条 · 第 '.(int)$data['page'].' / '.(int)$data['pages'].' 页</span>';
    foreach(array(-1=>'上一页',1=>'下一页') as $delta=>$label){$page=(int)$data['page']+$delta;if($page>=1&&$page<=(int)$data['pages'])$html.='<a class="secondary-button" href="'.e($url.(strpos($url,'?')===false?'?':'&').$key.'='.$page).'">'.$label.'</a>';}
    return $html.'</nav>';
}

function email_campaign_worker_note()
{
    return '<div class="admin-info-banner">通知先入队，需要管理员在服务器运行专用发送器 <code>php scripts/customer_email_worker.php --loop</code>（单次处理可用 <code>--once</code>）。保存开关、生成快照和确认任务均不表示已投递；SMTP 未启用或配置不完整时暂停。请勿使用旧全量 worker 处理历史积压。</div>';
}

function email_campaign_editor($campaign,$error='')
{
    $id=isset($campaign['id'])?(int)$campaign['id']:0;
    $ids=isset($campaign['user_ids'])&&is_array($campaign['user_ids'])?$campaign['user_ids']:array();
    $body=admin_heading($id?'编辑群发草稿 #'.$id:'新建通知群发','先保存草稿，再生成快照；预览不会发送邮件。','<a class="secondary-button" href="/admin/email-campaigns">返回群发列表</a>');
    if($error!=='')$body.='<div class="notice error">'.e($error).'</div>';
    $body.='<section class="product-form-card campaign-editor"><form method="post" action="/admin/email-campaigns/'.($id?$id.'/save':'create').'">'.csrf_field().($id?'<input type="hidden" name="version" value="'.(int)$campaign['version'].'">':'').'<div class="product-form-section"><h2>邮件内容</h2><label>主题<input name="subject" required maxlength="600" value="'.e($campaign['subject']).'"><small>最多 600 UTF-8 字节；不能包含换行。</small></label><label>纯文本正文<textarea name="body" rows="12" required maxlength="60000">'.e($campaign['body']).'</textarea><small>最多 60000 UTF-8 字节。不支持 HTML、附件或外部邮箱导入；内容将安全转义。</small></label><fieldset><legend>收件范围</legend><label class="campaign-option"><input type="radio" name="scope" value="all"'.($campaign['scope']==='all'?' checked':'').'> 全部正常用户</label><label class="campaign-option"><input type="radio" name="scope" value="selected"'.($campaign['scope']==='selected'?' checked':'').'> 指定正常用户（最多 1000 人）</label><small>快照时筛除无效邮箱、退订用户，并按邮箱去重；发送前再次核对资格。</small></fieldset><section id="campaign-picker" aria-label="选择收件用户"><h3>指定用户</h3><div id="campaign-selected">';
    $body.='<input type="hidden" name="user_ids_json" value="'.e(json_encode(array_values(array_map('intval',$ids)))).'">';
    $body.='</div><p id="campaign-selection" aria-live="polite"></p><div class="campaign-picker-search"><input type="search" id="campaign-search" maxlength="60" placeholder="搜索正常用户昵称或邮箱" aria-label="搜索用户"><button type="button" class="secondary-button" id="campaign-search-button">搜索</button></div><div id="campaign-users" aria-live="polite"></div><div class="campaign-pagination"><button type="button" class="secondary-button" id="campaign-prev">上一页</button><span id="campaign-user-page"></span><button type="button" class="secondary-button" id="campaign-next">下一页</button></div><noscript>指定用户选择需要启用 JavaScript；已保存的选择将保留。</noscript></section></div><div class="product-form-actions"><button class="button" type="submit">保存草稿（不发送）</button></div></form></section>'.email_campaign_worker_note();
    $body.='<script>(function(){var holder=document.getElementById("campaign-selected"),selected=new Set(JSON.parse(holder.querySelector("input").value).map(String).filter(function(id){return /^[1-9][0-9]{0,17}$/.test(id)})),page=1,pages=1,request=0;var list=document.getElementById("campaign-users"),picker=document.getElementById("campaign-picker");function sync(){holder.querySelector("input").value=JSON.stringify(Array.from(selected));var summary=document.getElementById("campaign-selection");summary.textContent="已选 "+selected.size+" 人（跨页保留）。";selected.forEach(function(id){var button=document.createElement("button");button.type="button";button.className="campaign-selected-chip";button.textContent="#"+id+" ×";button.setAttribute("aria-label","移除用户 "+id);button.onclick=function(){selected.delete(id);sync();list.querySelectorAll("input").forEach(function(x){if(x.value===id)x.checked=false})};summary.appendChild(button)})}function load(n){var serial=++request;list.textContent="加载中…";fetch("/admin/email-campaigns/users?q="+encodeURIComponent(document.getElementById("campaign-search").value)+"&page="+n,{credentials:"same-origin",headers:{Accept:"application/json"}}).then(function(r){if(!r.ok)throw new Error();return r.json()}).then(function(data){if(serial!==request)return;page=data.page;pages=data.pages;list.textContent="";data.items.forEach(function(user){var label=document.createElement("label");label.className="campaign-option";var box=document.createElement("input");box.type="checkbox";box.value=String(user.id);box.checked=selected.has(box.value);box.onchange=function(){if(box.checked&&selected.size>=1000){box.checked=false;alert("最多选择1000名用户");return}if(box.checked)selected.add(box.value);else selected.delete(box.value);sync()};label.appendChild(box);label.appendChild(document.createTextNode(" #"+user.id+" "+user.display_name+" · "+user.email_masked));list.appendChild(label)});if(!data.items.length)list.textContent="本页没有符合条件的用户。";document.getElementById("campaign-user-page").textContent=page+" / "+pages;document.getElementById("campaign-prev").disabled=page<=1;document.getElementById("campaign-next").disabled=page>=pages}).catch(function(){if(serial===request)list.textContent="无法加载用户，请重新搜索或刷新页面。"})}function scope(){picker.hidden=document.querySelector("input[name=scope]:checked").value!=="selected"}document.querySelectorAll("input[name=scope]").forEach(function(x){x.onchange=scope});document.getElementById("campaign-search-button").onclick=function(){load(1)};document.getElementById("campaign-search").onkeydown=function(e){if(e.key==="Enter"){e.preventDefault();load(1)}};document.getElementById("campaign-prev").onclick=function(){if(page>1)load(page-1)};document.getElementById("campaign-next").onclick=function(){if(page<pages)load(page+1)};sync();scope();load(1)})();</script>';
    return $body;
}

function email_campaign_action_form($campaign,$action,$label,$extra='')
{
    return '<form method="post" action="/admin/email-campaigns/'.(int)$campaign['id'].'/'.$action.'">'.csrf_field().'<input type="hidden" name="version" value="'.(int)$campaign['version'].'">'.$extra.'<button class="'.($action==='confirm'?'button':'secondary-button').'" type="submit">'.$label.'</button></form>';
}

function register_admin_email_campaign_routes($router,$pdo,$config)
{
    $router->get('/admin/email-campaigns',function()use($pdo,$config){
        Security::requireAdmin();header('Cache-Control: no-store');
        $data=(new EmailCampaignService($pdo,$config))->listing(isset($_GET['page'])&&is_scalar($_GET['page'])?$_GET['page']:1);$rows='';
        foreach($data['items'] as $c)$rows.='<tr><td><a href="/admin/email-campaigns/'.(int)$c['id'].'">#'.(int)$c['id'].' · '.e($c['subject']).'</a></td><td>'.($c['scope']==='all'?'全部正常用户':'指定用户').'</td><td>'.e(email_campaign_text($c['status'])).'</td><td>'.(int)$c['recipient_count'].'</td><td>'.e($c['created_at']).'</td><td><a href="/admin/email-campaigns/'.(int)$c['id'].'">查看详情 →</a></td></tr>';
        page('通知邮件群发',admin_heading('通知邮件群发','草稿 → 快照预览 → 确认 → 专用发送器处理','<a class="button" href="/admin/email-campaigns/create">新建群发草稿</a>').email_campaign_worker_note().'<section class="admin-panel admin-data-panel"><div class="table-wrap"><table><thead><tr><th>任务 / 主题</th><th>范围</th><th>状态</th><th>快照人数</th><th>创建时间</th><th>操作</th></tr></thead><tbody>'.($rows?:'<tr><td colspan="6" class="admin-empty">暂无群发任务。新建草稿不会发送邮件。</td></tr>').'</tbody></table></div>'.email_campaign_pager($data,'/admin/email-campaigns').'</section>',$config);
    });
    $router->get('/admin/email-campaigns/users',function()use($pdo,$config){
        Security::requireAdmin();header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
        try{$q=isset($_GET['q'])&&is_string($_GET['q'])?trim($_GET['q']):'';$data=(new EmailCampaignService($pdo,$config))->eligibleUsers($q,isset($_GET['page'])&&is_scalar($_GET['page'])?$_GET['page']:1);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}catch(Throwable $e){http_response_code(400);echo '{"error":"无法加载用户"}';}
    });
    $router->get('/admin/email-campaigns/create',function()use($config){Security::requireAdmin();header('Cache-Control: no-store');page('新建群发草稿',email_campaign_editor(array('subject'=>'','body'=>'','scope'=>'all','user_ids'=>array())),$config);});
    foreach(array('/admin/email-campaigns/create','/admin/email-campaigns/{id}/save') as $route){
        $router->post($route,function($id=null)use($pdo,$config){
            $aid=Security::requireAdmin();post_csrf();header('Cache-Control: no-store');
            $input=array('subject'=>isset($_POST['subject'])&&is_string($_POST['subject'])?$_POST['subject']:'','body'=>isset($_POST['body'])&&is_string($_POST['body'])?$_POST['body']:'','scope'=>isset($_POST['scope'])&&is_string($_POST['scope'])?$_POST['scope']:'all','user_ids'=>isset($_POST['user_ids_json'])&&is_string($_POST['user_ids_json'])&&strlen($_POST['user_ids_json'])<=22000?json_decode($_POST['user_ids_json'],true):array());
            try{$saved=(new EmailCampaignService($pdo,$config))->saveDraft($input,$aid,$id===null?null:email_campaign_id($id),isset($_POST['version'])?$_POST['version']:null);flash('success','草稿已保存，尚未生成快照或发送邮件。');redirect('/admin/email-campaigns/'.$saved);}catch(Throwable $e){flash('error',email_campaign_error($e));redirect($id===null?'/admin/email-campaigns/create':'/admin/email-campaigns/'.email_campaign_id($id));}
        });
    }
    foreach(array('snapshot','back','confirm','cancel') as $action){
        $router->post('/admin/email-campaigns/{id}/'.$action,function($id)use($pdo,$config,$action){
            $aid=Security::requireAdmin();post_csrf();$id=email_campaign_id($id);
            try{$service=new EmailCampaignService($pdo,$config);$version=isset($_POST['version'])?$_POST['version']:null;
                if($action==='snapshot')$service->snapshot($id,$version,$aid);
                elseif($action==='back')$service->backToDraft($id,$version,$aid);
                elseif($action==='cancel'){if(!isset($_POST['ack_cancel'])||$_POST['ack_cancel']!=='1')throw new InvalidArgumentException('请先确认已发送或在途邮件无法撤回。');$service->cancel($id,$aid);}
                else{if(!isset($_POST['ack_send'])||$_POST['ack_send']!=='1')throw new InvalidArgumentException('请明确确认发送此快照。');$service->confirm($id,$version,$aid);}
                $messages=array('snapshot'=>'快照已生成，请核对内容与人数；尚未发送。','back'=>'已退回草稿，旧快照已失效。','confirm'=>'群发任务已确认，等待专用发送器处理；不代表已投递。','cancel'=>'任务已取消，已发送或在途邮件无法撤回。');flash('success',$messages[$action]);
            }catch(Throwable $e){flash('error',email_campaign_error($e));}redirect('/admin/email-campaigns/'.$id);
        });
    }
    $router->get('/admin/email-campaigns/{id}',function($id)use($pdo,$config){
        Security::requireAdmin();header('Cache-Control: no-store');
        try{$service=new EmailCampaignService($pdo,$config);$c=$service->get(email_campaign_id($id));}catch(Throwable $e){http_response_code(404);return page('任务不存在','<div class="admin-info-banner">群发任务不存在。<a href="/admin/email-campaigns">返回列表</a></div>',$config);}
        if($c['status']==='draft')return page('群发草稿',email_campaign_editor($c).'<section class="admin-panel campaign-section"><h2>生成快照</h2><p>请先保存内容修改，再生成当前已保存版本的收件人快照。</p>'.email_campaign_action_form($c,'snapshot','生成快照并预览（不发送）').'</section>',$config);
        $body=admin_heading('群发任务 #'.(int)$c['id'],'当前状态：'.email_campaign_text($c['status']),' <a class="secondary-button" href="/admin/email-campaigns">返回列表</a>').email_campaign_worker_note();
        $body.='<section class="admin-panel campaign-section"><h2>冻结快照预览</h2><p>版本 '.(int)$c['version'].' · '.($c['scope']==='all'?'全部正常用户':'指定用户').' · 快照人数 <strong>'.(int)$c['recipient_count'].'</strong> · '.e($c['snapshot_at']?:'—').'</p><h3>'.e($c['subject']).'</h3><div class="campaign-preview">'.e($c['body']).'</div><p>以上是冻结的主题和纯文本正文。正式邮件另含品牌信息及群发退订链接；逐用户投递，不暴露其他收件地址。</p>';
        if($c['status']==='ready'){$body.='<div class="campaign-actions">'.email_campaign_action_form($c,'back','退回草稿（使快照失效）');if((int)$c['recipient_count']>0)$body.=email_campaign_action_form($c,'confirm','确认发送此快照','<label class="campaign-option"><input type="checkbox" name="ack_send" value="1" required> 我已核对内容与 '.(int)$c['recipient_count'].' 名收件人，确认提交发送任务</label>');else $body.='<p>快照没有合格收件人，不能确认发送。请退回草稿修改范围。</p>';$body.='</div>';}
        $body.='</section><section class="admin-panel campaign-section"><div class="admin-panel-head"><h2>发送进度</h2><a class="secondary-button" href="/admin/email-campaigns/'.(int)$c['id'].'">刷新进度</a></div><div class="campaign-counts">';
        foreach($c['counts'] as $state=>$count)$body.='<div><span>'.e(email_campaign_text($state)).'</span><strong>'.(int)$count.'</strong></div>';
        $body.='</div><p>SMTP已接受不代表最终送达邮箱；失败项仅按有限次数自动重试，不提供批量强制重发。</p>';
        if(in_array($c['status'],array('ready','queued'),true))$body.=email_campaign_action_form($c,'cancel','取消任务','<label class="campaign-option"><input type="checkbox" name="ack_cancel" value="1" required> 停止后续入队和未发送项；我理解已发送或在途邮件无法撤回</label>');
        $body.='</section>';$recipients=$service->recipients($c['id'],isset($_GET['page'])&&is_scalar($_GET['page'])?$_GET['page']:1);$rows='';
        foreach($recipients['items'] as $r)$rows.='<tr><td>#'.(int)$r['user_id'].'</td><td>'.e($r['email_masked']).'</td><td>'.e(email_campaign_text($r['status'])).'</td><td>'.e($r['reason']).'</td><td>'.(int)$r['retry_count'].'</td><td>'.e($r['sent_at']?:'—').'</td></tr>';
        $body.='<section class="admin-panel admin-data-panel"><div class="admin-panel-head"><h2>收件人明细（脱敏）</h2></div><div class="table-wrap"><table><thead><tr><th>用户</th><th>邮箱快照</th><th>状态</th><th>安全原因</th><th>重试次数</th><th>SMTP接受时间</th></tr></thead><tbody>'.($rows?:'<tr><td colspan="6" class="admin-empty">没有收件人。</td></tr>').'</tbody></table></div>'.email_campaign_pager($recipients,'/admin/email-campaigns/'.(int)$c['id']).'</section>';
        page('群发任务进度',$body,$config);
    });
}

function email_campaign_id($id)
{
    if(!is_string($id)||!preg_match('/\A[1-9][0-9]{0,17}\z/D',$id)){http_response_code(404);exit('群发任务不存在。');}
    return (int)$id;
}

function email_campaign_error($error)
{
    return ($error instanceof InvalidArgumentException||($error instanceof RuntimeException&&!($error instanceof PDOException)))?$error->getMessage():'操作未完成，请刷新页面后重试。';
}

function email_unsubscribe_page($info,$token,$config,$error='')
{
    header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
    $body='<h1>群发邮件退订</h1><p>仅停止通知群发，不影响密码重置及下单、发货等交易邮件。</p>';
    if($error!=='')$body.='<div class="notice error">'.e($error).'</div>';
    if(!$info['valid']){$body.='<p>退订链接无效，请检查邮件中的完整链接。</p>';http_response_code(400);}
    elseif($info['unsubscribed'])$body.='<div class="notice success">您已退订通知群发，无需重复操作。</div>';
    else $body.='<p>打开此页面不会退订。请点击下方按钮确认。</p><form method="post" action="/email/unsubscribe?token='.e(rawurlencode($token)).'">'.csrf_field().'<input type="hidden" name="token" value="'.e($token).'"><button class="button" type="submit">确认退订通知群发</button></form>';
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>群发邮件退订 - '.e($config['app']['name']).'</title><link rel="stylesheet" href="/assets/style.css"></head><body><main class="container"><section class="card">'.$body.'<p><a href="/">返回首页</a></p></section></main></body></html>';
}

function register_email_unsubscribe_routes($router,$pdo,$config)
{
    $router->get('/email/unsubscribe',function()use($pdo,$config){$token=isset($_GET['token'])&&is_string($_GET['token'])?$_GET['token']:'';email_unsubscribe_page((new EmailCampaignService($pdo,$config))->unsubscribeInfo($token),$token,$config);});
    $router->post('/email/unsubscribe',function()use($pdo,$config){
        post_csrf();$token=isset($_GET['token'])&&is_string($_GET['token'])?$_GET['token']:'';$submitted=isset($_POST['token'])&&is_string($_POST['token'])?$_POST['token']:'';
        if($token===''||!hash_equals($token,$submitted)){http_response_code(400);return email_unsubscribe_page(array('valid'=>false,'unsubscribed'=>false),'',$config);}
        $service=new EmailCampaignService($pdo,$config);
        try{$done=$service->unsubscribe($token);email_unsubscribe_page(array('valid'=>$done,'unsubscribed'=>$done),$token,$config);}catch(Throwable $e){http_response_code(503);email_unsubscribe_page(array('valid'=>true,'unsubscribed'=>false),$token,$config,'暂时无法保存退订，请稍后重试。');}
    });
}
