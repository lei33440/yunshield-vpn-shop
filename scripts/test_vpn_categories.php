<?php
// CLI only. Never loads site config, creates a database, cleans a database, or calls a network worker.
// Run default/fresh and --upgrade against TWO DIFFERENT explicitly supplied empty local databases.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(count($argv)>2||(isset($argv[1])&&$argv[1]!=='--upgrade'))throw new InvalidArgumentException('仅支持无参数或 --upgrade');
$upgrade=isset($argv[1]);$dsn=getenv('CHAOXI_TEST_DSN');$dsnParts=array();
if(!is_string($dsn)||strpos($dsn,'mysql:')!==0)throw new RuntimeException('必须显式指定 CHAOXI_TEST_DSN=mysql:...');
foreach(explode(';',substr($dsn,6)) as $part){
    if($part==='')continue;
    if(!preg_match('/\A([a-z_]+)=([^;\x00-\x1f]*)\z/Di',$part,$m))throw new RuntimeException('DSN 参数格式无效');
    $key=strtolower($m[1]);if(!in_array($key,array('host','port','dbname','charset'),true)||isset($dsnParts[$key]))throw new RuntimeException('DSN 只允许唯一 host/port/dbname/charset，禁止 socket/config 回落');
    $dsnParts[$key]=$m[2];
}
if(!isset($dsnParts['dbname'])||!preg_match('/\Achaoxi_wallet_test_[A-Za-z0-9_]+\z/D',$dsnParts['dbname']))throw new RuntimeException('必须显式指定唯一 chaoxi_wallet_test_ 前缀隔离库');
$allowedHosts=array('127.0.0.1','localhost','[::1]');if(is_file('/.dockerenv'))$allowedHosts[]='db';
if(!isset($dsnParts['host'])||!in_array($dsnParts['host'],$allowedHosts,true))throw new RuntimeException('仅允许明确本地主机；db 仅容器内允许');
if(isset($dsnParts['port'])&&(!preg_match('/\A[1-9][0-9]{0,4}\z/D',$dsnParts['port'])||(int)$dsnParts['port']>65535))throw new RuntimeException('端口无效');
function vc_connect(){global $dsn,$dsnParts;$p=new PDO($dsn,getenv('CHAOXI_TEST_USER'),getenv('CHAOXI_TEST_PASSWORD'),array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false));if($p->query('SELECT DATABASE()')->fetchColumn()!==$dsnParts['dbname'])throw new RuntimeException('实际数据库名称不符');return $p;}
function vc_ok($yes,$label){if(!$yes)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
function vc_reject($fn,$class,$label){try{$fn();}catch(Throwable $e){if(!$e instanceof $class)throw $e;vc_ok(true,$label);return $e;}throw new RuntimeException('FAIL 未拒绝 '.$label);}
function vc_sql($sql,$args=array(),$connection=null){global $pdo;$s=($connection?:$pdo)->prepare($sql);$s->execute($args);return $s;}
function vc_hash($tables){$data=array();foreach($tables as $table){$rows=vc_sql('SELECT * FROM `'.$table.'`')->fetchAll();$rows=array_map('serialize',$rows);sort($rows,SORT_STRING);$data[$table]=$rows;}return hash('sha256',serialize($data));}
function vc_text($t){return is_array($t)?$t[1]:$t;}
function vc_open($t){return $t==='{'||(is_array($t)&&in_array($t[0],array(T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES),true));}
// Bootstrap ONLY the four named token extraction helpers from the cost test, not its DSN/test body.
function vc_extract_helpers($file){
    $tokens=token_get_all(file_get_contents($file));$wanted=array('pc_text','pc_open','pc_functions','pc_route_source');$found=array();$depth=0;
    for($i=0,$n=count($tokens);$i<$n;$i++){
        $t=$tokens[$i];
        if($depth===0&&is_array($t)&&$t[0]===T_FUNCTION){
            $j=$i+1;while($j<$n&&is_array($tokens[$j])&&in_array($tokens[$j][0],array(T_WHITESPACE,T_COMMENT,T_DOC_COMMENT),true))$j++;
            if(isset($tokens[$j])&&is_array($tokens[$j])&&in_array($tokens[$j][1],$wanted,true)){
                $name=$tokens[$j][1];$code='';$balance=0;$started=false;
                for(;$i<$n;$i++){$x=$tokens[$i];$code.=vc_text($x);if(vc_open($x)){$balance++;$started=true;}elseif($x==='}')$balance--;if($started&&$balance===0)break;}
                if(!$started||$balance!==0||isset($found[$name]))throw new RuntimeException('提取器边界不唯一/不完整');
                eval($code);$found[$name]=true;continue;
            }
        }
        if(vc_open($t))$depth++;elseif($t==='}')$depth--;
    }
    vc_ok(count($found)===count($wanted),'复用真实成本测试 token 提取器，不执行其引导');
}
class VCRedirect extends Error{public $url;public function __construct($url){$this->url=$url;}}
function redirect($url){throw new VCRedirect($url);}
function flash($type,$message){$GLOBALS['vc_flash']=array($type,$message);}
function page($title,$body,$config){$GLOBALS['vc_body']=$body;}
// Security is the real class. Only the outer page renderer and redirect termination are replaced.
// Invalid-CSRF exit branches are NOT invoked; real HTTP/auth/CSRF acceptance belongs to port 8081.
function vc_router($connection){global $source,$config;$pdo=$connection;$router=new Router();foreach(array(array('get','/products'),array('get','/products/{id}'),array('get','/admin/products/create'),array('post','/admin/products/create'),array('get','/admin/products/{id}/edit'),array('post','/admin/products/{id}/edit')) as $r)eval(pc_route_source($source,$r[0],$r[1]));register_vpn_category_routes($router,$pdo,$config);return $router;}
function vc_run($router,$method,$path,$get=array(),$post=array(),$connection=null){
    $previous=$GLOBALS['pdo'];if($connection!==null)$GLOBALS['pdo']=$connection;
    $_GET=$get;$_POST=$post+array('_csrf'=>'vpn-isolated-csrf');$GLOBALS['vc_flash']=null;$GLOBALS['vc_body']=null;http_response_code(200);
    try{$router->dispatch($method,$path);}catch(VCRedirect $r){return $r->url;}finally{$GLOBALS['pdo']=$previous;}
    return $GLOBALS['vc_body'];
}
function vc_post($name,$cost=''){$p=array('product_type'=>'vpn','name'=>$name,'description'=>'公开 VPN 分类测试说明','traffic'=>'10G','region'=>'HK','device_limit'=>'1','sort_order'=>'0','status'=>'active','plans'=>array(array('id'=>'0','name'=>'monthly-plan','duration_days'=>'30','price'=>'29.99','status'=>'active','sort_order'=>'0','cost'=>$cost)));return $p;}
function vc_edit_post($id){$p=vc_sql('SELECT * FROM products WHERE id=?',array($id))->fetch();$post=vc_post($p['name']);foreach(array('description','traffic','region','device_limit','sort_order','status','product_type','account_platform','account_specification','account_after_sales') as $key)$post[$key]=$p[$key]===null?'':(string)$p[$key];$post['plans']=array();foreach(vc_sql('SELECT * FROM product_plans WHERE product_id=? ORDER BY id',array($id))->fetchAll() as $plan){$row=array();foreach(array('id','name','duration_days','status','sort_order') as $key)$row[$key]=$plan[$key]===null?'':(string)$plan[$key];$row['price']=number_format($plan['price_cents']/100,2,'.','');$post['plans'][]=$row;}return $post;}
function vc_create($router,$post){$url=vc_run($router,'POST','/admin/products/create',array(),$post);vc_ok($url==='/admin/products'&&$GLOBALS['vc_flash'][0]==='success','真实创建路由成功 '.$post['name']);return (int)vc_sql('SELECT id FROM products WHERE name=?',array($post['name']))->fetchColumn();}
function vc_category($id){return vc_sql('SELECT vpn_category_id FROM products WHERE id=?',array($id))->fetchColumn();}
function vc_costs($id){return vc_sql('SELECT pp.id,pc.cost_cents FROM product_plans pp LEFT JOIN product_plan_costs pc ON pc.product_plan_id=pp.id WHERE pp.product_id=? ORDER BY pp.id',array($id))->fetchAll();}
function vc_audit($id,$action){global $aid;$r=vc_sql('SELECT * FROM admin_logs WHERE target_type="vpn_category" AND target_id=? AND action=? ORDER BY id DESC LIMIT 1',array($id,$action))->fetch();vc_ok($r&&(int)$r['admin_id']===$aid&&is_array(json_decode($r['details_json'],true)),'CRUD 审计 '.$action);return json_decode($r['details_json'],true);}
// 介绍专项共用断言：同时核对持久化值和审计实际 after，NULL 不能被缺键冒充。
function vc_description($svc,$id,$expected,$action,$label){
    $row=$svc->requireId($id);$audit=vc_audit($id,$action);
    vc_ok(array_key_exists('description',$row)&&$row['description']===$expected,$label.' 持久化介绍');
    vc_ok(isset($audit['after'])&&array_key_exists('description',$audit['after'])&&$audit['after']['description']===$expected,$label.' 审计 after 实际介绍');
}
function vc_intro($html,$name=null,$description=null){
    preg_match_all('/<section\b[^>]*class="[^"]*\bpurchase-category-intro\b[^"]*"[^>]*>(.*?)<\/section>/s',$html,$sections);
    if($name===null){vc_ok(count($sections[0])===0&&strpos($html,'purchase-category-intro')===false,'未选择具体有介绍分类时不输出介绍 section');return;}
    vc_ok(count($sections[0])===1,'选中分类只输出一个介绍 section');$section=$sections[0][0];
    vc_ok(preg_match('/<h2\b[^>]*>'.preg_quote(e($name),'/').'<\/h2>/s',$section)===1,'介绍 h2 使用当前分类名称并转义');
    vc_ok(preg_match('/<div\b[^>]*class="[^"]*\bpurchase-category-description\b[^"]*"[^>]*>'.preg_quote(e($description),'/').'<\/div>/s',$section)===1,'介绍 div 完整保留换行并转义');
    $nav=strpos($html,'</nav>',strpos($html,'purchase-category-nav'));$intro=strpos($html,$section);$toolbar=strpos($html,'purchase-toolbar');
    vc_ok($nav!==false&&$toolbar!==false&&$nav<$intro&&$intro<$toolbar,'介绍位于分类导航之后 toolbar 之前');
}
function vc_description_form($html,$expected){
    preg_match_all('/<textarea\b(?=[^>]*\bname="description")[^>]*>(.*?)<\/textarea>/s',$html,$fields);
    vc_ok(count($fields[0])===1&&preg_match('/\bmaxlength="2000"/',$fields[0][0])===1,'后台介绍单一 textarea 且 maxlength=2000');
    vc_ok($fields[1][0]===e($expected),'后台 GET 介绍 textarea 精确转义回显');
}
function vc_timeout($e,$label){vc_ok($e instanceof PDOException&&isset($e->errorInfo[1])&&(int)$e->errorInfo[1]===1205,$label);}
function vc_index_rows(){return vc_sql('SHOW INDEX FROM products')->fetchAll();}
function vc_category_indexes(){return array_values(array_filter(vc_index_rows(),function($r){return $r['Column_name']==='vpn_category_id';}));}
function vc_fixture_product($name,$category,$status='active',$days=30,$price=2999){
    vc_sql('INSERT INTO products(name,description,product_type,vpn_category_id,traffic,region,device_limit,duration_days,price_cents,status,created_at,updated_at) VALUES(?,"隔离说明","vpn",?,"10G","HK",1,?,?,?,NOW(),NOW())',array($name,$category,$days,$price,$status));global $pdo;$id=(int)$pdo->lastInsertId();
    vc_sql('INSERT INTO product_plans(product_id,name,duration_days,price_cents,status,created_at,updated_at) VALUES(?,?,?,?,"active",NOW(),NOW())',array($id,'PLAN_'.$name,$days,$price));return $id;
}
$pdo=null;$second=null;
try{
    $pdo=vc_connect();vc_ok((int)$pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn()===0,'已确认实际本地隔离库为空');
    date_default_timezone_set('UTC');set_error_handler(function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
    $root=dirname(__DIR__);spl_autoload_register(function($class)use($root){foreach(array('/app/Services/','/app/Core/') as $dir)if(is_file($f=$root.$dir.$class.'.php')){require_once $f;return;}});
    require $root.'/scripts/migrate.php';require $root.'/app/Core/Security.php';
    vc_extract_helpers($root.'/scripts/test_product_plan_costs.php');
    pc_functions($root.'/public/index.php',array('product_form','product_plans_input','save_product_plans','product_input','post_csrf','money','plan_description_html','user_nav_icon','shop_plan_period','shop_plan_key','shop_plan_daily','shop_traffic_gb','shop_tagline'));
    require $root.'/app/Core/AccountProductPages.php';require $root.'/app/Core/AdminVpnCategoryPages.php';
    pc_functions($root.'/app/Core/Bootstrap.php',array('e'));pc_functions($root.'/app/Core/AdminExtraPages.php',array('admin_heading'));
    $source=file_get_contents($root.'/public/index.php');$config=array('app'=>array('name'=>'isolated','timezone'=>'UTC'));$router=vc_router($pdo);
    if($upgrade){
        // Execute schema DDL, then remove ONLY the empty VPN addition: a real pre-feature schema.
        foreach(array_filter(array_map('trim',preg_split('/;\s*(?:\r?\n|$)/',file_get_contents($root.'/database/schema.sql')))) as $sql)$pdo->exec($sql);
        vc_ok(count(vc_category_indexes())>0,'升级基线确实包含待移除的分类索引');
        $pdo->exec('ALTER TABLE products DROP COLUMN vpn_category_id');$pdo->exec('DROP TABLE vpn_categories');
        vc_ok((int)vc_sql('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="products" AND COLUMN_NAME="vpn_category_id"')->fetchColumn()===0,'旧结构没有分类列');
    }else{run_migrations($pdo,$root);vc_ok(true,'空库完整新装迁移');}
    vc_sql('INSERT INTO admin_users(email,password_hash,created_at,updated_at) VALUES("vpn-admin@example.test","isolated",NOW(),NOW())');$aid=(int)$pdo->lastInsertId();$_SESSION=array('admin_id'=>$aid,'_csrf'=>'vpn-isolated-csrf');
    vc_sql('INSERT INTO users(email,password_hash,referral_code,balance_cents,created_at,updated_at) VALUES("vpn-user@example.test","isolated","VPNFIXTURE",7001,NOW(),NOW())');$uid=(int)$pdo->lastInsertId();
    vc_sql('INSERT INTO products(name,description,product_type,traffic,region,device_limit,duration_days,price_cents,created_at,updated_at) VALUES("LEGACY_VPN","legacy","vpn","10G","HK",1,30,2999,NOW(),NOW())');$legacy=(int)$pdo->lastInsertId();
    vc_sql('INSERT INTO product_plans(product_id,name,duration_days,price_cents,created_at,updated_at) VALUES(?,"legacy-plan",30,2999,NOW(),NOW())',array($legacy));$legacyPlan=(int)$pdo->lastInsertId();
    vc_sql('INSERT INTO product_plan_costs(product_plan_id,cost_cents,updated_at) VALUES(?,4567891,NOW())',array($legacyPlan));
    vc_sql('INSERT INTO orders(order_no,user_id,product_id,product_plan_id,product_name_snapshot,plan_name_snapshot,product_description_snapshot,traffic_snapshot,region_snapshot,device_limit_snapshot,duration_days_snapshot,unit_price_cents_snapshot,original_amount_cents,total_amount_cents,created_at,updated_at) VALUES("VPN_ORIGINAL",?,?,?,"legacy","legacy-plan","legacy","10G","HK",1,30,2999,2999,2999,NOW(),NOW())',array($uid,$legacy,$legacyPlan));$oid=(int)$pdo->lastInsertId();
    vc_sql('INSERT INTO payment_records(order_id,method,amount_cents,status,created_at,updated_at) VALUES(?,"balance",2999,"confirmed",NOW(),NOW())',array($oid));
    vc_sql('INSERT INTO wallet_transactions(user_id,amount_cents,balance_before_cents,balance_after_cents,type,order_id,idempotency_key,created_at) VALUES(?,-2999,10000,7001,"order_payment",?,"vpn-original",NOW())',array($uid,$oid));
    $protected=array('users','orders','payment_records','wallet_transactions');$moneyHash=vc_hash($protected);$planHash=vc_hash(array('product_plans','product_plan_costs'));$legacyBefore=vc_sql('SELECT * FROM products WHERE id=?',array($legacy))->fetch();unset($legacyBefore['vpn_category_id']);
    run_migrations($pdo,$root);run_migrations($pdo,$root);
    $legacyAfter=vc_sql('SELECT * FROM products WHERE id=?',array($legacy))->fetch();vc_ok(array_key_exists('vpn_category_id',$legacyAfter)&&$legacyAfter['vpn_category_id']===null,'旧套餐分类为 NULL');unset($legacyAfter['vpn_category_id']);
    vc_ok($legacyBefore===$legacyAfter&&vc_hash($protected)===$moneyHash&&vc_hash(array('product_plans','product_plan_costs'))===$planHash,'两次完整迁移保留旧套餐售价、成本、订单与资金哈希');
    // 新装及完整旧结构升级均必须得到可空 TEXT 介绍列，后续原保护哈希保持不变。
    $descriptionColumn=vc_sql('SELECT DATA_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="vpn_categories" AND COLUMN_NAME="description"')->fetch();
    vc_ok($descriptionColumn&&strtolower($descriptionColumn['DATA_TYPE'])==='text'&&$descriptionColumn['IS_NULLABLE']==='YES','新装/旧结构升级包含 description TEXT NULL');
    // Independently recover a missing index, column and table before any category bindings exist.
    $indexes=vc_category_indexes();vc_ok(count($indexes)>0,'products 分类索引存在');$indexNames=array();foreach($indexes as $index)$indexNames[$index['Key_name']]=true;
    foreach(array_keys($indexNames) as $index)$pdo->exec('ALTER TABLE products DROP INDEX `'.str_replace('`','``',$index).'`');run_migrations($pdo,$root);vc_ok(count(vc_category_indexes())>0,'缺分类索引可恢复');
    $pdo->exec('ALTER TABLE products DROP COLUMN vpn_category_id');run_migrations($pdo,$root);vc_ok(vc_category($legacy)===null&&count(vc_category_indexes())>0,'缺列可恢复为 NULL 且重建索引');
    $pdo->exec('DROP TABLE vpn_categories');run_migrations($pdo,$root);vc_ok(is_array((new VpnCategoryService($pdo))->all()),'缺分类表可恢复');
    $svc=new VpnCategoryService($pdo);$state=array('vpn_categories','products','product_plans','product_plan_costs','admin_logs');$state=array_merge($state,$protected);
    foreach(array(array('',null),array('1',1),array((string)PHP_INT_MAX,PHP_INT_MAX)) as $case)vc_ok(VpnCategoryService::parseId($case[0])===$case[1],'严格 parseId 合法边界 '.var_export($case[0],true));
    $badIds=array(array(),null,1,0,true,false,1.0,'0','01',' 1','1 ','+1','-1','1e0','1.0',"1\n",'١',((string)PHP_INT_MAX).'0');
    foreach($badIds as $i=>$bad)vc_reject(function()use($bad){VpnCategoryService::parseId($bad);},'InvalidArgumentException','parseId 拒绝类型/格式 #'.$i);
    vc_reject(function()use($svc){$svc->requireId('999999999');},'InvalidArgumentException','requireId 不存在拒绝');
    foreach(array(null,array(),true,'',0,'01') as $bad)vc_reject(function()use($svc,$bad){$svc->requireId($bad);},'InvalidArgumentException','requireId 非规范编号拒绝');
    $a=(int)$svc->save(0,array('name'=>'  A_MONTHLY  ','sort_order'=>'10'),$aid);$b=(int)$svc->save(0,array('name'=>'B_YEARLY','sort_order'=>'10'),$aid);$evil='<img src=x onerror=alert(7)>';$x=(int)$svc->save(0,array('name'=>$evil,'sort_order'=>'0'),$aid);
    vc_ok($svc->requireId($a)['name']==='A_MONTHLY'&&(int)$svc->requireId((string)$a)['id']===$a,'requireId 接受正 int/string 且名称 trim');vc_audit($a,'create_vpn_category');
    vc_description($svc,$a,null,'create_vpn_category','缺介绍键新建');
    // 单独模拟已使用分类、仅缺介绍列的旧表；不删除完整分类结构，也不豁免任何保护表。
    $upgradeBound=vc_fixture_product('DESCRIPTION_UPGRADE_BOUND',$a,'inactive');
    $oldCategories=$svc->all();foreach($oldCategories as $row)vc_ok(array_key_exists('description',$row)&&$row['description']===null,'缺键新建分类介绍为 NULL');
    // 分类逐字段比较（列物理顺序可因 ADD COLUMN 改变）；其他表仍沿用原始全列哈希。
    foreach($oldCategories as &$row)ksort($row);unset($row);
    $descriptionUpgradeTables=array_values(array_diff($state,array('vpn_categories')));
    $descriptionUpgradeHash=vc_hash($descriptionUpgradeTables);$descriptionUpgradePlanHash=vc_hash(array('product_plans','product_plan_costs'));$pdo->exec('ALTER TABLE vpn_categories DROP COLUMN description');
    vc_ok((int)vc_sql('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="vpn_categories" AND COLUMN_NAME="description"')->fetchColumn()===0,'仅缺介绍列的真实旧分类表基线');
    for($pass=1;$pass<=2;$pass++){
        run_migrations($pdo,$root);
        $restoredCategories=$svc->all();foreach($restoredCategories as &$row)ksort($row);unset($row);
        vc_ok($restoredCategories===$oldCategories&&(int)vc_category($upgradeBound)===$a,'仅缺介绍列升级第 '.$pass.' 次逐字段保留旧分类名称排序介绍及套餐归属');
        vc_ok(vc_hash($descriptionUpgradeTables)===$descriptionUpgradeHash&&vc_hash($protected)===$moneyHash&&vc_hash(array('product_plans','product_plan_costs'))===$descriptionUpgradePlanHash,'仅缺介绍列升级第 '.$pass.' 次全表哈希与原资金售价成本保护');
    }
    // 升级归属已验证，解除本用例的临时引用，避免干扰后续独立的删除审计故障测试。
    vc_sql('UPDATE products SET vpn_category_id=NULL WHERE id=?',array($upgradeBound));
    // UTF-8 字符长度按 trim 后计数；所有数据仅为隔离库测试哨兵。
    $descriptionMulti="专项介绍甲第一行\n中文第二行";$descriptionHtml="专项介绍乙\n</textarea><script>alert(19)</script><img src=x onerror=alert(20)> & \" '";
    $descriptionId=(int)$svc->save(0,array('name'=>'DESCRIPTION_SERVICE','sort_order'=>'0','description'=>" \t".$descriptionMulti."\r\n "),$aid);
    vc_description($svc,$descriptionId,$descriptionMulti,'create_vpn_category','多行中文 trim');
    $svc->save($descriptionId,array('name'=>'DESCRIPTION_SERVICE','sort_order'=>'1'),$aid);
    vc_description($svc,$descriptionId,$descriptionMulti,'update_vpn_category','编辑缺键保留');
    $description2000=str_repeat('中',2000);$svc->save($descriptionId,array('name'=>'DESCRIPTION_SERVICE','sort_order'=>'1','description'=>" \n".$description2000."\t "),$aid);
    vc_description($svc,$descriptionId,$description2000,'update_vpn_category','trim 后2000中文字符合法');
    foreach(array(str_repeat('中',2001),array('伪造'),null,"非法\xff",true,2000) as $i=>$badDescription){foreach(array(0,$descriptionId) as $target){
        $before=vc_hash($state);vc_reject(function()use($svc,$target,$badDescription,$aid){$svc->save($target,array('name'=>'DESCRIPTION_INVALID','sort_order'=>'0','description'=>$badDescription),$aid);},'InvalidArgumentException','介绍非法类型/UTF8/2001字符 #'.$i.' target='.$target);
        vc_ok(vc_hash($state)===$before&&!$pdo->inTransaction(),'非法介绍拒绝无业务或审计写入');
    }}
    foreach(array(''," \t\r\n ") as $blank){
        $svc->save($descriptionId,array('name'=>'DESCRIPTION_SERVICE','sort_order'=>'1','description'=>$descriptionMulti),$aid);
        $svc->save($descriptionId,array('name'=>'DESCRIPTION_SERVICE','sort_order'=>'1','description'=>$blank),$aid);
        vc_description($svc,$descriptionId,null,'update_vpn_category','显式空介绍清除');
        $blankId=(int)$svc->save(0,array('name'=>'DESCRIPTION_BLANK','sort_order'=>'0','description'=>$blank),$aid);
        vc_description($svc,$blankId,null,'create_vpn_category','新建空介绍');$svc->delete($blankId,$aid);
    }
    $svc->delete($descriptionId,$aid);
    // 保留后续既有名称/排序断言，只给两个分类及 XSS 分类增加介绍。
    $svc->save($a,array('name'=>'A_MONTHLY','sort_order'=>'10','description'=>$descriptionMulti),$aid);
    $svc->save($b,array('name'=>'B_YEARLY','sort_order'=>'10','description'=>'专项介绍丙独立分组'),$aid);
    $svc->save($x,array('name'=>$evil,'sort_order'=>'0','description'=>$descriptionHtml),$aid);
    vc_description($svc,$a,$descriptionMulti,'update_vpn_category','分类甲介绍');
    vc_reject(function()use($svc,$a){$svc->requireId($a,true);},'LogicException','共享分类锁必须在事务内');
    $pdo->beginTransaction();try{vc_ok((int)$svc->requireId($a,true)['id']===$a,'事务内真实共享锁读取');}finally{$pdo->rollBack();}
    $sorted=$svc->all();$copy=$sorted;usort($copy,function($l,$r){return ((int)$l['sort_order']<=>(int)$r['sort_order'])?:((int)$l['id']<=>(int)$r['id']);});vc_ok($sorted===$copy,'all 按 sort_order,id 稳定排序');
    $before=vc_hash($state);vc_reject(function()use($svc,$aid){$svc->save(0,array('name'=>'A_MONTHLY','sort_order'=>'0','description'=>'重名创建不得写入介绍'),$aid);},'InvalidArgumentException','同名分类拒绝');vc_ok(vc_hash($state)===$before&&!$pdo->inTransaction(),'重名拒绝无部分数据或审计');
    $badInputs=array(null,array(),array('name'=>'','sort_order'=>'0'),array('name'=>'  ','sort_order'=>'0'),array('name'=>str_repeat('中',101),'sort_order'=>'0'),array('name'=>"\xff",'sort_order'=>'0'));
    foreach(array(null,array(),true,0) as $bad){$badInputs[]=array('name'=>$bad,'sort_order'=>'0');$badInputs[]=array('name'=>'valid','sort_order'=>$bad);}
    foreach(array('-1','01','+1','1e2','1.0',' 1','1000000000',"1\n") as $bad)$badInputs[]=array('name'=>'valid','sort_order'=>$bad);
    foreach($badInputs as $i=>$input){$before=vc_hash($state);vc_reject(function()use($svc,$input,$aid){$svc->save(0,$input,$aid);},'InvalidArgumentException','分类表单边界 #'.$i);vc_ok(vc_hash($state)===$before&&!$pdo->inTransaction(),'非法分类无写入 #'.$i);}
    foreach(array(array(),null,true,-1,'01','') as $bad){$before=vc_hash($state);vc_reject(function()use($svc,$aid,$bad){$svc->save($bad,array('name'=>'bad-id','sort_order'=>'0'),$aid);},'InvalidArgumentException','save 非法编号拒绝');vc_reject(function()use($svc,$aid,$bad){$svc->delete($bad,$aid);},'InvalidArgumentException','delete 非法编号拒绝');vc_ok(vc_hash($state)===$before&&!$pdo->inTransaction(),'非法 CRUD 编号无副作用');}
    foreach(array('save','delete') as $op){$before=vc_hash($state);vc_reject(function()use($svc,$aid,$op){if($op==='save')$svc->save(999999999,array('name'=>'missing-id','sort_order'=>'0'),$aid);else $svc->delete(999999999,$aid);},'InvalidArgumentException','CRUD 不存在编号 '.$op);vc_ok(vc_hash($state)===$before&&!$pdo->inTransaction(),'不存在编号事务回滚 '.$op);}
    $long=(int)$svc->save(0,array('name'=>str_repeat('中',100),'sort_order'=>'999999999'),$aid);vc_ok((int)$svc->requireId($long)['sort_order']===999999999,'100字及排序上界可保存');$svc->delete($long,$aid);vc_audit($long,'delete_vpn_category');
    $svc->save($a,array('name'=>'A_MONTHLY_RENAMED','sort_order'=>'1'),$aid);$audit=vc_audit($a,'update_vpn_category');vc_ok($audit['before']['name']==='A_MONTHLY'&&$audit['after']['name']==='A_MONTHLY_RENAMED','改名排序审计含前后值');
    vc_description($svc,$a,$descriptionMulti,'update_vpn_category','既有改名排序缺介绍键仍保留');
    $before=vc_hash($state);vc_reject(function()use($svc,$aid,$b){$svc->save($b,array('name'=>'A_MONTHLY_RENAMED','sort_order'=>'0','description'=>'重名编辑不得覆盖原介绍'),$aid);},'InvalidArgumentException','编辑重名拒绝');vc_ok(vc_hash($state)===$before,'编辑重名完整回滚');
    // Force audit failure AFTER the mutation to prove audit and CRUD share one transaction.
    $pdo->exec("CREATE TRIGGER vc_fail_audit BEFORE INSERT ON admin_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='isolated audit failure'");
    try{foreach(array('create','update','delete') as $operation){$before=vc_hash($state);vc_reject(function()use($svc,$aid,$a,$operation){if($operation==='delete')$svc->delete($a,$aid);else $svc->save($operation==='create'?0:$a,array('name'=>'audit-failure','sort_order'=>'0','description'=>'审计故障必须连介绍一起回滚'),$aid);},'PDOException','审计故障 '.$operation);vc_ok(vc_hash($state)===$before&&!$pdo->inTransaction(),'审计故障回滚业务和日志 '.$operation);}}finally{$pdo->exec('DROP TRIGGER vc_fail_audit');}
    $gone=(int)$svc->save(0,array('name'=>'DELETED_NO_RESEED','sort_order'=>'0'),$aid);$svc->delete($gone,$aid);$before=vc_hash($state);run_migrations($pdo,$root);run_migrations($pdo,$root);vc_ok(vc_hash($state)===$before&&(int)vc_sql('SELECT COUNT(*) FROM vpn_categories WHERE name="DELETED_NO_RESEED"')->fetchColumn()===0,'删除分类不被两次全迁移 seed 复活');
    foreach(array('draft','inactive','active') as $status){$cat=(int)$svc->save(0,array('name'=>'REFERENCED_'.$status,'sort_order'=>'0'),$aid);$pid=vc_fixture_product('REF_'.$status,$cat,$status);$before=vc_hash($state);vc_reject(function()use($svc,$cat,$aid){$svc->delete($cat,$aid);},'InvalidArgumentException','有 '.$status.' VPN 引用禁止删除');vc_ok(vc_hash($state)===$before&&!$pdo->inTransaction(),'引用拒删无部分删除或审计 '.$status);vc_sql('UPDATE products SET vpn_category_id=NULL,status="inactive" WHERE id=?',array($pid));$svc->delete($cat,$aid);}
    // Real administrative registration, rendering, CRUD and real requireAdmin redirects.
    vc_run($router,'GET','/admin/vpn-categories');vc_ok(strpos($GLOBALS['vc_body'],e($evil))!==false&&strpos($GLOBALS['vc_body'],$evil)===false,'后台分类列表名称转义');
    vc_run($router,'GET','/admin/vpn-categories/'.$x.'/edit');vc_ok(strpos($GLOBALS['vc_body'],e($evil))!==false&&strpos($GLOBALS['vc_body'],$evil)===false,'后台分类编辑表单转义');
    vc_run($router,'GET','/admin/vpn-categories/new');vc_ok(strpos($GLOBALS['vc_body'],'vpn-category-form')!==false,'真实分类新建 GET');
    // 后台真实 GET 验证空表单、多行介绍及 textarea 闭合攻击不会逃逸。
    vc_description_form($GLOBALS['vc_body'],'');
    vc_run($router,'GET','/admin/vpn-categories/'.$a.'/edit');vc_description_form($GLOBALS['vc_body'],$descriptionMulti);
    vc_run($router,'GET','/admin/vpn-categories/'.$x.'/edit');vc_description_form($GLOBALS['vc_body'],$descriptionHtml);
    vc_ok(strpos($GLOBALS['vc_body'],$descriptionHtml)===false&&substr_count($GLOBALS['vc_body'],'</textarea>')===1&&strpos($GLOBALS['vc_body'],'<script>alert(19)</script>')===false,'后台 textarea 闭合与 HTML 攻击完全转义');
    vc_run($router,'POST','/admin/vpn-categories/save',array(),array('name'=>'ADMIN_ROUTE_CATEGORY','sort_order'=>'7','description'=>$descriptionMulti));vc_ok($GLOBALS['vc_flash'][0]==='success','真实分类新增 POST');$adminCat=(int)vc_sql('SELECT id FROM vpn_categories WHERE name="ADMIN_ROUTE_CATEGORY"')->fetchColumn();vc_audit($adminCat,'create_vpn_category');
    vc_run($router,'POST','/admin/vpn-categories/'.$adminCat.'/save',array(),array('name'=>'ADMIN_ROUTE_RENAMED','sort_order'=>'2'));vc_ok($GLOBALS['vc_flash'][0]==='success'&&$svc->requireId($adminCat)['name']==='ADMIN_ROUTE_RENAMED'&&(int)$svc->requireId($adminCat)['sort_order']===2,'真实后台改名排序 POST');vc_audit($adminCat,'update_vpn_category');
    vc_description($svc,$adminCat,$descriptionMulti,'update_vpn_category','后台 POST 缺介绍键保留');
    vc_run($router,'POST','/admin/vpn-categories/'.$adminCat.'/save',array(),array('name'=>'ADMIN_ROUTE_RENAMED','sort_order'=>'2','description'=>''));
    vc_ok($GLOBALS['vc_flash'][0]==='success','后台 POST 显式清空介绍成功');vc_description($svc,$adminCat,null,'update_vpn_category','后台清空介绍');
    $before=vc_hash($state);vc_run($router,'POST','/admin/vpn-categories/'.$adminCat.'/save',array(),array('name'=>'A_MONTHLY_RENAMED','sort_order'=>'0'));vc_ok($GLOBALS['vc_flash'][0]==='error'&&vc_hash($state)===$before,'真实后台重名 POST 无副作用');
    vc_run($router,'POST','/admin/vpn-categories/'.$adminCat.'/delete');vc_ok($GLOBALS['vc_flash'][0]==='success'&&(int)vc_sql('SELECT COUNT(*) FROM vpn_categories WHERE id=?',array($adminCat))->fetchColumn()===0,'真实后台删除 POST');vc_audit($adminCat,'delete_vpn_category');
    vc_run($router,'GET','/admin/vpn-categories/999999999/edit');vc_ok(http_response_code()===404,'后台未知分类 GET 404');
    unset($_SESSION['admin_id']);try{foreach(array(array('GET','/admin/vpn-categories'),array('GET','/admin/vpn-categories/new'),array('GET','/admin/vpn-categories/'.$a.'/edit'),array('POST','/admin/vpn-categories/save'),array('POST','/admin/vpn-categories/'.$a.'/save'),array('POST','/admin/vpn-categories/'.$a.'/delete'),array('GET','/admin/products/create'),array('POST','/admin/products/create')) as $r){$before=vc_hash($state);$url=vc_run($router,$r[0],$r[1]);vc_ok($url==='/admin/login'&&$GLOBALS['vc_body']===null&&vc_hash($state)===$before,'真实 Security 未登录拒绝 '.$r[0].' '.$r[1]);}}finally{$_SESSION['admin_id']=$aid;}
    $_POST=vc_post('parse-missing');vc_ok(!array_key_exists('vpn_category_id',product_input()),'VPN 输入缺分类键不生成更新指令');$_POST['vpn_category_id']='';vc_ok(array_key_exists('vpn_category_id',product_input())&&product_input()['vpn_category_id']===null,'VPN 空分类明确解析 NULL');
    $_POST['vpn_category_id']=(string)$a;vc_ok(product_input()['vpn_category_id']===$a,'VPN 单分类解析为整数');foreach($badIds as $bad){$_POST['vpn_category_id']=$bad;vc_reject(function(){product_input();},'InvalidArgumentException','VPN 表单非法分类类型拒绝');}
    $missing=vc_create($router,vc_post('CREATE_MISSING'));$emptyPost=vc_post('CREATE_EMPTY','0');$emptyPost['vpn_category_id']='';$empty=vc_create($router,$emptyPost);vc_ok(vc_category($missing)===null&&vc_category($empty)===null,'真实创建缺键/空分类均 NULL');
    $post=vc_post('CREATE_BOUND','87654.32');$post['vpn_category_id']=(string)$a;$zeroPlan=$post['plans'][0];$zeroPlan['name']='zero-cost';$zeroPlan['cost']='0';$unknownPlan=$post['plans'][0];$unknownPlan['name']='unknown-cost';$unknownPlan['cost']='';$post['plans'][]=$zeroPlan;$post['plans'][]=$unknownPlan;
    $bound=vc_create($router,$post);vc_ok((int)vc_category($bound)===$a,'真实创建绑定单分类');$boundCosts=vc_costs($bound);vc_ok(count($boundCosts)===3&&(int)$boundCosts[0]['cost_cents']===8765432&&$boundCosts[1]['cost_cents']!==null&&(int)$boundCosts[1]['cost_cents']===0&&$boundCosts[2]['cost_cents']===null,'同套餐辨识成本/零成本/未知成本逐方案绑定');
    $beforeCosts=vc_costs($bound);$edit=vc_edit_post($bound);$edit['name']='EDIT_MISSING_PRESERVED';vc_run($router,'POST','/admin/products/'.$bound.'/edit',array(),$edit);vc_ok($GLOBALS['vc_flash'][0]==='success'&&(int)vc_category($bound)===$a&&vc_costs($bound)===$beforeCosts,'真实编辑缺键保留分类与未知成本更新指令');
    $edit['vpn_category_id']='';vc_run($router,'POST','/admin/products/'.$bound.'/edit',array(),$edit);vc_ok($GLOBALS['vc_flash'][0]==='success'&&vc_category($bound)===null&&vc_costs($bound)===$beforeCosts,'真实编辑空分类清除且不串成本');
    $edit['vpn_category_id']=(string)$b;vc_run($router,'POST','/admin/products/'.$bound.'/edit',array(),$edit);vc_ok($GLOBALS['vc_flash'][0]==='success'&&(int)vc_category($bound)===$b,'真实编辑改绑另一分类');
    foreach(array('create','edit') as $mode){$badPost=$mode==='create'?vc_post('UNKNOWN_CREATE'):vc_edit_post($bound);$badPost['name']='MUST_ROLL_BACK_'.$mode;$badPost['vpn_category_id']='999999999';$badPost['plans'][0]['cost']='0';$before=vc_hash($state);vc_run($router,'POST',$mode==='create'?'/admin/products/create':'/admin/products/'.$bound.'/edit',array(),$badPost);vc_ok($GLOBALS['vc_flash'][0]==='error'&&vc_hash($state)===$before&&!$pdo->inTransaction(),'真实 '.$mode.' 不存在分类回滚套餐、方案、成本');}
    $late=vc_edit_post($bound);$late['name']='LATE_FAILURE_MUST_ROLL_BACK';$late['vpn_category_id']=(string)$a;$late['plans'][0]['id']=(string)vc_sql('SELECT id FROM product_plans WHERE product_id=? LIMIT 1',array($empty))->fetchColumn();$late['plans'][0]['cost']='0';$before=vc_hash($state);vc_run($router,'POST','/admin/products/'.$bound.'/edit',array(),$late);vc_ok($GLOBALS['vc_flash'][0]==='error'&&vc_hash($state)===$before&&!$pdo->inTransaction()&&(int)vc_category($bound)===$b,'方案跨套餐失败回滚已执行的分类和产品更新');
    vc_ok(vc_costs($empty)[0]['cost_cents']!==null&&(int)vc_costs($empty)[0]['cost_cents']===0&&vc_costs($missing)[0]['cost_cents']===null,'零成本/未知成本保持独立语义');
    $accountPost=vc_post('ACCOUNT_IGNORES_CATEGORY','76543.21');$accountPost['product_type']='account';$accountPost['account_platform']='telegram';$accountPost['account_specification']='公开规格';$accountPost['account_after_sales']='公开售后';$accountPost['vpn_category_id']=array('forged');
    $_POST=$accountPost;vc_ok(!array_key_exists('vpn_category_id',product_input()),'账号解析完全忽略伪造分类数组');$account=vc_create($router,$accountPost);vc_ok(vc_category($account)===null&&vc_costs($account)[0]['cost_cents']===null,'真实账号创建忽略伪造分类及成本');
    $accountEdit=vc_edit_post($account);$accountEdit['vpn_category_id']='999999999';$accountEdit['plans'][0]['cost']='invalid';vc_run($router,'POST','/admin/products/'.$account.'/edit',array(),$accountEdit);vc_ok($GLOBALS['vc_flash'][0]==='success'&&vc_category($account)===null&&vc_costs($account)[0]['cost_cents']===null,'真实账号编辑忽略伪造分类与成本');
    vc_run($router,'GET','/admin/products/'.$bound.'/edit');$form=$GLOBALS['vc_body'];vc_ok(substr_count($form,'name="vpn_category_id"')===1&&strpos($form,'multiple')===false&&strpos($form,e($evil))!==false&&strpos($form,$evil)===false,'真实 VPN 编辑表单单选且分类 XSS 转义');vc_run($router,'GET','/admin/products/'.$account.'/edit');vc_ok(strpos($GLOBALS['vc_body'],'name="vpn_category_id"')===false,'真实账号表单无 VPN 分类');
    // Two actual MySQL connections, no threads/processes, one-second bounded InnoDB waits.
    $second=vc_connect();$second->exec('SET SESSION innodb_lock_wait_timeout=1');$pdo->exec('SET SESSION innodb_lock_wait_timeout=1');$svc2=new VpnCategoryService($second);$router2=vc_router($second);
    // 编辑缺介绍键也必须等待分类独占锁；超时不产生审计，解锁后保留最新已提交介绍。
    $before=vc_hash($state);$pdo->beginTransaction();
    try{vc_sql('SELECT id FROM vpn_categories WHERE id=? FOR UPDATE',array($a));$e=vc_reject(function()use($svc2,$a,$aid){$svc2->save($a,array('name'=>'A_MONTHLY_RENAMED','sort_order'=>'1'),$aid);},'PDOException','缺介绍键编辑等待 FOR UPDATE');vc_timeout($e,'缺介绍键编辑真实锁等待 1205');vc_ok(!$second->inTransaction(),'缺介绍键编辑锁超时自动回滚');}finally{$pdo->rollBack();}
    vc_ok(vc_hash($state)===$before,'缺介绍键锁超时完整保留介绍及所有状态');
    $svc->save($a,array('name'=>'A_MONTHLY_RENAMED','sort_order'=>'1','description'=>'专项最新已提交介绍'),$aid);
    $svc2->save($a,array('name'=>'A_MONTHLY_RENAMED','sort_order'=>'1'),$aid);vc_description($svc,$a,'专项最新已提交介绍','update_vpn_category','双连接缺键编辑保留最新已提交介绍');
    $svc->save($a,array('name'=>'A_MONTHLY_RENAMED','sort_order'=>'1','description'=>$descriptionMulti),$aid);
    $lockCat=(int)$svc->save(0,array('name'=>'LOCK_CATEGORY','sort_order'=>'0'),$aid);$before=vc_hash($state);$pdo->beginTransaction();
    try{$svc->requireId($lockCat,true);$e=vc_reject(function()use($svc2,$lockCat,$aid){$svc2->delete($lockCat,$aid);},'PDOException','绑定共享锁与删除独占锁互斥');vc_timeout($e,'删除等待真实分类共享锁 1205');vc_ok(!$second->inTransaction(),'删除锁等待失败自动回滚');}finally{$pdo->rollBack();}
    vc_ok(vc_hash($state)===$before,'共享锁冲突与双连接回滚保持原哈希');
    $pdo->beginTransaction();try{vc_sql('SELECT id FROM vpn_categories WHERE id=? FOR UPDATE',array($lockCat));$lockedPost=vc_post('LOCK_CREATE');$lockedPost['vpn_category_id']=(string)$lockCat;vc_run($router2,'POST','/admin/products/create',array(),$lockedPost,$second);vc_ok($GLOBALS['vc_flash'][0]==='error'&&strpos($GLOBALS['vc_flash'][1],'1205')!==false&&!$second->inTransaction(),'真实创建等待删除侧分类锁并回滚');}finally{$pdo->rollBack();}
    vc_ok(vc_hash($state)===$before,'创建锁等待失败不产生套餐/方案/成本/日志');
    $lockedPost=vc_post('LOCK_EDIT');$lockProduct=vc_create($router,$lockedPost);$lockEdit=vc_edit_post($lockProduct);$lockEdit['vpn_category_id']=(string)$lockCat;$before=vc_hash($state);
    $pdo->beginTransaction();try{vc_sql('SELECT id FROM vpn_categories WHERE id=? FOR UPDATE',array($lockCat));vc_run($router2,'POST','/admin/products/'.$lockProduct.'/edit',array(),$lockEdit,$second);vc_ok($GLOBALS['vc_flash'][0]==='error'&&strpos($GLOBALS['vc_flash'][1],'1205')!==false&&!$second->inTransaction(),'真实编辑等待目标分类锁并回滚');}finally{$pdo->rollBack();}vc_ok(vc_hash($state)===$before,'编辑锁失败无部分字段更新');
    $pdo->beginTransaction();try{$svc->requireId($lockCat,true);vc_sql('UPDATE products SET vpn_category_id=? WHERE id=?',array($lockCat,$lockProduct));$e=vc_reject(function()use($svc2,$lockCat,$aid){$svc2->delete($lockCat,$aid);},'PDOException','未提交绑定阻塞删除');vc_timeout($e,'未提交绑定真实锁超时');$pdo->commit();}finally{if($pdo->inTransaction())$pdo->rollBack();}
    $before=vc_hash($state);vc_reject(function()use($svc2,$lockCat,$aid){$svc2->delete($lockCat,$aid);},'InvalidArgumentException','提交绑定后删除当前读发现引用');vc_ok(vc_hash($state)===$before&&!$second->inTransaction(),'当前读拒删保持哈希');vc_sql('UPDATE products SET vpn_category_id=NULL WHERE id=?',array($lockProduct));$svc->delete($lockCat,$aid);
    // Make public fixtures unambiguous: other products remain persisted but inactive.
    vc_sql('UPDATE products SET status="inactive"');$pubA=vc_fixture_product('VISIBLE_A_MONTHLY',$a,'active',30,2999);$pubA2=vc_fixture_product('VISIBLE_A_SECOND',$a,'active',30,3999);$pubB=vc_fixture_product('VISIBLE_B_YEARLY',$b,'active',365,100);$pubNull=vc_fixture_product('VISIBLE_NULL',null,'active',90,4999);$pubX=vc_fixture_product('VISIBLE_X_ESCAPED',$x,'active',30,5999);$pubDraft=vc_fixture_product('HIDDEN_DRAFT',$a,'draft');$pubInactive=vc_fixture_product('HIDDEN_INACTIVE',$a,'inactive');vc_sql('UPDATE products SET status="active" WHERE id=?',array($account));
    vc_sql('INSERT INTO product_plan_costs(product_plan_id,cost_cents,updated_at) SELECT id,8765432,NOW() FROM product_plans WHERE product_id IN (?,?,?)',array($pubA,$pubA2,$pubB));
    $before=vc_hash($state);$visible=array('VISIBLE_A_MONTHLY','VISIBLE_A_SECOND','VISIBLE_B_YEARLY','VISIBLE_NULL','VISIBLE_X_ESCAPED');$bodies=array();
    foreach(array(array(array(),$visible),array(array('category'=>''),$visible),array(array('category'=>'uncategorized'),array('VISIBLE_NULL')),array(array('category'=>(string)$a),array('VISIBLE_A_MONTHLY','VISIBLE_A_SECOND')),array(array('category'=>(string)$b),array('VISIBLE_B_YEARLY')),array(array('category'=>(string)$x),array('VISIBLE_X_ESCAPED'))) as $case){
        $html=vc_run($router,'GET','/products',$case[0]);vc_ok(http_response_code()===200&&is_string($html)&&strpos($html,'purchase-card')!==false,'公开真实筛选有正向套餐输出 '.json_encode($case[0]));
        foreach($visible as $name)vc_ok((strpos($html,$name)!==false)===in_array($name,$case[1],true),'服务端卡片/推荐范围 '.$name.' '.json_encode($case[0]));
        preg_match_all('/<article class="purchase-card(?: [^"]*)?"[^>]*>(.*?)<\/article>/s',$html,$cards);vc_ok(count($cards[0])===count($case[1]),'服务器仅生成当前类卡片，不是客户端隐藏');
        foreach($case[1] as $name)vc_ok(strpos($html,'<h2>'.$name.'</h2>')!==false,'当前类套餐卡片保留 '.$name);
        vc_ok(strpos($html,'purchase-compare')===false&&strpos($html,'套餐权益对比')===false,'桌面表格及移动权益对比均不再生成');
        foreach(array('HIDDEN_DRAFT','HIDDEN_INACTIVE','ACCOUNT_IGNORES_CATEGORY','cost_cents','8765432','87654.32','87,654.32','[cost]') as $secret)vc_ok(strpos($html,$secret)===false,'公开范围/成本无泄漏 '.$secret);
        // 通过实际 /products 路由判定介绍归属，不能只依赖导航里本来就有的分类名。
        $selected=isset($case[0]['category'])?$case[0]['category']:'';
        if($selected===(string)$a){vc_intro($html,'A_MONTHLY_RENAMED',$descriptionMulti);vc_ok(strpos($html,e($descriptionHtml))===false&&strpos($html,'专项介绍丙独立分组')===false,'分类甲介绍不串乙丙');}
        elseif($selected===(string)$b){vc_intro($html,'B_YEARLY','专项介绍丙独立分组');vc_ok(strpos($html,e($descriptionMulti))===false&&strpos($html,e($descriptionHtml))===false,'分类丙介绍不串甲乙');}
        elseif($selected===(string)$x){vc_intro($html,$evil,$descriptionHtml);vc_ok(strpos($html,$descriptionHtml)===false&&strpos($html,'<script>alert(19)</script>')===false&&strpos($html,'</textarea>')===false,'公开 HTML 与 textarea 闭合攻击均转义');vc_ok(strpos($html,e($descriptionMulti))===false&&strpos($html,'专项介绍丙独立分组')===false,'分类乙介绍不串甲丙');}
        else{vc_intro($html);foreach(array($descriptionMulti,$descriptionHtml,'专项介绍丙独立分组') as $introText)vc_ok(strpos($html,e($introText))===false,'全部/未分类不泄露任何分类介绍');}
        $bodies[]=array($case[0],$html);
    }
    // 换行不仅存储正确，公开介绍的样式还必须明确使用 pre-wrap。
    $introCss=file_get_contents($root.'/public/assets/style.css');
    vc_ok(preg_match('/\.purchase-category-description\b[^{}]*\{[^{}]*white-space\s*:\s*pre-wrap\b/s',$introCss)===1||preg_match('/<div\b(?=[^>]*class="[^"]*\bpurchase-category-description\b)(?=[^>]*style="[^"]*white-space\s*:\s*pre-wrap\b)[^>]*>/s',$bodies[3][1])===1,'公开介绍明确采用 white-space: pre-wrap');
    $html=vc_run($router,'GET','/products',array('category'=>(string)$a,'q'=>'VPN & <tag>'));
    vc_ok(preg_match('/<button\b[^>]*class="purchase-period is-active"[^>]*data-period="monthly"/',$html)===1,'只有 monthly 时自动选中 monthly');
    vc_ok(strpos($html,'purchase-category-nav')!==false&&strpos($html,'purchase-category-link')!==false,'分类导航与 purchase-period 周期分离');
    preg_match('/<nav class="purchase-category-nav".*?<\/nav>/s',$html,$nav);preg_match_all('/href="([^"]+)"/',$nav[0],$links);vc_ok(count($links[1])>=5,'分类导航生成所有类别链接');foreach($links[1] as $href){parse_str((string)parse_url(html_entity_decode($href,ENT_QUOTES,'UTF-8'),PHP_URL_QUERY),$params);vc_ok(isset($params['q'])&&$params['q']==='VPN & <tag>','分类切换保留 q');}
    $html=vc_run($router,'GET','/products',array('category'=>(string)$x));vc_ok(strpos($html,e($evil))!==false&&strpos($html,$evil)===false,'分类导航及公开卡片转义 XSS');$detail=vc_run($router,'GET','/products/'.$pubX);vc_ok(strpos($detail,'plan-choice')!==false&&strpos($detail,e($evil))!==false&&strpos($detail,$evil)===false,'公开详情展示分类并转义');
    foreach(array_merge($badIds,array('unknown','UNCATEGORIZED')) as $bad){vc_run($router,'GET','/products',array('category'=>$bad));vc_ok(http_response_code()===400,'非法 category 为 400 '.var_export($bad,true));}
    vc_run($router,'GET','/products',array('category'=>'999999999'));vc_ok(http_response_code()===404,'规范但不存在 category 为 404');
    vc_ok(vc_hash($state)===$before,'全部公开筛选/非法参数/详情读取不改变数据库状态');
    $emptyCategory=(int)$svc->save(0,array('name'=>'EMPTY_PUBLIC','sort_order'=>'0','description'=>'专项空套餐分类介绍'),$aid);$emptyBody=vc_run($router,'GET','/products',array('category'=>(string)$emptyCategory));vc_ok(http_response_code()===200&&strpos($emptyBody,'<article class="purchase-card')===false,'存在但无套餐的类别正常空态');
    // 空套餐不能吞掉分类介绍；空介绍无论有无套餐都不得生成 section。
    vc_intro($emptyBody,'EMPTY_PUBLIC','专项空套餐分类介绍');
    $svc->save($emptyCategory,array('name'=>'EMPTY_PUBLIC','sort_order'=>'0','description'=>''),$aid);
    vc_intro(vc_run($router,'GET','/products',array('category'=>(string)$emptyCategory)));
    $noIntroProduct=vc_fixture_product('VISIBLE_NO_INTRO',$emptyCategory);
    $noIntroBody=vc_run($router,'GET','/products',array('category'=>(string)$emptyCategory));vc_intro($noIntroBody);
    vc_ok(http_response_code()===200&&strpos($noIntroBody,'<h2>VISIBLE_NO_INTRO</h2>')!==false,'有套餐且空介绍仍展示套餐，不生成介绍 section');
    vc_sql('UPDATE products SET vpn_category_id=NULL,status="inactive" WHERE id=?',array($noIntroProduct));$svc->delete($emptyCategory,$aid);
    // Audit rows intentionally changed in empty-category CRUD; snapshot after it for read-only proof.
    $readHash=vc_hash($state);$pdo->beginTransaction();try{vc_sql('UPDATE product_plan_costs pc JOIN product_plans pp ON pp.id=pc.product_plan_id SET pc.cost_cents=7654321 WHERE pp.product_id IN (?,?,?)',array($pubA,$pubA2,$pubB));foreach($bodies as $case)vc_ok(vc_run($router,'GET','/products',$case[0])===$case[1],'仅成本变化不影响公开路由任何 HTML');$firstDetail=vc_run($router,'GET','/products/'.$pubA);vc_sql('UPDATE product_plan_costs pc JOIN product_plans pp ON pp.id=pc.product_plan_id SET pc.cost_cents=6543210 WHERE pp.product_id=?',array($pubA));vc_ok(vc_run($router,'GET','/products/'.$pubA)===$firstDetail,'仅成本变化不影响详情任何 HTML');}finally{$pdo->rollBack();}
    vc_ok(vc_hash($state)===$readHash,'公开差分读取及回滚保持所有状态');
    $final=vc_hash($state);run_migrations($pdo,$root);run_migrations($pdo,$root);vc_ok(vc_hash($state)===$final&&vc_hash($protected)===$moneyHash,'最终两次完整迁移保留分类、套餐、售价、成本、订单及资金');
    echo 'PASS '.($upgrade?'--upgrade':'fresh').' VPN 分类回归完成；真实函数/路由/SQL/Security 类，模拟 page/redirect；未覆盖 HTTP 会话/CSRF exit、浏览器 JS、8081 实机验收。'.PHP_EOL;
}catch(Throwable $e){if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();if($second instanceof PDO&&$second->inTransaction())$second->rollBack();fwrite(STDERR,'VPN 测试失败：'.$e->getMessage().PHP_EOL);exit(1);}
