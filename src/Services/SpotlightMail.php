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
 *   champion    "You were Champion of the Day" (or of the Week, or of the Award), once
 *               DailyChampion has announced it. For a day: once the West Africa Time
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
            // The week that has ENDED, read at its last second (the Mobiliser of the Week).
            $endOfLastWeek  = $lagos->copy()->startOfWeek(Carbon::MONDAY)->subSecond();

            // The champion is told once the day is ANNOUNCED (DailyChampion seals it the morning
            // after, once payments have settled), and told the announced name — never a live
            // reading that a refund could have changed since. The board is read again only for
            // the address, and only when it still names the same person.
            // Days, then weeks, then the award: each told once its period is announced.
            foreach ([...DailyChampion::due($now), ...DailyChampion::dueWeeks($now), DailyChampion::AWARD] as $day) {
                $row = DailyChampion::day($cid, $day);
                if ($row === null) continue;
                $who = DailyChampion::leaderAt($cid, $day);
                if (!$who || (string) $who['key'] !== (string) $row->person_key) continue;
                $sent += self::send($mailer, 'champion', $cid, $day, $who + ['row' => $row], $prog, $c, $site);
            }

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

        $row = $who['row'] ?? null;
        $ckind = is_object($row) ? (string) ($row->kind ?? 'day') : 'day';
        $ctitle = DailyChampion::KINDS[$ckind] ?? DailyChampion::KINDS['day'];
        $gave = number_format($votes) . ($votes === 1 ? ' vote' : ' votes') . ' — more than anybody else' . ($for !== '' ? ', backing ' . $for : '');
        [$subject, $lead] = match ($kind) {
            'champion'  => match ($ckind) {
                'week'  => ["You were {$place}’s Champion of the Week", 'Last week you gave ' . $gave . '. That made you Champion of the Week in ' . $title . '.'],
                'award' => ["You are {$place}’s Champion of the Award", 'Across the whole of ' . $title . ' you gave ' . $gave . '. That makes you Champion of the Award.'],
                default => ["You were {$place}’s Champion of the Day", 'Yesterday you gave ' . $gave . '. That made you Champion of the Day in ' . $title . '.'],
            },
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

        // The champion's own extras: the announcement to share, and — once per campaign — the
        // gift, with the code to quote and where to send it (AwardCampaign, DailyChampion).
        $extraH = ''; $extraP = '';
        if ($kind === 'champion' && is_object($row)) {
            $page = $site . '/vote/' . rawurlencode((string) $prog->slug) . '/champion/' . $row->day;
            $extraH .= '<p style="margin:13px 0 0;font-size:16px;line-height:1.6;color:' . Accent::hex('ink') . '">Your announcement, with a card to share: <a href="' . $e($page) . '" style="color:' . Accent::hex('ink') . '">' . $e($page) . '</a></p>';
            $extraP .= "Your announcement, with a card to share: " . $page . "\n\n";
            if ((int) $row->gift === 1) {
                $code = DailyChampion::code($row);
                $claim = AwardCampaign::claimLink($c, $ctitle . ': claiming my gift ' . $code);
                $gifts = $c[['day' => 'champion_gifts', 'week' => 'week_gifts', 'award' => 'award_gifts'][$ckind] ?? 'champion_gifts'] ?? [];
                $how = $claim ? ' Contact us with it to claim your gift: ' . $claim['label'] . '.' : ' Keep it: the team will contact you about your gift.';
                $extraH .= ($gifts ? '<p style="margin:13px 0 0;font-size:16px;line-height:1.6;color:' . Accent::hex('ink-2') . '">Your prize as ' . $e($ctitle) . ':</p><ul style="margin:6px 0 0;padding-left:20px;font-size:16px;line-height:1.6;color:' . Accent::hex('ink') . '">'
                              . implode('', array_map(static fn ($g) => '<li>' . $e((string) $g) . '</li>', $gifts)) . '</ul>' : '')
                         . '<p style="margin:13px 0 0;font-size:16px;line-height:1.6;color:' . Accent::hex('ink-2') . '">Your claim code is <strong style="font-family:Consolas,monospace;color:' . Accent::hex('ink') . '">' . $e($code) . '</strong>.' . $e($how) . '</p>';
                $extraP .= ($gifts ? "Your prize as {$ctitle}:\n- " . implode("\n- ", $gifts) . "\n\n" : '') . 'Your claim code is ' . $code . '.' . $how . "\n\n";
            } else {
                $extraH .= '<p style="margin:13px 0 0;font-size:16px;line-height:1.6;color:' . Accent::hex('ink-2') . '">Each champion’s gift is given once per person each campaign, and yours was for an earlier win. Every time you are champion is still announced and yours to share.</p>';
                $extraP .= "Each champion’s gift is given once per person each campaign, and yours was for an earlier win. Every time you are champion is still announced and yours to share.\n\n";
            }
        }

        [$ink, $ink2, $go, $on, $soft] = [Accent::hex('ink'), Accent::hex('ink-2'), Accent::hex('green'), Accent::hex('surface'), Accent::hex('soft')];
        $html = '<h1 style="margin:0;font-family:Helvetica,Arial,sans-serif;font-weight:700;font-size:24px;line-height:30px;color:' . $ink . '">' . $e($subject) . '</h1>'
              . '<p style="margin:13px 0 0;font-size:16px;line-height:1.6;color:' . $ink2 . '">' . $e($lead) . '</p>'
              . $extraH
              . ($c['rally'] !== '' ? '<p style="margin:13px 0 0;font-size:16px;line-height:1.6;color:' . $ink . '"><strong>' . $e($c['rally']) . '</strong></p>' : '')
              . '<p style="text-align:center;margin:24px 0"><a href="' . $e($board) . '" style="display:inline-block;padding:13px 28px;background:' . $go . ';color:' . $on . ';border-radius:999px;font-weight:700;text-decoration:none;font-size:16px">See the Spotlight</a></p>'
              . '<p style="margin:0;font-size:13px;line-height:1.6;color:' . $soft . '">' . $e($shown) . ' Prefer not to be on it? <a href="' . $e($off) . '" style="color:' . $ink2 . '">Take me off the Spotlight</a>.</p>';
        $plain = $subject . "\n\n" . $lead . "\n\n" . $extraP . ($c['rally'] !== '' ? $c['rally'] . "\n\n" : '')
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
