<?php
declare(strict_types=1);

function asc_test(bool $ok, string $message, int $code): void
{
    if ($ok) {
        return;
    }
    fwrite(STDERR, "FAIL {$code}: {$message}\n");
    exit($code);
}

$root = dirname(__DIR__);
$shell = file_get_contents($root . '/js/admin-shell-consolidation.js');
$loader = file_get_contents($root . '/js/floor-planner-module.js');
$template = file_get_contents($root . '/index.html');

asc_test(is_string($shell) && $shell !== '', 'admin shell consolidation script is missing', 2);
asc_test(is_string($loader) && $loader !== '', 'floor planner module loader is missing', 3);
asc_test(is_string($template) && $template !== '', 'workspace template is missing', 4);

asc_test(str_contains($shell, '.sidebar .role-card{display:none!important}'), 'Active position card is not hidden', 5);
asc_test(str_contains($shell, '#page-owner .owner-agent-dialogue'), 'Owner private conversation is not hidden', 6);
asc_test(str_contains($shell, '#page-owner .owner-agent-composer'), 'Owner private composer is not hidden', 7);
asc_test(str_contains($shell, '#page-owner>.page-head{display:none!important}'), 'Owner promotional page header is not hidden', 8);
asc_test(str_contains($shell, 'body.gelato-global-agent-ready #page-workspace>.workspace>.composer'), 'Local composer is not conditionally replaced by the global Agent bar', 9);
asc_test(str_contains($shell, 'gelato-agent-ready'), 'Global Agent readiness integration is missing', 10);
asc_test(str_contains($shell, 'gelato-agent-response'), 'Global Agent responses are not mirrored into the main canvas', 11);
asc_test(str_contains($shell, 'sendToGuidedTraining'), 'Guided training forwarding is missing', 12);
asc_test(str_contains($shell, "</span>Wholesale'"), 'Wholesale navigation normalization is missing', 13);
asc_test(str_contains($shell, "</span>Catering'"), 'Catering navigation normalization is missing', 14);
asc_test(str_contains($loader, "admin-shell-consolidation.js?v=20260913-1"), 'Admin shell script is not loaded by the authenticated workspace', 15);

// Legacy hooks stay in the DOM because existing training/admin code still updates them.
asc_test(str_contains($template, 'id="sidebarRole"'), 'sidebarRole compatibility hook was removed', 16);
asc_test(str_contains($template, 'id="ownerAgentMessages"'), 'ownerAgentMessages compatibility hook was removed', 17);
asc_test(str_contains($template, 'id="ownerAgentInput"'), 'ownerAgentInput compatibility hook was removed', 18);

echo "admin-shell-consolidation=ok\n";
