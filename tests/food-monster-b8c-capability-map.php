<?php
declare(strict_types=1);

/**
 * FM-B8C: source-backed, discovery-only capability map certification.
 * This test never opens a DB connection, an HTTP request, or an action route.
 */
$root = dirname(__DIR__);
$path = $root.'/config/food-monster-capability-map.json';
$raw = file_get_contents($path);
if ($raw === false) { fwrite(STDERR, "FM-B8C missing manifest\n"); exit(1); }
try { $map = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }
catch (JsonException $error) { fwrite(STDERR, "FM-B8C invalid JSON: ".$error->getMessage()."\n"); exit(1); }
$count = 0;
function fm_b8c_check(bool $condition, string $name): void {
    global $count;
    if (!$condition) { fwrite(STDERR, "FM-B8C FAIL: ".$name."\n"); exit(1); }
    ++$count;
}
fm_b8c_check(($map['schemaVersion'] ?? null) === '1.0.0', 'Stable map schema');
fm_b8c_check(($map['executionGrant'] ?? null) === false, 'No action grant from manifest');
fm_b8c_check(($map['section'] ?? null) === 'FM-B8C', 'Section identity');

$features = [];
foreach (($map['featureFamilies'] ?? []) as $feature) {
    $id = (string)($feature['id'] ?? '');
    fm_b8c_check((bool)preg_match('/^[a-z][a-z_]+$/D', $id), 'Valid feature '.$id);
    fm_b8c_check(!isset($features[$id]), 'Feature unique '.$id);
    $features[$id] = true;
}
fm_b8c_check(count($features) >= 25, 'Broad feature discovery');

$actualApi = array_map(static fn(string $file): string => 'api/'.basename($file), glob($root.'/api/*.php') ?: []);
sort($actualApi);
$declaredApi = [];
$visionActions = [];
$discoveredActionCount = 0;
foreach (($map['apiEndpoints'] ?? []) as $api) {
    $route = (string)($api['path'] ?? '');
    fm_b8c_check((bool)preg_match('~^api/[a-z0-9-]+\.php$~D', $route), 'Safe local API route');
    fm_b8c_check(!isset($declaredApi[$route]), 'Unique API '.$route);
    $declaredApi[$route] = true;
    fm_b8c_check(isset($features[$api['feature'] ?? '']), 'Feature group for '.$route);
    fm_b8c_check(($api['dispatch'] ?? null) === 'discovery_only', 'No dispatch '.$route);
    fm_b8c_check(($api['permissionStatus'] ?? null) === 'needs_endpoint_contract_review', 'No assumed authorization '.$route);
    $source = file_get_contents($root.'/'.$route);
    fm_b8c_check(is_string($source), 'Source exists '.$route);
    $blob = sha1('blob '.strlen($source)."\0".$source);
    fm_b8c_check(hash_equals((string)$api['sourceBlob'], $blob), 'Source evidence current '.$route);
    $found = [];
    // Same deliberately conservative literal-comparison extractor as the manifest build.
    preg_match_all('~(?:\x24action|\x24in\[\x27action\x27\]|\x24input\[\x27action\x27\])\s*===?\s*\x27([^\x27]+)\x27~', $source, $found);
    $observed = array_values(array_unique($found[1] ?? []));
    sort($observed);
    fm_b8c_check($observed === ($api['discoveredActions'] ?? null), 'Static action evidence current '.$route);
    fm_b8c_check((bool)$api['directAppRequireAuthCall'] === str_contains($source, 'app_require_auth()'), 'Auth marker current '.$route);
    foreach ((array)$api['discoveredActions'] as $action) {
        fm_b8c_check(is_string($action) && (bool)preg_match('/^[A-Za-z0-9_.-]+$/D', $action), 'Action token '.$route);
        ++$discoveredActionCount;
    }
    if ($route === 'api/glasses-vision-lab.php') { $visionActions = (array)$api['discoveredActions']; }
}
$actualMap = array_keys($declaredApi);
sort($actualMap);
fm_b8c_check($actualApi === $actualMap, 'All current API files mapped exactly once');
fm_b8c_check(count($actualApi) === 135, '135 current API files accounted for');
fm_b8c_check(count($visionActions) === 123, '123 Vision Lab action comparisons discovered');
fm_b8c_check($discoveredActionCount >= 550, 'Broad static action discovery');

require $root.'/includes/agent-node-registry.php';
$realNodes = gaw_agent_nodes();
$mappedNodes = [];
foreach (($map['nativeAgentNodes'] ?? []) as $node) {
    $id = (string)($node['id'] ?? '');
    fm_b8c_check(!isset($mappedNodes[$id]), 'Unique native node '.$id);
    $mappedNodes[$id] = true;
    fm_b8c_check(isset($realNodes[$id]), 'Existing native node '.$id);
    fm_b8c_check(($node['route'] ?? null) === $realNodes[$id]['route'], 'Canonical node route '.$id);
    fm_b8c_check(($node['mode'] ?? null) === $realNodes[$id]['mode'], 'Canonical node mode '.$id);
    fm_b8c_check(($node['domain'] ?? null) === $realNodes[$id]['domain'], 'Canonical node domain '.$id);
    fm_b8c_check(($node['dispatch'] ?? null) === 'existing_node_policy_only', 'Node preserves policy '.$id);
    fm_b8c_check(isset($declaredApi[$node['route']]), 'Node resolves to mapped API '.$id);
}
fm_b8c_check(count($realNodes) === count($mappedNodes), 'Every native node accounted for');

$ids = [];
$skillFeatures = [];
foreach (($map['skillContractCandidates'] ?? []) as $skill) {
    $id = (string)($skill['id'] ?? '');
    $feature = (string)($skill['feature'] ?? '');
    $tool = (array)($skill['tool'] ?? []);
    $route = (string)($tool['path'] ?? '');
    $action = $tool['action'] ?? null;
    fm_b8c_check((bool)preg_match('/^[a-z][a-z0-9_.]+$/D', $id), 'Skill identifier '.$id);
    fm_b8c_check(!isset($ids[$id]), 'Unique skill identifier '.$id);
    $ids[$id] = true;
    fm_b8c_check(isset($features[$feature]), 'Mapped feature for skill '.$id);
    fm_b8c_check(isset($declaredApi[$route]), 'Mapped API for skill '.$id);
    fm_b8c_check(($skill['execution'] ?? null) === 'contract_candidate_not_granted', 'No skill execution grant '.$id);
    fm_b8c_check(!empty($skill['permissionRequirement']), 'Authorization requirement documented '.$id);
    fm_b8c_check(!empty($skill['gate']), 'Approval gate documented '.$id);
    $skillFeatures[$feature] = true;
    if ($action !== null) {
        $api = null;
        foreach ($map['apiEndpoints'] as $entry) {
            if ($entry['path'] === $route) { $api = $entry; break; }
        }
        fm_b8c_check(in_array($action, (array)($api['discoveredActions'] ?? []), true), 'Skill action supported by source '.$id);
        fm_b8c_check(in_array(($tool['method'] ?? null), ['GET', 'POST'], true), 'Skill transport noted '.$id);
    }
}
fm_b8c_check(count($ids) >= 70, 'At least 70 candidate task-level skills');
fm_b8c_check(count(array_diff(array_keys($features), array_keys($skillFeatures))) === 0, 'Every feature has candidate skills');
$required = ['schedule.shift_save', 'schedule.publish', 'schedule.request_time_off',
    'timeclock.clock_in', 'timeclock.clock_out', 'timeclock.start_break', 'vision.dataset_create'];
foreach ($required as $id) { fm_b8c_check(isset($ids[$id]), 'Owner priority skill '.$id); }
fm_b8c_check(!isset($map['databaseCredentials']) && !isset($map['toolToken']), 'No sensitive material in map');
echo "FM-B8C capability map: PASS ($count assertions; ".
    count($features)." feature families; ".count($actualApi)." APIs; ".
    $discoveredActionCount." static comparisons; ".
    count($realNodes)." Agent nodes; ".count($ids)." skill candidates)\n";
