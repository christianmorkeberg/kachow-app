<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Picks WHICH category scheme a shared list's items should be grouped by, based
 * on the list's own NAME. Shopping/groceries (and anything unrecognised) keep the
 * existing supermarket-aisle grouping (GroceryCategories); a packing list ("Pakkeliste",
 * "packing list", "suitcase"…) groups by PackingCategories instead. Keyword-routed,
 * same deterministic approach as the category matchers themselves — no model call,
 * no stored "list type" column.
 */
final class ListCategories
{
    /** A list NAME containing any of these (case-insensitive) uses the packing scheme. */
    private const PACKING_NAME_KEYWORDS = [
        'pakkeliste', 'pakke til', 'kuffert', 'rejse', 'ferie', 'udlandstur',
        'packing', 'suitcase', 'travel', 'trip', 'vacation', 'holiday',
    ];

    /** @return array<string, array{en:string, da:string, icon:string}> */
    public static function categories(string $listName): array
    {
        return self::isPacking($listName) ? PackingCategories::CATEGORIES : GroceryCategories::CATEGORIES;
    }

    public static function categorize(string $listName, string $item): string
    {
        return self::isPacking($listName) ? PackingCategories::categorize($item) : GroceryCategories::categorize($item);
    }

    public static function order(string $listName, string $category): int
    {
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
