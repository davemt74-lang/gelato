(()=>{'use strict';
const boot=window.GELATO_VISION_LAB||{},root=document.getElementById('vlContextDrift'),run=document.getElementById('vlContextDriftAnalyze');
if(!root)return;
let catalog=boot.catalog||{};
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot',"'":'&#039;'}[c]));
async function post(payload){const r=await fetch(boot.apiUrl,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({...payload,csrfToken:boot.csrfToken})});const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Vision Lab request failed.');return j;}
async function refresh(){const r=await fetch(boot.apiUrl,{credentials:'same-origin'}),j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Refresh failed.');catalog=j.catalog||{};render();}
function render(){
 const d=catalog.contextDrift||{},rows=d.analyses||[],counts=d.classifications||{};
 const keys=['stable','context_shift','model_degradation','mixed','ambiguous','insufficient_context'];
 root.innerHTML='<div class="vl-context-summary">'+keys.map(k=>'<div><span>'+esc(k.replaceAll('_',' '))+'</span><strong>'+Number(counts[k]||0)+'</strong></div>').join('')+'</div>'+
 (rows.length?rows.map(r=>'<div class="vl-context-row"><strong><span class="vl-context-classification">'+esc(r.classification.replaceAll('_',' '))+'</span></strong><small>context '+Number(r.contextScore).toFixed(3)+' · performance '+Number(r.performanceScore).toFixed(3)+' · evidence '+Number(r.confidenceScore).toFixed(3)+'</small><small>'+esc(r.result?.explanation||'')+'</small><code>'+esc(r.analysisHash)+'</code></div>').join(''):'<div class="vl-empty">No context-aware drift analyses yet.</div>');
}
run?.addEventListener('click',async()=>{
 const latest=(catalog.modelHealth?.snapshots||[])[0];if(!latest){alert('Capture a model-health window first.');return;}
 run.disabled=true;try{await post({action:'context_drift.analyze',healthSnapshotPublicId:latest.publicId});await refresh();}catch(e){alert(e.message);}finally{run.disabled=false;}
});
render();
})();