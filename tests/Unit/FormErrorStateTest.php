<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\FormErrors;
use PHPUnit\Framework\TestCase;

/**
 * The error state, and the ways a form can be confidently wrong about it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FAULT THIS WAS WRITTEN FOR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `/account/register` marked its EMAIL input as invalid like this:
 *
 *     class="ag-acct__input{% if error %} ag-acct__input--err{% endif %}"
 *
 * `error` is one string for the whole form. So "Password must be at least 8
 * characters" reddened the email box — sending somebody to correct an address that was
 * perfectly fine, with the real problem unmarked two fields below. A confident wrong
 * answer, which is worse than no error state at all.
 *
 * The comment directly above it read "the one failure that has a field to blame: an
 * address already in use", so the intent was right and the condition did not implement
 * it. §19's shape at the view layer.
 *
 * ── AND THE WHOLE-TREE SWEEP, BECAUSE ONE INSTANCE IS NEVER THE FAULT ───────
 *
 * Fixing that line would be an enumeration of past failures. {@see
 * test_an_error_state_is_never_shown_on_a_condition_that_is_not_about_that_field}
 * asks the rule of every template: if a control is given an error class or
 * `aria-invalid` conditionally, the condition has to name that control.
 */
final class FormErrorStateTest extends TestCase
{
    private const TEMPLATES = __DIR__ . '/../../templates';

    // ══════════════════════════════════════════════════════════════════════════
    // The sweep
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * A field only reddens for a failure that is about that field.
     *
     * Proved by breaking it: restoring `{% if error %}` on the register page's email
     * input fails this by name, and nothing else in the suite notices.
     */
    public function test_an_error_state_is_never_shown_on_a_condition_that_is_not_about_that_field(): void
    {
        $bad = [];

        foreach ($this->templates() as $rel => $body) {
            foreach ($this->controls($body) as $tag) {
                // The control's own name, from `name=` first and `id=` second: `name`
                // is what the server keys its bag on.
                $field = $this->fieldOf($tag);
                if ($field === null) continue;

                // ── THE ONE DELIBERATE EXCEPTION, DECLARED IN THE MARKUP ────
                //
                // A sign-in marks its email AND its password together on purpose:
                // reddening only the password would say "that address has an account",
                // which is the enumeration disclosure `/account/login` is explicitly
                // built to avoid — all four states (wrong password on a real account,
                // wrong password on no account, and either once throttled) are told one
                // sentence. A six-box code input is the same field wearing six boxes.
                //
                // So the opt-out is `data-ag-error-group="…"` ON THE CONTROL, where the
                // next person reads it, rather than a list of filenames in this test —
                // an enumeration of past exceptions is never a fix for the next one.
                if (str_contains($tag, 'data-ag-error-group')) continue;

                foreach ($this->conditionalErrorStates($tag) as $condition) {
                    // A condition is "about this field" when it mentions it at all —
                    // `errors.email`, `errors['email']`, `email_err`, `err == 'email'`.
                    // Deliberately generous: the point is to catch a condition with NO
                    // relationship to the control, not to dictate a spelling.
                    if (str_contains($condition, $field)) continue;

                    $bad[] = $rel . ': `' . trim($condition) . '` decides the error state of `'
                        . $field . '`, which it never mentions';
                }
            }
        }

        $this->assertSame([], $bad, "An error state is shown on a condition that is not about "
            . "that field, so the wrong box reddens:\n  - " . implode("\n  - ", $bad) . "\n");
    }

    /**
     * A form that suppresses the browser's bubble must draw its own messages.
     *
     * `novalidate` without the script is a form with NO client-side feedback at all —
     * strictly worse than the native bubble it replaced. The two travel together or
     * neither does.
     */
    public function test_novalidate_and_the_validator_are_never_separated(): void
    {
        $bad = [];

        foreach ($this->templates() as $rel => $body) {
            foreach ($this->formTags($body) as $tag) {
                $nov = str_contains($tag, 'novalidate');
                $val = str_contains($tag, 'data-ag-validate');

                if ($nov && !$val) $bad[] = "$rel: `novalidate` with no `data-ag-validate` — the "
                    . 'native bubble is off and nothing replaced it';
                if ($val && !$nov) $bad[] = "$rel: `data-ag-validate` without `novalidate` — the "
                    . 'browser bubble and this site\'s message both fire, saying different things';
            }
        }

        $this->assertSame([], $bad, implode("\n  ", $bad));
    }

    /**
     * …and the validator a form promises is actually LOADED on its layout.
     *
     * The sweep above reads the form tag, which is the right token for "were the two
     * attributes separated" and the wrong one for "does anything answer the second".
     * `layout/shell.twig` — the nomination form's layout — loaded neither
     * form-validate.js nor forms.css, so `novalidate data-ag-validate` there turned the
     * browser's messages off on a promise nothing kept, and the attribute check passed.
     */
    public function test_every_layout_carrying_a_validated_form_loads_the_validator(): void
    {
        $root = dirname(__DIR__, 2) . '/templates';
        $bad  = [];
        foreach ($this->templates() as $rel => $body) {
            if (!str_contains($body, 'data-ag-validate')) continue;
            // The whole `extends` chain, not the parent alone: account-auth.twig loads
            // neither file and extends gates.twig, which loads both. Reading one level
            // reported the registration page as unvalidated when it was not.
            $chain = '';
            $names = [];
            $cur = $body;
            while (preg_match('~\{%\s*extends\s+[\'"]([^\'"]+)[\'"]~', $cur, $m) && count($names) < 6) {
                $names[] = $m[1];
                $cur = (string) @file_get_contents($root . '/' . $m[1]);
                $chain .= $cur;
            }
            if ($names === []) continue;
            foreach (['/assets/js/form-validate.js', '/assets/css/components/forms.css'] as $need) {
                if (!str_contains($chain, $need)) $bad[] = "{$rel} → " . implode(' → ', $names) . " does not load {$need}";
            }
        }
        $this->assertSame([], array_values(array_unique($bad)), implode("\n  ", array_unique($bad)));
    }

    /**
     * The error sentence is TEXT, never a colour or an icon alone (WCAG 1.4.1).
     *
     * `--ag-error` is `#b42318` on the house paper. Somebody who cannot separate it from
     * the ink gets nothing from a red border, and the icon is `aria-hidden` by design.
     */
    public function test_the_error_message_carries_a_sentence_and_hides_its_icon(): void
    {
        $macro = (string) file_get_contents(self::TEMPLATES . '/partials/field.twig');

        $this->assertStringContainsString('{{ err }}', $macro,
            'the message macro does not print the sentence');
        $this->assertStringContainsString('aria-hidden="true"', $macro,
            'the error icon is not hidden from assistive technology, so it is read as noise');
        $this->assertMatchesRegularExpression('/class="ag-err"\s+id="\{\{ id \}\}-err"/', $macro,
            'the message has no stable id, so aria-describedby cannot point at it');
    }

    /** The CSS must not express the invalid state by colour alone. */
    public function test_the_invalid_field_is_not_signalled_by_colour_alone(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../public/assets/css/components/forms.css');

        $this->assertStringContainsString('box-shadow:inset', $css,
            'the invalid field changes only its border colour');
        // Focus has to win, or a keyboard user inside an error loses "where am I".
        $this->assertStringContainsString('.ag-field:focus-within{ box-shadow:none }', $css);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The bag
    // ══════════════════════════════════════════════════════════════════════════

    protected function setUp(): void
    {
        parent::setUp();
        // The bag is a no-op without an active session, so these cases would pass
        // vacuously — green while proving nothing, which is the shape this repo keeps
        // paying for. The CLI SAPI starts one without sending headers.
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $_SESSION = [];

        self::assertSame(PHP_SESSION_ACTIVE, session_status(),
            'no session, so FormErrors::flash() silently does nothing and these tests '
            . 'would pass over a bag that never stored anything');
    }

    public function test_the_first_message_for_a_field_wins(): void
    {
        $b = FormErrors::for('t')->add('email', 'Required.')->add('email', 'Not an address.');

        $this->assertSame(['email' => 'Required.'], $b->all(),
            'two sentences stacked on one field, and they contradict each other');
    }

    /**
     * A password is never handed back.
     *
     * It is the one field where refilling is a disclosure rather than a courtesy: the
     * value is readable in the DOM, in a cached page, and over the shoulder of whoever
     * is sitting beside them.
     */
    public function test_a_password_is_never_carried_back(): void
    {
        $b = FormErrors::for('t')->keep([
            'name' => 'Ada', 'email' => 'a@b.c', 'password' => 'hunter2', 'csrf_token' => 'x',
        ]);

        $r = new \ReflectionProperty($b, 'old');
        $old = $r->getValue($b);

        $this->assertArrayNotHasKey('password', $old);
        $this->assertArrayNotHasKey('csrf_token', $old);
        $this->assertSame('Ada', $old['name']);
    }

    /**
     * Two branches of one form keep separate bags.
     *
     * This codebase has shipped the other thing: both halves of `/account/register`
     * stored rejected values under one `reg_old`, and `name` is a person on one branch
     * and an organisation on the other — so a failed application for "Bright Futures
     * Initiative" prefilled the Full name field of the individual form.
     */
    public function test_two_forms_do_not_share_a_bag(): void
    {
        FormErrors::for('register_individual')->add('name', 'Your full name, please.')->flash();
        FormErrors::for('register_organisation')->add('name', 'Your registered name, please.')->flash();

        $ind = FormErrors::take('register_individual');
        $org = FormErrors::take('register_organisation');

        $this->assertSame('Your full name, please.', $ind['errors']['name']);
        $this->assertSame('Your registered name, please.', $org['errors']['name']);
    }

    /** Taken once, so it cannot haunt the next visit to the same page. */
    public function test_a_bag_is_consumed_on_the_way_out(): void
    {
        FormErrors::for('once')->add('email', 'Nope.')->flash();

        $this->assertSame(['email' => 'Nope.'], FormErrors::take('once')['errors']);
        $this->assertSame([], FormErrors::take('once')['errors'],
            'the error survived its own render and will accuse the next visitor');
    }

    public function test_a_failure_with_no_field_is_a_summary_and_reddens_nothing(): void
    {
        $b = FormErrors::for('t')->summary('That is several accounts from this connection.');

        $this->assertTrue($b->any());
        $this->assertSame([], $b->all(),
            'a throttle was attributed to a field, sending somebody to fix a correct value');
    }

    /**
     * Every refusal the register validator can produce names a field.
     *
     * The one exception is deliberate and is the throttle, which is about the
     * connection rather than anything typed.
     */
    public function test_every_registration_refusal_names_its_field(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/Services/UserAccountService.php');

        // Only inside register(), which is where the form's refusals live.
        $from = strpos($src, 'public function register(');
        $this->assertNotFalse($from);
        $body = substr($src, $from, (int) (strpos($src, "\n    public function", $from + 10) - $from));

        preg_match_all("/\['ok' => false[^\]]*\]/", $body, $m);
        $this->assertNotEmpty($m[0], 'no refusals found — the parser lost the method body');

        foreach ($m[0] as $refusal) {
            $this->assertStringContainsString("'field' =>", $refusal,
                "a refusal with no field will redden whichever box the template guesses: $refusal");
        }
    }

    // ══════════════════════════════════════════════════════════════════════════

    /** @return array<string,string> rel => body */
    private function templates(): array
    {
        $out = [];
        $it  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::TEMPLATES));

        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'twig') continue;
            $body = (string) file_get_contents($f->getPathname());
            // A Twig comment reaches nobody, so it was never in scope — and stripping
            // it is what lets the comment above a fix NAME the thing it removed
            // without tripping the sweep that documents it.
            $body = (string) preg_replace('/\{#.*?#\}/s', '', $body);
            $out[str_replace(self::TEMPLATES . '/', '', $f->getPathname())] = $body;
        }

        return $out;
    }

    /** @return list<string> every <input>/<select>/<textarea> tag */
    private function controls(string $body): array
    {
        preg_match_all('/<(?:input|select|textarea)\b[^>]*>/is', $body, $m);

        return $m[0];
    }

    /** @return list<string> every <form> tag */
    private function formTags(string $body): array
    {
        preg_match_all('/<form\b[^>]*>/is', $body, $m);

        return $m[0];
    }

    /** `name` first, `id` second — the server keys its bag on the name. */
    private function fieldOf(string $tag): ?string
    {
        // A Twig-interpolated name cannot be compared to anything, so it is skipped
        // rather than guessed at.
        if (preg_match('/\bname="([a-zA-Z0-9_\[\]]+)"/', $tag, $m)) return rtrim($m[1], '[]');
        if (preg_match('/\bid="([a-zA-Z0-9_-]+)"/', $tag, $m)) return $m[1];

        return null;
    }

    /**
     * The conditions guarding an error state inside one tag.
     *
     * Only CONDITIONAL ones: a control rendered by a macro that is already inside an
     * `{% if err %}` has its condition elsewhere, and an unconditional error class is
     * a static demo (`dev-ui.twig`) rather than a live state.
     *
     * @return list<string>
     */
    private function conditionalErrorStates(string $tag): array
    {
        $out = [];

        // `{% if … %} …--err…` and `{% if … %}aria-invalid` — the two ways a control
        // is marked. The `[^%]*` keeps it inside one tag's own if-expression.
        preg_match_all('/\{%\s*if\s+(.+?)\s*%\}[^{]*?(?:--err|aria-invalid|data-invalid)/is', $tag, $m);

        foreach ($m[1] as $cond) $out[] = $cond;

        return $out;
    }
}
