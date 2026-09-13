<?php
declare(strict_types=1);

/**
 * Rebuild synthetic organization-wide sales rows from location-specific evidence.
 * Explicit all-location imports always win: generated rows use import_batch_id=NULL,
 * while imported rows retain their real batch id.
 */
function sales_rebuild_all_location_rollups(PDO $pdo,int $organizationId,string $provider='csv'): void
{
    $provider=trim($provider);if($provider==='')throw new InvalidArgumentException('Sales provider is required.');

    // Remove only synthetic rollups. Explicit organization-wide imports remain canonical.
    $pdo->prepare("DELETE FROM sales_periods WHERE organization_id=? AND source_provider=? AND location_key='all' AND import_batch_id IS NULL")->execute([$organizationId,$provider]);
    $pdo->prepare("DELETE FROM sales_hourly WHERE organization_id=? AND source_provider=? AND location_key='all' AND import_batch_id IS NULL")->execute([$organizationId,$provider]);

    // Build period rollups from every real location. INSERT IGNORE preserves an explicit
    // all-location source row if one already exists for the same period.
    $pdo->prepare("INSERT IGNORE INTO sales_periods
        (organization_id,location_id,location_key,source_provider,import_batch_id,granularity,service_period,period_start,period_end,tickets,covers,gross_sales,net_sales,tax_amount,tips_amount,discounts_amount,comps_amount,voids_amount,refunds_amount,service_charges_amount,source_metadata_json)
      SELECT organization_id,NULL,'all',source_provider,NULL,granularity,service_period,period_start,period_end,
             SUM(tickets),SUM(covers),SUM(gross_sales),SUM(net_sales),SUM(tax_amount),SUM(tips_amount),SUM(discounts_amount),SUM(comps_amount),SUM(voids_amount),SUM(refunds_amount),SUM(service_charges_amount),
             JSON_OBJECT('locationName','All locations','rollup',TRUE)
        FROM sales_periods
       WHERE organization_id=? AND source_provider=? AND location_key<>'all'
       GROUP BY organization_id,source_provider,granularity,service_period,period_start,period_end")->execute([$organizationId,$provider]);

    // Rebuild item mix for generated organization-wide periods. Explicit all-location
    // source periods are intentionally excluded from this synthetic item rollup.
    $pdo->prepare("INSERT INTO sales_item_periods
        (organization_id,sales_period_id,menu_item_id,source_item_key,source_item_id,item_name,category_name,quantity,gross_sales,net_sales,discounts_amount,refunds_amount)
      SELECT src.organization_id,allp.id,MAX(src.menu_item_id),src.source_item_key,MAX(src.source_item_id),MAX(src.item_name),MAX(src.category_name),
             SUM(src.quantity),SUM(src.gross_sales),SUM(src.net_sales),SUM(src.discounts_amount),SUM(src.refunds_amount)
        FROM sales_item_periods src
        JOIN sales_periods sp ON sp.id=src.sales_period_id AND sp.organization_id=src.organization_id
        JOIN sales_periods allp ON allp.organization_id=sp.organization_id
             AND allp.source_provider=sp.source_provider AND allp.location_key='all' AND allp.import_batch_id IS NULL
             AND allp.granularity=sp.granularity AND allp.service_period=sp.service_period
             AND allp.period_start=sp.period_start AND allp.period_end=sp.period_end
       WHERE src.organization_id=? AND sp.source_provider=? AND sp.location_key<>'all'
       GROUP BY src.organization_id,allp.id,src.source_item_key")->execute([$organizationId,$provider]);

    // Hourly rollups enable organization-wide daypart forecasting from location-tagged exports.
    $pdo->prepare("INSERT IGNORE INTO sales_hourly
        (organization_id,location_id,location_key,source_provider,import_batch_id,business_date,hour_start,tickets,covers,gross_sales,net_sales)
      SELECT organization_id,NULL,'all',source_provider,NULL,business_date,hour_start,
             SUM(tickets),SUM(covers),SUM(gross_sales),SUM(net_sales)
        FROM sales_hourly
       WHERE organization_id=? AND source_provider=? AND location_key<>'all'
       GROUP BY organization_id,source_provider,business_date,hour_start")->execute([$organizationId,$provider]);
}
