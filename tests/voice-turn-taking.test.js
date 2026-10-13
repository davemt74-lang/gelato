'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync('js/voice-turn-taking.js','utf8');
const spoken=[],events=[],saved=new Map();
class Utterance{constructor(text){this.text=text;this.rate=1;this.voice=null;}}
const synthesis={
  voices:[{voiceURI:'eng-one',name:'English 1',lang:'en-US'},{voiceURI:'fr-one',name:'French',lang:'fr-FR'}],
  getVoices(){return this.voices;},
  cancel(){this.canceled=(this.canceled||0)+1;},
  speak(u){spoken.push(u);}
};
const win={speechSynthesis:synthesis,SpeechSynthesisUtterance:Utterance,dispatchEvent(e){events.push(e);}};
const ctx={window:win,localStorage:{getItem:k=>saved.get(k),setItem:(k,v)=>saved.set(k,v)},
  CustomEvent:class{constructor(type,args){this.type=type;this.detail=args.detail;}},Date,console};
vm.runInNewContext(source,ctx,{filename:'voice-turn-taking.js'});
const v=win.GelatoVoiceTurns;
assert.ok(v);
assert.equal(v.speaking,false);
assert.equal(v.environment,'standard');
assert.equal(v.voices.length,1,'filter speaker list to English voices');
assert.equal(v.setEnvironment('noisy'),true);
assert.equal(v.environment,'noisy');
assert.equal(v.setEnvironment('wrong'),false);
assert.match(saved.get('gelato.voice.device.v1'),/noisy/);
assert.equal(v.setVoice('eng-one'),true);
assert.equal(v.setRate(8),1.25,'rate bounded');
assert.equal(v.speak('Order 12, ticket 9012. Add bacon.',{owner:'kds'}),true);
assert.equal(v.speaking,true);
assert.equal(v.owner,'kds');
assert.equal(spoken.at(-1).voice.voiceURI,'eng-one');
assert.equal(spoken.at(-1).rate,1.25);
assert.equal(v.evaluate('Order 12 ticket 9012').reason,'tts-echo','agent speech must not loop back as a command');
assert.equal(v.evaluate('someone on television').reason,'wake-required-while-speaking','background conversation cannot barge in');
assert.equal(v.evaluate('hey gelato read order 17').kind,'command');
assert.equal(v.evaluate('hey gelato read order 17').interrupt,true,'explicit wake prefix may barge in');
assert.equal(v.evaluate('hey gelato stop').kind,'interrupt');
assert.equal(v.evaluate('stop speaking').kind,'interrupt','simple stop speaking can barge in while speech active');
let done=null;
v.interrupt('voice');
assert.equal(v.speaking,false);
assert.equal(events.at(-1).detail.owner,'kds','interrupted event retains speaking owner');
assert.equal(events.at(-1).detail.source,'voice');
assert.equal(v.evaluate('Order 12 ticket 9012').reason,'post-tts-echo','post speech echo blocked briefly');
assert.equal(v.evaluate('read order 17').reason,'wake-required','noisy profile requires wake phrase');
assert.equal(v.evaluate('hey gelato read order 17').kind,'command');
assert.equal(v.setEnvironment('quiet'),true);
assert.equal(v.evaluate('read order 17').kind,'command','quiet profile allows normal commands');
assert.equal(v.evaluate('nonsense background',0.12).reason,'low-confidence');
v.speak('Next item is mozzarella.',{owner:'training',onEnd:x=>done=x});
const stale=spoken.at(-1);
v.interrupt('button');
assert.deepEqual(JSON.parse(JSON.stringify(done)),{interrupted:true,source:'button'});
stale.onend?.();
assert.equal(v.speaking,false,'late TTS callback after cancel must not restart playback');
let ended=null;
v.speak('One step.',{owner:'global-agent',onEnd:x=>ended=x});
spoken.at(-1).onend?.();
assert.equal(v.speaking,false);
assert.deepEqual(JSON.parse(JSON.stringify(ended)),{interrupted:false});
assert.ok(events.some(x=>x.detail.kind==='speaking'));
console.log('shared-voice-turn-taking=ok');
