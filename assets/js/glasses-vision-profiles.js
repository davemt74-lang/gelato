(function(){
'use strict';
var boot=window.GELATO_VISION_PROFILES||{};
var catalog=boot.catalog||{};
var profiles=Array.isArray(catalog.profiles)?catalog.profiles:[];
var ingredients=Array.isArray(catalog.ingredients)?catalog.ingredients:[];
var el={
 publicId:document.getElementById('gvpPublicId'),
 detector:document.getElementById('gvpDetector'),
 label:document.getElementById('gvpLabel'),
 ingredient:document.getElementById('gvpIngredient'),
 confidence:document.getElementById('gvpConfidence'),
 status:document.getElementById('gvpStatus'),
 notes:document.getElementById('gvpNotes'),
 validation:document.getElementById('gvpValidation'),
 save:document.getElementById('gvpSave'),
 reset:document.getElementById('gvpReset'),
 rows:document.getElementById('gvpRows'),
 search:document.getElementById('gvpSearch'),
 title:document.getElementById('gvpFormTitle'),
 toast:document.getElementById('gvpToast')
};
function esc(v){return String(v==null?'':v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
function toast(message,error){el.toast.textContent=String(message||'');el.toast.classList.toggle('error',!!error);el.toast.hidden=false;clearTimeout(toast.t);toast.t=setTimeout(function(){el.toast.hidden=true;},3200);}
function ingredientName(id){var row=ingredients.find(function(x){return Number(x.id)===Number(id);});return row?row.name:'';}
function populateIngredients(){
 var html='<option value="">Choose ingredient</option>';
 ingredients.forEach(function(item){html+='<option value="'+Number(item.id)+'">'+esc(item.name)+'</option>';});
 el.ingredient.innerHTML=html;
}
function normalizedLabel(value){return String(value||'').trim().toLowerCase().replace(/[^\p{L}\p{N}]+/gu,' ').replace(/\s+/g,' ').trim();}
function validate(){
 var errors=[];
 var detector=String(el.detector.value||'').trim();
 var label=String(el.label.value||'').trim();
 var ingredientId=Number(el.ingredient.value||0);
 var confidence=String(el.confidence.value||'').trim();
 if(!detector)errors.push('Detector is required. Use * for a generic mapping.');
 if(!label||!normalizedLabel(label))errors.push('Model label is required.');
 if(!ingredientId)errors.push('Choose a Gelato ingredient.');
 if(confidence!==''){
   var n=Number(confidence);
   if(!Number.isFinite(n)||n<0.50||n>1)errors.push('Minimum confidence must be between 0.50 and 1.00.');
 }
 el.validation.innerHTML=errors.length
   ?'<strong>Needs attention</strong><ul>'+errors.map(function(x){return '<li>'+esc(x)+'</li>';}).join('')+'</ul>'
   :'<strong>Ready to save</strong><span>Runtime will still enforce the global confidence floor.</span>';
 el.validation.classList.toggle('ready',errors.length===0);
 el.save.disabled=!catalog.canManage||!catalog.ready||errors.length>0;
 return errors.length===0;
}
function reset(){
 el.publicId.value=''; el.detector.value='*'; el.label.value=''; el.ingredient.value=''; el.confidence.value=''; el.status.value='active'; el.notes.value='';
 el.title.textContent='Add model label'; validate();
}
function edit(profile){
 el.publicId.value=profile.publicId||'';
 el.detector.value=profile.detectorName||'*';
 el.label.value=profile.modelLabel||'';
 el.ingredient.value=String(profile.ingredientId||'');
 el.confidence.value=profile.minimumConfidence==null?'':String(profile.minimumConfidence);
 el.status.value=profile.status||'active';
 el.notes.value=profile.notes||'';
 el.title.textContent='Edit '+(profile.modelLabel||'mapping');
 validate(); window.scrollTo({top:0,behavior:'smooth'});
}
function render(){
 var q=String(el.search.value||'').trim().toLowerCase();
 var filtered=profiles.filter(function(p){
   if(!q)return true;
   return [p.detectorName,p.modelLabel,p.normalizedLabel,p.ingredientName,p.status].join(' ').toLowerCase().includes(q);
 });
 if(!filtered.length){el.rows.innerHTML='<tr><td colspan="6" class="gvp-empty">No mappings found.</td></tr>';return;}
 el.rows.innerHTML=filtered.map(function(p){
   var active=p.status==='active';
   return '<tr>'+
    '<td><code>'+esc(p.detectorName)+'</code></td>'+
    '<td><strong>'+esc(p.modelLabel)+'</strong><small>'+esc(p.normalizedLabel)+'</small></td>'+
    '<td>'+esc(p.ingredientName||ingredientName(p.ingredientId))+'</td>'+
    '<td>'+(p.minimumConfidence==null?'Global floor':Number(p.minimumConfidence).toFixed(2))+'</td>'+
    '<td><span class="gvp-pill '+(active?'active':'inactive')+'">'+esc(p.status)+'</span></td>'+
    '<td><div class="gvp-actions"><button type="button" class="admin-button quiet" data-edit="'+esc(p.publicId)+'">Edit</button>'+
    (catalog.canManage?'<button type="button" class="admin-button quiet" data-toggle="'+esc(p.publicId)+'">'+(active?'Disable':'Enable')+'</button>':'')+
    '</div></td></tr>';
 }).join('');
}
async function request(payload){
 var response=await fetch(boot.apiUrl,{method:'POST',credentials:'same-origin',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-Token':String(boot.csrfToken||'')},body:JSON.stringify(payload)});
 var data=await response.json();
 if(!response.ok||!data.ok)throw new Error(data.message||'Vision profile request failed.');
 return data;
}
async function save(){
 if(!validate())return;
 el.save.disabled=true; var old=el.save.textContent;el.save.textContent='Saving…';
 try{
   var data=await request({
     action:'profile.save',csrf_token:String(boot.csrfToken||''),publicId:String(el.publicId.value||''),
     detectorName:String(el.detector.value||''),modelLabel:String(el.label.value||''),ingredientId:Number(el.ingredient.value||0),
     minimumConfidence:String(el.confidence.value||'')===''?null:Number(el.confidence.value),
     status:String(el.status.value||'active'),notes:String(el.notes.value||'')
   });
   var idx=profiles.findIndex(function(p){return p.publicId===data.profile.publicId;});
   if(idx>=0)profiles[idx]=data.profile;else profiles.push(data.profile);
   render();reset();toast('Vision label mapping saved.');
 }catch(e){toast(e.message,true);}
 finally{el.save.textContent=old;validate();}
}
async function toggle(publicId){
 var profile=profiles.find(function(p){return p.publicId===publicId;});if(!profile)return;
 var status=profile.status==='active'?'inactive':'active';
 try{
   var data=await request({action:'profile.status',csrf_token:String(boot.csrfToken||''),publicId:publicId,status:status});
   var idx=profiles.findIndex(function(p){return p.publicId===publicId;});if(idx>=0)profiles[idx]=data.profile;
   render();toast('Mapping '+status+'.');
 }catch(e){toast(e.message,true);}
}
populateIngredients();render();validate();
[el.detector,el.label,el.ingredient,el.confidence,el.status,el.notes].forEach(function(x){x.addEventListener('input',validate);x.addEventListener('change',validate);});
el.save.addEventListener('click',save);el.reset.addEventListener('click',reset);el.search.addEventListener('input',render);
el.rows.addEventListener('click',function(e){
 var editButton=e.target.closest&&e.target.closest('[data-edit]');if(editButton){var p=profiles.find(function(x){return x.publicId===editButton.getAttribute('data-edit');});if(p)edit(p);return;}
 var toggleButton=e.target.closest&&e.target.closest('[data-toggle]');if(toggleButton)toggle(toggleButton.getAttribute('data-toggle'));
});
})();