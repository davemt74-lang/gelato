from pathlib import Path
import re

def replace_once(path, old, new, label):
    p=Path(path); s=p.read_text()
    count=s.count(old)
    if count != 1:
        raise SystemExit(f'{label}: expected 1 exact match, found {count}')
    p.write_text(s.replace(old,new,1))

def regex_once(path, pattern, repl, label, flags=0):
    p=Path(path); s=p.read_text()
    out,n=re.subn(pattern,repl,s,count=1,flags=flags)
    if n != 1:
        raise SystemExit(f'{label}: expected 1 regex match, found {n}')
    p.write_text(out)

# First-class registry node.
replace_once(
    'includes/agent-node-registry.php',
    "        'online_orders'=>[\n",
    "        'timeclock'=>[\n            'label'=>'Time Clock + Attendance',\n            'route'=>'api/timeclock-agent.php',\n            'domain'=>'timeclock_attendance',\n            'mode'=>'read_confirmed_write',\n        ],\n        'online_orders'=>[\n",
    'registry timeclock node'
)

# Shared legacy route normalizes to the first-class node and confirmations return to it.
replace_once(
    'includes/agent-workspace-core.php',
    "    $time='/\\b(clock(?:ed)?\\s+(?:me\\s+)?(?:in|out)|time\\s*clock|break|attendance|no.?show|late|actual labor|on clock|clock status)\\b/u';\n",
    "    $time='/\\b(clock(?:ed)?\\s+(?:me\\s+)?(?:in|out)|time\\s*clock|(?:start|take|begin|end|finish|stop)\\s+(?:my\\s+)?break|my break|attendance|no.?show|late|actual labor|on clock|clock status)\\b/u';\n",
    'narrow global timeclock break intent'
)
replace_once(
    'includes/agent-workspace-core.php',
    "        if($pendingNode==='online_orders'&&(app_has_permission('online_orders.fulfill',$user)||app_has_permission('order_recovery.view',$user)||app_has_permission('order_recovery.manage',$user)||app_has_permission('order_recovery.refund',$user)||app_has_permission('pos.use',$user)||app_has_permission('kds.view',$user)||app_has_permission('crm.view',$user)))return gaw_node_route('online_orders','online_order_confirmation');\n",
    "        if($pendingNode==='online_orders'&&(app_has_permission('online_orders.fulfill',$user)||app_has_permission('order_recovery.view',$user)||app_has_permission('order_recovery.manage',$user)||app_has_permission('order_recovery.refund',$user)||app_has_permission('pos.use',$user)||app_has_permission('kds.view',$user)||app_has_permission('crm.view',$user)))return gaw_node_route('online_orders','online_order_confirmation');\n        if($pendingNode==='timeclock'&&app_has_permission('timeclock.agent',$user))return gaw_node_route('timeclock','timeclock_confirmation');\n",
    'core confirmation route'
)
replace_once(
    'includes/agent-workspace-core.php',
    "    if(preg_match($time,$text)&&(app_has_permission('timeclock.agent',$user)||app_has_permission('timeclock.self',$user)||app_has_permission('attendance.view',$user)))return ['route'=>'api/timeclock-agent.php','domain'=>'timeclock'];\n",
    "    if(preg_match($time,$text)&&app_has_permission('timeclock.agent',$user))return function_exists('gaw_node_route')?gaw_node_route('timeclock'):['route'=>'api/timeclock-agent.php','domain'=>'timeclock_attendance'];\n",
    'core explicit timeclock route'
)

# Shared page-context router.
replace_once(
    'api/agent-workspace.php',
    "// api/customer-crm-agent.php api/catering-agent.php api/wholesale-agent.php api/prep-intelligence-agent.php api/operations-agent.php api/kds-agent.php api/live-shift-agent.php api/front-of-house-agent.php api/equipment-agent.php api/recipe-agent.php api/online-order-agent.php\n",
    "// api/customer-crm-agent.php api/catering-agent.php api/wholesale-agent.php api/prep-intelligence-agent.php api/operations-agent.php api/kds-agent.php api/live-shift-agent.php api/front-of-house-agent.php api/equipment-agent.php api/recipe-agent.php api/online-order-agent.php api/timeclock-agent.php\n",
    'workspace compatibility marker'
)
replace_once(
    'api/agent-workspace.php',
    "        $isOnlineOrdersContext=in_array($module,['online_orders','pickup_fulfillment','order_recovery'],true)&&$canOnlineOrders;\n",
    "        $isOnlineOrdersContext=in_array($module,['online_orders','pickup_fulfillment','order_recovery'],true)&&$canOnlineOrders;\n        $isTimeclockContext=$module==='timeclock'&&app_has_permission('timeclock.agent',$user);\n",
    'workspace timeclock context flag'
)
replace_once(
    'api/agent-workspace.php',
    "            if($pendingNode==='online_orders'&&$canOnlineOrders)app_json_response(['ok'=>true]+gaw_node_route('online_orders','online_order_confirmation'));\n",
    "            if($pendingNode==='online_orders'&&$canOnlineOrders)app_json_response(['ok'=>true]+gaw_node_route('online_orders','online_order_confirmation'));\n            if($pendingNode==='timeclock'&&app_has_permission('timeclock.agent',$user))app_json_response(['ok'=>true]+gaw_node_route('timeclock','timeclock_confirmation'));\n",
    'workspace pending timeclock route'
)
replace_once(
    'api/agent-workspace.php',
    "\n        $liveShiftIntent=preg_match(",
    "\n        if($isTimeclockContext){\n            $localIntent=preg_match('/\\b(this employee|selected employee|my clock|clock status|clocked in|clocked out|break|attendance|late|no[ -]?show|labor|labor variance|scheduled labor|actual labor|who is working|who is clocked in|what needs attention|what should we do|what(?:\\x27s| is) going on)\\b/u',$text)===1;\n            if($localIntent||$confirmationIntent)app_json_response(['ok'=>true]+gaw_node_route('timeclock','timeclock_context'));\n        }\n\n        $liveShiftIntent=preg_match(",
    'workspace local timeclock context'
)

# Brain composition: Time Clock is another signal adapter over the same canonical snapshot.
replace_once(
    'api/brain-orchestrator.php',
    "require_once __DIR__.'/../includes/online-order-agent-brain.php';\n",
    "require_once __DIR__.'/../includes/online-order-agent-brain.php';\nrequire_once __DIR__.'/../includes/timeclock-agent-brain.php';\n",
    'brain require timeclock'
)
replace_once(
    'api/brain-orchestrator.php',
    "        return online_order_agent_merge_brain_snapshot(\n",
    "        return timeclock_agent_merge_brain_snapshot(\n            $pdo,\n            $user,\n            online_order_agent_merge_brain_snapshot(\n",
    'brain wrap timeclock'
)
replace_once(
    'api/brain-orchestrator.php',
    "            )\n        );\n    };\n",
    "            )\n        ));\n    };\n",
    'brain close timeclock wrapper'
)
replace_once(
    'api/brain-orchestrator.php',
    "'onlineOrdersIntegrated'=>true]",
    "'onlineOrdersIntegrated'=>true,'timeclockIntegrated'=>true]",
    'brain audit integration marker'
)

# Time Clock page: shared Agent canvas replaces the old page-specific text bar; voice remains opt-in UI.
replace_once('timeclock.php','<title>Time Clock + Voice Agent</title>','<title>Time Clock + Attendance | Restaurant Admin</title>','page title')
replace_once('timeclock.php','<strong>Time Clock + Voice Agent</strong><div class="muted">Attendance · Labor · Employee AI</div>','<strong>Time Clock + Attendance</strong><div class="muted">Attendance · Labor · Voice personalization</div>','page brand')
replace_once('timeclock.php','padding-bottom:108px','padding-bottom:32px','page bottom spacing')
regex_once(
    'timeclock.php',
    r"\.agentbar\{.*?\.listen\.on\{.*?\}",
    '',
    'remove legacy agentbar css',
    re.S
)
replace_once(
    'timeclock.php',
    '<div class="actions"><button class="btn" id="listeningToggle">Enable Listening Mode</button><label class="muted"><input type="checkbox" id="speakAlerts"> Speak proactive alerts</label></div>',
    '<div class="actions"><button class="btn" id="listeningToggle">Enable Listening Mode</button><button class="btn" id="talkButton" type="button">Speak command</button><label class="muted"><input type="checkbox" id="speakAlerts"> Speak proactive alerts</label></div>',
    'add voice command button'
)
regex_once(
    'timeclock.php',
    r'<\?php if\(\$canAgent\):\?><div class="status" id="agentStatus"></div><div class="agentbar">.*?</div><\?php endif;\?>',
    '<?php if($canAgent):?><div class="status" id="agentStatus"></div><?php endif;?>',
    'remove legacy agentbar markup',
    re.S
)
replace_once(
    'timeclock.php',
    "canProactive:<?=$canProactive?'true':'false'?>};",
    "canProactive:<?=$canProactive?'true':'false'?>,today:<?=json_encode(date('Y-m-d'),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>};",
    'server local date context'
)
replace_once(
    'timeclock.php',
    '<script src="js/universal-admin-page-shell.js?v=20260915-3"></script><script src="js/timeclock-voice.js?v=20260913-1"></script></body></html>',
    '<script src="js/universal-admin-page-shell.js?v=20260915-3"></script><script src="js/timeclock-voice.js?v=20260916-1"></script><script src="js/agent-page-context.js?v=20260916-1"></script><script src="js/timeclock-agent-context.js?v=20260916-1"></script><script src="js/global-agent.js?v=20260916-1"></script><script src="js/dynamic-agent-canvas.js?v=20260916-1"></script></body></html>',
    'shared Agent scripts'
)

# Voice JS keeps voice controls but routes direct spoken commands through the same confirmed Agent core + page context.
replace_once(
    'js/timeclock-voice.js',
    "async function sendAgent(text,voice=false){if(!text.trim())return;status('Gelato is checking your restaurant context…');try{const d=await post('api/timeclock-agent.php',{message:text,voice,voiceEventId:voice?voiceEventId:''});status(d.answer);",
    "async function sendAgent(text,voice=false){if(!text.trim())return;status('Gelato is checking your restaurant context…');try{const pageContext=window.GelatoTimeclockAgentContext?.transportSnapshot?.()||{module:'timeclock',selectedDate:$('#dateFilter')?.value||C.today||null};const d=await post('api/timeclock-agent.php',{message:text,voice,voiceEventId:voice?voiceEventId:'',pageContext});status(d.answer);",
    'voice Agent page context'
)
replace_once(
    'js/timeclock-voice.js',
    'rows.map(r=>`<article class="row"><strong>${esc(r.display_name)}</strong>',
    'rows.map(r=>`<article class="row" data-timeclock-user-id="${Number(r.user_id)||0}"><strong>${esc(r.display_name)}</strong>',
    'attendance selectable row context'
)

print('timeclock finalizer applied')
