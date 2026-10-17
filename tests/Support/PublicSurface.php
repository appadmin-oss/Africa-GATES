<?php
declare(strict_types=1);

namespace Tests\Support;

/**
 * WHAT "THE PUBLIC SURFACE" MEANS TO A TYPE SWEEP, AND HOW ONE READS IT.
 *
 * One answer for `TypeScaleTest` and `MonoAndCaseTest`, because two sweeps disagreeing
 * about which files they cover is worse than either being loose — the same reason
 * `ColourFields` exists.
 *
 * ── THE SCOPE IS BY EXCLUSION, AND THE EXCLUSIONS ARE OTHER SURFACES ─────────
 *
 * The owner decided the handoff's type rules (3 Oct 2026, GAPS §8 Q4/Q5) for the PUBLIC
 * site. Two consoles sit outside it, for two different reasons:
 *
 *  - THE ADMIN CONSOLE is no longer held (owner, 4 Oct 2026, GAPS §8d): it was destroyed
 *    and rebuilt from its own handoff, which has its OWN ladder (README §10). The owner
 *    asked for the public rules to be held strictly on the public surface, so the console
 *    is out of THIS scope by name and inside {@see ConsoleSurface}, where ConsoleTypeTest
 *    holds the console's ladder and the two rules the surfaces share — no capitals, and
 *    monospace only for what is copied or counted. Its templates are `templates/admin/`
 *    and its sheets are `public/assets/css/console/`.
 *  - THE JUDGE CONSOLE is still held: `templates/judge/` and the stylesheets only it
 *    links — `judge.css`, `main.css` and `aurora.css` (the judge layout's), and
 *    `components/auth.css`, whose only remaining readers are `admin/login.twig`,
 *    `admin/magic.twig` and `judge/login.twig` — the member sign-in it once also served
 *    was destroyed with the old pages; and `a11y.css`, linked by `judge/layout.twig` alone
 *    since the admin console carries its own corrections (GAPS §7.3 lists it as held). Its
 *    16px input floor is in px, which the rem rule (TypeScaleTest) refuses on a public
 *    screen; the public input floor is shell.css's, in rem;
 *  - `templates/partials/viz.twig`, which sits in `partials/` but whose only includers
 *    are `admin/stands/index.twig` and `admin/stands/_viz.twig` — an admin screen's
 *    charts, held with the console. `test_the_held_partials_are_included_only_by_held_screens`
 *    keeps that true: the day a public page includes it, it is public type again;
 *  - THE DOOR SCANNER — `templates/pages/events/door.twig` (served at `/door/{token}`)
 *    and `public/assets/css/components/door.css`, which only that template links. The
 *    owner decided on 3 Oct 2026 that the door is HELD like the consoles: it is a staff
 *    tool, used by a steward at a gate on a phone held at arm's length in bad light, not
 *    a page the public reads, and the redesign handoff does not draw it (REFERENCE
 *    §18.7 leaves it undesigned; GAPS §5 lists `/door/{token}` among the undesigned
 *    surfaces). Its 10px mono capitals are a scanner's status readout, and whether they
 *    stay is the owner's call about that tool, not a public type question. Held does not
 *    mean unwatched: `TypeScaleTest::test_the_held_door_sheet_is_linked_only_by_the_held_door`
 *    fails the day any other template links the sheet, because from that moment its
 *    sizes are public type this scope would be hiding;
 *  - `vendor/`, pinned third-party code a version bump reverts invisibly.
 *
 * Everything else is IN by default, so a new public stylesheet or template is covered
 * the day it lands rather than the day somebody remembers to list it. That is also why
 * `test_the_held_sheets_are_not_on_the_public_surface` exists in `TypeScaleTest`: an
 * exclusion is only honest while no public page links the excluded file. The moment a
 * public layout links `main.css`, every size in it is public type that this scope is
 * hiding.
 *
 * What it does NOT read: JavaScript. A script that writes `el.style.fontSize` is type
 * this sweep cannot see. Said here so a clean pass is not read as covering it.
 */
final class PublicSurface
{
    /** Template directories the owner holds. */
    public const HELD_TEMPLATE_DIRS = ['templates/admin/', 'templates/judge/'];

    /** Templates outside the held directories that only held screens include. */
    public const HELD_TEMPLATES = [
        'templates/partials/viz.twig',
        'templates/pages/events/door.twig',
    ];

    /** The door scanner's own sheet, and the one template allowed to link it (owner, 3 Oct 2026). */
    public const DOOR_CSS = 'public/assets/css/components/door.css';
    public const DOOR_TEMPLATE = 'templates/pages/events/door.twig';

    /** Stylesheets only the held consoles link, with the reason in the docblock above. */
    public const HELD_CSS = [
        'public/assets/css/judge.css',
        'public/assets/css/main.css',
        'public/assets/css/aurora.css',
        'public/assets/css/components/auth.css',
        'public/assets/css/a11y.css',
        self::DOOR_CSS,
    ];

    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Every public template and stylesheet, as rel => absolute path.
     *
     * @param bool $emails whether `templates/emails/` is in. Mail is public type (it
     *                     reaches the same people) and the scale holds it; the mono and
     *                     case rules are the handoff's for SCREENS and say nothing about
     *                     a mail client, so that guard passes false.
     * @return array<string,string>
     */
    public static function files(bool $emails): array
    {
        $root = self::root();
        $out  = [];
        foreach (['templates', 'public/assets/css'] as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                /** @var \SplFileInfo $f */
                $abs = $f->getPathname();
                if (!preg_match('~\.(twig|css)$~', $abs)) continue;
                $rel = ltrim(str_replace($root, '', $abs), '/');
                if (str_contains($rel, '/vendor/')) continue;
                if (str_starts_with($rel, ConsoleSurface::CSS_DIR)) continue;   // the admin console's own surface
                if (in_array($rel, self::HELD_CSS, true)) continue;
                if (in_array($rel, self::HELD_TEMPLATES, true)) continue;
                foreach (self::HELD_TEMPLATE_DIRS as $held) {
                    if (str_starts_with($rel, $held)) continue 2;
                }
                if (!$emails && str_starts_with($rel, 'templates/emails/')) continue;
                $out[$rel] = $abs;
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Comments and Twig tags blanked to spaces, newlines kept, so every offset and line
     * number in the result is the source's.
     *
     * A comment is reached by nobody, so it was never in scope — and blanking it rather
     * than deleting it is what lets a comment above a rule NAME the thing the rule
     * forbids. (CLAUDE.md: a sweep that trips on the comment documenting a removal makes
     * comments unwritable.) Twig expressions are blanked too: `{{ c.action }}` inside an
     * inline style is not CSS, and its braces would otherwise open a block.
     */
    public static function strip(string $src, bool $twig): string
    {
        $blank = static fn(array $m): string => preg_replace('~[^\n]~', ' ', $m[0]);
        $src = (string) preg_replace_callback('~/\*.*?\*/~s', $blank, $src);
        if ($twig) {
            $src = (string) preg_replace_callback('~\{#.*?#\}~s', $blank, $src);
            $src = (string) preg_replace_callback('~<!--.*?-->~s', $blank, $src);
            $src = (string) preg_replace_callback('~\{\{.*?\}\}|\{%.*?%\}~s', $blank, $src);
        }
        return $src;
    }

    public static function lineAt(string $text, int $offset): int
    {
        return substr_count($text, "\n", 0, $offset) + 1;
    }

    /**
     * Every CSS rule in a file as [selector, body, offset-of-body].
     *
     * Reads innermost blocks only, so a rule inside `@media (…){ … }` is found with its
     * own selector and the at-rule's prelude is never mistaken for one. In a template,
     * the CSS is the contents of `<style>` elements and of every `style="…"` attribute;
     * an attribute's "selector" is its own element and classes, which is the only name
     * an inline style has.
     *
     * @return list<array{0:string,1:string,2:int}>
     */
    public static function rules(string $stripped, bool $twig): array
    {
        $out = [];
        $sheets = $twig ? [] : [[$stripped, 0]];
        if ($twig) {
            preg_match_all('~<style\b[^>]*>(.*?)</style>~is', $stripped, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[1] as [$css, $at]) $sheets[] = [$css, $at];
        }
        foreach ($sheets as [$css, $base]) {
            preg_match_all('~([^{}]*)\{([^{}]*)\}~', $css, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[1] as $i => [$sel]) {
                $sel = trim((string) preg_replace('~^.*;~s', '', $sel));
                $out[] = [$sel, $m[2][$i][0], $base + $m[2][$i][1]];
            }
        }
        if ($twig) {
            preg_match_all('~<([a-zA-Z][\w-]*)\b([^>]*)>~', $stripped, $tags, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
            foreach ($tags as $t) {
                $attrs = $t[2][0];
                if (!preg_match('~\bstyle\s*=\s*(["\'])(.*?)\1~s', $attrs, $s, PREG_OFFSET_CAPTURE)) continue;
                $sel = strtolower($t[1][0]);
                if (preg_match('~\bclass\s*=\s*(["\'])(.*?)\1~s', $attrs, $c)) {
                    foreach (preg_split('~\s+~', trim($c[2])) ?: [] as $cls) {
                        if ($cls !== '') $sel .= '.' . $cls;
                    }
                }
                $out[] = [$sel, $s[2][0], $t[2][1] + $s[2][1]];
            }
        }
        return $out;
    }

    /**
     * The declarations in a rule body as [property, value, offset-within-body].
     *
     * @return list<array{0:string,1:string,2:int}>
     */
    public static function declarations(string $body): array
    {
        // A declaration is the FIRST `name:` of its `;`-separated segment, never any
        // colon in it — `url(https://…)` and `a:hover` are not properties.
        $out = [];
        $at  = 0;
        foreach (explode(';', $body) as $seg) {
            if (preg_match('~^(\s*)(--[\w-]+|[a-zA-Z-]+)\s*:(.*)$~s', $seg, $m)) {
                $out[] = [strtolower($m[2]), trim($m[3]), $at + strlen($m[1])];
            }
            $at += strlen($seg) + 1;
        }
        return $out;
    }
}
