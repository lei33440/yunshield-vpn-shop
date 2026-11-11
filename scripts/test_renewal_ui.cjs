// 仅访问本地 8081 静态资源；所有订单页面来自内存 SQLite 夹具，不使用真实会话或资金。
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const fs = require('node:fs');
const { chromium } = require('playwright');
const root = path.resolve(__dirname, '..');
const base = 'http://127.0.0.1:8081';
const output = path.join(root, 'storage', 'ui-review');
const views = Object.fromEntries(['list', 'quote', 'detail', 'list-unpaid', 'list-processing', 'processing'].map(view => [view, execFileSync('php', [path.join(__dirname, 'test_renewal_pages.php'), '--html', `--view=${view}`], { encoding: 'utf8', maxBuffer: 4 * 1024 * 1024 })]));
(async () => {
  const browser = await chromium.launch({ headless: true, channel: process.env.PLAYWRIGHT_CHANNEL || 'msedge' });
  const results = [];
  try {
    for (const width of [1440, 390]) {
      const context = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 1000 } });
      const page = await context.newPage();
      let state = 'new';
      let mockedCreates = 0;
      const forbidden = [];
      await context.route('**/*', async route => {
        const req = route.request();
        const url = new URL(req.url());
        if (url.origin !== base) { forbidden.push(req.url()); return route.abort(); }
        if (url.pathname.startsWith('/assets/') && req.method() === 'GET') return route.continue();
        if (url.pathname === '/orders' && req.method() === 'GET') return route.fulfill({ contentType: 'text/html; charset=utf-8', body: views[state === 'new' ? 'list' : state === 'unpaid' ? 'list-unpaid' : 'list-processing'] });
        if (url.pathname === '/orders/PARENT/renew') {
          if (req.method() === 'GET') return route.fulfill({ contentType: 'text/html; charset=utf-8', body: views.quote });
          if (req.method() === 'POST') {
            const data = new URLSearchParams(req.postData());
            assert.equal(data.get('option_key'), 'plan:2');
            assert.equal(data.get('_csrf'), 'isolated-only');
            assert.match(data.get('request_key'), /^[a-f0-9]{64}$/);
            mockedCreates++;
            state = 'unpaid';
            // 只模拟创建成功后的跳转，不调用真实创建服务或数据库。
            return route.fulfill({ contentType: 'text/html; charset=utf-8', body: '<!doctype html><meta http-equiv="refresh" content="0;url=/orders/RENEWAL">' });
          }
        }
        if (url.pathname === '/orders/RENEWAL' && req.method() === 'GET') return route.fulfill({ contentType: 'text/html; charset=utf-8', body: views[state === 'processing' ? 'processing' : 'detail'] });
        forbidden.push(`${req.method()} ${req.url()}`);
        return route.abort();
      });
      async function shot(name) {
        await page.waitForLoadState('networkidle');
        const dimensions = await page.evaluate(() => ({ width: innerWidth, scrollWidth: document.documentElement.scrollWidth, background: getComputedStyle(document.body).backgroundColor }));
        assert(dimensions.scrollWidth <= width + 1, `${name} 横向溢出`);
        await page.screenshot({ path: path.join(output, `renewal-${name}-${width}.png`), fullPage: true });
        results.push({ name, width, url: page.url(), ...dimensions });
      }
      await page.goto(`${base}/orders`);
      const original = () => page.locator('article.mo-order').filter({ has: page.locator('h2', { hasText: '小云铺·畅享订阅' }) }).filter({ has: page.locator('a[href="/orders/PARENT"]') });
      assert.equal(await original().getByRole('link', { name: '查看详情', exact: true }).count(), 1);
      await shot('normal-card');
      await original().getByRole('link', { name: '续费', exact: true }).click();
      await page.waitForURL(`${base}/orders/PARENT/renew`);
      assert(await page.locator('input[value="original"]').isChecked());
      const radioWidth = await page.locator('input[value="original"]').evaluate(node => node.getBoundingClientRect().width);
      assert(radioWidth >= 16 && radioWidth <= 20, '原生单选不能被通用详情输入框样式拉宽');
      await shot('quote');
      await page.locator('input[value="plan:2"]').check();
      await page.getByRole('button', { name: '确认方案并创建续费订单' }).click();
      await page.waitForURL(`${base}/orders/RENEWAL`).catch(async error => { console.error({ url: page.url(), mockedCreates, forbidden, invalid: await page.locator('input:invalid').evaluateAll(nodes => nodes.map(node => ({name:node.name,message:node.validationMessage}))) }); throw error; });
      assert.equal(mockedCreates, 1);
      assert((await page.locator('.order-reference-summary').innerText()).includes('¥28.00'));
      assert(!(await page.locator('.order-reference-summary').innerText()).includes('2028-01-01'));
      assert.equal(await page.locator('form[action="/orders/RENEWAL/balance-pay"]').count(), 1);
      await shot('unpaid-detail');
      await page.getByRole('link', { name: '订单列表', exact: true }).click();
      await page.waitForURL(`${base}/orders`);
      await shot('unpaid-card');
      await original().getByRole('link', { name: '去付款', exact: true }).click();
      await page.waitForURL(`${base}/orders/RENEWAL`);
      // 状态推进仅切换内存 HTML，不发出付款、确认或其他资金请求。
      state = 'processing';
      await page.goto(`${base}/orders`);
      await shot('processing-card');
      await original().getByRole('link', { name: '续费处理中', exact: true }).click();
      await page.waitForURL(`${base}/orders/RENEWAL`);
      assert.equal(await page.locator('form[action="/orders/RENEWAL/renewal-refund"]').count(), 1);
      await shot('processing-detail');
      assert.deepEqual(forbidden, [], '不允许外站、付款或其他未隔离请求');
      await context.close();
    }
    fs.writeFileSync(path.join(output, 'renewal-browser-results.json'), JSON.stringify({ mock: '内存SQLite订单、身份与支付替身；真实共享外壳只替换身份读取点；POST创建仅模拟成功后的本地页面跳转；8081只读取静态资源；无真实会话、资金、业务库写入', results }, null, 2));
    console.log(JSON.stringify(results, null, 2));
    console.log('RENEWAL BROWSER TESTS PASSED');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
