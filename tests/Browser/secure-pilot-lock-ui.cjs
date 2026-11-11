const { chromium } = require('../../storage/app/ui-tools/node_modules/playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const base = 'http://127.0.0.1:8026';
(async () => {
  assert.equal(process.env.SECURE_PILOT_UI, '1');
  const accounts = JSON.parse(fs.readFileSync('storage/framework/secure-pilot-ui-credentials.json', 'utf8'));
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  let externalRequests = 0, checks = 0;
  try {
    const context = await browser.newContext();
    await context.route('**/*', route => {
      if (new URL(route.request().url()).origin !== base) { externalRequests++; return route.abort(); }
      return route.continue();
    });
    const page = await context.newPage();
    await page.goto(base + '/login');
    await page.locator('#email').fill('platform-admin@example.test');
    await page.locator('#password').fill(accounts['platform-admin@example.test'].password);
    await Promise.all([page.waitForURL(/\/platform\/organizations$/), page.getByRole('button', { name: 'Sign in', exact: true }).click()]);
    await page.goto(base + '/organization');
    const token = await page.locator('input[name="_token"]').first().inputValue();
    await context.request.post(base + '/organization/switch', { form: { _token: token, organization_id: '1' } });
    for (const width of [1920, 768, 390, 320]) {
      await page.setViewportSize({ width, height: 1000 });
      assert.equal((await page.goto(base + '/tests')).status(), 200);
      assert.equal(await page.locator('#model').inputValue(), 'gpt-6-luna');
      assert((await page.locator('#provider option:checked').innerText()).includes('OpenAI'));
      assert(await page.getByRole('button', { name: 'Run test', exact: true }).isDisabled());
      assert(!(await page.locator('body').innerText()).includes('SIMULATED TEST DATA'));
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
      checks++;
    }
    const form = await page.locator('form[action$="/tests"]').evaluate(el => Object.fromEntries(new FormData(el)));
    const response = await context.request.post(base + '/tests', { form, headers: { Referer: base + '/tests' } });
    assert.equal(new URL(response.url()).pathname, '/tests');
    assert((await response.text()).includes('Monitoring is currently unavailable'));
    checks++;
    fs.mkdirSync('storage/app/secure-pilot-lock-ui-verification', { recursive: true });
    await page.screenshot({ path: 'storage/app/secure-pilot-lock-ui-verification/nandini-locked-320.png', fullPage: true });
    const report = { result: 'passed', originalWorkspace: 'organization 1 / brand 1 in disposable SQLite copy', checks, widths: [1920,768,390,320], forcedQuickTestSubmission: 'rejected by unchanged live lock', externalRequests };
    assert.equal(externalRequests, 0);
    fs.writeFileSync('storage/app/secure-pilot-lock-ui-verification/checks.json', JSON.stringify(report, null, 2));
    console.log(JSON.stringify(report));
  } finally { await browser.close(); }
})().catch(error => { console.error(error.stack); process.exit(1); });
