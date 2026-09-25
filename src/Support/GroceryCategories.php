<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Groups shopping-list items into the aisles of a typical (Danish) supermarket, so the
 * list reads in store order: produce, bread, dairy, meat… (dev idea: group the shopping
 * list). Deterministic keyword matching over Danish + English stems — no model call, so it
 * also covers items added straight from the card, and it needs no stored column.
 *
 * Matching: the LONGEST keyword found in the item wins ("æblejuice" → juice beats æble;
 * "vandmelon" → melon beats vand; "pålæg" beats "æg"). Keywords of ≤ 3 letters must match
 * a whole word so "øl" doesn't fire inside other words. Unknown → 'other'.
 */
final class GroceryCategories
{
    /** Store-walk order + bilingual labels + a Lucide icon name for the card header. */
    public const CATEGORIES = [
        'produce'   => ['en' => 'Fruit & veg',        'da' => 'Frugt & grønt',     'icon' => 'apple'],
        'bakery'    => ['en' => 'Bread & bakery',     'da' => 'Brød & bageri',     'icon' => 'croissant'],
        'dairy'     => ['en' => 'Dairy & eggs',       'da' => 'Mejeri & æg',       'icon' => 'milk'],
        'meat'      => ['en' => 'Meat, fish & deli',  'da' => 'Kød, fisk & pålæg', 'icon' => 'beef'],
        'frozen'    => ['en' => 'Frozen',             'da' => 'Frost',             'icon' => 'snowflake'],
        'pantry'    => ['en' => 'Pantry',             'da' => 'Kolonial',          'icon' => 'wheat'],
        'drinks'    => ['en' => 'Drinks',             'da' => 'Drikkevarer',       'icon' => 'cup-soda'],
        'snacks'    => ['en' => 'Snacks & sweets',    'da' => 'Snacks & slik',     'icon' => 'candy'],
        'household' => ['en' => 'Household',          'da' => 'Husholdning',       'icon' => 'spray-can'],
        'personal'  => ['en' => 'Personal care',      'da' => 'Personlig pleje',   'icon' => 'hand-heart'],
        'other'     => ['en' => 'Other',              'da' => 'Andet',             'icon' => 'shopping-basket'],
    ];

    /** @var array<string, list<string>> category => keywords (lowercase stems) */
    private const KEYWORDS = [
        'produce' => [
            'æble', 'pære', 'banan', 'appelsin', 'citron', 'lime', 'mandarin', 'klementin', 'grape', 'vindrue',
            'drue', 'jordbær', 'hindbær', 'blåbær', 'brombær', 'kirsebær', 'blomme', 'fersken', 'nektarin',
            'melon', 'ananas', 'mango', 'kiwi', 'avocado', 'granatæble', 'frugt', 'grønt', 'grøntsag',
            'kartoffel', 'kartofler', 'løg', 'hvidløg', 'porre', 'gulerod', 'gulerødder', 'agurk', 'tomat',
            'peberfrugt', 'squash', 'aubergine', 'broccoli', 'blomkål', 'kål', 'spinat', 'salat', 'rucola',
            'selleri', 'pastinak', 'rødbede', 'majs', 'champignon', 'svampe', 'ingefær', 'chili', 'krydderurt',
            'persille', 'basilikum', 'koriander', 'purløg', 'dild', 'radise', 'asparges', 'bønnespirer', 'sukkerærter',
            'apple', 'pear', 'banana', 'orange', 'lemon', 'grapes', 'strawberr', 'raspberr', 'blueberr', 'cherr',
            'peach', 'pineapple', 'fruit', 'veg', 'potato', 'onion', 'garlic', 'leek', 'carrot', 'cucumber',
            'tomato', 'pepper', 'zucchini', 'courgette', 'cabbage', 'spinach', 'lettuce', 'celery', 'mushroom',
            'ginger', 'herbs', 'parsley', 'basil', 'coriander', 'cilantro', 'avocado', 'corn',
        ],
        'bakery' => [
            'brød', 'rugbrød', 'franskbrød', 'toastbrød', 'boller', 'bolle', 'rundstykke', 'baguette', 'croissant',
            'wienerbrød', 'kage', 'knækbrød', 'tortilla', 'wraps', 'pitabrød', 'burgerboller', 'pølsebrød',
            'bread', 'bun', 'buns', 'roll', 'rolls', 'bagel', 'cake', 'pastry', 'crispbread',
        ],
        'dairy' => [
            'mælk', 'letmælk', 'minimælk', 'sødmælk', 'skummetmælk', 'havremælk', 'mandelmælk', 'chokolademælk',
            'yoghurt', 'skyr', 'kvark', 'ymer', 'a38', 'fløde', 'piskefløde', 'madlavningsfløde', 'creme fraiche',
            'cremefraiche', 'smør', 'smørbar', 'margarine', 'ost', 'mozzarella', 'parmesan', 'feta', 'hytteost',
            'flødeost', 'kaffefløde', 'æg', 'æggehvide', 'milk', 'yogurt', 'yoghurt', 'cream', 'butter', 'cheese', 'eggs', 'egg',
        ],
        'meat' => [
            'kød', 'oksekød', 'svinekød', 'hakket', 'hakkekød', 'kylling', 'kyllingebryst', 'kalkun', 'bacon',
            'pølse', 'pølser', 'skinke', 'salami', 'leverpostej', 'rullepølse', 'pålæg', 'frikadelle', 'medister',
            'bøf', 'steak', 'mørbrad', 'koteletter', 'flæsk', 'lam', 'fisk', 'laks', 'torsk', 'rejer', 'tun',
            'makrel', 'sild', 'fiskefilet', 'fiskefrikadeller', 'beef', 'pork', 'chicken', 'turkey', 'ham',
            'sausage', 'mince', 'minced', 'meat', 'fish', 'salmon', 'cod', 'shrimp', 'prawn', 'tuna', 'deli',
        ],
        'frozen' => [
            'frost', 'frosne', 'frossen', 'frosset', 'dybfrost', 'vaniljeis', 'isvafler', 'flødeis', 'frysepizza',
            'pommes frites', 'frozen', 'ice cream',
        ],
        'pantry' => [
            'pasta', 'spaghetti', 'makaroni', 'nudler', 'ris', 'couscous', 'bulgur', 'quinoa', 'mel', 'hvedemel',
            'sukker', 'flormelis', 'bagepulver', 'gær', 'havregryn', 'müsli', 'mysli', 'granola', 'cornflakes',
            'morgenmad', 'olie', 'olivenolie', 'rapsolie', 'eddike', 'salt', 'peber', 'krydderi', 'bouillon',
            'fond', 'ketchup', 'sennep', 'mayonnaise', 'remoulade', 'dressing', 'pesto', 'soja', 'sojasauce',
            'hakkede tomater', 'flåede tomater', 'tomatpuré', 'passata', 'kokosmælk', 'bønner', 'kikærter',
            'linser', 'dåse', 'konserves', 'honning', 'marmelade', 'syltetøj', 'nutella', 'peanutbutter',
            'jordnøddesmør', 'kaffe', 'te', 'tebreve', 'kakao', 'nødder', 'mandler', 'rosiner',
            'rice', 'flour', 'sugar', 'oats', 'cereal', 'oil', 'vinegar', 'spice', 'stock', 'sauce', 'beans',
            'lentils', 'chickpeas', 'canned', 'honey', 'jam', 'coffee', 'tea', 'nuts', 'noodles',
        ],
        'drinks' => [
            'vand', 'danskvand', 'kildevand', 'sodavand', 'cola', 'pepsi', 'fanta', 'juice', 'appelsinjuice',
            'æblejuice', 'saft', 'øl', 'vin', 'rødvin', 'hvidvin', 'rosé', 'cider', 'energidrik', 'smoothie',
            'water', 'soda', 'beer', 'wine', 'lemonade', 'energy drink',
        ],
        'snacks' => [
            'slik', 'chokolade', 'chips', 'snacks', 'kiks', 'småkager', 'lakrids', 'vingummi', 'popcorn',
            'nachos', 'dip', 'candy', 'chocolate', 'crisps', 'cookies', 'biscuits', 'sweets',
        ],
        'household' => [
            'toiletpapir', 'køkkenrulle', 'køkkenruller', 'opvaskemiddel', 'opvasketabs', 'vaskemiddel',
            'skyllemiddel', 'rengøring', 'rengøringsmiddel', 'affaldsposer', 'skraldeposer', 'fryseposer',
            'alufolie', 'bagepapir', 'husholdningsfilm', 'svamp', 'klude', 'batterier', 'lys',
            'servietter', 'stearinlys', 'toilet paper', 'kitchen roll', 'paper towel', 'detergent', 'dish soap',
            'bin bags', 'trash bags', 'foil', 'batteries', 'napkins', 'cleaning',
        ],
        'personal' => [
            'tandpasta', 'tandbørste', 'shampoo', 'balsam', 'sæbe', 'håndsæbe', 'deodorant', 'barberskraber',
            'bind', 'tamponer', 'vatrondeller', 'vatpinde', 'solcreme', 'creme', 'plaster', 'panodil', 'ipren',
            'bleer', 'vådservietter', 'toothpaste', 'toothbrush', 'soap', 'conditioner', 'razor', 'pads',
            'tampons', 'sunscreen', 'lotion', 'plasters', 'painkillers', 'diapers', 'nappies', 'wipes',
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

    /** Position of a category in store-walk order (for sorting groups). */
    public static function order(string $category): int
    {
        $i = array_search($category, array_keys(self::CATEGORIES), true);

        return $i === false ? PHP_INT_MAX : (int) $i;
    }
}
