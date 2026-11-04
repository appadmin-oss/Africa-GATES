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

    /**
     * The assistant, expanded: conversations on the left, the conversation in the middle,
     * the evidence beside it (README §10b "Ask", after the design the owner pointed at).
     * `?t=N` opens one of this person's conversations.
     */
    public function index(Request $req, Response $res): Response
    {
        $adminId = (int) ($_SESSION['admin_id'] ?? 0);
        $t = (int) ($req->getQueryParams()['t'] ?? 0);
        return $this->view->render($res, 'admin/assistant.twig', [
            'page_title'    => 'Assistant — Admin',
            'admin_page'    => 'assistant',
            // Availability, not merely "a key exists" — the switches and today's
            // budget decide too, and the console must not promise what it cannot do.
            'ai_configured' => \AfricaGates\Services\AiGateway::available('admin.assistant')
                               || \AfricaGates\Services\AiGateway::available(\AfricaGates\Services\Ops\OpsAgent::CAPABILITY),
            'is_superadmin' => (($_SESSION['admin_role'] ?? '') === 'superadmin'),
            'open_thread'   => \AfricaGates\Admin\Services\AssistantThreads::find($t, $adminId)['id'] ?? 0,
            'budget'        => self::BUDGET_PER_HOUR,
        ]);
    }

    /** GET /admin/assistant/threads/feed?q= — this person's conversations, for the side list. */
    public function threads(Request $req, Response $res): Response
    {
        $q = mb_substr((string) ($req->getQueryParams()['q'] ?? ''), 0, 80);
        return self::json($res, ['ok' => true,
            'threads' => \AfricaGates\Admin\Services\AssistantThreads::list((int) ($_SESSION['admin_id'] ?? 0), $q)]);
    }

    /** GET /admin/assistant/threads/{id} — one conversation of theirs, every turn with its evidence. */
    public function thread(Request $req, Response $res, array $args): Response
    {
        $adminId = (int) ($_SESSION['admin_id'] ?? 0);
        $t = \AfricaGates\Admin\Services\AssistantThreads::find((int) ($args['id'] ?? 0), $adminId);
        if ($t === null) return self::json($res, ['ok' => false, 'error' => 'That conversation is not here any more.'], 404);
        return self::json($res, ['ok' => true, 'thread' => $t,
            'messages' => \AfricaGates\Admin\Services\AssistantThreads::messages($t['id'], $adminId)]);
    }

    /** POST /admin/assistant/threads/{id}/delete */
    public function deleteThread(Request $req, Response $res, array $args): Response
    {
        $ok = \AfricaGates\Admin\Services\AssistantThreads::delete((int) ($args['id'] ?? 0), (int) ($_SESSION['admin_id'] ?? 0));
        return self::json($res, $ok ? ['ok' => true] : ['ok' => false, 'error' => 'That conversation is not here any more.'], $ok ? 200 : 404);
    }

    /** POST /admin/assistant/messages/{id}/rate — helpful (1), not (-1), or neither (0). */
    public function rate(Request $req, Response $res, array $args): Response
    {
        $b = $req->getParsedBody();
        $v = (int) (is_array($b) ? ($b['value'] ?? 0) : 0);
        $ok = \AfricaGates\Admin\Services\AssistantThreads::rate((int) ($args['id'] ?? 0), (int) ($_SESSION['admin_id'] ?? 0), $v);
        return self::json($res, ['ok' => $ok], $ok ? 200 : 404);
    }

    private static function json(Response $res, array $p, int $code = 200): Response
    {
        $res->getBody()->write((string) json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $res->withHeader('Content-Type', 'application/json')->withStatus($code);
    }

    /**
     * Where the operator is, and the pages they tagged with @, each held to an /admin page
     * their role may open — a tag is a hint to the model, never a way to read a page the
     * rail would not show them.
     *
     * @return array{page?:string, page_label?:string, tags:list<array{label:string,href:string}>}
     */
    private static function context(array $b, string $role): array
    {
        $byHref = [];
        foreach (\AfricaGates\Admin\Support\AdminNav::destinations($role) as $d) $byHref[$d['href']] = $d['label'];
        $out = ['tags' => []];
        // A path the browser sent from location.pathname — never a whole address, or any host
        // would pass by having "/admin/payments" in its path.
        $page = (string) ($b['page'] ?? '');
        $path = str_starts_with($page, '/admin') ? (string) (parse_url($page, PHP_URL_PATH) ?? '') : '';
        if (preg_match('~^/admin(?:/[A-Za-z0-9_\-][A-Za-z0-9._\-]*)*$~', $path) === 1 && $path !== '/admin/assistant'
            && \AfricaGates\Admin\Support\Permissions::canOpen($role, $path)) {
            $qs = (string) (parse_url($page, PHP_URL_QUERY) ?? '');
            $out['page'] = mb_substr($path . ($qs !== '' && preg_match('/^[A-Za-z0-9=&._%\-]{1,200}$/', $qs) === 1 ? '?' . $qs : ''), 0, 300);
            $label = $byHref[$path] ?? null;
            if ($label === null) {   // a sub-page: the nearest destination it sits under
                foreach ($byHref as $h => $l) if (str_starts_with($path, $h . '/') && strlen($h) > strlen((string) ($best ?? ''))) { $best = $h; $label = $l; }
            }
            $out['page_label'] = (string) ($label ?? 'a console page');
        }
        foreach (array_slice(is_array($b['tags'] ?? null) ? $b['tags'] : [], 0, 5) as $h) {
            $h = (string) $h;
            if (isset($byHref[$h])) $out['tags'][] = ['label' => $byHref[$h], 'href' => $h];
        }
        return $out;
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
        $context = self::context($b, $role);

        // ── THE CONVERSATION IS KEPT, AND IS THE HISTORY ─────────────────────
        // The server's record, not what the browser sent back: a history the client supplies
        // is one the client can write, and the turns sent to the model are then whatever it said.
        $T = \AfricaGates\Admin\Services\AssistantThreads::class;
        $threadId = (int) ($T::find((int) ($b['thread_id'] ?? 0), $adminId)['id'] ?? 0);
        $history = $threadId > 0 ? $T::history($threadId, $adminId)
                 : (is_array($b['history'] ?? null) && !$T::ready() ? array_slice($b['history'], -10) : []);
        if ($threadId < 1) $threadId = (int) ($T::create($adminId, $message) ?? 0);
        if ($threadId > 0) $T::append($threadId, 'user', $message, [], [], (string) ($context['page'] ?? ''));

        $done = function (string $reply, array $ran = [], array $runs = [], array $actions = []) use ($json, $T, $threadId, $adminId): Response {
            $msg = null;
            if ($threadId > 0 && ($id = $T::append($threadId, 'assistant', $reply, $runs, $actions)) !== null) {
                foreach ($T::messages($threadId, $adminId) as $m) if ($m['id'] === $id) $msg = $m;
            }
            $msg ??= ['id' => 0, 'role' => 'assistant', 'text' => \AfricaGates\Support\ChatMarkdown::plain($reply),
                      'html' => \AfricaGates\Support\ChatMarkdown::html($reply), 'ran' => [], 'actions' => $actions,
                      'evidence' => array_map(static fn (array $r): array => $T::shape($r['key'], $r['title'], $r['ok'], $r['ms'], $r['output']), $runs),
                      'rating' => 0];
            return $json(['ok' => true, 'thread' => $T::find($threadId, $adminId), 'message' => $msg,
                          // The shape the drawer read before this change, kept for a page still open on the old script.
                          'reply' => $reply, 'ran' => $ran, 'actions' => $actions]);
        };

        // ── THE AGENT FIRST: it runs the platform's own checks, then answers ──
        // Far cheaper per question than the snapshot below (a short cached prompt, and only
        // the evidence the question needs), and it can actually find things out.
        try {
            $agent = (new \AfricaGates\Services\Ops\OpsAgent(AiService::boot()))
                ->answer($message, $history, $role, $adminId ?: null, $context);
            if ($agent !== null) {
                return $done($agent['reply'], $agent['ran'], $agent['runs'] ?? [], $agent['actions']);
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
                'NO_PROVIDER'         => 'No AI provider is configured. A superadmin can add a key (Groq is free) under Settings → AI.',
                'DISABLED_GLOBAL'     => 'AI is switched off for this platform (Settings → ai_enabled).',
                'DISABLED_CAPABILITY' => 'The assistant is switched off (Settings → ai_cap_disabled_admin_assistant).',
                'BUDGET_CALLS',
                'BUDGET_TOKENS'       => 'The assistant has reached today\'s AI budget. It resets at midnight.',
                default               => 'The AI provider did not answer. Try again, or check the key under Settings → AI.',
            }], $r->code === 'NO_PROVIDER' ? 503 : 502);
        }
        return $done((string) $r->value);
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
