<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Picks WHICH category scheme a shared list's items should be grouped by — or
 * whether to group it at all. The list's own `grouping` column (see ShoppingLists)
 * gives the user explicit control: 'none' always renders flat (a to-do/ideas list
 * that shouldn't be bucketed); 'auto' (the default) keeps the name-based detection —
 * a packing list ("Pakkeliste", "packing list", "suitcase"…) groups by
 * PackingCategories, everything else by the supermarket-aisle GroceryCategories.
 * Keyword-routed, same deterministic approach as the category matchers themselves —
 * no model call.
 */
final class ListCategories
{
    public const MODE_AUTO = 'auto';
    public const MODE_NONE = 'none';

    /** @var list<string> */
    public const VALID_MODES = [self::MODE_AUTO, self::MODE_NONE];

    /** A list NAME containing any of these (case-insensitive) uses the packing scheme. */
    private const PACKING_NAME_KEYWORDS = [
        'pakkeliste', 'pakke til', 'kuffert', 'rejse', 'ferie', 'udlandstur',
        'packing', 'suitcase', 'travel', 'trip', 'vacation', 'holiday',
    ];

    /** @return array<string, array{en:string, da:string, icon:string}> empty when $mode is 'none' */
    public static function categories(string $listName, string $mode = self::MODE_AUTO): array
    {
        if ($mode === self::MODE_NONE) {
            return [];
        }

        return self::isPacking($listName) ? PackingCategories::CATEGORIES : GroceryCategories::CATEGORIES;
    }

    public static function categorize(string $listName, string $item, string $mode = self::MODE_AUTO): string
    {
        if ($mode === self::MODE_NONE) {
            return 'none';
        }

        return self::isPacking($listName) ? PackingCategories::categorize($item) : GroceryCategories::categorize($item);
    }

    public static function order(string $listName, string $category, string $mode = self::MODE_AUTO): int
    {
        if ($mode === self::MODE_NONE) {
            return 0;
        }

        return self::isPacking($listName) ? PackingCategories::order($category) : GroceryCategories::order($category);
    }

    private static function isPacking(string $listName): bool
    {
        $name = mb_strtolower(trim($listName));
        foreach (self::PACKING_NAME_KEYWORDS as $kw) {
            if (str_contains($name, $kw)) {
                return true;
            }
        }

        return false;
    }
}
