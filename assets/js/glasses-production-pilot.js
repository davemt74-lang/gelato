(function(){'use strict';
const boot=window.GELATO_VISION_LAB||{},root=document.getElementById('vlProductionPilots'),create=document.getElementById('vlPilotCreate');
if(!root)return;
let catalog=boot.catalog||{};
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
async function api(payload){
 payload.csrf_token=boot.csrfToken;
 const r=await fetch(boot.apiUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify(payload)});
 const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Pilot governance request failed.');
 await refresh();return j;
}
async function refresh(){
 const r=await fetch(boot.apiUrl,{credentials:'same-origin',cache:'no-store'}),j=await r.json();
 if(!r.ok||!j.ok)throw new Error(j.message||'Pilot refresh failed.');
 catalog=j.catalog||{};render();
}
function render(){
 const pg=catalog.productionPilots||{pilots:[],devices:[]},pilots=pg.pilots||[],members=pg.devices||[];
 if(!pilots.length){root.innerHTML='<div class="vl-empty">No production pilots yet. Real AIR3 devices remain blocked until the air3.vendor.v1 adapter is installed and readiness passes.</div>';return;}
 root.innerHTML=pilots.map(p=>{
   const devices=members.filter(d=>d.pilotPublicId===p.publicId);
   const status='<span class="vl-state '+esc(p.status)+'">'+esc(p.status)+(p.killSwitch?' · KILL SWITCH':'')+'</span>';
   const scope=[p.locationName||'All locations',p.stationName||'All stations',p.rolloutPublicId||'No bound rollout'].join(' · ');
   const deviceHtml=devices.length?devices.map(d=>'<article><div><strong>'+esc(d.displayName)+'</strong><small>'+esc(d.devicePublicId)+' · '+esc(d.platform)+'</small></div><div>'+esc(d.status)+'<small>readiness '+esc(d.readinessState||'unknown')+(d.readiness?.reasons?.length?' · '+esc(d.readiness.reasons.join(', ')):'')+'</small></div><div><button data-pilot-eval="'+esc(p.publicId)+'" data-device="'+esc(d.devicePublicId)+'">Evaluate</button> '+(d.status==='enabled'?'<button data-pilot-disable="'+esc(p.publicId)+'" data-device="'+esc(d.devicePublicId)+'">Disable</button>':'<button data-pilot-enable="'+esc(p.publicId)+'" data-device="'+esc(d.devicePublicId)+'">Enable</button>')+'</div></article>').join(''):'<div class="vl-empty">No devices enrolled in this cohort.</div>';
   return '<section class="vl-pilot-card"><div class="vl-head"><div><strong>'+esc(p.name)+'</strong><small>'+esc(p.cohortKey)+' · '+esc(p.releaseLabel)+' · required '+esc(p.requiredAdapterId)+'</small></div>'+status+'</div><small>'+esc(scope)+'</small><div class="inline-actions">'+
     (p.status==='draft'||p.status==='paused'?'<button data-pilot-activate="'+esc(p.publicId)+'">Activate</button>':'')+
     (p.status==='active'?'<button data-pilot-pause="'+esc(p.publicId)+'">Pause</button>':'')+
     (p.status!=='stopped'?'<button data-pilot-stop="'+esc(p.publicId)+'">Stop</button>':'')+
     '<button data-pilot-kill="'+esc(p.publicId)+'" data-enabled="'+(p.killSwitch?'0':'1')+'">'+(p.killSwitch?'Clear kill switch':'KILL SWITCH')+'</button>'+(p.rolloutPublicId?'<button data-pilot-rollback="'+esc(p.publicId)+'">Rollback rollout</button>':'')+
     '<button data-pilot-enroll="'+esc(p.publicId)+'">Enroll device</button></div><div class="vl-production-pilot-devices">'+deviceHtml+'</div></section>';
 }).join('');
}
function deviceChoice(){
 const devices=catalog.devices||[];if(!devices.length)throw new Error('No active paired glasses are available.');
 const menu=devices.map((d,i)=>(i+1)+'. '+d.display_name+' ('+d.public_id+')').join('\n');
 const choice=Number(prompt('Choose device:\n'+menu,'1')||0);if(choice<1||choice>devices.length)throw new Error('Device selection cancelled.');
 return devices[choice-1].public_id;
}
create?.addEventListener('click',async()=>{
 try{
   const name=prompt('Pilot name','AIR3 Kitchen Pilot');if(!name)return;
   const cohortKey=prompt('Cohort key','air3-kitchen-pilot');if(!cohortKey)return;
   const adapter=prompt('Required hardware adapter','air3.vendor.v1')||'air3.vendor.v1';
   const simulator=adapter==='simulator.v1';
   await api({action:'pilot.create',name,cohortKey,releaseLabel:'v10-pilot',requiredAdapterId:adapter,requireVendorAdapter:!simulator,notes:simulator?'Internal simulator pilot':'AIR3 production pilot'});
 }catch(e){alert(e.message);}
});
root.addEventListener('click',async e=>{
 const b=e.target.closest('button');if(!b)return;
 try{
  if(b.dataset.pilotEnroll)await api({action:'pilot.device_enroll',pilotPublicId:b.dataset.pilotEnroll,devicePublicId:deviceChoice()});
  else if(b.dataset.pilotActivate)await api({action:'pilot.activate',pilotPublicId:b.dataset.pilotActivate});
  else if(b.dataset.pilotPause){const reason=prompt('Pause reason');if(!reason)return;await api({action:'pilot.pause',pilotPublicId:b.dataset.pilotPause,reason});}
  else if(b.dataset.pilotStop){const reason=prompt('Stop reason');if(!reason)return;await api({action:'pilot.stop',pilotPublicId:b.dataset.pilotStop,reason});}
  else if(b.dataset.pilotKill){const enabled=b.dataset.enabled==='1',reason=enabled?prompt('Kill switch reason'):'';if(enabled&&!reason)return;await api({action:'pilot.kill_switch',pilotPublicId:b.dataset.pilotKill,enabled,reason});}
  else if(b.dataset.pilotRollback){const reason=prompt('Rollback reason');if(!reason)return;await api({action:'pilot.rollback_rollout',pilotPublicId:b.dataset.pilotRollback,reason});}
  else if(b.dataset.pilotEval)await api({action:'pilot.device_evaluate',pilotPublicId:b.dataset.pilotEval,devicePublicId:b.dataset.device,runtime:{}});
  else if(b.dataset.pilotEnable)await api({action:'pilot.device_enable',pilotPublicId:b.dataset.pilotEnable,devicePublicId:b.dataset.device,runtime:{}});
  else if(b.dataset.pilotDisable){const reason=prompt('Disable reason');if(!reason)return;await api({action:'pilot.device_disable',pilotPublicId:b.dataset.pilotDisable,devicePublicId:b.dataset.device,reason});}
 }catch(err){alert(err.message);}
});
render();
})();