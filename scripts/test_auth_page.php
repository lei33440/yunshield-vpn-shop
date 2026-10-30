<?php
// 无数据库测试：执行实际认证 GET 模板与认证页渲染器，不提交账户数据。
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require $root.'/app/Core/Router.php';
function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function csrf_field() { return '<input type="hidden" name="_csrf" value="isolated-csrf">'; }
function page($title, $body, $config) { auth_page($title, $body, '<div class="notice error">隔离提示</div>', $config); }
function auth_assert($ok, $label) { if (!$ok) throw new RuntimeException('FAIL '.$label); echo 'PASS '.$label.PHP_EOL; }
$source = file_get_contents($root.'/public/index.php');
$tokens = token_get_all($source);
foreach ($tokens as $i=>$token) {
    if (!is_array($token) || $token[0] !== T_FUNCTION) continue;
    $j=$i+1;
    while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0]===T_WHITESPACE) $j++;
    if (!isset($tokens[$j]) || !is_array($tokens[$j]) || !in_array($tokens[$j][1], array('auth_page','auth_icon'), true)) continue;
    $chunk=''; $depth=0; $started=false;
    for (;$i<count($tokens);$i++) {
        $t=$tokens[$i]; $text=is_array($t)?$t[1]:$t; $chunk.=$text;
        if (!is_array($t) && $text==='{') { $depth++; $started=true; }
        if (!is_array($t) && $text==='}' && --$depth===0 && $started) break;
    }
    eval($chunk);
}
$config=array('app'=>array('name'=>'品牌<script>不可执行</script>'));
$router=new Router;
foreach (explode("\n", $source) as $line) if (preg_match("~^\\\$router->get\\('/(?:login|register)',~", $line)) eval($line);
foreach (array('/login','/register') as $path) {
    $_SERVER['REQUEST_URI']=$path; $_GET=array('ref'=>'"><script>ref</script>');
    ob_start(); $router->dispatch('GET',$path); $html=ob_get_clean();
    auth_assert(strpos($html,'class="auth-page"')!==false && strpos($html,'user-sidebar')===false, $path.' 独立认证外壳');
    auth_assert(strpos($html,'action="'.$path.'"')!==false && strpos($html,'name="_csrf" value="isolated-csrf"')!==false, $path.' 原提交目标与CSRF');
    auth_assert(strpos($html,'name="email"')!==false && strpos($html,'name="password"')!==false, $path.' 真实认证字段');
    auth_assert(strpos($html,'type="button"')!==false && strpos($html,'auth-password-toggle')!==false, $path.' 密码查看不提交表单');
    auth_assert(strpos($html,'<script>不可执行</script>')===false && strpos($html,'&lt;script&gt;不可执行&lt;/script&gt;')!==false, $path.' 品牌输出转义');
    auth_assert(strpos($html,'隔离提示')!==false && strpos($html,'aria-current="page"')!==false, $path.' 提示和选中入口');
    if ($path==='/register') auth_assert(strpos($html,'name="ref"')!==false && strpos($html,'<script>ref</script>')===false && strpos($html,'name="display_name"')!==false, '注册邀请字段保留且转义');
    preg_match_all('~/assets/(auth-[a-z-]+\.svg)~', $html, $matches);
    foreach (array_unique($matches[1]) as $asset) auth_assert(is_file($root.'/public/assets/'.$asset) && filesize($root.'/public/assets/'.$asset)>0, $path.' 本地资源 '.$asset);
}
