<?php
// 只使用SQLite内存数据与隔离会话，禁止提交真实工单或回复。
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);require $root.'/app/Core/Router.php';require $root.'/app/Core/Security.php';require $root.'/app/Services/TicketService.php';require $root.'/app/Core/TicketPages.php';
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function page($title,$body,$config){$GLOBALS['ticket_body']=$body;}
function flash($type,$text){}
function redirect($url){throw new RuntimeException('REDIRECT '.$url);}
function ticket_ui_ok($ok,$label){if(!$ok)throw new RuntimeException('FAIL '.$label);if(!in_array('--html',$_SERVER['argv'],true))echo 'PASS '.$label.PHP_EOL;}
function ticket_ui_function($source,$name){$tokens=token_get_all($source);$capture=false;$depth=0;$opened=false;$result='';foreach($tokens as $i=>$token){if(!$capture&&is_array($token)&&$token[0]===T_FUNCTION){$j=$i+1;while(isset($tokens[$j])&&is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE)$j++;if(isset($tokens[$j])&&is_array($tokens[$j])&&$tokens[$j][1]===$name)$capture=true;}if(!$capture)continue;$result.=is_array($token)?$token[1]:$token;if($token==='{'){$opened=true;$depth++;}elseif($token==='}'&&--$depth===0&&$opened)return $result;}throw new RuntimeException('函数缺失 '.$name);}
function ticket_ui_run($get=array()){$_GET=$get;http_response_code(200);$GLOBALS['ticket_body']='';$GLOBALS['router']->dispatch('GET','/tickets');return $GLOBALS['ticket_body'];}
set_error_handler(function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$pdo=new PDO('sqlite::memory:',null,null,array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,display_name TEXT,email TEXT,password_changed_at TEXT);CREATE TABLE orders(id INTEGER PRIMARY KEY,user_id INTEGER,order_no TEXT,product_name_snapshot TEXT);CREATE TABLE support_tickets(id INTEGER PRIMARY KEY,user_id INTEGER,order_id INTEGER,ticket_no TEXT,subject TEXT,status TEXT,updated_at TEXT,created_at TEXT,version_no INTEGER);');
$pdo->exec("INSERT INTO users VALUES(1,'云间旅人','user@example.test',NULL),(2,'其他用户','other@example.test',NULL);INSERT INTO orders VALUES(1,1,'XY20261106001','畅享年包'),(2,1,'XY20261105002','海外账号'),(99,2,'PRIVATE-ORDER','PRIVATE-PRODUCT')");
$insert=$pdo->prepare('INSERT INTO support_tickets VALUES(?,1,?,?,?,?,?,?,1)');$now=time()-60;
$fixtures=array(array(1,'订阅导入后无法使用','waiting_customer'),array(1,'正式交付进度咨询','open'),array(2,'海外账号交付说明咨询','open'),array(null,'macOS客户端配置问题','closed'),array(1,'余额账本记录咨询','closed'));
foreach($fixtures as $i=>$row)$insert->execute(array($i+1,$row[0],'TK2026110600'.($i+1),$row[1],$row[2],date('Y-m-d H:i:s',$now-$i*86400),date('Y-m-d H:i:s',$now-$i*86400)));
$pdo->exec("INSERT INTO support_tickets VALUES(99,2,99,'PRIVATE-TICKET','PRIVATE-SUBJECT','open','".date('Y-m-d H:i:s',$now)."','2026-01-01',1)");
$_SESSION=array('user_id'=>1,'user_password_changed_at'=>null,'_csrf'=>'isolated-ticket-token');$config=array('app'=>array('name'=>'小云店'));$router=new Router();register_ticket_routes($router,$pdo,$config);
$source=file_get_contents($root.'/public/index.php');foreach(array('user_nav_icon','home_outline_icon','account_overview_shell') as $name)eval(str_replace('__DIR__',var_export($root.'/public',true),ticket_ui_function($source,$name)));
$before=$pdo->query('SELECT total_changes()')->fetchColumn();$body=ticket_ui_run();
ticket_ui_ok(strpos($body,'SUPPORT TICKETS')!==false&&substr_count($body,'class="mt-ticket')===5,'默认入口新布局与五张真实工单');
$doc=new DOMDocument();$doc->loadHTML('<?xml encoding="UTF-8">'.$body,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$xp=new DOMXPath($doc);
ticket_ui_ok($xp->query('//h1')->length===1&&$xp->query('//nav[@aria-label="工单状态筛选"]/a[@aria-current="page"]')->length===1,'唯一主标题与当前状态');
foreach(array('open'=>2,'waiting_customer'=>1,'closed'=>2) as $status=>$count){ticket_ui_ok(strpos($body,'<strong>'.$count.'</strong>')!==false,'全局真实状态计数 '.$status);$result=ticket_ui_run(array('status'=>$status));ticket_ui_ok(substr_count($result,'class="mt-ticket')===$count,'真实状态筛选 '.$status);}
ticket_ui_ok(strpos($body,'class="mt-ticket is-waiting"')!==false&&strpos($body,'查看并回复')!==false&&strpos($body,'>未读回复<')===false,'等待回复高亮但不伪造工单未读状态');
ticket_ui_ok(strpos($body,'href="/tickets/1"')!==false&&strpos($body,'href="/orders/XY20261106001"')!==false&&strpos($body,'href="#new-ticket"')!==false,'新建查看回复及本人订单真实入口');
ticket_ui_ok(strpos($body,'PRIVATE-')===false,'不泄露他人工单和订单');
ticket_ui_ok($xp->query('//details[@id="new-ticket"]//form[@method="post"][@action="/tickets"]')->length===1&&strpos($body,'name="_csrf"')!==false&&strpos($body,'name="request_key"')!==false&&strpos($body,'maxlength="5000"')!==false,'新建表单保留CSRF幂等键与内容限制');
ticket_ui_ok($before==$pdo->query('SELECT total_changes()')->fetchColumn(),'GET渲染不写工单消息订单或资金');
ob_start();account_overview_shell('我的工单',$body,'',2,$pdo,$config,'/tickets');$html=ob_get_clean();ticket_ui_ok(strpos($html,'account-tickets-editorial.css')!==false&&strpos($html,'account-messages-editorial.css')===false&&strpos($html,'href="/tickets" aria-current="page"')!==false,'独立CSS与正确个人中心侧栏');
ticket_ui_ok(strpos($html,'action="/logout"')!==false&&strpos($html,'name="_csrf"')!==false,'共享外壳保留退出CSRF');
$result=ticket_ui_run(array('q'=>'tk20261106001','status'=>'waiting_customer','sort'=>'updated_asc'));ticket_ui_ok(substr_count($result,'class="mt-ticket')===1&&strpos($result,'name="status" value="waiting_customer"')!==false&&strpos($result,'value="updated_asc" selected')!==false,'工单编号搜索与状态排序交集保留');
ticket_ui_ok(substr_count(ticket_ui_run(array('q'=>'海外账号')),'class="mt-ticket')===1,'按真实标题搜索');
foreach(array('PRIVATE-TICKET','%','_') as $q)ticket_ui_ok(strpos(ticket_ui_run(array('q'=>$q)),'暂无匹配的工单')!==false,'搜索隔离他人与字面通配符 '.$q);
$result=ticket_ui_run(array('sort'=>'updated_asc'));ticket_ui_ok(strpos($result,'href="/tickets/5"')<strpos($result,'href="/tickets/1"'),'最早更新排序真实生效');
foreach(array(array('q'=>array('x')),array('q'=>"\xff"),array('q'=>str_repeat('长',101)),array('status'=>'unknown'),array('status'=>array('open')),array('sort'=>'invalid'),array('sort'=>array('updated_desc')),array('page'=>'0'),array('page'=>'1e2'),array('page'=>array('1'))) as $bad){ticket_ui_run($bad);ticket_ui_ok(http_response_code()===400,'异常工单筛选400');}
$result=ticket_ui_run(array('q'=>'"><script>'));ticket_ui_ok(strpos($result,'&quot;&gt;&lt;script&gt;')!==false&&strpos($result,'<script>"')===false,'搜索输入安全转义');
$pdo->exec("UPDATE support_tickets SET subject='<script>标题</script>',ticket_no='<b>编号</b>',order_id=99 WHERE id=1");$result=ticket_ui_run();ticket_ui_ok(strpos($result,'&lt;script&gt;标题')!==false&&strpos($result,'&lt;b&gt;编号')!==false&&strpos($result,'PRIVATE-ORDER')===false,'标题编号转义及异常跨用户关联不泄露');
$result=ticket_ui_run(array('order_id'=>'1'));ticket_ui_ok(strpos($result,'id="new-ticket" open')!==false&&strpos($result,'value="1" selected')!==false,'订单入口带入本人订单并展开表单');
$result=ticket_ui_run(array('order_id'=>'99'));ticket_ui_ok(strpos($result,'value="99" selected')===false&&strpos($result,'PRIVATE-ORDER')===false,'不能预选他人订单');
for($i=3;$i<=60;$i++)$pdo->exec("INSERT INTO orders VALUES(".(100+$i).",1,'EXTRA-".$i."','旧订单测试')");$result=ticket_ui_run(array('order_id'=>'1'));ticket_ui_ok(strpos($result,'value="1" selected')!==false,'超过最近五十条仍可带入本人旧订单');
$_SESSION['_ticket_create'][1]=array('subject'=>'<script>失败草稿</script>','body'=>'<img src=x onerror=alert(1)>','order_id'=>'2');$result=ticket_ui_run();ticket_ui_ok(strpos($result,'id="new-ticket" open')!==false&&strpos($result,'&lt;script&gt;失败草稿')!==false&&strpos($result,'&lt;img src=x onerror=alert(1)&gt;')!==false&&strpos($result,'value="2" selected')!==false,'失败草稿安全回显并展开');unset($_SESSION['_ticket_create']);
for($i=6;$i<=27;$i++)$insert->execute(array($i,null,'TKPAGE'.$i,'分页工单 '.$i,'open',date('Y-m-d H:i:s',$now),date('Y-m-d H:i:s',$now)));
$result=ticket_ui_run();ticket_ui_ok(substr_count($result,'class="mt-ticket')===20&&strpos($result,'共 27 条')!==false&&strpos($result,'rel="next"')!==false,'每页二十条及真实总数');
$result=ticket_ui_run(array('page'=>'2'));ticket_ui_ok(substr_count($result,'class="mt-ticket')===7&&strpos($result,'第 2 / 2 页')!==false,'第二页剩余工单');
$result=ticket_ui_run(array('page'=>'99999999'));ticket_ui_ok(strpos($result,'第 2 / 2 页')!==false,'越界页码收敛末页');
$result=ticket_ui_run();ticket_ui_ok(strpos($result,'href="/tickets/27"')<strpos($result,'href="/tickets/26"'),'同更新时间按ID稳定排序');
$pdo->exec('DELETE FROM support_tickets WHERE user_id=1');$result=ticket_ui_run();ticket_ui_ok(strpos($result,'暂无匹配的工单')!==false&&strpos($result,'<strong>0</strong>')!==false&&strpos($result,'PRIVATE-')===false,'空列表不伪造工单');
$_SESSION=array();try{ticket_ui_run();throw new LogicException('缺少鉴权');}catch(RuntimeException $ex){ticket_ui_ok($ex->getMessage()==='REDIRECT /login','默认工单入口保留访客鉴权');}
if(in_array('--html',$_SERVER['argv'],true))echo $html;else echo "TICKETS PAGE TESTS PASSED\n";
