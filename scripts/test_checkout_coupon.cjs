// 无网络、无依赖的确认页优惠交互回归。
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const elements = {};
for (const id of ['checkout-validate-coupon', 'checkout-promotion', 'checkout-coupon-result', 'checkout-total', 'checkout-discount', 'checkout-total-label', 'checkout-mobile-total', 'checkout-mobile-label', 'checkout-original', 'checkout-product-price']) {
    elements[id] = { textContent: '', value: '', dataset: {}, handlers: {}, addEventListener(name, fn) { this.handlers[name] = fn; }, focus() { this.focused = true; } };
}
const input = elements['checkout-promotion'];
const button = elements['checkout-validate-coupon'];
elements['checkout-total'].dataset.originalCents = '1999';
input.form = { elements: { namedItem(name) { return { value: { _csrf: 'fixture-csrf', product_id: '1', plan_id: '7' }[name] }; } } };
let request;
let next;
const context = { document: { getElementById(id) { return elements[id]; } }, URLSearchParams,
    fetch(url, options) { request = { url, options }; return next(); } };
const source = fs.readFileSync(path.join(__dirname, '../public/assets/checkout-coupon.js'), 'utf8');
vm.runInNewContext(source, context);
const response = (amounts = { original_amount_cents: 1999, discount_amount_cents: 399, total_amount_cents: 1600 }) => ({ ok: true, async json() { return { ok: true, amounts, message: '试算不占用名额' }; } });
(async () => {
    await button.handlers.click();
    assert(input.focused);
    assert.equal(request, undefined);
    input.value = ' SAVE ';
    next = async () => response();
    await button.handlers.click();
    assert.equal(request.url, '/order-confirm/validate-coupon');
    assert.equal(request.options.method, 'POST');
    assert.equal(request.options.body.get('_csrf'), 'fixture-csrf');
    assert.equal(request.options.body.get('promotion_code'), 'SAVE');
    assert.equal(elements['checkout-total'].textContent, '¥16.00');
    assert.equal(elements['checkout-mobile-total'].textContent, '¥16.00');
    assert.equal(elements['checkout-discount'].textContent, '−¥3.99');
    assert.equal(elements['checkout-total-label'].textContent, '预计实付');
    console.log('PASS 验证按钮不创建订单，携带CSRF且同步桌面及手机金额');
    input.value = 'CHANGED'; input.handlers.input();
    assert.equal(elements['checkout-total'].textContent, '¥19.99');
    assert.equal(elements['checkout-mobile-total'].textContent, '¥19.99');
    assert.equal(elements['checkout-discount'].textContent, '提交后核算');
    console.log('PASS 修改优惠码立即清除过期试算');
    let resolve;
    next = () => new Promise(done => { resolve = done; });
    const pending = button.handlers.click();
    input.value = 'NEW'; input.handlers.input();
    resolve(response()); await pending;
    assert.equal(elements['checkout-total'].textContent, '¥19.99');
    assert.equal(button.disabled, false);
    console.log('PASS 旧请求迟到不会覆盖新输入');
    next = async () => ({ ok: false, async json() { return { ok: false, message: '该优惠券不适用于当前方案。' }; } });
    await button.handlers.click();
    assert.equal(elements['checkout-coupon-result'].textContent, '该优惠券不适用于当前方案。');
    assert.equal(elements['checkout-total'].textContent, '¥19.99');
    next = async () => { throw new Error('offline'); };
    await button.handlers.click();
    assert.match(elements['checkout-coupon-result'].textContent, /暂时无法验证/);
    assert.equal(button.disabled, false);
    console.log('PASS 无效优惠及网络故障保留原价并允许重试');
    next = async () => response({ original_amount_cents: 1999, discount_amount_cents: 1999, total_amount_cents: 0 });
    await button.handlers.click();
    assert.equal(elements['checkout-total'].textContent, '¥0.00');
    next = async () => response({ original_amount_cents: 1999, discount_amount_cents: 2000, total_amount_cents: -1 });
    await button.handlers.click();
    assert.equal(elements['checkout-total'].textContent, '¥19.99');
    console.log('PASS 全额抵扣显示零元，异常金额不显示');
    next = async () => response({ original_amount_cents: 2999, discount_amount_cents: 500, total_amount_cents: 2499 });
    await button.handlers.click();
    assert.equal(elements['checkout-original'].textContent, '¥29.99');
    assert.equal(elements['checkout-product-price'].textContent, '¥29.99');
    input.handlers.input();
    assert.equal(elements['checkout-total'].textContent, '¥29.99');
    console.log('PASS 服务器价格变化同步商品金额，重新编辑后不退回旧价');
    vm.runInNewContext(source, { document: { getElementById() { return null; } } });
    console.log('PASS 账号商品及无优惠输入页面不启用优惠交互');
    if (process.argv.includes('--browser')) {
        const { chromium } = require('playwright');
        const { execFileSync } = require('node:child_process');
        const html = execFileSync('php', [path.join(__dirname, 'test_checkout_page.php'), '--html'], { encoding: 'utf8' });
        const browser = await chromium.launch({ headless: true });
        try {
            for (const width of [1440, 390]) {
                const page = await browser.newPage({ viewport: { width, height: 1000 } });
                await page.route('**/*', async route => {
                    const url = new URL(route.request().url());
                    assert.equal(url.origin, 'http://127.0.0.1:8081', '禁止外部请求');
                    if (url.pathname === '/order-confirm') return route.fulfill({ contentType: 'text/html; charset=utf-8', body: html });
                    if (url.pathname === '/order-confirm/validate-coupon') {
                        const data = new URLSearchParams(route.request().postData());
                        assert.equal(data.get('_csrf'), 'fixture-csrf');
                        const valid = data.get('promotion_code') === 'SAVE';
                        return route.fulfill({ status: valid ? 200 : 422, contentType: 'application/json', body: JSON.stringify(valid ? { ok: true, amounts: { original_amount_cents: 19800, discount_amount_cents: 3960, total_amount_cents: 15840 }, message: '优惠码可用，试算不占用名额。' } : { ok: false, message: '优惠券不存在或已失效。' }) });
                    }
                    assert(url.pathname.startsWith('/assets/'), '禁止真实下单或业务写请求');
                    return route.continue();
                });
                await page.goto('http://127.0.0.1:8081/order-confirm?product_id=1&plan_id=7');
                await page.locator('#checkout-promotion').fill('SAVE');
                if (width === 390) await page.locator('#checkout-promotion').press('Enter');
                else await page.locator('#checkout-validate-coupon').click();
                await page.waitForFunction(() => document.getElementById('checkout-total').textContent === '¥158.40');
                assert.equal(await page.locator('#checkout-mobile-total').textContent(), '¥158.40');
                assert.equal(await page.locator('#checkout-discount').textContent(), '−¥39.60');
                assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), '确认页不能横向溢出');
                await page.screenshot({ path: path.join(__dirname, '../storage/ui-review', `checkout-coupon-${width}.png`), fullPage: true });
                await page.locator('#checkout-promotion').fill('INVALID');
                assert.equal(await page.locator('#checkout-total').textContent(), '¥198.00');
                await page.locator('#checkout-validate-coupon').click();
                await page.waitForFunction(() => document.getElementById('checkout-coupon-result').textContent === '优惠券不存在或已失效。');
                console.log(`PASS ${width}px 浏览器真实确认页模板与8081资源交互（金额接口为隔离响应）`);
                await page.close();
            }
        } finally { await browser.close(); }
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
