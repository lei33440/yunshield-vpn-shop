<?php
class FeedbackService
{
    private $pdo;
    public function __construct($pdo){$this->pdo=$pdo;}
    private function id($value){$s=(string)$value;if((!is_int($value)&&!is_string($value))||!preg_match('/^[1-9][0-9]*$/D',$s)||strlen($s)>strlen((string)PHP_INT_MAX)||(strlen($s)===strlen((string)PHP_INT_MAX)&&strcmp($s,(string)PHP_INT_MAX)>0))throw new InvalidArgumentException('记录编号无效。');return (int)$value;}
    private function key($key){if(!is_string($key)||!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$key))throw new InvalidArgumentException('提交令牌无效，请刷新页面。');return $key;}
    private function start(){if($this->pdo->inTransaction())throw new LogicException('反馈操作不能嵌套事务。');$this->pdo->beginTransaction();}
    public function create($userId,$type,$subject,$body,$submissionKey)
    {
        $userId=$this->id($userId);$submissionKey=$this->key($submissionKey);
        if(!is_string($type)||!is_string($subject)||!is_string($body))throw new InvalidArgumentException('反馈内容格式无效。');
        $type=trim($type);$subject=trim($subject);$body=trim($body);
        if(!in_array($type,array('suggestion','bug'),true)||$subject===''||mb_strlen($subject,'UTF-8')>200||$body===''||mb_strlen($body,'UTF-8')>5000)throw new InvalidArgumentException('请填写有效类型、200字内主题及5000字内内容。');
        $this->start();try{
            // 提交按用户串行，防止并发越过提交频率与待审核数量限制。
            $s=$this->pdo->prepare('SELECT id FROM users WHERE id=? AND status="active" FOR UPDATE');$s->execute(array($userId));if(!$s->fetch())throw new RuntimeException('用户不存在或已停用。');
            $s=$this->pdo->prepare('SELECT * FROM feedback_submissions WHERE user_id=? AND submission_key=?');$s->execute(array($userId,$submissionKey));$old=$s->fetch(PDO::FETCH_ASSOC);
            if($old){if($old['type']!==$type||$old['subject']!==$subject||$old['body']!==$body)throw new RuntimeException('提交令牌已用于其他内容，请刷新后重试。');$this->pdo->commit();return (int)$old['id'];}
            $s=$this->pdo->prepare('SELECT SUM(created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)) recent,SUM(status="pending") pending FROM feedback_submissions WHERE user_id=?');$s->execute(array($userId));$counts=$s->fetch(PDO::FETCH_ASSOC);
            if((int)$counts['recent']>=5||(int)$counts['pending']>=10)throw new RuntimeException('提交过于频繁或待审核反馈较多，请稍后再试。');
            $now=date('Y-m-d H:i:s');$no='FDB'.date('YmdHis').strtoupper(bin2hex(random_bytes(4)));
            $this->pdo->prepare('INSERT INTO feedback_submissions(feedback_no,user_id,type,subject,body,status,submission_key,created_at,updated_at,progress_status,progress_updated_at,progress_version) VALUES(?,?,?,?,?,"pending",?,?,?,"received",?,1)')->execute(array($no,$userId,$type,$subject,$body,$submissionKey,$now,$now,$now));$id=(int)$this->pdo->lastInsertId();
            $this->pdo->prepare('INSERT INTO feedback_progress_events(feedback_id,from_status,to_status,public_note,actor_type,actor_id,request_key,request_hash,created_at) VALUES(?,NULL,?,?,?,?,?,?,?)')->execute(array($id,'received','反馈已收到，等待登记后续处理安排。','user',$userId,$submissionKey,hash('sha256',json_encode(array($type,$subject,$body),JSON_UNESCAPED_UNICODE)),$now));
            $this->pdo->commit();return $id;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function review($id,$adminId,$status,$rewardAmount,$note)
    {
        $id=$this->id($id);$adminId=$this->id($adminId);
        if(!is_string($note)||!in_array($status,array('rewarded','rejected'),true))throw new InvalidArgumentException('反馈处理参数无效。');$note=trim($note);
        if($note===''||mb_strlen($note,'UTF-8')>500)throw new InvalidArgumentException('审核说明必填且不超过500字。');
        $rewardCents=0;if($status==='rewarded'){$rewardCents=WalletService::parseAmount($rewardAmount);if($rewardCents<=0)throw new InvalidArgumentException('奖励金额必须大于零。');}
        $this->start();try{
            $s=$this->pdo->prepare('SELECT id FROM admin_users WHERE id=? AND status="active"');$s->execute(array($adminId));if(!$s->fetch())throw new RuntimeException('管理员身份无效。');
            $s=$this->pdo->prepare('SELECT * FROM feedback_submissions WHERE id=? FOR UPDATE');$s->execute(array($id));$f=$s->fetch(PDO::FETCH_ASSOC);if(!$f)throw new RuntimeException('反馈不存在。');
            if($f['status']!=='pending'){
                if($f['status']===$status&&(int)$f['reward_cents']===$rewardCents&&(string)$f['admin_note']===$note){$this->pdo->commit();return $f;}
                throw new RuntimeException('该反馈已处理或参数冲突。');
            }
            $txId=null;if($status==='rewarded'){$tx=(new WalletService($this->pdo))->rewardFeedback((int)$f['user_id'],$id,$rewardCents,$adminId,$note);$txId=(int)$tx['id'];}
            $now=date('Y-m-d H:i:s');$this->pdo->prepare('UPDATE feedback_submissions SET status=?,reward_cents=?,reward_transaction_id=?,reviewed_by=?,reviewed_at=?,admin_note=?,updated_at=? WHERE id=?')->execute(array($status,$rewardCents,$txId,$adminId,$now,$note,$now,$id));
            $this->pdo->prepare('INSERT INTO admin_logs(admin_id,action,target_type,target_id,details_json,created_at) VALUES(?,?,?,?,?,?)')->execute(array($adminId,'review_feedback','feedback',$id,json_encode(array('status'=>$status,'reward_cents'=>$rewardCents,'note'=>$note,'wallet_transaction_id'=>$txId),JSON_UNESCAPED_UNICODE),$now));
            (new NotificationService($this->pdo))->create((int)$f['user_id'],'feedback_review','反馈审核结果',$status==='rewarded'?'反馈已通过，奖励 ¥'.WalletService::formatAmount($rewardCents).' 已入消费余额。审核说明：'.$note:'反馈未采纳：'.$note,null,'feedback',$id,'feedback_review:'.$id);
            $this->pdo->commit();return $this->detail($id);
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public static function progressLabels(){return array('received'=>'已收到','planned'=>'已纳入计划','in_progress'=>'处理中','completed'=>'已完成','not_planned'=>'暂不安排');}
    public function updateProgress($id,$adminId,$status,$note,$version,$requestKey)
    {
        $id=$this->id($id);$adminId=$this->id($adminId);$requestKey=$this->key($requestKey);
        if(!is_string($status)||!isset(self::progressLabels()[$status])||!is_string($note))throw new InvalidArgumentException('处理进度参数无效。');
        $note=trim($note);if($note===''||mb_strlen($note,'UTF-8')>1000)throw new InvalidArgumentException('公开进度说明必填且不超过1000字。');
        if((!is_int($version)&&!is_string($version))||!preg_match('/^(0|[1-9][0-9]{0,9})$/D',(string)$version)||(float)$version>4294967294)throw new InvalidArgumentException('进度版本无效，请刷新页面。');
        $hash=hash('sha256',json_encode(array($status,$note,(int)$version),JSON_UNESCAPED_UNICODE));
        $this->start();try{
            $s=$this->pdo->prepare('SELECT id FROM admin_users WHERE id=? AND status="active"');$s->execute(array($adminId));if(!$s->fetch())throw new RuntimeException('管理员身份无效。');
            $s=$this->pdo->prepare('SELECT * FROM feedback_submissions WHERE id=? FOR UPDATE');$s->execute(array($id));$f=$s->fetch(PDO::FETCH_ASSOC);if(!$f)throw new RuntimeException('反馈不存在。');
            $s=$this->pdo->prepare('SELECT * FROM feedback_progress_events WHERE feedback_id=? AND actor_type="admin" AND actor_id=? AND request_key=?');$s->execute(array($id,$adminId,$requestKey));$old=$s->fetch(PDO::FETCH_ASSOC);
            if($old){if(!hash_equals($old['request_hash'],$hash))throw new RuntimeException('请求令牌已用于其他进度内容。');$this->pdo->commit();return $old;}
            if((int)$f['progress_version']!==(int)$version)throw new RuntimeException('处理进度已更新，请刷新页面后重试。');
            $now=date('Y-m-d H:i:s');$this->pdo->prepare('INSERT INTO feedback_progress_events(feedback_id,from_status,to_status,public_note,actor_type,actor_id,request_key,request_hash,created_at) VALUES(?,?,?,?,"admin",?,?,?,?)')->execute(array($id,$f['progress_status'],$status,$note,$adminId,$requestKey,$hash,$now));$eventId=(int)$this->pdo->lastInsertId();
            $this->pdo->prepare('UPDATE feedback_submissions SET progress_status=?,progress_updated_at=?,progress_version=progress_version+1,updated_at=? WHERE id=?')->execute(array($status,$now,$now,$id));
            $this->pdo->prepare('INSERT INTO admin_logs(admin_id,action,target_type,target_id,details_json,created_at) VALUES(?,?,?,?,?,?)')->execute(array($adminId,'update_feedback_progress','feedback',$id,json_encode(array('event_id'=>$eventId,'from'=>$f['progress_status'],'to'=>$status,'note'=>$note,'version'=>(int)$version+1),JSON_UNESCAPED_UNICODE),$now));
            $this->notifyProgress((int)$f['user_id'],$id,$eventId,$status,$note);
            $s=$this->pdo->prepare('SELECT * FROM feedback_progress_events WHERE id=?');$s->execute(array($eventId));$event=$s->fetch(PDO::FETCH_ASSOC);$this->pdo->commit();return $event;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    private function notifyProgress($userId,$id,$eventId,$status,$note)
    {
        // NotificationService uses this PDO and must not commit the business transaction.
        (new NotificationService($this->pdo))->create($userId,'feedback_progress','反馈处理进度更新',self::progressLabels()[$status].'：'.$note,null,'feedback',$id,'feedback_progress:'.$id.':'.$eventId);
    }
    private function events($id){$s=$this->pdo->prepare('SELECT id,from_status,to_status,public_note,actor_type,actor_id,created_at FROM feedback_progress_events WHERE feedback_id=? ORDER BY id ASC');$s->execute(array($id));return $s->fetchAll(PDO::FETCH_ASSOC);}
    private function page($where,$args,$page,$perPage,$includeUser=false){$page=max(1,(int)$page);$perPage=max(1,min(50,(int)$perPage));$s=$this->pdo->prepare('SELECT COUNT(*) FROM feedback_submissions WHERE '.$where);$s->execute($args);$total=(int)$s->fetchColumn();$pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);$fields=$includeUser?'feedback_submissions.*, users.email AS user_email':'feedback_submissions.*';$join=$includeUser?' LEFT JOIN users ON users.id=feedback_submissions.user_id':'';$s=$this->pdo->prepare('SELECT '.$fields.' FROM feedback_submissions'.$join.' WHERE '.$where.' ORDER BY feedback_submissions.id DESC LIMIT '.$perPage.' OFFSET '.(($page-1)*$perPage));$s->execute($args);return array('items'=>$s->fetchAll(PDO::FETCH_ASSOC),'total'=>$total,'page'=>$page,'pages'=>$pages,'per_page'=>$perPage);}
    public function mine($userId,$page=1,$perPage=20){return $this->page('user_id=?',array($this->id($userId)),$page,$perPage);}
    public function listing($filters=array(),$page=1,$perPage=20){$where=array('1=1');$args=array();foreach(array('status'=>array('pending','rejected','rewarded'),'type'=>array('bug','suggestion')) as $key=>$allowed){if(isset($filters[$key])&&in_array($filters[$key],$allowed,true)){$where[]='feedback_submissions.'.$key.'=?';$args[]=$filters[$key];}}if(isset($filters['progress_status'])){if($filters['progress_status']==='unregistered')$where[]='feedback_submissions.progress_status IS NULL';elseif(is_string($filters['progress_status'])&&isset(self::progressLabels()[$filters['progress_status']])){$where[]='feedback_submissions.progress_status=?';$args[]=$filters['progress_status'];}}return $this->page(implode(' AND ',$where),$args,$page,$perPage,true);}
    public function detail($id){$s=$this->pdo->prepare('SELECT feedback_submissions.*, users.email AS user_email FROM feedback_submissions LEFT JOIN users ON users.id=feedback_submissions.user_id WHERE feedback_submissions.id=?');$s->execute(array($this->id($id)));$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)throw new RuntimeException('反馈不存在。');$r['progress_events']=$this->events((int)$r['id']);return $r;}
    public function findMine($userId,$id){$s=$this->pdo->prepare('SELECT * FROM feedback_submissions WHERE id=? AND user_id=?');$s->execute(array($this->id($id),$this->id($userId)));$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)throw new RuntimeException('反馈不存在。');$r['progress_events']=$this->events((int)$r['id']);return $r;}
}
