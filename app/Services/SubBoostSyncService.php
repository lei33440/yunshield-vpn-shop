<?php
class SubBoostSyncService
{
    private $pdo;
    private $config;
    public function __construct($pdo,$config=array()){$this->pdo=$pdo;$this->config=$config;}
    public static function enqueuePaidOrder($pdo,$order)
    {
        $config=isset($GLOBALS['config'])?$GLOBALS['config']:array();
        if(!SubBoostClient::enabled($config))return;
        if(!$pdo->inTransaction())throw new LogicException('Payment outbox requires transaction');
        if($order['product_type_snapshot']!=='vpn')return;
        $s=$pdo->prepare('SELECT id FROM group_orders WHERE order_id=?');$s->execute(array($order['id']));if($s->fetch())return;
        // 快照单位为天；无效历史时长保留重试，绝不默认为某个时长。
        $days=$order['duration_days_snapshot'];
        $valid=(is_int($days)||is_string($days))&&preg_match('/\A[1-9][0-9]*\z/D',(string)$days)&&((float)$days<=2147483647);
        $name=$order['product_name_snapshot'].' / '.$order['plan_name_snapshot'];
        if(function_exists('mb_substr'))$name=mb_substr($name,0,100,'UTF-8');
        else{$characters=preg_split('//u',$name,-1,PREG_SPLIT_NO_EMPTY);$name=$characters===false?'':implode('',array_slice($characters,0,100));}
        $request=array('orderNo'=>$order['order_no'],'name'=>$name,'durationDays'=>$valid?(int)$days:null);
        $settings=SubBoostClient::settings($config);$now=gmdate('Y-m-d H:i:s');
        $pdo->prepare('INSERT INTO subboost_sync_tasks(order_id,source_hash,request_json,status,available_at,last_error,created_at,updated_at) VALUES(?,?,?,"pending",?,?,?,?) ON DUPLICATE KEY UPDATE order_id=VALUES(order_id)')->execute(array($order['id'],hash('sha256',$settings['url']),json_encode($request,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$now,$valid?'':'invalid_duration',$now,$now));
    }
    public function runOnce($limit=100)
    {
        $result=array('processed'=>0,'completed'=>0,'errors'=>0,'locked'=>false,'disabled'=>false);
        if(!SubBoostClient::enabled($this->config)){$result['disabled']=true;return $result;}
        if($this->pdo->inTransaction())throw new LogicException('Worker cannot run inside transaction');
        // MySQL 连接级锁覆盖 HTTP 及最终交付，防止多个主机上的 worker 并行处理。
        $key='subboost:'.substr(hash('sha256',$this->pdo->query('SELECT DATABASE()')->fetchColumn()),0,48);
        $s=$this->pdo->prepare('SELECT GET_LOCK(?,0)');$s->execute(array($key));
        if((int)$s->fetchColumn()!==1){$result['locked']=true;return $result;}
        try{
            $client=new SubBoostClient($this->config);
            $limit=max(1,min(1000,(int)$limit));
            $tasks=$this->pdo->query('SELECT * FROM subboost_sync_tasks WHERE status IN ("pending","waiting","retry") AND available_at<=UTC_TIMESTAMP() ORDER BY id LIMIT '.$limit)->fetchAll();
            foreach($tasks as $task){
                $result['processed']++;
                try{if($this->process($task,$client))$result['completed']++;}
                catch(Throwable $e){
                    if($this->pdo->inTransaction())$this->pdo->rollBack();
                    $code=$e->getMessage();
                    if(!in_array($code,array('invalid_configuration','invalid_duration','invalid_request','invalid_response','invalid_dates','invalid_subscription_url','identity_mismatch','source_mismatch','dates_changed','http_transport_failed'),true)&&!preg_match('/\Ahttp_[0-9]{1,3}\z/D',$code))$code='sync_failed';
                    $delay=min(3600,30*(int)pow(2,min(7,(int)$task['retry_count'])));
                    $this->pdo->prepare('UPDATE subboost_sync_tasks SET status="retry",retry_count=retry_count+1,available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),last_error=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND status<>"completed"')->execute(array($delay,$code,$task['id']));$result['errors']++;
                }
            }
        }finally{$s=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');$s->execute(array($key));}
        return $result;
    }
    private function order($id,$lock=false)
    {
        $s=$this->pdo->prepare('SELECT * FROM orders WHERE id=?'.($lock?' FOR UPDATE':''));$s->execute(array($id));return $s->fetch();
    }
    private function eligible($order)
    {
        if(!$order||$order['status']!=='pending_delivery'||$order['product_type_snapshot']!=='vpn'||empty($order['paid_payment_record_id'])||!empty($order['delivered_at']))return false;
        $s=$this->pdo->prepare('SELECT id FROM delivery_records WHERE order_id=? LIMIT 1');$s->execute(array($order['id']));if($s->fetch())return false;
        $s=$this->pdo->prepare('SELECT id FROM group_orders WHERE order_id=? LIMIT 1');$s->execute(array($order['id']));return !$s->fetch();
    }
    private function process($task,$client)
    {
        $order=$this->order($task['order_id']);
        if(!$this->eligible($order)){
            // 退款申请后可能被驳回，因此暂停任务而不丢弃记录。
            $this->pdo->prepare('UPDATE subboost_sync_tasks SET available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 300 SECOND),last_error="order_ineligible",updated_at=UTC_TIMESTAMP() WHERE id=?')->execute(array($task['id']));return false;
        }
        if($task['source_hash']!==$client->sourceHash())throw new RuntimeException('source_mismatch');
        $request=json_decode($task['request_json'],true);
        if(!is_array($request)||!isset($request['durationDays'])||!is_int($request['durationDays'])||$request['durationDays']<1)throw new RuntimeException('invalid_duration');
        if($request['orderNo']!==$order['order_no']||(int)$order['duration_days_snapshot']!==$request['durationDays'])throw new RuntimeException('invalid_request');
        $data=$client->validate($task['subscription_id']===null?$client->create($request):$client->status($order['order_no']),$order['order_no']);
        if($task['subscription_id']!==null&&$data['subscriptionId']!==$task['subscription_id'])throw new RuntimeException('identity_mismatch');
        // 发货前持久化订阅身份；POST 后崩溃由订单号幂等恢复。
        $this->pdo->prepare('UPDATE subboost_sync_tasks SET subscription_id=?,status="waiting",last_error="",updated_at=UTC_TIMESTAMP() WHERE id=? AND (subscription_id IS NULL OR subscription_id=?)')->execute(array($data['subscriptionId'],$task['id'],$data['subscriptionId']));
        if($data['status']!=='ready'){
            $this->pdo->prepare('UPDATE subboost_sync_tasks SET available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 60 SECOND) WHERE id=?')->execute(array($task['id']));return false;
        }
        $utc=new DateTimeZone('UTC');$tz=new DateTimeZone(isset($this->config['app']['timezone'])?$this->config['app']['timezone']:date_default_timezone_get());
        $opened=$client->parseInstant($data['openedAt']);$expires=$client->parseInstant($data['expiresAt']);
        if($expires->getTimestamp()-$opened->getTimestamp()!==$request['durationDays']*86400)throw new RuntimeException('invalid_dates');
        $openedUtc=$opened->setTimezone($utc)->format('Y-m-d H:i:s');$expiresUtc=$expires->setTimezone($utc)->format('Y-m-d H:i:s');
        // 即使后续发货或加密失败，也固定首次确认的开通及到期时间。
        $this->pdo->prepare('UPDATE subboost_sync_tasks SET opened_at=?,expires_at=? WHERE id=? AND opened_at IS NULL')->execute(array($openedUtc,$expiresUtc,$task['id']));
        $this->pdo->beginTransaction();
        try{
            $order=$this->order($task['order_id'],true);
            $s=$this->pdo->prepare('SELECT * FROM subboost_sync_tasks WHERE id=? FOR UPDATE');$s->execute(array($task['id']));$locked=$s->fetch();
            if($locked['subscription_id']!==$data['subscriptionId']||$locked['source_hash']!==$client->sourceHash())throw new RuntimeException('identity_mismatch');
            if($locked['opened_at']!==$openedUtc||$locked['expires_at']!==$expiresUtc)throw new RuntimeException('dates_changed');
            if($locked['status']==='completed'||!$this->eligible($order)){$this->pdo->commit();return false;}
            $payload=array('subscription'=>$data['subscriptionUrl'],'account'=>'','instructions'=>'','duration_preset'=>'',
                'opened_at'=>$opened->setTimezone($tz)->format('Y-m-d H:i:s'),'expires_at'=>$expires->setTimezone($tz)->format('Y-m-d H:i:s'));
            (new OrderService($this->pdo))->deliverSystem($order['order_no'],$payload,$this->config);
            $this->pdo->prepare('UPDATE subboost_sync_tasks SET status="completed",completed_at=UTC_TIMESTAMP(),last_error="",updated_at=UTC_TIMESTAMP() WHERE id=?')->execute(array($task['id']));
            $this->pdo->commit();return true;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
}
