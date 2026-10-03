<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use Twig\Environment;
use Twig\TwigFilter;

/**
 * The translation layer: one resolver for "what does this sentence say in the
 * language this request is rendering in".
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE SHAPE, AND WHY EACH PART OF IT IS THAT SHAPE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * ── KEYED BY THE ENGLISH SOURCE, GETTEXT-STYLE ───────────────────────────────
 *
 * `{{ 'Back'|trans }}` — the English IS the key. Every snippet in the design
 * handoff is written that way, and the alternative (`nav.back`) makes somebody
 * invent a name for every string, after which a missing entry renders the NAME —
 * `nav.back` on a public page — or, worse, the name of something else that was
 * copied. Here a missing entry renders the sentence the template already holds, in
 * English, which is the one fallback that is always right to show.
 *
 * English therefore needs no catalogue and has none: identity is the catalogue.
 * An `en.php` would be a second copy of every source string, and the moment the
 * template is edited and the copy is not, the English page would show the old one.
 *
 * ── THE LOCALE IS LanguageMiddleware'S ANSWER, NEVER A SECOND ONE ────────────
 *
 * {@see Languages::current()}, which the middleware primed from the real request.
 * This class does not read `$_GET`, `$_COOKIE` or `Accept-Language`: a second
 * resolver for one value is how a page comes to announce `lang="fr"` while its
 * words are chosen by some other rule. Off the request — a cron mail, a console
 * render — nobody primed it and the answer is English, which is correct.
 *
 * ── PLAIN TEXT OUT, SO AUTOESCAPE STILL APPLIES ──────────────────────────────
 *
 * The filter is deliberately NOT `is_safe`. A catalogue is edited by people who
 * are translating, not reviewing markup, and a translation that happens to contain
 * `<` must render as a `<`. Placeholders (`%name%`, the Symfony convention the
 * snippets assume) are substituted BEFORE escaping, so a nominee's name dropped
 * into a sentence is escaped with the rest of it.
 *
 * ── NEVER EMPTY ──────────────────────────────────────────────────────────────
 *
 * A catalogue entry that is an empty string, or not a string at all, counts as
 * missing. An empty translation renders a button with no label, which is worse
 * than the English one it replaced and gives nobody anything to report.
 *
 * ── A CATALOGUE ENTRY NEEDS A READER ─────────────────────────────────────────
 *
 * `TranslatorTest` fails on any key in `resources/lang/*.php` that no template
 * passes to `|trans` and no PHP passes to this class — CLAUDE.md §17's fault in a
 * catalogue, where it would read as a language being better covered than it is.
 */
final class Translator
{
    /** @var array<string,array<string,string>> code → source → translation, per process */
    private static array $catalogues = [];

    /** Test seam: the directory catalogues are read from. Null means the shipped one. */
    private static ?string $root = null;

    /**
     * The sentence in this request's language, placeholders filled.
     *
     * Read by the Twig `trans` filter, which every template reaches it through.
     *
     * @param array<string,scalar|\Stringable|null> $params e.g. ['%name%' => $n]
     */
    public static function t(string $source, array $params = []): string
    {
        $out = self::translated(Languages::current(), $source) ?? $source;

        if ($params === []) return $out;

        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[(string) $k] = (string) $v;
        }

        return strtr($out, $pairs);
    }

    /**
     * The translation in ONE NAMED language, or null when nobody has written it.
     *
     * Separate from {@see t()} because one surface needs every language at once:
     * the first-visit prompt asks each visitor in THEIR language whatever the page
     * is rendering in, and decides which languages it may offer by whether the
     * question has been written — null is that answer, where `t()` would quietly
     * hand back English and the prompt would ask a Hausa reader in English.
     */
    public static function translated(string $code, string $source): ?string
    {
        $hit = self::catalogue($code)[$source] ?? null;

        return $hit === null || $hit === '' ? null : $hit;
    }

    /**
     * Put `trans` on an environment. The one registration, so the app's environment
     * and every bare one a mailer builds cannot disagree about what it does.
     *
     * Any code building its own `Twig\Environment` to render a template from
     * `templates/` must call this: an unknown filter is a COMPILE error, so the
     * first `|trans` in a shared partial would otherwise take down a mail that
     * renders from a cron tick, where the throw is caught and nobody sees it.
     */
    public static function register(Environment $env): Environment
    {
        $env->addFilter(new TwigFilter(
            'trans',
            static fn (mixed $source, array $params = []): string => self::t((string) $source, $params)
        ));

        return $env;
    }

    /** @return array<string,string> */
    private static function catalogue(string $code): array
    {
        $code = strtolower(trim($code));

        // English is identity, and an unsupported code never reaches the filesystem:
        // it arrives from a query string, and `supported()` is the allowlist.
        if ($code === Languages::DEFAULT || !Languages::supported($code)) return [];

        if (isset(self::$catalogues[$code])) return self::$catalogues[$code];

        $file = (self::$root ?? \dirname(__DIR__, 2) . '/resources/lang') . '/' . $code . '.php';
        $raw  = is_file($file) ? (static fn (string $f): mixed => require $f)($file) : [];

        $clean = [];
        if (is_array($raw)) {
            foreach ($raw as $k => $v) {
                if (is_string($k) && is_string($v) && $v !== '') $clean[$k] = $v;
            }
        }

        return self::$catalogues[$code] = $clean;
    }

    /**
     * Test seam: the memo is per PROCESS and the suite is one process, and a test
     * that points at its own catalogues must not leave them for the next one.
     */
    public static function forget(?string $root = null): void
    {
        self::$catalogues = [];
        self::$root       = $root;
    }
}
