<?php
declare(strict_types=1);

/** Deterministic human-readable kitchen announcements from canonical KDS data. */
function kso_quantity(mixed $value): string
{
    $n=(float)$value;
    if(!is_finite($n)||$n<=0)return 'one';
    $words=[1=>'one',2=>'two',3=>'three',4=>'four',5=>'five',6=>'six',7=>'seven',8=>'eight',9=>'nine',10=>'ten'];
    if(floor($n)===$n)return $words[(int)$n]??(string)(int)$n;
    return rtrim(rtrim(number_format($n,2,'.',''),'0'),'.');
}
function kso_clean(mixed $value,int $length=220):string
{
    $s=trim(preg_replace('/[\x00-\x1F\x7F]+/u',' ',(string)$value)??'');
    return mb_substr(preg_replace('/\s+/u',' ',$s)??$s,0,$length,'UTF-8');
}
function kso_item(array $item):string
{
    $s=kso_quantity($item['quantity']??1).' '.kso_clean($item['item_name_snapshot']??$item['name']??'item');
    if(!empty($item['option_name_snapshot']))$s.=', '.kso_clean($item['option_name_snapshot']);
    if(!empty($item['special_instructions']))$s.='. Note: '.kso_clean($item['special_instructions'],400);
    return $s;
}
function kso_ticket(array $ticket,?int $slot=null,?array $addedItems=null):string
{
    $label=$slot!==null&&$slot>0?'Order '.$slot:'Kitchen order';
    $ticketNo=kso_clean($ticket['checkNumber']??'');
    $prefix=($addedItems!==null?'Update for ':'').$label.($ticketNo!==''?', ticket '.$ticketNo:'').'.';
    $place=!empty($ticket['tableName'])?' Table '.kso_clean($ticket['tableName']).'.':
      (!empty($ticket['serviceMode'])?' '.ucfirst(str_replace('_',' ',kso_clean($ticket['serviceMode']))).'.':'');
    $items=$addedItems??(array)($ticket['items']??[]);
    $phrases=[];foreach($items as $item)if(is_array($item))$phrases[]=kso_item($item);
    $result=$prefix.$place.' '.($phrases?implode('. ',$phrases).'.':'No routed items yet.');
    if(!empty($ticket['late']))$result.=' This order is running late.';
    elseif(!empty($ticket['readyToBump']))$result.=' This ticket is ready for pickup.';
    return trim($result);
}
