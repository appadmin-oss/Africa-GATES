<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Accent;
use AfricaGates\Support\AwardCampaign;
use AfricaGates\Support\BroadcastLog;
use AfricaGates\Support\EditionName;
use AfricaGates\Support\NomineeUrl;
use AfricaGates\Support\SchemaHas;
use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * "YOU WERE PART OF IT" — the message every backer gets when an award's results are announced
 * (owner, 16 Nov 2026: "make the voter feel good and proud of themselves … to really honour
 * them and make it feel worthwhile instead of … the sentimental need to vote someone because
 * they asked me").
 *
 * The platform used to go quiet on exactly the day a vote pays off: the nominees were told,
 * the page changed, and the people who carried them heard nothing. So on results day each
 * backer is told what became of the nominee they backed most, and that they were one of the
 * people behind them — the board's own count, never an estimate:
 *
 *   won            "Chiamaka Eze won Best Teacher. You were one of 412 people who backed her
 *                   to it" — without the pronoun, which this platform never guesses
 *   runner-up      second, said as second
 *   shortlisted    "made the shortlist" — true, and worth saying
 *   otherwise      "did not win this time", and that the support is on the record. Never a
 *                   consolation dressed as a result.
 *
 * ── WHEN ──────────────────────────────────────────────────────────────────────────────
 * Only for a release that was ANNOUNCED (gates_cycle_transitions.notify = 1 into `results`):
 * a cycle that reached results late, with its notifications withheld as stale, must not mail
 * congratulations now (CycleMaterialiser). And only within WINDOW_DAYS of it.
 *
 * ── ONCE ──────────────────────────────────────────────────────────────────────────────
 * Each address is claimed in gates_broadcast_log before it is composed (BroadcastLog::claim),
 * so two cron ticks, or a tick that stopped half-way, never send it twice. BATCH per tick;
 * the claims carry the rest to the next one.
 *
 * ── WHO ───────────────────────────────────────────────────────────────────────────────
 * SupporterSpotlight::backers(): a checkout's address or a verified account's. A free voter
 * with no account is a hash only and is not written to. The message carries an unsubscribe,
 * so the transport's own rules (Mail\SendPolicy) keep it from anybody who opted out.
 */
final class ResultsDayMail
{
    public const BATCH = 150;
    public const WINDOW_DAYS = 14;

    public static function sweep(?OtpService $mailer, ?Carbon $now = null, ?string $site = null, ?callable $send = null): int
    {
        if ($mailer === null && $send === null) return 0;
        if (!SchemaHas::table('gates_cycle_transitions')) return 0;
        $now ??= Carbon::now();
        $site = rtrim($site ?? SiteUrl::base(), '/');
        $sent = 0;
        try {
            $cycles = DB::table('gates_cycle_transitions as t')
                ->join('gates_award_cycles as c', 'c.id', '=', 't.cycle_id')
                ->join('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
                ->where('t.to_status', 'results')->where('t.notify', 1)->where('p.is_active', 1)
                ->where('t.created_at', '>=', $now->copy()->subDays(self::WINDOW_DAYS)->toDateTimeString())
                ->get(['c.*', 'p.id as programme_id', 'p.title as programme', 'p.slug as programme_slug'])->all();
        } catch (\Throwable) {
            return 0;
        }
        foreach ($cycles as $cycle) {
            $sent += self::cycle($cycle, $site, $mailer, $send, self::BATCH - $sent);
            if ($sent >= self::BATCH) break;
        }
        return $sent;
    }

    /** One cycle's backers, up to $room messages. */
    private static function cycle(object $cycle, string $site, ?OtpService $mailer, ?callable $send, int $room): int
    {
        $cid = (int) $cycle->id;
        $backers = SupporterSpotlight::backers($cid);
        if ($backers === []) return 0;
        $people = [];
        foreach ($backers as $b) foreach ($b['by'] as $nid => $_) $people[$nid] = ($people[$nid] ?? 0) + 1;
        $noms = self::nominees(array_keys($people));
        $short = ShortlistService::shortlistedIn($cid);
        $edition = EditionName::label($cycle);
        $campaign = AwardCampaign::forProgramme((int) $cycle->programme_id);
        $key = 'results-day:' . $cid;
        $sent = 0;
        foreach ($backers as $b) {
            if ($sent >= $room) break;
            $email = trim((string) $b['email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            // The nominee they backed most; a tie goes to the one who did best.
            $by = $b['by'];
            uksort($by, static fn ($x, $y) => ($by[$y] <=> $by[$x]) ?: (self::rank($noms[$x] ?? null, $short) <=> self::rank($noms[$y] ?? null, $short)));
            $pick = $noms[(int) array_key_first($by)] ?? null;
            if ($pick === null) continue;
            if (!BroadcastLog::claim($key, $email, (int) $pick->id)) continue;
            [$subject, $html, $plain] = self::compose($pick, (int) ($people[(int) $pick->id] ?? 1), $short, (string) $cycle->programme, $edition,
                $campaign['spotlight'] ? $site . '/vote/' . rawurlencode((string) $cycle->programme_slug) . '/spotlight' : '', $site, $email);
            $ok = false;
            try {
                $ok = $send ? (bool) $send($email, $subject, $html, $plain)
                            : (($mailer->sendBranded($email, $subject, $html, $plain, 'Awards')['success'] ?? false) === true);
            } catch (\Throwable $e) {
                error_log('[results-day] send failed: ' . $e->getMessage());
            }
            BroadcastLog::settle($key, $email, $ok);
            if ($ok) $sent++;
        }
        return $sent;
    }

    /** @param list<int> $ids @return array<int,object> */
    private static function nominees(array $ids): array
    {
        if ($ids === []) return [];
        $out = [];
        try {
            foreach (DB::table('gates_nominees as n')->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->whereIn('n.id', $ids)->get(['n.id', 'n.name', 'n.status', 'n.category_id', 'c.title as category']) as $n) $out[(int) $n->id] = $n;
        } catch (\Throwable) {}
        return $out;
    }

    /** Lower is better: winner, runner-up, shortlisted, the rest. */
    private static function rank(?object $n, array $short): int
    {
        if ($n === null) return 9;
        return match ((string) $n->status) { 'winner' => 0, 'runner_up' => 1, default => isset($short[(int) $n->id]) ? 2 : 3 };
    }

    /** @return array{0:string,1:string,2:string} subject, html, plain */
    public static function compose(object $n, int $people, array $short, string $award, string $edition, string $spotlight, string $site, string $email): array
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $name = (string) $n->name;
        $cat = (string) $n->category;
        $where = $award . ($edition !== '' ? ' ' . $edition : '');
        $one = $people === 1 ? 'You were the one person who backed ' . $name . '.'
                             : 'You were one of ' . number_format($people) . ' people who backed ' . $name . '.';
        [$subject, $lead] = match (true) {
            $n->status === 'winner'    => [$name . ' won. You were part of it.',
                                           $name . ' won ' . $cat . ' at ' . $where . '. ' . $one],
            $n->status === 'runner_up' => [$name . ' was runner-up in ' . $cat,
                                           $name . ' finished second in ' . $cat . ' at ' . $where . '. ' . $one],
            isset($short[(int) $n->id]) => [$name . ' made the shortlist in ' . $cat,
                                           $name . ' made the shortlist for ' . $cat . ' at ' . $where . '. ' . $one],
            default                    => ['The ' . $award . ' results are out',
                                           $name . ' did not win ' . $cat . ' this time. ' . $one . ' That support is on the record, and it was seen.'],
        };
        $thanks = 'Thank you for celebrating your own. Awards like this exist because people like you turn up for the people who make a difference where they live.';
        $results = $site . '/results/' . PublicResults::slug((int) $n->category_id, $cat);
        $card = $site . NomineeUrl::path((int) $n->id) . '/backed.png';
        $off = EmailOptOut::url($site, $email);
        [$ink, $ink2, $go, $on, $soft] = [Accent::hex('ink'), Accent::hex('ink-2'), Accent::hex('green'), Accent::hex('surface'), Accent::hex('soft')];
        $html = '<h1 style="margin:0;font-family:Helvetica,Arial,sans-serif;font-weight:700;font-size:24px;line-height:30px;color:' . $ink . '">' . $e($subject) . '</h1>'
              . '<p style="margin:13px 0 0;font-size:16px;line-height:1.6;color:' . $ink2 . '">' . $e($lead) . '</p>'
              . '<p style="margin:13px 0 0;font-size:16px;line-height:1.6;color:' . $ink2 . '">' . $e($thanks) . '</p>'
              . '<p style="text-align:center;margin:24px 0 10px"><a href="' . $e($results) . '" style="display:inline-block;padding:13px 28px;background:' . $go . ';color:' . $on . ';border-radius:999px;font-weight:700;text-decoration:none;font-size:16px">See the results</a></p>'
              . '<p style="text-align:center;margin:0 0 24px;font-size:15px"><a href="' . $e($card) . '" style="color:' . $ink . '">Get your “I backed ' . $e($name) . '” card</a>'
              . ($spotlight !== '' ? ' · <a href="' . $e($spotlight) . '" style="color:' . $ink . '">See the Supporter Spotlight</a>' : '') . '</p>'
              . '<p style="margin:0;font-size:13px;line-height:1.6;color:' . $soft . '">You are receiving this because you voted in ' . $e($award) . '. <a href="' . $e($off) . '" style="color:' . $ink2 . '">Unsubscribe</a>.</p>';
        $plain = $subject . "\n\n" . $lead . "\n\n" . $thanks . "\n\nSee the results: " . $results
               . "\nYour “I backed " . $name . "” card: " . $card . ($spotlight !== '' ? "\nThe Supporter Spotlight: " . $spotlight : '')
               . "\n\nYou are receiving this because you voted in " . $award . ". Unsubscribe: " . $off;
        return [$subject, $html, $plain];
    }
}
