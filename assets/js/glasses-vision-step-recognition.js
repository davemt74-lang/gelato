(()=>{'use strict';
const boot=window.GELATO_VISION_LAB||{},root=document.getElementById('vlStepRecognitions'),button=document.getElementById('vlStepRecognize');
if(!root)return;
let catalog=boot.catalog||{};
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
async function post(payload){const r=await fetch(boot.apiUrl,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({...payload,csrfToken:boot.csrfToken})});const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Vision Lab request failed.');return j;}
async function refresh(){const r=await fetch(boot.apiUrl,{credentials:'same-origin'}),j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Refresh failed.');catalog=j.catalog||{};render();}
function render(){
 const rows=catalog.stepRecognitions?.recognitions||[];
 root.innerHTML=rows.length?rows.map(r=>'<div class="vl-step-row"><div><strong><span class="vl-step-state">'+esc(r.state)+'</span> · '+esc(r.recognizedStep?.text||'No step confidently recognized')+'</strong><small>Scene '+esc(r.scenePublicId)+' · confidence '+Number(r.confidence||0).toFixed(3)+' · margin '+Number(r.margin||0).toFixed(3)+'</small><small>Next: '+esc(r.nextStep?.text||'—')+'</small><code>'+esc(r.recognitionHash)+'</code></div><div class="vl-step-actions"><button data-verify="'+esc(r.publicId)+'">Verify</button></div></div>').join(''):'<div class="vl-empty">No recipe-step recognitions yet.</div>';
 root.querySelectorAll('[data-verify]').forEach(b=>b.addEventListener('click',async()=>{try{const j=await post({action:'step.verify',publicId:b.dataset.verify});alert(j.verification.passed?'Recipe-step recognition verified.':'Recipe-step integrity failed.');}catch(e){alert(e.message);}}));
}
button?.addEventListener('click',async()=>{
 const scene=(catalog.liveScenes?.scenes||[])[0];
 if(!scene){alert('No live scene is available yet.');return;}
 try{await post({action:'step.recognize',scenePublicId:scene.publicId});await refresh();}catch(e){alert(e.message);}
});
render();
})();