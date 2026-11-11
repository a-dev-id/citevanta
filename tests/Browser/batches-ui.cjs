const { chromium } = require('../../storage/app/ui-tools/node_modules/playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const base = process.env.BATCH_UI_TEST_URL || 'http://127.0.0.1:8026';
const output = path.resolve('storage/app/batch-ui-verification');

(async () => {
    fs.mkdirSync(output, { recursive: true });
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    let state;
    let checks = 0;
    let paidFormSubmissions = 0;
    try {
        for (const width of [1920, 768, 390, 320]) {
            const context = await browser.newContext({ viewport: { width, height: 1000 }, reducedMotion: 'reduce' });
            const page = await context.newPage();
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            page.on('request', request => {
                if (request.method() === 'POST' && new URL(request.url()).pathname === '/tests') paidFormSubmissions++;
            });
            const verify = async name => {
                assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `Overflow on ${name} at ${width}`);
                assert.equal(await page.locator('#sidebar-menu .nav-link-icon svg').count(), 6);
                assert.equal(await page.locator('#sidebar-menu [aria-current="page"]').count(), 1);
                assert(!/[\ufffd]|\u00c2\u00b7|\u00e2\u20ac\u201d/.test(await page.locator('body').innerText()));
                await page.screenshot({ path: `${output}/${name}-${width}.png`, fullPage: true });
                checks++;
            };
            if (!state) {
                await page.goto(`${base}/login`);
                await page.locator('#email').fill('ui-batches@example.test');
                await page.locator('#password').fill('UiValidationPassword1234!');
                await Promise.all([page.waitForURL('**/dashboard'), page.getByRole('button', { name: 'Sign in', exact: true }).click()]);
                state = await context.storageState();
                await page.goto(`${base}/batch-settings`);
                if (await page.locator('#opening').count()) {
                await page.locator('#opening').fill('0.02');
                await page.locator('#opening-note').fill('Isolated UI test: copied saved fixture costs reviewed with safety reserve; no new API requests.');
                await page.locator('[name="confirm_opening"]').check();
                await Promise.all([page.waitForURL('**/batch-settings'), page.getByRole('button', { name: 'Save cost controls', exact: true }).click()]);
                assert.equal(await page.locator('#opening').count(), 0);
                }
            } else {
                await context.addCookies(state.cookies);
            }
            await page.goto(`${base}/batches/create`);
            await page.locator('#batch-name').fill(`Isolated UI validation ${width}px`);
            for (const checkbox of await page.locator('input[name="prompt_ids[]"]').all()) await checkbox.check();
            for (const checkbox of await page.locator('input[name="market_ids[]"]').all()) await checkbox.check();
            await page.locator('#repetitions').fill('2');
            assert.equal((await page.locator('[data-request-count]').innerText()).trim(), '16');
            await page.locator('#repetitions').blur();
            await verify('configuration');
            await Promise.all([page.waitForURL(/\/batches\/\d+$/), page.getByRole('button', { name: 'Preview requests and cost', exact: true }).click()]);
            const batchPath = new URL(page.url()).pathname;
            assert.equal(await page.locator('tbody tr').count(), 16);
            assert(await page.getByText(/Large batch: 16 planned requests/).isVisible());
            assert(await page.locator('form[action$="/start"]').evaluate(form => !form.checkValidity()));
            await verify('priced-preview');
            await page.locator('[name="confirm_costs"]').check();
            await page.locator('[name="large_warning"]').check();
            await Promise.all([page.waitForURL(`**${batchPath}`), page.getByRole('button', { name: 'Approve batch', exact: true }).click()]);
            assert.equal((await page.locator('.card-header .badge').first().innerText()).trim(), 'Running');
            assert.equal(await page.getByRole('link', { name: 'View response', exact: true }).count(), 0);
            await verify('approved-not-executed');
            await page.getByRole('link', { name: 'Refresh status', exact: true }).click();
            assert.equal(await page.locator('tbody .badge').filter({ hasText: /^Pending$/ }).count(), 16);
            await Promise.all([page.waitForURL(`**${batchPath}`), page.getByRole('button', { name: 'Stop pending requests', exact: true }).click()]);
            assert.equal((await page.locator('.card-header .badge').first().innerText()).trim(), 'Cancelled');
            assert.equal(await page.locator('tbody .badge').filter({ hasText: /^Cancelled$/ }).count(), 16);
            await verify('cancelled');
            await page.goto(`${base}/batches`);
            await verify('batch-list');
            await page.goto(`${base}/batch-settings`);
            await verify('cost-controls');
            assert.deepEqual(errors, []);
            await context.close();
        }
        assert.equal(paidFormSubmissions, 0);
        const result = { result: 'passed', pageChecks: checks, widths: [1920, 768, 390, 320], paidFormSubmissions, processorExecuted: false, database: 'isolated SQLite test database' };
        fs.writeFileSync(`${output}/checks.json`, JSON.stringify(result, null, 2));
        console.log(JSON.stringify(result));
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error.stack); process.exit(1); });
