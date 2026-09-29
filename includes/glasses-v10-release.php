<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-production-pilot.php';
require_once __DIR__.'/glasses-cook-ux.php';
require_once __DIR__.'/glasses-runtime-soak.php';
require_once __DIR__.'/glasses-runtime-recovery.php';
require_once __DIR__.'/glasses-device-health.php';
require_once __DIR__.'/glasses-frame-pipeline.php';
require_once __DIR__.'/glasses-hardware-runtime.php';

const GLASSES_V10_RELEASE='v10.0-rc1';

function glasses_v10_release_manifest(): array
{
    return [
      'release'=>GLASSES_V10_RELEASE,
      'sections'=>[
        1=>['name'=>'Hardware Runtime Contract & AIR3 Simulator Adapter','runtime'=>'glasses-hardware-runtime.php'],
        2=>['name'=>'Production Frame Pipeline & Backpressure','runtime'=>'glasses-frame-pipeline.php'],
        3=>['name'=>'Device Health, Telemetry & Diagnostics','runtime'=>'glasses-device-health.php'],
        4=>['name'=>'Disconnect / Reconnect Recovery','runtime'=>'glasses-runtime-recovery.php'],
        5=>['name'=>'Long-Running Station Soak & Fault Injection','runtime'=>'glasses-runtime-soak.php'],
        6=>['name'=>'Cook Interaction & On-Lens UX Acceptance','runtime'=>'glasses-cook-ux.php'],
        7=>['name'=>'Production Pilot Governance','runtime'=>'glasses-production-pilot.php','migration'=>'20261126_v10_production_pilot_governance.sql'],
      ],
      'adapterBoundary'=>[
        'interface'=>'GlassesHardwareRuntimeAdapter',
        'currentAdapter'=>'simulator.v1',
        'futureVendorAdapter'=>'air3.vendor.v1',
        'vendorSdkInstalled'=>false,
        'blockedBySdk'=>['native_camera','native_display','native_input','vendor_inference','battery_telemetry','thermal_telemetry','physical_hardware_acceptance'],
        'notBlockedBySdk'=>['v9_kitchen_intelligence','browser_simulator','browser_camera','governed_onnx','frame_backpressure','health_diagnostics','recovery','soak','cook_ux','pilot_governance'],
      ],
      'authority'=>[
        'kitchenTruth'=>'existing POS/KDS/build runtime',
        'handoff'=>'existing governed human-confirmed handoff',
        'modelRollout'=>'existing glasses vision rollout runtime',
        'productionEnablement'=>'V10 pilot governance',
      ],
      'releaseGates'=>[
        'sectionContracts'=>true,
        'cleanInstall'=>true,
        'upgradeExhaustion'=>true,
        'upgradeIdempotence'=>true,
        'adapterSubstitutionBoundary'=>true,
        'soakAcceptance'=>true,
        'cookUxAcceptance'=>true,
        'pilotGovernance'=>true,
        'v9Regression'=>true,
        'productionPackage'=>true,
      ],
    ];
}

function glasses_v10_release_readiness(PDO $pdo,string $root): array
{
    $manifest=glasses_v10_release_manifest();
    $missing=[];
    foreach($manifest['sections'] as $n=>$section){
        if(!is_file(rtrim($root,'/').'/includes/'.$section['runtime']))$missing[]='runtime:section-'.$n;
        if(isset($section['migration'])&&!is_file(rtrim($root,'/').'/database/'.$section['migration']))$missing[]='migration:section-'.$n;
    }
    foreach(['glasses_production_pilots','glasses_production_pilot_devices','glasses_production_pilot_events'] as $table){
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$table]);if((int)$q->fetchColumn()!==1)$missing[]='table:'.$table;
    }
    foreach([
      'glasses_hardware_runtime_adapter','glasses_frame_pipeline_policy','glasses_device_health_normalize',
      'glasses_runtime_recovery_decide','glasses_runtime_soak_evaluate','glasses_cook_ux_evaluate',
      'glasses_production_pilot_device_status'
    ] as $fn)if(!function_exists($fn))$missing[]='function:'.$fn;

    $adapter=glasses_hardware_runtime_contract('simulator.v1');
    if(($adapter['sdkBoundary']['requiredAdapterId']??'')!=='air3.vendor.v1')$missing[]='adapter:future-air3-id';
    if(($adapter['sdkBoundary']['vendorAdapterInstalled']??true)!==false)$missing[]='adapter:vendor-sdk-state';

    return [
      'release'=>$manifest['release'],
      'ready'=>$missing===[],
      'missing'=>$missing,
      'sectionCount'=>count($manifest['sections']),
      'adapterBoundary'=>$manifest['adapterBoundary'],
      'authority'=>$manifest['authority'],
      'releaseGates'=>$manifest['releaseGates'],
    ];
}
