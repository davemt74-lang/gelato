<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/floor-planner-ops.php';
$html = (string)ob_get_clean();
$needle = '<script src="js/floor-planner-ops.js?v=20260912-canonical1"></script>';
$replacement = '<script src="js/floor-planner-v2.js?v=20260914-1"></script>' . $needle;
if (!str_contains($html, $needle)) {
    http_response_code(500);
    echo 'Floor Planner runtime could not be initialized.';
    exit;
}
echo str_replace($needle, $replacement, $html);
