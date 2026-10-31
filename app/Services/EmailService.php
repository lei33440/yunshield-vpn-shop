<?php
class EmailService
{
    private $pdo;
    private $config;
    public function __construct($pdo,$config=array()){$this->pdo=$pdo;$this->config=$config;}
    public function settings(){
        $keys=array('smtp_enabled','smtp_host','smtp_port','smtp_encryption','smtp_username','smtp_password','smtp_from_email','smtp_from_name');
        $s=$this->pdo->prepare('SELECT * FROM system_settings WHERE setting_key IN ('.implode(',',array_fill(0,count($keys),'?')).')');$s->execute($keys);$rows=array();foreach($s as $r)$rows[$r['setting_key']]=$r;
        $password='';if(!empty($rows['smtp_password']['setting_ciphertext'])){try{$password=(new CryptoService($this->config))->decrypt($rows['smtp_password']['setting_ciphertext'],$rows['smtp_password']['setting_nonce'],$rows['smtp_password']['setting_tag']);}catch(Throwable $e){$password='';}}
        return array('enabled'=>isset($rows['smtp_enabled']['setting_value'])&&$rows['smtp_enabled']['setting_value']==='1','host'=>trim(isset($rows['smtp_host']['setting_value'])?$rows['smtp_host']['setting_value']:''),'port'=>(int)(isset($rows['smtp_port']['setting_value'])?$rows['smtp_port']['setting_value']:587),'encryption'=>strtolower(isset($rows['smtp_encryption']['setting_value'])?$rows['smtp_encryption']['setting_value']:'tls'),'username'=>isset($rows['smtp_username']['setting_value'])?$rows['smtp_username']['setting_value']:'','password'=>$password,'from_email'=>isset($rows['smtp_from_email']['setting_value'])?$rows['smtp_from_email']['setting_value']:'','from_name'=>isset($rows['smtp_from_name']['setting_value'])?$rows['smtp_from_name']['setting_value']:'','configured'=>trim(isset($rows['smtp_host']['setting_value'])?$rows['smtp_host']['setting_value']:'')!==''&&filter_var(isset($rows['smtp_from_email']['setting_value'])?$rows['smtp_from_email']['setting_value']:'',FILTER_VALIDATE_EMAIL)&&$password!=='')+(new CustomerEmailService($this->pdo,$this->config))->settings();
    }
    public function saveSettings($input,$adminId=null){
        if(!is_array($input))throw new InvalidArgumentException('SMTP 设置格式无效。');
        $enabled=!empty($input['enabled'])||(!empty($input['smtp_enabled'])&&$input['smtp_enabled']==='1');
        $host=trim(isset($input['host'])?$input['host']:(isset($input['smtp_host'])?$input['smtp_host']:''));$port=(int)(isset($input['port'])?$input['port']:(isset($input['smtp_port'])?$input['smtp_port']:587));$encryption=strtolower(trim(isset($input['encryption'])?$input['encryption']:(isset($input['smtp_encryption'])?$input['smtp_encryption']:'tls')));$username=trim(isset($input['username'])?$input['username']:(isset($input['smtp_username'])?$input['smtp_username']:''));$fromEmail=trim(isset($input['from_email'])?$input['from_email']:(isset($input['smtp_from_email'])?$input['smtp_from_email']:''));$fromName=trim(isset($input['from_name'])?$input['from_name']:(isset($input['smtp_from_name'])?$input['smtp_from_name']:''));$password=isset($input['password'])?(string)$input['password']:(isset($input['smtp_password'])?(string)$input['smtp_password']:'');
        if($host===''||strlen($host)>255||$port<1||$port>65535||!in_array($encryption,array('none','tls','ssl'),true)||!filter_var($fromEmail,FILTER_VALIDATE_EMAIL)||strlen($fromName)>200)throw new InvalidArgumentException('SMTP 设置无效。');
        $now=date('Y-m-d H:i:s');$values=array('smtp_enabled'=>$enabled?'1':'0','smtp_host'=>$host,'smtp_port'=>(string)$port,'smtp_encryption'=>$encryption,'smtp_username'=>$username,'smtp_from_email'=>$fromEmail,'smtp_from_name'=>$fromName);$this->pdo->beginTransaction();try{foreach($values as $key=>$value)$this->pdo->prepare('INSERT INTO system_settings(setting_key,setting_value,updated_by,updated_at) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)')->execute(array($key,$value,$adminId,$now));if($password!==''){$c=(new CryptoService($this->config))->encrypt($password);$this->pdo->prepare('INSERT INTO system_settings(setting_key,setting_value,setting_ciphertext,setting_nonce,setting_tag,is_secret,updated_by,updated_at) VALUES("smtp_password","",?,?,?,1,?,?) ON DUPLICATE KEY UPDATE setting_value="",setting_ciphertext=VALUES(setting_ciphertext),setting_nonce=VALUES(setting_nonce),setting_tag=VALUES(setting_tag),is_secret=1,updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)')->execute(array($c[0],$c[1],$c[2],$adminId,$now));}(new CustomerEmailService($this->pdo,$this->config))->saveSettings($input,$adminId);$this->pdo->commit();}catch(Exception $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}return true;
    }
    public function sendTest($recipient){$cfg=$this->settings();if(!$cfg['enabled'])throw new RuntimeException('SMTP 未启用。');if($cfg['host']===''||!filter_var($cfg['from_email'],FILTER_VALIDATE_EMAIL)||$cfg['password']==='')throw new RuntimeException('SMTP 尚未完整配置。');return $this->send($recipient,'SMTP 测试邮件','这是 SMTP 配置测试邮件。');}
    public static function passwordResetMessage($brand,$link){
        if(!is_string($link)||!filter_var($link,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($link,PHP_URL_SCHEME)),array('http','https'),true))throw new InvalidArgumentException('密码重置链接无效。');
        $brand=trim(str_replace(array("\r","\n"),' ',(string)$brand));if($brand==='')$brand='账户安全中心';
        $name=htmlspecialchars($brand,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$url=htmlspecialchars($link,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $body=$brand." · 密码重置\n\n我们收到了您的密码重置申请。请打开以下链接设置新密码：\n".$link."\n\n链接自申请起 30 分钟内有效，使用后即失效。\n如果不是您本人操作，请忽略此邮件，您的密码不会因此改变。\n请勿将此邮件或重置链接转发给他人。";
        $html='<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>重置密码 · '.$name.'</title></head>
<body style="margin:0;padding:0;background-color:#f4f3fa;color:#252338;font-family:Arial,\'Microsoft YaHei\',sans-serif;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">重置您的密码。链接自申请起 30 分钟内有效，请勿向他人分享。</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#f4f3fa" style="width:100%;background-color:#f4f3fa;"><tr><td align="center" style="padding:32px 16px;">
<table role="presentation" width="560" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:560px;">
<tr><td style="padding:0 8px 20px;"><span style="font-size:20px;font-weight:700;letter-spacing:1px;color:#6554c0;">'.$name.'</span><span style="padding-left:12px;font-size:12px;color:#9590a6;">账户安全中心</span></td></tr>
<tr><td bgcolor="#ffffff" style="background-color:#ffffff;border:1px solid #e8e4f2;border-top:4px solid #7762d7;border-radius:16px;padding:32px 24px;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
<tr><td style="padding-bottom:12px;font-size:11px;font-weight:700;letter-spacing:2px;color:#8c7bbd;">PASSWORD RESET</td></tr>
<tr><td style="padding-bottom:16px;font-size:28px;font-weight:700;line-height:1.4;color:#252338;">重置您的密码</td></tr>
<tr><td style="font-size:14px;line-height:1.9;color:#716b82;">您好，我们收到了您的密码重置申请。<br>点击下方按钮，即可为您的账户设置新密码。</td></tr>
<tr><td align="center" style="padding:28px 0;">
<table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr><td align="center" bgcolor="#7560d2" style="border-radius:9px;background-color:#7560d2;mso-padding-alt:15px 44px;"><a href="'.$url.'" style="display:inline-block;padding:15px 44px;border:1px solid #7560d2;border-radius:9px;color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;line-height:1.4;">重置密码</a></td></tr></table>
</td></tr>
<tr><td bgcolor="#f6f3fd" style="padding:14px 16px;border-radius:8px;background-color:#f6f3fd;font-size:13px;line-height:1.8;color:#75678e;">链接自申请起 <strong style="color:#6554b1;">30 分钟内有效</strong>，使用后即失效。<br>若链接已过期，请返回网站重新申请。</td></tr>
<tr><td style="padding-top:24px;font-size:12px;line-height:1.8;color:#9791a5;">如果按钮无法点击，请复制以下完整链接到浏览器打开：</td></tr>
<tr><td style="padding:8px 0 24px;font-size:12px;line-height:1.8;word-break:break-all;overflow-wrap:anywhere;"><a href="'.$url.'" style="color:#7d6bb0;text-decoration:underline;word-break:break-all;overflow-wrap:anywhere;">'.$url.'</a></td></tr>
<tr><td style="border-top:1px solid #efecf5;padding-top:20px;font-size:12px;line-height:1.9;color:#928b9e;"><strong style="color:#716a7e;">不是您本人操作？</strong><br>请忽略此邮件，您的密码不会因此改变。为保障账户安全，请勿将此邮件或重置链接转发给他人。</td></tr>
</table></td></tr>
<tr><td align="center" style="padding:20px 12px 0;font-size:11px;line-height:1.9;color:#a39caf;">此邮件由 '.$name.' 自动发送，请勿直接回复。</td></tr>
</table></td></tr></table></body></html>';
        return array('subject'=>$brand.' · 密码重置','body'=>$body,'html'=>$html);
    }
    /** RFC 2047：每段保持完整UTF-8字符，续行仅由本方法添加，拒绝原始控制字符。 */
    private function encodedHeader($value){
        $value=(string)$value;
        if(preg_match('/[\x00-\x1f\x7f]/',$value)||!preg_match('//u',$value))throw new InvalidArgumentException('邮件头文字无效。');
        // 42字节最多编码为56字符，加包装68字符；Subject首行最多77字符。
        $chunks=array();$chunk='';
        foreach(preg_split('//u',$value,-1,PREG_SPLIT_NO_EMPTY) as $character){
            if(strlen($chunk)+strlen($character)>42){$chunks[]=$chunk;$chunk='';}
            $chunk.=$character;
        }
        if($chunk!==''||!$chunks)$chunks[]=$chunk;
        $words=array();foreach($chunks as $part)$words[]='=?UTF-8?B?'.base64_encode($part).'?=';
        return implode("\r\n ",$words);
    }
    private function message($cfg,$to,$subject,$body,$html=null){
        $name=$cfg['from_name']!==''?$this->encodedHeader($cfg['from_name']):'';
        $headers='From: '.($name?$name.' ':'').'<'.$cfg['from_email'].">\r\nTo: <".$to.">\r\nSubject: ".$this->encodedHeader($subject)."\r\nMIME-Version: 1.0\r\n";
        $body=str_replace("\n","\r\n",str_replace(array("\r\n","\r"),"\n",(string)$body));
        if(is_string($html)&&$html!==''){
            $boundary='=_chaoxi_'.bin2hex(random_bytes(18));
            $headers.='Content-Type: multipart/alternative; boundary="'.$boundary.'"'."\r\n\r\n";
            $content='--'.$boundary."\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($body),76,"\r\n");
            $content.='--'.$boundary."\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($html),76,"\r\n").'--'.$boundary.'--';
        }else{
            $headers.="Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";$content=$body;
        }
        return $headers.preg_replace('/^\./m','..',$content);
    }
    public function send($to,$subject,$body,$html=null){
        $cfg=$this->settings();if(!$cfg['enabled'])return false;if(!filter_var($to,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('收件地址无效。');if($cfg['host']===''||!filter_var($cfg['from_email'],FILTER_VALIDATE_EMAIL))throw new RuntimeException('SMTP 尚未完整配置。');
        $transport=($cfg['encryption']==='ssl'?'ssl://':'').$cfg['host'].':'.$cfg['port'];$errno=0;$err='';$fp=@stream_socket_client($transport,$errno,$err,8,STREAM_CLIENT_CONNECT);if(!$fp)throw new RuntimeException('SMTP 网络连接失败。');stream_set_timeout($fp,8);$this->expect($fp,220);$this->command($fp,'EHLO localhost',250);if($cfg['encryption']==='tls'){$this->command($fp,'STARTTLS',220);if(!stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT))throw new RuntimeException('SMTP TLS 握手失败。');$this->command($fp,'EHLO localhost',250);}if($cfg['username']!==''){$this->command($fp,'AUTH LOGIN',334);$this->command($fp,base64_encode($cfg['username']),334);$this->command($fp,base64_encode($cfg['password']),235);}$this->command($fp,'MAIL FROM:<'.$cfg['from_email'].'>',250);$this->command($fp,'RCPT TO:<'.$to.'>',250);$this->command($fp,'DATA',354);$this->command($fp,$this->message($cfg,$to,$subject,$body,$html)."\r\n.",250);$this->command($fp,'QUIT',221);fclose($fp);return true;
    }
    private function command($fp,$cmd,$code){fwrite($fp,$cmd."\r\n");$this->expect($fp,$code);}
    private function expect($fp,$code){$line='';while(($part=fgets($fp,512))!==false){$line.=$part;if(strlen($part)<4||$part[3]!=='-')break;}if((int)substr($line,0,3)!==$code)throw new RuntimeException('SMTP 响应异常。');}
}
