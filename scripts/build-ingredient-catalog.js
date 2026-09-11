/* Run with: node scripts/build-ingredient-catalog.js */
const fs = require('fs');
const path = require('path');
global.window = {};
const root = path.resolve(__dirname, '..');
require(path.join(root, 'data/menu-knowledge.js'));
require(path.join(root, 'data/allergen-rules.js'));
const sections = window.MENU_SECTIONS;
const kb = window.ALLERGEN_KNOWLEDGE;
const aliases = new Map(Object.entries({
  'mozzarella cheese':'mozzarella','pure mozzarella cheese':'mozzarella','melted mozzarella':'mozzarella',
  'aged provolone':'provolone','provolone cheese':'provolone','melted provolone':'provolone',
  'extra sharp cheddar':'cheddar','sharp cheddar cheese':'cheddar','melted cheddar cheese':'cheddar','extra cheddar':'cheddar','cheddar cheese':'cheddar',
  'swiss':'swiss cheese','grated parmesan':'parmesan','parmesan cheese':'parmesan',
  'fresh mushrooms':'mushrooms','fresh mushroom':'mushrooms','mushroom':'mushrooms',
  'fresh onions':'onions','fresh onion':'onions','onion':'onions','sweet onion':'sweet onions',
  'green pepper':'green peppers','black olive':'black olives','tomato':'tomatoes','diced tomatoes':'tomatoes','vine ripened tomatoes':'tomatoes','vine-ripened tomatoes':'tomatoes',
  'fresh broccoli':'broccoli','jalapeños':'jalapenos','fresh jalapeños':'jalapenos','fresh jalapenos':'jalapenos','breaded jalapeños':'jalapeno poppers',
  'real bacon bits':'bacon bits','crispy bacon':'bacon','white meat chicken':'chicken','grilled chicken':'chicken','lean ham':'ham','tender sliced turkey':'turkey',
  'thinly sliced roast beef':'roast beef','tender, lean roast beef':'roast beef','razor thin sliced extra lean roast beef':'roast beef',
  'house-made ranch':'ranch','house made ranch':'ranch','2 oz house-made ranch':'ranch','2 oz house made ranch':'ranch','wings ranch':'ranch',
  '2 oz house-made pizza sauce':'pizza sauce','2 oz house made pizza sauce':'pizza sauce','side of sauce':'pizza sauce',
  'medium buffalo':'medium buffalo sauce','hot buffalo':'hot buffalo sauce','honey bbq':'honey bbq sauce',
  '4 wings':'wings','3 jalapeno poppers':'jalapeno poppers','3 mozzarella sticks':'mozzarella sticks','2 potato skins':'potato skins',
  'hot chocolate chip cookie':'chocolate chip cookie','fresh flour tortilla':'flour tortilla','italian bun':'italian bun','fresh baked roll':'italian roll',
  'romaine lettuce':'romaine','crispy romaine lettuce':'romaine','fresh crisp lettuce':'lettuce','crispy fresh lettuce':'lettuce',
  'assorted seasonal fresh vegetables':'seasonal vegetables','four kinds of cheese':'four-cheese blend','12-inch gluten-free crust':'gluten-free crust','fresh-baked roll':'italian roll','white-meat chicken':'chicken','skillet':''
}));
const unique = a => [...new Set(a.filter(Boolean))];
function normalize(raw){
  let v=String(raw||'').trim().replace(/[.;]+$/,'').replace(/\s+/g,' ');
  let lower=v.toLowerCase();
  if(aliases.has(lower)) return String(aliases.get(lower)).toLowerCase();
  v=v.replace(/^\d+\s+(?:oz\.?\s+)?/i,'');
  lower=v.toLowerCase();
  return String(aliases.has(lower)?aliases.get(lower):lower).toLowerCase();
}
function split(raw){return /^ranch or pizza sauce$/i.test(String(raw).trim())?['ranch','pizza sauce']:[raw];}
function ingredients(item, sectionId){
  const raw=[...(item.ingredients||[]),...(item.optionList||[])];
  if(sectionId==='sandwiches'){
    raw.push('chips');
    if(!['Chicken Caesar Wrap','French Connection'].includes(item.name)) raw.push('Italian bun');
  }
  if(sectionId==='salads' && ['Chef Salad',"Fatty's Jumbo Garden Salad","Roy's Side Salad"].includes(item.name)) raw.push('ranch','Italian dressing');
  if(sectionId==='salads' && item.name==='Chef Salad') raw.push('chicken on request','tuna on request');
  return unique(raw.flatMap(split).map(normalize));
}
function profile(name){
  const text=name.toLowerCase(); const direct=[]; const verify=[];
  for(const rule of kb.directRules) if(rule.patterns.some(p=>text.includes(p.toLowerCase()))) direct.push(rule.allergen);
  for(const rule of kb.verifyRules) if(rule.patterns.some(p=>text.includes(p.toLowerCase()))) verify.push(rule.allergen);
  return {direct:unique(direct),verify:unique(verify).filter(x=>!direct.includes(x))};
}
const map=new Map();
for(const section of sections){
  for(const item of section.items){
    for(const ingredient of ingredients(item, section.id)){
      if(!map.has(ingredient)) map.set(ingredient,{id:ingredient.replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,''),name:ingredient,categories:new Set(),menuItems:[],allergens:profile(ingredient)});
      const row=map.get(ingredient); row.categories.add(section.id); row.menuItems.push({sectionId:section.id,sectionName:section.name,itemName:item.name});
    }
  }
}
const catalog=[...map.values()].map(x=>({...x,categories:[...x.categories],menuItems:x.menuItems.sort((a,b)=>a.sectionName.localeCompare(b.sectionName)||a.itemName.localeCompare(b.itemName))})).sort((a,b)=>a.name.localeCompare(b.name));
fs.writeFileSync(path.join(root,'data/ingredient-catalog.json'),JSON.stringify({generatedAt:new Date().toISOString(),ingredientCount:catalog.length,ingredients:catalog},null,2)+'\n');
console.log(`Wrote ${catalog.length} ingredients.`);
