from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]


def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{label}: expected 1 exact match, found {count}')
    return text.replace(old, new, 1)


def regex_once(text: str, pattern: str, replacement: str, label: str, flags=0) -> str:
    updated, count = re.subn(pattern, replacement, text, count=1, flags=flags)
    if count != 1:
        raise SystemExit(f'{label}: expected 1 regex match, found {count}')
    return updated


index_path = ROOT / 'index.html'
index = index_path.read_text(encoding='utf-8')
index = replace_once(
    index,
    '<script src="data/menu-knowledge.js"></script>',
    '<script src="api/menu-knowledge.php?v=20260910-menu-sync1"></script>',
    'index menu source',
)

index = regex_once(
    index,
    r"  function itemIngredients\(item\)\{\n    const raw=\[\.\.\.\(item\.ingredients\|\|\[\]\),\.\.\.\(item\.optionList\|\|\[\]\)\];\n(?:.|\n)*?    return unique\(raw\.flatMap\(splitIngredient\)\.map\(normalizeIngredient\)\.filter\(Boolean\)\);\n  \}",
    "  function itemIngredients(item){\n    const raw=[...(item.ingredients||[]),...(item.optionList||[])];\n    return unique(raw.flatMap(splitIngredient).map(normalizeIngredient).filter(Boolean));\n  }",
    'index itemIngredients',
)

index = regex_once(
    index,
    r"    if \(isItem && sectionId==='sandwiches'\).*?\n    if \(isItem && itemOrIngredient\.name==='Gluten-Free Crust'\) verify\.push\('wheat'\);",
    "    if (isItem && sectionId==='panini') { if(!direct.includes('wheat')) verify.push('wheat'); verify.push('sesame','egg','soy'); }\n    if (isItem && sectionId==='wood-fired-pizza') { if(!direct.includes('wheat')) verify.push('wheat'); verify.push('soy'); }\n    if (isItem && sectionId==='gelato') verify.push('milk','egg','soy','tree-nuts','peanuts');",
    'index allergen section rules',
    flags=re.S,
)

index = regex_once(
    index,
    r"  const menuNotes = \[\n(?:.|\n)*?\n  \];\n\n  const pageInfo=",
    "  const menuNotes = Array.isArray(window.MENU_NOTES) ? window.MENU_NOTES : [];\n\n  const pageInfo=",
    'index menu notes',
    flags=re.S,
)

index = regex_once(
    index,
    r"  function matchSection\(query\)\{\n(?:.|\n)*?\n  \}\n  function matchIngredient",
    "  function matchSection(query){\n    const q=query.toLowerCase();\n    const aliases={\n      'appetizers':['appetizer','starter'],\n      'panini':['panini','sandwich'],\n      'wood-fired-pizza':['pizza','wood fired'],\n      'salads-and-pasta':['salad','pasta'],\n      'gelato':['gelato','dessert','ice cream'],\n      'beverages':['beverage','drink','soda'],\n      'beer-wine-and-cocktails':['beer','wine','cocktail','bar'],\n      'coffee':['coffee','espresso','latte'],\n      'happy-hour':['happy hour']\n    };\n    return sections.find(section=>q.includes(section.name.toLowerCase())||q.includes(section.id.replace(/-/g,' '))||(aliases[section.id]||[]).some(alias=>q.includes(alias)))||null;\n  }\n  function matchIngredient",
    'index matchSection',
    flags=re.S,
)

index = regex_once(
    index,
    r"  function buildExceptionTrainingQuestions\(position\)\{\n(?:.|\n)*?\n  \}\n  function buildGuidedQuestionPool",
    "  function buildExceptionTrainingQuestions(position){\n    const allowed=new Set(position.sections);\n    const questions=[];\n    const allergyNote=menuNotes.find(note=>note.id==='allergen-boundary');\n    if(allergyNote){\n      questions.push({type:'allergens',sectionId:'global',prompt:'What should you do before telling a guest an item is safe for an allergy?',answers:['manager','verify','recipe','label','cross contact'],min:2,answer:'Do not guarantee safety; involve a manager and verify recipes, labels, substitutions, and cross-contact controls.',hint:'The correct response includes escalation and verification.',explanation:allergyNote.detail||allergyNote.summary,source:`Menu Notes → ${allergyNote.title}`});\n    }\n    menuNotes.filter(note=>note.sectionId!=='global'&&allowed.has(note.sectionId)&&note.summary).forEach(note=>{\n      questions.push({type:'service',sectionId:note.sectionId,prompt:`What current menu note applies to ${note.title}?`,answers:[note.summary],min:1,answer:note.summary,hint:'Use the current menu-source note.',explanation:note.detail||note.summary,source:`Menu Notes → ${note.title}`});\n    });\n    return questions;\n  }\n  function buildGuidedQuestionPool",
    'index exception training',
    flags=re.S,
)

index = replace_once(
    index,
    "  appendMessage('agent','<h4>Workspace initialized</h4><p>I have loaded the complete menu, ingredient reverse index, ten menu categories, twelve restaurant positions, pricing records, and conservative allergen tags.</p><p>I can now lead a continuous role-based training session. Use the recommended action or the <strong>+</strong> button to choose a one-click training activity.</p>');",
    "  appendMessage('agent',`<h4>Workspace initialized</h4><p>I loaded ${allItems.length} current menu entries across ${sections.length} live menu sections, along with database prices, derived ingredient references, role training, and conservative allergen tags.</p><p>The menu comes from this installation's database, which can be synchronized from the same REST source used by the live Gelato Spot menu.</p>`);",
    'index startup message',
)
index_path.write_text(index, encoding='utf-8')

advanced_path = ROOT / 'js' / 'advanced-training.js'
advanced = advanced_path.read_text(encoding='utf-8')
advanced = regex_once(
    advanced,
    r"  function itemIngredients\(item\) \{\n    const ingredients = \[\.\.\.\(item\.ingredients \|\| \[\]\), \.\.\.\(item\.optionList \|\| \[\]\)\];\n(?:.|\n)*?    return unique\(ingredients\.flatMap\(splitIngredient\)\.map\(normalizeIngredient\)\.filter\(Boolean\)\);\n  \}",
    "  function itemIngredients(item) {\n    const ingredients = [...(item.ingredients || []), ...(item.optionList || [])];\n    return unique(ingredients.flatMap(splitIngredient).map(normalizeIngredient).filter(Boolean));\n  }",
    'advanced itemIngredients',
    flags=re.S,
)
advanced = regex_once(
    advanced,
    r"  const KITCHEN_SCENARIOS = \[\n(?:.|\n)*?\n  \];\n  const KITCHEN_ISSUE_OPTIONS = unique\(KITCHEN_SCENARIOS\.flatMap\(scenario => scenario\.issues\)\.concat\(\['Wrong size', 'Missing ranch', 'Wrong sauce', 'No issues'\]\)\);",
    "  function buildKitchenScenarios() {\n    const candidates = allItems.filter(item => itemIngredients(item).length >= 2).slice(0, 14);\n    const scenarios = [];\n    candidates.forEach((item, index) => {\n      const components = itemIngredients(item).slice(0, 5);\n      if (index % 2 === 0 && components.length >= 2) {\n        const missing = components[0];\n        scenarios.push({\n          id: `live-${index}-missing`,\n          item: item.name,\n          ticket: [item.name, ...components.slice(1)],\n          issues: [`Missing ${missing}`],\n          explanation: `${item.name} includes ${missing} in the current menu description.`\n        });\n      } else {\n        scenarios.push({\n          id: `live-${index}-correct`,\n          item: item.name,\n          ticket: [item.name, ...components],\n          issues: ['No issues'],\n          explanation: `This ticket matches the current listed components for ${item.name}.`\n        });\n      }\n    });\n    return scenarios.length ? scenarios : [{\n      id: 'live-menu-no-components',\n      item: 'Menu verification',\n      ticket: ['Use the current menu description'],\n      issues: ['No issues'],\n      explanation: 'No structured component data is available for a kitchen verification scenario yet.'\n    }];\n  }\n  const KITCHEN_SCENARIOS = buildKitchenScenarios();\n  const KITCHEN_ISSUE_OPTIONS = unique(KITCHEN_SCENARIOS.flatMap(scenario => scenario.issues).concat(['Wrong item', 'Wrong size', 'Wrong sauce', 'No issues']));",
    'advanced kitchen scenarios',
    flags=re.S,
)
advanced_path.write_text(advanced, encoding='utf-8')

positions = r'''/* Position-based curriculum mapping derived from the current database-backed menu sections. */
(() => {
  const available = new Set((window.MENU_SECTIONS || []).map(section => section.id));
  const pick = (...ids) => ids.filter(id => available.has(id));
  const all = [...available];
  const food = pick('appetizers','panini','wood-fired-pizza','salads-and-pasta','gelato','happy-hour');
  const guest = pick('appetizers','panini','wood-fired-pizza','salads-and-pasta','gelato','beverages','coffee');
  const roles = [
    ['host-counter','Host / Counter','◫','Recognize current menu sections, answer basic questions, quote common prices, and route detailed requests correctly.',guest],
    ['server','Server','◎','Master current items, descriptions, prices, service notes, recommendations, and accurate guest communication.',all],
    ['bartender','Bartender','◒','Handle high-frequency food and drink orders, happy hour, recommendations, and accurate bar-tab ordering.',pick('appetizers','panini','wood-fired-pizza','beverages','beer-wine-and-cocktails','coffee','happy-hour')],
    ['pizza-cook','Pizza Cook','◉','Know every current wood-fired pizza build, description, price option, and related happy-hour preparation.',pick('wood-fired-pizza','appetizers','happy-hour')],
    ['line-cook','Line Cook','▰','Prepare current savory menu items accurately and recognize listed components and service details.',pick('appetizers','panini','wood-fired-pizza','salads-and-pasta','happy-hour')],
    ['prep-cook','Prep Cook','◇','Connect listed menu components to prep demand across food and gelato service.',pick('appetizers','panini','wood-fired-pizza','salads-and-pasta','gelato')],
    ['expo','Expo','⇄','Verify completed food orders against current item descriptions, components, prices, and special instructions.',food],
    ['food-runner','Food Runner','→','Recognize finished items and deliver the correct guest-facing menu names and listed accompaniments.',food],
    ['cashier','Cashier','$','Quote current prices and menu options accurately across food, drinks, coffee, gelato, and happy hour.',all],
    ['delivery-driver','Delivery Driver','▱','Verify packaged food orders, item counts, names, and customer handoff details.',food],
    ['trainer','Trainer','✦','Teach the current database-backed menu, coach missed answers, and validate position readiness.',all],
    ['manager','Shift Lead / Manager','★','Maintain complete current menu authority, resolve exceptions, assign training, and review readiness.',all]
  ];
  window.RESTAURANT_POSITIONS = roles.map(([id,name,icon,summary,sections]) => ({
    id,name,icon,summary,
    sections: sections.length ? sections : all,
    mastery: { recognition:100, ingredients:['trainer','manager','line-cook','prep-cook','pizza-cook'].includes(id)?100:80, prices:['cashier','server','trainer','manager'].includes(id)?100:75, service:95, modifications:['trainer','manager','pizza-cook','line-cook','expo'].includes(id)?95:75 },
    priorities: ['Current menu recognition','Accurate item descriptions','Current prices and options','Listed components and service details','Safe escalation when information is not verified'],
    scenarios: ['Find a current menu item','Explain a current menu description','Verify an order against the current menu']
  }));
})();
'''
(ROOT / 'data' / 'positions.js').write_text(positions, encoding='utf-8')

allergens = r'''/* Conservative allergen lookup rules for the current Gelato Spot menu.
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
'''
(ROOT / 'data' / 'allergen-rules.js').write_text(allergens, encoding='utf-8')

legacy = "/* Legacy static menu removed. The training workspace now loads api/menu-knowledge.php from the local database, synchronized from the live Gelato Spot REST menu. */\n"
(ROOT / 'data' / 'menu-knowledge.js').write_text(legacy, encoding='utf-8')

# Active runtime must not retain the old restaurant's item-specific rules.
banned = ["Fatso's Skinny", 'Belly Buster Combo', 'French Connection', 'Fresh Baked Wings', 'Great White', 'Fat Boy Special']
for rel in ['index.html','js/advanced-training.js','data/positions.js']:
    text = (ROOT / rel).read_text(encoding='utf-8')
    hits = [term for term in banned if term in text]
    if hits:
        raise SystemExit(f'{rel}: legacy menu references remain: {hits}')

print('Gelato runtime refactor applied successfully.')
