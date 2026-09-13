<?php
declare(strict_types=1);

require_once __DIR__.'/sales-intelligence-core.php';
require_once __DIR__.'/sales-location-rollup.php';

function sales_import_center_ready(PDO $pdo): bool
{
    return sales_intelligence_ready($pdo) && restaurant_brain_table_ready($pdo,'sales_import_profiles');
}

function sales_import_center_signature(array $headers): string
{
    $keys=array_values(array_filter(array_map(static fn($h)=>sales_header_key((string)$h),$headers),static fn($v)=>$v!==''));
    sort($keys,SORT_STRING);return hash('sha256',implode('|',$keys));
}

function sales_import_center_named_mapping(array $headers,array $indexMapping): array
{
    $out=[];foreach($indexMapping as $field=>$index){if(array_key_exists((int)$index,$headers))$out[(string)$field]=(string)$headers[(int)$index];}return $out;
}

function sales_import_center_index_mapping(array $headers,array $namedMapping): array
{
    $lookup=[];foreach($headers as $i=>$header)$lookup[sales_header_key((string)$header)]=(int)$i;$allowed=array_keys(sales_alias_map());$out=[];
    foreach($namedMapping as $field=>$header){if(!in_array((string)$field,$allowed,true))continue;$key=sales_header_key((string)$header);if($key!==''&&array_key_exists($key,$lookup))$out[(string)$field]=$lookup[$key];}
    return $out;
}

function sales_import_center_validate_mapping(array $mapping): void
{
    if(empty($mapping['date']))throw new InvalidArgumentException('Map a source column to Date / business date.');
    $evidence=false;foreach(['net_sales','gross_sales','tickets','covers','item_name','quantity','item_sales'] as $field)if(!empty($mapping[$field])){$evidence=true;break;}
    if(!$evidence)throw new InvalidArgumentException('Map at least one sales field such as net sales, tickets, covers, item name, quantity or item sales.');
}

function sales_import_center_read_headers(string $path): array
{
    if(!is_file($path)||filesize($path)<1)throw new InvalidArgumentException('The sales file is empty.');
    if(filesize($path)>15*1024*1024)throw new InvalidArgumentException('Sales CSV files must be 15 MB or smaller.');
    $delimiter=sales_csv_delimiter($path);$fh=fopen($path,'rb');if(!$fh)throw new RuntimeException('Could not open the sales CSV.');$headers=fgetcsv($fh,0,$delimiter);fclose($fh);
    if(!is_array($headers)||!$headers)throw new InvalidArgumentException('The CSV needs a header row.');
    $headers=array_map(static fn($v)=>preg_replace('/^\xEF\xBB\xBF/','',(string)$v)??(string)$v,$headers);
    return ['headers'=>$headers,'delimiter'=>$delimiter,'signature'=>sales_import_center_signature($headers)];
}

function sales_import_center_preview(PDO $pdo,int $org,string $path): array
{
    $meta=sales_import_center_read_headers($path);$auto=sales_import_center_named_mapping($meta['headers'],sales_column_mapping($meta['headers']));
    $q=$pdo->prepare("SELECT p.public_id,p.name,p.location_id,p.provider,p.cadence,p.default_granularity,p.default_service_period,p.mapping_json,p.last_success_at,p.last_period_end,l.name location_name FROM sales_import_profiles p LEFT JOIN locations l ON l.id=p.location_id AND l.organization_id=p.organization_id WHERE p.organization_id=? AND p.header_signature=? AND p.status='active' AND p.archived_at IS NULL ORDER BY p.last_success_at DESC,p.id DESC LIMIT 12");$q->execute([$org,$meta['signature']]);$matches=$q->fetchAll();
    foreach($matches as &$row){$row['mapping']=$row['mapping_json']?json_decode((string)$row['mapping_json'],true):[];unset($row['mapping_json']);}unset($row);
    return ['headers'=>$meta['headers'],'delimiter'=>$meta['delimiter'],'headerSignature'=>$meta['signature'],'autoMapping'=>$auto,'matchingProfiles'=>$matches,'canonicalFields'=>array_keys(sales_alias_map())];
}

function sales_import_center_profile(PDO $pdo,int $org,string $publicId): ?array
{
    if($publicId==='')return null;$q=$pdo->prepare("SELECT * FROM sales_import_profiles WHERE organization_id=? AND public_id=? AND status='active' AND archived_at IS NULL LIMIT 1");$q->execute([$org,$publicId]);$row=$q->fetch();if(!$row)return null;$row['mapping']=$row['mapping_json']?json_decode((string)$row['mapping_json'],true):[];unset($row['mapping_json']);return $row;
}

function sales_import_center_profile_save(PDO $pdo,int $org,array $data,int $uid): array
{
    $public=trim((string)($data['profileId']??''));$name=mb_substr(trim((string)($data['profileName']??'')),0,160,'UTF-8');if($name==='')$name='Sales CSV '.date('Y-m-d');
    $locationId=isset($data['locationId'])&&$data['locationId']!==''?(int)$data['locationId']:null;if($locationId){$q=$pdo->prepare("SELECT id FROM locations WHERE organization_id=? AND id=? AND status='active'");$q->execute([$org,$locationId]);if(!$q->fetchColumn())throw new InvalidArgumentException('Import profile location was not found.');}
    $cadence=(string)($data['cadence']??'manual');if(!in_array($cadence,['manual','daily','weekly','monthly'],true))$cadence='manual';$granularity=(string)($data['defaultGranularity']??'daily');if(!in_array($granularity,['daily','weekly','monthly'],true))$granularity='daily';$service=sales_service_period((string)($data['defaultServicePeriod']??'all'));
    $signature=(string)($data['headerSignature']??'');if(!preg_match('/^[a-f0-9]{64}$/',$signature))throw new InvalidArgumentException('Import profile header signature is invalid.');$mapping=is_array($data['mapping']??null)?$data['mapping']:[];sales_import_center_validate_mapping($mapping);$mappingJson=json_encode($mapping,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if($public!==''){$q=$pdo->prepare("SELECT id FROM sales_import_profiles WHERE organization_id=? AND public_id=? AND archived_at IS NULL");$q->execute([$org,$public]);$id=(int)($q->fetchColumn()?:0);if(!$id)throw new InvalidArgumentException('Import profile not found.');$pdo->prepare("UPDATE sales_import_profiles SET location_id=?,name=?,cadence=?,default_granularity=?,default_service_period=?,header_signature=?,mapping_json=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?")->execute([$locationId,$name,$cadence,$granularity,$service,$signature,$mappingJson,$uid,$org,$id]);}
    else{$public=sales_public_id('sales-import-profile');$pdo->prepare("INSERT INTO sales_import_profiles (organization_id,location_id,public_id,name,provider,cadence,default_granularity,default_service_period,header_signature,mapping_json,created_by,updated_by) VALUES (?,?,?,?,'csv',?,?,?,?,?,?,?)")->execute([$org,$locationId,$public,$name,$cadence,$granularity,$service,$signature,$mappingJson,$uid,$uid]);}
    $profile=sales_import_center_profile($pdo,$org,$public);if(!$profile)throw new RuntimeException('Import profile could not be loaded.');return $profile;
}

function sales_import_center_normalize(string $sourcePath,array $namedMapping,string $granularity,string $service): string
{
    $meta=sales_import_center_read_headers($sourcePath);$index=sales_import_center_index_mapping($meta['headers'],$namedMapping);sales_import_center_validate_mapping(sales_import_center_named_mapping($meta['headers'],$index));
    $granularity=in_array($granularity,['daily','weekly','monthly'],true)?$granularity:'daily';$service=sales_service_period($service);$canonical=array_keys(sales_alias_map());
    $src=fopen($sourcePath,'rb');if(!$src)throw new RuntimeException('Could not reopen the sales CSV.');fgetcsv($src,0,$meta['delimiter']);$temp=tempnam(sys_get_temp_dir(),'gelato-sales-normalized-');if(!is_string($temp)){fclose($src);throw new RuntimeException('Could not create a normalized sales file.');}$dst=fopen($temp,'wb');if(!$dst){fclose($src);@unlink($temp);throw new RuntimeException('Could not write the normalized sales file.');}fputcsv($dst,$canonical);
    while(($row=fgetcsv($src,0,$meta['delimiter']))!==false){if(count(array_filter($row,static fn($v)=>trim((string)$v)!==''))===0)continue;$out=[];foreach($canonical as $field){$i=$index[$field]??null;$out[$field]=$i===null?'':trim((string)($row[$i]??''));}$out['granularity']=$out['granularity']!==''?$out['granularity']:$granularity;$out['service_period']=$out['service_period']!==''?$out['service_period']:$service;if($out['row_type']==='')$out['row_type']=$out['item_name']!==''?'item':'summary';fputcsv($dst,array_map(static fn($f)=>(string)$out[$f],$canonical));}
    fclose($src);fclose($dst);return $temp;
}

function sales_import_center_import(PDO $pdo,int $org,int $uid,string $path,string $originalName,array $options): array
{
    $meta=sales_import_center_read_headers($path);$profileId=trim((string)($options['profileId']??''));$profile=$profileId!==''?sales_import_center_profile($pdo,$org,$profileId):null;$mapping=is_array($options['mapping']??null)?$options['mapping']:($profile['mapping']??[]);if(!$mapping)$mapping=sales_import_center_named_mapping($meta['headers'],sales_column_mapping($meta['headers']));sales_import_center_validate_mapping($mapping);
    $locationId=isset($options['locationId'])&&$options['locationId']!==''?(int)$options['locationId']:($profile['location_id']??null);$granularity=(string)($options['defaultGranularity']??($profile['default_granularity']??'daily'));$service=(string)($options['defaultServicePeriod']??($profile['default_service_period']??'all'));$cadence=(string)($options['cadence']??($profile['cadence']??'manual'));
    $normalized=sales_import_center_normalize($path,$mapping,$granularity,$service);try{$result=sales_import_csv($pdo,$org,$uid,$normalized,$originalName,$locationId,!empty($options['allowDuplicate']));sales_rebuild_all_location_rollups($pdo,$org,'csv');}finally{@unlink($normalized);}
    $save=!empty($options['saveProfile'])||$profile!==null;$savedProfile=$profile;if($save){$savedProfile=sales_import_center_profile_save($pdo,$org,['profileId'=>$profile['public_id']??'','profileName'=>$options['profileName']??($profile['name']??''),'locationId'=>$locationId,'cadence'=>$cadence,'defaultGranularity'=>$granularity,'defaultServicePeriod'=>$service,'headerSignature'=>$meta['signature'],'mapping'=>$mapping],$uid);$q=$pdo->prepare("UPDATE sales_import_profiles SET last_success_at=NOW(6),last_period_start=?,last_period_end=?,last_filename=?,updated_by=?,updated_at=NOW(6) WHERE organization_id=? AND id=?");$q->execute([$result['periodStart'],$result['periodEnd'],mb_substr($originalName,0,255,'UTF-8'),$uid,$org,(int)$savedProfile['id']]);$batch=$pdo->prepare("UPDATE sales_import_batches SET import_profile_id=? WHERE organization_id=? AND public_id=?");$batch->execute([(int)$savedProfile['id'],$org,$result['publicId']]);$savedProfile=sales_import_center_profile($pdo,$org,(string)$savedProfile['public_id']);}
    return ['import'=>$result,'profile'=>$savedProfile,'headerSignature'=>$meta['signature']];
}

function sales_import_center_health(array $profile): array
{
    $cadence=(string)$profile['cadence'];$last=(string)($profile['last_period_end']??'');if($cadence==='manual'||$last==='')return ['state'=>$last===''?'never_imported':'manual','nextDue'=>null,'periodsBehind'=>0];
    $d=new DateTimeImmutable($last);$today=new DateTimeImmutable('today');if($cadence==='daily'){$next=$d->modify('+1 day');$intervalDays=1;}elseif($cadence==='weekly'){$next=$d->modify('+7 days');$intervalDays=7;}else{$next=$d->modify('last day of next month');$intervalDays=max(28,(int)$d->diff($next)->days);}
    $behind=0;if($today>$next){$days=(int)$next->diff($today)->days;$behind=$cadence==='monthly'?max(1,((int)$today->format('Y')-(int)$next->format('Y'))*12+(int)$today->format('n')-(int)$next->format('n')+1):1+(int)floor($days/$intervalDays);}
    return ['state'=>$behind>0?'stale':'current','nextDue'=>$next->format('Y-m-d'),'periodsBehind'=>$behind];
}

function sales_import_center_profiles(PDO $pdo,int $org): array
{
    $q=$pdo->prepare("SELECT p.*,l.name location_name FROM sales_import_profiles p LEFT JOIN locations l ON l.id=p.location_id AND l.organization_id=p.organization_id WHERE p.organization_id=? AND p.status='active' AND p.archived_at IS NULL ORDER BY p.name,p.id");$q->execute([$org]);$rows=$q->fetchAll();foreach($rows as &$row){$row['mapping']=$row['mapping_json']?json_decode((string)$row['mapping_json'],true):[];unset($row['mapping_json']);$row['health']=sales_import_center_health($row);}unset($row);return $rows;
}

function sales_import_center_dashboard(PDO $pdo,int $org): array
{
    $profiles=sales_import_center_profiles($pdo,$org);$stale=0;$never=0;foreach($profiles as $p){if(($p['health']['state']??'')==='stale')$stale++;if(($p['health']['state']??'')==='never_imported')$never++;}
    $q=$pdo->prepare("SELECT b.public_id,b.original_filename,b.period_start,b.period_end,b.accepted_count,b.rejected_count,b.status,b.imported_at,p.public_id profile_public_id,p.name profile_name FROM sales_import_batches b LEFT JOIN sales_import_profiles p ON p.id=b.import_profile_id AND p.organization_id=b.organization_id WHERE b.organization_id=? ORDER BY b.id DESC LIMIT 30");$q->execute([$org]);
    return ['profiles'=>$profiles,'summary'=>['profiles'=>count($profiles),'stale'=>$stale,'neverImported'=>$never],'recentImports'=>$q->fetchAll()];
}
