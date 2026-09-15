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
    /**
     * ── THE GROUND, AND WHY IT MOVED ─────────────────────────────────────────
     *
     * `#f0f2f2` is a cool grey with a green-blue cast. The tone this platform needs is a
     * printed ceremony programme — warm — and beside the gold honour wash the old ground
     * turned faintly green. Same lightness; the cast moves from cyan to a low-chroma
     * yellow.
     *
     * THE COST IS STATED RATHER THAN DISCOVERED: every ratio drops about 2% because the
     * warm ground is fractionally darker. Nothing crosses a floor, and `ink-soft` lands at
     * 4.80 against a 4.5 requirement — the tightest margin in the set, and the token that
     * fails first if anybody ever lightens it.
     */
    public const PAPER = '#f1efe9';

    /** A card on the ground. */
    public const SURFACE = '#ffffff';

    /**
     * ── THE NEUTRAL RAMP: SIX STEPS, THREE OF THEM NEW ───────────────────────
     *
     * THE FAULT THIS FIXES. The ramp was two greys and one alpha hairline. You cannot
     * build a weight-and-size hierarchy on two greys, so every screen that needed
     * something to stand out reached for the gold — and the gold is 1.61:1 and did not
     * show. The missing emphasis was never a missing hue; it was four missing neutrals.
     *
     * Three rules attach, and each is enforced by `SlotFloorTest`:
     *
     *   · `mute` IS NEVER A WORD. At 2.75:1 it is for disabled glyphs and decorative
     *     rules. The test refuses it on anything containing text.
     *
     *   · THE ALPHA HAIRLINE BECOMES A SOLID `line`. `rgba(16,41,44,.12)` changes value
     *     with whatever sits behind it, which is why one rule looked like two different
     *     rules on a card and on the ground.
     *
     *   · `surface-2` INVALIDATES BORDERLINE VALUES. It is only a 1.29:1 step off the
     *     ground, and that step eats the margin anything near 4.5 was relying on:
     *     `ink-soft` 4.80 → 4.38 and `live` ink 4.78 → 4.36. Both fail. On `surface-2`,
     *     secondary greys step up to `ink-2` and role inks step to `ink`. Measuring
     *     against the ground alone is exactly what let one new neutral break two inks the
     *     moment it was added.
     *
     * @var array<string,array{hex:string,text:bool,note:string}>
     */
    private const NEUTRALS = [
        'ink'       => ['hex' => '#10292c', 'text' => true,
                        'note' => 'Body and headings.'],
        'ink-2'     => ['hex' => '#3a4a4c', 'text' => true,
                        'note' => 'Sub-headings, active nav, secondary text on a tinted surface.'],
        'ink-soft'  => ['hex' => '#626a6e', 'text' => true,
                        'note' => 'Captions and metadata. On the ground and on a card only.'],
        'mute'      => ['hex' => '#8b9295', 'text' => false,
                        'note' => 'Never a word. Disabled glyphs and decorative rules.'],
        'line'      => ['hex' => '#d6d4cc', 'text' => false,
                        'note' => 'Borders and separators. Solid, never an alpha.'],
        'surface-2' => ['hex' => '#e8e5dd', 'text' => false,
                        'note' => 'Hover rows, zebra stripes, insets.'],
        'card'      => ['hex' => '#ffffff', 'text' => false,
                        'note' => 'A card on the ground.'],
        'desk'      => ['hex' => '#dcd8cf', 'text' => false,
                        'note' => 'The ground behind a frame: artboards, print margins, embeds.'],
    ];

    /**
     * Every ground a value can actually be drawn on.
     *
     * The general rule the ramp taught: any ground a value is drawn on is a ground the
     * test must enumerate. A neutral cleared at 4.80 on paper has no margin left for a
     * surface 1.24× darker.
     *
     * @return array<string,string>
     */
    public static function grounds(): array
    {
        return [
            'ground'    => self::PAPER,
            'card'      => self::NEUTRALS['card']['hex'],
            'surface-2' => self::NEUTRALS['surface-2']['hex'],
            'desk'      => self::NEUTRALS['desk']['hex'],
        ];
    }

    /** One neutral's hex. */
    public static function neutral(string $name): string
    {
        return self::NEUTRALS[$name]['hex'] ?? self::NEUTRALS['ink']['hex'];
    }

    /** May this neutral carry a word? `mute` may not. */
    public static function neutralTakesText(string $name): bool
    {
        return (bool) ($self = self::NEUTRALS[$name]['text'] ?? false);
    }

    /** @return array<string,array{hex:string,text:bool,note:string}> */
    public static function neutrals(): array
    {
        return self::NEUTRALS;
    }

    public const HONOUR  = 'honour';
    public const ACTION  = 'action';
    public const LIVE    = 'live';
    public const CAUTION = 'caution';

    /**
     * ── A FIFTH ROLE WITH NO HUE AT ALL ──────────────────────────────────────
     *
     * Reserving red for WITHHELD leaves no way to say a thing actually broke. Adding a
     * sixth hue is the wrong answer — every remaining band on the wheel collides with
     * something already spoken for, which is the finding that cut the programme palette.
     *
     * So a fault is THE GROUND INVERTED: paper type on ink, at 13.28:1. On a site that is
     * ink on paper everywhere, an inverted block is the loudest object available. It costs
     * no new colour, it cannot be mistaken for a withheld award, and it survives every form
     * of colour vision deficiency because it is a lightness difference and not a hue.
     *
     * Form errors, declined payments, destructive confirms.
     */
    public const FAULT = 'fault';

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
            // Reserved, and untouched. 4.65:1 on the warm ground.
            'fill' => '#237b22',
            'edge' => '#237b22',
            'ink'  => '#237b22',
            'wash' => '#e4f6e4',
            // The press shade. A hard offset bottom border in a solid darker shade of the
            // button's own colour — never a blurred drop shadow, because THERE ARE NO
            // SHADOWS ANYWHERE ON THIS SITE. The lip collapses on press, and that collapse
            // is what makes it an affordance rather than an ornament; so the lip belongs
            // only to things that are pressed. Not tiles, not cards, not panels.
            'lip'  => '#145213',
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
        self::FAULT => [
            // No hue. The ground inverted — see the constant's docblock.
            'fill' => '#10292c',
            'edge' => '#10292c',
            'ink'  => '#10292c',
            // A fault's "wash" is the ink itself, because the block is inverted: the WORDS
            // are paper. Named `wash` so the four-slot shape holds, and the floor test
            // knows to measure paper-on-this rather than ink-on-this.
            'wash' => '#10292c',
            'means' => 'Something actually broke — a form error, a declined payment.',
        ],
    ];

    /** Roles whose block is inverted: the wash is dark and the words are paper. */
    private const INVERTED = [self::FAULT];

    /** Is this role drawn as paper-on-dark rather than ink-on-wash? */
    public static function inverted(string $role): bool
    {
        return in_array(strtolower(trim($role)), self::INVERTED, true);
    }

    /** The press shade for a role that has one, or '' where it has none. */
    public static function lip(string $role): string
    {
        return (string) (self::of($role)['lip'] ?? '');
    }

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
        'teal'       => ['fill' => '#1fbacb', 'edge' => '#13909e', 'ink' => '#10747f', 'wash' => '#e2f6f8'],
        'plum'       => ['fill' => '#af3cab', 'edge' => '#c559c1', 'ink' => '#a83aa5', 'wash' => '#f5e6f4'],
        // ── FIVE WAS TWO TOO MANY, AND THE TWO THAT WENT SHARED A FAMILY ─────
        //
        // `ochre #d58f16` is the same hue family as the honour gold, so a programme
        // wearing it on a results page reads as a decided award. `moss #7ab733` is 31°
        // from the action green, so a moss spine beside a green button asks the reader to
        // work out which green is the instruction. Neither was wrong on its own; both were
        // wrong in a system that had already spent those families.
        //
        // Three separable hues remain, none sharing a family with a role. A fourth
        // programme wraps to indigo and its printed name separates them — which is the
        // same answer the sixth hue got, for the same reason.
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
        // ONE-BASED, because programme ids are. The handoff states the mapping —
        // Incredible Principal Awards (id 1) is indigo, African Creative Honours (id 2) is
        // teal — and a plain `% count` would hand id 1 the SECOND hue and silently
        // contradict the specification the designs were drawn against.
        $key  = $keys[max(0, abs($id) - 1) % count($keys)];

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
     * One tile's four values, as inline custom properties.
     *
     * ── THE TILE IS THE ONE COLOUR DEVICE, AND IT HAS FOUR PARTS ─────────────
     *
     * An emoji pops for three reasons at once: it is fully saturated, it is small, and it
     * is BOUNDED. Saturation without a boundary is a wash; a boundary without saturation
     * is a card. You need both, at small size, rarely — and that is a tile:
     *
     *     wash   the bounded ground, holding house ink above 12:1
     *     edge   a 1px hard boundary — what makes it an object rather than a tint
     *     fill   the saturated mark, 14–20px, rounded 4–5px
     *     ink    the word, above 4.5:1 — saying what the colour says
     *
     * Remove any one and it stops being a tile: no edge and it is a wash, no fill and it
     * is a card, no ink and it is decoration, no wash and it is a floating chip.
     *
     * ── AND `live` CANNOT LABEL ITSELF ───────────────────────────────────────
     *
     * A role ink on its own wash IS the tile, and three of the four clear it. `live` ink
     * on `live` wash is 4.44 — the system's one failure, and a stated exception rather
     * than a bug. A live tile's label is house ink and the DOT carries the hue, so the
     * `ink` slot is overridden here rather than left for each template to remember.
     * `SlotFloorTest` holds both halves: the three that work, and the one that does not.
     */
    public static function tileStyle(string $meaning): string
    {
        $c    = self::for($meaning);
        $role = self::roleFor($meaning);

        $ink = $role === self::LIVE ? self::NEUTRALS['ink']['hex'] : $c['ink'];

        return '--tile-wash:' . $c['wash'] . ';--tile-edge:' . $c['edge']
             . ';--tile-fill:' . $c['fill'] . ';--tile-ink:' . $ink;
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

    /**
     * ── ASK FOR A COLOUR BY WHAT IT MEANS, NOT BY ITS NAME ───────────────────
     *
     * `Accent::for('withheld')` rather than `Accent::of('caution')`. The difference is
     * that asking for a semantic colour in a non-semantic place becomes a VISIBLE MISTAKE
     * IN THE DIFF: `for('voting-open')` on a page with no ballot on it reads wrong to a
     * reviewer in a way `of('live')` never does.
     *
     * This is GOV.UK's rule — do not copy the hex values, and a functional colour name may
     * only be used in the context it was designed for. It is also the answer to the 642
     * literal hexes: those are not a discipline problem, they are what happens when the
     * correct value is harder to reach than a literal.
     *
     * @var array<string,string> meaning => role
     */
    private const MEANINGS = [
        // honour — an award decided, and the person it was decided for
        'award-decided'   => self::HONOUR,
        'overall-winner'  => self::HONOUR,
        'the-index'       => self::HONOUR,
        // action — the one thing the screen asks for
        'do-this'         => self::ACTION,
        'primary-action'  => self::ACTION,
        'focus'           => self::ACTION,
        // live — true right now
        'voting-open'     => self::LIVE,
        'counting'        => self::LIVE,
        'happening-now'   => self::LIVE,
        // caution — withheld, never a failure
        'withheld'        => self::CAUTION,
        'delayed'         => self::CAUTION,
        'not-measured'    => self::CAUTION,
        'provisional'     => self::CAUTION,
        // fault — something actually broke
        'form-error'      => self::FAULT,
        'payment-declined'=> self::FAULT,
        'destructive'     => self::FAULT,
    ];

    /**
     * One meaning's four slots.
     *
     * An unrecognised meaning throws rather than falling back, and that is the opposite of
     * {@see of()}'s behaviour on purpose. `of()` is asked for a role that a template
     * already named, where a blank would silently strip an element's meaning on a live
     * page. `for()` is asked at the point somebody is CHOOSING, and a typo there should
     * stop the build rather than quietly render the wrong state — a page that says
     * "withheld" in caution red when it meant "form error" is worse than a page that fails.
     *
     * @return array{fill:string,edge:string,ink:string,wash:string,means:string}
     */
    public static function for(string $meaning): array
    {
        $key = strtolower(trim($meaning));

        if (!isset(self::MEANINGS[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'No colour means "%s". The meanings are: %s',
                $meaning, implode(', ', array_keys(self::MEANINGS))));
        }

        return self::of(self::MEANINGS[$key]);
    }

    /** Which role a meaning resolves to, for a test or a template helper. */
    public static function roleFor(string $meaning): string
    {
        $key = strtolower(trim($meaning));

        return self::MEANINGS[$key] ?? '';
    }

    /** @return array<string,string> */
    public static function meanings(): array
    {
        return self::MEANINGS;
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

        // The ramp first, because most of the platform never leaves it. Emitted here
        // rather than written into base/tokens.css so that every colour on the site has
        // ONE source — which is the only way the literal-hex sweep can be true.
        $out[] = '--ag-ground:' . self::PAPER . ';';
        foreach (self::NEUTRALS as $name => $v) {
            $out[] = '--ag-' . $name . ':' . $v['hex'] . ';';
        }

        // ── THE TWO LEGACY HAIRLINES BOTH BECOME THE ONE SOLID `line` ────────
        //
        // base/tokens.css carried `--ag-line` at rgba(…,.07) and `--ag-line-strong` at
        // .12, and both are read across the templates. The ramp has ONE line, and the
        // reason it is solid applies to both: an alpha border takes its value from
        // whatever sits behind it, which is why the same rule looked like two different
        // rules on a card and on the ground.
        //
        // Emitted as aliases rather than left in the stylesheet, so there is no second
        // source for a colour — a sheet that still declared them would win or lose by
        // load order, which is exactly the kind of thing nobody can see in a diff.
        $out[] = '--ag-line-strong:' . self::NEUTRALS['line']['hex'] . ';';
        // The page ground under its old name, so a template that has not been converted
        // yet still warms with everything else.
        $out[] = '--ag-bg:' . self::PAPER . ';';
        $out[] = '--ag-surface:' . self::NEUTRALS['card']['hex'] . ';';

        foreach (self::ROLES as $role => $v) {
            foreach (['fill', 'edge', 'ink', 'wash'] as $slot) {
                $out[] = '--ag-' . $role . '-' . $slot . ':' . $v[$slot] . ';';
            }
            if (($v['lip'] ?? '') !== '') {
                $out[] = '--ag-' . $role . '-lip:' . $v['lip'] . ';';
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
