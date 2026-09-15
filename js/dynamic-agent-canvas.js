(() => {
  'use strict';
  if (window.GelatoDynamicAgentCanvas) return;

  const state = {
    open: false,
    mounted: false,
    activeThread: '',
    loading: false,
    lastFocus: null,
    previousOverflow: '',
    context: '',
    drawerMounted: false,
    drawerOpen: false,
    drawerLoading: false,
    drawerPending: false,
  };
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const fmt = (value) => {
    if (!value) return '';
    try { return new Intl.DateTimeFormat('en-US',{month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}).format(new Date(String(value).replace(' ','T'))); }
    catch { return String(value); }
  };

  function isWorkspacePage() {
    const file = String(window.location.pathname || '').split('/').filter(Boolean).pop()?.toLowerCase() || '';
    return file === 'workspace.php' || document.body?.dataset.agentWorkspace === '1';
  }

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
      .gda-messages{overflow:auto;padding:28px clamp(18px,4vw,54px);scroll-behavior:smooth}.gda-message{max-width:900px;margin:0 auto 18px;display:flex;gap:10px;align-items:flex-start}.gda-message.user{justify-content:flex-end}.gda-avatar{width:31px;height:31px;border-radius:10px;display:grid;place-items:center;background:#ef6540;color:#fff;font-size:11px;font-weight:900;flex:0 0 31px}.gda-message.user .gda-avatar{order:2;background:#151815}.gda-bubble{max-width:min(760px,86%);padding:13px 15px;border:1px solid #dfdfd9;border-radius:16px;background:#fff;box-shadow:0 5px 18px rgba(20,20,18,.04);font-size:13px;line-height:1.55;white-space:pre-wrap}.gda-message.user .gda-bubble{background:#151815;color:#fff;border-color:#151815}.gda-source-row{display:flex;gap:5px;flex-wrap:wrap;margin-top:8px}.gda-source{border:1px solid #d9e2ec;background:#f4f8fc;color:#355f89;border-radius:999px;padding:3px 7px;font-size:9px;font-weight:800}.gda-empty{max-width:760px;margin:15vh auto 0;text-align:center;color:#777a74}.gda-empty strong{display:block;color:#151815;font-size:24px;letter-spacing:-.03em}.gda-empty p{line-height:1.6}
      .gda-compose{padding:12px 20px 18px;background:linear-gradient(180deg,rgba(255,255,255,0),#fff 35%)}.gda-compose-box{max-width:920px;margin:0 auto;border:1px solid #d6d6d0;border-radius:18px;background:#fff;display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:7px;align-items:end;padding:8px;box-shadow:0 15px 44px rgba(20,20,18,.1)}.gda-input{width:100%;min-height:44px;max-height:130px;resize:none;border:0;outline:0;padding:12px;background:transparent;color:#151815;font:inherit}.gda-tool,.gda-send{width:42px;height:42px;border:0;border-radius:12px;cursor:pointer;font-weight:900}.gda-tool{background:#efefe9}.gda-send{background:#151815;color:#fff}.gda-status{max-width:920px;margin:0 auto 7px;color:#777a74;font-size:10px;min-height:14px}
      body.gda-open #gelato-agent-bar,body.gda-open #gelato-agent-status{opacity:0!important;pointer-events:none!important}

      #gelato-agent-response-drawer{position:fixed;z-index:2147483090;display:none;overflow:hidden;background:#fff;border:1px solid #d9d9d2;border-radius:20px;box-shadow:0 20px 56px rgba(18,20,18,.2);font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#151815}
      #gelato-agent-response-drawer.open{display:grid;grid-template-rows:auto minmax(0,1fr)}
      .gar-head{height:48px;display:flex;align-items:center;gap:10px;padding:0 10px 0 14px;border-bottom:1px solid #ecece7;background:#fbfbf9}
      .gar-orb{width:26px;height:26px;border-radius:9px;display:grid;place-items:center;background:#151815;color:#fff;font-size:10px;font-weight:900;flex:0 0 26px}
      .gar-copy{min-width:0;flex:1}.gar-copy strong{display:block;font-size:12px;line-height:1.2}.gar-context{display:block;margin-top:2px;color:#80827d;font-size:9px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .gar-close{width:32px;height:32px;border:0;border-radius:10px;background:#efefe9;color:#151815;cursor:pointer;font-size:18px;line-height:1}
      .gar-messages{min-height:110px;max-height:min(44vh,420px);overflow:auto;padding:14px;scroll-behavior:smooth;background:#fff}
      .gar-message{display:flex;gap:8px;align-items:flex-start;margin:0 0 10px}.gar-message.user{justify-content:flex-end}.gar-avatar{width:24px;height:24px;border-radius:8px;display:grid;place-items:center;background:#ef6540;color:#fff;font-size:9px;font-weight:900;flex:0 0 24px}.gar-message.user .gar-avatar{order:2;background:#151815}
      .gar-bubble{max-width:84%;padding:9px 11px;border:1px solid #e0e0da;border-radius:13px;background:#f8f8f5;font-size:12px;line-height:1.5;white-space:pre-wrap}.gar-message.user .gar-bubble{background:#171b1a;color:#fff;border-color:#171b1a}
      .gar-sources{display:flex;flex-wrap:wrap;gap:4px;margin-top:7px}.gar-source{padding:2px 6px;border-radius:999px;background:#edf3f8;color:#355f89;font-size:8px;font-weight:800}
      .gar-empty{padding:18px;text-align:center;color:#777a74;font-size:11px}.gar-pending{display:flex;align-items:center;gap:8px;color:#777a74;font-size:11px;padding:2px 0 8px}.gar-pulse{width:8px;height:8px;border-radius:50%;background:#ef6540;animation:garPulse 1.1s ease-in-out infinite}
      #gaResponseToggle{font-size:15px}
      #gaResponseToggle.on{background:#171b1a!important;color:#fff!important}
      @keyframes garPulse{50%{opacity:.25;transform:scale(.8)}}
      @media(max-width:760px){.gda-shell{grid-template-columns:1fr}.gda-side{position:absolute;z-index:5;inset:0 auto 0 0;width:min(82vw,300px);transform:translateX(-105%);transition:.2s;box-shadow:14px 0 32px rgba(0,0,0,.14)}.gda-side.open{transform:none}.gda-messages{padding:20px 14px}.gda-head{padding:12px 14px}.gda-bubble{max-width:90%}.gar-messages{max-height:min(46vh,360px)}}
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

  function mountDrawer() {
    if (isWorkspacePage() || state.drawerMounted) return;
    installStyles();
    const drawer=document.createElement('section');
    drawer.id='gelato-agent-response-drawer';
    drawer.setAttribute('role','region');
    drawer.setAttribute('aria-label','Gelato Agent responses');
    drawer.setAttribute('aria-live','polite');
    drawer.innerHTML=`<header class="gar-head"><div class="gar-orb">G</div><div class="gar-copy"><strong>Gelato Agent</strong><span class="gar-context"></span></div><button class="gar-close" type="button" aria-label="Close Agent responses">×</button></header><div class="gar-messages"></div>`;
    document.body.appendChild(drawer);
    drawer.querySelector('.gar-close').addEventListener('click',closeDrawer);
    state.drawerMounted=true;
    ensureDrawerToggle();
    syncDrawerPosition();
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

  function renderDrawerMessages(messages=[]) {
    const root=document.querySelector('.gar-messages');if(!root)return;
    const recent=messages.slice(-8);
    if(!recent.length&&!state.drawerPending){
      root.innerHTML='<div class="gar-empty">Ask Gelato from the chat bar. Responses stay here while you keep working on this page.</div>';
      return;
    }
    root.innerHTML=recent.map(m=>`<article class="gar-message ${m.role==='user'?'user':'agent'}"><div class="gar-avatar">${m.role==='user'?'Y':'G'}</div><div class="gar-bubble">${esc(m.content)}${Array.isArray(m.sources)&&m.sources.length?`<div class="gar-sources">${m.sources.map(s=>`<span class="gar-source">${esc(s)}</span>`).join('')}</div>`:''}</div></article>`).join('');
    if(state.drawerPending)root.insertAdjacentHTML('beforeend','<div class="gar-pending"><span class="gar-pulse"></span><span>Gelato is checking the restaurant context…</span></div>');
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

  async function refreshDrawer() {
    if(isWorkspacePage()||state.drawerLoading)return;
    mountDrawer();
    state.drawerLoading=true;
    try{
      const bootstrap=await json('api/agent-workspace.php?action=bootstrap');
      state.activeThread=window.GelatoGlobalAgent?.threadId||state.activeThread||bootstrap.activeThread||'';
      if(!state.activeThread){renderDrawerMessages([]);return;}
      const thread=await json('api/agent-workspace.php?action=thread&id='+encodeURIComponent(state.activeThread));
      renderDrawerMessages(thread.messages||[]);
    }finally{state.drawerLoading=false;}
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
    if(!isWorkspacePage()){openDrawer(options);return;}
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

  function ensureDrawerToggle() {
    if(isWorkspacePage())return;
    removeLegacyCanvasButton();
    const bar=document.getElementById('gelato-agent-bar');
    if(!bar||document.getElementById('gaResponseToggle'))return;
    const button=document.createElement('button');
    button.id='gaResponseToggle';
    button.type='button';
    button.title='Show Agent responses';
    button.setAttribute('aria-label','Show Agent responses');
    button.setAttribute('aria-controls','gelato-agent-response-drawer');
    button.setAttribute('aria-expanded','false');
    button.textContent='✦';
    const send=bar.querySelector('#gaSend');
    bar.insertBefore(button,send||null);
    button.addEventListener('click',toggleDrawer);
  }

  function syncDrawerPosition() {
    if(isWorkspacePage())return;
    const drawer=document.getElementById('gelato-agent-response-drawer');
    const bar=document.getElementById('gelato-agent-bar');
    if(!drawer||!bar)return;
    const rect=bar.getBoundingClientRect();
    drawer.style.left=Math.max(6,rect.left)+'px';
    drawer.style.width=Math.min(rect.width,window.innerWidth-12)+'px';
    drawer.style.bottom=Math.max(66,window.innerHeight-rect.top+8)+'px';
  }

  function openDrawer(options={}) {
    if(isWorkspacePage()){open(options);return;}
    mountDrawer();ensureDrawerToggle();syncDrawerPosition();
    const drawer=document.getElementById('gelato-agent-response-drawer');if(!drawer)return;
    state.drawerOpen=true;
    if(options.pending)state.drawerPending=true;
    drawer.classList.add('open');
    const context=drawer.querySelector('.gar-context');if(context)context.textContent=options.context||pageContext();
    const toggle=document.getElementById('gaResponseToggle');
    if(toggle){toggle.classList.add('on');toggle.setAttribute('aria-expanded','true');toggle.title='Hide Agent responses';}
    refreshDrawer().catch(error=>renderDrawerError(error));
  }

  function closeDrawer() {
    const drawer=document.getElementById('gelato-agent-response-drawer');if(!drawer)return;
    drawer.classList.remove('open');state.drawerOpen=false;
    const toggle=document.getElementById('gaResponseToggle');
    if(toggle){toggle.classList.remove('on');toggle.setAttribute('aria-expanded','false');toggle.title='Show Agent responses';}
  }

  function toggleDrawer() {
    if(state.drawerOpen){closeDrawer();return;}
    state.drawerPending=false;
    openDrawer();
  }

  function renderDrawerError(error) {
    state.drawerPending=false;
    const root=document.querySelector('.gar-messages');if(!root)return;
    root.innerHTML=`<div class="gar-empty">${esc(error?.message||'Gelato could not load this conversation.')}</div>`;
  }

  async function submit() {
    const input=document.querySelector('.gda-input');const text=String(input?.value||'').trim();if(!text||!window.GelatoGlobalAgent)return;
    input.value='';setStatus('Gelato is checking the restaurant context…');
    try{await window.GelatoGlobalAgent.send(text,false);}catch(error){setStatus(error.message||'Gelato could not complete that request.');}
  }

  function routeFooterToAgentView() {
    if(isWorkspacePage())open();
    else openDrawer({pending:true});
  }

  function hookFooter() {
    removeLegacyCanvasButton();
    ensureDrawerToggle();
    document.addEventListener('click',(event)=>{
      if(event.target.closest('#gaSend'))routeFooterToAgentView();
      if(event.target.closest('#gaMic'))routeFooterToAgentView();
    },true);
    document.addEventListener('keydown',(event)=>{
      if(event.target?.id==='gaInput'&&event.key==='Enter'&&!event.shiftKey)routeFooterToAgentView();
      if(event.key==='Escape'&&state.open)close();
      else if(event.key==='Escape'&&state.drawerOpen)closeDrawer();
    },true);
    new MutationObserver(()=>{removeLegacyCanvasButton();ensureDrawerToggle();syncDrawerPosition();}).observe(document.documentElement,{childList:true,subtree:true});
    window.addEventListener('resize',syncDrawerPosition);
  }

  window.addEventListener('gelato-agent-ready',()=>{
    removeLegacyCanvasButton();ensureDrawerToggle();
    if(state.open)refresh().catch(()=>{});
    if(state.drawerOpen)refreshDrawer().catch(()=>{});
  });
  window.addEventListener('gelato-agent-response',(event)=>{
    state.activeThread=event.detail?.threadId||window.GelatoGlobalAgent?.threadId||state.activeThread;
    if(isWorkspacePage()){
      if(!state.open)open();
      setStatus('');refreshThreadsAndMessages().catch(error=>setStatus(error.message));
      return;
    }
    state.drawerPending=false;
    if(!state.drawerOpen)openDrawer();
    refreshDrawer().catch(error=>renderDrawerError(error));
  });

  window.GelatoDynamicAgentCanvas={
    open,
    close,
    refresh,
    openDrawer,
    closeDrawer,
    get isOpen(){return state.open;},
    get isDrawerOpen(){return state.drawerOpen;},
  };
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>{mount();mountDrawer();hookFooter();},{once:true});
  else{mount();mountDrawer();hookFooter();}
})();
