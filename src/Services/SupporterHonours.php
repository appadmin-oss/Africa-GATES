<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Telling supporters that what they did mattered.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Everything else on this platform is built to make sure a supporter is not
 * cheated: the vote is verified, the money is traced, the ledger reconciles, the
 * standings are sealed. All of it defensive, and all of it invisible when it
 * works. Somebody backs a nominee, gets a receipt that reads like a bank
 * statement, and never hears from us again — including on the day the person they
 * backed actually wins.
 *
 * That is a strange thing for a platform about communal recognition to be silent
 * about. The nominee gets a congratulations email. The two hundred people who put
 * them there get nothing, and they are the reason there is anything to celebrate.
 *
 * So: two moments, and only two.
 *
 *   THANKS   Immediately after their votes land, saying what the contribution
 *            actually did — not "payment received", which they already know from
 *            their bank, but where the nominee now stands and who else is behind
 *            them. A receipt confirms a transaction; this confirms a decision.
 *
 *   VICTORY  The day their nominee wins. Addressed to them as a participant in
 *            the result rather than as a customer who was billed.
 *
 * Two is a deliberate ceiling. A platform that emails supporters whenever it has
 * something to say becomes a platform whose emails get filtered, and the victory
 * message is the one that must arrive.
 *
 * ── ONCE, AND ONLY ONCE ──────────────────────────────────────────────────────
 *
 * Both triggers re-run by design. Mints get retried by the reconciler and replayed
 * by webhooks; winner promotion re-enters {@see CycleMaterialiser} every time the
 * scheduler wakes on a cycle already at 'results'. Without a claim, the warmest
 * message this platform sends would arrive four times and read as a fault.
 *
 * The claim is an INSERT into gates_supporter_honours, taken BEFORE the message is
 * composed. The UNIQUE key is the mutex — same doctrine as the cycle transition
 * ledger — so two concurrent runs cannot both conclude they are the sender.
 *
 * ── NOTHING HERE MAY BREAK ANYTHING ──────────────────────────────────────────
 *
 * Every entry point is best-effort and swallows its own failures. A thank-you that
 * cannot be sent must never roll back the mint that earned it, and a celebration
 * that fails must never leave a winner un-promoted. Delivery is RECORDED rather
 * than assumed, so "we wrote to four hundred people" can be checked.
 */
final class SupporterHonours
{
    /** Names on the roll of honour, most supportive first. */
    public const ROLL_LIMIT = 60;

    /**
     * How long a delivery took, in seconds, and what that means for the wording.
     *
     * ── WHY ONE MESSAGE HAS FIVE VOICES ──────────────────────────────────────
     *
     * The same email goes to somebody whose votes landed four seconds after they
     * paid and to somebody whose votes landed eleven days later, after a webhook
     * that was never configured left their order stranded. Sending both of them
     * "your votes are counted!" is wrong in both directions: it is noise to the
     * first and an insult to the second, who has spent a week believing they were
     * robbed and is owed an account of what happened rather than a cheerful
     * notification.
     *
     * So the delay is measured from the moment THEY started the payment — not from
     * the moment our system noticed it, which is the whole thing that went wrong —
     * and the message changes shape around it. Under five minutes it does not
     * mention time at all, because nothing happened worth mentioning.
     *
     * The bands are wide on purpose. "Six hours" and "nine hours" call for the same
     * apology; only the order of magnitude changes what a person needs to hear.
     */
    public const LATE_MINUTES = 5 * 60;        // beyond this, it was late
    public const LATE_HOURS   = 60 * 60;
    public const LATE_DAYS    = 24 * 60 * 60;
    public const LATE_LONG    = 7 * 24 * 60 * 60;

    /** Injectable so tests can watch what would have been sent. */
    private static ?OtpService $mailer = null;

    public static function using(?OtpService $mailer): void { self::$mailer = $mailer; }

    // ─────────────────────────────────────────────────────────────────────────
    // 1 · Thanks — what your contribution did
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Tell one supporter what their contribution did, just after it landed.
     *
     * @return array{ok:bool, code:string}
     */
    public static function thank(int $donationId): array
    {
        $no = static fn (string $c): array => ['ok' => false, 'code' => $c];

        $don = DB::table('gates_donations')->where('id', $donationId)->first();
        if (!$don)                                         return $no('NO_ORDER');
        if ((string) ($don->status ?? '') !== 'confirmed') return $no('NOT_CONFIRMED');
        if (($don->refunded_at ?? null) !== null)          return $no('REFUNDED');

        $email = strtolower(trim((string) ($don->donor_email ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return $no('NO_ADDRESS');

        $nomineeId = (int) ($don->intent_nominee_id ?? 0);
        if ($nomineeId < 1) return $no('NO_NOMINEE');

        $votes = max(0, (int) ($don->votes_used ?? 0));
        if ($votes < 1) return $no('NOTHING_DELIVERED');

        $n = self::nominee($nomineeId);
        if (!$n) return $no('NO_NOMINEE');

        if (!self::claim('thanks', $nomineeId, $email, $donationId)) return $no('ALREADY_THANKED');

        $first  = self::firstName((string) $n->name);
        $people = CommunityReturnService::supporterCount($nomineeId);
        $base   = self::baseUrl();
        $url    = self::nomineeUrl($n);

        $e   = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $nm  = $e(trim((string) ($don->donor_name ?? '')) ?: 'there');
        $vs  = number_format($votes) . ' vote' . ($votes === 1 ? '' : 's');

        // ── HOW LATE WAS IT ──────────────────────────────────────────────────
        //
        // Measured from when THEY paid. For a normal delivery this is silent and
        // the message below is unchanged; for one dug out of the backlog it turns
        // the whole email from an announcement into an account of what happened.
        //
        // Somebody who has spent eleven days believing they were robbed does not
        // need "your votes are counted!" — they need to be told the payment was
        // always good, the fault was ours, and their votes are dated from the
        // moment they paid rather than the moment we caught up.
        $late = self::lateness((string) ($don->created_at ?? ''));
        $isLate = $late['band'] !== 'prompt';

        // The headline says the reassuring thing first when it arrived on time, and
        // the honest thing first when it did not.
        $headline = $isLate
            ? 'Your ' . $vs . ' for ' . $e((string) $n->name) . ' ' . ($votes === 1 ? 'is' : 'are') . ' on the board now.'
            : 'Your ' . $vs . ' for ' . $e((string) $n->name) . ' ' . ($votes === 1 ? 'is' : 'are') . ' counted.';

        $subject = $isLate
            ? 'Delivered: your ' . $vs . ' for ' . $n->name
            : 'Your ' . $vs . ' for ' . $n->name . ' ' . ($votes === 1 ? 'is' : 'are') . ' counted';

        // WHAT IT DID, not what it cost. The amount is on their bank statement and
        // on the receipt; repeating it here would make this a second invoice. What
        // they cannot see anywhere else is the standing and the company they are in.
        $html = "<p>Hi <strong>{$nm}</strong>,</p>"
              . "<p style=\"font-size:17px;font-weight:700;color:#10292C\">{$headline}</p>"
              . ($late['lead'] !== ''
                    ? "<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin:14px 0;background:#fdf6f6;border-left:4px solid #b0453f;border-radius:0 8px 8px 0;padding:14px 18px\">"
                      . "<tr><td style=\"font-size:14px;color:#5c2b28;line-height:1.75\">" . $e($late['lead'])
                      . ($late['note'] !== '' ? "<br><br><strong>" . $e($late['note']) . "</strong>" : '')
                      . "</td></tr></table>"
                    : '')
              . "<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin:14px 0;background:#f0fdf4;border-left:4px solid #22c55e;border-radius:0 8px 8px 0;padding:14px 18px\">"
              . "<tr><td style=\"font-size:14px;color:#166534;line-height:1.8\">"
              . "Category: <strong>" . $e((string) ($n->category ?? '')) . "</strong><br>"
              . "Their tally now: <strong>" . number_format((int) ($n->vote_count ?? 0)) . "</strong><br>"
              . "People behind them: <strong>" . number_format($people) . "</strong>"
              . "</td></tr></table>"
              . "<p>You are one of " . number_format($people) . " " . ($people === 1 ? 'person' : 'people') . " backing {$first} in this cycle. "
              . ((int) ($don->show_name ?? 0) === 1
                    ? "Your name is on their supporters list."
                    : "You gave anonymously, so your name is not published — the votes count exactly the same.")
              . "</p>"
              . "<p style=\"font-size:13.5px;color:#5a6d6f;line-height:1.7\">One thing worth knowing, because we would rather you heard it from us: contributions raise a nominee's <em>public tally</em>, and they are deliberately kept out of the score that decides the winner. That is judged on free verified votes and an independent panel. "
              . "<a href=\"{$base}/integrity#money\" style=\"color:#237b22\">Why we do it that way</a>.</p>"
              . "<p style=\"text-align:center;margin:22px 0\"><a href=\"{$url}\" style=\"display:inline-block;padding:12px 28px;background:#10292C;color:#fff;border-radius:999px;font-weight:600;text-decoration:none;font-size:15px\">See where {$first} stands &rarr;</a></p>";

        $plain = "Hi " . (trim((string) ($don->donor_name ?? '')) ?: 'there') . ",\n\n"
               . html_entity_decode(strip_tags($headline), ENT_QUOTES, 'UTF-8') . "\n\n"
               . ($late['lead'] !== '' ? $late['lead'] . "\n\n" : '')
               . ($late['note'] !== '' ? $late['note'] . "\n\n" : '')
               . "Category: " . (string) ($n->category ?? '') . "\n"
               . "Their tally now: " . number_format((int) ($n->vote_count ?? 0)) . "\n"
               . "People behind them: " . number_format($people) . "\n\n"
               . "Contributions raise a nominee's public tally and are deliberately kept out of the score "
               . "that decides the winner — that is judged on free verified votes and an independent panel. "
               . "{$base}/integrity#money\n\n{$url}\n\n— Africa GATES";

        // ── AND THIS ONE IS NOT SUPPRESSED BY THE OPT-OUT LIST ──────────────────
        //
        // Deliberately, and it is the opposite call from {@see celebrate()}. This message
        // exists because THIS PERSON paid for votes: it is the confirmation that what they
        // bought is on the board, and when it is late it is the apology for that. The
        // unsubscribe page's own wording — "anything you specifically asked for still
        // reaches you" — covers exactly this and not a broadcast about somebody winning.
        //
        // The way out still travels with it, because a reader who thinks otherwise should
        // not have to go looking for one.
        $sent = self::send($email, $subject, $html, $plain, 'Supporters', '',
                           EmailOptOut::url($base, $email));
        self::markDelivered('thanks', $nomineeId, $email, $sent, $late['band']);

        return ['ok' => true, 'code' => $sent ? 'THANKED' : 'THANKED_NOT_SENT', 'band' => $late['band']];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2 · Victory — the day the person they backed wins
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Write to everybody who backed a nominee that just won or placed.
     *
     * Fanning out to every supporter of a winner is the largest send this platform
     * performs, and it happens inside the promotion path — so it is capped, claimed
     * per recipient before composing, and completely silent about its own failures.
     *
     * ── AND IT IS AN ANNOUNCEMENT, SO THE OPT-OUT LIST DECIDES ───────────────
     *
     * This did not consult {@see EmailOptOut} and carried no way out, which made it the
     * only bulk sender in the codebase that did neither — QuestionnaireInvites, StandNotice,
     * NomineeBroadcast and the campaign screen all do both.
     *
     * That is not a style inconsistency. `pages/email-unsubscribe.twig` tells somebody who
     * has just unsubscribed, in as many words, that only what they specifically asked for
     * still reaches them: a receipt, a sign-in code, a support reply. "Someone you backed
     * won" is none of those, it is the single largest send here, and it would have arrived
     * from a platform that had promised in writing it would not.
     *
     * @param  string $kind 'winner' | 'runner_up'
     * @return array{ok:bool, code:string, sent:int, skipped:int, unsubscribed:int}
     */
    public static function celebrate(int $nomineeId, string $kind = 'winner', int $limit = 2000): array
    {
        $out = ['ok' => false, 'code' => 'NO_NOMINEE', 'sent' => 0, 'skipped' => 0, 'unsubscribed' => 0];

        $n = self::nominee($nomineeId);
        if (!$n) return $out;

        $recipients = self::reachableSupporters($nomineeId, $limit);
        if ($recipients === []) {
            return ['ok' => true, 'code' => 'NOBODY_REACHABLE', 'sent' => 0, 'skipped' => 0, 'unsubscribed' => 0];
        }

        // Read once, not once per recipient — this loop is up to $limit rows long.
        // Failing OPEN would mail everybody who ever opted out, so a missing table or a
        // broken read has to stop the fan-out rather than proceed without the list.
        try {
            $suppressed = EmailOptOut::suppressedHashes();
        } catch (\Throwable) {
            return ['ok' => false, 'code' => 'OPTOUT_UNREADABLE', 'sent' => 0, 'skipped' => 0, 'unsubscribed' => 0];
        }

        $first   = self::firstName((string) $n->name);
        $base    = self::baseUrl();
        $roll    = self::rollOfHonour($nomineeId);
        $crowd   = CommunityReturnService::supporterCount($nomineeId);
        $won     = $kind === 'winner';
        $verb    = $won ? 'won' : 'placed as runner-up in';
        $subject = $n->name . ($won ? ' won. You helped.' : ' placed. You helped.');

        $sent = 0; $skipped = 0; $optedOut = 0;
        foreach ($recipients as $email => $name) {
            // Before the claim, so an address that later resubscribes is still reachable:
            // claiming first would burn the one-send-per-supporter mutex on a message that
            // was never composed.
            if (isset($suppressed[EmailOptOut::hash($email)])) { $optedOut++; continue; }
            if (!self::claim('victory', $nomineeId, $email, null)) { $skipped++; continue; }

            $e  = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
            $nm = $e(trim($name) ?: 'there');

            // THE POINT OF THE WHOLE MESSAGE is the second person. Not "a nominee you
            // supported has won" — which is a notification about somebody else — but
            // "you are part of why". The roll of honour underneath makes that
            // concrete: their own name is on it.
            $html = "<p>Hi <strong>{$nm}</strong>,</p>"
                  . "<p style=\"font-size:19px;font-weight:700;color:#10292C;line-height:1.35\">"
                  . $e((string) $n->name) . " {$verb} " . $e((string) ($n->category ?? 'their category')) . ".</p>"
                  . "<p style=\"font-size:15px;line-height:1.75\">You were one of <strong>" . number_format($crowd) . "</strong> "
                  . ($crowd === 1 ? 'person' : 'people') . " who backed {$first} this cycle. "
                  . "That is not a footnote to the result — it <em>is</em> the result. The community half of "
                  . "the score is the part you decided.</p>"
                  . "<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"margin:16px 0;background:#fffbeb;border-left:4px solid #c9a24b;border-radius:0 8px 8px 0;padding:14px 18px\">"
                  . "<tr><td style=\"font-size:14px;color:#5b4a1f;line-height:1.75\">"
                  . "<strong>Roll of honour</strong><br>"
                  . ($roll === []
                        ? 'Every supporter who asked to be named is listed on their page.'
                        : $e(implode(' · ', array_slice(array_column($roll, 'name'), 0, 12)))
                          . (count($roll) > 12 ? ' · and more' : ''))
                  . "</td></tr></table>"
                  . "<p style=\"text-align:center;margin:24px 0\"><a href=\"" . self::nomineeUrl($n)
                  . "\" style=\"display:inline-block;padding:13px 30px;background:#10292C;color:#fff;border-radius:999px;font-weight:600;text-decoration:none;font-size:15px\">See the roll of honour &rarr;</a></p>"
                  . "<p style=\"font-size:13px;color:#5a6d6f;text-align:center\">Thank you for showing up for somebody.</p>";

            $plain = "Hi " . (trim($name) ?: 'there') . ",\n\n"
                   . "{$n->name} {$verb} " . (string) ($n->category ?? 'their category') . ".\n\n"
                   . "You were one of " . number_format($crowd) . " people who backed {$first} this cycle. "
                   . "That is not a footnote to the result — it is the result.\n\n"
                   . self::nomineeUrl($n) . "\n\n"
                   . "Thank you for showing up for somebody.\n\n— Africa GATES\n\n"
                   // The plain part is a separate body, so the footer link the brand
                   // wrapper adds to the HTML is not in it. A reader on a text-only client
                   // gets the same way out or none at all.
                   . "No more announcement emails: " . EmailOptOut::url($base, $email);

            $ok = self::send($email, $subject, $html, $plain, 'Results',
                             $base . '/assets/img/illustrations/illo-trophy.jpg',
                             EmailOptOut::url($base, $email));
            self::markDelivered('victory', $nomineeId, $email, $ok);
            if ($ok) $sent++;
        }

        return ['ok' => true, 'code' => 'CELEBRATED', 'sent' => $sent, 'skipped' => $skipped,
                'unsubscribed' => $optedOut];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The roll of honour
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The named supporters of a nominee, for the celebration surface.
     *
     * Deliberately {@see SupportersService::forNominee()} rather than a second
     * query: the consent rule ("only people who ticked the box, never the
     * placeholder name") is subtle enough that a second implementation of it would
     * eventually publish somebody who did not agree to be published. One reader,
     * one rule.
     *
     * @return list<array{name:string, votes:int, paid:bool, when:string}>
     */
    public static function rollOfHonour(int $nomineeId, int $limit = self::ROLL_LIMIT): array
    {
        try { return SupportersService::forNominee($nomineeId, $limit); }
        catch (\Throwable) { return []; }
    }

    /**
     * Who can actually be written to, and under what name.
     *
     * Only CONTRIBUTORS are reachable: a free vote is verified against an email but
     * the platform stores only its hash, deliberately, so there is no address to
     * write to and that is the correct trade. Contributors gave an address to a
     * checkout in order to be contacted about the order.
     *
     * @return array<string,string> email => best known name
     */
    private static function reachableSupporters(int $nomineeId, int $limit): array
    {
        $out = [];
        try {
            $rows = DB::table('gates_donations')
                ->where('intent_nominee_id', $nomineeId)
                ->where('status', 'confirmed')
                ->whereNull('refunded_at')
                ->whereNotNull('donor_email')
                ->where('votes_used', '>', 0)
                ->orderByDesc('id')
                ->limit(max(1, $limit) * 2)
                ->get(['donor_email', 'donor_name']);
        } catch (\Throwable) {
            return [];
        }

        foreach ($rows as $r) {
            $email = strtolower(trim((string) $r->donor_email));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            if (count($out) >= $limit && !isset($out[$email])) continue;

            $name = trim((string) ($r->donor_name ?? ''));
            if (strcasecmp($name, 'Supporter') === 0) $name = '';
            // Newest order first, so the first non-empty name we meet is the most
            // recent one they gave us.
            if (!isset($out[$email]) || ($out[$email] === '' && $name !== '')) {
                $out[$email] = $name;
            }
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Plumbing
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Take the right to send, or discover somebody already has.
     *
     * The INSERT is the claim. Returning false on a duplicate-key violation is the
     * whole mechanism — checking first and inserting after would leave a window two
     * concurrent runs both walk through.
     */
    private static function claim(string $kind, int $nomineeId, string $email, ?int $donationId): bool
    {
        try {
            DB::table('gates_supporter_honours')->insert([
                'kind'           => $kind,
                'nominee_id'     => $nomineeId,
                'recipient_hash' => hash('sha256', strtolower(trim($email))),
                'donation_id'    => $donationId,
                'delivered'      => 0,
                'created_at'     => Carbon::now()->toDateTimeString(),
            ]);
            return true;
        } catch (\Throwable) {
            return false;   // already claimed, or the table is not there yet
        }
    }

    /**
     * Record whether it actually left, so the count can be checked rather than
     * believed — and, for a thank-you, WHICH VOICE it was sent in.
     *
     * The band is worth keeping: after a backlog sweep, "how many people did we
     * apologise to, and how badly" is the question somebody will ask, and without
     * it the only honest answer is a shrug.
     */
    private static function markDelivered(string $kind, int $nomineeId, string $email, bool $ok,
                                          string $band = ''): void
    {
        try {
            DB::table('gates_supporter_honours')
                ->where('kind', $kind)->where('nominee_id', $nomineeId)
                ->where('recipient_hash', hash('sha256', strtolower(trim($email))))
                ->update(['delivered' => $ok ? 1 : 0] + ($band !== '' ? ['detail' => 'delay:' . $band] : []));
        } catch (\Throwable) {}
    }

    private static function send(string $to, string $subject, string $html, string $plain,
                                 string $category, string $hero = '', string $unsubscribeUrl = ''): bool
    {
        try {
            $m = self::$mailer ?? self::defaultMailer();
            $r = $m->sendBranded($to, $subject, $html, $plain, $category, $hero, $unsubscribeUrl);
            return (bool) ($r['success'] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * How late this delivery was, and what to say about it.
     *
     * Measured from the moment the supporter STARTED the payment. That choice is
     * the whole point: an order stranded by a missing webhook was paid on time and
     * noticed eleven days later, and dating the delay from when our system caught
     * up would report a delay of zero on the exact case that most needs an
     * apology.
     *
     * @return array{band:string, secs:int, human:string, lead:string, note:string}
     */
    public static function lateness(?string $startedAt, ?string $deliveredAt = null): array
    {
        $start = $startedAt !== null ? (int) strtotime($startedAt) : 0;
        $end   = $deliveredAt !== null ? (int) strtotime($deliveredAt) : Carbon::now()->getTimestamp();
        $secs  = ($start > 0 && $end > $start) ? $end - $start : 0;

        $human = self::humanDuration($secs);

        // Under five minutes nothing happened worth naming, and saying "your votes
        // arrived in 4 minutes" invents a problem in the reader's mind.
        if ($secs < self::LATE_MINUTES) {
            return ['band' => 'prompt', 'secs' => $secs, 'human' => $human, 'lead' => '', 'note' => ''];
        }

        if ($secs < self::LATE_HOURS) {
            return ['band' => 'slow', 'secs' => $secs, 'human' => $human,
                'lead' => "That took {$human} longer than it should have — your bank's confirmation "
                        . 'reached us slowly. Everything is where it should be now.',
                'note' => ''];
        }

        if ($secs < self::LATE_DAYS) {
            return ['band' => 'hours', 'secs' => $secs, 'human' => $human,
                'lead' => "We are sorry this took {$human}. Your payment went through immediately; the "
                        . 'confirmation of it did not reach us, so your votes sat waiting on our side '
                        . 'rather than yours.',
                'note' => 'Your votes are dated from when you paid, not from when we noticed. Nothing was lost.'];
        }

        if ($secs < self::LATE_LONG) {
            return ['band' => 'days', 'secs' => $secs, 'human' => $human,
                'lead' => "This should not have taken {$human}, and we are sorry. Your payment succeeded at "
                        . 'the time you made it. The message that tells us so never arrived, so your order '
                        . 'sat as unconfirmed on our side while your money had already left yours.',
                'note' => 'We have fixed the cause rather than only this order. Your votes are dated from '
                        . 'when you paid, so they count exactly as if they had appeared straight away.'];
        }

        return ['band' => 'long', 'secs' => $secs, 'human' => $human,
            'lead' => "You paid {$human} ago and we are only delivering now. That is our failure, not your "
                    . "bank's and not yours, and we are sorry — particularly if you wrote to us about it and "
                    . 'were told to wait.',
            'note' => 'The cause was on our side: payment confirmations were not reaching us, so orders that '
                    . 'had really been paid looked unpaid. It is fixed. Your votes are dated from the moment '
                    . 'you paid, so they count for the cycle you bought them in.'];
    }

    /** "3 minutes", "6 hours", "11 days" — an order of magnitude, not a stopwatch. */
    private static function humanDuration(int $secs): string
    {
        if ($secs < 90)      return 'a moment';
        if ($secs < 3600)    { $m = (int) round($secs / 60);    return $m . ' minute' . ($m === 1 ? '' : 's'); }
        if ($secs < 86400)   { $h = (int) round($secs / 3600);  return $h . ' hour'   . ($h === 1 ? '' : 's'); }
        $d = (int) round($secs / 86400);
        if ($d < 14)         return $d . ' day' . ($d === 1 ? '' : 's');
        $w = (int) round($d / 7);
        return $w . ' week' . ($w === 1 ? '' : 's');
    }

    /** No DI in the console/cron context this runs from — same pattern as CycleAnnouncer. */
    private static function defaultMailer(): OtpService
    {
        return new OtpService([
            'host'         => Env::get('SMTP_HOST', 'smtp-relay.brevo.com'),
            'port'         => Env::int('SMTP_PORT', 587),
            'username'     => Env::get('SMTP_USER', ''),
            'password'     => Env::get('SMTP_PASS', ''),
            'from_address' => Env::get('MAIL_FROM_ADDRESS', 'noreply@afrovanguard.org.ng'),
            'from_name'    => Env::get('MAIL_FROM_NAME', 'Africa GATES'),
        ]);
    }

    private static function nominee(int $nomineeId): ?object
    {
        try {
            return DB::table('gates_nominees as n')
                ->leftJoin('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->leftJoin('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
                ->leftJoin('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
                ->where('n.id', $nomineeId)
                ->select(['n.id', 'n.name', 'n.vote_count', 'n.status',
                          'c.title as category', 'p.slug as programme_slug'])
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The canonical nominee link: /vote/{programme}/{id}-{name}.
     *
     * Built with {@see \AfricaGates\Support\Slug::idSegment()} rather than an inline
     * expression, because the five hand-rolled copies of that regex in this codebase
     * DELETED accented letters instead of folding them — "Ọlásùnkànmí Adébáyọ̀" came
     * out as "l-s-nk-nm-ad-b-y". The link still resolved, since the id leads the
     * segment, which is exactly why nobody caught it. A congratulations email is the
     * last place a nominee's name should arrive looking like corruption.
     *
     * Falls back to the legacy /vote/{id} form, which redirects, when the programme
     * join found nothing.
     */
    private static function nomineeUrl(object $n): string
    {
        $seg  = \AfricaGates\Support\Slug::idSegment((int) $n->id, (string) $n->name);
        $prog = trim((string) ($n->programme_slug ?? ''));
        return self::baseUrl() . ($prog !== '' ? '/vote/' . rawurlencode($prog) . '/' . $seg : '/vote/' . (int) $n->id);
    }

    private static function baseUrl(): string
    {
        return rtrim((string) Env::get('APP_URL', 'https://afg.afrovanguard.org.ng'), '/');
    }

    private static function firstName(string $full): string
    {
        $parts = preg_split('/\s+/', trim($full)) ?: [];
        return htmlspecialchars((string) ($parts[0] ?? $full), ENT_QUOTES, 'UTF-8');
    }
}
