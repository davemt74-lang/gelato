<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/admin-control-core.php';
require_once __DIR__ . '/includes/admin-shell-core.php';
require_once __DIR__ . '/includes/admin-dashboard-core.php';
require_once __DIR__ . '/includes/workspace-bootstrap.php';
$user = app_require_auth();
// Authenticated workspace content must not be reused from earlier deployments.
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Gelato-Workspace-Entry: php');
if (($user['role_slug'] ?? '') === 'wholesale_customer') {
    app_redirect('wholesale-portal.php');
}
$adminControlAllowed = admin_control_allowed($user);
$adminDashboardAllowed = admin_dashboard_allowed($user);
$pdo = app_pdo();
$organizationId = (int)$user['organization_id'];

$bootstrap = workspace_bootstrap($pdo, $user);

$html = file_get_contents(__DIR__ . '/index.html');
if (!is_string($html)) {
    http_response_code(500);
    exit('The workspace template could not be loaded.');
}
$script = '<script>window.RESTAURANT_SERVER_SESSION=true;window.RESTAURANT_CSRF_TOKEN=' . json_encode(app_csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) . ';window.RESTAURANT_CURRENT_USER_ID=' . (int)$user['id'] . ';window.RESTAURANT_ADMIN_CONTROL_ALLOWED=' . ($adminControlAllowed ? 'true' : 'false') . ';window.RESTAURANT_ADMIN_DASHBOARD_ALLOWED=' . ($adminDashboardAllowed ? 'true' : 'false') . ';window.RESTAURANT_WORKSPACE_BOOTSTRAP=' . json_encode($bootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) . ';if(window.RESTAURANT_ADMIN_CONTROL_ALLOWED){document.addEventListener("DOMContentLoaded",function(){var settings=document.getElementById("profileSettingsLink");if(!settings||document.getElementById("restaurantAdminControlLink"))return;var link=document.createElement("a");link.id="restaurantAdminControlLink";link.href="admin.php";link.textContent="▦ Restaurant Admin";settings.insertAdjacentElement("afterend",link);});}</script>';
$html = str_replace('<script src="js/auth.js"></script>', $script . "\n  <script src=\"js/private-browser-state.js?v=hardening-a1\"></script>\n  <script src=\"js/auth.js\"></script>\n  <script src=\"js/floor-planner-module.js?v=20261103-e1\"></script>", $html);
// Server-rendered navigation stays available when browser-local role data or JS is stale.
if ($adminControlAllowed) {
    $releaseSha = is_file(__DIR__ . '/DEPLOY_SHA.txt')
        ? trim((string)file_get_contents(__DIR__ . '/DEPLOY_SHA.txt')) : 'unknown';
    $navHtml = '<details id="gelato-server-navigation" class="gelato-server-navigation">'
        . '<summary>Admin tools <span class="gelato-version-label">Build ' . app_escape(substr($releaseSha, 0, 8)) . '</span></summary>'
        . '<div class="gelato-server-navigation-panel"><strong>Available administration tools</strong>';
    foreach (admin_shell_filtered_navigation($user) as $group) {
        $navHtml .= '<div class="gelato-server-navigation-group"><b>' . app_escape((string)$group['label']) . '</b>';
        foreach ($group['items'] as $item) {
            $navHtml .= '<a href="' . app_escape((string)$item['href']) . '">'
                . app_escape((string)$item['label']) . '</a>';
        }
        $navHtml .= '</div>';
    }
    $navHtml .= '<div class="gelato-server-navigation-group"><a href="deployment-check.php">Deployment and permissions check</a></div></div></details>';
    // The inline Admin sidebar is reconciled from the canonical API by
    // workspace-shell-sync.js. Keep the independent Admin tools fallback,
    // but do not inject a second copy of the same routes into the sidebar.
    $navCss = '<style id="gelato-server-navigation-styles">'
        . '.gelato-server-navigation{align-self:flex-end;flex:0 0 auto;width:min(500px,calc(100% - 30px));margin:8px 15px;font:600 12px/1.35 system-ui,sans-serif}'
        . '.gelato-server-navigation>summary{width:fit-content;max-width:100%;margin-left:auto;cursor:pointer;list-style:none;background:#17231f;color:white;border:1px solid #53645d;padding:11px 14px;border-radius:12px;box-shadow:0 6px 25px #0003}'
        . '.gelato-version-label{font-size:9px;color:#c6f0dc;margin-left:8px}'
        . '.gelato-server-navigation-panel{margin-top:7px;max-height:min(50vh,500px);overflow:auto;padding:16px;border:1px solid #ddd;background:white;color:#151a18;border-radius:13px;box-shadow:0 20px 65px #0003}'
        . '.gelato-server-navigation-group{display:flex;gap:7px;align-items:baseline;flex-wrap:wrap;padding:9px 0;border-top:1px solid #eee}'
        . '.gelato-server-navigation-group b{min-width:100%;font-size:11px;color:#555}'
        . '.gelato-server-navigation-group a{padding:6px 9px;background:#f1f5f2;border:1px solid #e5eae6;border-radius:7px;text-decoration:none;color:#193f30;font-size:11px}'
        . '</style>';
    $html = str_replace('</head>', $navCss . '</head>', $html);
    // Reserve space for both the summary and expanded fallback panel in the
    // main column; floating it above the page blocks actions and dialog controls.
    $html = str_replace('<div class="content">', $navHtml . '<div class="content">', $html);
    // Cache-bust all static workspace assets on every merged release.
    $assetVersion = rawurlencode(substr($releaseSha, 0, 12));
    $html = preg_replace_callback(
        '~((?:src|href)=["\'])(?!https?://|//|data:)([^"\']+\.(?:js|css))(?:\?[^"\']*)?(["\'])~i',
        static fn(array $m): string => $m[1] . $m[2] . '?v=' . $assetVersion . $m[3],
        $html
    ) ?? $html;
}
if ($adminDashboardAllowed) {
    $html = str_replace('</head>', "  <link rel=\"stylesheet\" href=\"css/admin-command-dashboard.css?v=20260914-command1\">\n</head>", $html);
    $html = str_replace('</body>', "  <script src=\"js/admin-command-dashboard.js?v=20260914-command1\"></script>\n</body>", $html);
}
if ($adminControlAllowed) {
    $html = str_replace('</head>', "  <link rel=\"stylesheet\" href=\"css/admin-tech-theme.css?v=20261013-nav1\">\n</head>", $html);
    $html = str_replace('<body>', '<body class="gelato-admin-tech-theme">', $html);
}
$html = str_replace('</body>', "  <script src=\"js/workspace-shell-sync.js?v=20261103-e1\"></script>\n</body>", $html);
// Training owns its visible chat/voice controls. Load after all inline
// page handlers, and after the Admin theme so the user bubble keeps contrast.
$html = str_replace('</head>', "  <link rel=\"stylesheet\" href=\"css/training-workspace-chat.css?v=20261013-training1\">\n</head>", $html);
$html = str_replace('</body>', "  <script src=\"js/voice-turn-taking.js?v=20261013-turn1\"></script>\n  <script src=\"js/proactive-listening-commands.js?v=20261013-listening1\"></script>\n  <script src=\"js/training-workspace-voice.js?v=20261013-barge1\"></script>\n</body>", $html);
echo $html;
