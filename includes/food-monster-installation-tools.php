<?php
declare(strict_types=1);

require_once __DIR__ . '/food-monster-installation.php';

function fmi_agent_catalogue(array $principal, array $context): array
{
    if (!fmi_owner($principal)
        || (($context['channel'] ?? '') === 'voice' && ($context['privateVoiceApproved'] ?? false) !== true)) {
        return [];
    }
    $catalogue = [];
    foreach ([
        'get_gelato_license_status' => [
            'license',
            'Read the current Food Monster license and renewal date for this Gelato installation. Use for active, expired, suspended or revoked license, renewal or update entitlement questions.',
        ],
        'get_gelato_update_status' => [
            'updates',
            'Read the actual installed Gelato version, latest release, release notes, automatic update preference and prior failure. Use for whether an update exists, what changed, why it failed, and how the owner can approve a manual update. This tool never installs or approves an update.',
        ],
    ] as $tool => [$topic, $description]) {
        $catalogue[$tool] = [
            'description' => $description,
            'parameters' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
            'allowedArguments' => [],
            'impact' => 'read',
            'authorize' => static fn(array $actor, array $args): bool => fmi_owner($actor),
            'validate' => static fn(array $args, array $actor): array => [],
            'execute' => static function (array $args, array $actor) use ($topic): array {
                if (!fmi_owner($actor)) {
                    return ['ok' => false, 'data' => []];
                }
                try {
                    $result = fmi_status($actor);
                } catch (Throwable $error) {
                    error_log('Food Monster installation status: ' . get_class($error));
                    $result = ['connection' => 'unavailable', 'status' => null];
                }
                return ['ok' => true, 'data' => [
                    'answer' => fmi_agent_answer($topic, fmi_version(), $result),
                    'source' => $result['connection'] === 'connected' ? 'Gelato local build and Food Monster check-in' : 'Gelato local build',
                ]];
            },
        ];
    }
    return $catalogue;
}

function fmi_agent_answer(string $topic, ?string $version, array $result): string
{
    $local = $version === null ? 'This Gelato build has no release version recorded.' : 'Gelato is running ' . $version . '.';
    $status = $result['status'] ?? null;
    if (!is_array($status)) {
        return $local . ' ' . (($result['connection'] ?? null) === 'unconfigured'
            ? 'Connect this installation from the owner’s License & Updates page to check its license and releases.'
            : ($result['message'] ?? 'Food Monster licensing is currently unavailable.')) . ' Restaurant operations remain available.';
    }
    $state = $status['license_state'];
    $renewal = !empty($status['expires_at']) ? ' The current access period ends ' . gmdate('M j, Y H:i', (int)$status['expires_at']) . ' UTC.' : '';
    $access = 'The Food Monster license is ' . $state . '.' . $renewal;
    if (in_array($state, ['expired', 'suspended', 'revoked'], true)) {
        $access .= ' Restaurant operations remain available. New updates are paused.';
    }
    if ($topic === 'license') {
        return $access;
    }
    $preference = (string)($status['auto_update'] ?? 'off');
    $summary = $local . ' ' . $access . ' The automatic update preference is ' . $preference . '.';
    $release = $status['available_release'] ?? null;
    if (is_array($release)) {
        $summary .= ' Gelato ' . (string)$release['version'] . ' is available. Approve its download in the Food Monster portal.';
        $notes = trim(strip_tags((string)($release['notes'] ?? '')));
        if ($notes !== '') {
            $summary .= ' Release notes: ' . mb_strcut($notes, 0, 430, 'UTF-8');
        }
    } else {
        $summary .= ' ' . match ($status['update_status'] ?? '') {
            'up_to_date' => 'This installation is up to date on its assigned channel.',
            'minimum_version' => 'An intermediate version is required before the latest release.',
            'updates_excluded' => 'This license does not include software updates.',
            'no_release' => 'There is no published release on the assigned channel.',
            default => 'There is no eligible manual update right now.',
        };
    }
    if (($status['last_update']['state'] ?? '') === 'failed') {
        $summary .= ' The last attempt failed. ' . mb_strcut((string)($status['last_update']['error_summary'] ?? ''), 0, 200, 'UTF-8');
    }
    return $summary;
}
