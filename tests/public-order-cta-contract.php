<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$index=file_get_contents($root.'/index.php')?:'';
if(!is_file($root.'/online-order.php'))throw new RuntimeException('online-order.php must exist before exposing public Order Online CTAs.');
foreach([
    'home-order-cta',
    'href="online-order.php">Order Online</a>',
    '<a class="btn btn-primary" href="online-order.php">Order Online</a>',
    '<a class="btn btn-secondary" href="menu.php">View Our Menu</a>',
    '<a class="btn btn-secondary" href="locations.php">Visit Us</a>',
    '.site-header .home-order-cta{display:inline-flex',
] as $needle){
    if(!str_contains($index,$needle))throw new RuntimeException('Homepage Order Online CTA contract missing: '.$needle);
}
if(substr_count($index,'online-order.php')<2)throw new RuntimeException('Homepage must expose Online Order from both header and hero.');
if(!str_contains($index,'str_replace($accountCta, $orderCta . $accountCta, $homeHeader)'))throw new RuntimeException('Homepage header CTA must preserve the existing Account CTA while inserting Order Online.');
echo "PASS: public homepage exposes responsive header and hero Order Online CTAs.\n";
