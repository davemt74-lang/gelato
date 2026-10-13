<?php
declare(strict_types=1);

/**
 * Resolve an Order N reference across the full sorted active KDS queue.
 * Only the canonical server board owns the ticket contents. The browser
 * mapping is used solely to reject stale slots after a re-sort.
 *
 * @return array{status:string,slot:int,total:int,ticket?:array}
 */
function kds_resolve_order_reference(array $tickets,array $clientSlots,int $slot):array
{
    $total=count($tickets);
    if($slot<1)return ['status'=>'invalid','slot'=>$slot,'total'=>$total];
    $current=$tickets[$slot-1]??null;
    if(!is_array($current))return ['status'=>'empty','slot'=>$slot,'total'=>$total];
    $expected=$clientSlots[$slot-1]??null;
    if(!is_array($expected)||(int)($expected['slot']??0)!==$slot
      ||(string)($expected['checkPublicId']??'')===''
      ||(string)$expected['checkPublicId']!==(string)($current['checkPublicId']??''))
        return ['status'=>'stale','slot'=>$slot,'total'=>$total];
    return ['status'=>'ok','slot'=>$slot,'total'=>$total,'ticket'=>$current];
}
