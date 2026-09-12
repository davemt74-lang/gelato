(() => {
  'use strict';
  if (!window.RESTAURANT_SERVER_SESSION) return;
  const Auth = window.RestaurantAuth;
  if (!Auth) return;
  const restaurantIntent = /\b(wholesale|buyer|lead|prospect|foodservice|sample|quote|private label|pipeline|recipe|formula|ingredient|yield|online recipe|recipe image|recipe source|equipment|oven|mixer|freezer|cooler|dish machine|maintenance|repair|warranty|service company|floor\s*plan|layout|located|placement|inventory|stock|par|reorder|shortage|task|tasks|prep|opening|closing|cleaning|assignment|assigned|overdue|task category)\b/i;
  const operationsIntent = /\b(inventory|stock|par|reorder|shortage|running out|task|tasks|prep|opening|closing|cleaning|assignment|assigned|overdue|task category)\b/i;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  const chatKey = 'restaurant-owner-agent-chat-v1';
  let busy = false;

  function shouldHandle(text) { return restaurantIntent.test(text); }
  function isOperations(text) { return operationsIntent.test(text); }
  function cleanWakePhrase(text) { return String(text || '').trim().replace(/^hey\s+gelato[,\s]*/i, ''); }
  function now() { return new Date().toISOString(); }
  function readHistory() { try { return JSON.parse(localStorage.getItem(chatKey) || '[]'); } catch { return []; } }
  function saveMessage(role, text) {
    const messages = readHistory();
    messages.push({id:`owner-message-${Date.now().toString(36)}-${Math.random().toString(36).slice(2,7)}`,role,text,actionId:null,createdAt:now()});
    localStorage.setItem(chatKey, JSON.stringify(messages.slice(-60)));
  }
  function appendMessage(role, text, pending = false) {
    const root = document.getElementById('ownerAgentMessages'); if (!root) return null;
    const user = Auth.current() || {}; const article = document.createElement('article');
    article.className = `owner-agent-message ${role === 'user' ? 'user' : 'agent'}`; if (pending) article.dataset.restaurantAgentPending = '1';
    article.innerHTML = `<span class="owner-agent-avatar">${role === 'user' ? esc(user.initials || (user.firstName?.[0] || '') + (user.lastName?.[0] || '') || 'U') : '✦'}</span><div class="owner-agent-bubble"><header><strong>${role === 'user' ? esc(user.displayName || 'You') : 'Restaurant AI Agent'}</strong><time>${new Intl.DateTimeFormat('en-US',{month:'short',day:'numeric',hour:'numeric',minute:'2-digit'}).format(new Date())}</time></header><p>${esc(text).replace(/\n/g,'<br>')}</p></div>`;
    const empty = root.querySelector('.admin-empty'); if (empty) empty.remove(); root.appendChild(article); root.scrollTop = root.scrollHeight; return article;
  }
  async function submit(rawText) {
    if (busy) return; const text=cleanWakePhrase(rawText); if(!text)return; busy = true;
    const input = document.getElementById('ownerAgentInput'); if (input) input.value = '';
    saveMessage('user', rawText); appendMessage('user', rawText);
    const pending = appendMessage('agent', 'Checking restaurant operations…', true); const guidance = document.getElementById('ownerComposerGuidance');
    if (guidance) guidance.textContent = isOperations(text) ? 'Gelato is checking tasks, prep and inventory.' : 'Restaurant Agent is checking private business knowledge.';
    try {
      const endpoint = isOperations(text) ? 'api/operations-agent.php' : 'api/agent-brain.php';
      const response = await fetch(endpoint,{method:'POST',cache:'no-store',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-Token':window.RESTAURANT_CSRF_TOKEN || ''},body:JSON.stringify({action:'ask',message:text,csrf_token:window.RESTAURANT_CSRF_TOKEN || ''})});
      const data = await response.json(); if (!response.ok || !data.ok) throw new Error(data.message || 'The Restaurant Agent could not complete that request.');
      if (pending) pending.remove(); saveMessage('agent', data.answer || data.reply || 'Done.'); appendMessage('agent', data.answer || data.reply || 'Done.');
      if (guidance) guidance.textContent = `Restaurant Agent skill: ${data.skill || 'knowledge.search'}`;
    } catch (error) {
      if (pending) pending.remove(); const message = error.message || 'The Restaurant Agent is unavailable.'; saveMessage('agent', message); appendMessage('agent', message); if (guidance) guidance.textContent = 'Restaurant Agent request could not be completed.';
    } finally { busy = false; }
  }
  function maybeSubmit(event) {
    const input = document.getElementById('ownerAgentInput'); const text = input?.value.trim() || ''; if (!text || !shouldHandle(text)) return false;
    event.preventDefault(); event.stopPropagation(); event.stopImmediatePropagation(); submit(text); return true;
  }
  function installVoice() {
    const input=document.getElementById('ownerAgentInput'); if(!input||document.getElementById('gelatoVoiceButton'))return;
    const button=document.createElement('button');button.id='gelatoVoiceButton';button.type='button';button.title='Speak to Gelato';button.setAttribute('aria-label','Speak to Gelato');button.textContent='🎙';button.style.cssText='border:1px solid #d9d9d2;background:#fff;border-radius:10px;min-width:38px;height:38px;cursor:pointer;margin-right:6px';
    const send=document.getElementById('ownerAgentSend');if(send&&send.parentNode)send.parentNode.insertBefore(button,send);else input.parentNode?.appendChild(button);
    button.addEventListener('click',()=>{const SR=window.SpeechRecognition||window.webkitSpeechRecognition;if(!SR){const guidance=document.getElementById('ownerComposerGuidance');if(guidance)guidance.textContent='Voice recognition is not available in this browser.';return;}const rec=new SR();rec.lang='en-US';rec.interimResults=false;rec.continuous=false;button.textContent='●';rec.onresult=e=>{const text=[...e.results].map(r=>r[0].transcript).join(' ');input.value=text;if(shouldHandle(text))submit(text);};rec.onerror=e=>{const guidance=document.getElementById('ownerComposerGuidance');if(guidance)guidance.textContent='Voice recognition error: '+e.error;};rec.onend=()=>button.textContent='🎙';rec.start();});
    const opsLink=document.createElement('a');opsLink.href='operations.php';opsLink.textContent='Operations';opsLink.style.cssText='font-size:10px;font-weight:800;margin-left:8px;color:inherit;text-decoration:none';send?.parentNode?.appendChild(opsLink);
  }
  document.addEventListener('click', event => { if (event.target.closest('#ownerAgentSend')) maybeSubmit(event); }, true);
  document.addEventListener('keydown', event => { if (event.target?.id === 'ownerAgentInput' && event.key === 'Enter' && !event.shiftKey) maybeSubmit(event); }, true);
  const input = document.getElementById('ownerAgentInput'); if (input) input.placeholder = 'Ask or say: Hey Gelato, add to prep list… check inventory… assign tasks…';
  installVoice();
})();