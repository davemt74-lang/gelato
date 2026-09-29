(function(){'use strict';
var boot=window.GELATO_VISION_LAB||{},catalog=boot.catalog||{},button=document.getElementById('vlBalanceAnalyze'),out=document.getElementById('vlBalance'),toast=document.getElementById('vlToast');
if(!button||!out)return;
function e(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
function note(m,x){if(!toast)return;toast.textContent=m;toast.classList.toggle('error',!!x);toast.hidden=false;setTimeout(function(){toast.hidden=true;},3000);}
function choose(list){var txt=list.map(function(x,i){return (i+1)+'. '+x.name+' '+x.versionLabel+' ('+x.status+')';}).join('\n');var n=Number(prompt(txt+'\n\nEnter number:'));return Number.isInteger(n)&&n>=1&&n<=list.length?list[n-1]:null;}
function render(a){var t=a.totals||{},r=a.ratios||{},d=a.diversity||{},m=d.media||{},recs=a.recommendations||[],classes=a.classes||{};
 out.innerHTML='<div class="vl-balance-grid">'+
 '<div class="vl-balance-card"><span>Samples</span><strong>'+Number(t.includedApproved||0)+'</strong></div>'+
 '<div class="vl-balance-card"><span>Class balance</span><strong>'+Math.round(Number(r.classBalanceIndex||0)*100)+'%</strong></div>'+
 '<div class="vl-balance-card"><span>Negatives</span><strong>'+Math.round(Number(r.negative||0)*100)+'%</strong></div>'+
 '<div class="vl-balance-card"><span>Hard examples</span><strong>'+Math.round(Number(r.hard||0)*100)+'%</strong></div>'+
 '<div class="vl-balance-card"><span>Operators</span><strong>'+Number(d.operatorCount||0)+'</strong></div>'+
 '<div class="vl-balance-card"><span>Locations</span><strong>'+Number(d.locationCount||0)+'</strong></div>'+
 '</div><div class="vl-balance-columns"><div><h3>Class distribution</h3>'+
 (Object.keys(classes).length?Object.keys(classes).map(function(k){return '<div class="vl-balance-rec"><strong>'+e(k)+'</strong><small>'+Number(classes[k])+' samples</small></div>';}).join(''):'<div class="vl-empty">No positive classes.</div>')+
 '<h3>Visual diversity</h3><div class="vl-balance-rec"><strong>'+Number(m.deviceDiversity||0)+' devices · '+Number(m.cameraDiversity||0)+' cameras · '+Number(m.poseDiversity||0)+' pose buckets</strong><small>'+Object.keys(m.exposureCoverage||{}).length+' lighting buckets · '+Object.keys(m.distanceCoverage||{}).length+' distance buckets · '+Object.keys(m.occlusionCoverage||{}).length+' occlusion buckets</small></div></div>'+
 '<div><h3>Recommended corpus changes</h3>'+(recs.length?recs.slice(0,30).map(function(x){return '<div class="vl-balance-rec" data-priority="'+(Number(x.priority||0)>=90?'high':'normal')+'"><strong>'+e(x.action)+' · '+e(x.dimension)+' · '+e(x.key)+'</strong><small>'+e(x.reason)+' · '+Number(x.amount||0)+' sample(s) · priority '+Number(x.priority||0)+'</small></div>';}).join(''):'<div class="vl-empty">No balance/diversity changes recommended at current policy thresholds.</div>')+'</div></div><small>Optimizer recommendations are advisory. No curation or split membership is changed automatically.</small>';
}
button.addEventListener('click',async function(){try{var fresh=await fetch(boot.apiUrl,{credentials:'same-origin',cache:'no-store'}),freshJson=await fresh.json();if(!fresh.ok||!freshJson.ok)throw new Error(freshJson.message||'Vision Lab catalog refresh failed.');catalog=freshJson.catalog||catalog;var ds=choose(catalog.datasets||[]);if(!ds)return;var payload={action:'balance.analyze',datasetPublicId:ds.publicId,targetPerClass:50,targetNegativeRatio:.10,targetHardRatio:.25,maxDominantShare:.50,csrf_token:boot.csrfToken};var resp=await fetch(boot.apiUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(payload)}),json=await resp.json();if(!resp.ok||!json.ok)throw new Error(json.message||'Balance analysis failed.');render(json.analysis||{});note('Dataset balance and diversity analyzed.');}catch(err){note(err.message,true);}});
})();