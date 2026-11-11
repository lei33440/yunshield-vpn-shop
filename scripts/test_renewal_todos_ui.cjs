// 仅本地8081静态资源；HTML由CLI内存夹具生成，不读取真实会话、不操作业务库。
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const fs = require('node:fs');
const { chromium } = require('playwright');
const root = path.resolve(__dirname, '..');
const base = 'http://127.0.0.1:8081';
const output = path.join(root, 'storage', 'ui-review');
const render = (script, args) => execFileSync('php', [path.join(__dirname, script), '--html', ...args], { encoding: 'utf8', maxBuffer: 4 * 1024 * 1024 });
const home = render('test_renewal_todos.php', ['--home']);
const detail = render('test_renewal_pages.php', ['--view=admin']);
(async () => {
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  const results = [];
  try {
    for (const width of [1440, 390]) {
      const context = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 1000 } });
      const page = await context.newPage();
      const forbidden = [];
      const resourceErrors = [];
      page.on('pageerror', error => resourceErrors.push(error.message));
      page.on('response', response => {
        if (new URL(response.url()).pathname.startsWith('/assets/') && response.status() >= 400) resourceErrors.push(response.status() + ' ' + response.url());
      });
      page.on('requestfailed', request => resourceErrors.push(request.method() + ' ' + request.url()));
      await context.route('**/*', async route => {
        const req = route.request();
        const url = new URL(req.url());
        if (url.origin !== base || req.method() !== 'GET') { forbidden.push(req.method() + ' ' + req.url()); return route.abort(); }
        if (url.pathname.startsWith('/assets/')) return route.continue();
        let body;
        if (url.pathname === '/admin') body = home;
        else if (url.pathname === '/admin/renewals') body = render('test_renewal_todos.php', ['--query=' + url.searchParams.toString()]);
        else if (url.pathname === '/admin/orders/RENEWAL') body = detail;
        else { forbidden.push(req.url()); return route.abort(); }
        return route.fulfill({ contentType: 'text/html; charset=utf-8', body });
      });
      async function shot(name) {
        await page.waitForLoadState('networkidle');
        const dimensions = await page.evaluate(() => ({ width: innerWidth, scrollWidth: document.documentElement.scrollWidth }));
        assert(dimensions.scrollWidth <= width + 1, name + '页面不能横向溢出，表格内部可滚动');
        await page.screenshot({ path: path.join(output, `renewal-todos-${name}-${width}.png`) });
        results.push({ name, url: page.url(), ...dimensions });
      }
      await page.goto(base + '/admin');
      if (width === 390) await page.getByRole('button', { name: '打开后台菜单', exact: true }).click();
      await page.locator('.admin-nav-section').filter({ has: page.locator('summary', { hasText: '商品与订单' }) }).locator('summary').click();
      await page.locator('.admin-sidebar').getByRole('link', { name: /^续费待办/ }).click();
      await page.waitForURL(base + '/admin/renewals');
      assert.equal(await page.locator('.admin-nav-item.active[href="/admin/renewals"]').count(), 1);
      assert.equal(await page.locator('tbody tr').count(), 50);
      await shot('default');
      await page.getByRole('link', { name: '下一页', exact: true }).click();
      await page.waitForURL(/page=2/);
      await shot('page2');
      await page.locator('.admin-log-filter input[name="q"]').fill('RENEWAL');
      await page.getByRole('button', { name: '查询', exact: true }).click();
      await page.waitForURL(/q=RENEWAL/);
      assert.equal(await page.locator('tbody tr').count(), 1);
      await shot('filtered');
      await page.locator('.admin-log-results').scrollIntoViewIfNeeded();
      await shot('filtered-results');
      await page.getByRole('link', { name: '去处理', exact: true }).click();
      await page.waitForURL(base + '/admin/orders/RENEWAL');
      assert.equal(await page.locator('form[action="/admin/orders/RENEWAL/renewal-complete"]').count(), 1);
      assert.equal(await page.locator('input[name="expected_version"]').getAttribute('value'), '4');
      await shot('detail');
      await page.locator('form[action="/admin/orders/RENEWAL/renewal-complete"]').scrollIntoViewIfNeeded();
      await shot('detail-confirm');
      await page.goto(base + '/admin/renewals');
      await page.locator('select[name="status"]').selectOption('delivered');
      await page.getByRole('button', { name: '查询', exact: true }).click();
      await page.waitForURL(/status=delivered/);
      assert.equal(await page.locator('tbody tr').count(), 1);
      assert.equal(await page.getByRole('link', { name: '查看详情', exact: true }).count(), 1);
      await shot('completed');
      await page.locator('.admin-log-filter input[name="q"]').fill('NO-MATCH');
      await page.getByRole('button', { name: '查询', exact: true }).click();
      await page.waitForURL(/q=NO-MATCH/);
      assert.equal(await page.locator('tbody tr').count(), 0);
      assert.equal(await page.getByText('暂无符合条件的续费订单。', { exact: true }).count(), 1);
      await shot('empty');
      await page.getByRole('link', { name: '重置筛选', exact: true }).click();
      await page.waitForURL(base + '/admin/renewals');
      assert.equal(await page.locator('select[name="status"]').inputValue(), 'pending_delivery');
      assert.equal(await page.locator('tbody tr').count(), 50);
      assert.deepEqual(resourceErrors, [], '静态资源全部加载成功且无页面脚本异常');
      assert.deepEqual(forbidden, [], '禁止外部、POST或未隔离页面请求');
      await context.close();
    }
    fs.writeFileSync(path.join(output, 'renewal-todos-browser-results.json'), JSON.stringify({ mock: '真实后台外壳、路由与只读列表服务；SQLite内存与独立身份夹具；详情复用既有续费夹具；8081仅提供静态资源；无真实登录、业务数据、支付或上游操作', results }, null, 2));
    console.log('续费待办浏览器隔离验收通过：桌面/移动端正常导航、分页、筛选、既有详情入口。');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
