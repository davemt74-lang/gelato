(function(root,factory){
  const api=factory();
  if(typeof module==='object'&&module.exports)module.exports=api;
  else root.GelatoGlassesSoakHarness=api;
})(typeof globalThis!=='undefined'?globalThis:this,function(){
  'use strict';
  function clamp(n,min,max){return Math.max(min,Math.min(max,Number(n)||0));}
  function seeded(seed){let s=(Number(seed)||1)>>>0;return function(){s=(s*1664525+1013904223)>>>0;return s/4294967296;};}
  function policy(input){
    input=input||{};
    return {
      cycles:clamp(input.cycles||500,10,10000),
      maxQueue:clamp(input.maxQueue||3,1,8),
      maxFrameAgeCycles:clamp(input.maxFrameAgeCycles||4,1,50),
      timeoutEvery:clamp(input.timeoutEvery||37,0,10000),
      networkFaultEvery:clamp(input.networkFaultEvery||71,0,10000),
      cameraFaultEvery:clamp(input.cameraFaultEvery||89,0,10000),
      inferenceFaultEvery:clamp(input.inferenceFaultEvery||113,0,10000),
      burstEvery:clamp(input.burstEvery||29,0,10000),
      burstSize:clamp(input.burstSize||6,1,25),
      jitterMaxCycles:clamp(input.jitterMaxCycles||3,0,20),
      seed:Math.max(1,Number(input.seed)||1337)
    };
  }
  function run(input){
    const p=policy(input),rand=seeded(p.seed),queue=[];
    let seq=0,lastDelivered=0,worker=false,maxWorkers=0,workers=0;
    let framesProduced=0,framesProcessed=0,framesDropped=0,framesStale=0,framesTimedOut=0;
    let recoveryState='ready',recoveryAttempts=0,recoverySuccesses=0,recoveryFailures=0,stalledCycles=0,unhandledErrors=0,maxQueue=0;
    let monotonic=true,pendingRecovery=0;

    function enqueue(cycle,count){
      for(let n=0;n<count;n++){
        seq++;framesProduced++;
        if(queue.length>=p.maxQueue){queue.shift();framesDropped++;}
        queue.push({seq,born:cycle,delay:Math.floor(rand()*(p.jitterMaxCycles+1))});
        maxQueue=Math.max(maxQueue,queue.length);
      }
    }
    function disconnect(){
      recoveryState='disconnected';queue.length=0;pendingRecovery=1;recoveryAttempts++;
    }
    function recover(){
      recoveryState='recovering';queue.length=0;
      pendingRecovery--;
      if(pendingRecovery<=0){recoveryState='ready';recoverySuccesses++;}
    }

    for(let cycle=1;cycle<=p.cycles;cycle++){
      try{
        if(p.networkFaultEvery&&cycle%p.networkFaultEvery===0)disconnect();
        else if(p.cameraFaultEvery&&cycle%p.cameraFaultEvery===0)disconnect();
        else if(p.inferenceFaultEvery&&cycle%p.inferenceFaultEvery===0)disconnect();
        if(recoveryState!=='ready'){recover();continue;}

        enqueue(cycle,p.burstEvery&&cycle%p.burstEvery===0?p.burstSize:1);
        if(worker){unhandledErrors++;continue;}
        if(!queue.length){stalledCycles++;continue;}
        worker=true;workers++;maxWorkers=Math.max(maxWorkers,workers);
        const frame=queue.shift();
        if(cycle-frame.born>p.maxFrameAgeCycles){framesStale++;}
        else if(p.timeoutEvery&&cycle%p.timeoutEvery===0){framesTimedOut++;}
        else if(frame.delay>0){frame.delay--;queue.unshift(frame);}
        else{
          if(frame.seq<=lastDelivered)monotonic=false;
          lastDelivered=frame.seq;framesProcessed++;
        }
        workers--;worker=false;
      }catch(e){unhandledErrors++;worker=false;workers=0;}
    }

    while(queue.length){
      const frame=queue.shift();
      if(frame.seq<=lastDelivered)monotonic=false;
      lastDelivered=Math.max(lastDelivered,frame.seq);framesProcessed++;
    }
    if(recoveryState!=='ready'){
      recoveryAttempts++;recoveryState='ready';recoverySuccesses++;
    }
    return {
      schema:'gelato.glasses_runtime_soak.v1',cycles:p.cycles,
      framesProduced,framesProcessed,framesDropped,framesStale,framesTimedOut,
      recoveryAttempts,recoverySuccesses,recoveryFailures,
      maxObservedQueueDepth:maxQueue,maxConcurrentWorkers:maxWorkers,
      stalledCycles,unhandledErrors,finalQueueDepth:queue.length,
      workerActive:worker,monotonicDelivery:monotonic,finalRecoveryState:recoveryState,
      policy:p
    };
  }
  return {policy,run};
});
