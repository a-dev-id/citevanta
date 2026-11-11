const { chromium } = require('../../storage/app/ui-tools/node_modules/playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const base = process.env.LOCAL_TEST_URL || 'http://127.0.0.1:8026';
const accounts = JSON.parse(fs.readFileSync('storage/app/private/testing-credentials.json','utf8'));
const output = path.resolve('storage/app/local-testing-ui-verification');
const widths = process.env.LOCAL_TEST_WIDTHS ? JSON.parse(process.env.LOCAL_TEST_WIDTHS) : [1920,768,390,320];
const banned = /cost controls|USD|estimated cost|actual API cost|budget|input tokens|output tokens|total tokens|financial reservation|API allowance|token consumption/i;
(async()=>{
 assert.equal(process.env.LOCAL_TEST_UI,'1'); fs.mkdirSync(output,{recursive:true});
 const browser = await chromium.launch({channel:'chrome',headless:true}); let checks=0, executionSubmissions=0;
 try {
  for(const account of Object.values(accounts)) {
   let storageState;
   for(const width of widths) {
    const context=await browser.newContext({viewport:{width,height:1000},reducedMotion:'reduce',...(storageState?{storageState}:{})});
    const page=await context.newPage(); const errors=[]; page.on('pageerror',e=>errors.push(e.message));
    await context.route('**/*',async route=>{
     const r=route.request(), url=new URL(r.url());
     if(url.origin!==base) return route.abort();
     if(r.method()!=='GET' && r.method()!=='HEAD' && !['/login','/organization/switch'].includes(url.pathname)){executionSubmissions++;return route.abort();}
     return route.continue();
    });
    if (!storageState) {
     await page.goto(base+'/login');await page.locator('#email').fill(account.email);await page.locator('#password').fill(account.password);
     await Promise.all([page.waitForURL(/\/(dashboard|platform\/organizations)$/),page.getByRole('button',{name:'Sign in',exact:true}).click()]);
     storageState=await context.storageState();
    } else { await page.goto(base+'/dashboard'); }
    if(account.role==='Super Admin') {
     const options=await page.locator('#organization-switch option').evaluateAll(els=>els.filter(e=>e.textContent.includes('CiteVanta Test')).map(e=>({value:e.value,name:e.textContent})));
     assert.equal(options.length,4);
     for(const option of options) {
      await page.locator('#organization-switch').selectOption(option.value);
      await Promise.all([page.waitForURL('**/dashboard'),page.getByRole('button',{name:'Switch',exact:true}).click()]);
      await page.goto(base+'/organization?section=brands');
      assert.equal(await page.locator('#organization-plan').inputValue(),option.name.split(' ').pop().toLowerCase());
      assert.equal(await page.locator('#organization-status').count(),1); checks++;
     }
    } else {
     assert.equal(await page.locator('#organization-switch option').count(),1);
     assert.equal((await context.request.get(base+'/platform/organizations')).status(),403);
    }
    const verify=async url=>{
     const response=await page.goto(base+url);assert.equal(response.status(),200,account.email+' '+url);
     const text=await page.locator('body').innerText();assert(!banned.test(text),account.email+' '+url+' financial content');
     assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),account.email+' '+url+' overflow '+width);
     assert.equal(await page.locator('#sidebar-menu .nav-link-icon svg').count(),6);
     assert.equal(await page.locator('[data-simulated-notice]').count(),1);
     if(width<768) assert((await page.locator('.page-header h1').boundingBox()).width>width*0.7,'Mobile page heading must have its own row');
     assert(!text.includes(account.password));checks++;
    };
    for(const url of ['/dashboard','/history','/batches','/organization?section=usage','/organization?section=team','/organization?section=brands','/settings','/competitors']) await verify(url);
    await page.goto(base+'/organization?section=usage');
    const plan=account.role==='Super Admin'?'starter':account.email.split('@')[0].split('-')[0];
    const allowance={free:5,starter:450,pro:1500,agency:4000}[plan];
    assert.equal(await page.locator('[data-credit-allowance]').innerText(),allowance.toLocaleString('en-US'));
    assert.equal(await page.locator('[data-credit-used]').innerText(),'0');assert.equal(await page.locator('[data-credit-reserved]').innerText(),'0');
    if(account.role==='Viewer') {
     assert.equal((await context.request.get(base+'/tests')).status(),403);
     assert.equal((await context.request.get(base+'/batches/create')).status(),403);
    } else {
     await verify('/tests');assert.equal(await page.getByRole('button',{name:'Run test',exact:true}).isEnabled(),await page.locator('#model').inputValue()==='simulated-monitoring-v1');
     if(plan!=='free') await verify('/batches/create'); else assert.equal((await context.request.get(base+'/batches/create')).status(),403);
    }
    await verify('/history');
    const result=page.locator('a[href]').filter({hasText:/View result|View test|View response/}).first();
    const resultUrls=await page.locator('a[href]').evaluateAll(els=>els.map(e=>e.href).filter(h=>/\/tests\/\d+$/.test(h)));
    assert(resultUrls.length>0);await verify(new URL(resultUrls[0]).pathname);
    await page.goto(base+'/batches');
    const campaigns=await page.locator('a[href]').evaluateAll(els=>els.map(e=>e.href).filter(h=>/\/batches\/\d+$/.test(h)));
    if(plan!=='free'){assert(campaigns.length>0);await verify(new URL(campaigns[0]).pathname);}
    assert.equal((await context.request.get(base+'/batch-settings')).status(),404);
    if(width===320||width===1920)await page.screenshot({path:path.join(output,account.email.split('@')[0]+'-'+width+'.png'),fullPage:true});
    assert.deepEqual(errors,[]);await context.close();
   }
   console.log('Verified '+account.email+' ('+account.role+')');
  }
  assert.equal(executionSubmissions,0);
  const report={result:'passed',accounts:Object.keys(accounts).length,checks,widths,executionSubmissions};
  fs.writeFileSync(path.join(output,'checks.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report));
 } finally {await browser.close();}
})().catch(e=>{console.error(e.stack);process.exit(1);});
