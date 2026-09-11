<?php
declare(strict_types=1);
$job = null;
$brand = ['restaurantName' => 'Restaurant', 'logoText' => 'FR', 'primary' => '#d94a2b', 'secondary' => '#ff835f', 'dark' => '#171b1a'];
$id = trim((string)($_GET['id'] ?? ''));
try {
    require __DIR__ . '/includes/bootstrap.php';
    if (app_has_config()) {
        $pdo = app_pdo();
        $statement = $pdo->prepare("SELECT * FROM jobs WHERE organization_id = (SELECT organization_id FROM brand_settings ORDER BY id ASC LIMIT 1) AND (CAST(id AS CHAR) = ? OR public_id = ? OR slug = ?) AND status = 'published' AND archived_at IS NULL AND (closes_at IS NULL OR closes_at >= CURDATE()) LIMIT 1");
        $statement->execute([$id, $id, $id]);
        $row = $statement->fetch();
        if ($row) {
            $job = [
                'id' => $row['public_id'] ?: (string)$row['id'], 'title' => $row['title'], 'department' => $row['department'], 'location' => $row['location_name'],
                'employmentType' => $row['employment_type'], 'schedule' => $row['schedule_text'], 'payRange' => $row['pay_range'], 'summary' => $row['summary'],
                'description' => $row['description'], 'responsibilities' => json_decode((string)$row['responsibilities_json'], true) ?: [],
                'requirements' => json_decode((string)$row['requirements_json'], true) ?: [], 'benefits' => json_decode((string)$row['benefits_json'], true) ?: [],
            ];
        }
        $brandRow = $pdo->query("SELECT restaurant_name, primary_color, secondary_color, theme_settings_json FROM brand_settings ORDER BY id ASC LIMIT 1")->fetch();
        if ($brandRow) {
            $theme = json_decode((string)$brandRow['theme_settings_json'], true) ?: [];
            $brand = ['restaurantName' => $brandRow['restaurant_name'], 'logoText' => $theme['logo_text'] ?? 'FR', 'primary' => $brandRow['primary_color'] ?: '#d94a2b', 'secondary' => $brandRow['secondary_color'] ?: '#ff835f', 'dark' => $theme['dark_color'] ?? '#171b1a'];
        }
    }
} catch (Throwable) {}
function e(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= e($job['title'] ?? 'Job Opening') ?> · <?= e($brand['restaurantName']) ?></title><link rel="stylesheet" href="css/public.css?v=20260804-jobs1"><style>:root{--brand-primary:<?= e($brand['primary']) ?>;--brand-secondary:<?= e($brand['secondary']) ?>;--brand-dark:<?= e($brand['dark']) ?>}</style></head><body>
<header class="public-header"><a class="public-brand" href="landing.html"><span class="public-logo"><?= e($brand['logoText']) ?></span><span><strong><?= e($brand['restaurantName']) ?></strong><span>Job opening</span></span></a><nav class="public-nav"><a href="jobs.html">All Jobs</a><a class="primary" id="headerApply" href="apply.html<?= $job ? '?job='.rawurlencode((string)$job['id']) : '' ?>">Apply</a><div class="guest-profile-wrap" data-public-account-menu><button class="guest-profile-button" type="button" data-guest-menu-button aria-haspopup="true" aria-expanded="false"><span class="guest-avatar">G</span><span class="guest-profile-copy"><strong>Guest</strong><span>Not signed in</span></span><span class="guest-chevron">⌄</span></button><div class="guest-profile-menu hidden" data-guest-menu><div class="guest-menu-head"><span class="guest-avatar">G</span><div><strong>Logged-out account</strong><span>Sign in to access training</span></div></div><div class="guest-menu-list"><a class="primary" href="login.php">↪ Sign in</a><a href="forgot-password.php">? Forgot password</a><div class="guest-menu-divider"></div><a href="landing.html">⌂ Public landing page</a><a href="jobs.html">⌂ Available jobs</a><a href="apply.html">▤ Submit a resume</a></div></div></div></nav></header>
<main class="job-detail-shell"><a class="job-back" href="jobs.html">← Back to available jobs</a><div id="jobDetailRoot">
<?php if ($job): ?>
<section class="job-detail-head"><div class="job-detail-meta"><span class="job-chip"><?= e($job['department']) ?></span><span class="job-chip"><?= e($job['employmentType']) ?></span><span class="job-chip"><?= e($job['location']) ?></span></div><h1><?= e($job['title']) ?></h1><p><?= e($job['summary']) ?></p></section>
<div class="job-detail-grid"><article class="job-copy"><section><h2>About this role</h2><p><?= nl2br(e($job['description'])) ?></p></section><?php foreach ([['Responsibilities',$job['responsibilities']],['Requirements',$job['requirements']],['Benefits',$job['benefits']]] as [$heading,$items]): if ($items): ?><section><h2><?= e($heading) ?></h2><ul><?php foreach ($items as $item): ?><li><?= e((string)$item) ?></li><?php endforeach ?></ul></section><?php endif; endforeach ?></article><aside class="job-apply-card"><p class="eyebrow">Apply for this job</p><h3><?= e($job['title']) ?></h3><p><?= e($job['schedule']) ?></p><p><strong><?= e($job['payRange']) ?></strong></p><a class="button primary" href="apply.html?job=<?= rawurlencode((string)$job['id']) ?>">Apply now</a></aside></div>
<?php else: ?><div class="job-empty" id="serverJobEmpty">Loading job details…</div><?php endif; ?>
</div></main>
<script src="js/public-jobs.js?v=20260804-jobs1"></script>
<script>
<?php if (!$job): ?>
(async()=>{const esc=value=>String(value??'').replace(/[&<>'"]/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[ch]));const jobs=await window.RestaurantPublicJobs.load();const id=new URLSearchParams(location.search).get('id'),job=jobs.find(item=>String(item.id)===String(id)&&item.status==='published'),root=document.getElementById('jobDetailRoot');if(!job){root.innerHTML='<div class="job-empty">This job is not available or is no longer published.</div>';return}document.title=`${job.title} · Job Opening`;document.getElementById('headerApply').href=`apply.html?job=${encodeURIComponent(job.id)}`;const list=(heading,items)=>(items||[]).length?`<section><h2>${heading}</h2><ul>${items.map(item=>`<li>${esc(item)}</li>`).join('')}</ul></section>`:'';root.innerHTML=`<section class="job-detail-head"><div class="job-detail-meta"><span class="job-chip">${esc(job.department)}</span><span class="job-chip">${esc(job.employmentType)}</span><span class="job-chip">${esc(job.location)}</span></div><h1>${esc(job.title)}</h1><p>${esc(job.summary)}</p></section><div class="job-detail-grid"><article class="job-copy"><section><h2>About this role</h2><p>${esc(job.description)}</p></section>${list('Responsibilities',job.responsibilities)}${list('Requirements',job.requirements)}${list('Benefits',job.benefits)}</article><aside class="job-apply-card"><p class="eyebrow">Apply for this job</p><h3>${esc(job.title)}</h3><p>${esc(job.schedule)}</p><p><strong>${esc(job.payRange)}</strong></p><a class="button primary" href="apply.html?job=${encodeURIComponent(job.id)}">Apply now</a></aside></div>`})();
<?php endif; ?>
</script><script src="js/public-account-menu.js"></script></body></html>
