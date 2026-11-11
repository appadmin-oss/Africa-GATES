<?php
declare(strict_types=1);

namespace AfricaGates\Services\Ads;

/**
 * How well an ad is put together, and the one or two things that would make it better.
 *
 * Owner, 11 Nov 2026: "research top services too … improve the ads". Google shows an "ad
 * strength" beside every responsive ad (Poor, Average, Good, Excellent) with the changes
 * that would raise it; Meta's Ads Manager does the same as "recommendations". Both exist
 * for the same reason: a first-time advertiser cannot tell a weak ad from a strong one
 * until they have paid to find out, and most of what separates them is knowable in
 * advance — a concrete offer, a specific number, a short headline, a button that matches
 * what they are asking people to do.
 *
 * WHAT IT IS NOT. It is not a prediction, and it moves NOTHING: not the price, not how
 * often the ad is shown, not the review. Google says the same of its own score. An ad
 * scored "Poor" runs exactly as often as one scored "Excellent"; what decides which of an
 * advertiser's versions is shown more is what people actually tapped (AdCampaigns::leader),
 * never this. If it ever fed delivery, an advertiser would be paying for a heuristic's
 * opinion of their copy, which is the opposite of the measured market this platform sells.
 *
 * One implementation. The builder's live panel asks the server (AdsController::check)
 * rather than carrying a second copy of these rules in JavaScript, because two scorers is
 * how the panel and the campaign page come to disagree about the same ad.
 *
 * Each check is worth points out of 100, and the tips are the checks that failed, heaviest
 * first, so the first tip shown is always the change worth most.
 */
final class AdStrength
{
    public const LEVELS = [
        'poor'      => 'Poor',
        'average'   => 'Average',
        'good'      => 'Good',
        'excellent' => 'Excellent',
    ];

    /** Meta's recommended headline length: longer ones are cut on a phone in its feeds. */
    public const HEADLINE_IDEAL = 27;
    /** Below this the text says too little to give a reason to tap. */
    public const BODY_IDEAL_MIN = 40;

    /** The buttons that ask for the thing a selling campaign wants. */
    private const SELLING_CTAS = ['shop_now', 'get_tickets', 'book_now', 'whatsapp', 'contact_us'];

    /**
     * @param array<string,mixed> $ad headline, body, cta, offer, url (as AdRules::creative() cleans them)
     * @return array{score:int, level:string, label:string, tips:list<string>, checks:int, passed:int}
     */
    public static function score(array $ad, string $objective, bool $hasImage, bool $hasLogo): array
    {
        $headline = trim((string) ($ad['headline'] ?? ''));
        $body = trim((string) ($ad['body'] ?? ''));
        $offer = trim((string) ($ad['offer'] ?? ''));
        $cta = (string) ($ad['cta'] ?? 'learn_more');
        $all = $headline . ' ' . $body . ' ' . $offer;

        // [points, passed, the tip when it did not], in the order a reader meets each part
        // of the card: the picture, the headline under it, the text, the offer, the button.
        $checks = [
            [25, $hasImage, 'Add a square image. It is the first thing people see, and an ad cannot run without one.'],
            [10, $headline !== '' && mb_strlen($headline) <= self::HEADLINE_IDEAL, sprintf('Shorten the headline to %d characters or fewer. Short headlines are read in full on a phone.', self::HEADLINE_IDEAL)],
            [15, mb_strlen($body) >= self::BODY_IDEAL_MIN, 'Say a little more in the text: what you offer, and why it is worth a look.'],
            [15, $offer !== '', 'Add an offer that is open to everyone, like “10% off this week” or “free delivery in Lagos”. A concrete offer gives people a reason to tap now.'],
            [5, self::ctaFits($cta, $objective), 'Change the button to Shop now, Get tickets, Book now or Chat on WhatsApp. “Learn more” asks for the least, and selling needs a clearer ask.'],
            [10, $hasLogo, 'Add your logo. Without one the ad shows your initials, and a logo is what makes it look like a real business.'],
            [10, self::isSpecific($all), 'Be specific: a price, a time or a number (“from ₦3,500”, “open till 10pm”, “500+ happy customers”) tells people what to expect.'],
            [5, $headline === '' || !self::repeats($headline, $body), 'Use the headline to say something the text does not. Repeating it wastes the line people read most.'],
            [5, !self::shouts($all), 'Write in sentence case, without capitals or exclamation marks. Shouting reads as spam and people scroll past it.'],
        ];

        $score = 0;
        $passed = 0;
        $failed = [];
        foreach ($checks as $i => [$pts, $ok, $tip]) {
            if ($ok) {
                $score += $pts;
                $passed++;
            } else {
                $failed[] = [$pts, $i, $tip];
            }
        }
        // Heaviest first, so the first tip is the change worth most; among equals, the
        // order above.
        usort($failed, static fn ($a, $b) => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        $level = match (true) {
            $score >= 85 => 'excellent',
            $score >= 70 => 'good',
            $score >= 50 => 'average',
            default      => 'poor',
        };
        return ['score' => $score, 'level' => $level, 'label' => self::LEVELS[$level],
                'tips' => array_map(static fn ($f) => $f[2], $failed),
                'checks' => count($checks), 'passed' => $passed];
    }

    /** A digit anywhere (a price, an hour, a count) or a naira sign. */
    private static function isSpecific(string $s): bool
    {
        return preg_match('/[0-9₦]/u', $s) === 1;
    }

    private static function ctaFits(string $cta, string $objective): bool
    {
        return $objective !== 'sales' || in_array($cta, self::SELLING_CTAS, true);
    }

    /** The headline is the text, or sits inside it word for word. */
    private static function repeats(string $headline, string $body): bool
    {
        $n = static fn (string $s): string => trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($s)));
        $h = $n($headline);
        return $h !== '' && str_contains(' ' . $n($body) . ' ', ' ' . $h . ' ');
    }

    /**
     * Two exclamation marks, or a run of words in capitals. Acronyms are not shouting —
     * an ad here may well name ARCON, NAFDAC, VAT and a brand like KFC in one line — so it
     * takes three capitalised words IN A ROW ("BEST JOLLOF IN LAGOS"), or more than half of
     * all the letters, before it counts.
     */
    private static function shouts(string $s): bool
    {
        if (preg_match('/!.*!/u', $s) === 1) return true;
        if (preg_match('/\b\p{Lu}{2,}(?:[\s,.-]+\p{Lu}{2,}){2,}\b/u', $s) === 1) return true;
        $letters = preg_replace('/[^\p{L}]/u', '', $s) ?? '';
        $upper = preg_replace('/[^\p{Lu}]/u', '', $s) ?? '';
        return mb_strlen($letters) >= 12 && mb_strlen($upper) > mb_strlen($letters) / 2;
    }
}
