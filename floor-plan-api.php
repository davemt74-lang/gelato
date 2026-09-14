<?php
declare(strict_types=1);

// Stable application-relative entry point for the Floor Planner UI.
// Keeps deployments from depending on direct /api/ routing support.
require __DIR__ . '/api/floor-plans.php';
