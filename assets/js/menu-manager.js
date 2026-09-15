(()=>{
'use strict';

const root=document.getElementById('menuManager');
if(!root)return;

const config=window.GELATO_MENU_MANAGER||{};
const csrf=String(config.csrf||root.dataset.csrf||'');
const canManage=Boolean(config.canManage);
const $=selector=>document.querySelector(selector);
const escapeHtml=value=>String(value??'').replace(/[&<>"']/g,ch=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[ch]));
const money=value=>new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(Number(value||0));
const sizeKey=()=>`size-${Date.now().toString(36)}-${Math.random().toString(36).slice(2,8)}`;

const ui={
  stats:$('#menuStats'),search:$('#menuSearch'),categoryFilter:$('#categoryFilter'),showArchived:$('#showArchived'),categoryList:$('#categoryList'),itemGrid:$('#itemGrid'),itemsTitle:$('#itemsTitle'),
  builder:$('#foodBuilder'),steps:$('#foodSteps'),canvas:$('#foodCanvas'),preview:$('#foodPreview'),builderTitle:$('#foodBuilderTitle'),builderStatus:$('#foodBuilderStatus'),lifecycle:$('#foodLifecycleActions'),saveState:$('#foodSaveState'),
  prev:$('#foodPrevious'),next:$('#foodNext'),save:$('#foodSave'),publish:$('#foodPublish'),categoryDialog:$('#categoryDialog')
};

const steps=[
  ['Item','Name, category & kitchen'],
  ['Sizes & Prices','Sellable variants'],
  ['Included ingredients','What comes on the item'],
  ['Paid add-ons & upgrades','Extra cheese, chicken, double toppings'],
  ['Distribution & availability','Where this item can be sold'],
  ['Preview & publish','Review and publish']
];

let state={categories:[],items:[],categoryId:0,query:'',archived:false};
let editor={item:null,step:0,dirty:false,ingredientResults:[]};

async function apiGet(params){
  const url=new URL('api/menu-manager.php',window.location.href);
  Object.entries(params).forEach(([key,value])=>{if(value!==''&&value!==null&&value!==undefined)url.searchParams.set(key,String(value));});
  const response=await fetch(url,{headers:{Accept:'application/json'},cache:'no-store'});
  const data=await response.json().catch(()=>({ok:false,message:'Invalid restaurant response.'}));
  if(!response.ok||!data.ok)throw new Error(data.message||'Menu request failed.');
  return data;
}

async function apiPost(payload){
  const response=await fetch('api/menu-manager.php',{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({...payload,csrfToken:csrf})});
  const data=await response.json().catch(()=>({ok:false,message:'Invalid restaurant response.'}));
  if(!response.ok||!data.ok)throw new Error(data.message||'Menu action failed.');
  return data;
}

function setStatus(message,isError=false){
  if(!ui.saveState)return;
  ui.saveState.textContent=message;
  ui.saveState.style.color=isError?'#9c3025':'#287044';
}

async function loadState(){
  try{
    const data=await apiGet({action:'state',q:state.query,categoryId:state.categoryId||'',archived:state.archived?1:''});
    state.categories=data.categories||[];
    state.items=data.items||[];
    renderMenuManager();
  }catch(error){
    ui.itemGrid.innerHTML=`<div class="mm-empty">${escapeHtml(error.message)}</div>`;
  }
}

function renderMenuManager(){
  const published=state.items.filter(item=>item.lifecycleStatus==='published').length;
  const drafts=state.items.filter(item=>item.lifecycleStatus==='draft').length;
  const paused=state.items.filter(item=>item.lifecycleStatus==='paused').length;
  ui.stats.innerHTML=`
    <article class="mm-stat"><span>Matching items</span><strong>${state.items.length}</strong></article>
    <article class="mm-stat"><span>Published</span><strong>${published}</strong></article>
    <article class="mm-stat"><span>Drafts</span><strong>${drafts}</strong></article>
    <article class="mm-stat"><span>Paused</span><strong>${paused}</strong></article>`;

  ui.categoryFilter.innerHTML='<option value="">All categories</option>'+state.categories.filter(c=>c.status==='active').map(c=>`<option value="${c.id}" ${Number(c.id)===Number(state.categoryId)?'selected':''}>${escapeHtml(c.name)}</option>`).join('');

  ui.categoryList.innerHTML=`<button class="category-row ${!state.categoryId?'active':''}" type="button" data-category="0"><div><strong>All categories</strong><span>Show matching menu items</span></div></button>`+
    state.categories.map(category=>`<div class="category-row ${Number(category.id)===Number(state.categoryId)?'active':''}"><button type="button" data-category="${category.id}" style="border:0;background:transparent;text-align:left;flex:1;cursor:pointer"><strong>${escapeHtml(category.name)}</strong><span>${escapeHtml(category.status)} · order ${category.sortOrder}</span></button>${canManage?`<button class="category-edit" type="button" data-edit-category="${category.id}">Edit</button>`:''}</div>`).join('');

  const currentCategory=state.categories.find(c=>Number(c.id)===Number(state.categoryId));
  ui.itemsTitle.textContent=currentCategory?currentCategory.name:'All Menu Items';

  if(!state.items.length){
    ui.itemGrid.innerHTML='<div class="mm-empty"><strong>No menu items match this view.</strong><br>Use + Add Food to create one.</div>';
    return;
  }

  ui.itemGrid.innerHTML=state.items.map(item=>{
    let price='No price';
    if(item.priceCount){price=item.minPrice===item.maxPrice?money(item.minPrice):`${money(item.minPrice)}–${money(item.maxPrice)}`;}
    const channels=Object.entries(item.distribution||{}).filter(([,enabled])=>enabled).map(([name])=>name.replace(/([A-Z])/g,' $1')).slice(0,4);
    return `<article class="menu-card">
      <div class="menu-card-top"><span class="status-pill ${escapeHtml(item.lifecycleStatus)}">${escapeHtml(item.lifecycleStatus)}</span><span class="menu-chip">${escapeHtml(item.categoryName)}</span></div>
      <h3>${escapeHtml(item.name)}</h3><p>${escapeHtml(item.description||'No description yet.')}</p>
      <div class="menu-card-meta">${channels.map(name=>`<span class="menu-chip">${escapeHtml(name)}</span>`).join('')}</div>
      <div class="menu-card-footer"><div class="menu-price"><small>${item.priceCount} size${item.priceCount===1?'':'s'}</small>${escapeHtml(price)}</div><button class="menu-edit" type="button" data-edit-item="${item.id}">${canManage?'Edit':'View'}</button></div>
    </article>`;
  }).join('');
}

function blankItem(){
  const category=state.categories.find(c=>c.status==='active');
  return {
    id:0,name:'',slug:'',description:'',preparationNotes:'',categoryId:category?Number(category.id):0,categoryName:category?.name||'',
    profile:{itemType:'food',lifecycleStatus:'draft',kitchenStation:'',publicMenu:true,onlineOrder:true,pos:true,packages:true,catering:false},
    sizes:[{id:0,clientKey:sizeKey(),label:'',sizeCode:'',amount:0,sortOrder:0,active:true}],ingredients:[],modifierGroups:[],locations:[],locationAvailability:{}
  };
}

function normalizeItem(item){
  const priceKeys=new Map();
  const sizes=(item.sizes||[]).filter(size=>size.active!==false).map((size,index)=>{
    const clientKey=size.clientKey||`price-${size.id||index}`;
    if(size.id)priceKeys.set(Number(size.id),clientKey);
    return {...size,clientKey};
  });
  const modifierGroups=(item.modifierGroups||[]).map(group=>({...group,options:(group.options||[]).map(option=>{
    const priceDeltas={};
    (option.sizePrices||[]).forEach(row=>{const key=priceKeys.get(Number(row.priceId));if(key)priceDeltas[key]=Number(row.amountDelta||0);});
    return {...option,priceDeltas};
  })}));
  const locationAvailability={};
  (item.locations||[]).forEach(location=>{locationAvailability[String(location.id)]=Boolean(location.available);});
  return {...item,sizes,modifierGroups,locationAvailability};
}

async function openItem(id=0){
  try{
    editor.step=0;editor.dirty=false;editor.ingredientResults=[];
    editor.item=id?normalizeItem((await apiGet({action:'item',id})).item):blankItem();
    if(id===0&&(!editor.item.locations||!editor.item.locations.length)){
      const seed=state.items[0];
      if(seed){
        const details=normalizeItem((await apiGet({action:'item',id:seed.id})).item);
        editor.item.locations=details.locations||[];
        editor.item.locationAvailability={};
        details.locations.forEach(location=>{editor.item.locationAvailability[String(location.id)]=true;});
      }
    }
    ui.builder.classList.add('open');
    ui.builder.setAttribute('aria-hidden','false');
    document.body.style.overflow='hidden';
    renderBuilder();
  }catch(error){alert(error.message);}
}

function closeBuilder(){
  if(editor.dirty&&!confirm('Close without saving these menu changes?'))return;
  ui.builder.classList.remove('open');ui.builder.setAttribute('aria-hidden','true');document.body.style.overflow='';editor.item=null;
}

function markDirty(){editor.dirty=true;setStatus('Unsaved changes.');renderPreview();}

function renderBuilder(){
  if(!editor.item)return;
  ui.steps.innerHTML=steps.map((step,index)=>`<button class="fb-step ${editor.step===index?'active':''}" type="button" data-step="${index}"><b>${index+1}</b><span>${escapeHtml(step[0])}</span></button>`).join('');
  ui.builderTitle.textContent=editor.item.name||'New Food Item';
  ui.builderStatus.textContent=`${editor.item.profile?.lifecycleStatus||'draft'}${editor.item.id?' · item #'+editor.item.id:' · not saved'}`;
  ui.prev.disabled=editor.step===0;ui.next.hidden=editor.step===steps.length-1;ui.next.disabled=editor.step===steps.length-1;
  renderLifecycle();renderCanvas();renderPreview();
}

function renderLifecycle(){
  if(!editor.item||!editor.item.id||!canManage){ui.lifecycle.innerHTML='';return;}
  const status=editor.item.profile.lifecycleStatus;
  let html='<button class="mm-btn" type="button" data-life="duplicate">Duplicate Food</button>';
  if(status==='published')html+='<button class="mm-btn" type="button" data-life="pause">Pause</button>';
  if(status==='paused'||status==='draft')html+='<button class="mm-btn publish" type="button" data-life="publish">Publish</button>';
  if(status!=='archived')html+='<button class="mm-btn danger" type="button" data-life="archive">Archive</button>';
  ui.lifecycle.innerHTML=html;
}

function heading(kicker,title,copy){return `<div class="fb-section-head"><span class="mm-eyebrow">${escapeHtml(kicker)}</span><h3>${escapeHtml(title)}</h3><p>${escapeHtml(copy)}</p></div>`;}

function renderCanvas(){
  const functions=[renderItemStep,renderSizesStep,renderIngredientsStep,renderAddonsStep,renderDistributionStep,renderReviewStep];
  ui.canvas.innerHTML=functions[editor.step](editor.item);
  bindCanvas();
}

function renderItemStep(item){
  return `<div class="fb-section">${heading('Step 1','Food item','Define the menu identity once. The same item can flow to every enabled sales channel.')}
    <div class="fb-grid">
      <label class="fb-field"><span>Food item name</span><input data-basic="name" value="${escapeHtml(item.name)}" maxlength="200" placeholder="Pepperoni Pizza"></label>
      <label class="fb-field"><span>Category</span><select data-basic="categoryId"><option value="">Choose category</option>${state.categories.filter(c=>c.status==='active').map(c=>`<option value="${c.id}" ${Number(item.categoryId)===Number(c.id)?'selected':''}>${escapeHtml(c.name)}</option>`).join('')}</select></label>
      <label class="fb-field full"><span>Description</span><textarea data-basic="description" rows="4" maxlength="8000">${escapeHtml(item.description)}</textarea></label>
      <label class="fb-field full"><span>Kitchen / preparation notes</span><textarea data-basic="preparationNotes" rows="3" maxlength="8000">${escapeHtml(item.preparationNotes)}</textarea></label>
      <label class="fb-field"><span>Kitchen station</span><input data-profile="kitchenStation" value="${escapeHtml(item.profile?.kitchenStation||'')}" maxlength="120" placeholder="Pizza / Oven"></label>
    </div><div class="fb-warning"><strong>One canonical item.</strong> Do not create separate website, POS and package copies.</div></div>`;
}

function renderSizesStep(item){
  return `<div class="fb-section">${heading('Step 2','Sizes & Prices','Create Small, Medium, Large, 20 oz or any other sellable price variant.')}
    ${item.sizes.map((size,index)=>`<div class="fb-card fb-row" data-size-index="${index}"><label class="fb-field"><span>Size / option</span><input data-size-field="label" value="${escapeHtml(size.label)}" placeholder="Medium"></label><label class="fb-field"><span>Internal code</span><input data-size-field="sizeCode" value="${escapeHtml(size.sizeCode||'')}" placeholder="medium"></label><label class="fb-field"><span>Price</span><input data-size-field="amount" type="number" min="0" step="0.01" value="${Number(size.amount||0).toFixed(2)}"></label><button class="fb-remove" type="button" data-remove-size="${index}" ${item.sizes.length===1?'disabled':''}>×</button></div>`).join('')}
    <button class="fb-add" type="button" id="addSize">+ Add Size / Price</button><p class="fb-note">Removing a size closes its availability instead of deleting historical order references.</p></div>`;
}

function renderIngredientsStep(item){
  return `<div class="fb-section">${heading('Step 3','Included ingredients','Define what normally comes on the item and whether the customer may remove it.')}
    <div class="ingredient-search"><input class="fb-input" id="ingredientSearch" placeholder="Search ingredient library"><button class="mm-btn" type="button" id="ingredientSearchBtn">Search</button><button class="mm-btn" type="button" id="ingredientNewBtn">+ Use typed name</button></div>
    <div class="ingredient-results">${editor.ingredientResults.map(ingredient=>`<button class="ingredient-result" type="button" data-add-ingredient="${ingredient.id}" data-name="${escapeHtml(ingredient.name)}">+ ${escapeHtml(ingredient.name)}</button>`).join('')}</div>
    ${item.ingredients.length?item.ingredients.map((ingredient,index)=>`<div class="fb-card fb-row ingredient" data-ingredient-index="${index}"><label class="fb-field"><span>Ingredient</span><input data-ingredient-field="name" value="${escapeHtml(ingredient.name)}"></label><label class="mm-check"><input type="checkbox" data-ingredient-field="canRemove" ${ingredient.canRemove!==false?'checked':''}> Removable</label><label class="mm-check"><input type="checkbox" data-ingredient-field="optional" ${ingredient.optional?'checked':''}> Optional</label><button class="fb-remove" type="button" data-remove-ingredient="${index}">×</button></div>`).join(''):'<div class="fb-card"><span class="fb-note">No included ingredients yet.</span></div>'}
  </div>`;
}

function renderAddonsStep(item){
  return `<div class="fb-section">${heading('Step 4','Paid add-ons & upgrades','Use this for Add Chicken, Double Pepperoni, Extra Cheese and similar paid modifications. Add-on prices may vary by size.')}
    ${item.modifierGroups.map((group,groupIndex)=>`<article class="fb-card" data-group-index="${groupIndex}"><div class="fb-card-head"><div><span class="mm-eyebrow">Add-on group</span><h4>${escapeHtml(group.name||'Food Add-ons')}</h4></div><button class="fb-remove" type="button" data-remove-group="${groupIndex}">×</button></div>
      <div class="group-settings"><label class="fb-field"><span>Group name</span><input data-group-field="name" value="${escapeHtml(group.name||'')}"></label><label class="fb-field"><span>Min</span><input data-group-field="minSelect" type="number" min="0" max="50" value="${Number(group.minSelect||0)}"></label><label class="fb-field"><span>Max</span><input data-group-field="maxSelect" type="number" min="1" max="50" value="${Number(group.maxSelect||10)}"></label><label class="fb-field"><span>Type</span><select data-group-field="type"><option value="add_on" ${(group.type||'add_on')==='add_on'?'selected':''}>Paid add-ons</option><option value="choice" ${group.type==='choice'?'selected':''}>Choice / modifier</option></select></label></div>
      ${(group.options||[]).map((option,optionIndex)=>`<div class="fb-card" data-option-index="${optionIndex}"><div class="fb-row option"><label class="fb-field"><span>Add-on</span><input data-option-field="name" value="${escapeHtml(option.name||'')}" placeholder="Extra cheese"></label><label class="fb-field"><span>Default +$</span><input data-option-field="defaultPriceDelta" type="number" min="0" step="0.01" value="${Number(option.defaultPriceDelta||0).toFixed(2)}"></label><label class="fb-field"><span>Max qty</span><input data-option-field="maxQuantity" type="number" min="1" max="20" value="${Number(option.maxQuantity||1)}"></label><button class="fb-remove" type="button" data-remove-option="${optionIndex}">×</button></div><div class="modifier-price-grid">${item.sizes.map(size=>`<label><span>${escapeHtml(size.label||'Size')} +$</span><input class="fb-input" data-size-delta="${escapeHtml(size.clientKey)}" type="number" min="0" step="0.01" value="${Number(option.priceDeltas?.[size.clientKey]??option.defaultPriceDelta??0).toFixed(2)}"></label>`).join('')}</div></div>`).join('')}
      <button class="fb-add" type="button" data-add-option="${groupIndex}">+ Add Paid Add-on</button></article>`).join('')}
    <button class="fb-add" type="button" id="addModifierGroup">+ Add Add-on Group</button>
    <div class="fb-warning" style="margin-top:12px"><strong>Add-ons stay attached to the food item.</strong> Extra cheese, chicken or double pepperoni belong here; unrelated side products remain separate menu items.</div>
  </div>`;
}

function renderDistributionStep(item){
  const channels=[
    ['publicMenu','Public Menu','Show on the customer-facing menu.'],['onlineOrder','Online Ordering','Available for online pickup ordering.'],['pos','POS','Available to restaurant staff at the point of sale.'],['packages','Package Deals','May be selected in package builders.'],['catering','Catering','Available to catering workflows.']
  ];
  return `<div class="fb-section">${heading('Step 5','Distribution & availability','Choose where this single canonical item can be sold and at which locations.')}
    <div class="distribution-grid">${channels.map(([key,title,copy])=>`<label class="distribution-card"><input type="checkbox" data-distribution="${key}" ${item.profile?.[key]?'checked':''}><div><strong>${title}</strong><span>${copy}</span></div></label>`).join('')}</div>
    <h4 style="margin:22px 0 8px">Location availability</h4><div class="locations-grid">${(item.locations||[]).length?item.locations.map(location=>`<label class="location-toggle"><input type="checkbox" data-location="${location.id}" ${item.locationAvailability?.[String(location.id)]!==false?'checked':''}><span>${escapeHtml(location.name)}${location.status!=='active'?' · '+escapeHtml(location.status):''}</span></label>`).join(''):'<div class="fb-card"><span class="fb-note">No per-location overrides are configured; organization-wide availability applies.</span></div>'}</div>
  </div>`;
}

function previewMarkup(item){
  const category=state.categories.find(c=>Number(c.id)===Number(item.categoryId));
  const addons=item.modifierGroups.flatMap(group=>(group.options||[]).map(option=>{
    const values=item.sizes.map(size=>Number(option.priceDeltas?.[size.clientKey]??option.defaultPriceDelta??0));
    const minimum=values.length?Math.min(...values):Number(option.defaultPriceDelta||0);
    return `<li>${escapeHtml(option.name)} · from ${money(minimum)}</li>`;
  })).join('');
  return `<article class="preview-card"><span class="preview-category">${escapeHtml(category?.name||item.categoryName||'Food')}</span><h3>${escapeHtml(item.name||'New food item')}</h3><p>${escapeHtml(item.description||'Add a customer-facing description.')}</p><div class="preview-prices">${item.sizes.map(size=>`<div class="preview-price"><strong>${escapeHtml(size.label||'Size')}</strong><span>${money(size.amount)}</span></div>`).join('')||'<div class="preview-price">No sizes configured</div>'}</div><div class="preview-block"><strong>Includes</strong><ul>${item.ingredients.map(i=>`<li>${escapeHtml(i.name)}</li>`).join('')||'<li>No included ingredients configured</li>'}</ul></div><div class="preview-block"><strong>Paid add-ons</strong><ul>${addons||'<li>No paid add-ons configured</li>'}</ul></div></article>`;
}

function renderReviewStep(item){
  const flags=[['Public menu',item.profile.publicMenu],['Online',item.profile.onlineOrder],['POS',item.profile.pos],['Packages',item.profile.packages],['Catering',item.profile.catering]];
  return `<div class="fb-section">${heading('Step 6','Preview & publish','Review this food item, save it as a draft, or publish it when ready to sell.')}<div class="fb-status-bar">${flags.map(([name,on])=>`<span class="fb-status-chip ${on?'on':''}">${escapeHtml(name)} ${on?'✓':'—'}</span>`).join('')}</div>${previewMarkup(item)}<div class="fb-warning" style="margin-top:14px"><strong>${item.profile.lifecycleStatus==='published'?'Currently published.':'Not yet published.'}</strong> Publishing requires an active category and at least one valid size / price.</div></div>`;
}

function renderPreview(){
  if(!editor.item)return;
  ui.preview.innerHTML=`<span class="mm-eyebrow">Live preview</span><h3 style="font:700 22px Georgia,serif;margin:6px 0 13px">Customer menu card</h3>${previewMarkup(editor.item)}`;
}

function bindCanvas(){
  const item=editor.item;
  if(!item)return;

  ui.canvas.querySelectorAll('[data-basic]').forEach(input=>input.addEventListener('input',()=>{
    const field=input.dataset.basic;item[field]=field==='categoryId'?Number(input.value):input.value;
    if(field==='categoryId'){const category=state.categories.find(c=>Number(c.id)===Number(input.value));item.categoryName=category?.name||'';}
    markDirty();
  }));
  ui.canvas.querySelectorAll('[data-profile]').forEach(input=>input.addEventListener('input',()=>{item.profile[input.dataset.profile]=input.value;markDirty();}));

  ui.canvas.querySelectorAll('[data-size-index]').forEach(row=>{
    const index=Number(row.dataset.sizeIndex);
    row.querySelectorAll('[data-size-field]').forEach(input=>input.addEventListener('input',()=>{
      const field=input.dataset.sizeField;item.sizes[index][field]=field==='amount'?Number(input.value||0):input.value;
      if(field==='label'&&!item.sizes[index].sizeCode)item.sizes[index].sizeCode=String(input.value).toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');
      markDirty();
    }));
  });
  ui.canvas.querySelectorAll('[data-remove-size]').forEach(button=>button.addEventListener('click',()=>{
    if(item.sizes.length<=1)return;
    const removed=item.sizes.splice(Number(button.dataset.removeSize),1)[0];
    item.modifierGroups.forEach(group=>(group.options||[]).forEach(option=>{if(option.priceDeltas)delete option.priceDeltas[removed.clientKey];}));
    markDirty();renderCanvas();renderPreview();
  }));
  $('#addSize')?.addEventListener('click',()=>{item.sizes.push({id:0,clientKey:sizeKey(),label:'',sizeCode:'',amount:0,sortOrder:item.sizes.length,active:true});markDirty();renderCanvas();renderPreview();});

  ui.canvas.querySelectorAll('[data-ingredient-index]').forEach(row=>{
    const index=Number(row.dataset.ingredientIndex);
    row.querySelectorAll('[data-ingredient-field]').forEach(input=>input.addEventListener('input',()=>{
      item.ingredients[index][input.dataset.ingredientField]=input.type==='checkbox'?input.checked:input.value;markDirty();
    }));
  });
  ui.canvas.querySelectorAll('[data-remove-ingredient]').forEach(button=>button.addEventListener('click',()=>{item.ingredients.splice(Number(button.dataset.removeIngredient),1);markDirty();renderCanvas();renderPreview();}));
  $('#ingredientSearchBtn')?.addEventListener('click',searchIngredients);
  $('#ingredientSearch')?.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();searchIngredients();}});
  $('#ingredientNewBtn')?.addEventListener('click',()=>{const name=String($('#ingredientSearch')?.value||'').trim();if(name)addIngredient({id:0,name});});
  ui.canvas.querySelectorAll('[data-add-ingredient]').forEach(button=>button.addEventListener('click',()=>addIngredient({id:Number(button.dataset.addIngredient),name:button.dataset.name||''})));

  ui.canvas.querySelectorAll('[data-group-index]').forEach(groupElement=>{
    const groupIndex=Number(groupElement.dataset.groupIndex);const group=item.modifierGroups[groupIndex];
    groupElement.querySelectorAll('[data-group-field]').forEach(input=>input.addEventListener('input',()=>{const field=input.dataset.groupField;group[field]=['minSelect','maxSelect'].includes(field)?Number(input.value||0):input.value;markDirty();}));
    groupElement.querySelectorAll('[data-option-index]').forEach(optionElement=>{
      const optionIndex=Number(optionElement.dataset.optionIndex);const option=group.options[optionIndex];
      optionElement.querySelectorAll('[data-option-field]').forEach(input=>input.addEventListener('input',()=>{const field=input.dataset.optionField;option[field]=field==='defaultPriceDelta'?Number(input.value||0):field==='maxQuantity'?Number(input.value||1):input.value;markDirty();}));
      optionElement.querySelectorAll('[data-size-delta]').forEach(input=>input.addEventListener('input',()=>{option.priceDeltas=option.priceDeltas||{};option.priceDeltas[input.dataset.sizeDelta]=Number(input.value||0);markDirty();}));
    });
  });
  ui.canvas.querySelectorAll('[data-remove-group]').forEach(button=>button.addEventListener('click',()=>{item.modifierGroups.splice(Number(button.dataset.removeGroup),1);markDirty();renderCanvas();renderPreview();}));
  ui.canvas.querySelectorAll('[data-add-option]').forEach(button=>button.addEventListener('click',()=>{
    const group=item.modifierGroups[Number(button.dataset.addOption)];const priceDeltas={};item.sizes.forEach(size=>{priceDeltas[size.clientKey]=0;});
    group.options=group.options||[];group.options.push({name:'',defaultPriceDelta:0,maxQuantity:1,priceDeltas,sortOrder:group.options.length});markDirty();renderCanvas();renderPreview();
  }));
  ui.canvas.querySelectorAll('[data-remove-option]').forEach(button=>button.addEventListener('click',()=>{
    const groupElement=button.closest('[data-group-index]');const optionElement=button.closest('[data-option-index]');
    item.modifierGroups[Number(groupElement.dataset.groupIndex)].options.splice(Number(optionElement.dataset.optionIndex),1);markDirty();renderCanvas();renderPreview();
  }));
  $('#addModifierGroup')?.addEventListener('click',()=>{item.modifierGroups.push({name:'Food Add-ons',type:'add_on',minSelect:0,maxSelect:10,sortOrder:item.modifierGroups.length,options:[]});markDirty();renderCanvas();renderPreview();});

  ui.canvas.querySelectorAll('[data-distribution]').forEach(input=>input.addEventListener('change',()=>{item.profile[input.dataset.distribution]=input.checked;markDirty();}));
  ui.canvas.querySelectorAll('[data-location]').forEach(input=>input.addEventListener('change',()=>{item.locationAvailability[String(input.dataset.location)]=input.checked;markDirty();}));
}

async function searchIngredients(){
  try{editor.ingredientResults=(await apiGet({action:'ingredients',q:$('#ingredientSearch')?.value||''})).ingredients||[];renderCanvas();}
  catch(error){setStatus(error.message,true);}
}

function addIngredient(ingredient){
  const duplicate=editor.item.ingredients.some(row=>(ingredient.id&&Number(row.id)===Number(ingredient.id))||String(row.name).toLowerCase()===String(ingredient.name).toLowerCase());
  if(duplicate)return;
  editor.item.ingredients.push({id:Number(ingredient.id||0),name:String(ingredient.name||''),optional:false,canRemove:true,sortOrder:editor.item.ingredients.length});editor.ingredientResults=[];markDirty();renderCanvas();renderPreview();
}

function buildPayload(item){
  return {
    name:item.name,slug:item.slug,categoryId:Number(item.categoryId),description:item.description,preparationNotes:item.preparationNotes,kitchenStation:item.profile.kitchenStation,
    distribution:{publicMenu:!!item.profile.publicMenu,onlineOrder:!!item.profile.onlineOrder,pos:!!item.profile.pos,packages:!!item.profile.packages,catering:!!item.profile.catering},
    sizes:item.sizes.map((size,index)=>({id:Number(size.id||0),clientKey:size.clientKey,label:size.label,sizeCode:size.sizeCode,amount:Number(size.amount||0),sortOrder:index})),
    ingredients:item.ingredients.map((ingredient,index)=>({id:Number(ingredient.id||0),name:ingredient.name,optional:!!ingredient.optional,canRemove:ingredient.canRemove!==false,sortOrder:index})),
    modifierGroups:item.modifierGroups.map((group,groupIndex)=>({name:group.name,type:group.type||'add_on',minSelect:Number(group.minSelect||0),maxSelect:Number(group.maxSelect||10),sortOrder:groupIndex,options:(group.options||[]).map((option,optionIndex)=>({name:option.name,ingredientId:Number(option.ingredientId||0),ingredientName:option.ingredientName||'',defaultPriceDelta:Number(option.defaultPriceDelta||0),maxQuantity:Number(option.maxQuantity||1),sortOrder:optionIndex,priceDeltas:option.priceDeltas||{}}))})),
    locationAvailability:item.locationAvailability||{}
  };
}

async function saveItem(publish=false){
  if(!canManage||!editor.item)return;
  try{
    setStatus('Saving…');
    let result=await apiPost({action:'item.save',id:Number(editor.item.id||0),item:buildPayload(editor.item)});
    editor.item=normalizeItem(result.item);editor.dirty=false;
    if(publish&&editor.item.profile.lifecycleStatus!=='published'){
      result=await apiPost({action:'item.publish',id:editor.item.id});editor.item=normalizeItem(result.item);
    }
    await loadState();setStatus(publish?'Food item published.':'Food item saved.');renderBuilder();
  }catch(error){setStatus(error.message,true);alert(error.message);}
}

async function lifecycle(action){
  if(!editor.item?.id)return;
  try{
    if(action==='duplicate'){
      const result=await apiPost({action:'item.duplicate',id:editor.item.id});editor.item=normalizeItem(result.item);editor.dirty=false;await loadState();setStatus('Draft copy created.');renderBuilder();return;
    }
    if(action==='archive'&&!confirm('Archive this food item? Historical orders remain intact.'))return;
    const result=await apiPost({action:action==='publish'?'item.publish':`item.${action}`,id:editor.item.id});editor.item=normalizeItem(result.item);editor.dirty=false;await loadState();setStatus(`Item ${action==='publish'?'published':action+'d'}.`);renderBuilder();
  }catch(error){setStatus(error.message,true);}
}

function openCategory(category=null){
  if(!canManage)return;
  $('#categoryId').value=category?.id||'';$('#categoryName').value=category?.name||'';$('#categoryDescription').value=category?.description||'';$('#categorySort').value=category?.sortOrder||0;$('#categoryStatus').value=category?.status||'active';$('#categoryDialogTitle').textContent=category?'Edit Category':'Add Category';ui.categoryDialog.showModal();
}

async function saveCategory(){
  try{
    await apiPost({action:'category.save',id:Number($('#categoryId').value||0),category:{name:$('#categoryName').value,description:$('#categoryDescription').value,sortOrder:Number($('#categorySort').value||0),status:$('#categoryStatus').value}});
    ui.categoryDialog.close();await loadState();
  }catch(error){alert(error.message);}
}

ui.search.addEventListener('input',()=>{clearTimeout(ui.search._timer);ui.search._timer=setTimeout(()=>{state.query=ui.search.value;loadState();},220);});
ui.categoryFilter.addEventListener('change',()=>{state.categoryId=Number(ui.categoryFilter.value||0);loadState();});
ui.showArchived.addEventListener('change',()=>{state.archived=ui.showArchived.checked;loadState();});
ui.categoryList.addEventListener('click',event=>{const choose=event.target.closest('[data-category]');if(choose){state.categoryId=Number(choose.dataset.category||0);loadState();return;}const edit=event.target.closest('[data-edit-category]');if(edit){const category=state.categories.find(row=>Number(row.id)===Number(edit.dataset.editCategory));if(category)openCategory(category);}});
ui.itemGrid.addEventListener('click',event=>{const button=event.target.closest('[data-edit-item]');if(button)openItem(Number(button.dataset.editItem));});
['addFoodTop','addFood'].forEach(id=>document.getElementById(id)?.addEventListener('click',()=>openItem(0)));
['addCategoryTop','addCategory'].forEach(id=>document.getElementById(id)?.addEventListener('click',()=>openCategory()));
$('#saveCategory')?.addEventListener('click',saveCategory);
$('#foodBuilderClose')?.addEventListener('click',closeBuilder);
ui.steps.addEventListener('click',event=>{const button=event.target.closest('[data-step]');if(button){editor.step=Number(button.dataset.step);renderBuilder();}});
ui.prev.addEventListener('click',()=>{editor.step=Math.max(0,editor.step-1);renderBuilder();});
ui.next.addEventListener('click',()=>{editor.step=Math.min(steps.length-1,editor.step+1);renderBuilder();});
ui.save?.addEventListener('click',()=>saveItem(false));
ui.publish?.addEventListener('click',()=>saveItem(true));
ui.lifecycle.addEventListener('click',event=>{const button=event.target.closest('[data-life]');if(button)lifecycle(button.dataset.life);});
document.addEventListener('keydown',event=>{if(event.key==='Escape'&&ui.builder.classList.contains('open'))closeBuilder();});

loadState().then(()=>{if(config.autoOpen)openItem(0);});
})();
