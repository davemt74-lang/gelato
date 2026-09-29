(()=>{'use strict';
const boot=window.GELATO_VISION_LAB||{},root=document.getElementById('vlHandoffConfirmations');if(!root)return;
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
const rows=(boot.catalog?.handoffConfirmations)||[];
root.innerHTML=rows.length?rows.map(r=>'<div class="vl-step-row"><div><strong><span class="vl-step-state">'+esc(r.status)+'</span> · Human kitchen handoff</strong><small>Final validation '+esc(r.finalValidationPublicId)+' · device '+esc(r.devicePublicId)+'</small><small>Handoff '+esc(r.handoffPublicId||'pending')+' · actor user '+esc(r.actorUserId??'—')+'</small><code>'+esc(r.confirmationKey)+'</code></div></div>').join(''):'<div class="vl-empty">No human handoff confirmations yet. Consequential confirmation is available only from the authenticated glasses device.</div>';
})();