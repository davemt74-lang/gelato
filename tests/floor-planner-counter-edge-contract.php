<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../js/floor-planner-v2-fixes.js');
if ($source === false) {
    throw new RuntimeException('Unable to read Floor Planner fixes layer.');
}

function counter_edge_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

counter_edge_assert(str_contains($source, 'event.ctrlKey && counter'), 'Counter node creation must require Ctrl + counter pointer input.');
counter_edge_assert(str_contains($source, 'nearestCounterEdge'), 'Counter editing must resolve the nearest polygon edge.');
counter_edge_assert(str_contains($source, 'getScreenCTM'), 'Counter edge detection must use SVG screen transforms so zoomed/rotated counters remain editable.');
counter_edge_assert(str_contains($source, 'points.splice(index, 0, hit.projected)'), 'Ctrl-click must insert the new node directly on the selected edge.');
counter_edge_assert(str_contains($source, 'dragCounterNode(event, counter, index)'), 'The newly created node must enter drag behavior immediately.');
counter_edge_assert(str_contains($source, 'fp-counter-edge-node'), 'Direct counter editing must expose one draggable active node.');
counter_edge_assert(str_contains($source, "counter.dataset.counterPoints = serializePoints(points)"), 'Counter node movement must persist through data-counter-points for normal Floor Plan saves.');
counter_edge_assert(str_contains($source, "counter.dataset.counterTransform = '0'"), 'The broken legacy transform mode must remain disabled during direct editing.');
counter_edge_assert(str_contains($source, '[data-counter-action="add"],[data-counter-action="transform"]'), 'Legacy Add Node and Transform Shape menu actions must be removed.');
counter_edge_assert(str_contains($source, 'Reset Counter Shape'), 'Reset Shape must remain available as the recovery action.');
counter_edge_assert(str_contains($source, 'fill:rgba(204,210,214,.82)'), 'Stainless/light-grey counter styling must be preserved.');

echo "floor-planner-counter-edge-contract-ok\n";
