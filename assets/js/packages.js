(() => {
  'use strict';
  const cfg=window.STONEFELLOWS_PACKAGES||{};
  const packages=Array.isArray(cfg.packages)?cfg.packages:[];
  const $=(s,r=document)=>r.querySelector(s);
  const esc=(v)=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const money=(n)=>new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(Number(n||0));
  const drawer=$('#packageDrawer'),backdrop=$('#packageBackdrop');
  if(!drawer||!backdrop)return;
  let active=null,selections={};

  function randomKey(){
    const bytes=new Uint8Array(20);crypto.getRandomValues(bytes);return 'package_'+Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('');
  }
  function storageKey(slug){return `stonefellows-package-${slug}`;}
  function restore(slug){try{const saved=JSON.parse(sessionStorage.getItem(storageKey(slug))||'{}');return saved&&typeof saved==='object'?saved:{};}catch{return {};}}
  function persist(){if(!active)return;try{sessionStorage.setItem(storageKey(active.slug),JSON.stringify(selections));}catch{}}
  function groupSelected(group){return Array.isArray(selections[group.id])?selections[group.id]:[];}
  function totalSelected(group){return groupSelected(group).length;}
  function countPrice(group,priceId){return groupSelected(group).filter(v=>Number(v)===Number(priceId)).length;}
  function packageComplete(){return !!active&&Number(cfg.locationId)>0&&active.groups.every(g=>totalSelected(g)===Number(g.requiredQuantity));}
  function selectedRetail(){
    if(!active)return 0;let total=0;
    active.groups.forEach(group=>groupSelected(group).forEach(priceId=>{const item=(group.items||[]).find(i=>Number(i.priceId)===Number(priceId));if(item)total+=Number(item.amount||0);}));return Math.round(total*100)/100;
  }
  function savings(retail){
    if(!active)return 0;const value=Number(active.discountValue||0);return active.discountMethod==='percent'?Math.round(retail*(value/100)*100)/100:Math.min(retail,Math.round(value*100)/100);
  }
  function normalizeSelections(){
    if(!active)return;
    const validGroups=new Set(active.groups.map(g=>g.id));Object.keys(selections).forEach(k=>{if(!validGroups.has(k))delete selections[k];});
    active.groups.forEach(group=>{const allowed=new Set((group.items||[]).filter(i=>i.available).map(i=>Number(i.priceId)));selections[group.id]=groupSelected(group).map(Number).filter(id=>allowed.has(id)).slice(0,Number(group.requiredQuantity));});
  }
  function render(){
    if(!active)return;normalizeSelections();
    $('#drawerTitle').textContent=active.name;$('#drawerSubtitle').textContent=`${active.discountMethod==='percent'?Number(active.discountValue)+'%':'$'+Number(active.discountValue).toFixed(2)} package savings · Pickup only`;
    $('#drawerGroups').innerHTML=active.groups.map((group,index)=>{
      const selected=totalSelected(group),need=Number(group.requiredQuantity),done=selected===need;
      return `<section class="drawer-group"><div class="drawer-group-head"><div><span>Selection ${index+1}</span><h3>${esc(group.label)}</h3></div><strong class="selection-count ${done?'done':''}">${selected} / ${need}</strong></div><div class="drawer-items">${(group.items||[]).filter(i=>i.available).map(item=>{const count=countPrice(group,item.priceId);return `<article class="drawer-item ${count?'chosen':''}"><div><strong>${esc(item.itemName)}</strong><span>${esc(item.optionName||'Standard')} · ${money(item.amount)}</span></div><div class="qty-controls"><button type="button" data-minus="${Number(item.priceId)}" data-group="${esc(group.id)}" ${count<1?'disabled':''}>−</button><b>${count}</b><button type="button" data-plus="${Number(item.priceId)}" data-group="${esc(group.id)}" ${selected>=need?'disabled':''}>+</button></div></article>`;}).join('')}</div></section>`;
    }).join('');
    $('#drawerGroups').querySelectorAll('[data-plus]').forEach(btn=>btn.addEventListener('click',()=>change(btn.dataset.group,Number(btn.dataset.plus),1)));
    $('#drawerGroups').querySelectorAll('[data-minus]').forEach(btn=>btn.addEventListener('click',()=>change(btn.dataset.group,Number(btn.dataset.minus),-1)));
    const retail=selectedRetail(),save=savings(retail);$('#drawerRetail').textContent=money(retail);$('#drawerSavings').textContent='−'+money(save);$('#drawerTotal').textContent=money(Math.max(0,retail-save));
    $('#packageSlug').value=active.slug;$('#packageSelections').value=JSON.stringify(selections);$('#packageSubmit').disabled=!packageComplete();
    persist();
  }
  function change(groupId,priceId,delta){
    const group=active.groups.find(g=>g.id===groupId);if(!group)return;const arr=groupSelected(group).slice();
    if(delta>0&&arr.length<Number(group.requiredQuantity))arr.push(priceId);
    if(delta<0){const index=arr.findIndex(v=>Number(v)===Number(priceId));if(index>=0)arr.splice(index,1);}
    selections[groupId]=arr;render();
  }
  function open(slug){
    active=packages.find(p=>p.slug===slug);if(!active)return;selections=restore(slug);normalizeSelections();
    $('#packageIdempotency').value=randomKey();backdrop.hidden=false;drawer.classList.add('open');drawer.setAttribute('aria-hidden','false');document.body.classList.add('package-drawer-open');render();
  }
  function close(){drawer.classList.remove('open');drawer.setAttribute('aria-hidden','true');backdrop.hidden=true;document.body.classList.remove('package-drawer-open');}
  document.querySelectorAll('[data-order-package]').forEach(btn=>btn.addEventListener('click',()=>open(btn.dataset.orderPackage)));
  $('#drawerClose').addEventListener('click',close);backdrop.addEventListener('click',close);document.addEventListener('keydown',e=>{if(e.key==='Escape')close();});
  $('#packageCheckout').addEventListener('submit',e=>{if(!packageComplete()){e.preventDefault();return;}persist();$('#packageSubmit').disabled=true;$('#packageSubmit').textContent=cfg.authenticated?'Placing Pickup Order…':'Opening Checkout…';});
  if(cfg.openPackage&&packages.some(p=>p.slug===cfg.openPackage))setTimeout(()=>open(cfg.openPackage),120);
})();
