<?php
declare(strict_types=1);

require_once __DIR__.'/menu-manager-core.php';

function menu_drink_kinds(): array
{
    return ['fountain','bottle_can','coffee_tea','specialty','other'];
}

function menu_drink_kind(array $input): string
{
    $kind=mb_substr(trim((string)($input['drinkKind']??'other')),0,40,'UTF-8');
    return in_array($kind,menu_drink_kinds(),true)?$kind:'other';
}

function menu_drink_mark(PDO $pdo,int $org,int $itemId,string $kind,int $userId): void
{
    $row=menu_manager_item_row($pdo,$org,$itemId,true);
    $metadata=[];
    try{$metadata=json_decode((string)($row['behavior_tags_json']??'{}'),true,64,JSON_THROW_ON_ERROR);if(!is_array($metadata))$metadata=[];}catch(Throwable){$metadata=[];}
    $metadata['source']='menu-manager';$metadata['managed']=true;$metadata['itemType']='drink';$metadata['drinkKind']=$kind;
    $pdo->prepare('UPDATE menu_items SET behavior_tags_json=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')
        ->execute([json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$org,$itemId]);
    $pdo->prepare("UPDATE menu_item_profiles SET item_type='drink',updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND menu_item_id=?")
        ->execute([$userId,$org,$itemId]);
}

function menu_drink_save(PDO $pdo,int $org,?int $itemId,array $input,int $userId): array
{
    if(trim((string)($input['name']??''))==='')throw new InvalidArgumentException('Drink item name is required.');
    $categoryId=max(0,(int)($input['categoryId']??0));$q=$pdo->prepare("SELECT COUNT(*) FROM menu_sections WHERE organization_id=? AND id=? AND status='active'");$q->execute([$org,$categoryId]);if((int)$q->fetchColumn()!==1)throw new InvalidArgumentException('Choose an active Drink category.');
    $input['ingredients']=[];
    $kind=menu_drink_kind($input);
    return pos_transaction($pdo,function()use($pdo,$org,$itemId,$input,$userId,$kind):array{
        $item=menu_manager_save_item($pdo,$org,$itemId,$input,$userId);
        menu_drink_mark($pdo,$org,(int)$item['id'],$kind,$userId);
        return menu_manager_item_payload($pdo,$org,(int)$item['id']);
    });
}

function menu_drink_duplicate(PDO $pdo,int $org,int $itemId,int $userId): array
{
    $source=menu_manager_item_payload($pdo,$org,$itemId);
    if((string)($source['profile']['itemType']??'')!=='drink')throw new InvalidArgumentException('Only Drink items can be duplicated through the Drink builder.');
    return pos_transaction($pdo,function()use($pdo,$org,$itemId,$userId,$source):array{
        $copy=menu_manager_duplicate($pdo,$org,$itemId,$userId);
        menu_drink_mark($pdo,$org,(int)$copy['id'],menu_drink_kind(['drinkKind'=>$source['metadata']['drinkKind']??'other']),$userId);
        return menu_manager_item_payload($pdo,$org,(int)$copy['id']);
    });
}

function menu_drink_set_status(PDO $pdo,int $org,int $itemId,string $action,int $userId): array
{
    $source=menu_manager_item_payload($pdo,$org,$itemId);
    if((string)($source['profile']['itemType']??'')!=='drink')throw new InvalidArgumentException('This item is not a Drink.');
    return menu_manager_set_status($pdo,$org,$itemId,$action,$userId);
}

function menu_drink_context(PDO $pdo,int $org): array
{
    $categories=menu_manager_categories($pdo,$org,true);
    $q=$pdo->prepare('SELECT id,name,status FROM locations WHERE organization_id=? ORDER BY status=\'active\' DESC,name,id');$q->execute([$org]);
    $locations=array_map(static fn(array $row):array=>['id'=>(int)$row['id'],'name'=>(string)$row['name'],'status'=>(string)$row['status']],$q->fetchAll());
    return ['categories'=>$categories,'locations'=>$locations];
}
