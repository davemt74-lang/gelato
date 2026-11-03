<?php
declare(strict_types=1);
// Full deployment archives retain tests; database fixtures may run only from CLI.
if(PHP_SAPI!=='cli'){http_response_code(404);header('Content-Type: text/plain; charset=utf-8');exit('Not found.');}

require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/daily-quicklist-purchase-drafts.php';

/** MariaDB behavior checks for shared vendor identity, atomicity and order guards. */
function pvi_assert(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException($message);
}
function pvi_reject(callable $action,string $message): void
{
    try{$action();}catch(InvalidArgumentException){return;}
    throw new RuntimeException($message);
}
function pvi_snapshot(PDO $pdo,int $org): string
{
    $snapshot=[];
    foreach(['inventory_vendor_items','inventory_items','vendor_price_history','purchase_orders','purchase_order_items','purchase_order_events'] as $table){
        $q=$pdo->prepare('SELECT * FROM '.$table.' WHERE organization_id=? ORDER BY id');
        $q->execute([$org]);$snapshot[$table]=$q->fetchAll(PDO::FETCH_ASSOC);
    }
    return json_encode($snapshot,JSON_THROW_ON_ERROR);
}
function pvi_second_connection(): PDO
{
    $db=app_config()['database'];$dsn=(string)($db['dsn']??'');
    if($dsn==='')$dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host']??'127.0.0.1',$db['port']??3306,$db['name'],$db['charset']??'utf8mb4');
    return new PDO($dsn,(string)($db['username']??''),(string)($db['password']??''),[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STRINGIFY_FETCHES=>false,
    ]);
}

$pdo=app_pdo();$slug='pvi-'.bin2hex(random_bytes(5));
$pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")
    ->execute(['Purchasing Integrity '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organizations(name,status,timezone) VALUES (?,'active','America/Phoenix')")
    ->execute(['Other Purchasing '.$slug]);$other=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users(email,password_hash,first_name,last_name,display_name,status)
    VALUES (?,'x','Purchasing','Tester','Purchasing Tester','active')")
    ->execute([$slug.'@example.test']);$uid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships(organization_id,user_id,status) VALUES (?,?,'active')")
    ->execute([$org,$uid]);
$stock=[];
foreach(['flour','cheese','tomato','stale'] as $name){
    $public=$slug.'-'.$name;
    $pdo->prepare("INSERT INTO inventory_items(organization_id,public_id,normalized_key,name,base_unit,on_hand_quantity,status,created_by,updated_by)
        VALUES (?,?,?,?, 'kg',3,'active',?,?)")->execute([$org,$public,$public,'Integrity '.$name,$uid,$uid]);
    $stock[$name]=['public'=>$public,'id'=>(int)$pdo->lastInsertId()];
}
$foreign=$slug.'-foreign';
$pdo->prepare("INSERT INTO inventory_items(organization_id,public_id,normalized_key,name,base_unit,on_hand_quantity,status,created_by,updated_by)
    VALUES (?,?,?,?, 'kg',3,'active',?,?)")->execute([$other,$foreign,$foreign,'Secret inventory '.$slug,$uid,$uid]);
$foreignStockId=(int)$pdo->lastInsertId();
$a=purchasing_save_vendor($pdo,$org,['name'=>'Supplier A '.$slug],$uid);
$b=purchasing_save_vendor($pdo,$org,['name'=>'Supplier B '.$slug],$uid);
$unmapped=purchasing_save_vendor($pdo,$org,['name'=>'Unmapped supplier '.$slug],$uid);
$foreignVendor=purchasing_save_vendor($pdo,$other,['name'=>'Foreign supplier '.$slug],$uid);
$save=static function(string $item,array $vendor,string $sku,bool $preferred=false)use($pdo,$org,$uid,$stock):array{
    return purchasing_save_vendor_item($pdo,$org,[
        'vendorId'=>$vendor['public_id'],'inventoryId'=>$stock[$item]['public'],
        'vendorSku'=>$sku,'packSize'=>2,'packUnit'=>'kg','pricePerPack'=>10,'preferred'=>$preferred,
    ],$uid);
};
$mapA=$save('flour',$a,'FLOUR-A',true);
$mapB=$save('flour',$b,'FLOUR-B');
$cheese=$save('cheese',$a,'CHEESE-A',true);
$tomato=$save('tomato',$a,'TOMATO-A',true);
$stale=$save('stale',$a,'STALE-A',true);
$foreignMap=purchasing_save_vendor_item($pdo,$other,[
    'vendorId'=>$foreignVendor['public_id'],'inventoryId'=>$foreign,
    'vendorSku'=>'FOREIGN','packSize'=>2,'packUnit'=>'kg','pricePerPack'=>11,'preferred'=>true,
],$uid);
$base=['id'=>$mapA['public_id'],'vendorId'=>$a['public_id'],'inventoryId'=>$stock['flour']['public'],
    'vendorSku'=>'FLOUR-A','packSize'=>2,'packUnit'=>'kg','pricePerPack'=>10,'preferred'=>true];

// Reject spoofed mapping identity before clearing any preference or history.
$before=pvi_snapshot($pdo,$org);
foreach([
    ['inventoryId'=>$stock['cheese']['public']],['vendorId'=>$b['public_id']],
    ['id'=>$foreignMap['public_id']],['id'=>'missing-'.$slug],['inventoryId'=>$foreign],
] as $bad)pvi_reject(static fn()=>purchasing_save_vendor_item($pdo,$org,array_replace($base,$bad),$uid),
    'A mapping update accepted the wrong tenant, vendor or stock identity.');
pvi_assert(pvi_snapshot($pdo,$org)===$before,'Rejected identity edits changed preference, catalog, stock or history.');
foreach([
    ['packSize'=>0],['packSize'=>-1],['packSize'=>INF],['packSize'=>'unknown'],
    ['pricePerPack'=>-1],['pricePerPack'=>NAN],['pricePerPack'=>true],['unitsPerPack'=>0],
    ['packUnit'=>'lb'],['packSize'=>null],['packSize'=>0.0001,'pricePerPack'=>99999999],
] as $bad)pvi_reject(static fn()=>purchasing_save_vendor_item($pdo,$org,array_replace($base,$bad),$uid),
    'An invalid catalog numeric value or preferred pack unit was accepted.');
pvi_assert(pvi_snapshot($pdo,$org)===$before,'Invalid catalog input partially changed canonical data.');

// A failure after preference clearing must roll back the complete transaction.
$conflict=$base;unset($conflict['id']);$conflict['pricePerPack']=17;
$failed=false;
try{purchasing_save_vendor_item($pdo,$org,$conflict,$uid);}catch(PDOException){$failed=true;}
pvi_assert($failed,'Duplicate supplier SKU fixture did not exercise a database write failure.');
pvi_assert(pvi_snapshot($pdo,$org)===$before,'Failed catalog insert cleared the old preference or appended price history.');

// Switching an existing mapping preserves its identity and one preferred row.
$switched=purchasing_save_vendor_item($pdo,$org,[
    'id'=>$mapB['public_id'],'vendorId'=>$b['public_id'],'inventoryId'=>$stock['flour']['public'],
    'vendorSku'=>'FLOUR-B','packSize'=>4,'packUnit'=>'kg','pricePerPack'=>28,'preferred'=>true,
],$uid);
pvi_assert($switched['public_id']===$mapB['public_id'],'Preferred change replaced the saved mapping identity.');
$q=$pdo->prepare('SELECT public_id FROM inventory_vendor_items WHERE organization_id=? AND inventory_item_id=? AND is_preferred=1');
$q->execute([$org,$stock['flour']['id']]);
pvi_assert($q->fetchAll(PDO::FETCH_COLUMN)===[$mapB['public_id']],'Preferred change did not leave exactly one selected mapping.');
$q=$pdo->prepare('SELECT vendor_name,vendor_sku,unit_cost,on_hand_quantity FROM inventory_items WHERE organization_id=? AND id=?');
$q->execute([$org,$stock['flour']['id']]);$canonical=$q->fetch();
pvi_assert($canonical['vendor_name']===$b['name']&&$canonical['vendor_sku']==='FLOUR-B'
    &&(float)$canonical['unit_cost']===7.0&&(float)$canonical['on_hand_quantity']===3.0,
    'Preferred change failed to synchronize canonical vendor/cost without changing stock.');

// Invalid manual and agent-created line payloads leave no partial order/header.
$valid=['inventoryId'=>$stock['cheese']['public'],'packs'=>2];$before=pvi_snapshot($pdo,$org);
foreach([
    ['packs'=>0],['packs'=>-2],['packs'=>NAN],['packs'=>INF],['packs'=>'two'],['packs'=>true],
    ['packs'=>0.0001],['packs'=>1.0001],['packs'=>100001],['pricePerPack'=>-1],
    ['pricePerPack'=>'unknown'],['pricePerPack'=>INF],['packSize'=>0],['packSize'=>-1],
    ['packSize'=>INF],['packs'=>100000,'packSize'=>1000000],['inventoryId'=>$foreign],
] as $bad)pvi_reject(static fn()=>purchasing_create_order($pdo,$org,$a['public_id'],[array_replace($valid,$bad)],$uid),
    'An invalid order quantity, price, pack or tenant identity was accepted.');
pvi_reject(static fn()=>purchasing_create_order($pdo,$org,$a['public_id'],[$valid,$valid],$uid),'Duplicate stock lines were accepted.');
pvi_reject(static fn()=>purchasing_create_order($pdo,$org,$a['public_id'],array_fill(0,101,$valid),$uid),'More than 100 lines were silently truncated.');
pvi_reject(static fn()=>purchasing_create_order($pdo,$org,$unmapped['public_id'],[$valid],$uid),'An unmapped vendor silently guessed a pack size.');
pvi_reject(static fn()=>purchasing_create_order($pdo,$org,$foreignVendor['public_id'],[$valid],$uid),'A foreign vendor was accepted.');
pvi_assert(pvi_snapshot($pdo,$org)===$before,'Invalid draft input changed an order, event, mapping or physical stock.');
$pdo->prepare("UPDATE vendors SET status='inactive' WHERE id=?")->execute([(int)$a['id']]);
pvi_reject(static fn()=>purchasing_create_order($pdo,$org,$a['public_id'],[$valid],$uid),'Inactive vendor accepted.');
$pdo->prepare("UPDATE vendors SET status='active' WHERE id=?")->execute([(int)$a['id']]);
$pdo->prepare("UPDATE inventory_items SET status='inactive' WHERE id=?")->execute([$stock['cheese']['id']]);
pvi_reject(static fn()=>purchasing_create_order($pdo,$org,$a['public_id'],[$valid],$uid),'Inactive stock accepted.');
$pdo->prepare("UPDATE inventory_items SET status='active' WHERE id=?")->execute([$stock['cheese']['id']]);
$pdo->prepare("UPDATE inventory_vendor_items SET status='inactive' WHERE id=?")->execute([(int)$cheese['id']]);
pvi_reject(static fn()=>purchasing_create_order($pdo,$org,$a['public_id'],[$valid],$uid),'Inactive mapping accepted.');
$pdo->prepare("UPDATE inventory_vendor_items SET status='active' WHERE id=?")->execute([(int)$cheese['id']]);

// Both entry paths share the stock guard, including a quote issued before a PO.
$request=daily_quicklist_add($pdo,$org,$uid,['type'=>'inventory','title'=>'Flour','inventoryId'=>$stock['flour']['public']],false);
daily_quicklist_mutate($pdo,$org,$uid,'update',['id'=>$request,'requestedStockQuantity'=>5],true);
$actor=['id'=>$uid,'organization_id'=>$org,'permissions'=>['tasks.manage','inventory.view','purchasing.view','purchasing.manage']];
$oldQuote=dqpo_quote($pdo,$org,$request);
$manual=purchasing_create_order($pdo,$org,$a['public_id'],[['inventoryId'=>$stock['flour']['public'],'packs'=>3]],$uid);
pvi_reject(static fn()=>dqpo_quote($pdo,$org,$request),'Manual draft did not block Need More preview.');
pvi_reject(static fn()=>dqpo_create_draft($pdo,$org,$uid,$actor,$request,$oldQuote['quoteHash']),
    'A Need More quote issued before a manual draft bypassed overlap protection.');
$pdo->prepare("UPDATE purchase_orders SET status='cancelled' WHERE organization_id=? AND public_id=?")->execute([$org,$manual['public_id']]);
$quote=dqpo_quote($pdo,$org,$request);$needDraft=dqpo_create_draft($pdo,$org,$uid,$actor,$request,$quote['quoteHash']);
foreach(['draft','submitted','partially_received'] as $status){
    $pdo->prepare('UPDATE purchase_orders SET status=? WHERE organization_id=? AND public_id=?')->execute([$status,$org,$needDraft['orderId']]);
    pvi_reject(static fn()=>purchasing_create_order($pdo,$org,$b['public_id'],[['inventoryId'=>$stock['flour']['public'],'packs'=>1]],$uid),
        'Need More '.$status.' order did not block a later manual draft.');
}
$before=pvi_snapshot($pdo,$org);
pvi_reject(static fn()=>purchasing_create_order($pdo,$org,$a['public_id'],[
    ['inventoryId'=>$stock['tomato']['public'],'packs'=>1],['inventoryId'=>$stock['flour']['public'],'packs'=>1],
],$uid),'An overlapping line did not reject the whole multi-item order.');
pvi_assert(pvi_snapshot($pdo,$org)===$before,'A failed multi-item draft left a partial header or line.');
purchasing_assert_no_open_order($pdo,$other,$stock['flour']['id']);
$pdo->prepare("UPDATE purchase_orders SET status='received' WHERE organization_id=? AND public_id=?")->execute([$org,$needDraft['orderId']]);
$free=purchasing_create_order($pdo,$org,$b['public_id'],[['inventoryId'=>$stock['flour']['public'],'packs'=>1,'pricePerPack'=>0]],$uid);
pvi_assert((float)$free['total_amount']===0.0,'A valid saved zero-price draft was rejected or replaced.');

// A current read must reject an order committed after this transaction snapshot.
$otherPdo=pvi_second_connection();
$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->beginTransaction();
try{
    $q=$pdo->prepare('SELECT COUNT(*) FROM purchase_orders WHERE organization_id=?');$q->execute([$org]);$q->fetchColumn();
    $committed=purchasing_create_order($otherPdo,$org,$a['public_id'],[['inventoryId'=>$stock['stale']['public'],'packs'=>1]],$uid);
    $q=$pdo->prepare('SELECT id FROM inventory_items WHERE organization_id=? AND id=? FOR UPDATE');
    $q->execute([$org,$stock['stale']['id']]);$q->fetchColumn();
    pvi_reject(static fn()=>purchasing_assert_no_open_order($pdo,$org,$stock['stale']['id']),
        'Shared guard read a stale snapshot and missed a newly committed draft.');
}finally{if($pdo->inTransaction())$pdo->rollBack();}

// Incompatible units are visible as unnormalized; foreign/archived rows disappear.
$mismatch=purchasing_save_vendor_item($pdo,$org,[
    'vendorId'=>$b['public_id'],'inventoryId'=>$stock['cheese']['public'],'vendorSku'=>'CHEESE-LB',
    'packSize'=>4,'packUnit'=>'lb','pricePerPack'=>1,'preferred'=>false,
],$uid);
$rows=purchasing_price_compare($pdo,$org,'Integrity cheese');
$mismatched=array_values(array_filter($rows,static fn(array $row):bool=>$row['vendor_sku']==='CHEESE-LB'));
pvi_assert(count($mismatched)===1&&$mismatched[0]['unit_price']===null,'Mismatched pack units produced a misleading normalized price.');
pvi_reject(static fn()=>purchasing_create_order($pdo,$org,$b['public_id'],[$valid],$uid),'Manual PO treated lb packs as kg inventory.');
$pdo->prepare('INSERT INTO inventory_vendor_items(organization_id,inventory_item_id,vendor_id,public_id,vendor_sku,pack_size,pack_unit,price_per_pack)
    VALUES (?,?,?,?,?,2,\'kg\',1)')->execute([$org,$foreignStockId,(int)$a['id'],$slug.'-bad-stock','BAD-STOCK']);
$pdo->prepare('INSERT INTO inventory_vendor_items(organization_id,inventory_item_id,vendor_id,public_id,vendor_sku,pack_size,pack_unit,price_per_pack)
    VALUES (?,?,?,?,?,2,\'kg\',1)')->execute([$org,$stock['cheese']['id'],(int)$foreignVendor['id'],$slug.'-bad-vendor','BAD-VENDOR']);
pvi_assert(purchasing_price_compare($pdo,$org,'Secret inventory '.$slug)===[],'Foreign inventory escaped through an inconsistent mapping row.');
pvi_assert(!array_filter(purchasing_price_compare($pdo,$org,'Integrity cheese'),
    static fn(array $row):bool=>$row['vendor_sku']==='BAD-VENDOR'),'Foreign vendor escaped through an inconsistent mapping row.');
$pdo->prepare('UPDATE vendors SET archived_at=NOW(6) WHERE id=?')->execute([(int)$b['id']]);
pvi_assert(!array_filter(purchasing_price_compare($pdo,$org,'Integrity cheese'),
    static fn(array $row):bool=>$row['vendor_id']===$b['public_id']),'Archived vendor appeared in comparison.');
$pdo->prepare('UPDATE inventory_items SET archived_at=NOW(6) WHERE id=?')->execute([$stock['cheese']['id']]);
pvi_assert(purchasing_price_compare($pdo,$org,'Integrity cheese')===[],'Archived inventory appeared in comparison.');
$q=$pdo->prepare('SELECT COUNT(*) FROM inventory_transactions WHERE organization_id=?');$q->execute([$org]);
pvi_assert((int)$q->fetchColumn()===0,'Purchasing draft or supplier changes wrote physical inventory.');
echo "purchasing-vendor-integrity-contract-ok\n";
