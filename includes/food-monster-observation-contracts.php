<?php
declare(strict_types=1);

/**
 * AI-01: server-owned, encoded-JSON observation contracts.
 *
 * This is deliberately an allowlist of actual reads, not an impact=read
 * shortcut: proposal, delegation and pending-action tools also use that
 * impact. Their outcomes must never be shortened after execution. Unknown
 * tools retain the original 3 KiB contract. No limit comes from the model.
 */
function fm8do_contracts(): array
{
    static $contracts=null;
    if($contracts!==null)return $contracts;
    $contracts=[];
    $add=static function(array $names,int $bytes,string $url,string $label,array $collections=[],bool $bounded=false)use(&$contracts):void{
        foreach($names as $name)$contracts[$name]=[
            'maxBytes'=>$bytes,'answerBytes'=>$bounded?$bytes:3000,
            'url'=>$url,'label'=>$label,'collections'=>$collections,
            'compact'=>!$bounded,'singleTurn'=>$bounded&&$name!=='list_my_gelato_capabilities',
        ];
    };
    $add(['get_current_user'],3072,'agent-canvas.php','Agent Brain');
    $add(['get_my_hours'],3072,'timeclock.php','Time Clock');
    $add(['get_gelato_license_status','get_gelato_update_status'],3072,'license-updates.php','License & Updates');
    $add(['get_my_schedule','get_employee_schedule','get_day_roster','check_staff_availability'],4096,'scheduling.php','Staff Scheduling');
    $add(['list_available_menu_items','get_menu_item','search_menu_ingredients'],4096,'menu-manager.php','Menu Manager');
    $add(['get_recipe_details','find_recipes_using_ingredient'],4096,'recipes.php','Recipe Library');
    $add(['get_my_training_progress','get_team_training_insights'],4096,'agent-canvas.php','Agent Brain training');
    $add(['get_vision_lab_overview','get_vision_device_health','get_vision_model_rollouts',
        'get_recent_vision_observations','get_recent_product_vision_checks','get_vision_fleet_analyses',
        'list_vision_training_missions','list_vision_dataset_versions','get_vision_sample_review_queue',
        'get_vision_sample_review_details','get_vision_annotation_quality','get_vision_release_candidate_queue'],
        4096,'glasses-vision-lab.php','Vision Lab');
    $add(['read_gelato_records'],4096,'agent-canvas.php','Agent Brain');
    $add(['get_my_availability'],6144,'scheduling.php','Staff Scheduling',['recurring','exceptions']);
    $add(['search_crm_customers'],6144,'customer-crm.php','Customer CRM',['matches']);
    $add(['read_crm_customer_profile'],6144,'customer-crm.php','Customer CRM',['records']);
    $add(['search_marketing_packages'],6144,'packages-admin.php','Package Deals',['matches']);
    $add(['search_catering_operations'],6144,'catering-operations.php','Catering Operations',['matches']);
    $add(['read_catering_operation'],6144,'catering-operations.php','Catering Operations',['records']);
    $add(['search_wholesale_orders'],6144,'wholesale-fulfillment.php','Wholesale Fulfillment',['matches']);
    $add(['read_wholesale_order','read_wholesale_batch'],6144,'wholesale-fulfillment.php','Wholesale Fulfillment',['records']);
    $add(['search_wholesale_quotes'],6144,'wholesale-pipeline.php','Wholesale Quotes',['matches']);
    $add(['read_wholesale_quote','read_wholesale_order_lifecycle'],6144,'wholesale-pipeline.php','Wholesale Orders',['records']);
    $add(['search_wholesale_invoices'],6144,'wholesale-receivables.php','Wholesale Receivables',['matches']);
    $add(['read_wholesale_invoice'],6144,'wholesale-receivables.php','Wholesale Receivables',['records']);
    $add(['get_wholesale_receivables_aging'],4096,'wholesale-receivables.php','Wholesale Receivables');
    $add(['get_prep_shortages'],6144,'prep-intelligence.php','Prep Intelligence',['items']);
    // These producers budget complete displayed rows before saving numbered
    // selections. The executor must not cut or renumber their answers.
    $add(['list_my_gelato_capabilities'],8192,'agent-canvas.php','Agent Brain capability details',[],true);
    $add(['get_daily_prep_checklist'],12288,'daily-prep.php','Daily Prep',[],true);
    $add(['get_need_more_receipts'],8192,'daily-prep.php','Daily Prep receipts',[],true);
    $add(['get_need_more_vendor_options'],16384,'need-more-purchasing.php','Need More Purchasing',[],true);
    $add(['get_need_more_stock_insights'],8192,'daily-prep.php','Daily Prep and Need More',[],true);
    // Stock insights have no numbered selection and may join other reads.
    $contracts['get_need_more_stock_insights']['singleTurn']=false;
    $contracts['get_need_more_stock_insights']['answerBytes']=3000;
    $contracts['list_my_gelato_capabilities']['answerBytes']=3000;
    $contracts['get_daily_prep_checklist']['answerBytes']=11000;
    $contracts['get_need_more_receipts']['answerBytes']=7000;
    $contracts['get_need_more_vendor_options']['answerBytes']=10000;
    return $contracts;
}

function fm8do_limit(string $tool,string $impact='read'): int
{
    return $impact==='read'?(fm8do_contracts()[$tool]['maxBytes']??3072):3072;
}

/** One encoding is used by the executor, producers and durable checkpoints. */
function fm8do_json(array $data): string
{
    // Invalid UTF-8, non-finite numbers, objects and recursive/deep arrays are
    // invalid observations, not candidates for a successful compact result.
    $plain=static function(mixed $value,int $depth=0)use(&$plain):bool{
        if($depth>24)return false;
        if(is_array($value)){
            foreach($value as $entry)if(!$plain($entry,$depth+1))return false;
            return true;
        }
        return $value===null||is_string($value)||is_int($value)||is_bool($value)
            ||is_float($value)&&is_finite($value);
    };
    if(!$plain($data))throw new DomainException('Observation contains unsupported values.');
    return json_encode($data,JSON_THROW_ON_ERROR,32);
}

/** Cut valid UTF-8 by encoded string bytes, including JSON quoting/escaping. */
function fm8do_text(string $text,int $bytes,string $suffix=''): string
{
    $encode=static fn(string $value):int=>strlen(json_encode($value,JSON_THROW_ON_ERROR));
    if($encode($text)<=$bytes)return $text;
    if($encode($suffix)>$bytes)throw new DomainException('Observation summary suffix exceeds its budget.');
    $low=0;$high=strlen($text);$best='';
    while($low<=$high){
        $mid=intdiv($low+$high,2);
        $prefix=substr($text,0,$mid);
        // A partial multibyte character is removed, never substituted.
        while($prefix!==''&&preg_match('//u',$prefix)!==1)$prefix=substr($prefix,0,-1);
        if($encode($prefix.$suffix)<=$bytes){$best=$prefix;$low=$mid+1;}
        else $high=$mid-1;
    }
    return rtrim($best).$suffix;
}

function fm8do_fits(string $tool,array $data,string $impact='read'): bool
{
    try{
        if(strlen(fm8do_json($data))>fm8do_limit($tool,$impact))return false;
        $contract=$impact==='read'?(fm8do_contracts()[$tool]??null):null;
        return $contract===null||(is_string($data['answer']??null)
            &&is_string($data['source']??null)
            &&strlen(json_encode($data['answer'],JSON_THROW_ON_ERROR))<=$contract['answerBytes']);
    }catch(Throwable){return false;}
}

/**
 * Compact only reviewed pure reads. Keep complete collection rows and their
 * order; record counts mean rows retrieved by the adapter, not an invented
 * total database count. Unlisted fields and action outcomes are never cut.
 */
function fm8do_prepare(string $tool,string $impact,array $data): ?array
{
    try{$original=fm8do_json($data);}catch(Throwable){return null;}
    $contract=$impact==='read'?(fm8do_contracts()[$tool]??null):null;
    if($contract===null)return strlen($original)<=3072?$data:null;
    if(!is_string($data['answer']??null)||!is_string($data['source']??null))return null;
    if(fm8do_fits($tool,$data,$impact))return $data;
    if(!$contract['compact'])return null;
    foreach(['approval','delegation','navigation','requiresConfirmation','proposalId','pendingProposal','mutation','observationSummary'] as $key)
        if(array_key_exists($key,$data))return null;
    if(isset($data['proof'])&&(!is_array($data['proof'])||strlen(fm8do_json($data['proof']))>256))return null;
    $counts=[];$listShapes=[];
    foreach($contract['collections'] as $field){
        if(!isset($data[$field])||!is_array($data[$field]))continue;
        $listShapes[$field]=array_is_list($data[$field]);
        $count=$listShapes[$field]?count($data[$field]):1;
        $counts[$field]=['retrieved'=>$count,'included'=>$count,'kind'=>$listShapes[$field]?'rows':'aggregate_record'];
    }
    $fullAnswer=$data['answer'];
    $data['truncated']=true;
    $data['proof']=['url'=>$contract['url']];
    $data['observationSummary']=['answerTruncated'=>false,
        'collections'=>$counts,'detailsUrl'=>$contract['url']];
    // Retain only complete prefixes of declared collections. Halving the
    // largest collection bounds the work even for thousands of forecast
    // rows; never repeatedly encode an almost-full result one row at a time.
    while(true){
        $omitted=[];
        foreach($data['observationSummary']['collections'] as $field=>$count)
            if($count['included']<$count['retrieved'])$omitted[]=$field.': '.$count['retrieved'].' retrieved, '.$count['included'].' included';
        $suffix=$omitted?' [Read detail shortened ('.implode('; ',$omitted).'). Open '.$contract['label'].' for complete details.]'
            :' [Brief summary; additional record details may be omitted. Open '.$contract['label'].' for complete details.]';
        $message=$fullAnswer.($omitted?$suffix:'');
        $data['answer']=fm8do_text($message,$contract['answerBytes'],$suffix);
        $data['observationSummary']['answerTruncated']=$data['answer']!==$message;
        if(fm8do_fits($tool,$data,$impact))return $data;
        $largest=null;$largestBytes=0;
        foreach(array_keys($counts) as $field){
            if(!$data[$field])continue;
            $bytes=strlen(fm8do_json($data[$field]));
            if($bytes>$largestBytes){$largest=$field;$largestBytes=$bytes;}
        }
        if($largest===null){
            // Preserve a long legitimate name/identifier verbatim and use
            // the remaining space for prose. Unknown large fields still
            // fail when they do not fit even with an empty answer.
            $empty=$data;$empty['answer']='';
            $remaining=$contract['maxBytes']-strlen(fm8do_json($empty))+2;
            if($remaining<strlen(json_encode($suffix,JSON_THROW_ON_ERROR)))return null;
            $data['answer']=fm8do_text($message,min($remaining,$contract['answerBytes']),$suffix);
            $data['observationSummary']['answerTruncated']=$data['answer']!==$message;
            return fm8do_fits($tool,$data,$impact)?$data:null;
        }
        $data[$largest]=$listShapes[$largest]
            ?array_slice($data[$largest],0,intdiv(count($data[$largest]),2)):[];
        $data['observationSummary']['collections'][$largest]['included']=$listShapes[$largest]?count($data[$largest]):0;
    }
}
