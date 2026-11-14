<?php
declare(strict_types=1);
namespace AfricaGates\Support;

/** Effective host ceilings, shared by upload controls and request validation. */
final class UploadLimits
{
    public static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || (float) $value <= 0) return 0;
        $power = ['k' => 1, 'm' => 2, 'g' => 3][strtolower(substr($value, -1))] ?? 0;
        return (int) ((float) $value * (1024 ** $power));
    }
    public static function imageBytes(): int
    {
        $limits = [10 * 1024 * 1024];
        foreach (['upload_max_filesize', 'post_max_size'] as $key) {
            $bytes = self::bytes((string) ini_get($key));
            if ($bytes > 0) $limits[] = $key === 'post_max_size' ? max(1, $bytes - 65536) : $bytes;
        }
        return min($limits);
    }
    public static function label(int $bytes): string { return rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.') . ' MB'; }
    public static function error(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'This photo is too large for the server. Use a file under ' . self::label(self::imageBytes()) . '.',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Please select the photo and try again.',
            UPLOAD_ERR_NO_FILE => 'Choose a photo first.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'The server could not store your photo. Please try again or contact support.',
            default => 'The photo could not be uploaded. Please try again.',
        };
    }
}
