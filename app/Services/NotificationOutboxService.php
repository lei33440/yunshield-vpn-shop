<?php
class NotificationOutboxService
{
    private $pdo;
    public function __construct($pdo){$this->pdo=$pdo;}
    public function enqueue($eventType,$dedupeKey,$payload,$channel='wecom'){
        $channel=trim((string)$channel);if(!in_array($channel,array('wecom','email'),true))throw new InvalidArgumentException('通知渠道无效。');
        $now=date('Y-m-d H:i:s');$json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json===false)throw new RuntimeException('通知内容编码失败。');
        try{$this->pdo->prepare('INSERT INTO notification_outbox(channel,event_type,dedupe_key,payload_json,status,retry_count,available_at,created_at,updated_at) VALUES(?,?,?,?,"pending",0,?,?,?)')->execute(array($channel,$eventType,$dedupeKey,$json,$now,$now,$now));}catch(PDOException $e){if(strpos(strtolower($e->getMessage()),'duplicate')===false)throw $e;}
    }
    public function claim($limit=20,$passwordResetOnly=false){
        $limit=max(1,min(100,(int)$limit));
        $scope=$passwordResetOnly?' AND channel="email" AND event_type="password_reset"':'';
        $this->pdo->exec('UPDATE notification_outbox SET status="retry",locked_at=NULL,updated_at=NOW() WHERE status="processing" AND locked_at IS NOT NULL AND locked_at<DATE_SUB(NOW(),INTERVAL 10 MINUTE)'.$scope);
        $s=$this->pdo->prepare('SELECT * FROM notification_outbox WHERE status IN ("pending","retry") AND available_at<=NOW()'.$scope.' ORDER BY id LIMIT '.$limit);$s->execute();$rows=$s->fetchAll();$claimed=array();
        foreach($rows as $row){
            $u=$this->pdo->prepare('UPDATE notification_outbox SET status="processing",locked_at=?,updated_at=? WHERE id=? AND status IN ("pending","retry") AND available_at<=NOW()'.$scope);
            $now=date('Y-m-d H:i:s');$u->execute(array($now,$now,$row['id']));
            if($u->rowCount()===1){$row['status']='processing';$claimed[]=$row;}
        }
        return $claimed;
    }
    /** 回收、查询和抢占均限定新邮件事件，不触碰历史积压及找回密码。 */
    public function claimCustomerEmails($limit=20){
        $limit=max(1,min(20,(int)$limit));
        $scope=' AND channel="email" AND event_type IN ("customer_order_created","customer_order_delivered","broadcast_notice")';
        $this->pdo->exec('UPDATE notification_outbox SET status="retry",locked_at=NULL,updated_at=NOW() WHERE status="processing" AND locked_at IS NOT NULL AND locked_at<DATE_SUB(NOW(),INTERVAL 10 MINUTE)'.$scope);
        $s=$this->pdo->prepare('SELECT * FROM notification_outbox WHERE status IN ("pending","retry") AND available_at<=NOW()'.$scope.' ORDER BY CASE WHEN event_type="broadcast_notice" THEN 1 ELSE 0 END,id LIMIT '.$limit);$s->execute();$claimed=array();
        foreach($s->fetchAll() as $row){
            $u=$this->pdo->prepare('UPDATE notification_outbox SET status="processing",locked_at=NOW(),updated_at=NOW() WHERE id=? AND status IN ("pending","retry") AND available_at<=NOW()'.$scope);$u->execute(array($row['id']));
            if($u->rowCount()===1){$row['status']='processing';$claimed[]=$row;}
        }
        return $claimed;
    }
    public function sent($id){$now=date('Y-m-d H:i:s');$this->pdo->prepare('UPDATE notification_outbox SET status="sent",sent_at=?,locked_at=NULL,updated_at=? WHERE id=?')->execute(array($now,$now,$id));}
    public function failed($id,$error,$retryable){$s=$this->pdo->prepare('SELECT retry_count FROM notification_outbox WHERE id=?');$s->execute(array($id));$count=(int)$s->fetchColumn();if($retryable&&$count<5){$count++;$delay=array(60,300,900,3600,7200)[$count-1];$this->pdo->prepare('UPDATE notification_outbox SET status="retry",retry_count=?,available_at=DATE_ADD(NOW(),INTERVAL ? SECOND),locked_at=NULL,last_error=?,updated_at=NOW() WHERE id=?')->execute(array($count,$delay,substr($error,0,500),$id));}else $this->pdo->prepare('UPDATE notification_outbox SET status="failed",retry_count=?,locked_at=NULL,last_error=?,updated_at=NOW() WHERE id=?')->execute(array($count,substr($error,0,500),$id));}
}
