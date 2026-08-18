<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use AfricaGates\Services\{PartnerOrg, RegistryCheck, QueueService};
use Illuminate\Database\Capsule\Manager as DB;

/**
 * What happens to a CAC number between the form and the register.
 *
 * ── THE GAP THIS CLOSES ─────────────────────────────────────────────────────
 *
 * `RegistryCheck` has always known the difference between a registration number and a phone
 * number, and it was only ever called from the admin vetting screen — which is the wrong end
 * of the process. The person who can fix a typo is the person who just made it, and by the
 * time a reviewer opens the record they are gone. So `12345`, a pasted line of a PDF, and a
 * bank account all stored clean and looked like registrations on the screen where somebody
 * was deciding whether an organisation was real.
 *
 * ── AND THE LINE THESE TESTS HOLD ───────────────────────────────────────────
 *
 * The shape is refused at the form, because it is knowable offline and instantly.
 * Everything else is RECORDED and left to a person: the kind of registration, the duplicate,
 * and whatever the register says. A machine that rejected on any of those three would be
 * deciding something it cannot see enough to decide — and would be doing it at a closing
 * date, to somebody with no way to argue.
 */
final class RegistryCheckOnSubmitTest extends TestCase
{
    private function vendorInput(array $over = []): array
    {
        return $over + [
            'entity_type'   => PartnerOrg::ENTITY_BUSINESS,
            'name'          => 'Adaeze Foods',
            'legal_name'    => 'Adaeze Foods Limited',
            'contact_email' => 'adaeze-' . bin2hex(random_bytes(4)) . '@example.test',
            'password'      => 'correct horse battery',
            'cac_number'    => 'BN1234567',
        ];
    }

    private function partnerInput(array $over = []): array
    {
        return $over + [
            'name'          => 'Bright Futures',
            'legal_name'    => 'Bright Futures Initiative',
            'contact_email' => 'bright-' . bin2hex(random_bytes(4)) . '@example.test',
            'password'      => 'correct horse battery',
            'cac_number'    => 'IT/998877',
        ];
    }

    // ───────────────────────────── the shape gate ───────────────────────────

    /**
     * The whole point. A number that is not a number is refused where it was typed.
     *
     * `12345` is the honest mistake; a phone number is what happens when somebody tabs one
     * field too far. Both used to be stored as a registration.
     */
    public function test_a_malformed_cac_is_refused_at_the_vendor_form(): void
    {
        foreach (['12345', '08031234567', 'see attached certificate', 'XX/123'] as $bad) {
            $r = PartnerOrg::registerVendor($this->vendorInput(['cac_number' => $bad]));

            $this->assertFalse($r['ok'], "“{$bad}” was accepted as a CAC number");
            $this->assertStringContainsString('RC, BN or IT', $r['message'],
                'the refusal has to say what a CAC number looks like, or it teaches nothing');
        }

        $this->assertSame(0, DB::table('gates_partner_orgs')->count(),
            'nothing may be written for a refused application');
    }

    /** And an empty one still gets its own message, which is a different problem. */
    public function test_an_absent_cac_still_gets_the_older_message(): void
    {
        $r = PartnerOrg::registerVendor($this->vendorInput(['cac_number' => '']));

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('apply as an individual', $r['message'],
            'a business with no number is offered the other route, not told about syntax');
    }

    /**
     * One spelling reaches the database.
     *
     * `rc 1234567`, `RC/1234567` and `RC-1234567` are one registration. Stored as typed they
     * are three organisations as far as any duplicate check is concerned, which is the same
     * as having no duplicate check.
     */
    public function test_the_stored_number_is_normalised(): void
    {
        foreach (['bn1234567', 'BN/1234567', 'BN-1234567', '  bn 1234567 '] as $typed) {
            DB::table('gates_partner_orgs')->delete();
            DB::table('gates_org_users')->delete();

            $r = PartnerOrg::registerVendor($this->vendorInput(['cac_number' => $typed]));
            $this->assertTrue($r['ok'], $r['message'] ?? '');

            $this->assertSame('BN/1234567',
                (string) DB::table('gates_partner_orgs')->where('id', $r['org_id'])->value('cac_number'),
                "“{$typed}” did not normalise");
        }
    }

    /**
     * An individual's stray number is discarded, not validated.
     *
     * The form does not ask a sole trader for one, so anything in that box arrived by
     * accident or by copying — and a number typed into a field somebody was told to leave
     * empty is likelier to be another business's than their own. Refusing it would fail an
     * application over a field that is not required; storing it would put a stranger's
     * registration on their record.
     */
    public function test_an_individual_never_carries_a_cac_number(): void
    {
        $r = PartnerOrg::registerVendor($this->vendorInput([
            'entity_type' => PartnerOrg::ENTITY_INDIVIDUAL,
            'cac_number'  => 'nonsense that would fail the format check',
        ]));

        $this->assertTrue($r['ok'], $r['message'] ?? '');
        $this->assertNull(DB::table('gates_partner_orgs')->where('id', $r['org_id'])->value('cac_number'));
    }

    /**
     * A non-IT number is a NOTE on a donation partner, never a refusal.
     *
     * A Nigerian non-profit limited by guarantee holds an RC. Turning one away at a form it
     * cannot argue with would be wrong, and would be wrong in the direction that costs the
     * platform the applicants it most wants.
     */
    public function test_a_donation_partner_with_an_rc_number_is_accepted_with_a_note(): void
    {
        $r = PartnerOrg::registerPartner($this->partnerInput(['cac_number' => 'RC112233']));
        $this->assertTrue($r['ok'], $r['message'] ?? '');

        PartnerOrg::runRegistryCheck((int) $r['org_id']);
        $note = (string) DB::table('gates_partner_orgs')->where('id', $r['org_id'])->value('cac_check_note');

        $this->assertStringContainsString('incorporated trustee', strtolower($note),
            'the reviewer has to be told what kind of registration this is');
    }

    /** But a donation partner with no number at all is still refused. */
    public function test_a_donation_partner_still_cannot_apply_without_a_number(): void
    {
        $r = PartnerOrg::registerPartner($this->partnerInput(['cac_number' => '']));

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('incorporated body', $r['message']);
    }

    // ────────────────────────────── the queued job ──────────────────────────

    /**
     * The register is asked in the background, and asked once.
     *
     * Never inside the submit: RegistryCheck allows ten seconds to connect and ten to read,
     * and that submit creates an account AND an application, on a phone, against a closing
     * date. A verifier having a bad afternoon must not cost somebody their application.
     */
    public function test_registering_queues_exactly_one_registry_check(): void
    {
        $r = PartnerOrg::registerVendor($this->vendorInput());
        $this->assertTrue($r['ok'], $r['message'] ?? '');

        $jobs = DB::table('gates_jobs')->where('type', PartnerOrg::JOB_REGISTRY)->get();
        $this->assertCount(1, $jobs);
        $this->assertSame($r['org_id'], (int) (json_decode((string) $jobs[0]->payload, true)['org_id'] ?? 0));

        // Deduped, so a second push for the same organisation does not queue a second job.
        PartnerOrg::queueRegistryCheck((int) $r['org_id']);
        $this->assertSame(1, DB::table('gates_jobs')->where('type', PartnerOrg::JOB_REGISTRY)->count());
    }

    /** The handler is wired, or the job would retry five times and land in `failed`. */
    public function test_the_job_type_has_a_handler_registered(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Support/Maintenance.php');
        $this->assertStringContainsString('PartnerOrg::JOB_REGISTRY', $src,
            'a queued job with no handler burns five attempts and fails silently');
    }

    // ──────────────────────────── what the check writes ─────────────────────

    /**
     * A borrowed number is found, and is reported rather than refused.
     *
     * This is the cheapest real fraud signal the platform has — one indexed lookup, no third
     * party, no key. It is also not a verdict: the second applicant may be the real one, and
     * deciding which is a judgement with a whole record behind it.
     */
    public function test_a_duplicate_number_is_recorded_against_the_second_organisation(): void
    {
        $first  = PartnerOrg::registerVendor($this->vendorInput(['cac_number' => 'RC5550001']));
        $second = PartnerOrg::registerVendor($this->vendorInput([
            'name' => 'Someone Else', 'cac_number' => 'rc 5550001',
        ]));
        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok'], 'a duplicate must NOT block the application');

        PartnerOrg::runRegistryCheck((int) $second['org_id']);
        $row = DB::table('gates_partner_orgs')->where('id', $second['org_id'])->first();

        $this->assertStringContainsString('already on file', (string) $row->cac_check_note);
        $this->assertStringContainsString('Adaeze Foods', (string) $row->cac_check_note,
            'naming the other organisation is what makes the note actionable');
        $this->assertNotNull($row->cac_checked_at);
    }

    /**
     * With no verifier configured, the answer is UNCHECKED. Never a pass, never a refusal.
     *
     * An outage that reads as a rejection is the most dangerous failure available on a screen
     * where somebody is deciding whether a charity is real, and "nobody asked" has to be
     * distinguishable from "we asked and it said no".
     */
    public function test_with_no_verifier_configured_the_state_stays_unchecked(): void
    {
        $r = PartnerOrg::registerVendor($this->vendorInput());
        PartnerOrg::runRegistryCheck((int) $r['org_id']);

        $this->assertSame(RegistryCheck::UNCHECKED,
            (string) DB::table('gates_partner_orgs')->where('id', $r['org_id'])->value('cac_check'));
    }

    /**
     * A person's verdict is never overwritten by the machine.
     *
     * The confirmed/verified distinction exists so the record can tell somebody's judgement
     * from a lookup's. A job that quietly replaced one with the other would destroy the only
     * thing that distinction is for.
     */
    public function test_a_reviewers_verdict_survives_a_later_run(): void
    {
        $r = PartnerOrg::registerVendor($this->vendorInput());
        DB::table('gates_partner_orgs')->where('id', $r['org_id'])->update([
            'cac_check'           => RegistryCheck::CONFIRMED,
            'cac_registered_name' => 'ADAEZE FOODS LIMITED',
        ]);

        PartnerOrg::runRegistryCheck((int) $r['org_id']);
        $row = DB::table('gates_partner_orgs')->where('id', $r['org_id'])->first();

        $this->assertSame(RegistryCheck::CONFIRMED, (string) $row->cac_check);
        $this->assertSame('ADAEZE FOODS LIMITED', (string) $row->cac_registered_name);
        // The note still refreshes — it is machine output and carries no verdict.
        $this->assertNotNull($row->cac_checked_at);
    }

    /** A check on an organisation that has gone is a no-op, not a crash in a worker. */
    public function test_checking_a_missing_organisation_is_harmless(): void
    {
        $this->assertSame(RegistryCheck::UNCHECKED, PartnerOrg::runRegistryCheck(999999)['state']);
        $this->assertSame(RegistryCheck::UNCHECKED, PartnerOrg::runRegistryCheck(0)['state']);
    }
}
