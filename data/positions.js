/* Position-based curriculum mapping derived from the current database-backed menu sections. */
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
