<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use AfricaGates\Services\{GuideService, HelpCentre, RateLimitService, SupportAgentService,
                          SupportContext, SupportIntent};

/**
 * Gee chat endpoint — POST /api/guide. First-party, rate-limited, input-capped.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * GEE ALSO SUPPORTS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Gee is on every page of the site. The support desk is on one. So the assistant
 * a stuck person actually meets is nearly always Gee — and until now Gee's answer
 * to "I paid and my votes never came" was to point at /support and stop, because
 * that is literally what its prompt told it to do.
 *
 * That is the worst answer available. The tools to FIX that person's problem
 * exist: re-check the payment against the gateway, credit the votes, resend the
 * receipt, open a ticket that a human will see. They were one link away from
 * somebody who had already explained themselves, and most people do not follow
 * the link — they give up, or they email, which is slower for them and for us.
 *
 * So this controller routes. One support brain, two front doors:
 *
 *   BROWSING  → GuideService. Warm, page-aware, cheap, knows the site.
 *   STUCK     → SupportAgentService, with a context built from $_SESSION.
 *               The same agent, the same tools and the same identity rules as
 *               /support. Nothing is relaxed for being reached through Gee.
 *
 * {@see SupportIntent} decides, in code, with no model — see that class for why
 * the two directions of error are not treated as equally bad.
 *
 * ── WHAT IS NOT RELAXED ──────────────────────────────────────────────────────
 *
 * Identity comes from {@see SupportContext::fromSession()} and nowhere else: no
 * body field, no header, no history entry contributes to who the agent thinks it
 * is talking to. A guest reached through Gee gets exactly what a guest gets at
 * the desk — the repair actions, which hand nothing back, and no reads. That is
 * the whole point of putting the rule in a factory rather than in a controller.
 */
final class GuideController
{
    /**
     * Support-tier turns per IP per hour.
     *
     * Far tighter than the 40 guide messages, because these are not the same
     * transaction: a guide reply is one model call, and a support turn is a tool
     * loop plus a planner plus a writer, against the database. Twelve is more than
     * any real problem takes to describe, and past it Gee falls back to the
     * Help-Centre answer rather than erroring — see GuideService::supportFallback().
     */
    private const SUPPORT_PER_HOUR = 12;

    public function __construct(
        private readonly GuideService      $guide,
        private readonly ?RateLimitService $rateLimit = null,
        private readonly ?LoggerInterface  $log = null,
        /** Optional: without it Gee still supports, from the written answers. */
        private readonly ?SupportAgentService $support = null,
    ) {}

    private function json(Response $res, array $data, int $status = 200): Response
    {
        $res->getBody()->write((string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $res->withHeader('Content-Type', 'application/json')
                   // Gee can now answer about somebody's own payment. A support
                   // answer is per-person and must never sit in a shared cache.
                   ->withHeader('Cache-Control', 'no-store')
                   ->withStatus($status);
    }

    /**
     * JSON in: {message, history?:[{role,text}], page?:{title,path}}.
     * Out: {ok, reply, source, articles, support, used?, ticket?, escalated?}.
     */
    public function chat(Request $req, Response $res): Response
    {
        $ip = (string)($req->getServerParams()['REMOTE_ADDR'] ?? '');

        // Invisible rate limiting: beyond the per-IP AI budget (or the global
        // daily budget) Gee degrades to the scripted tier — a 200 with a real
        // answer, never an error. Only a hard abuse wall (5× the budget)
        // returns 429, so the limit is imperceptible to normal humans.
        $useAi = true;
        if ($this->rateLimit) {
            if (!$this->rateLimit->check(hash('sha256', $ip . '|gee-wall'), 'gee_wall', 200, 3600)) {
                return $this->json($res, ['ok' => false, 'reply' => "I'm getting a lot of questions right now — give me a minute and try again."], 429);
            }
            $useAi = $this->rateLimit->check(hash('sha256', $ip . '|gee'), 'gee_chat', 40, 3600)
                  && $this->rateLimit->check('global|gee', 'gee_ai_day', 4000, 86400);
        }

        $b       = (array)$req->getParsedBody();
        $message = trim((string)($b['message'] ?? ''));
        if ($message === '')            return $this->json($res, ['ok' => false, 'reply' => 'Ask me anything about Africa GATES.'], 422);
        if (mb_strlen($message) > 1000) return $this->json($res, ['ok' => false, 'reply' => 'Please keep your question under 1000 characters.'], 422);

        $history = is_array($b['history'] ?? null) ? $b['history'] : [];
        $pageIn  = is_array($b['page'] ?? null) ? $b['page'] : [];
        $page    = [
            'title' => mb_substr((string)($pageIn['title'] ?? ''), 0, 120),
            'path'  => mb_substr((string)($pageIn['path'] ?? ''), 0, 200),
        ];

        // ── stuck, or browsing? ─────────────────────────────────────────────
        $stuck = SupportIntent::looksLikeSupport($message, $history);

        try {
            if ($stuck) {
                $out = $this->supportTurn($message, $history, $ip);
                return $this->json($res, $out);
            }

            $out = $useAi ? $this->guide->answer($message, $history, $page) : $this->guide->scripted($message);
            return $this->json($res, [
                'ok'      => true,
                'reply'   => $out['reply'],
                'source'  => $out['source'],
                'support' => false,
                // No last resort here. A browsing question with no help match
                // gets no strip — "how do I nominate" must not sprout a refunds
                // card, which is what a fallback set would do on every miss.
                'articles' => HelpCentre::previews($message, [], 2),
            ]);
        } catch (\Throwable $e) {
            $this->log?->error('[gee] chat failed', ['err' => $e->getMessage(), 'support' => $stuck]);
            return $this->json($res, ['ok' => false, 'reply' => "I hit a snag just now. In the meantime, /help and /support have you covered."], 500);
        }
    }

    /**
     * One support turn, answered by the real support agent.
     *
     * ── WHY THIS RUNS BEFORE THE MAKE BRIDGE ─────────────────────────────────
     *
     * When a superadmin configures the Make.com agent, GuideService forwards
     * everything to it. That agent is a fine guide and it is the wrong thing to
     * hand a payment problem: it holds none of this platform's tools, it cannot
     * be scoped to the person asking, and whatever it replies we would be
     * relaying to somebody about their own money. So a support-shaped message
     * never reaches the bridge; it goes to the agent that can act.
     *
     * ── AND WHY EXHAUSTION IS NOT AN ERROR ───────────────────────────────────
     *
     * Past the support allowance, or with no AI provider configured at all, this
     * falls through to the Help Centre answer rather than an apology. Those are
     * precisely the conditions of an incident — everybody arrives at once and the
     * budget goes first — which is the moment a support widget must not become a
     * "please email us" box.
     *
     * @return array<string,mixed> the JSON body
     */
    private function supportTurn(string $message, array $history, string $ip): array
    {
        $allowed = $this->support?->available()
            && ($this->rateLimit === null
                || $this->rateLimit->check(hash('sha256', $ip . '|gee-support'),
                                           'gee_support', self::SUPPORT_PER_HOUR, 3600));

        if (!$allowed) {
            $out = $this->guide->supportFallback($message);
            return [
                'ok'       => true,
                'reply'    => $out['reply'],
                'source'   => $out['source'],
                'support'  => true,
                'articles' => HelpCentre::previews($message, [], 3, lastResort: true),
            ];
        }

        // Gee's client keeps history as {role, text}; the support agent speaks
        // {role, content}. Converted here rather than changing either side: the
        // widget's storage format is in every visitor's sessionStorage already.
        $turns = [];
        foreach (array_slice($history, -12) as $h) {
            if (!is_array($h)) continue;
            $text = trim((string) ($h['content'] ?? $h['text'] ?? ''));
            if ($text === '') continue;
            $turns[] = ['role' => ($h['role'] ?? '') === 'assistant' ? 'assistant' : 'user',
                        'content' => mb_substr($text, 0, 2000)];
        }

        $r = $this->support->ask($message, $turns, SupportContext::fromSession($this->rateLimit, $ip));

        return [
            'ok'        => true,
            'reply'     => $r['reply'],
            'source'    => 'support',
            'support'   => true,
            // Shown in the widget as "re-checked your payment" — an assistant that
            // says where its answer came from is one people can sanity-check.
            'used'      => $r['used'],
            'ticket'    => $r['ticket'],
            'escalated' => $r['escalated'],
            'articles'  => HelpCentre::previews($message,
                SupportAgentService::citedSlugs($r['results'] ?? []), 3, lastResort: true),
        ];
    }

    /**
     * INBOUND agent bridge — POST /api/v1/agent/gee. Lets an external AI agent
     * (e.g. the configured Make.com agent) query Gee and the live site state,
     * so the two communicate BOTH ways. Bearer-authenticated with the same
     * shared key as the outbound bridge; 404 when no key is configured so the
     * endpoint is invisible until a superadmin enables the integration.
     *
     * ── DELIBERATELY GUIDE-ONLY ──────────────────────────────────────────────
     *
     * This endpoint never routes to the support brain, however support-shaped the
     * message is. It is authenticated by a SHARED KEY, which identifies an
     * integration and not a person — there is no session behind it, so there is no
     * identity to scope a read to, and a caller holding the key could otherwise
     * ask about anybody at all. An external agent that wants a payment repaired
     * sends its user here to sign in; it does not get to act as them.
     *
     * JSON in: {message, history?, page?, include_state?:bool}
     * Out: {ok, reply, source, site_state?}
     */
    public function agent(Request $req, Response $res): Response
    {
        $key = $this->guide->agentKey();
        if ($key === '' || strlen($key) < 16) return $res->withStatus(404);
        $given = trim((string) preg_replace('/^Bearer\s+/i', '', $req->getHeaderLine('Authorization')));
        if ($given === '' || !hash_equals($key, $given)) {
            return $this->json($res, ['ok' => false, 'error' => 'Unauthorized.'], 401);
        }
        if ($this->rateLimit && !$this->rateLimit->check('agent|gee', 'gee_agent_day', 2000, 86400)) {
            return $this->json($res, ['ok' => false, 'error' => 'Daily agent budget reached.'], 429);
        }

        $b       = (array)$req->getParsedBody();
        $message = trim((string)($b['message'] ?? ''));
        if ($message === '' || mb_strlen($message) > 2000) {
            return $this->json($res, ['ok' => false, 'error' => "Provide 'message' (1–2000 chars)."], 422);
        }
        $history = is_array($b['history'] ?? null) ? $b['history'] : [];
        $pageIn  = is_array($b['page'] ?? null) ? $b['page'] : [];
        $page    = [
            'title' => mb_substr((string)($pageIn['title'] ?? ''), 0, 120),
            'path'  => mb_substr((string)($pageIn['path'] ?? ''), 0, 200),
        ];

        try {
            // allowBridge=false — an agent asking Gee must never bounce back out
            // to the agent (infinite loop); Gee answers from its own providers.
            $out = $this->guide->answer($message, $history, $page, allowBridge: false);
            $payload = ['ok' => true, 'reply' => $out['reply'], 'source' => $out['source']];
            if (!empty($b['include_state'])) $payload['site_state'] = $this->guide->siteState();
            return $this->json($res, $payload);
        } catch (\Throwable $e) {
            $this->log?->error('[gee] agent endpoint failed', ['err' => $e->getMessage()]);
            return $this->json($res, ['ok' => false, 'error' => 'Internal error.'], 500);
        }
    }
}
