(() => {
  'use strict';
  const cfg=window.GELATO_PACKAGE_ADMIN||{};
  const $=(s,r=document)=>r.querySelector(s);
  const esc=(v)=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const money=(n)=>new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(Number(n||0));
  const app=$('#packageAdminApp');
  if(!app) return;
  let packages=[],current=null,dirty=false,searchTimers=new Map();

  async function api(url,options={}){
    const response=await fetch(url,{credentials:'same-origin',cache:'no-store',...options});
    const payload=await response.json().catch(()=>({}));
    if(!response.ok||!payload.ok) throw new Error(payload.message||'The package request failed.');
    return payload;
  }
  async function post(action,extra={}){
    return api('api/packages.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({csrfToken:cfg.csrf,action,...extra})});
  }
  function alertError(error){ window.alert(error?.message||String(error)); }
  function dtLocal(value){ return value?String(value).replace(' ','T').slice(0,16):''; }
  function blankPackage(){return {id:'',slug:'',name:'',eyebrow:'',description:'',status:'draft',discountMethod:'percent',discountValue:10,pickupOnly:true,startsAt:null,endsAt:null,sortOrder:0,featured:false,groups:[],stats:{redemptions:0,retail:0,discounts:0,revenue:0},createdAt:null,updatedAt:null};}
  function markDirty(){dirty=true;$('#saveState').textContent='Unsaved changes';}
  function clearDirty(){dirty=false;$('#saveState').textContent='Saved';}

  function renderStats(){
    const visible=packages.filter(p=>p.status!=='archived');
    const count=(s)=>visible.filter(p=>p.status===s).length;
    const redemptions=packages.reduce((n,p)=>n+Number(p.stats?.redemptions||0),0);
    const revenue=packages.reduce((n,p)=>n+Number(p.stats?.revenue||0),0);
    $('#packageStats').innerHTML=[['Active',count('active')],['Paused',count('paused')],['Draft',count('draft')],['Ended',count('ended')],['Orders',redemptions],['Package revenue',money(revenue)]].map(([k,v])=>`<article><span>${esc(k)}</span><strong>${esc(v)}</strong></article>`).join('');
  }
  function renderList(){
    const showArchived=$('#showArchived').checked;
    const rows=packages.filter(p=>showArchived||p.status!=='archived');
    $('#packageCount').textContent=`${rows.length} package${rows.length===1?'':'s'}`;
    $('#packageList').innerHTML=rows.length?rows.map(p=>`<button type="button" class="package-row ${current?.id===p.id?'active':''}" data-id="${esc(p.id)}"><span class="row-status ${esc(p.status)}">${esc(p.status)}</span><strong>${esc(p.name)}</strong><small>${p.discountMethod==='percent'?`${Number(p.discountValue)}% off`:`${money(p.discountValue)} off`} · ${Number(p.stats?.redemptions||0)} orders</small><em>${money(p.stats?.revenue||0)} revenue</em></button>`).join(''):'<div class="list-empty">No package deals yet.</div>';
    $('#packageList').querySelectorAll('[data-id]').forEach(btn=>btn.addEventListener('click',()=>selectPackage(btn.dataset.id)));
  }
  function groupTemplate(group,index){
    const items=group.items||[];
    return `<article class="package-group" data-group-index="${index}">
      <div class="group-head"><div class="group-number">${index+1}</div><label><span>Category / selection name</span><input class="group-label" maxlength="140" value="${esc(group.label||'')}" placeholder="Pizza"></label><label class="qty-field"><span>Customer chooses</span><input class="group-qty" type="number" min="1" max="20" value="${Number(group.requiredQuantity||1)}"></label>${cfg.canManage?'<button class="remove-group" type="button" aria-label="Remove category">×</button>':''}</div>
      <div class="selected-items"><div class="selected-head"><strong>Eligible menu items</strong><span>${items.length} selected</span></div><div class="selected-chips">${items.length?items.map(i=>`<button type="button" class="selected-chip" data-remove-price="${Number(i.priceId)}" title="Remove ${esc(i.itemName)}"><span>${esc(i.itemName)}</span><small>${esc(i.optionName||'')} · ${money(i.amount)}</small><b>×</b></button>`).join(''):'<span class="empty-chip">Search the menu below and click items to add them.</span>'}</div></div>
      ${cfg.canManage?`<div class="menu-search"><label><span>Search live menu</span><input class="group-search" autocomplete="off" placeholder="Search pizza, drink, salad, gelato..."></label><div class="search-results"><span class="search-hint">Type to search the active restaurant menu.</span></div></div>`:''}
    </article>`;
  }
  function renderGroups(){
    const host=$('#packageGroups');host.innerHTML=(current.groups||[]).map(groupTemplate).join('')||'<div class="groups-empty">No categories yet. Add the first category to start building this package.</div>';
    host.querySelectorAll('.package-group').forEach(card=>{
      const index=Number(card.dataset.groupIndex),group=current.groups[index];
      card.querySelector('.group-label')?.addEventListener('input',e=>{group.label=e.target.value;markDirty();});
      card.querySelector('.group-qty')?.addEventListener('input',e=>{group.requiredQuantity=Math.max(1,Number(e.target.value||1));markDirty();});
      card.querySelector('.remove-group')?.addEventListener('click',()=>{if(confirm('Remove this category and its eligible items?')){current.groups.splice(index,1);markDirty();renderGroups();}});
      card.querySelectorAll('[data-remove-price]').forEach(btn=>btn.addEventListener('click',()=>{group.items=group.items.filter(i=>Number(i.priceId)!==Number(btn.dataset.removePrice));markDirty();renderGroups();}));
      const search=card.querySelector('.group-search');
      if(search) search.addEventListener('input',()=>{
        clearTimeout(searchTimers.get(index));
        searchTimers.set(index,setTimeout(()=>searchMenu(index,search.value,card.querySelector('.search-results')),220));
      });
    });
  }
  async function searchMenu(index,q,host){
    q=String(q||'').trim();if(!q){host.innerHTML='<span class="search-hint">Type to search the active restaurant menu.</span>';return;}
    host.innerHTML='<span class="search-hint">Searching…</span>';
    try{
      const payload=await api(`api/packages.php?action=menu.search&q=${encodeURIComponent(q)}`);
      const selected=new Set((current.groups[index].items||[]).map(i=>Number(i.priceId)));
      host.innerHTML=payload.items.length?payload.items.map(i=>`<button type="button" class="search-result ${selected.has(Number(i.priceId))?'selected':''}" data-price="${Number(i.priceId)}"><span><strong>${esc(i.itemName)}</strong><small>${esc(i.sectionName)} · ${esc(i.optionName||'Standard')}</small></span><em>${money(i.amount)}</em><b>${selected.has(Number(i.priceId))?'✓':'+'}</b></button>`).join(''):'<span class="search-hint">No matching active menu items.</span>';
      host.querySelectorAll('[data-price]').forEach(btn=>btn.addEventListener('click',()=>{
        const item=payload.items.find(i=>Number(i.priceId)===Number(btn.dataset.price));
        if(!item||selected.has(Number(item.priceId)))return;
        current.groups[index].items.push(item);markDirty();renderGroups();
      }));
    }catch(error){host.innerHTML=`<span class="search-error">${esc(error.message)}</span>`;}
  }
  function lifecycleButtons(){
    if(!cfg.canManage||!current?.id)return '';
    const s=current.status,buttons=[];
    if(s==='draft')buttons.push(['activate','Activate Deal','primary']);
    if(s==='active')buttons.push(['pause','Pause Deal','']);
    if(s==='paused')buttons.push(['resume','Resume Deal','primary']);
    if(['draft','active','paused'].includes(s))buttons.push(['end','End Deal','warn']);
    if(s!=='archived')buttons.push(['duplicate','Duplicate Package','']);
    if(s!=='archived')buttons.push(['archive','Archive Deal','danger']);
    return buttons.map(([a,l,c])=>`<button type="button" class="btn ${c}" data-lifecycle="${a}">${l}</button>`).join('');
  }
  function renderEditor(){
    $('#editorEmpty').hidden=true;$('#packageForm').hidden=false;
    $('#packageId').value=current.id||'';$('#packageName').value=current.name||'';$('#packageEyebrow').value=current.eyebrow||'';$('#packageDescription').value=current.description||'';
    $('#discountMethod').value=current.discountMethod||'percent';$('#discountValue').value=Number(current.discountValue||0);$('#startsAt').value=dtLocal(current.startsAt);$('#endsAt').value=dtLocal(current.endsAt);$('#featured').checked=!!current.featured;
    $('#statusPill').className=`status-pill ${current.status}`;$('#statusPill').textContent=current.status;$('#editorTitle').textContent=current.id?current.name||'Package':'New Package';
    $('#editorMeta').textContent=current.id?`${current.slug} · ${Number(current.stats?.redemptions||0)} orders · ${money(current.stats?.revenue||0)} revenue`:'Draft not saved yet';
    $('#lifecycleActions').innerHTML=lifecycleButtons();
    $('#lifecycleActions').querySelectorAll('[data-lifecycle]').forEach(btn=>btn.addEventListener('click',()=>lifecycle(btn.dataset.lifecycle)));
    renderGroups();renderList();
  }
  function selectPackage(id){
    if(dirty&&!confirm('Discard unsaved package changes?'))return;
    current=structuredClone(packages.find(p=>p.id===id));dirty=false;renderEditor();clearDirty();
  }
  function newPackage(){
    if(dirty&&!confirm('Discard unsaved package changes?'))return;
    current=blankPackage();dirty=false;renderEditor();$('#packageName').focus();$('#saveState').textContent='New draft';
  }
  function formPayload(){
    return {name:$('#packageName').value,eyebrow:$('#packageEyebrow').value,description:$('#packageDescription').value,discountMethod:$('#discountMethod').value,discountValue:Number($('#discountValue').value||0),startsAt:$('#startsAt').value||null,endsAt:$('#endsAt').value||null,featured:$('#featured').checked,groups:(current.groups||[]).map((g,i)=>({label:g.label,requiredQuantity:Number(g.requiredQuantity||1),sortOrder:i,priceIds:(g.items||[]).map(x=>Number(x.priceId))}))};
  }
  async function save(event){
    event.preventDefault();if(!cfg.canManage)return;
    const button=event.submitter||$('#packageForm button[type="submit"]');button.disabled=true;$('#saveState').textContent='Saving…';
    try{
      const payload=await post('save',{id:current.id||'',package:formPayload()});current=payload.package;dirty=false;await load(false,current.id);clearDirty();
    }catch(error){alertError(error);$('#saveState').textContent='Save failed';}finally{button.disabled=false;}
  }
  async function lifecycle(action){
    if(dirty&&!confirm('This package has unsaved changes. Continue without saving them?'))return;
    const label=action==='duplicate'?'Duplicate this package?':`${action[0].toUpperCase()+action.slice(1)} this package deal?`;
    if(!confirm(label))return;
    try{
      const payload=await post(action,{id:current.id});current=payload.package;dirty=false;await load(false,current.id);clearDirty();
    }catch(error){alertError(error);}
  }
  async function load(first=true,selectId=''){
    try{
      const payload=await api(`api/packages.php${$('#showArchived')?.checked?'?archived=1':''}`);packages=payload.packages||[];renderStats();
      if(selectId){const found=packages.find(p=>p.id===selectId);if(found)current=structuredClone(found);}
      if(current?.id){const found=packages.find(p=>p.id===current.id);if(found)current=structuredClone(found);}
      renderList();if(current)renderEditor();
      if(first&&new URLSearchParams(location.search).get('action')==='add')newPackage();
    }catch(error){alertError(error);}
  }

  $('#packageForm').addEventListener('submit',save);
  ['#packageName','#packageEyebrow','#packageDescription','#discountMethod','#discountValue','#startsAt','#endsAt','#featured'].forEach(sel=>$(sel)?.addEventListener('input',markDirty));
  $('#addGroup')?.addEventListener('click',()=>{current.groups.push({id:'',label:'',requiredQuantity:1,sortOrder:current.groups.length,items:[]});markDirty();renderGroups();setTimeout(()=>$('#packageGroups .package-group:last-child .group-label')?.focus(),0);});
  $('#newPackage')?.addEventListener('click',newPackage);$('#newPackageTop')?.addEventListener('click',newPackage);
  $('#showArchived').addEventListener('change',()=>load(false,current?.id||''));
  load(true);
})();
