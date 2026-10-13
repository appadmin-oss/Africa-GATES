<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * WHO GEE IS TALKING TO, WHEN THEY ARE SIGNED IN (owner, 12 Oct 2026: "Gee should be more
 * personalized for signed-in users").
 *
 * Gee greeted a member by first name and then treated them exactly like a stranger: the
 * same four starters, and a prompt that knew only "a signed-in member". So "where is my
 * ticket" from somebody holding one ticket, for one gala next week, started with "which
 * event?". One read of the member's own state now feeds both faces:
 *
 *   · `starters()` — the empty state offers what is actually going on for them: the ticket
 *     they hold, the nomination waiting on a decision, the points that now buy a vote, the
 *     email they never verified; the general questions fill the rest;
 *   · `promptBlock()` — the model is told the same facts, so it answers about THEIR ticket
 *     without asking which, and uses their name the way a person would.
 *
 * WHAT IS NEVER IN IT: the email address, the phone number, a ticket's door code, a payment
 * reference. Counts, names of the person's own items and dates only — the block goes to a
 * model provider with every turn, and nothing in it is needed to be helpful that would be
 * harmful to leak. Every figure comes from the same service the account page reads, so
 * Gee and the account cannot disagree.
 *
 * Memoised per session for five minutes: the widget is on every page, and its starters
 * should not cost a member five queries per page view.
 */
final class GeePersonal
{
    private const TTL = 300;
    private const KEY = 'ag_gee_personal';

    /**
     * The signed-in member's facts, or null for a guest.
     *
     * @return array{first:string, points:?int, per_vote:?int, verified:bool, votes:int,
     *               nominations:int, pending:int, next_event:?array{event:string,when:string},
     *               open_tickets:int, unread:int}|null
     */
    public static function facts(bool $fresh = false): ?array
    {
        $m = UserAccountService::memberForForms();
        if ($m === null) return null;

        $cached = $_SESSION[self::KEY] ?? null;
        if (!$fresh && is_array($cached) && ($cached['uid'] ?? 0) === $m['id'] && ($cached['at'] ?? 0) > time() - self::TTL) {
            return $cached['facts'];
        }

        $id = (int) $m['id']; $email = (string) $m['email'];
        $f = ['first' => trim((string) (preg_split('/\s+/', trim($m['name']))[0] ?? '')), 'points' => null, 'per_vote' => null,
              'verified' => false, 'votes' => 0, 'nominations' => 0, 'pending' => 0, 'next_event' => null,
              'open_tickets' => 0, 'unread' => 0];
        try { $f['verified'] = (int) DB::table('gates_users')->where('id', $id)->value('email_verified') === 1; } catch (\Throwable) {}
        try {
            if (PointsService::enabled()) { $f['points'] = PointsService::balance($id); $f['per_vote'] = PointsService::pointsPerVote(); }
        } catch (\Throwable) {}
        try { $f['votes'] = count(MemberActivityService::votesFor($email, 100)); } catch (\Throwable) {}
        try {
            $noms = MemberActivityService::nominationsFor($email, 50);
            $f['nominations'] = count($noms);
            $f['pending'] = count(array_filter($noms, static fn ($n) => ($n['status'] ?? '') === 'pending'));
        } catch (\Throwable) {}
        try {
            $now = time();
            foreach (MemberActivityService::ticketsFor($email, 10) as $t) {
                if (($t['state'] ?? '') !== 'confirmed' || strtotime((string) ($t['when'] ?? '')) < $now) continue;
                if ($f['next_event'] === null || strtotime((string) $t['when']) < strtotime($f['next_event']['when'])) {
                    $f['next_event'] = ['event' => (string) $t['event'], 'when' => (string) $t['when']];
                }
            }
        } catch (\Throwable) {}
        try {
            $f['open_tickets'] = (int) DB::table('gates_support_tickets')->where('user_id', $id)
                ->whereNotIn('status', ['resolved', 'closed'])->count();
        } catch (\Throwable) {}
        try {
            $f['unread'] = count(array_filter((new AlertService())->forMember($id, $email), static fn ($a) => !empty($a['unread'])));
        } catch (\Throwable) {}

        $_SESSION[self::KEY] = ['uid' => $id, 'at' => time(), 'facts' => $f];
        return $f;
    }

    /** Forget the memo — after something the starters describe has changed. */
    public static function forget(): void
    {
        unset($_SESSION[self::KEY]);
    }

    /**
     * Four starters: theirs first, the general ones after. Each is a question the person
     * might ask, in their words, because pressing one SENDS it.
     *
     * @return list<array{t:string, d:string}>
     */
    public static function starters(?array $f): array
    {
        $t = static fn (string $s, array $v = []): string => \AfricaGates\Support\Translator::t($s, $v);
        $mine = [];
        if ($f !== null) {
            if ($f['next_event'] !== null) {
                $mine[] = ['t' => $t('Where is my ticket for %event%?', ['%event%' => $f['next_event']['event']]), 'd' => 'M4 5h16v15H4zM4 10h16'];
            }
            if ($f['pending'] > 0) {
                $mine[] = ['t' => $f['pending'] === 1 ? $t('What is happening with my nomination?') : $t('What is happening with my nominations?'),
                           'd' => 'M12 5v14M5 12h14'];
            }
            if ($f['points'] !== null && $f['per_vote'] && $f['points'] >= $f['per_vote']) {
                $mine[] = ['t' => $t('How do I spend my points on a vote?'), 'd' => 'M12 2l2.9 6.3 6.8.7-5.1 4.6 1.5 6.7L12 16.9 5.9 20.3l1.5-6.7L2.3 9l6.8-.7z'];
            }
            if (!$f['verified']) {
                $mine[] = ['t' => $t('How do I verify my email?'), 'd' => 'M3 6h18v12H3zM3 7l9 6 9-6'];
            }
            if ($f['unread'] > 0) {
                $mine[] = ['t' => $t('What’s new on my account?'), 'd' => 'M6 8a6 6 0 0 1 12 0c0 7 3 8 3 8H3s3-1 3-8M10 20a2 2 0 0 0 4 0'];
            }
        }
        $general = [
            ['t' => $t('How do I vote?'), 'd' => 'M4 12.5 9 17.5 20 6.5'],
            ['t' => $t('My payment didn’t go through'), 'd' => 'M3 7h18v10H3zM3 11h18'],
            ['t' => $t('Find tickets for an event'), 'd' => 'M4 5h16v15H4zM4 10h16'],
            ['t' => $t('Nominate someone'), 'd' => 'M12 5v14M5 12h14'],
        ];
        return array_slice(array_merge(array_slice($mine, 0, 3), $general), 0, 4);
    }

    /** The facts as the model is told them. Empty for a guest. */
    public static function promptBlock(?array $f): string
    {
        if ($f === null) return '';
        $lines = [];
        if ($f['first'] !== '') $lines[] = 'First name: ' . $f['first'] . ' (use it now and then, not in every reply).';
        $lines[] = $f['verified'] ? 'Email verified.' : 'Email NOT verified yet — some things (redeeming points, some votes) need it; /account resends the link.';
        if ($f['points'] !== null) {
            $lines[] = 'Points: ' . number_format($f['points']) . ($f['per_vote'] ? ' (' . $f['per_vote'] . ' points buy one vote, so '
                     . intdiv((int) $f['points'], (int) $f['per_vote']) . ' votes\' worth).' : '.');
        }
        $lines[] = 'Votes they have cast: ' . $f['votes'] . '.';
        if ($f['nominations'] > 0) $lines[] = 'Nominations they submitted: ' . $f['nominations'] . ($f['pending'] > 0 ? ', ' . $f['pending'] . ' awaiting a decision.' : '.');
        if ($f['next_event'] !== null) $lines[] = 'Next event they hold a confirmed ticket for: ' . $f['next_event']['event'] . ' on ' . $f['next_event']['when'] . '.';
        if ($f['open_tickets'] > 0) $lines[] = 'Open support tickets: ' . $f['open_tickets'] . ' — if they ask about a problem, check my_tickets before opening another.';
        if ($f['unread'] > 0) $lines[] = 'Unread notifications: ' . $f['unread'] . '.';

        return "\n\nWHO YOU ARE TALKING TO — a signed-in member. Their own account, so use it: answer about THEIR ticket, "
             . "nomination or points without asking which, and never ask them for details you can see here. Never read "
             . "out an email address or phone number.\n- " . implode("\n- ", $lines);
    }
}
