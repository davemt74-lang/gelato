<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/menu-training-knowledge.php';

$pdo=app_pdo();
function pca_assert(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function pca_text(string $path): string {$value=@file_get_contents($path);if($value===false)throw new RuntimeException('Unable to read '.$path);return $value;}

$root=dirname(__DIR__);
$slug='pos-agent-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['POS Agent CI '.$slug]);
$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_sections (organization_id,name,slug,description,status,sort_order) VALUES (?,'Wood Fired Pizza',?,'Stonefellow pizzas are wood-fired and should be described using the current menu wording.','active',1)")->execute([$org,'pizza-'.$slug]);
$section=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_items (organization_id,section_id,name,slug,description,preparation_notes,is_active) VALUES (?,?,'Margherita Pizza',?,'Tomato, mozzarella and basil on pizza dough.','Finish with fresh basil after the bake.',1)")->execute([$org,$section,'margherita-'.$slug]);
$item=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO menu_item_prices (menu_item_id,option_name,size_code,amount,currency,sort_order) VALUES (?,'12 inch','12',18.00,'USD',1)")->execute([$item]);
$ingredientInsert=$pdo->prepare("INSERT INTO ingredients (organization_id,canonical_name,slug,category,verification_status) VALUES (?,?,?,'menu-description','unverified')");
$linkInsert=$pdo->prepare("INSERT INTO menu_item_ingredients (menu_item_id,ingredient_id,display_name,is_optional,can_remove,sort_order) VALUES (?,?,?,0,1,?)");
foreach([['mozzarella','mozzarella'],['pizza dough','pizza-dough'],['tomato','tomato'],['basil','basil']] as $index=>$ingredient){
    $ingredientInsert->execute([$org,$ingredient[0],$ingredient[1].'-'.$slug]);
    $ingredientId=(int)$pdo->lastInsertId();
    $linkInsert->execute([$item,$ingredientId,$ingredient[0],$index]);
}

$natural=menu_training_search_items($pdo,$org,"What's in the Margherita?",12);
pca_assert(count($natural)===1&&(int)$natural[0]['id']===$item,'Natural-language item lookup must resolve Margherita without requiring the entire question as a literal LIKE string.');
pca_assert(in_array('mozzarella',$natural[0]['ingredients'],true)&&in_array('pizza dough',$natural[0]['ingredients'],true),'Shared menu knowledge must expose canonical Ingredient Catalog links.');
pca_assert((string)$natural[0]['preparationNotes']==='Finish with fresh basil after the bake.','Shared menu knowledge must expose preparation notes.');
pca_assert(str_contains((string)$natural[0]['sectionNote'],'wood-fired'),'Shared menu knowledge must expose section/service notes.');
$direct=array_column($natural[0]['allergens']['direct'],'id');
pca_assert(in_array('milk',$direct,true)&&in_array('wheat',$direct,true),'Shared allergen rules must identify direct milk and wheat indicators from recorded ingredients.');
pca_assert(str_contains((string)$natural[0]['allergens']['warning'],'not')||str_contains(mb_strtolower((string)$natural[0]['allergens']['warning'],'UTF-8'),'verify'),'Allergen knowledge must retain a verification safety boundary.');
$menuKnowledge=pca_text($root.'/includes/menu-training-knowledge.php');
pca_assert(!str_contains($menuKnowledge,'ORDER BY mi.sort_order,mi.id'),'Shared menu knowledge must not order by a nonexistent menu_item_ingredients id column.');
pca_assert(str_contains($menuKnowledge,'ORDER BY mi.sort_order,mi.ingredient_id'),'Shared menu knowledge must use the canonical ingredient link identity as its deterministic secondary sort.');

$posPage=pca_text($root.'/pos.php');
pca_assert(str_contains($posPage,'js/pos.js?v=20260915-pos-agent1'),'POS page must use the contextual-Agent cache key so deployments do not reuse the pre-Agent POS entrypoint.');
$loader=pca_text($root.'/js/pos.js');
pca_assert(str_contains($loader,'js/agent-page-context.js')&&str_contains($loader,'js/pos-agent-context.js'),'POS entrypoint must load shared Agent context before the POS provider.');
pca_assert(str_contains($loader,'js/pos-runtime.js'),'POS entrypoint must preserve the existing specialized POS runtime.');
pca_assert(str_contains($loader,'js/global-agent.js')&&str_contains($loader,'js/dynamic-agent-canvas.js'),'POS entrypoint must load the shared Agent bar and response drawer.');
$context=pca_text($root.'/js/pos-agent-context.js');
$sharedContext=pca_text($root.'/js/agent-page-context.js');
pca_assert(str_contains($context,"module: 'pos'")&&str_contains($context,'checkPublicId'),'POS page context must identify the module and active check.');
pca_assert(str_contains($context,'function transportSnapshot()')&&str_contains($context,'checkPublicId: context.checkPublicId')&&str_contains($context,'focusedLineId: context.focusedLineId'),'POS provider must expose minimized identifier-only transport context.');
pca_assert(str_contains($context,'GelatoAgentPageContext?.register?.(provider)'),'POS must register its provider with the shared Agent page-context layer.');
pca_assert(!str_contains($context,'body.pageContext')&&!str_contains($context,'withAgentContext'),'POS must not maintain a second Agent request injector after the shared transport refactor.');
pca_assert(str_contains($sharedContext,"file === 'agent-workspace.php' || /-agent\\.php$/i.test(file)")&&str_contains($sharedContext,'body.pageContext = context'),'Shared Agent context must attach page context to Agent routing and routed skills.');
pca_assert(!str_contains($context,'localStorage'),'POS Agent context must not copy browser-local Training performance telemetry into the transaction context.');

$route=pca_text($root.'/api/agent-workspace.php');
pca_assert(str_contains($route,"route'=>'api/pos-agent.php'")&&str_contains($route,"domain'=>'pos_context'"),'Agent Workspace must route POS-context menu/customer/check questions to the POS Agent skill.');
$agent=pca_text($root.'/api/pos-agent.php');
pca_assert(str_contains($agent,'pos_check_base($pdo,$org,$checkPublicId,false)'),'POS Agent must re-resolve the check server-side instead of trusting browser transaction content.');
pca_assert(str_contains($agent,'pos_agent_location_allowed'),'POS Agent must enforce location-scoped POS permission.');
pca_assert(str_contains($agent,'menu_training_items_by_ids'),'POS Agent must use the shared Training menu/ingredient knowledge for cart items.');
pca_assert(str_contains($agent,"return app_has_permission('crm.view',\$user);"),'Repeat-order history, favorites, average check, and lifetime spend must require CRM view permission.');
pca_assert(str_contains($agent,"app_has_permission('crm.pos_link',\$user)")&&str_contains($agent,'pos_agent_can_customer_offers'),'POS customer-link access may surface active customer offers without unlocking CRM history.');
pca_assert(str_contains($agent,'customer_inbox_messages'),'POS Agent must support active customer promotion/reward context when authorized.');
pca_assert(!str_contains($agent,'restaurant-mistakes')&&!str_contains($agent,'restaurant-quiz-history')&&!str_contains($agent,'restaurant-certifications'),'POS Agent must not consume employee Training performance history as customer transaction context.');

$menuEndpoint=pca_text($root.'/api/menu-knowledge.php');
pca_assert(str_contains($menuEndpoint,"menu_training_allergen_knowledge()"),'Training runtime must receive the canonical server-side allergen knowledge.');
$fallback=pca_text($root.'/data/allergen-rules.js');
pca_assert(str_contains($fallback,'window.ALLERGEN_KNOWLEDGE = window.ALLERGEN_KNOWLEDGE ||'),'Legacy allergen JS must remain only a compatibility fallback.');

echo "pos-contextual-agent contract passed\n";