<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;

/**
 * What can actually be checked about a Nigerian non-profit's registration numbers.
 *
 * ── THE HONEST ANSWER FIRST ──────────────────────────────────────────────────
 *
 * There is no free public API for the CAC register. The Corporate Affairs Commission runs a
 * public SEARCH — a web page a human uses — and the programmatic access that exists is
 * third-party (Dojah, MetaMap and similar KYC vendors) and paid. SCUML has no public lookup
 * at all; a SCUML certificate is a document an organisation holds, and the only check
 * available to us is that it exists, is in date, and names the same organisation.
 *
 * So this class does three real things and refuses to imply a fourth:
 *
 *   1. VALIDATES THE SHAPE of a number, which catches typos and pasted rubbish but proves
 *      nothing about existence. `format()` says so in its return value rather than in a
 *      comment nobody reads.
 *   2. BUILDS THE SEARCH URL so a reviewer verifies in one click instead of retyping a
 *      number into a portal — the single highest-leverage thing available, because the
 *      manual check is the check.
 *   3. CALLS A VERIFIER IF ONE IS CONFIGURED. Optional, off by default, and its absence is
 *      reported as `unchecked` rather than as a pass.
 *
 * A number that has only been stored is `unchecked`, never `verified`. The difference is
 * the whole point: a vetting record that cannot distinguish "we looked" from "we typed it
 * in" is a record that will be quoted in an argument it cannot survive.
 */
final class RegistryCheck
{
    /** Nothing was asked of any registry. */
    public const UNCHECKED = 'unchecked';
    /** A human confirmed it against the public register, and said so. */
    public const CONFIRMED = 'confirmed';
    /** A configured verifier matched it. */
    public const VERIFIED  = 'verified';
    /** A configured verifier said no, or a human did. */
    public const REJECTED  = 'rejected';

    public const STATES = [
        self::UNCHECKED => 'Not checked',
        self::CONFIRMED => 'Confirmed by a reviewer',
        self::VERIFIED  => 'Verified against the register',
        self::REJECTED  => 'Rejected',
    ];

    /**
     * The CAC public search, as a page a human can open.
     *
     * Kept as the fallback for the case that matters most: when the search endpoint below is
     * unreachable, a reviewer still needs somewhere to go. It is offered as an escape hatch
     * rather than as the primary route — see searchCac() for why leaving the vetting record
     * to check a number in another tab is the weak point it is.
     */
    public const CAC_SEARCH = 'https://search.cac.gov.ng/home';

    /**
     * The endpoint the register is searched through, overridable with `CAC_SEARCH_API`.
     *
     * A default rather than a promise. The Commission publishes no documented free API, so
     * an operator may well have to point this at a licensed reseller — which is precisely
     * why it is one environment variable and a tolerant parser rather than a hard-coded
     * integration with one vendor's payload.
     */
    public const CAC_SEARCH_API = 'https://search.cac.gov.ng/api/public/company/search';

    /**
     * Does this look like a CAC registration number?
     *
     * Nigerian registrations carry a prefix for their kind — RC for a limited company, BN
     * for a business name, IT for Incorporated Trustees, which is the one that matters here
     * because it is the only structure a non-profit can hold property under (Part F,
     * ss.825–829 CAMA 2020).
     *
     * Shape only. A well-formed number that belongs to nobody passes this and must still be
     * checked by a person.
     *
     * @return array{ok:bool,kind:string,normalised:string,message:string,nonprofit:bool}
     */
    public static function cacFormat(string $raw): array
    {
        $s = strtoupper(trim($raw));
        $s = preg_replace('/[^A-Z0-9]/', '', $s) ?? '';

        if ($s === '') {
            return ['ok' => false, 'kind' => '', 'normalised' => '', 'nonprofit' => false,
                    'message' => 'No registration number given.'];
        }

        if (!preg_match('/^(RC|BN|IT)(\d{1,10})$/', $s, $m)) {
            return ['ok' => false, 'kind' => '', 'normalised' => $s, 'nonprofit' => false,
                    'message' => 'That does not look like a CAC number. They begin RC, BN or IT '
                               . 'followed by digits — an incorporated trustee is IT.'];
        }

        $kind = $m[1];
        return [
            'ok'         => true,
            'kind'       => $kind,
            'normalised' => $kind . '/' . $m[2],
            'nonprofit'  => $kind === 'IT',
            'message'    => $kind === 'IT'
                ? ''
                : 'This is a ' . ($kind === 'RC' ? 'limited company' : 'business name')
                  . ' registration, not an incorporated trustee. A non-profit collecting '
                  . 'donations is normally registered under Part F as IT — worth asking about.',
        ];
    }

    /**
     * Does this look like a SCUML number?
     *
     * SCUML issues a certificate rather than a number in a documented public format, so this
     * is deliberately permissive: it rejects blanks and obvious rubbish and nothing else.
     * A stricter pattern invented from a handful of examples would reject valid certificates
     * and teach whoever hits it to type something that passes, which is worse than no check.
     *
     * @return array{ok:bool,normalised:string,message:string}
     */
    public static function scumlFormat(string $raw): array
    {
        $s = strtoupper(trim($raw));
        $s = preg_replace('/\s+/', '', $s) ?? '';

        if (strlen($s) < 4) {
            return ['ok' => false, 'normalised' => $s,
                    'message' => 'Enter the SCUML certificate number as printed on the certificate.'];
        }
        if (!preg_match('/^[A-Z0-9\/\-]+$/', $s)) {
            return ['ok' => false, 'normalised' => $s,
                    'message' => 'A SCUML number is letters, digits, slashes and hyphens.'];
        }
        return ['ok' => true, 'normalised' => $s, 'message' => ''];
    }

    /** Where a reviewer goes to check a CAC number by hand, in one click. */
    public static function cacSearchUrl(string $number = ''): string
    {
        $f = self::cacFormat($number);
        return $f['ok']
            ? self::CAC_SEARCH . '?' . http_build_query(['q' => $f['normalised']])
            : self::CAC_SEARCH;
    }

    /**
     * Search the CAC register from this site, rather than sending a reviewer to theirs.
     *
     * ── WHY THIS IS WORTH THE TROUBLE ────────────────────────────────────────
     *
     * The one-click link to search.cac.gov.ng worked and was quietly the weakest part of
     * onboarding, because of what happens after the click: a reviewer leaves the vetting
     * record, searches in a tab, reads a result, comes back, and presses "I checked this"
     * from memory. Nothing connects the two. There is no record of WHAT they saw, so a
     * mistyped digit, a near-identical name, or a reviewer who got distracted and clicked
     * confirm all leave exactly the same trace.
     *
     * Searching here means the result lands beside the number it is about, the registered
     * name can be compared without retyping it, and what was returned is what gets stored.
     *
     * ── AND WHY IT IS HONEST ABOUT BEING A SEARCH ────────────────────────────
     *
     * The Commission publishes no free API and no documented contract. This calls a
     * CONFIGURABLE endpoint and reads a deliberately loose shape, because the alternative —
     * baking one provider's payload in — breaks the day they change a field name and takes
     * onboarding down with it. Results are CANDIDATES a human still has to pick from, never
     * an automatic verdict, and a search that fails says so rather than returning nothing
     * and letting the empty list read as "no such company".
     *
     * @return array{ok:bool,results:array<int,array<string,string>>,message:string,live:bool}
     */
    public static function searchCac(string $query, ?callable $http = null): array
    {
        $q = trim($query);
        if (mb_strlen($q) < 3) {
            return ['ok' => false, 'results' => [], 'live' => false,
                    'message' => 'Type at least three characters of a name or a registration number.'];
        }

        $endpoint = trim((string) Env::get('CAC_SEARCH_API', self::CAC_SEARCH_API));
        if ($endpoint === '') {
            return ['ok' => false, 'results' => [], 'live' => false,
                    'message' => 'Register search is switched off on this deployment. '
                               . 'Set CAC_SEARCH_API to enable it.'];
        }

        $url = $endpoint . (str_contains($endpoint, '?') ? '&' : '?')
             . http_build_query(['searchTerm' => $q, 'limit' => 10]);

        try {
            $headers = ['Accept: application/json'];
            $key = trim((string) Env::get('CAC_VERIFY_KEY', ''));
            if ($key !== '') $headers[] = 'Authorization: Bearer ' . $key;

            $body = $http ? (string) $http($url, $key) : self::get($url, $headers);
        } catch (\Throwable $e) {
            // Unreachable is UNKNOWN, never "no such company". On a screen where somebody is
            // deciding whether a charity is real, an outage that reads as a refusal is the
            // most dangerous possible failure.
            return ['ok' => false, 'results' => [], 'live' => false,
                    'message' => 'Could not reach the register just now (' . $e->getMessage()
                               . '). That is not the same as the company not existing.'];
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return ['ok' => false, 'results' => [], 'live' => false,
                    'message' => 'The register returned something this platform could not read.'];
        }

        $rows = self::rowsIn($json);
        if ($rows === []) {
            return ['ok' => true, 'results' => [], 'live' => true,
                    'message' => 'The register returned nothing for that. Check the spelling, or '
                               . 'try the registration number on its own.'];
        }

        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $name = self::pick($r, ['approvedName', 'companyName', 'company_name', 'name', 'businessName']);
            if ($name === '') continue;

            $out[] = [
                'name'    => mb_substr($name, 0, 200),
                'rc'      => mb_substr(self::pick($r, ['rcNumber', 'rc_number', 'registrationNumber', 'number', 'rcNo']), 0, 60),
                'type'    => mb_substr(self::pick($r, ['classification', 'companyType', 'type', 'entityType']), 0, 80),
                'status'  => mb_substr(self::pick($r, ['companyStatus', 'status', 'state']), 0, 60),
                'address' => mb_substr(self::pick($r, ['address', 'registeredAddress', 'companyAddress']), 0, 250),
                'date'    => mb_substr(self::pick($r, ['registrationDate', 'registration_date', 'dateOfRegistration']), 0, 40),
            ];
            if (count($out) >= 10) break;
        }

        return ['ok' => true, 'results' => $out, 'live' => true,
                'message' => count($out) . ' result(s) from the register. These are candidates — '
                           . 'the name and the number both have to match before you confirm.'];
    }

    /**
     * Find the list inside whatever the provider wrapped it in.
     *
     * @return array<int,mixed>
     */
    private static function rowsIn(array $json): array
    {
        // A plain list, already.
        if (array_is_list($json) && $json !== [] && is_array($json[0] ?? null)) return $json;

        foreach (['data', 'items', 'results', 'content', 'companies', 'records'] as $k) {
            $v = $json[$k] ?? null;
            if (is_array($v) && array_is_list($v)) return $v;
            // One level of nesting, which is where half of them put it.
            if (is_array($v)) {
                foreach (['items', 'results', 'content', 'data'] as $k2) {
                    if (is_array($v[$k2] ?? null) && array_is_list($v[$k2])) return $v[$k2];
                }
            }
        }
        return [];
    }

    /** @param array<string,mixed> $row @param array<int,string> $keys */
    private static function pick(array $row, array $keys): string
    {
        foreach ($keys as $k) {
            $v = $row[$k] ?? null;
            if (is_string($v) && trim($v) !== '') return trim($v);
            if (is_int($v) || is_float($v))       return (string) $v;
        }
        return '';
    }

    /** Is a programmatic verifier configured at all? */
    public static function verifierAvailable(): bool
    {
        return trim((string) Env::get('CAC_VERIFY_URL', '')) !== ''
            && trim((string) Env::get('CAC_VERIFY_KEY', '')) !== '';
    }

    /**
     * Ask a configured verifier about a CAC number.
     *
     * ── PLUGGABLE, BECAUSE THE MARKET IS ────────────────────────────────────
     *
     * The providers here are third parties reselling access to a public register, and which
     * one an operator uses is a commercial decision that will change. So this speaks to a
     * URL and a key from the environment and reads a deliberately small response shape,
     * rather than baking one vendor's payload into the platform.
     *
     * Unreachable is `unchecked`, never `rejected`. An outage must not look like a refusal
     * on a screen where somebody is about to decide whether a charity is real.
     *
     * @return array{state:string,name:string,message:string}
     */
    public static function verifyCac(string $number, ?callable $http = null): array
    {
        $f = self::cacFormat($number);
        if (!$f['ok']) {
            return ['state' => self::UNCHECKED, 'name' => '', 'message' => $f['message']];
        }
        if (!self::verifierAvailable()) {
            return ['state' => self::UNCHECKED, 'name' => '',
                    'message' => 'No registry verifier is configured, so this number has been '
                               . 'stored but not checked. Confirm it against the CAC public search.'];
        }

        $url = rtrim((string) Env::get('CAC_VERIFY_URL', ''), '/')
             . '?' . http_build_query(['rc_number' => $f['normalised']]);
        $key = (string) Env::get('CAC_VERIFY_KEY', '');

        try {
            $body = $http
                ? (string) $http($url, $key)
                : self::get($url, ['Authorization: Bearer ' . $key, 'Accept: application/json']);
        } catch (\Throwable $e) {
            return ['state' => self::UNCHECKED, 'name' => '',
                    'message' => 'Could not reach the registry verifier: ' . $e->getMessage()];
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return ['state' => self::UNCHECKED, 'name' => '',
                    'message' => 'The registry verifier returned something unreadable.'];
        }

        // A small, tolerant shape. Vendors nest their payloads differently and all of them
        // put a company name somewhere; anything more specific breaks on the first provider
        // change and takes onboarding down with it.
        $data = is_array($json['data'] ?? null) ? $json['data'] : $json;
        $name = trim((string) ($data['company_name'] ?? ($data['name'] ?? '')));

        if ($name === '') {
            return ['state' => self::REJECTED, 'name' => '',
                    'message' => 'The registry has no company against that number.'];
        }
        return ['state' => self::VERIFIED, 'name' => $name, 'message' => ''];
    }

    /** Minimal GET. Its own, because PaymentService's chokepoint is Paystack-shaped. */
    private static function get(string $url, array $headers): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new \RuntimeException($err !== '' ? $err : 'transport error');
        return (string) $raw;
    }
}
