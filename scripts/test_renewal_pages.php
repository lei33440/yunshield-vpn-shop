<?php
// 仅限 CLI：内存 SQLite、真实 RenewalService 只读接口及提取的生产 GET 路由。
// 身份、CSRF、支付设置、余额和 OrderService 明确使用本地替身，不创建会话、不加载业务配置、不访问网络或启动 worker。
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);require $root.'/app/Core/Router.php';require $root.'/app/Core/RenewalPages.php';require $root.'/app/Services/RenewalService.php';require $root.'/app/Services/CryptoService.php';require $root.'/app/Core/AccountProductPages.php';
class Security {
    public static $uid=1;
    public static function requireUser(){if(!self::$uid)throw new RuntimeException('AUTH');return self::$uid;}
    public static function requireAdmin(){return 9;}
    public static function csrfToken(){return 'isolated-only';}
}
class PaymentService {public function __construct($p,$c){}public function settings(){return array('enabled'=>1,'alipay_enabled'=>1,'wechat_enabled'=>1);}}
class WalletService {public function __construct($p){}public function balance($id){return 50000;}public static function formatAmount($v){return number_format($v/100,2,'.','');}}
class OrderService {
    private $pdo;public static $refunds=0;
    public function __construct($pdo){$this->pdo=$pdo;}
    public function getForUser($no,$uid){$s=$this->pdo->prepare('SELECT * FROM orders WHERE order_no=? AND user_id=?');$s->execute(array($no,$uid));return $s->fetch();}
    public function events($id){return array(array('description'=>'隔离测试订单事件','created_at'=>'2026-11-01','actor_type'=>'admin'));}
    public function requestRefund($no,$uid,$reason){self::$refunds++;}
}
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function csrf_field(){return '<input type="hidden" name="_csrf" value="isolated-only">';}
function post_csrf(){if(($_POST['_csrf']??'')!=='isolated-only')throw new RuntimeException('CSRF');}
function page($title,$body,$config){$GLOBALS['body']=$body;}
function flash($type,$message){$GLOBALS['flash']=$message;}
function redirect($url){$GLOBALS['redirect']=$url;}
function renewal_test_function($source,$name){$tokens=token_get_all($source);$capture=false;$depth=0;$opened=false;$result='';foreach($tokens as $i=>$token){if(!$capture&&is_array($token)&&$token[0]===T_FUNCTION){$j=$i+1;while(isset($tokens[$j])&&is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE)$j++;if(isset($tokens[$j])&&is_array($tokens[$j])&&$tokens[$j][1]===$name)$capture=true;}if(!$capture)continue;$result.=is_array($token)?$token[1]:$token;if($token==='{'){$opened=true;$depth++;}elseif($token==='}'&&--$depth===0&&$opened)return $result;}throw new RuntimeException('Missing '.$name);}
$checks=0;$htmlMode=in_array('--html',$_SERVER['argv'],true);
function check_renewal($ok,$label){if(!$ok)throw new RuntimeException('FAIL '.$label);$GLOBALS['checks']++;if(!$GLOBALS['htmlMode'])print('PASS '.$label.PHP_EOL);}
set_error_handler(function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$pdo=new PDO('sqlite::memory:',null,null,array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT);CREATE TABLE products(id INTEGER PRIMARY KEY,product_type TEXT);CREATE TABLE product_plans(id INTEGER PRIMARY KEY,product_id INTEGER,status TEXT,name TEXT,duration_days INTEGER,price_cents INTEGER);CREATE TABLE orders(id INTEGER PRIMARY KEY,order_no TEXT,user_id INTEGER,product_id INTEGER,product_plan_id INTEGER,order_kind TEXT DEFAULT "purchase",parent_order_id INTEGER,product_name_snapshot TEXT,plan_name_snapshot TEXT,product_type_snapshot TEXT,duration_days_snapshot INTEGER,unit_price_cents_snapshot INTEGER,total_amount_cents INTEGER,status TEXT,created_at TEXT,payment_started_at TEXT);CREATE TABLE delivery_records(id INTEGER PRIMARY KEY,order_id INTEGER,is_current INTEGER,version_no INTEGER,opened_at TEXT,expires_at TEXT,content_ciphertext TEXT,content_nonce BLOB,content_tag BLOB);CREATE TABLE order_renewals(order_id INTEGER,duration_days INTEGER,price_source TEXT,price_cents INTEGER,previous_expires_at TEXT,renewal_starts_at TEXT,new_expires_at TEXT,completed_at TEXT)');
$pdo->exec("INSERT INTO users VALUES(1,'active'),(2,'active');INSERT INTO products VALUES(1,'vpn'),(2,'vpn');INSERT INTO product_plans VALUES(1,1,'active','原年卡现价',365,29900),(2,1,'active','月卡',30,2800),(3,2,'active','其他订阅',30,1)");
$s=$pdo->prepare('INSERT INTO orders(id,order_no,user_id,product_id,product_plan_id,order_kind,parent_order_id,product_name_snapshot,plan_name_snapshot,product_type_snapshot,duration_days_snapshot,unit_price_cents_snapshot,total_amount_cents,status,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$base=array(1,'PARENT',1,1,1,'purchase',null,'小云铺·畅享订阅','年卡','vpn',365,19800,16800,'delivered','2026-11-01 12:00:00');$s->execute($base);
foreach(array(array(2,'PRIVATE',2,'vpn'),array(3,'ACCOUNT',1,'account'),array(4,'NO-DELIVERY',1,'vpn')) as $row){$b=$base;$b[0]=$row[0];$b[1]=$row[1];$b[2]=$row[2];$b[9]=$row[3];$s->execute($b);}
$config=array('app'=>array('name'=>'小云铺','timezone'=>'Asia/Shanghai'),'security'=>array('encryption_key'=>'renewal-page-isolated-fixture-only'));
// 使用真实加密服务构造当前交付；日期须与元数据完全一致，供真实报价前置校验读取。
$fixturePayload=array('subscription'=>'https://delivery.example.test/isolated-renewal/subscription','opened_at'=>'2026-01-01 00:00:00','expires_at'=>'2027-01-01 00:00:00');
$fixtureCrypto=new CryptoService($config);
list($fixtureCipher,$fixtureNonce,$fixtureTag)=$fixtureCrypto->encrypt(json_encode($fixturePayload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
$pdo->prepare('INSERT INTO delivery_records(id,order_id,is_current,version_no,opened_at,expires_at,content_ciphertext,content_nonce,content_tag) VALUES(1,1,1,4,?,?,?,?,?)')->execute(array($fixturePayload['opened_at'],$fixturePayload['expires_at'],$fixtureCipher,$fixtureNonce,$fixtureTag));
check_renewal(json_decode($fixtureCrypto->decrypt($fixtureCipher,$fixtureNonce,$fixtureTag),true)===$fixturePayload,'当前交付夹具使用真实加密且日期与元数据一致');
$source=file_get_contents($root.'/public/index.php');foreach(array('money','status_text','order_timeline','account_orders_page','order_detail_script') as $name)eval(renewal_test_function($source,$name));
$router=new Router();$start=strpos($source,"\$router->get('/orders/{no}',");$end=strpos($source,'function account_subscriptions_page(',$start);$detailRoute=substr($source,$start,$end-$start);check_renewal(substr_count($detailRoute,'$router->get(')===1&&strpos($detailRoute,'$router->post(')===false,'详情路由提取边界保持');eval($detailRoute);register_renewal_routes($router,$pdo,$config);
function renewal_get($path){global $pdo,$router;$before=$pdo->query('SELECT total_changes()')->fetchColumn();$pdo->exec('PRAGMA query_only=ON');$GLOBALS['body']='';try{$router->dispatch('GET',$path);}finally{$pdo->exec('PRAGMA query_only=OFF');check_renewal($before==$pdo->query('SELECT total_changes()')->fetchColumn(),'GET 只读 '.$path);}return $GLOBALS['body'];}
$_GET=array();$list=account_orders_page($pdo,1,$config);
check_renewal(substr_count($list,'href="/orders/PARENT/renew"')===1&&strpos($list,'/ACCOUNT/renew')===false&&strpos($list,'/NO-DELIVERY/renew')===false&&strpos($list,'PRIVATE')===false,'默认订单卡仅合资格本人 VPN 有续费入口');
$body=renewal_get('/orders/PARENT/renew');
check_renewal(strpos($body,'value="original" checked')!==false&&strpos($body,'¥198.00')!==false&&strpos($body,'¥168.00')===false,'默认原时长与原单价，不使用优惠后总价');
check_renewal(strpos($body,'value="plan:2"')!==false&&strpos($body,'¥28.00')!==false&&strpos($body,'plan:3')===false&&strpos($body,'299.00')===false,'仅同商品换时长，不重复当前同天数现价');
check_renewal(strpos($body,'价格来源：原订单价格快照')!==false&&strpos($body,'当前同商品方案价格')!==false&&strpos($body,'付款不等于续费已完成')!==false,'确认页价格来源、金额和人工处理说明');
check_renewal(strpos($body,'未过期从原到期时间顺延')!==false&&strpos($body,'已过期从管理员确认续费成功时起算')!==false&&strpos($body,'不重复使用原订单优惠券')!==false,'确认页明示起算规则与不重复原优惠');
preg_match('/name="request_key" value="([a-f0-9]+)"/',$body,$match);check_renewal(isset($match[1])&&strlen($match[1])===64,'请求键与真实服务64位契约一致');
foreach(array('PRIVATE','ACCOUNT','NO-DELIVERY') as $no){$b=renewal_get('/orders/'.$no.'/renew');check_renewal(strpos($b,'name="option_key"')===false,'不合资格不可报价 '.$no);}
$child=$base;$child[0]=10;$child[1]='RENEWAL';$child[5]='renewal';$child[6]=1;$child[12]=19800;$child[13]='pending_payment';$s->execute($child);
$pdo->exec("INSERT INTO order_renewals VALUES(10,365,'original',19800,NULL,NULL,NULL,NULL)");
$quote=(new RenewalService($pdo,$config))->quote('PARENT',1);check_renewal(strpos(renewal_parent_action($quote),'去付款')!==false&&strpos(renewal_parent_action($quote),'/orders/RENEWAL')!==false,'原卡活跃未付款子单显示去付款');
$b=renewal_get('/orders/PARENT/renew');check_renewal(strpos($b,'name="option_key"')===false&&strpos($b,'已有续费订单')!==false,'已有活跃续费不得再次选购');
$b=renewal_get('/orders/RENEWAL');check_renewal(strpos($b,'/payment/start/RENEWAL/alipay')!==false&&strpos($b,'/payment/start/RENEWAL/wxpay')!==false&&strpos($b,'/orders/RENEWAL/balance-pay')!==false&&strpos($b,'/orders/RENEWAL/cancel')!==false,'待付子单复用全部原付款路由和取消');
check_renewal(strpos($b,'交付记录待核对')===false&&strpos($b,'class="order-secret-row')===false,'续费详情优先分支不解密或渲染普通交付');
$pdo->exec("UPDATE orders SET status='pending_delivery' WHERE id=10");$quote=(new RenewalService($pdo,$config))->quote('PARENT',1);check_renewal(strpos(renewal_parent_action($quote),'续费处理中')!==false,'已付未确认原卡显示续费处理中');
$b=renewal_get('/orders/RENEWAL');check_renewal(strpos($b,'/orders/RENEWAL/renewal-refund')!==false&&strpos($b,'/balance-pay')===false&&strpos($b,'temporary-subscription')===false,'处理中允许退款申请但无临时订阅或重复付款');
$d=(new RenewalService($pdo,$config))->detail('RENEWAL',1);$adminStart=strpos($source,"\$router->get('/admin/orders/{no}',");$adminEnd=strpos($source,"\$router->post('/admin/orders/{no}/cancel'",$adminStart);eval(substr($source,$adminStart,$adminEnd-$adminStart));
$pdo->exec('ALTER TABLE users ADD COLUMN email TEXT DEFAULT "fixture@example.test"');
$admin=renewal_get('/admin/orders/RENEWAL');
check_renewal(strpos($admin,'/renewal-complete')!==false&&strpos($admin,'name="expected_version" value="4"')!==false&&strpos($admin,'我已在上游手工续费，标记续费成功')!==false,'后台人工确认提交当前交付版本');
$dstDetail=$d;$dstDetail['delivery']['expires_at']='2030-03-09 12:00:00';$dstDetail['renewal']['duration_days']=2;
$dstBody=renewal_detail_page($pdo,array('app'=>array('timezone'=>'America/New_York')),$dstDetail,1);
check_renewal(strpos($dstBody,'2030-03-11 13:00:00')!==false,'跨夏令时预览按固定秒数增加，与服务一致');
$pdo->exec('ALTER TABLE orders ADD COLUMN paid_payment_record_id INTEGER;CREATE TABLE payment_records(id INTEGER PRIMARY KEY,order_id INTEGER,method TEXT,gateway_type TEXT,status TEXT)');
$pdo->exec("UPDATE orders SET status='refunding',paid_payment_record_id=50 WHERE id=10;INSERT INTO payment_records VALUES(50,10,'epay_alipay','alipay','confirmed')");
$refundAdmin=renewal_get('/admin/orders/RENEWAL');
check_renewal(strpos($refundAdmin,'支付宝（外部支付）')!==false&&strpos($refundAdmin,'不会自动向支付宝、微信或其他外部渠道退钱')!==false&&strpos($refundAdmin,'我已在原渠道完成退款，确认记录')!==false,'后台外部退款展示渠道并要求原渠道实际退款后确认');
$pdo->exec("UPDATE payment_records SET gateway_type='balance',method='balance' WHERE id=50");$refundAdmin=renewal_get('/admin/orders/RENEWAL');
check_renewal(strpos($refundAdmin,'批准退款并退回消费余额')!==false&&strpos($refundAdmin,'请勿另行原渠道转账退款')!==false,'余额退款明确系统退余额，防止重复线下退款');
$pdo->exec("UPDATE payment_records SET gateway_type='alipay',method='epay_alipay' WHERE id=50;UPDATE orders SET status='pending_delivery' WHERE id=10");
foreach(array('/deliver"','/update-delivery','/revert-delivery') as $bad)check_renewal(strpos($admin,$bad)===false,'后台无普通交付操作 '.$bad);
check_renewal(strpos(renewal_parent_history($pdo,(new RenewalService($pdo,$config))->quote('PARENT',1)['parent'],1),'RENEWAL')!==false,'原详情可查看本人续费历史');
Security::$uid=2;$b=renewal_get('/orders/RENEWAL');check_renewal(strpos($b,'无权查看')!==false&&strpos($b,'PARENT')===false,'他人不可查看续费订单和父单');Security::$uid=1;
$_POST=array('_csrf'=>'bad','expected_version'=>'4','upstream_confirmed'=>'1');$caught=false;try{$router->dispatch('POST','/admin/orders/RENEWAL/renewal-complete');}catch(RuntimeException $ex){$caught=$ex->getMessage()==='CSRF';}check_renewal($caught,'人工确认强制CSRF');
$_POST=array('_csrf'=>'isolated-only','expected_version'=>array('4'),'upstream_confirmed'=>'1');$router->dispatch('POST','/admin/orders/RENEWAL/renewal-complete');check_renewal(strpos($GLOBALS['flash'],'交付版本')!==false,'无效版本在调用写服务前拒绝');
$_POST=array('_csrf'=>'isolated-only','reason'=>'隔离退款测试');$router->dispatch('POST','/orders/RENEWAL/renewal-refund');check_renewal(OrderService::$refunds===1,'处理中退款委托现有OrderService');
$pdo->exec("UPDATE orders SET status='delivered' WHERE id=10;UPDATE order_renewals SET previous_expires_at='2027-01-01 00:00:00',renewal_starts_at='2027-01-01 00:00:00',new_expires_at='2028-01-01 00:00:00',completed_at='2026-11-01 13:00:00'");
$b=renewal_get('/orders/RENEWAL');check_renewal(strpos($b,'续费成功')!==false&&strpos($b,'2028-01-01')!==false&&strpos($b,'/renewal-refund')===false&&strpos($b,'提交关联订单工单')!==false,'成功显示新期限及工单，不提供自动退款');
$router->dispatch('POST','/orders/RENEWAL/renewal-refund');check_renewal(OrderService::$refunds===1,'成功续费的直接退款POST也被拒绝');
$pdo->exec("UPDATE orders SET product_name_snapshot='<script>fixture</script>' WHERE id=10");$b=renewal_get('/orders/RENEWAL');check_renewal(strpos($b,'&lt;script&gt;fixture&lt;/script&gt;')!==false&&strpos($b,'<script>fixture')===false,'续费快照安全转义');
$pdo->exec("UPDATE orders SET product_name_snapshot='小云铺·畅享订阅',status='pending_payment' WHERE id=10");
check_renewal($pdo->query('SELECT status FROM orders WHERE id=1')->fetchColumn()==='delivered','所有页面渲染保持原单delivered');
Security::$uid=0;$caught=false;try{renewal_get('/orders/PARENT/renew');}catch(RuntimeException $ex){$caught=$ex->getMessage()==='AUTH';}check_renewal($caught,'续费GET必须用户鉴权');Security::$uid=1;
if($htmlMode){
    $view='list';foreach($_SERVER['argv'] as $arg)if(strpos($arg,'--view=')===0)$view=substr($arg,7);
    $pdo->exec("UPDATE order_renewals SET previous_expires_at=NULL,renewal_starts_at=NULL,new_expires_at=NULL,completed_at=NULL,duration_days=30,price_source='current',price_cents=2800;UPDATE orders SET duration_days_snapshot=30,unit_price_cents_snapshot=2800,total_amount_cents=2800,plan_name_snapshot='月卡',product_plan_id=2 WHERE id=10");
    if(in_array($view,array('list','quote'),true))$pdo->exec('DELETE FROM order_renewals;DELETE FROM orders WHERE id=10');
    if(in_array($view,array('list-processing','processing'),true))$pdo->exec("UPDATE orders SET status='pending_delivery' WHERE id=10");
    if($view==='admin'){
        require $root.'/app/Core/AdminView.php';
        $pdo->exec("ALTER TABLE orders ADD COLUMN paid_at TEXT; CREATE TABLE support_tickets(id INTEGER PRIMARY KEY,status TEXT); UPDATE orders SET status='pending_delivery',paid_at='2026-11-01 12:00:00' WHERE id=10");
        $content=renewal_get('/admin/orders/RENEWAL');
        $pdo->exec('PRAGMA query_only=ON');
        admin_page('续费详情隔离验收','<div class="admin-info-banner">内存夹具：仅查看人工确认入口，不提交上游操作或付款。</div>'.$content,'','/admin/orders/RENEWAL',$pdo,$config);
        exit;
    }
    if($view==='quote')$content=renewal_get('/orders/PARENT/renew');
    elseif(in_array($view,array('detail','processing'),true))$content=renewal_get('/orders/RENEWAL');
    else $content=account_orders_page($pdo,1,$config);
    $pdo->exec('ALTER TABLE users ADD COLUMN display_name TEXT DEFAULT "隔离验收用户"');
    foreach(array('user_nav_icon','home_outline_icon','account_overview_shell') as $name){
        $function=renewal_test_function($source,$name);
        // 仅夹具内替换身份读取点，复用真实外壳，不创建或伪造登录会话。
        $function=str_replace(array('__DIR__',"\$_SESSION['user_id']"),array(var_export($root.'/public',true),'Security::requireUser()'),$function);
        eval($function);
    }
    account_overview_shell('续费页面隔离验收',$content,'',0,$pdo,$config,'/orders',strpos($view,'list')===0?'':'order-detail');
}else print('RENEWAL PAGE TESTS PASSED ('.$checks.')'.PHP_EOL);
