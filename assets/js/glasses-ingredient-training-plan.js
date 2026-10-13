(()=>{'use strict';
/* Deterministic guidance only. It never labels an unconfigured ingredient. */
const PHASES=[
 {id:'isolated',name:'Ingredient close-up',hint:'Photograph the ingredient alone before it is added. Mark its visible area.',positive:true},
 {id:'empty_base',name:'Empty base',hint:'Show the base without this ingredient. Negative example; no bounding box.',positive:false},
 {id:'before_add',name:'Before placement',hint:'Show the item immediately before the ingredient is added. No box.',positive:false},
 {id:'after_add',name:'After placement',hint:'Show the ingredient placed on the item. Mark its visible area.',positive:true},
 {id:'mixed',name:'Mixed ingredients',hint:'Capture it with other toppings nearby. Mark only this ingredient.',positive:true},
 {id:'occluded',name:'Partly hidden',hint:'Use a safe view with partial occlusion. Mark only visible ingredient area.',positive:true},
 {id:'negative',name:'Look-alike / absent',hint:'Capture a confusing alternative without the selected ingredient. No box.',positive:false}
];
const P=Object.fromEntries(PHASES.map(x=>[x.id,x]));
function choice(catalog,menuId,key,phase){
 const menu=(catalog?.menuItems||[]).find(x=>x.id===Number(menuId));
 const ingredient=menu?.ingredients?.find(x=>x.key===String(key));
 const guide=P[String(phase||'')];
 return menu&&ingredient&&guide?{menu,ingredient,guide}:null;
}
function validate(data){
 if(!data?.runtimePublicId||!data?.sessionPublicId)return 'Start an assigned employee training session first.';
 const picked=choice(data.catalog,data.menuItemId,data.ingredientKey,data.phase);
 if(!picked)return 'Select a configured menu ingredient and capture phase.';
 if(!data.blob||data.blob.size===0)return 'Select a camera capture or an image file.';
 if(!Number.isInteger(Number(data.cameraSlot))||Number(data.cameraSlot)<1||Number(data.cameraSlot)>5)return 'Select camera slot 1–5.';
 if(picked.guide.positive){
  const b=data.bbox;if(!b||![b.x,b.y,b.width,b.height].every(Number.isFinite)||
    b.width<=.03||b.height<=.03||b.x<0||b.y<0||b.x+b.width>1.00001||b.y+b.height>1.00001)
   return 'Draw a valid bounding box around the configured ingredient.';
 }else if(data.bbox)return 'This negative example must not contain a positive ingredient box.';
 if(data.consent!==true)return 'Explicitly approve private Vision Lab storage and human review.';
 return '';
}
window.GelatoIngredientTrainingPlan={PHASES,choice,validate};
})();
