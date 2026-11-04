<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Services\AwardService;
use AfricaGates\Services\HolidayTheme;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * /admin/campaigns/greetings — the windows a member's seasonal greeting shows in
 * (HOLIDAY-THEMES, handoff 5 Oct 2026).
 *
 * A sub-page of campaigns, linked from the newsletter's holiday section, so it carries the
 * campaigns gate and changes nobody's access. Every date is typed: Eid and Easter are
 * entered each year, never computed (Services\HolidayTheme), so a theme with no row here
 * simply does not appear.
 */
final class GreetingsAdminController
{
    private const BASE = '/admin/campaigns/greetings';

    public function __construct(private readonly Twig $view, private readonly ?AuditService $audit = null) {}

    private function blocked(Response $res, bool $write = true): ?Response
    {
        $role = (string) ($_SESSION['admin_role'] ?? '');
        $may  = $write ? ['superadmin', 'admin'] : ['superadmin', 'admin', 'moderator', 'viewer'];
        if (in_array($role, $may, true)) return null;
        $_SESSION['flash_error'] = $write ? 'Your role can read the greetings but not change them.' : 'You don’t have access to the greetings.';

        return $res->withHeader('Location', '/admin')->withStatus(302);
    }

    private function back(Response $res): Response
    {
        return $res->withHeader('Location', self::BASE)->withStatus(302);
    }

    public function index(Request $req, Response $res): Response
    {
        if ($b = $this->blocked($res, false)) return $b;
        $ready = SchemaHas::table('gates_holidays');
        $rows  = $ready ? DB::table('gates_holidays')->orderByDesc('starts_on')->orderByDesc('id')->limit(60)->get()->all() : [];
        $themes = [];
        foreach (HolidayTheme::THEMES as $slug => $t) $themes[$slug] = ['name' => $t[0], 'scope' => $t[8]];
        $today = Carbon::now()->toDateString();

        return $this->view->render($res, 'admin/campaigns/greetings.twig', [
            'page_title' => 'Seasonal greetings — Admin', 'admin_page' => 'campaigns',
            'ready'      => $ready,
            'rows'       => $rows,
            'themes'     => $themes,
            'today'      => $today,
            'programmes' => array_map(static fn ($p) => ['id' => (int) $p['id'], 'title' => (string) $p['title']],
                                (new AwardService())->getActiveProgrammesWithStatus()),
        ]);
    }

    public function add(Request $req, Response $res): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $in = (array) $req->getParsedBody();
        $slug = (string) ($in['slug'] ?? '');
        $from = (string) ($in['starts_on'] ?? ''); $to = (string) ($in['ends_on'] ?? '');
        $cc   = strtoupper(trim((string) ($in['country_code'] ?? '')));
        $ok   = isset(HolidayTheme::THEMES[$slug]) && self::date($from) && self::date($to) && $from <= $to
             && ($cc === '' || preg_match('/^[A-Z]{2}$/', $cc));
        if (!$ok || !SchemaHas::table('gates_holidays')) {
            $_SESSION['flash_error'] = 'Choose a greeting, a first and last day (the last on or after the first), and a two-letter country or none.';
            return $this->back($res);
        }
        $pid = (int) ($in['cta_programme_id'] ?? 0);
        $id = (int) DB::table('gates_holidays')->insertGetId([
            'slug' => $slug, 'starts_on' => $from, 'ends_on' => $to, 'country_code' => $cc !== '' ? $cc : null,
            'cta_programme_id' => $slug === 'teachers' && $pid > 0 ? $pid : null,
            'active' => 1, 'created_at' => Carbon::now()->toDateTimeString(),
        ]);
        $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'greeting.add', 'holiday', $id, ['slug' => $slug, 'from' => $from, 'to' => $to, 'country' => $cc]);
        $_SESSION['flash_ok'] = 'Added. Members see it from ' . $from . '.';

        return $this->back($res);
    }

    public function toggle(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id = (int) ($args['id'] ?? 0);
        $row = SchemaHas::table('gates_holidays') ? DB::table('gates_holidays')->where('id', $id)->first() : null;
        if ($row) {
            DB::table('gates_holidays')->where('id', $id)->update(['active' => (int) $row->active === 1 ? 0 : 1]);
            $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'greeting.toggle', 'holiday', $id, ['active' => (int) $row->active === 1 ? 0 : 1]);
        }

        return $this->back($res);
    }

    public function delete(Request $req, Response $res, array $args): Response
    {
        if ($b = $this->blocked($res)) return $b;
        $id = (int) ($args['id'] ?? 0);
        if (SchemaHas::table('gates_holidays') && DB::table('gates_holidays')->where('id', $id)->delete()) {
            $this->audit?->record((int) ($_SESSION['admin_id'] ?? 0), 'greeting.delete', 'holiday', $id, []);
            $_SESSION['flash_ok'] = 'Removed.';
        }

        return $this->back($res);
    }

    private static function date(string $d): bool
    {
        $t = \DateTime::createFromFormat('!Y-m-d', $d);

        return $t !== false && $t->format('Y-m-d') === $d;
    }
}
