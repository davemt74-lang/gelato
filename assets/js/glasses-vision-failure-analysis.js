(()=>{'use strict';
const boot=window.GELATO_VISION_LAB||{},catalog=boot.catalog||{};
const root=document.getElementById('vlFailureAnalysis'),button=document.getElementById('vlFailureAnalyze');
if(!root)return;
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
const label=s=>String(s??'').replaceAll('_',' ');
const rows=(title,list)=>(list&&list.length)?'<div class="vl-failure-dim"><h3>'+esc(title)+'</h3>'+list.slice(0,8).map(x=>'<div class="vl-failure-row"><span>'+esc(label(x.label))+'</span><strong>'+Number(x.count||0)+'</strong><small>'+Math.round(Number(x.share||0)*100)+'%</small></div>').join('')+'</div>':'';
function renderAnalysis(a){
 if(!a){root.innerHTML='<div class="vl-empty">No failure analysis has been run yet.</div>';return;}
 const r=a.result||{},dims=r.dimensions||{},ancestry=r.modelAncestry||[];
 const topModel=(dims.model||[])[0],topError=(dims.errorType||[])[0],topStation=(dims.station||[])[0];
 root.innerHTML='<div class="vl-failure-summary">'+
  '<div><span>Failures analyzed</span><strong>'+Number(r.eventCount||0)+'</strong></div>'+
  '<div><span>Top error</span><strong>'+esc(topError?label(topError.label):'—')+'</strong></div>'+
  '<div><span>Top model</span><strong>'+esc(topModel?topModel.label:'—')+'</strong></div>'+
  '<div><span>Top station</span><strong>'+esc(topStation?topStation.label:'—')+'</strong></div></div>'+
  '<div class="vl-failure-columns">'+
  rows('Error type',dims.errorType)+rows('Model',dims.model)+rows('Predicted class',dims.predictedClass)+rows('Expected class',dims.expectedClass)+
  rows('Station',dims.station)+rows('Device',dims.device)+rows('Menu item',dims.menuItem)+rows('Confidence',dims.confidence)+
  rows('Lighting',dims.lighting)+rows('Pose',dims.pose)+rows('Build step',dims.buildStep)+rows('Environment',dims.captureEnvironment)+'</div>'+
  (ancestry.length?'<div><h3>Training ancestry</h3>'+ancestry.map(x=>{const l=x.lineage||{};return '<div class="vl-failure-ancestry"><strong>'+esc(x.modelPackagePublicId)+' · '+Number(x.failureCount||0)+' failures</strong><small>'+esc(l.datasetPublicId||'No dataset lineage')+' → '+esc(l.trainingReleasePublicId||'No release lineage')+'</small><code>'+esc(l.datasetHash||'')+'</code></div>';}).join('')+'</div>':'')+
  '<div class="vl-failure-note">This view reports failure counts and share only. No production denominator is available here, so it does not claim an error rate.</div>';
}
async function post(payload){
 const res=await fetch(boot.apiUrl,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({...payload,csrfToken:boot.csrfToken})});
 const j=await res.json();if(!res.ok||!j.ok)throw new Error(j.message||'Failure analysis request failed.');return j;
}
const history=catalog.failureAnalysis?.analyses||[];renderAnalysis(history[0]||null);
button?.addEventListener('click',async()=>{
 button.disabled=true;try{const j=await post({action:'failure_analysis.run'});renderAnalysis(j.analysis);}catch(e){root.insertAdjacentHTML('afterbegin','<div class="vl-alert">'+esc(e.message)+'</div>');}finally{button.disabled=false;}
});
})();