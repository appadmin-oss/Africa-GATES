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

    // ══ the extension, packed for this deployment ════════════════════════════

    /**
     * Download the Chrome extension as a zip, wired to the host this admin is on.
     *
     * ── WHY THIS ROUTE HAD TO EXIST BEFORE THE EXTENSION COULD BE USED ───────
     *
     * The interview screen said "Load unpacked → the extension/ folder from the upload",
     * and nothing served that folder. It lives outside the web root, which is right — a
     * browsable directory of extension source under public/ is one anybody can enumerate —
     * and this host has no SSH, so there was no way to obtain it. The instruction named a
     * folder the operator could not reach, which is why the report was that triggering the
     * extension was impossible.
     *
     * ── AND WHY IT IS BUILT PER REQUEST ─────────────────────────────────────
     *
     * The committed source hardcodes one hostname in three places, one of them
     * `host_permissions` — which decides whether the service worker's fetch is a
     * privileged extension request or an ordinary blocked cross-origin one. Pointed at the
     * wrong host it fails from inside a live interview with nothing in Chrome naming the
     * manifest. {@see InterviewExtension} injects the host from this request's own URI, so
     * a staging install and a renamed domain both get a correct extension.
     *
     * A GET, and no id: the extension is per DEPLOYMENT, not per sitting. What is per
     * sitting is the live key, and that is pasted separately and deliberately — one key
     * baked into a download is a key that outlives the interview.
     */
    public function extension(Request $req, Response $res): Response
    {
        if ($b = $this->blocked($res, false)) return $b;

        $uri  = $req->getUri();
        $port = $uri->getPort();
        // Rebuilt from the URI rather than read from a Host header or a setting: the whole
        // failure this guards against is a baked address that disagrees with reality, and
        // the address the operator is looking at cannot.
        $base = $uri->getScheme() . '://' . $uri->getHost()
              . ($port !== null && !in_array($port, [80, 443], true) ? ':' . $port : '');

        try {
            $pack = \AfricaGates\Services\InterviewExtension::pack($base);
        } catch (\Throwable $e) {
            // Reported on the screen they came from, in its own words. A 500 here reads as
            // a broken console, and the two real causes — the folder was left out of the
            // upload, or the host has no zip extension — have different fixes.
            $_SESSION['flash_error'] = $e->getMessage();
            return $this->back($res, '/admin/interviews');
        }

        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'interview.extension_download',
                              null, null, ['host' => $pack['host']]);

        $res->getBody()->write($pack['bytes']);

        return $res
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $pack['filename'] . '"')
            ->withHeader('Content-Length', (string) strlen($pack['bytes']))
            // Built from this request's host, so a proxy or a browser holding it would hand
            // the next deployment somebody else's address.
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Content-Type-Options', 'nosniff');
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
            // Whether the extension download can be offered, and why not when it cannot.
            // A button that leads to a 500 is worse than a sentence saying the folder was
            // left out of the upload, which is one of the two real causes.
            'ext_ready'  => \AfricaGates\Services\InterviewExtension::available(),
            'ext_why'    => \AfricaGates\Services\InterviewExtension::why(),
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
        // Converted, not just trimmed: the field renders through |when_input, so the
        // browser hands back the organiser's own wall clock and storage is UTC.
        $when      = (string) (\AfricaGates\Support\DisplayTime::toStored($b2['scheduled_at'] ?? null) ?? '');

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

        // The panel, PLUS anybody the operator added by hand. Merged here rather than
        // stored merged: the panel is an integrity record that changes when judges are
        // assigned, and the guest list is a note about one meeting. Keeping them apart is
        // what stops "let the interpreter join" from becoming "appoint the interpreter".
        $guests = array_values(array_unique(array_merge(
            array_values(array_filter(array_column($d['panel'], 'email'))),
            $d['guests'] ?? []
        )));
        $r = GoogleMeetService::boot()->createSpace([
            'title'       => GoogleMeetService::eventTitle((string) $d['nominee']),
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
            // Calendar has no co-host field — co-host is a Meet concept the host grants
            // inside the call. `guestsCanModify` is the nearest thing an EVENT can carry,
            // and being invited at all is what makes Meet admit somebody rather than make
            // them knock. See the migration note.
            'guests_can_edit' => (bool) ($d['guests_can_edit'] ?? false),
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

    /**
     * Save the addresses of people who are in the meeting but not on the panel.
     *
     * Its own action rather than a field on the schedule form: the guest list changes for
     * reasons that have nothing to do with the appointment (an interpreter confirmed three
     * days later), and folding it into "reschedule" would clear the nominee's confirmation
     * and re-queue both reminders every time somebody added a note-taker.
     */
    public function guests(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id   = (int) ($args['id'] ?? 0);
        $body = (array) $req->getParsedBody();

        $r = InterviewService::setGuests($id, (string) ($body['guests'] ?? ''),
                                         !empty($body['guests_can_edit']));

        $_SESSION[($r['ok'] ?? false) ? 'flash' : 'flash_error'] = (string) $r['message'];
        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'interview.guests', 'interview',
                              $id, ['count' => (int) $r['saved']]);

        return $this->back($res, '/admin/interviews/' . $id);
    }

    /**
     * Re-read this sitting's appointment out of Google and correct our copy.
     *
     * Also runs on its own during the maintenance sweep for anything due within two hours
     * ({@see \AfricaGates\Services\InterviewBot::sweep()}). Offered as a button as well
     * because an operator who has just dragged the meeting wants the console to agree with
     * the calendar NOW rather than on the next tick — and because "why does this page say
     * Tuesday" deserves an answer they can press.
     */
    public function refresh(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id = (int) ($args['id'] ?? 0);

        $r = InterviewService::reconcileFromCalendar($id);

        $_SESSION[($r['ok'] ?? false) ? 'flash' : 'flash_error'] = (string) $r['message'];
        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'interview.calendar_refresh',
                              'interview', $id, ['changed' => (bool) $r['changed']]);

        return $this->back($res, '/admin/interviews/' . $id);
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
            // The event title scopes the Apps Script's Drive fallback to this sitting —
            // without it that branch would take the newest transcript in the whole Drive,
            // which on a two-interview day is somebody else's.
            $det = InterviewService::detail($id);
            $r   = GoogleMeetService::boot()->transcript(
                (string) ($iv->meet_code ?? ''),
                (string) ($iv->scheduled_at ?? ''),
                GoogleMeetService::eventTitle((string) ($det['nominee'] ?? '')));
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

    /** Replace the extension's live key — pasted into the wrong browser, or shared. */
    public function rotateLive(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id = (int) ($args['id'] ?? 0);
        $t  = \AfricaGates\Services\InterviewLive::rotate($id);
        $_SESSION[$t !== '' ? 'flash' : 'flash_error'] = $t !== ''
            ? 'A new live key has been issued. Paste it into the extension again — the old one no longer works.'
            : 'The key could not be replaced just now.';
        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'interview.live_rotate', 'interview', $id);
        return $this->back($res, '/admin/interviews/' . $id);
    }

    /**
     * Publish what the extension captured.
     *
     * Goes through InterviewService::publish() like every other route, so the consent gate,
     * the machine labelling and the figure check all apply — there is no shortcut for having
     * arrived through an extension.
     */
    public function saveLive(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id   = (int) ($args['id'] ?? 0);
        $text = \AfricaGates\Services\InterviewLive::assemble($id);

        if (trim($text) === '') {
            $_SESSION['flash_error'] = 'Nothing has been captured for this interview yet.';
            return $this->back($res, '/admin/interviews/' . $id);
        }

        $r = InterviewService::publish($id, $text, [
            'source'      => 'machine',
            'transcriber' => 'Google Meet live captions, captured by the Africa GATES extension',
        ], (int) ($_SESSION['admin_id'] ?? 0) ?: null);

        $_SESSION[($r['ok'] ?? false) ? 'flash' : 'flash_error'] = (string) $r['message'];
        if ($r['ok'] ?? false) {
            $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'interview.publish',
                'interview', $id, ['transcript_id' => $r['transcript_id'] ?? 0, 'source' => 'captions']);
        }
        return $this->back($res, '/admin/interviews/' . $id);
    }

    // ══ the recording bot ════════════════════════════════════════════════════

    /**
     * Send the bot into this sitting now, rather than waiting for the sweep.
     *
     * The sweep sends it a few minutes before the scheduled start and is the ordinary
     * path. This is for the two cases the schedule cannot cover: a sitting that started
     * late, and one where the nominee only pressed the consent button once everybody was
     * already in the room.
     */
    public function botSend(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id = (int) ($args['id'] ?? 0);
        $r  = \AfricaGates\Services\InterviewBot::dispatch($id);

        $_SESSION[($r['ok'] ?? false) ? 'flash' : 'flash_error'] = (string) $r['message'];
        if ($r['ok'] ?? false) {
            $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'interview.bot_send',
                'interview', $id, ['bot_id' => $r['bot_id'] ?? '']);
        }
        return $this->back($res, '/admin/interviews/' . $id);
    }

    /** Pull the bot out. Reads whatever it heard first — see InterviewBot::remove(). */
    /**
     * Send the operator to the recording, on a link minted for this click.
     *
     * Attendee's download URL is presigned and expires in thirty minutes, so there is no
     * durable link to put in the page — see {@see \AfricaGates\Services\InterviewBot::collectRecording()}
     * for the bug that came of storing one. A redirect keeps the credential out of the
     * rendered HTML as well, which matters on a page a panel can have open for an hour.
     *
     * Read-level access: listening to a sitting you can already read the transcript of
     * changes nothing.
     */
    public function botRecording(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res, false)) return $b;
        $id = (int) ($args['id'] ?? 0);

        $url = \AfricaGates\Services\InterviewBot::recordingLink($id);
        if ($url === '') {
            $_SESSION['flash_error'] = 'That recording is not available — it may still be processing, or the provider has expired it.';
            return $this->back($res, '/admin/interviews/' . $id);
        }

        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'interview.bot_recording', 'interview', $id);
        return $res->withHeader('Location', $url)->withStatus(302);
    }

    public function botRemove(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id = (int) ($args['id'] ?? 0);
        $r  = \AfricaGates\Services\InterviewBot::remove($id);

        $_SESSION[($r['ok'] ?? false) ? 'flash' : 'flash_error'] = (string) $r['message'];
        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'interview.bot_remove', 'interview', $id);
        return $this->back($res, '/admin/interviews/' . $id);
    }

    /**
     * Change what the bot is allowed to say in this sitting.
     *
     * Audited with the old value as well as the new one. "Who turned autonomous
     * questioning on for the sitting that decided this award, and when" is a question a
     * panel may have to answer to a nominee who appeals, and a log that records only the
     * current state cannot answer it.
     */
    public function botVoice(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id   = (int) ($args['id'] ?? 0);
        $want = trim((string) (((array) $req->getParsedBody())['voice_mode'] ?? ''));

        if (!in_array($want, \AfricaGates\Services\InterviewVoice::MODES, true)) {
            $_SESSION['flash_error'] = 'That is not a voice setting.';
            return $this->back($res, '/admin/interviews/' . $id);
        }

        $iv = InterviewService::byId($id);
        if (!$iv) {
            $_SESSION['flash_error'] = 'No such interview.';
            return $this->back($res, '/admin/interviews');
        }
        $was = (string) ($iv->voice_mode ?? 'off');

        \Illuminate\Database\Capsule\Manager::table('gates_interviews')
            ->where('id', $id)->update(['voice_mode' => $want]);

        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'interview.bot_voice',
            'interview', $id, ['from' => $was, 'to' => $want]);

        // The effective mode, not the requested one. An operator who picks 'auto' under a
        // platform cap of 'assisted' must be told they did not get it, here, rather than
        // discovering it from a silent bot during the interview.
        $effective = \AfricaGates\Services\InterviewVoice::mode(InterviewService::byId($id));
        $_SESSION['flash'] = $effective === $want
            ? 'Voice for this sitting is now: ' . $want . '.'
            : 'Saved as ' . $want . ', but this platform is capped at ' . $effective
                . ' — so that is what the bot will do. Change the cap in Settings.';

        return $this->back($res, '/admin/interviews/' . $id);
    }

    /**
     * Read a question aloud into the room.
     *
     * The 'assisted' path, and the reason that mode is worth having: the model wrote the
     * question and a person decided it would be asked. `$autonomous` is false here and
     * that is not a detail — it is what {@see InterviewVoice::maySpeak()} checks to keep
     * the turn loop away from the microphone in a sitting that did not ask for it.
     */
    public function botSay(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id   = (int) ($args['id'] ?? 0);
        $text = (string) (((array) $req->getParsedBody())['text'] ?? '');

        $r = \AfricaGates\Services\InterviewVoice::say($id, $text, false);

        $_SESSION[($r['ok'] ?? false) ? 'flash' : 'flash_error'] = ($r['ok'] ?? false)
            ? 'Asked: ' . (string) $r['spoken']
            : (string) $r['error'];
        if ($r['ok'] ?? false) {
            $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'interview.bot_say',
                'interview', $id, ['text' => mb_substr((string) $r['spoken'], 0, 300)]);
        }
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
