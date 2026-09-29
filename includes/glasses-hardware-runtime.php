<?php
declare(strict_types=1);

const GLASSES_HARDWARE_RUNTIME_SCHEMA='gelato.glasses_hardware_runtime.v1';

interface GlassesHardwareRuntimeAdapter
{
    public function adapterId(): string;
    public function platform(): string;
    public function capabilities(): array;
    public function normalizeFrame(array $frame): array;
    public function normalizeInput(array $input): array;
    public function normalizeDisplay(array $display): array;
    public function normalizeModelLoad(array $model): array;
}

final class GlassesSimulatorHardwareAdapter implements GlassesHardwareRuntimeAdapter
{
    public function adapterId(): string { return 'simulator.v1'; }
    public function platform(): string { return 'browser_simulator'; }

    public function capabilities(): array
    {
        return [
          'schema'=>GLASSES_HARDWARE_RUNTIME_SCHEMA,
          'adapterId'=>$this->adapterId(),
          'platform'=>$this->platform(),
          'camera'=>[
            'available'=>true,
            'sources'=>['browser_camera','image_fixture'],
            'pixelFormats'=>['rgba8','rgb8','grayscale8'],
            'maxWidth'=>3840,'maxHeight'=>2160,
          ],
          'display'=>[
            'available'=>true,'mode'=>'browser_hud',
            'projectionSurfaces'=>['left_lens','right_lens','binocular_safe_area'],
          ],
          'input'=>[
            'available'=>true,
            'actions'=>['confirm','cancel','next','previous','select','voice_intent','simulator_control'],
          ],
          'inference'=>[
            'available'=>true,'runtimes'=>['fixture','onnx'],
            'vendorRuntimeAvailable'=>false,
            'vendorRuntimeReason'=>'AIR3 proprietary SDK/model-loader files not installed',
          ],
          'transport'=>[
            'frameEnvelope'=>'gelato.glasses_frame.v1',
            'displayEnvelope'=>'gelato.glasses_display.v1',
            'inputEnvelope'=>'gelato.glasses_input.v1',
            'modelEnvelope'=>'gelato.glasses_model_load.v1',
          ],
        ];
    }

    public function normalizeFrame(array $frame): array
    {
        $frameKey=mb_substr(trim((string)($frame['frameKey']??'')),0,190,'UTF-8');
        if($frameKey==='')throw new InvalidArgumentException('Hardware frame key is required.');
        $width=(int)($frame['width']??0);$height=(int)($frame['height']??0);
        if($width<32||$width>3840||$height<32||$height>2160)
            throw new InvalidArgumentException('Hardware frame dimensions are outside the supported simulator contract.');
        $pixel=strtolower(trim((string)($frame['pixelFormat']??'rgba8')));
        if(!in_array($pixel,['rgba8','rgb8','grayscale8'],true))
            throw new InvalidArgumentException('Hardware frame pixel format is unsupported.');
        $capturedAt=trim((string)($frame['capturedAt']??''));
        if($capturedAt==='')$capturedAt=gmdate('c');
        try{$captured=(new DateTimeImmutable($capturedAt))->setTimezone(new DateTimeZone('UTC'));}
        catch(Throwable){throw new InvalidArgumentException('Hardware frame capturedAt is invalid.');}
        $source=(string)($frame['source']??'browser_camera');
        if(!in_array($source,['browser_camera','image_fixture'],true))
            throw new InvalidArgumentException('Hardware frame source is unsupported.');

        $detections=[];
        foreach((array)($frame['detections']??[]) as $d){
            if(!is_array($d))continue;
            $label=mb_substr(trim((string)($d['label']??'')),0,160,'UTF-8');
            $confidence=(float)($d['confidence']??-1);
            $bbox=(array)($d['bbox']??[]);
            if($label===''||$confidence<0||$confidence>1||count($bbox)!==4)continue;
            $coords=array_map('floatval',array_values($bbox));
            if(array_filter($coords,static fn($v)=>!is_finite($v)||$v<0||$v>1))continue;
            $detections[]=['label'=>$label,'confidence'=>round($confidence,6),'bbox'=>$coords,'attributes'=>is_array($d['attributes']??null)?$d['attributes']:[]];
        }

        return [
          'schema'=>'gelato.glasses_frame.v1','adapterId'=>$this->adapterId(),'platform'=>$this->platform(),
          'frameKey'=>$frameKey,'source'=>$source,'width'=>$width,'height'=>$height,'pixelFormat'=>$pixel,
          'capturedAt'=>$captured->format(DATE_ATOM),'detections'=>$detections,
        ];
    }

    public function normalizeInput(array $input): array
    {
        $action=strtolower(trim((string)($input['action']??'')));
        if(!in_array($action,$this->capabilities()['input']['actions'],true))
            throw new InvalidArgumentException('Hardware input action is unsupported.');
        return [
          'schema'=>'gelato.glasses_input.v1','adapterId'=>$this->adapterId(),'action'=>$action,
          'value'=>$input['value']??null,'occurredAt'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format(DATE_ATOM),
        ];
    }

    public function normalizeDisplay(array $display): array
    {
        $surface=strtolower(trim((string)($display['surface']??'binocular_safe_area')));
        if(!in_array($surface,$this->capabilities()['display']['projectionSurfaces'],true))
            throw new InvalidArgumentException('Hardware display surface is unsupported.');
        $elements=array_values(array_filter((array)($display['elements']??[]),'is_array'));
        if(count($elements)>64)throw new InvalidArgumentException('Hardware display element limit exceeded.');
        return [
          'schema'=>'gelato.glasses_display.v1','adapterId'=>$this->adapterId(),'surface'=>$surface,
          'elements'=>$elements,'replace'=>!array_key_exists('replace',$display)||!empty($display['replace']),
        ];
    }

    public function normalizeModelLoad(array $model): array
    {
        $runtime=strtolower(trim((string)($model['runtime']??'')));
        if(!in_array($runtime,['fixture','onnx'],true))
            throw new InvalidArgumentException('Simulator model runtime is unsupported.');
        $sha=strtolower(trim((string)($model['artifactSha256']??'')));
        if($runtime==='onnx'&&!preg_match('/^[a-f0-9]{64}$/',$sha))
            throw new InvalidArgumentException('ONNX model load requires an artifact SHA-256.');
        return [
          'schema'=>'gelato.glasses_model_load.v1','adapterId'=>$this->adapterId(),'runtime'=>$runtime,
          'detectorName'=>mb_substr(trim((string)($model['detectorName']??'')),0,160,'UTF-8'),
          'packagePublicId'=>trim((string)($model['packagePublicId']??''))?:null,
          'artifactSha256'=>$runtime==='onnx'?$sha:null,
          'vendorSdkRequired'=>false,
        ];
    }
}

function glasses_hardware_runtime_adapter(string $adapterId='simulator.v1'): GlassesHardwareRuntimeAdapter
{
    if($adapterId==='simulator.v1')return new GlassesSimulatorHardwareAdapter();
    throw new InvalidArgumentException('Hardware runtime adapter is not installed.');
}

function glasses_hardware_runtime_contract(string $adapterId='simulator.v1'): array
{
    $adapter=glasses_hardware_runtime_adapter($adapterId);
    return [
      'schema'=>GLASSES_HARDWARE_RUNTIME_SCHEMA,
      'adapterId'=>$adapter->adapterId(),
      'platform'=>$adapter->platform(),
      'capabilities'=>$adapter->capabilities(),
      'sdkBoundary'=>[
        'vendorAdapterInstalled'=>false,
        'requiredAdapterId'=>'air3.vendor.v1',
        'integrationPoint'=>'GlassesHardwareRuntimeAdapter',
        'blockedBySdk'=>['native_camera','native_display','native_input','vendor_inference'],
        'notBlockedBySdk'=>['v9_kitchen_intelligence','simulator','browser_camera','governed_onnx','pos_kds_sync'],
      ],
    ];
}
