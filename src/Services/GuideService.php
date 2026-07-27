<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Psr\Log\LoggerInterface;

/**
 * Gee — the Africa GATES guide.
 *
 * A page-aware assistant that answers questions about the platform and points
 * users to the right page. Gee is provider-agnostic (batch 3):
 *
 *   1. Make.com AI-agent bridge (optional, off by default) — when a superadmin
 *      configures an agent URL + key, Gee forwards the conversation to that
 *      agent (with the live site-state digest) and relays its reply, giving
 *      Gee access to the agent's tools. The same key authenticates the
 *      inbound POST /api/v1/agent/gee endpoint so the two talk BOTH ways.
 *   2. Anthropic via env ANTHROPIC_API_KEY + GEE_MODEL (legacy direct path,
 *      kept so existing installs keep their exact behaviour).
 *   3. The shared AiService chain — Groq → Gemini → Anthropic → OpenAI —
 *      i.e. whatever the admin configured in Settings, Groq models first.
 *   4. Built-in keyword-routed answers. Gee ALWAYS replies — never a dead
 *      widget, and rate-limit degradation lands here invisibly.
 *
 * Gee is grounded with a cached (~10 min) SITE-STATE digest — live cycle
 * statuses, deadlines and counts — so it always knows the current state of
 * the platform. The grounding rules still forbid inventing figures the
 * digest doesn't contain.
 */
final class GuideService
{
    private const ENDPOINT          = 'https://api.anthropic.com/v1/messages';
    private const ANTHROPIC_VERSION = '2023-06-01';
    private const MAX_HISTORY       = 10;
    private const MAX_CHARS         = 2000;
    private const STATE_TTL         = 600; // seconds; keeps Gee's knowledge current

    public function __construct(
        private readonly ?LoggerInterface $log = null,
        private readonly ?CacheService $cache = null,
    ) {}

    public function isAiEnabled(): bool
    {
        if (trim((string)($_ENV['ANTHROPIC_API_KEY'] ?? '')) !== '') return true;
        try { return AiGateway::available('guide.chat') || $this->makeConfigured(); }
        catch (\Throwable) { return false; }
    }

    /**
     * The model for the LEGACY direct-Anthropic path.
     *
     * This defaulted to 'claude-opus-4-8', which is not an id any provider
     * serves — and GEE_MODEL was absent from .env.example. So an installer who
     * set ANTHROPIC_API_KEY and nothing else got isAiEnabled() === true while
     * every call 404'd, silently falling through to the scripted keyword tier.
     * The widget looked AI-powered and was not, with no error anywhere.
     */
    private const DEFAULT_MODEL = 'claude-haiku-4-5-20251001';

    private function model(): string
    {
        $m = trim((string)($_ENV['GEE_MODEL'] ?? ''));
        return $m !== '' ? $m : self::DEFAULT_MODEL;
    }

    /**
     * Answer a user message.
     *
     * @param string $message     the new user message
     * @param array  $history     prior turns: [['role'=>'user'|'assistant','text'=>string], …] (oldest→newest)
     * @param array  $page        {title, path} of the page the user is on (untrusted; context only)
     * @param bool   $allowBridge false on the INBOUND agent endpoint so a Make agent
     *                            querying Gee can never loop back out to itself
     * @return array{reply:string, source:'agent'|'ai'|'scripted'}
     */
    public function answer(string $message, array $history, array $page, bool $allowBridge = true): array
    {
        $message = trim($message);
        if ($message === '') {
            return ['reply' => "Ask me anything about Africa GATES — voting, nominations, the CPI score, the shop, partnerships…", 'source' => 'scripted'];
        }

        // 1) External Make.com agent (tools live there) — first when configured.
        if ($allowBridge && $this->makeConfigured()) {
            try {
                $reply = $this->askMakeAgent($message, $history, $page);
                if ($reply !== null && trim($reply) !== '') {
                    return ['reply' => trim($reply), 'source' => 'agent'];
                }
            } catch (\Throwable $e) {
                $this->log?->warning('[gee] Make agent failed; falling back', ['err' => $e->getMessage()]);
            }
        }

        // 2) Legacy direct Anthropic path (env key + GEE_MODEL).
        if (trim((string)($_ENV['ANTHROPIC_API_KEY'] ?? '')) !== '') {
            try {
                $reply = $this->askClaude($message, $history, $page);
                if ($reply !== null && trim($reply) !== '') {
                    return ['reply' => trim($reply), 'source' => 'ai'];
                }
            } catch (\Throwable $e) {
                $this->log?->warning('[gee] Anthropic call failed; trying provider chain', ['err' => $e->getMessage()]);
            }
        }

        // 3) Shared provider chain via the gateway — admin-configured keys, with
        //    the budget, the kill switch and the record. The visitor's message is
        //    fenced as untrusted content; the grounding prompt stays outside it.
        $r = (new AiGateway())->run('guide.chat', [
            'system'      => $this->systemPrompt($page),
            'trusted'     => 'The conversation with the visitor follows.',
            'user'        => $this->transcriptFor($message, $history),
            'temperature' => 0.4,
            'schema'      => static function (string $raw): ?string {
                $t = trim($raw);
                return $t === '' ? null : mb_substr($t, 0, 4000);
            },
        ]);
        if ($r->ok) {
            return ['reply' => $r->value, 'source' => 'ai'];
        }
        if ($r->code !== 'NO_PROVIDER') {
            $this->log?->warning('[gee] AI unavailable; using scripted fallback', ['code' => $r->code]);
        }

        // 4) Never a dead widget.
        return $this->scripted($message);
    }

    /** The zero-cost tier — also the invisible rate-limit degrade path. */
    public function scripted(string $message): array
    {
        return ['reply' => $this->scriptedAnswer($message), 'source' => 'scripted'];
    }

    // ───────────────────────── Site-state knowledge ──────────────────────

    /**
     * Live platform state, cached ~10 min: what's open, what's counting down,
     * headline numbers. Feeds the system prompt AND the inbound agent API.
     */
    public function siteState(): array
    {
        $build = function (): array {
            $state = ['generated_at' => date('c')];
            try {
                $progs = DB::table('gates_award_programmes as p')
                    ->join('gates_award_cycles as c', 'c.programme_id', '=', 'p.id')
                    ->where('p.is_active', 1)->where('c.year', (int) date('Y'))
                    ->get(['p.title', 'c.status', 'c.nominations_close', 'c.voting_close', 'c.results_date']);
                $state['programmes'] = array_map(static fn($r) => [
                    'title'             => (string) $r->title,
                    'cycle_status'      => (string) $r->status,
                    'nominations_close' => $r->nominations_close ? (string) $r->nominations_close : null,
                    'voting_close'      => $r->voting_close ? (string) $r->voting_close : null,
                    'results_date'      => $r->results_date ? (string) $r->results_date : null,
                ], $progs->all());
            } catch (\Throwable) { $state['programmes'] = []; }
            try {
                $state['counts'] = [
                    'approved_nominees' => (int) DB::table('gates_nominees')->whereIn('status', ['approved', 'winner', 'runner_up'])->count(),
                    'votes'             => (int) DB::table('gates_votes')->count(),
                    'community_threads' => (int) DB::table('gates_threads')->whereIn('status', ['approved', 'locked'])->count(),
                    'registry_profiles' => (int) DB::table('gates_profiles')->where('status', 'approved')->count(),
                ];
            } catch (\Throwable) { $state['counts'] = []; }
            try {
                $e = DB::table('gates_site_events')->where('status', 'published')
                    ->where('event_date', '>=', date('Y-m-d H:i:s'))->orderBy('event_date')->first(['title', 'event_date', 'location']);
                $state['next_event'] = $e ? ['title' => (string) $e->title, 'date' => (string) $e->event_date, 'location' => (string) ($e->location ?? '')] : null;
            } catch (\Throwable) { $state['next_event'] = null; }
            try {
                $sla = DB::table('gates_settings')->where('key_name', 'review_sla_hours')->value('value');
                $state['review_sla_hours'] = $sla !== null ? (int) $sla : 48;
            } catch (\Throwable) { $state['review_sla_hours'] = 48; }
            return $state;
        };

        if ($this->cache) {
            try { return (array) $this->cache->remember('gee:site-state', self::STATE_TTL, $build); }
            catch (\Throwable) {}
        }
        return $build();
    }

    /** Compact prompt-ready rendering of siteState(). */
    private function siteStateDigest(): string
    {
        try { $s = $this->siteState(); } catch (\Throwable) { return ''; }
        $lines = [];
        foreach (($s['programmes'] ?? []) as $p) {
            $bits = $p['title'] . ': cycle status "' . $p['cycle_status'] . '"';
            if (!empty($p['nominations_close']) && $p['cycle_status'] === 'nominations') $bits .= ', nominations close ' . substr($p['nominations_close'], 0, 10);
            if (!empty($p['voting_close']) && $p['cycle_status'] === 'voting')           $bits .= ', voting closes ' . substr($p['voting_close'], 0, 10);
            $lines[] = '- ' . $bits;
        }
        $c = $s['counts'] ?? [];
        if ($c !== []) {
            $lines[] = '- Live counts: ' . ($c['approved_nominees'] ?? 0) . ' nominees, ' . ($c['votes'] ?? 0) . ' votes cast, '
                . ($c['registry_profiles'] ?? 0) . ' registry profiles, ' . ($c['community_threads'] ?? 0) . ' community threads.';
        }
        if (!empty($s['next_event']['title'])) {
            $lines[] = '- Next event: ' . $s['next_event']['title'] . ' on ' . substr((string) $s['next_event']['date'], 0, 10)
                . (!empty($s['next_event']['location']) ? ' (' . $s['next_event']['location'] . ')' : '') . ' — details at /events.';
        }
        $lines[] = '- Nominations are typically reviewed within ' . ($s['review_sla_hours'] ?? 48) . ' hours.';
        return $lines === [] ? '' : "\n\nCURRENT SITE STATE (live, refreshed every few minutes — you MAY quote these):\n" . implode("\n", $lines);
    }

    // ───────────────────────── Make.com agent bridge ─────────────────────

    /** @return array{url:string,key:string} */
    private function makeConfig(): array
    {
        $resolve = static function (string $settingKey, string $envKey): string {
            $v = null;
            try { $v = DB::table('gates_settings')->where('key_name', $settingKey)->value('value'); }
            catch (\Throwable) {}
            $v = is_string($v) ? trim($v) : '';
            return $v !== '' ? $v : trim((string)($_ENV[$envKey] ?? ''));
        };
        return [
            'url' => $resolve('gee_make_agent_url', 'GEE_MAKE_AGENT_URL'),
            'key' => $resolve('gee_make_agent_key', 'GEE_MAKE_AGENT_KEY'),
        ];
    }

    public function makeConfigured(): bool
    {
        $c = $this->makeConfig();
        return $c['url'] !== '' && str_starts_with($c['url'], 'https://');
    }

    /** Shared secret for the INBOUND /api/v1/agent/gee endpoint ('' = disabled). */
    public function agentKey(): string
    {
        return $this->makeConfig()['key'];
    }

    /** Forward the conversation (+ site state) to the Make agent; relay its reply. */
    private function askMakeAgent(string $message, array $history, array $page): ?string
    {
        $cfg = $this->makeConfig();
        $payload = json_encode([
            'source'     => 'africa-gates/gee',
            'message'    => mb_substr($message, 0, self::MAX_CHARS),
            'history'    => array_slice(array_values(array_filter($history, 'is_array')), -self::MAX_HISTORY),
            'page'       => ['title' => (string)($page['title'] ?? ''), 'path' => (string)($page['path'] ?? '')],
            'site_state' => $this->siteState(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $headers = ['Content-Type: application/json'];
        if ($cfg['key'] !== '') $headers[] = 'Authorization: Bearer ' . $cfg['key'];

        $ch = curl_init($cfg['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $payload,
        ]);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $code < 200 || $code >= 300) {
            throw new \RuntimeException('Make agent HTTP ' . $code);
        }
        // Accept {reply} | {text} | {output} | a bare JSON string | raw text.
        $data = json_decode((string) $raw, true);
        if (is_array($data)) {
            foreach (['reply', 'text', 'output', 'answer'] as $k) {
                if (isset($data[$k]) && is_string($data[$k]) && trim($data[$k]) !== '') return mb_substr($data[$k], 0, 4000);
            }
            return null;
        }
        if (is_string($data) && trim($data) !== '') return mb_substr($data, 0, 4000);
        $rawStr = trim((string) $raw);
        return ($rawStr !== '' && $rawStr[0] !== '{' && $rawStr[0] !== '[') ? mb_substr($rawStr, 0, 4000) : null;
    }

    // ─────────────────────────────── Prompt ──────────────────────────────

    private function systemPrompt(array $page): string
    {
        $pageLine = '';
        $title = trim((string)($page['title'] ?? ''));
        $path  = trim((string)($page['path'] ?? ''));
        if ($title !== '' || $path !== '') {
            $pageLine = "\n\nRIGHT NOW the user is on: " . trim($title . ($path !== '' ? " ({$path})" : '')) . ". Tailor your help to where they are when it's relevant.";
        }
        $stateBlock = $this->siteStateDigest();

        return <<<SYS
You are **Gee**, the warm, sharp guide for Africa GATES — the continental Cultural Power Index (CPI) that recognises African excellence.

WHAT AFRICA GATES IS
- The community nominates people and organisations, the public votes, and an independent jury scores them. Each entrant earns a transparent Cultural Power Index from 0–1000, recomputed every 6 hours.
- The CPI is 45% verified public votes + 55% an independent jury panel. The jury averages four criteria — Impact, Originality, Reach, Integrity. Only organic verified votes count toward the score.
- An Afrovanguard initiative — live in Nigeria, building toward 54 nations.

PAGES YOU CAN SEND PEOPLE TO (write the bare path; the interface turns it into a button)
- /vote — cast a verified vote (OTP-confirmed, one per category)
- /nominate — nominate someone (free, ~90 seconds; the nominee needs an email OR phone)
- /registry — the verified profile registry (search by name, country, field)
- /leaderboard — live CPI rankings
- /awards — the award programmes
- /integrity — how scoring, voting and audits actually work
- /shop — apparel & keepsakes; proceeds fund child leadership programmes
- /donate — make a one-time gift that funds child leadership programmes (receipted; never affects the CPI)
- /events — events and RSVPs
- /community — member threads, polls and conversations
- /partner — partnerships for organisations and brands
- /register — create a verified profile
- /account — member dashboard (votes, nominations, points, share links)
- /help — the Help Center
- /support — support, appeals and account issues

HOW TO RESPOND
- Be warm, concise and specific — usually 2–4 short sentences.
- When a page answers the question, point to it by its path (e.g. "Head to /vote to cast yours.").
- You MAY quote figures from CURRENT SITE STATE below — it is live. NEVER invent any OTHER specific figure (prices, someone's CPI score, totals not listed). If asked for one, send them to the page that shows it.
- You explain and direct; you don't take actions, make payments, or change accounts. For account, payment, appeal or moderation issues, point to /support.
- Stay on Africa GATES topics. If asked something unrelated, gently steer back.{$pageLine}{$stateBlock}
SYS;
    }

    /** Flatten history + message into one user turn for single-shot providers. */
    private function transcriptFor(string $message, array $history): string
    {
        $out = [];
        foreach (array_slice($history, -self::MAX_HISTORY) as $h) {
            if (!is_array($h)) continue;
            $text = trim((string)($h['text'] ?? ''));
            if ($text === '') continue;
            $out[] = ((($h['role'] ?? '') === 'assistant') ? 'Gee' : 'User') . ': ' . mb_substr($text, 0, self::MAX_CHARS);
        }
        $out[] = 'User: ' . mb_substr($message, 0, self::MAX_CHARS);
        $out[] = 'Gee:';
        return implode("\n", $out);
    }

    // ─────────────────────────────── Claude ──────────────────────────────

    private function askClaude(string $message, array $history, array $page): ?string
    {
        $messages = [];
        foreach (array_slice($history, -self::MAX_HISTORY) as $h) {
            if (!is_array($h)) continue;
            $role = (($h['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
            $text = trim((string)($h['text'] ?? ''));
            if ($text !== '') $messages[] = ['role' => $role, 'content' => mb_substr($text, 0, self::MAX_CHARS)];
        }
        $messages[] = ['role' => 'user', 'content' => mb_substr($message, 0, self::MAX_CHARS)];

        $payload = json_encode([
            'model'      => $this->model(),
            'max_tokens' => 1024,
            'system'     => $this->systemPrompt($page),
            'messages'   => $messages,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . trim((string)($_ENV['ANTHROPIC_API_KEY'] ?? '')),
                'anthropic-version: ' . self::ANTHROPIC_VERSION,
            ],
            CURLOPT_POSTFIELDS     => $payload,
        ]);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false)  throw new \RuntimeException('cURL error: ' . $err);
        if ($code !== 200)   throw new \RuntimeException('Claude API HTTP ' . $code . ': ' . mb_substr((string)$raw, 0, 300));

        $data = json_decode((string)$raw, true);
        if (!is_array($data)) throw new \RuntimeException('Malformed JSON from Claude API');

        // Safety: a refusal returns 200 with stop_reason "refusal" and empty/partial content.
        if (($data['stop_reason'] ?? '') === 'refusal') return null;

        $text = '';
        foreach (($data['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') $text .= (string)($block['text'] ?? '');
        }
        return $text !== '' ? $text : null;
    }

    // ───────────────────────────── Fallback ──────────────────────────────

    /** Keyword-routed answers used when no provider is configured (or every call fails). */
    private function scriptedAnswer(string $message): string
    {
        $m   = mb_strtolower($message);
        $hit = static fn(array $kw): bool => array_filter($kw, static fn($k) => str_contains($m, $k)) !== [];

        if ($hit(['cpi', 'score', 'rank', 'index', 'how it work']))
            return "The Cultural Power Index (CPI) is a 0–1000 score: 45% from verified public votes and 55% from an independent jury rating Impact, Originality, Reach and Integrity. It recomputes every 6 hours. Full method at /integrity, live rankings at /leaderboard.";
        if ($hit(['vote', 'voting']))
            return "Voting is verified — one OTP-confirmed vote per category. Cast yours at /vote. Curious how votes are weighed? See /integrity.";
        if ($hit(['nominate', 'nomination']))
            return "Anyone can nominate, free, in about 90 seconds — it's OTP-verified to keep things fair, and you only need the nominee's email or phone. Start at /nominate.";
        if ($hit(['shop', 'buy', 'merch', 'apparel', 'tee', 'hoodie', 'order']))
            return "The shop has heritage apparel and keepsakes, and proceeds fund child leadership programmes. Browse it at /shop.";
        if ($hit(['donat', 'give', 'fund']))
            return "You can give directly at /donate — one-time gifts that fund child leadership programmes (mentorship, scholarships, grassroots education), receipted and audited. The /shop also gives back, and organisations can do more via /partner.";
        if ($hit(['partner', 'sponsor', 'brand', 'advertis']))
            return "Organisations can partner with Africa GATES to reach a verified, prestige audience and fund impact. Details and the enquiry form are at /partner.";
        if ($hit(['event', 'rsvp', 'gala', 'ticket']))
            return "Events and RSVPs live at /events — that's also where the awards gala is listed.";
        if ($hit(['community', 'thread', 'forum', 'discuss']))
            return "The community lives at /community — threads, polls and conversations. Anyone can read; members can post, react and vote in polls (join free at /account/register).";
        if ($hit(['register', 'profile', 'join', 'sign up', 'signup', 'account', 'member']))
            return "Create a free member account at /account/register — you'll get a dashboard for your votes, nominations and voting points. Public figures can also claim a registry profile at /register.";
        if ($hit(['judge', 'jury', 'scoring', 'criteria']))
            return "An independent African jury scores shortlisted nominees on four criteria — Impact, Originality, Reach, Integrity — making up 55% of the CPI. The full method is at /integrity.";
        if ($hit(['support', 'appeal', 'contact', 'problem', 'issue', 'help', 'wrong']))
            return "Our team can help — the Help Center is at /help, and appeals or account issues go to /support.";
        if ($hit(['registry', 'find', 'search', 'who is', 'lookup']))
            return "The verified registry is at /registry — search by name, country or field. Live rankings are at /leaderboard.";

        return "I can help you find your way around Africa GATES — voting (/vote), nominations (/nominate), the CPI score & method (/integrity), the community (/community), the registry (/registry), the shop (/shop) or partnerships (/partner). What are you trying to do?";
    }
}
