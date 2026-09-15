(()=>{
  'use strict';
  const cfg=window.STONEFELLOWS_ORDER||{};
  const locationId=Number(cfg.locationId||0);
  const authenticated=Boolean(cfg.authenticated);
  const signupUrl=String(cfg.signupUrl||'customer-signup.php?return=online-order.php%3Fcheckout%3D1');
  const key=`stonefellows.onlineCart.v2.${locationId}`;
  const legacyKey=`stonefellows.onlineCart.v1.${locationId}`;
  const linesEl=document.getElementById('cartLines');
  const countEl=document.getElementById('cartCount');
  const subtotalEl=document.getElementById('cartSubtotal');
  const cartJson=document.getElementById('cartJson');
  const form=document.getElementById('checkoutForm');
  const submit=document.getElementById('placeOrder');
  const token=document.getElementById('idempotencyKey');
  const note=form?form.querySelector('textarea[name="order_note"]'):null;
  const drawer=document.getElementById('orderCartDrawer');
  const backdrop=document.getElementById('orderCartBackdrop');
  const closeButton=document.getElementById('orderCartClose');
  const nav=document.getElementById('nav');
  if(!linesEl||!countEl||!subtotalEl||!cartJson||!form||!submit||!token||!drawer||!backdrop)return;
  const noteKey=`${key}.note`;
  const catalogCache=new Map();

  const emptyCustom=()=>({removals:[],substitutions:[],note:''});
  const normalizeCustom=value=>{
    const v=value&&typeof value==='object'?value:{};
    const removals=[...new Set((Array.isArray(v.removals)?v.removals:[]).map(Number).filter(id=>id>0))].slice(0,12);
    const seen=new Set();
    const substitutions=[];
    (Array.isArray(v.substitutions)?v.substitutions:[]).forEach(row=>{
      const fromIngredientId=Number(row?.fromIngredientId||0),toIngredientId=Number(row?.toIngredientId||0);
      if(fromIngredientId>0&&toIngredientId>0&&fromIngredientId!==toIngredientId&&!seen.has(fromIngredientId)){
        seen.add(fromIngredientId);substitutions.push({fromIngredientId,toIngredientId});
      }
    });
    return {removals,substitutions:substitutions.slice(0,8),note:String(v.note||'').trim().slice(0,300)};
  };

  let cart=[];
  try{
    const source=sessionStorage.getItem(key)||sessionStorage.getItem(legacyKey)||'[]';
    const parsed=JSON.parse(source);
    if(Array.isArray(parsed))cart=parsed.filter(row=>row&&Number(row.priceId)>0&&Number(row.quantity)>0).map(row=>({...row,customizations:normalizeCustom(row.customizations)}));
  }catch(_){cart=[];}
  if(note){try{note.value=sessionStorage.getItem(noteKey)||note.value||'';}catch(_){}}

  let cartTrigger=document.getElementById('orderCartToggle');
  let headerCount=document.getElementById('cartHeaderCount');
  if(!cartTrigger&&nav){
    cartTrigger=document.createElement('button');
    cartTrigger.id='orderCartToggle';cartTrigger.className='order-cart-trigger';cartTrigger.type='button';
    cartTrigger.setAttribute('aria-controls','orderCartDrawer');cartTrigger.setAttribute('aria-expanded','false');cartTrigger.setAttribute('aria-label','Cart, 0 items');
    cartTrigger.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2l2.2 10.1a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 1.9-1.4L21 8H7"/><circle cx="10" cy="19" r="1.2"/><circle cx="18" cy="19" r="1.2"/></svg><span id="cartHeaderCount" class="order-cart-badge">0</span>';
    const menuToggle=nav.querySelector('.menu-toggle');if(menuToggle)nav.insertBefore(cartTrigger,menuToggle);else nav.appendChild(cartTrigger);
    headerCount=cartTrigger.querySelector('#cartHeaderCount');
  }

  const makeToken=()=>{
    if(window.crypto&&crypto.getRandomValues){const bytes=new Uint8Array(20);crypto.getRandomValues(bytes);return Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('');}
    return `${Date.now().toString(36)}_${Math.random().toString(36).slice(2)}_${Math.random().toString(36).slice(2)}`.replace(/[^A-Za-z0-9_-]/g,'').slice(0,80);
  };
  token.value=makeToken();

  const money=value=>new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(Number(value||0));
  const escapeHtml=value=>String(value??'').replace(/[&<>'"]/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[ch]));
  const persist=()=>{try{sessionStorage.setItem(key,JSON.stringify(cart));sessionStorage.removeItem(legacyKey);}catch(_){}};
  const quantity=()=>cart.reduce((sum,row)=>sum+Number(row.quantity||0),0);
  const submitPayload=()=>cart.map(row=>({
    priceId:Number(row.priceId),quantity:Number(row.quantity),instructions:String(row.instructions||''),
    customizations:normalizeCustom(row.customizations)
  }));

  function installCustomizer(){
    if(document.getElementById('orderCustomizeModal'))return;
    const style=document.createElement('style');
    style.textContent=`
      .cart-customize{margin-top:8px;border:1px solid #d8d6cf;background:#fff;border-radius:999px;padding:6px 10px;font:800 10px/1 system-ui;cursor:pointer}.cart-custom-summary{margin-top:7px;display:grid;gap:3px;color:#6f716c;font:600 10px/1.35 system-ui}.cart-custom-summary b{color:#171815;font-weight:850}.ocm{position:fixed;inset:0;z-index:11000;display:none;place-items:center;padding:18px;background:rgba(10,10,9,.62)}.ocm.open{display:grid}.ocm-card{width:min(720px,100%);max-height:min(88vh,820px);overflow:auto;border-radius:22px;background:#fff;box-shadow:0 30px 80px rgba(0,0,0,.35);padding:20px;color:#171815}.ocm-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;padding-bottom:14px;border-bottom:1px solid #eceae4}.ocm-head h2{margin:0;font-size:25px;letter-spacing:-.035em}.ocm-head p{margin:5px 0 0;color:#73766f;font-size:12px}.ocm-close{width:36px;height:36px;border:1px solid #dddcd5;background:#fff;border-radius:10px;font-size:20px;cursor:pointer}.ocm-section{padding:16px 0;border-bottom:1px solid #eceae4}.ocm-section h3{font-size:12px;text-transform:uppercase;letter-spacing:.09em;margin:0 0 4px}.ocm-section>p{color:#73766f;font-size:11px;margin:0 0 11px}.ocm-ingredient{display:grid;grid-template-columns:minmax(150px,1fr) auto minmax(190px,1fr);gap:10px;align-items:center;padding:9px 0;border-top:1px solid #f2f0eb}.ocm-ingredient:first-of-type{border-top:0}.ocm-name{font-weight:800;font-size:12px}.ocm-remove{display:flex;align-items:center;gap:5px;font-size:10px;font-weight:800;white-space:nowrap}.ocm-ingredient select,.ocm textarea{width:100%;border:1px solid #d8d6cf;border-radius:10px;background:#fff;padding:9px;font:inherit}.ocm textarea{min-height:82px;resize:vertical}.ocm-actions{display:flex;justify-content:flex-end;gap:8px;padding-top:16px}.ocm-btn{border:1px solid #d8d6cf;background:#fff;border-radius:10px;padding:10px 14px;font-weight:850;cursor:pointer}.ocm-btn.primary{background:#171b1a;color:#fff;border-color:#171b1a}.ocm-empty{padding:12px;border:1px dashed #d8d6cf;border-radius:12px;color:#73766f;font-size:11px}@media(max-width:650px){.ocm-ingredient{grid-template-columns:1fr}.ocm-card{padding:16px;border-radius:16px}}
    `;
    document.head.appendChild(style);
    const modal=document.createElement('div');modal.id='orderCustomizeModal';modal.className='ocm';modal.setAttribute('aria-hidden','true');
    modal.innerHTML='<div class="ocm-card" role="dialog" aria-modal="true" aria-labelledby="ocmTitle"><div class="ocm-head"><div><h2 id="ocmTitle">Customize item</h2><p id="ocmSubtitle"></p></div><button class="ocm-close" type="button" aria-label="Close">×</button></div><div id="ocmBody"></div><div class="ocm-actions"><button class="ocm-btn" type="button" data-ocm-cancel>Cancel</button><button class="ocm-btn primary" type="button" data-ocm-save>Save changes</button></div></div>';
    document.body.appendChild(modal);
  }
  installCustomizer();

  const modal=document.getElementById('orderCustomizeModal'),modalBody=document.getElementById('ocmBody'),modalTitle=document.getElementById('ocmTitle'),modalSubtitle=document.getElementById('ocmSubtitle');
  let editingIndex=-1;

  async function customizationCatalog(priceId){
    priceId=Number(priceId);if(catalogCache.has(priceId))return catalogCache.get(priceId);
    const response=await fetch(`api/online-order-customizations.php?priceId=${encodeURIComponent(priceId)}`,{headers:{Accept:'application/json'},cache:'no-store'});
    const data=await response.json().catch(()=>({ok:false,message:'Invalid restaurant response.'}));
    if(!response.ok||!data.ok)throw new Error(data.message||'Item customization is unavailable.');
    catalogCache.set(priceId,data.customization);return data.customization;
  }

  function customizationSummary(row){
    const labels=row.customizationLabels||{};const parts=[];
    if(Array.isArray(labels.removals)&&labels.removals.length)parts.push(`<span><b>Remove:</b> ${labels.removals.map(escapeHtml).join(', ')}</span>`);
    if(Array.isArray(labels.substitutions)&&labels.substitutions.length)parts.push(`<span><b>Substitute:</b> ${labels.substitutions.map(escapeHtml).join('; ')}</span>`);
    const itemNote=String(row.customizations?.note||'').trim();if(itemNote)parts.push(`<span><b>Item note:</b> ${escapeHtml(itemNote)}</span>`);
    return parts.length?`<div class="cart-custom-summary">${parts.join('')}</div>`:'';
  }

  async function openCustomizer(index){
    const row=cart[index];if(!row)return;editingIndex=index;
    modal.classList.add('open');modal.setAttribute('aria-hidden','false');modalTitle.textContent=`Customize ${row.name||'item'}`;modalSubtitle.textContent='Loading current menu ingredients…';modalBody.innerHTML='<div class="ocm-empty">Loading customization options…</div>';
    try{
      const catalog=await customizationCatalog(row.priceId);if(editingIndex!==index)return;
      const custom=normalizeCustom(row.customizations),removed=new Set(custom.removals),subMap=new Map(custom.substitutions.map(s=>[Number(s.fromIngredientId),Number(s.toIngredientId)]));
      modalSubtitle.textContent=`${row.option||''}${catalog.sectionName?' · '+catalog.sectionName:''}`;
      const options=(fromId,selected)=>'<option value="">No substitution</option>'+catalog.substitutions.filter(x=>Number(x.id)!==Number(fromId)).map(x=>`<option value="${Number(x.id)}" ${Number(x.id)===Number(selected)?'selected':''}>${escapeHtml(x.name)}</option>`).join('');
      const ingredients=(catalog.ingredients||[]).map(ingredient=>`<div class="ocm-ingredient" data-ingredient="${Number(ingredient.id)}"><span class="ocm-name">${escapeHtml(ingredient.name)}</span>${ingredient.canRemove?`<label class="ocm-remove"><input type="checkbox" data-remove ${removed.has(Number(ingredient.id))?'checked':''}> Remove</label>`:'<span></span>'}<select data-substitute aria-label="Substitute ${escapeHtml(ingredient.name)}">${options(ingredient.id,subMap.get(Number(ingredient.id))||0)}</select></div>`).join('');
      modalBody.innerHTML=`<section class="ocm-section"><h3>Removals & substitutions</h3><p>These choices come from Stonefellows’ current menu ingredient data and are revalidated by the restaurant when the order is placed.</p>${ingredients||'<div class="ocm-empty">No ingredient-level changes are configured for this item. You can still add an item note below.</div>'}</section><section class="ocm-section"><h3>Item note</h3><p>Add a preparation request specific to this item.</p><textarea id="ocmNote" maxlength="300" placeholder="Example: light bake, sauce on the side">${escapeHtml(custom.note)}</textarea></section>`;
      modalBody.querySelectorAll('[data-ingredient]').forEach(line=>{
        const remove=line.querySelector('[data-remove]'),select=line.querySelector('[data-substitute]');
        remove?.addEventListener('change',()=>{if(remove.checked)select.value='';});
        select?.addEventListener('change',()=>{if(select.value&&remove)remove.checked=false;});
      });
    }catch(error){modalSubtitle.textContent='';modalBody.innerHTML=`<div class="ocm-empty">${escapeHtml(error.message)}</div>`;}
  }

  function closeCustomizer(){modal.classList.remove('open');modal.setAttribute('aria-hidden','true');editingIndex=-1;}
  modal.querySelector('.ocm-close').addEventListener('click',closeCustomizer);modal.querySelector('[data-ocm-cancel]').addEventListener('click',closeCustomizer);
  modal.addEventListener('click',event=>{if(event.target===modal)closeCustomizer();});
  modal.querySelector('[data-ocm-save]').addEventListener('click',()=>{
    if(editingIndex<0||!cart[editingIndex])return;
    const removals=[],substitutions=[],removalLabels=[],substitutionLabels=[];
    modalBody.querySelectorAll('[data-ingredient]').forEach(line=>{
      const id=Number(line.dataset.ingredient),name=line.querySelector('.ocm-name')?.textContent||'Ingredient',remove=line.querySelector('[data-remove]'),select=line.querySelector('[data-substitute]');
      if(remove?.checked){removals.push(id);removalLabels.push(name);}
      if(select?.value){const toIngredientId=Number(select.value),toName=select.options[select.selectedIndex]?.textContent||'Substitute';substitutions.push({fromIngredientId:id,toIngredientId});substitutionLabels.push(`${name} → ${toName}`);}
    });
    cart[editingIndex].customizations=normalizeCustom({removals,substitutions,note:document.getElementById('ocmNote')?.value||''});
    cart[editingIndex].customizationLabels={removals:removalLabels,substitutions:substitutionLabels};persist();render();closeCustomizer();
  });

  function openCart(){document.body.classList.add('order-cart-open');drawer.setAttribute('aria-hidden','false');backdrop.setAttribute('aria-hidden','false');if(cartTrigger)cartTrigger.setAttribute('aria-expanded','true');closeButton?.focus({preventScroll:true});}
  function closeCart({restoreFocus=true}={}){document.body.classList.remove('order-cart-open');drawer.setAttribute('aria-hidden','true');backdrop.setAttribute('aria-hidden','true');if(cartTrigger)cartTrigger.setAttribute('aria-expanded','false');if(restoreFocus&&cartTrigger)cartTrigger.focus({preventScroll:true});}
  function updateHeaderCount(qty,bump=false){if(headerCount){headerCount.textContent=String(qty);headerCount.hidden=qty===0;if(bump&&qty>0){headerCount.classList.remove('bump');void headerCount.offsetWidth;headerCount.classList.add('bump');}}if(cartTrigger)cartTrigger.setAttribute('aria-label',`Cart, ${qty} item${qty===1?'':'s'}`);}

  function render({bump=false}={}){
    cart=cart.filter(row=>Number(row.quantity)>0);const qty=quantity(),subtotal=cart.reduce((sum,row)=>sum+(Number(row.price||0)*Number(row.quantity||0)),0);
    countEl.textContent=`${qty} item${qty===1?'':'s'}`;updateHeaderCount(qty,bump);subtotalEl.textContent=money(subtotal);cartJson.value=JSON.stringify(submitPayload());submit.disabled=!locationId||cart.length===0;submit.textContent=authenticated?'Place pickup order':'Continue to checkout';
    if(!cart.length){linesEl.innerHTML='<div class="customer-empty">Your cart is empty.</div>';persist();return;}
    linesEl.innerHTML=cart.map((row,index)=>`<article class="cart-line"><div><strong>${escapeHtml(row.name)}</strong><small>${escapeHtml(row.option)}</small><em>${money(row.price)}</em>${customizationSummary(row)}<button class="cart-customize" type="button" data-cart-action="customize" data-index="${index}">Customize</button></div><div class="cart-stepper"><button type="button" data-cart-action="minus" data-index="${index}" aria-label="Decrease quantity">−</button><span>${Number(row.quantity)}</span><button type="button" data-cart-action="plus" data-index="${index}" aria-label="Increase quantity">+</button></div><button class="cart-remove" type="button" data-cart-action="remove" data-index="${index}">Remove item</button></article>`).join('');persist();
  }

  cartTrigger?.addEventListener('click',()=>document.body.classList.contains('order-cart-open')?closeCart():openCart());closeButton?.addEventListener('click',()=>closeCart());backdrop.addEventListener('click',()=>closeCart());
  document.addEventListener('keydown',event=>{if(event.key==='Escape'){if(modal.classList.contains('open'))closeCustomizer();else if(document.body.classList.contains('order-cart-open'))closeCart();}});
  document.addEventListener('click',event=>{
    const add=event.target.closest('.order-add');
    if(add){const priceId=Number(add.dataset.priceId||0);if(!priceId)return;const existing=cart.find(row=>Number(row.priceId)===priceId&&!row.customizations?.removals?.length&&!row.customizations?.substitutions?.length&&!row.customizations?.note&&String(row.instructions||'')==='');if(existing)existing.quantity=Math.min(20,Number(existing.quantity||0)+1);else cart.push({priceId,quantity:1,instructions:'',customizations:emptyCustom(),customizationLabels:{removals:[],substitutions:[]},name:add.dataset.itemName||'Item',option:add.dataset.optionName||'',price:Number(add.dataset.price||0)});add.classList.add('added');setTimeout(()=>add.classList.remove('added'),240);render({bump:true});return;}
    const control=event.target.closest('[data-cart-action]');if(!control)return;const index=Number(control.dataset.index);if(!Number.isInteger(index)||!cart[index])return;const action=control.dataset.cartAction;
    if(action==='customize'){openCustomizer(index);return;}if(action==='minus')cart[index].quantity=Math.max(0,Number(cart[index].quantity||0)-1);if(action==='plus')cart[index].quantity=Math.min(20,Number(cart[index].quantity||0)+1);if(action==='remove')cart.splice(index,1);render();
  });
  if(note)note.addEventListener('input',()=>{try{sessionStorage.setItem(noteKey,note.value||'');}catch(_){}});
  form.addEventListener('submit',event=>{if(!cart.length){event.preventDefault();return;}cartJson.value=JSON.stringify(submitPayload());persist();if(note){try{sessionStorage.setItem(noteKey,note.value||'');}catch(_){}}if(!authenticated){event.preventDefault();window.location.assign(signupUrl);return;}submit.disabled=true;submit.textContent='Sending order…';});

  render();const params=new URLSearchParams(window.location.search);if(params.get('checkout')==='1'&&cart.length)requestAnimationFrame(()=>openCart());
})();
