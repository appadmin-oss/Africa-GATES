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
    /**
     * The legal directory (/legal): every document, grouped by who it is for, each with one
     * sentence saying what it answers. Structured the way the large platforms structure
     * theirs (Meta's and Google's policy centres group by audience, not by date): a reader
     * looking for "can I get my money back" or "what may my ad say" should find the document
     * by the question, not by guessing its name.
     *
     * A document an operator adds in the console and that is not listed here still appears,
     * under "More", so nothing published is ever unreachable.
     */
    public const GROUPS = [
        'everyone' => ['For everyone', ['terms', 'privacy', 'cookies']],
        'money'    => ['Paying and getting money back', ['refunds']],
        'business' => ['For businesses', ['advertising', 'vendor-terms']],
    ];

    /** What each shipped document answers, in one line. */
    public const SUMMARIES = [
        'terms'        => 'The rules for nominating, voting, entering and taking part.',
        'privacy'      => 'What we collect about you, why, who sees it and your rights.',
        'cookies'      => 'What is stored on your device, and your choices.',
        'refunds'      => 'When you get money back, how, and how long it takes.',
        'advertising'  => 'Who may advertise, what an ad may be, how ads are checked and paid for.',
        'vendor-terms' => 'What a trader agrees to when they take a stand at an event.',
    ];

    /**
     * Documents that have their own short address, because people look for them there
     * (/privacy, /terms, /cookies, /refunds, /vendor-terms). Everything else lives under
     * /legal/{slug}. One answer to "where is this document", so the directory, the tabs and
     * the downloads cannot point at three different places.
     */
    private const OWN_PATH = ['terms', 'privacy', 'cookies', 'refunds', 'vendor-terms'];

    public static function path(string $slug): string
    {
        return in_array($slug, self::OWN_PATH, true) ? '/' . $slug : '/legal/' . $slug;
    }

    /**
     * The directory: published documents in their groups, then anything else.
     *
     * @return list<array{key:string, title:string, docs:list<array{slug:string,title:string,summary:string,url:string,updated:string}>}>
     */
    public static function directory(): array
    {
        try {
            $rows = DB::table('gates_legal_docs')->where('is_published', 1)
                ->orderBy('sort_order')->orderBy('slug')->get(['slug', 'title', 'updated_label'])->all();
        } catch (\Throwable) {
            $rows = [];
        }
        $bySlug = [];
        foreach ($rows as $r) $bySlug[(string) $r->slug] = $r;
        // A shipped document that has never been installed is listed all the same: get()
        // installs it on its first visit, and leaving it out would hide it until then.
        foreach (LegalSeeder::documents() as $slug => $d) {
            if (!isset($bySlug[$slug]) && LegalService::find($slug) === null) {
                $bySlug[$slug] = (object) ['slug' => $slug, 'title' => $d['title'], 'updated_label' => ''];
            }
        }
        $card = static fn (object $r): array => [
            'slug' => (string) $r->slug, 'title' => (string) $r->title,
            'summary' => self::SUMMARIES[(string) $r->slug] ?? '', 'url' => self::path((string) $r->slug),
            'updated' => (string) ($r->updated_label ?? ''),
        ];
        $out = [];
        $placed = [];
        foreach (self::GROUPS as $key => [$title, $slugs]) {
            $docs = [];
            foreach ($slugs as $slug) {
                if (isset($bySlug[$slug])) { $docs[] = $card($bySlug[$slug]); $placed[$slug] = true; }
            }
            if ($docs !== []) $out[] = ['key' => $key, 'title' => $title, 'docs' => $docs];
        }
        $more = [];
        foreach ($bySlug as $slug => $r) {
            if (!isset($placed[$slug])) $more[] = $card($r);
        }
        if ($more !== []) $out[] = ['key' => 'more', 'title' => 'More', 'docs' => $more];
        return $out;
    }

    /** Published docs for the public tab strip, in sort order. */
    public static function published(): array
    {
        try {
            return DB::table('gates_legal_docs')->where('is_published', 1)
                ->orderBy('sort_order')->orderBy('slug')
                ->get(['slug', 'title'])->map(fn($r) => (array) $r + ['url' => self::path((string) $r->slug)])->all();
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
