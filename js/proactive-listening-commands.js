(() => {
  'use strict';
  if (window.GelatoProactiveCommands) return;
  const normalize=text=>String(text||'').toLowerCase().replace(/[.,!?;:]/g,' ').replace(/\s+/g,' ').trim();
  function parse(raw,wake='Hey Gelato'){
    const phrase=normalize(raw),prefix=normalize(wake||'Hey Gelato');
    // Only explicit wake-prefixed controls; ordinary kitchen speech/TV cannot
    // silently turn on the microphone or change the saved preference.
    if(!prefix||!phrase.startsWith(prefix+' '))return null;
    const command=phrase.slice(prefix.length).trim();
    if(/^(?:start listening|resume listening|enable listening|listen now|wake up)(?: please)?$/.test(command))return 'start';
    if(/^(?:stop listening|pause listening|disable listening)(?: please)?$/.test(command))return 'stop';
    if(/^(?:microphone off|turn off microphone|stop microphone|mic off|stop all listening)(?: please)?$/.test(command))return 'off';
    return null;
  }
  function display({saved=false,active=false,wake=false,blocked=false,hidden=false}={}){
    if(blocked)return {code:'blocked',label:'Microphone blocked',message:'Check browser microphone permission, then click Start Listening.'};
    if(hidden&&(active||wake))return {code:'paused',label:'Microphone paused',message:'Tab hidden. Return to this tab to resume the explicitly started session.'};
    if(active)return {code:'listening',label:'Actively listening',message:'Say “Hey Gelato, stop listening” to return to wake standby.'};
    if(wake)return {code:'wake',label:'Wake standby · microphone on',message:'Only wake controls are accepted. Say “Hey Gelato, start listening”, or Microphone Off.'};
    if(saved)return {code:'saved',label:'Saved on · microphone off',message:'Your preference was restored. Click Start Listening to activate the microphone.'};
    return {code:'off',label:'Microphone off',message:'Listening preference off. Click Start Listening to begin.'};
  }
  window.GelatoProactiveCommands={parse,display};
})();
