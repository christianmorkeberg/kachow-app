<?php

declare(strict_types=1);

/**
 * ListCategories/GroceryCategories/PackingCategories unit tests — pure logic, no
 * DB, no network: php bin/list-categories-test.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Support\GroceryCategories;
use App\Support\ListCategories;
use App\Support\PackingCategories;

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✓ {$label}\n"; }
    else     { $fail++; echo "  ✗ {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

echo "1) a plain/default list name keeps the GROCERY scheme (store aisles)\n";
check('"Shopping" -> grocery', ListCategories::categorize('Shopping', 'mælk') === GroceryCategories::categorize('mælk'));
check('"Indkøbsliste" -> grocery', ListCategories::categorize('Indkøbsliste', 'æble') === GroceryCategories::categorize('æble'));
check('an unrelated list name -> grocery (fallback)', ListCategories::categorize('Gaveideer', 'æble') === GroceryCategories::categorize('æble'));

echo "2) \"Pakkeliste\" (Danish) and \"Packing list\" (English) -> the PACKING scheme\n";
check('"Pakkeliste" groups a charger as electronics', ListCategories::categorize('Pakkeliste', 'Mobiloplader') === 'electronics');
check('"Packing list" groups a passport as documents', ListCategories::categorize('Packing list', 'Passport') === 'documents');
check('"Kuffert til Rom" groups a t-shirt as clothing', ListCategories::categorize('Kuffert til Rom', 'T-shirt') === 'clothing');

echo "3) report #30's actual removed items land in sensible PACKING categories (not grocery 'other')\n";
$expect = [
    'Deodorant'         => 'toiletries',
    'Mobiloplader'      => 'electronics',
    'Computeroplader'   => 'electronics',
    'Badetøj'           => 'clothing',
    'ørepropper'        => 'gear',
];
foreach ($expect as $item => $want) {
    check("\"{$item}\" -> {$want}", PackingCategories::categorize($item) === $want, PackingCategories::categorize($item));
}

echo "4) categories()/order() stay consistent with whichever scheme categorize() picked\n";
$packingCats = ListCategories::categories('Pakkeliste');
check('packing scheme exposes "electronics"', isset($packingCats['electronics']));
check('packing scheme has an icon for every category', !array_filter($packingCats, static fn (array $c): bool => !isset($c['icon'])));
check(
    'packing order list leads with clothing before documents',
    ListCategories::order('Pakkeliste', 'clothing') < ListCategories::order('Pakkeliste', 'documents')
);

$groceryCats = ListCategories::categories('Indkøbsliste');
check('grocery scheme has NO "clothing" category', !isset($groceryCats['clothing']));

echo "5) an unmatched packing item falls back to packing 'other', not grocery 'other'\n";
check('"Rejsehåndbog" (unlisted) -> other', PackingCategories::categorize('Rejsehåndbog') === 'other');

echo "6) mode 'none' (a list the user turned grouping off for) never buckets, regardless of name\n";
check(
    '"Pakkeliste" (would normally be packing-grouped) -> \'none\' category under MODE_NONE',
    ListCategories::categorize('Pakkeliste', 'Mobiloplader', ListCategories::MODE_NONE) === 'none'
);
check(
    '"Opgaver" (would normally be grocery-grouped) -> \'none\' category under MODE_NONE',
    ListCategories::categorize('Opgaver', 'Ring til tandlæge', ListCategories::MODE_NONE) === 'none'
);
check('categories() is EMPTY under MODE_NONE (nothing to group by)', ListCategories::categories('Opgaver', ListCategories::MODE_NONE) === []);
check(
    'order() is stable (no PHP_INT_MAX surprises) under MODE_NONE',
    ListCategories::order('Opgaver', 'none', ListCategories::MODE_NONE) === 0
);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
