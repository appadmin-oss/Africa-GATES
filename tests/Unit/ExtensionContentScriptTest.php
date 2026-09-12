<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The content script has to survive being clicked into.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE SECOND HALF OF "IT IS IMPOSSIBLE TO TRIGGER THE EXTENSION"
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The first line of the script was:
 *
 *     const CODE = (location.pathname.match(/([a-z]{3}-[a-z]{4}-[a-z]{3})/) || [])[1] || '';
 *     if (!CODE) return;                       // the landing page, not a call
 *
 * A content script is injected ONCE per document load, and Meet is a single-page app. The
 * ordinary way into a call is to open meet.google.com, find the meeting in the list, and
 * click it — a history navigation, not a page load. So the script had already run, at a
 * moment when the path was `/` and held no meeting code, and it returned permanently. No
 * later event could bring it back: the panel never appeared, in exactly the tab it was
 * needed in, and nothing anywhere said why.
 *
 * From the operator's side that is indistinguishable from an extension that does not work.
 * Pasting the key again does nothing. Reinstalling does nothing. Only opening the call URL
 * directly in a fresh tab works, and no screen said so.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS ASSERTED FROM PHP
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * There is no JavaScript test runner in this project and adding one for a single file is a
 * dependency the platform would carry for years. What can be checked without a browser is
 * that the specific constructs whose ABSENCE caused the bug are present, and that the
 * construct that caused it has not come back. That is a weaker guarantee than running the
 * script, and it is the guarantee that catches somebody re-simplifying this back into an
 * early return.
 */
final class ExtensionContentScriptTest extends TestCase
{
    private function js(string $file = 'content.js'): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/extension/' . $file);
    }

    /**
     * The same file with its comments removed.
     *
     * Needed for the negative assertions, and not fussiness: the fix carries a comment that
     * QUOTES the line it removed, so a scan of the raw file reports the fixed script as
     * still broken. TwigBlockScopeTest documents this exact mistake, and it was made again
     * here, in the test written to prevent it.
     */
    private function code(string $file = 'content.js'): string
    {
        $s = (string) preg_replace('~/\*[\s\S]*?\*/~', '', $this->js($file));
        return (string) preg_replace('~^\s*//.*$~m', '', $s);
    }

    public function test_it_does_not_give_up_when_the_url_has_no_meeting_code(): void
    {
        $js = $this->js();

        // The exact line that made the extension impossible to trigger.
        $this->assertStringNotContainsString('if (!CODE) return;', $this->code(),
            'a bare early return here means the panel never appears for anybody who reaches '
            . 'a call by clicking it in the Meet list');
    }

    public function test_the_meeting_code_is_read_live_rather_than_captured_once(): void
    {
        $js = $this->js();

        $this->assertStringContainsString('codeInUrl', $js);
        // A `const` would be the old bug with a helper function in front of it.
        $this->assertMatchesRegularExpression('/\blet\s+CODE\b/', $js,
            'CODE has to be reassignable — a tab can move from one call to another');
    }

    public function test_something_keeps_watching_the_url(): void
    {
        $js = $this->js();

        // Polling, not a history hook: patching History.prototype from a content script
        // reaches only the isolated world's copy, so the page's own pushState navigations
        // would not fire it, and popstate misses pushState entirely.
        $this->assertStringContainsString('setInterval', $js,
            'nothing re-checks the URL, so an SPA navigation into a call is still missed');
    }

    public function test_the_panel_is_mounted_when_there_is_a_call_and_not_before(): void
    {
        $js = $this->js();

        $this->assertStringContainsString('function mount()', $js);
        // Appended inside mount(), not at the top level: a floating "Connecting…" card over
        // somebody's meeting list is an extension that looks broken before it has had
        // anything to do.
        $this->assertSame(1, preg_match_all('~documentElement\.appendChild~', $js, $m));
        $this->assertMatchesRegularExpression(
            '~function mount\(\)[\s\S]{0,400}?documentElement\.appendChild~', $js,
            'the panel is still attached before the tab is in a call'
        );
    }

    public function test_moving_between_calls_does_not_double_up(): void
    {
        $js = $this->js();

        // connect() can now run more than once per document, and three things in this file
        // were written on the assumption that it runs exactly once:
        //
        //   · hunt() re-schedules itself, so two loops would race to observe one container;
        //   · observe() created a MutationObserver and never disconnected the last one, so
        //     every caption line would be queued twice and each duplicate would arrive as
        //     a revision of a line that was already right;
        //   · the pending buffer would carry the previous call's captions into the new
        //     interview's transcript.
        $this->assertStringContainsString('if (hunting) return;', $js);
        $this->assertStringContainsString('observer.disconnect()', $js);
        $this->assertStringContainsString('pending.length = 0;', $js);
    }

    public function test_the_worker_still_does_every_fetch(): void
    {
        // The architecture, and it is load-bearing: a fetch from the content script runs in
        // Meet's origin, so it needs CORS on our side, fires a preflight, and can be
        // blocked by Meet's own CSP. A fetch from the service worker with the host in
        // host_permissions is a privileged extension request and needs none of that.
        $this->assertStringNotContainsString('fetch(', $this->code('content.js'),
            'a fetch in the content script reintroduces CORS and Meet CSP as failure modes');
        $this->assertStringContainsString('fetch(', $this->js('worker.js'));
    }

    public function test_the_manifest_still_matches_the_meet_landing_page(): void
    {
        $m = json_decode($this->js('manifest.json'), true);

        // `https://meet.google.com/*` and not `/*-*-*`: the script has to already be in the
        // tab when the operator clicks a meeting, because there is no page load at that
        // point to inject it.
        $this->assertSame(['https://meet.google.com/*'], $m['content_scripts'][0]['matches']);
    }
}
