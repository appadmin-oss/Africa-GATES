<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * The two kinds of guest of honour, and what each of them is asked.
 *
 * ── TWO, AND WHY NOT MORE ────────────────────────────────────────────────────
 *
 * This began as three — `principal`, `child` and `judge` — because the brief that asked
 * for it wrote separate copy for the Incredible Principal Awards and the Incorruptible
 * Awards. That was a taxonomy invented out of two example programmes, and it was wrong:
 * those are just two of the programmes that happen to exist, and the platform is built to
 * run any number of them. A nominee is a nominee whichever award they are shortlisted for.
 *
 * So the split that survives is the one that is real, because the two groups are invited
 * for genuinely different reasons and are drawn from different places: a NOMINEE comes off
 * a published shortlist and is honoured for what they did; a JUDGE comes off the panel and
 * is honoured for how they judged it. Nothing else about them differs.
 *
 * ── AND WHY THE 'WHY' IS EDITABLE ────────────────────────────────────────────
 *
 * The one sentence that names why the hall is being filled cannot be generic and cannot be
 * hardcoded either. An Incredible Principal Award and a Carol Award honour completely
 * different things; a sentence written to cover both honours neither, and a sentence
 * hardcoded for one is wrong for every other programme this platform will ever run. So it
 * is a setting, per audience, with a warm general default — and an operator running a
 * particular programme writes the sentence that is true of it.
 *
 * WHICH PROGRAMME the nominees come from is not asked here at all. It is the programme the
 * ceremony is linked to (`gates_site_events.programme_id`), which is a fact the operator
 * has already stated on the event, and reading it from a settings slug instead was a second
 * source for one answer.
 */
final class InviteAudience
{
    public const NOMINEE = 'nominee';
    public const JUDGE   = 'judge';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::NOMINEE, self::JUDGE];
    }

    public static function isValid(string $audience): bool
    {
        return in_array($audience, self::all(), true);
    }

    /**
     * Everything one audience is and is asked.
     *
     * @return array{
     *   key:string, label:string, quota:int, quota_setting:string,
     *   programme_slug:?string, witness:string, salutation:string
     * }
     */
    public static function spec(string $audience): array
    {
        $defaults = [
            self::NOMINEE => [
                'label'      => 'Nominees',
                'one'        => 'Nominee',
                'quota'      => 25,
                'salutation' => 'Dear',
                // The reason the room is being filled. EDITABLE, and that is the point of
                // this whole class: an Incredible Principal Award and a Carol Award honour
                // completely different things, and a sentence that tries to cover both
                // honours neither. The default is warm and general; an operator running a
                // specific programme writes the sentence that is true of it.
                'witness'    => 'to witness the work you have done, and what it has meant to '
                              . 'the people around you',
            ],
            self::JUDGE => [
                'label'      => 'Judges',
                'one'        => 'Judge',
                'quota'      => 10,
                'salutation' => 'Dear',
                'witness'    => 'to witness the integrity of your judgement, your love for '
                              . 'this community, and your support for this initiative',
            ],
        ];

        if (!isset($defaults[$audience])) {
            throw new \InvalidArgumentException('Unknown invite audience: ' . $audience);
        }

        $d   = $defaults[$audience];
        $set = self::settings();

        $quotaKey   = 'invite_quota_' . $audience;
        $witnessKey = 'invite_witness_' . $audience;

        $quota   = (int) trim((string) ($set[$quotaKey] ?? ''));
        $witness = trim((string) ($set[$witnessKey] ?? ''));

        return [
            'key'             => $audience,
            'label'           => $d['label'],
            'one'             => $d['one'],
            // Clamped, not trusted. A quota is what a code's max_uses becomes and what a
            // letter promises in writing; 0 would send an invitation that admits nobody
            // and a four-figure typo would hand out a code the whole internet can spend.
            'quota'           => $quota > 0 ? min(500, $quota) : $d['quota'],
            'quota_setting'   => $quotaKey,
            'witness'         => $witness !== '' ? $witness : $d['witness'],
            'witness_setting' => $witnessKey,
            'witness_default' => $d['witness'],
            'salutation'      => $d['salutation'],
        ];
    }

    /** The discount an invitee's guests get, as a whole percentage. */
    public static function discountPercent(): int
    {
        $v = (int) trim((string) (self::settings()['invite_discount_percent'] ?? ''));

        // 1–100. A 0 would mint a code that discounts nothing while the letter promises
        // ten per cent, and anything over 100 is a negative price the checkout would
        // have to decide what to do with.
        return $v > 0 ? min(100, $v) : 10;
    }

    /**
     * @return array<string,string>
     */
    private static function settings(): array
    {
        try {
            return DB::table('gates_settings')->pluck('value', 'key_name')->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
