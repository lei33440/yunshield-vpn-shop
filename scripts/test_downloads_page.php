<?php
// 执行真实下载路由，仅使用内存数据库，不加载站点配置或下载外部文件。
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require $root.'/app/Core/Router.php';
function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function page($title, $body, $config) { echo $body; }
function download_assert($ok, $label) {
    if (!$ok) throw new RuntimeException('FAIL '.$label);
    $GLOBALS['download_checks']++;
    echo 'PASS '.$label.PHP_EOL;
}
function download_document($router) {
    ob_start();
    $router->dispatch('GET', '/downloads');
    $html = ob_get_clean();
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    return array($html, new DOMXPath($doc));
}
$download_checks = 0;
$pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC));
$pdo->exec('CREATE TABLE download_resources (id INTEGER PRIMARY KEY AUTOINCREMENT, app_name TEXT, version TEXT, description TEXT, project_url TEXT, platform TEXT, filename TEXT, source_url TEXT, status TEXT, sort_order INTEGER)');
$fixtures = array(
    array('FlClash','Android','FlClash-v1-x86_64.apk',20,'active'),
    array('FlClash','Windows','FlClash-v1-amd64.exe',0,'active'),
    array('FlClash','Android','FlClash-v1-arm64-v8a.apk',0,'active'),
    array('FlClash','Android','FlClash-v1-armeabi-v7a.apk',10,'active'),
    array('FlClash','Android','FlClash-v2-arm64-v8a.apk',30,'active'),
    array('OtherApp','Android','OtherApp-arm64.apk',0,'active'),
    array('FlClash','Android','Disabled-arm64.apk',-1,'inactive'),
    array('FlClash','Windows','FlClash-v1-arm64.exe',0,'active'),
    array('<script>unsafe</script>','Android','<img src=x onerror=alert(1)>.apk',0,'active'),
);
$insert = $pdo->prepare('INSERT INTO download_resources(app_name,version,description,project_url,platform,filename,source_url,status,sort_order) VALUES(?,?,?,?,?,?,?,?,?)');
foreach ($fixtures as $i=>$f) {
    $insert->execute(array($f[0], 'v1', '<img src=x onerror=alert(2)>', 'https://example.test/project?a=1&b=2', $f[1], $f[2], 'https://example.test/download/'.$i.'?a=1&b=2', $f[4], $f[3]));
}
$config = array();
$router = new Router();
$source = file_get_contents($root.'/public/index.php');
$start = strpos($source, '$router->get(\'/downloads\',');
$end = strpos($source, '$router->get(\'/articles\',', $start === false ? 0 : $start);
if ($start === false || $end === false) throw new RuntimeException('未找到真实下载路由边界');
eval(substr($source, $start, $end-$start));
list($html, $xpath) = download_document($router);
$flclash = '//article[@class="download-app-card"][.//h2[text()="FlClash"]]';
$android = $xpath->query($flclash.'//div[@class="download-resource"][@data-platform="Android"]');
download_assert($android->length === 4, '同软件三个安卓架构及另一个版本全部保留');
$expected = array('FlClash-v1-arm64-v8a.apk','FlClash-v1-armeabi-v7a.apk','FlClash-v1-x86_64.apk','FlClash-v2-arm64-v8a.apk');
foreach ($android as $i=>$node) download_assert(strpos($node->textContent, $expected[$i]) !== false, '安卓资源排序 '.$expected[$i]);
$windows = $xpath->query($flclash.'//div[@class="download-resource"][@data-platform="Windows"]');
download_assert($windows->length === 2, '同平台多个Windows安装包不覆盖');
download_assert(strpos($windows->item(0)->textContent,'amd64.exe') !== false && strpos($windows->item(1)->textContent,'arm64.exe') !== false, '同sort_order按id保留原顺序');
download_assert($xpath->query('//article[@class="download-app-card"][.//h2[text()="OtherApp"]]//div[@class="download-resource"]')->length === 1, '不同软件独立分组');
download_assert($xpath->query('//div[@class="download-resource"]')->length === 8 && strpos($html,'Disabled-arm64.apk') === false, '停用资源不会进入公开列表');
foreach ($fixtures as $i=>$fixture) {
    if ($fixture[4] !== 'active') continue;
    $url = 'https://gh-proxy.com/https://example.test/download/'.$i.'?a=1&b=2';
    $links = $xpath->query('//a[@class="download-button"][@href="'.$url.'"]');
    download_assert($links->length === 1 && strpos($links->item(0)->parentNode->textContent,$fixture[2]) !== false, '安装包与原下载地址一一对应 '.$i);
}
download_assert(strpos($html,'&lt;script&gt;unsafe&lt;/script&gt;') !== false && strpos($html,'&lt;img src=x onerror=alert(1)&gt;.apk') !== false, '软件名及文件名转义');
download_assert($xpath->query('//*[@onerror]')->length === 0 && $xpath->query('//script')->length === 1, '描述等不注入额外标签或脚本');
download_assert($xpath->query('//button[@class="download-filter"][@data-platform="Android"]')->length === 1, '多个安卓包共用Android筛选按钮');
$pdo->exec('DELETE FROM download_resources');
list($fallbackHtml, $fallbackXpath) = download_document($router);
$fallbackRows = $fallbackXpath->query($flclash.'//div[@class="download-resource"][@data-platform="Android"]');
download_assert($fallbackRows->length === 3, '内置资源同样保留三个Android安装包');
foreach (array('arm64-v8a','armeabi-v7a','x86_64') as $arch) download_assert(strpos($fallbackHtml,'FlClash-0.8.98-android-'.$arch.'.apk') !== false, '内置架构 '.$arch);
download_assert($fallbackXpath->query('//div[@class="download-resource"][@data-unavailable="1"]')->length === 12, '内置未配置平台提示保持正常');
download_assert($fallbackXpath->query($flclash.'//div[@class="download-resource"]')->length === 6, '内置桌面与Android资源均完整');
echo 'DOWNLOAD PAGE TESTS PASSED '.$download_checks.PHP_EOL;
