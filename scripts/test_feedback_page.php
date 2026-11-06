<?php
// 内存数据库只读页面回归，不提交反馈，不审核奖励，不连接业务库。
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);require $root.'/app/Core/Router.php';require $root.'/app/Core/Security.php';require $root.'/app/Services/FeedbackService.php';require $root.'/app/Services/WalletService.php';require $root.'/app/Core/FeedbackPages.php';
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function flash($type,$text){}
function page($title,$body,$config){$GLOBALS['feedback_body']=$body;}
function redirect($url){throw new RuntimeException('REDIRECT '.$url);}
function feedback_ui_ok($ok,$label){if(!$ok)throw new RuntimeException('FAIL '.$label);if(!in_array('--html',$_SERVER['argv'],true))echo 'PASS '.$label.PHP_EOL;}
function feedback_ui_function($source,$name){$tokens=token_get_all($source);$capture=false;$depth=0;$opened=false;$result='';foreach($tokens as $i=>$token){if(!$capture&&is_array($token)&&$token[0]===T_FUNCTION){$j=$i+1;while(isset($tokens[$j])&&is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE)$j++;if(isset($tokens[$j])&&is_array($tokens[$j])&&$tokens[$j][1]===$name)$capture=true;}if(!$capture)continue;$result.=is_array($token)?$token[1]:$token;if($token==='{'){$opened=true;$depth++;}elseif($token==='}'&&--$depth===0&&$opened)return $result;}throw new RuntimeException('函数缺失 '.$name);}
function feedback_ui_run($get=array()){$_GET=$get;http_response_code(200);$GLOBALS['feedback_body']='';$GLOBALS['router']->dispatch('GET','/feedback');return $GLOBALS['feedback_body'];}
set_error_handler(function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$pdo=new PDO('sqlite::memory:',null,null,array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,display_name TEXT,email TEXT,password_changed_at TEXT);CREATE TABLE feedback_submissions(id INTEGER PRIMARY KEY,user_id INTEGER,feedback_no TEXT,type TEXT,subject TEXT,body TEXT,status TEXT,reward_cents INTEGER,reward_transaction_id INTEGER,admin_note TEXT,created_at TEXT,progress_status TEXT,submission_key TEXT,reviewed_by INTEGER);CREATE TABLE wallet_transactions(id INTEGER PRIMARY KEY,user_id INTEGER,type TEXT,amount_cents INTEGER);');
$pdo->exec("INSERT INTO users VALUES(1,'云间旅人','user@example.test',NULL),(2,'其他用户','other@example.test',NULL);INSERT INTO wallet_transactions VALUES(1,1,'feedback_reward',1000),(99,2,'feedback_reward',99999)");
$insert=$pdo->prepare('INSERT INTO feedback_submissions VALUES(?,1,?,?,?,?,?,?,?,?,?,?,?,1)');$now=time()-60;
$fixtures=array(array('suggestion','建议在订阅列表增加到期筛选','希望更方便地找到即将到期的订阅。','pending',0,null,'','received'),array('suggestion','优化客户端安装教程的步骤说明','增加图文配置说明。','rewarded',1000,1,'建议已采纳，将补充图文配置步骤。','planned'),array('bug','客户端页面显示问题','部分文字显示不完整。','rejected',0,null,'暂未复现，请补充说明。','in_progress'));
foreach($fixtures as $i=>$r)$insert->execute(array($i+1,'FK2026110600'.($i+1),$r[0],$r[1],$r[2],$r[3],$r[4],$r[5],$r[6],date('Y-m-d H:i:s',$now-$i*86400),$r[7],'PRIVATE-KEY-'.$i));
$pdo->exec("INSERT INTO feedback_submissions VALUES(99,2,'PRIVATE-FEEDBACK','bug','PRIVATE-SUBJECT','PRIVATE-BODY','rewarded',99999,99,'PRIVATE-NOTE','2026-01-01','completed','PRIVATE-KEY',99)");
$_SESSION=array('user_id'=>1,'user_password_changed_at'=>null,'_csrf'=>'isolated-feedback-token');$config=array('app'=>array('name'=>'小云店'));$router=new Router();register_feedback_routes($router,$pdo,$config);
$source=file_get_contents($root.'/public/index.php');foreach(array('user_nav_icon','home_outline_icon','account_overview_shell') as $name)eval(str_replace('__DIR__',var_export($root.'/public',true),feedback_ui_function($source,$name)));
$before=$pdo->query('SELECT total_changes()')->fetchColumn();$body=feedback_ui_run();
feedback_ui_ok(strpos($body,'FEEDBACK &amp; SUGGESTIONS')!==false&&substr_count($body,'class="mf-feedback"')===3,'默认入口新布局与三条真实反馈');
feedback_ui_ok(strpos($body,'全部 (3)')!==false&&strpos($body,'待审核 (1)')!==false&&strpos($body,'已奖励 (1)')!==false&&strpos($body,'已驳回 (1)')!==false,'本人反馈真实状态计数');
feedback_ui_ok(strpos($body,'PRIVATE-')===false,'不泄露他人反馈及内部提交令牌');
feedback_ui_ok(strpos($body,'奖励 ¥10.00')!==false&&strpos($body,'已入账户余额。')!==false&&strpos($body,'/wallet?type=feedback_reward&amp;range=all')!==false,'真实整数奖励与已核对本人流水入口');
feedback_ui_ok(strpos($body,'已纳入计划（独立于审核状态）')!==false&&strpos($body,'处理中（独立于审核状态）')!==false,'奖励与驳回不替代真实独立处理进度');
feedback_ui_ok(strpos($body,'href="/feedback/1"')!==false&&strpos($body,'href="/feedback/2"')!==false&&strpos($body,'href="/tickets#new-ticket"')!==false,'真实详情与售后入口');
$doc=new DOMDocument();$doc->loadHTML('<?xml encoding="UTF-8">'.$body,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$xp=new DOMXPath($doc);
feedback_ui_ok($xp->query('//h1')->length===1&&$xp->query('//nav[@aria-label="反馈状态筛选"]/a[@aria-current="page"]')->length===1,'唯一主标题与筛选选中态');
feedback_ui_ok($xp->query('//input[@type="radio"][@name="type"]')->length===2&&$xp->query('//input[@name="type"][@value="suggestion"][@checked]')->length===1,'只展示后端支持的两类真实反馈');
feedback_ui_ok($xp->query('//form[@method="post"][@action="/feedback"]')->length===1&&strpos($body,'name="_csrf"')!==false&&strpos($body,'name="submission_key"')!==false&&strpos($body,'maxlength="5000"')!==false,'表单保留CSRF提交令牌与长度限制');
preg_match('/name="submission_key" value="([a-f0-9]+)"/',$body,$m);feedback_ui_ok(isset($_SESSION['_feedback_submission_keys'][$m[1]]),'表单令牌真实登记会话');
feedback_ui_ok($before==$pdo->query('SELECT total_changes()')->fetchColumn(),'GET渲染不写反馈账本或奖励');
ob_start();account_overview_shell('反馈建议',$body,'',2,$pdo,$config,'/feedback');$html=ob_get_clean();feedback_ui_ok(strpos($html,'account-feedback-editorial.css')!==false&&strpos($html,'account-tickets-editorial.css')===false&&strpos($html,'href="/feedback" aria-current="page"')!==false,'独立CSS与反馈侧栏高亮');
feedback_ui_ok(strpos($html,'action="/logout"')!==false&&strpos($html,'name="_csrf"')!==false,'共享外壳保留退出CSRF');
foreach(array('pending','rewarded','rejected') as $status){$r=feedback_ui_run(array('status'=>$status));feedback_ui_ok(substr_count($r,'class="mf-feedback"')===1&&strpos($r,'value="'.$status.'" selected')!==false&&strpos($r,'全部 (3)')!==false,'服务端状态筛选且保留全局计数 '.$status);}
foreach(array(array('status'=>'unknown'),array('status'=>array('pending')),array('page'=>'0'),array('page'=>'1e2'),array('page'=>array('1'))) as $bad){feedback_ui_run($bad);feedback_ui_ok(http_response_code()===400,'异常反馈筛选400');}
$_SESSION['_feedback_old']=array('type'=>'bug','subject'=>'<script>草稿</script>','body'=>'<img src=x onerror=alert(1)>');$r=feedback_ui_run();feedback_ui_ok(strpos($r,'value="bug" checked')!==false&&strpos($r,'&lt;script&gt;草稿')!==false&&strpos($r,'&lt;img src=x onerror=alert(1)&gt;')!==false&&!isset($_SESSION['_feedback_old']),'失败草稿安全回显并维持原消费行为');
$pdo->exec("UPDATE feedback_submissions SET subject='<script>标题</script>',body='<img src=x>',admin_note='<b>审核说明</b>',feedback_no='<b>编号</b>' WHERE id=2");$r=feedback_ui_run();feedback_ui_ok(strpos($r,'&lt;script&gt;标题')!==false&&strpos($r,'&lt;b&gt;审核说明')!==false&&strpos($r,'&lt;b&gt;编号')!==false&&strpos($r,'<script>标题')===false,'反馈标题编号与审核说明安全转义');
$pdo->exec('UPDATE feedback_submissions SET reward_transaction_id=99 WHERE id=2');$r=feedback_ui_run(array('status'=>'rewarded'));feedback_ui_ok(strpos($r,'查看奖励流水')===false&&strpos($r,'已入账户余额。')===false,'不把他人奖励流水当成本人入账');
$pdo->exec('UPDATE feedback_submissions SET reward_transaction_id=1,reward_cents=2000 WHERE id=2');$r=feedback_ui_run(array('status'=>'rewarded'));feedback_ui_ok(strpos($r,'查看奖励流水')===false,'奖励与流水金额不匹配不冒充已入账');
$pdo->exec('UPDATE feedback_submissions SET reward_cents=1000 WHERE id=2');
for($i=4;$i<=26;$i++)$insert->execute(array($i,'FKPAGE'.$i,'suggestion','分页反馈 '.$i,'正文','pending',0,null,'',date('Y-m-d H:i:s',$now),'received','PRIVATE-PAGE-'.$i));
$r=feedback_ui_run();feedback_ui_ok(substr_count($r,'class="mf-feedback"')===20&&strpos($r,'共 26 条')!==false&&strpos($r,'rel="next"')!==false,'每页二十条与真实总数');
$r=feedback_ui_run(array('page'=>'2'));feedback_ui_ok(substr_count($r,'class="mf-feedback"')===6&&strpos($r,'第 2 / 2 页')!==false,'第二页剩余反馈');
$r=feedback_ui_run(array('page'=>'99999999'));feedback_ui_ok(strpos($r,'第 2 / 2 页')!==false,'越界页码收敛末页');
$r=feedback_ui_run(array('status'=>'pending'));feedback_ui_ok(strpos($r,'status=pending&amp;page=2')!==false&&strpos($r,'href="/feedback/26"')<strpos($r,'href="/feedback/25"'),'分页保留状态且按ID稳定倒序');
feedback_ui_ok(count($_SESSION['_feedback_submission_keys'])<=40,'多次渲染提交令牌保持原四十个上限');
$pdo->exec('DELETE FROM feedback_submissions WHERE user_id=1');$r=feedback_ui_run();feedback_ui_ok(strpos($r,'暂无匹配的反馈记录')!==false&&strpos($r,'全部 (0)')!==false&&strpos($r,'PRIVATE-')===false,'空反馈列表不填示例');
$_SESSION=array();try{feedback_ui_run();throw new LogicException('缺少鉴权');}catch(RuntimeException $ex){feedback_ui_ok($ex->getMessage()==='REDIRECT /login','默认反馈入口保留访客鉴权');}
if(in_array('--html',$_SERVER['argv'],true))echo $html;else echo "FEEDBACK PAGE TESTS PASSED\n";
