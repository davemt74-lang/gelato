(() => {
  'use strict';
  if (window.GelatoKdsAgentSidebar) return;

  const ANNOUNCE_KEY = 'gelato.kds.voiceNewOrders.v1';
  const observedFetch = window.fetch.bind(window);
  const state = {
    open: false,
    ready: false,
    busy: false,
    listening: false,
    announceNewOrders: false,
    boards: new Map(),
    lastBoard: null,
    lastLocationId: 0,
    voiceQueue: [],
    speaking: false,
  };

  try { state.announceNewOrders = localStorage.getItem(ANNOUNCE_KEY) === '1'; } catch {}

  const clean = (value, max = 1200) => String(value ?? '')
    .replace(/[\u0000-\u001f\u007f]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, max);
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (ch) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
  const fmtQty = (value) => {
    const number = Number(value || 0);
    return Number.isInteger(number) ? String(number) : number.toFixed(2).replace(/0+$/,'').replace(/\.$/,'');
  };

  function hasPermission(permission) {
    const permissions = window.GelatoGlobalAgent?.permissions || [];
    return permissions.includes('*') || permissions.includes(permission);
  }

  function canVoiceOutput() {
    return hasPermission('voice.agent');
  }

  function targetFile(input) {
    let value = '';
    if (typeof input === 'string') value = input;
    else if (input instanceof URL) value = input.toString();
    else if (typeof Request !== 'undefined' && input instanceof Request) value = input.url;
    try { return new URL(value, location.href).pathname.split('/').pop() || ''; }
    catch { return value.split('?')[0].split('/').pop() || ''; }
  }

  function styles() {
    if (document.querySelector('style[data-kds-agent-sidebar]')) return;
    const style = document.createElement('style');
    style.dataset.kdsAgentSidebar = '1';
    style.textContent = `
      .kds-agent-handle{position:fixed;z-index:2147482500;right:0;top:50%;transform:translateY(-50%);width:52px;min-height:138px;border:1px solid #424a43;border-right:0;border-radius:16px 0 0 16px;background:#171b18;color:#f7f8f4;box-shadow:-10px 0 26px rgba(0,0,0,.28);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:9px;cursor:pointer;font:900 10px/1 Inter,system-ui,sans-serif;letter-spacing:.08em;text-transform:uppercase}
      .kds-agent-handle .mark{width:34px;height:34px;border-radius:11px;background:#ef6a3a;color:#111;display:grid;place-items:center;font-size:18px;letter-spacing:0}.kds-agent-handle .label{writing-mode:vertical-rl;transform:rotate(180deg)}.kds-agent-handle .dot{width:7px;height:7px;border-radius:50%;background:#757c76}.kds-agent-handle .dot.on{background:#61c48a;box-shadow:0 0 0 4px rgba(97,196,138,.12)}
      .kds-agent-panel{position:fixed;z-index:2147482600;inset:0 0 0 auto;width:min(400px,94vw);background:#101310;color:#f7f8f4;border-left:1px solid #343b35;box-shadow:-24px 0 60px rgba(0,0,0,.5);transform:translateX(103%);transition:transform .2s ease;display:flex;flex-direction:column;font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif}.kds-agent-panel.open{transform:translateX(0)}
      .kds-agent-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px;border-bottom:1px solid #343b35;background:#171b18}.kds-agent-title{display:flex;align-items:center;gap:10px}.kds-agent-logo{width:38px;height:38px;border-radius:12px;background:#ef6a3a;color:#111;display:grid;place-items:center;font-size:18px;font-weight:950}.kds-agent-title strong{display:block;font-size:13px}.kds-agent-title small{display:block;margin-top:3px;color:#9da59e;font-size:9px}.kds-agent-close{width:38px;height:38px;border:1px solid #3c443d;border-radius:10px;background:#202520;color:#fff;font-size:20px;cursor:pointer}
      .kds-agent-body{flex:1;overflow:auto;padding:16px;display:flex;flex-direction:column;gap:13px}.kds-agent-context{padding:10px 11px;border:1px solid #303731;border-radius:11px;background:#171b18;color:#bdc5be;font-size:10px;line-height:1.45}.kds-agent-context strong{color:#fff}.kds-agent-voice{display:grid;place-items:center;gap:10px;padding:20px 10px 12px}.kds-agent-mic{width:110px;height:110px;border:0;border-radius:50%;background:#ef6a3a;color:#111;box-shadow:0 0 0 10px rgba(239,106,58,.08);font-size:38px;cursor:pointer;transition:transform .14s,box-shadow .14s}.kds-agent-mic:hover{transform:scale(1.03)}.kds-agent-mic.busy{animation:kdsAgentPulse 1.2s infinite}.kds-agent-mic:disabled{opacity:.45;cursor:not-allowed}.kds-agent-voice strong{font-size:15px}.kds-agent-voice p{margin:0;color:#9da59e;text-align:center;font-size:10px;line-height:1.5;max-width:300px}
      .kds-agent-toggle{width:100%;display:flex;align-items:center;justify-content:space-between;gap:10px;border:1px solid #343b35;border-radius:12px;background:#171b18;color:#fff;padding:11px 12px;cursor:pointer;text-align:left}.kds-agent-toggle strong{display:block;font-size:10px}.kds-agent-toggle small{display:block;color:#9da59e;font-size:8px;margin-top:3px;line-height:1.4}.kds-agent-switch{width:36px;height:20px;border-radius:999px;background:#444a45;padding:3px;transition:background .15s;flex:0 0 auto}.kds-agent-switch::after{content:"";display:block;width:14px;height:14px;border-radius:50%;background:#fff;transition:transform .15s}.kds-agent-toggle.on .kds-agent-switch{background:#2d8057}.kds-agent-toggle.on .kds-agent-switch::after{transform:translateX(16px)}.kds-agent-toggle:disabled{opacity:.45;cursor:not-allowed}
      .kds-agent-quick{width:100%;border:1px solid #3c443d;border-radius:11px;background:#202520;color:#fff;padding:10px 12px;font-size:10px;font-weight:900;cursor:pointer}.kds-agent-quick:disabled{opacity:.45;cursor:not-allowed}.kds-agent-card{border:1px solid #303731;border-radius:12px;background:#171b18;padding:12px}.kds-agent-card .eyebrow{font-size:8px;font-weight:900;letter-spacing:.11em;text-transform:uppercase;color:#8f978f;margin-bottom:7px}.kds-agent-card .copy{font-size:11px;line-height:1.55;color:#eef1ed;white-space:pre-wrap}.kds-agent-card.user .copy{color:#ffd7c8}.kds-agent-status{min-height:38px;padding:10px 11px;border-radius:10px;background:#202520;color:#cad1cb;font-size:10px;line-height:1.4}.kds-agent-status.good{color:#aee9c6;background:#173126}.kds-agent-status.warn{color:#ffdba1;background:#302719}.kds-agent-status.bad{color:#ffb2ac;background:#351f1e}
      .kds-agent-foot{padding:12px 14px;border-top:1px solid #343b35;color:#8f978f;font-size:8px;line-height:1.45;background:#131713}.kds-agent-foot strong{color:#cbd1cc}
      @keyframes kdsAgentPulse{50%{box-shadow:0 0 0 17px rgba(239,106,58,.13)}}
      @media(max-width:700px){.kds-agent-handle{min-height:116px;width:46px}.kds-agent-panel{width:min(370px,96vw)}.kds-agent-mic{width:96px;height:96px}}
    `;
    document.head.appendChild(style);
  }

  function mount() {
    if (document.getElementById('kdsAgentPanel')) return;
    styles();
    document.body.insertAdjacentHTML('beforeend', `
      <button class="kds-agent-handle" id="kdsAgentHandle" type="button" aria-controls="kdsAgentPanel" aria-expanded="false" title="Open Gelato voice agent"><span class="mark">✦</span><span class="label">Agent</span><span class="dot" id="kdsAgentDot"></span></button>
      <aside class="kds-agent-panel" id="kdsAgentPanel" aria-label="Gelato voice agent" aria-hidden="true">
        <header class="kds-agent-head"><div class="kds-agent-title"><span class="kds-agent-logo">✦</span><span><strong>Gelato Voice Agent</strong><small>KDS · voice-only kitchen assistant</small></span></div><button class="kds-agent-close" id="kdsAgentClose" type="button" aria-label="Close Agent">×</button></header>
        <div class="kds-agent-body">
          <div class="kds-agent-context" id="kdsAgentContext"><strong>Kitchen context</strong><br>Waiting for the live KDS board…</div>
          <section class="kds-agent-voice"><button class="kds-agent-mic" id="kdsAgentMic" type="button" aria-label="Ask Gelato by voice">🎙</button><strong>Tap and speak</strong><p>Ask about any active order, table, ticket number, item, special instruction, station load, late ticket, ready order, All Day count, or recent completed order.</p></section>
          <button class="kds-agent-toggle" id="kdsAgentListen" type="button" aria-pressed="false"><span><strong>Hands-free “Hey Gelato”</strong><small>Listen for the wake phrase while the KDS stays open.</small></span><span class="kds-agent-switch" aria-hidden="true"></span></button>
          <button class="kds-agent-toggle" id="kdsAgentAnnounce" type="button" aria-pressed="false"><span><strong>Voice new-order announcements</strong><small>Read each new kitchen order—and later fired additions to that order—as it arrives.</small></span><span class="kds-agent-switch" aria-hidden="true"></span></button>
          <button class="kds-agent-quick" id="kdsAgentReadOrders" type="button">🔊 Read active orders now</button>
          <div class="kds-agent-status" id="kdsAgentStatus">Connecting to Gelato Agent…</div>
          <section class="kds-agent-card user" id="kdsAgentHeard" hidden><div class="eyebrow">Heard</div><div class="copy"></div></section>
          <section class="kds-agent-card" id="kdsAgentAnswer" hidden><div class="eyebrow">Gelato</div><div class="copy"></div></section>
        </div>
        <footer class="kds-agent-foot"><strong>Same Agent Brain, same permissions.</strong> Voice identity protects spoken requests. New-order announcements are local to this KDS device and can be switched off at any time. There is no text-entry mode on the KDS display.</footer>
      </aside>
    `);

    document.getElementById('kdsAgentHandle')?.addEventListener('click', open);
    document.getElementById('kdsAgentClose')?.addEventListener('click', close);
    document.getElementById('kdsAgentMic')?.addEventListener('click', talkOnce);
    document.getElementById('kdsAgentListen')?.addEventListener('click', toggleListening);
    document.getElementById('kdsAgentAnnounce')?.addEventListener('click', toggleAnnouncements);
    document.getElementById('kdsAgentReadOrders')?.addEventListener('click', readOrdersNow);
    renderState();
  }

  function open() {
    state.open = true;
    document.getElementById('kdsAgentPanel')?.classList.add('open');
    document.getElementById('kdsAgentPanel')?.setAttribute('aria-hidden','false');
    document.getElementById('kdsAgentHandle')?.setAttribute('aria-expanded','true');
  }

  function close() {
    state.open = false;
    document.getElementById('kdsAgentPanel')?.classList.remove('open');
    document.getElementById('kdsAgentPanel')?.setAttribute('aria-hidden','true');
    document.getElementById('kdsAgentHandle')?.setAttribute('aria-expanded','false');
  }

  function setStatus(message, kind = '') {
    const element = document.getElementById('kdsAgentStatus');
    if (!element) return;
    element.textContent = clean(message, 800) || 'Ready.';
    element.className = 'kds-agent-status' + (kind ? ` ${kind}` : '');
  }

  function renderState() {
    const dot = document.getElementById('kdsAgentDot');
    dot?.classList.toggle('on', state.ready);
    const mic = document.getElementById('kdsAgentMic');
    if (mic) {
      mic.disabled = !state.ready || state.busy;
      mic.classList.toggle('busy', state.busy);
    }
    const listen = document.getElementById('kdsAgentListen');
    listen?.classList.toggle('on', state.listening);
    listen?.setAttribute('aria-pressed', state.listening ? 'true' : 'false');
    const announce = document.getElementById('kdsAgentAnnounce');
    announce?.classList.toggle('on', state.announceNewOrders);
    announce?.setAttribute('aria-pressed', state.announceNewOrders ? 'true' : 'false');
    if (announce) announce.disabled = state.ready && !canVoiceOutput();
    const read = document.getElementById('kdsAgentReadOrders');
    if (read) read.disabled = !state.ready || state.busy || !canVoiceOutput();
    renderContext();
  }

  function renderContext() {
    const element = document.getElementById('kdsAgentContext');
    if (!element) return;
    const board = state.lastBoard;
    if (!board) {
      element.innerHTML = '<strong>Kitchen context</strong><br>Waiting for the live KDS board…';
      return;
    }
    const metrics = board.metrics || {};
    const location = board.location?.name || 'Current location';
    element.innerHTML = `<strong>${esc(location)}</strong><br>${Number(metrics.tickets||0)} active tickets · ${Number(metrics.queued||0)} queued · ${Number(metrics.inProgress||0)} cooking · ${Number(metrics.ready||0)} ready · ${Number(metrics.late||0)} late · ${Number(metrics.unrouted||0)} unrouted`;
  }

  async function talkOnce() {
    if (!state.ready || state.busy) return;
    state.busy = true;
    renderState();
    setStatus('Voice check and listening…');
    try {
      await window.GelatoGlobalAgent?.talkOnce?.();
    } finally {
      state.busy = false;
      renderState();
    }
  }

  async function toggleListening() {
    if (!state.ready) return;
    const next = !state.listening;
    setStatus(next ? 'Starting hands-free listening…' : 'Stopping hands-free listening…');
    await window.GelatoGlobalAgent?.setListening?.(next);
  }

  function toggleAnnouncements() {
    if (!state.ready) return;
    if (!canVoiceOutput()) {
      setStatus('Voice Agent permission is required for spoken new-order announcements.', 'warn');
      return;
    }
    state.announceNewOrders = !state.announceNewOrders;
    try { localStorage.setItem(ANNOUNCE_KEY, state.announceNewOrders ? '1' : '0'); } catch {}
    setStatus(state.announceNewOrders ? 'Voice new-order announcements are on.' : 'Voice new-order announcements are off.', state.announceNewOrders ? 'good' : '');
    renderState();
  }

  async function readOrdersNow() {
    if (!state.ready || state.busy || !canVoiceOutput()) return;
    state.busy = true;
    renderState();
    setStatus('Gelato is reading the live KDS board…');
    try {
      const result = await window.GelatoGlobalAgent?.send?.('Read the active KDS orders aloud in concise kitchen order. Include ticket number or table, quantities, item names, and important special instructions.', false);
      const answer = result?.answer || result?.reply || '';
      if (answer) queueSpeech(answer);
    } catch (error) {
      setStatus(error?.message || 'Gelato could not read the current orders.', 'bad');
    } finally {
      state.busy = false;
      renderState();
    }
  }

  function ticketItemPhrase(item) {
    const qty = fmtQty(item.quantity);
    let phrase = `${qty} ${clean(item.item_name_snapshot, 120)}`;
    if (item.option_name_snapshot) phrase += ` ${clean(item.option_name_snapshot, 80)}`;
    if (item.special_instructions) phrase += `. Special instruction: ${clean(item.special_instructions, 220)}`;
    return phrase;
  }

  function ticketAnnouncement(ticket, addedItems = null) {
    const items = Array.isArray(addedItems) ? addedItems : (ticket.items || []);
    const update = Array.isArray(addedItems);
    const parts = [update ? `Order update ${clean(ticket.checkNumber, 80)}` : `New order ${clean(ticket.checkNumber, 80)}`];
    if (ticket.tableName) parts.push(`table ${clean(ticket.tableName, 80)}`);
    else if (ticket.serviceMode) parts.push(clean(String(ticket.serviceMode).replaceAll('_',' '), 60));
    if (!update && Number(ticket.guestCount || 0) > 0) parts.push(`${Number(ticket.guestCount)} guests`);
    const header = parts.join(', ');
    const itemText = items.map(ticketItemPhrase).join('. ');
    return `${header}. ${itemText}.`;
  }

  function queueSpeech(text) {
    const message = clean(text, 1800);
    if (!message || !canVoiceOutput() || !window.speechSynthesis) return;
    state.voiceQueue.push(message);
    pumpSpeech();
  }

  function pumpSpeech() {
    if (state.speaking || !state.voiceQueue.length || !window.speechSynthesis) return;
    const message = state.voiceQueue.shift();
    state.speaking = true;
    const utterance = new SpeechSynthesisUtterance(message);
    utterance.rate = 1;
    utterance.onend = utterance.onerror = () => {
      state.speaking = false;
      pumpSpeech();
    };
    speechSynthesis.speak(utterance);
  }

  function boardSnapshot(board) {
    const map = new Map();
    for (const ticket of board?.tickets || []) {
      map.set(String(ticket.checkPublicId || ''), new Set((ticket.items || []).map((item) => String(item.public_id || '')).filter(Boolean)));
    }
    return map;
  }

  function observeBoard(data) {
    const board = data?.board;
    if (!board || !Array.isArray(board.tickets)) return;
    const locationId = Number(data.locationId || board.location?.id || 0);
    state.lastBoard = board;
    state.lastLocationId = locationId;
    renderContext();

    const current = boardSnapshot(board);
    const previous = state.boards.get(locationId);
    state.boards.set(locationId, current);
    if (!previous) return;

    for (const ticket of board.tickets) {
      const key = String(ticket.checkPublicId || '');
      if (!key) continue;
      const priorItems = previous.get(key);
      if (!priorItems) {
        const detail = {type:'new_order',ticket,locationId};
        window.dispatchEvent(new CustomEvent('gelato-kds-new-order', {detail}));
        if (state.announceNewOrders) queueSpeech(ticketAnnouncement(ticket));
        continue;
      }
      const added = (ticket.items || []).filter((item) => item.public_id && !priorItems.has(String(item.public_id)));
      if (added.length) {
        const detail = {type:'order_update',ticket,items:added,locationId};
        window.dispatchEvent(new CustomEvent('gelato-kds-new-order', {detail}));
        if (state.announceNewOrders) queueSpeech(ticketAnnouncement(ticket, added));
      }
    }
  }

  window.fetch = async function kdsAgentObservedFetch(input, init) {
    const response = await observedFetch(input, init);
    if (targetFile(input) === 'kds.php') {
      response.clone().json().then((data) => {
        if (response.ok && data?.ok && data?.board) observeBoard(data);
      }).catch(() => {});
    }
    return response;
  };

  window.addEventListener('gelato-agent-ready', () => {
    state.ready = true;
    state.listening = Boolean(window.GelatoGlobalAgent?.listening);
    setStatus(canVoiceOutput() ? 'Voice Agent ready.' : 'Agent ready. Voice output permission is not enabled for this account.', canVoiceOutput() ? 'good' : 'warn');
    renderState();
  });
  window.addEventListener('gelato-agent-status', (event) => {
    const message = clean(event.detail?.message, 800);
    if (message) setStatus(message);
  });
  window.addEventListener('gelato-agent-voice-transcript', (event) => {
    const text = clean(event.detail?.text, 800);
    const card = document.getElementById('kdsAgentHeard');
    if (!card || !text) return;
    card.hidden = false;
    card.querySelector('.copy').textContent = text;
  });
  window.addEventListener('gelato-agent-response', (event) => {
    const answer = clean(event.detail?.answer, 1800);
    const card = document.getElementById('kdsAgentAnswer');
    if (!card || !answer) return;
    card.hidden = false;
    card.querySelector('.copy').textContent = answer;
  });
  window.addEventListener('gelato-agent-listening-change', (event) => {
    state.listening = Boolean(event.detail?.listening);
    renderState();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && state.open) {
      event.preventDefault();
      event.stopImmediatePropagation();
      close();
    }
  }, true);

  function boot() {
    mount();
    window.GelatoKdsAgentSidebar = {open, close, observeBoard, get announceNewOrders(){return state.announceNewOrders;}};
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, {once:true});
  else boot();
})();
