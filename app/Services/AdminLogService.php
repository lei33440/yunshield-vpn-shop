<?php
class AdminLogService
{
    private $pdo;
    public function __construct($pdo) { $this->pdo=$pdo; }

    public static function actions() {
        return array('save_email_template'=>'保存邮件模板','reset_email_template'=>'恢复邮件模板默认值','create_account_platform'=>'创建账号平台','update_account_platform'=>'修改账号平台','delete_account_platform'=>'删除账号平台','create_vpn_category'=>'创建订阅分类','update_vpn_category'=>'修改订阅分类','delete_vpn_category'=>'删除订阅分类','admin_login_success'=>'管理员登录成功','admin_login_failed'=>'管理员登录失败','test_smtp_email'=>'测试邮件','deliver_order'=>'订单发货','update_delivery'=>'更正发货','revert_delivery'=>'退回待发货','confirm_payment'=>'确认付款','cancel_order'=>'取消订单','approve_refund'=>'同意退款','reject_refund'=>'拒绝退款','create_product'=>'创建商品','update_product'=>'修改商品','delete_product'=>'删除商品','save_email_campaign'=>'保存邮件群发草稿','snapshot_email_campaign'=>'生成群发收件快照','edit_email_campaign'=>'群发退回草稿','confirm_email_campaign'=>'确认邮件群发','cancel_email_campaign'=>'取消邮件群发','update_smtp_settings'=>'修改邮箱设置','update_wecom_settings'=>'修改企业微信设置','update_payment_settings'=>'修改支付设置','update_temporary_subscription'=>'修改临时订阅','update_general_settings'=>'修改基础设置');
    }
    public static function events() { return array('customer_order_created'=>'用户下单通知','customer_order_delivered'=>'用户发货通知','broadcast_notice'=>'通知邮件群发','password_reset'=>'找回密码','order_delivered'=>'订单发货邮件','delivered'=>'订单发货','delivery_updated'=>'更正发货','delivery_reverted'=>'退回待发货','ticket_reply'=>'工单回复'); }
    public static function results() { return array('accepted'=>'SMTP 调用成功','failed'=>'失败','started'=>'结果未确认','legacy'=>'历史成功记录'); }
    public static function statuses() { return array('pending'=>'待发送','processing'=>'处理中','retry'=>'等待重试','failed'=>'失败 / 已跳过','sent'=>'SMTP 已接受'); }
    public static function reason($code) {
        $labels=array('customer_delivery_inactive'=>'订单交付已撤回或退款，已跳过','customer_delivery_binding_invalid'=>'交付绑定无效，已跳过','customer_delivery_version_expired'=>'交付版本已更正或失效，已跳过','customer_delivery_data_invalid'=>'交付数据无效，已跳过','customer_delivery_expired'=>'正式订阅已过期，已跳过','customer_delivery_decrypt_failed'=>'交付无法解密，已跳过','customer_payload_invalid'=>'通知数据无效，已跳过','customer_user_inactive'=>'用户不存在或已停用，已跳过','customer_email_changed'=>'用户邮箱已变更，不改投新地址','customer_event_disabled'=>'通知开关已关闭，已跳过','customer_generation_expired'=>'通知代次已失效，不补发旧通知','customer_order_invalid'=>'订单已不存在或不匹配，已跳过','campaign_cancelled'=>'群发已取消，未发送项已停止','campaign_unsubscribed'=>'用户已退订群发，已跳过','customer_transport_retryable'=>'邮件传输异常，有限自动重试','credentials_invalid'=>'账号、密码或状态不符合登录条件','smtp_disabled'=>'SMTP 未启用','smtp_config_incomplete'=>'SMTP 配置不完整','recipient_invalid'=>'收件地址无效','smtp_connect_failed'=>'SMTP 网络连接失败','smtp_tls_failed'=>'TLS 握手失败','smtp_response_unexpected'=>'SMTP 响应异常','send_failed'=>'发送失败，详情已隐藏','password_reset_invalid_or_expired'=>'找回链接已失效，已跳过','notification_transport_retryable'=>'网络或传输异常，等待重试','notification_delivery_failed'=>'通知发送失败');
        return isset($labels[$code])?$labels[$code]:'历史错误详情已隐藏';
    }
    public static function maskEmail($email) {
        if(!is_string($email)||!filter_var($email,FILTER_VALIDATE_EMAIL))return '未记录 / 无效地址';
        list($local,$domain)=explode('@',$email,2);
        return substr($local,0,1).'***@'.$domain;
    }
    private static function details($value) { $d=json_decode((string)$value,true);return is_array($d)?$d:array(); }
    private function insert($adminId,$action,$type,$details) {
        $s=$this->pdo->prepare('INSERT INTO admin_logs(admin_id,action,target_type,details_json,created_at) VALUES(?,?,?,?,?)');
        $s->execute(array($adminId,$action,$type,json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),date('Y-m-d H:i:s')));
        return $this->pdo->lastInsertId();
    }
    public function authenticate($email,$password,$ip) {
        $s=$this->pdo->prepare('SELECT id,email,display_name,password_hash,status FROM admin_users WHERE email=?');$s->execute(array($email));$a=$s->fetch();
        $ok=$a&&password_verify($password,$a['password_hash'])&&$a['status']==='active';
        $details=array('schema_version'=>1,'ip'=>filter_var($ip,FILTER_VALIDATE_IP)?$ip:'','result'=>$ok?'success':'failed','reason'=>$ok?'':'credentials_invalid');
        if(!$ok){
            try{$this->insert($a?$a['id']:0,'admin_login_failed','admin',$details);}catch(Throwable $e){error_log('admin_login_audit_failed');}
            return false;
        }
        $this->pdo->beginTransaction();
        try{
            $this->insert($a['id'],'admin_login_success','admin',$details);
            $this->pdo->prepare('UPDATE admin_users SET last_login_at=? WHERE id=?')->execute(array(date('Y-m-d H:i:s'),$a['id']));
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw new RuntimeException('管理员登录暂不可用，请稍后重试。');}
        unset($a['password_hash']);return $a;
    }
    private static function emailError($e) {
        $codes=array('SMTP 未启用。'=>'smtp_disabled','SMTP 尚未完整配置。'=>'smtp_config_incomplete','收件地址无效。'=>'recipient_invalid','SMTP 网络连接失败。'=>'smtp_connect_failed','SMTP TLS 握手失败。'=>'smtp_tls_failed','SMTP 响应异常。'=>'smtp_response_unexpected');
        return isset($codes[$e->getMessage()])?$codes[$e->getMessage()]:'send_failed';
    }
    public function testEmail($sender,$adminId,$recipient) {
        $d=array('schema_version'=>1,'recipient_masked'=>self::maskEmail($recipient),'result'=>'started','started_at'=>date('Y-m-d H:i:s'));
        $id=$this->insert($adminId,'test_smtp_email','system',$d);
        try{
            if($sender->sendTest($recipient)===false)throw new RuntimeException('SMTP 未启用。');
            $d['result']='accepted';$d['error_code']='';
        }catch(Throwable $e){$d['result']='failed';$d['error_code']=self::emailError($e);}
        $d['finished_at']=date('Y-m-d H:i:s');$saved=true;
        try{$this->pdo->prepare('UPDATE admin_logs SET details_json=? WHERE id=? AND admin_id=? AND action="test_smtp_email"')->execute(array(json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$id,$adminId));}
        catch(Throwable $e){$saved=false;error_log('smtp_test_audit_update_failed');}
        return array('id'=>$id,'result'=>$d['result'],'error_code'=>$d['error_code'],'log_saved'=>$saved);
    }
    private static function text($input,$key) { return isset($input[$key])&&is_string($input[$key])?trim($input[$key]):''; }
    public static function filters($input) {
        $f=array('tab'=>'audit','source'=>'queue','from'=>'','to'=>'','admin_id'=>'','status'=>'','action'=>'','event'=>'','order_no'=>'','id'=>'','page'=>1);
        $tab=self::text($input,'tab');if(in_array($tab,array('audit','login','delivery','email'),true))$f['tab']=$tab;
        if(self::text($input,'source')==='test')$f['source']='test';
        foreach(array('from','to') as $key){$v=self::text($input,$key);if($v==='')continue;if(!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',$v))throw new InvalidArgumentException('请输入有效的起止日期。');$date=DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$date||$date->format('Y-m-d')!==$v||$v<'1000-01-01'||$v>'9998-12-31')throw new InvalidArgumentException('请输入有效的起止日期。');$f[$key]=$v;}
        if($f['from']!==''&&$f['to']!==''&&$f['from']>$f['to'])throw new InvalidArgumentException('开始日期不能晚于结束日期。');
        foreach(array('admin_id','id','page') as $key){$v=self::text($input,$key);if(preg_match('/\A[0-9]{1,9}\z/D',$v)&&($key!=='page'||(int)$v>0))$f[$key]=$key==='page'?max(1,(int)$v):(string)(int)$v;}
        $v=self::text($input,'action');if(isset(self::actions()[$v]))$f['action']=$v;
        $v=self::text($input,'event');if(isset(self::events()[$v]))$f['event']=$v;
        $states=$f['tab']==='login'?array('success'=>'成功','failed'=>'失败'):($f['source']==='test'?self::results():self::statuses());
        $v=self::text($input,'status');if(isset($states[$v]))$f['status']=$v;
        $v=self::text($input,'order_no');if(strlen($v)<=40)$f['order_no']=$v;
        return $f;
    }
    public function listing($input) {
        $f=self::filters($input);$where=array();$args=array();$tab=$f['tab'];$test=$tab==='email'&&$f['source']==='test';
        if($tab==='delivery'){
            $from='order_events l LEFT JOIN orders o ON o.id=l.order_id LEFT JOIN admin_users a ON l.actor_type="admin" AND a.id=l.actor_id';
            $select='l.id,l.created_at,l.event_type,l.order_id,l.actor_type,l.actor_id,l.old_status,l.new_status,o.order_no,a.display_name';
            $where[]='l.event_type IN ("delivered","delivery_updated","delivery_reverted")';
            if($f['order_no']!==''){$where[]='o.order_no=?';$args[]=$f['order_no'];}
            if(in_array($f['event'],array('delivered','delivery_updated','delivery_reverted'),true)){$where[]='l.event_type=?';$args[]=$f['event'];}
        }elseif($tab==='email'&&!$test){
            $from='notification_outbox l';$select='l.id,l.created_at,l.updated_at,l.event_type,l.payload_json,l.status,l.retry_count,l.available_at,l.locked_at,l.sent_at,l.last_error';$where[]='l.channel="email"';
            if($f['status']!==''){$where[]='l.status=?';$args[]=$f['status'];}
            if($f['event']!==''){$where[]='l.event_type=?';$args[]=$f['event'];}
            if($f['id']!==''){$where[]='l.id=?';$args[]=$f['id'];}
        }else{
            $from='admin_logs l LEFT JOIN admin_users a ON a.id=l.admin_id';$select='l.id,l.created_at,l.admin_id,l.action,l.target_type,l.target_id,l.details_json,a.display_name';
            if($tab==='login'){
                $where[]='l.action IN ("admin_login_success","admin_login_failed")';
                if($f['status']!==''){$where[]='l.action=?';$args[]=$f['status']==='success'?'admin_login_success':'admin_login_failed';}
            }elseif($test){
                $where[]='l.action="test_smtp_email"';
                if($f['status']!==''){$where[]="COALESCE(JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(l.details_json) THEN l.details_json ELSE '{}' END,'$.result')),'legacy')=?";$args[]=$f['status'];}
            }elseif($f['action']!==''){$where[]='l.action=?';$args[]=$f['action'];}
        }
        if(!($tab==='email'&&!$test)&&$f['admin_id']!==''){$where[]=$tab==='delivery'?'(l.actor_type="admin" AND l.actor_id=?)':'l.admin_id=?';$args[]=$f['admin_id'];}
        if($f['from']!==''){$where[]='l.created_at>=?';$args[]=$f['from'].' 00:00:00';}
        if($f['to']!==''){$where[]='l.created_at<?';$args[]=(new DateTimeImmutable($f['to']))->modify('+1 day')->format('Y-m-d').' 00:00:00';}
        $sql=' FROM '.$from.' WHERE '.($where?implode(' AND ',$where):'1=1');
        $s=$this->pdo->prepare('SELECT COUNT(*)'.$sql);$s->execute($args);$total=(int)$s->fetchColumn();$pages=max(1,(int)ceil($total/50));$f['page']=min($f['page'],$pages);
        $s=$this->pdo->prepare('SELECT '.$select.$sql.' ORDER BY l.id DESC LIMIT 50 OFFSET '.(($f['page']-1)*50));$s->execute($args);
        $items=array();foreach($s->fetchAll() as $row)$items[]=$this->project($row,$tab,$test);
        return array('filters'=>$f,'items'=>$items,'total'=>$total,'pages'=>$pages,'page'=>$f['page']);
    }
    private function project($r,$tab,$test) {
        $item=array('id'=>$r['id'],'time'=>$r['created_at']);
        if($tab==='email'&&!$test){
            $payload=self::details($r['payload_json']);$events=self::events();$states=self::statuses();
            return $item+array('event'=>isset($events[$r['event_type']])?$events[$r['event_type']]:'其他邮件','recipient'=>self::maskEmail(isset($payload['to'])?$payload['to']:null),'status'=>isset($states[$r['status']])?$r['status']:'unknown','result'=>isset($states[$r['status']])?$states[$r['status']]:'未知状态','retries'=>(int)$r['retry_count'],'available'=>$r['available_at'],'locked'=>$r['locked_at'],'sent'=>$r['sent_at'],'updated'=>$r['updated_at'],'reason'=>$r['last_error']!==''?self::reason($r['last_error']):'—');
        }
        $actor=isset($r['admin_id'])?$r['admin_id']:(isset($r['actor_id'])?$r['actor_id']:0);
        $item['admin']=!empty($r['display_name'])?$r['display_name']:((int)$actor>0?'管理员 #'.(int)$actor:'未识别账号');
        if($tab==='delivery'){
            if($r['actor_type']!=='admin')$item['admin']=$r['actor_type']==='system'?'系统':'其他操作者';
            $states=array('delivered'=>'已发货','pending_delivery'=>'待发货');$events=self::events();
            return $item+array('event'=>$events[$r['event_type']],'order_no'=>$r['order_no']?:'订单已移除','order_id'=>$r['order_id'],'change'=>(isset($states[$r['old_status']])?$states[$r['old_status']]:'—').' → '.(isset($states[$r['new_status']])?$states[$r['new_status']]:'—'));
        }
        $d=self::details($r['details_json']);$actions=self::actions();$item['action']=isset($actions[$r['action']])?$actions[$r['action']]:'其他管理操作';
        if($tab==='login')return $item+array('status'=>$r['action']==='admin_login_success'?'success':'failed','result'=>$r['action']==='admin_login_success'?'成功':'失败','ip'=>isset($d['ip'])&&is_string($d['ip'])&&filter_var($d['ip'],FILTER_VALIDATE_IP)?$d['ip']:'未记录','reason'=>$r['action']==='admin_login_success'?'登录验证通过':self::reason('credentials_invalid'));
        if($test){
            $result=isset($d['result'])&&is_string($d['result'])&&isset(self::results()[$d['result']])?$d['result']:'legacy';
            $recipient=self::maskEmail(isset($d['recipient'])?$d['recipient']:null);
            if(isset($d['recipient_masked'])&&is_string($d['recipient_masked'])&&preg_match('/\A[^@\s]\*{3}@[A-Za-z0-9.-]+\z/D',$d['recipient_masked']))$recipient=$d['recipient_masked'];
            $finished=isset($d['finished_at'])&&is_string($d['finished_at'])&&preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D',$d['finished_at'])?$d['finished_at']:'—';
            return $item+array('recipient'=>$recipient,'status'=>$result,'result'=>self::results()[$result],'finished'=>$finished,'reason'=>$result==='failed'?self::reason(isset($d['error_code'])&&is_string($d['error_code'])?$d['error_code']:'send_failed'):($result==='started'?'尚未记录最终结果，请勿据此重复发送':'—'));
        }
        $targets=array('email_template'=>'邮件模板','email_campaign'=>'邮件群发','admin'=>'管理员','system'=>'系统设置','order'=>'订单','product'=>'商品','user'=>'用户','coupon'=>'优惠码','feedback'=>'反馈','ticket'=>'工单','announcement'=>'公告','article'=>'文章','download'=>'下载资源','vpn_category'=>'订阅分类','account_platform'=>'账号平台');
        $summary='已记录操作；敏感详情不展示';
        if($r['action']==='test_smtp_email')$summary='请在邮件日志中查看结果';
        elseif(in_array($r['action'],array('deliver_order','update_delivery'),true)&&isset($d['version'])&&is_numeric($d['version']))$summary='交付版本 v'.(int)$d['version'];
        elseif(in_array($r['action'],array('save_email_template','reset_email_template'),true)&&isset($d['key'],$d['version'],$d['result'])&&in_array($d['key'],EmailTemplateService::keys(),true)&&is_numeric($d['version'])&&$d['result']==='success')$summary=$d['key'].' / v'.(int)$d['version'].' / 成功';
        elseif($r['action']==='admin_login_success')$summary='登录验证通过';
        elseif($r['action']==='admin_login_failed')$summary=self::reason('credentials_invalid');
        return $item+array('target'=>(isset($targets[$r['target_type']])?$targets[$r['target_type']]:'其他对象').($r['target_id']?' #'.(int)$r['target_id']:''),'summary'=>$summary);
    }
}
