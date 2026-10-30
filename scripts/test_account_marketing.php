<?php
// Explicit isolated DSN only. No site configuration, migrations, workers or gateways.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
date_default_timezone_set('Asia/Shanghai');
set_error_handler(function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
$dsn=getenv('CHAOXI_TEST_DSN');
if(!$dsn||strpos($dsn,'mysql:')!==0||preg_match_all('/(?:^|;)\s*dbname\s*=/i',substr($dsn,6))!==1||!preg_match('/(?:^|;)dbname=(chaoxi_wallet_test_[A-Za-z0-9_]+)(?:;|$)/',substr($dsn,6),$match))throw new RuntimeException('Explicit isolated test DSN required.');
$expected=$match[1];$pdo=new PDO($dsn,getenv('CHAOXI_TEST_USER'),getenv('CHAOXI_TEST_PASSWORD'),array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false));
$actual=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();if($actual!==$expected||!preg_match('/\Achaoxi_wallet_test_[A-Za-z0-9_]+\z/D',$actual))throw new RuntimeException('Actual test database mismatch.');
$root=dirname(__DIR__);spl_autoload_register(function($c)use($root){foreach(array('/app/Services/','/app/Core/')as$d){$f=$root.$d.$c.'.php';if(is_file($f)){require_once$f;return;}}});
function am_ok($ok,$name){if(!$ok)throw new RuntimeException('FAIL: '.$name);echo 'PASS: '.$name.PHP_EOL;}
function am_reject($fn,$name,$expected='账号'){try{$fn();}catch(PDOException$e){throw$e;}catch(RuntimeException$e){am_ok(strpos($e->getMessage(),$expected)!==false,$name.' (expected rejection: '.$expected.')');return;}catch(InvalidArgumentException$e){am_ok(strpos($e->getMessage(),$expected)!==false,$name.' (expected rejection: '.$expected.')');return;}throw new RuntimeException('FAIL: '.$name);}
function am_sql($sql,$args=array()){global$pdo;$s=$pdo->prepare($sql);$s->execute($args);return$s;}
function am_tx_reject($fn,$name,$expected='账号'){global$pdo;$pdo->beginTransaction();try{am_reject($fn,$name,$expected);}finally{if($pdo->inTransaction())$pdo->rollBack();}}
$stamp=bin2hex(random_bytes(6));$now=date('Y-m-d H:i:s');$activity=new ActivityService($pdo);$group=new GroupService($pdo);$orders=new OrderService($pdo);
// Do not overwrite shared activity settings permanently, even if an assertion fails.
$saved=am_sql('SELECT * FROM activity_settings WHERE activity_key IN ("referral","leader","group")')->fetchAll();
try{
$uids=array();for($i=0;$i<4;$i++){am_sql('INSERT INTO users(email,password_hash,display_name,created_at,updated_at) VALUES(?,"isolated-test","marketing",?,?)',array('am'.$i.$stamp.'@example.test',$now,$now));$uids[]=(int)$pdo->lastInsertId();}list($inviter,$buyer,$legacyBuyer,$accountBuyer)=$uids;
am_sql('INSERT INTO admin_users(email,password_hash,created_at,updated_at) VALUES(?,"isolated-test",?,?)',array('am'.$stamp.'@example.test',$now,$now));$admin=(int)$pdo->lastInsertId();
$products=$plans=array();foreach(array('vpn','account')as$type){am_sql('INSERT INTO products(product_type,name,description,traffic,region,duration_days,price_cents,created_at,updated_at) VALUES(?,?,"isolated test",?,?,?,1000,?,?)',array($type,'marketing '.$type,$type==='vpn'?'1G':null,$type==='vpn'?'test':null,$type==='vpn'?30:null,$now,$now));$products[$type]=(int)$pdo->lastInsertId();am_sql('INSERT INTO product_plans(product_id,name,duration_days,price_cents,created_at,updated_at) VALUES(?,"test",?,1000,?,?)',array($products[$type],$type==='vpn'?30:null,$now,$now));$plans[$type]=(int)$pdo->lastInsertId();}
am_sql('UPDATE products SET account_platform="telegram",account_specification="合法来源测试规格",account_after_sales="隔离测试售后说明",device_limit=NULL WHERE id=?',array($products['account']));
foreach(array($buyer,$legacyBuyer,$accountBuyer)as$uid)am_sql('INSERT INTO referral_relations(user_id,referrer_user_id,status,source,created_at,updated_at) VALUES(?,?,"active","register",?,?)',array($uid,$inviter,$now,$now));
$activity->saveSetting('referral',array('status'=>'enabled','config'=>array('rate_bps'=>1000)), $admin);
$activity->saveSetting('leader',array('status'=>'enabled','config'=>array('required_referrals'=>2,'reward_plan_id'=>$plans['vpn'])), $admin);
$activity->saveSetting('group',array('status'=>'enabled','config'=>array('plan_ids'=>array($plans['vpn']))),$admin);
require_once $root.'/app/Core/AdminCouponPages.php';
if(!function_exists('e')){function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}}
if(!function_exists('csrf_field')){function csrf_field(){return '';}}
$form=array('code'=>'AM_'.$stamp,'name'=>'test','scope'=>'public','coupon_type'=>'fixed','status'=>'active','amount'=>'1.00','product_plan_id'=>(string)$plans['vpn']);
am_ok(admin_coupon_input($pdo,$form)['product_plan_id']===$plans['vpn'],'admin coupon accepts VPN plan');
$bad=$form;$bad['product_plan_id']=(string)$plans['account'];am_reject(function()use($pdo,$bad){admin_coupon_input($pdo,$bad);},'admin coupon rejects account plan');
$html=admin_coupon_form($pdo,null,'/isolated-test');am_ok(strpos($html,'<option value="'.$plans['account'].'"')===false&&strpos($html,'<option value="'.$plans['vpn'].'"')!==false,'admin coupon choices exclude account and retain VPN');
// Invoke the real route closure with CLI-only auth/CSRF/redirect stubs.
class Security{public static function requireAdmin(){global$admin;return$admin;}}
function post_csrf(){}
function flash($kind,$message){$GLOBALS['am_flash']=array($kind,$message);}
function redirect($url){}
$router=new class{public $posts=array();public function get($path,$fn){}public function post($path,$fn){$this->posts[$path]=$fn;}};
register_admin_coupon_routes($router,$pdo,array());
$toggle=$router->posts['/admin/coupons/{id}/toggle'];
$badCoupon=$activity->issueCoupon($buyer,null,'toggle fixture',100);am_sql('UPDATE coupons SET product_plan_id=?,status="inactive" WHERE id=?',array($plans['account'],$badCoupon['id']));
$auditBefore=(int)am_sql('SELECT COUNT(*) FROM admin_logs')->fetchColumn();$_POST=array('status'=>'active');$toggle((string)$badCoupon['id']);
am_ok($GLOBALS['am_flash'][0]==='error'&&strpos($GLOBALS['am_flash'][1],'账号')!==false&&am_sql('SELECT status FROM coupons WHERE id=?',array($badCoupon['id']))->fetchColumn()==='inactive'&&(int)am_sql('SELECT COUNT(*) FROM admin_logs')->fetchColumn()===$auditBefore,'historical account coupon toggle rejects without audit or status mutation');
am_sql('UPDATE coupons SET product_plan_id=? WHERE id=?',array($plans['vpn'],$badCoupon['id']));$toggle((string)$badCoupon['id']);
am_ok($GLOBALS['am_flash'][0]==='success'&&am_sql('SELECT status FROM coupons WHERE id=?',array($badCoupon['id']))->fetchColumn()==='active','VPN coupon toggle remains valid');
$c=$activity->issueCoupon($buyer,null,'isolated',100);$before=am_sql('SELECT * FROM coupons WHERE id=?',array($c['id']))->fetch();
am_tx_reject(function()use($activity,$buyer,$c,$plans){$activity->prepareCoupon($buyer,$c['code'],$plans['account'],1000);},'account coupon prepare rejects');
foreach(array('group','leader')as$key)am_reject(function()use($activity,$plans,$admin,$key){$activity->saveSetting($key,array('status'=>'enabled','config'=>$key==='group'?array('plan_ids'=>array($plans['account'])):array('reward_plan_id'=>$plans['account'])),$admin);},'account '.$key.' config rejects');
am_reject(function()use($activity,$buyer,$plans){$activity->issueCoupon($buyer,null,'bad',100,0,30,0,$plans['account']);},'account scoped coupon issuance rejects');
am_reject(function()use($group,$buyer,$plans){$group->createGroup($buyer,$plans['account']);},'account create group rejects','所选方案未开放拼团');
$gid=$group->createGroup($inviter,$plans['vpn']);
// Simulate corrupt pre-existing campaign mapping: entry gates must not trust it.
am_sql('INSERT INTO group_campaign_plans(campaign_id,product_plan_id,created_at) VALUES(?,?,?)',array($gid,$plans['account'],$now));
am_reject(function()use($group,$gid,$buyer,$plans){$group->joinGroup($gid,$buyer,$plans['account']);},'forged account join rejects','该团不允许所选方案');
am_ok((int)am_sql('SELECT COUNT(*) FROM group_members WHERE campaign_id=? AND user_id=?',array($gid,$buyer))->fetchColumn()===0,'rejected join creates no membership');
$group->joinGroup($gid,$buyer,$plans['vpn']);
$reservedBefore=am_sql('SELECT reserved_members FROM group_campaigns WHERE id=?',array($gid))->fetchColumn();
am_tx_reject(function()use($group,$gid,$buyer,$plans){$group->preparePromotion($gid,$buyer,$plans['account'],1000,true);},'account promotion reservation rejects','方案不在该团快照中');
am_ok(am_sql('SELECT reserved_members FROM group_campaigns WHERE id=?',array($gid))->fetchColumn()===$reservedBefore,'forged promotion does not reserve seat');
am_reject(function()use($activity,$gid,$plans,$admin){$activity->saveGroupPlans($gid,array($plans['account']),$admin);},'account campaign plan save rejects');
// Core create must reject before occupying coupons or existing group context.
foreach(array($c['code'],'group:'.$gid)as$code)am_reject(function()use($orders,$buyer,$products,$plans,$code){$orders->create($buyer,$products['account'],$plans['account'],'',$code);},'account forged order promotion rejects');
am_ok(am_sql('SELECT * FROM coupons WHERE id=?',array($c['id']))->fetch()===$before,'forged marketing leaves coupon unchanged');
$accountNo=$orders->create($buyer,$products['account'],$plans['account'],'');$orders->confirmPayment($accountNo,$admin);$account=$orders->getForUser($accountNo,$buyer);
$pdo->beginTransaction();try{$activity->settlePaidOrder($account['id']);$pdo->commit();}catch(Throwable$e){$pdo->rollBack();throw$e;}
am_ok((int)am_sql('SELECT COUNT(*) FROM referral_commissions WHERE order_id=?',array($account['id']))->fetchColumn()===0,'paid account earns no commission even through direct settle');
am_tx_reject(function()use($activity,$account,$c){$activity->applyPromotion($account['id'],array('coupon'=>array('id'=>$c['id']),'discount'=>100),$c['code']);},'forged account apply rejects');
am_tx_reject(function()use($group,$gid,$buyer,$account){$group->attachOrder($account['id'],array('group'=>array('id'=>$gid,'member_user_id'=>$buyer),'discount'=>200));},'account attach rejects before seat mutation');
am_ok((int)am_sql('SELECT COUNT(*) FROM group_orders WHERE order_id=?',array($account['id']))->fetchColumn()===0,'no account seat side effect');
am_tx_reject(function()use($group,$account){$group->markPaid($account['id'],array('group'=>array('id'=>0),'seat'=>array('user_id'=>$account['user_id'])));},'direct forged account group settlement rejects');
$pdo->beginTransaction();try{
am_sql('INSERT INTO group_orders(campaign_id,order_id,user_id,role,status,amount_cents,created_at,updated_at) VALUES(?,?,?,"member","pending",200,?,?)',array($gid,$account['id'],$buyer,$now,$now));
am_reject(function()use($group,$account){$group->paymentGate($account['id']);},'historical corrupt account seat cannot pass payment gate');
$group->reverseOrder($account['id']);
am_ok(am_sql('SELECT status FROM group_orders WHERE order_id=?',array($account['id']))->fetchColumn()==='cancelled','historical account seat reversal remains allowed');
}finally{$pdo->rollBack();}
$vpnNo=$orders->create($buyer,$products['vpn'],$plans['vpn'],'');$orders->confirmPayment($vpnNo,$admin);$vpn=$orders->getForUser($vpnNo,$buyer);
am_ok((int)am_sql('SELECT COUNT(*) FROM referral_commissions WHERE order_id=? AND status="credited"',array($vpn['id']))->fetchColumn()===1,'account-first purchase preserves VPN first commission');
$second=$orders->create($buyer,$products['vpn'],$plans['vpn'],'');$orders->confirmPayment($second,$admin);
am_ok((int)am_sql('SELECT COUNT(*) FROM referral_commissions WHERE referred_user_id=?',array($buyer))->fetchColumn()===1,'second VPN purchase remains ineligible');
// Corrupt account commission cannot count toward leader rewards or first VPN qualification.
am_sql('INSERT INTO referral_commissions(commission_no,referrer_user_id,referred_user_id,order_id,amount_cents,rate_bps,settlement_mode,status,created_at,updated_at) VALUES(?,?,?,?,100,1000,"legacy","available",?,?)',array('AM'.$stamp,$inviter,$accountBuyer,$account['id'],$now,$now));
am_ok($activity->leaderSummary($inviter)['qualified']===1,'leader display excludes account commission snapshot');
$pdo->beginTransaction();try{$activity->settlePaidOrder($orders->getForUser($second,$buyer)['id']);$pdo->commit();}catch(Throwable$e){$pdo->rollBack();throw$e;}
am_ok((int)am_sql('SELECT COUNT(*) FROM leader_rewards WHERE leader_user_id=?',array($inviter))->fetchColumn()===0,'account commission cannot trigger early leader reward');
$third=$orders->create($accountBuyer,$products['vpn'],$plans['vpn'],'');$orders->confirmPayment($third,$admin);
am_ok((int)am_sql('SELECT COUNT(*) FROM referral_commissions WHERE referred_user_id=?',array($accountBuyer))->fetchColumn()===2,'corrupt account commission does not consume first VPN eligibility');
am_ok($activity->leaderSummary($inviter)['qualified']===2,'leader display counts distinct valid VPN buyers');
am_ok((int)am_sql('SELECT COUNT(*) FROM leader_rewards WHERE leader_user_id=?',array($inviter))->fetchColumn()===1,'leader actual reward uses VPN eligible count');
// Refund corrections remain allowed regardless of account snapshot.
$pdo->beginTransaction();try{$activity->reverseOrder($account['id']);$pdo->commit();}catch(Throwable$e){$pdo->rollBack();throw$e;}
am_ok(am_sql('SELECT status FROM referral_commissions WHERE commission_no=?',array('AM'.$stamp))->fetchColumn()==='reversed','account historical commission reversal remains available');
am_sql('INSERT INTO referral_commissions(commission_no,referrer_user_id,referred_user_id,amount_cents,rate_bps,settlement_mode,status,created_at,updated_at) VALUES(?,?,?,100,1000,"legacy","available",?,?)',array('LEG'.$stamp,$inviter,$legacyBuyer,$now,$now));
am_ok($activity->leaderSummary($inviter)['qualified']===3,'unlinked legacy commissions retain qualification');
$legacyNo=$orders->create($legacyBuyer,$products['vpn'],$plans['vpn'],'');$orders->confirmPayment($legacyNo,$admin);
am_ok((int)am_sql('SELECT COUNT(*) FROM referral_commissions WHERE referred_user_id=?',array($legacyBuyer))->fetchColumn()===1,'unlinked legacy commission retains first-order exclusion');
echo 'Account marketing regression passed.'.PHP_EOL;
}finally{
if($pdo->inTransaction())$pdo->rollBack();
am_sql('DELETE FROM activity_settings WHERE activity_key IN ("referral","leader","group")');
foreach($saved as$row){$cols=array_keys($row);am_sql('INSERT INTO activity_settings (`'.implode('`,`',$cols).'`) VALUES('.implode(',',array_fill(0,count($cols),'?')).')',array_values($row));}
}
