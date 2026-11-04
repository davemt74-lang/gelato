<?php
declare(strict_types=1);

function food_monster_agent_name(?array $config = null): string
{
    $config ??= function_exists('app_has_config') && app_has_config() ? app_config() : [];
    $name = $config['food_monster']['agent_name'] ?? '';
    if (!is_string($name)) return 'Food Monster';
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($name === '' || preg_match('/\p{C}/u', $name)) return 'Food Monster';
    return mb_substr($name, 0, 120, 'UTF-8');
}
