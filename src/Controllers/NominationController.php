<?php
declare(strict_types=1);
namespace AfricaGates\Controllers;
use AfricaGates\Support\Env;
use AfricaGates\Support\Name;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use AfricaGates\Services\{CacheService,AwardService,RateLimitService,GoogleSheetsService,CommunityService,OtpService,Notifier};

class NominationController {
    public function __construct(
        private readonly Twig $view,
        private readonly CacheService $cache,
        private readonly AwardService $awards,
        private readonly RateLimitService $rateLimit,
        private readonly ?GoogleSheetsService $sheets = null,
        private readonly ?CommunityService $community = null,
        private readonly ?OtpService $mailer = null
    ){}

    /**
     * The programmes the wizard may offer, from the POLICY's predicate.
     *
     * Two things were wrong with the previous form. It matched the phase LABEL —
     * `in_array($p['cycle_status'], ['nominations'])` — which is a second
     * implementation of CyclePhase::isNominationsOpen() and would diverge the moment
     * another phase accepts nominations or a label changes; the symptom would be F7
     * again, the wizard offering programmes it should not or hiding ones it should.
     * And it appeared TWICE, in `form()` and in the POST re-render, so the list a
     * user saw after a validation error was derived independently of the one they
     * first saw. One helper, one predicate.
     *
     * @param list<array<string,mixed>> $progs
     * @return list<array<string,mixed>>
     */
    private static function openForNominations(array $progs): array
    {
        return array_values(array_filter(
            $progs,
            static fn (array $p): bool => !empty($p['phase']['is_nominations_open'])
        ));
    }
    /**
     * One award's own nomination page — `/nominate/{slug}`.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY EVERY AWARD HAS ITS OWN DOOR
     * ══════════════════════════════════════════════════════════════════════════
     *
     * `/nominate` asks which award first, which is the right question when somebody
     * arrives wanting to nominate. It is the wrong question for everybody else: the
     * award's own page, a poster, a WhatsApp forward and a closing-soon email all
     * point at ONE award, and landing those on a chooser asks a person to re-answer
     * something they had already decided. This is that page, with the award fixed.
     *
     * It is the same flow and the same POST — one door, as `/account/register`'s
     * note argues at length about registration. Two submit paths for one thing is
     * this codebase's oldest fault wearing a different face.
     *
     * ── AN AWARD THAT IS NOT TAKING NOMINATIONS STILL ANSWERS ────────────────
     *
     * 200 with the reason, not a 404 and not a silent bounce to the chooser. Whoever
     * follows a link on the day is exactly the person owed the explanation, and a
     * 404 reads as the award having been taken down — the same reasoning
     * `PublicResults::delayed()` spells out for a late result. The page says which
     * phase it is in and when nominations open or closed.
     */
    public function award(Request $req, Response $res, array $args): Response
    {
        $slug  = strtolower(trim((string) ($args['slug'] ?? '')));
        $progs = $this->cache->remember('awards:active', 1800, fn () => $this->awards->getActiveProgrammesWithStatus());

        $prog = null;
        foreach ($progs as $p) {
            if (strtolower((string) $p['slug']) === $slug) { $prog = $p; break; }
        }

        // An award that does not exist is a 404; an award that exists and is closed is
        // a page. The two are different facts and a visitor can act on the second.
        if ($prog === null) return $res->withStatus(404);

        $open = !empty($prog['phase']['is_nominations_open']);

        // A shared prefill link — `NominationLinkService`. The nominee-side fields land
        // in the form, fully editable; whoever opens it still submits their own
        // nomination in their own name. Resolved HERE as well as on the hub because a
        // link can be forwarded straight to this page.
        $prefill = null;
        $share   = trim((string) ($req->getQueryParams()['share'] ?? ''));
        if ($share !== '') {
            try { $prefill = (new \AfricaGates\Services\NominationLinkService())->resolve($share); }
            catch (\Throwable) { $prefill = null; }
        }

        $name = trim((string) ($req->getQueryParams()['name'] ?? ''));

        return $this->view->render($res, 'pages/nominate-award.twig', $this->flowContext($prog, [
            'page_title'       => ($open ? 'Nominate for ' : '') . $prog['title'] . ' — Africa GATES',
            'meta_description' => $open
                ? 'Put someone forward for the ' . $prog['title'] . ' on Africa GATES.'
                : $prog['title'] . ' on Africa GATES — nominations are not open right now.',
            // The hub's "Who do you want to nominate?" travels here as `?name=`, so what was
            // typed there is the first field here rather than typed twice.
            'old'              => $name !== '' ? ['nominee_name' => mb_substr($name, 0, 200)] : [],
            'prefill'          => $prefill,
            'share_expired'    => $share !== '' && $prefill === null,
        ]));
    }

    /**
     * Everything the flow draws for one award, for the GET and for the error re-render
     * alike — two copies of this list is how a re-render comes to draw a form the first
     * render did not (and a counter that disagrees with the refusal beside it).
     *
     * @param array<string,mixed> $prog
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function flowContext(array $prog, array $extra): array
    {
        $accepts = $prog['wording']['accepts'] ?? ['person'];
        $member  = \AfricaGates\Services\UserAccountService::memberForForms();
        return $extra + [
            'gates_page'       => 'nominate',
            'programme'        => $prog,
            'wording'          => $prog['wording'],
            'edition'          => self::editionLine($prog),
            'nominations_open' => !empty($prog['phase']['is_nominations_open']),
            'kinds'            => array_values(array_filter(\AfricaGates\Support\NomineeKind::options(),
                                    static fn (array $k): bool => in_array($k['kind'], $accepts, true))),
            'name_hints'       => array_map(static fn (array $k): array => [
                                    'label' => $k['name_label'], 'hint' => $k['name_hint']], \AfricaGates\Support\NomineeKind::ALL),
            'rules'            => self::ruleBundle(),
            'relations'        => \AfricaGates\Services\NominationRules::RELATIONS,
            'countries'        => self::countries(),
            'member'           => $member,
            'live'             => self::happening((int) ($prog['id'] ?? 0)),
            'old'              => $extra['old'] ?? [],
            'error'            => $extra['error'] ?? null,
            'prefill'          => $extra['prefill'] ?? null,
            'share_expired'    => $extra['share_expired'] ?? false,
            // "Counts toward Celebrate Nigeria · 6/10", for a member who has JOINED a
            // challenge this award counts inside. On a form somebody is halfway through it
            // is a progress line and not an advertisement, so nobody else sees it here.
            'challenge'        => (static function (?array $s): ?array {
                return $s !== null && $s['joined'] ? $s : null;
            })(\AfricaGates\Services\ChallengeService::stripFor(
                (int) ($prog['id'] ?? 0), (int) ($_SESSION['user_id'] ?? 0))),
        ];
    }

    /** "4th Edition · 2026" — the cycle's own label where an operator wrote one. */
    private static function editionLine(array $prog): string
    {
        $label = '';
        try {
            $label = (string) (\Illuminate\Database\Capsule\Manager::table('gates_award_cycles')
                ->where('id', (int) ($prog['cycle_id'] ?? 0))->value('edition_label') ?? '');
        } catch (\Throwable) {}
        return trim($label . ($label !== '' ? ' · ' : '') . (string) ($prog['year'] ?? ''), ' ·');
    }

    /** Every country a nominee may be from, ISO code => name, from the one table of names. */
    private static function countries(): array
    {
        $out = [];
        foreach (array_keys(\AfricaGates\Support\Regions::MAP) as $cc) $out[$cc] = \AfricaGates\Support\NationsLive::name((string) $cc);
        asort($out);
        return $out;
    }

    /**
     * "Happening now" — the newest nominations in LIVE awards: the category and the country
     * the nominator gave, never a name. Reached only through an active programme, so the
     * sandbox (an inactive programme) cannot appear (DemoSeeder's containment is the chain).
     *
     * @return list<array{what:string,where:string,at:string}>
     */
    private static function happening(int $programmeId = 0): array
    {
        try {
            $q = \Illuminate\Database\Capsule\Manager::table('gates_nominations as n')
                ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->join('gates_award_cycles as y', 'y.id', '=', 'c.cycle_id')
                ->join('gates_award_programmes as p', 'p.id', '=', 'y.programme_id')
                ->where('p.is_active', 1);
            if ($programmeId > 0) $q->where('p.id', $programmeId);
            $rows = $q->orderByDesc('n.id')->limit(4)
                ->get(['c.title', 'n.nominator_country', 'n.country_code', 'n.created_at'])->all();
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $cc = strtoupper((string) ($r->nominator_country ?: $r->country_code ?: ''));
            $out[] = ['what' => (string) $r->title,
                      'where' => $cc !== '' ? \AfricaGates\Support\NationsLive::name($cc) : '',
                      'at' => (string) $r->created_at];
        }
        return $out;
    }

    /**
     * `/nominate` — the hub. One question: which award?
     *
     * Everything after it belongs to that award's own page, where the words, the
     * categories and the accepted kinds of nominee are its own. This used to BE the
     * whole flow — a five-step wizard over a single shared set of nouns — which is
     * why a form under the heading *Carol Awards* asked a choirmaster for a person's
     * full name.
     *
     * ── A SHARE LINK IS ALREADY ABOUT ONE AWARD ─────────────────────────────
     *
     * `/nominate?share=<token>` is minted by `NominationLinkService` and carries the
     * programme in its payload, so it lands on the chooser having already chosen.
     * It is redirected to that award's page with the token intact — one token, one
     * service, resolved in one place. Asking somebody to pick the award a link
     * already named is the thing this hub exists to stop doing.
     */
    public function form(Request $req, Response $res): Response
    {
        $progs = $this->cache->remember('awards:active', 1800, fn () => $this->awards->getActiveProgrammesWithStatus());

        $share = trim((string) ($req->getQueryParams()['share'] ?? ''));
        if ($share !== '') {
            $prefill = null;
            try { $prefill = (new \AfricaGates\Services\NominationLinkService())->resolve($share); }
            catch (\Throwable) { $prefill = null; }

            $wanted = (int) ($prefill['programme_id'] ?? 0);
            foreach ($progs as $p) {
                if ((int) $p['id'] !== $wanted) continue;
                // 302 and not 301: this address is the hub, and it is the hub again the
                // moment the token expires. A permanent redirect would teach every cache
                // on the way that `/nominate` IS that award.
                return $res->withHeader('Location', '/nominate/' . $p['slug'] . '?share=' . rawurlencode($share))
                           ->withStatus(302);
            }

            // The token is dead or names an award that has gone. The chooser says so
            // rather than silently behaving as though no link had been followed.
            return $this->render($res, $progs, true);
        }

        return $this->render($res, $progs, false, $req->getQueryParams());
    }

    /**
     * The hub, with the awards split by whether they are taking nominations.
     *
     * BOTH lists are rendered and both are linked. An award somebody came here for is
     * a page they are owed — it says which phase it is in and when the window opens —
     * and a row that cannot be pressed answers nothing.
     *
     * @param list<array<string,mixed>> $progs
     */
    private function render(Response $res, array $progs, bool $shareExpired, array $q = []): Response
    {
        $open = self::openForNominations($progs);
        $openIds = array_map(static fn (array $p): int => (int) $p['id'], $open);

        // Awards in the member's areas first (MemberInterests — the promise the joining
        // step makes), then soonest to close: a reorder, never a filter.
        $closesAt = static fn (array $p): int => ($t = strtotime((string) ($p['nominations_close'] ?? ''))) ? $t : PHP_INT_MAX;
        usort($open, static fn ($a, $b) => $closesAt($a) <=> $closesAt($b));
        $about = static fn (array $p): string => $p['title'] . ' ' . implode(' ',
            array_map(static fn ($c) => (string) ($c->title ?? ''), (array) ($p['categories'] ?? [])));
        $mine = \AfricaGates\Services\MemberInterests::of((int) ($_SESSION['user_id'] ?? 0));
        $open = \AfricaGates\Services\MemberInterests::rank($open, $about, $mine);

        // Browse by field: the same ten areas, each with how many open awards it touches.
        $field  = (string) ($q['field'] ?? '');
        $fields = [];
        foreach (\AfricaGates\Services\MemberInterests::options() as $o) {
            $n = count(array_filter($open, static fn ($p) => \AfricaGates\Services\MemberInterests::matches($about($p), [$o['key']])));
            if ($n > 0) $fields[] = $o + ['n' => $n];
        }
        $shown = isset(\AfricaGates\Services\MemberInterests::FIELDS[$field])
            ? array_values(array_filter($open, static fn ($p) => \AfricaGates\Services\MemberInterests::matches($about($p), [$field])))
            : $open;
        foreach ($shown as &$p) $p['edition_line'] = self::editionLine($p);
        unset($p);

        $name = mb_substr(trim((string) ($q['name'] ?? '')), 0, 200);
        $user = \AfricaGates\Services\UserAccountService::memberForForms();

        return $this->view->render($res, 'pages/nominate.twig', [
            'page_title'       => 'Nominate — Africa GATES',
            'meta_description' => 'Put someone forward for continental recognition. Choose the '
                                . 'Africa GATES award you are nominating for.',
            'gates_page'       => 'nominate',
            'programmes'       => $shown,
            'open_count'       => count($open),
            'show_all'         => !empty($q['all']),
            'field'            => isset(\AfricaGates\Services\MemberInterests::FIELDS[$field]) ? $field : '',
            'fields'           => $fields,
            'name'             => $name,
            'matches'          => $name !== '' ? self::matches($name) : [],
            'mine'             => $user ? \AfricaGates\Services\MemberActivityService::nominationsFor((string) $user['email'], 5) : [],
            'live'             => self::happening(),
            'closed'           => array_values(array_filter(
                $progs,
                static fn (array $p): bool => !in_array((int) $p['id'], $openIds, true)
            )),
            'share_expired'    => $shareExpired,
        ]);
    }

    /**
     * "Or is it someone already here?" — approved public registry profiles whose name holds
     * what was typed. The wildcards are defused (Support\Like), so `100%` is not "everybody".
     *
     * @return list<array{name:string,slug:string,meta:string,kind:string}>
     */
    private static function matches(string $name): array
    {
        if (mb_strlen($name) < 2) return [];
        try {
            $rows = \Illuminate\Database\Capsule\Manager::table('gates_profiles')->where('status', 'approved')
                ->whereRaw(\AfricaGates\Support\Like::clause('display_name'), [\AfricaGates\Support\Like::contains($name)])
                ->orderBy('display_name')->limit(3)
                ->get(['display_name', 'slug', 'category', 'location_city', 'country_code', 'profile_type'])->all();
        } catch (\Throwable) {
            return [];
        }
        return array_map(static fn ($r) => [
            'name' => (string) $r->display_name, 'slug' => (string) $r->slug,
            'meta' => trim(implode(' · ', array_filter([(string) $r->category,
                        trim((string) $r->location_city . ', ' . \AfricaGates\Support\NationsLive::name((string) $r->country_code), ', ')]))),
            'kind' => (string) $r->profile_type === 'individual' ? 'person' : 'org',
        ], $rows);
    }

    /**
     * The numbers and sentences the form draws, straight from `NominationRules`.
     *
     * ONE method, called by the GET and by the error re-render. Two copies of this
     * array is two chances for a page to state a rule the server does not hold — and
     * the re-render is exactly the render where somebody is already looking at a
     * refusal, so a counter disagreeing with it there is the worst place for it.
     *
     * `max_file_mb` is derived here rather than in Twig: `constant()` in a template
     * spells a class name inside a string, which no editor renames and no sweep finds.
     *
     * @return array<string,int|string>
     */
    private static function ruleBundle(): array
    {
        $R = \AfricaGates\Services\NominationRules::class;

        return [
            'min_categories' => $R::MIN_CATEGORIES,
            'max_categories' => $R::MAX_CATEGORIES,
            'min_reason'     => $R::MIN_REASON,
            'max_reason'     => $R::MAX_REASON,
            'max_evidence'   => $R::MAX_EVIDENCE,
            'short_reason'   => $R::SHORT_REASON,
            'max_file_mb'    => (int) round($R::MAX_FILE_BYTES / 1048576),
        ];
    }

    /**
     * The evidence files on this request, ready to store — or none.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * THIS WAS CALLED AND NEVER DEFINED, AND IT WAS A 500 ON THE SUBMIT PATH
     * ══════════════════════════════════════════════════════════════════════════
     *
     * `submit()` has looped over `self::uploadedEvidence($req)` since the evidence
     * table shipped. The method did not exist, so every nomination carrying a file
     * died with `Call to undefined method` AFTER the row was written — the nomination
     * was saved, the nominator got a 500, and the only honest thing they could
     * conclude was that it had not gone through.
     *
     * Nothing caught it because nothing posted a file through this controller: the
     * suite exercised `AwardService::recordEvidenceFile()` directly, which is the
     * half that worked. A unit test of the piece below the fault is not a test of the
     * path. {@see \Tests\Unit\NominationUploadTest}.
     *
     * ── WHAT IT HAS TO GET RIGHT ─────────────────────────────────────────────
     *
     * `evidence[]` arrives as a LIST under one key, and a single `evidence` field
     * would arrive as one object — both shapes are normalised here so a change to the
     * form's field name cannot produce a silent no-upload.
     *
     * An empty file input posts with `UPLOAD_ERR_NO_FILE`, which is not an error to
     * report: it is what a form looks like when somebody attached nothing.
     *
     * The cap is the rules' own. Taking the first N rather than refusing the lot is
     * deliberate — the links are already capped and validated by then, and discarding
     * a whole nomination over a sixth attachment is a worse answer than keeping five.
     *
     * @return list<\Psr\Http\Message\UploadedFileInterface>
     */
    private static function uploadedEvidence(Request $req): array
    {
        $raw = $req->getUploadedFiles()['evidence'] ?? null;
        if ($raw === null) return [];
        if (!is_array($raw)) $raw = [$raw];

        $out = [];
        foreach ($raw as $f) {
            if (!$f instanceof \Psr\Http\Message\UploadedFileInterface) continue;
            // UPLOAD_ERR_NO_FILE is an empty input, not a failure.
            if ($f->getError() !== UPLOAD_ERR_OK) continue;
            if ((int) $f->getSize() <= 0) continue;
            $out[] = $f;
            if (count($out) >= \AfricaGates\Services\NominationRules::MAX_EVIDENCE) break;
        }

        return $out;
    }

    public function submit(Request $req,Response $res):Response {
        $b=(array)$req->getParsedBody(); $ip=$req->getServerParams()['REMOTE_ADDR']??''; $fp=hash('sha256',$ip.strtolower(trim($b['nominator_email']??'')));
        // Real programme data for any error re-render, so the form never falls back
        // to stale hardcoded categories (which could misfile a nomination). `old`
        // feeds every typed value back into the wizard so a server-side rejection
        // never wipes the form. Defined up-front so the rate-limit path re-renders
        // the form too (never a false "success" page).
        // ── A REFUSAL RE-RENDERS THE AWARD'S OWN PAGE ───────────────────────
        //
        // Not the chooser. Somebody filling in the Incredible Principal Awards form who
        // writes a 39-character reason was being sent back to "which award?" with every
        // field they had typed gone — the page they landed on does not even have those
        // inputs. `old` only works if the form it feeds is the form they were on.
        //
        // The programme is resolved from what they posted, so the re-render is that
        // award's page with that award's wording, categories and accepted kinds.
        $progs=$this->cache->remember('awards:active',1800,fn()=>$this->awards->getActiveProgrammesWithStatus());
        $prog=null;
        foreach($progs as $p){ if((int)$p['id']===(int)($b['programme_id']??0)){ $prog=$p; break; } }

        $rerender=function(string $msg,int $status=422) use ($res,$b,$prog,$progs){
            // A post with no recognisable programme cannot be re-rendered as a form —
            // there is no award to draw. That is the chooser's job, and it says why.
            if($prog===null){
                return $this->render($res->withStatus($status), $progs, false);
            }
            return $this->view->render($res,'pages/nominate-award.twig',$this->flowContext($prog,[
                'page_title'=>'Nominate for '.$prog['title'].' — Africa GATES',
                'error'=>$msg,'old'=>$b,
            ]))->withStatus($status);
        };
        if(!$this->rateLimit->check($fp,'nominate',5,86400)) return $rerender("You've reached today's nomination limit (5 per day). Please try again tomorrow.",429);
        // ── THE FIELDS THIS DOOR ASKS FOR ───────────────────────────────────
        //
        // `reason` and `nominee_name` are NOT in this list any more, and that is the
        // point: they are the two fields the API door disagreed with this one about,
        // and they belong to NominationRules now — which AwardService runs for both
        // doors. What stays here is what only a browser form collects (the location
        // and contact fields an operator needs), stated once.
        //
        // `Support\NomineeKind` owns the two-words rule, because it is not one rule:
        // a person needs a first and last name and "Andela" is a whole registered
        // name. Demanding a second word of an organisation is the stricter-in-the-
        // browser-than-on-the-server shape this codebase has already paid for.
        // ── WHO IS NOMINATING ────────────────────────────────────────────────
        //
        // A signed-in member is the nominator — "Signed in as" (NominationFlow.dc.html
        // step 5) — so their name, address and number come from the ACCOUNT, never from
        // fields a stranger could edit to file in somebody else's name. A guest types the
        // three. The location and age fields the old form demanded are not in the design
        // and are not asked; the columns stay, written blank, for the rows that have them.
        $member = \AfricaGates\Services\UserAccountService::memberForForms();
        if ($member) {
            $b['nominator_name']  = $member['name'];
            $b['nominator_email'] = $member['email'];
            $b['nominator_phone'] = $member['phone'];
        }
        // "Their email or phone" is ONE field in the design; it is split here into the two
        // the record has. An @ makes it an address; anything else is read as a number.
        if (isset($b['nominee_contact']) && !isset($b['nominee_email']) && !isset($b['nominee_phone'])) {
            $c = trim((string) $b['nominee_contact']);
            if (str_contains($c, '@')) $b['nominee_email'] = $c; else $b['nominee_phone'] = $c;
        }
        $required = [
            'programme_id'=>'a programme', 'country_code'=>"the nominee's country",
            'nominator_name'=>'your full name', 'nominator_email'=>'your email',
        ];
        foreach ($required as $f=>$lbl) if (trim((string)($b[$f] ?? '')) === '') return $rerender('Please provide ' . $lbl . '.');
        if (count(preg_split('/\s+/', trim((string)($b['nominator_name'] ?? '')))) < 2)
            return $rerender('Please enter your full name — first and last name.');
        if (!filter_var(strtolower(trim((string)$b['nominator_email'])), FILTER_VALIDATE_EMAIL)) return $rerender('Please enter a valid email address.');
        // "I confirm this nomination is genuine and accept the award terms." It was a
        // required box the server never read (GAPS §3.2) — a consent nothing checks is a
        // sentence, not a consent.
        if (empty($b['consent'])) return $rerender('Please confirm the nomination is genuine and that you accept the award terms.');
        // Nominee contact: EMAIL OR PHONE — at least one is required; anything the
        // nominator actually typed must validate (never silently dropped).
        $neRaw = strtolower(trim((string)($b['nominee_email'] ?? '')));
        $npRaw = trim((string)($b['nominee_phone'] ?? ''));
        if ($neRaw === '' && $npRaw === '') return $rerender("Please provide the nominee's email address or phone number — at least one is required.");
        if ($neRaw !== '' && !filter_var($neRaw, FILTER_VALIDATE_EMAIL)) return $rerender("Please enter a valid email address for the nominee.");
        if ($npRaw !== '' && \AfricaGates\Support\Phone::normalize($npRaw, (string)($b['country_code'] ?? '')) === null) return $rerender("Please enter a valid phone number for the nominee — include the country code (e.g. +234 803 123 4567).");

        // Optional nominee portrait — validated image (re-encoded + size-capped) stored
        // BEFORE the insert so its path lands on the nomination row for moderators to
        // review and, on approval, seed the profile avatar. Failure never blocks the
        // nomination — the photo is optional.
        $photoNote = '';
        $photo = $req->getUploadedFiles()['nominee_photo'] ?? null;
        if ($photo instanceof \Psr\Http\Message\UploadedFileInterface && $photo->getError() === UPLOAD_ERR_OK && $photo->getSize() > 0) {
            try {
                $pic = (new \AfricaGates\Admin\Services\UploadService())->uploadImage($photo, 'nominations', 1200, 82, null, 'nomination', null, 200);
                $b['nominee_photo_path'] = $pic['path'];
                $photoNote = "\n\nPhoto: " . $pic['path'];
            } catch (\Throwable $e) {
                $photoNote = "\n\nPhoto: (attachment rejected — " . $e->getMessage() . ")";
            }
        }
        // Our own refusals here are written for the person filling the form ("that
        // category has closed") and must reach them verbatim — a generic error on a
        // nomination form is how somebody gives up on entering. Anything that is not
        // ours becomes a next step and a reference. See Support\PublicFault.
        try{ $nominationId = $this->awards->submitNomination($b,$ip); }
        catch(\RuntimeException $e){ return $rerender(\AfricaGates\Support\PublicFault::line($e,
            'We could not record that nomination. Nothing was saved, so trying once more is safe.',
            'nomination submit')); }

        // ── SUPPORTING FILES ────────────────────────────────────────────────
        //
        // These used to be uploaded and then have their URL put into a STRING IN AN
        // EMAIL. There was no column, nothing on the review desk showed one, and the
        // AI triage — whose whole job is to help a moderator judge a nomination — had
        // never seen a single piece of evidence in the platform's history. The file
        // sat on disk, reachable only by whoever still had the alert in their inbox.
        //
        // They are rows now. The note is still built, because the operator alert is
        // genuinely useful, but it is no longer the only place the evidence exists.
        $evidenceNote = '';
        $stored = [];
        foreach (self::uploadedEvidence($req) as $file) {
            try {
                $up = (new \AfricaGates\Admin\Services\UploadService())->uploadDocument(
                    $file, 'nominations', (int) ceil(\AfricaGates\Services\NominationRules::MAX_FILE_BYTES / 1048576), null, 'public'
                );
                \AfricaGates\Services\AwardService::recordEvidenceFile(
                    (int) $nominationId, (string) $up['path'] ?? (string) $up['url'],
                    $file->getClientFilename(), $file->getClientMediaType(), $file->getSize()
                );
                $stored[] = (string) $up['url'];
            } catch (\Throwable $e) {
                // Never fatal: the nomination is already saved, and a person who has
                // just submitted must not be shown a 500 that sends them round again.
                $stored[] = '(attachment rejected — ' . $e->getMessage() . ')';
            }
        }
        if ($stored !== []) $evidenceNote = "\n\nEvidence: " . implode(', ', $stored);

        // ── Everything after the insert ──────────────────────────────────────
        //
        // Moved out of this controller, because this was NOT the only door
        // nominations come through — the comment here used to claim it was.
        // POST /api/nominations is a live public endpoint that inserted the row
        // and returned `ok`, so an API nomination told no operator, sent the
        // nominator no confirmation or reference, never notified the nominee,
        // queued no triage and fired no webhook. See NominationAftercare.
        $after = \AfricaGates\Services\NominationAftercare::run(
            $b, (int) $nominationId, \AfricaGates\Support\SiteUrl::base($req), $this->mailer,
            ['evidence' => trim(str_replace("\n\nEvidence: ", '', $evidenceNote)),
             'photo'    => trim(str_replace("\n\nPhoto: ", '', $photoNote))],
            $this->sheets
        );
        $reference = $after['reference'];
        $nomName   = $after['nominee'];
        $catLine   = $after['category'];
        $nomEmail  = $after['nominee_email'];

        // Hand the real reference + names to the success page for one render
        // (server-side flash — keeps sequential IDs out of the URL).
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['nom_done'] = ['ref' => $reference, 'nominee' => $nomName, 'cat' => $catLine,
                // Nominee-side fields only — powers the "invite others to second
                // this nomination" share link on the success page (one render).
                'share' => [
                    'nominee_name'  => $nomName,
                    'nominee_email' => $nomEmail,
                    'nominee_phone' => trim((string)($b['nominee_phone'] ?? '')),
                    'country_code'  => strtoupper((string)($b['country_code'] ?? '')),
                    'nominee_state' => trim((string)($b['nominee_state'] ?? '')),
                    'nominee_lga'   => trim((string)($b['nominee_lga'] ?? '')),
                    'nominee_org'   => trim((string)($b['nominee_org'] ?? '')),
                    'programme_id'  => (int)$b['programme_id'],
                    'category_id'   => (int)($b['category_id'] ?? 0),
                ]];
        }
        return $res->withHeader('Location', '/nominate/success')->withStatus(302);
    }

    /**
     * GET /nominate/success — "Nomination received" (NominationFlow.dc.html step 6, §8.16).
     *
     * One render from the server-side flash `submit()` leaves (`nom_done`), so a reference
     * never sits in a URL and a refresh does not replay the moment. With nothing in the
     * flash it is still a page — somebody arriving here from history is told where their
     * nominations are, never shown a blank celebration.
     *
     * The share link is minted HERE, once, from the nominee-side fields only
     * (NominationLinkService): somebody opening it starts their own nomination of the same
     * person, in their own name — "rally more nominations".
     */
    public function success(Request $req, Response $res): Response
    {
        $d = $_SESSION['nom_done'] ?? null;
        unset($_SESSION['nom_done']);
        $d = is_array($d) ? $d : null;

        $prog = null;
        $shareUrl = '';
        if ($d) {
            $pid = (int) ($d['share']['programme_id'] ?? 0);
            $progs = $this->cache->remember('awards:active', 1800, fn () => $this->awards->getActiveProgrammesWithStatus());
            foreach ($progs as $p) if ((int) $p['id'] === $pid) { $prog = $p; break; }
            if (is_array($d['share'] ?? null)) {
                try {
                    $token = (new \AfricaGates\Services\NominationLinkService())->create($d['share'], (string) ($req->getServerParams()['REMOTE_ADDR'] ?? ''));
                    $shareUrl = \AfricaGates\Support\SiteUrl::base($req) . '/nominate?share=' . $token;
                } catch (\Throwable) {
                    $shareUrl = '';   // the page stands without a link; nothing is pretended
                }
            }
        }

        return $this->view->render($res, 'pages/nominate-success.twig', [
            'page_title'       => 'Nomination received — Africa GATES',
            'meta_description' => 'Your nomination is in. Thank you for putting someone forward for recognition.',
            'meta_robots'      => 'noindex',
            'gates_page'       => 'nominate',
            'done'             => $d !== null,
            'ref'              => (string) ($d['ref'] ?? ''),
            'nominee'          => (string) ($d['nominee'] ?? ''),
            'category'         => (string) ($d['cat'] ?? ''),
            'programme'        => $prog,
            'edition'          => $prog ? self::editionLine($prog) : '',
            'share_url'        => $shareUrl,
            // The review promise, from its one resolver (CLAUDE.md, review_sla_hours).
            'sla_hours'        => \AfricaGates\Services\NominationFeedbackService::slaHours(),
        ]);
    }
}
