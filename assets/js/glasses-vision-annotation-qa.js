(function(){'use strict';
var boot=window.GELATO_VISION_LAB||{},out=document.getElementById('vlAnnotationQa'),button=document.getElementById('vlAnnotationQaAnalyze'),toast=document.getElementById('vlToast');
if(!button||!out)return;
function e(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
function note(m,x){if(!toast)return;toast.textContent=m;toast.classList.toggle('error',!!x);toast.hidden=false;setTimeout(function(){toast.hidden=true;},3000);}
function choose(list){if(!list.length)return null;var txt=['0. Full corpus'].concat(list.map(function(x,i){return (i+1)+'. '+x.name+' '+x.versionLabel+' ('+x.status+')';})).join('\n');var n=Number(prompt(txt+'\n\nEnter number:','0'));if(n===0)return null;return Number.isInteger(n)&&n>=1&&n<=list.length?list[n-1]:undefined;}
function render(a){var s=a.summary||{},ag=a.agreement||{},queue=a.adjudicationQueue||[],guidance=a.guidance||[],samples=(a.samples||[]).filter(function(x){return !x.complete;});
out.innerHTML='<div class="vl-qa-grid">'+
'<div class="vl-qa-card"><span>QA score</span><strong>'+Number(s.qaScore||0)+'</strong></div>'+
'<div class="vl-qa-card"><span>Complete</span><strong>'+Number(s.completeSamples||0)+'</strong></div>'+
'<div class="vl-qa-card"><span>Incomplete</span><strong>'+Number(s.incompleteSamples||0)+'</strong></div>'+
'<div class="vl-qa-card"><span>Box issues</span><strong>'+Number(s.boxIssueCount||0)+'</strong></div>'+
'<div class="vl-qa-card"><span>Agreement</span><strong>'+(ag.agreementRate==null?'N/A':Math.round(Number(ag.agreementRate)*100)+'%')+'</strong></div>'+
'<div class="vl-qa-card"><span>Adjudication</span><strong>'+Number(s.unresolvedDisagreements||0)+'</strong></div></div>'+
'<div class="vl-qa-columns"><div><h3>Annotation issues</h3>'+(samples.length?samples.slice(0,40).map(function(x){return '<div class="vl-qa-row"><strong>'+e(x.samplePublicId)+' · '+e(x.label||'unlabeled')+'</strong><small>'+e((x.issues||[]).join(', '))+'</small></div>';}).join(''):'<div class="vl-empty">No annotation blockers detected.</div>')+
'</div><div><h3>Adjudication queue</h3>'+(queue.length?queue.slice(0,40).map(function(x){return '<div class="vl-qa-row"><strong>'+e(x.samplePublicId)+' · '+e(x.label||'unlabeled')+'</strong><small>'+e(x.reviewStatus||'')+' · '+e((x.issues||[]).join(', '))+'</small></div>';}).join(''):'<div class="vl-empty">No unresolved reviewer disagreements.</div>')+
'<h3>Class guidance</h3>'+guidance.slice(0,20).map(function(g){return '<div class="vl-qa-row"><strong>'+e(g.label||'target object')+'</strong><small>'+e(g.instructions||'')+'</small></div>';}).join('')+'</div></div>';
}
button.addEventListener('click',async function(){try{var fresh=await fetch(boot.apiUrl,{credentials:'same-origin',cache:'no-store'}),catalogJson=await fresh.json();if(!fresh.ok||!catalogJson.ok)throw new Error(catalogJson.message||'Catalog refresh failed.');var picked=choose((catalogJson.catalog||{}).datasets||[]);if(picked===undefined)return;var payload={action:'annotation_qa.analyze',datasetPublicId:picked?picked.publicId:null,csrf_token:boot.csrfToken};var resp=await fetch(boot.apiUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(payload)}),json=await resp.json();if(!resp.ok||!json.ok)throw new Error(json.message||'Annotation QA failed.');render(json.analysis||{});note('Annotation QA and reviewer agreement analyzed.');}catch(err){note(err.message,true);}});
})();