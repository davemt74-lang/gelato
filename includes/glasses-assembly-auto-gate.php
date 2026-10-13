<?php
declare(strict_types=1);
/**
 * Automatic browser recognition must be revalidated at the kitchen backend.
 * Browser confidence, profile selection and model claims are not authoritative.
 * The camera pixels remain client-observed: this is a governance check,
 * NOT cryptographic hardware or physical ingredient attestation.
 */
require_once __DIR__.'/glasses-vision-models.php';
require_once __DIR__.'/glasses-vision-profiles.php';
require_once __DIR__.'/glasses-employee-runtimes.php';

function glasses_assembly_auto_observation_validate(PDO $pdo,array $device,array $user,
    string $buildSessionPublicId,array $input): void
{
    $meta=is_array($input['metadata']??null)?$input['metadata']:[];
    if(($meta['automatic']??null)!==true)return;
    if((string)($meta['source']??'')!=='web_glasses_simulator_auto_vision'
       ||(string)($meta['adapter']??'')!=='onnx')
        throw new DomainException('Automatic browser observations require the governed ONNX adapter.');
    $runtimeId=trim((string)($input['runtimePublicId']??''));
    if($runtimeId==='')throw new DomainException('Automatic observations require an assigned glasses runtime.');
    $runtime=glasses_employee_runtime_assert_binding($pdo,$user,$runtimeId,$device);
    if((string)($meta['runtimePublicId']??'')!==$runtimeId)
        throw new DomainException('Automatic observation runtime identity changed.');
    $detector=(string)($meta['detectorName']??'');
    $sha=strtolower(trim((string)($meta['modelSha256']??'')));
    $profileHash=strtolower(trim((string)($meta['profileHash']??'')));
    $label=glasses_vision_normalize_label((string)($meta['detectorLabel']??''));
    $componentKey=trim((string)($input['componentKey']??''));
    $trackingId=trim((string)($input['trackingId']??''));
    if($detector===''||$detector==='*'||!preg_match('/^[a-f0-9]{64}$/',$sha)
       ||!preg_match('/^[a-f0-9]{64}$/',$profileHash)||$label===''||
       $componentKey===''||$trackingId==='')
        throw new DomainException('Automatic observation is missing traceable model, profile or track evidence.');
    $confidence=(float)($input['confidence']??0);
    $threshold=(float)($meta['requestedThreshold']??0);
    if(!is_finite($confidence)||!is_finite($threshold)||$threshold<0.40||$threshold>0.99)
        throw new DomainException('Automatic observation confidence policy is invalid.');
    $assignment=glasses_vision_model_assignment($pdo,$device,$buildSessionPublicId,$detector);
    if(($assignment['action']??null)!=='apply'||($assignment['compatibility']['compatible']??null)!==true)
        throw new DomainException('No approved governed model applies to this build.');
    if(strtolower((string)($assignment['package']['artifactSha256']??''))!==$sha
       ||(string)($assignment['package']['runtimeType']??'')!=='onnx')
        throw new DomainException('Automatic observation model checksum or runtime does not match the approved assignment.');
    $profile=glasses_vision_profile_for_build($pdo,$device,$buildSessionPublicId,$detector);
    if(!hash_equals(strtolower((string)($profile['profileHash']??'')),$profileHash))
        throw new DomainException('Vision label profile changed. Reload the governed model.');
    $matches=array_values(array_filter((array)($profile['mappings']??[]),
        static fn(array $m):bool=>
            (string)($m['normalizedLabel']??'')===$label
            &&(string)($m['componentKey']??'')===$componentKey));
    if(count($matches)!==1)throw new DomainException('Detector label lacks one approved mapping to this build component.');
    $floor=max(0.90,$threshold,(float)($matches[0]['minimumConfidence']??0));
    if($confidence<$floor||$confidence>1.0)
        throw new DomainException('Automatic observation is below the governed ingredient confidence floor.');
    $bbox=is_array($input['bbox']??null)?$input['bbox']:[];
    foreach(['x','y','width','height'] as $k){
        if(!isset($bbox[$k])||!is_numeric($bbox[$k])||!is_finite((float)$bbox[$k]))
            throw new DomainException('Automatic camera observation requires a valid bounding box.');
    }
    $x=(float)$bbox['x'];$y=(float)$bbox['y'];$w=(float)$bbox['width'];$h=(float)$bbox['height'];
    if($x<0||$y<0||$w<=0||$h<=0||$x+$w>1.00001||$y+$h>1.00001)
        throw new DomainException('Automatic camera bounding box is outside the image.');

    // Device, build session, location and active KDS state remain enforced by
    // glasses_build_observe() and the simulator's existing permission gates.
}
