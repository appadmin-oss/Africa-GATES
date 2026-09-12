<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\GivingUrl;
use AfricaGates\Services\OrgBrand;
use AfricaGates\Support\Csp;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * AN ORGANISATION'S OWN PAGE — BOTH HALVES OF IT.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS WRONG, AND WHY IT NEEDED A TEST FILE OF ITS OWN
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `OrgBrand` shipped complete on every side except the two that face a human being. It
 * had a validated writer, a route at `POST /org/brand`, an image uploader, an accent
 * refused for failing contrast against white with the ratio in the refusal, and `css()`
 * to turn the whole document into custom properties.
 *
 * And `css()` had NO CALLER. `DonationController` never mentioned the service, so the
 * `/gift/{slug}` page the feature exists for rendered none of it. `pages/org/dashboard.twig`
 * was handed `brand` and `brand_sections` and contained the word "brand" ZERO times, so
 * there was no form — and `saveBrand()` redirected to `#brand`, an anchor on no page.
 *
 * Meanwhile the migration's own docblock described it as shipped: "what the page LOOKS
 * like belongs to whoever is doing the asking." That is §17's shape in its most expensive
 * variant — prose promising a behaviour with a schema behind it and no reader — combined
 * with §18's, a mechanism with no route in.
 *
 * So the first two tests here are not about behaviour at all. They assert that a form
 * exists and that a renderer is reached, because a passing test over a service nobody can
 * reach is exactly the state this shipped in. The distinguishing question from the
 * `manageUrl()` case is the same one: not "does this work?" — every piece did — but
 * **who is ever handed this?**
 */
final class OrgPageTest extends TestCase
{
    private int $orgId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orgId = (int) DB::table('gates_partner_orgs')->insertGetId([
            'slug' => 'read-naija', 'name' => 'Read Naija', 'status' => 'approved',
            'contact_name' => 'A Person', 'contact_email' => 'org@example.test',
        ]);
    }

    /** The form's POST body, as the browser sends it: parallel arrays per block. */
    private function post(array $over = []): array
    {
        return $over + [
            'accent' => '#1a6118',
            'tagline' => 'Books in every classroom',
            'story' => "We started in one school.\n\nToday we are in forty-one.",
            'website' => 'https://readnaija.example/about',
            'section_story' => '1', 'section_impact' => '1', 'section_video' => '1',
            'section_links' => '1', 'section_faq' => '1', 'section_asks' => '1',
            'link_label' => ['Our 2026 annual report'],
            'link_url'   => ['https://readnaija.example/annual.pdf'],
            'video_title' => ['A day at Ikorodu'],
            'video_url'   => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            'impact_figure' => ['12,400'], 'impact_label' => ['books placed in 2026'],
            'asks_amount' => ['NGN 25,000'], 'asks_buys' => ['a class set for one term'],
            'faq_q' => ['How much reaches the work?'], 'faq_a' => ['Ninety-one per cent.'],
        ];
    }

    private function org(): object
    {
        return (object) ((array) DB::table('gates_partner_orgs')->where('id', $this->orgId)->first());
    }

    /**
     * The real renderer, rendered.
     *
     * The partial rather than `donate.twig`, because the whole page needs globals a unit
     * test has no business booting — and `test_the_donation_page_includes_the_renderer`
     * below is what stops that being a way to test a file nothing includes.
     */
    private function render(?array $brandOver = null): string
    {
        $org   = $this->org();
        $brand = $brandOver ?? OrgBrand::of($org);

        $twig = new \Twig\Environment(
            new \Twig\Loader\FilesystemLoader(dirname(__DIR__, 2) . '/templates'),
            ['strict_variables' => true]);
        $twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $p): string => $p));

        return $twig->render('partials/org-page.twig', [
            'org' => $org, 'brand' => $brand,
            'brand_css' => OrgBrand::css($brand),
            'story_paragraphs' => OrgBrand::paragraphs((string) $brand['story']),
            'gates_credit' => OrgBrand::GATES_CREDIT,
        ]);
    }

    // ══ the two halves that did not exist ════════════════════════════════════

    /**
     * THERE IS A FORM, AND IT POSTS WHERE THE SERVICE LISTENS.
     *
     * The `#brand` anchor `saveBrand()` has always redirected to must exist, or a
     * successful save returns somebody to the top of a long dashboard with a flash
     * message and no idea which screen it came from.
     */
    public function test_the_dashboard_has_a_form_that_reaches_the_service(): void
    {
        $tpl = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/org/dashboard.twig');

        $this->assertStringContainsString('action="/org/brand"', $tpl,
            'the route has always existed; the form is what did not');
        $this->assertStringContainsString('name="_token"', $tpl, 'and it carries CSRF');
        $this->assertStringContainsString('id="page"', $tpl,
            'saveBrand() redirects to an anchor, which has to be on the page');

        // ── THE FIELD NAMES ARE DERIVED, NOT TYPED ──────────────────────────
        //
        // The form emits `name="{{ name }}_{{ spec.a }}[]"` from the same table
        // `OrgBrand::blockFrom()` derives the names it reads. That is the whole reason
        // the spec is a table: two hand-written lists claiming the same thing in the
        // same words is the shape this codebase has paid for twice, and here it would
        // fail silently — a field named `impact_figures` posts happily, is never read,
        // and the organisation saves with no error and finds the block empty.
        //
        // So this asserts the DERIVATION is still what generates them. The round-trip in
        // test_every_block_survives_the_form is what proves the two ends actually meet.
        $this->assertStringContainsString('name="{{ name }}_{{ spec.a }}[]"', $tpl,
            'block field names must be derived from the spec, never typed per block');
        $this->assertStringContainsString('name="{{ name }}_{{ spec.b }}[]"', $tpl);
        foreach (['link_label[]', 'link_url[]', 'video_url[]', 'video_title[]'] as $f) {
            $this->assertStringContainsString('name="' . $f . '"', $tpl);
        }
    }

    /**
     * EVERY BLOCK SURVIVES A ROUND TRIP THROUGH ITS DERIVED FIELD NAMES.
     *
     * The assertion that the form and the writer meet. Built from the spec, so a block
     * added to `OrgBrand::BLOCKS` is covered here the moment it exists — and a block
     * whose reader and writer disagree fails on the row it drops.
     */
    public function test_every_block_survives_the_form(): void
    {
        $post = ['accent' => '#1a6118'];
        foreach (OrgBrand::BLOCKS as $name => $spec) {
            $post['section_' . $name]          = '1';
            $post[$name . '_' . $spec['a']]    = ['A-' . $name];
            $post[$name . '_' . $spec['b']]    = ['B-' . $name];
        }

        $r = OrgBrand::save($this->orgId, $post);
        $this->assertTrue($r['ok'], (string) $r['message']);

        $blocks = OrgBrand::of($this->org())['blocks'];
        $html   = $this->render();

        foreach (OrgBrand::BLOCKS as $name => $spec) {
            $this->assertCount(1, $blocks[$name], "{$name} did not survive the form");
            $this->assertSame('A-' . $name, $blocks[$name][0][$spec['a']]);
            $this->assertSame('B-' . $name, $blocks[$name][0][$spec['b']]);
            $this->assertStringContainsString('A-' . $name, $html,
                "{$name} was stored but never drawn");
        }
    }

    /**
     * AND THE DONATION PAGE ACTUALLY INCLUDES THE RENDERER.
     *
     * Without this, every other assertion in this file is about a template no request
     * ever reaches — which is the fault being fixed, one level up.
     */
    public function test_the_donation_page_includes_the_renderer(): void
    {
        $tpl = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/donate.twig');
        $this->assertStringContainsString("include 'partials/org-page.twig'", $tpl);

        $ctl = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controllers/DonationController.php');
        $this->assertStringContainsString('OrgBrand::of($org)', $ctl,
            'the controller has to read the brand for the template to draw it');
    }

    // ══ what an organisation can put on the page ═════════════════════════════

    public function test_every_block_an_organisation_fills_in_reaches_the_page(): void
    {
        $r = OrgBrand::save($this->orgId, $this->post());
        $this->assertTrue($r['ok'], (string) $r['message']);

        $html = $this->render();

        $this->assertStringContainsString('Today we are in forty-one.', $html, 'story');
        $this->assertStringContainsString('12,400', $html, 'impact figure');
        $this->assertStringContainsString('a class set for one term', $html, 'gift ladder');
        $this->assertStringContainsString('Ninety-one per cent.', $html, 'faq answer');
        $this->assertStringContainsString('Our 2026 annual report', $html, 'link label');
        $this->assertStringContainsString('A day at Ikorodu', $html, 'video title');
    }

    /**
     * A STORY KEEPS ITS PARAGRAPHS.
     *
     * `clip()` collapses every run of whitespace, which is right for a link label and
     * wrong for four thousand characters: five paragraphs about somebody's work arrived
     * as one block that read like a terms notice. Nobody reports that as a bug — it looks
     * like a design choice — which is why it needs pinning.
     */
    public function test_a_story_keeps_its_paragraphs_and_loses_its_tags(): void
    {
        OrgBrand::save($this->orgId, $this->post([
            'story' => "One.\n\nTwo.\n\n\n\nThree <script>alert(1)</script>.",
        ]));
        $paras = OrgBrand::paragraphs((string) OrgBrand::of($this->org())['story']);

        $this->assertCount(3, $paras, 'blank lines are paragraphs; runs of them are one break');
        $this->assertSame('Three alert(1).', $paras[2], 'and the markup is gone, not escaped');
    }

    /**
     * A BLOCK SWITCHED ON WITH NOTHING IN IT PUBLISHES NO HEADING.
     *
     * Both conditions, deliberately. A bare "Questions donors ask you" over nothing reads
     * as a page somebody abandoned halfway, and on a donation page that costs the
     * organisation money.
     */
    public function test_an_empty_block_that_is_switched_on_draws_nothing(): void
    {
        OrgBrand::save($this->orgId, ['accent' => '#1a6118', 'section_faq' => '1',
                                      'section_team' => '1', 'section_quotes' => '1']);
        $html = $this->render();

        $this->assertStringNotContainsString('What donors ask', $html);
        $this->assertStringNotContainsString('Who runs', $html);
        $this->assertStringNotContainsString('What people say', $html);
    }

    // ══ the security surface ═════════════════════════════════════════════════

    /**
     * NOTHING AN ORGANISATION TYPED EVER REACHES AN `src`.
     *
     * A video is stored as a provider and an id; the URL is built from our own constant.
     * These are the strings somebody would try.
     */
    public function test_only_real_provider_urls_yield_a_video(): void
    {
        foreach ([
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ'          => 'youtube:dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ?si=xyz'                  => 'youtube:dQw4w9WgXcQ',
            'https://www.youtube.com/shorts/dQw4w9WgXcQ'           => 'youtube:dQw4w9WgXcQ',
            'https://vimeo.com/123456789'                          => 'vimeo:123456789',
            'https://player.vimeo.com/video/123456789'             => 'vimeo:123456789',
        ] as $url => $want) {
            $ref = OrgBrand::videoRef($url);
            $this->assertNotNull($ref, $url);
            $this->assertSame($want, $ref['provider'] . ':' . $ref['id'], $url);
        }

        foreach ([
            // The host is PARSED, not substring-matched. `str_contains($u,'youtube.com')`
            // is true of both of these, which is how an allowlist stops being one.
            'https://youtube.com.attacker.example/watch?v=dQw4w9WgXcQ',
            'https://attacker.example/?q=youtube.com&v=dQw4w9WgXcQ',
            'https://www.youtube.com/channel/UCabcdefghij',   // not a video
            'javascript:alert(1)',
            'data:text/html;base64,PHNjcmlwdD4=',
            'https://www.youtube.com/watch?v="><script>alert(1)</script>',
            '',
        ] as $bad) {
            $this->assertNull(OrgBrand::videoRef($bad), $bad . ' must be refused');
        }
    }

    /** And whatever is stored, the embed URL is one of ours or it is nothing. */
    public function test_an_embed_url_is_always_one_of_our_own_origins(): void
    {
        foreach ([
            ['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ'],
            ['provider' => 'vimeo',   'id' => '123456789'],
        ] as $v) {
            $this->assertMatchesRegularExpression(
                '~^https://(www\.youtube-nocookie\.com/embed/|player\.vimeo\.com/video/)~',
                OrgBrand::embedUrl($v));
        }

        // A row that survived a provider being retired, or was written by an import.
        foreach ([
            ['provider' => 'evil',    'id' => 'dQw4w9WgXcQ'],
            ['provider' => 'youtube', 'id' => '../../etc/passwd'],
            ['provider' => 'youtube', 'id' => 'x" onload="alert(1)'],
            [],
        ] as $v) {
            $this->assertSame('', OrgBrand::embedUrl($v),
                'an unusable row renders as nothing, never as a guessed host');
        }
    }

    /**
     * NO IFRAME UNTIL SOMEBODY PRESSES PLAY, WHICH IS A LEGAL REQUIREMENT.
     *
     * An iframe in the delivered markup sends the visitor's IP to YouTube or Vimeo and
     * lets them set storage, on page view, before the visitor has done anything. Under
     * the GDPR joint-controller line and Nigeria's NDPA 2023 that needs a lawful basis,
     * and "the page contained a video" is not one. `youtube-nocookie.com` narrows the
     * cookie question and does not touch the transmission.
     *
     * So the assertion is on the SHIPPED HTML, not on an intention: zero frames, the
     * provider named before the press, and a plain link out for a browser that refuses.
     */
    public function test_a_video_sends_nothing_to_a_provider_until_it_is_pressed(): void
    {
        OrgBrand::save($this->orgId, $this->post());
        $html = $this->render();

        $this->assertStringNotContainsString('<iframe', $html,
            'a frame in the markup is a third-party request nobody consented to');
        $this->assertStringContainsString('data-ob-src="https://www.youtube-nocookie.com/embed/', $html,
            'the URL is carried for the handler to use on a press');
        $this->assertStringContainsString('Loads from YouTube when you press play', $html,
            'the provider is named BEFORE the visitor contacts it');
        $this->assertStringContainsString('Watch on YouTube', $html,
            'and there is a way out for a browser that refuses the frame');
    }

    /**
     * THE HEADER HAS TO PERMIT WHAT THE PAGE ACTUALLY FRAMES.
     *
     * This repo's most expensive recurring fault is a header switching a feature off in a
     * way nothing on the page can see: `camera=()` killed the door scanner for months,
     * `autoplay=()` the door's greeting, and `media-src` the nominee's read-aloud — every
     * one a rejected promise the page swallowed on purpose.
     *
     * A frame blocked by `frame-src` is the same shape. So this asks the question that
     * catches it: is every origin this platform's own code frames actually allowed — in
     * BOTH policies, because on this host the static one in `.htaccess` is the one a
     * browser receives and the nonce policy has never reached one.
     */
    public function test_the_csp_permits_every_provider_the_page_can_frame(): void
    {
        $live   = (string) file_get_contents(dirname(__DIR__, 2) . '/public/.htaccess');
        $static = Csp::staticPolicy();

        foreach (OrgBrand::VIDEO_PROVIDERS as $key => $p) {
            $origin = (string) parse_url($p['embed'], PHP_URL_SCHEME)
                    . '://' . (string) parse_url($p['embed'], PHP_URL_HOST);

            $this->assertStringContainsString($origin, Csp::FRAME_HOSTS,
                "{$key} is offered to organisations, so frame-src must allow {$origin}");
            $this->assertStringContainsString($origin, $static,
                "{$origin} must be in the static policy too");
            $this->assertStringContainsString($origin, $live,
                "{$origin} must be in public/.htaccess — on this host that is the policy a "
                . 'browser actually receives, so an origin added only in PHP works nowhere');
        }
    }

    /** Links an organisation typed are not links this platform vouches for. */
    public function test_an_organisations_links_are_rel_hardened_and_refused_when_unsafe(): void
    {
        $r = OrgBrand::save($this->orgId, $this->post([
            'link_label' => ['Report', 'Bad'],
            'link_url'   => ['https://readnaija.example/r.pdf', 'javascript:alert(1)'],
        ]));
        $this->assertFalse($r['ok'], 'a javascript: URL is refused, and the save is refused with it');
        $this->assertStringContainsString('https://', (string) $r['message'],
            'and the message says what was wrong with it');

        OrgBrand::save($this->orgId, $this->post());
        $html = $this->render();

        // `nofollow ugc` so we pass no ranking to a partner's link and make no claim about
        // it; `noopener noreferrer` because a new tab that can reach back into this one is
        // a tab that can rewrite the donation form.
        foreach (['noopener', 'noreferrer', 'nofollow', 'ugc'] as $token) {
            $this->assertStringContainsString($token, $html, "links must carry rel={$token}");
        }
    }

    /**
     * A PARTNER'S TEXT CANNOT BREAK OUT OF THE PAGE.
     *
     * `strip_tags` on the way in and Twig's autoescaping on the way out, and the reason
     * both are needed is that neither is sufficient: escaping alone would render the
     * markup visibly as text, and stripping alone would leave `"` and `<` to be escaped
     * by something.
     */
    public function test_markup_an_organisation_typed_is_inert(): void
    {
        OrgBrand::save($this->orgId, $this->post([
            'impact_figure' => ['</div><script>alert(1)</script>'],
            'impact_label'  => ['" onload="alert(2)'],
            'faq_q'         => ['<img src=x onerror=alert(3)>'],
            'faq_a'         => ['ok'],
        ]));
        $html = $this->render();

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('onerror=', $html);
        // The one survivor is TEXT — an escaped quote cannot open an attribute.
        $this->assertStringContainsString('&quot; onload=&quot;alert(2)', $html);
    }

    // ══ the column, which is 64KB on MySQL ═══════════════════════════════════

    /**
     * THE CAPS BOUND AN ASCII DOCUMENT WELL UNDER THE COLUMN.
     *
     * `brand_json` is TEXT — 65,535 bytes on MySQL. Filling every block to its ceiling in
     * Latin characters comes to roughly half that, which is the margin the per-list caps
     * are for. If this ever fails, a cap has grown past what the column can hold and the
     * next organisation to fill the form in loses their page.
     */
    public function test_a_completely_full_page_fits_the_column(): void
    {
        $r = OrgBrand::save($this->orgId, $this->maxedOut('x'));
        $this->assertTrue($r['ok'], (string) $r['message']);

        $bytes = strlen((string) DB::table('gates_partner_orgs')
            ->where('id', $this->orgId)->value('brand_json'));

        $this->assertLessThan(OrgBrand::MAX_JSON_BYTES, $bytes,
            'a page filled to every cap must not need the guard');
        $this->assertLessThan(60000, $bytes, 'and must sit clear of the 65,535-byte column');
    }

    /**
     * AND WITH MULTI-BYTE TEXT THE GUARD IS THE ONLY THING BETWEEN AN ORGANISATION AND
     * A SILENTLY WRECKED PAGE.
     *
     * The caps count CHARACTERS and the column counts BYTES. Every limit filled with
     * four-byte characters is about 132KB — twice what the column holds — so an
     * organisation writing in a script that uses them can exceed it while typing nothing
     * the form told them was too long. That is not a hypothetical audience for a
     * continental award.
     *
     * Left to the database, both outcomes are worse than a message: strict mode throws
     * and they are told "that could not be saved just now" for something they could have
     * fixed, and non-strict mode — which shared hosting turns on — TRUNCATES. A truncated
     * JSON document does not parse, so `of()` falls back to the house defaults and their
     * whole page silently reverts to unbranded.
     *
     * Asserted on SQLite, where the column would have accepted it, which is the only
     * place the guard can be shown to be ours rather than the database's.
     */
    public function test_a_multibyte_page_beyond_the_column_is_refused_with_its_size(): void
    {
        OrgBrand::save($this->orgId, $this->maxedOut('x'));          // a good page first
        $before = (string) DB::table('gates_partner_orgs')
            ->where('id', $this->orgId)->value('brand_json');

        $r = OrgBrand::save($this->orgId, $this->maxedOut('𝄞'));      // U+1D11E, four bytes

        $this->assertFalse($r['ok'], 'past the column, and refused rather than truncated');
        $this->assertStringContainsString('KB', (string) $r['message'],
            'the organisation is told the size, so they can act on it');

        $this->assertSame($before, (string) DB::table('gates_partner_orgs')
            ->where('id', $this->orgId)->value('brand_json'),
            'and the previous page is untouched — a refused save changes nothing');
    }

    /** Every block filled to its ceiling with one repeated character. */
    private function maxedOut(string $ch): array
    {
        $rep = static fn (int $n): string => str_repeat($ch, $n);

        $post = ['accent' => '#1a6118', 'story' => $rep(OrgBrand::MAX_STORY),
                 'tagline' => $rep(OrgBrand::MAX_TAGLINE),
                 'website' => 'https://readnaija.example/'];
        foreach (OrgBrand::BLOCKS as $name => $spec) {
            $post[$name . '_' . $spec['a']] = array_fill(0, $spec['max'], $rep($spec['a_max']));
            $post[$name . '_' . $spec['b']] = array_fill(0, $spec['max'], $rep($spec['b_max']));
        }
        $post['link_label'] = array_fill(0, OrgBrand::MAX_LINKS, $rep(OrgBrand::MAX_LABEL));
        $post['link_url']   = array_fill(0, OrgBrand::MAX_LINKS,
                                         'https://readnaija.example/' . str_repeat('p', 200));
        return $post;
    }

    /** Each list has a ceiling, so one block cannot consume the whole document. */
    public function test_each_block_stops_at_its_own_ceiling(): void
    {
        $spec = OrgBrand::BLOCKS['impact'];
        OrgBrand::save($this->orgId, [
            'accent' => '#1a6118', 'section_impact' => '1',
            'impact_figure' => array_fill(0, $spec['max'] + 6, '1'),
            'impact_label'  => array_fill(0, $spec['max'] + 6, 'things'),
        ]);

        $this->assertCount($spec['max'], OrgBrand::of($this->org())['blocks']['impact']);
    }

    // ══ the platform underneath ══════════════════════════════════════════════

    /**
     * THE AFRICA GATES CREDIT IS NOT A SECTION AN ORGANISATION CAN SWITCH OFF.
     *
     * Africa GATES provides the page, the checkout, the settlement, the receipting and
     * the refund path, and takes no cut. Something pays for that, and the credit is the
     * only place on the page it is named. It is below the organisation's own ask and is
     * never a second amount field — see OrgBrand::GATES_CREDIT for why the restraint is
     * the commercial decision rather than squeamishness.
     */
    public function test_the_platform_credit_survives_an_organisation_turning_everything_off(): void
    {
        // Every section explicitly off.
        $off = ['accent' => '#1a6118'];
        OrgBrand::save($this->orgId, $off);
        $html = $this->render();

        $this->assertStringContainsString(OrgBrand::GATES_CREDIT, $html);
        $this->assertStringContainsString('takes no cut', $html,
            'and it says what the organisation gets for it');
        $this->assertStringContainsString('href="' . GivingUrl::page() . '"', $html,
            'a credit nobody can act on raises money for nobody');

        $this->assertArrayNotHasKey('gates', OrgBrand::SECTIONS,
            'it must not be offered as a section, or it becomes a checkbox');
    }

    /**
     * AND EVERY ROUTE THE CREDIT POINTS AT IS REGISTERED.
     *
     * `/giving` is in the plan and is NOT built. A credit line pointing at a 404 is worse
     * than no credit at all, and this is the sweep that stops one being written from a
     * roadmap rather than from the route table.
     */
    public function test_the_credit_links_only_to_routes_that_exist(): void
    {
        $html   = $this->render();
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');

        preg_match_all('~href="(/[a-z0-9/-]*)"~', $html, $m);
        $this->assertNotEmpty($m[1]);

        foreach (array_unique($m[1]) as $path) {
            $this->assertStringContainsString("'" . $path . "'", $routes,
                "the page links to {$path}, which no route registers");
        }
    }
}
