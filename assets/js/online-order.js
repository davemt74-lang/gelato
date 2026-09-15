(()=>{
  const cfg=window.STONEFELLOWS_ORDER||{};
  const locationId=Number(cfg.locationId||0);
  const authenticated=Boolean(cfg.authenticated);
  const signupUrl=String(cfg.signupUrl||'customer-signup.php?return=online-order.php%3Fcheckout%3D1');
  const key=`stonefellows.onlineCart.v1.${locationId}`;
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

  let cartTrigger=document.getElementById('orderCartToggle');
  let headerCount=document.getElementById('cartHeaderCount');
  if(!cartTrigger&&nav){
    cartTrigger=document.createElement('button');
    cartTrigger.id='orderCartToggle';
    cartTrigger.className='order-cart-trigger';
    cartTrigger.type='button';
    cartTrigger.setAttribute('aria-controls','orderCartDrawer');
    cartTrigger.setAttribute('aria-expanded','false');
    cartTrigger.setAttribute('aria-label','Cart, 0 items');
    cartTrigger.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2l2.2 10.1a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 1.9-1.4L21 8H7"/><circle cx="10" cy="19" r="1.2"/><circle cx="18" cy="19" r="1.2"/></svg><span id="cartHeaderCount" class="order-cart-badge">0</span>';
    const menuToggle=nav.querySelector('.menu-toggle');
    if(menuToggle)nav.insertBefore(cartTrigger,menuToggle);else nav.appendChild(cartTrigger);
    headerCount=cartTrigger.querySelector('#cartHeaderCount');
  }

  const makeToken=()=>{
    if(window.crypto&&crypto.getRandomValues){const bytes=new Uint8Array(20);crypto.getRandomValues(bytes);return Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('');}
    return `${Date.now().toString(36)}_${Math.random().toString(36).slice(2)}_${Math.random().toString(36).slice(2)}`.replace(/[^A-Za-z0-9_-]/g,'').slice(0,80);
  };
  token.value=makeToken();

  let cart=[];
  try{const parsed=JSON.parse(sessionStorage.getItem(key)||'[]');if(Array.isArray(parsed))cart=parsed.filter(row=>row&&Number(row.priceId)>0&&Number(row.quantity)>0);}catch(_){cart=[];}
  if(note){try{note.value=sessionStorage.getItem(noteKey)||note.value||'';}catch(_){}}

  const money=value=>new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(Number(value||0));
  const persist=()=>{try{sessionStorage.setItem(key,JSON.stringify(cart));}catch(_){};};
  const submitPayload=()=>cart.map(({priceId,quantity,instructions=''})=>({priceId:Number(priceId),quantity:Number(quantity),instructions:String(instructions||'')}));
  const escapeHtml=value=>String(value??'').replace(/[&<>'"]/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[ch]));
  const quantity=()=>cart.reduce((sum,row)=>sum+Number(row.quantity||0),0);

  function openCart(){
    document.body.classList.add('order-cart-open');
    drawer.setAttribute('aria-hidden','false');
    backdrop.setAttribute('aria-hidden','false');
    if(cartTrigger)cartTrigger.setAttribute('aria-expanded','true');
    if(closeButton)closeButton.focus({preventScroll:true});
  }

  function closeCart({restoreFocus=true}={}){
    document.body.classList.remove('order-cart-open');
    drawer.setAttribute('aria-hidden','true');
    backdrop.setAttribute('aria-hidden','true');
    if(cartTrigger)cartTrigger.setAttribute('aria-expanded','false');
    if(restoreFocus&&cartTrigger)cartTrigger.focus({preventScroll:true});
  }

  function updateHeaderCount(qty,bump=false){
    if(headerCount){
      headerCount.textContent=String(qty);
      headerCount.hidden=qty===0;
      if(bump&&qty>0){
        headerCount.classList.remove('bump');
        void headerCount.offsetWidth;
        headerCount.classList.add('bump');
      }
    }
    if(cartTrigger)cartTrigger.setAttribute('aria-label',`Cart, ${qty} item${qty===1?'':'s'}`);
  }

  function render({bump=false}={}){
    cart=cart.filter(row=>Number(row.quantity)>0);
    const qty=quantity();
    const subtotal=cart.reduce((sum,row)=>sum+(Number(row.price||0)*Number(row.quantity||0)),0);
    countEl.textContent=`${qty} item${qty===1?'':'s'}`;
    updateHeaderCount(qty,bump);
    subtotalEl.textContent=money(subtotal);
    cartJson.value=JSON.stringify(submitPayload());
    submit.disabled=!locationId||cart.length===0;
    submit.textContent=authenticated?'Place pickup order':'Continue to checkout';
    if(!cart.length){linesEl.innerHTML='<div class="customer-empty">Your cart is empty.</div>';persist();return;}
    linesEl.innerHTML=cart.map((row,index)=>`<article class="cart-line"><div><strong>${escapeHtml(row.name)}</strong><small>${escapeHtml(row.option)}</small><em>${money(row.price)}</em></div><div class="cart-stepper"><button type="button" data-cart-action="minus" data-index="${index}" aria-label="Decrease quantity">−</button><span>${Number(row.quantity)}</span><button type="button" data-cart-action="plus" data-index="${index}" aria-label="Increase quantity">+</button></div><button class="cart-remove" type="button" data-cart-action="remove" data-index="${index}">Remove</button></article>`).join('');
    persist();
  }

  if(cartTrigger)cartTrigger.addEventListener('click',()=>document.body.classList.contains('order-cart-open')?closeCart():openCart());
  if(closeButton)closeButton.addEventListener('click',()=>closeCart());
  backdrop.addEventListener('click',()=>closeCart());
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&document.body.classList.contains('order-cart-open'))closeCart();});

  document.addEventListener('click',event=>{
    const add=event.target.closest('.order-add');
    if(add){
      const priceId=Number(add.dataset.priceId||0);if(!priceId)return;
      const existing=cart.find(row=>Number(row.priceId)===priceId&&String(row.instructions||'')==='');
      if(existing)existing.quantity=Math.min(20,Number(existing.quantity||0)+1);
      else cart.push({priceId,quantity:1,instructions:'',name:add.dataset.itemName||'Item',option:add.dataset.optionName||'',price:Number(add.dataset.price||0)});
      add.classList.add('added');setTimeout(()=>add.classList.remove('added'),240);render({bump:true});return;
    }
    const control=event.target.closest('[data-cart-action]');if(!control)return;
    const index=Number(control.dataset.index);if(!Number.isInteger(index)||!cart[index])return;
    const action=control.dataset.cartAction;
    if(action==='minus')cart[index].quantity=Math.max(0,Number(cart[index].quantity||0)-1);
    if(action==='plus')cart[index].quantity=Math.min(20,Number(cart[index].quantity||0)+1);
    if(action==='remove')cart.splice(index,1);
    render();
  });

  if(note)note.addEventListener('input',()=>{try{sessionStorage.setItem(noteKey,note.value||'');}catch(_){}});

  form.addEventListener('submit',event=>{
    if(!cart.length){event.preventDefault();return;}
    cartJson.value=JSON.stringify(submitPayload());
    persist();
    if(note){try{sessionStorage.setItem(noteKey,note.value||'');}catch(_){}}
    if(!authenticated){
      event.preventDefault();
      window.location.assign(signupUrl);
      return;
    }
    submit.disabled=true;submit.textContent='Sending order…';
  });

  render();
  const params=new URLSearchParams(window.location.search);
  if(params.get('checkout')==='1'&&cart.length)requestAnimationFrame(()=>openCart());
})();
