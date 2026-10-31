<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * What is happening RIGHT NOW.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE DIFFERENCE BETWEEN THIS AND SupportKnowledge
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see SupportKnowledge} teaches the assistant how this platform works — the
 * payment flow, the failure modes, the policy. It is true today and it was true
 * last month.
 *
 * This is the other half: what is true in the last hour. Twelve payments stuck
 * since noon. No webhook has arrived in ninety minutes. Four people have written
 * in about the same thing. A cycle closed an hour ago and orders are still
 * landing against it.
 *
 * ── WHY IT MATTERS MORE THAN IT SOUNDS ───────────────────────────────────────
 *
 * Without it the assistant troubleshoots an outage one person at a time, and
 * tells each of them a version of "that is unusual, let me look" — which is
 * false, unhelpful, and the exact experience that turns an incident into a
 * reputation. The buyers in the incident this platform was fixed after were not
 * unlucky individuals. They were the visible edge of one broken webhook, and
 * every one of them was told, in effect, that they were the only one.
 *
 * With it, the same assistant says: yes, this is happening to other people since
 * about half past twelve, here is what has been done about yours, here is when
 * to expect it. That is a different conversation, and it is only possible if
 * something is counting.
 *
 * ── EVERY SIGNAL IS DERIVED, NONE IS DECLARED ────────────────────────────────
 *
 * There is no incident table for somebody to remember to fill in. During an
 * incident nobody updates the status board — that is the first thing that stops
 * happening — so a support bot that reads a declared status is a support bot
 * that is confidently wrong exactly when it matters. Everything here is counted
 * from rows that the platform writes anyway.
 *
 * ── CACHED, BECAUSE THIS RUNS ON EVERY TURN ──────────────────────────────────
 *
 * Roughly a dozen aggregates. At one support conversation a minute that is
 * nothing; during the incident that makes it worth having it is a hundred people
 * at once, which is when the database can least afford it. Sixty seconds of
 * cache is far fresher than the thing being measured.
 */
final class SupportSignals
{
    private const CACHE_KEY = 'support:signals:v1';
    private const CACHE_TTL = 60;

    /** Stuck payments in the last hour that make this an incident rather than a case. */
    private const STUCK_IS_INCIDENT = 4;

    /** Minutes of webhook silence that stop being quiet and start being broken. */
    private const WEBHOOK_SILENCE_MINUTES = 90;

    /**
     * The situation report, for the model.
     *
     * Empty string when nothing is wrong, and that is deliberate: a paragraph
     * saying "everything is normal" on every single turn is tokens spent teaching
     * the model to skim the section. It appears when it has something to say.
     */
    public static function brief(): string
    {
        $s = self::read();
        $lines = [];

        if ($s['payments_stuck_1h'] >= self::STUCK_IS_INCIDENT) {
            $lines[] = '- ' . $s['payments_stuck_1h'] . ' payments have gone through and are STILL uncredited in the '
                . 'last hour. This is a live incident, not one person\'s bad luck. Say so plainly — "yes, this is '
                . 'affecting other people too and we are on it" — then repair theirs with fix_payment anyway, '
                . 'because the repair works per payment even while the underlying fault is open.';
        } elseif ($s['payments_stuck_1h'] > 0) {
            $lines[] = '- ' . $s['payments_stuck_1h'] . ' payment(s) confirmed but uncredited in the last hour. '
                . 'Normal background level; repair the one in front of you.';
        }

        if ($s['webhook_silent_minutes'] !== null && $s['webhook_silent_minutes'] >= self::WEBHOOK_SILENCE_MINUTES) {
            $lines[] = '- No payment webhook has arrived for ' . $s['webhook_silent_minutes'] . ' minutes. That is the '
                . 'exact shape of the fault where wallet-app payers are never credited. Assume anyone reporting a '
                . 'missing payment is hitting it, and go straight to fix_payment.';
        }

        if ($s['unminted_awaiting_refund'] > 0) {
            $lines[] = '- ' . $s['unminted_awaiting_refund'] . ' paid order(s) could not be counted (voting closed first) '
                . 'and are being refunded automatically. If somebody asks about one, check refund_status before saying '
                . 'a refund needs arranging — it is probably already on its way.';
        }

        if ($s['refunds_pending'] > 0) {
            $lines[] = '- ' . $s['refunds_pending'] . ' refund(s) are in flight at the gateway. Banks take 5–10 working '
                . 'days to show them, so "it has not arrived" this week is expected and not a second fault.';
        }

        if ($s['tickets_open'] > 0) {
            $lines[] = '- ' . $s['tickets_open'] . ' support ticket(s) are open'
                . ($s['tickets_urgent'] > 0 ? ', ' . $s['tickets_urgent'] . ' of them urgent' : '') . '.';
        }

        if ($s['theme'] !== null) {
            $lines[] = '- Several people have written in about the same thing in the last day: "' . $s['theme'] . '". '
                . 'If this person is asking about that too, they are not imagining it.';
        }

        foreach ($s['degraded'] as $what) {
            $lines[] = '- ' . $what . ' is DEGRADED right now. Do not tell anybody it is working.';
        }

        if ($s['closing_soon'] !== null) {
            $lines[] = '- ' . $s['closing_soon'] . '. Anyone planning to buy votes there should be told before they pay, '
                . 'because a payment that confirms after the close cannot be counted.';
        }

        return $lines === [] ? '' : "WHAT IS HAPPENING RIGHT NOW (counted in the last hour — trust this over anything "
            . "you assume about the normal state of things)\n" . implode("\n", $lines);
    }

    /**
     * The raw numbers, for the admin console and the tests.
     *
     * @return array{payments_stuck_1h:int, webhook_silent_minutes:?int, unminted_awaiting_refund:int,
     *               refunds_pending:int, tickets_open:int, tickets_urgent:int, theme:?string,
     *               degraded:list<string>, closing_soon:?string}
     */
    public static function read(bool $fresh = false): array
    {
        if (!$fresh) {
            try {
                $hit = (new CacheService())->remember(self::CACHE_KEY, self::CACHE_TTL, static fn() => self::gather());
                if (is_array($hit)) return $hit;
            } catch (\Throwable) { /* cache down is not a reason to be blind */ }
        }
        return self::gather();
    }

    /**
     * Count everything, and let no single failure blank the report.
     *
     * Each probe is wrapped on its own. The alternative — one try/catch around
     * the lot — means a missing column in the newest feature silently erases the
     * incident detection that the oldest one depends on.
     */
    private static function gather(): array
    {
        $out = [
            'payments_stuck_1h' => 0, 'webhook_silent_minutes' => null, 'unminted_awaiting_refund' => 0,
            'refunds_pending' => 0, 'tickets_open' => 0, 'tickets_urgent' => 0, 'theme' => null,
            'degraded' => [], 'closing_soon' => null,
        ];

        // Confirmed, paid for votes, nothing minted, and recent. The exact
        // population the incident produced.
        try {
            $out['payments_stuck_1h'] = (int) DB::table('gates_donations')
                ->where('status', 'confirmed')->where('tier', 'paid-vote')->where('votes_used', 0)
                ->whereNull('refunded_at')
                ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 3600))
                ->count();
        } catch (\Throwable) {}

        // Silence is the signal. A webhook that has stopped arriving looks
        // identical to a quiet hour until you know how quiet the quiet hours are.
        try {
            $last = DB::table('gates_donations')->where('status', 'confirmed')
                ->orderByDesc('id')->value('created_at');
            if ($last) {
                $mins = (int) floor((time() - strtotime((string) $last)) / 60);
                // Only meaningful when the platform is otherwise busy — on a quiet
                // night nothing arriving is not a fault.
                $recent = (int) DB::table('gates_donations')
                    ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 6 * 3600))->count();
                if ($recent >= 3) $out['webhook_silent_minutes'] = $mins;
            }
        } catch (\Throwable) {}

        try {
            if (RefundService::ready()) {
                $out['unminted_awaiting_refund'] = (int) DB::table('gates_donations')
                    ->where('status', 'confirmed')->where('tier', 'paid-vote')->where('votes_used', 0)
                    ->whereNull('refunded_at')->whereNull('refund_requested_at')->count();
                $out['refunds_pending'] = (int) DB::table('gates_donations')
                    ->whereIn('refund_state', ['requested', 'pending'])->count();
            }
        } catch (\Throwable) {}

        try {
            $out['tickets_open']   = (int) DB::table('gates_support_tickets')->where('status', 'open')->count();
            $out['tickets_urgent'] = (int) DB::table('gates_support_tickets')
                ->where('status', 'open')->where('severity', 'urgent')->count();
            $out['theme'] = self::theme();
        } catch (\Throwable) {}

        try {
            $h = (new SupportContext())->run('platform_health');
            foreach ((array) ($h['data'] ?? []) as $name => $state) {
                if (is_array($state) && ($state['ok'] ?? true) === false) {
                    $out['degraded'][] = ucfirst((string) $name);
                }
            }
        } catch (\Throwable) {}

        try {
            $soon = DB::table('gates_award_cycles as y')
                ->join('gates_award_programmes as p', 'p.id', '=', 'y.programme_id')
                ->where('y.status', 'voting')->whereNotNull('y.voting_close')
                ->where('y.voting_close', '>', date('Y-m-d H:i:s'))
                ->where('y.voting_close', '<', date('Y-m-d H:i:s', time() + 48 * 3600))
                ->orderBy('y.voting_close')->first(['p.title', 'y.voting_close']);
            if ($soon) {
                $out['closing_soon'] = 'Voting for ' . $soon->title . ' closes at ' . $soon->voting_close;
            }
        } catch (\Throwable) {}

        return $out;
    }

    /**
     * Are several people complaining about the same thing?
     *
     * A crude but honest clustering: the most common significant word across
     * recent ticket subjects, reported only when it appears in at least three of
     * them. Not because word frequency is a good classifier — it is not — but
     * because the question being asked is narrow ("is this person alone?") and
     * three tickets sharing a word answers it well enough to change what the
     * assistant says. Anything cleverer here would be a model call per turn to
     * improve a hint.
     */
    private static function theme(): ?string
    {
        static $stop = ['the','and','for','not','with','have','has','this','that','from','are','was','been',
                        'you','your','their','they','can','cant','won','will','about','into','out','get',
                        'got','why','how','what','when','where','vote','votes','africa','gates','support',
                        'please','help','issue','problem','account','still'];

        try {
            $subjects = DB::table('gates_support_tickets')
                ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 86400))
                ->orderByDesc('id')->limit(40)->pluck('subject');
        } catch (\Throwable) {
            return null;
        }
        if (count($subjects) < 3) return null;

        $seen = [];
        foreach ($subjects as $s) {
            // Per SUBJECT, not per occurrence: one person writing "payment" four
            // times in one sentence is not four people with a payment problem.
            $words = array_unique(preg_split('/[^a-z]+/', mb_strtolower((string) $s)) ?: []);
            foreach ($words as $w) {
                if (mb_strlen($w) < 4 || in_array($w, $stop, true)) continue;
                $seen[$w] = ($seen[$w] ?? 0) + 1;
            }
        }
        if (!$seen) return null;

        arsort($seen);
        $top = array_key_first($seen);
        return $seen[$top] >= 3 ? $top . ' (' . $seen[$top] . ' tickets)' : null;
    }
}
