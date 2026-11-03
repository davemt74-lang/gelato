<?php
declare(strict_types=1);

require_once __DIR__.'/unit-conversion.php';

/** Parse one complete nonnegative amount, never the first number in ambiguous text. */
function recipe_ingredient_quantity(mixed $raw): ?float
{
    if(is_int($raw)||is_float($raw))return is_finite((float)$raw)&&$raw>=0?(float)$raw:null;
    if(!is_string($raw))return null;
    $value=strtr(trim($raw),['½'=>' 1/2','⅓'=>' 1/3','⅔'=>' 2/3','¼'=>' 1/4','¾'=>' 3/4','⅕'=>' 1/5','⅖'=>' 2/5','⅗'=>' 3/5','⅘'=>' 4/5','⅙'=>' 1/6','⅚'=>' 5/6','⅛'=>' 1/8','⅜'=>' 3/8','⅝'=>' 5/8','⅞'=>' 7/8','⁄'=>'/']);
    $value=trim(preg_replace('/\s+/u',' ',$value)??$value);
    $number=null;
    if(preg_match('~^(?:\d+(?:\.\d+)?|\.\d+)$~D',$value))$number=(float)$value;
    elseif(preg_match('~^(?:(\d+) )?(\d+)\s*/\s*(\d+)$~D',$value,$m)&& (float)$m[3]>0)
        $number=(float)($m[1]??0)+(float)$m[2]/(float)$m[3];
    return $number!==null&&is_finite($number)&&$number>=0?$number:null;
}

/** Known text units stop before the ingredient name; custom units belong in structured fields. */
function recipe_ingredient_unit_pattern(): string
{
    return '(?:fluid\s+ounces?|fl\.?\s*oz\.?|tablespoons?|tbsp\.?|teaspoons?|tsp\.?|kilograms?|kg|grams?|g|millilit(?:er|re)s?|ml|lit(?:er|re)s?|l|pounds?|lbs?\.?|ounces?|oz\.?|cups?|gallons?|gal|quarts?|qt|pints?|pt|each|ea|units?|pieces?|count|cloves?|cans?|packages?|pkg|pinches?|dashes?|bunch(?:es)?|slices?|sprigs?|stalks?|sticks?|heads?|bottles?|jars?|bags?|boxes?|cases?|pans?|trays?|batches?)';
}

function recipe_ingredient_unit(string $unit): string
{
    $unit=mb_strtolower(trim($unit),'UTF-8');
    $unit=preg_replace('/\s+/u',' ',$unit)??$unit;
    $unit=rtrim($unit,'.');
    if(preg_match('/^fl\.?\s*oz$/D',$unit))$unit='fl oz';
    $unit=strtr($unit,['millilitre'=>'milliliter','millilitres'=>'milliliters']);
    return restaurant_unit_normalize($unit);
}

/** Keep the saved input intact so a caller can display a review gap instead of guessing. */
function recipe_ingredient_parse(mixed $ingredient): ?array
{
    $name='';$quantity=null;$unit='';$rawQuantity=null;$reason=null;
    if(is_string($ingredient)){
        $text=trim($ingredient);if($text==='')return null;
        // Mixed amounts precede simple fractions/decimals in this alternation.
        $amount='(?:\d+\s+\d+\s*[/⁄]\s*\d+|\d+\s*[/⁄]\s*\d+|\d*\s*[½⅓⅔¼¾⅕⅖⅗⅘⅙⅚⅛⅜⅝⅞]|\d+(?:\.\d+)?|\.\d+)';
        if(preg_match('~^('.$amount.')\s+(.+)$~uD',$text,$m)){
            $rawQuantity=$m[1];$quantity=recipe_ingredient_quantity($m[1]);$rest=trim($m[2]);
            if(preg_match('~^('.recipe_ingredient_unit_pattern().')\s+(.+)$~iuD',$rest,$parts)){
                $unit=recipe_ingredient_unit($parts[1]);$name=trim($parts[2]);
            }else{
                // "2 eggs" has no invented unit; "1 to 2 cups" is not a count of "to 2 cups".
                $name=$rest;
                if(preg_match('~^'.recipe_ingredient_unit_pattern().'$~iuD',$rest)){
                    $name=$text;$quantity=null;
                    $reason='Review the ingredient name; the quantity and unit need a named ingredient.';
                }elseif(preg_match('/^(?:to\b|or\b|[–—-]|\d)/iu',$rest))$quantity=null;
            }
        }else $name=$text;
        if($quantity===null&&$reason===null)$reason='Review the ingredient amount; a single numeric quantity is required.';
    }elseif(is_array($ingredient)){
        $rawName=$ingredient['name']??$ingredient['ingredient']??$ingredient['item']??$ingredient['description']??'';
        $name=is_string($rawName)?trim($rawName):'';
        if($name===''&&isset($ingredient[0])&&is_string($ingredient[0]))return recipe_ingredient_parse($ingredient[0]);
        if($name==='')return null;
        $rawQuantity=$ingredient['quantity']??$ingredient['amount']??$ingredient['qty']??null;
        $quantity=recipe_ingredient_quantity($rawQuantity);
        $rawUnit=$ingredient['unit']??$ingredient['measure']??'';
        if(!is_string($rawUnit))$reason='Review the ingredient unit; a text unit is required.';
        else $unit=recipe_ingredient_unit($rawUnit);
        if($quantity===null)$reason='Review the ingredient amount; a single numeric quantity is required.';
    }else return null;
    if(mb_strlen($name,'UTF-8')>220||mb_strlen($unit,'UTF-8')>80)$reason='Review the ingredient name or unit; it exceeds the supported length.';
    return ['name'=>$name,'quantity'=>$quantity,'unit'=>$unit,'raw'=>$ingredient,'rawQuantity'=>$rawQuantity,'needsReview'=>$reason!==null,'reviewReason'=>$reason];
}
