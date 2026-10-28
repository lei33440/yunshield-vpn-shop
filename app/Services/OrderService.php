<?php
class OrderService
{
    private $pdo;
    public function __construct($pdo) { $this->pdo = $pdo; }
    public function create($userId, $productId, $planId, $note, $promotionCode = '', $quantity = 1)
    {
        foreach(array($userId,$productId,$planId) as $id)if((!is_int($id)&&!is_string($id))||!preg_match('/\A[1-9][0-9]*\z/D',(string)$id))throw new InvalidArgumentException('商品、方案或用户参数无效。');
        if(!is_string($note)||!is_string($promotionCode))throw new InvalidArgumentException('订单备注或优惠参数格式无效。');
        if($quantity!==1&&$quantity!=='1')throw new InvalidArgumentException('每笔订单只能购买一个商品。');
        // 团锁内非锁定计数只读其他席位，使用当前已提交视图避免快照读导致超卖。
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->pdo->beginTransaction();
        try {
            $promotionCode=trim((string)$promotionCode);
            $stmt = $this->pdo->prepare('SELECT * FROM products WHERE id=? AND status="active"'); $stmt->execute(array($productId)); $p = $stmt->fetch();
            if (!$p) throw new RuntimeException('套餐不存在或已下架。');
            $type=$this->productType($p['product_type']);
            if($type==='account'&&$promotionCode!=='')throw new InvalidArgumentException('账号商品不参与优惠券或拼团。');
            if(strpos($promotionCode,'group:')===0){
                if(!preg_match('/\\Agroup:[1-9][0-9]*\\z/D',$promotionCode))throw new InvalidArgumentException('拼团与优惠券不可叠加。');
                $this->lockSettlementUsers(array('user_id'=>$userId));
                $existing=(new GroupService($this->pdo))->existingOrder((int)substr($promotionCode,6),$userId);
                if($existing){if(in_array($existing['status'],array('cancelled','refunded','refunding'),true))throw new RuntimeException('该团订单已关闭，请参加其他团。');$this->pdo->commit();return $existing['order_no'];}
            }
            $stmt = $this->pdo->prepare('SELECT * FROM product_plans WHERE id=? AND product_id=? AND status="active"'); $stmt->execute(array($planId,$productId)); $plan = $stmt->fetch();
            if (!$plan) throw new RuntimeException('所选方案不存在或已下架。');
            if($type==='vpn'&&(trim((string)$p['traffic'])===''||trim((string)$p['region'])===''||(int)$p['device_limit']<1||(int)$p['duration_days']<1||(int)$plan['duration_days']<1))throw new InvalidArgumentException('VPN商品流量、地区、设备及方案时长必须完整。');
            $platformName=null;if($type==='account'){ $platform=$this->pdo->prepare('SELECT name FROM account_platforms WHERE code=? LOCK IN SHARE MODE');$platform->execute(array($p['account_platform']));$platformName=$platform->fetchColumn();if(!$platformName||trim((string)$p['account_specification'])===''||trim((string)$p['account_after_sales'])==='')throw new InvalidArgumentException('账号商品平台、规格或售后说明无效。'); }
            $now=date('Y-m-d H:i:s'); $suffix=''; $alphabet='0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'; for($i=0;$i<5;$i++) $suffix.=$alphabet[random_int(0,35)]; $no='CX'.date('ymd').$suffix;
            $this->lockSettlementUsers(array('user_id'=>$userId));
            $original=(int)$plan['price_cents']; $discount=0; $promotion=array('discount'=>0); $promotionCode=trim((string)$promotionCode);$activity=new ActivityService($this->pdo);
            if(strpos($promotionCode,'group:')===0){if(!preg_match('/^group:[0-9]+$/',$promotionCode))throw new InvalidArgumentException('拼团与优惠券不可叠加。');$promotion=$activity->prepareGroupPromotion((int)substr($promotionCode,6),$userId,(int)$plan['id'],$original);$discount=(int)$promotion['discount'];}
            elseif($promotionCode!==''){$promotion=$activity->prepareCoupon($userId,$promotionCode,(int)$plan['id'],$original);$discount=(int)$promotion['discount'];}
            $total=max(0,$original-$discount);
            $sql='INSERT INTO orders(order_no,user_id,product_id,product_plan_id,product_name_snapshot,plan_name_snapshot,product_description_snapshot,product_type_snapshot,account_platform_snapshot,account_platform_name_snapshot,account_specification_snapshot,account_after_sales_snapshot,traffic_snapshot,region_snapshot,device_limit_snapshot,duration_days_snapshot,unit_price_cents_snapshot,original_amount_cents,discount_amount_cents,promotion_code,total_amount_cents,status,customer_note,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
            $this->pdo->prepare($sql)->execute(array($no,$userId,$p['id'],$plan['id'],$p['name'],$plan['name'],$p['description'],$type,$type==='account'?$p['account_platform']:null,$type==='account'?$platformName:null,$type==='account'?$p['account_specification']:null,$type==='account'?$p['account_after_sales']:null,$type==='vpn'?$p['traffic']:null,$type==='vpn'?$p['region']:null,$type==='vpn'?$p['device_limit']:null,$type==='vpn'?$plan['duration_days']:null,$original,$original,$discount,$promotionCode,$total,'pending_payment',substr($note,0,500),$now,$now));
            $id=$this->pdo->lastInsertId();
            $this->pdo->prepare('INSERT INTO payment_records(order_id,method,gateway_type,amount_cents,status,created_at,updated_at) VALUES(?,?,?, ?,"pending",?,?)')->execute(array($id,$total===0?'zero':'manual',$total===0?'zero':'',$total,$now,$now));$initialPaymentId=(int)$this->pdo->lastInsertId();
            if(isset($promotion['coupon']))$activity->applyPromotion($id,$promotion,$promotionCode);elseif(isset($promotion['group'])){$activity->attachGroupOrder($id,$promotion);$this->pdo->prepare('INSERT INTO order_promotions(order_id,promotion_key,promotion_type,promotion_id,promotion_code,discount_amount_cents,idempotency_key,created_at,updated_at) VALUES(?,"group","group",?,?,?, ?,?,?)')->execute(array($id,$promotion['group']['id'],$promotionCode,$discount,'group:'.$id,$now,$now));}
            $this->event($id,'created',null,'pending_payment','订单已提交，等待人工确认付款。','user',$userId,$now);
            (new NotificationOutboxService($this->pdo))->enqueue('order_created','order_created:'.$id,array('title'=>'新订单待处理','lines'=>array('订单号：'.$no,'套餐：'.$p['name'].' / '.$plan['name'],'金额：'.number_format($total/100,2).' 元','时间：'.$now)));
            if($total===0){$o=$this->lock($no);$payment=array('id'=>$initialPaymentId,'gateway_type'=>'zero','amount_cents'=>0);$this->settlePayment($o,$payment,'system',null,array());}
            $this->pdo->commit(); return $no;
        } catch (Exception $e) { if($this->pdo->inTransaction())$this->pdo->rollBack(); throw $e; }
    }
    public function getForUser($no,$userId){$s=$this->pdo->prepare('SELECT * FROM orders WHERE order_no=? AND user_id=?');$s->execute(array($no,$userId));return $s->fetch();}
    public function confirmPayment($no,$adminId,$config=array())
    {
        $this->confirmPaymentInternal($no,'admin',$adminId,array());
    }
    public function confirmPaymentSystem($no,$gatewayType,$tradeNo,$payload,$paymentId)
    {
        return $this->confirmPaymentInternal($no,'system',null,array('gateway_type'=>$gatewayType,'gateway_trade_no'=>$tradeNo,'notify_payload'=>$payload,'payment_id'=>(int)$paymentId));
    }
    private function confirmPaymentInternal($no,$actorType,$actorId,$paymentData)
    {
        $this->pdo->beginTransaction();
        try{
            $o=$this->lock($no);if(!$o)throw new RuntimeException('订单不存在。');
            $this->lockSettlementUsers($o,$actorType!=='system');
            if($actorType==='system'){
                $s=$this->pdo->prepare('SELECT * FROM payment_records WHERE id=? AND order_id=? FOR UPDATE');$s->execute(array($paymentData['payment_id'],$o['id']));$payment=$s->fetch();
                if(!$payment||(int)$payment['amount_cents']!==(int)$o['total_amount_cents']||$payment['gateway_type']!==$paymentData['gateway_type'])throw new RuntimeException('支付记录不匹配。');
                $groups=new GroupService($this->pdo);
                if($groups->isV2($o['id'])){
                    if(!empty($payment['paid_at'])){$this->pdo->commit();return true;}
                    $s=$this->pdo->prepare('SELECT g.status,g.expires_at,go.status seat_status,go.reserved_until FROM group_orders go JOIN group_campaigns g ON g.id=go.campaign_id WHERE go.order_id=?');$s->execute(array($o['id']));$ctx=$s->fetch();$dbNow=$groups->now();
                    $eligible=$o['status']==='pending_payment'&&$payment['status']==='pending'&&$ctx['status']==='forming'&&$ctx['seat_status']==='pending'&&$dbNow<$ctx['reserved_until']&&(!$ctx['expires_at']||$dbNow<$ctx['expires_at']);
                    if(!$eligible){
                        $this->pdo->prepare('UPDATE payment_records SET status="confirmed",gateway_trade_no=?,notify_payload=?,paid_at=?,confirmed_at=?,updated_at=? WHERE id=?')->execute(array($paymentData['gateway_trade_no'],$paymentData['notify_payload'],$dbNow,$dbNow,$dbNow,$payment['id']));
                        $groups->recordRefund($o,$payment,'拼团失效晚款或额外到账，须原渠道退款');
                        $this->event($o['id'],'group_late_payment',$o['status'],$o['status'],'已记录真实晚款，等待人工原渠道退款。','system',null,$dbNow);$this->pdo->commit();return true;
                    }
                }
                if($o['status']!=='pending_payment'){
                    if((int)$o['paid_payment_record_id']===(int)$payment['id']&&$payment['status']==='confirmed'){$this->pdo->commit();return true;}
                    if($payment['status']!=='conflict'){
                        $this->pdo->prepare('UPDATE payment_records SET status="conflict",gateway_trade_no=?,notify_payload=?,paid_at=?,updated_at=? WHERE id=?')->execute(array($paymentData['gateway_trade_no'],$paymentData['notify_payload'],date('Y-m-d H:i:s'),date('Y-m-d H:i:s'),$payment['id']));
                        $this->event($o['id'],'payment_conflict',$o['status'],$o['status'],'收到非实际付款来源的成功回调，请人工核对网关收款。','system',null,date('Y-m-d H:i:s'));
                    }
                    $this->pdo->commit();return true;
                }
                if($payment['status']!=='pending')throw new RuntimeException('支付记录已关闭，请人工核对。');
            }else{
                if($o['status']!=='pending_payment'){
                    if(in_array($o['status'],array('pending_delivery','delivered'),true)){$this->pdo->commit();return true;}
                    throw new RuntimeException('当前订单状态不能确认付款。');
                }
                if(!empty($o['payment_started_at']))throw new RuntimeException('该订单已发起在线支付，请等待网关回调或核对网关收款，不可重复人工确认。');
                $payment=$this->newPayment($o,'manual');
            }
            $this->settlePayment($o,$payment,$actorType,$actorId,$paymentData);
            $this->pdo->commit();return true;
        }catch(Exception $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function payWithBalance($no,$userId)
    {
        $this->pdo->beginTransaction();
        try{
            $o=$this->lock($no);if(!$o||(int)$o['user_id']!==(int)$userId)throw new RuntimeException('订单不存在或无权付款。');
            if($o['status']!=='pending_payment'){
                $s=$this->pdo->prepare('SELECT gateway_type FROM payment_records WHERE id=? AND order_id=?');$s->execute(array($o['paid_payment_record_id'],$o['id']));
                if($s->fetchColumn()==='balance'&&in_array($o['status'],array('paid_waiting_group','pending_delivery','delivered','refunding'),true)){$this->pdo->commit();return;}
                throw new RuntimeException('当前订单不能使用余额付款。');
            }
            if(!empty($o['payment_started_at']))throw new RuntimeException('该订单已发起在线支付，不能再使用余额以免重复付款。');
            $s=$this->pdo->prepare('SELECT id FROM payment_records WHERE order_id=? AND gateway_order_no<>"" LIMIT 1');$s->execute(array($o['id']));if($s->fetch())throw new RuntimeException('该订单已有在线支付记录，请核对原支付渠道。');
            $this->lockSettlementUsers($o);$payment=$this->newPayment($o,(int)$o['total_amount_cents']===0?'zero':'balance');
            if((int)$o['total_amount_cents']>0)(new WalletService($this->pdo))->debitOrder($o,$payment['id']);
            $this->settlePayment($o,$payment,'user',$userId,array());$this->pdo->commit();
        }catch(Exception $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    private function lockSettlementUsers($order,$requireActive=true)
    {
        $ids=array((int)$order['user_id']);$s=$this->pdo->prepare('SELECT referrer_user_id FROM referral_relations WHERE user_id=? AND status="active"');$s->execute(array($order['user_id']));$ref=(int)$s->fetchColumn();if($ref)$ids[]=$ref;
        if(!empty($order['id'])){$s=$this->pdo->prepare('SELECT DISTINCT referrer_user_id FROM referral_commissions WHERE order_id=?');$s->execute(array($order['id']));foreach($s->fetchAll(PDO::FETCH_COLUMN) as $beneficiary)$ids[]=(int)$beneficiary;}
        $ids=array_values(array_unique($ids));sort($ids,SORT_NUMERIC);$s=$this->pdo->prepare('SELECT id,status FROM users WHERE id=? FOR UPDATE');
        foreach($ids as $id){$s->execute(array($id));$user=$s->fetch();if(!$user||($requireActive&&$id===(int)$order['user_id']&&$user['status']!=='active'))throw new RuntimeException('用户不存在或账户已停用。');}
    }
    private function newPayment($order,$method)
    {
        $now=date('Y-m-d H:i:s');$this->pdo->prepare('INSERT INTO payment_records(order_id,method,gateway_type,amount_cents,status,created_at,updated_at) VALUES(?,?,?, ?,"pending",?,?)')->execute(array($order['id'],$method,$method,$order['total_amount_cents'],$now,$now));
        return array('id'=>(int)$this->pdo->lastInsertId(),'gateway_type'=>$method,'amount_cents'=>(int)$order['total_amount_cents']);
    }
    private function settlePayment($o,$payment,$actorType,$actorId,$paymentData)
    {
        if(!$this->pdo->inTransaction()||$o['status']!=='pending_payment')throw new RuntimeException('付款结算状态无效。');
        $groups=new GroupService($this->pdo);
        $this->pdo->prepare('SELECT id FROM payment_records WHERE order_id=? ORDER BY id FOR UPDATE')->execute(array($o['id']));
        $type=$this->productType($o['product_type_snapshot']);
        $gate=$groups->paymentGate($o['id']);
        if($type==='account'&&$groups->orderContext($o['id']))throw new RuntimeException('账号订单不能参与拼团。');
        if($gate&&!$gate['valid']){
            if($actorType==='system'&&!empty($paymentData['payment_id'])&&empty($gate['legacy'])){
                // 回滚并重新走已过期分支，不能持团锁再插入资金记录。
                throw new RuntimeException('group_callback_retry_after_boundary');
            }
            throw new RuntimeException('拼团付款占位已过期或属于历史流程，不能付款。');
        }
        $now=$groups->now();$no=$o['order_no'];$isGroup=$gate!==null;$tempUrl=($isGroup||$type!=='vpn')?'':$this->temporarySubscriptionUrl();$nextStatus=$isGroup?'paid_waiting_group':'pending_delivery';
        $this->pdo->prepare('UPDATE orders SET status=?,paid_payment_record_id=?,paid_at=?,temporary_subscription_url=?,temporary_subscription_status=?,temporary_subscription_issued_at=?,updated_at=? WHERE id=?')->execute(array($nextStatus,$payment['id'],$now,$tempUrl,$tempUrl?'active':'none',$tempUrl?$now:null,$now,$o['id']));
        $this->pdo->prepare('UPDATE payment_records SET status="confirmed",confirmed_by=?,confirmed_at=?,gateway_trade_no=?,paid_at=?,notify_payload=?,updated_at=? WHERE id=? AND order_id=? AND status="pending"')->execute(array($actorType==='admin'?$actorId:null,$now,isset($paymentData['gateway_trade_no'])?$paymentData['gateway_trade_no']:'',$now,isset($paymentData['notify_payload'])?$paymentData['notify_payload']:null,$now,$payment['id'],$o['id']));
        $this->pdo->prepare('UPDATE payment_records SET status="superseded",updated_at=? WHERE order_id=? AND id<>? AND status="pending"')->execute(array($now,$o['id'],$payment['id']));
        if($tempUrl){$this->event($o['id'],'temporary_subscription_issued','pending_delivery','pending_delivery','已发放临时订阅地址，正式人工发货后将自动失效。',$actorType,$actorId,$now);$this->notify($o['user_id'],'temporary_subscription_issued','临时订阅已生效','订单 '.$no.' 已确认付款，临时订阅地址现已可用。正式人工发货后该地址会失效。',$no,$now);}
        $channel=$payment['gateway_type']==='balance'?'余额支付':($payment['gateway_type']==='zero'?'零元订单结算':($actorType==='system'?'在线支付回调':'管理员人工确认'));
        $this->event($o['id'],'payment_confirmed','pending_payment','pending_delivery',$channel.'已完成，订单进入发货队列。',$actorType,$actorId,$now);
        $this->notify($o['user_id'],'payment_confirmed','付款已确认','订单 '.$no.' 已完成'.$channel.'，请等待人工发货。',$no,$now);
        (new NotificationOutboxService($this->pdo))->enqueue('payment_confirmed','payment_confirmed:'.$o['id'],array('title'=>'付款已确认','lines'=>array('订单号：'.$no,'金额：'.number_format($o['total_amount_cents']/100,2).' 元','确认方式：'.$channel,'时间：'.$now)));
        $activity=new ActivityService($this->pdo);if(!$isGroup){if($type==='vpn')$activity->settlePaidOrder($o['id']);}else{$groups->markPaid($o['id'],$gate);}
        if($actorType==='admin')$this->log($actorId,'confirm_payment','order',$o['id'],array('order_no'=>$no,'payment_id'=>$payment['id']));
    }
    // worker 已按订单→升序用户→资金取得锁；成团终态不可逆，资金结算先于最终团锁。
    public function releaseGroupDelivery($o)
    {
        if($this->productType($o['product_type_snapshot'])!=='vpn')throw new RuntimeException('账号订单不能释放拼团订阅。');
        if(!$this->pdo->inTransaction()||$o['status']!=='paid_waiting_group')throw new LogicException('拼团释放事务无效。');
        $s=$this->pdo->prepare('SELECT g.status,g.flow_version FROM group_orders go JOIN group_campaigns g ON g.id=go.campaign_id WHERE go.order_id=?');$s->execute(array($o['id']));$g=$s->fetch();if(!$g||(int)$g['flow_version']!==2||$g['status']!=='successful')throw new RuntimeException('拼团尚未成功。');
        $now=(new GroupService($this->pdo))->now();$url=$this->temporarySubscriptionUrl();$this->pdo->prepare('UPDATE orders SET status="pending_delivery",temporary_subscription_url=?,temporary_subscription_status=?,temporary_subscription_issued_at=?,updated_at=? WHERE id=?')->execute(array($url,$url?'active':'none',$url?$now:null,$now,$o['id']));
        (new ActivityService($this->pdo))->settlePaidOrder($o['id']);
        $this->event($o['id'],'group_released','paid_waiting_group','pending_delivery','拼团已成功，订单已释放至发货队列。','system',null,$now);
        $this->notify($o['user_id'],'group_released','拼团成功','订单 '.$o['order_no'].' 拼团成功，请等待人工发货。',$o['order_no'],$now);
        (new NotificationOutboxService($this->pdo))->enqueue('payment_confirmed','group_released:'.$o['id'],array('title'=>'拼团成功待发货','lines'=>array('订单号：'.$o['order_no'])));
    }
    private function refundBalancePayment($order,$adminId=null)
    {
        if(empty($order['paid_payment_record_id']))return;
        $s=$this->pdo->prepare('SELECT * FROM payment_records WHERE id=? AND order_id=?');$s->execute(array($order['paid_payment_record_id'],$order['id']));$payment=$s->fetch();
        if($payment&&$payment['gateway_type']==='balance')(new WalletService($this->pdo))->refundOrder($order,$payment['id'],$adminId);
    }
    private function revokeTemporarySubscription($order,$now)
    {
        if($order['temporary_subscription_status']==='active')$this->pdo->prepare('UPDATE orders SET temporary_subscription_status="revoked",temporary_subscription_revoked_at=? WHERE id=?')->execute(array($now,$order['id']));
    }
    private function normalizeDeliveryPayload($payload,$config,$existingPayload=null,$isNewDelivery=false,$type='vpn')
    {
        if(!is_array($payload))throw new InvalidArgumentException('交付内容格式无效。');
        foreach(array('subscription','account','instructions','duration_preset','opened_at','expires_at') as $field)if(array_key_exists($field,$payload)&&!is_string($payload[$field])&&!(in_array($field,array('opened_at','expires_at'),true)&&$payload[$field]===null))throw new InvalidArgumentException('交付文字或日期格式无效。');
        $payload['subscription']=trim(isset($payload['subscription'])?$payload['subscription']:'');
        $payload['account']=trim(isset($payload['account'])?$payload['account']:'');
        $payload['instructions']=trim(isset($payload['instructions'])?$payload['instructions']:'');
        if($this->productType($type)==='account'){
            if($payload['account']==='')throw new InvalidArgumentException('请填写账号交付文字内容。');
            $tz=new DateTimeZone(isset($config['app']['timezone'])?$config['app']['timezone']:date_default_timezone_get());
            $dates=array();foreach(array('opened_at','expires_at') as $field){$value=isset($payload[$field])?$payload[$field]:'';$date=$this->parseDeliveryDate($value,$tz);if(trim((string)$value)!==''&&!$date)throw new InvalidArgumentException('交付日期格式无效。');$dates[$field]=$date;}
            if($dates['opened_at']&&$dates['expires_at']&&$dates['expires_at']<=$dates['opened_at'])throw new InvalidArgumentException('到期时间必须晚于开通时间。');
            return array('account'=>$payload['account'],'instructions'=>$payload['instructions'],'subscription'=>'','duration_preset'=>'','opened_at'=>$dates['opened_at']?$dates['opened_at']->format('Y-m-d H:i:s'):null,'expires_at'=>$dates['expires_at']?$dates['expires_at']->format('Y-m-d H:i:s'):null);
        }
        $preset=trim(isset($payload['duration_preset'])?$payload['duration_preset']:'');
        $allowed=array('1m'=>1,'3m'=>3,'6m'=>6,'1y'=>12);
        if($preset!==''&&!isset($allowed[$preset]))throw new InvalidArgumentException('快捷时长无效。');
        $tz=new DateTimeZone(isset($config['app']['timezone'])?$config['app']['timezone']:date_default_timezone_get());
        if($preset!==''){
            $baseValue=$isNewDelivery?(isset($payload['opened_at'])?$payload['opened_at']:''):(isset($existingPayload['opened_at'])?$existingPayload['opened_at']:'');
            $opened=$this->parseDeliveryDate($baseValue,$tz);
            if(trim((string)$baseValue)!==''&&!$opened)throw new InvalidArgumentException('开通时间格式无效。');
            if(!$opened)$opened=new DateTimeImmutable('now',$tz);
            $expires=$this->addCalendarMonths($opened,$allowed[$preset]);
        }else{
            $openedValue=isset($payload['opened_at'])?$payload['opened_at']:'';
            $expiresValue=isset($payload['expires_at'])?$payload['expires_at']:'';
            $opened=$this->parseDeliveryDate($openedValue,$tz);
            if(trim((string)$openedValue)!==''&&!$opened)throw new InvalidArgumentException('开通时间格式无效。');
            if(!$opened)$opened=new DateTimeImmutable('now',$tz);
            $expires=$this->parseDeliveryDate($expiresValue,$tz);
            if(!$expires)throw new InvalidArgumentException('请填写有效的到期时间，或选择快捷时长。');
        }
        if($expires<=$opened)throw new InvalidArgumentException('到期时间必须晚于开通时间。');
        $payload['opened_at']=$opened->format('Y-m-d H:i:s');
        $payload['expires_at']=$expires->format('Y-m-d H:i:s');
        $payload['duration_preset']=$preset;
        return $payload;
    }
    private function parseDeliveryDate($value,$tz)
    {
        $value=trim((string)$value);if($value==='')return null;
        foreach(array('Y-m-d\\TH:i:s','Y-m-d\\TH:i','Y-m-d H:i:s','Y-m-d H:i') as $format){$date=DateTimeImmutable::createFromFormat($format,$value,$tz);$errors=DateTimeImmutable::getLastErrors();if($date&&($errors===false||($errors['warning_count']===0&&$errors['error_count']===0)))return $date;}
        return null;
    }
    private function addCalendarMonths($date,$months)
    {
        $year=(int)$date->format('Y');$month=(int)$date->format('n')+$months;$day=(int)$date->format('j');$hour=(int)$date->format('G');$minute=(int)$date->format('i');$second=(int)$date->format('s');$year+=(int)floor(($month-1)/12);$month=(($month-1)%12)+1;$monthStart=$date->setDate($year,$month,1);$last=(int)$monthStart->modify('last day of this month')->format('j');$day=min($day,$last);return $monthStart->setDate($year,$month,$day)->setTime($hour,$minute,$second);
    }
    public function deliver($no,$adminId,$payload,$config)
    {
        $this->pdo->beginTransaction();
        try{$o=$this->lock($no);if(!$o||$o['status']!=='pending_delivery')throw new RuntimeException('订单未付款、未成团或已发货，不能重复发货。');$ctx=(new GroupService($this->pdo))->orderContext($o['id']);if($ctx&&(int)$ctx['group']['flow_version']===2&&($ctx['group']['status']!=='successful'||empty($ctx['seat']['released_at'])))throw new RuntimeException('拼团未成功释放，禁止交付。');$payload=$this->normalizeDeliveryPayload($payload,$config,null,true,$this->productType($o['product_type_snapshot']));$s=$this->pdo->prepare('SELECT COALESCE(MAX(version_no),0)+1 v FROM delivery_records WHERE order_id=?');$s->execute(array($o['id']));$v=(int)$s->fetch()['v'];$crypto=new CryptoService($config);list($cipher,$nonce,$tag)=$crypto->encrypt(json_encode($payload,JSON_UNESCAPED_UNICODE));$now=date('Y-m-d H:i:s');$this->pdo->prepare('UPDATE delivery_records SET is_current=0 WHERE order_id=?')->execute(array($o['id']));$this->pdo->prepare('INSERT INTO delivery_records(order_id,version_no,action,content_ciphertext,content_nonce,content_tag,content_preview,is_current,opened_at,expires_at,created_by,created_at) VALUES(?,? ,"created",?,?,?, ?,1,?,?,?,?)')->execute(array($o['id'],$v,$cipher,$nonce,$tag,'已加密交付内容',$payload['opened_at']?:null,$payload['expires_at']?:null,$adminId,$now));if(!empty($o['temporary_subscription_url'])&&$o['temporary_subscription_status']==='active'){$this->pdo->prepare('UPDATE orders SET temporary_subscription_status="revoked",temporary_subscription_revoked_at=? WHERE id=?')->execute(array($now,$o['id']));$this->event($o['id'],'temporary_subscription_revoked','pending_delivery','pending_delivery','正式人工发货后，临时订阅地址已失效。','admin',$adminId,$now);$this->notify($o['user_id'],'temporary_subscription_revoked','临时订阅已失效','订单 '.$no.' 已完成正式发货，临时订阅地址已失效，请使用新的正式订阅。',$no,$now);}$this->pdo->prepare('UPDATE orders SET status="delivered",delivered_at=?,updated_at=? WHERE id=?')->execute(array($now,$now,$o['id']));$this->event($o['id'],'delivered','pending_delivery','delivered','管理员已完成发货，交付内容已加密保存。','admin',$adminId,$now);$this->notify($o['user_id'],'delivered','订单已发货','订单 '.$no.' 已完成发货，请登录查看交付内容。',$no,$now);$u=$this->pdo->prepare('SELECT email FROM users WHERE id=?');$u->execute(array($o['user_id']));$email=$u->fetchColumn();if($email) (new NotificationOutboxService($this->pdo))->enqueue('order_delivered','email:order_delivered:'.$o['id'],array('to'=>$email,'subject'=>'订单已发货','body'=>'您的订单 '.$no.' 已完成发货，请登录网站查看交付内容。'),'email');$this->log($adminId,'deliver_order','order',$o['id'],array('order_no'=>$no,'version'=>$v));$this->pdo->commit();}
        catch(Exception $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function cancel($no,$actorType,$actorId,$reason)
    {
        $reason=substr(trim($reason),0,500);if($reason==='')throw new InvalidArgumentException('取消原因不能为空。');$this->pdo->beginTransaction();
        try{$o=$this->lock($no);if(!$o)throw new RuntimeException('订单不存在。');if($actorType==='user'&&(int)$o['user_id']!==(int)$actorId)throw new RuntimeException('无权操作该订单。');if(!in_array($o['status'],array('pending_payment','pending_delivery'),true))throw new RuntimeException('当前订单状态不能取消。');$this->lockSettlementUsers($o,false);$this->refundBalancePayment($o,$actorType==='admin'?$actorId:null);$now=date('Y-m-d H:i:s');$this->revokeTemporarySubscription($o,$now);$this->pdo->prepare('UPDATE payment_records SET status="cancelled",updated_at=? WHERE order_id=? AND status="pending"')->execute(array($now,$o['id']));$this->pdo->prepare('UPDATE orders SET status="cancelled",cancelled_at=?,cancelled_by_type=?,cancelled_by_id=?,cancel_reason=?,updated_at=? WHERE id=?')->execute(array($now,$actorType,$actorId,$reason,$now,$o['id']));$this->event($o['id'],'cancelled',$o['status'],'cancelled','订单已取消：'.$reason,$actorType,$actorId,$now);$this->notify($o['user_id'],'cancelled','订单已取消','订单 '.$no.' 已取消：'.$reason,$no,$now);$this->reverseCoupon($o);$activity=new ActivityService($this->pdo);$activity->reverseOrder($o['id']);$activity->reverseGroupOrder($o['id']);if($actorType==='admin')$this->log($actorId,'cancel_order','order',$o['id'],array('order_no'=>$no,'reason'=>$reason));$this->pdo->commit();}
        catch(Exception $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function requestRefund($no,$userId,$reason)
    {
        $reason=substr(trim($reason),0,500);if($reason==='')throw new InvalidArgumentException('退款原因不能为空。');$this->pdo->beginTransaction();
        try{$s=$this->pdo->prepare('SELECT * FROM orders WHERE order_no=? AND user_id=? FOR UPDATE');$s->execute(array($no,$userId));$o=$s->fetch();if(!$o)throw new RuntimeException('订单不存在或无权操作。');if(!in_array($o['status'],array('pending_delivery','delivered'),true))throw new RuntimeException('当前订单状态不能申请退款。');$now=date('Y-m-d H:i:s');$this->pdo->prepare('UPDATE orders SET status="refunding",refund_previous_status=?,refund_requested_at=?,refund_reason=?,updated_at=? WHERE id=?')->execute(array($o['status'],$now,$reason,$now,$o['id']));$this->event($o['id'],'refund_requested',$o['status'],'refunding','用户申请退款：'.$reason,'user',$userId,$now);$this->notify($o['user_id'],'refund_requested','退款申请已提交','订单 '.$no.' 的退款申请已提交，等待管理员处理。',$no,$now);$this->pdo->commit();}
        catch(Exception $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function processRefund($no,$adminId,$approve,$reason)
    {
        $reason=substr(trim($reason),0,500);if($reason==='')throw new InvalidArgumentException('退款处理说明不能为空。');$this->pdo->beginTransaction();
        try{$o=$this->lock($no);if(!$o||$o['status']!=='refunding')throw new RuntimeException('当前订单没有待处理退款申请。');$ctx=(new GroupService($this->pdo))->orderContext($o['id']);if($ctx&&(int)$ctx['group']['flow_version']===2&&($ctx['group']['status']==='failed'||$ctx['seat']['status']==='refund_pending'))throw new RuntimeException('失败拼团退款不可驳回，请使用拼团原渠道退款登记或余额重试。');$now=date('Y-m-d H:i:s');$next=$approve?'refunded':(in_array($o['refund_previous_status'],array('pending_delivery','delivered'),true)?$o['refund_previous_status']:(!empty($o['delivered_at'])?'delivered':'pending_delivery'));$type=$approve?'refund_processed':'refund_rejected';$desc=($approve?'退款已处理：':'退款申请未通过：').$reason;$this->pdo->prepare('UPDATE orders SET status=?,refund_processed_at=?,refund_reason=?,updated_at=? WHERE id=?')->execute(array($next,$now,$reason,$now,$o['id']));$this->event($o['id'],$type,'refunding',$next,$desc,'admin',$adminId,$now);$this->notify($o['user_id'],$type,$approve?'退款已处理':'退款申请未通过','订单 '.$no.' '.$desc,$no,$now);if($approve){$this->lockSettlementUsers($o,false);$this->refundBalancePayment($o,$adminId);$this->revokeTemporarySubscription($o,$now);$this->reverseCoupon($o);$activity=new ActivityService($this->pdo);$activity->reverseOrder($o['id']);$activity->reverseGroupOrder($o['id']);}$this->log($adminId,$type,'order',$o['id'],array('order_no'=>$no,'reason'=>$reason));$this->pdo->commit();}
        catch(Exception $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function events($orderId){$s=$this->pdo->prepare('SELECT * FROM order_events WHERE order_id=? ORDER BY created_at,id');$s->execute(array($orderId));return $s->fetchAll();}
    public function getDeliveryForAdmin($no,$config){$s=$this->pdo->prepare('SELECT o.*,d.* FROM orders o LEFT JOIN delivery_records d ON d.order_id=o.id AND d.is_current=1 WHERE o.order_no=?');$s->execute(array($no));$row=$s->fetch();if(!$row||!$row['content_ciphertext'])return null;$crypto=new CryptoService($config);return json_decode($crypto->decrypt($row['content_ciphertext'],$row['content_nonce'],$row['content_tag']),true);}
    public function updateDelivery($no,$adminId,$payload,$config)
    {
        $this->pdo->beginTransaction();try{$o=$this->lock($no);if(!$o||$o['status']!=='delivered')throw new RuntimeException('只有已发货订单可以修改交付内容。');$current=$this->pdo->prepare('SELECT * FROM delivery_records WHERE order_id=? AND is_current=1 LIMIT 1');$current->execute(array($o['id']));$current=$current->fetch();$existingPayload=array();if($current){$existingPayload=json_decode((new CryptoService($config))->decrypt($current['content_ciphertext'],$current['content_nonce'],$current['content_tag']),true);}$payload=$this->normalizeDeliveryPayload($payload,$config,$existingPayload,false,$this->productType($o['product_type_snapshot']));$s=$this->pdo->prepare('SELECT COALESCE(MAX(version_no),0)+1 v FROM delivery_records WHERE order_id=?');$s->execute(array($o['id']));$v=(int)$s->fetch()['v'];$crypto=new CryptoService($config);list($cipher,$nonce,$tag)=$crypto->encrypt(json_encode($payload,JSON_UNESCAPED_UNICODE));$now=date('Y-m-d H:i:s');$this->pdo->prepare('UPDATE delivery_records SET is_current=0 WHERE order_id=?')->execute(array($o['id']));$this->pdo->prepare('INSERT INTO delivery_records(order_id,version_no,action,content_ciphertext,content_nonce,content_tag,content_preview,is_current,opened_at,expires_at,created_by,created_at) VALUES(?,? ,"updated",?,?,?, ?,1,?,?,?,?)')->execute(array($o['id'],$v,$cipher,$nonce,$tag,'已加密交付内容',$payload['opened_at']?:null,$payload['expires_at']?:null,$adminId,$now));$this->pdo->prepare('UPDATE orders SET updated_at=? WHERE id=?')->execute(array($now,$o['id']));$this->event($o['id'],'delivery_updated','delivered','delivered','管理员已更新交付内容。','admin',$adminId,$now);$this->notify($o['user_id'],'delivery_updated','交付内容已更新','订单 '.$no.' 的交付内容已更新，请登录查看最新内容。',$no,$now);$this->log($adminId,'update_delivery','order',$o['id'],array('order_no'=>$no,'version'=>$v));$this->pdo->commit();}catch(Exception $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function revertToPendingDelivery($no,$adminId,$reason)
    {
        $reason=substr(trim($reason),0,500);if($reason==='')throw new InvalidArgumentException('退回原因不能为空。');$this->pdo->beginTransaction();try{$o=$this->lock($no);if(!$o||$o['status']!=='delivered')throw new RuntimeException('只有已发货订单可以退回待发货。');$now=date('Y-m-d H:i:s');$this->pdo->prepare('UPDATE delivery_records SET is_current=0 WHERE order_id=?')->execute(array($o['id']));$this->pdo->prepare('UPDATE orders SET status="pending_delivery",delivered_at=NULL,updated_at=? WHERE id=?')->execute(array($now,$o['id']));$this->event($o['id'],'delivery_reverted','delivered','pending_delivery','订单退回待发货：'.$reason,'admin',$adminId,$now);$this->notify($o['user_id'],'delivery_reverted','订单重新进入发货处理','订单 '.$no.' 已退回待发货，请留意后续交付。',$no,$now);$this->log($adminId,'revert_delivery','order',$o['id'],array('order_no'=>$no,'reason'=>$reason));$this->pdo->commit();}catch(Exception $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    private function productType($type){if(!in_array($type,array('vpn','account'),true))throw new InvalidArgumentException('商品类型无效。');return $type;}
    private function reverseCoupon($order){(new ActivityService($this->pdo))->releaseCoupon($order['id']);}
    private function temporarySubscriptionUrl(){try{$s=$this->pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key="temporary_subscription_url" LIMIT 1');$s->execute();$row=$s->fetch();if($row)return trim((string)$row['setting_value']);}catch(Exception $e){}return isset($GLOBALS['config']['app']['temporary_subscription_url'])?$GLOBALS['config']['app']['temporary_subscription_url']:'';}
    private function lock($no){$s=$this->pdo->prepare('SELECT * FROM orders WHERE order_no=? FOR UPDATE');$s->execute(array($no));return $s->fetch();}
    private function event($orderId,$type,$old,$new,$description,$actorType,$actorId,$now){$this->pdo->prepare('INSERT INTO order_events(order_id,event_type,old_status,new_status,description,actor_type,actor_id,created_at) VALUES(?,?,?,?,?,?,?,?)')->execute(array($orderId,$type,$old,$new,$description,$actorType,$actorId,$now));}
    private function notify($userId,$type,$title,$content,$orderNo,$now){
        $s=$this->pdo->prepare('SELECT o.id, (SELECT MAX(e.id) FROM order_events e WHERE e.order_id=o.id AND e.event_type=?) event_id FROM orders o WHERE o.order_no=? AND o.user_id=?');
        $s->execute(array($type,$orderNo,$userId));$event=$s->fetch();
        $key=$event&&!empty($event['event_id'])?'order_event:'.$event['event_id']:null;
        (new NotificationService($this->pdo))->create($userId,$type,$title,$content,$orderNo,'order',$event?(int)$event['id']:null,$key);
    }
    private function log($adminId,$action,$type,$id,$details){$this->pdo->prepare('INSERT INTO admin_logs(admin_id,action,target_type,target_id,details_json,created_at) VALUES(?,?,?,?,?,?)')->execute(array($adminId,$action,$type,$id,json_encode($details),date('Y-m-d H:i:s')));}
}
