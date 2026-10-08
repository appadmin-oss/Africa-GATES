<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\ActivityFeedService;
use AfricaGates\Services\RateLimitService;
use AfricaGates\Services\SearchLanding;
use AfricaGates\Support\Env;
use AfricaGates\Support\Translator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * `GET /search.json?q=&scope=` — the search palette's data, as JSON (REFERENCE §7.1).
 *
 * Moved from `/search` on 8 Oct 2026 (owner, AUDIT 5 Oct Q13): the JSON has its own
 * address and `/search` is a page address that 301s to Discover. The history below is
 * the 3 Oct move off `/activity/search`; the reasoning — one endpoint — is unchanged.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * ONE URL, AND IT IS THIS ONE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Until 3 Oct 2026 the palette read `/activity/search`, and `/search` and `/find` were
 * 301 aliases to the HTML `/activity` page — so the address the handoff names for the
 * endpoint was a redirect to a page, and the endpoint lived under a page that the
 * redesign retires (GAPS C16, §3.6). The owner decided: `/search` IS the endpoint, the
 * aliases and `/activity/search` are retired. There is one search URL, which is the shape
 * the old router comment argued for ("one endpoint, one index, one set of promises") with
 * the right address on it.
 *
 * ── THE INDEX IS {@see ActivityFeedService}; THIS ONLY SHAPES IT ───────────
 *
 * That service is behaviour — the sources, what each is allowed to show, the sandbox
 * containment, the model's whitelisted reading of a query — and it is reused, not
 * copied. What is new here is presentation for the palette:
 *
 *  · GROUPED ON THE SERVER, by {@see ActivityFeedService::SCOPES}. The client never names
 *    a source (the inventory rule `ag-search.js` held, and `SearchScopeTest` sweeps the new
 *    script for it). The old endpoint delivered the scope map for the script to group
 *    with; grouping here means there is no map in JavaScript to drift and nothing for a
 *    client to get wrong. Groups come in chip order, at most six items each, and a kind no
 *    scope names goes under "More" rather than being dropped.
 *  · THE EMPTY QUERY IS {@see SearchLanding}: open now and coming up, from real signals,
 *    and no trending group because there is no trending signal (GAPS §3.6).
 *
 * ── LIMITS ──────────────────────────────────────────────────────────────────
 *
 * 120 searches a minute per client, as before — the debounce in the script is the first
 * line and this is the one that holds when somebody calls the endpoint directly. The
 * search itself is uncached by design (the service says why).
 */
final class SearchController
{
    /** How many rows the index is asked for before grouping: four groups of six. */
    private const ASK = 24;

    public function __construct(private readonly ActivityFeedService $feed) {}

    public function search(Request $req, Response $res): Response
    {
        $json = static function (Response $res, array $payload, int $code = 200): Response {
            $res->getBody()->write((string) json_encode(
                $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $res
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                // A search result is per-visitor and promises "right now"; never reused.
                ->withHeader('Cache-Control', 'no-store')
                ->withStatus($code);
        };

        try {
            if (!(new RateLimitService())->check($this->clientKey($req), 'search', 120, 60)) {
                return $json($res, ['ok' => false,
                    'error' => Translator::t('Too many searches — pause a moment.')], 429);
            }
        } catch (\Throwable) {
            // A rate-limiter outage must not take a read-only public search down.
        }

        $params  = $req->getQueryParams();
        $q       = trim(is_string($params['q'] ?? null) ? $params['q'] : '');
        $scopeIn = strtolower(trim(is_string($params['scope'] ?? null) ? $params['scope'] : ''));
        $scope   = array_key_exists($scopeIn, ActivityFeedService::SCOPES) ? $scopeIn : '';
        $literal = ($params['literal'] ?? '') !== '';

        if (mb_strlen($q) < ActivityFeedService::MIN_QUERY) {
            return $json($res, [
                'ok'      => true,
                'query'   => '',
                'scope'   => $scope === '' ? 'all' : $scope,
                'landing' => true,
                'groups'  => $this->landing($scope),
            ]);
        }

        $result = $this->feed->search($q, self::ASK, interpret: !$literal, scope: $scope);

        return $json($res, [
            'ok'         => true,
            'query'      => $result['query'],
            'scope'      => $scope === '' ? 'all' : $scope,
            'landing'    => false,
            'groups'     => $this->group($result['items']),
            // What a model understood, so the palette can say so; null when nothing was
            // interpreted, which is also every deployment without a provider.
            'understood' => $result['understood'],
        ]);
    }

    /**
     * Items grouped by scope, in chip order, ≤6 each.
     *
     * @param list<array<string,mixed>> $items
     * @return list<array{key:string,label:string,items:list<array<string,string>>}>
     */
    private function group(array $items): array
    {
        $byKind = [];
        foreach (ActivityFeedService::SCOPES as $scope => $kinds) {
            foreach ($kinds as $k) $byKind[$k] = $scope;
        }

        $buckets = [];
        foreach ($items as $i) {
            $scope = $byKind[(string) ($i['kind'] ?? '')] ?? 'more';
            if (count($buckets[$scope] ?? []) >= SearchLanding::PER_GROUP) continue;
            $buckets[$scope][] = $this->row($scope, $i);
        }

        $out = [];
        foreach ([...array_keys(ActivityFeedService::SCOPES), 'more'] as $scope) {
            if (empty($buckets[$scope])) continue;
            $out[] = ['key' => $scope, 'label' => self::label($scope), 'items' => $buckets[$scope]];
        }

        return $out;
    }

    /** @return list<array{key:string,label:string,items:list<array<string,string>>}> */
    private function landing(string $scope): array
    {
        $groups = [];
        if ($scope === '' || $scope === 'awards') {
            $open = SearchLanding::openNow();
            if ($open) $groups[] = ['key' => 'awards', 'label' => Translator::t('Open now'),
                'items' => array_map(fn (array $i): array => $this->row('awards', $i), $open)];
        }
        if ($scope === '' || $scope === 'events') {
            $soon = SearchLanding::comingUp();
            if ($soon) $groups[] = ['key' => 'events', 'label' => Translator::t('Coming up'),
                'items' => array_map(fn (array $i): array => $this->row('events', $i), $soon)];
        }

        return $groups;
    }

    /**
     * One result as the palette draws it. `tile` is the GROUP, not the source kind, so the
     * script decides how a row looks without knowing what a source is.
     *
     * @param array<string,mixed> $i
     * @return array<string,string>
     */
    private function row(string $scope, array $i): array
    {
        $title = (string) ($i['title'] ?? '');
        $words = preg_split('/\s+/u', trim($title)) ?: [];
        $ini   = '';
        foreach (array_slice($words, 0, 2) as $w) $ini .= mb_strtoupper(mb_substr($w, 0, 1));

        $detail = trim((string) ($i['detail'] ?? ''));
        $when   = trim((string) ($i['at_label'] ?? ''));

        return [
            'title' => $title,
            'meta'  => trim($detail . ($detail !== '' && $when !== '' ? ' · ' : '') . $when),
            'url'   => (string) ($i['url'] ?? '/'),
            'tile'  => $scope,
            'ini'   => $scope === 'people' ? $ini : '',
            // The design labels only a page as a page; a person, an award and an event say
            // what they are in their own title and meta.
            'kind'  => ($i['kind'] ?? '') === 'page' ? Translator::t('Page') : '',
        ];
    }

    /** The heading a group is drawn under: the chip's own word. */
    private static function label(string $scope): string
    {
        return match ($scope) {
            'people' => Translator::t('People'),
            'awards' => Translator::t('Awards'),
            'events' => Translator::t('Events'),
            'pages'  => Translator::t('Pages'),
            default  => Translator::t('More'),
        };
    }

    /**
     * Rate-limit bucket for this client — X-Forwarded-For only when TRUST_PROXY says a
     * proxy sets it, hashed so the limiter never stores an address.
     */
    private function clientKey(Request $req): string
    {
        $server = $req->getServerParams();
        $ip = (string) ($server['REMOTE_ADDR'] ?? '');
        if (Env::bool('TRUST_PROXY') && !empty($server['HTTP_X_FORWARDED_FOR'])) {
            $ip = trim(explode(',', (string) $server['HTTP_X_FORWARDED_FOR'])[0]);
        }

        return hash('sha256', $ip . '|search');
    }
}
