(()=>{
'use strict';
const panel=document.getElementById('locationCoverEditor');if(!panel)return;
const id=Number(panel.dataset.locationId||0),csrf=String(panel.dataset.csrf||''),can=panel.dataset.manage==='1';
const preview=panel.querySelector('[data-cover-preview]'),status=panel.querySelector('[data-cover-status]'),upload=panel.querySelector('[data-cover-upload]'),removeBtn=panel.querySelector('[data-cover-remove]');
let busy=false;
async function request(form){const response=await fetch('api/media.php',{method:'POST',body:form,credentials:'same-origin'});const data=await response.json().catch(()=>({ok:false,message:'Invalid image response.'}));if(!response.ok||!data.ok)throw new Error(data.message||'Image request failed.');return data.media;}
function show(media){const file=media?.file;if(preview)preview.innerHTML=file?`<img src="${String(file.url).replace(/"/g,'&quot;')}" alt="Location cover">`:'No cover';if(status)status.textContent=file?file.originalName:'JPEG, PNG or WebP · max 10 MB';if(removeBtn)removeBtn.hidden=!file;}
function choose(){const input=document.createElement('input');input.type='file';input.accept='image/jpeg,image/png,image/webp';input.hidden=true;document.body.appendChild(input);input.addEventListener('change',async()=>{const file=input.files?.[0];input.remove();if(!file)return;busy=true;if(status)status.textContent='Uploading…';try{const form=new FormData();form.append('csrf_token',csrf);form.append('action','upload');form.append('target','location');form.append('id',String(id));form.append('image',file);show(await request(form));}catch(error){if(status)status.textContent=error.message;alert(error.message);}finally{busy=false;}},{once:true});input.click();}
upload?.addEventListener('click',()=>{if(can&&id>0&&!busy)choose();});
removeBtn?.addEventListener('click',async()=>{if(!can||id<1||busy||!confirm('Remove this location cover image?'))return;busy=true;if(status)status.textContent='Removing…';try{const form=new FormData();form.append('csrf_token',csrf);form.append('action','remove');form.append('target','location');form.append('id',String(id));show(await request(form));}catch(error){if(status)status.textContent=error.message;}finally{busy=false;}});
})();
