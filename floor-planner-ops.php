<?php
declare(strict_types=1);

// Floor Planner 2.0 is the only public planner entry. Keep this legacy URL
// working for old bookmarks while forcing the v2 interaction layer to load.
$planId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['plan'] ?? '')) ?: '';
$destination = 'floor-planner-v2.php' . ($planId !== '' ? '?plan=' . rawurlencode($planId) : '');
header('Location: ' . $destination, true, 302);
exit;
