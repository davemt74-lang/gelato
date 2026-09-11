/* Position-based curriculum mapping for the local restaurant training agent. */
window.RESTAURANT_POSITIONS = [
  {
    id: 'host-counter',
    name: 'Host / Counter',
    icon: '◫',
    summary: 'Recognize menu categories, answer basic questions, quote common prices, and route detailed requests correctly.',
    sections: ['munchies','salads','wings','calzones','sandwiches','pizza','dessert'],
    mastery: { recognition: 100, ingredients: 65, prices: 80, service: 75, modifications: 45 },
    priorities: ['Menu category recognition','Common item descriptions','Base prices and sizes','Included sides and sauces','When to ask a server or manager'],
    scenarios: ['Greeting and menu orientation','Phone order basics','Finding a menu item quickly']
  },
  {
    id: 'server',
    name: 'Server',
    icon: '◎',
    summary: 'Master ingredients, prices, modifications, included sides, recommendations, and accurate guest communication.',
    sections: ['munchies','salads','wings','calzones','sandwiches','extra-toppings','crust-options','premium-meat','pizza','dessert'],
    mastery: { recognition: 100, ingredients: 95, prices: 90, service: 95, modifications: 95 },
    priorities: ['Complete item descriptions','Ingredient questions','Price and size accuracy','Upsells and add-ons','Special preparation notes'],
    scenarios: ['Guest recommendation','Dietary question escalation','Building and repeating an order']
  },
  {
    id: 'bartender',
    name: 'Bartender',
    icon: '◒',
    summary: 'Handle high-frequency food orders, quick recommendations, sauces, sides, and accurate bar-tab ordering.',
    sections: ['munchies','salads','wings','sandwiches','pizza','dessert'],
    mastery: { recognition: 95, ingredients: 80, prices: 85, service: 90, modifications: 70 },
    priorities: ['Munchies and wings','Popular pizzas','Fast recommendations','Sauces and extra dips','Order confirmation'],
    scenarios: ['Fast bar order','Shareable recommendation','Wing sauce explanation']
  },
  {
    id: 'pizza-cook',
    name: 'Pizza Cook',
    icon: '◉',
    summary: 'Know every pizza build, crust and size option, topping charge, and special no-sauce or no-cheese rule.',
    sections: ['extra-toppings','crust-options','premium-meat','pizza'],
    mastery: { recognition: 100, ingredients: 100, prices: 75, service: 75, modifications: 100 },
    priorities: ['Pizza recipes','Crust and size availability','No-red-sauce items','No-cheese item','Topping and premium meat additions'],
    scenarios: ['Read-back and build verification','Modified specialty pizza','Crust substitution check']
  },
  {
    id: 'line-cook',
    name: 'Line Cook',
    icon: '▰',
    summary: 'Prepare non-pizza menu items accurately and recognize service details, sides, sauces, and restrictions.',
    sections: ['munchies','salads','wings','calzones','sandwiches','dessert'],
    mastery: { recognition: 100, ingredients: 100, prices: 40, service: 95, modifications: 90 },
    priorities: ['Item builds','Included sauces and sides','Sandwich request options','French Connection restriction','Dessert presentation'],
    scenarios: ['Ticket interpretation','Missing side prevention','No-substitution check']
  },
  {
    id: 'prep-cook',
    name: 'Prep Cook',
    icon: '◇',
    summary: 'Connect ingredients to menu demand and identify shared meats, cheeses, vegetables, sauces, and prep components.',
    sections: ['munchies','salads','wings','calzones','sandwiches','extra-toppings','pizza','dessert'],
    mastery: { recognition: 85, ingredients: 100, prices: 25, service: 65, modifications: 75 },
    priorities: ['Ingredient cross-reference','Cheese and meat usage','Produce usage','House-made sauces','High-overlap ingredients'],
    scenarios: ['Ingredient lookup','Prep shortage impact','Cross-menu component search']
  },
  {
    id: 'expo',
    name: 'Expo',
    icon: '⇄',
    summary: 'Verify completed orders against menu standards, sides, sauces, sizes, and special instructions before release.',
    sections: ['munchies','salads','wings','calzones','sandwiches','pizza','dessert'],
    mastery: { recognition: 100, ingredients: 95, prices: 50, service: 100, modifications: 100 },
    priorities: ['Visual item recognition','Included side verification','Sauce and dressing checks','Modification verification','Final order completeness'],
    scenarios: ['Expo accuracy check','Missing ranch or chips','Modified pizza verification']
  },
  {
    id: 'food-runner',
    name: 'Food Runner',
    icon: '→',
    summary: 'Recognize finished items and deliver the correct sides, sauces, and guest-facing item names.',
    sections: ['munchies','salads','wings','calzones','sandwiches','pizza','dessert'],
    mastery: { recognition: 100, ingredients: 60, prices: 25, service: 95, modifications: 65 },
    priorities: ['Item identification','Included sides','Included dips and dressings','Table handoff language','Modification awareness'],
    scenarios: ['Identify and deliver','Missing accompaniment','Guest asks what the item is']
  },
  {
    id: 'cashier',
    name: 'Cashier',
    icon: '$',
    summary: 'Quote prices, sizes, add-ons, crust upgrades, extra toppings, and order totals accurately.',
    sections: ['munchies','salads','wings','calzones','sandwiches','extra-toppings','crust-options','premium-meat','pizza','dessert'],
    mastery: { recognition: 100, ingredients: 75, prices: 100, service: 90, modifications: 95 },
    priorities: ['Base menu prices','Pizza size pricing','Extra topping charges','Crust upgrades','Premium meat charges'],
    scenarios: ['Quote an order','Explain an upcharge','Check size availability']
  },
  {
    id: 'delivery-driver',
    name: 'Delivery Driver',
    icon: '▱',
    summary: 'Verify packaged orders, included sauces and sides, item counts, and customer handoff details.',
    sections: ['munchies','salads','wings','calzones','sandwiches','pizza','dessert'],
    mastery: { recognition: 90, ingredients: 55, prices: 45, service: 100, modifications: 70 },
    priorities: ['Package verification','Sauce and side counts','Item count checks','Modification labels','Customer handoff'],
    scenarios: ['Bag verification','Missing dip prevention','Customer item confirmation']
  },
  {
    id: 'trainer',
    name: 'Trainer',
    icon: '✦',
    summary: 'Teach all menu knowledge, assign role curricula, coach missed answers, and validate position readiness.',
    sections: ['munchies','salads','wings','calzones','sandwiches','extra-toppings','crust-options','premium-meat','pizza','dessert'],
    mastery: { recognition: 100, ingredients: 100, prices: 100, service: 100, modifications: 100 },
    priorities: ['Complete menu mastery','Role-based coaching','Quiz review','Scenario facilitation','Knowledge correction'],
    scenarios: ['Coach a missed answer','Assign role training','Run a readiness check']
  },
  {
    id: 'manager',
    name: 'Shift Lead / Manager',
    icon: '★',
    summary: 'Maintain complete menu authority, resolve exceptions, assign training, and review readiness across positions.',
    sections: ['munchies','salads','wings','calzones','sandwiches','extra-toppings','crust-options','premium-meat','pizza','dessert'],
    mastery: { recognition: 100, ingredients: 100, prices: 100, service: 100, modifications: 100 },
    priorities: ['Complete menu authority','Exception handling','Training assignment','Knowledge-base review','Readiness decisions'],
    scenarios: ['Resolve an unsupported request','Review employee readiness','Approve a menu knowledge update']
  }
];
