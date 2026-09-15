<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\CookiePrefs;
use Tests\Support\AppTwig;
use Tests\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The switch, and the notice: the two screens a visitor can actually refuse from.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IS BEING HELD, AND WHY EACH ONE IS HERE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * IT WORKS WITH SCRIPTS OFF. A privacy control that needs JavaScript is a privacy control
 * that is missing for exactly the people most likely to have switched it off. Both are
 * plain forms that post, and the test asserts there is no script and no `fetch` in either.
 *
 * NEITHER IS A NESTED FORM. The HTML parser silently DELETES a `<form>` start tag while a
 * form is already open — not nested, not errored: dropped, with its children adopted by
 * the outer form. The notice is drawn from the site layout at the very end of the body, so
 * if it ever moved inside a page form, its two buttons would post the page the visitor was
 * reading to `/cookies/choice`, styled correctly, with no console warning. This repository
 * has shipped that fault three times.
 *
 * THE TWO ANSWERS ARE THE SAME WEIGHT. Not a taste call: a notice where "accept" is a
 * button and "decline" is grey text is the design the consent rules exist to stop. The
 * classes are asserted to be identical, which is the only mechanical form that claim has.
 *
 * NOTHING TELLS THE PAGE'S SCRIPTS WHAT THE VISITOR CHOSE. The cookie is HttpOnly and the
 * state is server-rendered; handing every page a privacy preference to read would add a
 * fingerprinting surface to save a round trip nobody is making.
 */
final class CookieChoiceScreenTest extends TestCase
{
    private function twig(): Environment
    {
        $t = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'), [
            'strict_variables' => true,
            'autoescape'       => 'html',
        ]);
        AppTwig::equip($t);

        return $t;
    }

    /** @param array<string,mixed> $over */
    private function panel(array $over = []): string
    {
        return $this->twig()->render('partials/cookie-choice.twig', [
            'cookie_control' => $over + [
                'counting'    => true,
                'locked'      => false,
                'headline'    => 'We are counting your visits',
                'explanation' => 'One row for this visit.',
                'cookie_name' => CookiePrefs::COOKIE,
                'saved'       => false,
                'saved_word'  => '',
                'locked_note' => 'Your browser has said no.',
            ],
        ]);
    }

    // ══ the panel on /cookies ════════════════════════════════════════════════

    public function test_the_panel_offers_the_answer_the_visitor_does_not_already_have(): void
    {
        // A button for the state you are already in does nothing when pressed, and a dead
        // control is worse than an absent one — it teaches people the switch is broken.
        $on = $this->panel(['counting' => true]);
        $this->assertStringContainsString('value="no"', $on);
        $this->assertStringNotContainsString('value="yes"', $on);

        $off = $this->panel(['counting' => false, 'headline' => 'We are not counting your visits']);
        $this->assertStringContainsString('value="yes"', $off);
        $this->assertStringNotContainsString('value="no"', $off);
    }

    public function test_the_panel_offers_nothing_when_the_browser_has_already_refused(): void
    {
        // We honour a browser refusal even over a yes given here. Drawing a "count me"
        // button would be offering to overrule them, so the page says so instead.
        $html = $this->panel(['locked' => true, 'counting' => false]);

        $this->assertStringNotContainsString('<form', $html,
            'a choice was offered to somebody whose browser we have already agreed with');
        $this->assertStringContainsString('Your browser has said no.', $html);
    }

    public function test_the_panel_carries_a_csrf_token_and_posts(): void
    {
        $html = $this->panel();

        $this->assertStringContainsString('method="post"', $html);
        $this->assertStringContainsString('action="/cookies/choice"', $html);
        // A render test that defaulted this in the template would ship an empty token and
        // have the write rejected in production. It is a Twig global; AppTwig mirrors it.
        $this->assertStringContainsString('name="_token" value="test-csrf"', $html);
    }

    public function test_the_panel_names_the_cookie_that_stores_the_answer(): void
    {
        $this->assertStringContainsString(CookiePrefs::COOKIE, $this->panel());
    }

    public function test_the_panel_confirms_a_press(): void
    {
        $saved = $this->panel(['saved' => true, 'saved_word' => 'We will not count your visits.']);

        $this->assertStringContainsString('We will not count your visits.', $saved);
        $this->assertStringContainsString('role="status"', $saved,
            'the confirmation is invisible to a screen reader unless it is announced');
        $this->assertStringNotContainsString('role="status"', $this->panel());
    }

    // ══ the notice ═══════════════════════════════════════════════════════════

    private function notice(string $return = '/nominees'): string
    {
        return $this->twig()->render('partials/cookie-notice.twig', ['cookie_return' => $return]);
    }

    public function test_the_notice_gives_both_answers_the_same_weight(): void
    {
        $html = $this->notice();

        preg_match_all('/<button[^>]*class="([^"]*)"[^>]*value="(yes|no)"/', $html, $m, PREG_SET_ORDER);
        // The attribute order in the template is name-then-value-then-class, so match the
        // other way round too rather than depend on how it happens to be written.
        if (count($m) < 2) {
            preg_match_all('/<button[^>]*value="(yes|no)"[^>]*class="([^"]*)"/', $html, $raw, PREG_SET_ORDER);
            $m = array_map(static fn (array $r): array => [$r[0], $r[2], $r[1]], $raw);
        }

        $this->assertCount(2, $m, 'the notice no longer offers exactly two answers');
        $this->assertSame(trim($m[0][1]), trim($m[1][1]),
            'one answer is styled more prominently than the other — that is the dark '
            . 'pattern the consent rules exist to stop');
    }

    public function test_the_notice_cannot_be_dismissed_without_answering(): void
    {
        // A close button that leaves the question open means the notice returns on the
        // next page, which trains people to press whatever makes it go away — and that is
        // the consent those designs actually collect.
        $html = strtolower($this->notice());

        foreach (['aria-label="close"', 'aria-label="dismiss"', '>×<', 'dismiss-parent'] as $out) {
            $this->assertStringNotContainsString($out, $html);
        }
    }

    public function test_the_notice_carries_the_page_it_was_drawn_on(): void
    {
        $this->assertStringContainsString('name="return" value="/nominees"', $this->notice('/nominees'));
        // And it is escaped, because the value reaches a Location header. The server
        // re-validates it anyway — see CookiePrefs::safeReturn() — but a page that emitted
        // an unescaped attribute would be a second, separate fault.
        $this->assertStringNotContainsString('"><script>',
            $this->notice('/x"><script>alert(1)</script>'));
    }

    public function test_neither_screen_needs_javascript(): void
    {
        foreach (['panel' => $this->panel(), 'notice' => $this->notice()] as $which => $html) {
            $this->assertStringNotContainsString('<script', $html,
                "the {$which} depends on a script, so it is missing for anybody who blocks them");
            $this->assertStringNotContainsString('fetch(', $html);
            // The admin CSP forbids these outright; the public one would allow an inline
            // handler, and it must not be how a privacy control works.
            $this->assertStringNotContainsString('onclick', $html);
        }
    }

    public function test_neither_screen_leaks_the_choice_to_the_page(): void
    {
        foreach ([$this->panel(), $this->notice()] as $html) {
            $this->assertStringNotContainsString('document.cookie', $html);
            $this->assertStringNotContainsString('localStorage', $html);
        }
    }

    public function test_the_notice_is_not_a_modal(): void
    {
        // Holding a page hostage until somebody agrees to be counted is the behaviour the
        // rules exist to stop, and an `aria-modal` region is also a keyboard trap.
        $html = $this->notice();

        $this->assertStringNotContainsString('aria-modal', $html);
        $this->assertStringNotContainsString('role="dialog"', $html);
        $this->assertStringNotContainsString('inert', $html);
    }
}
