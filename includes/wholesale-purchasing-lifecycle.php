<?php
declare(strict_types=1);

require_once __DIR__ . '/wholesale-acquisition.php';
require_once __DIR__ . '/wholesale-commerce.php';

function wholesale_planning_ready(PDO $pdo): bool
{
    foreach (['wholesale_planning_settings','wholesale_purchase_worksheets','wholesale_lifecycle','wholesale_lifecycle_events','wholesale_tastings'] as $table) {
        if (!restaurant_brain_table_ready($pdo,$table)) return false;
    }
    return true;
}

function wholesale_planning_datetime(mixed $value,string $label): ?string
{
    $value=trim((string)$value);
    if($value==='')return null;
    foreach(['Y-m-d\TH:i','Y-m-d H:i:s','Y-m-d H:i'] as $format){
        $date=DateTimeImmutable::createFromFormat('!'.$format,$value);
        if($date)return $date->format('Y-m-d H:i:s');
    }
    throw new InvalidArgumentException($label.' is invalid.');
}

function wholesale_planning_settings(PDO $pdo,int $org): array
{
    $q=$pdo->prepare('SELECT * FROM wholesale_planning_settings WHERE organization_id=? LIMIT 1');
    $q->execute([$org]);$row=$q->fetch();
    if($row)return $row;
    $pdo->prepare('INSERT INTO wholesale_planning_settings (organization_id) VALUES (?)')->execute([$org]);
    $q->execute([$org]);return $q->fetch()?:[];
}

function wholesale_planning_save_settings(PDO $pdo,int $org,array $input,int $userId): array
{
    $pan=(float)($input['panLiters']??5);$serving=(float)($input['defaultServingOz']??4);$safety=(float)($input['defaultSafetyStockPercent']??10);$waste=(float)($input['defaultWastePercent']??5);$weeks=(float)($input['weeksPerMonth']??4.33);
    $core=(float)($input['coreWeeklyPans']??3);$growth=(float)($input['growthWeeklyPans']??6);$key=(float)($input['keyWeeklyPans']??12);
    if($pan<=0||$pan>50)throw new InvalidArgumentException('Pan liters must be greater than zero and no more than 50.');
    if($serving<1||$serving>16)throw new InvalidArgumentException('Default serving size must be between 1 and 16 oz.');
    if($safety<0||$safety>100||$waste<0||$waste>100)throw new InvalidArgumentException('Safety stock and waste must be between 0% and 100%.');
    if($weeks<1||$weeks>6)throw new InvalidArgumentException('Weeks per month must be between 1 and 6.');
    if($core<=0||$growth<=$core||$key<=$growth)throw new InvalidArgumentException('Account tier thresholds must increase from Core to Growth to Key.');
    $pdo->prepare("INSERT INTO wholesale_planning_settings (organization_id,pan_liters,default_serving_oz,default_safety_stock_percent,default_waste_percent,weeks_per_month,core_weekly_pans,growth_weekly_pans,key_weekly_pans,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE pan_liters=VALUES(pan_liters),default_serving_oz=VALUES(default_serving_oz),default_safety_stock_percent=VALUES(default_safety_stock_percent),default_waste_percent=VALUES(default_waste_percent),weeks_per_month=VALUES(weeks_per_month),core_weekly_pans=VALUES(core_weekly_pans),growth_weekly_pans=VALUES(growth_weekly_pans),key_weekly_pans=VALUES(key_weekly_pans),updated_by=VALUES(updated_by),updated_at=NOW(6)")->execute([$org,$pan,$serving,$safety,$waste,$weeks,$core,$growth,$key,$userId]);
    app_audit($pdo,$org,$userId,'wholesale.planning_settings_saved','organization',(string)$org,null,['panLiters'=>$pan,'defaultServingOz'=>$serving,'safetyStockPercent'=>$safety,'wastePercent'=>$waste,'weeksPerMonth'=>$weeks,'tierThresholds'=>[$core,$growth,$key]]);
    return wholesale_planning_settings($pdo,$org);
}

function wholesale_planning_lead(PDO $pdo,int $org,string $publicId): array
{
    $q=$pdo->prepare("SELECT l.*,a.id account_id,a.public_id account_public_id,a.account_status,a.price_tier FROM wholesale_leads l LEFT JOIN wholesale_accounts a ON a.wholesale_lead_id=l.id AND a.organization_id=l.organization_id AND a.archived_at IS NULL WHERE l.organization_id=? AND l.public_id=? AND l.archived_at IS NULL LIMIT 1");
    $q->execute([$org,$publicId]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Wholesale lead not found.');
    return $row;
}

function wholesale_planning_tier(float $weeklyPans,array $settings): string
{
    if($weeklyPans >= (float)$settings['key_weekly_pans'])return 'key';
    if($weeklyPans >= (float)$settings['growth_weekly_pans'])return 'growth';
    if($weeklyPans >= (float)$settings['core_weekly_pans'])return 'core';
    return 'trial';
}

function wholesale_planning_catalog_map(array $catalog): array
{
    $map=[];
    foreach($catalog as $row)$map[(string)$row['id']]=$row;
    return $map;
}

function wholesale_planning_starter_items(array $input,array $catalog,float $recommendedWeeklyPans): array
{
    $map=wholesale_planning_catalog_map($catalog);$items=[];
    foreach((array)($input['starterItems']??[]) as $raw){
        if(!is_array($raw))continue;
        $skuId=trim((string)($raw['skuId']??''));
        if($skuId===''||!isset($map[$skuId]))continue;
        $qty=(float)($raw['quantity']??0);
        if($qty<=0)continue;
        $sku=$map[$skuId];$min=max((float)($sku['priceMinimumQuantity']??0),(float)($sku['minimumQuantity']??1));$inc=max(.0001,(float)($sku['quantityIncrement']??1));
        if($qty<$min)$qty=$min;
        $qty=ceil(($qty-0.0000001)/$inc)*$inc;
        $items[]=['skuId'=>$skuId,'sku'=>(string)$sku['sku'],'name'=>(string)$sku['name'],'quantity'=>round($qty,4),'sellUom'=>(string)$sku['sellUom'],'unitPrice'=>$sku['unitPrice']!==null?(float)$sku['unitPrice']:null];
    }
    if($items)return $items;
    $selected=array_values(array_filter(array_unique(array_map('strval',(array)($input['selectedSkuIds']??[]))),static fn($id)=>isset($map[$id])));
    if(!$selected)return [];
    $base=max(1,(int)ceil($recommendedWeeklyPans/count($selected)));$remaining=max(1,(int)ceil($recommendedWeeklyPans));
    foreach($selected as $i=>$skuId){
        $sku=$map[$skuId];$slots=count($selected)-$i;$qty=max(1,(int)ceil($remaining/$slots));$remaining=max(0,$remaining-$qty);
        $min=max((float)($sku['priceMinimumQuantity']??0),(float)($sku['minimumQuantity']??1));$inc=max(.0001,(float)($sku['quantityIncrement']??1));$qty=max($qty,$min);$qty=ceil(($qty-0.0000001)/$inc)*$inc;
        $items[]=['skuId'=>$skuId,'sku'=>(string)$sku['sku'],'name'=>(string)$sku['name'],'quantity'=>round($qty,4),'sellUom'=>(string)$sku['sellUom'],'unitPrice'=>$sku['unitPrice']!==null?(float)$sku['unitPrice']:null];
    }
    return $items;
}

function wholesale_planning_calculate(array $input,array $settings,array $catalog=[]): array
{
    $mode=(string)($input['customerMode']??'adding_gelato');
    if(!in_array($mode,['adding_gelato','existing_dessert','existing_gelato'],true))$mode='adding_gelato';
    $serving=(float)($input['servingSizeOz']??$settings['default_serving_oz']);
    $days=(float)($input['serviceDaysPerWeek']??7);$safety=(float)($input['safetyStockPercent']??$settings['default_safety_stock_percent']);$waste=(float)($input['wastePercent']??$settings['default_waste_percent']);
    $panLiters=(float)$settings['pan_liters'];$weeks=(float)$settings['weeks_per_month'];
    if($serving<1||$serving>16)throw new InvalidArgumentException('Serving size must be between 1 and 16 oz.');
    if($days<=0||$days>7)throw new InvalidArgumentException('Service days per week must be greater than zero and no more than 7.');
    if($safety<0||$safety>100||$waste<0||$waste>100)throw new InvalidArgumentException('Safety stock and waste must be between 0% and 100%.');
    $servings=(float)($input['servingsPerDay']??0);$desserts=(float)($input['currentDessertUnitsPerDay']??0);$capture=(float)($input['gelatoCapturePercent']??0);$existingPans=(float)($input['existingWeeklyPans']??0);
    if($mode==='existing_dessert'&&$desserts>0&&$capture>0)$servings=$desserts*min(100,$capture)/100;
    if($servings<0||$desserts<0||$existingPans<0)throw new InvalidArgumentException('Demand inputs cannot be negative.');
    $litersPerOz=0.0295735295625;$servingsPerPan=($panLiters/$litersPerOz)/$serving;
    if($mode==='existing_gelato'&&$existingPans>0){$basePans=$existingPans;$weeklyLiters=$basePans*$panLiters;$servings=$servings>0?$servings:($basePans*$servingsPerPan/max(1,$days));}
    else{$weeklyLiters=$servings*$days*$serving*$litersPerOz;$basePans=$panLiters>0?$weeklyLiters/$panLiters:0;}
    $recommended=ceil(max(0,$basePans)*(1+($safety+$waste)/100)-0.0000001);$monthly=$recommended*$weeks;
    $tier=wholesale_planning_tier($recommended,$settings);$starter=wholesale_planning_starter_items($input,$catalog,$recommended);
    $priced=array_values(array_filter($starter,static fn($x)=>$x['unitPrice']!==null));$avgPrice=null;
    if($priced){$units=array_sum(array_column($priced,'quantity'));if($units>0)$avgPrice=array_sum(array_map(static fn($x)=>(float)$x['quantity']*(float)$x['unitPrice'],$priced))/$units;}
    $menuPrice=isset($input['menuPricePerServing'])&&$input['menuPricePerServing']!==''?(float)$input['menuPricePerServing']:null;
    if($menuPrice!==null&&$menuPrice<0)throw new InvalidArgumentException('Menu price cannot be negative.');
    $monthlyRetail=$menuPrice!==null?round($monthly*$servingsPerPan*$menuPrice,2):null;
    $monthlyWholesale=$avgPrice!==null?round($monthly*$avgPrice,2):null;
    $gross=($monthlyRetail!==null&&$monthlyWholesale!==null)?round($monthlyRetail-$monthlyWholesale,2):null;
    $capacity=isset($input['freezerPanCapacity'])&&$input['freezerPanCapacity']!==''?max(0,(float)$input['freezerPanCapacity']):null;
    $warnings=[];
    if($capacity!==null&&$recommended>$capacity)$warnings[]='Recommended weekly pans exceed stated freezer pan capacity.';
    if(!$starter)$warnings[]='Select at least one active wholesale SKU to create an editable starter order.';
    if($starter&&!$priced)$warnings[]='Selected SKUs do not yet have active canonical pricing, so wholesale spend and gross profit are unavailable.';
    return [
        'customerMode'=>$mode,'servingSizeOz'=>round($serving,2),'servingsPerDay'=>round($servings,2),'serviceDaysPerWeek'=>round($days,2),'safetyStockPercent'=>round($safety,2),'wastePercent'=>round($waste,2),
        'panLiters'=>round($panLiters,3),'weeksPerMonth'=>round($weeks,3),'weeklyLiters'=>round($weeklyLiters,4),'baseWeeklyPans'=>round($basePans,4),'recommendedWeeklyPans'=>$recommended,'recommendedMonthlyPans'=>round($monthly,2),'servingsPerPan'=>round($servingsPerPan,2),'accountTier'=>$tier,
        'projectedMonthlyRetailRevenue'=>$monthlyRetail,'projectedMonthlyWholesaleSpend'=>$monthlyWholesale,'projectedMonthlyGrossProfit'=>$gross,'starterItems'=>$starter,'warnings'=>$warnings,
    ];
}

function wholesale_planning_save_worksheet(PDO $pdo,int $org,string $leadPublic,array $input,int $userId): array
{
    $lead=wholesale_planning_lead($pdo,$org,$leadPublic);$settings=wholesale_planning_settings($pdo,$org);$catalog=wholesale_commerce_ready($pdo)?wholesale_commerce_catalog($pdo,$org,$lead['account_id']?(int)$lead['account_id']:null):[];
    $calc=wholesale_planning_calculate($input,$settings,$catalog);$notes=mb_substr(trim((string)($input['notes']??'')),0,10000,'UTF-8')?:null;$delivery=mb_substr(trim((string)($input['deliveryFrequency']??'')),0,40,'UTF-8')?:null;
    $desserts=isset($input['currentDessertUnitsPerDay'])&&$input['currentDessertUnitsPerDay']!==''?max(0,(float)$input['currentDessertUnitsPerDay']):null;$capture=isset($input['gelatoCapturePercent'])&&$input['gelatoCapturePercent']!==''?max(0,min(100,(float)$input['gelatoCapturePercent'])):null;$existing=isset($input['existingWeeklyPans'])&&$input['existingWeeklyPans']!==''?max(0,(float)$input['existingWeeklyPans']):null;$capacity=isset($input['freezerPanCapacity'])&&$input['freezerPanCapacity']!==''?max(0,(float)$input['freezerPanCapacity']):null;$menu=isset($input['menuPricePerServing'])&&$input['menuPricePerServing']!==''?max(0,(float)$input['menuPricePerServing']):null;
    $selected=array_values(array_unique(array_filter(array_map('strval',(array)($input['selectedSkuIds']??[])))));$starter=$calc['starterItems'];$assumptions=['panLiters'=>$calc['panLiters'],'weeksPerMonth'=>$calc['weeksPerMonth'],'warnings'=>$calc['warnings']];
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare("INSERT INTO wholesale_purchase_worksheets (organization_id,wholesale_lead_id,customer_mode,serving_size_oz,servings_per_day,service_days_per_week,safety_stock_percent,waste_percent,menu_price_per_serving,current_dessert_units_per_day,gelato_capture_percent,existing_weekly_pans,freezer_pan_capacity,delivery_frequency,weekly_liters,base_weekly_pans,recommended_weekly_pans,recommended_monthly_pans,servings_per_pan,projected_monthly_retail_revenue,projected_monthly_wholesale_spend,projected_monthly_gross_profit,account_tier,selected_skus_json,starter_order_json,assumptions_json,notes,calculated_at,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(6),?,?) ON DUPLICATE KEY UPDATE customer_mode=VALUES(customer_mode),serving_size_oz=VALUES(serving_size_oz),servings_per_day=VALUES(servings_per_day),service_days_per_week=VALUES(service_days_per_week),safety_stock_percent=VALUES(safety_stock_percent),waste_percent=VALUES(waste_percent),menu_price_per_serving=VALUES(menu_price_per_serving),current_dessert_units_per_day=VALUES(current_dessert_units_per_day),gelato_capture_percent=VALUES(gelato_capture_percent),existing_weekly_pans=VALUES(existing_weekly_pans),freezer_pan_capacity=VALUES(freezer_pan_capacity),delivery_frequency=VALUES(delivery_frequency),weekly_liters=VALUES(weekly_liters),base_weekly_pans=VALUES(base_weekly_pans),recommended_weekly_pans=VALUES(recommended_weekly_pans),recommended_monthly_pans=VALUES(recommended_monthly_pans),servings_per_pan=VALUES(servings_per_pan),projected_monthly_retail_revenue=VALUES(projected_monthly_retail_revenue),projected_monthly_wholesale_spend=VALUES(projected_monthly_wholesale_spend),projected_monthly_gross_profit=VALUES(projected_monthly_gross_profit),account_tier=VALUES(account_tier),selected_skus_json=VALUES(selected_skus_json),starter_order_json=VALUES(starter_order_json),assumptions_json=VALUES(assumptions_json),notes=VALUES(notes),calculated_at=NOW(6),updated_by=VALUES(updated_by),updated_at=NOW(6)");
        $q->execute([$org,(int)$lead['id'],$calc['customerMode'],$calc['servingSizeOz'],$calc['servingsPerDay'],$calc['serviceDaysPerWeek'],$calc['safetyStockPercent'],$calc['wastePercent'],$menu,$desserts,$capture,$existing,$capacity,$delivery,$calc['weeklyLiters'],$calc['baseWeeklyPans'],$calc['recommendedWeeklyPans'],$calc['recommendedMonthlyPans'],$calc['servingsPerPan'],$calc['projectedMonthlyRetailRevenue'],$calc['projectedMonthlyWholesaleSpend'],$calc['projectedMonthlyGrossProfit'],$calc['accountTier'],json_encode($selected,JSON_THROW_ON_ERROR),json_encode($starter,JSON_THROW_ON_ERROR),json_encode($assumptions,JSON_THROW_ON_ERROR),$notes,$userId,$userId]);
        $value=$calc['projectedMonthlyWholesaleSpend'];$pdo->prepare("UPDATE wholesale_leads SET estimated_monthly_volume=?,estimated_value=COALESCE(?,estimated_value),freezer_capacity=COALESCE(?,freezer_capacity),order_frequency=COALESCE(?,order_frequency),updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([number_format((float)$calc['recommendedMonthlyPans'],2,'.','').' x '.$calc['panLiters'].'L pans/month',$value,$capacity!==null?(string)$capacity.' pans':null,$delivery,$org,(int)$lead['id']]);
        if(restaurant_brain_table_ready($pdo,'wholesale_acquisition_profiles'))$pdo->prepare("UPDATE wholesale_acquisition_profiles SET estimated_monthly_units=?,estimated_monthly_revenue=COALESCE(?,estimated_monthly_revenue),storage_notes=COALESCE(?,storage_notes),updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND wholesale_lead_id=?")->execute([$calc['recommendedMonthlyPans'],$value,$capacity!==null?'Freezer capacity: '.$capacity.' pans.':null,$userId,$org,(int)$lead['id']]);
        $pdo->prepare("INSERT INTO wholesale_lead_activities (organization_id,wholesale_lead_id,activity_type,summary,details,metadata_json,created_by) VALUES (?,?,'worksheet','Purchasing worksheet calculated',?,?,?)")->execute([$org,(int)$lead['id'],$calc['recommendedWeeklyPans'].' pans/week · '.$calc['accountTier'].' tier',json_encode(['recommendedWeeklyPans'=>$calc['recommendedWeeklyPans'],'recommendedMonthlyPans'=>$calc['recommendedMonthlyPans'],'accountTier'=>$calc['accountTier'],'projectedMonthlyWholesaleSpend'=>$value],JSON_THROW_ON_ERROR),$userId]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    restaurant_brain_sync_wholesale($pdo,$org,(int)$lead['id'],$userId);
    app_audit($pdo,$org,$userId,'wholesale.purchasing_worksheet_saved','wholesale_lead',$leadPublic,null,['recommendedWeeklyPans'=>$calc['recommendedWeeklyPans'],'accountTier'=>$calc['accountTier']]);
    return $calc;
}

function wholesale_lifecycle_stage_from_pipeline(string $stage): string
{
    return ['new'=>'discovery','qualified'=>'qualified','sample'=>'tasting','quoted'=>'proposal','negotiation'=>'trial','won'=>'active','lost'=>'lost'][$stage]??'discovery';
}

function wholesale_lifecycle_pipeline_stage(string $stage,?string $current=null): ?string
{
    return match($stage){'discovery'=>'new','qualified'=>'qualified','tasting'=>'sample','proposal'=>'quoted','trial'=>'negotiation','active','recurring'=>'won','lost'=>'lost','paused'=>$current==='won'?'won':null,default=>null};
}

function wholesale_lifecycle_get(PDO $pdo,int $org,array $lead): array
{
    $q=$pdo->prepare('SELECT l.*,u.display_name owner_name FROM wholesale_lifecycle l LEFT JOIN users u ON u.id=l.owner_user_id WHERE l.organization_id=? AND l.wholesale_lead_id=? LIMIT 1');$q->execute([$org,(int)$lead['id']]);$row=$q->fetch();
    if($row)return $row;
    return ['lifecycle_stage'=>wholesale_lifecycle_stage_from_pipeline((string)$lead['pipeline_stage']),'owner_user_id'=>$lead['assigned_to'],'owner_name'=>'','next_action'=>null,'next_action_at'=>$lead['next_followup_at'],'lifecycle_notes'=>null];
}

function wholesale_lifecycle_save(PDO $pdo,int $org,string $leadPublic,array $input,int $userId): array
{
    $lead=wholesale_planning_lead($pdo,$org,$leadPublic);$stage=(string)($input['lifecycleStage']??'discovery');$allowed=['discovery','qualified','tasting','proposal','trial','active','recurring','paused','lost'];if(!in_array($stage,$allowed,true))throw new InvalidArgumentException('Invalid wholesale lifecycle stage.');
    $owner=wholesale_acquisition_validate_assignee($pdo,$org,$input['ownerUserId']??$lead['assigned_to']);$next=mb_substr(trim((string)($input['nextAction']??'')),0,500,'UTF-8')?:null;$nextAt=wholesale_planning_datetime($input['nextActionAt']??null,'Next action time');$notes=mb_substr(trim((string)($input['lifecycleNotes']??'')),0,10000,'UTF-8')?:null;
    $old=wholesale_lifecycle_get($pdo,$org,$lead);$from=(string)$old['lifecycle_stage'];$pipeline=wholesale_lifecycle_pipeline_stage($stage,(string)$lead['pipeline_stage']);
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare("INSERT INTO wholesale_lifecycle (organization_id,wholesale_lead_id,lifecycle_stage,owner_user_id,next_action,next_action_at,trial_started_at,active_at,recurring_at,paused_at,lost_at,lifecycle_notes,created_by,updated_by) VALUES (?,?,?,?,?,?,IF(?='trial',NOW(6),NULL),IF(?='active',NOW(6),NULL),IF(?='recurring',NOW(6),NULL),IF(?='paused',NOW(6),NULL),IF(?='lost',NOW(6),NULL),?,?,?) ON DUPLICATE KEY UPDATE lifecycle_stage=VALUES(lifecycle_stage),owner_user_id=VALUES(owner_user_id),next_action=VALUES(next_action),next_action_at=VALUES(next_action_at),trial_started_at=IF(VALUES(lifecycle_stage)='trial',COALESCE(trial_started_at,NOW(6)),trial_started_at),active_at=IF(VALUES(lifecycle_stage)='active',COALESCE(active_at,NOW(6)),active_at),recurring_at=IF(VALUES(lifecycle_stage)='recurring',COALESCE(recurring_at,NOW(6)),recurring_at),paused_at=IF(VALUES(lifecycle_stage)='paused',COALESCE(paused_at,NOW(6)),paused_at),lost_at=IF(VALUES(lifecycle_stage)='lost',COALESCE(lost_at,NOW(6)),lost_at),lifecycle_notes=VALUES(lifecycle_notes),updated_by=VALUES(updated_by),updated_at=NOW(6)");
        $q->execute([$org,(int)$lead['id'],$stage,$owner,$next,$nextAt,$stage,$stage,$stage,$stage,$stage,$notes,$userId,$userId]);
        if($pipeline!==null){$prob=wholesale_acquisition_probability($pipeline);$pdo->prepare("UPDATE wholesale_leads SET pipeline_stage=?,probability_percent=?,assigned_to=?,next_followup_at=?,won_at=IF(?='won',COALESCE(won_at,NOW(6)),won_at),lost_at=IF(?='lost',COALESCE(lost_at,NOW(6)),lost_at),updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$pipeline,$prob,$owner,$nextAt,$pipeline,$pipeline,$org,(int)$lead['id']]);}
        else $pdo->prepare('UPDATE wholesale_leads SET assigned_to=?,next_followup_at=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$owner,$nextAt,$org,(int)$lead['id']]);
        if($stage!==$from)$pdo->prepare("INSERT INTO wholesale_lifecycle_events (organization_id,wholesale_lead_id,from_stage,to_stage,event_type,summary,metadata_json,actor_user_id) VALUES (?,?,?,?, 'stage_changed',?,?,?)")->execute([$org,(int)$lead['id'],$from,$stage,'Lifecycle moved from '.$from.' to '.$stage,json_encode(['pipelineStage'=>$pipeline],JSON_THROW_ON_ERROR),$userId]);
        $pdo->prepare("INSERT INTO wholesale_lead_activities (organization_id,wholesale_lead_id,activity_type,summary,details,metadata_json,created_by) VALUES (?,?,'followup',?,?,?,?)")->execute([$org,(int)$lead['id'],'Lifecycle: '.$stage,$next?('Next action: '.$next):null,json_encode(['from'=>$from,'to'=>$stage,'nextActionAt'=>$nextAt],JSON_THROW_ON_ERROR),$userId]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    restaurant_brain_sync_wholesale($pdo,$org,(int)$lead['id'],$userId);app_audit($pdo,$org,$userId,'wholesale.lifecycle_updated','wholesale_lead',$leadPublic,['stage'=>$from],['stage'=>$stage,'pipelineStage'=>$pipeline,'nextActionAt'=>$nextAt]);
    return wholesale_lifecycle_get($pdo,$org,$lead);
}

function wholesale_tasting_save(PDO $pdo,int $org,string $leadPublic,array $input,int $userId): array
{
    $lead=wholesale_planning_lead($pdo,$org,$leadPublic);$public=trim((string)($input['tastingId']??''));$status=(string)($input['status']??'scheduled');if(!in_array($status,['scheduled','completed','cancelled','no_show'],true))throw new InvalidArgumentException('Invalid tasting status.');
    $format=(string)($input['tastingFormat']??'onsite');if(!in_array($format,['onsite','dropoff','virtual','event'],true))$format='onsite';$scheduled=wholesale_planning_datetime($input['scheduledAt']??null,'Tasting time');$completed=$status==='completed'?wholesale_planning_datetime($input['completedAt']??date('Y-m-d H:i:s'),'Completion time'):null;
    $score=isset($input['overallScore'])&&$input['overallScore']!==''?(float)$input['overallScore']:null;if($score!==null&&($score<1||$score>5))throw new InvalidArgumentException('Tasting score must be between 1 and 5.');$outcome=trim((string)($input['outcome']??''));if($outcome!==''&&!in_array($outcome,['advance','follow_up','trial','hold','no_fit'],true))throw new InvalidArgumentException('Invalid tasting outcome.');
    $flavors=array_values(array_unique(array_filter(array_map(static fn($v)=>mb_substr(trim((string)$v),0,220,'UTF-8'),(array)($input['flavors']??[])))));$attendees=mb_substr(trim((string)($input['attendees']??'')),0,1000,'UTF-8')?:null;$notes=mb_substr(trim((string)($input['notes']??'')),0,10000,'UTF-8')?:null;$next=mb_substr(trim((string)($input['nextAction']??'')),0,500,'UTF-8')?:null;$nextAt=wholesale_planning_datetime($input['nextActionAt']??null,'Tasting next action time');
    if($public===''){$public=wholesale_commerce_public_id('wtaste');$pdo->prepare("INSERT INTO wholesale_tastings (organization_id,wholesale_lead_id,public_id,status,tasting_format,scheduled_at,completed_at,attendees,flavors_json,overall_score,outcome,notes,next_action,next_action_at,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([$org,(int)$lead['id'],$public,$status,$format,$scheduled,$completed,$attendees,json_encode($flavors,JSON_THROW_ON_ERROR),$score,$outcome?:null,$notes,$next,$nextAt,$userId,$userId]);}
    else{$q=$pdo->prepare('SELECT id FROM wholesale_tastings WHERE organization_id=? AND wholesale_lead_id=? AND public_id=?');$q->execute([$org,(int)$lead['id'],$public]);if(!$q->fetchColumn())throw new InvalidArgumentException('Tasting record not found.');$pdo->prepare("UPDATE wholesale_tastings SET status=?,tasting_format=?,scheduled_at=?,completed_at=?,attendees=?,flavors_json=?,overall_score=?,outcome=?,notes=?,next_action=?,next_action_at=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND wholesale_lead_id=? AND public_id=?")->execute([$status,$format,$scheduled,$completed,$attendees,json_encode($flavors,JSON_THROW_ON_ERROR),$score,$outcome?:null,$notes,$next,$nextAt,$userId,$org,(int)$lead['id'],$public]);}
    $summary=$status==='completed'?'Tasting completed'.($outcome?' · '.$outcome:''):'Tasting '.$status;$pdo->prepare("INSERT INTO wholesale_lead_activities (organization_id,wholesale_lead_id,activity_type,summary,details,metadata_json,created_by) VALUES (?,?,'sample',?,?,?,?)")->execute([$org,(int)$lead['id'],$summary,$notes,json_encode(['tastingId'=>$public,'format'=>$format,'scheduledAt'=>$scheduled,'score'=>$score,'outcome'=>$outcome,'flavors'=>$flavors],JSON_THROW_ON_ERROR),$userId]);
    if($next!==null)$pdo->prepare('UPDATE wholesale_leads SET next_followup_at=?,updated_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$nextAt,$org,(int)$lead['id']]);
    restaurant_brain_sync_wholesale($pdo,$org,(int)$lead['id'],$userId);app_audit($pdo,$org,$userId,'wholesale.tasting_saved','wholesale_tasting',$public,null,['leadId'=>$leadPublic,'status'=>$status,'outcome'=>$outcome]);
    return ['id'=>$public,'status'=>$status,'format'=>$format,'scheduledAt'=>$scheduled,'completedAt'=>$completed,'attendees'=>$attendees,'flavors'=>$flavors,'overallScore'=>$score,'outcome'=>$outcome,'notes'=>$notes,'nextAction'=>$next,'nextActionAt'=>$nextAt];
}

function wholesale_planning_detail(PDO $pdo,int $org,string $leadPublic): array
{
    $lead=wholesale_planning_lead($pdo,$org,$leadPublic);$worksheetQ=$pdo->prepare('SELECT * FROM wholesale_purchase_worksheets WHERE organization_id=? AND wholesale_lead_id=? LIMIT 1');$worksheetQ->execute([$org,(int)$lead['id']]);$worksheet=$worksheetQ->fetch()?:null;
    if($worksheet){foreach(['selected_skus_json','starter_order_json','assumptions_json'] as $field){$worksheet[$field]=json_decode((string)($worksheet[$field]??'[]'),true)?:[];}}
    $taste=$pdo->prepare('SELECT * FROM wholesale_tastings WHERE organization_id=? AND wholesale_lead_id=? ORDER BY COALESCE(scheduled_at,created_at) DESC,id DESC LIMIT 50');$taste->execute([$org,(int)$lead['id']]);$tastings=$taste->fetchAll();foreach($tastings as &$row)$row['flavors']=json_decode((string)($row['flavors_json']??'[]'),true)?:[];unset($row);
    $catalog=wholesale_commerce_ready($pdo)?wholesale_commerce_catalog($pdo,$org,$lead['account_id']?(int)$lead['account_id']:null):[];
    return ['lead'=>$lead,'settings'=>wholesale_planning_settings($pdo,$org),'worksheet'=>$worksheet,'lifecycle'=>wholesale_lifecycle_get($pdo,$org,$lead),'tastings'=>$tastings,'catalog'=>$catalog,'canCreateOrder'=>!empty($lead['account_id'])&&(string)$lead['account_status']==='active'&&wholesale_commerce_ready($pdo)];
}

function wholesale_planning_create_starter_order(PDO $pdo,int $org,string $leadPublic,int $userId): array
{
    $lead=wholesale_planning_lead($pdo,$org,$leadPublic);if(empty($lead['account_id']))throw new InvalidArgumentException('Convert this lead to a Wholesale account before creating a canonical starter order.');if((string)$lead['account_status']!=='active')throw new InvalidArgumentException('The linked Wholesale account is not active.');
    $q=$pdo->prepare('SELECT starter_order_json FROM wholesale_purchase_worksheets WHERE organization_id=? AND wholesale_lead_id=? LIMIT 1');$q->execute([$org,(int)$lead['id']]);$raw=$q->fetchColumn();$starter=$raw?json_decode((string)$raw,true):[];if(!$starter)throw new InvalidArgumentException('Save a purchasing worksheet with starter items first.');
    $account=wholesale_commerce_account($pdo,$org,(int)$lead['account_id']);$items=array_map(static fn($row)=>['skuId'=>$row['skuId'],'quantity'=>$row['quantity']],$starter);
    $result=wholesale_commerce_create_order($pdo,$org,$account,['status'=>'requested','items'=>$items,'fulfillmentType'=>$lead['fulfillment_preference']??null,'internalNotes'=>'Starter order generated from Wholesale Purchasing Worksheet for lead '.$leadPublic.'.'],$userId);
    wholesale_commerce_order_event($pdo,$org,(int)$result['id'],'worksheet_generated','Starter order generated from purchasing worksheet.',$userId,['leadId'=>$leadPublic]);
    wholesale_lifecycle_save($pdo,$org,$leadPublic,['lifecycleStage'=>'trial','ownerUserId'=>$lead['assigned_to'],'nextAction'=>'Confirm starter order and launch trial account.','nextActionAt'=>date('Y-m-d\TH:i',strtotime('+2 days'))],$userId);
    app_audit($pdo,$org,$userId,'wholesale.starter_order_created','wholesale_order',$result['publicId'],null,['leadId'=>$leadPublic,'orderNumber'=>$result['orderNumber'],'total'=>$result['totals']['total']]);
    return $result;
}
