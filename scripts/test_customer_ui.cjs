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
const temporaryHtml = execFileSync('php', [path.join(__dirname, 'test_order_detail_page.php'), '--temporary-html'], { encoding: 'utf8' });
async function inspect(page, name, fixture = false) {
  console.log(`检查 ${name}${fixture ? '（隔离数据）' : ''}`);
  await page.waitForLoadState('load');
  const promotionClose = page.locator('dialog.promotion-dialog[open] [data-promotion-close]');
  if (await promotionClose.count()) await promotionClose.click();
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
  if (await page.locator('body:is(.events-overview-page,.downloads-page,.help-overview-page)').count()) {
    const shellColors = await page.locator('.home-shell,.home-main-column').evaluateAll(nodes => nodes.map(node => getComputedStyle(node).backgroundColor));
    assert.deepEqual(shellColors, [geometry.background, geometry.background], `${name} 外壳应与页面底色一致，导航下方不能露出白色横条`);
  }
  for (const banner of await page.locator('.service-announcement-banner').all()) {
    const style = await banner.evaluate(e => {
      const s = getComputedStyle(e);
      return { background: s.backgroundColor, color: s.color, border: s.border, radius: s.borderRadius, padding: s.padding, gap: s.gap, shadow: s.boxShadow };
    });
    assert.deepEqual(style, { background: 'rgb(238, 230, 218)', color: 'rgb(102, 91, 81)', border: '1px solid rgb(229, 218, 207)', radius: '14px', padding: '12px 18px', gap: '12px', shadow: 'none' }, `${name} 置顶通知应统一为首页模板`);
    assert.match(await banner.getAttribute('href'), /^\/announcements\/\d+$/, '保留真实公告详情入口');
    if (geometry.width <= 600) {
      const label = await banner.locator('span').boundingBox();
      const title = await banner.locator('b').boundingBox();
      const detail = await banner.locator('small').boundingBox();
      assert(title.y >= label.y + label.height && Math.abs(title.y - detail.y) < 3, '移动端通知类型单独一行，标题与详情同行');
    }
  }
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
  if (await page.locator('.mo-search').count()) {
    const controls = await page.evaluate(() => {
      const search = document.querySelector('.mo-search'), input = search.querySelector('[name="q"]'), sort = document.querySelector('.mo-sort');
      return { searchRadius: getComputedStyle(search).borderRadius, inputBackground: getComputedStyle(input).backgroundColor, inputBorder: getComputedStyle(input).borderTopWidth, searchButtonBackground: getComputedStyle(search.querySelector('button')).backgroundColor, sortRadius: getComputedStyle(sort).borderRadius, heights: [search.querySelector('button'), sort.querySelector('select'), sort.querySelector('button')].map(e => e.getBoundingClientRect().height), sortWidth: sort.getBoundingClientRect().width, resultsWidth: document.querySelector('.mo-results-bar').getBoundingClientRect().width };
    });
    assert.equal(controls.searchRadius, '99px', '搜索使用完整胶囊外框');
    assert.equal(controls.inputBackground, 'rgba(0, 0, 0, 0)', '搜索输入框不能残留白色内底');
    assert.equal(controls.inputBorder, '0px');
    assert.equal(controls.searchButtonBackground, 'rgb(166, 63, 37)', '搜索按钮使用朱砂色');
    assert.equal(controls.sortRadius, '99px', '排序和应用按钮作为一组');
    assert(controls.heights.every(h => h >= 44), '查询与排序控件保持44px点击区域');
    if (geometry.width <= 600) assert(Math.abs(controls.sortWidth - controls.resultsWidth) <= 1, '手机排序栏铺满可用宽度');
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
        if (fixture.name === 'orders') {
          // 查询请求仅回显隔离HTML，不访问真实账户或业务订单。
          const queryRoute = base + '/orders?**';
          await page.route(queryRoute, route => route.fulfill({ contentType: 'text/html; charset=utf-8', body: fixture.html }));
          await page.locator('.mo-search input[name="q"]').fill('CX-UI-TEST');
          const searchRequest = page.waitForRequest(request => request.isNavigationRequest() && new URL(request.url()).pathname === '/orders');
          await page.locator('.mo-search button').click();
          const searchUrl = new URL((await searchRequest).url());
          assert.equal(searchUrl.searchParams.get('q'), 'CX-UI-TEST', '搜索保留订单号参数');
          await page.waitForLoadState('load');
          await page.locator('.mo-sort select').selectOption('oldest');
          const sortRequest = page.waitForRequest(request => request.isNavigationRequest() && new URL(request.url()).pathname === '/orders');
          await page.locator('.mo-sort button').click();
          assert.equal(new URL((await sortRequest).url()).searchParams.get('sort'), 'oldest', '应用按钮提交最早排序');
          await page.waitForLoadState('load');
          await page.unroute(queryRoute);
          results.push({ check: '订单号搜索与排序GET提交（隔离拦截）', width, passed: true });
        }
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
      const clientSettings = page.locator('.order-client-settings');
      assert.equal(await clientSettings.getAttribute('open'), null, '高级格式默认收起');
      await clientSettings.locator('summary').click();
      assert(await page.locator('#subscription-client').isVisible());
      assert.equal(await page.locator('#subscription-client option').count(), 11);
      await page.locator('#subscription-client').selectOption('sing-box');
      assert(await page.locator('#subscription-link').isHidden(), '切换格式不会自动泄露链接');
      const expectedClientUrl = await page.locator('#subscription-client option:checked').getAttribute('data-url');
      await page.locator('[data-detail-copy="subscription-link"]').click();
      assert.equal(await page.evaluate(() => navigator.clipboard.readText()), expectedClientUrl, '复制使用当前客户端格式');
      await inspect(page, `order-client-settings-${width}`, true);
      await clientSettings.evaluate(el => scrollTo({ top: scrollY + el.getBoundingClientRect().top - 110, behavior: 'instant' }));
      await page.screenshot({ path: path.join(output, `order-client-settings-viewport-${width}.png`), animations: 'disabled' });
      await clientSettings.locator('summary').focus();
      await page.keyboard.press('Enter');
      assert.equal(await clientSettings.getAttribute('open'), null, '高级格式可用键盘收起');
      await page.unroute(base + '/orders/FIXTURE-VPN');
      await page.route(base + '/orders/FIXTURE-VPN', route => route.fulfill({ contentType: 'text/html; charset=utf-8', body: temporaryHtml }));
      await page.goto(base + '/orders/FIXTURE-VPN');
      await inspect(page, `order-temporary-${width}`, true);
      assert(await page.locator('#temporary-subscription-link').isHidden(), '临时链接默认隐藏');
      await page.locator('.od-temporary-guide').click();
      assert.equal(new URL(page.url()).hash, '#temporary-subscription', '下滑提示指向真实临时订阅');
      await page.waitForFunction(() => {
        const top = document.querySelector('#temporary-subscription').getBoundingClientRect().top;
        const padding = parseFloat(getComputedStyle(document.documentElement).scrollPaddingTop) || 0;
        const maxScroll = Math.max(0, document.documentElement.scrollHeight - innerHeight);
        const expectedTop = Math.max(padding + 96, scrollY + top - maxScroll);
        return Math.abs(top - expectedTop) <= 2;
      });
      const temporaryBounds = await page.locator('#temporary-subscription').boundingBox();
      assert(temporaryBounds.y >= 64 && temporaryBounds.y < (await page.evaluate(() => innerHeight)), '临时订阅定位后不被吸顶导航遮挡');
      await page.screenshot({ path: path.join(output, `order-temporary-anchor-${width}.png`), animations: 'disabled' });
      await page.locator('[data-detail-copy="temporary-subscription-link"]').click();
      assert((await page.evaluate(() => navigator.clipboard.readText())).includes('FIXTURE-TEMP'), '引导后临时订阅可复制');
      results.push({ check: '临时订阅下滑定位、高级格式展开及当前链接复制', width, passed: true });
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
    for (const width of [1101, 1280, 1440, 390]) {
      await page.setViewportSize({ width, height: width === 390 ? 844 : 1000 });
      for (const [name, url] of [['login', '/login'], ['register', '/register'], ['forgot', '/password/forgot']]) {
        await page.goto(base + url);
        await page.waitForLoadState('networkidle');
        const card = page.locator('.auth-brand-card');
        if (width > 1100) {
          const layout = await card.evaluate(card => {
            const s = getComputedStyle(card), bounds = card.getBoundingClientRect();
            const rows = [...card.querySelectorAll('.auth-desktop-features li')].map(row => {
              const r = row.getBoundingClientRect();
              return { x: r.x, right: r.right, bottom: r.bottom };
            });
            return { padding: parseFloat(s.paddingLeft), overflow: s.overflow, bounds: { x: bounds.x, right: bounds.right, bottom: bounds.bottom }, rows };
          });
          assert.equal(layout.rows.length, 4, '四项账户功能完整保留');
          assert(layout.padding >= 26 && layout.overflow === 'hidden', '卡片保留内边距并裁切装饰');
          assert(layout.rows.every(row => row.x >= layout.bounds.x + 25 && row.right <= layout.bounds.right - 25), '功能卡片不贴边');
          assert(layout.bounds.bottom - layout.rows[3].bottom <= 40, '移除底部过量留白');
          assert(layout.bounds.x >= 0 && layout.bounds.right <= width, '品牌卡片完整位于视口内');
        } else assert(await card.isHidden(), '手机保留以表单为主的单列布局');
        assert(await page.locator('.auth-form-panel input[name="_csrf"]').count(), '真实表单保留CSRF字段');
        assert(await page.locator('.auth-form-panel button[type="submit"]').isVisible());
        if (name === 'login') {
          const input = page.locator('.auth-form-panel input[type="password"]');
          await page.locator('.auth-password-toggle').click();
          assert.equal(await page.locator('.auth-password-toggle').getAttribute('aria-pressed'), 'true');
          assert.equal(await input.count(), 0, '密码显示按钮正常工作');
          await page.locator('.auth-password-toggle').click();
        }
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), '认证页无横向溢出');
        await page.screenshot({ path: path.join(output, `auth-${name}-${width}.png`), fullPage: true, animations: 'disabled' });
        results.push({ check: '认证品牌卡片布局与真实表单控件', name, width, passed: true });
      }
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
