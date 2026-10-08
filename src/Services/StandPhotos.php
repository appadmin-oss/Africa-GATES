<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Admin\Services\UploadService;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The photographs on a stand application: what is stored, who may see them, and when.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE COUNTER IN THE FORM IS NOT THE RULE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The form shows "3 of 6 added · minimum 3", and that is a convenience. The rule is here,
 * because the endpoint is reachable without the form: the minimum decides whether an
 * application is COMPLETE, and completeness is the published tiebreak in §5.4. A limit
 * enforced only in a template is a limit anyone can step around, and the thing they would
 * be stepping around is the ranking.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND WHY THE UPLOAD IS SOMEBODY ELSE'S PROBLEM
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every byte goes through {@see UploadService::uploadImage()}, which sniffs the bytes with
 * finfo rather than believing the client's Content-Type, caps at 10 MB, re-encodes the
 * image, and files it under `uploads/{bucket}/YYYY/MM/{uuid}.{ext}`. A second upload path
 * on this platform would be a second place for that discipline to be forgotten, and the
 * one that forgot is the one an attacker uses.
 *
 * The only rule added on top is a floor on the dimensions. A 200px thumbnail tells a
 * scorer nothing, and rejecting it at upload is kinder than accepting it and having the
 * application read as though the vendor could not be bothered.
 */
final class StandPhotos
{
    /** Below this an application is not complete, and below this a form cannot be sent. */
    public const MIN = 3;

    /**
     * The day photographs became a submit gate rather than a completeness note.
     *
     * ── WHY A DATE AND NOT A COLUMN ──────────────────────────────────────────
     *
     * A call that was ALREADY OPEN when this shipped published one set of rules, and
     * vendors have been part-way through filling that form — some of them for weeks,
     * some of them without the photographs yet because the form told them they could
     * upload from the dashboard afterwards. Tightening a requirement under somebody
     * mid-application is the kind of change that loses a market its traders.
     *
     * A column would have to be backfilled, and the only value it could be backfilled
     * from is this same date. So the date is the mechanism, stated once, rather than
     * copied into every existing row and then believed.
     *
     * The transition ends on its own: every call opened from here carries the gate, and
     * once the last pre-existing call closes this constant stops being consulted. It is
     * deliberately NOT a setting — an operator who could turn it off would be turning off
     * the evidence the panel scores.
     */
    public const REQUIRED_FROM = '2026-08-29';

    /** Above this the extra photographs are not read, so they are not accepted. */
    public const MAX = 6;

    /**
     * The shortest edge a usable photograph has.
     *
     * The handoff asks for ~600 on the LONG edge; {@see UploadService} checks both, which
     * is stricter and is the codebase's own mechanic rather than a second implementation
     * beside it. Every photograph a phone takes clears it; what it stops is a 200px
     * thumbnail and an image so elongated that the 4/3 tile would be mostly crop.
     */
    public const MIN_EDGE = 600;

    public const BUCKET = 'stand-photos';

    /**
     * Must this call's applicants attach photographs before they can send the form?
     *
     * A call with no opening date recorded is treated as new: the only rows without one
     * are drafts, which have no applicants to grandfather.
     */
    public static function requiredForCall(?object $call): bool
    {
        $opened = trim((string) ($call->opens_at ?? ''));
        if ($opened === '') return true;

        // Compared as DATES, not strings. MySQL hands back "2026-08-29 09:00:00" and
        // SQLite hands back whatever was written — including the T-separated form, which
        // string-compares wrong against a space-separated literal. This codebase has
        // shipped that bug once already; see CLAUDE.md.
        return strtotime($opened) >= strtotime(self::REQUIRED_FROM);
    }

    /** What this codebase would allow per photograph, before the host has its say. */
    public const WANT_BYTES = 10 * 1024 * 1024;

    /**
     * The per-photograph ceiling a vendor is actually told about.
     *
     * ── THE BUG THIS EXISTS TO END ───────────────────────────────────────────
     *
     * The form advertised a flat 10MB and six photographs. This host accepts
     * `upload_max_filesize = 2M` and `post_max_size = 8M`. A vendor attaching three
     * photographs off a phone — 3 to 5MB each is ordinary — exceeded `post_max_size`, and
     * PHP does not reject that request: it DISCARDS THE WHOLE BODY. `$_POST` arrives
     * empty, so the controller read no stand type and answered "Choose which kind of stand
     * you want" — naming a field they had filled in, saying nothing about photographs, on a
     * form they had just spent twenty minutes on.
     *
     * The platform already knows this trap; {@see SupportAttachmentService::limitBytes()}
     * and {@see PulseMediaService} both read these ini values for exactly this reason. The
     * stand form was the one that did not.
     *
     * The SMALLER of the three, because promising 10MB on a host that drops 2MB is how you
     * get a bug nobody can reproduce.
     */
    public static function limitBytes(): int
    {
        $caps = array_filter([
            self::WANT_BYTES,
            self::iniBytes('upload_max_filesize'),
            self::iniBytes('post_max_size'),
        ]);

        return $caps === [] ? self::WANT_BYTES : (int) min($caps);
    }

    /**
     * What the whole POST may weigh — photographs, form fields and multipart overhead.
     *
     * Separate from the per-file cap and not derivable from it: `post_max_size` governs the
     * REQUEST, so six files that each clear `upload_max_filesize` can still add up to a
     * body PHP throws away. The form needs both numbers to keep a running total, and 512KB
     * is left for the rest of the multipart body — the typed fields, the boundaries and the
     * headers — because a budget spent exactly to the limit is a body over it.
     */
    public static function requestBudgetBytes(): int
    {
        $post = self::iniBytes('post_max_size');
        if ($post <= 0) return self::WANT_BYTES * self::MAX;   // 0 means unlimited

        return max(0, $post - 512 * 1024);
    }

    /** "2 MB", for a sentence a vendor reads. */
    public static function humanLimit(): string
    {
        return self::human(self::limitBytes());
    }

    public static function human(int $bytes): string
    {
        return $bytes >= 1048576
            ? rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.') . ' MB'
            : max(1, (int) round($bytes / 1024)) . ' KB';
    }

    /** An ini shorthand ("8M", "512K") in bytes. 0 when unset or unlimited. */
    private static function iniBytes(string $key): int
    {
        $v = trim((string) ini_get($key));
        if ($v === '' || $v === '-1') return 0;

        $n = (int) $v;

        return match (strtolower(substr($v, -1))) {
            'g'     => $n * 1073741824,
            'm'     => $n * 1048576,
            'k'     => $n * 1024,
            default => $n,
        };
    }

    /**
     * Every photograph on an application, cover first.
     *
     * @return list<array{id:int, path:string, width:int, height:int, sort_order:int, cover:bool}>
     */
    public static function forApplication(int $applicationId): array
    {
        if ($applicationId < 1) return [];

        try {
            $rows = DB::table('gates_stand_application_photos')
                ->where('application_id', $applicationId)
                ->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'path', 'width', 'height', 'sort_order']);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $i => $r) {
            $out[] = [
                'id'         => (int) $r->id,
                'path'       => (string) $r->path,
                'width'      => (int) $r->width,
                'height'     => (int) $r->height,
                'sort_order' => (int) $r->sort_order,
                // The FIRST row, not `sort_order === 0`. A gap left by a deletion would
                // otherwise leave an application with photographs and no cover.
                'cover'      => $i === 0,
            ];
        }
        return $out;
    }

    public static function count(int $applicationId): int
    {
        try {
            return (int) DB::table('gates_stand_application_photos')
                ->where('application_id', $applicationId)->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Add one photograph.
     *
     * @return array{ok:bool, message:string, photo?:array<string,mixed>}
     */
    public static function add(int $applicationId, int $orgId, UploadedFileInterface $file,
                               ?UploadService $uploads = null): array
    {
        if ($applicationId < 1 || $orgId < 1) {
            return ['ok' => false, 'message' => 'Unknown application.'];
        }

        $have = self::count($applicationId);
        if ($have >= self::MAX) {
            return ['ok' => false, 'message' => 'That is ' . self::MAX
                . ' photographs already — remove one before adding another.'];
        }

        try {
            $r = ($uploads ?? new UploadService())->uploadImage(
                $file,
                self::BUCKET,
                1600,
                82,
                null,
                'stand_application',
                $applicationId,
                self::MIN_EDGE,
            );
        } catch (\Throwable $e) {
            // The message from UploadService already says what is wrong with the file and
            // what to do about it — an unsupported type names the type, a small image names
            // the size. Replacing it with "upload failed" would throw that away.
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        try {
            $id = (int) DB::table('gates_stand_application_photos')->insertGetId([
                'application_id' => $applicationId,
                'org_id'         => $orgId,
                'path'           => (string) $r['path'],
                'width'          => (int) $r['width'],
                'height'         => (int) $r['height'],
                'bytes'          => (int) $r['size'],
                'sort_order'     => $have,
                'created_at'     => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'The photograph uploaded but could not be saved. Try again.'];
        }

        // A photograph can be the thing that makes an application complete, and completeness
        // is stamped once and never moved. Doing it here rather than leaving it to the next
        // save means the clock starts at the upload, which is when it became true.
        StandApplication::refreshCompleteness($applicationId);

        return ['ok' => true, 'message' => 'Added.', 'photo' => [
            'id'    => $id,
            'path'  => (string) $r['path'],
            'width' => (int) $r['width'],
            'height'=> (int) $r['height'],
            'cover' => $have === 0,
        ]];
    }

    /**
     * Remove one, and close the gap it left.
     *
     * @return array{ok:bool, message:string}
     */
    public static function remove(int $applicationId, int $photoId): array
    {
        try {
            $n = DB::table('gates_stand_application_photos')
                ->where('id', $photoId)->where('application_id', $applicationId)->delete();
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'Could not remove that photograph.'];
        }

        if ($n < 1) return ['ok' => false, 'message' => 'That photograph is not on this application.'];

        self::renumber($applicationId);

        // NOT a call to refreshCompleteness(). Completeness is stamped once and never
        // moved — §5.4 ranks on the moment an application BECAME complete, so a vendor who
        // deletes a photograph does not lose their place, and one who deletes and re-adds
        // does not gain one either.
        return ['ok' => true, 'message' => 'Removed.'];
    }

    /**
     * Put them in the given order. Ids not on this application are ignored, and any
     * photograph the caller left out keeps its place at the end.
     *
     * @param list<int> $ids
     */
    public static function reorder(int $applicationId, array $ids): bool
    {
        $mine = array_column(self::forApplication($applicationId), 'id');
        if ($mine === []) return false;

        $wanted = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if (in_array($id, $mine, true) && !in_array($id, $wanted, true)) $wanted[] = $id;
        }
        foreach ($mine as $id) {
            if (!in_array($id, $wanted, true)) $wanted[] = $id;
        }

        try {
            foreach ($wanted as $i => $id) {
                DB::table('gates_stand_application_photos')->where('id', $id)
                    ->update(['sort_order' => $i]);
            }
        } catch (\Throwable) {
            return false;
        }
        return true;
    }

    /**
     * Whether these photographs may be shown to somebody who is not the vendor.
     *
     * The form promises "published beside your name on the stand list ONLY if you are
     * offered a stand and accept. Nothing is published while the call is running." This is
     * that promise as a function, and it is called by the controller rather than consulted
     * by a template: a template `if` protects a page, and the thing that needs protecting
     * is the file.
     */
    public static function arePublic(int $applicationId): bool
    {
        try {
            $decision = (string) (DB::table('gates_stand_applications')
                ->where('id', $applicationId)->value('decision') ?? '');
        } catch (\Throwable) {
            return false;
        }
        return $decision === StandApplication::DECISION_ACCEPTED;
    }

    /** The cover, for a stand list — null until the offer is accepted. */
    public static function publicCover(int $applicationId): ?string
    {
        if (!self::arePublic($applicationId)) return null;
        $all = self::forApplication($applicationId);
        return $all === [] ? null : $all[0]['path'];
    }

    private static function renumber(int $applicationId): void
    {
        try {
            foreach (self::forApplication($applicationId) as $i => $p) {
                if ($p['sort_order'] === $i) continue;
                DB::table('gates_stand_application_photos')->where('id', $p['id'])
                    ->update(['sort_order' => $i]);
            }
        } catch (\Throwable) { /* order is cosmetic until the next reorder */ }
    }
}
