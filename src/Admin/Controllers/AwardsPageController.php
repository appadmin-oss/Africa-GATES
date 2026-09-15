<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use AfricaGates\Admin\Services\{SettingsService, AuditService};

/**
 * Editor for the public /awards page's editorial copy (hero, stats, CTAs,
 * "how it works" steps). The programme cards themselves are live data managed
 * under Awards & Cycles — this screen only governs the surrounding presentation.
 *
 * Content is stored as key/value rows in gates_settings (keys prefixed
 * `awards_`). FIELDS is the single source of truth: it drives the admin form,
 * the per-field defaults, and the values handed to the public template — so the
 * page copy can never drift between the editor and what renders.
 */
class AwardsPageController
{
    /** key => [section, label, type(text|textarea), default, help]. */
    public const FIELDS = [
        'awards_hero_badge'      => ['Hero',                'Eyebrow badge',          'text',     '🏆 Inaugural edition · 2026', 'The small pill shown above the headline.'],
        'awards_hero_title'      => ['Hero',                'Headline',               'text',     'The Africa GATES Awards', ''],
        'awards_hero_subtitle'   => ['Hero',                'Sub-headline',           'textarea', 'The Cultural Power Index, made physical — recognition decided in the open by community votes and an independent jury, then honoured on one stage.', ''],
        'awards_stat2_value'     => ['Hero stats',          'Stat 2 — value',         'text',     '45 + 55', 'Stat 1 is the live programme count (set automatically).'],
        'awards_stat2_label'     => ['Hero stats',          'Stat 2 — label',         'text',     'community + jury', ''],
        'awards_stat3_value'     => ['Hero stats',          'Stat 3 — value',         'text',     '100%', ''],
        'awards_stat3_label'     => ['Hero stats',          'Stat 3 — label',         'text',     'audited', ''],
        'awards_cta_nominate'    => ['Hero buttons',        'Primary button label',   'text',     'Submit a nomination →', 'Always links to /nominate.'],
        'awards_cta_vote'        => ['Hero buttons',        'Secondary button label', 'text',     'Cast a vote', 'Always links to /vote.'],
        'awards_gala_text'       => ['Gala line',           'Lead text',              'text',     'The 2026 Gala honours winners in person —', ''],
        'awards_gala_link_label' => ['Gala line',           'Link label',             'text',     'tickets & RSVP on the events page →', ''],
        'awards_gala_link_url'   => ['Gala line',           'Link URL',               'text',     '/events', 'Where the gala link points — e.g. the ceremony event page.'],
        'awards_tracks_heading'  => ['Recognition tracks',  'Heading',                'text',     'The recognition tracks', 'The programme cards below are managed under Awards & Cycles.'],
        'awards_tracks_sub'      => ['Recognition tracks',  'Sub-heading',            'textarea', 'Each programme is independent — its own jury and calendar. Winners are decided by the final CPI at the close of voting.', ''],
        'awards_steps_heading'   => ['How it works',        'Heading',                'text',     'From nomination to recognition', ''],
        'awards_steps_sub'       => ['How it works',        'Sub-heading',            'textarea', 'Every cycle runs the same three transparent steps.', ''],
        'awards_step1_title'     => ['How it works',        'Step 1 — title',         'text',     'Open nominations', ''],
        'awards_step1_body'      => ['How it works',        'Step 1 — body',          'textarea', 'Anyone can nominate. Submissions are OTP-verified to keep the pipeline clean and the credit fair.', ''],
        'awards_step2_title'     => ['How it works',        'Step 2 — title',         'text',     'Community vote + jury', ''],
        'awards_step2_body'      => ['How it works',        'Step 2 — body',          'textarea', 'Verified voters weigh in publicly (45%) while an independent panel of African experts scores each shortlist (55%).', ''],
        'awards_step3_title'     => ['How it works',        'Step 3 — title',         'text',     'Permanent attribution', ''],
        'awards_step3_body'      => ['How it works',        'Step 3 — body',          'textarea', 'Every winner lands in the legacy vault — searchable, dated, and forever credited on the continental record.', ''],
    ];

    public function __construct(
        private readonly Twig            $view,
        private readonly SettingsService $settings,
        private readonly AuditService    $audit,
    ) {}

    /**
     * Effective awards-page copy for the public template: the admin override
     * when set, otherwise the built-in default. Keys are returned without the
     * `awards_` prefix (e.g. `hero_title`). Null-tolerant so the public page
     * still renders sensible copy if settings are unavailable.
     */
    public static function resolved(?SettingsService $settings = null): array
    {
        $out = [];
        foreach (self::FIELDS as $key => $f) {
            $default = $f[3];
            $stored  = $settings?->get($key);
            $out[substr($key, 7)] = ($stored !== null && trim($stored) !== '') ? $stored : $default;
        }
        return $out;
    }

    public function form(Request $req, Response $res): Response
    {
        // Group fields by section, carrying the stored value (blank = using default).
        $sections = [];
        foreach (self::FIELDS as $key => [$section, $label, $type, $default, $help]) {
            $sections[$section][] = [
                'key'     => $key,
                'label'   => $label,
                'type'    => $type,
                'default' => $default,
                'help'    => $help,
                'value'   => (string)($this->settings->get($key) ?? ''),
            ];
        }

        return $this->view->render($res, 'admin/awards-page.twig', [
            'page_title' => 'Awards Page — Admin',
            'admin_page' => 'awards_page',
            'sections'   => $sections,
        ]);
    }

    public function save(Request $req, Response $res): Response
    {
        $b       = (array)$req->getParsedBody();
        $adminId = (int)($_SESSION['admin_id'] ?? 0);

        foreach (array_keys(self::FIELDS) as $key) {
            // Trimmed value; a blank field clears the override and reverts to default.
            $this->settings->set($key, trim((string)($b[$key] ?? '')), $adminId);
        }

        $this->audit->record($adminId, 'awards_page.update', 'settings', null);
        $_SESSION['flash_ok'] = 'Awards page updated — changes are live.';
        return $res->withHeader('Location', '/admin/awards-page')->withStatus(302);
    }
}
