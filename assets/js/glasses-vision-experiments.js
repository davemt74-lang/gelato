(()=>{'use strict';
const boot=window.GELATO_VISION_LAB||{},root=document.getElementById('vlModelExperiments'),create=document.getElementById('vlExperimentCreate');
if(!root)return;
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
let catalog=boot.catalog||{};
async function post(payload){const r=await fetch(boot.apiUrl,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({...payload,csrfToken:boot.csrfToken})});const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Vision Lab request failed.');return j;}
async function refresh(){const r=await fetch(boot.apiUrl,{credentials:'same-origin'}),j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Refresh failed.');catalog=j.catalog||{};render();}
function render(){
 const rows=catalog.modelExperiments?.experiments||[];
 root.innerHTML=rows.length?rows.map(x=>'<div class="vl-experiment-card"><div><strong>'+esc(x.hypothesis)+'</strong><small>'+esc(x.status)+' · '+esc(x.champion?.name||'')+' '+esc(x.champion?.version||'')+' → '+esc(x.challenger?.name||'pending challenger')+'</small><small>'+esc(x.candidateDataset?.publicId||'')+' · '+esc(x.trainingRelease?.publicId||'')+'</small><code>'+esc(x.experimentHash||'')+'</code></div><div class="vl-experiment-actions">'+(x.status==='ready'?'<button data-start="'+esc(x.publicId)+'">Start</button>':'')+(x.status==='running'?'<button data-run="'+esc(x.publicId)+'">Attach run</button>':'')+(x.status==='trained'?'<button data-challenger="'+esc(x.publicId)+'">Bind challenger</button>':'')+'</div></div>').join(''):'<div class="vl-empty">No model improvement experiments yet.</div>';
 root.querySelectorAll('[data-start]').forEach(b=>b.onclick=async()=>{try{await post({action:'model_experiment.start',publicId:b.dataset.start});await refresh();}catch(e){alert(e.message);}});
 root.querySelectorAll('[data-run]').forEach(b=>b.onclick=async()=>{const id=prompt('Training run public ID');if(!id)return;try{await post({action:'model_experiment.attach_run',publicId:b.dataset.run,trainingRunPublicId:id});await refresh();}catch(e){alert(e.message);}});
 root.querySelectorAll('[data-challenger]').forEach(b=>b.onclick=async()=>{const id=prompt('Challenger model package public ID');if(!id)return;try{await post({action:'model_experiment.bind_challenger',publicId:b.dataset.challenger,modelPackagePublicId:id});await refresh();}catch(e){alert(e.message);}});
}
create?.addEventListener('click',async()=>{const champion=prompt('Champion model package public ID');if(!champion)return;const release=prompt('Qualified training release public ID');if(!release)return;const hypothesis=prompt('Experiment hypothesis');if(!hypothesis)return;try{await post({action:'model_experiment.create',championModelPublicId:champion,trainingReleasePublicId:release,hypothesis,trainingConfig:{epochs:100,batchSize:16,imageSize:640,seed:74}});await refresh();}catch(e){alert(e.message);}});
render();
})();