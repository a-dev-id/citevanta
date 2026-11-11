const { chromium } = require('../../storage/app/ui-tools/node_modules/playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const base = 'http://127.0.0.1:8026';
const output = path.resolve('storage/app/subscription-ui-verification');
(async () => {
    assert.equal(process.env.SUBSCRIPTION_UI_ISOLATED, '1');
    fs.mkdirSync(output, { recursive: true });
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    let checks=0; let apiSubmissions=0;
    try {
        for(const width of [1920,768,390,320]) {
            const context=await browser.newContext({viewport:{width,height:1000},reducedMotion:'reduce'});
            const page=await context.newPage(); const errors=[];page.on('pageerror',e=>errors.push(e.message));
            await context.route('**/*',async route=>{const r=route.request();const p=new URL(r.url()).pathname;if(r.method()==='POST'&&(p==='/tests'||p==='/pilot/enable'||p.endsWith('/start')||p.endsWith('/explain'))){apiSubmissions++;return route.abort();}return route.continue();});
            await page.goto(base+'/login');await page.locator('#email').fill('credits@example.test');await page.locator('#password').fill('UiValidationPassword1234!');await Promise.all([page.waitForURL('**/dashboard'),page.getByRole('button',{name:'Sign in',exact:true}).click()]);
            const verify=async name=>{assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${name} overflow at ${width}`);assert.equal(await page.locator('#sidebar-menu .nav-link-icon svg').count(),6);assert.equal(await page.locator('#sidebar-menu a[aria-current="page"]').count(),1);assert(!/Safety reservation|Retained commitment|Search-tool fees in estimate|Approved pilot ceiling \(USD\)/i.test(await page.locator('body').innerText()),name);checks++;};
            for(const [key,allowance,price] of [['free',5,'0.00'],['starter',450,'29.00'],['pro',1500,'99.00'],['agency',4000,'249.00']]) {
                const option=page.locator('#organization-switch option').filter({hasText:new RegExp('^Credit '+key[0].toUpperCase()+key.slice(1)+'$')});const id=await option.getAttribute('value');await page.locator('#organization-switch').selectOption(id);await Promise.all([page.waitForURL('**/dashboard'),page.locator('form[action$="/organization/switch"] button').click()]);
                await page.goto(base+'/organization?section=usage');assert.equal(await page.locator('[data-credit-allowance]').innerText(),allowance.toLocaleString('en-US'));assert.equal(await page.locator('[data-credit-used]').innerText(),'0');assert.equal(await page.locator('[data-credit-reserved]').innerText(),'0');assert.equal(await page.locator('[data-credit-remaining]').innerText(),allowance.toLocaleString('en-US'));
                const card=page.locator('.card').filter({has:page.getByRole('heading',{name:'Current subscription',exact:true})});assert((await card.innerText()).includes('$'+price+'/month'));assert((await page.locator('body').innerText()).includes('Next reset:'));assert((await page.locator('body').innerText()).includes('Not launched'));await verify(key+'-usage');await page.screenshot({path:`${output}/${key}-usage-${width}.png`,fullPage:true});
                await page.goto(base+'/organization?section=team');assert.equal(await page.getByRole('button',{name:'Add member',exact:true}).count(),key==='free'?0:1);await verify(key+'-team-capacity');
                await page.goto(base+'/organization?section=brands');assert.equal(await page.locator('form[action$="/organization/brands"] button[type="submit"]').count(),['free','starter'].includes(key)?0:1);await verify(key+'-brand-capacity');
                await page.goto(base+'/tests');assert(await page.getByRole('button',{name:'Run test',exact:true}).isDisabled());assert((await page.locator('body').innerText()).includes('Required credits:'));await verify(key+'-quick-test');
                await page.goto(base+'/batches');assert.equal(await page.getByRole('link',{name:'New Campaign',exact:true}).count()>0,key!=='free');await verify(key+'-campaigns');
                const advanced=page.locator('#sidebar-menu a[href$="/improvements"]');assert.equal(await advanced.count(),['pro','agency'].includes(key)?1:0);
                if(key!=='free') {await page.goto(base+'/batches/create');await page.locator('input[name="prompt_ids[]"]').first().check();await page.locator('input[name="market_ids[]"]').first().check();assert.equal(await page.locator('[data-credit-count]').innerText(),'1');assert.equal(await page.getByRole('button',{name:'Preview requests and credits',exact:true}).count(),1);await verify(key+'-campaign-preview');}
                else {assert.equal((await context.request.get(base+'/batches/create')).status(),403);assert.equal((await context.request.get(base+'/improvements')).status(),403);}
            }
            assert.deepEqual(errors,[]);await context.close();
        }
        assert.equal(apiSubmissions,0);const report={result:'passed',pageChecks:checks,plans:['free','starter','pro','agency'],widths:[1920,768,390,320],apiSubmissions,database:'isolated SQLite, invalid API key'};fs.writeFileSync(`${output}/checks.json`,JSON.stringify(report,null,2));console.log(JSON.stringify(report));
    } finally {await browser.close();}
})().catch(e=>{console.error(e.stack);process.exit(1);});
