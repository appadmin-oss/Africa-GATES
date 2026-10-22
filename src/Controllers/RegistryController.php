<?php
declare(strict_types=1);
namespace AfricaGates\Controllers;

use AfricaGates\Services\{CacheService, CommunityService, GoogleSheetsService, Notifier, OtpService, ProfilePage, ProfileService, RateLimitService};
use AfricaGates\Support\{Assets, Schema, SiteUrl};
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * The registry: one profile per URL (Phase 6, ProfilePage.dc.html, §8.8).
 *
 * `/registry` itself is no longer a page. Discover is the directory (owner-delegated decision,
 * 5 Oct 2026), so the index is a PERMANENT redirect to `/discover?tab=people` carrying its
 * query — a bookmarked search keeps working — UNLESS the URL addresses one profile
 * (`?slug=` or `?profile=`, the shapes old links and shares used), which goes to that profile.
 * Two doors to one directory is the fault the register page's own notes describe.
 */
class RegistryController {
    /** Query keys an old registry URL used to name ONE profile. */
    private const ONE_PROFILE_KEYS = ['slug', 'profile'];

    /** About is free text on a public page; long enough for a paragraph, short enough to read. */
    public const ABOUT_MAX = 1200;

    public function __construct(
        private readonly Twig $view,
        private readonly CacheService $cache,
        private readonly ProfileService $profiles,
        private readonly RateLimitService $rateLimit,
        private readonly ?GoogleSheetsService $sheets = null,
        private readonly ?CommunityService $community = null,
        private readonly ?OtpService $mailer = null
    ){}

    public function index(Request $req, Response $res): Response
    {
        $q = $req->getQueryParams();
        foreach (self::ONE_PROFILE_KEYS as $k) {
            $slug = is_string($q[$k] ?? null) ? trim((string) $q[$k]) : '';
            if ($slug !== '' && preg_match('~^[a-z0-9-]{1,191}$~i', $slug)
                && DB::table('gates_profiles')->where('slug', $slug)->where('status', 'approved')->exists()) {
                return $res->withHeader('Location', '/registry/' . rawurlencode($slug))->withStatus(301);
            }
        }
        // `page` meant the registry's own pagination and means nothing to Discover.
        unset($q['page'], $q['tab']);
        $carry = http_build_query($q);
        return $res->withHeader('Location', '/discover?tab=people' . ($carry !== '' ? '&' . $carry : ''))->withStatus(301);
    }

    public function profile(Request $req, Response $res, array $args): Response
    {
        $slug = (string) ($args['slug'] ?? '');
        $p    = $this->profiles->getBySlug($slug);
        if (!$p) throw new \Slim\Exception\HttpNotFoundException($req);

        $viewer = (int) ($_SESSION['user_id'] ?? 0);
        $pg     = ProfilePage::build($p, $viewer);
        $editing = $pg['owner'] && ($req->getQueryParams()['edit'] ?? '') === '1';

        $bio  = trim(strip_tags((string) ($p['bio'] ?? '')));
        $meta = $p['display_name'] . ($p['category'] ? ' · ' . $p['category'] : '') . ' on Africa GATES'
              . ($bio !== '' ? '. ' . $bio : '. Recognitions from verified issuers, reviewed evidence and the Cultural Power Index.');
        if (mb_strlen($meta) > 160) $meta = rtrim(mb_substr($meta, 0, 157)) . '…';
        $base = SiteUrl::base($req);

        return $this->view->render($res, 'pages/profile.twig', [
            'page_title'       => $p['display_name'] . ' — Africa GATES',
            'meta_description' => $meta,
            'og_title'         => $p['display_name'] . ' — Africa GATES',
            'gates_page'       => 'registry',
            'profile'          => $p,
            'pg'               => $pg,
            'editing'          => $editing,
            'about_max'        => self::ABOUT_MAX,
            // Person JSON-LD, through Schema::text() — a typed name is untrusted.
            'schema'           => Schema::person($p, $base, $base . '/registry/' . rawurlencode((string) $p['slug']),
                                     (string) (Assets::absoluteOg($p['avatar_path'] ?? null) ?? '')),
        ] + array_filter(['og_image' => Assets::absoluteOg($p['avatar_path'] ?? null)], static fn ($v) => $v !== null));
    }

    /** POST /registry/{slug}/edit — the owner's About. Name and country are locked (§8.8). */
    public function edit(Request $req, Response $res, array $args): Response
    {
        $slug = (string) ($args['slug'] ?? '');
        $row  = DB::table('gates_profiles')->where('slug', $slug)->where('status', 'approved')->first(['id', 'slug']);
        if (!$row) throw new \Slim\Exception\HttpNotFoundException($req);
        $back = '/registry/' . rawurlencode((string) $row->slug);
        $uid  = (int) ($_SESSION['user_id'] ?? 0);
        if (!ProfilePage::owns($uid, (int) $row->id)) {
            // Refused for the REASON, said where it happens: not yours, or not signed in.
            $_SESSION['flash_error'] = $uid > 0 ? 'Only the person this profile belongs to can edit it.' : 'Sign in to edit your profile.';
            return $res->withHeader('Location', $uid > 0 ? $back : '/account/login?next=' . rawurlencode($back . '?edit=1'))->withStatus(303);
        }
        $about = trim(str_replace("\r\n", "\n", (string) (((array) $req->getParsedBody())['about'] ?? '')));
        if (mb_strlen($about) > self::ABOUT_MAX) {
            $_SESSION['flash_error'] = 'About can be ' . self::ABOUT_MAX . ' characters at most.';
            return $res->withHeader('Location', $back . '?edit=1')->withStatus(303);
        }
        DB::table('gates_profiles')->where('id', (int) $row->id)->update(['bio' => strip_tags($about), 'updated_at' => date('Y-m-d H:i:s')]);
        $this->cache->forget('profile:' . $row->slug);
        $_SESSION['flash_ok'] = 'Your profile is saved.';
        return $res->withHeader('Location', $back)->withStatus(303);
    }

    /** POST /registry/{slug}/follow — toggles; a plain form, so it works without script. */
    public function follow(Request $req, Response $res, array $args): Response
    {
        $slug = (string) ($args['slug'] ?? '');
        $row  = DB::table('gates_profiles')->where('slug', $slug)->where('status', 'approved')->first(['id', 'slug']);
        if (!$row) throw new \Slim\Exception\HttpNotFoundException($req);
        $back = '/registry/' . rawurlencode((string) $row->slug);
        $uid  = (int) ($_SESSION['user_id'] ?? 0);
        if ($uid < 1) return $res->withHeader('Location', '/account/login?next=' . rawurlencode($back))->withStatus(303);
        $r = ($this->community ?? new CommunityService())->toggleFollow($uid, 'profile', (int) $row->id);
        if (empty($r['ok'])) $_SESSION['flash_error'] = (string) ($r['message'] ?? 'That did not work. Please try again.');
        return $res->withHeader('Location', $back)->withStatus(303);
    }

    public function registerForm(Request $req,Response $res):Response {
        return $this->view->render($res,'pages/registry/register.twig',['page_title'=>'Register — Africa GATES','meta_description'=>'Join the Africa GATES registry. Create your verified profile, start building a Cultural Power Index score and become eligible for every awards cycle.','gates_page'=>'register']);
    }
    public function registerSubmit(Request $req,Response $res):Response {
        $b=(array)$req->getParsedBody(); $ip=$req->getServerParams()['REMOTE_ADDR']??''; $fp=hash('sha256',$ip);
        if(!$this->rateLimit->check($fp,'register',3,3600)) return $this->view->render($res,'pages/registry/register.twig',['error'=>'Too many submissions.','gates_page'=>'register','old'=>$b])->withStatus(429);
        foreach(['display_name','email','category','profile_type','country_code'] as $f) if(empty(trim((string)($b[$f]??'')))) return $this->view->render($res,'pages/registry/register.twig',['error'=>'Please fill all required fields.','gates_page'=>'register','old'=>$b])->withStatus(422);
        if(!filter_var($b['email'],FILTER_VALIDATE_EMAIL)) return $this->view->render($res,'pages/registry/register.twig',['error'=>'Invalid email address.','gates_page'=>'register','old'=>$b])->withStatus(422);
        try{ $newId = $this->profiles->register($b); }catch(\Exception $e){ $msg=str_contains($e->getMessage(),'Duplicate')?'Email already registered.':'Registration failed.'; return $this->view->render($res,'pages/registry/register.twig',['error'=>$msg,'gates_page'=>'register','old'=>$b])->withStatus(422); }
        $this->sheets?->pushRegistration($b);
        $this->community?->recordActivity('register', trim((string)$b['display_name']), 'profile', (int)$newId, trim((string)$b['display_name']), ['country' => strtoupper((string)$b['country_code'])]);
        $dn=trim((string)$b['display_name']); $em=strtolower(trim((string)$b['email']));
        Notifier::adminAlert($this->mailer, 'New profile registration',
            "Name: $dn\nType: ".trim((string)($b['profile_type']??''))."\nCategory: ".trim((string)($b['category']??''))."\nCountry: ".strtoupper((string)$b['country_code'])."\nEmail: $em");
        Notifier::confirm($this->mailer, $em, 'Your profile is registered',
            "Hi $dn,\n\nYour Africa GATES profile has been registered. Once our team verifies it, your CPI score begins ticking and you become eligible for every cycle.\n\n— Africa GATES");
        return $res->withHeader('Location','/register/success')->withStatus(302);
    }
}
