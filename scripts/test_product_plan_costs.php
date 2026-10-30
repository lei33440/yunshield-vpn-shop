<?php
// 仅显式隔离 DSN；不加载站点配置、不建库、不调用网络或工作进程。
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$dsn=getenv('CHAOXI_TEST_DSN');$parts=$dsn&&strpos($dsn,'mysql:')===0?explode(';',substr($dsn,6)):array();$names=array();
foreach($parts as $part){if(preg_match('/^\s*dbname\s*=(.*)$/i',$part,$m))$names[]=$m[1];}
if(count($names)!==1||!preg_match('/\Achaoxi_wallet_test_[A-Za-z0-9_]+\z/D',$names[0]))throw new RuntimeException('必须显式指定唯一的隔离测试库名称');
$pdo=new PDO($dsn,getenv('CHAOXI_TEST_USER'),getenv('CHAOXI_TEST_PASSWORD'),array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false));
if($pdo->query('SELECT DATABASE()')->fetchColumn()!==$names[0])throw new RuntimeException('实际数据库与隔离库名称不符');
date_default_timezone_set('UTC');set_error_handler(function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
$root=dirname(__DIR__);spl_autoload_register(function($class)use($root){foreach(array('/app/Services/','/app/Core/') as $dir)if(is_file($f=$root.$dir.$class.'.php')){require_once $f;return;}});require $root.'/scripts/migrate.php';

// 用法：默认新装；--upgrade 模拟仅缺成本表的升级。两种模式各需一个空库。
// 不清库，保留现场；再次执行须由操作者提供另一个空隔离库。
function pc_ok($yes,$name){if(!$yes)throw new RuntimeException('失败：'.$name);echo 'PASS '.$name.PHP_EOL;}
function pc_reject($fn,$class,$name){
    try{$fn();}catch(Throwable $e){if($e instanceof $class){pc_ok(true,$name);return $e;}throw $e;}
    throw new RuntimeException('失败：未拒绝'.$name);
}
function pc_text($token){return is_array($token)?$token[1]:$token;}
function pc_open($token){return $token==='{'||(is_array($token)&&in_array($token[0],array(T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES),true));}
// 仅提取顶层命名函数；字符串、注释、heredoc 内的花括号不作为函数边界。
function pc_functions($file,$wanted){
    $tokens=token_get_all(file_get_contents($file));$depth=0;$found=array();
    for($i=0,$n=count($tokens);$i<$n;$i++){
        $t=$tokens[$i];
        if($depth===0&&is_array($t)&&$t[0]===T_FUNCTION){
            $j=$i+1;while($j<$n&&is_array($tokens[$j])&&in_array($tokens[$j][0],array(T_WHITESPACE,T_COMMENT,T_DOC_COMMENT),true))$j++;
            if(isset($tokens[$j])&&is_array($tokens[$j])&&$tokens[$j][0]===T_STRING&&in_array($tokens[$j][1],$wanted,true)){
                $name=$tokens[$j][1];$code='';$body=false;$balance=0;
                for(;$i<$n;$i++){$x=$tokens[$i];$code.=pc_text($x);if(pc_open($x)){$body=true;$balance++;}elseif($x==='}')$balance--;if($body&&$balance===0)break;}
                if(!$body||$balance!==0||isset($found[$name]))throw new RuntimeException('函数边界不唯一或不完整');
                $found[$name]=$code;continue;
            }
        }
        if(pc_open($t))$depth++;elseif($t==='}')$depth--;
    }
    foreach($wanted as $name){if(!isset($found[$name]))throw new RuntimeException('未找到真实顶层函数 '.$name);eval($found[$name]);}
}
// 仅截取明确的路由注册语句；不执行 index.php 的启动、配置或其它路由。
function pc_route_source($source,$method,$path){
    $needle='$router->'.$method.'('.var_export($path,true).',';
    $start=strpos($source,$needle);
    if($start===false||strpos($source,$needle,$start+1)!==false)throw new RuntimeException('路由注册边界不唯一');
    $tokens=token_get_all('<?php '.substr($source,$start));$code='';$depth=0;
    foreach($tokens as $t){if(is_array($t)&&$t[0]===T_OPEN_TAG)continue;$code.=pc_text($t);if(pc_open($t)||$t==='('||$t==='[')$depth++;elseif($t==='}'||$t===')'||$t===']')$depth--;if($t===';'&&$depth===0)return $code;}
    throw new RuntimeException('路由注册语句未闭合');
}
// 明确模拟 Security guard，非真实 Security 类的鉴权/会话/退出行为测试。
// Router、post_csrf、业务函数和 edit/delete 闭包均来自真实代码。
class PCGuardFailure extends RuntimeException {}
class Security {
    public static $admin=false;
    public static function requireAdmin(){if(!self::$admin)throw new PCGuardFailure('模拟未授权');return 1;}
    public static function csrfToken(){return '隔离测试令牌';}
    public static function checkCsrf($token){if($token!==self::csrfToken())throw new PCGuardFailure('模拟令牌拒绝');}
}
function redirect($url){$GLOBALS['pc_redirect']=$url;return $url;}
function flash($type,$message){$GLOBALS['pc_flash']=array($type,$message);}
function page($title,$body,$config){$GLOBALS['pc_page']=$body;}
function pc_parse($rows,$type='vpn'){$_POST=array('plans'=>$rows);return product_plans_input($type);}
function pc_row($name,$cost='12.34',$id=0){return array('id'=>(string)$id,'name'=>$name,'duration_days'=>'30','price'=>'29.99','status'=>'active','sort_order'=>'0','cost'=>$cost);}
function pc_product($pdo,$name,$type='vpn'){
    $pdo->prepare('INSERT INTO products(name,description,product_type,traffic,region,duration_days,price_cents,created_at,updated_at) VALUES(?,?,?,"10G","测试",30,2999,NOW(),NOW())')->execute(array($name,'隔离公开说明',$type));return (int)$pdo->lastInsertId();
}
function pc_save($pdo,$id,$plans){$pdo->beginTransaction();try{save_product_plans($pdo,$id,$plans,'2026-10-29 12:00:00');$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}}
function pc_rows($pdo,$id){$s=$pdo->prepare('SELECT * FROM product_plans WHERE product_id=? ORDER BY id');$s->execute(array($id));return $s->fetchAll();}
function pc_cost($pdo,$id){$s=$pdo->prepare('SELECT cost_cents FROM product_plan_costs WHERE product_plan_id=?');$s->execute(array($id));$v=$s->fetchColumn();return $v===false?null:(int)$v;}
function pc_hash($pdo,$tables){$data=array();foreach($tables as $t){$rows=$pdo->query('SELECT * FROM '.$t)->fetchAll();$encoded=array_map('serialize',$rows);sort($encoded,SORT_STRING);$data[$t]=$encoded;}return hash('sha256',serialize($data));}
function pc_order($pdo,$pid,$plan,$no){
    $pdo->prepare('INSERT INTO orders(order_no,user_id,product_id,product_plan_id,product_name_snapshot,plan_name_snapshot,product_description_snapshot,traffic_snapshot,region_snapshot,device_limit_snapshot,duration_days_snapshot,unit_price_cents_snapshot,original_amount_cents,total_amount_cents,created_at,updated_at) VALUES(?,1,?,?,"原套餐","原方案","原订单说明","10G","测试",1,30,2999,2999,2999,NOW(),NOW())')->execute(array($no,$pid,$plan));return (int)$pdo->lastInsertId();
}
function pc_edit_post($plans){return array('_csrf'=>Security::csrfToken(),'product_type'=>'vpn','name'=>'路由编辑套餐','description'=>'路由隔离说明','traffic'=>'10G','region'=>'测试','device_limit'=>'1','sort_order'=>'0','status'=>'active','plans'=>$plans);}
function pc_dispatch($router,$method,$path,$post=array()){$_POST=$post;$GLOBALS['pc_flash']=null;$GLOBALS['pc_redirect']=null;$GLOBALS['pc_page']=null;return $router->dispatch($method,$path);}

try{
    pc_ok((int)$pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn()===0,'实际隔离库为空，允许执行测试');
    if(count($argv)>2||(isset($argv[1])&&$argv[1]!=='--upgrade'))throw new InvalidArgumentException('仅支持默认新装或 --upgrade 参数');
    $upgrade=isset($argv[1]);
    $source=file_get_contents($root.'/public/index.php');
    pc_functions($root.'/public/index.php',array('product_form','product_plans_input','save_product_plans','product_input','post_csrf','money','plan_description_html','user_nav_icon','shop_plan_period','shop_plan_key','shop_plan_daily','shop_traffic_gb','shop_tagline'));
    pc_functions($root.'/app/Core/AccountProductPages.php',array('catalog_type','account_catalog_ready','catalog_vpn_condition'));
    pc_functions($root.'/app/Core/Bootstrap.php',array('e'));
    pc_functions($root.'/app/Core/Security.php',array('csrf_field'));
    $router=new Router();$config=array();
    foreach(array(array('get','/products'),array('get','/products/{id}'),array('get','/admin/products/{id}/edit'),array('post','/admin/products/{id}/edit'),array('post','/admin/products/{id}/delete')) as $r)eval(pc_route_source($source,$r[0],$r[1]));
    pc_ok(true,'已提取真实顶层函数与路由，未执行应用；仅安全守卫及页面输出使用模拟');

    if($upgrade){
        $schema=file_get_contents($root.'/database/schema.sql');$count=0;
        $schema=preg_replace('/CREATE TABLE IF NOT EXISTS product_plan_costs\s*\([^;]+;\s*/','',$schema,-1,$count);
        pc_ok($count===1,'升级基线仅移除本次独立成本表 DDL');
        foreach(array_filter(array_map('trim',preg_split('/;\s*(?:\r?\n|$)/',$schema))) as $sql)$pdo->exec($sql);
        pc_ok((int)$pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="product_plan_costs"')->fetchColumn()===0,'升级前没有成本表');
    }else{run_migrations($pdo,$root);pc_ok(true,'完整迁移可从空库新装');}
    $pdo->exec('INSERT INTO users(email,password_hash,referral_code,balance_cents,created_at,updated_at) VALUES("cost@example.test","隔离哈希","COSTFIXTURE",7001,NOW(),NOW())');
    $legacy=pc_product($pdo,'原有套餐');
    $pdo->prepare('INSERT INTO product_plans(product_id,name,duration_days,price_cents,created_at,updated_at) VALUES(?,"原方案",30,2999,NOW(),NOW())')->execute(array($legacy));$legacyPlan=(int)$pdo->lastInsertId();
    $oid=pc_order($pdo,$legacy,$legacyPlan,'COST_ORIGINAL');
    $pdo->prepare('INSERT INTO payment_records(order_id,method,amount_cents,status,created_at,updated_at) VALUES(?,"balance",2999,"confirmed",NOW(),NOW())')->execute(array($oid));
    $pdo->prepare('INSERT INTO wallet_transactions(user_id,amount_cents,balance_before_cents,balance_after_cents,type,order_id,idempotency_key,created_at) VALUES(1,-2999,10000,7001,"order_payment",?,"cost-original",NOW())')->execute(array($oid));
    $protected=array('users','orders','payment_records','wallet_transactions');$moneyHash=pc_hash($pdo,$protected);
    run_migrations($pdo,$root);run_migrations($pdo,$root);
    pc_ok(pc_hash($pdo,$protected)===$moneyHash,($upgrade?'无成本表升级':'新装重复迁移').'两次完整迁移保留原订单和资金保护哈希');
    pc_ok((int)$pdo->query('SELECT COUNT(*) FROM product_plan_costs')->fetchColumn()===0,'迁移不臆造历史成本');

    foreach(array(array('',null),array('0',0),array('0.01',1),array('99999999.99',9999999999)) as $case){$p=pc_parse(array(pc_row('解析',$case[0])));pc_ok(array_key_exists('cost_cents',$p[0])&&$p[0]['cost_cents']===$case[1],'成本解析边界：'.($case[0]===''?'空字符串':$case[0]));}
    $badValues=array('1.001','1e2','-1',array(),null,1,'100000000','99999999.999',true,0.01);
    foreach($badValues as $i=>$bad)pc_reject(function()use($bad){pc_parse(array(pc_row('非法',$bad)));},'InvalidArgumentException','非法成本类型或格式第'.($i+1).'项');
    $missing=pc_row('缺键');unset($missing['cost']);$parsed=pc_parse(array($missing));
    pc_ok(!array_key_exists('cost_cents',$parsed[0]),'缺键解析不生成成本更新指令');
    foreach($badValues as $bad){$p=pc_parse(array(pc_row('账号忽略',$bad)),'account');pc_ok(!array_key_exists('cost_cents',$p[0]),'账号解析忽略成本且不尝试验证');}

    $vpn=pc_product($pdo,'多方案测试');
    $plans=pc_parse(array(pc_row('未知',''),pc_row('免费','0'),pc_row('一分','0.01'),pc_row('上限','99999999.99'),pc_row('辨识成本','87654.32')));
    pc_reject(function()use($pdo,$vpn,$plans){save_product_plans($pdo,$vpn,$plans,'2026-10-29 12:00:00');},'LogicException','保存必须已有外层事务');
    pc_ok(count(pc_rows($pdo,$vpn))===0,'无事务调用没有写入');
    pc_save($pdo,$vpn,$plans);$rows=pc_rows($pdo,$vpn);$ids=array_column($rows,'id');$expected=array(null,0,1,9999999999,8765432);
    pc_ok(count($rows)===5,'一次插入多个真实方案');
    foreach($rows as $i=>$row)pc_ok($row['name']===$plans[$i]['name']&&pc_cost($pdo,$row['id'])===$expected[$i],'成本与方案一一对应第'.($i+1).'项');
    $persistHash=pc_hash($pdo,array('products','product_plans','product_plan_costs'));
    run_migrations($pdo,$root);run_migrations($pdo,$root);
    pc_ok(pc_hash($pdo,array('products','product_plans','product_plan_costs'))===$persistHash&&pc_hash($pdo,$protected)===$moneyHash,'有成本后两次完整迁移保留成本、方案、原订单及资金');

    // 双真实连接：另一事务先锁产品行，save 必须在碰方案之前等待产品锁。
    $second=new PDO($dsn,getenv('CHAOXI_TEST_USER'),getenv('CHAOXI_TEST_PASSWORD'),array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false));
    pc_ok($second->query('SELECT DATABASE()')->fetchColumn()===$names[0],'第二连接再次核对实际隔离库');
    $second->exec('SET SESSION innodb_lock_wait_timeout=1');$pdo->beginTransaction();
    try{
        $pdo->prepare('SELECT id FROM products WHERE id=? FOR UPDATE')->execute(array($vpn));$second->beginTransaction();
        try{$e=pc_reject(function()use($second,$vpn){save_product_plans($second,$vpn,array(),'2026-10-29 12:00:00');},'PDOException','真实保存等待 products 行锁');pc_ok(isset($e->errorInfo[1])&&(int)$e->errorInfo[1]===1205,'拒绝源于真实行锁等待超时');}finally{if($second->inTransaction())$second->rollBack();}
    }finally{if($pdo->inTransaction())$pdo->rollBack();}
    pc_ok(pc_hash($pdo,array('products','product_plans','product_plan_costs'))===$persistHash,'锁等待失败不改变方案与成本');
    pc_reject(function()use($pdo,$plans){pc_save($pdo,999999999,$plans);},'InvalidArgumentException','不存在的真实产品被拒绝');

    $edit=array();foreach($rows as $i=>$row)$edit[]=pc_row($row['name'],'12.34',$row['id']);
    $edit[4]['cost']='';$edit[2]['cost']='0';unset($edit[3]['cost']);
    pc_save($pdo,$vpn,pc_parse($edit));
    pc_ok(pc_cost($pdo,$ids[4])===null&&pc_cost($pdo,$ids[2])===0&&pc_cost($pdo,$ids[3])===9999999999,'编辑空值清除、零有效、缺字段保留已有成本');
    $other=pc_product($pdo,'另一套餐');$account=pc_product($pdo,'账号套餐','account');
    $otherPlans=pc_parse(array(pc_row('外部方案','51.23')));pc_save($pdo,$other,$otherPlans);$foreign=pc_rows($pdo,$other)[0]['id'];
    $stateTables=array('products','product_plans','product_plan_costs','orders','payment_records','users','wallet_transactions');
    foreach(array(array(pc_row('合法先行','11.11',$ids[0]),pc_row('跨套餐','22.22',$foreign)),array(pc_row('重复一','11.11',$ids[0]),pc_row('重复二','22.22',$ids[0]))) as $badRows){
        $before=pc_hash($pdo,$stateTables);pc_reject(function()use($pdo,$vpn,$badRows){pc_save($pdo,$vpn,pc_parse($badRows));},'InvalidArgumentException','跨套餐或重复方案编号拒绝');pc_ok(pc_hash($pdo,$stateTables)===$before&&!$pdo->inTransaction(),'非法方案回滚全部产品、方案、成本和资金状态');
    }
    // 在已更新成本后主动制造第二条唯一键冲突，验证不是仅测预检拒绝。
    $before=pc_hash($pdo,$stateTables);$late=pc_parse(array(pc_row('正常更新','33.33',$ids[0]),pc_row('正常新增','44.44')));$late[1]['name']=$late[0]['name'];
    pc_reject(function()use($pdo,$vpn,$late){pc_save($pdo,$vpn,$late);},'PDOException','中途真实 SQL 唯一键失败');
    pc_ok(pc_hash($pdo,$stateTables)===$before&&!$pdo->inTransaction(),'已执行的方案与成本更新完整回滚');

    // 调用方伪装 VPN 解析结果也不能越过数据库内真实 account 类型。
    $manual=pc_parse(array(pc_row('账号手工成本','76543.21')));pc_save($pdo,$account,$manual);$accountPlan=pc_rows($pdo,$account)[0]['id'];
    pc_ok(pc_cost($pdo,$accountPlan)===null,'真实账号类型忽略手工 cost_cents 插入');
    $manual[0]['id']=(int)$accountPlan;$manual[0]['cost_cents']=123456;pc_save($pdo,$account,$manual);
    pc_ok(pc_cost($pdo,$accountPlan)===null,'真实账号类型忽略手工 cost_cents 编辑');

    // 管理员编辑表单真实 JOIN 与函数；公开 SELECT * 仍只从方案表取值。
    Security::$admin=true;
    $adminRows=pc_rows($pdo,$vpn);$adminInput=array();foreach($adminRows as $row)$adminInput[]=pc_row($row['name'],'87654.32',$row['id']);
    pc_dispatch($router,'POST','/admin/products/'.$vpn.'/edit',pc_edit_post($adminInput));
    pc_ok($GLOBALS['pc_flash'][0]==='success'&&pc_cost($pdo,$ids[0])===8765432,'真实编辑路由保存成本成功（模拟安全守卫）');
    pc_dispatch($router,'GET','/admin/products/'.$vpn.'/edit');$html=$GLOBALS['pc_page'];
    pc_ok(strpos($html,'name="plans[0][cost]"')!==false&&strpos($html,'value="87654.32"')!==false&&strpos($html,'预估毛利')!==false,'真实编辑 GET 联表生成含辨识成本的管理员表单');
    $public=pc_rows($pdo,$vpn);$publicJson=json_encode($public);
    pc_ok(strpos($publicJson,'cost_cents')===false&&strpos($publicJson,'8765432')===false&&strpos($publicJson,'87654.32')===false,'公开 product_plans SELECT * 无成本字段和辨识值');
    // 真实多行公开路由完整 statement；只模拟 page 外壳，内部 SQL/HTML 均为真实实现。
    // 正向断言确保渲染到有方案的套餐，避免空页造成隐私断言假通过。
    $publicBefore=pc_hash($pdo,$stateTables);$publicBodies=array();Security::$admin=false;$_GET=array();
    foreach(array('/products'=>'purchase-card','/products/'.$vpn=>'plan-choice') as $path=>$marker){
        pc_dispatch($router,'GET',$path);$body=$GLOBALS['pc_page'];
        pc_ok(is_string($body)&&strpos($body,$marker)!==false&&strpos($body,'路由编辑套餐')!==false&&strpos($body,'29.99')!==false,'真实公开路由渲染实际套餐与方案：'.$path);
        foreach(array('cost_cents','plan-cost-input','[cost]','8765432','87654.32','87,654.32') as $secret)pc_ok(strpos($body,$secret)===false,'真实公开路由无成本字段或辨识值：'.$path.'，'.$secret);
        $publicBodies[$path]=$body;
    }
    pc_ok(pc_hash($pdo,$stateTables)===$publicBefore,'真实公开路由读取不改变产品、成本、订单或资金');
    // 差分断言覆盖任意 HTML 属性、脚本及未知字段名的潜在成本泄漏。
    $pdo->beginTransaction();
    try{
        $pdo->prepare('UPDATE product_plan_costs pc JOIN product_plans pp ON pp.id=pc.product_plan_id SET pc.cost_cents=7654321 WHERE pp.product_id=?')->execute(array($vpn));
        pc_ok(pc_cost($pdo,$ids[0])===7654321,'公开路由差分测试确实更改了隔离成本');
        foreach($publicBodies as $path=>$body){pc_dispatch($router,'GET',$path);pc_ok($GLOBALS['pc_page']===$body,'仅改变成本不改变真实公开路由任何输出：'.$path);}
    }finally{if($pdo->inTransaction())$pdo->rollBack();}
    pc_ok(pc_hash($pdo,$stateTables)===$publicBefore,'公开路由成本差分测试回滚完整');
    foreach(array(array('GET','/edit'),array('POST','/edit'),array('POST','/delete')) as $route){
        $before=pc_hash($pdo,$stateTables);Security::$admin=false;
        pc_reject(function()use($router,$vpn,$route,$adminInput){pc_dispatch($router,$route[0],'/admin/products/'.$vpn.$route[1],pc_edit_post($adminInput));},'PCGuardFailure','真实路由调用模拟未授权守卫');
        pc_ok(pc_hash($pdo,$stateTables)===$before&&$GLOBALS['pc_page']===null,'未授权模拟拒绝不泄漏表单或改库');
    }
    Security::$admin=true;
    foreach(array('/edit','/delete') as $suffix)foreach(array(null,'错误令牌') as $token){
        $post=pc_edit_post($adminInput);if($token===null)unset($post['_csrf']);else $post['_csrf']=$token;$before=pc_hash($pdo,$stateTables);
        pc_reject(function()use($router,$vpn,$suffix,$post){pc_dispatch($router,'POST','/admin/products/'.$vpn.$suffix,$post);},'PCGuardFailure','真实路由和 post_csrf 调用模拟缺失或错误令牌守卫');
        pc_ok(pc_hash($pdo,$stateTables)===$before,'模拟 CSRF 拒绝发生在任何写入之前');
    }
    foreach(array(array(pc_row('先合法','1.23',$ids[0]),pc_row('外部','2.34',$foreign)),array(pc_row('重复一','1.23',$ids[0]),pc_row('重复二','2.34',$ids[0]))) as $badRows){
        $before=pc_hash($pdo,$stateTables);pc_dispatch($router,'POST','/admin/products/'.$vpn.'/edit',pc_edit_post($badRows));
        pc_ok($GLOBALS['pc_flash'][0]==='error'&&pc_hash($pdo,$stateTables)===$before&&!$pdo->inTransaction(),'真实编辑路由非法编号回滚产品基础字段及全部方案成本');
    }

    $referencedOrder=pc_order($pdo,$vpn,$ids[0],'COST_REFERENCE');$moneyHash=pc_hash($pdo,$protected);
    pc_save($pdo,$vpn,pc_parse(array(pc_row('只留此方案','65.43',$ids[1]))));
    $left=pc_rows($pdo,$vpn);$byId=array_column($left,null,'id');
    pc_ok(count($left)===2&&isset($byId[$ids[0]])&&$byId[$ids[0]]['status']==='inactive'&&pc_cost($pdo,$ids[0])===8765432,'删除有订单引用方案仅下架并保留成本');
    foreach(array($ids[2],$ids[3],$ids[4]) as $id)pc_ok(!isset($byId[$id])&&pc_cost($pdo,$id)===null,'删除无订单引用方案同时清理成本');
    $before=pc_hash($pdo,$stateTables);pc_dispatch($router,'POST','/admin/products/'.$vpn.'/delete',array('_csrf'=>Security::csrfToken()));
    pc_ok($GLOBALS['pc_flash'][0]==='error'&&pc_hash($pdo,$stateTables)===$before&&!$pdo->inTransaction(),'真实删除路由拒绝有订单套餐并完整回滚');
    pc_dispatch($router,'POST','/admin/products/'.$other.'/delete',array('_csrf'=>Security::csrfToken()));
    pc_ok($GLOBALS['pc_flash'][0]==='success'&&count(pc_rows($pdo,$other))===0&&pc_cost($pdo,$foreign)===null&&(int)$pdo->query('SELECT COUNT(*) FROM products WHERE id='.$other)->fetchColumn()===0,'真实删除路由清除无订单套餐、方案和独立成本');
    pc_ok(pc_hash($pdo,$protected)===$moneyHash,'全部编辑和删除保留原订单、引用订单与资金保护哈希');
    $costHash=pc_hash($pdo,array('product_plan_costs'));run_migrations($pdo,$root);run_migrations($pdo,$root);
    pc_ok(pc_hash($pdo,array('product_plan_costs'))===$costHash&&pc_hash($pdo,$protected)===$moneyHash,'最终两次完整迁移保留剩余成本、订单和资金');
    echo 'PASS '.($upgrade?'模拟无成本表升级':'空库新装').'回归完成；真实公开路由正文已覆盖，未覆盖真实 Security 会话/HTTP 退出、浏览器交互及 page 布局外壳。'.PHP_EOL;
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,'测试失败：'.$e->getMessage().PHP_EOL);exit(1);}
