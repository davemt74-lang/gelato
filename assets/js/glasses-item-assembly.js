(()=>{'use strict';
/* Kitchen assembly is NOT preparation recipes. One menu item's configured
   ingredient list drives this view. No menu data => no implied ingredients. */
function ingredientsFor(item){
  const configured=Array.isArray(item?.menu?.ingredients)?item.menu.ingredients:[];
  const pizza=/pizza|flatbread/i.test(String(item?.menu?.sectionName||'')+' '+String(item?.posLine?.name||''));
  const ingredients=configured.map((row,i)=>({
    id:String(i)+':'+String(row?.canonicalName||row?.name||''),
    name:String(row?.name||'').trim(),
    optional:!!row?.optional,category:String(row?.category||'').toLowerCase(),
    position:i
  })).filter(r=>r.name);
  const priority=(row)=>{
    if(!pizza)return row.position;
    // Ingredients remain admin-configured; categorization changes ordering only.
    if(/sauce|marinara|pesto|oil/.test(row.category+' '+row.name.toLowerCase()))return -200;
    if(/cheese|mozzarella|provolone|parmesan|ricotta/.test(row.category+' '+row.name.toLowerCase()))return -100;
    return row.position;
  };
  ingredients.sort((a,b)=>priority(a)-priority(b)||a.position-b.position);
  return ingredients;
}
function ticketKey(item){return String(item?.ticket?.checkPublicId||item?.kdsItemPublicId||'');}
function ticketGroups(items,max=8){
  const groups=new Map();
  for(const item of items||[]){
    if(!item||['completed','cancelled'].includes(item.status))continue;
    const key=ticketKey(item);if(!key)continue;
    if(!groups.has(key)){if(groups.size>=max)continue;groups.set(key,{key,items:[]});}
    groups.get(key).items.push(item);
  }
  return [...groups.values()];
}
function counts(item,actions){
  const list=ingredientsFor(item);
  const resolved=list.filter(ingredient=>actions?.[ingredient.id]==='added'||actions?.[ingredient.id]==='skipped');
  return {total:list.length,accounted:resolved.length,complete:list.length>0&&resolved.length===list.length,
    missingConfiguration:list.length===0};
}
function nextUnaccounted(item,actions){
  return ingredientsFor(item).find(i=>!['added','skipped'].includes(actions?.[i.id]))||null;
}
function nextItemInTicket(group,currentId,getActions){
  if(!group?.items?.length)return null;
  return group.items.find(item=>item.kdsItemPublicId!==currentId&&
    !['ready','completed','cancelled'].includes(item.status)&&
    !counts(item,getActions(item.kdsItemPublicId)).complete)||null;
}
window.GelatoGlassesItemAssembly={ingredientsFor,ticketKey,ticketGroups,counts,nextUnaccounted,nextItemInTicket};
})();