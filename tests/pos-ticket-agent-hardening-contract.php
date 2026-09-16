<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$paths=[
    'page'=>$root.'/pos.php',
    'removeApi'=>$root.'/api/pos-item-remove.php',
    'runtime'=>$root.'/js/pos-runtime.js',
    'context'=>$root.'/js/pos-agent-context.js',
    'loader'=>$root.'/js/pos.js',
    'router'=>$root.'/api/agent-workspace.php',
    'agent'=>$root.'/api/pos-agent.php',
];
$files=[];foreach($paths as $name=>$path){if(!is_file($path)){fwrite(STDERR,"Missing {$name}: {$path}\n");exit(1);}$files[$name]=(string)file_get_contents($path);}

$checks=[
    'remove endpoint requires POS permission'=>str_contains($files['removeApi'],"app_has_permission('pos.use',$user)"),
    'remove endpoint enforces assigned location'=>str_contains($files['removeApi'],"operational_location_allowed($pdo,$user,'pos.use',$locationId)"),
    'remove endpoint blocks captured tender edits'=>str_contains($files['removeApi'],"status='captured'"),
    'remove endpoint blocks kitchen-sent line'=>str_contains($files['removeApi'],'kds_assert_pos_line_mutable'),
    'remove endpoint removes only active line'=>str_contains($files['removeApi'],"DELETE FROM pos_check_items")&&str_contains($files['removeApi'],"status='active'"),
    'remove endpoint recalculates ticket'=>str_contains($files['removeApi'],'pos_recalculate_check'),
    'remove endpoint audits action'=>str_contains($files['removeApi'],'pos.item_removed'),
    'cart removal does not require void permission'=>!str_contains($files['removeApi'],"pos.void"),
    'runtime exposes remove action'=>str_contains($files['runtime'],'data-act="remove"')&&str_contains($files['runtime'],"action==='item.remove'"),
    'quantity one minus removes directly'=>str_contains($files['runtime'],"Number(item.quantity)<=1")&&str_contains($files['runtime'],"mutate('item.remove'"),
    'remove path has no confirmation prompt'=>!preg_match("/act==='remove'.{0,240}prompt\\(/s",$files['runtime']),
    'sent item uses void instead of remove'=>str_contains($files['runtime'],'Void sent item'),
    'context observes remove API payload'=>str_contains($files['context'],"'pos-item-remove.php'"),
    'context focuses newly added line'=>str_contains($files['context'],'const newlyAdded = state.lineItemIds.filter')&&str_contains($files['context'],'state.focusedLineId = newlyAdded[newlyAdded.length - 1]'),
    'single ticket line becomes default Agent focus'=>str_contains($files['context'],'state.lineItemIds.length === 1'),
    'focused line remains transport-minimal'=>str_contains($files['context'],'focusedLineId: context.focusedLineId')&&!str_contains($files['context'],'item_name_snapshot'),
    'POS loader runs page context before runtime'=>strpos($files['loader'],'agent-page-context.js')<strpos($files['loader'],'pos-runtime.js'),
    'POS loader uses fresh cache key'=>str_contains($files['loader'],"20260916-pos-context2"),
    'POS page busts old loader cache'=>str_contains($files['page'],'js/pos.js?v=20260916-pos-context2'),
    'router treats cook questions as POS intent'=>str_contains($files['router'],'|cook|cooking|')&&str_contains($files['router'],"gaw_node_route('pos')"),
    'POS Agent loads cart menu knowledge'=>str_contains($files['agent'],'menu_training_items_by_ids')&&str_contains($files['agent'],'pos_agent_cart_item_ids'),
    'POS Agent resolves focused line'=>str_contains($files['agent'],'focusedLineId')&&str_contains($files['agent'],'pos_agent_pick_items'),
    'POS Agent preparation answers use Menu Notes'=>str_contains($files['agent'],'pos_agent_prep_answer')&&str_contains($files['agent'],'Menu Notes'),
];

$failed=[];foreach($checks as $label=>$passed)if(!$passed)$failed[]=$label;
if($failed){fwrite(STDERR,"POS ticket/Agent hardening contract failed:\n - ".implode("\n - ",$failed)."\n");exit(1);}
echo 'POS ticket/Agent hardening contract passed ('.count($checks)." checks).\n";
