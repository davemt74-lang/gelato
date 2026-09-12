<?php
declare(strict_types=1);

require_once __DIR__ . '/restaurant-brain.php';

function recipe_intelligence_credential(PDO $pdo, int $organizationId, string $provider): ?array
{
    $statement = $pdo->prepare("SELECT provider, encrypted_key, nonce, encryption_method, status FROM llm_api_credentials WHERE organization_id=? AND provider=? AND status='configured' LIMIT 1");
    $statement->execute([$organizationId, $provider]);
    $row = $statement->fetch();
    return $row ?: null;
}

function recipe_intelligence_http_json(string $url, array $headers, array $payload): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL is required for recipe AI mapping.');
    $handle = curl_init($url);
    if ($handle === false) throw new RuntimeException('Could not initialize the AI provider request.');
    curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>90,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERAGENT=>'Gelato-Restaurant-Recipe-Intelligence/1.0']);
    $body=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);$error=curl_error($handle);curl_close($handle);
    if(!is_string($body))throw new RuntimeException($error!==''?'The AI provider connection failed.':'The AI provider returned no response.');
    $decoded=json_decode($body,true);if(!is_array($decoded))throw new RuntimeException('The AI provider returned invalid JSON.');
    if($status<200||$status>=300){$message=(string)($decoded['error']['message']??'The AI provider could not complete the recipe mapping request.');throw new RuntimeException(mb_substr($message,0,500,'UTF-8'));}
    return $decoded;
}

function recipe_intelligence_output_text(array $response): string
{
    if(isset($response['output_text'])&&is_string($response['output_text']))return trim($response['output_text']);$parts=[];foreach((array)($response['output']??[]) as $item){foreach((array)($item['content']??[]) as $content){if(($content['type']??'')==='output_text'&&isset($content['text']))$parts[]=(string)$content['text'];}}return trim(implode("\n",$parts));
}

function recipe_intelligence_decode_json(string $text): array
{
    $text=trim($text);$text=preg_replace('/^```(?:json)?\s*/i','',$text)??$text;$text=preg_replace('/\s*```$/','',$text)??$text;$decoded=json_decode($text,true);if(is_array($decoded))return $decoded;if(preg_match('/\{.*\}/s',$text,$match)){$decoded=json_decode($match[0],true);if(is_array($decoded))return $decoded;}throw new RuntimeException('The AI provider did not return a usable structured recipe mapping.');
}

function recipe_intelligence_safe_external_url(?string $url): ?string
{
    $url=trim((string)$url);if($url===''||strlen($url)>1000||!filter_var($url,FILTER_VALIDATE_URL))return null;$parts=parse_url($url);if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https')return null;$host=strtolower((string)($parts['host']??''));if($host===''||$host==='localhost'||str_ends_with($host,'.local'))return null;if(filter_var($host,FILTER_VALIDATE_IP)){if(filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false)return null;}return $url;
}

function recipe_intelligence_normalize(array $data,string $provider):array
{
    $ingredients=[];foreach((array)($data['ingredients']??[]) as $item){if(is_string($item)){$ingredients[]=['quantity'=>'','unit'=>'','ingredient'=>trim($item),'notes'=>''];continue;}if(!is_array($item))continue;$ingredients[]=['quantity'=>mb_substr(trim((string)($item['quantity']??'')),0,80,'UTF-8'),'unit'=>mb_substr(trim((string)($item['unit']??'')),0,80,'UTF-8'),'ingredient'=>mb_substr(trim((string)($item['ingredient']??$item['name']??'')),0,300,'UTF-8'),'notes'=>mb_substr(trim((string)($item['notes']??'')),0,300,'UTF-8')];}
    $instructions=[];foreach((array)($data['instructions']??[]) as $item){$text=is_array($item)?(string)($item['text']??''):(string)$item;$text=trim($text);if($text!=='')$instructions[]=mb_substr($text,0,2000,'UTF-8');}
    $match=is_array($data['online_match']??null)?$data['online_match']:[];$url=recipe_intelligence_safe_external_url((string)($match['url']??''));$confidence=max(0.0,min(1.0,(float)($match['confidence']??0)));$domain=$url?(string)(parse_url($url,PHP_URL_HOST)?:''):'';$alternates=[];foreach((array)($data['alternates']??[]) as $alt){if(!is_array($alt))continue;$altUrl=recipe_intelligence_safe_external_url((string)($alt['url']??''));if(!$altUrl)continue;$alternates[]=['title'=>mb_substr(trim((string)($alt['title']??'')),0,500,'UTF-8'),'url'=>$altUrl,'domain'=>(string)(parse_url($altUrl,PHP_URL_HOST)?:''),'confidence'=>max(0.0,min(1.0,(float)($alt['confidence']??0)))];if(count($alternates)>=5)break;}
    return ['provider'=>$provider,'recipeName'=>mb_substr(trim((string)($data['recipe_name']??'')),0,220,'UTF-8'),'category'=>mb_substr(trim((string)($data['category']??'')),0,100,'UTF-8'),'description'=>mb_substr(trim((string)($data['description']??'')),0,3000,'UTF-8'),'yieldQuantity'=>isset($data['yield_quantity'])&&is_numeric($data['yield_quantity'])?(float)$data['yield_quantity']:null,'yieldUnit'=>mb_substr(trim((string)($data['yield_unit']??'')),0,80,'UTF-8'),'ingredients'=>$ingredients,'instructions'=>$instructions,'extractedText'=>mb_substr(trim((string)($data['extracted_text']??'')),0,30000,'UTF-8'),'mappingNotes'=>mb_substr(trim((string)($data['mapping_notes']??$match['why']??'')),0,4000,'UTF-8'),'onlineMatch'=>['title'=>mb_substr(trim((string)($match['title']??'')),0,500,'UTF-8'),'url'=>$url,'domain'=>$domain,'confidence'=>$confidence,'why'=>mb_substr(trim((string)($match['why']??'')),0,2000,'UTF-8')],'alternates'=>$alternates];
}

function recipe_intelligence_openai(string $apiKey,string $mime,string $bytes,string $recipeName=''):array
{
    $prompt="You are the private recipe intelligence tool for a restaurant. Analyze the uploaded image. It may be a handwritten recipe card, printed recipe, cookbook page, prep sheet, or photo of a finished dish. Extract only what is reasonably visible or inferable. Then use web search to find the closest credible online recipe that matches the image and extracted recipe. Prefer established recipe publishers, manufacturers, culinary publications, or recognizable food sites. Do not claim an exact match unless the ingredients/method strongly align. Return JSON only with keys: recipe_name, category, description, yield_quantity, yield_unit, ingredients (array of objects quantity, unit, ingredient, notes), instructions (array of strings), extracted_text, mapping_notes, online_match (object title,url,confidence,why), alternates (array of title,url,confidence). Confidence must be 0 to 1. Use HTTPS source URLs. If no credible match is found, use an empty URL and explain why.";if($recipeName!=='')$prompt.=" Existing restaurant recipe name: ".$recipeName.".";
    $response=recipe_intelligence_http_json('https://api.openai.com/v1/responses',['Authorization: Bearer '.$apiKey,'Content-Type: application/json'],['model'=>'gpt-5.6-luna','store'=>false,'tools'=>[['type'=>'web_search']],'input'=>[['role'=>'user','content'=>[['type'=>'input_text','text'=>$prompt],['type'=>'input_image','image_url'=>'data:'.$mime.';base64,'.base64_encode($bytes)]]]],'max_output_tokens'=>3000]);
    return recipe_intelligence_normalize(recipe_intelligence_decode_json(recipe_intelligence_output_text($response)),'openai');
}

function recipe_intelligence_anthropic(string $apiKey,string $mime,string $bytes,string $recipeName=''):array
{
    $prompt="Analyze this restaurant recipe image and return JSON only with keys recipe_name, category, description, yield_quantity, yield_unit, ingredients (quantity, unit, ingredient, notes), instructions, extracted_text, mapping_notes, online_match, alternates. You do not have a live web-search tool in this workflow, so online_match.url must be empty. Extract the image accurately and provide a concise mapping_notes field describing what should be searched online.";if($recipeName!=='')$prompt.=" Existing restaurant recipe name: ".$recipeName.".";
    $response=recipe_intelligence_http_json('https://api.anthropic.com/v1/messages',['x-api-key: '.$apiKey,'anthropic-version: 2023-06-01','Content-Type: application/json'],['model'=>'claude-sonnet-4-20250514','max_tokens'=>3000,'temperature'=>0.1,'messages'=>[['role'=>'user','content'=>[['type'=>'image','source'=>['type'=>'base64','media_type'=>$mime,'data'=>base64_encode($bytes)]],['type'=>'text','text'=>$prompt]]]]]);$parts=[];foreach((array)($response['content']??[]) as $content){if(($content['type']??'')==='text')$parts[]=(string)($content['text']??'');}return recipe_intelligence_normalize(recipe_intelligence_decode_json(implode("\n",$parts)),'anthropic');
}

function recipe_intelligence_map(PDO $pdo,int $organizationId,string $mime,string $bytes,string $recipeName=''):array
{
    $credential=recipe_intelligence_credential($pdo,$organizationId,'openai');if($credential){$apiKey=app_decrypt_secret((string)$credential['encrypted_key'],(string)$credential['nonce'],(string)$credential['encryption_method']);try{return recipe_intelligence_openai($apiKey,$mime,$bytes,$recipeName);}finally{if(function_exists('sodium_memzero'))sodium_memzero($apiKey);}}
    $credential=recipe_intelligence_credential($pdo,$organizationId,'anthropic');if($credential){$apiKey=app_decrypt_secret((string)$credential['encrypted_key'],(string)$credential['nonce'],(string)$credential['encryption_method']);try{return recipe_intelligence_anthropic($apiKey,$mime,$bytes,$recipeName);}finally{if(function_exists('sodium_memzero'))sodium_memzero($apiKey);}}
    throw new RuntimeException('Configure an OpenAI or Anthropic API key before using recipe image intelligence. OpenAI is preferred because it can map the image to a live online recipe with web search.');
}
