<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use AfricaGates\Support\Slug;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The "vote for me" flier — a shareable graphic a nominee can post as-is.
 *
 * A nominee's whole job on this platform is to get their community to vote, and the
 * only tool they had was a URL. On the channels this actually happens through —
 * WhatsApp status, Instagram stories, a Facebook group — a bare link is the weakest
 * possible artefact: no image preview in a WhatsApp status, nothing to look at while
 * scrolling, and no statement of why now.
 *
 * ── TWO RENDERINGS, AND WHY BOTH ARE SERVER-SIDE ─────────────────────────────
 *
 * `svg()` for viewing, printing and vector download. `png()` for everything that needs
 * a raster: the download a nominee posts, the native share sheet, and — the reason it
 * has to exist at all — the **`og:image`**. WhatsApp, Facebook and X do not render SVG
 * in a link preview, and a crawler cannot run JavaScript, so a browser-side canvas
 * cannot be the OG image no matter how good it looks.
 *
 * An earlier version drew the PNG in the browser precisely to avoid GD's font problem.
 * That was defensible while the PNG was only a download; it stopped being defensible the
 * moment the graphic had to be fetchable by a crawler. It also produced a real bug: two
 * renderers of one design, and a monogram rule fixed in the SVG kept rendering the old
 * way on the canvas. One server-side pipeline removes that class of drift entirely.
 *
 * ── THE FONTS ────────────────────────────────────────────────────────────────
 *
 * `imagettftext()` needs a TrueType file on disk, and a shared cPanel host frequently
 * has none — so relying on the system is how the flier silently becomes DejaVu on the
 * deployment nobody can inspect. The site's own two faces are therefore committed under
 * `resources/fonts/`, as STATIC instances: Google Fonts now ships DM Sans and Playfair
 * Display only as variable fonts, GD cannot select a weight axis, and FreeType renders a
 * variable font's LIGHTEST instance — measured, the variable Montserrat this originally used came out Thin, which at 26px
 * is close to invisible. See that directory's README for the subsetting and the African
 * orthographies it was verified against.
 *
 * If a face is missing at runtime the renderer says so rather than guessing:
 * {@see fontsPresent()}, surfaced by `app:doctor`.
 *
 * ── WHAT IS ON IT ────────────────────────────────────────────────────────────
 *
 * The nominee, their category, their REAL standing ({@see StandingsService} — nothing
 * invented), one line of rally copy, and the vote URL. The standing is the reason the
 * flier works: "#3 of 24 — 12 votes from #2" is a reason to act today in a way that
 * "vote for me" is not.
 *
 * Every string is escaped for XML. A nominee name is public-submitted text and this
 * output is served as `image/svg+xml`, which browsers execute — an unescaped `<` here
 * is stored XSS with a content type that bypasses HTML sanitising entirely.
 */
final class FlierService
{
    /**
     * The GD primitives — text with tracking, measured wrapping, gradients, cover-crop,
     * remote photo loading. Extracted so the EVENT flier can draw with the same hands as the
     * nominee one: two renderers duplicating a cover-crop is how the two come to disagree
     * about where a face sits, and this file's own history is a list of that shape of bug.
     */
    use FlierRaster;

    /** Instagram/WhatsApp portrait. 4:5 is the largest feed crop on both. */
    public const W = 1080;
    public const H = 1350;

    /**
     * The panel height {@see \AfricaGates\Support\Media}'s `flier` preset requests.
     *
     * The panel is no longer one number: it is 1020 with a rank pill and 1120 without
     * one ({@see FlierLayout}). A Cloudinary preset can pin only one geometry, so it
     * asks for the TALLER — enough pixels for either, with the renderer cropping the
     * difference off the bottom. Requesting the shorter one would make every unranked
     * card an upscale, which is visible at 1080px wide.
     */
    public const PHOTO_H = FlierLayout::PANEL_H_UNRANKED;

    /**
     * The LINK-PREVIEW card. A second graphic, not a crop of the first.
     *
     * ── WHY THE FLIER CANNOT BE THE og:image ─────────────────────────────────
     *
     * It was, and it was the wrong shape. Facebook and LinkedIn crop an `og:image` to
     * 1.91:1 and WhatsApp to roughly square, so a 4:5 portrait loses its bottom third in
     * every one of them — and the flier's bottom third is the vote URL, the rally copy
     * and the jury footnote. The single most-shared surface on the platform was
     * previewing with the call to action cut off.
     *
     * So this is a horizontal split: the face in a 480px column, everything else in the
     * 720px beside it. Nothing is cropped away by the platforms because the aspect ratio
     * is already theirs.
     *
     * ── DESIGNED FOR ~380 PIXELS ─────────────────────────────────────────────
     *
     * A link preview in a WhatsApp thread renders about a third of native size, so only
     * three elements are sized to survive it: the NAME, the gold RANK CHIP, and the
     * STANDING line. Everything else (category, URL, the jury footnote) is deliberately
     * secondary — present for anyone who opens the image, not competing at thumbnail
     * size. Momentum is omitted entirely; a fourth number at 8 effective pixels is noise
     * that costs the other three their clarity.
     */
    public const OG_W = 1200;
    public const OG_H = 630;

    /** Width of the card's portrait column. Media's `og_photo` preset must match it. */
    public const OG_PHOTO_W = FlierLayout::OG_PHOTO_W;

    /**
     * The vertical band the card's middle block is centred in — below the kicker rail,
     * above the URL pill. Named constants because the block's position is DERIVED from
     * them at render time, and three bare numbers would have to be kept in agreement by
     * hand every time an element moved.
     */
    private const OG_BAND_TOP    = 152;
    private const OG_BAND_BOTTOM = 500;

    /** Rally copy is cached per nominee — it changes with the standing, not the view. */
    public const COPY_TTL = 1800;

    public function __construct(
        private readonly StandingsService $standings = new StandingsService(),
    ) {}

    /**
     * Everything the flier needs, or null when the nominee is not voteable.
     *
     * @return array{
     *   id:int, name:string, tagline:string, category:string, programme:string,
     *   country:string, photo:?string, votes:int, standing:array,
     *   headline:string, cta:string, rally:string, url:string, short_url:string, ai:bool
     * }|null
     */
    public function forNominee(int $nomineeId): ?array
    {
        try {
            $n = DB::table('gates_nominees as n')
                ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->join('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
                ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
                ->where('n.id', $nomineeId)
                ->whereIn('n.status', ['approved', 'winner', 'runner_up'])
                ->whereNull('n.merged_into')
                // `organisation` only when the column exists — the flier is also an
                // og:image fetched by crawlers, and a 500 here is a broken link preview
                // on every share. See VoteController::nominee() for the same guard.
                ->first(array_merge([
                    'n.id', 'n.name', 'n.tagline', 'n.photo_path', 'n.country_code', 'n.vote_count',
                    'c.id as category_id', 'c.title as category', 'p.title as programme', 'p.slug as programme_slug',
                ], \AfricaGates\Support\OptionalColumn::on('gates_nominees', 'organisation') ? ['n.organisation'] : []));
        } catch (\Throwable) {
            return null;
        }
        if ($n === null) return null;

        $standing = $this->standings->forNominee((int) $n->id, (int) $n->category_id);
        $base     = rtrim((string) Env::get('APP_URL', ''), '/') ?: 'https://afg.afrovanguard.org.ng';
        // Shared with VoteController::nomineeUrl() through Slug, so the URL printed on
        // the flier is byte-identical to the one the ballot links to. Two expressions
        // producing "nearly the same" slug is how a printed URL stops matching the page.
        $path     = '/vote/' . $n->programme_slug . '/' . Slug::idSegment((int) $n->id, (string) $n->name);

        [$rally, $usedAi] = $this->rallyCopy(
            (string) $n->name,
            (string) $n->category,
            StandingsService::headline($standing),
        );

        return [
            'id'        => (int) $n->id,
            'name'      => (string) $n->name,
            'tagline'   => (string) ($n->tagline ?? ''),
            'category'  => (string) $n->category,
            'programme' => (string) $n->programme,
            'country'   => strtoupper(trim((string) ($n->country_code ?? ''))),
            'organisation' => trim((string) ($n->organisation ?? '')),
            'photo'     => $this->photoUrl((string) ($n->photo_path ?? ''), $base, 'flier'),
            // The same photo, cropped for the card's tall column. TWO derivatives rather
            // than one reused, because the flier's panel is landscape and the card's is
            // portrait — reusing either would mean cropping an already-cropped image and
            // throwing away the face anchoring that is the whole point.
            'photo_card' => $this->photoUrl((string) ($n->photo_path ?? ''), $base, 'og_photo'),
            'votes'     => (int) $n->vote_count,
            'standing'  => $standing,
            'headline'  => StandingsService::headline($standing),
            'cta'       => StandingsService::callToAction($standing),
            'rally'     => $rally,
            'url'       => $base . $path,
            // What goes ON the graphic, and it has to FIT. The pill at the bottom is a
            // fixed-width rounded rect with centred text and no wrapping, so an
            // overlong URL runs straight out of it — visible on the first real render,
            // where `host/vote/prog-1/48-nominee-48-surname` already reached the edge
            // at 30px and a production domain plus a three-word name would clear it.
            //
            // The name segment is decoration: `/vote/{programme}/{id}` resolves on its
            // own (the route only requires a leading digit). So the long form is used
            // when it fits and the id-only form when it does not — shorter AND easier
            // to type from a phone screen, which is how someone reads it off a flier.
            'short_url' => $this->displayUrl($base, (string) $n->programme_slug, (int) $n->id, $path),
            'ai'        => $usedAi,
        ];
    }

    /**
     * One line of rally copy, AI-written when available.
     *
     * The fallback is not an error state — it is a written line that is genuinely
     * usable, because a nominee pressing "download" must never get a graphic with an
     * apology on it. Cached against the STANDING as well as the nominee, so the copy
     * refreshes when the position moves and not on every page view.
     *
     * @return array{0:string,1:bool}
     */
    private function rallyCopy(string $name, string $category, string $headline): array
    {
        $fallback = $this->writtenFallback($category, $headline);

        $key = 'flier:copy:' . substr(hash('sha256', $name . '|' . $category . '|' . $headline), 0, 24);
        $cache = new CacheService();
        try {
            $hit = $cache->remember($key, self::COPY_TTL, function () use ($name, $category, $headline): array {
                $r = (new AiGateway())->run('nominee.rally_copy', [
                    'system' => 'You write ONE line of rally copy for a shareable graphic, in the voice of the '
                        . "nominee's supporter, for an African cultural-excellence award. "
                        . 'Rules: 12 to 22 words. No hashtags, no emoji, no quotation marks, no exclamation marks. '
                        . 'Do NOT claim they have won or will win. Do NOT invent numbers, dates or achievements. '
                        . 'Refer to the standing only if it is given. Plain, warm, specific. '
                        . 'Reply with the line and nothing else.',
                    'trusted' => 'Category: ' . $category . '. Current standing: ' . $headline . '.',
                    'user'    => 'Nominee: ' . $name,
                    // Validated, not trusted. A model that returns a paragraph, a
                    // hashtag storm or an empty string is REJECTED rather than clamped,
                    // because a half-usable line on a graphic someone posts under their
                    // own name is worse than the written fallback.
                    'schema'  => static function (string $raw): ?string {
                        $line = trim(preg_replace('/\s+/', ' ', strip_tags($raw)) ?? '');
                        $line = trim($line, " \t\n\r\0\x0B\"'“”");
                        if ($line === '') return null;
                        if (str_contains($line, '#')) return null;              // hashtags
                        if (preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $line)) return null;
                        $words = str_word_count($line, 0, "0123456789'-");
                        if ($words < 8 || $words > 30) return null;
                        // A model asked not to claim a win frequently does anyway.
                        if (preg_match('/\b(has won|will win|winner of|champion of)\b/i', $line)) return null;
                        return mb_substr($line, 0, 180);
                    },
                ]);
                return $r->ok && is_string($r->value) ? ['copy' => $r->value, 'ai' => true] : ['copy' => null, 'ai' => false];
            }, ['leaderboard']);
        } catch (\Throwable) {
            return [$fallback, false];
        }

        $copy = is_array($hit) ? ($hit['copy'] ?? null) : null;
        return is_string($copy) && $copy !== ''
            ? [$copy, (bool) ($hit['ai'] ?? false)]
            : [$fallback, false];
    }

    /**
     * The no-AI line. Written to be shareable on its own, not to be a placeholder.
     *
     * Varies with the standing because a single sentence used for a leader and for
     * someone twelve votes off the pace is wrong for both.
     */
    private function writtenFallback(string $category, string $headline): string
    {
        return match (true) {
            str_starts_with($headline, 'Leading') =>
                'Holding the lead in ' . $category . '. Every vote keeps it there — cast yours today.',
            str_contains($headline, 'Level') =>
                'Level on votes in ' . $category . '. The next vote breaks the tie, and it could be yours.',
            str_contains($headline, 'from #') =>
                'The gap in ' . $category . ' is closing. Add your vote and help finish the climb.',
            default =>
                'Standing for recognition in ' . $category . '. One vote from you moves this forward.',
        };
    }

    /**
     * Longest form of the vote URL that still fits the flier's pill.
     *
     * The pill is 952px wide with 22px of padding each side; at 30px Montserrat Bold a
     * character averages just under 19px, which puts the ceiling around 46 characters.
     * SVG cannot measure text server-side, so the budget is a character count with the
     * font size fixed — the honest version of the trade, rather than a guess that looks
     * fine on the one name it was tested with.
     */
    private const URL_BUDGET = 46;

    private function displayUrl(string $base, string $programmeSlug, int $id, string $path): string
    {
        $host = (string) preg_replace('~^https?://~', '', $base);
        $long = $host . $path;
        if (mb_strlen($long) <= self::URL_BUDGET) return $long;

        $short = $host . '/vote/' . $programmeSlug . '/' . $id;
        if (mb_strlen($short) <= self::URL_BUDGET) return $short;

        // A very long host with a very long programme slug. Truncating the URL would
        // make it wrong, so the host alone is shown — still correct, still reachable,
        // and the page's caption and share text carry the full link.
        return $host;
    }

    /**
     * Absolute URL for the nominee photo, or null when there is none.
     *
     * ── WHY THIS ASKS FOR A DERIVATIVE AND NOT THE ORIGINAL ──────────────────
     *
     * The photo panel is 1080×820 — a 4:3 landscape box — and a submitted portrait is
     * almost always taller than it is wide, with the face in the upper third. Both
     * renderers fill that box by cropping, and both used to crop from the CENTRE, which
     * on a waist-up photo lands squarely on the subject's chest. The result is a flier
     * with no face on it, which for a share graphic is not a cosmetic defect: the face
     * is the entire reason anyone stops scrolling, and the failure looks like a working
     * render, so nobody reports it.
     *
     * When the photo is on Cloudinary, `Media`'s `flier` preset asks for
     * `c_fill,g_faces:auto` at exactly the panel's dimensions — face-detected crop,
     * falling back to saliency detection when no face is found. The image that arrives
     * is already the right shape, so {@see cover()} has nothing left to crop and cannot
     * reintroduce the problem.
     *
     * A local photo gets no face detection — GD has none, and this runs on shared
     * hosting where adding OpenCV is not on the table — so {@see cover()} applies a
     * documented upper bias instead. That is a real improvement on centre and honestly
     * not a substitute for detection, which is the strongest argument for migrating the
     * existing photos.
     */
    private function photoUrl(string $path, string $base, string $preset): ?string
    {
        $p = trim($path);
        if ($p === '') return null;

        return \AfricaGates\Support\Media::absolute($p, $preset, $base);
    }

    /**
     * The flier as SVG.
     *
     * Hand-built rather than templated because the geometry and the escaping have to be
     * reasoned about together: this is served as `image/svg+xml`, a content type the
     * browser EXECUTES, so a nominee name carrying `<` is stored XSS that no HTML
     * sanitiser sees. Every interpolation goes through {@see x()}.
     *
     * The photo is referenced by URL rather than embedded. Embedding would make the
     * file self-contained, at the cost of base64-ing an image on every request; a
     * same-origin URL resolves for anyone viewing it in a browser, and the PNG path —
     * which is what gets posted — draws the photo itself.
     */
    public function svg(array $f): string
    {
        $W = self::W; $H = self::H;
        $L = FlierLayout::for($f);
        $name = (string) $f['name'];

        $PH   = (int) $L['panelH'];
        $sans = 'DM Sans, system-ui, sans-serif';
        $serif= 'Playfair Display, Georgia, serif';

        $out  = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" ';
        $out .= 'width="' . $W . '" height="' . $H . '" viewBox="0 0 ' . $W . ' ' . $H . '" ';
        // A title and description make the graphic itself accessible — a screen reader
        // opening the SVG directly, and any surface that embeds it, get a real name
        // instead of "image".
        $out .= 'role="img" aria-labelledby="fTitle fDesc">';
        $out .= '<title id="fTitle">' . $this->x('Vote for ' . $name . ' — ' . $f['category']) . '</title>';
        // The rally line lives HERE now rather than on the face of the card. The new
        // design gives the bottom third to the vote pill and the jury note, and a
        // paragraph of persuasion competing with them made the card worse — but the
        // sentence is still the best available description of the graphic, and this is
        // what a screen reader and a share-sheet caption read.
        $out .= '<desc id="fDesc">' . $this->x($f['headline'] . '. ' . $f['rally'] . ' Vote at ' . $L['url']) . '</desc>';

        $out .= '<defs>';
        // Two stops, deepening downward. A flier is read at thumbnail size on a phone;
        // a busier gradient only muddies the type over it.
        $out .= '<linearGradient id="bg" x1="0" y1="0" x2="0" y2="1">'
              . '<stop offset="0" stop-color="' . FlierLayout::C_BG_TOP . '"/>'
              . '<stop offset="1" stop-color="' . FlierLayout::C_BG_BOTTOM . '"/></linearGradient>';
        // The scrim's colour is the PAGE GRADIENT'S VALUE AT THE PANEL BASE, not the
        // panel's own colour. That is what makes the seam invisible: the photo fades
        // into exactly the tone the background already is at that scanline, so there is
        // no band where the panel ends.
        $out .= '<linearGradient id="scrim" x1="0" y1="0" x2="0" y2="1">'
              . '<stop offset="0" stop-color="' . FlierLayout::C_SCRIM . '" stop-opacity="0"/>'
              . '<stop offset="0.40" stop-color="' . FlierLayout::C_SCRIM . '" stop-opacity="0.55"/>'
              . '<stop offset="0.72" stop-color="' . FlierLayout::C_SCRIM . '" stop-opacity="0.92"/>'
              . '<stop offset="1" stop-color="' . FlierLayout::C_SCRIM . '" stop-opacity="1"/></linearGradient>';
        // Top scrim — see FlierLayout::TOP_SCRIM_H. Without it the white kicker
        // vanishes on a photo with a pale background.
        // Sampled from the SAME `1 - u²` curve the raster uses, as intermediate stops —
        // SVG cannot express the easing directly, and a plain two-stop ramp leaves a
        // visible seam where the fade begins.
        $out .= '<linearGradient id="topscrim" x1="0" y1="0" x2="0" y2="1">'
              . '<stop offset="0" stop-color="' . FlierLayout::C_SCRIM . '" stop-opacity="' . FlierLayout::TOP_SCRIM_OP . '"/>';
        $hold = FlierLayout::TOP_SCRIM_HOLD;
        foreach ([0.0, 0.35, 0.6, 0.8, 1.0] as $u) {
            $out .= '<stop offset="' . round($hold + $u * (1 - $hold), 4) . '" '
                  . 'stop-color="' . FlierLayout::C_SCRIM . '" '
                  . 'stop-opacity="' . round(FlierLayout::TOP_SCRIM_OP * (1 - $u * $u), 4) . '"/>';
        }
        $out .= '</linearGradient>';
        $out .= '<clipPath id="photoClip"><rect x="0" y="0" width="' . $W . '" height="' . $PH . '"/></clipPath>';
        $out .= '</defs>';

        $out .= '<rect width="' . $W . '" height="' . $H . '" fill="url(#bg)"/>';

        // Photo, or a monogram when there is none. A blank rectangle where a face
        // should be is the difference between a flier someone posts and one they do not.
        // `xMidYMin`, not `xMidYMid`: a centre crop of a portrait lands on the chest.
        // A Cloudinary photo arrives already cropped to this box, so the rule only
        // applies to a local original.
        $out .= '<rect x="0" y="0" width="' . $W . '" height="' . $PH . '" fill="' . FlierLayout::C_PANEL . '"/>';
        if ($f['photo'] !== null) {
            $out .= '<g clip-path="url(#photoClip)">'
                  . '<image href="' . $this->x($f['photo']) . '" xlink:href="' . $this->x($f['photo']) . '" '
                  . 'x="0" y="0" width="' . $W . '" height="' . $PH . '" preserveAspectRatio="xMidYMin slice"/>'
                  . '</g>';
        } else {
            // 400px on a 400px line box, top 150 — a deliberate slab, not a watermark.
            // The previous 28%-opacity version read as a rendering fault at thumbnail
            // size; at full strength it is a graphic choice.
            $out .= '<text x="' . ($W / 2) . '" y="' . (150 + 320) . '" text-anchor="middle" '
                  . 'font-family="' . $serif . '" font-size="400" font-weight="700" '
                  . 'letter-spacing="8" fill="' . FlierLayout::C_MONOGRAM . '">'
                  . $this->x($L['monogram']) . '</text>';
        }
        $out .= '<rect x="0" y="' . $L['scrimTop'] . '" width="' . $W . '" height="' . FlierLayout::SCRIM_H . '" fill="url(#scrim)"/>';
        // Only over a real photo. The monogram panel is already dark enough, and
        // darkening it further would just band the top of a flat colour.
        if ($f['photo'] !== null) {
            $out .= '<rect x="0" y="0" width="' . $W . '" height="' . FlierLayout::TOP_SCRIM_H . '" fill="url(#topscrim)"/>';
        }

        // Kicker lockup — gold rail, VOTE NOW, the year line.
        $out .= '<rect x="64" y="60" width="6" height="82" fill="' . FlierLayout::C_GOLD . '"/>';
        $out .= '<text x="90" y="96" font-family="' . $sans . '" font-size="27" font-weight="700" '
              . 'letter-spacing="6.5" fill="' . FlierLayout::C_GOLD . '">VOTE NOW</text>';
        $out .= '<text x="90" y="134" font-family="' . $sans . '" font-size="21" font-weight="600" '
              . 'letter-spacing="2.1" fill="' . FlierLayout::C_MIST . '">' . $this->x(mb_strtoupper((string) $f['programme'])) . '</text>';

        // Rank pill, top-right where the eye lands after the face. Dropped entirely
        // when the category has fewer than two nominees — "#1 of 1" is not a standing.
        if ($L['showRank']) {
            $head = '#' . $L['rank'];
            $tail = ' of ' . $L['fieldSize'];
            $cw   = 60 + (mb_strlen($head) * 22) + (mb_strlen($tail) * 13);
            $cx   = $W - 64 - $cw;
            $out .= '<rect x="' . $cx . '" y="60" width="' . $cw . '" height="62" rx="31" fill="' . FlierLayout::C_GOLD . '"/>';
            $out .= '<text x="' . ($cx + $cw / 2) . '" y="103" text-anchor="middle" fill="' . FlierLayout::C_ON_GOLD . '">'
                  . '<tspan font-family="' . $serif . '" font-size="36" font-weight="700">' . $this->x($head) . '</tspan>'
                  . '<tspan font-family="' . $sans . '" font-size="24" font-weight="700">' . $this->x($tail) . '</tspan>'
                  . '</text>';
        }

        // NAME — bottom-anchored. The block grows upward from panelH − 108, so a long
        // two-line name pushes into the photo rather than down into the category line.
        $lh = FlierLayout::NAME_LINE_H * $L['nameSize'];
        $y  = $L['nameTop'] + FlierLayout::NAME_PAD + $lh * 0.78;   // 0.78 ≈ cap-height baseline
        foreach ($L['nameLines'] as $line) {
            $out .= '<text x="64" y="' . round($y, 1) . '" font-family="' . $serif . '" '
                  . 'font-size="' . $L['nameSize'] . '" font-weight="700" fill="' . FlierLayout::C_WHITE . '">'
                  . $this->x($line) . '</text>';
            $y += $lh;
        }

        // Category · country, on the panel's own baseline.
        $out .= '<text x="64" y="' . ($L['catTop'] + 26) . '" font-family="' . $sans . '" font-size="30" font-weight="600">'
              . '<tspan fill="' . FlierLayout::C_MIST . '">' . $this->x($L['category']) . '</tspan>';
        if ($L['countryCode'] !== '') {
            $out .= '<tspan fill="' . FlierLayout::C_LEAF . '"> · </tspan>'
                  . '<tspan fill="' . FlierLayout::C_MUTED . '" letter-spacing="3">' . $this->x($L['countryCode']) . '</tspan>';
        }
        $out .= '</text>';

        // The school / organisation, when there is one.
        if ($L['organisation'] !== '') {
            $out .= '<text x="64" y="' . ($L['orgTop'] + 22) . '" font-family="' . $sans . '" font-size="26" '
                  . 'fill="' . FlierLayout::C_MUTED . '">' . $this->x($L['organisation']) . '</text>';
        }

        // Standing line — three clauses, each present only when it says something.
        if ($L['showStanding']) {
            $out .= '<text x="64" y="' . (FlierLayout::STANDING_Y + 26) . '" font-family="' . $sans . '" font-size="31" font-weight="700">';
            if ($L['gapText'] !== '')  $out .= '<tspan fill="' . FlierLayout::C_WHITE . '">' . $this->x($L['gapText']) . '</tspan>';
            if ($L['leadText'] !== '') $out .= '<tspan fill="' . FlierLayout::C_LEAF . '">' . $this->x($L['leadText']) . '</tspan>';
            if ($L['showMiddot'])      $out .= '<tspan fill="' . FlierLayout::C_DEEP . '">  ·  </tspan>';
            if ($L['momText'] !== '')  $out .= '<tspan fill="' . FlierLayout::C_GOLD . '">' . $this->x($L['momText']) . '</tspan>';
            $out .= '</text>';
        }

        // The vote pill and the footnote are FIXED in every state, so the card always
        // ends the same way whatever was dropped above them.
        $out .= '<rect x="64" y="' . FlierLayout::PILL_Y . '" width="952" height="' . FlierLayout::PILL_H . '" rx="56" fill="' . FlierLayout::C_WHITE . '"/>';
        $out .= '<text x="' . ($W / 2) . '" y="' . (FlierLayout::PILL_Y + 70) . '" text-anchor="middle" '
              . 'font-family="' . $sans . '" font-size="' . $L['urlSize'] . '" font-weight="700" '
              . 'letter-spacing="-0.4" fill="' . FlierLayout::C_ON_WHITE . '">' . $this->x($L['url']) . '</text>';

        // A rank on a graphic reads as a result, and this platform's award is decided
        // largely by an independent jury. Omitting that turns a share card into a claim.
        $out .= '<text x="' . ($W / 2) . '" y="' . (FlierLayout::FOOTNOTE_Y + 22) . '" text-anchor="middle" '
              . 'font-family="' . $sans . '" font-size="22" fill="' . FlierLayout::C_MUTED . '">'
              . $this->x(FlierLayout::FOOTNOTE) . '</text>';

        return $out . '</svg>';
    }

    // ── The raster ───────────────────────────────────────────────────────────

    /** The bundled faces, by role. Paths resolved once in {@see font()}. */
    private const FONTS = [
        'display'  => 'PlayfairDisplay-Bold.ttf',   // the name
        'bold'     => 'DMSans-Bold.ttf',            // kicker, chip, URL
        'semibold' => 'DMSans-SemiBold.ttf',        // the standing line
        'regular'  => 'DMSans-Regular.ttf',         // everything else
        // Micro-labels — a date, a venue, a domain. The house style reserves mono for
        // metadata, which is what keeps a date from reading as a sentence.
        'mono'     => 'AGMono-Bold.ttf',
    ];

    /**
     * Absolute path to a bundled face, for anything else on the platform that rasterises
     * type. Public so the event flier draws in the same four faces rather than repeating the
     * FONTS map — two copies of a filename list is how one graphic silently falls back to
     * GD's built-in bitmap font while the other stays correct.
     */
    public static function fontPath(string $role): string
    {
        return self::font($role);
    }

    /** Absolute path to a bundled face. */
    private static function font(string $role): string
    {
        return dirname(__DIR__, 2) . '/resources/fonts/' . (self::FONTS[$role] ?? self::FONTS['regular']);
    }

    /**
     * Are all four faces readable? Reported by `app:doctor`.
     *
     * @return array{ok:bool, missing:list<string>}
     */
    public static function fontsPresent(): array
    {
        $missing = [];
        foreach (self::FONTS as $role => $file) {
            if (!is_readable(self::font($role))) $missing[] = $file;
        }
        return ['ok' => $missing === [], 'missing' => $missing];
    }

    /**
     * The flier as a PNG, rendered by GD.
     *
     * Deliberately a line-for-line mirror of {@see svg()}: same geometry constants, same
     * ordering, same suppression rules. They are two encodings of one design, and the
     * only honest way to keep them identical is to write them beside each other and pin
     * the shared constants in a test — which is what happened after a monogram rule was
     * fixed in one and not the other.
     *
     * Returns null when GD or a font is unavailable, so the caller can fall back to the
     * SVG rather than serve a broken image.
     */
    public function png(array $f): ?string
    {
        if (!function_exists('imagecreatetruecolor') || !self::fontsPresent()['ok']) {
            return null;
        }

        $W = self::W; $H = self::H;
        $L  = FlierLayout::for($f);
        $PH = (int) $L['panelH'];

        $im = imagecreatetruecolor($W, $H);
        // No alpha: an OG image is composited on an unknown background by every client,
        // and a transparent flier renders differently in each one.
        imagealphablending($im, true);

        // Allocated FROM THE LAYOUT'S palette rather than re-typed here, so a colour
        // cannot be right in the SVG and stale in the raster — which is the exact class
        // of drift this pair of renderers has produced before.
        $c = static function (string $hex) use ($im): int {
            [$r, $g, $b] = FlierLayout::rgb($hex);
            return (int) imagecolorallocate($im, $r, $g, $b);
        };
        $white   = $c(FlierLayout::C_WHITE);
        $leaf    = $c(FlierLayout::C_LEAF);
        $deep    = $c(FlierLayout::C_DEEP);
        $gold    = $c(FlierLayout::C_GOLD);
        $goldInk = $c(FlierLayout::C_ON_GOLD);
        $mist    = $c(FlierLayout::C_MIST);
        $muted   = $c(FlierLayout::C_MUTED);
        $onWhite = $c(FlierLayout::C_ON_WHITE);

        $bgTop = FlierLayout::rgb(FlierLayout::C_BG_TOP);
        $bgBot = FlierLayout::rgb(FlierLayout::C_BG_BOTTOM);
        $this->vGradient($im, 0, 0, $W, $H, $bgTop, $bgBot);

        // Photo, or the monogram. Same fallback as the SVG, and the same reason: a blank
        // rectangle where a face should be is the difference between a flier someone
        // posts and one they do not.
        $photo = $f['photo'] !== null ? $this->loadPhoto((string) $f['photo']) : null;
        if ($photo !== null) {
            $this->cover($im, $photo, 0, 0, $W, $PH);
            imagedestroy($photo);
        } else {
            // $PH - 1, not $PH. imagefilledrectangle() is INCLUSIVE of both corners while
            // the scrim loop runs rows y..y+h-1, so filling to $PH leaves the panel's last
            // row painted and never darkened — a one-pixel bright line straight across the
            // card. It reads as a rendering fault, which on a graphic a nominee posts is
            // the whole impression.
            imagefilledrectangle($im, 0, 0, $W, $PH - 1, $c(FlierLayout::C_PANEL));
            // The spec's band is y 150..550. Filled by MEASURED ink rather than by a
            // baseline derived from the point size — see centredInBand(), and the
            // collision with the VOTE NOW lockup that made the difference visible.
            $this->centredInBand($im, (string) $L['monogram'], 400, self::font('display'),
                $c(FlierLayout::C_MONOGRAM), $W / 2, 150, 400);
        }
        // The scrim keeps text legible over ANY photo, which is the one thing that
        // reliably ruins a generated graphic.
        //
        // Its terminal colour is the PAGE GRADIENT'S value at the panel base, not the
        // gradient's end colour. Using the latter left a visible horizontal seam — the
        // scrim finished nearly opaque on the end colour while the background at that
        // line was several levels lighter, a step straight across the card.
        $this->scrim($im, (int) $L['scrimTop'], FlierLayout::SCRIM_H, $W,
            $this->gradientAt($bgTop, $bgBot, $PH, $H));

        // The TOP scrim, over a photo only. Without it the white kicker disappears on
        // a portrait shot against a pale background — see FlierLayout::TOP_SCRIM_H.
        if ($photo !== null) {
            $this->topScrim($im, $W, FlierLayout::TOP_SCRIM_H, FlierLayout::rgb(FlierLayout::C_SCRIM));
        }

        // The rank pill is measured and drawn BEFORE the kicker's second line, because
        // that line has to be shortened to clear it. Drawing them the other way round put
        // the programme title straight underneath the gold pill — two strings overlapping
        // in the corner the eye reaches first, which the card's first render showed plainly.
        $chipW = 0.0;
        if ($L['showRank']) {
            $head  = '#' . $L['rank'];
            $tail  = ' of ' . $L['fieldSize'];
            $wHead = $this->width($head, 36, self::font('display'));
            $wTail = $this->width($tail, 24, self::font('bold'));
            $chipW = $wHead + $wTail + 60;
            $cx    = $W - 64 - $chipW;
            $this->pill($im, $cx, 60, $chipW, 62, $gold);
            // Two sizes on ONE baseline, laid out left to right from measured widths.
            // Centring each half separately would leave an uneven gap at one end.
            $x0 = $cx + ($chipW - ($wHead + $wTail)) / 2;
            $this->text($im, $head, 36, self::font('display'), $goldInk, $x0, 103);
            $this->text($im, $tail, 24, self::font('bold'), $goldInk, $x0 + $wHead, 103);
        }

        imagefilledrectangle($im, 64, 60, 69, 141, $gold);
        $this->text($im, 'VOTE NOW', 27, self::font('bold'), $gold, 90, 96, 6.5);
        // wrapMeasured has no concept of letter-spacing, and this line carries 2.1px of
        // it — so its real width is the measured width plus that per character.
        // Subtracting it from the budget rather than hoping the right margin absorbs it
        // is what keeps a longer programme title out from under the pill.
        $prog   = mb_strtoupper((string) $f['programme']);
        $progW  = max(160.0, $W - 90 - 64 - $chipW - 28 - (mb_strlen($prog) * 2.1));
        $progLn = $this->wrapMeasured($prog, $progW, 21, self::font('semibold'), 1);
        $this->text($im, $progLn[0] ?? '', 21, self::font('semibold'), $mist, 90, 134, 2.1);

        // NAME — bottom-anchored, and MEASURED rather than trusted.
        //
        // The layout's ladder is what the SVG must use, because SVG cannot measure text.
        // The raster can, and the ladder has a failure the first render exposed:
        // `wrapMeasured` never breaks a word, so a single name element wider than the
        // column at the chosen size runs off the edge — `Wolde-Giorgis` is 13 characters
        // and clears 952px at 96pt. So the ladder is the STARTING size and fitLines()
        // steps down until it genuinely fits, rather than ellipsising a name it could
        // have set smaller. The two renderers agree except where the metrics say the
        // ladder would overflow, and the raster is the one that gets posted.
        $startSize = (int) $L['nameSize'];
        [$size, $lines] = $this->fitLines((string) $f['name'], $W - 128, $startSize, 40, self::font('display'), 2);
        $lh = FlierLayout::NAME_LINE_H * $size;
        // Re-anchored to the MEASURED size, so a name that had to shrink still ends on
        // the spec's fixed bottom edge instead of floating above it.
        $y = ($L['nameBottom'] - (FlierLayout::NAME_PAD + count($lines) * $lh))
           + FlierLayout::NAME_PAD + $lh * 0.78;
        foreach ($lines as $line) {
            $this->text($im, $line, $size, self::font('display'), $white, 64, $y);
            $y += $lh;
        }

        // Category · country, coloured per element rather than as one string.
        $catY = $L['catTop'] + 26;
        $this->text($im, (string) $L['category'], 30, self::font('semibold'), $mist, 64, $catY);
        if ($L['countryCode'] !== '') {
            $x = 64 + $this->width((string) $L['category'], 30, self::font('semibold'));
            $this->text($im, '  ·  ', 30, self::font('semibold'), $leaf, $x, $catY);
            $x += $this->width('  ·  ', 30, self::font('semibold'));
            $this->text($im, (string) $L['countryCode'], 30, self::font('semibold'), $muted, $x, $catY, 3);
        }

        // The school / organisation, shrunk to the column rather than trusted: an
        // institution name is frequently longer than a person's.
        if ($L['organisation'] !== '') {
            $this->centredFitLeft($im, (string) $L['organisation'], 26, self::font('regular'), $muted,
                64, $L['orgTop'] + 22, $W - 128);
        }

        // Standing line — three clauses, each drawn only when it says something, laid out
        // left to right from measured widths so the middot lands between them.
        if ($L['showStanding']) {
            $x  = 64.0;
            $sy = FlierLayout::STANDING_Y + 26;
            foreach ([[(string) $L['gapText'], $white], [(string) $L['leadText'], $leaf]] as [$txt, $col]) {
                if ($txt === '') continue;
                $this->text($im, $txt, 31, self::font('bold'), $col, $x, $sy);
                $x += $this->width($txt, 31, self::font('bold'));
            }
            if ($L['showMiddot']) {
                $this->text($im, '  ·  ', 31, self::font('bold'), $deep, $x, $sy);
                $x += $this->width('  ·  ', 31, self::font('bold'));
            }
            if ($L['momText'] !== '') {
                $this->text($im, (string) $L['momText'], 31, self::font('bold'), $gold, $x, $sy);
            }
        }

        // Fixed in every state, so the card always ends the same way whatever was
        // dropped above.
        $this->pill($im, 64, FlierLayout::PILL_Y, 952, FlierLayout::PILL_H, $white);
        $this->centredFit($im, (string) $L['url'], (int) $L['urlSize'], self::font('bold'), $onWhite,
            $W / 2, FlierLayout::PILL_Y + 70, 880);
        // Shrunk to fit rather than trusted: at its nominal size this sentence is wider
        // than the card and ran off both edges. The SVG estimates it as fitting because
        // it cannot measure — which is exactly why the raster does.
        $this->centredFit($im, FlierLayout::FOOTNOTE, 22, self::font('regular'), $muted,
            $W / 2, FlierLayout::FOOTNOTE_Y + 22, $W - 96);

        ob_start();
        imagepng($im, null, 6);
        $out = (string) ob_get_clean();
        imagedestroy($im);

        return $out !== '' ? $out : null;
    }

    /**
     * The 1200×630 link-preview card, as a PNG.
     *
     * Shares every helper, colour and suppression rule with {@see png()} — same brand
     * palette, same bundled faces, same "omit rather than print a zero" discipline — but
     * it is a DIFFERENT LAYOUT, because 1.91:1 has no room for a full-bleed portrait with
     * a text stack over it. See the note on {@see OG_W} for why it exists at all.
     *
     * Returns null when GD or a font is unavailable, so the caller can fall back rather
     * than serve a broken image — same contract as png().
     */
    public function ogCard(array $f): ?string
    {
        if (!function_exists('imagecreatetruecolor') || !self::fontsPresent()['ok']) {
            return null;
        }

        $W = self::OG_W; $H = self::OG_H; $PW = self::OG_PHOTO_W;
        $L = FlierLayout::for($f);

        $im = imagecreatetruecolor($W, $H);
        imagealphablending($im, true);

        $c = static function (string $hex) use ($im): int {
            [$r, $g, $b] = FlierLayout::rgb($hex);
            return (int) imagecolorallocate($im, $r, $g, $b);
        };
        $white   = $c(FlierLayout::C_WHITE);
        $gold    = $c(FlierLayout::C_GOLD);
        $goldInk = $c(FlierLayout::C_ON_GOLD);
        $mist    = $c(FlierLayout::C_MIST);
        $muted   = $c(FlierLayout::C_MUTED);

        // The background runs the full width, INCLUDING behind the photo column, so the
        // fade at the seam has a real colour to land on at every scanline.
        $bgTop = FlierLayout::rgb(FlierLayout::C_BG_TOP);
        $bgBot = FlierLayout::rgb(FlierLayout::C_BG_BOTTOM);
        $this->vGradient($im, 0, 0, $W, $H, $bgTop, $bgBot);

        // ── The portrait column ──────────────────────────────────────────────
        $photo = !empty($f['photo_card']) ? $this->loadPhoto((string) $f['photo_card']) : null;
        if ($photo !== null) {
            $this->cover($im, $photo, 0, 0, $PW, $H);
            imagedestroy($photo);
            // Soft seam. A hard vertical edge between a photograph and a flat panel reads
            // as two images pasted together, which at preview size is the difference
            // between a designed card and a broken one.
            $this->edgeFade($im, $PW - 150, $PW, $H, $bgTop, $bgBot);
        } else {
            imagefilledrectangle($im, 0, 0, $PW - 1, $H - 1, $c(FlierLayout::C_PANEL));
            // Centred in the whole column here — the OG card's photo panel is full-height
            // with nothing above it to collide with.
            $this->centredInBand($im, (string) $L['monogram'], 230, self::font('display'),
                $c(FlierLayout::C_MONOGRAM), $PW / 2, 0, $H);
            $this->edgeFade($im, $PW - 110, $PW, $H, $bgTop, $bgBot);
        }

        // ── The content column ───────────────────────────────────────────────
        //
        // FOUR THINGS ONLY: the VOTE NOW lockup, the rank pill, the name, and the jury
        // note. Category, country, the standing line, the progress rail and the URL are
        // all deliberately absent — this card is read at roughly a third of native size
        // in a WhatsApp thread, where a fifth and sixth element are not small text, they
        // are noise that costs the name and the rank their legibility. The flier is the
        // artefact that carries the full story; this one has to survive a thumbnail.
        $cx = $PW + 62;
        $cw = $W - $cx - 56;

        // Rank pill, top-right — measured first, because the kicker's second line has to
        // be shortened to clear it.
        $chipW = 0.0;
        if ($L['showRank']) {
            $head  = '#' . $L['rank'];
            $tail  = ' of ' . $L['fieldSize'];
            $wHead = $this->width($head, 34, self::font('display'));
            $wTail = $this->width($tail, 22, self::font('bold'));
            $chipW = $wHead + $wTail + 52;
            $px    = $W - 56 - $chipW;
            $this->pill($im, $px, 52, $chipW, 58, $gold);
            $x0 = $px + ($chipW - ($wHead + $wTail)) / 2;
            $this->text($im, $head, 34, self::font('display'), $goldInk, $x0, 92);
            $this->text($im, $tail, 22, self::font('bold'), $goldInk, $x0 + $wHead, 92);
        }

        // Kicker: no rail on this card — at preview size a 6px bar beside 24px type is
        // a smudge, and the column edge already establishes the alignment.
        $this->text($im, 'VOTE NOW', 24, self::font('bold'), $gold, $cx, 76, 5.8);
        $prog  = mb_strtoupper((string) $f['programme']);
        // Shortened to the space the pill leaves, minus the tracking wrapMeasured
        // cannot see. A three-word programme title otherwise runs under the pill.
        $progW = max(120.0, $cw - $chipW - 28 - (mb_strlen($prog) * 1.9));
        $progLines = $this->wrapMeasured($prog, $progW, 19, self::font('semibold'), 1);
        $this->text($im, $progLines[0] ?? '', 19, self::font('semibold'), $mist, $cx, 112, 1.9);

        // ── The name ─────────────────────────────────────────────────────────
        //
        // Bottom-anchored at a FIXED edge, like the flier, and at the same ladder size —
        // the card is 562px wide against the flier's 920, so the ladder alone would
        // overflow, and fitLines() steps down from it until the name genuinely fits.
        // Measuring is also what keeps a stacked Yoruba or Igbo diacritic on the card:
        // at 76px the marks on "Ọlásùnkànmí" reach ~14px above the cap line, and a
        // baseline derived from the point size alone clipped them flat — which on a name
        // is not a cosmetic imperfection, it is the name spelled wrong.
        [$size, $lines] = $this->fitLines((string) $f['name'], $cw, (int) $L['ogNameSize'], 34, self::font('display'), 2);
        $lh = FlierLayout::NAME_LINE_H * $size;
        $y  = (496 - (FlierLayout::NAME_PAD + count($lines) * $lh)) + FlierLayout::NAME_PAD
            + max($lh * 0.78, $this->ascent($lines[0] ?? '', $size, self::font('display')));
        foreach ($lines as $line) {
            $this->text($im, $line, $size, self::font('display'), $white, $cx, $y);
            $y += $lh;
        }

        // The jury note, at a fixed y. Same reason as the flier: a rank reads as a
        // result, and this award is decided largely by an independent jury.
        foreach ($this->wrapMeasured(FlierLayout::FOOTNOTE, $cw, 19, self::font('regular'), 2) as $i => $line) {
            $this->text($im, $line, 19, self::font('regular'), $muted, $cx, 548 + 22 + ($i * 25));
        }

        ob_start();
        imagepng($im, null, 6);
        $out = (string) ob_get_clean();
        imagedestroy($im);

        return $out !== '' ? $out : null;
    }

    /** The portrait strip on a message card. Narrower than the ballot card's, because
     *  here the WORDS are the subject and the face is the context. */
    private const MSG_PHOTO_W = 372;

    /**
     * A voter's message of support, as a 1200×630 social card.
     *
     * ── WHY A MESSAGE NEEDS ITS OWN GRAPHIC ─────────────────────────────────
     *
     * `/m/{token}` already gives each message its own og:title, so Facebook renders a
     * different HEADLINE per message. The IMAGE was still the nominee's ballot card —
     * "VOTE NOW", the rank pill, the name — which is a good card for the ballot and
     * the wrong one here: it invites a vote where the reader was handed a sentence,
     * and it makes fifty supporters' fifty different messages look, at thumbnail
     * size, like fifty copies of the same post. Facebook's preview is mostly image;
     * a card that does not carry the words is a card that loses them.
     *
     * So this puts the message itself on the graphic. It reuses the ballot card's
     * gradient, portrait strip and seam fade — a supporter's share and the nominee's
     * own share should look like they came from the same platform — and drops
     * everything that belongs to the ballot: no VOTE NOW lockup, no rank, no
     * progress, no jury footnote. A rank on a card about somebody's kind words reads
     * as a scoreboard.
     *
     * ── WHAT IT DOES ABOUT LONG MESSAGES ────────────────────────────────────
     *
     * A card is a teaser, not a document. The quote is pre-trimmed to what can be
     * read at a third of native size in a chat thread, on a word boundary, with an
     * ellipsis that says plainly there is more — because the alternative is either
     * 22px type nobody reads or a sentence cut mid-word, and both make the platform
     * look broken rather than the message look inviting.
     *
     * @param array<string,mixed> $f    the nominee, from {@see forNominee()}
     * @param string $quote             the message body
     * @param string $attribution       the display name, already resolved against consent
     */
    public function messageCard(array $f, string $quote, string $attribution): ?string
    {
        if (!function_exists('imagecreatetruecolor') || !self::fontsPresent()['ok']) {
            return null;
        }

        $W = self::OG_W; $H = self::OG_H; $PW = self::MSG_PHOTO_W;
        $L = FlierLayout::for($f);

        $im = imagecreatetruecolor($W, $H);
        imagealphablending($im, true);

        $c = static function (string $hex) use ($im): int {
            [$r, $g, $b] = FlierLayout::rgb($hex);
            return (int) imagecolorallocate($im, $r, $g, $b);
        };
        $white = $c(FlierLayout::C_WHITE);
        $leaf  = $c(FlierLayout::C_LEAF);
        $mist  = $c(FlierLayout::C_MIST);
        $muted = $c(FlierLayout::C_MUTED);

        $bgTop = FlierLayout::rgb(FlierLayout::C_BG_TOP);
        $bgBot = FlierLayout::rgb(FlierLayout::C_BG_BOTTOM);
        $this->vGradient($im, 0, 0, $W, $H, $bgTop, $bgBot);

        // ── The portrait strip ───────────────────────────────────────────────
        $photo = !empty($f['photo_card']) ? $this->loadPhoto((string) $f['photo_card']) : null;
        if ($photo !== null) {
            $this->cover($im, $photo, 0, 0, $PW, $H);
            imagedestroy($photo);
            $this->edgeFade($im, $PW - 130, $PW, $H, $bgTop, $bgBot);
        } else {
            imagefilledrectangle($im, 0, 0, $PW - 1, $H - 1, $c(FlierLayout::C_PANEL));
            // 140, not the ballot card's 230: this column is 372px wide, and a two-letter
            // monogram at display sizes is ~1.9× the point size, so the larger figure ran
            // the second letter straight into the seam fade — visible on the first render.
            $this->centredInBand($im, (string) $L['monogram'], 140, self::font('display'),
                $c(FlierLayout::C_MONOGRAM), $PW / 2, 0, $H);
            $this->edgeFade($im, $PW - 100, $PW, $H, $bgTop, $bgBot);
        }

        // ── The words ────────────────────────────────────────────────────────
        $cx = $PW + 56;
        $cw = $W - $cx - 56;

        $this->text($im, 'A MESSAGE OF SUPPORT', 21, self::font('bold'), $leaf, $cx, 88, 5.4);

        // Trimmed BEFORE fitting. fitLines() shrinks rather than truncates — right for a
        // name, wrong here: it would take a 400-character message down to the floor and
        // render it at a size nobody reads in a thread.
        $q = '“' . $this->cardQuote($quote) . '”';
        [$size, $lines] = $this->fitLines($q, $cw, 46, 28, self::font('display'), 5);

        // Centred as a BLOCK in the space between the kicker and the attribution, so a
        // one-line message and a five-line one are both balanced rather than both
        // top-aligned with a hole underneath.
        $lh   = 1.3 * $size;
        $band = 466 - 150;                       // between kicker and attribution
        $y    = 150 + max(0.0, ($band - count($lines) * $lh) / 2)
              + max($lh * 0.76, $this->ascent($lines[0] ?? '', $size, self::font('display')));
        foreach ($lines as $line) {
            $this->text($im, $line, $size, self::font('display'), $white, $cx, $y);
            $y += $lh;
        }

        // ── Who said it, and about whom ──────────────────────────────────────
        //
        // The attribution has already been resolved against the voter's consent by
        // VoteMessageService — "A supporter" arrives here as a string, so this cannot
        // publish a name the reader never agreed to.
        $who = $this->wrapMeasured(
            '— ' . $attribution . ', on ' . (string) $f['name'], $cw, 25, self::font('semibold'), 2
        );
        foreach ($who as $i => $line) {
            $this->text($im, $line, 25, self::font('semibold'), $mist, $cx, 500 + ($i * 32));
        }

        $this->text($im, 'AFRICA GATES · ' . mb_strtoupper((string) $f['category']),
            17, self::font('regular'), $muted, $cx, 588, 2.4);

        ob_start();
        imagepng($im, null, 6);
        $out = (string) ob_get_clean();
        imagedestroy($im);

        return $out !== '' ? $out : null;
    }

    /** How much of a message fits on a card and still reads at thumbnail size. */
    private const CARD_QUOTE_CHARS = 168;

    /** Trim to a whole word. A card is a teaser; the page carries the rest. */
    private function cardQuote(string $s): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
        if (mb_strlen($s) <= self::CARD_QUOTE_CHARS) return $s;
        $cut = mb_substr($s, 0, self::CARD_QUOTE_CHARS);
        $sp  = mb_strrpos($cut, ' ');
        return rtrim($sp !== false && $sp > self::CARD_QUOTE_CHARS * 0.6 ? mb_substr($cut, 0, $sp) : $cut, " ,.;:—-") . '…';
    }

    /**
     * How far this string's INK actually rises above the baseline, in pixels.
     *
     * Not the point size, and not the font's nominal ascent — the measured extent of
     * these exact glyphs. It exists because African orthographies stack marks: at 76px
     * the acute-plus-dot-below of "Ọlásùnkànmí" reaches about fourteen pixels higher
     * than an unaccented cap, and a first baseline placed at `top + size` (which is what
     * the flier's fixed geometry effectively assumes, with the slack to absorb it) cut
     * those marks flat on the card's tighter band. A clipped diacritic is not a cosmetic
     * imperfection on a graphic showing someone's name; it is their name misspelled.
     *
     * imagettfbbox returns the corners anticlockwise from the lower left, with y NEGATIVE
     * above the baseline — hence the min of the two upper corners, negated.
     */
    private function ascent(string $s, int $size, string $font): float
    {
        $b = imagettfbbox($size, 0, $font, $s);
        if ($b === false) return (float) $size;
        return max((float) $size * 0.72, (float) -min($b[5], $b[7]));
    }

    /**
     * The largest size at which $text fits $maxLines lines of $maxW WITHOUT being
     * ellipsised, and those lines.
     *
     * {@see wrapMeasured} truncates when it runs out of lines, which is the right
     * behaviour for rally copy — a sentence can lose its tail. It is the wrong behaviour
     * for a name: "Ọlásùnkànmí Adébáyọ̀ Ogun…" is a person rendered incorrectly on a
     * graphic they are about to share under their own name. So this shrinks first and
     * only accepts truncation at the floor, where there is nothing left to trade.
     *
     * ── AND IT CHECKS THE WIDTHS, NOT JUST THE LINE COUNT ────────────────────
     *
     * `wrapMeasured` places a word that is wider than the whole column anyway rather
     * than break it mid-word (`|| $cur === ''`), which is the right call for wrapping and
     * makes its output a poor thing to trust blind. "Tsehaynesh Wolde-Giorgis" wrapped to
     * two lines at 76px and the second, `Wolde-Giorgis`, was 60px wider than the column —
     * so it ran off the edge of the card and the surname was cut in half. Verifying each
     * returned line against $maxW is what turns this into a real fit test.
     *
     * @return array{0:int, 1:list<string>}
     */
    private function fitLines(string $text, float $maxW, int $start, int $min, string $font, int $maxLines): array
    {
        for ($size = $start; $size > $min; $size -= 2) {
            $lines = $this->wrapMeasured($text, $maxW, $size, $font, $maxLines);
            if (count($lines) > $maxLines) continue;
            if (str_ends_with((string) end($lines), '…')) continue;
            $fits = true;
            foreach ($lines as $line) {
                if ($this->width($line, $size, $font) > $maxW) { $fits = false; break; }
            }
            if ($fits) return [$size, $lines];
        }
        return [$min, $this->wrapMeasured($text, $maxW, $min, $font, $maxLines)];
    }

    private function centred($im, string $s, int $size, string $font, int $colour, float $cx, float $y): void
    {
        imagettftext($im, $size, 0, (int) round($cx - $this->width($s, $size, $font) / 2), (int) round($y), $colour, $font, $s);
    }


    /**
     * Centred, shrinking the size until it fits $maxW.
     *
     * Never truncates: every string this is used on is load-bearing — the vote URL and
     * the jury footnote — and a clipped URL is worse than a small one.
     */
    private function centredFit($im, string $s, int $size, string $font, int $colour, float $cx, float $y, float $maxW): void
    {
        while ($size > 10 && $this->width($s, $size, $font) > $maxW) {
            $size--;
        }
        $this->centred($im, $s, $size, $font, $colour, $cx, $y);
    }

    /**
     * Left-aligned, shrunk until it fits — and never truncated.
     *
     * {@see centredFit} for the centred equivalent. Split out rather than parameterised
     * because the two differ in the x they are given (a centre vs. a left edge), and
     * conflating those is how a string ends up half a column to the left of where it
     * should be with no obvious cause.
     */
    private function centredFitLeft($im, string $s, int $size, string $font, int $colour, float $x, float $y, float $maxW): void
    {
        while ($size > 10 && $this->width($s, $size, $font) > $maxW) {
            $size--;
        }
        $this->text($im, $s, $size, $font, $colour, $x, $y);
    }

    /**
     * Fade the photo column into the panel across a band of columns.
     *
     * ── WHY IT IS DONE PER PIXEL ─────────────────────────────────────────────
     *
     * The obvious implementation draws one vertical `imageline` per column in a single
     * flat colour. That is wrong here for the same reason the flier's scrim had to be
     * taught {@see gradientAt}: the background is a VERTICAL gradient, so the colour the
     * fade must land on differs by about eleven levels per channel between the top and
     * bottom of the card. A flat terminal colour therefore leaves a visible bright band
     * at one end of the seam and a dark one at the other — which reads as a rendering
     * fault, and on a link preview that is the entire first impression.
     *
     * So the alpha ramp is precomputed once per column and the row colour is taken from
     * the gradient, giving ~150 × 630 writes. The packed-integer colour avoids an
     * `imagecolorallocatealpha` call per pixel; `imagesetpixel` on a truecolour image
     * accepts `(alpha << 24) | (r << 16) | (g << 8) | b` directly.
     *
     * The ramp is quadratic, not linear: the photograph stays clean until close to the
     * seam and then goes quickly, rather than being veiled across the whole band.
     */
    private function edgeFade($im, int $x0, int $x1, int $h, array $from, array $to): void
    {
        $x0 = max(0, $x0);
        $band = max(1, $x1 - $x0);

        // alpha 0..127 in GD's inverted scale (0 = opaque, 127 = transparent).
        $alpha = [];
        for ($i = 0; $i < $band; $i++) {
            $t = $band > 1 ? $i / ($band - 1) : 1.0;
            $alpha[$i] = (int) round(127 * (1 - $t * $t)) << 24;
        }

        for ($y = 0; $y < $h; $y++) {
            [$r, $g, $b] = $this->gradientAt($from, $to, $y, $h);
            $base = ($r << 16) | ($g << 8) | $b;
            for ($i = 0; $i < $band; $i++) {
                imagesetpixel($im, $x0 + $i, $y, $alpha[$i] | $base);
            }
        }
    }


    private function pill($im, float $x, float $y, float $w, float $h, int $colour): void
    {
        $r = min($h / 2, $w / 2);
        imagefilledrectangle($im, (int) ($x + $r), (int) $y, (int) ($x + $w - $r), (int) ($y + $h), $colour);
        imagefilledellipse($im, (int) ($x + $r), (int) ($y + $h / 2), (int) ($r * 2), (int) $h, $colour);
        imagefilledellipse($im, (int) ($x + $w - $r), (int) ($y + $h / 2), (int) ($r * 2), (int) $h, $colour);
    }

    /**
     * The gradient's colour at a given scanline.
     *
     * Exists so the scrim can finish on exactly the tone the background already has
     * there, rather than on the gradient's endpoint. See the call site for the seam this
     * was written to remove.
     *
     * @return array{0:int,1:int,2:int}
     */
    private function gradientAt(array $from, array $to, int $y, int $h): array
    {
        $t = $h > 1 ? max(0.0, min(1.0, $y / ($h - 1))) : 0.0;
        return [
            (int) round($from[0] + ($to[0] - $from[0]) * $t),
            (int) round($from[1] + ($to[1] - $from[1]) * $t),
            (int) round($from[2] + ($to[2] - $from[2]) * $t),
        ];
    }


    /** The photo scrim: transparent at the top, near-opaque at the bottom. */
    private function scrim($im, int $y, int $h, int $w, array $rgb): void
    {
        for ($i = 0; $i < $h; $i++) {
            $t = $i / max(1, $h - 1);
            // Matches the SVG's stops: 0 at the top, 0.55 at 62%, 0.97 at the bottom.
            $a = $t < 0.62 ? (0.55 * ($t / 0.62)) : (0.55 + 0.42 * (($t - 0.62) / 0.38));
            $c = imagecolorallocatealpha($im, $rgb[0], $rgb[1], $rgb[2], (int) round(127 * (1 - $a)));
            imageline($im, 0, $y + $i, $w - 1, $y + $i, $c);
        }
    }

    /**
     * The top scrim, behind the kicker. Mirrors the SVG's `topscrim` stops exactly:
     * TOP_SCRIM_OP at the top, 0.18 at 60%, 0 at the bottom.
     */
    private function topScrim($im, int $w, int $h, array $rgb): void
    {
        $peak = FlierLayout::TOP_SCRIM_OP;
        $hold = FlierLayout::TOP_SCRIM_HOLD;
        for ($i = 0; $i < $h; $i++) {
            $t = $i / max(1, $h - 1);
            // Full strength through the lockup, then away on a curve whose slope is ZERO
            // at the hold boundary. A straight ramp from full to nothing put a visible
            // horizontal edge across the photo at the moment the fade began — the eye
            // reads a sudden change in gradient as a seam. `1 - u²` leaves the boundary
            // smooth and does its falling later.
            $u = $t <= $hold ? 0.0 : ($t - $hold) / (1 - $hold);
            $a = $peak * (1 - $u * $u);
            $c = imagecolorallocatealpha($im, $rgb[0], $rgb[1], $rgb[2], (int) round(127 * (1 - $a)));
            imageline($im, 0, $i, $w - 1, $i, $c);
        }
    }

    /** XML-escape. Every interpolation into the SVG goes through this. */
    private function x(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Greedy word wrap to at most $max lines, ellipsising what will not fit.
     *
     * SVG has no text flow, so wrapping is the caller's job. Truncating the LAST line
     * rather than dropping it keeps the sentence readable instead of ending mid-clause.
     *
     * @return list<string>
     */
    private function wrap(string $text, int $perLine, int $max): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = ['']; $i = 0;
        foreach ($words as $w) {
            $try = $lines[$i] === '' ? $w : $lines[$i] . ' ' . $w;
            if (mb_strlen($try) <= $perLine || $lines[$i] === '') {
                $lines[$i] = $try;
                continue;
            }
            if ($i + 1 >= $max) {
                $lines[$i] = mb_substr($lines[$i], 0, max(1, $perLine - 1)) . '…';
                break;
            }
            $lines[++$i] = $w;
        }
        return array_values(array_filter($lines, static fn (string $l): bool => $l !== ''));
    }

    /** Up to two initials, for the no-photo monogram. */
    private function initials(string $name): string
    {
        // LETTERS only. "Nominee 48 Surname" produced "N4" on the first real render —
        // a digit as an initial reads as a rendering fault rather than as a monogram,
        // and any name carrying a number, an edition or a cohort year does the same.
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $out = '';
        foreach ($parts as $p) {
            $c = mb_substr($p, 0, 1);
            if ($c === '' || !preg_match('/^\p{L}$/u', $c)) continue;
            $out .= mb_strtoupper($c);
            if (mb_strlen($out) >= 2) break;
        }
        return $out !== '' ? $out : '?';
    }
}
