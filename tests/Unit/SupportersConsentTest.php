<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\PaidVoteService;
use AfricaGates\Services\SupportersService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * The public supporters list publishes CONSENT, not names.
 *
 * On the paid ballot the name field is optional and says what filling it in does, so
 * typing a name IS the consent. That cannot be evaluated at read time, though: rows
 * already in the table were filled in under a label that named no audience. Hence the
 * `show_name` column and its 0 default.
 *
 * These tests exist because the failure here is one-way. A pricing bug is a refund;
 * publishing the name of someone who paid before the ballot said what the field was
 * for cannot be taken back once it has been indexed. So the cases below are mostly
 * about the DEFAULT — what happens when nobody said yes — rather than the happy path.
 */
final class SupportersConsentTest extends TestCase
{
    private function seedOpenCycle(): void
    {
        DB::table('gates_award_cycles')->insertOrIgnore([
            'id' => 1, 'programme_id' => 0, 'year' => (int) date('Y'), 'status' => 'voting',
            'voting_open'  => date('Y-m-d H:i:s', strtotime('-1 day')),
            'voting_close' => date('Y-m-d H:i:s', strtotime('+7 days')),
        ]);
        DB::table('gates_award_categories')->insertOrIgnore([
            'id' => 2, 'cycle_id' => 1, 'slug' => 'cat-2', 'title' => 'Category',
        ]);
    }

    /** @param array<string,mixed> $over */
    private function order(array $over = []): int
    {
        return (int) DB::table('gates_donations')->insertGetId(array_merge([
            'donor_name' => 'Amara Okonkwo', 'donor_email' => 'a@x.io',
            'amount_naira' => 1000, 'tier' => 'paid-vote',
            'bonus_votes' => 10, 'votes_used' => 0, 'intent_nominee_id' => 5,
            'payment_ref' => 'AFG-PVOTE-' . bin2hex(random_bytes(4)), 'status' => 'confirmed',
            'created_at' => date('Y-m-d H:i:s'),
        ], $over));
    }

    /**
     * Env::get reads live from $_ENV/$_SERVER, so the gateway key `checkout()` plants
     * would otherwise outlive this class and make "no provider configured" false for
     * every later test in the same process.
     */
    protected function tearDown(): void
    {
        unset($_ENV['PAYSTACK_SECRET_KEY'], $_SERVER['PAYSTACK_SECRET_KEY']);
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOpenCycle();
        DB::table('gates_nominees')->insert(['id' => 5, 'name' => 'Ada Obi', 'category_id' => 2, 'status' => 'approved', 'vote_count' => 0, 'organic_vote_count' => 0]);
    }

    /**
     * THE ONE THAT MATTERS. A row written the way every historical order was written —
     * a real donor_name and no show_name key at all — must not surface a name. The
     * column default is what enforces it, so this test is really asserting the schema,
     * and it is the reason consent is not derived from `donor_name != ''`.
     */
    public function test_an_order_that_never_answered_the_question_stays_private(): void
    {
        $id = $this->order(); // a named order, exactly like a pre-feature row
        $this->assertSame(0, (int) DB::table('gates_donations')->where('id', $id)->value('show_name'),
            'the column must default to private, not to null-ish/1');

        PaidVoteService::mint($id);

        $this->assertSame([], SupportersService::forNominee(5));
        $this->assertSame(0, SupportersService::countForNominee(5));
        $this->assertSame(0, (int) DB::table('gates_votes')->where('donation_id', $id)->value('show_name'),
            'consent must not be invented at mint time');
    }

    public function test_a_named_order_publishes_the_name_and_the_weight(): void
    {
        PaidVoteService::mint($this->order(['show_name' => 1]));

        $list = SupportersService::forNominee(5);
        $this->assertCount(1, $list);
        $this->assertSame('Amara Okonkwo', $list[0]['name']);
        $this->assertSame(10, $list[0]['votes']);
        $this->assertTrue($list[0]['paid']);
        $this->assertSame(1, SupportersService::countForNominee(5));
    }

    /** Consent is per-order. One buyer opting in must not drag the others onto the list. */
    public function test_consent_does_not_leak_between_orders(): void
    {
        PaidVoteService::mint($this->order(['show_name' => 1, 'donor_name' => 'Named Buyer']));
        PaidVoteService::mint($this->order(['show_name' => 0, 'donor_name' => 'Private Buyer']));

        $names = array_column(SupportersService::forNominee(5), 'name');
        $this->assertSame(['Named Buyer'], $names);
    }

    /**
     * 'Supporter' is PaidVoteController::start()'s placeholder for an EMPTY name field —
     * the anonymous case. `show_name` is not set for a blank field, so this pairing
     * should never occur; the reader drops it anyway, because a list row that names
     * nobody is worse than no row, and inflating the count with it is worse still.
     */
    public function test_the_blank_name_placeholder_is_not_published(): void
    {
        PaidVoteService::mint($this->order(['show_name' => 1, 'donor_name' => 'Supporter']));

        $this->assertSame([], SupportersService::forNominee(5));
    }

    /**
     * Free OTP votes are never listed. The name is REQUIRED on that path, so supplying
     * one is not a choice to be published, and nothing sets the flag — but the column
     * lives on this table too, so the reader would honour it if a future opt-in did.
     */
    public function test_the_free_path_shares_the_flag_and_the_default(): void
    {
        DB::table('gates_votes')->insert([
            'nominee_id' => 5, 'category_id' => 2, 'voter_email_hash' => 'h1',
            'voter_name' => 'Quiet Voter', 'vote_type' => 'standard', 'weight' => 1,
            'voted_at' => date('Y-m-d H:i:s'),
        ]);
        DB::table('gates_votes')->insert([
            'nominee_id' => 5, 'category_id' => 2, 'voter_email_hash' => 'h2',
            'voter_name' => 'Loud Voter', 'show_name' => 1, 'vote_type' => 'standard', 'weight' => 1,
            'voted_at' => date('Y-m-d H:i:s'),
        ]);

        $list = SupportersService::forNominee(5);
        $this->assertSame(['Loud Voter'], array_column($list, 'name'));
        $this->assertFalse($list[0]['paid'], 'an organic vote is not a paid one');
    }

    /** Another nominee's consenting supporters are not this nominee's. */
    public function test_the_list_is_scoped_to_the_nominee(): void
    {
        DB::table('gates_nominees')->insert(['id' => 6, 'name' => 'Other', 'category_id' => 2, 'status' => 'approved', 'vote_count' => 0, 'organic_vote_count' => 0]);
        DB::table('gates_votes')->insert([
            'nominee_id' => 6, 'category_id' => 2, 'voter_email_hash' => 'h3',
            'voter_name' => 'Backs Someone Else', 'show_name' => 1, 'vote_type' => 'standard', 'weight' => 1,
            'voted_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame([], SupportersService::forNominee(5));
        $this->assertCount(1, SupportersService::forNominee(6));
    }

    /**
     * The list is truncated; the count is not. "and N more" would be wrong — and
     * would understate the support — if it were derived from the truncated list.
     */
    public function test_the_count_is_not_the_length_of_the_truncated_list(): void
    {
        for ($i = 0; $i < 5; $i++) {
            DB::table('gates_votes')->insert([
                'nominee_id' => 5, 'category_id' => 2, 'voter_email_hash' => 'h' . $i,
                'voter_name' => 'Voter ' . $i, 'show_name' => 1, 'vote_type' => 'standard', 'weight' => 1,
                'voted_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $this->assertCount(2, SupportersService::forNominee(5, 2));
        $this->assertSame(5, SupportersService::countForNominee(5));
    }

    /** A nonsense id is a quiet empty list, not a query and not an error. */
    public function test_an_invalid_nominee_id_is_empty_not_fatal(): void
    {
        $this->assertSame([], SupportersService::forNominee(0));
        $this->assertSame(0, SupportersService::countForNominee(-1));
    }

    // ── Where the consent is actually decided ────────────────────────────────
    //
    // The two below drive the real checkout, because the rule ("the field IS the
    // consent") lives in the controller and everything above only tests what the
    // reader does with the flag afterwards. The gateway call fails in the harness —
    // no keys — but the PENDING row is written first, which is the row that carries
    // the answer through the round-trip.

    /** @param array<string,mixed> $post */
    private function checkout(array $post): void
    {
        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $ctrl = $builder->build()->get(\AfricaGates\Controllers\PaidVoteController::class);

        DB::table('gates_settings')->updateOrInsert(['key_name' => 'paid_voting_enabled'], ['value' => '1']);
        // A provider has to look configured or `start()` bails at the availability
        // check, several guards before the row this test is about. The key is never
        // used: the outbound call fails and the order is left pending, which is
        // exactly the state being asserted.
        $_ENV['PAYSTACK_SECRET_KEY'] = $_SERVER['PAYSTACK_SECRET_KEY'] = 'sk_test_consent_fixture';

        $req = (new \Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('POST', '/vote/paid/start')
            ->withParsedBody($post + ['nominee_id' => 5, 'qty' => 3, 'email' => 'buyer@x.io', 'provider' => 'paystack']);

        $ctrl->start($req, new \Slim\Psr7\Response());
    }

    public function test_typing_a_name_at_checkout_is_the_consent(): void
    {
        $this->checkout(['name' => 'Amara Okonkwo']);

        $row = DB::table('gates_donations')->where('tier', 'paid-vote')->orderByDesc('id')->first();
        $this->assertNotNull($row, 'the pending order is written before the gateway is called');
        $this->assertSame('Amara Okonkwo', (string) $row->donor_name);
        $this->assertSame(1, (int) $row->show_name, 'no separate tickbox — the field is the choice');
    }

    /** Leaving the optional field blank is how you give anonymously. */
    public function test_leaving_the_name_blank_is_an_anonymous_order(): void
    {
        $this->checkout(['name' => '   ']);

        $row = DB::table('gates_donations')->where('tier', 'paid-vote')->orderByDesc('id')->first();
        $this->assertSame('Supporter', (string) $row->donor_name, 'the placeholder, not a name');
        $this->assertSame(0, (int) $row->show_name);
    }
}
