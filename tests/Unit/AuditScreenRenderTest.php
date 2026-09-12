<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Services\AuditService;
use AfricaGates\Admin\Support\AuditTargets;
use AfricaGates\Support\DisplayTime;
use Tests\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

/**
 * The audit screen, actually rendered.
 *
 * ── WHY A RENDER TEST AND NOT A STRUCTURAL ONE ───────────────────────────────
 *
 * A test asserting the route exists and the file is on disk proves the screen is
 * REACHABLE, which is a different claim from the screen working. Everything that takes
 * an admin page down in production happens at render: a filter that was never
 * registered, `is iterable` asked of a scalar, an undefined key on a row shape that
 * changed. None of it shows up in a syntax check and all of it shows up as a 500 on the
 * morning somebody opens the log because a result has been challenged.
 *
 * The layout is stubbed rather than rendered. This is a test of the audit screen, and
 * pulling in the real admin chrome would make every failure here ambiguous between the
 * two — which is how a render test stops being run.
 */
final class AuditScreenRenderTest extends TestCase
{
    /** The layout's blocks, and nothing else. */
    private const LAYOUT = <<<'TWIG'
        <!doctype html><title>{% block topbar_title %}{% endblock %}</title>
        {% block head_styles %}{% endblock %}
        <nav>{% block topbar_actions %}{% endblock %}</nav>
        <main>{% block content %}{% endblock %}</main>
        {% block foot_scripts %}{% endblock %}
        TWIG;

    private function render(array $vars): string
    {
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['admin/layout.twig' => self::LAYOUT]),
            new FilesystemLoader(dirname(__DIR__, 2) . '/templates'),
        ]), ['strict_variables' => true]);

        // The filters the screen uses that the container registers at boot. `when` is the
        // one that matters: the log stores UTC by this application's convention, and a
        // screen printing the column raw shows an operator in Lagos a time an hour out
        // with nothing on the page saying which zone it is.
        $twig->addFilter(new TwigFilter('when', [DisplayTime::class, 'show']));
        $twig->addGlobal('csp_nonce', 'test-nonce');

        return $twig->render('admin/audit.twig', $vars + $this->baseline());
    }

    /** The shape the controller hands over, with nothing in it. */
    private function baseline(): array
    {
        return [
            'page_title' => 'Audit log', 'admin_page' => 'audit',
            'result' => ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per' => 60],
            'facets' => ['areas' => [], 'admins' => [], 'types' => [], 'total' => 0,
                         'span' => ['first' => null, 'last' => null]],
            'f' => ['admin' => null, 'area' => '', 'action' => '', 'target_type' => '',
                    'target_id' => null, 'q' => '', 'from' => '', 'to' => '', 'page' => 1],
            'active' => 0, 'qs' => '', 'qs_no_action' => '', 'window' => [1],
            'subject' => null, 'form_action' => '/admin/audit', 'areaActions' => [],
        ];
    }

    /**
     * The filter shape with some keys overridden.
     *
     * A helper rather than `baseline()['f'] + [...]` at each call site: `+` on arrays keeps
     * the LEFT value when a key is already present, and every key in this shape is, so the
     * override reads as applied and is a no-op.
     *
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    private function filters(array $over): array
    {
        return array_merge($this->baseline()['f'], $over);
    }

    /** One log row, in exactly the shape AuditService::search() returns. */
    private function row(array $o = []): array
    {
        return $o + [
            'id' => 1, 'admin_id' => 4, 'action' => 'settings.update', 'area' => 'settings',
            'target_type' => 'site_event', 'target_id' => 12, 'meta' => null,
            'admin_name' => 'Adaeze Umeh', 'admin_email' => 'adaeze@example.test',
            'created_at' => '2026-06-01 09:00:00',
            'device' => 'a1b2c3', 'agent' => 'Chrome on Android',
            'target_label' => 'Event', 'target_name' => 'Lagos Gala 2026',
            'target_href' => '/admin/events/12', 'target_key' => 'site_event',
        ];
    }

    /**
     * `strict_variables` is on above, so this passing at all is the assertion that
     * matters: every key the template reads is one the controller supplies.
     */
    public function test_an_empty_log_renders(): void
    {
        $out = $this->render([]);
        $this->assertStringContainsString('Nothing has been recorded yet', $out);
    }

    /** "No results" against an unknown corpus is unreadable — say which it is. */
    public function test_an_empty_filtered_view_offers_to_clear_the_filters(): void
    {
        $out = $this->render(['active' => 2, 'f' => $this->filters(['q' => 'nope'])]);

        $this->assertStringContainsString('Nothing recorded matches those filters', $out);
        $this->assertStringContainsString('Clear them', $out);
        $this->assertStringNotContainsString('Nothing has been recorded yet', $out);
    }

    public function test_a_row_shows_the_target_by_name_not_by_number(): void
    {
        $out = $this->render(['result' => ['rows' => [$this->row()], 'total' => 1,
                                           'page' => 1, 'pages' => 1, 'per' => 60]]);

        $this->assertStringContainsString('Lagos Gala 2026', $out);
        $this->assertStringContainsString('href="/admin/events/12"', $out, 'the record itself is not reachable');
        $this->assertStringContainsString('Open the event record', $out,
            'the link out of the log is a bare glyph with no accessible name');
        $this->assertStringContainsString('/admin/audit?type=site_event&amp;tid=12', $out,
            'the target does not link to its own history');
    }

    /**
     * The time is converted, not printed raw. Asserted against DisplayTime rather than a
     * literal, so this keeps holding when the platform zone is reconfigured.
     */
    public function test_the_time_is_shown_in_the_display_zone(): void
    {
        $out = $this->render(['result' => ['rows' => [$this->row()], 'total' => 1,
                                           'page' => 1, 'pages' => 1, 'per' => 60]]);

        $this->assertStringContainsString(DisplayTime::show('2026-06-01 09:00:00'), $out);
        // The raw UTC value survives only inside the title attribute, which says so.
        $this->assertStringContainsString('title="2026-06-01 09:00:00 UTC"', $out);
    }

    /**
     * `meta` holds both scalars and lists — `['fields' => array_keys($patch)]` on one
     * controller, `['ok' => true]` on another. Both have to render.
     */
    public function test_meta_renders_lists_and_scalars_alike(): void
    {
        $rows = [
            $this->row(['id' => 1, 'meta' => ['fields' => ['title', 'venue', 'capacity']]]),
            $this->row(['id' => 2, 'meta' => ['reason' => 'duplicate', 'count' => 3]]),
        ];
        $out = $this->render(['result' => ['rows' => $rows, 'total' => 2,
                                           'page' => 1, 'pages' => 1, 'per' => 60]]);

        $this->assertStringContainsString('title, venue, capacity', $out, 'a list meta value did not render');
        $this->assertStringContainsString('duplicate', $out);
        $this->assertStringContainsString('<b>count</b>', $out);
    }

    /** The two columns nothing had ever rendered. */
    public function test_the_device_and_agent_reach_the_page(): void
    {
        $out = $this->render(['result' => ['rows' => [$this->row()], 'total' => 1,
                                           'page' => 1, 'pages' => 1, 'per' => 60]]);

        $this->assertStringContainsString('a1b2c3', $out, 'the network fingerprint is still invisible');
        $this->assertStringContainsString('Chrome on Android', $out, 'the user agent is still invisible');
    }

    /**
     * A row with no admin is cron, the console, or an expired session. Naming it beats an
     * em-dash: unattributed is a fact about the action, not a gap in the record.
     */
    public function test_an_unattributed_row_says_so(): void
    {
        $rows = [$this->row(['admin_id' => null, 'admin_name' => null, 'admin_email' => null])];
        $out  = $this->render(['result' => ['rows' => $rows, 'total' => 1,
                                            'page' => 1, 'pages' => 1, 'per' => 60]]);

        $this->assertStringContainsString('Unattributed', $out);
    }

    /** A target the log names and the database no longer holds. */
    public function test_a_vanished_record_is_distinguished_from_a_broken_one(): void
    {
        $out = $this->render([
            'subject' => ['label' => 'Nominee', 'name' => null, 'href' => null, 'id' => 412],
            'f' => $this->filters(['target_type' => 'nominee', 'target_id' => 412]),
            'active' => 2,
        ]);

        $this->assertStringContainsString('No longer on file', $out);
        $this->assertStringContainsString('Its history is not', $out);
    }

    /** The per-admin view, including the count `ip_hash` exists for. */
    public function test_the_actor_summary_renders(): void
    {
        $out = $this->render([
            'actor' => ['total' => 240, 'first_at' => '2026-01-02 08:00:00',
                        'last_at' => '2026-06-01 09:00:00', 'devices' => 9, 'agents' => 3,
                        'top' => [['action' => 'settings.update', 'n' => 40]]],
            'actor_id' => 4,
            'result' => ['rows' => [$this->row()], 'total' => 240, 'page' => 1, 'pages' => 4, 'per' => 60],
            'window' => [1, 2, 3, 4],
        ]);

        $this->assertStringContainsString('Adaeze Umeh', $out);
        $this->assertStringContainsString('9 distinct networks', $out,
            'the network count is the one thing ip_hash was recorded for');
        // Phrased as a question, not a verdict: the screen cannot know how this person works.
        $this->assertStringContainsString('worth asking about', $out);
        $this->assertStringContainsString('settings.update', $out);
    }

    /** Below the threshold the note must not appear — an alert that always fires is noise. */
    public function test_an_ordinary_network_count_raises_nothing(): void
    {
        $out = $this->render([
            'actor' => ['total' => 12, 'first_at' => null, 'last_at' => null,
                        'devices' => 2, 'agents' => 1, 'top' => []],
            'actor_id' => 4,
        ]);

        $this->assertStringNotContainsString('worth asking about', $out);
    }

    /**
     * Every filter select is populated from the log itself. A hardcoded list would go
     * stale the first time somebody added a controller, and a filter that silently omits
     * an action is how you conclude something never happened.
     */
    public function test_the_filters_are_built_from_the_log(): void
    {
        $out = $this->render(['facets' => [
            'areas'  => [['area' => 'stand_call', 'n' => 12, 'actions' => []]],
            'admins' => [['admin_id' => 4, 'name' => 'Adaeze Umeh', 'n' => 240, 'last_at' => null],
                         ['admin_id' => null, 'name' => null, 'n' => 6, 'last_at' => null]],
            'types'  => [['type' => 'site_event', 'label' => 'Event', 'n' => 30]],
            'total'  => 246,
            'span'   => ['first' => '2026-01-02 08:00:00', 'last' => '2026-06-01 09:00:00'],
        ]]);

        $this->assertStringContainsString('stand_call (12)', $out);
        $this->assertStringContainsString('Adaeze Umeh (240)', $out);
        $this->assertStringContainsString('Unattributed (6)', $out,
            'rows with no admin had no way to be selected');
        $this->assertStringContainsString('Event (30)', $out);
    }

    /**
     * Every filter control needs a label a screen reader can reach. Placeholders are not
     * labels and disappear the moment somebody types.
     */
    public function test_every_filter_control_has_a_label(): void
    {
        $out = $this->render([]);

        preg_match_all('/<(?:input|select)\b[^>]*\bid="(aud-[a-z]+)"/', $out, $m);
        $this->assertNotEmpty($m[1], 'no filter controls rendered at all');

        foreach ($m[1] as $id) {
            $this->assertMatchesRegularExpression('/<label\s+for="' . preg_quote($id, '/') . '"/', $out,
                "the filter control #{$id} has no associated label");
        }
    }

    /**
     * The exact-action filter has no control of its own — a hundred-odd actions is not a
     * usable select — so it is set by a chip. Without a hidden field it was dropped the
     * moment anybody touched any OTHER filter, silently widening what they were looking at.
     */
    public function test_the_exact_action_filter_survives_a_re_filter(): void
    {
        $out = $this->render([
            'f' => $this->filters(['action' => 'settings.update']),
            'active' => 1, 'qs' => '&action=settings.update', 'qs_no_action' => '',
        ]);

        $this->assertMatchesRegularExpression(
            '/<input type="hidden" name="action" value="settings\.update">/', $out,
            'the action filter is not carried by the form, so re-filtering drops it');
        // And it must be visible: a filter nobody can see is a filter nobody can argue with.
        $this->assertStringContainsString('remove this filter', $out);
    }

    /**
     * `facets()` returns the actions under each area, and until this rendered them the
     * field had no reader at all — §17 inside the fix for §17.
     */
    public function test_choosing_an_area_reveals_the_actions_under_it(): void
    {
        $out = $this->render([
            'f' => $this->filters(['area' => 'settings']),
            'active' => 1,
            'areaActions' => [['action' => 'settings.update', 'n' => 40],
                              ['action' => 'settings.smtp_test', 'n' => 3]],
        ]);

        $this->assertStringContainsString('settings covers', $out);
        $this->assertStringContainsString('settings.smtp_test · 3', $out);
        $this->assertStringContainsString('action=settings.update', $out);
    }

    /** With no area chosen they must stay out of the way — that is the whole disclosure. */
    public function test_with_no_area_chosen_no_actions_are_listed(): void
    {
        $out = $this->render([]);
        $this->assertStringNotContainsString('covers', $out);
    }

    /**
     * On the per-admin view the admin comes from the PATH, so an admin select there could
     * not take effect. A control that looks live and is inert is worse than an absent one:
     * somebody changes it, reads an unchanged table, and concludes the log is wrong.
     */
    public function test_the_per_admin_view_has_no_inert_admin_control(): void
    {
        $vars = [
            'actor' => ['total' => 3, 'first_at' => null, 'last_at' => null,
                        'devices' => 1, 'agents' => 1, 'top' => []],
            'actor_id' => 4, 'form_action' => '/admin/audit/actor/4',
            'facets' => ['areas' => [], 'types' => [], 'total' => 3,
                         'admins' => [['admin_id' => 4, 'name' => 'Adaeze Umeh', 'n' => 3, 'last_at' => null]],
                         'span' => ['first' => null, 'last' => null]],
        ];

        $out = $this->render($vars);
        $this->assertStringNotContainsString('id="aud-admin"', $out,
            'the per-admin view renders an admin select the form cannot act on');
        $this->assertStringContainsString('href="/admin/audit"', $out,
            'there is no way back to the whole log from one admin\'s view');

        // And it is present on the general view, or the filter lost a dimension.
        $this->assertStringContainsString('id="aud-admin"', $this->render([]));
    }

    /** The form posts back to wherever it is, or the per-admin view loses its place. */
    public function test_the_filter_form_posts_back_to_the_current_view(): void
    {
        $out = $this->render(['form_action' => '/admin/audit/actor/4',
                              'actor' => ['total' => 0, 'first_at' => null, 'last_at' => null,
                                          'devices' => 0, 'agents' => 0, 'top' => []],
                              'actor_id' => 4]);

        $this->assertStringContainsString('action="/admin/audit/actor/4"', $out);
    }

    /** The screen writes nothing, and must not offer to. */
    public function test_the_screen_has_no_form_that_writes(): void
    {
        $out = $this->render(['result' => ['rows' => [$this->row()], 'total' => 1,
                                           'page' => 1, 'pages' => 1, 'per' => 60]]);

        $this->assertStringNotContainsString('method="post"', strtolower($out),
            'an audit of a log must not be able to change anything, including itself');
    }

    /** The service and the template have to agree about the row shape. */
    public function test_the_rendered_shape_is_the_shape_the_service_returns(): void
    {
        $service = array_keys((new AuditService())->search(['per' => 1])['rows'][0]
            ?? array_fill_keys(array_keys($this->row()), null));

        foreach (['target_label', 'target_name', 'target_href', 'target_key'] as $k) {
            $this->assertContains($k, array_keys($this->row()),
                "the fixture is missing {$k}, so this file stopped testing the real shape");
        }
        $this->assertNotEmpty($service);
        $this->assertSame('site_event', AuditTargets::canonical('event'));
    }
}
