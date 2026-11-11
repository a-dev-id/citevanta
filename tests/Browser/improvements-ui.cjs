const { chromium } = require('../../storage/app/ui-tools/node_modules/playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const base = 'http://127.0.0.1:8026';
const output = path.resolve('storage/app/improvements-ui-verification');

(async () => {
    assert.equal(process.env.IMPROVEMENTS_UI_ISOLATED, '1', 'Run only against the isolated Phase 4 SQLite fixture server.');
    fs.mkdirSync(output, { recursive: true });
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    let state;
    let checks = 0;
    let paidSubmissions = 0;
    try {
        for (const width of [1920, 768, 390, 320]) {
            const context = await browser.newContext({ viewport: { width, height: 1000 }, reducedMotion: 'reduce' });
            const page = await context.newPage();
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            page.on('request', request => {
                const url = new URL(request.url());
                if (request.method() === 'POST' && (url.pathname === '/tests' || url.pathname.endsWith('/explain'))) paidSubmissions++;
            });
            page.on('response', response => {
                if (response.url().startsWith(base) && response.status() >= 400) errors.push(`HTTP ${response.status()}: ${new URL(response.url()).pathname}`);
            });
            const verify = async name => {
                assert.match(await page.title(), /CiteVanta/);
                assert(!/Nandini AI Intelligence|\ufffd|\u00e2\u20ac\u201d/.test(await page.locator('body').innerText()));
                assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `Overflow: ${name} at ${width}`);
                assert.equal(await page.evaluate(() => document.characterSet), 'UTF-8');
                await page.screenshot({ path: `${output}/${name}-${width}.png`, fullPage: true });
                checks++;
            };
            await page.goto(`${base}/login`);
            await verify('login');
            const logo = page.locator('img[alt="CiteVanta"]');
            assert(await logo.evaluate(img => img.complete && img.naturalWidth > 0));
            const favicon = await page.locator('link[rel="icon"]').getAttribute('href');
            assert.equal((await context.request.get(favicon)).status(), 200);
            if (!state) {
                await page.locator('#email').fill('ui-batches@example.test');
                await page.locator('#password').fill('UiValidationPassword1234!');
                await Promise.all([page.waitForURL('**/dashboard'), page.getByRole('button', { name: 'Sign in', exact: true }).click()]);
                state = await context.storageState();
                // This server has only a copied SQLite fixture and an invalid test API key.
                await page.goto(`${base}/batch-settings`);
                await page.locator('[name="monthly_limit"]').fill('0.00');
                await Promise.all([page.waitForURL('**/batch-settings'), page.getByRole('button', { name: 'Save cost controls', exact: true }).click()]);
                await page.goto(`${base}/improvements`);
                await Promise.all([page.waitForURL('**/improvements'), page.getByRole('button', { name: 'Generate / refresh from saved results', exact: true }).click()]);
            } else {
                await context.addCookies(state.cookies);
                await page.goto(`${base}/improvements`);
            }
            assert.equal(await page.locator('#sidebar-menu .nav-link-icon svg').count(), 6);
            await verify('improvements');
            const href = await page.locator('table tbody a').first().getAttribute('href');
            assert(href);
            await page.goto(href);
            await verify('review');
            assert(await page.getByRole('button', { name: 'Generate AI explanation', exact: true }).isDisabled());
            const evidence = page.getByRole('link', { name: /^Original test #/ }).first();
            const evidenceHref = await evidence.getAttribute('href');
            assert.equal((await context.request.get(evidenceHref)).status(), 200);
            await page.locator('details').first().evaluate(element => { element.open = true; });
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
            for (const status of ['under_review', 'in_progress', 'completed', 'new']) {
                await page.locator('#status').selectOption(status);
                await page.locator('#notes').fill(`Isolated review check at ${width}px: ${status}`);
                await page.locator('#target_url').fill('https://nandinibali.com/');
                await Promise.all([page.waitForURL(href), page.getByRole('button', { name: 'Save review', exact: true }).click()]);
                assert.equal(await page.locator('#status').inputValue(), status);
                assert.match(await page.locator('#notes').inputValue(), new RegExp(`${width}px`));
            }
            await page.goto(`${base}/dashboard`);
            await verify('dashboard');
            assert(await page.getByRole('heading', { name: 'Improvement Opportunities', exact: true }).isVisible());
            assert.deepEqual(errors, []);
            await context.close();
        }
        assert.equal(paidSubmissions, 0);
        const report = { result: 'passed', pageChecks: checks, widths: [1920, 768, 390, 320], reviewTransitions: 16, paidSubmissions, database: 'isolated SQLite fixture' };
        fs.writeFileSync(`${output}/checks.json`, JSON.stringify(report, null, 2));
        console.log(JSON.stringify(report));
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error.message); process.exit(1); });
