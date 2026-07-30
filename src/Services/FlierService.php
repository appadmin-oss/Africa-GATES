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
    /** Instagram/WhatsApp portrait. 4:5 is the largest feed crop on both. */
    public const W = 1080;
    public const H = 1350;

    /**
     * Height of the photo panel. Was a bare `820` written out five times across the two
     * renderers; it is a constant now because {@see \AfricaGates\Support\Media}'s `flier`
     * preset has to request exactly these dimensions from Cloudinary. A preset that
     * disagreed with the panel would hand GD a differently-shaped image and reintroduce
     * a crop — the specific thing the face-aware derivative exists to remove.
     */
    public const PHOTO_H = 820;

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
    public const OG_PHOTO_W = 480;

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
                ->first([
                    'n.id', 'n.name', 'n.tagline', 'n.photo_path', 'n.country_code', 'n.vote_count',
                    'c.id as category_id', 'c.title as category', 'p.title as programme', 'p.slug as programme_slug',
                ]);
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
        $W = self::W; $H = self::H; $PH = self::PHOTO_H;
        $s = $f['standing'];

        // Name sizing by length. A three-word Nigerian or Ethiopian name is common and
        // a fixed size either clips it or wastes the poster on a short one.
        $name  = $f['name'];
        $len   = mb_strlen($name);
        $nameSize = $len <= 14 ? 96 : ($len <= 22 ? 78 : ($len <= 32 ? 62 : 50));
        $nameLines = $this->wrap($name, $len <= 22 ? 16 : 22, 2);

        $rallyLines = $this->wrap($f['rally'], 34, 3);

        $out  = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" ';
        $out .= 'width="' . $W . '" height="' . $H . '" viewBox="0 0 ' . $W . ' ' . $H . '" ';
        // A title and description make the graphic itself accessible — a screen reader
        // opening the SVG directly, and any surface that embeds it, get a real name
        // instead of "image".
        $out .= 'role="img" aria-labelledby="fTitle fDesc">';
        $out .= '<title id="fTitle">' . $this->x('Vote for ' . $name . ' — ' . $f['category']) . '</title>';
        $out .= '<desc id="fDesc">' . $this->x($f['headline'] . '. ' . $f['rally'] . ' Vote at ' . $f['short_url']) . '</desc>';

        $out .= '<defs>';
        // The brand green, deepening downward. Two stops, not a busy gradient — a flier
        // is read at thumbnail size on a phone.
        $out .= '<linearGradient id="bg" x1="0" y1="0" x2="0" y2="1">'
              . '<stop offset="0" stop-color="#123b2f"/><stop offset="0.55" stop-color="#0d2a24"/>'
              . '<stop offset="1" stop-color="#08201c"/></linearGradient>';
        // Fades the photo into the panel so text over it stays legible whatever the
        // photo is — the one thing that reliably ruins a generated graphic.
        $out .= '<linearGradient id="scrim" x1="0" y1="0" x2="0" y2="1">'
              . '<stop offset="0" stop-color="#08201c" stop-opacity="0"/>'
              . '<stop offset="0.62" stop-color="#08201c" stop-opacity="0.55"/>'
              . '<stop offset="1" stop-color="#08201c" stop-opacity="0.97"/></linearGradient>';
        $out .= '<clipPath id="photoClip"><rect x="0" y="0" width="' . $W . '" height="' . $PH . '"/></clipPath>';
        $out .= '</defs>';

        $out .= '<rect width="' . $W . '" height="' . $H . '" fill="url(#bg)"/>';

        // Photo, or a monogram when there is none. A blank rectangle where a face
        // should be is the difference between a flier someone posts and one they do not.
        // `xMidYMin`, not `xMidYMid`. Same reasoning as the raster's PHOTO_ANCHOR_Y: a
        // centre crop of a portrait lands on the chest. SVG's preserveAspectRatio only
        // offers Min/Mid/Max, so this is top-anchored where the raster is 22% down —
        // the two differ slightly on a very tall local photo, and the raster is the one
        // that matters, being both the download and the og:image. A Cloudinary photo
        // arrives already cropped to this exact box, so neither rule applies to it.
        if ($f['photo'] !== null) {
            $out .= '<g clip-path="url(#photoClip)">'
                  . '<image href="' . $this->x($f['photo']) . '" xlink:href="' . $this->x($f['photo']) . '" '
                  . 'x="0" y="0" width="' . $W . '" height="' . $PH . '" preserveAspectRatio="xMidYMin slice"/>'
                  . '</g>';
        } else {
            $initials = $this->initials($name);
            $out .= '<rect x="0" y="0" width="' . $W . '" height="' . $PH . '" fill="#0f3329"/>';
            $out .= '<text x="' . ($W / 2) . '" y="500" text-anchor="middle" '
                  . 'font-family="Playfair Display, Georgia, serif" font-size="260" font-weight="700" '
                  . 'fill="#7fc87c" fill-opacity="0.28">' . $this->x($initials) . '</text>';
        }
        $out .= '<rect x="0" y="0" width="' . $W . '" height="' . $PH . '" fill="url(#scrim)"/>';

        // Kicker rail
        $out .= '<rect x="64" y="64" width="6" height="52" fill="#c9a227"/>';
        $out .= '<text x="88" y="92" font-family="DM Sans, system-ui, sans-serif" font-size="26" '
              . 'font-weight="700" letter-spacing="4" fill="#f3f7f4">' . $this->x(mb_strtoupper('Vote now')) . '</text>';
        $out .= '<text x="88" y="126" font-family="DM Sans, system-ui, sans-serif" font-size="22" '
              . 'fill="#a9c7bd" letter-spacing="1">' . $this->x($f['programme']) . '</text>';

        // Standing chip — the reason the flier is persuasive, so it sits top-right where
        // the eye lands after the face.
        if (($s['field'] ?? 0) >= 2) {
            $chip = '#' . $s['rank'] . ' of ' . $s['field'];
            $cw   = 44 + (mb_strlen($chip) * 17);
            $out .= '<rect x="' . ($W - 64 - $cw) . '" y="62" width="' . $cw . '" height="58" rx="29" '
                  . 'fill="#c9a227"/>';
            $out .= '<text x="' . ($W - 64 - $cw / 2) . '" y="100" text-anchor="middle" '
                  . 'font-family="DM Sans, system-ui, sans-serif" font-size="28" font-weight="700" '
                  . 'fill="#1a1204">' . $this->x($chip) . '</text>';
        }

        // Name
        $y = 700 - ((count($nameLines) - 1) * ($nameSize + 8));
        foreach ($nameLines as $line) {
            $out .= '<text x="64" y="' . $y . '" font-family="Playfair Display, Georgia, serif" '
                  . 'font-size="' . $nameSize . '" font-weight="700" fill="#ffffff">' . $this->x($line) . '</text>';
            $y += $nameSize + 8;
        }

        // Category + country
        $meta = $f['category'] . ($f['country'] !== '' ? '  ·  ' . $f['country'] : '');
        $out .= '<text x="64" y="' . ($y + 14) . '" font-family="DM Sans, system-ui, sans-serif" '
              . 'font-size="30" fill="#7fc87c" letter-spacing="1">' . $this->x($meta) . '</text>';

        // Standing line + progress track. Omitted entirely rather than shown empty when
        // there is no field to have a position in.
        $panelY = 880;
        if (($s['field'] ?? 0) >= 2) {
            $out .= '<text x="64" y="' . $panelY . '" font-family="DM Sans, system-ui, sans-serif" '
                  . 'font-size="30" font-weight="600" fill="#e8f2ec">' . $this->x($f['headline']) . '</text>';
            $out .= '<rect x="64" y="' . ($panelY + 24) . '" width="' . ($W - 128) . '" height="14" rx="7" fill="#ffffff" fill-opacity="0.14"/>';
            $out .= '<rect x="64" y="' . ($panelY + 24) . '" width="'
                  . (int) round((($W - 128) * (int) $s['progress_pct']) / 100)
                  . '" height="14" rx="7" fill="#7fc87c"/>';
            $panelY += 74;
        }

        // Momentum, only when it is measurable AND non-zero. "0 votes in 24 hours" on a
        // flier you are about to post is an argument against voting.
        if (($s['momentum_available'] ?? false) && (int) ($s['momentum_24h'] ?? 0) > 0) {
            $m = (int) $s['momentum_24h'];
            $out .= '<text x="64" y="' . $panelY . '" font-family="DM Sans, system-ui, sans-serif" '
                  . 'font-size="26" fill="#c9a227">'
                  . $this->x($m . ' vote' . ($m === 1 ? '' : 's') . ' in the last 24 hours')
                  . '</text>';
            $panelY += 48;
        }

        // Rally copy
        $panelY = max($panelY, 1010);
        foreach ($rallyLines as $line) {
            $out .= '<text x="64" y="' . $panelY . '" font-family="DM Sans, system-ui, sans-serif" '
                  . 'font-size="34" fill="#ffffff" fill-opacity="0.94">' . $this->x($line) . '</text>';
            $panelY += 46;
        }

        // URL bar — the actionable part, so it is the highest-contrast block on the card.
        $out .= '<rect x="64" y="' . ($H - 150) . '" width="' . ($W - 128) . '" height="86" rx="43" fill="#ffffff"/>';
        $out .= '<text x="' . ($W / 2) . '" y="' . ($H - 96) . '" text-anchor="middle" '
              . 'font-family="DM Sans, system-ui, sans-serif" font-size="30" font-weight="700" '
              . 'fill="#0d2a24">' . $this->x($f['short_url']) . '</text>';

        // The honest footnote. A rank on a graphic reads as a result, and this platform's
        // awards are 55% independent jury — omitting that turns a share card into a
        // misleading claim.
        $out .= '<text x="' . ($W / 2) . '" y="' . ($H - 28) . '" text-anchor="middle" '
              . 'font-family="DM Sans, system-ui, sans-serif" font-size="20" fill="#7fa295">'
              . $this->x('Public votes are one part of the score. An independent jury decides the award.')
              . '</text>';

        return $out . '</svg>';
    }

    // ── The raster ───────────────────────────────────────────────────────────

    /** The bundled faces, by role. Paths resolved once in {@see font()}. */
    private const FONTS = [
        'display'  => 'PlayfairDisplay-Bold.ttf',   // the name
        'bold'     => 'DMSans-Bold.ttf',            // kicker, chip, URL
        'semibold' => 'DMSans-SemiBold.ttf',        // the standing line
        'regular'  => 'DMSans-Regular.ttf',         // everything else
    ];

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

        $W = self::W; $H = self::H; $PH = self::PHOTO_H;
        $im = imagecreatetruecolor($W, $H);
        // No alpha: an OG image is composited on an unknown background by every client,
        // and a transparent flier renders differently in each one.
        imagealphablending($im, true);

        $rgb = static fn (int $r, int $g, int $b) => imagecolorallocate($im, $r, $g, $b);
        $white  = $rgb(255, 255, 255);
        $green  = $rgb(127, 200, 124);
        $gold   = $rgb(201, 162, 39);
        $goldInk= $rgb(26, 18, 4);
        $mint   = $rgb(232, 242, 236);
        $muted  = $rgb(169, 199, 189);
        $faint  = $rgb(127, 162, 149);
        $ink    = $rgb(13, 42, 36);

        $bgTop = [18, 59, 47]; $bgBot = [8, 32, 28];
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
            // the scrim loop below runs rows 0..$PH-1, so filling to $PH left row 820
            // painted with the panel colour and never darkened — a one-pixel bright line
            // straight across the card, measured as rgb(15,51,41) between two rows of
            // rgb(12,43,35). It reads as a rendering fault, which on a graphic a nominee
            // posts is the whole impression.
            imagefilledrectangle($im, 0, 0, $W, $PH - 1, $rgb(15, 51, 41));
            $ini = $this->initials((string) $f['name']);
            $this->centred($im, $ini, 260, self::font('display'), $rgb(30, 74, 60), $W / 2, 500);
        }
        // The scrim keeps text legible over ANY photo, which is the one thing that
        // reliably ruins a generated graphic.
        //
        // Its terminal colour is the PAGE GRADIENT'S colour at the scrim's bottom edge,
        // not the gradient's end colour. Using the latter left a visible horizontal seam
        // at y=820 — the scrim finished ~97% opaque on (8,32,28) while the background at
        // that line was (12,43,36), a step of about four levels per channel that reads as
        // a rendering fault straight across the card.
        $this->scrim($im, 0, $PH, $W, $this->gradientAt($bgTop, $bgBot, $PH, $H));

        // The chip is measured and drawn BEFORE the programme line, because the programme
        // line has to be shortened to clear it. Drawing them in the other order — which is
        // what this did — put "Africa GATES Continental Recognition Programme" straight
        // underneath the gold pill, two strings overlapping in the corner the eye reaches
        // first. The card's first render made the same mistake and showed it plainly.
        $s = $f['standing'];
        $chipW = 0.0;
        if ((int) ($s['field'] ?? 0) >= 2) {
            $chip  = '#' . $s['rank'] . ' of ' . $s['field'];
            $chipW = $this->width($chip, 28, self::font('bold')) + 44;
            $this->pill($im, $W - 64 - $chipW, 62, $chipW, 58, $gold);
            $this->centred($im, $chip, 28, self::font('bold'), $goldInk, $W - 64 - $chipW / 2, 100);
        }

        imagefilledrectangle($im, 64, 64, 69, 116, $gold);
        $this->text($im, 'VOTE NOW', 26, self::font('bold'), $white, 88, 92, 4);
        // wrapMeasured has no concept of letter-spacing, and this line is drawn with 1px
        // of tracking — so its real width is the measured width plus one pixel per
        // character. Subtracting that from the budget rather than hoping the right margin
        // absorbs it is what keeps a longer programme title out from under the chip.
        $progW  = max(160.0, $W - 88 - 64 - $chipW - 28 - mb_strlen((string) $f['programme']));
        $progLn = $this->wrapMeasured((string) $f['programme'], $progW, 22, self::font('regular'), 1);
        $this->text($im, $progLn[0] ?? '', 22, self::font('regular'), $muted, 88, 126, 1);

        // Measured, not counted. The character-count ladder (96/78/62/50 by mb_strlen)
        // was an estimate the SVG has to make because it cannot measure text — but the
        // raster CAN, and the estimate has the same failure the card's first render
        // exposed: `wrapMeasured` never breaks a word, so a single name element wider than
        // the column at the chosen size runs off the edge of the graphic. `Wolde-Giorgis`
        // is 13 characters and clears 952px at 96pt. {@see fitLines} shrinks until it
        // genuinely fits and refuses to ellipsise a name it could have set smaller.
        //
        // This is a deliberate divergence from svg(), which keeps the ladder. The two are
        // still one design; the raster is simply the one with metrics, and it is the one
        // that gets downloaded and posted.
        $name = (string) $f['name'];
        [$size, $lines] = $this->fitLines($name, $W - 128, 96, 40, self::font('display'), 2);
        $y = 700 - ((count($lines) - 1) * ($size + 8));
        foreach ($lines as $line) {
            $this->text($im, $line, $size, self::font('display'), $white, 64, $y);
            $y += $size + 8;
        }

        $meta = $f['category'] . ($f['country'] !== '' ? '  ·  ' . $f['country'] : '');
        $this->text($im, $meta, 30, self::font('regular'), $green, 64, $y + 14, 1);

        $py = 880;
        if ((int) ($s['field'] ?? 0) >= 2) {
            $this->text($im, (string) $f['headline'], 30, self::font('semibold'), $mint, 64, $py);
            $this->pill($im, 64, $py + 24, $W - 128, 14, $rgb(46, 78, 68));
            $fill = max(14, (int) round((($W - 128) * (int) $s['progress_pct']) / 100));
            $this->pill($im, 64, $py + 24, $fill, 14, $green);
            $py += 74;
        }
        // Momentum only when measurable AND non-zero — "0 votes in 24 hours" on a graphic
        // you are about to post is an argument against voting.
        if (($s['momentum_available'] ?? false) && (int) ($s['momentum_24h'] ?? 0) > 0) {
            $m = (int) $s['momentum_24h'];
            $this->text($im, $m . ' vote' . ($m === 1 ? '' : 's') . ' in the last 24 hours',
                26, self::font('regular'), $gold, 64, $py);
            $py += 48;
        }

        $py = max($py, 1010);
        foreach ($this->wrapMeasured((string) $f['rally'], $W - 128, 34, self::font('regular'), 3) as $line) {
            $this->text($im, $line, 34, self::font('regular'), $white, 64, $py);
            $py += 46;
        }

        $this->pill($im, 64, $H - 150, $W - 128, 86, $white);
        $this->centredFit($im, (string) $f['short_url'], 30, self::font('bold'), $ink, $W / 2, $H - 96, $W - 176);
        // Shrunk to fit rather than trusted: at 20px Montserrat Regular this sentence is
        // wider than the card and ran off both edges. The SVG estimated it as fitting
        // because it cannot measure — which is exactly why the raster does.
        $this->centredFit($im, 'Public votes are one part of the score. An independent jury decides the award.',
            20, self::font('regular'), $faint, $W / 2, $H - 28, $W - 96);

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
        $im = imagecreatetruecolor($W, $H);
        imagealphablending($im, true);

        $rgb = static fn (int $r, int $g, int $b) => imagecolorallocate($im, $r, $g, $b);
        $white   = $rgb(255, 255, 255);
        $green   = $rgb(127, 200, 124);
        $gold    = $rgb(201, 162, 39);
        $goldInk = $rgb(26, 18, 4);
        $mint    = $rgb(232, 242, 236);
        $muted   = $rgb(169, 199, 189);
        $faint   = $rgb(127, 162, 149);
        $ink     = $rgb(13, 42, 36);

        // The background runs the full width, INCLUDING behind the photo column, so the
        // fade at the seam has a real colour to land on at every scanline.
        $bgTop = [18, 59, 47]; $bgBot = [8, 32, 28];
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
            imagefilledrectangle($im, 0, 0, $PW - 1, $H - 1, $rgb(15, 51, 41));
            $this->centred($im, $this->initials((string) $f['name']), 190, self::font('display'), $rgb(30, 74, 60), $PW / 2, 380);
            $this->edgeFade($im, $PW - 110, $PW, $H, $bgTop, $bgBot);
        }

        // ── The content column ───────────────────────────────────────────────
        $cx = $PW + 56;                 // left edge of the text column
        $cw = $W - $cx - 56;            // its usable width
        $s  = $f['standing'];
        $ranked = (int) ($s['field'] ?? 0) >= 2;

        // Rank chip, TOP-RIGHT — one of the three elements sized to survive a thumbnail,
        // and top-right is where the eye lands after the face. Measured first because the
        // programme line has to be shortened to clear it.
        $chipW = 0;
        if ($ranked) {
            $chip  = '#' . $s['rank'] . ' of ' . $s['field'];
            $chipW = (int) round($this->width($chip, 34, self::font('bold'))) + 48;
            $this->pill($im, $W - 56 - $chipW, 58, $chipW, 62, $gold);
            $this->centred($im, $chip, 34, self::font('bold'), $goldInk, $W - 56 - $chipW / 2, 101);
        }

        // Kicker rail + VOTE NOW + programme.
        imagefilledrectangle($im, $cx, 60, $cx + 5, 112, $gold);
        $this->text($im, 'VOTE NOW', 26, self::font('bold'), $white, $cx + 24, 88, 4);
        // Shortened to the space the chip leaves, not assumed to fit. A three-word
        // programme title otherwise runs underneath the chip and the two overlap.
        // Minus one pixel per character for the tracking wrapMeasured cannot see.
        $progW = max(120.0, $cw - $chipW - 28 - mb_strlen((string) $f['programme']));
        $progLines = $this->wrapMeasured((string) $f['programme'], $progW, 22, self::font('regular'), 1);
        $this->text($im, $progLines[0] ?? '', 22, self::font('regular'), $muted, $cx + 24, 120, 1);

        // ── The name, and the middle block it anchors ────────────────────────
        //
        // Sized by MEASUREMENT, not by character count. The flier's `mb_strlen`
        // heuristic was tuned against a 952px-wide column; this one is 608px, and
        // copying the thresholds over produced the defect the first render showed:
        // "Adaeze Nwosu" — twelve characters, so nominally the largest size — did not
        // fit on one line, wrapped to two, and its ascenders ran straight through the
        // programme line above it. {@see fitLines()} shrinks until the name genuinely
        // fits, so it is also never ellipsised when a smaller size would have held it.
        [$size, $lines] = $this->fitLines((string) $f['name'], $cw, 76, 34, self::font('display'), 2);

        // The block is vertically CENTRED in the band between the kicker and the URL
        // pill, rather than started at a fixed y. With a fixed start, a one-line name
        // left ~120px of empty green above the pill and a two-line name crowded it —
        // and the card is a fixed canvas, so there is no reflow to absorb either.
        // +12, not +8 as the flier uses: two stacked lines of a name carrying BOTH a
        // mark below (the dot of ọ) and marks above brought the two within a couple of
        // pixels of each other at 54px. The flier's wider column rarely wraps a name at
        // all, so it never surfaced there.
        $lineH  = $size + 12;
        $blockH = count($lines) * $lineH + 46 + ($ranked ? 84 : 0);
        $top    = self::OG_BAND_TOP + max(0.0, (self::OG_BAND_BOTTOM - self::OG_BAND_TOP - $blockH) / 2);

        // First baseline placed from the string's REAL ink extent, so a stacked Yoruba
        // or Igbo diacritic has room. Measured rather than assumed: at 76px the marks on
        // "Ọlásùnkànmí" reach ~14px above the cap line, and a baseline derived from the
        // point size alone clipped them flat — which on a name is not a cosmetic
        // imperfection, it is the name spelled wrong.
        $y = $top + $this->ascent($lines[0] ?? '', $size, self::font('display'));
        foreach ($lines as $line) {
            $this->text($im, $line, $size, self::font('display'), $white, $cx, $y);
            $y += $lineH;
        }
        $nameBottom = $y - $lineH;   // baseline of the last line

        // Category + country.
        $meta = $f['category'] . ($f['country'] !== '' ? '  ·  ' . $f['country'] : '');
        $this->centredFitLeft($im, $meta, 30, self::font('regular'), $green, $cx, $nameBottom + 46, $cw);

        // Standing line + progress track. Omitted together, so the card never shows an
        // empty rail — the same rule png() follows.
        if ($ranked) {
            $this->centredFitLeft($im, (string) $f['headline'], 30, self::font('semibold'), $mint, $cx, $nameBottom + 96, $cw);
            $this->pill($im, $cx, $nameBottom + 116, $cw, 12, $rgb(46, 78, 68));
            $fill = max(12, (int) round(($cw * (int) $s['progress_pct']) / 100));
            $this->pill($im, $cx, $nameBottom + 116, $fill, 12, $green);
        }

        // The URL, anchored to the bottom rather than to the cursor above it, so a
        // two-line name cannot push it off the card.
        $this->pill($im, $cx, $H - 122, $cw, 66, $white);
        $this->centredFit($im, (string) $f['short_url'], 30, self::font('bold'), $ink, $cx + $cw / 2, $H - 79, $cw - 40);

        // The honest footnote. Same reason as the flier: a rank reads as a result, and
        // these awards are 55% independent jury.
        $this->centredFit($im, 'Public votes are one part of the score. An independent jury decides the award.',
            18, self::font('regular'), $faint, $cx + $cw / 2, $H - 26, $cw);

        ob_start();
        imagepng($im, null, 6);
        $out = (string) ob_get_clean();
        imagedestroy($im);

        return $out !== '' ? $out : null;
    }

    // ── GD helpers. Small, named, and shared by png() only. ──────────────────

    /** Draw text with an optional letter-spacing, which imagettftext has no concept of. */
    private function text($im, string $s, int $size, string $font, int $colour, float $x, float $y, float $tracking = 0): void
    {
        if ($tracking <= 0) {
            imagettftext($im, $size, 0, (int) round($x), (int) round($y), $colour, $font, $s);
            return;
        }
        // Per-character, because the kicker's letter-spacing is a real part of the design
        // and GD cannot express it. Only used on two short strings.
        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            imagettftext($im, $size, 0, (int) round($x), (int) round($y), $colour, $font, $ch);
            $x += $this->width($ch, $size, $font) + $tracking;
        }
    }

    /** Advance width of a string at a size, from FreeType's own metrics. */
    private function width(string $s, int $size, string $font): float
    {
        $b = imagettfbbox($size, 0, $font, $s);
        return $b === false ? 0.0 : (float) ($b[2] - $b[0]);
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

    /**
     * Word wrap by MEASURED width, not character count.
     *
     * The SVG wraps by character count because it cannot measure; here the real metrics
     * are available, so a name in a wide face and a rally line in a narrow one each break
     * where they actually run out of room.
     *
     * @return list<string>
     */
    private function wrapMeasured(string $text, float $maxW, int $size, string $font, int $maxLines): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = []; $cur = '';
        foreach ($words as $w) {
            $try = $cur === '' ? $w : $cur . ' ' . $w;
            if ($this->width($try, $size, $font) <= $maxW || $cur === '') { $cur = $try; continue; }
            if (count($lines) + 1 >= $maxLines) {
                while ($cur !== '' && $this->width($cur . '…', $size, $font) > $maxW) $cur = mb_substr($cur, 0, -1);
                // Trim before appending: dropping characters can land on a space, and
                // "Adébáyọ …" reads as a rendering fault where "Adébáyọ…" reads as
                // deliberate truncation.
                $cur = rtrim($cur) . '…';
                break;
            }
            $lines[] = $cur; $cur = $w;
        }
        if ($cur !== '') $lines[] = $cur;
        return $lines;
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

    /** Vertical linear gradient, drawn a scanline at a time. */
    private function vGradient($im, int $x, int $y, int $w, int $h, array $from, array $to): void
    {
        for ($i = 0; $i < $h; $i++) {
            $t = $h > 1 ? $i / ($h - 1) : 0;
            $c = imagecolorallocate($im,
                (int) round($from[0] + ($to[0] - $from[0]) * $t),
                (int) round($from[1] + ($to[1] - $from[1]) * $t),
                (int) round($from[2] + ($to[2] - $from[2]) * $t));
            imageline($im, $x, $y + $i, $x + $w - 1, $y + $i, $c);
        }
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
     * The vertical anchor when a photo is taller than the box it must fill.
     *
     * 0 keeps the top, 0.5 the middle, 1 the bottom. 0.22 is a deliberate upper bias:
     * across submitted portraits — a phone photo, a headshot, a stage shot — the face
     * sits in the upper third, and 0.5 crops to the chest. Photographic composition puts
     * the eyes near the upper third line, so anchoring a little above centre keeps the
     * head in frame on a portrait while still including the shoulders.
     *
     * It is a heuristic and it is not face detection. A photo that is already the box's
     * aspect ratio is unaffected (there is nothing to crop), and a Cloudinary-hosted
     * photo never reaches this code path because it arrives pre-cropped on the detected
     * face — see {@see photoUrl()}. This is the honest best available for a local file
     * on a host with GD and nothing else.
     */
    private const PHOTO_ANCHOR_Y = 0.22;

    /** Draw $src to cover the box, cropping the overflow — never stretching it. */
    private function cover($im, $src, int $dx, int $dy, int $dw, int $dh): void
    {
        $sw = imagesx($src); $sh = imagesy($src);
        if ($sw < 1 || $sh < 1) return;
        // A squashed face is the most obvious tell that a graphic was generated.
        $scale = max($dw / $sw, $dh / $sh);
        $cw = (int) round($dw / $scale);
        $ch = (int) round($dh / $scale);
        // Horizontally centred (a subject is reliably centred left-to-right); vertically
        // biased upward, because they are reliably NOT centred top-to-bottom.
        $sx = (int) round(($sw - $cw) / 2);
        $sy = (int) round(($sh - $ch) * self::PHOTO_ANCHOR_Y);
        imagecopyresampled($im, $src, $dx, $dy, max(0, $sx), max(0, $sy), $dw, $dh, $cw, $ch);
    }

    /**
     * Read the nominee photo from disk or over HTTP.
     *
     * Local first: the photo is almost always an upload under public/, and reading it
     * from the filesystem avoids a request the server makes to itself — which on a
     * single-worker PHP server is a deadlock, not a slow path.
     */
    private function loadPhoto(string $url): mixed
    {
        $root = dirname(__DIR__, 2) . '/public';
        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && !str_contains($path, '..')) {
            $local = $root . rawurldecode($path);
            if (is_file($local)) {
                $im = @imagecreatefromstring((string) @file_get_contents($local));
                if ($im !== false) return $im;
            }
        }
        // Remote (a CDN-hosted photo). Short timeout: a slow photo host must not hold
        // an OG-image request open, because a crawler will simply give up and show none.
        $ctx = stream_context_create(['http' => ['timeout' => 4, 'follow_location' => 0]]);
        $data = @file_get_contents($url, false, $ctx);
        if (!is_string($data) || $data === '') return null;
        $im = @imagecreatefromstring($data);
        return $im !== false ? $im : null;
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
