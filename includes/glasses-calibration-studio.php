<?php
declare(strict_types=1);

require_once __DIR__.'/operational-access.php';
require_once __DIR__.'/kds-core.php';
require_once __DIR__.'/glasses-calibration.php';

function glasses_calibration_studio_ready(PDO $pdo): bool
{
    if(!glasses_station_calibration_ready($pdo))return false;
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='glasses_station_calibration_regions'");
    $q->execute();
    return (int)$q->fetchColumn()===1;
}

function glasses_calibration_studio_catalog(PDO $pdo,array $user): array
{
    $org=(int)($user['organization_id']??0);
    if($org<1)throw new InvalidArgumentException('Organization context is required.');

    $q=$pdo->prepare("SELECT id,name,is_primary,sort_order FROM locations WHERE organization_id=? AND status='active' ORDER BY is_primary DESC,sort_order,name,id");
    $q->execute([$org]);
    $locations=operational_filter_locations($pdo,$user,'glasses.view',$q->fetchAll());

    $stations=[];
    foreach($locations as $location){
        $locationId=(int)$location['id'];
        $stations[(string)$locationId]=array_map(
            static fn(array $station):array=>[
                'id'=>(int)$station['id'],
                'publicId'=>(string)$station['public_id'],
                'name'=>(string)$station['name'],
                'slug'=>(string)$station['slug'],
            ],
            kds_stations($pdo,$org,$locationId,true)
        );
    }

    $q=$pdo->prepare("SELECT id,canonical_name,category,verification_status
        FROM ingredients
        WHERE organization_id=?
        ORDER BY canonical_name,id
        LIMIT 2000");
    $q->execute([$org]);
    $ingredients=array_map(
        static fn(array $ingredient):array=>[
            'id'=>(int)$ingredient['id'],
            'name'=>(string)$ingredient['canonical_name'],
            'category'=>(string)($ingredient['category']??''),
            'verificationStatus'=>(string)($ingredient['verification_status']??''),
        ],
        $q->fetchAll()
    );

    return [
        'locations'=>array_map(
            static fn(array $location):array=>[
                'id'=>(int)$location['id'],
                'name'=>(string)$location['name'],
                'primary'=>(bool)$location['is_primary'],
            ],
            $locations
        ),
        'stationsByLocation'=>$stations,
        'ingredients'=>$ingredients,
        'canManage'=>app_has_permission('glasses.manage',$user),
        'schemaReady'=>glasses_calibration_studio_ready($pdo),
    ];
}
