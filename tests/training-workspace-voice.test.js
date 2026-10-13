'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const script=fs.readFileSync('js/training-workspace-voice.js','utf8');
function testBrowser(supportsSpeech){
  const listeners={},nodes={},instances=[],submissions=[],states=[],timeoutQueue=[];
  let activeMode='training',pageActive=true,hidden=false;
  function node(id){
    if(!nodes[id]){
      const callbacks={};
      nodes[id]={
        id,dataset:{},value:'',disabled:false,textContent:'',title:'',
        classList:{contains:name=>name==='active'&&pageActive},
        setAttribute(name,value){this[name]=value;},
        addEventListener(event,fn){callbacks[event]=fn;},
        click(){callbacks.click?.({target:this});},
        listeners:callbacks
      };
    }
    return nodes[id];
  }
  const train=node('trainSwitch');train.dataset.workspaceMode='training';
  const admin=node('adminSwitch');admin.dataset.workspaceMode='admin';
  node('sendChat').click=()=>submissions.push(node('chatInput').value);
  class FakeSpeech{
    constructor(){instances.push(this);this.started=false;}
    start(){this.started=true;}
    abort(){this.aborted=true;}
    hear(message){
      this.onresult?.({resultIndex:0,results:[{isFinal:true,0:{transcript:message}}]});
    }
  }
  const document={
    get hidden(){return hidden;},
    getElementById:node,
    querySelector(selector){
      if(selector==='#workspaceSwitch [data-workspace-mode="training"].active')return activeMode==='training'?train:null;
      return null;
    },
    querySelectorAll(selector){return selector==='#workspaceSwitch [data-workspace-mode]'?[train,admin]:[];},
    addEventListener(event,fn){listeners[event]=fn;}
  };
  const window={
    addEventListener(event,fn){listeners['window:'+event]=fn;},
    dispatchEvent(event){states.push(event.detail);},
    ...(supportsSpeech?{SpeechRecognition:FakeSpeech}:{})
  };
  const context={window,document,CustomEvent:class{constructor(name,args){this.type=name;this.detail=args.detail;}},
    setTimeout(fn){timeoutQueue.push(fn);return 1;},clearTimeout(){},console};
  vm.runInNewContext(script,context,{filename:'training-workspace-voice.js'});
  return {window,document,node,instances,submissions,states,listeners,
    setMode:value=>{activeMode=value;},setHidden:value=>{hidden=value;},lastStatus:()=>node('trainingVoiceStatus').dataset.state};
}
{
  const b=testBrowser(true),voice=b.window.GelatoTrainingVoice;
  assert.ok(voice&&voice.supported,'Training voice controller should initialize');
  assert.equal(voice.mode,'off','must not start microphone on page load');
  b.node('trainingVoiceOnce').click();
  assert.equal(voice.mode,'once');
  const first=b.instances.at(-1);
  first.onstart();
  assert.equal(b.lastStatus(),'listening');
  first.hear('$13');
  assert.deepEqual(b.submissions,['$13'],'one dictated answer must submit to local Training chat');
  assert.equal(voice.mode,'off','one-answer dictation must stop after submission');
  b.node('trainingVoiceAlways').click();
  assert.equal(voice.mode,'always');
  const continuous=b.instances.at(-1);
  continuous.onstart();
  assert.equal(continuous.continuous,true);
  continuous.hear('hint');continuous.hear('skip');
  assert.deepEqual(b.submissions,['$13','hint','skip'],'hands-free mode must use the same quiz grader repeatedly');
  b.setHidden(true);b.listeners.visibilitychange();
  assert.equal(voice.active,false,'hidden KDS/Training tab must release microphone');
  assert.equal(b.lastStatus(),'paused');
  b.setHidden(false);b.listeners.visibilitychange();
  assert.equal(b.instances.length,3,'visible Training page may resume user-enabled voice');
  b.instances.at(-1).onstart();
  assert.equal(voice.active,true);
  b.setMode('admin');b.node('adminSwitch').click();
  assert.equal(voice.mode,'off','switching to Admin must stop Training microphone');
  b.setMode('training');b.node('trainingVoiceOnce').click();
  const denied=b.instances.at(-1);
  denied.onerror({error:'not-allowed'});
  assert.equal(voice.mode,'off');
  assert.equal(b.lastStatus(),'blocked','microphone-denied state visible');
  assert.ok(!script.includes('GelatoGlobalAgent.send'),'no global Agent request/overlay');
}
{
  const b=testBrowser(false);
  assert.equal(b.node('trainingVoiceOnce').disabled,true,'unsupported browsers cannot show misleading active mic');
  assert.equal(b.node('trainingVoiceAlways').disabled,true);
  assert.equal(b.lastStatus(),'unsupported');
}
console.log('training-workspace-voice=ok');
