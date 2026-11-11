const { chromium } = require('../../storage/app/ui-tools/node_modules/playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const base = 'http://127.0.0.1:8026';
const accounts = JSON.parse(fs.readFileSync(process.env.AUTHORIZATION_UI_CREDENTIALS || 'storage/framework/authorization-ui-credentials.json', 'utf8'));
const output = path.resolve(process.env.AUTHORIZATION_UI_OUTPUT || 'storage/app/authorization-ui-verification');
(async () => {
  assert.equal(process.env.AUTHORIZATION_AUDIT_UI, '1');
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  let checks = 0, externalRequests = 0, forbiddenRequests = 0;
  try {
    for (const email of ['platform-admin@example.test', 'free@example.test', 'starter@example.test', 'starter-admin@example.test', 'pro@example.test', 'pro-admin@example.test', 'pro-viewer@example.test', 'agency@example.test', 'agency-admin@example.test', 'agency-viewer@example.test']) {
      const context = await browser.newContext({ viewport: { width: 1920, height: 1000 } });
      await context.route('**/*', route => {
        if (new URL(route.request().url()).origin !== base) { externalRequests++; return route.abort(); }
        return route.continue();
      });
      const page = await context.newPage();
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.goto(base + '/login');
      await page.locator('#email').fill(email);
      await page.locator('#password').fill(accounts[email].password);
      await Promise.all([page.waitForURL(/\/(dashboard|platform\/organizations)$/), page.getByRole('button', { name: 'Sign in', exact: true }).click()]);
      const platform = email.startsWith('platform-');
      const viewer = email.includes('-viewer');
      const role = platform ? 'Platform Administrator' : viewer ? 'Viewer' : email.includes('-admin') ? 'Administrator' : 'Owner';
      const csrf = await page.locator('input[name="_token"]').first().inputValue();
      for (const width of [1920, 768, 390, 320]) {
        await page.setViewportSize({ width, height: 1000 });
        for (const url of ['/dashboard', '/history', '/batches', '/organization?section=brands', '/organization?section=team', '/organization?section=usage']) {
          const response = await page.goto(base + url);
          assert.equal(response.status(), 200, email + ' ' + url);
          assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), email + ' overflow ' + width + ' ' + url);
          const text = await page.locator('body').innerText();
          assert(!/->user\(\)|@(?:endif|endforeach|else)\b/.test(text));
          if (url.startsWith('/organization')) assert(text.includes('Your role: ' + role), email + ' incorrect role');
          if (viewer) {
            assert.equal(await page.locator('main form[method="post"]:not([action$="/organization/switch"]):not([action$="/brand/switch"])').count(), 0, email + ' editable workspace form');
          }
          checks++;
        }
        await page.screenshot({ path: path.join(output, email.split('@')[0] + '-' + width + '.png'), fullPage: true });
      }
      if (!platform) {
        assert.equal((await context.request.get(base + '/platform/organizations')).status(), 403);
        const denied = [['PUT', '/organization/plan', { plan_key: 'agency' }], ['PUT', '/organization', { name: 'Forbidden', plan_key: 'agency' }], ['POST', '/organization/switch', { organization_id: 1 }]];
        if (viewer) denied.push(['POST', '/prompts', {}], ['POST', '/tests', {}], ['POST', '/batches/preview', {}], ['POST', '/organization/brands', {}], ['POST', '/organization/members', {}], ['POST', '/organization/transfer', {}]);
        for (const [method, url, data] of denied) {
          const response = await context.request.fetch(base + url, { method, form: { _token: csrf, ...data } });
          assert.equal(response.status(), url === '/organization/switch' ? 404 : 403, email + ' ' + method + ' ' + url);
          forbiddenRequests++;
        }
      } else assert.equal((await context.request.get(base + '/platform/organizations')).status(), 200);
      assert.deepEqual(errors, []);
      await context.close();
      console.log('Verified permissions: ' + email);
    }
    assert.equal(externalRequests, 0);
    const report = { result: 'passed', responsivePageChecks: checks, forbiddenDirectRequests: forbiddenRequests, accounts: 10, widths: [1920,768,390,320], externalRequests, database: 'disposable SQLite copy; live execution disabled' };
    fs.writeFileSync(path.join(output, 'checks.json'), JSON.stringify(report, null, 2));
    console.log(JSON.stringify(report));
  } finally { await browser.close(); }
})().catch(error => { console.error(error.stack); process.exit(1); });
