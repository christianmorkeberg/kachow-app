<?php

declare(strict_types=1);

namespace App\Receipts;

use Imagick;
use RuntimeException;

/**
 * Stores receipt images OUTSIDE the webroot. Every upload is normalised to a
 * downscaled JPEG (handles iPhone HEIC, strips EXIF, bounds file size) via
 * Imagick, then saved under uploads/receipts/<userId>/<random>.jpg. Only the
 * filename is kept in the DB; files are served through an auth'd endpoint.
 */
final class ReceiptStorage
{
    private const MAX_BYTES = 12 * 1024 * 1024; // 12 MB upload cap
    private const MAX_EDGE  = 2000;             // downscale longest edge
    private const QUALITY   = 85;

    /** Accepted incoming image types (converted to JPEG on store). */
    private const ACCEPTED = ['image/jpeg', 'image/png', 'image/heic', 'image/heif', 'image/webp'];

    private string $baseDir;

    public function __construct(?string $baseDir = null)
    {
        // Default: a sibling "uploads" dir next to the app root, outside the webroot.
        // __DIR__ = …/assistant-app/src/Receipts → up 3 = the app root's parent
        // (/home/kachowdk on the server) → /home/kachowdk/uploads/receipts.
        $this->baseDir = rtrim($baseDir ?? dirname(__DIR__, 3) . '/uploads/receipts', '/');
    }

    /**
     * Validates + normalises an uploaded file to JPEG and stores it.
     *
     * @return array{file_ref:string, mime:string} the stored filename + its mime
     * @throws RuntimeException on an invalid or unreadable image
     */
    public function store(int $userId, string $tmpPath, int $size): array
    {
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new RuntimeException('That image is too large (max 12 MB).');
        }
        $mime = $this->detectMime($tmpPath);
        if (!in_array($mime, self::ACCEPTED, true)) {
            throw new RuntimeException('Please upload a photo (JPEG, PNG, HEIC or WebP).');
        }

        $dir = $this->userDir($userId);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not prepare storage for the receipt.');
        }

        $name = bin2hex(random_bytes(16)) . '.jpg';
        $dest = $dir . '/' . $name;

        try {
            $img = new Imagick($tmpPath);
            $img->setFirstIterator();
            if (method_exists($img, 'autoOrient')) {
                $img->autoOrient();
            }
            $img->setImageFormat('jpeg');
            $img->resizeImage(self::MAX_EDGE, self::MAX_EDGE, Imagick::FILTER_LANCZOS, 1, true);
            $img->stripImage();
            $img->setImageCompressionQuality(self::QUALITY);
            $img->writeImage($dest);
            $img->clear();
        } catch (\Throwable $e) {
            throw new RuntimeException('That image could not be read. Try another photo.');
        }

        return ['file_ref' => $name, 'mime' => 'image/jpeg'];
    }

    /** Absolute path to a stored file, or null if the ref is unsafe/missing. */
    public function pathFor(int $userId, string $fileRef): ?string
    {
        $fileRef = basename($fileRef); // defence against traversal
        $path = $this->userDir($userId) . '/' . $fileRef;

        return is_file($path) ? $path : null;
    }

    public function delete(int $userId, string $fileRef): void
    {
        $path = $this->pathFor($userId, $fileRef);
        if ($path !== null) {
            @unlink($path);
        }
    }

    private function userDir(int $userId): string
    {
        return $this->baseDir . '/' . $userId;
    }

    private function detectMime(string $path): string
    {
        $f = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $f !== false ? (string) finfo_file($f, $path) : '';
        if ($f !== false) {
            finfo_close($f);
        }

        return strtolower($mime);
    }
}
