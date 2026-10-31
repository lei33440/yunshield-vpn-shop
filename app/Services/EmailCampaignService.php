<?php
/** 通知群发：内容冻结、收件人快照、确认和分批游标均有事务保护。 */
class EmailCampaignService
{
    private $pdo;
    private $config;
    public function __construct($pdo,$config=array()){$this->pdo=$pdo;$this->config=$config;}
    private function transaction($fn){
        if($this->pdo->inTransaction())throw new LogicException('群发操作必须使用独立事务。');
        $this->pdo->beginTransaction();try{$r=$fn();$this->pdo->commit();return $r;}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    private function lock($id){$s=$this->pdo->prepare('SELECT * FROM email_campaigns WHERE id=? FOR UPDATE');$s->execute(array($id));$c=$s->fetch();if(!$c)throw new RuntimeException('群发任务不存在。');return $c;}
    private function version($c,$version){if(!is_scalar($version)||!preg_match('/\A[1-9][0-9]*\z/D',(string)$version)||(int)$c['version']!==(int)$version)throw new RuntimeException('页面版本已过期，请刷新后重试。');}
    private function audit($adminId,$action,$id,$meta=array()){
        // 严禁将正文、邮箱快照、队列载荷或退订令牌写入审计。
        $this->pdo->prepare('INSERT INTO admin_logs(admin_id,action,target_type,target_id,details_json,created_at) VALUES(?,?,"email_campaign",?,?,NOW())')->execute(array($adminId,$action,$id,json_encode($meta,JSON_UNESCAPED_UNICODE)));
    }
    public function saveDraft($input,$adminId,$id=null,$version=null){
        if(!is_array($input))throw new InvalidArgumentException('群发内容无效。');
        foreach(array('subject','body','scope') as $k)if(!isset($input[$k])||!is_string($input[$k]))throw new InvalidArgumentException('请填写主题、正文和收件范围。');
        $subject=trim($input['subject']);$body=trim(str_replace(array("\r\n","\r"),"\n",$input['body']));$scope=$input['scope'];
        if($subject===''||strlen($subject)>600||preg_match('/[\r\n\x00-\x1F\x7F]/',$subject)||$body===''||strlen($body)>60000||strpos($body,"\0")!==false||!preg_match('//u',$subject.$body)||!in_array($scope,array('all','selected'),true))throw new InvalidArgumentException('主题或正文超长、为空或含无效字符。');
        $ids=array();if($scope==='selected'){
            if(!isset($input['user_ids'])||!is_array($input['user_ids'])||count($input['user_ids'])>1000)throw new InvalidArgumentException('请选择最多1000名正常用户。');
            foreach($input['user_ids'] as $uid){if(!is_scalar($uid)||!preg_match('/\A[1-9][0-9]{0,17}\z/D',(string)$uid))throw new InvalidArgumentException('用户编号无效。');$ids[]=(int)$uid;}
            $ids=array_values(array_unique($ids));sort($ids,SORT_NUMERIC);if(!$ids)throw new InvalidArgumentException('请选择收件用户。');
        }
        return $this->transaction(function()use($subject,$body,$scope,$ids,$id,$version,$adminId){
            if($id!==null){$c=$this->lock($id);$this->version($c,$version);if($c['status']!=='draft')throw new RuntimeException('快照已冻结，请先退回草稿。');
                $this->pdo->prepare('UPDATE email_campaigns SET subject=?,body=?,scope=?,selected_user_ids=?,version=version+1,updated_at=NOW() WHERE id=?')->execute(array($subject,$body,$scope,json_encode($ids),$id));
            }else{$this->pdo->prepare('INSERT INTO email_campaigns(created_by,subject,body,scope,selected_user_ids,created_at,updated_at) VALUES(?,?,?,?,?,NOW(),NOW())')->execute(array($adminId,$subject,$body,$scope,json_encode($ids)));$id=(int)$this->pdo->lastInsertId();}
            $this->audit($adminId,'save_email_campaign',$id);return (int)$id;
        });
    }
    public function snapshot($id,$version,$adminId){
        $this->transaction(function()use($id,$version,$adminId){
            $c=$this->lock($id);$this->version($c,$version);if($c['status']!=='draft')throw new RuntimeException('只能为草稿生成快照。');
            $args=array();$where='u.status="active" AND COALESCE(p.broadcast_unsubscribed,0)=0';
            if($c['scope']==='selected'){$ids=json_decode($c['selected_user_ids'],true);if(!$ids)throw new RuntimeException('未选择用户。');$where.=' AND u.id IN ('.implode(',',array_fill(0,count($ids),'?')).')';$args=$ids;}
            $s=$this->pdo->prepare('SELECT u.id,u.email FROM users u LEFT JOIN email_preferences p ON p.user_id=u.id WHERE '.$where.' ORDER BY u.id');$s->execute($args);
            $insert=$this->pdo->prepare('INSERT INTO email_campaign_recipients(campaign_id,user_id,email_snapshot,created_at) VALUES(?,?,?,NOW())');$emails=array();$digest=hash_init('sha256');$count=0;
            foreach($s->fetchAll() as $u){
                if(!filter_var($u['email'],FILTER_VALIDATE_EMAIL))continue;$email=strtolower($u['email']);if(isset($emails[$email]))continue;$emails[$email]=true;
                $insert->execute(array($id,$u['id'],$u['email']));$count++;hash_update($digest,$u['id'].':'.$email."\n");
            }
            $this->pdo->prepare('UPDATE email_campaigns SET status="ready",recipient_count=?,snapshot_digest=?,snapshot_at=NOW(),updated_at=NOW() WHERE id=?')->execute(array($count,hash_final($digest),$id));
            $this->audit($adminId,'snapshot_email_campaign',$id,array('recipient_count'=>$count,'version'=>(int)$c['version']));
        });return $this->get($id);
    }
    public function backToDraft($id,$version,$adminId){
        $this->transaction(function()use($id,$version,$adminId){$c=$this->lock($id);$this->version($c,$version);if($c['status']!=='ready')throw new RuntimeException('仅未确认快照可退回草稿。');
            $this->pdo->prepare('DELETE FROM email_campaign_recipients WHERE campaign_id=? AND outbox_id IS NULL')->execute(array($id));
            $this->pdo->prepare('UPDATE email_campaigns SET status="draft",version=version+1,recipient_count=0,snapshot_digest="",snapshot_at=NULL,enqueue_cursor=0,updated_at=NOW() WHERE id=?')->execute(array($id));$this->audit($adminId,'edit_email_campaign',$id);
        });return $this->get($id);
    }
    private function trustedBaseUrl(){
        $url=isset($this->config['app']['base_url'])?$this->config['app']['base_url']:'';
        if(!is_string($url)||!filter_var($url,FILTER_VALIDATE_URL))throw new RuntimeException('请先配置可信站点地址，才可确认群发。');
        $p=parse_url($url);if(!isset($p['scheme'],$p['host'])||!in_array(strtolower($p['scheme']),array('http','https'),true)||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])||strlen($url)>500||preg_match('/[\x00-\x20\x7f]/',$url))throw new RuntimeException('可信站点地址无效。');
        return rtrim($url,'/');
    }
    public function confirm($id,$version,$adminId){
        $this->transaction(function()use($id,$version,$adminId){$c=$this->lock($id);$this->version($c,$version);
            if(in_array($c['status'],array('queued','completed'),true))return;
            if($c['status']!=='ready'||(int)$c['recipient_count']===0)throw new RuntimeException('请先生成非空收件人快照并预览。');
            $base=$this->trustedBaseUrl();$this->pdo->prepare('UPDATE email_campaigns SET status="queued",trusted_base_url=?,confirmed_at=NOW(),confirmed_by=?,updated_at=NOW() WHERE id=?')->execute(array($base,$adminId,$id));
            $this->audit($adminId,'confirm_email_campaign',$id,array('recipient_count'=>(int)$c['recipient_count'],'version'=>(int)$c['version']));
        });return $this->get($id);
    }
    public function cancel($id,$adminId){
        $this->transaction(function()use($id,$adminId){$c=$this->lock($id);if($c['status']==='cancelled')return;if($c['status']==='completed')throw new RuntimeException('该任务已经结束。');
            $this->pdo->prepare('UPDATE email_campaigns SET status="cancelled",cancelled_at=NOW(),updated_at=NOW() WHERE id=?')->execute(array($id));
            // 仅终止本任务尚未发出的新群发项；处理中由投递前校验拦截，已在SMTP中的邮件不可撤回。
            $this->pdo->prepare('UPDATE notification_outbox o JOIN email_campaign_recipients r ON r.outbox_id=o.id SET o.status="failed",o.last_error="campaign_cancelled",o.locked_at=NULL,o.updated_at=NOW() WHERE r.campaign_id=? AND o.channel="email" AND o.event_type="broadcast_notice" AND o.status IN ("pending","retry")')->execute(array($id));
            $this->audit($adminId,'cancel_email_campaign',$id);
        });return $this->get($id);
    }
    /** 每次至多20项；游标、token hash、队列关联同时提交，重复运行不重复入队。 */
    public function enqueueBatch($limit=20){
        $limit=max(1,min(20,(int)$limit));
        return $this->transaction(function()use($limit){
            $s=$this->pdo->query('SELECT * FROM email_campaigns WHERE status="queued" AND enqueue_complete=0 ORDER BY id LIMIT 1 FOR UPDATE');$c=$s->fetch();if(!$c)return 0;
            $s=$this->pdo->prepare('SELECT * FROM email_campaign_recipients WHERE campaign_id=? AND id>? ORDER BY id LIMIT '.$limit);$s->execute(array($c['id'],$c['enqueue_cursor']));$rows=$s->fetchAll();$cursor=(int)$c['enqueue_cursor'];$outbox=new NotificationOutboxService($this->pdo);
            foreach($rows as $r){
                $token=bin2hex(random_bytes(32));$key='broadcast_notice:'.$r['id'];
                $payload=array('campaign_id'=>(int)$c['id'],'recipient_id'=>(int)$r['id'],'user_id'=>(int)$r['user_id'],'to'=>$r['email_snapshot'],'unsubscribe_token'=>$token);
                $outbox->enqueue('broadcast_notice',$key,$payload,'email');$s=$this->pdo->prepare('SELECT id FROM notification_outbox WHERE dedupe_key=? AND channel="email" AND event_type="broadcast_notice"');$s->execute(array($key));$oid=$s->fetchColumn();if(!$oid)throw new RuntimeException('群发入队失败。');
                $this->pdo->prepare('UPDATE email_campaign_recipients SET outbox_id=?,token_hash=? WHERE id=? AND outbox_id IS NULL')->execute(array($oid,hash('sha256',$token),$r['id']));$cursor=(int)$r['id'];
            }
            $s=$this->pdo->prepare('SELECT COUNT(*) FROM email_campaign_recipients WHERE campaign_id=? AND id>?');$s->execute(array($c['id'],$cursor));$done=(int)$s->fetchColumn()===0;
            $this->pdo->prepare('UPDATE email_campaigns SET enqueue_cursor=?,enqueue_complete=?,updated_at=NOW() WHERE id=?')->execute(array($cursor,$done?1:0,$c['id']));return count($rows);
        });
    }
    public function refreshCompleted(){
        $this->pdo->exec('UPDATE email_campaigns c SET c.status="completed",c.updated_at=NOW() WHERE c.status="queued" AND c.enqueue_complete=1 AND NOT EXISTS (SELECT 1 FROM email_campaign_recipients r LEFT JOIN notification_outbox o ON o.id=r.outbox_id WHERE r.campaign_id=c.id AND (o.id IS NULL OR o.status IN ("pending","retry","processing")))');
    }
    private function paginate($from,$args,$page,$select,$order='id DESC'){
        $page=max(1,(int)$page);$s=$this->pdo->prepare('SELECT COUNT(*) '.$from);$s->execute($args);$total=(int)$s->fetchColumn();$pages=max(1,(int)ceil($total/50));$page=min($page,$pages);
        $s=$this->pdo->prepare('SELECT '.$select.' '.$from.' ORDER BY '.$order.' LIMIT 50 OFFSET '.(($page-1)*50));$s->execute($args);return array('items'=>$s->fetchAll(),'total'=>$total,'page'=>$page,'pages'=>$pages);
    }
    public function listing($page=1){return $this->paginate('FROM email_campaigns',array(),$page,'id,subject,scope,status,version,recipient_count,created_by,created_at,updated_at,confirmed_at,cancelled_at');}
    public function get($id){
        $s=$this->pdo->prepare('SELECT * FROM email_campaigns WHERE id=?');$s->execute(array($id));$c=$s->fetch();if(!$c)throw new RuntimeException('群发任务不存在。');$c['user_ids']=json_decode($c['selected_user_ids'],true);unset($c['selected_user_ids']);
        $counts=array('not_queued'=>0,'pending'=>0,'retry'=>0,'processing'=>0,'sent'=>0,'failed'=>0,'skipped'=>0,'cancelled'=>0);
        $s=$this->pdo->prepare('SELECT o.status,o.last_error,COUNT(*) n FROM email_campaign_recipients r LEFT JOIN notification_outbox o ON o.id=r.outbox_id WHERE r.campaign_id=? GROUP BY o.status,o.last_error');$s->execute(array($id));
        foreach($s as $r){$state=$this->recipientState($r,$c['status']);if(!isset($counts[$state]))$state='failed';$counts[$state]+=(int)$r['n'];}$c['counts']=$counts;return $c;
    }
    private function recipientState($r,$campaignStatus){
        if(!$r['status'])return $campaignStatus==='cancelled'?'cancelled':'not_queued';
        if($r['status']==='failed'&&$r['last_error']==='campaign_cancelled')return 'cancelled';
        if($r['status']==='failed'&&in_array($r['last_error'],array('customer_payload_invalid','customer_user_inactive','customer_email_changed','customer_event_disabled','customer_generation_expired','customer_order_invalid','campaign_unsubscribed'),true))return 'skipped';
        return $r['status'];
    }
    public function recipients($id,$page=1){
        $c=$this->get($id);$result=$this->paginate('FROM email_campaign_recipients r LEFT JOIN notification_outbox o ON o.id=r.outbox_id WHERE r.campaign_id=?',array($id),$page,'r.id,r.user_id,r.email_snapshot,r.outbox_id,o.status,o.last_error,o.retry_count,o.sent_at','r.id');
        foreach($result['items'] as &$r){$r['email_masked']=AdminLogService::maskEmail($r['email_snapshot']);unset($r['email_snapshot']);$r['status']=$this->recipientState($r,$c['status']);$r['reason']=$r['status']==='cancelled'?AdminLogService::reason('campaign_cancelled'):(!empty($r['last_error'])?AdminLogService::reason($r['last_error']):'—');unset($r['last_error']);}unset($r);return $result;
    }
    public function eligibleUsers($search='',$page=1){
        if(!is_string($search)||strlen($search)>190)throw new InvalidArgumentException('搜索条件过长。');$where='u.status="active" AND COALESCE(p.broadcast_unsubscribed,0)=0';$args=array();if($search!==''){$where.=' AND (u.display_name LIKE ? OR u.email LIKE ?)';$args=array('%'.$search.'%','%'.$search.'%');}
        $result=$this->paginate('FROM users u LEFT JOIN email_preferences p ON p.user_id=u.id WHERE '.$where,$args,$page,'u.id,u.display_name,u.email','u.id');
        $items=array();foreach($result['items'] as $u){if(!filter_var($u['email'],FILTER_VALIDATE_EMAIL))continue;$u['email_masked']=AdminLogService::maskEmail($u['email']);unset($u['email']);$items[]=$u;}$result['items']=$items;return $result;
    }
    private function tokenUser($token){
        if(!is_string($token)||!preg_match('/\A[a-f0-9]{64}\z/D',$token))return false;
        $s=$this->pdo->prepare('SELECT user_id FROM email_campaign_recipients WHERE token_hash=? LIMIT 1');$s->execute(array(hash('sha256',$token)));return $s->fetchColumn();
    }
    public function unsubscribeInfo($token){$uid=$this->tokenUser($token);if(!$uid)return array('valid'=>false,'unsubscribed'=>false);$s=$this->pdo->prepare('SELECT broadcast_unsubscribed FROM email_preferences WHERE user_id=?');$s->execute(array($uid));return array('valid'=>true,'unsubscribed'=>(bool)$s->fetchColumn());}
    public function unsubscribe($token){
        return $this->transaction(function()use($token){$uid=$this->tokenUser($token);if(!$uid)return false;
            $this->pdo->prepare('INSERT INTO email_preferences(user_id,broadcast_unsubscribed,updated_at) VALUES(?,1,NOW()) ON DUPLICATE KEY UPDATE broadcast_unsubscribed=1,updated_at=VALUES(updated_at)')->execute(array($uid));return true;
        });
    }
}
