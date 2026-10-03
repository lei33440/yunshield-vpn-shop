<?php
class OrderService
{
    private $pdo;
    public function __construct($pdo) { $this->pdo = $pdo; }
    public function create($userId, $productId, $planId, $note, $promotionCode = '')
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM products WHERE id=? AND status="active" FOR UPDATE'); $stmt->execute(array($productId)); $p = $stmt->fetch();
            if (!$p) throw new RuntimeException('套餐不存在或已下架。');
            $stmt = $this->pdo->prepare('SELECT * FROM product_plans WHERE id=? AND product_id=? AND status="active" FOR UPDATE'); $stmt->execute(array($planId,$productId)); $plan = $stmt->fetch();
            if (!$plan) throw new RuntimeException('所选时长方案不存在或已下架。');
            $now=date('Y-m-d H:i:s'); $suffix=''; $alphabet='0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'; for($i=0;$i<5;$i++) $suffix.=$alphabet[random_int(0,35)]; $no='CX'.date('ymd').$suffix;
            $original=(int)$plan['price_cents']; $discount=0; $promotion=array('discount'=>0); $promotionCode=trim((string)$promotionCode);$activity=new ActivityService($this->pdo);
            if(strpos($promotionCode,'group:')===0){$promotion=$activity->prepareGroupPromotion((int)substr($promotionCode,6),$userId,(int)$plan['id'],$original);$discount=(int)$promotion['discount'];}
            elseif($promotionCode!==''){$promotion=$activity->prepareCoupon($userId,$promotionCode,(int)$plan['id'],$original);$discount=(int)$promotion['discount'];}
            $total=max(0,$original-$discount);
            $sql='INSERT INTO orders(order_no,user_id,product_id,product_plan_id,product_name_snapshot,plan_name_snapshot,product_description_snapshot,traffic_snapshot,region_snapshot,device_limit_snapshot,duration_days_snapshot,unit_price_cents_snapshot,original_amount_cents,discount_amount_cents,promotion_code,total_amount_cents,status,customer_note,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
            $this->pdo->prepare($sql)->execute(array($no,$userId,$p['id'],$plan['id'],$p['name'],$plan['name'],$p['description'],$p['traffic'],$p['region'],$p['device_limit'],$plan['duration_days'],$original,$original,$discount,$promotionCode,$total,'pending_payment',substr($note,0,500),$now,$now));
            $id=$this->pdo->lastInsertId();
            if(isset($promotion['coupon']))$activity->applyPromotion($id,$promotion,$promotionCode);elseif(isset($promotion['group'])){$activity->attachGroupOrder($id,$promotion);$this->pdo->prepare('INSERT INTO order_promotions(order_id,promotion_key,promotion_type,promotion_id,promotion_code,discount_amount_cents,idempotency_key,created_at,updated_at) VALUES(?,"group","group",?,?,?, ?,?,?)')->execute(array($id,$promotion['group']['id'],$promotionCode,$discount,'group:'.$id,$now,$now));}
            $this->pdo->prepare('INSERT INTO payment_records(order_id,amount_cents,status,created_at,updated_at) VALUES(?, ?,"pending",?,?)')->execute(array($id,$total,$now,$now));
            $this->event($id,'created',null,'pending_payment','订单已提交，等待人工确认付款。','user',$userId,$now);
            $this->pdo->commit(); return $no;
        } catch (Exception $e) { if($this->pdo->inTransaction())$this->pdo->rollBack(); throw $e; }
    }
    public function getForUser($no,$userId){$s=$this->pdo->prepare('SELECT * FROM orders WHERE order_no=? AND user_id=?');$s->execute(array($no,$userId));return $s->fetch();}
    public function confirmPayment($no,$adminId,$config=array())
    {
        $this->confirmPaymentInternal($no,'admin',$adminId,array());
    }
    public function confirmPaymentSystem($no,$gatewayType,$tradeNo,$payload)
    {
        $this->confirmPaymentInternal($no,'system',null,array('gateway_type'=>$gatewayType,'gateway_trade_no'=>$tradeNo,'notify_payload'=>$payload));
    }
    private function confirmPaymentInternal($no,$actorType,$actorId,$paymentData)
    {
        $this->pdo->beginTransaction();
        try{
            $o=$this->lock($no); if(!$o) throw new RuntimeException('订单不存在。');
            if($o['status']==='pending_payment'){
                $now=date('Y-m-d H:i:s'); $tempUrl=$this->temporarySubscriptionUrl();
                $this->pdo->prepare('UPDATE orders SET status="pending_delivery",paid_at=?,temporary_subscription_url=?,temporary_subscription_status=?,temporary_subscription_issued_at=?,updated_at=? WHERE id=?')->execute(array($now,$tempUrl,$tempUrl?'active':'none',$tempUrl?$now:null,$now,$o['id']));
                if($tempUrl){$this->event($o['id'],'temporary_subscription_issued','pending_delivery','pending_delivery','已发放临时订阅地址，正式人工发货后将自动失效。',$actorType,$actorId,$now);$this->notify($o['user_id'],'temporary_subscription_issued','临时订阅已生效','订单 '.$no.' 已确认付款，临时订阅地址现已可用。正式人工发货后该地址会失效。',$no,$now);}
                if($actorType==='system') $this->pdo->prepare('UPDATE payment_records SET status="confirmed",confirmed_by=NULL,confirmed_at=?,gateway_type=?,gateway_trade_no=?,paid_at=?,notify_payload=?,updated_at=? WHERE order_id=? AND status="pending"')->execute(array($now,$paymentData['gateway_type'],$paymentData['gateway_trade_no'],$now,$paymentData['notify_payload'],$now,$o['id']));
                else $this->pdo->prepare('UPDATE payment_records SET status="confirmed",confirmed_by=?,confirmed_at=?,paid_at=?,updated_at=? WHERE order_id=? AND status="pending"')->execute(array($actorId,$now,$now,$now,$o['id']));
                $desc=$actorType==='system'?'易支付异步通知已确认付款，订单进入发货队列。':'管理员已确认付款，订单进入发货队列。';
                $this->event($o['id'],'payment_confirmed','pending_payment','pending_delivery',$desc,$actorType,$actorId,$now);$this->notify($o['user_id'],'payment_confirmed','付款已确认','订单 '.$no.' 已确认付款，请等待人工发货。',$no,$now);$activity=new ActivityService($this->pdo);$activity->settlePaidOrder($o['id']);$activity->settleGroupOrder($o['id']);
                if($actorType==='admin')$this->log($actorId,'confirm_payment','order',$o['id'],array('order_no'=>$no));
            }elseif($o['status']!=='pending_delivery'&&$o['status']!=='delivered') throw new RuntimeException('当前订单状态不能确认付款。');
            $this->pdo->commit();
        }catch(Exception $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function deliver($no,$adminId,$payload,$config)
    {
        $this->pdo->beginTransaction();
        try{$o=$this->lock($no);if(!$o||$o['status']!=='pending_delivery')throw new RuntimeException('订单未付款或已发货，不能重复发货。');$s=$this->pdo->prepare('SELECT COALESCE(MAX(version_no),0)+1 v FROM delivery_records WHERE order_id=?');$s->execute(array($o['id']));$v=(int)$s->fetch()['v'];$crypto=new CryptoService($config);list($cipher,$nonce,$tag)=$crypto->encrypt(json_encode($payload,JSON_UNESCAPED_UNICODE));$now=date('Y-m-d H:i:s');$this->pdo->prepare('UPDATE delivery_records SET is_current=0 WHERE order_id=?')->execute(array($o['id']));$this->pdo->prepare('INSERT INTO delivery_records(order_id,version_no,action,content_ciphertext,content_nonce,content_tag,content_preview,is_current,opened_at,expires_at,created_by,created_at) VALUES(?,? ,"created",?,?,?, ?,1,?,?,?,?)')->execute(array($o['id'],$v,$cipher,$nonce,$tag,'已加密交付内容',$payload['opened_at']?:null,$payload['expires_at']?:null,$adminId,$now));if(!empty($o['temporary_subscription_url'])&&$o['temporary_subscription_status']==='active'){$this->pdo->prepare('UPDATE orders SET temporary_subscription_status="revoked",temporary_subscription_revoked_at=? WHERE id=?')->execute(array($now,$o['id']));$this->event($o['id'],'temporary_subscription_revoked','pending_delivery','pending_delivery','正式人工发货后，临时订阅地址已失效。','admin',$adminId,$now);$this->notify($o['user_id'],'temporary_subscription_revoked','临时订阅已失效','订单 '.$no.' 已完成正式发货，临时订阅地址已失效，请使用新的正式订阅。',$no,$now);}$this->pdo->prepare('UPDATE orders SET status="delivered",delivered_at=?,updated_at=? WHERE id=?')->execute(array($now,$now,$o['id']));$this->event($o['id'],'delivered','pending_delivery','delivered','管理员已完成发货，交付内容已加密保存。','admin',$adminId,$now);$this->notify($o['user_id'],'delivered','订单已发货','订单 '.$no.' 已完成发货，请登录查看交付内容。',$no,$now);$this->log($adminId,'deliver_order','order',$o['id'],array('order_no'=>$no,'version'=>$v));$this->pdo->commit();}
        catch(Exception $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function cancel($no,$actorType,$actorId,$reason)
    {
        $reason=substr(trim($reason),0,500);if($reason==='')throw new InvalidArgumentException('取消原因不能为空。');$this->pdo->beginTransaction();
        try{$o=$this->lock($no);if(!$o)throw new RuntimeException('订单不存在。');if($actorType==='user'&&(int)$o['user_id']!==(int)$actorId)throw new RuntimeException('无权操作该订单。');if(!in_array($o['status'],array('pending_payment','pending_delivery'),true))throw new RuntimeException('当前订单状态不能取消。');$now=date('Y-m-d H:i:s');$this->pdo->prepare('UPDATE orders SET status="cancelled",cancelled_at=?,cancelled_by_type=?,cancelled_by_id=?,cancel_reason=?,updated_at=? WHERE id=?')->execute(array($now,$actorType,$actorId,$reason,$now,$o['id']));$this->event($o['id'],'cancelled',$o['status'],'cancelled','订单已取消：'.$reason,$actorType,$actorId,$now);$this->notify($o['user_id'],'cancelled','订单已取消','订单 '.$no.' 已取消：'.$reason,$no,$now);$this->reverseCoupon($o);$activity=new ActivityService($this->pdo);$activity->reverseOrder($o['id']);$activity->reverseGroupOrder($o['id']);if($actorType==='admin')$this->log($actorId,'cancel_order','order',$o['id'],array('order_no'=>$no,'reason'=>$reason));$this->pdo->commit();}
        catch(Exception $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function requestRefund($no,$userId,$reason)
    {
        $reason=substr(trim($reason),0,500);if($reason==='')throw new InvalidArgumentException('退款原因不能为空。');$this->pdo->beginTransaction();
        try{$s=$this->pdo->prepare('SELECT * FROM orders WHERE order_no=? AND user_id=? FOR UPDATE');$s->execute(array($no,$userId));$o=$s->fetch();if(!$o)throw new RuntimeException('订单不存在或无权操作。');if(!in_array($o['status'],array('pending_delivery','delivered'),true))throw new RuntimeException('当前订单状态不能申请退款。');$now=date('Y-m-d H:i:s');$this->pdo->prepare('UPDATE orders SET status="refunding",refund_requested_at=?,refund_reason=?,updated_at=? WHERE id=?')->execute(array($now,$reason,$now,$o['id']));$this->event($o['id'],'refund_requested',$o['status'],'refunding','用户申请退款：'.$reason,'user',$userId,$now);$this->notify($o['user_id'],'refund_requested','退款申请已提交','订单 '.$no.' 的退款申请已提交，等待管理员处理。',$no,$now);$this->pdo->commit();}
        catch(Exception $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function processRefund($no,$adminId,$approve,$reason)
    {
        $reason=substr(trim($reason),0,500);if($reason==='')throw new InvalidArgumentException('退款处理说明不能为空。');$this->pdo->beginTransaction();
        try{$o=$this->lock($no);if(!$o||$o['status']!=='refunding')throw new RuntimeException('当前订单没有待处理退款申请。');$now=date('Y-m-d H:i:s');$next=$approve?'refunded':'delivered';$type=$approve?'refund_processed':'refund_rejected';$desc=($approve?'退款已处理：':'退款申请未通过：').$reason;$this->pdo->prepare('UPDATE orders SET status=?,refund_processed_at=?,refund_reason=?,updated_at=? WHERE id=?')->execute(array($next,$now,$reason,$now,$o['id']));$this->event($o['id'],$type,'refunding',$next,$desc,'admin',$adminId,$now);$this->notify($o['user_id'],$type,$approve?'退款已处理':'退款申请未通过','订单 '.$no.' '.$desc,$no,$now);if($approve){$this->reverseCoupon($o);$activity=new ActivityService($this->pdo);$activity->reverseOrder($o['id']);$activity->reverseGroupOrder($o['id']);}$this->log($adminId,$type,'order',$o['id'],array('order_no'=>$no,'reason'=>$reason));$this->pdo->commit();}
        catch(Exception $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function events($orderId){$s=$this->pdo->prepare('SELECT * FROM order_events WHERE order_id=? ORDER BY created_at,id');$s->execute(array($orderId));return $s->fetchAll();}
    public function getDeliveryForAdmin($no,$config){$s=$this->pdo->prepare('SELECT o.*,d.* FROM orders o LEFT JOIN delivery_records d ON d.order_id=o.id AND d.is_current=1 WHERE o.order_no=?');$s->execute(array($no));$row=$s->fetch();if(!$row||!$row['content_ciphertext'])return null;$crypto=new CryptoService($config);return json_decode($crypto->decrypt($row['content_ciphertext'],$row['content_nonce'],$row['content_tag']),true);}
    public function updateDelivery($no,$adminId,$payload,$config)
    {
        $this->pdo->beginTransaction();try{$o=$this->lock($no);if(!$o||$o['status']!=='delivered')throw new RuntimeException('只有已发货订单可以修改交付内容。');$s=$this->pdo->prepare('SELECT COALESCE(MAX(version_no),0)+1 v FROM delivery_records WHERE order_id=?');$s->execute(array($o['id']));$v=(int)$s->fetch()['v'];$crypto=new CryptoService($config);list($cipher,$nonce,$tag)=$crypto->encrypt(json_encode($payload,JSON_UNESCAPED_UNICODE));$now=date('Y-m-d H:i:s');$this->pdo->prepare('UPDATE delivery_records SET is_current=0 WHERE order_id=?')->execute(array($o['id']));$this->pdo->prepare('INSERT INTO delivery_records(order_id,version_no,action,content_ciphertext,content_nonce,content_tag,content_preview,is_current,opened_at,expires_at,created_by,created_at) VALUES(?,? ,"updated",?,?,?, ?,1,?,?,?,?)')->execute(array($o['id'],$v,$cipher,$nonce,$tag,'已加密交付内容',$payload['opened_at']?:null,$payload['expires_at']?:null,$adminId,$now));$this->pdo->prepare('UPDATE orders SET updated_at=? WHERE id=?')->execute(array($now,$o['id']));$this->event($o['id'],'delivery_updated','delivered','delivered','管理员已更新交付内容。','admin',$adminId,$now);$this->notify($o['user_id'],'delivery_updated','交付内容已更新','订单 '.$no.' 的交付内容已更新，请登录查看最新内容。',$no,$now);$this->log($adminId,'update_delivery','order',$o['id'],array('order_no'=>$no,'version'=>$v));$this->pdo->commit();}catch(Exception $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function revertToPendingDelivery($no,$adminId,$reason)
    {
        $reason=substr(trim($reason),0,500);if($reason==='')throw new InvalidArgumentException('退回原因不能为空。');$this->pdo->beginTransaction();try{$o=$this->lock($no);if(!$o||$o['status']!=='delivered')throw new RuntimeException('只有已发货订单可以退回待发货。');$now=date('Y-m-d H:i:s');$this->pdo->prepare('UPDATE delivery_records SET is_current=0 WHERE order_id=?')->execute(array($o['id']));$this->pdo->prepare('UPDATE orders SET status="pending_delivery",delivered_at=NULL,updated_at=? WHERE id=?')->execute(array($now,$o['id']));$this->event($o['id'],'delivery_reverted','delivered','pending_delivery','订单退回待发货：'.$reason,'admin',$adminId,$now);$this->notify($o['user_id'],'delivery_reverted','订单重新进入发货处理','订单 '.$no.' 已退回待发货，请留意后续交付。',$no,$now);$this->log($adminId,'revert_delivery','order',$o['id'],array('order_no'=>$no,'reason'=>$reason));$this->pdo->commit();}catch(Exception $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    private function reverseCoupon($order){$s=$this->pdo->prepare('SELECT coupon_id FROM order_promotions WHERE order_id=? AND promotion_type="coupon" AND status="applied" FOR UPDATE');$s->execute(array($order['id']));$couponId=(int)$s->fetchColumn();if($couponId){$this->pdo->prepare('UPDATE coupons SET status=IF(expires_at IS NULL OR expires_at>=NOW(),"active","expired"),used_order_id=NULL,used_quantity=GREATEST(used_quantity-1,0),updated_at=? WHERE id=? AND used_order_id=?')->execute(array(date('Y-m-d H:i:s'),$couponId,$order['id']));$this->pdo->prepare('UPDATE order_promotions SET status="reversed",updated_at=? WHERE order_id=? AND coupon_id=?')->execute(array(date('Y-m-d H:i:s'),$order['id'],$couponId));}}
    private function temporarySubscriptionUrl(){try{$s=$this->pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key="temporary_subscription_url" LIMIT 1');$s->execute();$row=$s->fetch();if($row)return trim((string)$row['setting_value']);}catch(Exception $e){}return isset($GLOBALS['config']['app']['temporary_subscription_url'])?$GLOBALS['config']['app']['temporary_subscription_url']:'';}
    private function lock($no){$s=$this->pdo->prepare('SELECT * FROM orders WHERE order_no=? FOR UPDATE');$s->execute(array($no));return $s->fetch();}
    private function event($orderId,$type,$old,$new,$description,$actorType,$actorId,$now){$this->pdo->prepare('INSERT INTO order_events(order_id,event_type,old_status,new_status,description,actor_type,actor_id,created_at) VALUES(?,?,?,?,?,?,?,?)')->execute(array($orderId,$type,$old,$new,$description,$actorType,$actorId,$now));}
    private function notify($userId,$type,$title,$content,$orderNo,$now){$this->pdo->prepare('INSERT INTO notifications(user_id,type,title,content,order_no,created_at) VALUES(?,?,?,?,?,?)')->execute(array($userId,$type,$title,$content,$orderNo,$now));}
    private function log($adminId,$action,$type,$id,$details){$this->pdo->prepare('INSERT INTO admin_logs(admin_id,action,target_type,target_id,details_json,created_at) VALUES(?,?,?,?,?,?)')->execute(array($adminId,$action,$type,$id,json_encode($details),date('Y-m-d H:i:s')));}
}
