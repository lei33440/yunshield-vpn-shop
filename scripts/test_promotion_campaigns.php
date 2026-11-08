<?php
// 仅限命令行运行；必须显式指定独立 MySQL 测试库，绝不加载站点配置。
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
date_default_timezone_set('Asia/Shanghai');
$dsn = getenv('CHAOXI_TEST_DSN');
if (!$dsn || strpos($dsn, 'mysql:') !== 0 || preg_match_all('/(?:^|;)\s*dbname\s*=/i', substr($dsn, 6)) !== 1 || !preg_match('/(?:^|;)dbname=(chaoxi_promotion_test_[A-Za-z0-9_]+)(?:;|$)/', substr($dsn, 6), $match)) {
    throw new RuntimeException('Explicit independent chaoxi_promotion_test_ MySQL database required; exactly one dbname allowed.');
}
$pdo = new PDO($dsn, getenv('CHAOXI_TEST_USER'), getenv('CHAOXI_TEST_PASSWORD'), array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false));
$actual = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if ($actual !== $match[1] || !preg_match('/\Achaoxi_promotion_test_[A-Za-z0-9_]+\z/D', $actual)) throw new RuntimeException('Actual database mismatch; migrations refused.');
$root = dirname(__DIR__);
spl_autoload_register(function ($class) use ($root) {
    foreach (array('/app/Services/', '/app/Core/') as $dir) {
        $file = $root.$dir.$class.'.php';
        if (is_file($file)) { require_once $file; return; }
    }
});
function pc_sql($sql, $args = array()) { global $pdo; $s=$pdo->prepare($sql); $s->execute($args); return $s; }
$passes = 0;
function pc_ok($condition, $name) {
    if (!$condition) throw new RuntimeException('FAIL: '.$name);
    $GLOBALS['passes']++;
    echo 'PASS: '.$name.PHP_EOL;
}
function pc_reject($fn, $name, $message) {
    try { $fn(); }
    catch (PDOException $e) { throw $e; }
    catch (InvalidArgumentException $e) { pc_ok(strpos($e->getMessage(), $message)!==false, $name.' [expected: '.$message.']'); return; }
    catch (RuntimeException $e) { pc_ok(strpos($e->getMessage(), $message)!==false, $name.' [expected: '.$message.']'); return; }
    throw new RuntimeException('FAIL: '.$name.' (unexpected success)');
}
function pc_release_twice($orderId) {
    global $pdo;
    $pdo->beginTransaction();
    try { $a=new ActivityService($pdo); $a->releaseCoupon($orderId); $a->releaseCoupon($orderId); $pdo->commit(); }
    catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
// 并发进程通过相同的独立库守卫连接，不执行迁移，也不读取站点配置。
if (isset($argv[1]) && $argv[1] === '--worker') {
    try {
        $no=(new OrderService($pdo))->create((int)$argv[2], (int)$argv[3], (int)$argv[4], 'parallel', $argv[5], 1, $argv[6]);
        echo $no; exit(0);
    } catch (Throwable $e) { fwrite(STDERR, get_class($e).': '.$e->getMessage()); exit(2); }
}
function pc_parallel($uid, $pid, $plan, $code, $key) {
    $children=array();
    for ($i=0; $i<2; $i++) {
        $pipes=array();
        $p=proc_open(array(PHP_BINARY,__FILE__,'--worker',(string)$uid,(string)$pid,(string)$plan,$code,$key), array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')), $pipes);
        if (!is_resource($p)) throw new RuntimeException('Cannot start concurrent worker.');
        fclose($pipes[0]); $children[]=array($p,$pipes);
    }
    $numbers=array(); $errors=array();
    foreach ($children as $child) {
        $out=stream_get_contents($child[1][1]); $err=stream_get_contents($child[1][2]);
        fclose($child[1][1]); fclose($child[1][2]); $exit=proc_close($child[0]);
        if ($exit!==0 || !preg_match('/\ACX[A-Z0-9]+\z/D',$out)) $errors[]=$out.$err;
        $numbers[]=$out;
    }
    if ($errors) throw new RuntimeException('Concurrent worker failed: '.implode('; ',$errors));
    return $numbers;
}
require $root.'/scripts/migrate.php';
require_once $root.'/app/Core/AdminCouponPages.php';
run_migrations($pdo,$root); run_migrations($pdo,$root);
pc_ok(true,'fresh schema and repeated run_migrations');
pc_ok((int)pc_sql('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ("promotion_campaigns","promotion_campaign_plans","promotion_campaign_categories")')->fetchColumn()===3,'all three promotion tables exist');
$index=pc_sql('SHOW INDEX FROM orders WHERE Key_name="uk_order_request"')->fetchAll();
pc_ok(count($index)===2 && (int)$index[0]['Non_unique']===0 && $index[0]['Column_name']==='user_id' && $index[1]['Column_name']==='request_key','order request key unique index is scoped to user');
$stamp=bin2hex(random_bytes(6)); $now=date('Y-m-d H:i:s');
$start=date('Y-m-d H:i:s',time()-3600); $end=date('Y-m-d H:i:s',time()+86400);
pc_sql('INSERT INTO users(email,password_hash,display_name,created_at,updated_at) VALUES(?,"isolated-test","promotion",?,?)',array('promotion'.$stamp.'@example.test',$now,$now)); $uid=(int)$pdo->lastInsertId();
pc_sql('INSERT INTO users(email,password_hash,display_name,created_at,updated_at) VALUES(?,"isolated-test","other",?,?)',array('promotion_other'.$stamp.'@example.test',$now,$now)); $other=(int)$pdo->lastInsertId();
pc_sql('INSERT INTO admin_users(email,password_hash,created_at,updated_at) VALUES(?,"isolated-test",?,?)',array('promotion_admin'.$stamp.'@example.test',$now,$now)); $aid=(int)$pdo->lastInsertId();
pc_sql('INSERT INTO vpn_categories(name,description,created_at,updated_at) VALUES(?,"original category",?,?)',array('promotion '.$stamp,$now,$now)); $category=(int)$pdo->lastInsertId();
$products=$plans=array();
foreach (array('vpn','sibling','account') as $kind) {
    $type=$kind==='account'?'account':'vpn';
    pc_sql('INSERT INTO products(product_type,vpn_category_id,name,description,traffic,region,device_limit,duration_days,price_cents,created_at,updated_at) VALUES(?,?,?,"isolated test","1G","test",1,30,1003,?,?)',array($type,$type==='vpn'?$category:null,'promotion '.$kind.' '.$stamp,$now,$now)); $products[$kind]=(int)$pdo->lastInsertId();
    pc_sql('INSERT INTO product_plans(product_id,name,duration_days,price_cents,created_at,updated_at) VALUES(?,"test",30,1003,?,?)',array($products[$kind],$now,$now)); $plans[$kind]=(int)$pdo->lastInsertId();
}
pc_sql('INSERT INTO product_plans(product_id,name,duration_days,price_cents,created_at,updated_at) VALUES(?,"unselected sibling plan",30,1003,?,?)',array($products['vpn'],$now,$now)); $secondPlan=(int)$pdo->lastInsertId();
pc_sql('UPDATE products SET account_platform="telegram",account_specification="合法来源测试规格",account_after_sales="隔离测试售后说明",traffic=NULL,region=NULL,device_limit=NULL,duration_days=NULL WHERE id=?',array($products['account']));
$service=new PromotionCampaignService($pdo); $orders=new OrderService($pdo); $activity=new ActivityService($pdo);
$form=array('name'=>'后台活动 '.$stamp,'title'=>'八折标题','subtitle'=>'活动副标题','description'=>'完整活动说明','starts_at'=>$start,'ends_at'=>$end,'plan_ids'=>array((string)$plans['vpn']),'category_descriptions'=>array($category=>'自定义分类介绍'),'coupon_mode'=>'new','new_coupon'=>array('code'=>'PROMO_'.$stamp,'name'=>'八折公共码','paid_percent'=>'80','total_quantity'=>'20','per_user_limit'=>'5','min_amount'=>'1.00','max_discount'=>'0','starts_at'=>date('Y-m-d H:i:s',time()-86400),'expires_at'=>date('Y-m-d H:i:s',time()+86400*7)));
$id=$service->save($form,$aid); $doc=$service->get($id); $couponId=(int)$doc['coupon_id']; $code=$doc['coupon']['code'];
$row=pc_sql('SELECT * FROM promotion_campaigns WHERE id=?',array($id))->fetch();
pc_ok($row['status']==='draft' && (int)$row['version_no']===1 && (int)$row['created_by']===$aid && (int)$row['updated_by']===$aid,'save creates audited version-one draft');
foreach (array('name','title','subtitle','description','starts_at','ends_at') as $field) pc_ok($row[$field]===$form[$field],'save persists '.$field);
pc_ok($doc['categories'][0]['description']===$form['category_descriptions'][$category] && (int)$doc['categories'][0]['id']===$category,'save persists category description');
$coupon=pc_sql('SELECT * FROM coupons WHERE id=?',array($couponId))->fetch();
pc_ok($coupon['scope']==='public' && $coupon['user_id']===null && $coupon['coupon_type']==='percent' && (int)$coupon['discount_percent']===20 && (int)$coupon['min_amount_cents']===100 && (int)$coupon['total_quantity']===20 && (int)$coupon['per_user_limit']===5 && (int)$coupon['max_discount_cents']===0 && $coupon['name']==='八折公共码','new_coupon paid_percent=80 creates public twenty-percent reduction with all limits');
pc_ok($coupon['starts_at']===$form['new_coupon']['starts_at'] && $coupon['expires_at']===$form['new_coupon']['expires_at'],'new_coupon explicit validity is persisted');
pc_ok($service->active()===null && $service->activeById($id)===null,'draft is not active');
pc_reject(function()use($orders,$uid,$products,$plans,$code){$orders->create($uid,$products['vpn'],$plans['vpn'],'draft',$code);},'draft coupon cannot be redeemed','绑定的活动');
$service->publish($id,$aid); $active=$service->activeById($id);
pc_ok($active && (int)$service->active()['id']===$id && $active['state']==='active' && substr($active['ends_at_iso'],-6)==='+08:00','publish exposes active and activeById with Beijing ISO dates');
pc_ok(array_map('intval',array_column($active['plans'],'id'))===array($plans['vpn']) && (int)$active['plans'][0]['discount_cents']===200 && (int)$active['plans'][0]['payable_cents']===803,'only explicit plan selected; 1003 cents discounts exactly 200 cents');
foreach (array(array($products['vpn'],$secondPlan),array($products['sibling'],$plans['sibling'])) as $selection) {
    pc_reject(function()use($orders,$uid,$selection,$code){$orders->create($uid,$selection[0],$selection[1],'scope',$code);},'unselected same-product or same-category plan rejected','不适用于当前方案');
}
$key=bin2hex(random_bytes(32)); $no=$orders->create($uid,$products['vpn'],$plans['vpn'],'idempotent',$code,1,$key);
pc_ok($orders->create($uid,$products['vpn'],$plans['vpn'],'idempotent',$code,1,$key)===$no,'same key and same parameters return original order');
$o=$orders->getForUser($no,$uid);
pc_ok((int)$o['original_amount_cents']===1003 && (int)$o['discount_amount_cents']===200 && (int)$o['total_amount_cents']===803 && $o['request_key']===$key,'order stores integer-cent discount and request key');
pc_ok((int)pc_sql('SELECT COUNT(*) FROM orders WHERE user_id=? AND request_key=?',array($uid,$key))->fetchColumn()===1 && (int)pc_sql('SELECT used_quantity FROM coupons WHERE id=?',array($couponId))->fetchColumn()===1 && (int)pc_sql('SELECT COUNT(*) FROM order_promotions WHERE order_id=? AND status="applied"',array($o['id']))->fetchColumn()===1,'duplicate request creates one order and one coupon reservation');
foreach (array(array($products['vpn'],$secondPlan,'idempotent',$code),array($products['sibling'],$plans['sibling'],'idempotent',$code),array($products['vpn'],$plans['vpn'],'changed',$code),array($products['vpn'],$plans['vpn'],'idempotent','')) as $params) {
    pc_reject(function()use($orders,$uid,$params,$key){$orders->create($uid,$params[0],$params[1],$params[2],$params[3],1,$key);},'same key rejects changed plan/product/note/coupon','同一订单请求');
}
foreach (array('bad',str_repeat('A',64),str_repeat('a',63)) as $badKey) pc_reject(function()use($orders,$uid,$products,$plans,$badKey){$orders->create($uid,$products['vpn'],$plans['vpn'],'','',1,$badKey);},'malformed request key rejected','订单请求标识');
$otherNo=$orders->create($other,$products['vpn'],$plans['vpn'],'idempotent',$code,1,$key);
pc_ok($otherNo!==$no && (int)pc_sql('SELECT used_quantity FROM coupons WHERE id=?',array($couponId))->fetchColumn()===2,'same key for another user creates independent order');
$orders->cancel($no,'user',$uid,'取消释放'); pc_release_twice($o['id']);
pc_ok((int)pc_sql('SELECT used_quantity FROM coupons WHERE id=?',array($couponId))->fetchColumn()===1 && pc_sql('SELECT status FROM order_promotions WHERE order_id=?',array($o['id']))->fetchColumn()==='reversed','cancel and repeated release do not decrement another reservation');
pc_ok($orders->create($uid,$products['vpn'],$plans['vpn'],'idempotent',$code,1,$key)===$no && $orders->getForUser($no,$uid)['status']==='cancelled','replay of cancelled key never reopens order');
$wallet=new WalletService($pdo); $wallet->adjust($uid,5000,'isolated promotion funding','promo_fund_'.$stamp,$aid);
$refundNo=$orders->create($uid,$products['vpn'],$plans['vpn'],'refund',$code); $refundOrder=$orders->getForUser($refundNo,$uid); $before=$wallet->balance($uid);
$orders->payWithBalance($refundNo,$uid); $orders->requestRefund($refundNo,$uid,'退款释放'); $orders->processRefund($refundNo,$aid,true,'isolated approved'); pc_release_twice($refundOrder['id']);
pc_ok($wallet->balance($uid)===$before && (int)pc_sql('SELECT used_quantity FROM coupons WHERE id=?',array($couponId))->fetchColumn()===1 && $orders->getForUser($refundNo,$uid)['status']==='refunded','refund restores actual paid amount and releases coupon exactly once');
pc_reject(function()use($orders,$refundNo,$aid){$orders->processRefund($refundNo,$aid,true,'duplicate');},'duplicate refund rejected','退款');
$parallelKey=bin2hex(random_bytes(32)); $numbers=pc_parallel($uid,$products['vpn'],$plans['vpn'],$code,$parallelKey);
pc_ok($numbers[0]===$numbers[1] && (int)pc_sql('SELECT COUNT(*) FROM orders WHERE user_id=? AND request_key=?',array($uid,$parallelKey))->fetchColumn()===1 && (int)pc_sql('SELECT used_quantity FROM coupons WHERE id=?',array($couponId))->fetchColumn()===2,'concurrent same-key workers return one order and consume one coupon');
$orders->cancel($numbers[0],'user',$uid,'parallel cleanup'); $orders->cancel($otherNo,'user',$other,'other cleanup');
$service->disable($id,$aid);
pc_ok($service->active()===null && $service->activeById($id)===null && $service->get($id)['state']==='disabled','disable removes active activity');
pc_reject(function()use($orders,$uid,$products,$plans,$code){$orders->create($uid,$products['vpn'],$plans['vpn'],'disabled',$code);},'disabled activity cannot create discounted order','绑定的活动');
$edit=$form; unset($edit['new_coupon']); $edit['coupon_mode']='existing'; $edit['coupon_id']=(string)$couponId;
$service->publish($id,$aid); $edit['version_no']=(string)$service->get($id)['version_no']; $edit['title']='编辑后标题';
$service->save($edit,$aid,$id);
pc_ok($service->get($id)['status']==='draft' && (int)$service->get($id)['version_no']===(int)$edit['version_no']+1 && $service->activeById($id)===null,'editing published activity returns draft and increments version');
pc_reject(function()use($service,$edit,$aid,$id){$service->save($edit,$aid,$id);},'stale optimistic-lock version rejected','其他操作更新');
$personal=$activity->issueCoupon($uid,null,'isolated personal',100);
$bad=$edit; $bad['version_no']=(string)$service->get($id)['version_no']; $bad['coupon_id']=(string)$personal['id'];
pc_reject(function()use($service,$bad,$aid,$id){$service->save($bad,$aid,$id);},'existing activity cannot replace coupon','绑定不可更改');
$bad=$edit; $bad['coupon_id']=(string)$personal['id']; unset($bad['version_no']);
pc_reject(function()use($service,$bad,$aid){$service->save($bad,$aid);},'new activity cannot bind personal coupon','公共优惠码');
$bad=$edit; unset($bad['version_no']);
pc_reject(function()use($service,$bad,$aid){$service->save($bad,$aid);},'coupon cannot bind a second campaign','已绑定其他活动');
// 通过保存活动测试时间窗口，不绕过活动管理接口。
foreach (array('future'=>array(time()+3600,time()+7200),'expired'=>array(time()-7200,time()-3600)) as $label=>$times) {
    $timeForm=$edit; $timeForm['version_no']=(string)$service->get($id)['version_no']; $timeForm['starts_at']=date('Y-m-d H:i:s',$times[0]); $timeForm['ends_at']=date('Y-m-d H:i:s',$times[1]); $service->save($timeForm,$aid,$id);
    if ($label==='future') $service->publish($id,$aid);
    else {
        pc_reject(function()use($service,$id,$aid){$service->publish($id,$aid);},'cannot publish already expired activity','发布失败');
        // 已发布活动到达结束时间后也必须停止展示。
        pc_sql('UPDATE promotion_campaigns SET status="published" WHERE id=?',array($id));
    }
    pc_ok($service->active()===null && $service->activeById($id)===null && $service->get($id)['state']===($label==='future'?'scheduled':'ended'),$label.' activity is not active');
    pc_reject(function()use($orders,$uid,$products,$plans,$code){$orders->create($uid,$products['vpn'],$plans['vpn'],'time',$code);},$label.' activity cannot create discounted order','绑定的活动');
}
$edit['version_no']=(string)$service->get($id)['version_no']; $service->save($edit,$aid,$id);
foreach (array('short-date','late-start','wrong-plan','minimum','inactive-coupon','off-sale-plan','off-sale-product') as $scenario) {
    if ($scenario==='short-date') pc_sql('UPDATE coupons SET expires_at=? WHERE id=?',array(date('Y-m-d H:i:s',time()+3600),$couponId));
    if ($scenario==='late-start') pc_sql('UPDATE coupons SET starts_at=? WHERE id=?',array(date('Y-m-d H:i:s',time()+60),$couponId));
    if ($scenario==='wrong-plan') pc_sql('UPDATE coupons SET product_plan_id=? WHERE id=?',array($secondPlan,$couponId));
    if ($scenario==='minimum') pc_sql('UPDATE coupons SET min_amount_cents=1004 WHERE id=?',array($couponId));
    if ($scenario==='inactive-coupon') pc_sql('UPDATE coupons SET status="inactive" WHERE id=?',array($couponId));
    if ($scenario==='off-sale-plan') pc_sql('UPDATE product_plans SET status="inactive" WHERE id=?',array($plans['vpn']));
    if ($scenario==='off-sale-product') pc_sql('UPDATE products SET status="inactive" WHERE id=?',array($products['vpn']));
    $beforeRow=pc_sql('SELECT * FROM promotion_campaigns WHERE id=?',array($id))->fetch();
    pc_reject(function()use($service,$id,$aid){$service->publish($id,$aid);},'publish rejects '.$scenario,'发布失败');
    pc_ok(pc_sql('SELECT * FROM promotion_campaigns WHERE id=?',array($id))->fetch()===$beforeRow,'failed publish preserves draft and version for '.$scenario);
    pc_sql('UPDATE coupons SET starts_at=?,expires_at=?,product_plan_id=0,min_amount_cents=100,status="active" WHERE id=?',array($coupon['starts_at'],$coupon['expires_at'],$couponId));
    pc_sql('UPDATE product_plans SET status="active" WHERE id=?',array($plans['vpn'])); pc_sql('UPDATE products SET status="active" WHERE id=?',array($products['vpn']));
}
// 已选方案在保存后可能变为规格不完整，发布时必须重新校验。
foreach (array('traffic','region','device_limit','duration_days','plan_duration_days') as $missing) {
    $table=$missing==='plan_duration_days'?'product_plans':'products';
    $column=$missing==='plan_duration_days'?'duration_days':$missing;
    $target=$missing==='plan_duration_days'?$plans['vpn']:$products['vpn'];
    $original=pc_sql('SELECT '.$column.' FROM '.$table.' WHERE id=?',array($target))->fetchColumn();
    $beforeRow=pc_sql('SELECT * FROM promotion_campaigns WHERE id=?',array($id))->fetch();
    try {
        pc_sql('UPDATE '.$table.' SET '.$column.'=? WHERE id=?',array(in_array($missing,array('traffic','region'),true)?'':0,$target));
        pc_reject(function()use($service,$id,$aid){$service->publish($id,$aid);},'publish rejects incomplete specification '.$missing,'发布失败');
        pc_ok(pc_sql('SELECT * FROM promotion_campaigns WHERE id=?',array($id))->fetch()===$beforeRow,'incomplete specification preserves draft and version: '.$missing);
    } finally {
        pc_sql('UPDATE '.$table.' SET '.$column.'=? WHERE id=?',array($original,$target));
    }
}
$bad=$edit; $bad['version_no']=(string)$service->get($id)['version_no']; $bad['starts_at']='2026-02-30T12:00';
pc_reject(function()use($service,$bad,$aid,$id){$service->save($bad,$aid,$id);},'invalid calendar date rejected','有效北京时间');
$bad=$edit; $bad['version_no']=(string)$service->get($id)['version_no']; $bad['ends_at']=$bad['starts_at'];
pc_reject(function()use($service,$bad,$aid,$id){$service->save($bad,$aid,$id);},'empty time interval rejected','结束时间必须晚于');
$bad=$edit; $bad['version_no']=(string)$service->get($id)['version_no']; $bad['plan_ids']=array((string)$plans['account']);
pc_reject(function()use($service,$bad,$aid,$id){$service->save($bad,$aid,$id);},'account plan cannot enter campaign selection','不是有效VPN方案');
$service->publish($id,$aid);
pc_reject(function()use($orders,$uid,$products,$plans,$code){$orders->create($uid,$products['account'],$plans['account'],'',$code);},'overseas account still rejects campaign coupon','账号商品不参与');
pc_reject(function()use($orders,$uid,$products,$plans){$orders->create($uid,$products['account'],$plans['account'],'','group:1');},'overseas account still rejects group promotion','账号商品不参与');
pc_reject(function()use($orders,$uid,$products,$plans,$code){$orders->create($uid,$products['vpn'],$plans['vpn'],'','group:1,'.$code);},'group and coupon cannot stack','拼团与优惠券不可叠加');
$activity->saveSetting('group',array('status'=>'enabled','config'=>array('plan_ids'=>array($plans['vpn']))),$aid);
$group=new GroupService($pdo); $gid=$group->createGroup($uid,$plans['vpn']); $groupNo=$orders->create($uid,$products['vpn'],$plans['vpn'],'group regression','group:'.$gid);
pc_ok($orders->create($uid,$products['vpn'],$plans['vpn'],'group regression','group:'.$gid)===$groupNo && (int)pc_sql('SELECT used_quantity FROM coupons WHERE id=?',array($couponId))->fetchColumn()===0,'existing group order idempotency remains independent of campaign coupon');
$plainAccount=$orders->create($uid,$products['account'],$plans['account'],'plain account');
pc_ok($orders->getForUser($plainAccount,$uid)['product_type_snapshot']==='account','account purchase without promotion remains allowed');
$personalNo=$orders->create($uid,$products['vpn'],$plans['vpn'],'personal regression',$personal['code']);
pc_ok((int)$orders->getForUser($personalNo,$uid)['total_amount_cents']===903,'unbound personal coupon remains usable');
$orders->cancel($personalNo,'user',$uid,'personal cleanup');
// 验证新活动绑定已有公共券，与编辑新建优惠券的活动场景区分。
$existingInput=admin_coupon_input($pdo,array('code'=>'EXISTING_'.$stamp,'name'=>'已有公共券','scope'=>'public','coupon_type'=>'fixed','status'=>'active','amount'=>'1.00','discount_percent'=>'0','max_discount'=>'0','min_amount'=>'0','total_quantity'=>'10','per_user_limit'=>'2','product_plan_id'=>(string)$plans['vpn'],'starts_at'=>'','expires_at'=>''));
$columns=array_keys($existingInput); $values=array_values($existingInput); $values[]=$now; $values[]=$now;
pc_sql('INSERT INTO coupons('.implode(',',$columns).',created_at,updated_at) VALUES('.implode(',',array_fill(0,count($values),'?')).')',$values); $existingCouponId=(int)$pdo->lastInsertId();
$boundForm=$edit; unset($boundForm['version_no']); $boundForm['coupon_id']=(string)$existingCouponId; $boundForm['name']='已有券绑定 '.$stamp;
$boundId=$service->save($boundForm,$aid);
pc_ok((int)$service->get($boundId)['coupon_id']===$existingCouponId && $service->activeById($boundId)===null,'new draft can bind an existing public coupon');
pc_reject(function()use($orders,$uid,$products,$plans,$existingInput){$orders->create($uid,$products['vpn'],$plans['vpn'],'existing draft',$existingInput['code']);},'existing coupon becomes unavailable while bound to draft','绑定的活动');
$service->publish($boundId,$aid);
pc_ok((int)$service->active()['id']===$boundId && (int)$service->activeById($boundId)['coupon_id']===$existingCouponId,'existing-coupon campaign can publish and become default active');
$boundNo=$orders->create($uid,$products['vpn'],$plans['vpn'],'existing public coupon',$existingInput['code']);
pc_ok((int)$orders->getForUser($boundNo,$uid)['total_amount_cents']===903,'existing plan-specific public coupon still discounts matching selected plan');
$orders->cancel($boundNo,'user',$uid,'existing coupon cleanup'); $service->disable($boundId,$aid);
pc_ok((int)$service->active()['id']===$id && $service->activeById($boundId)===null,'active falls back but activeById never exposes another campaign');
pc_ok((int)pc_sql('SELECT COUNT(*) FROM admin_logs WHERE target_type="promotion_campaign" AND target_id=?',array($id))->fetchColumn()>5,'campaign management emits audit records');
// 即使 PHP 默认时区不同，优惠券和活动日期仍按北京时间解释。
try {
    foreach (array('UTC','America/New_York') as $timezone) {
        date_default_timezone_set($timezone);
        $beijingNow=new DateTimeImmutable('now',new DateTimeZone('Asia/Shanghai'));
        foreach (array('valid'=>array(-3600,3600),'future'=>array(3600,7200),'expired'=>array(-7200,-3600)) as $window=>$offsets) {
            $tzForm=$form;
            $tzForm['name']='timezone '.$timezone.' '.$window.' '.$stamp;
            $tzForm['starts_at']=$beijingNow->setTimestamp($beijingNow->getTimestamp()+$offsets[0])->format('Y-m-d H:i:s');
            $tzForm['ends_at']=$beijingNow->setTimestamp($beijingNow->getTimestamp()+$offsets[1])->format('Y-m-d H:i:s');
            $tzForm['new_coupon']['code']='TZ_'.strtoupper(bin2hex(random_bytes(8)));
            $tzForm['new_coupon']['per_user_limit']='10';
            // 优惠券和活动使用完全相同的北京时间区间，包括当前时间前后一小时的有效窗口。
            $tzForm['new_coupon']['starts_at']=$tzForm['starts_at'];
            $tzForm['new_coupon']['expires_at']=$tzForm['ends_at'];
            $tzId=$service->save($tzForm,$aid);
            if ($window==='expired') {
                // 模拟此前已发布、现已超过优惠券及活动结束时间的活动。
                pc_sql('UPDATE promotion_campaigns SET status="published" WHERE id=?',array($tzId));
            } else {
                $service->publish($tzId,$aid);
            }
            $tzCode=$tzForm['new_coupon']['code'];
            if ($window==='valid') {
                $tzNo=$orders->create($uid,$products['vpn'],$plans['vpn'],'timezone '.$timezone,$tzCode);
                $tzOrder=$orders->getForUser($tzNo,$uid);
                pc_ok((int)$tzOrder['original_amount_cents']===1003 && (int)$tzOrder['discount_amount_cents']===200 && (int)$tzOrder['total_amount_cents']===803 && $service->activeById($tzId)!==null,'BJT +/-1h bound coupon gives correct actual order discount under '.$timezone);
                $orders->cancel($tzNo,'user',$uid,'timezone cleanup');
            } else {
                pc_ok($service->activeById($tzId)===null && $service->get($tzId)['state']===($window==='future'?'scheduled':'ended'),$window.' BJT coupon/campaign remains inactive under '.$timezone);
                pc_reject(function()use($orders,$uid,$products,$plans,$tzCode,$timezone){$orders->create($uid,$products['vpn'],$plans['vpn'],'timezone '.$timezone,$tzCode);},$window.' BJT bound coupon rejects actual order under '.$timezone,'绑定的活动');
            }
            $service->disable($tzId,$aid);
        }
    }
} finally {
    date_default_timezone_set('Asia/Shanghai');
}
echo 'TEST_DATABASE='.$actual.PHP_EOL.'ASSERTIONS='.$passes.PHP_EOL.'ALL TESTS PASSED'.PHP_EOL;
echo 'NOTE: AdminPromotionPages browser permission/CSRF and normal-navigation acceptance must be run separately on local 8081.'.PHP_EOL;
