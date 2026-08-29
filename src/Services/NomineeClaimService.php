<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Phone;
use AfricaGates\Support\Reference;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Claiming a nominee page by one-time code — where *held* is an outcome, not an error.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE THREE OUTCOMES, AND WHY THERE ARE THREE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * docs/CLAIM-FAIRNESS-AND-FRAUD.md §2. A confirmed code produces one of:
 *
 *   ACTIVE — the code landed on a channel independent of every nominator, and the
 *            nominee was told on every channel we hold. Ninety seconds, no document.
 *   HELD   — something needs a person. NOT a refusal, and the whole design turns on
 *            that distinction.
 *   (and a wrong code, which is neither: it consumes nothing and can be retried.)
 *
 * ── WHY HELD IS THE INTERESTING ONE ──────────────────────────────────────────
 *
 * The commonest reason a nominee's contact matches their nominator's is that a
 * customer, a daughter or a church secretary filled the form in for them and used the
 * only address they had — their own. That person is the MOST likely to be the real
 * nominee, not the least. Baba Sule in §6 is exactly this: his customer nominated him
 * and typed her own email, so the email fails independence and his own number, on the
 * same row, clears it.
 *
 * So every path that cannot say yes says "we need one more thing", names the likely
 * innocent explanation, states that there is nothing to pay, and opens a ticket a
 * person can answer WITHOUT AN ACCOUNT (§7.3, via {@see TicketLinkService}). A check
 * that catches every attacker and half the genuine nominees is not a security control;
 * it is exclusion with a security justification.
 *
 * ── WHAT THE CLAIMANT IS NEVER ALLOWED TO CHOOSE ─────────────────────────────
 *
 * The destination. `start()` takes an opaque channel KEY, never an address, and the
 * key is only honoured if it resolves to a contact already on an approved nomination.
 * An endpoint that accepted "send my claim code to X" would let anybody claim anybody:
 * the code would prove only that the claimant can read their own inbox, which is the
 * failure §1 is about, reintroduced one layer up.
 *
 * The key is a hash of the channel value and needs no secret. It is a SELECTOR, not an
 * authenticator — knowing it lets you ask for a code to be sent to an address you
 * already knew, and receiving that code is the part you cannot fake.
 *
 * ── AND WHAT ONLY THE DATABASE CAN GUARANTEE ─────────────────────────────────
 *
 * "Two people own this page" is prevented by the UNIQUE index on
 * `active_nominee_id`, not by a check in this file. Two simultaneous confirmations
 * both pass every guard here; the second one loses on the insert and is HELD for a
 * person, which is what §10 requires of a second claim on an active page.
 *
 * ── A NOTE ON $deviceFp AND $ipHash ──────────────────────────────────────────
 *
 * Both are the STORED forms, not raw values: `gates_nominations` holds
 * `sha256(device fingerprint)` and `sha256(ip)`, so the caller hashes before calling
 * and the comparison is column against column. Passing a raw fingerprint here would
 * silently never match, which is the quiet-failure shape of a security control — it
 * looks configured, it runs, and it catches nobody.
 */
final class NomineeClaimService
{
    /** How long a claim code lives. Matches the voting OTP — one habit, not two. */
    public const CODE_TTL_MINUTES = 10;

    /**
     * Wrong guesses per code before the code itself dies — a BACKSTOP, not the control.
     *
     * ── WHY THIS IS NOT 5 ────────────────────────────────────────────────────
     *
     * It was, and that made a victim's code something a stranger could destroy. `claim_id`
     * is an auto-increment integer handed back by start(), so it is guessable by counting;
     * confirm() takes no session and is open to guests. Six wrong guesses against somebody
     * else's claim id used to mark their token used, and they then had to ask for another
     * — against a limit of {@see CODES_PER_HOUR} an hour and {@see CLAIMS_PER_DAY} a day
     * for the whole page. Roughly thirty requests could therefore lock a real nominee out
     * of claiming their own page until tomorrow, from anywhere, with no account.
     *
     * The budget that stops brute force now lives PER CLIENT (below), so one attacker
     * cannot spend another person's. This remains as the last line, and is still nowhere
     * near walkable: a 6-digit code lives ten minutes in a space of a million, so sixty
     * guesses is a 0.006% chance. Small enough to ignore, large enough not to be a weapon.
     */
    private const MAX_TOKEN_ATTEMPTS = 60;

    /**
     * Wrong guesses one CLIENT may make against one claim, and across all claims.
     *
     * Two dimensions for the same reason start() has two: the per-claim budget is the one
     * a real person mistyping their own code will feel (five tries is generous), and the
     * per-client one is what makes sweeping claim ids expensive rather than free.
     */
    private const CONFIRMS_PER_CLAIM = 5;
    private const CONFIRMS_PER_HOUR  = 20;

    /**
     * Codes one browser may request per hour, and claims one PAGE may collect per day.
     *
     * Two dimensions because they stop different people (§4). The browser limit stops
     * the farmer working through a category; the per-page limit stops a rival hammering
     * one nominee. Both are generous for a real person — nobody legitimately needs a
     * fourth code in an hour — and useless to a script.
     */
    private const CODES_PER_HOUR   = 4;
    private const CLAIMS_PER_DAY   = 6;

    public function __construct(
        private readonly ?OtpService $mailer = null,
        private readonly ?SmsService $sms = null,
        private readonly ?SupportTicketService $tickets = null,
        private readonly ?RateLimitService $limits = null,
    ) {}

    // ══ 1. what a claimant may pick ══════════════════════════════════════════

    /**
     * The channels this page can be claimed through — masked, and only deliverable ones.
     *
     * A channel we cannot actually send on is not an option, it is a dead end with a
     * button on it. So a phone is offered only when SMS or WhatsApp is configured, and
     * an email only when there is a mailer. A nominee whose one contact is a phone on a
     * deployment with no SMS credentials gets an empty list and the assisted path —
     * which is a real answer, unlike a code that silently never arrives.
     *
     * Raw addresses never leave this method. An unclaimed page must not become a
     * contact-harvesting endpoint for every nominee on the platform.
     *
     * @return list<array{key:string, channel:string, hint:string, independent:bool}>
     */
    public function channels(int $nomineeId, string $deviceFp = '', string $ipHash = ''): array
    {
        $out = [];
        foreach (ClaimIndependence::channelsFor($nomineeId, $deviceFp, $ipHash) as $c) {
            if (!$this->canDeliver($c['channel'])) continue;
            $out[] = [
                'key'         => self::channelKey($nomineeId, $c['channel'], $c['value']),
                'channel'     => $c['channel'],
                'hint'        => $c['hint'],
                'independent' => $c['independent'],
            ];
        }
        return $out;
    }

    // ══ 2. send a code ═══════════════════════════════════════════════════════

    /**
     * Issue a claim code to a channel already on the nomination.
     *
     * @return array{ok:bool, code:string, message:string, claim_id?:int, hint?:string, will_hold?:bool}
     */
    public function start(
        int $nomineeId,
        string $channelKey,
        string $deviceFp = '',
        string $ipHash = '',
        string $clientKey = '',
    ): array {
        $nominee = $this->nominee($nomineeId);
        if ($nominee === null) {
            return $this->no('NO_NOMINEE', 'That page could not be found.');
        }

        // §10: a second claim on an ACTIVE page goes to a human, always. No code is sent,
        // because sending one would imply the claim might succeed on its own.
        $active = $this->activeClaim($nomineeId);
        if ($active !== null) {
            return $this->no('ALREADY_CLAIMED',
                'This page has already been claimed. If it is your page and you did not claim it, '
                . 'write to ' . Notifier::supportEmail() . ' quoting ' . $active . ' and a person will '
                . 'look into it. There is nothing to pay.');
        }

        if (!$this->withinLimits($nomineeId, $clientKey)) {
            return $this->no('TOO_MANY',
                'That is several claim codes in a short time. Please wait an hour and try again, or '
                . 'write to ' . Notifier::supportEmail() . ' and a person will help.');
        }

        $contact = $this->resolveKey($nomineeId, $channelKey);
        if ($contact === null) {
            // Deliberately not "no such address": the key is either one of this page's
            // channels or it is nothing, and saying which would confirm an address to
            // somebody guessing at one.
            return $this->no('CHANNEL_UNKNOWN',
                'That is not a contact we hold for this page. Please pick one of the options shown.');
        }

        $verdict = $this->verdictFor($nomineeId, $contact, $deviceFp, $ipHash);

        // The row FIRST, then the send — the same order SupportTicketService argues for.
        // A code sent against no row is a claim with no trace; a row with no code is a
        // visible, harmless pending attempt.
        $claimId = $this->openPendingClaim($nomineeId, $contact, $verdict, $deviceFp, $ipHash);
        if ($claimId === 0) {
            return $this->no('NOT_STORED',
                'We could not start the claim just now. Please try again in a moment.');
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        if (!$this->issueToken($nomineeId, $contact, $code)) {
            return $this->no('NOT_STORED',
                'We could not start the claim just now. Please try again in a moment.');
        }

        if (!$this->deliverCode($contact, $code, (string) $nominee->name)) {
            return $this->no('SEND_FAILED',
                'We could not send the code to ' . $contact['hint'] . ' just now. Please try again, '
                . 'or write to ' . Notifier::supportEmail() . ' and a person will help. There is '
                . 'nothing to pay.');
        }

        return [
            'ok'       => true,
            'code'     => 'SENT',
            'message'  => 'We sent a 6-digit code to ' . $contact['hint'] . '. It expires in '
                        . self::CODE_TTL_MINUTES . ' minutes.',
            'claim_id' => $claimId,
            'hint'     => $contact['hint'],
            // Told UP FRONT, because §2's assisted path must not feel like a trap sprung
            // after the fact. Somebody whose only channel is their nominator's address
            // deserves to know a person will be involved before they wait for a code.
            'will_hold' => !$verdict['independent'],
        ];
    }

    // ══ 3. confirm it ════════════════════════════════════════════════════════

    /**
     * Confirm a code, and settle the claim as active or held.
     *
     * @return array{ok:bool, code:string, status?:string, message:string, reference?:string, ticket?:?string}
     */
    public function confirm(
        int $claimId,
        string $code,
        string $deviceFp = '',
        string $ipHash = '',
        string $clientKey = '',
    ): array {
        $claim = $this->pendingClaim($claimId);
        if ($claim === null) {
            return $this->no('NOT_PENDING',
                'That claim is no longer open. Please start again from the page.');
        }

        // The guessing budget is spent BEFORE the code is checked, and it is spent by the
        // CLIENT doing the guessing. Checking first and counting afterwards would put the
        // cost of a stranger's guesses on the claimant's code — which is the defect this
        // replaced. See MAX_TOKEN_ATTEMPTS.
        if (!$this->withinConfirmLimits($claimId, $clientKey)) {
            return $this->no('TOO_MANY_ATTEMPTS',
                'That is too many tries. Please wait an hour and ask for a new code, or write to '
                . Notifier::supportEmail() . ' and a person will help. There is nothing to pay.');
        }

        $nomineeId = (int) $claim->nominee_id;
        $token     = $this->consumeToken($nomineeId, $code);
        if ($token['ok'] !== true) {
            return $this->no($token['code'], $token['message']);
        }

        // ── from here the claim is a FACT: somebody proved they can read a channel on
        // this nomination. What remains is whether it may take the page.

        // Recomputed, not read back from the row. The channel verdict was stored at send
        // time for explanation, but the DEVICE is a property of the request confirming
        // right now — a claim started on one machine and confirmed on the nominator's is
        // exactly attacker 1, and a stored verdict would miss it.
        $contact = $this->contactOnClaim($claim);
        $verdict = $contact !== null
            ? $this->verdictFor($nomineeId, $contact, $deviceFp, $ipHash)
            : ['independent' => false, 'matched' => ['channel-unreadable'],
               'say' => 'We need one more thing before this page can be handed over. The contact '
                      . 'this code went to is no longer readable on the nomination, so a person '
                      . 'needs to confirm it with you. There is nothing to pay.'];

        // The fan-out runs BEFORE the decision, and for both outcomes. Somebody confirmed
        // a code against this page; that is news the nominee is owed whether or not we
        // then hand the page over. §5.
        $announced = ClaimNotifier::fanOut($claimId, $this->mailer, $this->sms);

        // §10 refuses silent claiming. A page handed over with nobody told is exactly
        // that, so it holds — and the usual cause is one contact channel and unconfigured
        // SMS, not an attack, which is why it is a hold and not a refusal.
        if ($verdict['independent'] && $announced['reached'] < 1) {
            $verdict = ['independent' => false, 'matched' => ['not-announced'],
                        'say' => 'We need one more thing before this page can be handed over. We '
                               . 'could not reach you on any other contact to confirm this claim, '
                               . 'so a person will check it with you. There is nothing to pay.'];
        }

        return $verdict['independent']
            ? $this->activate($claim, $verdict)
            : $this->hold($claim, $verdict, $contact);
    }

    // ══ settling ═════════════════════════════════════════════════════════════

    /**
     * Take the page — or lose the race and be held.
     *
     * The UNIQUE index on `active_nominee_id` is the guarantee, not the SELECT above it.
     * Two confirmations arriving together both pass every check in this class; the second
     * one's INSERT is what fails, and it is caught here rather than surfacing as a 500 to
     * somebody who did nothing wrong.
     *
     * @param array{independent:bool, matched:list<string>, say:string} $verdict
     * @return array{ok:bool, code:string, status?:string, message:string, reference?:string, ticket?:?string}
     */
    private function activate(object $claim, array $verdict): array
    {
        $claimId   = (int) $claim->id;
        $nomineeId = (int) $claim->nominee_id;
        $reference = ClaimNotifier::reference($claimId, $claim);

        try {
            $taken = DB::table('gates_nominee_claims')
                ->where('id', $claimId)->where('status', 'pending')
                ->update(\AfricaGates\Support\OptionalColumn::filter('gates_nominee_claims', [
                    'status'            => 'active',
                    'active_nominee_id' => $nomineeId,
                    'activated_at'      => date('Y-m-d H:i:s'),
                    'independence'      => self::encode($verdict),
                    // ── THE WINDOW, WRITTEN DOWN ─────────────────────────────
                    //
                    // The notification tells every contact "no money moves on a claim
                    // less than N days old". That sentence used to be the only place in
                    // the codebase that knew about the window — nothing enforced it, and
                    // ClaimGuard now refuses payouts against this exact column.
                    //
                    // STORED rather than derived, because the window length is a policy
                    // that will change and a claim must be governed by the policy in
                    // force when it was made. Deriving it from today's constant would
                    // silently move a date a nominee has already been given in writing.
                    'cooling_off_until' => ClaimGuard::windowFromNow(),
                    // Minted here as well as by the notifier, so the dispute link exists
                    // from the instant the claim is live rather than from whenever the
                    // first message is composed.
                    'dispute_token'     => ClaimDispute::mintToken(),
                ], ['cooling_off_until', 'dispute_token']));
            if ($taken < 1) {
                // Somebody else settled this row between the read and the write.
                return $this->hold($claim, [
                    'independent' => false, 'matched' => ['already-settled'],
                    'say' => 'We need one more thing before this page can be handed over. This '
                           . 'claim was already being dealt with, so a person will finish it with '
                           . 'you. There is nothing to pay.',
                ], $this->contactOnClaim($claim));
            }
        } catch (\Throwable $e) {
            // The duplicate-key path: another claim is already ACTIVE on this nominee.
            // §10 — a second claim on an active page goes to a human, always.
            error_log('[claim] ' . $reference . ' could not activate: ' . $e->getMessage());
            return $this->hold($claim, [
                'independent' => false, 'matched' => ['second-claim'],
                'say' => 'We need one more thing before this page can be handed over. Another '
                       . 'claim on this page was completed first, so a person will sort out which '
                       . 'is which. There is nothing to pay.',
            ], $this->contactOnClaim($claim));
        }

        return [
            'ok'        => true,
            'code'      => 'ACTIVE',
            'status'    => 'active',
            'reference' => $reference,
            'message'   => 'This page is yours. We have told every contact on the nomination that '
                         . 'it was claimed, so anyone else listed knows too.',
            'ticket'    => null,
        ];
    }

    /**
     * Hold it for a person, and make sure that person can be reached.
     *
     * The ticket is opened against the channel the code was DELIVERED to, because that is
     * the one address in this flow we know the claimant can read — they just proved it.
     * A held claim whose ticket nobody can answer is the monologue
     * 2026_08_13_ticket_links exists to end, so a signed no-account reply link is issued
     * whenever there is an email to issue it to.
     *
     * @param array{independent:bool, matched:list<string>, say:string} $verdict
     * @param array{channel:string, value:string, hint:string, country:string}|null $contact
     * @return array{ok:bool, code:string, status:string, message:string, reference:string, ticket:?string}
     */
    private function hold(object $claim, array $verdict, ?array $contact): array
    {
        $claimId   = (int) $claim->id;
        $reference = ClaimNotifier::reference($claimId, $claim);
        $say       = (string) $verdict['say'];

        try {
            DB::table('gates_nominee_claims')->where('id', $claimId)->update([
                'status'       => 'held',
                'hold_reason'  => mb_substr($say, 0, 250),
                'independence' => self::encode($verdict),
            ]);
        } catch (\Throwable $e) {
            error_log('[claim] ' . $reference . ' could not be recorded as held: ' . $e->getMessage());
        }

        return [
            'ok'        => true,          // NOT an error. The claimant did nothing wrong.
            'code'      => 'HELD',
            'status'    => 'held',
            'reference' => $reference,
            'message'   => $say,
            'ticket'    => $this->openAssistedTicket($claim, $reference, $say, $verdict, $contact),
        ];
    }

    /**
     * The assisted path, as a ticket with a checklist rather than a conversation.
     *
     * Any ONE of the routes listed clears the hold (§2), and the list is in the ticket so
     * whoever picks it up does not have to remember it — including the video call, which
     * is what makes "a person with no email, no document and a borrowed phone can still
     * claim" true rather than aspirational.
     *
     * @param array{independent:bool, matched:list<string>, say:string} $verdict
     * @param array{channel:string, value:string, hint:string, country:string}|null $contact
     */
    private function openAssistedTicket(object $claim, string $reference, string $say,
                                        array $verdict, ?array $contact): ?string
    {
        if ($this->tickets === null) return null;

        $email = ($contact !== null && $contact['channel'] === 'email') ? $contact['value'] : '';
        $name  = $this->nomineeName((int) $claim->nominee_id);

        $body = "A nominee claim needs a person.\n\n"
              . "Claim:    {$reference}\n"
              . "Page:     {$name}\n"
              . "Code went to: " . (string) ($claim->channel_hint ?? 'unknown') . "\n"
              . "Matched:  " . implode(', ', $verdict['matched']) . "\n\n"
              . "What the claimant was told:\n{$say}\n\n"
              . "ANY ONE of these clears the hold:\n"
              . "  • the nominator confirms from their own verified address — void if the\n"
              . "    nominator IS the claimant;\n"
              . "  • the school, church or organisation named on the nomination vouches;\n"
              . "  • a verified social account posts a one-time code;\n"
              . "  • a short video call on the number on file.\n\n"
              . "Nothing may be charged for any of this, and this is not a refusal.\n";

        try {
            $ref = $this->tickets->open($body, [], new SupportContext(null, $email !== '' ? $email : null), [], [
                'email'            => $email !== '' ? $email : null,
                'name'             => $name,
                'subject_override' => "Nominee claim {$reference} — needs one more thing",
            ]);
        } catch (\Throwable $e) {
            error_log('[claim] assisted ticket for ' . $reference . ' failed: ' . $e->getMessage());
            return null;
        }

        // §7.3: a human route WITHOUT an account. A claimant with no login must be able to
        // answer the ticket opened on their behalf, or the promise is one-way.
        if ($ref !== null && $email !== '') {
            try {
                $id = (int) DB::table('gates_support_tickets')->where('reference', $ref)->value('id');
                if ($id > 0) TicketLinkService::issue($id, $email);
            } catch (\Throwable) { /* the ticket stands; the link is a convenience */ }
        }

        return $ref;
    }

    // ══ the code itself ══════════════════════════════════════════════════════

    /**
     * One live claim code per PAGE, not per address.
     *
     * `OtpService::generate()` scopes its invalidation to (email_hash, purpose), which for
     * voting is right — one voter, one code. Here it would leave a live code on every
     * channel a nominee has, so somebody could hold two outstanding codes on one page and
     * confirm whichever arrives. Scoping to the nominee means the newest code is the only
     * one that works.
     *
     * @param array{channel:string, value:string, hint:string, country:string} $contact
     */
    private function issueToken(int $nomineeId, array $contact, string $code): bool
    {
        try {
            DB::table('gates_otp_tokens')
                ->where('purpose', 'claim')->where('nominee_id', $nomineeId)->where('is_used', 0)
                ->update(['is_used' => 1]);

            DB::table('gates_otp_tokens')->insert([
                // The column is named email_hash because voting got here first. What it
                // holds is the hash of whichever CHANNEL VALUE the code went to — an
                // address or a normalised number — never the value itself.
                'email_hash' => hash('sha256', $contact['channel'] . ':' . $contact['value']),
                'token_hash' => hash('sha256', $code),
                'purpose'    => 'claim',
                'nominee_id' => $nomineeId,
                'award_id'   => null,
                'attempts'   => 0,
                'is_used'    => 0,
                'expires_at' => Carbon::now()->addMinutes(self::CODE_TTL_MINUTES)->toDateTimeString(),
                'created_at' => Carbon::now()->toDateTimeString(),
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log('[claim] could not issue a code for nominee ' . $nomineeId . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check a code, exactly as the vote path does, and for the same reasons.
     *
     * Every attempt is counted so a code cannot be walked; a WRONG code does not consume
     * the token, because the real person mistyping their own code must be able to try
     * again; and the token is bound to the nominee, so a code issued for one page cannot
     * settle a claim on another.
     *
     * @return array{ok:bool, code:string, message:string}
     */
    private function consumeToken(int $nomineeId, string $code): array
    {
        $code = preg_replace('/\D+/', '', trim($code)) ?? '';
        if ($code === '') {
            return ['ok' => false, 'code' => 'INVALID_CODE', 'message' => 'Please enter the 6-digit code.'];
        }

        try {
            $token = DB::table('gates_otp_tokens')
                ->where('purpose', 'claim')->where('nominee_id', $nomineeId)->where('is_used', 0)
                ->where('expires_at', '>', Carbon::now()->toDateTimeString())
                ->orderBy('id', 'desc')->first();

            if (!$token) {
                return ['ok' => false, 'code' => 'INVALID_CODE',
                        'message' => 'That code has expired or was already used. Please ask for a new one.'];
            }

            DB::table('gates_otp_tokens')->where('id', $token->id)->increment('attempts');
            if (((int) $token->attempts + 1) > self::MAX_TOKEN_ATTEMPTS) {
                DB::table('gates_otp_tokens')->where('id', $token->id)->update(['is_used' => 1]);
                return ['ok' => false, 'code' => 'TOO_MANY_ATTEMPTS',
                        'message' => 'That is too many tries on one code. Please ask for a new one.'];
            }

            if (!hash_equals((string) $token->token_hash, hash('sha256', $code))) {
                return ['ok' => false, 'code' => 'INVALID_CODE',
                        'message' => 'That code is not right. Please check it and try again.'];
            }

            DB::table('gates_otp_tokens')->where('id', $token->id)->update(['is_used' => 1]);
            return ['ok' => true, 'code' => 'OK', 'message' => ''];
        } catch (\Throwable $e) {
            error_log('[claim] code check failed for nominee ' . $nomineeId . ': ' . $e->getMessage());
            return ['ok' => false, 'code' => 'INVALID_CODE',
                    'message' => 'We could not check that code just now. Please try again.'];
        }
    }

    /** @param array{channel:string, value:string, hint:string, country:string} $contact */
    private function deliverCode(array $contact, string $code, string $nomineeName): bool
    {
        $ttl = self::CODE_TTL_MINUTES;

        if ($contact['channel'] === 'email') {
            if ($this->mailer === null) return false;
            $safeName = htmlspecialchars($nomineeName, ENT_QUOTES, 'UTF-8');
            $html = <<<HTML
<p style="margin:0 0 14px;font-size:15px;color:#374151">Enter this code to claim the Africa GATES page for <strong>{$safeName}</strong>. It expires in <strong>{$ttl} minutes</strong>.</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0">
  <tr><td style="background:#f4f7f4;border:1px solid #d6e8d3;border-radius:14px;padding:24px;text-align:center">
    <div style="font-size:10px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#92a6a7;margin-bottom:12px">Your claim code</div>
    <div style="font-family:'JetBrains Mono',Consolas,'Courier New',monospace;font-weight:700;font-size:38px;letter-spacing:.34em;color:#10292C;padding-left:.34em">{$code}</div>
  </td></tr>
</table>
<p style="margin:0;font-size:13px;line-height:1.6;color:#92a6a7">There is nothing to pay to claim a page. If you did not ask for this code, ignore this email — nobody can claim the page without it.</p>
HTML;
            $plain = "Your Africa GATES claim code for {$nomineeName} is {$code}.\n\n"
                   . "It expires in {$ttl} minutes. There is nothing to pay to claim a page.\n"
                   . "If you did not ask for this code, ignore this email.\n";

            try {
                return (bool) ($this->mailer->sendBranded($contact['value'],
                    'Your Africa GATES claim code', $html, $plain, 'Security')['success'] ?? false);
            } catch (\Throwable $e) {
                error_log('[claim] code email failed: ' . $e->getMessage());
                return false;
            }
        }

        if ($this->sms === null) return false;
        $e164 = Phone::normalize($contact['value'], $contact['country']);
        if ($e164 === null) return false;

        $body = "Africa GATES claim code: {$code}. It expires in {$ttl} minutes. Nothing to pay. "
              . "If you did not ask for it, ignore this message.";

        $ok = false;
        // respectOptOut FALSE: this is a code the person just asked for, to prove a page
        // is theirs. Withholding it because they once opted out of event texts locks them
        // out of their own profile — which is the opposite of what opting out asked for.
        try { $ok = $this->sms->sendSms($e164, $body, 'claim-code', respectOptOut: false); } catch (\Throwable) {}
        try {
            if (!$ok && $this->sms->whatsappConfigured()) {
                $ok = $this->sms->sendWhatsApp($e164, $body, 'claim-code', respectOptOut: false);
            }
        } catch (\Throwable) {}
        return $ok;
    }

    // ══ rows ═════════════════════════════════════════════════════════════════

    /**
     * @param array{channel:string, value:string, hint:string, country:string} $contact
     * @param array{independent:bool, matched:list<string>, say:string} $verdict
     */
    private function openPendingClaim(int $nomineeId, array $contact, array $verdict,
                                      string $deviceFp, string $ipHash): int
    {
        try {
            $id = (int) DB::table('gates_nominee_claims')->insertGetId([
                'nominee_id'   => $nomineeId,
                'status'       => 'pending',
                'method'       => 'otp',
                'channel'      => $contact['channel'],
                // The MASKED destination, in the column too: a leak of this table must not
                // hand over a list of nominee contact details.
                'channel_hint' => mb_substr($contact['hint'], 0, 120),
                'independence' => self::encode($verdict),
                'device_fp'    => $deviceFp !== '' ? $deviceFp : null,
                'ip_hash'      => $ipHash !== '' ? $ipHash : null,
                'claimed_at'   => date('Y-m-d H:i:s'),
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            if ($id > 0) ClaimNotifier::reference($id);
            return $id;
        } catch (\Throwable $e) {
            error_log('[claim] could not open a claim for nominee ' . $nomineeId . ': ' . $e->getMessage());
            return 0;
        }
    }

    /** The reference of the claim already holding this page, or null. */
    private function activeClaim(int $nomineeId): ?string
    {
        try {
            $row = DB::table('gates_nominee_claims')
                ->where('nominee_id', $nomineeId)->where('status', 'active')->first();
            return $row === null ? null : ClaimNotifier::reference((int) $row->id, $row);
        } catch (\Throwable) {
            return null;
        }
    }

    private function pendingClaim(int $claimId): ?object
    {
        if ($claimId < 1) return null;
        try {
            return DB::table('gates_nominee_claims')
                ->where('id', $claimId)->where('status', 'pending')->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /** An approved, unmerged nominee — the only kind with a page to claim. */
    private function nominee(int $nomineeId): ?object
    {
        if ($nomineeId < 1) return null;
        try {
            return MergeService::notMerged(
                DB::table('gates_nominees')->where('id', $nomineeId)->where('status', 'approved')
            )->first();
        } catch (\Throwable) {
            return null;
        }
    }

    private function nomineeName(int $nomineeId): string
    {
        try {
            $n = trim((string) DB::table('gates_nominees')->where('id', $nomineeId)->value('name'));
            if ($n !== '') return $n;
        } catch (\Throwable) {}
        return 'this nominee';
    }

    // ══ channels and verdicts ════════════════════════════════════════════════

    /**
     * A stable, opaque selector for one channel of one nominee.
     *
     * No secret, because it does not need one: it selects among addresses the platform
     * already holds, and the only thing it can cause is a code being sent to one of them.
     * Bound to the nominee so a key cannot be lifted from one page and replayed on
     * another.
     */
    private static function channelKey(int $nomineeId, string $channel, string $value): string
    {
        return substr(hash('sha256', $nomineeId . '|' . $channel . '|' . $value), 0, 16);
    }

    /**
     * Resolve a key back to a real, deliverable contact — or null.
     *
     * @return array{channel:string, value:string, hint:string, country:string}|null
     */
    private function resolveKey(int $nomineeId, string $channelKey): ?array
    {
        $channelKey = strtolower(trim($channelKey));
        if ($channelKey === '') return null;

        foreach (ClaimIndependence::contactsFor($nomineeId) as $c) {
            if (!$this->canDeliver($c['channel'])) continue;
            if (hash_equals(self::channelKey($nomineeId, $c['channel'], $c['value']), $channelKey)) {
                return $c;
            }
        }
        return null;
    }

    /**
     * The contact a pending claim's code went to, found again from the masked hint.
     *
     * Matching on the mask rather than storing the address keeps the raw value out of the
     * claims table, which is the rule the schema already sets for `channel_hint`. If the
     * nomination changed underneath and nothing matches, the caller HOLDS — unreadable is
     * not independent, the same fail-safe {@see ClaimIndependence} applies.
     *
     * @return array{channel:string, value:string, hint:string, country:string}|null
     */
    private function contactOnClaim(object $claim): ?array
    {
        $channel = (string) ($claim->channel ?? '');
        $hint    = (string) ($claim->channel_hint ?? '');
        if ($channel === '' || $hint === '') return null;

        $found = [];
        foreach (ClaimIndependence::contactsFor((int) $claim->nominee_id) as $c) {
            if ($c['channel'] === $channel && $c['hint'] === $hint) $found[] = $c;
        }
        // Exactly one, or none. Two contacts sharing a mask are indistinguishable here,
        // and guessing between them would mean judging a claim against the wrong address.
        return count($found) === 1 ? $found[0] : null;
    }

    /**
     * The independence verdict for this channel AND this request's device.
     *
     * @param array{channel:string, value:string, hint:string, country:string} $contact
     * @return array{independent:bool, matched:list<string>, say:string}
     */
    private function verdictFor(int $nomineeId, array $contact, string $deviceFp, string $ipHash): array
    {
        $v = ClaimIndependence::check(
            $nomineeId,
            email:    $contact['channel'] === 'email' ? $contact['value'] : '',
            phone:    $contact['channel'] === 'phone' ? $contact['value'] : '',
            deviceFp: $deviceFp,
            ipHash:   $ipHash,
        );

        // ── SECOND OPINION: SIGNALS INDEPENDENCE CANNOT SEE ──────────────────
        //
        // The independence check asks whether this contact belongs to somebody who
        // nominated. It cannot see a SHARED mailbox (one school address on thirty
        // children's nominations — independent of the nominator on every one of them,
        // and proof of being none of them), one device working through several nominees,
        // or a page whose published result makes it worth taking.
        //
        // Either voice can send a claim to a person; neither can refuse it. See ClaimRisk.
        $risk = ClaimRisk::assess($nomineeId, $contact, $deviceFp, $ipHash);
        if ($risk['hold']) {
            return [
                'independent' => false,
                // Both sets of reasons are kept on the row: an operator opening a held
                // claim needs to know whether it was held for a contact match, a breadth
                // signal, or both — they lead to different questions.
                'matched' => array_values(array_unique(array_merge($v['matched'], $risk['signals']))),
                // The RISK sentence when independence had nothing to say, because
                // ClaimIndependence's wording explains a nominator match and would be
                // simply untrue here.
                'say' => $v['independent'] ? $risk['say'] : $v['say'],
            ];
        }

        return ['independent' => $v['independent'], 'matched' => $v['matched'], 'say' => $v['say']];
    }

    private function canDeliver(string $channel): bool
    {
        return $channel === 'email'
            ? $this->mailer !== null
            : ($this->sms !== null && $this->sms->configured());
    }

    // ══ limits ═══════════════════════════════════════════════════════════════

    private function withinLimits(int $nomineeId, string $clientKey): bool
    {
        if ($this->limits === null) return true;   // CLI and tests

        // The page limit first: it is the one an attacker cannot escape by changing
        // browsers, and checking it first means a farmer's traffic does not also burn
        // through a real visitor's per-browser allowance on the same page.
        if (!$this->limits->check('claim-page:' . $nomineeId, 'claim_start', self::CLAIMS_PER_DAY, 86400)) {
            return false;
        }
        if ($clientKey === '') return true;
        return $this->limits->check($clientKey, 'claim_code', self::CODES_PER_HOUR, 3600);
    }

    /**
     * May this client make another guess — at this claim, and at all?
     *
     * Keyed on (claim, client) as well as on the client alone, so that exhausting a budget
     * harms only whoever spent it. With no limiter configured (the CLI, and tests that do
     * not inject one) the token backstop in {@see MAX_TOKEN_ATTEMPTS} carries the whole
     * policy, which is why that number is set to something still unwalkable.
     */
    private function withinConfirmLimits(int $claimId, string $clientKey): bool
    {
        if ($this->limits === null || $clientKey === '') return true;

        // sha256 because gates_rate_limits.fingerprint is exactly 64 characters.
        $perClaim = hash('sha256', 'claim-confirm:' . $claimId . ':' . $clientKey);
        if (!$this->limits->check($perClaim, 'claim_confirm', self::CONFIRMS_PER_CLAIM, 3600)) {
            return false;
        }
        return $this->limits->check($clientKey, 'claim_confirm_any', self::CONFIRMS_PER_HOUR, 3600);
    }

    // ══ helpers ══════════════════════════════════════════════════════════════

    /** @return array{ok:bool, code:string, message:string} */
    private function no(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }

    /** @param array<string,mixed> $verdict */
    private static function encode(array $verdict): string
    {
        return (string) json_encode($verdict, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
