<?php
declare(strict_types=1);

$display = file_get_contents(__DIR__ . '/../js/floor-planner-v2-display.js');
$shell = file_get_contents(__DIR__ . '/../floor-planner-v2.php');
if ($display === false || $shell === false) {
    throw new RuntimeException('Unable to read Floor Planner measurement-toggle sources.');
}

function measurement_toggle_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

measurement_toggle_assert(str_contains($shell, 'floor-planner-v2-display.js?v=20260914-1'), 'Floor Planner shell must load the measurement display layer.');
measurement_toggle_assert(str_contains($display, "!event.ctrlKey || !event.shiftKey"), 'Measurement toggle must require Ctrl + Shift.');
measurement_toggle_assert(str_contains($display, "key !== 'a'"), 'Measurement toggle must use the A key.');
measurement_toggle_assert(str_contains($display, 'event.preventDefault()'), 'Measurement shortcut must suppress the page/browser default when delivered to the app.');
measurement_toggle_assert(str_contains($display, 'event.stopImmediatePropagation()'), 'Measurement shortcut must not leak into other Floor Planner shortcut handlers.');
measurement_toggle_assert(str_contains($display, '#stage .dim'), 'Measurement toggle must hide only on-floor dimension labels.');
measurement_toggle_assert(str_contains($display, 'display:none!important'), 'Hidden measurement state must actually remove labels from the floor.');
measurement_toggle_assert(str_contains($display, 'floorPlanner.measurementsVisible'), 'Measurement visibility must persist in local storage.');
measurement_toggle_assert(str_contains($display, 'Item measurements'), 'Measurement toggle must report its current state.');
measurement_toggle_assert(str_contains($display, "visible ? 'shown' : 'hidden'"), 'Measurement status must distinguish shown and hidden states.');

echo "floor-planner-measurement-toggle-contract-ok\n";
