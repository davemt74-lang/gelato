(function(){'use strict';
var boot=window.GELATO_VISION_LAB||{},button=document.getElementById('vlTrainingReleaseBuild'),out=document.getElementById('vlTrainingReleases'),toast=document.getElementById('vlToast');
if(!button||!out)return;
function e(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
function note(m,x){if(!toast)return;toast.textContent=m;toast.classList.toggle('error',!!x);toast.hidden=false;setTimeout(function(){toast.hidden=true;},3500);}
function choose(list,label){if(!list.length)return undefined;var txt=list.map(function(x,i){return (i+1)+'. '+label(x);}).join('\n');var n=Number(prompt(txt+'\n\nEnter number:'));return Number.isInteger(n)&&n>=1&&n<=list.length?list[n-1]:undefined;}
function render(releases){out.innerHTML=releases.length?releases.map(function(r){var m=r.manifest||{},d=m.dataset||{},s=m.splitCounts||{},classes=m.classes||[];return '<article><div><strong>'+e(d.name||'Vision dataset')+' · '+e(d.versionLabel||'')+'</strong><small>'+e(r.trainingProfile)+' · '+Number(m.itemCount||0)+' media · '+Number(classes.length)+' classes</small></div><div><strong>'+Number(s.train||0)+' / '+Number(s.val||0)+' / '+Number(s.test||0)+'</strong><small>train / val / test media</small></div><div><small>release '+e(String(r.releaseHash||'').slice(0,16))+'…</small><small>zip '+e(String(r.artifactSha256||'').slice(0,16))+'… · '+Number(r.artifactBytes||0)+' bytes</small></div><a class="admin-button" href="api/glasses-vision-training-release.php?publicId='+encodeURIComponent(r.publicId)+'">Download YOLO ZIP</a></article>';}).join(''):'<div class="vl-empty">No training releases built yet.</div>';}
async function catalog(){var resp=await fetch(boot.apiUrl,{credentials:'same-origin',cache:'no-store'}),json=await resp.json();if(!resp.ok||!json.ok)throw new Error(json.message||'Vision Lab catalog refresh failed.');return json.catalog||{};}
button.addEventListener('click',async function(){try{
 var c=await catalog(),datasets=(c.datasets||[]).filter(function(x){return x.status==='frozen';});
 var ds=choose(datasets,function(x){return x.name+' '+x.versionLabel+' · '+String(x.datasetHash||'').slice(0,12)+'…';});if(!ds){note(datasets.length?'No dataset selected.':'Freeze a curated dataset before building a training release.',true);return;}
 var profiles=Object.keys(((c.trainingReleases||{}).profiles)||{}),profile=choose(profiles,function(x){return x;});if(!profile)return;
 var payload={action:'training_release.build',datasetPublicId:ds.publicId,trainingProfile:profile,csrf_token:boot.csrfToken};
 var resp=await fetch(boot.apiUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(payload)}),json=await resp.json();
 if(!resp.ok||!json.ok)throw new Error(json.message||'Training release build failed.');
 c=await catalog();render(((c.trainingReleases||{}).releases)||[]);note('Deterministic YOLO training release built.');
 }catch(err){note(err.message,true);}});
catalog().then(function(c){render(((c.trainingReleases||{}).releases)||[]);}).catch(function(){});
})();