/* Gelato staff quicklist. Canonical recipes/menu/stock are read-only sources. */
(function(){
'use strict';
var state={tab:'prep',data:null,busy:false,loadedAt:Date.now(),search:''};
var $=function(id){return document.getElementById(id);};
var html=function(value){return String(value==null?'':value).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});};
var today=function(){var d=new Date();return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');};
$('work-date').value=today();
function notify(message,error){var n=$('notice');n.textContent=message;n.hidden=false;n.classList.toggle('error',!!error);clearTimeout(notify.timeout);notify.timeout=setTimeout(function(){n.hidden=true;},6500);}
function statusLabel(s){return ({todo:'To do',in_progress:'In progress',paused:'Paused',done:'Done',need_more:'Need more',ordered:'Ordered',received:'Received'})[s]||s;}
function timerDisplay(s){s=Math.max(0,Number(s)||0);var hrs=Math.floor(s/3600);var min=Math.floor((s%3600)/60);var sec=Math.floor(s%60);return (hrs?String(hrs).padStart(2,'0')+':':'')+String(min).padStart(2,'0')+':'+String(sec).padStart(2,'0');}
function textOfPart(v){if(typeof v==='string')return v;if(!v||typeof v!=='object')return String(v||'');return [v.quantity,v.unit,v.ingredient||v.name||v.item||v.text||v.description,v.notes].filter(function(x){return x!==null&&x!==undefined&&String(x).trim()!=='';}).join(' ');}
function stepOfPart(v){if(typeof v==='string')return v;if(!v||typeof v!=='object')return String(v||'');return String(v.instruction||v.text||v.description||v.step||v.content||'');}
function recipeHtml(recipe){
 if(!recipe)return '<p class="recipe-missing">Linked recipe is unavailable or you do not have Recipe Library access.</p>';
 var ing=Array.isArray(recipe.ingredients)?recipe.ingredients:[];
 var steps=Array.isArray(recipe.instructions)?recipe.instructions:[];
 var yieldText=recipe.yieldQuantity!=null?'<div class="yield">YIELD · '+html(recipe.yieldQuantity)+' '+html(recipe.yieldUnit||'')+'</div>':'';
 return '<div class="recipe-sheet"><h4>'+html(recipe.name)+'</h4>'+yieldText+
 (recipe.description?'<p>'+html(recipe.description)+'</p>':'')+
 '<div class="recipe-columns"><div><h5>Ingredients</h5>'+
 (ing.length?'<ul>'+ing.map(function(x){return '<li>'+html(textOfPart(x))+'</li>';}).join('')+'</ul>':'<p>No ingredients recorded.</p>')+
 '</div><div><h5>Method</h5>'+
 (steps.length?'<ol>'+steps.map(function(x){return '<li>'+html(stepOfPart(x))+'</li>';}).join('')+'</ol>':'<p>No instructions recorded.</p>')+
 '</div></div>'+(recipe.notes?'<p><strong>Recipe notes:</strong> '+html(recipe.notes)+'</p>':'')+'</div>';
}
function recipeDetails(item){
 if(!item.recipeId)return '';
 var recipe=(state.data.recipes||[]).find(function(r){return r.id===item.recipeId;});
 return '<details class="recipe-details"><summary>📖 Recipe · '+html(recipe?recipe.name:'View linked recipe')+'</summary>'+recipeHtml(recipe)+'</details>';
}
function managerControls(item){
 if(!state.data.manager)return '';
 var statuses=item.type==='prep'?['todo','in_progress','paused','done']:['need_more','ordered','received'];
 return '<div class="manager-bar"><span class="muted">ADMIN</span><select class="manager-status" aria-label="Admin status">'+statuses.map(function(s){return '<option value="'+s+'"'+(item.status===s?' selected':'')+'>'+statusLabel(s)+'</option>';}).join('')+'</select>'+
 '<input class="manager-note" aria-label="Admin note" placeholder="Internal status note…" maxlength="2000" value="'+html(item.adminNote)+'">'+
 '<button class="small-btn" type="button" data-action="manager-save">Save status</button>'+
 '<button class="small-btn" type="button" data-action="history">History</button>'+
 '<button class="small-btn" type="button" data-action="notify">Notify</button></div><div class="history-slot"></div>';
}
function renderPrep(){
 var list=(state.data.items||[]).filter(function(i){return i.type==='prep';});
 var finished=list.filter(function(i){return i.status==='done';}).length;
 var pct=list.length?Math.round(finished/list.length*100):0;
 $('prep-tab-count').textContent=list.length;$('prep-percent').textContent=pct+'%';$('prep-progress').style.width=pct+'%';
 $('prep-list').innerHTML=list.length?list.map(function(item){
  var stopped=item.status==='done';
  var timer=Number(item.elapsedSeconds)||0;
  var controls=stopped?'':(item.timerRunning?'<button class="small-btn" data-action="timer" data-mode="pause">Pause</button>':
   '<button class="small-btn" data-action="timer" data-mode="'+(timer>0?'resume':'start')+'">'+(timer>0?'Resume':'Start')+'</button>')+
   (!stopped&&(item.timerRunning||timer>0)?'<button class="small-btn" data-action="timer" data-mode="stop">Stop</button>':'');
  return '<article class="task '+(stopped?'done':'')+'" data-id="'+html(item.id)+'"><div class="task-main">'+
   '<input class="check" type="checkbox" data-action="toggle" aria-label="Mark '+html(item.title)+' complete" '+(stopped?'checked':'')+'>'+
   '<div class="task-body"><div class="task-title">'+html(item.title)+'</div><div class="task-sub"><span class="status-tag '+html(item.status)+'">'+statusLabel(item.status)+'</span>'+ (item.recipeId?'<span>📖 Saved recipe</span>':'')+(item.adminNote?'<span>Manager note: '+html(item.adminNote)+'</span>':'')+'</div></div>'+
   '<div class="task-tools"><span class="time-pill '+(item.timerRunning?'running':'')+'" data-elapsed="'+timer+'" data-running="'+(item.timerRunning?'1':'0')+'">'+timerDisplay(timer)+'</span>'+
   controls+'<button class="small-btn danger" data-action="archive" aria-label="Remove '+html(item.title)+'">Remove</button></div></div>'+
   recipeDetails(item)+managerControls(item)+'</article>';
 }).join(''):'<div class="empty">Your prep list is ready to build. Add your own item or use the menu suggestions below.</div>';
 var existing=new Set(list.map(function(x){return x.title.toLowerCase();}));
 var suggestions=(state.data.suggestions||[]).filter(function(s){return !existing.has(s.title.toLowerCase());}).slice(0,35);
 $('suggestions').innerHTML=suggestions.length?suggestions.map(function(s){
  return '<button class="chip" data-suggest="'+html(s.title)+'" data-recipe="'+html(s.recipeId||'')+'"><span>+</span><span>'+html(s.title)+' <small>· '+html(s.source)+'</small></span>'+(s.recipeId?'<span class="recipe-indicator">📖</span>':'')+'</button>';
 }).join(''):'<span class="muted">No unused published-menu suggestions. You can always add a custom prep task.</span>';
 $('seed-prep').disabled=!suggestions.length;
 var recipeSelect=$('prep-recipe'),current=recipeSelect.value;recipeSelect.innerHTML='<option value="">No linked recipe</option>'+
 (state.data.recipes||[]).map(function(r){return '<option value="'+html(r.id)+'">'+html(r.name)+'</option>';}).join('');
 recipeSelect.value=current||'';recipeSelect.hidden=!state.data.canRecipe;
}
function renderInventory(){
 var items=(state.data.items||[]).filter(function(i){return i.type==='inventory';});
 var outstanding=items.filter(function(i){return i.status!=='received';}).length;
 $('need-count').textContent=outstanding;$('inventory-tab-count').textContent=outstanding;
 $('need-caption').textContent=items.length+' listed · '+outstanding+' outstanding';
 $('need-list').innerHTML=items.length?items.map(function(item){
  return '<article class="task" data-id="'+html(item.id)+'"><div class="task-main"><span class="status-tag '+html(item.status)+'">'+statusLabel(item.status)+'</span><div class="task-body"><div class="task-title">'+html(item.title)+'</div><div class="task-sub">'+(item.quantityNote?'<span>Requested: '+html(item.quantityNote)+'</span>':'')+(item.adminNote?'<span>Manager note: '+html(item.adminNote)+'</span>':'')+'</div></div><button class="small-btn danger" data-action="archive">Remove</button></div>'+
  managerControls(item)+'</article>';
 }).join(''):'<div class="empty">Nothing in Need More. Tap an inventory item or add something above.</div>';
 var stock=(state.data.inventory||[]).filter(function(x){return !state.search||x.name.toLowerCase().includes(state.search)||x.category.toLowerCase().includes(state.search);});
 $('stock-list').innerHTML=!state.data.canInventory?'<div class="empty">Stock directory requires Inventory View permission. You can still add a Need More request above.</div>':
 stock.length?stock.slice(0,150).map(function(x){
  var low=x.reorderPoint!=null&&x.onHand<=x.reorderPoint;
  var pending=items.some(function(i){return i.inventoryId===x.id&&i.status!=='received';});
  return '<div class="stock-row"><div class="stock-name">'+html(x.name)+'<div class="stock-meta '+(low?'stock-low':'')+'">'+html(x.category)+' · On hand '+html(x.onHand)+' '+html(x.unit)+(low?' · LOW STOCK':'')+'</div></div><button class="small-btn" '+(pending?'disabled':'')+' data-stock-id="'+html(x.id)+'" data-stock-name="'+html(x.name)+'">'+(pending?'On list':'+ Need More')+'</button></div>';
 }).join(''):'<div class="empty">No matching stock items.</div>';
}
function render(){if(!state.data)return;renderPrep();renderInventory();}
function setTab(name){state.tab=name;document.querySelectorAll('.tab').forEach(function(b){b.classList.toggle('active',b.dataset.tab===name);});$('prep-pane').hidden=name!=='prep';$('inventory-pane').hidden=name!=='inventory';$('work-date').disabled=name==='inventory';}
async function json(url,options){var r=await fetch(url,Object.assign({credentials:'same-origin',headers:{'Accept':'application/json'}},options||{}));var data=await r.json();if(!r.ok||!data.ok)throw Error(data.message||'Please try again.');return data;}
async function load(){var date=$('work-date').value;var data=await json('api/daily-prep.php?action=bootstrap&date='+encodeURIComponent(date));state.data=data;state.loadedAt=Date.now();render();}
async function act(action,body){if(state.busy)return;state.busy=true;document.body.classList.add('busy');try{
  var response=await json('api/daily-prep.php',{method:'POST',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-Token':window.DAILY_PREP_CSRF},body:JSON.stringify(Object.assign({action:action},body||{}))});
  await load();if(action==='notify')notify('Notification sent to '+(response.recipients||0)+' other manager(s).');
 }catch(e){notify(e.message,true);}finally{state.busy=false;document.body.classList.remove('busy');}}
function updateTimers(){if(!state.data)return;var diff=Math.floor((Date.now()-state.loadedAt)/1000);document.querySelectorAll('[data-elapsed]').forEach(function(el){var seconds=Number(el.dataset.elapsed)||0;if(el.dataset.running==='1')seconds+=diff;el.textContent=timerDisplay(seconds);});}
document.querySelectorAll('.tab').forEach(function(b){b.addEventListener('click',function(){setTab(b.dataset.tab);});});
$('work-date').addEventListener('change',function(){load().catch(function(e){notify(e.message,true);});});
$('stock-search').addEventListener('input',function(e){state.search=e.target.value.toLowerCase().trim();if(state.data)renderInventory();});
$('prep-add').addEventListener('submit',function(e){e.preventDefault();var title=$('prep-title').value.trim(),recipeId=$('prep-recipe').value;if(!title)return;act('add',{type:'prep',date:$('work-date').value,title:title,recipeId:recipeId}).then(function(){$('prep-title').value='';$('prep-recipe').value='';});});
$('inventory-add').addEventListener('submit',function(e){e.preventDefault();var title=$('inventory-title').value.trim();if(!title)return;act('add',{type:'inventory',title:title,quantityNote:$('inventory-qty').value.trim()}).then(function(){$('inventory-title').value='';$('inventory-qty').value='';});});
$('seed-prep').addEventListener('click',function(){if(!confirm('Add the first 12 suggested prep items for '+$('work-date').value+'?'))return;act('seed',{date:$('work-date').value});});
document.addEventListener('change',function(e){var target=e.target;if(target.matches('[data-action="toggle"]')){var card=target.closest('[data-id]');act('toggle',{id:card.dataset.id});}});
document.addEventListener('click',function(e){
 var suggest=e.target.closest('[data-suggest]');if(suggest){act('add',{type:'prep',title:suggest.dataset.suggest,date:$('work-date').value,recipeId:suggest.dataset.recipe});return;}
 var stock=e.target.closest('[data-stock-id]');if(stock){act('add',{type:'inventory',title:stock.dataset.stockName,inventoryId:stock.dataset.stockId});return;}
 var button=e.target.closest('[data-action]');if(!button||button.matches('input'))return;
 var card=button.closest('[data-id]');if(!card)return;var id=card.dataset.id,action=button.dataset.action;
 if(action==='archive'){if(confirm('Remove this item from the shared list? You can review its history as a manager.'))act('archive',{id:id});}
 if(action==='timer')act('timer',{id:id,mode:button.dataset.mode});
 if(action==='manager-save')act('update',{id:id,status:card.querySelector('.manager-status').value,adminNote:card.querySelector('.manager-note').value});
 if(action==='notify')act('notify',{id:id});
 if(action==='history'){json('api/daily-prep.php?action=history&id='+encodeURIComponent(id)).then(function(d){var slot=card.querySelector('.history-slot');slot.className='history';slot.innerHTML=d.events.length?d.events.map(function(ev){return '<div>'+html(ev.when)+' · <strong>'+html(ev.event)+'</strong> · '+html(ev.by)+'</div>';}).join(''):'No changes logged.';}).catch(function(err){notify(err.message,true);});}
});
setInterval(updateTimers,1000);
setTab('prep');
load().catch(function(e){$('prep-list').innerHTML='<div class="empty">'+html(e.message)+'</div>';notify(e.message,true);});
})();
