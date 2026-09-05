<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * A link that hands work to the assistant must hand it a parameter the assistant reads.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE BUG THIS FILE EXISTS BECAUSE OF
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Four pages linked to `/support/assistant?q=…`. The assistant's `fromLink()` read
 * `ref`, `topic` and `ask`. It did not read `q`. So all four landed the reader on
 * an empty composer:
 *
 *   • /vote/verify — the "Re-check & fix this payment" button, which is the ONLY
 *     action on the proof page we tell supporters to open;
 *   • /help/<slug> — highlight a passage, "Ask about this";
 *   • /help/<slug> — "No — ask the assistant" under "Did this answer it?";
 *   • /help — a search that found nothing, whose whole job is to hand over.
 *
 * Nothing threw. No 404, no console error, no log line. The button simply did not
 * do the thing it said, which is worse than a broken link: a broken link is
 * obviously broken, whereas this teaches the reader the assistant is useless.
 *
 * ── WHY A TEST AND NOT A CAREFUL REVIEW ──────────────────────────────────────
 *
 * Because the two halves live in different files and neither one is wrong on its
 * own. The link is a plausible URL; the reader is a reasonable parser. Only the
 * PAIR is broken, and nothing about editing either half shows you the other. So
 * the pairing is asserted here: the params the templates send are read out of the
 * templates, the params the assistant honours are read out of the assistant, and
 * the first set has to be inside the second.
 */
final class SupportHandoffLinksTest extends TestCase
{
    private const ASSISTANT = 'templates/pages/support-assistant.twig';

    private function repo(): string
    {
        return dirname(__DIR__, 2);
    }

    /** Every query parameter any template sends to /support/assistant. */
    private function sentParams(): array
    {
        $root = $this->repo() . '/templates';
        $sent = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,
            \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') continue;
            $body = (string) file_get_contents($file->getPathname());
            $rel  = str_replace($root . '/', '', $file->getPathname());

            // Runs to the closing quote, NOT to the first whitespace: a Twig href
            // reads `?ref={{ proof.reference|url_encode }}&amp;ask=1`, and a
            // whitespace-terminated pattern stops inside the `{{ … }}` and never
            // sees the second parameter. The first version of this test did exactly
            // that and passed while `ask=1` was renamed to `nope=1`.
            preg_match_all('~/support/assistant\?([^"\']*)~', $body, $m);
            foreach ($m[1] as $query) {
                // `&amp;` in an HTML attribute is a single `&`.
                foreach (preg_split('~&(?:amp;)?~', $query) as $pair) {
                    $name = strtok($pair, '=');
                    if (is_string($name) && $name !== '' && preg_match('~^[a-z_]+$~', $name) === 1) {
                        $sent[$name][] = $rel;
                    }
                }
            }
        }
        return $sent;
    }

    /** Every parameter the assistant's fromLink() actually looks at. */
    private function readParams(): array
    {
        $body = (string) file_get_contents($this->repo() . '/' . self::ASSISTANT);

        $this->assertSame(1, preg_match('~fromLink\(\)\{(.+?)\n    \},~s', $body, $m),
            'Could not find fromLink() in ' . self::ASSISTANT . ' — if it was renamed, update this test.');

        preg_match_all('~\.get\(\s*[\'"]([a-z_]+)[\'"]\s*\)~', $m[1], $g);
        return array_values(array_unique($g[1]));
    }

    public function test_every_parameter_a_page_sends_is_one_the_assistant_reads(): void
    {
        $read = $this->readParams();
        $this->assertNotEmpty($read, 'fromLink() reads no parameters at all — that cannot be right.');

        $ignored = [];
        foreach ($this->sentParams() as $name => $senders) {
            if (!in_array($name, $read, true)) {
                $ignored[] = "?{$name}= sent by " . implode(', ', array_unique($senders));
            }
        }

        $this->assertSame([], $ignored,
            "These links pass a parameter the assistant throws away, so the button appears to "
            . "do nothing:\n  " . implode("\n  ", $ignored)
            . "\nfromLink() currently reads: " . implode(', ', $read));
    }

    /**
     * The proof page's one action must RUN the repair, not just open a chat box.
     *
     * `ref=` is the parameter that makes the assistant build the sentence and send
     * it, and `ask=1` is what makes it send without a second click. A supporter
     * arriving here has already been told once that something was fixed when it was
     * not; the page exists so the next thing they are told is checkable.
     */
    public function test_the_verify_page_asks_for_the_repair_to_actually_run(): void
    {
        $body = (string) file_get_contents($this->repo() . '/templates/pages/vote-verify.twig');

        $this->assertSame(1, preg_match('~/support/assistant\?ref=\{\{[^}]+\}\}&(?:amp;)?ask=1~', $body),
            'The repair button must pass ref= and ask=1 so fix_payment runs on arrival.');
        $this->assertSame(0, preg_match('~/support/assistant\?q=~', $body),
            'q= only prefills a draft; this button is meant to act.');
    }

    /** `?q=` is free text from a URL, so it must be capped before it becomes a message. */
    public function test_a_question_from_a_url_is_bounded(): void
    {
        $body = (string) file_get_contents($this->repo() . '/' . self::ASSISTANT);
        $this->assertSame(1, preg_match('~p\.get\([\'"]q[\'"]\).*?\.slice\(0,\s*\d+\)~s', $body),
            'A pasted essay is not a question — cap ?q= before it reaches the composer.');
    }

    /**
     * `.get(…)` in fromLink() may only be called on the URLSearchParams object.
     *
     * Caught in Chromium as `q.get is not a function`, which took the WHOLE Alpine
     * component down — composer, send button and transcript all dead — after `q`
     * was renamed to `p` in one half of the function while the other half still
     * called `q.get('ask')`, and `q` had meanwhile become a string.
     *
     * Checking "is it declared?" does not catch that, because the broken `q` WAS
     * declared, just as a string. So the assertion is about the TYPE: collect the
     * names assigned `new URLSearchParams(...)`, and require every `.get(` receiver
     * to be one of them. A JS type error inside a Twig template is invisible to
     * PHP tests and to every linter this project runs, so the structural check has
     * to be the right structural check.
     */
    public function test_only_the_url_params_object_is_asked_for_parameters(): void
    {
        $body = (string) file_get_contents($this->repo() . '/' . self::ASSISTANT);
        preg_match('~fromLink\(\)\{(.+?)\n    \},~s', $body, $m);
        $fn = $m[1] ?? '';
        $this->assertNotSame('', $fn, 'Could not isolate fromLink().');

        preg_match_all('~\b(?:var|let|const)\s+([a-zA-Z_$][\w$]*)\s*=\s*new\s+URLSearchParams~', $fn, $params);
        $this->assertNotEmpty($params[1], 'fromLink() must read the query string from URLSearchParams.');

        preg_match_all('~\b([a-zA-Z_$][\w$]*)\.get\(~', $fn, $used);
        foreach (array_unique($used[1]) as $name) {
            $this->assertContains($name, $params[1],
                "fromLink() calls {$name}.get(...), but {$name} is not the URLSearchParams object "
                . '(' . implode(', ', $params[1]) . '). That is a TypeError at runtime, and it '
                . 'takes the entire assistant component down with it.');
        }
    }
}
