<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * One error bag, keyed by field, carried back to the form that produced it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A BAG AND NOT A STRING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Nearly every form here re-rendered with a single sentence at the top — "Please
 * check the form" — and nothing on the field that was wrong. On a nomination with
 * eleven inputs that is an instruction to go and look, repeatedly, and the person
 * most likely to give up is the one furthest from a mouse: a screen-reader user hears
 * the sentence and then has to walk the whole form to find the field it is about.
 *
 * WCAG 3.3.1 asks for the item in error to be IDENTIFIED, not merely for an error to
 * be announced. So the bag is keyed by field name, the field renders its own message,
 * and a summary at the top links to each one.
 *
 * ── AND THE MESSAGE IS THE SERVER'S, ALWAYS ─────────────────────────────────
 *
 * The browser has its own validation messages and they are worse: untranslated,
 * unstyled, dismissed on the next keystroke, and absent entirely on a form submitted
 * with JavaScript off. More importantly a `pattern` attribute and a server rule are
 * two rules claiming the same thing, which is this codebase's most expensive shape —
 * a pattern stricter than the server refuses *Ngozi Chimamanda Adichie* in a tooltip
 * the person cannot argue with. So the server decides, the browser's native bubble is
 * suppressed with `novalidate`, and the client-side check mirrors the same rules for
 * speed without ever being the authority.
 *
 * ── OLD VALUES TRAVEL WITH THE ERRORS, AND ARE KEYED ────────────────────────
 *
 * A rejected form that comes back empty is a form somebody fills in twice. The old
 * values are kept beside the errors — under a key PER FORM, because this codebase has
 * already shipped the other thing: both halves of `/account/register` stored their
 * rejected values under one `reg_old`, and `name` means a person on one branch and an
 * organisation on the other, so a failed application prefilled the Full name field of
 * the individual form. Nothing threw and nothing looked wrong.
 */
final class FormErrors
{
    private const KEY = 'ag_form_state';

    /** @var array<string,string> field => message */
    private array $errors = [];

    /** @var array<string,mixed> field => what they typed */
    private array $old = [];

    private string $summary = '';

    public function __construct(private readonly string $form) {}

    public static function for(string $form): self
    {
        return new self($form);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Collecting
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Record one field's failure.
     *
     * The FIRST message for a field wins. A field that fails two rules ("required" and
     * "too short") should say the one the person meets first, not stack two sentences
     * that contradict each other.
     */
    public function add(string $field, string $message): self
    {
        if (!isset($this->errors[$field])) $this->errors[$field] = $message;

        return $this;
    }

    /** Record a failure that belongs to no single field — a CSRF timeout, a throttle. */
    public function summary(string $message): self
    {
        $this->summary = $message;

        return $this;
    }

    /** @param array<string,mixed> $values */
    public function keep(array $values, array $except = ['password', 'password2', 'csrf_token']): self
    {
        foreach ($values as $k => $v) {
            // A password is never handed back. It is the one field where refilling is
            // a disclosure rather than a courtesy: the value is readable in the DOM,
            // in a cached page, and over the shoulder of whoever is beside them.
            if (in_array($k, $except, true)) continue;
            if (is_scalar($v) || is_array($v)) $this->old[$k] = $v;
        }

        return $this;
    }

    public function any(): bool
    {
        return $this->errors !== [] || $this->summary !== '';
    }

    /** @return array<string,string> */
    public function all(): array
    {
        return $this->errors;
    }

    public function first(): ?string
    {
        return array_key_first($this->errors);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Travelling
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Put the bag in the session so the redirect-back can render it.
     *
     * Re-rendering in place would be simpler and is wrong on a POST: the person is
     * left on a URL that re-submits on refresh, and the back button re-posts. So the
     * pattern is POST, store, 303, render — and the bag is consumed on the way out so
     * it cannot haunt the next visit to the same page.
     */
    public function flash(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return;

        $_SESSION[self::KEY][$this->form] = [
            'errors' => $this->errors, 'old' => $this->old, 'summary' => $this->summary,
        ];
    }

    /**
     * Take the bag back out, exactly once.
     *
     * @return array{errors:array<string,string>,old:array<string,mixed>,summary:string}
     */
    public static function take(string $form): array
    {
        $empty = ['errors' => [], 'old' => [], 'summary' => ''];

        if (session_status() !== PHP_SESSION_ACTIVE) return $empty;

        $bag = $_SESSION[self::KEY][$form] ?? null;
        unset($_SESSION[self::KEY][$form]);

        if (!is_array($bag)) return $empty;

        return [
            'errors'  => is_array($bag['errors'] ?? null) ? $bag['errors'] : [],
            'old'     => is_array($bag['old'] ?? null) ? $bag['old'] : [],
            'summary' => (string) ($bag['summary'] ?? ''),
        ];
    }

    /** Everything a template needs, under one name. */
    public static function context(string $form): array
    {
        $bag = self::take($form);

        return ['form_name' => $form, 'errors' => $bag['errors'],
                'old' => $bag['old'], 'error_summary' => $bag['summary']];
    }
}
