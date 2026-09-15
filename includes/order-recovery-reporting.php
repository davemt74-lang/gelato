<?php
declare(strict_types=1);

require_once __DIR__.'/sales-intelligence-core.php';
require_once __DIR__.'/sales-location-rollup.php';

function order_recovery_reporting_ready(PDO $pdo): bool
{
    foreach(['pos_refunds','pos_checks','sales_periods','sales_hourly'] as $table){
        try{$q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");$q->execute([$table]);if((int)$q->fetchColumn()!==1)return false;}catch(Throwable){return false;}
    }
    return true;
}

function order_recovery_sync_native_sales_day(PDO $pdo,int $org,int $locationId,string $businessDate): void
{
    if(!order_recovery_reporting_ready($pdo))return;
    $locationKey='id:'.$locationId;$provider='gelato_pos';

    $q=$pdo->prepare("SELECT COALESCE(SUM(pr.amount),0)
        FROM pos_refunds pr
        JOIN pos_checks c ON c.id=pr.pos_check_id AND c.organization_id=pr.organization_id
        WHERE pr.organization_id=? AND pr.location_id=? AND c.business_date=? AND pr.status='recorded'");
    $q->execute([$org,$locationId,$businessDate]);$refunds=round((float)$q->fetchColumn(),2);

    $q=$pdo->prepare("SELECT COALESCE(SUM(GREATEST(0,c.subtotal-c.discount_amount)),0)
        FROM pos_checks c WHERE c.organization_id=? AND c.location_id=? AND c.business_date=? AND c.status='paid'");
    $q->execute([$org,$locationId,$businessDate]);$baseNet=round((float)$q->fetchColumn(),2);$net=max(0,round($baseNet-$refunds,2));

    $pdo->prepare("UPDATE sales_periods SET net_sales=?,refunds_amount=?,updated_at=NOW(6)
        WHERE organization_id=? AND location_key=? AND source_provider=? AND granularity='daily' AND service_period='all' AND period_start=? AND period_end=?")
        ->execute([$net,$refunds,$org,$locationKey,$provider,$businessDate,$businessDate]);

    $hourly=$pdo->prepare("SELECT c.closed_hour,COALESCE(SUM(GREATEST(0,c.subtotal-c.discount_amount)),0) base_net,
        COALESCE(SUM(COALESCE(r.refunds,0)),0) refunds
        FROM pos_checks c
        LEFT JOIN (SELECT pos_check_id,SUM(amount) refunds FROM pos_refunds WHERE organization_id=? AND status='recorded' GROUP BY pos_check_id) r ON r.pos_check_id=c.id
        WHERE c.organization_id=? AND c.location_id=? AND c.business_date=? AND c.status='paid' AND c.closed_hour IS NOT NULL
        GROUP BY c.closed_hour");
    $hourly->execute([$org,$org,$locationId,$businessDate]);
    $update=$pdo->prepare("UPDATE sales_hourly SET net_sales=?,updated_at=NOW(6) WHERE organization_id=? AND location_key=? AND source_provider=? AND business_date=? AND hour_start=?");
    foreach($hourly->fetchAll() as $row){$hourNet=max(0,round((float)$row['base_net']-(float)$row['refunds'],2));$update->execute([$hourNet,$org,$locationKey,$provider,$businessDate,(int)$row['closed_hour']]);}

    sales_rebuild_all_location_rollups($pdo,$org,$provider);
}

function order_recovery_sync_native_sales_for_check(PDO $pdo,int $org,int $checkId): void
{
    if(!order_recovery_reporting_ready($pdo))return;
    $q=$pdo->prepare('SELECT location_id,business_date FROM pos_checks WHERE organization_id=? AND id=? LIMIT 1');$q->execute([$org,$checkId]);$row=$q->fetch();if(!$row)return;
    order_recovery_sync_native_sales_day($pdo,$org,(int)$row['location_id'],(string)$row['business_date']);
}

function order_recovery_sync_native_sales_for_public_check(PDO $pdo,int $org,string $checkPublicId): void
{
    if(!order_recovery_reporting_ready($pdo))return;
    $q=$pdo->prepare('SELECT id FROM pos_checks WHERE organization_id=? AND public_id=? LIMIT 1');$q->execute([$org,trim($checkPublicId)]);$id=(int)$q->fetchColumn();if($id>0)order_recovery_sync_native_sales_for_check($pdo,$org,$id);
}
