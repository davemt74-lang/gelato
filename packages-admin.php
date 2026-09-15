<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/package-deals-core.php';
$user=app_require_permission('packages.view');
$canManage=app_has_permission('packages.manage',$user);
$ready=package_deals_ready(app_pdo());
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Package Deals | Gelato</title>
<link rel="stylesheet" href="assets/css/packages-admin.css?v=20260915-1">
</head>
<body>
<header class="top"><strong>Package Deals</strong><div class="actions"><?php if($canManage):?><button class="btn primary" type="button" id="newPackageTop">+ Add New Package</button><?php endif;?><a class="btn" href="packages.php" target="_blank" rel="noopener">View Public Page ↗</a></div></header>
<main class="package-admin-shell" id="packageAdminApp" data-ready="<?=$ready?'1':'0'?>" data-manage="<?=$canManage?'1':'0'?>" data-csrf="<?=app_escape(app_csrf_token())?>">
  <section class="package-admin-hero">
    <div><span class="eyebrow">Pickup merchandising</span><h1>Package Deals</h1><p>Build family meals and bundled takeout offers from the real restaurant menu. Every package expands into native POS/KDS items and keeps its discount and redemption history.</p></div>
    <div class="package-hero-rule"><strong>Pickup only</strong><span>No dine-in · No delivery</span></div>
  </section>
  <?php if(!$ready):?><div class="package-alert">Package Deals is not installed yet. Run <strong>upgrade.php → Upgrade once</strong>.</div><?php endif;?>
  <section class="package-stats" id="packageStats"></section>
  <div class="package-layout">
    <aside class="package-list-panel">
      <div class="package-list-head"><div><span>Deals</span><strong id="packageCount">0 packages</strong></div><?php if($canManage):?><button class="icon-btn" type="button" id="newPackage" aria-label="Add new package">+</button><?php endif;?></div>
      <label class="package-filter"><input type="checkbox" id="showArchived"> <span>Show archived</span></label>
      <div class="package-list" id="packageList"></div>
    </aside>
    <section class="package-editor-panel">
      <div id="editorEmpty" class="editor-empty"><strong>Select a package</strong><span>Choose a deal from the left or create a new package.</span></div>
      <form id="packageForm" class="package-editor" hidden>
        <div class="package-editor-head"><div><span class="status-pill" id="statusPill">Draft</span><h2 id="editorTitle">New Package</h2><p id="editorMeta">Not saved yet</p></div><div class="editor-actions" id="lifecycleActions"></div></div>
        <input type="hidden" id="packageId">
        <div class="editor-grid two"><label><span>Package name</span><input id="packageName" maxlength="180" required placeholder="Family Dinner"></label><label><span>Short headline</span><input id="packageEyebrow" maxlength="120" placeholder="Good pizza brings people together"></label></div>
        <label><span>Description</span><textarea id="packageDescription" rows="3" maxlength="5000" placeholder="A complete family takeout meal with pizza, salads, bread and drinks."></textarea></label>
        <div class="editor-grid four"><label><span>Discount type</span><select id="discountMethod"><option value="percent">Percent off</option><option value="fixed">Fixed amount off</option></select></label><label><span>Discount value</span><input id="discountValue" type="number" min="0.01" step="0.01" value="10"></label><label><span>Starts</span><input id="startsAt" type="datetime-local"></label><label><span>Ends</span><input id="endsAt" type="datetime-local"></label></div>
        <label class="check-row"><input id="featured" type="checkbox"><span>Feature this package first on the public package page</span></label>
        <div class="pickup-lock"><span>✓</span><div><strong>Pickup only</strong><p>Package deals cannot be ordered for dine-in or delivery.</p></div></div>
        <section class="group-builder">
          <div class="section-head"><div><span class="eyebrow">Package composition</span><h3>Categories & selections</h3><p>Add a category such as Pizza, Drinks, Salad or Dessert, set how many the customer chooses, then search the live menu and click items to make them eligible.</p></div><?php if($canManage):?><button class="btn" id="addGroup" type="button">+ Add Category</button><?php endif;?></div>
          <div id="packageGroups" class="package-groups"></div>
        </section>
        <?php if($canManage):?><div class="save-bar"><button class="btn primary big" type="submit">Save Package</button><span id="saveState">Changes are not saved until you click Save Package.</span></div><?php endif;?>
      </form>
    </section>
  </div>
</main>
<script>window.GELATO_PACKAGE_ADMIN={csrf:<?=json_encode(app_csrf_token())?>,canManage:<?=json_encode($canManage)?>};</script>
<script src="assets/js/packages-admin.js?v=20260915-1"></script>
<script src="js/universal-admin-page-shell.js?v=20260915-shell2"></script>
</body>
</html>
