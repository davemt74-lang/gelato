(()=>{'use strict';
const boot=window.GELATO_VISION_LAB||{},root=document.getElementById('vlReworkCases');if(!root)return;
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
const rows=(boot.catalog?.reworkCases)||[];
root.innerHTML=rows.length?rows.map(r=>'<div class="vl-step-row"><div><strong><span class="vl-step-state">'+esc(r.status)+'</span> · Rework case</strong><small>Source '+esc(r.sourceFinalValidationPublicId)+' · latest '+esc(r.latestFinalValidationPublicId||'—')+'</small><small>'+esc(r.resolution?.resolvedByFinalValidationPublicId?'Resolved by '+r.resolution.resolvedByFinalValidationPublicId:'Awaiting corrected-scene revalidation')+'</small><code>'+esc(r.caseKey)+'</code></div></div>').join(''):'<div class="vl-empty">No rework cases yet. Rework actions are performed from the authenticated glasses device; Vision Lab is audit-only.</div>';
})();