<?php
declare(strict_types=1);

namespace LiveWorship;

final class Files
{
    private const MAX_BYTES = 16 * 1024 * 1024;
    private const MIME_EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public static function save(array $file, string $directoryName = 'song-pages'): array
    {
        $details = self::inspect($file);
        $mime = $details['mime'];
        if (!preg_match('/^[a-z-]+$/', $directoryName)) throw new ApiError(500, 'Invalid image storage area.');

        $relative = $directoryName . '/' . gmdate('Y/m') . '/' . bin2hex(random_bytes(20)) . '.' . self::MIME_EXTENSIONS[$mime];
        $root = self::root();
        $destination = $root . '/' . $relative;
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) throw new ApiError(500, 'Could not prepare song page storage.');
        if (!move_uploaded_file($file['tmp_name'], $destination)) throw new ApiError(500, 'Could not store the uploaded song page.');
        return ['path' => $relative, 'mime' => $mime, 'size' => $details['size']];
    }

    public static function inspect(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new ApiError(400, 'The image could not be uploaded.');
        $temporaryPath = $file['tmp_name'] ?? '';
        if (!is_string($temporaryPath) || $temporaryPath === '' || !is_file($temporaryPath)) throw new ApiError(400, 'The image could not be uploaded.');
        $size = filesize($temporaryPath);
        if ($size === false || $size < 1 || $size > self::MAX_BYTES) throw new ApiError(400, 'The image must be smaller than 16 MB.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
        if (!isset(self::MIME_EXTENSIONS[$mime]) || @getimagesize($temporaryPath) === false) {
            throw new ApiError(400, 'Choose a JPEG, PNG, or WebP image.');
        }
        return ['mime' => $mime, 'size' => $size];
    }

    public static function absolute(string $relative): string
    {
        if (str_contains($relative, '..') || str_starts_with($relative, '/')) throw new ApiError(404, 'Song page not found.');
        $path = self::root() . '/' . $relative;
        if (!is_file($path)) throw new ApiError(404, 'Song page not found.');
        return $path;
    }

    public static function remove(string $relative): void
    {
        try { $path = self::absolute($relative); if (is_file($path)) unlink($path); } catch (ApiError) { }
    }

    private static function root(): string
    {
        $configured = trim((string) getenv('LIVE_WORSHIP_STORAGE_DIR'));
        return $configured !== '' ? rtrim($configured, '/') : dirname(__DIR__) . '/storage';
    }
}
