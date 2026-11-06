<?php
// 仅内存数据库与隔离会话，执行真实余额入口，不连接业务库或执行资金服务写方法。
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);require $root.'/app/Core/Router.php';require $root.'/app/Core/Security.php';require $root.'/app/Services/WalletService.php';
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function page($title,$body,$config){$GLOBALS['wallet_body']=$body;}
function redirect($url){throw new RuntimeException('REDIRECT '.$url);}
function flash($type,$text){}
function wallet_ok($ok,$label){if(!$ok)throw new RuntimeException('FAIL '.$label);if(!in_array('--html',$_SERVER['argv'],true))echo 'PASS '.$label.PHP_EOL;}
function wallet_function($source,$name){$tokens=token_get_all($source);$capture=false;$depth=0;$opened=false;$result='';foreach($tokens as $i=>$token){if(!$capture&&is_array($token)&&$token[0]===T_FUNCTION){$j=$i+1;while(isset($tokens[$j])&&is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE)$j++;if(isset($tokens[$j])&&is_array($tokens[$j])&&$tokens[$j][1]===$name)$capture=true;}if(!$capture)continue;$result.=is_array($token)?$token[1]:$token;if($token==='{'){$opened=true;$depth++;}elseif($token==='}'&&--$depth===0&&$opened)return $result;}throw new RuntimeException('函数缺失 '.$name);}
function wallet_run($get=array()){$_GET=$get;http_response_code(200);$GLOBALS['wallet_body']='';$GLOBALS['router']->dispatch('GET','/wallet');return $GLOBALS['wallet_body'];}
set_error_handler(function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$pdo=new PDO('sqlite::memory:',null,null,array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,display_name TEXT,email TEXT,password_changed_at TEXT,balance_cents INTEGER);CREATE TABLE orders(id INTEGER PRIMARY KEY,user_id INTEGER,order_no TEXT);CREATE TABLE wallet_transactions(id INTEGER PRIMARY KEY,user_id INTEGER,amount_cents INTEGER,balance_before_cents INTEGER,balance_after_cents INTEGER,type TEXT,order_id INTEGER,reason TEXT,idempotency_key TEXT,created_at TEXT);CREATE TABLE feedback_submissions(id INTEGER PRIMARY KEY,user_id INTEGER,feedback_no TEXT,reward_transaction_id INTEGER,UNIQUE(user_id,reward_transaction_id))');
$pdo->exec("INSERT INTO users VALUES(1,'云间旅人','user@example.test',NULL,6800),(2,'他人','other@example.test',NULL,999999);INSERT INTO orders VALUES(1,1,'XY20261030001'),(2,1,'XY20261104001'),(99,2,'PRIVATE-ORDER');INSERT INTO feedback_submissions VALUES(1,1,'FK20261106001',6),(2,2,'PRIVATE-FEEDBACK',8)");
$insert=$pdo->prepare('INSERT INTO wallet_transactions VALUES(?,1,?,?,?,?,?,?,?,?)');$now=time()-60;
$fixtures=array(array(5000,0,5000,'admin_adjust',null),array(1800,5000,6800,'referral_credit',99),array(6800,6800,13600,'order_refund',1),array(-6800,13600,6800,'order_payment',2),array(-1000,6800,5800,'referral_reversal',99),array(1000,5800,6800,'feedback_reward',null));
foreach($fixtures as $i=>$row){$id=$i+1;$insert->execute(array($id,$row[0],$row[1],$row[2],$row[3],$row[4],'<script>PRIVATE-REASON</script>','PRIVATE-KEY-'.$id,date('Y-m-d H:i:s',$now-(5-$i)*86400)));}
$pdo->exec("INSERT INTO wallet_transactions VALUES(99,2,999999,0,999999,'admin_adjust',99,'PRIVATE-OTHER','PRIVATE-OTHER-KEY','".date('Y-m-d H:i:s',$now)."')");
$_SESSION=array('user_id'=>1,'user_password_changed_at'=>null,'_csrf'=>'isolated-test-token');$config=array('app'=>array('name'=>'小云店'));$router=new Router();$source=file_get_contents($root.'/public/index.php');
foreach(array('account_wallet_page','user_nav_icon','home_outline_icon','account_overview_shell') as $name)eval(str_replace('__DIR__',var_export($root.'/public',true),wallet_function($source,$name)));
$start=strpos($source,"\$router->get('/wallet',");$end=strpos($source,"\$router->post('/orders/{no}/balance-pay',",$start);wallet_ok($start!==false&&$end!==false,'真实余额路由边界');eval(substr($source,$start,$end-$start));
$before=$pdo->query('SELECT total_changes()')->fetchColumn();$body=wallet_run();
wallet_ok(strpos($body,'MY BALANCE')!==false&&strpos($body,'class="mw-page"')!==false&&substr_count($body,'data-label="时间"')===6,'默认余额入口与六笔真实流水');
wallet_ok(strpos($body,'<span>¥</span>68.00')!==false&&strpos($body,'<dd>¥146.00</dd>')!==false&&strpos($body,'<dd>¥78.00</dd>')!==false,'真实可用余额与近三十天收支整数统计');
wallet_ok(strpos($body,'+¥10.00')!==false&&strpos($body,'-¥68.00')!==false&&strpos($body,'-¥10.00')!==false&&strpos($body,'¥136.00')!==false,'正确收支符号与每笔历史余额');
wallet_ok(strpos($body,'href="/orders/XY20261104001"')!==false&&strpos($body,'href="/orders/XY20261030001"')!==false&&strpos($body,'href="/feedback/1"')!==false,'本人订单与反馈关联真实入口');
wallet_ok(strpos($body,'PRIVATE-')===false&&strpos($body,'&lt;script&gt;PRIVATE-REASON')===false&&strpos($body,'/orders/PRIVATE-ORDER')===false,'不泄露返佣他人订单及内部原因和幂等键');
wallet_ok(strpos($body,'mw-record-details')!==false&&strpos($body,'变动前余额')!==false&&strpos($body,'不支持在线充值或提现')!==false&&strpos($body,'不能与在线支付组合使用')!==false,'无外部入口的流水提供原生只读详情及真实余额规则');
$doc=new DOMDocument();$doc->loadHTML('<?xml encoding="UTF-8">'.$body,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$xp=new DOMXPath($doc);
wallet_ok($xp->query('//h1')->length===1&&$xp->query('//nav[@aria-label="账本收支筛选"]/a[@aria-current="page"]')->length===1&&$xp->query('//form[@method="post"]')->length===0,'唯一主标题选中态且无业务POST表单');
ob_start();account_overview_shell('我的余额',$body,'',2,$pdo,$config,'/wallet');$html=ob_get_clean();
wallet_ok(strpos($html,'account-wallet-editorial.css')!==false&&strpos($html,'account-orders-editorial.css')===false&&strpos($html,'href="/wallet" aria-current="page"')!==false,'独立CSS与正确余额侧栏');
wallet_ok(strpos($html,'action="/logout"')!==false&&strpos($html,'name="_csrf"')!==false,'共享外壳保留退出CSRF');
wallet_ok($before==$pdo->query('SELECT total_changes()')->fetchColumn(),'页面渲染不写余额订单账本或反馈');
foreach(array('income'=>4,'expense'=>2) as $direction=>$count){$result=wallet_run(array('direction'=>$direction));wallet_ok(substr_count($result,'data-label="时间"')===$count&&strpos($result,'<dd>¥146.00</dd>')!==false,'收入支出筛选不影响顶部近三十天统计 '.$direction);}
foreach(array('admin_adjust','order_payment','order_refund','referral_credit','referral_reversal','feedback_reward') as $type){$result=wallet_run(array('type'=>$type));wallet_ok(substr_count($result,'data-label="时间"')===1,'真实来源筛选 '.$type);}
$result=wallet_run(array('q'=>'xy20261104001','type'=>'order_payment','direction'=>'expense','range'=>'all'));wallet_ok(substr_count($result,'data-label="时间"')===1&&strpos($result,'name="direction" value="expense"')!==false&&strpos($result,'name="range" value="all"')!==false&&strpos($result,'value="order_payment" selected')!==false,'订单搜索交集与筛选参数互相保留');
wallet_ok(strpos(wallet_run(array('q'=>'PRIVATE-ORDER')),'暂无匹配的余额变动记录')!==false&&strpos(wallet_run(array('q'=>'%')),'暂无匹配的余额变动记录')!==false&&strpos(wallet_run(array('q'=>'_')),'暂无匹配的余额变动记录')!==false,'他人订单不可搜索且通配符按字面匹配');
$insert->execute(array(7,10000,0,10000,'admin_adjust',null,'PRIVATE-OLD','PRIVATE-OLD-KEY',date('Y-m-d H:i:s',$now-45*86400)));
$result=wallet_run();wallet_ok(substr_count($result,'data-label="时间"')===6&&strpos($result,'<dd>¥146.00</dd>')!==false,'近三十天排除旧记录与统计');
wallet_ok(substr_count(wallet_run(array('range'=>'90')),'data-label="时间"')===7&&substr_count(wallet_run(array('range'=>'all')),'data-label="时间"')===7,'最近九十天与全部时间实际生效');
$insert->execute(array(8,100,0,100,'feedback_reward',null,'PRIVATE-FOREIGN-FEEDBACK','PRIVATE-FAKE-KEY',date('Y-m-d H:i:s',$now)));$result=wallet_run();wallet_ok(strpos($result,'/feedback/2')===false&&strpos($result,'PRIVATE-FEEDBACK')===false,'奖励关联也必须为本人反馈');
$insert->execute(array(9,-100,100,0,'order_payment',99,'PRIVATE-FOREIGN-ORDER','PRIVATE-FAKE-ORDER',date('Y-m-d H:i:s',$now)));$result=wallet_run();wallet_ok(strpos($result,'PRIVATE-ORDER')===false,'异常跨用户付款关联仍不泄露订单');
foreach(array(array('q'=>array('x')),array('q'=>"\xff"),array('q'=>str_repeat('长',81)),array('direction'=>'invalid'),array('type'=>array('admin_adjust')),array('type'=>'unknown'),array('range'=>'365'),array('range'=>array('all')),array('page'=>'0'),array('page'=>array('1')),array('page'=>'1e2')) as $bad){wallet_run($bad);wallet_ok(http_response_code()===400,'异常账本参数400');}
$result=wallet_run(array('q'=>'"><script>'));wallet_ok(strpos($result,'&quot;&gt;&lt;script&gt;')!==false&&strpos($result,'<script>')===false,'搜索输入安全转义');
$pdo->exec("UPDATE orders SET order_no='<script>订单</script>' WHERE id=2;UPDATE feedback_submissions SET feedback_no='<b>反馈</b>' WHERE id=1");$result=wallet_run();wallet_ok(strpos($result,'&lt;script&gt;订单&lt;/script&gt;')!==false&&strpos($result,'&lt;b&gt;反馈&lt;/b&gt;')!==false&&strpos($result,'<script>订单')===false,'订单号与反馈编号安全转义');
for($i=10;$i<32;$i++)$insert->execute(array($i,1,0,1,'admin_adjust',null,'PRIVATE-PAGE','PRIVATE-PAGE-'.$i,date('Y-m-d H:i:s',$now)));
$total=(int)$pdo->query('SELECT COUNT(*) FROM wallet_transactions WHERE user_id=1')->fetchColumn();$result=wallet_run(array('range'=>'all'));wallet_ok(substr_count($result,'data-label="时间"')===20&&strpos($result,'page=2')!==false&&strpos($result,'共 '.$total.' 条')!==false,'账本每页二十笔与真实总数');
$result=wallet_run(array('range'=>'all','page'=>'2'));wallet_ok(substr_count($result,'data-label="时间"')===$total-20&&strpos($result,'2 / 2')!==false,'第二页保留范围与剩余记录');
$result=wallet_run(array('range'=>'all','page'=>'99999999'));wallet_ok(strpos($result,'2 / 2')!==false,'越界页码收敛末页');
$result=wallet_run(array('range'=>'all','type'=>'admin_adjust'));wallet_ok(strpos($result,'查看余额流水 31')<strpos($result,'查看余额流水 30'),'相同时间按ID稳定倒序');
$insert->execute(array(32,999999999,0,999999999,'admin_adjust',null,'PRIVATE-LARGE','PRIVATE-LARGE',date('Y-m-d H:i:s',$now)));$insert->execute(array(33,999999999,0,999999999,'admin_adjust',null,'PRIVATE-LARGE','PRIVATE-LARGE-2',date('Y-m-d H:i:s',$now)));
$result=wallet_run();wallet_ok(strpos($result,'<dd>¥'.WalletService::formatAmount(14600+100+22+2*999999999).'</dd>')!==false,'累计收支超过单钱包上限仍精确到整数分');
$pdo->exec('DELETE FROM wallet_transactions WHERE user_id=1');$result=wallet_run();wallet_ok(strpos($result,'暂无匹配的余额变动记录')!==false&&strpos($result,'<dd>¥0.00</dd>')!==false&&strpos($result,'<span>¥</span>68.00')!==false,'空流水仍显示真实账户余额不填示例');
$_SESSION=array();try{wallet_run();throw new LogicException('鉴权未触发');}catch(RuntimeException $ex){wallet_ok($ex->getMessage()==='REDIRECT /login','实际余额入口保留访客鉴权');}
if(in_array('--html',$_SERVER['argv'],true))echo $html;else echo "WALLET PAGE TESTS PASSED\n";
