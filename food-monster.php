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
<meta name="theme-color" content="#081b2e">
<title>Food Monster · Gelato Agent</title>
<link rel="stylesheet" href="css/food-monster.css?v=20261015-fmg7a">
</head>
<body class="food-monster-stage-page">
<main id="food-monster-stage" aria-label="Food Monster Agent stage">
  <a class="fm-stage-brand" href="workspace.php" aria-label="Return to Gelato workspace">Food Monster <span>AI</span></a>
  <div class="fm-stage-help">Drag the monster · Move your cursor · Ask a question below</div>
</main>
<script src="js/agent-page-context.js?v=20260915-context3"></script>
<script src="js/voice-session-presence.js?v=20261013-presence1"></script>
<script src="js/voice-turn-taking.js?v=20261013-turn1"></script>
<script src="js/proactive-listening-commands.js?v=20261013-listening1"></script>
<script src="js/voice-diagnostics.js?v=20261013-diagnostics1"></script>
<script src="js/global-agent.js?v=20261015-fmg7c"></script>
<script src="js/food-monster.js?v=20261015-fmg7a"></script>
</body>
</html>
