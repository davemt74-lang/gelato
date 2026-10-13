(() => {
  'use strict';
  if (window.GelatoTrainingVoice) return;
  const $=id=>document.getElementById(id);
  const Recognition=window.SpeechRecognition||window.webkitSpeechRecognition;
  const once=$('trainingVoiceOnce'),always=$('trainingVoiceAlways'),statusNode=$('trainingVoiceStatus');
  if(!once||!always||!statusNode||!$('chatInput')||!$('sendChat'))return;
  let mode='off',rec=null,active=false,retry=0,retryTimer=null;
  const trainingActive=()=>!!document.querySelector('#workspaceSwitch [data-workspace-mode="training"].active')
    &&!!$('page-workspace')?.classList.contains('active');
  function status(code,detail) {
    statusNode.dataset.state=code;
    statusNode.textContent=detail;
    once.setAttribute('aria-pressed',mode==='once'?'true':'false');
    always.setAttribute('aria-pressed',mode==='always'?'true':'false');
    once.title=mode==='once'?'Stop dictation':'Dictate one training answer';
    always.title=mode==='always'?'Turn off hands-free dictation':'Enable hands-free training dictation';
    window.dispatchEvent(new CustomEvent('gelato-training-voice-status',{detail:{mode,active,code,message:detail}}));
  }
  function discard(){
    if(retryTimer!==null){clearTimeout(retryTimer);retryTimer=null;}
    if(rec){
      const old=rec;rec=null;old.onend=null;old.onerror=null;old.onresult=null;
      try{old.abort();}catch{}
    }
    active=false;
  }
  function stop(reason='Voice off · click microphone to dictate'){
    discard();mode='off';retry=0;status('off',reason);
  }
  function schedule(){
    if(mode!=='always'||document.hidden||!trainingActive())return;
    if(retry>=3){stop('Microphone paused after repeated errors · click Hands-free to retry');status('error',statusNode.textContent);return;}
    const delay=Math.min(4500,500*Math.pow(2,retry++));
    status('paused','Microphone reconnecting…');
    retryTimer=setTimeout(()=>{retryTimer=null;start();},delay);
  }
  function start(){
    if(mode==='off')return;
    if(document.hidden||!trainingActive()){status('paused','Microphone paused · return to the Training chat');return;}
    if(!Recognition){stop('Browser speech recognition unavailable · type your answer instead');status('unsupported',statusNode.textContent);return;}
    discard();
    const current=new Recognition();rec=current;
    current.lang='en-US';current.continuous=mode==='always';current.interimResults=false;
    current.maxAlternatives=1;
    current.onstart=()=>{
      if(rec!==current)return;
      active=true;retry=0;
      status('listening',mode==='once'?'Listening for one training answer…':'Listening · each completed phrase answers the current question');
    };
    current.onresult=event=>{
      if(rec!==current||document.hidden||!trainingActive())return;
      for(let i=event.resultIndex;i<event.results.length;i++){
        const result=event.results[i];
        if(!result?.isFinal)continue;
        const spoken=String(result[0]?.transcript||'').trim();
        if(!spoken)continue;
        // Training owns the composer and quiz session. Do not send to the
        // global Agent backend, open a modal or bypass existing grading.
        $('chatInput').value=spoken;
        $('sendChat').click();
        status('heard','Answer received: '+spoken.slice(0,90));
        if(mode==='once'){stop('Answer submitted · microphone off');return;}
      }
    };
    current.onerror=event=>{
      if(rec!==current)return;
      const code=String(event.error||'unavailable');
      if(['not-allowed','service-not-allowed','audio-capture'].includes(code)){
        stop('Microphone '+code+' · allow browser access, then click again');
        status('blocked',statusNode.textContent);return;
      }
      if(code==='no-speech')status('paused','No speech heard · waiting to retry');
      else if(code!=='aborted')status('error','Microphone: '+code+' · retrying if allowed');
    };
    current.onend=()=>{
      if(rec!==current)return;
      rec=null;active=false;
      if(mode==='once'){stop('Listening ended · click microphone to try again');return;}
      schedule();
    };
    try{
      current.start();status('starting','Requesting microphone access…');
    }catch(error){
      rec=null;active=false;
      status('error','Could not start microphone: '+(error?.message||'check browser permission'));
      if(mode==='always')schedule();else stop(statusNode.textContent);
    }
  }
  function select(next){
    if(mode===next){stop();return;}
    discard();mode=next;retry=0;
    if(!Recognition){stop('Speech recognition unsupported in this browser');status('unsupported',statusNode.textContent);return;}
    start();
  }
  once.addEventListener('click',()=>select('once'));
  always.addEventListener('click',()=>select('always'));
  document.addEventListener('visibilitychange',()=>{
    if(document.hidden&&mode!=='off'){discard();status('paused','Microphone paused · tab is hidden');}
    else if(mode!=='off'){start();}
  });
  document.querySelectorAll('#workspaceSwitch [data-workspace-mode]').forEach(button=>{
    button.addEventListener('click',()=>{
      // Admin mode must never keep the Training microphone active.
      if(button.dataset.workspaceMode!=='training'&&mode!=='off')stop('Voice off · left Training mode');
    });
  });
  window.addEventListener('beforeunload',discard);
  window.GelatoTrainingVoice={get mode(){return mode;},get active(){return active;},
    get supported(){return !!Recognition;},stop,startOnce:()=>select('once'),toggleAlways:()=>select('always')};
  if(!Recognition){once.disabled=true;always.disabled=true;status('unsupported','Speech recognition unavailable · type answers normally');}
  else status('off','Voice off · mic for one answer · ◉ for hands-free');
})();
