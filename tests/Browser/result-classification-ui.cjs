const { chromium }=require('../../storage/app/ui-tools/node_modules/playwright');
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const base='http://127.0.0.1:8026';
const accounts=JSON.parse(fs.readFileSync('storage/app/private/testing-credentials.json','utf8'));
const output=path.resolve('storage/app/result-classification-ui-verification');
(async()=>{
 assert.equal(process.env.RESULT_CLASSIFICATION_UI,'1');fs.mkdirSync(output,{recursive:true});
 const browser=await chromium.launch({channel:'chrome',headless:true});let checks=0,blockedWrites=0,externalRequests=0;
 try{
  for(const email of ['starter@example.test','starter-admin@example.test','pro-viewer@example.test']){
   let state;
   for(const width of [1920,768,390,320]){
    const context=await browser.newContext({viewport:{width,height:1000},reducedMotion:'reduce',...(state?{storageState:state}:{})});const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
    await context.route('**/*',route=>{const r=route.request();if(new URL(r.url()).origin!==base){externalRequests++;return route.abort();}if(!['GET','HEAD'].includes(r.method())&&new URL(r.url()).pathname!=='/login'){blockedWrites++;return route.abort();}return route.continue();});
    if(!state){await page.goto(base+'/login');await page.locator('#email').fill(email);await page.locator('#password').fill(accounts[email].password);await Promise.all([page.waitForURL('**/dashboard'),page.getByRole('button',{name:'Sign in',exact:true}).click()]);state=await context.storageState();}
    const verify=async name=>{const text=await page.locator('body').innerText();assert(!/->user\(\)|is_platform_admin|@(?:endif|endforeach|else|continue)\b|Hotel visibility analysis|Verified hotel|No verified hotel/i.test(text),name+' broken or industry-specific output');assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),name+' overflow '+width);assert(text.includes('SIMULATED TEST DATA'));checks++;};
    if(email==='pro-viewer@example.test'){
     assert.equal((await context.request.get(base+'/tests/52')).status(),404);
     await page.goto(base+'/history');
     const own=await page.locator('a[href]').evaluateAll(links=>links.map(a=>a.href).find(h=>/\/tests\/\d+$/.test(h)));
     assert(own);await page.goto(own);await verify('viewer own result');assert.equal(await page.getByRole('link',{name:'Review / correct',exact:true}).count(),0);
    }else{
     await page.goto(base+'/organization?section=usage');const credits=await page.locator('[data-credit-used]').innerText();
     const response=await page.goto(base+'/tests/52');assert.equal(response.status(),200);await verify('test 52');
     assert.equal(await page.locator('[data-test-details] dt').count(),9);
     assert.equal(await page.locator('[data-brand-analysis] [data-comparative-rank]').first().innerText(),'Not ranked');
     assert((await page.locator('#response-evidence').innerText()).includes('1. Nandini Jungle by Hanging Gardens is recommended'));
     assert((await page.locator('[id^="citation-source-"]').first().innerText()).includes('Monitored brand official'));
     await page.locator('[data-brand-analysis] details').first().evaluate(el=>el.open=true);
     const evidenceLinks=await page.locator('[data-brand-analysis] a[href^="#"]').evaluateAll(els=>els.map(el=>el.getAttribute('href')));
     assert(evidenceLinks.includes('#response-evidence'));assert(evidenceLinks.some(h=>h.startsWith('#citation-source-')));
     for(const href of evidenceLinks)assert.equal(await page.locator(href).count(),1,href);
     await page.screenshot({path:path.join(output,email.split('@')[0]+'-result-52-'+width+'.png'),fullPage:true});
     await page.getByRole('link',{name:'Review / correct',exact:true}).click();await verify('review test 52');
     await page.goto(base+'/history');await verify('history');
     await page.goto(base+'/organization?section=usage');assert.equal(await page.locator('[data-credit-used]').innerText(),credits);
    }
    assert.deepEqual(errors,[]);await context.close();
   }
   console.log('Verified result rendering: '+email);
  }
  assert.equal(externalRequests,0);assert.equal(blockedWrites,0);
  const report={result:'passed',checks,testResult:52,accounts:['starter owner','starter administrator','pro viewer'],widths:[1920,768,390,320],externalRequests,executionSubmissions:blockedWrites};fs.writeFileSync(path.join(output,'checks.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report));
 }finally{await browser.close();}
})().catch(e=>{console.error(e.stack);process.exit(1);});
