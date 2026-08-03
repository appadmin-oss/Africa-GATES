<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Services\SupportTicketService;
use AfricaGates\Services\VoteProof;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * The support queue, for the people who answer it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS DID NOT EXIST, AND WHY THAT WAS A REAL PROBLEM
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Tickets have been stored in the database from the beginning, and staff have been
 * reading them in EMAIL. The rows were the record; the inbox was the workflow. Six
 * things follow from that split, and every one of them was happening:
 *
 *   1. A reply sent from an inbox never reaches `gates_support_messages`, so the
 *      member's own thread page shows their question and silence — while somebody
 *      has in fact answered them.
 *   2. Two people answer the same ticket, because an inbox has no notion of
 *      "someone is on this".
 *   3. Nothing is ever closed, because closing lives in a table nobody opens.
 *   4. `is_internal` exists and cannot be used: an inbox has no private note.
 *   5. The context that would answer the ticket — the payment, the votes — is in
 *      the admin, three tabs away from where the reply is being written.
 *   6. There is no queue. "How many are waiting" is unanswerable.
 *
 * So this is not a nicer view of email. It is the workflow moving to where the
 * record already is.
 *
 * ── AND THE THREAD IS NOT A CHAT ─────────────────────────────────────────────
 *
 * Explicitly not. Left-and-right bubbles are wrong for this in three ways: they
 * imply synchrony that does not exist (replies here are hours apart), they waste
 * half the width on alignment when the entries are paragraphs rather than
 * one-liners, and they have nowhere honest to put a note that the member must
 * never see.
 *
 * A CASE RECORD instead: full-width entries in one column, oldest first, each
 * headed with who wrote it, in what capacity, and when. The reading order is the
 * order things happened. An internal note is visibly a different kind of thing,
 * with a warning on it, rather than a differently-tinted bubble.
 */
final class SupportController
{
    private const PER_PAGE = 30;

    public function __construct(
        private readonly Twig $view,
        private readonly SupportTicketService $tickets,
    ) {}

    /**
     * The queue.
     *
     * Ordered by LAST ACTIVITY, not by creation. A ticket somebody replied to an
     * hour ago is live; a three-week-old one nobody has touched is a different
     * problem and belongs further down. Sorting by `created_at` — the obvious
     * choice — buries every active conversation under the backlog.
     */
    public function index(Request $req, Response $res): Response
    {
        $q      = $req->getQueryParams();
        $status = (string) ($q['status'] ?? 'open');
        $search = trim((string) ($q['q'] ?? ''));
        $page   = max(1, (int) ($q['page'] ?? 1));

        $rows = []; $counts = ['open' => 0, 'answered' => 0, 'resolved' => 0, 'all' => 0]; $total = 0;

        try {
            foreach (DB::table('gates_support_tickets')
                        ->groupBy('status')->get([DB::raw('status'), DB::raw('COUNT(*) as n')]) as $c) {
                $counts[(string) $c->status] = (int) $c->n;
                $counts['all'] += (int) $c->n;
            }

            $qb = DB::table('gates_support_tickets');
            if ($status !== 'all') $qb->where('status', $status);
            if ($search !== '') {
                $like = '%' . mb_strtolower($search) . '%';
                $qb->where(function ($w) use ($like) {
                    $w->whereRaw('LOWER(reference) LIKE ?', [$like])
                      ->orWhereRaw('LOWER(subject) LIKE ?', [$like])
                      ->orWhereRaw('LOWER(email) LIKE ?', [$like])
                      ->orWhereRaw('LOWER(name) LIKE ?', [$like]);
                });
            }
            $total = (int) $qb->count();

            // COALESCE, because `last_activity` is null on a ticket nobody has
            // replied to yet — and a null sorts to the far end, which would hide
            // brand-new tickets at the bottom of the queue they most belong at the
            // top of.
            $rows = $qb->orderByRaw('COALESCE(last_activity, created_at) DESC')
                ->limit(self::PER_PAGE)->offset(($page - 1) * self::PER_PAGE)
                ->get()->all();
        } catch (\Throwable $e) {
            error_log('[admin/support] queue read failed: ' . $e->getMessage());
        }

        // Reply counts in ONE query. A count per row is what turns a thirty-row
        // queue into thirty-one queries and a page nobody leaves open.
        $replies = [];
        if ($rows) {
            try {
                $ids = array_map(static fn($r) => (int) $r->id, $rows);
                foreach (DB::table('gates_support_messages')->whereIn('ticket_id', $ids)
                            ->where('is_internal', 0)->groupBy('ticket_id')
                            ->get([DB::raw('ticket_id as tid'), DB::raw('COUNT(*) as n')]) as $g) {
                    $replies[(int) $g->tid] = (int) $g->n;
                }
            } catch (\Throwable) {}
        }

        return $this->view->render($res, 'admin/support/index.twig', [
            'page_title' => 'Support queue',
            'admin_page' => 'support',
            'tickets'    => $rows,
            'replies'    => $replies,
            'counts'     => $counts,
            'status'     => $status,
            'q'          => $search,
            'page'       => $page,
            'pages'      => max(1, (int) ceil($total / self::PER_PAGE)),
            'total'      => $total,
        ]);
    }

    /**
     * One ticket, as a case record, with the context needed to answer it.
     *
     * ── WHY THE PAYMENT EVIDENCE IS ON THIS PAGE ─────────────────────────────
     *
     * Most tickets here are about a payment, and the answer is a fact about that
     * payment. Reading it meant leaving the ticket, finding Finance, searching a
     * reference, and coming back — so in practice the reply got written from
     * memory of what the member said, which is how a support conversation ends up
     * agreeing with a mistaken premise.
     *
     * If the thread mentions an AFG- reference, {@see VoteProof} is read for it and
     * shown beside the reply box: what was charged, what is actually on the tally,
     * and whether our own counter disagrees. The evidence and the reply are in the
     * same field of view.
     */
    public function show(Request $req, Response $res, array $args): Response
    {
        $ref = (string) ($args['ref'] ?? '');

        try {
            $t = DB::table('gates_support_tickets')->where('reference', $ref)->first();
        } catch (\Throwable) {
            $t = null;
        }
        if (!$t) return $res->withHeader('Location', '/admin/support')->withStatus(302);

        $messages = [];
        try {
            $messages = DB::table('gates_support_messages')->where('ticket_id', (int) $t->id)
                ->orderBy('id')->get()->all();
        } catch (\Throwable) {}

        // Any AFG- reference anywhere in the ticket — subject, opening transcript
        // or a reply. The member rarely puts it in the subject.
        $haystack = (string) $t->subject . ' ' . (string) ($t->transcript ?? '');
        foreach ($messages as $m) $haystack .= ' ' . (string) $m->body;

        $proof = null;
        if (preg_match('/\bAFG-[A-Za-z0-9-]{4,}/', $haystack, $hit)) {
            $p = VoteProof::forReference($hit[0]);
            if (!empty($p['found'])) $proof = $p;
        }

        return $this->view->render($res, 'admin/support/show.twig', [
            'page_title' => 'Ticket ' . $t->reference,
            'admin_page' => 'support',
            't'          => $t,
            'messages'   => $messages,
            'proof'      => $proof,
        ]);
    }

    /**
     * Reply, or write a note nobody outside sees.
     *
     * ── ONE FORM, TWO VERY DIFFERENT ACTS ────────────────────────────────────
     *
     * A visible reply is emailed to the member and appears on their thread. An
     * internal note is neither — it is the "refunded by hand, chased Paystack"
     * line that has to live somewhere other than a colleague's memory. The column
     * has existed since the table was built and nothing could write to it, because
     * an inbox has no private note.
     *
     * The two paths are deliberately far apart in the UI, because sending an
     * internal note to a customer is the mistake this feature makes possible and
     * it is not recoverable. The button says which one it is, and the note path
     * never touches the mailer.
     */
    public function reply(Request $req, Response $res, array $args): Response
    {
        $ref  = (string) ($args['ref'] ?? '');
        $b    = (array) $req->getParsedBody();
        $body = trim((string) ($b['body'] ?? ''));
        $kind = (string) ($b['kind'] ?? 'reply');
        $back = '/admin/support/' . rawurlencode($ref);

        if ($body === '') return $res->withHeader('Location', $back . '?e=empty')->withStatus(302);

        try {
            $t = DB::table('gates_support_tickets')->where('reference', $ref)->first();
        } catch (\Throwable) { $t = null; }
        if (!$t) return $res->withHeader('Location', '/admin/support')->withStatus(302);

        $actor = trim((string) ($_SESSION['admin_name'] ?? $_SESSION['admin_email'] ?? 'Support team'));

        if ($kind === 'note') {
            // Never mailed, never shown to the member. Written straight to the
            // record rather than through agentReply(), which exists to deliver.
            try {
                DB::table('gates_support_messages')->insert([
                    'ticket_id'   => (int) $t->id,
                    'author_type' => 'staff',
                    'author_name' => mb_substr($actor, 0, 120),
                    'body'        => mb_substr($body, 0, 8000),
                    'is_internal' => 1,
                    'emailed'     => 0,
                    'created_at'  => Carbon::now()->toDateTimeString(),
                ]);
                DB::table('gates_support_tickets')->where('id', (int) $t->id)
                    ->update(['last_activity' => Carbon::now()->toDateTimeString()]);
            } catch (\Throwable $e) {
                error_log('[admin/support] note failed: ' . $e->getMessage());
                return $res->withHeader('Location', $back . '?e=save')->withStatus(302);
            }
            return $res->withHeader('Location', $back . '?ok=note')->withStatus(302);
        }

        // A visible reply goes through the service, which mails the member and
        // records the delivery — the same path the auto-resolver uses, so a
        // hand-written answer and a machine one are recorded identically.
        $resolve = !empty($b['resolve']);
        $ok = $this->tickets->agentReply((int) $t->id, $body, $resolve);

        return $res->withHeader('Location', $back . ($ok ? '?ok=' . ($resolve ? 'resolved' : 'sent') : '?e=send'))
                   ->withStatus(302);
    }

    /** Move a ticket's status without writing anything. */
    public function status(Request $req, Response $res, array $args): Response
    {
        $ref = (string) ($args['ref'] ?? '');
        $to  = (string) ($args['to'] ?? '');
        if (!in_array($to, ['open', 'answered', 'resolved'], true)) {
            return $res->withHeader('Location', '/admin/support')->withStatus(302);
        }

        try {
            $patch = ['status' => $to, 'last_activity' => Carbon::now()->toDateTimeString()];
            // Stamped only on the way IN to resolved, and cleared on the way out,
            // so "when was this closed" cannot end up describing a ticket that was
            // reopened afterwards.
            $patch['resolved_at'] = $to === 'resolved' ? Carbon::now()->toDateTimeString() : null;
            DB::table('gates_support_tickets')->where('reference', $ref)->update($patch);
        } catch (\Throwable $e) {
            error_log('[admin/support] status change failed: ' . $e->getMessage());
        }

        return $res->withHeader('Location', '/admin/support/' . rawurlencode($ref) . '?ok=status')->withStatus(302);
    }
}
