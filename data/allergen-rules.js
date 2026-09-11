/* Conservative allergen lookup rules for the current Gelato Spot menu.
   These are training indicators only, never a safety guarantee. */
window.ALLERGEN_KNOWLEDGE = {
  majorAllergens: [
    {id:'milk',name:'Milk',note:'Cheese, dairy, cream, butter, milk, and gelato names are direct or likely indicators; verify recipes and cross-contact.'},
    {id:'egg',name:'Egg',note:'Egg and mayonnaise are direct indicators; dressings, breads, pasta, gelato, and baked goods require verification.'},
    {id:'fish',name:'Fish',note:'Verify recipes and labels when fish or fish-derived ingredients may be present.'},
    {id:'shellfish',name:'Crustacean shellfish',note:'Verify current recipes, suppliers, substitutions, and shared preparation areas.'},
    {id:'tree-nuts',name:'Tree nuts',note:'Almond, pecan, and nut-branded flavors are direct indicators; pesto, spreads, and gelato require verification.'},
    {id:'peanuts',name:'Peanuts',note:'Peanut and peanut-butter names are direct indicators; verify gelato cross-contact and supplier labels.'},
    {id:'wheat',name:'Wheat',note:'Pizza dough, bread, panini, pasta, croutons, cookies, cakes, brownies, and similar baked items are indicators.'},
    {id:'soy',name:'Soy',note:'Sauces, processed foods, chocolate, baked goods, breads, and gelato may contain soy; verify labels.'},
    {id:'sesame',name:'Sesame',note:'Bread, buns, sauces, dressings, and garnishes may contain sesame; verify labels.'}
  ],
  directRules: [
    {allergen:'milk',patterns:['milk','cream','gelato','mozzarella','parmesan','cheese','butter','affogato','latte']},
    {allergen:'egg',patterns:['egg','mayonnaise','mayo']},
    {allergen:'tree-nuts',patterns:['almond','pecan','tree nut']},
    {allergen:'peanuts',patterns:['peanut','peanut butter']},
    {allergen:'wheat',patterns:['pizza','crust','dough','bread','panini','pasta','spaghetti','rigatoni','crouton','cookie','cake','brownie','biscoff']},
    {allergen:'soy',patterns:['soy']},
    {allergen:'sesame',patterns:['sesame']},
    {allergen:'fish',patterns:['anchovy','tuna','salmon','fish']},
    {allergen:'shellfish',patterns:['shrimp','crab','lobster','crustacean']}
  ],
  verifyRules: [
    {allergen:'milk',patterns:['gelato','chocolate','caramel','dressing','sauce','pesto','meatball','bread','pizza','panini','pasta','coffee']},
    {allergen:'egg',patterns:['gelato','dressing','aioli','bread','panini','pasta','cookie','cake','brownie','biscoff']},
    {allergen:'fish',patterns:['caesar','dressing']},
    {allergen:'tree-nuts',patterns:['pesto','nutella','gelato','dessert','cookie','cake']},
    {allergen:'peanuts',patterns:['gelato','dessert','chocolate','candy']},
    {allergen:'wheat',patterns:['pizza','panini','pasta','bread','crouton','dessert','cookie','cake','brownie','biscoff']},
    {allergen:'soy',patterns:['chocolate','sauce','dressing','bread','pizza','panini','pasta','gelato','dessert']},
    {allergen:'sesame',patterns:['bread','panini','dressing','sauce','garnish']}
  ],
  alwaysVerify: [
    'Menu names and descriptions do not establish complete ingredient or cross-contact information.',
    'Supplier labels, recipes, and substitutions can change.',
    'Shared ovens, prep surfaces, utensils, equipment, and storage areas require current restaurant verification.',
    'Employees must escalate allergy questions and verify current recipes and labels before making safety statements.'
  ]
};
