# Stonefellows public-site images

Production presentation assets belong at the root public path `/assets/images/`:

- `hero.jpg`
- `pizza-scroll-feature.webp`
- `pizza-scroll-margherita.webp`
- `pizza-scroll-pepperoni.webp`
- `pizza-scroll-sausage-mushroom.webp`
- `pizza-scroll-vegetable.webp`
- `pizza-scroll-meat-lovers.webp`
- `card-pizza.jpg`
- `card-drinks.jpg`
- `card-music.jpg`
- `card-reservations.jpg`
- `story-one.jpg`
- `story-two.jpg`
- `gelato.jpg`
- `favorite-stonefellow.jpg`
- `favorite-funghi.jpg`
- `favorite-spicy.jpg`
- `favorite-burrata.jpg`

The five dedicated `pizza-scroll-*` WebPs are transparent rolling-story assets. `index.php` checks for them individually and falls back to the legacy homepage pizza artwork if they are not installed, so code-only deployments remain safe.

Menu names, descriptions, prices, toppings and featured selection remain database-driven. These files are presentation assets only.
