const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('../../storage/app/ui-tools/node_modules/playwright');

const baseUrl = process.env.UI_TEST_URL || 'http://127.0.0.1:8025';
const output = path.resolve('storage/app/ui-verification');
const credentialsPath = 'storage/app/private/administrator-credentials.txt';
const localCredentials = fs.existsSync(credentialsPath) ? fs.readFileSync(credentialsPath, 'utf8') : '';
const email = process.env.UI_TEST_EMAIL || localCredentials.match(/^Email: (.+)$/m)?.[1].trim();
const password = process.env.UI_TEST_PASSWORD || localCredentials.match(/^Password: (.+)$/m)?.[1].trim();
const corrupted = /\ufffd|\u00c2\u00b7|\u00e2\u20ac[\u201d\u00a6]|\u00e2\u2020[\u2019\u2014]|\u00e2\u2014[\u00ab\u00b7]|\u00e2\u0152\u2022|\u00e2\u2030\u00a1|\u00e2\u0161\u2122/;

(async () => {
    assert(email && password, 'Provide UI_TEST_EMAIL and UI_TEST_PASSWORD or a private local credentials file.');
    fs.mkdirSync(output, { recursive: true });
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const reports = [];
    let authenticatedState;
    try {
        for (const viewport of [{ width: 1920, height: 1080 }, { width: 768, height: 1024 }, { width: 390, height: 844 }, { width: 320, height: 740 }]) {
            const context = await browser.newContext({ viewport });
            const page = await context.newPage();
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            page.on('response', response => {
                if (response.url().startsWith(baseUrl) && response.status() >= 400) {
                    errors.push(`HTTP ${response.status()}: ${new URL(response.url()).pathname}`);
                }
            });
            const login = await page.goto(`${baseUrl}/login`);
            assert.match(login.headers()['content-type'], /text\/html;\s*charset=utf-8/i);
            assert.equal(await page.evaluate(() => document.characterSet), 'UTF-8');
            assert(await page.locator('form').evaluate(form => !form.checkValidity()));
            const loginCard = await page.locator('.card').boundingBox();
            assert(loginCard.width <= 512, 'Login must retain Tabler container-tight width');
            await page.screenshot({ path: `${output}/login-${viewport.width}.png`, fullPage: true });
            if (authenticatedState) {
                await context.addCookies(authenticatedState.cookies);
                await page.goto(`${baseUrl}/dashboard`);
            } else {
                await page.locator('#email').fill(email);
                await page.locator('#password').fill(password);
                await Promise.all([
                    page.waitForURL('**/dashboard'),
                    page.getByRole('button', { name: 'Sign in', exact: true }).click(),
                ]);
                authenticatedState = await context.storageState();
            }

            const pages = [
                ['/dashboard', 'Dashboard'], ['/tests', 'Quick Test'],
                ['/prompts', 'Search Prompts'], ['/history', 'Test History'],
                ['/settings', 'AI Settings'], ['/prompts/create', 'Add prompt'],
                ['/competitors', 'Competitor Analysis'], ['/opportunities', 'Improvement Opportunities'], ['/hotels', 'Verified Business Identities'],
            ];
            const editHref = await page.goto(`${baseUrl}/prompts`).then(() => page.getByRole('link', { name: 'Edit', exact: true }).first().getAttribute('href'));
            if (editHref) pages.push([new URL(editHref).pathname, 'Edit prompt']);
            await page.goto(`${baseUrl}/history`);
            const resultHref = await page.getByRole('link', { name: 'View result', exact: true }).first().getAttribute('href').catch(() => null);
            if (resultHref) {
                pages.push([new URL(resultHref).pathname, null]);
                pages.push([new URL(resultHref).pathname + '/analysis', null]);
            }

            for (const [route, heading] of pages) {
                const response = await page.goto(baseUrl + route);
                assert.equal(response.status(), 200, route);
                assert.match(response.headers()['content-type'], /charset=utf-8/i);
                if (heading) assert.equal(await page.locator('h1').innerText(), heading);
                assert(!corrupted.test(await page.locator('body').innerText()), `Corrupted text on ${route}`);
                assert.equal(await page.locator('link[rel="stylesheet"]').count(), 2);
                assert.equal(await page.locator('script[src*="tabler.min.js"]').count(), 1);
                assert.equal(await page.locator('#sidebar-menu .nav-link-icon svg').count(), 6);
                assert.equal(await page.locator('#sidebar-menu [aria-current="page"]').count(), 1);
                const dimensions = await page.evaluate(() => ({
                    width: innerWidth,
                    scrollWidth: document.documentElement.scrollWidth,
                    contentWidth: document.querySelector('.page-body > .container-fluid').getBoundingClientRect().width,
                    sidebarWidth: document.querySelector('aside').getBoundingClientRect().width,
                    primary: getComputedStyle(document.documentElement).getPropertyValue('--tblr-primary').trim(),
                }));
                assert(dimensions.scrollWidth <= dimensions.width + 1, `Page-wide overflow on ${route} at ${viewport.width}`);
                assert.equal(dimensions.primary, '#26745d');
                if (viewport.width >= 992) {
                    assert.equal(dimensions.sidebarWidth, 256);
                    assert.equal(dimensions.contentWidth, viewport.width - 256);
                }
                await page.screenshot({ path: `${output}/${route.slice(1).replaceAll('/', '-')}-${viewport.width}.png`, fullPage: true });
                if (route === '/hotels' || route.endsWith('/analysis')) {
                    await page.locator('details').evaluateAll(elements => elements.forEach(element => { element.open = true; }));
                    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `Expanded review forms overflow on ${route} at ${viewport.width}`);
                }
                reports.push({ viewport: viewport.width, route, status: 'passed', dimensions });
            }

            await page.goto(`${baseUrl}/dashboard`);
            if (viewport.width < 992) {
                const toggle = page.getByRole('button', { name: 'Toggle sidebar navigation' });
                await toggle.click();
                await page.locator('#sidebar-menu.show').waitFor();
                assert(await page.locator('#sidebar-menu a').first().isVisible());
                assert(await page.locator('#sidebar-menu svg').first().isVisible());
                await page.locator('#sidebar-menu').getByRole('button', { name: 'Settings', exact: true }).click();
                await page.locator('#sidebar-menu').getByRole('link', { name: 'AI Settings', exact: true }).click();
                await page.waitForURL('**/settings');
            }
            await page.getByRole('button', { name: 'Open account menu' }).click();
            assert(await page.getByRole('button', { name: 'Log out', exact: true }).isVisible());
            const menu = await page.locator('[aria-labelledby="account-menu"]').boundingBox();
            assert(menu.x >= -1 && menu.x + menu.width <= viewport.width + 1, 'Account menu exceeds viewport');
            await page.keyboard.press('Escape');

            await page.goto(`${baseUrl}/prompts`);
            await page.getByRole('textbox', { name: 'Search prompts', exact: true }).fill('luxury');
            await Promise.all([page.waitForURL('**/prompts?**'), page.getByRole('button', { name: 'Filter', exact: true }).click()]);
            assert.equal(new URL(page.url()).searchParams.get('q'), 'luxury');
            await page.goto(`${baseUrl}/history`);
            await page.getByRole('combobox', { name: 'Filter by status' }).selectOption('failed');
            await Promise.all([page.waitForURL('**/history?**'), page.getByRole('button', { name: 'Filter', exact: true }).click()]);
            assert.equal(new URL(page.url()).searchParams.get('status'), 'failed');

            await page.goto(`${baseUrl}/prompts/create`);
            assert(await page.locator('main form[action$="/prompts"]').evaluate(form => !form.checkValidity()));
            await page.locator('#category').fill('UI verification only');
            await page.locator('#text').fill('Not submitted to the server');
            assert(await page.locator('main form[action$="/prompts"]').evaluate(form => form.checkValidity()));
            await page.getByRole('link', { name: 'Cancel', exact: true }).click();
            await page.waitForURL('**/prompts');

            // Exercise only the loading UI; prevent the form's network submission.
            await page.goto(`${baseUrl}/tests`);
            if (await page.getByRole('button', { name: 'Run test', exact: true }).isEnabled()) {
                await page.locator('form[data-run-test]').evaluate(form => {
                    form.addEventListener('submit', event => event.preventDefault(), { once: true });
                });
                await page.getByRole('button', { name: 'Run test', exact: true }).click();
                assert(await page.locator('button[aria-busy="true"]').isDisabled());
                assert(await page.locator('[data-progress]').isVisible());
            }
            if (viewport.width === 320) {
                await page.getByRole('button', { name: 'Open account menu' }).click();
                await Promise.all([
                    page.waitForURL('**/login'),
                    page.getByRole('button', { name: 'Log out', exact: true }).click(),
                ]);
            }
            assert.deepEqual(errors, []);
            await context.close();
        }
        fs.writeFileSync(`${output}/checks.json`, JSON.stringify(reports, null, 2));
        console.log(JSON.stringify({ result: 'passed', pageChecks: reports.length, viewports: [1920, 768, 390, 320], screenshots: output }));
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error.message); process.exit(1); });
