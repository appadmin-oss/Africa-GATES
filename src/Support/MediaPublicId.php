<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * The Cloudinary public id for a locally-stored image, derived from its path.
 *
 * ── WHY IT IS DERIVED AND NOT RANDOM ─────────────────────────────────────────
 *
 * Two callers upload the same file, at different times, for different reasons:
 * {@see \AfricaGates\Admin\Services\UploadService} at the moment a photo is submitted,
 * and {@see \AfricaGates\Services\MediaMigrationService} when it sweeps a database that
 * predates Cloudinary. Between them there are three ways to upload the same bytes
 * twice — a live upload whose Cloudinary leg failed and is later swept up, a migration
 * batch interrupted halfway, and an operator re-running the sweep because they are not
 * sure it finished.
 *
 * With a random id each of those makes a new asset: a bulk migration of a few thousand
 * nominee photos, re-run twice out of caution, is a Cloudinary bill for three copies of
 * every face on the platform, and no way to tell which copy any given row points at. A
 * deterministic id makes every one of those a harmless overwrite of the same asset,
 * which is what turns "run it again to be sure" from a hazard into the correct advice.
 *
 * ── THE SHAPE ────────────────────────────────────────────────────────────────
 *
 * `<basename-slug>-<hash8>`, where the hash is over the FULL relative path. The slug
 * keeps the Cloudinary media library humanly browsable — an operator looking for a
 * nominee's photo can recognise it — and the hash guarantees uniqueness, because the
 * uploads tree is dated (`uploads/nominees/2026/07/…`) and two months can hold the same
 * filename. Cloudinary treats `/` in a public id as a folder separator, so the id
 * itself must never contain one; the folder is passed separately.
 */
final class MediaPublicId
{
    /** Longest slug kept from the filename. Cloudinary's limit is far higher; this is for legibility. */
    private const SLUG_MAX = 48;

    /**
     * @param string $relPath Path relative to public/, e.g. `uploads/nominees/2026/07/<uuid>.jpg`.
     *                        Leading slashes and any `./` are ignored so the same file
     *                        yields the same id however the caller spells its path.
     */
    public static function forPath(string $relPath): string
    {
        $clean = ltrim(str_replace('\\', '/', trim($relPath)), '/');
        $clean = (string) preg_replace('~(^|/)\./~', '$1', $clean);

        $base = basename($clean);
        $dot  = strrpos($base, '.');
        // The extension is a delivery format on Cloudinary, never part of the id — a
        // public id ending `.jpg` produces URLs ending `.jpg.jpg`.
        $stem = $dot !== false ? substr($base, 0, $dot) : $base;

        // Slug::make, not a local expression. Uploaded filenames are not all UUIDs —
        // several admin paths preserve the submitted name — so a stem can carry the
        // accented letters of a real African name, and the ad-hoc
        // `preg_replace('/[^a-z0-9]+/', '-', …)` this originally used DELETES those
        // rather than folding them. SlugTest guards against reintroducing it, and was
        // right to catch this file.
        $slug = Slug::make($stem, self::SLUG_MAX);
        if ($slug === '') $slug = 'image';

        return $slug . '-' . substr(hash('sha256', $clean), 0, 8);
    }
}
