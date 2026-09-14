<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/menu-sync.php';

function public_site_defaults(): array
{
    return [
        'organization_id' => 0,
        'restaurant_name' => 'Stonefellows',
        'legal_name' => '',
        'tagline' => 'Wood-Fired Pizza. Craft Drinks. Good Company.',
        'hours_text' => '',
        'address_line_1' => '',
        'address_line_2' => '',
        'city' => '',
        'state' => '',
        'postal_code' => '',
        'phone' => '',
        'email' => '',
        'instagram_url' => '',
        'facebook_url' => '',
        'tiktok_url' => '',
        'youtube_url' => '',
        'x_url' => '',
    ];
}

function public_site_fallback_context(): array
{
    return [
        'organizationId' => 0,
        'settings' => public_site_defaults(),
        'locations' => [],
        'menuSections' => [],
        'dataAvailable' => false,
        'dataMessage' => 'Live restaurant data is temporarily unavailable.',
    ];
}

function public_site_organization_id(PDO $pdo): int
{
    $statement = $pdo->query(
        "SELECT o.id
         FROM organizations o
         WHERE o.status = 'active'
         ORDER BY
           EXISTS(
             SELECT 1 FROM menu_sections ms
             WHERE ms.organization_id = o.id AND ms.status = 'active'
           ) DESC,
           EXISTS(
             SELECT 1 FROM brand_settings bs
             WHERE bs.organization_id = o.id
           ) DESC,
           o.id ASC
         LIMIT 1"
    );
    $organizationId = (int)$statement->fetchColumn();
    if ($organizationId < 1) {
        throw new RuntimeException('No active restaurant organization is configured.');
    }
    return $organizationId;
}

function public_site_load_settings(PDO $pdo, int $organizationId): array
{
    $settings = public_site_defaults();
    $settings['organization_id'] = $organizationId;

    try {
        $brandStatement = $pdo->prepare(
            'SELECT restaurant_name, legal_name, theme_settings_json, contact_settings_json FROM brand_settings WHERE organization_id = ? LIMIT 1'
        );
        $brandStatement->execute([$organizationId]);
        $brand = $brandStatement->fetch() ?: [];
        $theme = [];
        $contact = [];
        try {
            $theme = json_decode((string)($brand['theme_settings_json'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $theme = [];
        }
        try {
            $contact = json_decode((string)($brand['contact_settings_json'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $contact = [];
        }
        if (!empty($brand['restaurant_name'])) {
            $settings['restaurant_name'] = (string)$brand['restaurant_name'];
        }
        $settings['legal_name'] = (string)($brand['legal_name'] ?? '');
        $settings['tagline'] = trim((string)($theme['tagline'] ?? $theme['description'] ?? $settings['tagline'])) ?: $settings['tagline'];
        foreach (['email', 'phone', 'address'] as $key) {
            if (!empty($contact[$key])) {
                if ($key === 'address') {
                    $settings['address_line_1'] = trim((string)$contact[$key]);
                } else {
                    $settings[$key] = trim((string)$contact[$key]);
                }
            }
        }
    } catch (Throwable) {
        // Brand settings are optional for the public-site fallback path.
    }

    try {
        $statement = $pdo->prepare(
            'SELECT tagline, hours_text, address_line_1, address_line_2, city, state, postal_code,
                    phone, email, instagram_url, facebook_url, tiktok_url, youtube_url, x_url
             FROM public_site_settings
             WHERE organization_id = ? LIMIT 1'
        );
        $statement->execute([$organizationId]);
        $row = $statement->fetch();
        if ($row) {
            foreach ($row as $key => $value) {
                if ($value !== null && trim((string)$value) !== '') {
                    $settings[$key] = trim((string)$value);
                }
            }
        }
    } catch (Throwable) {
        // Migration may not have been run yet. Public pages continue with brand/location defaults.
    }

    return $settings;
}

function public_site_load_locations(PDO $pdo, int $organizationId): array
{
    try {
        $statement = $pdo->prepare(
            "SELECT id, name, address_line_1, address_line_2, city, state, postal_code, country_code, phone
             FROM locations
             WHERE organization_id = ? AND status = 'active'
             ORDER BY id ASC"
        );
        $statement->execute([$organizationId]);
        return $statement->fetchAll() ?: [];
    } catch (Throwable) {
        return [];
    }
}

function public_site_context(PDO $pdo): array
{
    $organizationId = public_site_organization_id($pdo);
    $settings = public_site_load_settings($pdo, $organizationId);
    $locations = public_site_load_locations($pdo, $organizationId);
    $menuSections = [];
    $menuMessage = '';

    try {
        $menuSections = menu_database_sections($pdo, $organizationId);
        if (!$menuSections) {
            $menuMessage = 'The live menu has not been published yet.';
        }
    } catch (Throwable $exception) {
        error_log('Public site menu load failed: ' . $exception->getMessage());
        $menuMessage = 'The live menu is temporarily unavailable.';
    }

    if ($settings['address_line_1'] === '' && $locations) {
        $first = $locations[0];
        foreach (['address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'phone'] as $key) {
            if (!empty($first[$key]) && empty($settings[$key])) {
                $settings[$key] = (string)$first[$key];
            }
        }
    }

    return [
        'organizationId' => $organizationId,
        'settings' => $settings,
        'locations' => $locations,
        'menuSections' => $menuSections,
        'dataAvailable' => true,
        'dataMessage' => $menuMessage,
    ];
}

function public_site_section(array $sections, string $needle): ?array
{
    $needle = mb_strtolower(trim($needle), 'UTF-8');
    foreach ($sections as $section) {
        $id = mb_strtolower((string)($section['id'] ?? ''), 'UTF-8');
        $name = mb_strtolower((string)($section['name'] ?? ''), 'UTF-8');
        if ($id === $needle || str_contains($id, $needle) || str_contains($name, $needle)) {
            return $section;
        }
    }
    return null;
}

function public_site_featured_items(array $section, int $limit = 4): array
{
    $items = is_array($section['items'] ?? null) ? $section['items'] : [];
    usort($items, static function (array $a, array $b): int {
        $featuredCompare = ((int)!empty($b['featured'])) <=> ((int)!empty($a['featured']));
        return $featuredCompare !== 0 ? $featuredCompare : 0;
    });
    return array_slice($items, 0, max(0, $limit));
}

function public_site_price(array $item): string
{
    $raw = trim((string)($item['rawPrice'] ?? ''));
    if ($raw !== '') {
        return $raw;
    }
    $prices = is_array($item['prices'] ?? null) ? $item['prices'] : [];
    if (!$prices) {
        return '';
    }
    $formatted = [];
    foreach ($prices as $price) {
        $amount = isset($price['price']) ? (float)$price['price'] : null;
        if ($amount === null) {
            continue;
        }
        $formatted[] = '$' . number_format($amount, $amount == floor($amount) ? 0 : 2);
    }
    return implode(' / ', array_values(array_unique($formatted)));
}

function public_site_format_address(array $source): string
{
    $street = trim(implode(' ', array_filter([
        trim((string)($source['address_line_1'] ?? '')),
        trim((string)($source['address_line_2'] ?? '')),
    ])));
    $locality = trim(implode(', ', array_filter([
        trim((string)($source['city'] ?? '')),
        trim((string)($source['state'] ?? '')),
    ])));
    $postal = trim((string)($source['postal_code'] ?? ''));
    if ($postal !== '') {
        $locality = trim($locality . ' ' . $postal);
    }
    return trim(implode(', ', array_filter([$street, $locality])));
}

function public_site_social_links(array $settings): array
{
    $map = [
        'instagram_url' => 'Instagram',
        'facebook_url' => 'Facebook',
        'tiktok_url' => 'TikTok',
        'youtube_url' => 'YouTube',
        'x_url' => 'X',
    ];
    $links = [];
    foreach ($map as $key => $label) {
        $url = trim((string)($settings[$key] ?? ''));
        if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
            $links[$label] = $url;
        }
    }
    return $links;
}

function public_site_asset(string $file): string
{
    return 'assets/images/' . ltrim($file, '/');
}

function public_site_render_header(array $settings, string $active = ''): void
{
    $name = app_escape((string)($settings['restaurant_name'] ?? 'Stonefellows'));
    $links = [
        'home' => ['index.php', 'Home'],
        'menu' => ['menu.php', 'Menu'],
        'gelato' => ['gelato.php', 'Gelato'],
        'about' => ['about.php', 'About'],
        'locations' => ['locations.php', 'Locations'],
        'contact' => ['contact.php', 'Contact'],
    ];
    echo '<header class="site-header' . ($active === 'home' ? '' : ' inner') . '"><div class="shell nav" id="nav">';
    echo '<a class="brand" href="index.php"><strong>' . $name . '</strong><span>Pizzeria + Bar</span></a>';
    echo '<nav class="nav-links" aria-label="Primary navigation">';
    foreach ($links as $key => [$href, $label]) {
        $class = $active === $key ? ' class="active"' : '';
        echo '<a' . $class . ' href="' . app_escape($href) . '">' . app_escape($label) . '</a>';
    }
    echo '</nav><a class="nav-cta" href="contact.php">Get in Touch</a>';
    echo '<button class="menu-toggle" id="menuToggle" type="button" aria-label="Open navigation" aria-expanded="false">☰</button>';
    echo '</div></header>';
}

function public_site_render_footer(array $settings): void
{
    $address = public_site_format_address($settings);
    $socials = public_site_social_links($settings);
    $footerLinks = [
        'Menu' => 'menu.php',
        'Gelato' => 'gelato.php',
        'About' => 'about.php',
        'Locations' => 'locations.php',
        'Contact' => 'contact.php',
        'Jobs' => 'jobs.html',
        'Catering' => 'catering.php',
        'Wholesale' => 'wholesale.php',
    ];

    echo '<footer><div class="shell footer-grid">';
    echo '<div class="footer-brand"><strong>' . app_escape((string)($settings['restaurant_name'] ?? 'Stonefellows')) . '</strong><span>Pizzeria + Bar</span></div>';
    echo '<div><strong class="footer-column-title">Links</strong><nav class="footer-links" aria-label="Footer links">';
    foreach ($footerLinks as $label => $href) {
        echo '<a href="' . app_escape($href) . '">' . app_escape($label) . '</a>';
    }
    echo '</nav></div>';
    echo '<div><strong class="footer-column-title">Visit</strong>' . ($address !== '' ? app_escape($address) : 'Location details coming soon') . '</div>';
    echo '<div><strong class="footer-column-title">Hours</strong>' . ($settings['hours_text'] !== '' ? nl2br(app_escape((string)$settings['hours_text'])) : 'Hours coming soon') . '</div>';
    echo '<div><strong class="footer-column-title">Follow</strong><div class="socials" aria-label="Social links">';
    foreach ($socials as $label => $url) {
        echo '<a href="' . app_escape($url) . '" target="_blank" rel="noopener noreferrer">' . app_escape($label) . '</a>';
    }
    if (!$socials) {
        echo '<span>Social links coming soon</span>';
    }
    echo '</div></div></div></footer>';
}
