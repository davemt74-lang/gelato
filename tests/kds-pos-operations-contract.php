<?php
declare(strict_types=1);

function ops_assert(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function ops_read(string $path): string {$v=file_get_contents(__DIR__.'/../'.$path);if($v===false)throw new RuntimeException('Could not read '.$path);return $v;}

$kds=ops_read('js/kds.js');
$dash=ops_read('js/kds-dashboard.js');
$history=ops_read('api/kds-history.php');
$pos=ops_read('pos.php');
$posJs=ops_read('js/pos.js');
$posReady=ops_read('api/pos-ready.php');
$posCore=ops_read('includes/pos-core.php');

ops_assert(str_contains($kds,"options.headers['X-CSRF-Token']=C.csrf||''"),'Full-screen KDS mutations must send the accepted CSRF request header.');
ops_assert(!str_contains($kds,'body.csrf=C.csrf'),'Full-screen KDS must not use the rejected csrf JSON field.');
ops_assert(str_contains($dash,"options.headers['X-CSRF-Token']=C.csrf||''"),'KDS configuration mutations must send the accepted CSRF request header.');
ops_assert(!str_contains($dash,'body.csrf=C.csrf'),'KDS configuration must not use the rejected csrf JSON field.');
ops_assert(str_contains($kds,'data-live-age')&&str_contains($kds,'setInterval(tickAges,1000)'),'KDS ticket/item clocks must update every second between server refreshes.');
ops_assert(str_contains($kds,"S.history?await historyApi():await liveApi()"),'KDS must expose a dedicated history mode.');
ops_assert(str_contains($history,'INTERVAL 24 HOUR'),'KDS order history must cover the prior 24 hours.');
ops_assert(str_contains($history,"k.status IN ('completed','cancelled')"),'KDS history must include completed/cancelled kitchen work only.');

ops_assert(str_contains($pos,'id="posMenuButton"')&&str_contains($pos,'id="activeTicketsDrawer"'),'POS must expose the left hamburger active-ticket drawer.');
ops_assert(str_contains($pos,'data-pos-view="menu"')&&str_contains($pos,'data-pos-view="floor"')&&str_contains($pos,'data-pos-view="ready"'),'POS header must expose Menu, Floor Plan, and READY workspace views.');
ops_assert(strpos($pos,'data-pos-view="menu"')<strpos($pos,'class="top-actions"'),'POS Menu/Floor/READY controls must live in the main header, not a second workspace row.');
ops_assert(!str_contains($pos,'<div class="logo">')&&!str_contains($pos,'class="brand-copy"'),'POS header logo/title block must be removed.');
ops_assert(!str_contains($pos,'id="floorMeta"')&&!str_contains($pos,'id="floorNotice"')&&!str_contains($pos,'id="floorRefresh"')&&!str_contains($pos,'id="floorToggle"'),'POS floor view must not render legacy title, status banner, refresh, or back-to-menu chrome.');
ops_assert(str_contains($pos,'.floor-view{overflow:hidden;padding:0}')&&str_contains($pos,'.floor-shell{height:100%;min-height:0;background:#f8f8f5;border:0;border-radius:0;padding:0'),'POS floor canvas must use the entire available workspace without an inset card/header.');
ops_assert(str_contains($pos,'id="readyTickets"')&&str_contains($pos,'id="readyBadge"'),'POS must render a dedicated READY ticket surface and header badge.');
ops_assert(str_contains($pos,'.menu-scroll{min-height:0;overflow-y:auto'),'POS menu must retain an independent vertical scroll region.');
ops_assert(str_contains($posCore,'t.id table_id')&&str_contains($posJs,'Table ID #${tableId}')&&str_contains($posJs,'Opened by'),'Active ticket drawer must expose the canonical service-table ID and staff user.');
ops_assert(str_contains($posJs,"function switchView(view)")&&str_contains($posJs,"switchView('menu')")&&str_contains($posJs,"view==='ready'"),'POS Menu, Floor Plan, and READY must be first-class switchable workspace sections.');
ops_assert(str_contains($posJs,"if(t.checkPublicId){selectCheck(t.checkPublicId);return}"),'Occupied mapped service points must reopen their existing active check.');
ops_assert(str_contains($posJs,"if(String(t.state)!=='available')"),'Unavailable mapped service points must not start duplicate checks.');
ops_assert(str_contains($posJs,'function renderReady()')&&str_contains($posJs,"fetch('api/pos-ready.php?locationId='")&&str_contains($posJs,'setInterval(refreshReady,4000)'),'POS must poll and render canonical KDS-ready tickets.');
ops_assert(str_contains($posReady,"require_once __DIR__.'/../includes/kds-production.php'")&&str_contains($posReady,'readyToBump'),'POS READY feed must reuse the KDS production board ready-to-bump definition.');
ops_assert(str_contains($posReady,'checkStatus')&&str_contains($posReady,"!=='open'"),'POS READY feed must exclude closed checks.');
ops_assert(!is_file(__DIR__.'/../includes/pos-floor-operations.php')&&!is_file(__DIR__.'/../api/pos-floor-sync.php'),'This pass must reuse the existing floor/table mapping architecture rather than create a duplicate mapping layer.');

echo "kds-pos-operations-ok\n";
