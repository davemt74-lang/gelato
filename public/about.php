<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/public-site.php';
$context = public_site_fallback_context();
try { $context = public_site_context(app_pdo()); } catch (Throwable $e) { error_log('Public about load failed: ' . $e->getMessage()); }
$settings = $context['settings'];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#0b0b09"><meta name="description" content="About <?= app_escape((string)$settings['restaurant_name']) ?> Pizzeria + Bar."><title>About | <?= app_escape((string)$settings['restaurant_name']) ?></title><link rel="stylesheet" href="assets/css/site.css?v=20261001"></head><body>
<?php public_site_render_header($settings, 'about'); ?>
<main class="page-main"><section class="page-hero"><div class="shell"><div class="eyebrow">About Stonefellows</div><h1>Built on Pizza. Fueled by People.</h1><p><?= app_escape((string)$settings['tagline']) ?></p></div></section>
<section class="section"><div class="shell about-grid"><article class="content-card"><div class="eyebrow">The Place</div><h2>A neighborhood pizzeria + bar.</h2><p>Stonefellows is designed around the things that make a neighborhood restaurant worth returning to: a hot oven, a comfortable room, familiar faces, cold drinks and food made to be shared.</p><p>The public website stays connected to the restaurant system, so current menu and location information can be maintained by the team instead of duplicated across static pages.</p></article><article class="content-card"><div class="eyebrow">What We Serve</div><h2>Pizza first. Plenty beyond it.</h2><p>Our current food, pizza, drinks and gelato offerings are published from the active restaurant menu database.</p><a class="btn-link" href="menu.php">Current Menu →</a><br><br><a class="btn-link" href="gelato.php">Current Gelato →</a></article></div></section></main>
<?php public_site_render_footer($settings); ?><script src="assets/js/site.js?v=20261001"></script></body></html>
