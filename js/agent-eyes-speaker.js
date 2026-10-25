/* Agent Eyes AE3: display-only correlation of independently verified voice and provisional visual presence. */
(function(root,factory){
 const api=factory();
 if(typeof module==='object'&&module.exports)module.exports=api;
 if(root)root.GelatoEyesSpeaker=api;
})(typeof window!=='undefined'?window:null,function(){
 'use strict';
 const pattern=/^r-[a-f0-9]{16,32}$/;
 function resolve(room){
  if(!room||room.status!=='active')return null;
  const speaker=room.currentSpeaker;
  if(!speaker||speaker.identityAssurance!=='correlated_voice_and_visual_observation'
    ||speaker.websiteAuthenticated!==false||speaker.agentAdministration!==false)return null;
  const id=Number(speaker.personId),trackId=String(speaker.trackId||'');
  if(!Number.isSafeInteger(id)||id<1||!pattern.test(trackId))return null;
  const people=Array.isArray(room.people)?room.people:[];
  const claims=people.filter(p=>p&&p.personId===id&&p.identityAssurance==='provisional_visual_match');
  if(claims.length!==1||claims[0].trackId!==trackId
    ||claims[0].identitySource!=='local_face_profile'
    ||typeof claims[0].displayName!=='string'||!claims[0].displayName.trim())return null;
  const name=claims[0].displayName.trim().slice(0,70);
  const handoff=speaker.handoffFromPreviousVerifiedTurn===true;
  return {trackId,personId:id,name,handoff,
    message:(handoff?'Speaker changed · ':'Speaking · ')+name+' · voice + provisional visual match'};
 }
 function key(room){
  const s=resolve(room);
  return s?[s.trackId,s.personId,s.handoff]:null;
 }
 return {resolve,key};
});
