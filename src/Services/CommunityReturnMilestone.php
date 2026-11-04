<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * An edition's collective milestone — the moment locked community-return credits become
 * money a nominee can ask for.
 *
 * ── THE BRIEF, STATED ONCE ───────────────────────────────────────────────────
 *
 * Owner, 16 Oct 2026: before the milestone, every eligible paid vote earns a small share
 * (10% by default) held LOCKED in the nominee's wallet; once the milestone is OFFICIALLY
 * CONFIRMED the locked credits become eligible for withdrawal and every later eligible
 * vote earns the full share (50%), from that point on only.
 *
 * ── THE MEASURE ──────────────────────────────────────────────────────────────
 *
 * The edition's eligible paid-vote revenue: confirmed paid-vote contributions to any
 * nominee in the edition, less anything refunded, refund-requested or fraud-flagged —
 * the same exclusions the accrual applies, so the figure that unlocks the money and the
 * money it unlocks are counted by one rule. Votes are not the measure: a vote can be free,
 * and a milestone reached on free votes would release money nobody raised.
 *
 * ── CONFIRMATION IS AN ACT, NOT A NUMBER CROSSING A LINE ─────────────────────
 *
 * Crossing the target is SHOWN, never acted on. Somebody with authority confirms it, with
 * the figures in front of them, and the row records what they saw. That is how a result
 * is released here (CycleMaterialiser::release(), not a date), and for the same reason: the
 * confirmation is what nominees are told, so it must be a decision a named person made
 * rather than the side effect of a refund landing an hour late. It happens once per
 * edition — UNIQUE (cycle_id) — and is never undone by a later refund pulling the measure
 * back under the target: money already told to people as available stays available.
 *
 * ── THE RULES LOCK WITH THE FIRST ENTRY ──────────────────────────────────────
 *
 * An edition's return rules (model, both rates, the target) are written from
 * /admin/community-return as a cycle-scope rule set. Once the edition has anything on the
 * ledger they cannot change: a nominee earned that entry under the rule as it stood, and
 * the brief requires the rules to be published before the programme opens.
 */
final class CommunityReturnMilestone
{
    /** Every model an edition may be saved with. Anything else is refused, never defaulted. */
    public const MODELS = ['pool', 'threshold', 'milestone', 'flat', 'tiered', 'custom'];

    /** The models' names as an operator reads them, for the messages this class writes. */
    public const MODEL_LABELS = [
        'pool' => 'Target pool', 'threshold' => 'Threshold', 'milestone' => 'Two-stage',
        'flat' => 'Flat', 'tiered' => 'Tiered', 'custom' => 'Custom',
    ];

    /**
     * When this edition's community-return rule last CHANGED, as a rule key at cycle scope.
     * Letters sent before it described a different rule and are owed a correction.
     */
    public const CHANGED_AT = 'community_return_rule_changed_at';

    /** The rules an edition runs: house defaults, then the programme, then the cycle. */
    public static function rulesForCycle(?int $cycleId): array
    {
        $programmeId = null;
        if ($cycleId !== null && $cycleId > 0) {
            try {
                $p = DB::table('gates_award_cycles')->where('id', $cycleId)->value('programme_id');
                $programmeId = $p !== null ? (int) $p : null;
            } catch (\Throwable) {}
        }
        return (new RuleEngine())->effective($programmeId, ($cycleId !== null && $cycleId > 0) ? $cycleId : null);
    }

    /** When the edition's milestone was confirmed, or null. */
    public static function confirmedAt(?int $cycleId): ?string
    {
        if ($cycleId === null || $cycleId < 1) return null;
        try {
            $at = DB::table('gates_return_milestones')->where('cycle_id', $cycleId)->value('confirmed_at');
            return $at !== null ? (string) $at : null;
        } catch (\Throwable) {
            return null;   // table absent before its migration: no edition has confirmed
        }
    }

    /** The target in kobo, or 0 when none is set. */
    public static function targetKobo(?int $cycleId): int
    {
        $r = self::rulesForCycle($cycleId);
        return max(0, (int) ($r['community_return_milestone_naira'] ?? 0)) * 100;
    }

    /**
     * The edition's eligible paid-vote revenue, in kobo.
     *
     * Through the CATEGORY, never a join on gates_award_cycles: a missing cycle row would
     * silently collapse an inner join to nothing (CLAUDE.md, the edition-scale bullet).
     */
    public static function measuredKobo(int $cycleId): int
    {
        if ($cycleId < 1) return 0;
        try {
            $naira = (float) DB::table('gates_donations as d')
                ->join('gates_nominees as n', 'n.id', '=', 'd.intent_nominee_id')
                ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->where('c.cycle_id', $cycleId)
                ->where('d.tier', 'paid-vote')
                ->where('d.status', 'confirmed')
                ->whereNull('d.refunded_at')
                ->whereNull('d.refund_requested_at')
                ->whereNotExists(static function ($q) {
                    $q->select(DB::raw(1))->from('gates_votes as v')
                      ->whereColumn('v.donation_id', 'd.id')->where('v.fraud_flag', 1);
                })
                ->sum('d.amount_naira');
        } catch (\Throwable) {
            return 0;
        }
        return (int) round($naira * 100);
    }

    /**
     * Everything the admin screen shows about one edition.
     *
     * @return array{cycle_id:int, model:string, target_kobo:int, measured_kobo:int, reached:bool,
     *               confirmed_at:?string, confirmed_by:?int, locked_kobo:int, rules_locked:bool,
     *               rules:array<string,mixed>}
     */
    public static function progress(int $cycleId): array
    {
        $rules  = self::rulesForCycle($cycleId);
        $target = max(0, (int) ($rules['community_return_milestone_naira'] ?? 0)) * 100;
        $done   = null;
        try {
            $done = DB::table('gates_return_milestones')->where('cycle_id', $cycleId)->first();
        } catch (\Throwable) {}

        $locked = 0;
        try {
            $locked = (int) DB::table('gates_community_returns')->where('cycle_id', $cycleId)
                ->where('stage', CommunityReturnService::STAGE_LOCKED)->sum('amount_kobo');
        } catch (\Throwable) {}

        $measured = self::measuredKobo($cycleId);

        return [
            'cycle_id'      => $cycleId,
            'model'         => CommunityReturnService::modelOf($rules),
            'target_kobo'   => $target,
            'measured_kobo' => $measured,
            'reached'       => $target > 0 && $measured >= $target,
            'confirmed_at'  => $done ? (string) $done->confirmed_at : null,
            'confirmed_by'  => $done && $done->confirmed_by !== null ? (int) $done->confirmed_by : null,
            'locked_kobo'   => max(0, $locked),
            'rules_locked'  => self::rulesLocked($cycleId),
            'rules'         => $rules,
            'uses_milestone'=> CommunityReturnService::usesMilestone($rules),
            'summary'       => self::summary($rules),
        ];
    }

    /**
     * Confirm the milestone.
     *
     * Refuses unless the edition runs the milestone model, has a target, has reached it
     * and has not already been confirmed. The figures are re-read here rather than taken
     * from the screen, so what is recorded is what was true at the moment of the act.
     *
     * @return array{ok:bool, code:string, message:string, measured_kobo?:int}
     */
    public static function confirm(int $cycleId, int $adminId, string $note = ''): array
    {
        $no = static fn (string $code, string $msg): array => ['ok' => false, 'code' => $code, 'message' => $msg];

        $p = self::progress($cycleId);
        if (!$p['uses_milestone']) {
            return $no('NOT_MILESTONE', 'This edition’s rule has no milestone to confirm.');
        }
        if ($p['confirmed_at'] !== null) return $no('ALREADY', 'The milestone was already confirmed on ' . $p['confirmed_at'] . '.');
        if ($p['target_kobo'] <= 0)      return $no('NO_TARGET', 'Set a milestone target for this edition first.');
        if (!$p['reached']) {
            return $no('NOT_REACHED', 'The edition has raised ₦' . CommunityReturnService::naira($p['measured_kobo'])
                . ' of the ₦' . CommunityReturnService::naira($p['target_kobo']) . ' target.');
        }

        try {
            DB::table('gates_return_milestones')->insert([
                'cycle_id'      => $cycleId,
                'target_kobo'   => $p['target_kobo'],
                'measured_kobo' => $p['measured_kobo'],
                'confirmed_by'  => $adminId > 0 ? $adminId : null,
                'confirmed_at'  => Carbon::now()->toDateTimeString(),
                'note'          => mb_substr(trim($note), 0, 400) ?: null,
            ]);
        } catch (\Throwable) {
            // uq_return_milestone_cycle: somebody pressed it a moment earlier.
            return $no('ALREADY', 'The milestone has just been confirmed by somebody else.');
        }

        return ['ok' => true, 'code' => 'CONFIRMED', 'measured_kobo' => $p['measured_kobo'],
                'message' => 'Milestone confirmed at ₦' . CommunityReturnService::naira($p['measured_kobo'])
                           . '. Locked credits are now available'
                           . ($p['model'] === CommunityReturnService::MODEL_MILESTONE ? ', and later contributions earn the full share.' : '.')];
    }

    /** Has anything been written to the ledger for this edition? Then its rules are fixed. */
    public static function rulesLocked(int $cycleId): bool
    {
        try {
            return DB::table('gates_community_returns')->where('cycle_id', $cycleId)->exists();
        } catch (\Throwable) {
            return true;   // cannot tell: refuse the change rather than risk it
        }
    }

    /**
     * Set an edition's return rules.
     *
     * Percent in the form, basis points in the store, as on the Settings card. The locked
     * rate may not exceed the full rate: a milestone that LOWERS what people earn is not
     * one anybody would work toward, and it is one keystroke away in a form.
     *
     * @param  array<string,mixed> $in
     * @return array{ok:bool, message:string}
     */
    public static function saveEditionRules(int $cycleId, array $in): array
    {
        if ($cycleId < 1) return ['ok' => false, 'message' => 'No such edition.'];
        if (self::rulesLocked($cycleId)) {
            return ['ok' => false, 'message' => 'This edition already has community-return entries, so its rules are '
                . 'fixed: nominees earned them under the rules as they stood.'];
        }

        // ── THE MODEL MUST BE CHOSEN, NOT FALLEN INTO ────────────────────────────
        //
        // modelOf() turns anything it does not recognise into `threshold`, which is right for
        // READING a stored rule and wrong for SAVING one: an empty or mistyped choice became a
        // saved Threshold rule, and the voting letter then told every nominee "50% once you have
        // 250 votes" on an edition its operator had set up as a target pool (4 Nov 2026).
        $raw = (string) ($in['model'] ?? '');
        if (!in_array($raw, self::MODELS, true)) {
            return ['ok' => false, 'message' => 'Choose the rule this edition runs.'];
        }
        $model = CommunityReturnService::modelOf(['community_return_model' => $raw]);
        $full   = (int) round(max(0.0, min(100.0, (float) ($in['full_pct'] ?? 50))) * 100);
        $locked = (int) round(max(0.0, min(100.0, (float) ($in['locked_pct'] ?? 10))) * 100);
        $target = max(0, (int) ($in['milestone_naira'] ?? 0));

        // A milestone the chosen model never reads is a sign the wrong model is selected. The
        // same incident: ₦5,900,000 typed into the milestone, Threshold left in the picker, and
        // the form saved both — the figure stored and ignored, the nominees told a threshold.
        // Only the target pool, the two-stage model and a custom ladder held locked read it.
        $readsTarget = $model === CommunityReturnService::MODEL_POOL
            || $model === CommunityReturnService::MODEL_MILESTONE
            || ($model === CommunityReturnLadder::MODEL_CUSTOM && ($in['ladder_locked'] ?? '') === '1');
        if ($target > 0 && !$readsTarget) {
            return ['ok' => false, 'message' => 'The ' . self::MODEL_LABELS[$model] . ' rule does not use a milestone, so the ₦'
                . number_format($target) . ' entered would be ignored. To use it, choose Target pool, Two-stage, or a Custom '
                . 'ladder held locked. To run ' . self::MODEL_LABELS[$model] . ', clear the milestone.'];
        }

        $write = [
            'community_return_model'           => $model,
            'community_return_bps'             => $full,
            'community_return_locked_bps'      => $locked,
            'community_return_milestone_naira' => $target,
        ];
        // The pool's target is milestone ÷ price ÷ nominees, so the price is part of the rule
        // and is pinned with it (CommunityReturnPool::target()).
        if ($model === CommunityReturnService::MODEL_POOL) $write['community_return_vote_price_naira'] = max(0, PaidVoteService::pricePerVote());

        if ($model === CommunityReturnService::MODEL_POOL) {
            // The vote target is worked out from the milestone (CommunityReturnPool::target()).
            if ($target < 1) return ['ok' => false, 'message' => 'The target pool needs a milestone in naira: the vote target is worked out from it.'];
            if ($full < 1) return ['ok' => false, 'message' => 'Set the share of each contribution that goes into the pool.'];
        }
        if ($model === CommunityReturnService::MODEL_MILESTONE) {
            if ($locked > $full) return ['ok' => false, 'message' => 'The locked share cannot be larger than the full share.'];
            if ($target < 1)     return ['ok' => false, 'message' => 'The two-stage model needs a milestone target in naira.'];
        }

        if ($model === CommunityReturnLadder::MODEL_TIERED || $model === CommunityReturnLadder::MODEL_CUSTOM) {
            // Tiered may leave the ladder blank for the house ladder; custom must write one.
            $text = trim((string) ($in['ladder'] ?? ''));
            if ($text === '' && $model === CommunityReturnLadder::MODEL_TIERED) {
                $write['community_return_ladder'] = CommunityReturnLadder::DEFAULT_TIERED;
            } else {
                $p = CommunityReturnLadder::parse($text);
                if (!$p['ok']) return ['ok' => false, 'message' => $p['message']];
                $write['community_return_ladder'] = array_map(
                    static fn (array $st): array => [$st[0], $st[1]], $p['steps']);
            }
        }
        if ($model === CommunityReturnLadder::MODEL_CUSTOM) {
            $write['community_return_ladder_basis'] = (string) ($in['basis'] ?? '') === CommunityReturnLadder::BASIS_EDITION
                ? CommunityReturnLadder::BASIS_EDITION : CommunityReturnLadder::BASIS_NOMINEE;
            $write['community_return_ladder_locked'] = ($in['ladder_locked'] ?? '') === '1';
            if ($write['community_return_ladder_locked'] && $target < 1) {
                return ['ok' => false, 'message' => 'A ladder held locked until the milestone needs a milestone target in naira.'];
            }
        } else {
            $write['community_return_ladder_locked'] = false;
        }

        // When what a nominee is TOLD changes, record the moment, so the letters sent before it
        // can be found and corrected (NomineeVotingNotice::correctionPlan()). Only on a real
        // change: re-saving the same rule must not make every earlier letter look wrong.
        $before = NomineeVotingNotice::returnFingerprint(self::rulesForCycle($cycleId));
        $engine = new RuleEngine();
        $engine->merge('cycle', $cycleId, $write);
        if (NomineeVotingNotice::returnFingerprint(self::rulesForCycle($cycleId)) !== $before) {
            $engine->merge('cycle', $cycleId, [self::CHANGED_AT => \Illuminate\Support\Carbon::now()->toDateTimeString()]);
        }

        return ['ok' => true, 'message' => 'Saved. This edition’s rule: ' . self::summary(self::rulesForCycle($cycleId))];
    }

    /**
     * One sentence describing an edition's rule, for the console, the account page and
     * /integrity — from the same rules the accrual reads.
     *
     * @param array<string,mixed> $rules
     */
    public static function summary(array $rules): string
    {
        $model = CommunityReturnService::modelOf($rules);
        $pct   = static fn (int $bps): string => CommunityReturnLadder::pct($bps);
        if (CommunityReturnLadder::isLadder($model)) return CommunityReturnLadder::describe($rules, $model);
        if ($model === CommunityReturnService::MODEL_POOL) return CommunityReturnPool::summary($rules);
        if ($model === CommunityReturnService::MODEL_MILESTONE) {
            return $pct((int) ($rules['community_return_locked_bps'] ?? 0)) . '% of every eligible contribution, held locked until the edition’s milestone is confirmed; '
                 . $pct((int) ($rules['community_return_bps'] ?? 0)) . '% of every contribution after it.';
        }
        return $pct((int) ($rules['community_return_bps'] ?? 0)) . '% of each contribution once a nominee has '
             . number_format((int) ($rules['community_return_vote_threshold'] ?? 0)) . ' votes of qualifying support.';
    }

    /**
     * EVERY open edition's rule, shaped for the public page.
     *
     * Per edition and read from the rules that do the arithmetic, so /integrity can never
     * publish a rate or a target the ledger is not using. Live programmes only, and not
     * archived editions: the sandbox's programme is inactive, and an archived edition's
     * milestone is history rather than a rule anybody can still act on.
     *
     * It used to skip every edition on the house threshold and print the house rule as the
     * site's rule, in a table of its own. Each award sets its own rule, so a single table
     * describes none of them in particular — and on 4 Nov 2026 it was the only rule on the
     * page while an open award ran a target pool. Every edition is listed now, and `own` says
     * whether the award chose its rule or is running the house rule by default.
     *
     * @return list<array{title:string, model:string, summary:string, own:bool, uses_milestone:bool,
     *                    target_kobo:int, measured_kobo:int, confirmed_at:?string, settled_at:?string}>
     */
    public static function publicEditions(): array
    {
        try {
            $cycles = DB::table('gates_award_cycles as cy')
                ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
                ->where('p.is_active', 1)->where('cy.status', '!=', 'archived')
                ->orderBy('cy.id')->get(['cy.id', 'cy.programme_id', 'cy.year', 'cy.edition_label', 'p.title']);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        $engine = new RuleEngine();
        foreach ($cycles as $c) {
            $p = self::progress((int) $c->id);
            $out[] = [
                'title'          => trim((string) $c->title . ' · ' . ((string) ($c->edition_label ?: $c->year))),
                'model'          => $p['model'],
                'summary'        => $p['summary'],
                // Chosen for this award (its programme or the edition), or inherited by default.
                'own'            => in_array($engine->provenance('community_return_model', (int) $c->programme_id, (int) $c->id)['from'],
                                             ['programme', 'cycle'], true),
                'uses_milestone' => $p['uses_milestone'],
                'target_kobo'    => $p['target_kobo'],
                'measured_kobo'  => $p['measured_kobo'],
                'confirmed_at'   => $p['confirmed_at'],
                // When a pool edition's money moves: at settling, after voting closes.
                'settled_at'     => $p['model'] === CommunityReturnService::MODEL_POOL ? (CommunityReturnPool::settlement((int) $c->id)->settled_at ?? null) : null,
            ];
        }
        return $out;
    }

    /**
     * Tell every nominee with locked credits in the edition that the milestone is confirmed.
     *
     * Best-effort and outside the confirmation itself: a mail outage must not undo an act
     * that has already been recorded, and Notifier swallows its own failures. Mailed to the
     * member holding the page's claim — the only address the platform can be sure is theirs.
     *
     * @return int how many were sent
     */
    public static function notifyConfirmed(int $cycleId, ?OtpService $mailer = null): int
    {
        try {
            $ids = DB::table('gates_community_returns')->where('cycle_id', $cycleId)
                ->where('stage', CommunityReturnService::STAGE_LOCKED)
                ->distinct()->pluck('nominee_id')->map(fn ($v) => (int) $v)->all();
        } catch (\Throwable) {
            return 0;
        }
        if ($ids === []) return 0;

        try { $mailer ??= OtpService::boot(); } catch (\Throwable) { return 0; }

        $sent = 0;
        foreach ($ids as $nomineeId) {
            $to = CommunityReturnWithdrawals::claimantEmail($nomineeId);
            if ($to === '') continue;
            $w = CommunityReturnService::wallet($nomineeId);
            Notifier::confirm($mailer, $to, 'Your community return is now available',
                "The milestone for your edition has been confirmed.\n\n"
                . 'Your locked community return is now available: ₦' . CommunityReturnService::naira($w['available_kobo'])
                . ".\n\n"
                . "You can ask for it from your account, under Community return.");
            $sent++;
        }
        return $sent;
    }
}
