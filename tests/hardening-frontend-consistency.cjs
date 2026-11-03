'use strict';
// Execute the shipped entrypoints with controlled fetch completion. These fixtures
// intentionally let aborted requests complete, so cancellation alone cannot pass.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.resolve(__dirname,'..');
let checks=0;
function check(value,message){assert.ok(value,message);checks++;}
function equal(actual,expected,message){assert.deepEqual(JSON.parse(JSON.stringify(actual)),expected,message);checks++;}
const flush=async()=>{for(let i=0;i<4;i++)await new Promise(resolve=>setImmediate(resolve));};
function storage(seed={}){const data=new Map(Object.entries(seed));return {getItem:key=>data.has(key)?data.get(key):null,setItem:(key,value)=>data.set(key,String(value)),removeItem:key=>data.delete(key),key:i=>[...data.keys()][i],get length(){return data.size;}};}
function node(id=''){
  const handlers=new Map(),classes=new Set();
  const n={id,dataset:{},disabled:false,textContent:'',value:'',hidden:false,tagName:'DIV',innerHTML:'',setAttribute(){},focus(){},scrollIntoView(){},matches(){return false;},closest(selector){return selector==='#'+id?n:null;},querySelector(){return null;},querySelectorAll(){return [];},appendChild(){},addEventListener(type,fn){if(!handlers.has(type))handlers.set(type,[]);handlers.get(type).push(fn);},async dispatch(type,event={}){for(const fn of handlers.get(type)||[])await fn({target:n,preventDefault(){},...event});},classList:{add:(...v)=>v.forEach(x=>classes.add(x)),remove:(...v)=>v.forEach(x=>classes.delete(x)),contains:x=>classes.has(x),toggle(x,on=!classes.has(x)){on?classes.add(x):classes.delete(x);return on;}}};
  return n;
}
function environment(config={}){
  const elements=new Map(),document=node(),window=node(),timers=[];
  document.getElementById=id=>{if(!elements.has(id))elements.set(id,node(id));return elements.get(id);};
  document.querySelector=selector=>selector.startsWith('#')?document.getElementById(selector.slice(1)):null;
  document.querySelectorAll=()=>[];document.body=node('body');document.documentElement=node();document.head=node();document.readyState='loading';document.createElement=()=>node();
  window.location={href:'',pathname:'/online-order.php',search:''};window.STONEFELLOWS_ORDER={locationId:1,authenticated:true,...config};window.dispatchEvent=event=>window.dispatch(event.type,event);
  const requests=[],fetch=(url,options={})=>new Promise((resolve,reject)=>{requests.push({url:String(url),options,resolve(data,ok=true){resolve({ok,json:async()=>data});},reject});});
  const context={window,document,location:window.location,sessionStorage:storage(),fetch,URLSearchParams,AbortController,Intl,Date,Math,JSON,CSS:{escape:String},CustomEvent:class{constructor(type,options={}){this.type=type;this.detail=options.detail;}},setTimeout:()=>0,clearTimeout(){},setInterval(fn,ms){timers.push({fn,ms});return timers.length;},clearInterval(){},requestAnimationFrame:fn=>fn(),console};
  vm.createContext(context);return {context,document,window,elements,requests,timers,get:id=>document.getElementById(id),run(file){vm.runInContext(fs.readFileSync(path.join(root,file),'utf8'),context,{filename:file});}};
}
function board(locationId,name,extra={}){return {ok:true,locationId,locations:[{id:1,name:'One'},{id:2,name:'Two'},{id:3,name:'Three'}],permissions:{update:true},board:{stations:[{public_id:'hot',name:'Hot'}],tickets:[{checkPublicId:name,checkNumber:name,guestCount:1,queued:1,items:[{public_id:'item-'+name,item_name_snapshot:name,quantity:1,status:'queued',station_id:1,station_public_id:'hot'}]}]},...extra};}
async function kds(){
  const e=environment();e.window.KDS_CONFIG={csrf:'test-token'};
  const source=fs.readFileSync(path.join(root,'js/kds.js'),'utf8').replace(/\}\)\(\);\s*$/,'window.__testKds={post,load};})();');
  vm.runInContext(source,e.context,{filename:'js/kds.js'});
  equal(e.requests.length,1,'initial KDS request');e.requests[0].resolve(board(1,'initial'));await flush();
  equal(e.window.GelatoKdsSlots.snapshot().allSlots.map(x=>x.checkPublicId),['initial'],'initial canonical board');
  const change=locationId=>e.document.dispatch('change',{target:{id:'location',value:String(locationId)}});
  const old=change(2);equal(e.requests.length,2,'second location requested');
  equal(e.window.GelatoKdsSlots.snapshot().allSlots,[],'no old voice/action slot mapping during a view change');
  check(!e.get('board').innerHTML.includes('data-item-act'),'old action controls removed while selection loads');
  await e.window.__testKds.post({action:'item.transition',itemPublicId:'item-initial',status:'ready'});
  equal(e.requests.length,2,'mutation rejected until selected data arrives');
  const latest=change(3);equal(e.requests.length,3,'latest selected view is never dropped behind older GET');
  check(e.requests[1].options.signal.aborted,'obsolete network request is cancelled');
  e.requests[2].resolve(board(3,'current'));await latest;
  e.requests[1].resolve(board(2,'obsolete'));await old;
  equal(e.window.GelatoKdsSlots.snapshot().allSlots.map(x=>x.checkPublicId),['current'],'late older response cannot replace latest location');
  check(!e.get('board').innerHTML.includes('obsolete'),'late response cannot render obsolete tickets');
  const abaOld=change(2),abaNew=change(3);
  equal(e.window.GelatoKdsSlots.snapshot().allSlots,[],'returning to a prior view cannot reuse old display authority before its new read');
  await e.window.__testKds.post({action:'item.transition',itemPublicId:'item-current',status:'ready'});equal(e.requests.length,5,'A/B/A wait cannot authorize a mutation from previously displayed data');
  e.requests[4].resolve(board(3,'aba-new'));await abaNew;e.requests[3].reject(Error('stale error'));await abaOld;
  equal(e.window.GelatoKdsSlots.snapshot().allSlots.map(x=>x.checkPublicId),['aba-new'],'A/B/A switching uses a generation as well as the view key');
  check(e.get('toast').textContent!=='stale error','obsolete errors do not overwrite current status');
  const post=e.window.__testKds.post({action:'item.transition',itemPublicId:'item-aba-new',status:'in_progress'});
  equal(JSON.parse(e.requests[5].options.body).locationId,3,'write carries its captured location');
  equal(e.requests[5].options.headers['X-CSRF-Token'],'test-token','writes retain CSRF');
  const switchDuringWrite=change(1);e.requests[6].resolve(board(1,'after-switch'));await switchDuringWrite;
  e.requests[5].resolve({ok:true,board:board(3,'late-write').board});await post;
  check(!e.get('board').innerHTML.includes('late-write'),'late write response cannot overwrite a different selected view');
  equal(e.requests[7].url,'api/kds.php?locationId=1','write completion refreshes the current view');
  e.requests[7].resolve(board(1,'fresh'));await flush();
  const history=e.get('history').dispatch('click');check(e.requests[8].url.startsWith('api/kds-history.php?'),'history has its own endpoint');
  equal(e.window.GelatoKdsSlots.snapshot().allSlots,[],'history never exposes live action slots');
  e.requests[8].reject(Error('temporary history error'));await history;
  check(e.get('board').innerHTML.includes('temporary history error'),'failed new view has an explicit recoverable state');
  e.timers.find(t=>t.ms===5000).fn();equal(e.requests.length,10,'failed history retries through polling');
  e.requests[9].resolve({ok:true,locationId:1,locations:[{id:1,name:'One'}],tickets:[{checkNumber:'completed',items:[]}]});await flush();
  check(e.get('board').innerHTML.includes('completed'),'history recovers with latest data');
  check(!e.get('board').innerHTML.includes('data-ticket-act'),'history cannot re-enable mutations');
  const back=e.get('history').dispatch('click');e.requests[10].resolve(board(2,'wrong-location'));await back;
  equal(e.window.GelatoKdsSlots.snapshot().allSlots,[],'server response for another location is refused');
}
async function cartEditor(file,legacy=false){
  const e=environment(),original={priceId:11,name:'Pizza',quantity:1,price:12,customizations:{removals:[5],substitutions:[{fromIngredientId:9,toIngredientId:10}],addOns:[{optionId:8,quantity:2,name:'Cheese',priceDelta:1}],note:'No nuts'}};
  const modal=e.get('orderCustomizeModal'),body=e.get('ocmBody'),save=node(),close=node(),cancel=node();
  modal.querySelector=selector=>selector==='.ocm-close'?close:selector.includes('save')?save:cancel;
  let html='';Object.defineProperty(body,'innerHTML',{get:()=>html,set(value){html=value;const note=node('ocmNote');const found=String(value).match(/<textarea[^>]*id="ocmNote"[^>]*>([\s\S]*?)<\/textarea>/);note.value=found?.[1]||'';e.elements.set('ocmNote',note);}});
  body.querySelector=selector=>selector==='#ocmNote'?e.get('ocmNote'):null;
  const orderNote=node();e.get('checkoutForm').querySelector=()=>orderNote;
  const storageKey='stonefellows.onlineCart.v3.1';e.context.sessionStorage.setItem(storageKey,JSON.stringify([original]));if(legacy)e.context.sessionStorage.setItem('stonefellows.onlineCart.v2.1',JSON.stringify([original]));
  e.run(file);const state=()=>JSON.parse(e.context.sessionStorage.getItem(storageKey));
  function open(index=0){const control={dataset:legacy?{index:String(index),cartAction:'customize'}:{customize:String(index)}};return (legacy?e.document:e.get('cartLines')).dispatch('click',{target:{closest:selector=>legacy?(selector==='[data-cart-action]'?control:null):(selector==='[data-customize]'?control:null)}});}
  const payload=(priceId=11)=>({ok:true,customization:{priceId,sectionName:'Current',ingredients:[],substitutions:[],addOnGroups:[]}});
  await open();check(save.disabled,file+' disables save while loading');
  const before=state()[0].customizations;await save.dispatch('click');equal(state()[0].customizations,before,file+' programmatic premature save cannot clear existing choices');
  e.requests[0].reject(Error('catalog unavailable'));await flush();check(save.disabled,file+' keeps save disabled after error');await save.dispatch('click');equal(state()[0].customizations,before,file+' error cannot clear existing choices');
  await close.dispatch('click');await open();const old=e.requests[1];await close.dispatch('click');await open();const current=e.requests[2];
  current.resolve(payload());await flush();check(!save.disabled,file+' latest successful catalog enables save');
  old.reject(Error('old failed request'));await flush();check(!body.innerHTML.includes('old failed request'),file+' late old error cannot replace reopened editor');
  check(body.innerHTML.includes('saved choices are unavailable'),file+' unavailable choices are disclosed without deleting them');
  e.get('ocmNote').value='Sauce on side';await e.get('ocmNote').dispatch('input');await save.dispatch('click');
  const saved=state()[0].customizations;equal(saved.removals,[5],file+' unrepresented removal preserved');equal(saved.substitutions,[{fromIngredientId:9,toIngredientId:10}],file+' unrepresented substitution preserved');equal(saved.addOns.map(a=>({optionId:a.optionId,quantity:a.quantity})),[{optionId:8,quantity:2}],file+' unrepresented paid add-ons preserved');equal(saved.note,'Sauce on side',file+' deliberate note edit saved from the draft');
  await open();const wrong=e.requests.at(-1);wrong.resolve(payload(99));await flush();check(save.disabled,file+' wrong-item catalog cannot arm save');
  equal(state()[0].customizations,saved,file+' wrong-item response does not change cart');
  await close.dispatch('click');await open();const closed=e.requests.at(-1);await close.dispatch('click');closed.resolve(payload());await flush();check(save.disabled&&!modal.classList.contains('open'),file+' late response cannot reopen or arm a closed editor');
}
async function publicCart(){
  const e=environment();e.run('assets/js/public-shell.js');const cart=e.window.GelatoPublicCart,s=e.context.sessionStorage;
  s.setItem('stonefellows.onlineCart.v1.1',JSON.stringify([{priceId:1,quantity:100}]));s.setItem('stonefellows.onlineCart.v3.1',JSON.stringify([{priceId:1,quantity:3}]));s.setItem('stonefellows.onlineCart.v3.2',JSON.stringify([{priceId:1,quantity:9}]));
  equal(cart.quantity(1),3,'current v3 cart wins over obsolete v1');equal(cart.quantity(2),9,'each pickup location has its own cart');
  cart.save(1,[{priceId:1,quantity:4}]);equal(e.get('cartHeaderCount').textContent,'4','explicit cart change event updates the active-location badge');
  cart.save(2,[{priceId:1,quantity:8}]);equal(e.get('cartHeaderCount').textContent,'4','another pickup location cannot inflate active badge');
  s.setItem('stonefellows.onlineCart.v2.3',JSON.stringify([{priceId:7,quantity:2,customizations:{note:'Keep this'}}]));s.setItem('stonefellows.onlineCart.v2.3.note','Order note');s.setItem('stonefellows.onlineCart.v1.3',JSON.stringify([{priceId:8,quantity:99}]));
  equal(cart.read(3)[0].quantity,2,'legacy v2 migrated ahead of v1');equal(s.getItem('stonefellows.onlineCart.v3.3.note'),'Order note','migration preserves order note');equal(s.getItem('stonefellows.onlineCart.v2.3'),null,'successfully migrated source retired');equal(cart.quantity(3),2,'migration never double counts');
  s.setItem('stonefellows.onlineCart.v3.4','[]');s.setItem('stonefellows.onlineCart.v1.4',JSON.stringify([{priceId:8,quantity:99}]));equal(cart.read(4),[],'deliberately emptied current cart does not resurrect legacy rows');
  s.setItem('stonefellows.onlineCart.v3.5','invalid');equal(cart.read(5),[],'malformed browser cart is contained');
  s.setItem('stonefellows.onlineCart.v2.6',JSON.stringify([{priceId:3,quantity:2}]));s.setItem=()=>{throw Error('storage full');};equal(cart.read(6)[0].quantity,2,'full storage does not hide readable legacy cart');check(s.getItem('stonefellows.onlineCart.v2.6')!==null,'unsaved migration keeps original source');cart.save(1,[{priceId:2,quantity:5}]);equal(e.get('cartHeaderCount').textContent,'5','live badge follows in-memory cart when storage is full');
}
(async()=>{const selected=process.argv.find(x=>x.startsWith('--case='))?.slice(7);if(!selected||selected==='kds')await kds();if(!selected||selected==='food')await cartEditor('assets/js/online-order-food.js');if(!selected||selected==='legacy')await cartEditor('assets/js/online-order.js',true);if(!selected||selected==='cart')await publicCart();console.log(`PASS: ${checks} frontend consistency checks against shipped entrypoints.`);})().catch(error=>{console.error(error);process.exitCode=1;});
