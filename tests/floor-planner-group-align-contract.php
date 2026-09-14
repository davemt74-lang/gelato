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

group_align_assert(str_contains($shell, 'floor-planner-v2-group-align.js?v=20260914-1'), 'Floor Planner shell must load the group alignment layer.');
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
group_align_assert(str_contains($align, 'applySpacingSetting(nodes, saved)'), 'Remembered Even Distance must become the active spacing setting for a new group selection.');
group_align_assert(str_contains($align, 'Gelato rotation arc slider'), 'Selected Gelato elements must expose an arc rotation slider.');
group_align_assert(str_contains($align, 'fp-gelato-rotate-arc'), 'Gelato rotation control must render a visible arc.');
group_align_assert(str_contains($align, 'fp-gelato-rotate-thumb'), 'Gelato rotation arc must provide a draggable thumb.');
group_align_assert(str_contains($align, "control.addEventListener('pointermove'"), 'Gelato arc slider must rotate continuously while dragged.');
group_align_assert(str_contains($align, 'rotateTarget.dataset.rotation'), 'Gelato rotation must update the persisted structure rotation value.');
group_align_assert(str_contains($align, 'rotateTarget.style.transform'), 'Gelato rotation must update its visual transform.');
group_align_assert(str_contains($align, "node.dataset[LOCKED_ATTR] === '1'"), 'Individual Gelato rotation must be suppressed while the element belongs to a locked logical group.');

echo "floor-planner-group-align-contract-ok\n";
