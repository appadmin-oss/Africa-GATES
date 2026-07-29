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
            'photo'     => $this->photoUrl((string) ($n->photo_path ?? ''), $base),
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

    /** Absolute URL for the nominee photo, or null when there is none. */
    private function photoUrl(string $path, string $base): ?string
    {
        $p = trim($path);
        if ($p === '') return null;
        if (preg_match('~^https?://~i', $p)) return $p;
        return $base . '/' . ltrim($p, '/');
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
        $out .= '<clipPath id="photoClip"><rect x="0" y="0" width="' . $W . '" height="820"/></clipPath>';
        $out .= '</defs>';

        $out .= '<rect width="' . $W . '" height="' . $H . '" fill="url(#bg)"/>';

        // Photo, or a monogram when there is none. A blank rectangle where a face
        // should be is the difference between a flier someone posts and one they do not.
        if ($f['photo'] !== null) {
            $out .= '<g clip-path="url(#photoClip)">'
                  . '<image href="' . $this->x($f['photo']) . '" xlink:href="' . $this->x($f['photo']) . '" '
                  . 'x="0" y="0" width="' . $W . '" height="820" preserveAspectRatio="xMidYMid slice"/>'
                  . '</g>';
        } else {
            $initials = $this->initials($name);
            $out .= '<rect x="0" y="0" width="' . $W . '" height="820" fill="#0f3329"/>';
            $out .= '<text x="' . ($W / 2) . '" y="500" text-anchor="middle" '
                  . 'font-family="Playfair Display, Georgia, serif" font-size="260" font-weight="700" '
                  . 'fill="#7fc87c" fill-opacity="0.28">' . $this->x($initials) . '</text>';
        }
        $out .= '<rect x="0" y="0" width="' . $W . '" height="820" fill="url(#scrim)"/>';

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

        $W = self::W; $H = self::H; $PH = 820;
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

        imagefilledrectangle($im, 64, 64, 69, 116, $gold);
        $this->text($im, 'VOTE NOW', 26, self::font('bold'), $white, 88, 92, 4);
        $this->text($im, (string) $f['programme'], 22, self::font('regular'), $muted, 88, 126, 1);

        $s = $f['standing'];
        if ((int) ($s['field'] ?? 0) >= 2) {
            $chip = '#' . $s['rank'] . ' of ' . $s['field'];
            $cw   = $this->width($chip, 28, self::font('bold')) + 44;
            $this->pill($im, $W - 64 - $cw, 62, $cw, 58, $gold);
            $this->centred($im, $chip, 28, self::font('bold'), $goldInk, $W - 64 - $cw / 2, 100);
        }

        $name = (string) $f['name'];
        $size = mb_strlen($name) <= 14 ? 96 : (mb_strlen($name) <= 22 ? 78 : (mb_strlen($name) <= 32 ? 62 : 50));
        $lines = $this->wrapMeasured($name, $W - 128, $size, self::font('display'), 2);
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
                $cur .= '…';
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

    /** Draw $src to cover the box, cropping the overflow — never stretching it. */
    private function cover($im, $src, int $dx, int $dy, int $dw, int $dh): void
    {
        $sw = imagesx($src); $sh = imagesy($src);
        if ($sw < 1 || $sh < 1) return;
        // A squashed face is the most obvious tell that a graphic was generated.
        $scale = max($dw / $sw, $dh / $sh);
        $cw = (int) round($dw / $scale);
        $ch = (int) round($dh / $scale);
        imagecopyresampled($im, $src, $dx, $dy, (int) (($sw - $cw) / 2), (int) (($sh - $ch) / 2), $dw, $dh, $cw, $ch);
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
