<?php
/** 仅供 CLI 测试桥使用，不加载 Bootstrap、配置、生产测试路由、工作进程或付款网关。
 * stdin/stdout 使用 JSON，必须事先独立创建测试数据库并完成迁移。
 * 首页使用真实 index 路由与 page() 外壳，包含页头和静态资源。
 * 其它公开页及订单回执使用简化外壳；后台、公开活动及状态接口、
 * 确认订单 GET 与下单 POST 均执行生产路由。
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
date_default_timezone_set('Asia/Shanghai');
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();
$result = array('status'=>200, 'redirect'=>null, 'body'=>'', 'session'=>array());
register_shutdown_function(function () {
    global $result, $pdo;
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $error = error_get_last();
    if ($error && in_array($error['type'], array(E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR), true)) {
        $result['status']=500; $result['error']='Fixture fatal error; inspect PHP stderr.';
    }
    $body=ob_get_clean();
    $result['body']=$body;
    $result['session']=isset($_SESSION) ? $_SESSION : array();
    if (http_response_code() >= 400) $result['status']=http_response_code();
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
});
function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function money($cents) { return '¥'.number_format($cents/100,2); }
function flash($type,$text) { $_SESSION['_fixture_flash']=array($type,$text); }
function get_flash($type=null) {
    $v=isset($_SESSION['_fixture_flash'])?$_SESSION['_fixture_flash']:null;
    if ($type!==null && (!$v || $v[0]!==$type)) return null;
    unset($_SESSION['_fixture_flash']); return $type===null?$v:$v[1];
}
// 此处有意使用 exit：生产路由会捕获 redirect() 周围的 Throwable，
// 抛异常会被误判为保存失败；关闭回调仍会序列化会话。
function redirect($url) {
    if (!is_string($url) || !preg_match('~\A/(?!/)[^\r\n]*\z~D',$url)) throw new LogicException('Unsafe fixture redirect.');
    $GLOBALS['result']['status']=302; $GLOBALS['result']['redirect']=$url; exit;
}
function post_csrf() { Security::checkCsrf(isset($_POST['_csrf'])?$_POST['_csrf']:null); }
function page($title,$body,$config) {
    global $pdo;
    $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
    if ($path==='/') { fixture_production_page($title,$body,$config); return; }
    $flash=get_flash(); $notice=$flash?'<p role="status" data-fixture-flash="'.e($flash[0]).'">'.e($flash[1]).'</p>':'';
    if (strpos($path,'/admin')===0) { admin_page($title,$body,$notice,$path,$pdo,$config); return; }
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($title).'</title><link rel="stylesheet" href="/assets/style.css"><link rel="stylesheet" href="/assets/customer-warm.css"></head><body class="user-page public-page pc-commerce checkout-body"><main class="user-main"><p data-fixture-wrapper>本地隔离验收 · 简化前台外壳（非首页还原验收）</p>'.$notice.$body.'</main></body></html>';
}
/** 提取一条完整的生产路由注册语句；按词法标记计算嵌套，忽略字符串内的括号。 */
function fixture_route_source($source,$method,$path) {
    $needle='$router->'.$method."('".$path."',";
    $start=strpos($source,$needle);
    if ($start===false) throw new LogicException('Production route not found: '.$path);
    $tokens=token_get_all('<?php '.substr($source,$start)); $code=''; $depth=0;
    foreach (array_slice($tokens,1) as $token) {
        $text=is_array($token)?$token[1]:$token; $code.=$text;
        if (!is_array($token)) {
            if (strpos('({[',$text)!==false) $depth++;
            if (strpos(')}]',$text)!==false) $depth--;
            if ($text===';' && $depth===0) return $code;
        }
    }
    throw new LogicException('Unbalanced production route.');
}
/** 不启动 index，只提取指定函数，保留字符串字面量和资源路径。 */
function fixture_home_function($source,$name,$publicDir) {
    $start=strpos($source,'function '.$name.'(');
    if ($start===false) throw new LogicException('Production function not found: '.$name);
    $tokens=token_get_all('<?php '.substr($source,$start)); $code=''; $depth=0; $opened=false;
    foreach (array_slice($tokens,1) as $token) {
        $text=is_array($token)?$token[1]:$token;
        if (is_array($token) && $token[0]===T_DIR) $text=var_export($publicDir,true);
        $code.=$text;
        if (!is_array($token) && $text==='{') { $depth++; $opened=true; }
        if (!is_array($token) && $text==='}') {
            $depth--; if ($opened && $depth===0) {
                if ($name==='page') $code=preg_replace('/\\Afunction page\\(/','function fixture_production_page(',$code,1);
                return $code;
            }
        }
    }
    throw new LogicException('Unbalanced production function: '.$name);
}
function fixture_insert($table,$values) {
    global $pdo;
    $columns=array_keys($values);
    $s=$pdo->prepare('INSERT INTO '.$table.' (`'.implode('`,`',$columns).'`) VALUES('.implode(',',array_fill(0,count($columns),'?')).')');
    $s->execute(array_values($values)); return (int)$pdo->lastInsertId();
}
try {
    $dsn=getenv('CHAOXI_TEST_DSN');
    if (!$dsn || strpos($dsn,'mysql:')!==0 || preg_match_all('/(?:^|;)\s*dbname\s*=/i',substr($dsn,6))!==1 || !preg_match('/(?:^|;)dbname=(chaoxi_promotion_test_[A-Za-z0-9_]+)(?:;|$)/',substr($dsn,6),$match)) throw new LogicException('Explicit isolated chaoxi_promotion_test_ DSN required.');
    $pdo=new PDO($dsn,getenv('CHAOXI_TEST_USER'),getenv('CHAOXI_TEST_PASSWORD'),array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false));
    $actual=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($actual!==$match[1] || !preg_match('/\Achaoxi_promotion_test_[A-Za-z0-9_]+\z/D',$actual)) throw new LogicException('Actual database mismatch; access refused.');
    $root=dirname(__DIR__);
    spl_autoload_register(function($class)use($root){
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/D',$class)) return;
        foreach (array('/app/Core/','/app/Services/') as $dir) if(is_file($root.$dir.$class.'.php')){require_once $root.$dir.$class.'.php';return;}
    });
    foreach (array('Security','Router','AdminView','AdminExtraPages','AdminCouponPages','AdminPromotionPages','PromotionCampaignPages','AccountProductPages') as $file) require_once $root.'/app/Core/'.$file.'.php';
    $request=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    if (!is_array($request)) throw new LogicException('JSON request required.');
    $_SESSION=isset($request['session'])&&is_array($request['session'])?$request['session']:array();
    $action=isset($request['action'])?$request['action']:'request';
    $config=array('app'=>array('name'=>'小云店'));
    if ($action==='init') {
        // 只追加带唯一标记的夹具数据，不清空、删除表或修改其它 agent 的数据。
        $tag='PUI_'.bin2hex(random_bytes(6)); $now=date('Y-m-d H:i:s');
        $base=array('created_at'=>$now,'updated_at'=>$now);
        $pdo->beginTransaction();
        $admin=fixture_insert('admin_users',$base+array('email'=>$tag.'@admin.invalid','password_hash'=>'!disabled','display_name'=>$tag));
        $user=fixture_insert('users',$base+array('email'=>$tag.'@user.invalid','password_hash'=>'!disabled','display_name'=>$tag));
        $category=$pdo->query("SELECT id FROM vpn_categories WHERE name='基础线路'")->fetchColumn();
        if(!$category)$category=fixture_insert('vpn_categories',$base+array('name'=>'基础线路','description'=>'适合日常浏览与轻量使用'));
        if (!empty($request['uncategorized'])) $category=null;
        $product=fixture_insert('products',$base+array('name'=>$tag.' 订阅','description'=>'无业务数据、无支付','vpn_category_id'=>$category,'product_type'=>'vpn','traffic'=>'100GB','region'=>'测试地区','device_limit'=>2,'duration_days'=>30,'price_cents'=>10000,'status'=>'active'));
        $plans=array();
        foreach (array('入选月卡','同分类未勾选季卡') as $name) $plans[]=fixture_insert('product_plans',$base+array('product_id'=>$product,'name'=>$name,'duration_days'=>30,'price_cents'=>10000,'status'=>'active'));
        $coupon=fixture_insert('coupons',$base+array('scope'=>'public','code'=>'EXIST'.$product,'name'=>$tag.' 已有券','coupon_type'=>'percent','discount_percent'=>20,'status'=>'active','total_quantity'=>-1,'per_user_limit'=>100));
        $pdo->commit();
        $_SESSION=array('admin_id'=>$admin,'admin_name'=>$tag,'user_id'=>$user,'user_password_changed_at'=>'','_csrf'=>bin2hex(random_bytes(32)),'promotion_ui_fixture'=>array('tag'=>$tag,'product'=>$product,'category'=>(int)$category,'plans'=>$plans,'coupon'=>$coupon));
        $result['data']=$_SESSION['promotion_ui_fixture']; $result['database']=$actual; exit;
    }
    if (empty($_SESSION['promotion_ui_fixture'])) throw new LogicException('Initialize isolated fixtures first.');
    $fixture=$_SESSION['promotion_ui_fixture'];
    if ($action==='add_plan') {
        $result['data']=array('plan'=>fixture_insert('product_plans',array('product_id'=>$fixture['product'],'name'=>'后加方案 '.bin2hex(random_bytes(4)),'duration_days'=>30,'price_cents'=>10000,'status'=>'active','created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')))); exit;
    }
    if ($action==='expire') {
        $id=PromotionCampaignService::parseId($request['id']);
        $s=$pdo->prepare('UPDATE promotion_campaigns SET starts_at=?,ends_at=? WHERE id=? AND created_by=? AND name LIKE ?');
        $s->execute(array(date('Y-m-d H:i:s',time()-7200),date('Y-m-d H:i:s',time()-1),$id,$_SESSION['admin_id'],$fixture['tag'].'%'));
        if ($s->rowCount()!==1) throw new LogicException('Can only expire this run campaign.'); exit;
    }
    if ($action==='inspect') {
        $id=PromotionCampaignService::parseId($request['id']);
        $s=$pdo->prepare('SELECT * FROM orders WHERE user_id=? ORDER BY id');$s->execute(array($_SESSION['user_id']));
        $result['data']=array('campaign'=>(new PromotionCampaignService($pdo))->get($id),'orders'=>$s->fetchAll()); exit;
    }
    if ($action!=='request') throw new LogicException('Unknown fixture action.');
    $url=isset($request['url'])?$request['url']:'';
    if (!is_string($url) || !preg_match('~\Ahttp://127\.0\.0\.1:8081/(?!/)[^\r\n]*\z~D',$url)) throw new LogicException('Only local 8081 requests allowed.');
    $method=isset($request['method'])?$request['method']:'GET';
    if (!in_array($method,array('GET','POST'),true)) throw new LogicException('Method refused.');
    $_SERVER['REQUEST_METHOD']=$method; $_SERVER['REQUEST_URI']=substr($url,strlen('http://127.0.0.1:8081')); $_SERVER['HTTP_HOST']='127.0.0.1:8081';
    parse_str((string)parse_url($url,PHP_URL_QUERY),$_GET); parse_str(isset($request['post'])?$request['post']:'',$_POST);
    $path=parse_url($url,PHP_URL_PATH); $router=new Router();
    register_admin_promotion_routes($router,$pdo,$config);
    $source=file_get_contents($root.'/public/index.php');
    foreach (array(array('get','/promotions/{id}/status'),array('get','/promotions/{id}'),array('get','/order-confirm'),array('post','/orders')) as $route) eval(fixture_route_source($source,$route[0],$route[1]));
    // 同时使用真实首页正文与页面外壳，保留页头定位、视口样式类、
    // 资源加载顺序、移动导航、首屏内容及弹窗顶层关系。
    foreach (array('user_nav_icon','user_nav_item','home_outline_icon','page') as $name) eval(fixture_home_function($source,$name,$root.'/public'));
    eval(fixture_route_source($source,'get','/'));
    // 只读订单回执使用真实 OrderService 数据，有意不提供付款界面或路由。
    $router->get('/orders/{no}',function($no)use($pdo,$config){$order=(new OrderService($pdo))->getForUser($no,Security::requireUser());if(!$order){http_response_code(404);return;}page('隔离订单回执','<h1>隔离订单回执（不付款）</h1><p data-order-no>'.e($order['order_no']).'</p><p data-order-original>'.(int)$order['original_amount_cents'].'</p><p data-order-discount>'.(int)$order['discount_amount_cents'].'</p><p data-order-total>'.(int)$order['total_amount_cents'].'</p><p data-order-status>'.e($order['status']).'</p>',$config);});
    // 生产下单 POST 失败时跳转至此，不访问真实商城。
    $router->get('/products',function()use($config){page('隔离订单拒绝','<h1>订单未创建</h1>',$config);});
    $router->dispatch($method,$path);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $result['status']=500;
    // PDO 连接错误可能包含凭据或主机信息，禁止将其序列化输出。
    $result['error']=$error instanceof PDOException?'Isolated database unavailable or schema incomplete.':$error->getMessage();
}
