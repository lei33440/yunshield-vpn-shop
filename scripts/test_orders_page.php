<?php
// 仅内存数据库与隔离会话，执行真实订单列表入口，不加载业务配置。
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);
require $root.'/app/Core/Router.php';require $root.'/app/Core/Security.php';
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function page($title,$body,$config){$GLOBALS['orders_body']=$body;}
function redirect($url){throw new RuntimeException('REDIRECT '.$url);}
function flash($type,$text){}
function orders_ok($ok,$label){if(!$ok)throw new RuntimeException('FAIL '.$label);if(!in_array('--html',$_SERVER['argv'],true))echo 'PASS '.$label.PHP_EOL;}
function orders_function($source,$name){$tokens=token_get_all($source);$capture=false;$depth=0;$opened=false;$result='';foreach($tokens as $i=>$token){if(!$capture&&is_array($token)&&$token[0]===T_FUNCTION){$j=$i+1;while(isset($tokens[$j])&&is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE)$j++;if(isset($tokens[$j])&&is_array($tokens[$j])&&$tokens[$j][1]===$name)$capture=true;}if(!$capture)continue;$result.=is_array($token)?$token[1]:$token;if($token==='{'){$opened=true;$depth++;}elseif($token==='}'&&--$depth===0&&$opened)return $result;}throw new RuntimeException('函数缺失 '.$name);}
function orders_run($get=array()){$_GET=$get;http_response_code(200);$GLOBALS['orders_body']='';$GLOBALS['router']->dispatch('GET','/orders');return $GLOBALS['orders_body'];}
set_error_handler(function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$pdo=new PDO('sqlite::memory:',null,null,array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,display_name TEXT,email TEXT,password_changed_at TEXT);CREATE TABLE orders(id INTEGER PRIMARY KEY,user_id INTEGER,order_no TEXT,product_name_snapshot TEXT,plan_name_snapshot TEXT,product_type_snapshot TEXT,duration_days_snapshot INTEGER,total_amount_cents INTEGER,status TEXT,created_at TEXT);CREATE TABLE delivery_records(id INTEGER PRIMARY KEY,order_id INTEGER,is_current INTEGER,opened_at TEXT,expires_at TEXT,content_ciphertext TEXT)');
$pdo->exec("INSERT INTO users VALUES(1,'云间旅人','orders@example.test',NULL),(2,'他人','other@example.test',NULL)");
$insert=$pdo->prepare('INSERT INTO orders VALUES(?,1,?,?,?,?,?,?,?,?)');
$states=array('pending_payment','paid_waiting_group','pending_delivery','delivered','delivered','refunding');
foreach($states as $i=>$status){$account=$i===4;$insert->execute(array($i+1,'XY2026110600'.($i+1),$account?'Google账号':($i%2?'小云铺·畅享年包':'特价活动福利'),$account?'标准方案':'年卡',$account?'account':'vpn',$account?null:365,$i%2?19800:6800,$status,'2026-11-06 10:24:00'));}
$pdo->exec("INSERT INTO orders VALUES(99,2,'PRIVATE-ORDER','其他用户私有商品','方案','vpn',30,999999,'pending_payment','2026-11-06');INSERT INTO delivery_records VALUES(1,4,1,'2026-10-01','2027-10-01','PRIVATE-SECRET'),(2,4,0,'2020-01-01','2020-02-01','PRIVATE-OLD'),(3,5,1,NULL,NULL,'PRIVATE-ACCOUNT')");
$_SESSION=array('user_id'=>1,'user_password_changed_at'=>null,'_csrf'=>'isolated-test-token');
$source=file_get_contents($root.'/public/index.php');
foreach(array('money','status_text','account_orders_page','user_nav_icon','home_outline_icon','account_overview_shell') as $name)eval(str_replace('__DIR__',var_export($root.'/public',true),orders_function($source,$name)));
$config=array('app'=>array('name'=>'小云店'));$router=new Router();$start=strpos($source,"\$router->get('/orders',");$end=strpos($source,'function order_detail_secret(',$start);orders_ok($start!==false&&$end!==false,'真实列表路由提取');eval(substr($source,$start,$end-$start));
$before=$pdo->query('SELECT total_changes()')->fetchColumn();$body=orders_run();
orders_ok(strpos($body,'class="mo-page"')!==false&&strpos($body,'orders-table')===false&&substr_count($body,'class="mo-order"')===6,'默认入口直接展示六张订单卡片');
orders_ok(strpos($body,'共 <strong>6</strong> 笔订单')!==false&&strpos($body,'PRIVATE-')===false&&strpos($body,'其他用户')===false,'本人订单计数且不泄露他人或交付秘密');
$doc=new DOMDocument();$doc->loadHTML('<?xml encoding="UTF-8">'.$body,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$xp=new DOMXPath($doc);
orders_ok($xp->query('//h1')->length===1&&$xp->query('//nav[@aria-label="订单商品分类"]/a[@aria-current="page"]')->length===1&&$xp->query('//nav[@aria-label="订单状态"]/a[@aria-current="page"]')->length===1,'唯一主标题与分类状态选中态');
orders_ok(strpos($body,'href="/orders/XY20261106001">去付款')!==false&&strpos($body,'href="/orders/XY20261106002">查看成团进度')!==false&&strpos($body,'href="/orders/XY20261106004#order-delivery">查看交付')!==false,'状态操作只链接现有真实GET入口');
orders_ok(strpos($body,'2026-10-01 · 到期 2027-10-01')!==false&&strpos($body,'2020-02-01')===false&&strpos($body,'账号交付内容仅本人可见')!==false,'有效交付日期与账号隐私说明');
orders_ok($xp->query('//form[@method="post"]')->length===0&&$before==$pdo->query('SELECT total_changes()')->fetchColumn(),'筛选列表不发起业务写入');
ob_start();account_overview_shell('我的订单',$body,'',2,$pdo,$config,'/orders');$html=ob_get_clean();
orders_ok(strpos($html,'account-orders-editorial.css')!==false&&strpos($html,'href="/orders" aria-current="page"')!==false&&strpos($html,'href="/account/overview" aria-current="page"')===false,'订单独立CSS与侧栏当前项正确');
orders_ok(strpos($html,'action="/logout"')!==false&&strpos($html,'name="_csrf"')!==false,'共享外壳保留退出CSRF');
foreach(array('vpn'=>5,'account'=>1) as $type=>$count){$result=orders_run(array('product_type'=>$type));orders_ok(substr_count($result,'class="mo-order"')===$count&&strpos($result,'共 <strong>'.$count.'</strong> 笔订单')!==false,'商品类型筛选 '.$type);}
foreach(array('pending_payment','paid_waiting_group','pending_delivery','delivered','refunding') as $status){$result=orders_run(array('status'=>$status));orders_ok(substr_count($result,'class="mo-order"')===($status==='delivered'?2:1),'服务端状态筛选 '.$status);}
$result=orders_run(array('q'=>'XY20261106005','product_type'=>'account','status'=>'delivered','sort'=>'oldest'));
orders_ok(substr_count($result,'class="mo-order"')===1&&strpos($result,'name="product_type" value="account"')!==false&&strpos($result,'name="status" value="delivered"')!==false&&strpos($result,'value="oldest" selected')!==false,'搜索分类状态排序交集且表单保留参数');
orders_ok(strpos(orders_run(array('q'=>'xy20261106005')),'Google账号')!==false,'订单号搜索大小写兼容');
orders_ok(strpos(orders_run(array('q'=>'%')),'暂无匹配订单')!==false&&strpos(orders_run(array('q'=>'_')),'暂无匹配订单')!==false,'搜索通配符按字面匹配');
foreach(array(array('q'=>array('x')),array('q'=>"\xff"),array('q'=>str_repeat('长',81)),array('status'=>'all'),array('status'=>array('delivered')),array('product_type'=>'invalid'),array('sort'=>'id DESC'),array('page'=>'0'),array('page'=>array('1')),array('page'=>'1e2')) as $bad){orders_run($bad);orders_ok(http_response_code()===400,'非法筛选返回400');}
$result=orders_run(array('q'=>'"><script>'));orders_ok(strpos($result,'&quot;&gt;&lt;script&gt;')!==false&&strpos($result,'<script>')===false,'搜索输入安全转义');
$pdo->exec("UPDATE orders SET product_name_snapshot='<script>商品</script>',plan_name_snapshot='<b>方案</b>' WHERE id=1");$result=orders_run();orders_ok(strpos($result,'&lt;script&gt;商品&lt;/script&gt;')!==false&&strpos($result,'&lt;b&gt;方案&lt;/b&gt;')!==false&&strpos($result,'<script>商品')===false,'商品与方案快照安全转义');
$pdo->exec("INSERT INTO delivery_records VALUES(4,4,1,'2026-11-01','2027-11-01','PRIVATE-DUPLICATE')");$result=orders_run();orders_ok(substr_count($result,'class="mo-order"')===6&&strpos($result,'2027-11-01')!==false,'异常多个current仍不重复订单且取最新记录');
$pdo->exec("UPDATE orders SET status='cancelled' WHERE id=1;UPDATE orders SET status='refunded' WHERE id=2");
foreach(array('cancelled','refunded') as $status){$result=orders_run(array('status'=>$status));orders_ok(substr_count($result,'class="mo-order"')===1&&strpos($result,'<details class="mo-more" open>')!==false,'更多状态可访问 '.$status);}
for($i=10;$i<23;$i++)$insert->execute(array($i,'PAGE-'.$i,'分页测试','月卡','vpn',30,1000,'pending_payment','2026-11-07 10:00:00'));
$result=orders_run();orders_ok(substr_count($result,'class="mo-order"')===10&&strpos($result,'page=2')!==false&&strpos($result,'共 <strong>19</strong> 笔订单')!==false,'真实分页首屏十笔与总数');
$result=orders_run(array('page'=>'2'));orders_ok(substr_count($result,'class="mo-order"')===9&&strpos($result,'2 / 2')!==false,'第二页剩余订单');
$result=orders_run(array('page'=>'99999999'));orders_ok(strpos($result,'2 / 2')!==false,'越界页码收敛到末页');
$result=orders_run(array('sort'=>'oldest'));orders_ok(strpos($result,'XY20261106001')<strpos($result,'XY20261106002'),'创建时间同值按ID稳定排序');
$pdo->exec('DELETE FROM orders WHERE user_id=1');$result=orders_run();orders_ok(strpos($result,'暂无订单')!==false&&strpos($result,'Google账号')===false&&strpos($result,'PRIVATE-ORDER')===false,'空列表不填充示例订单或其他用户数据');
$_SESSION=array();try{orders_run();throw new LogicException('鉴权未触发');}catch(RuntimeException $ex){orders_ok($ex->getMessage()==='REDIRECT /login','真实路由保留访客鉴权');}
if(in_array('--html',$_SERVER['argv'],true))echo $html;else echo "ORDERS PAGE TESTS PASSED\n";
