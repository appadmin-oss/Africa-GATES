<?php
declare(strict_types=1);

namespace AfricaGates\Services\Ads;

use AfricaGates\Services\BallotGuard;
use AfricaGates\Services\NomineeClaimService;
use AfricaGates\Support\NationsLive;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * What an ad is allowed to be, where it may run, and the free trial: every rule in one place,
 * so the builder that states a rule and the code that enforces it read the same constant.
 *
 * The model is Meta's (owner, 27 Oct 2026): campaign → ad set → ad, one standard format, a
 * published rate card, prepaid, reviewed before it runs, always labelled. There is no free
 * promotion. The only free thing is one TRIAL per account, and it is a number of views, not
 * money, because the owner's rule is no wallet and no credit: nothing here holds a balance.
 *
 * ── THE NOMINEE RULES (owner, 27 Oct 2026) ───────────────────────────────────
 *
 * Nominees are not promoted, free or paid, while votes are cast (D15). A nominee may still
 * advertise WHAT THEY DO, under four rules:
 *
 *   1. the ad is about their business, never about the award, a nominee or a ballot;
 *   2. an offer is open to everyone and never depends on voting;
 *   3. while their own award is open, their ads stay off the vote page;
 *   4. they pay the same rate card as everybody else.
 *
 * Rule 1 is checked on the words and the link (flags()), and a person makes the final call.
 * Rule 2 refuses outright. Rule 3 is applied when an ad is chosen (AdDelivery), not when it is
 * approved, because an award can open after an ad was approved. Rule 4 is the absence of any
 * price that depends on who is asking.
 */
final class AdRules
{
    /** Where ads may run, and what the advertiser is told each one is. The vote page is its list only. */
    public const PLACEMENTS = [
        'vote_hub' => 'Vote page',
        'discover' => 'Discover',
        'events'   => 'Events',
    ];

    /** Meta's three simplest objectives. The objective decides which result the manager leads with. */
    public const OBJECTIVES = [
        'awareness' => 'Be seen',
        'traffic'   => 'Get visits',
        'sales'     => 'Sell tickets or products',
    ];

    /** The buttons an ad may carry, as Meta offers them. */
    public const CTAS = [
        'learn_more'  => 'Learn more',
        'shop_now'    => 'Shop now',
        'get_tickets' => 'Get tickets',
        'book_now'    => 'Book now',
        'contact_us'  => 'Contact us',
        'sign_up'     => 'Sign up',
    ];

    // The one standard format: a square image, a headline, a line of text, a button.
    public const HEADLINE_MAX = 40;
    public const BODY_MIN     = 10;
    public const BODY_MAX     = 125;
    public const NAME_MAX     = 60;
    public const OFFER_MAX    = 40;
    public const CODE_MAX     = 20;
    public const IMAGE_MIN    = 600;
    public const ADS_PER_SET  = 3;

    // The free trial (owner, 27 Oct 2026): one per account, 7 days or 1,000 views, whichever
    // comes first. Every other rule applies to it: the review, the label, the reporting.
    public const TRIAL_DAYS  = 7;
    public const TRIAL_VIEWS = 1000;

    /** Seen by one person, on one ad, at most this many times a day. */
    public const FREQUENCY_CAP = 3;

    /** The longest a paid campaign may be booked for. */
    public const MAX_DAYS = 90;

    /**
     * Words that mean an ad may be about an award, a nominee or voting. A FLAG for the
     * reviewer, not a refusal: "award-winning jollof" is a restaurant, and only a person can
     * tell it apart from "vote for me".
     */
    private const AWARD_WORDS = ['vote', 'voting', 'votes', 'ballot', 'nominee', 'nominated', 'nomination',
                                 'award', 'awards', 'shortlist', 'finalist', 'support me'];

    /**
     * Validate the creative. Returns the clean row and the errors, keyed by field.
     *
     * @param array<string,mixed> $in
     * @return array{row:array<string,mixed>, errors:array<string,string>}
     */
    public static function creative(array $in): array
    {
        $e = [];
        $headline = self::line((string) ($in['headline'] ?? ''));
        $body     = self::line((string) ($in['body'] ?? ''));
        $cta      = (string) ($in['cta'] ?? 'learn_more');
        $url      = trim((string) ($in['url'] ?? ''));
        $offer    = self::line((string) ($in['offer'] ?? ''));
        $code     = strtoupper(preg_replace('/\s+/', '', (string) ($in['offer_code'] ?? '')) ?? '');

        $hl = mb_strlen($headline);
        if ($hl < 3 || $hl > self::HEADLINE_MAX) $e['headline'] = sprintf('A headline of 3 to %d characters.', self::HEADLINE_MAX);
        $bl = mb_strlen($body);
        if ($bl < self::BODY_MIN || $bl > self::BODY_MAX) $e['body'] = sprintf('Text of %d to %d characters.', self::BODY_MIN, self::BODY_MAX);
        if (!isset(self::CTAS[$cta])) $e['cta'] = 'Choose a button.';

        $safe = self::safeUrl($url);
        if ($safe === null) $e['url'] = 'Use a full https:// address.';
        elseif (self::pointsAtANominee($safe)) $e['url'] = 'An ad cannot link to a nominee, a ballot or a result.';

        if (mb_strlen($offer) > self::OFFER_MAX) $e['offer'] = sprintf('Keep the offer under %d characters.', self::OFFER_MAX);
        if ($offer !== '' && self::mentionsVoting($offer)) $e['offer'] = 'An offer must be open to everyone, not tied to voting.';
        if ($code !== '' && preg_match('/^[A-Z0-9-]{3,' . self::CODE_MAX . '}$/', $code) !== 1) $e['offer_code'] = 'A code of letters and numbers.';
        if ($code !== '' && $offer === '') $e['offer'] = 'Say what the code gives.';

        return ['row' => [
            'headline' => $headline, 'body' => $body, 'cta' => $cta, 'url' => (string) $safe,
            'offer' => $offer !== '' ? $offer : null, 'offer_code' => $code !== '' ? $code : null,
        ], 'errors' => $e];
    }

    /**
     * What the reviewer should look at twice, worked out when the ad is submitted. Never a
     * refusal on its own: the person deciding sees the flags beside the ad.
     *
     * @param array<string,mixed> $ad
     * @return list<string>
     */
    public static function flags(array $ad, int $userId): array
    {
        $f = [];
        $text = (string) ($ad['headline'] ?? '') . ' ' . (string) ($ad['body'] ?? '') . ' ' . (string) ($ad['offer'] ?? '');
        if (self::mentionsVoting($text)) $f[] = 'Mentions voting or an award';
        if (self::nomineeIds($userId) !== []) $f[] = 'Advertiser is a nominee';
        $url = (string) ($ad['url'] ?? '');
        if ($url !== '' && !str_starts_with($url, '/')) $f[] = 'Links to ' . (string) (parse_url($url, PHP_URL_HOST) ?? 'another site');
        return $f;
    }

    public static function mentionsVoting(string $text): bool
    {
        $t = ' ' . mb_strtolower((string) preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $text)) . ' ';
        foreach (self::AWARD_WORDS as $w) {
            if (str_contains($t, ' ' . $w . ' ')) return true;
        }
        return false;
    }

    /** https://host/…, or a path on this site; nothing else ever reaches an href. */
    public static function safeUrl(string $u): ?string
    {
        $u = trim($u);
        if (preg_match('{^/(?!/)[A-Za-z0-9/_.~%?=&+#-]*$}', $u) === 1) return substr($u, 0, 400);
        $p = parse_url($u);
        if (!is_array($p) || strtolower((string) ($p['scheme'] ?? '')) !== 'https') return null;
        $host = strtolower((string) ($p['host'] ?? ''));
        if ($host === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $host) || isset($p['user']) || isset($p['pass'])) return null;
        return strlen($u) <= 400 ? $u : null;
    }

    /** A ballot, a nominee page, a claim or a result: never an ad's destination (D15). */
    public static function pointsAtANominee(string $u): bool
    {
        $path = str_starts_with($u, '/') ? $u : (string) (parse_url($u, PHP_URL_PATH) ?? '');
        $host = str_starts_with($u, '/') ? '' : strtolower((string) (parse_url($u, PHP_URL_HOST) ?? ''));
        // Only this site's own nominee pages; somebody else's "/vote" is their business.
        $ours = $host === '' || self::isOurHost($host);
        return $ours && preg_match('~^/(vote|claim|v|results|nominee|winners)(/|$)~i', $path) === 1;
    }

    private static function isOurHost(string $host): bool
    {
        $base = strtolower((string) (parse_url(\AfricaGates\Support\SiteUrl::base(), PHP_URL_HOST) ?? ''));
        return $base !== '' && ($host === $base || $host === 'www.' . $base || 'www.' . $host === $base);
    }

    /**
     * The countries an ad set may be aimed at: the ones the site names. An empty choice
     * means everywhere.
     *
     * @return array<string,string>
     */
    public static function countries(): array
    {
        $n = NationsLive::NAMES;
        asort($n);
        return $n;
    }

    /** @param mixed $raw @return list<string> */
    public static function cleanCountries(mixed $raw): array
    {
        $out = [];
        foreach ((array) $raw as $c) {
            $c = strtoupper(trim((string) $c));
            if (isset(NationsLive::NAMES[$c])) $out[$c] = $c;
        }
        return array_values($out);
    }

    /** @param mixed $raw @return list<string> */
    public static function cleanPlacements(mixed $raw): array
    {
        $out = [];
        foreach ((array) $raw as $p) {
            $p = (string) $p;
            if (isset(self::PLACEMENTS[$p])) $out[$p] = $p;
        }
        return array_values($out);
    }

    /**
     * The nominees this account holds, by an active claim. A claim is the platform's own
     * record that a person IS a nominee; an unclaimed nominee is not known to be anyone.
     *
     * @return list<int>
     */
    public static function nomineeIds(int $userId): array
    {
        if ($userId < 1 || !SchemaHas::table('gates_nominee_claims')) return [];
        try {
            return array_map('intval', DB::table('gates_nominee_claims')->where('user_id', $userId)
                ->where('status', NomineeClaimService::ST_ACTIVE)->pluck('nominee_id')->all());
        } catch (\Throwable) {
            return [];
        }
    }

    /** Rule 3: is this advertiser a nominee in an award that is taking votes right now? */
    public static function inOpenAward(int $userId): bool
    {
        $ids = self::nomineeIds($userId);
        if ($ids === []) return false;
        try {
            $cats = DB::table('gates_nominees')->whereIn('id', $ids)->pluck('category_id')->all();
        } catch (\Throwable) {
            return true; // cannot tell: keep them off the vote page rather than guess
        }
        foreach (array_unique(array_map('intval', $cats)) as $cat) {
            if ($cat > 0 && BallotGuard::isVotable($cat)) return true;
        }
        return false;
    }

    private static function line(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace(["\r", "\n"], ' ', $s)));
    }
}
