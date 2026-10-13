(() => {
  'use strict';
  if (window.GelatoVoiceTurns) return;
  const KEY='gelato.voice.device.v1';
  const presets={
    quiet:{label:'Quiet room',confidence:0.22,requiresWake:false},
    standard:{label:'Normal workspace',confidence:0.38,requiresWake:false},
    noisy:{label:'Busy kitchen / TV',confidence:0.62,requiresWake:true}
  };
  const safeRead=()=>{try{return JSON.parse(localStorage.getItem(KEY)||'{}')}catch{return {}}};
  let saved=safeRead();
  let environment=presets[saved.environment]?saved.environment:'standard';
  let voiceURI=String(saved.voiceURI||'');
  let rate=Math.min(1.25,Math.max(.8,Number(saved.rate)||1));
  let speaking=false,owner='',pending=[],current='',lastSpoken='',lastEnd=0,token=0,endCallback=null;
  const norm=s=>String(s||'').toLowerCase().replace(/[^\p{L}\p{N}\s]/gu,' ').replace(/\s+/g,' ').trim();
  function save(){try{localStorage.setItem(KEY,JSON.stringify({environment,voiceURI,rate}))}catch{}}
  function notify(kind,detail={}){window.dispatchEvent(new CustomEvent('gelato-voice-turn',{detail:{kind,speaking,owner,...detail}}));}
  function splitSpeech(raw){
    const text=String(raw||'').trim(),out=[];let remain=text;
    while(remain){
      if(remain.length<=650){out.push(remain);break;}
      let cut=remain.lastIndexOf('. ',650);
      if(cut<190)cut=remain.lastIndexOf(' ',650);
      if(cut<100)cut=650;else cut+=1;
      out.push(remain.slice(0,cut).trim());remain=remain.slice(cut).trim();
    }
    return out;
  }
  function chooseVoice(){
    const voices=window.speechSynthesis?.getVoices?.()||[];
    return voices.find(v=>v.voiceURI===voiceURI)||voices.find(v=>/^en([-_]|$)/i.test(v.lang||''))||null;
  }
  function finish(id,interrupted=false){
    if(id!==token)return;
    speaking=false;lastEnd=Date.now();current='';owner='';pending=[];
    const callback=endCallback;endCallback=null;
    notify(interrupted?'interrupted':'ended');
    try{callback?.({interrupted})}catch{}
  }
  function next(id){
    if(id!==token)return;
    if(!pending.length){finish(id);return;}
    if(!window.speechSynthesis||typeof window.SpeechSynthesisUtterance!=='function'){finish(id);return;}
    current=pending.shift();
    const utterance=new window.SpeechSynthesisUtterance(current);
    utterance.rate=rate;utterance.lang='en-US';
    const voice=chooseVoice();if(voice)utterance.voice=voice;
    let settled=false;
    utterance.onend=utterance.onerror=()=>{if(settled||id!==token)return;settled=true;next(id);};
    try{window.speechSynthesis.speak(utterance);}catch{finish(id);}
  }
  function interrupt(source='button'){
    const wasSpeaking=speaking;
    const interruptedOwner=owner;
    const cb=endCallback;
    token++;speaking=false;pending=[];current='';owner='';endCallback=null;lastEnd=Date.now();
    try{window.speechSynthesis?.cancel?.()}catch{}
    if(wasSpeaking){notify('interrupted',{source,owner:interruptedOwner});try{cb?.({interrupted:true,source})}catch{}}
    return wasSpeaking;
  }
  function speak(text,options={}){
    const segments=splitSpeech(text);
    if(!segments.length||!window.speechSynthesis||typeof window.SpeechSynthesisUtterance!=='function')return false;
    interrupt('replaced');
    token++;const id=token;
    speaking=true;owner=String(options.owner||'agent');lastSpoken=norm(text);
    pending=segments;endCallback=options.onEnd||null;
    notify('speaking',{chunks:segments.length});
    next(id);return true;
  }
  function resemblesSpeech(raw){
    const phrase=norm(raw);
    if(!phrase||!lastSpoken)return false;
    // Match exact phrases emitted by TTS, including short quoted instructions.
    if(phrase.length>=8&&lastSpoken.includes(phrase))return true;
    const words=phrase.split(' ').filter(w=>w.length>2);
    if(words.length<3)return false;
    const unique=[...new Set(words)];
    return unique.filter(w=>lastSpoken.split(' ').includes(w)).length/unique.length>=.82;
  }
  function evaluate(raw,confidence=undefined,{wakeRequired=false}={}){
    const text=String(raw||'').trim(),phrase=norm(text);
    if(!phrase)return {kind:'ignored',reason:'empty',text};
    const woken=/^(?:hey|hi|okay|ok)\s+gelato\b/.test(phrase);
    const isStop=/^(?:(?:hey|hi|okay|ok)\s+gelato\s+)?(?:stop|stop speaking|be quiet|quiet|interrupt|cancel speech|hold on)\b/.test(phrase);
    if(speaking){
      if(isStop&&(woken||phrase==='stop speaking'||phrase==='interrupt'))return {kind:'interrupt',text};
      if(resemblesSpeech(phrase))return {kind:'ignored',reason:'tts-echo',text};
      if(!woken)return {kind:'ignored',reason:'wake-required-while-speaking',text};
      // A genuine wake-word command is barge-in, not another concurrent TTS.
      return {kind:'command',interrupt:true,text};
    }
    if(Date.now()-lastEnd<1400&&resemblesSpeech(phrase))return {kind:'ignored',reason:'post-tts-echo',text};
    const preset=presets[environment];
    const score=Number(confidence);
    if(Number.isFinite(score)&&score>0&&score<preset.confidence&&!woken)
      return {kind:'ignored',reason:'low-confidence',text};
    if(wakeRequired||preset.requiresWake){
      if(!woken)return {kind:'ignored',reason:'wake-required',text};
    }
    return {kind:'command',interrupt:false,text};
  }
  function setEnvironment(value){
    if(!presets[value])return false;
    environment=value;save();notify('profile',{environment});
    return true;
  }
  function setVoice(value){
    voiceURI=String(value||'');save();notify('profile',{voiceURI});return true;
  }
  function setRate(value){
    rate=Math.min(1.25,Math.max(.8,Number(value)||1));save();notify('profile',{rate});return rate;
  }
  window.GelatoVoiceTurns={
    speak,interrupt,evaluate,setEnvironment,setVoice,setRate,
    get environment(){return environment;},get rate(){return rate;},get voiceURI(){return voiceURI;},
    get profiles(){return Object.keys(presets).map(id=>({id,...presets[id]}));},
    get voices(){return (window.speechSynthesis?.getVoices?.()||[]).filter(v=>/^en([-_]|$)/i.test(v.lang||''));},
    get speaking(){return speaking;},get owner(){return owner;},
    // Diagnostic only; no audio or voice biometric samples are stored.
    get currentText(){return current;}
  };
})();
