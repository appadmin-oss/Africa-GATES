<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Accent;
use AfricaGates\Support\BroadcastLog;
use AfricaGates\Support\NomineeUrl;
use AfricaGates\Support\SchemaHas;
use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * "WHO'S BEHIND YOU" — a nominee's own view of their support, on /account/nomination (owner,
 * 16 Nov 2026: "build them all"): how many people, how many came this week, the milestones they
 * have reached (each with its card), their poster, and the people who brought others to vote
 * for them — with a way to THANK EACH ONE PERSONALLY.
 *
 * A mobiliser is a supporter whose own link (VoteRecruit) brought people to vote. They did the
 * work a campaign runs on, and until now nobody but the board ever said so. The nominee's thanks
 * goes by email, once per mobiliser, from the nominee in their own words — never the address:
 * the nominee sees a name only where the board's rule would show one (an account, unless taken
 * down; anybody else by consent), and "A supporter" otherwise.
 *
 * What a nominee may NOT do here: mail anybody who has not mobilised for them, mail the same
 * person twice (BroadcastLog's UNIQUE campaign/address decides), or see who anybody is beyond
 * what the public board already shows.
 */
final class NomineeBackers
{
    public const TOP = 10;
    public const NOTE_MAX = 300;

    /**
     * @return array{people:int, votes:int, week:int, last_week:int, milestones:list<array{kind:string,value:int,words:string,card:string,at:string}>,
     *               mobilisers:list<array{id:int, name:string, people:int, reachable:bool, thanked:bool}>, page:string, poster:string}
     */
    public static function dashboard(int $nomineeId): array
    {
        $g = SupporterSpotlight::growthOf($nomineeId);
        $page = NomineeUrl::path($nomineeId);
        try {
            $n = DB::table('gates_nominees as n')->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->where('n.id', $nomineeId)->first(['n.name', 'c.title as category']);
        } catch (\Throwable) {
            $n = null;
        }
        $ms = [];
        foreach (NomineeMilestones::forNominee($nomineeId) as $m) {
            $ms[] = ['kind' => (string) $m->kind, 'value' => (int) $m->value, 'at' => (string) $m->reached_at,
                'words' => NomineeMilestones::words((string) $m->kind, (int) $m->value, (string) ($n->name ?? ''), (string) ($n->category ?? '')),
                'card' => $page . '/milestone/' . $m->kind . '-' . (int) $m->value . '.png'];
        }
        return $g + ['milestones' => $ms, 'mobilisers' => self::mobilisers($nomineeId), 'page' => $page, 'poster' => $page . '/poster'];
    }

    /**
     * The people whose own links brought others to vote for this nominee, most first.
     *
     * @return list<array{id:int, name:string, people:int, reachable:bool, thanked:bool}>
     */
    public static function mobilisers(int $nomineeId, int $limit = self::TOP): array
    {
        $rows = self::recruits($nomineeId, $limit);
        if ($rows === []) return [];
        $who = self::people($nomineeId);
        $done = self::thanked($nomineeId);
        $out = [];
        foreach ($rows as $r) {
            $p = $who[(string) $r->email_hash] ?? null;
            $email = (string) ($p['email'] ?? '');
            $out[] = ['id' => (int) $r->id, 'name' => (string) ($p['name'] ?? '') !== '' ? (string) $p['name'] : 'A supporter', 'people' => (int) $r->people,
                'reachable' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
                'thanked' => $email !== '' && isset($done[EmailOptOut::hash($email)])];
        }
        return $out;
    }

    /**
     * THANK ONE MOBILISER, personally, now. Once per mobiliser per nominee — the claim is the
     * guard, so a double press or two tabs send one message.
     *
     * @return array{ok:bool, error?:string}
     */
    public static function thank(int $nomineeId, int $recruitId, string $note, ?OtpService $mailer, ?string $site = null, ?callable $send = null): array
    {
        $note = trim(preg_replace('/[ \t]+/', ' ', strip_tags($note)) ?? '');
        if (mb_strlen($note) > self::NOTE_MAX) return ['ok' => false, 'error' => 'Keep it to ' . self::NOTE_MAX . ' characters.'];
        $r = null;
        foreach (self::recruits($nomineeId, 1000) as $row) if ((int) $row->id === $recruitId) $r = $row;
        if ($r === null) return ['ok' => false, 'error' => 'That supporter has not brought anybody to vote for you, so there is nothing to thank them for here.'];
        $p = self::people($nomineeId)[(string) $r->email_hash] ?? null;
        $email = trim((string) ($p['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'We have no way to reach that supporter by email, so the thanks could not be sent.'];
        try {
            $n = DB::table('gates_nominees')->where('id', $nomineeId)->first(['id', 'name']);
        } catch (\Throwable) {
            $n = null;
        }
        if (!$n) return ['ok' => false, 'error' => 'That nomination could not be found.'];
        if ($mailer === null && $send === null) return ['ok' => false, 'error' => 'Email is not available just now. Please try again later.'];
        $key = self::campaign($nomineeId);
        if (!BroadcastLog::claim($key, $email, $nomineeId)) return ['ok' => false, 'error' => 'You have already thanked them. Thank you for doing it.'];
        $site = rtrim($site ?? SiteUrl::base(), '/');
        [$subject, $html, $plain] = self::compose((string) $n->name, (int) $r->people, $note, $site . NomineeUrl::path($nomineeId), $site, $email);
        $ok = false;
        try {
            $ok = $send ? (bool) $send($email, $subject, $html, $plain)
                        : (($mailer->sendBranded($email, $subject, $html, $plain, 'Awards')['success'] ?? false) === true);
        } catch (\Throwable $e) {
            error_log('[mobiliser-thanks] send failed: ' . $e->getMessage());
        }
        if (!$ok) {
            // A message that did not go is not a thank-you said: free the address to try again
            // (release takes only a row still at `sending`, so it comes before any settle).
            BroadcastLog::release($key, $email);
            return ['ok' => false, 'error' => 'The email could not be sent just now. Please try again.'];
        }
        BroadcastLog::settle($key, $email, true);
        return ['ok' => true];
    }

    /** @return array{0:string,1:string,2:string} */
    public static function compose(string $name, int $people, string $note, string $page, string $site, string $email): array
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $subject = $name . ' thanked you personally';
        $lead = 'You brought ' . ($people === 1 ? 'one person' : number_format($people) . ' people') . ' to vote for ' . $name
              . ' through your own link. ' . $name . ' saw that, and asked us to tell you: thank you.';
        $off = EmailOptOut::url($site, $email);
        [$ink, $ink2, $line, $soft] = [Accent::hex('ink'), Accent::hex('ink-2'), Accent::hex('line'), Accent::hex('soft')];
        $html = '<h1 style="margin:0;font-family:Helvetica,Arial,sans-serif;font-weight:700;font-size:24px;line-height:30px;color:' . $ink . '">' . $e($subject) . '</h1>'
              . '<p style="margin:13px 0 0;font-size:16px;line-height:1.6;color:' . $ink2 . '">' . $e($lead) . '</p>'
              . ($note !== '' ? '<blockquote style="margin:16px 0 0;padding:4px 0 4px 16px;border-left:3px solid ' . $line . ';font-size:17px;line-height:1.6;color:' . $ink . '">'
                  . nl2br($e($note)) . '<br><span style="font-size:15px;color:' . $ink2 . '">— ' . $e($name) . '</span></blockquote>' : '')
              . '<p style="margin:20px 0 0;font-size:15px"><a href="' . $e($page) . '" style="color:' . $ink . '">See ' . $e($name) . '’s page</a></p>'
              . '<p style="margin:20px 0 0;font-size:13px;line-height:1.6;color:' . $soft . '">You are receiving this because you shared your link for ' . $e($name) . '. <a href="' . $e($off) . '" style="color:' . $ink2 . '">Unsubscribe</a>.</p>';
        $plain = $subject . "\n\n" . $lead . ($note !== '' ? "\n\n" . $note . "\n— " . $name : '') . "\n\n" . $name . '’s page: ' . $page
               . "\n\nYou are receiving this because you shared your link for " . $name . '. Unsubscribe: ' . $off;
        return [$subject, $html, $plain];
    }

    private static function campaign(int $nomineeId): string
    {
        return 'mobiliser-thanks:' . $nomineeId;
    }

    /** @return list<object{id:int, email_hash:string, people:int}> */
    private static function recruits(int $nomineeId, int $limit): array
    {
        if (!SchemaHas::table('gates_vote_recruits') || !SchemaHas::table('gates_vote_recruit_credits')) return [];
        try {
            return DB::table('gates_vote_recruits as r')->join('gates_vote_recruit_credits as k', 'k.recruit_id', '=', 'r.id')
                ->where('r.nominee_id', $nomineeId)->groupBy('r.id', 'r.email_hash')
                ->orderByRaw('COUNT(k.id) DESC')->orderBy('r.id')->limit($limit)
                ->get(['r.id', 'r.email_hash', DB::raw('COUNT(k.id) as people')])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** The board's people for this nominee's edition, by person key. @return array<string,array> */
    private static function people(int $nomineeId): array
    {
        try {
            $cycle = (int) (DB::table('gates_nominees as n')->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')->where('n.id', $nomineeId)->value('c.cycle_id') ?? 0);
        } catch (\Throwable) {
            return [];
        }
        return $cycle ? SupporterSpotlight::backers($cycle) : [];
    }

    /** @return array<string,true> email hashes already thanked by this nominee */
    private static function thanked(int $nomineeId): array
    {
        try {
            $h = DB::table('gates_broadcast_log')->where('campaign', self::campaign($nomineeId))->where('status', BroadcastLog::SENT)->pluck('email_hash')->all();
        } catch (\Throwable) {
            return [];
        }
        return array_fill_keys(array_map('strval', $h), true);
    }
}
