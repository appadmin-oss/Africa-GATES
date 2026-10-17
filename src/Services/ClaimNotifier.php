<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Phone;
use AfricaGates\Support\Reference;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Telling the nominee that somebody has claimed their page — on every channel we hold.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE CONTROL THAT MATTERS MORE THAN THE GATE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * docs/CLAIM-FAIRNESS-AND-FRAUD.md §5. The honest answer about attacker 3 — the
 * bandmate, the ex-manager, the relative who knows the address and may hold the photos
 * — is that no remote check reliably stops somebody who knows their victim. Every gate
 * that would stop them also stops a large share of genuine nominees, because the two
 * look identical from the outside.
 *
 * What stops them is that the theft is LOUD:
 *
 *   A thief who claims through an email they control still sets the victim's PHONE
 *   ringing. "Someone has claimed your Africa GATES page. If this was not you, tell
 *   us." Nothing to pay, no account needed.
 *
 * ── WHY THIS IGNORES THE INDEPENDENCE VERDICT ────────────────────────────────
 *
 * {@see ClaimIndependence} sorts channels so a claimant is offered the ones that will
 * pass. This class does the opposite: it uses {@see ClaimIndependence::contactsFor()},
 * which applies no filter at all, because the channels worth telling are precisely the
 * ones the claimant did NOT use and could not control.
 *
 * Filtering here would go silent in exactly the case §1 is about. The nominator who
 * typed their own address claims through it; if the fan-out only used "independent"
 * channels it would skip that address — and the victim's other number, the one that
 * needed to ring, is often on the same row.
 *
 * ── AND WHY A FAN-OUT THAT REACHED NOBODY IS A HOLD ──────────────────────────
 *
 * §10 refuses to build silent claiming. So this reports `reached` honestly and
 * {@see NomineeClaimService} holds the claim when it is zero, rather than activating a
 * page nobody was told about. The commonest cause is not an attack — it is a nomination
 * with one contact channel and unconfigured SMS — which is exactly why the outcome is a
 * hold for a human and never a refusal.
 *
 * ── WHAT IS STORED ───────────────────────────────────────────────────────────
 *
 * Masked destinations only, the same rule as `gates_nominee_claims.channel_hint`. A
 * table recording "we messaged +234••••••789" is useful to a support agent; one
 * recording the whole number turns this audit trail into a contact list for every
 * nominee who was ever claimed.
 */
final class ClaimNotifier
{
    /**
     * Cooling-off before any money moves, quoted in the message. §3.
     *
     * Read from {@see ClaimGuard} rather than declared here. It used to be a private
     * constant on this class, which meant the sentence in the email was the ONLY thing
     * in the codebase that knew about seven days — nothing enforced it. It is now the
     * same number the payout bar refuses on, so the promise and the behaviour cannot
     * drift apart.
     */
    private const COOLING_OFF_DAYS = ClaimGuard::COOLING_OFF_DAYS;

    /**
     * Tell every channel on file. Idempotent per claim, never throws.
     *
     * @return array{reached:int, attempted:int, channels:list<array{channel:string,hint:string,status:string}>}
     */
    public static function fanOut(int $claimId, ?OtpService $mailer = null, ?SmsService $sms = null): array
    {
        $none = ['reached' => 0, 'attempted' => 0, 'channels' => []];
        if ($claimId < 1) return $none;

        try {
            $claim = DB::table('gates_nominee_claims')->where('id', $claimId)->first();
        } catch (\Throwable $e) {
            error_log('[claim] fan-out could not read claim ' . $claimId . ': ' . $e->getMessage());
            return $none;
        }
        if (!$claim) return $none;

        // ALREADY TOLD: return what happened, do not send again.
        //
        // A retried POST, a double-submitted form or a resumed request must not send a
        // second round of "someone has claimed your page" — to a nominee those read as
        // two different attacks. The stored summary is the answer, and it is the same
        // shape as a fresh one so the caller cannot tell the difference.
        $already = self::storedSummary($claim);
        if ($already !== null) return $already;

        $nomineeId = (int) ($claim->nominee_id ?? 0);
        $name      = self::nomineeName($nomineeId);
        $reference = self::reference($claimId, $claim);
        $usedHint  = trim((string) ($claim->channel_hint ?? ''));

        // ── THE ONE-TAP WAY TO STOP IT ───────────────────────────────────────
        //
        // Before this, the only lever a wrongly-claimed nominee had was "reply to this
        // email" — while somebody else already held their page. The link freezes the
        // claim without an account and without waiting for a human to read support mail.
        //
        // Absolute, because it is going into an email. SiteUrl::base() needs a request
        // and there is none here (this runs from a controller, a console command and a
        // queue worker), so APP_URL is the only source that is right in all three.
        $base = rtrim((string) \AfricaGates\Support\Env::get('APP_URL', ''), '/');
        $disputeUrl = $base !== '' ? ClaimDispute::url($claimId, $base) : null;

        $results = [];
        foreach (ClaimIndependence::contactsFor($nomineeId) as $c) {
            $results[] = $c['channel'] === 'email'
                ? self::tellByEmail($c, $name, $reference, $usedHint, $mailer, $disputeUrl)
                : self::tellByPhone($c, $name, $reference, $sms, $disputeUrl);
        }

        $reached = count(array_filter($results, static fn(array $r) => $r['status'] === 'sent'));
        $summary = ['reached' => $reached, 'attempted' => count($results), 'channels' => $results];

        // LOUD when it reached nobody. This is the condition §5 is built to prevent, and
        // it is invisible from the outside: the claim looks fine, the page changes hands,
        // and the person it belongs to hears nothing. The claim service holds on it too,
        // but the log line is what tells an operator their SMS credentials are missing.
        if ($reached === 0) {
            error_log('[claim] fan-out for ' . $reference . ' reached NOBODY on '
                    . count($results) . ' channel(s) — check SMTP and SMS configuration.');
        }

        self::record($claimId, $reference, $summary);
        return $summary;
    }

    /**
     * The claim's reference, persisted on first use.
     *
     * Derived from the id, so it is stable and checksummable offline; written to the row,
     * so a support agent can find the claim with an indexed lookup rather than by
     * decoding. See {@see Reference::claim()}.
     */
    public static function reference(int $claimId, ?object $claim = null): string
    {
        $stored = trim((string) ($claim->reference ?? ''));
        if ($stored !== '') return $stored;

        try {
            $ref = Reference::claim($claimId);
        } catch (\Throwable) {
            return 'AGC-' . $claimId;   // absurd id; still quotable to a human
        }

        try {
            DB::table('gates_nominee_claims')->where('id', $claimId)
                ->whereNull('reference')->update(['reference' => $ref]);
        } catch (\Throwable) {
            // The column may predate 2026_08_14_claim_notifications. The reference is
            // derived, so it is correct with or without being written down.
        }
        return $ref;
    }

    // ── the channels ────────────────────────────────────────────────────────

    /**
     * @param array{channel:string,hint:string,value:string,country:string} $c
     * @return array{channel:string,hint:string,status:string}
     */
    private static function tellByEmail(array $c, string $name, string $ref, string $usedHint,
                                       ?OtpService $mailer, ?string $disputeUrl = null): array
    {
        if ($mailer === null) {
            return ['channel' => 'email', 'hint' => $c['hint'], 'status' => 'unconfigured'];
        }

        $support = Notifier::supportEmail();
        $used    = $usedHint !== '' ? $usedHint : 'a contact channel on your nomination';
        $days    = self::COOLING_OFF_DAYS;

        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeUsed = htmlspecialchars($used, ENT_QUOTES, 'UTF-8');
        $safeRef  = htmlspecialchars($ref, ENT_QUOTES, 'UTF-8');
        $safeSup  = htmlspecialchars($support, ENT_QUOTES, 'UTF-8');

        // A BUTTON, not a bare link, and it lands on a page with a confirm step rather
        // than acting on the GET. Corporate mail systems and every link-safety scanner
        // fetch the URLs in a message before a human sees it — a freeze on GET would be
        // tripped automatically on a large share of honest claims, and the cause would
        // be invisible because the request looks like an ordinary visitor.
        $stopBlock = $disputeUrl !== null
            ? '<br><br><a href="' . htmlspecialchars($disputeUrl, ENT_QUOTES, 'UTF-8')
              . '" style="display:inline-block;background:#b45309;color:#fff;font-weight:700;'
              . 'font-size:14px;padding:11px 22px;border-radius:999px;text-decoration:none">'
              . 'This was not me — freeze this claim</a>'
            : '';

        $html = <<<HTML
<p style="margin:0 0 14px;font-size:15px;color:#374151">Someone has just claimed the Africa GATES page for <strong>{$safeName}</strong>.</p>
<p style="margin:0 0 14px;font-size:15px;color:#374151">They confirmed a code sent to <strong>{$safeUsed}</strong>. We are telling every contact on the nomination, including this one, so that you hear about it from us either way.</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0;background:#f0fdf4;border-left:4px solid #22c55e;border-radius:0 8px 8px 0;padding:14px 18px">
  <tr><td style="font-size:14px;color:#166534;line-height:1.7">
    <strong>If this was you</strong> — nothing to do. The page is yours.
  </td></tr>
</table>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0;background:#fffbeb;border-left:4px solid #f59e0b;border-radius:0 8px 8px 0;padding:14px 18px">
  <tr><td style="font-size:14px;color:#92400e;line-height:1.7">
    <strong>If this was not you</strong> — stop it now:{$stopBlock}
    <br>Or reply to this email, or write to
    <a href="mailto:{$safeSup}" style="color:#b45309">{$safeSup}</a>, quoting
    <strong>{$safeRef}</strong>. There is nothing to pay, either way.
  </td></tr>
</table>
<p style="margin:0 0 10px;font-size:14px;color:#6b7280">Two things worth knowing, whoever it was:</p>
<ul style="margin:0 0 14px;padding-left:20px;font-size:14px;color:#6b7280;line-height:1.7">
  <li>No money moves on a claim less than <strong>{$days} days</strong> old.</li>
  <li>Any payment can only ever go to a bank account in the nominee's own name — never a manager's, never anyone else's.</li>
</ul>
<p style="margin:0;font-size:13px;color:#9ca3af">Claim reference: {$safeRef}</p>
HTML;

        // ══ "IF THIS WAS NOT YOU" IS NOT OPTIONAL ═══════════════════════════════
        //
        // This block was conditional on `$disputeUrl`, and the whole sentence went with it.
        // Without a freeze link the plain text read:
        //
        //     If this was you, there is nothing to do.
        //     You can also reply to this email or write to support quoting REF.
        //
        // — which never names the case it is for, and whose "also" refers to an option that
        // was never offered. The one instruction this email exists to deliver, missing.
        //
        // Three things make that worse than a wording slip:
        //
        //   IT DISAGREED WITH ITSELF.   The HTML half of the SAME message always said "If this
        //                               was not you — stop it now". Which half a recipient sees
        //                               is decided by their mail client, so the security
        //                               instruction was present or absent at random.
        //   IT FAILED OPEN.             `$disputeUrl` is null whenever APP_URL is unset — see
        //                               the note above, this runs from a console command and a
        //                               queue worker as well as a controller. So the sentence
        //                               went missing on the automated paths first.
        //   IT DROPPED OUT WHEN NEEDED MOST. No freeze link means the recipient has FEWER ways
        //                               to act, not fewer reasons to.
        //
        // The email route always exists and needs no configuration, so it is the floor. The
        // one-tap freeze is the addition when a URL can be built — and "also" now appears only
        // where something came before it.
        $stopBlockPlain = $disputeUrl !== null
            ? "If this was NOT you, stop it now:\n{$disputeUrl}\n\n"
              . "You can also reply to this email or write to {$support} quoting {$ref}. "
            : "If this was NOT you, reply to this email or write to {$support} quoting {$ref}. ";

        $plain = "Someone has just claimed the Africa GATES page for {$name}.\n\n"
               . "They confirmed a code sent to {$used}. We are telling every contact on the "
               . "nomination, including this one.\n\n"
               . "If this was you, there is nothing to do.\n\n"
               . $stopBlockPlain
               . "We will stop it while a person looks. There is nothing to pay.\n\n"
               . "No money moves on a claim less than {$days} days old, and any payment can only "
               . "go to a bank account in the nominee's own name.\n\n"
               . "Claim reference: {$ref}\n";

        try {
            $sent = $mailer->sendBranded($c['value'], 'Someone has claimed your Africa GATES page',
                                         $html, $plain, 'Security');
            $ok = (bool) ($sent['success'] ?? false);
        } catch (\Throwable $e) {
            error_log('[claim] fan-out email failed for ' . $ref . ': ' . $e->getMessage());
            $ok = false;
        }

        return ['channel' => 'email', 'hint' => $c['hint'], 'status' => $ok ? 'sent' : 'failed'];
    }

    /**
     * SMS and WhatsApp, both when both are configured.
     *
     * One phone is ONE channel for the purposes of `reached`, whichever transport
     * carried it: the question this count answers is "did a person get told", and a
     * nominee who received the same sentence twice on one handset was told once.
     *
     * @param array{channel:string,hint:string,value:string,country:string} $c
     * @return array{channel:string,hint:string,status:string}
     */
    private static function tellByPhone(array $c, string $name, string $ref, ?SmsService $sms,
                                       ?string $disputeUrl = null): array
    {
        $hint = $c['hint'];
        if ($sms === null || !$sms->configured()) {
            return ['channel' => 'phone', 'hint' => $hint, 'status' => 'unconfigured'];
        }

        // Stored as it was typed (08031234567); every provider wants E.164. The country
        // travels with the number from the nomination rather than being assumed.
        $e164 = Phone::normalize($c['value'], $c['country']);
        if ($e164 === null) {
            return ['channel' => 'phone', 'hint' => $hint, 'status' => 'failed'];
        }

        $support = Notifier::supportEmail();
        // The LINK first when we have one. On a phone, tapping a link is the whole
        // action; composing an email quoting a reference is a different afternoon. The
        // email address stays as the fallback, and the reference stays because somebody
        // reading this on a feature phone still needs to be able to quote it.
        // The REFERENCE is in the message either way. A link is the easier action on a
        // smartphone, and a large part of this audience is reading on a handset where
        // tapping a URL is not an option at all — for them the only route is quoting the
        // reference to a person, so dropping it in favour of the link would have taken
        // the one thing they could use.
        $stop = $disputeUrl !== null
            ? "If this was not you, stop it here: {$disputeUrl}"
            : "If this was not you, email {$support} and we will stop it.";
        $body = "Africa GATES: someone has claimed the page for {$name}. {$stop} Ref {$ref}. "
              . "Nothing to pay. No money moves for " . self::COOLING_OFF_DAYS . " days, and only "
              . "to the nominee's own account.";

        $ok = false;
        // respectOptOut FALSE: somebody is claiming this person's page and this is the
        // warning. A security notice suppressed by a marketing preference is how the one
        // message that mattered is the one that never arrived.
        try { $ok = $sms->sendSms($e164, $body, 'claim-alert', respectOptOut: false); } catch (\Throwable) {}
        try {
            if ($sms->whatsappConfigured() && $sms->sendWhatsApp($e164, $body, 'claim-alert', respectOptOut: false)) $ok = true;
        } catch (\Throwable) {}

        return ['channel' => 'phone', 'hint' => $hint, 'status' => $ok ? 'sent' : 'failed'];
    }

    // ── persistence ─────────────────────────────────────────────────────────

    /** @param array{reached:int,attempted:int,channels:list<array<string,string>>} $summary */
    private static function record(int $claimId, string $reference, array $summary): void
    {
        try {
            DB::table('gates_nominee_claims')->where('id', $claimId)->update([
                'notified_at' => date('Y-m-d H:i:s'),
                'notified'    => json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            // Recording must never fail a fan-out that has already happened — the
            // messages are gone regardless, and the log lines above are the backstop.
            error_log('[claim] could not record fan-out for ' . $reference . ': ' . $e->getMessage());
        }
    }

    /**
     * The stored outcome of an earlier fan-out, or null if there was none.
     *
     * `notified_at` is the authority, not `notified`: a row whose JSON failed to write
     * has still had its messages sent, and re-sending them is the one outcome worse than
     * an incomplete audit trail.
     *
     * @return array{reached:int,attempted:int,channels:list<array{channel:string,hint:string,status:string}>}|null
     */
    private static function storedSummary(object $claim): ?array
    {
        if (trim((string) ($claim->notified_at ?? '')) === '') return null;

        $decoded = json_decode((string) ($claim->notified ?? ''), true);
        if (!is_array($decoded) || !isset($decoded['reached'])) {
            return ['reached' => 0, 'attempted' => 0, 'channels' => []];
        }

        return [
            'reached'   => (int) $decoded['reached'],
            'attempted' => (int) ($decoded['attempted'] ?? 0),
            'channels'  => is_array($decoded['channels'] ?? null) ? $decoded['channels'] : [],
        ];
    }

    /** The nominee's name for the message, or a neutral stand-in. */
    private static function nomineeName(int $nomineeId): string
    {
        try {
            $n = trim((string) DB::table('gates_nominees')->where('id', $nomineeId)->value('name'));
            if ($n !== '') return $n;
        } catch (\Throwable) {}
        return 'your nominee page';
    }
}
