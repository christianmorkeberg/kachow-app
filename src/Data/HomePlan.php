<?php

declare(strict_types=1);

namespace App\Data;

use App\Database;
use PDO;

/**
 * The household's floor plan for the Home card: a private image plus tappable areas (polygons in the image's own coordinates), each tied to a Hue room by name
 * fragments (`match`). Lives in the DB, never in the public repos. Tolerant of the table being missing.
 */
final class HomePlan
{
    public const MAX_IMAGE = 3_000_000;
    public const MAX_AREAS = 24;
    public const MAX_POINTS = 400;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::get();
    }

    /**
     * Checks and cleans a config: sizes, names, polygons of at least 3 points inside the image. Returns the clean array or an error string.
     *
     * @param mixed $c
     * @return array<string, mixed>|string
     */
    public static function clean(mixed $c): array|string
    {
        if (!is_array($c) || !isset($c['w'], $c['h']) || (float) $c['w'] < 100 || (float) $c['h'] < 100 || (float) $c['w'] > 10000 || (float) $c['h'] > 10000) {
            return 'The plan needs w and h (the image size in pixels).';
        }
        $w = (float) $c['w'];
        $h = (float) $c['h'];
        $areas = [];
        foreach (array_slice((array) ($c['areas'] ?? []), 0, self::MAX_AREAS) as $a) {
            if (!is_array($a) || trim((string) ($a['label'] ?? '')) === '') {
                return 'Every area needs a label.';
            }
            $polys = [];
            foreach ((array) ($a['polygons'] ?? []) as $p) {
                $pts = [];
                foreach (array_slice((array) $p, 0, self::MAX_POINTS) as $pt) {
                    if (!is_array($pt) || count($pt) < 2 || !is_numeric($pt[0]) || !is_numeric($pt[1])) {
                        return 'A polygon point must be [x, y].';
                    }
                    $pts[] = [round(max(0.0, min($w, (float) $pt[0])), 1), round(max(0.0, min($h, (float) $pt[1])), 1)];
                }
                if (count($pts) >= 3) {
                    $polys[] = $pts;
                }
            }
            if ($polys === []) {
                return 'Area "' . $a['label'] . '" has no polygon (at least 3 points).';
            }
            $areas[] = [
                'key'      => preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($a['key'] ?? $a['label']))) ?: 'area' . count($areas),
                'label'    => mb_substr(trim((string) $a['label']), 0, 40),
                'match'    => array_values(array_filter(array_map(static fn ($m): string => mb_strtolower(mb_substr(trim((string) $m), 0, 40)), (array) ($a['match'] ?? [])), static fn (string $m): bool => $m !== '')),
                'polygons' => $polys,
            ];
        }
        if ($areas === []) {
            return 'Add at least one area.';
        }

        return ['w' => $w, 'h' => $h, 'areas' => $areas];
    }

    /** @return array{config: ?array<string, mixed>, has_image: bool, version: string}|null */
    public function get(int $ownerId): ?array
    {
        try {
            $st = $this->db->prepare('SELECT config, (image IS NOT NULL) AS has_image, updated_at FROM home_plans WHERE user_id = :u');
            $st->execute([':u' => $ownerId]);
            $r = $st->fetch();
        } catch (\Throwable) {
            return null;   // not migrated yet
        }
        if ($r === false) {
            return null;
        }

        return ['config' => $r['config'] !== null ? json_decode((string) $r['config'], true) : null, 'has_image' => (int) $r['has_image'] === 1, 'version' => md5((string) $r['updated_at'])];
    }

    /** @return array{mime:string, bytes:string, version:string}|null */
    public function image(int $ownerId): ?array
    {
        try {
            $st = $this->db->prepare('SELECT mime, image, updated_at FROM home_plans WHERE user_id = :u AND image IS NOT NULL');
            $st->execute([':u' => $ownerId]);
            $r = $st->fetch();
        } catch (\Throwable) {
            return null;
        }

        return $r === false ? null : ['mime' => (string) $r['mime'], 'bytes' => (string) $r['image'], 'version' => md5((string) $r['updated_at'])];
    }

    /** Saves the image and/or the config (whichever is given). */
    public function save(int $ownerId, ?string $imageBytes, ?string $mime, ?array $config): void
    {
        $exists = $this->get($ownerId) !== null;
        if (!$exists) {
            $this->db->prepare('INSERT INTO home_plans (user_id) VALUES (:u)')->execute([':u' => $ownerId]);
        }
        if ($imageBytes !== null) {
            $this->db->prepare('UPDATE home_plans SET image = :i, mime = :m WHERE user_id = :u')->execute([':i' => $imageBytes, ':m' => $mime ?: 'image/jpeg', ':u' => $ownerId]);
        }
        if ($config !== null) {
            $this->db->prepare('UPDATE home_plans SET config = :c WHERE user_id = :u')->execute([':c' => json_encode($config, JSON_UNESCAPED_UNICODE), ':u' => $ownerId]);
        }
    }

    public function remove(int $ownerId): void
    {
        $this->db->prepare('DELETE FROM home_plans WHERE user_id = :u')->execute([':u' => $ownerId]);
    }
}
