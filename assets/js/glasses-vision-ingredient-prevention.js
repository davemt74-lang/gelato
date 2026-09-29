(()=>{'use strict';
const boot=window.GELATO_VISION_LAB||{},root=document.getElementById('vlIngredientPreventions'),button=document.getElementById('vlIngredientAssess');
if(!root)return;
let catalog=boot.catalog||{};
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
async function post(payload){const r=await fetch(boot.apiUrl,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({...payload,csrfToken:boot.csrfToken})});const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Vision Lab request failed.');return j;}
async function refresh(){const r=await fetch(boot.apiUrl,{credentials:'same-origin'}),j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Refresh failed.');catalog=j.catalog||{};render();}
function riskList(r){return (r.risks||[]).map(x=>'<li><strong>'+esc(x.severity)+': '+esc(x.type)+'</strong> — '+esc(x.message)+'</li>').join('');}
function render(){
 const rows=catalog.ingredientPreventions||[];
 root.innerHTML=rows.length?rows.map(r=>'<div class="vl-step-row"><div><strong><span class="vl-step-state">'+esc(r.state)+'</span> · Ingredient prevention</strong><small>Scene '+esc(r.scenePublicId)+' · current '+esc(r.currentComponentKey||'—')+' · stops '+Number(r.stopCount||0)+' · warnings '+Number(r.warningCount||0)+'</small>'+(r.risks?.length?'<ul class="vl-risk-list">'+riskList(r)+'</ul>':'<small>No ingredient risk detected.</small>')+'<code>'+esc(r.assessmentHash)+'</code></div><div class="vl-step-actions"><button data-verify="'+esc(r.publicId)+'">Verify</button></div></div>').join(''):'<div class="vl-empty">No ingredient-prevention assessments yet.</div>';
 root.querySelectorAll('[data-verify]').forEach(b=>b.addEventListener('click',async()=>{try{const j=await post({action:'ingredient_guard.verify',publicId:b.dataset.verify});alert(j.verification.passed?'Ingredient-prevention assessment verified.':'Ingredient-prevention integrity failed.');}catch(e){alert(e.message);}}));
}
button?.addEventListener('click',async()=>{
 const scene=(catalog.liveScenes?.scenes||[])[0];
 if(!scene){alert('No live scene is available yet.');return;}
 try{await post({action:'ingredient_guard.assess',scenePublicId:scene.publicId});await refresh();}catch(e){alert(e.message);}
});
render();
})();