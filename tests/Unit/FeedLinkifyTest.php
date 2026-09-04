<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * EVERY LINK ANYBODY SHARED IN THE PULSE WAS DEAD TEXT.
 *
 * `agSocial.linkify()` turned `@mentions` and `#hashtags` into links and left a URL exactly
 * as typed. So a member posting an address got characters, not a link, on the one surface
 * of this platform built for passing things along.
 *
 * The result announcement is what made that impossible to leave. Its last line is the
 * address of the page carrying the whole standing — the working, the two halves, the
 * denominator — and that post exists to take people there. Unlinked, the single most
 * important post this platform makes would have ended in a string somebody had to select
 * by hand on a phone.
 *
 * ── WHY THE PATTERN IS TESTED FROM PHP ───────────────────────────────────────
 *
 * There is no JS runner in this suite. {@see GeeSupportsTest} established the approach:
 * lift the pattern out of the shipped file and assert on it directly, so the thing under
 * test is the source that actually runs rather than a copy of it in a fixture. PCRE and
 * JS regex agree on everything used here.
 *
 * The two properties that matter are ORDER and CONTAINMENT — the URL pass has to run
 * before the sigil passes or it matches inside an href they just wrote, and the pattern
 * has to be unable to escape the attribute it is interpolated into.
 */
final class FeedLinkifyTest extends TestCase
{
    private const JS = __DIR__ . '/../../public/assets/js/ag-social.js';

    private static function source(): string
    {
        return (string) file_get_contents(self::JS);
    }

    /** The shipped URL pattern, translated to a PCRE with the same body. */
    private static function urlRe(): string
    {
        $src = self::source();
        if (!preg_match('~var URL_RE = /(.+?)/g;~', $src, $m)) {
            self::fail('URL_RE is no longer declared in ag-social.js');
        }

        // The JS literal escapes `/` as `\/`; PCRE with `~` delimiters does not need it.
        return '~' . str_replace('\\/', '/', $m[1]) . '~';
    }

    public function test_an_ordinary_link_is_matched(): void
    {
        foreach (['https://afg.example/results/12-primary-school-principal',
                  'http://example.test',
                  'https://example.test/a/b?c=d&amp;e=f',
                  'https://example.test/path#anchor'] as $url) {
            $this->assertSame(1, preg_match(self::urlRe(), 'see ' . $url . ' for more'),
                $url . ' is not linkified, so it renders as text nobody can follow');
        }
    }

    /**
     * A BARE PATH IS NEVER A LINK.
     *
     * The tempting widening — also match `/results/12` — turns "and/or", "12/06" and every
     * fraction anybody types into a link to a page that does not exist. That is a worse
     * outcome on far more posts than the one it fixes, which is why the result
     * announcement stores an absolute URL rather than the pattern being loosened.
     */
    public function test_a_bare_path_is_not_linkified(): void
    {
        foreach (['and/or', 'on 12/06 at noon', '/results/12', 'w/ friends'] as $text) {
            $this->assertSame(0, preg_match(self::urlRe(), $text),
                '"' . $text . '" was turned into a link');
        }
    }

    /**
     * A SCHEME THAT IS NOT http(s) CANNOT GET IN.
     *
     * This string is interpolated straight into an `href`. `javascript:` there is script
     * execution on a page rendering other members' text.
     */
    public function test_no_other_scheme_can_reach_the_href(): void
    {
        foreach (['javascript:alert(1)', 'data:text/html;base64,PHNjcmlwdD4=',
                  'vbscript:msgbox', 'file:///etc/passwd'] as $bad) {
            $this->assertSame(0, preg_match(self::urlRe(), ' ' . $bad),
                $bad . ' would be written into an href');
        }
    }

    /**
     * AND IT CANNOT BREAK OUT OF THE ATTRIBUTE.
     *
     * `escapeHtml()` runs first, so by the time this pattern sees the text there is no raw
     * `"`, `<`, `>` or `'` left in it — but the pattern excludes them anyway. Two
     * independent reasons a crafted post cannot close the href and open a handler, because
     * one of them is a property of a different function that somebody could reorder.
     */
    public function test_the_match_can_never_contain_a_quote_or_a_tag(): void
    {
        $nasty = 'https://example.test/a" onmouseover="alert(1)';
        preg_match(self::urlRe(), $nasty, $m);

        foreach (['"', "'", '<', '>'] as $bad) {
            $this->assertStringNotContainsString($bad, (string) ($m[0] ?? ''),
                'the captured URL contains ' . $bad . ', which is interpolated into an href');
        }
    }

    /**
     * A URL AT THE END OF A SENTENCE KEEPS ITS ADDRESS AND LOSES THE FULL STOP.
     *
     * The normal case, and the one that breaks silently: swallowing the punctuation makes
     * the link 404 while it still looks perfectly right on the page.
     */
    public function test_trailing_punctuation_is_left_outside_the_link(): void
    {
        $src = self::source();
        $this->assertMatchesRegularExpression('~url\.match\(/\[[.,;:!?)]+\]\+\$/\)~', $src,
            'the trailing-punctuation trim is gone — a link at the end of a sentence now '
            . 'carries the full stop into its own address and 404s');
    }

    /**
     * ORDER. The URL pass MUST run before the two sigil passes.
     *
     * Those insert `<a class="ag-tag" href="/registry?q=…">`. A URL pattern running
     * afterwards would match `/registry?q=…` inside that attribute and rewrite the markup
     * from the middle of a tag it did not write — producing corrupt HTML from ordinary
     * text, on a feed. In this order nothing can collide: both sigil patterns require
     * whitespace or `(` before the sigil and a URL contains neither.
     */
    public function test_urls_are_linkified_before_mentions_and_hashtags(): void
    {
        $src = self::source();
        $body = substr($src, (int) strpos($src, 'function linkify(text)'));
        $body = substr($body, 0, (int) strpos($body, "\n  }\n"));

        $url  = strpos($body, 'URL_RE');
        $at   = strpos($body, '@([A-Za-z0-9_.]');
        $hash = strpos($body, '#([A-Za-z0-9_]');

        $this->assertIsInt($url);
        $this->assertIsInt($at);
        $this->assertIsInt($hash);
        $this->assertLessThan($at, $url,
            'the URL pass runs after the @mention pass and will match inside its href');
        $this->assertLessThan($hash, $url,
            'the URL pass runs after the #hashtag pass and will match inside its href');
    }

    /** Escaping still comes first. It is the reason none of the above is the only guard. */
    public function test_the_text_is_escaped_before_anything_is_linkified(): void
    {
        $src  = self::source();
        $body = substr($src, (int) strpos($src, 'function linkify(text)'));

        $escape = strpos($body, 'escapeHtml(text)');
        $url    = strpos($body, 'URL_RE');

        // assertIsInt FIRST, and this is not defensive tidiness. With `escapeHtml(text)`
        // deleted outright, strpos returns false, PHP compares false against an int as 0,
        // and `assertLessThan` passes — so the version of this fault that removes escaping
        // altogether was the one version this test could not see. Caught by mutation.
        $this->assertIsInt($escape,
            'linkify no longer escapes at all — a member post going into innerHTML is now '
            . 'a stored-XSS delivery mechanism');
        $this->assertIsInt($url);
        $this->assertLessThan($url, $escape,
            'linkify escapes AFTER it linkifies, so the anchors it just wrote are escaped '
            . 'and the text it trusted was never inert');
    }

    /**
     * AND SOMETHING STYLES IT.
     *
     * An unstyled `<a>` in the Pulse's dark canvas inherits the browser default blue on a
     * near-black ground, which is the one colour pairing that fails contrast outright. The
     * class is inserted by shared JS, so the surfaces that render post text have to know
     * about it — a link that is technically present and invisible is not a fix.
     */
    public function test_the_feed_styles_the_class_the_shared_helper_inserts(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pages/pulse.twig');

        $this->assertStringContainsString('.pf__canvas .ag-link', $src,
            'the Pulse canvas does not style ag-link, so shared links render as default blue');
        $this->assertStringContainsString('.pf__cap .ag-link', $src,
            'a media post\'s caption does not style ag-link');
    }
}
