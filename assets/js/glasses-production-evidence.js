(function(){'use strict';
const boot=window.GELATO_VISION_LAB||{},root=document.getElementById('vlProductionEvidence');if(!root)return;
let catalog=boot.catalog||{};
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
async function api(payload){
  payload.csrf_token=boot.csrfToken;
  const r=await fetch(boot.apiUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
  const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Evidence request failed.');
  await refresh();return j;
}
async function refresh(){
  const r=await fetch(boot.apiUrl,{credentials:'same-origin',cache:'no-store'}),j=await r.json();
  if(!r.ok||!j.ok)throw new Error(j.message||'Evidence refresh failed.');
  catalog=j.catalog||{};render();
}
function render(){
  const items=catalog.productionEvidence?.evidence||[];
  if(!items.length){root.innerHTML='<div class="vl-empty">No V11 production evidence captured yet.</div>';return;}
  root.innerHTML=items.map(e=>{
    const lineage=[e.operatorName||'Unknown operator',e.devicePublicId||'No device',e.stationPublicId||'No station',e.programPublicId||'No program'].join(' · ');
    const model=e.modelPackagePublicId?('model '+e.modelPackagePublicId):'model unbound';
    const context=[e.buildSessionPublicId||'no build',e.recipeStepKey||'no step',e.validationOutcome||'no validation',model].join(' · ');
    return '<article class="vl-pilot-card"><div class="vl-head"><div><strong>'+esc(e.publicId)+'</strong><small>'+esc(lineage)+'</small></div><span class="vl-state '+esc(e.trainingEligibility)+'">'+esc(e.trainingEligibility)+'</span></div><small>'+esc(context)+'</small><div class="inline-actions"><button data-evidence-review="'+esc(e.publicId)+'">Review</button><button data-evidence-eligible="'+esc(e.publicId)+'">Eligible</button><button data-evidence-exclude="'+esc(e.publicId)+'">Exclude</button></div></article>';
  }).join('');
}
root.addEventListener('click',async e=>{
  const b=e.target.closest('button');if(!b)return;
  try{
    if(b.dataset.evidenceReview)await api({action:'training.evidence_eligibility',evidencePublicId:b.dataset.evidenceReview,eligibility:'review'});
    else if(b.dataset.evidenceEligible)await api({action:'training.evidence_eligibility',evidencePublicId:b.dataset.evidenceEligible,eligibility:'eligible'});
    else if(b.dataset.evidenceExclude){
      const reason=prompt('Exclusion reason');if(!reason)return;
      await api({action:'training.evidence_eligibility',evidencePublicId:b.dataset.evidenceExclude,eligibility:'excluded',reason});
    }
  }catch(err){alert(err.message);}
});
render();
})();