(function(){'use strict';
const boot=window.GELATO_VISION_LAB||{},root=document.getElementById('vlV11Benchmarks'),run=document.getElementById('vlV11BenchmarkRun');if(!root)return;let catalog=boot.catalog||{};
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
async function api(payload){payload.csrf_token=boot.csrfToken;const r=await fetch(boot.apiUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)}),j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Benchmark request failed.');await refresh();return j;}
async function refresh(){const r=await fetch(boot.apiUrl,{credentials:'same-origin',cache:'no-store'}),j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Benchmark refresh failed.');catalog=j.catalog||{};render();}
function render(){const rows=catalog.modelBenchmarksV11?.benchmarks||[];if(!rows.length){root.innerHTML='<div class="vl-empty">No V11 model benchmarks yet.</div>';return;}root.innerHTML=rows.map(b=>'<article class="vl-pilot-card"><div class="vl-head"><div><strong>'+esc(b.experiment?.publicId||'Experiment')+'</strong><small>'+esc(b.publicId)+' · score '+esc(b.score)+'/100 · '+esc((b.benchmarkHash||'').slice(0,12))+'</small></div><span class="vl-state '+esc(b.status)+'">'+esc(b.status)+'</span></div><small>mAP50 Δ '+esc(b.result?.deltas?.map50??'')+' · recall Δ '+esc(b.result?.deltas?.recall??'')+' · precision Δ '+esc(b.result?.deltas?.precision??'')+'</small></article>').join('');}
run?.addEventListener('click',async()=>{try{
 const experimentPublicId=prompt('Completed experiment public ID');if(!experimentPublicId)return;
 const testSetHash=prompt('Held-out test-set SHA-256');if(!testSetHash)return;
 const hardExampleSetHash=prompt('Hard-example-set SHA-256');if(!hardExampleSetHash)return;
 alert('Use the API for full benchmark metrics/slices. The UI entry point is intentionally conservative until automated evaluator output is connected.');
}catch(e){alert(e.message);}});
render();
})();
