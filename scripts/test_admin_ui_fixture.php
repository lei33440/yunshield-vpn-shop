<?php
// 仅 CLI 隔离测试：执行真实 index 路由，替换启动配置与重定向输出，不读取站点配置。
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Shanghai');
ob_start();
$result = array('status'=>200, 'redirect'=>null);
register_shutdown_function(function () {
    global $result;
    $error = error_get_last();
    if ($error && in_array($error['type'], array(E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR), true)) $result['status']=500;
    $result['body']=ob_get_clean();
    $result['session']=isset($_SESSION)?$_SESSION:array();
    if (http_response_code()>=400) $result['status']=http_response_code();
    echo json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
});
function redirect($url) {
    if (!preg_match('~\A/(?!/)[^\r\n]*\z~D', $url)) throw new LogicException('测试重定向必须留在本地。');
    $GLOBALS['result']['redirect']=$url;
    $GLOBALS['result']['status']=302;
    exit;
}
function admin_ui_eval($source, $directory) {
    $code='';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && $token[0]===T_OPEN_TAG) continue;
        $code.=is_array($token) ? ($token[0]===T_DIR?var_export($directory,true):$token[1]) : $token;
    }
    return $code;
}
try {
    $dsn=getenv('CHAOXI_TEST_DSN');
    if (!$dsn || !preg_match('/\Amysql:host=(?:db|127\.0\.0\.1|localhost);(?:port=\d+;)?dbname=(chaoxi_wallet_test_adminui_[A-Za-z0-9_]+);charset=utf8mb4\z/D',$dsn,$match)) throw new LogicException('必须明确指定本地 adminui 隔离库。');
    $fixturePdo=new PDO($dsn,getenv('CHAOXI_TEST_USER'),getenv('CHAOXI_TEST_PASSWORD'),array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false));
    if ($fixturePdo->query('SELECT DATABASE()')->fetchColumn()!==$match[1]) throw new LogicException('测试库不匹配。');
    $root=dirname(__DIR__);
    spl_autoload_register(function ($class) use ($root) {
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/D',$class)) return;
        foreach (array('/app/Core/','/app/Services/') as $directory) if (is_file($root.$directory.$class.'.php')) { require_once $root.$directory.$class.'.php'; return; }
    });
    $request=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    $_SESSION=isset($request['session'])?$request['session']:array();
    $fixtureConfig=array('app'=>array('name'=>'小云店','base_url'=>'http://127.0.0.1:8081','timezone'=>'Asia/Shanghai','debug'=>false),'security'=>array('encryption_key'=>base64_encode(str_repeat('a',32)),'session_name'=>'admin_ui_isolated'),'subboost'=>array('url'=>'','api_secret'=>''),'update'=>array('enabled'=>false,'install_enabled'=>false),'db'=>array('dsn'=>$dsn,'username'=>getenv('CHAOXI_TEST_USER'),'password'=>getenv('CHAOXI_TEST_PASSWORD')));
    if (isset($request['action']) && $request['action']==='init') {
        require_once $root.'/scripts/migrate.php';
        run_migrations($fixturePdo,$root);
        $now=date('Y-m-d H:i:s');
        $tag='UI'.bin2hex(random_bytes(4));
        $fixturePdo->prepare('INSERT INTO admin_users(email,password_hash,display_name,created_at,updated_at) VALUES(?,?,?,?,?)')->execute(array($tag.'@admin.invalid','!disabled','测试管理员',$now,$now));
        $aid=(int)$fixturePdo->lastInsertId();
        $fixturePdo->prepare('INSERT INTO users(email,password_hash,display_name,created_at,updated_at) VALUES(?,?,?,?,?)')->execute(array($tag.'@user.invalid','!disabled','云间旅人',$now,$now));
        $uid=(int)$fixturePdo->lastInsertId();
        $fixturePdo->prepare('INSERT INTO products(name,description,traffic,region,device_limit,duration_days,price_cents,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute(array('轻享年包','UI 隔离测试商品','100 GB','基础线路',5,365,6800,'active',$now,$now));
        $pid=(int)$fixturePdo->lastInsertId();
        $fixturePdo->prepare('INSERT INTO product_plans(product_id,name,duration_days,price_cents,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?)')->execute(array($pid,'年卡',365,6800,'active',$now,$now));
        $plan=(int)$fixturePdo->lastInsertId();
        $orders=new OrderService($fixturePdo);
        $numbers=array();
        for ($i=0;$i<15;$i++) $numbers[]=$orders->create($uid,$pid,$plan,'UI 隔离测试');
        $orders->confirmPayment($numbers[1],$aid);
        $orders->confirmPayment($numbers[2],$aid);
        $orders->requestRefund($numbers[2],$uid,'测试退款申请');
        $orders->cancel($numbers[3],'admin',$aid,'测试取消');
        $announcements=new AnnouncementService($fixturePdo);
        for ($i=1;$i<=21;$i++) $announcements->create($aid,'system','本地测试公告 '.$tag.' '.$i,'仅供 UI 测试，不发布到实际站点。',$now);
        $_SESSION=array('admin_id'=>$aid,'admin_name'=>'测试管理员','_csrf'=>bin2hex(random_bytes(32)),'admin_ui_fixture'=>true);
        $result['data']=array('orders'=>$numbers,'user'=>$uid,'product'=>$pid);
        $result['database']=$match[1];
        exit;
    }
    if (empty($_SESSION['admin_ui_fixture'])) throw new LogicException('测试会话尚未初始化。');
    $url=parse_url($request['url']);
    if (($url['scheme']??'')!=='http' || ($url['host']??'')!=='127.0.0.1' || ($url['port']??0)!==8081 || !preg_match('~\A/admin(?:/|\z)~',$url['path'])) throw new LogicException('仅允许本地后台路由。');
    $path=$url['path'];
    if (preg_match('~/system/update|/email-campaigns/.+/(send|start|resume)|/payment~',$path)) throw new LogicException('禁止外部支付、邮件发送与更新。');
    $_GET=array(); parse_str($url['query']??'',$_GET);
    $_POST=array(); parse_str($request['post']??'',$_POST);
    $_SERVER['REQUEST_URI']=$path.(isset($url['query'])?'?'.$url['query']:'');
    $_SERVER['REQUEST_METHOD']=$request['method']??'GET';
    $_SERVER['REMOTE_ADDR']='127.0.0.1';
    $_SERVER['HTTP_HOST']='127.0.0.1:8081';
    $_SERVER['SCRIPT_NAME']='/index.php';
    // 保留生产 helper；仅将原生 redirect 改名，测试通过 JSON 传递重定向。
    $bootstrap=file_get_contents($root.'/app/Core/Bootstrap.php');
    $bootstrap=str_replace('function redirect(', 'function unused_native_redirect(', $bootstrap);
    eval(admin_ui_eval($bootstrap,$root.'/app/Core'));
    $source=file_get_contents($root.'/public/index.php');
    $source=str_replace(array("require_once \$projectRoot . '/app/Core/Bootstrap.php';", "require \$projectRoot . '/app/Core/Bootstrap.php';"),'',$source);
    $source=str_replace(array('$config = Bootstrap::init();','$pdo = Database::connect($config);'),array('$config = $fixtureConfig;','$pdo = $fixturePdo;'),$source);
    eval(admin_ui_eval($source,$root.'/public'));
} catch (Throwable $error) {
    $result['status']=500;
    $result['error']=$error instanceof PDOException?'隔离数据库或结构错误。':$error->getMessage();
}
