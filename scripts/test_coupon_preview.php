<?php
// 所有优惠验证仅使用 SQLite 内存，不读取站点配置或占用真实优惠券。
if(PHP_SAPI!=='cli')exit(1);
ob_start();
$root=dirname(__DIR__);
require $root.'/app/Services/ActivityService.php';
require $root.'/app/Services/PromotionCampaignService.php';
require $root.'/app/Core/Router.php';
require $root.'/app/Core/Security.php';
function coupon_ok($ok,$label){if(!$ok)throw new LogicException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function flash($type,$message){}
function redirect($path){throw new RuntimeException('redirect '.$path);}
function post_csrf(){Security::checkCsrf(isset($_POST['_csrf'])?$_POST['_csrf']:null);}
class CouponPreviewPDO extends PDO {
    public $queries=array();
    public function prepare($sql,$options=array()) {$this->queries[]=$sql;return parent::prepare(str_replace(array(' FOR UPDATE',' LOCK IN SHARE MODE'),'',$sql),$options);}
}
$pdo=new CouponPreviewPDO('sqlite::memory:',null,null,array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));
$pdo->exec('CREATE TABLE products(id INTEGER,name TEXT,status TEXT,product_type TEXT,traffic TEXT,region TEXT,device_limit INTEGER,duration_days INTEGER,vpn_category_id INTEGER,sort_order INTEGER);CREATE TABLE product_plans(id INTEGER,product_id INTEGER,name TEXT,status TEXT,price_cents INTEGER,duration_days INTEGER,sort_order INTEGER);CREATE TABLE coupons(id INTEGER PRIMARY KEY,code TEXT,scope TEXT,user_id INTEGER,status TEXT,used_quantity INTEGER,used_order_id INTEGER,starts_at TEXT,expires_at TEXT,total_quantity INTEGER,min_amount_cents INTEGER,product_plan_id INTEGER,per_user_limit INTEGER,coupon_type TEXT,discount_percent INTEGER,amount_cents INTEGER,max_discount_cents INTEGER);CREATE TABLE order_promotions(id INTEGER,coupon_id INTEGER,user_id INTEGER,status TEXT);CREATE TABLE promotion_campaigns(id INTEGER,coupon_id INTEGER,status TEXT,starts_at TEXT,ends_at TEXT);CREATE TABLE promotion_campaign_plans(campaign_id INTEGER,product_plan_id INTEGER);CREATE TABLE vpn_categories(id INTEGER,name TEXT,description TEXT,sort_order INTEGER);CREATE TABLE users(id INTEGER,password_changed_at TEXT)');
$pdo->exec("INSERT INTO products VALUES(1,'测试订阅','active','vpn','100G','测试区',2,30,NULL,0),(2,'测试账号','active','account','','',1,0,NULL,0);INSERT INTO product_plans VALUES(7,1,'月度','active',1999,30,0),(8,2,'账号','active',2900,0,0),(9,1,'下架','inactive',1999,30,0);INSERT INTO users VALUES(1,NULL)");
$insert=$pdo->prepare('INSERT INTO coupons VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$base=array(1,'SAVE','public',null,'active',0,null,'2020-01-01 00:00:00','2099-01-01 00:00:00',100,0,0,1,'percent',20,0,0);
$insert->execute($base);
function add_coupon($id,$code,$changes=array()){global $insert,$base;$row=$base;$row[0]=$id;$row[1]=$code;foreach($changes as $index=>$value)$row[$index]=$value;$insert->execute($row);}
add_coupon(2,'OWN',array(2=>'personal',3=>1,14=>0,13=>'fixed',15=>500));
add_coupon(3,'OTHER',array(2=>'personal',3=>2));
add_coupon(4,'EXPIRED',array(8=>'2020-01-01 00:00:00'));
add_coupon(5,'FUTURE',array(7=>'2098-01-01 00:00:00'));
add_coupon(6,'EMPTY',array(5=>100));
add_coupon(7,'MIN',array(10=>2000));
add_coupon(8,'PLAN',array(11=>9));
add_coupon(9,'LIMIT');$pdo->exec('INSERT INTO order_promotions VALUES(1,9,1,"applied")');
add_coupon(10,'CAP',array(16=>100));
add_coupon(11,'FREE',array(13=>'fixed',15=>9999));
add_coupon(12,'BOUND');$pdo->exec('INSERT INTO promotion_campaigns VALUES(1,12,"draft","2020-01-01 00:00:00","2099-01-01 00:00:00");INSERT INTO promotion_campaign_plans VALUES(1,7)');
$service=new ActivityService($pdo);$before=$pdo->query('SELECT total_changes()')->fetchColumn();
$pdo->exec('PRAGMA query_only=ON');
$preview=$service->previewCoupon(1,1,7,'SAVE');
coupon_ok($preview===array('original_amount_cents'=>1999,'discount_amount_cents'=>399,'total_amount_cents'=>1600),'百分比优惠按整数分向下取整');
coupon_ok($service->previewCoupon(1,1,7,' OWN ')['discount_amount_cents']===500,'个人券仅限本人并支持去除两端空格');
coupon_ok($service->previewCoupon(1,1,7,'CAP')['discount_amount_cents']===100,'优惠封顶');
coupon_ok($service->previewCoupon(1,1,7,'FREE')['total_amount_cents']===0,'全额优惠不产生负金额');
foreach(array('OTHER','EXPIRED','FUTURE','EMPTY','MIN','PLAN','LIMIT','BOUND','missing','group:1','',array('SAVE')) as $code){$failed=false;try{$service->previewCoupon(1,1,7,$code);}catch(InvalidArgumentException $e){$failed=true;}catch(RuntimeException $e){$failed=true;}coupon_ok($failed,'拒绝无效、越权或不满足条件的优惠参数 '.(is_string($code)?$code:'array'));}
foreach(array(array(2,8),array(1,9),array(2,7)) as $ids){$failed=false;try{$service->previewCoupon(1,$ids[0],$ids[1],'SAVE');}catch(InvalidArgumentException $e){$failed=true;}coupon_ok($failed,'拒绝账号、下架及跨商品方案');}
$pdo->exec('PRAGMA query_only=OFF');
coupon_ok($before==$pdo->query('SELECT total_changes()')->fetchColumn(),'试算成功与失败均无数据库写入');
coupon_ok(!preg_match('/FOR UPDATE|LOCK IN SHARE MODE/i',implode("\n",$pdo->queries)),'试算不锁定优惠券或活动配额');
$pdo->beginTransaction();$actual=$service->prepareCoupon(1,'SAVE',7,1999);$pdo->rollBack();
coupon_ok($actual['discount']===$preview['discount_amount_cents'],'试算与正式下单共用核算规则');
$pdo->exec('UPDATE coupons SET used_quantity=100 WHERE id=1');$failed=false;$pdo->beginTransaction();try{$service->prepareCoupon(1,'SAVE',7,1999);}catch(RuntimeException $e){$failed=true;}$pdo->rollBack();
coupon_ok($failed,'试算后配额变化仍由下单重新拒绝');
$pdo->exec('UPDATE coupons SET used_quantity=0 WHERE id=1;UPDATE promotion_campaigns SET status="published" WHERE id=1');
coupon_ok($service->previewCoupon(1,1,7,'BOUND')['total_amount_cents']===1600,'已发布活动仅允许绑定的有效方案');
$pdo->exec('DELETE FROM promotion_campaign_plans');$failed=false;try{$service->previewCoupon(1,1,7,'BOUND');}catch(RuntimeException $e){$failed=true;}coupon_ok($failed,'活动范围修改后试算立即拒绝');
$source=file_get_contents($root.'/public/index.php');$start=strpos($source,"\$router->post('/order-confirm/validate-coupon',");$end=strpos($source,"\$router->post('/orders',",$start);
$router=new Router();eval(substr($source,$start,$end-$start));
$_SESSION=array('user_id'=>1,'user_password_changed_at'=>null,'_csrf'=>'fixture-csrf');
$_POST=array('_csrf'=>'fixture-csrf','product_id'=>'1','plan_id'=>'7','promotion_code'=>'SAVE');
ob_start();$router->dispatch('POST','/order-confirm/validate-coupon');$response=json_decode(ob_get_clean(),true);
coupon_ok($response['ok']&&$response['amounts']['total_amount_cents']===1600,'正式验证路由返回服务器计算金额');
$_POST['promotion_code']=array('SAVE');ob_start();$router->dispatch('POST','/order-confirm/validate-coupon');$response=json_decode(ob_get_clean(),true);
coupon_ok(!$response['ok']&&http_response_code()===422,'验证接口拒绝数组篡改');
