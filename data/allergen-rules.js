/*
  Conservative allergen-tagging rules for training and lookup.
  These tags are not a substitute for recipes, supplier labels, or manager confirmation.
*/
window.ALLERGEN_KNOWLEDGE = {
  majorAllergens: [
    { id: 'milk', name: 'Milk', note: 'Cheese, cream cheese, sour cream, and ice cream are direct menu indicators.' },
    { id: 'egg', name: 'Egg', note: 'Mayonnaise is a strong indicator. Dressings, breading, and baked goods require recipe verification.' },
    { id: 'fish', name: 'Fish', note: 'Tuna is a direct menu indicator. Caesar dressing may contain anchovy and requires recipe verification.' },
    { id: 'shellfish', name: 'Crustacean shellfish', note: 'No shellfish ingredient is named on the supplied menu. Cross-contact remains unverified.' },
    { id: 'tree-nuts', name: 'Tree nuts', note: 'No tree nut ingredient is named on the supplied menu. Cross-contact remains unverified.' },
    { id: 'peanuts', name: 'Peanuts', note: 'No peanut ingredient is named on the supplied menu. Cross-contact remains unverified.' },
    { id: 'wheat', name: 'Wheat', note: 'Crusts, buns, breading, crackers, croutons, pretzels, tortillas, and cookies are indicators.' },
    { id: 'soy', name: 'Soy', note: 'Processed meats, sauces, dressings, breads, and cheese products may contain soy; labels must be checked.' },
    { id: 'sesame', name: 'Sesame', note: 'Buns, breads, crackers, dressings, and sauces may contain sesame; labels must be checked.' }
  ],
  directRules: [
    { allergen: 'milk', patterns: ['mozzarella','cheddar','provolone','swiss cheese','swiss','parmesan','cream cheese','sour cream','pepper jack','four kinds of cheese','cheese','vanilla ice cream','ice cream'] },
    { allergen: 'fish', patterns: ['tuna'] },
    { allergen: 'wheat', patterns: ['pizza crust','whole-wheat crust','whole wheat crust','pretzel','crackers','croutons','italian bun','fresh baked roll','flour tortilla','breaded jalapeños','breaded jalapenos','chocolate chip cookie','calzone'] },
    { allergen: 'egg', patterns: ['mayo','mayonnaise'] }
  ],
  verifyRules: [
    { allergen: 'milk', patterns: ['ranch','caesar dressing','italian dressing','peppercorn dressing','poppy seed sauce','pizza sauce','wing sauce','honey bbq','teriyaki','buffalo','cookie','breaded','sausage','pepperoni','salami','bacon bits','meatballs'] },
    { allergen: 'egg', patterns: ['ranch','caesar dressing','italian dressing','peppercorn dressing','poppy seed sauce','breaded','croutons','pretzel','crackers','pizza crust','bun','roll','tortilla','cookie','calzone'] },
    { allergen: 'fish', patterns: ['caesar dressing'] },
    { allergen: 'wheat', patterns: ['mozzarella sticks','jalapeno poppers','jalapeño poppers','pizza','bun','roll','tortilla','cookie','crust','breaded','crackers','croutons','pretzel','calzone'] },
    { allergen: 'soy', patterns: ['sausage','pepperoni','salami','ham','turkey','chicken','hamburger','roast beef','bacon','meatballs','ranch','dressing','sauce','cheese','crust','bun','roll','tortilla','cookie','crackers','croutons','pretzel','breaded'] },
    { allergen: 'sesame', patterns: ['bun','roll','crackers','croutons','pretzel','tortilla','dressing','sauce'] }
  ],
  alwaysVerify: [
    'Shared ovens, prep surfaces, utensils, fryers, cutting tools, pans, and storage areas are not documented.',
    'House-made sauce, dressing, dough, bread, cookie, meat, and breading recipes are not yet supplied.',
    'Supplier labels and ingredient substitutions can change.',
    'A gluten-free crust does not establish a gluten-free finished pizza or eliminate cross-contact.',
    'Employees must escalate allergy questions to a manager and verify current recipes and labels.'
  ]
};
