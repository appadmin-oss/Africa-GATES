<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Support;

use AfricaGates\Support\OptionalColumn;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Turning `nominee #412` back into a person.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A WHOLE CLASS FOR A NAME
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_audit_log` records `target_type` + `target_id` and nothing else about what was
 * acted on — which is the right thing to store, because a name copied into a log row goes
 * stale the moment somebody corrects a spelling, and an audit trail that disagrees with
 * the record is worse than one that is terse.
 *
 * But it means every reader of the log has the same job to do, and the job is not small:
 * fifty distinct target types across forty-odd tables, several of which name their subject
 * in a different column (`title`, `name`, `display_name`, `reference`), several of which a
 * given deployment may not have migrated at all, and two pairs that are the SAME THING
 * under two names — see the aliases below. Left to each caller, that is fifty chances to
 * get it wrong, and it is exactly the "two readers of one fact" this codebase keeps
 * paying for.
 *
 * ── IT RESOLVES IN BATCHES, AND THAT IS THE POINT ────────────────────────────
 *
 * {@see resolve()} takes a whole page of log rows and issues ONE query per distinct type
 * present — never one per row. A fifty-row page touching eight types costs eight queries;
 * the obvious per-row form costs fifty, and the screen that made this necessary shows a
 * hundred rows at a time.
 *
 * ── A MISSING NAME IS NOT AN ERROR ───────────────────────────────────────────
 *
 * Every lookup degrades to the bare `type #id` the log already had. The row being named
 * may have been deleted years ago — that is not a failure of the audit trail, it is the
 * audit trail doing its job, and an unresolvable name must never take the screen down
 * with it.
 */
final class AuditTargets
{
    /**
     * The same subject recorded under two names.
     *
     * These are not tidiness. `gates_site_events` is written as `'site_event'` by
     * {@see \AfricaGates\Admin\Controllers\EventsController} and as `'event'` by
     * {@see \AfricaGates\Admin\Controllers\StandsController} — so one event's history is
     * split across two buckets, and anybody filtering the log for what happened to an
     * event sees roughly half of it depending on which word they picked. Same for the
     * settings pair.
     *
     * Fixed HERE rather than by rewriting the rows: the log is the record, and a record
     * that gets edited to look tidier is not a record. The reader reconciles; the history
     * stays as it was written.
     */
    private const ALIASES = [
        'event'   => 'site_event',
        'setting' => 'settings',
    ];

    /**
     * type => [label, table, name-column candidates in preference order, detail href]
     *
     * The href is a printf pattern or null. NULL is deliberate and common: most of these
     * have no per-record admin screen, and a link to a list page that does not scroll to
     * the row is worse than a name on its own — it promises a destination it does not
     * have. Where there is no route, the log's own per-target view is the destination.
     *
     * @var array<string,array{0:string,1:?string,2:list<string>,3:?string}>
     */
    private const MAP = [
        'site_event'          => ['Event',            'gates_site_events',          ['title'],                    '/admin/events/%d'],
        'programme'           => ['Programme',        'gates_award_programmes',     ['title'],                    '/admin/programmes/%d'],
        'form'                => ['Form',             'gates_forms',                ['title'],                    '/admin/forms/%d'],
        'legacy_event'        => ['Legacy event',     'gates_legacy_events',        ['title'],                    '/admin/legacy/%d'],
        'nomination'          => ['Nomination',       'gates_nominations',          ['reference'],                '/admin/nominations/%d'],
        'opportunity'         => ['Opportunity',      'gates_opportunities',        ['title'],                    '/admin/opportunities/%d'],
        'post'                => ['Post',             'gates_posts',                ['title'],                    '/admin/posts/%d'],
        'product'             => ['Product',          'gates_products',             ['name'],                     '/admin/products/%d'],
        'profile'             => ['Profile',          'gates_profiles',             ['display_name', 'slug'],     '/admin/profiles/%d'],
        'vote_recovery_batch' => ['Recovery batch',   'gates_vote_recovery_batches', ['reference'],               '/admin/vote-recovery/%d'],

        // Named, not linked — no per-record screen exists for these.
        'nominee'             => ['Nominee',          'gates_nominees',             ['name'],                     null],
        'judge'               => ['Judge',            'gates_judges',               ['name', 'email'],            null],
        'admin'               => ['Admin',            'gates_admins',               ['name', 'email'],            null],
        'user'                => ['User',             'gates_users',                ['name', 'email'],            null],
        'campaign'            => ['Campaign',         'gates_email_campaigns',      ['name', 'subject'],          null],
        'partner_org'         => ['Partner org',      'gates_partner_orgs',         ['name'],                     null],
        'partner_enquiry'     => ['Partner enquiry',  'gates_partner_enquiries',    ['org_name', 'contact_name'], null],
        'category'            => ['Category',         'gates_award_categories',     ['title'],                    null],
        'cycle'               => ['Cycle',            'gates_award_cycles',         ['edition_label', 'year'],    null],
        'criterion'           => ['Criterion',        'gates_judge_criteria',       ['label'],                    null],
        'stand_preset'        => ['Stand preset',     'gates_stand_presets',        ['name'],                     null],
        'stand_application'   => ['Stand application', 'gates_stand_applications',  [],                          null],
        'event_registration'  => ['Registration',     'gates_event_registrations',  ['name', 'reference'],        null],
        'order'               => ['Shop order',       'gates_orders',               ['reference', 'name'],        null],
        'donation'            => ['Donation',         'gates_donations',            ['donor_name', 'payment_ref'], null],
        'payout'              => ['Referral payout',  'gates_referral_payouts',     ['account_name', 'payment_ref'], '/admin/payouts'],
        'interview'           => ['Interview',        'gates_interviews',           [],                           null],
        'submission'          => ['Submission',       'gates_nominee_submissions',  [],                           null],
        'shortlist'           => ['Shortlist',        'gates_shortlists',           [],                           null],
        'shortlist_rule'      => ['Shortlist rule',   'gates_shortlist_rules',      [],                           null],
        'upload'              => ['Upload',           'gates_uploads',              ['alt', 'path'],              null],
        'webhook'             => ['Webhook',          'gates_webhooks',             ['url'],                      null],
        'legal_doc'           => ['Legal document',   'gates_legal_docs',           ['title', 'slug'],            null],
        'fraud_score'         => ['Flagged attempt',  'gates_fraud_scores',         [],                           null],
        'vote_message'        => ['Vote message',     'gates_vote_messages',        [],                           null],
        'event_code'          => ['Event code',       'gates_event_codes',          ['code'],                     null],
        'event_tier'          => ['Ticket tier',      'gates_event_tiers',          ['name'],                     null],
        'shop_code'           => ['Discount code',    'gates_shop_codes',           ['code'],                     null],
        // Recorded with a NULL id — the meta carries which dispute. Type-only.
        'dispute'             => ['Payment dispute', null, [], '/admin/payments/disputes'],
        'thread'              => ['Community thread', 'gates_threads',              ['title'],                    null],
        // Also NULL-id: AiPromptsController names the capability in the meta.
        'capability'          => ['AI capability',   null, [], '/admin/ai-prompts'],

        // Singletons: the id is meaningless, the type IS the target.
        'settings'            => ['Site settings',    null, [], '/admin/settings'],
        'rubric'              => ['Judging rubric',   null, [], '/admin/rubric'],
        'finance'             => ['Finance',          null, [], '/admin/finance'],
    ];

    /** The name this type is filed under, folding the aliases together. */
    public static function canonical(string $type): string
    {
        $t = strtolower(trim($type));
        return self::ALIASES[$t] ?? $t;
    }

    /** Human label for a target type — falls back to the raw token made readable. */
    public static function label(string $type): string
    {
        $t = self::canonical($type);
        return self::MAP[$t][0] ?? ucfirst(str_replace('_', ' ', $t));
    }

    /** Every alias that files under $canonical, including itself. Needed to QUERY the log. */
    public static function aliasesOf(string $canonical): array
    {
        $out = [$canonical];
        foreach (self::ALIASES as $from => $to) if ($to === $canonical) $out[] = $from;
        return $out;
    }

    /** A singleton target's id carries no meaning — do not print `#1` beside it. */
    public static function isSingleton(string $type): bool
    {
        $t = self::canonical($type);
        return isset(self::MAP[$t]) && self::MAP[$t][1] === null;
    }

    /** The admin screen for one record, or null when none exists. */
    public static function href(string $type, ?int $id): ?string
    {
        $t   = self::canonical($type);
        $pat = self::MAP[$t][3] ?? null;
        if ($pat === null) return null;
        if (self::isSingleton($t)) return $pat;
        return ($id !== null && $id > 0) ? sprintf($pat, $id) : null;
    }

    /**
     * type => table, for every mapped type that names a real table.
     *
     * Exists for the test that asserts they all exist. A typo here does not throw — the
     * lookup degrades to the bare `type #id` the log already had — so a wrong table name
     * is invisible in exactly the way §17 describes: the feature quietly does not work
     * and every screen looks normal.
     *
     * @return array<string,string>
     */
    public static function mappedTables(): array
    {
        $out = [];
        foreach (self::MAP as $type => $spec) if ($spec[1] !== null) $out[$type] = $spec[1];
        return $out;
    }

    /**
     * type => [table, name columns], for the same reason: a column that does not exist
     * means that target renders as a number forever and nothing says so.
     *
     * @return array<string,array{0:string,1:list<string>}>
     */
    public static function mappedNameColumns(): array
    {
        $out = [];
        foreach (self::MAP as $type => $spec) {
            if ($spec[1] !== null && $spec[2] !== []) $out[$type] = [$spec[1], $spec[2]];
        }
        return $out;
    }

    /**
     * Name every target on a page of log rows. ONE query per distinct type.
     *
     * @param list<array<string,mixed>> $rows each with `target_type` and `target_id`
     * @return list<array<string,mixed>> the same rows, each gaining `target_label`,
     *         `target_name` (null when unresolvable) and `target_href`
     */
    public static function resolve(array $rows): array
    {
        // Gather the ids wanted per canonical type first, so the queries are batched.
        $wanted = [];
        foreach ($rows as $r) {
            $type = (string) ($r['target_type'] ?? '');
            $id   = (int) ($r['target_id'] ?? 0);
            if ($type === '' || $id < 1) continue;
            $wanted[self::canonical($type)][$id] = true;
        }

        $names = [];
        foreach ($wanted as $type => $ids) {
            $names[$type] = self::namesFor($type, array_map('intval', array_keys($ids)));
        }

        foreach ($rows as $i => $r) {
            $type = (string) ($r['target_type'] ?? '');
            $id   = (int) ($r['target_id'] ?? 0);
            $can  = self::canonical($type);

            $rows[$i]['target_label'] = $type === '' ? null : self::label($type);
            $rows[$i]['target_name']  = $names[$can][$id] ?? null;
            $rows[$i]['target_href']  = $type === '' ? null : self::href($type, $id);
            // The canonical form, so a template linking to the per-target view sends the
            // filter the name that will actually match both aliases.
            $rows[$i]['target_key']   = $type === '' ? null : $can;
        }

        return $rows;
    }

    /**
     * Name one target. For the per-target header, where there is exactly one.
     */
    public static function name(string $type, int $id): ?string
    {
        if ($id < 1) return null;
        return self::namesFor(self::canonical($type), [$id])[$id] ?? null;
    }

    /**
     * @param list<int> $ids
     * @return array<int,string>
     */
    private static function namesFor(string $canonicalType, array $ids): array
    {
        $spec = self::MAP[$canonicalType] ?? null;
        if ($spec === null || $spec[1] === null || $ids === []) return [];

        [, $table, $candidates] = $spec;

        // Only the columns this deployment actually has. A half-migrated database is the
        // normal state here (see OptionalColumn), and a name is the most survivable thing
        // on the screen — never let its absence throw.
        $cols = array_values(array_filter(
            $candidates,
            static fn (string $c): bool => OptionalColumn::on($table, $c)
        ));
        if ($cols === []) return [];

        try {
            $rows = DB::table($table)->whereIn('id', $ids)
                ->select(array_merge(['id'], $cols))->get();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            foreach ($cols as $c) {
                $v = trim((string) ($row->{$c} ?? ''));
                if ($v !== '') { $out[(int) $row->id] = $v; break; }
            }
        }

        return $out;
    }
}
