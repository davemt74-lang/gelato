<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$required=['index.php','menu.php','gelato.php','about.php','contact.php','locations.php','jobs.html','job.php','apply.html','catering.php','wholesale.php','login.php','login.html','forgot-password.php','reset-password.php','workspace.php','public-site-settings.php','customer-signup.php','customer-login.php','customer-account.php','customer-logout.php','assets/css/site.css','assets/css/page-headers.css','assets/css/customer-account.css','css/stonefellows-public.css','css/stonefellows-public-v2.css','assets/js/site.js','assets/js/public-shell.js','assets/css/public-shell.css','js/public-account-menu.js','assets/images/README.md','includes/public-site.php','includes/customer-account-core.php','api/public-shell.php','database/20260914_public_site_settings.sql','database/20261002_customer_accounts_online_ordering_foundation.sql'];
foreach($required as $path)if(!is_file($root.'/'.$path))throw new RuntimeException('Missing required root public-site file: '.$path);
if(is_dir($root.'/public'))throw new RuntimeException('Legacy /public directory must not exist.');

$helper=file_get_contents($root.'/includes/public-site.php')?:'';
if(!str_contains($helper,'menu_database_sections'))throw new RuntimeException('Public site must use canonical menu database helper.');
if(!str_contains($helper,'href="customer-account.php">Account</a>'))throw new RuntimeException('Public header must expose the customer Account CTA.');
$headerStart=strpos($helper,'function public_site_render_header');
$footerStart=strpos($helper,'function public_site_render_footer');
if($headerStart===false||$footerStart===false||$footerStart<=$headerStart)throw new RuntimeException('Public header/footer renderers are missing or out of order.');
$headerSection=substr($helper,$headerStart,$footerStart-$headerStart);
$footerSection=substr($helper,$footerStart);
if(str_contains($headerSection,"'contact' => ['contact.php', 'Contact']"))throw new RuntimeException('Contact must not be duplicated in the primary header navigation.');
if(!str_contains($footerSection,"'Admin / Employee Login' => 'login.php'"))throw new RuntimeException('Public footer must retain the Admin / Employee Login path.');
foreach(["'Contact' => 'contact.php'","'Wholesale' => 'wholesale.php'","'Catering' => 'catering.php'","'Admin / Employee Login' => 'login.php'"] as $needle)if(!str_contains($footerSection,$needle))throw new RuntimeException('Required footer link missing: '.$needle);
foreach(["'Menu' => 'menu.php'","'Gelato' => 'gelato.php'","'About' => 'about.php'","'Locations' => 'locations.php'","'Account' => 'customer-account.php'","'Jobs' => 'jobs.html'","'Staff Login' => 'login.php'"] as $needle)if(str_contains($footerSection,$needle))throw new RuntimeException('Footer must not duplicate or retain retired link: '.$needle);
if(str_contains($helper,'href="contact.php">Get in Touch</a>'))throw new RuntimeException('Legacy Get in Touch header CTA must not remain.');
foreach(['footer-column-title','footer-links','Links','Follow'] as $needle)if(!str_contains($helper,$needle))throw new RuntimeException('Footer column contract missing: '.$needle);

foreach(['index.php','menu.php','gelato.php'] as $path){$source=file_get_contents($root.'/'.$path)?:'';if(str_contains($source,'api/menu.php')||preg_match('/fetch\s*\(/',$source))throw new RuntimeException($path.' must not use authenticated menu API.');if(!str_contains($source,"__DIR__ . '/includes/public-site.php'"))throw new RuntimeException($path.' must load root public-site helper.');}

$workspace=file_get_contents($root.'/workspace.php')?:'';if(!str_contains($workspace,'app_require_auth'))throw new RuntimeException('workspace.php must remain authenticated.');
$login=file_get_contents($root.'/login.php')?:'';if(!str_contains($login,'workspace.php'))throw new RuntimeException('Login must return staff to workspace.php.');if(!str_contains($login,'stonefellows-public-v2.css'))throw new RuntimeException('Login must load the explicit Stonefellows v2 theme.');

$signup=file_get_contents($root.'/customer-signup.php')?:'';
foreach(['customer_account_register','app_verify_csrf','email_marketing','sms_marketing','$return=customer_account_safe_return','name="return"','app_redirect($return)'] as $needle)if(!str_contains($signup,$needle))throw new RuntimeException('Customer signup contract missing: '.$needle);
$customerLogin=file_get_contents($root.'/customer-login.php')?:'';
foreach(['customer_account_authenticate','app_verify_csrf','customer-signup.php'] as $needle)if(!str_contains($customerLogin,$needle))throw new RuntimeException('Customer login contract missing: '.$needle);
$customerAccount=file_get_contents($root.'/customer-account.php')?:'';
foreach(['customer_account_require','customer_account_profile','customer_account_orders','customer-logout.php'] as $needle)if(!str_contains($customerAccount,$needle))throw new RuntimeException('Customer account contract missing: '.$needle);
$customerCore=file_get_contents($root.'/includes/customer-account-core.php')?:'';
foreach(['customer.portal','online_ordering.use','crm_customers','crm_consent_set','Online Customer','password_verify','session_regenerate_id'] as $needle)if(!str_contains($customerCore,$needle))throw new RuntimeException('Customer identity contract missing: '.$needle);

$settings=file_get_contents($root.'/public-site-settings.php')?:'';foreach(['app_require_auth','app_verify_csrf','public_pages.edit','public_site_settings','workspace.php'] as $needle)if(!str_contains($settings,$needle))throw new RuntimeException('Admin settings contract missing: '.$needle);

$css=file_get_contents($root.'/assets/css/site.css')?:'';foreach(['min-height:clamp(540px,66vh,640px)','.hero-inner{position:relative;z-index:2;width:var(--content);margin-inline:auto','.hero-copy h1','.gelato-section','background:transparent','grid-template-columns:1.2fr .9fr 1.15fr 1fr .8fr','.footer-links'] as $needle)if(!str_contains($css,$needle))throw new RuntimeException('Visual contract missing: '.$needle);
$pageHeaders=file_get_contents($root.'/assets/css/page-headers.css')?:'';foreach(['hero.jpg','gelato.jpg','story-two.jpg','card-reservations.jpg','card-drinks.jpg'] as $needle)if(!str_contains($pageHeaders,$needle))throw new RuntimeException('Page header image contract missing: '.$needle);
foreach(['menu.php'=>'page-menu','gelato.php'=>'page-gelato','about.php'=>'page-about','locations.php'=>'page-locations','contact.php'=>'page-contact'] as $path=>$class){$source=file_get_contents($root.'/'.$path)?:'';if(!str_contains($source,'page-headers.css'))throw new RuntimeException($path.' must load page header styles.');if(!str_contains($source,'class="'.$class.'"'))throw new RuntimeException($path.' must declare page header class '.$class.'.');}

$v2=file_get_contents($root.'/css/stonefellows-public-v2.css')?:'';foreach(['card-music.jpg','gelato.jpg','story-two.jpg','story-one.jpg','.public-header','.auth-shell','.wholesale-hero','.jobs-hero','.form-hero','.sf-public-footer'] as $needle)if(!str_contains($v2,$needle))throw new RuntimeException('Stonefellows v2 public theme missing: '.$needle);
foreach(['jobs.html','job.php','apply.html','catering.php','wholesale.php','login.php'] as $path){$source=file_get_contents($root.'/'.$path)?:'';if(!str_contains($source,'stonefellows-public-v2.css?v=20260914-2'))throw new RuntimeException($path.' must load the explicit cache-busted Stonefellows v2 theme.');if(!str_contains($source,'href="login.php">Login</a>')&&$path!=='login.php')throw new RuntimeException($path.' must expose the Login CTA.');}
foreach(['jobs.html','job.php','apply.html','catering.php','wholesale.php'] as $path){$source=file_get_contents($root.'/'.$path)?:'';if(!str_contains($source,'sf-public-footer'))throw new RuntimeException($path.' must render the Stonefellows footer directly.');}
foreach(['catering.php','wholesale.php','apply.html'] as $path){$source=file_get_contents($root.'/'.$path)?:'';if(str_contains($source,'landing.html'))throw new RuntimeException($path.' must not link to the legacy landing page.');}

$legacyJs=file_get_contents($root.'/js/public-account-menu.js')?:'';if(!str_contains($legacyJs,'assets/js/public-shell.js?v=20260915-1'))throw new RuntimeException('Legacy public integration must mount the universal public shell.');if(str_contains($legacyJs,'signup.php'))throw new RuntimeException('Legacy public account menu must not expose the obsolete generic signup route.');

$assets=file_get_contents($root.'/assets/images/README.md')?:'';foreach(['hero.jpg','card-pizza.jpg','card-drinks.jpg','card-music.jpg','card-reservations.jpg','gelato.jpg','story-one.jpg','story-two.jpg'] as $asset)if(!str_contains($assets,$asset))throw new RuntimeException('Missing image asset contract: '.$asset);
echo "PASS: Stonefellows root public-site, customer account surface, and visible unified design contract verified.\n";
