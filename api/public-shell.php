<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/public-site.php';

$context = public_site_fallback_context();
try {
    $context = public_site_context(app_pdo());
} catch (Throwable $exception) {
    error_log('Public shell data load failed: ' . $exception->getMessage());
}

$settings = is_array($context['settings'] ?? null) ? $context['settings'] : public_site_defaults();
$address = public_site_format_address($settings);
$socials = public_site_social_links($settings);

app_json_response([
    'ok' => true,
    'shell' => [
        'restaurantName' => (string)($settings['restaurant_name'] ?? 'Stonefellows'),
        'address' => $address,
        'hoursText' => (string)($settings['hours_text'] ?? ''),
        'socials' => $socials,
    ],
]);
