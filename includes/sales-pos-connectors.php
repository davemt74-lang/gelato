<?php
declare(strict_types=1);

function sales_pos_env(string $key): ?string
{
    $value=getenv($key);if($value===false||trim($value)==='')return null;return trim($value);
}

function sales_toast_config(): array
{
    return [
        'baseUrl'=>rtrim((string)(sales_pos_env('TOAST_API_BASE_URL')??''),'/'),
        'clientId'=>sales_pos_env('TOAST_CLIENT_ID'),
        'clientSecret'=>sales_pos_env('TOAST_CLIENT_SECRET'),
        'restaurantGuid'=>sales_pos_env('TOAST_RESTAURANT_GUID'),
    ];
}

function sales_toast_status(): array
{
    $c=sales_toast_config();$configured=$c['baseUrl']!==''&&$c['clientId']!==null&&$c['clientSecret']!==null&&$c['restaurantGuid']!==null;
    return ['provider'=>'toast','configured'=>$configured,'credentialsStoredInDatabase'=>false,'missing'=>array_values(array_filter(['TOAST_API_BASE_URL'=>$c['baseUrl']===''?'TOAST_API_BASE_URL':null,'TOAST_CLIENT_ID'=>$c['clientId']===null?'TOAST_CLIENT_ID':null,'TOAST_CLIENT_SECRET'=>$c['clientSecret']===null?'TOAST_CLIENT_SECRET':null,'TOAST_RESTAURANT_GUID'=>$c['restaurantGuid']===null?'TOAST_RESTAURANT_GUID':null]))];
}

function sales_http_json(string $method,string $url,array $headers=[],?array $body=null,int $timeout=20): array
{
    if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL is required for POS API connections.');$ch=curl_init($url);if($ch===false)throw new RuntimeException('Could not initialize HTTP client.');$headerList=['Accept: application/json'];foreach($headers as $k=>$v)$headerList[]=$k.': '.$v;$opts=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>$timeout,CURLOPT_HTTPHEADER=>$headerList];if($body!==null){$payload=json_encode($body,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$headerList[]='Content-Type: application/json';$opts[CURLOPT_HTTPHEADER]=$headerList;$opts[CURLOPT_POSTFIELDS]=$payload;}curl_setopt_array($ch,$opts);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);if($raw===false||$error!=='')throw new RuntimeException('POS connection failed.');$decoded=json_decode((string)$raw,true);if($status<200||$status>=300){$message=is_array($decoded)?(string)($decoded['message']??$decoded['status']??'POS API request failed.'):'POS API request failed.';throw new RuntimeException($message.' HTTP '.$status.'.');}return is_array($decoded)?$decoded:[];
}

function sales_toast_access_token(): string
{
    $c=sales_toast_config();$status=sales_toast_status();if(!$status['configured'])throw new RuntimeException('Toast is not configured. Set the required Toast environment variables on the server.');$response=sales_http_json('POST',$c['baseUrl'].'/authentication/v1/authentication/login',[],['clientId'=>$c['clientId'],'clientSecret'=>$c['clientSecret'],'userAccessType'=>'TOAST_MACHINE_CLIENT']);$token=(string)($response['token']['accessToken']??'');if($token==='')throw new RuntimeException('Toast authentication did not return an access token.');return $token;
}

function sales_toast_connection_test(): array
{
    $started=microtime(true);$token=sales_toast_access_token();return ['ok'=>$token!=='','provider'=>'toast','authenticated'=>true,'elapsedMs'=>(int)round((microtime(true)-$started)*1000),'restaurantGuid'=>sales_toast_config()['restaurantGuid']];
}

function sales_toast_orders_for_business_date(string $businessDate,int $page=1,int $pageSize=100): array
{
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$businessDate);if(!$d||$d->format('Y-m-d')!==$businessDate)throw new InvalidArgumentException('Toast business date must use YYYY-MM-DD.');$c=sales_toast_config();$token=sales_toast_access_token();$page=max(1,$page);$pageSize=max(1,min(100,$pageSize));$query=http_build_query(['businessDate'=>$d->format('Ymd'),'page'=>$page,'pageSize'=>$pageSize]);return sales_http_json('GET',$c['baseUrl'].'/orders/v2/ordersBulk?'.$query,['Authorization'=>'Bearer '.$token,'Toast-Restaurant-External-ID'=>(string)$c['restaurantGuid']]);
}
