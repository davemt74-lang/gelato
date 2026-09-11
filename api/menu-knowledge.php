<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/menu-sync.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

try {
    $user = app_current_user();
    if (!$user) {
        http_response_code(401);
        echo "window.MENU_SECTIONS=[];window.MENU_NOTES=[];window.MENU_SOURCE_META={ok:false,message:'Authentication required'};\n";
        exit;
    }
    $pdo = app_pdo();
    $organizationId = (int)$user['organization_id'];
    $sections = menu_database_sections($pdo, $organizationId);
    $notes = menu_database_notes($sections);
    $meta = [
        'ok' => true,
        'source' => 'database',
        'organizationId' => $organizationId,
        'sections' => count($sections),
        'items' => array_sum(array_map(static fn(array $section): int => count($section['items'] ?? []), $sections)),
    ];
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR;
    echo 'window.MENU_SECTIONS=' . json_encode($sections, $flags) . ";\n";
    echo 'window.MENU_NOTES=' . json_encode($notes, $flags) . ";\n";
    echo 'window.MENU_SOURCE_META=' . json_encode($meta, $flags) . ";\n";
} catch (Throwable $exception) {
    error_log('Menu knowledge endpoint error: ' . $exception->getMessage());
    http_response_code(500);
    echo "window.MENU_SECTIONS=[];window.MENU_NOTES=[];window.MENU_SOURCE_META={ok:false,message:'Menu data is temporarily unavailable.'};\n";
}
