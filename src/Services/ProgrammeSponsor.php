<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\OptionalColumn;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * SPONSORS OF AN AWARD PROGRAMME — NAMED IN PUBLIC, AND NOWHERE NEAR THE JUDGING.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE ONE RULE THIS CLASS EXISTS TO ENFORCE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The whole claim of this platform is that an award cannot be bought. A sponsorship is
 * money changing hands beside that claim, so it is the single most dangerous revenue line
 * here — and the thing that makes it safe is not restraint, it is **structure**:
 *
 *   · A sponsor is attached to a PROGRAMME or an EDITION. There is no column that attaches
 *     one to a category, a nominee or a judge, and that is deliberate: a schema with no
 *     place to put it is a promise no future screen can quietly break.
 *   · Nothing here is readable by the scorer. {@see NomineeScoringService} never asks this
 *     class anything, and `SponsorshipIntegrityTest` fails if it ever does.
 *   · The list is published. A sponsorship nobody can see is the arrangement people
 *     reasonably suspect; one printed under the award is one they can weigh.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND THE CONFLICT THAT ACTUALLY HAPPENS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Not bribery — sponsorship by somebody who is also in the running. A school group that
 * sponsors the awards and has a principal nominated in them is an ordinary, innocent
 * situation and an indefensible one if nobody noticed. {@see conflicts()} looks for it by
 * name so an operator is told before the page is published rather than after a complaint,
 * and it deliberately reports rather than blocks: whether a sponsorship is acceptable is a
 * judgement for a person, and a silent refusal teaches people to stop asking.
 */
final class ProgrammeSponsor
{
    public const TABLE = 'gates_programme_sponsors';

    /** Rank, never a price. What a tier costs is a negotiation; what it shows is a size. */
    public const TIER_HEADLINE   = 'headline';
    public const TIER_SUPPORTING = 'supporting';
    public const TIER_PARTNER    = 'partner';

    public const TIERS = [
        self::TIER_HEADLINE   => 'Headline sponsor',
        self::TIER_SUPPORTING => 'Supporting sponsor',
        self::TIER_PARTNER    => 'Partner',
    ];

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ENDED     = 'ended';

    /** Only this one appears anywhere public. */
    public static function publishedStatuses(): array
    {
        return [self::STATUS_PUBLISHED];
    }

    /** A tier we recognise, or the middle one. Never a guess and never a blank. */
    public static function tier(?string $raw): string
    {
        $t = strtolower(trim((string) $raw));
        return isset(self::TIERS[$t]) ? $t : self::TIER_SUPPORTING;
    }

    /**
     * The sponsors to print on one edition's pages.
     *
     * ── PROGRAMME-WIDE AND EDITION-SPECIFIC, IN ONE LIST ────────────────────
     *
     * `cycle_id IS NULL` backs every edition; a cycle id backs one. Both belong on that
     * edition's page and neither should be able to hide the other, so this is one query
     * with an OR rather than two lists a template has to interleave — a template deciding
     * the order is a template that will order them differently on the next page.
     *
     * Ordered by TIER first and then by the operator's own `sort_order`: a headline sponsor
     * under a partner is the one arrangement that reliably produces a phone call.
     *
     * @return list<object>
     */
    public static function forCycle(int $programmeId, ?int $cycleId): array
    {
        if ($programmeId < 1) return [];

        try {
            $rows = DB::table(self::TABLE)
                ->where('programme_id', $programmeId)
                ->whereIn('status', self::publishedStatuses())
                ->where(static function ($q) use ($cycleId) {
                    $q->whereNull('cycle_id');
                    if ($cycleId !== null && $cycleId > 0) $q->orWhere('cycle_id', $cycleId);
                })
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()->all();
        } catch (\Throwable) {
            // No table yet (a deploy before db:migrate). A page with no sponsor block is a
            // working page; a page that 500s because a sponsorship has not been migrated
            // is not. Same doctrine as every other optional surface here.
            return [];
        }

        // The tier order is the CONSTANT's order, not alphabetical and not the database's:
        // 'headline' sorts after 'partner' in every collation, which would print the
        // arrangement upside down.
        $rank = array_flip(array_keys(self::TIERS));
        usort($rows, static fn (object $a, object $b): int =>
            [$rank[self::tier($a->tier ?? null)] ?? 9, (int) ($a->sort_order ?? 0), (int) $a->id]
            <=> [$rank[self::tier($b->tier ?? null)] ?? 9, (int) ($b->sort_order ?? 0), (int) $b->id]);

        return array_values(array_filter($rows, static fn (object $r): bool => self::live($r)));
    }

    /**
     * Is this row inside its own dates?
     *
     * A sponsorship that ended is not a sponsorship, and a page that goes on naming one is
     * making a claim about a relationship that no longer exists — which is the sponsor's
     * complaint to make, not ours. `ended` as a status is the operator saying so; these
     * dates are the agreement saying so by itself, so nobody has to remember.
     */
    private static function live(object $r): bool
    {
        $now = Carbon::now()->toDateTimeString();

        $from = trim((string) ($r->starts_at ?? ''));
        $to   = trim((string) ($r->ends_at ?? ''));

        if ($from !== '' && $from > $now) return false;
        if ($to   !== '' && $to   < $now) return false;

        return true;
    }

    /**
     * Every sponsor of a programme, published or not, for the admin screen.
     *
     * @return list<object>
     */
    public static function allFor(int $programmeId): array
    {
        try {
            return DB::table(self::TABLE)
                ->where('programme_id', $programmeId)
                ->orderBy('sort_order')->orderBy('id')
                ->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * SPONSORS WHO ARE ALSO IN THE RUNNING.
     *
     * Matched on the name, which is crude and is the right crudeness: the alternative is a
     * foreign key somebody has to remember to set, and a conflict nobody linked is a
     * conflict nobody sees. A false positive costs an operator one glance; a false negative
     * costs the award's credibility.
     *
     * Reports rather than blocks. Whether a sponsorship is acceptable is a judgement for a
     * person — a school group backing the awards their principal is nominated in may be
     * entirely fine and disclosed — and a silent refusal teaches people to stop asking.
     *
     * @return list<array{sponsor:string, nominee:string, category:string}>
     */
    public static function conflicts(int $programmeId, ?int $cycleId): array
    {
        $sponsors = self::allFor($programmeId);
        if ($sponsors === []) return [];

        try {
            $q = DB::table('gates_nominees as n')
                ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->whereIn('n.status', ['approved', 'winner', 'runner_up']);
            if ($cycleId !== null && $cycleId > 0) $q->where('c.cycle_id', $cycleId);

            $nominees = $q->get(['n.name as name', 'c.title as category'])->all();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($sponsors as $s) {
            $needle = self::fold((string) ($s->name ?? ''));
            if ($needle === '') continue;

            foreach ($nominees as $n) {
                $hay = self::fold((string) ($n->name ?? ''));
                if ($hay === '') continue;

                // Containment either way: "Bright Futures" sponsors and "Bright Futures
                // Academy" is nominated, or the reverse. Equality alone would miss the
                // case that actually happens.
                if (!str_contains($hay, $needle) && !str_contains($needle, $hay)) continue;

                $out[] = [
                    'sponsor'  => (string) $s->name,
                    'nominee'  => (string) $n->name,
                    'category' => (string) ($n->category ?? ''),
                ];
            }
        }

        return $out;
    }

    /**
     * A name reduced to what a match should care about.
     *
     * Case, punctuation and the corporate suffixes everybody spells differently. Without
     * this "Bright Futures Ltd." and "Bright Futures Limited" are two organisations, and
     * the conflict check is a control that never fires.
     */
    private static function fold(string $name): string
    {
        $n = strtolower(trim($name));
        $n = (string) preg_replace('~\b(limited|ltd|plc|inc|incorporated|nigeria|ng|foundation|group|academy|schools?|company|co)\b~', ' ', $n);
        $n = (string) preg_replace('~[^a-z0-9]+~', ' ', $n);

        return trim((string) preg_replace('~\s+~', ' ', $n));
    }

    /**
     * Store one sponsor.
     *
     * @param array<string,mixed> $in
     */
    public static function save(array $in, ?int $adminId = null): int
    {
        $row = [
            'programme_id' => max(0, (int) ($in['programme_id'] ?? 0)),
            // Zero is stored as NULL, because "every edition" and "edition nought" are
            // different claims and only one of them is a thing.
            'cycle_id'     => ((int) ($in['cycle_id'] ?? 0)) > 0 ? (int) $in['cycle_id'] : null,
            'name'         => trim(mb_substr((string) ($in['name'] ?? ''), 0, 160)),
            'tier'         => self::tier((string) ($in['tier'] ?? '')),
            'website'      => self::url((string) ($in['website'] ?? '')),
            'blurb'        => trim(mb_substr((string) ($in['blurb'] ?? ''), 0, 400)),
            'amount_naira' => ((int) ($in['amount_naira'] ?? 0)) > 0 ? (int) $in['amount_naira'] : null,
            'status'       => in_array((string) ($in['status'] ?? ''),
                                [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_ENDED], true)
                              ? (string) $in['status'] : self::STATUS_DRAFT,
            'sort_order'   => max(0, min(9999, (int) ($in['sort_order'] ?? 0))),
            'starts_at'    => self::stamp($in['starts_at'] ?? null),
            'ends_at'      => self::stamp($in['ends_at'] ?? null),
        ];

        $id = (int) ($in['id'] ?? 0);
        if ($id > 0) {
            DB::table(self::TABLE)->where('id', $id)->update($row);
            return $id;
        }

        $row['created_at'] = Carbon::now()->toDateTimeString();
        $row['created_by'] = $adminId;

        return (int) DB::table(self::TABLE)->insertGetId(
            OptionalColumn::filter(self::TABLE, $row, ['created_by']));
    }

    /**
     * A URL we are willing to put in an `href`, or null.
     *
     * PARSED, never substring-matched, and only http(s): `javascript:` in a sponsor's
     * website field is a script on a page that ranks awards, and an operator pasting a
     * link they were emailed is exactly how it would arrive.
     */
    private static function url(string $raw): ?string
    {
        $u = trim($raw);
        if ($u === '') return null;
        if (!preg_match('~^https?://~i', $u)) $u = 'https://' . $u;

        $parts = parse_url($u);
        if (!is_array($parts)) return null;
        if (!in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) return null;

        // ── A HOST THAT LOOKS LIKE ONE, NOT MERELY A NON-EMPTY STRING ───────
        //
        // Prepending the scheme above means `parse_url` finds a "host" in almost anything:
        // "not a url at all" becomes `https://not a url at all`, whose host is `not`. So
        // the presence check passed and the page rendered an href to a hostname that does
        // not exist — an operator's typo published as a link on an awards page.
        //
        // A label, a dot and a TLD. Deliberately no allowlist of TLDs: they change, and
        // refusing a real `.africa` or `.ng` address because a list is out of date is a
        // worse failure than accepting a plausible typo.
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!preg_match('~^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]*[a-z0-9])?'
                      . '(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*\.[a-z]{2,63}$~', $host)) {
            return null;
        }

        return mb_substr($u, 0, 255);
    }

    /**
     * A datetime the database will accept, or null.
     *
     * Through the same normaliser the gateway dates use: a `T`-separated value compares
     * wrong on SQLite and one carrying milliseconds and a zone is REFUSED outright by
     * strict-mode MySQL. A sponsorship date is typed by a person into a form, so it arrives
     * in whatever shape the browser's date input produces.
     */
    private static function stamp(mixed $raw): ?string
    {
        $v = trim((string) $raw);
        return $v === '' ? null : RecurringGiving::stamp($v);
    }
}
