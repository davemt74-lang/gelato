(() => {
'use strict';
if(window.GelatoEmployeeClockPopup)return;
const target=document.body;
let panel=null,lastFocus=null,csrf='',people=[],selectedAction='clock_in',setup=false,busy=false;
const employeeEndpoint='api/employee-clock.php';
const $=(q)=>panel?.querySelector(q);

function matchIntent(value){
 const text=String(value||'').trim().replace(/^(?:(?:hey|hi|okay|ok)\s+)?(?:food\s+monster|monster|gelato)[,\s]+/i,'').trim();
 if(/^(?:(?:i|we)\s+(?:want|need|would\s+like)\s+to\s+)?(?:clock|punch)\s+out|(?:i\s+)?(?:want|need)\s+to\s+clock\s+out|end\s+(?:my\s+)?shift)\s*[.!?]*$/i.test(text))return 'clock_out';
 if(/^(?:(?:i|we)\s+(?:want|need|would\s+like)\s+to\s+)?(?:clock|punch)\s+in|(?:i\s+)?(?:want|need)\s+to\s+(?:sign\s+in|log\s*in)(?:\s+for\s+(?:my\s+)?shift)?|(?:sign\s+in|log\s*in)\s+to\s+work|start\s+(?:my\s+)?shift)\s*[.!?]*$/i.test(text))return 'clock_in';
 return null;
}
function status(value,isError=false){const x=$('[data-fm-clock-status]');if(x){x.textContent=value;x.style.color=isError?'#b91c1c':'#334155';}}
function selectEmployee(personId){
 const select=$('select[name="personId"]');if(!select)return;
 select.replaceChildren();
 for(const p of people){const op=document.createElement('option');op.value=String(p.personId);op.textContent=p.displayName+(p.clockedIn?' · on shift':' · off shift')+(!p.pinSet?' · PIN needed':'');select.append(op);}
 const found=people.find(x=>x.personId===personId);
 const preferred=found||people.find(x=>selectedAction==='clock_in'?!x.clockedIn:x.clockedIn)||people[0];
 if(preferred)select.value=String(preferred.personId);
 select.disabled=!people.length;
}
async function request(action,payload={}){
 const isGet=action==='roster';
 const response=await fetch(employeeEndpoint+(isGet?'?action=roster':''),{
  method:isGet?'GET':'POST',cache:'no-store',credentials:'same-origin',
  headers:{Accept:'application/json',...(isGet?{}:{'Content-Type':'application/json','X-CSRF-Token':csrf})},
  ...(isGet?{}:{body:JSON.stringify({...payload,action,csrf_token:csrf})})
 });
 const data=await response.json().catch(()=>({ok:false,message:'The shift clock returned an invalid response.'}));
 if(!response.ok||!data.ok)throw Error(data.message||'Employee shift clock failed.');
 if(data.csrf)csrf=data.csrf;
 return data;
}
function render(){
 if(!panel)return;
 $('h2').textContent=setup?'Set employee shift PIN':selectedAction==='clock_in'?'Clock in for your shift':'Clock out of your shift';
 $('button[data-fm-clock-submit]').textContent=setup?'Save shift PIN':selectedAction==='clock_in'?'Clock in':'Clock out';
 $('button[data-fm-clock-setup]').textContent=setup?'Back to shift clock':'Set or reset shift PIN';
 const admin=$('[data-fm-clock-owner]');admin.hidden=!setup;
 const pin=$('input[name="pin"]');pin.value='';pin.focus();
 if(panel.querySelector('form'))panel.querySelector('form').dataset.mode=setup?'setup':selectedAction;
}
async function loadRoster(personId=0){
 const data=await request('roster');people=data.employees||[];
 selectEmployee(personId);
 if(!people.length)status('No active employees available. Create the employee record in Gelato first.',true);
 return data;
}
function close(){
 if(!panel)return;
 panel.remove();panel=null;csrf='';people=[];setup=false;busy=false;
 lastFocus?.focus?.();lastFocus=null;
}
function build(){
 const style=document.createElement('style');style.id='fm-shift-clock-css';
 style.textContent=`#fm-shift-clock{position:fixed;inset:0;background:rgba(8,21,31,.67);z-index:2147483300;display:grid;place-items:center;padding:18px}
 #fm-shift-clock .fm-clock-card{width:min(420px,100%);border:1px solid #d5dce1;border-radius:20px;padding:24px;background:#fff;color:#152535;box-shadow:0 24px 70px #0005;font:14px/1.5 Inter,system-ui,sans-serif}
 #fm-shift-clock h2{font-size:21px;margin:0 0 8px}#fm-shift-clock p{font-size:12px;color:#526374;margin:0 0 16px}
 #fm-shift-clock label{display:block;font-size:12px;font-weight:700;margin-top:12px}
 #fm-shift-clock select,#fm-shift-clock input{display:block;margin-top:5px;width:100%;border:1px solid #c5cdd4;border-radius:10px;padding:11px;font:inherit;background:#fff;color:#142232}
 #fm-shift-clock .fm-actions{display:flex;gap:8px;margin-top:15px;flex-wrap:wrap}
 #fm-shift-clock button{border:1px solid #c5cdd4;background:#fff;color:#142232;border-radius:10px;padding:10px 14px;cursor:pointer;font:inherit}
 #fm-shift-clock button.primary{background:#14283d;color:#fff;border-color:#14283d}
 #fm-shift-clock button:disabled{opacity:.6;cursor:wait}
 #fm-shift-clock [data-fm-clock-status]{min-height:20px;margin:12px 0 0}
 #fm-shift-clock [hidden]{display:none!important}`;
 if(!document.getElementById('fm-shift-clock-css'))document.head.append(style);
 const overlay=document.createElement('div');overlay.id='fm-shift-clock';
 overlay.setAttribute('role','presentation');
 overlay.innerHTML=`<section class="fm-clock-card" role="dialog" aria-modal="true" aria-labelledby="fm-clock-heading">
 <div style="display:flex;align-items:start;justify-content:space-between;gap:8px">
 <h2 id="fm-clock-heading">Clock in</h2><button type="button" data-fm-clock-close aria-label="Close employee clock">✕</button></div>
 <p>Employees clock in here. This never signs anyone into the Gelato website.</p>
 <form autocomplete="off">
 <label>Employee<select name="personId" required aria-label="Select employee"></select></label>
 <label>Shift PIN<input name="pin" type="password" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="off" required aria-label="Six-digit shift PIN"></label>
 <div data-fm-clock-owner hidden><label>Super Admin password<input name="ownerPassword" type="password" autocomplete="off" aria-label="Super Admin password"></label></div>
 <div class="fm-actions"><button class="primary" type="submit" data-fm-clock-submit>Clock in</button><button type="button" data-fm-clock-setup>Set or reset shift PIN</button></div>
 <p role="status" aria-live="polite" data-fm-clock-status></p>
 </form></section>`;
 overlay.addEventListener('click',e=>{if(e.target===overlay||e.target.closest('[data-fm-clock-close]'))close();});
 overlay.addEventListener('keydown',e=>{
  if(e.key==='Escape'){e.preventDefault();close();return;}
  if(e.key==='Tab'){const focus=Array.from(overlay.querySelectorAll('button:not([disabled]),select:not([disabled]),input:not([disabled]):not([hidden])')).filter(x=>!x.closest('[hidden]'));if(!focus.length)return;const a=focus[0],z=focus[focus.length-1];if(e.shiftKey&&document.activeElement===a){e.preventDefault();z.focus();}else if(!e.shiftKey&&document.activeElement===z){e.preventDefault();a.focus();}}
 });
 overlay.querySelector('[data-fm-clock-setup]').addEventListener('click',()=>{setup=!setup;render();status(setup?'Super Admin authorization is required to create or change a shift PIN.':'');});
 overlay.querySelector('form').addEventListener('submit',async e=>{
  e.preventDefault();if(busy)return;busy=true;
  const form=e.currentTarget,btn=form.querySelector('[data-fm-clock-submit]');
  btn.disabled=true;
  const personId=Number(form.elements.personId.value),pin=form.elements.pin.value,ownerPassword=form.elements.ownerPassword.value;
  try{
    if(!people.some(p=>p.personId===personId))throw Error('Choose an employee.');
    const action=setup?'set_pin':selectedAction;
    const result=await request(action,{personId,pin,...(setup?{ownerPassword}:{})});
    form.elements.pin.value='';form.elements.ownerPassword.value='';
    if(setup){setup=false;status('Shift PIN saved. The employee can now clock in.');await loadRoster(personId);render();}
    else{
      await loadRoster(personId);
      status(result.message||'Shift updated.');
      window.dispatchEvent(new CustomEvent('gelato-employee-clock-changed',{detail:{personId,status:result.result?.status}}));
      await window.GelatoGlobalAgent?.refreshContext?.().catch(()=>{});
      setTimeout(()=>{if(panel===overlay)close();},850);
    }
  }catch(err){status(err.message||'Unable to complete shift clock.',true);}
  finally{busy=false;btn.disabled=false;}
 });
 return overlay;
}
async function open(action='clock_in'){
 selectedAction=action==='clock_out'?'clock_out':'clock_in';
 if(panel){setup=false;render();return;}
 lastFocus=document.activeElement;
 panel=build();document.body.append(panel);render();status('Loading employees…');
 try{await loadRoster();if(people.length)status('Enter your shift PIN to continue.');}
 catch(e){status(e.message,true);}
}
window.GelatoEmployeeClockPopup={open,close,matchIntent};
window.addEventListener('gelato-agent-before-send',event=>{
 const action=matchIntent(event.detail?.text);if(!action)return;
 event.preventDefault();void open(action);
});
document.addEventListener('click',event=>{
 if(event.target.closest('#fm-shift-clock-launch')){event.preventDefault();void open('clock_in');}
});
})();
