'use strict';
// Actual Chromium/DOM journeys against the shipped browser files. Only API data
// is controlled here: server-side authorization is covered by the domain suites.
const assert=require('node:assert/strict');
const fs=require('node:fs/promises');
const http=require('node:http');
const path=require('node:path');
const {chromium}=require('playwright');
const root=path.resolve(__dirname,'..');
let checks=0;
function equal(actual,expected,message){assert.deepEqual(actual,expected,message);checks++;}
function check(value,message){assert.ok(value,message);checks++;}
const kdsMarkup=`<!doctype html><html><body><div id="launch"><button id="startKds">Start</button></div><select id="location"></select><button id="refresh">Refresh</button><button id="history">Order history</button><div id="tabs"></div><div id="unroutedAlert"></div><div id="allDay"></div><div id="kdsSlotBar"></div><div id="board"></div><div id="toast"></div><script>window.KDS_CONFIG={csrf:'fixture-csrf'};</script><script src="/js/kds.js"></script></body></html>`;
// Match public_site_render_header(): the ordering script adds its trigger inside
// #nav, then public-shell replaces the whole .site-header (including that trigger).
const cartHeaderMarkup='<header class="site-header inner"><div class="shell nav" id="nav"><a class="brand" href="index.php">Fixture Restaurant</a><button class="menu-toggle" id="menuToggle" type="button" aria-label="Open navigation" aria-expanded="false">Menu</button></div></header>';
function cartMarkup(legacy){return `<!doctype html><html><head><style>body{font-family:sans-serif}button{padding:8px}.order-cart-backdrop{display:none}#orderCartDrawer{position:relative;width:650px;margin:120px auto 20px}</style></head><body>${cartHeaderMarkup}<aside id="orderCartDrawer"><button id="orderCartClose">Close</button><div id="cartCount"></div><div id="cartSubtotal"></div><div id="cartLines"></div><form id="checkoutForm"><textarea name="order_note"></textarea><input id="cartJson"><input id="idempotencyKey"><button id="placeOrder">Order</button></form></aside><div id="orderCartBackdrop"></div><script>window.STONEFELLOWS_ORDER={locationId:1,authenticated:true};</script><script src="/assets/js/public-shell.js"></script><script src="/assets/js/${legacy?'online-order':'online-order-food'}.js"></script></body></html>`;}
async function assertCartBadges(page,quantity,message){
  // Inspect every visible badge, so a second stale badge cannot be hidden by
  // selecting the first match. Also reject duplicate IDs, even if not visible.
  equal(await page.locator('[id="cartHeaderCount"]:visible').allTextContents(),[String(quantity)],message);
  equal(await page.locator('[id="cartHeaderCount"]').count(),1,'public shell replaces the original header without duplicate cart badge IDs');
}
function board(locationId,id){return {ok:true,locationId,locations:[{id:1,name:'One'},{id:2,name:'Two'},{id:3,name:'Three'}],permissions:{update:true},board:{stations:[{public_id:'hot',name:'Hot'}],tickets:[{checkPublicId:id,checkNumber:id,serviceMode:'pickup',guestCount:1,queued:1,items:[{public_id:'item-'+id,item_name_snapshot:id,quantity:1,status:'queued',station_id:1,station_public_id:'hot'}]}]}};}
function catalog(priceId){return {ok:true,customization:{priceId,sectionName:priceId===11?'Pizza options':'Salad options',ingredients:[{id:5,name:'Onion',canRemove:true},{id:9,name:'Tomato',canRemove:true}],substitutions:[{id:10,name:'Spinach'}],addOnGroups:[{id:'extras',name:'Extra toppings',maxSelect:3,options:[{id:8,name:'Cheese',priceDelta:1,maxQuantity:3}]}]}};}
async function queued(queue,count){const deadline=Date.now()+10000;while(queue.length<count){if(Date.now()>deadline)throw new Error(`Expected request ${count}, got ${queue.length}`);await new Promise(resolve=>setTimeout(resolve,10));}return queue[count-1];}
async function respond(request,data,status=200){await request.route.fulfill({status,contentType:'application/json',body:JSON.stringify(data)});}
async function kdsJourney(browser,origin){
  const page=await browser.newPage(),requests=[];
  await page.route('**/api/kds*.php?*',route=>requests.push({route,url:route.request().url(),method:route.request().method()}));
  await page.route('**/api/kds.php',route=>requests.push({route,url:route.request().url(),method:route.request().method()}));
  await page.goto(origin+'/fixtures/kds');await respond(await queued(requests,1),board(1,'initial'));
  await page.locator('[data-ticket="initial"]').waitFor();
  await page.selectOption('#location','2');await queued(requests,2);
  equal(await page.locator('[data-item-act]').count(),0,'view changes immediately remove old mutation controls');
  equal(await page.evaluate(()=>window.GelatoKdsSlots.snapshot().allSlots.length),0,'view change removes stale voice slot references');
  await page.selectOption('#location','3');await respond(await queued(requests,3),board(3,'new-three'));
  await page.locator('[data-ticket="new-three"]').waitFor();
  await respond(requests[1],board(2,'obsolete-two')).catch(()=>{});
  equal(await page.locator('[data-ticket="obsolete-two"]').count(),0,'late other-location result is never shown');
  await page.locator('[data-station="hot"]').click();const station=await queued(requests,4);check(station.url.includes('station=hot'),'station request carries immutable selected station');
  await page.locator('#history').click();const history=await queued(requests,5);check(history.url.includes('kds-history.php'),'history requested while station load is pending');
  await respond(history,{ok:true,locationId:3,locations:[{id:1,name:'One'},{id:3,name:'Three'}],tickets:[{checkNumber:'done-history',guestCount:1,items:[]}]});
  await page.getByText('done-history',{exact:true}).waitFor();await respond(station,board(3,'obsolete-station')).catch(()=>{});
  equal(await page.locator('[data-ticket="obsolete-station"]').count(),0,'obsolete live station cannot replace history');
  await page.locator('#history').click();await respond(await queued(requests,6),board(3,'live-again'));await page.locator('[data-ticket="live-again"]').waitFor();
  await page.locator('[data-item-act="in_progress"]').click();const write=await queued(requests,7);
  equal(write.method,'POST','actual KDS action posts');equal(write.route.request().postDataJSON().locationId,3,'write retains original location');
  await page.selectOption('#location','1');await respond(await queued(requests,8),board(1,'new-one'));await page.locator('[data-ticket="new-one"]').waitFor();
  await respond(write,{ok:true,board:board(3,'old-write-result').board});const fresh=await queued(requests,9);check(fresh.url.includes('locationId=1'),'write completion refreshes the latest view');
  await respond(fresh,board(1,'final-one'));await page.locator('[data-ticket="final-one"]').waitFor();
  equal(await page.locator('[data-ticket="old-write-result"]').count(),0,'late mutation result never changes displayed view');
  equal((await page.evaluate(()=>window.GelatoKdsSlots.snapshot())).allSlots.map(x=>x.checkPublicId),['final-one'],'visual and spoken order slots use latest view');
  await page.close();
}
async function editorJourney(browser,origin,legacy){
  const page=await browser.newPage(),requests=[];
  const initial=[{priceId:11,name:'Pizza',quantity:2,price:12,customizations:{removals:[5],substitutions:[{fromIngredientId:9,toIngredientId:10}],addOns:[{optionId:8,quantity:2,name:'Cheese',priceDelta:1}],note:'No nuts'}},{priceId:22,name:'Salad',quantity:1,price:8,customizations:{removals:[77],substitutions:[],addOns:[{optionId:88,quantity:1,name:'Saved extra',priceDelta:2}],note:'No dressing'}}];
  await page.addInitScript(rows=>{sessionStorage.setItem('stonefellows.onlineCart.v3.1',JSON.stringify(rows));sessionStorage.setItem('stonefellows.onlineCart.v1.1',JSON.stringify([{priceId:99,quantity:50}]));sessionStorage.setItem('stonefellows.onlineCart.v3.2',JSON.stringify([{priceId:99,quantity:90}]));},initial);
  await page.route('**/api/online-order-customizations.php?*',route=>requests.push({route,url:route.request().url()}));
  await page.goto(origin+'/fixtures/cart'+(legacy?'?legacy=1':''));
  await page.locator('.public-shell-header').waitFor();await assertCartBadges(page,3,'all visible cart badges use current pickup cart without legacy/other-location double count');
  const customize=index=>page.locator(legacy?`[data-cart-action="customize"][data-index="${index}"]`:`[data-customize="${index}"]`);
  const save=page.locator(legacy?'[data-ocm-save]':'[data-save]'),cancel=page.locator(legacy?'[data-ocm-cancel]':'[data-cancel]');
  const read=()=>page.evaluate(()=>JSON.parse(sessionStorage.getItem('stonefellows.onlineCart.v3.1')));
  await customize(0).click();await queued(requests,1);check(await save.isDisabled(),'save disabled until exact item catalog is ready');
  const before=await read();await save.evaluate(el=>el.dispatchEvent(new MouseEvent('click',{bubbles:true})));equal(await read(),before,'even dispatched premature save preserves cart');
  await respond(requests[0],{ok:false,message:'Catalog temporarily unavailable'},503);await page.getByRole('alert').waitFor();check(await save.isDisabled(),'failed fetch leaves save disabled');equal(await read(),before,'failed fetch preserves all instructions');
  await cancel.click();await customize(0).click();const old=await queued(requests,2);await cancel.click();await customize(1).click();const latest=await queued(requests,3);
  await respond(latest,catalog(22));await page.waitForFunction(()=>!document.querySelector('[data-save],[data-ocm-save]').disabled);
  await respond(old,catalog(11)).catch(()=>{});equal(await page.locator('#ocmTitle').textContent(),'Customize Salad','late prior-item response cannot retarget current editor');
  check((await page.locator('#ocmBody').textContent()).includes('saved choices are unavailable'),'unavailable saved choices remain disclosed');
  await page.locator('#ocmNote').fill('Keep dressing separate');await save.click();let rows=await read();equal(rows[0].customizations,before[0].customizations,'editing another row never changes the original item');equal(rows[1].customizations.removals,[77],'unknown saved removal preserved');equal(rows[1].customizations.addOns[0].optionId,88,'unknown paid add-on preserved');equal(rows[1].customizations.note,'Keep dressing separate','deliberate note edit applied to exact row');
  await customize(0).click();await respond(await queued(requests,4),catalog(11));await page.waitForFunction(()=>!document.querySelector('[data-save],[data-ocm-save]').disabled);
  check(await page.locator('[data-ingredient="5"] [data-remove]').isChecked(),'existing removal restored in actual checkbox');
  await page.locator('[data-ingredient="5"] [data-remove]').uncheck();await page.locator('[data-ingredient="9"] select').selectOption('');
  await page.locator('[data-addon="8"] [data-addon-plus]').click();await page.locator('#ocmNote').fill('Light bake');await save.click();rows=await read();
  equal(rows[0].customizations.removals,[],'intentional removal reset is saved');equal(rows[0].customizations.substitutions,[],'intentional substitution reset is saved');equal(rows[0].customizations.addOns[0].quantity,3,'paid add-on quantity control updates draft');equal(rows[0].customizations.note,'Light bake','note input updates draft');
  await assertCartBadges(page,3,'all visible cart badges remain stable after save and shell updates');
  await customize(0).click();const deleted=await queued(requests,5);
  const minus=page.locator(legacy?'[data-cart-action="minus"][data-index="0"]':'[data-minus="0"]');
  await minus.evaluate(el=>el.click());await minus.evaluate(el=>el.click());
  await respond(deleted,catalog(11)).catch(()=>{});check(!(await page.locator('#orderCustomizeModal').evaluate(el=>el.classList.contains('open'))),'removing edited row closes pending editor');rows=await read();equal(rows.map(x=>x.priceId),[22],'late response cannot customize the row that moved into an old index');equal(rows[0].customizations.note,'Keep dressing separate','remaining row instructions preserved');
  await assertCartBadges(page,1,'same-page cart event updates every visible badge after removal');
  await page.close();
}
(async()=>{
  const server=http.createServer(async(req,res)=>{
    try{
      const url=new URL(req.url,'http://fixture');
      if(url.pathname==='/fixtures/kds'){res.setHeader('Content-Type','text/html');res.end(kdsMarkup);return;}
      if(url.pathname==='/fixtures/cart'){res.setHeader('Content-Type','text/html');res.end(cartMarkup(url.searchParams.has('legacy')));return;}
      if(url.pathname==='/api/public-shell.php'){res.setHeader('Content-Type','application/json');res.end(JSON.stringify({ok:true,shell:{restaurantName:'Fixture Restaurant',selectedLocationId:1}}));return;}
      const file=path.resolve(root,'.'+url.pathname);if(!file.startsWith(root+path.sep)){res.statusCode=404;res.end();return;}
      const data=await fs.readFile(file);res.setHeader('Content-Type',file.endsWith('.js')?'text/javascript':file.endsWith('.css')?'text/css':'application/octet-stream');res.end(data);
    }catch(_){res.statusCode=404;res.end();}
  });
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));let browser;
  try{browser=await chromium.launch({headless:true});const origin=`http://127.0.0.1:${server.address().port}`;await kdsJourney(browser,origin);await editorJourney(browser,origin,false);await editorJourney(browser,origin,true);console.log(`PASS: ${checks} real Chromium frontend consistency checks.`);}
  finally{if(browser)await browser.close();await new Promise(resolve=>server.close(resolve));}
})().catch(error=>{console.error(error);process.exitCode=1;});
