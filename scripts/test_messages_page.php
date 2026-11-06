<?php
// 仅使用内存数据库与隔离会话，不连接业务库、不创建外发消息。
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);require $root.'/app/Core/Router.php';require $root.'/app/Core/Security.php';require $root.'/app/Services/NotificationService.php';require $root.'/app/Core/NotificationPages.php';
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function flash($type,$text){}
function page($title,$body,$config){$GLOBALS['message_body']=$body;}
function redirect($url){throw new RuntimeException('REDIRECT '.$url);}
function post_csrf(){if(!isset($_POST['_csrf'])||!is_string($_POST['_csrf'])||!hash_equals($_SESSION['_csrf'],$_POST['_csrf']))throw new RuntimeException('CSRF');$GLOBALS['csrf_calls']++;}
function message_ok($ok,$label){if(!$ok)throw new RuntimeException('FAIL '.$label);if(!in_array('--html',$_SERVER['argv'],true))echo 'PASS '.$label.PHP_EOL;}
function message_function($source,$name){$tokens=token_get_all($source);$capture=false;$depth=0;$opened=false;$result='';foreach($tokens as $i=>$token){if(!$capture&&is_array($token)&&$token[0]===T_FUNCTION){$j=$i+1;while(isset($tokens[$j])&&is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE)$j++;if(isset($tokens[$j])&&is_array($tokens[$j])&&$tokens[$j][1]===$name)$capture=true;}if(!$capture)continue;$result.=is_array($token)?$token[1]:$token;if($token==='{'){$opened=true;$depth++;}elseif($token==='}'&&--$depth===0&&$opened)return $result;}throw new RuntimeException('函数缺失 '.$name);}
function message_run($get=array()){$_GET=$get;http_response_code(200);$GLOBALS['message_body']='';$GLOBALS['router']->dispatch('GET','/notifications');return $GLOBALS['message_body'];}
set_error_handler(function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$pdo=new PDO('sqlite::memory:',null,null,array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,display_name TEXT,email TEXT,password_changed_at TEXT);CREATE TABLE orders(id INTEGER PRIMARY KEY,user_id INTEGER,order_no TEXT);CREATE TABLE notifications(id INTEGER PRIMARY KEY,user_id INTEGER,type TEXT,title TEXT,content TEXT,order_no TEXT,target_type TEXT,target_id INTEGER,read_at TEXT,created_at TEXT,dedupe_key TEXT)');
$pdo->exec("INSERT INTO users VALUES(1,'云间旅人','user@example.test',NULL),(2,'其他用户','other@example.test',NULL);INSERT INTO orders VALUES(1,1,'XY20261106001'),(99,2,'PRIVATE-ORDER')");
$insert=$pdo->prepare('INSERT INTO notifications VALUES(?,1,?,?,?,?,?,?,?,?,?)');$now=time()-60;
$fixtures=array(array('order_delivered','正式订阅已交付','订阅已完成交付，请进入订单查看订阅链接与有效期。','XY20261106001','order',1,null),array('ticket_update','售后工单有新回复','你提交的订阅配置问题已有回复，请进入工单查看。',null,'ticket',2,null),array('order_refunded','余额退款已入账','相关订单退款已退回余额，可查看订单处理进度。','XY20261106001','order',1,'2026-01-01'),array('group_released','拼团已成功','订单已进入待发货状态，请等待管理员人工交付。','XY20261106001','order',1,'2026-01-01'),array('feedback_review','反馈建议审核完成','你的建议已通过审核，请查看反馈处理进度。',null,'feedback',3,'2026-01-01'),array('system','账户提醒','请定期检查账户安全设置。',null,null,null,'2026-01-01'));
foreach($fixtures as $i=>$r)$insert->execute(array_merge(array($i+1),$r,array(date('Y-m-d H:i:s',$now-$i*86400),'PRIVATE-KEY-'.$i)));
$pdo->exec("INSERT INTO notifications VALUES(99,2,'system','PRIVATE-OTHER','PRIVATE-CONTENT',NULL,NULL,NULL,NULL,'".date('Y-m-d H:i:s',$now)."','PRIVATE-KEY')");
$_SESSION=array('user_id'=>1,'user_password_changed_at'=>null,'_csrf'=>'isolated-message-token');$config=array('app'=>array('name'=>'小云店'));$router=new Router();register_notification_routes($router,$pdo,$config);$GLOBALS['csrf_calls']=0;
$source=file_get_contents($root.'/public/index.php');foreach(array('user_nav_icon','home_outline_icon','account_overview_shell') as $name)eval(str_replace('__DIR__',var_export($root.'/public',true),message_function($source,$name)));
$before=$pdo->query('SELECT total_changes()')->fetchColumn();$body=message_run();
message_ok(strpos($body,'MESSAGE CENTER')!==false&&substr_count($body,'class="mn-message')===6,'默认消息中心新布局与六条真实消息');
message_ok(strpos($body,'共 6 条站内消息 · 2 条未读')!==false&&substr_count($body,'mn-message is-unread')===2,'本人真实消息总数与未读状态');
message_ok(strpos($body,'PRIVATE-')===false&&strpos($body,'href="/orders/XY20261106001"')!==false&&strpos($body,'href="/tickets/2"')!==false&&strpos($body,'href="/feedback/3"')!==false,'真实详情入口且不泄露他人与内部去重键');
message_ok(strpos($body,'action="/notifications/read-all"')!==false&&strpos($body,'action="/notifications/1/read"')!==false&&substr_count($body,'name="_csrf"')===3,'保留全部与单条已读的CSRF表单');
message_ok($before==$pdo->query('SELECT total_changes()')->fetchColumn(),'GET渲染不写数据库或自动标记已读');
$doc=new DOMDocument();$doc->loadHTML('<?xml encoding="UTF-8">'.$body,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$xp=new DOMXPath($doc);message_ok($xp->query('//h1')->length===1&&$xp->query('//nav[@aria-label="已读状态"]/a[@aria-current="page"]')->length===1,'唯一主标题和当前状态标签');
ob_start();account_overview_shell('消息中心',$body,'',2,$pdo,$config,'/notifications');$html=ob_get_clean();
message_ok(strpos($html,'account-messages-editorial.css')!==false&&strpos($html,'account-wallet-editorial.css')===false&&strpos($html,'href="/notifications" aria-current="page"')!==false,'消息独立样式与正确侧栏高亮');
message_ok(strpos($html,'action="/logout"')!==false&&strpos($html,'name="_csrf"')!==false,'共享外壳保留退出CSRF');
foreach(array('unread'=>2,'read'=>4) as $state=>$count){$result=message_run(array('read'=>$state));message_ok(substr_count($result,'class="mn-message')===$count&&strpos($result,'共 6 条站内消息 · 2 条未读')!==false,'服务端已读筛选且全局统计不变 '.$state);}
foreach(array('order_delivered','ticket_update','order_refunded','group_released','feedback_review','system') as $type){$result=message_run(array('type'=>$type));message_ok(substr_count($result,'class="mn-message')===1,'真实类型筛选 '.$type);}
$result=message_run(array('type'=>'ticket_update','read'=>'unread','range'=>'all'));message_ok(substr_count($result,'class="mn-message')===1&&strpos($result,'name="range" value="all"')!==false&&strpos($result,'value="ticket_update" selected')!==false&&strpos($result,'name="read" value="unread"')!==false,'类型状态时间交集且POST保留筛选');
$insert->execute(array(7,'payment_confirmed','OLD-MESSAGE','较早的付款消息',null,null,null,null,date('Y-m-d H:i:s',$now-45*86400),'PRIVATE-OLD'));
message_ok(strpos(message_run(),'OLD-MESSAGE')===false&&strpos(message_run(array('range'=>'90')),'OLD-MESSAGE')!==false&&strpos(message_run(array('range'=>'all')),'OLD-MESSAGE')!==false,'最近30天90天及全部时间实际生效');
$pdo->exec("UPDATE notifications SET order_no='PRIVATE-ORDER',target_id=99 WHERE id=1");$result=message_run();message_ok(strpos($result,'PRIVATE-ORDER')===false&&strpos($result,'/orders/PRIVATE')===false,'异常跨用户订单关联不泄露订单号');
$pdo->exec("UPDATE notifications SET title='<script>标题</script>',content='<img src=x onerror=alert(1)>' WHERE id=1");$result=message_run();message_ok(strpos($result,'&lt;script&gt;标题')!==false&&strpos($result,'&lt;img src=x onerror=alert(1)&gt;')!==false&&strpos($result,'<script>标题')===false,'消息标题正文安全转义');
foreach(array(array('read'=>array('x')),array('read'=>'invalid'),array('type'=>'unknown'),array('type'=>array('system')),array('range'=>'365'),array('range'=>array('all')),array('page'=>'0'),array('page'=>'1e2'),array('page'=>array('1'))) as $bad){message_run($bad);message_ok(http_response_code()===400,'非法消息参数400');}
for($i=8;$i<=32;$i++)$insert->execute(array($i,'system','分页消息 '.$i,'正文',null,null,null,null,date('Y-m-d H:i:s',$now),'PRIVATE-PAGE-'.$i));
$result=message_run(array('range'=>'all'));message_ok(substr_count($result,'class="mn-message')===20&&strpos($result,'rel="next"')!==false&&strpos($result,'共 32 条')!==false,'每页二十条与真实总数');
$result=message_run(array('range'=>'all','page'=>'2'));message_ok(substr_count($result,'class="mn-message')===12&&strpos($result,'第 2 / 2 页')!==false,'第二页剩余消息');
$result=message_run(array('range'=>'all','page'=>'99999999'));message_ok(strpos($result,'第 2 / 2 页')!==false,'越界页码收敛末页');
$result=message_run(array('type'=>'system','range'=>'all'));message_ok(strpos($result,'id="message-32"')<strpos($result,'id="message-31"'),'相同时间按ID稳定倒序');
$typeIndex=1000;foreach(notification_page_types() as $type=>$label){$insert->execute(array($typeIndex++,$type,'类型覆盖 '.$type,'真实类型正文',null,null,null,null,date('Y-m-d H:i:s',$now),'PRIVATE-TYPE-'.$type));$result=message_run(array('type'=>$type,'range'=>'all'));if(strpos($result,'类型覆盖 '.$type)===false)throw new RuntimeException('FAIL 类型筛选 '.$type);}message_ok(true,'订单交付退款活动返佣等全部已知类型可筛选');
$insert->execute(array(2000,'future_type','未知类型消息','仍然可读的站内通知',null,null,null,null,date('Y-m-d H:i:s',$now),'PRIVATE-UNKNOWN'));$result=message_run();message_ok(strpos($result,'未知类型消息')!==false&&strpos($result,'账户消息')!==false,'未识别类型保留真实消息并安全展示');
$_POST=array('_csrf'=>'bad');$before=$pdo->query('SELECT total_changes()')->fetchColumn();try{$router->dispatch('POST','/notifications/1/read');throw new LogicException('缺少CSRF拦截');}catch(RuntimeException $ex){message_ok($ex->getMessage()==='CSRF'&&$before==$pdo->query('SELECT total_changes()')->fetchColumn(),'CSRF失败禁止写入');}
$_POST=array('_csrf'=>$_SESSION['_csrf'],'read'=>'unread','type'=>'ticket_update','range'=>'all','page'=>'2');try{$router->dispatch('POST','/notifications/99/read');}catch(RuntimeException $ex){message_ok(strpos($ex->getMessage(),'type=ticket_update&page=2&range=all')!==false&&$pdo->query('SELECT read_at FROM notifications WHERE id=99')->fetchColumn()===null,'单条已读限定本人且跳回保留筛选');}
try{$router->dispatch('POST','/notifications/2/read');}catch(RuntimeException $ex){message_ok($pdo->query('SELECT read_at FROM notifications WHERE id=2')->fetchColumn()!==null,'现有单条已读实际生效（仅内存）');}
$_POST=array('_csrf'=>$_SESSION['_csrf'],'read'=>array('bad'),'type'=>array('bad'),'page'=>array('bad'));try{$router->dispatch('POST','/notifications/read-all');}catch(RuntimeException $ex){message_ok($ex->getMessage()==='REDIRECT /notifications?read=&type=&page=1'&&(new NotificationService($pdo))->unreadCount(1)===0&&(new NotificationService($pdo))->unreadCount(2)===1,'全部已读仅本人且畸形跳回参数安全');}
$pdo->exec('DELETE FROM notifications WHERE user_id=1');$result=message_run();message_ok(strpos($result,'暂无匹配的站内消息')!==false&&strpos($result,'共 0 条站内消息 · 0 条未读')!==false&&strpos($result,'type="submit" disabled')!==false,'空列表不填示例并禁用无效全部已读');
$_SESSION=array();try{message_run();throw new LogicException('鉴权未触发');}catch(RuntimeException $ex){message_ok($ex->getMessage()==='REDIRECT /login','消息默认入口保留访客鉴权');}
if(in_array('--html',$_SERVER['argv'],true))echo $html;else echo "MESSAGES PAGE TESTS PASSED\n";
