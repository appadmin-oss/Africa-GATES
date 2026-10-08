<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The desk calls them SUPPORT tickets, everywhere a person can read it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A TEST AND NOT JUST A SWEEP
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * This platform sells EVENT tickets and runs a SUPPORT desk, and until this was
 * fixed both were called "tickets" on pages a signed-in member sees within two
 * clicks of each other. Their account page lists "Tickets" (events, with a code
 * to show at a door); the support desk said "Your tickets", "Open ticket",
 * "No tickets yet". A member who bought a table at the dinner and also had a
 * payment problem had two unrelated things wearing the same word.
 *
 * The sweep is easy to do once and impossible to keep — the next person writing
 * a flash message or an empty state reaches for the short word. So the rule is
 * enforced rather than remembered: in the VISIBLE TEXT of the support surfaces,
 * "ticket" is always qualified.
 *
 * The check deliberately looks at text nodes only. Class names, route paths,
 * Alpine component names and the database columns keep the short form — renaming
 * `gates_support_tickets` or `/support/tickets` would be churn with a migration
 * and a redirect attached, and nobody reads either.
 */
final class SupportTicketNamingTest extends TestCase
{
    /** The surfaces where the word means the support desk. */
    private const SURFACES = [
        // The four public support pages (support-tickets, support, support-ticket-link,
        // support-assistant) left this list on 3 Oct 2026 when they were destroyed
        // (docs/handoff/DESTROYED.md); each rebuild adds itself back.
        'templates/admin/support/index.twig',
        'templates/admin/support/show.twig',
    ];

    /*
     * Gee's copy — which renders on EVERY public page, over event pages where "your
     * tickets" is the thing the reader just bought — is held by GeeTest::
     * test_gee_never_says_a_bare_ticket. It is not a surface in the list above because
     * every word in partials/gee.twig is a `'…'|trans` literal, which visibleText() strips
     * with the rest of `{{ … }}`: listed here it would read nothing and pass. A
     * `SCRIPT_COPY` constant used to stand here naming gee.js; nothing read it (§17), and
     * the rebuilt gee.js types no sentence at all (its words arrive as data-msg-*).
     */

    /**
     * Everything a reader actually sees: comments, styles, scripts, Twig tags and
     * HTML markup removed, leaving the text nodes.
     */
    private function visibleText(string $twig): string
    {
        $s = (string) preg_replace('~\{#.*?#\}~s', ' ', $twig);
        $s = (string) preg_replace('~<style\b.*?</style>~is', ' ', $s);
        $s = (string) preg_replace('~<script\b.*?</script>~is', ' ', $s);
        $s = (string) preg_replace('~\{\{.*?\}\}~s', ' ', $s);
        $s = (string) preg_replace('~\{%.*?%\}~s', ' ', $s);
        return (string) preg_replace('~<[^>]*>~s', ' ', $s);
    }

    public function test_no_support_surface_says_a_bare_ticket(): void
    {
        $root  = dirname(__DIR__, 2) . '/';
        $bad   = [];

        foreach (self::SURFACES as $rel) {
            $lines = explode("\n", $this->visibleText((string) file_get_contents($root . $rel)));
            foreach ($lines as $i => $line) {
                if (!preg_match_all('~tickets?\b~i', $line, $m, PREG_OFFSET_CAPTURE)) continue;
                foreach ($m[0] as $hit) {
                    $before = substr($line, 0, (int) $hit[1]);
                    // Qualified, which is the whole point.
                    if (preg_match('~\b(support|event)\s+$~i', $before)) continue;
                    // A URL printed for a reader to copy is a path, not prose.
                    if (preg_match('~/support/$~', $before)) continue;
                    $bad[] = $rel . ':' . ($i + 1) . '  ' . trim($line);
                }
            }
        }

        $this->assertSame([], $bad,
            "a support surface says \"ticket\" where the member also has event tickets:\n" . implode("\n", $bad));
    }
}
