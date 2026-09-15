(() => {
  'use strict';
  if (window.GelatoDynamicAgentCanvas) return;

  const state = {open:false, mounted:false, activeThread:'', loading:false, lastFocus:null, previousOverflow:'', context:'', pendingText:''};
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const fmt = (value) => {
    if (!value) return '';
    try { return new Intl.DateTimeFormat('en-US',{month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}).format(new Date(String(value).replace(' ','T'))); }
    catch { return String(value); }
  };

  function removeLegacyCanvasButton() {
    document.getElementById('gaCanvas')?.remove();
  }

  function installStyles() {
    if (document.querySelector('style[data-dynamic-agent-canvas]')) return;
    const style=document.createElement('style');
    style.dataset.dynamicAgentCanvas='1';
    style.textContent=`
      #gelato-dynamic-agent{position:fixed;inset:0;z-index:2147483200;opacity:0;visibility:hidden;pointer-events:none;transition:opacity .22s ease,visibility .22s ease;background:rgba(248,248,246,.97);backdrop-filter:blur(12px);font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#151815}
      #gelato-dynamic-agent.open{opacity:1;visibility:visible;pointer-events:auto}
      .gda-shell{height:100%;display:grid;grid-template-columns:260px minmax(0,1fr);overflow:hidden}
      .gda-side{border-right:1px solid #dfdfd9;background:#f4f4f0;padding:18px;display:flex;flex-direction:column;gap:14px;min-width:0}
      .gda-brand{display:flex;align-items:center;gap:10px}.gda-orb{width:38px;height:38px;border-radius:13px;display:grid;place-items:center;background:#151815;color:#fff;font-weight:900}.gda-brand strong{font-size:14px}.gda-brand span{display:block;color:#777a74;font-size:10px;margin-top:2px}
      .gda-new{height:40px;border:1px solid #d6d6d0;border-radius:12px;background:#fff;color:#151815;font-weight:800;cursor:pointer}.gda-thread-list{display:grid;gap:6px;overflow:auto;min-height:0}.gda-thread{display:block;width:100%;border:0;border-radius:11px;padding:10px;background:transparent;text-align:left;cursor:pointer;color:#30322f}.gda-thread:hover,.gda-thread.active{background:#fff;box-shadow:0 4px 14px rgba(20,20,18,.06)}.gda-thread strong{display:block;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.gda-thread small{display:block;margin-top:4px;color:#858781;font-size:9px}
      .gda-main{min-width:0;min-height:0;display:grid;grid-template-rows:auto minmax(0,1fr) auto;background:rgba(255,255,255,.7)}
      .gda-head{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:17px 24px;border-bottom:1px solid #e2e2dc;background:rgba(255,255,255,.88)}.gda-head-copy{min-width:0}.gda-eyebrow{font-size:9px;letter-spacing:.12em;text-transform:uppercase;font-weight:900;color:#ef6540}.gda-head h2{margin:4px 0 0;font-size:18px}.gda-context{margin-top:4px;color:#777a74;font-size:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.gda-close{width:38px;height:38px;border:1px solid #d8d8d2;border-radius:12px;background:#fff;color:#151815;font-size:22px;cursor:pointer}
      .gda-messages{overflow:auto;padding:28px clamp(18px,4vw,54px);scroll-behavior:smooth}.gda-message{max-width:900px;margin:0 auto 18px;display:flex;gap:10px;align-items:flex-start}.gda-message.user{justify-content:flex-end}.gda-avatar{width:31px;height:31px;border-radius:10px;display:grid;place-items:center;background:#ef6540;color:#fff;font-size:11px;font-weight:900;flex:0 0 31px}.gda-message.user .gda-avatar{order:2;background:#151815}.gda-bubble{max-width:min(760px,86%);padding:13px 15px;border:1px solid #dfdfd9;border-radius:16px;background:#fff;box-shadow:0 5px 18px rgba(20,20,18,.04);font-size:13px;line-height:1.55;white-space:pre-wrap}.gda-message.user .gda-bubble{background:#151815;color:#fff;border-color:#151815}.gda-meta{max-width:900px;margin:5px auto 0;color:#8a8c86;font-size:9px}.gda-source-row{display:flex;gap:5px;flex-wrap:wrap;margin-top:8px}.gda-source{border:1px solid #d9e2ec;background:#f4f8fc;color:#355f89;border-radius:999px;padding:3px 7px;font-size:9px;font-weight:800}.gda-empty{max-width:760px;margin:15vh auto 0;text-align:center;color:#777a74}.gda-empty strong{display:block;color:#151815;font-size:24px;letter-spacing:-.03em}.gda-empty p{line-height:1.6}.gda-thinking{opacity:.7}
      .gda-compose{padding:12px 20px 18px;background:linear-gradient(180deg,rgba(255,255,255,0),#fff 35%)}.gda-compose-box{max-width:920px;margin:0 auto;border:1px solid #d6d6d0;border-radius:18px;background:#fff;display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:7px;align-items:end;padding:8px;box-shadow:0 15px 44px rgba(20,20,18,.1)}.gda-input{width:100%;min-height:44px;max-height:130px;resize:none;border:0;outline:0;padding:12px;background:transparent;color:#151815;font:inherit}.gda-tool,.gda-send{width:42px;height:42px;border:0;border-radius:12px;cursor:pointer;font-weight:900}.gda-tool{background:#efefe9}.gda-send{background:#151815;color:#fff}.gda-status{max-width:920px;margin:0 auto 7px;color:#777a74;font-size:10px;min-height:14px}
      body.gda-open #gelato-agent-bar,body.gda-open #gelato-agent-status{opacity:0!important;pointer-events:none!important}
      @media(max-width:760px){.gda-shell{grid-template-columns:1fr}.gda-side{position:absolute;z-index:5;inset:0 auto 0 0;width:min(82vw,300px);transform:translateX(-105%);transition:.2s;box-shadow:14px 0 32px rgba(0,0,0,.14)}.gda-side.open{transform:none}.gda-messages{padding:20px 14px}.gda-head{padding:12px 14px}.gda-bubble{max-width:90%}}
    `;
    document.head.appendChild(style);
  }

  function mount() {
    if (state.mounted) return;
    installStyles();
    removeLegacyCanvasButton();
    const root=document.createElement('section');
    root.id='gelato-dynamic-agent';
    root.setAttribute('role','dialog');root.setAttribute('aria-modal','true');root.setAttribute('aria-label','Gelato Agent chat canvas');
    root.innerHTML=`<div class="gda-shell"><aside class="gda-side"><div class="gda-brand"><div class="gda-orb">G</div><div><strong>Gelato Agent</strong><span>Main restaurant brain</span></div></div><button class="gda-new" type="button">+ New conversation</button><div class="gda-thread-list"></div></aside><main class="gda-main"><header class="gda-head"><div class="gda-head-copy"><div class="gda-eyebrow">Dynamic Agent Canvas</div><h2>Gelato Agent</h2><div class="gda-context"></div></div><button class="gda-close" type="button" aria-label="Close Agent canvas">×</button></header><div class="gda-messages"></div><div class="gda-compose"><div class="gda-status"></div><div class="gda-compose-box"><textarea class="gda-input" rows="1" placeholder="Ask Gelato about this page, staff, schedules, menus, sales, operations…"></textarea><button class="gda-tool" type="button" title="Talk once">🎙</button><button class="gda-send" type="button" title="Send">↑</button></div></div></main></div>`;
    document.body.appendChild(root);
    root.querySelector('.gda-close').addEventListener('click',close);
    root.querySelector('.gda-new').addEventListener('click',newThread);
    root.querySelector('.gda-send').addEventListener('click',submit);
    root.querySelector('.gda-tool').addEventListener('click',()=>window.GelatoGlobalAgent?.talkOnce?.());
    root.querySelector('.gda-input').addEventListener('keydown',(event)=>{if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();submit();}});
    root.addEventListener('click',(event)=>{const thread=event.target.closest('[data-gda-thread]');if(thread)openThread(thread.dataset.gdaThread);});
    state.mounted=true;
  }

  function pageContext() {
    const title=document.getElementById('topPageTitle')?.textContent?.trim()||document.querySelector('h1,h2')?.textContent?.trim()||document.title||'Restaurant workspace';
    const scope=window.RestaurantAdminDashboardContext?.scope?.locationName;
    return scope&&scope!=='All locations'?`${title} · ${scope}`:title;
  }

  async function json(url,opt={}) {
    const response=await fetch(url,{cache:'no-store',headers:{Accept:'application/json',...(opt.headers||{})},...opt});
    const data=await response.json().catch(()=>({ok:false,message:'Invalid Agent response.'}));
    if(!response.ok||!data.ok)throw new Error(data.message||'Agent request failed.');
    return data;
  }

  function setStatus(text='') { const n=document.querySelector('.gda-status');if(n)n.textContent=text; }

  function renderThreads(threads=[]) {
    const list=document.querySelector('.gda-thread-list');if(!list)return;
    list.innerHTML=threads.length?threads.map(t=>`<button class="gda-thread ${t.public_id===state.activeThread?'active':''}" type="button" data-gda-thread="${esc(t.public_id)}"><strong>${esc(t.title||'Conversation')}</strong><small>${Number(t.message_count||0)} messages · ${esc(fmt(t.last_message_at||t.created_at))}</small></button>`).join(''):'<div class="gda-empty"><p>No conversations yet.</p></div>';
  }

  function renderMessages(messages=[]) {
    const root=document.querySelector('.gda-messages');if(!root)return;
    if(!messages.length){root.innerHTML='<div class="gda-empty"><strong>Ask from anywhere.</strong><p>The page you were using is still open underneath this canvas. Gelato keeps the same conversation, permissions, and restaurant context.</p></div>';return;}
    root.innerHTML=messages.map(m=>`<article class="gda-message ${m.role==='user'?'user':'agent'}"><div class="gda-avatar">${m.role==='user'?'Y':'G'}</div><div class="gda-bubble">${esc(m.content)}${Array.isArray(m.sources)&&m.sources.length?`<div class="gda-source-row">${m.sources.map(s=>`<span class="gda-source">${esc(s)}</span>`).join('')}</div>`:''}</div></article>`).join('');
    root.scrollTop=root.scrollHeight;
  }

  async function refresh() {
    if(state.loading)return;state.loading=true;
    try{
      const bootstrap=await json('api/agent-workspace.php?action=bootstrap');
      state.activeThread=state.activeThread||window.GelatoGlobalAgent?.threadId||bootstrap.activeThread||'';
      renderThreads(bootstrap.threads||[]);
      if(state.activeThread){
        const thread=await json('api/agent-workspace.php?action=thread&id='+encodeURIComponent(state.activeThread));
        renderMessages(thread.messages||[]);
        const h=document.querySelector('.gda-head h2');if(h)h.textContent=thread.thread?.title||'Gelato Agent';
      }else renderMessages([]);
    }finally{state.loading=false;}
  }

  async function openThread(id) {
    if(!id)return;state.activeThread=String(id);window.GelatoGlobalAgent?.selectThread?.(state.activeThread);await refreshThreadsAndMessages();
  }

  async function refreshThreadsAndMessages() {
    const list=await json('api/agent-workspace.php?action=threads');renderThreads(list.threads||[]);
    if(state.activeThread){const data=await json('api/agent-workspace.php?action=thread&id='+encodeURIComponent(state.activeThread));renderMessages(data.messages||[]);const h=document.querySelector('.gda-head h2');if(h)h.textContent=data.thread?.title||'Gelato Agent';}
  }

  async function newThread() {
    if(!window.GelatoGlobalAgent)return;
    const bootstrap=await json('api/agent-workspace.php?action=bootstrap');
    const response=await json('api/agent-workspace.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':bootstrap.csrf},body:JSON.stringify({action:'new_thread',csrf_token:bootstrap.csrf})});
    state.activeThread=response.thread.public_id;window.GelatoGlobalAgent.selectThread(state.activeThread);await refreshThreadsAndMessages();document.querySelector('.gda-input')?.focus();
  }

  function open(options={}) {
    mount();removeLegacyCanvasButton();
    const root=document.getElementById('gelato-dynamic-agent');if(!root)return;
    if(!state.open){state.lastFocus=document.activeElement;state.previousOverflow=document.documentElement.style.overflow;document.documentElement.style.overflow='hidden';document.body.classList.add('gda-open');root.classList.add('open');state.open=true;}
    state.context=options.context||pageContext();const ctx=root.querySelector('.gda-context');if(ctx)ctx.textContent='From '+state.context+' · page remains open underneath';
    state.activeThread=window.GelatoGlobalAgent?.threadId||state.activeThread;
    refresh().catch(error=>setStatus(error.message));
    requestAnimationFrame(()=>root.querySelector('.gda-input')?.focus());
  }

  function close() {
    const root=document.getElementById('gelato-dynamic-agent');if(!root||!state.open)return;
    root.classList.remove('open');document.body.classList.remove('gda-open');document.documentElement.style.overflow=state.previousOverflow;state.open=false;setStatus('');
    if(state.lastFocus instanceof HTMLElement)state.lastFocus.focus({preventScroll:true});
  }

  async function submit() {
    const input=document.querySelector('.gda-input');const text=String(input?.value||'').trim();if(!text||!window.GelatoGlobalAgent)return;
    input.value='';setStatus('Gelato is checking the restaurant context…');
    try{await window.GelatoGlobalAgent.send(text,false);}catch(error){setStatus(error.message||'Gelato could not complete that request.');}
  }

  function hookFooter() {
    removeLegacyCanvasButton();
    document.addEventListener('click',(event)=>{
      if(event.target.closest('#gaSend'))open();
      if(event.target.closest('#gaMic'))open();
    },true);
    document.addEventListener('keydown',(event)=>{
      if(event.target?.id==='gaInput'&&event.key==='Enter'&&!event.shiftKey)open();
      if(event.key==='Escape'&&state.open)close();
    },true);
    new MutationObserver(removeLegacyCanvasButton).observe(document.documentElement,{childList:true,subtree:true});
  }

  window.addEventListener('gelato-agent-ready',()=>{removeLegacyCanvasButton();if(state.open)refresh().catch(()=>{});});
  window.addEventListener('gelato-agent-response',(event)=>{
    if(!state.open)open();
    state.activeThread=event.detail?.threadId||window.GelatoGlobalAgent?.threadId||state.activeThread;
    setStatus('');refreshThreadsAndMessages().catch(error=>setStatus(error.message));
  });

  window.GelatoDynamicAgentCanvas={open,close,refresh,get isOpen(){return state.open;}};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>{mount();hookFooter();},{once:true});
  else{mount();hookFooter();}
})();
