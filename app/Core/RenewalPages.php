<?php
// 仅负责页面集成；续费资格、价格和业务写入统一由 RenewalService 校验处理。
function renewal_is_order($row) {
    return class_exists('RenewalService')?RenewalService::isRenewal($row):(isset($row['order_kind'])&&$row['order_kind']==='renewal');
}
function renewal_price_source($source) { return $source==='original'?'原订单价格快照':'当前同商品方案价格'; }
function order_payment_panel($pdo,$config,$o,$uid) {
    if($o['status']!=='pending_payment')return '';
    $no=rawurlencode($o['order_no']);$ps=(new PaymentService($pdo,$config))->settings();
    $html='<section class="order-payment-panel"><div class="order-payment-heading"><div><span class="order-section-label">SECURE CHECKOUT</span><h2>选择支付方式</h2><p>应付金额将以订单实际支付记录为准。</p></div><strong>'.e(money($o['total_amount_cents'])).'</strong></div>';
    if(!empty($ps['enabled'])){
        $html.='<div class="payment-methods">';
        foreach(array('alipay'=>array('alipay','支','支付宝支付'),'wechat'=>array('wxpay','微','微信支付')) as $key=>$method)if(!empty($ps[$key.'_enabled']))$html.='<article class="payment-method-card payment-method-'.$key.'"><span class="payment-method-icon">'.$method[1].'</span><div class="payment-method-copy"><b>'.$method[2].'</b><small>安全快捷，支付成功后自动确认</small></div><form method="post" action="/payment/start/'.e($no).'/'.$method[0].'">'.csrf_field().'<button class="button" type="submit">立即支付</button></form></article>';
        $html.='</div>';
    }
    $balance=(new WalletService($pdo))->balance($uid);$canPay=empty($o['payment_started_at'])&&$balance>=(int)$o['total_amount_cents'];
    return $html.'<div class="payment-method-divider"><span>或使用消费余额</span></div><article class="payment-method-card payment-method-balance"><span class="payment-method-icon">¥</span><div class="payment-method-copy"><b>消费余额</b><small>当前可用 ¥'.e(WalletService::formatAmount($balance)).' · '.(!empty($o['payment_started_at'])?'已发起在线支付，请继续原渠道付款。':($canPay?'余额足够，可全额支付。':'余额不足，请选择其他支付方式。')).'</small></div><form method="post" action="/orders/'.e($no).'/balance-pay">'.csrf_field().'<button class="button" type="submit"'.($canPay?'':' disabled').'>余额支付 '.e(money($o['total_amount_cents'])).'</button></form></article><a class="payment-balance-link" href="/wallet">查看余额明细与流水 →</a><p class="payment-security-note">支付完成后，系统会自动更新订单状态；请勿重复提交不同支付方式。</p></section>';
}
function renewal_parent_quote($pdo,$config,$o,$uid) {
    if(renewal_is_order($o)||($o['product_type_snapshot']??'')!=='vpn'||$o['status']!=='delivered'||!class_exists('RenewalService'))return null;
    try{return (new RenewalService($pdo,$config))->quote($o['order_no'],$uid);}catch(Exception $ex){return null;}
}
function renewal_parent_action($quote) {
    if(!$quote)return '';
    $active=$quote['active'];$path='/orders/'.rawurlencode($active?$active['order_no']:$quote['parent']['order_no']);
    $label=$active?($active['status']==='pending_payment'?'去付款':($active['status']==='refunding'?'续费退款中':'续费处理中')):'续费';
    return '<a class="ao-button" href="'.e($path.($active?'':'/renew')).'">'.$label.'</a>';
}
function renewal_parent_history($pdo,$o,$uid=null,$admin=false) {
    if(renewal_is_order($o)||!isset($o['order_kind']))return '';
    $sql='SELECT * FROM orders WHERE parent_order_id=? AND order_kind="renewal"';$args=array($o['id']);
    if(!$admin){$sql.=' AND user_id=?';$args[]=$uid;}
    $s=$pdo->prepare($sql.' ORDER BY id DESC');$s->execute($args);$links='';
    foreach($s as $row)$links.='<li><a href="'.($admin?'/admin':'').'/orders/'.e(rawurlencode($row['order_no'])).'">'.e($row['order_no']).'</a> · '.e(RenewalService::statusLabel($row)).' · '.e(money($row['total_amount_cents'])).'</li>';
    return $links?'<section class="order-reference-card renewal-card"><h2>续费记录</h2><ul>'.$links.'</ul></section>':'';
}
function renewal_quote_page($quote,$requestKey) {
    $parent=$quote['parent'];$path='/orders/'.rawurlencode($parent['order_no']);
    $html='<div class="order-detail-page order-reference-page renewal-page"><header class="order-reference-heading"><nav><a href="/orders">我的订单</a> / <a href="'.e($path).'">原订单</a> / 续费</nav><h1>确认续费</h1></header><section class="order-reference-summary"><h2>'.e($parent['product_name_snapshot']).'</h2><p>原订单 '.e($parent['order_no']).' · 当前到期 '.e($quote['delivery']['expires_at']??'未设置').'</p><p>仅延长原商品订阅时长，不更换订阅类型，也不会创建新的订阅。原订单保持已交付。</p></section><section class="order-reference-card renewal-card"><h2>选择续费时长</h2>';
    if($quote['active'])return $html.'<p>已有续费订单，请先处理，避免重复付款。</p>'.renewal_parent_action($quote).'</section></div>';
    $html.='<form method="post" action="'.e($path).'/renew">'.csrf_field().'<input type="hidden" name="request_key" value="'.e($requestKey).'"><fieldset><legend>时长、金额与价格来源</legend>';
    foreach($quote['options'] as $option)$html.='<label class="renewal-option"><input type="radio" name="option_key" value="'.e($option['key']).'"'.($option['key']==='original'?' checked':'').' required><span><strong>'.e($option['name']).' · '.(int)$option['duration_days'].' 天 · '.e(money($option['price_cents'])).'</strong><small>价格来源：'.e(renewal_price_source($option['price_source'])).($option['key']==='original'?'（默认原时长、原价）':'').'</small></span></label>';
    return $html.'</fieldset><p>续费起算规则：未过期从原到期时间顺延；已过期从管理员确认续费成功时起算。续费不重复使用原订单优惠券，也不重复享受原订单优惠。</p><p>付款确认后由管理员在上游人工续费，再核对并更新到期时间；付款不等于续费已完成。成功后继续使用原订阅，请勿重复购买。</p><div class="product-form-actions"><a class="ao-button" href="'.e($path).'">返回原订单</a><button class="ao-button ao-primary" type="submit">确认方案并创建续费订单</button></div></form></section></div>';
}
function renewal_detail_page($pdo,$config,$detail,$uid=null,$admin=false) {
    $o=$detail['order'];$r=$detail['renewal'];$parent=$detail['parent'];$path=($admin?'/admin':'').'/orders/'.rawurlencode($o['order_no']);
    $label=RenewalService::statusLabel($o);
    $html='<div class="order-detail-page order-reference-page renewal-page"><header class="order-reference-heading"><nav><a href="'.($admin?'/admin':'').'/orders">订单列表</a> / 续费订单</nav><h1>续费订单</h1></header><section class="order-reference-summary"><h2>'.e($o['product_name_snapshot']).'</h2><p>续费单号 '.e($o['order_no']).'</p><strong class="order-status-badge">'.e($label).'</strong><p>关联原订单 <a href="'.($admin?'/admin':'').'/orders/'.e(rawurlencode($parent['order_no'])).'">'.e($parent['order_no']).'</a>（原订单保持已交付）</p><dl class="order-reference-facts">';
    foreach(array('续费金额'=>money($r['price_cents']),'续费时长'=>(int)$r['duration_days'].' 天','价格来源'=>renewal_price_source($r['price_source']),'当前到期时间'=>$detail['delivery']['expires_at']??'未设置','续费前到期时间'=>$r['previous_expires_at']??'待人工确认','续费起算时间'=>$r['renewal_starts_at']??'待人工确认','续费后到期时间'=>$r['new_expires_at']??'待人工确认','完成时间'=>$r['completed_at']??'尚未完成') as $key=>$value)$html.='<div><dt>'.e($key).'</dt><dd>'.e($value?:'待人工确认').'</dd></div>';
    $html.='</dl>';
    if($o['status']==='pending_delivery'&&!empty($detail['delivery']['expires_at'])){
        try{
            $zone=new DateTimeZone($config['app']['timezone']??date_default_timezone_get());
            $now=new DateTimeImmutable('now',$zone);$expiry=new DateTimeImmutable($detail['delivery']['expires_at'],$zone);
            $start=$expiry>$now?$expiry:$now;$end=$start->setTimestamp($start->getTimestamp()+(int)$r['duration_days']*86400);
            $html.='<p>当前到期预览：预计从 '.e($start->format('Y-m-d H:i:s')).' 延长至 '.e($end->format('Y-m-d H:i:s')).'（'.(int)$r['duration_days'].' 天）。仅为预览，最终以人工确认时的交付版本及到期时间为准。</p>';
        }catch(Exception $ex){$html.='<p>到期预览暂不可用，请管理员核对当前交付日期。</p>';}
    }
    $html.='<p>由管理员在上游手工续费后确认，付款不会自动延长有效期；继续使用原订阅，不另发临时订阅或新订阅。</p></section><section class="order-reference-card renewal-card">';
    if($admin){
        if($o['status']==='pending_payment')$html.='<form method="post" action="'.e($path).'/confirm-payment">'.csrf_field().'<button class="button">确认已付款</button></form>';
        if($o['status']==='pending_delivery')$html.='<form method="post" action="'.e($path).'/renewal-complete">'.csrf_field().'<input type="hidden" name="expected_version" value="'.(int)($detail['delivery']['version_no']??0).'"><label><input type="checkbox" name="upstream_confirmed" value="1" required> 我已核对上游订阅、续费天数和当前交付版本</label><button class="button">我已在上游手工续费，标记续费成功</button></form>';
        if($o['status']==='refunding'){
            $payment=null;
            if(!empty($o['paid_payment_record_id'])){
                $s=$pdo->prepare('SELECT method,gateway_type,status FROM payment_records WHERE id=? AND order_id=?');
                $s->execute(array($o['paid_payment_record_id'],$o['id']));$payment=$s->fetch();
            }
            $channel=$payment&&$payment['status']==='confirmed'?(string)$payment['gateway_type']:'';
            $channelLabels=array('balance'=>'消费余额','zero'=>'零元结算','alipay'=>'支付宝（外部支付）','wxpay'=>'微信（外部支付）','wechat'=>'微信（外部支付）','manual'=>'人工确认付款');
            $channelLabel=$channelLabels[$channel]??'未识别，请核对原付款凭证';
            $balanceRefund=$channel==='balance';$zeroRefund=$channel==='zero';
            $refundNote=$balanceRefund?'本单使用消费余额支付。批准退款后由系统按原余额支付记录退回消费余额，请勿另行原渠道转账退款。':($zeroRefund?'本单为零元结算，无实际退款金额；确认仅记录退款处理结果。':'本页面不会自动向支付宝、微信或其他外部渠道退钱。管理员必须先核对原付款渠道并在原渠道完成退款，再点击确认记录；渠道未识别或仅人工确认付款时，须先核对付款凭证，未实际退款不得确认。');
            $approveLabel=$balanceRefund?'批准退款并退回消费余额':($zeroRefund?'确认零元订单退款记录':'我已在原渠道完成退款，确认记录');
            $html.='<p><strong>付款渠道：'.e($channelLabel).'</strong></p><p>'.e($refundNote).'</p><form method="post" action="'.e($path).'/refund">'.csrf_field().'<label>处理说明（外部支付请填写原渠道退款凭证）<textarea name="reason" maxlength="500" required></textarea></label><button class="button" name="decision" value="approve">'.e($approveLabel).'</button><button class="secondary-button" name="decision" value="reject">拒绝退款</button></form>';
        }
    }else{
        $html.=order_payment_panel($pdo,$config,$o,$uid);
        if(!empty($o['payment_started_at'])&&$o['status']==='pending_payment')$html.='<p role="status">付款结果等待确认，请勿重复付款。</p><a href="'.e($path).'/payment-result">刷新付款状态</a>';
        if($o['status']==='pending_delivery')$html.='<p role="status">续费处理中：付款已确认，等待管理员人工续费。原订阅仍按当前到期时间使用。</p><form method="post" action="'.e($path).'/renewal-refund">'.csrf_field().'<input type="hidden" name="order_no" value="'.e($o['order_no']).'"><label>退款原因<textarea name="reason" maxlength="500" required></textarea></label><button class="secondary-button">申请退款</button></form>';
        if($o['status']==='delivered')$html.='<p>续费已成功，请继续使用原订阅。如有问题请提交工单，不自动发起退款。</p>';
    }
    if($o['status']==='pending_payment')$html.='<form method="post" action="'.e($path).'/cancel">'.csrf_field().'<label>取消原因<input name="reason" maxlength="500" required></label><button class="secondary-button">取消未付款订单</button></form>';
    $html.='</section><section class="order-reference-card">'.order_timeline((new OrderService($pdo))->events($o['id']),$o).'</section>';
    if(!$admin)$html.='<footer class="order-reference-support"><a href="/tickets?order_id='.(int)$o['id'].'#new-ticket">提交关联订单工单</a></footer>'.order_detail_script();
    return $html.'</div>';
}
function register_renewal_routes($router,$pdo,$config) {
    $router->get('/orders/{no}/renew',function($no)use($pdo,$config){
        $uid=Security::requireUser();if(PHP_SAPI!=='cli')header('Cache-Control: no-store, private');
        try{$quote=(new RenewalService($pdo,$config))->quote($no,$uid);return page('确认续费',renewal_quote_page($quote,bin2hex(random_bytes(32))),$config);}
        catch(Exception $ex){http_response_code(400);return page('无法续费','<div class="empty">'.e($ex instanceof PDOException?'续费暂不可用，请稍后重试。':$ex->getMessage()).'<a href="/orders">返回我的订单</a></div>',$config);}
    });
    $router->post('/orders/{no}/renew',function($no)use($pdo,$config){
        $uid=Security::requireUser();post_csrf();
        try{$option=$_POST['option_key']??'';$key=$_POST['request_key']??'';if(!is_string($option)||!is_string($key)||!preg_match('/\A[a-f0-9]{64}\z/D',$key))throw new InvalidArgumentException('续费请求无效，请重新确认方案。');$child=(new RenewalService($pdo,$config))->create($no,$uid,$option,$key);return redirect('/orders/'.rawurlencode($child));}
        catch(Exception $ex){flash('error',$ex instanceof PDOException?'续费创建失败，请稍后重试。':$ex->getMessage());return redirect('/orders/'.rawurlencode($no).'/renew');}
    });
    $router->post('/orders/{no}/renewal-refund',function($no)use($pdo,$config){
        $uid=Security::requireUser();post_csrf();
        try{$service=new OrderService($pdo);$order=$service->getForUser($no,$uid);if(!$order||!renewal_is_order($order)||$order['status']!=='pending_delivery')throw new InvalidArgumentException('当前续费状态不支持退款申请，请通过工单联系支持。');$reason=$_POST['reason']??'';if(!is_string($reason)||trim($reason)===''||mb_strlen($reason,'UTF-8')>500)throw new InvalidArgumentException('请填写有效退款原因。');$service->requestRefund($no,$uid,$reason);flash('success','退款申请已提交。');}
        catch(Exception $ex){flash('error',$ex instanceof PDOException?'退款申请失败，请稍后重试。':$ex->getMessage());}return redirect('/orders/'.rawurlencode($no));
    });
    $router->post('/admin/orders/{no}/renewal-complete',function($no)use($pdo,$config){
        $aid=Security::requireAdmin();post_csrf();
        try{$version=$_POST['expected_version']??'';if(!is_string($version)||!preg_match('/\A[1-9][0-9]{0,8}\z/D',$version)||($_POST['upstream_confirmed']??'')!=='1')throw new InvalidArgumentException('请核对上游续费结果与交付版本。');(new RenewalService($pdo,$config))->complete($no,$aid,(int)$version);flash('success','续费成功已记录。');}
        catch(Exception $ex){flash('error',$ex instanceof PDOException?'续费确认失败，请刷新后重试。':$ex->getMessage());}return redirect('/admin/orders/'.rawurlencode($no));
    });
}
