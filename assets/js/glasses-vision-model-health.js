(()=>{'use strict';
const boot=window.GELATO_VISION_LAB||{},root=document.getElementById('vlModelHealth'),capture=document.getElementById('vlModelHealthSnapshot');
if(!root)return;
let catalog=boot.catalog||{};
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
async function post(payload){const r=await fetch(boot.apiUrl,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({...payload,csrfToken:boot.csrfToken})});const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Vision Lab request failed.');return j;}
async function refresh(){const r=await fetch(boot.apiUrl,{credentials:'same-origin'}),j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Refresh failed.');catalog=j.catalog||{};render();}
function render(){
 const mh=catalog.modelHealth||{},rows=mh.snapshots||[],states=mh.states||{};
 root.innerHTML='<div class="vl-health-summary">'+['healthy','watch','degraded','critical','insufficient'].map(k=>'<div><span>'+esc(k)+'</span><strong>'+Number(states[k]||0)+'</strong></div>').join('')+'</div>'+
 (rows.length?rows.map(r=>'<div class="vl-health-row"><div><strong>'+esc(r.modelName)+' '+esc(r.modelVersion)+' · <span class="vl-health-state">'+esc(r.healthState)+'</span></strong><small>'+esc(r.windowStartedAt)+' → '+esc(r.windowEndedAt)+' · '+esc(r.stationPublicId||'all stations')+' · '+esc(r.devicePublicId||'all devices')+'</small><small>errors '+Number(r.metrics.productionErrorCount||0)+' · corrections '+Number(r.metrics.correctionCount||0)+' · low confidence '+Number(r.metrics.lowConfidenceCount||0)+' · confidence '+(r.metrics.meanConfidence==null?'—':Number(r.metrics.meanConfidence).toFixed(3))+' · latency '+(r.metrics.meanLatencyMs==null?'—':Number(r.metrics.meanLatencyMs).toFixed(1)+'ms')+'</small><code>'+esc(r.snapshotHash)+'</code></div><button data-verify="'+esc(r.publicId)+'">Verify</button></div>').join(''):'<div class="vl-empty">No V8 model-health snapshots yet.</div>');
 root.querySelectorAll('[data-verify]').forEach(b=>b.addEventListener('click',async()=>{try{const j=await post({action:'model_health.verify',publicId:b.dataset.verify});alert(j.verification.passed?'Health snapshot verified.':'Health snapshot integrity failed.');}catch(e){alert(e.message);}}));
}
capture?.addEventListener('click',async()=>{
 const packages=catalog.modelHealth?.snapshots||[];const fallback=(catalog.modelExperiments?.experiments||[]).map(x=>x.challengerPublicId).filter(Boolean)[0]||'';
 const modelPackagePublicId=prompt('Model package public ID',fallback);if(!modelPackagePublicId)return;
 const now=new Date(),from=new Date(now.getTime()-24*60*60*1000);
 try{await post({action:'model_health.snapshot',modelPackagePublicId,windowStartedAt:from.toISOString(),windowEndedAt:now.toISOString()});await refresh();}catch(e){alert(e.message);}
});
render();
})();