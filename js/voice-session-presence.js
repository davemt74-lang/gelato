(() => {
  'use strict';
  if (window.GelatoVoicePresence) return;
  // Origin-scoped, session-only microphone presence. Never broadcasts voice data,
  // text, device IDs, user identifiers, or authentication state.
  const channelName='gelato-voice-presence-v1';
  const sources=new Set(['glasses','global-agent','timeclock']);
  const state={local:{source:'none',active:false,mode:'off'},remote:[]};
  const id='tab-'+Math.random().toString(36).slice(2)+Date.now().toString(36);
  const peers=new Map();
  const TTL=9000;
  let channel=null;
  try {if(typeof BroadcastChannel==='function')channel=new BroadcastChannel(channelName);}catch{}
  function remote() {
    const now=Date.now();
    for(const [key,peer] of peers)if(now-peer.at>TTL)peers.delete(key);
    state.remote=Array.from(peers.values()).filter(x=>x.active).map(x=>({source:x.source,mode:x.mode,active:true}));
    return state.remote.slice();
  }
  function snapshot(){return {local:{...state.local},remote:remote()};}
  function change(){window.dispatchEvent?.(new CustomEvent('gelato-voice-presence-change',{detail:snapshot()}));}
  function publish(){
    try{channel?.postMessage({v:1,id,source:state.local.source,active:state.local.active,mode:state.local.mode});}catch{}
    change();
  }
  function report(source,active,mode='off') {
    if(!sources.has(source))return;
    const validMode=['off','active','starting','recovering','wake_standby','paused','blocked','failed'].includes(mode)?mode:'off';
    state.local={source,active:!!active,mode:validMode};
    publish();
  }
  if(channel){
    channel.onmessage=event=>{
      const x=event?.data;
      if(!x||x.v!==1||x.id===id||typeof x.id!=='string'||x.id.length>72||!sources.has(x.source))return;
      if(x.mode==='off'||!x.active)peers.delete(x.id);
      else peers.set(x.id,{source:x.source,mode:String(x.mode).slice(0,20),active:true,at:Date.now()});
      change();
    };
    try{channel.postMessage({v:1,id,hello:true});}catch{}
    const original=channel.onmessage;
    channel.onmessage=event=>{
      if(event?.data?.v===1&&event.data.hello&&event.data.id!==id){publish();return;}
      original(event);
    };
  }
  window.addEventListener('pagehide',()=>{report(state.local.source==='none'?'glasses':state.local.source,false,'off');});
  // Heartbeat expires orphaned tabs. No timers activate microphones.
  if(channel)setInterval(()=>{if(state.local.active)publish();else if(peers.size)change();},3000);
  window.GelatoVoicePresence={report,snapshot};
})();