<?php
declare(strict_types=1);

require __DIR__.'/../includes/bootstrap.php';
require __DIR__.'/../includes/sales-import-center-core.php';

$pdo=app_pdo();
function sic_assert(bool $condition,string $message): void { if(!$condition) throw new RuntimeException($message); }
function sic_scalar(PDO $pdo,string $sql,array $args=[]): mixed { $q=$pdo->prepare($sql);$q->execute($args);return $q->fetchColumn(); }

sic_assert(sales_import_center_ready($pdo),'Sales Import Center must be installed.');
$slug='import-center-ci-'.bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Import Center CI '.$slug]);$org=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO locations (organization_id,name,city,state,status) VALUES (?,'Main Restaurant','Phoenix','AZ','active')")->execute([$org]);$location=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (email,password_hash,first_name,last_name,display_name,status) VALUES (?,?,?,?,?,'active')")->execute([$slug.'@example.test',password_hash('CI-only-password',PASSWORD_DEFAULT),'Import','Manager','Import Manager']);$uid=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO organization_memberships (organization_id,user_id,primary_location_id,job_title,status) VALUES (?,?,?,'Manager','active')")->execute([$org,$uid,$location]);
sales_ensure_csv_integration($pdo,$org,$uid);

$csv=tempnam(sys_get_temp_dir(),'gelato-import-center-');sic_assert(is_string($csv),'Temp CSV could not be created.');
$rows=[
 ['Business Date','Revenue','Orders','Guests','Secret Customer Email'],
 ['2026-09-09','410.50','18','29','private-one@example.test'],
 ['2026-09-10','520.25','22','34','private-two@example.test'],
 ['2026-09-11','615.75','25','39','private-three@example.test'],
];
$fh=fopen($csv,'wb');sic_assert((bool)$fh,'Temp CSV could not be opened.');foreach($rows as $row)fputcsv($fh,$row);fclose($fh);

$preview=sales_import_center_preview($pdo,$org,$csv);
sic_assert(($preview['autoMapping']['date']??'')==='Business Date','Business Date should auto-map to date.');
sic_assert(($preview['autoMapping']['net_sales']??'')==='Revenue','Revenue should auto-map to net sales.');
sic_assert(($preview['autoMapping']['tickets']??'')==='Orders','Orders should auto-map to tickets.');
sic_assert(($preview['autoMapping']['covers']??'')==='Guests','Guests should auto-map to covers.');
sic_assert(!in_array('Secret Customer Email',$preview['autoMapping'],true),'Unknown customer columns must never be mapped into the canonical sales schema.');
$reordered=['Secret Customer Email','Guests','Revenue','Business Date','Orders'];
sic_assert($preview['headerSignature']===sales_import_center_signature($reordered),'Header signature must be independent of column order.');

$normalized=sales_import_center_normalize($csv,$preview['autoMapping'],'daily','all');
$normalizedText=(string)file_get_contents($normalized);sic_assert(!str_contains($normalizedText,'Secret Customer Email'),'Normalized CSV must not include unknown source headers.');
sic_assert(!str_contains($normalizedText,'private-one@example.test'),'Normalized CSV must not include unknown customer values.');
@unlink($normalized);

$profile=sales_import_center_profile_save($pdo,$org,[
 'profileName'=>'Daily POS Export',
 'locationId'=>$location,
 'cadence'=>'daily',
 'defaultGranularity'=>'daily',
 'defaultServicePeriod'=>'all',
 'headerSignature'=>$preview['headerSignature'],
 'mapping'=>$preview['autoMapping'],
],$uid);
sic_assert((string)$profile['name']==='Daily POS Export','Import profile should save.');

$result=sales_import_center_import($pdo,$org,$uid,$csv,'daily-pos-export.csv',[
 'profileId'=>$profile['public_id'],
 'allowDuplicate'=>false,
]);
sic_assert((int)$result['import']['accepted']===3,'All three normalized sales rows should import.');
sic_assert((int)$result['import']['rejected']===0,'No normalized sales rows should reject.');
sic_assert((string)($result['profile']['last_period_end']??'')==='2026-09-11','Profile should record the latest imported period.');

$batchPublic=(string)$result['import']['publicId'];
$linked=(int)sic_scalar($pdo,"SELECT COUNT(*) FROM sales_import_batches b JOIN sales_import_profiles p ON p.id=b.import_profile_id AND p.organization_id=b.organization_id WHERE b.organization_id=? AND b.public_id=? AND p.public_id=?",[$org,$batchPublic,$profile['public_id']]);
sic_assert($linked===1,'Import batch must link back to the mapping profile.');
$periods=(int)sic_scalar($pdo,"SELECT COUNT(*) FROM sales_periods WHERE organization_id=? AND location_key=? AND source_provider='csv' AND granularity='daily' AND service_period='all'",[$org,'id:'.$location]);
sic_assert($periods===3,'Three daily sales periods should be created.');
$total=(float)sic_scalar($pdo,"SELECT COALESCE(SUM(net_sales),0) FROM sales_periods WHERE organization_id=? AND location_key=? AND source_provider='csv' AND granularity='daily' AND service_period='all'",[$org,'id:'.$location]);
sic_assert(abs($total-1546.50)<0.01,'Normalized net-sales total is incorrect.');

$raw=(string)sic_scalar($pdo,"SELECT raw_json FROM sales_import_rows r JOIN sales_import_batches b ON b.id=r.import_batch_id WHERE r.organization_id=? AND b.public_id=? ORDER BY r.source_row_number LIMIT 1",[$org,$batchPublic]);
sic_assert(!str_contains($raw,'Secret Customer Email')&&!str_contains($raw,'private-one@example.test'),'Unknown customer columns must not persist in row audit data.');

$previewAgain=sales_import_center_preview($pdo,$org,$csv);sic_assert(count($previewAgain['matchingProfiles'])>=1,'Saved profile should be recognized by header fingerprint.');
$matched=$previewAgain['matchingProfiles'][0];sic_assert((string)$matched['public_id']===(string)$profile['public_id'],'Matching profile should resolve to the saved mapping.');

$pdo->prepare("UPDATE sales_import_profiles SET last_period_end='2026-08-01',last_success_at='2026-08-01 12:00:00' WHERE organization_id=? AND public_id=?")->execute([$org,$profile['public_id']]);
$dashboard=sales_import_center_dashboard($pdo,$org);$found=null;foreach($dashboard['profiles'] as $p)if((string)$p['public_id']===(string)$profile['public_id']){$found=$p;break;}sic_assert(is_array($found),'Profile should appear in Import Center dashboard.');sic_assert(($found['health']['state']??'')==='stale'&&(int)($found['health']['periodsBehind']??0)>0,'Overdue daily import profile should be marked stale.');sic_assert((int)$dashboard['summary']['stale']>=1,'Dashboard stale counter should reflect overdue profiles.');

$duplicateBlocked=false;try{sales_import_center_import($pdo,$org,$uid,$csv,'daily-pos-export.csv',['profileId'=>$profile['public_id']]);}catch(InvalidArgumentException){$duplicateBlocked=true;}sic_assert($duplicateBlocked,'Exact duplicate normalized import must remain checksum-protected.');

$pdo->prepare("INSERT INTO organizations (name,status,timezone) VALUES (?,'active','America/Phoenix')")->execute(['Import Center Isolation '.$slug]);$otherOrg=(int)$pdo->lastInsertId();$other=sales_import_center_dashboard($pdo,$otherOrg);sic_assert(count($other['profiles'])===0&&count($other['recentImports'])===0,'Cross-organization import profiles or history leaked.');

@unlink($csv);
echo "sales-import-center contract passed\n";
