<?php
declare(strict_types=1);

/** Run targeted browser cleanup after the server session has been destroyed. */
function app_render_browser_logout(string $target): never
{
    if (!in_array($target, ['login.php', 'customer-login.php'], true)) $target = 'login.php';
    header('Cache-Control: private, no-store, max-age=0');
    header('Content-Type: text/html; charset=UTF-8');
    header('Referrer-Policy: no-referrer');
    $safe = htmlspecialchars($target, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Signed out</title></head><body>'
        . '<p>You have signed out. <a href="'.$safe.'">Continue to sign in</a>.</p>'
        . '<script src="js/private-browser-state.js?v=hardening-a1" data-logout-target="'.$safe.'"></script>'
        . '<noscript><p>JavaScript is required to clear saved browser drafts. Close this tab when using a shared device.</p></noscript></body></html>';
    exit;
}
