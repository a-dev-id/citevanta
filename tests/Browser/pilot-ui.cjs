const { chromium } = require('../../storage/app/ui-tools/node_modules/playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const base = 'http://127.0.0.1:8026';
const output = path.resolve('storage/app/pilot-ui-verification');
const phase = process.env.PILOT_UI_PHASE || 'draft';

(async () => {
    assert.equal(process.env.PILOT_UI_ISOLATED, '1', 'Use only the Phase 6 isolated SQLite server with an invalid API key.');
    fs.mkdirSync(output, { recursive: true });
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    let state;
    let checks = 0;
    let paidSubmissions = 0;
    try {
        for (const width of [1920, 768, 390, 320]) {
            const context = await browser.newContext({ viewport: { width, height: 1000 }, reducedMotion: 'reduce', ...(state ? { storageState: state } : {}) });
            const page = await context.newPage();
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            page.on('request', request => {
                const pathname = new URL(request.url()).pathname;
                if (request.method() === 'POST' && (pathname === '/tests' || pathname === '/pilot/enable' || pathname.endsWith('/start') || pathname.endsWith('/explain'))) paidSubmissions++;
            });
            const verify = async name => {
                assert.match(await page.title(), /CiteVanta/);
                assert.equal(await page.locator('#sidebar-menu .nav-link-icon svg').count(), 6);
                assert.equal(await page.locator('#sidebar-menu [aria-current="page"]').count(), 1);
                assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `Overflow ${name} at ${width}`);
                assert(!/\ufffd|\u00e2\u20ac\u201d/.test(await page.locator('body').innerText()));
                await page.screenshot({ path: `${output}/${phase}-${name}-${width}.png`, fullPage: true });
                checks++;
            };
            if (!state) {
                await page.goto(`${base}/login`);
                await page.locator('#email').fill('ui-batches@example.test');
                await page.locator('#password').fill('UiValidationPassword1234!');
                await Promise.all([page.waitForURL('**/dashboard'), page.getByRole('button', { name: 'Sign in', exact: true }).click()]);
                state = await context.storageState();
            }
            const response = await page.goto(`${base}/pilot`);
            assert.equal(response.status(), 200);
            assert.match(await page.locator('body').innerText(), /approximate tool-supported country/);
            assert.equal(await page.locator('main ol > li').count(), 12);
            assert(await page.getByRole('button', { name: 'Approve budget and enable pilot', exact: true }).isDisabled());
            await verify('preview');
            if (phase === 'draft') {
                await Promise.all([page.waitForURL('**/pilot'), page.getByRole('button', { name: 'Prepare / refresh dry-run preview', exact: true }).click()]);
                assert.equal(await page.locator('main ol > li').count(), 12);
                assert((await page.locator('body').innerText()).includes('No usage observed'));
            } else {
                if (width === 1920) {
                    const reviews = page.locator('main form[action*="/pilot/results/"]');
                    assert.equal(await reviews.count(), 3);
                    const actions = [];
                    for (let index = 0; index < 3; index++) actions.push(await reviews.nth(index).getAttribute('action'));
                    for (let index = 0; index < actions.length; index++) {
                        const form = page.locator(`form[action="${actions[index]}"]`);
                        await form.locator('xpath=ancestor::details[1]').evaluate(element => { element.open = true; });
                        await form.locator('[name="decision"]').selectOption(index === 2 ? 'flagged' : 'approved');
                        await form.locator('[name="note"]').fill(index === 2 ? 'Mock result flagged for context-only evidence review.' : 'Mock original evidence and supporting citations reviewed.');
                        if (index < 2) await form.locator('[name="acknowledge"]').check();
                        await Promise.all([page.waitForURL('**/pilot'), form.getByRole('button', { name: 'Save quality review', exact: true }).click()]);
                    }
                    await Promise.all([page.waitForURL('**/pilot'), page.getByRole('button', { name: 'Generate tracked hypotheses', exact: true }).click()]);
                }
                const rates = page.locator('table').first();
                assert.equal(((await rates.innerText()).match(/50%/g) || []).length, 3);
                assert.equal(await rates.getByText('2 / 2 results', { exact: true }).count(), 0);
                assert((await rates.innerText()).includes('1 / 2 results'));
                await page.locator('main details').evaluateAll(elements => elements.forEach(element => { element.open = true; }));
                const original = page.getByRole('link', { name: /^Original test #/ }).first();
                const href = await original.getAttribute('href');
                assert(href);
                assert.equal((await context.request.get(href)).status(), 200);
                await page.locator('main details').evaluateAll(elements => elements.forEach(element => { element.open = true; }));
                await verify('expanded-quality');
            }
            const batchHref = await page.getByRole('link', { name: 'Batch details', exact: true }).getAttribute('href');
            await page.goto(batchHref);
            await verify('batch');
            assert((await page.locator('body').innerText()).includes('Controlled pilot: 12 maximum attempts'));
            assert.deepEqual(errors, []);
            await context.close();
        }
        assert.equal(paidSubmissions, 0);
        const result = { result: 'passed', phase, pageChecks: checks, widths: [1920, 768, 390, 320], paidSubmissions, database: 'isolated SQLite; report results mocked' };
        fs.writeFileSync(`${output}/${phase}-checks.json`, JSON.stringify(result, null, 2));
        console.log(JSON.stringify(result));
    } finally { await browser.close(); }
})().catch(error => { console.error(error.stack); process.exit(1); });
