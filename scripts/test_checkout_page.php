<?php
// 只执行真实确认订单 GET，数据使用 SQLite 内存夹具，不读取站点配置。
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__);
require $root.'/app/Core/Router.php';
require $root.'/app/Core/Security.php';
require $root.'/app/Core/AccountProductPages.php';
function e($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function money($cents){return '¥'.number_format($cents/100,2);}
function page($title,$body,$config){$GLOBALS['checkout_body']=$body;}
function flash($type,$text){}
function redirect($url){throw new RuntimeException($url);}
function checkout_ok($ok,$label){if(!$ok)throw new LogicException('FAIL '.$label);if(!in_array('--html',$_SERVER['argv'],true))echo 'PASS '.$label.PHP_EOL;}
$pdo=new PDO('sqlite::memory:',null,null,array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));
$pdo->exec('CREATE TABLE users(id INTEGER,password_changed_at TEXT);INSERT INTO users VALUES(1,NULL);CREATE TABLE products(id INTEGER,name TEXT,description TEXT,product_type TEXT,status TEXT,account_platform TEXT,account_specification TEXT,account_after_sales TEXT);CREATE TABLE product_plans(id INTEGER,product_id INTEGER,name TEXT,duration_days INTEGER,price_cents INTEGER,status TEXT)');
$pdo->exec("INSERT INTO products VALUES(1,'隔离测试订阅','真实确认页模板测试','vpn','active','','',''),(2,'隔离测试账号','公开说明','account','active','虚构平台','单个账号','按公开规则人工处理');INSERT INTO product_plans VALUES(7,1,'年卡',365,19800,'active'),(8,2,'标准规格',0,2900,'active')");
$_SESSION=array('user_id'=>1,'user_password_changed_at'=>null,'_csrf'=>'fixture-csrf');
$config=array();$router=new Router();$source=file_get_contents($root.'/public/index.php');
foreach(explode("\n",$source)as $line)if(strpos($line,"\$router->get('/order-confirm',")===0)eval($line);
function run_checkout($product,$plan,$extra=array()){
    global $router,$pdo;
    $_SERVER['REQUEST_URI']='/order-confirm';$_GET=array_merge(array('product_id'=>(string)$product,'plan_id'=>(string)$plan),$extra);
    $before=$pdo->query('SELECT total_changes()')->fetchColumn();$pdo->exec('PRAGMA query_only=ON');
    try{$router->dispatch('GET','/order-confirm');}finally{$pdo->exec('PRAGMA query_only=OFF');}
    checkout_ok($before==$pdo->query('SELECT total_changes()')->fetchColumn(),'购前确认GET不写入数据库');
    return $GLOBALS['checkout_body'];
}
$body=run_checkout(1,7);
checkout_ok(strpos($body,'action="/orders"')!==false&&strpos($body,'name="_csrf" value="fixture-csrf"')!==false,'下单目标和CSRF沿用正式路由');
checkout_ok(strpos($body,'name="plan_id" value="7"')!==false&&strpos($body,'¥198.00')!==false&&strpos($body,'365 天')!==false,'确认页使用真实方案、价格和期限');
checkout_ok(strpos($body,'purchase-mobile-action')!==false&&strpos($body,'提交后核算')!==false,'手机底部金额与提交按钮保留且不伪造优惠结果');
checkout_ok(strpos($body,'name="promotion_code"')!==false&&strpos($body,'name="note"')!==false,'VPN优惠码及订单备注保留');
$account=run_checkout(2,8);
checkout_ok(strpos($account,'name="quantity" value="1"')!==false&&strpos($account,'name="promotion_code"')===false&&strpos($account,'单个账号')!==false,'账号商品仍单份且不开放优惠');
checkout_ok(strpos(run_checkout(2,8,array('quantity'=>'2')),'账号商品不参与优惠或拼团')!==false,'拒绝账号数量篡改');
checkout_ok(strpos(run_checkout(1,8),'套餐或期限方案不存在')!==false,'拒绝跨商品方案');
checkout_ok(strpos($source,'$no=(new OrderService($pdo))->create($uid,')!==false,'下单仍调用原OrderService');
checkout_ok(preg_match('/name="request_key" value="[a-f0-9]{64}"/',$body)===1,'确认表单附带订单幂等键');
if(in_array('--html',$_SERVER['argv'],true))echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/style.css"><link rel="stylesheet" href="/assets/customer-warm.css"><body class="user-page checkout-body pc-commerce public-page"><main class="user-main">'.$body.'</main></body></html>';
