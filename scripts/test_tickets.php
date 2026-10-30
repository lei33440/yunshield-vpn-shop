<?php
// 仅操作显式指定的隔离库，不读取站点配置或启动外发任务。
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
$dsn = getenv('CHAOXI_TEST_DSN');
if (!$dsn || strpos($dsn, 'mysql:') !== 0 || preg_match_all('/(?:^|;)\s*dbname\s*=/i', substr($dsn, 6)) !== 1 || !preg_match('/(?:^|;)dbname=(chaoxi_wallet_test_[A-Za-z0-9_]+)(?:;|$)/', substr($dsn, 6), $match)) throw new RuntimeException('必须指定唯一隔离库 DSN。');
$pdo = new PDO($dsn, getenv('CHAOXI_TEST_USER'), getenv('CHAOXI_TEST_PASSWORD'), array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false));
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $match[1]) throw new RuntimeException('实际隔离库不一致。');
date_default_timezone_set('Asia/Shanghai');
set_error_handler(function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
$root = dirname(__DIR__);
require $root.'/app/Services/NotificationService.php';
require $root.'/app/Services/TicketService.php';
function ticket_assert($value, $name) { if (!$value) throw new RuntimeException('FAIL '.$name); echo 'PASS '.$name.PHP_EOL; }
function ticket_key() { return 'test_ticket_'.bin2hex(random_bytes(12)); }
function ticket_exec($sql, $args=array()) { global $pdo; $s=$pdo->prepare($sql); $s->execute($args); return $s; }
function ticket_reject($fn, $name, $text='') {
    try { $fn(); } catch (PDOException $error) { throw $error; } catch (RuntimeException $error) {
        if ($text!=='' && strpos($error->getMessage(),$text)===false) throw $error;
        ticket_assert(true,$name); return;
    } catch (InvalidArgumentException $error) {
        if ($text!=='' && strpos($error->getMessage(),$text)===false) throw $error;
        ticket_assert(true,$name); return;
    }
    throw new RuntimeException('未拒绝：'.$name);
}
function ticket_user() {
    $now=date('Y-m-d H:i:s');
    ticket_exec('INSERT INTO users(email,password_hash,display_name,created_at,updated_at) VALUES(?,"x","ticket-test",?,?)',array(bin2hex(random_bytes(12)).'@ticket.test',$now,$now));
    global $pdo; return (int)$pdo->lastInsertId();
}
function ticket_state($id, $uid) {
    return array(
        ticket_exec('SELECT * FROM support_tickets WHERE id=?',array($id))->fetch(),
        ticket_exec('SELECT * FROM ticket_messages WHERE ticket_id=? ORDER BY id',array($id))->fetchAll(),
        ticket_exec('SELECT * FROM ticket_operations WHERE ticket_id=? ORDER BY id',array($id))->fetchAll(),
        ticket_exec('SELECT * FROM notifications WHERE user_id=? ORDER BY id',array($uid))->fetchAll(),
        ticket_exec('SELECT * FROM admin_logs WHERE target_type="ticket" AND target_id=? ORDER BY id',array($id))->fetchAll()
    );
}
class TicketFaultConnection {
    private $pdo; private $needle;
    public function __construct($pdo,$needle) {$this->pdo=$pdo;$this->needle=$needle;}
    public function prepare($sql) {if(strpos($sql,$this->needle)!==false)throw new RuntimeException('工单测试故障注入');return $this->pdo->prepare($sql);}
    public function __call($name,$args) {return call_user_func_array(array($this->pdo,$name),$args);}
}
function ticket_parallel($jobs) {
    $running=array();
    foreach($jobs as $job){
        $pipes=array();$process=proc_open(array_merge(array(PHP_BINARY,__FILE__,'--worker'),$job),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
        if(!is_resource($process))throw new RuntimeException('无法创建工单并发进程。');
        fclose($pipes[0]);$running[]=array($process,$pipes);
    }
    $wins=0;
    foreach($running as $child){
        $out=stream_get_contents($child[1][1]);$err=stream_get_contents($child[1][2]);fclose($child[1][1]);fclose($child[1][2]);$code=proc_close($child[0]);
        if($code===0&&$out==='OK'&&$err===''){$wins++;continue;}
        if($code!==2||strpos($out,'REJECT:')!==0||$err!=='')throw new RuntimeException('并发进程异常：'.$out.$err);
    }
    return $wins;
}
if (($argv[1]??'') === '--worker') {
    $svc=new TicketService($pdo);
    try {
        if($argv[2]==='create')$svc->create((int)$argv[3],'并发创建','正文',null,$argv[4]);
        elseif($argv[2]==='reply')$svc->replyUser((int)$argv[3],(int)$argv[4],'并发用户回复',$argv[5]);
        elseif($argv[2]==='admin')$svc->replyAdmin((int)$argv[3],(int)$argv[4],'并发客服回复',$argv[5],(int)$argv[6]);
        elseif($argv[2]==='close')$svc->close((int)$argv[3],(int)$argv[4],$argv[5],(int)$argv[6]);
        else throw new LogicException('未知测试动作');
        echo 'OK';exit(0);
    } catch(PDOException $error){throw $error;} catch(RuntimeException $error){
        if(!preg_match('/过于频繁|版本已变化|已关闭|已达上限/',$error->getMessage()))throw $error;
        echo 'REJECT:'.$error->getMessage();exit(2);
    }
}
$uid=ticket_user();$other=ticket_user();$now=date('Y-m-d H:i:s');
ticket_exec('INSERT INTO admin_users(email,password_hash,created_at,updated_at) VALUES(?,"x",?,?)',array(bin2hex(random_bytes(12)).'@admin.ticket.test',$now,$now));$aid=(int)$pdo->lastInsertId();
ticket_exec('INSERT INTO orders(order_no,user_id,product_name_snapshot,product_description_snapshot,traffic_snapshot,region_snapshot,device_limit_snapshot,duration_days_snapshot,unit_price_cents_snapshot,total_amount_cents,created_at,updated_at) VALUES(?, ?,"test","test","test","test",1,1,100,100,?,?)',array('TKTEST'.bin2hex(random_bytes(10)),$uid,$now,$now));$oid=(int)$pdo->lastInsertId();
$outbox=(int)$pdo->query('SELECT COUNT(*) FROM notification_outbox')->fetchColumn();
$svc=new TicketService($pdo);$key=ticket_key();$created=$svc->create($uid,'中文主题','正文内容',$oid,$key);$id=(int)$created['id'];
ticket_assert($svc->create($uid,'中文主题','正文内容',$oid,$key)===$created,'创建重试返回原结果');
ticket_assert($svc->create($uid,'中文主题','正文内容',(string)$oid,$key)===$created,'表单字符串订单编号与整数编号幂等一致');
ticket_reject(function()use($svc,$uid,$oid,$key){$svc->create($uid,'不同主题','正文内容',$oid,$key);},'创建同键不同内容拒绝');
ticket_reject(function()use($svc,$other,$oid){$svc->create($other,'主题','正文',$oid,ticket_key());},'他人订单关联拒绝','无权');
ticket_reject(function()use($svc,$uid){$svc->create($uid,array(),'正文',null,ticket_key());},'数组主题拒绝');
ticket_reject(function()use($svc,$uid){$svc->create($uid,str_repeat('中',201),'正文',null,ticket_key());},'中文主题超长拒绝');
ticket_reject(function()use($svc,$uid){$svc->create($uid,'主题',str_repeat('中',5001),null,ticket_key());},'中文正文超长拒绝');
ticket_reject(function()use($svc,$id,$other){$svc->detail($id,$other);},'详情仅本人可见');
ticket_reject(function()use($svc,$uid){$svc->create($uid,'数组订单','正文',array('bad'),ticket_key());},'数组订单安全拒绝');
ticket_reject(function()use($svc,$id,$aid){$svc->replyAdmin($id,$aid,'数组版本',ticket_key(),array('bad'));},'数组版本安全拒绝');
ticket_assert(!$pdo->inTransaction(),'无效输入不泄漏事务');
$replyKey=ticket_key();$svc->replyUser($id,$uid,'用户回复',$replyKey);$replyState=ticket_state($id,$uid);$svc->replyUser($id,$uid,'用户回复',$replyKey);
ticket_assert(ticket_state($id,$uid)===$replyState,'用户回复重试无副作用');
$secondKey=ticket_key();$second=$svc->create($uid,'第二张','正文',null,$secondKey);
ticket_assert($svc->create($uid,'第二张','正文','',$secondKey)===$second,'空字符串与null不关联订单幂等一致');
ticket_reject(function()use($svc,$second,$uid,$replyKey){$svc->replyUser($second['id'],$uid,'用户回复',$replyKey);},'跨工单复用请求键拒绝');
$foreign=$svc->create($other,'其他用户','正文',null,ticket_key());
ticket_reject(function()use($svc,$foreign,$uid,$replyKey){$svc->replyUser($foreign['id'],$uid,'用户回复',$replyKey);},'幂等重放不能绕过归属');
$version=(int)$svc->detail($id,$uid)['version_no'];$adminKey=ticket_key();$svc->replyAdmin($id,$aid,'客服回复',$adminKey,$version);
ticket_assert($svc->detail($id,$uid)['status']==='waiting_customer','客服回复进入等待用户状态');
$adminState=ticket_state($id,$uid);$svc->replyAdmin($id,$aid,'客服回复',$adminKey,$version);
ticket_assert(ticket_state($id,$uid)===$adminState,'成功管理请求先于过期版本幂等返回');
$svc->replyAdmin($id,$aid,'字符串表单版本',ticket_key(),(string)$svc->detail($id,$uid)['version_no']);
$laterState=ticket_state($id,$uid);$svc->replyAdmin($id,$aid,'客服回复',$adminKey,(string)$version);
ticket_assert(ticket_state($id,$uid)===$laterState,'表单字符串版本与整数版本规范一致且晚重试幂等');
ticket_reject(function()use($svc,$id,$aid,$version){$svc->replyAdmin($id,$aid,'旧页面',ticket_key(),$version);},'过期管理版本拒绝');
ticket_reject(function()use($svc,$id,$uid){$svc->replyAdmin($id,PHP_INT_MAX,'无效管理员',ticket_key(),(int)$svc->detail($id,$uid)['version_no']);},'无效管理员拒绝','管理员身份无效');
foreach(array('INSERT INTO notifications','INSERT INTO admin_logs') as $needle){
    $before=ticket_state($id,$uid);$fault=new TicketService(new TicketFaultConnection($pdo,$needle));
    ticket_reject(function()use($fault,$svc,$id,$uid,$aid){$fault->replyAdmin($id,$aid,'应整体回滚',ticket_key(),(int)$svc->detail($id,$uid)['version_no']);},$needle.'故障传播','故障注入');
    ticket_assert(ticket_state($id,$uid)===$before,$needle.'故障回滚消息状态操作通知审计');
    ticket_assert(!$pdo->inTransaction(),'故障不泄漏事务');
}
$version=(int)$svc->detail($id,$uid)['version_no'];$closeKey=ticket_key();$svc->close($id,$aid,$closeKey,$version);$closed=ticket_state($id,$uid);
$svc->close($id,$aid,$closeKey,$version);ticket_assert(ticket_state($id,$uid)===$closed,'关闭成功请求重试无副作用');
$closedAt=$closed[0]['closed_at'];$svc->close($id,$aid,ticket_key(),(int)$closed[0]['version_no']);
ticket_assert($svc->detail($id,$uid)['closed_at']===$closedAt,'重复关闭保留首次关闭时间');
ticket_reject(function()use($svc,$id,$uid){$svc->replyUser($id,$uid,'关闭后',ticket_key());},'关闭后用户不能回复');
ticket_reject(function()use($svc,$id,$aid,$uid){$svc->replyAdmin($id,$aid,'关闭后',ticket_key(),(int)$svc->detail($id,$uid)['version_no']);},'关闭后客服不能回复','已关闭');
$notes=ticket_exec('SELECT target_type,target_id FROM notifications WHERE user_id=? AND type="ticket_update"',array($uid))->fetchAll();
ticket_assert(count($notes)>=2&&count(array_filter($notes,function($n)use($id){return $n['target_type']==='ticket'&&(int)$n['target_id']===$id;}))===count($notes),'管理通知安全指向工单');
$beforeCreate=(int)ticket_exec('SELECT COUNT(*) FROM support_tickets WHERE user_id=?',array($other))->fetchColumn();
$createFault=new TicketService(new TicketFaultConnection($pdo,'INSERT INTO ticket_operations'));
ticket_reject(function()use($createFault,$other){$createFault->create($other,'创建应回滚','正文',null,ticket_key());},'创建操作记录故障传播','故障注入');
ticket_assert((int)ticket_exec('SELECT COUNT(*) FROM support_tickets WHERE user_id=?',array($other))->fetchColumn()===$beforeCreate,'创建故障回滚工单与首条消息');
$noOrder=$svc->detail($second['id'],$uid);ticket_assert(array_key_exists('order_no',$noOrder)&&$noOrder['order_no']===null,'无订单详情字段显式为空');
$summary=$svc->detail($id,$uid);ticket_assert(!empty($summary['order_no']),'本人订单摘要可见');
ticket_exec('UPDATE support_tickets SET order_id=? WHERE id=?',array($oid,$foreign['id']));
$foreignSummary=$svc->detail($foreign['id'],$other);ticket_assert($foreignSummary['order_no']===null,'历史错绑不泄漏他人订单摘要');
$raceUser=ticket_user();$jobs=array();for($i=0;$i<7;$i++)$jobs[]=array('create',$raceUser,ticket_key());
ticket_assert(ticket_parallel($jobs)===5,'真实进程并发创建每小时最多五次');
ticket_assert((int)ticket_exec('SELECT COUNT(*) FROM support_tickets WHERE user_id=?',array($raceUser))->fetchColumn()===5,'并发创建无超额或孤立工单');
$limitUser=ticket_user();$limited=$svc->create($limitUser,'回复限频','初始正文',null,ticket_key());$limitId=(int)$limited['id'];
ticket_exec('UPDATE ticket_messages SET created_at=? WHERE ticket_id=?',array(date('Y-m-d H:i:s',time()-120),$limitId));
$jobs=array();for($i=0;$i<7;$i++)$jobs[]=array('reply',$limitId,$limitUser,ticket_key());
ticket_assert(ticket_parallel($jobs)===5,'真实进程并发用户回复每分钟最多五次');
ticket_assert((int)ticket_exec('SELECT COUNT(*) FROM ticket_messages WHERE ticket_id=?',array($limitId))->fetchColumn()===6,'回复并发仅写入五条消息');
$conversation=$svc->detail($limitId,$limitUser,2,2);
ticket_assert($conversation['message_page']===2&&$conversation['message_pages']===3&&count($conversation['messages'])===2,'对话分页实际限制消息并提供页数');
$list=$svc->listing($raceUser,array('status'=>'open'),2,2);
ticket_assert($list['page']===2&&$list['pages']===3&&count($list['items'])===2&&$list['total']===5,'本人列表筛选分页不泄漏其他用户');
ticket_exec('INSERT INTO admin_users(email,password_hash,created_at,updated_at) VALUES(?,"x",?,?)',array(bin2hex(random_bytes(12)).'@admin.ticket.test',$now,$now));$secondAdmin=(int)$pdo->lastInsertId();
$raceTicket=$svc->create(ticket_user(),'关闭回复竞态','正文',null,ticket_key());$raceId=(int)$raceTicket['id'];$raceVersion=1;
ticket_assert(ticket_parallel(array(array('admin',$raceId,$aid,ticket_key(),$raceVersion),array('close',$raceId,$secondAdmin,ticket_key(),$raceVersion)))===1,'不同管理员真实进程关闭回复同版本仅一胜');
$raceRow=$svc->detail($raceId);ticket_assert((int)$raceRow['version_no']===2,'关闭回复竞态版本仅增加一次');
$rateKey=ticket_key();$retryUser=ticket_user();$retry=$svc->create($retryUser,'幂等优先','正文',null,$rateKey);for($i=0;$i<4;$i++)$svc->create($retryUser,'填满限频','正文',null,ticket_key());
ticket_assert($svc->create($retryUser,'幂等优先','正文',null,$rateKey)===$retry,'成功创建重试先于当前限频');
$permissionUser=ticket_user();$permissionTicket=$svc->create($permissionUser,'归属优先','正文',null,ticket_key());$permissionKey=ticket_key();
$svc->replyUser($permissionTicket['id'],$permissionUser,'原用户回复',$permissionKey);
ticket_exec('UPDATE support_tickets SET user_id=? WHERE id=?',array($other,$permissionTicket['id']));
ticket_reject(function()use($svc,$permissionTicket,$permissionUser,$permissionKey){$svc->replyUser($permissionTicket['id'],$permissionUser,'原用户回复',$permissionKey);},'成功请求重试仍先验证当前归属','不存在');
$openUser=ticket_user();for($i=0;$i<10;$i++){
    $svc->create($openUser,'未关闭上限','正文',null,ticket_key());
    ticket_exec('UPDATE support_tickets SET created_at=? WHERE user_id=?',array(date('Y-m-d H:i:s',time()-7200),$openUser));
}
ticket_reject(function()use($svc,$openUser){$svc->create($openUser,'第十一张','正文',null,ticket_key());},'未关闭工单最多十张','已达上限');
ticket_assert((int)$pdo->query('SELECT COUNT(*) FROM notification_outbox')->fetchColumn()===$outbox,'所有工单操作不新增外发队列');
ticket_assert(!$pdo->inTransaction(),'全部操作后无事务泄漏');
// 执行真实路由 handler，警告也会使测试失败，避免 lint 掩盖闭包变量遗漏。
class Security {
    public static $uid; public static $aid;
    public static function requireUser(){return self::$uid;}
    public static function requireAdmin(){return self::$aid;}
}
class TicketTestRouter {
    public $get=array(); public $post=array();
    public function get($path,$handler){$this->get[$path]=$handler;}
    public function post($path,$handler){$this->post[$path]=$handler;}
}
class TicketTestRedirect extends Error {}
function e($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function csrf_field(){return '<input name="_csrf" value="test">';}
function post_csrf(){}
function page($title,$body,$config){$GLOBALS['ticket_html']=$body;$GLOBALS['ticket_layout']='user';}
function admin_page($title,$body,$subtitle,$path,$pdo,$config){$GLOBALS['ticket_html']=$body;$GLOBALS['ticket_layout']='admin';}
function flash($type,$message){$GLOBALS['ticket_flash']=$message;}
function redirect($path){$GLOBALS['ticket_redirect']=$path;throw new TicketTestRedirect('redirect');}
function ticket_post($handler,$args=array()){
    try{call_user_func_array($handler,$args);}catch(TicketTestRedirect $redirect){return;}
    throw new RuntimeException('POST未重定向');
}
require $root.'/app/Core/TicketPages.php';
$router=new TicketTestRouter();register_ticket_routes($router,$pdo,array());Security::$uid=$uid;Security::$aid=$aid;$_SESSION=array();$_GET=array('order_id'=>(string)$oid);
$router->get['/tickets']();
ticket_assert(strpos($GLOBALS['ticket_html'],'value="'.$oid.'" selected')!==false,'真实GET本人订单下拉支持详情带入');
ticket_assert(strpos($GLOBALS['ticket_html'],ticket_exec('SELECT order_no FROM orders WHERE id=?',array($oid))->fetchColumn())!==false,'订单下拉展示本人订单');
$_POST=array('request_key'=>array('bad'),'subject'=>'<script>保留主题</script>','body'=>'<img src=x onerror=alert(1)>','order_id'=>(string)$oid);
ticket_post($router->post['/tickets']);$_GET=array();$router->get['/tickets']();
ticket_assert(strpos($GLOBALS['ticket_html'],'&lt;script&gt;保留主题&lt;/script&gt;')!==false&&strpos($GLOBALS['ticket_html'],'&lt;img')!==false,'创建失败内容转义回显');
ticket_assert(strpos($GLOBALS['ticket_html'],'value="'.$oid.'" selected')!==false,'创建失败保留本人订单选择');
http_response_code(200);Security::$uid=$other;$router->get['/tickets/{id}']($id);ticket_assert(http_response_code()===404,'真实详情handler他人访问404');Security::$uid=$uid;
$_GET=array();$router->get['/admin/tickets']();ticket_assert($GLOBALS['ticket_layout']==='admin','后台工单列表使用后台布局');
$router->get['/admin/tickets/{id}']($second['id']);ticket_assert($GLOBALS['ticket_layout']==='admin','后台工单详情使用后台布局');
$before=ticket_state($second['id'],$uid);$_POST=array('body'=>'绕过版本','request_key'=>ticket_key());ticket_post($router->post['/admin/tickets/{id}/reply'],array($second['id']));
ticket_assert(ticket_state($second['id'],$uid)===$before,'管理员POST缺失版本不能绕过校验');
$_POST=array('body'=>'<b>失败回复保留</b>','request_key'=>ticket_key(),'version_no'=>'999999');ticket_post($router->post['/admin/tickets/{id}/reply'],array($second['id']));$router->get['/admin/tickets/{id}']($second['id']);
ticket_assert(strpos($GLOBALS['ticket_html'],'&lt;b&gt;失败回复保留&lt;/b&gt;')!==false,'客服回复失败安全保留正文');
$_POST=array('request_key'=>ticket_key());$before=ticket_state($second['id'],$uid);ticket_post($router->post['/admin/tickets/{id}/close'],array($second['id']));
ticket_assert(ticket_state($second['id'],$uid)===$before,'管理员关闭POST必须提交版本');
$_POST=array('body'=>'<i>用户失败草稿</i>','request_key'=>array('bad'),'version_no'=>(string)$svc->detail($second['id'],$uid)['version_no']);ticket_post($router->post['/tickets/{id}/reply'],array($second['id']));$router->get['/tickets/{id}']($second['id']);
ticket_assert(strpos($GLOBALS['ticket_html'],'&lt;i&gt;用户失败草稿&lt;/i&gt;')!==false,'用户回复失败转义回显');
ticket_assert(strpos($GLOBALS['ticket_html'],'失败回复保留')===false,'用户页面不得看到管理员失败草稿');
for($i=0;$i<21;$i++)ticket_exec('INSERT INTO support_tickets(ticket_no,user_id,subject,status,version_no,created_at,updated_at) VALUES(?,?,?,"open",1,?,?)',array('PAGE'.bin2hex(random_bytes(12)),$uid,'分页测试',$now,$now));
$_GET=array('status'=>'open');$router->get['/tickets']();
ticket_assert(strpos($GLOBALS['ticket_html'],'下一页')!==false&&strpos($GLOBALS['ticket_html'],'status=open')!==false&&strpos($GLOBALS['ticket_html'],'page=2')!==false,'用户列表可点击保留状态分页');
ticket_assert(strpos($GLOBALS['ticket_html'],'id="new-ticket"')!==false,'订单详情锚点能定位新建工单');
$router->get['/admin/tickets']();ticket_assert(strpos($GLOBALS['ticket_html'],'下一页')!==false&&strpos($GLOBALS['ticket_html'],'status=open')!==false,'后台列表可点击保留状态分页');
for($i=0;$i<21;$i++)ticket_exec('INSERT INTO ticket_messages(ticket_id,sender_type,sender_user_id,body,created_at) VALUES(?,"user",?,"分页正文",?)',array($second['id'],$uid,$now));
$_GET=array();$router->get['/tickets/{id}']($second['id']);ticket_assert(strpos($GLOBALS['ticket_html'],'message_page=2')!==false,'用户对话有可点击分页');
$router->get['/admin/tickets/{id}']($second['id']);ticket_assert(strpos($GLOBALS['ticket_html'],'message_page=2')!==false,'后台对话有可点击分页');
$_POST=array('body'=>'正常表单客服回复','request_key'=>ticket_key(),'version_no'=>(string)$svc->detail($second['id'],$uid)['version_no']);
ticket_post($router->post['/admin/tickets/{id}/reply'],array($second['id']));$router->get['/admin/tickets/{id}']($second['id']);
ticket_assert($svc->detail($second['id'],$uid)['status']==='waiting_customer','真实客服POST字符串版本完成回复');
ticket_assert(strpos($GLOBALS['ticket_html'],'失败回复保留')===false,'客服成功回复清除失败草稿');
ticket_exec('UPDATE ticket_messages SET created_at=? WHERE ticket_id=? AND sender_type="user"',array(date('Y-m-d H:i:s',time()-120),$second['id']));
$_POST=array('body'=>'正常用户回复','request_key'=>ticket_key(),'version_no'=>(string)$svc->detail($second['id'],$uid)['version_no']);
ticket_post($router->post['/tickets/{id}/reply'],array($second['id']));$router->get['/tickets/{id}']($second['id']);
ticket_assert($svc->detail($second['id'],$uid)['status']==='open','真实用户POST回复进入待客服状态');
ticket_assert(strpos($GLOBALS['ticket_html'],'用户失败草稿')===false,'用户成功回复清除失败草稿');
echo 'TEST_DATABASE='.$match[1].PHP_EOL.'TICKET TESTS PASSED'.PHP_EOL;
