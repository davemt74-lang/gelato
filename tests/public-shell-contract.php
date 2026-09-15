<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$marker = '<script src="assets/js/public-shell.js?v=20260915-1"></script>';
$publicPages = [
    'index.php', 'menu.php', 'gelato.php', 'about.php', 'contact.php', 'locations.php',
    'online-order.php', 'customer-signup.php', 'customer-login.php', 'customer-account.php',
    'jobs.html', 'job.php', 'apply.html', 'catering.php', 'wholesale.php',
    'login.php', 'forgot-password.php', 'reset-password.php',
];

foreach ($publicPages as $page) {
    $path = $root . '/' . $page;
    if (!is_file($path)) {
        throw new RuntimeException('Missing public page: ' . $page);
    }
    $source = file_get_contents($path) ?: '';
    if (!str_contains($source, $marker)) {
        throw new RuntimeException('Public page does not mount the universal shell: ' . $page);
    }
}

$shell = file_get_contents($root . '/assets/js/public-shell.js') ?: '';
foreach ([
    "navLink('home', 'index.php', 'Home')",
    "navLink('menu', 'menu.php', 'Menu')",
    "navLink('gelato', 'gelato.php', 'Gelato')",
    "navLink('about', 'about.php', 'About')",
    "navLink('locations', 'locations.php', 'Locations')",
    'id="publicCartLink"',
    'id="cartHeaderCount"',
    'href="online-order.php"',
    'href="customer-account.php">Account</a>',
] as $needle) {
    if (!str_contains($shell, $needle)) {
        throw new RuntimeException('Universal public header contract missing: ' . $needle);
    }
}
if (str_contains($shell, "navLink('contact'")) {
    throw new RuntimeException('Contact must not appear in the universal primary header navigation.');
}

foreach ([
    '<a href="contact.php">Contact</a>',
    '<a href="wholesale.php">Wholesale</a>',
    '<a href="catering.php">Catering</a>',
    '<a href="jobs.html">Jobs</a>',
    '<a href="login.php">Admin / Employee Login</a>',
] as $needle) {
    if (!str_contains($shell, $needle)) {
        throw new RuntimeException('Universal public footer contract missing: ' . $needle);
    }
}
foreach ([
    '<a href="menu.php">Menu</a>',
    '<a href="gelato.php">Gelato</a>',
    '<a href="about.php">About</a>',
    '<a href="locations.php">Locations</a>',
] as $duplicate) {
    $footerStart = strpos($shell, 'function footerHtml');
    $footerSource = $footerStart === false ? '' : substr($shell, $footerStart);
    if (str_contains($footerSource, $duplicate)) {
        throw new RuntimeException('Primary navigation link duplicated in universal footer: ' . $duplicate);
    }
}

$api = file_get_contents($root . '/api/public-shell.php') ?: '';
foreach (['public_site_context', 'public_site_format_address', 'public_site_social_links', 'hoursText'] as $needle) {
    if (!str_contains($api, $needle)) {
        throw new RuntimeException('Public shell data endpoint contract missing: ' . $needle);
    }
}

$css = file_get_contents($root . '/assets/css/public-shell.css') ?: '';
foreach (['.public-shell-header', '.public-shell-footer', '.public-shell-cart', '.public-shell-nav', '.public-shell-account'] as $needle) {
    if (!str_contains($css, $needle)) {
        throw new RuntimeException('Universal public shell style contract missing: ' . $needle);
    }
}

echo "PASS: universal Stonefellows public header/footer and cart contract verified across all rendered public pages.\n";
