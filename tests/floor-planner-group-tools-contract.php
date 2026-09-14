<?php
declare(strict_types=1);

function group_tools_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$groups = file_get_contents(__DIR__ . '/../js/floor-planner-v2-groups.js');
$state = file_get_contents(__DIR__ . '/../js/floor-planner-v2-group-state.js');
$shell = file_get_contents(__DIR__ . '/../floor-planner-v2.php');
if ($groups === false || $state === false || $shell === false) {
    throw new RuntimeException('Unable to read Floor Planner group-tool sources.');
}

group_tools_assert(str_contains($shell, 'floor-planner-v2-groups.js?v=20260914-1'), 'Floor Planner shell must load the group tools layer.');
group_tools_assert(str_contains($shell, 'floor-planner-v2-group-state.js?v=20260914-1'), 'Floor Planner shell must load the group state hardening layer.');
$groupPos = strpos($shell, 'floor-planner-v2-groups.js?v=20260914-1');
$canonicalPos = strpos($shell, '. $needle');
group_tools_assert($groupPos !== false && $canonicalPos !== false && $groupPos < $canonicalPos, 'Group persistence must load before the canonical planner starts initial plan hydration.');
group_tools_assert(str_contains($groups, "window.addEventListener('contextmenu'"), 'Group tools must provide a right-click menu for multi-selection.');
group_tools_assert(str_contains($groups, 'fpGroupMenu'), 'Group tools must render a dedicated group context menu.');
group_tools_assert(str_contains($groups, 'Even Distance'), 'Group menu must expose Even Distance.');
group_tools_assert(str_contains($groups, 'Even Distance Setting'), 'Group menu must expose an Even Distance setting.');
group_tools_assert(str_contains($groups, 'function averageGap'), 'Even Distance must calculate an automatic average gap when no setting exists.');
group_tools_assert(str_contains($groups, 'setting === null ? averageGap'), 'Even Distance must fall back to the average current object gap.');
group_tools_assert(str_contains($groups, 'Duplicate Group'), 'Group menu must expose Duplicate Group.');
group_tools_assert(str_contains($groups, 'Equipment records are unique'), 'Duplicate Group must not silently clone canonical equipment assets.');
group_tools_assert(str_contains($groups, 'Lock Group') && str_contains($groups, 'Unlock Group'), 'Group menu must expose Lock Group and Unlock Group.');
group_tools_assert(str_contains($groups, "node.dataset[LOCKED_ATTR] = '1'"), 'Locked groups must be represented as logical composite elements.');
group_tools_assert(str_contains($groups, 'syncV2Selection(members'), 'Clicking a locked group member must select the entire logical group.');
group_tools_assert(str_contains($groups, 'parsed.plan.data.groups = groupRecords()'), 'Locked-group membership must persist with the saved floor plan.');
group_tools_assert(str_contains($groups, 'kind:\'equipment\'') && str_contains($groups, 'kind:\'structure\''), 'Persisted groups must support both structure and equipment membership.');
group_tools_assert(str_contains($state, 'const desired = new Map()'), 'Live group state must remember user lock/unlock intent within the session.');
group_tools_assert(str_contains($state, "desired.set(key, null)"), 'Unlocking a group must prevent stale loaded group state from re-locking it.');
group_tools_assert(str_contains($state, 'fp-group-composite-member'), 'Lock/unlock must mutate a tracked class so the unsaved-change guard sees logical group changes.');
group_tools_assert(str_contains($groups, "const GELATO_TYPE = 'gelato-display'"), 'Planner must define a first-class Gelato element type.');
group_tools_assert(str_contains($groups, 'data-structure="gelato-display"'), 'Gelato element must be added to the structure palette.');
group_tools_assert(str_contains($groups, 'function gelatoNode'), 'Planner must create and restore Gelato elements.');
group_tools_assert(str_contains($groups, 'border-radius:24px 24px 15px 15px / 18px 18px 12px 12px'), 'Gelato element must use the requested curved rectangular shape.');
group_tools_assert(str_contains($groups, "item?.type === GELATO_TYPE"), 'Saved Gelato elements must be restored from floor-plan data.');

echo "floor-planner-group-tools-contract-ok\n";
