<?php
declare(strict_types=1);

function home_story_assert(bool $condition,string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function home_story_source(string $path): string {
    $value = file_get_contents(__DIR__.'/../'.$path);
    if ($value === false) throw new RuntimeException('Unable to read '.$path);
    return $value;
}

$index = home_story_source('index.php');
$menu = home_story_source('includes/menu-sync.php');
$css = home_story_source('assets/css/home-pizza-scroll.css');
$js = home_story_source('assets/js/home-pizza-scroll.js');

home_story_assert(str_contains($index, '$pizzaStoryItems = $pizzaSection ? public_site_featured_items($pizzaSection, 5) : [];'), 'Homepage must source up to five pizzas from the canonical menu database.');
home_story_assert(str_contains($index, 'id="pizzaStory"'), 'Homepage must expose the sticky pizza story root.');
home_story_assert(str_contains($index, 'data-pizza-slide'), 'Homepage must render menu-driven pizza story slides.');
home_story_assert(str_contains($index, 'pizza-scroll-feature.webp'), 'Homepage must use the supplied pizza presentation asset.');
home_story_assert(str_contains($index, 'pizza-story-toppings'), 'Homepage story must show canonical toppings/ingredients.');
home_story_assert(str_contains($index, 'Special notes'), 'Homepage story must render special notes when present.');
home_story_assert(str_contains($index, 'home-pizza-scroll.css?v=20260915-1'), 'Homepage must load the pizza story stylesheet.');
home_story_assert(str_contains($index, 'home-pizza-scroll.js?v=20260915-1'), 'Homepage must load the pizza story runtime.');

home_story_assert(str_contains($menu, 'preparation_notes'), 'Menu projection must retain preparation notes for public presentation.');
home_story_assert(str_contains($menu, "'specialNotes'"), 'Menu projection must expose special notes without hardcoding product copy.');

home_story_assert(str_contains($css, 'position:sticky'), 'Pizza story must use sticky scrolling.');
home_story_assert(str_contains($css, 'calc((var(--pizza-count) + 2) * 100svh)'), 'Pizza story height must scale to the number of products.');
home_story_assert(str_contains($css, '@media(prefers-reduced-motion:reduce)'), 'Pizza story must provide a reduced-motion fallback.');

home_story_assert(str_contains($js, "document.getElementById('pizzaStory')"), 'Pizza runtime must bind the story root.');
home_story_assert(str_contains($js, 'rotate(${rotate}deg)'), 'Pizza must rotate while entering to create the rolling motion.');
home_story_assert(str_contains($js, 'translate3d(${x}vw,0,0)'), 'Pizza must travel from right to left.');
home_story_assert(str_contains($js, 'outroStart'), 'Pizza runtime must fade out and return to normal page scrolling.');
home_story_assert(str_contains($js, "prefers-reduced-motion: reduce"), 'Pizza runtime must honor reduced-motion preferences.');

$image = __DIR__.'/../assets/images/pizza-scroll-feature.webp';
home_story_assert(is_file($image) && filesize($image) > 10000, 'Supplied pizza image asset must ship with the feature.');

echo "home-pizza-scroll-contract-ok\n";
