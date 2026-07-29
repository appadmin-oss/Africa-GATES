<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\AiPrivacy;
use AfricaGates\Services\AiCapability;
use AfricaGates\Services\AiGateway;
use AfricaGates\Services\AiService;

/**
 * Sending less, and telling the truth about what is sent.
 *
 * The AI gateway shipped with `AiPrivacy` named in its docblock and nothing
 * behind it, because the legal half of the question — lawful basis under the
 * NDPA, cross-border transfer, provider retention — needs sources. The
 * engineering half does not: a spam classifier cannot use a phone number, so a
 * phone number should not leave the process.
 *
 * The properties worth defending here are all about NOT over-reaching. Redaction
 * that eats an impact figure damages the very triage it protects, and a
 * disclosure that omits a capability is worse than no disclosure at all because
 * it reads as complete.
 */
class AiPrivacyTest extends TestCase
{
    // ── Minimisation ────────────────────────────────────────────────────────

    public function test_an_email_address_is_replaced_not_deleted(): void
    {
        // Substitution, not deletion: the PRESENCE of a contact detail is itself a
        // spam signal, so removing it silently would destroy the evidence for the
        // classification the call is making.
        $r = AiPrivacy::minimise('Reach her at ada.obi@example.com for details.');

        $this->assertSame('Reach her at [email] for details.', $r['text']);
        $this->assertSame(['email' => 1], $r['removed']);
    }

    public function test_nigerian_phone_formats_are_caught(): void
    {
        foreach (['+234 803 123 4567', '08031234567', '0803-123-4567', '(080) 3123 4567', '+2348031234567'] as $phone) {
            $r = AiPrivacy::minimise("Call {$phone} now");
            $this->assertStringNotContainsString('803', $r['text'], "not minimised: {$phone}");
        }
    }

    public function test_a_number_at_the_end_of_a_sentence_is_still_caught(): void
    {
        // The trailing full stop broke an earlier version of the pattern, which
        // then matched nothing at all — the worst possible failure mode for a
        // redaction rule, since it looks like it is working.
        foreach (['Call 08031234567.', 'Call +234 803 123 4567.', 'BVN 12345678901.'] as $sentence) {
            $this->assertFalse(AiPrivacy::containsContactDetail(AiPrivacy::minimise($sentence)['text']),
                "not minimised: {$sentence}");
            $this->assertStringNotContainsString('1234567', AiPrivacy::minimise($sentence)['text'],
                "not minimised: {$sentence}");
        }
    }

    public function test_an_email_containing_digits_is_not_half_eaten_by_the_phone_rule(): void
    {
        // Rule order is load-bearing: a numeric local part would otherwise be
        // partly consumed, leaving a mangled fragment of a real address behind.
        $r = AiPrivacy::minimise('ada08031234567@example.com');

        $this->assertSame('[email]', $r['text']);
        $this->assertSame(['email' => 1], $r['removed']);
    }

    public function test_years_and_impact_figures_survive(): void
    {
        // The triage prompt rewards specific verifiable impact and the form asks
        // for evidence. Redaction that eats the evidence would invert the
        // platform's own instruction to nominators.
        $text = 'Since 2019 she trained 12,000 farmers across 36 states, raising yields 45% and '
              . 'securing 250000 naira in grants.';

        $r = AiPrivacy::minimise($text);

        $this->assertSame($text, $r['text']);
        $this->assertSame([], $r['removed']);
    }

    public function test_reference_urls_survive(): void
    {
        $text = 'See https://guardian.ng/news/story-2024 and https://example.org/report.pdf';

        $this->assertSame($text, AiPrivacy::minimise($text)['text'],
            'the form asks for up to three reference links; redacting them would be self-defeating');
    }

    public function test_a_bare_identifier_is_labelled_a_number_not_a_phone(): void
    {
        // The phone pattern is deliberately broad. Left to itself it would eat a
        // bare BVN and tell the model "[phone]" about a bank identifier, so the
        // two rules stay separate and each keeps its own placeholder — otherwise
        // the second rule is dead code that only looks like a safeguard.
        $r = AiPrivacy::minimise('BVN 12345678901 please');

        $this->assertSame('BVN [number] please', $r['text']);
        $this->assertSame(['number' => 1], $r['removed']);
    }

    public function test_a_trunk_zero_run_is_read_as_a_phone_number(): void
    {
        // A leading 0 is the Nigerian trunk prefix, so this shape is a phone
        // first — which is also the more useful thing to tell a classifier.
        $r = AiPrivacy::minimise('Account 0123456789');

        $this->assertSame('Account [phone]', $r['text']);
        $this->assertSame(['phone' => 1], $r['removed']);
    }

    public function test_several_identifiers_in_one_text_are_all_counted(): void
    {
        $r = AiPrivacy::minimise('a@b.com, c@d.org, call +2348031234567');

        $this->assertSame(2, $r['removed']['email']);
        $this->assertArrayHasKey('phone', $r['removed']);
        $this->assertFalse(AiPrivacy::containsContactDetail($r['text']),
            'minimising must be idempotent — one pass leaves nothing for a second');
    }

    public function test_clean_text_is_returned_untouched(): void
    {
        $text = 'She founded a theatre collective in Ibadan and mentors young playwrights.';

        $r = AiPrivacy::minimise($text);
        $this->assertSame($text, $r['text']);
        $this->assertFalse(AiPrivacy::containsContactDetail($text));
    }

    public function test_empty_and_whitespace_input_does_not_break(): void
    {
        $this->assertSame('', AiPrivacy::minimise('')['text']);
        $this->assertSame('   ', AiPrivacy::minimise('   ')['text']);
    }

    public function test_pathological_input_leaves_the_text_intact_rather_than_empty(): void
    {
        // A regex that hits a backtrack limit returns null. Writing that null
        // through would send an EMPTY prompt to the model, turning a privacy
        // measure into a silent feature outage.
        $r = AiPrivacy::minimise(str_repeat('1', 40000));

        $this->assertNotSame('', $r['text'], 'a failed pass must never null the payload');
    }

    // ── Enforced at the door ────────────────────────────────────────────────

    /** A provider that records the assembled prompt, following AiGatewayTest. */
    private function recordingAi(): AiService
    {
        return new class extends AiService {
            public string $seen = '';
            public function __construct() { parent::__construct(groqKey: 'test-key'); }
            public function complete(string $system, string $user, int $maxTokens = 512, bool $json = false, float $temperature = 0.2, array $route = [], int $maxAttempts = 0): ?string
            {
                $this->seen = $user;
                return 'ok';
            }
            public function lastUsage(): array { return ['in' => 1, 'out' => 1]; }
        };
    }

    public function test_the_gateway_minimises_before_the_provider_sees_the_prompt(): void
    {
        // The point of the gateway: a rule applied at the single door cannot be
        // forgotten by the next feature. Asserted against the real assembled
        // prompt rather than by re-calling minimise().
        $spy = $this->recordingAi();

        (new AiGateway($spy))->run('nomination.triage', [
            'system'  => 'sys',
            'trusted' => 'Nominee: Ada Obi',
            'user'    => 'Contact her on ada@example.com or 08031234567.',
        ]);

        $this->assertStringNotContainsString('ada@example.com', $spy->seen);
        $this->assertStringNotContainsString('08031234567', $spy->seen);
        $this->assertStringContainsString('[email]', $spy->seen);
        $this->assertStringContainsString('Ada Obi', $spy->seen, 'names still go out — the feature needs them');
    }

    public function test_a_contact_detail_smuggled_into_the_trusted_block_is_also_minimised(): void
    {
        // The trusted block is platform-composed, so it should never carry a
        // contact field — but it is assembled by call sites that can change, and
        // the door is the only place that closes that drift for good.
        $spy = $this->recordingAi();

        (new AiGateway($spy))->run('nomination.triage', [
            'system'  => 'sys',
            'trusted' => 'Nominee: Ada Obi — ada@example.com',
            'user'    => 'She runs a theatre collective.',
        ]);

        $this->assertStringNotContainsString('ada@example.com', $spy->seen);
    }

    public function test_an_admin_capability_that_opts_out_keeps_its_query_intact(): void
    {
        // An admin searching for a phone number is acting deliberately on data
        // they already hold; redacting the query would simply break the search.
        $spy = $this->recordingAi();

        (new AiGateway($spy))->run('admin.filter_parse', [
            'system' => 'sys',
            'user'   => 'nominations from 08031234567',
        ]);

        $this->assertStringContainsString('08031234567', $spy->seen);
        $this->assertFalse(AiCapability::find('admin.filter_parse')->minimise,
            'and the exemption is DECLARED, not inferred from some implicit rule');
    }

    public function test_every_capability_carrying_public_text_minimises_it(): void
    {
        // The property that matters more than any single capability's setting: a
        // new feature processing public submissions must not be able to skip
        // minimisation by omission.
        foreach (AiCapability::all() as $cap) {
            if (!$cap->publicContent) continue;
            $this->assertTrue($cap->minimise,
                "{$cap->name} processes public content but does not minimise it");
        }
    }

    // ── Disclosure ──────────────────────────────────────────────────────────

    public function test_every_public_capability_appears_in_the_disclosure(): void
    {
        // A notice that omits a capability is worse than none, because it reads
        // as complete. Derived from the registry so it cannot fall behind.
        // A capability now appears under EVERY provider its route can reach, so the
        // comparison is over the distinct set of names, not the raw list. That
        // repetition is the feature: a reader has to be told the recipient for each
        // destination, and a fallback provider receives exactly the same payload.
        $listed = [];
        foreach (AiPrivacy::disclosure() as $group) {
            foreach ($group['capabilities'] as $cap) $listed[] = $cap['name'];
        }
        $listed = array_values(array_unique($listed));

        $expected = [];
        foreach (AiCapability::all() as $cap) {
            if ($cap->publicContent) $expected[] = $cap->name;
        }

        sort($listed);
        sort($expected);
        $this->assertSame($expected, $listed);
        $this->assertNotSame([], $expected, 'a disclosure listing nothing would be a bug, not a clean bill');
    }

    public function test_admin_only_capabilities_are_not_in_the_visitor_facing_notice(): void
    {
        // Listing an operator's drafting assistant would bury the part that
        // actually concerns a visitor's data.
        $listed = [];
        foreach (AiPrivacy::disclosure() as $group) {
            foreach ($group['capabilities'] as $cap) $listed[] = $cap['name'];
        }

        foreach (['admin.filter_parse', 'admin.assistant', 'admin.content_assist',
                  'admin.form_design', 'integrity.brief'] as $adminOnly) {
            $this->assertNotContains($adminOnly, $listed);
        }
    }

    public function test_the_disclosure_is_grouped_by_destination_provider(): void
    {
        // "Who receives this?" is the question a privacy notice has to answer.
        $groups = AiPrivacy::disclosure();

        $this->assertNotSame([], $groups);
        foreach ($groups as $group) {
            $this->assertArrayHasKey('provider', $group);
            $this->assertNotSame('', $group['provider']);
            $this->assertArrayHasKey('primary', $group);
            foreach ($group['capabilities'] as $cap) {
                // The group's provider must be somewhere in this capability's ROUTE,
                // not merely its pin: a capability listed under Google because Gemini
                // is its fallback is correct, and asserting equality with the pin
                // would forbid disclosing the fallback at all.
                $route = array_map(
                    static fn (string $hop): string => explode(':', $hop, 2)[0],
                    AiCapability::find($cap['name'])->route()
                );
                $this->assertContains($group['provider'], $route);
            }
        }
    }

    public function test_a_fallback_destination_is_disclosed_not_hidden(): void
    {
        // The reason the shape changed. A capability declares a pinned model and an
        // ordered fallback ladder, and the ladder runs precisely when the primary
        // provider is down — so a notice naming only the pin names the wrong
        // recipient on the days it matters. Marked non-primary so "usually OpenAI,
        // occasionally Google" stays distinguishable from "OpenAI".
        $groups = AiPrivacy::disclosure();
        $byProvider = [];
        foreach ($groups as $g) $byProvider[$g['provider']] = $g;

        $this->assertArrayHasKey('openai', $byProvider, 'the pinned provider');
        $this->assertTrue($byProvider['openai']['primary']);

        $fallbackProviders = array_filter($byProvider, static fn (array $g): bool => !$g['primary']);
        $this->assertNotSame([], $fallbackProviders,
            'every capability declares fallbacks, so at least one non-primary '
            . 'destination must be disclosed');

        // And each disclosed destination is genuinely reachable — no phantom entries.
        foreach ($groups as $g) {
            $reachable = false;
            foreach (AiCapability::all() as $cap) {
                if (!$cap->publicContent) continue;
                foreach ($cap->route() as $hop) {
                    if (explode(':', $hop, 2)[0] === $g['provider']) { $reachable = true; break 2; }
                }
            }
            $this->assertTrue($reachable, "{$g['provider']} is disclosed but no capability routes to it");
        }
    }

    public function test_provider_names_are_spelled_the_way_the_companies_spell_them(): void
    {
        // A published legal notice that says "Openai" reads as though nobody checked.
        $this->assertSame('OpenAI', AiPrivacy::providerLabel('openai'));
        $this->assertSame('Google (Gemini)', AiPrivacy::providerLabel('gemini'));
        $this->assertSame('Anthropic', AiPrivacy::providerLabel('anthropic'));
        $this->assertSame('Groq', AiPrivacy::providerLabel('groq'));
        // An unknown id still renders something rather than an empty heading.
        $this->assertSame('Somebodynew', AiPrivacy::providerLabel('somebodynew'));
    }

    public function test_every_public_capability_describes_its_data_in_plain_words(): void
    {
        // The default string exists so a missing declaration cannot crash the
        // page — but it must never reach a reader for a public capability.
        foreach (AiCapability::all() as $cap) {
            if (!$cap->publicContent) continue;
            $this->assertNotSame('Nothing submitted by the public.', $cap->dataSent,
                "{$cap->name} was added to the disclosure without describing what it sends");
            $this->assertGreaterThan(40, mb_strlen($cap->dataSent),
                "{$cap->name} — a one-word description is not a disclosure");
            $this->assertNotSame($cap->purpose, $cap->dataPurpose,
                "{$cap->name} — 'moderation'/'assist' is a code word, not an explanation for a nominator");
        }
    }

    public function test_no_public_capability_is_declared_non_advisory(): void
    {
        // The notice promises a person decides. If a capability could block, that
        // promise would be a lie told at scale.
        foreach (AiCapability::all() as $cap) {
            if (!$cap->publicContent) continue;
            $this->assertTrue($cap->advisory, "{$cap->name} is disclosed as advisory but is not declared so");
        }
    }

    public function test_the_notice_reports_whether_the_features_are_currently_on(): void
    {
        // updateOrInsert, not insert: the MySQL harness applies the dated
        // migrations, one of which SEEDS ai_enabled = 1, while the SQLite harness
        // loads schema files only. A plain insert collides on the primary key on
        // one driver and not the other — a difference in the harness, but the fix
        // is the right habit anyway: assert the value you need, don't assume the
        // row is absent.
        DB::table('gates_settings')->updateOrInsert(['key_name' => 'ai_enabled'], ['value' => '0']);

        $this->assertFalse(AiPrivacy::currentlyActive(),
            'with the master switch off, the page must not claim text is being sent');
        $this->assertNotSame([], AiPrivacy::disclosure(),
            'but the disclosure itself stays — a notice that vanishes with a toggle would mislead '
            . 'anyone who read it on a quiet day');
    }

    // ── The published page ──────────────────────────────────────────────────

    /** Render pages/legal.twig through the app's real Twig, as /privacy does. */
    private function renderPrivacy(array $extra = []): string
    {
        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $twig = $builder->build()->get(\Slim\Views\Twig::class);

        return $twig->fetch('pages/legal.twig', array_merge([
            'legal_doc'  => ['slug' => 'privacy', 'title' => 'Privacy', 'body_html' => '<p>Body.</p>',
                             'updated_label' => '1 May 2025'],
            'legal_tabs' => [['slug' => 'privacy', 'title' => 'Privacy']],
            'ai_disclosure'        => AiPrivacy::disclosure(),
            'ai_disclosure_active' => AiPrivacy::currentlyActive(),
        ], $extra));
    }

    public function test_the_privacy_page_actually_renders_the_disclosure(): void
    {
        // The data being right is not the same as the page showing it. Twig's
        // non-strict mode renders a missing variable as empty, so a disclosure
        // wired to the wrong key would silently show nothing at all — which is
        // the exact class of bug this codebase has been bitten by repeatedly.
        $html = $this->renderPrivacy();

        $this->assertStringContainsString('Automated processing', $html);

        // Asserted against the REGISTRY, not against a vendor name. Hardcoding
        // 'Sent to Groq' meant retargeting the capabilities to GPT broke a privacy
        // test for no privacy reason, which teaches the next person to edit the
        // expectation rather than read it.
        foreach (\AfricaGates\Services\AiPrivacy::disclosure() as $group) {
            $this->assertStringContainsString('Sent to ' . $group['label'], $html,
                'every destination the registry names must appear on the page');
        }
        // And the spelling is the company's, not ucfirst()'s.
        $this->assertStringNotContainsString('Sent to Openai', $html);
        $this->assertStringContainsString('[email]', $html, 'the placeholder is named so a reader knows what to expect');
        $this->assertStringContainsString('never sent', $html, 'nominee contact fields are stated as never sent');
    }

    public function test_the_page_says_names_are_sent_rather_than_implying_otherwise(): void
    {
        $this->assertStringContainsString('Names', $this->renderPrivacy(),
            'a notice that omits the one obvious identifier would be the misleading kind of true');
    }

    public function test_the_page_admits_what_is_not_known_about_provider_retention(): void
    {
        // The alternative was an assurance nobody had verified. An admitted gap
        // is worth more on a privacy page than a comfortable sentence.
        $html = $this->renderPrivacy();

        $this->assertStringContainsString('What we cannot yet tell you', $html);
        $this->assertStringContainsString('train their models', $html);
    }

    public function test_the_other_legal_documents_carry_no_ai_section(): void
    {
        $html = $this->renderPrivacy([
            'legal_doc'     => ['slug' => 'terms', 'title' => 'Terms', 'body_html' => '<p>Body.</p>'],
            'ai_disclosure' => [],
        ]);

        $this->assertStringNotContainsString('Automated processing', $html);
        $this->assertStringContainsString('Body.', $html, 'and the document itself still renders');
    }

    public function test_the_disclosure_text_is_escaped_not_injected(): void
    {
        // The strings are developer-authored today, but they land in a public page
        // through a loop; autoescape must be doing its job on them.
        $html = $this->renderPrivacy([
            'ai_disclosure' => [[
                'provider'     => 'groq',
                'capabilities' => [[
                    'name' => 'x', 'purpose' => '<script>alert(1)</script>',
                    'sends' => 'text', 'minimised' => true, 'advisory' => true,
                ]],
            ]],
        ]);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_the_nominate_form_discloses_at_the_point_of_collection(): void
    {
        // Burying "we send this to a third party" in a policy page nobody opens is
        // disclosure in name only. The notice belongs beside the button that
        // sends the text, and it must link to the generated detail rather than
        // restate it — a second copy of the facts is a second thing to drift.
        DB::table('gates_award_programmes')->insert([
            'id' => 1, 'slug' => 'gates', 'title' => 'GATES Awards', 'is_active' => 1, 'sort_order' => 1,
            'description' => 'A programme.',
        ]);
        DB::table('gates_award_cycles')->insert([
            'id' => 1, 'programme_id' => 1, 'year' => (int) date('Y'), 'status' => 'nominations',
            'nominations_open'  => date('Y-m-d H:i:s', strtotime('-1 day')),
            'nominations_close' => date('Y-m-d H:i:s', strtotime('+10 days')),
        ]);
        DB::table('gates_award_categories')->insert([
            'id' => 1, 'cycle_id' => 1, 'slug' => 'music', 'title' => 'Music', 'sort_order' => 1,
        ]);

        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        $ctrl = $builder->build()->get(\AfricaGates\Controllers\NominationController::class);
        $req  = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/nominate');
        $html = (string) $ctrl->form($req, new \Slim\Psr7\Response())->getBody();

        $this->assertStringContainsString('third-party AI service', $html);
        $this->assertStringContainsString('replaced with placeholders', $html);
        $this->assertStringContainsString('/privacy#automated-processing', $html,
            'and it must point at the generated section, whose anchor therefore has to exist');
        $this->assertStringContainsString('id="automated-processing"', $this->renderPrivacy(),
            'the anchor the nominate form links to');
    }
}
