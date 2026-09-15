<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Every admin form that destroys something asks first.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS A TEST AND NOT A CONVENTION
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `data-confirm` was already the house pattern, already wired to a delegated listener in
 * `public/assets/js/admin.js`, and already used on most of the destructive controls. Ten
 * had it and ten did not — and there is nothing about the ten that did not to distinguish
 * them. They were simply added on a day nobody remembered.
 *
 * That is exactly the shape of thing a test can hold and a convention cannot: the failure
 * is a MISSING attribute, so it is invisible in review (the diff shows a form that looks
 * like every other form) and invisible in use until somebody clicks the wrong row. The ten
 * that were missing included revoking a door-scanning pass mid-event and cancelling an
 * interview — a click that cannot be taken back on a screen somebody is using under
 * pressure.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND WHY IT MATCHES ON THE ACTION, NOT ON THE BUTTON LABEL
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A label is a design decision that changes ("Remove", "Turn off", "Take it out"), and
 * matching on words is how you get both a false alarm on a button called "Reset filters"
 * and a miss on one called "Turn off". An earlier attempt at this matched on text and
 * produced four false positives — "re**set**" inside "pre**set**", "re**store**" inside
 * "restore" — before it found anything real.
 *
 * The route path is the honest signal: `/delete`, `/remove`, `/revoke`, `/cancel`,
 * `/retire`, `/purge`, `/destroy`. It is what the SERVER will do, which is the thing being
 * warned about.
 */
final class AdminDestructiveConfirmTest extends TestCase
{
    /** Path segments that name a destructive server action. */
    private const DESTRUCTIVE = ['delete', 'remove', 'revoke', 'cancel', 'retire', 'purge', 'destroy'];

    /**
     * A form whose action ends in a destructive verb, but which is NOT one.
     *
     * Kept as an explicit list rather than a cleverer pattern, because every entry is a
     * judgement somebody has to be able to read and disagree with. Adding to it is how the
     * test is silenced, so it should be uncomfortable to do.
     *
     * @var list<string>
     */
    private const NOT_DESTRUCTIVE = [
        // Withdraws a transcript from the dossier; the interview and the transcript both
        // survive and it can be put back.
        '/withdraw',
    ];

    /** @return list<string> every admin template, recursively */
    private function templates(): array
    {
        $root  = realpath(__DIR__ . '/../../templates/admin');
        $out   = [];
        $it    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.twig')) $out[] = $f->getPathname();
        }
        sort($out);
        return $out;
    }

    /**
     * Twig comments removed, with the offsets preserved.
     *
     * Replaced by equal-length whitespace rather than deleted, because this test reports a
     * LINE NUMBER and a comment that shortened the file would send somebody to the wrong
     * one. The same reasoning as {@see TwigBlockScopeTest}.
     *
     * It matters here in particular: several of these confirmations are introduced by a
     * comment that quotes the very markup being asserted on.
     */
    private function stripComments(string $src): string
    {
        return (string) preg_replace_callback(
            '~\{#.*?#\}~s',
            static fn (array $m): string => preg_replace('~[^\n]~', ' ', $m[0]),
            $src
        );
    }

    public function test_every_destructive_admin_form_asks_before_it_acts(): void
    {
        $missing = [];

        foreach ($this->templates() as $path) {
            $src = $this->stripComments((string) file_get_contents($path));

            // Each <form …> and everything up to its </form>. Non-greedy, so nested markup
            // between two sibling forms is never attributed to the first.
            preg_match_all('~<form\b[^>]*>.*?</form>~is', $src, $forms, PREG_OFFSET_CAPTURE);

            foreach ($forms[0] as [$form, $offset]) {
                if (!preg_match('~action\s*=\s*"([^"]*)"~i', $form, $m)) continue;
                $action = $m[1];

                $skip = false;
                foreach (self::NOT_DESTRUCTIVE as $ok) {
                    if (str_contains($action, $ok)) { $skip = true; break; }
                }
                if ($skip) continue;

                $hits = false;
                foreach (self::DESTRUCTIVE as $verb) {
                    // A path SEGMENT, so `/delete` and `/delete-test` match and a query
                    // parameter called `filter=deleted` does not.
                    if (preg_match('~/' . $verb . '(?:[-/"?]|$)~i', $action)) { $hits = true; break; }
                }
                if (!$hits) continue;

                // Either on the form or on a control inside it — admin.js honours both.
                if (str_contains($form, 'data-confirm')) continue;

                $line = substr_count(substr($src, 0, $offset), "\n") + 1;
                $missing[] = str_replace(dirname(__DIR__, 2) . '/', '', $path)
                           . ':' . $line . '  →  ' . $action;
            }
        }

        $this->assertSame([], $missing,
            "These admin forms destroy something and never ask:\n  " . implode("\n  ", $missing));
    }

    /**
     * A confirmation says what is LOST, not merely "are you sure".
     *
     * "Are you sure?" transfers no information — it asks somebody who has already decided
     * to decide again, and after the third one they stop reading. What makes the pause
     * worth its cost is a sentence naming the consequence, so the answer can actually
     * change.
     *
     * Length is a crude proxy for that and is deliberately generous: it catches the empty
     * gesture without pretending to grade prose.
     */
    public function test_a_confirmation_says_what_is_lost(): void
    {
        $weak = [];

        foreach ($this->templates() as $path) {
            $src = $this->stripComments((string) file_get_contents($path));
            preg_match_all('~data-confirm\s*=\s*"([^"]*)"~i', $src, $m, PREG_OFFSET_CAPTURE);

            foreach ($m[1] as [$text, $offset]) {
                // Twig inside the attribute renders to more than it reads as, so measure
                // the literal prose only.
                $prose = trim((string) preg_replace('~\{[%{#].*?[%}#]\}~s', '', $text));
                if (mb_strlen($prose) >= 40) continue;

                $line = substr_count(substr($src, 0, $offset), "\n") + 1;
                $weak[] = str_replace(dirname(__DIR__, 2) . '/', '', $path) . ':' . $line
                        . '  →  "' . $prose . '"';
            }
        }

        $this->assertSame([], $weak,
            "A confirmation that does not name the consequence is a click, not a decision:\n  "
            . implode("\n  ", $weak));
    }
}
