const { chromium } = require('../../storage/app/ui-tools/node_modules/playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const base = 'http://127.0.0.1:8026';
const output = path.resolve('storage/app/navigation-ui-verification');

(async () => {
    assert.equal(process.env.NAV_UI_ISOLATED, '1', 'Use the isolated navigation SQLite fixture and invalid API key.');
    fs.mkdirSync(output, { recursive: true });
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    let checks = 0;
    let paidSubmissions = 0;
    try {
        for (const role of ['owner', 'viewer']) {
            for (const width of [1920, 768, 390, 320]) {
                const context = await browser.newContext({ viewport: { width, height: 1000 }, reducedMotion: 'reduce' });
                const page = await context.newPage();
                const errors = [];
                page.on('pageerror', e => errors.push(e.message));
                await context.route('**/*', async route => {
                    const req = route.request();
                    if (req.method() === 'POST' && new URL(req.url()).pathname !== '/login') {
                        paidSubmissions++;
                        return route.abort();
                    }
                    return route.continue();
                });
                await page.goto(base + '/login');
                await page.locator('#email').fill(role === 'owner' ? 'ui-batches@example.test' : 'ui-viewer@example.test');
                await page.locator('#password').fill('UiValidationPassword1234!');
                await Promise.all([page.waitForURL('**/dashboard'), page.getByRole('button', { name: 'Sign in', exact: true }).click()]);
                const nav = page.locator('#sidebar-menu');
                assert.equal(await nav.locator('.nav-link-icon svg').count(), 6);
                assert.equal(await nav.locator('[aria-expanded="true"]').count(), 0);
                assert.equal(await nav.locator('a[aria-current="page"]').count(), 1);
                assert.equal(await nav.locator('a[href$="/batch-settings"]').count(), 0);
                assert.equal(await nav.locator('a[href$="/tests"]').count(), role === 'owner' ? 1 : 0);
                const openSidebar = async () => {
                    if (width < 992 && !await nav.evaluate(el => el.classList.contains('show'))) {
                        await page.getByRole('button', { name: 'Toggle sidebar navigation' }).click();
                        await page.locator('#sidebar-menu.show').waitFor();
                    }
                };
                await openSidebar();
                for (const group of ['Monitoring', 'Results', 'Improvements', 'Workspace', 'Settings']) {
                    const toggle = nav.getByRole('button', { name: group, exact: true });
                    const target = await toggle.getAttribute('aria-controls');
                    await toggle.click();
                    await page.locator(`#${target}.show`).waitFor();
                    assert.equal(await toggle.getAttribute('aria-expanded'), 'true');
                    assert(await page.locator(`#${target} a`).first().isVisible());
                    await toggle.click();
                    await page.locator(`#${target}:not(.show):not(.collapsing)`).waitFor({ state: 'attached' });
                    assert.equal(await toggle.getAttribute('aria-expanded'), 'false');
                    checks++;
                }
                const links = await nav.locator('a').evaluateAll(els => els.map(el => ({ href: el.href, label: el.textContent.trim() })));
                for (const link of links) {
                    const response = await page.goto(link.href);
                    assert.equal(response.status(), 200, link.label);
                    const pageText = await page.locator('body').innerText();
                    for (const amount of pageText.match(/\$[\d,]+(?:\.\d+)?/g) || []) {
                        assert.match(amount, /^\$[\d,]+\.\d{2}$/, `${link.label}: ${amount}`);
                    }
                    assert.equal(await nav.locator('a[aria-current="page"]').count(), 1, link.label);
                    assert.equal(await nav.locator(`a[href="${link.href}"][aria-current="page"]`).count(), 1, link.label);
                    if (link.label !== 'Dashboard') {
                        assert.equal(await nav.locator('button[aria-expanded="true"]').count(), 1, link.label);
                        assert.equal(await nav.locator('.collapse.show a[aria-current="page"]').count(), 1, link.label);
                    }
                    await openSidebar();
                    assert(await nav.locator('a[aria-current="page"]').isVisible());
                    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${role} ${link.label} overflow at ${width}`);
                    checks++;
                }
                for (const view of ['all', 'active', 'completed', 'templates']) {
                    await page.goto(`${base}/batches?view=${view}`);
                    assert.equal(await page.locator('h1').innerText(), 'Monitoring Campaigns');
                    assert.equal(await page.getByRole('link', { name: 'New Campaign', exact: true }).count() > 0, role === 'owner');
                    assert.equal(await nav.locator('#nav-monitoring.show').count(), 1);
                    checks++;
                }
                await page.goto(`${base}/batches`);
                await openSidebar();
                if (!await nav.locator('#nav-monitoring').isVisible()) await nav.getByRole('button', { name: 'Monitoring', exact: true }).click();
                assert(await page.locator('.navbar-brand a[aria-label="CiteVanta dashboard"] img').evaluate(img => img.complete && img.naturalWidth > 0));
                await page.screenshot({ path: `${output}/${role}-campaigns-${width}.png`, fullPage: false });
                await page.goto(`${base}/settings`);
                const settingsText = await page.locator('body').innerText();
                assert(settingsText.includes('gpt-6-luna'));
                assert.equal(/NANDINI_OPENAI_API_KEY|OPENAI_MODEL/.test(settingsText), role === 'owner');
                assert(!/organization spending limit/.test(settingsText));
                assert.equal(await page.locator('.card-body a[href$="/organization?section=usage"]').count(), role === 'viewer' ? 1 : 0);
                const controls = await context.request.get(`${base}/batch-settings`);
                assert.equal(controls.status(), role === 'owner' ? 200 : 403);
                if (role === 'owner') assert((await controls.text()).includes('Monthly application limit'));
                checks++;
                if (role === 'owner') {
                    await page.goto(`${base}/tests`);
                    assert.equal(await page.locator('select[name="model"]').inputValue(), 'gpt-6-luna');
                    assert(await page.getByRole('button', { name: 'Run test', exact: true }).isDisabled());
                    await page.goto(`${base}/batches/create`);
                    assert((await page.locator('body').innerText()).includes('OpenAI / gpt-6-luna'));
                    checks++;
                }
                await page.goto(`${base}/batches`);
                const campaign = page.locator('a[href]').filter({ hasText: /./ });
                const campaignUrl = (await campaign.evaluateAll(links => links.map(link => link.href))).find(href => /\/batches\/\d+$/.test(href));
                assert(campaignUrl, 'The navigation fixture must include a saved campaign.');
                await page.goto(campaignUrl);
                assert.equal(await page.locator('.page-header a[href$="/organization?section=usage"]').count(), role === 'viewer' ? 1 : 0);
                assert.equal(await page.getByRole('link', { name: 'Cost controls', exact: true }).count(), role === 'owner' ? 1 : 0);
                checks++;
                if (role === 'viewer') {
                    await page.goto(`${base}/organization?section=costs`);
                    assert.equal(await page.locator('#organization-limit').count(), 0);
                    await page.goto(`${base}/settings`);
                    assert(!/NANDINI_OPENAI_API_KEY|OPENAI_MODEL/.test(await page.locator('body').innerText()));
                    checks++;
                }
                assert.deepEqual(errors, []);
                await context.close();
            }
        }
        assert.equal(paidSubmissions, 0);
        const report = { result: 'passed', checks, roles: ['owner', 'viewer'], widths: [1920, 768, 390, 320], paidSubmissions, database: 'isolated SQLite' };
        fs.writeFileSync(`${output}/checks.json`, JSON.stringify(report, null, 2));
        console.log(JSON.stringify(report));
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error.stack); process.exit(1); });
