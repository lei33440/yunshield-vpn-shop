<?php
/** 结构化纯文字模板；敏感交付与安全提示始终由系统固定模块生成。 */
class EmailTemplateService
{
    private $pdo;
    private $config;
    public function __construct($pdo,$config=array()){$this->pdo=$pdo;$this->config=$config;}
    public static function keys(){return array('password_reset','customer_order_created','customer_order_delivered');}
    private function assertKey($key){if(!in_array($key,self::keys(),true))throw new InvalidArgumentException('邮件模板类型无效。');}
    public function defaults($key){
        $this->assertKey($key);
        $titles=array('password_reset'=>'密码重置','customer_order_created'=>'订单已提交','customer_order_delivered'=>'订单已发货');
        return array('subject'=>'{{brand}} · '.$titles[$key],'title'=>$titles[$key],'body'=>$key==='password_reset'?'我们收到了您的密码重置申请。请使用下方系统链接设置新密码。':"订单号：{{order_no}}\n商品：{{product_summary}}\n\n".($key==='customer_order_created'?'您的订单已提交，请登录查看订单进度。':'您的订单已完成发货，请核对下方交付信息。'),'button_text'=>$key==='password_reset'?'重置密码':'查看订单','color'=>'#7762D7');
    }
    public function variables($key){$this->assertKey($key);return $key==='password_reset'?array('brand'):array('brand','order_no','product_summary');}
    public function validate($key,$config){
        $this->assertKey($key);$limits=array('subject'=>200,'title'=>200,'body'=>20000,'button_text'=>80,'color'=>7);
        if(!is_array($config)||array_diff(array_keys($config),array_keys($limits))||array_diff(array_keys($limits),array_keys($config)))throw new InvalidArgumentException('模板字段无效。');
        foreach($limits as $field=>$limit){$v=$config[$field];if(!is_string($v)||!preg_match('//u',$v)||strlen($v)>$limit||trim($v)===''||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$v))throw new InvalidArgumentException('模板文字格式或长度无效。');
            if($field!=='body'&&preg_match('/[\r\n\t]/',$v))throw new InvalidArgumentException('模板单行文字不能包含控制字符。');
            if(preg_match('/[<>]|(?:https?:\/\/|javascript:|data:|url\s*\()/i',$v))throw new InvalidArgumentException('模板不支持 HTML、脚本或远程资源。');
            preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/',$v,$m);foreach($m[1] as $name)if(!in_array($name,$this->variables($key),true))throw new InvalidArgumentException('模板包含未知变量。');
            $rest=preg_replace('/\{\{\s*[a-z_]+\s*\}\}/','',$v);if(strpos($rest,'{')!==false||strpos($rest,'}')!==false)throw new InvalidArgumentException('模板变量格式无效。');
        }
        if(!preg_match('/\A#[0-9a-fA-F]{6}\z/D',$config['color']))throw new InvalidArgumentException('模板主题色无效。');return $config;
    }
    public function get($key){
        $this->assertKey($key);$s=$this->pdo->prepare('SELECT config_json,format_version,version_no FROM email_templates WHERE template_key=?');$s->execute(array($key));$r=$s->fetch();
        $c=$r?json_decode($r['config_json'],true):$this->defaults($key);if($r&&(int)$r['format_version']!==1)throw new RuntimeException('email_template_format_invalid');$c=$this->validate($key,$c);
        return array('key'=>$key,'config'=>$c,'version'=>$r?(int)$r['version_no']:0,'format_version'=>1,'variables'=>$this->variables($key));
    }
    public function save($key,$config,$expectedVersion,$adminId=null){return $this->persist($key,$this->validate($key,$config),$expectedVersion,$adminId,'save_email_template');}
    public function reset($key,$expectedVersion,$adminId=null){return $this->persist($key,$this->defaults($key),$expectedVersion,$adminId,'reset_email_template');}
    private function persist($key,$config,$expectedVersion,$adminId,$action){
        if((!is_int($expectedVersion)&&!is_string($expectedVersion))||!preg_match('/\A[0-9]+\z/D',(string)$expectedVersion))throw new InvalidArgumentException('模板版本无效。');
        $own=!$this->pdo->inTransaction();if($own)$this->pdo->beginTransaction();
        try{
            $s=$this->pdo->prepare('INSERT IGNORE INTO email_templates(template_key,format_version,version_no,config_json,updated_by,updated_at) VALUES(?,1,0,?,?,NOW())');$s->execute(array($key,json_encode($this->defaults($key),JSON_UNESCAPED_UNICODE),$adminId));
            $s=$this->pdo->prepare('UPDATE email_templates SET config_json=?,format_version=1,version_no=version_no+1,updated_by=?,updated_at=NOW() WHERE template_key=? AND version_no=?');$s->execute(array(json_encode($config,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$adminId,$key,(int)$expectedVersion));
            if($s->rowCount()!==1)throw new RuntimeException('email_template_version_conflict');
            $version=(int)$expectedVersion+1;
            $this->pdo->prepare('INSERT INTO admin_logs(admin_id,action,target_type,details_json,created_at) VALUES(?,?,"email_template",?,NOW())')->execute(array($adminId?:0,$action,json_encode(array('key'=>$key,'version'=>$version,'result'=>'success'))));
            $result=$this->get($key);if($own)$this->pdo->commit();return $result;
        }catch(Throwable $e){if($own&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    private function safeLink($link){if(!is_string($link)||preg_match('/[\x00-\x20\x7f]/',$link)||!filter_var($link,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($link,PHP_URL_SCHEME)),array('http','https'),true))throw new InvalidArgumentException('系统邮件链接无效。');return $link;}
    public function render($key,$variables=array(),$modules=array(),$configOverride=null){
        $c=$configOverride===null?$this->get($key)['config']:$this->validate($key,$configOverride);
        $values=array('brand'=>isset($this->config['app']['name'])?$this->config['app']['name']:'朝夕','order_no'=>'','product_summary'=>'');foreach($this->variables($key) as $n)if(isset($variables[$n])&&is_scalar($variables[$n]))$values[$n]=(string)$variables[$n];
        foreach($values as $v)if(!preg_match('//u',$v))throw new InvalidArgumentException('邮件动态文字无效。');
        $replace=function($v)use($values){return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/',function($m)use($values){return $values[$m[1]];},$v);};
        $subject=$replace($c['subject']);if(preg_match('/[\x00-\x1f\x7f]/',$subject))throw new InvalidArgumentException('邮件主题无效。');
        $title=$replace($c['title']);$body=$replace($c['body']);$buttonText=$replace($c['button_text']);$esc=function($v){return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');};$extra='';$extraHtml='';$link=null;
        if($key==='password_reset'){
            $link=$this->safeLink(isset($modules['reset_link'])?$modules['reset_link']:'');
            $extra="密码重置备用链接：\n".$link."\n\n链接自申请起 30 分钟内有效，使用后即失效。\n如果不是您本人操作，请忽略此邮件，您的密码不会因此改变。\n请勿将此邮件或重置链接转发给他人。";
        }elseif($key==='customer_order_delivered'){
            if(isset($modules['delivery'])&&is_array($modules['delivery'])){$d=$modules['delivery'];$extra="正式订阅地址（完整复制使用）：\n".$d['subscription']."\n\n开通时间：".$d['opened_at']."\n到期时间：".$d['expires_at']."\n\n使用说明：\n".$d['instructions'];}
            else $extra='请登录网站查看订单详情及交付内容。此邮件不包含交付密钥或支付凭据。';
            $extra.="\n\n交付信息可能含敏感凭据，请勿转发。邮件发送后无法撤回，交付更新以订单详情为准。";
        }
        if($key!=='password_reset'&&!empty($modules['order_link']))$link=$this->safeLink($modules['order_link']);
        $extraHtml=$extra!==''?'<div style="margin-top:24px;padding:16px;background:#f6f3fd;white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-all">'.$esc($extra).'</div>':'';
        $button=$link!==null?'<p style="padding:16px 0"><a href="'.$esc($link).'" style="display:inline-block;padding:14px 28px;background:'.$c['color'].';color:#fff;text-decoration:none;border-radius:8px">'.$esc($c['button_text']).'</a></p>':'';
        $text=$values['brand']."\n\n".$title."\n\n".$body.($extra!==''?"\n\n".$extra:'').($link!==null&&$key!=='password_reset'?"\n\n".$c['button_text'].'：'.$link:'');
        $html='<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;background:#f4f3fa;color:#252338;font-family:Arial,Microsoft YaHei,sans-serif"><table role="presentation" width="100%"><tr><td align="center" style="padding:32px 16px"><table role="presentation" width="560" style="width:100%;max-width:560px"><tr><td style="padding:16px;font-size:20px">'.$esc($values['brand']).'</td></tr><tr><td style="padding:32px 24px;background:#fff;border-top:4px solid '.$c['color'].';border-radius:16px"><h1 style="font-size:24px">'.$esc($title).'</h1><div style="line-height:1.9;overflow-wrap:anywhere">'.nl2br($esc($body)).'</div>'.$button.$extraHtml.'</td></tr><tr><td style="padding:20px;font-size:12px;color:#9791a5">此邮件由 '.$esc($values['brand']).' 自动发送，请勿直接回复。</td></tr></table></td></tr></table></body></html>';
        return array('subject'=>$subject,'body'=>$text,'text'=>$text,'html'=>$html);
    }
    public function preview($key,$config,$variant='vpn'){
        if(!in_array($variant,array('vpn','account'),true))throw new InvalidArgumentException('预览类型无效。');
        $modules=$key==='password_reset'?array('reset_link'=>'https://example.test/password/reset?token=preview-only'):array('order_link'=>'https://example.test/orders/EXAMPLE-0001');
        if($key==='customer_order_delivered'&&$variant==='vpn')$modules['delivery']=array('subscription'=>'https://example.test/subscription?key=fictional&client=demo','opened_at'=>'2026-01-01 12:00:00','expires_at'=>'2099-01-01 12:00:00','instructions'=>"复制完整地址导入客户端。\n此处为虚构示例数据。");
        return $this->render($key,array('brand'=>'示例商店','order_no'=>'EXAMPLE-0001','product_summary'=>$variant==='vpn'?'示例 VPN / 月度方案':'示例账号 / 标准方案'),$modules,$config);
    }
}
