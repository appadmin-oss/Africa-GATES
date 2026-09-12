<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use AfricaGates\Admin\Services\AuditService;

/**
 * Community moderation queue — the human safety-net behind the automatic AI
 * filter. On submit, SpamService auto-decides every post: allow → live,
 * reject → never stored. Only borderline content lands in 'quarantined' limbo
 * (invisible to the public). This screen lets moderators release or remove it,
 * so admins no longer approve every post — they only review the grey area.
 */
class ModerationController
{
    public function __construct(
        private readonly Twig $view,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $req, Response $res): Response
    {
        $comments = DB::table('gates_comments')->where('status', 'quarantined')->orderByDesc('id')->limit(100)
            ->get()->map(fn($r) => (array)$r)->all();
        $threads = DB::table('gates_threads')->where('status', 'quarantined')->orderByDesc('id')->limit(100)
            ->get()->map(fn($r) => (array)$r)->all();
        $recentLog = DB::table('gates_moderation_log')->orderByDesc('id')->limit(40)
            ->get()->map(fn($r) => (array)$r)->all();

        // ── VOTE MESSAGES ARE THE SHARPEST ITEM IN THIS QUEUE ────────────────
        //
        // A quarantined forum comment is a nuisance. A quarantined message of support
        // is user-written text attached to a NAMED REAL PERSON — often a child — on
        // that person's own page, and several nominees never asked to be nominated.
        // So it is surfaced here first, with the nominee's name on every row, so a
        // moderator can see who the words are about before deciding.
        //
        // 'pending' as well as 'quarantined': a message held because the classifier
        // was unavailable is not borderline, it is UNJUDGED, and it must not sit
        // invisible waiting for a decision nobody knows is owed.
        $voteMessages = \AfricaGates\Services\VoteMessageService::queue(100);

        return $this->view->render($res, 'admin/moderation/index.twig', [
            'page_title'  => 'Moderation Queue — Admin',
            'admin_page'  => 'moderation',
            'comments'    => $comments,
            'threads'     => $threads,
            'vote_messages' => $voteMessages,
            'recent_log'  => $recentLog,
            'pending'     => count($comments) + count($threads) + count($voteMessages),
            // Flash banners render from the Twig globals via the layout — passing
            // them here would shadow the (already-consumed) global with null.
        ]);
    }

    public function action(Request $req, Response $res, array $args): Response
    {
        $type     = (string)($args['type'] ?? '');
        $id       = (int)($args['id'] ?? 0);
        $decision = (string)($args['decision'] ?? '');
        if (!in_array($type, ['comment', 'thread', 'vote_message'], true) || !in_array($decision, ['approve', 'remove', 'recheck'], true) || $id < 1) {
            $_SESSION['flash_error'] = 'Invalid moderation action.';
            return $res->withHeader('Location', '/admin/moderation')->withStatus(302);
        }

        // ── VOTE MESSAGES: DECIDED THROUGH THEIR OWN SERVICE ─────────────────
        //
        // Handled before the generic branches rather than by adding a third table to
        // them, because the state transitions differ. `remove` here is a SOFT delete:
        // a message about a real person that had to be taken down is exactly the row
        // an appeal, a complaint or a safeguarding question is asked about later, and
        // a hard delete answers none of them. And a re-check re-runs the pipeline
        // through the same service the public path uses, so an operator who has just
        // tuned the thresholds sees the tuned verdict, not a second implementation of
        // one.
        if ($type === 'vote_message') {
            $svc = \AfricaGates\Services\VoteMessageService::class;
            if ($decision === 'recheck') {
                $row = DB::table('gates_vote_messages')->where('id', $id)->first();
                if (!$row) {
                    $_SESSION['flash_error'] = 'That message no longer exists.';
                    return $res->withHeader('Location', '/admin/moderation')->withStatus(302);
                }
                $spam    = new \AfricaGates\Services\SpamService(\AfricaGates\Services\AiService::boot('moderation'));
                $verdict = $spam->evaluate((string)$row->body, ['target' => 'vote_message', 'recheck' => true]);
                $spam->logDecision('vote_message', $id, $verdict);
                if ($verdict['decision'] === 'allow') {
                    $svc::decide($id, 'approve', (int)($_SESSION['admin_id'] ?? 0));
                    $_SESSION['flash_ok'] = sprintf('AI re-check: clean (score %.2f, %s) — published.', $verdict['score'], $verdict['provider']);
                } elseif ($verdict['decision'] === 'reject') {
                    $svc::decide($id, 'reject', (int)($_SESSION['admin_id'] ?? 0));
                    $_SESSION['flash_ok'] = sprintf('AI re-check: refused (score %.2f, %s) — not published.', $verdict['score'], $verdict['provider']);
                } else {
                    $_SESSION['flash_ok'] = sprintf('AI re-check: still borderline (score %.2f, %s) — left for your call.', $verdict['score'], $verdict['provider']);
                }
                $this->audit->record((int)($_SESSION['admin_id'] ?? 0), 'moderation.recheck', 'vote_message', $id, ['decision' => $verdict['decision'], 'score' => $verdict['score']]);
                return $res->withHeader('Location', '/admin/moderation')->withStatus(302);
            }

            $adminId = (int)($_SESSION['admin_id'] ?? 0);
            if ($decision === 'approve') {
                $ok = $svc::decide($id, 'approve', $adminId);
            } else {
                // Rejected AND soft-deleted: the status stops it being served, the
                // timestamp records that a person took it down.
                $ok = $svc::decide($id, 'reject', $adminId) && $svc::withdraw($id, $adminId);
            }
            $this->audit->record($adminId, 'moderation.' . $decision, 'vote_message', $id);
            \AfricaGates\Services\WebhookService::dispatch('moderation.actioned', [
                'target_type' => 'vote_message', 'target_id' => $id,
                'decision'    => $decision, 'new_status' => $decision === 'approve' ? 'approved' : 'rejected',
            ]);
            $_SESSION[$ok ? 'flash_ok' : 'flash_error'] = $ok
                ? ('Message ' . ($decision === 'approve' ? 'approved and published.' : 'removed.'))
                : 'Could not update that message.';
            return $res->withHeader('Location', '/admin/moderation')->withStatus(302);
        }

        // AI re-check: run the CURRENT moderation pipeline (fresh provider,
        // fresh thresholds) over a quarantined item and apply its verdict —
        // useful after configuring an AI key or tuning thresholds. Logged to
        // the same audit trail as automatic decisions.
        if ($decision === 'recheck') {
            $table = $type === 'comment' ? 'gates_comments' : 'gates_threads';
            $row = DB::table($table)->where('id', $id)->first();
            if (!$row || (string)$row->status !== 'quarantined') {
                $_SESSION['flash_error'] = 'Only quarantined items can be re-checked.';
                return $res->withHeader('Location', '/admin/moderation')->withStatus(302);
            }
            $text = $type === 'comment' ? (string)$row->body : trim((string)$row->title . "\n\n" . (string)$row->body);
            $spam = new \AfricaGates\Services\SpamService(\AfricaGates\Services\AiService::boot('moderation'));
            $verdict = $spam->evaluate($text, ['target' => $type, 'recheck' => true]);
            $spam->logDecision($type, $id, $verdict);

            if ($verdict['decision'] === 'allow') {
                DB::table($table)->where('id', $id)->update(['status' => 'approved']);
                if ($type === 'comment' && (string)$row->target_type === 'thread') {
                    $count = (int)DB::table('gates_comments')->where('target_type', 'thread')->where('target_id', $row->target_id)->where('status', 'approved')->count();
                    DB::table('gates_threads')->where('id', $row->target_id)->update(['reply_count' => $count, 'last_activity' => Carbon::now()->toDateTimeString()]);
                }
                $_SESSION['flash_ok'] = sprintf('AI re-check: clean (score %.2f, %s) — published.', $verdict['score'], $verdict['provider']);
            } elseif ($verdict['decision'] === 'reject') {
                DB::table($table)->where('id', $id)->update(['status' => 'rejected']);
                $_SESSION['flash_ok'] = sprintf('AI re-check: spam (score %.2f, %s) — removed.', $verdict['score'], $verdict['provider']);
            } else {
                $_SESSION['flash_ok'] = sprintf('AI re-check: still borderline (score %.2f, %s) — kept in the queue for your call.', $verdict['score'], $verdict['provider']);
            }
            $this->audit->record((int)$_SESSION['admin_id'], 'moderation.recheck', $type, $id, ['decision' => $verdict['decision'], 'score' => $verdict['score']]);
            return $res->withHeader('Location', '/admin/moderation')->withStatus(302);
        }
        $newStatus = $decision === 'approve' ? 'approved' : 'rejected';

        if ($type === 'comment') {
            $c = DB::table('gates_comments')->where('id', $id)->first();
            if ($c) {
                DB::table('gates_comments')->where('id', $id)->update(['status' => $newStatus]);
                // Releasing a thread reply → reflect it in the thread's reply tally + bump activity.
                if ($decision === 'approve' && $c->target_type === 'thread') {
                    $count = (int)DB::table('gates_comments')->where('target_type', 'thread')->where('target_id', $c->target_id)->where('status', 'approved')->count();
                    DB::table('gates_threads')->where('id', $c->target_id)->update(['reply_count' => $count, 'last_activity' => Carbon::now()->toDateTimeString()]);
                }
            }
        } else {
            DB::table('gates_threads')->where('id', $id)->update(['status' => $newStatus]);
        }

        $this->audit->record((int)$_SESSION['admin_id'], 'moderation.' . $decision, $type, $id);
        \AfricaGates\Services\WebhookService::dispatch('moderation.actioned', [
            'target_type' => $type,
            'target_id'   => $id,
            'decision'    => $decision,
            'new_status'  => $newStatus,
        ]);
        $_SESSION['flash_ok'] = ucfirst($type) . ' ' . ($decision === 'approve' ? 'approved and published.' : 'removed.');
        return $res->withHeader('Location', '/admin/moderation')->withStatus(302);
    }

    /** Operator thread controls: lock/unlock (readable, no replies) + pin/unpin. */
    public function threadFlag(Request $req, Response $res, array $args): Response
    {
        $id   = (int)($args['id'] ?? 0);
        $flag = (string)($args['flag'] ?? '');
        $on   = ($args['on'] ?? '0') === '1';
        if ($id < 1 || !in_array($flag, ['locked', 'pinned'], true)) {
            $_SESSION['flash_error'] = 'Invalid thread action.';
            return $res->withHeader('Location', '/admin/moderation')->withStatus(302);
        }
        $svc = new \AfricaGates\Services\CommunityService(new \AfricaGates\Services\SpamService());
        $ok = $svc->setThreadFlag($id, $flag, $on);
        if ($ok) {
            $this->audit->record((int)$_SESSION['admin_id'], 'thread.' . $flag . '.' . ($on ? 'on' : 'off'), 'thread', $id);
            \AfricaGates\Services\WebhookService::dispatch('moderation.actioned', [
                'target_type' => 'thread', 'target_id' => $id,
                'decision'    => $flag . ($on ? '' : '.removed'), 'new_status' => $flag === 'locked' ? ($on ? 'locked' : 'approved') : 'approved',
            ]);
            $_SESSION['flash_ok'] = 'Thread ' . ($on ? $flag : 'un' . $flag) . '.';
        } else {
            $_SESSION['flash_error'] = 'Could not update the thread.';
        }
        return $res->withHeader('Location', '/admin/moderation')->withStatus(302);
    }
}
