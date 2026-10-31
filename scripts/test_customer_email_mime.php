<?php
// 完全离线：仅反射调用 MIME 构造方法，不连接数据库、不加载站点配置、不调用 send()。
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/app/Services/EmailService.php';
$method=new ReflectionMethod('EmailService','message');$method->setAccessible(true);
$email=(new ReflectionClass('EmailService'))->newInstanceWithoutConstructor();$failures=0;
function cem_ok($ok,$name){echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;if(!$ok)$GLOBALS['failures']++;}
function cem_decode($field){return preg_replace_callback('/=\?UTF-8\?B\?([^?]*)\?=/i',function($m){return base64_decode($m[1],true);},preg_replace('/(?<=\?=)[ \t\r\n]+(?==\?)/','',$field));}
foreach(array('password reset'=>'Test · 密码重置','600 byte campaign'=>'朝夕 · '.str_repeat('通',200),'long brand campaign'=>str_repeat('品',100).' · '.str_repeat('通',200),'mixed unicode'=>'品牌 · '.str_repeat('通知🙂éA',50)) as $name=>$subject){
 $cfg=array('from_name'=>$name==='long brand campaign'?str_repeat('品牌🙂',80):'朝夕 Test','from_email'=>'sender@example.test');
 $wire=$method->invoke($email,$cfg,'recipient@example.test',$subject,'Offline body','<p>Offline body</p>');
 $headers=explode("\r\n\r\n",$wire,2)[0];$lines=explode("\r\n",$headers);$max=0;foreach($lines as $line)$max=max($max,strlen($line));
 cem_ok($max<=998,$name.' header physical lines <=998 bytes (actual '.$max.')');
 preg_match_all('/=\?UTF-8\?B\?[^?]*\?=/i',$headers,$words);$maxWord=0;$valid=true;foreach($words[0] as $word){$maxWord=max($maxWord,strlen($word));preg_match('/\?B\?([^?]*)\?=/i',$word,$m);$decoded=base64_decode($m[1],true);if($decoded===false||!preg_match('//u',$decoded))$valid=false;}
 cem_ok($maxWord<=75,$name.' encoded words <=75 bytes (actual '.$maxWord.')');cem_ok($valid,$name.' each encoded word is valid complete UTF8');
 preg_match('/(?:^|\r\n)Subject: ([^\r\n]*(?:\r\n[ \t][^\r\n]*)*)/',$headers,$m);cem_ok(isset($m[1])&&cem_decode($m[1])===$subject,$name.' decoded subject bytewise preserved');
 preg_match('/(?:^|\r\n)From: ([^\r\n]*(?:\r\n[ \t][^\r\n]*)*)/',$headers,$from);$unfolded=isset($from[1])?preg_replace('/\r\n[ \t]+/',' ',$from[1]):'';cem_ok(cem_decode($unfolded)===$cfg['from_name'].' <sender@example.test>',$name.' decoded From display name bytewise preserved');
 cem_ok(preg_match('/(?<!\r)\n|\r(?!\n)/',$wire)===0,$name.' MIME only CRLF');
}
foreach(array("bad\r\nBcc: other@example.test","bad\nvalue","bad\0value","bad\x7fvalue","bad\xc0\xaf") as $i=>$bad){
 foreach(array('subject','from_name') as $field){$cfg=array('from_name'=>'Test','from_email'=>'sender@example.test');$subject='Safe';if($field==='subject')$subject=$bad;else $cfg['from_name']=$bad;$rejected=false;try{$method->invoke($email,$cfg,'recipient@example.test',$subject,'Body','<p>Body</p>');}catch(InvalidArgumentException $e){$rejected=true;}cem_ok($rejected,$field.' rejects unsafe control or invalid UTF8 fixture '.$i);}
}
exit($failures?1:0);
