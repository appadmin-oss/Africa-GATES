<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The eight languages this platform offers, and the one place they are listed.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THIS IS AND, JUST AS IMPORTANTLY, WHAT IT IS NOT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * This is the language MECHANISM: the supported set, the cookie, the `?lang=`
 * parameter, and the `lang` and `dir` attributes that go on `<html>`. Choosing a
 * language here really does change the document's language and direction, and the
 * choice really does survive a reload.
 *
 * The WORDS are {@see Translator}'s: the `trans` filter, and catalogues in
 * `resources/lang/{code}.php` keyed by the English source string. That layer reads
 * {@see current()} and nothing else, so this class stays the one answer to "which
 * language is this request in" and the translator is the one answer to "what does
 * this sentence say in it". The product owner decided to build it before the
 * redesign's first phase (`docs/handoff/GAPS.md` Q12), so the rebuilt screens are
 * translatable from their first commit.
 *
 * The catalogues are close to empty today, and that is the honest state: a string is
 * translated when a template passes it through `|trans` AND a speaker has written it.
 * Until then picking Yorùbá sets `lang="yo"`, tells a screen reader which voice to
 * use, and leaves the copy in English — which is worth shipping on its own: `lang`
 * drives pronunciation, hyphenation and the browser's own offer to translate, and
 * `dir="rtl"` is what the entire RTL requirement of the acceptance protocol is
 * measured against.
 *
 * What would NOT be worth shipping is a language menu that silently does nothing,
 * so the surfaces that offer these say so in one line.
 *
 * ── THE NAME IS WRITTEN IN THE LANGUAGE ITSELF ───────────────────────────────
 *
 * "Français", not "French". Somebody looking for their own language is scanning
 * for the word they would use, and they cannot be assumed to read the word the
 * current interface would use for it. The English name rides alongside as the
 * second line, for the case where the list is being read by somebody choosing on
 * another person's behalf.
 *
 * Each option also carries `lang` on its own element, so a screen reader announces
 * "Français" in French rather than reading it as though it were English.
 */
final class Languages
{
    /**
     * Code → the three facts about it.
     *
     * Order is deliberate: English first because it is the fallback and the
     * interface language today, then by the size of the audience this platform
     * actually reaches.
     *
     * The first-visit prompt's words (`ask`, `yes`) used to live here too, as the
     * only translated strings in the codebase. They are in the catalogues now, so a
     * sentence has exactly one place it is written in each language; {@see options()}
     * reads them back so a template sees the same shape it always did.
     */
    public const ALL = [
        'en' => ['name' => 'English',   'english' => 'English',    'dir' => 'ltr'],
        'fr' => ['name' => 'Français',  'english' => 'French',     'dir' => 'ltr'],
        'ar' => ['name' => 'العربية',    'english' => 'Arabic',     'dir' => 'rtl'],
        'sw' => ['name' => 'Kiswahili', 'english' => 'Swahili',    'dir' => 'ltr'],
        'pt' => ['name' => 'Português', 'english' => 'Portuguese', 'dir' => 'ltr'],
        'ha' => ['name' => 'Hausa',     'english' => 'Hausa',      'dir' => 'ltr'],
        'yo' => ['name' => 'Yorùbá',    'english' => 'Yoruba',     'dir' => 'ltr'],
        'ig' => ['name' => 'Igbo',      'english' => 'Igbo',       'dir' => 'ltr'],
    ];

    public const COOKIE  = 'ag_lang';
    public const DEFAULT = 'en';

    /** A year. The choice is a preference, not a session. */
    public const TTL = 31536000;

    /** Whether a code is one we offer. Never trust a query parameter. */
    public static function supported(mixed $code): bool
    {
        return is_string($code) && array_key_exists(strtolower(trim($code)), self::ALL);
    }

    /**
     * The language to render in: `?lang=` first, then the cookie, then English.
     *
     * The parameter wins so a link can carry a language — somebody sharing a page
     * with a friend who reads Hausa should be able to send them the Hausa URL —
     * and the handler that reads it writes the cookie, so the next page keeps it.
     *
     * @param array<string,mixed> $query
     * @param array<string,mixed> $cookies
     */
    public static function resolve(array $query, array $cookies): string
    {
        $q = $query['lang'] ?? null;
        if (self::supported($q)) return strtolower(trim((string) $q));

        $c = $cookies[self::COOKIE] ?? null;
        if (self::supported($c)) return strtolower(trim((string) $c));

        return self::DEFAULT;
    }

    /** `ltr` or `rtl`. Only Arabic is right-to-left in this set. */
    public static function dir(string $code): string
    {
        return self::ALL[strtolower($code)]['dir'] ?? 'ltr';
    }

    /** The name in that language — "Français", never "French". */
    public static function name(string $code): string
    {
        return self::ALL[strtolower($code)]['name'] ?? self::ALL[self::DEFAULT]['name'];
    }

    /**
     * The list a template loops over.
     *
     * @return list<array{code:string,name:string,english:string,dir:string,ask:string,yes:string}>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::ALL as $code => $l) {
            $out[] = ['code' => $code] + $l + self::promptWords($code);
        }
        return $out;
    }

    /**
     * The first-visit prompt's question and its "yes", IN THAT LANGUAGE, or '' each.
     *
     * Asked of the catalogue for that language rather than through `|trans`, because
     * the prompt is not in the page's language: it asks a visitor in theirs, and the
     * page they are reading is still English. And '' rather than a fallback, because
     * an English fallback here would ask a Hausa reader in English — the one thing
     * the prompt exists not to do. The decline, "Keep English", stays in English on
     * purpose: it is the option it describes, and a reader who wants it can read it.
     *
     * @return array{ask:string,yes:string}
     */
    private static function promptWords(string $code): array
    {
        return [
            'ask' => Translator::translated($code, 'View Africa GATES in your language?') ?? '',
            'yes' => Translator::translated($code, 'Yes') ?? '',
        ];
    }

    /**
     * The languages the first-visit prompt can be written in.
     *
     * English is excluded because the prompt offers a way OUT of English, and a
     * language whose catalogue lacks the question OR its "yes" is excluded because a
     * prompt nobody has written is a prompt that would have to be composed here —
     * which is how a placeholder, or a button with no label, reaches a person's
     * screen.
     *
     * @return list<array{code:string,name:string,english:string,dir:string,ask:string,yes:string}>
     */
    public static function prompts(): array
    {
        return array_values(array_filter(
            self::options(),
            static fn (array $l): bool => $l['code'] !== self::DEFAULT && $l['ask'] !== '' && $l['yes'] !== ''
        ));
    }

    // ══════════════════════════════════════════════════════════════════════════
    // THE PER-REQUEST ANSWER
    // ══════════════════════════════════════════════════════════════════════════
    //
    // Resolved once, by LanguageMiddleware, from the real request — and read at
    // RENDER time through Twig functions. It is deliberately not a Twig global:
    // globals are computed when the container is built, which has no Request, so a
    // global could only answer by reading $_COOKIE and $_GET itself. That would be
    // a second resolver for one value, which is the fault this class exists to
    // avoid. CookiePrefs settles the same question the same way and says so.

    /** @var string|null the memo primed by {@see observe()}; null means nobody asked */
    private static ?string $current = null;

    /** @var bool whether this visitor has already answered the language question */
    private static bool $chosen = false;

    /** @var array<string,string> code → this URL with `lang` set to that code */
    private static array $urls = [];

    /** @var array<string,mixed> this request's query string, for {@see carry()} */
    private static array $query = [];

    /**
     * Settle the language for this request.
     *
     * `$chosen` is the COOKIE's presence and not the resolved language, because the
     * first-visit prompt's condition is "has this person been asked", and somebody
     * whose browser asks for French but who has never answered resolves to English
     * exactly like somebody who chose it. Reading the resolved value instead would
     * make the prompt unaskable for anybody, which is the shape of silent failure
     * that hides for a year.
     */
    public static function observe(Request $request): void
    {
        $query   = $request->getQueryParams();
        $cookies = $request->getCookieParams();

        self::$current = self::resolve($query, $cookies);
        self::$chosen  = self::supported($cookies[self::COOKIE] ?? null)
                       || self::supported($query['lang'] ?? null);
        self::$urls    = self::buildUrls($request, $query);
        self::$query   = $query;
    }

    /** The language this request is rendering in. */
    public static function current(): string
    {
        return self::$current ?? self::DEFAULT;
    }

    /** `ltr` or `rtl` for this request — what goes on `<html dir>`. */
    public static function currentDir(): string
    {
        return self::dir(self::current());
    }

    /** Has this visitor answered the language question at all? */
    public static function chosen(): bool
    {
        return self::$chosen;
    }

    /**
     * Should the first-visit prompt be rendered on this request?
     *
     * The server answers the COOKIE half; the browser answers the `navigator.languages`
     * half, because only it can. Rendering the rows costs nothing when the script never
     * reveals one, and it means the prompt carries no JSON in an attribute — the shape
     * that silently shredded the flier's style list when the HTML parser closed the
     * attribute at its first quote.
     */
    public static function shouldAsk(): bool
    {
        return !self::$chosen;
    }

    /**
     * Code → the current URL with `lang` set to it.
     *
     * Built rather than written as `?lang=xx` in a template, because a bare `?lang=xx`
     * href REPLACES the whole query string: switching language on `/discover?q=lagos`
     * would silently drop the search somebody had just run, and the page would come back
     * looking like the language switch had broken it.
     *
     * @return array<string,string>
     */
    public static function urls(): array
    {
        return self::$urls;
    }

    /**
     * Everything else in this request's query string, for a form to carry as hidden
     * fields.
     *
     * The Display & reading select is a real GET form so it works with scripting off,
     * and a GET form submits ONLY its own fields — so without these, changing language
     * on `/discover?q=lagos` would come back with the search gone and read as the
     * language switch having broken the page.
     *
     * Scalars only. An array parameter (`?tag[]=a&tag[]=b`) would need a name per
     * element, and no surface that carries one offers this control; dropping it is
     * visible, where flattening it to the string "Array" would not be.
     *
     * @return array<string,string>
     */
    public static function carry(): array
    {
        $out = [];
        foreach (self::$query as $k => $v) {
            if ($k === 'lang' || !is_scalar($v)) continue;
            $out[(string) $k] = (string) $v;
        }

        return $out;
    }

    /**
     * The URL that switches to one language, keeping every other parameter.
     *
     * Falls back to `?lang=xx` when nobody primed the memo — a console render or a
     * test that never went through the middleware — so a template calling this can
     * never emit an empty `href`, which would reload the page and look like the
     * control simply not working.
     */
    public static function url(string $code): string
    {
        return self::$urls[strtolower($code)] ?? ('?lang=' . rawurlencode(strtolower($code)));
    }

    /** @param array<string,mixed> $query @return array<string,string> */
    private static function buildUrls(Request $request, array $query): array
    {
        $out = [];
        try {
            $path = $request->getUri()->getPath();
        } catch (\Throwable) {
            $path = '/';
        }

        foreach (array_keys(self::ALL) as $code) {
            $q = $query;
            $q['lang'] = $code;
            $out[$code] = $path . '?' . http_build_query($q);
        }

        return $out;
    }

    /** Test seam: the memo is per PROCESS and the suite is one process. */
    public static function forget(): void
    {
        self::$current = null;
        self::$chosen  = false;
        self::$urls    = [];
        self::$query   = [];
    }

    /**
     * Write the choice onto a response.
     *
     * A PSR-7 header rather than `setcookie()`, for the reason CookiePrefs::apply()
     * gives: the response is the thing being returned, and a side effect on the global
     * output buffer is invisible to every test that renders the route.
     *
     * `HttpOnly`, because nothing in any page needs to read it. The language is already
     * on `<html lang>` in the markup the server sent, and the prompt's own condition is
     * answered server-side by {@see shouldAsk()} — so handing scripts one more stable
     * value to read would widen the fingerprinting surface to save a round trip nobody
     * is making.
     */
    public static function apply(Response $response, string $code): Response
    {
        $parts = [
            self::COOKIE . '=' . (self::supported($code) ? strtolower(trim($code)) : self::DEFAULT),
            'Path=/',
            'Max-Age=' . self::TTL,
            'HttpOnly',
            'SameSite=Lax',
        ];

        if (self::secure()) $parts[] = 'Secure';

        return $response->withAddedHeader('Set-Cookie', implode('; ', $parts));
    }

    /**
     * Secure, exactly as the session cookie was decided to be.
     *
     * Read from the live session parameters rather than re-deriving it, because
     * public/index.php already settles "is this deployment on HTTPS" with a documented
     * tri-state, and a second copy here would be a second answer to it.
     */
    private static function secure(): bool
    {
        try {
            return (bool) (session_get_cookie_params()['secure'] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }
}
