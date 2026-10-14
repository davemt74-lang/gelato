/* Section 284: SDK-ready Camera 1 source boundary.
 * Does not load, simulate or infer a vendor SDK. The vendor bridge must explicitly
 * register, enumerate actual connected sources and return a genuine MediaStream.
 * Browsers/USB/HDMI remain managed by navigator.mediaDevices in the simulator.
 */
(()=>{
'use strict';
if(window.GelatoGlassesCameraSources)return;
const REQUIRED_ADAPTER='air3.vendor.v1';
const PREFIX='sdk:'+REQUIRED_ADAPTER+':';
let adapter=null;
const changes=()=>window.dispatchEvent?.(new Event('gelato-glasses-camera-sources-change'));
function isSdkSource(id){return typeof id==='string'&&id.startsWith(PREFIX);}
function keyFor(id){return PREFIX+encodeURIComponent(id);}
function sourceIdFor(key){
  if(!isSdkSource(key))throw new TypeError('Not a registered glasses camera key.');
  try{return decodeURIComponent(key.slice(PREFIX.length));}
  catch{throw new TypeError('Invalid glasses camera key.');}
}
function registerVendorAdapter(candidate){
  if(!candidate||candidate.adapterId!==REQUIRED_ADAPTER||
    typeof candidate.listSources!=='function'||typeof candidate.openStream!=='function')
    throw new TypeError('AIR3 adapter must implement listSources() and openStream(sourceId,constraints).');
  if(adapter&&adapter!==candidate)throw new Error('A glasses vendor adapter is already registered.');
  adapter=candidate;changes();return true;
}
function unregisterVendorAdapter(candidate){
  if(!adapter||adapter!==candidate)return false;
  adapter=null;changes();return true;
}
async function listSdkSources(){
  if(!adapter)return [];
  const entries=await adapter.listSources();
  if(!Array.isArray(entries))throw new TypeError('AIR3 listSources must return an array.');
  const seen=new Set(),sources=[];
  for(const item of entries.slice(0,16)){
    if(!item||item.connected!==true||typeof item.id!=='string'||!item.id||
       item.id.length>128||seen.has(item.id))continue;
    seen.add(item.id);
    sources.push({key:keyFor(item.id),kind:'sdk',adapterId:REQUIRED_ADAPTER,
      label:String(item.label||'AIR3 Glasses Camera').slice(0,120),perspective:'glasses_pov'});
  }
  return sources;
}
async function openSdkStream(key,videoConstraints){
  const chosen=adapter;
  if(!chosen)throw new Error('AIR3 vendor SDK adapter is not installed or connected.');
  const id=sourceIdFor(key);
  const entries=await listSdkSources();
  if(adapter!==chosen||!entries.some(x=>x.key===key))
    throw new Error('Selected AIR3 camera is disconnected or unavailable.');
  const stream=await chosen.openStream(id,{video:videoConstraints,audio:false});
  if(!stream||typeof stream.getVideoTracks!=='function'||typeof stream.getTracks!=='function'||
    stream.getVideoTracks().length===0){
    if(typeof stream?.getTracks==='function')for(const track of stream.getTracks())track.stop?.();
    throw new TypeError('AIR3 adapter did not provide a video MediaStream.');
  }
  if(adapter!==chosen){
    for(const track of stream.getTracks())track.stop?.();
    throw new Error('AIR3 camera disconnected while starting.');
  }
  return stream;
}
window.GelatoGlassesCameraSources=Object.freeze({
  sdkAdapterId:REQUIRED_ADAPTER,isSdkSource,registerVendorAdapter,unregisterVendorAdapter,
  listSdkSources,openSdkStream,isVendorInstalled:()=>!!adapter
});
})();
