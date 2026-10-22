<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * The three configurations `ChallengePage.dc.html` ships, as data.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THESE THREE AND NOT A PRETTIER SET
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * They are the DC's own `CH` object, value for value. The handoff requires
 * `ChallengeCopy` to be unit-tested against them, and a test is only evidence that
 * the port is faithful if its inputs are the ones the design was drawn with — a
 * fixture invented here would prove the copy agrees with itself.
 *
 * Between them they cover every branch `derive()` has: three actions (nominate,
 * refer), three modes (first, top, draw), three prize types (cash_each, tickets,
 * points), with art and without, with a flag and without, a cap and no cap.
 *
 * THIS IS NOT A SEEDER AND NOT THE SANDBOX. `DemoSeeder` creates rows a visitor must
 * never meet; this is a fixture the admin builder and the tests read. Nothing here
 * is written to a live table by itself.
 */
final class ChallengeDemo
{
    /**
     * @return array<string,array<string,mixed>> keyed by the DC's own prop value
     */
    public static function all(): array
    {
        return [
            'nigeria' => [
                'slug' => 'celebrate-nigeria',
                'title' => 'Celebrate Nigeria',
                'kicker' => 'Independence Day challenge',
                'flag' => true,
                'art_url' => '/assets/img/challenges/alimosho-celebrates-nigeria.png',
                'art_alt' => 'Àlímọ̀ṣọ́ celebrates Nigeria artwork',
                'theme' => ChallengeEnum::THEME_GREEN,
                'action' => ChallengeEnum::ACTION_NOMINATE,
                'target' => 10,
                'cap' => 11,
                'mode' => ChallengeEnum::MODE_FIRST,
                'prize_type' => ChallengeEnum::PRIZE_CASH_EACH,
                'prize_amount' => 6000,
                'prize_currency' => '₦',
                'award' => 'Alimosho Awards 2026',
                'cats' => 'Choral, Business or Impact',
                'ends' => '15 Oct, 23:59 WAT',
                'days' => 14,
                'terms_version' => '1.2',
                'claimed' => 7,
                'mine' => 6,
                'area' => 'Alimosho',
            ],
            'teachers' => [
                'slug' => 'teachers-day',
                'title' => 'Thank a teacher',
                'kicker' => 'World Teachers’ Day',
                'icon' => 'M3 10 12 5l9 5-9 5zM7 12v4c0 1.5 2.2 3 5 3s5-1.5 5-3v-4',
                'theme' => ChallengeEnum::THEME_BLUE,
                'action' => ChallengeEnum::ACTION_NOMINATE,
                'target' => 5,
                'cap' => 0,
                'mode' => ChallengeEnum::MODE_DRAW,
                'prize_type' => ChallengeEnum::PRIZE_TICKETS,
                'prize_amount' => 2,
                'prize_label' => 'gala tickets',
                'draw_count' => 20,
                'award' => 'Africa GATES Education Awards · 4th Edition',
                'cats' => 'Teacher of the Year or STEM Educator',
                'ends' => '12 Oct, 23:59 WAT',
                'days' => 11,
                'terms_version' => '1.0',
                'claimed' => 0,
                'mine' => 2,
                'draw_date' => '14 Oct',
            ],
            'gala' => [
                'slug' => 'bring-five',
                'title' => 'Bring five to the gala',
                'kicker' => 'Referral challenge',
                'icon' => 'M16 11a4 4 0 1 0-8 0M3 21a9 9 0 0 1 18 0M19 8v6M22 11h-6',
                'theme' => ChallengeEnum::THEME_GOLD,
                'action' => ChallengeEnum::ACTION_REFER,
                'target' => 5,
                'cap' => 3,
                'mode' => ChallengeEnum::MODE_TOP,
                'prize_type' => ChallengeEnum::PRIZE_POINTS,
                'prize_amount' => 5000,
                'award' => 'KCEA 11th Edition ceremony',
                'ends' => '30 Nov, 23:59 EAT',
                'days' => 60,
                'terms_version' => '1.0',
                'claimed' => 0,
                'mine' => 3,
            ],
        ];
    }

    /** One config, or the launch one when a caller asks for something unknown. */
    public static function get(string $key): array
    {
        return self::all()[$key] ?? self::all()['nigeria'];
    }
}
