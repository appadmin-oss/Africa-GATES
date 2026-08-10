<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\GoogleMeetService;
use AfricaGates\Services\InterviewBrief;
use AfricaGates\Services\InterviewReview;
use AfricaGates\Services\InterviewService;
use AfricaGates\Services\OtpService;
use AfricaGates\Services\SmsService;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Judging interviews: the schedule, the console the panel runs a sitting from, and the
 * transcript afterwards.
 *
 * ── WHY THE RUN SCREEN IS A SEPARATE PAGE FROM THE DETAIL SCREEN ─────────────
 *
 * They are used in different rooms. The detail page is admin work done at a desk — set a
 * time, paste a link, invite people, publish a transcript. The run page is open on a laptop
 * beside a live video call with a nervous person on the other end, and everything on it has
 * to be readable at a glance and operable without thinking. Putting both on one screen
 * means the interviewer scrolls past a "cancel this interview" button while somebody is
 * mid-answer.
 *
 * ── WHAT THIS CONTROLLER REFUSES TO DO ───────────────────────────────────────
 *
 * Write a score. Not from the brief, not from the AI review, not as a "suggested" value a
 * judge could accept with one press. Scores are written on the judge's own ballot by the
 * judge, and {@see InterviewReview} deliberately produces no numbers at all.
 */
final class InterviewsController
{
    public function __construct(
        private readonly Twig $view,
        private readonly ?AuditService $audit = null,
        private readonly ?OtpService $mailer = null,
        private readonly ?SmsService $sms = null,
    ) {}

    /**
     * Reading the schedule and doing something to it are different permissions.
     *
     * `/admin/interviews` maps to the `moderation` section, which a viewer holds — and a
     * viewer looking at who is being interviewed and when is an ordinary operational
     * lookup. Inviting a nominee, publishing their recorded words to the panel or
     * cancelling a sitting is not, so every write demands superadmin, admin or moderator.
     *
     * Splitting it here rather than narrowing the section keeps the sidebar, the section
     * guard and this controller on one answer. The alternative — a section a viewer holds
     * plus a controller that refuses them — is the exact disagreement that had admins
     * clicking "Refunds" in their own sidebar and being told their role has no access.
     */
    private function blocked(Response $res, bool $write = true): ?Response
    {
        $role = (string) ($_SESSION['admin_role'] ?? '');
        $may  = $write
            ? ['superadmin', 'admin', 'moderator']
            : ['superadmin', 'admin', 'moderator', 'viewer'];
        if (in_array($role, $may, true)) return null;
        $_SESSION['flash_error'] = $write
            ? 'Your role can look at interviews but not change them.'
            : 'You don’t have access to interviews.';
        return $res->withHeader('Location', '/admin')->withStatus(302);
    }

    private function back(Response $res, string $to): Response
    {
        return $res->withHeader('Location', $to)->withStatus(302);
    }

    // ══ the list ═════════════════════════════════════════════════════════════

    public function index(Request $req, Response $res): Response
    {
        if ($b = $this->blocked($res, false)) return $b;

        $meet = GoogleMeetService::boot();
        return $this->view->render($res, 'admin/interviews/index.twig', [
            'page_title'   => 'Interviews',
            'admin_page'   => 'interviews',
            'rows'         => InterviewService::queue(),
            'summary'      => InterviewService::summary(),
            'unpublished'  => InterviewService::unpublished(),
            'auto_meet'    => $meet->canSchedule(),
            'meet_why'     => $meet->why(),
            'candidates'   => $this->candidates(),
            'judges'       => $this->judges(),
        ]);
    }

    /** Approved nominees with no interview waiting — the list you can schedule from. */
    private function candidates(int $limit = 300): array
    {
        try {
            $busy = DB::table('gates_interviews')->whereIn('status', InterviewService::PENDING)
                ->pluck('nominee_id')->map(fn ($v) => (int) $v)->all();
            $q = DB::table('gates_nominees as n')
                ->leftJoin('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->whereIn('n.status', ['approved', 'winner', 'runner_up'])
                ->whereNull('n.merged_into')
                ->orderBy('n.name')->limit($limit)
                ->select('n.id', 'n.name', 'c.title as category');
            if ($busy) $q->whereNotIn('n.id', $busy);
            return $q->get()->map(fn ($r) => (array) $r)->all();
        } catch (\Throwable $e) {
            error_log('[interview] could not list candidates: ' . $e->getMessage());
            return [];
        }
    }

    private function judges(): array
    {
        try {
            return DB::table('gates_judges')->where('is_active', 1)->orderBy('name')
                ->get(['id', 'name', 'title'])->map(fn ($r) => (array) $r)->all();
        } catch (\Throwable) { return []; }
    }

    // ══ one sitting ══════════════════════════════════════════════════════════

    public function show(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res, false)) return $b;
        $id = (int) ($args['id'] ?? 0);
        $d  = InterviewService::detail($id);
        if ($d === null) {
            $_SESSION['flash_error'] = 'That interview could not be found.';
            return $this->back($res, '/admin/interviews');
        }

        $meet = GoogleMeetService::boot();
        return $this->view->render($res, 'admin/interviews/show.twig', [
            'page_title' => 'Interview #' . $id,
            'admin_page' => 'interviews',
            'iv'         => $d,
            'judges'     => $this->judges(),
            'auto_meet'  => $meet->canSchedule(),
            'meet_why'   => $meet->why(),
        ]);
    }

    /**
     * The console. GET, because opening it must be free — but it marks the sitting live,
     * which is the one write a GET does here and it is idempotent.
     */
    public function run(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id = (int) ($args['id'] ?? 0);
        $d  = InterviewService::detail($id);
        if ($d === null) {
            $_SESSION['flash_error'] = 'That interview could not be found.';
            return $this->back($res, '/admin/interviews');
        }

        // A pack that was never built (queue not draining, or the sitting created seconds
        // ago) must not leave the panel with a blank screen. Build it now, in the request:
        // the rules path needs no network and the panel is already waiting.
        if (($d['brief']['questions'] ?? []) === []) {
            InterviewBrief::build($id);
            $d = InterviewService::detail($id) ?? $d;
        }

        InterviewService::markLive($id);

        return $this->view->render($res, 'admin/interviews/run.twig', [
            'page_title' => 'Running: ' . $d['nominee'],
            'admin_page' => 'interviews',
            'iv'         => $d,
        ]);
    }

    // ══ actions ══════════════════════════════════════════════════════════════

    public function create(Request $req, Response $res): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $b2 = (array) $req->getParsedBody();

        $nomineeId = (int) ($b2['nominee_id'] ?? 0);
        $tz        = trim((string) ($b2['timezone'] ?? 'Africa/Lagos'));
        $when      = trim((string) ($b2['scheduled_at'] ?? ''));

        // The RAW local string, with the zone beside it. Converting here as well as in
        // create() is what stored every sitting an hour early in Lagos.
        $r = InterviewService::create($nomineeId, [
            'scheduled_at'  => $when,
            'duration_mins' => (int) ($b2['duration_mins'] ?? 30),
            'timezone'      => $tz,
            'meet_url'      => trim((string) ($b2['meet_url'] ?? '')),
            'panel'         => array_map('intval', (array) ($b2['panel'] ?? [])),
        ], (int) ($_SESSION['admin_id'] ?? 0) ?: null);

        if (!($r['ok'] ?? false)) {
            $_SESSION['flash_error'] = (string) $r['message'];
            return $this->back($res, isset($r['id']) ? '/admin/interviews/' . $r['id'] : '/admin/interviews');
        }

        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'interview.create',
            'nominee', $nomineeId, ['interview_id' => $r['id']]);

        // Ask Google for the room, if that door is open. Failure is not fatal: the screen
        // then shows the paste box with the reason printed beside it.
        if (!empty($b2['auto_meet']) && $when !== '') {
            $this->attachMeet((int) $r['id']);
        }

        $_SESSION['flash'] = (string) $r['message'];
        return $this->back($res, '/admin/interviews/' . $r['id']);
    }

    public function reschedule(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id = (int) ($args['id'] ?? 0);
        $b2 = (array) $req->getParsedBody();
        $tz = trim((string) ($b2['timezone'] ?? ''));

        $r = InterviewService::reschedule(
            $id,
            trim((string) ($b2['scheduled_at'] ?? '')),
            isset($b2['duration_mins']) ? (int) $b2['duration_mins'] : null,
            $tz,
            trim((string) ($b2['meet_url'] ?? ''))
        );

        if (isset($b2['panel'])) InterviewService::setPanel($id, (array) $b2['panel']);

        $_SESSION[($r['ok'] ?? false) ? 'flash' : 'flash_error'] = (string) $r['message'];
        if ($r['ok'] ?? false) {
            $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'interview.reschedule',
                'interview', $id, ['moved' => (bool) ($r['moved'] ?? false)]);
        }
        return $this->back($res, '/admin/interviews/' . $id);
    }

    /** Ask Google for a Meet room, or take a pasted link. */
    public function meet(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id = (int) ($args['id'] ?? 0);
        $b2 = (array) $req->getParsedBody();
        $paste = trim((string) ($b2['meet_url'] ?? ''));

        if ($paste !== '') {
            $r = InterviewService::setMeetUrl($id, $paste);
            $_SESSION[($r['ok'] ?? false) ? 'flash' : 'flash_error'] = (string) $r['message'];
            return $this->back($res, '/admin/interviews/' . $id);
        }

        // An empty box is only a request to create the room when that button was the one
        // pressed. Otherwise it is somebody hitting save on a blank field, and quietly
        // booking a calendar event instead of saying "you left it empty" is a surprise.
        if (empty($b2['create'])) {
            $_SESSION['flash_error'] = 'Paste a Meet link, or use "Create it in Google for me".';
            return $this->back($res, '/admin/interviews/' . $id);
        }

        $r = $this->attachMeet($id);
        $_SESSION[$r['ok'] ? 'flash' : 'flash_error'] = $r['message'];
        return $this->back($res, '/admin/interviews/' . $id);
    }

    /**
     * Create the Google side of a sitting and store what comes back.
     *
     * @return array{ok:bool, message:string}
     */
    private function attachMeet(int $id): array
    {
        $d = InterviewService::detail($id);
        if ($d === null) return ['ok' => false, 'message' => 'That interview could not be found.'];
        $row = $d['row'];
        if (trim((string) ($row['scheduled_at'] ?? '')) === '') {
            return ['ok' => false, 'message' => 'Set a date and time before creating the meeting.'];
        }

        $guests = array_values(array_filter(array_column($d['panel'], 'email')));
        $r = GoogleMeetService::boot()->createSpace([
            'title'       => 'Africa GATES interview — ' . $d['nominee'],
            'description' => 'Judging interview for ' . $d['nominee']
                           . ($d['category'] !== '' ? ' (' . $d['category'] . ')' : '') . ".\n\n"
                           . "Panel console: " . rtrim(\AfricaGates\Support\SiteUrl::base(), '/')
                           . '/admin/interviews/' . $id . "/run\n"
                           . "Turn transcription ON at the start of the call (Activities → Transcripts) "
                           . "if the nominee has given permission — the panel reads the transcript "
                           . "afterwards.",
            'start'       => (string) $row['scheduled_at'],
            'minutes'     => (int) ($row['duration_mins'] ?? 30),
            'timezone'    => (string) ($row['timezone'] ?? 'Africa/Lagos'),
            'guests'      => $guests,
        ]);

        if (!$r['ok']) return ['ok' => false, 'message' => $r['message']];

        DB::table('gates_interviews')->where('id', $id)->update([
            'meet_url'          => $r['meet_url'],
            'meet_code'         => $r['meet_code'],
            'calendar_event_id' => $r['event_id'],
            'updated_at'        => \Illuminate\Support\Carbon::now()->toDateTimeString(),
        ]);
        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'interview.meet_created',
            'interview', $id, ['code' => $r['meet_code']]);

        return ['ok' => true, 'message' => $r['message']];
    }

    public function invite(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id = (int) ($args['id'] ?? 0);

        $r = InterviewService::invite($id, $this->mailer, $this->sms);
        $_SESSION[($r['ok'] ?? false) ? 'flash' : 'flash_error'] = (string) $r['message'];
        if ($r['ok'] ?? false) {
            $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'interview.invite',
                'interview', $id, ['recipients' => count($r['sent'] ?? [])]);
        }
        return $this->back($res, '/admin/interviews/' . $id);
    }

    /** Rebuild the question pack — after evidence is added, or to try the model again. */
    public function rebuild(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id = (int) ($args['id'] ?? 0);
        $r  = InterviewBrief::build($id);
        $_SESSION[($r['ok'] ?? false) ? 'flash' : 'flash_error'] = (string) $r['message'];
        return $this->back($res, '/admin/interviews/' . $id);
    }

    /** Capture one answer during the sitting. Answers JSON, because the console is live. */
    public function answer(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id = (int) ($args['id'] ?? 0);
        $b2 = (array) $req->getParsedBody();

        $r = InterviewService::recordAnswer(
            $id,
            (string) ($b2['key'] ?? ''),
            (string) ($b2['question'] ?? ''),
            (string) ($b2['note'] ?? ''),
            isset($b2['criterion_id']) && $b2['criterion_id'] !== '' ? (int) $b2['criterion_id'] : null,
            (string) ($b2['flag'] ?? '')
        );

        $res->getBody()->write((string) json_encode($r));
        return $res->withHeader('Content-Type', 'application/json')
                   ->withStatus(($r['ok'] ?? false) ? 200 : 422);
    }

    public function close(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id = (int) ($args['id'] ?? 0);
        $b2 = (array) $req->getParsedBody();
        $held = (string) ($b2['held'] ?? '1') !== '0';

        $r = InterviewService::close($id, $held, (string) ($b2['note'] ?? ''));
        $_SESSION[($r['ok'] ?? false) ? 'flash' : 'flash_error'] = (string) $r['message'];
        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'interview.close',
            'interview', $id, ['held' => $held]);
        return $this->back($res, '/admin/interviews/' . $id);
    }

    public function cancel(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id = (int) ($args['id'] ?? 0);
        $r  = InterviewService::cancel($id, (string) (((array) $req->getParsedBody())['note'] ?? ''));
        $_SESSION[($r['ok'] ?? false) ? 'flash' : 'flash_error'] = (string) $r['message'];
        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'interview.cancel', 'interview', $id);
        return $this->back($res, '/admin/interviews');
    }

    /** Pull the transcript from Google, or accept a pasted one, then publish it. */
    public function transcript(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id = (int) ($args['id'] ?? 0);
        $b2 = (array) $req->getParsedBody();
        $to = '/admin/interviews/' . $id;

        $text   = trim((string) ($b2['transcript'] ?? ''));
        $source = 'human';

        if ($text === '' && !empty($b2['fetch'])) {
            $iv = InterviewService::byId($id);
            $r  = GoogleMeetService::boot()->transcript(
                (string) ($iv->meet_code ?? ''), (string) ($iv->scheduled_at ?? ''));
            if (!$r['ok']) {
                $_SESSION['flash_error'] = $r['message'];
                return $this->back($res, $to);
            }
            $text   = $r['text'];
            $source = 'machine';
        }

        if ($text === '') {
            $_SESSION['flash_error'] = 'Paste the transcript, or press "Fetch from Google".';
            return $this->back($res, $to);
        }

        $r = InterviewService::publish($id, $text, [
            'source'          => (string) ($b2['source'] ?? $source),
            'language'        => (string) ($b2['language'] ?? ''),
            'translated_from' => (string) ($b2['translated_from'] ?? ''),
            'interviewer'     => (string) ($b2['interviewer'] ?? ''),
        ], (int) ($_SESSION['admin_id'] ?? 0) ?: null);

        $_SESSION[($r['ok'] ?? false) ? 'flash' : 'flash_error'] = (string) $r['message'];
        if ($r['ok'] ?? false) {
            $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'interview.publish',
                'interview', $id, ['transcript_id' => $r['transcript_id'] ?? 0, 'source' => $source]);
        }
        return $this->back($res, $to);
    }

    /** Read the transcript again — after a correction, or to try the model. */
    public function review(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id = (int) ($args['id'] ?? 0);
        $r  = InterviewReview::run($id);
        $_SESSION[($r['ok'] ?? false) ? 'flash' : 'flash_error'] = (string) $r['message'];
        return $this->back($res, '/admin/interviews/' . $id);
    }

    /** Take a published transcript back out of the judges' dossier. */
    public function withdraw(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id = (int) ($args['id'] ?? 0);
        $r  = InterviewService::withdraw($id, (string) (((array) $req->getParsedBody())['note'] ?? ''));
        $_SESSION[($r['ok'] ?? false) ? 'flash' : 'flash_error'] = (string) $r['message'];
        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'interview.withdraw', 'interview', $id);
        return $this->back($res, '/admin/interviews/' . $id);
    }
}
