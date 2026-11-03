<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/daily-quicklist-core.php';
$user=app_require_auth();
if(!daily_quicklist_can_view($user)){http_response_code(403);exit('Daily Prep is available to operational staff only.');}
$displayName=htmlspecialchars((string)($user['display_name']??'Team member'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#183a2a">
<title>Daily Prep · Gelato</title>
<link rel="stylesheet" href="assets/css/daily-prep.css?v=3k2f">
</head><body>
<div class="workspace">
<header class="masthead">
  <a href="index.php" class="back-link" aria-label="Back to workspace">← Workspace</a>
  <div class="brand"><span class="brand-mark">g.</span><span>GELATO <small>/ DAILY WORKSPACE</small></span></div>
  <a class="agent-link" href="agent-canvas.php" title="Ask the official Gelato AI Agent">✦ Ask Gelato AI</a>
  <span class="user-chip"><?php echo $displayName; ?></span>
</header>
<main>
  <section class="hero">
    <div><div class="eyebrow">KITCHEN WORKSPACE <span class="live-dot"></span> SHARED LIVE LIST</div>
    <h1>Make today<br><em>run smoothly.</em></h1>
    <p>One simple place for team prep and everything you need to restock.</p></div>
    <div class="hero-date"><label for="work-date">WORKING DATE</label><input type="date" id="work-date"><span id="today-label">Your daily checklist</span></div>
  </section>
  <nav class="tabs" aria-label="Workspace pages">
    <button class="tab active" type="button" data-tab="prep"><span class="tab-icon">✓</span> Daily Prep <span class="tab-count" id="prep-tab-count">0</span></button>
    <button class="tab" type="button" data-tab="inventory"><span class="tab-icon">▦</span> Inventory / Need More <span class="tab-count" id="inventory-tab-count">0</span></button>
  </nav>
  <div id="notice" role="status" aria-live="polite" class="notice" hidden></div>
  <section id="prep-pane">
    <div class="section-head"><div><div class="eyebrow dark">PREP CHECKLIST</div><h2>Today's prep</h2><p>Tap to finish a task. Open <strong>Recipe</strong> for the full method.</p></div>
      <div class="progress-badge"><strong id="prep-percent">0%</strong><span>COMPLETE</span></div>
    </div>
    <div class="progress-rail"><div id="prep-progress" style="width:0%"></div></div>
    <form id="prep-add" class="add-bar"><label class="sr-only" for="prep-title">New prep item</label><input id="prep-title" name="title" placeholder="Add a prep item… (e.g. portion mozzarella)" maxlength="220" required>
      <label class="sr-only" for="prep-recipe">Recipe</label><select id="prep-recipe" name="recipeId"><option value="">No linked recipe</option></select>
      <button class="primary" type="submit">+ Add</button></form>
    <div id="prep-list" class="task-list"><div class="loading">Loading your prep list…</div></div>
    <section class="suggestion-panel"><div class="panel-head"><div><span class="eyebrow dark">FROM YOUR MENU</span><h3>Suggested prep</h3><p>Review published-menu ingredients and saved recipes. Select what the kitchen should prepare; historical amounts are guidance, not orders.</p></div><button id="seed-prep" class="outline" type="button">Add selected (0)</button></div><div id="suggestions" class="chips"></div></section>
  </section>
  <section id="inventory-pane" hidden>
    <?php if(daily_quicklist_can_manage($user)&&(app_has_permission('inventory.view',$user)||app_has_permission('inventory.manage',$user))): ?>
    <div class="board-actions" style="margin:12px 0 22px"><a class="primary" style="display:inline-block;padding:12px 16px;text-decoration:none;border-radius:10px" href="need-more-purchasing.php">Open Purchasing &amp; Receiving Dashboard →</a></div>
    <?php endif; ?>
    <div class="section-head"><div><div class="eyebrow dark">STOCK & SUPPLIES</div><h2>Inventory</h2><p>Flag shortages without changing actual stock quantities.</p></div><div class="progress-badge stock"><strong id="need-count">0</strong><span>NEED MORE</span></div></div>
    <form id="inventory-add" class="add-bar"><label class="sr-only" for="inventory-title">New inventory request</label><input id="inventory-title" name="title" placeholder="Add something we need…" maxlength="220" required><label class="sr-only" for="inventory-qty">Quantity needed</label><input id="inventory-qty" name="quantityNote" placeholder="Amount / notes (optional)" maxlength="120"><button class="primary" type="submit">+ Need More</button></form>
    <section class="stock-alert-panel" id="stock-alert-panel" hidden><div class="panel-head"><div><h3>Stock at reorder point</h3><p class="muted">Based on saved stock thresholds. Recipe links are exact-name context, not calculated shortages or order amounts.</p></div></div><div id="stock-alerts" class="stock-alerts"></div></section>
    <div class="inventory-grid"><section><div class="panel-head"><h3>Need More list</h3><span id="need-caption" class="muted"></span></div><div id="need-list" class="task-list"></div></section><section class="stock-panel"><div class="panel-head"><h3>Stock directory</h3></div><input id="stock-search" type="search" placeholder="Search your current stock…" aria-label="Search inventory"><div id="stock-list" class="stock-list"></div></section></div>
  </section>
  <p class="footer-note">Shared with your Gelato team · Changes are logged · Inventory counts update only in <a href="operations.php">Operations</a></p>
</main></div>
<script>window.DAILY_PREP_CSRF=<?php echo json_encode(app_csrf_token(),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;</script>
<script src="js/daily-prep.js?v=3k2f" defer></script>
</body></html>
