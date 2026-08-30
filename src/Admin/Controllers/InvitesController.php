<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Services\EventInvites;
use AfricaGates\Services\InviteAudience;
use AfricaGates\Services\InviteMailer;
use AfricaGates\Services\InviteReminders;
use AfricaGates\Services\OtpService;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * `/admin/events/{id}/invites` — the invitation run for a ceremony.
 *
 * ── THREE SCREENS' WORTH OF WORK, DELIBERATELY IN THREE STEPS ────────────────
 *
 * Build, read, send. Never one button, because the thing being sent is a personal letter
 * to somebody who was shortlisted, carrying their name and their own reference, and it
 * cannot be recalled. So an operator mints the list first and READS it — who is
 * unreachable, whose name is ambiguous, how many of each audience — and only then sends.
 *
 * ── AND WHY SENDING IS BATCHED ───────────────────────────────────────────────
 *
 * Each invitation renders a PDF and does an SMTP round trip, so four hundred of them is
 * far past any shared host's max_execution_time. The same shape as
 * `/__setup/broadcast`: a small batch per request, the page reports where it got to, and
 * the operator presses again. Progress is in `gates_broadcast_log`, so a request that
 * dies half way through has still recorded everything it sent.
 */
final class InvitesController
{
    /** Invitations per press. Each one is a PDF render plus an SMTP round trip. */
    private const BATCH = 15;

    public function __construct(private readonly Twig $view) {}

    public function index(Request $req, Response $res, array $args): Response
    {
        $id    = (int) ($args['id'] ?? 0);
        $event = DB::table('gates_site_events')->where('id', $id)->first();
        if (!$event) {
            $_SESSION['flash_error'] = 'That event could not be found.';
            return $res->withHeader('Location', '/admin/events')->withStatus(302);
        }

        $invites = EventInvites::forEvent($id);
        $sent    = 0;
        foreach ($invites as $i) if ($i->sent_at !== null) $sent++;

        return $this->view->render($res, 'admin/events/invites.twig', [
            'page_title' => 'Invitations — ' . $event->title,
            'admin_page' => 'events',
            'event'      => (array) $event,
            // The awards this ceremony is for. Read from the join table, not from
            // `gates_site_events.programme_id` — that is the single-programme column the
            // multi-programme migration folds INTO the join table, so on any migrated
            // install it is null and this page was rendering "From ''s published
            // shortlist" above a table of zeroes.
            'programmes' => \AfricaGates\Services\EventInvites::programmesFor($id),
            // Why the table is a table of zeroes, when it is. Five different failures
            // used to render identically here and there is no shell to go and look.
            'blockers'   => \AfricaGates\Services\EventInvites::readiness($id),
            // Read-only: nothing is minted by opening this page.
            'plan'        => EventInvites::plan($id),
            'invites'     => array_map(static fn ($r) => (array) $r, $invites),
            'sent_count'  => $sent,
            'pending'     => count($invites) - $sent,
            'audiences'   => InviteAudience::all(),
            'discount'    => InviteAudience::discountPercent(),
            'lowest_tier' => EventInvites::lowestTier($id),
            'batch'       => self::BATCH,
            // What the unattended half of this feature has done and will do. The send
            // above is a button somebody watches; the reminders are not, so the only
            // place their state can be read is here.
            'reminders'   => InviteReminders::status($id, (string) $event->event_date),
            // Pre-filled, because "send one to yourself" that makes you type your own
            // address is a step between an operator and the one check that matters.
            'admin_email' => (string) (DB::table('gates_admins')
                ->where('id', (int) ($_SESSION['admin_id'] ?? 0))->value('email') ?? ''),
        ]);
    }

    /** Mint the list. Idempotent — a second press adds only who is new. */
    public function build(Request $req, Response $res, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);

        // ── PER AUDIENCE, AND NAMING WHAT FAILED ────────────────────────────
        //
        // This reported one number for the whole run. So a build that minted twenty
        // judges and no nominees said "20 invitations minted" — which is true, reads as
        // success, and is the exact screen an operator was looking at while reporting
        // that nominees were missing. There was no way to tell "already invited" from
        // "no address" from "the insert threw", and no shell to go and look.
        $made = [];
        $kept = [];
        $failed = [];

        foreach (EventInvites::plan($id) as $audience => $group) {
            $label = strtolower((string) ($group['audience']['label'] ?? $audience));
            $made[$label]   = 0;
            $kept[$label]   = 0;

            foreach ($group['ready'] as $who) {
                $before = DB::table('gates_event_invites')->where('event_id', $id)->count();
                $row    = EventInvites::mint($id, $audience, $who, (int) ($who['cycle_id'] ?? 0) ?: null);
                $after  = DB::table('gates_event_invites')->where('event_id', $id)->count();

                if ($after > $before)      { $made[$label]++; continue; }
                if ($row !== null)         { $kept[$label]++; continue; }

                // Named, and capped: three examples tell an operator what is wrong,
                // three hundred tell them nothing they can read.
                if (count($failed) < 3) {
                    $failed[] = (string) $who['name'] . ' — ' . (EventInvites::lastMintError() ?: 'unknown reason');
                }
            }
        }

        $parts = [];
        foreach ($made as $label => $n) {
            $parts[] = $n . ' ' . $label . ($kept[$label] > 0 ? ' (' . $kept[$label] . ' already had one)' : '');
        }

        $total = array_sum($made);
        $msg   = $total === 0
            ? 'Nothing new to add. ' . implode(', ', $parts) . '.'
            : 'Minted: ' . implode(', ', $parts) . '. Read the list, then send.';

        if ($failed !== []) {
            $_SESSION['flash_error'] = count($failed) . ' could not be added: '
                                     . implode('; ', $failed) . '.';
        }

        $_SESSION['flash'] = $msg;

        return $res->withHeader('Location', '/admin/events/' . $id . '/invites')->withStatus(302);
    }

    /**
     * Give somebody an address by hand, and invite them with it.
     *
     * ── WHY THIS IS NEEDED AT ALL ────────────────────────────────────────────
     *
     * A nominee's address is looked up from their linked profile or from the nomination
     * that named them. Plenty of real nominations arrive without one — somebody was
     * nominated by a colleague who knew their name and not their inbox — and until now
     * those people sat in a list captioned "cannot be written to" with no way to write to
     * them. The organiser knows the address; the platform simply had nowhere to put it.
     *
     * ── WHAT IT DOES NOT DO ──────────────────────────────────────────────────
     *
     * It does not edit the nomination. That row is somebody else's submission and a
     * record of what they said; correcting it here would quietly rewrite the evidence a
     * panel scored. The address goes onto the INVITE, which is this platform's own record
     * of who it wrote to — and once the invite exists the address is remembered, so a
     * later rebuild does not ask again.
     *
     * ── AND WHY THE ID IS CHECKED AGAINST THE PLAN ───────────────────────────
     *
     * Minting attaches a real discount code with a real guest quota. Taking the id
     * straight from the form would let a mistyped — or crafted — value invite somebody
     * who is not on this ceremony's shortlist at all, with a code their guests can spend.
     * So the person must be in this event's own unreachable list, which is the same walk
     * the screen rendered from.
     */
    public function address(Request $req, Response $res, array $args): Response
    {
        $id   = (int) ($args['id'] ?? 0);
        $back = '/admin/events/' . $id . '/invites';
        $b    = (array) $req->getParsedBody();

        // ── ONE KEY, USED TWICE ─────────────────────────────────────────────
        //
        // This control lives INSIDE the build form — a <form> nested in a <form> is
        // deleted by the parser, so it is a `formaction` on its own submit button and the
        // whole outer form posts. Every unreachable row therefore submits its box at once,
        // and the pressed button's own name/value is what says which row was meant.
        //
        // `nominee:42` / `judge:7` rather than two fields, so the button and the box are
        // keyed by the same string and cannot come apart.
        $key   = trim((string) ($b['mint_for'] ?? ''));
        $email = trim((string) (((array) ($b['addr'] ?? []))[$key] ?? ''));

        [$kind, $rawId] = array_pad(explode(':', $key, 2), 2, '');
        $nomineeId = $kind === 'nominee' ? (int) $rawId : 0;
        $judgeId   = $kind === 'judge'   ? (int) $rawId : 0;
        $audience  = $kind === 'judge' ? InviteAudience::JUDGE : InviteAudience::NOMINEE;

        if ($nomineeId < 1 && $judgeId < 1) {
            $_SESSION['flash_error'] = 'That form did not say who the address was for. '
                                     . 'Nothing was changed.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'That is not an email address the platform can write to. '
                                     . 'Nothing was changed.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        // The same walk the screen rendered from, so an id that was never on it cannot be
        // invited by posting one.
        $match = null;
        foreach (EventInvites::plan($id) as $key => $group) {
            if ($key !== $audience) continue;
            foreach ($group['unreachable'] as $who) {
                if ($nomineeId > 0 && (int) ($who['nominee_id'] ?? 0) === $nomineeId) { $match = $who; break 2; }
                if ($judgeId   > 0 && (int) ($who['judge_id']   ?? 0) === $judgeId)   { $match = $who; break 2; }
            }
        }

        if ($match === null) {
            $_SESSION['flash_error'] = 'That person is not on this ceremony\'s list of people '
                                     . 'without an address. Nothing was changed.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        $invite = EventInvites::mint($id, $audience, [
            'name'       => (string) $match['name'],
            'email'      => $email,
            'nominee_id' => (int) ($match['nominee_id'] ?? 0),
            'judge_id'   => (int) ($match['judge_id'] ?? 0),
        ], (int) ($match['cycle_id'] ?? 0) ?: null);

        // Reported on what is TRUE afterwards. mint() returns the EXISTING row when that
        // address already has an invitation for this event, which is a different outcome
        // from having made one and must not be announced as a new invitation.
        $_SESSION[$invite === null ? 'flash_error' : 'flash'] = $invite === null
            ? 'That address could not be used — it may already belong to somebody else on '
              . 'this list. Nothing was changed.'
            : (string) $match['name'] . ' is on the list now, at ' . $email
              . '. Press Send when you are ready.';

        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /** Send one batch. */
    public function send(Request $req, Response $res, array $args): Response
    {
        $id    = (int) ($args['id'] ?? 0);
        $event = DB::table('gates_site_events')->where('id', $id)->first();
        if (!$event) {
            $_SESSION['flash_error'] = 'That event could not be found.';
            return $res->withHeader('Location', '/admin/events')->withStatus(302);
        }

        // One transport for the whole batch, so fifteen invitations are fifteen messages
        // over one connection rather than fifteen handshakes.
        $mailer = OtpService::boot();
        if (!$mailer->smtpConfigured()) {
            $_SESSION['flash_error'] = 'SMTP is not configured — set it in Settings → Email & sender first. '
                                     . 'Nothing was sent.';
            return $res->withHeader('Location', '/admin/events/' . $id . '/invites')->withStatus(302);
        }

        $ok = 0; $failed = 0; $skipped = 0;
        // sendQueue(), not forEvent(): the list screen groups by audience, which is right
        // to read and wrong to send. 'judge' sorts before 'nominee', so a batch drawn in
        // that order was all judges on any ceremony with fifteen of them — every time.
        foreach (EventInvites::sendQueue($id) as $invite) {
            if ($ok + $failed >= self::BATCH) break;

            $r = InviteMailer::send($invite, $event, $mailer);
            if ($r['skipped'])   { $skipped++; continue; }
            $r['ok'] ? $ok++ : $failed++;

            // A quarter-second between sends, the same pacing the nominee broadcast uses:
            // a shared host's relay drops a burst and reports success.
            usleep(250000);
        }

        // WHO is left, not just how many. "12 still to go" beside a list an operator has
        // just watched go out to judges is the sentence that made this look like nominees
        // were being skipped rather than queued behind a cap.
        $remaining = [];
        foreach (EventInvites::sendQueue($id) as $i) {
            $key = (string) $i->audience;
            $remaining[$key] = ($remaining[$key] ?? 0) + 1;
        }
        $left  = array_sum($remaining);
        $named = [];
        foreach ($remaining as $key => $n) {
            $spec    = InviteAudience::isValid($key) ? InviteAudience::spec($key) : null;
            $named[] = $n . ' ' . strtolower((string) ($spec['label'] ?? $key));
        }

        $_SESSION[$failed > 0 ? 'flash_error' : 'flash'] = trim(
            $ok . ' sent'
            . ($failed  > 0 ? ', ' . $failed . ' FAILED' : '')
            . ($skipped > 0 ? ', ' . $skipped . ' skipped (opted out or already sent)' : '')
            . '. ' . ($left > 0
                ? 'Still to go: ' . implode(', ', $named) . ' — press Send again.'
                : 'That is everybody.')
        );

        return $res->withHeader('Location', '/admin/events/' . $id . '/invites')->withStatus(302);
    }

    /**
     * The invitation, or the letter, for one person — or for nobody in particular.
     *
     * `{reference}` may be the literal `sample`, which resolves to an invitation-shaped
     * object that was never saved. The preview used to need a real row, so an operator
     * could not look at what they were about to send until they had already built the
     * list — and before building it is exactly when you want to look.
     *
     * @return array{0:object, 1:object}
     */
    private function subject(Request $req, array $args): array
    {
        $id    = (int) ($args['id'] ?? 0);
        $ref   = (string) ($args['reference'] ?? '');
        $event = DB::table('gates_site_events')->where('id', $id)->first();
        if (!$event) throw new \Slim\Exception\HttpNotFoundException($req);

        if ($ref === 'sample') {
            return [EventInvites::sample($id, (string) (($req->getQueryParams()['as'] ?? ''))), $event];
        }

        $invite = EventInvites::byReference($ref);
        if (!$invite || (int) $invite->event_id !== $id) {
            throw new \Slim\Exception\HttpNotFoundException($req);
        }
        return [$invite, $event];
    }

    /** The rendered invitation for one person, so an operator can read it before sending. */
    public function preview(Request $req, Response $res, array $args): Response
    {
        [$invite, $event] = $this->subject($req, $args);

        $res->getBody()->write(InviteMailer::preview($invite, $event));

        return $res->withHeader('Content-Type', 'text/html; charset=utf-8')
                   ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * The rendered REMINDER, so an operator can read what the cron will send.
     *
     * ── WHY THIS EXISTS AND IS NOT OPTIONAL ──────────────────────────────────
     *
     * Everything else on this screen is sent by somebody pressing a button and watching
     * what happens. The reminder is not: it is composed from a sentence typed on the
     * settings screen, wrapped in copy nobody outside this repository has read, and
     * posted by a cron at six in the morning to a list of people being honoured in
     * public. An operator who cannot read it before it goes is being asked to sign
     * something unseen.
     *
     * `?days=` because the message CHANGES with the countdown — "today" and "in 21 days"
     * are different letters — and an operator judging the copy needs to see the one that
     * will actually land. Defaults to the real distance to this event, so the common case
     * needs no query at all; falls back to the furthest mark for a ceremony already past,
     * where the true answer is a negative number and no reminder would ever be sent.
     */
    public function reminder(Request $req, Response $res, array $args): Response
    {
        [$invite, $event] = $this->subject($req, $args);

        $asked = $req->getQueryParams()['days'] ?? null;
        $marks = InviteReminders::marks();
        $real  = InviteReminders::daysUntil((string) $event->event_date);

        $days = $asked !== null && $asked !== ''
            // Clamped to the same range the schedule itself accepts. A preview is a
            // rendering path reachable by URL, and an unbounded integer from a query
            // string is how a page comes to render "in 999999999 days".
            ? max(0, min(365, (int) $asked))
            : ($real !== null && $real >= 0 ? $real : (int) ($marks[0] ?? 7));

        $res->getBody()->write(InviteReminders::preview($invite, $event, $days));

        return $res->withHeader('Content-Type', 'text/html; charset=utf-8')
                   ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * The formal letter, as the PDF that is actually attached.
     *
     * {@see InviteLetter::render()} was only ever called from inside the send path, so the
     * one document this whole run puts in front of a nominee could not be looked at by the
     * person sending it. Rendered here from the same call the attachment builder makes —
     * not a preview OF the letter, the letter.
     *
     * `inline`, so it opens in the browser's viewer rather than landing in Downloads: this
     * is a thing to read now, not a file to keep.
     */
    public function letter(Request $req, Response $res, array $args): Response
    {
        [$invite, $event] = $this->subject($req, $args);

        try {
            $pdf = \AfricaGates\Services\InviteLetter::render(
                $invite, $event, EventInvites::lowestTier((int) $event->id)
            );
        } catch (\Throwable $e) {
            // Said rather than 500ed: the commonest cause is a missing font file, and a
            // stack trace does not tell an operator that the letter their invitees are
            // about to receive is the one thing in the run that is broken.
            $_SESSION['flash_error'] = 'The letter could not be rendered: ' . $e->getMessage();
            return $res->withHeader('Location', '/admin/events/' . (int) $event->id . '/invites')
                       ->withStatus(302);
        }

        $res->getBody()->write($pdf);

        return $res
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition',
                'inline; filename="' . \AfricaGates\Services\InviteLetter::fileName($invite) . '"')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * Send one real invitation to an address the operator types — their own, by default.
     *
     * The whole run is irreversible and personal, and there was no way to see what lands
     * in an inbox short of sending to a nominee. A preview in a browser tab is not that
     * test: it does not prove SMTP works, does not prove the letter attaches, and does not
     * show what Gmail does to the layout.
     *
     * Changes nothing — see {@see InviteMailer::sendTest()} for why it cannot be `send()`
     * with a different address.
     */
    public function test(Request $req, Response $res, array $args): Response
    {
        $id    = (int) ($args['id'] ?? 0);
        $back  = '/admin/events/' . $id . '/invites';
        $event = DB::table('gates_site_events')->where('id', $id)->first();
        if (!$event) {
            $_SESSION['flash_error'] = 'That event could not be found.';
            return $res->withHeader('Location', '/admin/events')->withStatus(302);
        }

        $b  = (array) $req->getParsedBody();
        $to = trim((string) ($b['to'] ?? ''));
        if ($to === '') {
            $to = trim((string) (DB::table('gates_admins')
                ->where('id', (int) ($_SESSION['admin_id'] ?? 0))->value('email') ?? ''));
        }

        $mailer = OtpService::boot();
        if (!$mailer->smtpConfigured()) {
            $_SESSION['flash_error'] = 'SMTP is not configured — set it in Settings → Email & sender first.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        // A real invitee when there is one, so the test carries a real name and reference;
        // the sample when the list has not been built, so this works on day one.
        $ref     = trim((string) ($b['reference'] ?? ''));
        $invite  = $ref !== '' ? EventInvites::byReference($ref) : null;
        if (!$invite || (int) $invite->event_id !== $id) {
            $first  = EventInvites::forEvent($id);
            $invite = $first[0] ?? EventInvites::sample($id);
        }

        $r = InviteMailer::sendTest($invite, $event, $to, $mailer);

        $_SESSION[$r['ok'] ? 'flash' : 'flash_error'] = $r['ok']
            ? 'A test invitation was sent to ' . $to . '. Nothing was recorded against the run.'
            : 'The test was not sent: ' . ($r['error'] !== '' ? $r['error'] : 'the mail server refused it.');

        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /**
     * Apply the setup step that creates the awards-to-event link, again.
     *
     * ── WHY A BUTTON AND NOT "RUN THE MIGRATION" ─────────────────────────────
     *
     * `/__setup/migrate` skips anything the ledger records as applied, and the ledger can
     * say applied for a step whose table is not there. There is no shell on this host, so
     * an operator in that state has been told to do the one thing that provably cannot
     * work — which is exactly what happened.
     *
     * Superadmin only, and named to one step rather than a general "re-run migrations":
     * every migration here is idempotent by contract, but re-applying a hundred and fifty
     * of them because one is wrong is a far larger act than the fault, and the person
     * pressing this can only be sure about the one in front of them.
     */
    public function repair(Request $req, Response $res, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);

        // Back to where the button was pressed. The same repair is offered on the event
        // form, where the missing link is what hides the tick boxes, and landing an
        // operator on the invitation screen instead moves them somewhere they were not
        // and interrupts what they were doing.
        $back = ($req->getQueryParams()['back'] ?? '') === 'form'
            ? '/admin/events/' . $id
            : '/admin/events/' . $id . '/invites';

        if ((string) ($_SESSION['admin_role'] ?? '') !== 'superadmin') {
            $_SESSION['flash_error'] = 'Only a superadmin can apply a setup step.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        // WHITELISTED, never taken as given. {@see MigrationRunner::rerun()} already
        // refuses a path that is not one of its own steps, so this is not about file
        // inclusion — it is about the other hundred and fifty steps that ARE in that list.
        // Two are offerable from this screen and the request may pick between them; a
        // posted name is not a licence to re-apply an unrelated migration.
        $asked = (string) (((array) $req->getParsedBody())['step'] ?? '');
        $step  = in_array($asked, [EventInvites::MIGRATION, EventInvites::REPAIR_AUDIENCE], true)
            ? $asked
            : EventInvites::MIGRATION;

        $r = \AfricaGates\Services\MigrationRunner::rerun($step);

        // Report what is TRUE afterwards, not what the runner returned: a step can finish
        // without an exception and still not have done its work, and that is the whole
        // fault this button exists for. Saying "applied" over a database that is still
        // wrong would put the operator back where they started, one press later.
        if ($step === EventInvites::REPAIR_AUDIENCE) {
            $ok = EventInvites::audienceAcceptsNominee();

            if ($ok === true) {
                $_SESSION['flash'] = 'The invitations table takes nominees now. '
                                   . 'Press Build the list again.';
            } elseif ($ok === null) {
                // Distinct from a failed repair on purpose: "the step ran and the database
                // cannot be asked whether it worked" is a different problem from "the step
                // ran and did not work", and they are fixed differently.
                $_SESSION['flash_error'] = 'The step ran, but this database cannot be asked '
                    . 'what the column accepts, so there is nothing to confirm. ' . $r['message'];
            } else {
                $_SESSION['flash_error'] = 'That did not widen the column. ' . $r['message']
                    . ' The deploy may be missing database/migrations/'
                    . EventInvites::REPAIR_AUDIENCE . '.';
            }

            return $res->withHeader('Location', $back)->withStatus(302);
        }

        $made = false;
        try { $made = DB::schema()->hasTable('gates_event_programmes'); } catch (\Throwable) {}

        if ($made) {
            $_SESSION['flash'] = 'The awards link is in place. Tick the awards on the event, '
                               . 'then come back here.';
        } else {
            $_SESSION['flash_error'] = 'That did not create the table. ' . $r['message']
                . ' The deploy may be missing database/migrations/' . EventInvites::MIGRATION . '.';
        }

        return $res->withHeader('Location', $back)->withStatus(302);
    }

    /**
     * The picture that travels with the invitation.
     *
     * ── WHY THIS SCREEN AND NOT THE EVENT FORM ───────────────────────────────
     *
     * The field feeding it is on the event form, and it is `type="url"` — while
     * {@see InviteMailer::cover()} refuses any value beginning `http`, because an
     * attachment has to be a file on this disk. So the only value the form would accept
     * was the one value the attachment builder throws away, and no invitation has ever
     * carried a picture. A browser will not even submit a bare `uploads/x.jpg` into a
     * url-typed input, so the local path the backend wants could not be typed either.
     *
     * An upload is the honest control: it produces a local path by construction. It is
     * offered HERE because this is the screen where somebody is thinking about the
     * invitation — and the copy beside it says plainly that the image is the event's
     * cover, used on the ticket and the event page too, because a control with a wider
     * blast radius than its label is how an operator changes something they did not mean
     * to touch.
     */
    public function image(Request $req, Response $res, array $args): Response
    {
        $id   = (int) ($args['id'] ?? 0);
        $back = '/admin/events/' . $id . '/invites';
        if (!DB::table('gates_site_events')->where('id', $id)->exists()) {
            $_SESSION['flash_error'] = 'That event could not be found.';
            return $res->withHeader('Location', '/admin/events')->withStatus(302);
        }

        $b = (array) $req->getParsedBody();
        if (!empty($b['remove'])) {
            DB::table('gates_site_events')->where('id', $id)->update(['cover_image' => '']);
            $_SESSION['flash'] = 'The invitation image was removed.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        $file = $req->getUploadedFiles()['cover'] ?? null;
        if (!$file || $file->getError() === UPLOAD_ERR_NO_FILE) {
            $_SESSION['flash_error'] = 'No image was chosen.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        try {
            // 1600px is the delivered width, not a master: this file is attached to every
            // invitation in the run, and MAX_COVER_BYTES drops anything over 2.5MB on the
            // floor silently. A 900px floor because a picture narrower than the band it
            // fills arrives blurred in somebody's inbox and cannot be fixed afterwards.
            $up = (new \AfricaGates\Admin\Services\UploadService())->uploadImage(
                $file, 'events', 1600, 82,
                ((int) ($_SESSION['admin_id'] ?? 0)) ?: null, 'site_event', $id, 900
            );
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'That image was not used: ' . $e->getMessage();
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        // The LOCAL path, deliberately — a hosted URL is exactly what cover() refuses, and
        // storing one here would put the screen back where it started.
        $local = ltrim((string) ($up['local'] ?? ''), '/');
        if ($local === '') {
            $_SESSION['flash_error'] = 'That image was stored remotely, so it cannot be attached.';
            return $res->withHeader('Location', $back)->withStatus(302);
        }

        DB::table('gates_site_events')->where('id', $id)->update(['cover_image' => $local]);
        $_SESSION['flash'] = 'The invitation image was set. It travels with every letter in this run.';

        return $res->withHeader('Location', $back)->withStatus(302);
    }
}
