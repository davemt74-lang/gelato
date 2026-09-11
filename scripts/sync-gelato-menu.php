<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/menu-sync.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$options = getopt('', ['dry-run', 'organization-id:', 'url:', 'file:', 'live']);
$dryRun = array_key_exists('dry-run', $options);
$organizationId = isset($options['organization-id']) ? (int)$options['organization-id'] : null;
$settings = menu_source_settings();
$defaultFile = realpath(__DIR__ . '/../data/gelato-menu-scan.json') ?: (__DIR__ . '/../data/gelato-menu-scan.json');
$useLive = array_key_exists('live', $options) || isset($options['url']);
$file = trim((string)($options['file'] ?? $defaultFile));
$url = trim((string)($options['url'] ?? $settings['rest_url']));

try {
    if ($useLive) {
        if ($url === '' || !str_starts_with($url, 'https://')) {
            throw new RuntimeException('Live menu source URL must use HTTPS.');
        }
        $payload = menu_http_json($url, (int)$settings['timeout_seconds']);
        $sourceMode = 'live-rest';
        $sourceReference = $url;
    } else {
        if ($file === '') {
            throw new RuntimeException('Menu snapshot file path is empty.');
        }
        $resolved = realpath($file);
        if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
            throw new RuntimeException('Menu snapshot file was not found or is not readable: ' . $file);
        }
        $body = file_get_contents($resolved);
        if (!is_string($body)) {
            throw new RuntimeException('Unable to read menu snapshot file: ' . $resolved);
        }
        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Menu snapshot file contains invalid JSON.', 0, $exception);
        }
        if (!is_array($payload)) {
            throw new RuntimeException('Menu snapshot file contains an unexpected payload.');
        }
        $sourceMode = 'manual-file';
        $sourceReference = $resolved;
    }

    $normalized = menu_normalize_source_payload($payload);

    $summary = [
        'ok' => true,
        'mode' => $dryRun ? 'dry-run' : 'sync',
        'sourceMode' => $sourceMode,
        'sourceReference' => $sourceReference,
        'sourceVersion' => $normalized['version'],
        'source' => $normalized['source'],
        'sections' => $normalized['section_count'],
        'items' => $normalized['item_count'],
        'sectionCounts' => array_reduce(
            $normalized['sections'],
            static function (array $carry, array $section): array {
                $carry[$section['slug']] = count($section['items']);
                return $carry;
            },
            []
        ),
        'prices' => array_sum(array_map(
            static fn(array $section): int => array_sum(array_map(static fn(array $item): int => count($item['prices']), $section['items'])),
            $normalized['sections']
        )),
        'ingredientLinks' => array_sum(array_map(
            static fn(array $section): int => array_sum(array_map(static fn(array $item): int => count($item['ingredients']), $section['items'])),
            $normalized['sections']
        )),
    ];

    if (!$dryRun) {
        $pdo = app_pdo();
        $organizationId = menu_resolve_organization_id($pdo, $organizationId);
        $sync = menu_sync_database($pdo, $organizationId, $normalized, null);
        $summary['organizationId'] = $organizationId;
        $summary['database'] = $sync;
    }

    fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'message' => $exception->getMessage(),
        'exception' => get_class($exception),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
