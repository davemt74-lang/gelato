<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$user = app_require_auth();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<meta name="gelato-csrf" content="<?= app_escape(app_csrf_token()) ?>">
<meta name="theme-color" content="#081b2e">
<title>Food Monster · Gelato Agent</title>
<link rel="stylesheet" href="css/food-monster.css?v=20261015-fmg8reset">
</head>
<body class="food-monster-stage-page">
<main id="food-monster-stage" aria-label="Food Monster Agent stage">
</main>
<script src="js/agent-page-context.js?v=20260915-context3"></script>
<script src="js/voice-session-presence.js?v=20261013-presence1"></script>
<script src="js/voice-turn-taking.js?v=20261015-fmg7b2e"></script>
<script src="js/proactive-listening-commands.js?v=20261013-listening1"></script>
<script src="js/voice-diagnostics.js?v=20261016-fmg11e"></script>
<script src="js/global-agent.js?v=20261018-fmg13k"></script>
<script src="js/food-monster.js?v=20261017-fmg12e"></script>
<script src="js/food-monster-clock-popup.js?v=20261016-fmg9b"></script>
<script src="js/food-monster-room-tracker.js?v=20261016-fmg11e"></script>
<script src="js/food-monster-room.js?v=20261016-fmg11e"></script>
</body>
</html>
