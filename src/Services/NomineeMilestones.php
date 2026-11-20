<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Accent;
use AfricaGates\Support\BroadcastLog;
use AfricaGates\Support\NomineeUrl;
use AfricaGates\Support\SchemaHas;
use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * "YOUR VOTE IS WORKING" — milestones in a nominee's race, told to the backers who asked
 * (owner, 16 Nov 2026: "more features for voters and nominees … make them feel good").
 *
 * Between voting and results a voter used to hear nothing, which is exactly when their vote
 * was doing something. So a backer may ask to be told when the nominee they backed reaches a
 * milestone — and is told, at most three times an edition:
 *
 *   people   10, 25, 50, 100, 250, 500, 1,000, 2,500, 5,000 supporters (VoterReach: PEOPLE,
 *            the board's count, never the tally)
 *   top3     into the top three of their category, by public votes, in a field of four or more
 *   lead     the outright lead of their category
 *   shortlist  on the published shortlist
 *
 * Each milestone is recorded ONCE (UNIQUE nominee/kind/value), so a nominee who drops out of the
 * top three and climbs back is not announced twice. The same records give the nominee their
 * share images ({@see MilestoneCard}).
 *
 * ── NOTHING ANNOUNCED BY SWITCHING IT ON ───────────────────────────────────────────────
 * The first time a cycle is looked at, everything already true is recorded `quiet` and never
 * sent. And a follower hears only of milestones reached AFTER they asked.
 *
 * ── ONLY AN ADDRESS THAT VOTED ─────────────────────────────────────────────────────────
 * {@see self::follow()} accepts an address only if it backed this nominee: a free vote hashed
 * from it, a settled order paid from it, or a verified member whose points voted. Otherwise a
 * stranger could sign anybody's inbox up for somebody else's campaign.
 *
 * ── ONE AT A TIME ──────────────────────────────────────────────────────────────────────
 * When several milestones land in one sweep (a burst of votes), a follower is told the best of
 * them — the lead before the top three before the shortlist before a supporter count — and the
 * rest are marked told. Each address is claimed before sending (BroadcastLog).
 */
final class NomineeMilestones
{
    public const PEOPLE = [10, 25, 50, 100, 250, 500, 1000, 2500, 5000];
    public const MAX_PER_FOLLOWER = 3;
    /** A milestone older than this is not news any more; it is recorded and never sent. */
    public const FRESH_HOURS = 48;

    public static function ready(): bool
    {
        return SchemaHas::table('gates_nominee_milestones') && SchemaHas::table('gates_nominee_follows');
    }

    /**
     * Record what a cycle's nominees have reached. Returns the milestone rows newly recorded.
     *
     * @return list<object>
     */
    public static function detect(int $cycleId, ?Carbon $now = null): array
    {
        if ($cycleId < 1 || !self::ready()) return [];
        $now ??= Carbon::now();
        try {
            $noms = DB::table('gates_nominees as n')->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->where('c.cycle_id', $cycleId)->whereIn('n.status', ['approved', 'winner', 'runner_up'])->whereNull('n.merged_into')
                ->get(['n.id', 'n.category_id', 'n.vote_count'])->all();
            $quiet = !DB::table('gates_nominee_milestones')->where('cycle_id', $cycleId)->exists();
        } catch (\Throwable) {
            return [];
        }
        // The first look at a cycle is recorded as a marker row (nominee 0), so a cycle where
        // nothing had happened yet is not "first look" again at its first real milestone —
        // which would otherwise be recorded quiet and never told.
        if ($quiet) {
            try {
                DB::table('gates_nominee_milestones')->insertOrIgnore(['nominee_id' => 0, 'cycle_id' => $cycleId, 'kind' => 'seen', 'value' => $cycleId,
                    'reached_at' => $now->toDateTimeString(), 'quiet' => 1, 'notified_at' => $now->toDateTimeString()]);
            } catch (\Throwable) {}
        }
        if ($noms === []) return [];
        $people = VoterReach::forNominees(array_map(static fn ($n) => (int) $n->id, $noms));
        $short = ShortlistService::shortlistedIn($cycleId);
        $byCat = [];
        foreach ($noms as $n) $byCat[(int) $n->category_id][] = $n;

        $want = [];
        foreach ($byCat as $field) {
            foreach ($field as $n) {
                $id = (int) $n->id; $v = (int) $n->vote_count;
                foreach (self::PEOPLE as $t) if (($people[$id] ?? 0) >= $t) $want[] = [$id, 'people', $t];
                if ($v > 0) {
                    $above = count(array_filter($field, static fn ($o) => (int) $o->vote_count > $v));
                    $tied = count(array_filter($field, static fn ($o) => (int) $o->vote_count === $v)) > 1;
                    if (count($field) >= 4 && $above < 3) $want[] = [$id, 'top3', 3];
                    if (count($field) >= 2 && $above === 0 && !$tied) $want[] = [$id, 'lead', 1];
                }
                if (isset($short[$id])) $want[] = [$id, 'shortlist', 1];
            }
        }
        $new = [];
        $at = $now->toDateTimeString();
        foreach ($want as [$id, $kind, $value]) {
            try {
                $ok = DB::table('gates_nominee_milestones')->insertOrIgnore(['nominee_id' => $id, 'cycle_id' => $cycleId, 'kind' => $kind,
                    'value' => $value, 'reached_at' => $at, 'quiet' => $quiet ? 1 : 0, 'notified_at' => $quiet ? $at : null]);
            } catch (\Throwable) {
                $ok = 0;
            }
            if ($ok === 1) $new[] = (object) ['nominee_id' => $id, 'kind' => $kind, 'value' => $value, 'quiet' => $quiet];
        }
        return $new;
    }

    /** Every milestone a nominee has reached, newest first. @return list<object> */
    public static function forNominee(int $nomineeId): array
    {
        if (!self::ready()) return [];
        try {
            return DB::table('gates_nominee_milestones')->where('nominee_id', $nomineeId)->orderByDesc('reached_at')->orderByDesc('id')->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public static function exists(int $nomineeId, string $kind, int $value): bool
    {
        if (!self::ready()) return false;
        try {
            return DB::table('gates_nominee_milestones')->where(['nominee_id' => $nomineeId, 'kind' => $kind, 'value' => $value])->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /** Did this address back this nominee? The only addresses that may follow. */
    public static function backed(int $nomineeId, string $email): bool
    {
        $email = trim($email);
        if ($nomineeId < 1 || $email === '') return false;
        try {
            $hash = VoteService::voterHash($email);
            if (DB::table('gates_votes')->where('nominee_id', $nomineeId)->where('voter_email_hash', $hash)->where('fraud_flag', 0)->exists()) return true;
            $paid = DB::table('gates_votes as v')->join('gates_donations as d', 'd.id', '=', 'v.donation_id')
                ->where('v.nominee_id', $nomineeId)->where('v.fraud_flag', 0)->where('d.status', 'confirmed')
                ->whereRaw('LOWER(d.donor_email) = ?', [mb_strtolower($email)]);
            if (SchemaHas::column('gates_donations', 'refunded_at')) $paid->whereNull('d.refunded_at');
            if ($paid->exists()) return true;
            $uid = (int) (DB::table('gates_users')->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->where('email_verified', 1)->value('id') ?? 0);
            if ($uid > 0) {
                // `points:<id>:<rand>` — the prefix is the member's (VoterReach); `!` escapes the LIKE on both drivers.
                $like = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], 'points:' . $uid . ':') . '%';
                if (DB::table('gates_votes')->where('nominee_id', $nomineeId)->where('fraud_flag', 0)->whereRaw("voter_email_hash LIKE ? ESCAPE '!'", [$like])->exists()) return true;
            }
        } catch (\Throwable) {}
        return false;
    }

    /** @return array{ok:bool, error?:string} */
    public static function follow(int $nomineeId, string $email): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'Milestone updates are not switched on yet.'];
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Enter the email address you voted with.'];
        $cycle = (int) (DB::table('gates_nominees as n')->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
            ->where('n.id', $nomineeId)->value('c.cycle_id') ?? 0);
        if ($cycle < 1) return ['ok' => false, 'error' => 'That nominee is not on an award.'];
        if (!self::backed($nomineeId, $email)) return ['ok' => false, 'error' => 'Updates go only to an address that voted for this nominee. Use the one you voted with.'];
        try {
            DB::table('gates_nominee_follows')->insertOrIgnore(['nominee_id' => $nomineeId, 'cycle_id' => $cycle, 'email' => $email,
                'email_hash' => VoteService::voterHash($email), 'created_at' => Carbon::now()->toDateTimeString()]);
            DB::table('gates_nominee_follows')->where('nominee_id', $nomineeId)->where('email_hash', VoteService::voterHash($email))->update(['stopped_at' => null]);
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'That could not be saved. Please try again.'];
        }
        return ['ok' => true];
    }

    /** Detect, then tell the followers. Returns how many messages went. */
    public static function sweep(?OtpService $mailer, ?Carbon $now = null, ?string $site = null, ?callable $send = null): int
    {
        if (($mailer === null && $send === null) || !self::ready()) return 0;
        $now ??= Carbon::now();
        $site = rtrim($site ?? SiteUrl::base(), '/');
        try {
            $cycles = DB::table('gates_award_cycles as c')->join('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
                ->where('p.is_active', 1)->get(['c.*'])->all();
        } catch (\Throwable) {
            return 0;
        }
        $sent = 0;
        foreach ($cycles as $c) {
            if (CyclePolicy::phaseFor($c, $now) !== CyclePhase::Voting) continue;
            self::detect((int) $c->id, $now);
            $sent += self::tell((int) $c->id, $now, $site, $mailer, $send);
        }
        return $sent;
    }

    private static function tell(int $cycleId, Carbon $now, string $site, ?OtpService $mailer, ?callable $send): int
    {
        try {
            $events = DB::table('gates_nominee_milestones')->where('cycle_id', $cycleId)->whereNull('notified_at')->where('quiet', 0)->get()->all();
        } catch (\Throwable) {
            return 0;
        }
        $byNom = [];
        foreach ($events as $e) $byNom[(int) $e->nominee_id][] = $e;
        $sent = 0;
        $stale = $now->copy()->subHours(self::FRESH_HOURS)->toDateTimeString();
        foreach ($byNom as $nid => $list) {
            usort($list, static fn ($a, $b) => self::weight($b) <=> self::weight($a));
            $best = $list[0];
            $n = DB::table('gates_nominees as n')->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->where('n.id', $nid)->first(['n.id', 'n.name', 'c.title as category']);
            if ($n && (string) $best->reached_at >= $stale) {
                $follows = DB::table('gates_nominee_follows')->where('nominee_id', $nid)->whereNull('stopped_at')
                    ->where('sent', '<', self::MAX_PER_FOLLOWER)->where('created_at', '<=', (string) $best->reached_at)->get()->all();
                foreach ($follows as $f) {
                    $key = 'milestone:' . (int) $best->id;
                    if (!BroadcastLog::claim($key, (string) $f->email, $nid)) continue;
                    [$subject, $html, $plain] = self::compose($best, (string) $n->name, (string) $n->category, $site, (string) $f->email, (int) $f->sent + 1);
                    $ok = false;
                    try {
                        $ok = $send ? (bool) $send((string) $f->email, $subject, $html, $plain)
                                    : (($mailer->sendBranded((string) $f->email, $subject, $html, $plain, 'Awards')['success'] ?? false) === true);
                    } catch (\Throwable $ex) {
                        error_log('[milestones] send failed: ' . $ex->getMessage());
                    }
                    BroadcastLog::settle($key, (string) $f->email, $ok);
                    if ($ok) { $sent++; DB::table('gates_nominee_follows')->where('id', $f->id)->increment('sent'); }
                }
            }
            DB::table('gates_nominee_milestones')->whereIn('id', array_map(static fn ($e) => (int) $e->id, $list))->update(['notified_at' => $now->toDateTimeString()]);
        }
        return $sent;
    }

    /** The lead before the top three before the shortlist before a supporter count (the bigger, the better). */
    private static function weight(object $e): int
    {
        return match ((string) $e->kind) { 'lead' => 3_000_000, 'top3' => 2_000_000, 'shortlist' => 1_000_000, default => (int) $e->value };
    }

    /** "Chiamaka Eze has passed 100 supporters" — the words for a milestone, about the nominee. */
    public static function words(string $kind, int $value, string $name, string $category): string
    {
        return match ($kind) {
            'lead'      => $name . ' is now leading ' . $category,
            'top3'      => $name . ' is now in the top 3 of ' . $category,
            'shortlist' => $name . ' made the shortlist for ' . $category,
            default     => $name . ' has passed ' . number_format($value) . ' supporters',
        };
    }

    /** @return array{0:string,1:string,2:string} */
    public static function compose(object $e, string $name, string $category, string $site, string $email, int $nth): array
    {
        $x = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $subject = self::words((string) $e->kind, (int) $e->value, $name, $category);
        $lead = 'Your vote is part of that. You backed ' . $name . ', and you asked to hear when they reach a milestone.';
        $nudge = 'Share their link with one more person who would back them.';
        $page = $site . NomineeUrl::path((int) $e->nominee_id);
        $left = self::MAX_PER_FOLLOWER - $nth;
        $cap = $left > 0 ? 'You will get at most ' . $left . ' more of these this edition.' : 'This is the last of these for this edition; the result will follow.';
        $off = EmailOptOut::url($site, $email);
        [$ink, $ink2, $go, $on, $soft] = [Accent::hex('ink'), Accent::hex('ink-2'), Accent::hex('green'), Accent::hex('surface'), Accent::hex('soft')];
        $html = '<h1 style="margin:0;font-family:Helvetica,Arial,sans-serif;font-weight:700;font-size:24px;line-height:30px;color:' . $ink . '">' . $x($subject) . '</h1>'
              . '<p style="margin:13px 0 0;font-size:16px;line-height:1.6;color:' . $ink2 . '">' . $x($lead) . '</p>'
              . '<p style="margin:13px 0 0;font-size:16px;line-height:1.6;color:' . $ink2 . '">' . $x($nudge) . '</p>'
              . '<p style="text-align:center;margin:24px 0"><a href="' . $x($page) . '" style="display:inline-block;padding:13px 28px;background:' . $go . ';color:' . $on . ';border-radius:999px;font-weight:700;text-decoration:none;font-size:16px">See ' . $x($name) . '’s race</a></p>'
              . '<p style="margin:0;font-size:13px;line-height:1.6;color:' . $soft . '">' . $x($cap) . ' <a href="' . $x($off) . '" style="color:' . $ink2 . '">Unsubscribe</a>.</p>';
        $plain = $subject . "\n\n" . $lead . "\n\n" . $nudge . "\n\nSee " . $name . '’s race: ' . $page . "\n\n" . $cap . ' Unsubscribe: ' . $off;
        return [$subject, $html, $plain];
    }
}
