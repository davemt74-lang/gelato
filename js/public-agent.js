(() => {
  'use strict';
  if (document.querySelector('[data-public-agent-root]')) return;

  const esc = value => String(value ?? '').replace(/[&<>'"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[ch]));
  const history = [];
  let config = null;
  let sending = false;

  function injectStyles() {
    const style = document.createElement('style');
    style.textContent = `
      .public-agent-launcher{position:fixed;z-index:2200;right:22px;bottom:22px;display:flex;align-items:center;gap:9px;min-height:50px;padding:10px 16px;border:0;border-radius:999px;color:#fff;background:var(--brand-dark,#171b1a);box-shadow:0 18px 45px rgba(0,0,0,.24);font:800 13px/1 system-ui;cursor:pointer}.public-agent-launcher i{display:grid;place-items:center;width:28px;height:28px;border-radius:10px;background:var(--brand-primary,#d94a2b);font-style:normal;font-size:15px}.public-agent-launcher:hover{transform:translateY(-1px)}
      .public-agent-panel{position:fixed;z-index:2201;right:22px;bottom:84px;width:min(390px,calc(100vw - 28px));height:min(590px,calc(100vh - 120px));display:grid;grid-template-rows:auto minmax(0,1fr) auto;border:1px solid rgba(0,0,0,.11);border-radius:22px;overflow:hidden;background:#fff;box-shadow:0 28px 80px rgba(0,0,0,.28);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.public-agent-panel[hidden]{display:none}.public-agent-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:15px 16px;color:#fff;background:linear-gradient(135deg,var(--brand-dark,#171b1a),#303835)}.public-agent-identity{display:flex;gap:10px;align-items:center}.public-agent-mark{width:38px;height:38px;display:grid;place-items:center;border-radius:12px;background:var(--brand-primary,#d94a2b);font-size:18px}.public-agent-identity strong{display:block;font-size:13px}.public-agent-identity span{display:block;margin-top:3px;color:rgba(255,255,255,.65);font-size:10px}.public-agent-close{width:34px;height:34px;border:1px solid rgba(255,255,255,.18);border-radius:10px;color:#fff;background:rgba(255,255,255,.08);font-size:20px;cursor:pointer}
      .public-agent-messages{display:grid;align-content:start;gap:12px;padding:16px;overflow:auto;background:#f7f6f2}.public-agent-message{display:grid;gap:5px;max-width:88%}.public-agent-message.assistant{justify-self:start}.public-agent-message.user{justify-self:end}.public-agent-bubble{padding:11px 12px;border:1px solid #dddcd5;border-radius:15px;background:#fff;color:#242622;font-size:12px;line-height:1.5;white-space:pre-wrap;word-break:break-word}.public-agent-message.user .public-agent-bubble{border-color:var(--brand-dark,#171b1a);color:#fff;background:var(--brand-dark,#171b1a)}.public-agent-sources{display:flex;flex-wrap:wrap;gap:5px}.public-agent-source{padding:4px 7px;border-radius:999px;color:#5c625d;background:#e9e8e2;font-size:9px;font-weight:800}.public-agent-typing{display:inline-flex;gap:4px;align-items:center}.public-agent-typing i{width:5px;height:5px;border-radius:50%;background:#858a84;animation:publicAgentPulse 1s infinite}.public-agent-typing i:nth-child(2){animation-delay:.15s}.public-agent-typing i:nth-child(3){animation-delay:.3s}@keyframes publicAgentPulse{0%,70%,100%{opacity:.3;transform:translateY(0)}35%{opacity:1;transform:translateY(-2px)}}
      .public-agent-compose{padding:12px;border-top:1px solid #dddcd5;background:#fff}.public-agent-compose-box{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:end;padding:7px;border:1px solid #d5d4ce;border-radius:15px}.public-agent-input{min-height:38px;max-height:100px;padding:9px;border:0;outline:0;resize:none;color:#20221f;background:transparent;font:12px/1.45 inherit}.public-agent-send{width:40px;height:40px;border:0;border-radius:11px;color:#fff;background:var(--brand-primary,#d94a2b);font-weight:900;cursor:pointer}.public-agent-send:disabled{opacity:.5;cursor:not-allowed}.public-agent-note{margin:7px 3px 0;color:#777b76;font-size:9px;line-height:1.35;text-align:center}
      @media(max-width:560px){.public-agent-launcher{right:14px;bottom:14px}.public-agent-panel{inset:12px;width:auto;height:auto;border-radius:18px}.public-agent-launcher span{display:none}}
    `;
    document.head.appendChild(style);
  }

  function message(role, text, sources = []) {
    const container = document.getElementById('publicAgentMessages');
    if (!container) return;
    const item = document.createElement('div');
    item.className = `public-agent-message ${role}`;
    item.innerHTML = `<div class="public-agent-bubble">${esc(text)}</div>${sources.length ? `<div class="public-agent-sources">${sources.slice(0,6).map(source=>`<span class="public-agent-source">${esc(source)}</span>`).join('')}</div>` : ''}`;
    container.appendChild(item);
    container.scrollTop = container.scrollHeight;
  }

  function setTyping(show) {
    const existing = document.getElementById('publicAgentTyping');
    if (!show) { existing?.remove(); return; }
    if (existing) return;
    const container = document.getElementById('publicAgentMessages');
    const item = document.createElement('div');
    item.id = 'publicAgentTyping';
    item.className = 'public-agent-message assistant';
    item.innerHTML = '<div class="public-agent-bubble"><span class="public-agent-typing"><i></i><i></i><i></i></span></div>';
    container.appendChild(item);
    container.scrollTop = container.scrollHeight;
  }

  function openPanel() {
    const panel = document.getElementById('publicAgentPanel');
    panel.hidden = false;
    document.getElementById('publicAgentLauncher').setAttribute('aria-expanded', 'true');
    setTimeout(() => document.getElementById('publicAgentInput')?.focus(), 0);
  }

  function closePanel() {
    document.getElementById('publicAgentPanel').hidden = true;
    document.getElementById('publicAgentLauncher').setAttribute('aria-expanded', 'false');
    document.getElementById('publicAgentLauncher').focus();
  }

  async function send() {
    const input = document.getElementById('publicAgentInput');
    const sendButton = document.getElementById('publicAgentSend');
    const text = input.value.trim();
    if (!text || sending) return;
    if (text.length > 1000) {
      message('assistant', 'Please shorten your question to 1,000 characters or fewer.');
      return;
    }
    sending = true;
    sendButton.disabled = true;
    input.disabled = true;
    input.value = '';
    message('user', text);
    setTyping(true);
    try {
      const response = await fetch('api/public-agent.php', {
        method:'POST',
        headers:{Accept:'application/json','Content-Type':'application/json'},
        body:JSON.stringify({message:text,history:history.slice(-6)}),
      });
      const data = await response.json();
      if (!response.ok || data.ok === false) throw new Error(data.message || 'The assistant could not answer right now.');
      setTyping(false);
      message('assistant', data.reply, data.sources || []);
      history.push({role:'user',content:text},{role:'assistant',content:data.reply});
      if (history.length > 12) history.splice(0, history.length - 12);
    } catch (error) {
      setTyping(false);
      message('assistant', error.message || 'The assistant could not answer right now.');
    } finally {
      sending = false;
      sendButton.disabled = false;
      input.disabled = false;
      input.focus();
    }
  }

  function mount(agent) {
    injectStyles();
    config = agent;
    const root = document.createElement('div');
    root.dataset.publicAgentRoot = 'true';
    root.innerHTML = `
      <button class="public-agent-launcher" id="publicAgentLauncher" type="button" aria-haspopup="dialog" aria-controls="publicAgentPanel" aria-expanded="false"><i>✦</i><span>Ask ${esc(agent.name || 'our assistant')}</span></button>
      <section class="public-agent-panel" id="publicAgentPanel" role="dialog" aria-modal="false" aria-label="${esc(agent.name || 'Restaurant Assistant')}" hidden>
        <header class="public-agent-head"><div class="public-agent-identity"><span class="public-agent-mark">✦</span><div><strong>${esc(agent.name || 'Restaurant Assistant')}</strong><span>Public information assistant</span></div></div><button class="public-agent-close" id="publicAgentClose" type="button" aria-label="Close assistant">×</button></header>
        <div class="public-agent-messages" id="publicAgentMessages" aria-live="polite"></div>
        <footer class="public-agent-compose"><div class="public-agent-compose-box"><textarea class="public-agent-input" id="publicAgentInput" maxlength="1000" rows="1" placeholder="${esc(agent.inputPlaceholder || 'Ask a question…')}"></textarea><button class="public-agent-send" id="publicAgentSend" type="button" aria-label="Send question">↑</button></div><div class="public-agent-note">Answers come from the restaurant’s public Knowledge Center. Do not submit sensitive personal information.</div></footer>
      </section>`;
    document.body.appendChild(root);
    message('assistant', agent.welcomeMessage || 'Hi! How can I help?');
    document.getElementById('publicAgentLauncher').onclick = openPanel;
    document.getElementById('publicAgentClose').onclick = closePanel;
    document.getElementById('publicAgentSend').onclick = send;
    document.getElementById('publicAgentInput').addEventListener('keydown', event => {
      if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); send(); }
    });
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape' && !document.getElementById('publicAgentPanel').hidden) closePanel();
    });
  }

  async function init() {
    try {
      const response = await fetch('api/public-agent.php', {headers:{Accept:'application/json'},cache:'no-store'});
      const data = await response.json();
      if (!response.ok || !data.enabled || !data.ready || !data.agent) return;
      mount(data.agent);
    } catch {}
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
