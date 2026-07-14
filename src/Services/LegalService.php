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
        return $r ? (array) $r : null;
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
