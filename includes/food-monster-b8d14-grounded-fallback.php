<?php
declare(strict_types=1);

/**
 * FM-B8D14: one bounded cognitive retry after an LLM selected no tools.
 * The model, not a phrase classifier, decides whether the question needs an
 * authorized restaurant read. A retry can never authorize or perform writes.
 * No database observations are forwarded to either model request.
 *
 * @return array<int,array{name:mixed,arguments:mixed,callId:mixed}>
 */
function fm8d14_cognitive_read_retry(
    string $provider,string $key,array $originalPayload,array $catalogue,
    ?callable $transport=null
):array{
    $readTools=array_filter($catalogue,
        static fn(array $tool):bool=>($tool['impact']??'')==='read');
    if(!$readTools)return [];
    $noRead='answer_without_restaurant_tools';
    if(isset($readTools[$noRead]))throw new LogicException('Reserved cognitive tool name.');
    $decision=$readTools;
    $decision[$noRead]=[
        'description'=>'Choose this ONLY when the user asked for general conversation, explanation, or another question that NONE of the available Gelato read tools can answer. This performs no action. Never choose this because the menu, schedule, recipe, or other tool data has not yet been fetched: choose the actual authorized read tool instead.',
        'parameters'=>['type'=>'object','properties'=>new stdClass(),
            'additionalProperties'=>false],
    ];
    $retry=$originalPayload;
    $retry['tools']=fm8d3_provider_tools($provider,$decision);
    $retry['tool_choice']=$provider==='openai'?'required':['type'=>'any'];
    $retryInstruction=' This is the single final tool-decision retry for the same user message. '
        .'Choose an authorized Gelato READ tool whenever the question asks for recorded restaurant facts, '
        .'including broad menu listings without specific item names. '
        .'Otherwise choose answer_without_restaurant_tools for ordinary conversation. '
        .'Return only tool selections; no prose, no proposed writes and no guessed business facts. '
        .'Do not ask the user to rephrase a question just to access the available read tools.';
    if($provider==='openai')$retry['instructions'].=$retryInstruction;
    else $retry['system'].=$retryInstruction;
    $response=fm8d3_provider_request($provider,$key,$retry,$transport);
    $calls=fm8d3_decode_calls($provider,$response);
    if(!$calls||count($calls)>4)return [];
    foreach($calls as $call){
        if(($call['name']??'')===$noRead)return [];
        if(!is_string($call['name']??null)||!isset($readTools[$call['name']])
            ||!is_array($call['arguments']??null))return [];
    }
    return $calls;
}
