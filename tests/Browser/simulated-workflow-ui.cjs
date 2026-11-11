const { chromium }=require('../../storage/app/ui-tools/node_modules/playwright');
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const base='http://127.0.0.1:8026';
const credentials=JSON.parse(fs.readFileSync(process.env.SIMULATION_UI_CREDENTIALS || 'storage/framework/simulation-ui-credentials.json','utf8'));
const output=path.resolve(process.env.SIMULATION_UI_OUTPUT || 'storage/app/simulated-workflow-ui-verification');
(async()=>{
 assert.equal(process.env.SIMULATED_WORKFLOW_UI,'1');fs.mkdirSync(output,{recursive:true});
 const browser=await chromium.launch({channel:'chrome',headless:true});let checks=0,externalRequests=0,simulatedQuickTests=0,completedCampaigns=0,stoppedCampaigns=0;
 try{
  for(const plan of ['free','starter','pro','agency']){
   let state;
   for(const width of [1920,768,390,320]){
    const context=await browser.newContext({viewport:{width,height:1000},reducedMotion:'reduce',...(state?{storageState:state}:{})});const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
    await context.route('**/*',route=>{if(new URL(route.request().url()).origin!==base){externalRequests++;return route.abort();}return route.continue();});
    if(!state){await page.goto(base+'/login');await page.locator('#email').fill(plan+'@example.test');await page.locator('#password').fill(credentials[plan+'@example.test'].password);await Promise.all([page.waitForURL('**/dashboard'),page.getByRole('button',{name:'Sign in',exact:true}).click()]);state=await context.storageState();}
    const verify=async name=>{const text=await page.locator('body').innerText();assert(!/@(?:endif|foreach|endforeach|else|endsection)\b/.test(text),name+' literal Blade directive');assert(!/Cost Controls|\bUSD\b|API attempts|Planned tests|Approve batch|Stop pending requests|cron processing|input tokens|output tokens/i.test(text),name+' old terminology');assert(text.includes('SIMULATED TEST DATA'));assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),name+' overflow '+width);checks++;};
    await page.goto(base+'/organization?section=usage');const initial=Number((await page.locator('[data-credit-used]').innerText()).replaceAll(',',''));
    await page.goto(base+'/tests');assert((await page.locator('#provider option:checked').innerText()).includes('Simulated'));assert.equal(await page.locator('#model').inputValue(),'simulated-monitoring-v1');assert(await page.getByRole('button',{name:'Run test',exact:true}).isEnabled());await verify(plan+'-quick-test');
    await Promise.all([page.waitForURL(/\/tests\/\d+$/),page.getByRole('button',{name:'Run test',exact:true}).click()]);assert((await page.locator('body').innerText()).includes('SIMULATED TEST RESULT'));await verify(plan+'-quick-result');simulatedQuickTests++;
    if(plan==='free'){
     assert.equal((await context.request.get(base+'/batches/create')).status(),403);
     await page.goto(base+'/organization?section=usage');assert.equal(Number(await page.locator('[data-credit-used]').innerText()),initial+1);await verify('free-usage');
    }else{
     const preview=async name=>{
      await page.goto(base+'/batches/create');await page.locator('#batch-name').fill(name);
      await page.locator('input[name="prompt_ids[]"]').evaluateAll(inputs=>inputs.forEach(input=>{input.checked=true;input.dispatchEvent(new Event('change',{bubbles:true}));}));
      await page.locator('input[name="market_ids[]"]').first().check();assert.equal(await page.locator('[data-credit-count]').innerText(),'12');
      await Promise.all([page.waitForURL(/\/batches\/\d+$/),page.getByRole('button',{name:'Preview requests and credits',exact:true}).click()]);await verify(plan+'-preview');
      assert(await page.getByRole('button',{name:'Start Campaign',exact:true}).isDisabled());
      await page.locator('input[name="confirm_credits"]').check();assert(await page.getByRole('button',{name:'Start Campaign',exact:true}).isDisabled());
      await page.locator('input[name="large_warning"]').check();assert(await page.getByRole('button',{name:'Start Campaign',exact:true}).isEnabled());
      await Promise.all([page.waitForURL(page.url()),page.getByRole('button',{name:'Start Campaign',exact:true}).click()]);
      assert.equal(await page.locator('[data-credit-reserved]').innerText(),'12');await verify(plan+'-started');
     };
     await preview('Browser simulated '+plan+' '+width);
     for(let i=0;i<4;i++){await Promise.all([page.waitForURL(page.url()),page.getByRole('button',{name:'Process Next Searches',exact:true}).click()]);assert.equal(Number((await page.locator('[data-credit-used]').innerText()).replaceAll(',','')),initial+1+(i+1)*3);assert.equal(Number(await page.locator('[data-credit-reserved]').innerText()),12-(i+1)*3);await verify(plan+'-progress');}
     assert.equal(await page.getByRole('button',{name:'Process Next Searches',exact:true}).count(),0);completedCampaigns++;
     await page.screenshot({path:path.join(output,plan+'-completed-'+width+'.png'),fullPage:true});
     await preview('Browser cancellation '+plan+' '+width);
     await Promise.all([page.waitForURL(page.url()),page.getByRole('button',{name:'Process Next Searches',exact:true}).click()]);
     await Promise.all([page.waitForURL(page.url()),page.getByRole('button',{name:'Stop Campaign',exact:true}).click()]);
     assert.equal(await page.locator('[data-credit-reserved]').innerText(),'0');assert.equal(Number((await page.locator('[data-credit-used]').innerText()).replaceAll(',','')),initial+16);assert.equal(await page.getByRole('button',{name:'Process Next Searches',exact:true}).count(),0);await verify(plan+'-stopped');stoppedCampaigns++;
    }
    for(const url of ['/dashboard','/history','/competitors','/batches','/organization?section=usage']){await page.goto(base+url);await verify(plan+url);}
    assert.deepEqual(errors,[]);await context.close();
   }
   console.log('Completed simulated workflow: '+plan);
  }
  assert.equal(externalRequests,0);const report={result:'passed',checks,plans:['free','starter','pro','agency'],widths:[1920,768,390,320],simulatedQuickTests,completedCampaigns,stoppedCampaigns,externalRequests,database:'isolated SQLite; invalid real API key; real execution locked'};fs.writeFileSync(path.join(output,'checks.json'),JSON.stringify(report,null,2));console.log(JSON.stringify(report));
 }finally{await browser.close();}
})().catch(e=>{console.error(e.stack);process.exit(1);});
