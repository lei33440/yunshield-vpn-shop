<?php
// 无数据库隔离测试：执行实际结果页路由，使用真实交付加密实现。
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require $root.'/app/Core/Router.php';
require $root.'/app/Services/CryptoService.php';
require $root.'/app/Core/AccountProductPages.php';
class Security { public static function requireUser() { return $GLOBALS['test_user']; } }
class OrderService {
    public function __construct($pdo) {}
    public function getForUser($no, $uid) {
        $o=$GLOBALS['test_order'];
        return $o && $o['order_no']===$no && $o['user_id']===$uid ? $o : false;
    }
}
class ResultStatement {
    public function execute($args) { if ($args!==array(1)) throw new RuntimeException('交付查询未绑定所属订单'); }
    public function fetch() { return $GLOBALS['test_delivery']; }
}
class ResultPDO {
    public $reads=0;
    public function prepare($sql) {
        if ($sql!=='SELECT * FROM delivery_records WHERE order_id=? AND is_current=1') throw new RuntimeException('意外 SQL');
        $this->reads++;
        return new ResultStatement;
    }
}
class ResultRedirect extends Exception { public $url; public function __construct($url) { $this->url=$url; } }
function redirect($url) { throw new ResultRedirect($url); }
function page($title,$body,$config) { $GLOBALS['test_body']=$body; }
function e($value) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function money($value) { return '¥'.number_format($value/100,2); }
function result_assert($value,$label) { if (!$value) throw new RuntimeException('FAIL '.$label); $GLOBALS['passes'][]=$label; }
$source=file_get_contents($root.'/public/index.php');
foreach (array('status_text') as $name) {
    preg_match('/^function '.preg_quote($name,'/').'\(.*$/m',$source,$match);
    if (!$match) throw new RuntimeException('找不到 '.$name);
    eval($match[0]);
}
$start=strpos($source,"\$router->get('/orders/{no}/payment-result',");
$end=strpos($source,"\$router->get('/orders',",$start);
$pdo=new ResultPDO;
$config=array('security'=>array('encryption_key'=>'isolated-payment-result-test'));
$router=new Router;
eval(substr($source,$start,$end-$start));
$test_user=7;
$test_order=array('id'=>1,'user_id'=>7,'order_no'=>'TEST001','status'=>'pending_delivery','product_type_snapshot'=>'vpn','product_name_snapshot'=>'标准订阅','plan_name_snapshot'=>'季付','duration_days_snapshot'=>90,'total_amount_cents'=>9800,'paid_at'=>'2026-10-27 10:00:00','created_at'=>'2026-10-27 09:59:00');
$test_delivery=false;
$passes=array();
function result_run() {
    $GLOBALS['test_body']='';http_response_code(200);
    try { $GLOBALS['router']->dispatch('GET','/orders/TEST001/payment-result'); }
    catch (ResultRedirect $e) { return $e->url; }
    return $GLOBALS['test_body'];
}
foreach (array('pending_payment','cancelled','refunding','refunded') as $status) {
    $test_order['status']=$status;
    result_assert(result_run()==='/orders/TEST001',$status.' 不显示成功');
}
foreach (array('pending_delivery'=>'等待交付','paid_waiting_group'=>'等待成团') as $status=>$label) {
    $test_order['status']=$status;$body=result_run();
    result_assert(strpos($body,$label)!==false&&strpos($body,'result-secret-box')===false,$status.' 真实状态与隐藏交付');
}
result_assert($pdo->reads===0,'未交付不查询或解密交付内容');
$data=array('subscription'=>'https://example.test/secret?x=<script>bad</script>','account'=>'敏感账号<script>bad</script>','opened_at'=>'2026-10-27','expires_at'=>'2027-01-25','instructions'=>'</div><script>bad</script>');
$encrypted=(new CryptoService($config))->encrypt(json_encode($data));
$test_delivery=array('content_ciphertext'=>$encrypted[0],'content_nonce'=>$encrypted[1],'content_tag'=>$encrypted[2]);
$test_order['status']='delivered';$body=result_run();$preview=$body;
result_assert(strpos($body,e($data['subscription']))!==false&&strpos($body,'<script>bad</script>')===false,'VPN 解密成功且交付字段转义');
result_assert(strpos($body,'result-reveal')!==false&&strpos($body,'data-result-copy')!==false,'查看与复制交付入口');
result_assert(strpos($body,'激活卡密')===false&&strpos($body,'待激活')===false,'不伪造卡密激活流程');
$savedDelivery=$test_delivery;$test_delivery=false;$body=result_run();
result_assert(strpos($body,'请核对交付记录')!==false&&strpos($body,'result-secret-box')===false,'缺失交付记录不伪造可领取内容');
$test_delivery=$savedDelivery;
$test_order['product_type_snapshot']='account';$test_order['duration_days_snapshot']=null;$body=result_run();
result_assert(strpos($body,e($data['account']))!==false&&strpos($body,'0 天')===false,'账号交付与可空周期');
$reads=$pdo->reads;$test_user=8;$body=result_run();
result_assert(http_response_code()===404&&strpos($body,'result-secret-box')===false&&$pdo->reads===$reads,'其他用户无权查询或读取交付');
$test_user=7;$test_order['status']='pending_payment';result_assert(result_run()==='/orders/TEST001','旧链接重新校验当前状态');
foreach (array('check','eye','copy','key','info') as $icon) {
    $path=$root.'/public/assets/result-'.$icon.'.svg';
    result_assert(is_file($path)&&filesize($path)>0&&simplexml_load_file($path)!==false,'本地图标 '.$icon);
}
if (in_array('--preview',$argv,true)) {
    file_put_contents($root.'/public/assets/payment-result-preview.html','<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/style.css"><title>隔离支付结果布局预览</title><body class="payment-result-body"><main>'.$preview.'</main></body></html>');
}
echo implode(PHP_EOL,array_map(function($label){return 'PASS '.$label;},$passes)).PHP_EOL;
