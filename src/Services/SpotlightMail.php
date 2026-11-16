<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Accent;
use AfricaGates\Support\AwardCampaign;
use AfricaGates\Support\SchemaHas;
use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * TELLING THE PEOPLE THE SPOTLIGHT CELEBRATES (owner, 16 Nov 2026: "mails should be sent to
 * them"). A board somebody never sees is a board that thanks nobody.
 *
 * Three messages, each sent once, each CLAIMED before it is composed
 * (gates_spotlight_notices' UNIQUE key is the mutex, as in SupporterHonours):
 *
 *   champion    the day after: "You were Champion of the Day", once the West Africa Time
 *               day is over — a champion named at 15:00 can still be overtaken at 23:00,
 *               and the framework's rule is to publish after the day closes
 *   mobiliser   the Monday after: "You were Mobiliser of the Week"
 *   top         the first time somebody enters the edition's top supporters
 *
 * ── EVERY MESSAGE CARRIES THE WAY OFF ────────────────────────────────────────
 * An account holder is shown with their own name and photo by default, and may ask not to
 * be. So each message says what is shown and why, and carries a signed one-tap link to be
 * taken off the Spotlight ({@see self::hideUrl()}). Somebody who has already asked is not
 * written to at all.
 *
 * ── WHO CAN BE WRITTEN TO ────────────────────────────────────────────────────
 * The address a checkout was given, or a member account's verified address. A free vote is
 * stored as a hash only, deliberately (SupporterHonours says why), so a free voter with no
 * account is celebrated on the board and simply cannot be emailed — their claim is never
 * taken, so they are not marked as told.
 */
final class SpotlightMail
{
    public const BATCH = 40;

    public static function sweep(?OtpService $mailer, ?Carbon $now = null, ?string $site = null): int
    {
        if ($mailer === null || !SchemaHas::table('gates_spotlight_notices') || !SchemaHas::column('gates_award_programmes', 'campaign_json')) return 0;
        $now ??= Carbon::now();
        $site = rtrim($site ?? SiteUrl::base(), '/');
        $sent = 0;

        try {
            $progs = DB::table('gates_award_programmes')->where('is_active', 1)->whereNotNull('campaign_json')->get(['id', 'slug', 'title', 'campaign_json']);
        } catch (\Throwable) {
            return 0;
        }
        foreach ($progs as $prog) {
            $c = AwardCampaign::of($prog);
            if (!$c['spotlight']) continue;
            $cycle = BallotGuard::currentCycleForProgramme((int) $prog->id);
            if (!$cycle) continue;
            $phase = CyclePolicy::phaseFor($cycle)->value;
            if (in_array($phase, ['upcoming', 'nominations', 'archived'], true)) continue;
            $cid = (int) $cycle->id;

            $lagos = $now->copy()->setTimezone(SupporterSpotlight::ZONE);
            // The day that has ENDED, read at its last second, and the week likewise.
            $endOfYesterday = $lagos->copy()->startOfDay()->subSecond();
            $endOfLastWeek  = $lagos->copy()->startOfWeek(Carbon::MONDAY)->subSecond();

            $y = SupporterSpotlight::build($cid, $endOfYesterday->copy()->setTimezone(date_default_timezone_get()));
            if ($y['champion']) $sent += self::send($mailer, 'champion', $cid, $endOfYesterday->format('Y-m-d'), $y['champion'], $prog, $c, $site);

            if ($lagos->isMonday()) {
                $w = SupporterSpotlight::build($cid, $endOfLastWeek->copy()->setTimezone(date_default_timezone_get()));
                if ($w['mobiliser']) $sent += self::send($mailer, 'mobiliser', $cid, $endOfLastWeek->format('o-\WW'), $w['mobiliser'], $prog, $c, $site);
            }

            foreach (SupporterSpotlight::build($cid, $now)['top'] as $t) {
                if ($sent >= self::BATCH) return $sent;
                $sent += self::send($mailer, 'top', $cid, 'edition', $t, $prog, $c, $site);
            }
            if ($sent >= self::BATCH) return $sent;
        }
        return $sent;
    }

    /** Claim, compose, send, record. 1 when a message went, 0 otherwise. */
    private static function send(OtpService $mailer, string $kind, int $cycleId, string $period, array $who, object $prog, array $c, string $site): int
    {
        $email = trim((string) ($who['email'] ?? ''));
        $key = (string) ($who['key'] ?? '');
        if ($key === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return 0;
        if (self::hidden($key)) return 0;
        try {
            $mine = DB::table('gates_spotlight_notices')->insertOrIgnore(['kind' => $kind, 'cycle_id' => $cycleId, 'period' => $period,
                'person_key' => $key, 'delivered' => 0, 'created_at' => Carbon::now()->toDateTimeString()]);
        } catch (\Throwable) {
            return 0;
        }
        if ($mine !== 1) return 0;

        [$subject, $html, $plain] = self::compose($kind, $who, $prog, $c, $site);
        $ok = false;
        try {
            $r = $mailer->sendBranded($email, $subject, $html, $plain, 'Awards');
            $ok = (bool) ($r['success'] ?? false);
        } catch (\Throwable $e) {
            error_log('[spotlight-mail] ' . $kind . ' send failed: ' . $e->getMessage());
        }
        try {
            DB::table('gates_spotlight_notices')->where(['kind' => $kind, 'cycle_id' => $cycleId, 'period' => $period, 'person_key' => $key])
                ->update(['delivered' => $ok ? 1 : 0]);
        } catch (\Throwable) {}
        return $ok ? 1 : 0;
    }

    /** @return array{0:string,1:string,2:string} subject, html, plain */
    public static function compose(string $kind, array $who, object $prog, array $c, string $site): array
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $title = (string) $prog->title;
        $place = $c['community'] !== '' ? $c['community'] : $title;
        $votes = (int) ($who['votes'] ?? 0);
        $for = (string) ($who['for'] ?? '');
        $board = $site . '/vote/' . rawurlencode((string) $prog->slug) . '/spotlight';
        $off = self::hideUrl($site, (string) $who['key']);
        $named = ($who['name'] ?? null) !== null;

        [$subject, $lead] = match ($kind) {
            'champion'  => ["You were {$place}’s Champion of the Day",
                            'Yesterday you gave ' . number_format($votes) . ($votes === 1 ? ' vote' : ' votes') . ' — more than anybody else'
                            . ($for !== '' ? ', backing ' . $for : '') . '. That made you Champion of the Day in ' . $title . '.'],
            'mobiliser' => ["You were {$place}’s Mobiliser of the Week",
                            'Last week ' . number_format((int) ($who['people'] ?? 0)) . ' people voted through your own link — more than anybody else brought. That made you Mobiliser of the Week in ' . $title . '.'],
            default     => ["You’re in the Supporter Spotlight for {$title}",
                            'With ' . number_format($votes) . ($votes === 1 ? ' vote' : ' votes') . ($for !== '' ? ' backing ' . $for : '')
                            . ', you are number ' . (int) ($who['rank'] ?? 0) . ' among the people backing their own in ' . $title . '.'],
        };
        $shown = $named
            ? ((int) ($who['uid'] ?? 0) > 0
                ? 'The vote page shows your name' . (($who['photo'] ?? null) ? ' and your profile photo' : '') . ' from your Africa GATES account, so the community can thank you.'
                : 'The vote page shows the name you asked to be shown with your votes.')
            : 'You are shown as an anonymous supporter, because you did not ask for your name to be shown.';

        [$ink, $ink2, $go, $on, $soft] = [Accent::hex('ink'), Accent::hex('ink-2'), Accent::hex('green'), Accent::hex('surface'), Accent::hex('soft')];
        $html = '<h1 style="margin:0;font-family:Helvetica,Arial,sans-serif;font-weight:700;font-size:24px;line-height:30px;color:' . $ink . '">' . $e($subject) . '</h1>'
              . '<p style="margin:13px 0 0;font-size:16px;line-height:1.6;color:' . $ink2 . '">' . $e($lead) . '</p>'
              . ($c['rally'] !== '' ? '<p style="margin:13px 0 0;font-size:16px;line-height:1.6;color:' . $ink . '"><strong>' . $e($c['rally']) . '</strong></p>' : '')
              . '<p style="text-align:center;margin:24px 0"><a href="' . $e($board) . '" style="display:inline-block;padding:13px 28px;background:' . $go . ';color:' . $on . ';border-radius:999px;font-weight:700;text-decoration:none;font-size:16px">See the Spotlight</a></p>'
              . '<p style="margin:0;font-size:13px;line-height:1.6;color:' . $soft . '">' . $e($shown) . ' Prefer not to be on it? <a href="' . $e($off) . '" style="color:' . $ink2 . '">Take me off the Spotlight</a>.</p>';
        $plain = $subject . "\n\n" . $lead . "\n\n" . ($c['rally'] !== '' ? $c['rally'] . "\n\n" : '')
               . 'See the Spotlight: ' . $board . "\n\n" . $shown . "\nPrefer not to be on it? Take me off the Spotlight: " . $off;
        return [$subject, $html, $plain];
    }

    /** The signed, one-tap way off the Spotlight. */
    public static function hideUrl(string $site, string $key): string
    {
        return rtrim($site, '/') . '/spotlight/hide?k=' . rawurlencode($key) . '&t=' . EmailOptOut::sign($key, 'spotlight-hide');
    }

    public static function validHide(string $key, string $token): bool
    {
        return $key !== '' && $token !== '' && hash_equals(EmailOptOut::sign($key, 'spotlight-hide'), $token);
    }

    private static function hidden(string $key): bool
    {
        try {
            return SchemaHas::table('gates_spotlight_hidden') && DB::table('gates_spotlight_hidden')->where('person_key', $key)->exists();
        } catch (\Throwable) {
            return true;
        }
    }
}
