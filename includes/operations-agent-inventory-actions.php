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
    if(preg_match('/^(?:add|put)\\s+(?:to|into)\\s+(?:the\\s+)?(?:inventory|stock)\\s*[:,-]?\\s*(\\d+(?:\\.\\d{1,4})?)\\s+([a-z][a-z0-9-]{0,19})\\s+(?:of\\s+)?(.+)$/iu',$command,$m)){
        $command='receive '.$m[1].' '.$m[2].' of '.$m[3];
    }elseif(preg_match('/^(?:add|put)\\s+(\\d+(?:\\.\\d{1,4})?)\\s+([a-z][a-z0-9-]{0,19})\\s+(?:of\\s+)?(.+?)\\s+(?:to|into)\\s+(?:the\\s+)?(?:inventory|stock)$/iu',$command,$m)){
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
        'qt'=>['quart','quarts'], 'pan'=>['pans'], 'tray'=>['trays'],
        'batch'=>['batches'], 'container'=>['containers'], 'dozen'=>[],
        'cup'=>['cups'],
    ] as $canonical=>$aliases){if($unit===$canonical||in_array($unit,$aliases,true))return $canonical;}
    return $unit;
}

/**
 * G1A: Creating an inventory *record* is distinct from receiving stock into
 * an existing record. Require an explicit "new item" phrase and all important
 * fields; never turn an ambiguous receiving request into catalog creation.
 */
function ops_agent_inventory_new_intent(string $message): ?array
{
    $text=trim((string)(preg_replace('/^\s*(?:hey\s+gelato[,\s]*)?(?:please\s+)?/iu','',$message)??$message));
    $text=rtrim($text," \t\r\n.");
    $quantity='(\d+(?:\.\d{1,4})?)';
    $pattern='/^(?:create|add|register)\s+(?:a\s+)?new\s+(?:inventory|stock)\s+item\s+(?:(?:called|named)\s+)?(.+?)\s*(?:,|\s+with)\s+(?:base\s+)?unit\s+([a-z][a-z0-9-]{0,19})\s*(?:,|and)\s*(?:starting\s+)?(?:quantity|stock|on\s+hand)\s+'.$quantity.'(?:\s*,?\s*category\s+(.+))?$/iu';
    if(!preg_match($pattern,$text,$m))return null;
    $name=trim($m[1]," \t,");
    $unit=ops_agent_inventory_unit($m[2]);
    $amount=(float)$m[3];
    $category=trim((string)($m[4]??'')," \t,");
    if($name===''||mb_strlen($name,'UTF-8')>220
       ||preg_match('/[;\r\n]/u',$name)
       ||$category!==''&&(mb_strlen($category,'UTF-8')>100||preg_match('/[;\r\n]/u',$category))
       ||!is_finite($amount)||$amount<0||$amount>10000000)
        throw new InvalidArgumentException('Specify one new item with a valid name, base unit, and starting quantity.');
    if(!in_array($unit,['lb','oz','g','kg','l','ml','ea','case','bag','box',
        'bottle','gal','qt','pan','tray','batch','container','dozen','cup'],true))
        throw new InvalidArgumentException('Unknown inventory base unit. Say a saved standard unit such as lb, kg, case, pan, or each.');
    return ['name'=>$name,'unit'=>$unit,'quantity'=>$amount,'category'=>$category];
}

function ops_agent_inventory_new_prepare(PDO $pdo,int $organizationId,array $intent): array
{
    $key=operations_slug(operations_normalized_ingredient((string)$intent['name']));
    $query=$pdo->prepare('SELECT public_id FROM inventory_items WHERE organization_id=? AND normalized_key=? LIMIT 1');
    $query->execute([$organizationId,$key]);
    if($query->fetchColumn()!==false)
        throw new InvalidArgumentException('This inventory item already exists (including archived records). Use a stock adjustment or review the saved record.');
    return [
        'name'=>(string)$intent['name'],'normalizedKey'=>$key,
        'unit'=>(string)$intent['unit'],'quantity'=>(float)$intent['quantity'],
        'category'=>(string)$intent['category'],
    ];
}

function ops_agent_inventory_new_execute(PDO $pdo,int $organizationId,int $userId,array $payload): array
{
    $key=operations_slug(operations_normalized_ingredient((string)$payload['name']));
    if($key!==(string)$payload['normalizedKey'])
        throw new InvalidArgumentException('New inventory item name changed after confirmation.');
    $publicId='inv-'.substr(hash('sha256',$organizationId.'|'.$key),0,20);
    $pdo->beginTransaction();
    try{
        // A second creation, whether concurrent or retried, must fail without
        // reactivating archived items or changing an existing quantity.
        $check=$pdo->prepare('SELECT id FROM inventory_items WHERE organization_id=? AND normalized_key=? LIMIT 1 FOR UPDATE');
        $check->execute([$organizationId,$key]);
        if($check->fetchColumn()!==false)
            throw new InvalidArgumentException('This inventory item was created after the proposal. Review its current stock instead.');
        $insert=$pdo->prepare('INSERT INTO inventory_items (organization_id,public_id,normalized_key,name,category,base_unit,on_hand_quantity,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?)');
        $insert->execute([$organizationId,$publicId,$key,$payload['name'],$payload['category']?:null,$payload['unit'],$payload['quantity'],$userId,$userId]);
        $itemId=(int)$pdo->lastInsertId();
        $ledger=$pdo->prepare("INSERT INTO inventory_transactions (organization_id,inventory_item_id,transaction_type,quantity_delta,resulting_quantity,unit,note,source_type,created_by) VALUES (?,?,'count',?,?,?,'Agent-confirmed initial inventory count','agent_initial',?)");
        $ledger->execute([$organizationId,$itemId,$payload['quantity'],$payload['quantity'],$payload['unit'],$userId]);
        $transactionId=(int)$pdo->lastInsertId();
        $pdo->commit();
        return ['publicId'=>$publicId,'name'=>$payload['name'],'unit'=>$payload['unit'],
            'onHand'=>$payload['quantity'],'transactionId'=>$transactionId];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        if($e instanceof PDOException&&$e->getCode()==='23000')
            throw new InvalidArgumentException('That inventory item already exists. No duplicate was created.',0,$e);
        throw $e;
    }
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
