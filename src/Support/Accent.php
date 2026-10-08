<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * Every colour on this site, and the only file a colour literal may be typed in.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE HANDOFF'S PALETTE, EXACTLY — AND WHY IT STILL LIVES IN PHP
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The table below is REFERENCE §6.1 and `snippets/css/tokens.css` of the redesign
 * handoff, name for name and value for value. The owner's decision (2 Oct 2026, GAPS §8
 * Q1/Q2) was "ship the handoff exactly": ±0 on colour, the handoff's names, and this class
 * destroyed and rebuilt as that palette rather than `tokens.css` becoming a second source.
 *
 * It stays PHP-emitted for the reason it was PHP-emitted in the first place: the values
 * the page receives and the values the tests measure have to be the same values. A
 * colour with two sources is decided by load order, and load order is the one thing
 * nobody can see in a diff — that is how four golds came to be in circulation under a
 * house style naming one. So `css()` writes one nonced `<style>` of custom properties,
 * every layout emits it before its stylesheets, and `public/assets/css/tokens.css` holds
 * no colour at all. Mail and GD cannot read `var()`; they ask {@see hex()} by name.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT "SHIP EXACTLY" COSTS, STATED RATHER THAN DISCOVERED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Several values here are under a WCAG floor where they are drawn, and every one is
 * accepted by the owner rather than adjusted (Q2). Measured on the ground `#f1efe9`
 * unless said otherwise:
 *
 *   gold `#f3b416`            1.61   honour as a FIELD; drawn as a line it barely shows
 *   line / tint `#e8e5dd`     1.09   hairlines and neutral fills
 *   line-2 `#d6d4cc`          1.48 on white — the outlined chip and input border
 *   line-3, gold-edge, green-edge   1.03–1.32   separators and success/honour borders
 *   mute `#8b9295`            2.75   disabled text and the off-switch track
 *   green-light, chevron, grabber   under 2.1   accents on dark, a chevron, a sheet grabber
 *
 * The floors that DO hold are held, by {@see words()}: every token the handoff draws as a
 * word, on every ground it is drawn on, at 4.5:1. `SlotFloorTest` re-derives them on
 * every run. If one fails the VALUE is not changed — it is reported, because the decision
 * was to ship the handoff.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * SHADOWS ARE COLOUR, SO THEY LIVE HERE TOO
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * This site used to have none — depth was a hard "lip" border that collapsed on press,
 * and this class's previous docblock said "there are no shadows anywhere on this site".
 * The owner adopted the handoff's shadows (Q3), REFERENCE §6.5 plus the snippet's
 * `--ag-sh-gee`. Each one is an rgba of the house ink, which is a colour literal, so they
 * are emitted from here beside the palette rather than typed into `tokens.css`. The two
 * per-element shadows of §6.5 (the celebration badge's ring and the celebration ticket)
 * take a value from the element they sit on and belong to that component (Phase 3).
 */
final class Accent
{
    /**
     * REFERENCE §6.1, in the handoff's order. `use` is the handoff's own column.
     *
     * `gold-wash-2`, `gold-edge`, `line-3`, `info-wash`, `scrim`, `chevron` and `grabber`
     * are in the snippet and not in the §6.1 table; the snippet is the file the handoff
     * calls final, so they are here.
     *
     * @var array<string,array{value:string,use:string}>
     */
    private const PALETTE = [
        'ink'         => ['value' => '#10292c', 'use' => 'primary text, dark UI, selected-chip border'],
        'ink-2'       => ['value' => '#3a4a4c', 'use' => 'secondary text'],
        'soft'        => ['value' => '#626a6e', 'use' => 'meta text, captions'],
        'mute'        => ['value' => '#8b9295', 'use' => 'disabled text, off-switch track'],
        'ground'      => ['value' => '#f1efe9', 'use' => 'page background'],
        'surface'     => ['value' => '#ffffff', 'use' => 'cards, inputs'],
        'bar'         => ['value' => '#fbfbfa', 'use' => 'app bars and tab bars when filled'],
        'line'        => ['value' => '#e8e5dd', 'use' => 'hairlines, card borders'],
        'line-2'      => ['value' => '#d6d4cc', 'use' => 'input and outlined-chip borders'],
        'line-3'      => ['value' => '#eeece6', 'use' => 'separators inside a white list'],
        'tint'        => ['value' => '#e8e5dd', 'use' => 'neutral fills (icon tiles, segmented track)'],
        'green'       => ['value' => '#237b22', 'use' => 'the primary action, success'],
        'green-deep'  => ['value' => '#1a6118', 'use' => 'success text'],
        'green-light' => ['value' => '#7fc87c', 'use' => 'accents on dark, sparks'],
        'green-wash'  => ['value' => '#effaf0', 'use' => 'success wash'],
        'green-edge'  => ['value' => '#cfe6ce', 'use' => 'success borders'],
        'gold'        => ['value' => '#f3b416', 'use' => 'honour, winners'],
        'gold-ink'    => ['value' => '#7a5600', 'use' => 'text on gold wash'],
        'gold-wash'   => ['value' => '#fcf4de', 'use' => 'winner cards'],
        'gold-wash-2' => ['value' => '#fff8df', 'use' => 'early-bird strip'],
        'gold-edge'   => ['value' => '#f0dfae', 'use' => 'honour borders'],
        'live'        => ['value' => '#e0245e', 'use' => 'live dot, hearts'],
        'live-ink'    => ['value' => '#b0224f', 'use' => 'live text, dates on events'],
        'live-wash'   => ['value' => '#fdecef', 'use' => 'giving wash'],
        'info'        => ['value' => '#1f6fa3', 'use' => 'tickets, info'],
        'info-wash'   => ['value' => '#e8f1f7', 'use' => 'ticket and info wash'],
        'error'       => ['value' => '#b42318', 'use' => 'errors, destructive text ("Sign out")'],
        'stock-low'   => ['value' => '#8a2020', 'use' => '"Only N left"'],
        'stock-gone'  => ['value' => '#8a5a00', 'use' => '"Sold out" note'],
        'scrim'       => ['value' => 'rgba(16,41,44,.32)', 'use' => 'behind a sheet or a dialog'],
        'chevron'     => ['value' => '#b3b9ba', 'use' => 'a row\'s disclosure chevron'],
        'grabber'     => ['value' => '#c9c4b8', 'use' => 'a bottom sheet\'s grabber'],
    ];

    /**
     * REFERENCE §6.5, exact, under the snippet's names.
     *
     * @var array<string,string>
     */
    private const SHADOWS = [
        'sh-pop'   => '0 24px 48px -20px rgba(16,41,44,.30)',   // popover, drawer
        'sh-mega'  => '0 24px 48px -28px rgba(16,41,44,.25)',   // mega panel
        'sh-sheet' => '0 -12px 40px rgba(16,41,44,.18)',        // bottom sheet
        'sh-float' => '0 2px 10px rgba(16,41,44,.15)',          // round button on a photo
        'sh-dot'   => '0 3px 10px rgba(16,41,44,.18)',          // quick-add dot on a card
        'sh-gee'   => '0 30px 70px -24px rgba(16,41,44,.45)',   // Gee's panel
    ];

    /**
     * ── THE FLOORS THAT HOLD: EVERY WORD, ON EVERY GROUND IT IS DRAWN ON ─────
     *
     * The rule the previous palette taught, and it survives the rebuild intact: a value
     * is never measured against "the" ground, only against each ground it is actually
     * drawn on. `soft` clears 4.80 on the ground and 4.38 on `tint` — so `tint` is not in
     * its row, and a template that sets meta text on an icon tile is the fault.
     *
     * `surface` appears as a WORD twice: white type on the primary green button (5.35)
     * and on an ink button or dark band (15.27).
     *
     * `mute` has no row. It is never a word (2.75:1 on the ground).
     *
     * @var array<string,list<string>> word token => grounds it is drawn on
     */
    private const WORDS = [
        'ink'        => ['ground', 'surface', 'bar', 'tint', 'green-wash', 'gold-wash', 'gold-wash-2', 'live-wash', 'info-wash'],
        'ink-2'      => ['ground', 'surface', 'bar', 'tint'],
        'soft'       => ['ground', 'surface', 'bar'],
        'green'      => ['ground', 'surface', 'bar'],
        'green-deep' => ['ground', 'surface', 'green-wash'],
        'gold-ink'   => ['gold-wash', 'gold-wash-2', 'surface'],
        'live-ink'   => ['ground', 'surface', 'live-wash'],
        'info'       => ['ground', 'surface', 'info-wash'],
        'error'      => ['ground', 'surface', 'live-wash'],
        'stock-low'  => ['ground', 'surface'],
        'stock-gone' => ['ground', 'surface'],
        'surface'    => ['green', 'ink'],
    ];

    /**
     * The values drawn below a floor on purpose, and what the owner accepted (Q2).
     *
     * Kept as data rather than prose so the test can hold both halves: none of these may
     * ever acquire a row in {@see WORDS}, and each still measures what it is said to
     * measure — a value that quietly cleared its floor would make this list a lie.
     *
     * @var array<string,array{on:string,why:string}>
     */
    private const ACCEPTED = [
        'gold'        => ['on' => 'ground',  'why' => 'honour as a field; as a line it is under 3:1'],
        'line'        => ['on' => 'ground',  'why' => 'hairlines and card borders'],
        'line-2'      => ['on' => 'surface', 'why' => 'the outlined chip and input border, 1.48:1'],
        'line-3'      => ['on' => 'surface', 'why' => 'separators inside a white list'],
        'tint'        => ['on' => 'ground',  'why' => 'neutral fills'],
        'gold-edge'   => ['on' => 'surface', 'why' => 'honour borders'],
        'green-edge'  => ['on' => 'surface', 'why' => 'success borders'],
        'mute'        => ['on' => 'ground',  'why' => 'disabled text — never a word'],
        'green-light' => ['on' => 'ground',  'why' => 'accents on dark only'],
        'chevron'     => ['on' => 'surface', 'why' => 'a decorative disclosure mark beside a labelled row'],
        'grabber'     => ['on' => 'surface', 'why' => 'a decorative sheet handle'],
    ];

    /**
     * ── A COLOUR FAMILY, AND THE FOUR PARTS A TILE IS MADE OF ────────────────
     *
     * The handoff names its accents as families — `gold`, `gold-ink`, `gold-wash`,
     * `gold-edge` — and a family answers the same four questions the old roles did:
     *
     *   fill   the identity, the colour somebody would name; owes no floor
     *   edge   the boundary
     *   ink    the word
     *   wash   the field a word sits on
     *
     * Each slot is a TOKEN NAME, never a hex: a tile or a programme spine is painted with
     * `var(--ag-…)`, so the inline style can only ever point at the palette.
     *
     * `error` has no wash in §6.1 — the handoff draws refusals as words. Where a refusal
     * needs a field (the "bad" pill, a withheld tile) it takes `live-wash`, which is the
     * handoff's own choice for its one caution field: the audit-request tile is drawn
     * `#fdecef` with `#b0224f`. `error` on it is 5.77:1.
     *
     * There is no inverted "fault" block any more. The previous palette drew a broken
     * thing as paper on ink to keep it apart from a withheld award; the handoff draws
     * both in `--ag-error`, and the difference is carried by the WORDS, which it always
     * had to be for anybody who cannot see the hue.
     *
     * @var array<string,array{fill:string,edge:string,ink:string,wash:string}>
     */
    private const FAMILIES = [
        'gold'  => ['fill' => 'gold',  'edge' => 'gold-edge',  'ink' => 'gold-ink',   'wash' => 'gold-wash'],
        'green' => ['fill' => 'green', 'edge' => 'green-edge', 'ink' => 'green-deep', 'wash' => 'green-wash'],
        'live'  => ['fill' => 'live',  'edge' => 'live',       'ink' => 'live-ink',   'wash' => 'live-wash'],
        'error' => ['fill' => 'error', 'edge' => 'error',      'ink' => 'error',      'wash' => 'live-wash'],
        'info'  => ['fill' => 'info',  'edge' => 'info',       'ink' => 'info',       'wash' => 'info-wash'],
    ];

    /**
     * Which tokens paint an AREA, per family — what a colour budget counts.
     *
     * A field is a bounded coloured area a reader can point at. An `ink` or an `edge` is a
     * word or a boundary, which is structure. `green` is both — the primary button's fill
     * and, by §13, the focus ring — so the sweeps count it only where it is a background.
     *
     * @var array<string,list<string>>
     */
    private const FIELDS = [
        'gold'  => ['gold', 'gold-wash', 'gold-wash-2'],
        'green' => ['green', 'green-wash', 'green-light'],
        'live'  => ['live', 'live-wash'],
        'error' => ['error'],
        'info'  => ['info', 'info-wash'],
    ];

    /**
     * ── ASK FOR A COLOUR BY WHAT IT MEANS, NOT BY ITS NAME ───────────────────
     *
     * `for('withheld')` rather than `family('error')`: asking for a semantic colour in a
     * non-semantic place is then a visible mistake in the diff. Unknown meanings THROW —
     * a page that says "withheld" in the colour of "counting" is worse than a page that
     * fails to render.
     *
     * The old roles map across as the owner decided: honour → gold, action → green,
     * live → live, caution and fault → error.
     *
     * @var array<string,string> meaning => family
     */
    private const MEANINGS = [
        'award-decided'    => 'gold',
        'overall-winner'   => 'gold',
        'the-index'        => 'gold',
        'do-this'          => 'green',
        'primary-action'   => 'green',
        'focus'            => 'green',
        'voting-open'      => 'live',
        'counting'         => 'live',
        'happening-now'    => 'live',
        'withheld'         => 'error',
        'delayed'          => 'error',
        'not-measured'     => 'error',
        'provisional'      => 'error',
        'form-error'       => 'error',
        'payment-declined' => 'error',
        'destructive'      => 'error',
    ];

    /**
     * ── A PROGRAMME'S IDENTITY IS A FEATURE, AND IT SURVIVES IN THIS PALETTE ──
     *
     * Hall of fame, results archive, edition lists: rows from two or three programmes, and
     * the programme is the dimension a reader scans by. The previous palette carried three
     * categorical hues for it (indigo, teal, plum). The handoff carries none, and the rule
     * is "no new colours", so the identity is drawn from the families §6.1 already has
     * that NO MEANING on a results page has claimed — gold is a decided award, green is
     * the thing to press, live is happening now, error is a refusal. That leaves exactly
     * two: `info` and the ink neutral.
     *
     * So two programmes separate by colour and a third wraps. That was always the honest
     * position past a handful of hues — **the programme's NAME is always printed beside
     * its colour**, so the hue accelerates a word and is never the fact itself.
     *
     * @var array<string,array{fill:string,edge:string,ink:string,wash:string}>
     */
    private const PROGRAMMES = [
        'info' => ['fill' => 'info', 'edge' => 'info', 'ink' => 'info', 'wash' => 'info-wash'],
        'ink'  => ['fill' => 'ink',  'edge' => 'ink',  'ink' => 'ink',  'wash' => 'tint'],
    ];

    // ══ the admin console's palette ════════════════════════════════════════════
    //
    // ── A SECOND SURFACE, AND STILL ONE FILE ─────────────────────────────────
    //
    // The admin console is not drawn by the public handoff. Its own handoff
    // (`design_handoff_admin_console`, README §10) is monochrome — near-black ink on
    // white, a ramp of neutral greys, and four status families — and the owner chose it
    // (4 Oct 2026, GAPS §8d: option (A), with DM Sans and the house JetBrains Mono in
    // place of Geist). The teal `--ad-*` set it replaces was typed into `admin.css`, a
    // second colour file; it was destroyed with that sheet rather than aliased.
    //
    // The console set lives HERE, beside the public one, so "Accent is the only file a
    // colour literal may be typed in" stays true with two surfaces. It is emitted under
    // its own prefix, `--cn-*`, by `css('console')` into the admin layout's nonced
    // `<style>`, and never into a public page: the two palettes share no name, so a
    // public stylesheet can never pick up a console grey by accident, and the reverse.
    //
    // ── THE VALUES ARE THE HANDOFF'S, ±0, AND SO ARE ITS FAILURES ─────────────
    //
    // README §10 plus the colours the HTML (which wins on what users see) draws in the
    // shell, Home and the overlays: `green` (#086411, the ticks and the all-clear dot),
    // `line-row` (#f2f2f2 row rules), `line-bar` (#f0f0f0 under the top bar),
    // `link-line` (#c4c4c4 underlines), `danger-edge` (#f0c9c5, the high-alert pill)
    // and the three scrims. Every word the console draws is measured at 4.5:1 on every
    // ground it is drawn on (`consoleWords()`); the ones that fail are REPORTED in
    // `CONSOLE_REPORTED`, never re-valued, exactly as the public palette's are.

    /**
     * README §10 of the admin handoff, plus the shell's own colours from its HTML.
     *
     * @var array<string,array{value:string,use:string}>
     */
    private const CONSOLE = [
        'ink'           => ['value' => '#0a0a0a', 'use' => 'text, primary buttons, the current state, focus'],
        'grey-800'      => ['value' => '#262626', 'use' => 'body text'],
        'grey-700'      => ['value' => '#404040', 'use' => 'nav items at rest'],
        'grey-600'      => ['value' => '#525252', 'use' => 'secondary text'],
        'grey-500'      => ['value' => '#737373', 'use' => 'muted text, counts, icons at rest'],
        'grey-450'      => ['value' => '#8f8f8f', 'use' => 'sidebar group labels'],
        'grey-400'      => ['value' => '#a3a3a3', 'use' => 'dots'],
        'grey-350'      => ['value' => '#bdbdbd', 'use' => 'disabled fills (the empty send button)'],
        'grey-300'      => ['value' => '#d4d4d4', 'use' => 'toggles off, dashed outlines'],
        'line-control'  => ['value' => '#e5e5e5', 'use' => 'input and pill borders'],
        'line-card'     => ['value' => '#ebebeb', 'use' => 'card borders'],
        'line-row'      => ['value' => '#f2f2f2', 'use' => 'rules between rows inside a card or a drawer'],
        'line-bar'      => ['value' => '#f0f0f0', 'use' => 'the rule under the top bar'],
        'link-line'     => ['value' => '#c4c4c4', 'use' => 'a link\'s underline at rest'],
        'fill-current'  => ['value' => '#ededed', 'use' => 'the current nav item'],
        'fill-hover'    => ['value' => '#efefef', 'use' => 'hover on a nav item'],
        'fill-subtle'   => ['value' => '#f2f2f2', 'use' => 'chips, the round close button'],
        'fill-selected' => ['value' => '#f5f5f5', 'use' => 'a focused row, a highlighted option'],
        'fill-panel'    => ['value' => '#fafafa', 'use' => 'the sidebar, table heads, the palette footer'],
        'surface'       => ['value' => '#ffffff', 'use' => 'the main area, cards, menus'],
        'green'         => ['value' => '#086411', 'use' => 'ticks and the all-clear dot'],
        'success'       => ['value' => '#1a6118', 'use' => 'success text'],
        'success-dot'   => ['value' => '#2f8f2c', 'use' => 'success dot'],
        'success-tint'  => ['value' => '#eef6ee', 'use' => 'success field'],
        'warning'       => ['value' => '#8a5a00', 'use' => 'warning text'],
        'warning-dot'   => ['value' => '#c98a00', 'use' => 'warning dot, a waiting count'],
        'warning-tint'  => ['value' => '#fdf0d2', 'use' => 'warning field'],
        'warning-tint-2'=> ['value' => '#fdf6e3', 'use' => 'a softer warning field, the "Proposed" note'],
        'warning-tint-3'=> ['value' => '#fdf9ee', 'use' => 'the softest warning field (a send-a-real-message card)'],
        'danger'        => ['value' => '#b3261e', 'use' => 'danger text, a destructive confirm, an urgent count'],
        'danger-pill'   => ['value' => '#8a2020', 'use' => 'danger text inside a pill'],
        'danger-dot'    => ['value' => '#d0362c', 'use' => 'danger dot'],
        'danger-tint'   => ['value' => '#fdf0ef', 'use' => 'danger field, the high-alert pill'],
        'danger-edge'   => ['value' => '#f0c9c5', 'use' => 'the high-alert pill\'s border'],
        'info'          => ['value' => '#2a5f9e', 'use' => 'info text, "in progress"'],
        'info-dot'      => ['value' => '#2a78d6', 'use' => 'info dot'],
        'proposed-ink'  => ['value' => '#5c4400', 'use' => 'text of a "Proposed" note'],
        'scrim'         => ['value' => 'rgba(10,10,10,.32)', 'use' => 'behind the confirm dialog'],
        'scrim-palette' => ['value' => 'rgba(10,10,10,.28)', 'use' => 'behind the command palette'],
        'scrim-drawer'  => ['value' => 'rgba(10,10,10,.2)',  'use' => 'behind the assistant drawer'],
        'on-ink-fill'   => ['value' => 'rgba(255,255,255,.14)', 'use' => 'a button on the black toast'],
    ];

    /**
     * README §10's shadows, verbatim, plus the palette's (which the HTML draws at .22).
     *
     * @var array<string,string>
     */
    private const CONSOLE_SHADOWS = [
        'sh-menu'    => '0 12px 32px rgba(10,10,10,.12)',
        'sh-modal'   => '0 20px 48px rgba(10,10,10,.2)',
        'sh-palette' => '0 20px 48px rgba(10,10,10,.22)',
        'sh-drawer'  => '-12px 0 32px rgba(10,10,10,.12)',
        'sh-toast'   => '0 10px 30px rgba(10,10,10,.25)',
        'sh-hero'    => '0 1px 2px rgba(10,10,10,.04), 0 10px 30px rgba(10,10,10,.05)',
        'sh-tile'    => '0 1px 2px rgba(10,10,10,.04)',
    ];

    /**
     * Every word the console draws, on every ground the handoff draws it on.
     *
     * Read off the HTML, not imagined: `grey-500` is the muted line on a white card, the
     * table head on `fill-panel`, a second line in a focused row (`fill-selected`) and
     * the count beside the CURRENT nav item (`fill-current`); `warning-dot` is the colour
     * of a waiting count on a Home card. Failures are listed in CONSOLE_REPORTED.
     *
     * @var array<string,list<string>>
     */
    private const CONSOLE_WORDS = [
        'ink'          => ['surface', 'fill-panel', 'fill-selected', 'fill-current', 'fill-hover', 'fill-subtle',
                           'success-tint', 'warning-tint', 'warning-tint-2', 'warning-tint-3', 'danger-tint'],
        'grey-800'     => ['surface', 'fill-panel', 'fill-selected'],
        'grey-700'     => ['fill-panel', 'fill-hover', 'surface'],
        'grey-600'     => ['surface', 'fill-panel', 'fill-subtle', 'fill-selected'],
        'grey-500'     => ['surface', 'fill-panel', 'fill-selected', 'fill-current'],
        'grey-450'     => ['fill-panel'],
        'success'      => ['surface', 'success-tint'],
        'green'        => ['surface'],
        'warning'      => ['surface', 'warning-tint', 'warning-tint-2', 'warning-tint-3'],
        'warning-dot'  => ['surface'],
        'danger'       => ['surface', 'danger-tint'],
        'danger-pill'  => ['surface', 'danger-tint'],
        'info'         => ['surface'],
        'proposed-ink' => ['warning-tint-2'],
        'surface'      => ['ink', 'danger', 'green'],
    ];

    /**
     * Words the handoff draws under 4.5:1 — REPORTED to the owner, not re-valued.
     *
     * The test holds this list EXACTLY: a pair that starts passing must leave it, and a
     * new failure must be added here with where it is drawn, which is the report.
     *
     * @var array<string,array{on:string,where:string}>
     */
    private const CONSOLE_REPORTED = [
        'grey-450@fill-panel'    => ['on' => 'fill-panel',    'where' => 'sidebar group labels, 12.5px (3.10:1)'],
        'grey-500@fill-selected' => ['on' => 'fill-selected', 'where' => 'the second line of a focused row, 12–12.5px (4.35:1)'],
        'grey-500@fill-current'  => ['on' => 'fill-current',  'where' => 'the count beside the current nav item, 11.5px (4.04:1)'],
        'warning-dot@surface'    => ['on' => 'surface',       'where' => 'a waiting count on a Home card, 22px at 500 — not large text (2.95:1)'],
    ];

    /**
     * Console tokens drawn below a floor on purpose because they are not words.
     *
     * @var array<string,array{on:string,why:string}>
     */
    private const CONSOLE_ACCEPTED = [
        'grey-350'     => ['on' => 'surface',    'why' => 'a DISABLED control\'s fill; WCAG 1.4.3 exempts an inactive control'],
        'grey-300'     => ['on' => 'surface',    'why' => 'a toggle that is off, a dashed outline'],
        'line-control' => ['on' => 'surface',    'why' => 'input and pill borders, as the handoff draws them'],
        'line-card'    => ['on' => 'surface',    'why' => 'card borders'],
        'line-row'     => ['on' => 'surface',    'why' => 'row rules'],
        'line-bar'     => ['on' => 'surface',    'why' => 'the rule under the top bar'],
        'link-line'    => ['on' => 'surface',    'why' => 'an underline beside words that carry the contrast'],
        'fill-panel'   => ['on' => 'surface',    'why' => 'the sidebar field'],
        'grey-400'     => ['on' => 'surface',    'why' => 'a dot that always sits beside its word'],
    ];

    // ══ the default-graphics tones (DEFAULT-GRAPHICS §4, handoff 5 Oct 2026) ═══════════════
    //
    // Every image slot with no upload draws a generated cover or avatar in one of five tones,
    // each a wash (`bg`), a line and an ink. The spec calls them "Accent pairs" and then
    // gives their hex; twelve of the fifteen ARE palette tokens and are named here, not
    // retyped, so moving a palette colour moves its covers with it. Three are not in the
    // palette at all — gold's line #c99a06, info's wash #e6f0f4 and ink #1f5f8b — and are
    // the spec's own values, typed once, here, as the only file a colour may be typed in.
    // They are reported as a deviation for the owner (docs/handoff/PHASE-DEFAULT-GRAPHICS.md)
    // rather than silently swapped for the nearest token, which would move every gold and
    // info cover off the design by a visible amount.

    // The avatar (DefaultAvatar.dc.html, §8) adds a fourth slot, `edge`, for its gradient's
    // far stop and its 1px ring; live's #f6c7d3 and info's #c9dde8 are not palette tokens
    // either, and are reported with the three above.
    /** @var array<string,array{bg:string,line:string,ink:string,edge:string}> palette name, or a hex */
    private const COVER = [
        'green' => ['bg' => 'green-wash', 'line' => 'green',   'ink' => 'green-deep', 'edge' => 'green-edge'],
        'gold'  => ['bg' => 'gold-wash',  'line' => '#c99a06', 'ink' => 'gold-ink',   'edge' => 'gold-edge'],
        'live'  => ['bg' => 'live-wash',  'line' => 'live',    'ink' => 'live-ink',   'edge' => '#f6c7d3'],
        'info'  => ['bg' => '#e6f0f4',    'line' => 'info',    'ink' => '#1f5f8b',    'edge' => '#c9dde8'],
        'stone' => ['bg' => 'ground',     'line' => 'ink-2',   'ink' => 'ink',        'edge' => 'line-2'],
    ];

    /** @return list<string> the five tone names, in the spec's order */
    public static function coverTones(): array
    {
        return array_keys(self::COVER);
    }

    /** One cover tone's slot as a six-digit hex (`bg`, `line`, `ink`, `edge`) — for GD and the SVG patterns. */
    public static function coverHex(string $tone, string $slot): string
    {
        if (!isset(self::COVER[$tone][$slot])) {
            throw new \InvalidArgumentException(sprintf('No cover tone "%s.%s". Tones: %s; slots: bg, line, ink, edge',
                $tone, $slot, implode(', ', array_keys(self::COVER))));
        }
        $v = self::COVER[$tone][$slot];

        return str_starts_with($v, '#') ? $v : self::hex($v);
    }

    /** `--ag-cover-{tone}-{slot}`: a palette token by reference, the spec's own hex where there is none. */
    private static function coverCss(): array
    {
        $out = [];
        foreach (self::COVER as $tone => $slots) {
            foreach ($slots as $slot => $v) {
                $out[] = '--ag-cover-' . $tone . '-' . $slot . ':' . (str_starts_with($v, '#') ? $v : 'var(--ag-' . $v . ')') . ';';
            }
        }

        return $out;
    }

    /** @return array<string,array{value:string,use:string}> */
    public static function console(): array
    {
        return self::CONSOLE;
    }

    /** @return array<string,string> */
    public static function consoleShadows(): array
    {
        return self::CONSOLE_SHADOWS;
    }

    /** @return array<string,list<string>> */
    public static function consoleWords(): array
    {
        return self::CONSOLE_WORDS;
    }

    /** @return array<string,array{on:string,where:string}> */
    public static function consoleReported(): array
    {
        return self::CONSOLE_REPORTED;
    }

    /** @return array<string,array{on:string,why:string}> */
    public static function consoleAccepted(): array
    {
        return self::CONSOLE_ACCEPTED;
    }

    /** One console token as a six-digit hex. Unknown names and translucent tokens throw. */
    public static function consoleHex(string $name): string
    {
        if (!isset(self::CONSOLE[$name])) {
            throw new \InvalidArgumentException(sprintf(
                'There is no console colour "%s". The console palette is: %s',
                $name, implode(', ', array_keys(self::CONSOLE))));
        }
        $v = self::CONSOLE[$name]['value'];
        if (!preg_match('/^#[0-9a-f]{6}$/', $v)) {
            throw new \InvalidArgumentException(sprintf('"%s" is %s, not a hex', $name, $v));
        }

        return $v;
    }

    // ══ the palette ═══════════════════════════════════════════════════════════

    /** @return array<string,array{value:string,use:string}> */
    public static function palette(): array
    {
        return self::PALETTE;
    }

    /** @return array<string,string> */
    public static function shadows(): array
    {
        return self::SHADOWS;
    }

    /**
     * One token's CSS value. An unknown name THROWS: a colour asked for by a name that
     * does not exist is a typo, and a blank would silently strip a mail's button of its
     * colour on every send.
     */
    public static function value(string $name): string
    {
        if (!isset(self::PALETTE[$name])) {
            throw new \InvalidArgumentException(sprintf(
                'There is no colour "%s". The palette is: %s',
                $name, implode(', ', array_keys(self::PALETTE))));
        }

        return self::PALETTE[$name]['value'];
    }

    /**
     * One token as a six-digit hex, for the places that cannot read `var()` — mail
     * clients, and GD drawing a flier. `scrim` is an rgba and is refused here: no caller
     * that needs a literal can use a translucent one.
     */
    public static function hex(string $name): string
    {
        $v = self::value($name);
        if (!preg_match('/^#[0-9a-f]{6}$/', $v)) {
            throw new \InvalidArgumentException(sprintf('"%s" is %s, not a hex', $name, $v));
        }

        return $v;
    }

    /**
     * Every hex token, under the handoff's own names, for a MAIL template.
     *
     * An inbox cannot read `var()` — Gmail strips the <style> that would declare one and
     * most clients never resolve it — so mail carries literal colours. It does not TYPE
     * them: every sender passes this map to its template as `c`, and the template writes
     * `{{ c.ink }}` or `{{ c['ink-2'] }}`. Before 3 Oct 2026 the seven templates typed
     * thirty-eight colours that were in no palette, and the newsletter read the palette
     * through a second set of names of its own (`card`, `action`, `bar_soft`…) — an alias
     * table, which is how a renamed token goes on rendering under a name nobody can find.
     * One map, the palette's names, nothing else. `scrim` is translucent and mail cannot
     * use it, so it is not here.
     *
     * @return array<string,string> token => six-digit hex
     */
    public static function mail(): array
    {
        $out = [];
        foreach (self::PALETTE as $name => $v) {
            if (preg_match('/^#[0-9a-f]{6}$/', $v['value'])) $out[$name] = $v['value'];
        }
        return $out;
    }

    /** @return array<string,list<string>> */
    public static function words(): array
    {
        return self::WORDS;
    }

    /** @return array<string,array{on:string,why:string}> */
    public static function accepted(): array
    {
        return self::ACCEPTED;
    }

    /**
     * The palette and the shadows as custom properties, for one nonced `<style>` that
     * every layout writes BEFORE its stylesheets.
     *
     * Values only ever come from the two constant tables, so nothing here can carry
     * anything a stylesheet would execute.
     *
     * `css('console')` is the admin console's set instead — `--cn-*`, and nothing of the
     * public palette, so neither surface can read the other's colours. Any other
     * argument throws rather than quietly emitting the public set into a console.
     */
    public static function css(string $surface = 'site'): string
    {
        if ($surface === 'console') {
            $out = [];
            foreach (self::CONSOLE as $name => $v) {
                $out[] = '--cn-' . $name . ':' . $v['value'] . ';';
            }
            foreach (self::CONSOLE_SHADOWS as $name => $v) {
                $out[] = '--cn-' . $name . ':' . $v . ';';
            }

            return ':root{' . implode('', $out) . '}';
        }
        if ($surface !== 'site') {
            throw new \InvalidArgumentException(sprintf('No surface "%s": site or console', $surface));
        }

        $out = [];
        foreach (self::PALETTE as $name => $v) {
            $out[] = '--ag-' . $name . ':' . $v['value'] . ';';
        }
        foreach (self::SHADOWS as $name => $v) {
            $out[] = '--ag-' . $name . ':' . $v . ';';
        }
        $out = array_merge($out, self::coverCss());

        return ':root{' . implode('', $out) . '}';
    }

    // ══ families and meanings ═══════════════════════════════════════════════

    /** @return array<string,array{fill:string,edge:string,ink:string,wash:string}> */
    public static function families(): array
    {
        return self::FAMILIES;
    }

    /** @return array<string,list<string>> */
    public static function fields(): array
    {
        return self::FIELDS;
    }

    /** @return array<string,string> */
    public static function meanings(): array
    {
        return self::MEANINGS;
    }

    /**
     * One meaning's four slots, as token names.
     *
     * @return array{fill:string,edge:string,ink:string,wash:string}
     */
    public static function for(string $meaning): array
    {
        return self::FAMILIES[self::familyFor($meaning)];
    }

    /** Which family a meaning resolves to. Throws on a meaning nobody defined. */
    public static function familyFor(string $meaning): string
    {
        $key = strtolower(trim($meaning));

        if (!isset(self::MEANINGS[$key])) {
            throw new \InvalidArgumentException(sprintf(
                'No colour means "%s". The meanings are: %s',
                $meaning, implode(', ', array_keys(self::MEANINGS))));
        }

        return self::MEANINGS[$key];
    }

    /**
     * One tile's four parts, as inline custom properties pointing at the palette.
     *
     * The tile is a wash bounded by an edge, a saturated mark, and a word. Every family's
     * ink clears 4.5 on its own wash now — `live-ink` is 5.78 on `live-wash` — so the old
     * exception that labelled a live tile in house ink has no reason to exist and is gone.
     */
    public static function tileStyle(string $meaning): string
    {
        $c = self::for($meaning);

        return '--tile-wash:var(--ag-' . $c['wash'] . ');--tile-edge:var(--ag-' . $c['edge']
             . ');--tile-fill:var(--ag-' . $c['fill'] . ');--tile-ink:var(--ag-' . $c['ink'] . ')';
    }

    // ══ programme identity ══════════════════════════════════════════════════

    /** @return array<string,array{fill:string,edge:string,ink:string,wash:string}> */
    public static function programmes(): array
    {
        return self::PROGRAMMES;
    }

    /**
     * One programme's identity, chosen from its own id.
     *
     * By id, one-based, because programme ids are: the first programmes created take the
     * first identities, and a programme's colour never depends on what else is on the
     * page — deriving it from a row's position would make one programme change colour
     * between the hall and the archive, which is the opposite of an identity.
     *
     * @return array{key:string,fill:string,edge:string,ink:string,wash:string}
     */
    public static function forProgramme(int $id): array
    {
        $keys = array_keys(self::PROGRAMMES);
        $n    = count($keys);
        // `$id % $n` first, so no id — PHP_INT_MIN included — can overflow on the way.
        $key  = $keys[((($id % $n) - 1) % $n + $n) % $n];

        return ['key' => $key] + self::PROGRAMMES[$key];
    }

    /**
     * A programme's identity as inline custom properties — per row, so it cannot live in
     * `:root`. Safe in a `style` attribute: every value is a `var()` of a palette token,
     * so nothing a programme's own typed fields contain can reach a stylesheet.
     */
    public static function programmeStyle(int $id): string
    {
        $c = self::forProgramme($id);

        return '--pg-fill:var(--ag-' . $c['fill'] . ');--pg-edge:var(--ag-' . $c['edge']
             . ');--pg-ink:var(--ag-' . $c['ink'] . ');--pg-wash:var(--ag-' . $c['wash'] . ')';
    }

    /**
     * A CHALLENGE'S THEME — the one table, read by the page and by the flier.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY THIS IS HERE AND NOT FOUR BLOCKS IN A STYLESHEET
     * ══════════════════════════════════════════════════════════════════════════
     *
     * The rebuilt page first carried `[data-theme="green|blue|gold|rose"]` blocks in
     * `components/challenge.css`, and two things were wrong with it.
     *
     * The first `ColourBudgetTest` found: a sheet naming four families charges the
     * page four colour events, and a tier-2 page may spend two. That is the counter
     * measuring the STYLESHEET rather than the screen — a challenge page draws one
     * theme — but the index genuinely does show cards of four themes at once, so the
     * objection is real and not a blind spot to work around.
     *
     * The second is worse and the counter could never have found it: the flier held
     * the SAME four themes as hexes in `ChallengeFlier::THEMES`, because there is no
     * CSS in a PNG. Two tables, one rule, and nothing but a test between them — "a
     * blue challenge whose page is blue and whose flier is green is two products".
     *
     * So the theme is resolved here, once, and emitted per element exactly as
     * {@see programmeStyle()} emits a programme's. The flier reads the same table.
     * There is no second copy left to drift.
     *
     * ── `solid` IS NOT `fill`, AND THAT IS THE WHOLE POINT OF IT ─────────────
     *
     * `fill` is the identity and owes no contrast floor. `solid` carries WHITE TEXT
     * on the primary action, so it owes 4.5:1 — gold's fill is `#f3b416`, which holds
     * white at 1.9:1, so a gold challenge's main button had a label nobody could
     * read. `ChallengePageTest::test_each_themes_solid_holds_white_text` re-derives
     * every floor from these token names rather than trusting this paragraph.
     *
     * @return array{fill:string, edge:string, wash:string, solid:string} token names
     */
    public static function challengeTheme(?string $theme): array
    {
        return match ((string) $theme) {
            'blue' => ['fill' => 'info',  'edge' => 'info',     'wash' => 'info-wash',  'solid' => 'info'],
            'gold' => ['fill' => 'gold',  'edge' => 'gold-ink', 'wash' => 'gold-wash',  'solid' => 'gold-ink'],
            'rose' => ['fill' => 'live',  'edge' => 'live-ink', 'wash' => 'live-wash',  'solid' => 'live-ink'],
            // Green is the house accent and the default, so an unknown name — an older
            // row, a hand-edited column — draws plain rather than drawing with every
            // property undefined: a transparent hero and a `currentColor` kicker.
            default => ['fill' => 'green', 'edge' => 'green-deep', 'wash' => 'green-wash', 'solid' => 'green'],
        };
    }

    /**
     * That theme as inline custom properties, per element.
     *
     * Safe in a `style` attribute: every value is a `var()` of a palette token, so
     * nothing a challenge's own typed fields contain can reach a stylesheet.
     */
    public static function challengeStyle(?string $theme): string
    {
        $c = self::challengeTheme($theme);

        return '--ch-fill:var(--ag-' . $c['fill'] . ');--ch-edge:var(--ag-' . $c['edge']
             . ');--ch-wash:var(--ag-' . $c['wash'] . ');--ch-solid:var(--ag-' . $c['solid'] . ')';
    }
}
