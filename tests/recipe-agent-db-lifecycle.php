<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/agent-node-registry.php';
require __DIR__.'/../includes/agent-workspace-core.php';
require __DIR__.'/../includes/recipe-agent-core.php';
require __DIR__.'/../includes/recipe-agent-brain.php';

function radb_assert(bool $ok,string $message): void
{
    if(!$ok)throw new RuntimeException($message);
}
function radb_scalar(PDO $pdo,string $sql,array $params=[]): mixed
{
    $q=$pdo->prepare($sql);$q->execute($params);return $q->fetchColumn();
}

$pdo=app_pdo();
$_SESSION=[];

$pdo->exec("INSERT INTO organizations (name,status,timezone) VALUES ('Recipe Agent CI','active','America/Phoenix')");
$org=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES ('recipe-agent@example.test','x','Recipe','Agent','Recipe Agent CI','active')");
$uid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,status) VALUES (?,?,'active')")->execute([$org,$uid]);
$user=['id'=>$uid,'organization_id'=>$org,'permissions'=>['*']];

$ingredients=[
    ['quantity'=>'2','unit'=>'cups','ingredient'=>'flour','notes'=>''],
    ['quantity'=>'1','unit'=>'cup','ingredient'=>'milk','notes'=>'whole'],
    ['quantity'=>'1/2','unit'=>'tsp','ingredient'=>'salt','notes'=>''],
];
$instructions=['Mix ingredients.','Bake until finished.'];
$pdo->prepare("INSERT INTO recipes (organization_id,public_id,name,category,description,yield_quantity,yield_unit,ingredients_json,instructions_json,notes,status,mapping_status,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?, 'active','unmapped',?,?)")
    ->execute([$org,'recipe-agent-ci','Margherita Dough','Dough','House production dough',10,'portions',json_encode($ingredients,JSON_THROW_ON_ERROR),json_encode($instructions,JSON_THROW_ON_ERROR),'Ferment overnight.',$uid,$uid]);
$recipeId=(int)$pdo->lastInsertId();
restaurant_brain_sync_recipe($pdo,$org,$recipeId,$uid);
$pdo->prepare("INSERT INTO recipes (organization_id,public_id,name,category,description,yield_quantity,yield_unit,ingredients_json,instructions_json,notes,status,mapping_status,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?, 'active','unmapped',?,?)")
    ->execute([$org,'recipe-agent-alt','Tomato Sauce','Sauce','House tomato sauce',5,'quarts',json_encode([['quantity'=>'1','unit'=>'can','ingredient'=>'tomatoes','notes'=>'']],JSON_THROW_ON_ERROR),json_encode(['Blend and simmer.'],JSON_THROW_ON_ERROR),'',$uid,$uid]);
$altRecipeId=(int)$pdo->lastInsertId();
restaurant_brain_sync_recipe($pdo,$org,$altRecipeId,$uid);
$context=['module'=>'recipes','selectedRecipePublicId'=>'recipe-agent-ci'];

// Registry + explicit routing. Generic ingredient wording stays neutral outside Recipe context.
$node=gaw_agent_node('recipes');
radb_assert(($node['route']??'')==='api/recipe-agent.php','Recipe Agent node is not registered.');
$route=gaw_route($user,'Scale the Margherita Dough recipe to 20 portions');
radb_assert(($route['route']??'')==='api/recipe-agent.php','Explicit recipe intent did not route to Recipe Agent.');
$generic=gaw_route($user,'Show me the ingredients.');
radb_assert(($generic['domain']??'')!=='recipe_production_standards','Generic ingredient wording was globally hijacked by Recipe Agent.');

// Read selected recipe context, scaling and allergen keyword screening without writes.
$read=recipe_agent_handle($pdo,$user,['message'=>'How do we make this recipe?','pageContext'=>$context]);
radb_assert(($read['data']['recipe']['publicId']??'')==='recipe-agent-ci','Selected recipe context was not resolved server-side.');
$named=recipe_agent_handle($pdo,$user,['message'=>'Show the Tomato Sauce recipe','pageContext'=>$context]);
radb_assert(($named['data']['recipe']['publicId']??'')==='recipe-agent-alt','Explicitly named recipe did not override selected page context.');
$beforeJson=(string)radb_scalar($pdo,"SELECT ingredients_json FROM recipes WHERE organization_id=? AND public_id=?",[$org,'recipe-agent-ci']);
$scaled=recipe_agent_handle($pdo,$user,['message'=>'Scale this recipe to 20 portions','pageContext'=>$context]);
radb_assert(($scaled['skill']??'')==='recipe.scale','Recipe scale read returned wrong skill.');
radb_assert(abs((float)($scaled['data']['scale']['factor']??0)-2.0)<0.0001,'Recipe scale factor is incorrect.');
radb_assert(($scaled['data']['scale']['ingredients'][0]['scaledQuantity']??'')==='4','Recipe quantity scaling is incorrect.');
$unitRejected=false;
try{recipe_agent_handle($pdo,$user,['message'=>'Scale this recipe to 20 quarts','pageContext'=>$context]);}catch(InvalidArgumentException $e){$unitRejected=str_contains($e->getMessage(),'recorded in portions');}
radb_assert($unitRejected,'Recipe scaling accepted a conflicting target yield unit.');
radb_assert((string)radb_scalar($pdo,"SELECT ingredients_json FROM recipes WHERE organization_id=? AND public_id=?",[$org,'recipe-agent-ci'])===$beforeJson,'Recipe scale read mutated the recipe.');
$allergen=recipe_agent_handle($pdo,$user,['message'=>'Check this recipe for allergens','pageContext'=>$context]);
radb_assert(in_array('milk/dairy',(array)($allergen['data']['keywordHits']??[]),true),'Dairy keyword screen did not find milk.');
radb_assert(in_array('wheat/gluten',(array)($allergen['data']['keywordHits']??[]),true),'Wheat keyword screen did not find flour.');

// Yield: Propose -> Confirm -> Execute, no pre-confirm mutation.
$yieldProposal=recipe_agent_handle($pdo,$user,['message'=>'set yield to 24 portions','pageContext'=>$context]);
radb_assert(($yieldProposal['data']['requiresConfirmation']??false)===true,'Recipe yield write skipped proposal state.');
radb_assert((float)radb_scalar($pdo,"SELECT yield_quantity FROM recipes WHERE organization_id=? AND public_id=?",[$org,'recipe-agent-ci'])===10.0,'Recipe yield changed before confirmation.');
$pendingRoute=gaw_route($user,'Confirm');
radb_assert(($pendingRoute['route']??'')==='api/recipe-agent.php','Pending Recipe confirmation did not route to Recipe Agent.');
$yieldConfirmed=recipe_agent_handle($pdo,$user,['message'=>'Confirm','pageContext'=>$context]);
radb_assert(($yieldConfirmed['skill']??'')==='recipe.action_confirmed','Recipe yield confirmation did not execute.');
radb_assert((float)radb_scalar($pdo,"SELECT yield_quantity FROM recipes WHERE organization_id=? AND public_id=?",[$org,'recipe-agent-ci'])===24.0,'Confirmed recipe yield was not persisted.');

// Explicitly named yield target overrides selected context and does not leak the recipe name into the unit.
$namedYield=recipe_agent_handle($pdo,$user,['message'=>'set yield to 12 quarts for Tomato Sauce','pageContext'=>$context]);
radb_assert(($namedYield['data']['requiresConfirmation']??false)===true,'Named-recipe yield proposal was not created.');
recipe_agent_handle($pdo,$user,['message'=>'Confirm','pageContext'=>$context]);
radb_assert((float)radb_scalar($pdo,"SELECT yield_quantity FROM recipes WHERE organization_id=? AND public_id=?",[$org,'recipe-agent-alt'])===12.0,'Named-recipe yield targeted the wrong recipe.');
radb_assert((string)radb_scalar($pdo,"SELECT yield_unit FROM recipes WHERE organization_id=? AND public_id=?",[$org,'recipe-agent-alt'])==='quarts','Named-recipe suffix leaked into the yield unit.');

// Add ingredient + Cancel leaves canonical JSON unchanged.
$ingredientBefore=(string)radb_scalar($pdo,"SELECT ingredients_json FROM recipes WHERE organization_id=? AND public_id=?",[$org,'recipe-agent-ci']);
$ingredientProposal=recipe_agent_handle($pdo,$user,['message'=>'add ingredient 2 tbsp olive oil','pageContext'=>$context]);
radb_assert(($ingredientProposal['data']['requiresConfirmation']??false)===true,'Recipe ingredient write skipped proposal state.');
$cancelled=recipe_agent_handle($pdo,$user,['message'=>'Cancel','pageContext'=>$context]);
radb_assert(($cancelled['skill']??'')==='recipe.action_cancelled','Recipe ingredient proposal did not cancel.');
radb_assert((string)radb_scalar($pdo,"SELECT ingredients_json FROM recipes WHERE organization_id=? AND public_id=?",[$org,'recipe-agent-ci'])===$ingredientBefore,'Cancelled ingredient proposal changed recipe JSON.');
$namedIngredient=recipe_agent_handle($pdo,$user,['message'=>'add ingredient 2 tbsp olive oil to Tomato Sauce','pageContext'=>$context]);
radb_assert(($namedIngredient['data']['requiresConfirmation']??false)===true,'Named-recipe ingredient proposal was not created.');
recipe_agent_handle($pdo,$user,['message'=>'Confirm','pageContext'=>$context]);
$altIngredients=json_decode((string)radb_scalar($pdo,"SELECT ingredients_json FROM recipes WHERE organization_id=? AND public_id=?",[$org,'recipe-agent-alt']),true);
radb_assert(($altIngredients[array_key_last($altIngredients)]['ingredient']??'')==='olive oil','Named recipe suffix leaked into ingredient text or wrong recipe was edited.');

// Instruction + note confirms append to canonical recipe.
$stepProposal=recipe_agent_handle($pdo,$user,['message'=>'add step rest the dough for 20 minutes','pageContext'=>$context]);
radb_assert(($stepProposal['data']['requiresConfirmation']??false)===true,'Recipe instruction write skipped proposal state.');
recipe_agent_handle($pdo,$user,['message'=>'Confirm','pageContext'=>$context]);
$steps=json_decode((string)radb_scalar($pdo,"SELECT instructions_json FROM recipes WHERE organization_id=? AND public_id=?",[$org,'recipe-agent-ci']),true);
radb_assert(end($steps)==='rest the dough for 20 minutes','Confirmed recipe instruction was not appended.');
$noteProposal=recipe_agent_handle($pdo,$user,['message'=>'add production note use cold water during summer prep','pageContext'=>$context]);
radb_assert(($noteProposal['data']['requiresConfirmation']??false)===true,'Recipe note write skipped proposal state.');
recipe_agent_handle($pdo,$user,['message'=>'Confirm','pageContext'=>$context]);
radb_assert(str_contains((string)radb_scalar($pdo,"SELECT notes FROM recipes WHERE organization_id=? AND public_id=?",[$org,'recipe-agent-ci']),'use cold water during summer prep'),'Confirmed production note was not appended.');

// Status proposal stale-write rejection preserves newer canonical state.
$statusProposal=recipe_agent_handle($pdo,$user,['message'=>'mark this recipe inactive','pageContext'=>$context]);
radb_assert(($statusProposal['data']['requiresConfirmation']??false)===true,'Recipe status write skipped proposal state.');
$pdo->prepare("UPDATE recipes SET status='active',updated_at=DATE_ADD(NOW(6),INTERVAL 1 SECOND) WHERE organization_id=? AND public_id=?")->execute([$org,'recipe-agent-ci']);
$staleRejected=false;
try{recipe_agent_handle($pdo,$user,['message'=>'Confirm','pageContext'=>$context]);}catch(InvalidArgumentException $e){$staleRejected=str_contains($e->getMessage(),'changed');}
radb_assert($staleRejected,'Recipe stale proposal was not rejected.');
radb_assert((string)radb_scalar($pdo,"SELECT status FROM recipes WHERE organization_id=? AND public_id=?",[$org,'recipe-agent-ci'])==='active','Stale Recipe confirmation overwrote newer state.');

// Permission boundary: view + Agent can read, but not propose edits.
$readOnly=$user;$readOnly['permissions']=['recipes.view','recipes.agent'];
$editBlocked=false;
try{recipe_agent_handle($pdo,$readOnly,['message'=>'set yield to 30 portions','pageContext'=>$context]);}catch(RecipeAgentPermissionException){$editBlocked=true;}
radb_assert($editBlocked,'Recipe Agent write bypassed recipes.edit permission.');
$readOnlyRead=recipe_agent_handle($pdo,$readOnly,['message'=>'How do we make this recipe?','pageContext'=>$context]);
radb_assert(($readOnlyRead['skill']??'')==='recipe.standard','Read-only recipe Agent could not read recipe standards.');

// Ingredient read/handoffs do not mutate Purchasing or other node records.
$poBefore=(int)radb_scalar($pdo,"SELECT COUNT(*) FROM purchase_orders WHERE organization_id=?",[$org]);
$detail=recipe_agent_handle($pdo,$user,['message'=>'Show this recipe','pageContext'=>$context]);
radb_assert(in_array('purchasing',array_column((array)($detail['data']['handoffs']??[]),'node'),true),'Recipe detail did not expose Purchasing handoff.');
radb_assert((int)radb_scalar($pdo,"SELECT COUNT(*) FROM purchase_orders WHERE organization_id=?",[$org])===$poBefore,'Recipe read created a purchase order.');

// Main Brain gets a production-standards signal when an active recipe is incomplete.
$pdo->prepare("UPDATE recipes SET instructions_json='[]',updated_at=NOW(6) WHERE organization_id=? AND public_id=?")->execute([$org,'recipe-agent-ci']);
$gaps=recipe_agent_standard_gaps($pdo,$org,10);
radb_assert(in_array('instructions',(array)($gaps[0]['gaps']??[]),true),'Recipe standard gap detection missed instructions.');
$snapshot=['generatedAt'=>date(DATE_ATOM),'scope'=>[],'signals'=>[],'nextMoves'=>[]];
$merged=recipe_agent_merge_brain_snapshot($pdo,$user,$snapshot);
radb_assert(in_array('recipes',array_column($merged['signals'],'node'),true),'Main Brain signals do not identify Recipe node.');

echo "Recipe + Production Standards DB-backed Agent lifecycle passed.\n";
