<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * Turns a plain-English query ("high-quality pending STEM nominations from
 * Kenya this month") into the SAFE, whitelisted filter params the nominations
 * list already understands. The AI only ever proposes values — every field is
 * validated here against a fixed allow-list before it can touch a query, so a
 * misbehaving model can't inject anything. Returns null when no AI provider is
 * configured (the caller falls back to a plain text search), so the feature is
 * a pure accelerant, never a dependency.
 */
final class AiFilterService
{
    /** @return array<string,string>|null validated filter params, or null when AI is unavailable */
    public static function parseNominationFilter(string $query, ?AiService $ai = null): ?array
    {
        $query = trim($query);
        if ($query === '') return null;
        $system = 'You convert an admin\'s plain-English request into filters for a nominations list. '
            . 'Reply ONLY with JSON using any of these keys (omit those not implied): '
            . '{"status":"pending|approved|rejected|all", '
            . '"country":"<ISO 3166-1 alpha-2, e.g. NG, KE, ZA>", '
            . '"range":"today|yesterday|7d|week|30d|month|year", '
            . '"sort":"newest|oldest", '
            . '"q":"<a name or keyword to search, or omit>"}. '
            . 'Map country NAMES to their ISO2 code. Do not invent fields or values outside these options.';
        // Through the gateway for the budget, the kill switch and the record.
        // The output contract is unchanged: sanitize() is the schema, and an
        // unexpected shape is discarded rather than coerced — the pattern every
        // other capability now follows.
        $r = (new AiGateway($ai))->run('admin.filter_parse', [
            'system'      => $system,
            'user'        => $query,
            'json'        => true,
            'temperature' => 0.0,
            'schema'      => static function (string $raw): ?array {
                $j = json_decode($raw, true);
                if (!is_array($j)) return null;
                $out = self::sanitize($j);
                // An empty result means the model proposed nothing valid; that is
                // a miss, not a filter, so the caller should fall back to search.
                return $out === [] ? null : $out;
            },
        ]);
        return $r->ok ? $r->value : null;
    }

    /**
     * Whitelist-validate a raw {status,country,range,sort,q} object into filter
     * params. Public + pure so it is unit-testable without an AI provider.
     *
     * @return array<string,string>
     */
    public static function sanitize(array $j): array
    {
        $out = [];

        $status = strtolower(trim((string) ($j['status'] ?? '')));
        if (in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) $out['status'] = $status;

        $country = strtoupper((string) preg_replace('/[^A-Za-z]/', '', (string) ($j['country'] ?? '')));
        if (strlen($country) === 2) $out['country'] = $country;

        $range = strtolower(trim((string) ($j['range'] ?? '')));
        if (in_array($range, ['today', 'yesterday', '7d', 'week', '30d', 'month', 'year'], true)) $out['range'] = $range;

        $sort = strtolower(trim((string) ($j['sort'] ?? '')));
        if (in_array($sort, ['newest', 'oldest'], true)) $out['sort'] = $sort;

        $q = trim((string) ($j['q'] ?? ''));
        if ($q !== '') $out['q'] = mb_substr($q, 0, 80);

        return $out;
    }
}
