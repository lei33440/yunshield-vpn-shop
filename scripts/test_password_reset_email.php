<?php
// 纯离线邮件测试：只加载邮件类，不加载配置、数据库、引导文件或任务入口。
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/app/Services/EmailService.php';
$preAssertions = 0;
function pre_ok($condition, $name) {
    if (!$condition) throw new RuntimeException('FAIL '.$name);
    $GLOBALS['preAssertions']++;
    echo 'PASS '.$name.PHP_EOL;
}
function pre_crlf($text) {
    return str_replace("\n", "\r\n", str_replace(array("\r\n", "\r"), "\n", $text));
}
function pre_escape($text) {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function pre_unescape($text) {
    return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
function pre_split_message($wire) {
    $pieces = explode("\r\n\r\n", $wire, 2);
    pre_ok(count($pieces) === 2, '邮件头与正文由 CRLF 空行分隔');
    return $pieces;
}
function pre_multipart($wire, $plain, $html) {
    pre_ok(preg_match('/(?<!\r)\n|\r(?!\n)/', $wire) === 0, 'MIME 线格式仅使用 CRLF');
    $shortLines = true;
    foreach (explode("\r\n", $wire) as $line) if (strlen($line) > 998) $shortLines = false;
    pre_ok($shortLines, 'MIME 每行不超过 998 字节');
    list($headers, $content) = pre_split_message($wire);
    pre_ok(strpos($headers, 'MIME-Version: 1.0') !== false, '保留 MIME 版本头');
    $matched = 0;
    foreach (explode("\r\n", $headers) as $line) {
        if (preg_match('/\AContent-Type: multipart\/alternative; boundary="([^"\r\n]+)"\z/', $line, $match)) { $matched = 1; break; }
    }
    pre_ok($matched === 1 && strlen($match[1]) <= 70, 'multipart/alternative 有合法长度的带引号 boundary');
    $boundary = $match[1];
    $segments = explode('--'.$boundary, $content);
    pre_ok(count($segments) === 4 && $segments[0] === '' && $segments[3] === '--', '恰好两个 MIME 分段且 boundary 正确闭合');
    foreach (array(array('plain', pre_crlf($plain)), array('html', $html)) as $index => $expected) {
        $segment = $segments[$index + 1];
        pre_ok(substr($segment, 0, 2) === "\r\n" && substr($segment, -2) === "\r\n", $expected[0].' 分段边界使用 CRLF');
        list($partHeaders, $encoded) = pre_split_message(substr($segment, 2));
        pre_ok($partHeaders === 'Content-Type: text/'.$expected[0]."; charset=UTF-8\r\nContent-Transfer-Encoding: base64", $expected[0].' 类型编码及分段顺序正确');
        pre_ok(substr($encoded, -2) === "\r\n", $expected[0].' base64 末行以 CRLF 结束');
        $lines = explode("\r\n", substr($encoded, 0, -2));
        $wrapped = count($lines) > 1;
        foreach ($lines as $i => $line) {
            if (strlen($line) < 1 || strlen($line) > 76 || preg_match('/\A[A-Za-z0-9+\/=]+\z/', $line) !== 1) $wrapped = false;
            if ($i < count($lines) - 1 && strlen($line) !== 76) $wrapped = false;
        }
        pre_ok($wrapped, $expected[0].' base64 按 76 字符换行且无空编码行');
        $compact = implode('', $lines);
        $decoded = base64_decode($compact, true);
        pre_ok($decoded !== false && base64_encode($decoded) === $compact, $expected[0].' base64 可严格解码且编码规范');
        pre_ok($decoded === $expected[1], $expected[0].' 解码后内容逐字节保真');
    }
    return $boundary;
}

$brand = '潮汐 <img src=x onerror="alert(1)"> & "品牌" \'账户\'';
$link = 'https://example.test/reset?token=testtoken&label="quoted"&single=\'yes\'&tag=<safe>&encoded=%26%22#fragment';
pre_ok(filter_var($link, FILTER_VALIDATE_URL) !== false, '特殊字符 URL 样本符合 filter_var 校验');
$message = EmailService::passwordResetMessage($brand, $link);
pre_ok(is_array($message) && array_keys($message) === array('subject', 'body', 'html'), '模板返回 subject/body/html');
foreach ($message as $key => $value) pre_ok(is_string($value) && $value !== '', $key.' 为非空字符串');
pre_ok($message['subject'] === $brand.' · 密码重置', '主题保留品牌文本');
pre_ok(strpos($message['body'], "\n\n") !== false && substr_count($message['body'], "\n") >= 6, '纯文本使用真实换行');
pre_ok(strpos($message['html'], "\n") !== false, 'HTML 使用真实换行');
pre_ok(strpos($message['body'], '\\n') === false && strpos($message['html'], '\\n') === false, '模板不含字面反斜线 n');
pre_ok(strpos($message['body'], $brand) !== false && substr_count($message['body'], $link) === 1, '纯文本保留原始品牌与完整 URL');
pre_ok(strpos($message['html'], pre_escape($brand)) !== false && strpos($message['html'], $brand) === false, '品牌中的尖括号单双引号及 & 被 HTML 转义');
pre_ok(strpos($message['html'], pre_escape($link)) !== false && strpos($message['html'], $link) === false, 'URL 中特殊字符被 HTML 转义');
preg_match_all('/<a\b[^>]*\bhref="([^"]*)"[^>]*>(.*?)<\/a>/is', $message['html'], $anchors, PREG_SET_ORDER);
pre_ok(count($anchors) === 2, '模板恰好有按钮和备用链接');
foreach ($anchors as $index => $anchor) pre_ok(pre_unescape($anchor[1]) === $link, '链接 '.($index + 1).' 解码后等于原始完整 URL');
pre_ok(trim(strip_tags($anchors[0][2])) === '重置密码' && strpos($anchors[0][0], 'display:inline-block') !== false, '主链接呈现重置密码按钮');
pre_ok(pre_unescape(trim(strip_tags($anchors[1][2]))) === $link, '备用链接可见文本也是同一个完整 URL');
foreach (array('body', 'html') as $key) {
    $visible = pre_unescape(strip_tags($message[$key]));
    pre_ok(strpos($visible, '自申请起 30 分钟内有效') !== false && strpos($visible, '使用后即失效') !== false, $key.' 明确从申请起计时并说明单次使用');
    pre_ok(strpos($visible, '如果不是您本人操作') !== false || strpos($visible, '不是您本人操作？') !== false, $key.' 包含非本人操作提示');
    pre_ok(strpos($visible, '请忽略此邮件') !== false && strpos($visible, '转发给他人') !== false, $key.' 包含忽略和禁止转发提示');
}
// 恶意品牌转义后仍可含 onerror= 文本，仅将真实标签内的事件属性视为执行入口。
pre_ok(preg_match('/<(?:img|script|iframe|object|embed|link|font)\b|@(?:import|font-face)\b|url\s*\(|<[^>]*\s+on\w+\s*=/i', $message['html']) === 0, 'HTML 无外部图片脚本字体加载或可执行事件属性');

foreach (array('http://example.test/reset?token=testtoken', 'https://example.test/reset?token=testtoken') as $validLink) {
    $validMessage = EmailService::passwordResetMessage('测试品牌', $validLink);
    pre_ok(strpos($validMessage['body'], $validLink) !== false && strpos($validMessage['html'], pre_escape($validLink)) !== false, '允许 URL '.$validLink);
}
foreach (array('javascript:alert(1)', 'data:text/html,<script>alert(1)</script>', '', 'invalidURL', 'https://', 'https://exa mple.test/reset', 'ftp://example.test/reset', '//example.test/reset', "https://example.test/reset\r\nBcc:other@example.test", null, array('https://example.test')) as $index => $invalidLink) {
    $rejected = false;
    try { EmailService::passwordResetMessage('测试品牌', $invalidLink); }
    catch (InvalidArgumentException $e) { $rejected = true; }
    pre_ok($rejected, '拒绝无效或非 http(s) URL 样本 #'.$index);
}
$sanitized = EmailService::passwordResetMessage("潮汐\r\n品牌", 'https://example.test/reset?token=testtoken');
pre_ok($sanitized['subject'] === '潮汐  品牌 · 密码重置' && preg_match('/[\r\n]/', $sanitized['subject']) === 0, '品牌 CRLF 不进入主题头');
$fallback = EmailService::passwordResetMessage(" \r\n ", 'https://example.test/reset?token=testtoken');
pre_ok($fallback['subject'] === '账户安全中心 · 密码重置', '空品牌回退至账户安全中心');

// 反射仅调用实际 MIME 构建方法；空 PDO 保证误调用 settings 会立即失败。
$service = new EmailService(null);
$reflection = new ReflectionMethod('EmailService', 'message');
// PHP 8.1 起无需开放可见性，旧版本保留兼容，避免新版弃用告警。
if (PHP_VERSION_ID < 80100) $reflection->setAccessible(true);
$cfg = array('from_name' => '潮汐测试', 'from_email' => 'sender@example.test');
$wire = $reflection->invokeArgs($service, array($cfg, 'recipient@example.test', $message['subject'], $message['body'], $message['html']));
$boundary = pre_multipart($wire, $message['body'], $message['html']);
list($headers) = pre_split_message($wire);
pre_ok(strpos($headers, 'From: =?UTF-8?B?'.base64_encode($cfg['from_name']).'?= <sender@example.test>') !== false && strpos($headers, 'To: <recipient@example.test>') !== false, 'MIME 发件人与测试收件人正确');
preg_match('/(?:^|\r\n)Subject: ([^\r\n]*(?:\r\n[ \t][^\r\n]*)*)/', $headers, $subjectHeader);
$subjectWords = isset($subjectHeader[1]) ? preg_replace('/\r\n[ \t]+/', ' ', $subjectHeader[1]) : '';
$subjectValid = preg_match('/\A=\?UTF-8\?B\?[A-Za-z0-9+\/=]+\?=(?:[ \t]+=\?UTF-8\?B\?[A-Za-z0-9+\/=]+\?=)*\z/D', $subjectWords) === 1;
$decodedSubject = '';
preg_match_all('/=\?UTF-8\?B\?([A-Za-z0-9+\/=]+)\?=/', $subjectWords, $encodedSubjects, PREG_SET_ORDER);
foreach ($encodedSubjects as $word) {
    $decodedWord = base64_decode($word[1], true);
    if (strlen($word[0]) > 75 || $decodedWord === false || preg_match('//u', $decodedWord) !== 1) $subjectValid = false;
    $decodedSubject .= $decodedWord === false ? '' : $decodedWord;
}
pre_ok($subjectValid && $decodedSubject === $message['subject'], 'MIME 主题使用合法 UTF-8 base64 分段且解码保真');
$boundaries = array($boundary);
for ($i = 0; $i < 4; $i++) {
    $again = $reflection->invokeArgs($service, array($cfg, 'recipient@example.test', $message['subject'], $message['body'], $message['html']));
    $boundaries[] = pre_multipart($again, $message['body'], $message['html']);
}
pre_ok(count(array_unique($boundaries)) === count($boundaries), '相同输入的五封邮件使用不同随机 boundary');

// 混合换行和行首点在纯文本旧分支及 multipart 中分别校验。
$dotBody = ".首行\n..已有点\r\n普通.中间\r.独立回车\n.\n末行";
$dotExpected = "..首行\r\n...已有点\r\n普通.中间\r\n..独立回车\r\n..\r\n末行";
// message 的三段邮件内容参数对应反射调用中的 cfg 加三个业务参数。
$plainWire = $reflection->invokeArgs($service, array($cfg, 'recipient@example.test', '旧文本测试', $dotBody));
list($plainHeaders, $plainContent) = pre_split_message($plainWire);
pre_ok(strpos($plainHeaders, 'Content-Type: text/plain; charset=UTF-8') !== false && strpos($plainHeaders, 'Content-Transfer-Encoding: 8bit') !== false, '旧三参邮件保持 text/plain 8bit');
pre_ok(strpos($plainWire, 'multipart/') === false && strpos($plainWire, 'boundary=') === false, '旧三参邮件不引入 multipart');
pre_ok($plainContent === $dotExpected, '旧纯文本统一 CRLF 并对每个行首点只额外转义一次');
pre_ok(preg_match('/(?<!\r)\n|\r(?!\n)/', $plainWire) === 0, '旧纯文本线格式仅使用 CRLF');
foreach (array(null, '', array('invalid')) as $index => $emptyHtml) {
    $fallbackWire = $reflection->invokeArgs($service, array($cfg, 'recipient@example.test', '旧文本测试', $dotBody, $emptyHtml));
    pre_ok($fallbackWire === $plainWire, '空或非字符串 HTML 保持原纯文本格式 #'.$index);
}
$longDotBody = str_repeat($dotBody."\n", 12);
$dotWire = $reflection->invokeArgs($service, array($cfg, 'recipient@example.test', '点转义测试', $longDotBody, $message['html']));
pre_multipart($dotWire, $longDotBody, $message['html']);
echo 'PASSWORD RESET EMAIL COMPLETE ('.$preAssertions.' assertions)'.PHP_EOL;
