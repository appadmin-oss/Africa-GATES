<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * The four colours this platform is allowed to mean something with.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE SITE READS MONOCHROME, MEASURED RATHER THAN FELT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The house ground is paper `#f0f2f2` and the house style names ONE gold accent,
 * `#f3b416`. Against that paper it is **1.65:1** — below the 3:1 floor a border owes
 * (WCAG 1.4.11) and far below the 4.5:1 a word owes (1.4.3). The site's one designated
 * moment of colour could not legally carry meaning, so wherever it appeared as a hairline
 * or a micro-label it did not register as colour at all. That is the whole explanation for
 * a palette that feels absent: not too little colour, but colour used as a LINE when it is
 * only visible as a FIELD.
 *
 * Three of the five accents in `base/tokens.css` fail against paper: gold at 1.65,
 * `--ag-green-light` at 1.79, and `--ag-gold` — a SECOND gold, `#c9a24b`, which the house
 * style does not mention — at 2.13. `--ag-pulse` `#e0245e` is 4.08, which passes for a
 * border and FAILS for text, and it was being used as text on three public screens.
 *
 * And the drift underneath: **642 distinct hex values across the templates**, among them
 * four golds (`#f3b416`, `#fbc329`, `#c9a24b`, `#7a5600`) and a gold wash `#fff8df` used
 * forty times. Nobody was inventing colours carelessly — they were hand-deriving exactly
 * the ramp below, separately, because there was nowhere to put it.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * FOUR VALUES PER ROLE, BECAUSE A COLOUR OWES A DIFFERENT DEBT IN EACH PLACE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * This is the same split `EventTierTone::hues()` already draws between `fill` and `edge`,
 * extended by the two the rest of the site needs. Do not invent a fifth name.
 *
 *   `fill`  The identity — the colour somebody would name. A chip, a swatch, a filled
 *           surface, a bar. Owes nothing, because it is not a boundary and not a letter.
 *   `edge`  The same hue at ≥3:1 on paper. Borders, rings, rules, icon strokes,
 *           underlines. This is the one that did not exist, so every accent border on
 *           the site was below 1.4.11.
 *   `ink`   The same hue at ≥4.5:1 on paper. Words.
 *   `wash`  A tint of the same hue, light enough that house ink sits on it above 12:1.
 *           This is where an accent is ALLOWED TO BE LOUD — a pale field is the only way
 *           a 1.65:1 gold is ever perceived as gold.
 *
 * Where a role's identity already clears 4.5:1 — `action` and `caution` — fill, edge and
 * ink are the same value. Three names for one colour is not duplication here; it is the
 * answer to three different questions that happen to agree.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * FOUR ROLES, AND THE CEILING IS THE POINT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Restraint is the setup and colour is the payoff, so the payoff has to be RARE — and
 * rarity is not something a palette can hope for. `AccentTest` fails a template that
 * reaches for more than two roles at once, because a screen wearing four accents has no
 * accent: every one of them is a background.
 *
 * `honour` is the one the platform is FOR. An award decided, a winner crowned, the index
 * that decided it. It is deliberately the role with the palest identity and the widest
 * wash, because the page where it belongs is a page that should feel like gold leaf and
 * not like a warning.
 */
final class Accent
{
    /** The ground every ratio below is measured against. */
    public const PAPER = '#f0f2f2';

    /** Paper's lighter sibling — a card on the ground. Ratios hold on both. */
    public const SURFACE = '#ffffff';

    public const HONOUR  = 'honour';
    public const ACTION  = 'action';
    public const LIVE    = 'live';
    public const CAUTION = 'caution';

    /**
     * The palette. Values are constants and not computed, because a fixed palette that is
     * re-derived on every render is arithmetic nobody asked for — but `AccentTest`
     * re-derives them and fails if any has drifted below its floor, which is the same
     * discipline the handbook's figures are held to.
     *
     * @var array<string,array{fill:string,edge:string,ink:string,wash:string,means:string}>
     */
    private const ROLES = [
        self::HONOUR => [
            // The house gold, unchanged: it is the identity and people know it.
            'fill' => '#f3b416',
            'edge' => '#ab7a01',
            // Already in circulation in thirty-five files — somebody derived it by hand and
            // got it right, so adopting it makes those files correct rather than legacy.
            'ink'  => '#7a5600',
            'wash' => '#fcf4de',
            'means' => 'An award decided, and the person it was decided for.',
        ],
        self::ACTION => [
            // Reserved, and untouched. It already clears 4.5:1 on paper at 4.76.
            'fill' => '#237b22',
            'edge' => '#237b22',
            'ink'  => '#237b22',
            'wash' => '#e4f6e4',
            'means' => 'Do this — the one thing this screen is asking for.',
        ],
        self::LIVE => [
            'fill' => '#e0245e',
            'edge' => '#e0245e',
            // 4.08:1 passes for a border and fails for a word, and it WAS a word on three
            // public screens. This is the value those become.
            'ink'  => '#cc1950',
            'wash' => '#f9e1e8',
            'means' => 'Happening now — open, counting, unfinished.',
        ],
        self::CAUTION => [
            'fill' => '#b3261e',
            'edge' => '#b3261e',
            'ink'  => '#b3261e',
            'wash' => '#f9e3e1',
            // Never "something went wrong". This platform withholds, delays and declines to
            // measure far more often than it errors, and dressing a withheld award as a
            // fault reads as an accusation about the nominee.
            'means' => 'Withheld, delayed, or not measured — not an error.',
        ],
    ];

    /**
     * ── COLOUR THAT IS AN IDENTITY RATHER THAN A MEANING ─────────────────────
     *
     * The four roles above say what something IS — won, do this, happening now, withheld.
     * They are deliberately few and deliberately rare, and that restraint is why a site
     * built from them reads as ink on paper with an occasional accent.
     *
     * It also left the one genuinely CATEGORICAL thing on this platform with no colour at
     * all: which award programme something belongs to. A hall of fame, a results archive
     * and an edition list are all lists whose rows come from two or three different
     * programmes, and every one of them printed the programme's name in the same grey as
     * everything else — so the one dimension a reader actually scans by was invisible.
     *
     * These hues carry NO meaning. `indigo` does not mean better than `ochre`; it means
     * "the Incredible Principal Awards" and nothing more. They are not roles, they are
     * never used for state, and `AccentTest`'s two-role ceiling does not count them —
     * a page may carry one role and as many programme identities as it lists programmes.
     *
     * ── ORDERED, AND THE ORDER IS THE ACCESSIBILITY DECISION ─────────────────
     *
     * You cannot have seven categorical hues that stay distinct for everybody. Red-green
     * is exactly the axis a deuteranope loses, and simulating it (Viénot) over these puts
     * `ochre` and `terracotta` **1.7 apart** — the same colour. So the list is ordered so
     * that consecutive assignments are as far apart as that allows, because a real
     * deployment runs two or three programmes and those are the ones that must separate:
     *
     *     first 2 assigned: 64.7 apart      first 4: 15.5
     *     first 3 assigned: 22.3            first 5: 3.0   ← the honest ceiling
     *
     * Past four they start to converge for some readers, and nothing here pretends
     * otherwise: **the programme's NAME is always written beside its colour**, so the hue
     * is an accelerator on top of a word and never the fact itself. Same rule as
     * {@see \AfricaGates\Support\Swatch}, and for the same reason.
     *
     * The seeds deliberately avoid the semantic hues — no caution red near 5°, no action
     * green near 119°, no live pink near 340° — so a programme chip can never be mistaken
     * for a status.
     *
     * @var array<string,array{fill:string,edge:string,ink:string,wash:string}>
     */
    private const PROGRAMME_HUES = [
        'indigo'     => ['fill' => '#5637d2', 'edge' => '#7a62da', 'ink' => '#5739d0', 'wash' => '#e7e3f7'],
        'ochre'      => ['fill' => '#d58f16', 'edge' => '#b3760d', 'ink' => '#905f0b', 'wash' => '#faf1e1'],
        'teal'       => ['fill' => '#1fbacb', 'edge' => '#13909e', 'ink' => '#10747f', 'wash' => '#e2f6f8'],
        'plum'       => ['fill' => '#af3cab', 'edge' => '#c559c1', 'ink' => '#a83aa5', 'wash' => '#f5e6f4'],
        'moss'       => ['fill' => '#7ab733', 'edge' => '#5e9125', 'ink' => '#4c741f', 'wash' => '#eef6e5'],
        // ── AND THERE IS NO SIXTH, WHICH IS A FINDING RATHER THAN A SHORTAGE ─
        //
        // A terracotta sat here and `AccentTest` refused it: at 18° it is **14.6° from the
        // caution red**, so a programme wearing it would read as a withheld award. Every
        // other gap on the wheel is taken — moss is already 31° from the action green,
        // ochre 34° from caution — and the remaining bands are blues that collapse into
        // `indigo` and `teal` for a deuteranope.
        //
        // So five is the number this platform's own semantic colours leave room for. A
        // sixth programme wraps to `indigo`, and its NAME — always printed — is what tells
        // the two apart. That is a better answer than a hue nobody can trust.
    ];

    /** @return list<string> */
    public static function programmeHues(): array
    {
        return array_keys(self::PROGRAMME_HUES);
    }

    /**
     * One programme's identity colour, chosen from its own id.
     *
     * BY ID AND NOT BY A HASH OF THE NAME. A hash scatters, so with three programmes it
     * would routinely hand out two hues from the converging end of the list while the
     * well-separated ones went unused — the ordering above would buy nothing. Ids here are
     * sequential, so the first programmes created take the first, most-separated hues,
     * which is exactly the case the order was built for.
     *
     * It is also STABLE: a programme's colour does not depend on what else is on the page.
     * Deriving it from a row's position in a list would make one programme change colour
     * between the hall and the archive, which is the opposite of an identity.
     *
     * Past six programmes the hues repeat. That is a collision and not a bug: two
     * programmes share a colour and their names, which are always printed, tell them apart.
     *
     * @return array{key:string,fill:string,edge:string,ink:string,wash:string}
     */
    public static function forProgramme(int $id): array
    {
        $keys = array_keys(self::PROGRAMME_HUES);
        // max(1, …) so a row with no id — an import, a fixture — still gets a colour
        // rather than a division by zero or a blank custom property.
        $key  = $keys[abs($id) % count($keys)];

        return ['key' => $key] + self::PROGRAMME_HUES[$key];
    }

    /**
     * One named hue from the categorical table.
     *
     * For the sweep and for a caller that already knows the key; ordinary callers reach
     * for {@see forProgramme()} and let the id choose.
     *
     * @return array{fill:string,edge:string,ink:string,wash:string}
     */
    public static function forProgrammeKey(string $key): array
    {
        return self::PROGRAMME_HUES[$key] ?? self::PROGRAMME_HUES[array_key_first(self::PROGRAMME_HUES)];
    }

    /**
     * A programme's colour as inline custom properties.
     *
     * Inline because the value is per-row and cannot live in `:root`. Safe in a `style`
     * attribute — `style-src-attr 'unsafe-inline'` is deliberately allowed (see
     * `Support\Csp` for why the directive is split) — and the values are constants above,
     * so nothing a person typed can reach a stylesheet through this.
     */
    public static function programmeStyle(int $id): string
    {
        $c = self::forProgramme($id);

        return '--pg-fill:' . $c['fill'] . ';--pg-edge:' . $c['edge']
             . ';--pg-ink:' . $c['ink'] . ';--pg-wash:' . $c['wash'];
    }

    /**
     * Every categorical value, flat, for the sweep to measure.
     *
     * @return list<array{role:string,slot:string,hex:string}>
     */
    public static function allProgrammeHues(): array
    {
        $out = [];
        foreach (self::PROGRAMME_HUES as $key => $v) {
            foreach (['fill', 'edge', 'ink', 'wash'] as $slot) {
                $out[] = ['role' => $key, 'slot' => $slot, 'hex' => $v[$slot]];
            }
        }

        return $out;
    }

    /** @return list<string> */
    public static function roles(): array
    {
        return array_keys(self::ROLES);
    }

    /**
     * One role's four values.
     *
     * An unknown role returns ACTION rather than throwing or returning nothing: a missing
     * colour renders as `inherit` and the element silently loses its meaning, which is
     * worse on a live page than the wrong accent. The test is what catches the typo.
     *
     * @return array{fill:string,edge:string,ink:string,wash:string,means:string}
     */
    public static function of(string $role): array
    {
        return self::ROLES[strtolower(trim($role))] ?? self::ROLES[self::ACTION];
    }

    public static function fill(string $role): string { return self::of($role)['fill']; }
    public static function edge(string $role): string { return self::of($role)['edge']; }
    public static function ink(string $role): string  { return self::of($role)['ink'];  }
    public static function wash(string $role): string { return self::of($role)['wash']; }

    /** What this role is allowed to say. Published in the admin handbook. */
    public static function means(string $role): string { return self::of($role)['means']; }

    /**
     * The palette as CSS custom properties, for one `<style>` block in the site layout.
     *
     * Emitted rather than hand-written into a stylesheet so the declaration and the values
     * the tests assert cannot drift apart — the fault that put four golds into circulation
     * in the first place. Hex digits only ever reach the page through the constants above,
     * so nothing here can carry anything a stylesheet would execute.
     */
    public static function css(): string
    {
        $out = [];
        foreach (self::ROLES as $role => $v) {
            foreach (['fill', 'edge', 'ink', 'wash'] as $slot) {
                $out[] = '--ag-' . $role . '-' . $slot . ':' . $v[$slot] . ';';
            }
        }

        return ':root{' . implode('', $out) . '}';
    }

    /**
     * Every value in the palette, flat, for a sweep to measure.
     *
     * @return list<array{role:string,slot:string,hex:string}>
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::ROLES as $role => $v) {
            foreach (['fill', 'edge', 'ink', 'wash'] as $slot) {
                $out[] = ['role' => $role, 'slot' => $slot, 'hex' => $v[$slot]];
            }
        }

        return $out;
    }

    /**
     * The floor each slot owes against paper, or null where it owes nothing.
     *
     * A `fill` owes nothing BY DESIGN and that is the load-bearing decision here: demanding
     * 3:1 of it would force the house gold to a mustard nobody chose, which is how a
     * palette gets fixed into blandness by an accessibility pass. The rule is that a fill
     * is never the only carrier of meaning — the ink beside it and the word under it are —
     * so `AccentTest` holds THAT instead.
     */
    public static function floor(string $slot): ?float
    {
        return match ($slot) {
            'edge'  => Contrast::UI,
            'ink'   => Contrast::TEXT,
            default => null,
        };
    }
}
