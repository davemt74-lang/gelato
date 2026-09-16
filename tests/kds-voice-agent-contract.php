<?php
declare(strict_types=1);

function kva_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function kva_text(string $path):string{$value=@file_get_contents($path);if($value===false)throw new RuntimeException('Unable to read '.$path);return $value;}

$root=dirname(__DIR__);
$page=kva_text($root.'/kds.php');
$global=kva_text($root.'/js/global-agent.js');
$sidebar=kva_text($root.'/js/kds-agent-sidebar.js');
$context=kva_text($root.'/js/kds-agent-context.js');
$agent=kva_text($root.'/api/kds-agent.php');
$registry=kva_text($root.'/includes/agent-node-registry.php');

kva_assert(str_contains($page,'data-agent-voice-only="1"'),'KDS must opt into voice-only Agent mode.');
kva_assert(str_contains($page,'js/agent-page-context.js')&&str_contains($page,'js/kds-agent-context.js')&&str_contains($page,'js/global-agent.js')&&str_contains($page,'js/kds-agent-sidebar.js'),'KDS must load shared Agent context, the main Agent Brain transport, and the KDS voice sidebar.');
kva_assert(str_contains($global,"document.body?.dataset.agentVoiceOnly === '1'"),'Global Agent must suppress its keyboard chat bar on voice-only workstations.');
kva_assert(!str_contains($sidebar,'<textarea')&&!str_contains($sidebar,'type="text"'),'KDS Agent sidebar must not expose keyboard/text-entry input.');
kva_assert(str_contains($sidebar,'Tap and speak')&&str_contains($sidebar,'talkOnce'),'KDS sidebar must expose tap-to-talk through the main Agent voice stack.');
kva_assert(str_contains($sidebar,'Hands-free “Hey Gelato”')&&str_contains($sidebar,'setListening'),'KDS sidebar must support optional hands-free wake mode.');

kva_assert(str_contains($sidebar,"const ANNOUNCE_KEY = 'gelato.kds.voiceNewOrders.v1'")&&str_contains($sidebar,'localStorage.setItem(ANNOUNCE_KEY'),'New-order voice announcement preference must persist per KDS device.');
kva_assert(str_contains($sidebar,'if (!previous) return;'),'The first observed board must establish a baseline instead of announcing existing tickets.');
kva_assert(str_contains($sidebar,"type:'new_order'")&&str_contains($sidebar,"type:'order_update'"),'KDS observer must distinguish new tickets from later fired additions.');
kva_assert(str_contains($sidebar,'!priorItems.has(String(item.public_id))'),'Order updates must announce only newly observed ticket items.');
kva_assert(str_contains($sidebar,'state.voiceQueue.push(message)')&&str_contains($sidebar,'pumpSpeech()'),'Spoken KDS announcements must be queued rather than overlapping.');
kva_assert(str_contains($sidebar,'Special instruction:')&&str_contains($sidebar,'ticket.tableName')&&str_contains($sidebar,'ticket.checkNumber'),'New-order speech must include order/table context and special instructions.');
kva_assert(str_contains($sidebar,'Read active orders now')&&str_contains($sidebar,"Read the active KDS orders aloud"),'KDS must provide an explicit read-current-board voice action.');

kva_assert(str_contains($context,"module: 'kds'")&&str_contains($context,'locationId')&&str_contains($context,'checkPublicId'),'KDS page context must send stable identifiers to the shared Agent.');
kva_assert(str_contains($agent,"app_has_permission('kds.view',\$user)")&&str_contains($agent,'operational_location_allowed'),'KDS Agent knowledge must be permission- and location-scoped server-side.');
kva_assert(str_contains($agent,'kds_production_board')&&str_contains($agent,'kda_recent_history'),'KDS Agent must use authoritative live production data plus recent completed-order history.');
kva_assert(str_contains($agent,'special_instructions')&&str_contains($agent,'station_name')&&str_contains($agent,'guestCount'),'KDS Agent must understand special instructions, station state, and guest/order context.');
kva_assert(str_contains($agent,"'skill'=>'kds.read_orders'")&&str_contains($agent,"'skill'=>'kds.order_detail'")&&str_contains($agent,"'skill'=>'kds.order_history'"),'KDS Agent must answer live-board, individual-order, and recent-history questions.');
kva_assert(str_contains($registry,"'kds'")&&str_contains($registry,"'route'=>'api/kds-agent.php'"),'KDS must be a first-class node in the main Agent Brain registry.');

echo "kds-voice-agent-contract-ok\n";