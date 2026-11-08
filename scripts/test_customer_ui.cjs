// 仅访问本地 8081；订单使用 CLI 隔离夹具，不写业务数据库或调用付款渠道。
const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const output = path.join(root, 'storage', 'ui-review');
const base = 'http://127.0.0.1:8081';
const results = [];
let desktopHeaderReference = null;
const fixtures = [['orders', '/orders'], ['subscriptions', '/account/subscriptions'], ['wallet', '/wallet'], ['messages', '/notifications'], ['tickets', '/tickets'], ['feedback', '/feedback'], ['settings', '/account/security']].map(([name, url]) => ({ name, url, html: execFileSync('php', [path.join(__dirname, `test_${name}_page.php`), '--html'], { encoding: 'utf8' }) }));
fixtures.push({ name: 'overview', url: '/account/overview', html: execFileSync('php', [path.join(__dirname, 'test_account_overview.php'), '--html'], { encoding: 'utf8' }) });
fixtures.push({ name: 'orders-empty', url: '/orders?status=cancelled', html: execFileSync('php', [path.join(__dirname, 'test_orders_page.php'), '--empty-html'], { encoding: 'utf8' }) });
const orderHtml = execFileSync('php', [path.join(__dirname, 'test_order_detail_page.php'), '--html'], { encoding: 'utf8' });
const checkoutHtml = execFileSync('php', [path.join(__dirname, 'test_checkout_page.php'), '--html'], { encoding: 'utf8' });
const paymentHtml = execFileSync('php', [path.join(__dirname, 'test_order_detail_page.php'), '--payment-html'], { encoding: 'utf8' });
async function inspect(page, name, fixture = false) {
  console.log(`检查 ${name}${fixture ? '（隔离数据）' : ''}`);
  const geometry = await page.evaluate(() => ({
    width: innerWidth, scrollWidth: document.documentElement.scrollWidth,
    background: getComputedStyle(document.body).backgroundColor,
    overflow: [...document.querySelectorAll('body *')].filter(e => {
      const r = e.getBoundingClientRect(), s = getComputedStyle(e);
      return r.width && (r.right > innerWidth + 1 || r.left < -1) && s.position !== 'fixed' && s.visibility !== 'hidden' && !e.closest('[hidden]');
    }).slice(0, 8).map(e => e.className || e.tagName)
  }));
  results.push({ name, fixture, url: page.url(), ...geometry });
  assert(geometry.scrollWidth <= geometry.width + 1, `${name} 横向溢出 ${geometry.scrollWidth}/${geometry.width}`);
  for (const crumb of await page.locator('.ep-crumb').all()) {
    const style = await crumb.evaluate(e => {
      const s = getComputedStyle(e);
      return { background: s.backgroundColor, shadow: s.boxShadow, backdrop: s.backdropFilter, border: s.borderTopWidth };
    });
    assert.deepEqual(style, { background: 'rgba(0, 0, 0, 0)', shadow: 'none', backdrop: 'none', border: '0px' }, `${name} 面包屑不能残留白底导航样式`);
  }
  for (const footer of await page.locator('.ep-footer,.ep-footer nav').all()) {
    const style = await footer.evaluate(e => {
      const s = getComputedStyle(e);
      return { background: s.backgroundColor, shadow: s.boxShadow, backdrop: s.backdropFilter };
    });
    assert.deepEqual(style, { background: 'rgba(0, 0, 0, 0)', shadow: 'none', backdrop: 'none' }, `${name} 页脚不能残留白底或玻璃导航样式`);
  }
  const headerNav = page.locator('.home-nav,.ao-main-nav,.auth-header>nav[aria-label="主导航"]');
  if (await headerNav.count()) {
    assert.equal(await headerNav.isVisible(), geometry.width > 800, `${name} 顶部导航响应式一致`);
    assert.deepEqual(await headerNav.locator('a').allTextContents(), ['首页', 'VPN 订阅', '海外账号', '活动', '软件下载', '帮助中心'], `${name} 顶部导航项目一致`);
    if (geometry.width === 1440) {
      const style = await headerNav.evaluate(e => {
        const s = getComputedStyle(e), a = getComputedStyle(e.querySelector('a'));
        return { background: s.backgroundColor, border: s.border, radius: s.borderRadius, padding: s.padding, gap: s.gap, fontSize: a.fontSize, fontWeight: a.fontWeight, linkHeight: a.minHeight };
      });
      if (!desktopHeaderReference) desktopHeaderReference = style;
      assert.deepEqual(style, desktopHeaderReference, `${name} 顶部胶囊样式应与首页相同`);
    }
  }
  const nav = page.locator('.public-mobile-nav');
  const accountPage = await page.locator('body.account-overview-editorial').count();
  if (accountPage) assert.equal(await nav.count(), 1, `${name} 账户页应有快捷导航`);
  if (await nav.count()) {
    assert.deepEqual(await nav.locator('a').allTextContents(), ['首页', '套餐商店', '活动', '海外账号', '个人中心']);
    assert.equal(await nav.locator('a').nth(2).getAttribute('href'), '/events');
    const purchaseBar = await page.locator('.od-mobile-pay,.ed-mobile-action').count();
    assert.equal(await nav.isVisible(), geometry.width <= 720 && !purchaseBar, `${name} 底部导航显示条件`);
    if (accountPage) assert.equal(await nav.locator('[aria-current="page"]').getAttribute('href'), '/account/overview');
    if (await nav.isVisible()) {
      const bounds = await nav.boundingBox();
      const viewportHeight = await page.evaluate(() => innerHeight);
      assert(bounds.x >= 16 && bounds.x + bounds.width <= geometry.width - 15, `${name} 悬浮菜单两侧保留间距`);
      assert(Math.abs(viewportHeight - bounds.y - bounds.height - 12) <= 1, `${name} 悬浮菜单距视口底部12px`);
      assert.equal(await nav.evaluate(e => getComputedStyle(e).borderRadius), '99px', '悬浮菜单保持胶囊圆角');
      assert(await page.locator('body').evaluate(e => parseFloat(getComputedStyle(e).paddingBottom)) >= bounds.height + 28, `${name} 页面底部预留悬浮菜单空间`);
    }
  }
  const mobileHeader = page.locator('.home-topnav,.ao-header,.auth-header');
  if (geometry.width <= 800 && await mobileHeader.count()) {
    const initialScroll = await page.evaluate(() => scrollY);
    await page.evaluate(() => scrollTo({ top: 600, behavior: 'instant' }));
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    if (await page.evaluate(() => scrollY > 100)) {
      const bounds = await mobileHeader.boundingBox();
      assert(bounds && Math.abs(bounds.y) <= 1, `${name} 下滑后顶部栏应保持吸顶`);
      if (name === 'orders-390' || name === 'home-390') {
        const account = name === 'orders-390';
        await page.locator(account ? '.ao-menu-toggle' : '.home-nav-toggle').click();
        const menu = page.locator(account ? '.ao-sidebar' : '.home-nav-drawer');
        assert(await menu.isVisible(), '下滑后顶部菜单仍可展开');
        const menuBounds = await menu.boundingBox();
        assert(menuBounds.y >= bounds.height && menuBounds.y + menuBounds.height <= (await page.evaluate(() => innerHeight)) + 1, '下滑后展开菜单仍在视口内');
        await page.keyboard.press('Escape');
        assert(await menu.isHidden(), 'Escape关闭下滑后展开的菜单');
      }
      if (name === 'orders-390') await page.screenshot({ path: path.join(output, 'orders-sticky-header-390.png'), animations: 'disabled' });
    }
    await page.evaluate(top => scrollTo({ top, behavior: 'instant' }), initialScroll);
  }
  await page.screenshot({ path: path.join(output, `${name}.png`), fullPage: true, animations: 'disabled' });
}
(async () => {
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ headless: true, ...(process.env.CHAOXI_CHROMIUM ? { executablePath: process.env.CHAOXI_CHROMIUM } : {}) });
  try {
    const context = await browser.newContext({ permissions: ['clipboard-read', 'clipboard-write'] });
    // 禁止页面及测试夹具向本地站点之外发送任何请求。
    await context.route('**/*', route => {
      const url = new URL(route.request().url());
      return url.origin === base || ['data:', 'blob:'].includes(url.protocol) ? route.continue() : route.abort();
    });
    const page = await context.newPage();
    page.on('pageerror', error => results.push({ error: error.message }));
    for (const width of [390, 1440]) {
      await page.setViewportSize({ width, height: width === 390 ? 844 : 1000 });
      for (const [name, url] of [['home', '/'], ['products', '/products'], ['accounts', '/accounts'], ['login', '/login'], ['downloads', '/downloads'], ['help', '/articles'], ['announcements', '/announcements'], ['benefits', '/benefits'], ['events', '/events']]) {
        await page.goto(base + url); await page.waitForLoadState('networkidle');
        await inspect(page, `${name}-${width}`);
        if (name === 'downloads') {
          assert.equal(await page.locator('.dl-filters').evaluate(e => getComputedStyle(e).backgroundColor), 'rgba(0, 0, 0, 0)', '平台筛选无白色底条');
          assert.equal(await page.locator('.download-filter.is-active').evaluate(e => getComputedStyle(e).borderRadius), '99px', '选中平台使用胶囊样式');
          for (const platform of ['Windows', 'macOS', 'Android', 'Linux']) {
            await page.locator(`.download-filter[data-platform="${platform}"]`).click();
            assert.equal(await page.locator('.download-filter[aria-pressed="true"]').getAttribute('data-platform'), platform);
            assert.equal(await page.locator('.dl-card:visible').count(), 1);
            assert.equal(await page.locator('.dl-card:visible').getAttribute('data-platform'), platform);
          }
          await page.locator('.download-filter[data-platform="全部"]').click();
          assert.equal(await page.locator('.dl-card:visible').count(), 4, '全部平台恢复显示');
        }
        if (name === 'events') {
          assert.equal(await page.locator('#daily-draw').count(), 1, '活动默认只显示每日抽奖');
          assert.equal(await page.locator('#group-events,#invite-friends').count(), 0, '默认页不渲染其他活动');
          for (const [category, panel] of [['group', 'group-events'], ['referral', 'invite-friends'], ['lottery', 'daily-draw']]) {
            await page.locator(`.ev-tabs a[href="/events?category=${category}"]`).click();
            await page.waitForURL(base + '/events?category=' + category);
            await page.waitForLoadState('networkidle');
            assert.equal(await page.locator('#daily-draw,#group-events,#invite-friends').count(), 1, '每个分类只渲染一项活动');
            assert.equal(await page.locator('#' + panel).count(), 1);
            assert.equal(await page.locator('.ev-tabs [aria-current="page"]').getAttribute('href'), '/events?category=' + category);
            await inspect(page, `events-${category}-${width}`);
          }
        }
        if (name === 'accounts') {
          await page.evaluate(() => { document.body.dataset.navigationCheck = 'same-document'; });
          await page.locator(width === 390 ? '.public-mobile-nav a[href="/accounts"]' : '.home-nav a[href="/accounts"]').click();
          await page.waitForLoadState('networkidle');
          assert.equal(await page.locator('body').getAttribute('data-navigation-check'), 'same-document', '重复点击海外账号不重载闪屏');
          assert.equal(await page.locator('html').evaluate(e => getComputedStyle(e).backgroundColor), 'rgb(245, 242, 236)', '根画布保持暖色避免白帧');
        }
        if (['home', 'accounts', 'downloads', 'events'].includes(name)) {
          // 仅替换浏览器中的展示节点，不登录真实账户或修改业务会话。
          await page.evaluate(html => {
            const icon = new DOMParser().parseFromString(html, 'text/html').querySelector('.ao-bell svg');
            const bell = document.createElement('a');
            bell.className = 'home-nav-bell'; bell.href = '/notifications'; bell.setAttribute('aria-label', '消息中心');
            bell.innerHTML = icon.outerHTML + '<b>3</b>';
            const user = document.createElement('a');
            user.className = 'home-nav-user'; user.href = '/account';
            user.innerHTML = '<span>1</span><b>1922702411</b>';
            document.querySelector('.home-nav-login').replaceWith(bell, user);
          }, fixtures.find(f => f.name === 'overview').html);
          const bell = await page.locator('.home-nav-bell').evaluate(e => {
            const r = e.getBoundingClientRect(), icon = e.querySelector('svg').getBoundingClientRect();
            return { width: r.width, height: r.height, iconWidth: icon.width, iconHeight: icon.height, dx: Math.abs(r.x + r.width / 2 - icon.x - icon.width / 2), dy: Math.abs(r.y + r.height / 2 - icon.y - icon.height / 2) };
          });
          assert.equal(bell.width, 44); assert.equal(bell.height, 44);
          assert.equal(bell.iconWidth, 20); assert.equal(bell.iconHeight, 20);
          assert(bell.dx < 1 && bell.dy < 1, '通知图标位于圆形按钮正中央');
          assert(await page.locator('.home-nav-user>b').isVisible(), '登录用户昵称可见');
          if (width === 1440) assert(await page.locator('.home-nav-user>b').evaluate(e => e.scrollWidth <= e.clientWidth), '桌面端短昵称不被过早截断');
          await inspect(page, `${name}-user-header-${width}`, true);
        }
      }
      for (const fixture of fixtures) {
        await page.route(base + fixture.url, route => route.fulfill({ contentType: 'text/html; charset=utf-8', body: fixture.html }));
        await page.goto(base + fixture.url);
        await inspect(page, `${fixture.name}-${width}`, true);
        if (width === 390 && fixture.name === 'orders') {
          await page.locator('.ao-menu-toggle').click();
          assert(await page.locator('.ao-sidebar').isVisible(), '手机账户菜单可展开');
          await page.keyboard.press('Escape');
          assert(await page.locator('.ao-sidebar').isHidden(), 'Escape可关闭账户菜单');
        }
        await page.unroute(base + fixture.url);
      }
      await page.goto(base + '/products');
      const detailLink = page.locator('a[href^="/products/"]').first();
      assert(await detailLink.count(), '真实套餐列表应有商品入口');
      await detailLink.click();
      await inspect(page, `product-detail-${width}`);
      const radios = page.locator('input[name=plan_id]');
      assert(await radios.count(), '真实套餐详情存在方案选择');
      const chosen = radios.last();
      await chosen.check({ force: true });
      const chosenPlan = await chosen.inputValue();
      await page.locator('#ed-purchase-form').evaluate(form => form.requestSubmit());
      await page.waitForURL('**/login');
      await page.locator('.auth-resume-note').waitFor();
      results.push({ check: '真实套餐选择到登录保留提示', width, chosenPlan, passed: true });
      if (width === 390) {
        await page.locator('.auth-menu-toggle').click();
        assert.equal(await page.locator('.auth-menu-toggle').getAttribute('aria-expanded'), 'true');
        await page.keyboard.press('Escape');
        assert.equal(await page.locator('.auth-menu-toggle').getAttribute('aria-expanded'), 'false');
      }
      await page.route(base + '/order-confirm?product_id=1&plan_id=7', route => route.fulfill({ contentType: 'text/html; charset=utf-8', body: checkoutHtml }));
      await page.goto(base + '/order-confirm?product_id=1&plan_id=7');
      await inspect(page, `checkout-${width}`, true);
      if (width === 390) {
        const fixed = await page.locator('.purchase-mobile-action').boundingBox();
        assert(fixed && fixed.y + fixed.height <= 845, '购前确认底栏在视口安全区内');
        await page.locator('details').first().locator('summary').click();
        await page.locator('#checkout-note').fill('隔离表单验收，不发送业务订单');
        await page.locator('#checkout-note').scrollIntoViewIfNeeded();
        const note = await page.locator('#checkout-note').boundingBox();
        assert(note.y + note.height <= fixed.y, '滚动后备注表单不被底栏遮挡');
      }
      let submitted = null;
      await page.route(base + '/orders', route => { submitted = new URLSearchParams(route.request().postData()); return route.fulfill({ contentType: 'text/html', body: '<p>隔离表单已接收；未创建业务订单</p>' }); });
      await page.locator(width === 390 ? '.purchase-mobile-action button' : '.checkout-total button').click();
      await page.waitForURL(base + '/orders');
      assert.equal(submitted.get('plan_id'), '7');
      assert.equal(submitted.get('_csrf'), 'fixture-csrf');
      results.push({ check: '真实确认模板下单表单提交（拦截，未创建业务订单）', width, passed: true });
      await page.unroute(base + '/orders');
      await page.unroute(base + '/order-confirm?product_id=1&plan_id=7');
      // 默认订单 URL 渲染正式模板，数据来自内存 SQLite；不冒用真实用户会话。
      await page.route(base + '/orders/FIXTURE-VPN', route => route.fulfill({ contentType: 'text/html; charset=utf-8', body: orderHtml }));
      await page.goto(base + '/orders/FIXTURE-VPN');
      await page.waitForLoadState('networkidle');
      await inspect(page, `order-detail-${width}`, true);
      await page.locator('[data-detail-copy="subscription-link"]').click();
      const copied = await page.evaluate(() => navigator.clipboard.readText());
      assert(copied.includes('FIXTURE-FORMAL'), '隔离正式订阅复制正确');
      await page.locator('[data-detail-reveal="subscription-link"]').click();
      assert(await page.locator('#subscription-link').isVisible(), '显示链接可用');
      await page.locator('[data-detail-reveal="subscription-link"]').click();
      assert(await page.locator('#subscription-link').isHidden(), '隐藏链接可用');
      await page.unroute(base + '/orders/FIXTURE-VPN');
      await page.route(base + '/orders/FIXTURE-VPN', route => route.fulfill({ contentType: 'text/html; charset=utf-8', body: paymentHtml }));
      await page.goto(base + '/orders/FIXTURE-VPN');
      await inspect(page, `order-payment-${width}`, true);
      if (width === 390) {
        const bar = page.locator('.od-mobile-pay');
        await bar.waitFor({ state: 'visible' });
        const bounds = await bar.boundingBox();
        assert(bounds.y + bounds.height <= 845 && bounds.y >= 700, '移动付款栏固定在安全底部');
        const first = page.locator('.od-payment-radio:not(:disabled)').first();
        await first.check({ force: true });
        assert.equal(await bar.locator('button').isEnabled(), true);
      }
      await page.unroute(base + '/orders/FIXTURE-VPN');
      results.push({ check: '隔离订单复制、显隐及付款选择', width, passed: true });
    }
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto(base + '/');
    const motion = await page.locator('.editorial-hero').evaluate(e => getComputedStyle(e).animationName);
    assert.equal(motion, 'none');
    results.push({ check: '减少动态效果', passed: true });
    await context.close();
  } finally { await browser.close(); fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify(results, null, 2)); }
  console.log(JSON.stringify(results, null, 2));
})().catch(error => { console.error(error); process.exitCode = 1; });
