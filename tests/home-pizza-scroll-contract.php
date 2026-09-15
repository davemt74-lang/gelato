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
$imagesReadme = home_story_source('assets/images/README.md');

home_story_assert(str_contains($index, '$pizzaStoryItems = $pizzaSection ? public_site_featured_items($pizzaSection, 5) : [];'), 'Homepage must source up to five pizzas from the canonical menu database.');
home_story_assert(str_contains($index, 'id="pizzaStory"'), 'Homepage must expose the sticky pizza story root.');
home_story_assert(str_contains($index, 'data-pizza-slide'), 'Homepage must render menu-driven pizza story slides.');
foreach ([
    'pizza-scroll-margherita.webp',
    'pizza-scroll-pepperoni.webp',
    'pizza-scroll-sausage-mushroom.webp',
    'pizza-scroll-vegetable.webp',
    'pizza-scroll-meat-lovers.webp',
] as $asset) {
    home_story_assert(str_contains($index, $asset), 'Homepage must register rolling asset '.$asset.'.');
    home_story_assert(str_contains($imagesReadme, '`'.$asset.'`'), 'Public image manifest must register '.$asset.'.');
}
home_story_assert(str_contains($index, "is_file(__DIR__ . '/assets/images/' . $storyCandidate)"), 'Homepage must safely fall back when rolling assets are not installed.');
home_story_assert(str_contains($index, 'pizza-story-toppings'), 'Homepage story must show canonical toppings/ingredients.');
home_story_assert(str_contains($index, 'Special notes'), 'Homepage story must render special notes when present.');
home_story_assert(str_contains($index, 'home-pizza-scroll.css?v=20260915-2'), 'Homepage must load the current pizza story stylesheet.');
home_story_assert(str_contains($index, 'home-pizza-scroll.js?v=20260915-2'), 'Homepage must load the current pizza story runtime.');

home_story_assert(str_contains($menu, 'preparation_notes'), 'Menu projection must retain preparation notes for public presentation.');
home_story_assert(str_contains($menu, "'specialNotes'"), 'Menu projection must expose special notes without hardcoding product copy.');

home_story_assert(str_contains($css, 'position:sticky'), 'Pizza story must use sticky scrolling.');
home_story_assert(str_contains($css, 'calc((var(--pizza-count) + 2) * 100svh)'), 'Pizza story height must scale to the number of products.');
home_story_assert(str_contains($css, '.pizza-story-stage:before'), 'Pizza story must include the presentation glow behind the rolling product.');
home_story_assert(str_contains($css, '@media(prefers-reduced-motion:reduce)'), 'Pizza story must provide a reduced-motion fallback.');

home_story_assert(str_contains($js, "document.getElementById('pizzaStory')"), 'Pizza runtime must bind the story root.');
home_story_assert(str_contains($js, 'rotation = (1 - entry) * 320 - exit * 110'), 'Pizza must roll into and out of the stage instead of only fading.');
home_story_assert(str_contains($js, 'imageTransform(image, -24 * exit, -110 * exit'), 'Previous pizza must continue rolling left while the next pizza enters.');
home_story_assert(str_contains($js, 'translate3d(${x}vw,0,0)'), 'Pizza must travel horizontally while rolling.');
home_story_assert(str_contains($js, 'outroStart'), 'Pizza runtime must fade out and return to normal page scrolling.');
home_story_assert(str_contains($js, "prefers-reduced-motion: reduce"), 'Pizza runtime must honor reduced-motion preferences.');

echo "home-pizza-scroll-contract-ok\n";
