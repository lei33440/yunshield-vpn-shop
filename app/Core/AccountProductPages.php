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
    $planDescription=$snapshot?(isset($row['plan_description_snapshot'])?$row['plan_description_snapshot']:''):(isset($row['public_description'])?$row['public_description']:'');
    return '<div class="account-public-spec"><p><b>平台：</b>'.e($row['account_platform'.$suffix]).'</p>'.($planDescription!==null&&$planDescription!==''?'<h3>所选方案介绍 / 备注</h3><p>'.nl2br(e($planDescription)).'</p>':'').'<h3>公开规格</h3><p>'.nl2br(e($row['account_specification'.$suffix])).'</p><h3>售后规则</h3><p>'.nl2br(e($row['account_after_sales'.$suffix])).'</p></div>';
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
    foreach ($plans as $i=>$plan) $rows .= '<div class="account-plan-row"><input type="hidden" name="plans['.$i.'][id]" value="'.(int)$plan['id'].'"><label>方案名称<input name="plans['.$i.'][name]" value="'.e($plan['name']).'" maxlength="100" required></label><label>价格（元）<input name="plans['.$i.'][price]" type="number" min="0.01" step="0.01" value="'.number_format($plan['price_cents']/100,2,'.','').'" required></label><label>状态<select name="plans['.$i.'][status]"><option value="active"'.($plan['status']==='active'?' selected':'').'>启用</option><option value="inactive"'.($plan['status']==='inactive'?' selected':'').'>下架</option></select></label><label>排序<input type="number" min="0" name="plans['.$i.'][sort_order]" value="'.(int)$plan['sort_order'].'"></label><label class="account-plan-description">本方案介绍 / 备注<textarea name="plans['.$i.'][public_description]" rows="3" placeholder="例如：美区账号、独享使用、支持更换绑定；请勿填写账号密码">'.e(isset($plan['public_description'])?$plan['public_description']:'').'</textarea><small>公开展示，选填；不同方案可填写不同说明，最多2000字。</small></label></div>';
    return '<div class="product-form-card account-product-form admin-catalog-editor"><div class="product-form-heading"><div><span class="admin-kicker">CATALOG / 海外账号</span><h1>'.e($product?'编辑海外账号':'新建海外账号').'</h1><p>维护商品公开信息与独立规格方案；账号密码等凭据请在订单交付时填写。</p></div><a class="secondary-button" href="/admin/accounts">返回海外账号</a></div><form method="post" action="'.e($action).'">'.csrf_field().'<input type="hidden" name="product_type" value="account"><div class="product-form-grid"><section class="product-form-section"><h2><span class="catalog-section-number">01</span>商品信息</h2><p class="field-help">所有规格共用的商品名称、介绍与公开说明。</p><label>商品名称<input name="name" maxlength="160" required value="'.e($p['name']).'"></label><label>介绍<textarea name="description" maxlength="5000" required>'.e($p['description']).'</textarea></label><label>平台<select name="account_platform" required>'.$platformOptions.'</select><a href="/admin/account-platforms">管理账号平台</a></label><label>公开规格<textarea name="account_specification" maxlength="10000" required aria-describedby="account-spec-help">'.e($p['account_specification']).'</textarea></label><p class="field-help" id="account-spec-help">每行填写一项公开规格，建议填写地区、账号类型、使用期限等，重要信息放前四行；勿填写账号密码等凭据。</p></section><section class="product-form-section"><h2><span class="catalog-section-number">02</span>售后与发布</h2><p class="field-help">每单一个账号，不参与优惠和拼团。仅销售合法来源且允许转让的商品。</p><label>售后规则<textarea name="account_after_sales" maxlength="10000" required>'.e($p['account_after_sales']).'</textarea></label><label>状态<select name="status"><option value="draft"'.($p['status']==='draft'?' selected':'').'>草稿</option><option value="active"'.($p['status']==='active'?' selected':'').'>上架</option><option value="inactive"'.($p['status']==='inactive'?' selected':'').'>下架</option></select></label><label>排序<input name="sort_order" type="number" min="0" value="'.(int)$p['sort_order'].'"></label></section></div><section class="product-form-section account-plans-section"><div class="plans-heading"><div><h2><span class="catalog-section-number">03</span>规格价格方案</h2><p>为不同账号规格设置价格和独立介绍，用户选择方案时会看到对应说明。</p></div><button type="button" class="secondary-button" id="account-plan-add">＋ 添加方案</button></div><div id="account-plan-list">'.$rows.'</div></section><div class="product-form-actions catalog-save-actions"><span>修改仅影响商品配置，历史订单保留购买时说明。</span><a class="secondary-button" href="/admin/accounts">返回列表</a><button class="button" type="submit">保存商品</button></div></form></div><script>(function(){var list=document.getElementById("account-plan-list"),index=list.children.length;document.getElementById("account-plan-add").onclick=function(){var row=list.children[0].cloneNode(true),i=index++;row.querySelectorAll("[name]").forEach(function(input){input.name=input.name.replace(/plans\[\d+\]/,"plans["+i+"]");if(input.name.endsWith("[id]"))input.value="0";else if(input.name.endsWith("[sort_order]"))input.value=String(i);else if(input.tagName==="SELECT")input.value="active";else input.value=""});list.appendChild(row)}})();</script>';
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
        $sort=isset($_GET['sort'])?$_GET['sort']:'default';
        $sorts=array('default'=>'p.sort_order,p.id','price_asc'=>'min_price ASC,p.sort_order,p.id','price_desc'=>'min_price DESC,p.sort_order,p.id');
        if(!is_string($sort)||!isset($sorts[$sort])) {
            http_response_code(400);return page('排序参数无效','<section class="empty service-ui-empty"><h1>无法使用此排序条件</h1><p>请返回商城选择页面中的排序方式。</p><a href="/accounts">返回海外账号商城</a></section>',$config);
        }
        $names=array();foreach($platforms as $item)$names[$item['code']]=$item['name'];
        $where='p.product_type="account" AND p.status="active"';$args=array();
        if($platform!==''){$where.=' AND p.account_platform=?';$args[]=$platform;}
        if($query!=='') {
            $match='%'.strtr($query,array('!'=>'!!','%'=>'!%','_'=>'!_')).'%';
            $searchWhere="p.name LIKE ? ESCAPE '!' OR p.description LIKE ? ESCAPE '!' OR p.account_specification LIKE ? ESCAPE '!'";
            array_push($args,$match,$match,$match);
            $matchingPlatforms=array();
            foreach($platforms as $item)if(mb_stripos($item['name'],$query,0,'UTF-8')!==false||mb_stripos($item['code'],$query,0,'UTF-8')!==false)$matchingPlatforms[]=$item['code'];
            if($matchingPlatforms){$searchWhere.=' OR p.account_platform IN ('.implode(',',array_fill(0,count($matchingPlatforms),'?')).')';$args=array_merge($args,$matchingPlatforms);}
            $where.=' AND ('.$searchWhere.')';
        }
        $s=$pdo->prepare('SELECT p.*,(SELECT MIN(price_cents) FROM product_plans WHERE product_id=p.id AND status="active") min_price FROM products p WHERE '.$where.' ORDER BY '.$sorts[$sort]);
        $s->execute($args);
        $catalogUrl=function($code,$term)use($sort){$params=array();if($code!=='')$params['platform']=$code;if($term!=='')$params['q']=$term;if($sort!=='default')$params['sort']=$sort;return '/accounts'.($params?'?'.http_build_query($params):'');};
        $clearUrl=$catalogUrl($platform,'');
        $cards='';$count=0;
        foreach($s as $p) {
            if($p['min_price']===null)continue;
            $count++;
            $platformName=isset($names[$p['account_platform']])?$names[$p['account_platform']]:$p['account_platform'];
            $code=strtolower($p['account_platform']);
            if($code==='google')$mark='<span class="ac-letter" aria-hidden="true">G</span>';
            elseif($code==='x'||$code==='twitter')$mark='<span class="ac-letter" aria-hidden="true">X</span>';
            else $mark=home_outline_icon($code==='apple'?'apple':($code==='telegram'?'send':(preg_match('/ai|gpt|claude|gemini/',$code)?'layers':'cube')));
            $caption=trim(preg_replace('/\s+/u',' ',(string)$p['description']));
            if($caption==='')$caption='查看账号规格与可选方案';
            $cards.='<article class="ep-card ac-card"><div class="ep-card-category">'.$mark.'<span title="'.e($platformName).'">'.e($platformName).'</span></div><div class="ep-card-title"><h2 title="'.e($p['name']).'">'.e($p['name']).'</h2></div><p class="ep-caption" title="'.e($caption).'">'.e(mb_substr($caption,0,80,'UTF-8')).'</p><p class="ac-card-meta"><span>账号商品</span><span>人工交付</span></p><a class="ep-button" href="/accounts/'.(int)$p['id'].'">查看商品 ↗</a></article>';
        }
        $platformNav='<nav class="ep-categories ac-platforms" aria-label="账号平台筛选"><a href="'.e($catalogUrl('',$query)).'"'.($platform===''?' aria-current="page"':'').'>全部平台</a>';
        foreach($platforms as $item)$platformNav.='<a href="'.e($catalogUrl($item['code'],$query)).'"'.($platform===$item['code']?' aria-current="page"':'').'>'.e($item['name']).'</a>';
        $platformNav.='</nav>';
        $search='<form class="ac-search" method="get" action="/accounts" role="search" aria-label="搜索平台或商品">'.($platform!==''?'<input type="hidden" name="platform" value="'.e($platform).'">':'').($sort!=='default'?'<input type="hidden" name="sort" value="'.e($sort).'">':'').'<button type="submit" aria-label="搜索平台或商品">'.home_outline_icon('search').'</button><input type="search" name="q" value="'.e($query).'" maxlength="100" aria-label="平台或商品关键词" placeholder="搜索平台或商品…"></form>';
        $sortForm='<form class="ep-sort ac-sort" method="get" action="/accounts">'.($platform!==''?'<input type="hidden" name="platform" value="'.e($platform).'">':'').($query!==''?'<input type="hidden" name="q" value="'.e($query).'">':'').'<select name="sort" aria-label="商品排序">';
        foreach(array('default'=>'默认排序','price_asc'=>'价格从低到高','price_desc'=>'价格从高到低') as $key=>$label)$sortForm.='<option value="'.$key.'"'.($sort===$key?' selected':'').'>'.$label.'</option>';
        $sortForm.='</select><button type="submit">应用</button></form>';
        $empty='<section class="ep-empty"><h2>'.($query!==''?'没有找到匹配的账号商品':($platform!==''?'当前平台暂无在售商品':'暂无可购买的账号商品')).'</h2><p>请尝试其他平台或关键词，稍后再来查看最新商品。</p><a href="'.e($query!==''?$clearUrl:'/accounts').'">'.($query!==''?'清除搜索':'查看全部平台').' →</a></section>';
        $appName=e($config['app']['name']);
        $body='<div class="ep-page ac-page"><nav class="ep-crumb" aria-label="面包屑"><a href="/">首页</a><span>/</span><span>海外账号</span></nav><header class="ep-intro"><div><span class="ep-kicker">OVERSEAS ACCOUNTS</span><h1><span>更多数字服务，</span><span>一个入口就够了。</span></h1><p>按平台挑选账号商品，查看方案与售后说明。</p></div><a href="#account-delivery">购买与交付说明 ↗</a></header>'
            .'<section class="ep-toolbar ac-toolbar" aria-label="账号商品筛选">'.$platformNav.'<div class="ep-tools">'.$search.$sortForm.'</div></section>'
            .'<p class="ep-results" role="status">共 '.$count.' 款商品 · 商品价格以具体方案为准。'.($query!==''?' 搜索：'.e($query).'　<a href="'.e($clearUrl).'">清除搜索</a>':'').'</p>'
            .'<div class="ep-grid ac-grid">'.($cards?:$empty).'</div>'
            .'<section class="ac-delivery" id="account-delivery"><h2>选好商品，<br>交付我们来处理。</h2><div><ol><li><span>01</span><b>选择商品与方案</b></li><li><span>02</span><b>下单并完成付款</b></li><li><span>03</span><b>等待管理员人工交付</b></li></ol><p>交付后，登录本人账号，在订单详情查看交付内容与使用说明。<br>每单1件；账号商品不参与优惠券、返佣与拼团活动。仅提供合法来源且允许转让的商品。</p></div></section>'
            .'<section class="ep-faq"><h2>购买前，<br>你可能想了解</h2><div><details><summary>付款后在哪里查看账号信息？</summary><p>交付完成后，请登录本人账号，在订单详情中主动显示或复制交付内容；邮件只作发货提醒，不直发账号密码。</p></details><details><summary>购买前需要确认哪些商品规格？</summary><p>请进入商品详情，确认所属平台、公开规格、可选方案及交付内容，再选择适合自己的方案。商品页不展示账号凭据。</p></details><details><summary>账号售后规则在哪里查看？</summary><p>商品详情的「售后与购买须知」展示该商品的具体售后规则。遇到问题可提交关联订单的工单，处理结果以实际订单状态为准。</p></details></div></section>'
            .'<section class="ep-help"><div><h2>需要帮助？</h2></div><a href="/articles">查看购买指南 ↗</a><a href="/tickets">提交咨询工单 ↗</a></section><footer class="ep-footer"><div><a href="/">'.home_outline_icon('cloud').'<span>'.$appName.'</span></a><p>数字服务，简单一点。</p></div><nav aria-label="页脚导航"><a href="/articles">帮助中心</a><a href="/announcements">系统公告</a></nav><p>© '.e(date('Y')).' '.$appName.' 版权所有</p></footer></div>'
            .'<script>(function(){var form=document.querySelector(".ac-sort");if(!form)return;form.classList.add("is-enhanced");form.querySelector("select").addEventListener("change",function(){form.requestSubmit();});})();</script>';
        page('海外账号',$body,$config);
    });
    $router->get('/accounts/{id}', function($id) use ($pdo,$config) {
        $p=false; if(account_catalog_ready($pdo)) { $s=$pdo->prepare('SELECT * FROM products WHERE id=? AND product_type="account" AND status="active"');$s->execute(array((int)$id));$p=$s->fetch(); }
        if(!$p){http_response_code(404);return page('商品不存在','<section class="empty service-ui-empty"><h1>商品暂不可查看</h1><p>商品不存在或已下架，请返回商城查看其他在售账号。</p><a class="button" href="/accounts">返回海外账号商城</a></section>',$config);}
        $s=$pdo->prepare('SELECT * FROM product_plans WHERE product_id=? AND status="active" ORDER BY sort_order,id');
        $s->execute(array($p['id']));
        $plans=$s->fetchAll();
        $first=$plans ? $plans[0] : null;
        $platformName=$p['account_platform'];
        foreach((new AccountPlatformService($pdo))->all() as $platform) {
            if($platform['code']===$p['account_platform']) { $platformName=$platform['name']; break; }
        }
        $options='';
        foreach($plans as $i=>$plan) {
            $options.='<label class="ed-choice"><input type="radio" name="plan_id" value="'.(int)$plan['id'].'" data-price="'.(int)$plan['price_cents'].'" data-name="'.e($plan['name']).'" data-description="'.e(isset($plan['public_description'])?$plan['public_description']:'').'"'.($i===0?' checked':'').' required><span class="ed-choice-copy"><b>'.e($plan['name']).'</b><small>单个账号 · 人工交付</small></span><strong class="ed-choice-price">'.e(money($plan['price_cents'])).'</strong></label>';
        }
        $loggedIn=!empty($_SESSION['user_id']);
        $firstCents=$first ? (int)$first['price_cents'] : 0;
        $buy=$first ? '<form class="ed-buy" id="ed-account-purchase" method="get" action="/order-confirm"><input type="hidden" name="product_id" value="'.(int)$p['id'].'"><input type="hidden" name="quantity" value="1"><h2>选择购买方案</h2><div class="ed-choices" role="group" aria-label="账号购买方案">'.$options.'</div><div class="ed-total"><span>方案价格</span><div class="ed-money"><span>¥</span><strong data-account-price-integer>'.intdiv($firstCents,100).'</strong><b data-account-price-fraction>.'.str_pad((string)($firstCents%100),2,'0',STR_PAD_LEFT).'</b></div></div><p class="ed-selected">数量 1件 · <span data-account-plan-name>'.e($first['name']).'</span></p><div class="ed-plan-description" data-account-plan-description'.(!isset($first['public_description'])||(string)$first['public_description']===''?' hidden':'').'>'.e(isset($first['public_description'])?$first['public_description']:'').'</div><p class="ed-quantity-note">每笔订单购买1件，可分开下单购买多个账号。</p><button class="ed-buy-button" type="submit">'.($loggedIn?'确认方案，继续下单 ↗':'登录后购买 ↗').'</button><p class="ed-login-note">'.($loggedIn?'核对方案后进入确认订单页。':'已有账号？请先登录后下单。').'</p><div class="ed-pay-notes"><ul><li>支付渠道以订单实际可用项为准。</li><li>余额不足时请选择可用在线渠道。</li><li>付款确认后由管理员人工交付。</li></ul><p>账号商品不参与优惠券、返佣与拼团活动。</p><a href="/tickets">购买前咨询 ↗</a></div></form>'
            : '<section class="ed-buy"><h2>暂无可购买方案</h2><p>此商品当前没有启用的方案，暂时无法下单。</p><a class="ed-back" href="/accounts">返回海外账号商城 ↗</a></section>';
        $appName=e($config['app']['name']);
        $body='<div class="ed-page ed-account-page"><nav class="ed-crumb" aria-label="面包屑"><a href="/">首页</a><span>/</span><a href="/accounts">海外账号</a><span>/</span><span>'.e($p['name']).'</span></nav>'
            .'<div class="ed-layout"><div class="ed-main"><header class="ed-hero"><span class="ed-kicker">OVERSEAS ACCOUNT · '.e($platformName).'</span><div class="ed-title"><h1>'.e($p['name']).'</h1><span>账号商品</span></div><p class="ed-tagline">先了解规格，再选择适合你的方案。</p><p class="ed-intro">购买前请确认账号规格、交付内容与售后规则，交付后在本人订单中查看账号信息。</p><p class="ed-account-reminder">仅提供合法来源且允许转让的商品。</p><div class="ed-stats"><div><strong>人工交付</strong></div><div><strong>订单内查看</strong></div><div><strong>每单1件</strong></div></div></header>'
            .'<nav class="ed-tabs" aria-label="商品内容"><a href="#account-specs" aria-current="location">商品详情</a><a href="#account-notes">购买须知</a><a href="#account-after-sales">售后说明</a></nav>'
            .'<section class="ed-specs" id="account-specs"><h2>商品规格</h2><div class="ed-table-wrap"><table><tbody><tr><th scope="row">所属平台</th><td>'.e($platformName).'</td></tr><tr><th scope="row">商品类型</th><td>数字账号</td></tr><tr><th scope="row">可选方案</th><td data-account-plan-name>'.($first?e($first['name']):'暂无可购买方案').'</td></tr><tr><th scope="row">规格说明</th><td>'.nl2br(e($p['account_specification'])).'</td></tr><tr><th scope="row">交付内容</th><td>账号信息与使用说明，以商品说明及实际交付为准。</td></tr><tr><th scope="row">交付方式</th><td>管理员人工交付</td></tr><tr><th scope="row">查看位置</th><td>登录后进入本人订单详情。</td></tr></tbody></table></div></section>'
            .(trim((string)$p['description'])!==''?'<section class="ed-description"><details><summary>商品介绍</summary><div class="ed-description-content">'.nl2br(e($p['description'])).'</div></details></section>':'')
            .'<section class="ed-delivery" id="account-notes"><h2>交付与使用说明</h2><ol><li><span>01</span><h3>选择方案并下单</h3><p>请确认商品说明后创建订单。</p></li><li><span>02</span><h3>付款后等待交付</h3><p>管理员人工处理，具体进度请查看订单。</p></li><li><span>03</span><h3>查看交付内容</h3><p>在本人订单中主动显示或复制账号信息。</p></li></ol><p class="ed-delivery-note">商品页不展示账号凭据；交付完成后，仅购买者登录可查看。邮件只作发货提醒，不直发账号密码。</p></section>'
            .'<section class="ed-account-after-sales" id="account-after-sales"><h2>售后与购买须知</h2><div class="ed-account-rules">'.nl2br(e($p['account_after_sales'])).'</div><ul><li>购买前请阅读当前商品的规格与售后规则。</li><li>遇到交付或使用问题，可提交关联订单的工单。</li><li>退款申请需填写原因，审核结果以订单状态为准。</li></ul></section></div>'
            .'<aside class="ed-aside">'.$buy.'<section class="ed-aside-help"><h2>需要帮助？</h2><a href="/articles">查看购买指南 ↗</a><a href="/tickets">提交咨询工单 ↗</a></section></aside></div>'
            .'<section class="ed-faq" id="account-faq"><h2>购买前，<br>你可能想了解</h2><div><details open><summary>付款后在哪里查看账号信息？</summary><p>交付完成后，登录本人账号，在订单详情中主动显示或复制交付内容。</p></details><details><summary>账号商品支持优惠券或拼团吗？</summary><p>账号商品每单1件，不参与优惠券、返佣或拼团活动。</p></details><details><summary>遇到问题如何申请售后？</summary><p>请阅读商品售后规则，并通过本人订单中的可用入口或关联订单工单申请，处理结果以实际订单状态为准。</p></details></div></section>'
            .'<a class="ed-back" href="/accounts">← 返回海外账号商城</a><footer class="ed-footer"><div><a href="/">'.home_outline_icon('cloud').'<span>'.$appName.'</span></a><p>数字服务，简单一点。</p></div><nav aria-label="页脚导航"><a href="/articles">帮助中心</a><a href="/announcements">系统公告</a></nav><p>© '.e(date('Y')).' '.$appName.' 版权所有</p></footer></div>';
        $body.='<script>(function(){var root=document.querySelector(".ed-account-page");if(!root)return;var form=root.querySelector("#ed-account-purchase");function sync(){if(!form)return;var radio=form.querySelector("input[name=plan_id]:checked");if(!radio)return;var cents=Number(radio.dataset.price);root.querySelectorAll("[data-account-plan-name]").forEach(function(el){el.textContent=radio.dataset.name;});root.querySelectorAll("[data-account-plan-description]").forEach(function(el){el.textContent=radio.dataset.description;el.hidden=!radio.dataset.description;});root.querySelector("[data-account-price-integer]").textContent=Math.floor(cents/100);root.querySelector("[data-account-price-fraction]").textContent="."+String(cents%100).padStart(2,"0");}if(form){form.addEventListener("change",function(event){if(event.target.matches("input[name=plan_id]"))sync();});sync();}var tabs=root.querySelectorAll(".ed-tabs a");tabs.forEach(function(tab){tab.addEventListener("click",function(){tabs.forEach(function(link){link.removeAttribute("aria-current");});tab.setAttribute("aria-current","location");});});})();</script>';
        page($p['name'],$body,$config);
    });
}
function register_account_platform_routes($router,$pdo,$config){
    $router->get('/admin/account-platforms',function()use($pdo,$config){Security::requireAdmin();$rows=(new AccountPlatformService($pdo))->all();$body=admin_heading('账号平台管理','维护海外账号商品的平台。已被商品引用的平台不能删除。','<a class="button" href="/admin/account-platforms/new">新建平台</a>');$body.='<div class="table-wrap"><table><thead><tr><th>平台名称</th><th>编码</th><th>排序</th><th>操作</th></tr></thead><tbody>';foreach($rows as $r)$body.='<tr><td>'.e($r['name']).'</td><td>'.e($r['code']).'</td><td>'.(int)$r['sort_order'].'</td><td><a href="/admin/account-platforms/'.(int)$r['id'].'/edit">编辑</a> <form method="post" action="/admin/account-platforms/'.(int)$r['id'].'/delete" style="display:inline" data-confirm="确认删除此平台？">'.csrf_field().'<button class="secondary-button">删除</button></form></td></tr>';if(!$rows)$body.='<tr><td colspan="4">暂无平台。</td></tr>';$body.='</tbody></table></div>';page('账号平台管理',$body,$config);});
    $form=function($r,$action){$editing=(bool)$r;$r=$r?:array('code'=>AccountPlatformService::generateCode(),'name'=>'','sort_order'=>0);return '<section class="product-form-card"><form method="post" action="'.e($action).'">'.csrf_field().'<label>平台编码（创建后不可修改）<input name="code" value="'.e($r['code']).'" required maxlength="32"'.($editing?' readonly':'').'></label>'.($editing?'':'<p class="muted">已默认随机生成平台编码，可直接使用；保存后不可修改。</p>').'<label>平台名称<input name="name" value="'.e($r['name']).'" required maxlength="100"></label><label>排序<input name="sort_order" type="number" min="0" value="'.(int)$r['sort_order'].'"></label><div class="product-form-actions"><a href="/admin/account-platforms">返回</a><button class="button">保存</button></div></form></section>';};
    $router->get('/admin/account-platforms/new',function()use($config,$form){Security::requireAdmin();page('新建账号平台',$form(null,'/admin/account-platforms/save'),$config);});
    $router->get('/admin/account-platforms/{id}/edit',function($id)use($pdo,$config,$form){Security::requireAdmin();$s=$pdo->prepare('SELECT * FROM account_platforms WHERE id=?');$s->execute(array((int)$id));$r=$s->fetch();if(!$r){http_response_code(404);return;}page('编辑账号平台',$form($r,'/admin/account-platforms/'.$r['id'].'/save'),$config);});
    $save=function($id=0)use($pdo){$aid=Security::requireAdmin();post_csrf();try{$id=(int)$id;(new AccountPlatformService($pdo))->save($id,$_POST,$aid);flash('success','账号平台已保存。');}catch(Exception $e){flash('error',$e->getMessage());}redirect('/admin/account-platforms');};$router->post('/admin/account-platforms/save',$save);$router->post('/admin/account-platforms/{id}/save',$save);
    $router->post('/admin/account-platforms/{id}/delete',function($id)use($pdo){$aid=Security::requireAdmin();post_csrf();try{(new AccountPlatformService($pdo))->delete((int)$id,$aid);flash('success','账号平台已删除。');}catch(Exception $e){flash('error',$e->getMessage());}redirect('/admin/account-platforms');});
}
