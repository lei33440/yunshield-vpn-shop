<?php

function email_template_labels()
{
    return array('password_reset'=>'找回密码','customer_order_created'=>'下单通知','customer_order_delivered'=>'发货通知');
}

function email_template_input()
{
    $input=array();
    foreach(array('subject','title','body','button_text','color') as $field){
        if(!isset($_POST[$field])||!is_string($_POST[$field]))throw new InvalidArgumentException('请填写完整的模板字段。');
        $input[$field]=$_POST[$field];
    }
    return $input;
}

function email_template_error($error)
{
    if($error instanceof RuntimeException&&$error->getMessage()==='email_template_version_conflict')return '模板已被其他管理员修改。当前编辑内容已保留，请另开页面核对最新版本后再编辑，不可覆盖保存。';
    return $error instanceof InvalidArgumentException?$error->getMessage():'模板操作暂不可用，请稍后重试；未发送邮件。';
}

function email_template_preview_html($html)
{
    // 预览只用于静态展示；移除链接与表单目标，不提供可导航的邮件链接。
    $html=preg_replace('/\s(?:href|target|action|formaction)\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i','',$html);
    $csp="default-src 'none'; style-src 'unsafe-inline'; img-src 'none'; font-src 'none'; connect-src 'none'; script-src 'none'; frame-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'";
    $meta='<meta http-equiv="Content-Security-Policy" content="'.e($csp).'"><meta name="referrer" content="no-referrer">';
    if(preg_match('/<head\b[^>]*>/i',$html))return preg_replace('/(<head\b[^>]*>)/i','$1'.$meta,$html,1);
    return $meta.$html;
}

function email_template_editor($template,$error='',$hasDraft=false)
{
    $key=$template['key'];$labels=email_template_labels();$c=$template['config'];
    $body=admin_heading('邮件模板','结构化编辑 · 服务端真实渲染 · 预览不发送','<a class="secondary-button" href="/admin/settings?tab=email">返回邮箱配置</a>');
    $body.='<nav class="admin-content-tabs" aria-label="邮件模板类型">';
    foreach($labels as $name=>$label)$body.='<a href="/admin/email-templates?template='.e($name).'"'.($key===$name?' class="selected" aria-current="page"':'').'>'.e($label).'</a>';
    $body.='</nav>';
    if($error!=='')$body.='<div class="notice error" role="alert">'.e($error).'</div>';
    $body.='<div class="admin-info-banner">找回密码邮件在申请时冻结内容；新下单及新版发货任务在发送时使用已保存模板。预览和未保存修改不影响实际邮件；群发邮件不受此处影响。</div><div class="email-template-layout"><section class="admin-panel email-template-editor"><h2>'.e($labels[$key]).' <small>版本 '.(int)$template['version'].'</small></h2><form id="email-template-form" method="post" action="/admin/email-templates/'.e($key).'/save">'.csrf_field().'<input type="hidden" name="version" value="'.(int)$template['version'].'">';
    foreach(array('subject'=>'邮件主题','title'=>'邮件标题','body'=>'正文内容','button_text'=>'按钮文字','color'=>'主题色') as $field=>$label){
        $limit=$field==='body'?20000:($field==='button_text'?80:200);
        $body.='<label>'.e($label);
        if($field==='body')$body.='<textarea name="body" rows="8" required maxlength="20000" data-byte-limit="20000">'.e($c[$field]).'</textarea><small>纯文本段落，换行会保留；不支持 HTML。右侧展示真实邮件排版。</small>';
        else $body.='<input name="'.e($field).'" type="'.($field==='color'?'color':'text').'" value="'.e($c[$field]).'" required'.($field==='color'?'':' maxlength="'.$limit.'" data-byte-limit="'.$limit.'"').'>';
        if($field!=='color')$body.='<small class="email-template-byte-count">'.strlen($c[$field]).' / '.$limit.' UTF-8 字节（中文通常每字 3 字节）</small>';
        $body.='</label>';
    }
    $body.='<fieldset class="email-template-variables"><legend>插入变量到当前文字字段</legend>';
    foreach($template['variables'] as $variable){$token=strpos($variable,'{{')===0?$variable:'{{'.$variable.'}}';$body.='<button type="button" class="secondary-button" data-template-variable="'.e($token).'">'.e($token).'</button>';}
    $body.='<small>只支持以上变量；按钮目标及交付地址由系统生成，不能在主题中插入敏感凭据。</small></fieldset><div class="product-form-actions"><button class="button" type="submit">保存模板</button><span id="email-template-dirty" aria-live="polite">'.($hasDraft?'已保留未保存修改':'当前为已保存版本').'</span></div></form><form method="post" action="/admin/email-templates/'.e($key).'/reset" class="email-template-reset">'.csrf_field().'<input type="hidden" name="version" value="'.(int)$template['version'].'"><label class="campaign-option"><input type="checkbox" name="confirm_reset" value="1" required> 确认恢复本类型内置默认模板，放弃未保存修改</label><button class="secondary-button" type="submit" data-confirm="确认恢复默认？此操作会替换已保存模板，不能撤销。">恢复默认模板</button></form></section><section class="admin-panel email-template-preview"><div class="admin-panel-head"><div><h2>即时邮件预览</h2><p>仅虚构 example.test 数据 · 不入队、不发信</p></div></div><div class="email-template-toolbar"><label>显示宽度<select id="email-template-device"><option value="desktop">桌面</option><option value="mobile">手机 · 375px</option></select></label><label>预览内容<select id="email-template-mode"><option value="html">HTML 邮件</option><option value="text">纯文本</option></select></label>';
    if($key==='customer_order_delivered')$body.='<label>交付示例<select id="email-template-variant"><option value="vpn">VPN 订阅交付</option><option value="account">账号 · 登录查看</option></select></label>';
    $body.='</div><p id="email-template-status" role="status" aria-live="polite">正在加载预览…</p><p class="email-template-subject">主题：<strong id="email-template-subject"></strong></p><div id="email-template-stage" class="email-template-stage"><iframe id="email-template-frame" title="安全邮件预览（链接不可点击）" sandbox="" referrerpolicy="no-referrer"></iframe><pre id="email-template-text" hidden></pre></div><noscript>即时预览需要 JavaScript；此处仅预览，不会发送邮件。</noscript></section></div><section class="admin-panel email-template-fixed"><h2>系统固定安全模块 · 不可删除</h2><p>密码重置按钮、备用链接、30 分钟有效 / 一次使用 / 非本人忽略提示均由系统保留；按钮地址不可自定义。</p><p>发货交付信息与敏感凭据勿转发提示由系统添加。VPN 正式订阅地址、使用说明、开通与到期时间可能随邮件发送；账号商品的账号密码仍须登录订单查看，不在邮件中直发。</p><p>修改交付不会新增邮件；已发送或 SMTP 在途邮件无法撤回，交付更新以订单详情为准。</p></section>';
    $endpoint=json_encode('/admin/email-templates/'.$key.'/preview',JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
    $body.='<script>(function(){"use strict";var form=document.getElementById("email-template-form"),status=document.getElementById("email-template-status"),frame=document.getElementById("email-template-frame"),text=document.getElementById("email-template-text"),mode=document.getElementById("email-template-mode"),device=document.getElementById("email-template-device"),variant=document.getElementById("email-template-variant"),dirty=document.getElementById("email-template-dirty"),target=form.elements.body,timer,serial=0,controller=null,changed='.($hasDraft?'true':'false').';function bytes(){form.querySelectorAll("[data-byte-limit]").forEach(function(field){var count=new TextEncoder().encode(field.value).length,limit=Number(field.dataset.byteLimit);field.setCustomValidity(count>limit?"文字超出 UTF-8 字节上限，请精简内容。":"");field.closest("label").querySelector(".email-template-byte-count").textContent=count+" / "+limit+" UTF-8 字节（中文通常每字 3 字节）"})}function clear(){frame.srcdoc="";text.textContent="";document.getElementById("email-template-subject").textContent=""}function preview(){var current=++serial;if(controller)controller.abort();controller=new AbortController();status.textContent="正在更新预览…";clear();var data=new FormData(form);data.delete("version");data.set("variant",variant?variant.value:"vpn");fetch('.$endpoint.',{method:"POST",body:data,credentials:"same-origin",cache:"no-store",signal:controller.signal,headers:{Accept:"application/json"}}).then(function(r){return r.json().then(function(d){if(!r.ok)throw new Error(d.error||"预览失败");return d})}).then(function(d){if(current!==serial)return;frame.srcdoc=d.html;text.textContent=d.text;document.getElementById("email-template-subject").textContent=d.subject;status.textContent="合成预览已更新 · 链接已禁用，不会发送邮件"}).catch(function(err){if(current!==serial||err.name==="AbortError")return;clear();status.textContent=err.message||"预览不可用，请重试"})}function schedule(){bytes();changed=true;dirty.textContent="有未保存修改";serial++;if(controller)controller.abort();clearTimeout(timer);clear();status.textContent="等待更新预览…";timer=setTimeout(preview,300)}form.querySelectorAll("input:not([type=hidden]),textarea").forEach(function(x){x.addEventListener("input",schedule);if(x.name!=="color")x.addEventListener("focus",function(){target=x})});document.querySelectorAll("[data-template-variable]").forEach(function(button){button.onclick=function(){target.focus();var start=target.selectionStart||0,end=target.selectionEnd||0;target.setRangeText(button.dataset.templateVariable,start,end,"end");schedule()}});mode.onchange=function(){frame.hidden=mode.value!=="html";text.hidden=mode.value!=="text"};device.onchange=function(){document.getElementById("email-template-stage").classList.toggle("is-mobile",device.value==="mobile")};if(variant)variant.onchange=function(){clearTimeout(timer);preview()};form.addEventListener("submit",function(){changed=false});document.querySelector(".email-template-reset").addEventListener("submit",function(){changed=false});window.addEventListener("beforeunload",function(e){if(changed){e.preventDefault();e.returnValue=""}});bytes();preview()})();</script>';
    return $body;
}

function register_admin_email_template_routes($router,$pdo,$config)
{
    $router->get('/admin/email-templates',function()use($pdo,$config){
        header('Cache-Control: no-store');Security::requireAdmin();
        $key=isset($_GET['template'])&&is_string($_GET['template'])?$_GET['template']:'password_reset';
        if(!isset(email_template_labels()[$key])){http_response_code(404);return page('模板不存在','<p>模板不存在。</p>',$config);}
        try{$template=(new EmailTemplateService($pdo,$config))->get($key);}catch(Throwable $error){http_response_code(503);return page('邮件模板暂不可用','<div class="notice error">模板读取失败，请确认模板表迁移已完成后重试。</div>',$config);}
        page('邮件模板',email_template_editor($template),$config);
    });
    $router->post('/admin/email-templates/{key}/preview',function($key)use($pdo,$config){
        header('Cache-Control: no-store');Security::requireAdmin();post_csrf();header('Content-Type: application/json; charset=utf-8');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
        try{
            if(!isset(email_template_labels()[$key]))throw new InvalidArgumentException('模板不存在。');
            foreach(array_keys($_POST) as $field)if(!in_array($field,array('_csrf','subject','title','body','button_text','color','variant'),true))throw new InvalidArgumentException('预览仅支持固定虚构示例，不接受真实订单、邮箱或 token。');
            if($_GET)throw new InvalidArgumentException('预览不接受查询参数。');
            $variant=isset($_POST['variant'])&&is_string($_POST['variant'])?$_POST['variant']:'vpn';
            if(!in_array($variant,array('vpn','account'),true))throw new InvalidArgumentException('无效示例。');
            $result=(new EmailTemplateService($pdo,$config))->preview($key,email_template_input(),$variant);
            echo json_encode(array('subject'=>$result['subject'],'html'=>email_template_preview_html($result['html']),'text'=>$result['text']),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        }catch(Throwable $error){http_response_code($error instanceof InvalidArgumentException?422:503);echo json_encode(array('error'=>email_template_error($error)),JSON_UNESCAPED_UNICODE);}
    });
    foreach(array('save','reset') as $action)$router->post('/admin/email-templates/{key}/'.$action,function($key)use($pdo,$config,$action){
        header('Cache-Control: no-store');$aid=Security::requireAdmin();post_csrf();
        if(!isset(email_template_labels()[$key])){http_response_code(404);return page('模板不存在','<p>模板不存在。</p>',$config);}
        $service=new EmailTemplateService($pdo,$config);$input=null;
        try{
            $version=isset($_POST['version'])&&is_string($_POST['version'])?$_POST['version']:null;
            if($action==='reset'){
                if(!isset($_POST['confirm_reset'])||$_POST['confirm_reset']!=='1')throw new InvalidArgumentException('请明确确认恢复默认模板。');
                $service->reset($key,$version,$aid);
            }else{$input=email_template_input();$service->save($key,$input,$version,$aid);}
            flash('success',$action==='reset'?'已恢复默认模板。未发送邮件。':'模板已保存。未发送邮件；新任务按生效规则使用此模板。');redirect('/admin/email-templates?template='.rawurlencode($key));
        }catch(Throwable $error){
            http_response_code($error instanceof RuntimeException&&$error->getMessage()==='email_template_version_conflict'?409:($error instanceof InvalidArgumentException?422:503));
            try{$template=$service->get($key);}catch(Throwable $readError){return page('邮件模板暂不可用','<div class="notice error">模板操作未完成，请稍后重试。</div>',$config);}
            if($input!==null){$template['config']=$input;$template['version']=isset($_POST['version'])&&is_string($_POST['version'])&&ctype_digit($_POST['version'])?(int)$_POST['version']:0;}
            page('邮件模板',email_template_editor($template,email_template_error($error),$input!==null),$config);
        }
    });
}
