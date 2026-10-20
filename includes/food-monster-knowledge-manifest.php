<?php
declare(strict_types=1);

/**
 * KB5A — bounded, fully permission-checked knowledge collection enumeration.
 *
 * A cursor is a numeric source row ID, scoped to the authenticated grant,
 * collection, and installed instance. It is NOT a global cross-tenant cursor.
 * HomeServer may only infer deletion after a completed full sweep, never from
 * a partial or interrupted page. A later KB5B implements the local index.
 */
require_once __DIR__.'/food-monster-knowledge-api.php';

function fm_kb5a_validate(string $type,int $afterId,int $limit): void
{
    if(!in_array($type,['menu','recipe'],true))
        throw new InvalidArgumentException('Collection must be menu or recipe.');
    if($afterId<0 || $afterId>2147483647)
        throw new InvalidArgumentException('Invalid collection cursor.');
    if($limit<1 || $limit>20)
        throw new InvalidArgumentException('Page size must be between 1 and 20.');
}

/** Return ACTIVE menu IDs in ascending order from the SAME organization. */
function fm_kb5a_menu_rows(PDO $pdo,int $org,int $afterId,int $fetch): array
{
    $statement=$pdo->prepare("SELECT i.id FROM menu_items i
        JOIN menu_sections s ON s.id=i.section_id
            AND s.organization_id=i.organization_id
        WHERE i.organization_id=? AND i.id>? AND i.is_active=1
            AND s.status='active'
        ORDER BY i.id ASC LIMIT {$fetch}");
    $statement->execute([$org,$afterId]);
    $items=[];
    foreach($statement->fetchAll(PDO::FETCH_COLUMN) as $id){
        $item=menu_training_item($pdo,$org,(int)$id);
        if(is_array($item))$items[]=['rowId'=>(int)$id,'record'=>$item];
    }
    return $items;
}

/** Never enumerate archived, inactive, draft or another restaurant's recipes. */
function fm_kb5a_recipe_rows(PDO $pdo,int $org,int $afterId,int $fetch): array
{
    $statement=$pdo->prepare("SELECT * FROM recipes
        WHERE organization_id=? AND id>? AND archived_at IS NULL
            AND status='active'
        ORDER BY id ASC LIMIT {$fetch}");
    $statement->execute([$org,$afterId]);
    return array_map(static fn(array $row):array=>[
        'rowId'=>(int)$row['id'],'record'=>$row,
    ],$statement->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Enumerate a single authorized knowledge category.
 *
 * Injectable reader permits deterministic role, pagination, revision,
 * no-partial-tombstone and cross-tenant contract tests. The production
 * readers enforce organization and ACTIVE filtering before projection.
 */
function fm_kb5a_page(
    PDO $pdo,array $principal,string $type,int $afterId=0,int $limit=20,
    ?callable $menuReader=null,?callable $recipeReader=null
): array {
    fm_kb5a_validate($type,$afterId,$limit);
    $org=(int)($principal['organization_id']??0);
    if($org<=0)throw new DomainException('An authorized Food Monster organization is required.');
    if(!in_array($type,fm_kb1_allowed_types($principal),true))
        throw new DomainException('Knowledge collection is not authorized.');
    $menuReader??='fm_kb5a_menu_rows';
    $recipeReader??='fm_kb5a_recipe_rows';
    $reader=$type==='menu'?$menuReader:$recipeReader;
    $rows=$reader($pdo,$org,$afterId,$limit+1);
    if(!is_array($rows)||count($rows)>$limit+1)
        throw new RuntimeException('Invalid source collection response.');
    $hasMore=count($rows)>$limit;
    $items=[];
    $cursor=$afterId;
    foreach(array_slice($rows,0,$limit) as $row){
        if(!is_array($row)||!isset($row['rowId'])||!is_int($row['rowId'])
            ||$row['rowId']<=$cursor||!is_array($row['record']??null))
            throw new RuntimeException('Collection order or evidence is invalid.');
        $cursor=$row['rowId'];
        if($type==='recipe' && (
            ($row['record']['status']??null)!=='active'
            ||!empty($row['record']['archived_at'])
        ))throw new RuntimeException('Inactive recipe in active collection.');
        $document=$type==='menu'
            ?fm_kb1_menu_document($row['record'])
            :fm_kb1_recipe_document($row['record']);
        $items[]=$document;
    }
    if($hasMore && !$items)
        throw new RuntimeException('Non-advancing collection cursor.');
    return [
        'ok'=>true,
        'schema'=>'food-monster-knowledge-manifest-v1',
        'readOnly'=>true,
        'collection'=>$type,
        'cursorMode'=>'organization-scoped-forward-id',
        'afterId'=>$afterId,
        'nextAfterId'=>$hasMore?$cursor:null,
        'complete'=>!$hasMore,
        'count'=>count($items),
        'results'=>$items,
    ];
}
