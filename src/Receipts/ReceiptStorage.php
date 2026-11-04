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

    /** Non-image documents stored verbatim (not converted) — invoices are often PDFs. */
    private const ACCEPTED_DOC = ['application/pdf'];

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
            throw new RuntimeException('That file is too large (max 12 MB).');
        }
        $mime = $this->detectMime($tmpPath);
        $isDoc = in_array($mime, self::ACCEPTED_DOC, true);
        if (!$isDoc && !in_array($mime, self::ACCEPTED, true)) {
            throw new RuntimeException('Please upload an image (JPEG, PNG, HEIC, WebP) or a PDF.');
        }

        $dir = $this->userDir($userId);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not prepare storage for the file.');
        }

        // PDFs (and other documents) are stored verbatim — no image conversion.
        if ($isDoc) {
            $docName = bin2hex(random_bytes(16)) . '.pdf';
            $docDest = $dir . '/' . $docName;
            if (!@copy($tmpPath, $docDest)) {
                throw new RuntimeException('Could not store that file. Try again.');
            }
            return ['file_ref' => $docName, 'mime' => 'application/pdf'];
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

    /** Document types an ATTACHMENT may be (stored as they are, never run or shown inline): extension → mime. */
    public const ATTACHMENT_TYPES = [
        'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'image/heic', 'heif' => 'image/heif',
        'csv' => 'text/csv', 'txt' => 'text/plain',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xls' => 'application/vnd.ms-excel',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'doc' => 'application/msword',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet', 'odt' => 'application/vnd.oasis.opendocument.text',
    ];

    /**
     * Stores any allowed document verbatim (an hour schedule, a contract, a spreadsheet …) under a random name; the original name is for display only.
     *
     * @return array{file_ref:string, mime:string, name:string, size:int}
     * @throws RuntimeException when the type is not allowed, the file is too large or cannot be stored
     */
    public function storeFile(int $userId, string $tmpPath, int $size, string $originalName): array
    {
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new RuntimeException('That file is too large (max 12 MB).');
        }
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!isset(self::ATTACHMENT_TYPES[$ext])) {
            throw new RuntimeException('That file type is not allowed. Use PDF, an image, CSV, Excel, Word or a text file.');
        }
        $dir = $this->userDir($userId);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not prepare storage for the file.');
        }
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!@copy($tmpPath, $dir . '/' . $name)) {
            throw new RuntimeException('Could not store that file. Try again.');
        }
        $display = trim(preg_replace('/[^\p{L}\p{N} ._()\-]+/u', '_', basename($originalName)) ?? '') ?: ('file.' . $ext);

        return ['file_ref' => $name, 'mime' => self::ATTACHMENT_TYPES[$ext], 'name' => mb_substr($display, 0, 160), 'size' => $size];
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
