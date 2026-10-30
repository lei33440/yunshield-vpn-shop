<?php
// Explicit isolated database only. Executes the actual registered page handlers.
if(PHP_SAPI!=='cli')exit(1);
$dsn=getenv('CHAOXI_TEST_DSN');$names=array();foreach(explode(';',substr((string)$dsn,6)) as $part)if(preg_match('/^\s*dbname\s*=(.*)$/i',$part,$m))$names[]=$m[1];
if(strpos((string)$dsn,'mysql:')!==0||count($names)!==1||!preg_match('/\Achaoxi_wallet_test_[A-Za-z0-9_]+\z/D',$names[0]))throw new RuntimeException('Explicit unique isolated DSN required');
$pdo=new PDO($dsn,getenv('CHAOXI_TEST_USER'),getenv('CHAOXI_TEST_PASSWORD'),array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false));
if($pdo->query('SELECT DATABASE()')->fetchColumn()!==$names[0])throw new RuntimeException('Database mismatch');
set_error_handler(function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});date_default_timezone_set('UTC');
$root=dirname(__DIR__);spl_autoload_register(function($class)use($root){foreach(array('/app/Services/','/app/Core/') as $dir)if(is_file($file=$root.$dir.$class.'.php')){require_once $file;return;}});
function e($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
class PageRedirect extends Error{public $url;public function __construct($url){$this->url=$url;}}
function redirect($url){throw new PageRedirect($url);}function flash($type,$message){$GLOBALS['test_flash'][$type]=$message;}
function page($title,$body,$config){$GLOBALS['test_body']=$body;}
require $root.'/app/Core/AdminDashboard.php';require $root.'/app/Core/AdminView.php';require $root.'/app/Core/Security.php';require $root.'/app/Core/AccountProductPages.php';require $root.'/app/Core/AdminExtraPages.php';
// Reuse the cost regression's token extractors, never its executable test/bootstrap.
$extractorTokens=token_get_all(file_get_contents($root.'/scripts/test_product_plan_costs.php'));
$extractorNames=array('pc_text','pc_open','pc_functions','pc_route_source');$extractorFound=array();
for($i=0,$n=count($extractorTokens);$i<$n;$i++){
    if(!is_array($extractorTokens[$i])||$extractorTokens[$i][0]!==T_FUNCTION)continue;
    $j=$i+1;while($j<$n&&is_array($extractorTokens[$j])&&in_array($extractorTokens[$j][0],array(T_WHITESPACE,T_COMMENT,T_DOC_COMMENT),true))$j++;
    if(!isset($extractorTokens[$j])||!is_array($extractorTokens[$j])||!in_array($extractorTokens[$j][1],$extractorNames,true))continue;
    $name=$extractorTokens[$j][1];$chunk='';$balance=0;$started=false;
    for(;$i<$n;$i++){$t=$extractorTokens[$i];$chunk.=is_array($t)?$t[1]:$t;if($t==='{'||(is_array($t)&&in_array($t[0],array(T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES),true))){$balance++;$started=true;}elseif($t==='}')$balance--;if($started&&$balance===0)break;}
    if(!$started||$balance!==0||isset($extractorFound[$name]))throw new RuntimeException('Invalid test extractor boundary');
    eval($chunk);$extractorFound[$name]=true;
}
if(count($extractorFound)!==count($extractorNames))throw new RuntimeException('Missing cost test extractor');
$source=file_get_contents($root.'/public/index.php');
pc_functions($root.'/public/index.php',array('product_form','plan_description_html','money','status_text','order_timeline','post_csrf','copy_subscription_script','delivery_duration_presets','delivery_duration_script','product_plans_input','save_product_plans','product_input','user_nav_icon','shop_plan_period','shop_plan_key','shop_plan_daily','shop_traffic_gb','shop_tagline'));
$config=array('security'=>array('encryption_key'=>bin2hex(random_bytes(32))),'app'=>array('timezone'=>'UTC','name'=>'isolated'));
$router=new Router();account_catalog_routes($router,$pdo,$config);
// Complete registration statements also support the multi-line public catalog route.
preg_match_all("~^\\\$router->(get|post)\\('(/products(?:/\\{id\\})?|/order-confirm|/orders(?:/\\{no\\})?|/admin/products/(?:create|\\{id\\}/(?:edit|toggle))|/admin/orders/\\{no\\}(?:/(?:deliver|update-delivery))?)'~m",$source,$registrations,PREG_SET_ORDER);
foreach($registrations as $registration)eval(pc_route_source($source,$registration[1],$registration[2]));
register_admin_extra_routes($router,$pdo,$config);
function ap_ok($value,$label){if(!$value)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label.PHP_EOL;}
function ap_sql($sql,$args=array()){global $pdo;$s=$pdo->prepare($sql);$s->execute($args);return $s;}
function ap_run($method,$path,$get=array(),$post=array()){global $router;$GLOBALS['test_body']='';$GLOBALS['test_flash']=array();$_GET=$get;$_POST=$post+array('_csrf'=>'test');http_response_code(200);try{$router->dispatch($method,$path);}catch(PageRedirect $r){return $r->url;}return $GLOBALS['test_body'];}
function ap_reject($fn,$label){try{$fn();}catch(InvalidArgumentException $e){ap_ok(true,$label);return;}throw new RuntimeException('FAIL '.$label);}
$tag='page_'.bin2hex(random_bytes(6));ap_sql('INSERT INTO users(email,password_hash,created_at,updated_at) VALUES(?,"isolated",NOW(),NOW())',array($tag.'@example.test'));$uid=(int)$pdo->lastInsertId();ap_sql('INSERT INTO admin_users(email,password_hash,created_at,updated_at) VALUES(?,"isolated",NOW(),NOW())',array('admin_'.$tag.'@example.test'));$aid=(int)$pdo->lastInsertId();$_SESSION=array('user_id'=>$uid,'user_password_changed_at'=>'','admin_id'=>$aid,'_csrf'=>'test');
$input=array('product_type'=>'account','name'=>$tag,'description'=>'public description','account_platform'=>'telegram','account_specification'=>'PUBLIC_SPEC_'.$tag,'account_after_sales'=>'PUBLIC_CARE_'.$tag,'status'=>'active','sort_order'=>'0','plans'=>array(array('id'=>0,'name'=>'single','price'=>'10.00','status'=>'active','sort_order'=>'0')));
ap_ok(account_catalog_ready($pdo),'complete schema is ready');ap_reject(function(){catalog_type(array());},'missing type rejected');ap_reject(function(){catalog_type(array('product_type'=>'unknown'));},'unknown type rejected');
$url=ap_run('POST','/admin/products/create',array(),$input);ap_ok($url==='/admin/products'&&empty($GLOBALS['test_flash']['error']),'real create handler account success');$p=ap_sql('SELECT * FROM products WHERE name=?',array($tag))->fetch();$pid=(int)$p['id'];$plan=ap_sql('SELECT * FROM product_plans WHERE product_id=?',array($pid))->fetch();$planId=(int)$plan['id'];ap_ok($p['traffic']===null&&$p['region']===null&&$p['device_limit']===null&&$p['duration_days']===null&&$plan['duration_days']===null,'account VPN fields SQL NULL');
$body=ap_run('GET','/accounts',array('platform'=>'telegram'));ap_ok(strpos($body,$tag)!==false,'platform list includes account');$body=ap_run('GET','/accounts',array('platform'=>'google'));ap_ok(strpos($body,$tag)===false,'platform list excludes other platform');ap_run('GET','/products/'.$pid);ap_ok(http_response_code()===404,'account on VPN URL is 404');
ap_sql('INSERT INTO products(name,description,product_type,traffic,region,device_limit,duration_days,price_cents,created_at,updated_at) VALUES(?,"VPN","vpn","10G","HK",1,30,1000,NOW(),NOW())',array('vpn_'.$tag));$vpn=(int)$pdo->lastInsertId();ap_run('GET','/accounts/'.$vpn);ap_ok(http_response_code()===404,'VPN on account URL is 404');
$body=ap_run('GET','/products');ap_ok(strpos($body,'PUBLIC_SPEC_'.$tag)===false&&strpos($body,'data-name="'.$tag.'"')===false,'VPN catalog excludes account');
$body=ap_run('GET','/order-confirm',array('product_id'=>$pid,'plan_id'=>$planId));ap_ok(strpos($body,'单个账号')!==false&&strpos($body,'name="promotion_code"')===false&&strpos($body,'0 天')===false,'checkout account no promotion or zero day');
ap_run('GET','/order-confirm',array('product_id'=>$pid,'plan_id'=>$planId,'group_id'=>1));ap_ok(http_response_code()===400,'checkout group tampering rejected');
ap_run('POST','/orders',array(),array('product_id'=>$pid,'plan_id'=>$planId,'note'=>'','quantity'=>'2'));ap_ok(!empty($GLOBALS['test_flash']['error'])&&(int)ap_sql('SELECT COUNT(*) FROM orders WHERE user_id=?',array($uid))->fetchColumn()===0,'POST quantity tamper rejected');
$url=ap_run('POST','/orders',array(),array('product_id'=>$pid,'plan_id'=>$planId,'note'=>'','quantity'=>'1'));$no=substr($url,8);ap_ok(strpos($url,'/orders/')===0,'real order POST success');
$changed=$input;$changed['product_type']='vpn';$changed['traffic']='10G';$changed['region']='HK';$changed['device_limit']='1';$changed['plans'][0]['duration_days']='30';ap_run('POST','/admin/products/'.$pid.'/edit',array(),$changed);ap_ok(!empty($GLOBALS['test_flash']['error'])&&ap_sql('SELECT product_type FROM products WHERE id=?',array($pid))->fetchColumn()==='account','edit immutable type rejected');
ap_sql('UPDATE products SET account_specification="" WHERE id=?',array($pid));ap_run('POST','/admin/products/'.$pid.'/toggle');ap_ok(!empty($GLOBALS['test_flash']['error']),'toggle invalid account configuration rejected');ap_sql('UPDATE products SET account_specification=? WHERE id=?',array('CHANGED_SPEC',$pid));
$service=new OrderService($pdo);$service->confirmPayment($no,$aid);$body=ap_run('GET','/admin/orders/'.$no);ap_ok(strpos($body,'账号交付内容')!==false&&strpos($body,'name="subscription"')===false&&strpos($body,'name="duration_preset"')===false,'admin account form no VPN inputs');$secret='PRIVATE_'.$tag.'<script>alert(1)</script>';
ap_run('POST','/admin/orders/'.$no.'/deliver',array(),array('account'=>$secret,'opened_at'=>'','expires_at'=>'','instructions'=>'private help'));ap_ok(empty($GLOBALS['test_flash']['error']),'real delivery POST nullable dates');$body=ap_run('GET','/orders/'.$no,array('view'=>'detail'));ap_ok(strpos($body,e($secret))!==false&&strpos($body,$secret)===false&&strpos($body,'PUBLIC_SPEC_'.$tag)!==false&&strpos($body,'CHANGED_SPEC')===false&&strpos($body,'0 天')===false,'buyer escaped encrypted text and original public snapshot');$record=ap_sql('SELECT * FROM delivery_records WHERE order_id=(SELECT id FROM orders WHERE order_no=?) AND is_current=1',array($no))->fetch();ap_ok(strpos($record['content_ciphertext'],$secret)===false&&$record['opened_at']===null&&$record['expires_at']===null,'delivery persisted encrypted and dates NULL');
$_SESSION['user_id']=$uid+999999;$body=ap_run('GET','/orders/'.$no,array('view'=>'detail'));ap_ok(strpos($body,'PRIVATE_'.$tag)===false,'other buyer cannot see delivery');$_SESSION['user_id']=$uid;
$body=ap_run('GET','/orders',array('product_type'=>'account'));ap_ok(strpos($body,'1<small> 笔订单')!==false&&strpos($body,$no)!==false&&strpos($body,'0天')===false,'user account filter count and display');$body=ap_run('GET','/orders',array('product_type'=>'vpn'));ap_ok(strpos($body,'0<small> 笔订单')!==false&&strpos($body,$no)===false,'user VPN filter count excludes account');
$body=ap_run('GET','/admin/orders',array('product_type'=>'account','q'=>$no));ap_ok(strpos($body,'筛选匹配 1 笔')!==false&&strpos($body,$no)!==false,'actual overridden admin orders handler count');$body=ap_run('GET','/admin/subscriptions',array('q'=>$no));ap_ok(strpos($body,'href="/admin/orders/'.$no.'"')===false,'undated delivered account excluded from subscription list');
$active=(int)ap_sql('SELECT COUNT(DISTINCT o.id) FROM orders o JOIN delivery_records d ON d.order_id=o.id AND d.is_current=1 WHERE o.status="delivered" AND o.product_type_snapshot="vpn" AND (d.expires_at IS NULL OR d.expires_at>=NOW())')->fetchColumn();ap_ok(strpos($body,'共 '.$active.' 个有效订阅')!==false,'subscription displayed count equals VPN-only query');
class MissingAccountColumns {public function query($sql){return array(array('Field'=>'product_type'));}}
ap_ok(!account_catalog_ready(new MissingAccountColumns())&&account_catalog_ready($pdo),'partial schema not ready and different PDO not cached');
ap_run('GET','/accounts',array('platform'=>array('telegram')));ap_ok(http_response_code()===400,'array platform filter rejected');ap_run('GET','/accounts',array('platform'=>'invalid'));ap_ok(http_response_code()===400,'unknown platform filter rejected');
foreach(array(array('2'),'-1','1e0') as $bad){ap_run('POST','/orders',array(),array('product_id'=>$pid,'plan_id'=>$planId,'note'=>'','quantity'=>$bad));ap_ok(!empty($GLOBALS['test_flash']['error']),'POST invalid quantity shape rejected');}
foreach(array(array('google'),'invalid') as $bad){$test=$input;$test['account_platform']=$bad;ap_run('POST','/admin/products/create',array(),$test);ap_ok(!empty($GLOBALS['test_flash']['error']),'POST invalid platform rejected');}
foreach(array('-1','1e2') as $bad){$test=$input;$test['plans'][0]['price']=$bad;ap_run('POST','/admin/products/create',array(),$test);ap_ok(!empty($GLOBALS['test_flash']['error']),'POST invalid price rejected');}
$test=$changed;unset($test['product_type']);ap_run('POST','/admin/products/'.$pid.'/edit',array(),$test);ap_ok(!empty($GLOBALS['test_flash']['error'])&&ap_sql('SELECT product_type FROM products WHERE id=?',array($pid))->fetchColumn()==='account','missing edit type cannot convert account');
ap_sql('INSERT INTO product_plans(product_id,name,duration_days,price_cents,created_at,updated_at) VALUES(?,"VPN plan",30,1000,NOW(),NOW())',array($vpn));$foreign=(int)$pdo->lastInsertId();ap_run('POST','/orders',array(),array('product_id'=>$pid,'plan_id'=>$foreign,'note'=>''));ap_ok(!empty($GLOBALS['test_flash']['error']),'foreign product plan rejected');
$evil='</textarea><script>alert(2)</script>';$data=$p;$data['account_specification']=$evil;ap_ok(strpos(account_product_form($data,'/test','test'),e($evil))!==false&&strpos(account_product_form($data,'/test','test'),$evil)===false,'account form textarea closing XSS escaped');
$body=account_delivery_fields(array('account'=>$evil));ap_ok(strpos($body,e($evil))!==false&&strpos($body,$evil)===false,'encrypted delivery edit textarea XSS escaped');
// 搜索回归只追加在交易测试之后；沿用脚本开头的专用空库保护，不执行公共页面外壳。
function ap_search_attrs($source){
    preg_match_all('~([a-zA-Z_:][a-zA-Z0-9_:.-]*)\s*=\s*(["\x27])(.*?)\2~s',$source,$matches,PREG_SET_ORDER);
    $attrs=array();foreach($matches as $match)$attrs[strtolower($match[1])]=html_entity_decode($match[3],ENT_QUOTES,'UTF-8');return $attrs;
}
function ap_search_has_class($attrs,$class){return isset($attrs['class'])&&in_array($class,preg_split('/\s+/',trim($attrs['class'])),true);}
function ap_search_section($body,$tag,$class){
    preg_match_all('~<'.$tag.'\b([^>]*)>(.*?)</'.$tag.'\s*>~si',$body,$matches,PREG_SET_ORDER);
    foreach($matches as $match)if(ap_search_has_class(ap_search_attrs($match[1]),$class))return array(ap_search_attrs($match[1]),$match[2]);
    throw new RuntimeException('FAIL missing '.$tag.'.'.$class);
}
function ap_search_tags($body,$tag){
    preg_match_all('~<'.$tag.'\b([^>]*)>~si',$body,$matches,PREG_SET_ORDER);$result=array();foreach($matches as $match)$result[]=ap_search_attrs($match[1]);return $result;
}
function ap_search_ids($body){
    // 仅从真实商品卡的详情链接取商品编号，避免输入框或导航中的 q 回显造成假命中。
    preg_match_all('~<article\b([^>]*)>(.*?)</article\s*>~si',$body,$matches,PREG_SET_ORDER);$ids=array();
    foreach($matches as $match){if(!ap_search_has_class(ap_search_attrs($match[1]),'account-catalog-card'))continue;$links=array();foreach(ap_search_tags($match[2],'a') as $link)if(isset($link['href'])&&preg_match('~\A/accounts/([0-9]+)\z~D',$link['href'],$id))$links[]=(int)$id[1];if(count($links)!==1)throw new RuntimeException('FAIL ambiguous account card detail link');$ids[]=$links[0];}
    sort($ids,SORT_NUMERIC);return $ids;
}
function ap_search_expect($get,$expected,$label){$body=ap_run('GET','/accounts',$get);sort($expected,SORT_NUMERIC);ap_ok(http_response_code()===200&&ap_search_ids($body)===$expected,$label);return $body;}
function ap_search_url($url,$expected){
    $parts=parse_url($url);if($parts===false||!isset($parts['path'])||$parts['path']!=='/accounts'||isset($parts['host'])||isset($parts['fragment']))return false;
    $query=array();parse_str(isset($parts['query'])?$parts['query']:'',$query);ksort($query);ksort($expected);return $query===$expected;
}
function ap_search_fixture($name,$description,$spec,$platform,$status='active',$type='account',$planStatus='active'){
    global $pdo;
    ap_sql('INSERT INTO products(name,description,product_type,account_platform,account_specification,account_after_sales,device_limit,price_cents,status,created_at,updated_at) VALUES(?,?,?,?,?,?,NULL,1000,?,NOW(),NOW())',array($name,$description,$type,$platform,$spec,'仅用于搜索回归的售后规则',$status));$id=(int)$pdo->lastInsertId();
    if($planStatus!==null)ap_sql('INSERT INTO product_plans(product_id,name,duration_days,price_cents,status,created_at,updated_at) VALUES(?,?,NULL,1000,?,NOW(),NOW())',array($id,'搜索测试方案',$planStatus));return $id;
}
$searchPlatform=$tag.'_a';$otherPlatform=$tag.'_b';$emptyPlatform=$tag.'_e';
foreach(array($searchPlatform,$otherPlatform,$emptyPlatform) as $code)ap_sql('INSERT INTO account_platforms(code,name,sort_order,created_at,updated_at) VALUES(?,?,0,NOW(),NOW())',array($code,'搜索测试 '.$code));
$needle='FindMixed_'.$tag;$chinese='海外中文检索'.$tag;
$nameHit=ap_search_fixture($needle,'普通介绍','普通公开规格',$searchPlatform);
$descriptionHit=ap_search_fixture('描述测试 '.$tag,$needle,'普通公开规格',$searchPlatform);
$specHit=ap_search_fixture('规格测试 '.$tag,'普通介绍',$needle,$searchPlatform);
$otherHit=ap_search_fixture('其他平台 '.$tag,'普通介绍',$needle,$otherPlatform);
$chineseHit=ap_search_fixture('中文测试 '.$tag,$chinese,'普通公开规格',$searchPlatform);
$draftHit=ap_search_fixture('草稿 '.$tag,$needle,$needle,$searchPlatform,'draft');
$inactiveHit=ap_search_fixture('下架 '.$tag,$needle,$needle,$searchPlatform,'inactive');
$noPlanHit=ap_search_fixture('无方案 '.$tag,$needle,$needle,$searchPlatform,'active','account',null);
$inactivePlanHit=ap_search_fixture('无启用方案 '.$tag,$needle,$needle,$searchPlatform,'active','account','inactive');
$vpnHit=ap_search_fixture('其他类型 '.$tag,$needle,$needle,$searchPlatform,'active','vpn');
// 同商品多个启用方案也只能出现一张卡，停用方案不能影响另一启用方案。
ap_sql('INSERT INTO product_plans(product_id,name,price_cents,status,created_at,updated_at) VALUES(?,?,500,"active",NOW(),NOW()),(?,?,1,"inactive",NOW(),NOW())',array($nameHit,'第二启用方案',$nameHit,'停用方案'));
ap_search_expect(array('q'=>$needle),array($nameHit,$descriptionHit,$specHit,$otherHit),'search OR matches name description and public specification only');
ap_search_expect(array('q'=>strtolower($needle),'platform'=>$searchPlatform),array($nameHit,$descriptionHit,$specHit),'search is case insensitive and AND intersects platform');
ap_search_expect(array('q'=>" \t".$needle."\r\n",'platform'=>$otherPlatform),array($otherHit),'search trims surrounding ASCII whitespace');
ap_search_expect(array('q'=>$chinese),array($chineseHit),'search accepts Chinese UTF-8');
ap_search_expect(array('q'=>$needle,'platform'=>'telegram'),array(),'search cannot OR past platform restriction');
$visible=array($nameHit,$descriptionHit,$specHit,$chineseHit);
ap_search_expect(array('platform'=>$searchPlatform),$visible,'unsearched catalog excludes draft inactive VPN and products without active plans');
ap_search_expect(array('q'=>'','platform'=>$searchPlatform),$visible,'empty search equals no search');
ap_search_expect(array('q'=>" \t\r\n",'platform'=>$searchPlatform),$visible,'whitespace search equals no search');
$body=ap_search_expect(array('q'=>" \t".$needle.' ','platform'=>$searchPlatform),array($nameHit,$descriptionHit,$specHit),'search form and platform navigation render matching cards');
list($formAttrs,$formBody)=ap_search_section($body,'form','account-catalog-search');
ap_ok(isset($formAttrs['method'],$formAttrs['action'])&&strtolower($formAttrs['method'])==='get'&&$formAttrs['action']==='/accounts','in-page account search GET action');
$searchInputs=array();$platformInputs=array();foreach(ap_search_tags($formBody,'input') as $field){if(isset($field['id'])&&$field['id']==='account-search')$searchInputs[]=$field;if(isset($field['type'],$field['name'])&&strtolower($field['type'])==='hidden'&&$field['name']==='platform')$platformInputs[]=$field;}
ap_ok(count($searchInputs)===1&&isset($searchInputs[0]['name'],$searchInputs[0]['value'])&&$searchInputs[0]['name']==='q'&&$searchInputs[0]['value']===$needle,'search input id name and trimmed value');
ap_ok(count($platformInputs)===1&&isset($platformInputs[0]['value'])&&$platformInputs[0]['value']===$searchPlatform,'search form preserves platform in hidden input');
list($navAttrs,$navBody)=ap_search_section($body,'nav','account-platform-filter');$navLinks=ap_search_tags($navBody,'a');$allFound=false;$platformFound=false;
foreach($navLinks as $link){ap_ok(isset($link['href']),'platform navigation href exists');$parts=parse_url($link['href']);$query=array();parse_str(isset($parts['query'])?$parts['query']:'',$query);ap_ok(isset($query['q'])&&$query['q']===$needle&&ap_search_url($link['href'],$query),'every platform navigation link preserves trimmed q');if(!isset($query['platform'])||$query['platform']==='')$allFound=true;if(isset($query['platform'])&&$query['platform']===$otherPlatform){$platformFound=true;ap_search_expect($query,array($otherHit),'following platform link preserves search intersection');}}
ap_ok($allFound&&$platformFound,'all-platform and other-platform search navigation exist');
$allBody=ap_search_expect(array('q'=>$needle),array($nameHit,$descriptionHit,$specHit,$otherHit),'all-platform search retains q and removes platform constraint');
$allClear=array();foreach(ap_search_tags($allBody,'a') as $link)if(ap_search_has_class($link,'account-search-clear'))$allClear[]=$link;
ap_ok(count($allClear)===1&&isset($allClear[0]['href'])&&$allClear[0]['href']==='/accounts','clear search without platform returns bare account catalog');
$clearLinks=array();foreach(ap_search_tags($body,'a') as $link)if(ap_search_has_class($link,'account-search-clear'))$clearLinks[]=$link;
ap_ok(count($clearLinks)===1&&isset($clearLinks[0]['href'])&&ap_search_url($clearLinks[0]['href'],array('platform'=>$searchPlatform)),'clear search link removes q and preserves platform');
$clearQuery=array();parse_str((string)parse_url($clearLinks[0]['href'],PHP_URL_QUERY),$clearQuery);ap_search_expect($clearQuery,$visible,'following clear link restores platform catalog');
$body=ap_search_expect(array('q'=>'missing_'.$tag,'platform'=>$searchPlatform),array(),'unmatched search has zero product cards');
ap_ok(strpos($body,'没有找到匹配的账号商品')!==false,'unmatched search uses search-specific empty state');
$viewAll=false;foreach(ap_search_tags($body,'a') as $link)if(isset($link['href'])&&$link['href']==='/accounts')$viewAll=true;ap_ok($viewAll,'unmatched search offers unfiltered /accounts entry');
$body=ap_search_expect(array('platform'=>$emptyPlatform),array(),'empty platform without search has no cards');ap_ok(strpos($body,'当前平台暂无在售商品')!==false&&strpos($body,'没有找到匹配的账号商品')===false,'original platform empty state retained without search');
$body=ap_search_expect(array('q'=>" \t",'platform'=>$emptyPlatform),array(),'blank query on empty platform has no cards');ap_ok(strpos($body,'当前平台暂无在售商品')!==false&&strpos($body,'没有找到匹配的账号商品')===false,'blank search retains original empty state');
$plainCatalog=ap_run('GET','/accounts');$plainIds=ap_search_ids($plainCatalog);sort($plainIds,SORT_NUMERIC);$blankCatalog=ap_run('GET','/accounts',array('q'=>" \t\r\n"));ap_ok(http_response_code()===200&&ap_search_ids($blankCatalog)===$plainIds,'blank search without platform retains full catalog');
// 百字按 UTF-8 字符而非字节计；先 trim 再检查长度。
$ascii100=str_repeat('a',100);$utf100=str_repeat('界',100);$asciiHit=ap_search_fixture('百字英文 '.$tag,$ascii100,'普通公开规格',$searchPlatform);$utfHit=ap_search_fixture('百字中文 '.$tag,'普通介绍',$utf100,$searchPlatform);
ap_search_expect(array('q'=>$ascii100),array($asciiHit),'100 ASCII characters accepted');
ap_search_expect(array('q'=>$utf100),array($utfHit),'100 Chinese characters accepted despite 300 bytes');
ap_search_expect(array('q'=>" \t".$utf100."\r\n"),array($utfHit),'trimmed 100-character boundary accepted');
$invalidQueries=array('101 ASCII'=>str_repeat('a',101),'101 Chinese'=>str_repeat('界',101),'array'=>array($needle),'nested array'=>array(array($needle)),'null'=>null,'integer'=>1,'boolean'=>false,'float'=>1.5,'invalid UTF-8'=>"\xC3\x28",'truncated UTF-8'=>"\xE4\xB8",'overlong UTF-8'=>"\xC0\xAF");
foreach($invalidQueries as $label=>$query){$body=ap_run('GET','/accounts',array('q'=>$query,'platform'=>$searchPlatform));ap_ok(http_response_code()===400&&ap_search_ids($body)===array(),'invalid search rejected with 400: '.$label);}
// 特殊字符必须按字面匹配；为每种字符放入可被错误通配符命中的干扰商品。
foreach(array('%','_','!','!%_') as $i=>$literal){$prefix='literal'.$i.$tag;$query=$prefix.$literal.'tail';$literalHit=ap_search_fixture('字面字符 '.$i.' '.$tag,$query,'普通公开规格',$searchPlatform);ap_search_fixture('通配干扰 '.$i.' '.$tag,$prefix.'Xtail','普通公开规格',$searchPlatform);ap_search_fixture('转义干扰 '.$i.' '.$tag,$prefix.'tail','普通公开规格',$searchPlatform);ap_search_expect(array('q'=>$query),array($literalHit),'LIKE treats '.$literal.' literally using escape semantics');}
ap_search_expect(array('q'=>"' OR 1=1 -- ".$tag),array(),'SQL injection quote OR comment cannot return catalog');
ap_search_expect(array('q'=>"%' OR '1'='1"),array(),'SQL injection with percent and quotes remains literal');
$attributeQuery="\" onfocus=\"alert(9)'><svg/onload=alert(8)> & ".$tag;
$attributeHit=ap_search_fixture('属性转义 '.$tag,$attributeQuery,'普通公开规格',$searchPlatform);
$body=ap_search_expect(array('q'=>$attributeQuery,'platform'=>$searchPlatform),array($attributeHit),'HTML metacharacters still match literal description');
list($formAttrs,$formBody)=ap_search_section($body,'form','account-catalog-search');$escapedInput=false;foreach(ap_search_tags($formBody,'input') as $field)if(isset($field['id'])&&$field['id']==='account-search'){$escapedInput=isset($field['name'],$field['value'])&&$field['name']==='q'&&$field['value']===$attributeQuery&&!isset($field['onfocus'])&&!isset($field['onload']);}
ap_ok($escapedInput&&strpos($formBody,e($attributeQuery))!==false&&strpos($body,$attributeQuery)===false&&strpos($body,'<svg/onload=alert(8)>')===false,'q is escaped inside HTML input attribute and card text');
list($navAttrs,$navBody)=ap_search_section($body,'nav','account-platform-filter');foreach(ap_search_tags($navBody,'a') as $link){$query=array();parse_str((string)parse_url($link['href'],PHP_URL_QUERY),$query);ap_ok(isset($query['q'])&&$query['q']===$attributeQuery,'platform URL round-trips quotes ampersand and HTML characters');}
// 复用前面真实交付的加密记录，不新增明文私密数据；售后与交付内容均不属于搜索字段。
ap_ok(!empty($record['content_ciphertext']),'private search fixture reuses actual encrypted delivery');
ap_search_expect(array('q'=>'PRIVATE_'.$tag),array(),'search excludes private delivered account content');
ap_search_expect(array('q'=>'private help','platform'=>'telegram'),array(),'search excludes private delivery instructions');
ap_search_expect(array('q'=>'PUBLIC_CARE_'.$tag),array(),'search excludes account after-sales content');
// 卡片摘要不改变完整规格存储、搜索或详情。
foreach(array(null,''," \r\n\t") as $blank)ap_ok(account_card_specification($blank)==='','blank card specification omits tag area');
$specHtml=account_card_specification(" 地区：美国\r\n\r\n类型：独享\r期限：30天\n0\n");
ap_ok(substr_count($specHtml,'class="account-spec-tag"')===4&&strpos($specHtml,'>0</li>')!==false&&strpos($specHtml,'规格已简略')===false,'mixed line endings trim blanks preserve zero and four tags');
$line32=str_repeat('界',32);$line33=$line32.'尾';
$specHtml=account_card_specification($line32);ap_ok(strpos($specHtml,e($line32))!==false&&strpos($specHtml,'规格已简略')===false,'32 character tag stays complete');
$specHtml=account_card_specification($line33);ap_ok(strpos($specHtml,e($line32).'…')!==false&&strpos($specHtml,'尾')===false&&strpos($specHtml,'规格已简略')!==false,'33 character tag is shortened with detail hint');
$emoji=str_repeat('😀',33);$specHtml=account_card_specification($emoji);ap_ok(mb_check_encoding($specHtml,'UTF-8')&&strpos($specHtml,str_repeat('😀',32).'…')!==false,'emoji truncation preserves UTF-8');
$boundary=str_repeat('a',31).'&<script>alert(1)</script>';$specHtml=account_card_specification($boundary);
ap_ok(strpos($specHtml,str_repeat('a',31).'&amp;…')!==false&&strpos($specHtml,'<script>')===false,'truncate raw text before escaping HTML entity boundary');
$specHtml=account_card_specification('<b>"&</b>');ap_ok(strpos($specHtml,e('<b>"&</b>'))!==false&&strpos($specHtml,'<b>')===false,'specification tag HTML escaped');
$hiddenLine='hidden_spec_'.$tag;$tail='hidden_tail_'.$tag;$longLine=str_repeat('长',32).$tail;
$fullSpec="地区：美国\r\n类型：独享\r期限：30天\n".$longLine."\n".$hiddenLine;
$specProduct=ap_search_fixture('规格标签 '.$tag,'规格标签介绍 '.$tag,$fullSpec,$searchPlatform);
$body=ap_search_expect(array('q'=>$hiddenLine),array($specProduct),'fifth specification line remains searchable');
list($attrs,$card)=ap_search_section($body,'article','account-catalog-card');
ap_ok(substr_count($card,'class="account-spec-tag"')===4&&strpos($card,$hiddenLine)===false&&strpos($card,$tail)===false&&strpos($card,'规格已简略')!==false,'actual catalog card limits tags and includes detail hint');
ap_ok(strpos($card,'account-card-spec')<strpos($card,'account-card-description')&&count(ap_search_tags($card,'a'))===1,'specifications precede description with one detail link');
ap_search_expect(array('q'=>$tail),array($specProduct),'truncated specification tail remains searchable');
$detail=ap_run('GET','/accounts/'.$specProduct);ap_ok(strpos($detail,$hiddenLine)!==false&&strpos($detail,e($longLine))!==false,'detail retains full specification including omitted lines and tails');
$stored=ap_sql('SELECT account_specification FROM products WHERE id=?',array($specProduct))->fetchColumn();ap_ok($stored===$fullSpec,'card rendering leaves stored specification unchanged');
$form=account_product_form($p,'/test','test');ap_ok(strpos($form,'aria-describedby="account-spec-help"')!==false&&strpos($form,'每行填写一项公开规格')!==false,'admin form explains per-line specification tags');
echo "ACCOUNT PAGE HANDLERS PASSED\n";
