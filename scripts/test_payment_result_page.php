<?php
// 无数据库测试：旧付款结果入口仅授权跳转，不读取交付凭据。
if (PHP_SAPI !== 'cli') exit(1);
$root=dirname(__DIR__);
require $root.'/app/Core/Router.php';
class Security { public static function requireUser() { return $GLOBALS['test_user']; } }
class OrderService {
    public function __construct($pdo) {}
    public function getForUser($no,$uid) {
        $o=$GLOBALS['test_order'];
        return $o && $o['order_no']===$no && $o['user_id']===$uid ? $o : false;
    }
}
class ResultPDO {
    public $reads=0;
    public function prepare($sql) { $this->reads++;throw new RuntimeException('兼容入口不应查询交付内容'); }
}
class ResultRedirect extends Exception {
    public $url;
    public function __construct($url) { $this->url=$url; }
}
function redirect($url) { throw new ResultRedirect($url); }
function page($title,$body,$config) { $GLOBALS['test_body']=$body; }
function result_assert($value,$label) {
    if (!$value) throw new RuntimeException('FAIL '.$label);
    $GLOBALS['passes'][]=$label;
}
$source=file_get_contents($root.'/public/index.php');
$start=strpos($source,"\$router->get('/orders/{no}/payment-result',");
$end=strpos($source,"\$router->get('/orders',",$start);
$handler=substr($source,$start,$end-$start);
$pdo=new ResultPDO;
$config=array();
$router=new Router;
eval($handler);
$test_user=7;
$test_order=array('id'=>1,'user_id'=>7,'order_no'=>'TEST001','status'=>'pending_delivery');
$passes=array();
function result_run($no='TEST001') {
    $GLOBALS['test_body']='';http_response_code(200);
    try { $GLOBALS['router']->dispatch('GET','/orders/'.$no.'/payment-result'); }
    catch (ResultRedirect $e) { return $e->url; }
    return $GLOBALS['test_body'];
}
foreach (array('pending_payment','paid_waiting_group','pending_delivery','delivered','cancelled','refunding','refunded') as $status) {
    $test_order['status']=$status;
    result_assert(result_run()==='/orders/TEST001',$status.' 旧入口统一到订单详情');
    result_assert($pdo->reads===0&&$test_body==='',$status.' 不输出旧成功页或解密交付');
}
$test_user=8;
$body=result_run();
result_assert(http_response_code()===404&&strpos($body,'无权查看')!==false&&$pdo->reads===0,'非本人旧链接返回404且无交付查询');
$test_user=7;$test_order=false;
result_assert(strpos(result_run(),'无权查看')!==false&&http_response_code()===404,'不存在订单返回404');
$test_order=array('id'=>1,'user_id'=>7,'order_no'=>'TEST 001','status'=>'delivered');
result_assert(result_run('TEST 001')==='/orders/TEST%20001','兼容跳转编码订单路径');
result_assert(strpos($handler,'payment-result-page')===false&&strpos($handler,'decrypt(')===false,'旧入口不再维护第二套模板或解密路径');
result_assert(strpos($handler,'Cache-Control: no-store, private')!==false&&strpos($handler,'Referrer-Policy: no-referrer')!==false,'保留兼容入口敏感信息响应头');
echo implode(PHP_EOL,array_map(function($label){return 'PASS '.$label;},$passes)).PHP_EOL;
