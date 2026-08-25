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
    /** Below this an application is not complete. Not a submit gate — see the migration. */
    public const MIN = 3;

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
