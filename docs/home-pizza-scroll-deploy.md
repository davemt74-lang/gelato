# Rolling pizza homepage deploy

The production deploy package for this change is a direct web-root patch containing:

- `index.php`
- `assets/css/home-pizza-scroll.css`
- `assets/js/home-pizza-scroll.js`
- five optimized transparent rolling-pizza WebP assets under `assets/images/`

No database migration is required. The PHP page continues to read pizza names, descriptions, pricing, toppings and notes from the canonical menu data. If any dedicated rolling asset is absent, the page falls back to the existing homepage pizza artwork instead of breaking.
