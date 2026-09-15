<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * AI integrity briefing — the platform's award-integrity signals, synthesised.
 *
 * Voter collusion ({@see CollusionService}), judge-score anomalies ({@see
 * JudgeAnomalyService}) and per-vote fraud flags each live on their own screen.
 * This gathers them into ONE structured snapshot and turns it into a short,
 * prioritised plain-English briefing for an admin — "what to look at first and
 * why". Model-agnostic via {@see AiService}; when no AI is configured (or it
 * fails) it returns a deterministic templated brief, so the page is never empty.
 *
 * Strictly ADVISORY: it summarises detections, never accuses and never acts.
 */
final class IntegrityBriefService
{
    /**
     * Deterministic integrity signals. Cheap DB reads — safe to compute on page load.
     *
     * @return array{collusion:array{open:int,by_kind:array<string,int>,top_risk:int}, judges:array{flags:int,judges:int,top:array}, fraud_votes_24h:int, pending_nominations:int, total:int}
     */
    public static function signals(): array
    {
        $collusion = ['open' => 0, 'by_kind' => [], 'top_risk' => 0];
        try {
            $rows = DB::table('gates_collusion_findings')->where('status', 'open')->get(['kind', 'risk_score']);
            $collusion['open'] = $rows->count();
            $collusion['top_risk'] = (int) ($rows->max('risk_score') ?? 0);
            foreach ($rows as $r) {
                $collusion['by_kind'][(string) $r->kind] = ($collusion['by_kind'][(string) $r->kind] ?? 0) + 1;
            }
        } catch (\Throwable) {}

        $judges = ['flags' => 0, 'judges' => 0, 'top' => []];
        try {
            $scan = (new JudgeAnomalyService())->scanActive();
            $judges['flags'] = count($scan['flags'] ?? []);
            $judges['judges'] = count($scan['judges'] ?? []);
            $judges['top'] = array_slice($scan['judges'] ?? [], 0, 3);
        } catch (\Throwable) {}

        $fraud = 0;
        try {
            $since = date('Y-m-d H:i:s', time() - 86400);
            $fraud = (int) DB::table('gates_votes')->where('fraud_flag', 1)->where('voted_at', '>=', $since)->count();
        } catch (\Throwable) {}

        $pending = 0;
        try { $pending = (int) DB::table('gates_nominations')->where('status', 'pending')->count(); } catch (\Throwable) {}

        // ── SYSTEMATIC LEAN, WHICH IS A DIFFERENT QUESTION ───────────────────
        //
        // JudgeAnomalyService above answers "did a judge disagree about a nominee". This
        // answers "does a judge score differently by country, category or criterion across
        // everything they marked". A disagreement is what a panel is for; a pattern across
        // fifteen scores is a finding, and only the second one is bias.
        //
        // Counted separately in `total` rather than folded into the anomaly count, because
        // the two lead to completely different conversations.
        $bias = ['findings' => 0, 'comparisons' => 0, 'top' => [], 'caveat' => ''];
        try {
            $cycleId = self::activeCycleId();
            if ($cycleId > 0) {
                $b = JudgeBiasService::briefInput($cycleId);
                $bias = ['findings'    => (int) $b['total'],
                         'comparisons' => (int) $b['comparisons'],
                         'top'         => array_slice($b['findings'], 0, 3),
                         'caveat'      => (string) $b['caveat']];
            }
        } catch (\Throwable) {}

        $total = $collusion['open'] + $judges['flags'] + $fraud + $bias['findings'];

        return [
            'collusion'           => $collusion,
            'judges'              => $judges,
            'bias'                => $bias,
            'fraud_votes_24h'     => $fraud,
            'pending_nominations' => $pending,
            'total'               => $total,
        ];
    }

    /**
     * A short prioritised briefing from the signals. AI when configured; a
     * deterministic template otherwise (or on AI failure).
     *
     * @return array{text:string, ai:bool}
     */
    public static function narrative(array $signals, ?AiService $ai = null): array
    {
        // The signals are counts this platform computed, so there is no untrusted
        // text here and nothing to fence. Routed for the budget and the record.
        $r = (new AiGateway($ai))->run('integrity.brief', [
            'system' => 'You are an award-integrity analyst for Africa GATES, a continental cultural-awards platform. '
                . 'Given DETECTED signals (already computed by the system), write a SHORT briefing (2–4 sentences, plain English) '
                . 'for a human reviewer: what to look at first and why, in priority order. It is ADVISORY — never accuse anyone, '
                . 'never state a verdict, suggest what to CHECK. If all counts are zero, say things look clean. No preamble, no markdown.',
            'user'        => "Signals:\n" . self::factLines($signals),
            'temperature' => 0.3,
            'schema'      => static function (string $raw): ?string {
                $t = trim($raw);
                return $t === '' ? null : $t;
            },
        ]);
        if ($r->ok) {
            return ['text' => $r->value, 'ai' => true];
        }
        // The deterministic template is always available, so an AI outage costs
        // nothing here — the brief just stops being a narrative.
        return ['text' => self::templateBrief($signals), 'ai' => false];
    }

    /** signals + narrative in one call. */
    public static function brief(?AiService $ai = null): array
    {
        $s = self::signals();
        return ['signals' => $s] + self::narrative($s, $ai);
    }

    /**
     * Render the signals as prompt lines.
     *
     * Tolerates a partial array: narrative() is public and now builds this input
     * before the gateway decides whether to call anything, so a caller passing a
     * subset must not emit warnings.
     */
    private static function factLines(array $s): string
    {
        $c     = is_array($s['collusion'] ?? null) ? $s['collusion'] : [];
        $j     = is_array($s['judges'] ?? null) ? $s['judges'] : [];
        $b     = is_array($s['bias'] ?? null) ? $s['bias'] : [];
        $kinds = [];
        foreach ((is_array($c['by_kind'] ?? null) ? $c['by_kind'] : []) as $k => $n) { $kinds[] = "$n $k"; }
        return implode("\n", [
            '- Open voter-collusion findings: ' . (int) ($c['open'] ?? 0)
                . ($kinds ? ' (' . implode(', ', $kinds) . ')' : '')
                . '; highest risk score ' . (int) ($c['top_risk'] ?? 0) . '/100.',
            '- Judge-score anomaly flags: ' . (int) ($j['flags'] ?? 0) . ' across ' . (int) ($j['judges'] ?? 0) . ' judge(s).',
            '- Votes auto-flagged for fraud in the last 24h: ' . (int) ($s['fraud_votes_24h'] ?? 0) . '.',
            '- Nominations awaiting review: ' . (int) ($s['pending_nominations'] ?? 0) . '.',
            // The comparison count goes to the model WITH the finding count, deliberately.
            // "Three judges show a lean" reads as alarming; "three out of two hundred and
            // forty comparisons" reads as normal, and the second is the true statement. A
            // model given only the first will write the alarming version.
            '- Judge bias checks: ' . (int) ($b['findings'] ?? 0) . ' lean(s) found across '
                . (int) ($b['comparisons'] ?? 0) . ' comparison(s).'
                . ($b['caveat'] ?? '' ? ' Context: ' . (string) $b['caveat'] : ''),
        ] + ($b['top'] ?? [] ? ['- ' . implode("\n- ", array_map(
                static fn (string $t): string => 'Lean: ' . $t, (array) $b['top']))] : []));
    }

    private static function templateBrief(array $s): string
    {
        if ($s['total'] === 0) {
            return 'No open integrity signals right now — voter collusion, judge-score anomalies and fraud flags are all clear. '
                . ($s['pending_nominations'] > 0 ? $s['pending_nominations'] . ' nomination(s) await review.' : '');
        }
        $parts = [];
        if ($s['collusion']['open'] > 0) {
            $parts[] = 'Review the ' . $s['collusion']['open'] . ' open collusion finding(s) first'
                . ($s['collusion']['top_risk'] >= 70 ? ' — one scores ' . $s['collusion']['top_risk'] . '/100 risk' : '') . '.';
        }
        if ($s['judges']['flags'] > 0) {
            $parts[] = $s['judges']['flags'] . ' judge-score anomaly flag(s) across ' . $s['judges']['judges'] . ' judge(s) — check whether a judge is consistently out of step with the panel.';
        }
        if ($s['fraud_votes_24h'] > 0) {
            $parts[] = $s['fraud_votes_24h'] . ' vote(s) were auto-flagged for fraud in the last 24h.';
        }
        // The denominator travels with the numerator here too. A fallback brief that says
        // "2 judges show a lean" without saying out of how many comparisons is the same
        // misleading sentence the AI one is prevented from writing.
        $b = is_array($s['bias'] ?? null) ? $s['bias'] : [];
        if ((int) ($b['findings'] ?? 0) > 0) {
            $parts[] = (int) $b['findings'] . ' judge scoring lean(s) stood out of '
                . (int) ($b['comparisons'] ?? 0) . ' comparison(s) — a question to ask, '
                . 'not a conclusion about anybody.';
        }
        return implode(' ', $parts);
    }

    /**
     * The cycle the bias scan should look at.
     *
     * The active one, and only one: bias is measured from deviations WITHIN a panel, and
     * pooling two cycles compares a judge against people who were never on their panel.
     */
    private static function activeCycleId(): int
    {
        try {
            // `judging` first, because that is when the scores this measures are being
            // written and when a lean can still be acted on. `results` is included so the
            // check does not vanish the moment somebody most wants to look at it — the
            // hour a result is announced is exactly when a judge's pattern gets queried.
            // Cycles have no is_active column; status is the state machine here.
            return (int) (DB::table('gates_award_cycles')
                ->whereIn('status', ['judging', 'results', 'shortlisting'])
                ->orderByRaw("CASE status WHEN 'judging' THEN 0 WHEN 'results' THEN 1 ELSE 2 END")
                ->orderByDesc('id')
                ->value('id') ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }
}
