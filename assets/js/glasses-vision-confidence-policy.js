(()=>{'use strict';
const boot=window.GELATO_VISION_LAB||{},root=document.getElementById('vlConfidencePolicies'),create=document.getElementById('vlConfidencePolicyCreate');
if(!root)return;
let catalog=boot.catalog||{};
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
async function post(payload){const r=await fetch(boot.apiUrl,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({...payload,csrfToken:boot.csrfToken})});const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Vision Lab request failed.');return j;}
async function refresh(){const r=await fetch(boot.apiUrl,{credentials:'same-origin'}),j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Refresh failed.');catalog=j.catalog||{};render();}
function render(){
 const cp=catalog.confidencePolicies||{},policies=cp.policies||[],decisions=cp.decisions||[];
 root.innerHTML='<div class="vl-confidence-grid">'+(policies.length?policies.map(p=>'<div class="vl-confidence-card"><strong>'+esc(p.policyKey)+' · '+esc(p.status)+'</strong><small>'+esc(p.detectorName)+' · default '+Number(p.defaultThreshold).toFixed(2)+' · hard floor '+Number(p.hardFloor).toFixed(2)+'</small><small>'+esc(p.modelPackagePublicId||'all compatible models')+'</small><code>'+esc(p.policyHash)+'</code><div class="vl-confidence-actions">'+(p.status==='draft'?'<button data-activate="'+esc(p.publicId)+'">Activate</button>':'')+(p.status==='active'?'<button data-retire="'+esc(p.publicId)+'">Retire</button>':'')+'</div></div>').join(''):'<div class="vl-empty">No adaptive confidence policies yet.</div>')+'</div>'+
 (decisions.length?'<div class="vl-confidence-decision"><strong>Latest decision · '+esc(decisions[0].decision)+'</strong><small>'+esc(decisions[0].normalizedLabel)+' · confidence '+Number(decisions[0].modelConfidence).toFixed(3)+' / threshold '+Number(decisions[0].effectiveThreshold).toFixed(3)+'</small><code>'+esc(decisions[0].decisionHash)+'</code></div>':'');
 root.querySelectorAll('[data-activate]').forEach(b=>b.addEventListener('click',async()=>{try{await post({action:'confidence_policy.activate',publicId:b.dataset.activate});await refresh();}catch(e){alert(e.message);}}));
 root.querySelectorAll('[data-retire]').forEach(b=>b.addEventListener('click',async()=>{try{await post({action:'confidence_policy.retire',publicId:b.dataset.retire});await refresh();}catch(e){alert(e.message);}}));
}
create?.addEventListener('click',async()=>{
 const policyKey=prompt('Policy key','production-default');if(!policyKey)return;
 const detectorName=prompt('Detector name','ingredient_detector');if(!detectorName)return;
 const defaultThreshold=Number(prompt('Default confidence threshold','0.70'));if(!Number.isFinite(defaultThreshold))return;
 const modelPackagePublicId=prompt('Optional model package public ID','')||'';
 try{await post({action:'confidence_policy.create',policyKey,detectorName,defaultThreshold,modelPackagePublicId,classRules:{},contextRules:{}});await refresh();}catch(e){alert(e.message);}
});
render();
})();