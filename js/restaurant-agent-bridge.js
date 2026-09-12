(() => {
  'use strict';
  if (!window.RESTAURANT_SERVER_SESSION) return;
  const Auth = window.RestaurantAuth;
  if (!Auth) return;
  const intent = /\b(wholesale|buyer|lead|prospect|foodservice|sample|quote|private label|pipeline|recipe|formula|ingredient|yield|online recipe|recipe image|recipe source|equipment|oven|mixer|freezer|cooler|dish machine|maintenance|repair|warranty|service company|floor\s*plan|layout|located|placement)\b/i;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  const chatKey = 'restaurant-owner-agent-chat-v1';
  let busy = false;

  function shouldHandle(text) {
    return intent.test(text);
  }
  function now() { return new Date().toISOString(); }
  function readHistory() {
    try { return JSON.parse(localStorage.getItem(chatKey) || '[]'); } catch { return []; }
  }
  function saveMessage(role, text) {
    const messages = readHistory();
    messages.push({id:`owner-message-${Date.now().toString(36)}-${Math.random().toString(36).slice(2,7)}`,role,text,actionId:null,createdAt:now()});
    localStorage.setItem(chatKey, JSON.stringify(messages.slice(-60)));
  }
  function appendMessage(role, text, pending = false) {
    const root = document.getElementById('ownerAgentMessages');
    if (!root) return null;
    const user = Auth.current() || {};
    const article = document.createElement('article');
    article.className = `owner-agent-message ${role === 'user' ? 'user' : 'agent'}`;
    if (pending) article.dataset.restaurantAgentPending = '1';
    article.innerHTML = `<span class="owner-agent-avatar">${role === 'user' ? esc(user.initials || (user.firstName?.[0] || '') + (user.lastName?.[0] || '') || 'U') : '✦'}</span><div class="owner-agent-bubble"><header><strong>${role === 'user' ? esc(user.displayName || 'You') : 'Restaurant AI Agent'}</strong><time>${new Intl.DateTimeFormat('en-US',{month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}).format(new Date())}</time></header><p>${esc(text).replace(/\n/g,'<br>')}</p></div>`;
    const empty = root.querySelector('.admin-empty'); if (empty) empty.remove();
    root.appendChild(article); root.scrollTop = root.scrollHeight;
    return article;
  }
  async function submit(text) {
    if (busy) return;
    busy = true;
    const input = document.getElementById('ownerAgentInput');
    if (input) input.value = '';
    saveMessage('user', text); appendMessage('user', text);
    const pending = appendMessage('agent', 'Checking the private restaurant knowledge base…', true);
    const guidance = document.getElementById('ownerComposerGuidance');
    if (guidance) guidance.textContent = 'Restaurant Agent is checking wholesale, recipes, equipment, and operations knowledge.';
    try {
      const response = await fetch('api/agent-brain.php', {
        method:'POST', cache:'no-store', headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-Token':window.RESTAURANT_CSRF_TOKEN || ''},
        body:JSON.stringify({action:'ask',message:text,csrf_token:window.RESTAURANT_CSRF_TOKEN || ''})
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.message || 'The Restaurant Agent could not answer that request.');
      if (pending) pending.remove();
      saveMessage('agent', data.answer || data.reply || 'No answer returned.');
      appendMessage('agent', data.answer || data.reply || 'No answer returned.');
      if (guidance) guidance.textContent = `Restaurant Agent skill: ${data.skill || 'knowledge.search'}`;
    } catch (error) {
      if (pending) pending.remove();
      const text = error.message || 'The Restaurant Agent is unavailable.';
      saveMessage('agent', text); appendMessage('agent', text);
      if (guidance) guidance.textContent = 'Restaurant Agent request could not be completed.';
    } finally { busy = false; }
  }
  function maybeSubmit(event) {
    const input = document.getElementById('ownerAgentInput');
    const text = input?.value.trim() || '';
    if (!text || !shouldHandle(text)) return false;
    event.preventDefault(); event.stopPropagation(); event.stopImmediatePropagation();
    submit(text);
    return true;
  }
  document.addEventListener('click', event => {
    if (event.target.closest('#ownerAgentSend')) maybeSubmit(event);
  }, true);
  document.addEventListener('keydown', event => {
    if (event.target?.id === 'ownerAgentInput' && event.key === 'Enter' && !event.shiftKey) maybeSubmit(event);
  }, true);
  const input = document.getElementById('ownerAgentInput');
  if (input) input.placeholder = 'Ask about wholesale leads, recipes, equipment, maintenance, hiring, training, or operations…';
})();
