// 本地隔离浏览器验收：除静态资源外，禁止向真实站点转发任何请求。
// 必须设置 CHAOXI_TEST_DSN=mysql:...;dbname=chaoxi_promotion_test_...（已迁移），
// 以及 CHAOXI_TEST_USER / CHAOXI_TEST_PASSWORD；不读取站点配置或凭据文件。
const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const fixture = path.join(__dirname, 'test_promotion_ui_fixture.php');
const base = 'http://127.0.0.1:8081';
const output = path.join(root, 'storage', 'ui-review');
const report = { fixtureHomepage: true, simplifiedPublicWrapper: true, paymentChannels: false, checks: [], blocked: [], errors: [], saveDiagnostics: [] };
function cli(request, env = process.env) {
  const container = env.CHAOXI_TEST_DOCKER_CONTAINER;
  const command = container ? 'docker' : env.CHAOXI_PHP || 'php';
  const args = container ? ['exec', '-i', '-e', 'CHAOXI_TEST_DSN', '-e', 'CHAOXI_TEST_USER', '-e', 'CHAOXI_TEST_PASSWORD', container, 'php', 'scripts/test_promotion_ui_fixture.php'] : [fixture];
  let text;
  try {
    text = execFileSync(command, args, {
      input: JSON.stringify(request), encoding: 'utf8', env, maxBuffer: 16 * 1024 * 1024,
      stdio: ['pipe', 'pipe', 'pipe'], timeout: 60000,
    });
  } catch (error) {
    throw new Error(`隔离 PHP 请求失败：exit=${error.status}, signal=${error.signal || 'none'}；不输出可能含会话的原始响应。`);
  }
  let value;
  try { value = JSON.parse(text); } catch { throw new Error('Fixture returned invalid JSON (output withheld to protect credentials).'); }
  assert(value && Number.isInteger(value.status), 'Fixture JSON status required');
  return value;
}
function pass(name, width) { report.checks.push({ name, width, passed: true }); console.log(`PASS ${width || ''} ${name}`); }
function beijing(offset) {
  return new Date(Date.now() + 8 * 3600000 + offset).toISOString().slice(0, 19).replace(/:00$/, '');
}
async function capture(page, name, width) {
  await page.screenshot({ path: path.join(output, `promotion-${name}-${width}.png`), fullPage: name !== 'home-modal', animations: 'disabled' });
}
(async () => {
  assert(process.env.CHAOXI_TEST_DSN, 'Set an explicit isolated CHAOXI_TEST_DSN; no default database is allowed.');
  // 数据库守卫回归在建立业务库或远程库连接之前执行。
  for (const dsn of ['', 'mysql:host=127.0.0.1;dbname=chaoxi', 'mysql:dbname=chaoxi_promotion_test_safe;dbname=chaoxi']) {
    assert.equal(cli({ action: 'init' }, { ...process.env, CHAOXI_TEST_DSN: dsn }).status, 500);
  }
  pass('CLI DSN guard rejects missing/business/duplicate database names');
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ headless: true, ...(process.env.CHAOXI_CHROMIUM ? { executablePath: process.env.CHAOXI_CHROMIUM } : {}) });
  try {
    for (const width of [1440, 1024, 390]) {
      const init = cli({ action: 'init', uncategorized: width === 390 });
      assert.equal(init.status, 200, init.error);
      let session = init.session;
      const data = init.data;
      assert.match(init.database, /^chaoxi_promotion_test_[A-Za-z0-9_]+$/);
      const call = request => {
        const response = cli({ ...request, session });
        assert(response.status < 500, response.error || 'Fixture server failure');
        session = response.session;
        return response;
      };
      const context = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 1000 }, permissions: ['clipboard-read', 'clipboard-write'], serviceWorkers: 'block' });
      const routeErrors = [];
      const pageErrors = [];
      const requests = [];
      const confirmations = [];
      const requestPaths = [];
      context.on('page', page => {
        // 同时记录夹具处理失败的请求，但不记录正文、Cookie、会话或查询参数。
        page.on('request', request => {
          const url = new URL(request.url());
          requestPaths.push({ method: request.method(), path: url.pathname });
        });
        page.on('pageerror', error => pageErrors.push(error.message));
        page.on('dialog', async dialog => { confirmations.push(dialog.message()); await dialog.accept(); });
      });
      // 统一拦截弹出窗口、预览 iframe 和 fetch。
      // 原生 HTTP 重定向的后续请求可能绕过 Playwright 拦截；记录 PHP 的真实 302，
      // 浏览器端改用 200 HTML + location.replace 发起独立导航，再次进入夹具。
      // 这里只验证目标与会话连续性，不冒充原生 HTTP 302 行为验收。
      await context.route('**/*', async route => {
        const req = route.request();
        const url = new URL(req.url());
        try {
          if (url.origin !== base) { report.blocked.push({ url: url.origin, reason: 'external' }); await route.abort('blockedbyclient'); return; }
          if (req.method() === 'GET' && /^\/assets\/[a-zA-Z0-9_./-]+\.(?:css|js|svg|png|jpe?g|webp|ico|woff2?|ttf)$/i.test(url.pathname)) {
            await route.continue(); return;
          }
          if (/^\/(?:payment|wallet|admin\/payment)(?:\/|$)/.test(url.pathname)) {
            throw new Error('Payment or wallet route must never be requested');
          }
          const response = call({ url: req.url(), method: req.method(), post: req.postData() || '' });
          requests.push({ method: req.method(), path: url.pathname, status: response.status });
          const headers = { 'cache-control': 'no-store', 'content-type': /\/promotions\/\d+\/status$/.test(url.pathname) ? 'application/json; charset=utf-8' : 'text/html; charset=utf-8' };
          if (response.redirect) {
            assert.equal(response.status, 302, 'Only PHP 302 redirects use the navigation bridge');
            assert.equal(typeof response.redirect, 'string');
            assert(/^\/(?!\/)[^\\\x00-\x20\x7f]*$/.test(response.redirect), 'Redirect must be a relative same-site absolute path');
            assert.equal(new URL(response.redirect, base).origin, base, 'Redirect must remain on local 8081');
            const target = JSON.stringify(response.redirect).replace(/</g, '\\u003c').replace(/\u2028/g, '\\u2028').replace(/\u2029/g, '\\u2029');
            // 不向浏览器发送 Location 或 3xx，避免后续重定向绕过拦截访问业务路由。
            await route.fulfill({ status: 200, headers: { 'cache-control': 'no-store', 'content-type': 'text/html; charset=utf-8' }, body: `<!doctype html><meta charset="utf-8"><script>location.replace(${target});</script>` });
            return;
          }
          assert(response.status < 300 || response.status >= 400, 'Unbridged HTTP redirects are forbidden');
          await route.fulfill({ status: response.status, headers, body: response.body });
        } catch (error) {
          routeErrors.push(error.message);
          await route.fulfill({ status: 500, contentType: 'text/plain', body: 'Isolated fixture failure; no business route was contacted.' });
        }
      });
      try {
        const page = await context.newPage();
        // 验证外部请求被拦截，不实际发送网络请求。
        const blocked = await page.evaluate(async () => { try { await fetch('https://promotion-test.invalid/blocked'); return false; } catch { return true; } });
        assert(blocked);
        await page.goto(base + '/admin/promotions');
        await page.getByRole('link', { name: '创建优惠活动', exact: true }).click();
        await page.waitForURL(base + '/admin/promotions/new');
        assert.equal(await page.locator('[name="coupon_mode"]').inputValue(), 'existing');
        assert(await page.locator(`[name="coupon_id"] option[value="${data.coupon}"]`).count(), 'Existing public coupon is selectable through the actual form');
        pass('正常后台列表入口与已有公共券选项', width);
        const layout = await page.locator('.promotion-editor').evaluate(root => {
          const summary = root.querySelector('.promotion-current-config');
          const panels = [...root.querySelectorAll('.product-form-grid > .promotion-editor-panel')].map(node => {
            const box = node.getBoundingClientRect();
            return { x: box.x, y: box.y, right: box.right, width: box.width };
          });
          return { width: innerWidth, scroll: root.scrollWidth, client: root.clientWidth, background: getComputedStyle(summary).backgroundColor, padding: parseFloat(getComputedStyle(summary).paddingLeft), panels };
        });
        assert.equal(layout.background, 'rgb(255, 255, 255)', '当前配置必须使用白色卡片，而非裸露在页面背景上');
        assert(layout.padding >= 18 && layout.scroll <= layout.client + 1, '卡片保留内边距，编辑容器无横向溢出');
        assert(layout.panels.every(panel => panel.x >= 0 && panel.right <= width + 1), '两块表单完整位于视口内');
        if (width > 1050) assert(Math.abs(layout.panels[0].y - layout.panels[1].y) < 2 && layout.panels[1].x > layout.panels[0].x, '桌面表单两列顶部对齐');
        else assert(layout.panels[1].y > layout.panels[0].y && Math.abs(layout.panels[0].x - layout.panels[1].x) < 2, '平板和手机表单按单列排列');
        await page.screenshot({ path: path.join(output, `promotion-admin-layout-${width}.png`), animations: 'disabled' });
        pass('后台配置卡片、桌面双列和平板手机单列布局', width);

        async function saveDiagnostic(stage, mode, suffix, error = null) {
          const entry = { stage, mode, suffix, width, url: page.url(), error: error ? error.message : null };
          try {
            Object.assign(entry, await page.evaluate(() => {
              const form = document.querySelector('.promotion-admin-form form');
              return {
                formFound: Boolean(form),
                formAction: form ? form.getAttribute('action') : null,
                // 只读取 ValidityState，不触发 invalid 事件，也不绕过原生校验。
                invalidFields: form ? [...form.elements].filter(field => field.willValidate && !field.validity.valid).map(field => ({
                  name: field.name,
                  validationMessage: field.validationMessage,
                  value: field.value,
                  type: field.type,
                  step: field.getAttribute('step'),
                  min: field.getAttribute('min'),
                  max: field.getAttribute('max'),
                  hiddenGroup: Boolean(field.closest('[hidden]')),
                  collapsedDetails: Boolean(field.closest('details:not([open])')),
                })) : [],
                flashTexts: [...document.querySelectorAll('.notice,[data-fixture-flash],.alert')].map(node => node.textContent.trim()).filter(Boolean),
              };
            }));
          } catch (diagnosticError) {
            entry.diagnosticError = diagnosticError.message;
          }
          entry.requestPaths = requestPaths.map(request => ({ ...request }));
          entry.fixtureResponses = requests.map(request => ({ ...request }));
          report.saveDiagnostics.push(entry);
          // 点击前及失败时立即保存诊断，不等浏览器关闭。
          fs.writeFileSync(path.join(output, 'promotion-results.json'), JSON.stringify(report, null, 2));
          if(error || entry.invalidFields?.length) console.log(`DIAGNOSTIC ${stage} ${width} ${suffix}: ${JSON.stringify(entry)}`);
        }
        async function create(mode, suffix) {
          if (!page.url().endsWith('/admin/promotions/new')) {
            await page.goto(base + '/admin/promotions');
            await page.getByRole('link', { name: '创建优惠活动', exact: true }).click();
            await page.waitForURL(base + '/admin/promotions/new');
          }
          await page.locator('[name="name"]').fill(`${data.tag}_${suffix}`);
          await page.locator('[name="title"]').fill('双十一限时优惠');
          await page.locator('[name="subtitle"]').fill('指定 VPN 套餐享 8 折');
          await page.locator('[name="description"]').fill('');
          await page.locator('[name="starts_at"]').fill(beijing(-3600000));
          await page.locator('[name="ends_at"]').fill(beijing(86400000));
          await page.locator('[name="coupon_mode"]').selectOption(mode);
          if (mode === 'existing') await page.locator('[name="coupon_id"]').selectOption(String(data.coupon));
          else {
            await page.locator('[name="new_coupon[code]"]').fill(`SAVE${data.product}${suffix[0]}`);
            await page.locator('[name="new_coupon[paid_percent]"]').fill('80');
            assert(await page.locator('[name="coupon_id"]').isDisabled());
          }
          await page.locator('[data-promotion-category-filter]').selectOption(String(data.category));
          await page.locator(`[name="category_descriptions[${data.category}]"]`).fill('活动专属介绍：仅限明确勾选的套餐');
          await page.locator(`input[name="plan_ids[]"][value="${data.plans[0]}"]`).check();
          assert.equal(await page.locator('input[name="plan_ids[]"]:checked').count(), 1);
          assert.equal(await page.locator(`input[name="plan_ids[]"][value="${data.plans[1]}"]`).isChecked(), false);
          await capture(page, `admin-form-${suffix}`, width);
          await saveDiagnostic('before-save-click', mode, suffix);
          try {
            await page.getByRole('button', { name: '保存草稿', exact: true }).click();
            await page.waitForURL(/\/admin\/promotions\/\d+\/edit$/);
          } catch (error) {
            await saveDiagnostic('save-or-navigation-failed', mode, suffix, error);
            throw error;
          }
          const id = Number(page.url().match(/\/(\d+)\/edit$/)[1]);
          const saved = call({ action: 'inspect', id }).data.campaign;
          assert.equal(saved.status, 'draft');
          assert.equal(saved.categories.length, 1);
          assert.equal(Number(saved.categories[0].id), data.category);
          assert.equal(saved.categories[0].description, '活动专属介绍：仅限明确勾选的套餐');
          assert.equal(await page.locator(`[name="category_descriptions[${data.category}]"]`).inputValue(), saved.categories[0].description);
          assert.deepEqual(saved.plans.map(p => Number(p.id)), [Number(data.plans[0])]);
          assert.equal(Number(saved.coupon.discount_percent), 20, '80% paid means 20% discount');
          return { id, code: saved.coupon.code };
        }
        const first = await create('new', 'NEW');
        assert.equal(await page.getByRole('link', { name: /^(桌面预览|手机预览)$/ }).count(), 0);
        await page.getByRole('link', { name: '返回列表', exact: true }).click();
        await page.waitForURL(base + '/admin/promotions');
        assert.equal(await page.getByRole('link', { name: /^(桌面预览|手机预览)$/ }).count(), 0);
        await page.locator(`a[href="/admin/promotions/${first.id}/edit"]`).click();
        await page.waitForURL(`${base}/admin/promotions/${first.id}/edit`);
        assert.equal(call({ action: 'inspect', id: first.id }).data.campaign.status, 'draft');
        pass('活动列表和编辑页不再显示桌面及手机预览入口，草稿状态保留', width);
        const beforeCSRF = call({ action: 'inspect', id: first.id }).data.campaign;
        const csrf = call({ url: `${base}/admin/promotions/${first.id}/publish`, method: 'POST', post: '_csrf=invalid' });
        assert.equal(csrf.status, 419);
        assert.match(csrf.body, /请求已过期/);
        assert.equal(call({ action: 'inspect', id: first.id }).data.campaign.status, beforeCSRF.status);
        await page.getByRole('button', { name: '发布已保存配置', exact: true }).click();
        await page.waitForLoadState('load');
        assert.equal(call({ action: 'inspect', id: first.id }).data.campaign.status, 'published');
        assert(confirmations.some(text => text.includes('确认继续')));
        pass('真实 CSRF 拒绝419、确认发布与八折存储语义', width);
        await page.goto(base + '/');
        await page.locator(`dialog[data-promotion-id="${first.id}"][open]`).waitFor();
        assert.match(await page.locator('.promotion-categories').textContent(), /活动专属介绍：仅限明确勾选的套餐/);
        assert.deepEqual(await page.locator('[data-promotion-modal-plan]').evaluateAll(nodes => nodes.map(n => Number(n.dataset.promotionModalPlan))), [Number(data.plans[0])]);
        assert.match(await page.locator('[data-promotion-modal-plan]').textContent(), /订阅 \/ 入选月卡/);
        pass('保存后分类介绍与明确勾选套餐在首页正确展示（含未分类）', width);
        await page.locator('[data-promotion-copy]').click();
        await page.getByText('优惠码已复制', { exact: true }).waitFor();
        assert.equal(await page.evaluate(() => navigator.clipboard.readText()), first.code);
        await capture(page, 'home-modal', width);
        const modalBox = await page.locator('dialog[open]').boundingBox();
        assert(modalBox.x >= -1 && modalBox.x + modalBox.width <= width + 1, 'Modal fits viewport');
        await page.locator('.promotion-cta').click();
        await page.waitForURL(`${base}/promotions/${first.id}`);
        assert.deepEqual(await page.locator('[data-promotion-plan]').evaluateAll(nodes => nodes.map(n => Number(n.dataset.promotionPlan))), [Number(data.plans[0])]);
        assert.match(await page.locator('.promotion-catalog-plan').textContent(), /20\.00/);
        const newPlan = call({ action: 'add_plan' }).data.plan;
        await page.reload();
        assert.equal(await page.locator(`[data-promotion-plan="${data.plans[1]}"],[data-promotion-plan="${newPlan}"]`).count(), 0);
        await capture(page, 'catalog', width);
        pass('首页真实弹窗复制→活动方案列表，不扩展同类或新增方案', width);
        await page.getByRole('link', { name: '选择此方案', exact: true }).click();
        await page.waitForURL(/\/order-confirm\?/);
        await page.locator('[name="promotion_code"]').fill(first.code);
        await capture(page, 'checkout', width);
        await page.locator(width === 390 ? '.purchase-mobile-action button' : '.checkout-total button').click();
        await page.waitForURL(/\/orders\/CX[A-Z0-9]+$/);
        assert.equal(await page.locator('[data-order-original]').textContent(), '10000');
        assert.equal(await page.locator('[data-order-discount]').textContent(), '2000');
        assert.equal(await page.locator('[data-order-total]').textContent(), '8000');
        assert.equal(await page.locator('[data-order-status]').textContent(), 'pending_payment');
        await capture(page, 'order-receipt', width);
        const orderCount = call({ action: 'inspect', id: first.id }).data.orders.length;
        async function rejectedOrder(plan, code, label) {
          const response = call({ url: `${base}/orders`, method: 'POST', post: new URLSearchParams({ _csrf: session._csrf, product_id: String(data.product), plan_id: String(plan), promotion_code: code, request_key: require('node:crypto').randomBytes(32).toString('hex'), note: '隔离拒绝验证' }).toString() });
          assert.equal(response.status, 302);
          assert.equal(response.redirect, '/products', label);
          assert.equal(call({ action: 'inspect', id: first.id }).data.orders.length, orderCount, label);
        }
        await rejectedOrder(data.plans[1], first.code, '同类未选方案不得使用优惠');
        await rejectedOrder(newPlan, first.code, '同类新增方案不得使用优惠');
        pass('真实下单路由及OrderService核算10000−2000=8000，范围外下单回滚', width);
        // 保存旧活动 ID 的偏好，再通过已有券表单发布不同 ID 的活动。
        await page.goto(base + '/');
        await page.locator('dialog[open]').waitFor();
        await page.locator('[data-promotion-dismiss]').check();
        await page.locator('[data-promotion-close]').click();
        await page.reload();
        assert.equal(await page.locator('dialog[open]').count(), 0);
        assert.equal(await page.evaluate(id => localStorage.getItem(`promotion-campaign:dismiss:${id}`), first.id), '1');
        const second = await create('existing', 'EXISTING');
        await page.getByRole('button', { name: '发布已保存配置', exact: true }).click();
        await page.waitForLoadState('load');
        await page.goto(base + '/');
        await page.locator(`dialog[data-promotion-id="${second.id}"][open]`).waitFor();
        assert.notEqual(second.id, first.id);
        pass('已有券真实保存发布、新活动ID不继承旧活动不提示', width);
        // 弹窗保持打开，通过真实后台路由停用活动，验证状态复核后关闭。
        const adminPage = await context.newPage();
        await adminPage.goto(`${base}/admin/promotions/${second.id}/edit`);
        await adminPage.getByRole('button', { name: '停用活动', exact: true }).click();
        await adminPage.waitForLoadState('load');
        await page.bringToFront();
        await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
        await page.locator('dialog[open]').waitFor({ state: 'hidden' });
        await rejectedOrder(data.plans[0], second.code, '停用码不得创建订单');
        assert.equal(call({ url: `${base}/promotions/${second.id}`, method: 'GET' }).status, 404);
        pass('真实后台停用使已打开弹窗关闭、列表404、下单拒绝', width);
        await adminPage.close();
        const third = await create('new', 'EXPIRY');
        await page.getByRole('button', { name: '发布已保存配置', exact: true }).click();
        await page.waitForLoadState('load');
        await page.goto(base + '/');
        await page.locator(`dialog[data-promotion-id="${third.id}"][open]`).waitFor();
        call({ action: 'expire', id: third.id });
        await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
        await page.locator('dialog[open]').waitFor({ state: 'hidden' });
        await rejectedOrder(data.plans[0], third.code, '到期码不得创建订单');
        assert.equal(call({ url: `${base}/promotions/${third.id}`, method: 'GET' }).status, 404);
        await capture(page, 'expired-closed', width);
        pass('隔离库推进到期后真实status关闭弹窗、列表404、下单拒绝', width);
        const receipt = call({ action: 'inspect', id: first.id }).data.orders[0];
        assert.equal(Number(receipt.discount_amount_cents), 2000, 'Later campaign state changes do not rewrite existing order');
        assert.equal(receipt.status, 'pending_payment');
        assert(requests.some(r => r.status === 302 && r.method === 'POST'));
        assert.equal(routeErrors.length, 0, routeErrors.join('\n'));
        assert.equal(pageErrors.length, 0, pageErrors.join('\n'));
        report.checks.push({ name: 'request audit', width, database: init.database, requests, passed: true });
      } finally {
        report.errors.push(...routeErrors, ...pageErrors);
        await context.close();
      }
    }
  } finally {
    await browser.close();
    fs.writeFileSync(path.join(output, 'promotion-results.json'), JSON.stringify(report, null, 2));
  }
  console.log(`PASS ${report.checks.length} checks; screenshots and report: ${output}`);
})().catch(error => { console.error(error.message); process.exitCode = 1; });
