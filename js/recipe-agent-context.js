(() => {
  'use strict';
  if (window.GelatoRecipeAgentContext) return;

  const state = {selectedRecipePublicId: '', recipeLabel: ''};
  const page = (location.pathname.split('/').pop() || '').toLowerCase();
  const clean = (value, max = 160) => String(value ?? '')
    .replace(/[\u0000-\u001f\u007f]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, max);
  const cleanId = (value) => /^[A-Za-z0-9._:-]{1,160}$/.test(String(value || '').trim()) ? String(value).trim() : '';

  function detectRecipe() {
    const queryId = cleanId(new URLSearchParams(location.search).get('id'));
    if (queryId) return queryId;
    const active = document.querySelector('.recipe-card.active[data-id]');
    return cleanId(active?.dataset?.id || active?.getAttribute('data-id') || '');
  }

  function detectLabel() {
    const heading = document.querySelector('#editor .hero h1')?.textContent?.trim();
    return clean(heading || '', 160);
  }

  function snapshot() {
    state.selectedRecipePublicId = detectRecipe();
    state.recipeLabel = detectLabel();
    return {
      module: 'recipes',
      route: page || 'recipes.php',
      pageTitle: 'Recipe + Production Standards',
      selectedRecipePublicId: state.selectedRecipePublicId || null,
      recipeLabel: state.recipeLabel || null,
    };
  }

  function transportSnapshot() {
    const current = snapshot();
    return {module: current.module, route: current.route, selectedRecipePublicId: current.selectedRecipePublicId};
  }

  function description(context = snapshot()) {
    return context.recipeLabel ? `Recipe · ${context.recipeLabel}` : 'Recipe + Production Standards';
  }

  function placeholder(context = snapshot()) {
    return context.selectedRecipePublicId
      ? 'Ask Gelato about this recipe, scaling, ingredients, method, allergens, or production standards…'
      : 'Ask Gelato about recipes, scaling, production standards, ingredients, or recipe gaps…';
  }

  function publish() { return window.GelatoAgentPageContext?.publish?.() || snapshot(); }
  function register() { return !!window.GelatoAgentPageContext?.register?.(provider); }

  const provider = {module: 'recipes', snapshot, transportSnapshot, description, placeholder};
  window.GelatoRecipeAgentContext = {state, provider, snapshot, transportSnapshot, description, placeholder, publish, sync: publish, register};

  window.addEventListener('gelato-agent-ready', () => { register(); publish(); });
  document.addEventListener('DOMContentLoaded', () => { register(); publish(); }, {once: true});
  document.addEventListener('click', (event) => {
    if (event.target.closest?.('.recipe-card[data-id],[data-recipe-agent-prompt]')) {
      setTimeout(publish, 0);
      setTimeout(publish, 180);
    }
  });
  document.addEventListener('gelato-recipe-selected', () => publish());

  register();
  publish();
})();
