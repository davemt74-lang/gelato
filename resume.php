<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = app_require_auth();
if (!app_has_permission('resumes.view', $user)) {
    http_response_code(403);
    exit('You do not have permission to review resume submissions.');
}

$resumeId = trim((string)($_GET['id'] ?? ''));
$returnPath = trim((string)($_GET['return'] ?? 'index.php#resumes'));
if ($returnPath === '' || preg_match('/^(?:https?:)?\/\//i', $returnPath)) {
    $returnPath = 'index.php#resumes';
}

$payload = [
    'resumeId' => $resumeId,
    'returnPath' => $returnPath,
    'currentUser' => [
        'id' => 'db-user-' . (int)$user['id'],
        'displayName' => (string)$user['display_name'],
        'email' => (string)$user['email'],
        'roleName' => (string)$user['role_name'],
        'initials' => mb_strtoupper(mb_substr((string)$user['first_name'], 0, 1) . mb_substr((string)$user['last_name'], 0, 1)),
        'permissions' => $user['permissions'],
    ],
    'csrfToken' => app_csrf_token(),
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Resume Review</title>
  <link rel="stylesheet" href="css/resume.css?v=20260803-3">
</head>
<body>
<header class="resume-topbar">
  <div class="resume-topbar-inner">
    <button class="back-button" id="resumeBackButton" type="button">← Back to Resume Submissions</button>
    <a class="resume-brand" href="index.php#resumes" aria-label="Return to the training workspace">
      <span class="resume-brand-logo" id="resumeBrandLogo">FR</span>
      <span><strong id="resumeBrandName">Restaurant</strong><small>Private applicant review</small></span>
    </a>
    <div class="resume-user">
      <span class="resume-user-avatar"><?= app_escape($payload['currentUser']['initials']) ?></span>
      <span><strong><?= app_escape($payload['currentUser']['displayName']) ?></strong><small><?= app_escape($payload['currentUser']['roleName']) ?></small></span>
      <a href="index.php#resumes">Workspace</a>
    </div>
  </div>
</header>

<main class="resume-shell">
  <section class="resume-loading" id="resumeLoading">
    <span class="loading-mark">▣</span>
    <strong>Loading applicant record…</strong>
  </section>
  <section class="hidden" id="resumeRecord"></section>
</main>

<div class="resume-toast hidden" id="resumeToast" role="status"></div>
<script>window.RESTAURANT_RESUME_PAGE = <?= json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;</script>
<script src="js/auth.js"></script>
<script src="js/form-media-storage.js?v=20260804-media1"></script>
<script src="js/resume-detail.js?v=20260804-media1"></script>
</body>
</html>
