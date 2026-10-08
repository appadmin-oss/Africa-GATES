<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * WHAT SOMEBODY TYPED, CARRIED BACK ACROSS A REFUSED POST — once.
 *
 * When a form is refused because its page had been open too long (CsrfMiddleware), the
 * person is sent back to the page. Without this they arrive at an empty form: a nomination
 * reason written on a phone, gone, and the instruction "please send it again" reads as a
 * joke. The fields are kept in the session for the one request that follows, drawn into
 * the page as data (`partials/form-replay.twig`, a meta tag — never markup), and
 * `csrf-fresh.js` puts them back into the form that posted them.
 *
 * WHAT IS NEVER KEPT: the token itself, anything that is a secret or a one-time code
 * (passwords, OTPs, card numbers, PINs), and anything past a size cap. A refused request
 * is the one moment nothing should be stored that was not going to be stored anyway, and
 * a credential typed into a session file is exactly that.
 *
 * Only a SAME-ORIGIN post reaches here (the middleware checks first), so a cross-site
 * page cannot seed a visitor's form with words of its choosing.
 */
final class FormReplay
{
    private const KEY = 'form_replay';

    /** A field whose NAME says it holds something that must not be written down. */
    private const SECRET = '~pass|pwd|otp|cvv|cvc|card|secret|token|^pin$|^code$|^digit~i';

    /** Bytes of JSON, at most. A long nomination fits; an upload smuggled as text does not. */
    private const MAX_BYTES = 60000;

    /** @param array<string,mixed> $body the parsed POST body */
    public static function keep(string $action, array $body): void
    {
        $pairs = self::pairs($body);
        if ($pairs === []) { unset($_SESSION[self::KEY]); return; }
        $json = json_encode(['action' => $action, 'fields' => $pairs], JSON_UNESCAPED_UNICODE);
        if ($json === false || strlen($json) > self::MAX_BYTES) { unset($_SESSION[self::KEY]); return; }
        $_SESSION[self::KEY] = $json;
    }

    /** The kept fields as JSON, consumed: a second page view does not see them again. */
    public static function take(): ?string
    {
        $v = $_SESSION[self::KEY] ?? null;
        unset($_SESSION[self::KEY]);

        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * The body as the form's own field names: `a[b]` for nested keys, `a[]` for a list, so
     * the page can match each value to the control that sent it.
     *
     * @return list<array{0:string,1:string}>
     */
    public static function pairs(array $body, string $prefix = ''): array
    {
        $out = [];
        $isList = array_is_list($body);
        foreach ($body as $k => $v) {
            $name = $prefix === '' ? (string) $k : $prefix . ($isList ? '[]' : '[' . $k . ']');
            if ($name === '_token' || $name === '_reason' || preg_match(self::SECRET, (string) $k) === 1) continue;
            if (is_array($v)) { array_push($out, ...self::pairs($v, $name)); continue; }
            if (!is_scalar($v)) continue;
            $out[] = [$name, (string) $v];
        }

        return $out;
    }
}
