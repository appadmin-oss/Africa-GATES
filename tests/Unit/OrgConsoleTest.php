<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\OrgBrand;
use AfricaGates\Services\OrgPayout;
use AfricaGates\Services\OrgRail;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Response;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * THE ORGANISATION CONSOLE — /org and /org?tab=…
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS FILE EXISTS, AND WHAT IT IS RESTORING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `GET /org` is routed to `pages/org/dashboard.twig`. That template was destroyed on
 * 3 Oct 2026 with the rest of the public pages and never rebuilt, so for a fortnight the
 * console an organisation manages its money and its public page from answered **500** — and
 * nothing in the suite said so, because every test that rendered it was deleted along with
 * it. `OrgPageTest` lost `test_the_dashboard_has_a_form_that_reaches_the_service`, the one
 * assertion standing between `OrgBrand` and the state it originally shipped in: complete,
 * correct, and reachable by nobody.
 *
 * So the first test here renders the page. Everything else is a rule that used to be held
 * by a test that no longer exists, listed in `docs/handoff/inventory/pages--org--dashboard.md`
 * under "Rules held by guard tests destroyed with this page", re-asserted against the new
 * markup.
 *
 * ── EACH ONE WAS WATCHED FAILING ────────────────────────────────────────────
 *
 * Per CLAUDE.md: a sweep is evidence only once it has named a break. Before this file was
 * trusted, each assertion below was run against a deliberately broken version of the thing
 * it guards — the template renamed away (every render test errors), `org_can_request`
 * inlined in the overview and left true (the offer/form agreement fails), the gate in
 * `OrgRail::offers()` short-circuited to `true` (the vendor and partner rail tests fail),
 * and a block field name typed by hand instead of derived (the editor-contract test fails).
 */
final class OrgConsoleTest extends TestCase
{
    private int $orgId = 0;
    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgId = (int) DB::table('gates_partner_orgs')->insertGetId([
            'slug' => 'owo-reading', 'name' => 'Owo Reading Trust', 'status' => 'approved',
            'contact_name' => 'Adaeze Okonkwo', 'contact_email' => 'lead@owo.test',
            'kind' => 'partner', 'entity_type' => 'organisation',
        ]);
        $this->userId = (int) DB::table('gates_org_users')->insertGetId([
            'org_id' => $this->orgId, 'email' => 'lead@owo.test', 'name' => 'Adaeze Okonkwo',
            'password_hash' => password_hash('x', PASSWORD_DEFAULT),
            'role' => 'owner', 'is_active' => 1,
        ]);
        // BOTH ids. `OrgAuth::user()` re-reads the user every request and refuses a session
        // whose org no longer matches the user's — so a fixture setting only the user id is
        // signed out, and every render comes back as an empty redirect body.
        $_SESSION['org_user_id'] = $this->userId;
        $_SESSION['org_id']      = $this->orgId;
    }

    private function vendor(): void
    {
        DB::table('gates_partner_orgs')->where('id', $this->orgId)
            ->update(['kind' => 'vendor']);
    }

    /** A gift that has cleared, so `has_money` is true. */
    private function gift(int $naira = 50_000): void
    {
        DB::table('gates_donations')->insert([
            'recipient_org_id' => $this->orgId, 'amount_naira' => $naira,
            'platform_fee_naira' => (int) round($naira * 0.05),
            'status' => 'confirmed', 'donor_name' => 'A Supporter', 'show_name' => 1,
            // NOT NULL with no default — checked against the schema, not guessed from the
            // other fixtures in this directory.
            'donor_email' => 'supporter@example.test',
            'payment_ref' => 'dev-' . $naira, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function page(string $tab = ''): string
    {
        $uri = '/org' . ($tab === '' ? '' : '?tab=' . $tab);
        $req = (new ServerRequestFactory())->createServerRequest('GET', $uri);
        if ($tab !== '') $req = $req->withQueryParams(['tab' => $tab]);

        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        $res = $b->build()->get(\AfricaGates\Controllers\OrgDashboardController::class)
            ->dashboard($req, new Response());

        return (string) $res->getBody();
    }

    /** Whitespace-normalised, because the templates wrap sentences across lines. */
    private function prose(string $html): string
    {
        return (string) preg_replace('~\s+~', ' ', $html);
    }

    // ══ the one that would have caught the whole thing ═══════════════════════

    /**
     * THE CONSOLE RENDERS AT ALL.
     *
     * For a fortnight this was a 500 and the suite was green, because the tests that
     * rendered it were destroyed with the template and nothing replaced them. A console an
     * organisation cannot open is every feature behind it being unreachable — the
     * fourteen page sections, the twelve links, the six videos, the payout request, the
     * appeals, the documents, the stands — all of which were working the whole time.
     */
    public function test_every_section_of_the_console_renders(): void
    {
        $this->gift();
        DB::table('gates_partner_orgs')->where('id', $this->orgId)->update(['kind' => 'vendor']);

        foreach (OrgRail::PAGE_TABS as $tab) {
            $html = $this->page($tab === 'overview' ? '' : $tab);
            $this->assertNotSame('', trim($html), "the $tab section rendered nothing");
            $this->assertStringContainsString('acx-main', $html,
                "the $tab section did not render inside the console frame");
        }
    }

    // ══ the editor: the reason the service existed and could not be reached ══

    /**
     * THERE IS A FORM, AND IT POSTS WHERE THE SERVICE LISTENS.
     *
     * The restored `OrgPageTest::test_the_dashboard_has_a_form_that_reaches_the_service`.
     * Not a behaviour test — `OrgBrand::save()` was always correct. This is the assertion
     * that somebody is HANDED it, which is the one that was missing for the whole life of
     * the feature.
     */
    public function test_the_editor_exists_and_posts_to_the_service(): void
    {
        $html = $this->page('page');

        $this->assertStringContainsString('action="/org/brand"', $html);
        $this->assertStringContainsString('enctype="multipart/form-data"', $html,
            'the logo is an upload, so the form needs the encoding or the file is dropped');
        $this->assertStringContainsString('name="_token"', $html);
    }

    /**
     * AN ORGANISATION CAN ADD LINKS, VIDEOS AND EVERY BLOCK THE SERVICE READS.
     *
     * The field names are DERIVED from `OrgBrand::BLOCKS` and `SECTIONS`, exactly as
     * `blockFrom()` derives the names it reads. This test asks the service what it reads
     * and requires the form to offer every one — so a block added to that table and
     * forgotten here fails, rather than posting happily into nothing.
     *
     * That failure is otherwise SILENT: a field named `impact_figures` submits, is never
     * read, and the organisation saves, sees no error and finds the block empty.
     */
    public function test_the_editor_offers_every_field_the_service_reads(): void
    {
        $html = $this->page('page');

        foreach (['accent', 'tagline', 'story', 'website'] as $f) {
            $this->assertStringContainsString('name="' . $f . '"', $html, "no $f field");
        }

        // Links and videos: parallel arrays, which is what a form posts with no JavaScript.
        foreach (['link_label[]', 'link_url[]', 'video_url[]', 'video_title[]'] as $f) {
            $this->assertStringContainsString('name="' . $f . '"', $html, "no $f field");
        }

        // All fourteen switches, by the service's own keys.
        foreach (array_keys(OrgBrand::SECTIONS) as $key) {
            $this->assertStringContainsString('name="section_' . $key . '"', $html,
                "the $key section cannot be switched on");
        }

        // And both halves of every block, named the way the service names them.
        foreach (OrgBrand::BLOCKS as $name => $spec) {
            foreach ([$spec['a'], $spec['b']] as $half) {
                $this->assertStringContainsString('name="' . $name . '_' . $half . '[]"', $html,
                    "the $name block's $half is not on the form, so it can never be filled in");
            }
        }
    }

    /**
     * A SAVED VIDEO COMES BACK AS THE ADDRESS THAT WAS PASTED.
     *
     * Only the provider and the id are stored — nothing an organisation types may reach an
     * `src` — so the editor has to rebuild the watch address to put it back in the field.
     * Without that the field renders EMPTY over a saved video, and the next save, which
     * reads the parallel arrays as submitted, silently deletes every one of them.
     */
    public function test_a_saved_video_is_rendered_back_into_its_field(): void
    {
        OrgBrand::save($this->orgId, [
            'accent' => '#1a6118',
            'video_url' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            'video_title' => ['A day at Ikorodu'],
        ]);

        $html = $this->page('page');
        $this->assertStringContainsString('dQw4w9WgXcQ', $html,
            'the saved video is not in the field, so saving again would delete it');
        $this->assertStringContainsString('A day at Ikorodu', $html);
    }

    /**
     * NO IFRAME REACHES THE EDITOR EITHER.
     *
     * `OrgPageTest` holds this for the public page. The editor shows saved videos back, and
     * the lazy way to preview one is to frame it — which would send the ORGANISATION's
     * address to YouTube on every visit to their own console, and set storage before
     * anybody pressed anything.
     */
    public function test_the_editor_frames_nothing(): void
    {
        OrgBrand::save($this->orgId, [
            'accent' => '#1a6118',
            'video_url' => ['https://vimeo.com/76979871'],
            'video_title' => ['The borehole'],
        ]);

        $this->assertStringNotContainsStringIgnoringCase('<iframe', $this->page('page'));
    }

    // ══ the rail: which sections an organisation is offered ══════════════════

    /** A donation partner gets the money and not the stands. */
    public function test_the_rail_offers_a_donation_partner_the_money_and_not_the_stands(): void
    {
        $keys = array_column($this->railOf(), 'key');

        $this->assertContains('page', $keys);
        $this->assertContains('donations', $keys);
        $this->assertContains('payouts', $keys);
        $this->assertNotContains('catalogue', $keys);
        $this->assertNotContains('fees', $keys);
    }

    /** A vendor gets the stands and not the appeals. */
    public function test_the_rail_offers_a_vendor_the_stands_and_not_the_appeals(): void
    {
        $this->vendor();
        $keys = array_column($this->railOf(), 'key');

        $this->assertContains('stands', $keys);
        $this->assertContains('catalogue', $keys);
        $this->assertNotContains('appeals', $keys,
            'a vendor shown Appeals is being told they may run charitable appeals, '
            . 'which OrgCampaign will refuse');
        $this->assertNotContains('donations', $keys);
    }

    /**
     * A VENDOR WHO LATER TAKES DONATIONS GETS BOTH.
     *
     * Two facts, not one `kind` — the reason `OrgRail::offers()` takes `is_vendor` AND
     * `has_money` rather than branching on what somebody signed up as.
     */
    public function test_a_vendor_who_later_takes_donations_gets_both(): void
    {
        $this->vendor();
        $this->gift();
        $keys = array_column($this->railOf(), 'key');

        $this->assertContains('donations', $keys);
        $this->assertContains('stands', $keys);
        $this->assertNotContains('fees', $keys, 'they have incoming money now');
    }

    /**
     * A TAB THIS ORGANISATION IS NOT OFFERED IS NOT A BROKEN PAGE.
     *
     * The rail and the page ask the same function, so a tab can never lead to a section
     * that refuses to draw — and a stale or typed `?tab=` lands on the overview rather
     * than on an empty column.
     */
    public function test_a_section_this_organisation_has_no_business_in_falls_back(): void
    {
        $this->vendor();
        $html = $this->prose($this->page('appeals'));

        $this->assertStringNotContainsString('Start an appeal', $html);
        // The overview's own content instead: the state panel is always drawn there.
        $this->assertStringContainsString('org-state', $html);
    }

    /** @return list<array<string,mixed>> */
    private function railOf(): array
    {
        $html = $this->page();
        $out = [];
        foreach (OrgRail::SECTIONS as $k => $_) {
            // The rail renders each offered section as a link to its own address.
            $href = $k === 'overview' ? '/org' : '/org?tab=' . $k;
            if (str_contains($html, 'href="' . $href . '"')) $out[] = ['key' => $k];
        }

        return $out;
    }

    // ══ the money, and the offer that must not outrun the form ═══════════════

    /**
     * THE FIGURES OPEN THE CONSOLE.
     *
     * An organisation signs in to find out what has arrived. Putting that behind a section
     * answers a question they did not ask first.
     */
    public function test_the_money_is_on_the_first_screen_and_not_behind_the_rail(): void
    {
        $this->gift(80_000);
        $html = $this->prose($this->page());

        $this->assertStringContainsString('Reached you, after fees', $html);
        $this->assertStringContainsString('org-figs', $html);
    }

    /**
     * THE ONE THAT MATTERS: THE OFFER AND THE FORM AGREE, IN EVERY STATE.
     *
     * A console that offers a payout the next screen then refuses wastes somebody's
     * afternoon and reads as the platform holding their money. Both read `org_can_request`,
     * computed once — so this walks the states and requires the two to move together.
     */
    public function test_the_lede_never_offers_a_payout_the_form_would_refuse(): void
    {
        // Below the floor: neither offers.
        $this->gift(OrgPayout::MIN_NAIRA - 1_000);
        $this->assertStringNotContainsString('Request a payout', $this->prose($this->page()),
            'offered a payout on a balance under the minimum');
        $this->assertStringNotContainsString('name="amount"', $this->page('payouts'),
            'the form was drawn for a balance the service would refuse');

        // Over the floor, approved, owner: both offer.
        $this->gift(OrgPayout::MIN_NAIRA * 3);
        $this->assertStringContainsString('Request a payout', $this->prose($this->page()));
        $this->assertStringContainsString('name="amount"', $this->page('payouts'));

        // Not approved: neither offers, and the reason is SAID rather than the form being
        // quietly absent.
        DB::table('gates_partner_orgs')->where('id', $this->orgId)->update(['status' => 'suspended']);
        $this->assertStringNotContainsString('Request a payout', $this->prose($this->page()));
        $this->assertStringNotContainsString('name="amount"', $this->page('payouts'));
    }

    /**
     * A CONSOLE WITH NO MONEY DOES NOT DRAW FOUR ZEROES AT SOMEBODY.
     *
     * Four ₦0 tiles tell an organisation still waiting on its first gift nothing at all.
     * The space says what to do instead — and what it points at is the page, because that
     * is the thing a donor actually sees.
     */
    public function test_an_organisation_with_no_gifts_is_not_shown_a_wall_of_zeroes(): void
    {
        $html = $this->prose($this->page());

        $this->assertStringNotContainsString('Reached you, after fees', $html);
        $this->assertStringContainsString('Nothing has arrived yet', $html);
        $this->assertStringContainsString('/org?tab=page', $html);
    }

    // ══ what the console must not do ═════════════════════════════════════════

    /**
     * A VIEWER IS NOT SHOWN CONTROLS THEY CANNOT USE.
     *
     * Permissions are visible UI, not only a server check — and the caveat has to be ABOVE
     * the buttons it governs, because on a phone there is no rail to put it in.
     */
    public function test_a_view_only_account_is_told_so_and_offered_nothing_to_press(): void
    {
        DB::table('gates_org_users')->where('id', $this->userId)->update(['role' => 'viewer']);
        $this->gift(OrgPayout::MIN_NAIRA * 3);

        $over = $this->prose($this->page());
        $this->assertStringContainsString('view-only access', $over);
        $this->assertStringNotContainsString('Request a payout', $over);

        $this->assertStringNotContainsString('Save your page', $this->page('page'));
    }

    /**
     * NOTHING THE RAIL SAYS ABOUT THE ACCOUNT IS A LITERAL.
     *
     * The design prints "Verified organisation" under the name. As a constant it says that
     * beside a SUSPENDED account, on the console whose first panel exists to explain the
     * suspension.
     */
    public function test_the_rail_does_not_call_a_suspended_account_verified(): void
    {
        DB::table('gates_partner_orgs')->where('id', $this->orgId)->update(['status' => 'suspended']);
        $html = $this->prose($this->page());

        $this->assertStringContainsString('Suspended', $html);
        $this->assertStringNotContainsString('Verified organisation', $html);
    }

    /**
     * THE SECTIONS NEED NO JAVASCRIPT.
     *
     * Each is an address, so the server draws one section and there is nothing to switch.
     * A console that hides its sections behind a script is a console that is blank for
     * anybody whose script failed to load — on the screen money moves from.
     */
    public function test_the_section_switch_needs_no_framework_and_no_javascript(): void
    {
        $this->gift();
        $donations = $this->page('donations');

        $this->assertStringContainsString('A Supporter', $donations,
            'the section content is not in the server\'s own response');
        $this->assertStringNotContainsString('display:none', $donations);
    }
}
