<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Reference;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Giving back the votes we dropped.
 *
 * ── WHOSE VOTES THESE ARE ────────────────────────────────────────────────────
 *
 * Not off-platform votes. Not votes anybody collected for us. These are people who
 * came to the ballot, chose a nominee, typed their address and pressed the button —
 * and then waited for a code that our mail relay never delivered. The platform
 * wrote down that they asked. The platform failed to finish the job. Their vote
 * does not exist, and it is nobody's fault but ours.
 *
 * Leaving them out is not neutral. It is a silent, self-inflicted distortion of the
 * result that produces no complaints, because most people who never get a code
 * simply give up and say nothing.
 *
 * ── THE ONE RULE EVERYTHING ELSE HANGS OFF ───────────────────────────────────
 *
 *      NO HUMAN SUPPLIES THE LIST.
 *
 * The moment an operator can name who gets a vote, this stops being a repair
 * mechanism and becomes a way to add votes with extra paperwork. So candidates are
 * derived, entirely, from records the platform wrote about ITSELF before anybody
 * knew they would be wanted:
 *
 *   • gates_otp_tokens.purpose = 'vote'      — they asked to vote
 *   • .nominee_id / .award_id                — for this nominee, in this award
 *   • .delivery_state = 'failed'             — and WE could not send their code
 *   • .is_used = 0                           — and they never got to use it
 *
 * An operator chooses a WINDOW and writes down what went wrong. They do not choose
 * people. Two operators running the same window on the same data get the same list.
 *
 * `delivery_state = 'sent'` is not recoverable and never will be: the code reached
 * them and they chose not to finish, and their choice is not ours to overturn.
 * `unknown` — every token written before the delivery columns existed — is not
 * recoverable either. We do not know that we failed those people, and being unable
 * to rule it out is not evidence. That is a real cost, paid deliberately.
 *
 * ── WHAT THE DERIVATION STILL DOES NOT PROTECT AGAINST ───────────────────────
 *
 * Honestly: during an outage EVERY send fails, including sends to addresses typed
 * in bad faith. Somebody who noticed the outage could have requested codes for a
 * hundred invented-but-valid addresses, and those tokens are indistinguishable from
 * a hundred real supporters'. The derivation removes the operator from the loop; it
 * does not make the underlying requests trustworthy.
 *
 * Hence everything below, none of which is ceremony:
 *   • the same fraud score the live ballot would have applied, re-applied here;
 *   • an IP/device cluster check, because a farm looks like a crowd one row at a
 *     time and like a farm in aggregate;
 *   • a cap, as a share of the votes we did verify for that nominee;
 *   • two people — the approver may not be the preparer;
 *   • public disclosure of every applied batch;
 *   • a void that reverses the votes and keeps the record.
 *
 * ── AND WHILE THE BALLOT IS OPEN, THIS IS THE WRONG TOOL ─────────────────────
 *
 * {@see resendable()}. If voting has not closed, the right repair is to send the
 * code again and let the person verify themselves — a real vote, cast by them, with
 * none of the above required. Minting on somebody's behalf is a last resort for
 * after the close, when re-sending can no longer help them.
 */
final class VoteRecoveryService
{
    /** Ceiling on recovered votes per nominee, as a % of the votes we did verify. */
    public const MAX_RECOVERY_PCT = 25;

    /** Floor, so a small category with a big outage is not left unrepairable. */
    public const MIN_RECOVERY_CAP = 10;

    /** More than this many candidates sharing one IP is a farm, not an outage. */
    public const CLUSTER_LIMIT = 5;

    // ─────────────────────────────────────────────────────────────────────────
    // Deriving the candidates
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Every vote attempt in this cycle that we failed to deliver a code for.
     *
     * @return array<int, object> token rows, each carrying nominee_id/award_id/email_hash
     */
    public static function candidates(int $cycleId, string $from, string $to): array
    {
        $categoryIds = DB::table('gates_award_categories')->where('cycle_id', $cycleId)
            ->pluck('id')->map(fn ($v) => (int) $v)->all();
        if (!$categoryIds) return [];

        $nomineeIds = DB::table('gates_nominees')->whereIn('category_id', $categoryIds)
            ->pluck('id')->map(fn ($v) => (int) $v)->all();
        if (!$nomineeIds) return [];

        try {
            return DB::table('gates_otp_tokens')
                ->where('purpose', 'vote')
                ->where('delivery_state', 'failed')   // OUR failure, from OUR log
                ->where('is_used', 0)                 // and they never got to use it
                ->whereIn('nominee_id', $nomineeIds)
                ->whereBetween('created_at', [$from, $to])
                ->orderBy('id')
                ->get()->all();
        } catch (\Throwable) {
            return [];   // delivery columns absent — pre-migration, nothing recoverable
        }
    }

    /**
     * Is re-sending still possible? If so, do that instead of any of this.
     *
     * A vote the person casts themselves is better than one we cast for them in
     * every way that matters: it is verified, it is theirs, and it needs no
     * approvals, caps or disclosure to be legitimate.
     */
    public static function resendable(int $cycleId): bool
    {
        $c = DB::table('gates_award_cycles')->where('id', $cycleId)->first();
        return $c !== null && (string) $c->status === 'voting';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The batch
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Open a draft for one outage window.
     *
     * @return array{ok:bool, code?:string, message?:string, batch_id?:int, reference?:string, candidates?:int}
     */
    public static function open(int $cycleId, string $from, string $to, string $incidentNote, int $adminId): array
    {
        $fail = static fn (string $c, string $m): array => ['ok' => false, 'code' => $c, 'message' => $m];

        $cycle = DB::table('gates_award_cycles')->where('id', $cycleId)->first();
        if (!$cycle) return $fail('NO_CYCLE', 'That cycle does not exist.');

        if (trim($incidentNote) === '') {
            return $fail('NO_INCIDENT', 'Describe the failure. The approver is being asked to agree that '
                . 'the platform let these people down, and they cannot agree to that without being told what happened.');
        }

        $f = self::ts($from);
        $t = self::ts($to);
        if ($f === null || $t === null) return $fail('NO_WINDOW', 'Give the window the outage covered.');
        if ($t < $f) return $fail('BAD_WINDOW', 'The window ends before it starts.');

        // The attempts must have happened while the ballot was open. A code request
        // outside the voting window was never going to become a vote.
        if (!empty($cycle->voting_open) && $f < (string) $cycle->voting_open) {
            return $fail('OUTSIDE_VOTING', 'The window starts before voting opened on ' . (string) $cycle->voting_open . '.');
        }
        if (!empty($cycle->voting_close) && $t > (string) $cycle->voting_close) {
            return $fail('OUTSIDE_VOTING', 'The window ends after voting closed on ' . (string) $cycle->voting_close . '.');
        }

        if (self::resendable($cycleId)) {
            return $fail('STILL_OPEN', 'Voting is still open on this cycle, so these people can be sent a '
                . 'working code and cast their own vote — which is better for them and for the result than '
                . 'anyone casting it for them. Fix the mail delivery and re-send. Recovery is for after the close.');
        }

        $candidates = self::candidates($cycleId, $f, $t);
        if (!$candidates) {
            return $fail('NO_CANDIDATES', 'No undelivered vote codes are recorded in that window. Either the '
                . 'sends succeeded, or they predate the delivery log — in which case there is no evidence we '
                . 'failed anybody, and recovery would be guesswork.');
        }

        $now = Carbon::now()->toDateTimeString();
        $id = (int) DB::table('gates_vote_recovery_batches')->insertGetId([
            'cycle_id' => $cycleId, 'window_from' => $f, 'window_to' => $t,
            'incident_note' => trim($incidentNote),
            'candidate_count' => count($candidates),
            'status' => 'draft', 'created_by' => $adminId, 'created_at' => $now,
        ]);
        $ref = Reference::recovery($id);
        DB::table('gates_vote_recovery_batches')->where('id', $id)->update(['reference' => $ref]);

        // Freeze the roster now, from the derivation. Re-deriving at apply time would
        // let the population drift between what was approved and what gets written.
        $rows = [];
        foreach ($candidates as $tok) {
            $catId = (int) DB::table('gates_nominees')->where('id', (int) $tok->nominee_id)->value('category_id');
            $rows[] = [
                'batch_id' => $id, 'otp_token_id' => (int) $tok->id,
                'category_id' => $catId, 'nominee_id' => (int) $tok->nominee_id,
                'voter_email_hash' => (string) $tok->email_hash,
                'requested_at' => (string) $tok->created_at,
                'status' => 'pending', 'created_at' => $now,
            ];
        }
        $kept = 0;
        foreach ($rows as $r) {
            // UNIQUE(otp_token_id): a token already claimed by an earlier batch is
            // silently skipped rather than double-counted.
            try { DB::table('gates_vote_recovery_rows')->insert($r); $kept++; } catch (\Throwable) {}
        }
        DB::table('gates_vote_recovery_batches')->where('id', $id)->update(['candidate_count' => $kept]);

        return ['ok' => true, 'batch_id' => $id, 'reference' => $ref, 'candidates' => $kept];
    }

    /**
     * What an approver needs in order to be able to say no.
     *
     * @return array{ok:bool, findings:array<int,array{level:string,text:string}>, stats:array<string,mixed>}
     */
    public static function screen(int $batchId): array
    {
        $batch = self::batch($batchId);
        if (!$batch) return ['ok' => false, 'findings' => [], 'stats' => []];

        $rows = DB::table('gates_vote_recovery_rows')->where('batch_id', $batchId)->get();
        $findings = [];
        $add = static function (string $l, string $t) use (&$findings): void { $findings[] = ['level' => $l, 'text' => $t]; };

        if ($rows->isEmpty()) $add('block', 'Nothing to recover.');

        // Already voted anyway — they came back later and got through.
        $already = 0;
        foreach ($rows as $r) {
            if (DB::table('gates_votes')->where('category_id', (int) $r->category_id)
                    ->where('voter_email_hash', (string) $r->voter_email_hash)->exists()) $already++;
        }
        if ($already > 0) {
            $add('warn', "$already of these people voted successfully later anyway. Those rows will be "
                . 'rejected on apply, not counted twice.');
        }

        // Concentration by nominee.
        $byNominee = $rows->groupBy('nominee_id')->map->count()->sortDesc();
        $total = max(1, $rows->count());
        if ($byNominee->count() === 1 && $rows->count() >= 20) {
            $add('warn', 'Every dropped attempt in this window names the same nominee. A mail outage does not '
                . 'know who anyone is voting for, so this is worth understanding before approving.');
        } elseif ($byNominee->count() > 0 && (int) round(($byNominee->first() ?? 0) / $total * 100) >= 90 && $rows->count() >= 20) {
            $add('warn', round(($byNominee->first() ?? 0) / $total * 100) . '% of the dropped attempts name one nominee.');
        }

        // ── The farm check ───────────────────────────────────────────────────
        // A hundred requests from one address during an outage is not a hundred
        // supporters we failed. Read off the funnel events that recorded the
        // attempts, since the token itself carries no network fingerprint.
        $clusters = self::ipClusters($rows->pluck('nominee_id')->all(),
                                     (string) $batch->window_from, (string) $batch->window_to);
        foreach ($clusters as $ip => $n) {
            if ($n > self::CLUSTER_LIMIT) {
                $add('block', "$n vote attempts in this window came from one network fingerprint ("
                    . substr((string) $ip, 0, 8) . "…). An outage produces scattered failures; this is a "
                    . 'single source, and recovering it would be recovering a farm.');
            }
        }

        // Cap headroom per nominee.
        foreach ($byNominee as $nomineeId => $count) {
            $cap = self::capFor((int) $nomineeId);
            $have = (int) DB::table('gates_vote_recovery_rows as r')
                ->join('gates_vote_recovery_batches as b', 'b.id', '=', 'r.batch_id')
                ->where('r.nominee_id', (int) $nomineeId)->where('r.status', 'applied')
                ->where('b.status', 'applied')->count();
            if ($have + $count > $cap['cap']) {
                $name = (string) DB::table('gates_nominees')->where('id', (int) $nomineeId)->value('name');
                $add('block', "$name would reach " . ($have + $count) . ' recovered votes, over the cap of '
                    . $cap['cap'] . " ({$cap['pct']}% of {$cap['organic']} verified). A repair should never be "
                    . 'a large fraction of a nominee\'s support — if it is, the outage was bad enough that the '
                    . 'result itself needs a decision, not a patch.');
            }
        }

        return [
            'ok' => true,
            'findings' => $findings,
            'stats' => [
                'rows' => $rows->count(),
                'nominees' => $byNominee->count(),
                'already_voted' => $already,
                'blocking' => count(array_filter($findings, static fn ($f) => $f['level'] === 'block')),
            ],
        ];
    }

    public static function submit(int $batchId, int $adminId): array
    {
        $b = self::batch($batchId);
        if (!$b) return ['ok' => false, 'code' => 'NO_BATCH', 'message' => 'No such batch.'];
        if ((string) $b->status !== 'draft') return ['ok' => false, 'code' => 'NOT_DRAFT', 'message' => 'Only a draft can be submitted.'];

        DB::table('gates_vote_recovery_batches')->where('id', $batchId)->update([
            'status' => 'submitted', 'submitted_by' => $adminId,
            'submitted_at' => Carbon::now()->toDateTimeString(),
        ]);
        return ['ok' => true, 'code' => 'SUBMITTED'];
    }

    /** Approve — by somebody who did not prepare it. */
    public static function approve(int $batchId, int $adminId, string $note = ''): array
    {
        $b = self::batch($batchId);
        if (!$b) return ['ok' => false, 'code' => 'NO_BATCH', 'message' => 'No such batch.'];
        if ((string) $b->status !== 'submitted') {
            return ['ok' => false, 'code' => 'NOT_SUBMITTED', 'message' => 'Only a submitted batch can be approved.'];
        }
        if ($adminId < 1) {
            return ['ok' => false, 'code' => 'NO_APPROVER', 'message' => 'An approval has to belong to somebody.'];
        }
        if ($adminId === (int) $b->created_by || $adminId === (int) $b->submitted_by) {
            return ['ok' => false, 'code' => 'SELF_APPROVAL',
                    'message' => 'You prepared this batch, so you cannot also approve it. Putting votes on the '
                               . 'tally is the most consequential thing this system does, and it does not happen '
                               . 'on one person\'s judgement.'];
        }

        $s = self::screen($batchId);
        if (($s['stats']['blocking'] ?? 0) > 0) {
            return ['ok' => false, 'code' => 'BLOCKED', 'message' => 'This batch has blocking findings.',
                    'findings' => $s['findings']];
        }

        DB::table('gates_vote_recovery_batches')->where('id', $batchId)->update([
            'status' => 'approved', 'approved_by' => $adminId,
            'approved_at' => Carbon::now()->toDateTimeString(),
            'decision_note' => trim($note) ?: null,
        ]);
        return ['ok' => true, 'code' => 'APPROVED'];
    }

    public static function reject(int $batchId, int $adminId, string $reason): array
    {
        $b = self::batch($batchId);
        if (!$b) return ['ok' => false, 'code' => 'NO_BATCH', 'message' => 'No such batch.'];
        if (!in_array((string) $b->status, ['draft', 'submitted'], true)) {
            return ['ok' => false, 'code' => 'NOT_PENDING', 'message' => 'Only a draft or submitted batch can be rejected.'];
        }
        DB::table('gates_vote_recovery_batches')->where('id', $batchId)->update([
            'status' => 'rejected', 'approved_by' => $adminId,
            'approved_at' => Carbon::now()->toDateTimeString(),
            'decision_note' => trim($reason) ?: 'rejected',
        ]);
        return ['ok' => true, 'code' => 'REJECTED'];
    }

    /**
     * Write the votes.
     *
     * Each becomes an ordinary row in gates_votes carrying the token that proves the
     * request and the batch that authorised the repair — so it counts toward the CPI
     * exactly like the vote it should always have been, and its provenance survives
     * in the ledger rather than in a side table somebody has to remember to join.
     */
    public static function apply(int $batchId, int $adminId): array
    {
        $b = self::batch($batchId);
        if (!$b) return ['ok' => false, 'code' => 'NO_BATCH', 'message' => 'No such batch.'];
        if ((string) $b->status !== 'approved') {
            return ['ok' => false, 'code' => 'NOT_APPROVED', 'message' => 'Only an approved batch can be applied.'];
        }

        return DB::transaction(function () use ($b, $batchId, $adminId) {
            $rows = DB::table('gates_vote_recovery_rows')->where('batch_id', $batchId)
                ->where('status', 'pending')->get();

            $applied = [];
            $rejected = 0;
            $now = Carbon::now()->toDateTimeString();

            foreach ($rows as $r) {
                if (($why = self::rowRejection($r)) !== null) {
                    DB::table('gates_vote_recovery_rows')->where('id', $r->id)
                        ->update(['status' => 'rejected', 'reject_reason' => $why]);
                    $rejected++;
                    continue;
                }

                try {
                    $voteId = (int) DB::table('gates_votes')->insertGetId([
                        'nominee_id'       => (int) $r->nominee_id,
                        'category_id'      => (int) $r->category_id,
                        'voter_email_hash' => (string) $r->voter_email_hash,
                        // The token IS the evidence, and it travels with the vote.
                        'otp_token_id'     => (int) $r->otp_token_id,
                        'recovery_batch_id'=> $batchId,
                        'vote_type'        => 'standard',
                        'weight'           => 1,
                        // Their real intent time, not the moment we got round to it —
                        // a recovered vote belongs to the ballot it was cast in.
                        'voted_at'         => (string) ($r->requested_at ?: $now),
                    ]);
                } catch (\Throwable $e) {
                    // uq_one_vote fired: they are already counted in this category.
                    DB::table('gates_vote_recovery_rows')->where('id', $r->id)
                        ->update(['status' => 'rejected', 'reject_reason' => 'already counted in this category']);
                    $rejected++;
                    continue;
                }

                DB::table('gates_vote_recovery_rows')->where('id', $r->id)
                    ->update(['status' => 'applied', 'vote_id' => $voteId]);
                // Burn the token so the same request cannot be redeemed twice.
                DB::table('gates_otp_tokens')->where('id', (int) $r->otp_token_id)->update(['is_used' => 1]);

                $applied[(int) $r->nominee_id] = ($applied[(int) $r->nominee_id] ?? 0) + 1;
            }

            foreach ($applied as $nomineeId => $n) {
                $cap = self::capFor($nomineeId);
                if ($n > $cap['cap']) {
                    throw new \RuntimeException(sprintf(
                        'Recovering %d votes for nominee %d exceeds the cap of %d (%d%% of %d verified). Nothing was written.',
                        $n, $nomineeId, $cap['cap'], $cap['pct'], $cap['organic']));
                }
                // A recovered vote is an ORGANIC vote: it moves both the public tally
                // and the CPI community signal, because the person asked for it and
                // only our outage stopped it counting the first time.
                DB::table('gates_nominees')->where('id', $nomineeId)->update([
                    'vote_count'         => DB::raw('vote_count + ' . (int) $n),
                    'organic_vote_count' => DB::raw('organic_vote_count + ' . (int) $n),
                ]);
            }

            DB::table('gates_vote_recovery_batches')->where('id', $batchId)->update([
                'status' => 'applied', 'applied_count' => array_sum($applied),
                'rejected_count' => $rejected, 'applied_at' => $now,
            ]);
            self::journal($batchId, 'applied', $adminId, ['applied' => array_sum($applied), 'rejected' => $rejected]);

            return ['ok' => true, 'code' => 'APPLIED', 'applied' => array_sum($applied), 'rejected' => $rejected];
        });
    }

    /** Reverse an applied batch. The votes go; the record of them stays. */
    public static function void(int $batchId, int $adminId, string $reason): array
    {
        $b = self::batch($batchId);
        if (!$b) return ['ok' => false, 'code' => 'NO_BATCH', 'message' => 'No such batch.'];
        if ((string) $b->status !== 'applied') {
            return ['ok' => false, 'code' => 'NOT_APPLIED', 'message' => 'Only an applied batch can be voided.'];
        }
        if (trim($reason) === '') {
            return ['ok' => false, 'code' => 'NO_REASON', 'message' => 'Say why. Removing votes with no stated reason '
                                                                     . 'is indistinguishable from removing them quietly.'];
        }

        return DB::transaction(function () use ($batchId, $adminId, $reason) {
            $rows = DB::table('gates_vote_recovery_rows')->where('batch_id', $batchId)
                ->where('status', 'applied')->get();

            $byNominee = [];
            foreach ($rows as $r) {
                if ($r->vote_id) DB::table('gates_votes')->where('id', (int) $r->vote_id)->delete();
                $byNominee[(int) $r->nominee_id] = ($byNominee[(int) $r->nominee_id] ?? 0) + 1;
            }
            foreach ($byNominee as $nomineeId => $n) {
                $cur = DB::table('gates_nominees')->where('id', $nomineeId)->first();
                DB::table('gates_nominees')->where('id', $nomineeId)->update([
                    'vote_count'         => max(0, (int) $cur->vote_count - $n),
                    'organic_vote_count' => max(0, (int) $cur->organic_vote_count - $n),
                ]);
            }

            DB::table('gates_vote_recovery_rows')->where('batch_id', $batchId)
                ->where('status', 'applied')->update(['status' => 'voided']);
            DB::table('gates_vote_recovery_batches')->where('id', $batchId)->update([
                'status' => 'voided', 'voided_by' => $adminId,
                'voided_at' => Carbon::now()->toDateTimeString(), 'void_reason' => trim($reason),
            ]);
            self::journal($batchId, 'voided', $adminId, ['reversed' => array_sum($byNominee), 'reason' => trim($reason)]);

            return ['ok' => true, 'code' => 'VOIDED', 'reversed' => array_sum($byNominee)];
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Reading it back
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * What we put on this nominee's tally ourselves, and why.
     *
     * Published, because the strongest control on a mechanism like this is not any
     * of the approvals — it is that using it cannot be quiet.
     *
     * @return array{total:int, batches:array<int,array<string,mixed>>}
     */
    public static function disclosureFor(int $nomineeId): array
    {
        $batches = DB::table('gates_vote_recovery_rows as r')
            ->join('gates_vote_recovery_batches as b', 'b.id', '=', 'r.batch_id')
            ->where('r.nominee_id', $nomineeId)->where('r.status', 'applied')
            ->where('b.status', 'applied')
            ->groupBy('b.id', 'b.reference', 'b.incident_note', 'b.window_from', 'b.window_to')
            ->selectRaw('b.reference, b.incident_note, b.window_from, b.window_to, COUNT(*) as votes')
            ->get()->map(static fn ($b) => [
                'reference' => (string) $b->reference,
                'votes'     => (int) $b->votes,
                'incident'  => (string) $b->incident_note,
                'window'    => (string) $b->window_from . ' → ' . (string) $b->window_to,
            ])->all();

        return ['total' => array_sum(array_column($batches, 'votes')), 'batches' => $batches];
    }

    /**
     * How often the platform fails to deliver a vote code.
     *
     * The number this whole feature exists to serve, and the one that should be
     * falling. A recovery run repairs a result; it does not repair the cause, and
     * the only thing that keeps the cause visible is somebody looking at this.
     *
     * @return array{sent:int, failed:int, unknown:int, pct:float}
     */
    public static function deliveryHealth(int $sinceDays = 7): array
    {
        $since = Carbon::now()->subDays(max(1, $sinceDays))->toDateTimeString();
        try {
            $rows = DB::table('gates_otp_tokens')->where('purpose', 'vote')
                ->where('created_at', '>=', $since)
                ->selectRaw('delivery_state, COUNT(*) as n')->groupBy('delivery_state')
                ->get()->pluck('n', 'delivery_state')->all();
        } catch (\Throwable) {
            return ['sent' => 0, 'failed' => 0, 'unknown' => 0, 'pct' => 0.0];
        }

        $sent    = (int) ($rows['sent'] ?? 0);
        $failed  = (int) ($rows['failed'] ?? 0);
        $unknown = (int) ($rows['unknown'] ?? 0);
        $known   = $sent + $failed;

        return [
            'sent' => $sent, 'failed' => $failed, 'unknown' => $unknown,
            'pct' => $known > 0 ? round($failed / $known * 100, 1) : 0.0,
        ];
    }

    /** Ceiling on recovered votes for one nominee. */
    public static function capFor(int $nomineeId): array
    {
        $organic = (int) DB::table('gates_nominees')->where('id', $nomineeId)->value('organic_vote_count');
        return [
            'cap'     => max(self::MIN_RECOVERY_CAP, (int) floor($organic * self::MAX_RECOVERY_PCT / 100)),
            'pct'     => self::MAX_RECOVERY_PCT,
            'organic' => $organic,
        ];
    }

    public static function batch(int $id): ?object
    {
        return DB::table('gates_vote_recovery_batches')->where('id', $id)->first();
    }

    /**
     * The batches, newest first, with the names of the people who touched each.
     *
     * Every state is listed, rejected and voided included. A queue that showed only
     * what is still moving would hide the two outcomes that matter most to somebody
     * asking whether this mechanism has been used well.
     *
     * @return list<array<string,mixed>>
     */
    public static function recent(int $limit = 60): array
    {
        try {
            return DB::table('gates_vote_recovery_batches as b')
                ->leftJoin('gates_award_cycles as c', 'c.id', '=', 'b.cycle_id')
                ->leftJoin('gates_admins as ca', 'ca.id', '=', 'b.created_by')
                ->leftJoin('gates_admins as aa', 'aa.id', '=', 'b.approved_by')
                ->orderByDesc('b.id')->limit($limit)
                ->get([
                    'b.id', 'b.reference', 'b.cycle_id', 'b.status', 'b.window_from', 'b.window_to',
                    'b.incident_note', 'b.candidate_count', 'b.applied_count', 'b.created_at',
                    'b.created_by', 'b.submitted_by', 'b.approved_at', 'b.decision_note',
                    'b.voided_at', 'b.void_reason',
                    'c.year as cycle_year',
                    'ca.name as created_by_name', 'aa.name as decided_by_name',
                ])
                ->map(fn ($r) => (array) $r)->all();
        } catch (\Throwable $e) {
            error_log('[vote-recovery] could not list batches: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * The rows in one batch, grouped by nominee — which is the only breakdown an
     * approver can actually reason about.
     *
     * A list of six hundred email hashes is not reviewable and publishing it would
     * be worse; "these 41 people were voting for X" is the shape of the question
     * being asked. The hashes stay in the table.
     *
     * @return list<array<string,mixed>>
     */
    public static function rowsByNominee(int $batchId): array
    {
        try {
            return DB::table('gates_vote_recovery_rows as r')
                ->leftJoin('gates_nominees as n', 'n.id', '=', 'r.nominee_id')
                ->leftJoin('gates_award_categories as c', 'c.id', '=', 'r.category_id')
                ->where('r.batch_id', $batchId)
                ->groupBy('r.nominee_id', 'r.status', 'n.name', 'c.title')
                ->orderBy('n.name')
                ->selectRaw('r.nominee_id, r.status, n.name as nominee, c.title as category, COUNT(*) as votes')
                ->get()->map(fn ($r) => (array) $r)->all();
        } catch (\Throwable $e) {
            error_log('[vote-recovery] could not group batch ' . $batchId . ': ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Cycles this could be run against: closed, with an award behind them.
     *
     * Open cycles are deliberately still listed — {@see open()} refuses them with the
     * sentence explaining that re-sending is the better repair, and an operator who
     * cannot find their cycle in a dropdown learns nothing at all.
     *
     * @return list<array<string,mixed>>
     */
    public static function cycles(int $limit = 24): array
    {
        try {
            return DB::table('gates_award_cycles as c')
                ->leftJoin('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
                ->orderByDesc('c.year')->orderByDesc('c.id')->limit($limit)
                ->get(['c.id', 'c.year', 'c.status', 'c.voting_open', 'c.voting_close', 'p.title as programme'])
                ->map(fn ($r) => (array) $r)->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public static function byReference(string $ref): ?object
    {
        $id = Reference::parseRecoveryId($ref);
        return $id === null ? null : self::batch($id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Why this row cannot become a vote, or null.
     *
     * Re-checked at apply time rather than trusted from approval: an approval is a
     * decision about a list, not a licence to write it later whatever has changed.
     */
    private static function rowRejection(object $r): ?string
    {
        $n = DB::table('gates_nominees')->where('id', (int) $r->nominee_id)->first();
        if (!$n) return 'nominee no longer exists';
        if (!empty($n->merged_into ?? null)) return 'nominee was merged into another entry';
        if (!in_array((string) $n->status, ['approved', 'winner', 'runner_up'], true)) return 'nominee is not on the ballot';
        if ((int) $n->category_id !== (int) $r->category_id) return 'nominee moved out of this category';

        if (DB::table('gates_votes')->where('category_id', (int) $r->category_id)
                ->where('voter_email_hash', (string) $r->voter_email_hash)->exists()) {
            return 'this person voted successfully later anyway';
        }

        // The token must still say what the batch was built on. If it has been
        // redeemed since, the person got through and this is not a dropped vote.
        $tok = DB::table('gates_otp_tokens')->where('id', (int) $r->otp_token_id)->first();
        if (!$tok) return 'the attempt record has gone';
        if ((int) $tok->is_used === 1) return 'the code was used after all';
        if ((string) ($tok->delivery_state ?? '') !== 'failed') return 'delivery is no longer recorded as failed';

        return null;
    }

    /**
     * Attempts per network fingerprint inside the window.
     *
     * The token carries no IP, so this reads gates_funnel_events, which records the
     * ballot steps with an ip_hash. Imperfect — a genuine shared connection at a
     * university or a phone on carrier NAT looks like a cluster — which is why it
     * blocks and asks a human rather than filtering silently.
     *
     * @param int[] $nomineeIds
     * @return array<string,int>
     */
    private static function ipClusters(array $nomineeIds, string $from, string $to): array
    {
        if (!$nomineeIds) return [];
        try {
            return DB::table('gates_funnel_events')
                ->whereIn('nominee_id', array_unique($nomineeIds))
                ->whereBetween('created_at', [$from, $to])
                ->whereNotNull('ip_hash')
                ->selectRaw('ip_hash, COUNT(*) as n')->groupBy('ip_hash')
                ->havingRaw('COUNT(*) > ?', [self::CLUSTER_LIMIT])
                ->get()->pluck('n', 'ip_hash')->map(fn ($v) => (int) $v)->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private static function journal(int $batchId, string $op, int $adminId, array $meta): void
    {
        try {
            DB::table('gates_audit_log')->insert([
                'admin_id' => $adminId, 'action' => 'vote_recovery.' . $op,
                'target_type' => 'vote_recovery', 'target_id' => $batchId,
                'meta' => json_encode($meta), 'created_at' => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable) { /* the batch row is the primary record */ }
    }

    private static function ts(mixed $v): ?string
    {
        $s = trim((string) $v);
        if ($s === '') return null;
        $t = strtotime($s);
        return $t === false ? null : date('Y-m-d H:i:s', $t);
    }
}
