/* Gelato 285C — shared qualified model, per-station read-only camera observations.
 * One session is owned by glasses-web-simulator; this module never opens streams,
 * loads unapproved models, collects frames, or writes KDS/POS/build status.
 */
((root,factory)=>{'use strict';const api=factory();
  if(typeof module==='object'&&module.exports)module.exports=api;
  if(root)root.GelatoSharedCameraRecognition=api;
})(typeof window!=='undefined'?window:null,()=>{'use strict';
 const SHA=/^[a-f0-9]{64}$/i,MIN=2,MAX=5;
 const validBox=b=>b&&[b.x,b.y,b.width,b.height].every(Number.isFinite)&&b.width>0&&b.height>0&&b.x>=0&&b.y>=0&&b.x+b.width<=1.001&&b.y+b.height<=1.001;
 function sanitize(raw){return (Array.isArray(raw)?raw:[]).slice(0,64).filter(d=>
   typeof d?.label==='string'&&d.label.length<=120&&Number.isFinite(d.confidence)&&
   d.confidence>=0&&d.confidence<=1&&validBox(d.bbox)).slice(0,16).map(d=>({
    label:d.label,confidence:d.confidence,bbox:{x:d.bbox.x,y:d.bbox.y,width:d.bbox.width,height:d.bbox.height}
   }));}
 function create({now=()=>Date.now()}={}){
  let cursor=MIN;const data=new Map();
  function reset(){cursor=MIN;data.clear();}
  function clearSlot(slot){data.delete(slot);}
  function status(){return Array.from({length:4},(_,index)=>{
   const slot=index+MIN,record=data.get(slot);
   return record?{...record}:{slot,state:'waiting',count:0};
  });}
  function pick({configuration,multiCamera,locationId}={}){
   if(!configuration||!multiCamera||!locationId||Number(configuration.locationId)!==Number(locationId))return null;
   for(let i=0;i<4;i++){
    const slot=cursor;cursor=slot===MAX?MIN:slot+1;
    const c=configuration.getSaved?.(slot),video=multiCamera.video?.(slot);
    if(!c||c.enabled!==true||!configuration.isEnabled?.(slot)||
       !video?.srcObject||!video.videoWidth||!video.videoHeight||
       !video.srcObject.getVideoTracks?.().some(t=>t.readyState!=='ended')){
      data.delete(slot);continue;
    }
    return {slot,video,stream:video.srcObject,stationPublicId:c.stationPublicId||null,
      role:c.role||'custom',locationId:Number(locationId),revision:c.revision||0};
   }
   return null;
  }
  function accept({source,modelSha256,raw,devicePublicId,locationId,currentStationPublicId,
    valid=true,capturedAt=now()}={}){
   if(!source||source.slot<MIN||source.slot>MAX||valid!==true||
      !SHA.test(String(modelSha256||''))||!devicePublicId||
      Number(locationId)!==Number(source.locationId)||
      !Number.isFinite(capturedAt)||capturedAt>now()+1000||now()-capturedAt>1600){
     if(source?.slot>=MIN&&source?.slot<=MAX)data.delete(source.slot);
     return null;
   }
   const objects=sanitize(raw),scope=source.stationPublicId&&
      source.stationPublicId===currentStationPublicId?'assigned_station':'unscoped';
   const result={slot:source.slot,state:'recognized',count:objects.length,sourceKind:'stationary',
    stationPublicId:source.stationPublicId||null,scope,
    locationId:source.locationId,devicePublicId,modelSha256,
    capturedAt,role:source.role,objects};
   data.set(source.slot,{slot:source.slot,state:'recognized',count:objects.length,
     scope,stationPublicId:source.stationPublicId||null,capturedAt,
     modelSha256,role:source.role});
   return result;
  }
  return Object.freeze({pick,accept,status,clearSlot,reset});
 }
 return Object.freeze({create,sanitize});
});

