<?php
declare(strict_types=1);

/**
 * Single identity resolver for published Gelato menu questions.
 * Consumers may format answers differently, but all must start from the same
 * channel-enabled item IDs. No draft/editor/paused products are candidates.
 */
require_once __DIR__.'/menu-manager-core.php';

function menu_published_name_key(string $raw):string
{
    $raw=mb_strtolower(trim($raw),'UTF-8');
    $raw=preg_replace('/[^\p{L}\p{N}]+/u',' ',$raw)??$raw;
    $raw=preg_replace('/\b(?:what|which|where|whats|s|is|are|does|do|the|this|that|on|in|of|for|to|a|an|me|you|please|tell|about|how|much|price|prices|cost|ingredients?|toppings?|contains?|have|has|menu|item|items|pizza|pizzas|pie|pies|dish|dishes)\b/u',' ',$raw)??$raw;
    return trim(preg_replace('/\s+/u',' ',$raw)??$raw);
}

/** Exact > substring > unique small transcription typo; never guess ties. */
function menu_published_match_rows(array $rows,string $raw):array
{
    $query=menu_published_name_key($raw);
    if(mb_strlen($query,'UTF-8')<2||mb_strlen($query,'UTF-8')>140)return [];
    $exact=[];$partial=[];$nearest=[];$best=3;
    foreach($rows as $row){
        $name=menu_published_name_key((string)($row['name']??''));
        if($name==='')continue;
        if($name===$query)$exact[]=$row;
        elseif(str_contains($name,$query))$partial[]=$row;
        elseif(strlen($query)>=5&&strlen($query)<=80&&strlen($name)<=80){
            $distance=levenshtein($query,$name);
            $allowed=mb_strlen($query,'UTF-8')>=9?2:1;
            if($distance>$allowed)continue;
            if($distance<$best){$best=$distance;$nearest=[$row];}
            elseif($distance===$best)$nearest[]=$row;
        }
    }
    if($exact)return $exact;
    if($partial)return $partial;
    return count($nearest)===1?$nearest:[];
}

/** Only the public_menu channel; IDs, descriptions and prices are server-owned. */
function menu_published_index(PDO $pdo,int $org):array
{
    if($org<1||!menu_manager_ready($pdo))return [];
    $rows=[];
    foreach(menu_manager_channel_menu($pdo,$org,'public_menu') as $section)
        foreach($section['items'] as $item){
            $rows[]=['id'=>(int)$item['id'],'name'=>(string)$item['name'],
                'section'=>(string)$section['name'],
                'description'=>(string)($item['description']??''),
                'prices'=>array_map(static fn(array $price):array=>[
                    'id'=>(int)$price['id'],'label'=>(string)$price['optionName'],
                    'amount'=>(float)$price['amount'],'currency'=>(string)$price['currency'],
                ],(array)$item['prices'])];
        }
    return $rows;
}
