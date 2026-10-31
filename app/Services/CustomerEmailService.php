<?php
/** 客户邮件只在原业务事务中入队；发送器再次核对资格，绝不补发旧代次。 */
class CustomerEmailService
{
    private $pdo;
    private $config;
    public function __construct($pdo,$config=array()){$this->pdo=$pdo;$this->config=$config;}
    public function settings(){
        $s=$this->pdo->query('SELECT setting_key,setting_value FROM system_settings WHERE setting_key IN ("customer_order_created_enabled","customer_order_created_generation","customer_order_delivered_enabled","customer_order_delivered_generation")');
        $values=array();foreach($s as $r)$values[$r['setting_key']]=$r['setting_value'];$result=array();
        foreach(array('customer_order_created','customer_order_delivered') as $event){$result[$event.'_enabled']=isset($values[$event.'_enabled'])&&$values[$event.'_enabled']==='1';$result[$event.'_generation']=isset($values[$event.'_generation'])?(int)$values[$event.'_generation']:0;}
        return $result;
    }
    public function saveSettings($input,$adminId=null){
        $own=!$this->pdo->inTransaction();if($own)$this->pdo->beginTransaction();
        try{
            foreach(array('customer_order_created','customer_order_delivered') as $event){
                $key=$event.'_enabled';if(!array_key_exists($key,$input))continue;
                if(!in_array($input[$key],array(true,false,0,1,'0','1'),true))throw new InvalidArgumentException('客户通知开关无效。');
                // 固定顺序锁配置，关闭时递增代次，即使随后重新启用也不补发。
                foreach(array($key,$event.'_generation') as $k)$this->pdo->prepare('INSERT IGNORE INTO system_settings(setting_key,setting_value,updated_by,updated_at) VALUES(?,"0",?,NOW())')->execute(array($k,$adminId));
                $s=$this->pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key=? FOR UPDATE');$s->execute(array($key));$old=$s->fetchColumn();$new=!empty($input[$key])?'1':'0';
                if($old==='1'&&$new==='0')$this->pdo->prepare('UPDATE system_settings SET setting_value=CAST(setting_value AS UNSIGNED)+1,updated_by=?,updated_at=NOW() WHERE setting_key=?')->execute(array($adminId,$event.'_generation'));
                $this->pdo->prepare('UPDATE system_settings SET setting_value=?,updated_by=?,updated_at=NOW() WHERE setting_key=?')->execute(array($new,$adminId,$key));
            }
            if($own)$this->pdo->commit();
        }catch(Throwable $e){if($own&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function enqueueOrder($event,$order,$delivery=null){
        if(!in_array($event,array('customer_order_created','customer_order_delivered'),true))throw new InvalidArgumentException('客户通知事件无效。');
        if(!$this->pdo->inTransaction())throw new LogicException('客户通知必须在订单事务中入队。');
        $cfg=$this->settings();if(!$cfg[$event.'_enabled'])return false;
        $s=$this->pdo->prepare('SELECT email,status FROM users WHERE id=?');$s->execute(array($order['user_id']));$user=$s->fetch();
        if(!$user||$user['status']!=='active'||!filter_var($user['email'],FILTER_VALIDATE_EMAIL))return false;
        // 仅挑选非敏感字段，不复制订单、备注或交付载荷。
        $payload=array('user_id'=>(int)$order['user_id'],'order_id'=>(int)$order['id'],'order_no'=>(string)$order['order_no'],'product_summary'=>(string)$order['product_name_snapshot'].' / '.(string)$order['plan_name_snapshot'],'to'=>$user['email'],'generation'=>$cfg[$event.'_generation']);
        $dedupe=$event.':'.$order['id'];
        if($event==='customer_order_delivered'){
            if(!is_array($delivery)||empty($delivery['id'])||empty($delivery['version_no']))throw new InvalidArgumentException('交付邮件必须绑定交付版本。');
            $payload['payload_format_version']=2;$payload['delivery_id']=(int)$delivery['id'];$payload['delivery_version']=(int)$delivery['version_no'];$dedupe=$event.':delivery:'.$payload['delivery_id'];
        }
        (new NotificationOutboxService($this->pdo))->enqueue($event,$dedupe,$payload,'email');return true;
    }
    public function eligibility($row,$payload){
        if(!is_array($payload)||empty($payload['user_id'])||!isset($payload['to'])||!is_string($payload['to'])||!filter_var($payload['to'],FILTER_VALIDATE_EMAIL))return 'customer_payload_invalid';
        $s=$this->pdo->prepare('SELECT email,status FROM users WHERE id=?');$s->execute(array($payload['user_id']));$user=$s->fetch();
        if(!$user||$user['status']!=='active')return 'customer_user_inactive';
        if(strcasecmp($user['email'],$payload['to'])!==0)return 'customer_email_changed';
        if(in_array($row['event_type'],array('customer_order_created','customer_order_delivered'),true)){
            $cfg=$this->settings();$event=$row['event_type'];
            if(!$cfg[$event.'_enabled'])return 'customer_event_disabled';
            if(!isset($payload['generation'])||(int)$payload['generation']!==$cfg[$event.'_generation'])return 'customer_generation_expired';
            $s=$this->pdo->prepare('SELECT id,status,product_type_snapshot FROM orders WHERE id=? AND user_id=? AND order_no=?');$s->execute(array(isset($payload['order_id'])?$payload['order_id']:0,$payload['user_id'],isset($payload['order_no'])?$payload['order_no']:''));$order=$s->fetch();
            if(!$order)return 'customer_order_invalid';
            if($event==='customer_order_delivered'){
                if($order['status']!=='delivered')return 'customer_delivery_inactive';
                if($this->hasBinding($payload))return $this->deliveryCheck($payload,$order);
            }
            return null;
        }
        if($row['event_type']!=='broadcast_notice')return 'customer_payload_invalid';
        $s=$this->pdo->prepare('SELECT c.status,r.email_snapshot,r.user_id,r.token_hash FROM email_campaign_recipients r JOIN email_campaigns c ON c.id=r.campaign_id WHERE r.id=? AND r.campaign_id=? AND r.outbox_id=?');
        $s->execute(array(isset($payload['recipient_id'])?$payload['recipient_id']:0,isset($payload['campaign_id'])?$payload['campaign_id']:0,$row['id']));$r=$s->fetch();
        if(!$r||$r['status']!=='queued')return 'campaign_cancelled';
        if((int)$r['user_id']!==(int)$payload['user_id']||strcasecmp($r['email_snapshot'],$payload['to'])!==0||!isset($payload['unsubscribe_token'])||!is_string($payload['unsubscribe_token'])||!hash_equals((string)$r['token_hash'],hash('sha256',$payload['unsubscribe_token'])))return 'customer_payload_invalid';
        $s=$this->pdo->prepare('SELECT broadcast_unsubscribed FROM email_preferences WHERE user_id=?');$s->execute(array($payload['user_id']));
        return $s->fetchColumn()?'campaign_unsubscribed':null;
    }
    private function hasBinding($payload){return array_key_exists('payload_format_version',$payload)||array_key_exists('delivery_id',$payload)||array_key_exists('delivery_version',$payload);}
    private function boundDelivery($payload){
        $s=$this->pdo->prepare('SELECT * FROM delivery_records WHERE order_id=? AND is_current=1');$s->execute(array($payload['order_id']));$rows=$s->fetchAll();
        if(count($rows)!==1)return null;$d=$rows[0];
        return (int)$d['id']===(int)$payload['delivery_id']&&(int)$d['version_no']===(int)$payload['delivery_version']?$d:null;
    }
    private function deliveryCheck($payload,$order){
        foreach(array('delivery_id','delivery_version') as $k)if(!isset($payload[$k])||(!is_int($payload[$k])&&!is_string($payload[$k]))||!preg_match('/\A[1-9][0-9]*\z/D',(string)$payload[$k]))return 'customer_delivery_binding_invalid';
        if(!isset($payload['payload_format_version'])||$payload['payload_format_version']!==2)return 'customer_delivery_binding_invalid';
        if(!in_array($order['product_type_snapshot'],array('vpn','account'),true))return 'customer_delivery_data_invalid';
        $d=$this->boundDelivery($payload);if(!$d)return 'customer_delivery_version_expired';
        if($order['product_type_snapshot']==='vpn'){
            foreach(array('opened_at','expires_at') as $k){$v=$d[$k];$dt=is_string($v)?DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$v):false;if(!$dt||$dt->format('Y-m-d H:i:s')!==$v)return 'customer_delivery_data_invalid';}
            if($d['expires_at']<=$d['opened_at'])return 'customer_delivery_data_invalid';
            if($d['expires_at']<=date('Y-m-d H:i:s'))return 'customer_delivery_expired';
        }
        return null;
    }
    public function revalidate($row,$payload){return $this->eligibility($row,$payload);}
    public function prepare($row,$payload){
        $reason=$this->eligibility($row,$payload);if($reason!==null)return array('reason'=>$reason,'message'=>null);
        try{return array('reason'=>null,'message'=>$this->message($row,$payload));}
        catch(PDOException $e){throw $e;}
        catch(UnexpectedValueException $e){return array('reason'=>$e->getMessage(),'message'=>null);}
    }
    public function message($row,$payload){
        $brand=isset($this->config['app']['name'])?$this->config['app']['name']:'朝夕';
        if($row['event_type']==='broadcast_notice'){
            $s=$this->pdo->prepare('SELECT subject,body,trusted_base_url FROM email_campaigns WHERE id=?');$s->execute(array($payload['campaign_id']));$c=$s->fetch();if(!$c)throw new RuntimeException('群发任务不存在。');
            $link=$c['trusted_base_url'].'/email/unsubscribe?token='.rawurlencode($payload['unsubscribe_token']);
            return self::render($brand,$c['subject'],$c['body']."\n\n退订通知群发（不影响交易和安全邮件）：\n".$link,$link);
        }
        $reason=$this->eligibility($row,$payload);if($reason!==null)throw new UnexpectedValueException($reason);
        $modules=array();$base=isset($this->config['app']['base_url'])?rtrim($this->config['app']['base_url'],'/'):'';
        if($base!==''&&filter_var($base,FILTER_VALIDATE_URL)&&in_array(strtolower((string)parse_url($base,PHP_URL_SCHEME)),array('http','https'),true))$modules['order_link']=$base.'/orders/'.rawurlencode($payload['order_no']);
        if($row['event_type']==='customer_order_delivered'&&$this->hasBinding($payload)){
            $s=$this->pdo->prepare('SELECT product_type_snapshot FROM orders WHERE id=? AND user_id=?');$s->execute(array($payload['order_id'],$payload['user_id']));$type=$s->fetchColumn();
            // 账号自由文字永不读取/解密；旧无绑定队列永不补绑当前交付。
            if($type==='vpn'){
                $d=$this->boundDelivery($payload);if(!$d)throw new UnexpectedValueException('customer_delivery_version_expired');
                try{$plain=(new CryptoService($this->config))->decrypt($d['content_ciphertext'],$d['content_nonce'],$d['content_tag']);$data=json_decode($plain,true);}
                catch(Throwable $e){throw new UnexpectedValueException('customer_delivery_decrypt_failed');}
                if(!is_array($data))throw new UnexpectedValueException('customer_delivery_data_invalid');
                foreach(array('subscription','instructions','opened_at','expires_at') as $k)if(!isset($data[$k])||!is_string($data[$k])||!preg_match('//u',$data[$k])||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$data[$k]))throw new UnexpectedValueException('customer_delivery_data_invalid');
                if(trim($data['subscription'])===''||$data['opened_at']!==$d['opened_at']||$data['expires_at']!==$d['expires_at'])throw new UnexpectedValueException('customer_delivery_data_invalid');
                $modules['delivery']=array_intersect_key($data,array_flip(array('subscription','instructions','opened_at','expires_at')));
            }
        }
        return (new EmailTemplateService($this->pdo,$this->config))->render($row['event_type'],array('brand'=>$brand,'order_no'=>$payload['order_no'],'product_summary'=>$payload['product_summary']),$modules);
    }
    private static function render($brand,$subject,$body,$unsubscribeLink=null){
        $brand=trim(str_replace(array("\r","\n"),' ',(string)$brand));$esc=function($s){return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');};
        $html='<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;background:#f4f3fa;color:#252338;font-family:Arial,Microsoft YaHei,sans-serif"><table role="presentation" width="100%"><tr><td align="center" style="padding:32px 16px"><table role="presentation" width="560" style="width:100%;max-width:560px"><tr><td style="padding:16px;color:#6554c0;font-size:20px;font-weight:bold">'.$esc($brand).'</td></tr><tr><td style="padding:32px 24px;background:#fff;border:1px solid #e8e4f2;border-top:4px solid #7762d7;border-radius:16px"><h1 style="font-size:24px">'.$esc($subject).'</h1><div style="font-size:14px;line-height:1.9;word-break:break-word">'.nl2br($esc($body)).'</div>'.($unsubscribeLink!==null?'<p style="padding-top:16px;font-size:13px"><a href="'.$esc($unsubscribeLink).'" style="color:#6554c0">退订通知群发</a>（不影响交易和安全邮件）</p>':'').'</td></tr><tr><td style="padding:20px;text-align:center;font-size:12px;color:#9791a5">此邮件由 '.$esc($brand).' 自动发送，请勿直接回复。</td></tr></table></td></tr></table></body></html>';
        return array('subject'=>$brand.' · '.$subject,'body'=>$brand."\n\n".$body,'html'=>$html);
    }
}
