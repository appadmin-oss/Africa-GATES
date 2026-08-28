<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use AfricaGates\Judge\Services\JudgeService;
use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Who is invited to a ceremony as a guest of honour, and what they are given.
 *
 * ── THE SHAPE OF THE RUN ─────────────────────────────────────────────────────
 *
 * An award programme leads to a ceremony (`gates_site_events.programme_id`). The people
 * invited to it are the published shortlist of that programme's current cycle, plus the
 * panel that judged it. Each of them gets one row in `gates_event_invites` carrying:
 *
 *   • a reference, which is their identity at the door and their discount code
 *   • a secret, which never leaves the server and is what makes the rotating ID
 *     on their phone impossible to forge from anything they can see
 *   • a guest quota, resolved and STORED at mint time, so the number in the letter and
 *     the number the code allows cannot drift apart afterwards
 *
 * ── WHY THE REFERENCE IS ALSO THE DISCOUNT CODE ──────────────────────────────
 *
 * Twenty-five guests learn it, which is the point of asking. It is safe because the
 * reference alone opens nothing: the door verifies an HMAC under the per-invite secret
 * (see {@see InvitePass}), so a guest holding the code they were given to buy a ticket
 * cannot mint a pass with it. And it buys exact attribution — a redemption names the
 * person who mobilised it, without a second identifier for an operator to reconcile.
 *
 * ── NOTHING HERE SENDS ───────────────────────────────────────────────────────
 *
 * Minting and sending are separate on purpose. An operator can build the list, read it,
 * see who is unreachable and who is ambiguous, and correct it, before a single message
 * leaves — because an invitation carrying the wrong person's name and personal reference
 * cannot be recalled. {@see InviteMailer} does the sending, against rows this made.
 */
final class EventInvites
{
    /**
     * The setup step that creates `gates_event_programmes`.
     *
     * Named here rather than typed into a notice, so the string an operator is asked to
     * re-run is the same string the runner matches against its own step list.
     */
    public const MIGRATION = '2026_11_01_event_invites.php';

    /** The ceremony a programme is honoured at, or null when none is linked. */
    public static function eventForProgramme(int $programmeId): ?object
    {
        if ($programmeId < 1) return null;
        try {
            return DB::table('gates_site_events as e')
                ->join('gates_event_programmes as ep', 'ep.event_id', '=', 'e.id')
                ->where('ep.programme_id', $programmeId)
                ->where('e.status', 'published')
                ->orderBy('e.event_date')
                ->first(['e.*']) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The awards this ceremony is for.
     *
     * Plural, because one gala night hands out several. A column would have forced an
     * operator to run four events for one evening or leave three shortlists uninvited.
     *
     * @return list<object> with `id` and `title`
     */
    public static function programmesFor(int $eventId): array
    {
        try {
            return DB::table('gates_event_programmes as ep')
                ->join('gates_award_programmes as p', 'p.id', '=', 'ep.programme_id')
                ->where('ep.event_id', $eventId)
                ->orderBy('p.sort_order')->orderBy('p.title')
                ->get(['p.id', 'p.title', 'p.slug'])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Replace the set of awards a ceremony is for.
     *
     * @param list<int> $programmeIds
     */
    public static function setProgrammes(int $eventId, array $programmeIds): void
    {
        try {
            $keep = [];
            foreach ($programmeIds as $id) {
                $id = (int) $id;
                if ($id > 0) $keep[$id] = true;
            }

            DB::table('gates_event_programmes')->where('event_id', $eventId)
                ->whereNotIn('programme_id', array_keys($keep) ?: [0])->delete();

            foreach (array_keys($keep) as $id) {
                DB::table('gates_event_programmes')->insertOrIgnore([
                    'event_id' => $eventId, 'programme_id' => $id,
                ]);
            }
        } catch (\Throwable) {
            // A ceremony with no programmes shows the invitation screen's "nothing to
            // invite" state, which is honest. Losing the save on the rest of the event
            // form over this would not be.
        }
    }

    /**
     * The cheapest tier on sale — the "minimum support" an invitation asks for.
     *
     * Anchored to the ladder rather than to a number in the copy. A letter that names a
     * figure the ticket page does not sell is a letter that has to be re-sent, and the
     * organiser moves prices right up to the week of the event.
     *
     * PAID tiers only, and that is the whole subtlety. `gates_event_tiers` records that
     * "zero is a real price, not a missing one: a free tier inside a paid event (press,
     * sponsors, students) is the ordinary case" — so the cheapest tier by price is quite
     * often a comp tier nobody can buy. An invitation that asks a nominee's guests to
     * support at the lowest tier and points them at the press list asks for nothing.
     *
     * @return ?object with `name` and `price_naira`
     */
    public static function lowestTier(int $eventId): ?object
    {
        try {
            return DB::table('gates_event_tiers')
                ->where('event_id', $eventId)
                ->where('is_active', 1)
                ->where('price_naira', '>', 0)
                ->orderBy('price_naira')
                ->first(['id', 'name', 'price_naira']) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Why there is nobody to invite, in the order an operator would fix it.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * THE BUG THIS EXISTS FOR
     * ══════════════════════════════════════════════════════════════════════════
     *
     * {@see shortlisted()} walks the linked programmes and `continue`s past a programme
     * with no cycle, and past a cycle with no PUBLISHED shortlist. {@see judges()} returns
     * an empty array the moment no programme is linked at all. And the picker that links
     * them is hidden outright on a deployment that has not run the migration.
     *
     * Five different failures, and every one of them renders as the same thing: a table of
     * zeroes. The operator cannot tell "this event is not linked to an award" from "the
     * shortlist has not been published yet" from "you need to run /__setup/migrate", and
     * there is NO SHELL ON PRODUCTION to go and look — which is the condition under which
     * a silent empty state stops being tidy and becomes the whole problem.
     *
     * So the counting and the diagnosis are the same walk, done here, once. Each blocker
     * names what is wrong, what to do, and where — because "where" is the part an operator
     * cannot guess and the part that costs an afternoon.
     *
     * `hard` marks the two that stop the run dead — no table, no linked award. Everything
     * else is one programme's problem on a night with several, and the run should still go
     * for the awards that ARE ready rather than refusing the whole evening.
     *
     * `rerun` names the setup step to apply again, in the one case where the ordinary
     * migrate endpoint cannot help — see the branch that sets it. Empty everywhere else.
     *
     * @return list<array{what:string, fix:string, href:string, hard:bool, rerun:string}>
     */
    public static function readiness(int $eventId): array
    {
        $out = [];

        // 0 · The table itself. An operator uploads the zip and runs /__setup/migrate as
        //     two separate acts, and between them this feature is invisible rather than
        //     broken — which reads as "it was never built".
        try {
            $ready = DB::schema()->hasTable('gates_event_programmes');
        } catch (\Throwable) {
            $ready = false;
        }
        if (!$ready) {
            // TWO DIFFERENT FAULTS, and telling an operator to "run the migration" when
            // they already have is the reason this needed a second look.
            //
            // The ledger is written after a step is included, so a step that THREW is not
            // recorded and the migrate endpoint will retry it — that is the ordinary case,
            // and the fix is to finish the run (151 steps, four per request, chained by a
            // meta refresh: a closed tab stops it part way and nothing says so).
            //
            // The other case is a step recorded as applied whose table is not there. The
            // migrate endpoint then skips it forever, and with no shell there is no way
            // back. That one needs {@see MigrationRunner::rerun()}, and the notice has to
            // name it rather than repeat an instruction that cannot work.
            $pending = [];
            try { $pending = MigrationRunner::status()['pending'] ?? []; } catch (\Throwable) {}
            $mine    = self::MIGRATION;
            $waiting = in_array($mine, $pending, true);

            return [[
                'what' => 'The awards-to-event link has not been created in the database yet.',
                'fix'  => $waiting
                    ? 'Setup is not finished — ' . count($pending) . ' step'
                      . (count($pending) === 1 ? '' : 's') . ' still to apply, and this is one '
                      . 'of them. Open /__setup/migrate?token=… with the SETUP_TOKEN from your '
                      . '.env and LEAVE THE TAB OPEN: it applies four steps per request and '
                      . 'refreshes itself until it says "setup complete".'
                    : 'The step that creates it is recorded as already applied, so the migrate '
                      . 'endpoint will skip it — it has to be run again on its own. Use the '
                      . 'button below.',
                'href'  => '',
                'hard'  => true,
                // Only offered in the case where the ordinary route cannot help.
                'rerun' => $waiting ? '' : $mine,
            ]];
        }

        $programmes = self::programmesFor($eventId);
        if ($programmes === []) {
            return [[
                'what' => 'This event is not linked to any award programme.',
                'fix'  => 'Open the event and tick the awards being presented, under '
                        . '"Awards presented at this event".',
                'href' => '/admin/events/' . $eventId,
                'hard' => true,
                'rerun' => '',
            ]];
        }

        // 1 · Per programme, the two things shortlisted() walks past in silence.
        foreach ($programmes as $programme) {
            $cycle = DB::table('gates_award_cycles')
                ->where('programme_id', $programme->id)
                ->orderByDesc('year')->orderByDesc('id')
                ->first(['id', 'year']);

            if (!$cycle) {
                $out[] = [
                    'what' => $programme->title . ' has no award cycle yet.',
                    'fix'  => 'Create this year\'s cycle before inviting anybody to be honoured in it.',
                    'href' => '/admin/programmes/' . (int) $programme->id,
                    'hard' => false,
                    'rerun' => '',
                ];
                continue;
            }

            if (ShortlistService::shortlistedIn((int) $cycle->id) === []) {
                $out[] = [
                    'what' => $programme->title . ' (' . (int) $cycle->year . ') has no PUBLISHED shortlist.',
                    // The distinction that costs the afternoon: a shortlist can be fully
                    // drawn up and still be a draft, and a draft is invisible here on
                    // purpose — an invitation is the loudest way to tell somebody they
                    // are on a list that has not been decided.
                    'fix'  => 'Draw the shortlist and publish it. A drafted shortlist is '
                            . 'deliberately not invited from.',
                    'href' => '/admin/shortlists?cycle=' . (int) $cycle->id,
                    'hard' => false,
                    'rerun' => '',
                ];
            }
        }

        // 2 · The panel. Judges with no programmes named are on every panel, so this is
        //     empty only when there are genuinely no active judges at all.
        if (self::judges($eventId) === []) {
            $out[] = [
                'what' => 'No active judge is on the panel for these awards.',
                'fix'  => 'Appoint judges, or leave this — the nominee half of the run works '
                        . 'without them.',
                'href' => '/admin/judges',
                'hard' => false,
                'rerun' => '',
            ];
        }

        return $out;
    }

    /**
     * Everybody who should be invited, by audience, with why any of them cannot be.
     *
     * Read-only. Nothing is minted and nothing is sent.
     *
     * @return array<string, array{
     *   audience:array<string,mixed>,
     *   ready:list<array{name:string,email:string,nominee_id:int,judge_id:int,category:string}>,
     *   unreachable:list<array{name:string,why:string}>,
     *   already:int
     * }>
     */
    public static function plan(int $eventId): array
    {
        $event = DB::table('gates_site_events')->where('id', $eventId)->first();
        $out   = [];

        foreach (InviteAudience::all() as $key) {
            $spec = InviteAudience::spec($key);
            $rows = $key === InviteAudience::JUDGE
                ? self::judges($eventId)
                : self::shortlisted($eventId);

            $ready = [];
            $bad   = [];
            foreach ($rows as $r) {
                if ($r['email'] === '') { $bad[] = ['name' => $r['name'], 'why' => $r['why']]; continue; }
                $ready[] = $r;
            }

            $out[$key] = [
                'audience'    => $spec,
                'ready'       => $ready,
                'unreachable' => $bad,
                'already'     => $event ? self::countFor($eventId, $key) : 0,
            ];
        }

        return $out;
    }

    /**
     * An invitation-shaped object that was never saved, for the preview and the test send.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY THIS EXISTS
     * ══════════════════════════════════════════════════════════════════════════
     *
     * The preview needed a real row, so an operator could not look at the letter until
     * they had already minted a list — and the moment you most want to see what you are
     * about to send is BEFORE you build it, not after. The same for a test to yourself and
     * for the PDF: all three were unreachable on a fresh ceremony.
     *
     * Nothing here is written. The reference is a fixed, obviously-fake string rather than
     * a freshly-minted one, because {@see freshReference()} would burn a code out of the
     * space real invitations draw from every time somebody pressed Preview. The secret is
     * real and random, so the rotating pass on a previewed ID behaves exactly as it will
     * on a real one — a preview whose QR does not rotate teaches the operator the wrong
     * thing about what they are sending.
     */
    public static function sample(int $eventId, string $audience = InviteAudience::NOMINEE): object
    {
        if (!InviteAudience::isValid($audience)) $audience = InviteAudience::NOMINEE;
        $spec = InviteAudience::spec($audience);

        return (object) [
            'id'            => 0,
            'event_id'      => $eventId,
            'cycle_id'      => null,
            'audience'      => $audience,
            'nominee_id'    => null,
            'judge_id'      => null,
            // A name that cannot be mistaken for a real invitee in a screenshot.
            'name'          => 'Sample ' . $spec['one'],
            'email'         => '',
            'reference'     => 'AGI-SAMPLE0',
            'id_secret'     => InvitePass::secret(),
            'discount_code' => 'AGI-SAMPLE0',
            'guest_quota'   => (int) $spec['quota'],
            'created_at'    => Carbon::now()->toDateTimeString(),
            'sent_at'       => null,
            'opened_at'     => null,
            'scanned_at'    => null,
        ];
    }

    /**
     * Mint one invite, or return the existing one for that person.
     *
     * Idempotent on (event, email) — the table's own unique key says so, and an operator
     * running the build twice must not produce two references for one person, each with
     * its own discount code, only one of which is in the letter they were sent.
     *
     * @param array{name:string,email:string,nominee_id:int,judge_id:int} $who
     */
    public static function mint(int $eventId, string $audience, array $who, ?int $cycleId = null): ?object
    {
        if (!InviteAudience::isValid($audience)) return null;

        $email = EmailOptOut::normalise($who['email'] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;

        try {
            $existing = DB::table('gates_event_invites')
                ->where('event_id', $eventId)->where('email', $email)->first();
            if ($existing) return $existing;

            $spec      = InviteAudience::spec($audience);
            $reference  = self::freshReference();
            $quota      = (int) $spec['quota'];

            $id = (int) DB::table('gates_event_invites')->insertGetId([
                'event_id'      => $eventId,
                'cycle_id'      => $cycleId,
                'audience'      => $audience,
                'nominee_id'    => ($who['nominee_id'] ?? 0) > 0 ? (int) $who['nominee_id'] : null,
                'judge_id'      => ($who['judge_id'] ?? 0) > 0 ? (int) $who['judge_id'] : null,
                'name'          => mb_substr(trim((string) ($who['name'] ?? '')), 0, 160),
                'email'         => $email,
                'reference'     => $reference,
                'id_secret'     => InvitePass::secret(),
                'discount_code' => $reference,
                'guest_quota'   => $quota,
                'created_at'    => Carbon::now()->toDateTimeString(),
            ]);

            self::mintCode($eventId, $reference, $quota, (string) $spec['label']);

            return DB::table('gates_event_invites')->where('id', $id)->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The discount code an invitee's guests spend.
     *
     * `max_uses` is the invitee's own quota, so the code runs out exactly where the
     * letter said it would. `max_per_email` is 1 because the quota counts PEOPLE — one
     * guest buying twice would otherwise consume two of the twenty-five somebody else
     * was promised.
     */
    private static function mintCode(int $eventId, string $code, int $quota, string $label): void
    {
        try {
            if (DB::table('gates_event_codes')->where('event_id', $eventId)->where('code', $code)->exists()) {
                return;
            }
            DB::table('gates_event_codes')->insert([
                'event_id'      => $eventId,
                'code'          => $code,
                'label'         => mb_substr($label . ' — guest of honour', 0, 120),
                'kind'          => 'percent',
                'amount'        => InviteAudience::discountPercent(),
                'tier_ids'      => null,          // every tier: they choose where to sit
                'max_uses'      => $quota,
                'max_per_email' => 1,
                'used_count'    => 0,
                'is_active'     => 1,
                'created_at'    => Carbon::now()->toDateTimeString(),
                'updated_at'    => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable) {
            // A missing code is visible on the invite row (discount_code with no code row)
            // and the admin screen reports it. It must not lose the invite itself.
        }
    }

    /** @return list<object> */
    public static function forEvent(int $eventId, string $audience = ''): array
    {
        try {
            $q = DB::table('gates_event_invites')->where('event_id', $eventId);
            if ($audience !== '') $q->where('audience', $audience);

            return $q->orderBy('audience')->orderBy('name')->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public static function byReference(string $reference): ?object
    {
        try {
            return DB::table('gates_event_invites')
                ->where('reference', strtoupper(trim($reference)))->first() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** The live mobile ID. Absolute, because it is opened from an email. */
    public static function idUrl(string $reference, string $base = ''): string
    {
        $base = $base !== '' ? rtrim($base, '/') : rtrim(SiteUrl::base(), '/');

        return $base . '/honour/' . rawurlencode(strtoupper($reference));
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function countFor(int $eventId, string $audience): int
    {
        try {
            return (int) DB::table('gates_event_invites')
                ->where('event_id', $eventId)->where('audience', $audience)->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * A reference nothing else holds.
     *
     * Retried rather than trusted: `reference` is UNIQUE, and a collision on a random
     * eight-character token is vanishingly unlikely but would surface as a failed mint
     * for one person in the middle of a run rather than as anything an operator could read.
     */
    private static function freshReference(): string
    {
        for ($i = 0; $i < 8; $i++) {
            $r = InvitePass::reference();
            if (!DB::table('gates_event_invites')->where('reference', $r)->exists()) return $r;
        }

        throw new \RuntimeException('Could not mint a unique invitation reference.');
    }

    /**
     * The published shortlist of every award this ceremony is for.
     *
     * Across ALL linked programmes, each at its own current cycle — a gala honouring four
     * awards invites four shortlists, and they do not share a cycle. The programme set is
     * the one the operator stated on the event; it is not looked up from a settings slug,
     * because that would be a second source for a fact already recorded.
     *
     * @return list<array{name:string,email:string,nominee_id:int,judge_id:int,category:string,why:string,cycle_id:int}>
     */
    private static function shortlisted(int $eventId): array
    {
        $out = [];

        foreach (self::programmesFor($eventId) as $programme) {
            $cycle = DB::table('gates_award_cycles')
                ->where('programme_id', $programme->id)
                ->orderByDesc('year')->orderByDesc('id')
                ->first(['id']);
            if (!$cycle) continue;

            $cycleId = (int) $cycle->id;
            $onList  = ShortlistService::shortlistedIn($cycleId);
            if ($onList === []) continue;

            $rows = DB::table('gates_nominees as n')
                ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->where('c.cycle_id', $cycleId)
                ->whereIn('n.id', array_keys($onList))
                ->whereNull('n.merged_into')
                ->select('n.id', 'n.name', 'n.profile_id', 'c.title as category')
                ->orderBy('c.title')->orderBy('n.name')
                ->get()->all();

            foreach ($rows as $n) {
                $found = NomineeAddress::candidates($n, $cycleId);
                $out[] = [
                    'name'       => (string) $n->name,
                    'email'      => count($found) === 1 ? $found[0] : '',
                    'nominee_id' => (int) $n->id,
                    'judge_id'   => 0,
                    // The award, not just the category: on a four-programme night "Community
                    // Engagement" alone does not say which award it belongs to.
                    'category'   => trim((string) $programme->title) . ' · ' . (string) $n->category,
                    'cycle_id'   => $cycleId,
                    // Named, not just counted: "two people share this name" and "we have no
                    // address" are different problems and an operator fixes them differently.
                    'why'        => $found === []
                        ? 'No address on the nomination or the linked profile.'
                        : (count($found) > 1
                            ? 'More than one nominee is approved under this name — pick the right address by hand.'
                            : ''),
                ];
            }
        }

        return $out;
    }

    /**
     * The panel that judged the awards this ceremony is for.
     *
     * Scoped to those programmes, not "every judge on the platform". `programme_ids` is a
     * JSON list on the judge row, so the filter is applied in PHP — the alternative is a
     * LIKE against a JSON string, which matches programme 1 inside programme 11.
     *
     * A judge with NO programmes recorded is included: an unassigned panellist is far more
     * likely to be a record nobody finished than a person who judged nothing, and leaving
     * a judge out of a ceremony they sat for is the worse mistake.
     *
     * Through realJudges(), so the sandbox's rehearsal judge is never invited to a real one.
     *
     * @return list<array{name:string,email:string,nominee_id:int,judge_id:int,category:string,why:string,cycle_id:int}>
     */
    private static function judges(int $eventId): array
    {
        $wanted = [];
        foreach (self::programmesFor($eventId) as $p) $wanted[(int) $p->id] = true;
        if ($wanted === []) return [];

        try {
            $rows = JudgeService::realJudges()
                ->where('is_active', 1)
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'programme_ids'])->all();
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $j) {
            $ids = [];
            if (!empty($j->programme_ids)) {
                $decoded = json_decode((string) $j->programme_ids, true);
                if (is_array($decoded)) $ids = array_map('intval', $decoded);
            }

            if ($ids !== [] && array_intersect($ids, array_keys($wanted)) === []) continue;

            $e = EmailOptOut::normalise((string) ($j->email ?? ''));
            $out[] = [
                'name'       => (string) $j->name,
                'email'      => filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '',
                'nominee_id' => 0,
                'judge_id'   => (int) $j->id,
                'category'   => 'Judging panel',
                'cycle_id'   => 0,
                'why'        => filter_var($e, FILTER_VALIDATE_EMAIL) ? '' : 'No usable address on the judge record.',
            ];
        }

        return $out;
    }
}
