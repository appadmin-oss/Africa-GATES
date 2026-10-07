<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Services\AiService;
use AfricaGates\Services\RateLimitService;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;

/**
 * The admin AI assistant — a console copilot grounded with LIVE, read-only
 * operational state (queues, cycles, counts) so operators can ask "what needs
 * my attention?" and get a real answer.
 *
 * Access: every console role can use it (section: overview). The SUPERADMIN
 * assistant is unlimited; other roles share a per-admin hourly budget.
 * Unlike the public site, AI failures here are LOUD — an operator must know
 * the provider is down/unconfigured, never get a silently degraded answer.
 */
final class AssistantController
{
    private const BUDGET_PER_HOUR = 60; // non-superadmin roles

    public function __construct(
        private readonly Twig $view,
        private readonly ?RateLimitService $rateLimit = null,
        private readonly ?LoggerInterface $log = null,
    ) {}

    public function index(Request $req, Response $res): Response
    {
        return $this->view->render($res, 'admin/assistant.twig', [
            'page_title'    => 'AI Assistant — Admin',
            'admin_page'    => 'assistant',
            // Availability, not merely "a key exists" — the switches and today's
            // budget decide too, and the console must not promise what it cannot do.
            'ai_configured' => \AfricaGates\Services\AiGateway::available('admin.assistant'),
            'ai_provider'   => AiService::boot()->activeProvider(),
            'is_superadmin' => (($_SESSION['admin_role'] ?? '') === 'superadmin'),
        ]);
    }

    public function chat(Request $req, Response $res): Response
    {
        $json = function (array $p, int $code = 200) use ($res): Response {
            $res->getBody()->write((string) json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return $res->withHeader('Content-Type', 'application/json')->withStatus($code);
        };

        $role    = (string) ($_SESSION['admin_role'] ?? '');
        $adminId = (int) ($_SESSION['admin_id'] ?? 0);
        // Superadmin is deliberately unlimited; every other role shares a budget.
        if ($role !== 'superadmin' && $this->rateLimit
            && !$this->rateLimit->check('admin:' . $adminId, 'admin_ai', self::BUDGET_PER_HOUR, 3600)) {
            return $json(['ok' => false, 'error' => 'You have reached this hour\'s assistant budget (' . self::BUDGET_PER_HOUR . ' messages). It resets within the hour.'], 429);
        }

        $b       = (array) $req->getParsedBody();
        $message = trim((string) ($b['message'] ?? ''));
        if ($message === '' || mb_strlen($message) > 2000) {
            return $json(['ok' => false, 'error' => 'Provide a message (1–2000 characters).'], 422);
        }
        $history = is_array($b['history'] ?? null) ? array_slice($b['history'], -10) : [];

        // ── THE AGENT FIRST: it runs the platform's own checks, then answers ──
        // Far cheaper per question than the snapshot below (a short cached prompt, and only
        // the evidence the question needs), and it can actually find things out.
        try {
            $agent = (new \AfricaGates\Services\Ops\OpsAgent(AiService::boot()))
                ->answer($message, $history, $role, $adminId ?: null);
            if ($agent !== null) {
                return $json(['ok' => true, 'reply' => $agent['reply'], 'ran' => $agent['ran'], 'actions' => $agent['actions']]);
            }
        } catch (\Throwable $e) {
            $this->log?->warning('[admin-assistant] agent failed; one-shot answer instead', ['err' => $e->getMessage()]);
        }

        $transcript = [];
        foreach ($history as $h) {
            if (!is_array($h)) continue;
            $t = trim((string) ($h['text'] ?? ''));
            if ($t === '') continue;
            $transcript[] = ((($h['role'] ?? '') === 'assistant') ? 'Assistant' : 'Operator') . ': ' . mb_substr($t, 0, 2000);
        }
        $transcript[] = 'Operator: ' . mb_substr($message, 0, 2000);
        $transcript[] = 'Assistant:';

        // FAIL_ANNOUNCE, and LOUD by design: the console must never pretend AI is
        // working. Every refusal reason — no provider, switched off, over budget —
        // is now distinguishable instead of collapsing into one 502.
        $r = (new \AfricaGates\Services\AiGateway())->run('admin.assistant', [
            'system'      => $this->systemPrompt($role),
            'trusted'     => 'The operator conversation follows.',
            'user'        => implode("\n", $transcript),
            'temperature' => 0.3,
            'schema'      => static function (string $raw): ?string {
                $t = trim($raw);
                return $t === '' ? null : $t;
            },
        ]);

        if (!$r->ok) {
            $this->log?->error('[admin-assistant] AI unavailable', ['code' => $r->code]);
            return $json(['ok' => false, 'error' => match ($r->code) {
                'NO_PROVIDER'         => 'No AI provider is configured. A superadmin can add a key (Groq is free) under Settings → AI providers.',
                'DISABLED_GLOBAL'     => 'AI is switched off for this platform (Settings → ai_enabled).',
                'DISABLED_CAPABILITY' => 'The assistant is switched off (Settings → ai_cap_disabled_admin_assistant).',
                'BUDGET_CALLS',
                'BUDGET_TOKENS'       => 'The assistant has reached today\'s AI budget. It resets at midnight.',
                default               => 'The AI provider did not answer. Try again, or check the key under Settings → AI providers.',
            }], $r->code === 'NO_PROVIDER' ? 503 : 502);
        }
        return $json(['ok' => true, 'reply' => $r->value]);
    }

    // ── Grounding ──────────────────────────────────────────────────────────

    private function systemPrompt(string $role): string
    {
        // Compact, not pretty-printed: the indentation was a third of the tokens and the
        // model reads either equally well. One snapshot, shared with the ops scripts.
        $state = \AfricaGates\Services\Ops\OpsScripts::snapshot();
        $state['ai_spend_today'] = \AfricaGates\Services\AiGateway::spendReport();
        $stateJson = \AfricaGates\Services\SupportAgentService::compact($state);
        return <<<SYS
You are the Africa GATES ADMIN ASSISTANT — a concise operations copilot inside the admin console of the continental Cultural Power Index platform (Slim 4 + MySQL; public voting, jury scoring, nominations, shop, donations, events, community).

The operator's console role is: {$role}.

LIVE OPERATIONAL STATE (read-only, freshly queried — you may quote these numbers):
{$stateJson}

CONSOLE AREAS you can direct the operator to (write the bare path): /admin/dashboard, /admin/nominations (review queue), /admin/moderation (quarantined community content), /admin/profiles, /admin/programmes, /admin/events, /admin/posts, /admin/data (datasets: votes, donations, orders, users), /admin/webhooks, /admin/settings (superadmin), /admin/judges (superadmin).

HOW TO RESPOND
- Be direct and operational: lead with what matters, quantify from the live state, then say where to act.
- If asked "what needs attention", triage: pending nominations, quarantined content, stale pending payments, cycles approaching their deadline, and any phase_divergences (which mean the scheduled task is behind).
- The `phase` field is COMPUTED from each cycle's date windows and is authoritative for whether votes and nominations are being accepted. `cached_status_stale` true means the stored status column is behind — the site is still behaving correctly, but reports reading that column are wrong until the scheduled task catches up.
- `schema_warnings` are DATABASE INTEGRITY problems, not content problems. A `critical` one means a guarantee the platform advertises is currently absent — say so plainly, quote its `fix` command, and treat it as more urgent than any queue length.
- NEVER invent numbers beyond the live state above. If you don't have a figure, say which /admin page shows it.
- You advise and point; you cannot change data yourself. Actions requiring superadmin (settings, webhooks, judges, admins) should be flagged as such.
- Keep answers under ~180 words unless the operator asks for depth.
SYS;
    }

    // ── Scripts: run by a person ──────────────────────────────────────────

    /**
     * GET /admin/assistant/scripts — every script this role may run, with its last result.
     * The same scripts the assistant runs itself; repairs are run only from here.
     */
    public function scripts(Request $req, Response $res): Response
    {
        $role = (string) ($_SESSION['admin_role'] ?? '');
        $runs = \AfricaGates\Services\Ops\OpsScripts::lastRuns();
        $list = [];
        foreach (\AfricaGates\Services\Ops\OpsScripts::forRole($role) as $key => $s) {
            $list[] = $s + ['key' => $key, 'last' => $runs[$key] ?? null];
        }
        return $this->view->render($res, 'admin/assistant/scripts.twig', [
            'page_title'   => 'Operations scripts',
            'topbar_title' => 'Operations scripts',
            'admin_page'   => 'assistant',
            'checks'       => array_values(array_filter($list, static fn ($s) => $s['kind'] === 'check')),
            'repairs'      => array_values(array_filter($list, static fn ($s) => $s['kind'] === 'repair')),
        ]);
    }

    /** POST /admin/assistant/scripts/{key} — run one, as a person. A repair carries its reason. */
    public function runScript(Request $req, Response $res, array $args): Response
    {
        $key  = (string) ($args['key'] ?? '');
        $b    = (array) $req->getParsedBody();
        $r = \AfricaGates\Services\Ops\OpsScripts::run($key, (string) ($_SESSION['admin_role'] ?? ''),
            (int) ($_SESSION['admin_id'] ?? 0) ?: null, 'person', (string) ($b['_reason'] ?? ''));
        $_SESSION[$r['ok'] ? 'flash_ok' : 'flash_error'] = $r['ok']
            ? $r['title'] . ' — done in ' . number_format($r['ms'] / 1000, 1) . 's. The result is below.'
            : $r['title'] . ' — ' . ($r['error'] ?? 'it reported a problem; the result is below.');
        return $res->withHeader('Location', '/admin/assistant/scripts#' . rawurlencode($key))->withStatus(302);
    }
}
