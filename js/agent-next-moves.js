(() => {
  'use strict';
  if (window.GelatoAgentNextMoves || document.body?.dataset.agentVoiceOnly === '1') return;

  const state = {moves: [], dismissed: new Set(), timer: null, mounted: false, csrf: ''};
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  function loadDismissed() {
    try { state.dismissed = new Set(JSON.parse(sessionStorage.getItem('gelato-agent-dismissed-next-moves') || '[]')); } catch { state.dismissed = new Set(); }
  }
  function saveDismissed() {
    try { sessionStorage.setItem('gelato-agent-dismissed-next-moves', JSON.stringify([...state.dismissed].slice(-80))); } catch {}
  }

  function installStyles() {
    if (document.querySelector('style[data-agent-next-moves]')) return;
    const style = document.createElement('style');
    style.dataset.agentNextMoves = '1';
    style.textContent = `
      #gelato-agent-next-moves{position:fixed;right:18px;bottom:84px;z-index:2147482988;width:min(390px,calc(100vw - 28px));max-height:min(62vh,560px);overflow:hidden;background:#fff;border:1px solid #d9d9d2;border-radius:18px;box-shadow:0 18px 50px rgba(18,20,18,.18);font-family:Inter,system-ui,-apple-system,Segoe UI,sans-serif;color:#151815;display:none}
      #gelato-agent-next-moves.open{display:block}.ganm-head{display:flex;align-items:center;gap:9px;padding:12px 13px;border-bottom:1px solid #ecece7;background:#fbfbf9}.ganm-orb{width:28px;height:28px;border-radius:9px;background:#151815;color:#fff;display:grid;place-items:center;font-weight:900;font-size:10px}.ganm-title{min-width:0;flex:1}.ganm-title strong{display:block;font-size:12px}.ganm-title span{display:block;color:#7a7d77;font-size:9px;margin-top:2px}.ganm-close{width:30px;height:30px;border:0;border-radius:9px;background:#efefe9;cursor:pointer}.ganm-list{padding:9px;display:grid;gap:8px;max-height:min(50vh,450px);overflow:auto}.ganm-card{border:1px solid #e0e0da;border-radius:13px;padding:10px;background:#fff}.ganm-top{display:flex;align-items:flex-start;gap:8px}.ganm-sev{margin-top:3px;width:8px;height:8px;border-radius:50%;background:#7b827c;flex:0 0 8px}.ganm-card[data-severity="critical"] .ganm-sev,.ganm-card[data-severity="high"] .ganm-sev{background:#d94a2b}.ganm-copy{min-width:0;flex:1}.ganm-copy strong{display:block;font-size:11px;line-height:1.35}.ganm-copy p{margin:4px 0 0;color:#6d716b;font-size:9px;line-height:1.45}.ganm-node{display:inline-block;margin-top:6px;padding:2px 6px;border-radius:999px;background:#eef2ed;color:#4f6255;font-size:8px;font-weight:800}.ganm-actions{display:flex;gap:6px;margin-top:9px}.ganm-actions button,.ganm-actions a{border:0;border-radius:9px;padding:7px 9px;font:800 9px/1 Inter,system-ui,sans-serif;cursor:pointer;text-decoration:none}.ganm-review{background:#171b1a;color:#fff}.ganm-propose{background:#eee9df;color:#4f4026}.ganm-dismiss{background:#f2f2ee;color:#6f726d}.ganm-link{background:#eef3f8;color:#355f89}.ganm-toggle{position:fixed;right:18px;bottom:24px;z-index:2147482988;border:1px solid #d9d9d2;border-radius:13px;background:#fff;box-shadow:0 10px 28px rgba(18,20,18,.14);padding:9px 11px;font:800 10px/1 Inter,system-ui,sans-serif;color:#151815;cursor:pointer;display:none}.ganm-toggle.show{display:block}.ganm-count{display:inline-grid;place-items:center;min-width:18px;height:18px;padding:0 5px;margin-left:5px;border-radius:999px;background:#d94a2b;color:#fff;font-size:8px}
      @media(max-width:760px){#gelato-agent-next-moves{right:8px;bottom:78px;width:calc(100vw - 16px)}.ganm-toggle{right:8px;bottom:16px}}
    `;
    document.head.appendChild(style);
  }

  function mount() {
    if (state.mounted || document.body?.dataset.agentVoiceOnly === '1') return;
    installStyles();loadDismissed();
    const panel = document.createElement('section');
    panel.id = 'gelato-agent-next-moves';
    panel.setAttribute('aria-label', 'Gelato Agent Next Moves');
    panel.innerHTML = '<header class="ganm-head"><div class="ganm-orb">G</div><div class="ganm-title"><strong>Next Moves</strong><span>Main Agent Brain · cross-node priorities</span></div><button class="ganm-close" type="button" aria-label="Close Next Moves">×</button></header><div class="ganm-list"></div>';
    const toggle = document.createElement('button');toggle.type='button';toggle.className='ganm-toggle';toggle.innerHTML='Next Moves <span class="ganm-count">0</span>';
    document.body.append(panel,toggle);
    panel.querySelector('.ganm-close').addEventListener('click',()=>panel.classList.remove('open'));
    toggle.addEventListener('click',()=>panel.classList.toggle('open'));
    panel.addEventListener('click', onAction);
    state.mounted=true;render();
  }

  function visibleMoves() {
    return state.moves.filter((move) => move?.key && !state.dismissed.has(String(move.key))).slice(0,8);
  }

  function render() {
    if (!state.mounted) return;
    const moves=visibleMoves();const root=document.querySelector('.ganm-list');const toggle=document.querySelector('.ganm-toggle');const count=document.querySelector('.ganm-count');
    if(count)count.textContent=String(moves.length);toggle?.classList.toggle('show',moves.length>0);
    if(!root)return;
    root.innerHTML=moves.length?moves.map((move)=>`<article class="ganm-card" data-key="${esc(move.key)}" data-severity="${esc(move.severity||'normal')}"><div class="ganm-top"><span class="ganm-sev"></span><div class="ganm-copy"><strong>${esc(move.title)}</strong><p>${esc(move.summary||'')}</p><span class="ganm-node">${esc(move.nodeLabel||move.node||'Agent node')}</span></div></div><div class="ganm-actions"><button class="ganm-review" type="button" data-next-action="review">Review</button><button class="ganm-propose" type="button" data-next-action="propose">Propose Fix</button>${move.href?`<a class="ganm-link" href="${esc(move.href)}">Open</a>`:''}<button class="ganm-dismiss" type="button" data-next-action="dismiss">Dismiss</button></div></article>`).join(''):'<div style="padding:14px;color:#777a74;font-size:10px">No active Next Moves are available from your permitted restaurant signals.</div>';
  }

  function findMove(key) { return state.moves.find((move)=>String(move.key)===String(key)); }
  async function onAction(event) {
    const action=event.target.closest('[data-next-action]');if(!action)return;
    const card=action.closest('[data-key]');const key=card?.dataset.key;const move=findMove(key);if(!move)return;
    if(action.dataset.nextAction==='dismiss'){state.dismissed.add(String(key));saveDismissed();render();return;}
    const nodeHint=`${move.nodeLabel || move.node || 'Agent node'}: `;
    const safety=action.dataset.nextAction==='propose'?'Propose the safest fix. Do not execute a consequential change until I explicitly confirm. ':'';
    document.getElementById('gelato-agent-next-moves')?.classList.remove('open');
    await window.GelatoGlobalAgent?.send?.(nodeHint+safety+String(move.prompt||move.title||''),false);
  }

  function accept(moves) {
    if(!Array.isArray(moves))return;
    state.moves=moves.filter((move)=>move&&typeof move==='object'&&move.key);
    render();
    if(visibleMoves().some((move)=>['critical','high'].includes(String(move.severity)))) document.querySelector('.ganm-toggle')?.classList.add('show');
  }

  async function csrfToken() {
    if(state.csrf)return state.csrf;
    const response=await fetch('api/agent-workspace.php?action=bootstrap',{cache:'no-store',headers:{Accept:'application/json'}});
    if(!response.ok)return '';
    const data=await response.json().catch(()=>null);state.csrf=String(data?.csrf||'');return state.csrf;
  }

  async function synthesizeProactiveEvents() {
    try {
      const csrf=await csrfToken();if(!csrf)return;
      await fetch('api/brain-events.php',{method:'POST',cache:'no-store',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({action:'synthesize',csrf_token:csrf})});
    } catch {}
  }

  async function refresh() {
    try {
      const response=await fetch('api/brain-orchestrator.php?action=snapshot',{cache:'no-store',headers:{Accept:'application/json'}});
      if(response.status===401||response.status===403)return;
      const data=await response.json().catch(()=>null);if(!response.ok||!data?.ok)return;
      accept(data.data?.nextMoves||[]);
      synthesizeProactiveEvents();
    } catch {}
  }

  function start() {
    mount();refresh();if(state.timer)clearInterval(state.timer);state.timer=setInterval(refresh,60000);
  }

  window.addEventListener('gelato-agent-ready',start);
  window.addEventListener('gelato-agent-response',(event)=>{const moves=event.detail?.result?.data?.nextMoves;if(Array.isArray(moves))accept(moves);});
  window.GelatoAgentNextMoves={refresh,accept,get moves(){return visibleMoves();}};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});else mount();
})();