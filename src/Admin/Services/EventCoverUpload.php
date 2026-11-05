<?php
declare(strict_types=1);
namespace AfricaGates\Admin\Services;
use Psr\Http\Message\UploadedFileInterface;

/** Store a local cover so the public page and invitation attachments share a source. */
final class EventCoverUpload
{
    public static function save(UploadedFileInterface $file, int $eventId, ?UploadService $uploads = null): string
    {
        $image = ($uploads ?? new UploadService())->uploadImage($file, 'event-covers', 1800, 85,
            (int) ($_SESSION['admin_id'] ?? 0) ?: null, 'site_event', $eventId ?: null, 300);
        return ltrim((string) $image['local'], '/');
    }
}
