<?php
declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

function media_allowed_mimes(): array
{
    return ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
}

function media_schema_ready(PDO $pdo): bool
{
    try {
        $q=$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND ((table_name='menu_items' AND column_name='main_image_file_id') OR (table_name='ingredients' AND column_name='image_file_id') OR (table_name='locations' AND column_name='cover_image_file_id'))");
        if((int)$q->fetchColumn()!==3)return false;
        $q=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='files'");
        return (int)$q->fetchColumn()===1;
    } catch(Throwable) {
        return false;
    }
}

function media_safe_original_name(string $name): string
{
    $name=trim(basename(str_replace('\\','/',$name)));
    if($name==='')return 'image';
    return mb_substr($name,0,255,'UTF-8');
}

function media_validate_image_bytes(string $bytes): array
{
    $size=strlen($bytes);
    if($size<1)throw new InvalidArgumentException('Choose an image to upload.');
    if($size>10*1024*1024)throw new InvalidArgumentException('Images must be 10 MB or smaller.');

    $finfo=new finfo(FILEINFO_MIME_TYPE);
    $mime=(string)$finfo->buffer($bytes);
    $allowed=media_allowed_mimes();
    if(!isset($allowed[$mime]))throw new InvalidArgumentException('Use a JPEG, PNG, or WebP image. SVG and other file types are not accepted.');

    $dimensions=@getimagesizefromstring($bytes);
    if(!is_array($dimensions) || empty($dimensions[0]) || empty($dimensions[1]))throw new InvalidArgumentException('The uploaded file is not a valid image.');
    $width=(int)$dimensions[0];$height=(int)$dimensions[1];
    if($width>12000 || $height>12000 || ($width*$height)>40000000)throw new InvalidArgumentException('The image dimensions are too large.');

    return ['mime'=>$mime,'extension'=>$allowed[$mime],'size'=>$size,'width'=>$width,'height'=>$height];
}

function media_storage_root(): string
{
    return RESTAURANT_APP_ROOT.'/uploads/media';
}

function media_store_image_bytes(PDO $pdo,int $organizationId,?int $userId,string $bytes,string $originalName): array
{
    if(!media_schema_ready($pdo))throw new RuntimeException('Restaurant media is not installed. Run Upgrade once.');
    $meta=media_validate_image_bytes($bytes);
    $year=date('Y');$month=date('m');
    $relative='uploads/media/org-'.$organizationId.'/'.$year.'/'.$month;
    $directory=RESTAURANT_APP_ROOT.'/'.$relative;
    if(!is_dir($directory) && !mkdir($directory,0775,true) && !is_dir($directory))throw new RuntimeException('The restaurant media directory could not be created.');

    $stored=bin2hex(random_bytes(20)).'.'.$meta['extension'];
    $relativePath=$relative.'/'.$stored;
    $absolute=RESTAURANT_APP_ROOT.'/'.$relativePath;
    if(file_put_contents($absolute,$bytes,LOCK_EX)!==strlen($bytes))throw new RuntimeException('The image could not be stored.');
    @chmod($absolute,0644);

    try {
        $q=$pdo->prepare("INSERT INTO files (organization_id,storage_driver,storage_path,original_name,stored_name,mime_type,file_size,checksum_sha256,uploaded_by,visibility) VALUES (?,'local_public',?,?,?,?,?,?,?,'public')");
        $q->execute([$organizationId,$relativePath,media_safe_original_name($originalName),$stored,$meta['mime'],$meta['size'],hash('sha256',$bytes),$userId?:null]);
        $fileId=(int)$pdo->lastInsertId();
    } catch(Throwable $e) {
        @unlink($absolute);
        throw $e;
    }
    return ['id'=>$fileId,'url'=>app_url($relativePath),'mimeType'=>$meta['mime'],'fileSize'=>$meta['size'],'width'=>$meta['width'],'height'=>$meta['height'],'originalName'=>media_safe_original_name($originalName)];
}

function media_store_uploaded_image(PDO $pdo,int $organizationId,?int $userId,array $upload): array
{
    $error=(int)($upload['error']??UPLOAD_ERR_NO_FILE);
    if($error!==UPLOAD_ERR_OK){
        $message=match($error){UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE=>'The image is too large.',UPLOAD_ERR_NO_FILE=>'Choose an image to upload.',default=>'The image upload did not complete.'};
        throw new InvalidArgumentException($message);
    }
    $tmp=(string)($upload['tmp_name']??'');
    if($tmp==='' || !is_uploaded_file($tmp))throw new InvalidArgumentException('The image upload is invalid.');
    $bytes=file_get_contents($tmp);
    if(!is_string($bytes))throw new RuntimeException('The uploaded image could not be read.');
    return media_store_image_bytes($pdo,$organizationId,$userId,$bytes,(string)($upload['name']??'image'));
}

function media_target_spec(string $target): array
{
    return match($target){
        'menu_item'=>['table'=>'menu_items','column'=>'main_image_file_id','permission'=>'menu.manage','label'=>'menu item'],
        'ingredient'=>['table'=>'ingredients','column'=>'image_file_id','permission'=>'menu.manage','label'=>'ingredient'],
        'location'=>['table'=>'locations','column'=>'cover_image_file_id','permission'=>'locations.manage','label'=>'location'],
        default=>throw new InvalidArgumentException('Unknown media target.'),
    };
}

function media_target_current(PDO $pdo,int $organizationId,string $target,int $entityId,bool $forUpdate=false): array
{
    if($entityId<1)throw new InvalidArgumentException('Save the record before adding an image.');
    $spec=media_target_spec($target);
    $sql="SELECT id,{$spec['column']} file_id FROM {$spec['table']} WHERE organization_id=? AND id=? LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $q=$pdo->prepare($sql);$q->execute([$organizationId,$entityId]);$row=$q->fetch();
    if(!$row)throw new InvalidArgumentException(ucfirst($spec['label']).' was not found.');
    return ['id'=>(int)$row['id'],'fileId'=>$row['file_id']!==null?(int)$row['file_id']:null]+$spec;
}

function media_file(PDO $pdo,int $organizationId,?int $fileId): ?array
{
    if(!$fileId)return null;
    $q=$pdo->prepare("SELECT id,storage_driver,storage_path,original_name,mime_type,file_size,checksum_sha256,created_at FROM files WHERE organization_id=? AND id=? AND deleted_at IS NULL LIMIT 1");
    $q->execute([$organizationId,$fileId]);$row=$q->fetch();if(!$row)return null;
    if((string)$row['storage_driver']!=='local_public' || !str_starts_with((string)$row['storage_path'],'uploads/media/'))return null;
    return ['id'=>(int)$row['id'],'url'=>app_url((string)$row['storage_path']),'originalName'=>(string)$row['original_name'],'mimeType'=>(string)$row['mime_type'],'fileSize'=>(int)$row['file_size'],'checksum'=>(string)$row['checksum_sha256'],'createdAt'=>$row['created_at']];
}

function media_target_status(PDO $pdo,int $organizationId,string $target,int $entityId): array
{
    $current=media_target_current($pdo,$organizationId,$target,$entityId,false);
    return ['target'=>$target,'entityId'=>$entityId,'file'=>media_file($pdo,$organizationId,$current['fileId'])];
}

function media_reference_count(PDO $pdo,int $organizationId,int $fileId): int
{
    $total=0;
    foreach([['menu_items','main_image_file_id'],['ingredients','image_file_id'],['locations','cover_image_file_id']] as [$table,$column]){
        $q=$pdo->prepare("SELECT COUNT(*) FROM $table WHERE organization_id=? AND $column=?");$q->execute([$organizationId,$fileId]);$total+=(int)$q->fetchColumn();
    }
    $q=$pdo->prepare("SELECT COUNT(*) FROM users u JOIN organization_memberships om ON om.user_id=u.id WHERE om.organization_id=? AND u.profile_image_file_id=?");$q->execute([$organizationId,$fileId]);$total+=(int)$q->fetchColumn();
    $q=$pdo->prepare("SELECT COUNT(*) FROM brand_settings WHERE organization_id=? AND (logo_file_id=? OR square_logo_file_id=? OR favicon_file_id=? OR default_profile_file_id=?)");$q->execute([$organizationId,$fileId,$fileId,$fileId,$fileId]);$total+=(int)$q->fetchColumn();
    return $total;
}

function media_retire_if_unreferenced(PDO $pdo,int $organizationId,?int $fileId): void
{
    if(!$fileId || media_reference_count($pdo,$organizationId,$fileId)>0)return;
    $q=$pdo->prepare("SELECT storage_driver,storage_path FROM files WHERE organization_id=? AND id=? AND deleted_at IS NULL LIMIT 1");$q->execute([$organizationId,$fileId]);$row=$q->fetch();if(!$row)return;
    $pdo->prepare('UPDATE files SET deleted_at=NOW(6) WHERE organization_id=? AND id=?')->execute([$organizationId,$fileId]);
    if((string)$row['storage_driver']==='local_public' && str_starts_with((string)$row['storage_path'],'uploads/media/')){
        $path=RESTAURANT_APP_ROOT.'/'.(string)$row['storage_path'];
        if(is_file($path))@unlink($path);
    }
}

function media_assign_target(PDO $pdo,int $organizationId,string $target,int $entityId,int $fileId): array
{
    $file=media_file($pdo,$organizationId,$fileId);
    if(!$file || !isset(media_allowed_mimes()[$file['mimeType']]))throw new InvalidArgumentException('The selected media file is unavailable.');
    $own=!$pdo->inTransaction();if($own)$pdo->beginTransaction();$old=null;
    try {
        $current=media_target_current($pdo,$organizationId,$target,$entityId,true);$old=$current['fileId'];
        $pdo->prepare("UPDATE {$current['table']} SET {$current['column']}=? WHERE organization_id=? AND id=?")->execute([$fileId,$organizationId,$entityId]);
        if($own)$pdo->commit();
    } catch(Throwable $e) { if($own&&$pdo->inTransaction())$pdo->rollBack();throw $e; }
    if($old && $old!==$fileId)media_retire_if_unreferenced($pdo,$organizationId,$old);
    return media_target_status($pdo,$organizationId,$target,$entityId);
}

function media_remove_target(PDO $pdo,int $organizationId,string $target,int $entityId): array
{
    $own=!$pdo->inTransaction();if($own)$pdo->beginTransaction();$old=null;
    try {
        $current=media_target_current($pdo,$organizationId,$target,$entityId,true);$old=$current['fileId'];
        $pdo->prepare("UPDATE {$current['table']} SET {$current['column']}=NULL WHERE organization_id=? AND id=?")->execute([$organizationId,$entityId]);
        if($own)$pdo->commit();
    } catch(Throwable $e) { if($own&&$pdo->inTransaction())$pdo->rollBack();throw $e; }
    if($old)media_retire_if_unreferenced($pdo,$organizationId,$old);
    return media_target_status($pdo,$organizationId,$target,$entityId);
}

function media_file_url_map(PDO $pdo,int $organizationId,array $fileIds): array
{
    $fileIds=array_values(array_unique(array_filter(array_map('intval',$fileIds),static fn(int $id):bool=>$id>0)));if(!$fileIds)return [];
    $ph=implode(',',array_fill(0,count($fileIds),'?'));
    $q=$pdo->prepare("SELECT id,storage_path FROM files WHERE organization_id=? AND id IN ($ph) AND storage_driver='local_public' AND storage_path LIKE 'uploads/media/%' AND deleted_at IS NULL");
    $q->execute(array_merge([$organizationId],$fileIds));$map=[];
    foreach($q->fetchAll() as $row)$map[(int)$row['id']]=app_url((string)$row['storage_path']);
    return $map;
}

function media_location_cover_map(PDO $pdo,int $organizationId): array
{
    if(!media_schema_ready($pdo))return [];
    $q=$pdo->prepare("SELECT l.id,l.cover_image_file_id,f.storage_path FROM locations l LEFT JOIN files f ON f.id=l.cover_image_file_id AND f.organization_id=l.organization_id AND f.deleted_at IS NULL AND f.storage_driver='local_public' WHERE l.organization_id=?");
    $q->execute([$organizationId]);$out=[];
    foreach($q->fetchAll() as $row)$out[(int)$row['id']]=!empty($row['storage_path'])?app_url((string)$row['storage_path']):'';
    return $out;
}

function media_enrich_channel_menu(PDO $pdo,int $organizationId,array $menu): array
{
    if(!media_schema_ready($pdo))return $menu;$ids=[];
    foreach($menu as $section)foreach(($section['items']??[]) as $item)$ids[]=(int)($item['id']??0);
    $ids=array_values(array_unique(array_filter($ids)));if(!$ids)return $menu;
    $ph=implode(',',array_fill(0,count($ids),'?'));
    $q=$pdo->prepare("SELECT i.id,f.storage_path FROM menu_items i LEFT JOIN files f ON f.id=i.main_image_file_id AND f.organization_id=i.organization_id AND f.deleted_at IS NULL AND f.storage_driver='local_public' WHERE i.organization_id=? AND i.id IN ($ph)");
    $q->execute(array_merge([$organizationId],$ids));$images=[];foreach($q->fetchAll() as $r)$images[(int)$r['id']]=!empty($r['storage_path'])?app_url((string)$r['storage_path']):'';
    foreach($menu as &$section)foreach($section['items'] as &$item)$item['imageUrl']=$images[(int)$item['id']]??'';unset($item,$section);
    return $menu;
}

function media_public_menu_sections(PDO $pdo,int $organizationId): array
{
    require_once __DIR__.'/menu-manager-core.php';
    $menu=media_enrich_channel_menu($pdo,$organizationId,menu_manager_channel_menu($pdo,$organizationId,'public_menu'));
    $itemIds=[];foreach($menu as $section)foreach($section['items'] as $item)$itemIds[]=(int)$item['id'];
    $ingredients=[];
    if($itemIds){
        $ph=implode(',',array_fill(0,count($itemIds),'?'));
        $q=$pdo->prepare("SELECT mii.menu_item_id,ing.id,COALESCE(mii.display_name,ing.canonical_name) name,f.storage_path FROM menu_item_ingredients mii JOIN ingredients ing ON ing.id=mii.ingredient_id LEFT JOIN files f ON f.id=ing.image_file_id AND f.organization_id=ing.organization_id AND f.deleted_at IS NULL AND f.storage_driver='local_public' WHERE ing.organization_id=? AND mii.menu_item_id IN ($ph) ORDER BY mii.menu_item_id,mii.sort_order,ing.canonical_name");
        $q->execute(array_merge([$organizationId],$itemIds));foreach($q->fetchAll() as $r)$ingredients[(int)$r['menu_item_id']][]=['id'=>(int)$r['id'],'name'=>(string)$r['name'],'imageUrl'=>!empty($r['storage_path'])?app_url((string)$r['storage_path']):''];
    }
    $out=[];
    foreach($menu as $section){$items=[];foreach($section['items'] as $item){$detail=$ingredients[(int)$item['id']]??[];$prices=array_map(static fn(array $p):array=>['label'=>(string)$p['optionName'],'price'=>(float)$p['amount']],$item['prices']);$items[]=['id'=>(int)$item['id'],'name'=>(string)$item['name'],'description'=>(string)($item['description']??''),'specialNotes'=>'','ingredients'=>array_column($detail,'name'),'ingredientDetails'=>$detail,'prices'=>$prices,'facts'=>[],'tags'=>[],'sourceUrl'=>'','imageUrl'=>(string)($item['imageUrl']??''),'rawPrice'=>'','featured'=>false];}$out[]=['id'=>menu_slugify((string)$section['name']),'name'=>(string)$section['name'],'icon'=>menu_section_icon(menu_slugify((string)$section['name'])),'intro'=>'','facts'=>[],'items'=>$items];}
    return $out;
}
