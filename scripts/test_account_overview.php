<?php
// 仅使用内存数据执行真实总览闭包，不加载配置或连接业务数据库。
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);
require $root.'/app/Core/Router.php';
require $root.'/app/Core/Security.php';
require $root.'/app/Services/WalletService.php';
require $root.'/app/Services/NotificationService.php';
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function page($title,$body,$config){$GLOBALS['overview_body']=$body;}
function overview_ok($ok,$label){if(!$ok)throw new RuntimeException('FAIL '.$label);if(!in_array('--html',$_SERVER['argv'],true))echo 'PASS '.$label.PHP_EOL;}
function overview_function($source,$name){$tokens=token_get_all($source);$capture=false;$depth=0;$opened=false;$result='';foreach($tokens as $i=>$token){if(!$capture&&is_array($token)&&$token[0]===T_FUNCTION){$j=$i+1;while(isset($tokens[$j])&&is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE)$j++;if(isset($tokens[$j])&&is_array($tokens[$j])&&$tokens[$j][1]===$name)$capture=true;}if(!$capture)continue;$text=is_array($token)?$token[1]:$token;$result.=$text;if($token==='{'){$opened=true;$depth++;}elseif($token==='}'&&--$depth===0&&$opened)return $result;}throw new RuntimeException('函数缺失 '.$name);}
set_error_handler(function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$pdo=new PDO('sqlite::memory:',null,null,array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,display_name TEXT,email TEXT,balance_cents INTEGER,password_changed_at TEXT);CREATE TABLE orders(id INTEGER PRIMARY KEY,user_id INTEGER,order_no TEXT,product_name_snapshot TEXT,plan_name_snapshot TEXT,product_type_snapshot TEXT,duration_days_snapshot INTEGER,traffic_snapshot TEXT,total_amount_cents INTEGER,status TEXT);CREATE TABLE delivery_records(order_id INTEGER,is_current INTEGER,opened_at TEXT,expires_at TEXT,secret TEXT);CREATE TABLE notifications(id INTEGER PRIMARY KEY,user_id INTEGER,title TEXT,content TEXT,order_no TEXT,target_type TEXT,target_id INTEGER,created_at TEXT,read_at TEXT)');
$pdo->exec("INSERT INTO users VALUES(1,'云间旅人','overview@example.test',6800,NULL),(2,'其他用户','other@example.test',999999,NULL)");
$insert=$pdo->prepare('INSERT INTO orders VALUES(?,1,?,?,?,?,30,?,19800,?)');
foreach(array('delivered','delivered','delivered','pending_delivery','paid_waiting_group','pending_payment') as $i=>$state)$insert->execute(array($i+1,'TEST-'.($i+1),'内存测试订阅','年卡','vpn','100 GB',$state));
$pdo->exec("INSERT INTO orders VALUES(99,2,'PRIVATE-ORDER','其他用户私有商品','方案','vpn',30,'100 GB',999999,'pending_payment')");
$d=$pdo->prepare('INSERT INTO delivery_records VALUES(?,1,?,?,?)');
$d->execute(array(1,'2020-01-01',null,'PRIVATE-SECRET'));
$d->execute(array(2,'2020-01-01','2020-02-01','PRIVATE-SECRET'));
$d->execute(array(3,date('Y-m-d'),date('Y-m-d',time()+86400*30),'PRIVATE-SECRET'));
$pdo->exec("INSERT INTO notifications VALUES(1,1,'正式交付已完成','请进入本人订单查看交付内容','TEST-3','order',3,'2026-11-01',NULL),(2,2,'其他用户私有消息','不可显示',NULL,NULL,NULL,'2026-11-01',NULL)");
$_SESSION=array('user_id'=>1,'user_password_changed_at'=>null,'_csrf'=>'isolated-test-token');
$config=array('app'=>array('name'=>'小云店'));$router=new Router();
$source=file_get_contents($root.'/public/index.php');
foreach(array('user_nav_icon','home_outline_icon','money','status_text','account_overview_shell') as $name)eval(str_replace('__DIR__',var_export($root.'/public',true),overview_function($source,$name)));
$start=strpos($source,'$workspacePage = function(');$end=strpos($source,"\$router->get('/wallet',",$start);
overview_ok($start!==false&&$end!==false,'真实路由提取边界');eval(substr($source,$start,$end-$start));
$before=$pdo->query('SELECT total_changes()')->fetchColumn();
$router->dispatch('GET','/account/overview');$body=$GLOBALS['overview_body'];
overview_ok(strpos($body,'ao-welcome')!==false&&strpos($body,'云间旅人')!==false,'默认总览使用新布局与真实昵称');
foreach(array('待付款','等待交付','等待成团','有效订阅') as $label)overview_ok(strpos($body,'<span>'.$label.'</span><strong>1</strong>')!==false,'独立真实计数 '.$label);
overview_ok(strpos($body,'</span>68.00')!==false&&strpos($body,'不支持拆分付款')!==false,'余额金额和支付说明');
overview_ok(substr_count($body,'class="ao-subscription"')===2&&strpos($body,'已到期')!==false&&strpos($body,'仅显示最近 2 项，共 3 项')!==false,'最近两条订阅与到期状态');
overview_ok(strpos($body,'/orders/TEST-6">去付款')!==false&&strpos($body,'/orders/TEST-5">查看成团进度')!==false&&strpos($body,'/orders/TEST-3#order-delivery">查看交付')!==false,'操作链接匹配真实订单状态');
overview_ok(strpos($body,'PRIVATE-')===false&&strpos($body,'其他用户私有')===false,'不泄露交付秘密与其他用户数据');
ob_start();account_overview_shell('个人总览',$body,'',1,$pdo,$config);$html=ob_get_clean();
overview_ok(strpos($html,'account-overview-editorial.css')!==false&&strpos($html,'UID：1')!==false&&strpos($html,'aria-current="page"')!==false,'完整独立外壳与当前导航');
overview_ok(strpos($html,'action="/logout"')!==false&&strpos($html,'name="_csrf"')!==false,'退出仍为带CSRF的POST表单');
foreach(array('subscriptions','wallet','messages','tickets','feedback','referral','settings') as $accountPage){
    $css=file_get_contents($root.'/public/assets/account-'.$accountPage.'-editorial.css');
    overview_ok(!preg_match('/\.ao-(?:layout|header|sidebar|profile|avatar|account-nav|count)\b/',$css),'侧栏与定位统一继承总览 '.$accountPage);
}
overview_ok($before==$pdo->query('SELECT total_changes()')->fetchColumn(),'渲染不写入余额订单或通知');
$pdo->exec("UPDATE users SET display_name='<script>昵称</script>' WHERE id=1");$router->dispatch('GET','/account/overview');
overview_ok(strpos($GLOBALS['overview_body'],'&lt;script&gt;昵称&lt;/script&gt;')!==false&&strpos($GLOBALS['overview_body'],'<script>昵称')===false,'昵称安全转义');
$_SESSION['user_id']=2;$router->dispatch('GET','/account/overview');
overview_ok(strpos($GLOBALS['overview_body'],'暂无已交付订阅')!==false&&strpos($GLOBALS['overview_body'],'暂无新消息')===false,'无订阅时显示真实空态');
$pdo->exec('DELETE FROM notifications;DELETE FROM orders;DELETE FROM delivery_records');$router->dispatch('GET','/account/overview');
overview_ok(strpos($GLOBALS['overview_body'],'暂无订单')!==false&&strpos($GLOBALS['overview_body'],'暂无新消息')!==false,'空库不填充示例订单消息');
if(in_array('--html',$_SERVER['argv'],true))echo $html;else echo "ACCOUNT OVERVIEW TESTS PASSED\n";
