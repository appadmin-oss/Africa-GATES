<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Psr\Http\Message\UploadedFileInterface;

/**
 * A NOMINEE'S VOICE THANK-YOU (owner, 16 Nov 2026: "build them all"): up to
 * {@see MAX_SECONDS} seconds, recorded on their own page, played to everybody who opens their
 * ballot and to the person who has just voted. Hearing the person you backed say thank you in
 * their own voice is the thing a typed message cannot do.
 *
 * One clip per nominee; recording again replaces it, and the old file goes only once the new
 * one is safely down. Stored OUTSIDE the web root (var/uploads/nominee-voice), as
 * QuestionnaireIntro stores a nominee's introduction, and served by a route that names the
 * nominee — so taking it down takes it off the internet at once, rather than leaving it at a
 * guessable /uploads/ address for as long as somebody kept the link.
 *
 * The bytes are checked, not the client's word: finfo must read an audio (or WebM) container,
 * because the file is played to the public under our name.
 */
final class NomineeVoice
{
    public const MAX_SECONDS = 30;

    /** Thirty seconds of Opus is ~120KB; of AAC ~500KB. A ceiling, not a target. */
    public const MAX_BYTES = 3_145_728;

    /** What finfo reports for what a browser's MediaRecorder produces. */
    private const TYPES = [
        'audio/webm' => 'webm', 'video/webm' => 'webm', 'audio/ogg' => 'ogg', 'application/ogg' => 'ogg',
        'audio/mp4' => 'm4a', 'video/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/m4a' => 'm4a',
        'audio/mpeg' => 'mp3', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/vnd.wave' => 'wav',
    ];

    public static function ready(): bool
    {
        return SchemaHas::table('gates_nominee_voice');
    }

    public static function forNominee(int $nomineeId): ?object
    {
        if ($nomineeId < 1 || !self::ready()) return null;
        try {
            $v = DB::table('gates_nominee_voice')->where('nominee_id', $nomineeId)->first();
        } catch (\Throwable) {
            return null;
        }
        return $v && self::fileFor($v) !== null ? $v : null;
    }

    /** @return array{ok:bool, error?:string} */
    public static function record(int $nomineeId, int $userId, ?UploadedFileInterface $file, int $seconds = 0): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'Voice thank-yous are not switched on yet.'];
        if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) return ['ok' => false, 'error' => 'Record your thank-you first.'];
        if ($file->getError() !== UPLOAD_ERR_OK) return ['ok' => false, 'error' => 'That recording did not finish uploading. A shorter one usually gets through on a weak connection.'];
        $size = (int) ($file->getSize() ?? 0);
        if ($size <= 0) return ['ok' => false, 'error' => 'That recording was empty.'];
        if ($size > self::MAX_BYTES) return ['ok' => false, 'error' => 'That recording is too long. Keep it under ' . self::MAX_SECONDS . ' seconds.'];
        $dir = self::dir();
        if ($dir === null) return ['ok' => false, 'error' => 'We could not save the recording just now. Please try again.'];

        $tmp = $dir . '/.in-' . bin2hex(random_bytes(8));
        try {
            $file->moveTo($tmp);
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'We could not save the recording just now. Please try again.'];
        }
        $real = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp));
        $ext = self::TYPES[$real] ?? null;
        if ($ext === null) {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'That is not a recording we can play. Record it again here, or upload an audio file.'];
        }
        $name = 'voice-' . $nomineeId . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!@rename($tmp, $dir . '/' . $name)) {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'We could not save the recording just now. Please try again.'];
        }
        $prev = self::forNominee($nomineeId);
        // Trusted from the client — only the client has a clock on the recording — but clamped,
        // because it is printed beside the player.
        $row = ['path' => $name, 'seconds' => max(0, min(self::MAX_SECONDS, $seconds)), 'user_id' => $userId, 'created_at' => Carbon::now()->toDateTimeString()];
        try {
            if (DB::table('gates_nominee_voice')->where('nominee_id', $nomineeId)->exists()) DB::table('gates_nominee_voice')->where('nominee_id', $nomineeId)->update($row);
            else DB::table('gates_nominee_voice')->insert($row + ['nominee_id' => $nomineeId]);
        } catch (\Throwable) {
            @unlink($dir . '/' . $name);
            return ['ok' => false, 'error' => 'We could not save the recording just now. Please try again.'];
        }
        if ($prev !== null && (string) $prev->path !== $name) @unlink($dir . '/' . basename((string) $prev->path));
        return ['ok' => true];
    }

    /** Take it down: off the page and off the disk. */
    public static function remove(int $nomineeId): bool
    {
        $v = self::forNominee($nomineeId);
        if ($v === null) return false;
        DB::table('gates_nominee_voice')->where('nominee_id', $nomineeId)->delete();
        $f = self::fileFor($v);
        if ($f !== null) @unlink($f);
        return true;
    }

    public static function fileFor(object $v): ?string
    {
        $name = basename(trim((string) ($v->path ?? '')));
        $dir = self::dir(false);
        if ($name === '' || $dir === null) return null;
        return is_file($dir . '/' . $name) ? $dir . '/' . $name : null;
    }

    public static function mimeFor(string $path): string
    {
        return match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
            'm4a' => 'audio/mp4', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', default => 'audio/webm',
        };
    }

    /** Overridable so a test writes somewhere it can clean up. */
    public static ?string $root = null;

    private static function dir(bool $create = true): ?string
    {
        $dir = self::$root ?? dirname(__DIR__, 2) . '/var/uploads/nominee-voice';
        if (is_dir($dir)) return is_writable($dir) ? $dir : null;
        if (!$create) return null;
        return (@mkdir($dir, 0775, true) || is_dir($dir)) ? $dir : null;
    }
}
