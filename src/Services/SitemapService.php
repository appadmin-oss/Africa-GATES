<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Slug;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The sitemap, built from the content that actually exists.
 *
 * ── WHAT THIS REPLACES, AND WHY IT MATTERED ──────────────────────────────────
 *
 * `/sitemap.xml` used to be a hand-written list of fifteen top-level paths — `/`,
 * `/awards`, `/vote`, `/leaderboard` and so on. Every one of those is a page a
 * crawler would find on its own from the first link on the home page. Not one
 * nominee ballot, registry profile, help answer, event or post was in it.
 *
 * Those are the pages that can rank. A nominee's name is a query where a small
 * site beats a large one, because the large one has no page for that name — but
 * only if the page is discoverable. Nominee ballots are reachable only through a
 * paginated category listing, so the deeper a nominee sits in a cycle with a few
 * hundred entries, the more likely a crawler simply never arrived. The sitemap is
 * how that page gets found on the day it is published rather than eventually.
 *
 * ── LASTMOD IS EITHER TRUE OR ABSENT ─────────────────────────────────────────
 *
 * The old file stamped every URL with `date('Y-m-d')`. That is not a small
 * inaccuracy: a sitemap whose every entry claims to have changed today, every
 * day, is a sitemap whose `lastmod` carries no information, and Google's
 * documentation says outright that it ignores the value when it does not trust
 * it. So each entry here takes its date from a real column, and where there is
 * no such column the element is omitted rather than filled in with today.
 *
 * ── SHAPED FOR SHARED HOSTING ────────────────────────────────────────────────
 *
 * `/sitemap.xml` is an INDEX pointing at one file per section, each capped at
 * {@see self::PER_FILE} URLs. The protocol allows fifty thousand per file, but
 * this runs on cPanel with a modest memory limit and builds the XML in a string:
 * smaller files also mean a partial failure costs one section rather than the
 * whole sitemap, and a crawler can re-fetch just the section that changed.
 *
 * ── EVERY QUERY IS GUARDED ───────────────────────────────────────────────────
 *
 * `hasTable` before each read, and the whole section in a try/catch. This
 * platform is routinely running between a `git pull` and somebody remembering to
 * migrate ({@see \AfricaGates\Support\OptionalColumn} documents that in full), and
 * a sitemap that 500s during that window is worse than one missing a section:
 * Search Console reports a fetch error and stops trusting the file.
 */
final class SitemapService
{
    /** URLs per section file. Well under the protocol's 50,000 — see the class note. */
    public const PER_FILE = 5000;

    /**
     * Section key => [changefreq, priority]. The key is the URL segment:
     * `/sitemap-nominees.xml`, `/sitemap-nominees-2.xml`.
     */
    private const SECTIONS = [
        'core'     => ['daily',   '1.0'],
        'awards'   => ['weekly',  '0.9'],
        'nominees' => ['daily',   '0.8'],
        'registry' => ['weekly',  '0.7'],
        'events'   => ['weekly',  '0.7'],
        // ── THE FUNDRAISING SURFACE, WHICH WAS ENTIRELY ABSENT ───────────────
        //
        // /donate, every organisation's own appeal page and every live campaign were in no
        // sitemap section at all. Not a ranking nicety: this is the half of the platform
        // whose whole job is to be FOUND by somebody searching for a cause, and the pages
        // an organisation is asking their own supporters to share. A charity's appeal page
        // that no search engine has been told about is a charity's appeal page that only
        // reaches people who already had the link.
        //
        // Daily, because a live appeal's total and its days-remaining both move.
        'donate'   => ['daily',   '0.8'],
        'blog'     => ['weekly',  '0.6'],
        'help'     => ['monthly', '0.6'],
        'legacy'   => ['monthly', '0.5'],
        'judges'   => ['monthly', '0.5'],
    ];

    public function __construct(private readonly ?CacheService $cache = null) {}

    /** @return list<string> section keys, in index order */
    public static function sectionKeys(): array
    {
        return array_keys(self::SECTIONS);
    }

    /**
     * The sitemap index: one <sitemap> per non-empty section file.
     *
     * A section with no rows is left out entirely. Listing an empty urlset is
     * legal but shows up in Search Console as a sitemap with zero URLs, which
     * reads as a broken sitemap to whoever is looking at the report.
     */
    public function index(string $base): string
    {
        $base = rtrim($base, '/');
        $xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
              . "<sitemapindex xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

        foreach (self::sectionKeys() as $key) {
            $urls = $this->urls($key);
            if ($urls === []) continue;

            $files = (int) ceil(count($urls) / self::PER_FILE);
            for ($n = 1; $n <= $files; $n++) {
                $loc  = $base . '/sitemap-' . $key . ($n > 1 ? '-' . $n : '') . '.xml';
                $slice = array_slice($urls, ($n - 1) * self::PER_FILE, self::PER_FILE);
                $xml .= "  <sitemap>\n    <loc>" . self::esc($loc) . "</loc>\n";
                $last = self::newest($slice);
                if ($last !== null) $xml .= "    <lastmod>{$last}</lastmod>\n";
                $xml .= "  </sitemap>\n";
            }
        }

        return $xml . "</sitemapindex>\n";
    }

    /**
     * One section file, or null when the section does not exist / that page is
     * past the end — the route turns null into a 404 rather than an empty urlset,
     * because a crawler that asked for page 9 of a 3-page section asked for a URL
     * that is genuinely not there.
     */
    public function section(string $key, string $base, int $page = 1): ?string
    {
        if (!isset(self::SECTIONS[$key]) || $page < 1) return null;

        $urls  = $this->urls($key);
        $slice = array_slice($urls, ($page - 1) * self::PER_FILE, self::PER_FILE);
        if ($slice === []) return null;

        [$freq, $pri] = self::SECTIONS[$key];
        $base = rtrim($base, '/');

        // The image namespace is declared unconditionally: it costs one attribute and
        // makes the file valid whether or not this particular slice carries portraits.
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
             . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\""
             . " xmlns:image=\"http://www.google.com/schemas/sitemap-image/1.1\">\n";

        foreach ($slice as $u) {
            $xml .= "  <url>\n    <loc>" . self::esc($base . $u['path']) . "</loc>\n";
            if (!empty($u['lastmod'])) $xml .= "    <lastmod>{$u['lastmod']}</lastmod>\n";
            $xml .= "    <changefreq>" . ($u['changefreq'] ?? $freq) . "</changefreq>\n"
                  . "    <priority>" . ($u['priority'] ?? $pri) . "</priority>\n";
            if (!empty($u['image'])) {
                $xml .= "    <image:image>\n      <image:loc>" . self::esc((string) $u['image'])
                      . "</image:loc>\n";
                if (!empty($u['image_title'])) {
                    $xml .= "      <image:title>" . self::esc((string) $u['image_title']) . "</image:title>\n";
                }
                $xml .= "    </image:image>\n";
            }
            $xml .= "  </url>\n";
        }

        return $xml . "</urlset>\n";
    }

    /**
     * Every URL in a section, newest first where a date exists.
     *
     * Cached for an hour. The nominee section joins four tables and the registry
     * section sorts a whole table; a crawler fetching nine section files back to
     * back would otherwise run all nine queries nine times over — the index call
     * needs the counts too.
     *
     * @return list<array{path:string,lastmod?:string,image?:string,image_title?:string,changefreq?:string,priority?:string}>
     */
    public function urls(string $key): array
    {
        if (!isset(self::SECTIONS[$key])) return [];

        $build = fn(): array => $this->build($key);

        if ($this->cache === null) return $build();

        // Fall back to building it directly if the cache table itself is missing:
        // remember() swallows storage errors but a hard failure here would take out
        // the whole sitemap, and the sitemap is exactly what somebody is fixing when
        // the cache table is missing.
        try {
            $rows = $this->cache->remember('sitemap:' . $key, 3600, $build, ['sitemap']);
        } catch (\Throwable) {
            return $build();
        }
        return is_array($rows) ? $rows : [];
    }

    /** @return list<array<string,mixed>> */
    private function build(string $key): array
    {
        try {
            return match ($key) {
                'core'     => self::core(),
                'help'     => self::help(),
                'awards'   => $this->awards(),
                'nominees' => $this->nominees(),
                'registry' => $this->registry(),
                'events'   => $this->events(),
                'donate'   => $this->donate(),
                'blog'     => $this->blog(),
                'legacy'   => $this->legacy(),
                'judges'   => $this->judges(),
                default    => [],
            };
        } catch (\Throwable) {
            // One broken section, not a broken sitemap. See the class note.
            return [];
        }
    }

    // ── Sections ────────────────────────────────────────────────────────────

    /**
     * The hand-curated top level.
     *
     * No `lastmod` anywhere in here on purpose: there is no column behind these
     * pages, so any date would be invented. `changefreq` already says which of
     * them move.
     */
    private static function core(): array
    {
        $paths = [
            ['/',              '1.0', 'daily'],
            ['/vote',          '0.9', 'daily'],
            ['/leaderboard',   '0.9', 'daily'],
            ['/awards',        '0.9', 'weekly'],
            ['/nominate',      '0.9', 'weekly'],
            ['/registry',      '0.8', 'daily'],
            ['/events',        '0.8', 'weekly'],
            ['/judges',        '0.7', 'monthly'],
            ['/opportunities', '0.7', 'weekly'],
            ['/legacy',        '0.7', 'monthly'],
            ['/philosophy',    '0.7', 'yearly'],
            ['/integrity',     '0.7', 'yearly'],
            ['/community',     '0.6', 'weekly'],
            ['/blog',          '0.6', 'weekly'],
            ['/partner',       '0.6', 'monthly'],
            // /account/register, not /register — the latter 301s, and the old sitemap
            // advertised the redirect for as long as it existed.
            ['/account/register', '0.5', 'monthly'],
            ['/support',       '0.4', 'monthly'],
            // ── PAGES THAT EXISTED AND WERE NEVER LISTED ─────────────────────
            //
            // Each of these returns 200, is indexable, and was in no section. The shop and
            // the feed are two of the platform's four public surfaces; /status is what
            // somebody searches when they think the site is down, and finding a real
            // answer there instead of nothing is the entire point of having built it.
            ['/shop',          '0.7', 'weekly'],
            ['/pulse',         '0.7', 'daily'],
            ['/status',        '0.3', 'daily'],
            ['/privacy',       '0.3', 'yearly'],
            ['/terms',         '0.3', 'yearly'],
            // Cookies and refunds shipped as documents, got their own routes, and were
            // left out of here — so the two policies a person most often goes looking for
            // BEFORE paying were the two a search engine had never been told about.
            ['/cookies',       '0.3', 'yearly'],
            ['/refunds',       '0.3', 'yearly'],
            // What a market trader agrees to when they accept a pitch. Linked from the
            // offer email and the acceptance screen; worth being findable on its own.
            ['/vendor-terms',  '0.3', 'yearly'],
        ];
        return array_map(
            static fn(array $p) => ['path' => $p[0], 'priority' => $p[1], 'changefreq' => $p[2]],
            $paths
        );
    }

    /**
     * The help centre — a PHP corpus, not a table, so its `lastmod` is the mtime
     * of the file that holds it. That is the honest answer to "when did this
     * answer last change", and it is the one section where the date is exact.
     */
    private static function help(): array
    {
        $file = (new \ReflectionClass(HelpCentre::class))->getFileName();
        $mod  = $file && is_file($file) ? gmdate('Y-m-d', (int) filemtime($file)) : null;
        $stamp = static fn(array $u): array => $mod ? $u + ['lastmod' => $mod] : $u;

        $out = [$stamp(['path' => '/help', 'priority' => '0.7', 'changefreq' => 'weekly'])];

        foreach (array_keys(HelpCentre::CATEGORIES) as $cat) {
            $out[] = $stamp(['path' => '/help/c/' . rawurlencode((string) $cat), 'priority' => '0.5']);
        }
        foreach (HelpCentre::all() as $a) {
            $slug = (string) ($a['slug'] ?? '');
            if ($slug === '') continue;
            $out[] = $stamp(['path' => '/help/' . rawurlencode($slug), 'priority' => '0.6']);
        }
        return $out;
    }

    private function awards(): array
    {
        if (!self::has('gates_award_programmes')) return [];
        $rows = DB::table('gates_award_programmes')->where('is_active', 1)
            ->orderBy('sort_order')->get();

        $out = [];
        foreach ($rows as $r) {
            $slug = (string) ($r->slug ?? '');
            if ($slug === '') continue;
            $out[] = array_filter([
                'path'    => '/awards/' . rawurlencode($slug),
                'lastmod' => self::day($r->created_at ?? null),
            ], static fn($v) => $v !== null);
        }
        return $out;
    }

    /**
     * Nominee ballots — the section this whole class exists for.
     *
     * The filters mirror {@see \AfricaGates\Controllers\VoteController} exactly:
     * public statuses only, and merged nominees excluded. A merged nominee's page
     * 302s to its survivor, so listing it means every one of those URLs comes back
     * from Search Console as "page with redirect" — a sitemap full of redirects is
     * a quality signal against the whole file.
     */
    private function nominees(): array
    {
        foreach (['gates_nominees', 'gates_award_categories', 'gates_award_cycles', 'gates_award_programmes'] as $t) {
            if (!self::has($t)) return [];
        }

        $q = DB::table('gates_nominees as n')
            ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
            ->join('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
            ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
            ->whereIn('n.status', ['approved', 'winner', 'runner_up'])
            ->where(fn($w) => MergeService::notMerged($w, 'n.merged_into'))
            ->orderByDesc('n.id')
            ->limit(self::PER_FILE * 10);

        $cols = ['n.id', 'n.name', 'p.slug as programme_slug'];
        foreach (['nominated_at' => 'n.nominated_at', 'photo_path' => 'n.photo_path'] as $c => $sel) {
            if (\AfricaGates\Support\OptionalColumn::on('gates_nominees', $c)) $cols[] = $sel;
        }

        $out = [];
        foreach ($q->select($cols)->get() as $r) {
            $prog = (string) ($r->programme_slug ?? '');
            if ($prog === '') continue;
            $out[] = array_filter([
                'path'        => '/vote/' . rawurlencode($prog) . '/'
                               . Slug::idSegment((int) $r->id, (string) $r->name),
                'lastmod'     => self::day($r->nominated_at ?? null),
                'image'       => self::media($r->photo_path ?? null),
                'image_title' => (string) $r->name,
            ], static fn($v) => $v !== null && $v !== '');
        }
        return $out;
    }

    private function registry(): array
    {
        if (!self::has('gates_profiles')) return [];

        // `approved` and nothing else. ProfileService::getBySlug() filters on exactly
        // that, so a `pending` profile in the sitemap is a URL that 404s — and a
        // sitemap of 404s is the fastest way to have the whole file discounted.
        // ProfileMergeService, not MergeService: the latter's guard checks
        // gates_nominees for the column regardless of the table being queried.
        $q = ProfileMergeService::notMerged(
                DB::table('gates_profiles')->where('status', 'approved')
             )->orderByDesc('id')->limit(self::PER_FILE * 10);

        $out = [];
        foreach ($q->get() as $r) {
            $slug = (string) ($r->slug ?? '');
            if ($slug === '') continue;
            $out[] = array_filter([
                'path'        => '/registry/' . rawurlencode($slug),
                'lastmod'     => self::day($r->updated_at ?? $r->registered_at ?? null),
                'image'       => self::media($r->avatar_path ?? null),
                'image_title' => (string) ($r->display_name ?? ''),
            ], static fn($v) => $v !== null && $v !== '');
        }
        return $out;
    }

    private function events(): array
    {
        if (!self::has('gates_site_events')) return [];
        $out = [];
        foreach (DB::table('gates_site_events')->where('status', 'published')->orderByDesc('id')->get() as $r) {
            $slug = (string) ($r->slug ?? '');
            if ($slug === '') continue;
            $out[] = array_filter([
                'path'        => '/events/' . rawurlencode($slug),
                'lastmod'     => self::day($r->created_at ?? null),
                'image'       => self::media($r->cover_image ?? null),
                'image_title' => (string) ($r->title ?? ''),
            ], static fn($v) => $v !== null && $v !== '');

            // ── THE CALL FOR STANDS, WHERE THERE IS ONE ─────────────────────
            //
            // A published call is a public page with prices, quotas and a closing date on
            // it — the page a trader searching "market stall Lagos" should reach, and one
            // that was in no sitemap. Listed once it is out of DRAFT, because the call page
            // itself serves a CLOSED call deliberately (a vendor who arrives late is owed
            // "this closed on the 14th" rather than a 404, and next year they know when to
            // look). A draft is not a public fact and is not listed.
            try {
                $call = \AfricaGates\Services\StandCall::forEvent((int) $r->id);
            } catch (\Throwable) {
                $call = null;
            }
            if ($call && (string) ($call->status ?? '') !== \AfricaGates\Services\StandCall::STATUS_DRAFT) {
                $out[] = array_filter([
                    'path'       => '/events/' . rawurlencode($slug) . '/stands',
                    'lastmod'    => self::day($call->updated_at ?? $call->created_at ?? null),
                    'priority'   => \AfricaGates\Services\StandCall::isAccepting($call) ? '0.7' : '0.4',
                    'changefreq' => 'weekly',
                ], static fn($v) => $v !== null && $v !== '');
            }
        }
        return $out;
    }

    /**
     * The fundraising pages: the hub, the application, and every live appeal.
     *
     * ── WHAT IS AND IS NOT LISTED, AND WHY ──────────────────────────────────
     *
     * Only organisations that can ACTUALLY RECEIVE money — the same list the donate page
     * itself renders, via {@see PartnerOrg::listReceivable()}. A suspended partner's page
     * 404s on purpose (somebody following a link to a closed appeal must be told it is
     * closed, not redirected into giving to a different organisation), and advertising a
     * 404 to a crawler is how a whole section loses its credibility with one.
     *
     * Only OPEN campaigns, for the same reason: {@see OrgCampaign::isOpen()} is what the
     * page checks, and a closed appeal answers 404 there too.
     *
     * The checkout return paths — /donate/callback, /donate/redirect, /donate/success — are
     * deliberately absent. They are steps in a transaction, they carry a payment reference,
     * and a crawler arriving at one has nothing to see and a receipt page to mangle.
     *
     * @return list<array<string,mixed>>
     */
    private function donate(): array
    {
        $out = [
            ['path' => '/donate',     'priority' => '0.9', 'changefreq' => 'daily'],
            // Where an organisation applies to raise donations through us. The one page on
            // this platform aimed at a charity searching "how do we take donations online",
            // and it was reachable only from a panel at the foot of /donate.
            ['path' => '/gift/apply', 'priority' => '0.6', 'changefreq' => 'monthly'],
        ];

        if (!self::has('gates_partner_orgs')) return $out;

        try {
            $orgs = \AfricaGates\Services\PartnerOrg::listReceivable();
        } catch (\Throwable) {
            return $out;
        }

        foreach ($orgs as $org) {
            $slug = trim((string) ($org->slug ?? ''));
            if ($slug === '') continue;

            $out[] = array_filter([
                'path'        => '/donate/' . rawurlencode($slug),
                'lastmod'     => self::day($org->updated_at ?? $org->created_at ?? null),
                'priority'    => '0.7',
                'changefreq'  => 'weekly',
                'image_title' => (string) ($org->name ?? ''),
            ], static fn ($v) => $v !== null && $v !== '');

            if (!self::has('gates_org_campaigns')) continue;

            try {
                $live = \AfricaGates\Services\OrgCampaign::openFor((int) $org->id);
            } catch (\Throwable) {
                continue;
            }

            foreach ($live as $c) {
                $cslug = trim((string) ($c->slug ?? ''));
                if ($cslug === '') continue;

                $out[] = array_filter([
                    'path'        => '/donate/' . rawurlencode($slug) . '/' . rawurlencode($cslug),
                    'lastmod'     => self::day($c->updated_at ?? $c->created_at ?? null),
                    // The highest priority in this section. A specific appeal with a target
                    // and a deadline is the page somebody actually shares, and the one a
                    // search for the cause should land on rather than the organisation's
                    // general page.
                    'priority'    => '0.8',
                    'changefreq'  => 'daily',
                    'image_title' => (string) ($c->title ?? ''),
                ], static fn ($v) => $v !== null && $v !== '');
            }
        }

        return $out;
    }

    private function blog(): array
    {
        if (!self::has('gates_posts')) return [];
        $out = [];
        foreach (DB::table('gates_posts')->where('status', 'published')
                     ->orderByDesc('published_at')->limit(self::PER_FILE * 2)->get() as $r) {
            $slug = (string) ($r->slug ?? '');
            if ($slug === '') continue;
            $out[] = array_filter([
                'path'        => '/blog/' . rawurlencode($slug),
                'lastmod'     => self::day($r->published_at ?? $r->created_at ?? null),
                'image'       => self::media($r->cover_image ?? null),
                'image_title' => (string) ($r->title ?? ''),
            ], static fn($v) => $v !== null && $v !== '');
        }
        return $out;
    }

    private function legacy(): array
    {
        if (!self::has('gates_legacy_events')) return [];
        $out = [];
        foreach (DB::table('gates_legacy_events')->where('is_published', 1)->orderByDesc('id')->get() as $r) {
            $slug = (string) ($r->slug ?? '');
            if ($slug === '') continue;
            $out[] = array_filter([
                'path'        => '/legacy/' . rawurlencode($slug),
                // event_date beats created_at here: a legacy page IS its event, and
                // the date somebody would call "last changed" is the night itself.
                'lastmod'     => self::day($r->event_date ?? $r->created_at ?? null),
                'image'       => self::media($r->cover_path ?? null),
                'image_title' => (string) ($r->title ?? ''),
            ], static fn($v) => $v !== null && $v !== '');
        }
        return $out;
    }

    private function judges(): array
    {
        if (!self::has('gates_judges')) return [];
        $out = [];
        foreach (DB::table('gates_judges')->where('is_active', 1)->orderBy('name')->get() as $r) {
            $name = (string) ($r->name ?? '');
            if ($name === '') continue;
            // The judge slug is {id}-{name} via Slug::make, NOT Slug::idSegment —
            // /judges/{slug} redirects when the slug does not match exactly, so
            // building it the other way would put a redirect in the sitemap.
            $s = Slug::make($name, 60);
            $out[] = array_filter([
                'path'        => '/judges/' . (int) $r->id . ($s !== '' ? '-' . $s : ''),
                'lastmod'     => self::day($r->created_at ?? null),
                'image'       => self::media($r->avatar_path ?? null),
                'image_title' => $name,
            ], static fn($v) => $v !== null && $v !== '');
        }
        return $out;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private static function has(string $table): bool
    {
        try { return DB::schema()->hasTable($table); } catch (\Throwable) { return false; }
    }

    /** A date column as W3C `YYYY-MM-DD`, or null when it is missing or unparseable. */
    private static function day(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '' || str_starts_with($s, '0000')) return null;
        $ts = strtotime($s);
        return $ts ? gmdate('Y-m-d', $ts) : null;
    }

    /** Absolute URL for a stored image path, or null. */
    private static function media(mixed $path): ?string
    {
        return \AfricaGates\Support\Assets::absoluteOg(
            ($p = trim((string) ($path ?? ''))) === '' ? null : $p
        );
    }

    /** The most recent lastmod in a slice, for the index's own <lastmod>. */
    private static function newest(array $urls): ?string
    {
        $best = null;
        foreach ($urls as $u) {
            $d = $u['lastmod'] ?? null;
            if ($d !== null && ($best === null || $d > $best)) $best = $d;
        }
        return $best;
    }

    /**
     * XML text escaping. `&` in a slug or an image query string is the one that
     * bites: an unescaped ampersand makes the whole file a parse error, and the
     * report says "could not read" with no line number.
     */
    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
