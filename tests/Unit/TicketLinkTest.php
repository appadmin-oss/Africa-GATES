<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\TicketLinkService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A link that opens ONE support thread, for somebody with no account.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE GAP THIS CLOSES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every ticket endpoint required a signed-in member — sound reasoning ("a reply
 * needs a verified address") that locked out the two groups most likely to need
 * support. Guest payers: paid voting takes an email and a card and creates no
 * account, so the entire unminted-vote incident population was given the repair
 * tools and then no way to answer the reply they got. And held claimants: the claim
 * rules require a human route that works without an account, and the assisted path
 * routes to a ticket the person could not open.
 *
 * A support thread the requester cannot reply to is a monologue.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE SECURITY PROPERTIES CARRY THE FILE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * This is an UNAUTHENTICATED read of somebody's correspondence with support, which
 * can contain a payment reference, an amount and a name. So most of what follows is
 * about what a link must NOT do. Each is a specific way this feature could become a
 * disclosure bug rather than a convenience:
 *
 *   • it opens exactly one thread and can never list;
 *   • every failure looks identical, so it is not an oracle for which references
 *     exist — and references travel in emails, receipts and screenshots;
 *   • the raw token is never at rest, so a database leak yields nothing usable;
 *   • it dies if the ticket's address changes, because it is permission for a
 *     PERSON, not a bearer key to a row id.
 */
final class TicketLinkTest extends TestCase
{
    private int $ticketId = 0;
    private const EMAIL = 'guest@example.test';
    private const REF   = 'AGS-LINK-1';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_ticket_links')->delete();
        DB::table('gates_support_tickets')->delete();

        $this->ticketId = $this->ticket(self::REF, self::EMAIL);
    }

    private function ticket(string $ref, string $email): int
    {
        return (int) DB::table('gates_support_tickets')->insertGetId([
            'reference' => $ref, 'user_id' => null, 'email' => $email,
            'name' => 'A Guest', 'subject' => 'Paid but no votes',
            'transcript' => '', 'severity' => 'normal', 'status' => 'open',
            'created_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    // ══ it works ═════════════════════════════════════════════════════════════

    /** THE POINT. A guest with no account can be admitted to their own thread. */
    public function test_a_link_opens_the_ticket_it_was_issued_for(): void
    {
        $token = TicketLinkService::issue($this->ticketId, self::EMAIL);
        $this->assertNotNull($token);

        $who = TicketLinkService::resolve($token);

        $this->assertNotNull($who, 'A guest with no account must be able to reach their own ticket.');
        $this->assertSame($this->ticketId, $who['ticket_id']);
        $this->assertSame(self::REF, $who['reference']);
    }

    /** The address is matched case-insensitively — people type their email anyhow. */
    public function test_the_address_is_matched_without_regard_to_case(): void
    {
        $token = TicketLinkService::issue($this->ticketId, 'GUEST@Example.Test');
        $this->assertNotNull(TicketLinkService::resolve($token));
    }

    // ══ and it is scoped to one thread ══════════════════════════════════════

    /**
     * A link for ticket A must never open ticket B.
     *
     * The whole containment property in one assertion. If this ever fails, one
     * leaked link is a key to everybody's correspondence.
     */
    public function test_a_link_cannot_reach_another_ticket(): void
    {
        $other = $this->ticket('AGS-LINK-2', 'someone.else@example.test');
        $token = TicketLinkService::issue($this->ticketId, self::EMAIL);

        $who = TicketLinkService::resolve($token);

        $this->assertSame($this->ticketId, $who['ticket_id']);
        $this->assertNotSame($other, $who['ticket_id']);
    }

    /**
     * The service exposes no way to LIST. Read as a design assertion, not a unit test.
     *
     * The member path can enumerate a person's own tickets because it knows who they
     * are. A link knows only that its bearer had one email once, so listing from it
     * would hand over every thread that address ever opened to whoever forwarded the
     * message.
     */
    public function test_the_service_offers_no_way_to_enumerate(): void
    {
        $methods = get_class_methods(TicketLinkService::class);

        foreach ($methods as $m) {
            $this->assertDoesNotMatchRegularExpression('/^(all|list|forEmail|search|find)/i', $m,
                "TicketLinkService::{$m}() looks like enumeration — a link is permission "
                . 'for one conversation, never for an address\'s history.');
        }
    }

    // ══ every failure looks the same ════════════════════════════════════════

    /**
     * Unknown, malformed, expired, revoked and reassigned all return the SAME null.
     *
     * Otherwise the endpoint is an oracle: try a reference, learn whether it exists.
     * References travel in emails, receipts and screenshots, so "does AGS-1234 exist"
     * is a question a stranger should not be able to ask.
     */
    public function test_every_kind_of_bad_token_fails_identically(): void
    {
        $good = TicketLinkService::issue($this->ticketId, self::EMAIL);

        $expired = TicketLinkService::issue($this->ticketId, self::EMAIL);
        DB::table('gates_ticket_links')->where('token_hash', hash('sha256', $expired))
            ->update(['expires_at' => Carbon::now()->subDay()->toDateTimeString()]);

        $revoked = TicketLinkService::issue($this->ticketId, self::EMAIL);
        DB::table('gates_ticket_links')->where('token_hash', hash('sha256', $revoked))
            ->update(['revoked_at' => Carbon::now()->toDateTimeString()]);

        foreach ([
            'empty'      => '',
            'too short'  => 'abc',
            'not hex'    => str_repeat('z', 64),
            'right shape, never issued' => str_repeat('a', 64),
            'expired'    => $expired,
            'revoked'    => $revoked,
        ] as $what => $token) {
            $this->assertNull(TicketLinkService::resolve($token),
                "{$what}: must be refused, and refused the same way as every other failure.");
        }

        $this->assertNotNull(TicketLinkService::resolve($good), 'The control still works.');
    }

    /**
     * Changing a ticket's address kills every link already sent for it.
     *
     * A link is permission for one PERSON. If a ticket is corrected or reassigned,
     * whoever holds the old link is no longer the person it was addressed to.
     */
    public function test_reassigning_the_ticket_kills_the_old_links(): void
    {
        $token = TicketLinkService::issue($this->ticketId, self::EMAIL);
        $this->assertNotNull(TicketLinkService::resolve($token));

        DB::table('gates_support_tickets')->where('id', $this->ticketId)
            ->update(['email' => 'new.owner@example.test']);

        $this->assertNull(TicketLinkService::resolve($token),
            'The old holder is no longer the person this thread belongs to.');
    }

    /** A deleted ticket takes its links with it. */
    public function test_a_link_to_a_vanished_ticket_is_refused(): void
    {
        $token = TicketLinkService::issue($this->ticketId, self::EMAIL);
        DB::table('gates_support_tickets')->where('id', $this->ticketId)->delete();

        $this->assertNull(TicketLinkService::resolve($token));
    }

    // ══ nothing usable is stored ════════════════════════════════════════════

    /**
     * The raw token is never at rest.
     *
     * The same reason password hashes exist: a dump of this table must not be a set
     * of working keys to other people's support conversations.
     */
    public function test_the_token_itself_is_never_written_down(): void
    {
        $token = TicketLinkService::issue($this->ticketId, self::EMAIL);

        $row = DB::table('gates_ticket_links')->where('ticket_id', $this->ticketId)->first();

        $this->assertSame(hash('sha256', $token), (string) $row->token_hash);
        foreach ((array) $row as $col => $value) {
            $this->assertNotSame($token, (string) $value,
                "Column `{$col}` holds the raw token — a leak of this table would be a "
                . 'set of live keys.');
        }
    }

    /** Tokens are unguessable and never repeat. */
    public function test_tokens_are_long_random_and_distinct(): void
    {
        $seen = [];
        for ($i = 0; $i < 25; $i++) {
            $t = TicketLinkService::issue($this->ticketId, self::EMAIL);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $t);
            $seen[(string) $t] = true;
        }
        $this->assertCount(25, $seen, 'A repeated token would be a collision in 256 bits.');
    }

    // ══ lifecycle ═══════════════════════════════════════════════════════════

    /** Revoking is per ticket and immediate — the "that wasn't me" route. */
    public function test_revoking_a_ticket_closes_every_link_at_once(): void
    {
        $a = TicketLinkService::issue($this->ticketId, self::EMAIL);
        $b = TicketLinkService::issue($this->ticketId, self::EMAIL);

        $this->assertSame(2, TicketLinkService::revokeForTicket($this->ticketId));

        $this->assertNull(TicketLinkService::resolve($a));
        $this->assertNull(TicketLinkService::resolve($b));
    }

    /** Revoking one ticket must not touch another's. */
    public function test_revoking_is_scoped_to_its_own_ticket(): void
    {
        $other      = $this->ticket('AGS-LINK-3', 'third@example.test');
        $mine       = TicketLinkService::issue($this->ticketId, self::EMAIL);
        $theirs     = TicketLinkService::issue($other, 'third@example.test');

        TicketLinkService::revokeForTicket($this->ticketId);

        $this->assertNull($this->resolveOrNull($mine));
        $this->assertNotNull($this->resolveOrNull($theirs));
    }

    private function resolveOrNull(?string $t): ?array
    {
        return $t === null ? null : TicketLinkService::resolve($t);
    }

    /**
     * The lifetime suits a support conversation, not a session.
     *
     * Short-lived links fail the exact population this exists for — somebody on
     * patchy mobile data reading their email on Saturday, who has no account to fall
     * back on when the link is dead.
     */
    public function test_the_lifetime_is_generous_enough_for_the_people_who_need_it(): void
    {
        $this->assertGreaterThanOrEqual(14, TicketLinkService::TTL_DAYS,
            'Anything shorter strands the people with no account, which is the whole point.');
        $this->assertLessThanOrEqual(60, TicketLinkService::TTL_DAYS,
            'An unauthenticated read of somebody\'s correspondence should not be indefinite.');
    }

    /** Pruning keeps recently-lapsed rows, so "my link stopped working" is answerable. */
    public function test_pruning_keeps_recently_expired_links(): void
    {
        $recent = TicketLinkService::issue($this->ticketId, self::EMAIL);
        DB::table('gates_ticket_links')->where('token_hash', hash('sha256', $recent))
            ->update(['expires_at' => Carbon::now()->subDays(2)->toDateTimeString()]);

        $ancient = TicketLinkService::issue($this->ticketId, self::EMAIL);
        DB::table('gates_ticket_links')->where('token_hash', hash('sha256', $ancient))
            ->update(['expires_at' => Carbon::now()->subDays(90)->toDateTimeString()]);

        TicketLinkService::prune();

        $this->assertSame(1, DB::table('gates_ticket_links')
            ->where('token_hash', hash('sha256', $recent))->count(),
            'A link that lapsed two days ago is still the answer to a live question.');
        $this->assertSame(0, DB::table('gates_ticket_links')
            ->where('token_hash', hash('sha256', $ancient))->count());
    }

    /** Use is recorded, so an oddly-travelled link is answerable after the fact. */
    public function test_use_is_counted(): void
    {
        $token = TicketLinkService::issue($this->ticketId, self::EMAIL);

        TicketLinkService::touch($token);
        TicketLinkService::touch($token);

        $row = DB::table('gates_ticket_links')->where('token_hash', hash('sha256', $token))->first();
        $this->assertSame(2, (int) $row->uses);
        $this->assertNotNull($row->last_used_at);
    }

    // ══ degrading, not breaking ═════════════════════════════════════════════

    /** Nonsense input is refused without a database round trip or an exception. */
    public function test_rubbish_input_is_refused_quietly(): void
    {
        foreach (['', '   ', 'null', '../../etc/passwd', "' OR 1=1 --", str_repeat('a', 5000)] as $junk) {
            $this->assertNull(TicketLinkService::resolve($junk));
        }
    }

    public function test_issuing_refuses_nonsense_rather_than_minting_a_useless_link(): void
    {
        $this->assertNull(TicketLinkService::issue(0, self::EMAIL));
        $this->assertNull(TicketLinkService::issue($this->ticketId, ''));
    }

    /**
     * A failed mint must never stop a support reply going out.
     *
     * `urlFor()` returns '' rather than throwing, because an email with no
     * convenience link is a small loss and an unsent support answer is a large one.
     */
    public function test_a_url_is_empty_rather_than_fatal_when_it_cannot_be_issued(): void
    {
        $this->assertSame('', TicketLinkService::urlFor(0, self::EMAIL));
    }

    /** The URL carries the token in a path segment and points at the guest route. */
    public function test_the_url_is_shaped_as_the_route_expects(): void
    {
        $token = TicketLinkService::issue($this->ticketId, self::EMAIL);
        $url   = TicketLinkService::url($token, 'https://afg.example');

        $this->assertSame('https://afg.example/support/t/' . $token, $url);
    }
}
