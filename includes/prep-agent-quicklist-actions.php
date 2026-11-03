<?php
declare(strict_types=1);
require_once __DIR__.'/daily-quicklist-receipts.php';

/**
 * Section 3K2C: confirmation-only Daily Prep and Need More actions.
 * Loaded by the official Prep Agent; never called as a separate unauthenticated API.
 * The user-facing name is resolved to a current, tenant-scoped row before proposal.
 */
/**
 * Server-owned conversational selection. Numbered items are never supplied
 * by the LLM, and a short-lived snapshot cannot select a different tenant.
 */
function pqa_context_key(int $org,int $uid,string $threadId=''): string
{
    return $org.':'.$uid.':'.($threadId===''?'direct':substr(hash('sha256',$threadId),0,24));
}
function pqa_context_get(int $org,int $uid,string $threadId=''): ?array
{
    if(session_status()!==PHP_SESSION_ACTIVE)return null;
    $key=pqa_context_key($org,$uid,$threadId);
    $row=$_SESSION['prep_quicklist_selection'][$key]??null;
    if(!is_array($row)||(int)($row['expires']??0)<time()
        ||(int)($row['org']??0)!==$org||(int)($row['user']??0)!==$uid){
        unset($_SESSION['prep_quicklist_selection'][$key]);
        return null;
    }
    return $row;
}
function pqa_context_save(int $org,int $uid,string $date,array $rows,string $threadId=''): array
{
    if(session_status()!==PHP_SESSION_ACTIVE)throw new RuntimeException('A secure Agent Chat session is required for checklist context.');
    $date=daily_quicklist_date($date);
    $lists=['prep'=>[],'inventory'=>[]];
    foreach($rows as $row){
        $kind=(string)($row['type']??'');
        if(!isset($lists[$kind]))continue;
        if($kind==='inventory'&&$row['status']==='received')continue;
        if($kind==='prep'&&$row['date']!==$date)continue;
        if(count($lists[$kind])>=20)continue;
        $lists[$kind][]=['id'=>(string)$row['id'],'title'=>(string)$row['title'],
            'fingerprint'=>pqa_item_fingerprint($row),'status'=>(string)$row['status']];
    }
    $prior=pqa_context_get($org,$uid,$threadId);
    $focus=$prior['focus']??null;
    if($focus){
        $present=false;
        foreach($lists[$focus['kind']]??[] as $item)
            if($item['id']===$focus['id']){$present=true;break;}
        if(!$present)$focus=null;
    }
    $context=['org'=>$org,'user'=>$uid,'date'=>$date,'expires'=>time()+900,
        'lists'=>$lists,'focus'=>$focus];
    $_SESSION['prep_quicklist_selection'][pqa_context_key($org,$uid,$threadId)]=$context;
    return $context;
}
function pqa_context_focus(int $org,int $uid,string $threadId,string $kind,string $id): void
{
    $row=pqa_context_get($org,$uid,$threadId);
    if(!$row)return;
    foreach($row['lists'][$kind]??[] as $item)if($item['id']===$id){
        $row['focus']=['kind'=>$kind,'id'=>$id];
        $_SESSION['prep_quicklist_selection'][pqa_context_key($org,$uid,$threadId)]=$row;
        return;
    }
}
/** Resolve only a displayed ordinal or an explicitly established focus. */
function pqa_context_resolve(PDO $pdo,int $org,int $uid,string $threadId,string $date,
    string $kind,string $mode,int $ordinal=0): array
{
    $saved=pqa_context_get($org,$uid,$threadId);
    if(!$saved||$saved['date']!==$date)
        throw new InvalidArgumentException('Please ask Gelato AI to show this dated Daily Prep list first.');
    $selection=null;
    if($mode==='ordinal'){
        $selection=$saved['lists'][$kind][$ordinal-1]??null;
        if(!$selection)throw new InvalidArgumentException('That number was not in the displayed '.($kind==='prep'?'prep':'Need More').' list.');
    }elseif($mode==='focused'){
        $focus=$saved['focus']??null;
        if($focus&&$focus['kind']===$kind){
            foreach($saved['lists'][$kind]??[] as $row)if($row['id']===$focus['id']){$selection=$row;break;}
        }
        if(!$selection&&count($saved['lists'][$kind]??[])===1)$selection=$saved['lists'][$kind][0];
        if(!$selection)throw new InvalidArgumentException('Which item do you mean? Say its name or a number from the displayed list.');
    }else throw new InvalidArgumentException('Use a name or a numbered checklist reference.');
    $rows=daily_quicklist_items($pdo,$org,$date);
    foreach($rows as $live){
        if($live['id']===$selection['id']&&$live['type']===$kind){
            if($mode==='ordinal'&&!hash_equals($selection['fingerprint'],pqa_item_fingerprint($live)))
                throw new InvalidArgumentException('The numbered checklist changed. Show the list again before selecting by number.');
            return $live;
        }
    }
    throw new InvalidArgumentException('That checklist item is no longer available. Show the list again.');
}
/** Numbered RECEIPT references are session/tenant/user/thread/item bound. */
function pqa_receipt_context_key(int $org,int $uid,string $threadId): string
{
    return pqa_context_key($org,$uid,$threadId);
}
function pqa_receipt_context_save(int $org,int $uid,string $threadId,string $itemId,array $candidates): void
{
    if(session_status()!==PHP_SESSION_ACTIVE)throw new RuntimeException('A secure Agent Chat session is required.');
    $_SESSION['prep_receipt_selection'][pqa_receipt_context_key($org,$uid,$threadId)]=[
        'org'=>$org,'user'=>$uid,'itemId'=>$itemId,'expires'=>time()+900,
        'receipts'=>array_values(array_map(static fn(array $row):int=>(int)$row['id'],array_slice($candidates,0,20)))];
}
function pqa_receipt_context_resolve(int $org,int $uid,string $threadId,string $itemId,int $number): int
{
    if(session_status()!==PHP_SESSION_ACTIVE)throw new InvalidArgumentException('Show the recorded receipts first.');
    $key=pqa_receipt_context_key($org,$uid,$threadId);
    $ctx=$_SESSION['prep_receipt_selection'][$key]??null;
    if(!is_array($ctx)||$ctx['org']!==$org||$ctx['user']!==$uid
        ||$ctx['itemId']!==$itemId||$ctx['expires']<time()
        ||$number<1||$number>20||!isset($ctx['receipts'][$number-1]))
        throw new InvalidArgumentException('Show the eligible recorded receipts for this Need More item first, then choose a number.');
    return (int)$ctx['receipts'][$number-1];
}
function pqa_numbered_brief(array $context): string
{
    $lines=[];
    foreach(['prep'=>'Daily Prep','inventory'=>'Need More'] as $kind=>$label){
        $items=$context['lists'][$kind];
        if(!$items){$lines[]=$label.': no active items.';continue;}
        $parts=[];
        foreach($items as $index=>$item)$parts[]=($index+1).'. '.$item['title'].' ('.str_replace('_',' ',$item['status']).')';
        $lines[]=$label.': '.implode('; ',$parts).'.';
        if(count($items)===20)$lines[]='Showing only the first 20 '.$label.' items; open Daily Prep for the full list.';
    }
    return implode(' ',$lines);
}

function pqa_parse_request(string $message): ?array
{
    $text=trim(preg_replace('/\s+/u',' ',$message)??$message);
    $p='(?:daily\s+prep(?:\s+(?:list|checklist))?|prep\s+checklist)';
    $n='need\s+more(?:\s+(?:inventory\s+)?list)?';
    $c='(?:on|in)\s+(?:the\s+)?';
    if(preg_match('~^(?:please\s+)?(?:add|put|request)\s+(.+?)\s+(?:to|on)\s+(?:the\s+)?'.$n.'(?:\s*:\s*(.+))?[.!]?$~iu',$text,$m))
        return ['op'=>'add_need_more','kind'=>'inventory','target'=>trim($m[1]),'note'=>trim($m[2]??'')];
    if(preg_match('~^(?:please\s+)?(?:remove|delete|archive)\s+(.+?)\s+from\s+(?:the\s+)?'.$n.'[.!]?$~iu',$text,$m))
        return ['op'=>'archive','kind'=>'inventory','target'=>trim($m[1])];
    if(preg_match('~^(?:please\s+)?(?:mark|set)\s+(.+?)\s+(?:as\s+)?(ordered|received|need more|need_more)\s+'.$c.$n.'[.!]?$~iu',$text,$m))
        return ['op'=>'update','kind'=>'inventory','target'=>trim($m[1]),'status'=>str_replace(' ','_',mb_strtolower($m[2],'UTF-8'))];
    if(preg_match('~^(?:please\s+)?(?:add|set|update)\s+(?:manager\s+)?note\s+(?:for|on)\s+(.+?)\s+'.$c.$n.'\s*:\s*(.+)$~iu',$text,$m))
        return ['op'=>'update','kind'=>'inventory','target'=>trim($m[1]),'note'=>trim($m[2])];
    if(preg_match('~^(?:please\s+)?(?:set|change|update)\s+(?:requested\s+)?(?:amount|quantity)\s+(?:for|on)\s+(.+?)\s+'.$c.$n.'\s*:\s*(.*)$~iu',$text,$m))
        return ['op'=>'update','kind'=>'inventory','target'=>trim($m[1]),'quantityNote'=>trim($m[2])];
    if(preg_match('~^(?:please\s+)?notify\s+(?:the\s+)?(?:team|managers?)\s+(?:about|regarding)\s+(.+?)\s+'.$c.$n.'[.!]?$~iu',$text,$m))
        return ['op'=>'notify','kind'=>'inventory','target'=>trim($m[1])];

    if(preg_match('~^(?:please\s+)?(?:set|change)\s+(.+?)\s+(?:batch\s+)?(?:target|yield)\s+(?:to|at)\s+(\d+(?:\.\d+)?)\s*([a-zA-Z]+)?\s+'.$c.$p.'[.!]?$~iu',$text,$m))
        return ['op'=>'set_target','kind'=>'prep','target'=>trim($m[1]),'quantity'=>(float)$m[2],'unit'=>trim($m[3]??'')];
    if(preg_match('~^(?:please\s+)?(start|pause|resume|stop)\s+(?:the\s+)?timer\s+(?:for|on)\s+(.+?)\s+'.$c.$p.'[.!]?$~iu',$text,$m))
        return ['op'=>'timer','kind'=>'prep','target'=>trim($m[2]),'mode'=>mb_strtolower($m[1],'UTF-8')];
    if(preg_match('~^(?:please\s+)?(start|pause|resume|stop)\s+(.+?)\s+timer\s+'.$c.$p.'[.!]?$~iu',$text,$m))
        return ['op'=>'timer','kind'=>'prep','target'=>trim($m[2]),'mode'=>mb_strtolower($m[1],'UTF-8')];
    if(preg_match('~^(?:please\s+)?(?:mark|set)\s+(.+?)\s+(?:as\s+)?(done|complete|completed|todo|to do|not done|unfinished|in progress|paused)\s+'.$c.$p.'[.!]?$~iu',$text,$m)){
        $s=mb_strtolower($m[2],'UTF-8');
        $status=in_array($s,['done','complete','completed'],true)?'done':
            (in_array($s,['todo','to do','not done','unfinished'],true)?'todo':str_replace(' ','_',$s));
        return ['op'=>in_array($status,['done','todo'],true)?'toggle':'update',
            'kind'=>'prep','target'=>trim($m[1]),'status'=>$status];
    }
    if(preg_match('~^(?:please\s+)?(?:check\s+off|complete|finish)\s+(.+?)\s+'.$c.$p.'[.!]?$~iu',$text,$m))
        return ['op'=>'toggle','kind'=>'prep','target'=>trim($m[1]),'status'=>'done'];
    if(preg_match('~^(?:please\s+)?(?:uncheck|undo|reopen)\s+(.+?)\s+'.$c.$p.'[.!]?$~iu',$text,$m))
        return ['op'=>'toggle','kind'=>'prep','target'=>trim($m[1]),'status'=>'todo'];
    if(preg_match('~^(?:please\s+)?(?:remove|delete|archive)\s+(.+?)\s+from\s+(?:the\s+)?'.$p.'[.!]?$~iu',$text,$m))
        return ['op'=>'archive','kind'=>'prep','target'=>trim($m[1])];
    if(preg_match('~^(?:please\s+)?(?:add|set|update)\s+(?:manager\s+)?note\s+(?:for|on)\s+(.+?)\s+'.$c.$p.'\s*:\s*(.+)$~iu',$text,$m))
        return ['op'=>'update','kind'=>'prep','target'=>trim($m[1]),'note'=>trim($m[2])];
    if(preg_match('~^(?:please\s+)?notify\s+(?:the\s+)?(?:team|managers?)\s+(?:about|regarding)\s+(.+?)\s+'.$c.$p.'[.!]?$~iu',$text,$m))
        return ['op'=>'notify','kind'=>'prep','target'=>trim($m[1])];
    return null;
}

function pqa_resolve_item(PDO $pdo,int $org,string $date,string $kind,string $target): array
{
    $key=daily_quicklist_normalize($target);
    if($key===''||mb_strlen($key,'UTF-8')>220)throw new InvalidArgumentException('Name a Daily Prep or Need More item.');
    $rows=array_values(array_filter(daily_quicklist_items($pdo,$org,daily_quicklist_date($date)),
        static fn(array $r):bool=>$r['type']===$kind));
    // A received shortage can remain visible for history while a new active
    // shortage exists for the same stock item. Select only active requests
    // when possible; never silently choose between two active duplicates.
    if($kind==='inventory'){
        $active=array_values(array_filter($rows,static function(array $r)use($key):bool{
            return $r['status']!=='received'
                &&str_contains(daily_quicklist_normalize((string)$r['title']),$key);
        }));
        if($active)$rows=$active;
    }
    $exact=array_values(array_filter($rows,static function(array $r)use($key):bool{
        $title=daily_quicklist_normalize((string)$r['title']);
        return $title===$key||($r['type']==='prep'&&preg_replace('/^prep\s+/u','',$title)===$key);
    }));
    if(count($exact)===1)return $exact[0];
    if(count($exact)>1)throw new InvalidArgumentException('More than one item matches. Use its full name in Daily Prep.');
    $partial=array_values(array_filter($rows,static function(array $r)use($key):bool{
        return str_contains(daily_quicklist_normalize((string)$r['title']),$key);
    }));
    if(count($partial)===1)return $partial[0];
    if(count($partial)>1)throw new InvalidArgumentException('Several checklist items match. Use a more specific name.');
    throw new InvalidArgumentException('That item is not on the current list. Open Daily Prep to check the name or date.');
}

/** Wall-clock elapsed time changes naturally, so it is not part of stale-action evidence. */
function pqa_item_fingerprint(array $row): string
{
    $evidence=[];
    foreach(['id','type','date','status','recipeId','targetYieldQuantity','targetYieldUnit','timerRunning','adminNote','quantityNote','requestedStockQuantity','updatedAt'] as $field)
        $evidence[$field]=$row[$field]??null;
    return hash('sha256',json_encode($evidence,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}

function pqa_require_action_permissions(array $user,array $intent): void
{
    pac_require($user,'prep.intelligence.view','Prep Intelligence view permission is required.');
    pac_require($user,'prep.intelligence.agent','Prep Agent permission is required.');
    if(!daily_quicklist_can_view($user))throw new PrepAgentPermissionException('Daily Prep staff access is required.');
    if(in_array($intent['op'],['update','notify','reconcile_receipt'],true)&&!daily_quicklist_can_manage($user))
        throw new PrepAgentPermissionException('Only managers can change checklist statuses, manager notes, or notifications.');
    if($intent['op']==='update'&&array_key_exists('requestedStockQuantity',$intent)
        &&!daily_quicklist_receipt_permission($user))
        throw new PrepAgentPermissionException('Manager and Inventory View permissions are required for numeric Need More targets.');
    if($intent['op']==='reconcile_receipt'&&!daily_quicklist_receipt_permission($user))
        throw new PrepAgentPermissionException('Manager and Inventory View permissions are required to reconcile receipts.');
    if($intent['op']==='set_target')
        pac_require($user,'recipes.view','Recipe Library view permission is required for batch targets.');
}

function pqa_current_recipe(PDO $pdo,int $org,string $recipeId): array
{
    $q=$pdo->prepare("SELECT yield_quantity,yield_unit FROM recipes WHERE organization_id=? AND public_id=? AND status='active' AND archived_at IS NULL LIMIT 1");
    $q->execute([$org,$recipeId]);$r=$q->fetch();
    if(!$r||$r['yield_quantity']===null||(float)$r['yield_quantity']<=0)
        throw new InvalidArgumentException('The linked recipe needs a valid canonical yield.');
    return $r;
}

function pqa_propose(PDO $pdo,int $org,int $uid,array $user,array $intent,string $date): array
{
    pqa_require_action_permissions($user,$intent);
    if(!daily_quicklist_ready($pdo))throw new InvalidArgumentException('Daily Prep is not installed. Run upgrade.php.');
    $date=daily_quicklist_date($date);$op=$intent['op'];$kind=$intent['kind'];
    $payload=['intent'=>$intent,'date'=>$date];$title=$intent['target'];
    if($op==='add_need_more'){
        $title=daily_quicklist_title($title);
        $note=trim((string)($intent['note']??''));
        if(mb_strlen($note,'UTF-8')>120)throw new InvalidArgumentException('Need More quantity notes must be 120 characters or less.');
        $inventoryId='';
        if(app_has_permission('inventory.view',$user)||app_has_permission('inventory.manage',$user)){
            $same=array_values(array_filter(daily_quicklist_inventory($pdo,$org),
                static fn(array $r):bool=>daily_quicklist_normalize($r['name'])===daily_quicklist_normalize($title)));
            if(count($same)>1)throw new InvalidArgumentException('More than one inventory record matches. Add it from the Inventory page.');
            if(count($same)===1){$inventoryId=$same[0]['id'];$title=$same[0]['name'];}
        }
        $payload['inventoryId']=$inventoryId;$payload['title']=$title;$payload['note']=$note;
        $active=array_filter(daily_quicklist_items($pdo,$org,$date),
            static fn(array $r):bool=>$r['type']==='inventory'&&$r['status']!=='received'&&daily_quicklist_normalize($r['title'])===daily_quicklist_normalize($title));
        if($active)throw new InvalidArgumentException('This item is already on the active Need More list.');
        $description='Add '.$title.' to Need More'.($note!==''?' with note "'.$note.'"':'');
    }else{
        if(isset($intent['resolvedItemId'])){
            $row=null;
            foreach(daily_quicklist_items($pdo,$org,$date) as $candidate)
                if($candidate['id']===$intent['resolvedItemId']&&$candidate['type']===$kind){$row=$candidate;break;}
            if(!$row)throw new InvalidArgumentException('The selected checklist item is no longer available.');
        }else $row=pqa_resolve_item($pdo,$org,$date,$kind,$title);
        $payload['itemId']=$row['id'];$payload['fingerprint']=pqa_item_fingerprint($row);
        $title=$row['title'];$payload['kind']=$kind;
        if($op==='toggle'){
            $desired=$intent['status'];
            if($row['status']===$desired)return ['ok'=>true,'skill'=>'prep.daily_no_change',
                'answer'=>$title.' is already '.str_replace('_',' ',$desired).'. Nothing was changed.',
                'data'=>['proof'=>['url'=>'daily-prep.php']],'sources'=>[$row['id']]];
            $description='Mark '.$title.' '.($desired==='done'?'done':'not done');
        }elseif($op==='timer'){
            $mode=$intent['mode'];$running=(bool)$row['timerRunning'];
            if(($mode==='pause'||$mode==='stop')&&!$running)
                throw new InvalidArgumentException('That timer is not running.');
            if(($mode==='start'||$mode==='resume')&&$running)
                throw new InvalidArgumentException('That timer is already running.');
            if($mode==='resume'&&!in_array($row['status'],['paused','todo'],true))
                throw new InvalidArgumentException('Resume requires a paused or stopped timer.');
            $description=ucfirst($mode).' the timer for '.$title.($mode==='start'?' (reset elapsed time)':'');
        }elseif($op==='set_target'){
            if(!$row['recipeId'])throw new InvalidArgumentException('Only prep items linked to saved recipes can set a batch target.');
            $recipe=pqa_current_recipe($pdo,$org,(string)$row['recipeId']);
            $quantity=(float)$intent['quantity'];$unit=(string)$recipe['yield_unit'];
            if(!is_finite($quantity)||$quantity<0.001||$quantity>10000
                ||$quantity/(float)$recipe['yield_quantity']>1000)
                throw new InvalidArgumentException('That recipe target is outside the allowed range.');
            if($intent['unit']!==''&&strcasecmp((string)$intent['unit'],$unit)!==0)
                throw new InvalidArgumentException('Use the saved recipe yield unit: '.$unit.'.');
            $payload['recipeUnit']=$unit;$payload['recipeBase']=(float)$recipe['yield_quantity'];
            $description='Set '.$title.' batch target to '.daily_quicklist_format_quantity($quantity).' '.$unit.' (display-only scaling; no inventory change)';
        }elseif($op==='update'){
            $status=$intent['status']??$row['status'];
            $allowed=$kind==='prep'?['todo','in_progress','paused','done']:['need_more','ordered','received'];
            if(!in_array($status,$allowed,true))throw new InvalidArgumentException('Unsupported list status.');
            $note=$intent['note']??$row['adminNote'];
            if(mb_strlen((string)$note,'UTF-8')>2000)throw new InvalidArgumentException('Manager note exceeds 2,000 characters.');
            $quantityNote=$intent['quantityNote']??$row['quantityNote'];
            if(array_key_exists('quantityNote',$intent)
                &&($kind!=='inventory'||!is_string($intent['quantityNote'])
                    ||mb_strlen(trim($intent['quantityNote']),'UTF-8')>120))
                throw new InvalidArgumentException('Need More requested amount must be 120 characters or less.');
            $stockQty=$row['requestedStockQuantity'];
            $stockUnit='';
            if(array_key_exists('requestedStockQuantity',$intent)){
                if($kind!=='inventory'||!$row['inventoryId'])
                    throw new InvalidArgumentException('Numeric Need More quantities require a linked inventory item.');
                $stock=array_values(array_filter(daily_quicklist_inventory($pdo,$org),
                    static fn(array $x):bool=>$x['id']===$row['inventoryId']));
                if(count($stock)!==1)throw new InvalidArgumentException('The saved stock item is unavailable.');
                $stockUnit=(string)$stock[0]['unit'];
                if(isset($intent['unit'])&&$intent['unit']!==''&&strcasecmp((string)$intent['unit'],$stockUnit)!==0)
                    throw new InvalidArgumentException('Use the saved inventory stock unit: '.$stockUnit.'.');
                $stockQty=(float)$intent['requestedStockQuantity'];
                if(!is_finite($stockQty)||$stockQty<0.001||$stockQty>1000000
                    ||abs($stockQty-round($stockQty,3))>0.0000001)
                    throw new InvalidArgumentException('The requested stock quantity is outside the allowed range.');
                $payload['stockUnit']=$stockUnit;
            }
            if($status===$row['status']&&$note===$row['adminNote']&&$quantityNote===$row['quantityNote']
                &&$stockQty===$row['requestedStockQuantity'])
                return ['ok'=>true,'skill'=>'prep.daily_no_change','answer'=>'That status and note are already current. No change was made.',
                    'data'=>['proof'=>['url'=>'daily-prep.php']],'sources'=>[$row['id']]];
            $description='Update '.$title.($status!==$row['status']?' status to '.str_replace('_',' ',$status):'').
                ($note!==$row['adminNote']?' with manager note "'.$note.'"':'').
                ($quantityNote!==$row['quantityNote']?' with requested amount "'.$quantityNote.'"':'').
                ($stockQty!==$row['requestedStockQuantity']?' with numeric stock target '.
                    daily_quicklist_format_quantity((float)$stockQty).' '.$stockUnit:'');
        }elseif($op==='reconcile_receipt'){
            if($kind!=='inventory'||$row['status']==='received')
                throw new InvalidArgumentException('Only outstanding Need More requests can be reconciled.');
            $receiptId=(int)($intent['receiptTransactionId']??0);
            $candidates=daily_quicklist_receipt_candidates($pdo,$org,(string)$row['id']);
            $found=null;
            foreach($candidates as $candidate)if((int)$candidate['id']===$receiptId){$found=$candidate;break;}
            if(!$found)throw new InvalidArgumentException('The selected inventory receipt is no longer eligible. Show receipts again.');
            $progress=daily_quicklist_receipt_progress($pdo,$org,(string)$row['id']);
            $allocation=array_key_exists('allocatedQuantity',$intent)
                ?(float)$intent['allocatedQuantity']:(float)$found['suggestedAllocation'];
            if(!is_finite($allocation)||$allocation<0.001
                ||abs($allocation-round($allocation,3))>0.0000001
                ||$allocation>(float)$found['quantity']+0.0000001
                ||($progress['remainingQuantity']!==null
                    &&$allocation>$progress['remainingQuantity']+0.0000001)
                ||($progress['requestedQuantity']===null
                    &&abs($allocation-(float)$found['quantity'])>0.0000001))
                throw new InvalidArgumentException('Choose a valid allocation from the confirmed receipt in the saved stock unit.');
            $payload['receiptTransactionId']=$receiptId;
            $payload['receiptAllocation']=$allocation;
            $description='Allocate '.daily_quicklist_format_quantity($allocation).' '.$found['unit'].
                ' from an existing '.daily_quicklist_format_quantity($found['quantity']).' '.$found['unit'].
                ' receipt on '.$found['when'].' toward '.$title.' (no stock adjustment)'.
                ($progress['remainingQuantity']===null?'; this legacy request will close':
                    '; remaining after link '.daily_quicklist_format_quantity(max(0,$progress['remainingQuantity']-$allocation)).' '.$found['unit']);
        }elseif($op==='notify')$description='Notify managers to check '.$title;
        elseif($op==='archive')$description='Remove '.$title.' from '.($kind==='prep'?'Daily Prep':'Need More').' (retain audit history)';
        else throw new InvalidArgumentException('Unsupported Daily Prep action.');
    }
    $summary='Proposed: '.$description.' for '.$date.'. No changes have been made.';
    $proposal=gac_pending_store('prep',$org,$uid,'quicklist_item_action',$payload,$summary);
    app_audit($pdo,$org,$uid,'prep.agent_action_proposed','prep_agent_proposal',(string)$proposal['id'],null,
        ['type'=>'quicklist_item_action','action'=>$op,'date'=>$date,'itemId'=>$payload['itemId']??null]);
    return gac_proposal_result($proposal,'prep.action_proposal',['Daily Prep','Need More']);
}

function pqa_execute(PDO $pdo,int $org,int $uid,array $user,array $payload): array
{
    $intent=(array)($payload['intent']??[]);$op=(string)($intent['op']??'');
    $date=daily_quicklist_date((string)($payload['date']??''));
    if(!in_array($op,['add_need_more','toggle','timer','set_target','update','notify','archive','reconcile_receipt'],true))
        throw new InvalidArgumentException('Unsupported proposed Daily Prep action.');
    pqa_require_action_permissions($user,$intent);
    if(!daily_quicklist_ready($pdo))throw new InvalidArgumentException('Daily Prep is not installed.');
    if($op==='add_need_more'){
        $title=daily_quicklist_title($payload['title']??'');
        $inventoryId=(string)($payload['inventoryId']??'');
        $lock='gelato:need-more:'.$org.':'.substr(hash('sha256',daily_quicklist_normalize($title)),0,24);
        $q=$pdo->prepare('SELECT GET_LOCK(?,5)');$q->execute([$lock]);
        if((int)$q->fetchColumn()!==1)throw new RuntimeException('Need More is being updated. Please retry.');
        try{
            $active=array_filter(daily_quicklist_items($pdo,$org,$date),static fn(array $r):bool=>
                $r['type']==='inventory'&&$r['status']!=='received'
                &&daily_quicklist_normalize($r['title'])===daily_quicklist_normalize($title));
            if($active)throw new InvalidArgumentException('The Need More request was already added. Review it first.');
            if($inventoryId!==''){
                if(!(app_has_permission('inventory.view',$user)||app_has_permission('inventory.manage',$user)))
                    throw new PrepAgentPermissionException('Inventory permission was removed before confirmation.');
                $q=$pdo->prepare('SELECT name FROM inventory_items WHERE organization_id=? AND public_id=? AND archived_at IS NULL LIMIT 1');
                $q->execute([$org,$inventoryId]);$current=$q->fetchColumn();
                if($current===false||daily_quicklist_normalize((string)$current)!==daily_quicklist_normalize($title))
                    throw new InvalidArgumentException('The linked inventory record changed. Review the Need More proposal again.');
            }
            $id=daily_quicklist_add($pdo,$org,$uid,['type'=>'inventory','title'=>$title,
                'inventoryId'=>$inventoryId,'quantityNote'=>(string)($payload['note']??'')],false);
            $result=['id'=>$id,'status'=>'need_more'];
        }finally{$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
    }else{
        $id=(string)($payload['itemId']??'');$kind=(string)($payload['kind']??'');
        $rows=daily_quicklist_items($pdo,$org,$date);$row=null;
        foreach($rows as $candidate)if($candidate['id']===$id&&$candidate['type']===$kind){$row=$candidate;break;}
        if(!$row||!hash_equals((string)($payload['fingerprint']??''),pqa_item_fingerprint($row)))
            throw new InvalidArgumentException('That checklist item changed after the proposal. Review the current list and ask again.');
        $input=['id'=>$id,'expectedFingerprint'=>(string)$payload['fingerprint']];
        if($op==='toggle'){
            $desired=(string)($intent['status']??'');
            if(!in_array($desired,['done','todo'],true)||$row['status']===$desired||($desired==='todo'&&$row['status']!=='done'))
                throw new InvalidArgumentException('The checklist status changed. Ask again.');
        }elseif($op==='timer')$input['mode']=$intent['mode'];
        elseif($op==='set_target'){
            if(!$row['recipeId'])throw new InvalidArgumentException('The item no longer has a saved recipe.');
            $recipe=pqa_current_recipe($pdo,$org,(string)$row['recipeId']);
            if((string)$recipe['yield_unit']!==(string)($payload['recipeUnit']??'')
                ||(float)$recipe['yield_quantity']!==(float)($payload['recipeBase']??0))
                throw new InvalidArgumentException('The saved recipe yield changed. Review the new batch target.');
            $input['targetYieldQuantity']=$intent['quantity'];
            $input['expectedRecipeBase']=$payload['recipeBase'];
            $input['expectedRecipeUnit']=$payload['recipeUnit'];
        }elseif($op==='update'){
            $input['status']=$intent['status']??$row['status'];
            $input['adminNote']=$intent['note']??$row['adminNote'];
            if(array_key_exists('quantityNote',$intent))$input['quantityNote']=$intent['quantityNote'];
            if(array_key_exists('requestedStockQuantity',$intent)){
                $q=$pdo->prepare('SELECT base_unit FROM inventory_items
                    WHERE organization_id=? AND public_id=? AND archived_at IS NULL LIMIT 1');
                $q->execute([$org,(string)$row['inventoryId']]);
                if($q->fetchColumn()!==(string)($payload['stockUnit']??''))
                    throw new InvalidArgumentException('The saved inventory unit changed. Review the target again.');
                $input['requestedStockQuantity']=$intent['requestedStockQuantity'];
            }
        }
        $result=$op==='reconcile_receipt'
            ?daily_quicklist_reconcile_receipt($pdo,$org,$uid,$user,$id,
                (int)($payload['receiptTransactionId']??0),(string)$payload['fingerprint'],
                (float)($payload['receiptAllocation']??0))
            :daily_quicklist_mutate($pdo,$org,$uid,$op,$input,daily_quicklist_can_manage($user),
                app_has_permission('recipes.view',$user));
    }
    app_audit($pdo,$org,$uid,'prep.agent_daily_quicklist_confirmed','daily_quicklist_item',$result['id'],null,
        ['action'=>$op,'date'=>$date]);
    return ['ok'=>true,'skill'=>'prep.action_confirmed',
        'answer'=>'Confirmed. '.ucfirst(str_replace('_',' ',$op)).' completed for '.
            ($op==='add_need_more'?(string)$payload['title']:(string)$row['title']).'. Open Daily Prep for the current status.',
        'data'=>['action'=>$op,'itemId'=>$result['id'],'result'=>$result,'proof'=>['url'=>'daily-prep.php']],
        'sources'=>[$result['id']]];
}
