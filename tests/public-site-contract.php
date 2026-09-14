<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$required = [
    'public/index.php', 'public/menu.php', 'public/gelato.php', 'public/about.php',
    'public/contact.php', 'public/locations.php', 'public/assets/css/site.css',
    'public/assets/js/site.js', 'public/assets/images/README.md', 'includes/public-site.php',
    'public-site-settings.php', 'database/20261001_public_site_settings.sql',
];
foreach ($required as $path) {
    if (!is_file($root . '/' . $path)) {
        throw new RuntimeException('Missing required public-site file: ' . $path);
    }
}

$helper = file_get_contents($root . '/includes/public-site.php') ?: '';
if (!str_contains($helper, 'menu_database_sections')) {
    throw new RuntimeException('Public site must read the canonical menu database helper.');
}
if (!str_contains($helper, "status = 'active'")) {
    throw new RuntimeException('Public site must scope to active restaurant data.');
}

foreach (['public/index.php', 'public/menu.php', 'public/gelato.php'] as $path) {
    $source = file_get_contents($root . '/' . $path) ?: '';
    if (str_contains($source, 'api/menu.php') || preg_match('/fetch\s*\(/', $source)) {
        throw new RuntimeException($path . ' must not depend on the authenticated menu API.');
    }
}

$settings = file_get_contents($root . '/public-site-settings.php') ?: '';
foreach (['app_require_auth', 'app_verify_csrf', 'public_pages.edit', 'public_site_settings'] as $needle) {
    if (!str_contains($settings, $needle)) {
        throw new RuntimeException('Admin settings contract missing: ' . $needle);
    }
}

$migration = file_get_contents($root . '/database/20261001_public_site_settings.sql') ?: '';
if (!str_contains($migration, 'CREATE TABLE IF NOT EXISTS public_site_settings')) {
    throw new RuntimeException('Public-site settings migration is missing its canonical table.');
}

$assets = file_get_contents($root . '/public/assets/images/README.md') ?: '';
foreach (['hero.jpg', 'card-pizza.jpg', 'gelato.jpg', 'favorite-stonefellow.jpg', 'favorite-burrata.jpg'] as $asset) {
    if (!str_contains($assets, $asset)) {
        throw new RuntimeException('Missing documented image asset: ' . $asset);
    }
}

echo "PASS: Stonefellows public-site contract verified.\n";
