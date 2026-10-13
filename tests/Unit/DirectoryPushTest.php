<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\DirectoryPush;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Telling CACENTRE's person directory who holds an account here.
 *
 * Three things are being protected, and each is a thing this platform has
 * paid for once already somewhere else.
 *
 * ONE: no credential leaves. Four tables here carry `password_hash`, one
 * carries a passkey, and the agreed payload carries none of it. The field
 * list is asserted as a SET, so a column added to a shaper fails here rather
 * than on another organisation's disk.
 *
 * TWO: the sandbox does not reach the estate's record of real humans.
 * `DemoSeeder` mints a judge at `demo.invalid` and `purge()` deletes it; a
 * pushed fixture becomes a person over there and then an orphan. This is the
 * same containment rule as every public reader, asked as the RULE —
 * a reserved domain — and never as the seeder's current address.
 *
 * THREE: the wire format agrees, to the byte, with three implementations in
 * three other repositories that nothing links this one to.
 */
final class DirectoryPushTest extends TestCase
{
    private const KEY = 'gates-directory-test-key-0123456789';

    /** @return array<string,mixed> */
    private function vectors(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../Support/directory-vectors.json'), true);
    }

    /**
     * Real accounts, at a domain that is really a domain.
     *
     * NOT `@example.org`: SendPolicy reserves example.com/.net/.org along
     * with the RFC 2606 TLDs, so a fixture written there is filtered by the
     * sandbox rule under test and every assertion about it reads as the
     * sender dropping real people. Found exactly that way.
     */
    private function seedAccounts(): void
    {
        DB::table('gates_users')->insert([
            ['id' => 901, 'name' => 'Ada Nwosu', 'email' => 'Ada@Gates.NG', 'password_hash' => 'SECRETHASH',
             'status' => 'active', 'email_verified' => 1, 'last_login_at' => '2026-10-01 09:15:00'],
            ['id' => 902, 'name' => 'Bode Ola', 'email' => 'bode@gates.ng', 'password_hash' => 'SECRETHASH',
             'status' => 'suspended', 'email_verified' => 0, 'last_login_at' => null],
        ]);
        DB::table('gates_admins')->insert([
            ['id' => 901, 'email' => 'editor@gates.ng', 'name' => 'An Editor',
             'password_hash' => 'SECRETHASH', 'role' => 'editor', 'is_active' => 1],
        ]);
    }

    public function test_the_wire_format_matches_the_shared_vectors(): void
    {
        /*
         * THE ONLY THING HOLDING FOUR REPOSITORIES TO ONE FORMAT. An
         * identical copy of this file is asserted in cacentre-site (both the
         * verifier and CFIS) and in afrovanguard-site. No package is shared
         * between any of them; if a sender's encoding drifts, that sender's
         * own suite fails rather than a push being refused in production
         * with nothing on either side to say why.
         */
        $v = $this->vectors();
        $body = rtrim(strtr(base64_encode((string) $v['sign']['payload']), '+/', '-_'), '=');
        $mac = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, (string) $v['secret'], true)), '+/', '-_'), '=');

        $this->assertSame($v['sign']['token'], 'v1.' . $body . '.' . $mac,
            'a known payload and key must produce the known token');
    }

    public function test_the_claims_are_assembled_in_the_order_the_format_fixes(): void
    {
        /* A MAC is over serialised bytes, so key ORDER is part of the format
           whether anybody intended it to be or not. Two senders that
           assemble the same claims differently produce different signatures
           and neither is wrong — the failure no single side can find. */
        $token = DirectoryPush::mint([['store' => 'gates_users', 'local_id' => '1']], self::KEY, 1760000000);
        $claims = json_decode((string) base64_decode(strtr(explode('.', $token)[1], '-_', '+/') . '=='), true);

        $this->assertSame(['rows', 'iss', 'typ', 'nonce', 'iat', 'exp'], array_keys($claims));
        $this->assertSame('gates', $claims['iss']);
        $this->assertSame('dir.push', $claims['typ'], 'a roster push is not an SSO assertion');
        $this->assertSame(1760000300, $claims['exp'], 'and the window is the one the receiver allows');
    }

    public function test_no_credential_leaves_this_platform(): void
    {
        $this->seedAccounts();

        $allowed = ['store', 'local_id', 'email', 'email_verified', 'name', 'local_role', 'local_status', 'last_seen_at'];
        $rows = DirectoryPush::memberBatch(900)['rows'];
        $rows = array_merge($rows, DirectoryPush::staffBatches()[0] ?? []);

        $this->assertNotSame([], $rows);
        foreach ($rows as $r) {
            $got = array_keys($r);
            sort($got);
            $expected = $allowed;
            sort($expected);
            $this->assertSame($expected, $got, 'exactly the agreed fields and no others');
        }

        $wire = (string) json_encode($rows);
        $this->assertStringNotContainsString('SECRETHASH', $wire, 'NO password hash crosses the wire');
        $this->assertStringNotContainsString('last_login_ip', $wire, 'nor an IP address');
        $this->assertStringNotContainsString('points', $wire, 'nor a member\'s point balance');
        $this->assertStringNotContainsString('phone', $wire, 'nor a phone number');
    }

    public function test_only_the_members_table_knows_about_verification(): void
    {
        /*
         * Three states, and the third is a different fact from the second.
         * `gates_users.email_verified` exists, so that store answers true or
         * false. The admin, judge and partner tables have no such column, so
         * they answer null — sending false would mark every judge and every
         * console admin "unverified" on a screen that is not about them.
         */
        $this->seedAccounts();

        $members = DirectoryPush::memberBatch(900)['rows'];
        $this->assertTrue($members[0]['email_verified'], 'a verified member');
        $this->assertFalse($members[1]['email_verified'], 'an unverified one — false, not null');

        $this->assertNull(DirectoryPush::fromAdmin(['id' => 1, 'email' => 'a@gates.ng'])['email_verified']);
        $this->assertNull(DirectoryPush::fromJudge(['id' => 1, 'email' => 'a@gates.ng'])['email_verified']);
        $this->assertNull(DirectoryPush::fromOrgUser(['id' => 1, 'email' => 'a@gates.ng'])['email_verified']);
    }

    public function test_the_sandbox_is_not_pushed_into_the_estate_s_record_of_real_people(): void
    {
        /*
         * `DemoSeeder` mints its judge at `demo.invalid` precisely so it can
         * never be mailed, and `purge()` deletes the row afterwards. Pushed,
         * it becomes a person in the estate directory and then an orphan
         * identity pointing at a row that no longer exists.
         *
         * Asserted through the RULE the platform already has for this —
         * SendPolicy's reserved domains — and not by naming this address.
         * A list of the cases somebody already found is never a fix for the
         * next one.
         */
        DB::table('gates_judges')->insert([
            ['id' => 901, 'name' => 'Sandbox Judge', 'email' => 'judge@demo.invalid', 'is_active' => 1],
            ['id' => 902, 'name' => 'A Real Judge', 'email' => 'real@judges.ng', 'is_active' => 1],
        ]);

        $sent = [];
        foreach (DirectoryPush::staffBatches() as $chunk) {
            foreach ($chunk as $r) {
                $sent[] = $r['email'];
            }
        }

        $this->assertNotContains('judge@demo.invalid', $sent, 'the sandbox judge is not a person');
        $this->assertContains('real@judges.ng', $sent, 'and a real one still goes');

        /* Any reserved domain, not that one address. */
        DB::table('gates_judges')->insert([
            ['id' => 903, 'name' => 'Another Fixture', 'email' => 'someone@fixtures.test', 'is_active' => 1],
        ]);
        $sent = [];
        foreach (DirectoryPush::staffBatches() as $chunk) {
            foreach ($chunk as $r) {
                $sent[] = $r['email'];
            }
        }
        $this->assertNotContains('someone@fixtures.test', $sent, 'nor any other reserved domain');
    }

    public function test_a_page_shortened_only_by_the_sandbox_filter_is_not_the_end_of_the_table(): void
    {
        /*
         * THE ONE THAT WOULD HAVE BEEN SILENT. The page size has to be
         * measured BEFORE the reserved-domain rows are dropped. Measured
         * after, a page that is short only because it contained a fixture
         * reads as the end of the table: the cursor wraps to zero, and every
         * member past that point is never pushed again — no error anywhere,
         * the sweep reporting success on a loop over the first few rows.
         */
        for ($i = 0; $i < 6; $i++) {
            DB::table('gates_users')->insert([[
                'id' => 910 + $i,
                'name' => 'M' . $i,
                /* Every other one is a fixture. */
                'email' => ($i % 2 === 0 ? "m$i@gates.ng" : "m$i@seed.invalid"),
                'password_hash' => 'x', 'status' => 'active', 'email_verified' => 0,
            ]]);
        }

        $b = DirectoryPush::memberBatch(909, 6);
        $this->assertCount(3, $b['rows'], 'the fixtures were dropped');
        $this->assertSame(3, $b['skipped'], 'and counted, so the short page is explained');
        $this->assertFalse($b['wrapped'], 'a full page of ROWS READ is not the end of the table');
        $this->assertSame(915, $b['cursor'], 'so the cursor advances past every row it read');
    }

    public function test_one_sweep_covers_every_member_exactly_once(): void
    {
        for ($i = 0; $i < 7; $i++) {
            DB::table('gates_users')->insert([[
                'id' => 920 + $i, 'name' => 'U' . $i, 'email' => "u$i@gates.ng",
                'password_hash' => 'x', 'status' => 'active', 'email_verified' => 0,
            ]]);
        }

        $seen = [];
        $cursor = 919;
        for ($n = 0; $n < 10; $n++) {
            $b = DirectoryPush::memberBatch($cursor, 3);
            foreach ($b['rows'] as $r) {
                $seen[] = (int) $r['local_id'];
            }
            $cursor = $b['cursor'];
            if ($b['wrapped']) {
                break;
            }
        }

        $this->assertSame([920, 921, 922, 923, 924, 925, 926], $seen, 'every member once, none twice');
        $this->assertSame(0, $cursor, 'and the sweep returns to the top');
    }

    public function test_is_active_becomes_a_word_and_a_missing_table_is_not_a_failure(): void
    {
        $this->assertSame('active', DirectoryPush::fromAdmin(['id' => 1, 'email' => 'a@gates.ng', 'is_active' => 1])['local_status']);
        $this->assertSame('inactive', DirectoryPush::fromAdmin(['id' => 1, 'email' => 'a@gates.ng', 'is_active' => 0])['local_status']);

        /* gates_org_users arrived in a dated migration; a database behind on
           migrations is behind, not broken, and one missing table must not
           stop the other three being pushed. */
        DB::statement('DROP TABLE IF EXISTS gates_org_users');
        DB::table('gates_admins')->insert([
            ['id' => 930, 'email' => 'someone@gates.ng', 'name' => 'A', 'password_hash' => 'x', 'role' => 'admin', 'is_active' => 1],
        ]);
        $this->assertNotSame([], DirectoryPush::staffBatches(), 'the admins still go');
    }

    public function test_a_judge_is_recorded_as_one_and_a_member_claims_no_role(): void
    {
        /* `local_role` records what the SOURCE says and is acted on nowhere
           over there. A member holds no role on this platform, so the field
           is empty rather than an invented 'member'. */
        $this->assertSame('judge', DirectoryPush::fromJudge(['id' => 1, 'email' => 'a@gates.ng'])['local_role']);
        $this->assertSame('', DirectoryPush::fromUser(['id' => 1, 'email' => 'a@gates.ng'])['local_role']);
        $this->assertSame('editor', DirectoryPush::fromAdmin(['id' => 1, 'email' => 'a@gates.ng', 'role' => 'editor'])['local_role']);
    }

    public function test_datetimes_are_normalised_rather_than_hoped_about(): void
    {
        /* MySQL normalises a T-separated value into a TIMESTAMP column and
           SQLite stores the string verbatim, so the same row read on the two
           drivers does not carry the same text. Normalised once, here. */
        $this->assertSame('2026-10-01 09:15:00', DirectoryPush::when('2026-10-01T09:15:00'));
        $this->assertSame('2026-10-01 09:15:00', DirectoryPush::when('2026-10-01 09:15:00'));
        $this->assertNull(DirectoryPush::when(''));
        $this->assertNull(DirectoryPush::when(null));
        $this->assertNull(DirectoryPush::when('last Tuesday-ish'), 'never a guess');
    }

    public function test_it_is_off_until_a_real_key_is_set(): void
    {
        foreach (['', 'short', 'changeme-changeme-changeme-changeme',
            '<set this to something random>', 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'] as $bad) {
            DB::table('gates_settings')->updateOrInsert(['key_name' => 'dir_push_secret'], ['value' => $bad]);
            $this->assertFalse(DirectoryPush::ready(), "a key of '$bad' is not a key");
        }

        DB::table('gates_settings')->updateOrInsert(['key_name' => 'dir_push_secret'], ['value' => self::KEY]);
        $this->assertTrue(DirectoryPush::ready());
        $this->assertSame(self::KEY, DirectoryPush::secret());

        /* And the tick does nothing rather than erroring every hour. */
        DB::table('gates_settings')->where('key_name', 'dir_push_secret')->delete();
        $this->assertSame('no key', DirectoryPush::tick()['skipped'] ?? '');
    }

    public function test_the_secret_is_settable_without_a_shell(): void
    {
        /*
         * There is no SSH on production, so a credential readable only from
         * `.env` is a credential that cannot be set — which is exactly how
         * the whole Google Calendar integration came to be dead while every
         * screen explained itself correctly. Settings first, env as the
         * fallback, one resolver.
         */
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'dir_push_secret'], ['value' => 'from-the-settings-table-0123456789']);
        $this->assertSame('from-the-settings-table-0123456789', DirectoryPush::secret());

        DB::table('gates_settings')->where('key_name', 'dir_push_secret')->delete();
        $this->assertSame('', DirectoryPush::secret(), 'and with neither it is simply unset');
    }

    public function test_only_this_platform_s_own_four_stores_are_ever_named(): void
    {
        /* CACENTRE enforces this too — a token signed with the GATES key may
           write only the GATES stores — but a sender that tries is a sender
           with a bug, and finding it here is cheaper than finding it in a
           refusal log on another host. */
        $this->seedAccounts();

        $named = [];
        foreach (array_merge(DirectoryPush::memberBatch(900)['rows'], DirectoryPush::staffBatches()[0] ?? []) as $r) {
            $named[$r['store']] = true;
        }

        foreach (array_keys($named) as $store) {
            $this->assertContains($store, DirectoryPush::STORES, "$store is one of ours");
        }
        $this->assertSame(['gates_users', 'gates_admins', 'gates_judges', 'gates_org_users'], DirectoryPush::STORES);
        $this->assertSame(500, DirectoryPush::BATCH, 'the cap matches the one the receiver names in its refusal');
    }
}
