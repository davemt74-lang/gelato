/* Advanced local training modules: certifications, daily quiz,
   kitchen ticket verification, mistake review, ingredient detail, and spaced repetition. */
(() => {
  'use strict';

  const sections = window.MENU_SECTIONS || [];
  const positions = window.RESTAURANT_POSITIONS || [];
  const allergenKB = window.ALLERGEN_KNOWLEDGE || { majorAllergens: [], directRules: [], verifyRules: [] };
  const sectionMap = new Map(sections.map(section => [section.id, section]));
  const allItems = sections.flatMap((section, sectionIndex) => section.items.map((item, itemIndex) => ({
    ...item,
    sectionId: section.id,
    sectionName: section.name,
    sectionIcon: section.icon,
    key: `${section.id}::${item.name}`,
    sectionIndex,
    itemIndex
  })));
  const itemMap = new Map(allItems.map(item => [item.key, item]));
  const $ = id => document.getElementById(id);
  const esc = value => String(value ?? '').replace(/[&<>'"]/g, character => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
  }[character]));
  const money = value => `$${Number(value || 0).toFixed(2)}`;
  const unique = values => [...new Set(values.filter(Boolean))];
  const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
  const todayKey = () => new Date().toLocaleDateString('en-CA');
  const uid = prefix => `${prefix}-${Date.now()}-${Math.random().toString(16).slice(2)}`;
  const safeJson = (key, fallback) => {
    try {
      const value = localStorage.getItem(key);
      return value === null ? fallback : JSON.parse(value);
    } catch {
      return fallback;
    }
  };
  const saveJson = (key, value) => {
    try { localStorage.setItem(key, JSON.stringify(value)); } catch {}
  };
  const getText = (key, fallback = '') => { try { const value = localStorage.getItem(key); return value === null ? fallback : value; } catch { return fallback; } };
  const setText = (key, value) => { try { localStorage.setItem(key, String(value)); } catch {} };
  const activePositionId = () => getText('restaurant-active-position', 'server');
  const activePosition = () => positions.find(position => position.id === activePositionId()) || positions[0];
  const clickNav = page => document.querySelector(`[data-nav="${page}"]`)?.click();
  const showToast = (message, good = false) => {
    document.querySelector('.advanced-toast')?.remove();
    const toast = document.createElement('div');
    toast.className = `advanced-toast${good ? ' good' : ''}`;
    toast.textContent = message;
    document.body.appendChild(toast);
    window.setTimeout(() => toast.remove(), 2400);
  };

  const aliases = new Map(Object.entries({
    'mozzarella cheese':'mozzarella','pure mozzarella cheese':'mozzarella','melted mozzarella':'mozzarella',
    'aged provolone':'provolone','provolone cheese':'provolone','melted provolone':'provolone',
    'extra sharp cheddar':'cheddar','sharp cheddar cheese':'cheddar','melted cheddar cheese':'cheddar','extra cheddar':'cheddar','cheddar cheese':'cheddar',
    'swiss':'swiss cheese','grated parmesan':'parmesan','parmesan cheese':'parmesan',
    'fresh mushrooms':'mushrooms','fresh mushroom':'mushrooms','mushroom':'mushrooms',
    'fresh onions':'onions','fresh onion':'onions','onion':'onions','green pepper':'green peppers',
    'black olive':'black olives','tomato':'tomatoes','diced tomatoes':'tomatoes','vine ripened tomatoes':'tomatoes','vine-ripened tomatoes':'tomatoes',
    'fresh broccoli':'broccoli','jalapeños':'jalapenos','fresh jalapeños':'jalapenos','fresh jalapenos':'jalapenos',
    'real bacon bits':'bacon bits','crispy bacon':'bacon','white meat chicken':'chicken','grilled chicken':'chicken','lean ham':'ham',
    'tender sliced turkey':'turkey','thinly sliced roast beef':'roast beef','tender, lean roast beef':'roast beef','razor thin sliced extra lean roast beef':'roast beef',
    'house-made ranch':'ranch','house made ranch':'ranch','2 oz house-made ranch':'ranch','2 oz house made ranch':'ranch','wings ranch':'ranch',
    '2 oz house-made pizza sauce':'pizza sauce','2 oz house made pizza sauce':'pizza sauce','side of sauce':'pizza sauce',
    'medium buffalo':'medium buffalo sauce','hot buffalo':'hot buffalo sauce','honey bbq':'honey bbq sauce',
    'hot chocolate chip cookie':'chocolate chip cookie','fresh flour tortilla':'flour tortilla','fresh baked roll':'italian roll',
    'romaine lettuce':'romaine','crispy romaine lettuce':'romaine','fresh crisp lettuce':'lettuce','crispy fresh lettuce':'lettuce',
    'assorted seasonal fresh vegetables':'seasonal vegetables','four kinds of cheese':'four-cheese blend','12-inch gluten-free crust':'gluten-free crust',
    'white-meat chicken':'chicken','skillet':''
  }));

  function normalizeIngredient(raw) {
    let value = String(raw || '').trim().replace(/[.;]+$/, '').replace(/\s+/g, ' ');
    let lower = value.toLowerCase();
    if (aliases.has(lower)) return aliases.get(lower);
    value = value.replace(/^\d+\s+(?:oz\.?\s+)?/i, '');
    lower = value.toLowerCase();
    return aliases.get(lower) || lower;
  }

  function splitIngredient(raw) {
    return /^ranch or pizza sauce$/i.test(String(raw || '').trim()) ? ['ranch', 'pizza sauce'] : [raw];
  }

  function itemIngredients(item) {
    const ingredients = [...(item.ingredients || []), ...(item.optionList || [])];
    return unique(ingredients.flatMap(splitIngredient).map(normalizeIngredient).filter(Boolean));
  }

  function buildIngredientCatalog() {
    const catalog = new Map();
    allItems.forEach(item => {
      itemIngredients(item).forEach(name => {
        if (!catalog.has(name)) catalog.set(name, { name, uses: [], sections: new Set(), variants: new Set() });
        const entry = catalog.get(name);
        entry.uses.push(item);
        entry.sections.add(item.sectionId);
      });
      [...(item.ingredients || []), ...(item.optionList || [])].forEach(raw => {
        splitIngredient(raw).forEach(part => {
          const name = normalizeIngredient(part);
          if (name && catalog.has(name)) catalog.get(name).variants.add(String(part).trim());
        });
      });
    });
    return [...catalog.values()].map(entry => ({ ...entry, sections: [...entry.sections], variants: [...entry.variants] }));
  }

  const ingredientCatalog = buildIngredientCatalog();
  const ingredientMap = new Map(ingredientCatalog.map(entry => [entry.name, entry]));
  const allergenName = id => allergenKB.majorAllergens.find(allergen => allergen.id === id)?.name || id;

  function allergenProfileForIngredient(name) {
    const normalized = normalizeIngredient(name);
    const direct = [];
    const verify = [];
    (allergenKB.directRules || []).forEach(rule => {
      const terms = rule.patterns || rule.terms || rule.matches || [];
      if (terms.some(term => normalized.includes(String(term).toLowerCase()))) direct.push(rule.allergen || rule.allergenId || rule.id);
    });
    (allergenKB.verifyRules || []).forEach(rule => {
      const terms = rule.patterns || rule.terms || rule.matches || [];
      if (terms.some(term => normalized.includes(String(term).toLowerCase()))) verify.push(rule.allergen || rule.allergenId || rule.id);
    });
    return { direct: unique(direct), verify: unique(verify).filter(id => !direct.includes(id)) };
  }

  const state = {
    orderLines: [],
    orderScenarioId: 'family-night',
    daily: null,
    kitchen: null,
    certificationPositionId: activePositionId(),
    ingredientDetail: null
  };

  function recordMistake(mistake) {
    const mistakes = safeJson('restaurant-mistakes', []);
    const moduleName = mistake.moduleName || mistake.source || 'Training';
    const moduleId = mistake.moduleId || String(moduleName).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'training';
    const normalized = {
      id: mistake.id || uid('mistake'),
      date: mistake.date || new Date().toISOString(),
      source: moduleName,
      moduleId,
      moduleName,
      type: mistake.type || 'knowledge',
      sectionId: mistake.sectionId || '',
      positionId: mistake.positionId || activePositionId(),
      prompt: mistake.prompt || 'Training question',
      selected: Array.isArray(mistake.selected) ? mistake.selected.map(String) : mistake.selected ? [String(mistake.selected)] : [],
      correct: Array.isArray(mistake.correct) ? mistake.correct.map(String) : mistake.correct ? [String(mistake.correct)] : [],
      explanation: mistake.explanation || mistake.explain || '',
      context: mistake.context || '',
      attemptId: mistake.attemptId || '',
      resolved: Boolean(mistake.resolved),
      resolvedAt: mistake.resolvedAt || null
    };
    mistakes.unshift(normalized);
    saveJson('restaurant-mistakes', mistakes.slice(0, 500));
    renderReviewCenter();
    window.dispatchEvent(new CustomEvent('restaurant:mistake-recorded', { detail: normalized }));
    return normalized;
  }

  function updateSpacedRepetition(card, status, flashStats) {
    if (!flashStats?.cards || !card?.id) return;
    const record = flashStats.cards[card.id] ||= { known: 0, review: 0 };
    const intervals = [1, 3, 7, 14, 30, 60];
    if (status === 'known') {
      record.box = clamp(Number(record.box || 0) + 1, 1, intervals.length);
      record.streak = Number(record.streak || 0) + 1;
      record.intervalDays = intervals[record.box - 1];
      record.lastResult = 'known';
    } else {
      record.box = 0;
      record.streak = 0;
      record.intervalDays = 0;
      record.lastResult = 'review';
    }
    const dueDate = new Date();
    dueDate.setDate(dueDate.getDate() + Number(record.intervalDays || 0));
    record.due = dueDate.toISOString();
    record.last = new Date().toISOString();
  }

  function isFlashDue(cardId, flashStats = safeJson('restaurant-flash-stats', { cards: {} })) {
    const record = flashStats?.cards?.[cardId];
    if (!record || !record.due) return true;
    return new Date(record.due).getTime() <= Date.now();
  }

  function sortFlashcards(pool, order, flashStats) {
    const stats = flashStats || safeJson('restaurant-flash-stats', { cards: {} });
    if (order === 'menu') return [...pool].sort((a, b) => a.sortIndex - b.sortIndex);
    if (order === 'due') {
      return [...pool].sort((a, b) => {
        const aRecord = stats?.cards?.[a.id] || {};
        const bRecord = stats?.cards?.[b.id] || {};
        const aDue = isFlashDue(a.id, stats) ? 0 : 1;
        const bDue = isFlashDue(b.id, stats) ? 0 : 1;
        return aDue - bDue || Number(aRecord.box || 0) - Number(bRecord.box || 0) || a.sortIndex - b.sortIndex;
      });
    }
    if (order === 'review') {
      return [...pool].sort((a, b) => {
        const aRecord = stats?.cards?.[a.id] || {};
        const bRecord = stats?.cards?.[b.id] || {};
        return Number(aRecord.box || 0) - Number(bRecord.box || 0) || Number(bRecord.review || 0) - Number(aRecord.review || 0) || a.sortIndex - b.sortIndex;
      });
    }
    return [...pool].sort(() => Math.random() - 0.5);
  }

  window.RestaurantAdvanced = {
    recordMistake,
    updateSpacedRepetition,
    isFlashDue,
    sortFlashcards
  };

  function buildKitchenScenarios() {
    const candidates = allItems.filter(item => itemIngredients(item).length >= 2).slice(0, 14);
    const scenarios = [];
    candidates.forEach((item, index) => {
      const components = itemIngredients(item).slice(0, 5);
      if (index % 2 === 0 && components.length >= 2) {
        const missing = components[0];
        scenarios.push({
          id: `live-${index}-missing`,
          item: item.name,
          ticket: [item.name, ...components.slice(1)],
          issues: [`Missing ${missing}`],
          explanation: `${item.name} includes ${missing} in the current menu description.`
        });
      } else {
        scenarios.push({
          id: `live-${index}-correct`,
          item: item.name,
          ticket: [item.name, ...components],
          issues: ['No issues'],
          explanation: `This ticket matches the current listed components for ${item.name}.`
        });
      }
    });
    return scenarios.length ? scenarios : [{
      id: 'live-menu-no-components',
      item: 'Menu verification',
      ticket: ['Use the current menu description'],
      issues: ['No issues'],
      explanation: 'No structured component data is available for a kitchen verification scenario yet.'
    }];
  }
  const KITCHEN_SCENARIOS = buildKitchenScenarios();
  const KITCHEN_ISSUE_OPTIONS = unique(KITCHEN_SCENARIOS.flatMap(scenario => scenario.issues).concat(['Wrong item', 'Wrong size', 'Wrong sauce', 'No issues']));

  function startKitchenSession() {
    const count = Number($('kitchenCount')?.value || 5);
    const scenarios = [...KITCHEN_SCENARIOS].sort(() => Math.random() - 0.5).slice(0, count);
    state.kitchen = { scenarios, index: 0, correct: 0, answers: [], positionId: activePositionId() };
    renderKitchenScenario();
  }

  function renderKitchenScenario() {
    const session = state.kitchen;
    if (!session) return;
    const scenario = session.scenarios[session.index];
    $('kitchenStage').innerHTML = `<div class="module-question-head"><span>Ticket ${session.index + 1} of ${session.scenarios.length}</span><strong>${session.correct}/${session.index} correct</strong></div><div class="kitchen-ticket"><div class="kitchen-ticket-head"><span>Kitchen ticket</span><strong>${esc(scenario.item)}</strong></div>${scenario.ticket.map(line => `<div class="kitchen-ticket-row">${esc(line)}</div>`).join('')}</div><h4 class="module-question">Select every issue on this ticket.</h4><p class="muted tiny">Choose “No issues” only when the ticket is fully correct.</p><div class="choices kitchen-choices">${KITCHEN_ISSUE_OPTIONS.map((issue, index) => `<div class="choice" data-option="${esc(issue)}"><input type="checkbox" id="kitchen-opt-${index}"><label for="kitchen-opt-${index}"><span class="choice-box"></span><span>${esc(issue)}</span></label></div>`).join('')}</div><div id="kitchenFeedback"></div><div class="quiz-actions"><button class="btn btn-light" id="endKitchen">End session</button><button class="btn btn-primary" id="submitKitchen">Verify ticket</button><button class="btn btn-dark hidden" id="nextKitchen">${session.index === session.scenarios.length - 1 ? 'View results' : 'Next ticket'}</button></div>`;
    $('endKitchen').addEventListener('click', () => { state.kitchen = null; renderKitchenIdle(); });
    $('submitKitchen').addEventListener('click', submitKitchenAnswer);
    $('nextKitchen').addEventListener('click', () => {
      if (session.index === session.scenarios.length - 1) finishKitchenSession();
      else { session.index += 1; renderKitchenScenario(); }
    });
  }

  function submitKitchenAnswer() {
    const session = state.kitchen;
    if (!session) return;
    const scenario = session.scenarios[session.index];
    const choices = [...$('kitchenStage').querySelectorAll('.choice')];
    const selected = choices.filter(choice => choice.querySelector('input').checked).map(choice => choice.dataset.option);
    if (!selected.length) {
      $('kitchenFeedback').innerHTML = '<div class="feedback bad">Select at least one answer.</div>';
      return;
    }
    const expected = [...scenario.issues].sort();
    const chosen = [...selected].sort();
    const correct = expected.length === chosen.length && expected.every((value, index) => value === chosen[index]);
    if (correct) session.correct += 1;
    choices.forEach(choice => {
      choice.querySelector('input').disabled = true;
      const value = choice.dataset.option;
      if (scenario.issues.includes(value) && selected.includes(value)) choice.classList.add('correct');
      else if (selected.includes(value)) choice.classList.add('wrong');
      else if (scenario.issues.includes(value)) choice.classList.add('missed');
    });
    session.answers.push({ scenario, selected, correct });
    if (!correct) recordMistake({
      source: 'Kitchen Tickets', moduleId: 'kitchen-tickets', moduleName: 'Kitchen Tickets', type: 'kitchen', prompt: `Verify ticket: ${scenario.ticket.join(' · ')}`,
      selected, correct: scenario.issues, explanation: scenario.explanation,
      positionId: session.positionId,
      sectionId: allItems.find(item => item.name === scenario.item)?.sectionId || ''
    });
    $('kitchenFeedback').innerHTML = `<div class="feedback ${correct ? 'good' : 'bad'}"><strong>${correct ? 'Correct.' : 'Not quite.'}</strong> ${esc(scenario.explanation)}</div>`;
    $('submitKitchen').classList.add('hidden');
    $('nextKitchen').classList.remove('hidden');
  }

  function finishKitchenSession() {
    const session = state.kitchen;
    if (!session) return;
    const score = Math.round(session.correct / session.scenarios.length * 100);
    const result = { id: uid('kitchen-result'), date: new Date().toISOString(), positionId: session.positionId, correct: session.correct, total: session.scenarios.length, score, passed: score >= 80 };
    const history = safeJson('restaurant-kitchen-results', []);
    history.unshift(result);
    saveJson('restaurant-kitchen-results', history.slice(0, 100));
    window.dispatchEvent(new CustomEvent('restaurant:activity',{detail:{eventType:'kitchen_verification.completed',sourceId:result.id,occurredAt:result.date,summary:{score:result.score,correct:result.correct,total:result.total,passed:result.passed,positionId:result.positionId}}}));
    $('kitchenStage').innerHTML = `<div class="score-banner"><small>Kitchen verification score</small><strong>${score}%</strong><span>${session.correct} of ${session.scenarios.length} correct · ${score >= 80 ? 'Passed' : 'Review missed tickets'}</span></div><div class="flash-setup-actions" style="margin-top:16px"><button class="btn btn-primary" id="restartKitchen">Start another session</button><button class="btn btn-light" id="openReviewFromKitchen">Review mistakes</button></div>`;
    $('restartKitchen').addEventListener('click', startKitchenSession);
    $('openReviewFromKitchen').addEventListener('click', () => clickNav('review'));
    state.kitchen = null;
    renderCertification();
  }

  function renderKitchenIdle() {
    if (!$('kitchenStage')) return;
    $('kitchenStage').innerHTML = '<div class="quiz-empty"><div><div style="font-size:44px">▤</div><h4>Verify realistic kitchen tickets</h4><p>Choose a session length, then identify missing sides, incorrect builds, invalid substitutions, and preparation errors.</p></div></div>';
  }

  function seededShuffle(values, seedText) {
    let seed = 2166136261;
    for (const character of seedText) seed = Math.imul(seed ^ character.charCodeAt(0), 16777619);
    const result = [...values];
    for (let index = result.length - 1; index > 0; index -= 1) {
      seed += 0x6D2B79F5;
      let random = seed;
      random = Math.imul(random ^ random >>> 15, random | 1);
      random ^= random + Math.imul(random ^ random >>> 7, random | 61);
      const number = ((random ^ random >>> 14) >>> 0) / 4294967296;
      const swapIndex = Math.floor(number * (index + 1));
      [result[index], result[swapIndex]] = [result[swapIndex], result[index]];
    }
    return result;
  }

  function dailyQuestionPool(positionId) {
    const position = positions.find(entry => entry.id === positionId) || activePosition();
    const items = allItems.filter(item => position.sections.includes(item.sectionId));
    const pool = [];
    items.forEach(item => {
      if (item.description) {
        const wrong = seededShuffle(items.filter(candidate => candidate.name !== item.name).map(candidate => candidate.name), `${todayKey()}-${item.key}-identity`).slice(0, 3);
        pool.push({
          type: 'identity', sectionId: item.sectionId, prompt: `Which menu item matches this description? “${item.description}”`,
          options: seededShuffle([item.name, ...wrong], `${todayKey()}-${item.key}-identity-options`), correct: [item.name],
          explanation: `${item.name} is the matching ${item.sectionName} item.`
        });
      }
      if ((item.prices || []).length) {
        const price = item.prices[0];
        const wrong = seededShuffle(unique(items.flatMap(candidate => candidate.prices || []).map(candidate => money(candidate.price)).filter(value => value !== money(price.price))), `${todayKey()}-${item.key}-price`).slice(0, 3);
        pool.push({
          type: 'price', sectionId: item.sectionId, prompt: `What is the listed price for ${item.name} — ${price.label}?`,
          options: seededShuffle([money(price.price), ...wrong], `${todayKey()}-${item.key}-price-options`), correct: [money(price.price)],
          explanation: `${item.name}, ${price.label}: ${money(price.price)}.`
        });
      }
      const ingredients = itemIngredients(item);
      if (ingredients.length >= 2) {
        const correct = seededShuffle(ingredients, `${todayKey()}-${item.key}-ingredients`).slice(0, Math.min(3, ingredients.length));
        const wrong = seededShuffle(ingredientCatalog.map(entry => entry.name).filter(name => !ingredients.includes(name)), `${todayKey()}-${item.key}-wrong-ingredients`).slice(0, Math.max(2, 5 - correct.length));
        pool.push({
          type: 'ingredients', sectionId: item.sectionId, prompt: `Select every listed ingredient or component of ${item.name}.`,
          options: seededShuffle(unique([...correct, ...wrong]), `${todayKey()}-${item.key}-ingredient-options`), correct,
          explanation: `${item.name}: ${ingredients.join(', ')}.`
        });
      }
    });
    return seededShuffle(pool, `${todayKey()}-${positionId}-daily-pool`);
  }

  function startDailyTraining() {
    const positionId = $('dailyPosition')?.value || activePositionId();
    const questions = dailyQuestionPool(positionId).slice(0, 8);
    state.daily = { dateKey: todayKey(), positionId, questions, index: 0, correct: 0, answers: [] };
    renderDailyQuestion();
  }

  function renderDailyQuestion() {
    const session = state.daily;
    if (!session) return;
    const question = session.questions[session.index];
    $('dailyStage').innerHTML = `<div class="module-question-head"><span>Daily question ${session.index + 1} of ${session.questions.length}</span><strong>${session.correct}/${session.index} correct</strong></div><p class="eyebrow">${esc(question.type)}</p><h4 class="module-question">${esc(question.prompt)}</h4><p class="muted tiny">Select all correct answers. Extra selections make the answer incorrect.</p><div class="choices">${question.options.map((option, index) => `<div class="choice" data-option="${esc(option)}"><input type="checkbox" id="daily-opt-${index}"><label for="daily-opt-${index}"><span class="choice-box"></span><span>${esc(option)}</span></label></div>`).join('')}</div><div id="dailyFeedback"></div><div class="quiz-actions"><button class="btn btn-light" id="endDaily">End session</button><button class="btn btn-primary" id="submitDaily">Submit answer</button><button class="btn btn-dark hidden" id="nextDaily">${session.index === session.questions.length - 1 ? 'Finish daily training' : 'Next question'}</button></div>`;
    $('endDaily').addEventListener('click', () => { state.daily = null; renderDailyDashboard(); });
    $('submitDaily').addEventListener('click', submitDailyAnswer);
    $('nextDaily').addEventListener('click', () => {
      if (session.index === session.questions.length - 1) finishDailyTraining();
      else { session.index += 1; renderDailyQuestion(); }
    });
  }

  function submitDailyAnswer() {
    const session = state.daily;
    if (!session) return;
    const question = session.questions[session.index];
    const choices = [...$('dailyStage').querySelectorAll('.choice')];
    const selected = choices.filter(choice => choice.querySelector('input').checked).map(choice => choice.dataset.option);
    if (!selected.length) {
      $('dailyFeedback').innerHTML = '<div class="feedback bad">Select at least one answer.</div>';
      return;
    }
    const expected = [...question.correct].sort();
    const chosen = [...selected].sort();
    const correct = expected.length === chosen.length && expected.every((value, index) => value === chosen[index]);
    if (correct) session.correct += 1;
    choices.forEach(choice => {
      choice.querySelector('input').disabled = true;
      const value = choice.dataset.option;
      if (question.correct.includes(value) && selected.includes(value)) choice.classList.add('correct');
      else if (selected.includes(value)) choice.classList.add('wrong');
      else if (question.correct.includes(value)) choice.classList.add('missed');
    });
    session.answers.push({ question, selected, correct });
    if (!correct) recordMistake({
      source: 'Daily Training', moduleId: 'daily-training', moduleName: 'Daily Training', type: question.type, sectionId: question.sectionId,
      prompt: question.prompt, selected, correct: question.correct, explanation: question.explanation,
      positionId: session.positionId
    });
    $('dailyFeedback').innerHTML = `<div class="feedback ${correct ? 'good' : 'bad'}"><strong>${correct ? 'Correct.' : 'Not quite.'}</strong> ${esc(question.explanation)}</div>`;
    $('submitDaily').classList.add('hidden');
    $('nextDaily').classList.remove('hidden');
  }

  function dailyStreak(positionId) {
    const history = safeJson('restaurant-daily-history', []).filter(entry => entry.positionId === positionId);
    const dates = new Set(history.map(entry => entry.dateKey));
    let streak = 0;
    const cursor = new Date();
    for (;;) {
      const key = cursor.toLocaleDateString('en-CA');
      if (!dates.has(key)) break;
      streak += 1;
      cursor.setDate(cursor.getDate() - 1);
    }
    return streak;
  }

  function finishDailyTraining() {
    const session = state.daily;
    if (!session) return;
    const score = Math.round(session.correct / session.questions.length * 100);
    const history = safeJson('restaurant-daily-history', []);
    const existing = history.find(entry => entry.dateKey === session.dateKey && entry.positionId === session.positionId);
    if (existing) {
      if (score > existing.score) Object.assign(existing, { score, correct: session.correct, total: session.questions.length, date: new Date().toISOString() });
    } else {
      history.unshift({ id: uid('daily'), date: new Date().toISOString(), dateKey: session.dateKey, positionId: session.positionId, score, correct: session.correct, total: session.questions.length });
    }
    saveJson('restaurant-daily-history', history.slice(0, 365));
    window.dispatchEvent(new CustomEvent('restaurant:activity',{detail:{eventType:'daily_training.completed',sourceId:`daily-${session.positionId}-${session.dateKey}-${Date.now().toString(36)}`,occurredAt:new Date().toISOString(),summary:{score,correct:session.correct,total:session.questions.length,positionId:session.positionId,dateKey:session.dateKey}}}));
    $('dailyStage').innerHTML = `<div class="score-banner"><small>Daily training complete</small><strong>${score}%</strong><span>${session.correct} of ${session.questions.length} correct · Current streak ${dailyStreak(session.positionId)} day${dailyStreak(session.positionId) === 1 ? '' : 's'}</span></div><div class="flash-setup-actions" style="margin-top:16px"><button class="btn btn-primary" id="retakeDaily">Retake today’s training</button><button class="btn btn-light" id="dailyReviewMistakes">Review mistakes</button></div>`;
    $('retakeDaily').addEventListener('click', startDailyTraining);
    $('dailyReviewMistakes').addEventListener('click', () => clickNav('review'));
    state.daily = null;
    renderDailySummary();
    renderCertification();
  }

  function renderDailySummary() {
    const positionId = $('dailyPosition')?.value || activePositionId();
    const history = safeJson('restaurant-daily-history', []).filter(entry => entry.positionId === positionId);
    const today = history.find(entry => entry.dateKey === todayKey());
    $('dailyStreak').textContent = String(dailyStreak(positionId));
    $('dailyCompleted').textContent = String(history.length);
    $('dailyTodayScore').textContent = today ? `${today.score}%` : 'Not started';
    $('dailyStatusText').textContent = today ? `Completed today with ${today.score}%. Retakes save the best score.` : 'Today’s eight-question session is ready.';
  }

  function renderDailyDashboard() {
    if (!$('dailyStage')) return;
    $('dailyStage').innerHTML = `<div class="daily-welcome"><div class="daily-icon">☀</div><div><p class="eyebrow">Today’s focused session</p><h4>Eight questions from the selected position</h4><p id="dailyStatusText" class="muted">Today’s session is ready.</p><button class="btn btn-primary" id="startDailyTraining">Start daily training</button></div></div>`;
    $('startDailyTraining').addEventListener('click', startDailyTraining);
    renderDailySummary();
  }

  function initializeDailyTraining() {
    if (!$('dailyPosition')) return;
    $('dailyPosition').innerHTML = positions.map(position => `<option value="${esc(position.id)}">${esc(position.name)}</option>`).join('');
    $('dailyPosition').value = activePositionId();
    $('dailyPosition').addEventListener('change', renderDailyDashboard);
    renderDailyDashboard();
  }

  function unresolvedMistakes() { return safeJson('restaurant-mistakes', []).filter(mistake => !mistake.resolved); }

  function renderReviewCenter() {
    const target = $('reviewResults');
    if (!target) return;
    const all = safeJson('restaurant-mistakes', []);
    const query = $('reviewSearch')?.value.trim().toLowerCase() || '';
    const source = $('reviewSource')?.value || 'all';
    const status = $('reviewStatus')?.value || 'open';
    const list = all.filter(mistake => {
      const moduleName = mistake.moduleName || mistake.source || 'Training';
      const sectionName = sectionMap.get(mistake.sectionId)?.name || mistake.sectionId || '';
      const matchesQuery = !query || [moduleName, sectionName, mistake.context, mistake.prompt, mistake.explanation, ...(mistake.correct || []), ...(mistake.selected || [])].join(' ').toLowerCase().includes(query);
      const matchesSource = source === 'all' || moduleName === source;
      const matchesStatus = status === 'all' || (status === 'open' && !mistake.resolved) || (status === 'resolved' && mistake.resolved);
      return matchesQuery && matchesSource && matchesStatus;
    });
    $('reviewOpenCount').textContent = String(all.filter(mistake => !mistake.resolved).length);
    $('reviewResolvedCount').textContent = String(all.filter(mistake => mistake.resolved).length);
    $('reviewTotalCount').textContent = String(all.length);
    if ($('reviewModuleCount')) $('reviewModuleCount').textContent = String(unique(all.map(mistake => mistake.moduleName || mistake.source || 'Training')).length);
    const sources = unique(all.map(mistake => mistake.moduleName || mistake.source || 'Training')).sort();
    const sourceSelect = $('reviewSource');
    const selectedSource = sourceSelect.value;
    sourceSelect.innerHTML = `<option value="all">All sources</option>${sources.map(value => `<option value="${esc(value)}">${esc(value)}</option>`).join('')}`;
    sourceSelect.value = sources.includes(selectedSource) ? selectedSource : 'all';
    target.innerHTML = list.length ? list.map(mistake => { const moduleName = mistake.moduleName || mistake.source || 'Training'; return `<article class="review-card ${mistake.resolved ? 'resolved' : ''}"><div class="review-card-head"><div><span class="tag module-tag">${esc(moduleName)}</span>${mistake.sectionId ? `<span class="tag">${esc(sectionMap.get(mistake.sectionId)?.name || mistake.sectionId)}</span>` : ''}${mistake.type ? `<span class="tag">${esc(String(mistake.type).replaceAll('_',' '))}</span>` : ''}</div><span>${new Date(mistake.date).toLocaleString()}</span></div><h4>${esc(mistake.prompt)}</h4>${mistake.context ? `<p class="review-context"><strong>Module context:</strong> ${esc(mistake.context)}</p>` : ''}<div class="review-answer-grid"><div><small>Your answer</small><p>${esc((mistake.selected || []).join(', ') || 'No answer')}</p></div><div><small>Correct answer</small><p>${esc((mistake.correct || []).join(', ') || 'See explanation')}</p></div></div><p class="muted tiny">${esc(mistake.explanation || '')}</p><div class="review-actions">${mistake.resolved ? `<button class="btn btn-light" data-reopen-mistake="${esc(mistake.id)}">Reopen</button>` : `<button class="btn btn-primary" data-resolve-mistake="${esc(mistake.id)}">Mark reviewed</button>`}<button class="btn btn-soft" data-card-mistake="${esc(mistake.id)}">Make flashcard</button><button class="btn btn-danger" data-delete-mistake="${esc(mistake.id)}">Delete</button></div></article>`; }).join('') : '<div class="empty">No mistakes match these filters.</div>';
    target.querySelectorAll('[data-resolve-mistake]').forEach(button => button.addEventListener('click', () => updateMistakeStatus(button.dataset.resolveMistake, true)));
    target.querySelectorAll('[data-reopen-mistake]').forEach(button => button.addEventListener('click', () => updateMistakeStatus(button.dataset.reopenMistake, false)));
    target.querySelectorAll('[data-delete-mistake]').forEach(button => button.addEventListener('click', () => deleteMistake(button.dataset.deleteMistake)));
    target.querySelectorAll('[data-card-mistake]').forEach(button => button.addEventListener('click', () => createMistakeFlashcard(button.dataset.cardMistake)));
  }

  function updateMistakeStatus(id, resolved) {
    const mistakes = safeJson('restaurant-mistakes', []);
    const mistake = mistakes.find(entry => entry.id === id);
    if (!mistake) return;
    mistake.resolved = resolved;
    mistake.resolvedAt = resolved ? new Date().toISOString() : null;
    saveJson('restaurant-mistakes', mistakes);
    renderReviewCenter();
    renderCertification();
  }

  function deleteMistake(id) {
    const mistakes = safeJson('restaurant-mistakes', []).filter(entry => entry.id !== id);
    saveJson('restaurant-mistakes', mistakes);
    renderReviewCenter();
  }

  function createMistakeFlashcard(id) {
    const mistake = safeJson('restaurant-mistakes', []).find(entry => entry.id === id);
    if (!mistake) return;
    const cards = safeJson('restaurant-custom-flashcards', []);
    cards.unshift({
      id: uid('review-card'),
      sectionId: mistake.sectionId || activePosition().sections[0] || 'pizza',
      prompt: mistake.prompt,
      answer: `${(mistake.correct || []).join(', ')}${mistake.explanation ? `\n\n${mistake.explanation}` : ''}`,
      created: new Date().toISOString()
    });
    saveJson('restaurant-custom-flashcards', cards.slice(0, 100));
    const types = new Set(safeJson('restaurant-flash-types', ['identity','ingredients','prices','details']));
    types.add('custom');
    saveJson('restaurant-flash-types', [...types]);
    showToast('Flashcard created from mistake.', true);
  }

  function createAllMistakeFlashcards() {
    const mistakes = unresolvedMistakes().slice(0, 50);
    if (!mistakes.length) { showToast('There are no open mistakes to convert.'); return; }
    const createdAt = new Date().toISOString();
    const setCards = mistakes.map(mistake => ({
      id: uid('review-card'), mistakeId: mistake.id,
      sectionId: mistake.sectionId || activePosition().sections[0] || 'pizza',
      moduleName: mistake.moduleName || mistake.source || 'Training',
      prompt: mistake.prompt,
      answer: `${(mistake.correct || []).join(', ')}${mistake.explanation ? `\n\n${mistake.explanation}` : ''}`,
      createdAt
    }));
    const sets = safeJson('restaurant-mistake-flashcard-sets', []);
    const modules = unique(setCards.map(card => card.moduleName));
    const name = `Mistake Review · ${new Date(createdAt).toLocaleDateString()} · ${setCards.length} cards`;
    sets.unshift({id: uid('mistake-deck'), name, createdAt, modules, cards: setCards});
    saveJson('restaurant-mistake-flashcard-sets', sets.slice(0, 30));
    const cards = safeJson('restaurant-custom-flashcards', []);
    setCards.slice().reverse().forEach(card => cards.unshift({id: card.id, sectionId: card.sectionId, prompt: card.prompt, answer: card.answer, created: createdAt, mistakeSetId: sets[0].id}));
    saveJson('restaurant-custom-flashcards', cards.slice(0, 200));
    const types = new Set(safeJson('restaurant-flash-types', ['identity','ingredients','prices','details']));
    types.add('custom'); saveJson('restaurant-flash-types', [...types]);
    window.dispatchEvent(new CustomEvent('restaurant:mistake-flashcard-sets-updated', {detail:{setId:sets[0].id,count:setCards.length}}));
    showToast(`${setCards.length} review flashcards saved as a study set.`, true);
    clickNav('flashcards');
  }

  function initializeReviewCenter() {
    ['reviewSearch','reviewSource','reviewStatus'].forEach(id => $(id)?.addEventListener(id === 'reviewSearch' ? 'input' : 'change', renderReviewCenter));
    $('reviewMakeCards')?.addEventListener('click', createAllMistakeFlashcards);
    $('reviewResolveAll')?.addEventListener('click', () => {
      const mistakes = safeJson('restaurant-mistakes', []).map(mistake => ({ ...mistake, resolved: true, resolvedAt: mistake.resolvedAt || new Date().toISOString() }));
      saveJson('restaurant-mistakes', mistakes);
      renderReviewCenter();
      renderCertification();
    });
    renderReviewCenter();
  }

  const CERT_REQUIREMENTS = [
    { id: 'quiz', name: 'Formal menu quiz', description: 'Best role-based quiz score', target: position => ['trainer','manager'].includes(position.id) ? 90 : 80 },
    { id: 'flash', name: 'Flashcard mastery', description: 'At least 20 marked cards and 75% confidence', target: () => 75 },
    { id: 'daily', name: 'Daily training', description: 'Complete three daily sessions', target: () => 3 },
    { id: 'kitchen', name: 'Kitchen verification', description: 'Pass two kitchen ticket sessions', target: () => 2 },
    { id: 'review', name: 'Mistake review', description: 'Five or fewer unresolved mistakes', target: () => 5 }
  ];

  function certificationMetrics(positionId) {
    const position = positions.find(entry => entry.id === positionId) || activePosition();
    const quizzes = safeJson('restaurant-quiz-history', []).filter(entry => entry.positionId === positionId);
    const quizBest = quizzes.length ? Math.max(...quizzes.map(entry => Number(entry.percent || 0))) : 0;
    const flashStats = safeJson('restaurant-flash-stats', { total: 0, known: 0, cards: {} });
    const flashRate = flashStats.total ? Math.round(Number(flashStats.known || 0) / flashStats.total * 100) : 0;
    const daily = safeJson('restaurant-daily-history', []).filter(entry => entry.positionId === positionId);
    const kitchen = safeJson('restaurant-kitchen-results', []).filter(entry => entry.positionId === positionId && entry.passed);
    const openMistakes = safeJson('restaurant-mistakes', []).filter(entry => entry.positionId === positionId && !entry.resolved).length;
    return {
      position,
      quiz: { value: quizBest, target: CERT_REQUIREMENTS[0].target(position), complete: quizBest >= CERT_REQUIREMENTS[0].target(position), display: `${quizBest}%` },
      flash: { value: flashRate, target: 75, complete: Number(flashStats.total || 0) >= 20 && flashRate >= 75, display: `${flashRate}% · ${flashStats.total || 0} cards` },
      daily: { value: daily.length, target: 3, complete: daily.length >= 3, display: `${daily.length}/3` },
      kitchen: { value: kitchen.length, target: 2, complete: kitchen.length >= 2, display: `${kitchen.length}/2` },
      review: { value: openMistakes, target: 5, complete: openMistakes <= 5, display: `${openMistakes} open` }
    };
  }

  function renderCertification() {
    if (!$('certPositionList')) return;
    if (!positions.some(position => position.id === state.certificationPositionId)) state.certificationPositionId = activePositionId();
    $('certPositionList').innerHTML = positions.map(position => {
      const metrics = certificationMetrics(position.id);
      const completed = CERT_REQUIREMENTS.filter(requirement => metrics[requirement.id].complete).length;
      return `<button class="cert-position-button ${position.id === state.certificationPositionId ? 'active' : ''}" data-cert-position="${esc(position.id)}"><span>${esc(position.icon)}</span><div><strong>${esc(position.name)}</strong><small>${completed}/${CERT_REQUIREMENTS.length} requirements</small></div></button>`;
    }).join('');
    $('certPositionList').querySelectorAll('[data-cert-position]').forEach(button => button.addEventListener('click', () => {
      state.certificationPositionId = button.dataset.certPosition;
      renderCertification();
    }));
    const metrics = certificationMetrics(state.certificationPositionId);
    const completed = CERT_REQUIREMENTS.filter(requirement => metrics[requirement.id].complete).length;
    const percent = Math.round(completed / CERT_REQUIREMENTS.length * 100);
    const issued = safeJson('restaurant-certifications', []).find(entry => entry.positionId === state.certificationPositionId);
    $('certificationDetail').innerHTML = `<div class="cert-hero"><div><p class="eyebrow">Position certification path</p><h3>${esc(metrics.position.icon)} ${esc(metrics.position.name)}</h3><p>${esc(metrics.position.summary)}</p></div><div class="cert-score"><strong>${percent}%</strong><span>${completed}/${CERT_REQUIREMENTS.length} complete</span></div></div><div class="cert-requirements">${CERT_REQUIREMENTS.map(requirement => {
      const metric = metrics[requirement.id];
      const progress = requirement.id === 'review' ? (metric.complete ? 100 : Math.max(0, 100 - metric.value * 10)) : clamp(metric.value / metric.target * 100, 0, 100);
      return `<article class="cert-requirement ${metric.complete ? 'complete' : ''}"><div class="cert-check">${metric.complete ? '✓' : '○'}</div><div><h4>${esc(requirement.name)}</h4><p>${esc(requirement.description)}</p><div class="cert-progress"><i style="width:${progress}%"></i></div></div><strong>${esc(metric.display)}</strong></article>`;
    }).join('')}</div><div class="cert-actions">${percent === 100 ? `<button class="btn btn-primary" id="issueCertificate">${issued ? 'Reissue local certificate' : 'Issue local certificate'}</button>` : '<span class="muted tiny">Complete every requirement to issue a local certificate.</span>'}<button class="btn btn-light" id="startCertificationDaily">Continue with daily training</button><button class="btn btn-soft" id="openCertificationReview">Open mistake review</button></div>${issued ? `<div class="issued-certificate"><span>Local certificate issued</span><strong>${new Date(issued.date).toLocaleDateString()}</strong><small>Browser-only record · ${esc(metrics.position.name)}</small></div>` : ''}`;
    $('issueCertificate')?.addEventListener('click', () => issueCertificate(metrics.position));
    $('startCertificationDaily').addEventListener('click', () => {
      setText('restaurant-active-position', metrics.position.id);
      const positionButton=[...document.querySelectorAll('.position-btn')].find(button=>button.querySelector('strong')?.textContent.trim()===metrics.position.name);
      positionButton?.click();
      clickNav('daily');
      window.setTimeout(() => { if ($('dailyPosition')) { $('dailyPosition').value = metrics.position.id; renderDailyDashboard(); } }, 0);
    });
    $('openCertificationReview').addEventListener('click', () => clickNav('review'));
  }

  function issueCertificate(position) {
    const certifications = safeJson('restaurant-certifications', []).filter(entry => entry.positionId !== position.id);
    certifications.unshift({ id: uid('cert'), positionId: position.id, positionName: position.name, date: new Date().toISOString() });
    saveJson('restaurant-certifications', certifications);
    const issued=certifications[0];
    window.dispatchEvent(new CustomEvent('restaurant:activity',{detail:{eventType:'certification.issued',sourceId:issued.id,occurredAt:issued.date,summary:{positionId:issued.positionId,positionName:issued.positionName}}}));
    renderCertification();
    showToast(`${position.name} certificate issued locally.`, true);
  }

  function initializeCertification() { renderCertification(); }

  function openIngredientDetail(name) {
    const entry = ingredientMap.get(normalizeIngredient(name));
    if (!entry || !$('ingredientDetailModal')) return;
    state.ingredientDetail = entry.name;
    const profile = allergenProfileForIngredient(entry.name);
    const grouped = entry.uses.reduce((map, item) => {
      map[item.sectionName] ||= [];
      map[item.sectionName].push(item);
      return map;
    }, {});
    const relevantPositions = positions.filter(position => entry.sections.some(sectionId => position.sections.includes(sectionId)));
    $('ingredientDetailBody').innerHTML = `<div class="ingredient-detail-hero"><div><p class="eyebrow">Ingredient detail</p><h3>${esc(entry.name)}</h3><p>${entry.uses.length} listed menu use${entry.uses.length === 1 ? '' : 's'} across ${entry.sections.length} categor${entry.sections.length === 1 ? 'y' : 'ies'}.</p></div><div class="ingredient-detail-count">${entry.uses.length}</div></div><div class="ingredient-detail-grid"><section><h4>Menu usage</h4>${Object.entries(grouped).map(([sectionName, items]) => `<div class="ingredient-use-group"><strong>${esc(sectionName)}</strong>${items.map(item => `<button data-detail-item="${esc(item.key)}">${esc(item.name)}</button>`).join('')}</div>`).join('')}</section><section><h4>Allergen and verification tags</h4><div class="allergen-badges">${profile.direct.map(id => `<span class="tag allergen-direct">Indicator: ${esc(allergenName(id))}</span>`).join('')}${profile.verify.map(id => `<span class="tag allergen-verify">Verify: ${esc(allergenName(id))}</span>`).join('')}${!profile.direct.length && !profile.verify.length ? '<span class="tag allergen-clear">No direct named major allergen</span>' : ''}</div><div class="warning compact"><div class="warning-icon">⚠</div><div><strong>Verification still required</strong><p>Ingredient naming does not confirm supplier formulation, substitutions, recipes, or cross-contact controls.</p></div></div><h4>Known menu wording</h4><div class="tag-row">${(entry.variants.length ? entry.variants : [entry.name]).map(value => `<span class="tag">${esc(value)}</span>`).join('')}</div><h4>Position relevance</h4><div class="tag-row">${relevantPositions.map(position => `<span class="tag">${esc(position.name)}</span>`).join('')}</div></section></div><div class="ingredient-detail-actions"><button class="btn btn-primary" id="ingredientDetailFlashcard">Create ingredient flashcard</button><button class="btn btn-dark" id="ingredientDetailAgent">Ask local agent</button></div>`;
    $('ingredientDetailModal').classList.add('open');
    document.body.classList.add('modal-open');
    $('ingredientDetailBody').querySelectorAll('[data-detail-item]').forEach(button => button.addEventListener('click', () => {
      const item = itemMap.get(button.dataset.detailItem);
      closeIngredientDetail();
      if (!item) return;
      clickNav('workspace');
      window.setTimeout(() => {
        $('chatInput').value = `What are the ingredients in ${item.name}?`;
        $('sendChat').click();
      }, 0);
    }));
    $('ingredientDetailFlashcard').addEventListener('click', () => createIngredientFlashcard(entry));
    $('ingredientDetailAgent').addEventListener('click', () => {
      closeIngredientDetail();
      clickNav('workspace');
      window.setTimeout(() => {
        $('chatInput').value = `Which menu items contain ${entry.name}?`;
        $('sendChat').click();
      }, 0);
    });
  }

  function createIngredientFlashcard(entry) {
    const cards = safeJson('restaurant-custom-flashcards', []);
    cards.unshift({
      id: uid('ingredient-card'),
      sectionId: entry.sections[0] || 'pizza',
      prompt: `Which menu items list ${entry.name}?`,
      answer: entry.uses.map(item => `${item.sectionName} · ${item.name}`).join('\n'),
      created: new Date().toISOString()
    });
    saveJson('restaurant-custom-flashcards', cards.slice(0, 100));
    const types = new Set(safeJson('restaurant-flash-types', ['identity','ingredients','prices','details']));
    types.add('custom');
    saveJson('restaurant-flash-types', [...types]);
    showToast('Ingredient flashcard created.', true);
  }

  function closeIngredientDetail() {
    $('ingredientDetailModal')?.classList.remove('open');
    document.body.classList.remove('modal-open');
    state.ingredientDetail = null;
  }

  function decorateIngredientCards() {
    const target = $('ingredientResults');
    if (!target) return;
    target.querySelectorAll('.ingredient-card').forEach(card => {
      if (card.querySelector('.ingredient-detail-button')) return;
      const name = card.querySelector('h5')?.textContent?.trim();
      if (!name) return;
      const button = document.createElement('button');
      button.className = 'ingredient-detail-button';
      button.type = 'button';
      button.textContent = 'View details →';
      button.dataset.ingredientDetail = name;
      card.appendChild(button);
    });
  }

  function initializeIngredientDetails() {
    const target = $('ingredientResults');
    if (!target) return;
    const observer = new MutationObserver(decorateIngredientCards);
    observer.observe(target, { childList: true, subtree: true });
    decorateIngredientCards();
    target.addEventListener('click', event => {
      const button = event.target.closest('[data-ingredient-detail]');
      if (button) openIngredientDetail(button.dataset.ingredientDetail);
    });
    $('closeIngredientDetail')?.addEventListener('click', closeIngredientDetail);
    $('ingredientDetailModal')?.addEventListener('click', event => {
      if (event.target === $('ingredientDetailModal')) closeIngredientDetail();
    });
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape' && $('ingredientDetailModal')?.classList.contains('open')) closeIngredientDetail();
    });
  }

  function renderAdvancedProgress() {
    window.renderRestaurantDashboard?.();
  }

  function updateAdvancedPage(page) {
    if (page === 'certifications') renderCertification();
    if (page === 'daily') state.daily ? renderDailyQuestion() : renderDailyDashboard();
    if (page === 'kitchen') state.kitchen ? renderKitchenScenario() : renderKitchenIdle();
    if (page === 'review') renderReviewCenter();
    if (page === 'dashboard') renderAdvancedProgress();
  }

  function initializePageHooks() {
    document.querySelectorAll('[data-nav]').forEach(button => button.addEventListener('click', () => updateAdvancedPage(button.dataset.nav)));
  }

  function initializeKitchen() {
    $('startKitchen')?.addEventListener('click', startKitchenSession);
    renderKitchenIdle();
  }

  function initializeFlashDueSummary() {
    const update = () => {
      const stats = safeJson('restaurant-flash-stats', { cards: {} });
      const records = Object.values(stats.cards || {});
      const due = records.filter(record => !record.due || new Date(record.due).getTime() <= Date.now()).length;
      const mastered = records.filter(record => Number(record.box || 0) >= 4).length;
      if ($('flashDueCount')) $('flashDueCount').textContent = String(due);
      if ($('flashMasteredCount')) $('flashMasteredCount').textContent = String(mastered);
    };
    update();
    document.querySelector('[data-nav="flashcards"]')?.addEventListener('click', update);
  }

  function initialize() {
    initializePageHooks();
    initializeKitchen();
    initializeDailyTraining();
    initializeReviewCenter();
    initializeCertification();
    initializeIngredientDetails();
    initializeFlashDueSummary();
    renderAdvancedProgress();
  }

  initialize();
})();
