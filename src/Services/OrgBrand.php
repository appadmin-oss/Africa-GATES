<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * An organisation's own look, on their own donation page.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THIS IS FOR
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A partner's appeal page used to carry their NAME and nothing else that was theirs: same
 * typeface, same colour, same platform copy around the form, no logo anywhere on it. A
 * supporter following a link from that organisation's own WhatsApp group arrived somewhere
 * that looked like a platform rather than like the people asking them for money — which is
 * the one thing a donation page has to get right, because giving is an act of trust in a
 * specific organisation and not in whoever is processing the card.
 *
 * We provide the donation service: the checkout, the settlement straight into their own
 * account, the receipt, the refund path, the audit trail. What the page LOOKS like belongs
 * to whoever is doing the asking.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT AN ORGANISATION CAN AND CANNOT CHANGE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * They choose: a logo, one accent colour, a tagline, a longer story, a website, and which
 * of the blocks appear — impact figures, a gift ladder, video, quotes, an FAQ, the team, a
 * short history, who they work with, and links out. {@see BLOCKS} is the table that defines
 * the two-field ones; {@see SECTIONS} is what the editor lists.
 *
 * They cannot change: the amount field, the fee disclosure, the settlement statement, the
 * refund policy, the line naming who receives the money, or the Africa GATES credit. Those
 * are not decoration. A page where the organisation could edit "your donation settles
 * directly to us" is a page where that sentence stops being evidence of anything, and one
 * where they could remove the credit is one where the platform funding it disappears from
 * the only place it is ever mentioned. {@see GATES_CREDIT}.
 *
 * ── AND THE ACCENT IS VALIDATED, NOT JUST STORED ─────────────────────────────
 *
 * {@see contrast()} refuses an accent that cannot be read against white. This is the one
 * place a taste question becomes a correctness question: the accent is the colour of the
 * donate button's label and of every link on the page, so #f2f2c0 is not a bold choice, it
 * is a button whose text has vanished. WCAG 2.2 AA wants 4.5:1 for body text and the same
 * ratio governs white-on-accent, so one check covers both uses.
 *
 * An organisation that picks an unreadable colour is TOLD, with the ratio and the
 * threshold, rather than having their choice silently discarded — a setting that appears to
 * save and then does not is worse than a refusal.
 */
final class OrgBrand
{
    /** The house accent, used when an organisation has not chosen one. */
    public const DEFAULT_ACCENT = '#237b22';

    /** WCAG 2.2 AA for normal text, and the floor for white-on-accent buttons. */
    public const MIN_CONTRAST = 4.5;

    public const MAX_TAGLINE = 140;
    public const MAX_STORY   = 4000;

    /**
     * The sections an organisation can switch on and off.
     *
     * Not "plugins" in the sense of third-party code — nothing here executes anything an
     * organisation supplies, and it never will: a donation page that runs somebody else's
     * script is a donation page that can have its amount field rewritten by whoever
     * compromises them. These are OUR blocks, shown or hidden.
     */
    public const SECTIONS = [
        'story'     => 'Your story — a longer description above the form',
        'campaigns' => 'Your open appeals, with their progress',
        'totals'    => 'What has been raised through this page so far',
        'contact'   => 'How to reach you — website and contact email',
        'gallery'   => 'Photographs of the work',
        'impact'    => 'Figures — what the work has actually done',
        'asks'      => 'What a gift buys — an amount beside what it pays for',
        'video'     => 'Video — from YouTube or Vimeo',
        'quotes'    => 'What people say about you',
        'faq'       => 'Questions donors ask you',
        'team'      => 'Who runs it',
        'milestones'=> 'How you got here — a short history',
        'partners'  => 'Who you work with',
        'links'     => 'Links — your reports, your press, your socials',
    ];

    /**
     * THE ONE BLOCK AN ORGANISATION CANNOT SWITCH OFF, AND WHY IT IS NOT A CHOICE.
     *
     * Africa GATES provides this page, the checkout, the settlement into the
     * organisation's own account, the receipting and the refund path, and takes no cut of
     * any gift. It is funded by an OPTIONAL donor contribution and by giving to the awards
     * programme directly. A platform that is free to its organisations has to be paid for
     * by somebody, and the honest place to say so is on the page the money moves through.
     *
     * ── WHAT IT IS AND WHAT IT DELIBERATELY IS NOT ──────────────────────────
     *
     * It is a credit line and a quiet, separate route to support Africa GATES, BELOW the
     * organisation's own ask and after their form. It is not a second amount field, it is
     * not preselected, and it never competes for the press the organisation's donate
     * button is asking for.
     *
     * That restraint is the commercial decision, not squeamishness. A charity's donation
     * page that puts the platform's hand out beside theirs converts worse for the charity
     * — which is the thing this platform exists to be good at — and reads to a donor as
     * two organisations arguing over one gift. The design handoff went further still and
     * permitted only a footer line; this adds a route to give, because a credit nobody can
     * act on raises money for nobody, and that is the whole point of the block.
     */
    public const GATES_CREDIT = 'Powered by Africa GATES Giving';

    /**
     * On by default. A new organisation gets a page that already says something.
     *
     * The nine blocks added later are OFF by default, and that is not timidity: an empty
     * "Questions donors ask you" heading on a live appeal reads as a page somebody
     * abandoned halfway. A block appears when it has content AND has been switched on —
     * both, so that switching one on before writing anything cannot publish a bare
     * heading either.
     */
    private const SECTIONS_DEFAULT = ['story' => true, 'campaigns' => true, 'totals' => true,
                                      'contact' => true, 'gallery' => false,
                                      'impact' => false, 'asks' => false, 'video' => false,
                                      'quotes' => false, 'faq' => false, 'team' => false,
                                      'milestones' => false, 'partners' => false,
                                      'links' => false];

    // ═══════════════════════════════════════════════════════════════════════
    // HOW MUCH OF EACH, AND WHY THERE IS A CEILING AT ALL
    // ═══════════════════════════════════════════════════════════════════════
    //
    // `gates_partner_orgs.brand_json` is a TEXT column, which is **65,535 bytes on MySQL**
    // and unbounded on SQLite. So an organisation pasting forty links and a 4,000-character
    // story would save perfectly in dev and, on production, either throw in strict mode or —
    // worse, on a host that overrides sql_mode, which shared hosting does — be TRUNCATED to
    // 64KB. A truncated JSON document does not parse, so `of()` would fall back to the
    // house defaults and the organisation's whole page would silently revert to unbranded.
    //
    // Two guards, because a per-list cap alone does not bound the total: each list has a
    // count limit, and {@see save()} refuses when the ENCODED document exceeds
    // MAX_JSON_BYTES. The margin under 65,535 is deliberate — multi-byte characters cost
    // more than one byte each, and an organisation writing in a language that uses them
    // must not hit a wall the English-language test never reaches.

    public const MAX_LINKS  = 12;
    public const MAX_VIDEOS = 6;

    public const MAX_LABEL  = 90;    // a link label, an impact caption, a quote's author
    public const MAX_FIGURE = 24;    // "12,400" or "₦4.2m" — a figure, not a sentence

    /** Refuse above this rather than let MySQL truncate the document. */
    public const MAX_JSON_BYTES = 56000;

    /**
     * THE TWO-FIELD BLOCKS, AS A TABLE RATHER THAN AS SEVEN LOOPS.
     *
     * Every one of these is the same shape: a repeating list of rows, each row two pieces
     * of plain text, both required, capped in length and in count. They differ only in
     * their field names, their limits and the words on the screen.
     *
     * Written as data because there are SEVEN. Seven near-identical read loops and seven
     * near-identical write loops is fourteen places to fix the next time a rule changes —
     * and this codebase has the scar for exactly that shape: two lists that "claimed the
     * same thing in the same words" and only one of them was right. One reader
     * ({@see readBlock()}) and one writer ({@see blockFrom()}) drive all of them, so a rule
     * cannot hold for impact figures and quietly not for milestones.
     *
     * Adding a block is one entry here plus one entry in {@see SECTIONS} plus its markup.
     * No migration: it is all one JSON document on a row already loaded by id or slug.
     *
     * `a` is the leading field — the figure, the question, the person's name — and is what
     * the template emphasises. `b` is the supporting one.
     *
     * @var array<string, array{a:string, b:string, a_max:int, b_max:int, max:int,
     *                          a_label:string, b_label:string, a_hint:string, b_hint:string}>
     */
    public const BLOCKS = [
        'impact' => [
            'a' => 'figure', 'b' => 'label', 'a_max' => self::MAX_FIGURE, 'b_max' => self::MAX_LABEL,
            'max' => 8,
            'a_label' => 'The figure', 'b_label' => 'What it counts',
            'a_hint' => '12,400', 'b_hint' => 'meals served in 2026',
        ],
        'asks' => [
            // A gift ladder. The single highest-converting block on a donation page,
            // because "₦25,000" means nothing and "₦25,000 sends a student for a term"
            // means something. DISPLAY ONLY in this release — it does not preset the
            // amount field, and pretending otherwise would be a control that looks live
            // and does nothing.
            'a' => 'amount', 'b' => 'buys', 'a_max' => self::MAX_FIGURE, 'b_max' => 140,
            'max' => 5,
            'a_label' => 'The amount', 'b_label' => 'What it pays for',
            'a_hint' => '₦25,000', 'b_hint' => 'sends one student for a term',
        ],
        'quotes' => [
            'a' => 'text', 'b' => 'who', 'a_max' => 400, 'b_max' => self::MAX_LABEL,
            'max' => 6,
            'a_label' => 'What they said', 'b_label' => 'Who said it',
            'a_hint' => 'The borehole changed what our school day looks like.',
            'b_hint' => 'Head teacher, Ikorodu Community Primary',
        ],
        'faq' => [
            'a' => 'q', 'b' => 'a', 'a_max' => 160, 'b_max' => 700,
            'max' => 12,
            'a_label' => 'The question', 'b_label' => 'Your answer',
            'a_hint' => 'How much of my gift reaches the work?',
            'b_hint' => 'Answer it in your own words. This is the block donors read most.',
        ],
        'team' => [
            'a' => 'name', 'b' => 'role', 'a_max' => self::MAX_LABEL, 'b_max' => 140,
            'max' => 10,
            'a_label' => 'Name', 'b_label' => 'Role',
            'a_hint' => 'Dr Amina Yusuf', 'b_hint' => 'Founder and Executive Director',
        ],
        'milestones' => [
            'a' => 'when', 'b' => 'what', 'a_max' => 40, 'b_max' => 200,
            'max' => 10,
            'a_label' => 'When', 'b_label' => 'What happened',
            'a_hint' => '2019', 'b_hint' => 'First four boreholes commissioned in Oyo State',
        ],
        'partners' => [
            'a' => 'name', 'b' => 'note', 'a_max' => self::MAX_LABEL, 'b_max' => 140,
            'max' => 12,
            'a_label' => 'Who', 'b_label' => 'What they do with you',
            'a_hint' => 'Lagos State Ministry of Education',
            'b_hint' => 'Places our reading programme in twelve schools',
        ],
    ];

    /**
     * THE VIDEO PROVIDERS, AND WHY THIS IS AN ALLOWLIST AND NOT A URL FIELD.
     *
     * An "embed" in the general sense is an arbitrary third-party iframe, and there are two
     * independent reasons this platform cannot offer that.
     *
     * The first is the one that would be discovered late: the live Content-Security-Policy
     * on this host is the STATIC one in `public/.htaccess` — the nonce policy in
     * {@see \AfricaGates\Support\Csp::policy()} has never reached a browser here, because
     * the host injects its own header. `frame-src` on it names specific origins. So an
     * iframe to any other host renders as a blank box with a console line the visitor never
     * sees, which is precisely the shape of fault this codebase keeps paying for: the
     * feature is simply not there and nothing on the page can say why.
     *
     * The second is the one that matters more. {@see SECTIONS} promises that nothing here
     * executes anything an organisation supplies, because a donation page that runs
     * somebody else's script is a donation page whose amount field can be rewritten by
     * whoever compromises them. A pasted `<iframe src>` is that script, one redirect away.
     *
     * So an organisation pastes the URL they already have, and we keep only the VIDEO ID.
     * {@see embedUrl()} then builds our own URL from the provider and that id. Nothing an
     * organisation typed ever reaches an `src` attribute — which is also what makes the id
     * pattern below load-bearing rather than cosmetic.
     *
     * Adding a provider is three edits and all three are required: an entry here, the origin
     * in `Csp::FRAME_HOSTS`, and the same origin in `public/.htaccess`. `CspStaticFallbackTest`
     * fails if the last two disagree, which is the only reason that pair stays honest.
     *
     * @var array<string, array{label:string, id:string, embed:string, watch:string}>
     */
    public const VIDEO_PROVIDERS = [
        'youtube' => [
            'label' => 'YouTube',
            // Exactly eleven of the URL-safe alphabet. Anchored, so a value that reached
            // this array cannot carry a quote, an angle bracket or a second URL.
            'id'    => '~^[A-Za-z0-9_-]{11}$~',
            // -nocookie deliberately: a donation page should not set a tracking cookie on
            // a visitor's browser on behalf of a video nobody pressed play on.
            'embed' => 'https://www.youtube-nocookie.com/embed/',
            'watch' => 'https://www.youtube.com/watch?v=',
        ],
        'vimeo' => [
            'label' => 'Vimeo',
            'id'    => '~^[0-9]{6,12}$~',
            'embed' => 'https://player.vimeo.com/video/',
            'watch' => 'https://vimeo.com/',
        ],
    ];

    // ═══════════════════════════════════════════════════════════════════════
    // READING
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * The brand for one organisation, always complete.
     *
     * Never returns a partial array. Every caller is a template, and a template deciding
     * what an absent accent means is how two pages end up disagreeing about the same
     * organisation's colour.
     *
     * @return array{accent:string, logo:string, tagline:string, story:string,
     *               website:string, gallery:list<string>, sections:array<string,bool>,
     *               is_default:bool}
     */
    public static function of(?object $org): array
    {
        $raw = [];
        if ($org !== null) {
            $j = json_decode((string) ($org->brand_json ?? ''), true);
            if (is_array($j)) $raw = $j;
        }

        $accent = self::normaliseHex((string) ($raw['accent'] ?? ''));
        if ($accent === '' || !self::readable($accent)) $accent = self::DEFAULT_ACCENT;

        $gallery = [];
        foreach ((array) ($raw['gallery'] ?? []) as $g) {
            $g = trim((string) $g);
            if ($g !== '' && self::safePath($g)) $gallery[] = $g;
        }

        $sections = self::SECTIONS_DEFAULT;
        foreach (self::SECTIONS as $key => $_) {
            if (isset($raw['sections'][$key])) $sections[$key] = (bool) $raw['sections'][$key];
        }

        $logo = trim((string) ($raw['logo'] ?? ''));
        if (!self::safePath($logo)) $logo = '';

        return [
            'accent'   => $accent,
            'logo'     => $logo,
            'tagline'  => self::clip((string) ($raw['tagline'] ?? ''), self::MAX_TAGLINE),
            'story'    => self::clipText((string) ($raw['story'] ?? ''), self::MAX_STORY),
            'website'  => self::url((string) ($raw['website'] ?? '')),
            'gallery'  => array_slice($gallery, 0, 6),
            // ── THE BLOCKS ARE RE-VALIDATED ON THE WAY OUT, NOT ONLY IN ─────
            //
            // `save()` already refuses a bad URL and a bad video id, so this looks
            // redundant. It is not, and the reason is the column: `brand_json` is a
            // document, and a document survives the code that wrote it. Rows exist that
            // were written before a rule did — by an import, by a restore, by a future
            // migration that back-fills, by this very release if a provider is ever
            // retired. Reading a URL out of storage and putting it in an `href` because
            // "it was checked when it was saved" is trusting a past version of this file.
            //
            // So every URL goes through url() again and every video through the provider
            // pattern again, on every read. It costs a preg_match per row on a page that
            // does one indexed query, and it means the template can render what it is
            // handed without a second thought about where it came from.
            'links'    => self::readLinks($raw['links'] ?? []),
            'videos'   => self::readVideos($raw['videos'] ?? []),
            // Every two-field block, from one table. `blocks` is keyed by block name so a
            // template reads `brand.blocks.impact` and a new block needs no change here.
            'blocks'   => self::readBlocks($raw),
            'sections' => $sections,
            // Whether this organisation has actually designed anything, so a page can tell
            // "chose our green" from "never opened the screen" and prompt accordingly.
            'is_default' => $raw === [],
        ];
    }

    /**
     * A hex the page can drop into a CSS custom property, and a darker one for hover.
     *
     * Returned as a pair because a single accent gives a flat button; the darker shade is
     * computed rather than asked for, since nobody choosing a brand colour also wants to
     * choose its pressed state.
     *
     * @return array{accent:string, accent_dark:string, accent_wash:string}
     */
    public static function css(array $brand): array
    {
        $hex = self::normaliseHex((string) ($brand['accent'] ?? self::DEFAULT_ACCENT))
             ?: self::DEFAULT_ACCENT;
        [$r, $g, $b] = self::rgb($hex);

        $dark = sprintf('#%02x%02x%02x',
            (int) round($r * 0.78), (int) round($g * 0.78), (int) round($b * 0.78));

        return [
            'accent'      => $hex,
            'accent_dark' => $dark,
            // rgba rather than a mixed hex, so it sits correctly on both the paper ground
            // and a white card without being computed twice.
            'accent_wash' => sprintf('rgba(%d,%d,%d,.10)', $r, $g, $b),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // READING THE BLOCKS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Titled links out. A row with no label or no usable URL is dropped, not repaired.
     *
     * Dropped rather than shown with the URL as its own label, which was the obvious
     * alternative: a link whose visible text is `https://drive.google.com/file/d/1a2B…`
     * is not a link somebody clicks, and a donation page is not the place to teach a
     * reader to squint at an address.
     *
     * @return list<array{label:string, url:string, host:string}>
     */
    private static function readLinks(mixed $raw): array
    {
        $out = [];
        foreach ((array) $raw as $row) {
            if (!is_array($row)) continue;
            $url   = self::url((string) ($row['url'] ?? ''));
            $label = self::clip((string) ($row['label'] ?? ''), self::MAX_LABEL);
            if ($url === '' || $label === '') continue;

            // Shown beside the label so a reader knows where a link goes before pressing
            // it. On a page asking for money, "this leaves for youtube.com" is information
            // the reader is owed, and it is the cheap half of the defence against an
            // organisation being persuaded to link somewhere they should not.
            $out[] = ['label' => $label, 'url' => $url,
                      'host'  => (string) (parse_url($url, PHP_URL_HOST) ?: '')];
            if (count($out) >= self::MAX_LINKS) break;
        }
        return $out;
    }

    /**
     * Videos, each already reduced to a provider and an id.
     *
     * @return list<array{provider:string, id:string, title:string, embed:string, watch:string}>
     */
    private static function readVideos(mixed $raw): array
    {
        $out = [];
        foreach ((array) $raw as $row) {
            if (!is_array($row)) continue;
            $v = ['provider' => (string) ($row['provider'] ?? ''), 'id' => (string) ($row['id'] ?? '')];
            $embed = self::embedUrl($v);
            if ($embed === '') continue;

            $out[] = $v + [
                'title' => self::clip((string) ($row['title'] ?? ''), self::MAX_LABEL),
                'embed' => $embed,
                'watch' => self::watchUrl($v),
            ];
            if (count($out) >= self::MAX_VIDEOS) break;
        }
        return $out;
    }

    /**
     * Every two-field block, keyed by name, each one complete and re-validated.
     *
     * @return array<string, list<array<string,string>>>
     */
    private static function readBlocks(array $raw): array
    {
        $out = [];
        foreach (self::BLOCKS as $name => $spec) {
            $out[$name] = self::readBlock($raw[$name] ?? [], $spec);
        }
        return $out;
    }

    /**
     * One block's rows.
     *
     * A row missing EITHER field is dropped. A question with no answer, a figure with no
     * caption and a name with no role are each half a thing, and half a thing on a live
     * appeal reads as a page somebody abandoned — which costs the organisation more than
     * the missing row would have earned them.
     *
     * @param array{a:string, b:string, a_max:int, b_max:int, max:int, ...} $spec
     * @return list<array<string,string>>
     */
    private static function readBlock(mixed $raw, array $spec): array
    {
        $out = [];
        foreach ((array) $raw as $row) {
            if (!is_array($row)) continue;
            $a = self::clip((string) ($row[$spec['a']] ?? ''), $spec['a_max']);
            $b = self::clip((string) ($row[$spec['b']] ?? ''), $spec['b_max']);
            if ($a === '' || $b === '') continue;

            $out[] = [$spec['a'] => $a, $spec['b'] => $b];
            if (count($out) >= $spec['max']) break;
        }
        return $out;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // VIDEO
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Turn whatever an organisation pasted into a provider and an id, or nothing.
     *
     * They will paste the address bar. That means a `watch?v=` link, a `youtu.be` short
     * link, a `/shorts/` link, an already-embedded `/embed/` link, a Vimeo number, a
     * `player.vimeo.com` link — and any of them with `?si=…`, `&t=90`, a trailing slash or
     * surrounding whitespace. Asking somebody to extract an id by hand is asking them to
     * get it wrong, and the failure would be a blank box.
     *
     * The HOST is matched exactly rather than with a substring test. `str_contains($url,
     * 'youtube.com')` is true of `https://youtube.com.attacker.example/x` and of
     * `https://attacker.example/?q=youtube.com`, so a substring check is how an allowlist
     * stops being one. Parsed with `parse_url()` and compared against a fixed set.
     *
     * @return array{provider:string, id:string}|null
     */
    public static function videoRef(string $raw): ?array
    {
        $v = trim($raw);
        if ($v === '') return null;

        // A bare id is accepted for both providers, because somebody who has read the
        // help text will paste exactly that and refusing it would be perverse. Checked
        // against the provider patterns, so it is still not free text.
        foreach (self::VIDEO_PROVIDERS as $key => $p) {
            if (preg_match($p['id'], $v)) {
                // An all-digit string matches Vimeo and nothing else; an eleven-character
                // mixed string matches YouTube and nothing else. The two patterns do not
                // overlap, which is what makes this unambiguous rather than first-wins.
                return ['provider' => $key, 'id' => $v];
            }
        }

        if (!preg_match('~^https?://~i', $v)) $v = 'https://' . $v;
        $parts = parse_url($v);
        if (!is_array($parts) || ($parts['host'] ?? '') === '') return null;

        $host = strtolower((string) $parts['host']);
        if (str_starts_with($host, 'www.')) $host = substr($host, 4);
        $path = trim((string) ($parts['path'] ?? ''), '/');
        parse_str((string) ($parts['query'] ?? ''), $q);

        $candidate = match ($host) {
            'youtube.com', 'm.youtube.com', 'youtube-nocookie.com' =>
                // `watch?v=`, or the last segment of /embed/, /shorts/, /live/, /v/.
                ['youtube', (string) ($q['v'] ?? self::lastSegment($path, ['embed', 'shorts', 'live', 'v']))],
            'youtu.be'          => ['youtube', $path],
            'vimeo.com'         => ['vimeo', self::lastSegment($path, []) ?: $path],
            'player.vimeo.com'  => ['vimeo', self::lastSegment($path, ['video'])],
            default             => null,
        };

        if ($candidate === null) return null;
        [$provider, $id] = $candidate;

        // The id still has to satisfy the provider's own pattern. This is the line that
        // makes everything above safe: whatever route a value took through the parsing, it
        // reaches storage only if it is eleven URL-safe characters or a run of digits.
        return preg_match(self::VIDEO_PROVIDERS[$provider]['id'], $id)
            ? ['provider' => $provider, 'id' => $id]
            : null;
    }

    /**
     * The last path segment, optionally only when the path begins with an expected prefix.
     *
     * `/shorts/abc` and `/embed/abc` both yield `abc`; a path of `/channel/UCxxxx` yields
     * nothing, because `channel` is not in the prefix list and a channel is not a video.
     * With an empty prefix list any single segment is taken, which is what Vimeo's
     * `/123456789` needs.
     *
     * @param list<string> $prefixes
     */
    private static function lastSegment(string $path, array $prefixes): string
    {
        if ($path === '') return '';
        $seg = explode('/', $path);

        if ($prefixes !== []) {
            if (count($seg) < 2 || !in_array($seg[0], $prefixes, true)) return '';
            return $seg[1];
        }
        return count($seg) === 1 ? $seg[0] : '';
    }

    /**
     * OUR url for a stored video, built from the provider and the id.
     *
     * Never a URL an organisation typed — see the note above {@see VIDEO_PROVIDERS}. An
     * unknown provider returns an empty string rather than guessing a host, so a row that
     * somehow survived a provider being retired renders as nothing instead of as a link to
     * a domain this platform no longer vouches for.
     */
    public static function embedUrl(array $video): string
    {
        $p  = (string) ($video['provider'] ?? '');
        $id = (string) ($video['id'] ?? '');
        if (!isset(self::VIDEO_PROVIDERS[$p])) return '';
        if (!preg_match(self::VIDEO_PROVIDERS[$p]['id'], $id)) return '';

        return self::VIDEO_PROVIDERS[$p]['embed'] . $id;
    }

    /** Where to send somebody whose browser refused the frame. Same guards as embedUrl(). */
    public static function watchUrl(array $video): string
    {
        $p  = (string) ($video['provider'] ?? '');
        $id = (string) ($video['id'] ?? '');
        if (!isset(self::VIDEO_PROVIDERS[$p])) return '';
        if (!preg_match(self::VIDEO_PROVIDERS[$p]['id'], $id)) return '';

        return self::VIDEO_PROVIDERS[$p]['watch'] . $id;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // WRITING
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Save what an organisation typed.
     *
     * Refuses rather than silently correcting, in every case where the organisation would
     * otherwise see a screen that saved and a page that did not change.
     *
     * @return array{ok:bool, message:string, field?:string}
     */
    public static function save(int $orgId, array $in): array
    {
        if ($orgId < 1) return ['ok' => false, 'message' => 'That organisation does not exist.'];

        $org = DB::table('gates_partner_orgs')->where('id', $orgId)->first();
        if (!$org) return ['ok' => false, 'message' => 'That organisation does not exist.'];

        $current = self::of($org);

        // ── the accent ───────────────────────────────────────────────────────
        $accent = self::normaliseHex((string) ($in['accent'] ?? ''));
        if (trim((string) ($in['accent'] ?? '')) !== '' && $accent === '') {
            return ['ok' => false, 'field' => 'accent',
                    'message' => 'That is not a colour we can read. Use a six-digit hex code '
                               . 'like #1a6118 — your designer or your logo file will have one.'];
        }
        if ($accent !== '' && !self::readable($accent)) {
            $ratio = self::contrast($accent, '#ffffff');
            return ['ok' => false, 'field' => 'accent',
                    'message' => 'That colour cannot be read on a white page — it scores '
                               . number_format($ratio, 1) . ':1 against white and needs at least '
                               . number_format(self::MIN_CONTRAST, 1) . ':1. It is the colour of '
                               . 'your donate button and every link, so a lighter shade would '
                               . 'leave people unable to see them. Try a darker version.'];
        }
        if ($accent === '') $accent = self::DEFAULT_ACCENT;

        // ── the words ────────────────────────────────────────────────────────
        $tagline = self::clip((string) ($in['tagline'] ?? ''), self::MAX_TAGLINE);
        $story   = self::clipText((string) ($in['story'] ?? ''), self::MAX_STORY);

        $websiteRaw = trim((string) ($in['website'] ?? ''));
        $website    = self::url($websiteRaw);
        if ($websiteRaw !== '' && $website === '') {
            return ['ok' => false, 'field' => 'website',
                    'message' => 'That web address did not look right. It should start with '
                               . 'https:// and be a full address.'];
        }

        // ── the sections ─────────────────────────────────────────────────────
        $sections = [];
        foreach (self::SECTIONS as $key => $_) {
            $sections[$key] = !empty($in['section_' . $key]);
        }

        // ── the blocks ───────────────────────────────────────────────────────
        //
        // Each arrives as parallel arrays from a repeating form — `link_label[]` beside
        // `link_url[]` — which is what a form does without JavaScript, and this screen has
        // to work without it. Rows are zipped by INDEX, and an index present in one array
        // and absent from the other is a row the organisation left half-filled: dropped,
        // not saved as a fragment.
        $links = [];
        foreach (self::rows($in, ['label' => 'link_label', 'url' => 'link_url']) as $r) {
            if ($r['label'] === '' && $r['url'] === '') continue;   // an untouched blank row
            $url = self::url($r['url']);
            if ($url === '') {
                return ['ok' => false, 'field' => 'link_url',
                        'message' => 'One of your links is not a full web address. Each one has to '
                                   . 'start with https:// — “' . self::clip($r['url'], 40)
                                   . '” did not, so nothing was saved.'];
            }
            if ($r['label'] === '') {
                return ['ok' => false, 'field' => 'link_label',
                        'message' => 'Every link needs a few words saying what it is. A donor will '
                                   . 'not press a bare web address.'];
            }
            $links[] = ['label' => self::clip($r['label'], self::MAX_LABEL), 'url' => $url];
        }
        if (count($links) > self::MAX_LINKS) {
            return ['ok' => false, 'field' => 'link_url',
                    'message' => 'That is more than ' . self::MAX_LINKS . ' links. A page of links is '
                               . 'a page nobody reads — keep the ones that build trust.'];
        }

        $videos = [];
        foreach (self::rows($in, ['url' => 'video_url', 'title' => 'video_title']) as $r) {
            if ($r['url'] === '') continue;
            $ref = self::videoRef($r['url']);
            if ($ref === null) {
                return ['ok' => false, 'field' => 'video_url',
                        'message' => 'We could not read a video out of “' . self::clip($r['url'], 40)
                                   . '”. Paste the address from YouTube or Vimeo — those are the two '
                                   . 'we can show. Anything else we would have to run somebody '
                                   . 'else’s code on your donation page, which we will not do.'];
            }
            $videos[] = $ref + ['title' => self::clip($r['title'], self::MAX_LABEL)];
        }
        if (count($videos) > self::MAX_VIDEOS) {
            return ['ok' => false, 'field' => 'video_url',
                    'message' => 'That is more than ' . self::MAX_VIDEOS . ' videos.'];
        }

        // Every two-field block off the same table that reads them, so the form field
        // names and the stored keys cannot drift apart.
        $blocks = [];
        foreach (self::BLOCKS as $name => $spec) {
            $blocks[$name] = self::blockFrom($in, $name, $spec);
        }

        $brand = [
            // The logo is written by uploadLogo(), never from this form: a path that arrived
            // in a text field is a path somebody chose, and this one addresses a file on our
            // disk.
            'logo'     => $current['logo'],
            'gallery'  => $current['gallery'],
            'accent'   => $accent,
            'tagline'  => $tagline,
            'story'    => $story,
            'website'  => $website,
            'links'    => $links,
            'videos'   => $videos,
            'sections' => $sections,
        ] + $blocks;

        $json = (string) json_encode($brand, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // ── THE COLUMN IS TEXT, WHICH IS 64KB ON MYSQL ──────────────────────
        //
        // Refused here rather than left to the database, because the two ways the database
        // handles it are both worse than a message. Strict mode throws and the organisation
        // sees "That could not be saved just now" for a problem they could have fixed;
        // non-strict mode — which shared hosting turns on — TRUNCATES, and a truncated JSON
        // document does not parse, so `of()` falls back to the house defaults and the whole
        // page silently reverts to unbranded. The second is the one that would take a week
        // to diagnose. See the note above MAX_LINKS.
        if (strlen($json) > self::MAX_JSON_BYTES) {
            return ['ok' => false,
                    'message' => 'There is more on your page than we can store — about '
                               . number_format(strlen($json) / 1024, 1) . 'KB against a limit of '
                               . number_format(self::MAX_JSON_BYTES / 1024) . 'KB. Shorten your story '
                               . 'or remove a few entries. Nothing was saved, so your page is '
                               . 'unchanged.'];
        }

        try {
            DB::table('gates_partner_orgs')->where('id', $orgId)->update([
                'brand_json' => $json,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'That could not be saved just now.'];
        }

        return ['ok' => true, 'message' => 'Saved. Your donation page has been updated.'];
    }

    /**
     * Zip parallel form arrays into rows, by index.
     *
     * @param array<string,string> $map  result key => form field name
     * @return list<array<string,string>>
     */
    private static function rows(array $in, array $map): array
    {
        $lists = [];
        $count = 0;
        foreach ($map as $key => $field) {
            $lists[$key] = array_values((array) ($in[$field] ?? []));
            $count = max($count, count($lists[$key]));
        }

        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $row = [];
            foreach ($map as $key => $_) {
                // Scalar-guarded: a crafted post can make any of these an array, and
                // `(string) []` is a warning plus "Array" rather than an error.
                $v = $lists[$key][$i] ?? '';
                $row[$key] = is_scalar($v) ? trim((string) $v) : '';
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * One block's rows, off the form.
     *
     * Field names are derived — `impact` reads `impact_figure[]` and `impact_label[]` —
     * so a block added to {@see BLOCKS} needs no change here and the form cannot name a
     * field this does not read.
     *
     * Unlike links and videos this does NOT refuse a half-filled row, and the asymmetry is
     * deliberate. A bad URL or an unreadable video address must be reported, because the
     * alternative is a dead link or a blank frame on their live page. A question typed with
     * no answer yet is an unfinished thought in a text box, and refusing the whole save for
     * it would throw away everything else they wrote in the same sitting.
     *
     * @param array{a:string, b:string, a_max:int, b_max:int, max:int, ...} $spec
     * @return list<array<string,string>>
     */
    private static function blockFrom(array $in, string $name, array $spec): array
    {
        $rows = self::rows($in, [
            $spec['a'] => $name . '_' . $spec['a'],
            $spec['b'] => $name . '_' . $spec['b'],
        ]);

        $out = [];
        foreach ($rows as $r) {
            $a = self::clip($r[$spec['a']], $spec['a_max']);
            $b = self::clip($r[$spec['b']], $spec['b_max']);
            if ($a === '' || $b === '') continue;

            $out[] = [$spec['a'] => $a, $spec['b'] => $b];
            if (count($out) >= $spec['max']) break;
        }
        return $out;
    }

    /**
     * Record a logo (or a gallery photograph) that has already been stored on disk.
     *
     * Takes a PATH rather than an upload, because the upload itself is the controller's
     * job and is already guarded there — the bytes are sniffed with finfo, re-encoded, and
     * written under the public uploads root. What this adds is that the path we KEEP is one
     * of ours: {@see safePath()} refuses anything that is not a relative path beneath
     * `uploads/`, so a stored value can never address a file outside it.
     *
     * @return array{ok:bool, message:string}
     */
    public static function attach(int $orgId, string $path, bool $gallery = false): array
    {
        $path = ltrim(trim($path), '/');
        if (!self::safePath($path)) {
            return ['ok' => false, 'message' => 'That file could not be attached.'];
        }

        $org = DB::table('gates_partner_orgs')->where('id', $orgId)->first();
        if (!$org) return ['ok' => false, 'message' => 'That organisation does not exist.'];

        $brand = self::of($org);
        $raw = json_decode((string) ($org->brand_json ?? ''), true);
        if (!is_array($raw)) $raw = [];

        if ($gallery) {
            $shots = $brand['gallery'];
            if (count($shots) >= 6) {
                return ['ok' => false, 'message' => 'You already have six photographs. '
                                                  . 'Remove one before adding another.'];
            }
            $shots[] = $path;
            $raw['gallery'] = $shots;
        } else {
            $raw['logo'] = $path;
        }

        try {
            DB::table('gates_partner_orgs')->where('id', $orgId)->update([
                'brand_json' => (string) json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'That could not be saved just now.'];
        }

        return ['ok' => true, 'message' => $gallery ? 'Photograph added.' : 'Logo updated.'];
    }

    /** Remove the logo, or one gallery photograph by its path. */
    public static function detach(int $orgId, string $path = ''): array
    {
        $org = DB::table('gates_partner_orgs')->where('id', $orgId)->first();
        if (!$org) return ['ok' => false, 'message' => 'That organisation does not exist.'];

        $raw = json_decode((string) ($org->brand_json ?? ''), true);
        if (!is_array($raw)) $raw = [];

        $path = ltrim(trim($path), '/');
        if ($path === '') {
            $raw['logo'] = '';
        } else {
            $raw['gallery'] = array_values(array_filter(
                (array) ($raw['gallery'] ?? []),
                static fn ($g): bool => ltrim(trim((string) $g), '/') !== $path));
        }

        try {
            DB::table('gates_partner_orgs')->where('id', $orgId)->update([
                'brand_json' => (string) json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            return ['ok' => false, 'message' => 'That could not be saved just now.'];
        }

        return ['ok' => true, 'message' => 'Removed.'];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // COLOUR
    // ═══════════════════════════════════════════════════════════════════════

    /** True when this accent can carry white text and be read as a link on white. */
    public static function readable(string $hex): bool
    {
        return self::contrast($hex, '#ffffff') >= self::MIN_CONTRAST;
    }

    /**
     * WCAG 2.2 relative-luminance contrast ratio, 1.0 to 21.0.
     *
     * The sRGB linearisation is the part people leave out, and leaving it out is not a
     * rounding difference: a naive (max+0.05)/(min+0.05) on raw channel values passes
     * mid-yellows that are genuinely unreadable, which is the exact case this guard exists
     * to catch.
     */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        if ($la < 0 || $lb < 0) return 0.0;

        $hi = max($la, $lb);
        $lo = min($la, $lb);
        return ($hi + 0.05) / ($lo + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $hex = self::normaliseHex($hex);
        if ($hex === '') return -1.0;

        $out = 0.0;
        [$r, $g, $b] = self::rgb($hex);
        foreach ([[$r, 0.2126], [$g, 0.7152], [$b, 0.0722]] as [$channel, $weight]) {
            $c = $channel / 255;
            $c = $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            $out += $c * $weight;
        }
        return $out;
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $hex): array
    {
        $hex = self::normaliseHex($hex);
        if ($hex === '') return [0, 0, 0];
        return [
            (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)),
            (int) hexdec(substr($hex, 5, 2)),
        ];
    }

    /** `#abc`, `abc`, `#AABBCC` and `aabbcc` all become `#aabbcc`. Anything else is ''. */
    public static function normaliseHex(string $raw): string
    {
        $v = strtolower(trim($raw));
        $v = ltrim($v, '#');

        if (preg_match('~^[0-9a-f]{3}$~', $v)) {
            $v = $v[0] . $v[0] . $v[1] . $v[1] . $v[2] . $v[2];
        }

        return preg_match('~^[0-9a-f]{6}$~', $v) ? '#' . $v : '';
    }

    // ═══════════════════════════════════════════════════════════════════════
    // SMALL GUARDS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * A stored image path must be one of ours.
     *
     * Relative, beneath `uploads/`, no traversal, no scheme. The upload path already decides
     * WHERE a file lands; this decides what we are willing to keep a pointer to, so a value
     * that reached the column some other way still cannot address `/etc/passwd` or an
     * off-site tracker dressed up as a logo.
     */
    public static function safePath(string $path): bool
    {
        $p = trim($path);
        if ($p === '') return false;
        if (str_contains($p, '..') || str_contains($p, '://') || str_starts_with($p, '/')) {
            return false;
        }
        return (bool) preg_match('~^uploads/[A-Za-z0-9._/-]+\.(jpe?g|png|webp|gif)$~i', $p);
    }

    /**
     * Turn an UploadService result into a path this class is willing to keep.
     *
     * ── THE CLOUDINARY TRAP ──────────────────────────────────────────────────
     *
     * `uploadImage()` returns `path` AND `local`. With remote media configured, `path` is an
     * `https://res.cloudinary.com/...` URL — and {@see safePath()} refuses anything with a
     * scheme in it, on purpose, because a stored value that can address an off-site host is
     * a logo slot that can hold somebody else's tracker.
     *
     * Reading `path` first would therefore mean that on every deployment with Cloudinary
     * turned on, uploading a logo would appear to succeed and attach nothing — a failure
     * that shows up on no configuration this repository's tests run under.
     *
     * `local` is written unconditionally, before any remote copy, and is always the relative
     * `uploads/...` path. It is the right answer here whatever the media configuration is.
     *
     * @param array<string,mixed> $upload
     */
    public static function pathFromUpload(array $upload): string
    {
        foreach (['local', 'path', 'url'] as $key) {
            $candidate = ltrim(trim((string) ($upload[$key] ?? '')), '/');
            if ($candidate !== '' && self::safePath($candidate)) return $candidate;
        }
        return '';
    }

    /** https only, and a real host. An http:// logo link on a payment page is a warning. */
    private static function url(string $raw): string
    {
        $v = trim($raw);
        if ($v === '') return '';
        if (!preg_match('~^https://~i', $v)) return '';
        return filter_var($v, FILTER_VALIDATE_URL) && strlen($v) <= 300 ? $v : '';
    }

    private static function clip(string $raw, int $max): string
    {
        $v = trim(preg_replace('~\s+~u', ' ', strip_tags($raw)) ?? '');
        return mb_substr($v, 0, $max);
    }

    /**
     * Like {@see clip()}, but keeps the paragraph breaks.
     *
     * `clip()` collapses every run of whitespace to one space, which is right for a link
     * label and wrong for four thousand characters of story: an organisation writing five
     * paragraphs about their work got one unbroken block, and the page that was supposed to
     * be theirs read like a terms-and-conditions notice. Nobody would report that as a bug
     * — it looks like a design choice — which is exactly why it survived.
     *
     * Tags are still stripped, so this widens what an organisation can SHAPE and not what
     * they can INJECT: the only structure that survives is a blank line, and the template
     * turns those into paragraphs itself. Runs of three or more newlines collapse to one
     * break, because a page cannot be padded into looking longer than it is.
     */
    private static function clipText(string $raw, int $max): string
    {
        $v = strip_tags($raw);
        $v = str_replace(["\r\n", "\r"], "\n", $v);
        // Trailing spaces first, or a line of only spaces defeats the blank-line collapse.
        $v = preg_replace('~[ \t]+~u', ' ', $v) ?? $v;
        $v = preg_replace('~ ?\n ?~u', "\n", $v) ?? $v;
        $v = preg_replace('~\n{3,}~u', "\n\n", $v) ?? $v;

        return mb_substr(trim($v), 0, $max);
    }

    /**
     * The story, split into paragraphs for the template.
     *
     * Done here rather than with a Twig `split` filter so there is one definition of what a
     * paragraph is. A template deciding that is how the donation page and any later surface
     * — a campaign page, a printed flier — come to disagree about the same organisation's
     * own words.
     *
     * @return list<string>
     */
    public static function paragraphs(string $story): array
    {
        $out = [];
        foreach (preg_split('~\n{2,}~u', $story) ?: [] as $p) {
            $p = trim((string) $p);
            if ($p !== '') $out[] = $p;
        }
        return $out;
    }
}
