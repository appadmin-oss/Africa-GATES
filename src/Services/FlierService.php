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
                // Same gate as the ballot this card links to. Without it the sandbox had
                // a share card: a real PNG of a "DEMO —" nominee, served to any crawler
                // that was handed the URL, on the one surface whose whole job is to be
                // reposted. See VoteController::nomineeBallot() for the full account.
                ->where('p.is_active', 1)
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
     * The share card as SVG — viewing, the print download, and the fallback when GD is
     * missing. The design and every rule on it live in {@see ShareCardSpec}; the drawing
     * in {@see ShareCardRenderer}, which serialises ONE layout plan as SVG or PNG, so the
     * two encodings cannot drift apart the way this class's history records.
     *
     * @param array<string,mixed> $f   from forNominee()
     * @param array<string,mixed> $opt ShareCardSpec::options() — size, style, tone, mode
     */
    public function svg(array $f, array $opt = []): string
    {
        return $this->renderer()->svg(ShareCardSpec::for($f, $opt));
    }

    /** The card's spec for these options — what the share-kit page shows beside it. */
    public function card(array $f, array $opt = []): array
    {
        return ShareCardSpec::for($f, $opt);
    }

    private function renderer(): ShareCardRenderer
    {
        $base = rtrim((string) Env::get('APP_URL', ''), '/') ?: 'https://afg.afrovanguard.org.ng';
        return new ShareCardRenderer($base);
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
     * The share card as PNG, through GD — what a nominee posts and what the share sheet
     * attaches. Null when GD or a bundled face is missing, so the caller can fall back
     * to the SVG rather than serve a broken image.
     *
     * @param array<string,mixed> $opt ShareCardSpec::options()
     */
    public function png(array $f, array $opt = []): ?string
    {
        if (!function_exists('imagecreatetruecolor') || !self::fontsPresent()['ok']) return null;
        return $this->renderer()->png(ShareCardSpec::for($f, $opt));
    }

    /**
     * The 1200×630 link-preview card: the share card in its `link` size. Every platform
     * crops an og:image to 1.91:1 or squarer, so the portrait flier would lose its vote
     * line in exactly the place the sharing happens.
     */
    public function ogCard(array $f, array $opt = []): ?string
    {
        return $this->png($f, ['format' => 'link'] + $opt);
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


    // pill() moved to FlierRaster — see the note there.

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
