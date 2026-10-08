<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * A `<form>` inside another `<form>` is deleted by the browser, and nothing says so.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS THE SAME GENRE AS THE TWIG BLOCK-SCOPE BUG
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The HTML parser holds a "form element pointer". When a `<form>` start tag arrives while
 * that pointer is already set, the token is IGNORED — not nested, not errored: dropped.
 * Its children survive and are adopted by the outer form.
 *
 * So the markup renders. The button renders. It is styled, it is enabled, it is in the
 * right place, and it posts to the OUTER form's action with the outer form's fields. There
 * is no console warning, no validator in the pipeline, and the server sees a well-formed
 * request to a route that exists — so the logs are clean too. The only symptom is that the
 * button does the wrong thing, and the wrong thing is usually plausible.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THREE THAT SHIPPED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * · **Settings → "Check the sync"** posted to `/admin/settings` instead of
 *   `/admin/settings/probe-sync`. It saved the page and returned no probe rows, which is
 *   indistinguishable from a Google integration that cannot be reached — the exact
 *   diagnosis the button exists to give. Reported as "the site is not reading the calendar".
 *
 * · **Programme cycle → a category's "Delete"** posted to the category UPDATE route.
 *   Pressing Delete saved the category. The confirmation dialog appeared and said yes.
 *
 * · **Questionnaire → "Copy them in so I can edit them"** posted to the outcomes SAVE
 *   route with none of the derived rows in the body: the one button whose job is to
 *   populate the outcome list submitted an empty outcome list to the route that stores
 *   outcome lists, and the screen came back still saying there were none.
 *
 * The fix in all three is `formaction` on the submit button, which is what the fourth
 * instance on the settings page had been doing correctly all along.
 *
 * ── AND WHY `formaction` NEEDS admin.js TO COOPERATE ─────────────────────────
 *
 * `form.submit()` ignores the submitter, so a `data-confirm` button carrying a `formaction`
 * would confirm and then post to the form's own action anyway — the same bug wearing a
 * dialog. The confirm handlers use `requestSubmit(submitter)` for that reason, and the last
 * test here holds them to it.
 */
final class NestedFormTest extends TestCase
{
    /** @return list<string> every .twig under templates/ */
    private function templates(): array
    {
        $out = [];
        $it  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/templates'));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'twig') $out[] = $f->getPathname();
        }
        sort($out);
        return $out;
    }

    /**
     * Twig comments blanked, offsets preserved.
     *
     * Required rather than tidy: the three fixed templates now each carry a comment that
     * quotes `<form>` while explaining this bug, and a scanner that reads comments reports
     * every one of them as still broken. That is not hypothetical — it is what the first
     * run of this scan did.
     */
    private function stripComments(string $src): string
    {
        return (string) preg_replace_callback(
            '~\{#.*?#\}~s',
            static fn (array $m): string => str_repeat(' ', strlen($m[0])),
            $src
        );
    }

    public function test_no_template_opens_a_form_inside_a_form(): void
    {
        $offences = [];

        foreach ($this->templates() as $path) {
            $src   = $this->stripComments((string) file_get_contents($path));
            $depth = 0;

            if (!preg_match_all('~</?form\b~i', $src, $m, PREG_OFFSET_CAPTURE)) continue;

            foreach ($m[0] as [$tag, $at]) {
                if (str_starts_with($tag, '</')) {
                    // Clamped at zero. A partial template may close a form its parent
                    // opened, and going negative would then mask a real nesting later.
                    $depth = max(0, $depth - 1);
                    continue;
                }
                if (++$depth > 1) {
                    $offences[] = sprintf('%s:%d',
                        str_replace(dirname(__DIR__, 2) . '/', '', $path),
                        substr_count(substr($src, 0, $at), "\n") + 1);
                }
            }
        }

        $this->assertSame([], $offences,
            "A <form> start tag inside an open form is DISCARDED by the browser, and the button "
            . "inside it silently posts to the outer form's action. Use formaction on the submit "
            . "button instead. Found at: " . implode(', ', $offences));
    }

    /**
     * The two confirm handlers must re-submit through the submitter.
     *
     * `form.submit()` posts to the form's own action and drops the pressed button's
     * `formaction` and its `name`/`value`. Since the fix above puts destructive buttons
     * inside the form they sit beside, a confirmed delete re-submitted with `submit()`
     * would run the SAVE — which is the bug this whole file is about, only now with the
     * operator having explicitly agreed to it.
     */
    public function test_a_confirmed_submit_keeps_the_button_it_came_from(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/admin.js');

        $this->assertStringContainsString('requestSubmit', $js,
            'agConfirm re-submits with form.submit(), which loses the button formaction');

        // Both paths: the form-level data-confirm listener and the standalone-button one.
        // `\.requestSubmit(` — the CALL, not the word. Counting the word matched the
        // comment above the call and made this pass at three.
        $this->assertSame(2, preg_match_all('~\.requestSubmit\s*\(~', $js, $unused),
            'one of the two confirm paths still re-submits without its submitter');
    }

    /**
     * The buttons that were fixed still carry a formaction.
     *
     * The scan above proves nobody re-nests a form. It cannot prove the replacement kept
     * the action, and a button that lost its formaction is the identical bug with tidier
     * markup — so the three routes are named here.
     */
    public function test_the_three_repaired_buttons_still_point_somewhere_else(): void
    {
        $root = dirname(__DIR__, 2) . '/templates/admin/';

        foreach ([
            'settings.twig'                => 'name="probe" value="sync"',
            'programmes/cycle.twig'        => 'formaction="/admin/categories/{{ c.id }}/delete"',
            'questionnaires/questions.twig'=> '/outcomes/seed"',
        ] as $file => $needle) {
            $this->assertStringContainsString(
                $needle,
                (string) file_get_contents($root . $file),
                $file . ' lost the action its button used to reach by nesting'
            );
        }
    }
}
