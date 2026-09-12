<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Editable legal / policy documents (privacy, terms, cookies, and any custom
 * page an operator adds). Content lives in gates_legal_docs — never hardcoded
 * in a template — so policies can change without a deploy. All reads are
 * fault-tolerant: a missing table/row yields null and the caller 404s or
 * hides the tab, so the site never white-screens on a fresh DB.
 */
class LegalService
{
    /** Published docs for the public tab strip, in sort order. */
    public static function published(): array
    {
        try {
            return DB::table('gates_legal_docs')->where('is_published', 1)
                ->orderBy('sort_order')->orderBy('slug')
                ->get(['slug', 'title'])->map(fn($r) => (array) $r)->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** A single published doc (slug, title, body_html, updated_label) or null. */
    public static function get(string $slug): ?array
    {
        try {
            $r = DB::table('gates_legal_docs')->where('slug', $slug)->where('is_published', 1)->first();
        } catch (\Throwable) {
            return null;
        }

        if ($r) return (array) $r;

        // ── A SHIPPED POLICY THAT IS MISSING INSTALLS ITSELF ─────────────────
        //
        // THE BUG THIS EXISTS BECAUSE OF: `/refunds` answered 404 in production. The route
        // was registered, the document was written, reviewed and tested — and
        // {@see LegalSeeder::install()} deliberately never overwrites an existing document,
        // so a policy ADDED to the seeder after the last install simply never appeared. The
        // only way to get it live was for somebody to remember to open /__setup/legal, and
        // nobody did.
        //
        // The visible result was the worst available one: the site footer linked "Refunds"
        // and "Cookies" straight at a 404. A published link to a policy that does not exist
        // reads worse than having no link, which is the exact sentence the seeder's own
        // comment uses about the previous instance of this.
        //
        // ── AND WHY THIS IS NOT A WAY TO RESURRECT A WITHDRAWN DOCUMENT ──────
        //
        // Seeded ONLY when the row is entirely absent. `find()` ignores `is_published`, so a
        // document an operator has deliberately unpublished still returns null here and the
        // page still 404s — their decision stands. This heals "never installed", not
        // "switched off on purpose".
        return self::seedShipped($slug);
    }

    /**
     * Install one document the codebase ships with, if it has never been installed.
     *
     * Returns the freshly-installed row, or null when the slug is not one we ship, when the
     * row already exists in any state, or when the write fails.
     */
    private static function seedShipped(string $slug): ?array
    {
        // Already there in some form — unpublished, or a draft an operator is working on.
        // Not ours to touch.
        if (self::find($slug) !== null) return null;

        if (!array_key_exists($slug, LegalSeeder::documents())) return null;

        try {
            $r = LegalSeeder::install(false, $slug);
            if (($r['written'] ?? []) === []) return null;

            error_log('[legal] installed the shipped "' . $slug . '" document on first request '
                    . '— it had never been seeded on this deployment.');
        } catch (\Throwable $e) {
            error_log('[legal] could not install "' . $slug . '": ' . $e->getMessage());
            return null;
        }

        try {
            $row = DB::table('gates_legal_docs')->where('slug', $slug)
                ->where('is_published', 1)->first();
        } catch (\Throwable) {
            return null;
        }

        return $row ? (array) $row : null;
    }

    /** All docs (incl. unpublished) for the admin list. */
    public static function all(): array
    {
        try {
            return DB::table('gates_legal_docs')->orderBy('sort_order')->orderBy('slug')
                ->get()->map(fn($r) => (array) $r)->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public static function find(string $slug): ?array
    {
        try {
            $r = DB::table('gates_legal_docs')->where('slug', $slug)->first();
            return $r ? (array) $r : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Upsert a doc from the admin editor. Slug is normalised + immutable-ish. */
    public static function save(string $slug, array $data, int $adminId): void
    {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9-]+/i', '-', $slug) ?? '', '-'));
        if ($slug === '') throw new \RuntimeException('A URL slug is required.');
        $row = [
            'title'         => mb_substr(trim((string) ($data['title'] ?? '')), 0, 160) ?: ucfirst($slug),
            'body_html'     => (string) ($data['body_html'] ?? ''),
            'updated_label' => mb_substr(trim((string) ($data['updated_label'] ?? '')), 0, 60) ?: date('j F Y'),
            'is_published'  => !empty($data['is_published']) ? 1 : 0,
            'sort_order'    => (int) ($data['sort_order'] ?? 0),
            'updated_by'    => $adminId,
            'updated_at'    => date('Y-m-d H:i:s'),
        ];
        DB::table('gates_legal_docs')->updateOrInsert(['slug' => $slug], $row);
    }

    public static function delete(string $slug): void
    {
        DB::table('gates_legal_docs')->where('slug', $slug)->delete();
    }
}
