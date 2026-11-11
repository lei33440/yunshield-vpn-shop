<?php
/** 仅使用交付元数据；墙钟 DATETIME 按应用时区解释，不读取或解密订阅凭据。 */
class SubscriptionReminderService
{
    private $pdo;
    private $config;
    private $timezone;
    public function __construct($pdo,$config=array()){
        $this->pdo=$pdo;$this->config=$config;
        $this->timezone=new DateTimeZone(isset($config['app']['timezone'])?$config['app']['timezone']:date_default_timezone_get());
    }
    private function now(){return new DateTimeImmutable('now',$this->timezone);}
    private function parse($value){
        if(!is_string($value)||!preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D',$value))return null;
        $d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,$this->timezone);
        return $d&&$d->format('Y-m-d H:i:s')===$value?$d:null;
    }
    /** 剩余 (3,7] / (1,3] / (0,1] 天；只选择当前最紧迫档位。 */
    public function tier($openedAt,$expiresAt,$now=null){
        $now=$now===null?$this->now():$now;$opened=$this->parse($openedAt);$expires=$this->parse($expiresAt);
        if(!$opened||!$expires||$opened>$now||$expires<=$opened)return null;
        $remaining=$expires->getTimestamp()-$now->getTimestamp();
        if($remaining<=0||$remaining>7*86400)return null;
        return $remaining<=86400?1:($remaining<=3*86400?3:7);
    }
    public function dedupeKey($orderId,$expiresAt,$tier){return 'subscription_expiring:'.(int)$orderId.':'.hash('sha256',$expiresAt).':'.(int)$tier;}
    private function delivery($orderId){
        $s=$this->pdo->prepare('SELECT id,version_no,opened_at,expires_at FROM delivery_records WHERE order_id=? AND is_current=1');$s->execute(array($orderId));$rows=$s->fetchAll(PDO::FETCH_ASSOC);
        return count($rows)===1?$rows[0]:null;
    }
    /** Keyset 遍历所有候选，已提醒的前 N 个订单不会遮挡后续订单。每个订单独立事务。 */
    public function scan(){
        if($this->pdo->inTransaction())throw new LogicException('subscription_reminder_transaction_active');
        $result=array('notified'=>0,'enqueued'=>0);$after=0;
        $now=$this->now();$from=$now->format('Y-m-d H:i:s');$until=$now->setTimestamp($now->getTimestamp()+7*86400)->format('Y-m-d H:i:s');
        do{
            $s=$this->pdo->prepare('SELECT o.id FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id>? AND o.status="delivered" AND o.product_type_snapshot="vpn" AND u.status="active" AND EXISTS (SELECT 1 FROM delivery_records d WHERE d.order_id=o.id AND d.is_current=1 AND d.expires_at>? AND d.expires_at<=?) ORDER BY o.id LIMIT 200');
            $s->execute(array($after,$from,$until));$ids=$s->fetchAll(PDO::FETCH_COLUMN);
            foreach($ids as $id){
                $after=(int)$id;$this->pdo->beginTransaction();
                try{
                    // 与交付/退款业务使用相同的订单锁，锁后重新读取当前交付。
                    $s=$this->pdo->prepare('SELECT id,user_id,order_no,status,product_type_snapshot,product_name_snapshot,plan_name_snapshot FROM orders WHERE id=? FOR UPDATE');$s->execute(array($id));$order=$s->fetch(PDO::FETCH_ASSOC);
                    if(!$order){$this->pdo->commit();continue;}
                    $s=$this->pdo->prepare('SELECT status FROM users WHERE id=?');$s->execute(array($order['user_id']));$active=$s->fetchColumn()==='active';
                    $d=$this->delivery($id);$tier=$d?$this->tier($d['opened_at'],$d['expires_at']):null;
                    if($order['status']==='delivered'&&$order['product_type_snapshot']==='vpn'&&$active&&$tier!==null){
                        $key=$this->dedupeKey($id,$d['expires_at'],$tier);
                        $s=$this->pdo->prepare('SELECT id FROM notifications WHERE user_id=? AND dedupe_key=?');$s->execute(array($order['user_id'],$key));
                        if($s->fetchColumn()===false){
                            (new NotificationService($this->pdo))->create($order['user_id'],'subscription_expiring','订阅即将到期','订单 '.$order['order_no'].' 的订阅将于 '.$d['expires_at'].' 到期（'.$tier.' 天内）。如需继续使用，可进入原订单申请续费；付款后由管理员人工处理，确认成功后更新有效期。系统不会自动扣款。',$order['order_no'],'order',$id,$key);
                            $result['notified']++;
                            if((new CustomerEmailService($this->pdo,$this->config))->enqueueSubscriptionExpiring($order,$d,$tier,$key))$result['enqueued']++;
                        }
                    }
                    $this->pdo->commit();
                }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
            }
        }while(count($ids)===200);
        return $result;
    }
    public function eligibility($payload,$order){
        if($order['status']!=='delivered'||$order['product_type_snapshot']!=='vpn')return 'customer_subscription_inactive';
        foreach(array('delivery_id','delivery_version','reminder_tier','expires_at') as $key)if(!isset($payload[$key])||!is_scalar($payload[$key]))return 'customer_subscription_binding_invalid';
        $d=$this->delivery($order['id']);
        if(!$d||(string)$d['id']!==(string)$payload['delivery_id']||(string)$d['version_no']!==(string)$payload['delivery_version']||$d['expires_at']!==$payload['expires_at'])return 'customer_subscription_version_expired';
        $tier=$this->tier($d['opened_at'],$d['expires_at']);
        return $tier!==null&&(string)$tier===(string)$payload['reminder_tier']?null:'customer_subscription_tier_expired';
    }
}
