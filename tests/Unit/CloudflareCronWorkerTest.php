<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\CronHealth;
use Tests\TestCase;

/**
 * The Cloudflare Worker that drives `/__cron/run`, and the one way it can quietly rot.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY A PHP TEST READS A JAVASCRIPT FILE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Because nothing else can. `deploy/cloudflare/status-worker.js` runs on somebody else's
 * infrastructure, on a schedule, with no test runner and no eyes on it — and the failure it
 * is built to prevent is SILENT BY CONSTRUCTION:
 *
 *   `/__cron/run` answers **200 with `ok:false`** when the orchestrator finished but
 *   individual tasks threw. That is deliberate and was itself a bug fix: an earlier version
 *   answered 500, webcron services reacted the way they are built to and disabled the job,
 *   and one broken task stopped every task that was still working.
 *
 * The cost of that choice is that a monitor which reads only the HTTP status reports GREEN
 * through exactly the failure it was hired to catch. So the Worker must parse the body, and
 * a well-meaning simplification of it back to `if (res.ok)` would look tidier, pass review,
 * and disable the alerting without changing a single visible behaviour until the day it
 * mattered.
 *
 * These assertions are deliberately shallow — presence of the right things in the source.
 * A shallow test on an artefact nothing else can reach beats no test, and every one of them
 * corresponds to a specific way the file has to stay true.
 *
 * The same reasoning, and the same shape, as {@see GoogleAppsScriptContractTest}.
 */
final class CloudflareCronWorkerTest extends TestCase
{
    private const WORKER = __DIR__ . '/../../deploy/cloudflare/status-worker.js';
    private const TOML   = __DIR__ . '/../../deploy/cloudflare/wrangler.toml';
    private const GUIDE  = __DIR__ . '/../../docs/CLOUDFLARE-CRON-WORKER.md';

    private function worker(): string
    {
        $this->assertFileExists(self::WORKER, 'the driver for scheduled work is missing');
        return (string) file_get_contents(self::WORKER);
    }

    // ════════════════════════════════════════════════════════════════════════
    // THE REGRESSION THIS FILE EXISTS FOR
    // ════════════════════════════════════════════════════════════════════════

    /**
     * The verdict is read from the BODY, not from the status code.
     *
     * See the class note: a partial run is a 200. A `res.ok`-only check is the one change
     * that would silently switch the alerting off.
     */
    public function test_the_worker_judges_the_run_on_the_body_not_the_status_code(): void
    {
        $js = $this->worker();

        $this->assertStringContainsString('body.ok === true', $js,
            'a partial run answers 200 with ok:false — a status-code check reports it as healthy');
        $this->assertStringContainsString('body.failures', $js,
            'the failures map names which task broke, and is the only actionable part');
    }

    /** The token rides in a header, so it does not land in the host's access logs. */
    public function test_the_token_is_sent_as_a_header_and_never_in_the_url(): void
    {
        $js = $this->worker();

        $this->assertStringContainsString("'X-Cron-Token': env.CRON_TOKEN", $js);

        // Asserted on the URL that is FETCHED, not on the absence of the string "?token="
        // anywhere in the file — the endpoint's own documentation in the header comment
        // mentions the query form, and a test that forbids naming it would forbid
        // explaining why the header form was chosen.
        $this->assertStringContainsString("fetch(site + '/__cron/run', {", $js,
            'the request URL must carry no query string: a secret in one ends up in the '
            . "host's access logs and in the scheduler's own dashboard");
    }

    /** And it is never written into the committed config, only into a Worker secret. */
    public function test_no_token_is_committed_in_the_wrangler_config(): void
    {
        $this->assertFileExists(self::TOML);
        $toml = (string) file_get_contents(self::TOML);

        // `[vars]` is readable by anyone with dashboard access; secrets are not.
        $this->assertDoesNotMatchRegularExpression('~^\s*CRON_TOKEN\s*=~m', $toml,
            'CRON_TOKEN must be a wrangler secret, never a [vars] entry');
        $this->assertStringContainsString('wrangler secret put CRON_TOKEN', $toml);
    }

    /**
     * A skipped run neither resets nor advances the failure streak.
     *
     * `{"ok":true,"skipped":"another run in progress"}` is the flock lock doing its job when
     * the CLI cron and the Worker overlap — which is the RECOMMENDED configuration. Counting
     * it as success would paper over a lock that never releases; counting it as failure would
     * email on healthy overlap.
     */
    public function test_a_skipped_run_is_neither_a_success_nor_a_failure(): void
    {
        $this->assertStringContainsString('if (r.skipped) return r;', $this->worker());
    }

    // ════════════════════════════════════════════════════════════════════════
    // AND THE NUMBERS THAT HAVE TO AGREE WITH THE PLATFORM
    // ════════════════════════════════════════════════════════════════════════

    /**
     * The schedule has to stay well inside the staleness window.
     *
     * `CronHealth::STALE_HOURS` is what `/status` calls scheduled work Down on, and the
     * refund ladder is 1h → 6h → 24h against a two-hour in-flight window. A cadence that
     * drifted up to hourly would leave no room for a missed run, and the first evidence
     * would be a public status page reporting an outage.
     */
    public function test_the_cron_cadence_leaves_room_for_missed_runs(): void
    {
        $toml = (string) file_get_contents(self::TOML);

        $this->assertMatchesRegularExpression('~crons\s*=\s*\[\s*"\*/(\d+) \* \* \* \*"~', $toml,
            'the schedule is expected as an every-N-minutes trigger');
        preg_match('~crons\s*=\s*\[\s*"\*/(\d+) \* \* \* \*"~', $toml, $m);
        $everyMinutes = (int) $m[1];

        $staleMinutes = CronHealth::STALE_HOURS * 60;

        $this->assertGreaterThan(0, $everyMinutes);
        $this->assertLessThanOrEqual($staleMinutes / 4, $everyMinutes,
            'at least four runs must fit inside the staleness window, so one missed tick is '
            . 'not a public outage');
    }

    /** The Worker points at the route that actually exists. */
    public function test_the_worker_calls_the_real_endpoint(): void
    {
        $this->assertStringContainsString("'/__cron/run'", $this->worker());

        $routes = (string) file_get_contents(__DIR__ . '/../../src/routes.php');
        $this->assertStringContainsString("'/__cron/run'", $routes,
            'the Worker is pointed at a path this application does not route');
    }

    /**
     * The guide tells an operator the thing that is least guessable.
     *
     * A 404 from this endpoint means the TOKEN is wrong — the route is invisible without a
     * matching one, by design. Read as "the route is missing", it sends somebody looking for
     * a deployment fault that is not there.
     */
    public function test_the_guide_explains_that_a_404_means_the_token(): void
    {
        $this->assertFileExists(self::GUIDE);
        $guide = (string) file_get_contents(self::GUIDE);

        $this->assertStringContainsString('404', $guide);
        $this->assertMatchesRegularExpression('~404[^\n]*token~i', $guide,
            'a 404 here is a rejected token, not a missing route');
    }
}
