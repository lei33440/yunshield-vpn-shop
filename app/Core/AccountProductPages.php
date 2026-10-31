<?php
// Account catalog shares checkout, payment and encrypted delivery with VPN orders.
function account_catalog_ready($pdo) {
    $required=array('products'=>array('product_type','account_platform','account_specification','account_after_sales'),'orders'=>array('product_type_snapshot','account_platform_snapshot','account_specification_snapshot','account_after_sales_snapshot','account_platform_name_snapshot'),'account_platforms'=>array('code','name','sort_order'));
    foreach($required as $table=>$columns){$present=array();try{$columnsResult=$pdo->query('SHOW COLUMNS FROM '.$table);}catch(PDOException $e){if($e->getCode()==='42S02')return false;throw $e;}foreach($columnsResult as $row)$present[$row['Field']]=true;foreach($columns as $column)if(!isset($present[$column]))return false;}
    return true;
}
function catalog_vpn_condition($pdo, $alias = '') {
    return ($alias ? $alias.'.' : '').'product_type="vpn"';
}
function order_vpn_condition($pdo, $alias = '') {
    return ($alias ? $alias.'.' : '').'product_type_snapshot="vpn"';
}
function catalog_type($row, $snapshot = false) {
    $key = $snapshot ? 'product_type_snapshot' : 'product_type';
    if(!isset($row[$key])||!in_array($row[$key],array('vpn','account'),true))throw new InvalidArgumentException('商品类型或订单类型快照缺失/未知。');
    return $row[$key];
}
function catalog_type_select($selected = '', $name = 'product_type') {
    return '<select name="'.e($name).'" aria-label="商品类型"><option value="">全部类型</option><option value="vpn"'.($selected==='vpn'?' selected':'').'>VPN 订阅</option><option value="account"'.($selected==='account'?' selected':'').'>海外账号</option></select>';
}
function order_plan_summary($row) {
    if (catalog_type($row, true) === 'account') return $row['plan_name_snapshot'].' · '.$row['account_platform_name_snapshot'].' · 单个账号';
    return $row['plan_name_snapshot'].' · '.(int)$row['duration_days_snapshot'].' 天';
}
function account_public_details($row, $snapshot = false) {
    $suffix = $snapshot ? '_snapshot' : '';
    return '<div class="account-public-spec"><p><b>平台：</b>'.e($row['account_platform'.$suffix]).'</p><h3>公开规格</h3><p>'.nl2br(e($row['account_specification'.$suffix])).'</p><h3>售后规则</h3><p>'.nl2br(e($row['account_after_sales'.$suffix])).'</p></div>';
}
function account_card_specification($specification) {
    $lines=array();
    foreach(preg_split('/\r\n|\r|\n/',(string)$specification) as $line){$line=trim($line);if($line!=='')$lines[]=$line;}
    if(!$lines)return '';
    $shortened=count($lines)>4;$items='';
    foreach(array_slice($lines,0,4) as $line){
        if(mb_strlen($line,'UTF-8')>32){$line=mb_substr($line,0,32,'UTF-8').'…';$shortened=true;}
        $items.='<li class="account-spec-tag">'.e($line).'</li>';
    }
    return '<div class="account-card-spec"><ul class="account-spec-tags" aria-label="关键规格">'.$items.'</ul>'.($shortened?'<p class="account-spec-note">规格已简略，完整内容见详情</p>':'').'</div>';
}
function account_delivery_fields($data = array()) {
    $value = function($key) use ($data) { return e(isset($data[$key]) ? $data[$key] : ''); };
    return '<p class="field-help">交付正文仅加密保存，只有本订单购买者可见。请勿将凭据写入商品、订单备注或公开说明。</p><label>账号交付内容<textarea name="account" required maxlength="20000">'.$value('account').'</textarea></label><label>开通时间（可空）<input type="datetime-local" name="opened_at" value="'.e(isset($data['opened_at']) ? str_replace(' ','T',(string)$data['opened_at']) : '').'"></label><label>到期时间（可空）<input type="datetime-local" name="expires_at" value="'.e(isset($data['expires_at']) ? str_replace(' ','T',(string)$data['expires_at']) : '').'"></label><label>使用说明（可选）<textarea name="instructions">'.$value('instructions').'</textarea></label>';
}
function account_delivery_display($data) {
    $html = '<div class="delivery account-delivery"><h2>账号交付内容</h2><pre class="account-delivery-text">'.e(isset($data['account'])?$data['account']:'').'</pre>';
    foreach (array('opened_at'=>'开通时间','expires_at'=>'到期时间') as $key=>$label) if (!empty($data[$key])) $html .= '<p><b>'.$label.'：</b>'.e($data[$key]).'</p>';
    if (!empty($data['instructions'])) $html .= '<p><b>使用说明：</b>'.nl2br(e($data['instructions'])).'</p>';
    return $html.'</div>';
}
function account_product_form($product, $action, $title, $pdo = null) {
    if($pdo===null)$pdo=$GLOBALS['pdo'];
    $platforms=(new AccountPlatformService($pdo))->all();
    $defaultPlatform=$platforms ? $platforms[0]['code'] : '';
    $p = $product ?: array('name'=>'','description'=>'','status'=>'draft','sort_order'=>0,'account_platform'=>$defaultPlatform,'account_specification'=>'','account_after_sales'=>'');
    $plans = isset($p['plans']) && $p['plans'] ? $p['plans'] : array(array('id'=>0,'name'=>'标准规格','price_cents'=>100,'status'=>'active','sort_order'=>0));
    $platformOptions='';foreach($platforms as $platform)$platformOptions.='<option value="'.e($platform['code']).'"'.($p['account_platform']===$platform['code']?' selected':'').'>'.e($platform['name']).'</option>';
    if(!$platforms)$platformOptions='<option value="">请先创建账号平台</option>';
    $rows = '';
    foreach ($plans as $i=>$plan) $rows .= '<div class="account-plan-row"><input type="hidden" name="plans['.$i.'][id]" value="'.(int)$plan['id'].'"><label>方案名称<input name="plans['.$i.'][name]" value="'.e($plan['name']).'" maxlength="100" required></label><label>价格（元）<input name="plans['.$i.'][price]" type="number" min="0.01" step="0.01" value="'.number_format($plan['price_cents']/100,2,'.','').'" required></label><label>状态<select name="plans['.$i.'][status]"><option value="active"'.($plan['status']==='active'?' selected':'').'>启用</option><option value="inactive"'.($plan['status']==='inactive'?' selected':'').'>下架</option></select></label><label>排序<input type="number" min="0" name="plans['.$i.'][sort_order]" value="'.(int)$plan['sort_order'].'"></label></div>';
    return '<div class="product-form-card account-product-form"><h1>'.e($title).'</h1><p>海外账号 · 创建后类型不可变 · 每单一个 · 不参与优惠和拼团。仅销售合法来源且允许转让的商品。</p><form method="post" action="'.e($action).'">'.csrf_field().'<input type="hidden" name="product_type" value="account"><label>商品名称<input name="name" maxlength="160" required value="'.e($p['name']).'"></label><label>介绍<textarea name="description" maxlength="5000" required>'.e($p['description']).'</textarea></label><label>平台<select name="account_platform" required>'.$platformOptions.'</select><a href="/admin/account-platforms">管理账号平台</a></label><label>公开规格<textarea name="account_specification" maxlength="10000" required aria-describedby="account-spec-help">'.e($p['account_specification']).'</textarea></label><p class="field-help" id="account-spec-help">每行填写一项公开规格，建议填写地区、账号类型、使用期限等，重要信息放前四行；勿填写账号密码等凭据。</p><label>售后规则<textarea name="account_after_sales" maxlength="10000" required>'.e($p['account_after_sales']).'</textarea></label><label>状态<select name="status"><option value="draft"'.($p['status']==='draft'?' selected':'').'>草稿</option><option value="active"'.($p['status']==='active'?' selected':'').'>上架</option><option value="inactive"'.($p['status']==='inactive'?' selected':'').'>下架</option></select></label><label>排序<input name="sort_order" type="number" min="0" value="'.(int)$p['sort_order'].'"></label><h2>规格价格方案</h2><div id="account-plan-list">'.$rows.'</div><button type="button" class="secondary-button" id="account-plan-add">添加方案</button><div class="product-form-actions"><a href="/admin/products">返回列表</a><button class="button">保存商品</button></div></form></div><script>(function(){var list=document.getElementById("account-plan-list"),index=list.children.length;document.getElementById("account-plan-add").onclick=function(){var row=list.children[0].cloneNode(true),i=index++;row.querySelectorAll("[name]").forEach(function(input){input.name=input.name.replace(/plans\[\d+\]/,"plans["+i+"]");if(input.name.endsWith("[id]"))input.value="0";else if(input.tagName!=="SELECT")input.value=""});list.appendChild(row)}})();</script>';
}
function validate_catalog_publication($pdo, $p, $status) {
    $type=catalog_type($p);if(!in_array($type,array('vpn','account'),true))throw new InvalidArgumentException('未知商品类型。');
    if($type==='account'){(new AccountPlatformService($pdo))->requireCode($p['account_platform'],$pdo->inTransaction());if(trim($p['account_specification'])===''||trim($p['account_after_sales'])==='')throw new InvalidArgumentException('账号商品的平台、公开规格及售后规则必填。');}
    if($type==='vpn'&&(trim((string)$p['traffic'])===''||trim((string)$p['region'])===''||(int)$p['device_limit']<1))throw new InvalidArgumentException('VPN商品流量、线路及设备数必填。');
    if($status==='active'){$s=$pdo->prepare('SELECT * FROM product_plans WHERE product_id=? AND status="active"');$s->execute(array($p['id']));$plans=$s->fetchAll();if(!$plans)throw new InvalidArgumentException('上架至少需要一个启用方案。');foreach($plans as $plan)if((int)$plan['price_cents']<1||($type==='vpn'&&(int)$plan['duration_days']<1)||($type==='account'&&$plan['duration_days']!==null))throw new InvalidArgumentException('方案价格或类型专属字段无效。');}
}
function account_catalog_routes($router, $pdo, $config) {
    $router->get('/accounts', function() use ($pdo,$config) {
        if (!account_catalog_ready($pdo)) {http_response_code(503);return page('海外账号','<section class="empty service-ui-empty"><h1>海外账号商城暂未开放</h1><p>当前暂时无法浏览账号商品，请稍后再来。</p><a class="secondary-button" href="/">返回商城首页</a></section>',$config);}
        $platform = isset($_GET['platform']) ? $_GET['platform'] : '';if(!is_string($platform)){$platform='__invalid__';}$platform=trim($platform);$platforms=(new AccountPlatformService($pdo))->all();$valid=array('');foreach($platforms as $item)$valid[]=$item['code'];if(!in_array($platform,$valid,true)){http_response_code(400);return page('平台无效','<section class="empty service-ui-empty"><h1>无法使用此平台筛选</h1><p>请返回账号商城，选择页面中列出的平台。</p><a class="button" href="/accounts">查看全部账号商品</a></section>',$config);}
        $query=array_key_exists('q',$_GET)?$_GET['q']:'';
        if(!is_string($query)||!mb_check_encoding($query,'UTF-8')||mb_strlen(trim($query),'UTF-8')>100){
            http_response_code(400);return page('搜索参数无效','<section class="empty service-ui-empty"><h1>无法使用此搜索条件</h1><p>请输入100字以内的搜索关键词。</p><a class="button" href="/accounts">返回海外账号商城</a></section>',$config);
        }
        $query=trim($query);
        $where = 'product_type="account" AND status="active"'; $args = array();
        if ($platform !== '') { $where .= ' AND account_platform=?'; $args[] = $platform; }
        if($query!==''){
            $match='%'.strtr($query,array('!'=>'!!','%'=>'!%','_'=>'!_')).'%';
            $where.=" AND (p.name LIKE ? ESCAPE '!' OR p.description LIKE ? ESCAPE '!' OR p.account_specification LIKE ? ESCAPE '!')";
            array_push($args,$match,$match,$match);
        }
        $s=$pdo->prepare('SELECT p.*,(SELECT MIN(price_cents) FROM product_plans WHERE product_id=p.id AND status="active") min_price FROM products p WHERE '.$where.' ORDER BY sort_order,id'); $s->execute($args);
        $names=array();foreach($platforms as $item)$names[$item['code']]=$item['name'];$cards=''; foreach($s as $p) { if ($p['min_price']===null) continue; $cards.='<article class="account-catalog-card"><span class="eyebrow">'.e(isset($names[$p['account_platform']])?$names[$p['account_platform']]:$p['account_platform']).' · 海外账号</span><h2>'.e($p['name']).'</h2>'.account_card_specification($p['account_specification']).'<p class="account-card-description">'.nl2br(e($p['description'])).'</p><div class="account-card-footer"><strong>'.money($p['min_price']).' 起</strong><a class="button" href="/accounts/'.(int)$p['id'].'">查看详情</a></div></article>'; }
        $catalogUrl=function($code,$term){$params=array();if($code!=='')$params['platform']=$code;if($term!=='')$params['q']=$term;return '/accounts'.($params?'?'.http_build_query($params):'');};
        $clearUrl=$catalogUrl($platform,'');
        $search='<form class="account-catalog-search" method="get" action="/accounts" role="search" aria-label="搜索账号商品"><label for="account-search">搜索账号商品</label>'.($platform!==''?'<input type="hidden" name="platform" value="'.e($platform).'">':'').'<div class="account-search-controls"><input id="account-search" type="search" name="q" value="'.e($query).'" maxlength="100" placeholder="搜索账号名称、介绍或规格"><button class="button" type="submit">搜索</button>'.($query!==''?'<a class="account-search-clear" href="'.e($clearUrl).'">清除搜索</a>':'').'</div></form>';
        $bodyPlatform='<nav class="account-platform-filter" aria-label="账号平台筛选"><a href="'.e($catalogUrl('',$query)).'"'.($platform===''?' aria-current="page"':'').'>全部平台</a>';
        $platformLabel=$platform!==''?$names[$platform]:'选择平台';
        $bodyPlatform.='<details class="account-platform-picker"><summary'.($platform!==''?' class="is-selected"':'').'><span>'.e($platformLabel).'</span><span class="account-platform-chevron" aria-hidden="true">⌄</span></summary><div class="account-platform-options" aria-label="选择账号平台"><div class="account-platform-search"><input type="search" placeholder="搜索平台分组" aria-label="搜索平台分组" autocomplete="off"></div><div class="account-platform-list">';
        foreach($platforms as $item)$bodyPlatform.='<a href="'.e($catalogUrl($item['code'],$query)).'"'.($platform===$item['code']?' aria-current="page"':'').'>'.e($item['name']).'</a>';
        if(!$platforms)$bodyPlatform.='<p class="muted">暂无可选平台</p>';
        $bodyPlatform.='</div><p class="account-platform-no-match muted" role="status" hidden>没有找到匹配的平台</p></div></details></nav><script>(function(){var picker=document.querySelector(".account-platform-picker"),search=picker.querySelector("input"),links=picker.querySelectorAll(".account-platform-list a"),empty=picker.querySelector(".account-platform-no-match");function filter(){var term=search.value.trim().toLocaleLowerCase(),count=0;links.forEach(function(link){link.hidden=link.textContent.toLocaleLowerCase().indexOf(term)===-1;if(!link.hidden)count++});empty.hidden=!links.length||count>0}search.addEventListener("input",filter);picker.addEventListener("toggle",function(){if(picker.open){search.focus()}else{search.value="";filter()}});document.addEventListener("click",function(event){if(!picker.contains(event.target))picker.open=false});picker.addEventListener("keydown",function(event){if(event.key==="Escape"){picker.open=false;picker.querySelector("summary").focus()}})})();</script>';
        $empty=$query!==''?'<section class="empty service-ui-empty"><h2>没有找到匹配的账号商品</h2><p>请尝试其他关键词，或清除搜索后查看当前平台商品。</p><div class="product-form-actions"><a class="button" href="'.e($clearUrl).'">清除搜索</a><a class="secondary-button" href="/accounts">查看全部平台</a></div></section>':'<section class="empty service-ui-empty"><h2>'.($platform!==''?'当前平台暂无在售商品':'暂无可购买的账号商品').'</h2><p>可以查看其他平台，或稍后再来浏览。</p><div class="product-form-actions"><a class="button" href="/accounts">查看全部平台</a><a class="secondary-button" href="/">返回商城首页</a></div></section>';
        page('海外账号','<div class="account-catalog"><header class="ux-page-head"><div><h1>海外账号商城</h1><p>公开规格与售后规则清晰可见，付款确认后人工加密交付。</p></div></header>'.$search.$bodyPlatform.'<p class="muted">仅提供合法来源且允许转让的商品。每单一个账号，不参与优惠券、返佣或拼团。</p><div class="account-catalog-grid">'.($cards?:$empty).'</div></div>',$config);
    });
    $router->get('/accounts/{id}', function($id) use ($pdo,$config) {
        $p=false; if(account_catalog_ready($pdo)) { $s=$pdo->prepare('SELECT * FROM products WHERE id=? AND product_type="account" AND status="active"');$s->execute(array((int)$id));$p=$s->fetch(); }
        if(!$p){http_response_code(404);return page('商品不存在','<section class="empty service-ui-empty"><h1>商品暂不可查看</h1><p>商品不存在或已下架，请返回商城查看其他在售账号。</p><a class="button" href="/accounts">返回海外账号商城</a></section>',$config);}
        $s=$pdo->prepare('SELECT * FROM product_plans WHERE product_id=? AND status="active" ORDER BY sort_order,id');$s->execute(array($p['id']));$options='';foreach($s as $i=>$plan)$options.='<label class="plan-option"><input type="radio" name="plan_id" value="'.(int)$plan['id'].'"'.($i===0?' checked':'').' required><span>'.e($plan['name']).'</span><strong>'.money($plan['price_cents']).'</strong></label>';
        page($p['name'],'<div class="account-product-detail"><nav class="account-product-back" aria-label="商品详情导航"><a href="/accounts">← 返回选购</a></nav><header class="ux-page-head"><div><h1>'.e($p['name']).'</h1><p>海外账号 · 公开规格 · 人工加密交付</p></div></header><div class="account-product-layout"><section class="account-product-overview"><h2>商品介绍</h2><p>'.nl2br(e($p['description'])).'</p>'.account_public_details($p).'</section><aside class="account-product-purchase"><h2>选择购买方案</h2><p>每单一个账号，付款确认后人工加密交付。日期以实际交付内容为准。账号密码仅限登录本人订单查看，邮件只作发货提醒，不直发账号密码。</p>'.($options?'<form method="get" action="/order-confirm"><input type="hidden" name="product_id" value="'.(int)$p['id'].'"><div class="plan-picker" role="group" aria-label="选择购买方案">'.$options.'</div><button class="button">确认所选方案</button></form>':'<section class="empty service-ui-empty"><h2>暂无可购买方案</h2><p>此商品当前没有可选方案，暂时无法下单。可以返回商城查看其他商品。</p><a class="button" href="/accounts">返回海外账号商城</a></section>').'</aside></div></div>',$config);
    });
}
function register_account_platform_routes($router,$pdo,$config){
    $router->get('/admin/account-platforms',function()use($pdo,$config){Security::requireAdmin();$rows=(new AccountPlatformService($pdo))->all();$body=admin_heading('账号平台管理','维护海外账号商品的平台。已被商品引用的平台不能删除。','<a class="button" href="/admin/account-platforms/new">新建平台</a>');$body.='<div class="table-wrap"><table><thead><tr><th>平台名称</th><th>编码</th><th>排序</th><th>操作</th></tr></thead><tbody>';foreach($rows as $r)$body.='<tr><td>'.e($r['name']).'</td><td>'.e($r['code']).'</td><td>'.(int)$r['sort_order'].'</td><td><a href="/admin/account-platforms/'.(int)$r['id'].'/edit">编辑</a> <form method="post" action="/admin/account-platforms/'.(int)$r['id'].'/delete" style="display:inline" data-confirm="确认删除此平台？">'.csrf_field().'<button class="secondary-button">删除</button></form></td></tr>';if(!$rows)$body.='<tr><td colspan="4">暂无平台。</td></tr>';$body.='</tbody></table></div>';page('账号平台管理',$body,$config);});
    $form=function($r,$action){$r=$r?:array('code'=>'','name'=>'','sort_order'=>0);return '<section class="product-form-card"><form method="post" action="'.e($action).'">'.csrf_field().'<label>平台编码（创建后不可修改）<input name="code" value="'.e($r['code']).'" required maxlength="32"'.($r['code']?' readonly':'').'></label><label>平台名称<input name="name" value="'.e($r['name']).'" required maxlength="100"></label><label>排序<input name="sort_order" type="number" min="0" value="'.(int)$r['sort_order'].'"></label><div class="product-form-actions"><a href="/admin/account-platforms">返回</a><button class="button">保存</button></div></form></section>';};
    $router->get('/admin/account-platforms/new',function()use($config,$form){Security::requireAdmin();page('新建账号平台',$form(null,'/admin/account-platforms/save'),$config);});
    $router->get('/admin/account-platforms/{id}/edit',function($id)use($pdo,$config,$form){Security::requireAdmin();$s=$pdo->prepare('SELECT * FROM account_platforms WHERE id=?');$s->execute(array((int)$id));$r=$s->fetch();if(!$r){http_response_code(404);return;}page('编辑账号平台',$form($r,'/admin/account-platforms/'.$r['id'].'/save'),$config);});
    $save=function($id=0)use($pdo){$aid=Security::requireAdmin();post_csrf();try{$id=(int)$id;(new AccountPlatformService($pdo))->save($id,$_POST,$aid);flash('success','账号平台已保存。');}catch(Exception $e){flash('error',$e->getMessage());}redirect('/admin/account-platforms');};$router->post('/admin/account-platforms/save',$save);$router->post('/admin/account-platforms/{id}/save',$save);
    $router->post('/admin/account-platforms/{id}/delete',function($id)use($pdo){$aid=Security::requireAdmin();post_csrf();try{(new AccountPlatformService($pdo))->delete((int)$id,$aid);flash('success','账号平台已删除。');}catch(Exception $e){flash('error',$e->getMessage());}redirect('/admin/account-platforms');});
}
