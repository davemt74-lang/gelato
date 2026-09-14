<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
app_require_permission('floorplans.view');
$planId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['plan'] ?? '')) ?: '';
$destination = 'floor-planner-v2.php' . ($planId !== '' ? '?plan=' . rawurlencode($planId) : '');
header('Location: ' . $destination, true, 302);
exit;
