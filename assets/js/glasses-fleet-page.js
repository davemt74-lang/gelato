(()=>{'use strict';
const cfg=window.GELATO_GLASSES_SIMULATOR||{},base='api/glasses-admin.php';
const $=id=>document.getElementById(id);
let pairing=null,timer=null,devices=[];
const message=(id,value)=>{const el=$(id);if(el)el.textContent=String(value);};
async function json(url,options={}){
 const response=await fetch(url,{credentials:'same-origin',cache:'no-store',...options});
 const data=await response.json().catch(()=>null);
 if(!response.ok||!data?.ok)throw Error(data?.message||'Device request failed ('+response.status+').');
 if(/^[a-f0-9]{64}$/i.test(data.csrfToken||''))cfg.csrf=data.csrfToken;
 return data;
}
function post(action,data){
 return json(base,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':cfg.csrf},
  body:JSON.stringify({action,csrf_token:cfg.csrf,...data})});
}
function closePair(){
 if(timer)clearInterval(timer);
 timer=null;pairing=null;
 const panel=$('fleetPairPanel');if(panel)panel.hidden=true;
 const cancel=$('fleetPairCancel');if(cancel)cancel.hidden=true;
 message('fleetPairCode','');
}
async function cancelPair(){
 const id=pairing?.id;closePair();
 if(!id)return;
 try{await post('pairing.cancel',{pairingPublicId:id});message('fleetPairStatus','Pairing code cancelled.');}
 catch(e){message('fleetPairStatus','Code no longer displayed; if unconsumed it expires automatically. '+e.message);}
}
async function checkPair(){
 if(!pairing)return;
 if(Date.now()>=pairing.until){closePair();message('fleetPairStatus','Pairing code expired.');return;}
 try{
  const r=await json(base+'?action=pairing.status&pairingPublicId='+encodeURIComponent(pairing.id));
  if(r.pairing.state==='paired'){
   closePair();await refreshDevices();await window.GelatoGlassesSoftware?.refresh?.();
   message('fleetPairStatus','Glasses paired successfully. Open Runtime and click + to assign them.');
  }else if(r.pairing.state!=='pending'){closePair();message('fleetPairStatus','Pairing request '+r.pairing.state+'.');}
 }catch(e){message('fleetPairStatus',e.message);}
}
async function beginPair(){
 const id=Number($('fleetPairLocation')?.value||0);
 if(id<1){message('fleetPairStatus','Select a restaurant location first.');return;}
 await cancelPair();
 try{
  const r=await post('pairing.create',{locationId:id,ttlMinutes:10});
  pairing={id:r.pairing.publicId,until:Date.now()+Number(r.pairing.expiresInSeconds||600)*1000};
  message('fleetPairCode',r.pairing.pairingCode);
  $('fleetPairPanel').hidden=false;$('fleetPairCancel').hidden=false;
  message('fleetPairStatus','Enter the code in the Gelato app on the glasses.');
  timer=setInterval(checkPair,3000);
 }catch(e){message('fleetPairStatus',e.message);}
}
async function refreshDevices(){
 try{
  const r=await json(base);devices=r.devices||[];
  const select=$('fleetDeviceSelect'),prior=select.value;
  select.replaceChildren(new Option('Choose paired glasses…',''));
  for(const d of devices)select.add(new Option((d.displayName||'Glasses')+' · '+(d.locationName||'Restaurant'),d.publicId));
  if(devices.some(d=>d.publicId===prior))select.value=prior;
  render();
  message('fleetDeviceStatus',devices.length+' registered device'+(devices.length===1?'':'s')+'.');
 }catch(e){message('fleetDeviceStatus',e.message);}
}
function render(){
 const d=devices.find(x=>x.publicId===$('fleetDeviceSelect').value);
 $('fleetDeviceName').value=d?.displayName||'';
 const manageable=!!d&&!!cfg.canManageMedia&&d.status==='active';
 $('fleetDeviceRename').disabled=!manageable;
 $('fleetDeviceRevoke').disabled=!manageable;
}
async function rename(){
 const d=devices.find(x=>x.publicId===$('fleetDeviceSelect').value);if(!d)return;
 try{
  await post('device.rename',{devicePublicId:d.publicId,displayName:$('fleetDeviceName').value.trim()});
  message('fleetDeviceStatus','Device renamed.');await refreshDevices();
 }catch(e){message('fleetDeviceStatus',e.message);}
}
async function revoke(){
 const d=devices.find(x=>x.publicId===$('fleetDeviceSelect').value);if(!d)return;
 if(!window.confirm('Revoke '+d.displayName+'? This ends device authentication and is not reversible.'))return;
 try{await post('device.revoke',{devicePublicId:d.publicId});message('fleetDeviceStatus','Device revoked.');await refreshDevices();
  await window.GelatoGlassesSoftware?.refresh?.();
 }catch(e){message('fleetDeviceStatus',e.message);}
}
function mount(){
 $('fleetPairStart')?.addEventListener('click',beginPair);
 $('fleetPairCancel')?.addEventListener('click',cancelPair);
 $('fleetDeviceSelect').addEventListener('change',render);
 $('fleetDeviceRename').addEventListener('click',rename);
 $('fleetDeviceRevoke').addEventListener('click',revoke);
 window.addEventListener('pagehide',()=>{if(timer)clearInterval(timer);timer=null;});
 refreshDevices();
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});else mount();
})();