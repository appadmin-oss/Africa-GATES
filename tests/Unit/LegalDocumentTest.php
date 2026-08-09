<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Illuminate\Database\Capsule\Manager as DB;
use AfricaGates\Services\LegalDocument as L;
use AfricaGates\Support\DocText;

/**
 * The terms and the privacy policy as documents a reader can keep.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE ONE THAT MATTERS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * On /privacy the rendered page is NOT `body_html`. It is the authored body PLUS an
 * automated-processing disclosure generated from the AI capability registry — a
 * section that exists precisely because hand-writing "we send nomination text to a
 * third-party model" into an editable field would go stale the first time a
 * capability changed.
 *
 * Adding a Download button to that page therefore had a trap in it: run the
 * converter over `body_html` and ship it, and every downloaded privacy policy
 * silently omits the AI section — the one section somebody most plausibly
 * downloaded the document in order to keep. `test_the_privacy_download_carries_the
 * _generated_ai_disclosure` is the guard, and it is the reason the disclosure moved
 * out of the template and into {@see L::bodyHtml()}.
 */
final class LegalDocumentTest extends TestCase
{
    private const TERMS_HTML = '<h2>Who we are</h2><p>Operated by <strong>Afrovanguard</strong>&rsquo;s '
        . 'team &mdash; Lagos.</p><h2>Voting</h2><ul><li>Free voting needs a code.</li>'
        . '<li>Contributions are participatory.</li></ul><ol><li>Request a code.</li>'
        . '<li>Confirm it.</li></ol><blockquote>Money cannot buy an award.</blockquote>';

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([['terms', 'Terms of Participation', self::TERMS_HTML, 1],
                  ['privacy', 'Privacy Policy', '<h2>What we collect</h2><p>A hash of your email.</p>', 2]] as [$slug, $title, $body, $o]) {
            DB::table('gates_legal_docs')->updateOrInsert(['slug' => $slug], [
                'title' => $title, 'body_html' => $body, 'updated_label' => '8 August 2026',
                'is_published' => 1, 'sort_order' => $o, 'updated_at' => '2026-08-08 10:00:00',
            ]);
        }
    }

    /** @return array{0:int,1:string,2:\Psr\Http\Message\ResponseInterface} */
    private function get(string $path): array
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        AppFactory::setContainer($builder->build());
        $app = AppFactory::create();
        (require dirname(__DIR__, 2) . '/src/routes.php')($app);

        $res = $app->handle((new ServerRequestFactory())->createServerRequest('GET', $path));
        return [$res->getStatusCode(), (string) $res->getBody(), $res];
    }

    private function doc(string $slug): array
    {
        return (array) DB::table('gates_legal_docs')->where('slug', $slug)->first();
    }

    // ── THE GUARD ───────────────────────────────────────────────────────────

    /**
     * A privacy policy you can download must contain the AI section.
     *
     * This is the whole reason the disclosure is built in PHP rather than in Twig.
     */
    public function test_the_privacy_download_carries_the_generated_ai_disclosure(): void
    {
        $doc = $this->doc('privacy');

        foreach (['text' => L::plainText($doc, 'https://x.test/privacy'),
                  'markdown' => L::markdown($doc, 'https://x.test/privacy')] as $what => $body) {
            // Case-insensitive: the .txt edition upper-cases its headings, so the
            // section title arrives as "AUTOMATED PROCESSING (AI)" there and in
            // sentence case in the Markdown.
            $this->assertMatchesRegularExpression('/automated processing/i', $body,
                "the {$what} edition of the privacy policy has no AI disclosure");
            $this->assertStringContainsString('advisory', $body,
                "the {$what} edition dropped the 'a person decides' guarantee");
            $this->assertStringContainsString('never sent to a model at all', $body,
                "the {$what} edition dropped the contact-details guarantee");
            $this->assertStringContainsString('A hash of your email', $body,
                "the {$what} edition lost the authored body");
        }
    }

    /** And the page shows the same thing, from the same builder. */
    public function test_the_page_and_the_download_come_from_one_source(): void
    {
        [$status, $html] = $this->get('/privacy');

        $this->assertSame(200, $status);
        $this->assertStringContainsString('Automated processing', $html);
        // Same anchor the .txt heading came from, so the two are provably one source.
        $this->assertStringContainsString('id="automated-processing"', $html);
    }

    /** The terms are not the privacy policy and must not grow an AI section. */
    public function test_the_terms_carry_no_ai_disclosure(): void
    {
        $body = L::plainText($this->doc('terms'), 'https://x.test/terms');

        $this->assertStringNotContainsString('Automated processing', $body);
        $this->assertStringContainsString('WHO WE ARE', $body, 'but the terms still render');
    }

    // ── DOWNLOADS ───────────────────────────────────────────────────────────

    public function test_both_legal_downloads_are_served_as_attachments(): void
    {
        foreach ([['/terms.txt', 'text/plain'], ['/terms.md', 'text/markdown'],
                  ['/privacy.txt', 'text/plain'], ['/privacy.md', 'text/markdown']] as [$path, $type]) {
            [$status, $body, $res] = $this->get($path);

            $this->assertSame(200, $status, "{$path} did not resolve");
            $this->assertStringContainsString($type, $res->getHeaderLine('Content-Type'), $path);
            // Without this a browser renders Markdown inline and the Download button
            // downloads nothing.
            $this->assertStringContainsString('attachment', $res->getHeaderLine('Content-Disposition'), $path);
            $this->assertNotSame('', trim($body), "{$path} served an empty document");
        }
    }

    /**
     * `/legal/{slug}` uses a placeholder, and Slim's default placeholder matches any
     * run of non-slash characters — so `/legal/terms.txt` would be swallowed as a
     * slug and render the PAGE unless the file routes are declared first. This is a
     * route-ordering test disguised as a content test.
     */
    public function test_a_dotted_path_serves_a_file_and_not_the_page(): void
    {
        [$status, $body, $res] = $this->get('/legal/terms.txt');

        $this->assertSame(200, $status);
        $this->assertStringContainsString('text/plain', $res->getHeaderLine('Content-Type'));
        $this->assertStringNotContainsString('<!DOCTYPE html>', $body,
            '/legal/terms.txt rendered the page — the {slug} route swallowed the extension');
    }

    public function test_an_unknown_document_is_a_404_in_every_format(): void
    {
        foreach (['/legal/nope', '/legal/nope.txt', '/legal/nope.md'] as $path) {
            [$status] = $this->get($path);
            $this->assertSame(404, $status, "{$path} should not exist");
        }
    }

    // ── CITATION ────────────────────────────────────────────────────────────

    /**
     * A policy is cited by effective date, not by semver, and the date must come from
     * `updated_at` rather than from `updated_label` — the label is free text an
     * administrator typed and may not parse at all.
     */
    public function test_a_policy_is_cited_by_its_effective_date(): void
    {
        $doc = $this->doc('terms');
        $this->assertSame('2026-08-08', L::effectiveDate($doc));

        $cites = array_column(L::citations($doc, 'https://x.test/terms', '2026-09-01'), 'text', 'id');

        $this->assertStringContainsString('2026-08-08', $cites['apa'], 'the version IS the date');
        $this->assertStringContainsString('1 September 2026', $cites['apa'], 'and the access date is separate');
        $this->assertStringContainsString('@misc{africagates2026terms', $cites['bibtex']);
        $this->assertStringContainsString('urldate      = {2026-09-01}', $cites['bibtex']);
    }

    public function test_an_unparseable_label_cannot_break_a_citation(): void
    {
        $doc = $this->doc('terms');
        $doc['updated_label'] = 'whenever we get round to it';

        // The label is decorative; the date still resolves from updated_at.
        $this->assertSame('2026-08-08', L::effectiveDate($doc));
        $this->assertStringContainsString('2026-08-08', L::citations($doc, 'https://x.test/terms')[0]['text']);
    }

    public function test_the_filename_carries_the_effective_date(): void
    {
        $this->assertSame('africa-gates-terms-2026-08-08', L::fileStem($this->doc('terms')));
    }

    // ── OUTLINE / ANCHORS ───────────────────────────────────────────────────

    public function test_the_contents_are_built_from_the_documents_own_headings(): void
    {
        $outline = L::outline($this->doc('terms'));

        $this->assertSame(['Who we are', 'Voting'], array_column($outline, 'title'));
        $this->assertSame(['who-we-are', 'voting'], array_column($outline, 'id'));
    }

    /** Every heading the contents links to must have that id in the body. */
    public function test_every_contents_entry_has_a_target_in_the_body(): void
    {
        $doc  = $this->doc('privacy');
        $body = L::bodyWithAnchors($doc);

        foreach (L::outline($doc) as $entry) {
            $this->assertStringContainsString('id="' . $entry['id'] . '"', $body,
                "contents links to #{$entry['id']} but no heading carries it");
        }
    }

    /**
     * Anchors must FOLD accented letters, not delete them.
     *
     * A local `[^a-z0-9]+` replacement would turn "Frais et rémunération" into
     * `frais-et-r-mun-ration`. Slug::make() folds, and SlugTest fails the build on
     * any file that reintroduces the ASCII version — which is how the first draft of
     * this class was caught.
     */
    public function test_an_accented_heading_keeps_its_letters(): void
    {
        $doc = ['slug' => 'terms', 'title' => 'T', 'updated_at' => '2026-08-08 10:00:00',
                'body_html' => '<h2>Frais et rémunération</h2><p>x</p><h2>Côte d’Ivoire</h2><p>y</p>'];

        // `cote-d-ivoire`, not `cote-divoire`: Slug::make treats the apostrophe as a
        // separator. What matters is that the é and the ô are FOLDED to e and o
        // rather than deleted — an ASCII-class replacement would have produced
        // `frais-et-r-mun-ration`.
        $this->assertSame(
            ['frais-et-remuneration', 'cote-d-ivoire'],
            array_column(L::outline($doc), 'id')
        );
    }

    /** Two headings with the same words still get distinct ids. */
    public function test_duplicate_headings_do_not_collide(): void
    {
        $doc = ['slug' => 'terms', 'title' => 'T', 'updated_at' => '2026-08-08 10:00:00',
                'body_html' => '<h2>Fees</h2><p>a</p><h2>Fees</h2><p>b</p>'];

        $this->assertSame(['fees', 'fees-2'], array_column(L::outline($doc), 'id'));
    }

    /**
     * An id already present on a heading is kept rather than recomputed.
     *
     * An AUTHOR cannot supply one: Html::sanitize() strips `id` from the editable
     * body, which this test asserts so nobody spends an afternoon wondering why. The
     * case the branch actually protects is the GENERATED disclosure, which writes its
     * own `id="automated-processing"` and is appended after sanitising — and which
     * external links and the AI-notice on the nominate form already point at.
     */
    public function test_an_existing_heading_id_is_kept_rather_than_recomputed(): void
    {
        $authored = ['slug' => 'terms', 'title' => 'T', 'updated_at' => '2026-08-08 10:00:00',
                     'body_html' => '<h2 id="mine">Fees</h2>'];

        $this->assertStringNotContainsString('id="mine"', L::bodyWithAnchors($authored),
            'the sanitizer strips ids from authored markup — so this one is expected to go');
        $this->assertStringContainsString('id="fees"', L::bodyWithAnchors($authored));

        // The generated section keeps the id it shipped with, and the contents agree.
        $privacy = $this->doc('privacy');
        $this->assertStringContainsString('id="automated-processing"', L::bodyWithAnchors($privacy));
        $this->assertContains('automated-processing', array_column(L::outline($privacy), 'id'),
            'the contents must link to the id the body actually carries');
        $this->assertNotContains('automated-processing-ai', array_column(L::outline($privacy), 'id'));
    }

    // ── THE SEEDED CONTENT ──────────────────────────────────────────────────

    /**
     * THE GUARD ON THE PROSE.
     *
     * The seeded terms and privacy policy are STATIC HTML in a database column.
     * There is no token resolution on the way out — unlike the philosophy, which
     * resolves `{community_pct}` on every render — so a percentage typed into this
     * content freezes at the moment it was written while RuleEngine moves on.
     *
     * A stale figure in the terms is materially worse than one on a marketing page,
     * because the terms are the document a disputed result gets argued against. So
     * both documents describe the mechanism and point at /integrity for the numbers,
     * and this test fails the build if anybody pastes a figure in.
     */
    public function test_the_seeded_legal_content_states_no_percentages(): void
    {
        foreach (\AfricaGates\Services\LegalSeeder::documents() as $slug => $doc) {
            preg_match_all('/(?<![\d])(\d{1,3}(?:\.\d+)?)\s?%/', $doc['body'], $m);

            $this->assertSame([], $m[1],
                "the seeded {$slug} has a hardcoded percentage (" . implode(', ', $m[1]) . '%) — '
                . 'this content cannot resolve tokens, so it must point at /integrity instead');
        }
    }

    /** And it does point there, so the guard above is not satisfied by silence. */
    public function test_the_seeded_terms_send_the_reader_to_the_live_figures(): void
    {
        $terms = \AfricaGates\Services\LegalSeeder::documents()['terms']['body'];

        $this->assertStringContainsString('href="/integrity"', $terms,
            'the terms describe a weighting without saying where the number is');
        $this->assertStringContainsString('href="/philosophy"', $terms);
    }

    /**
     * The seeded privacy policy must NOT contain its own AI section.
     *
     * LegalDocument appends one generated from the capability registry. A
     * handwritten section would produce two — and the handwritten one would be the
     * copy that went stale the first time a capability changed, which is the exact
     * failure the generated section exists to prevent.
     */
    public function test_the_seeded_privacy_policy_does_not_write_its_own_ai_section(): void
    {
        $seeded = \AfricaGates\Services\LegalSeeder::documents()['privacy']['body'];

        $this->assertStringNotContainsString('Automated processing', $seeded,
            'the privacy body writes an AI section by hand; LegalDocument already appends one');

        // And on the assembled document there is exactly one.
        $assembled = L::bodyWithAnchors($this->doc('privacy'));
        $this->assertSame(1, substr_count($assembled, 'id="automated-processing"'),
            'the privacy page must carry exactly one automated-processing section');
    }

    /** Every tag used in the seeded content must survive the sanitizer. */
    public function test_the_seeded_content_is_not_stripped_by_the_sanitizer(): void
    {
        foreach (\AfricaGates\Services\LegalSeeder::documents() as $slug => $doc) {
            $before = $doc['body'];
            $after  = \AfricaGates\Support\Html::sanitize($before);

            foreach (['<h2', '<h3', '<p>', '<ul>', '<ol>', '<li>', '<strong>', '<em>', '<a ', '<code>'] as $tag) {
                if (str_contains($before, $tag)) {
                    $this->assertStringContainsString($tag, $after,
                        "{$slug}: the sanitizer removed {$tag} — it is not on Html::ALLOWED");
                }
            }
            // A sanitizer that ate a third of a legal document is a bug worth catching
            // even when every individual tag survived.
            $this->assertGreaterThan(strlen($before) * 0.9, strlen($after),
                "{$slug}: sanitising lost more than a tenth of the document");
        }
    }

    // ── THE HTML → TEXT CONVERTER ───────────────────────────────────────────

    /**
     * `strip_tags` alone would concatenate a whole policy into one wall with no
     * boundaries — a legal document rendered unreadable at exactly the moment
     * somebody wanted to keep a copy of it. So the block structure has to survive.
     */
    public function test_block_structure_survives_the_conversion_to_text(): void
    {
        $text = DocText::toText(self::TERMS_HTML);

        $this->assertStringContainsString("WHO WE ARE\n----------", $text, 'h2 needs an underline');
        $this->assertStringContainsString('  - Free voting needs a code.', $text, 'ul takes dashes');
        $this->assertStringContainsString('  1. Request a code.', $text, 'ol takes numbers');
        $this->assertStringContainsString('  2. Confirm it.', $text, 'and they count up');
        $this->assertStringContainsString('"Money cannot buy an award."', $text, 'blockquote is quoted');
        // Decoded, not leaked: a clipboard holding a literal &rsquo; is a broken paste.
        $this->assertStringContainsString('Afrovanguard’s team — Lagos', $text);
        $this->assertStringNotContainsString('&rsquo;', $text);
        $this->assertStringNotContainsString('<strong>', $text);
    }

    public function test_block_structure_survives_the_conversion_to_markdown(): void
    {
        $md = DocText::toMarkdown(self::TERMS_HTML);

        $this->assertStringContainsString('## Who we are', $md);
        $this->assertStringContainsString('- Free voting needs a code.', $md);
        $this->assertStringContainsString('1. Request a code.', $md);
        $this->assertStringContainsString('> Money cannot buy an award.', $md);
    }

    /** A <br> inside a paragraph is the author's line break; dropping it runs two lines together. */
    public function test_a_line_break_is_a_boundary_not_a_space(): void
    {
        $text = DocText::toText('<p>Line one.<br>Line two.</p>');

        $this->assertStringContainsString('Line one.', $text);
        $this->assertStringContainsString('Line two.', $text);
        $this->assertStringNotContainsString('Line one.Line two.', $text);
    }

    /** A .txt nobody can read in a terminal is not a plain-text edition. */
    public function test_the_text_edition_is_wrapped(): void
    {
        $body = L::plainText($this->doc('privacy'), 'https://x.test/privacy');

        foreach (explode("\n", $body) as $i => $line) {
            $this->assertLessThanOrEqual(80, mb_strlen($line),
                'line ' . ($i + 1) . ' is unwrapped: ' . $line);
        }
    }

    public function test_empty_input_does_not_produce_furniture(): void
    {
        $this->assertSame('', trim(DocText::toText('')));
        $this->assertSame('', trim(DocText::toMarkdown('')));
    }
}
