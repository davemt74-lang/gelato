(function(){'use strict';
var boot=window.GELATO_VISION_LAB||{},out=document.getElementById('vlTrainingReleases'),button=document.getElementById('vlTrainingReleaseBuild'),toast=document.getElementById('vlToast');
if(!button||!out)return;
function e(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
function note(m,x){if(!toast)return;toast.textContent=m;toast.classList.toggle('error',!!x);toast.hidden=false;setTimeout(function(){toast.hidden=true;},3500);}
function size(n){n=Number(n||0);if(n<1024)return n+' B';if(n<1048576)return (n/1024).toFixed(1)+' KB';return (n/1048576).toFixed(1)+' MB';}
function render(list){out.innerHTML=list.length?list.map(function(r){return '<article><div><strong>'+e(r.datasetName)+' · '+e(r.datasetVersion)+'</strong><small>'+e(r.profileKey)+' · '+e(r.publicId)+'</small></div><div><span class="vl-state frozen">immutable</span><small>'+size(r.packageBytes)+'</small></div><div><code>'+e((r.packageHash||'').slice(0,20))+'…</code></div><a class="admin-button" href="api/glasses-vision-training-release.php?publicId='+encodeURIComponent(r.publicId)+'">Download ZIP</a></article>';}).join(''):'<div class="vl-empty">No governed training releases have been built yet.</div>';}
async function catalog(){var resp=await fetch(boot.apiUrl,{credentials:'same-origin',cache:'no-store'}),json=await resp.json();if(!resp.ok||!json.ok)throw new Error(json.message||'Catalog refresh failed.');return json.catalog||{};}
function choose(list,label){if(!list.length)return undefined;var txt=list.map(function(x,i){return (i+1)+'. '+label(x);}).join('\n');var n=Number(prompt(txt+'\n\nEnter number:','1'));return Number.isInteger(n)&&n>=1&&n<=list.length?list[n-1]:undefined;}
button.addEventListener('click',async function(){try{
    var c=await catalog(),frozen=(c.datasets||[]).filter(function(d){return d.status==='frozen';}),tr=c.trainingRelease||{};
    if(!tr.ready)throw new Error('Training release schema is not ready. Run Upgrade first.');
    var d=choose(frozen,function(x){return x.name+' '+x.versionLabel+' · '+(x.datasetHash||'').slice(0,12);});if(!d){note('Freeze a governed dataset before building a training release.',true);return;}
    var p=choose(tr.profiles||[],function(x){return x.label;});if(!p)return;
    button.disabled=true;button.textContent='Building…';
    var resp=await fetch(boot.apiUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({action:'training_release.build',datasetPublicId:d.publicId,profileKey:p.key,csrf_token:boot.csrfToken})}),json=await resp.json();
    if(!resp.ok||!json.ok)throw new Error(json.message||'Training release build failed.');
    var fresh=await catalog();render((fresh.trainingRelease||{}).releases||[]);note(json.release.reused?'Reproducible release already existed; reused immutable package.':'Deterministic training release built.');
}catch(err){note(err.message,true);}finally{button.disabled=false;button.textContent='Build release';}});
render((((boot.catalog||{}).trainingRelease||{}).releases)||[]);
})();