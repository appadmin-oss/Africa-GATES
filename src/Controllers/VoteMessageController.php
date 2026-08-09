<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\RateLimitService;
use AfricaGates\Services\VoteMessageService;
use AfricaGates\Support\SiteUrl;
use AfricaGates\Support\Slug;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * A voter's message of support: its permalink, and the two things a reader can do
 * with one.
 *
 * ── WHY A MESSAGE HAS ITS OWN URL ────────────────────────────────────────────
 *
 * Because that is what makes it shareable. Facebook, WhatsApp and LinkedIn all
 * build their preview card from the meta tags of the URL they are given — so a
 * message shared as a link to the nominee's ballot previews as "Vote for X",
 * identical to every other share of that page, with the words the supporter
 * actually wrote nowhere in sight. Fifty supporters sharing fifty different
 * sentences would produce fifty identical cards.
 *
 * `/m/{token}` gives each message its own og:title and og:description, so the card
 * carries the message. The image stays the nominee's own 1200×630 card, which is
 * the right picture for it: the point of sharing a message is the person it is
 * about.
 *
 * ── WHY THE URL IS A TOKEN AND NOT AN ID ────────────────────────────────────
 *
 * `/m/1`, `/m/2`, `/m/3` is a directory of everything anybody has ever written on
 * this platform, walkable by anyone with a for-loop. The token is 16 random bytes.
 * It is not a secret — the message is public — but it is not an index either.
 *
 * ── THE MESSAGE MUST BE APPROVED, EVERY TIME IT IS READ ─────────────────────
 *
 * The token is minted at submission, before a moderator has necessarily seen the
 * words. {@see VoteMessageService::byToken()} only ever returns an approved
 * message, so a link posted to Facebook the second it was written stops resolving
 * the moment a moderator rejects it. A share link is not a bypass.
 */
final class VoteMessageController
{
    public function __construct(
        private readonly Twig $view,
        private readonly RateLimitService $rateLimit,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // GET /m/{token} — the shareable permalink
    // ─────────────────────────────────────────────────────────────────────────

    public function permalink(Request $req, Response $res, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');
        $msg   = VoteMessageService::byToken($token);
        if ($msg === null) throw new \Slim\Exception\HttpNotFoundException($req);

        $ballot = $this->ballotPath((int) $msg['nominee_id'], (string) $msg['nominee_name']);
        $base   = SiteUrl::base($req);

        // A preview headline is not a sentence — it is a fragment that has to survive
        // being cut off by five different platforms. The supporter's own words come
        // first because they are the reason the link was shared; the nominee's name
        // follows so the card still says who it is about when the quote is truncated.
        $quote  = $this->excerpt((string) $msg['body'], 90);

        return $this->view->render($res, 'pages/vote-message.twig', [
            'page_title'       => '“' . $quote . '” — a message for ' . $msg['nominee_name'],
            'og_title'         => '“' . $quote . '”',
            'meta_description' => $msg['name'] . ' on ' . $msg['nominee_name']
                                . ': ' . $this->excerpt((string) $msg['body'], 180),
            'gates_page'       => 'awards',
            'has_hero'         => false,
            'og_type'          => 'article',
            'msg'              => $msg,
            'ballot_url'       => $ballot,
            'share_url'        => $base . '/m/' . rawurlencode((string) $msg['token']),
            'wall'             => array_values(array_filter(
                VoteMessageService::wall((int) $msg['nominee_id'], 6),
                fn(array $m) => (int) $m['id'] !== (int) $msg['id']
            )),
            'wall_total'       => VoteMessageService::countForNominee((int) $msg['nominee_id']),
        ] + array_filter([
            // The nominee's purpose-built social card. Absolute, because a relative
            // og:image is silently ignored by every crawler.
            'og_image'      => $ballot !== '' ? $base . $ballot . '/card.png' : null,
            'og_image_w'    => \AfricaGates\Services\FlierService::OG_W,
            'og_image_h'    => \AfricaGates\Services\FlierService::OG_H,
            'og_image_type' => 'image/png',
            'og_image_alt'  => 'A message of support for ' . $msg['nominee_name'] . ' — Africa GATES',
        ], fn($v) => $v !== null));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/vote-message — the paid path
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Post a message against a CONFIRMED paid order.
     *
     * ── WHY THE PAID PATH POSTS AFTERWARDS AND THE FREE PATH DOES NOT ───────
     *
     * A free vote and its message arrive in the same request: the OTP proves the
     * voter, so the message can be stored the moment the vote succeeds.
     *
     * A paid vote cannot work that way. Its checkout leaves for a bank and comes
     * back, and a message captured before the handoff would be a message stored for
     * a payment that may never complete — which is an open door: start a checkout,
     * abandon it, and the words are already on the nominee's page. So the paid
     * message is written HERE, after the fact, and the payment reference is what
     * authorises it. A reference belongs to somebody who actually paid.
     *
     * The reference is checked against a `confirmed` order for THIS nominee, and the
     * hash comes from the order's own email rather than anything the client sends —
     * so a leaked reference still cannot be used to write under another person's
     * identity or against a different nominee.
     */
    public function post(Request $req, Response $res): Response
    {
        $b    = (array) $req->getParsedBody();
        $ref  = trim((string) ($b['ref'] ?? ''));
        $body = (string) ($b['body'] ?? '');

        $ipHash = hash('sha256', $this->ip($req));
        if (!$this->rateLimit->check($ipHash, 'vote_message', 12, 3600)) {
            return $this->json($res, ['success' => false, 'code' => 'RATE_LIMITED',
                'message' => 'Too many messages from this network. Try again later.'], 429);
        }
        if ($ref === '') {
            return $this->json($res, ['success' => false, 'code' => 'NO_REF',
                'message' => 'That form is missing its payment reference.'], 400);
        }

        $don = DB::table('gates_donations')
            ->where('payment_ref', $ref)->where('tier', 'paid-vote')->where('status', 'confirmed')
            ->first();
        if ($don === null) {
            return $this->json($res, ['success' => false, 'code' => 'NOT_CONFIRMED',
                'message' => 'We could not match that to a confirmed payment.'], 403);
        }

        $nomineeId = (int) ($don->intent_nominee_id ?? 0);
        if ($nomineeId < 1) {
            return $this->json($res, ['success' => false, 'code' => 'NO_NOMINEE',
                'message' => 'That order is not attached to a nominee.'], 400);
        }

        $r = VoteMessageService::submit([
            'nominee_id'  => $nomineeId,
            'category_id' => (int) (DB::table('gates_nominees')->where('id', $nomineeId)->value('category_id') ?? 0),
            'donation_id' => (int) $don->id,
            // FROM THE ORDER, never from the request. The reference proves who paid;
            // an email in the body would only prove who typed one.
            'email'       => (string) $don->donor_email,
            'body'        => $body,
            'name'        => (string) ($don->donor_name ?? ''),
            // The buyer's naming decision was already made and recorded at checkout —
            // this re-uses it rather than asking the same question twice with a
            // different answer available.
            'show_name'   => (int) ($don->show_name ?? 0) === 1,
            'source'      => 'paid',
        ]);

        if (!$r['ok']) {
            return $this->json($res, ['success' => false, 'code' => $r['code'] ?? 'ERROR',
                'message' => $r['message'] ?? 'Could not save your message.'], 400);
        }

        return $this->json($res, [
            'success'   => true,
            'status'    => $r['status'],
            'published' => ($r['status'] ?? '') === 'approved',
            'token'     => $r['token'] ?? '',
            'url'       => ($r['status'] ?? '') === 'approved' && !empty($r['token'])
                             ? SiteUrl::base($req) . '/m/' . rawurlencode((string) $r['token']) : '',
            'message'   => self::statusLine((string) ($r['status'] ?? '')),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/vote-message/cheer
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Applaud somebody else's message.
     *
     * ── WHY THIS ONE IS NOT MEMBERS-ONLY ────────────────────────────────────
     *
     * Community reactions require an account, keyed to the member so one person
     * counts once from any device. That is right for a forum somebody joined.
     *
     * This is a stranger reading a message on a nominee's page, arriving from a
     * WhatsApp link, who is never going to register in order to tap it. Demanding
     * an account here does not protect the number, it just makes the number zero.
     *
     * So the limit is per-network instead: one cheer for a given message per IP per
     * day, plus the browser remembering its own taps. That is softer than an account
     * and it is not pretending otherwise — a cheer count is a warm signal on
     * somebody's page, not a figure anything is decided by. Nothing on this platform
     * ranks, scores or pays out on it.
     */
    public function cheer(Request $req, Response $res): Response
    {
        $b     = (array) $req->getParsedBody();
        $token = trim((string) ($b['token'] ?? ''));
        if ($token === '') {
            return $this->json($res, ['success' => false, 'message' => 'Which message?'], 400);
        }

        $ipHash = hash('sha256', $this->ip($req));
        // Per network AND per message: a household or an office can cheer many
        // different messages, but not the same one over and over.
        if (!$this->rateLimit->check($ipHash . '|' . $token, 'vmsg_cheer', 1, 86400)
            || !$this->rateLimit->check($ipHash, 'vmsg_cheer_any', 120, 3600)) {
            // Not an error the reader needs to see. The count they already have is
            // returned so the button settles instead of appearing broken.
            return $this->json($res, ['success' => true, 'code' => 'ALREADY',
                'cheers' => VoteMessageService::cheerCount($token)]);
        }

        $n = VoteMessageService::cheer($token);
        if ($n === null) {
            return $this->json($res, ['success' => false, 'message' => 'That message is not available.'], 404);
        }
        return $this->json($res, ['success' => true, 'cheers' => $n]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Shared
    // ─────────────────────────────────────────────────────────────────────────

    /** What the voter is told, in the three cases the classifier can produce. */
    public static function statusLine(string $status): string
    {
        return match ($status) {
            'approved' => 'Your message is live on the page.',
            'rejected' => 'That message was not published. If you think that is wrong, contact support.',
            default    => 'Thank you — your message is with a moderator and will appear shortly.',
        };
    }

    /** The nominee's canonical ballot path, or '' when it cannot be resolved. */
    private function ballotPath(int $nomineeId, string $name): string
    {
        try {
            $slug = DB::table('gates_nominees as n')
                ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->join('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
                ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
                ->where('n.id', $nomineeId)->value('p.slug');
        } catch (\Throwable) { return ''; }
        if (!$slug) return '';
        return '/vote/' . $slug . '/' . Slug::idSegment($nomineeId, $name);
    }

    /** Trim to a whole word, so a preview never breaks mid-name. */
    private function excerpt(string $s, int $len): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
        if (mb_strlen($s) <= $len) return $s;
        $cut = mb_substr($s, 0, $len);
        $sp  = mb_strrpos($cut, ' ');
        return rtrim($sp !== false && $sp > $len * 0.6 ? mb_substr($cut, 0, $sp) : $cut, " ,.;:—-") . '…';
    }

    private function json(Response $res, array $d, int $status = 200): Response
    {
        $res->getBody()->write((string) json_encode($d, JSON_UNESCAPED_UNICODE));
        return $res->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    /** Same rule as ApiController: X-Forwarded-For only where a proxy is trusted. */
    private function ip(Request $req): string
    {
        $s = $req->getServerParams();
        if (\AfricaGates\Support\Env::bool('TRUST_PROXY', false) && !empty($s['HTTP_X_FORWARDED_FOR'])) {
            return trim(explode(',', (string) $s['HTTP_X_FORWARDED_FOR'])[0]);
        }
        return (string) ($s['REMOTE_ADDR'] ?? '');
    }
}
