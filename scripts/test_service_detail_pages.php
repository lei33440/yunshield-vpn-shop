<?php
// 隔离执行真实 GET 模板；服务替身不连接数据库，不验证业务服务权限实现。
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__).'/app/Core/Router.php';
require dirname(__DIR__).'/app/Core/TicketPages.php';
require dirname(__DIR__).'/app/Core/AnnouncementPages.php';
function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function csrf_field() { return '<input type="hidden" name="_csrf" value="isolated-csrf">'; }
function page($title, $body, $config) { echo '<title>'.e($title).'</title>'.$body; }
function detail_assert($ok, $label) { if (!$ok) throw new RuntimeException('FAIL '.$label); echo 'PASS '.$label.PHP_EOL; }
class Security { static function requireUser() { return 7; } }
class TicketService {
    static $status = 'open';
    static $empty = false;
    function __construct($pdo) { if ($pdo !== null) throw new RuntimeException('禁止连接数据库'); }
    function detail($id, $actor, $page, $limit) {
        if ($actor !== 7 || $id !== '1') throw new RuntimeException('工单不存在');
        return array('id'=>1,'subject'=>'标题<script>不可执行</script>','status'=>self::$status,'order_no'=>'ORDER-TEST','product_name_snapshot'=>'商品<测试>','version_no'=>3,'message_page'=>1,'message_pages'=>2,'messages'=>self::$empty?array():array(array('sender_type'=>'admin','created_at'=>'2026-10-27 12:00:00','body'=>'客服<script>不可执行</script>'),array('sender_type'=>'user','created_at'=>'2026-10-27 12:01:00','body'=>'用户<测试>')));
    }
}
class AnnouncementService {
    function __construct($pdo) { if ($pdo !== null) throw new RuntimeException('禁止连接数据库'); }
    function findActive($id) { return $id===1?array('title'=>'公告 & <标题>','starts_at'=>'2026-10-27 12:00:00','body'=>'正文<script>不可执行</script>'):false; }
}
$_SESSION=array(); $_GET=array();
$router=new Router;
register_ticket_routes($router,null,array());
register_announcement_routes($router,null,array());
$render=function($path)use($router){http_response_code(200);ob_start();$router->dispatch('GET',$path);return ob_get_clean();};
foreach(array('open','waiting_customer','closed') as $status) {
    TicketService::$status=$status;
    $html=$render('/tickets/1');
    detail_assert(strpos($html,'<script>')===false && strpos($html,'&lt;script&gt;')!==false,$status.' 主题与消息转义');
    detail_assert(strpos($html,'/orders/ORDER-TEST')!==false && strpos($html,'返回工单列表')!==false,$status.' 真实订单与返回入口');
    detail_assert(strpos($html,'message_page=2')!==false,$status.' 沟通记录分页');
    detail_assert((strpos($html,'action="/tickets/1/reply"')!==false)===($status!=='closed'),$status.' 回复表单状态');
    if($status!=='closed') detail_assert(strpos($html,'name="version_no" value="3"')!==false && strpos($html,'isolated-csrf')!==false,$status.' 保留版本与CSRF');
}
TicketService::$empty=true;
detail_assert(strpos($render('/tickets/1'),'当前页暂无沟通记录')!==false,'空沟通记录提示');
foreach(array('/tickets/2','/tickets/0','/tickets/999999999999999999999999999999') as $path) {
    $html=$render($path);
    detail_assert(http_response_code()===404 && strpos($html,'无法查看此工单')!==false,$path.' 不可查看状态');
}
$html=$render('/announcements/1');
detail_assert(strpos($html,'<title>公告 &amp; &lt;标题&gt;</title>')!==false,'公告标题仅转义一次');
detail_assert(strpos($html,'<script>')===false && strpos($html,'&lt;script&gt;')!==false,'公告正文转义');
detail_assert(strpos($html,'返回公告列表')!==false,'公告详情返回入口');
$html=$render('/announcements/2');
detail_assert(http_response_code()===404 && strpos($html,'公告不存在或已失效')!==false,'失效公告状态');
