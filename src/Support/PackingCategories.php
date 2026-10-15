<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Groups PACKING-list items (not groceries) into bag-packing categories — clothing,
 * toiletries, electronics, documents/money, other gear. Same deterministic keyword
 * matching as GroceryCategories, just a different vocabulary; routed here by
 * ListCategories when the list's own name says "packing list"/"pakkeliste" etc.
 */
final class PackingCategories
{
    /** Packing order + bilingual labels + a Lucide icon name for the card header. */
    public const CATEGORIES = [
        'clothing'    => ['en' => 'Clothing',           'da' => 'Tøj',               'icon' => 'shirt'],
        'toiletries'  => ['en' => 'Toiletries',         'da' => 'Toiletsager',       'icon' => 'droplet'],
        'electronics' => ['en' => 'Electronics',        'da' => 'Elektronik',        'icon' => 'zap'],
        'documents'   => ['en' => 'Documents & money',  'da' => 'Papirer & penge',   'icon' => 'file-text'],
        'gear'        => ['en' => 'Gear & extras',      'da' => 'Udstyr & diverse',  'icon' => 'backpack'],
        'other'       => ['en' => 'Other',              'da' => 'Andet',             'icon' => 'list-checks'],
    ];

    /** @var array<string, list<string>> category => keywords (lowercase stems) */
    private const KEYWORDS = [
        'clothing' => [
            'tøj', 't-shirt', 'tshirt', 'trøje', 'bukser', 'shorts', 'underbukser', 'undertøj', 'strømper',
            'sokker', 'kjole', 'nederdel', 'skjorte', 'jakke', 'regnjakke', 'badetøj', 'bikini', 'badedragt',
            'svømmebukser', 'svømmebriller', 'pyjamas', 'nattøj', 'sko', 'sandaler', 'klipklapper', 'støvler',
            'sweater', 'hættetrøje', 'cardigan', 'halstørklæde', 'hue', 'hat', 'kasket', 'handsker', 'bælte',
            'slips', 'tights', 'leggings', 'badesandaler',
            'clothes', 'clothing', 'shirt', 'trousers', 'pants', 'shorts', 'underwear', 'socks', 'dress',
            'skirt', 'jacket', 'raincoat', 'swimwear', 'swimsuit', 'bikini', 'swim trunks', 'goggles',
            'pajamas', 'pyjamas', 'shoes', 'sandals', 'flip-flops', 'flipflops', 'boots', 'sweater', 'hoodie',
            'cardigan', 'scarf', 'gloves', 'belt', 'tie', 'leggings',
        ],
        'toiletries' => [
            'tandpasta', 'tandbørste', 'shampoo', 'balsam', 'sæbe', 'håndsæbe', 'deodorant', 'barberskraber',
            'barbermaskine', 'parfume', 'solcreme', 'creme', 'makeupfjerner', 'makeup', 'mascara', 'læbepomade',
            'vatpinde', 'hårbørste', 'hårelastik', 'menstruationskop', 'bind', 'tamponer', 'kontaktlinser',
            'linsevæske', 'briller', 'vådservietter',
            'toothpaste', 'toothbrush', 'shampoo', 'conditioner', 'soap', 'deodorant', 'razor', 'perfume',
            'sunscreen', 'sunblock', 'lotion', 'makeup', 'mascara', 'lip balm', 'cotton swabs', 'hairbrush',
            'hair tie', 'tampons', 'pads', 'contact lenses', 'contacts', 'glasses', 'wipes',
        ],
        'electronics' => [
            'mobiloplader', 'oplader', 'computeroplader', 'opladwr', 'powerbank', 'høretelefoner', 'hovedtelefoner',
            'adapter', 'rejseadapter', 'kamera', 'kabel', 'laptop', 'tablet',
            'charger', 'phone charger', 'laptop charger', 'power bank', 'headphones', 'earbuds', 'earphones',
            'adapter', 'travel adapter', 'camera', 'cable', 'usb cable', 'laptop', 'tablet', 'e-reader', 'kindle',
        ],
        'documents' => [
            'pas', 'kørekort', 'billet', 'billetter', 'boardingpas', 'bekræftelse', 'forsikring',
            'rejseforsikring', 'kontanter', 'valuta', 'kreditkort', 'visum', 'vaccinationskort',
            'passport', "driver's license", 'drivers license', 'ticket', 'tickets', 'boarding pass',
            'confirmation', 'insurance', 'travel insurance', 'cash', 'currency', 'credit card', 'visa',
            'vaccination card',
        ],
        'gear' => [
            'kuffert', 'rygsæk', 'toilettaske', 'hængelås', 'paraply', 'solbriller', 'nøgler', 'vandflaske',
            'rejsepude', 'ørepropper', 'hovedpude', 'bog',
            'suitcase', 'backpack', 'toiletry bag', 'wash bag', 'padlock', 'umbrella', 'sunglasses', 'keys',
            'water bottle', 'travel pillow', 'earplugs', 'pillow', 'book',
        ],
    ];

    /** The category key for one item ('other' when nothing matches). */
    public static function categorize(string $item): string
    {
        $text = ' ' . mb_strtolower(trim($item)) . ' ';
        $best = 'other';
        $bestLen = 0;
        foreach (self::KEYWORDS as $cat => $words) {
            foreach ($words as $w) {
                $len = mb_strlen($w);
                if ($len <= $bestLen) {
                    continue;
                }
                $hit = $len <= 3
                    ? preg_match('/(?<![\p{L}])' . preg_quote($w, '/') . '(?![\p{L}])/u', $text) === 1
                    : str_contains($text, $w);
                if ($hit) {
                    $best    = $cat;
                    $bestLen = $len;
                }
            }
        }

        return $best;
    }

    /** Position of a category in packing order (for sorting groups). */
    public static function order(string $category): int
    {
        $i = array_search($category, array_keys(self::CATEGORIES), true);

        return $i === false ? PHP_INT_MAX : (int) $i;
    }
}
