<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\{RateLimitService, SupportAgentService,
                          SupportContext, SupportTicketService, UserAccountService};

/**
 * The support assistant.
 *
 * ── THE IDENTITY IS BUILT HERE, ONCE, FROM THE SESSION ───────────────────────
 *
 * {@see context()} is the only place a SupportContext is constructed, and it
 * reads `$_SESSION` and nothing else. No request parameter, header or body field
 * contributes to who the agent thinks it is talking to — because every one of
 * those is attacker-controlled, and the context decides which transactions are
 * readable.
 *
 * That is also why the chat endpoint takes only a message and a history: there
 * is no "as user" field to forget to validate, because there is no such field.
 */
final class SupportController
{
    /** Turns of history the client may send back. Older ones are dropped. */
    private const MAX_HISTORY = 12;

    public function __construct(
        private readonly Twig $view,
        private readonly ?SupportAgentService $agent = null,
        private readonly ?SupportTicketService $tickets = null,
        private readonly ?RateLimitService $rateLimit = null,
    ) {}

    public function page(Request $req, Response $res): Response
    {
        $m = UserAccountService::memberForForms();

        return $this->view->render($res, 'pages/support-assistant.twig', [
            'page_title'       => 'Support — Africa GATES',
            'meta_description' => 'Get help with voting, payments, nominations and your account on Africa GATES.',
            'gates_page'       => 'support',
            'has_hero'         => false,
            'ai_on'            => $this->agent?->available() ?? false,
            'is_signed_in'     => $m !== null,
            'member_first'     => $m ? explode(' ', trim((string) $m['name']))[0] : null,
            // Shown to a guest so they understand WHY the assistant cannot LIST
            // their payments, instead of concluding it is broken. It is no longer
            // the same thing as "cannot help with a payment" — a guest with a
            // reference gets the repair, which is what they came for.
            'can_see_payments' => $m !== null,
            'support_email'    => \AfricaGates\Services\Notifier::supportEmail(),
        ]);
    }

    /**
     * One turn of the conversation.
     *
     * Stateless on the server: the client returns the history. That is a
     * deliberate trade — no session bloat and no conversation table for
     * something most people use once — and it is safe precisely because the
     * history is only ever fed to the model as quoted text. It grants nothing.
     * Identity comes from the session, so a forged history cannot escalate
     * privilege; the worst it can do is make the assistant misremember.
     */
    public function chat(Request $req, Response $res): Response
    {
        $b = (array) $req->getParsedBody();
        $ip = (string) ($req->getServerParams()['REMOTE_ADDR'] ?? '');

        if ($this->rateLimit !== null && $ip !== '') {
            // 30/hour: a real conversation is a handful of turns, and each one
            // costs two model calls plus database reads.
            if (!$this->rateLimit->check(hash('sha256', $ip), 'support_chat', 30, 3600)) {
                return $this->json($res, [
                    'ok' => false,
                    'reply' => 'You have sent a lot of messages in a short time. Please wait a few minutes, '
                             . 'or email the team directly and they will pick it up.',
                ], 429);
            }
        }

        $message = trim((string) ($b['message'] ?? ''));
        if ($message === '') {
            return $this->json($res, ['ok' => false, 'reply' => 'Tell me what is going wrong.'], 422);
        }

        $history = [];
        $raw = $b['history'] ?? '[]';
        $decoded = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
        if (is_array($decoded)) {
            foreach (array_slice($decoded, -self::MAX_HISTORY) as $h) {
                if (!is_array($h)) continue;
                $role = ($h['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
                $content = trim((string) ($h['content'] ?? ''));
                if ($content !== '') $history[] = ['role' => $role, 'content' => mb_substr($content, 0, 2000)];
            }
        }

        if ($this->agent === null) {
            return $this->json($res, ['ok' => false, 'reply' => 'Support chat is unavailable right now.'], 503);
        }

        $ctx = $this->context($ip);
        $r   = $this->agent->ask($message, $history, $ctx);

        return $this->json($res, [
            'ok'        => true,
            'reply'     => $r['reply'],
            'escalated' => $r['escalated'],
            'ticket'    => $r['ticket'],
            // Shown in the UI as "checked your payments" — a support bot that
            // says where its answer came from is one people can sanity-check.
            'used'      => $r['used'],
            // Rendered as preview cards under the reply. See articlesFor().
            'articles'  => $this->articlesFor($message, $r['results'] ?? []),
        ]);
    }

    /**
     * The Help Centre answers worth showing beside this reply, as preview cards.
     *
     * The card-building moved to {@see HelpCentre::previews()} when Gee gained the
     * same strip: two copies of a ranking would drift, and differently ordered
     * cards under the same question read as a bug in whichever one the person saw
     * second. Capped at three — a wall of cards under every reply is
     * indistinguishable from an advert and teaches people to ignore the strip.
     *
     * `lastResort: true` here and nowhere else. Somebody at the support desk
     * arrived stuck, so a blank strip is a failure; Gee's ordinary browsing
     * chatter must not sprout a refunds card.
     *
     * @param list<array<string,mixed>> $results the turn's tool results
     * @return list<array<string,mixed>>
     */
    private function articlesFor(string $message, array $results): array
    {
        return \AfricaGates\Services\HelpCentre::previews(
            $message, SupportAgentService::citedSlugs($results), 3, lastResort: true);
    }

    /**
     * Ask for a human directly, without arguing with a robot first.
     *
     * Deliberately a separate endpoint and a separate button. Making someone
     * negotiate with an assistant to reach a person is the single most resented
     * pattern in support software, and the escape hatch has to work even when
     * the model is down — which is why this path touches no model at all.
     */
    public function escalate(Request $req, Response $res): Response
    {
        $b = (array) $req->getParsedBody();
        $ip = (string) ($req->getServerParams()['REMOTE_ADDR'] ?? '');

        if ($this->rateLimit !== null && $ip !== '') {
            if (!$this->rateLimit->check(hash('sha256', $ip), 'support_escalate', 5, 3600)) {
                return $this->json($res, ['ok' => false, 'message' => 'Too many requests. Please email the team.'], 429);
            }
        }

        $message = trim((string) ($b['message'] ?? ''));
        if ($message === '') {
            return $this->json($res, ['ok' => false, 'message' => 'Describe the problem first.'], 422);
        }
        if ($this->tickets === null) {
            return $this->json($res, ['ok' => false, 'message' => 'Ticketing is unavailable right now.'], 503);
        }

        $history = [];
        $decoded = json_decode((string) ($b['history'] ?? '[]'), true);
        if (is_array($decoded)) {
            foreach (array_slice($decoded, -self::MAX_HISTORY) as $h) {
                if (is_array($h) && trim((string) ($h['content'] ?? '')) !== '') {
                    $history[] = ['role' => ($h['role'] ?? '') === 'assistant' ? 'assistant' : 'user',
                                  'content' => mb_substr(trim((string) $h['content']), 0, 2000)];
                }
            }
        }

        $m     = UserAccountService::memberForForms();
        $email = $m['email'] ?? (filter_var(trim((string) ($b['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: null);

        // ── ALREADY ASKED? ──────────────────────────────────────────────────
        //
        // This button is on screen from the first frame, on purpose, and people
        // press it again when a day goes by with no answer. Minting a second
        // reference for one problem splits it across two tickets and hands the
        // member two numbers to quote. Chasing is added to the ticket they have.
        // ── AND WHOSE TICKET MAY IT FIND? ────────────────────────────────────
        //
        // This searched by the email in the REQUEST BODY, which for a guest is
        // simply typed in. That made the endpoint do two things it must not:
        //
        //   DISCLOSE — supply a stranger's address with a matching first sentence
        //     and the JSON came back with THEIR ticket reference, confirming they
        //     have an open complaint and handing over the number used to look it up.
        //   INJECT — appendEscalation() then wrote the sender's text into that
        //     stranger's thread, where support staff read it. On a desk that
        //     issues refunds and delivers votes, arbitrary text in somebody else's
        //     complaint is a social-engineering vector, not a nuisance.
        //
        // And the subject match is not the obstacle it looks like: the subject is
        // the first sentence of the message, and on this platform the complaints
        // are near-identical — "I paid but my votes have not been added" was
        // written almost verbatim by everyone in the unminted-vote incident.
        //
        // Deduplication needs a PROVEN identity. A member session is proof. A typed
        // address is not, so for a guest the only safe match is a ticket this same
        // browser opened — which is exactly the case the feature exists for: one
        // person pressing the button again because nobody has answered.
        $sessionRefs = (array) ($_SESSION['support_refs'] ?? []);
        $existing    = $this->tickets->openTicketFor(
            (int) ($m['id'] ?? 0), (string) ($email ?? ''),
            SupportTicketService::subjectFrom($message));

        if ($existing !== null && !$m
            && !in_array((string) $existing['reference'], $sessionRefs, true)) {
            // A guest matched somebody else's ticket. Not theirs to see or append to.
            $existing = null;
        }

        if ($existing !== null
            && $this->tickets->appendEscalation($existing, $message, $history, (string) ($m['name'] ?? ''))) {
            $this->rememberRef($existing['reference']);
            return $this->json($res, [
                'ok' => true, 'ticket' => $existing['reference'], 'appended' => true,
                'message' => "You already have this with the team as {$existing['reference']} — I have added what "
                           . 'you just said to it and pushed it back up their queue, rather than starting a second '
                           . 'ticket about the same thing.',
            ]);
        }

        $ref = $this->tickets->open($message, $history, $this->context($ip), [], [
            'user_id'    => $m['id']    ?? null,
            'email'      => $email,
            'name'       => $m['name']  ?? null,
            'page_url'   => (string) ($b['page_url'] ?? ''),
            'user_agent' => $req->getHeaderLine('User-Agent'),
            'ip'         => $ip,
        ]);

        if ($ref === null) {
            return $this->json($res, [
                'ok' => false,
                'message' => 'I could not open a ticket. Please email the team directly and mention what happened.',
            ], 500);
        }

        $this->rememberRef($ref);

        return $this->json($res, [
            'ok' => true, 'ticket' => $ref,
            'message' => "Passed to the team — your reference is {$ref}. They reply by email, usually within a working day.",
        ]);
    }

    /**
     * Remember, in THIS browser's session, a ticket this visitor actually opened.
     *
     * The only identity a guest has. It is why the duplicate check can still work
     * for the person pressing the button a second time without letting anybody
     * reach a ticket by typing somebody else's address.
     *
     * Bounded, because a session is not a filing cabinet and an unbounded list in
     * session storage is a slow memory leak on a shared host.
     */
    private function rememberRef(string $reference): void
    {
        $reference = trim($reference);
        if ($reference === '' || !isset($_SESSION)) return;

        $refs = array_values(array_filter((array) ($_SESSION['support_refs'] ?? []),
            static fn($r): bool => is_string($r) && $r !== ''));
        if (!in_array($reference, $refs, true)) $refs[] = $reference;

        $_SESSION['support_refs'] = array_slice($refs, -10);
    }

    // ── tickets ──────────────────────────────────────────────────────────────

    /**
     * Raise a ticket directly.
     *
     * MEMBERS ONLY, and the reason is not gatekeeping. A ticket is a promise to
     * reply, and a reply needs a verified address to go to: an anonymous form
     * takes whatever email is typed, which makes the queue a place to send mail
     * to strangers and gives the person no way to see the answer. A member has an
     * address we have already verified and a page they can come back to.
     *
     * Anyone who is not signed in still has the escalate path above, which reaches
     * the same team — it simply cannot promise them a thread.
     */
    public function ticketCreate(Request $req, Response $res): Response
    {
        $m = UserAccountService::memberForForms();
        if (!$m) {
            return $this->json($res, ['ok' => false, 'code' => 'SIGN_IN',
                'message' => 'Sign in to raise a ticket — that is how we can reply to you and how you can follow it.',
                'login_url' => '/account/login?next=' . rawurlencode('/support/tickets')], 401);
        }

        $ip = (string) ($req->getServerParams()['REMOTE_ADDR'] ?? '');
        if ($this->rateLimit !== null && $ip !== ''
            && !$this->rateLimit->check(hash('sha256', (string) $m['id']), 'support_ticket', 6, 3600)) {
            return $this->json($res, ['ok' => false, 'message' => 'You have opened several tickets already. '
                . 'Reply on an existing one instead, and the team will see it.'], 429);
        }

        $b       = (array) $req->getParsedBody();
        $subject = trim((string) ($b['subject'] ?? ''));
        $body    = trim((string) ($b['body'] ?? ''));
        if ($body === '') {
            return $this->json($res, ['ok' => false, 'message' => 'Describe the problem.'], 422);
        }
        if ($this->tickets === null) {
            return $this->json($res, ['ok' => false, 'message' => 'Ticketing is unavailable right now.'], 503);
        }

        $ref = $this->tickets->openForMember((int) $m['id'], (string) $m['email'], (string) $m['name'],
            $subject, mb_substr($body, 0, 5000), [
                'page_url'   => (string) ($b['page_url'] ?? ''),
                'user_agent' => $req->getHeaderLine('User-Agent'),
                'ip'         => $ip,
            ]);

        if ($ref === null) {
            return $this->json($res, ['ok' => false,
                'message' => 'I could not open the ticket. Please email the team directly.'], 500);
        }
        // Evidence, once the ticket is safely open. Best-effort and never fatal: a
        // rejected screenshot must not lose the description somebody just wrote.
        $note = '';
        try {
            $tid = (int) DB::table('gates_support_tickets')->where('reference', $ref)->value('id');
            if ($tid > 0) {
                $mid = (int) (DB::table('gates_support_messages')->where('ticket_id', $tid)
                    ->orderBy('id')->value('id') ?? 0);
                $a = \AfricaGates\Services\SupportAttachmentService::attachAll(
                    $req->getUploadedFiles()['files'] ?? null, $tid, $mid ?: null,
                    'member', (int) $m['id']);
                if ($a['problems']) $note = ' ' . implode(' ', $a['problems']);
            }
        } catch (\Throwable $e) {
            error_log('[support] attachment failed on ' . $ref . ': ' . $e->getMessage());
        }

        return $this->json($res, ['ok' => true, 'ticket' => $ref,
            'message' => "Ticket {$ref} is open. You will get a reply by email, and you can follow it here." . $note]);
    }

    /** The member's own tickets. */
    public function tickets(Request $req, Response $res): Response
    {
        $m = UserAccountService::memberForForms();
        if (!$m) {
            return $res->withHeader('Location', '/account/login?next=' . rawurlencode('/support/tickets'))->withStatus(302);
        }

        $ref    = trim((string) ($req->getQueryParams()['ref'] ?? ''));
        $thread = $ref !== '' && $this->tickets !== null
            ? $this->tickets->threadFor($ref, (int) $m['id'], (string) $m['email'])
            : null;

        return $this->view->render($res, 'pages/support-tickets.twig', [
            'page_title'       => 'Your support tickets — Africa GATES',
            'meta_description' => 'Track your Africa GATES support tickets and replies.',
            'gates_page'       => 'support',
            'has_hero'         => false,
            'member_name'      => $m['name'],
            'tickets'          => $this->tickets?->forMember((int) $m['id'], (string) $m['email']) ?? [],
            'thread'           => $thread,
            'attachments'      => $thread !== null && isset($thread['ticket']->id)
                ? \AfricaGates\Services\SupportAttachmentService::byMessage((int) $thread['ticket']->id)
                : [],
            'attach_accept'    => \AfricaGates\Services\SupportAttachmentService::acceptAttribute(),
            'attach_limit'     => \AfricaGates\Services\SupportAttachmentService::humanLimit(),
            'attach_max'       => \AfricaGates\Services\SupportAttachmentService::MAX_PER_MESSAGE,
            // A reference that is not theirs looks exactly like one that does not
            // exist. Said plainly so it does not read as a bug.
            'not_found'        => $ref !== '' && $thread === null ? $ref : null,
        ]);
    }

    /**
     * One ticket, opened by a link, with no account.
     *
     * ── WHY THIS ROUTE EXISTS ────────────────────────────────────────────────
     *
     * Every other ticket endpoint requires a member, and the reasoning was sound as
     * far as it went — a reply needs a verified address. But it locked out the two
     * groups most likely to need support. Paid voting takes an email and a card and
     * creates no account, so the entire unminted-vote incident population was given
     * the repair tools and then had no way to answer the reply they received. And
     * the claim rules require a human route that works WITHOUT an account, while the
     * assisted path routes to a ticket the person could not open.
     *
     * A support thread the requester cannot reply to is a monologue.
     *
     * ── WHAT THE LINK IS TRUSTED FOR ─────────────────────────────────────────
     *
     * Exactly one thread and nothing else. It cannot list, cannot reach another
     * reference, and grants no capability the member path does not already have —
     * {@see \AfricaGates\Services\TicketLinkService} owns those properties and this
     * action adds none of its own.
     *
     * A bad token renders the SAME page as an expired one, because distinguishing
     * them turns this into an oracle for which references exist — and references
     * travel in emails, receipts and screenshots.
     */
    public function linkedThread(Request $req, Response $res, array $args = []): Response
    {
        $token = (string) ($args['token'] ?? '');
        $who   = \AfricaGates\Services\TicketLinkService::resolve($token);

        if ($who === null || $this->tickets === null) {
            // 404, not 403. A refusal that distinguishes "no such token" from
            // "expired token on a real ticket" is itself a disclosure.
            return $this->view->render($res->withStatus(404), 'pages/support-ticket-link.twig', [
                'page_title'       => 'This link has expired — Africa GATES',
                'meta_description' => 'Support ticket link.',
                'gates_page'       => 'support',
                'has_hero'         => false,
                'thread'           => null,
                'token'            => '',
            ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        \AfricaGates\Services\TicketLinkService::touch($token);

        return $this->view->render($res, 'pages/support-ticket-link.twig', [
            'page_title'       => 'Ticket ' . $who['reference'] . ' — Africa GATES',
            'meta_description' => 'Your Africa GATES support conversation.',
            'gates_page'       => 'support',
            'has_hero'         => false,
            // userId 0 — threadFor() has always matched on email alone, so the guest
            // path reuses the member reader rather than a parallel one that could
            // drift from it and start returning internal staff notes.
            'thread'           => $this->tickets->threadFor($who['reference'], 0, $who['email']),
            'token'            => $token,
        ])->withHeader('X-Robots-Tag', 'noindex, nofollow')
          ->withHeader('Cache-Control', 'no-store, private');
    }

    /**
     * Reply on a link-opened ticket.
     *
     * Rate limited on the token, because this is the one write on the platform that
     * takes no session at all: without a limit, a leaked link would be an unbounded
     * way to append text to somebody else's support thread.
     */
    public function linkedReply(Request $req, Response $res, array $args = []): Response
    {
        $token = (string) ($args['token'] ?? '');
        $who   = \AfricaGates\Services\TicketLinkService::resolve($token);

        if ($who === null || $this->tickets === null) {
            return $this->json($res, ['ok' => false,
                'message' => 'This link has expired. Reply to the email instead and we will pick it up.'], 404);
        }

        // Keyed on the TOKEN, not the IP. The people this exists for share networks —
        // a cyber-café, a household router, a carrier NAT — so an IP bucket would
        // have one person's replies throttle their neighbour's. The token identifies
        // the thread being written to, which is the thing actually worth bounding.
        if ($this->rateLimit !== null
            && !$this->rateLimit->check(hash('sha256', $token), 'ticket_link_reply', 10, 3600)) {
            return $this->json($res, ['ok' => false,
                'message' => 'That is a lot of replies at once. Try again shortly.'], 429);
        }

        $r = $this->tickets->reply(
            $who['reference'], (string) (((array) $req->getParsedBody())['body'] ?? ''),
            0, $who['email'], ''
        );

        return $this->json($res, ['ok' => $r['ok'], 'message' => $r['message']], $r['ok'] ? 200 : 422);
    }

    /** Reply on one of the member's own tickets. */
    public function ticketReply(Request $req, Response $res): Response
    {
        $m = UserAccountService::memberForForms();
        if (!$m) {
            return $this->json($res, ['ok' => false, 'code' => 'SIGN_IN',
                'message' => 'Sign in to reply.'], 401);
        }
        if ($this->tickets === null) {
            return $this->json($res, ['ok' => false, 'message' => 'Ticketing is unavailable right now.'], 503);
        }

        $b = (array) $req->getParsedBody();
        $r = $this->tickets->reply(
            (string) ($b['reference'] ?? ''), (string) ($b['body'] ?? ''),
            (int) $m['id'], (string) $m['email'], (string) $m['name']
        );

        // Bound to THIS message, using the id the service now returns rather than
        // "the newest row on the ticket" — which is right almost always, and wrong
        // exactly when two replies land together.
        $note = '';
        if ($r['ok'] ?? false) {
            try {
                $a = \AfricaGates\Services\SupportAttachmentService::attachAll(
                    $req->getUploadedFiles()['files'] ?? null,
                    (int) ($r['ticket_id'] ?? 0), (int) ($r['message_id'] ?? 0) ?: null,
                    'member', (int) $m['id']);
                if ($a['problems']) $note = ' ' . implode(' ', $a['problems']);
            } catch (\Throwable $e) {
                error_log('[support] attachment failed on reply: ' . $e->getMessage());
            }
        }

        return $this->json($res, ['ok' => $r['ok'], 'message' => $r['message'] . $note], $r['ok'] ? 200 : 422);
    }

    /**
     * Identity from the SESSION, never from the request. See the class note.
     *
     * One line, because the rule itself lives in {@see SupportContext::fromSession()}
     * — there is more than one front door onto this agent now, and the guarantee
     * is only a guarantee while every door builds identity the same way.
     *
     * The rate limiter is passed because the repair tools are open to guests and
     * need their own ceiling, separate from the chat limit: one conversation can
     * ask for several repairs, and a hundred repairs is not a conversation.
     */
    private function context(string $ip = ''): SupportContext
    {
        return SupportContext::fromSession($this->rateLimit, $ip);
    }

    private function json(Response $res, array $payload, int $status = 200): Response
    {
        $res->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $res->withHeader('Content-Type', 'application/json')
                   ->withHeader('Cache-Control', 'no-store')   // a support answer is per-person
                   ->withStatus($status);
    }
}
