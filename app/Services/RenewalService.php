<?php
/** 人工续费服务；不触发促销、邮件、任务进程或上游同步。 */
class RenewalService
{
    private $pdo;
    private $config;
    private $timezone;

    public function __construct($pdo, $config=array())
    {
        $this->pdo=$pdo; $this->config=$config;
        $this->timezone=new DateTimeZone(isset($config['app']['timezone'])?$config['app']['timezone']:date_default_timezone_get());
    }
    public static function isRenewal($order) { return is_array($order)&&isset($order['order_kind'])&&$order['order_kind']==='renewal'; }
    public static function statusLabel($order)
    {
        $status=isset($order['status'])?$order['status']:'';
        $labels=array('pending_payment'=>'待付款','pending_delivery'=>'续费处理中','delivered'=>'续费成功','refunding'=>'退款处理中','refunded'=>'已退款','cancelled'=>'已取消');
        if(self::isRenewal($order))return isset($labels[$status])?$labels[$status]:$status;
        if(function_exists('status_text'))return status_text($status);
        $labels['pending_delivery']='待发货'; $labels['delivered']='已发货'; $labels['paid_waiting_group']='待成团';
        return isset($labels[$status])?$labels[$status]:$status;
    }
    private function id($value)
    {
        if((!is_int($value)&&!is_string($value))||!preg_match('/\A[1-9][0-9]*\z/D',(string)$value)||strlen((string)$value)>strlen((string)PHP_INT_MAX)||(strlen((string)$value)===strlen((string)PHP_INT_MAX)&&strcmp((string)$value,(string)PHP_INT_MAX)>0))throw new InvalidArgumentException('记录编号无效。');
        return (int)$value;
    }
    private function no($value)
    {
        if(!is_string($value)||!preg_match('/\A[A-Za-z0-9_-]{1,40}\z/D',$value))throw new InvalidArgumentException('订单号无效。');
        return $value;
    }
    private function row($sql,$args=array()) { $s=$this->pdo->prepare($sql);$s->execute($args);return $s->fetch(PDO::FETCH_ASSOC); }
    private function rows($sql,$args=array()) { $s=$this->pdo->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC); }
    private function run($sql,$args) { $s=$this->pdo->prepare($sql);$s->execute($args);return $s; }
    private function now() { return new DateTimeImmutable('now',$this->timezone); }
    private function date($value)
    {
        if(!is_string($value)||!preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D',$value))throw new RuntimeException('交付日期缺失或无效，请联系管理员。');
        $d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,$this->timezone);
        if(!$d||$d->format('Y-m-d H:i:s')!==$value)throw new RuntimeException('交付日期无效，请联系管理员。');
        return $d;
    }
    private function safeDelivery($d) { return array('id'=>(int)$d['id'],'version_no'=>(int)$d['version_no'],'opened_at'=>$d['opened_at'],'expires_at'=>$d['expires_at']); }
    private function current($parentId,$lock=false,$secret=false)
    {
        $fields=$secret?'*':'id,version_no,opened_at,expires_at';
        $rows=$this->rows('SELECT '.$fields.' FROM delivery_records WHERE order_id=? AND is_current=1'.($lock?' FOR UPDATE':''),array($parentId));
        if(count($rows)!==1)throw new RuntimeException('原订单必须具有唯一的当前交付记录。');
        $d=$rows[0];$opened=$this->date($d['opened_at']);$expires=$this->date($d['expires_at']);
        if($expires<=$opened||$opened>$this->now())throw new RuntimeException('原订单交付日期不符合续费条件。');
        $this->id($d['id']);$this->id($d['version_no']);return $d;
    }
    private function eligible($parent,$lock=false,$secret=false)
    {
        if(!$parent||self::isRenewal($parent)||(isset($parent['order_kind'])&&$parent['order_kind']!=='purchase')||$parent['status']!=='delivered'||$parent['product_type_snapshot']!=='vpn')throw new RuntimeException('仅已交付的原VPN购买订单可续费。');
        $u=$this->row('SELECT status FROM users WHERE id=?',array($parent['user_id']));
        if(!$u||$u['status']!=='active')throw new RuntimeException('用户不存在或账户已停用。');
        // 报价、创建和本地付款都必须验证真实交付可用，不能等付款后才发现无法续费。
        $delivery=$this->current($parent['id'],$lock,true);
        $this->payload($delivery);
        return $secret?$delivery:$this->safeDelivery($delivery);
    }
    private function active($id,$lock=false)
    {
        $rows=$this->rows('SELECT * FROM orders WHERE parent_order_id=? AND order_kind="renewal" AND status IN ("pending_payment","pending_delivery","refunding") ORDER BY id'.($lock?' FOR UPDATE':''),array($id));
        if(count($rows)>1)throw new RuntimeException('存在多笔进行中的续费，请联系管理员核对。');
        return $rows?$rows[0]:null;
    }
    private function option($key,$planId,$name,$days,$price,$source)
    {
        $days=$this->id($days);
        if($days>4294967295||(!is_int($price)&&!is_string($price))||!preg_match('/\A(?:0|[1-9][0-9]*)\z/D',(string)$price)||strlen((string)$price)>strlen((string)PHP_INT_MAX)||(strlen((string)$price)===strlen((string)PHP_INT_MAX)&&strcmp((string)$price,(string)PHP_INT_MAX)>0))throw new RuntimeException('续费时长或价格无效。');
        return array('key'=>$key,'plan_id'=>$planId===null?null:$this->id($planId),'name'=>$name,'duration_days'=>$days,'price_cents'=>(int)$price,'price_source'=>$source);
    }
    private function options($parent,$lock=false)
    {
        $original=$this->option('original',$parent['product_plan_id'],$parent['plan_name_snapshot'],$parent['duration_days_snapshot'],$parent['unit_price_cents_snapshot'],'original');$out=array($original);
        $product=$this->row('SELECT id,product_type FROM products WHERE id=?'.($lock?' FOR UPDATE':''),array($parent['product_id']));
        if(!$product||$product['product_type']!=='vpn')return $out;
        $plans=$this->rows('SELECT * FROM product_plans WHERE product_id=? AND status="active" ORDER BY duration_days,id'.($lock?' FOR UPDATE':''),array($parent['product_id']));
        foreach($plans as $p){if((int)$p['duration_days']===$original['duration_days'])continue;$out[]=$this->option('plan:'.$p['id'],$p['id'],$p['name'],$p['duration_days'],$p['price_cents'],'current');}
        return $out;
    }
    public function quote($parentNo,$userId)
    {
        $p=$this->row('SELECT * FROM orders WHERE order_no=? AND user_id=?',array($this->no($parentNo),$this->id($userId)));
        $d=$this->eligible($p);return array('parent'=>$p,'delivery'=>$this->safeDelivery($d),'options'=>$this->options($p),'active'=>$this->active($p['id']));
    }
    public function create($parentNo,$uid,$optionKey,$requestKey)
    {
        $parentNo=$this->no($parentNo);$uid=$this->id($uid);
        if(!is_string($optionKey)||strlen($optionKey)>32||!preg_match('/\A(?:original|plan:[1-9][0-9]*)\z/D',$optionKey))throw new InvalidArgumentException('续费方案无效。');
        if(!is_string($requestKey)||!preg_match('/\A[a-f0-9]{64}\z/D',$requestKey))throw new InvalidArgumentException('订单请求标识无效。');
        if($this->pdo->inTransaction())throw new LogicException('续费创建不能嵌套事务。');
        $this->pdo->beginTransaction();
        try{
            $p=$this->row('SELECT * FROM orders WHERE order_no=? AND user_id=? FOR UPDATE',array($parentNo,$uid));
            if(!$p)throw new RuntimeException('原订单不存在或无权操作。');
            // 按父单→用户加锁，使不同父单上的同用户唯一请求标识也串行处理。
            $this->row('SELECT id FROM users WHERE id=? FOR UPDATE',array($uid));
            $existing=$this->row('SELECT * FROM orders WHERE user_id=? AND request_key=? FOR UPDATE',array($uid,$requestKey));
            if($existing){
                $r=$this->row('SELECT * FROM order_renewals WHERE order_id=?',array($existing['id']));
                $same=$r&&self::isRenewal($existing)&&(int)$existing['parent_order_id']===(int)$p['id']&&($optionKey==='original'?$r['price_source']==='original':($r['price_source']==='current'&&$optionKey==='plan:'.$existing['product_plan_id']));
                if(!$same)throw new InvalidArgumentException('同一请求不能更改原订单或续费方案。');
                $this->pdo->commit();return $existing['order_no'];
            }
            $d=$this->eligible($p,true);$active=$this->active($p['id'],true);
            if($active){$this->pdo->commit();return $active['order_no'];}
            $selected=null;foreach($this->options($p,true) as $option)if($option['key']===$optionKey)$selected=$option;
            if(!$selected)throw new RuntimeException('所选续费方案已失效，请刷新页面。');
            if(strlen($p['order_no'])>34)throw new RuntimeException('原订单号过长，无法生成续费单号，请联系管理员。');
            $now=$this->now()->format('Y-m-d H:i:s');$alphabet='0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
            do{
                $suffix='';for($i=0;$i<5;$i++)$suffix.=$alphabet[random_int(0,35)];
                $no=$p['order_no'].'-'.$suffix;
            }while($this->row('SELECT id FROM orders WHERE order_no=?',array($no)));
            $data=array('order_no'=>$no,'user_id'=>$uid,'product_id'=>$p['product_id'],'product_plan_id'=>$selected['plan_id'],'order_kind'=>'renewal','parent_order_id'=>$p['id'],'request_key'=>$requestKey);
            foreach($p as $key=>$value)if(substr($key,-9)==='_snapshot')$data[$key]=$value;
            $data['plan_name_snapshot']=$selected['name'];$data['duration_days_snapshot']=$selected['duration_days'];$data['unit_price_cents_snapshot']=$selected['price_cents'];
            if($selected['price_source']==='current'){
                $plan=$this->row('SELECT * FROM product_plans WHERE id=? AND product_id=? AND status="active" FOR UPDATE',array($selected['plan_id'],$p['product_id']));
                if(!$plan)throw new RuntimeException('续费方案已失效。');
                if(array_key_exists('plan_description_snapshot',$data))$data['plan_description_snapshot']=isset($plan['public_description'])?$plan['public_description']:null;
            }
            $data=array_merge($data,array('original_amount_cents'=>$selected['price_cents'],'discount_amount_cents'=>0,'promotion_code'=>'','total_amount_cents'=>$selected['price_cents'],'status'=>'pending_payment','customer_note'=>'','created_at'=>$now,'updated_at'=>$now));
            $this->run('INSERT INTO orders('.implode(',',array_keys($data)).') VALUES('.implode(',',array_fill(0,count($data),'?')).')',array_values($data));$id=(int)$this->pdo->lastInsertId();
            $this->run('INSERT INTO order_renewals(order_id,source_delivery_id,source_delivery_version,duration_days,price_source,price_cents,created_at) VALUES(?,?,?,?,?,?,?)',array($id,$d['id'],$d['version_no'],$selected['duration_days'],$selected['price_source'],$selected['price_cents'],$now));
            $this->run('INSERT INTO payment_records(order_id,method,gateway_type,amount_cents,status,created_at,updated_at) VALUES(?,?,?,?,"pending",?,?)',array($id,$selected['price_cents']===0?'zero':'manual',$selected['price_cents']===0?'zero':'',$selected['price_cents'],$now,$now));
            $child=array_merge($data,array('id'=>$id));$message='续费订单 '.$no.' 已创建，续费 '.$selected['duration_days'].' 天，金额 '.WalletService::formatAmount($selected['price_cents']).' 元，等待付款。';
            $this->event($id,'renewal_created',null,'pending_payment',$message,'user',$uid,$now);
            $this->event($p['id'],'renewal_created','delivered','delivered',$message,'user',$uid,$now);
            $this->notify($child,'renewal_created','续费订单已创建',$message);
            $this->pdo->commit();return $no;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    /** 调用方先持有父单→子单锁；必须在本地扣款或发起付款之前调用。 */
    public function assertPayable($order)
    {
        if(!self::isRenewal($order))return;
        if(!$this->pdo->inTransaction())throw new LogicException('续费付款校验必须在父单锁事务内。');
        if($order['status']!=='pending_payment')throw new RuntimeException('当前续费订单不能付款。');
        $p=$this->row('SELECT * FROM orders WHERE id=? FOR UPDATE',array($this->id($order['parent_order_id'])));
        if(!$p||(int)$p['user_id']!==(int)$order['user_id']||(int)$p['product_id']!==(int)$order['product_id'])throw new RuntimeException('续费原订单绑定无效。');
        $this->eligible($p,true);
    }
    public function settlePayment($o,$payment,$actorType,$actorId,$paymentData)
    {
        if(!$this->pdo->inTransaction()||!self::isRenewal($o)||$o['status']!=='pending_payment')throw new LogicException('续费付款结算状态无效。');
        if(!in_array($actorType,array('admin','system','user'),true))throw new InvalidArgumentException('付款操作来源无效。');
        if($actorType!=='system')$actorId=$this->id($actorId);
        if($actorType==='user'&&$actorId!==$this->id($o['user_id']))throw new RuntimeException('无权支付该续费订单。');
        $warning='';
        if($actorType==='system'){
            try{$this->assertPayable($o);}catch(PDOException $e){throw $e;}catch(RuntimeException $e){$warning=' 原订单已不符合续费条件，已记录到账，禁止完成续费，请人工核对并办理退款。';}
        }else{$this->assertPayable($o);}
        $actual=$this->row('SELECT * FROM payment_records WHERE id=? AND order_id=? FOR UPDATE',array($this->id($payment['id']),$o['id']));
        if(!$actual||$actual['status']!=='pending'||(string)$actual['amount_cents']!==(string)$o['total_amount_cents'])throw new RuntimeException('续费付款记录无效。');
        $now=$this->now()->format('Y-m-d H:i:s');
        $this->run('UPDATE payment_records SET status="confirmed",confirmed_by=?,confirmed_at=?,gateway_trade_no=?,paid_at=?,notify_payload=?,updated_at=? WHERE id=?',array($actorType==='admin'?$actorId:null,$now,isset($paymentData['gateway_trade_no'])?$paymentData['gateway_trade_no']:'',$now,isset($paymentData['notify_payload'])?$paymentData['notify_payload']:null,$now,$actual['id']));
        $this->run('UPDATE payment_records SET status="superseded",updated_at=? WHERE order_id=? AND id<>? AND status="pending"',array($now,$o['id'],$actual['id']));
        $this->run('UPDATE orders SET status="pending_delivery",paid_payment_record_id=?,paid_at=?,updated_at=? WHERE id=?',array($actual['id'],$now,$now,$o['id']));
        $message='续费订单 '.$o['order_no'].' 已确认付款 '.WalletService::formatAmount($o['total_amount_cents']).' 元，续费处理中，等待管理员手工处理后确认。'.$warning;
        $this->event($o['id'],'renewal_paid','pending_payment','pending_delivery',$message,$actorType,$actorId,$now);
        $p=$this->row('SELECT status FROM orders WHERE id=? FOR UPDATE',array($o['parent_order_id']));
        if($p)$this->event($o['parent_order_id'],'renewal_paid',$p['status'],$p['status'],$message,$actorType,$actorId,$now);
        $this->notify($o,'renewal_paid','续费处理中',$message);return true;
    }
    public function assertParentMutable($order,$action='change')
    {
        if(self::isRenewal($order))return;
        if($this->active($this->id($order['id']),$this->pdo->inTransaction()))throw new RuntimeException('原订单存在进行中的续费，请先处理续费订单。');
        if(in_array($action,array('refund','revert'),true)&&$this->row('SELECT id FROM orders WHERE parent_order_id=? AND order_kind="renewal" AND status="delivered" LIMIT 1'.($this->pdo->inTransaction()?' FOR UPDATE':''),array($order['id'])))throw new RuntimeException('原订单已有成功续费，禁止整笔自动退款或退回待发货，请通过人工工单处理权益及退款。');
    }
    public function assertRefundable($order)
    {
        if(self::isRenewal($order)&&($order['status']==='delivered'||!empty($order['delivered_at'])||$this->row('SELECT completed_delivery_id FROM order_renewals WHERE order_id=? AND completed_delivery_id IS NOT NULL',array($this->id($order['id'])))))throw new RuntimeException('已成功续费不能自动退款，请通过人工工单核对权益。');
    }
    public function assertCancellable($order)
    {
        if(self::isRenewal($order)&&($order['status']!=='pending_payment'||!empty($order['paid_at'])||!empty($order['paid_payment_record_id'])))throw new RuntimeException('已付款的续费不能直接取消，请申请退款。');
    }
    public function detail($renewalNo,$uid=null)
    {
        $args=array($this->no($renewalNo));$sql='SELECT * FROM orders WHERE order_no=?';
        if($uid!==null){$sql.=' AND user_id=?';$args[]=$this->id($uid);}
        $o=$this->row($sql,$args);if(!$o||!self::isRenewal($o))return null;
        $p=$this->row('SELECT * FROM orders WHERE id=? AND user_id=?',array($this->id($o['parent_order_id']),$o['user_id']));
        if(!$p)throw new RuntimeException('续费原订单不存在。');
        $r=$this->row('SELECT * FROM order_renewals WHERE order_id=?',array($o['id']));
        if(!$r)throw new RuntimeException('续费元数据缺失。');
        $ds=$this->rows('SELECT id,version_no,opened_at,expires_at FROM delivery_records WHERE order_id=? AND is_current=1',array($p['id']));
        if(count($ds)>1)throw new RuntimeException('原订单当前交付记录不唯一。');
        return array('order'=>$o,'parent'=>$p,'renewal'=>$r,'delivery'=>$ds?$this->safeDelivery($ds[0]):null);
    }
    private function payload($d)
    {
        $value=json_decode((new CryptoService($this->config))->decrypt($d['content_ciphertext'],$d['content_nonce'],$d['content_tag']),true);
        if(!is_array($value)||!isset($value['subscription'])||!is_string($value['subscription'])||trim($value['subscription'])==='')throw new RuntimeException('交付订阅内容无效，请人工核对。');
        foreach(array('opened_at','expires_at') as $key)if(!isset($value[$key])||$value[$key]!==$d[$key])throw new RuntimeException('加密交付日期与元数据不一致，请人工核对。');
        return $value;
    }
    public function complete($renewalNo,$adminId,$expectedVersion)
    {
        $renewalNo=$this->no($renewalNo);$adminId=$this->id($adminId);$expectedVersion=$this->id($expectedVersion);
        if($this->pdo->inTransaction())throw new LogicException('续费完成不能嵌套事务。');
        $this->pdo->beginTransaction();
        try{
            $hint=$this->row('SELECT id,parent_order_id,order_kind FROM orders WHERE order_no=?',array($renewalNo));
            if(!$hint||!self::isRenewal($hint))throw new RuntimeException('续费订单不存在。');
            $p=$this->row('SELECT * FROM orders WHERE id=? FOR UPDATE',array($this->id($hint['parent_order_id'])));
            $o=$this->row('SELECT * FROM orders WHERE id=? FOR UPDATE',array($hint['id']));
            if(!$p||!$o||!self::isRenewal($o)||(int)$o['parent_order_id']!==(int)$p['id']||(int)$o['user_id']!==(int)$p['user_id']||(int)$o['product_id']!==(int)$p['product_id'])throw new RuntimeException('续费绑定无效。');
            $r=$this->row('SELECT * FROM order_renewals WHERE order_id=? FOR UPDATE',array($o['id']));
            if(!$r)throw new RuntimeException('续费元数据缺失。');
            if($o['status']==='delivered'&&!empty($r['completed_delivery_id'])){$this->pdo->commit();return $renewalNo;}
            if($o['status']!=='pending_delivery'||!empty($r['completed_delivery_id']))throw new RuntimeException('仅已付款且待处理的续费可确认完成。');
            $payment=$this->row('SELECT * FROM payment_records WHERE id=? AND order_id=? FOR UPDATE',array($o['paid_payment_record_id'],$o['id']));
            if(!$payment||$payment['status']!=='confirmed'||(string)$payment['amount_cents']!==(string)$o['total_amount_cents']||(string)$r['price_cents']!==(string)$o['total_amount_cents'])throw new RuntimeException('续费主付款记录未确认或金额不一致。');
            $d=$this->eligible($p,true,true);
            if((int)$d['version_no']!==$expectedVersion)throw new RuntimeException('交付版本已变化，请刷新后核对再确认。');
            $source=$this->row('SELECT * FROM delivery_records WHERE id=? AND order_id=?',array($r['source_delivery_id'],$p['id']));
            if(!$source||(int)$source['version_no']!==(int)$r['source_delivery_version'])throw new RuntimeException('原续费订阅版本不存在。');
            $payload=$this->payload($d);$sourcePayload=$this->payload($source);
            if(!hash_equals($sourcePayload['subscription'],$payload['subscription']))throw new RuntimeException('原订阅身份已改变，不能将续费应用到不同订阅，请人工核对。');
            $days=$this->id($r['duration_days']);
            if($days!==(int)$o['duration_days_snapshot']||$days>intdiv(PHP_INT_MAX,86400))throw new RuntimeException('续费时长无效。');
            $nowDate=$this->now();$now=$nowDate->format('Y-m-d H:i:s');$old=$this->date($d['expires_at']);$start=$old>$nowDate?$old:$nowDate;
            $newTimestamp=$start->getTimestamp()+$days*86400;
            if(!is_int($newTimestamp)||$newTimestamp<=$start->getTimestamp())throw new RuntimeException('续费到期时间溢出。');
            $newDate=$start->setTimestamp($newTimestamp);$new=$newDate->format('Y-m-d H:i:s');
            if((int)$newDate->format('Y')>9999)throw new RuntimeException('续费到期时间超出数据库范围。');
            $payload['expires_at']=$new;
            $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            list($cipher,$nonce,$tag)=(new CryptoService($this->config))->encrypt($json);
            $v=$this->row('SELECT version_no FROM delivery_records WHERE order_id=? ORDER BY version_no DESC LIMIT 1 FOR UPDATE',array($p['id']));$version=(int)$v['version_no']+1;
            if($version>4294967295)throw new RuntimeException('交付版本超出范围。');
            $this->run('UPDATE delivery_records SET is_current=0 WHERE order_id=? AND is_current=1',array($p['id']));
            $this->run('INSERT INTO delivery_records(order_id,version_no,action,content_ciphertext,content_nonce,content_tag,content_preview,is_current,opened_at,expires_at,created_by,created_at) VALUES(?,?,"renewed",?,?,?,?,1,?,?,?,?)',array($p['id'],$version,$cipher,$nonce,$tag,'已加密交付内容',$d['opened_at'],$new,$adminId,$now));$deliveryId=(int)$this->pdo->lastInsertId();
            $this->run('UPDATE order_renewals SET completed_delivery_id=?,previous_expires_at=?,renewal_starts_at=?,new_expires_at=?,completed_by=?,completed_at=? WHERE order_id=?',array($deliveryId,$d['expires_at'],$start->format('Y-m-d H:i:s'),$new,$adminId,$now,$o['id']));
            $this->run('UPDATE orders SET status="delivered",delivered_at=?,updated_at=? WHERE id=?',array($now,$now,$o['id']));
            $this->run('UPDATE orders SET updated_at=? WHERE id=?',array($now,$p['id']));
            $message='续费订单 '.$renewalNo.' 已人工确认完成，金额 '.WalletService::formatAmount($r['price_cents']).' 元，续费 '.$days.' 天；原到期 '.$d['expires_at'].'，续费起算 '.$start->format('Y-m-d H:i:s').'，新到期 '.$new.'。';
            $this->event($o['id'],'renewal_completed','pending_delivery','delivered',$message,'admin',$adminId,$now);
            $this->event($p['id'],'renewal_completed','delivered','delivered',$message,'admin',$adminId,$now);
            $this->notify($o,'renewal_completed','续费成功',$message);
            $this->run('INSERT INTO admin_logs(admin_id,actor_type,action,target_type,target_id,details_json,created_at) VALUES(?,"admin","complete_renewal","order",?,?,?)',array($adminId,$o['id'],json_encode(array('order_no'=>$renewalNo,'parent_order_id'=>(int)$p['id'],'delivery_id'=>$deliveryId,'version'=>$version,'duration_days'=>$days,'price_cents'=>(int)$r['price_cents'],'previous_expires_at'=>$d['expires_at'],'renewal_starts_at'=>$start->format('Y-m-d H:i:s'),'new_expires_at'=>$new)),$now));
            $this->pdo->commit();return $renewalNo;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    private function event($id,$type,$old,$new,$message,$actor,$actorId,$now)
    {
        $this->run('INSERT INTO order_events(order_id,event_type,old_status,new_status,description,actor_type,actor_id,created_at) VALUES(?,?,?,?,?,?,?,?)',array($id,$type,$old,$new,$message,$actor,$actorId,$now));
    }
    private function notify($o,$type,$title,$message)
    {
        (new NotificationService($this->pdo))->create($o['user_id'],$type,$title,$message,$o['order_no'],'order',$o['id'],$type.':'.$o['id']);
    }
}
