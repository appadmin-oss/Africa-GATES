<?php
declare(strict_types=1);

namespace AfricaGates\Controllers;

use AfricaGates\Services\Notifier;
use AfricaGates\Services\QuestionnaireService;
use AfricaGates\Support\ClientIp;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * "Tell the judges about your work" — the nominee's own page.
 *
 * ── OPEN TO GUESTS, WITH A TOKEN AS THE WHOLE CREDENTIAL ─────────────────────
 *
 * The third page on this platform built that way, after the claim link and the interview
 * link, and for the same reason each time: a nominee has no account, and requiring one to
 * describe their own work would shut out exactly the population the awards exist to find.
 * The token opens one submission and does four things — read it, save a draft, attach a
 * file, send it.
 *
 * ── SAVING IS CHEAP AND SUBMITTING IS DELIBERATE ─────────────────────────────
 *
 * A draft save never validates anything: somebody filling this in on a phone between other
 * work must be able to leave half a sentence and come back tomorrow. A form that refuses to
 * save until it is perfect is a form that gets abandoned, and an abandoned questionnaire is
 * a judge reading a nominator's paragraph again.
 *
 * Submitting is the only step that checks the required answers, and it is the step that puts
 * the work in front of the panel — so it asks for a typed name first.
 *
 * ── AND FILE UPLOADS ARE THEIR OWN REQUEST ───────────────────────────────────
 *
 * Separate from the draft save, because an upload can fail on its own — too large, or a type
 * the platform will not store — and losing a page of typing to a rejected attachment is
 * exactly the kind of thing that stops somebody finishing.
 */
final class MyWorkController
{
    public function __construct(private readonly Twig $view) {}

    public function page(Request $req, Response $res, array $args = []): Response
    {
        $token = (string) ($args['token'] ?? '');
        $form  = QuestionnaireService::formFor($token);

        if ($form === null) {
            // Never says whether the token is unknown or spent: the difference between those
            // two answers is a way to test tokens.
            return $this->render($res->withStatus(404), null, $token, '', '');
        }

        $notice = (string) ($_SESSION['mywork_notice'] ?? '');
        $error  = (string) ($_SESSION['mywork_error'] ?? '');
        $missing = $_SESSION['mywork_missing'] ?? [];
        unset($_SESSION['mywork_notice'], $_SESSION['mywork_error'], $_SESSION['mywork_missing']);

        return $this->render($res, $form, $token, $notice, $error,
                             is_array($missing) ? $missing : []);
    }

    /** Save a draft, or send it to the panel — whichever button was pressed. */
    public function save(Request $req, Response $res, array $args = []): Response
    {
        $token = (string) ($args['token'] ?? '');
        $body  = (array) $req->getParsedBody();
        $to    = '/my-work/' . $token;

        unset($_SESSION['mywork_notice'], $_SESSION['mywork_error'], $_SESSION['mywork_missing']);

        $answers = is_array($body['a'] ?? null) ? $body['a'] : [];
        $works   = is_array($body['w'] ?? null) ? array_values($body['w']) : [];

        // The draft is stored on BOTH paths. A submit that fails validation must not throw
        // away what was typed on the way to failing.
        $saved = QuestionnaireService::saveDraft($token, $answers, $works);
        if (!($saved['ok'] ?? false)) {
            $_SESSION['mywork_error'] = (string) $saved['message'];
            return $res->withHeader('Location', $to)->withStatus(302);
        }

        if ((string) ($body['action'] ?? '') !== 'submit') {
            $_SESSION['mywork_notice'] = (string) $saved['message'];
            return $res->withHeader('Location', $to)->withStatus(302);
        }

        $r = QuestionnaireService::submit(
            $token,
            (string) ($body['declared_name'] ?? ''),
            ClientIp::from($req)
        );

        if ($r['ok'] ?? false) {
            $_SESSION['mywork_notice'] = (string) $r['message'];
        } else {
            $_SESSION['mywork_error']   = (string) $r['message'];
            $_SESSION['mywork_missing'] = $r['missing'] ?? [];
        }
        return $res->withHeader('Location', $to)->withStatus(302);
    }

    /**
     * The conversation: one turn in, the next question out.
     *
     * JSON, because a page reload between "I typed my answer" and "here is the next question"
     * loses the thread — and because the answer is already stored server-side by the time this
     * responds, so a dropped connection costs the reply and not the answer.
     */
    public function chat(Request $req, Response $res, array $args = []): Response
    {
        $token = (string) ($args['token'] ?? '');
        $body  = (array) $req->getParsedBody();
        $said  = trim((string) ($body['say'] ?? ''));

        $r = $said === ''
            ? \AfricaGates\Services\QuestionnaireChat::start($token)
            : \AfricaGates\Services\QuestionnaireChat::say($token, $said);

        // Told once per conversation, so the operator screen can say which engine built the
        // record — the same disclosure the interview pack and the transcript reading carry.
        if (($r['ok'] ?? false) && $said !== '') {
            \AfricaGates\Services\QuestionnaireChat::noteSource(
                $token, \AfricaGates\Services\AiGateway::available('questionnaire.chat') ? 'ai' : 'rules');
        }

        $r['readiness'] = \AfricaGates\Services\QuestionnaireChat::readiness($token);

        $res->getBody()->write((string) json_encode($r));
        return $res->withHeader('Content-Type', 'application/json')
                   ->withStatus(($r['ok'] ?? false) ? 200 : 422);
    }

    /**
     * The live interview: one turn in, the next question out.
     *
     * Separate from {@see chat()} rather than a mode inside it, because the two are different
     * features that happen to look alike. `chat()` walks a question list and stores an answer
     * against a slug; this holds a conversation and moves an outcome ledger. Folding them
     * together would mean one method with two of everything and a branch at the top of each.
     */
    public function interview(Request $req, Response $res, array $args = []): Response
    {
        $token = (string) ($args['token'] ?? '');
        $body  = (array) $req->getParsedBody();
        $said  = trim((string) ($body['say'] ?? ''));

        $r = $said === ''
            ? \AfricaGates\Services\QuestionnaireInterview::open($token)
            : \AfricaGates\Services\QuestionnaireInterview::say($token, $said);

        return $this->json($res, $r, ($r['ok'] ?? false) ? 200 : 422);
    }

    /**
     * Leave the conversation for the form.
     *
     * A REDIRECT rather than JSON, because it is a navigation: the page that comes back is a
     * different page. It is also the reason this works with the script broken — the escape
     * hatch on every screen is a real form post, so a nominee whose JavaScript has failed can
     * still get out.
     */
    public function interviewSwitch(Request $req, Response $res, array $args = []): Response
    {
        $token = (string) ($args['token'] ?? '');
        $r = \AfricaGates\Services\QuestionnaireInterview::switchToForm($token);
        $_SESSION[($r['ok'] ?? false) ? 'mywork_notice' : 'mywork_error'] = (string) $r['message'];
        return $res->withHeader('Location', '/my-work/' . $token)->withStatus(302);
    }

    /** Talk, show, review. The nominee moving themselves between the interview's phases. */
    public function interviewPhase(Request $req, Response $res, array $args = []): Response
    {
        $token = (string) ($args['token'] ?? '');
        $body  = (array) $req->getParsedBody();
        \AfricaGates\Services\QuestionnaireInterview::setPhase($token, (string) ($body['phase'] ?? 'talk'));
        return $this->json($res, \AfricaGates\Services\QuestionnaireInterview::state($token));
    }

    /**
     * The nominee taking one of their own messages back.
     *
     * The undo route a verbatim doctrine has to have. "Stored exactly as you said it" with no
     * way out is a trap for anybody who mistypes a figure or names somebody who would rather
     * not be named — in a record a judging panel reads.
     */
    public function interviewAmend(Request $req, Response $res, array $args = []): Response
    {
        $token = (string) ($args['token'] ?? '');
        $body  = (array) $req->getParsedBody();

        $r = \AfricaGates\Services\QuestionnaireInterview::amend(
            $token,
            (int) ($body['turn'] ?? -1),
            (string) ($body['how'] ?? ''),
            (string) ($body['text'] ?? ''),
        );
        return $this->json($res, $r, ($r['ok'] ?? false) ? 200 : 422);
    }

    /**
     * The nominee correcting, or deleting, one of the machine's readings.
     *
     * This is the whole point of the review screen. Every value the model produced is labelled
     * machine-derived and carries the quote behind it, and none of it goes to a panel until a
     * person has had the chance to say "that is not what I meant".
     */
    public function interviewOutcome(Request $req, Response $res, array $args = []): Response
    {
        $token = (string) ($args['token'] ?? '');
        $body  = (array) $req->getParsedBody();
        $slug  = (string) ($body['slug'] ?? '');

        $s = QuestionnaireService::byToken($token);
        if (!$s) return $this->json($res, ['ok' => false, 'message' => 'That link is not valid.'], 422);

        if ((string) ($body['action'] ?? '') === 'drop') {
            \AfricaGates\Services\QuestionnaireLedger::drop($s, $slug);
            $r = ['ok' => true, 'message' => 'Removed from what will be sent.'];
        } else {
            $r = \AfricaGates\Services\QuestionnaireLedger::correct($s, $slug, (string) ($body['text'] ?? ''));
            $r['message'] = ($r['ok'] ?? false) ? 'Changed to your words.' : (string) $r['reason'];
        }

        return $this->json($res, $r + \AfricaGates\Services\QuestionnaireInterview::state($token),
                           ($r['ok'] ?? false) ? 200 : 422);
    }

    /** JSON, with the headers a token-gated page needs on every one of its endpoints. */
    private function json(Response $res, array $payload, int $code = 200): Response
    {
        $res->getBody()->write((string) json_encode($payload));
        return $res->withHeader('Content-Type', 'application/json')
                   ->withHeader('X-Robots-Tag', 'noindex, nofollow')
                   ->withStatus($code);
    }

    /**
     * Read one of the AI's questions aloud. Returns MP3 bytes, not JSON.
     *
     * Addressed by TURN INDEX, never by text: the page says "speak turn 4 of my own
     * conversation", so this endpoint cannot be used to have the platform's ElevenLabs
     * account read out a stranger's paragraph. {@see QuestionnaireVoice::say()} for why that
     * shape also removes the need for a character quota.
     */
    public function speak(Request $req, Response $res, array $args = []): Response
    {
        $token = (string) ($args['token'] ?? '');
        $body  = (array) $req->getParsedBody();
        $index = (int) ($body['turn'] ?? -1);

        $r = \AfricaGates\Services\QuestionnaireVoice::say($token, $index);

        if (!($r['ok'] ?? false)) {
            // JSON on failure, audio on success. The page needs to be able to tell the
            // nominee something ("the voice service did not answer — you can still read it")
            // and it cannot read a message out of an empty audio body.
            $res->getBody()->write((string) json_encode(['ok' => false,
                'message' => (string) ($r['message'] ?? 'Voice is not available.')]));
            return $res->withHeader('Content-Type', 'application/json')
                       ->withHeader('X-Robots-Tag', 'noindex, nofollow')
                       ->withStatus(422);
        }

        $res->getBody()->write((string) $r['audio']);
        return $res
            ->withHeader('Content-Type', (string) ($r['mime'] ?? 'audio/mpeg'))
            ->withHeader('Content-Length', (string) strlen((string) $r['audio']))
            // Private, because the URL is behind a token that is the whole credential and a
            // shared cache holding a nominee's questionnaire audio would defeat that. Long,
            // because the clip for a given turn never changes — a replay should not cost a
            // round trip, let alone a character.
            ->withHeader('Cache-Control', 'private, max-age=86400')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * Transcribe a spoken answer and hand the words back to the page.
     *
     * Writes nothing. The nominee reads the transcription, corrects whatever the recogniser
     * misheard, and then sends it through the ordinary chat turn — so the answer stored
     * against their name is still one they approved, which is the promise the whole
     * questionnaire is built on.
     */
    public function listen(Request $req, Response $res, array $args = []): Response
    {
        $token = (string) ($args['token'] ?? '');
        $file  = $req->getUploadedFiles()['audio'] ?? null;

        $fail = static function (Response $res, string $message, int $status = 422): Response {
            $res->getBody()->write((string) json_encode(['ok' => false, 'message' => $message]));
            return $res->withHeader('Content-Type', 'application/json')
                       ->withHeader('X-Robots-Tag', 'noindex, nofollow')
                       ->withStatus($status);
        };

        if ($file === null) return $fail($res, 'No recording arrived. Try the microphone again.');
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return $fail($res, 'That recording did not finish uploading. On a weak connection, a '
                             . 'shorter answer usually gets through.');
        }

        $size = (int) ($file->getSize() ?? 0);
        if ($size > \AfricaGates\Services\VoiceService::MAX_AUDIO_BYTES) {
            return $fail($res, 'That recording is too long. Say it in two or three shorter pieces.');
        }

        $bytes = (string) $file->getStream()->getContents();

        $r = \AfricaGates\Services\QuestionnaireVoice::hear(
            $token,
            $bytes,
            (string) ($file->getClientFilename() ?? 'answer.webm'),
            (string) ($file->getClientMediaType() ?? 'audio/webm')
        );

        $res->getBody()->write((string) json_encode($r));
        return $res->withHeader('Content-Type', 'application/json')
                   ->withHeader('X-Robots-Tag', 'noindex, nofollow')
                   ->withStatus(($r['ok'] ?? false) ? 200 : 422);
    }

    /**
     * Read one answer as it is being written, and say the single most useful thing about it.
     *
     * ── WHY THIS IS NOT THE READINESS PANEL ──────────────────────────────────
     *
     * The readiness panel makes the same observations at the END, which is the worst moment:
     * the nominee has finished, they are ready to be done, and being sent back to four
     * questions reads as rejection rather than help. The likeliest response is to send it
     * anyway.
     *
     * Beside the box, while the thought is still in their head, "a number here would make this
     * much stronger" is a suggestion somebody acts on in ten seconds.
     *
     * Writes NOTHING. It is a read on text the nominee has not even saved yet, and a coach
     * that recorded its own opinions would be building a second, hidden assessment of a person
     * next to the one the judges are making.
     */
    public function coach(Request $req, Response $res, array $args = []): Response
    {
        $token = (string) ($args['token'] ?? '');
        $body  = (array) $req->getParsedBody();
        $slug  = trim((string) ($body['slug'] ?? ''));
        $text  = (string) ($body['text'] ?? '');

        $json = function (array $payload) use ($res): Response {
            $res->getBody()->write((string) json_encode($payload));
            return $res->withHeader('Content-Type', 'application/json')
                       ->withHeader('X-Robots-Tag', 'noindex, nofollow');
        };

        $s = QuestionnaireService::byToken($token);
        if (!$s) return $json(['ok' => false]);

        // The question is resolved from the SUBMISSION's own applicable set, not from anything
        // the caller sent: a coach that would read any slug against any text is a free
        // language-model endpoint on the operator's key.
        $question = null;
        foreach (QuestionnaireService::questionsFor($s) as $q) {
            if ((string) $q['slug'] === $slug) { $question = $q; break; }
        }
        if ($question === null) return $json(['ok' => false]);

        $r = \AfricaGates\Services\QuestionnaireCoach::read($question, $text);

        return $json(['ok' => true, 'state' => $r['state'], 'note' => $r['note'],
                      'nudge' => $r['nudge']]);
    }

    /**
     * The brief has been read. Until this, the questions do not start.
     *
     * A POST rather than a query flag, because it is a record that a person saw something —
     * and a mail scanner fetching every URL in a message must not be able to make that record
     * on their behalf. Same reasoning as the interview consent page.
     */
    public function ready(Request $req, Response $res, array $args = []): Response
    {
        $token = (string) ($args['token'] ?? '');
        \AfricaGates\Services\QuestionnaireIntro::markSeen($token);
        return $res->withHeader('Location', '/my-work/' . $token)->withStatus(302);
    }

    /**
     * The spoken introduction: store it, transcribe it, hand back what we heard.
     *
     * Unlike a spoken ANSWER, this recording is KEPT — it is the artefact a judge is meant to
     * hear, not a way of typing. So it goes to disk, outside the web root, and the transcript
     * is a convenience alongside it rather than a replacement for it.
     */
    public function intro(Request $req, Response $res, array $args = []): Response
    {
        $token = (string) ($args['token'] ?? '');
        $body  = (array) $req->getParsedBody();
        $file  = $req->getUploadedFiles()['audio'] ?? null;

        $json = function (array $payload, int $code = 200) use ($res): Response {
            $res->getBody()->write((string) json_encode($payload));
            return $res->withHeader('Content-Type', 'application/json')
                       ->withHeader('X-Robots-Tag', 'noindex, nofollow')
                       ->withStatus($code);
        };

        $action = (string) ($body['action'] ?? 'record');

        if ($action === 'delete') {
            $r = \AfricaGates\Services\QuestionnaireIntro::remove($token);
            return $json(['ok' => (bool) $r['ok'], 'message' => (string) $r['message']],
                         ($r['ok'] ?? false) ? 200 : 422);
        }
        if ($action === 'consent') {
            $r = \AfricaGates\Services\QuestionnaireIntro::consent($token);
            return $json(['ok' => (bool) $r['ok'], 'message' => (string) $r['message']],
                         ($r['ok'] ?? false) ? 200 : 422);
        }

        if ($file === null) {
            return $json(['ok' => false, 'message' => 'No recording arrived. Try again.'], 422);
        }

        $r = \AfricaGates\Services\QuestionnaireIntro::record(
            $token, $file, (int) ($body['seconds'] ?? 0)
        );

        $form = QuestionnaireService::byToken($token);
        $r['state'] = \AfricaGates\Services\QuestionnaireIntro::state($form, $token);

        return $json($r, ($r['ok'] ?? false) ? 200 : 422);
    }

    /**
     * Play the recording back.
     *
     * Reachable with the submission's own token, because the nominee has no account and must
     * be able to hear what they just recorded before agreeing that a panel will. A panel
     * reaches the same file through the dossier, from a session.
     *
     * Streamed rather than redirected to a public path: the file is stored outside the web
     * root on purpose, and a nominee's voice behind nothing but an unlisted URL is a
     * confidentiality change nobody asked for.
     */
    public function introAudio(Request $req, Response $res, array $args = []): Response
    {
        $token = (string) ($args['token'] ?? '');
        $s     = QuestionnaireService::byToken($token);
        $path  = \AfricaGates\Services\QuestionnaireIntro::fileFor($s);

        if ($path === null) return $res->withStatus(404);

        $res->getBody()->write((string) file_get_contents($path));
        return $res
            ->withHeader('Content-Type', \AfricaGates\Services\QuestionnaireIntro::mimeFor($path))
            ->withHeader('Content-Length', (string) filesize($path))
            // Private: the URL carries a token that is the whole credential, and a shared
            // cache holding a nominee's voice would defeat that entirely.
            ->withHeader('Cache-Control', 'private, max-age=600')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /** One file, attached to one listed work. */
    public function upload(Request $req, Response $res, array $args = []): Response
    {
        $token = (string) ($args['token'] ?? '');
        $body  = (array) $req->getParsedBody();
        $file  = $req->getUploadedFiles()['file'] ?? null;

        if ($file === null) {
            $_SESSION['mywork_error'] = 'No file was chosen.';
            return $res->withHeader('Location', '/my-work/' . $token)->withStatus(302);
        }

        $r = QuestionnaireService::attachFile($token, (string) ($body['uid'] ?? ''), $file);
        $_SESSION[($r['ok'] ?? false) ? 'mywork_notice' : 'mywork_error'] = (string) $r['message'];
        return $res->withHeader('Location', '/my-work/' . $token)->withStatus(302);
    }

    /**
     * @param array<string,mixed>|null $form
     * @param list<string> $missing
     */
    private function render(Response $res, ?array $form, string $token,
                           string $notice, string $error, array $missing = []): Response
    {
        // ── WHICH QUESTIONNAIRE IS THIS PERSON ANSWERING? ────────────────────
        //
        // Decided by the STAMP on their own submission, written when they first opened the
        // link — never by the programme's current setting. An administrator switching a
        // programme mid-cycle must not change the rules under somebody halfway through, and
        // an AI outage must not convert their conversation into a form they never chose.
        //
        // `open()` is called here rather than on the first turn because the stamp has to exist
        // before anything renders, and because the greeting is written rather than generated —
        // so the first screen never waits on an API call and never fails at the worst possible
        // moment.
        if ($form !== null
            && \AfricaGates\Services\QuestionnaireInterview::open($token)['style']
               === \AfricaGates\Services\QuestionnaireStyle::INTERVIEW) {
            return $this->renderInterview($res, $form, $token, $notice, $error);
        }

        // The conversation's state is rendered WITH the page rather than fetched after it. A
        // nominee returning to a half-finished chat should see it already there — a panel that
        // arrives a second later, after a spinner, reads as a different feature that has lost
        // their place.
        $chat = $form !== null
            ? \AfricaGates\Services\QuestionnaireChat::state($token)
            : ['turns' => [], 'question' => null, 'progress' => [], 'done' => false];

        return $this->view->render($res, 'pages/my-work.twig', [
            'page_title'    => $form !== null ? 'Tell the judges about your work' : 'Your work',
            // The same minimal chrome the interview gets, for the same reason: this page is
            // reached by a token in an email, has exactly one thing to do, and every footer
            // link on it is an invitation to leave in the middle of describing your own work.
            'hide_chrome'   => true,
            'form'          => $form,
            'token'         => $token,
            'notice'        => $notice,
            'error'         => $error,
            'missing'       => $missing,
            'chat'          => $chat,
            'readiness'     => $form !== null
                ? \AfricaGates\Services\QuestionnaireChat::readiness($token)
                : ['ready' => false, 'missing' => [], 'thin' => [], 'works' => 0, 'files' => 0],
            // Whether the page renders a speaker and a microphone at all. With no ElevenLabs
            // key this is false and the questionnaire is exactly the working text conversation
            // it was before voice existed — no disabled buttons, no "unavailable" notices. A
            // nominee should never be shown the shape of a feature the operator has not bought.
            'voice'         => $form !== null && \AfricaGates\Services\QuestionnaireVoice::enabled(),
            // ── THE BRIEF AND THE SPOKEN INTRODUCTION ────────────────────────
            //
            // `intro_ready` gates the questions. Somebody who has not been told how long this
            // takes, what a usable answer looks like, or that nothing is sent until they press
            // the button is being asked to describe their life's work in the dark.
            'brief'         => \AfricaGates\Services\QuestionnaireIntro::briefParagraphs(
                                $form !== null ? (int) ($form['programme_id'] ?? 0) : 0),
            'intro_ready'   => $form === null || \AfricaGates\Services\QuestionnaireIntro::seen(
                                QuestionnaireService::byToken($token)),
            'intro'         => \AfricaGates\Services\QuestionnaireIntro::state(
                                $form !== null ? QuestionnaireService::byToken($token) : null, $token),
            'intro_max'     => \AfricaGates\Services\QuestionnaireIntro::MAX_SECONDS,
            // What a judge is looking for, per question. Resolved here rather than in the
            // template because it is derived from the criterion and a template deciding that
            // would put the rubric's logic in the layer least able to explain itself.
            'looking_for'   => $form !== null
                ? array_reduce($form['questions'], static function (array $c, array $q): array {
                    $c[(string) $q['slug']] = \AfricaGates\Services\QuestionnaireCoach::lookingFor($q);
                    return $c;
                }, [])
                : [],
            'support_email' => Notifier::supportEmail(),
        ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * The live interview, which is its own screen rather than a mode on the form.
     *
     * ── WHY A SECOND TEMPLATE AND A SECOND ALPINE SCOPE ──────────────────────
     *
     * The two styles are mutually exclusive per submission — the stamp decides, once — so they
     * never appear on one page. Sharing a scope would mean every nominee in a conversation
     * carrying the form's works list, its readiness panel and its spoken-introduction state,
     * none of which their screen has, and it would mean one template holding two layouts that
     * share nothing but a token.
     *
     * @param array<string,mixed> $form
     */
    private function renderInterview(Response $res, array $form, string $token,
                                     string $notice, string $error): Response
    {
        $state = \AfricaGates\Services\QuestionnaireInterview::state($token);
        $s     = QuestionnaireService::byToken($token);
        $pid   = ($s->programme_id ?? null) !== null ? (int) $s->programme_id : null;

        return $this->view->render($res, 'pages/my-work/interview.twig', [
            'page_title' => 'Your interview',
            // ── NO SITE CHROME ON THIS SCREEN ────────────────────────────────
            //
            // No top nav, no mobile tab bar, no footer, no floating guide. Three reasons, and
            // the third is the one that decided it:
            //
            //   • It is what the design specifies — the app surface is the whole screen.
            //   • A conversation with a pinned composer cannot share a viewport with a fixed
            //     tab bar. The composer ends up underneath it.
            //   • Every one of those twenty footer links is an invitation to leave in the
            //     middle of describing your life's work, on a deadline, to somebody who may
            //     not find their way back. This page is reached by a token in an email and
            //     has exactly one thing to do.
            'hide_chrome' => true,
            'form'       => $form,
            'token'      => $token,
            'notice'     => $notice,
            'error'      => $error,
            'iv'         => $state,
            // Same rule as the form: with no ElevenLabs key the page renders no speaker and no
            // microphone at all, rather than disabled buttons. A nominee should never be shown
            // the shape of a feature the operator has not bought.
            'voice'      => \AfricaGates\Services\QuestionnaireVoice::enabled(),
            'deadline'   => $form['deadline'] ?? '',
            'persona'    => (string) (\AfricaGates\Services\QuestionnaireStyle::config($pid)['persona'] ?? ''),
            // The composer's maxlength comes from the same constant the server truncates at,
            // so the two cannot drift into a limit the nominee cannot see.
            'say_max'    => \AfricaGates\Services\QuestionnaireInterview::MAX_SAY_CHARS,
            'support_email' => Notifier::supportEmail(),
        ])->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
