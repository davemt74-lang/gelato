# Homepage pizza scroll assets

The rolling homepage pizza story uses these optional production assets when present in `assets/images/`:

- `pizza-scroll-margherita.webp`
- `pizza-scroll-pepperoni.webp`
- `pizza-scroll-sausage-mushroom.webp`
- `pizza-scroll-vegetable.webp`
- `pizza-scroll-meat-lovers.webp`

`index.php` checks for each asset at runtime and falls back to the existing homepage pizza artwork when an asset is not installed, so the branch remains deploy-safe without the binary files.

The production deploy package for this change includes all five optimized transparent WebP files and should be extracted into the application web root while preserving the `assets/images/` directory.
