<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/floor-planner-ops-legacy.php';
$html = (string)ob_get_clean();
$needle = '<script src="js/floor-planner-ops.js?v=20260912-canonical1"></script>';
$replacement = '<script src="js/floor-planner-v2.js?v=20260914-3"></script>'
    . $needle
    . '<script src="js/floor-planner-v2-runtime.js?v=20260914-3"></script>'
    . '<script src="js/floor-planner-v2-enhancements.js?v=20260914-1"></script>'
    . '<script src="js/floor-planner-v2-fixes.js?v=20260914-1"></script>'
    . '<script src="js/floor-planner-v2-display.js?v=20260914-1"></script>';
if (!str_contains($html, $needle)) {
    http_response_code(500);
    echo 'Floor Planner runtime could not be initialized.';
    exit;
}
$html = str_replace($needle, $replacement, $html);
$html = str_replace(
    '<header class="top"><strong>Operational Floor Planner</strong>',
    '<header class="top"><strong>Floor Planner 2.0</strong>',
    $html
);
echo $html;
