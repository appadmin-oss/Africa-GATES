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
 * A THANK-YOU FROM THE NOMINEE to everybody who backed them (owner, 16 Nov 2026: "make the
 * voter feel good and proud of themselves … to really honour them").
 *
 * Being thanked by the person you stood behind is the reward no platform can manufacture, and
 * it costs nothing. So a nominee writes one message on their own page (/account/nomination),
 * and it goes to every backer who can be reached, once, and stays on their vote page for
 * everybody else who backed them.
 *
 *   · ONE PER NOMINEE. It can be changed for GRACE_MINUTES after it is written — a typo is
 *     caught on rereading — and then it is sent and final: a second message is a campaign.
 *   · WHO: SupporterSpotlight::backers(), the board's own people, reached by a checkout's
 *     address or a verified account's. A free voter with no account is a hash and is not
 *     written to; the page shows the nominee both numbers, so they know who it reaches.
 *   · ONCE: each address claimed before sending (BroadcastLog), BATCH per tick; `sent_at`
 *     is stamped when nobody is left to send to.
 *   · It is the nominee's words. Tags are stripped and nothing is rendered as HTML.
 */
final class NomineeThanks
{
    public const MIN = 20;
    public const MAX = 600;
    public const GRACE_MINUTES = 30;
    public const BATCH = 200;

    public static function ready(): bool
    {
        return SchemaHas::table('gates_nominee_thanks');
    }

    public static function forNominee(int $nomineeId): ?object
    {
        if ($nomineeId < 1 || !self::ready()) return null;
        try {
            return DB::table('gates_nominee_thanks')->where('nominee_id', $nomineeId)->first() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Can it still be changed? Until the grace runs out, and never once sending has begun. */
    public static function editable(?object $t, ?Carbon $now = null): bool
    {
        if ($t === null) return true;
        if ($t->sent_at !== null) return false;
        $now ??= Carbon::now();
        return Carbon::parse((string) $t->created_at)->addMinutes(self::GRACE_MINUTES)->greaterThan($now);
    }

    /** @return array{ok:bool, error?:string} */
    public static function save(int $nomineeId, int $userId, string $message): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'Thank-you messages are not switched on yet.'];
        $msg = trim(preg_replace('/[ \t]+/', ' ', strip_tags($message)) ?? '');
        if (mb_strlen($msg) < self::MIN) return ['ok' => false, 'error' => 'Write a little more: at least ' . self::MIN . ' characters.'];
        if (mb_strlen($msg) > self::MAX) return ['ok' => false, 'error' => 'Keep it to ' . self::MAX . ' characters, so it is read to the end.'];
        $t = self::forNominee($nomineeId);
        if (!self::editable($t)) return ['ok' => false, 'error' => 'Your thank-you has already gone out, so it can’t be changed now.'];
        $at = Carbon::now()->toDateTimeString();
        try {
            if ($t) DB::table('gates_nominee_thanks')->where('id', $t->id)->update(['message' => $msg, 'updated_at' => $at]);
            else DB::table('gates_nominee_thanks')->insert(['nominee_id' => $nomineeId, 'user_id' => $userId, 'message' => $msg, 'created_at' => $at, 'updated_at' => $at]);
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'It could not be saved. Nothing was sent; please try again.'];
        }
        return ['ok' => true];
    }

    /**
     * Who backed this nominee, and how many of them a message can reach.
     *
     * @return array{people:int, reach:int}
     */
    public static function audience(int $nomineeId): array
    {
        $cycle = self::cycleOf($nomineeId);
        $people = 0; $reach = 0;
        foreach ($cycle ? SupporterSpotlight::backers($cycle) : [] as $b) {
            if (!isset($b['by'][$nomineeId])) continue;
            $people++;
            if (filter_var((string) $b['email'], FILTER_VALIDATE_EMAIL)) $reach++;
        }
        return ['people' => $people, 'reach' => $reach];
    }

    /** Send the thank-yous whose grace has run out. Returns how many messages went. */
    public static function sweep(?OtpService $mailer, ?Carbon $now = null, ?string $site = null, ?callable $send = null): int
    {
        if (($mailer === null && $send === null) || !self::ready()) return 0;
        $now ??= Carbon::now();
        $site = rtrim($site ?? SiteUrl::base(), '/');
        $sent = 0;
        try {
            $due = DB::table('gates_nominee_thanks')->whereNull('sent_at')
                ->where('created_at', '<=', $now->copy()->subMinutes(self::GRACE_MINUTES)->toDateTimeString())->orderBy('id')->get()->all();
        } catch (\Throwable) {
            return 0;
        }
        foreach ($due as $t) {
            $nid = (int) $t->nominee_id;
            $n = DB::table('gates_nominees')->where('id', $nid)->first(['id', 'name']);
            $cycle = self::cycleOf($nid);
            if (!$n || !$cycle) { DB::table('gates_nominee_thanks')->where('id', $t->id)->update(['sent_at' => $now->toDateTimeString()]); continue; }
            $key = 'nominee-thanks:' . (int) $t->id;
            $left = false; $reached = 0;
            foreach (SupporterSpotlight::backers($cycle) as $b) {
                if (!isset($b['by'][$nid])) continue;
                $email = trim((string) $b['email']);
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
                if ($sent >= self::BATCH) { $left = true; break; }
                if (!BroadcastLog::claim($key, $email, $nid)) continue;
                [$subject, $html, $plain] = self::compose((string) $n->name, (string) $t->message, $site . NomineeUrl::path($nid), $site, $email);
                $ok = false;
                try {
                    $ok = $send ? (bool) $send($email, $subject, $html, $plain)
                                : (($mailer->sendBranded($email, $subject, $html, $plain, 'Awards')['success'] ?? false) === true);
                } catch (\Throwable $e) {
                    error_log('[nominee-thanks] send failed: ' . $e->getMessage());
                }
                BroadcastLog::settle($key, $email, $ok);
                if ($ok) { $sent++; $reached++; }
            }
            DB::table('gates_nominee_thanks')->where('id', $t->id)->update(array_filter([
                'reached' => DB::raw('reached + ' . $reached), 'sent_at' => $left ? null : $now->toDateTimeString()], static fn ($v) => $v !== null));
            if ($left) break;
        }
        return $sent;
    }

    /** @return array{0:string,1:string,2:string} */
    public static function compose(string $name, string $message, string $page, string $site, string $email): array
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $subject = 'A thank-you from ' . $name;
        $lead = 'You backed ' . $name . ' on Africa GATES. They wrote this to everybody who did:';
        $off = EmailOptOut::url($site, $email);
        [$ink, $ink2, $line, $soft] = [Accent::hex('ink'), Accent::hex('ink-2'), Accent::hex('line'), Accent::hex('soft')];
        $html = '<h1 style="margin:0;font-family:Helvetica,Arial,sans-serif;font-weight:700;font-size:24px;line-height:30px;color:' . $ink . '">' . $e($subject) . '</h1>'
              . '<p style="margin:13px 0 0;font-size:16px;line-height:1.6;color:' . $ink2 . '">' . $e($lead) . '</p>'
              . '<blockquote style="margin:16px 0 0;padding:4px 0 4px 16px;border-left:3px solid ' . $line . ';font-size:17px;line-height:1.6;color:' . $ink . '">'
              . nl2br($e($message)) . '<br><span style="font-size:15px;color:' . $ink2 . '">— ' . $e($name) . '</span></blockquote>'
              . '<p style="margin:20px 0 0;font-size:15px"><a href="' . $e($page) . '" style="color:' . $ink . '">See ' . $e($name) . '’s page</a> · <a href="' . $e($page . '/backed.png') . '" style="color:' . $ink . '">Get your “I backed ' . $e($name) . '” card</a></p>'
              . '<p style="margin:20px 0 0;font-size:13px;line-height:1.6;color:' . $soft . '">You are receiving this because you voted for ' . $e($name) . '. <a href="' . $e($off) . '" style="color:' . $ink2 . '">Unsubscribe</a>.</p>';
        $plain = $subject . "\n\n" . $lead . "\n\n" . $message . "\n— " . $name . "\n\n" . $name . '’s page: ' . $page
               . "\nYour “I backed " . $name . "” card: " . $page . "/backed.png\n\nYou are receiving this because you voted for " . $name . '. Unsubscribe: ' . $off;
        return [$subject, $html, $plain];
    }

    private static function cycleOf(int $nomineeId): int
    {
        try {
            return (int) (DB::table('gates_nominees as n')->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->where('n.id', $nomineeId)->value('c.cycle_id') ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }
}
