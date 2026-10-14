/* Section 281: independently initialized, on-device MediaPipe Hand Landmarker.
 * No camera permission requests, uploads, biometric identification or KDS writes.
 * Vendor assets are packaged in the certified full deployment ZIP. */
((root,factory)=>{
  'use strict';
  const api=factory();
  if(typeof module==='object'&&module.exports)module.exports=api;
  if(root)root.GelatoMediaPipeHands=api;
})(typeof window!=='undefined'?window:null,()=>{
  'use strict';
  const VERSION='0.10.32';
  const CDN='https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@'+VERSION+'/';
  const MODEL='https://storage.googleapis.com/mediapipe-models/hand_landmarker/hand_landmarker/float16/1/hand_landmarker.task';
  const LOCAL='assets/vendor/mediapipe/';
  const clamp=x=>Math.max(0,Math.min(1,x));
  function convert(result,padding=.045){
    const hands=Array.isArray(result?.landmarks)?result.landmarks:[];
    const sided=result?.handednesses||result?.handedness||[];
    return hands.slice(0,2).map((points,i)=>{
      const valid=(Array.isArray(points)?points:[]).filter(p=>Number.isFinite(p?.x)&&Number.isFinite(p?.y));
      if(valid.length<5)return null;
      const xmin=clamp(Math.min(...valid.map(p=>p.x))-padding),xmax=clamp(Math.max(...valid.map(p=>p.x))+padding);
      const ymin=clamp(Math.min(...valid.map(p=>p.y))-padding),ymax=clamp(Math.max(...valid.map(p=>p.y))+padding);
      if(xmax-xmin<.002||ymax-ymin<.002)return null;
      const info=sided[i]?.[0]||{},side=String(info.categoryName||info.displayName||'').toLowerCase();
      return {kind:'hand',label:side==='left'?'left hand':side==='right'?'right hand':'hand',
        handedness:side==='left'||side==='right'?side:null,
        confidence:Math.max(.5,Math.min(1,Number(info.score)||.8)),
        bbox:{x:xmin,y:ymin,width:xmax-xmin,height:ymax-ymin}};
    }).filter(Boolean);
  }
  function createRuntime(options={}){
    const importModule=options.importModule||((url)=>import(url));
    const fileset=options.fileset||null;
    const modelPath=options.modelPath||null;
    const setDelay=options.setDelay||((fn,ms)=>setTimeout(fn,ms));
    const cancelDelay=options.cancelDelay||((id)=>clearTimeout(id));
    const now=options.now||(()=>Date.now());
    const frameClock=options.frameClock||(()=>performance.now());
    const hidden=options.hidden||(()=>document.hidden);
    const emit=options.emit||((type,detail)=>window.dispatchEvent(new CustomEvent(type,{detail})));
    const onStatus=options.onStatus||(()=>{});
    const interval=Math.max(100,Math.min(333,Number(options.intervalMs)||125));
    let epoch=0,timer=null,detector=null,loaded=null,phase='ready',video=null,context=null;
    let otherObjects=[],otherAt=0,otherSeq=0,otherSha=null,otherItemId=null,lastFrame=-Infinity;
    function status(value,note=''){
      phase=value;onStatus(value,note);
    }
    async function load(){
      if(detector)return detector;
      if(loaded)return loaded;
      loaded=(async()=>{
        const localUrl=(typeof document!=='undefined'?new URL(LOCAL,document.baseURI).href:LOCAL);
        const moduleUrl=localUrl+'vision_bundle.mjs';
        let vision,assetDir,modelFile;
        try{
          vision=await importModule(moduleUrl);assetDir=localUrl+'wasm';modelFile=localUrl+'hand_landmarker.task';
        }catch{
          vision=await importModule(CDN+'vision_bundle.mjs');assetDir=CDN+'wasm';modelFile=MODEL;
        }
        const FilesetResolver=vision.FilesetResolver,HandLandmarker=vision.HandLandmarker;
        if(!FilesetResolver?.forVisionTasks||!HandLandmarker?.createFromOptions)
          throw Error('MediaPipe Hand Landmarker module could not be initialized.');
        const create=async(wasm,model)=>HandLandmarker.createFromOptions(
          await (fileset||FilesetResolver.forVisionTasks)(wasm),{
            baseOptions:{modelAssetPath:model,delegate:'CPU'},runningMode:'VIDEO',numHands:2,
            minHandDetectionConfidence:.50,minHandPresenceConfidence:.50,minTrackingConfidence:.35
          });
        try{detector=await create(assetDir,modelPath||modelFile);}
        catch(err){
          if(assetDir===CDN||modelPath)throw err;
          // The archive may be deployed without optional vendor assets, or the
          // server may not serve .mjs/.wasm with their required MIME types.
          detector=await create(CDN+'wasm',MODEL);
        }
        return detector;
      })();
      try{return await loaded;}
      catch(error){loaded=null;throw error;}
    }
    function schedule(run){timer=setDelay(run,interval);}
    function emitFrame(hands){
      const scope=typeof context==='function'?context()||{}:{};
      emit('gelato-glasses-hand-frame',{
        timestamp:now(),cameraReady:!!video,modelReady:true,hands,
        objects:otherItemId&&otherItemId===scope.activeItem?.kdsItemPublicId&&now()-otherAt<900?
          otherObjects:[],
        objectTime:otherAt,
        objectSeq:otherItemId===scope.activeItem?.kdsItemPublicId?otherSeq:null,
        modelSha256:otherItemId===scope.activeItem?.kdsItemPublicId?otherSha:null,
        activeItem:scope.activeItem||null,
        actions:scope.actions||{}
      });
    }
    function tick(activeEpoch){
      if(activeEpoch!==epoch)return;
      if(hidden()||!video||video.readyState<2||!video.videoWidth||!video.videoHeight){
        schedule(()=>tick(activeEpoch));return;
      }
      try{
        const stamp=Math.max(lastFrame+1,frameClock());lastFrame=stamp;
        const result=detector.detectForVideo(video,stamp);
        emitFrame(convert(result));
      }catch(error){
        stop('error');status('error','Hand tracking paused: '+String(error?.message||error));
        return;
      }
      schedule(()=>tick(activeEpoch));
    }
    async function start(camera,selectedContext){
      stop('ready');
      if(!camera?.srcObject){status('ready','Start Camera 1 to track both hands.');return false;}
      const started=epoch;
      video=camera;context=selectedContext;
      status('loading','Loading pretrained hand detector…');
      try{
        await load();
        if(started!==epoch||!video?.srcObject)return false;
        lastFrame=-Infinity;status('running','Two-hand detector active · on-device video processing.');
        tick(started);return true;
      }catch(error){
        if(started!==epoch)return false;
        video=null;context=null;
        status('error','Hand detector could not load. Check internet/WASM access. '+String(error?.message||error));
        return false;
      }
    }
    function stop(reason='ready'){
      epoch++;
      if(timer!==null){cancelDelay(timer);timer=null;}
      video=null;context=null;otherObjects=[];otherAt=0;otherSeq=0;otherSha=null;otherItemId=null;lastFrame=-Infinity;
      status(reason,reason==='ready'?'Ready when Camera 1 starts.':'');
      emit('gelato-glasses-hand-reset',{});
    }
    function setObjects(objects,metadata={}){
      otherObjects=(Array.isArray(objects)?objects:[]).slice(0,16);
      otherAt=now();otherSeq++;
      otherSha=/^[a-f0-9]{64}$/i.test(String(metadata.modelSha256||''))?String(metadata.modelSha256):null;
      otherItemId=String(metadata.kdsItemPublicId||'')||null;
    }
    function close(){stop('ready');detector?.close?.();detector=null;loaded=null;}
    return {start,stop,close,setObjects,status:()=>phase,isActive:()=>phase==='running',version:VERSION};
  }
  return {createRuntime,convert,VERSION,CDN,MODEL,LOCAL};
});