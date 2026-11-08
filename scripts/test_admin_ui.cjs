// 本地 8081 验收：静态资源来自站点，业务请求全部桥接至 CLI 隔离库。
const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const output = path.join(root, 'storage/ui-review');
const base = 'http://127.0.0.1:8081';
const report = { mode: 'CLI 隔离库真实路由，非生产 HTTP 登录/重定向验收', checks: [], errors: [], screenshots: [] };
assert(process.env.CHAOXI_TEST_DSN, '必须显式指定隔离库');
function cli(request) {
  const text = execFileSync('docker', ['exec', '-i', '-e', 'CHAOXI_TEST_DSN', '-e', 'CHAOXI_TEST_USER', '-e', 'CHAOXI_TEST_PASSWORD', 'chaoxi-app-1', 'php', 'scripts/test_admin_ui_fixture.php'], { input: JSON.stringify(request), encoding: 'utf8', maxBuffer: 20 * 1024 * 1024, stdio: ['pipe', 'pipe', 'pipe'] });
  const response = JSON.parse(text);
  assert(response.status < 500, response.error || '隔离夹具失败');
  return response;
}
function pass(name, width) { report.checks.push({ name, width }); console.log(`PASS ${width}: ${name}`); }
(async () => {
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  try {
    for (const width of [1440, 1024, 390]) {
      const init = cli({ action: 'init' });
      let session = init.session;
      const denied = cli({ url: base + '/admin/orders', session: { ...session, admin_id: null } });
      assert.equal(denied.redirect, '/admin/login');
      const csrfDenied = cli({ url: base + '/admin/orders/' + init.data.orders[0] + '/confirm-payment', method: 'POST', post: '_csrf=invalid', session });
      assert.equal(csrfDenied.status, 419);
      pass('原管理员鉴权与CSRF拒绝规则保留', width);
      const context = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 1050 } });
      const page = await context.newPage();
      page.on('pageerror', error => report.errors.push(error.message));
      page.on('dialog', dialog => dialog.accept());
      await context.route('**/*', async route => {
        try {
          const request = route.request();
          const url = new URL(request.url());
          assert.equal(url.origin, base, '禁止外部请求');
          if (request.method() === 'GET' && /^\/assets\/[a-zA-Z0-9_.\/-]+\.(css|js|svg|png|jpg|ico|woff2?)$/.test(url.pathname)) return route.continue();
          assert(url.pathname.startsWith('/admin'), '禁止向真实业务站点转发');
          let post = request.postData() || '';
          if ((request.headers()['content-type'] || '').startsWith('multipart/form-data')) {
            const fields = await new Request(request.url(), { method: 'POST', headers: request.headers(), body: request.postDataBuffer() }).formData();
            assert([...fields.values()].every(value => typeof value === 'string'), '本夹具不接受上传文件');
            post = new URLSearchParams([...fields]).toString();
          }
          const response = cli({ url: request.url(), method: request.method(), post, session });
          assert(response.status < 400, `后台请求失败：${url.pathname} ${response.status}`);
          session = response.session;
          if (response.redirect) {
            assert(response.redirect.startsWith('/admin'), '重定向不能离开后台');
            const target = JSON.stringify(response.redirect).replace(/</g, '\\u003c');
            return route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: `<script>location.replace(${target})</script>` });
          }
          return route.fulfill({ status: response.status, contentType: /\/preview$/.test(url.pathname) ? 'application/json; charset=utf-8' : 'text/html; charset=utf-8', body: response.body });
        } catch (error) {
          report.errors.push(error.message);
          return route.fulfill({ status: 500, contentType: 'text/plain', body: '隔离测试失败，未访问真实业务路由。' });
        }
      });
      async function shot(name) {
        const file = `admin-${name}-${width}.png`;
        await page.screenshot({ path: path.join(output, file), fullPage: !['orders', 'menu'].includes(name), animations: 'disabled' });
        report.screenshots.push(file);
      }
      async function layout(name) {
        assert(await page.locator('.admin-main h1').count(), `${name} 必须有标题`);
        const geometry = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, width: innerWidth, background: getComputedStyle(document.body).backgroundColor }));
        assert(geometry.scroll <= geometry.width + 1, `${name} 页面横向溢出 ${geometry.scroll}/${geometry.width}`);
        assert.equal(geometry.background, 'rgb(245, 242, 237)');
        pass(`${name} 暖白公共布局且无页面横向溢出`, width);
      }
      await page.setContent('<main class="admin-main"><table id="ordinary"><tr><th>名称</th><th>状态</th></tr><tr><td>测试</td><td>草稿</td></tr></table><table id="empty"><tr><th>名称</th><th>状态</th></tr><tr><td colspan="2">暂无记录</td></tr></table><table id="partial"><tr><th>名称</th><th>金额</th><th>状态</th></tr><tr><td colspan="2">合并内容</td><td>草稿</td></tr></table><table id="rowspan"><tr><th>名称</th><th>状态</th></tr><tr><td rowspan="2">合并内容</td><td>草稿</td></tr><tr><td>草稿</td></tr></table></main>');
      await page.addScriptTag({ content: fs.readFileSync(path.join(root, 'public/assets/admin-ui.js'), 'utf8') });
      for (const id of ['ordinary', 'empty']) assert(await page.locator('#' + id).evaluate(table => table.hasAttribute('data-admin-responsive')));
      for (const id of ['partial', 'rowspan']) assert.equal(await page.locator('#' + id).evaluate(table => table.hasAttribute('data-admin-responsive')), false);
      pass('表格增强边界：普通表格与整行空态适配，局部合并列和合并行回退', width);
      await page.goto(base + '/admin');
      await layout('工作台');
      await shot('dashboard');
      await page.getByRole('button', { name: '待付款', exact: true }).click();
      assert(await page.locator('[data-order-status=pending_payment]:visible').count());
      assert.equal(await page.locator('[data-order-status=pending_delivery]:visible').count(), 0);
      await page.getByRole('button', { name: '全部', exact: true }).click();
      pass('工作台最近订单筛选保留', width);
      if (width === 390) {
        await page.getByRole('button', { name: '打开后台菜单', exact: true }).click();
        await page.locator('.admin-menu-search input').fill('订单管理');
        await shot('menu');
      } else {
        await page.locator('.admin-menu-search input').fill('订单管理');
      }
      await page.locator('.admin-sidebar a[href="/admin/orders"]').click();
      await page.waitForURL(base + '/admin/orders');
      await layout('订单列表');
      assert(await page.locator('table[data-admin-responsive]').count());
      const originalFields = ['q', 'status', 'from', 'to', 'min_amount', 'max_amount'];
      for (const name of originalFields) assert(await page.locator(`.admin-order-filter [name="${name}"]`).count());
      assert(await page.locator('form[action="/admin/orders/batch"] [name="_csrf"]').count());
      await shot('orders');
      if (width === 390) {
        await page.locator('table[data-admin-responsive] tbody tr').first().scrollIntoViewIfNeeded();
        await page.screenshot({ path: path.join(output, 'admin-orders-cards-390.png'), animations: 'disabled' });
        report.screenshots.push('admin-orders-cards-390.png');
      }
      await page.locator('.admin-order-filter [name=q]').fill(init.data.orders[0]);
      await page.locator('.admin-order-filter [name=status]').selectOption('pending_payment');
      await page.locator('.admin-order-filter button').click();
      await page.waitForURL(url => url.searchParams.get('q') === init.data.orders[0]);
      assert.equal(await page.locator('input[name="order_ids[]"]').count(), 1);
      await page.getByRole('link', { name: init.data.orders[0], exact: true }).click();
      await page.waitForURL(base + '/admin/orders/' + init.data.orders[0]);
      await layout('订单详情');
      await shot('order-detail');
      pass('正常菜单入口、订单服务端搜索和状态筛选、详情跳转', width);
      if (process.argv.includes('--smoke')) { await context.close(); continue; }
      await page.getByRole('button', { name: '确认已付款', exact: true }).click();
      await page.getByRole('button', { name: '保存并发货', exact: true }).waitFor();
      pass('原确认付款流程进入待交付', width);
      const delivery = page.locator('.delivery-create-form');
      await delivery.locator('[name=subscription]').fill('https://example.invalid/isolated-ui');
      await delivery.locator('[data-duration-preset="1y"]').click();
      assert(await delivery.locator('[name=expires_at]').inputValue());
      await delivery.getByRole('button', { name: '保存并发货', exact: true }).click();
      await page.locator('.delivery-edit-form').waitFor();
      pass('交付时长脚本、原表单保存和交付状态更新', width);
      await shot('delivered');
      const revert = page.locator('.delivery-revert-form');
      await revert.locator('[name=reason]').fill('隔离测试重新交付');
      await revert.getByRole('button', { name: '退回待发货', exact: true }).click();
      await page.locator('.delivery-create-form').waitFor();
      pass('已交付订单退回待发货', width);
      await page.goto(base + '/admin/orders/' + init.data.orders[2]);
      await page.locator('form[action$="/refund"] [name=reason]').fill('隔离测试拒绝退款');
      await page.getByRole('button', { name: '拒绝退款', exact: true }).click();
      await page.locator('.delivery-create-form').waitFor();
      pass('原退款拒绝流程恢复待交付', width);
      await page.goto(base + '/admin/orders?q=' + init.data.orders[4]);
      await page.locator('#check-all').check();
      await page.locator('.admin-order-batch [name=action]').selectOption('cancel');
      await page.locator('.admin-order-batch [name=reason]').fill('隔离批量取消');
      await page.locator('.admin-order-batch button').click();
      await page.waitForURL(base + '/admin/orders');
      pass('全选绑定和批量取消保留', width);
      const pages = [['/admin/products','products'],['/admin/users','users'],['/admin/coupons','coupons'],['/admin/events','events'],['/admin/tickets','tickets'],['/admin/feedback','feedback'],['/admin/content','content'],['/admin/announcements','announcements'],['/admin/finance','finance'],['/admin/logs','logs'],['/admin/settings','settings'],['/admin/promotions','promotions'],['/admin/email-templates','templates']];
      for (const [url, name] of pages) {
        await page.goto(base + url);
        await layout(name);
        if (name === 'templates') {
          await page.waitForFunction(() => document.querySelector('#email-template-status')?.textContent.includes('合成预览已更新'));
          assert((await page.locator('#email-template-frame').getAttribute('srcdoc')).length > 100);
          pass('邮件模板真实合成预览保留，不发送邮件', width);
        }
        await shot(name);
      }
      await page.goto(base + '/admin/account-platforms');
      await page.getByRole('link', { name: '新建平台', exact: true }).click();
      await page.waitForURL(base + '/admin/account-platforms/new');
      const platformName = 'UI测试平台' + init.data.product;
      await page.locator('[name=name]').fill(platformName);
      await page.getByRole('button', { name: '保存', exact: true }).click();
      await page.waitForURL(base + '/admin/account-platforms');
      assert(await page.getByText(platformName, { exact: true }).count());
      pass('平台新建表单通过原提交地址保存', width);
      await page.goto(base + '/admin/announcements');
      await page.getByRole('navigation', { name: '公告分页' }).getByRole('link', { name: '下一页', exact: false }).click();
      await page.waitForURL(base + '/admin/announcements?page=2');
      assert(await page.locator('.admin-announcement-pagination').getByText(/第 2/).count());
      pass('已有公告服务端分页与上一页入口', width);
      await page.getByRole('link', { name: '新建公告', exact: false }).click();
      await page.waitForURL(base + '/admin/announcements/new');
      const announcementTitle = 'UI保存测试' + init.data.product;
      await page.locator('[name=title]').fill(announcementTitle);
      await page.locator('[name=body]').fill('隔离库表单保存检查，仍保持草稿。');
      await page.locator('[name=starts_at]').fill('2026-11-08T12:00');
      await page.getByRole('button', { name: '保存草稿', exact: true }).click();
      await page.waitForURL(base + '/admin/announcements');
      assert(await page.getByText(announcementTitle, { exact: true }).count());
      await page.getByRole('link', { name: '编辑或预览：' + announcementTitle, exact: true }).click();
      await page.locator('.admin-announcement-edit-form').waitFor();
      await page.locator('[name=body]').fill('已通过原编辑入口保存。');
      await page.getByRole('button', { name: '保存编辑', exact: true }).click();
      await page.waitForFunction(() => document.querySelector('.admin-announcement-preview-content')?.textContent.includes('已通过原编辑入口保存。'));
      await layout('公告编辑');
      await shot('announcement-editor');
      pass('公告草稿创建、版本字段、原编辑保存与预览', width);
      await context.close();
    }
    assert.deepEqual(report.errors, []);
  } finally {
    await browser.close();
    fs.writeFileSync(path.join(output, process.argv.includes('--smoke') ? 'admin-smoke-report.json' : 'admin-ui-report.json'), JSON.stringify(report, null, 2));
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
