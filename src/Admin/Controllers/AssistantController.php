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
        $state = $this->operationalState();
        $stateJson = (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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

    /** Read-only operational snapshot — every query individually fault-tolerant. */
    private function operationalState(): array
    {
        $get = static function (callable $q, $fallback = null) {
            try { return $q(); } catch (\Throwable) { return $fallback; }
        };
        $today = date('Y-m-d 00:00:00');
        return [
            'generated_at'            => date('c'),
            'nominations_pending'     => $get(fn() => (int) DB::table('gates_nominations')->where('status', 'pending')->count(), 0),
            'moderation_quarantined'  => $get(fn() => (int) DB::table('gates_comments')->where('status', 'quarantined')->count()
                                                    + (int) DB::table('gates_threads')->where('status', 'quarantined')->count(), 0),
            'votes_today'             => $get(fn() => (int) DB::table('gates_votes')->where('voted_at', '>=', $today)->count(), 0),
            'votes_total'             => $get(fn() => (int) DB::table('gates_votes')->count(), 0),
            'members_total'           => $get(fn() => (int) DB::table('gates_users')->where('status', 'active')->count(), 0),
            'orders_pending'          => $get(fn() => (int) DB::table('gates_orders')->where('status', 'pending')->count(), 0),
            'donations_pending'       => $get(fn() => (int) DB::table('gates_donations')->where('status', 'pending')->count(), 0),
            // The COMPUTED phase per cycle, and no `year = date('Y')` filter.
            // This previously reported the raw status column alongside
            // voting_close — the exact pair that can contradict each other — and
            // filtered to the calendar year, so the copilot could confidently
            // narrate a state that was not real while being blind to any
            // in-flight cycle tagged with a different year.
            'cycles'                  => $get(fn() => DB::table('gates_award_cycles as c')
                ->join('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
                ->where('c.status', '!=', 'archived')
                ->get(['p.title', 'c.id', 'c.year', 'c.status', 'c.nominations_open', 'c.nominations_close',
                       'c.voting_open', 'c.voting_close', 'c.results_date'])
                ->map(function ($r) {
                    $phase = \AfricaGates\Services\CyclePolicy::stateFor($r);
                    return [
                        'programme'           => (string) $r->title,
                        'year'                => (int) $r->year,
                        // What the platform actually does right now.
                        'phase'               => $phase['phase'],
                        'accepting_votes'     => $phase['is_voting_open'],
                        'accepting_nominations' => $phase['is_nominations_open'],
                        'deadline'            => $phase['closes_at'],
                        'note'                => $phase['detail'],
                        // Flagged so the assistant can tell an operator the
                        // cached column is behind, rather than trusting it.
                        'cached_status_stale' => $phase['drifted'],
                    ];
                })->all(), []),
            // Cycles whose declared boundary has passed but whose materialised
            // status has not caught up. Traffic-independent, so it surfaces
            // cycles nobody happens to be voting in.
            'phase_divergences'       => $get(fn() => count(\AfricaGates\Services\CycleMaterialiser::divergences()), 0),
            // Schema integrity. Read-only. Present because every defect in the index
            // repair shared one shape: it failed, printed a warning, and nobody read
            // it. A missing uniqueness constraint must keep announcing itself rather
            // than wait to be discovered by a double-counted vote.
            'schema_warnings'         => $get(fn() => \AfricaGates\Services\VoteIndexRepair::warnings(), []),
            // AI spend today, per capability — previously unknowable.
            'ai_spend_today'          => $get(fn() => \AfricaGates\Services\AiGateway::spendReport(), []),
            'webhook_failures_24h'    => $get(fn() => (int) DB::table('gates_webhook_deliveries')->where('ok', 0)->where('created_at', '>=', date('Y-m-d H:i:s', time() - 86400))->count(), 0),
            'messages_failed_24h'     => $get(fn() => (int) DB::table('gates_messages')->where('status', 'failed')->where('created_at', '>=', date('Y-m-d H:i:s', time() - 86400))->count(), 0),
        ];
    }
}
