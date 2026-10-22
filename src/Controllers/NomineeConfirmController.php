<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\NomineeConfirmation;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * The page a nominee reaches from an SMS — /n/confirm/{token}.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * EVERY VISITOR HERE IS A STRANGER, AND THAT SHAPES ALL OF IT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Whoever opens this link is not signed in, did not ask to be contacted, and may never
 * have heard of this platform. They are here because somebody else typed their phone
 * number into a form. So:
 *
 * · THE ROUTE IS SHORT. `/n/confirm/{token}` rather than `/nominations/confirm/…`,
 *   because it travels inside a 160-character SMS beside a sentence that has to explain
 *   itself. Every character of path is a character not available to the explanation.
 *
 * · A BAD TOKEN IS A PAGE, NOT A 404. Somebody whose link has expired, or who has already answered,
 *   or whose SMS wrapped across two lines and lost its last character, needs a sentence
 *   telling them which of those happened. A 404 reads as "this platform is broken" and
 *   there is nobody for them to ask.
 *
 * · IT IS `noindex`. The URL is a credential. One in a search index is one anybody can
 *   answer with.
 */
final class NomineeConfirmController
{
    public function __construct(private readonly Twig $view) {}

    /** GET — the question. */
    public function show(Request $req, Response $res, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');
        $n     = NomineeConfirmation::peek($token);

        if (!$n) return $this->dead($res);

        if (!empty($n->nominee_confirmed_at)) {
            return $this->done($res, 'confirmed', 'You have already confirmed this',
                'Thank you — we have your answer and nothing else is needed.');
        }

        return $this->view->render($res->withHeader('X-Robots-Tag', 'noindex'),
            'pages/nominee-confirm.twig', [
                'page_title' => 'Confirm your nomination',
                'hide_chrome' => true,
                'token'     => $token,
                'nominee'   => (string) ($n->nominee_name ?? ''),
                'nominator' => trim((string) ($n->nominator_name ?? '')) ?: 'Somebody',
                'award'     => $this->awardName($n),
                'category'  => $this->categoryName($n),
                'ttl_days'  => NomineeConfirmation::TTL_DAYS,
            ]);
    }

    /** POST — the answer. */
    public function answer(Request $req, Response $res, array $args): Response
    {
        $token  = (string) ($args['token'] ?? '');
        $said   = (string) (((array) $req->getParsedBody())['answer'] ?? '');

        // An unrecognised answer must not be read as a yes. The two buttons are the only
        // two values, and anything else means the request did not come from this page.
        if ($said !== 'yes' && $said !== 'no') return $this->dead($res);

        $r = $said === 'yes'
            ? NomineeConfirmation::confirm($token)
            : NomineeConfirmation::decline($token);

        if (!$r['ok']) return $this->dead($res, (string) $r['code']);

        return $said === 'yes'
            ? $this->done($res, 'confirmed', 'Thank you — that is confirmed',
                'Your nomination can now go forward to be checked by our team.')
            : $this->done($res, 'declined', 'Taken down',
                'We have removed the nomination. Sorry to have bothered you.');
    }

    // ══════════════════════════════════════════════════════════════════════════

    /**
     * The not-a-404.
     *
     * It deliberately does not distinguish "no such token" from "expired" by default:
     * the two are told apart only by somebody probing, and the remedy is identical —
     * ask whoever nominated you to send it again. `EXPIRED` is named when the service
     * could tell, because that one has a different remedy from a mistyped link.
     */
    private function dead(Response $res, string $code = ''): Response
    {
        $expired = $code === 'EXPIRED';

        return $this->done($res, 'dead',
            $expired ? 'That link has expired' : 'That link did not work',
            $expired
                ? 'Links last ' . NomineeConfirmation::TTL_DAYS . ' days. Ask whoever '
                  . 'nominated you to send a fresh one.'
                : 'It may have been used already, or copied across two lines. Ask whoever '
                  . 'nominated you to send it again.',
            404);
    }

    private function done(Response $res, string $outcome, string $heading, string $body, int $status = 200): Response
    {
        return $this->view->render(
            $res->withStatus($status)->withHeader('X-Robots-Tag', 'noindex'),
            'pages/nominee-confirm-done.twig',
            ['page_title' => $heading, 'hide_chrome' => true,
             'outcome' => $outcome, 'heading' => $heading, 'body' => $body],
        );
    }

    /** The award's own name, resolved through the category chain the sandbox relies on. */
    private function awardName(object $n): string
    {
        $cycleId = (int) ($n->cycle_id ?? 0);
        if ($cycleId < 1) return '';

        $row = \Illuminate\Database\Capsule\Manager::table('gates_award_cycles as c')
            ->join('gates_award_programmes as p', 'p.id', '=', 'c.programme_id')
            ->where('c.id', $cycleId)->first(['p.title', 'c.edition_label', 'c.year']);

        if (!$row) return '';

        return trim((string) $row->title . ' ' . (string) ($row->edition_label ?: $row->year));
    }

    private function categoryName(object $n): string
    {
        $id = (int) ($n->category_id ?? 0);
        if ($id < 1) return '';

        return (string) (\Illuminate\Database\Capsule\Manager::table('gates_award_categories')
            ->where('id', $id)->value('title') ?? '');
    }
}
