<?php
// 仅限本地独立 MySQL 测试库；不加载站点配置，不接触真实 SMTP、业务数据或网络发送器。
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);$dsn=getenv('CHAOXI_TEST_DSN');
if(!is_string($dsn)||!preg_match('/\Amysql:host=(?:db|localhost|127\.0\.0\.1);dbname=chaoxi_wallet_test_[a-z0-9_]+;charset=utf8mb4\z/D',$dsn))throw new RuntimeException('Explicit isolated local DSN required');
date_default_timezone_set('UTC');
set_error_handler(function($n,$s,$f,$l){throw new ErrorException($s,0,$n,$f,$l);});
spl_autoload_register(function($c)use($root){foreach(array('/app/Core/','/app/Services/') as $d)if(is_file($f=$root.$d.$c.'.php')){require_once $f;return;}});
class CustomerTestPDO extends PDO {public $failCommit=false;public function commit(){if($this->failCommit){$this->failCommit=false;throw new RuntimeException('injected commit failure');}return parent::commit();}}
$p=new CustomerTestPDO($dsn,getenv('CHAOXI_TEST_USER'),getenv('CHAOXI_TEST_PASSWORD'),array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false));
$config=array('app'=>array('base_url'=>'https://example.test','name'=>'Test <brand>','timezone'=>'UTC'),'security'=>array('encryption_key'=>bin2hex(random_bytes(32))));
$c=new EmailCampaignService($p,$config);$customer=new CustomerEmailService($p,$config);$out=new NotificationOutboxService($p);
if(isset($argv[1])&&$argv[1]==='--confirm-child'){echo "READY\n";fflush(STDOUT);$c->confirm((int)$argv[2],1,1);echo "CONFIRMED\n";exit;}
// 缓冲命令行断言输出，避免影响真实路由的 header() 调用。
ob_start();
function ce_ok($b,$n){if(!$b)throw new RuntimeException('FAIL '.$n);echo 'PASS '.$n.PHP_EOL;}
function ce_reject($f,$n){try{$f();}catch(Throwable $e){ce_ok(true,$n);return;}throw new RuntimeException('FAIL '.$n);}
function ce_rows($p,$table){return $p->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();}
function ce_campaign($c,$ids=null){$i=array('subject'=>'Notice <script>','body'=>'Safe <img src=x onerror=alert(1)> body','scope'=>$ids===null?'all':'selected');if($ids!==null)$i['user_ids']=$ids;$id=$c->saveDraft($i,1);$c->snapshot($id,1,1);return $id;}
ce_ok((int)$p->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn()===0,'empty dedicated database');
require $root.'/scripts/migrate_customer_email.php';customer_email_migrate($p);$tables=$p->query('SHOW TABLES')->fetchAll();customer_email_migrate($p);ce_ok(count($tables)===3&&$tables===$p->query('SHOW TABLES')->fetchAll(),'dedicated migration twice creates only three tables');
foreach(array_filter(array_map('trim',preg_split('/;\s*(?:\r?\n|$)/',file_get_contents($root.'/database/schema.sql')))) as $sql)$p->exec($sql);
require $root.'/scripts/customer_email_worker.php';
class CustomerSMTPStub {public $enabled=true,$configured=true,$fail=false,$stop=false,$calls=array();public function settings(){return array('enabled'=>$this->enabled,'configured'=>$this->configured);}public function send($to,$s,$b,$h=null){if(!preg_match('/@example\.test\z/iD',$to))throw new LogicException('Forbidden recipient');$this->calls[]=array($to,$s,$b,$h);if($this->fail)throw new RuntimeException('SECRET_SMTP_PASSWORD');return !$this->stop;}}
$smtp=new CustomerSMTPStub;$waits=array();$wait=function($s)use(&$waits){$waits[]=$s;};
$p->exec('INSERT INTO admin_users(email,password_hash,created_at,updated_at) VALUES("admin@example.test","test",NOW(),NOW())');
for($i=1;$i<=45;$i++)$p->prepare('INSERT INTO users(email,password_hash,status,created_at,updated_at) VALUES(?,"test","active",NOW(),NOW())')->execute(array('person'.$i.'@example.test'));
$p->exec('UPDATE users SET status="disabled" WHERE id=45');$p->exec('INSERT INTO email_preferences(user_id,broadcast_unsubscribed,updated_at) VALUES(44,1,NOW())');
ce_ok(!$customer->settings()['customer_order_created_enabled']&&!$customer->settings()['customer_order_delivered_enabled'],'both transaction defaults off');
$customer->saveSettings(array('customer_order_created_enabled'=>1,'customer_order_delivered_enabled'=>1),1);$customer->saveSettings(array(),1);ce_ok($customer->settings()['customer_order_created_enabled'],'missing settings preserve enabled');
$p->exec('INSERT INTO account_platforms(code,name,sort_order,created_at,updated_at) VALUES("telegram","Telegram",0,NOW(),NOW())');
$p->exec('INSERT INTO products(name,description,product_type,account_platform,account_specification,account_after_sales,price_cents,created_at,updated_at) VALUES("Product <b>","public","account","telegram","spec","care",1000,NOW(),NOW())');
$p->exec('INSERT INTO product_plans(product_id,name,price_cents,created_at,updated_at) VALUES(1,"Plan",1000,NOW(),NOW())');
$orders=new OrderService($p);$p->failCommit=true;ce_reject(function()use($orders){$orders->create(1,1,1,'SECRET_NOTE');},'create commit fault throws');ce_ok(!ce_rows($p,'orders')&&!ce_rows($p,'notification_outbox'),'create atomic rollback no notification');
$no=$orders->create(1,1,1,'SECRET_NOTE');$order=$orders->getForUser($no,1);$orders->confirmPayment($no,1);$before=ce_rows($p,'notification_outbox');$p->failCommit=true;ce_reject(function()use($orders,$no,$config){$orders->deliver($no,1,array('account'=>'SECRET_DELIVERY'),$config);},'delivery commit fault throws');ce_ok(ce_rows($p,'notification_outbox')===$before&&!ce_rows($p,'delivery_records')&&$orders->getForUser($no,1)['status']==='pending_delivery','delivery atomic rollback');
$orders->deliver($no,1,array('account'=>'SECRET_DELIVERY'),$config);$deliveryBinding=$p->query('SELECT id,version_no FROM delivery_records WHERE order_id='.(int)$order['id'].' AND is_current=1')->fetch();$p->beginTransaction();foreach(array('customer_order_created','customer_order_delivered') as $e)$customer->enqueueOrder($e,$order,$e==='customer_order_delivered'?$deliveryBinding:null);$p->commit();
$emailRows=$p->query('SELECT * FROM notification_outbox WHERE channel="email"')->fetchAll();ce_ok(count($emailRows)===2&&strpos(json_encode($emailRows),'SECRET_')===false,'two events deduplicate and omit delivery/note secrets');
ce_reject(function()use($customer,$order){$customer->enqueueOrder('customer_order_created',$order);},'enqueue outside transaction prohibited');
$customer->saveSettings(array('customer_order_created_enabled'=>0),1);$customer->saveSettings(array('customer_order_created_enabled'=>1),1);ce_ok($customer->eligibility($emailRows[0],json_decode($emailRows[0]['payload_json'],true))==='customer_generation_expired','off then on invalidates old generation');
foreach(array('pending','retry','processing') as $status)foreach(array(array('email','order_delivered'),array('email','password_reset'),array('email','ticket_reply'),array('wecom','customer_order_created'),array('wecom','broadcast_notice')) as $type){$key='legacy:'.bin2hex(random_bytes(6));$out->enqueue($type[1],$key,array('to'=>'person1@example.test'),$type[0]);$p->prepare('UPDATE notification_outbox SET status=?,locked_at=DATE_SUB(NOW(),INTERVAL 11 MINUTE),available_at=DATE_SUB(NOW(),INTERVAL 1 HOUR) WHERE dedupe_key=?')->execute(array($status,$key));}
$legacy=$p->query('SELECT * FROM notification_outbox WHERE dedupe_key LIKE "legacy:%" ORDER BY id')->fetchAll();$before=ce_rows($p,'notification_outbox');
foreach(array('enabled','configured') as $flag){$smtp->$flag=false;$r=customer_email_worker_run($p,$config,$smtp,$wait);ce_ok($r['paused']&&$r['claimed']===0&&ce_rows($p,'notification_outbox')===$before,'SMTP '.$flag.' off leaves all queue fields untouched');$smtp->$flag=true;}
$r=customer_email_worker_run($p,$config,$smtp,$wait);ce_ok($r['sent']===1&&$r['skipped']===1,'transaction worker generation preflight');ce_ok(strpos($smtp->calls[0][3],'<b>')===false&&strpos($smtp->calls[0][3],'&lt;b&gt;')!==false,'transaction template escapes product');
ce_ok($legacy===$p->query('SELECT * FROM notification_outbox WHERE dedupe_key LIKE "legacy:%" ORDER BY id')->fetchAll(),'legacy queues bytewise unchanged including stale reset');
// 快照冻结及并发确认：独立进程之间仅共享本轮隔离测试库。
$id=ce_campaign($c);ce_ok((int)$c->get($id)['recipient_count']===43,'snapshot excludes disabled and unsubscribed');
ce_reject(function()use($c,$id){$c->saveDraft(array('subject'=>'edit','body'=>'edit','scope'=>'all'),1,$id,1);},'snapshot content immutable');
$bad=new EmailCampaignService($p,array('app'=>array('base_url'=>'https://attacker.test/?x=1')));ce_reject(function()use($bad,$id){$bad->confirm($id,1,1);},'untrusted URL shape rejected');
$p->beginTransaction();$p->query('SELECT id FROM email_campaigns WHERE id='.$id.' FOR UPDATE')->fetch();$children=array();
for($j=0;$j<2;$j++){$pipes=array();$proc=proc_open(array(PHP_BINARY,__FILE__,'--confirm-child',(string)$id),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);if(!is_resource($proc))throw new RuntimeException('Cannot start isolated confirm');fclose($pipes[0]);ce_ok(trim(fgets($pipes[1]))==='READY','concurrent confirm child ready '.$j);$children[]=array($proc,$pipes);}
$p->commit();foreach($children as $j=>$child){$stdout=stream_get_contents($child[1][1]);$stderr=stream_get_contents($child[1][2]);fclose($child[1][1]);fclose($child[1][2]);ce_ok(proc_close($child[0])===0&&trim($stdout)==='CONFIRMED'&&$stderr==='','concurrent confirm succeeds '.$j);}
$c->confirm($id,1,1);ce_ok((int)$p->query('SELECT COUNT(*) FROM admin_logs WHERE action="confirm_email_campaign" AND target_id='.$id)->fetchColumn()===1,'concurrent and repeated confirm audit once');
ce_ok($c->enqueueBatch(500)===20&&$c->get($id)['counts']['not_queued']===23,'hard 20 enqueue boundary');ce_ok($c->enqueueBatch()===20&&$c->enqueueBatch()===3&&$c->enqueueBatch()===0,'cursor resumes 20 20 3 without duplicate');
ce_ok((int)$p->query('SELECT COUNT(DISTINCT outbox_id) FROM email_campaign_recipients WHERE campaign_id='.$id)->fetchColumn()===43,'all recipient outbox links unique');
$first=$p->query('SELECT o.* FROM notification_outbox o JOIN email_campaign_recipients r ON r.outbox_id=o.id WHERE r.campaign_id='.$id.' ORDER BY r.id LIMIT 1')->fetch();$payload=json_decode($first['payload_json'],true);$token=$payload['unsubscribe_token'];$msg=$customer->message($first,$payload);
ce_ok(preg_match('/<a[^>]+href="https:\/\/example\.test\/email\/unsubscribe\?token='.$token.'"/',$msg['html'])===1&&strpos($msg['html'],'<img')===false,'HTML contains clickable trusted unsubscribe and escapes body');
$hash=$p->query('SELECT token_hash FROM email_campaign_recipients WHERE outbox_id='.$first['id'])->fetchColumn();ce_ok(strlen($token)===64&&$hash===hash('sha256',$token)&&$hash!==$token,'random token only hash stored in recipient');
// 调用真实 Router 处理器，注入权限与 CSRF 替身；不使用真实会话或业务库。
class Security {public static $allow=false;public static function requireAdmin(){if(!self::$allow)throw new LogicException('admin denied');return 1;}}
$GLOBALS['ce_csrf']=false;function post_csrf(){if(!$GLOBALS['ce_csrf'])throw new LogicException('csrf denied');}function e($s){return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}function csrf_field(){return '<input name="csrf" value="isolated">';}
require $root.'/app/Core/AdminEmailCampaignPages.php';$router=new Router;register_admin_email_campaign_routes($router,$p,$config);register_email_unsubscribe_routes($router,$p,$config);
foreach(array('/admin/email-campaigns','/admin/email-campaigns/users','/admin/email-campaigns/create','/admin/email-campaigns/'.$id) as $path)ce_reject(function()use($router,$path){$router->dispatch('GET',$path);},'anonymous denied '.$path);
Security::$allow=true;foreach(array('/admin/email-campaigns/create','/admin/email-campaigns/'.$id.'/save','/admin/email-campaigns/'.$id.'/snapshot','/admin/email-campaigns/'.$id.'/back','/admin/email-campaigns/'.$id.'/confirm','/admin/email-campaigns/'.$id.'/cancel') as $path)ce_reject(function()use($router,$path){$router->dispatch('POST',$path);},'missing CSRF denied '.$path);
$_GET=array('token'=>$token);$prefs=$p->query('SELECT * FROM email_preferences ORDER BY user_id')->fetchAll();ob_start();$router->dispatch('GET','/email/unsubscribe');$html=ob_get_clean();ce_ok($prefs===$p->query('SELECT * FROM email_preferences ORDER BY user_id')->fetchAll()&&strpos($html,'method="post"')!==false,'actual unsubscribe GET read only confirmation');
$_POST=array('token'=>$token);ce_reject(function()use($router){$router->dispatch('POST','/email/unsubscribe');},'unsubscribe missing CSRF denied');$GLOBALS['ce_csrf']=true;$_POST['token']=str_repeat('b',64);ob_start();$router->dispatch('POST','/email/unsubscribe');ob_end_clean();ce_ok($prefs===$p->query('SELECT * FROM email_preferences ORDER BY user_id')->fetchAll(),'mismatched POST query token read only');
$_POST['token']=$token;for($i=0;$i<2;$i++){ob_start();$router->dispatch('POST','/email/unsubscribe');ob_end_clean();}ce_ok($c->unsubscribeInfo($token)['unsubscribed']&&(int)$p->query('SELECT COUNT(*) FROM email_preferences WHERE user_id=1')->fetchColumn()===1,'actual unsubscribe POST idempotent');ce_ok(!$c->unsubscribe('invalid')&&!$c->unsubscribe(str_repeat('f',64)),'invalid and unknown tokens rejected');
$p->exec('UPDATE users SET email="changed@example.test" WHERE id=2');$p->exec('UPDATE users SET status="disabled" WHERE id=3');
$r=customer_email_worker_run($p,$config,$smtp,$wait);ce_ok($r['claimed']===20&&$r['skipped']===3&&$r['sent']===17,'claim hard 20 and unsubscribe changed disabled skipped');
customer_email_worker_run($p,$config,$smtp,$wait);customer_email_worker_run($p,$config,$smtp,$wait);ce_ok($c->get($id)['status']==='completed'&&count($waits)===43&&min($waits)>=1,'43 broadcasts throttle individually and complete');
$details=json_encode($c->recipients($id));$audit=json_encode(ce_rows($p,'admin_logs'));ce_ok(strpos($details,'person1@example.test')===false&&strpos($details,$token)===false&&strpos($audit,$token)===false&&strpos($audit,'Safe <img')===false,'details and audit redact addresses body tokens');
// 已领取后在限速等待中取消任务，发送前最终资格检查必须阻止投递。
$cancel=ce_campaign($c,array(4,5,6));$c->confirm($cancel,1,1);$calls=count($smtp->calls);$cancelWait=function($s)use($c,$cancel){$c->cancel($cancel,1);};$r=customer_email_worker_run($p,$config,$smtp,$cancelWait);ce_ok(count($smtp->calls)===$calls&&$r['skipped']===3&&$c->get($cancel)['counts']['cancelled']===3,'cancel during processing stops final send');
$retry=ce_campaign($c,array(4));$c->confirm($retry,1,1);$smtp->fail=true;for($i=0;$i<6;$i++){$p->exec('UPDATE notification_outbox SET available_at=NOW() WHERE channel="email" AND event_type="broadcast_notice" AND status="retry"');customer_email_worker_run($p,$config,$smtp,$wait);}
$rc=$c->get($retry);$rd=$c->recipients($retry);ce_ok($rc['counts']['failed']===1&&$rc['counts']['skipped']===0&&(int)$rd['items'][0]['retry_count']===5,'finite five retries then failed not skipped');ce_ok(strpos(json_encode($rd),'SECRET_SMTP_PASSWORD')===false,'transport exception secret never exposed');$smtp->fail=false;
$edit=ce_campaign($c,array(4));$oldRecipients=$p->query('SELECT id FROM email_campaign_recipients WHERE campaign_id='.$edit)->fetchAll();$back=$c->backToDraft($edit,1,1);ce_ok((int)$back['version']===2&&$back['recipient_count']==0&&!$p->query('SELECT id FROM email_campaign_recipients WHERE campaign_id='.$edit)->fetchAll(),'return draft invalidates old snapshot');ce_reject(function()use($c,$edit){$c->confirm($edit,1,1);},'stale confirmation rejected');
// 批次提交故障必须原子回滚游标、令牌哈希、收件关联和队列。
$atomic=ce_campaign($c,array(4,5,6));$c->confirm($atomic,1,1);$beforeOut=ce_rows($p,'notification_outbox');$beforeCampaign=$c->get($atomic);$p->failCommit=true;
ce_reject(function()use($c){$c->enqueueBatch();},'batch commit fault throws');
ce_ok(ce_rows($p,'notification_outbox')===$beforeOut&&$c->get($atomic)===$beforeCampaign,'batch rollback preserves queue cursor and counts');
ce_ok((int)$p->query('SELECT COUNT(*) FROM email_campaign_recipients WHERE campaign_id='.$atomic.' AND (outbox_id IS NOT NULL OR token_hash IS NOT NULL)')->fetchColumn()===0,'batch rollback leaves no linked outbox or token hash');
ce_ok($c->enqueueBatch()===3,'batch resumes cleanly after commit fault');
$beforeCalls=count($smtp->calls);$pauseWait=function($s)use($smtp){$smtp->enabled=false;};$r=customer_email_worker_run($p,$config,$smtp,$pauseWait);
ce_ok($r['paused']&&$r['claimed']===3&&count($smtp->calls)===$beforeCalls,'SMTP disabled during wait prevents all sends');
ce_ok((int)$p->query('SELECT COUNT(*) FROM notification_outbox o JOIN email_campaign_recipients r ON r.outbox_id=o.id WHERE r.campaign_id='.$atomic.' AND o.status="retry" AND o.retry_count=0 AND o.locked_at IS NULL')->fetchColumn()===3,'midrun pause releases all claims without consuming retries');
$smtp->enabled=true;customer_email_worker_run($p,$config,$smtp,$wait);ce_ok($c->get($atomic)['counts']['sent']===3,'released claims recover on next invocation');
$empty=ce_campaign($c,array(44,45));ce_ok((int)$c->get($empty)['recipient_count']===0,'no eligible recipients produces empty preview');ce_reject(function()use($c,$empty){$c->confirm($empty,1,1);},'empty snapshot cannot be confirmed');
$cancelPartial=ce_campaign($c,array(4,5,6));$c->confirm($cancelPartial,1,1);$c->enqueueBatch(1);$c->cancel($cancelPartial,1);$c->cancel($cancelPartial,1);ce_ok($c->get($cancelPartial)['counts']['cancelled']===3&&$c->enqueueBatch()===0,'repeated cancellation stops queued and not-yet-enqueued items');
ce_reject(function()use($c){$c->saveDraft(array('subject'=>"Header\r\nInjected",'body'=>'body','scope'=>'all'),1);},'subject header injection rejected');
ce_reject(function()use($c){$c->saveDraft(array('subject'=>'subject','body'=>'body','scope'=>'selected','user_ids'=>range(1,1001)),1);},'over 1000 selected recipients rejected');
// 退订仅影响通知群发，不得阻止未来交易邮件。
$newNo=$orders->create(1,1,1,'safe');$newOrder=$orders->getForUser($newNo,1);$newRow=$p->query('SELECT * FROM notification_outbox WHERE event_type="customer_order_created" ORDER BY id DESC LIMIT 1')->fetch();ce_ok($customer->eligibility($newRow,json_decode($newRow['payload_json'],true))===null,'broadcast opt-out does not block order mail');$r=customer_email_worker_run($p,$config,$smtp,$wait);ce_ok($r['sent']===1,'opted-out customer receives new-generation transaction through stub');
// 隔离库特意构造大小写相同邮箱，验证群发快照仍按邮箱唯一去重。
$p->exec('ALTER TABLE users DROP INDEX email');$p->prepare('INSERT INTO users(email,password_hash,status,created_at,updated_at) VALUES(?,"test","active",NOW(),NOW())')->execute(array('PERSON4@example.test'));
$dedupe=ce_campaign($c,array(4,(int)$p->lastInsertId()));ce_ok((int)$c->get($dedupe)['recipient_count']===1,'same mailbox case-insensitive snapshot deduplication');
ce_ok($legacy===$p->query('SELECT * FROM notification_outbox WHERE dedupe_key LIKE "legacy:%" ORDER BY id')->fetchAll(),'full worker suite preserves every legacy queue field');$claimed=$out->claim(20,true);ce_ok(count($claimed)===3,'reset-only independently claims exactly reset fixtures');foreach($claimed as $row)ce_ok($row['channel']==='email'&&$row['event_type']==='password_reset','reset-only scope remains strict');
foreach(array(array(),array('--once','--loop'),array('--once','--once'),array('--unknown')) as $a)ce_reject(function()use($a){customer_email_worker_mode($a);},'worker rejects invalid or ambiguous arguments');ce_ok(customer_email_worker_mode(array('--once'))==='once'&&customer_email_worker_mode(array('--loop'))==='loop','explicit worker modes');
echo "COMPLETE customer email isolated suite\n";
