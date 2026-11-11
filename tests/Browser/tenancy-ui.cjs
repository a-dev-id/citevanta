const { chromium } = require('../../storage/app/ui-tools/node_modules/playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const base = 'http://127.0.0.1:8026';
const output = path.resolve('storage/app/tenancy-ui-verification');

(async () => {
    assert.equal(process.env.TENANCY_UI_ISOLATED, '1', 'Use only the isolated Phase 5 SQLite fixture server with an invalid API key.');
    fs.mkdirSync(output, { recursive: true });
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    let ownerState;
    let viewerState;
    let organizationId;
    let firstBrand;
    let secondBrand;
    let checks = 0;
    let paidSubmissions = 0;
    const stamp = Date.now();
    const customer = `Fixture Customer ${stamp}`;
    const viewerEmail = `viewer-${stamp}@example.test`;
    const viewerPassword = 'IsolatedViewerPassword1234!';
    try {
        const setup = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
        const page = await setup.newPage();
        page.on('request', request => {
            if (request.method() === 'POST' && (new URL(request.url()).pathname === '/tests' || new URL(request.url()).pathname.endsWith('/explain'))) paidSubmissions++;
        });
        const submit = async (button, expected) => Promise.all([page.waitForURL(expected), button.click()]);
        await page.goto(`${base}/login`);
        await page.locator('#email').fill('ui-batches@example.test');
        await page.locator('#password').fill('UiValidationPassword1234!');
        await submit(page.getByRole('button', { name: 'Sign in', exact: true }), '**/dashboard');
        await page.goto(`${base}/organizations/create`);
        await page.locator('#organization-name').fill(customer);
        await page.locator('#brand-new-name').fill('Aurora Coffee');
        await page.locator('#brand-new-website').fill('https://aurora.example.test/');
        await page.locator('#brand-new-industry').fill('Food and beverage');
        await page.locator('#brand-new-country').fill('US');
        await page.locator('#brand-new-aliases').fill('Aurora Coffee Roasters');
        await page.locator('[name="verified"]').check();
        await submit(page.getByRole('button', { name: 'Create organization', exact: true }), '**/organization');
        organizationId = await page.locator('#organization-switch').inputValue();
        firstBrand = await page.locator('#brand-switch').inputValue();
        assert.equal(await page.locator('#organization-limit').inputValue(), '0.00');
        await page.locator('#organization-plan').selectOption('pro');
        await submit(page.getByRole('button', { name: 'Save organization settings', exact: true }), '**/organization');
        await page.locator('summary').filter({ hasText: /^Add a monitored brand$/ }).click();
        await page.locator('#brand-new-name').fill('Aurora Bookshop');
        await page.locator('#brand-new-website').fill('https://bookshop.example.test/');
        await page.locator('#brand-new-industry').fill('Retail');
        await page.locator('#brand-new-country').fill('GB');
        await page.locator('form[action$="/organization/brands"] [name="verified"]').check();
        await submit(page.getByRole('button', { name: 'Add brand', exact: true }), '**/organization');
        secondBrand = await page.locator('#brand-switch option').filter({ hasText: 'Aurora Bookshop' }).getAttribute('value');
        await page.locator('#member-name').fill('Fixture Viewer');
        await page.locator('#member-email').fill(viewerEmail);
        await page.locator('#member-password').fill(viewerPassword);
        await submit(page.getByRole('button', { name: 'Add member', exact: true }), '**/organization');
        await page.goto(`${base}/prompts/create`);
        await page.locator('#category').fill('Coffee');
        await page.locator('#text').fill('Private coffee prompt for Aurora');
        await submit(page.getByRole('button', { name: /Create prompt|Save prompt/, exact: true }), '**/prompts');
        await page.locator('#brand-switch').selectOption(secondBrand);
        await submit(page.locator('form[action$="/brand/switch"] button'), '**/dashboard');
        await page.goto(`${base}/prompts`);
        assert(!(await page.locator('body').innerText()).includes('Private coffee prompt for Aurora'));
        await page.goto(`${base}/prompts/create`);
        await page.locator('#category').fill('Books');
        await page.locator('#text').fill('Private bookshop prompt for Aurora');
        await submit(page.getByRole('button', { name: /Create prompt|Save prompt/, exact: true }), '**/prompts');
        await page.locator('#brand-switch').selectOption(firstBrand);
        await submit(page.locator('form[action$="/brand/switch"] button'), '**/dashboard');
        await page.goto(`${base}/prompts`);
        assert((await page.locator('body').innerText()).includes('Private coffee prompt for Aurora'));
        assert(!(await page.locator('body').innerText()).includes('Private bookshop prompt for Aurora'));
        // A permitted organization switch still resets the selected brand and private prompt list.
        const legacy = await page.locator('#organization-switch option').filter({ hasText: 'Nandini Jungle' }).getAttribute('value');
        await page.locator('#organization-switch').selectOption(legacy);
        await submit(page.locator('form[action$="/organization/switch"] button'), '**/dashboard');
        await page.goto(`${base}/prompts`);
        assert(!(await page.locator('body').innerText()).includes('Private coffee prompt for Aurora'));
        await page.locator('#organization-switch').selectOption(organizationId);
        await submit(page.locator('form[action$="/organization/switch"] button'), '**/dashboard');
        ownerState = await setup.storageState();
        await setup.close();

        for (const width of [1920, 768, 390, 320]) {
            for (const role of ['owner', 'viewer']) {
                const context = await browser.newContext({ viewport: { width, height: 1000 }, reducedMotion: 'reduce', ...(role === 'owner' ? { storageState: ownerState } : viewerState ? { storageState: viewerState } : {}) });
                const page = await context.newPage();
                const errors = [];
                page.on('pageerror', error => errors.push(error.message));
                page.on('request', request => {
                    if (request.method() === 'POST' && (new URL(request.url()).pathname === '/tests' || new URL(request.url()).pathname.endsWith('/explain'))) paidSubmissions++;
                });
                if (role === 'viewer' && !viewerState) {
                    await page.goto(`${base}/login`);
                    await page.locator('#email').fill(viewerEmail);
                    await page.locator('#password').fill(viewerPassword);
                    await Promise.all([page.waitForURL('**/dashboard'), page.getByRole('button', { name: 'Sign in', exact: true }).click()]);
                    viewerState = await context.storageState();
                }
                for (const route of ['/dashboard', '/organization', '/prompts', '/history', '/improvements']) {
                    const response = await page.goto(base + route);
                    assert.equal(response.status(), 200, `${role} ${route}`);
                    assert.match(await page.title(), /CiteVanta/);
                    assert.equal(await page.locator('#organization-switch').inputValue(), organizationId);
                    assert.equal(await page.locator('#brand-switch').inputValue(), firstBrand);
                    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `Overflow ${role} ${route} at ${width}`);
                    assert(!/\ufffd|\u00e2\u20ac\u201d/.test(await page.locator('body').innerText()));
                    assert(!(await page.locator('body').innerText()).includes('Best luxury jungle resorts in Ubud Bali'));
                    if (route === '/organization') {
                        if (role === 'owner') {
                            await page.locator('details').evaluateAll(elements => elements.forEach(element => { element.open = true; }));
                            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `Expanded forms overflow at ${width}`);
                        } else {
                            assert.equal(await page.getByRole('button', { name: 'Add member', exact: true }).count(), 0);
                            assert.equal(await page.getByRole('button', { name: 'Save organization settings', exact: true }).count(), 0);
                        }
                    }
                    if (role === 'viewer') {
                        assert.equal(await page.getByRole('link', { name: 'Run AI search test', exact: true }).count(), 0);
                        assert.equal(await page.getByRole('link', { name: 'Add prompt', exact: true }).count(), 0);
                    }
                    await page.screenshot({ path: `${output}/${role}-${route.slice(1)}-${width}.png`, fullPage: true });
                    checks++;
                }
                if (role === 'viewer') {
                    assert.equal((await context.request.get(base + '/tests/1')).status(), 404);
                    assert.equal((await context.request.get(base + '/batch-settings')).status(), 403);
                }
                assert.deepEqual(errors, []);
                await context.close();
            }
        }
        assert.equal(paidSubmissions, 0);
        const report = { result: 'passed', pageChecks: checks, widths: [1920, 768, 390, 320], roles: ['owner','viewer'], workflows: ['organization creation','brand creation','member creation','organization switching','brand isolation','viewer restrictions'], paidSubmissions, database: 'isolated SQLite fixture' };
        fs.writeFileSync(`${output}/checks.json`, JSON.stringify(report, null, 2));
        console.log(JSON.stringify(report));
    } finally { await browser.close(); }
})().catch(error => { console.error(error.stack); process.exit(1); });
