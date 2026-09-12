(() => {
  'use strict';
  const Auth = window.RestaurantAuth;
  if (!Auth || !Auth.has('catering.view')) return;
  const csrf = String(window.RESTAURANT_CSRF_TOKEN || '');
  const ownerChatKey = 'restaurant-owner-agent-chat-v1';
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));

  function isCateringQuestion(value) {
    return /\b(catering|catered|caterer|wedding|rehearsal|private party|corporate event|office lunch|event venue|event guests|guest count|tasting|catering quote|catering contract|catering deposit|catering pipeline|upcoming events|event menu|event staffing|event rental)\b/i.test(String(value || ''));
  }

  function addOwnerMessage(role, text) {
    const messages = Auth.read(ownerChatKey, []);
    messages.push({id:`owner-catering-${Date.now().toString(36)}-${Math.random().toString(36).slice(2,7)}`,role,text,actionId:null,createdAt:new Date().toISOString()});
    Auth.write(ownerChatKey, messages.slice(-60));
    if (window.RestaurantAdmin?.onNavigate) window.RestaurantAdmin.onNavigate('owner');
  }

  async function askCatering(message) {
    if (!Auth.has('catering.agent')) return false;
    const value = String(message || '').trim();
    if (!value || !isCateringQuestion(value)) return false;
    addOwnerMessage('user', value);
    const input = document.getElementById('ownerAgentInput'); if (input) input.value = '';
    try {
      const response = await fetch('api/catering-agent.php', {method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({action:'ask',message:value,csrf_token:csrf})});
      const data = await response.json();
      if (!response.ok || data.ok === false) throw new Error(data.message || `Request failed (${response.status}).`);
      addOwnerMessage('agent', data.answer || 'No catering answer was returned.');
    } catch (error) { addOwnerMessage('agent', `Catering Agent could not complete that request: ${error.message}`); }
    return true;
  }

  function bindRouting() {
    if (!Auth.has('catering.agent')) return;
    const send=document.getElementById('ownerAgentSend'),input=document.getElementById('ownerAgentInput');
    if(send&&!send.dataset.cateringBrainBound){send.dataset.cateringBrainBound='1';send.addEventListener('click',event=>{const value=input?.value.trim()||'';if(!isCateringQuestion(value))return;event.preventDefault();event.stopImmediatePropagation();askCatering(value)},true)}
    if(input&&!input.dataset.cateringBrainBound){input.dataset.cateringBrainBound='1';input.addEventListener('keydown',event=>{if(event.key!=='Enter'||event.shiftKey)return;const value=input.value.trim();if(!isCateringQuestion(value))return;event.preventDefault();event.stopImmediatePropagation();askCatering(value)},true)}
  }

  async function installSummary() {
    if (!Auth.has('catering.agent')) return;
    const ownerPage=document.getElementById('page-owner');if(!ownerPage||ownerPage.querySelector('[data-catering-brain-card]'))return;
    try{
      const response=await fetch('api/catering-agent.php?action=summary',{headers:{Accept:'application/json'},cache:'no-store'}),data=await response.json();if(!response.ok||data.ok===false)return;
      const by=Object.fromEntries((data.summary||[]).map(row=>[row.pipeline_stage,row]));
      const stages=['new','qualified','menu_proposal','tasting','quoted','contracted','deposit_paid','confirmed'];
      const open=stages.reduce((n,s)=>n+Number(by[s]?.lead_count||0),0),value=stages.reduce((n,s)=>n+Number(by[s]?.value_total||0),0);
      const card=document.createElement('section');card.className='card';card.dataset.cateringBrainCard='1';card.style.marginTop='14px';card.innerHTML=`<div class="admin-card-head"><div><p class="eyebrow">Restaurant Agent skill</p><h4>Catering Operations</h4><p>The Agent knows catering inquiries, event dates, guest counts, menus, dietary needs, quotes, follow-ups, and pipeline state.</p></div><button class="btn btn-light" type="button" data-open-catering>Open Catering Pipeline</button></div><div class="admin-kpis" style="margin-top:12px"><article><small>Open events</small><strong>${esc(open)}</strong></article><article><small>Next 30 days</small><strong>${esc((data.upcoming||[]).length)}</strong></article><article><small>Open value</small><strong>$${Number(value).toLocaleString(undefined,{maximumFractionDigits:0})}</strong></article><article><small>Confirmed</small><strong>${esc(by.confirmed?.lead_count||0)}</strong></article></div><div class="quick-prompts" style="margin-top:12px"><button class="prompt-chip" type="button" data-catering-question="What catering events are coming up in the next 30 days?">Upcoming events</button><button class="prompt-chip" type="button" data-catering-question="Summarize the catering pipeline and weighted value.">Pipeline</button><button class="prompt-chip" type="button" data-catering-question="What catering follow-ups are due this week?">Follow-ups</button></div>`;
      const dialogue=ownerPage.querySelector('.owner-agent-dialogue');if(dialogue)dialogue.insertAdjacentElement('beforebegin',card);else ownerPage.appendChild(card);
      card.querySelector('[data-open-catering]')?.addEventListener('click',()=>{window.location.href='catering-pipeline.php'});card.querySelectorAll('[data-catering-question]').forEach(button=>button.addEventListener('click',()=>askCatering(button.dataset.cateringQuestion)));
    }catch(_){/* migration may not be installed yet */}
  }

  function install(){bindRouting();installSummary();document.querySelectorAll('[data-nav="owner"]').forEach(button=>button.addEventListener('click',()=>setTimeout(()=>{bindRouting();installSummary()},30)))}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',install,{once:true});else install();
})();
