<?php
// 仅限 CLI：使用隔离 SQLite 测试数据和真实 GET 路由及辅助函数，不加载业务入口、不注册 POST、不调用网络。
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
foreach (array('Router','Security') as $name) require $root.'/app/Core/'.$name.'.php';
require $root.'/app/Core/AccountProductPages.php';
foreach (array('OrderService','CryptoService','SubBoostClient','PaymentService','WalletService') as $name) require $root.'/app/Services/'.$name.'.php';
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function page($title,$body,$config) { $GLOBALS['detail_body']=$body; }
function redirect($url) { throw new RuntimeException('REDIRECT '.$url); }
function flash($type,$text) {}
function detail_ok($ok,$label) {
    if (!$ok) throw new RuntimeException('FAIL '.$label);
    $GLOBALS['detail_checks']++;
    if (!array_intersect(array('--html','--payment-html','--temporary-html'),$_SERVER['argv'])) echo 'PASS '.$label.PHP_EOL;
}
// 复用 test_orders_page.php 的词法标记提取器，绝不执行 index.php 顶层代码。
function detail_function($source,$name) {
    $tokens=token_get_all($source);$capture=false;$depth=0;$opened=false;$result='';
    foreach($tokens as $i=>$token) {
        if(!$capture&&is_array($token)&&$token[0]===T_FUNCTION) {
            $j=$i+1;while(isset($tokens[$j])&&is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE)$j++;
            if(isset($tokens[$j])&&is_array($tokens[$j])&&$tokens[$j][1]===$name)$capture=true;
        }
        if(!$capture)continue;
        $result.=is_array($token)?$token[1]:$token;
        if($token==='{'){$opened=true;$depth++;}elseif($token==='}'&&--$depth===0&&$opened)return $result;
    }
    throw new RuntimeException('函数缺失 '.$name);
}
function detail_xpath($html) {
    $doc=new DOMDocument();$doc->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
    return new DOMXPath($doc);
}
function detail_run($no='FIXTURE-VPN') {
    global $pdo,$router;
    $_GET=array();$_SERVER['REQUEST_URI']='/orders/'.$no;$GLOBALS['detail_body']='';
    $before=$pdo->query('SELECT total_changes()')->fetchColumn();
    $pdo->exec('PRAGMA query_only=ON');
    try { $router->dispatch('GET','/orders/'.$no); }
    finally {
        if($before!=$pdo->query('SELECT total_changes()')->fetchColumn())throw new RuntimeException('GET 意外写入数据库');
        $pdo->exec('PRAGMA query_only=OFF');
    }
    return $GLOBALS['detail_body'];
}
function detail_delivery($id,$payload) {
    global $pdo,$config;
    $encrypted=(new CryptoService($config))->encrypt(json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    $pdo->prepare('UPDATE delivery_records SET content_ciphertext=?,content_nonce=?,content_tag=? WHERE id=?')->execute(array($encrypted[0],$encrypted[1],$encrypted[2],$id));
}
set_error_handler(function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$detail_checks=0;
$savedEnv=array();foreach(array('SUBBOOST_URL','SUBBOOST_API_SECRET') as $key){$savedEnv[$key]=getenv($key);putenv($key.'=');}
register_shutdown_function(function()use($savedEnv){foreach($savedEnv as $key=>$value)putenv($value===false?$key:$key.'='.$value);});
try {
    $pdo=new PDO('sqlite::memory:',null,null,array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));
    $pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,display_name TEXT,email TEXT,password_changed_at TEXT,balance_cents INTEGER);CREATE TABLE orders(id INTEGER PRIMARY KEY,user_id INTEGER,order_no TEXT,product_name_snapshot TEXT,plan_name_snapshot TEXT,product_type_snapshot TEXT,duration_days_snapshot INTEGER,total_amount_cents INTEGER,status TEXT,created_at TEXT,paid_at TEXT,delivered_at TEXT,cancelled_at TEXT,cancel_reason TEXT,cancelled_by_type TEXT,refund_requested_at TEXT,refund_processed_at TEXT,refund_reason TEXT,payment_started_at TEXT,temporary_subscription_url TEXT,temporary_subscription_status TEXT,account_platform_snapshot TEXT,account_specification_snapshot TEXT,account_after_sales_snapshot TEXT);CREATE TABLE order_events(id INTEGER PRIMARY KEY,order_id INTEGER,description TEXT,created_at TEXT,actor_type TEXT);CREATE TABLE delivery_records(id INTEGER PRIMARY KEY,order_id INTEGER,is_current INTEGER,version_no INTEGER,created_at TEXT,opened_at TEXT,expires_at TEXT,content_ciphertext TEXT,content_nonce BLOB,content_tag BLOB);CREATE TABLE payment_settings(id INTEGER PRIMARY KEY,enabled INTEGER,alipay_enabled INTEGER,wechat_enabled INTEGER,md5_key_ciphertext TEXT,md5_key_nonce BLOB,md5_key_tag BLOB)');
    $pdo->exec("INSERT INTO users VALUES(1,'测试旅人','fixture@example.test',NULL,25000),(2,'他人','other@example.test',NULL,0);INSERT INTO payment_settings VALUES(1,0,1,1,'',NULL,NULL)");
    $config=array('app'=>array('name'=>'小云店'),'security'=>array('encryption_key'=>'fixture-only'),'subboost'=>array('url'=>'https://delivery.example.test','api_secret'=>'fixture-only'));
    $insert=$pdo->prepare('INSERT INTO orders(id,user_id,order_no,product_name_snapshot,plan_name_snapshot,product_type_snapshot,duration_days_snapshot,total_amount_cents,status,created_at,temporary_subscription_url,temporary_subscription_status,account_platform_snapshot,account_specification_snapshot,account_after_sales_snapshot) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach(array('FIXTURE-VPN','FIXTURE-OTHER','FIXTURE-ACCOUNT') as $i=>$no)$insert->execute(array($i+1,$i===1?2:1,$no,'虚构畅享订阅','虚构年卡',$i===2?'account':'vpn',365,19800,'delivered','2026-11-06 10:00:00','https://delivery.example.test/temporary/FIXTURE-TEMP','active','虚构平台','虚构规格','虚构售后'));
    $pdo->exec("INSERT INTO order_events VALUES(1,1,'虚构付款已确认','2026-11-06 10:01:00','admin');INSERT INTO delivery_records VALUES(1,1,1,2,'2026-11-06','1990-01-01','1991-01-01','broken','broken','broken'),(2,1,0,1,'2020-01-01',NULL,NULL,'broken-history','broken','broken'),(3,2,1,1,'2026-11-06',NULL,NULL,'broken-other','broken','broken'),(4,3,1,1,'2026-11-06',NULL,NULL,'broken','broken','broken')");
    $payload=array('subscription'=>'https://delivery.example.test/api/subscriptions/FIXTURE-FORMAL/config.yaml','account'=>'FIXTURE-VPN-ACCOUNT','opened_at'=>'2026-11-06 10:02:00','expires_at'=>'2027-11-06 10:02:00','instructions'=>"虚构实际指令第一行\n虚构实际指令第二行");
    detail_delivery(1,$payload);detail_delivery(4,array('account'=>'FIXTURE-ACCOUNT-CONTENT'));
    $_SESSION=array('user_id'=>1,'user_password_changed_at'=>null,'_csrf'=>'fixture-csrf');
    $source=file_get_contents($root.'/public/index.php');
    foreach(array('money','status_text','order_timeline','order_detail_secret','order_detail_subscription','order_detail_script','user_nav_icon','home_outline_icon','account_overview_shell') as $name)eval(str_replace('__DIR__',var_export($root.'/public',true),detail_function($source,$name)));
    $start=strpos($source,"\$router->get('/orders/{no}',");$end=strpos($source,'function account_subscriptions_page(',$start);
    detail_ok($start!==false&&$end!==false,'精确提取真实 GET 路由边界');
    $route=substr($source,$start,$end-$start);
    detail_ok(substr_count($route,'$router->get(')===1&&strpos($route,'$router->post(')===false,'仅注册一条 GET 路由，不注册 POST');
    $router=new Router();eval($route);
    // 显式空环境变量不能继承现有业务配置，此时应回退为 legacy 链接。
    $emptyBody=detail_run();detail_ok(strpos($emptyBody,'本链接不支持多客户端切换')!==false,'空环境变量禁用客户端链接生成');
    // 真实 settings() 仅在环境覆盖不存在（而非空字符串）时使用虚构测试配置。
    putenv('SUBBOOST_URL');putenv('SUBBOOST_API_SECRET');
    $body=detail_run();$xp=detail_xpath($body);
    detail_ok(strpos($body,'od-delivered')!==false&&$xp->query('//section[@class="od-delivery-card"]')->length===1,'默认已交付 VPN 使用单卡片布局');
    detail_ok(strpos($body,'虚构畅享订阅')!==false&&strpos($body,'虚构年卡')!==false&&strpos($body,'FIXTURE-VPN')!==false,'展示实际商品、套餐与订单快照');
    detail_ok(strpos($body,$payload['opened_at'])!==false&&strpos($body,$payload['expires_at'])!==false&&strpos($body,'1990-01-01')===false,'日期仅来自实际加密交付内容');
    detail_ok($xp->query('//*[@id="subscription-link" and @hidden and @aria-labelledby="subscription-link-label"]')->length===1,'正式秘密在 JS 执行前已由服务端隐藏并关联标签');
    detail_ok($xp->query('//button[@data-detail-reveal="subscription-link" and @aria-pressed="false"]')->length===1&&$xp->query('//button[@data-detail-copy="subscription-link"]')->length===1,'显示与复制控件保留无障碍属性');
    detail_ok($xp->query('//select[@id="subscription-client"]/option')->length===11&&$xp->query('//option[@value="auto" and @selected]')->length===1,'保留全部十一种格式并默认选择 auto');
    detail_ok($xp->query('//details[@class="order-client-settings" and not(@open)]')->length===1&&strpos($body,'/FIXTURE-FORMAL/auto')!==false,'高级客户端切换默认折叠');
    detail_ok($xp->query('//details[@class="order-client-settings"]/summary//strong')->length===1&&strpos($body,'默认自动适配，导入异常时再手动选择')!==false,'高级格式入口包含清晰标题与默认适配提示');
    detail_ok(strpos($body,'od-temporary-guide')===false,'正式交付后不再显示临时订阅引导');
    detail_ok(strpos($body,'虚构实际指令第一行<br')!==false&&strpos($body,'/downloads')!==false&&strpos($body,'/articles?q=')!==false,'展示实际多行使用说明及帮助链接');
    detail_ok(strpos($body,'临时订阅已撤销')===false&&strpos($body,'FIXTURE-TEMP')===false,'交付后临时状态为 active 时不虚构撤销提示');
    detail_ok($xp->query('//details[@class="od-order-records" and not(@open)]')->length===1&&strpos($body,'¥198.00')!==false&&strpos($body,'虚构付款已确认')!==false&&strpos($body,'v1')!==false&&strpos($body,'v2')!==false,'金额、时间线与两个版本记录保留并折叠');
    detail_ok(strpos($body,'broken-history')===false&&strpos($body,'broken-other')===false,'损坏历史密文不被解密或暴露');
    $js=order_detail_script();detail_ok(strpos($js,'navigator.clipboard.writeText(value.textContent)')!==false&&strpos($js,'value.textContent=option.dataset.url')!==false&&strpos($js,'value.hidden=!show')!==false,'源码保留原有复制、显示及本地客户端切换逻辑');
    detail_ok(!preg_match('/fetch\s*\(|XMLHttpRequest|localStorage|sessionStorage|sendBeacon/',$js),'秘密相关 JS 不发起网络请求或持久化存储');
    detail_ok($xp->query('//*[@href and contains(@href,"FIXTURE-FORMAL")] | //*[@action and contains(@action,"FIXTURE-FORMAL")]')->length===0,'正式秘密不进入导航或表单地址');
    $helper=detail_xpath(order_detail_secret('fixture-secret','虚构标签','<script>fixture</script>',true));
    detail_ok($helper->query('//div[@id="fixture-secret" and @hidden]')->length===1&&$helper->query('//script')->length===0,'多行秘密辅助函数默认隐藏并转义内容');
    // 共享外壳同样必须在只读保护下渲染，避免个人资料查询意外触发业务写入。
    $before=$pdo->query('SELECT total_changes()')->fetchColumn();
    $pdo->exec('PRAGMA query_only=ON');
    ob_start();
    try {
        account_overview_shell('订阅交付',$body,'',0,$pdo,$config,'/orders','order-detail');
        $html=ob_get_contents();
    } finally {
        ob_end_clean();
        try {
            detail_ok($before==$pdo->query('SELECT total_changes()')->fetchColumn(),'共享外壳受 query_only 和 total_changes 只读保护');
        } finally {
            $pdo->exec('PRAGMA query_only=OFF');
        }
    }
    detail_ok(strpos($html,'/assets/order-detail-editorial.css')!==false&&strpos($html,'order-detail-editorial"')!==false&&strpos($html,'account-orders-editorial.css')===false,'完整详情外壳加载独立 CSS 和 body 类，不加载列表 CSS');
    detail_ok(strpos($html,'href="/orders" aria-current="page"')!==false&&strpos(substr($html,strpos($html,'<aside class="ao-sidebar"'),strpos($html,'</aside>')-strpos($html,'<aside class="ao-sidebar"')),'href="/account/overview" aria-current="page"')===false,'共享外壳侧栏正确高亮我的订单');
    $pageSource=detail_function($source,'page');
    detail_ok(strpos($pageSource,'^/orders/[^/]+$')!==false&&strpos($pageSource,"'/orders', 'order-detail'")!==false,'正常 page 分发器选择详情专用外壳');
    $listSource=detail_function($source,'account_orders_page');
    detail_ok(strpos($listSource,'#order-delivery')!==false&&strpos($listSource,'/orders/')!==false,'正常订单列表仍链接到详情 GET 入口的交付锚点');
    detail_ok(strpos(detail_run('FIXTURE-OTHER'),'订单不存在或无权查看')!==false,'他人订单不可访问且不解密其损坏交付密文');
    detail_ok(strpos(detail_run('MISSING'),'订单不存在或无权查看')!==false,'不存在的订单不触发解密');
    $session=$_SESSION;$_SESSION=array();try{detail_run();throw new LogicException('访客被错误放行');}catch(RuntimeException $ex){detail_ok($ex->getMessage()==='REDIRECT /login','访客跳转到登录页');}finally{$_SESSION=$session;}
    $pdo->exec("UPDATE orders SET temporary_subscription_status='revoked' WHERE id=1");detail_ok(strpos(detail_run(),'临时订阅已撤销')!==false,'临时订阅状态为 revoked 时展示真实撤销提示');
    foreach(array('cancelled','refunded','refunding') as $state){
        $pdo->prepare('UPDATE orders SET status=?,temporary_subscription_status="active" WHERE id=1')->execute(array($state));
        $pdo->exec("UPDATE delivery_records SET content_ciphertext='invalid-current' WHERE id=1");
        $result=detail_run();detail_ok(strpos($result,'FIXTURE-FORMAL')===false&&strpos($result,'FIXTURE-TEMP')===false&&strpos($result,'subscription-link')===false&&strpos($result,'od-temporary-guide')===false,'不解密正式内容或暴露临时秘密，订单状态为 '.$state);
    }
    $pdo->exec("UPDATE orders SET status='pending_delivery' WHERE id=1");
    $result=detail_run();$temp=detail_xpath($result);detail_ok($temp->query('//*[@id="temporary-subscription-link" and @hidden]')->length===1&&strpos($result,'FIXTURE-TEMP')!==false,'待交付且临时状态为 active 时提供默认隐藏的临时秘密控件');
    $temporaryBody=$result;
    detail_ok($temp->query('//a[@class="od-temporary-guide" and @href="#temporary-subscription"]')->length===1&&$temp->query('//*[@id="temporary-subscription"]')->length===1&&strpos($result,'下滑，先使用临时订阅')!==false,'有效临时订阅在状态区提供真实下滑锚点');
    detail_ok($temp->query('//a[@class="od-temporary-guide" and contains(@href,"FIXTURE-TEMP")]')->length===0,'引导入口不包含临时订阅秘密');
    $pdo->exec("UPDATE orders SET temporary_subscription_status='revoked' WHERE id=1");$revoked=detail_run();detail_ok(strpos($revoked,'FIXTURE-TEMP')===false&&strpos($revoked,'od-temporary-guide')===false,'待交付且临时状态为 revoked 时不展示临时秘密或使用引导');
    $pdo->exec("UPDATE orders SET temporary_subscription_status='active',temporary_subscription_url='' WHERE id=1");detail_ok(strpos(detail_run(),'od-temporary-guide')===false,'临时地址为空时不提示可用');
    $pdo->exec("UPDATE orders SET temporary_subscription_url='https://delivery.example.test/temporary/FIXTURE-TEMP',temporary_subscription_status='revoked' WHERE id=1;UPDATE orders SET status='pending_delivery' WHERE id=3");
    detail_ok(strpos(detail_run('FIXTURE-ACCOUNT'),'od-temporary-guide')===false,'海外账号待交付时不显示临时订阅引导');
    $pdo->exec("UPDATE orders SET status='delivered' WHERE id=3");
    $account=detail_run('FIXTURE-ACCOUNT');detail_ok(strpos($account,'FIXTURE-ACCOUNT-CONTENT')!==false&&strpos($account,'subscription-client')===false&&strpos($account,'正式订阅链接')===false&&strpos($account,'od-delivered')===false,'海外账号保留原交付结构且不展示 VPN 界面');
    $pdo->exec("UPDATE orders SET status='pending_payment' WHERE id=1");$payment=detail_run();$px=detail_xpath($payment);
    detail_ok($px->query('//form[@action="/orders/FIXTURE-VPN/balance-pay"]/button[not(@disabled)]')->length===1&&strpos($payment,'/payment/start/')===false,'支付网关禁用时不展示在线支付，实际余额足够时允许余额支付');
    detail_ok($px->query('//form[@action="/orders/FIXTURE-VPN/cancel"]//input[@name="_csrf" and @value="fixture-csrf"]')->length===1&&$px->query('//form[@action="/orders/FIXTURE-VPN/balance-pay"]//input[@name="_csrf"]')->length===1,'原取消与余额支付路由保留 CSRF');
    $pdo->exec('UPDATE payment_settings SET enabled=1');$payment=detail_run();$px=detail_xpath($payment);
    detail_ok($px->query('//form[starts-with(@action,"/payment/start/")]//input[@name="_csrf" and @value="fixture-csrf"]')->length===2&&strpos($payment,'/alipay')!==false&&strpos($payment,'/wxpay')!==false,'测试支付网关启用时仅渲染原支付表单并保留 CSRF');
    $pdo->exec('UPDATE users SET balance_cents=0 WHERE id=1');detail_ok(detail_xpath(detail_run())->query('//form[contains(@action,"balance-pay")]/button[@disabled]')->length===1,'余额不足时禁用余额支付');
    $pdo->exec("UPDATE users SET balance_cents=25000 WHERE id=1;UPDATE orders SET payment_started_at='2026-11-06' WHERE id=1");detail_ok(detail_xpath(detail_run())->query('//form[contains(@action,"balance-pay")]/button[@disabled]')->length===1,'已发起在线支付时禁用余额支付');
    $confirmingBody=detail_run();
    detail_ok(strpos($confirmingBody,'付款确认中')!==false&&strpos($confirmingBody,'尚未确认到账')!==false&&strpos($confirmingBody,'/orders/FIXTURE-VPN/payment-result')!==false,'在线支付发起后区分确认中并提供真实刷新入口，不伪造到账');
    detail_ok(strpos($confirmingBody,'order-reference-confirming')!==false,'付款确认中独立视觉状态不改变订单业务状态');
    $pdo->exec("UPDATE orders SET status='delivered' WHERE id=1");
    $minimal=array('subscription'=>$payload['subscription']);detail_delivery(1,$minimal);$result=detail_run();
    detail_ok(strpos($result,'粘贴订阅链接并按教程完成导入')!==false&&strpos($result,'od-dates')===false&&strpos($result,'2027-11-06')===false,'缺少使用说明时提供默认指引且不伪造日期');
    $pdo->exec('UPDATE delivery_records SET is_current=0 WHERE id=1');$result=detail_run();detail_ok(strpos($result,'交付记录待核对')!==false&&strpos($result,'id="subscription-link"')===false&&strpos($result,'临时订阅已撤销')===false,'缺少 current 记录时提示核对，不伪造交付内容或撤销提示');
    $pdo->exec('UPDATE delivery_records SET is_current=1 WHERE id=1');
    $evil='<script>fixture-xss</script>';detail_delivery(1,array('subscription'=>$evil,'account'=>$evil,'instructions'=>$evil,'opened_at'=>$evil));
    $pdo->prepare('UPDATE orders SET product_name_snapshot=?,plan_name_snapshot=? WHERE id=1')->execute(array($evil,$evil));
    $result=detail_run();detail_ok(strpos($result,$evil)===false&&substr_count($result,e($evil))>=5,'商品、套餐、秘密、使用说明和日期均进行 XSS 转义');
    detail_ok(strpos(order_detail_subscription('https://legacy.example.test/fixture?x=1&y=2',$config),'本链接不支持多客户端切换')!==false,'保留 legacy 原链接，不伪造客户端格式');
    detail_ok((int)$pdo->query('PRAGMA query_only')->fetchColumn()===0,'所有 GET 均受 query_only 和 total_changes 保护，仅在保护外修改测试数据');
    detail_ok(strpos(order_detail_script(),'form.requestSubmit(button)')!==false && strpos(order_detail_script(),'radio.disabled=button.disabled')!==false,'移动付款复用原表单和禁用状态，不另行构造支付请求');
    detail_ok(strpos(order_detail_script(),"radio.name='mobile_payment_method'")!==false && strpos(order_detail_script(),"pay.disabled=!selected")!==false,'支付方式使用原生单选且无可用渠道时禁用付款按钮');
    if(in_array('--temporary-html',$_SERVER['argv'],true)){
        ob_start();account_overview_shell('订单详情',$temporaryBody,'',0,$pdo,$config,'/orders','order-detail');echo ob_get_clean();
    }elseif(in_array('--payment-html',$_SERVER['argv'],true)){
        $pdo->exec("UPDATE orders SET status='pending_payment',payment_started_at=NULL,product_name_snapshot='虚构畅享订阅',plan_name_snapshot='虚构年卡' WHERE id=1;UPDATE users SET balance_cents=3200 WHERE id=1;UPDATE order_events SET description='订单已提交，等待付款' WHERE order_id=1");
        ob_start();account_overview_shell('订单详情',detail_run(),'',0,$pdo,$config,'/orders','order-detail');$paymentHtml=ob_get_clean();
        echo $paymentHtml;
    }elseif(in_array('--html',$_SERVER['argv'],true))echo $html;
    else echo '订单详情页测试全部通过（共 '.$detail_checks." 项）\n";
} finally {
    foreach($savedEnv as $key=>$value)putenv($value===false?$key:$key.'='.$value);
    restore_error_handler();
}
