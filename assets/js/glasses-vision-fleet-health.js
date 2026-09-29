(()=>{'use strict';
const boot=window.GELATO_VISION_LAB||{},root=document.getElementById('vlFleetHealth'),analyze=document.getElementById('vlFleetHealthAnalyze');
if(!root)return;let catalog=boot.catalog||{};
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
async function post(payload){const r=await fetch(boot.apiUrl,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({...payload,csrfToken:boot.csrfToken})});const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Vision Lab request failed.');return j;}
async function refresh(){const r=await fetch(boot.apiUrl,{credentials:'same-origin'}),j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Refresh failed.');catalog=j.catalog||{};render();}
function render(){
 const data=catalog.fleetHealth||{},rows=data.analyses||[];
 root.innerHTML=rows.length?rows.map(a=>'<div class="vl-fleet-row"><div><strong>'+esc(a.fleetState)+' · '+esc(a.recommendation)+'</strong><small>'+esc(a.modelPackagePublicId)+' · '+esc(a.rolloutPublicId||'no rollout')+' · '+esc(a.windowStartedAt)+' → '+esc(a.windowEndedAt)+'</small><small>devices '+Number(a.evidence?.counts?.devices||0)+' · locations '+Number(a.evidence?.counts?.locations||0)+' · affected '+Number(a.evidence?.counts?.affectedDevices||0)+' devices</small><code>'+esc(a.analysisHash)+'</code></div><div class="vl-fleet-actions"><button data-verify="'+esc(a.publicId)+'">Verify</button>'+(a.recommendation==='rollback_review'&&a.rolloutPublicId?'<button data-rollback="'+esc(a.publicId)+'">Rollback</button>':'')+'</div></div>').join(''):'<div class="vl-empty">No fleet-health analyses yet.</div>';
 root.querySelectorAll('[data-verify]').forEach(b=>b.addEventListener('click',async()=>{try{const j=await post({action:'fleet_health.verify',publicId:b.dataset.verify});alert(j.verification.passed?'Fleet health analysis verified.':'Fleet health integrity failed.');}catch(e){alert(e.message);}}));
 root.querySelectorAll('[data-rollback]').forEach(b=>b.addEventListener('click',async()=>{const reason=prompt('Document the rollback reason');if(!reason)return;try{await post({action:'fleet_health.rollback',publicId:b.dataset.rollback,reason});await refresh();}catch(e){alert(e.message);}}));
}
analyze?.addEventListener('click',async()=>{const rolloutPublicId=prompt('Rollout public ID');if(!rolloutPublicId)return;const to=new Date(),from=new Date(to.getTime()-24*60*60*1000);try{await post({action:'fleet_health.analyze',rolloutPublicId,windowStartedAt:from.toISOString(),windowEndedAt:to.toISOString()});await refresh();}catch(e){alert(e.message);}});
render();
})();