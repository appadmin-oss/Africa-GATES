<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use AfricaGates\Services\{ActivityFeedService, RateLimitService, SupportAgentService,
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
     * ── WHY A URL INSIDE A SENTENCE IS NOT ENOUGH ────────────────────────────
     *
     * The assistant can already cite an article and writes the link into its
     * prose. In a chat bubble that is a bare blue string mid-paragraph: no title,
     * no sense of what is behind it, nothing to weigh against the effort of
     * leaving the conversation. People do not click it, so the answer we vetted
     * goes unread while they keep typing at the robot.
     *
     * A card with a title, a one-line summary and its category is a decision
     * somebody can make at a glance. Same destination, several times the traffic.
     *
     * ── TWO SOURCES, AND THE ORDER MATTERS ───────────────────────────────────
     *
     *   CITED     articles the model actually read this turn via help_article.
     *             These belong to the answer, so they lead.
     *   SUGGESTED searched over the USER'S OWN WORDS, independently of the model.
     *
     * The second exists because the model does not always reach for the tool, and
     * when it does not, a perfectly good written answer stays invisible. Searching
     * the question directly costs nothing and does not depend on the model having
     * made a good decision — which is the sort of thing a UI should never depend on.
     *
     * Deduplicated and capped at three. A wall of cards under every reply is
     * indistinguishable from an advert and teaches people to ignore the strip.
     *
     * @param list<array<string,mixed>> $results the turn's tool results
     * @return list<array<string,mixed>>
     */
    private function articlesFor(string $message, array $results): array
    {
        $picked = [];

        // 1. What the answer was actually built from.
        foreach ($results as $r) {
            if (($r['tool'] ?? '') !== 'help_article') continue;
            $d = $r['data'] ?? [];
            if (!is_array($d) || empty($d['found'])) continue;

            foreach (array_merge([$d['article'] ?? null], (array) ($d['other_matches'] ?? [])) as $a) {
                if (!is_array($a) || empty($a['url'])) continue;
                $picked[basename((string) $a['url'])] ??= true;
            }
        }
        $cited = array_keys($picked);

        // 2. The safety net: their own words, whether or not the model looked.
        foreach (\AfricaGates\Services\HelpCentre::search($message, 3) as $hit) {
            $picked[(string) $hit['slug']] ??= true;
        }

        // 3. LAST RESORT — never show an empty strip to somebody who is stuck.
        //
        // Observed: a user typed "send an article I can read" and got nothing at
        // all. Nothing was wrong with the corpus; that sentence simply has no
        // topic in it, so it matches no keyword, so the search correctly returns
        // empty — and the person asking most explicitly for something to read got
        // the least. The same happens on any reply where the model failed.
        //
        // These four are the commonest reasons anybody is here. Offering them to
        // someone we have otherwise failed is strictly better than a blank space,
        // and it is the same set the Help Centre front page leads with.
        if (!$picked) {
            foreach (['paid-but-no-votes', 'vote-not-showing', 'code-did-not-arrive'] as $s) {
                $picked[$s] = true;
            }
        }

        $out = [];
        foreach (array_keys($picked) as $slug) {
            $a = \AfricaGates\Services\HelpCentre::bySlug((string) $slug);
            if ($a === null) continue;
            $cat = \AfricaGates\Services\HelpCentre::CATEGORIES[$a['cat']]
                ?? ['title' => '', 'tint' => '#eef2ef', 'fg' => '#39464a'];
            $out[] = [
                'slug'     => (string) $a['slug'],
                'title'    => (string) $a['title'],
                'summary'  => (string) $a['summary'],
                'url'      => \AfricaGates\Services\HelpCentre::url((string) $a['slug']),
                'category' => (string) $cat['title'],
                'tint'     => (string) $cat['tint'],
                'fg'       => (string) $cat['fg'],
                // Lets the UI say "I used this" rather than "you might also want",
                // which are different claims and should not look identical.
                'cited'    => in_array((string) $a['slug'], $cited, true),
            ];
            if (count($out) >= 3) break;
        }
        return $out;
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
        $existing = $this->tickets->openTicketFor(
            (int) ($m['id'] ?? 0), (string) ($email ?? ''),
            SupportTicketService::subjectFrom($message));

        if ($existing !== null
            && $this->tickets->appendEscalation($existing, $message, $history, (string) ($m['name'] ?? ''))) {
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

        return $this->json($res, [
            'ok' => true, 'ticket' => $ref,
            'message' => "Passed to the team — your reference is {$ref}. They reply by email, usually within a working day.",
        ]);
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
        return $this->json($res, ['ok' => true, 'ticket' => $ref,
            'message' => "Ticket {$ref} is open. You will get a reply by email, and you can follow it here."]);
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
            // A reference that is not theirs looks exactly like one that does not
            // exist. Said plainly so it does not read as a bug.
            'not_found'        => $ref !== '' && $thread === null ? $ref : null,
        ]);
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

        return $this->json($res, ['ok' => $r['ok'], 'message' => $r['message']], $r['ok'] ? 200 : 422);
    }

    /** Identity from the SESSION, never from the request. See the class note. */
    private function context(string $ip = ''): SupportContext
    {
        $m = UserAccountService::memberForForms();

        return new SupportContext(
            viewerId:    $m['id'] ?? null,
            viewerEmail: $m['email'] ?? null,
            // Staff scope requires a real admin session — the same one /admin uses.
            isAdmin:     (int) ($_SESSION['admin_id'] ?? 0) > 0,
            search:      new ActivityFeedService(),
            // The repair tools are open to guests, so they need their own ceiling
            // — separate from the chat limit, because one conversation can ask for
            // several repairs and a hundred repairs is not a conversation.
            limits:      $this->rateLimit,
            clientKey:   $ip !== '' ? hash('sha256', $ip) : '',
        );
    }

    private function json(Response $res, array $payload, int $status = 200): Response
    {
        $res->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $res->withHeader('Content-Type', 'application/json')
                   ->withHeader('Cache-Control', 'no-store')   // a support answer is per-person
                   ->withStatus($status);
    }
}
