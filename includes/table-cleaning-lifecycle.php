<?php
declare(strict_types=1);

require_once __DIR__.'/host-stand-core.php';

function table_cleaning_ready(PDO $pdo): bool
{
    $q=$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='service_tables' AND column_name IN ('dirty_at','cleaning_started_at','cleaning_started_by','ready_at','ready_by')");
    return (int)$q->fetchColumn()===5;
}

function table_cleaning_elapsed_seconds(?string $from,?string $to=null): ?int
{
    if($from===null||trim($from)==='')return null;
    try{
        $a=new DateTimeImmutable($from);
        $b=$to!==null&&trim($to)!==''?new DateTimeImmutable($to):new DateTimeImmutable('now');
        return max(0,$b->getTimestamp()-$a->getTimestamp());
    }catch(Throwable){return null;}
}

function table_cleaning_metadata(PDO $pdo,int $org,int $locationId): array
{
    if(!table_cleaning_ready($pdo))return [];
    $q=$pdo->prepare("SELECT t.id,t.public_id,t.dirty_at,t.cleaning_started_at,t.cleaning_started_by,t.ready_at,t.ready_by,
                             cu.display_name cleaning_started_by_name,ru.display_name ready_by_name
        FROM service_tables t
        LEFT JOIN users cu ON cu.id=t.cleaning_started_by
        LEFT JOIN users ru ON ru.id=t.ready_by
        WHERE t.organization_id=? AND t.location_id=?");
    $q->execute([$org,$locationId]);$out=[];
    foreach($q->fetchAll() as $r){
        $dirtyAt=$r['dirty_at']!==null?(string)$r['dirty_at']:null;
        $cleaningAt=$r['cleaning_started_at']!==null?(string)$r['cleaning_started_at']:null;
        $readyAt=$r['ready_at']!==null?(string)$r['ready_at']:null;
        $out[(string)$r['public_id']]=[
            'dirtyAt'=>$dirtyAt,
            'cleaningStartedAt'=>$cleaningAt,
            'cleaningStartedById'=>$r['cleaning_started_by']!==null?(int)$r['cleaning_started_by']:null,
            'cleaningStartedByName'=>$r['cleaning_started_by_name']!==null?(string)$r['cleaning_started_by_name']:null,
            'readyAt'=>$readyAt,
            'readyById'=>$r['ready_by']!==null?(int)$r['ready_by']:null,
            'readyByName'=>$r['ready_by_name']!==null?(string)$r['ready_by_name']:null,
            'dirtyElapsedSeconds'=>$dirtyAt!==null&&$readyAt===null?table_cleaning_elapsed_seconds($dirtyAt):null,
            'cleaningElapsedSeconds'=>$cleaningAt!==null&&$readyAt===null?table_cleaning_elapsed_seconds($cleaningAt):null,
            'lastResetSeconds'=>$dirtyAt!==null&&$readyAt!==null?table_cleaning_elapsed_seconds($dirtyAt,$readyAt):null,
        ];
    }
    return $out;
}

function table_cleaning_enrich_map(PDO $pdo,int $org,int $locationId,array $map): array
{
    $meta=table_cleaning_metadata($pdo,$org,$locationId);
    foreach($map['tables'] as &$table){
        $m=$meta[(string)$table['publicId']]??[];
        $table['dirtyAt']=$m['dirtyAt']??null;
        $table['cleaningStartedAt']=$m['cleaningStartedAt']??null;
        $table['cleaningStartedById']=$m['cleaningStartedById']??null;
        $table['cleaningStartedByName']=$m['cleaningStartedByName']??null;
        $table['readyAt']=$m['readyAt']??null;
        $table['readyById']=$m['readyById']??null;
        $table['readyByName']=$m['readyByName']??null;
        $table['dirtyElapsedSeconds']=$m['dirtyElapsedSeconds']??null;
        $table['cleaningElapsedSeconds']=$m['cleaningElapsedSeconds']??null;
        $table['lastResetSeconds']=$m['lastResetSeconds']??null;
    }
    unset($table);
    return $map;
}

function table_cleaning_assert_asset_ready(array $table): void
{
    if(!host_asset_available($table))throw new InvalidArgumentException((string)$table['name'].' is not physically available for service.');
    if($table['active_check_id']!==null)throw new InvalidArgumentException('Close or transfer the active check before cleaning this table.');
}

function table_cleaning_start(PDO $pdo,int $org,int $locationId,string $tablePublicId,int $userId): array
{
    if(!table_cleaning_ready($pdo))throw new RuntimeException('Table cleaning migration is not installed. Run upgrade.php.');
    return table_service_transaction($pdo,function()use($pdo,$org,$locationId,$tablePublicId,$userId){
        $table=host_table_row($pdo,$org,$locationId,$tablePublicId,true);
        table_cleaning_assert_asset_ready($table);
        if((string)$table['state']!=='dirty')throw new InvalidArgumentException((string)$table['name'].' must be Dirty before cleaning can start.');
        $pdo->prepare("UPDATE service_tables SET state='cleaning',cleaning_started_at=NOW(6),cleaning_started_by=?,ready_at=NULL,ready_by=NULL,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")
            ->execute([$userId,$userId,$org,(int)$table['id']]);
        table_service_event($pdo,$org,$locationId,(int)$table['id'],null,null,'table_cleaning_started','Table cleaning started.',['dirtyAt'=>$table['dirty_at']??null],$userId);
        return host_table_row($pdo,$org,$locationId,$tablePublicId,false);
    });
}

function table_cleaning_mark_ready(PDO $pdo,int $org,int $locationId,string $tablePublicId,int $userId): array
{
    if(!table_cleaning_ready($pdo))throw new RuntimeException('Table cleaning migration is not installed. Run upgrade.php.');
    return table_service_transaction($pdo,function()use($pdo,$org,$locationId,$tablePublicId,$userId){
        $table=host_table_row($pdo,$org,$locationId,$tablePublicId,true);
        table_cleaning_assert_asset_ready($table);
        if((string)$table['state']!=='cleaning')throw new InvalidArgumentException((string)$table['name'].' must be Cleaning before it can be marked Ready.');
        $dirtyAt=$table['dirty_at']!==null?(string)$table['dirty_at']:null;
        $cleaningAt=$table['cleaning_started_at']!==null?(string)$table['cleaning_started_at']:null;
        $pdo->prepare("UPDATE service_tables SET state='available',ready_at=NOW(6),ready_by=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")
            ->execute([$userId,$userId,$org,(int)$table['id']]);
        $now=(new DateTimeImmutable('now'))->format('Y-m-d H:i:s.u');
        table_service_event($pdo,$org,$locationId,(int)$table['id'],null,null,'table_ready','Table reset completed and is ready for guests.',[
            'dirtyAt'=>$dirtyAt,
            'cleaningStartedAt'=>$cleaningAt,
            'dirtyToReadySeconds'=>$dirtyAt!==null?table_cleaning_elapsed_seconds($dirtyAt,$now):null,
            'cleaningSeconds'=>$cleaningAt!==null?table_cleaning_elapsed_seconds($cleaningAt,$now):null,
        ],$userId);
        return host_table_row($pdo,$org,$locationId,$tablePublicId,false);
    });
}
