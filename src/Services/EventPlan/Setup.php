<?php
declare(strict_types=1);
namespace AfricaGates\Services\EventPlan;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * SETTING A GOAL BY SAYING IT (design plan, Setup: "Describe the goal in plain words … at most
 * two or three questions, each with the reason it matters"; "Rules decide first and AI second").
 *
 * A lead writes the goal as a sentence — "three awards earning ₦15M from paid votes by 6
 * December at ₦200 a vote" — and {@see read()} takes out what it can with plain rules: an amount
 * or a count and what it counts, a date, an award or category named in it, a vote price. Only
 * the pieces it could not find become questions, each a card of two to four answers plus the
 * lead's own, and each says why it is asked. Nothing is created until the lead confirms, and
 * the goal itself is still refused without a metric, a target and a deadline (plan AT-1).
 *
 * No model is called: the rules are predictable, cost nothing and cannot invent a figure. A
 * sentence they cannot read gives questions, never a guess.
 */
final class Setup
{
    /** words in the sentence → the metric they mean */
    private const WORDS = [
        'revenue' => '/(revenue|raise|earn|income|paid votes?|naira|₦|ngn|\bmillion\b)/iu',
        'nominees' => '/\bnominees?\b|confirmed/i',
        'nominations' => '/\bnominations?\b/i',
        'tickets' => '/\btickets?\b|\bguests?\b|attendees?/i',
        'votes' => '/\bvotes?\b/i',
        'volunteers' => '/\bvolunteers?\b/i',
        'contacted' => '/\bcontact(ed|s)?\b|\bprospects?\b|\breach out\b/i',
    ];

    /**
     * What the sentence says. @return array{metric:?string, target:?int, deadline:?string, scope:string, price:?int,
     *   found:list<string>, ask:list<string>, title:string}
     */
    public static function read(int $eventId, string $text): array
    {
        $t = ' ' . trim($text) . ' ';
        $out = ['metric' => null, 'target' => null, 'deadline' => null, 'scope' => 'event', 'price' => null, 'found' => [], 'ask' => [], 'title' => mb_substr(trim($text), 0, 190)];

        // A vote price first ("₦200 a vote", "200 naira per vote"), so it is not read as the target.
        if (preg_match('/(?:₦|ngn|n)\s*([\d,]+)\s*(?:a|per|each)\s*vote|([\d,]+)\s*naira\s*(?:a|per|each)\s*vote/iu', $t, $m)) {
            $out['price'] = (int) str_replace(',', '', $m[1] !== '' ? $m[1] : $m[2]);
            $t = str_replace($m[0], ' ', $t);
            $out['found'][] = 'a vote costs ₦' . number_format($out['price']);
        }
        // Money: ₦15M, 15 million naira, NGN 15,000,000.
        if (preg_match('/(?:₦|ngn)\s*([\d.,]+)\s*(k|m|bn|million|thousand)?|([\d.,]+)\s*(k|m|bn|million|thousand)?\s*(?:naira|ngn)/iu', $t, $m)) {
            $n = Goals::number(($m[1] !== '' ? $m[1] : $m[3]) . (($m[2] ?? '') !== '' ? $m[2] : ($m[4] ?? '')));
            if ($n) { $out['metric'] = 'revenue'; $out['target'] = $n; $out['found'][] = Goals::fmt('revenue', $n) . ' of paid-vote revenue'; }
        }
        // A count with what it counts: "60 nominees", "500 tickets".
        if ($out['target'] === null && preg_match('/([\d.,]+\s*(?:k|thousand)?)\s+(nominees?|nominations?|tickets?|guests?|votes?|volunteers?|prospects?|contacts?)/iu', $t, $m)) {
            $n = Goals::number($m[1]);
            foreach (self::WORDS as $metric => $re) {
                if ($metric !== 'revenue' && preg_match($re, $m[2])) { $out['metric'] = $metric; break; }
            }
            if ($n && $out['metric']) { $out['target'] = $n; $out['found'][] = number_format($n) . ' ' . mb_strtolower(Goals::METRICS[$out['metric']][0]); }
        }
        if ($out['metric'] === null) foreach (self::WORDS as $metric => $re) if (preg_match($re, $t)) { $out['metric'] = $metric; break; }
        // A date: "by 6 December", "on 15 Nov", "before 2026-12-06".
        if (preg_match('/(\d{4}-\d{2}-\d{2})|(\d{1,2})(?:st|nd|rd|th)?\s+(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?(?:\s+(\d{4}))?|(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s+(\d{1,2})(?:st|nd|rd|th)?(?:,?\s+(\d{4}))?/iu', $t, $m)) {
            $s = $m[1] !== '' ? $m[1] : (($m[2] ?? '') !== '' ? $m[2] . ' ' . $m[3] . ' ' . ($m[4] ?? '') : ($m[6] ?? '') . ' ' . ($m[5] ?? '') . ' ' . ($m[7] ?? ''));
            $at = strtotime(trim($s));
            if ($at !== false) {
                if ($at < strtotime('today') && !preg_match('/\d{4}/', $s)) $at = strtotime(trim($s) . ' ' . ((int) date('Y') + 1));
                $out['deadline'] = date('Y-m-d', $at);
                $out['found'][] = 'by ' . Goals::day($out['deadline']);
            }
        }
        // An award or a category named in it.
        foreach (Goals::scopes($eventId) as $key => $words) {
            if ($key === 'event') continue;
            $last = trim((string) preg_replace('/^.*·\s*/u', '', $words));
            if ($last !== '' && mb_stripos($t, $last) !== false) { $out['scope'] = $key; $out['found'][] = 'within ' . $words; }
        }
        if ($out['metric'] === null) $out['ask'][] = 'metric';
        if ($out['target'] === null) $out['ask'][] = 'target';
        if ($out['deadline'] === null) $out['ask'][] = 'deadline';
        if ($out['scope'] === 'event' && count(Goals::scopes($eventId)) > 2 && in_array($out['metric'], ['revenue', 'nominees', 'nominations', 'votes'], true)) $out['ask'][] = 'scope';
        return $out;
    }

    /** The dates a deadline is most likely to be: the night, and each award's nominations and voting close. @return array<string,string> */
    public static function dates(int $eventId): array
    {
        $out = [];
        $e = Plan::event($eventId);
        if ($e) $out[date('Y-m-d', (int) strtotime((string) $e->event_date))] = 'The night (' . Goals::day((string) $e->event_date) . ')';
        foreach (Goals::cycles($eventId) as $c) {
            $row = DB::table('gates_award_cycles')->where('id', $c->id)->first();
            foreach (['nominations_close' => 'nominations close', 'voting_close' => 'voting closes'] as $col => $w) {
                if ($row && !empty($row->{$col}) && strtotime((string) $row->{$col}) > time()) $out[date('Y-m-d', (int) strtotime((string) $row->{$col}))] = ucfirst($w) . ' (' . Goals::day((string) $row->{$col}) . ')';
            }
        }
        return array_slice($out, 0, 3, true);
    }
}
