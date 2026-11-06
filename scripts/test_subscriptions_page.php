<?php
// 仅使用内存数据库与隔离会话，执行真实订阅入口，不读取业务配置。
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);require $root.'/app/Core/Router.php';require $root.'/app/Core/Security.php';
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function page($title,$body,$config){$GLOBALS['subscriptions_body']=$body;}
function redirect($url){throw new RuntimeException('REDIRECT '.$url);}
function flash($type,$text){}
function subscriptions_ok($ok,$label){if(!$ok)throw new RuntimeException('FAIL '.$label);if(!in_array('--html',$_SERVER['argv'],true))echo 'PASS '.$label.PHP_EOL;}
function subscriptions_function($source,$name){$tokens=token_get_all($source);$capture=false;$depth=0;$opened=false;$result='';foreach($tokens as $i=>$token){if(!$capture&&is_array($token)&&$token[0]===T_FUNCTION){$j=$i+1;while(isset($tokens[$j])&&is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE)$j++;if(isset($tokens[$j])&&is_array($tokens[$j])&&$tokens[$j][1]===$name)$capture=true;}if(!$capture)continue;$result.=is_array($token)?$token[1]:$token;if($token==='{'){$opened=true;$depth++;}elseif($token==='}'&&--$depth===0&&$opened)return $result;}throw new RuntimeException('函数缺失 '.$name);}
function subscriptions_run($get=array()){$_GET=$get;http_response_code(200);$GLOBALS['subscriptions_body']='';$GLOBALS['router']->dispatch('GET','/account/subscriptions');return $GLOBALS['subscriptions_body'];}
set_error_handler(function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$pdo=new PDO('sqlite::memory:',null,null,array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,display_name TEXT,email TEXT,password_changed_at TEXT);CREATE TABLE orders(id INTEGER PRIMARY KEY,user_id INTEGER,order_no TEXT,product_name_snapshot TEXT,plan_name_snapshot TEXT,product_type_snapshot TEXT,status TEXT);CREATE TABLE delivery_records(id INTEGER PRIMARY KEY,order_id INTEGER,is_current INTEGER,opened_at TEXT,expires_at TEXT,content_ciphertext TEXT)');
$pdo->exec("INSERT INTO users VALUES(1,'云间旅人','subscriptions@example.test',NULL),(2,'他人','other@example.test',NULL)");
$insert=$pdo->prepare('INSERT INTO orders VALUES(?,1,?,?,?,?,?)');$delivery=$pdo->prepare('INSERT INTO delivery_records VALUES(?,?,1,?,?,?)');$now=time();
foreach(array(329,313,3,-6) as $i=>$days){$id=$i+1;$insert->execute(array($id,'XY2026101000'.$id,$i===0?'小云铺·畅享年包':($i===2?'轻量体验套餐':'特价活动福利'),$i===2?'月卡':'年卡','vpn','delivered'));$delivery->execute(array($id,$id,date('Y-m-d H:i:s',$now-30*86400),date('Y-m-d H:i:s',$now+$days*86400),'PRIVATE-SECRET'));}
$pdo->exec("INSERT INTO orders VALUES(90,2,'PRIVATE-ORDER','他人私有订阅','年卡','vpn','delivered'),(91,1,'ACCOUNT-ORDER','账号不应展示','标准','account','delivered'),(92,1,'PENDING-ORDER','未交付不应展示','月卡','vpn','pending_delivery'),(93,1,'OLD-ORDER','非当前记录不应展示','月卡','vpn','delivered');INSERT INTO delivery_records VALUES(90,90,1,'2026-01-01','2099-01-01','PRIVATE-OTHER'),(91,91,1,'2026-01-01','2099-01-01','PRIVATE-ACCOUNT'),(92,92,1,'2026-01-01','2099-01-01','PRIVATE-PENDING'),(93,93,0,'2026-01-01','2099-01-01','PRIVATE-OLD')");
$_SESSION=array('user_id'=>1,'user_password_changed_at'=>null,'_csrf'=>'isolated-test-token');$config=array('app'=>array('name'=>'小云店'));$router=new Router();$source=file_get_contents($root.'/public/index.php');
foreach(array('account_subscriptions_page','user_nav_icon','home_outline_icon','account_overview_shell') as $name)eval(str_replace('__DIR__',var_export($root.'/public',true),subscriptions_function($source,$name)));
$start=strpos($source,'$workspacePage = function(');$end=strpos($source,"\$router->get('/wallet',",$start);subscriptions_ok($start!==false&&$end!==false,'真实订阅路由边界');eval(substr($source,$start,$end-$start));
$before=$pdo->query('SELECT total_changes()')->fetchColumn();$body=subscriptions_run();
subscriptions_ok(substr_count($body,'class="ms-subscription')===4&&strpos($body,'MY SUBSCRIPTIONS')!==false&&strpos($body,'workspace-subscription-grid')===false,'默认入口使用参考卡片布局');
foreach(array('全部订阅'=>4,'有效订阅'=>3,'已到期'=>1) as $label=>$count)subscriptions_ok(strpos($body,'<span>'.$label.'</span><strong>'.$count.'</strong>')!==false,'真实全局统计 '.$label);
subscriptions_ok(strpos($body,'即将到期<span>(1)</span>')!==false&&strpos($body,'class="ms-subscription is-soon"')!==false&&strpos($body,'class="ms-subscription is-expired"')!==false,'七天内即将到期且包含在有效统计');
subscriptions_ok(strpos($body,'PRIVATE-')===false&&strpos($body,'ACCOUNT-ORDER')===false&&strpos($body,'PENDING-ORDER')===false&&strpos($body,'OLD-ORDER')===false,'仅本人当前已交付VPN且不包含交付秘密');
subscriptions_ok(strpos($body,'href="/orders/XY20261010001#order-delivery"')!==false&&strpos($body,'href="/orders/XY20261010001">查看订单')!==false&&strpos($body,'class="ms-repurchase" href="/products"')!==false,'查看交付订单与重新选购为真实只读入口');
subscriptions_ok(strpos($body,'未来 7 天')!==false&&strpos($body,'不代表节点在线状态')!==false&&strpos($body,'/downloads')!==false,'明确状态口径与现有使用教程入口');
$doc=new DOMDocument();$doc->loadHTML('<?xml encoding="UTF-8">'.$body,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$xp=new DOMXPath($doc);
subscriptions_ok($xp->query('//h1')->length===1&&$xp->query('//nav[@aria-label="订阅状态"]/a[@aria-current="page"]')->length===1,'唯一主标题与状态选中态');
ob_start();account_overview_shell('我的订阅',$body,'',2,$pdo,$config,'/account/subscriptions');$html=ob_get_clean();
subscriptions_ok(strpos($html,'account-subscriptions-editorial.css')!==false&&strpos($html,'account-orders-editorial.css')===false&&strpos($html,'href="/account/subscriptions" aria-current="page"')!==false&&strpos($html,'href="/orders" aria-current="page"')===false,'独立CSS及正确侧栏当前项');
subscriptions_ok(strpos($html,'action="/logout"')!==false&&strpos($html,'name="_csrf"')!==false,'共享外壳保留退出CSRF');
subscriptions_ok($before==$pdo->query('SELECT total_changes()')->fetchColumn(),'订阅渲染不写入业务数据');
foreach(array('active'=>3,'soon'=>1,'expired'=>1,'unknown'=>0) as $status=>$count){$result=subscriptions_run(array('status'=>$status));subscriptions_ok(substr_count($result,'class="ms-subscription')===$count,'服务端状态筛选 '.$status);}
$result=subscriptions_run(array('q'=>'轻量','status'=>'active','sort'=>'expiry_asc'));subscriptions_ok(substr_count($result,'class="ms-subscription')===1&&strpos($result,'name="status" value="active"')!==false&&strpos($result,'value="expiry_asc" selected')!==false&&strpos($result,'即将到期<span>(1)</span>')!==false,'搜索状态排序交集且保留条件');
subscriptions_ok(strpos(subscriptions_run(array('q'=>'xy20261010001')),'小云铺·畅享年包')!==false&&strpos(subscriptions_run(array('q'=>'月卡')),'轻量体验套餐')!==false,'搜索订单号与方案名称');
subscriptions_ok(strpos(subscriptions_run(array('q'=>'PRIVATE-SECRET')),'暂无匹配订阅')!==false&&strpos(subscriptions_run(array('q'=>'%_!')),'暂无匹配订阅')!==false,'不搜索秘密且特殊符号按字面处理');
$result=subscriptions_run(array('sort'=>'expiry_desc'));subscriptions_ok(strpos($result,'<article class="ms-subscription"><div class="ms-product"><h2><a href="/orders/XY20261010001"')!==false&&strpos($result,'/orders/XY20261010001#order-delivery')<strpos($result,'/orders/XY20261010002#order-delivery'),'默认到期从晚到早排序');
$result=subscriptions_run(array('sort'=>'expiry_asc'));subscriptions_ok(strpos($result,'/orders/XY20261010004#order-delivery')<strpos($result,'/orders/XY20261010001#order-delivery'),'到期从早到晚排序');
$insert->execute(array(5,'UNKNOWN-ORDER','未登记期限订阅','月卡','vpn','delivered'));$delivery->execute(array(5,5,null,null,'PRIVATE-UNKNOWN'));
$result=subscriptions_run();subscriptions_ok(strpos($result,'<span>全部订阅</span><strong>5</strong>')!==false&&strpos($result,'<span>有效订阅</span><strong>3</strong>')!==false&&strpos($result,'另有 1 项期限未设置')!==false&&strpos($result,'期限未设置<span>(1)</span>')!==false,'未知期限不伪装有效或已到期');
$result=subscriptions_run(array('status'=>'unknown'));subscriptions_ok(substr_count($result,'class="ms-subscription')===1&&strpos($result,'UNKNOWN-ORDER')!==false&&strpos($result,'开通时间 未设置')!==false,'未知期限可独立筛选');
$boundary=array(array('id'=>1,'order_no'=>'BOUNDARY','product_name_snapshot'=>'边界','plan_name_snapshot'=>'月卡','opened_at'=>null,'expires_at'=>date('Y-m-d H:i:s',$now)));
$_GET=array();$result=account_subscriptions_page($boundary,$now);subscriptions_ok(strpos($result,'<span>有效订阅</span><strong>0</strong>')!==false&&strpos($result,'<span>已到期</span><strong>1</strong>')!==false,'恰好到期即为已到期');
$boundary[0]['expires_at']=date('Y-m-d H:i:s',$now+7*86400);$result=account_subscriptions_page($boundary,$now);subscriptions_ok(strpos($result,'class="ms-subscription is-soon"')!==false,'恰好未来七天归入即将到期');
$boundary[0]['expires_at']=date('Y-m-d H:i:s',$now+7*86400+1);$result=account_subscriptions_page($boundary,$now);subscriptions_ok(strpos($result,'class="ms-subscription is-soon"')===false,'超过七天不归入即将到期');
foreach(array(array('q'=>array('x')),array('q'=>"\xff"),array('q'=>str_repeat('长',101)),array('status'=>'all'),array('status'=>array('active')),array('sort'=>'id DESC'),array('page'=>'0'),array('page'=>array('1')),array('page'=>'1e2')) as $bad){subscriptions_run($bad);subscriptions_ok(http_response_code()===400,'异常查询参数400');}
$result=subscriptions_run(array('q'=>'"><script>'));subscriptions_ok(strpos($result,'&quot;&gt;&lt;script&gt;')!==false&&strpos($result,'<script>')===false,'搜索输入安全转义');
$pdo->exec("UPDATE orders SET product_name_snapshot='<script>商品</script>',plan_name_snapshot='<b>年卡</b>' WHERE id=1");$result=subscriptions_run();subscriptions_ok(strpos($result,'&lt;script&gt;商品&lt;/script&gt;')!==false&&strpos($result,'&lt;b&gt;年卡&lt;/b&gt;')!==false&&strpos($result,'<script>商品')===false,'商品与方案快照安全转义');
$delivery->execute(array(6,1,date('Y-m-d H:i:s',$now),date('Y-m-d H:i:s',$now+200*86400),'PRIVATE-DUPLICATE'));$result=subscriptions_run();subscriptions_ok(substr_count($result,'class="ms-subscription')===5,'异常多个当前版本不重复订阅');
for($i=10;$i<22;$i++){$insert->execute(array($i,'PAGE-'.$i,'分页测试','月卡','vpn','delivered'));$delivery->execute(array($i,$i,null,date('Y-m-d H:i:s',$now+200*86400),'PRIVATE-PAGE'));}
$result=subscriptions_run();subscriptions_ok(substr_count($result,'class="ms-subscription')===10&&strpos($result,'page=2')!==false&&strpos($result,'共 17 项')!==false,'真实分页首屏十项与总数');
$result=subscriptions_run(array('page'=>'2','sort'=>'expiry_asc'));subscriptions_ok(substr_count($result,'class="ms-subscription')===7&&strpos($result,'2 / 2')!==false,'第二页剩余订阅');
$result=subscriptions_run(array('page'=>'99999999'));subscriptions_ok(strpos($result,'2 / 2')!==false,'越界页码收敛到末页');
$pdo->exec('DELETE FROM orders WHERE user_id=1');$result=subscriptions_run();subscriptions_ok(strpos($result,'还没有已交付订阅')!==false&&strpos($result,'PRIVATE-ORDER')===false,'空列表不填示例或其他用户数据');
$_SESSION=array();try{subscriptions_run();throw new LogicException('鉴权未触发');}catch(RuntimeException $ex){subscriptions_ok($ex->getMessage()==='REDIRECT /login','真实订阅入口保持访客鉴权');}
if(in_array('--html',$_SERVER['argv'],true))echo $html;else echo "SUBSCRIPTIONS PAGE TESTS PASSED\n";
