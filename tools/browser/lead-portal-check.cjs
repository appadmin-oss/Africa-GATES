// Read-only responsive checks against an isolated, populated event-lead fixture.
// BASE_URL, QA_SESSION and QA_EVENT are required; QA_OUTPUT is optional.
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const base=process.env.BASE_URL, session=process.env.QA_SESSION, event=process.env.QA_EVENT;
if(!base||!session||!/^\d+$/.test(event||'')) throw new Error('Set BASE_URL, QA_SESSION and QA_EVENT for an isolated lead fixture.');
const output=process.env.QA_OUTPUT||'/tmp/africa-gates-lead-qa';fs.mkdirSync(output,{recursive:true});
(async()=>{
 const browser=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox']});
 try {
  const context=await browser.newContext({hasTouch:true});
  await context.addCookies([{name:'PHPSESSID',value:session,url:base}]);
  const page=await context.newPage(), errors=[], checks=[];
  page.on('pageerror',e=>errors.push(e.message));
  const eventBase='/lead/event/'+event;
  const routes=['/lead','/lead/inbox','/lead/people',eventBase,...['plan','plan?tab=risks','people','people?tab=invites','money','night','roles','settings'].map(p=>eventBase+'/'+p)];
  for(const width of [320,390,600,768,1024,1440]) {
   await page.setViewportSize({width,height:850});
   for(const route of routes){
    const response=await page.goto(base+route,{waitUntil:'domcontentloaded'});
    assert.equal(response.status(),200,route);
    const main=page.locator('#sh7Main');await main.waitFor();
    assert(await main.evaluate(e=>e.scrollWidth<=e.clientWidth+2),route+' overflow at '+width);
    const current=page.locator('.sh7-tabs [aria-current=page]');
    if(await current.count()) assert(await current.evaluate(e=>{const r=e.getBoundingClientRect(),p=e.parentElement.getBoundingClientRect();return r.left>=p.left-1&&r.right<=p.right+1;}),'current tab hidden: '+route);
    checks.push({route,width});
   }
  }
  await page.setViewportSize({width:390,height:844});
  await page.goto(base+eventBase+'/roles');
  assert(await page.locator('.sh7-more>summary.is-on').count());
  assert(await page.getByText('Leads can create teams and assign routine roles directly.',{exact:false}).count());
  const jump=page.locator('.sh7-section-nav');await jump.locator('summary').click();
  const last=jump.locator('nav a').last(), target=await last.getAttribute('href');await last.click();
  assert(await page.locator(target).evaluate(e=>e.getBoundingClientRect().top>=0&&e.getBoundingClientRect().top<innerHeight));
  await page.goto(base+eventBase+'/roles');await page.screenshot({path:output+'/lead-roles-mobile.png'});
  await page.goto(base+eventBase+'/plan');await page.screenshot({path:output+'/lead-plan-mobile.png'});
  await page.setViewportSize({width:1440,height:960});await page.goto(base+eventBase+'/plan');await page.screenshot({path:output+'/lead-plan-desktop.png'});
  await page.setViewportSize({width:1280,height:900});await page.evaluate(()=>document.body.style.zoom='2');
  assert(await page.locator('#sh7Main').evaluate(e=>e.scrollWidth<=e.clientWidth+2),'200% zoom overflow');
  assert.deepEqual(errors,[]);
  console.log(JSON.stringify({pages:routes.length,viewportChecks:checks.length,widths:[320,390,600,768,1024,1440],currentTabsVisible:true,sectionNavigation:true,morePageIndicator:true,zoom200:true,pageErrors:errors}));
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
