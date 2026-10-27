<?php
declare(strict_types=1);

/**
 * B8D17D: Small, explicit, confirmation-only stock commands for the existing
 * Operations Agent. The agent never chooses a row by fuzzy/partial matching.
 */
function ops_agent_inventory_intent(string $message): ?array
{
    $command=trim((string)(preg_replace('/^\s*(?:hey\s+gelato[,\s]*)?(?:please\s+)?/iu','',$message)??$message));
    $command=rtrim($command," \t\r\n.");
    // Common speech-to-text phrasings; keep quantities and item names explicit.
    $spoken=['one'=>1,'two'=>2,'three'=>3,'four'=>4,'five'=>5,'six'=>6,
        'seven'=>7,'eight'=>8,'nine'=>9,'ten'=>10,'eleven'=>11,'twelve'=>12,
        'thirteen'=>13,'fourteen'=>14,'fifteen'=>15,'sixteen'=>16,
        'seventeen'=>17,'eighteen'=>18,'nineteen'=>19,'twenty'=>20];
    $command=preg_replace_callback('/\\b('.implode('|',array_keys($spoken)).')\\b/iu',
        static fn(array $m):string=>(string)$spoken[strtolower($m[1])],$command)??$command;
    if(preg_match('/^(?:add|put)\\s+(\\d+(?:\\.\\d{1,4})?)\\s+([a-z][a-z0-9-]{0,19})\\s+(?:of\\s+)?(.+?)\\s+(?:to|into)\\s+(?:the\\s+)?(?:inventory|stock)$/iu',$command,$m)){
        $command='receive '.$m[1].' '.$m[2].' of '.$m[3];
    }elseif(preg_match('/^(?:take|remove|deduct)\\s+(\\d+(?:\\.\\d{1,4})?)\\s+([a-z][a-z0-9-]{0,19})\\s+(?:of\\s+)?(.+?)\\s+from\\s+(?:the\\s+)?(?:inventory|stock)$/iu',$command,$m)){
        $command='use '.$m[1].' '.$m[2].' of '.$m[3];
    }
    if(preg_match('/^(receive|use|waste|count)\s+(\d+(?:\.\d{1,4})?)\s+([a-z][a-z0-9-]{0,19})\s+(?:of\s+)?(.+)$/iu',$command,$m)){
        $intent=['type'=>strtolower($m[1]),'quantity'=>(float)$m[2],
            'unit'=>strtolower($m[3]),'name'=>trim($m[4])];
    }elseif(preg_match('/^set\s+(?:the\s+)?(?:inventory\s+)?count\s+(?:of|for)\s+(.+?)\s+to\s+(\d+(?:\.\d{1,4})?)\s+([a-z][a-z0-9-]{0,19})$/iu',$command,$m)){
        $intent=['type'=>'count','quantity'=>(float)$m[2],
            'unit'=>strtolower($m[3]),'name'=>trim($m[1])];
    }else{return null;}
    if(preg_match('/;|\n|\band\s+(?:take|use|receive|waste|count|adjust|change)\b/iu',$intent['name'])){
        throw new InvalidArgumentException('Submit only one stock change at a time; each one needs its own confirmation.');
    }
    if($intent['name']===''||strlen($intent['name'])>220||!is_finite($intent['quantity'])
        ||$intent['quantity']>10000000||($intent['type']!=='count'&&$intent['quantity']<=0)){
        throw new InvalidArgumentException('Specify an exact inventory item, a valid quantity, and its saved base unit.');
    }
    return $intent;
}

function ops_agent_inventory_unit(string $value): string
{
    $unit=strtolower(trim($value));
    foreach([
        'lb'=>['lbs','pound','pounds'], 'oz'=>['ounce','ounces'],
        'g'=>['gram','grams'], 'kg'=>['kilogram','kilograms'],
        'l'=>['liter','liters','litre','litres'], 'ml'=>['milliliter','milliliters'],
        'ea'=>['each','unit','units'], 'case'=>['cases'], 'bag'=>['bags'],
        'box'=>['boxes'], 'bottle'=>['bottles'], 'gal'=>['gallon','gallons'],
        'qt'=>['quart','quarts'],
    ] as $canonical=>$aliases){if($unit===$canonical||in_array($unit,$aliases,true))return $canonical;}
    return $unit;
}

function ops_agent_inventory_prepare(PDO $pdo,int $organizationId,array $intent): array
{
    $find=$pdo->prepare('SELECT public_id,name,base_unit,on_hand_quantity FROM inventory_items WHERE organization_id=? AND archived_at IS NULL AND LOWER(TRIM(name))=LOWER(TRIM(?)) LIMIT 3');
    $find->execute([$organizationId,$intent['name']]);
    $matches=$find->fetchAll(PDO::FETCH_ASSOC);
    if(count($matches)!==1)throw new InvalidArgumentException(count($matches)>1
        ?'Multiple inventory items have that exact name. Rename them uniquely in Operations → Inventory first.'
        :'No exact inventory match. Check its saved name in Operations → Inventory.');
    $row=$matches[0];$savedUnit=trim((string)$row['base_unit']);
    if($savedUnit===''||ops_agent_inventory_unit($savedUnit)!==ops_agent_inventory_unit($intent['unit'])){
        throw new InvalidArgumentException('Unit mismatch. Use the saved base unit for “'.$row['name'].'” ('.($savedUnit?:'not set').'). No automatic unit conversions.');
    }
    $old=(float)$row['on_hand_quantity'];$requested=$intent['quantity'];$type=$intent['type'];
    $delta=in_array($type,['use','waste'],true)?-$requested:$requested;
    if($type!=='count'&&($old+$delta<0||$old+$delta>9999999999)){
        throw new InvalidArgumentException('That change would put the item below zero or above inventory capacity. Count the actual stock instead.');
    }
    return ['publicId'=>(string)$row['public_id'],'name'=>(string)$row['name'],
        'unit'=>$savedUnit,'type'=>$type,'quantity'=>$requested,'delta'=>$delta,
        'expectedOnHand'=>$old,'resultOnHand'=>$type==='count'?$delta:$old+$delta];
}
