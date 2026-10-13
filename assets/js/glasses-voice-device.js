(() => {
  'use strict';
  if (window.GelatoGlassesVoiceDevice) return;
  const ERRORS = new Set(['not-allowed','service-not-allowed','audio-capture','network','no-speech','aborted','language-not-supported','bad-grammar','unknown','start-failed','device-lost']);
  const STATES = new Set(['off','certifying','active','wake_standby','recovering','blocked','failed','paused','starting']);
  const MAX_RETRIES = 3;
  const RETRY_DELAYS = Object.freeze([750,1500,3000]);
  const now = () => new Date().toISOString();
  const state = {state:'off',microphoneActive:false,consented:false,retries:0,lastError:'none',lastRecovery:'never',lastCertified:'never',certification:null,events:[]};
  const safeError = error => ERRORS.has(String(error)) ? String(error) : 'unknown';
  function emit() {
    window.dispatchEvent?.(new CustomEvent('gelato-glasses-voice-device-change',{detail:snapshot()}));
  }
  function snapshot() {
    return {...state,events:state.events.map(e=>({...e})),certification:state.certification?{
      ...state.certification,checks:state.certification.checks.map(c=>({...c}))
    }:null};
  }
  function log(kind,error) {
    const entry={at:now(),kind:String(kind).slice(0,34)};
    if(error)entry.error=safeError(error);
    state.events.push(entry);
    if(state.events.length>30)state.events.shift();
  }
  function transition(next,{error,consented,microphoneActive,retry}={}) {
    if (!STATES.has(next)) return false;
    if (typeof consented==='boolean') state.consented=consented;
    if (!state.consented && ['active','wake_standby','recovering','starting'].includes(next)) return false;
    if (typeof retry==='number') state.retries=Math.max(0,Math.min(MAX_RETRIES,Math.floor(retry)));
    if (error)state.lastError=safeError(error);
    if(next==='recovering')state.lastRecovery=now();
    state.state=next;
    state.microphoneActive=(next==='active'||next==='wake_standby') && !!microphoneActive;
    if(next==='off') {state.consented=false;state.retries=0;state.microphoneActive=false;}
    if(['blocked','failed','paused'].includes(next))state.microphoneActive=false;
    log(next,error);
    window.GelatoVoicePresence?.report?.('glasses',state.microphoneActive,next);
    emit();return true;
  }
  const recoveryDelay = attempt => attempt>=1 && attempt<=MAX_RETRIES ? RETRY_DELAYS[attempt-1] : null;
  function check(id,label,status,detail) {return {id,label,status,detail:String(detail||'').slice(0,210)};}
  function classify(checks) {
    if(checks.some(c=>c.status==='failed'))return 'failed';
    return checks.some(c=>c.status==='warning')?'warning':'ready';
  }
  async function certify({probeMicrophone=false}={}) {
    // Explicit user action only. Never opens an audio stream on page load.
    transition('certifying',{consented:state.consented});
    const n=window.navigator||{},md=n.mediaDevices;
    const Recognition=window.SpeechRecognition||window.webkitSpeechRecognition;
    const checks=[];
    const secure=window.isSecureContext!==false;
    checks.push(check('secure','Secure browser context',secure?'ready':'failed',secure?'HTTPS/local secure context':'Open over HTTPS to use the microphone.'));
    let permission='unknown';
    try {
      if(n.permissions?.query){const p=await n.permissions.query({name:'microphone'});permission=p.state||'unknown';}
    }catch{}
    const permissionStatus=permission==='denied'?'failed':permission==='granted'?'ready':'warning';
    checks.push(check('permission','Microphone permission',permissionStatus,permission==='denied'?'Allow microphone access in browser settings.':permission==='granted'?'Granted by browser':'Browser will request access when needed.'));
    let inputs=[];
    try {if(md?.enumerateDevices)inputs=(await md.enumerateDevices()).filter(d=>d.kind==='audioinput');}catch{}
    checks.push(check('input','Audio input',!md?'failed':inputs.length?'ready':'warning',inputs.length?inputs.length+' microphone input(s)':'No input exposed yet; connect a microphone or grant permission.'));
    let captureStatus= !md?.getUserMedia?'failed':'warning',captureDetail=md?.getUserMedia?'Run microphone test to verify actual capture.':'Browser microphone capture unavailable.';
    let echoStatus='warning',echoDetail='Device echo processing has not been measured.';
    const voice=window.GelatoGlassesVoiceUI;
    if (probeMicrophone && md?.getUserMedia && secure) {
      if (voice?.status?.().microphoneActive) {
        captureDetail='Stop active voice capture before running the microphone probe.';
      } else {
        let stream;
        try {
          stream=await md.getUserMedia({audio:{echoCancellation:{ideal:true},noiseSuppression:{ideal:true},autoGainControl:{ideal:false}},video:false});
          const track=stream.getAudioTracks?.()[0];
          captureStatus=track?.readyState==='live'?'ready':'failed';
          captureDetail=captureStatus==='ready'?'Temporary test stream acquired successfully.':'Microphone track not live.';
          const settings=track?.getSettings?.()||{};
          const capabilities=track?.getCapabilities?.()||{};
          if(settings.echoCancellation===true){echoStatus='ready';echoDetail='Browser reports echoCancellation enabled.';}
          else if('echoCancellation' in settings||'echoCancellation' in capabilities){echoStatus='warning';echoDetail='Echo cancellation reported, but not confirmed enabled.';}
        }catch(e){
          const name=String(e?.name||'');
          captureStatus='failed';
          captureDetail=/NotAllowed|PermissionDenied|Security/.test(name)?'Permission denied. Enable microphone for this site.':/NotFound|DevicesNotFound/.test(name)?'No microphone found. Attach a device and retry.':'Could not acquire a microphone; check browser and device settings.';
        }finally{try{stream?.getTracks?.().forEach(t=>t.stop());}catch{}}
      }
    }
    checks.push(check('capture','Microphone capture',captureStatus,captureDetail));
    checks.push(check('speech','Speech recognition',Recognition?'ready':'failed',Recognition?'Browser recognition API available.':'Use a supported Chromium browser or manual navigation.'));
    checks.push(check('tts','Spoken replies',typeof window.speechSynthesis?.speak==='function'?'ready':'warning','Browser speech synthesis availability.'));
    checks.push(check('wake','Wake listening',Recognition?'warning':'failed',Recognition?'Simulator uses explicit Start Voice; no continuous hardware wake engine is claimed.':'Requires speech recognition.'));
    checks.push(check('echo','Echo control',echoStatus,echoDetail));
    checks.push(check('barge','Barge-in',typeof window.speechSynthesis?.cancel==='function'?'warning':'failed','Stop speech is supported; device-specific barge-in needs a live test.'));
    checks.push(check('speaker','Speaker verification','warning','Glasses simulator does not verify enrolled speaker identity; signed-in account permissions remain enforced.'));
    checks.push(check('recovery','Auto recovery','ready','Bounded 3-attempt retry policy; permission errors stop recovery.'));
    checks.push(check('visibility','Tab visibility',typeof document.addEventListener==='function'?'ready':'warning','Hidden tabs pause capture; explicit active sessions may resume when visible.'));
    checks.push(check('device-change','Device changes',typeof md?.addEventListener==='function'?'ready':'warning','Input device changes are observed when supported.'));
    state.lastCertified=now();
    state.certification={status:classify(checks),at:state.lastCertified,checks};
    const wants=voice?.status?.();
    const was=state.consented;
    // Certification does not begin live listening; it restores only an already-active
    // controller's declared state and never gives microphone ownership to diagnostics.
    const target=was && wants?.microphoneActive?'active':was?'paused':'off';
    if (target==='off')transition('off');
    else transition(target,{consented:was,microphoneActive:!!wants?.microphoneActive});
    emit();
    return snapshot().certification;
  }
  window.GelatoGlassesVoiceDevice = {MAX_RETRIES,RETRY_DELAYS,recoveryDelay,transition,certify,snapshot};
})();