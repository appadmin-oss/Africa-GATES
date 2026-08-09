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

        DB::table('gates_award_programmes')->insertOrIgnore(['id' => 4400, 'title' => 'Prog', 'slug' => 'prog-4400']);
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 4400, 'programme_id' => 4400, 'year' => 2026, 'status' => 'voting',
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
