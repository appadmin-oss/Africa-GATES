<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * One input box, two kinds of code.
 *
 * ── WHY NOT TWO FIELDS ───────────────────────────────────────────────────────
 * A buyer holding a string of letters does not know, and should not have to know, whether
 * it is a discount or a referral. Two boxes means half of them are typed into the wrong
 * one and the answer is "that code is not recognised" for a code that is perfectly valid.
 * So the existing "Discount code" field takes either, and this decides which it was.
 *
 * ── ORDER: DISCOUNT FIRST ────────────────────────────────────────────────────
 * A discount changes what the buyer pays and a referral does not, so if a string is both
 * (it should never be — the codespaces are separate tables — but a collision would be
 * somebody's bad day) the reading that saves the buyer money wins.
 *
 * ── THE LINK BEATS THE BOX ───────────────────────────────────────────────────
 * When a `ref` arrived by LINK, that referral wins over one typed here. The link is the
 * path this scheme is built around: the referrer shares a URL, the buyer does nothing.
 * A typed code is the fallback for a link that could not survive being read aloud or
 * printed. Both routes are shown back to the buyer in the quote — a referral that is
 * silently applied to the wrong person is how a support ticket starts.
 */
final class EventCodeResolver
{
    /**
     * @param string   $typed       whatever is in the shared code box
     * @param string   $linkedRef   a referral code captured from ?ref= earlier in the session
     * @param int|null $buyerUserId signed-in member, or null
     *
     * @return array{
     *   discount:string, referral:string, kind:string,
     *   ok:bool, message:string, off:int, note:string
     * }
     *   kind is 'none' | 'discount' | 'referral'. `discount` is the string to hand to
     *   EventDiscount; `referral` is the code to stamp on the registration.
     */
    public static function resolve(string $typed, string $linkedRef, int $eventId, int $tierId,
                                   int $lineTotal, string $buyerEmail, int $qty = 1,
                                   ?int $buyerUserId = null): array
    {
        $blank = ['discount' => '', 'referral' => '', 'kind' => 'none',
                  'ok' => false, 'message' => '', 'off' => 0, 'note' => ''];

        // The link first, so it is already decided before the box is read.
        $linked = '';
        if (ReferralService::normalise($linkedRef) !== '') {
            $u = ReferralService::usable($linkedRef, $buyerUserId, $buyerEmail);
            // A refused link (self-referral, unknown code) is not surfaced as an error:
            // the buyer did not type it and cannot act on it. It is simply not applied.
            if ($u['ok']) $linked = ReferralService::normalise($linkedRef);
        }

        $typedNorm = ReferralService::normalise($typed);
        if ($typedNorm === '') {
            return $linked === ''
                ? $blank
                : array_merge($blank, ['referral' => $linked, 'kind' => 'referral', 'ok' => true,
                                       'message' => 'Referral applied.', 'note' => 'Referral applied.']);
        }

        // 1. A discount?
        $d = EventDiscount::apply($typed, $eventId, $tierId, $lineTotal, $buyerEmail, $qty);
        if ($d['ok'] ?? false) {
            return [
                'discount' => $typed,
                'referral' => $linked,          // a link still gets its credit alongside
                'kind'     => 'discount',
                'ok'       => true,
                'message'  => (string) ($d['message'] ?? 'Discount applied.'),
                'off'      => (int) ($d['off'] ?? 0),
                'note'     => (string) ($d['note'] ?? $d['message'] ?? ''),
            ];
        }

        // 2. A referral, then. Only if a link has not already claimed it.
        if ($linked !== '') {
            return array_merge($blank, [
                'referral' => $linked, 'kind' => 'referral', 'ok' => true,
                // Said out loud, because the buyer typed something and it did not win.
                'message'  => 'Referral from your link is applied. The code you typed is not a discount for this event.',
                'note'     => 'Referral applied.',
            ]);
        }

        $r = ReferralService::usable($typed, $buyerUserId, $buyerEmail);
        if ($r['ok']) {
            return array_merge($blank, [
                'referral' => $typedNorm, 'kind' => 'referral', 'ok' => true,
                'message'  => 'Referral code applied — thanks for supporting them. '
                            . 'It does not change your price.',
                'note'     => 'Referral applied.',
            ]);
        }

        // Neither. The discount refusal is the more useful of the two messages when the
        // code looked like a discount attempt, and ReferralService's when it did not
        // resolve at all — but "not recognised" from either is the same sentence to a
        // buyer, so prefer whichever actually said something specific.
        $why = (string) ($d['message'] ?? '');
        if ($why === '' || $why === 'That code is not recognised for this event.') {
            $why = (string) $r['message'];
        }

        return array_merge($blank, ['ok' => false, 'message' => $why ?: 'That code is not recognised.']);
    }
}
