(()=>{'use strict';
const boot=window.GELATO_VISION_LAB||{},catalog=boot.catalog||{};
const root=document.getElementById('vlProductionFeedback'),sync=document.getElementById('vlFeedbackSync');
if(!root)return;
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
const label=s=>String(s??'').replaceAll('_',' ');
function render(data){
 const summary=data?.summary||{total:0,byType:{},byOutcome:{}},events=data?.events||[];
 const top=Object.entries(summary.byType||{}).sort((a,b)=>b[1]-a[1]).slice(0,3);
 root.innerHTML='<div class="vl-feedback-kpis"><div><span>Total errors</span><strong>'+Number(summary.total||0)+'</strong></div>'+
   top.map(([k,v])=>'<div><span>'+esc(label(k))+'</span><strong>'+Number(v||0)+'</strong></div>').join('')+'</div>'+
   (events.length?events.map(e=>'<div class="vl-feedback-event"><strong>'+esc(label(e.errorType))+' · '+esc(label(e.outcome))+'</strong>'+
    '<small>'+esc(e.occurredAt||'')+' · '+esc(e.stationPublicId||'No station')+' · '+esc(e.modelPackagePublicId||'No model assignment')+'</small>'+
    '<small>'+esc(e.predictedComponentKey||'—')+' → '+esc(e.expectedComponentKey||'—')+(e.confidence!==null&&e.confidence!==undefined?' · confidence '+Number(e.confidence).toFixed(3):'')+'</small>'+
    '<code>'+esc(e.eventHash||'')+'</code></div>').join(''):'<div class="vl-empty">No production vision errors recorded yet.</div>');
}
async function post(payload){
 const r=await fetch(boot.apiUrl,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({...payload,csrfToken:boot.csrfToken})});
 const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Vision Lab request failed.');return j;
}
render(catalog.productionFeedback||{});
sync?.addEventListener('click',async()=>{
 sync.disabled=true;try{
  await post({action:'production_error.sync_corrections',limit:500});
  const r=await fetch(boot.apiUrl,{credentials:'same-origin'}),j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Refresh failed.');
  render(j.catalog?.productionFeedback||{});
 }catch(e){root.insertAdjacentHTML('afterbegin','<div class="vl-alert">'+esc(e.message)+'</div>');}
 finally{sync.disabled=false;}
});
})();