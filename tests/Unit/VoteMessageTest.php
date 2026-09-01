<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\SpamService;
use AfricaGates\Services\VoteMessageService;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * Messages of support: consent, moderation, and the share link.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THIS FEATURE CAN GET WRONG, AND WHY IT MATTERS MORE THAN USUAL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every other piece of user-submitted text on this platform lands on a page about
 * the platform. This one lands on a page about a NAMED REAL PERSON, several of whom
 * are children, most of whom did not ask to be nominated. That changes which bugs
 * are acceptable:
 *
 *   · Publishing something unjudged is not a display bug, it is a thing a nominee
 *     has to read about themselves. So the tests below prove the classifier's
 *     UNAVAILABILITY holds a message rather than releasing it.
 *
 *   · Printing a voter's name when they did not ask for it is a privacy breach, not
 *     a formatting slip — and the free ballot REQUIRES a name to vote, so the
 *     presence of a name proves nothing about consent. Two tests pin that.
 *
 *   · A share link that keeps resolving after a moderator takes the message down
 *     makes moderation decorative. One test pins that too.
 *
 * The service is also the only writer, which is what makes these assertions
 * meaningful: there is no second path that could bypass them.
 */
final class VoteMessageTest extends TestCase
{
    private const NOMINEE = 4400;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 44, 'title' => 'Prog', 'slug' => 'prog-4400']);
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 4400, 'programme_id' => 44, 'year' => 2026, 'status' => 'voting',
            // A real open window, not just the status column: BallotGuard computes the
            // phase from the dates, so a vote cast in the tests below has to be a vote
            // the platform would actually accept.
            'voting_open'  => \Illuminate\Support\Carbon::now()->subDays(3)->toDateTimeString(),
            'voting_close' => \Illuminate\Support\Carbon::now()->addDays(3)->toDateTimeString(),
        ]);
        DB::table('gates_award_categories')->insertOrIgnore(['id' => 4400, 'cycle_id' => 4400, 'title' => 'Cat', 'slug' => 'cat-4400']);
        DB::table('gates_nominees')->insertOrIgnore([
            'id' => self::NOMINEE, 'category_id' => 4400, 'name' => 'Amara Okonkwo',
            'status' => 'approved', 'vote_count' => 3,
        ]);
    }

    /** A classifier with a scripted verdict — no network, no AI, no thresholds. */
    private function spam(string $decision, float $score = 0.1, string $reason = ''): SpamService
    {
        return new class ($decision, $score, $reason) extends SpamService {
            public function __construct(
                private readonly string $d,
                private readonly float $s,
                private readonly string $r,
            ) { parent::__construct(null); }

            public function evaluate(string $text, array $context = []): array
            {
                return ['decision' => $this->d, 'score' => $this->s, 'reason' => $this->r, 'provider' => 'test'];
            }
        };
    }

    /** One that fails the way a dead provider fails: by throwing. */
    private function brokenSpam(): SpamService
    {
        return new class extends SpamService {
            public function __construct() { parent::__construct(null); }
            public function evaluate(string $text, array $context = []): array
            {
                throw new \RuntimeException('provider unreachable');
            }
        };
    }

    private function submit(array $over = [], ?SpamService $spam = null): array
    {
        return VoteMessageService::submit($over + [
            'nominee_id' => self::NOMINEE,
            'email'      => 'voter@example.com',
            'body'       => 'She taught our whole street to read. Nobody else showed up.',
        ], $spam ?? $this->spam('allow'));
    }

    // ── consent ──────────────────────────────────────────────────────────────

    /**
     * THE PRIVACY REGRESSION THIS FILE IS REALLY FOR.
     *
     * A free vote cannot be cast without a full name — the API rejects a blank one —
     * so `display_name` is populated for essentially every free message whether or
     * not the voter wanted to be identified. Any read that decides "we have a name,
     * so print it" publishes the identity of every voter on the platform.
     */
    public function test_a_name_is_not_published_just_because_it_was_collected(): void
    {
        $this->submit(['name' => 'Chidi Eze', 'show_name' => false]);

        $wall = VoteMessageService::wall(self::NOMINEE);
        $this->assertCount(1, $wall);
        $this->assertSame('A supporter', $wall[0]['name'],
            'a name required in order to vote was published as if it had been volunteered');
        $this->assertFalse($wall[0]['named']);

        // And the name is still STORED — a moderator deciding on words about a real
        // person has to be able to see who wrote them.
        $this->assertSame('Chidi Eze', DB::table('gates_vote_messages')->value('display_name'));
    }

    public function test_a_name_is_published_when_the_voter_asked_for_that(): void
    {
        $this->submit(['name' => 'Chidi Eze', 'show_name' => true]);
        $wall = VoteMessageService::wall(self::NOMINEE);
        $this->assertSame('Chidi Eze', $wall[0]['name']);
        $this->assertTrue($wall[0]['named']);
    }

    /** Consent cannot be manufactured out of a tickbox with no name behind it. */
    public function test_show_name_without_a_name_does_not_invent_an_attribution(): void
    {
        $this->submit(['name' => '', 'show_name' => true]);
        $this->assertSame('A supporter', VoteMessageService::wall(self::NOMINEE)[0]['name']);
        $this->assertSame(0, (int) DB::table('gates_vote_messages')->value('show_name'));
    }

    // ── moderation ───────────────────────────────────────────────────────────

    public function test_a_clean_message_is_published_immediately(): void
    {
        $r = $this->submit();
        $this->assertTrue($r['ok']);
        $this->assertSame('approved', $r['status']);
        $this->assertCount(1, VoteMessageService::wall(self::NOMINEE));
    }

    /**
     * THE ASYMMETRY THAT PROTECTS THE NOMINEE.
     *
     * When the classifier cannot answer — switched off, out of budget, provider down
     * — the message is HELD. The tempting alternative (publish it, review later)
     * turns every outage into unmoderated text on a real person's page, and there is
     * no undo for something a nominee has already read about themselves. A delay is
     * recoverable; that is not.
     */
    public function test_an_unavailable_classifier_holds_the_message_instead_of_publishing_it(): void
    {
        $r = $this->submit([], $this->brokenSpam());

        $this->assertTrue($r['ok'], 'the voter should not be shown a failure — the vote worked');
        $this->assertSame('pending', $r['status'],
            'a message nobody has judged was published because the classifier was down');
        $this->assertSame([], VoteMessageService::wall(self::NOMINEE));
        $this->assertCount(1, VoteMessageService::queue(),
            'the held message is invisible to the public AND missing from the queue — nobody will ever decide it');
    }

    public function test_a_refused_message_is_stored_rejected_and_never_shown(): void
    {
        $r = $this->submit([], $this->spam('reject', 0.97, 'abuse'));
        $this->assertSame('rejected', $r['status']);
        $this->assertSame([], VoteMessageService::wall(self::NOMINEE));
        // Kept, not deleted: a refusal that leaves no row cannot be appealed.
        $this->assertSame('rejected', DB::table('gates_vote_messages')->value('status'));
    }

    /**
     * Two checks run before the language model, because a nominee's page is a
     * high-value target for link spam and for harvesting contact details, and
     * neither needs a model to spot. Note the scripted classifier says `allow` —
     * these have to hold anyway.
     */
    public function test_links_and_contact_details_are_held_without_asking_a_model(): void
    {
        $this->submit(['body' => 'Great work! Buy followers at https://spam.example'], $this->spam('allow'));
        $this->assertSame('quarantined', (string) DB::table('gates_vote_messages')->value('status'));

        DB::table('gates_vote_messages')->delete();
        $this->submit(['body' => 'Amazing. Call me on +234 801 234 5678 to book her.'], $this->spam('allow'));
        $this->assertSame('quarantined', (string) DB::table('gates_vote_messages')->value('status'));
    }

    public function test_markup_is_stripped_and_length_is_capped(): void
    {
        $this->submit(['body' => '<script>alert(1)</script>Well deserved ' . str_repeat('x', 600)]);
        $body = (string) DB::table('gates_vote_messages')->value('body');

        $this->assertStringNotContainsString('<script', $body);
        $this->assertSame(VoteMessageService::MAX_LEN, mb_strlen($body));
    }

    public function test_nothing_is_stored_for_an_empty_message(): void
    {
        $r = VoteMessageService::submit(['nominee_id' => self::NOMINEE, 'email' => 'a@b.c', 'body' => '  '], $this->spam('allow'));
        $this->assertFalse($r['ok']);
        $this->assertSame('EMPTY', $r['code']);
        $this->assertSame(0, DB::table('gates_vote_messages')->count());
    }

    // ── one per person, and rewriting one ────────────────────────────────────

    public function test_a_second_message_rewrites_the_first_rather_than_stacking(): void
    {
        $a = $this->submit(['body' => 'First thought.']);
        $b = $this->submit(['body' => 'Better second thought.']);

        $this->assertSame($a['id'], $b['id']);
        $this->assertSame('REPLACED', $b['code']);
        $this->assertSame(1, DB::table('gates_vote_messages')->count());
        $this->assertSame('Better second thought.', DB::table('gates_vote_messages')->value('body'));
        // The link already posted to Facebook must keep resolving.
        $this->assertSame($a['token'], $b['token']);
    }

    /**
     * Editing text through a caller that does not resend the name must not quietly
     * strip the attribution the voter chose to put on it — losing public credit for
     * something you said is a surprising outcome for fixing a typo.
     */
    public function test_rewriting_a_message_without_resending_the_name_keeps_the_attribution(): void
    {
        $this->submit(['name' => 'Chidi Eze', 'show_name' => true]);
        $this->submit(['body' => 'Fixed a typo.']);

        $wall = VoteMessageService::wall(self::NOMINEE);
        $this->assertSame('Chidi Eze', $wall[0]['name']);
    }

    /** A rewrite is re-judged: the old verdict describes words that are gone. */
    public function test_a_rewrite_is_moderated_again_and_loses_the_previous_verdict(): void
    {
        $this->submit();
        DB::table('gates_vote_messages')->update(['moderated_by' => 7, 'moderated_at' => '2026-01-01 00:00:00']);

        $this->submit(['body' => 'Completely different words.'], $this->spam('reject', 0.9, 'abuse'));

        $row = DB::table('gates_vote_messages')->first();
        $this->assertSame('rejected', (string) $row->status);
        $this->assertNull($row->moderated_by, 'a moderator was recorded as vouching for words they never saw');
        $this->assertNull($row->moderated_at);
    }

    // ── the share permalink ──────────────────────────────────────────────────

    public function test_the_permalink_resolves_an_approved_message(): void
    {
        $r   = $this->submit();
        $msg = VoteMessageService::byToken((string) $r['token']);

        $this->assertNotNull($msg);
        $this->assertSame('Amara Okonkwo', $msg['nominee_name']);
        $this->assertSame(self::NOMINEE, $msg['nominee_id']);
    }

    /**
     * The token is minted at submission, BEFORE anybody has necessarily read the
     * words. A link posted the second it was written must stop resolving the moment
     * a moderator takes the message down — otherwise the removal only removes it
     * from the page it was already easiest to ignore.
     */
    public function test_a_share_link_stops_resolving_once_the_message_is_taken_down(): void
    {
        $r = $this->submit();
        $this->assertNotNull(VoteMessageService::byToken((string) $r['token']));

        VoteMessageService::decide((int) $r['id'], 'reject', 1);
        $this->assertNull(VoteMessageService::byToken((string) $r['token']),
            'a rejected message is still readable at its share URL');
    }

    public function test_a_withdrawn_message_leaves_the_wall_and_the_permalink(): void
    {
        $r = $this->submit();
        VoteMessageService::withdraw((int) $r['id'], 1);

        $this->assertSame([], VoteMessageService::wall(self::NOMINEE));
        $this->assertNull(VoteMessageService::byToken((string) $r['token']));
        // Soft, so the audit trail survives the takedown.
        $this->assertSame(1, DB::table('gates_vote_messages')->whereNotNull('deleted_at')->count());
    }

    /** Tokens are opaque, so a share link cannot be walked by incrementing. */
    public function test_tokens_are_unguessable_and_distinct(): void
    {
        $a = $this->submit(['email' => 'one@example.com']);
        $b = $this->submit(['email' => 'two@example.com']);

        $this->assertNotSame($a['token'], $b['token']);
        foreach ([$a['token'], $b['token']] as $t) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{22}$/', (string) $t);
        }
    }

    // ── reports ──────────────────────────────────────────────────────────────

    /**
     * THE SAFEGUARDING PATH.
     *
     * The classifier cleared it; readers who can see the nominee — often a child —
     * and the context did not. At the threshold the message comes OFF the page and
     * goes in front of a person. The tie goes to taking it down and looking again.
     */
    public function test_enough_reader_reports_pull_an_approved_message_off_the_page(): void
    {
        $r = $this->submit();
        $token = (string) $r['token'];

        for ($i = 1; $i < VoteMessageService::REPORT_THRESHOLD; $i++) {
            VoteMessageService::report($token);
            $this->assertCount(1, VoteMessageService::wall(self::NOMINEE),
                'the message came down before the threshold was reached');
        }

        VoteMessageService::report($token);

        $this->assertSame([], VoteMessageService::wall(self::NOMINEE));
        $this->assertNull(VoteMessageService::byToken($token), 'the share link still resolves after a takedown');
        $this->assertCount(1, VoteMessageService::queue(), 'the reported message is not in front of a moderator');
    }

    /** Quarantined, never deleted — a moderator has to be able to put it straight back. */
    public function test_a_reported_message_is_held_rather_than_destroyed(): void
    {
        $r = $this->submit();
        for ($i = 0; $i < VoteMessageService::REPORT_THRESHOLD; $i++) {
            VoteMessageService::report((string) $r['token']);
        }

        $row = DB::table('gates_vote_messages')->first();
        $this->assertSame('quarantined', (string) $row->status);
        $this->assertNull($row->deleted_at, 'a reported message was destroyed instead of held');
        $this->assertSame(VoteMessageService::REPORT_THRESHOLD, (int) $row->reports);
        $this->assertStringContainsString('report', (string) $row->mod_reason);

        // And the moderator can reverse it.
        VoteMessageService::decide((int) $r['id'], 'approve', 1);
        $this->assertCount(1, VoteMessageService::wall(self::NOMINEE));
    }

    /**
     * The previous verdict does not survive a takedown. It was a decision about
     * whether the text was publishable, and it has just been contradicted by people
     * who can see who the text is about.
     */
    public function test_a_takedown_clears_the_moderator_who_had_approved_it(): void
    {
        $r = $this->submit();
        VoteMessageService::decide((int) $r['id'], 'approve', 42);

        for ($i = 0; $i < VoteMessageService::REPORT_THRESHOLD; $i++) {
            VoteMessageService::report((string) $r['token']);
        }

        $row = DB::table('gates_vote_messages')->first();
        $this->assertNull($row->moderated_by);
        $this->assertNull($row->moderated_at);
    }

    /** Reported messages sort to the top: they are the ones with a person waiting. */
    public function test_the_queue_puts_reported_messages_first(): void
    {
        $old = $this->submit(['email' => 'old@example.com', 'body' => 'An older borderline one.'],
            $this->spam('quarantine', 0.6, 'borderline'));
        $new = $this->submit(['email' => 'new@example.com', 'body' => 'A newer one that readers flagged.']);
        DB::table('gates_vote_messages')->where('id', $old['id'])->update(['created_at' => '2026-01-01 00:00:00']);

        for ($i = 0; $i < VoteMessageService::REPORT_THRESHOLD; $i++) {
            VoteMessageService::report((string) $new['token']);
        }

        $queue = VoteMessageService::queue();
        $this->assertSame((int) $new['id'], (int) $queue[0]['id'],
            'the reported message is buried behind whatever the classifier held that morning');
    }

    /**
     * Anyone may report — no account. The reader who most needs this is a stranger who
     * followed a WhatsApp link and saw something about a named child; requiring
     * registration would not protect the nominee, it would mean the report never
     * arrives.
     *
     * The reply is identical whether the report landed or was refused. A reporter told
     * "you already reported this" has learned that the first one counted; one told
     * "over the limit" has learned where the ceiling is.
     */
    public function test_the_report_endpoint_is_open_and_indistinguishable_when_refused(): void
    {
        $r = $this->submit();

        $first = $this->jsonPost('/api/vote-message/report', ['token' => $r['token']]);
        $again = $this->jsonPost('/api/vote-message/report', ['token' => $r['token']]);

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(200, $again->getStatusCode());
        $this->assertSame((string) $first->getBody(), (string) $again->getBody(),
            'the second report from the same network is distinguishable from the first');

        // And it only counted once, so a single reader cannot reach the threshold alone.
        $this->assertSame(1, (int) DB::table('gates_vote_messages')->value('reports'));
    }

    // ── cheers ───────────────────────────────────────────────────────────────

    public function test_cheering_counts_up_and_only_for_a_live_message(): void
    {
        $r = $this->submit();
        $this->assertSame(1, VoteMessageService::cheer((string) $r['token']));
        $this->assertSame(2, VoteMessageService::cheer((string) $r['token']));
        $this->assertSame(2, VoteMessageService::cheerCount((string) $r['token']));

        VoteMessageService::decide((int) $r['id'], 'reject', 1);
        $this->assertNull(VoteMessageService::cheer((string) $r['token']));
        $this->assertNull(VoteMessageService::cheer('not-a-real-token'));
    }

    // ── the routes ───────────────────────────────────────────────────────────

    /**
     * The app with the middleware that actually decides whether these endpoints work.
     *
     * Body parsing, because a JSON POST with no parser reaches the controller with a
     * null body and every assertion below would pass for the wrong reason. CSRF,
     * because `/api/` writes are same-origin-guarded and an endpoint that the real
     * stack refuses is not a working endpoint — the requests here send the same
     * `X-Requested-With` the page's fetch() does, which is what satisfies it. Error
     * middleware, so a 404 arrives as a 404 rather than an uncaught exception.
     *
     * Same order as public/index.php: Slim runs middleware LIFO, so body parsing
     * (added last) runs before CSRF, which is what lets CSRF read `_token`.
     */
    private function app(): \Slim\App
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);
        $app->addRoutingMiddleware();
        $app->add(new \AfricaGates\Middleware\CsrfMiddleware());
        $app->addBodyParsingMiddleware();
        $app->addErrorMiddleware(false, false, false);
        return $app;
    }

    /** A JSON POST shaped exactly like the one the page's fetch() sends. */
    private function jsonPost(string $path, array $payload): \Psr\Http\Message\ResponseInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', $path)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Requested-With', 'XMLHttpRequest');
        $req->getBody()->write((string) json_encode($payload));
        $req->getBody()->rewind();
        return $this->app()->handle($req);
    }

    /**
     * ── THE FREE PATH, END TO END ────────────────────────────────────────────
     *
     * The message rides along with the vote in ONE request, and this is the test
     * that the wiring exists at all: `/api/vote` has to accept `message` and
     * `message_show_name`, store them, and report back what happened.
     *
     * It also pins the two properties that make the design defensible. First, the
     * message CANNOT break the vote — asserted by the harder case below, where a
     * message is refused and the vote still stands. Second, the reply tells the
     * voter what became of their words, because a message that silently fails to
     * appear reads as a message that was thrown away.
     */
    private function castVoteWith(array $extra): array
    {
        $email = 'freevoter@example.com';
        $code  = '424242';
        DB::table('gates_otp_tokens')->insert([
            'email_hash' => hash('sha256', $email), 'token_hash' => hash('sha256', $code),
            'purpose' => 'vote', 'nominee_id' => self::NOMINEE, 'award_id' => 4400,
            'attempts' => 0, 'is_used' => 0,
            'expires_at' => \Illuminate\Support\Carbon::now()->addMinutes(10)->toDateTimeString(),
            'created_at' => \Illuminate\Support\Carbon::now()->toDateTimeString(),
        ]);

        $res = $this->jsonPost('/api/vote', $extra + [
            'email' => $email, 'otp' => $code,
            'nominee_id' => self::NOMINEE, 'award_id' => 4400,
            'name' => 'Ngozi Balogun', 'phone' => '+2348012345678',
        ]);

        return (array) json_decode((string) $res->getBody(), true);
    }

    public function test_a_free_voter_can_send_a_message_with_their_vote(): void
    {
        $d = $this->castVoteWith(['message' => 'She reads to the little ones every Saturday.']);

        $this->assertTrue($d['success'] ?? false, 'the vote itself failed: ' . ($d['message'] ?? ''));
        $this->assertSame('approved', $d['message_status'] ?? null);
        $this->assertNotEmpty($d['message_note'] ?? '');
        $this->assertStringContainsString('/m/', (string) ($d['message_url'] ?? ''));

        $wall = VoteMessageService::wall(self::NOMINEE);
        $this->assertCount(1, $wall);
        $this->assertSame('She reads to the little ones every Saturday.', $wall[0]['body']);
        // No consent was given, and the free ballot's required name must not stand in
        // for one — see the first test in this file.
        $this->assertSame('A supporter', $wall[0]['name']);
    }

    public function test_a_vote_with_no_message_writes_no_row_at_all(): void
    {
        $d = $this->castVoteWith(['message' => '   ']);

        $this->assertTrue($d['success'] ?? false);
        $this->assertArrayNotHasKey('message_status', $d,
            'an empty message produced a moderation outcome the voter never asked for');
        $this->assertSame(0, DB::table('gates_vote_messages')->count());
    }

    /**
     * THE PROPERTY THAT MATTERS MOST. A message held or refused must leave the vote
     * untouched — the vote is the thing this platform exists to record, and a
     * sentence about it is not worth risking one for.
     */
    public function test_a_held_message_does_not_cost_the_voter_their_vote(): void
    {
        $d = $this->castVoteWith(['message' => 'Vote here too: https://spam.example']);

        $this->assertTrue($d['success'] ?? false, 'a quarantined message took the vote down with it');
        $this->assertSame(4, (int) DB::table('gates_nominees')->where('id', self::NOMINEE)->value('vote_count'));
        $this->assertSame(1, DB::table('gates_votes')->count());
        // Held, not published, and the voter is told so rather than left guessing.
        $this->assertSame('quarantined', $d['message_status']);
        $this->assertStringContainsString('moderator', $d['message_note']);
        $this->assertSame('', $d['message_url']);
        $this->assertSame([], VoteMessageService::wall(self::NOMINEE));
    }

    public function test_the_permalink_page_puts_the_message_in_its_own_social_card(): void
    {
        $r = $this->submit(['body' => 'She taught our whole street to read.']);

        $req  = (new ServerRequestFactory())->createServerRequest('GET', '/m/' . $r['token']);
        $html = (string) $this->app()->handle($req)->getBody();

        // THE WHOLE POINT OF THE PAGE. Shared as a link to the ballot, fifty
        // supporters' fifty different sentences all preview as the same "Vote for X"
        // card. Here the words are in the og:title, so the card carries them.
        $this->assertStringContainsString('She taught our whole street to read', $html);
        $this->assertMatchesRegularExpression(
            '/property="og:title" content="[^"]*She taught our whole street/',
            $html,
            'the message is on the page but not in the preview card, which is the only reason the page exists'
        );
        // And it still has to lead somewhere: a shared message that cannot be acted
        // on is a dead end.
        $this->assertStringContainsString('/vote/prog-4400/', $html);
    }

    /**
     * THE CARD IS THE SHARE.
     *
     * Facebook's preview is mostly image. Before this, the image was the nominee's
     * ballot card — so fifty supporters posting fifty different sentences produced
     * fifty identical "VOTE NOW" thumbnails and the words were lost. The og:image now
     * points at the message's own graphic.
     */
    public function test_the_permalink_advertises_the_messages_own_card_not_the_ballots(): void
    {
        $r    = $this->submit();
        $html = (string) $this->app()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/m/' . $r['token'])
        )->getBody();

        $this->assertMatchesRegularExpression(
            '#property="og:image" content="[^"]*/m/' . preg_quote((string) $r['token'], '#') . '/card\.png"#',
            $html,
            'a shared message previews with the ballot card, so the words never appear'
        );
    }

    /**
     * A message permalink is share bait, not search bait: one short quote surrounded by
     * boilerplate, one per message. A few hundred of those is thin content that dilutes
     * the pages which should rank, so it is noindex — and `follow`, because the links
     * out of it are worth crawling. Social crawlers read og: tags and ignore robots
     * meta, so the share is unaffected. The full wall stays indexable.
     */
    public function test_a_message_permalink_is_noindex_but_the_full_wall_is_not(): void
    {
        $r = $this->submit();
        $app = $this->app();

        $one = (string) $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/m/' . $r['token'])
        )->getBody();
        $all = (string) $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/vote/prog-4400/' . self::NOMINEE . '-amara-okonkwo/messages')
        )->getBody();

        $this->assertMatchesRegularExpression('/name="robots" content="noindex, follow/', $one);
        $this->assertMatchesRegularExpression('/name="robots" content="index, follow/', $all);
        // The og: tags are what a share preview reads, and they are untouched by any of
        // this — asserted here because "noindex" and "unshareable" are one careless
        // change apart.
        $this->assertStringContainsString('property="og:image"', $one);
    }

    /**
     * And the card renders. GD and the bundled fonts are what it needs; where they are
     * missing it must REDIRECT to the nominee's card rather than 404, because a missing
     * og:image is a blank preview and that is worse than a generic one.
     */
    public function test_the_message_card_is_a_png_or_a_redirect_never_an_error(): void
    {
        $r   = $this->submit();
        $res = $this->app()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/m/' . $r['token'] . '/card.png')
        );

        if ($res->getStatusCode() === 200) {
            $this->assertSame('image/png', $res->getHeaderLine('Content-Type'));
            // The PNG magic number, so this is a real raster and not an error page
            // served with the wrong header.
            $this->assertSame("\x89PNG", substr((string) $res->getBody(), 0, 4));
            $this->assertStringContainsString('public', $res->getHeaderLine('Cache-Control'));
        } else {
            $this->assertSame(302, $res->getStatusCode(), 'no GD/fonts should redirect, not fail');
            $this->assertStringContainsString('/card.png', $res->getHeaderLine('Location'));
        }
    }

    /** A card for a message a moderator took down must not keep rendering. */
    public function test_the_card_disappears_with_the_message(): void
    {
        $r = $this->submit();
        VoteMessageService::decide((int) $r['id'], 'reject', 1);

        $status = $this->app()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/m/' . $r['token'] . '/card.png')
        )->getStatusCode();

        $this->assertSame(404, $status);
    }

    /**
     * The full wall is a PAGE, not a "load more" button — so it has a URL a nominee can
     * send to their family, it is visible to crawlers and to readers without
     * JavaScript, and the item markup has exactly one renderer.
     */
    public function test_a_nominee_has_a_page_of_all_their_messages(): void
    {
        $this->submit(['email' => 'a@example.com', 'body' => 'She never once asked anybody for a naira.']);
        $this->submit(['email' => 'b@example.com', 'body' => 'Twelve people and no instruments.']);

        $html = (string) $this->app()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/vote/prog-4400/' . self::NOMINEE . '-amara-okonkwo/messages')
        )->getBody();

        $this->assertStringContainsString('She never once asked anybody for a naira.', $html);
        $this->assertStringContainsString('Twelve people and no instruments.', $html);
        $this->assertStringContainsString('Messages for Amara Okonkwo', $html);
        // The report control has to be on THIS page too — it comes from the shared
        // partial, which is the whole reason the partial exists.
        $this->assertStringContainsString('vmItem(', $html);
    }

    /** Held and rejected messages are not on it, not counted, and not hinted at. */
    public function test_the_messages_page_shows_only_what_was_approved(): void
    {
        $this->submit(['email' => 'ok@example.com', 'body' => 'A clean and public message.']);
        $this->submit(['email' => 'held@example.com', 'body' => 'Something borderline.'],
            $this->spam('quarantine', 0.6, 'borderline'));

        $html = (string) $this->app()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/vote/prog-4400/' . self::NOMINEE . '-amara-okonkwo/messages')
        )->getBody();

        $this->assertStringContainsString('A clean and public message.', $html);
        $this->assertStringNotContainsString('Something borderline.', $html);
        $this->assertStringContainsString('1 message from verified voters', $html);
    }

    public function test_an_unknown_or_rejected_token_is_a_404_not_a_blank_page(): void
    {
        $r = $this->submit();
        VoteMessageService::decide((int) $r['id'], 'reject', 1);

        $app = $this->app();
        foreach (['/m/' . $r['token'], '/m/AAAAAAAAAAAAAAAAAAAAAA'] as $path) {
            $status = $app->handle((new ServerRequestFactory())->createServerRequest('GET', $path))->getStatusCode();
            $this->assertSame(404, $status, $path . ' should not resolve');
        }
    }

    /**
     * The paid path is authorised by a CONFIRMED payment reference and nothing else.
     * Without that rule the endpoint is an open door: start a checkout, abandon it,
     * and the words are already on a real person's page.
     */
    public function test_the_paid_endpoint_refuses_a_reference_that_was_never_paid(): void
    {
        DB::table('gates_donations')->insert([
            'donor_name' => 'Ada', 'donor_email' => 'ada@example.com', 'amount_naira' => 500,
            'tier' => 'paid-vote', 'bonus_votes' => 5, 'votes_used' => 0,
            'intent_nominee_id' => self::NOMINEE, 'payment_ref' => 'AFG-PVOTE-UNPAID',
            'status' => 'pending', 'created_at' => '2026-08-01 10:00:00',
        ]);

        $res = $this->jsonPost('/api/vote-message', ['ref' => 'AFG-PVOTE-UNPAID', 'body' => 'Sneaking this in.']);

        $this->assertSame(403, $res->getStatusCode());
        $this->assertSame(0, DB::table('gates_vote_messages')->count(),
            'an abandoned checkout wrote a message onto a nominee page');
    }

    /**
     * And with a confirmed one it works — including the part that matters for
     * privacy: the identity comes from the ORDER, not from the request body, so a
     * leaked reference cannot be used to write under somebody else's name.
     */
    public function test_a_confirmed_payment_may_post_and_the_identity_comes_from_the_order(): void
    {
        DB::table('gates_donations')->insert([
            'donor_name' => 'Ada Nwosu', 'donor_email' => 'ada@example.com', 'amount_naira' => 500,
            'tier' => 'paid-vote', 'bonus_votes' => 5, 'votes_used' => 5,
            'intent_nominee_id' => self::NOMINEE, 'payment_ref' => 'AFG-PVOTE-PAID',
            'status' => 'confirmed', 'show_name' => 1, 'created_at' => '2026-08-01 10:00:00',
        ]);

        $res = $this->jsonPost('/api/vote-message', [
            'ref' => 'AFG-PVOTE-PAID', 'body' => 'Worth every naira.',
            // Ignored, both of them. This is the attack the test is for.
            'email' => 'someone-else@example.com', 'name' => 'Not Ada',
        ]);
        $body = json_decode((string) $res->getBody(), true);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertTrue($body['success']);

        $row = DB::table('gates_vote_messages')->first();
        $this->assertSame('paid', (string) $row->source);
        $this->assertSame('Ada Nwosu', (string) $row->display_name);
        $this->assertSame(
            \AfricaGates\Services\VoteService::voterHash('ada@example.com'),
            (string) $row->voter_email_hash,
            'the message was attributed to whoever the request claimed to be'
        );
    }
}
