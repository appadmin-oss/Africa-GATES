<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use AfricaGates\Support\NationsLive;
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
        if (trim((string) Env::get('ANTHROPIC_API_KEY', '')) !== '') return true;
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
        $m = trim((string) Env::get('GEE_MODEL', ''));
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

        // 2) The agent: the same tool loop as the help desk, under Gee's voice, with the
        //    read-only tools and offer_action. It looks things up instead of reciting a
        //    prompt, and ends a "how do I…" with a button rather than directions.
        try {
            $agent = (new SupportAgentService(AiService::boot()))
                ->converse($message, $this->agentHistory($history), SupportContext::fromSession(),
                           $this->systemPrompt($page, $message));
            if ($agent !== null && trim($agent['reply']) !== '') {
                return ['reply' => trim($agent['reply']), 'source' => 'ai', 'actions' => $agent['actions']];
            }
        } catch (\Throwable $e) {
            $this->log?->warning('[gee] agent failed; trying the one-shot answer', ['err' => $e->getMessage()]);
        }

        // 3) Legacy direct Anthropic path, for a key that reaches nothing above (env key + GEE_MODEL).
        if (trim((string) Env::get('ANTHROPIC_API_KEY', '')) !== '') {
            try {
                $reply = $this->askClaude($message, $history, $page);
                if ($reply !== null && trim($reply) !== '') {
                    return ['reply' => trim($reply), 'source' => 'ai'];
                }
            } catch (\Throwable $e) {
                $this->log?->warning('[gee] Anthropic call failed; trying provider chain', ['err' => $e->getMessage()]);
            }
        }

        // 4) Shared provider chain via the gateway — admin-configured keys, with
        //    the budget, the kill switch and the record. The visitor's message is
        //    fenced as untrusted content; the grounding prompt stays outside it.
        $r = (new AiGateway())->run('guide.chat', [
            'system'      => $this->systemPrompt($page, $message),
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

        // 5) Never a dead widget.
        return $this->scripted($message);
    }

    /**
     * Gee's history ({role, text}) as the agent's ({role, content}).
     *
     * @return list<array{role:string,content:string}>
     */
    private function agentHistory(array $history): array
    {
        $out = [];
        foreach ($history as $h) {
            if (!is_array($h)) continue;
            $t = trim((string) ($h['content'] ?? $h['text'] ?? ''));
            if ($t === '') continue;
            $out[] = ['role' => ($h['role'] ?? '') === 'assistant' ? 'assistant' : 'user', 'content' => mb_substr($t, 0, 2000)];
        }
        return $out;
    }

    /** The zero-cost tier — also the invisible rate-limit degrade path. */
    public function scripted(string $message): array
    {
        // A support-shaped question gets the Help Centre's own words even here.
        // See supportFallback(): the keyword tier below is a signpost, and a
        // signpost is the wrong answer for somebody whose money is missing.
        if (SupportIntent::looksLikeSupport($message)) {
            return $this->supportFallback($message);
        }
        return ['reply' => $this->scriptedAnswer($message), 'source' => 'scripted'];
    }

    /**
     * A real support answer with NO model involved.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY THIS IS NOT A REDIRECT
     * ══════════════════════════════════════════════════════════════════════════
     *
     * This is the path taken when the support agent cannot run — no provider
     * configured, the daily AI budget spent, or this visitor past their support
     * allowance. Those are the moments a support widget usually turns into
     * "please email us", and they are disproportionately likely to happen during
     * an incident, when everybody arrives at once and the budget goes first.
     *
     * So the floor is not a signpost. The Help Centre is 24 written answers,
     * live-valued from the same settings the checkout reads, and the commonest
     * support questions on this platform are ALREADY ANSWERED in it. Quoting the
     * matching one costs nothing, cannot hallucinate, and is the same text a
     * working model would have quoted.
     *
     * The composition lives in {@see HelpCentre::writtenAnswer()} because the
     * support DESK needs the identical floor, and the two had drifted — Gee quoted
     * the article while the desk said "I cannot reach my assistant service right
     * now", so the person who had navigated all the way to the support page got
     * the worse answer.
     *
     * When nothing matches, this says so and asks for the one thing that makes the
     * next step possible — the payment reference — rather than apologising.
     */
    public function supportFallback(string $message): array
    {
        // An error-page reference is not a payment reference, whatever the search thinks.
        $fault = \AfricaGates\Support\PublicFault::quoted($message);
        if ($fault !== null) {
            $e = \AfricaGates\Support\PublicFault::find($fault);
            return ['reply' => \AfricaGates\Support\PublicFault::chatReply($fault, $e,
                    \AfricaGates\Support\PublicFault::others($fault, (string) ($e['where'] ?? '')))
                . "\n\nIf it keeps happening, /support takes it to the team; quote **{$fault}** and they can open "
                . "exactly what failed.", 'source' => 'help'];
        }
        $written = HelpCentre::writtenAnswer($message);

        return ['reply' => $written ?? (
            "I want to get this to the right place rather than guess. If it is about a payment or votes you "
            . "paid for, send me the payment reference (it looks like **AFG-…**, and it is on the checkout page "
            . "and in your bank alert) and I will have it looked at.\n\n"
            . "Otherwise /support will take it to the team, and /help has the written answers."
        ), 'source' => 'help'];
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
            // One resolver, shared with the mailer that acts on this number and with the
            // Twig global the public pages print. Its own cast had no floor, so a setting
            // of nought had the assistant telling the public we review "within 0 hours".
            $state['review_sla_hours'] = NominationFeedbackService::slaHours();
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
            // Predicate, not label — same reason as NominationController: one
            // source of truth for "is this open", so Gee cannot tell a visitor a
            // window is open when the guard would refuse the write.
            if (!empty($p['nominations_close']) && !empty($p['phase']['is_nominations_open'])) $bits .= ', nominations close ' . substr($p['nominations_close'], 0, 10);
            if (!empty($p['voting_close']) && !empty($p['phase']['is_voting_open']))          $bits .= ', voting closes ' . substr($p['voting_close'], 0, 10);
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
            return $v !== '' ? $v : (string) Env::get($envKey, '');
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

    /**
     * The Help Centre answers relevant to THIS question, for the prompt.
     *
     * ── WHY GROUND A GUIDE IN THE HELP CENTRE ────────────────────────────────
     *
     * Gee's prompt describes the platform in about twenty lines. The Help Centre
     * describes it in twenty-four articles, each one live-valued from the same
     * settings the checkout reads — so when the price of a vote changes, the
     * articles change and the prompt does not.
     *
     * Ungrounded, Gee answers "how long until my votes appear?" from whatever it
     * absorbed in training. Grounded, it answers from the sentence the team wrote
     * and maintains. The second one is also the sentence support will repeat if
     * the person writes in, and an assistant that contradicts the help page is
     * worse than no assistant, because now the reader has to decide who is lying.
     *
     * Two articles, trimmed. This is a chat bubble, not a knowledge dump, and a
     * prompt stuffed with 24 articles buries the page context that makes Gee
     * page-aware in the first place.
     */
    private function helpGrounding(string $message): string
    {
        if (trim($message) === '') return '';
        $hits = HelpCentre::search($message, 2);
        if ($hits === []) return '';

        $out = [];
        foreach ($hits as $a) {
            $out[] = '### ' . (string) $a['title'] . ' (' . HelpCentre::url((string) $a['slug']) . ")\n"
                   . mb_substr(HelpCentre::plainText($a), 0, 900);
        }
        return "\n\nWRITTEN HELP CENTRE ANSWERS matching this question. These are the platform's OWN vetted answers "
             . "and they are kept correct as settings change. PREFER them over your own memory — if one disagrees "
             . "with you, it is right and you are not. Give the reader its URL so they can keep it.\n\n"
             . implode("\n\n", $out);
    }

    private function systemPrompt(array $page, string $message = ''): string
    {
        $pageLine = '';
        $title = trim((string)($page['title'] ?? ''));
        $path  = trim((string)($page['path'] ?? ''));
        if ($title !== '' || $path !== '') {
            $pageLine = "\n\nRIGHT NOW the user is on: " . trim($title . ($path !== '' ? " ({$path})" : '')) . ". Tailor your help to where they are when it's relevant.";
        }
        $stateBlock = $this->siteStateDigest();
        $helpBlock  = $this->helpGrounding($message);

        // ── THE THREE FACTS BELOW WERE TYPED INTO THE PROMPT ─────────────────
        //
        // "live in Nigeria, building toward 54 nations" and "45% / 55%" were literals here,
        // which makes this the one place that explains the platform to people and the one
        // place that could not follow it. The weights are configurable per programme and
        // per cycle; the nations are counted from the awards. A guide confidently quoting a
        // split this platform is not using is worse than one that says it does not know.
        $w    = (new RuleEngine())->weights();
        $cw   = (int) round($w['community'] * 100);
        $jw   = 100 - $cw;
        $live = NationsLive::phrase();
        // The ambition, from the same setting the footer reads. Its own try/catch because
        // a guide that cannot answer at all is a worse failure than one saying 54.
        $goal = 54;
        try {
            $v = DB::table('gates_settings')->where('key_name', 'nations_count')->value('value');
            if (is_numeric($v)) $goal = (int) $v;
        } catch (\Throwable) {}

        return <<<SYS
You are **Gee**, the warm, sharp guide for Africa GATES — the continental Cultural Power Index (CPI) that recognises African excellence.

WHAT AFRICA GATES IS
- The community nominates people and organisations, the public votes, and an independent jury scores them. Each entrant earns a transparent Cultural Power Index from 0–1000, recomputed every 6 hours.
- The CPI is {$cw}% community support + {$jw}% an independent jury panel. The jury averages four criteria — Impact, Originality, Reach, Integrity. The community share counts mostly how many separate verified PEOPLE backed a nominee, and partly their total votes — free and contributed votes both add to that tally. The exact arithmetic is at /help/how-cpi-works; never say paid votes do not count.
- An Afrovanguard initiative — live in {$live}, building toward {$goal} nations.

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

HOW TO RESPOND — YOUR JOB IS TO SOLVE THE PROBLEM, NOT TO POINT AT IT
- Do your best with every question. Work out what the person is actually trying to do and get them there: answer it fully, in your own words, before you mention any page. A link is the last line of a good answer, never the whole of it.
- If you have tools, use them — look the thing up rather than guessing, and when a page or a step fixes it, offer it as a button. If you lack a fact, say exactly what you need from them (a payment reference, the email they used, the page they were on) and ask for it once.
- If the answer has steps, give the steps, numbered and short. If something might be the cause, name the likeliest one first and say how to check it.
- Usually 2–5 short sentences; longer only when the steps need it. Warm, specific, no filler.
- When a page answers the question, name it by its path (e.g. "Head to /vote to cast yours.").
- You MAY quote figures from CURRENT SITE STATE below — it is live. NEVER invent any OTHER specific figure (prices, someone's CPI score, totals not listed). If asked for one, say where it is shown.
- You cannot move money, issue refunds, change an account, or add or remove votes — a person on the team does those. When that is what they need, do not stop at "contact support": collect what the team will need (the reference, what happened, when), tell them it is being passed on or how to pass it on at /support, and what happens next.
- If somebody has a problem with THEIR OWN payment, votes or account, never send them away with a link and nothing else. Being redirected after explaining yourself once is the thing people complain about most.
- Never answer "I can't help with that" and stop. If one part is out of reach, help with every part that is not, and say plainly who can do the rest.
- Anything that stands between a person and taking part counts as on-topic — getting a code by SMS or email, paying by card or transfer, using the site on a phone. Help with it.
- Only questions with nothing to do with Africa GATES or taking part in it get a gentle steer back.{$pageLine}{$stateBlock}{$helpBlock}
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
            'system'     => $this->systemPrompt($page, $message),
            'messages'   => $messages,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . trim((string) Env::get('ANTHROPIC_API_KEY', '')),
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

    /**
     * Keyword-routed answers used when no provider is configured (or every call fails).
     *
     * ── DO ITS BEST, EVEN WITH NO MODEL (owner, 12 Oct 2026) ──────────────────
     *
     * This tier used to be a signpost and nothing more: one sentence per keyword and a
     * menu of paths for everything else. Two faults with that. The Help Centre already
     * holds the written answer to most of what people ask, live-valued from the same
     * settings the checkout reads, and the menu threw it away whenever no keyword
     * matched. And two of the routes typed facts the rules decide — "45% … 55%" is a
     * per-programme setting, and "one OTP-confirmed vote per category" is not the whole
     * story wherever votes are also bought.
     *
     * So a route's sentence is kept (it is the shortest correct answer), the best
     * article is added beneath it when one is a strong match, and a question no route
     * recognises is answered from the article rather than with a menu. The weights come
     * from RuleEngine, as the prompt's do.
     */
    private function scriptedAnswer(string $message): string
    {
        $m   = mb_strtolower($message);
        $hit = static fn(array $kw): bool => array_filter($kw, static fn($k) => str_contains($m, $k)) !== [];

        $cw = 45; $jw = 55;
        try { $w = (new RuleEngine())->weights(); $cw = (int) round($w['community'] * 100); $jw = 100 - $cw; } catch (\Throwable) {}

        $route = null;
        if ($hit(['cpi', 'score', 'rank', 'index', 'how it work']))
            $route = "The Cultural Power Index (CPI) is a 0–1000 score: {$cw}% from community support — mostly how many separate verified people backed a nominee, partly their total votes — and {$jw}% from an independent jury rating Impact, Originality, Reach and Integrity. Full method at /integrity, live rankings at /leaderboard.";
        elseif ($hit(['why is voting paid', 'why paid', 'where does the money go', 'where do the funds go', 'paid vot']))
            $route = "Contributed votes keep recognition community-funded rather than waiting on a sponsor, and they add to a nominee's tally at full weight. The written answer says where the money goes.";
        elseif ($hit(['vote', 'voting']))
            $route = "Voting is verified — a free vote is confirmed with a one-time code, and where vote packs are offered they add to the tally too. Cast yours at /vote. Curious how votes are weighed? See /integrity.";
        elseif ($hit(['nominate', 'nomination']))
            $route = "Anyone can nominate, free, in about 90 seconds — it's OTP-verified to keep things fair, and you only need the nominee's email or phone. Start at /nominate.";
        elseif ($hit(['shop', 'buy', 'merch', 'apparel', 'tee', 'hoodie', 'order']))
            $route = "The shop has heritage apparel and keepsakes, and proceeds fund child leadership programmes. Browse it at /shop.";
        elseif ($hit(['donat', 'give', 'fund']))
            $route = "You can give directly at /donate — one-time gifts that fund child leadership programmes (mentorship, scholarships, grassroots education), receipted and audited. The /shop also gives back, and organisations can do more via /partner.";
        elseif ($hit(['partner', 'sponsor', 'brand', 'advertis']))
            $route = "Organisations can partner with Africa GATES to reach a verified, prestige audience and fund impact. Details and the enquiry form are at /partner.";
        elseif ($hit(['event', 'rsvp', 'gala', 'ticket']))
            $route = "Events and RSVPs live at /events — that's also where the awards gala is listed.";
        elseif ($hit(['community', 'thread', 'forum', 'discuss']))
            $route = "The community lives at /community — threads, polls and conversations. Anyone can read; members can post, react and vote in polls (join free at /account/register).";
        elseif ($hit(['register', 'profile', 'join', 'sign up', 'signup', 'account', 'member']))
            $route = "Create a free member account at /account/register — you'll get a dashboard for your votes, nominations and voting points. Public figures can also claim a registry profile at /register.";
        elseif ($hit(['judge', 'jury', 'scoring', 'criteria']))
            $route = "An independent African jury scores shortlisted nominees on four criteria — Impact, Originality, Reach, Integrity — making up {$jw}% of the CPI. The full method is at /integrity.";
        elseif ($hit(['registry', 'find', 'search', 'who is', 'lookup']))
            $route = "The verified registry is at /registry — search by name, country or field. Live rankings are at /leaderboard.";

        // The written answer, when one is a STRONG match: a phrase from the article's own
        // keywords appears in what they typed (search() scores that 30). A weaker hit is
        // a word in common, and quoting an unrelated article is worse than a menu.
        $article = null;
        try {
            $top = HelpCentre::search($message, 1)[0] ?? null;
            if ($top !== null && (int) $top['score'] >= 30) $article = $top;
        } catch (\Throwable) {}

        if ($route !== null) {
            return $article !== null
                ? $route . "\n\nThe written answer: **" . (string) $article['title'] . '** — ' . HelpCentre::url((string) $article['slug'])
                : $route;
        }
        if ($article !== null) {
            $written = HelpCentre::writtenAnswer($message);
            if ($written !== null) return $written;
        }
        if ($hit(['support', 'appeal', 'contact', 'problem', 'issue', 'help', 'wrong']))
            return "Tell me what you were trying to do and what happened instead, and I will work through it with you. If it needs a person, /support takes it to the team, and /help has the written answers.";

        return "I can help you find your way around Africa GATES — voting (/vote), nominations (/nominate), the CPI score & method (/integrity), the community (/community), the registry (/registry), the shop (/shop) or partnerships (/partner). What are you trying to do?";
    }
}
