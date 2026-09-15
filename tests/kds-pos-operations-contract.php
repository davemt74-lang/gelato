<?php
declare(strict_types=1);

function ops_assert(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function ops_read(string $path): string {$v=file_get_contents(__DIR__.'/../'.$path);if($v===false)throw new RuntimeException('Could not read '.$path);return $v;}

$kds=ops_read('js/kds.js');
$dash=ops_read('js/kds-dashboard.js');
$history=ops_read('api/kds-history.php');
$pos=ops_read('pos.php');
$posJs=ops_read('js/pos.js');

ops_assert(str_contains($kds,"options.headers['X-CSRF-Token']=C.csrf||''"),'Full-screen KDS mutations must send the accepted CSRF request header.');
ops_assert(!str_contains($kds,'body.csrf=C.csrf'),'Full-screen KDS must not use the rejected csrf JSON field.');
ops_assert(str_contains($dash,"options.headers['X-CSRF-Token']=C.csrf||''"),'KDS configuration mutations must send the accepted CSRF request header.');
ops_assert(!str_contains($dash,'body.csrf=C.csrf'),'KDS configuration must not use the rejected csrf JSON field.');
ops_assert(str_contains($kds,'data-live-age')&&str_contains($kds,'setInterval(tickAges,1000)'),'KDS ticket/item clocks must update every second between server refreshes.');
ops_assert(str_contains($kds,"S.history?await historyApi():await liveApi()"),'KDS must expose a dedicated history mode.');
ops_assert(str_contains($history,'INTERVAL 24 HOUR'),'KDS order history must cover the prior 24 hours.');
ops_assert(str_contains($history,"k.status IN ('completed','cancelled')"),'KDS history must include completed/cancelled kitchen work only.');

ops_assert(str_contains($pos,'id="posMenuButton"')&&str_contains($pos,'id="activeTicketsDrawer"'),'POS must expose the left hamburger active-ticket drawer.');
ops_assert(str_contains($pos,'data-pos-view="menu"')&&str_contains($pos,'data-pos-view="floor"'),'POS must have separate Menu and Floor Plan workspace views.');
ops_assert(str_contains($pos,'.menu-scroll{min-height:0;overflow-y:auto'),'POS menu must retain an independent vertical scroll region.');
ops_assert(str_contains($posJs,'Table ID:')&&str_contains($posJs,'Opened by'),'Active ticket drawer must expose mapped table ID and staff user.');
ops_assert(str_contains($posJs,"function switchView(view)")&&str_contains($posJs,"switchView('menu')"),'POS Floor Plan must be a first-class switchable workspace section.');
ops_assert(str_contains($posJs,"if(t.checkPublicId){selectCheck(t.checkPublicId);return}"),'Occupied mapped service points must reopen their existing active check.');
ops_assert(str_contains($posJs,"if(String(t.state)!=='available')"),'Unavailable mapped service points must not start duplicate checks.');
ops_assert(!is_file(__DIR__.'/../includes/pos-floor-operations.php')&&!is_file(__DIR__.'/../api/pos-floor-sync.php'),'This pass must reuse the existing floor/table mapping architecture rather than create a duplicate mapping layer.');

echo "kds-pos-operations-ok\n";
