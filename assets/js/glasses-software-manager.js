(()=>{'use strict';
const cfg=window.GELATO_GLASSES_SIMULATOR||{};
const $=id=>document.getElementById(id);
const api='api/glasses-software.php';
let devices=[],chosen='',inFlight=false,ready=false;
function text(id,value){const el=$(id);if(el)el.textContent=String(value??'');}
function notice(message){text('glassesSoftwareNotice',message);}
async function request(url,options={}){
 const response=await fetch(url,{credentials:'same-origin',cache:'no-store',...options});
 const json=await response.json().catch(()=>null);
 if(!response.ok||!json?.ok)throw Error(json?.message||'Software request failed ('+response.status+').');
 return json;
}
function device(){return devices.find(d=>d.publicId===chosen)||null;}
function followRuntime(){
 const rt=window.GelatoGlassesRuntimes?.selected?.()||window.GelatoGlassesRuntimes?.binding?.();
 const id=rt?.devicePublicId||(!cfg.runtimePublicId?$('deviceSelect')?.value:'');
 if(id&&devices.some(d=>d.publicId===id)&&id!==chosen){
  chosen=id;
  const select=$('glassesSoftwareDevice');if(select)select.value=id;
  render();
 }
}
function el(tag,value){
 const node=document.createElement(tag);
 if(value!==undefined)node.textContent=String(value??'');
 return node;
}
function addCell(row,value){const cell=el('td',value);row.appendChild(cell);return cell;}
function renderFleet(){
 const rows=$('glassesFleetRows');if(!rows)return;
 rows.replaceChildren();text('glassesFleetCount','('+devices.length+')');
 for(const d of devices){
  const tr=el('tr');
  const title=addCell(tr,d.name+' · '+d.locationName);
  title.title=d.platform+(d.stationName?' · '+d.stationName:'');
  addCell(tr,d.appVersion||'Not reported');
  addCell(tr,d.status!=='active'?'Revoked':d.online?'Online':'Offline');
  addCell(tr,d.runtime?.employeeName||'Unassigned');
  const cell=el('td'),btn=el('button','Inspect');
  btn.type='button';btn.setAttribute('aria-label','Inspect '+d.name);
  btn.addEventListener('click',()=>{chosen=d.publicId;$('glassesSoftwareDevice').value=chosen;render();});
  cell.appendChild(btn);tr.appendChild(cell);
  if(d.publicId===chosen)tr.dataset.selected='1';
  rows.appendChild(tr);
 }
}
function render(){
 const d=device(),policy=d?.policy;
 text('glassesSoftwareOnline',!d?'Not selected':d.status!=='active'?'Revoked':d.online?'Physical device online':'Paired · Offline');
 text('glassesSoftwareVersion',d?.appVersion||'Not reported');
 text('glassesSoftwareStatus',d?.software?.label||'Select glasses');
 text('glassesSoftwareRuntime',d?.runtime?.employeeName||'No active employee');
 $('glassesSoftwareStatus').dataset.state=d?.software?.state||'unknown';
 const edit=$('glassesSoftwarePolicy');
 edit.hidden=!d||!d.canManage;
 $('glassesSoftwareMinimum').value=policy?.minimumVersion||'';
 $('glassesSoftwareTarget').value=policy?.targetVersion||'';
 $('glassesSoftwareNotes').value=policy?.notes||'';
 $('glassesSoftwareDiagnostics').disabled=!d;
 $('glassesSoftwareSave').disabled=!d?.canManage;
 renderFleet();
}
async function refresh(){
 if(inFlight)return;
 inFlight=true;
 try{
  const r=await request(api+'?action=fleet');
  devices=r.devices||[];
  const selected=$('glassesSoftwareDevice');
  const keep=chosen;
  selected.replaceChildren(el('option','Choose paired glasses…'));
  selected.options[0].value='';
  for(const d of devices){
   const option=el('option',d.name+' · '+d.locationName);
   option.value=d.publicId;selected.appendChild(option);
  }
  if(keep&&devices.some(d=>d.publicId===keep))chosen=keep;
  else chosen=devices.length?devices[0].publicId:'';
  selected.value=chosen;
  followRuntime();render();
  notice('Fleet status refreshed. Native software installation is not available.');
 }catch(e){notice(e.message);}
 finally{inFlight=false;}
}
async function save(){
 const d=device();if(!d?.canManage)return;
 const min=$('glassesSoftwareMinimum').value.trim();
 const target=$('glassesSoftwareTarget').value.trim();
 const notes=$('glassesSoftwareNotes').value.trim();
 const version=/^(?:0|[1-9][0-9]{0,4})(?:\.(?:0|[1-9][0-9]{0,4})){1,3}$/;
 if((min&&!version.test(min))||(target&&!version.test(target))){
  notice('Use dotted numeric versions such as 1.2.3.');return;
 }
 if(min&&target&&compare(target,min)<0){notice('Target must be at or above the minimum version.');return;}
 const saveButton=$('glassesSoftwareSave');saveButton.disabled=true;
 try{
  const r=await request(api,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':cfg.csrf},
   body:JSON.stringify({action:'policy.save',csrf_token:cfg.csrf,devicePublicId:d.publicId,
    minimumVersion:min,targetVersion:target,notes,expectedRevision:d.policy?.revision||0})});
  await refresh();notice('Version policy saved (revision '+r.policy.revision+'). No device update was installed.');
 }catch(e){notice(e.message+' Refresh policy before retrying.');}
 finally{saveButton.disabled=!device()?.canManage;}
}
function compare(a,b){
 const aa=a.split('.').map(Number),bb=b.split('.').map(Number);
 for(let i=0;i<Math.max(aa.length,bb.length);i++){
  const diff=(aa[i]||0)-(bb[i]||0);if(diff)return diff;
 }
 return 0;
}
async function diagnostics(){
 const d=device();if(!d)return;
 const area=$('glassesSoftwareEvents');area.hidden=false;area.replaceChildren();
 try{
  const r=await request(api+'?action=diagnostics&devicePublicId='+encodeURIComponent(d.publicId));
  area.appendChild(el('strong','Recent device events (excluding heartbeats)'));
  if(!r.diagnostics.events.length){area.appendChild(el('p','No recent device events.'));return;}
  const list=el('ul');
  for(const event of r.diagnostics.events){
   const li=el('li',event.eventType+' · '+event.at);list.appendChild(li);
  }
  area.appendChild(list);
 }catch(e){area.textContent=e.message;}
}
function mount(){
 if(ready||!$('glassesSoftwareManager'))return;ready=true;
 $('glassesSoftwareRefresh').addEventListener('click',refresh);
 $('glassesSoftwareSave').addEventListener('click',save);
 $('glassesSoftwareDiagnostics').addEventListener('click',diagnostics);
 $('glassesSoftwareDevice').addEventListener('change',event=>{chosen=event.target.value;render();});
 window.addEventListener('gelato-glasses-tab-select',event=>{if(event.detail?.tab==='runtime')refresh();});
 refresh();
}
window.GelatoGlassesSoftware={refresh,followRuntime};
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount);
else mount();
})();