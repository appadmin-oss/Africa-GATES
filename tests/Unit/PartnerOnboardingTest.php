<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\{OrgPayout, PartnerOrg, PaymentService, RegistryCheck};
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Onboarding a partner: the checks, and the honesty about which of them are real.
 *
 * The point of most of these is negative. There is no free public API for the CAC register,
 * so a number that has only been typed in must never read as verified — a vetting record
 * that cannot tell "we looked" from "we stored it" is worthless in the argument it exists
 * for.
 */
class PartnerOnboardingTest extends TestCase
{
    private function offlinePayments(): PaymentService
    {
        return new class extends PaymentService {
            public function isEnabled(string $provider): bool { return true; }
            protected function request(string $method, string $url, ?array $b, array $h): array
            {
                return ['ok' => false, 'code' => 0, 'json' => [], 'raw' => ''];
            }
        };
    }

    private function makeOrg(array $over = []): int
    {
        return (int) DB::table('gates_partner_orgs')->insertGetId($over + [
            'slug' => 'bright-futures', 'name' => 'Bright Futures Initiative',
            'status' => PartnerOrg::STATUS_DRAFT,
        ]);
    }

    // ───────────────────────────── CAC number shape ─────────────────────────

    public function test_incorporated_trustees_numbers_are_recognised_as_nonprofit(): void
    {
        $r = RegistryCheck::cacFormat('IT/1234567');
        $this->assertTrue($r['ok']);
        $this->assertSame('IT', $r['kind']);
        $this->assertTrue($r['nonprofit'], 'IT is the Part F structure a non-profit uses.');
        $this->assertSame('IT/1234567', $r['normalised']);
    }

    /**
     * RC and BN are valid registrations and the wrong KIND for a charity collecting
     * donations. Flagged rather than refused: it is a question for a reviewer, not a rule.
     */
    public function test_company_and_business_names_are_valid_but_flagged(): void
    {
        foreach (['RC1234567' => 'limited company', 'BN9988' => 'business name'] as $num => $word) {
            $r = RegistryCheck::cacFormat($num);
            $this->assertTrue($r['ok'], "$num should parse");
            $this->assertFalse($r['nonprofit']);
            $this->assertStringContainsString($word, $r['message']);
        }
    }

    public function test_rubbish_is_rejected_with_a_useful_message(): void
    {
        foreach (['', 'hello', '12345', 'XX/1234'] as $bad) {
            $r = RegistryCheck::cacFormat($bad);
            $this->assertFalse($r['ok'], "'$bad' should not parse");
            $this->assertNotSame('', $r['message']);
        }
    }

    /** Formatting noise must not change the answer — people paste these from certificates. */
    public function test_spacing_and_punctuation_do_not_change_the_result(): void
    {
        foreach ([' it/123 4567 ', 'IT-1234567', 'it 1234567'] as $messy) {
            $this->assertSame('IT/1234567', RegistryCheck::cacFormat($messy)['normalised'], $messy);
        }
    }

    // ──────────────────────────── SCUML number shape ────────────────────────

    /**
     * Deliberately permissive. SCUML publishes no documented public format, and a strict
     * pattern invented from a few examples would reject valid certificates and teach whoever
     * hits it to type something that passes — worse than no check at all.
     */
    public function test_scuml_rejects_blanks_and_accepts_certificate_shapes(): void
    {
        $this->assertFalse(RegistryCheck::scumlFormat('')['ok']);
        $this->assertFalse(RegistryCheck::scumlFormat('ab')['ok']);
        $this->assertFalse(RegistryCheck::scumlFormat('SC 99 !!')['ok']);

        foreach (['SC-9988', 'SCUML/2026/00412', 'ABC123'] as $good) {
            $this->assertTrue(RegistryCheck::scumlFormat($good)['ok'], $good);
        }
    }

    // ─────────────────────── what is NOT automatically checked ──────────────

    /**
     * The load-bearing test in this file. With no verifier configured, asking about a
     * perfectly well-formed number must come back UNCHECKED — never verified, never
     * rejected. Anything else would let the screen claim a check that never happened.
     */
    public function test_with_no_verifier_configured_a_number_is_unchecked_not_verified(): void
    {
        $this->assertFalse(RegistryCheck::verifierAvailable());

        $r = RegistryCheck::verifyCac('IT/1234567');
        $this->assertSame(RegistryCheck::UNCHECKED, $r['state']);
        $this->assertNotSame(RegistryCheck::VERIFIED, $r['state']);
        $this->assertStringContainsString('not checked', $r['message']);
    }

    /** An unreachable verifier is "we could not ask", never "the answer was no". */
    public function test_an_unreachable_verifier_is_unchecked_rather_than_rejected(): void
    {
        putenv('CAC_VERIFY_URL=https://example.invalid/verify');
        putenv('CAC_VERIFY_KEY=k');
        $_ENV['CAC_VERIFY_URL'] = 'https://example.invalid/verify';
        $_ENV['CAC_VERIFY_KEY'] = 'k';

        try {
            $r = RegistryCheck::verifyCac('IT/1234567', function (): string {
                throw new \RuntimeException('could not resolve host');
            });
            $this->assertSame(RegistryCheck::UNCHECKED, $r['state']);
            $this->assertStringContainsString('Could not reach', $r['message']);
        } finally {
            putenv('CAC_VERIFY_URL'); putenv('CAC_VERIFY_KEY');
            unset($_ENV['CAC_VERIFY_URL'], $_ENV['CAC_VERIFY_KEY']);
        }
    }

    /** A verifier that finds nothing is a rejection, and one that finds a name is not. */
    public function test_a_configured_verifier_can_confirm_or_reject(): void
    {
        putenv('CAC_VERIFY_URL=https://example.test/verify');
        putenv('CAC_VERIFY_KEY=k');
        $_ENV['CAC_VERIFY_URL'] = 'https://example.test/verify';
        $_ENV['CAC_VERIFY_KEY'] = 'k';

        try {
            $hit = RegistryCheck::verifyCac('IT/1234567',
                fn(): string => json_encode(['data' => ['company_name' => 'BRIGHT FUTURES INITIATIVE']]));
            $this->assertSame(RegistryCheck::VERIFIED, $hit['state']);
            $this->assertSame('BRIGHT FUTURES INITIATIVE', $hit['name']);

            $miss = RegistryCheck::verifyCac('IT/7654321', fn(): string => json_encode(['data' => []]));
            $this->assertSame(RegistryCheck::REJECTED, $miss['state']);
        } finally {
            putenv('CAC_VERIFY_URL'); putenv('CAC_VERIFY_KEY');
            unset($_ENV['CAC_VERIFY_URL'], $_ENV['CAC_VERIFY_KEY']);
        }
    }

    public function test_the_search_url_carries_the_normalised_number(): void
    {
        $this->assertStringContainsString('IT%2F1234567', RegistryCheck::cacSearchUrl('it 1234567'));
        // A number that does not parse still gets somebody to the portal.
        $this->assertSame(RegistryCheck::CAC_SEARCH, RegistryCheck::cacSearchUrl('nonsense'));
    }

    // ───────────────────────────── onboarding rules ─────────────────────────

    /** An organisation may only ever have one settlement account attached at a time. */
    public function test_a_second_settlement_account_cannot_be_attached_over_the_first(): void
    {
        $id = $this->makeOrg(['subaccount_code' => 'ACCT_first']);
        $r  = PartnerOrg::attachSubaccount($this->offlinePayments(), $id, '0123456789', '058');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('already has a settlement account', $r['message']);
        $this->assertSame('ACCT_first',
            DB::table('gates_partner_orgs')->where('id', $id)->value('subaccount_code'));
    }

    /**
     * A gateway that cannot be reached must not leave a half-onboarded organisation behind.
     * Nothing is written until Paystack has agreed.
     */
    public function test_an_unreachable_gateway_writes_nothing(): void
    {
        $id = $this->makeOrg();
        $r  = PartnerOrg::attachSubaccount($this->offlinePayments(), $id, '0123456789', '058');

        $this->assertFalse($r['ok']);
        $org = PartnerOrg::find($id);
        $this->assertNull($org->subaccount_code);
        $this->assertNull($org->account_last4);
        $this->assertSame(PartnerOrg::STATUS_DRAFT, $org->status, 'Status must not advance on a failure.');
    }

    // ───────────────── the payout gap that the recipient code closes ────────

    /**
     * The reason the transfer recipient is created during onboarding: a payout cannot build
     * one, because the account number is deliberately never stored. Without a recipient on
     * file the payout must fail cleanly and say what to do — never invent a second recipient
     * for the same bank account, which makes a reconciliation unanswerable.
     */
    public function test_a_payout_without_a_recipient_on_file_fails_clearly(): void
    {
        $id = $this->makeOrg([
            'status' => PartnerOrg::STATUS_APPROVED, 'subaccount_code' => 'ACCT_x',
            'cac_number' => 'IT/1', 'scuml_number' => 'SC-1',
        ]);
        $ref = OrgPayout::mintReference($id);
        DB::table('gates_org_payouts')->insert([
            'org_id' => $id, 'reference' => $ref, 'amount_naira' => 5000,
            'status' => OrgPayout::ST_QUEUED, 'requested_at' => date('Y-m-d H:i:s'),
        ]);

        $r = OrgPayout::send($this->offlinePayments(), $ref);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('no payout recipient', $r['message']);
        // Still queued, still the same reference — recoverable, and never re-sent blind.
        $this->assertSame(OrgPayout::ST_QUEUED,
            DB::table('gates_org_payouts')->where('reference', $ref)->value('status'));
    }

    // ─────────────────────────── the documents table ────────────────────────

    public function test_certificates_can_be_recorded_with_and_without_an_expiry(): void
    {
        $id = $this->makeOrg();
        DB::table('gates_org_documents')->insert([
            ['org_id' => $id, 'kind' => 'cac',   'stored_path' => 'uploads/org-docs/a.pdf',
             'expires_on' => null, 'created_at' => date('Y-m-d H:i:s')],
            ['org_id' => $id, 'kind' => 'insurance', 'stored_path' => 'uploads/org-docs/b.pdf',
             'expires_on' => '2020-01-01', 'created_at' => date('Y-m-d H:i:s')],
        ]);

        $rows = DB::table('gates_org_documents')->where('org_id', $id)->orderBy('kind')->get()->all();
        $this->assertCount(2, $rows);
        // A CAC certificate does not expire; an insurance policy does, and a lapsed one must
        // be distinguishable from one that was never given a date.
        $this->assertNull($rows[0]->expires_on);
        $this->assertSame('2020-01-01', substr((string) $rows[1]->expires_on, 0, 10));
    }
}
