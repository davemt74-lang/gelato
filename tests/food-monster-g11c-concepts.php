<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/food-monster-conversation-memory.php';
$n=0;
function ok11c(bool $value,string $label):void{global $n;if(!$value)throw new RuntimeException($label);$n++;}
$memory=[
 'openReports'=>[['messageId'=>'msg-report','text'=>'The walkin refrigerator is not working','createdAt'=>'2026-10-16']],
 'recentIngredientQuestions'=>[
  ['messageId'=>'msg-dough','text'=>'What ingredients go in the pizza dough?','createdAt'=>'2026-10-15'],
  ['messageId'=>'msg-allergy','text'=>'Does pesto contain dairy allergens?','createdAt'=>'2026-10-16']],
 'recentTrainingQuestions'=>[
  ['messageId'=>'msg-ferment','text'=>'Explain fermentation for the overnight batch','createdAt'=>'2026-10-15']]
];
$id=static function(string $prompt)use($memory):array{
 return array_map(static fn($row)=>$row['item']['messageId'],fm9f_relevant_context($memory,$prompt,3));
};
ok11c(in_array('msg-dough',$id('What should our crust contain?'),true),
 'crust paraphrase retrieves evidence-linked dough question');
ok11c(in_array('msg-ferment',$id('How long do we proof a batch?'),true),
 'proof/ferment concept resolves an existing training question');
ok11c(in_array('msg-report',$id('Is the fridge fixed?'),true),
 'fridge / refrigerator alias finds an unresolved report');
ok11c(in_array('msg-allergy',$id('Does pesto have an allergy concern?'),true),
 'allergy / allergen concept preserves an ingredient query');
ok11c($id('What time is closing?')===[],'unrelated question retrieves no memory');
ok11c($id('Hello')===[],'greeting does not leak individual memory');
ok11c(!in_array('msg-report',$id('Remember what I asked?'),true),
 'generic recall excludes unrelated operational reports');
ok11c($id('Does this contain wheat?')===[],
 'unsafe allergy synonym not invented from dairy or gluten');
ok11c($id('What about sesame?')===[],
 'specific unknown allergens never substituted');
ok11c(count(fm9f_relevant_context($memory,'dough allergy refrigerator',1))<=1,
 'bounded retrieval cap remains enforced');
ok11c(in_array('concept:dough',fm11c_concepts('Is the crust ready?'),true),
 'reviewable, deterministic concept mapping');
ok11c(!in_array('concept:allergen',fm11c_concepts('wheat'),true),
 'wheat does not become a blanket safe allergen conclusion');
foreach(fm9f_relevant_context($memory,'What should our crust contain?') as $match){
 ok11c(isset($match['item']['messageId'])&&isset($match['item']['createdAt']),
   'every returned memory retains immutable source evidence');
}
echo "FM-G11C kitchen-concept retrieval and source provenance PASS ($n assertions)\n";
