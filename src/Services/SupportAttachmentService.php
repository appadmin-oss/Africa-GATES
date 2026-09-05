<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Evidence on a support ticket — stored privately, served only to whoever may see it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS DOES NOT REUSE THE PULSE MEDIA PATH
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The commonest attachment here will be a screenshot of a bank alert: an account
 * name, a balance, a masked card number, a transaction. {@see PulseMediaService}
 * exists and handles images well, and using it would have been the obvious move
 * and the wrong one — everything it does is built for PUBLISHING. World-readable
 * path, CDN bucket, third-party AI moderation.
 *
 * Here each of those is a fault:
 *
 *   PRIVATE BY CONSTRUCTION.  Files land outside the web root. There is no URL
 *   that serves them; the only way out is {@see stream()}, and the only way into
 *   that is {@see mayView()}. An unguessable public path would have been easier
 *   and would still have been a public path.
 *
 *   NOT SENT TO A MODERATOR.  Support evidence is volunteered in confidence.
 *   Shipping a stranger's bank alert to a third-party classifier to ask whether
 *   it contains nudity is a worse privacy failure than the one it guards against.
 *   The mitigation here is different in kind: the file is private, attributed,
 *   capped, and only ever seen by staff and the person who sent it.
 *
 * ── WHAT IS ACTUALLY LOAD-BEARING ────────────────────────────────────────────
 *
 * 1. finfo on the STORED BYTES decides the type. A file called `receipt.png`
 *    that is really a PHP script is rejected here.
 * 2. The extension written to disk comes from the DETECTED type, never from the
 *    name the browser sent.
 * 3. The file is outside the document root, so even if 1 and 2 were bypassed
 *    there is no request that reaches it.
 *
 * Point 3 is the one that matters. The other two are defence in depth.
 */
final class SupportAttachmentService
{
    /** Detected MIME → stored extension. Nothing else is kept. */
    private const TYPES = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'image/gif'       => 'gif',
        // PDF earns its place: bank statements and gateway receipts arrive as
        // one far more often than as a photograph of a screen.
        'application/pdf' => 'pdf',
    ];

    public const MAX_BYTES = 8 * 1024 * 1024;
    public const MAX_PER_MESSAGE = 4;

    /** Outside the web root. Nothing under public/ ever holds one of these. */
    public static function root(): string
    {
        return dirname(__DIR__, 2) . '/var/uploads/support';
    }

    /**
     * The ceiling a person is actually told about.
     *
     * The SMALLER of our limit and what PHP will accept, because a shared host
     * with `upload_max_filesize = 2M` silently discards anything larger and the
     * browser reports a successful POST with no file in it. Promising 8MB on a
     * server that drops 3MB is how you get a bug nobody can reproduce.
     */
    public static function limitBytes(): int
    {
        $toBytes = static function (string $v): int {
            $v = trim($v);
            if ($v === '') return 0;
            $unit = strtolower(substr($v, -1));
            $n = (int) $v;
            return match ($unit) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
        };
        $caps = array_filter([
            self::MAX_BYTES,
            $toBytes((string) ini_get('upload_max_filesize')),
            $toBytes((string) ini_get('post_max_size')),
        ]);
        return $caps ? (int) min($caps) : self::MAX_BYTES;
    }

    public static function humanLimit(): string
    {
        return round(self::limitBytes() / 1048576, 1) . 'MB';
    }

    /** What the file input should advertise, and what the server enforces. */
    public static function acceptAttribute(): string
    {
        return 'image/jpeg,image/png,image/webp,image/gif,application/pdf';
    }

    /**
     * Store one piece of evidence against a ticket.
     *
     * @return array{ok:bool, id?:int, message?:string}
     */
    public static function store(
        UploadedFileInterface $file,
        int $ticketId,
        ?int $messageId = null,
        string $uploaderType = 'member',
        ?int $uploaderId = null,
    ): array {
        $err = $file->getError();
        if ($err === UPLOAD_ERR_NO_FILE) return ['ok' => false, 'message' => 'No file was received.'];
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            return ['ok' => false, 'message' => 'That file is larger than this server accepts (' . self::humanLimit() . ').'];
        }
        if ($err !== UPLOAD_ERR_OK) return ['ok' => false, 'message' => 'The upload did not complete. Please try again.'];

        $size = (int) $file->getSize();
        if ($size <= 0)                    return ['ok' => false, 'message' => 'That file is empty.'];
        if ($size > self::limitBytes())    return ['ok' => false, 'message' => 'Please keep attachments under ' . self::humanLimit() . '.'];

        $dir = self::root() . '/' . date('Y-m');
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return ['ok' => false, 'message' => 'Attachments are unavailable right now.'];
        }

        // Land it first, then ask the BYTES what it is. A name and a Content-Type
        // are both things the client chose.
        $tmp = $dir . '/.incoming-' . bin2hex(random_bytes(12));
        try { $file->moveTo($tmp); }
        catch (\Throwable) { return ['ok' => false, 'message' => 'The upload could not be saved.']; }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $ext  = self::TYPES[$mime] ?? null;
        if ($ext === null) {
            @unlink($tmp);
            return ['ok' => false, 'message' => 'Attach a photo (JPEG, PNG, WebP, GIF) or a PDF.'];
        }

        $w = null; $h = null;
        if ($mime !== 'application/pdf') {
            $dim = @getimagesize($tmp);
            if ($dim === false) { @unlink($tmp); return ['ok' => false, 'message' => 'That image could not be read.']; }
            $w = (int) $dim[0]; $h = (int) $dim[1];
        }

        $name = date('Y-m') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        if (!@rename($tmp, self::root() . '/' . $name)) {
            @unlink($tmp);
            return ['ok' => false, 'message' => 'The upload could not be saved.'];
        }
        @chmod(self::root() . '/' . $name, 0640);

        try {
            $id = (int) DB::table('gates_support_attachments')->insertGetId([
                'ticket_id'     => $ticketId,
                'message_id'    => $messageId,
                'uploader_type' => $uploaderType === 'staff' ? 'staff' : 'member',
                'uploader_id'   => $uploaderId,
                'storage_path'  => $name,
                // Kept for display only, and clipped. Never used to build a path.
                'original_name' => mb_substr(preg_replace('/[\x00-\x1F\/\\\\]+/', '', (string) $file->getClientFilename()) ?: 'attachment', 0, 200),
                'mime'          => $mime,
                'bytes'         => $size,
                'width'         => $w,
                'height'        => $h,
                'created_at'    => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable) {
            @unlink(self::root() . '/' . $name);
            return ['ok' => false, 'message' => 'The attachment could not be recorded.'];
        }

        return ['ok' => true, 'id' => $id];
    }

    /**
     * Attach any files on a request to a ticket, capped and best-effort.
     *
     * Best-effort deliberately: a rejected screenshot must never lose somebody's
     * written message. The reasons come back so the caller can say what happened.
     *
     * @param  array<int,UploadedFileInterface>|UploadedFileInterface|null $files
     * @return array{stored:int, problems:array<int,string>}
     */
    public static function attachAll(
        $files,
        int $ticketId,
        ?int $messageId,
        string $uploaderType = 'member',
        ?int $uploaderId = null,
    ): array {
        if ($files === null) return ['stored' => 0, 'problems' => []];
        if ($files instanceof UploadedFileInterface) $files = [$files];
        if (!is_array($files)) return ['stored' => 0, 'problems' => []];

        $stored = 0; $problems = [];
        $n = 0;
        foreach ($files as $f) {
            if (!$f instanceof UploadedFileInterface) continue;
            if ($f->getError() === UPLOAD_ERR_NO_FILE) continue;
            if (++$n > self::MAX_PER_MESSAGE) {
                $problems[] = 'Only ' . self::MAX_PER_MESSAGE . ' attachments per message were kept.';
                break;
            }
            $r = self::store($f, $ticketId, $messageId, $uploaderType, $uploaderId);
            if (!empty($r['ok'])) $stored++;
            else $problems[] = (string) ($r['message'] ?? 'One attachment could not be saved.');
        }
        return ['stored' => $stored, 'problems' => $problems];
    }

    /** @return array<int,object> live attachments on a ticket */
    public static function forTicket(int $ticketId): array
    {
        try {
            return DB::table('gates_support_attachments')
                ->where('ticket_id', $ticketId)->whereNull('deleted_at')
                ->orderBy('id')->get()->all();
        } catch (\Throwable) { return []; }
    }

    /** @return array<int,array<int,object>> message_id => attachments */
    public static function byMessage(int $ticketId): array
    {
        $out = [];
        foreach (self::forTicket($ticketId) as $a) {
            $out[(int) ($a->message_id ?? 0)][] = $a;
        }
        return $out;
    }

    /**
     * May this request see this attachment?
     *
     * Three legitimate viewers and no fourth: staff, the signed-link holder, and
     * the signed-in member whose ticket it is. Everything else is a 404 rather
     * than a 403 — telling a stranger that an attachment exists is itself a
     * disclosure, and this codebase already makes that distinction for tickets.
     *
     * @param  string|null $linkToken the account-free thread token, if one was presented
     */
    public static function mayView(object $att, ?int $adminId, ?int $userId, ?string $linkToken): bool
    {
        if ($adminId !== null && $adminId > 0) return true;

        $ticketId = (int) $att->ticket_id;

        if ($linkToken !== null && $linkToken !== '') {
            try {
                $who = TicketLinkService::resolve($linkToken);
                if ($who !== null && (int) ($who['ticket_id'] ?? 0) === $ticketId) return true;
            } catch (\Throwable) {}
        }

        if ($userId !== null && $userId > 0) {
            try {
                $owns = DB::table('gates_support_tickets')
                    ->where('id', $ticketId)->where('user_id', $userId)->exists();
                if ($owns) return true;
            } catch (\Throwable) {}
        }

        return false;
    }

    /** Absolute path on disk, or null when the row points at nothing. */
    public static function pathOf(object $att): ?string
    {
        $rel = (string) $att->storage_path;
        // Belt and braces: a stored path is generated here and can only ever be
        // "YYYY-MM/<hex>.<ext>", but a traversal in that column would be a
        // filesystem read primitive and it costs nothing to refuse one.
        if ($rel === '' || str_contains($rel, '..') || str_starts_with($rel, '/')) return null;
        $abs = self::root() . '/' . $rel;
        return is_file($abs) ? $abs : null;
    }
}
