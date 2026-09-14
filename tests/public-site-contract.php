<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$required=['index.php','menu.php','gelato.php','about.php','contact.php','locations.php','jobs.html','job.php','apply.html','catering.php','wholesale.php','login.php','login.html','forgot-password.php','reset-password.php','workspace.php','public-site-settings.php','assets/css/site.css','assets/css/page-headers.css','css/stonefellows-public.css','assets/js/site.js','js/public-account-menu.js','assets/images/README.md','includes/public-site.php','database/20260914_public_site_settings.sql'];
foreach($required as $path)if(!is_file($root.'/'.$path))throw new RuntimeException('Missing required root public-site file: '.$path);
if(is_dir($root.'/public'))throw new RuntimeException('Legacy /public directory must not exist.');

$helper=file_get_contents($root.'/includes/public-site.php')?:'';
if(!str_contains($helper,'menu_database_sections'))throw new RuntimeException('Public site must use canonical menu database helper.');
if(!str_contains($helper,'href="login.php">Login</a>'))throw new RuntimeException('Public header must use Login CTA.');
if(str_contains($helper,'href="contact.php">Get in Touch</a>'))throw new RuntimeException('Legacy Get in Touch header CTA must not remain.');
foreach(['menu.php','gelato.php','about.php','locations.php','contact.php','jobs.html','catering.php','wholesale.php'] as $href)if(!str_contains($helper,"'".$href."'"))throw new RuntimeException('Footer link missing: '.$href);
foreach(['footer-column-title','footer-links','Links','Follow'] as $needle)if(!str_contains($helper,$needle))throw new RuntimeException('Footer column contract missing: '.$needle);

foreach(['index.php','menu.php','gelato.php'] as $path){$source=file_get_contents($root.'/'.$path)?:'';if(str_contains($source,'api/menu.php')||preg_match('/fetch\s*\(/',$source))throw new RuntimeException($path.' must not use authenticated menu API.');if(!str_contains($source,"__DIR__ . '/includes/public-site.php'"))throw new RuntimeException($path.' must load root public-site helper.');}

$workspace=file_get_contents($root.'/workspace.php')?:'';if(!str_contains($workspace,'app_require_auth'))throw new RuntimeException('workspace.php must remain authenticated.');
$login=file_get_contents($root.'/login.php')?:'';if(!str_contains($login,'workspace.php'))throw new RuntimeException('Login must return staff to workspace.php.');if(!str_contains($login,'stonefellows-public.css'))throw new RuntimeException('Login must use Stonefellows public theme.');if(!str_contains($login,'story-one.jpg')&&!str_contains(file_get_contents($root.'/css/stonefellows-public.css')?:'','story-one.jpg'))throw new RuntimeException('Login design must use an existing Stonefellows image.');

$settings=file_get_contents($root.'/public-site-settings.php')?:'';foreach(['app_require_auth','app_verify_csrf','public_pages.edit','public_site_settings','workspace.php'] as $needle)if(!str_contains($settings,$needle))throw new RuntimeException('Admin settings contract missing: '.$needle);

$css=file_get_contents($root.'/assets/css/site.css')?:'';foreach(['min-height:clamp(540px,66vh,640px)','.hero-inner{position:relative;z-index:2;width:var(--content);margin-inline:auto','.hero-copy h1','.gelato-section','background:transparent','grid-template-columns:1.2fr .9fr 1.15fr 1fr .8fr','.footer-links'] as $needle)if(!str_contains($css,$needle))throw new RuntimeException('Visual contract missing: '.$needle);
$legacyTheme=file_get_contents($root.'/css/stonefellows-public.css')?:'';foreach(['card-music.jpg','gelato.jpg','story-two.jpg','story-one.jpg','.public-header','.auth-shell','.wholesale-hero','.jobs-hero','.form-hero'] as $needle)if(!str_contains($legacyTheme,$needle))throw new RuntimeException('Unified public theme missing: '.$needle);
$pageHeaders=file_get_contents($root.'/assets/css/page-headers.css')?:'';foreach(['hero.jpg','gelato.jpg','story-two.jpg','card-reservations.jpg','card-drinks.jpg'] as $needle)if(!str_contains($pageHeaders,$needle))throw new RuntimeException('Page header image contract missing: '.$needle);
foreach(['menu.php'=>'page-menu','gelato.php'=>'page-gelato','about.php'=>'page-about','locations.php'=>'page-locations','contact.php'=>'page-contact'] as $path=>$class){$source=file_get_contents($root.'/'.$path)?:'';if(!str_contains($source,'page-headers.css'))throw new RuntimeException($path.' must load page header styles.');if(!str_contains($source,'class="'.$class.'"'))throw new RuntimeException($path.' must declare page header class '.$class.'.');}

$legacyJs=file_get_contents($root.'/js/public-account-menu.js')?:'';foreach(['stonefellows-public.css','login.php','index.php','catering.php','wholesale.php','sf-public-footer'] as $needle)if(!str_contains($legacyJs,$needle))throw new RuntimeException('Legacy public-page integration missing: '.$needle);
if(str_contains($legacyJs,'signup.php'))throw new RuntimeException('Public navigation must not expose a signup flow that does not exist.');
foreach(['jobs.html','job.php'] as $path){$source=file_get_contents($root.'/'.$path)?:'';if(!str_contains($source,'stonefellows-public.css'))throw new RuntimeException($path.' must load unified Stonefellows theme directly.');if(!str_contains($source,'href="login.php">Login</a>'))throw new RuntimeException($path.' must expose the Login CTA.');}
foreach(['catering.php','wholesale.php','apply.html','forgot-password.php','reset-password.php'] as $path){$source=file_get_contents($root.'/'.$path)?:'';if(!str_contains($source,'js/public-account-menu.js'))throw new RuntimeException($path.' must load unified public-page integration.');}

$assets=file_get_contents($root.'/assets/images/README.md')?:'';foreach(['hero.jpg','card-pizza.jpg','card-drinks.jpg','card-music.jpg','card-reservations.jpg','gelato.jpg','story-one.jpg','story-two.jpg'] as $asset)if(!str_contains($assets,$asset))throw new RuntimeException('Missing image asset contract: '.$asset);
echo "PASS: Stonefellows root public-site and unified design contract verified.\n";
