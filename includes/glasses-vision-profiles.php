<?php
declare(strict_types=1);

require_once __DIR__.'/glasses-build.php';

function glasses_vision_profiles_ready(PDO $pdo): bool
{
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='glasses_vision_label_profiles'");
    $q->execute();
    return (int)$q->fetchColumn()===1;
}

function glasses_vision_normalize_label(string $value): string
{
    $value=mb_strtolower(trim($value),'UTF-8');
    if($value==='')return '';
    $value=preg_replace('/[^p{L}p{N}]+/u',' ',$value)??'';
    $value=preg_replace('/s+/u',' ',trim($value))??'';
    return mb_substr($value,0,160,'UTF-8');
}

function glasses_vision_normalize_detector(?string $value): string
{
    $value=mb_strtolower(trim((string)$value),'UTF-8');
    if($value===''||$value==='*')return '*';
    $value=preg_replace('/[^a-z0-9._-]+/','-',$value)??'';
    $value=trim($value,'-');
    if($value==='')throw new InvalidArgumentException('Detector name is invalid.');
    return mb_substr($value,0,120,'UTF-8');
}

function glasses_vision_profile_public(array $row): array
{
    return [
        'publicId'=>(string)$row['public_id'],
        'ingredientId'=>(int)$row['ingredient_id'],
        'ingredientName'=>(string)($row['ingredient_name']??''),
        'detectorName'=>(string)$row['detector_name'],
        'modelLabel'=>(string)$row['model_label'],
        'normalizedLabel'=>(string)$row['normalized_label'],
        'minimumConfidence'=>$row['minimum_confidence']!==null?(float)$row['minimum_confidence']:null,
        'status'=>(string)$row['status'],
        'notes'=>(string)($row['notes']??''),
        'createdAt'=>$row['created_at']??null,
        'updatedAt'=>$row['updated_at']??null,
    ];
}

function glasses_vision_profiles(PDO $pdo,int $org): array
{
    if(!glasses_vision_profiles_ready($pdo))return [];
    $q=$pdo->prepare("SELECT p.*,i.canonical_name ingredient_name
        FROM glasses_vision_label_profiles p
        JOIN ingredients i ON i.id=p.ingredient_id AND i.organization_id=p.organization_id
        WHERE p.organization_id=?
        ORDER BY p.status='active' DESC,p.detector_name,p.normalized_label,p.id");
    $q->execute([$org]);
    return array_map('glasses_vision_profile_public',$q->fetchAll());
}

function glasses_vision_profile_ingredients(PDO $pdo,int $org): array
{
    $q=$pdo->prepare("SELECT id,canonical_name,category,verification_status
        FROM ingredients
        WHERE organization_id=?
        ORDER BY canonical_name,id
        LIMIT 5000");
    $q->execute([$org]);
    return array_map(static fn(array $row):array=>[
        'id'=>(int)$row['id'],
        'name'=>(string)$row['canonical_name'],
        'category'=>(string)($row['category']??''),
        'verificationStatus'=>(string)($row['verification_status']??''),
    ],$q->fetchAll());
}

function glasses_vision_profile_save(PDO $pdo,int $org,array $input,int $userId): array
{
    if(!glasses_vision_profiles_ready($pdo))
        throw new RuntimeException('Glasses vision label profile migration is not installed.');

    $publicId=trim((string)($input['publicId']??''));
    $ingredientId=(int)($input['ingredientId']??0);
    $detector=glasses_vision_normalize_detector($input['detectorName']??'*');
    $modelLabel=mb_substr(trim((string)($input['modelLabel']??'')),0,160,'UTF-8');
    $normalized=glasses_vision_normalize_label($modelLabel);
    $status=trim((string)($input['status']??'active'));
    $notes=mb_substr(trim((string)($input['notes']??'')),0,1000,'UTF-8')?:null;

    if($ingredientId<1)throw new InvalidArgumentException('Ingredient is required.');
    if($modelLabel===''||$normalized==='')throw new InvalidArgumentException('Model label is required.');
    if(!in_array($status,['active','inactive'],true))throw new InvalidArgumentException('Profile status is invalid.');

    $minimumConfidence=null;
    if(array_key_exists('minimumConfidence',$input)&&$input['minimumConfidence']!==null&&$input['minimumConfidence']!==''){
        $minimumConfidence=(float)$input['minimumConfidence'];
        if(!is_finite($minimumConfidence)||$minimumConfidence<0.50||$minimumConfidence>1.0)
            throw new InvalidArgumentException('Minimum confidence must be between 0.50 and 1.00.');
        $minimumConfidence=round($minimumConfidence,4);
    }

    $q=$pdo->prepare("SELECT id FROM ingredients WHERE organization_id=? AND id=? LIMIT 1");
    $q->execute([$org,$ingredientId]);
    if(!$q->fetchColumn())throw new InvalidArgumentException('Ingredient was not found.');

    return glasses_transaction($pdo,function()use(
        $pdo,$org,$publicId,$ingredientId,$detector,$modelLabel,$normalized,$minimumConfidence,$status,$notes,$userId
    ):array{
        $excludeId=0;
        if($publicId!==''){
            $q=$pdo->prepare("SELECT id FROM glasses_vision_label_profiles WHERE organization_id=? AND public_id=? LIMIT 1 FOR UPDATE");
            $q->execute([$org,$publicId]);
            $excludeId=(int)($q->fetchColumn()?:0);
            if($excludeId<1)throw new InvalidArgumentException('Vision label profile was not found.');
        }

        $q=$pdo->prepare("SELECT id FROM glasses_vision_label_profiles
            WHERE organization_id=? AND detector_name=? AND normalized_label=? AND id<>?
            LIMIT 1");
        $q->execute([$org,$detector,$normalized,$excludeId]);
        if($q->fetchColumn())
            throw new InvalidArgumentException('This detector already has a mapping for that normalized model label.');

        if($excludeId>0){
            $pdo->prepare("UPDATE glasses_vision_label_profiles
                SET ingredient_id=?,detector_name=?,model_label=?,normalized_label=?,minimum_confidence=?,status=?,notes=?,updated_by=?,updated_at=NOW(6)
                WHERE organization_id=? AND id=?")
                ->execute([$ingredientId,$detector,$modelLabel,$normalized,$minimumConfidence,$status,$notes,$userId,$org,$excludeId]);
            $id=$excludeId;
        }else{
            $public=glasses_public_id('vision-label');
            $pdo->prepare("INSERT INTO glasses_vision_label_profiles
                (organization_id,public_id,ingredient_id,detector_name,model_label,normalized_label,minimum_confidence,status,notes,created_by,updated_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$org,$public,$ingredientId,$detector,$modelLabel,$normalized,$minimumConfidence,$status,$notes,$userId,$userId]);
            $id=(int)$pdo->lastInsertId();
        }

        $q=$pdo->prepare("SELECT p.*,i.canonical_name ingredient_name
            FROM glasses_vision_label_profiles p
            JOIN ingredients i ON i.id=p.ingredient_id AND i.organization_id=p.organization_id
            WHERE p.organization_id=? AND p.id=? LIMIT 1");
        $q->execute([$org,$id]);
        $row=$q->fetch();
        if(!$row)throw new RuntimeException('Vision label profile could not be loaded.');
        return glasses_vision_profile_public($row);
    });
}

function glasses_vision_profile_set_status(PDO $pdo,int $org,string $publicId,string $status,int $userId): array
{
    $publicId=trim($publicId);
    if($publicId==='')throw new InvalidArgumentException('Vision label profile is required.');
    if(!in_array($status,['active','inactive'],true))throw new InvalidArgumentException('Profile status is invalid.');

    $pdo->prepare("UPDATE glasses_vision_label_profiles
        SET status=?,updated_by=?,updated_at=NOW(6)
        WHERE organization_id=? AND public_id=?")
        ->execute([$status,$userId,$org,$publicId]);

    $q=$pdo->prepare("SELECT p.*,i.canonical_name ingredient_name
        FROM glasses_vision_label_profiles p
        JOIN ingredients i ON i.id=p.ingredient_id AND i.organization_id=p.organization_id
        WHERE p.organization_id=? AND p.public_id=? LIMIT 1");
    $q->execute([$org,$publicId]);
    $row=$q->fetch();
    if(!$row)throw new InvalidArgumentException('Vision label profile was not found.');
    return glasses_vision_profile_public($row);
}

function glasses_vision_profile_for_build(
    PDO $pdo,
    array $device,
    string $sessionPublicId,
    ?string $detectorName
): array {
    if(!glasses_vision_profiles_ready($pdo))
        throw new RuntimeException('Glasses vision label profile migration is not installed.');

    $org=(int)$device['organization_id'];
    $session=glasses_build_session_row($pdo,$org,$sessionPublicId,false);
    glasses_build_assert_device_session($device,$session);
    $sessionId=(int)$session['id'];
    $detector=glasses_vision_normalize_detector($detectorName);

    $q=$pdo->prepare("SELECT ingredient_id,component_key,display_name
        FROM glasses_build_components
        WHERE organization_id=? AND build_session_id=? AND ingredient_id IS NOT NULL AND expected_quantity>0
        ORDER BY sort_order,id");
    $q->execute([$org,$sessionId]);

    $components=[];
    $ingredientIds=[];
    foreach($q->fetchAll() as $row){
        $ingredientId=(int)$row['ingredient_id'];
        if($ingredientId<1)continue;
        $components[$ingredientId]=[
            'componentKey'=>(string)$row['component_key'],
            'displayName'=>(string)$row['display_name'],
        ];
        $ingredientIds[$ingredientId]=$ingredientId;
    }

    $mappings=[];
    if($ingredientIds){
        $placeholders=implode(',',array_fill(0,count($ingredientIds),'?'));
        $sql="SELECT p.*,i.canonical_name ingredient_name
            FROM glasses_vision_label_profiles p
            JOIN ingredients i ON i.id=p.ingredient_id AND i.organization_id=p.organization_id
            WHERE p.organization_id=? AND p.status='active'
              AND p.ingredient_id IN ({$placeholders})
              AND p.detector_name IN ('*',?)
            ORDER BY CASE WHEN p.detector_name='*' THEN 0 ELSE 1 END,p.id";
        $args=[$org,...array_values($ingredientIds),$detector];
        $q=$pdo->prepare($sql);
        $q->execute($args);

        $byLabel=[];
        foreach($q->fetchAll() as $row){
            $ingredientId=(int)$row['ingredient_id'];
            if(!isset($components[$ingredientId]))continue;
            $component=$components[$ingredientId];
            $normalized=(string)$row['normalized_label'];
            $byLabel[$normalized]=[
                'modelLabel'=>(string)$row['model_label'],
                'normalizedLabel'=>$normalized,
                'componentKey'=>$component['componentKey'],
                'displayName'=>$component['displayName'],
                'ingredientId'=>$ingredientId,
                'minimumConfidence'=>$row['minimum_confidence']!==null?(float)$row['minimum_confidence']:null,
                'hasMinimumConfidence'=>$row['minimum_confidence']!==null,
                'sourceDetector'=>(string)$row['detector_name'],
            ];
        }
        ksort($byLabel,SORT_STRING);
        $mappings=array_values($byLabel);
    }

    $material=[
        'detectorName'=>$detector,
        'buildSessionPublicId'=>$sessionPublicId,
        'mappings'=>$mappings,
    ];
    $json=json_encode($material,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);

    return [
        'schema'=>'gelato.vision_label_profile.v1',
        'detectorName'=>$detector,
        'buildSessionPublicId'=>$sessionPublicId,
        'profileHash'=>hash('sha256',$json),
        'mappings'=>$mappings,
    ];
}

function glasses_vision_profile_catalog(PDO $pdo,array $user): array
{
    $org=(int)($user['organization_id']??0);
    if($org<1)throw new InvalidArgumentException('Organization context is required.');
    return [
        'ready'=>glasses_vision_profiles_ready($pdo),
        'canManage'=>app_has_permission('glasses.manage',$user),
        'profiles'=>glasses_vision_profiles($pdo,$org),
        'ingredients'=>glasses_vision_profile_ingredients($pdo,$org),
    ];
}
