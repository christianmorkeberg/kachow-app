<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * General per-user settings — a small, controlled key/value store for structured
 * preferences the app and cron read directly (unlike UserInstructions, which is
 * free text steering the model).
 *
 * Keys are a fixed, self-documenting set (DEFS): adding a new per-user knob is one
 * entry here plus wherever reads it. Every query is hard-scoped to the user id.
 */
final class UserSettings
{
    /**
     * Known settings: key => { default, label, description }. The default is the
     * value used when the user hasn't set one.
     *
     * @var array<string, array{default:string, label:string, description:string}>
     */
    public const DEFS = [
        'work_calendar' => [
            'default'     => WorkLog::WORK_CALENDAR,
            'label'       => 'Work calendar',
            'description' => 'Name of the Google calendar whose events drive work-log tracking and the afternoon "what did you get done?" nudge.',
        ],
        'cycle_show_fertile' => [
            'default'     => 'off',
            'label'       => 'Show fertile window',
            'description' => 'Whether the cycle card shows the estimated fertile window ("on"/"off"). Off = show only phase and next period.',
        ],
        'theme' => [
            'default'     => 'aurora',
            'label'       => 'Appearance',
            'description' => 'The visual look / colour theme, one of: aurora (the default dark blue), noir '
                . '(dark monochrome with an amber accent), paper (clean light), lavender (soft violet), '
                . 'blush (soft pink), disco (neon on deep purple). Changes colours and corner rounding only.',
        ],
        'personality' => [
            'default'     => '2',
            'label'       => 'Assistant personality',
            'description' => 'How much characterful, context-aware personality the assistant\'s replies carry, '
                . 'on a scale of 1–5 (1 = off / plain and neutral; 5 = maximum, leans all the way in). It '
                . 'colours delivery only (e.g. a hyped gym-coach when logging workouts, a mood-matching '
                . 'weather presenter, warm encouragement around cycle tracking) and never changes facts or '
                . 'numbers.',
        ],
        'tax_reserve_pct' => [
            'default'     => '40',
            'label'       => 'Tax reserve %',
            'description' => 'The percentage of business profit (revenue − expenses) to set aside for income '
                . 'tax + AM-bidrag, used for the bookkeeping "reserve" estimate. A rough buffer so the owner '
                . 'does not overspend money that is really SKAT\'s — NOT a filed tax figure. Whole number 0–100 '
                . '(default 40).',
        ],
        'cash_opening' => [
            'default'     => '0',
            'label'       => 'Opening bank balance',
            'description' => 'The bank-account balance BEFORE any tracked movement — the anchor for the cash / '
                . 'expected-balance view. A brand-new business starts at 0; set it if Kachow should reconcile '
                . 'against an account that already had money in it. A number in DKK (default 0).',
        ],
        // Seller/company details for generated private invoices (the "from" block).
        'company_name' => [
            'default'     => '',
            'label'       => 'Company name',
            'description' => 'Your business name, shown as the sender on invoices you generate.',
        ],
        'company_cvr' => [
            'default'     => '',
            'label'       => 'CVR number',
            'description' => 'Your CVR (Danish business registration) number, shown on generated invoices.',
        ],
        'company_address' => [
            'default'     => '',
            'label'       => 'Company address',
            'description' => 'Your business address (street, postcode, city), shown on generated invoices. Use commas or line breaks between lines.',
        ],
        'company_email' => [
            'default'     => '',
            'label'       => 'Company email',
            'description' => 'Contact email shown on generated invoices.',
        ],
        'company_payment' => [
            'default'     => '',
            'label'       => 'Payment details',
            'description' => 'How clients pay you — e.g. "Reg 1234 Konto 5678901", an IBAN, or "MobilePay 12345". Shown on generated invoices.',
        ],
    ];

    /**
     * The seller/company profile for generated invoices.
     *
     * @return array{name:string, cvr:string, address:string, email:string, payment:string}
     */
    public function companyProfile(int $userId): array
    {
        return [
            'name'    => (string) ($this->get($userId, 'company_name') ?? ''),
            'cvr'     => (string) ($this->get($userId, 'company_cvr') ?? ''),
            'address' => (string) ($this->get($userId, 'company_address') ?? ''),
            'email'   => (string) ($this->get($userId, 'company_email') ?? ''),
            'payment' => (string) ($this->get($userId, 'company_payment') ?? ''),
        ];
    }

    /** The opening (anchor) bank balance in DKK for the cash view; default 0. */
    public function openingBalance(int $userId): float
    {
        return round((float) ($this->get($userId, 'cash_opening') ?? '0'), 2);
    }

    /** The tax-reserve percentage (0–100) for the reserve estimate; default 40. */
    public function reservePct(int $userId): int
    {
        $v = (int) round((float) ($this->get($userId, 'tax_reserve_pct') ?? '40'));

        return max(0, min(100, $v));
    }

    /** Interprets a stored setting value as a boolean (on/yes/true/1, incl. Danish ja). */
    public static function isTruthy(?string $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['on', 'yes', 'true', '1', 'ja'], true);
    }

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    public static function exists(string $key): bool
    {
        return isset(self::DEFS[$key]);
    }

    public static function defaultFor(string $key): ?string
    {
        return isset(self::DEFS[$key]) ? (string) self::DEFS[$key]['default'] : null;
    }

    /** Allowed values for the personality dial, in slider order (1 = off … 5 = max). */
    public const PERSONALITY_LEVELS = ['1', '2', '3', '4', '5'];

    /** Available visual themes (the client owns the actual palettes). */
    public const THEMES = ['aurora', 'noir', 'paper', 'lavender', 'blush', 'disco'];

    /** Any theme input → a known theme id (unknown → the default 'aurora'). */
    public static function normalizeTheme(?string $value): string
    {
        $v = strtolower(trim((string) $value));

        return in_array($v, self::THEMES, true) ? $v : 'aurora';
    }

    /** The renderable "appearance" picker card payload (kind: appearance). */
    public static function appearanceCard(string $theme): array
    {
        return ['kind' => 'appearance', 'theme' => self::normalizeTheme($theme)];
    }

    /**
     * Canonicalises any personality input to '1'..'5': accepts a number (clamped) and
     * maps legacy/spoken words (off/neutral → 1, subtle/light → 2, medium → 3, strong → 4,
     * full/max → 5). Unknown → '2'. Keeps old stored values and voice phrases working.
     */
    public static function normalizePersonality(?string $value): string
    {
        $v = strtolower(trim((string) $value));
        $legacy = [
            'off' => '1', 'neutral' => '1', 'none' => '1', 'subtle' => '2', 'light' => '2',
            'medium' => '3', 'moderate' => '3', 'strong' => '4', 'full' => '5', 'max' => '5',
        ];
        if (isset($legacy[$v])) {
            return $legacy[$v];
        }
        if (is_numeric($v)) {
            return (string) max(1, min(5, (int) round((float) $v)));
        }

        return '2';
    }

    /**
     * The renderable "personality slider" card payload (kind: personality). The client
     * draws the slider + bilingual labels/examples; the server just carries the level.
     *
     * @return array{kind:string, level:string}
     */
    public static function personalityCard(string $level): array
    {
        return ['kind' => 'personality', 'level' => self::normalizePersonality($level)];
    }

    /** @return array<int, string> the valid setting keys */
    public static function keys(): array
    {
        return array_keys(self::DEFS);
    }

    /** Current value for a key (stored value, else the default). Null if the key is unknown. */
    public function get(int $userId, string $key): ?string
    {
        if (!self::exists($key)) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT setting_value FROM user_settings WHERE user_id = :u AND setting_key = :k');
        $stmt->execute([':u' => $userId, ':k' => $key]);
        $v = $stmt->fetchColumn();

        return $v === false ? (string) self::DEFS[$key]['default'] : (string) $v;
    }

    /**
     * Sets a known key, owner-scoped. An empty value resets it to the default (deletes
     * the row). Returns false only if the key is unknown.
     */
    public function set(int $userId, string $key, string $value): bool
    {
        if (!self::exists($key)) {
            return false;
        }
        $value = trim($value);
        if ($value === '') {
            return $this->remove($userId, $key);
        }
        $value = mb_substr($value, 0, 255);

        $stmt = $this->db->prepare(
            'INSERT INTO user_settings (user_id, setting_key, setting_value) VALUES (:u, :k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute([':u' => $userId, ':k' => $key, ':v' => $value]);

        return true;
    }

    /** Resets a key to its default (removes any stored override), owner-scoped. */
    public function remove(int $userId, string $key): bool
    {
        $stmt = $this->db->prepare('DELETE FROM user_settings WHERE user_id = :u AND setting_key = :k');
        $stmt->execute([':u' => $userId, ':k' => $key]);

        return true;
    }

    /**
     * All known settings with the user's current value + default + metadata.
     *
     * @return array<int, array{key:string, label:string, description:string, value:string, default:string, is_custom:bool}>
     */
    public function all(int $userId): array
    {
        $stored = [];
        $stmt = $this->db->prepare('SELECT setting_key, setting_value FROM user_settings WHERE user_id = :u');
        $stmt->execute([':u' => $userId]);
        foreach ($stmt->fetchAll() as $r) {
            $stored[(string) $r['setting_key']] = (string) $r['setting_value'];
        }

        $out = [];
        foreach (self::DEFS as $key => $meta) {
            $out[] = [
                'key'         => $key,
                'label'       => $meta['label'],
                'description' => $meta['description'],
                'value'       => $stored[$key] ?? (string) $meta['default'],
                'default'     => (string) $meta['default'],
                'is_custom'   => isset($stored[$key]),
            ];
        }

        return $out;
    }
}
