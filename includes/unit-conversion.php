<?php
declare(strict_types=1);

function restaurant_unit_normalize(string $unit): string
{
    $unit=mb_strtolower(trim($unit),'UTF-8');
    $unit=preg_replace('/\s+/u',' ',$unit)??$unit;
    $aliases=[
        'lbs'=>'lb','pound'=>'lb','pounds'=>'lb','ounces'=>'oz','ounce'=>'oz',
        'grams'=>'g','gram'=>'g','kilograms'=>'kg','kilogram'=>'kg',
        'milliliters'=>'ml','milliliter'=>'ml','liters'=>'l','liter'=>'l','litres'=>'l','litre'=>'l',
        'teaspoon'=>'tsp','teaspoons'=>'tsp','tablespoon'=>'tbsp','tablespoons'=>'tbsp',
        'cups'=>'cup','pints'=>'pt','pint'=>'pt','quarts'=>'qt','quart'=>'qt','gallons'=>'gal','gallon'=>'gal',
        'fluid ounce'=>'fl oz','fluid ounces'=>'fl oz','floz'=>'fl oz',
        'each'=>'ea','unit'=>'ea','units'=>'ea','piece'=>'ea','pieces'=>'ea','count'=>'ea'
    ];
    return $aliases[$unit]??$unit;
}

function restaurant_unit_definition(string $unit): ?array
{
    $unit=restaurant_unit_normalize($unit);
    if($unit==='')return null;
    $map=[
        'g'=>['dimension'=>'weight','factor'=>1.0],
        'kg'=>['dimension'=>'weight','factor'=>1000.0],
        'oz'=>['dimension'=>'weight','factor'=>28.349523125],
        'lb'=>['dimension'=>'weight','factor'=>453.59237],
        'ml'=>['dimension'=>'volume','factor'=>1.0],
        'l'=>['dimension'=>'volume','factor'=>1000.0],
        'tsp'=>['dimension'=>'volume','factor'=>4.92892159375],
        'tbsp'=>['dimension'=>'volume','factor'=>14.78676478125],
        'fl oz'=>['dimension'=>'volume','factor'=>29.5735295625],
        'cup'=>['dimension'=>'volume','factor'=>236.5882365],
        'pt'=>['dimension'=>'volume','factor'=>473.176473],
        'qt'=>['dimension'=>'volume','factor'=>946.352946],
        'gal'=>['dimension'=>'volume','factor'=>3785.411784],
        'ea'=>['dimension'=>'count','factor'=>1.0],
    ];
    return isset($map[$unit])?['unit'=>$unit]+$map[$unit]:['unit'=>$unit,'dimension'=>'unknown','factor'=>1.0];
}

function restaurant_unit_convert(float $quantity,string $fromUnit,string $toUnit): ?float
{
    $from=restaurant_unit_definition($fromUnit);$to=restaurant_unit_definition($toUnit);
    if(!$from&&!$to)return $quantity;
    if(!$from||!$to)return null;
    if($from['unit']===$to['unit'])return $quantity;
    if($from['dimension']==='unknown'||$to['dimension']==='unknown'||$from['dimension']!==$to['dimension'])return null;
    return $quantity*((float)$from['factor']/(float)$to['factor']);
}
