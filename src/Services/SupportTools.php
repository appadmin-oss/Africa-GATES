<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * The tools that reach outside this application, and the diagnostics that do not.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THESE FIVE, AND NOT A LONGER LIST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Each one answers a question that arrives constantly and that the assistant
 * previously had to guess at, apologise for, or escalate:
 *
 *   "is the payment page broken?"          → gatewayStatus()
 *   "my code never came"                   → emailDomain()
 *   "how much is that in dollars?"         → convertCurrency()
 *   "how do I vote for <person>?"          → findNominee()   (in SupportContext)
 *   "is <category> still open?"            → categoryState() (in SupportContext)
 *
 * A tool that does not remove a whole class of ticket is not worth the surface it
 * adds. Every one of these does.
 *
 * ── THE RULES FOR ANYTHING THAT LEAVES THIS SERVER ───────────────────────────
 *
 * A support bot is a page a stranger can make the server call things from, so the
 * outbound tools are deliberately boring:
 *
 *   NO KEYS         Both third-party endpoints are free and unauthenticated. There
 *                   is no credential here to leak, rotate or bill.
 *   NO USER INPUT   The URLs are constants. Nothing a member types is interpolated
 *                   into a request, so this cannot be turned into a proxy for
 *                   fetching arbitrary URLs on our behalf.
 *   NO PII OUT      Nobody's email, name, reference or amount is ever sent
 *                   outward. The FX call sends two currency codes from a fixed
 *                   allowlist; the status calls send nothing at all.
 *   SHORT TIMEOUT   Six seconds. A support reply that hangs is worse than one that
 *                   says "I could not check that" — the person is already waiting.
 *   CACHED HARD     Status for 2 minutes, rates for 6 hours. A hundred people
 *                   asking during an outage is one request, which is exactly when
 *                   the upstream can least afford a hundred.
 *   FAIL SOFT       Every failure returns a shaped answer the model can say out
 *                   loud. None of them throws, and none of them ever reports
 *                   "everything is fine" because a check did not run.
 */
final class SupportTools
{
    private const TIMEOUT = 6;

    /** Statuspage exposes a free, unauthenticated summary at a fixed path. */
    private const STATUS_URLS = [
        'Paystack'    => 'https://status.paystack.com/api/v2/status.json',
        'Flutterwave' => 'https://status.flutterwave.com/api/v2/status.json',
    ];

    /** Free, no key, and it carries NGN — which most ECB-based free APIs do not. */
    private const FX_URL = 'https://open.er-api.com/v6/latest/NGN';

    /** What we will quote. A short list because each one is a promise of accuracy. */
    private const FX_ALLOWED = ['USD', 'GBP', 'EUR', 'CAD', 'ZAR', 'GHS', 'KES', 'XOF', 'AED'];

    public function __construct(private readonly ?CacheService $cache = null) {}

    // ── is it them, or is it us? ─────────────────────────────────────────────

    /**
     * Are the payment gateways actually up?
     *
     * ── THE TICKET THIS DELETES ──────────────────────────────────────────────
     *
     * During a Paystack incident every supporter mid-checkout arrives at once,
     * all saying the same thing, and the honest answer — "the payment provider is
     * having an outage, your money is fine, try again in an hour" — was one the
     * assistant had no way to know. So it did the worst available thing: it
     * treated an infrastructure outage as a hundred separate personal problems,
     * asked each person for their reference, and escalated each one.
     *
     * Both providers publish a Statuspage summary, free and unauthenticated. Two
     * seconds of work turns that wave into one sentence.
     *
     * Cached for two minutes: during an incident this is the most-called tool on
     * the platform, and hammering a status page in an outage is antisocial.
     *
     * @return array{checked:bool, all_ok:bool, providers:array<string,array{ok:bool,say:string}>, say:string}
     */
    public function gatewayStatus(): array
    {
        return $this->remember('support:gwstatus', 120, function (): array {
            $out = []; $anyKnown = false; $allOk = true;

            foreach (self::STATUS_URLS as $name => $url) {
                $body = $this->get($url);
                if ($body === null) {
                    // UNKNOWN is not OK. Reporting a gateway healthy because its
                    // status page did not answer is the one lie this tool must
                    // never tell — it is the moment somebody is deciding whether
                    // to try paying again.
                    $out[$name] = ['ok' => true, 'say' => 'could not be checked just now'];
                    continue;
                }
                $anyKnown   = true;
                $indicator  = strtolower((string) ($body['status']['indicator'] ?? 'none'));
                $desc       = (string) ($body['status']['description'] ?? 'Operational');
                $ok         = $indicator === 'none';
                if (!$ok) $allOk = false;
                $out[$name] = ['ok' => $ok, 'say' => $desc];
            }

            if (!$anyKnown) {
                return ['checked' => false, 'all_ok' => true, 'providers' => $out,
                        'say' => 'I could not reach the payment providers\' status pages, so I cannot say '
                               . 'either way. Do not tell anyone the gateways are fine on the strength of this.'];
            }

            $bad = array_keys(array_filter($out, static fn(array $p) => !$p['ok']));
            return [
                'checked'   => true,
                'all_ok'    => $allOk,
                'providers' => $out,
                'say'       => $allOk
                    ? 'Both payment providers report normal service, so a failed payment is specific to '
                    . 'this person rather than an outage. Move on to their reference.'
                    : implode(' and ', $bad) . ' is reporting a live incident. Say so plainly: their money '
                    . 'is not lost, a payment that failed can be retried once it clears, and a payment that '
                    . 'went through will still be credited. Do not ask them to troubleshoot their own bank.',
            ];
        });
    }

    // ── why the code never arrived ───────────────────────────────────────────

    /**
     * Can this address actually receive mail?
     *
     * ── WHY THIS IS THE HIGHEST-VALUE DIAGNOSTIC HERE ────────────────────────
     *
     * "My six-digit code never arrived" is the commonest free-voting complaint,
     * and the advice for it — check spam, try again — is right often enough to be
     * useless. It gives the same answer to somebody whose mail is in spam and
     * somebody who typed `gmial.com`, and only one of those is going to work.
     *
     * A DNS MX lookup settles it in milliseconds. No third party, no key, no
     * network egress beyond a resolver: a domain with no MX record cannot receive
     * mail from anybody, and no amount of checking spam will change that.
     *
     * The typo check catches the rest. A near-miss on a common provider is by far
     * the likeliest cause of a code that "never arrived", and Levenshtein finds it
     * without a list of every possible mistake.
     *
     * ── AND IT NEVER LEAVES A LOCAL PART ─────────────────────────────────────
     *
     * Only the DOMAIN is looked at, and only the domain is returned. The address
     * itself is not logged, not cached and not echoed back — an assistant that
     * repeats somebody's email into a chat transcript has created a disclosure
     * out of a diagnostic.
     *
     * @return array{ok:bool, domain:string, deliverable:bool, reason:string, suggest:?string, say:string}
     */
    public function emailDomain(string $email): array
    {
        $email  = trim($email);
        $at     = strrpos($email, '@');
        $domain = $at === false ? '' : strtolower(substr($email, $at + 1));

        if ($domain === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'domain' => '', 'deliverable' => false, 'reason' => 'not_an_address',
                    'suggest' => null,
                    'say' => 'That is not a complete email address. Ask them to read it back carefully — '
                           . 'a missing character is the commonest reason a code goes nowhere.'];
        }

        // Typo FIRST. `gmial.com` may well resolve — somebody registered it — so an
        // MX check alone would call it deliverable and send the reader back to
        // their spam folder for a code that went to a stranger.
        $suggest = $this->nearMiss($domain);
        if ($suggest !== null) {
            return ['ok' => true, 'domain' => $domain, 'deliverable' => false, 'reason' => 'likely_typo',
                    'suggest' => $suggest,
                    'say' => 'That address ends in "' . $domain . '", which is one character away from "'
                           . $suggest . '". Almost certainly a typo — the code went somewhere real, just not '
                           . 'to them. Ask them to try again with the corrected address.'];
        }

        $hasMx = $this->hasMailExchanger($domain);
        if (!$hasMx) {
            return ['ok' => true, 'domain' => $domain, 'deliverable' => false, 'reason' => 'no_mx',
                    'suggest' => null,
                    'say' => 'The domain "' . $domain . '" has no mail server configured, so it cannot receive '
                           . 'email from anyone — this is not our delivery failing. Ask them to check the '
                           . 'spelling, or use a different address.'];
        }

        return ['ok' => true, 'domain' => $domain, 'deliverable' => true, 'reason' => 'accepts_mail',
                'suggest' => null,
                'say' => 'That domain accepts mail normally, so the address is fine and the code is very '
                       . 'likely in spam or promotions. Say that with confidence rather than as a guess, and '
                       . 'have them request a fresh code — only the newest one works.'];
    }

    /** True when the domain publishes an MX (or at least an A) record. */
    private function hasMailExchanger(string $domain): bool
    {
        try {
            if (checkdnsrr($domain, 'MX')) return true;
            // A bare A record is a legal, if unusual, mail destination. Treating it
            // as undeliverable would tell somebody with a small self-hosted domain
            // that their perfectly working address is broken.
            return checkdnsrr($domain, 'A');
        } catch (\Throwable) {
            return true;   // a resolver failure is not evidence against the user
        }
    }

    /**
     * One-slip misspellings of the providers almost everybody uses.
     *
     * ── WHY PLAIN LEVENSHTEIN IS NOT ENOUGH HERE ─────────────────────────────
     *
     * `gmial.com` is the single most common email typo in the world, and
     * `levenshtein('gmial.com', 'gmail.com')` is **2**, not 1 — a swap of two
     * adjacent letters costs two substitutions in the classic algorithm. A
     * distance-of-one rule therefore misses the exact case it exists for, which
     * is how this shipped failing its own most important example.
     *
     * Raising the threshold to 2 is the wrong fix: at these lengths it starts
     * matching real, distinct domains against each other. So transpositions get
     * their own test — same length and the same multiset of characters means the
     * letters were all typed and two arrived in the wrong order.
     *
     * Length-guarded first, so a short real domain is never read as a typo of a
     * long one.
     */
    private function nearMiss(string $domain): ?string
    {
        $common = ['gmail.com', 'yahoo.com', 'yahoo.co.uk', 'hotmail.com', 'outlook.com',
                   'icloud.com', 'live.com', 'aol.com', 'protonmail.com', 'googlemail.com'];
        if (in_array($domain, $common, true)) return null;

        foreach ($common as $c) {
            if (abs(strlen($c) - strlen($domain)) > 1) continue;
            // Insert, delete or substitute one character.
            if (levenshtein($domain, $c) === 1) return $c;
            // Two adjacent characters swapped: every letter is present, in very
            // nearly the right order. gmial → gmail, yahooo → yahoo's neighbours.
            if (self::isTransposition($domain, $c)) return $c;
        }
        return null;
    }

    /** Same letters, same length, and no more than one adjacent pair swapped. */
    private static function isTransposition(string $a, string $b): bool
    {
        if (strlen($a) !== strlen($b) || $a === $b) return false;

        $sa = str_split($a); sort($sa);
        $sb = str_split($b); sort($sb);
        if ($sa !== $sb) return false;   // different letters entirely

        // Exactly two positions differ, and swapping them makes the strings equal.
        $diff = [];
        for ($i = 0, $n = strlen($a); $i < $n; $i++) {
            if ($a[$i] !== $b[$i]) $diff[] = $i;
            if (count($diff) > 2) return false;
        }
        return count($diff) === 2
            && $a[$diff[0]] === $b[$diff[1]]
            && $a[$diff[1]] === $b[$diff[0]];
    }

    // ── what it costs where they live ────────────────────────────────────────

    /**
     * Naira into a diaspora supporter's own currency.
     *
     * Half the people backing a Nigerian nominee are not in Nigeria, and "how much
     * is ₦5,000?" is a genuine blocker rather than idle curiosity — somebody
     * deciding whether to buy votes is deciding in their own currency. The
     * assistant used to refuse the question outright.
     *
     * Free, unauthenticated, cached for six hours. The rate is INDICATIVE and the
     * copy says so, because the buyer's card issuer will use its own rate and a
     * support bot that quotes an exact figure has made a promise about somebody
     * else's bank.
     *
     * @return array{ok:bool, say:string, rate?:float, amount?:float, currency?:string}
     */
    public function convertCurrency(int $naira, string $to): array
    {
        $to = strtoupper(trim($to));
        if ($naira < 1) {
            return ['ok' => false, 'say' => 'Give me an amount in naira to convert.'];
        }
        if (!in_array($to, self::FX_ALLOWED, true)) {
            return ['ok' => false,
                    'say' => 'I can only convert to ' . implode(', ', self::FX_ALLOWED) . '. Quote the naira '
                           . 'figure and say their bank will apply its own rate.'];
        }

        $rates = $this->remember('support:fx', 21600, function (): array {
            $body = $this->get(self::FX_URL);
            $r    = $body['rates'] ?? null;
            return is_array($r) ? $r : [];
        });

        $rate = isset($rates[$to]) ? (float) $rates[$to] : 0.0;
        if ($rate <= 0) {
            return ['ok' => false,
                    'say' => 'I could not get a live exchange rate just now. Quote the naira amount and let '
                           . 'them convert it — do not guess a figure.'];
        }

        $converted = round($naira * $rate, 2);
        return [
            'ok' => true, 'rate' => $rate, 'amount' => $converted, 'currency' => $to,
            'say' => '₦' . number_format($naira) . ' is roughly ' . $to . ' '
                   . number_format($converted, 2) . ' at today\'s rate. Say "roughly" — their card issuer '
                   . 'will use its own rate and may add a fee, and an exact figure would be a promise '
                   . 'about somebody else\'s bank.',
        ];
    }

    // ── transport ────────────────────────────────────────────────────────────

    /**
     * One GET, JSON out, or null.
     *
     * Never throws and never surfaces a URL or a network error to the caller: the
     * caller's job is to phrase an outcome for a worried person, and "cURL error
     * 28 connecting to status.paystack.com" is not that.
     *
     * @return array<string,mixed>|null
     */
    private function get(string $url): ?array
    {
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                // A status page that redirects us somewhere else is a status page
                // we should stop listening to.
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ]);
            $raw  = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($raw === false || $code < 200 || $code >= 300) return null;
            $json = json_decode((string) $raw, true);
            return is_array($json) ? $json : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Cache when there is one; run it plainly when there is not (CLI, tests). */
    private function remember(string $key, int $ttl, callable $fn): array
    {
        if ($this->cache === null) return (array) $fn();
        try {
            return (array) $this->cache->remember($key, $ttl, $fn);
        } catch (\Throwable) {
            return (array) $fn();
        }
    }
}
