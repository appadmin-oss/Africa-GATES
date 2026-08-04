<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Is the channel this person is claiming through independent of whoever nominated them?
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FACT THAT BREAKS THE OBVIOUS CLAIM FLOW
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * **The email on a nomination was typed by the NOMINATOR.** It is a claim about the
 * nominee, not proof from them.
 *
 * Every first-draft claim flow sends a code to it and calls the job done. So:
 *
 *   I nominate a well-known choral director, and in the nominee email field I type
 *   MY OWN address. The nomination is genuine, a moderator approves it, her page
 *   goes live. Then I "claim" it with a code sent to my own inbox. I have proved
 *   that I can read my own email; the system records it as proof of her identity.
 *
 * No amount of OTP rigour fixes an input the attacker supplied. The fix is not a
 * stronger code — it is asking a different question first: *is this address one the
 * nominator could have controlled?* Both sides are in the same database row, so the
 * check costs one read and no judgement.
 *
 * ── FAILING IS NOT REFUSING ──────────────────────────────────────────────────
 *
 * This is the part that is easy to get backwards, and getting it backwards would be
 * cruel. The single commonest reason a nominee's contact matches their nominator's
 * is that a customer, a daughter or a church secretary filled the form in on their
 * behalf and used the address they had — their own. That person is the MOST likely
 * to be the genuine nominee, not the least.
 *
 * So a failure here HOLDS the claim for a human with a checklist. It never says no.
 * {@see \AfricaGates\Services\NomineeClaimService} owns that outcome; this class
 * only reports what matched.
 *
 * ── WHAT IS DELIBERATELY NOT HERE ────────────────────────────────────────────
 *
 * No scoring, no thresholds, no model. Every signal below is an exact comparison of
 * two stored values, so the verdict is reproducible months later from the same two
 * rows — which is what makes it explainable to somebody it held, and what stops it
 * drifting as data changes around it.
 */
final class ClaimIndependence
{
    /**
     * Compare a claim channel and device against every nominator for this nominee.
     *
     * Every APPROVED nomination is checked, not just the one being claimed against.
     * A nominee with three nominations has three different nominators, and an
     * address matching ANY of them is an address a nominator could read. Checking
     * only the claimed row would let an attacker who submitted the second nomination
     * claim through the first.
     *
     * @param string $email     the claim channel, when claiming by email
     * @param string $phone     the claim channel, when claiming by phone
     * @param string $deviceFp  the CLAIMANT's device fingerprint, now
     * @param string $ipHash    the CLAIMANT's hashed IP, now
     *
     * @return array{independent:bool, matched:list<string>, checked:int, say:string}
     */
    public static function check(
        int $nomineeId,
        string $email = '',
        string $phone = '',
        string $deviceFp = '',
        string $ipHash = '',
    ): array {
        $rows = self::nominationsFor($nomineeId);

        // NO NOMINATIONS IS NOT INDEPENDENCE.
        //
        // Returning "independent" for an empty set would mean a nominee whose rows
        // could not be read — a renamed column, a failed join, a merged nominee —
        // sails through the one check that stops the commonest attack. Absence of
        // evidence is not evidence, so it holds.
        if ($rows === []) {
            return ['independent' => false, 'matched' => ['no-nomination'], 'checked' => 0,
                    'say' => 'No approved nomination could be read for this page, so independence '
                           . 'cannot be established. A person needs to look at this.'];
        }

        $email    = self::normEmail($email);
        $phone    = self::normPhone($phone);
        $deviceFp = trim($deviceFp);
        $ipHash   = trim($ipHash);

        $matched = [];
        foreach ($rows as $r) {
            $nEmail = self::normEmail((string) ($r->nominator_email ?? ''));
            $nPhone = self::normPhone((string) ($r->nominator_phone ?? ''));

            if ($email !== '' && $nEmail !== '') {
                if ($email === $nEmail) {
                    $matched[] = 'email';
                } elseif (self::sameMailbox($email, $nEmail)) {
                    // a.okonkwo+gates@gmail.com and a.okonkwo@gmail.com are one inbox.
                    // Plus-addressing and dots in Gmail local-parts are the cheapest
                    // possible bypass of an exact-match check, so they are closed here
                    // rather than left as a known gap.
                    $matched[] = 'email-alias';
                }
            }

            if ($phone !== '' && $nPhone !== '' && $phone === $nPhone) {
                $matched[] = 'phone';
            }

            // The device and IP are compared regardless of which channel is being
            // claimed. They are the signals an attacker cannot re-type: somebody
            // claiming from the same browser that submitted the nomination is the
            // textbook case, caught without reference to any address at all.
            if ($deviceFp !== '' && $deviceFp === trim((string) ($r->device_fp ?? ''))) {
                $matched[] = 'device';
            }
            if ($ipHash !== '' && $ipHash === trim((string) ($r->ip_hash ?? ''))) {
                $matched[] = 'ip';
            }
        }

        $matched = array_values(array_unique($matched));

        return [
            'independent' => $matched === [],
            'matched'     => $matched,
            'checked'     => count($rows),
            'say'         => $matched === []
                ? 'This channel is independent of everyone who nominated this person.'
                : self::explain($matched),
        ];
    }

    /**
     * The contact channels a claim may legitimately be sent to, already filtered.
     *
     * Offering a channel that will fail independence and only saying so after the
     * code has been sent wastes an SMS and reads as a trap. So the choice presented
     * to a claimant is computed from the same rule that will judge them.
     *
     * Returns masked hints only — an unclaimed page must never disclose the
     * nominee's actual address to whoever is looking at it, which would turn this
     * flow into a contact-harvesting endpoint for every nominee on the platform.
     *
     * @return list<array{channel:string, hint:string, value:string, independent:bool}>
     */
    public static function channelsFor(int $nomineeId, string $deviceFp = '', string $ipHash = ''): array
    {
        $out = [];

        foreach (self::contactsFor($nomineeId) as $c) {
            $verdict = self::check(
                $nomineeId,
                email:    $c['channel'] === 'email' ? $c['value'] : '',
                phone:    $c['channel'] === 'phone' ? $c['value'] : '',
                deviceFp: $deviceFp,
                ipHash:   $ipHash,
            );

            $out[] = [
                'channel'     => $c['channel'],
                'hint'        => $c['hint'],
                'value'       => $c['value'],
                'independent' => $verdict['independent'],
            ];
        }

        // Independent channels first, so the fast path is the default and the held
        // path is a deliberate second choice rather than an accident of ordering.
        usort($out, static fn($a, $b) => ($b['independent'] <=> $a['independent']));
        return $out;
    }

    /**
     * Every channel on file for this nominee — no verdict, no filtering.
     *
     * Separate from {@see channelsFor()} because the two callers want opposite things
     * from the same list. A claimant is offered the channels that will PASS, sorted so
     * the fast path is the default. The notification fan-out in
     * {@see \AfricaGates\Services\ClaimNotifier} must reach every channel there is,
     * *especially* the ones the claimant did not use and could not control — which is
     * the whole mechanism by which a stolen claim becomes loud.
     *
     * So independence is deliberately not computed here. A fan-out that skipped
     * non-independent channels would go quiet in exactly the case §1 is about: the
     * nominator claiming through the address they typed. Their victim's other number
     * is the one that has to ring.
     *
     * `country` rides along because a phone is stored as the nominator typed it
     * (08031234567) and every messaging provider needs E.164. Resolving that needs the
     * nomination's country, so it is carried with the number rather than guessed
     * downstream.
     *
     * @return list<array{channel:string, hint:string, value:string, country:string}>
     */
    public static function contactsFor(int $nomineeId): array
    {
        $out  = [];
        $seen = [];

        foreach (self::nominationsFor($nomineeId) as $r) {
            $country = strtoupper(trim((string) ($r->country_code ?? ''))) ?: 'NG';

            foreach ([
                ['email', self::normEmail((string) ($r->nominee_email ?? ''))],
                ['phone', self::normPhone((string) ($r->nominee_phone ?? ''))],
            ] as [$kind, $value]) {
                if ($value === '' || isset($seen[$kind . ':' . $value])) continue;
                $seen[$kind . ':' . $value] = true;

                $out[] = [
                    'channel' => $kind,
                    'hint'    => $kind === 'email' ? self::maskEmail($value) : self::maskPhone($value),
                    'value'   => $value,
                    'country' => $country,
                ];
            }
        }

        return $out;
    }

    // ── comparisons ─────────────────────────────────────────────────────────

    /**
     * Same inbox behind two different spellings?
     *
     * Only Gmail's documented rules are applied, and only to Gmail. Dot-insensitivity
     * is a Google behaviour, not an internet one: stripping dots from a local-part at
     * a provider that treats them as significant would declare two DIFFERENT people
     * the same person and hold an innocent claim. Plus-addressing is near-universal,
     * so it is stripped everywhere.
     */
    private static function sameMailbox(string $a, string $b): bool
    {
        [$la, $da] = array_pad(explode('@', $a, 2), 2, '');
        [$lb, $db] = array_pad(explode('@', $b, 2), 2, '');
        if ($da === '' || $da !== $db) return false;

        $strip = static function (string $local, string $domain): string {
            $local = explode('+', $local, 2)[0];
            if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
                $local = str_replace('.', '', $local);
            }
            return $local;
        };

        return $strip($la, $da) !== '' && $strip($la, $da) === $strip($lb, $db);
    }

    private static function normEmail(string $v): string
    {
        return strtolower(trim($v));
    }

    /**
     * Nigerian numbers, comparable.
     *
     * 08031234567, +2348031234567 and 2348031234567 are one phone. Comparing them
     * raw would let the same number pass as independent of itself simply by being
     * typed in the other common format — and both formats are genuinely in the
     * database, because the nomination form accepts either.
     */
    private static function normPhone(string $v): string
    {
        $d = preg_replace('/\D+/', '', $v) ?? '';
        if ($d === '') return '';
        if (str_starts_with($d, '234')) $d = '0' . substr($d, 3);
        if (strlen($d) === 10 && !str_starts_with($d, '0')) $d = '0' . $d;
        return $d;
    }

    // ── presentation ────────────────────────────────────────────────────────

    /** Never the whole address. An unclaimed page must not disclose contact details. */
    private static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($domain === '') return '•••';
        $head = mb_substr($local, 0, 1);
        return $head . str_repeat('•', max(3, min(6, mb_strlen($local) - 1))) . '@' . $domain;
    }

    private static function maskPhone(string $phone): string
    {
        $tail = mb_substr($phone, -3);
        return str_repeat('•', max(3, mb_strlen($phone) - 3)) . $tail;
    }

    /**
     * Why it was held, in words the person it held can act on.
     *
     * Never "verification failed". The overwhelmingly likely explanation is that
     * somebody helped them fill the form in, and the message has to leave that
     * possibility open or it reads as an accusation of fraud against the victim of
     * an admin quirk.
     *
     * @param list<string> $matched
     */
    private static function explain(array $matched): string
    {
        $bits = [];
        if (in_array('email', $matched, true) || in_array('email-alias', $matched, true)) {
            $bits[] = 'the email address';
        }
        if (in_array('phone', $matched, true))  $bits[] = 'the phone number';
        if (in_array('device', $matched, true)) $bits[] = 'the device';
        if (in_array('ip', $matched, true))     $bits[] = 'the network';

        $what = $bits === [] ? 'the contact details' : self::join($bits);

        return 'We need one more thing before this page can be handed over. ' . ucfirst($what)
             . ' used here is the same one the person who nominated you used — which usually just '
             . 'means they filled the form in for you. Someone on our team will confirm it with you, '
             . 'and there is nothing to pay.';
    }

    /** @param list<string> $bits */
    private static function join(array $bits): string
    {
        if (count($bits) === 1) return $bits[0];
        $last = array_pop($bits);
        return implode(', ', $bits) . ' and ' . $last;
    }

    // ── data ────────────────────────────────────────────────────────────────

    /**
     * Approved nominations for this nominee.
     *
     * APPROVED only, deliberately. A pending nomination is an unreviewed allegation
     * about a named person and must not be a source of anything — but note the
     * direction of the risk here: excluding pending rows makes the independence
     * check LOOSER, because a nominator on a pending row is not compared against.
     * That is the correct trade only because a pending nomination cannot put a
     * nominee on a ballot, so there is nothing yet to claim.
     *
     * @return list<object>
     */
    private static function nominationsFor(int $nomineeId): array
    {
        if ($nomineeId < 1) return [];

        try {
            $nominee = DB::table('gates_nominees')->where('id', $nomineeId)->first();
            if (!$nominee) return [];

            $q = DB::table('gates_nominations')->where('status', 'approved');

            // Matched on name within the category, because gates_nominations has no
            // nominee_id: the nominee row is created FROM the nomination at approval
            // and the link was never written back. Narrow rather than fuzzy — an
            // over-broad match here would compare against a stranger's nominator and
            // hold an honest claim for no reason.
            $q->whereRaw('LOWER(TRIM(nominee_name)) = ?', [mb_strtolower(trim((string) $nominee->name))]);
            if (($nominee->category_id ?? null) !== null) {
                $q->where('category_id', (int) $nominee->category_id);
            }

            return $q->get()->all();
        } catch (\Throwable $e) {
            error_log('[claim] could not read nominations for nominee ' . $nomineeId . ': ' . $e->getMessage());
            // Unreadable is not independent — see check().
            return [];
        }
    }
}
