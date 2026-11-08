<?php
/** 仅负责展示；管理员预览须由调用路由先完成鉴权。 */
function promotion_campaign_assets()
{
    $root = dirname(__DIR__, 2) . '/public/assets/';
    $html = '';
    foreach (array('promotion-campaign.css', 'promotion-campaign.js') as $file) {
        $url = '/assets/' . $file . '?v=' . (is_file($root . $file) ? filemtime($root . $file) : '1');
        $html .= substr($file, -4) === '.css'
            ? '<link rel="stylesheet" href="' . e($url) . '">'
            : '<script src="' . e($url) . '" defer></script>';
    }
    return $html;
}

/** 接受明确的北京墙钟存储格式或 +08:00 ISO，不依赖 PHP 默认时区。 */
function promotion_campaign_iso($value)
{
    if (!is_string($value)) return '';
    $zone = new DateTimeZone('Asia/Shanghai');
    $format = 'Y-m-d H:i:s';
    if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+08:00\z/D', $value)) {
        $format = 'Y-m-d\TH:i:sP';
    }
    $date = DateTimeImmutable::createFromFormat('!' . $format, $value, $zone);
    if (!$date || $date->format($format) !== $value) return '';
    return $date->setTimezone($zone)->format('Y-m-d\TH:i:sP');
}

function promotion_campaign_modal($campaign, $preview = false)
{
    if (!is_array($campaign) || empty($campaign['id'])) return '';
    $id = (int) $campaign['id'];
    if ($id < 1) return '';
    $ends = promotion_campaign_iso(isset($campaign['ends_at']) ? $campaign['ends_at'] : '');
    if ($ends === '' && !$preview) return '';
    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d\TH:i:sP');
    $prefix = 'promotion-' . $id;
    $title = isset($campaign['title']) ? $campaign['title'] : $campaign['name'];
    $coupon = isset($campaign['coupon']) && is_array($campaign['coupon']) ? $campaign['coupon'] : array();
    $code = isset($coupon['code']) ? $coupon['code'] : '';
    $money = function ($cents) {
        $cents = max(0, (int) $cents);
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    };
    $terms = array();
    if (isset($coupon['coupon_type']) && $coupon['coupon_type'] === 'percent') {
        $percent = max(0, min(100, (int) $coupon['discount_percent']));
        $terms[] = '减免' . $percent . '%（实付' . (100 - $percent) . '%）';
    } elseif (isset($coupon['coupon_type']) && $coupon['coupon_type'] === 'fixed') {
        $terms[] = '减免 ¥' . $money(isset($coupon['amount_cents']) ? $coupon['amount_cents'] : 0);
    }
    if (!empty($coupon['min_amount_cents'])) $terms[] = '满 ¥' . $money($coupon['min_amount_cents']) . ' 可用';
    if (!empty($coupon['max_discount_cents'])) $terms[] = '最高减免 ¥' . $money($coupon['max_discount_cents']);
    $termsHtml = $terms ? '<p class="promotion-coupon-terms">' . e(implode(' · ', $terms)) . '</p>' : '';
    $plansByCategory = array();
    foreach ($campaign['plans'] as $plan) {
        $categoryId = (int) $plan['category_id'];
        if (!isset($plansByCategory[$categoryId])) $plansByCategory[$categoryId] = '';
        $plansByCategory[$categoryId] .= '<li data-promotion-modal-plan="' . (int) $plan['id'] . '">' . e($plan['product_name'] . ' / ' . $plan['plan_name']) . '</li>';
    }
    $categories = '';
    foreach (isset($campaign['categories']) && is_array($campaign['categories']) ? $campaign['categories'] : array() as $category) {
        $categoryId = (int) $category['id'];
        $intro = isset($category['description']) && $category['description'] !== '' ? '<p class="promotion-category-description">' . nl2br(e($category['description'])) . '</p>' : '';
        $plansHtml = isset($plansByCategory[$categoryId]) ? '<ul class="promotion-category-plans">' . $plansByCategory[$categoryId] . '</ul>' : '';
        $categories .= '<div class="promotion-category"><dt>' . e($category['name']) . '</dt><dd>' . $intro . $plansHtml . '</dd></div>';
    }
    if ($categories === '') $categories = '<div class="promotion-category"><dt>暂无适用分类</dt><dd>请先配置参与活动的套餐。</dd></div>';
    $timer = '';
    foreach (array('days' => '天', 'hours' => '时', 'minutes' => '分', 'seconds' => '秒') as $unit => $label) {
        $timer .= '<div class="promotion-time"><b data-promotion-unit="' . $unit . '">00</b><span>' . $label . '</span></div>';
    }
    $description = !empty($campaign['description']) ? '<p class="promotion-description" id="' . $prefix . '-description">' . nl2br(e($campaign['description'])) . '</p>' : '';
    return '<dialog class="promotion-dialog" data-promotion-id="' . $id . '" data-ends-at="' . e($ends) . '" data-server-now="' . e($now) . '" data-preview="' . ($preview ? '1' : '0') . '" aria-labelledby="' . $prefix . '-title"' . ($description !== '' ? ' aria-describedby="' . $prefix . '-description"' : '') . '>
        <div class="promotion-card">
        <svg class="promotion-globe" viewBox="0 0 300 170" fill="none" aria-hidden="true" focusable="false"><g stroke="currentColor" stroke-width=".65"><circle cx="185" cy="105" r="90"/><ellipse cx="185" cy="105" rx="64" ry="90"/><ellipse cx="185" cy="105" rx="29" ry="90"/><path d="M185 15v180M95 105h180"/><ellipse cx="185" cy="70" rx="83" ry="17"/><ellipse cx="185" cy="140" rx="83" ry="17"/><ellipse cx="185" cy="105" rx="90" ry="22"/><ellipse cx="172" cy="78" rx="139" ry="18" transform="rotate(-12 172 78)"/></g><path d="M92 24c0 9-3 12-12 12 9 0 12 3 12 12 0-9 3-12 12-12-9 0-12-3-12-12Z" fill="currentColor"/></svg>
        <button class="promotion-close" type="button" data-promotion-close aria-label="关闭活动弹窗" autofocus><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="m5 5 14 14M19 5 5 19"/></svg></button>
        <header class="promotion-heading"><span class="promotion-tag">限时活动</span><h2 class="promotion-title" id="' . $prefix . '-title">' . e($title) . '</h2><p class="promotion-subtitle">' . e(isset($campaign['subtitle']) ? $campaign['subtitle'] : '') . '</p>' . $description . $termsHtml . '</header>
        <section class="promotion-countdown" aria-label="活动结束倒计时"><h3 class="promotion-rule">距活动结束</h3><div class="promotion-time-grid" role="timer" aria-live="off">' . $timer . '</div><span class="promotion-sr-only">结束时间（北京时间）：' . e(isset($campaign['ends_at']) ? $campaign['ends_at'] : '') . '</span></section>
        <section class="promotion-categories" aria-labelledby="' . $prefix . '-categories"><h3 class="promotion-rule" id="' . $prefix . '-categories">适用套餐</h3><dl class="promotion-category-list">' . $categories . '</dl><p class="promotion-scope-note">仅限以上明确列出的套餐</p></section>
        <div class="promotion-coupon"><span class="promotion-code-label">优惠码</span><code class="promotion-code" tabindex="0" data-promotion-code>' . e($code) . '</code><button class="promotion-copy" type="button" data-promotion-copy>复制</button></div>
        <p class="promotion-copy-status" role="status" aria-live="polite" data-promotion-copy-status></p>
        <a class="promotion-cta" href="' . ($preview ? '#promotion-preview' : '/promotions/' . $id) . '"' . ($preview ? ' aria-disabled="true"' : '') . '>查看活动套餐 <span aria-hidden="true">→</span></a>
        <label class="promotion-dismiss"><input type="checkbox" data-promotion-dismiss><span>本次活动不再提示</span></label>
        </div></dialog>';
}

/** 输出只读完整预览文档，不发布、不保存草稿、不调用业务服务。 */
function promotion_campaign_preview_document($campaign, $config, $device)
{
    $device = $device === 'mobile' ? 'mobile' : 'desktop';
    $frame = isset($_GET['frame']) && is_string($_GET['frame']) && $_GET['frame'] === '1';
    $id = (int) $campaign['id'];
    $url = '/admin/promotions/' . $id . '/preview';
    $asset = dirname(__DIR__, 2) . '/public/assets/promotion-admin.css';
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: same-origin');
        header('X-Frame-Options: SAMEORIGIN');
    }
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex,nofollow"><title>' . e($campaign['name']) . ' · 活动预览</title>' . promotion_campaign_assets() . '<link rel="stylesheet" href="/assets/promotion-admin.css?v=' . e(is_file($asset) ? filemtime($asset) : '1') . '"></head><body class="promotion-preview-document">';
    if (!$frame) {
        echo '<header class="promotion-preview-toolbar"><div><strong>活动预览</strong><span>仅预览已保存内容，不会发布活动或记录用户偏好。</span></div><nav aria-label="预览设备">';
        foreach (array('desktop' => '桌面端 · 1440', 'mobile' => '移动端 · 390') as $mode => $label) {
            echo '<a href="' . e($url . '?device=' . $mode) . '"' . ($mode === $device ? ' aria-current="page"' : '') . '>' . e($label) . '</a>';
        }
        echo '<a href="' . e('/admin/promotions/' . $id . '/edit') . '">返回编辑</a></nav></header><main class="promotion-preview-viewport"><iframe class="promotion-preview-frame promotion-preview-frame--' . e($device) . '" width="' . ($device === 'mobile' ? '390' : '1440') . '" height="' . ($device === 'mobile' ? '844' : '900') . '" title="' . e($device === 'mobile' ? '移动端活动弹窗预览' : '桌面端活动弹窗预览') . '" src="' . e($url . '?device=' . $device . '&frame=1') . '"></iframe></main>';
    } else {
        $site = isset($config['app']['name']) ? $config['app']['name'] : '小云店';
        echo '<main class="promotion-preview-stage promotion-preview-stage--' . e($device) . '"><div class="promotion-preview-background" aria-hidden="true"><div class="promotion-preview-sitebar"><b>' . e($site) . '</b><span>首页　 商品分类　 会员订阅　 帮助中心</span></div><div class="promotion-preview-hero"><h1>' . e($site) . '</h1><p>优质数字商品，<br>简单安心购买。</p><span>立即选购 →</span></div><h2>热门商品</h2><div class="promotion-preview-tiles"><div></div><div></div><div></div></div></div>' . promotion_campaign_modal($campaign, true) . '</main>';
    }
    echo '</body></html>';
}
