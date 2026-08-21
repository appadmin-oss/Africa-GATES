<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The properties that decide whether a campaign renders in a real inbox.
 *
 * None of this can be proved without Litmus and a rack of devices. What CAN be pinned is
 * the set of specific, known causes of a broken render — and each assertion here names the
 * client it is for, because a rule with no client attached gets deleted by the next person
 * as superstition.
 */
class EmailInboxCompatTest extends TestCase
{
    private const TPL = __DIR__ . '/../../templates/emails/final-hours.twig';

    private static function src(): string
    {
        return (string) file_get_contents(self::TPL);
    }

    /** The template with Twig comments removed — those never reach a recipient. */
    private static function markup(): string
    {
        return (string) preg_replace('/\{#.*?#\}/s', '', self::src());
    }

    public function test_layout_survives_a_stripped_style_block(): void
    {
        // GMAIL. It strips <style> in several configurations — most commonly a non-Gmail
        // address pulled into a Gmail account over POP/IMAP. Anything whose only mobile
        // behaviour lives in @media is then a desktop-width table on a phone.
        $m = self::markup();

        // The main wrapper must be percentage-width with a max-width, not a fixed px width.
        $this->assertMatchesRegularExpression(
            '/class="agw-wrapper[^"]*"\s+width="100%"/',
            $m,
            'The wrapper must be fluid. A fixed width="560" needs the @media override to be '
            . 'mobile-safe, and that override is exactly what Gmail throws away.'
        );
        $this->assertStringContainsString('max-width:560px', $m);

        // Outlook ignores max-width, so it needs the fixed width back — inside a conditional.
        $this->assertStringContainsString('<!--[if mso]>', $m,
            'Outlook ignores max-width and needs a conditional fixed-width table');
    }

    public function test_outlook_conditional_tables_are_balanced(): void
    {
        // OUTLOOK (Word engine). An unbalanced conditional table is invisible everywhere
        // else and destroys the layout in the one client that reads it.
        $mso = implode('', self::matchAll('/<!--\[if mso\]>(.*?)<!\[endif\]-->/s', self::markup()));
        foreach (['table', 'tr', 'td'] as $tag) {
            $this->assertSame(
                preg_match_all('/<' . $tag . '[\s>]/', $mso),
                preg_match_all('/<\/' . $tag . '>/', $mso),
                "Unbalanced <$tag> inside an [if mso] block"
            );
        }
    }

    public function test_the_cta_works_with_and_without_outlook(): void
    {
        // OUTLOOK. border-radius and padding on an <a> do nothing, so a styled anchor
        // renders as bare underlined text. The VML roundrect is the version Outlook sees.
        $m = self::markup();
        $this->assertStringContainsString('v:roundrect', $m, 'no VML button for Outlook');
        $this->assertStringContainsString('<w:anchorlock/>', $m,
            'without anchorlock the VML button text is selectable and the click target misbehaves');
        $this->assertStringContainsString('<!--[if !mso]><!-->', $m,
            'no non-Outlook CTA — every other client would see nothing');
    }

    public function test_the_deadline_is_readable_with_images_blocked(): void
    {
        // OUTLOOK DESKTOP and most corporate mail block remote images by default. The
        // countdown GIF is the hero, so the deadline has to exist as text as well.
        $m = self::markup();

        // Repeated as real text, outside any <img>.
        $withoutImages = (string) preg_replace('/<img[^>]*>/', '', $m);
        $this->assertStringContainsString('Voting closes {{ closes_human }}', $withoutImages,
            'With images off there is nothing left saying when voting closes');

        // And the alt text is styled, or a blocked image reads as a broken attachment
        // rather than as a sentence somebody wrote.
        $img = self::matchAll('/<img[^>]*countdown_url[^>]*>/s', $m)[0] ?? '';
        $this->assertNotSame('', $img, 'countdown image not found');
        $this->assertStringContainsString('alt=', $img);
        $this->assertStringContainsString('font-family', $img, 'unstyled alt text renders as 10px serif');
        $this->assertStringContainsString('height=', $img,
            'a blocked image with no height collapses the panel it is the hero of');
    }

    public function test_dark_mode_is_declared_rather_than_left_to_inversion(): void
    {
        // GMAIL and OUTLOOK.COM invert regardless of color-scheme, and do it badly on
        // near-white surfaces. APPLE MAIL / iOS honour color-scheme and leave it alone.
        $m = self::markup();
        $this->assertStringContainsString('prefers-color-scheme: dark', $m);
        $this->assertStringContainsString('name="color-scheme"', $m);
        $this->assertStringContainsString('name="supported-color-schemes"', $m);
    }

    public function test_no_layout_css_outlook_cannot_render(): void
    {
        // OUTLOOK (Word engine) supports none of these. Any of them load-bearing means the
        // layout collapses there — which is why this template is tables, not divs.
        $m = self::markup();
        // Only look OUTSIDE the [if !mso] blocks, where Outlook never reads.
        $seen = (string) preg_replace('/<!--\[if !mso\]><!-->.*?<!--<!\[endif\]-->/s', '', $m);
        foreach (['display:flex', 'display:grid', 'position:absolute', 'position:fixed',
                  'float:', 'background-image'] as $banned) {
            $this->assertStringNotContainsString($banned, $seen,
                "$banned does not render in Outlook and must not be load-bearing");
        }
    }

    public function test_it_is_a_table_layout_with_presentation_roles(): void
    {
        // SCREEN READERS. A layout table without role="presentation" is announced as a
        // data table, cell by cell.
        $m      = self::markup();
        $tables = preg_match_all('/<table\b/', $m);
        $roles  = preg_match_all('/<table[^>]*role="presentation"/', $m);
        $this->assertSame($tables, $roles, 'every layout table needs role="presentation"');
        $this->assertGreaterThan(3, $tables, 'a div layout will not survive Outlook');
    }

    public function test_the_preheader_exists_and_is_hidden(): void
    {
        // EVERY CLIENT shows the first text it finds beside the subject. Without a
        // preheader that is "View in browser" or a stray style rule.
        $m = self::markup();
        $this->assertStringContainsString('mso-hide:all', $m, 'preheader must hide in Outlook too');
        $this->assertMatchesRegularExpression('/display:none;\s*max-height:0/', $m);
    }

    public function test_no_inlined_data_uri_images(): void
    {
        // GMAIL, OUTLOOK DESKTOP, OUTLOOK.COM and YAHOO all refuse data: URIs in an
        // img src. The brand mark here was 20,848 base64 bytes — over half the whole
        // email — which the majority of readers downloaded in order to see the alt text,
        // while it ate the headroom before Gmail's clipping point. What Gmail clips off a
        // campaign is the footer, and that is where the unsubscribe link lives.
        $this->assertStringNotContainsString('src="data:', self::markup(),
            'Inline a data: URI here and most recipients see alt text for double the bytes. '
            . 'Host the file and reference it absolutely.');
    }

    public function test_images_are_referenced_absolutely(): void
    {
        // An inbox has no page to be relative TO. A src="/assets/..." resolves against
        // the mail client's own host and 404s everywhere.
        foreach (self::matchAll('/<img[^>]+src="([^"]+)"/', self::markup()) as $src) {
            $this->assertMatchesRegularExpression(
                '~^(https?://|\{\{)~', $src,
                "Image src must be absolute or a Twig variable that resolves to one: $src"
            );
        }
    }

    public function test_it_stays_well_under_gmails_clipping_threshold(): void
    {
        // GMAIL clips around 102KB and appends a "View entire message" link, which on a
        // campaign usually cuts the unsubscribe footer off — the one part that must not
        // be optional.
        $this->assertLessThan(102400, strlen(self::src()),
            'Template source is near Gmail clipping; the rendered output is larger still');
    }

    public function test_ios_does_not_autolink_the_dates_and_addresses(): void
    {
        // APPLE MAIL / iOS turn dates, phone numbers and addresses into blue links,
        // which on a designed email looks like damage.
        $m = self::markup();
        $this->assertStringContainsString('name="format-detection"', $m);
        $this->assertStringContainsString('x-apple-data-detectors', $m,
            'the detector override is needed even with format-detection set');
    }

    /** @return list<string> */
    private static function matchAll(string $re, string $subject): array
    {
        preg_match_all($re, $subject, $m);

        return $m[1] ?? $m[0] ?? [];
    }
}
