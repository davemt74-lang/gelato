<?php
declare(strict_types=1);

function group_align_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$align = file_get_contents(__DIR__ . '/../js/floor-planner-v2-group-align.js');
$shell = file_get_contents(__DIR__ . '/../floor-planner-v2.php');
if ($align === false || $shell === false) {
    throw new RuntimeException('Unable to read Floor Planner group alignment sources.');
}

group_align_assert(str_contains($shell, 'floor-planner-v2-group-align.js?v=20260914-2'), 'Floor Planner shell must load the group alignment layer.');
$alignPos = strpos($shell, 'floor-planner-v2-group-align.js?v=20260914-2');
$groupsPos = strpos($shell, 'floor-planner-v2-groups.js?v=20260914-1');
$canonicalPos = strpos($shell, '. $needle');
group_align_assert($alignPos !== false && $groupsPos !== false && $canonicalPos !== false && $alignPos < $groupsPos && $groupsPos < $canonicalPos, 'Gelato arc persistence and group persistence must load before canonical plan hydration.');

group_align_assert(str_contains($align, 'Align Vertically'), 'Group right-click menu must expose vertical alignment.');
group_align_assert(str_contains($align, 'Align Groups Vertically'), 'Multiple locked groups must expose vertical group alignment.');
group_align_assert(str_contains($align, 'Align Groups Horizontally'), 'Multiple locked groups must expose horizontal group alignment.');
group_align_assert(str_contains($align, 'function selectedGroupIds'), 'Group alignment must identify distinct selected locked groups.');
group_align_assert(str_contains($align, "axis === 'vertical'"), 'Vertical group alignment must align group centers on the x axis.');
group_align_assert(str_contains($align, "axis === 'horizontal'"), 'Horizontal group alignment must align group centers on the y axis.');

group_align_assert(str_contains($align, "const STORAGE_KEY = 'stonefellows.floorPlanner.evenDistanceFt'"), 'Even Distance setting must have a stable localStorage key.');
group_align_assert(str_contains($align, 'localStorage.setItem(STORAGE_KEY'), 'Typed Even Distance values must be remembered in localStorage.');
group_align_assert(str_contains($align, "spacingInput.addEventListener('input'"), 'Even Distance must update while the user changes the number.');
group_align_assert(str_contains($align, "spacingInput.addEventListener('change'"), 'Even Distance must persist when the number is changed.');
group_align_assert(str_contains($align, 'applySpacingSetting(nodes, saved)'), 'Remembered Even Distance must become the active spacing setting for a new selection.');

group_align_assert(str_contains($align, "const ARC_ATTR = 'gelatoArcDegree'"), 'Gelato arc degree must have a persisted structure attribute.');
group_align_assert(str_contains($align, 'function gelatoPath'), 'Gelato element must generate a dedicated curved footprint.');
group_align_assert(str_contains($align, 'topMidY = topY + bow') && str_contains($align, 'bottomMidY = bottomY + bow'), 'Gelato arc must bow both long edges together.');
group_align_assert(str_contains($align, 'topLeftX = 5 + endInset') && str_contains($align, 'topRightX = 95 - endInset'), 'Gelato end caps must angle as arc degree increases.');
group_align_assert(str_contains($align, 'fp-gelato-arc-slider'), 'Selected Gelato element must expose an in-rectangle Arc slider.');
group_align_assert(str_contains($align, 'type="range"') && str_contains($align, 'min="0" max="45"'), 'Gelato Arc slider must provide a degree range.');
group_align_assert(str_contains($align, 'applyGelatoArc(node, slider.value, true)'), 'Gelato Arc slider must update geometry live.');
group_align_assert(str_contains($align, 'item.gelatoArcDegree = arcDegreeForNode(node)'), 'Gelato arc degree must persist with floor-plan item data.');
group_align_assert(str_contains($align, 'arcByPlan.set(id, map)'), 'Saved Gelato arc degree must restore on plan load.');

group_align_assert(str_contains($align, 'fp-gelato-rotate-handle'), 'Selected Gelato element must expose a separate rotate handle.');
group_align_assert(str_contains($align, 'applyGelatoRotation(node'), 'Rotate handle must rotate the complete Gelato element independently of Arc.');
group_align_assert(str_contains($align, 'node.dataset.rotation = String(normalized)'), 'Gelato rotation must update the structure rotation value.');
group_align_assert(str_contains($align, 'node.style.transform = `rotate(${normalized}deg)`'), 'Gelato rotation must update its visual transform.');
group_align_assert(str_contains($align, "node.dataset[LOCKED_ATTR] === '1'"), 'Individual Gelato controls must be suppressed while it belongs to a locked logical group.');

echo "floor-planner-group-align-contract-ok\n";
