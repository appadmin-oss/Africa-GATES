<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * The three kinds of guest of honour, and what each of them is asked.
 *
 * ── WHY THIS IS A TABLE OF THREE AND NOT A FLAG ──────────────────────────────
 *
 * A principal, a child nominee and a judge are invited to the same ceremony for three
 * different reasons, and the reason is the whole content of the letter. A principal is
 * asked to fill the hall so the room can witness the work they have done; a child
 * nominee so it can witness the life they are living and what their parents have poured
 * into them; a judge so it can witness the integrity of a judgement nobody sees being
 * made. Collapsing that into one template with a name substituted produces a circular,
 * and a circular is what people delete.
 *
 * Each audience therefore owns four things: which shortlist it is drawn from, how many
 * guests it may bring, the sentence that names why, and the settings key an operator
 * edits to change the quota. Nothing here is a literal at a call site.
 *
 * ── WHICH PROGRAMME IS WHICH ─────────────────────────────────────────────────
 *
 * `principal` and `child` are resolved by programme slug, not by category, because that
 * is what they are: the Incredible Principal Awards and the Incorruptible Awards are
 * separate programmes with separate cycles. The slugs are the default and are
 * settings-overridable, because a programme can be renamed and a hardcoded slug is how
 * an invitation run silently resolves nobody.
 */
final class InviteAudience
{
    public const PRINCIPAL = 'principal';
    public const CHILD     = 'child';
    public const JUDGE     = 'judge';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::PRINCIPAL, self::CHILD, self::JUDGE];
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
            self::PRINCIPAL => [
                'label'          => 'Principal nominees',
                'quota'          => 25,
                'programme_slug' => 'principals',
                'salutation'     => 'Dear',
                // The reason the room is being filled. Written as one sentence because it
                // has to survive being read on a phone, in a hurry, once.
                'witness'        => 'to witness your resilience, your hard work, and the '
                                  . 'incredible labour of love you have given this community',
            ],
            self::CHILD => [
                'label'          => 'Child nominees',
                'quota'          => 25,
                'programme_slug' => 'incorruptible',
                'salutation'     => 'Dear',
                'witness'        => 'to witness the incorruptible life you are living, and the '
                                  . 'hard work your parents have poured into you',
            ],
            self::JUDGE => [
                'label'          => 'Judges',
                'quota'          => 10,
                // Judges are not drawn from a programme's shortlist; they are the panel.
                'programme_slug' => null,
                'salutation'     => 'Dear',
                'witness'        => 'to witness the integrity of your judgement, your love for '
                                  . 'this community, and your support for this initiative',
            ],
        ];

        if (!isset($defaults[$audience])) {
            throw new \InvalidArgumentException('Unknown invite audience: ' . $audience);
        }

        $d   = $defaults[$audience];
        $set = self::settings();

        $quotaKey = 'invite_quota_' . $audience;
        $slugKey  = 'invite_programme_' . $audience;

        $quota = (int) trim((string) ($set[$quotaKey] ?? ''));
        $slug  = trim((string) ($set[$slugKey] ?? ''));

        return [
            'key'            => $audience,
            'label'          => $d['label'],
            // Clamped, not trusted. A quota is what a code's max_uses becomes and what a
            // letter promises in writing; 0 would send an invitation that admits nobody
            // and a four-figure typo would hand out a code the whole internet can spend.
            'quota'          => $quota > 0 ? min(500, $quota) : $d['quota'],
            'quota_setting'  => $quotaKey,
            'programme_slug' => $d['programme_slug'] === null ? null : ($slug !== '' ? $slug : $d['programme_slug']),
            'witness'        => $d['witness'],
            'salutation'     => $d['salutation'],
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
