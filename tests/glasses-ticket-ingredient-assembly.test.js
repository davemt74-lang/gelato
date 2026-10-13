'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const asm=fs.readFileSync('assets/js/glasses-item-assembly.js','utf8');
const main=fs.readFileSync('assets/js/glasses-web-simulator.js','utf8');
const voice=fs.readFileSync('assets/js/glasses-simulator-voice.js','utf8');
const html=fs.readFileSync('glasses-simulator.php','utf8');
const test={window:{},Map,Set,Array,Number,String,Math};vm.runInNewContext(asm,test);
const model=test.window.GelatoGlassesItemAssembly;
const item=(name,ingredients,ticket='t1',id='k1',status='queued')=>({
 kdsItemPublicId:id,status,ticket:{checkPublicId:ticket,checkNumber:'PRIVATE-FULL-POS-001'},
 posLine:{name,modifiers:[],specialInstructions:''},
 menu:{sectionName:name.includes('Pizza')?'Pizza':'Appetizers',
   ingredients:ingredients.map((name,i)=>({name,canonicalName:name.toLowerCase(),
    category:/sauce|marinara|pesto/i.test(name)?'sauce':/cheese|mozzarella/i.test(name)?'cheese':'topping'}))}
});
const pizza=item('BBQ Chicken Pizza',['Chicken','Mozzarella','BBQ Sauce','Red Onion','Cilantro']);
const names=model.ingredientsFor(pizza).map(x=>x.name);
assert.deepEqual([...names],['BBQ Sauce','Mozzarella','Chicken','Red Onion','Cilantro'],
 'pizzas must show configured assembly ingredients: sauce, cheese, then toppings');
const meatballs=item('Roasted Meatballs',['Meatballs','Marinara','Mozzarella','Parmesan','Oregano']);
assert.deepEqual([...model.ingredientsFor(meatballs).map(x=>x.name)],
 ['Meatballs','Marinara','Mozzarella','Parmesan','Oregano'],
 'pre-purchased meatballs and purchased/scratch sauces are shown as assembly ingredients, not prep recipes');
const noMenu={...pizza,menu:{ingredients:[]},recipeSource:{recipe:{instructions:['Make Sauce','Cook Chicken']}}};
assert.deepEqual([...model.ingredientsFor(noMenu)],[],'NEVER synthesize ingredient entries from recipe instructions');
assert.equal(model.counts(noMenu,{}).missingConfiguration,true);
assert.equal(model.counts(noMenu,{}).complete,false,'missing ingredient setup must not auto-complete item');
assert.ok(!asm.includes('recipeSource'), 'ingredient model never depends on recipe source');
const mods={...pizza,posLine:{...pizza.posLine,specialInstructions:'NO ONION',modifiers:[{name:'Extra BBQ Sauce'}]}};
assert.deepEqual([...model.ingredientsFor(mods).map(x=>x.name)],names,
 'POS notes and modifiers must not silently rewrite a configured ingredient list');
const sauce=model.ingredientsFor(pizza)[0],cheese=model.ingredientsFor(pizza)[1];
const acts={[sauce.id]:'added',[cheese.id]:'skipped'};
assert.equal(model.nextUnaccounted(pizza,acts).name,'Chicken');
assert.equal(model.counts(pizza,acts).accounted,2);
assert.equal(model.counts(pizza,acts).complete,false);
for(const i of model.ingredientsFor(pizza))acts[i.id]=i.id===cheese.id?'skipped':'added';
assert.equal(model.counts(pizza,acts).complete,true);
assert.equal(model.nextUnaccounted(pizza,acts),null);
const next=item('Roasted Meatballs',['Meatballs'],'t1','k2');
const other=item('Cheese Pizza',['Marinara','Mozzarella'],'t2','k3');
const groups=model.ticketGroups([pizza,next,other],8);
assert.equal(groups.length,2,'only occupied tickets become tabs');
assert.equal(groups[0].items.length,2,'ticket includes multiple KDS items');
assert.equal(model.nextItemInTicket(groups[0],'k1',id=>id==='k1'?acts:{}).kdsItemPublicId,'k2');
assert.equal(model.nextItemInTicket(groups[0],'k2',id=>id==='k1'?acts:{}),null,
 'last unaccounted item does not jump to another ticket');
const complete=model.ticketGroups([...Array.from({length:12},(_,i)=>item('Pizza'+i,['Sauce'],'t'+i,'k'+i))],8);
assert.equal(complete.length,8,'at most eight actual occupied KDS tickets');
assert.equal(model.ticketGroups([item('Cancelled',['A'],'t1','x','cancelled')]).length,0);
assert.match(main,/function acknowledgeIngredient\(result,options=\{\}\)/,'operator acknowledgement includes voice origin for spoken duplicate suppression');
assert.match(main,/actions\[ingredient\.id\]=result/);
assert.match(main,/nextItemInTicket\(activeTicket\(\),item\.kdsItemPublicId/);
assert.match(main,/official KDS readiness separately/);
assert.match(main,/No assembly ingredients configured/);
assert.match(main,/data-kds-ticket-item/);
assert.match(main,/role="tab"/);
assert.match(main,/class="hud-ticket-tabs"/);
assert.match(html,/assets\/js\/glasses-item-assembly\.js/);
assert.doesNotMatch(html,/id="hudIngredientAdded"|id="hudIngredientSkip"/,'no duplicated large Added/Skip lens buttons');
assert.match(main,/data-hud-ingredient-action="'+kind/,'small per-ingredient Added/Skip icons are rendered by ingredient row');
assert.match(main,/data-hud-ingredient-index="'+index/,'each icon is bound to its own ingredient index');
assert.match(main,/options\\.ingredientId/,'row actions address the selected ingredient, not just the next row');
assert.match(main,/operator_row_icon/,'per-row operator acknowledgements retain source');
assert.match(voice,/return 'ingredient_added'/);
assert.match(voice,/return 'skip_ingredient'/);
console.log('section265-ticket-items-ingredient-assembly=ok');
