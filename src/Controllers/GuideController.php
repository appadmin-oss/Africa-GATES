<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use AfricaGates\Services\{GuideService, RateLimitService};

/** Gee chat endpoint — POST /api/guide. First-party, rate-limited, input-capped. */
final class GuideController
{
    public function __construct(
        private readonly GuideService      $guide,
        private readonly ?RateLimitService $rateLimit = null,
        private readonly ?LoggerInterface  $log = null,
    ) {}

    private function json(Response $res, array $data, int $status = 200): Response
    {
        $res->getBody()->write((string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $res->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    /** JSON in: {message, history?:[{role,text}], page?:{title,path}}. Out: {ok, reply, source}. */
    public function chat(Request $req, Response $res): Response
    {
        // Invisible rate limiting: beyond the per-IP AI budget (or the global
        // daily budget) Gee degrades to the scripted tier — a 200 with a real
        // answer, never an error. Only a hard abuse wall (5× the budget)
        // returns 429, so the limit is imperceptible to normal humans.
        $useAi = true;
        if ($this->rateLimit) {
            $ip = (string)($req->getServerParams()['REMOTE_ADDR'] ?? '');
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

        try {
            $out = $useAi ? $this->guide->answer($message, $history, $page) : $this->guide->scripted($message);
            return $this->json($res, ['ok' => true, 'reply' => $out['reply'], 'source' => $out['source']]);
        } catch (\Throwable $e) {
            $this->log?->error('[gee] chat failed', ['err' => $e->getMessage()]);
            return $this->json($res, ['ok' => false, 'reply' => "I hit a snag just now. In the meantime, /help and /support have you covered."], 500);
        }
    }

    /**
     * INBOUND agent bridge — POST /api/v1/agent/gee. Lets an external AI agent
     * (e.g. the configured Make.com agent) query Gee and the live site state,
     * so the two communicate BOTH ways. Bearer-authenticated with the same
     * shared key as the outbound bridge; 404 when no key is configured so the
     * endpoint is invisible until a superadmin enables the integration.
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
