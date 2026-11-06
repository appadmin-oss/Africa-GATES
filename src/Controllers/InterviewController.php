<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\InterviewService;
use AfricaGates\Support\ClientIp;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * The nominee's own interview page — the only place consent can be given.
 *
 * ── OPEN TO GUESTS, LIKE THE CLAIM PAGE, FOR THE SAME REASON ─────────────────
 *
 * A nominee has no account. Requiring one to attend their own interview would gate the
 * appointment on having the thing the appointment leads to, and the population most likely
 * to be shut out is exactly the one this platform exists to find: somebody nominated by
 * their community who has never used the site.
 *
 * So the token in the emailed link is the whole credential. It opens ONE sitting and does
 * four things — read the details, confirm, give or withhold permission to record, or say
 * they cannot come. It authorises nothing else and shows no other nominee.
 *
 * ── WHY CONSENT IS A SEPARATE ANSWER FROM "YES I'LL COME" ────────────────────
 *
 * Because "I will attend but I would rather you did not keep a recording" is a legitimate
 * position, and a form that only offers one combined button turns it into a refusal to
 * attend. The interview still happens without consent; what changes is that no transcript
 * reaches the panel — {@see InterviewService::publish()} enforces that, and this page says
 * so in plain words rather than burying it.
 *
 * ── AND WHY THE PAGE NEVER SHOWS THE QUESTIONS ───────────────────────────────
 *
 * It shows the THEMES. A nominee handed the panel's exact wording is interviewed on their
 * rehearsal, and a nominee told nothing walks into an ambush where the panel measures
 * composure instead of work. The themes are the published rubric, so sharing them costs
 * nothing and settles most of the fear.
 */
final class InterviewController
{
    public function __construct(private readonly Twig $view) {}

    /** The page. A GET that writes nothing — consent needs the button. */
    public function page(Request $req, Response $res, array $args = []): Response
    {
        $token = (string) ($args['token'] ?? '');
        $iv    = InterviewService::preview($token);

        if ($iv === null) {
            // Deliberately not "no such interview": a 404 for an unknown token and a 404
            // for a real one would differ, and the difference is a way to test tokens.
            return $this->view->render($res->withStatus(404), 'pages/interview.twig', [
                'page_title'    => 'Interview',
                'iv'            => null,
                'token'         => '',
                'notice'        => '',
                'error'         => '',
                'support_email' => \AfricaGates\Services\Notifier::supportEmail(),
            ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        // Read once and cleared, so a refresh does not keep re-announcing what happened.
        $notice = (string) ($_SESSION['interview_notice'] ?? '');
        $error  = (string) ($_SESSION['interview_error'] ?? '');
        unset($_SESSION['interview_notice'], $_SESSION['interview_error']);

        return $this->view->render($res, 'pages/interview.twig', [
            'page_title'    => 'Your interview',
            'iv'            => $iv,
            'token'         => $token,
            'notice'        => $notice,
            'error'         => $error,
            'support_email' => \AfricaGates\Services\Notifier::supportEmail(),
        ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * Confirm, consent, or decline — whichever button was pressed.
     *
     * A POST for all three, never a link. Mail scanners and link previewers follow URLs in
     * emails, and a scanner must not be able to confirm an appointment or record a
     * person's permission to be recorded. The claim path learned this the same way.
     */
    public function submit(Request $req, Response $res, array $args = []): Response
    {
        $token = (string) ($args['token'] ?? '');
        $body  = (array) $req->getParsedBody();
        $to    = '/interview/' . $token;

        unset($_SESSION['interview_notice'], $_SESSION['interview_error']);

        $action = (string) ($body['action'] ?? '');
        if ($action === 'decline') {
            $r = InterviewService::decline($token, (string) ($body['note'] ?? ''));
        } else {
            $r = InterviewService::confirm(
                $token,
                (string) ($body['name'] ?? ''),
                !empty($body['consent']),
                ClientIp::from($req)
            );
        }

        $_SESSION[($r['ok'] ?? false) ? 'interview_notice' : 'interview_error'] = (string) $r['message'];
        return $res->withHeader('Location', $to)->withStatus(302);
    }
}
