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

        $total = $collusion['open'] + $judges['flags'] + $fraud;

        return [
            'collusion'           => $collusion,
            'judges'              => $judges,
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
        $ai ??= AiService::boot();
        if ($ai->configured()) {
            $facts = self::factLines($signals);
            $system = 'You are an award-integrity analyst for Africa GATES, a continental cultural-awards platform. '
                . 'Given DETECTED signals (already computed by the system), write a SHORT briefing (2–4 sentences, plain English) '
                . 'for a human reviewer: what to look at first and why, in priority order. It is ADVISORY — never accuse anyone, '
                . 'never state a verdict, suggest what to CHECK. If all counts are zero, say things look clean. No preamble, no markdown.';
            $out = $ai->complete($system, "Signals:\n" . $facts, 220, false, 0.3);
            if (is_string($out) && trim($out) !== '') {
                return ['text' => trim($out), 'ai' => true];
            }
        }
        return ['text' => self::templateBrief($signals), 'ai' => false];
    }

    /** signals + narrative in one call. */
    public static function brief(?AiService $ai = null): array
    {
        $s = self::signals();
        return ['signals' => $s] + self::narrative($s, $ai);
    }

    private static function factLines(array $s): string
    {
        $c = $s['collusion'];
        $kinds = [];
        foreach ($c['by_kind'] as $k => $n) { $kinds[] = "$n $k"; }
        return implode("\n", [
            '- Open voter-collusion findings: ' . $c['open'] . ($kinds ? ' (' . implode(', ', $kinds) . ')' : '') . '; highest risk score ' . $c['top_risk'] . '/100.',
            '- Judge-score anomaly flags: ' . $s['judges']['flags'] . ' across ' . $s['judges']['judges'] . ' judge(s).',
            '- Votes auto-flagged for fraud in the last 24h: ' . $s['fraud_votes_24h'] . '.',
            '- Nominations awaiting review: ' . $s['pending_nominations'] . '.',
        ]);
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
        return implode(' ', $parts);
    }
}
