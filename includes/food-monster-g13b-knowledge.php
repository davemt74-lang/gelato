<?php
declare(strict_types=1);
require_once __DIR__.'/menu-training-knowledge.php';
require_once __DIR__.'/menu-published-resolver.php';
require_once __DIR__.'/restaurant-brain.php';
require_once __DIR__.'/food-monster-g13a-engagement.php';

/** Only explicit informational requests enter this read-only path. */
function fm13b_intent(string $message):?string
{
    $text=mb_strtolower(trim($message),'UTF-8');
    if($text===''||mb_strlen($text,'UTF-8')>1000)return null;
    if(preg_match('/\\b(?:add|remove|delete|change|update|set|create|edit|publish|mark|approve|order|purchase|refund|void|execute|run)\\b/u',$text))return null;
    if(preg_match('/\\b(?:allergens?|allergies|allergic|gluten|dairy|milk|lactose|peanuts?|sesame|shellfish|tree nuts?|soy|wheat|eggs?)\\b/u',$text))return 'allergen';
    if(preg_match('/\\b(?:recipe|recipe standards?|batch formula|production standards?|yield)\\b/u',$text))return 'recipe';
    if(preg_match('/\\b(?:ingredients?|contains?|made of|what.s in|menu items?|menu price|toppings?|how much|sizes?)\\b/u',$text))return 'menu';
    if(preg_match('/\\b(?:how (?:do|should|to)|show me how|prepare|preparation|prep steps?|training steps?)\\b/u',$text)
      &&preg_match('/\\b(?:make|prepare|cook|bake|mix|stretch|knead|assemble|food|pizza|dough|pasta|gelato|sauce|toppings?|menu|dish|item)\\b/u',$text))return 'training';
    return null;
}
function fm13b_allowed(array $operator,string $intent,bool $operatorVoiceVerified,bool $voice):bool
{
    if(!in_array($intent,['menu','allergen','training','recipe'],true))return false;
    if($intent==='recipe')return (!$voice||$operatorVoiceVerified)
      &&app_has_permission('recipes.view',$operator)&&app_has_permission('recipes.agent',$operator);
    return app_has_permission('menu.view',$operator)
        ||(app_has_permission('employee.self',$operator)&&app_has_permission('training.self_view',$operator));
}

/** Strip request words and allergen labels from the menu-item search, not the displayed evidence. */
function fm13b_subject(string $question):string
{
    $s=mb_strtolower(trim($question),'UTF-8');
    $s=preg_replace('/\\b(?:allerg(?:en|ens|y|ies|ic)|contains?|ingredients?|gluten|dairy|milk|lactose|egg|eggs|nuts?|peanuts?|sesame|shellfish|soy|wheat|safe|cross.contact|recipe|instructions?|steps?|how|what|which|where|does|do|can|is|are|the|this|that|it|in|of|on|for|to|i|we|you|do|would|should|there|free|with|without|and|of|a|an|some|please|show|me|tell|prepare|preparation|cook|make|made|much|price|menu)\\b/u',' ',$s)??$s;
    return trim(preg_replace('/\\s+/u',' ',$s)??$s," .?!,'\"\n\r\t");
}
function fm13b_allergen_key(string $q):?string
{
    foreach(['peanuts'=>'peanuts','peanut'=>'peanuts','shellfish'=>'shellfish','tree nut'=>'tree-nuts',
      'nuts'=>'tree-nuts','nut'=>'tree-nuts','milk'=>'milk','dairy'=>'milk','lactose'=>'milk',
      'egg'=>'egg','eggs'=>'egg','fish'=>'fish','soy'=>'soy','sesame'=>'sesame',
      'wheat'=>'wheat','gluten'=>'wheat'] as $token=>$id)
      if(preg_match('/\\b'.preg_quote($token,'/').'\\b/iu',$q))return $id;
    return null;
}
/** Pure, testable formatter: no invented facts or allergy-safety guarantees. */
function fm13b_menu_answer(array $matches,string $intent,string $question):array
{
    $failure=['skill'=>'food_monster.menu_knowledge','answer'=>
      'I could not find a matching active menu record. Please name the exact item and verify with the current menu or a manager.',
      'sources'=>[],'data'=>['verified'=>false]];
    if(!$matches)return $failure;
    if(count($matches)>1){
        $names=array_map(static fn($r)=>mb_substr((string)($r['name']??''),0,90,'UTF-8'),array_slice($matches,0,5));
        return ['skill'=>'food_monster.menu_clarification',
          'answer'=>'I found several menu items: '.implode('; ',$names).'. Which one do you mean?',
          'sources'=>[],'data'=>['verified'=>false]];
    }
    $item=$matches[0];$name=mb_substr((string)($item['name']??''),0,100,'UTF-8');
    if($name==='')return $failure;
    $ingredients=array_values(array_filter(array_map('strval',(array)($item['ingredients']??[]))));
    $prep=trim((string)($item['preparationNotes']??''));
    $profile=(array)($item['allergens']??[]);
    $source='Gelato active menu item #'.(int)($item['id']??0).': '.$name;
    $prefix=$name.'. ';
    if($intent==='allergen'){
        $key=fm13b_allergen_key($question);
        $direct=array_column((array)($profile['direct']??[]),'id');
        $verify=array_column((array)($profile['verify']??[]),'id');
        $names=array_column((array)($profile['direct']??[]),'name');
        $answer=$prefix;
        if($key!==null && in_array($key,$direct,true))$answer.='Recorded ingredients indicate '.menu_training_allergen_name(menu_training_allergen_knowledge(),$key).'. ';
        elseif($key!==null && in_array($key,$verify,true))$answer.='The menu information indicates a possible '.menu_training_allergen_name(menu_training_allergen_knowledge(),$key).' concern, but does not establish whether it is present. ';
        elseif($key!==null)$answer.='The recorded menu information does not establish whether '.menu_training_allergen_name(menu_training_allergen_knowledge(),$key).' is present. ';
        elseif($names)$answer.='Recorded ingredients indicate possible allergens: '.implode(', ',array_slice($names,0,8)).'. ';
        else $answer.='No complete allergen determination is recorded. ';
        $answer.='This is not an allergy-safety clearance. Check current recipes, supplier labels, substitutions, and cross-contact with the manager before advising a guest.';
    }elseif($intent==='training'){
        $answer=$prefix.($prep!==''?'Recorded preparation notes: '.mb_substr($prep,0,650,'UTF-8'):'No verified preparation steps are recorded for this item.');
        $answer.=' Follow the approved kitchen procedure; ask a manager if steps or measurements are missing.';
    }elseif(preg_match('/\\b(?:price|prices|how much|cost|sizes?)\\b/iu',$question)){
        $prices=[];
        foreach(array_slice((array)($item['prices']??[]),0,12) as $price){
            $amount=$price['amount']??null;
            if(!is_numeric($amount)||!is_finite((float)$amount)||(float)$amount<0)continue;
            $label=trim((string)($price['label']??''));
            $currency=trim((string)($price['currency']??''));
            $prices[]=($label!==''?mb_substr($label,0,50,'UTF-8').': ':'')
                .number_format((float)$amount,2,'.','').' '.mb_substr($currency,0,10,'UTF-8');
        }
        $answer=$prefix.($prices?'Recorded prices: '.implode('; ',$prices):
            'No current price is recorded for this item.');
        $answer.=' Confirm the location and current POS price before quoting a guest.';
    }else{
        $answer=$prefix.'Recorded ingredients: '.($ingredients?implode(', ',array_slice($ingredients,0,18)):'not recorded');
        $description=trim((string)($item['description']??''));
        if($description!=='')$answer.='. Menu description: '.mb_substr($description,0,220,'UTF-8');
        $answer.='. Ingredients shown may be incomplete; verify recipe changes and substitutions.';
    }
    return ['skill'=>'food_monster.menu_knowledge','answer'=>$answer,'sources'=>[$source],
      'data'=>['verified'=>false,'sourceType'=>'active_menu','itemId'=>(int)($item['id']??0)]];
}
function fm13b_recipe_answer(array $matches,string $question):array
{
    $active=array_values(array_filter($matches,static fn($r)=>($r['status']??'')==='active'));
    if(count($active)!==1)return ['skill'=>'food_monster.recipe_knowledge','answer'=>
      count($active)>1?'Multiple active recipe records match. Please name the exact recipe.':
      'No active recipe record was found for that request. Please verify with the kitchen manager.',
      'sources'=>[],'data'=>['verified'=>false]];
    $r=$active[0];$name=mb_substr((string)$r['name'],0,100,'UTF-8');
    $ingredients=json_decode((string)($r['ingredients_json']??'[]'),true)?:[];
    $steps=json_decode((string)($r['instructions_json']??'[]'),true)?:[];
    $mode=fm13b_intent($question);
    $lines=[];
    if($mode==='allergen'||preg_match('/\\b(?:ingredients?|what.s in|contains?)\\b/iu',$question)){
        foreach(array_slice($ingredients,0,16) as $it)$lines[]=is_array($it)?trim((string)($it['ingredient']??$it['name']??'')):(string)$it;
        $answer='Recorded ingredients for '.$name.': '.($lines?implode(', ',array_filter($lines)):'not recorded').'.';
    }else{
        foreach(array_slice($steps,0,10) as $i=>$it)$lines[]=($i+1).'. '.mb_substr(trim(is_array($it)?(string)($it['text']??''):(string)$it),0,180,'UTF-8');
        $answer='Recorded procedure for '.$name.': '.($lines?implode(' ',array_filter($lines)):'no instructions recorded').'.';
    }
    $answer.=' Verify the current approved standard; any missing ingredient or allergen detail must be checked with a manager. Never assume allergy safety.';
    return ['skill'=>'food_monster.recipe_knowledge','answer'=>$answer,
      'sources'=>['Gelato active recipe '.(string)($r['public_id']??'')],
      'data'=>['verified'=>false,'sourceType'=>'active_recipe']];
}
/** All DB reads are tenant-bound and gated BEFORE retrieval. No writes here. */
function fm13b_knowledge(PDO $pdo,array $operator,string $question,string $intent,
    bool $operatorVoiceVerified,bool $voice,string $referent=''):array
{
    if(!fm13b_allowed($operator,$intent,$operatorVoiceVerified,$voice))
      throw new DomainException('Permission required for this menu or recipe information.');
    $org=(int)($operator['organization_id']??0);
    if($org<1)throw new DomainException('Organization required.');
    $query=fm13b_subject($referent!==''?$referent:$question);
    if($query===''||mb_strlen($query,'UTF-8')<2)return ['skill'=>'food_monster.knowledge_clarification',
      'answer'=>'Which specific menu item or recipe do you mean?','sources'=>[],'data'=>['verified'=>false]];
    if($intent==='recipe'){
        if(!restaurant_brain_table_ready($pdo,'recipes'))
          return ['skill'=>'food_monster.recipe_knowledge','answer'=>'The recipe library is not installed. Please ask a manager to verify the current procedure.','sources'=>[],'data'=>['verified'=>false]];
        $matches=restaurant_brain_recipe_search($pdo,$org,$query,5);
        return fm13b_recipe_answer($matches,$question);
    }
    // Identity is resolved only from the same published list shown in chat.
    // The legacy ingredient/description search may run as a fallback, but its
    // candidates must pass through this published-ID allowlist.
    $public=menu_published_index($pdo,$org);
    $matches=menu_published_match_rows($public,$query);
    if(!$matches){
        $allowed=array_fill_keys(array_column($public,'id'),true);
        foreach(menu_training_search_items($pdo,$org,$query,12) as $candidate)
            if(isset($allowed[(int)$candidate['id']]))$matches[]=$candidate;
    }
    $ids=array_map(static fn(array $item):int=>(int)$item['id'],array_slice($matches,0,5));
    $items=menu_training_items_by_ids($pdo,$org,$ids,5);
    $publishedPrices=[];
    foreach($public as $row)$publishedPrices[(int)$row['id']]=$row['prices'];
    foreach($items as &$item)$item['prices']=$publishedPrices[(int)$item['id']]??[];
    unset($item);
    return fm13b_menu_answer($items,$intent,$question);
}
