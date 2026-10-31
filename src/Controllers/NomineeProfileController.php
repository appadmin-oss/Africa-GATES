<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\GatedFormService;
use AfricaGates\Services\NomineeDetails;
use AfricaGates\Services\UserAccountService;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * A nominee's own page (owner, 30 Oct 2026): from the approval email to an account, and from then
 * on their contact, photographs, evidence and bio, kept up to date for as long as they like.
 *
 *   GET  /nominee/start/{token}     the emailed link: signs them in or makes the account, then
 *                                   ties the nominee to it once (NomineeDetails::bind)
 *   GET  /account/nomination[/{id}] the page: a checklist until it is whole, a review after
 *   POST /account/nomination/{id}   one section at a time, by `do`
 */
final class NomineeProfileController
{
    public function __construct(private readonly Twig $view, private readonly UserAccountService $accounts) {}

    public function start(Request $req, Response $res, array $args): Response
    {
        $raw = (string) $args['token'];
        $user = $this->accounts->current();
        $row = GatedFormService::resolve($raw);
        $nominee = $row && $row->purpose === 'nominee' ? DB::table('gates_nominees as n')->leftJoin('gates_award_categories as c', 'c.id', '=', 'n.category_id')
            ->where('n.id', $row->subject_id)->first(['n.id', 'n.name', 'c.title as category']) : null;
        if (!$user) {
            // The email box posts straight to the code request; every sign-in path reads this.
            $_SESSION['login_next'] = '/nominee/start/' . $raw;
            $d = $nominee ? DB::table('gates_nominee_details')->where('nominee_id', $nominee->id)->first() : null;
            return $this->view->render($res, 'nominee/start.twig', ['page_title' => 'Your nomination — Africa GATES', 'nominee' => $nominee,
                'state' => $nominee ? (GatedFormService::status($row) === 'ok' || ($d && $d->user_id) ? 'signin' : 'spent') : 'invalid',
                'email' => $d ? (NomineeDetails::list($d->emails)[0] ?? '') : '']);
        }
        if (!$this->accounts->isVerified($user)) {
            return $this->view->render($res, 'nominee/start.twig', ['page_title' => 'Your nomination — Africa GATES', 'nominee' => $nominee, 'state' => 'verify', 'email' => (string) $user->email]);
        }
        $r = NomineeDetails::bind($raw, (int) $user->id);
        if (!$r['ok']) {
            return $this->view->render($res->withStatus(410), 'nominee/start.twig', ['page_title' => 'Your nomination — Africa GATES', 'nominee' => $nominee,
                'state' => 'refused', 'error' => $r['error'], 'email' => '']);
        }
        $_SESSION['nominee_ok'] = 'Welcome. This page is yours now; finish the steps below.';
        return $res->withHeader('Location', '/account/nomination/' . $r['nominee'])->withStatus(303);
    }

    public function page(Request $req, Response $res, array $args): Response
    {
        $user = $this->accounts->current();
        if (!$user) return $res->withHeader('Location', '/account/login?next=/account/nomination')->withStatus(302);
        $mine = NomineeDetails::forUser((int) $user->id);
        $id = (int) ($args['id'] ?? 0) ?: (int) ($mine[0]->nominee_id ?? 0);
        $d = $id ? NomineeDetails::mine($id, (int) $user->id) : null;
        if ($id && !$d && isset($args['id'])) throw new \Slim\Exception\HttpNotFoundException($req);
        $ctx = ['page_title' => 'My nomination — Africa GATES', 'mine' => $mine, 'd' => $d, 'ok' => $this->take('nominee_ok'), 'error' => $this->take('nominee_error'),
                'open' => (string) ($this->take('nominee_open') ?? '')];
        if ($d) {
            $n = DB::table('gates_nominees as n')->leftJoin('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->leftJoin('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')->leftJoin('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
                ->where('n.id', $d->nominee_id)->first(['n.id', 'n.name', 'c.title as category', 'p.title as programme']);
            $check = NomineeDetails::checklist($d);
            $ctx += ['n' => $n, 'check' => $check, 'done' => count(array_filter($check)), 'steps' => NomineeDetails::STEPS,
                'phones' => NomineeDetails::list($d->phones), 'emails' => NomineeDetails::list($d->emails),
                'photos' => NomineeDetails::files((int) $d->nominee_id, 'photo'), 'evidence' => NomineeDetails::files((int) $d->nominee_id, 'evidence'),
                'reason' => NomineeDetails::reason($d), 'terms_url' => GatedFormService::subjectTermsUrl('nominee', (int) $d->nominee_id),
                'max' => ['phones' => NomineeDetails::MAX_PHONES, 'emails' => NomineeDetails::MAX_EMAILS, 'photos' => NomineeDetails::MAX_PHOTOS,
                          'evidence' => NomineeDetails::MAX_EVIDENCE, 'bio' => NomineeDetails::BIO_MAX]];
        }
        return $this->view->render($res, 'nominee/profile.twig', $ctx);
    }

    public function save(Request $req, Response $res, array $args): Response
    {
        $user = $this->accounts->current();
        $id = (int) $args['id'];
        $d = $user ? NomineeDetails::mine($id, (int) $user->id) : null;
        if (!$d) throw new \Slim\Exception\HttpNotFoundException($req);
        $b = (array) $req->getParsedBody();
        $files = $req->getUploadedFiles();
        $do = (string) ($b['do'] ?? '');
        $r = match ($do) {
            'contact' => NomineeDetails::saveContact($d, (array) ($b['phones'] ?? []), (array) ($b['emails'] ?? [])),
            'bio' => NomineeDetails::saveBio($d, (string) ($b['bio'] ?? '')),
            'terms' => empty($b['accept']) ? ['ok' => false, 'error' => 'Tick the box to accept.'] : NomineeDetails::acceptTerms($d),
            'photo' => isset($files['photo']) && $files['photo']->getError() !== UPLOAD_ERR_NO_FILE
                ? NomineeDetails::addPhoto($d, $files['photo'], (string) ($b['label'] ?? '')) : ['ok' => false, 'error' => 'Choose a photograph first.'],
            'evidence' => NomineeDetails::addEvidence($d, $files['file'] ?? null, (string) ($b['url'] ?? ''), (string) ($b['label'] ?? '')),
            'remove' => NomineeDetails::remove($d, (int) ($b['file'] ?? 0)) ? ['ok' => true] : ['ok' => false, 'error' => 'That was already removed.'],
            default => ['ok' => false, 'error' => 'That isn’t something this page can do.'],
        };
        $section = in_array($do, ['remove'], true) ? (string) ($b['section'] ?? '') : $do;
        $_SESSION[$r['ok'] ? 'nominee_ok' : 'nominee_error'] = $r['ok'] ? match ($do) { 'remove' => 'Removed.', 'terms' => 'Accepted. Thank you.', default => 'Saved.' } : (string) $r['error'];
        if (!$r['ok']) $_SESSION['nominee_open'] = $section;
        return $res->withHeader('Location', '/account/nomination/' . $id . ($section !== '' ? '#nm-' . $section : ''))->withStatus(303);
    }

    private function take(string $k): ?string
    {
        $v = $_SESSION[$k] ?? null;
        unset($_SESSION[$k]);
        return $v === null ? null : (string) $v;
    }
}
