<?php

function admin_heading($title, $subtitle, $action = '')
{
    return '<div class="admin-page-heading"><div><h1>'.e($title).'</h1><p>'.e($subtitle).'</p></div>'.$action.'</div>';
}

function admin_hub_card($href, $title, $description, $icon, $tone)
{
    return '<a class="admin-hub-card" href="'.e($href).'"><span class="admin-stat-icon '.$tone.'">'.admin_icon($icon).'</span><h2>'.e($title).'</h2><p>'.e($description).'</p><em>进入管理 →</em></a>';
}

function lottery_prize_input(){
    $code=trim(isset($_POST['prize_code'])?$_POST['prize_code']:'');$name=trim(isset($_POST['name'])?$_POST['name']:'');$type=isset($_POST['prize_type'])?$_POST['prize_type']:'';$amount=trim(isset($_POST['value'])?$_POST['value']:'0');$minAmount=trim(isset($_POST['min_amount'])?$_POST['min_amount']:'0');$validDays=filter_var(isset($_POST['valid_days'])?$_POST['valid_days']:'30',FILTER_VALIDATE_INT);$quantity=filter_var(isset($_POST['quantity'])?$_POST['quantity']:'-1',FILTER_VALIDATE_INT);$weight=filter_var(isset($_POST['weight'])?$_POST['weight']:'0',FILTER_VALIDATE_INT);$sort=filter_var(isset($_POST['sort_order'])?$_POST['sort_order']:'0',FILTER_VALIDATE_INT);$status=isset($_POST['status'])?$_POST['status']:'active';
    if(!preg_match('/^[A-Za-z0-9_-]{1,80}$/',$code)||$name===''||strlen($name)>160||!in_array($type,array('coupon'),true)||!preg_match('/^\\d+(?:\\.\\d{1,2})?$/',$amount)||!preg_match('/^\\d+(?:\\.\\d{1,2})?$/',$minAmount)||$validDays===false||$validDays<1||$validDays>365||$quantity===false||($quantity!==-1&&$quantity<0)||$weight===false||$weight<0||$sort===false||$sort<0||!in_array($status,array('active','inactive'),true))throw new InvalidArgumentException('请检查奖品编码、名称、类型、金额、使用门槛、有效期、库存和权重。');
    $parts=explode('.',$amount);$cents=((int)$parts[0])*100+(int)str_pad(isset($parts[1])?$parts[1]:'',2,'0');$parts=explode('.',$minAmount);$minCents=((int)$parts[0])*100+(int)str_pad(isset($parts[1])?$parts[1]:'',2,'0');return array($code,$name,$type,$cents,$minCents,$validDays,$quantity,$weight,$sort,$status);
}
function lottery_prize_form($row,$action,$title){$r=$row?:array('prize_code'=>'','name'=>'','prize_type'=>'coupon','value_cents'=>0,'min_amount_cents'=>0,'valid_days'=>30,'quantity'=>-1,'remaining_quantity'=>-1,'weight'=>0,'sort_order'=>0,'status'=>'active');return '<div class="product-form-card"><div class="product-form-heading"><div><h1>'.e($title).'</h1><p>奖品库存为 -1 表示不限量；已发放奖品不可将库存调低到已发放数量以下。</p></div><a class="secondary-button" href="/admin/events/lottery">返回抽奖配置</a></div><form method="post" action="'.e($action).'">'.csrf_field().'<div class="product-form-grid"><div class="product-form-section"><h2>奖品信息</h2><label>奖品编码<input name="prize_code" maxlength="80" pattern="[A-Za-z0-9_-]+" value="'.e($r['prize_code']).'" required></label><label>奖品名称<input name="name" maxlength="160" value="'.e($r['name']).'" required></label><label>奖品类型<select name="prize_type"><option value="coupon"'.($r['prize_type']==='coupon'?' selected':'').'>优惠券</option></select></label><label>优惠金额（元）<input type="text" name="value" inputmode="decimal" value="'.e(number_format((int)$r['value_cents']/100,2,'.','')).'" required></label><label>使用门槛（元）<input type="text" name="min_amount" inputmode="decimal" value="'.e(number_format((int)$r['min_amount_cents']/100,2,'.','')).'" required></label><label>有效期（天）<input type="number" name="valid_days" min="1" max="365" value="'.(int)$r['valid_days'].'" required></label></div><div class="product-form-section"><h2>奖池设置</h2><label>总库存（-1 为不限）<input type="number" name="quantity" min="-1" value="'.(int)$r['quantity'].'" required></label><label>抽奖权重<input type="number" name="weight" min="0" value="'.(int)$r['weight'].'" required></label><label>排序<input type="number" name="sort_order" min="0" value="'.(int)$r['sort_order'].'" required></label><label>状态<select name="status"><option value="active"'.($r['status']==='active'?' selected':'').'>启用</option><option value="inactive"'.($r['status']==='inactive'?' selected':'').'>停用</option></select></label></div></div><div class="product-form-actions"><a class="secondary-button" href="/admin/events/lottery">取消</a><button class="button" type="submit">保存奖品</button></div></form></div>';}

function update_check_panel($config)
{
    try {
        $service = new UpdateService($config);
        $result = $service->cachedResult();
        $current = $service->currentVersion();
    } catch (Exception $e) {
        $result = array('status'=>'error','message'=>'本地版本信息暂不可用。','current_version'=>'未知','latest_version'=>'','release_name'=>'','published_at'=>'','release_url'=>'','has_update'=>false,'checked_at'=>'');
        $current = '未知';
    }
    $status = $result ? (isset($result['message']) ? $result['message'] : '已检测') : '尚未检测';
    $tone = $result && isset($result['has_update']) && $result['has_update'] ? 'pending_payment' : 'delivered';
    $latest = $result && !empty($result['latest_version']) ? e($result['latest_version']) : '—';
    $checked = $result && !empty($result['checked_at']) ? e(date('Y-m-d H:i', strtotime($result['checked_at']))) : '—';
    $link = $result && !empty($result['release_url']) ? '<a class="secondary-button" target="_blank" rel="noopener" href="'.e($result['release_url']).'">查看 Release</a>' : '';
    $prepare = $result && !empty($result['has_update']) && !empty($result['assets_ready']) && !empty($config['update']['install_enabled']) ? '<form method="post" action="/admin/system/update/prepare" style="display:inline">'.csrf_field().'<button class="button" type="submit">下载并验证更新</button></form>' : '';
    $statusPanel = update_status_panel($config);
    return '<section class="admin-panel admin-settings-panel"><div class="admin-panel-head"><div><h2>项目更新</h2><p>检测 GitHub 新版本；在线安装前会校验签名，不会自动迁移数据库。</p></div><span class="admin-status '.e($tone).'">'.e($status).'</span></div><div class="admin-settings-fields"><label>当前版本<input value="'.e($current).'" readonly></label><label>GitHub 最新版本<input value="'.$latest.'" readonly></label><label>最近检测时间<input value="'.$checked.'" readonly></label></div><div class="product-form-actions">'.$link.'<form method="post" action="/admin/system/update/check" style="display:inline">'.csrf_field().'<button class="button" type="submit">立即检测</button></form>'.$prepare.'</div>'.$statusPanel.'</section>';
}

function update_admin_log($pdo, $adminId, $action, $details = array())
{
    $pdo->prepare('INSERT INTO admin_logs(admin_id,action,target_type,target_id,details_json,created_at) VALUES(?,?,?,?,?,?)')->execute(array((int)$adminId, $action, 'system_update', null, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), date('Y-m-d H:i:s')));
}

function update_status_panel($config)
{
    try {
        $status = (new UpdateService($config))->status();
    } catch (Exception $e) {
        $status = null;
    }
    if (!$status) return '<div class="admin-module-empty">暂无待处理更新任务或备份。</div>';
    $state = isset($status['status']) ? $status['status'] : 'unknown';
    $labels = array('verified'=>'已验证，等待安装','installed'=>'已安装','failed'=>'安装失败','preparing'=>'准备中');
    $label = isset($labels[$state]) ? $labels[$state] : $state;
    $action='';
    if($state==='verified')$action='<form method="post" action="/admin/system/update/install" style="margin-top:12px">'.csrf_field().'<input type="hidden" name="update_id" value="'.e($status['id']).'"><label><input type="checkbox" name="confirm_install" value="1" required> 我已备份数据库，确认安装此更新</label><button class="button" type="submit">安装更新</button></form>';
    return '<div class="admin-info-banner">最近更新任务：'.e($label).'，目标版本 '.e(isset($status['version'])?$status['version']:'未知').'。'.(!empty($status['error'])?' '.e($status['error']):'').$action.'</div>';
}

function register_admin_extra_routes($router, $pdo, $config)
{
    $router->get('/admin/search', function() use ($pdo, $config) {
        Security::requireAdmin();
        $q=isset($_GET['q'])?trim($_GET['q']):'';
        $body=admin_heading('搜索结果',$q!==''?'搜索：'.$q:'输入关键词搜索订单、用户或订阅套餐');
        if($q===''){page('搜索结果',$body.'<div class="admin-info-banner">请输入搜索关键词。</div>',$config);return;}
        $pattern='%'.$q.'%';
        $orders=$pdo->prepare('SELECT order_no,product_name_snapshot FROM orders WHERE order_no LIKE ? OR product_name_snapshot LIKE ? ORDER BY id DESC LIMIT 12');
        $orders->execute(array($pattern,$pattern));
        $users=$pdo->prepare('SELECT email,display_name FROM users WHERE email LIKE ? OR display_name LIKE ? ORDER BY id DESC LIMIT 12');
        $users->execute(array($pattern,$pattern));
        $products=$pdo->prepare('SELECT id,name FROM products WHERE name LIKE ? OR region LIKE ? ORDER BY id DESC LIMIT 12');
        $products->execute(array($pattern,$pattern));
        $groups=array('订单'=>array(),'用户'=>array(),'订阅套餐'=>array());
        foreach($orders as $item)$groups['订单'][]='<a href="/admin/orders/'.e($item['order_no']).'"><b>'.e($item['order_no']).'</b><small>'.e($item['product_name_snapshot']).'</small></a>';
        foreach($users as $item)$groups['用户'][]='<a href="/admin/orders?q='.rawurlencode($item['email']).'"><b>'.e($item['display_name']!==''?$item['display_name']:$item['email']).'</b><small>'.e($item['email']).'</small></a>';
        foreach($products as $item)$groups['订阅套餐'][]='<a href="/admin/products/'.$item['id'].'/edit"><b>'.e($item['name']).'</b><small>编辑套餐</small></a>';
        $found=false;$body.='<div class="admin-hub-grid">';
        foreach($groups as $label=>$items){if($items)$found=true;$body.='<section class="admin-panel admin-search-group"><div class="admin-panel-head"><div><h2>'.e($label).'</h2><p>'.count($items).' 条匹配结果</p></div></div>'.($items?implode('',$items):'<div class="admin-module-empty">无匹配结果</div>').'</section>';}
        $body.='</div>';
        if(!$found)$body='<div class="admin-info-banner">没有找到匹配的订单、用户或套餐。</div>'.$body;
        page('搜索结果',$body,$config);
    });

    $router->get('/admin', function() use ($pdo, $config) {
        Security::requireAdmin();
        admin_dashboard($pdo, $config);
    });

    $router->get('/admin/products', function() use ($pdo, $config) {
        Security::requireAdmin();
        $q = isset($_GET['q']) ? trim($_GET['q']) : '';
        $status = isset($_GET['status']) ? $_GET['status'] : '';
        $where = array('1=1'); $params = array();
        if ($q !== '') { $where[] = '(p.name LIKE ? OR p.region LIKE ? OR p.traffic LIKE ?)'; $params = array('%'.$q.'%','%'.$q.'%','%'.$q.'%'); }
        if (in_array($status,array('active','inactive','draft'),true)) { $where[]='p.status=?'; $params[]=$status; }
        $statement = $pdo->prepare('SELECT p.*, (SELECT MIN(price_cents) FROM product_plans WHERE product_id=p.id AND status="active") AS min_price, (SELECT COUNT(*) FROM orders o WHERE o.product_id=p.id AND o.status="delivered") AS subscription_count FROM products p WHERE '.implode(' AND ',$where).' ORDER BY p.sort_order,p.id DESC');
        $statement->execute($params);
        $cards = '';
        foreach ($statement as $product) {
            $id=(int)$product['id'];
            $state=$product['status']==='active'?'销售中':($product['status']==='inactive'?'已下架':'草稿');
            $action=$product['status']==='active'?'下架':'上架';
            $price=$product['min_price']===null ? (int)$product['price_cents'] : (int)$product['min_price'];
            $cards.='<article class="admin-product-card"><div class="admin-product-card-top"><span class="admin-product-symbol">'.e(mb_strtoupper(mb_substr($product['name'],0,1,'UTF-8'),'UTF-8')).'</span><span class="admin-status product-'.e($product['status']).'">'.$state.'</span></div><h3>'.e($product['name']).'</h3><small>'.e($product['region']).'</small><div class="admin-product-price"><strong>'.e(money($price)).'</strong><span>起</span></div><dl><div><dt>覆盖线路</dt><dd>'.e($product['region']).'</dd></div><div><dt>每月流量</dt><dd>'.e($product['traffic']).'</dd></div><div><dt>同时在线</dt><dd>'.(int)$product['device_limit'].' 台</dd></div><div><dt>已交付订单</dt><dd>'.(int)$product['subscription_count'].'</dd></div></dl><div class="admin-product-actions"><a href="/admin/products/'.$id.'/edit">编辑</a><form method="post" action="/admin/products/'.$id.'/toggle">'.csrf_field().'<button type="submit">'.$action.'</button></form></div></article>';
        }
        if ($cards==='') $cards='<div class="admin-info-banner">暂无符合条件的订阅套餐。可以新建套餐后在此管理。</div>';
        $active=(int)admin_scalar($pdo,'SELECT COUNT(*) FROM products WHERE status="active"');
        $inactive=(int)admin_scalar($pdo,'SELECT COUNT(*) FROM products WHERE status="inactive"');
        $subscribers=(int)admin_scalar($pdo,'SELECT COUNT(DISTINCT user_id) FROM orders WHERE status="delivered"');
        $monthlyRevenue=(int)admin_scalar($pdo,'SELECT COALESCE(SUM(total_amount_cents),0) FROM orders WHERE paid_at>=? AND status IN ("pending_delivery","delivered","refunding")',array(date('Y-m-01').' 00:00:00'));
        $body=admin_heading('订阅套餐','管理前台销售的订阅产品、价格和权益','<a class="button" href="/admin/products/create">＋ 新建订阅套餐</a>');
        $body.='<section class="admin-stats admin-list-stats">'.admin_metric('在售套餐',(string)$active,$inactive.' 个已下架','products','violet').admin_metric('订阅用户',(string)$subscribers,'已交付用户','users','blue').admin_metric('套餐月收入',money($monthlyRevenue),'本月实收','finance','green').'</section>';
        $body.='<div class="admin-panel-head admin-product-list-heading"><div><h2>订阅套餐列表</h2><p>管理售价、线路数量、流量与设备限制</p></div></div><form class="admin-product-filter" method="get"><input name="q" value="'.e($q).'" placeholder="搜索套餐名称、线路或流量"><select name="status"><option value="">全部状态</option><option value="active"'.($status==='active'?' selected':'').'>销售中</option><option value="inactive"'.($status==='inactive'?' selected':'').'>已下架</option><option value="draft"'.($status==='draft'?' selected':'').'>草稿</option></select><button class="button">筛选</button></form><div class="admin-product-grid">'.$cards.'</div>';
        page('订阅套餐',$body,$config);
    });

    $router->get('/admin/orders', function() use ($pdo, $config) {
        Security::requireAdmin();
        $status=isset($_GET['status'])?$_GET['status']:'';
        $q=isset($_GET['q'])?trim($_GET['q']):'';
        $from=isset($_GET['from'])?trim($_GET['from']):'';
        $to=isset($_GET['to'])?trim($_GET['to']):'';
        $min=isset($_GET['min_amount'])?trim($_GET['min_amount']):'';
        $max=isset($_GET['max_amount'])?trim($_GET['max_amount']):'';
        $allowed=array('pending_payment','pending_delivery','delivered','cancelled','refunding','refunded');
        $where=array('1=1');$params=array();
        if($status==='paid')$where[]='o.status IN ("pending_delivery","delivered")';
        elseif(in_array($status,$allowed,true)){$where[]='o.status=?';$params[]=$status;}
        if($q!==''){$where[]='(o.order_no LIKE ? OR o.product_name_snapshot LIKE ? OR u.email LIKE ?)';array_push($params,'%'.$q.'%','%'.$q.'%','%'.$q.'%');}
        if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)){$where[]='o.created_at>=?';$params[]=$from.' 00:00:00';}
        if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)){$where[]='o.created_at<=?';$params[]=$to.' 23:59:59';}
        if(is_numeric($min)){$where[]='o.total_amount_cents>=?';$params[]=(int)round($min*100);}
        if(is_numeric($max)){$where[]='o.total_amount_cents<=?';$params[]=(int)round($max*100);}
        $statement=$pdo->prepare('SELECT o.*,u.email,u.display_name,(SELECT method FROM payment_records pr WHERE pr.order_id=o.id ORDER BY pr.id DESC LIMIT 1) AS payment_method FROM orders o JOIN users u ON u.id=o.user_id WHERE '.implode(' AND ',$where).' ORDER BY o.id DESC LIMIT 200');
        $statement->execute($params);$rows='';
        foreach($statement as $order){
            $can=in_array($order['status'],array('pending_payment','pending_delivery','refunding'),true);
            $name=$order['display_name']!==''?$order['display_name']:$order['email'];
            $method=$order['payment_method']==='alipay'?'支付宝':($order['payment_method']==='wechat'?'微信支付':($order['payment_method']==='manual'?'人工确认':'—'));
            $rows.='<tr><td><input type="checkbox" name="order_ids[]" value="'.(int)$order['id'].'"'.($can?'':' disabled').'></td><td><a href="/admin/orders/'.e($order['order_no']).'">'.e($order['order_no']).'</a></td><td><span class="admin-user-cell"><i>'.e(mb_substr($name,0,1,'UTF-8')).'</i><span><b>'.e($name).'</b><small class="table-sub">'.e($order['email']).'</small></span></span></td><td>'.e($order['product_name_snapshot']).'</td><td><b>'.e(money($order['total_amount_cents'])).'</b><small class="table-sub">'.e(date('m-d H:i',strtotime($order['created_at']))).'</small></td><td>'.e($method).'</td><td><span class="admin-status '.e($order['status']).'">'.e(status_text($order['status'])).'</span></td><td><a href="/admin/orders/'.e($order['order_no']).'">详情 →</a></td></tr>';
        }
        if($rows==='')$rows='<tr><td colspan="8" class="admin-empty">没有找到符合条件的订单。</td></tr>';
        $today=date('Y-m-d').' 00:00:00';
        $todayAmount=(int)admin_scalar($pdo,'SELECT COALESCE(SUM(total_amount_cents),0) FROM orders WHERE created_at>=?',array($today));
        $todayCount=(int)admin_scalar($pdo,'SELECT COUNT(*) FROM orders WHERE created_at>=?',array($today));
        $waiting=(int)admin_scalar($pdo,'SELECT COUNT(*) FROM orders WHERE status="pending_payment"');
        $waitingAmount=(int)admin_scalar($pdo,'SELECT COALESCE(SUM(total_amount_cents),0) FROM orders WHERE status="pending_payment"');
        $refunds=(int)admin_scalar($pdo,'SELECT COUNT(*) FROM orders WHERE status="refunding"');
        $refundAmount=(int)admin_scalar($pdo,'SELECT COALESCE(SUM(total_amount_cents),0) FROM orders WHERE status="refunding"');
        $body=admin_heading('订单管理','查看付款、续费、退款与异常订单');
        $body.='<nav class="admin-content-tabs"><a href="/admin/orders"'.($status===''?' class="selected"':'').'>全部订单</a><a href="/admin/orders?status=paid"'.($status==='paid'?' class="selected"':'').'>已支付</a><a href="/admin/orders?status=pending_payment"'.($status==='pending_payment'?' class="selected"':'').'>待支付</a><a href="/admin/orders?status=refunding"'.($status==='refunding'?' class="selected"':'').'>退款中</a></nav>';
        $body.='<section class="admin-stats admin-list-stats">'.admin_metric('今日订单金额',money($todayAmount),$todayCount.' 笔订单','finance','violet').admin_metric('待支付',(string)$waiting,'金额 '.money($waitingAmount),'orders','blue').admin_metric('退款申请',(string)$refunds,'待处理 '.money($refundAmount),'finance','amber').'</section>';
        $body.='<section class="admin-panel admin-data-panel"><div class="admin-panel-head"><div><h2>订单列表</h2><p>按条件筛选并处理订单</p></div></div><form class="admin-order-filter" method="get"><input name="q" value="'.e($q).'" placeholder="搜索订单、用户或套餐"><select name="status"><option value="">全部状态</option><option value="paid"'.($status==='paid'?' selected':'').'>已支付</option><option value="pending_payment"'.($status==='pending_payment'?' selected':'').'>待支付</option><option value="pending_delivery"'.($status==='pending_delivery'?' selected':'').'>待发货</option><option value="delivered"'.($status==='delivered'?' selected':'').'>已发货</option><option value="refunding"'.($status==='refunding'?' selected':'').'>退款中</option><option value="refunded"'.($status==='refunded'?' selected':'').'>已退款</option><option value="cancelled"'.($status==='cancelled'?' selected':'').'>已取消</option></select><input type="date" name="from" value="'.e($from).'" title="开始日期"><input type="date" name="to" value="'.e($to).'" title="结束日期"><input type="number" step="0.01" min="0" name="min_amount" value="'.e($min).'" placeholder="最低金额"><input type="number" step="0.01" min="0" name="max_amount" value="'.e($max).'" placeholder="最高金额"><button class="button">筛选</button></form>';
        $body.='<form method="post" action="/admin/orders/batch">'.csrf_field().'<div class="admin-order-batch"><label><input type="checkbox" id="check-all"> 全选可处理订单</label><select name="action" required><option value="">批量操作</option><option value="confirm_payment">确认付款</option><option value="cancel">取消订单</option><option value="refund_approve">同意退款</option><option value="refund_reject">拒绝退款</option></select><input name="reason" maxlength="500" placeholder="取消或退款处理说明"><button class="button">执行</button></div><div class="table-wrap"><table><thead><tr><th></th><th>订单号</th><th>用户</th><th>订阅套餐</th><th>金额</th><th>支付方式</th><th>状态</th><th>操作</th></tr></thead><tbody>'.$rows.'</tbody></table></div></form></section><script>var all=document.getElementById("check-all");if(all)all.onclick=function(){document.querySelectorAll("input[name=\"order_ids[]\"]:not(:disabled)").forEach(function(x){x.checked=all.checked})}</script>';
        page('订单管理',$body,$config);
    });

    $router->get('/admin/subscriptions', function() use ($pdo, $config) {
        Security::requireAdmin();
        $tab=isset($_GET['status'])&&in_array($_GET['status'],array('all','active','expiring','expired'),true)?$_GET['status']:'all';
        $q=isset($_GET['q'])?trim($_GET['q']):'';
        $where=array('o.status="delivered"');$params=array();
        if($tab==='active')$where[]='(d.expires_at IS NULL OR d.expires_at>=NOW())';
        elseif($tab==='expiring')$where[]='(d.expires_at>=NOW() AND d.expires_at<DATE_ADD(NOW(), INTERVAL 7 DAY))';
        elseif($tab==='expired')$where[]='(d.expires_at<NOW())';
        if($q!==''){$where[]='(o.order_no LIKE ? OR o.product_name_snapshot LIKE ? OR u.email LIKE ?)';$params=array('%'.$q.'%','%'.$q.'%','%'.$q.'%');}
        $rows='';
        $statement=$pdo->prepare('SELECT o.order_no,o.product_name_snapshot,o.status,o.delivered_at,u.email,u.display_name,d.expires_at FROM orders o JOIN users u ON u.id=o.user_id LEFT JOIN delivery_records d ON d.order_id=o.id AND d.is_current=1 WHERE '.implode(' AND ',$where).' ORDER BY o.delivered_at DESC LIMIT 100');
        $statement->execute($params);
        foreach($statement as $item){
            $expires=$item['expires_at'] ? date('Y-m-d',strtotime($item['expires_at'])) : '未设置';
            $state=$item['expires_at'] && strtotime($item['expires_at'])<time() ? '已到期' : ($item['expires_at'] && strtotime($item['expires_at'])<time()+7*86400 ? '即将到期':'有效');
            $name=$item['display_name']!==''?$item['display_name']:$item['email'];
            $rows.='<tr><td><span class="admin-user-cell"><i>'.e(mb_substr($name,0,1,'UTF-8')).'</i><span><b>'.e($name).'</b><small class="table-sub">'.e($item['email']).'</small></span></span></td><td>'.e($item['product_name_snapshot']).'</td><td>'.e($item['order_no']).'</td><td>'.e($expires).'</td><td>—</td><td><span class="admin-status '.($state==='有效'?'delivered':($state==='即将到期'?'pending_payment':'')).'">'.$state.'</span></td><td><a href="/admin/orders/'.e($item['order_no']).'">管理 →</a></td></tr>';
        }
        if($rows==='')$rows='<tr><td colspan="7" class="admin-empty">暂无符合条件的订阅。</td></tr>';
        $active=(int)admin_scalar($pdo,'SELECT COUNT(DISTINCT o.id) FROM orders o JOIN delivery_records d ON d.order_id=o.id AND d.is_current=1 WHERE o.status="delivered" AND (d.expires_at IS NULL OR d.expires_at>=NOW())');
        $body=admin_heading('订阅管理','管理用户订阅、地址签发与使用状态','<a class="button" href="/admin/orders?status=pending_delivery">处理待交付订单</a>');
        $body.='<nav class="admin-content-tabs"><a href="/admin/subscriptions?status=all"'.($tab==='all'?' class="selected"':'').'>全部订阅</a><a href="/admin/subscriptions?status=active"'.($tab==='active'?' class="selected"':'').'>有效</a><a href="/admin/subscriptions?status=expiring"'.($tab==='expiring'?' class="selected"':'').'>即将到期</a><a href="/admin/subscriptions?status=expired"'.($tab==='expired'?' class="selected"':'').'>已到期</a></nav>';
        $body.='<section class="admin-panel admin-data-panel"><div class="admin-panel-head"><div><h2>用户订阅</h2><p>共 '.$active.' 个有效订阅</p></div></div><form class="admin-product-filter admin-table-filter" method="get"><input type="hidden" name="status" value="'.e($tab).'"><input name="q" value="'.e($q).'" placeholder="搜索用户、订单或套餐"><button class="button">搜索</button></form><div class="table-wrap"><table><thead><tr><th>用户</th><th>套餐</th><th>关联订单</th><th>到期日期</th><th>流量使用</th><th>状态</th><th>操作</th></tr></thead><tbody>'.$rows.'</tbody></table></div></section>';
        page('订阅管理',$body,$config);
    });

    $router->get('/admin/users', function() use ($pdo, $config) {
        Security::requireAdmin();
        $q=isset($_GET['q'])?trim($_GET['q']):'';
        $where='';$params=array();
        if($q!==''){$where='WHERE u.email LIKE ? OR u.display_name LIKE ?';$params=array('%'.$q.'%','%'.$q.'%');}
        $statement=$pdo->prepare('SELECT u.id,u.email,u.display_name,u.status,u.created_at,COUNT(o.id) AS order_count,COUNT(DISTINCT CASE WHEN o.status="delivered" THEN o.id END) AS subscriptions,COALESCE(SUM(CASE WHEN o.status IN ("pending_delivery","delivered") THEN o.total_amount_cents ELSE 0 END),0) AS spent FROM users u LEFT JOIN orders o ON o.user_id=u.id '.$where.' GROUP BY u.id ORDER BY u.id DESC LIMIT 100');
        $statement->execute($params);$rows='';
        foreach($statement as $user)$rows.='<tr><td><span class="admin-user-cell"><i>'.e(mb_substr($user['display_name']!==''?$user['display_name']:$user['email'],0,1,'UTF-8')).'</i><span><b>'.e($user['display_name']!==''?$user['display_name']:'未设置昵称').'</b><small class="table-sub">'.e($user['email']).'</small></span></span></td><td>'.(int)$user['subscriptions'].'</td><td>'.(int)$user['order_count'].'</td><td>'.e(money($user['spent'])).'</td><td><span class="admin-status '.($user['status']==='active'?'delivered':'').'">'.($user['status']==='active'?'正常':e($user['status'])).'</span></td><td><a href="/admin/orders?q='.rawurlencode($user['email']).'">查看订单 →</a></td></tr>';
        if($rows==='')$rows='<tr><td colspan="6" class="admin-empty">没有找到用户。</td></tr>';
        $total=(int)admin_scalar($pdo,'SELECT COUNT(*) FROM users');
        $paying=(int)admin_scalar($pdo,'SELECT COUNT(DISTINCT user_id) FROM orders WHERE status IN ("pending_delivery","delivered")');
        $newToday=(int)admin_scalar($pdo,'SELECT COUNT(*) FROM users WHERE created_at>=?',array(date('Y-m-d').' 00:00:00'));
        $body=admin_heading('用户管理','查看用户资料、会员状态与账户行为');
        $body.='<section class="admin-stats admin-list-stats">'.admin_metric('总用户数',number_format($total),'已注册用户','users','violet').admin_metric('付费用户',number_format($paying),($total?round($paying/$total*100,1):0).'% 付费率','finance','green').admin_metric('今日新增',(string)$newToday,'今日注册用户','users','blue').'</section>';
        $body.='<section class="admin-panel admin-data-panel"><div class="admin-panel-head"><div><h2>用户列表</h2><p>注册用户与会员资料</p></div></div><form class="admin-product-filter admin-table-filter" method="get"><input name="q" value="'.e($q).'" placeholder="搜索用户邮箱或昵称"><button class="button">搜索</button></form><div class="table-wrap"><table><thead><tr><th>用户</th><th>有效订阅</th><th>订单</th><th>累计消费</th><th>账户状态</th><th>操作</th></tr></thead><tbody>'.$rows.'</tbody></table></div></section>';
        page('用户管理',$body,$config);
    });

    $router->get('/admin/tickets', function() use ($pdo, $config) {
        Security::requireAdmin();
        $open=(int)admin_scalar($pdo,'SELECT COUNT(*) FROM support_tickets WHERE status="open"');
        $waiting=(int)admin_scalar($pdo,'SELECT COUNT(*) FROM support_tickets WHERE status="waiting_customer"');
        $closed=(int)admin_scalar($pdo,'SELECT COUNT(*) FROM support_tickets WHERE status="closed"');
        $rows='';
        foreach($pdo->query('SELECT t.*,u.email,u.display_name FROM support_tickets t JOIN users u ON u.id=t.user_id ORDER BY t.id DESC LIMIT 100') as $ticket){
            $state=$ticket['status']==='closed'?'已关闭':($ticket['status']==='waiting_customer'?'等待用户':'待回复');
            $rows.='<tr><td><a href="/admin/tickets/'.(int)$ticket['id'].'">'.e($ticket['ticket_no']).'</a></td><td>'.e($ticket['display_name']!==''?$ticket['display_name']:$ticket['email']).'<small class="table-sub">'.e($ticket['email']).'</small></td><td>'.e($ticket['subject']).'</td><td><span class="admin-status '.($ticket['status']==='closed'?'delivered':'pending_payment').'">'.$state.'</span></td><td>'.e($ticket['updated_at']).'</td><td><a href="/admin/tickets/'.(int)$ticket['id'].'">处理 →</a></td></tr>';
        }
        if($rows==='')$rows='<tr><td colspan="6" class="admin-empty">暂无售后工单。</td></tr>';
        $body=admin_heading('售后工单','统一处理用户咨询、问题和售后请求');
        $body.='<section class="admin-stats admin-list-stats">'.admin_metric('待回复',(string)$open,'尽快处理用户问题','tickets','violet').admin_metric('等待用户',(string)$waiting,'已回复待用户反馈','users','blue').admin_metric('已关闭',(string)$closed,'历史已完成工单','tickets','green').'</section>';
        $body.='<section class="admin-panel admin-data-panel"><div class="admin-panel-head"><div><h2>工单列表</h2><p>最近 100 条售后记录</p></div></div><div class="table-wrap"><table><thead><tr><th>工单编号</th><th>用户</th><th>主题</th><th>状态</th><th>更新时间</th><th>操作</th></tr></thead><tbody>'.$rows.'</tbody></table></div></section>';
        page('售后工单',$body,$config);
    });

    $router->get('/admin/charity', function() use ($pdo, $config) {
        Security::requireAdmin();
        $count=(int)admin_scalar($pdo,'SELECT COUNT(*) FROM articles WHERE category LIKE "%公益%" OR title LIKE "%公益%"');
        $body=admin_heading('公益订阅','维护定时轮换的免费订阅地址');
        $body.='<section class="admin-charity-hero"><span class="admin-status">尚未接入</span><h2>公益订阅源未配置</h2><p>当前服务器尚无公益订阅源与自动轮换数据。</p><div class="admin-charity-address">配置订阅源后，当前地址会显示在这里</div></section>';
        $body.='<section class="admin-stats admin-list-stats">'.admin_metric('下次自动轮换','—','未配置轮换策略','charity','violet').admin_metric('当前可用线路','—','未接入线路监控','subscriptions','blue').admin_metric('今日获取次数','—','暂无统计数据','users','green').'</section>';
        $body.='<div class="admin-hub-grid"><section class="admin-panel"><div class="admin-panel-head"><div><h2>订阅源配置</h2><p>轮换时从可用订阅源中选择</p></div></div><div class="admin-module-empty">尚未接入公益订阅源管理</div><a class="admin-module-link" href="/admin/articles">管理公益说明内容 →</a></section><section class="admin-panel"><div class="admin-panel-head"><div><h2>轮换策略</h2><p>公益订阅自动更新规则</p></div></div><div class="admin-module-empty">自动轮换尚未启用</div><a class="admin-module-link" href="/admin/temporary-subscription">查看现有临时订阅配置 →</a></section><section class="admin-panel"><div class="admin-panel-head"><div><h2>轮换记录</h2><p>公益订阅更新历史</p></div></div><div class="admin-module-empty">暂无轮换记录</div></section><section class="admin-panel"><div class="admin-panel-head"><div><h2>公益内容</h2><p>前台可见的公益相关说明</p></div></div><div class="admin-module-empty">当前有 '.$count.' 篇相关内容</div><a class="admin-module-link" href="/admin/articles">进入文章管理 →</a></section></div>';
        page('公益订阅',$body,$config);
    });

    $router->get('/admin/events', function() use ($pdo, $config) {
        Security::requireAdmin();
        $service=new ActivityService($pdo);
        $modules=array('lottery'=>array('幸运抽奖','每日抽奖与奖品库存'),'referral'=>array('拉人返佣','邀请关系与人工提现'),'group'=>array('好友拼单','三人成团与折扣规则'),'leader'=>array('团长免单','三名有效邀请领取指定套餐'));
        $cards='';foreach($modules as $key=>$module){$row=$service->setting($key);$status=$row&&$row['status']==='enabled'?'已启用':'未启用';$cards.='<article class="admin-event-card"><span class="admin-stat-icon violet">'.admin_icon('events').'</span><span class="admin-status">'.$status.'</span><h2>'.e($module[0]).'</h2><p>'.e($module[1]).'</p><div class="admin-event-value">'.e($service->config($key)['name']??$module[0]).' <small>运营配置</small></div><a href="/admin/events/'.e($key).'">配置活动 →</a></article>';}
        $draws=(int)admin_scalar($pdo,'SELECT COUNT(*) FROM lottery_draws WHERE created_at>=?',array(date('Y-m-d').' 00:00:00'));
        $withdrawals=(int)admin_scalar($pdo,'SELECT COUNT(*) FROM withdrawal_requests WHERE status="pending"');
        $groups=(int)admin_scalar($pdo,'SELECT COUNT(*) FROM group_campaigns WHERE status="forming"');
        $leaders=(int)admin_scalar($pdo,'SELECT COUNT(*) FROM leader_rewards WHERE status="awarded"');
        $body=admin_heading('活动运营','配置抽奖、返佣、拼单与团长免单活动');
        $body.='<div class="admin-event-grid">'.$cards.'</div><section class="admin-stats admin-list-stats">'.admin_metric('今日抽奖',(string)$draws,'参与次数','events','violet').admin_metric('待审核提现',(string)$withdrawals,'人工处理','finance','amber').admin_metric('进行中拼团',(string)$groups,'待成团','events','blue').admin_metric('已发放免单',(string)$leaders,'团长奖励','events','green').'</section>';
        $body.='<section class="admin-panel"><div class="admin-panel-head"><div><h2>活动公告</h2><p>通过文章管理发布活动规则和公告。</p></div><a href="/admin/articles/create">发布活动公告 →</a></div><div class="admin-module-empty">请在文章分类中选择“活动公告”。</div></section>';
        page('活动运营',$body,$config);
    });
    $router->get('/admin/events/lottery/prizes/create', function() use ($config){Security::requireAdmin();page('新增抽奖奖品',lottery_prize_form(null,'/admin/events/lottery/prizes/create','新增抽奖奖品'),$config);});
    $router->post('/admin/events/lottery/prizes/create', function() use ($pdo,$config){$aid=Security::requireAdmin();post_csrf();try{$r=lottery_prize_input();$a=(new ActivityService($pdo))->setting('lottery');if(!$a)throw new RuntimeException('抽奖活动不存在。');$now=date('Y-m-d H:i:s');$pdo->prepare('INSERT INTO lottery_prizes(activity_id,prize_code,name,prize_type,value_cents,min_amount_cents,valid_days,quantity,remaining_quantity,weight,status,sort_order,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute(array($a['id'],$r[0],$r[1],$r[2],$r[3],$r[4],$r[5],$r[6],$r[6],$r[7],$r[9],$r[8],$aid,$aid,$now,$now));$pdo->prepare('INSERT INTO admin_logs(admin_id,action,target_type,target_id,details_json,created_at) VALUES(?,?,?,?,?,?)')->execute(array($aid,'create_lottery_prize','lottery_prize',$pdo->lastInsertId(),json_encode(array('code'=>$r[0],'quantity'=>$r[4],'weight'=>$r[5]),JSON_UNESCAPED_UNICODE),$now));flash('success','抽奖奖品已添加。');redirect('/admin/events/lottery');}catch(Exception $e){flash('error',$e->getMessage());redirect('/admin/events/lottery/prizes/create');}});
    $router->get('/admin/events/lottery/prizes/{id}/edit', function($id) use ($pdo,$config){Security::requireAdmin();$s=$pdo->prepare('SELECT * FROM lottery_prizes WHERE id=?');$s->execute(array((int)$id));$r=$s->fetch();if(!$r)return page('奖品不存在','<div class="empty">奖品不存在。</div>',$config);page('编辑抽奖奖品',lottery_prize_form($r,'/admin/events/lottery/prizes/'.$id.'/edit','编辑抽奖奖品'),$config);});
    $router->post('/admin/events/lottery/prizes/{id}/edit', function($id) use ($pdo,$config){$aid=Security::requireAdmin();post_csrf();try{$r=lottery_prize_input();$s=$pdo->prepare('SELECT * FROM lottery_prizes WHERE id=? FOR UPDATE');$s->execute(array((int)$id));$old=$s->fetch();if(!$old)throw new RuntimeException('奖品不存在。');$issued=(int)$old['quantity']>=0?(int)$old['quantity']-(int)$old['remaining_quantity']:0;if($r[4]>=0&&$r[4]<$issued)throw new InvalidArgumentException('总库存不能低于已发放数量。');$remaining=$r[4]<0?-1:max(0,$r[4]-$issued);$now=date('Y-m-d H:i:s');$pdo->prepare('UPDATE lottery_prizes SET prize_code=?,name=?,prize_type=?,value_cents=?,min_amount_cents=?,valid_days=?,quantity=?,remaining_quantity=?,weight=?,status=?,sort_order=?,updated_by=?,updated_at=? WHERE id=?')->execute(array($r[0],$r[1],$r[2],$r[3],$r[4],$r[5],$r[6],$remaining,$r[7],$r[9],$r[8],$aid,$now,(int)$id));$pdo->prepare('INSERT INTO admin_logs(admin_id,action,target_type,target_id,details_json,created_at) VALUES(?,?,?,?,?,?)')->execute(array($aid,'update_lottery_prize','lottery_prize',(int)$id,json_encode(array('old_quantity'=>$old['quantity'],'new_quantity'=>$r[4],'old_remaining'=>$old['remaining_quantity'],'new_remaining'=>$remaining),JSON_UNESCAPED_UNICODE),$now));flash('success','抽奖奖品已更新。');redirect('/admin/events/lottery');}catch(Exception $e){flash('error',$e->getMessage());redirect('/admin/events/lottery/prizes/'.$id.'/edit');}});
    $router->post('/admin/events/lottery/prizes/{id}/toggle', function($id) use ($pdo,$config){$aid=Security::requireAdmin();post_csrf();$s=$pdo->prepare('UPDATE lottery_prizes SET status=IF(status="active","inactive","active"),updated_by=?,updated_at=? WHERE id=?');$s->execute(array($aid,date('Y-m-d H:i:s'),(int)$id));flash('success','奖品状态已更新。');redirect('/admin/events/lottery');});
    $router->get('/admin/events/{key}', function($key) use ($pdo, $config) {
        $aid=Security::requireAdmin();$allowed=array('lottery','referral','group','leader');if(!in_array($key,$allowed,true))return page('活动不存在','<div class="empty">活动不存在。</div>',$config);$service=new ActivityService($pdo);$row=$service->setting($key);$cfg=$service->config($key);$labels=array('lottery'=>'幸运抽奖','referral'=>'拉人返佣','group'=>'好友拼单','leader'=>'团长免单');$fields='';
        if($key==='lottery')$fields='<label>每日抽奖次数<input type="number" name="daily_limit" min="1" max="10" value="'.(int)$cfg['daily_limit'].'"></label><label>中奖概率（百分比）<input type="number" name="win_weight" min="0" max="100" value="'.(int)$cfg['win_weight'].'"></label>';
        if($key==='referral')$fields='<label>返佣比例（百分比）<input type="number" name="rate" min="0" max="100" step="0.01" value="'.number_format($cfg['rate_bps']/100,2,'.','').'"></label><label>最低提现金额（元）<input type="number" name="minimum_withdraw" min="0.01" step="0.01" value="'.number_format($cfg['minimum_withdraw_cents']/100,2,'.','').'"></label>';
        if($key==='group'){$selected=array_map('intval',isset($cfg['plan_ids'])&&is_array($cfg['plan_ids'])?$cfg['plan_ids']:array());$allowedRows=$pdo->query('SELECT pp.id,pp.name plan_name,pp.duration_days,pp.price_cents,p.name product_name FROM product_plans pp JOIN products p ON p.id=pp.product_id WHERE pp.status="active" AND p.status="active" ORDER BY p.sort_order,p.id,pp.sort_order,pp.id')->fetchAll();$checks='';foreach($allowedRows as $plan)$checks.='<label class="event-plan-option"><input type="checkbox" name="group_plan_ids[]" value="'.(int)$plan['id'].'"'.(in_array((int)$plan['id'],$selected,true)?' checked':'').'><span>'.e($plan['product_name'].' / '.$plan['plan_name'].' / '.$plan['duration_days'].' 天 / '.money($plan['price_cents'])).'</span></label>';$fields='<label>成团人数<input type="number" name="target_members" min="2" max="99" value="'.(int)$cfg['target_members'].'"></label><label>折扣（百分比）<input type="number" name="discount_percent" min="1" max="100" value="'.(int)$cfg['discount_percent'].'"></label><label>有效时长（小时）<input type="number" name="duration_hours" min="1" max="168" value="'.(int)$cfg['duration_hours'].'"></label><fieldset><legend>允许拼团的套餐方案</legend>'.($checks?:'<p>当前没有已上架套餐方案。</p>').'</fieldset>'; }
        if($key==='leader'){$options='<option value="0">暂不指定</option>';foreach($pdo->query('SELECT pp.id,CONCAT(p.name," / ",pp.name," / ¥",FORMAT(pp.price_cents/100,2)) label FROM product_plans pp JOIN products p ON p.id=pp.product_id WHERE pp.status="active" AND p.status="active" ORDER BY p.sort_order,pp.sort_order,pp.id') as $p)$options.='<option value="'.(int)$p['id'].'"'.((int)$cfg['reward_plan_id']===(int)$p['id']?' selected':'').'>'.e($p['label']).'</option>';$fields='<label>达标邀请人数<input type="number" name="required_referrals" min="1" max="99" value="'.(int)$cfg['required_referrals'].'"></label><label>免单奖励套餐方案<select name="reward_plan_id">'.$options.'</select></label>' ;}
        $status=$row&&$row['status']==='enabled'?'enabled':'disabled';$body=admin_heading($labels[$key],'配置活动规则、状态与默认参数','<a class="secondary-button" href="/admin/events">返回活动运营</a>');$body.='<div class="product-form-card"><form method="post" action="/admin/events/'.e($key).'">'.csrf_field().'<div class="product-form-section"><h2>基础设置</h2><label>活动状态<select name="status"><option value="disabled"'.($status==='disabled'?' selected':'').'>关闭</option><option value="enabled"'.($status==='enabled'?' selected':'').'>启用</option></select></label>'.$fields.'</div><div class="product-form-actions"><a class="secondary-button" href="/admin/events">取消</a><button class="button">保存配置</button></div></form></div>';
        if($key==='lottery'){$prizeRows='';$s=$pdo->prepare('SELECT * FROM lottery_prizes WHERE activity_id=? ORDER BY sort_order,id');$s->execute(array($row?$row['id']:$service->setting('lottery')['id']));foreach($s as $prize)$prizeRows.='<tr><td><b>'.e($prize['name']).'</b><small class="table-sub">'.e($prize['prize_code']).'</small></td><td>'.e($prize['prize_type']).' · '.e(money($prize['value_cents'])).'<small class="table-sub">满 '.e(money($prize['min_amount_cents'])).' · '.(int)$prize['valid_days'].' 天</small></td><td>'.((int)$prize['quantity']<0?'不限':(int)$prize['remaining_quantity'].' / '.(int)$prize['quantity']).'</td><td>'.(int)$prize['weight'].'</td><td>'.e($prize['status']).'</td><td><div class="row-actions"><a class="row-edit" href="/admin/events/lottery/prizes/'.(int)$prize['id'].'/edit">编辑</a><form method="post" action="/admin/events/lottery/prizes/'.(int)$prize['id'].'/toggle" class="inline">'.csrf_field().'<button class="row-link" type="submit">'.($prize['status']==='active'?'停用':'启用').'</button></form></div></td></tr>';$body.='<section class="admin-panel"><div class="admin-panel-head"><div><h2>自定义奖池</h2><p>独立设置奖品价值、库存、权重和状态。</p></div><a class="button" href="/admin/events/lottery/prizes/create">＋ 添加奖品</a></div><div class="table-wrap"><table><thead><tr><th>奖品</th><th>类型 / 面值</th><th>剩余 / 总库存</th><th>权重</th><th>状态</th><th>操作</th></tr></thead><tbody>'.($prizeRows?:'<tr><td colspan="6" class="admin-empty">奖池为空，请添加奖品后再启用活动。</td></tr>').'</tbody></table></div></section>';}
        if($key==='group')$body.='<div class="admin-info-banner">保存所选方案时立即更新该拼团活动允许参与的套餐范围。</div>';
        if($key==='referral'){$rows='';foreach($pdo->query('SELECT w.*,u.email FROM withdrawal_requests w JOIN users u ON u.id=w.user_id ORDER BY w.id DESC LIMIT 30') as $w)$rows.='<tr><td>'.e($w['request_no']).'</td><td>'.e($w['email']).'</td><td>'.e(money($w['amount_cents'])).'</td><td>'.e($w['status']).'</td><td><form method="post" action="/admin/events/withdrawals/'.(int)$w['id'].'">'.csrf_field().'<select name="status"><option value="approved">通过</option><option value="rejected">驳回</option><option value="paid">已打款</option></select><button class="row-link">保存</button></form></td></tr>';$body.='<section class="admin-panel"><div class="admin-panel-head"><div><h2>提现审核</h2><p>人工审核后在线下完成打款。</p></div></div><div class="table-wrap"><table><thead><tr><th>申请号</th><th>用户</th><th>金额</th><th>状态</th><th>处理</th></tr></thead><tbody>'.($rows?:'<tr><td colspan="5" class="admin-empty">暂无提现申请。</td></tr>').'</tbody></table></div></section>';}
        page($labels[$key],$body,$config);
    });
    $router->post('/admin/events/{key}', function($key) use ($pdo, $config) {
        $aid=Security::requireAdmin();post_csrf();$allowed=array('lottery','referral','group','leader');if(!in_array($key,$allowed,true))return redirect('/admin/events');$cfg=array();
        if($key==='lottery')$cfg=array('daily_limit'=>max(1,min(10,(int)$_POST['daily_limit'])),'win_weight'=>max(0,min(100,(int)$_POST['win_weight'])));
        if($key==='referral')$cfg=array('rate_bps'=>max(0,min(10000,(int)round((float)$_POST['rate']*100))),'minimum_withdraw_cents'=>max(1,(int)round((float)$_POST['minimum_withdraw']*100)));
        if($key==='group'){$planIds=array();foreach(isset($_POST['group_plan_ids'])&&is_array($_POST['group_plan_ids'])?$_POST['group_plan_ids']:array() as $planId){$planId=(int)$planId;if($planId>0)$planIds[$planId]=true;}if(!$planIds&&isset($_POST['status'])&&$_POST['status']==='enabled')throw new InvalidArgumentException('启用好友拼单前请至少选择一个套餐方案。');$valid=array();if($planIds){$in=implode(',',array_fill(0,count($planIds),'?'));$s=$pdo->prepare('SELECT pp.id FROM product_plans pp JOIN products p ON p.id=pp.product_id WHERE pp.id IN ('.$in.') AND pp.status="active" AND p.status="active"');$s->execute(array_keys($planIds));foreach($s as $plan)$valid[]=(int)$plan['id'];}if(count($valid)!==count($planIds))throw new InvalidArgumentException('包含不存在或已下架的套餐方案。');$cfg=array('target_members'=>max(2,min(99,(int)$_POST['target_members'])),'discount_percent'=>max(1,min(100,(int)$_POST['discount_percent'])),'duration_hours'=>max(1,min(168,(int)$_POST['duration_hours'])),'plan_ids'=>$valid);}
        if($key==='leader')$cfg=array('required_referrals'=>max(1,min(99,(int)$_POST['required_referrals'])),'reward_plan_id'=>max(0,(int)$_POST['reward_plan_id']));
        $service=new ActivityService($pdo);$nextStatus=isset($_POST['status'])&&$_POST['status']==='enabled'?'enabled':'disabled';if($key==='lottery'&&$nextStatus==='enabled'){$activity=$service->setting('lottery');$s=$pdo->prepare('SELECT COUNT(*) FROM lottery_prizes WHERE activity_id=? AND status="active" AND weight>0 AND (remaining_quantity<0 OR remaining_quantity>0)');$s->execute(array($activity['id']));if(!(int)$s->fetchColumn())throw new InvalidArgumentException('奖池没有可用奖品，请先添加启用且有库存的奖品。');}$service->saveSetting($key,array('status'=>$nextStatus,'config'=>$cfg),$aid);$pdo->prepare('INSERT INTO admin_logs(admin_id,action,target_type,details_json,created_at) VALUES(?,?,?,?,?)')->execute(array($aid,'update_activity','activity',$key,json_encode($cfg,JSON_UNESCAPED_UNICODE),date('Y-m-d H:i:s')));flash('success','活动配置已保存。');redirect('/admin/events/'.$key);
    });
    $router->post('/admin/events/withdrawals/{id}', function($id) use ($pdo, $config) {$aid=Security::requireAdmin();post_csrf();try{(new ActivityService($pdo))->processWithdrawal((int)$id,$aid,isset($_POST['status'])?$_POST['status']:'rejected',isset($_POST['reason'])?$_POST['reason']:'');flash('success','提现审核已保存。');}catch(Exception $e){flash('error',$e->getMessage());}redirect('/admin/events/referral');});

    $router->get('/admin/finance', function() use ($pdo, $config) {
        Security::requireAdmin();
        $today=date('Y-m-d').' 00:00:00';
        $month=date('Y-m-01').' 00:00:00';
        $revenue=(int)admin_scalar($pdo,'SELECT COALESCE(SUM(total_amount_cents),0) FROM orders WHERE paid_at>=? AND status IN ("pending_delivery","delivered","refunding")',array($month));
        $refunds=(int)admin_scalar($pdo,'SELECT COALESCE(SUM(total_amount_cents),0) FROM orders WHERE refund_processed_at>=? AND status="refunded"',array($month));
        $todayRevenue=(int)admin_scalar($pdo,'SELECT COALESCE(SUM(total_amount_cents),0) FROM orders WHERE paid_at>=? AND status IN ("pending_delivery","delivered","refunding")',array($today));
        $rows='';$statement=$pdo->query('SELECT order_no,total_amount_cents,status,paid_at FROM orders WHERE paid_at IS NOT NULL ORDER BY paid_at DESC LIMIT 30');
        foreach($statement as $item)$rows.='<tr><td><a href="/admin/orders/'.e($item['order_no']).'">'.e($item['order_no']).'</a></td><td>'.e(money($item['total_amount_cents'])).'</td><td><span class="admin-status '.e($item['status']).'">'.e(status_text($item['status'])).'</span></td><td>'.e($item['paid_at']).'</td></tr>';
        if($rows==='')$rows='<tr><td colspan="4" class="admin-empty">暂无收款流水。</td></tr>';
        $body=admin_heading('财务中心','查看真实订单收款与退款数据');
        $body.='<section class="admin-stats admin-list-stats">'.admin_metric('本月实收',money($revenue),'已确认付款','finance','violet').admin_metric('今日实收',money($todayRevenue),'今日已确认付款','orders','green').admin_metric('本月退款',money($refunds),'已处理退款','finance','amber').'</section>';
        $body.='<section class="admin-panel admin-data-panel"><div class="admin-panel-head"><div><h2>最近收款</h2><p>最近 30 笔已确认付款订单</p></div><a href="/admin/orders">查看订单 →</a></div><div class="table-wrap"><table><thead><tr><th>订单号</th><th>金额</th><th>状态</th><th>付款时间</th></tr></thead><tbody>'.$rows.'</tbody></table></div></section>';
        page('财务中心',$body,$config);
    });

    $router->get('/admin/content', function() use ($pdo, $config) {
        Security::requireAdmin();
        $tab=isset($_GET['tab'])&&in_array($_GET['tab'],array('downloads','tutorials','announcements'),true)?$_GET['tab']:'downloads';
        $tabs='<nav class="admin-content-tabs"><a href="/admin/content?tab=downloads"'.($tab==='downloads'?' class="selected"':'').'>客户端版本</a><a href="/admin/content?tab=tutorials"'.($tab==='tutorials'?' class="selected"':'').'>使用教程</a><a href="/admin/content?tab=announcements"'.($tab==='announcements'?' class="selected"':'').'>系统公告</a></nav>';
        $body=admin_heading('内容管理','发布客户端版本、教程和系统公告','<a class="button" href="'.($tab==='downloads'?'/admin/downloads/create':'/admin/articles/create').'">＋ 发布新内容</a>').$tabs;
        if($tab==='downloads'){
            $cards='';
            foreach($pdo->query('SELECT id,app_name,platform,version,status,updated_at FROM download_resources ORDER BY sort_order,updated_at DESC LIMIT 12') as $item){
                $cards.='<a class="admin-resource-card" href="/admin/downloads/'.(int)$item['id'].'/edit"><span>'.e(mb_substr($item['app_name'],0,1,'UTF-8')).'</span><div><h2>'.e($item['app_name']).'</h2><p>最新版本 '.e($item['version']!==''?$item['version']:'未填写').'</p><small>更新于 '.e(date('Y-m-d',strtotime($item['updated_at']))).'</small></div><em class="admin-status '.($item['status']==='active'?'delivered':'').'">'.($item['status']==='active'?'已发布':'已下架').'</em></a>';
            }
            if($cards==='')$cards='<div class="admin-module-empty">暂无客户端资源。</div>';
            $body.='<div class="admin-resource-grid">'.$cards.'</div>';
            $body.='<div class="admin-panel-head admin-content-subhead"><div><h2>最近更新的教程</h2><p>前台帮助中心内容</p></div><a href="/admin/articles">管理全部教程 →</a></div>';
            $statement=$pdo->query('SELECT id,title,category,status,updated_at FROM articles ORDER BY updated_at DESC LIMIT 4');
        }else{
            $body.='<div class="admin-panel-head admin-content-subhead"><div><h2>'.($tab==='tutorials'?'使用教程':'系统公告').'</h2><p>前台帮助中心内容</p></div><a href="/admin/articles">管理全部内容 →</a></div>';
            $statement=$pdo->prepare('SELECT id,title,category,status,updated_at FROM articles WHERE category LIKE ? ORDER BY updated_at DESC LIMIT 12');
            $statement->execute(array($tab==='tutorials'?'%教程%':'%公告%'));
        }
        $items='';$index=0;
        foreach($statement as $item){$index++;$items.='<a class="admin-content-row" href="/admin/articles/'.(int)$item['id'].'/edit"><span>'.sprintf('%02d',$index).'</span><div><b>'.e($item['title']).'</b><small>'.e($item['category']).' · 更新于 '.e(date('Y-m-d',strtotime($item['updated_at']))).'</small></div><em class="admin-status '.($item['status']==='published'?'delivered':'').'">'.($item['status']==='published'?'已发布':'草稿').'</em></a>';}
        $body.='<section class="admin-panel admin-content-list">'.($items?:'<div class="admin-module-empty">暂无内容。</div>').'</section>';
        page('内容管理',$body,$config);
    });

    $router->post('/admin/system/update/check', function() use ($config) {
        Security::requireAdmin();
        post_csrf();
        try {
            $result = (new UpdateService($config))->checkLatest(true);
            flash($result['status']==='ok' ? 'success' : 'error', $result['message']);
        } catch (Exception $e) {
            flash('error', '更新检测失败，请稍后重试。');
        }
        redirect('/admin/settings?tab=general');
    });
    $router->post('/admin/system/update/prepare', function() use ($pdo, $config) {
        $aid=Security::requireAdmin(); post_csrf();
        try {
            $state=(new UpdateService($config))->prepareUpdate();
            update_admin_log($pdo,$aid,'update_prepare',array('version'=>$state['version'],'update_id'=>$state['id']));
            flash('success','更新包已下载并完成签名校验，可以安装。');
        } catch(Exception $e) { flash('error',$e->getMessage()); }
        redirect('/admin/settings?tab=general');
    });
    $router->post('/admin/system/update/install', function() use ($pdo, $config) {
        $aid=Security::requireAdmin(); post_csrf(); $id=isset($_POST['update_id'])?trim($_POST['update_id']):'';
        if(!isset($_POST['confirm_install'])||$_POST['confirm_install']!=='1'){flash('error','请确认已备份数据库并了解更新不会自动迁移。');redirect('/admin/settings?tab=general');}
        try {
            (new UpdateService($config))->startWorker($id,'install');
            update_admin_log($pdo,$aid,'update_install_start',array('update_id'=>$id));
            flash('success','更新任务已启动，请稍后刷新页面查看状态。');
        } catch(Exception $e) { flash('error',$e->getMessage()); }
        redirect('/admin/settings?tab=general');
    });
    $router->get('/admin/system/update/status', function() use ($config) {
        Security::requireAdmin(); header('Content-Type: application/json; charset=utf-8'); echo json_encode((new UpdateService($config))->status(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    });
    $router->post('/admin/system/update/rollback', function() use ($pdo, $config) {
        $aid=Security::requireAdmin(); post_csrf(); $id=isset($_POST['backup_id'])?trim($_POST['backup_id']):'';
        try {
            (new UpdateService($config))->startWorker($id,'rollback');
            update_admin_log($pdo,$aid,'update_rollback_start',array('backup_id'=>$id));
            flash('success','回滚任务已启动。');
        } catch(Exception $e) { flash('error',$e->getMessage()); }
        redirect('/admin/settings?tab=general');
    });
    $router->get('/admin/settings', function() use ($pdo, $config) {
        Security::requireAdmin();
        $tab=isset($_GET['tab'])&&in_array($_GET['tab'],array('general','payment','notifications','admins','security'),true)?$_GET['tab']:'general';
        $tabs='<nav class="admin-content-tabs"><a href="/admin/settings?tab=general"'.($tab==='general'?' class="selected"':'').'>基础设置</a><a href="/admin/settings?tab=payment"'.($tab==='payment'?' class="selected"':'').'>支付配置</a><a href="/admin/settings?tab=notifications"'.($tab==='notifications'?' class="selected"':'').'>消息通知</a><a href="/admin/settings?tab=admins"'.($tab==='admins'?' class="selected"':'').'>管理员与权限</a><a href="/admin/settings?tab=security"'.($tab==='security'?' class="selected"':'').'>安全与审计</a></nav>';
        $body=admin_heading('系统设置','配置支付、通知、安全与管理员权限').$tabs;
        if($tab==='general'){
            $body.='<section class="admin-panel admin-settings-panel"><div class="admin-panel-head"><div><h2>基础设置</h2><p>平台品牌及默认业务配置</p></div></div><div class="admin-settings-logo"><img src="/assets/xiaoyun-logo.png" alt="小云铺加速器"><span><b>平台 Logo</b><small>当前品牌标识</small></span></div><div class="admin-settings-fields"><label>平台名称<input value="'.e($config['app']['name']).'" readonly></label><label>平台域名<input value="'.e($config['app']['base_url']).'" readonly></label><label>默认货币<input value="人民币 CNY" readonly></label></div><div class="admin-info-banner">品牌名称和平台域名由服务器配置文件维护。</div></section>'.update_check_panel($config);
        }elseif($tab==='payment'){
            $body.='<div class="admin-hub-grid">'.admin_hub_card('/admin/payment','支付配置','管理支付渠道、网关和通知地址。','finance','violet').admin_hub_card('/admin/temporary-subscription','临时订阅','维护付款确认后交付给用户的临时订阅。','subscriptions','blue').'</div>';
        }elseif($tab==='admins'){
            $rows='';foreach($pdo->query('SELECT display_name,email,role,status,created_at FROM admin_users ORDER BY id DESC LIMIT 100') as $item)$rows.='<tr><td>'.e($item['display_name']).'</td><td>'.e($item['email']).'</td><td>'.e($item['role']).'</td><td>'.e($item['status']).'</td><td>'.e($item['created_at']).'</td></tr>';
            $body.='<section class="admin-panel admin-data-panel"><div class="admin-panel-head"><div><h2>管理员与权限</h2><p>已有管理员账号</p></div></div><div class="table-wrap"><table><thead><tr><th>名称</th><th>邮箱</th><th>角色</th><th>状态</th><th>创建时间</th></tr></thead><tbody>'.$rows.'</tbody></table></div></section>';
        }elseif($tab==='security'){
            $rows='';foreach($pdo->query('SELECT l.action,l.target_type,l.created_at,a.display_name FROM admin_logs l LEFT JOIN admin_users a ON a.id=l.admin_id ORDER BY l.id DESC LIMIT 30') as $item)$rows.='<tr><td>'.e($item['action']).'</td><td>'.e($item['target_type']).'</td><td>'.e($item['display_name']).'</td><td>'.e($item['created_at']).'</td></tr>';
            $body.='<section class="admin-panel admin-data-panel"><div class="admin-panel-head"><div><h2>安全与审计</h2><p>最近 30 条管理员操作记录</p></div></div><div class="table-wrap"><table><thead><tr><th>操作</th><th>对象</th><th>管理员</th><th>时间</th></tr></thead><tbody>'.($rows?:'<tr><td colspan="4" class="admin-empty">暂无审计记录。</td></tr>').'</tbody></table></div></section>';
        }else{
            $body.='<section class="admin-panel"><div class="admin-panel-head"><div><h2>消息通知</h2><p>当前服务器项目尚无独立通知渠道配置。</p></div></div><div class="admin-module-empty">订单和工单通知仍由现有系统逻辑处理。</div></section>';
        }
        page('系统设置',$body,$config);
    });
}
