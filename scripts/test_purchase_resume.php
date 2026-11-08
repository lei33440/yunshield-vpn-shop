<?php
// 隔离内存数据库，执行真实登录路由；不读取站点配置、不发送网络请求。
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__).'/app/Core/Security.php';
require dirname(__DIR__).'/app/Core/Router.php';
function flash($type,$message) {}
function redirect($url) { throw new RuntimeException('REDIRECT '.$url); }
function post_csrf() { Security::checkCsrf($_POST['_csrf']); }
function resume_ok($condition,$label) {
    if (!$condition) throw new LogicException('FAIL '.$label);
    $GLOBALS['checks'][]='PASS '.$label;
}
function expect_redirect($fn) {
    try {$fn();} catch (RuntimeException $error) {return substr($error->getMessage(),9);}
    throw new LogicException('缺少预期跳转');
}
session_start();
$checks=array();
$_SESSION=array();
$_SERVER['REQUEST_URI']='/order-confirm?product_id=3&plan_id=7&group_id=2';
$_GET=array('product_id'=>'3','plan_id'=>'7','group_id'=>'2','next'=>'https://evil.example.test');
resume_ok(expect_redirect(function(){Security::requireUser();})==='/login','访客仍先登录，不绕过权限');
resume_ok($_SESSION['purchase_return']==='/order-confirm?product_id=3&plan_id=7&group_id=2','仅保留方案与拼团标识，不接收外部回跳');
$saved=$_SESSION['purchase_return'];
$_SESSION=array();$_GET=array('product_id'=>array('3'),'plan_id'=>'7');
expect_redirect(function(){Security::requireUser();});
resume_ok(!isset($_SESSION['purchase_return']),'数组参数不能进入回跳地址');
$_SERVER['REQUEST_URI']='/wallet';$_GET=array('product_id'=>'3','plan_id'=>'7');
expect_redirect(function(){Security::requireUser();});
resume_ok(!isset($_SESSION['purchase_return']),'只有购前确认入口记住方案');
$pdo=new PDO('sqlite::memory:',null,null,array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));
$pdo->exec('CREATE TABLE users(id INTEGER,email TEXT,status TEXT,password_hash TEXT,display_name TEXT,password_changed_at TEXT)');
$pdo->prepare('INSERT INTO users VALUES(1,?,"active",?,"隔离测试",NULL)')->execute(array('resume@example.test',password_hash('isolated-test-password',PASSWORD_DEFAULT)));
$config=array();$router=new Router();
$source=file_get_contents(dirname(__DIR__).'/public/index.php');
foreach(explode("\n",$source) as $line)if(strpos($line,"\$router->post('/login',")===0)eval($line);
$_SESSION=array('_csrf'=>'isolated-csrf','purchase_return'=>$saved);
$_POST=array('_csrf'=>'isolated-csrf','email'=>'resume@example.test','password'=>'incorrect');
resume_ok(expect_redirect(function()use($router){$router->dispatch('POST','/login');})==='/login'&&$_SESSION['purchase_return']===$saved,'密码错误后仍保留方案');
$_POST['password']='isolated-test-password';
resume_ok(expect_redirect(function()use($router){$router->dispatch('POST','/login');})===$saved,'真实密码验证后回到原方案确认页');
resume_ok(isset($_SESSION['user_id'])&&!isset($_SESSION['purchase_return']),'回跳仅使用一次并正常建立登录会话');
$_SESSION['purchase_return']='//evil.example.test';
resume_ok(expect_redirect(function()use($router){$router->dispatch('POST','/login');})==='/orders','非白名单地址退回原订单入口');
resume_ok(strpos($source,'已保留你选择的方案')!==false,'认证页展示续购说明');
session_destroy();
echo implode(PHP_EOL,$checks).PHP_EOL;
