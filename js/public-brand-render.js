(() => {
  'use strict';
  function imageUrl(value) {
    if (typeof value !== 'string' || !value.trim()) return null;
    try {
      const url = new URL(value, document.baseURI);
      return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password ? url.href : null;
    } catch { return null; }
  }
  function renderLogo(element, brand, fallbackName, fallbackText) {
    if (!element) return;
    const url = imageUrl(brand.logoUrl);
    if (!url) { element.textContent = String(brand.logoText || fallbackText); return; }
    const image = document.createElement('img');
    image.src = url;
    image.alt = `${String(brand.restaurantName || fallbackName)} logo`;
    element.replaceChildren(image);
  }
  window.GelatoPublicBrand = {imageUrl, renderLogo};
})();
