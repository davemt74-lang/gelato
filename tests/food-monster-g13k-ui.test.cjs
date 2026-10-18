'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync('js/global-agent.js','utf8');
const start=source.indexOf('  function showTrainingUpdateNotice(notice) {');
const end=source.indexOf('\n  }\n\n  function capabilityMessage()',start);
assert(start>=0&&end>start,'notice is implemented before normal Agent response flow');
const js=source.slice(start,end+4);
let sends=0,speech=0,focus=0;
const nodes=[];
function node(tag) {
  const n={tag,id:'',children:[],attributes:{},style:{},textContent:'',parentNode:null,
    setAttribute(k,v){this.attributes[k]=v;},
    append(...children){for(const child of children){child.parentNode=this;this.children.push(child);}},
    insertBefore(child,before){child.parentNode=this;const ix=this.children.indexOf(before);
      if(ix===-1)this.children.push(child);else this.children.splice(ix,0,child);},
    remove(){if(this.parentNode)this.parentNode.children=this.parentNode.children.filter(x=>x!==this);
      this.parentNode=null;},
    classList:{contains(){return false;}},focus(){focus++}};
  nodes.push(n);return n;
}
const parent=node('main'),bar=node('section'),field=node('textarea');bar.id='gelato-agent-bar';
field.id='gaInput';parent.append(bar);
const document={
  getElementById(id){return nodes.find(n=>n.id===id&&n.parentNode!==null)||null;},
  querySelector(){return null;},
  createElement(tag){return node(tag);}
};
const show=vm.runInNewContext('('+js+')',{document,send(){sends++},speak(){speech++}});
let count=0;
function check(value,description){assert(value,description);count++;}
check(typeof show==='function','notice can be invoked');
show({available:true,type:'not_a_notice',suggestedCommand:'review updated training'});
check(document.getElementById('gaTrainingUpdate')===null,'unknown notice type cannot show UI');
show({available:true,type:'menu_training_change',suggestedCommand:'start updated training'});
check(document.getElementById('gaTrainingUpdate')===null,'server may only suggest safe opt-in review');
show({available:true,type:'menu_training_change',suggestedCommand:'review updated training',
  text:'<script>secret</script>',personId:33});
const chip=document.getElementById('gaTrainingUpdate');
check(!!chip,'valid server notice displays one chip');
check(chip.children.length===3,'notice contains short text, review and dismiss');
const chipCopy=chip.children.map(child=>String(child.textContent||'')).join('|');
check(!chipCopy.includes('secret'),'server-injected content not copied into DOM');
check(!chipCopy.includes('33'),'participant numeric identifier never displayed');
check(sends===0&&speech===0,'UI does not auto-act or speak');
chip.children[1].onclick();
check(field.value==='review updated training'&&focus===1,'review click prepares command for manual submission');
check(document.getElementById('gaTrainingUpdate')===null,'review click dismisses chip');
show({available:true,type:'menu_training_change',suggestedCommand:'review updated training'});
show({available:true,type:'menu_training_change',suggestedCommand:'review updated training'});
check(parent.children.filter(n=>n.id==='gaTrainingUpdate').length===1,'repeated render replaces existing chip');
const last=document.getElementById('gaTrainingUpdate');
last.children[2].onclick();
check(document.getElementById('gaTrainingUpdate')===null,'dismiss button hides notification without writing state');
check(sends===0&&speech===0,'nothing submitted or spoken after click');
show({available:true,type:'menu_training_change',suggestedCommand:'review updated training'});
show(null);
check(document.getElementById('gaTrainingUpdate')===null,'next speaker/new turn resets previous notice');
console.log('FM-G13K nonintrusive update chip PASS ('+count+' assertions)');
