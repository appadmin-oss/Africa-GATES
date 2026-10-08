<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\CookiePrefs;
use AfricaGates\Services\VisitTracker;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\TestCase;

/**
 * May we? — four categories, one rule, and the ways it must never be got wrong.
 *
 * Rebuilt on 3 Oct 2026 with CookiePrefs (GAPS Q11: the handoff's four categories in a
 * versioned `ag_consent`). The old file asked about one switch; every rule it held is held
 * again here against the four, and three are new: the old cookie's answer is carried
 * across (a no stays a no), Preferences starts OFF whatever the posture, and Marketing —
 * which nothing on this site does — is never offered a switch.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE RULE IS ONE SENTENCE: IF ANYTHING SAID NO, THE ANSWER IS NO
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A browser signal beats a stored yes, for EVERY optional category — stricter than the GPC
 * specification requires, and deliberate. And the tracker reads the same answer the page
 * draws: two resolvers for one decision is how a page comes to ask somebody a question it
 * has already counted them without. So the tracker cases are asserted against the TABLE.
 */
final class CookiePrefsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        // The memo is per PROCESS and the suite is one process.
        CookiePrefs::forget();
        DB::table('gates_settings')->where('key_name', 'like', 'visits_%')->delete();
    }

    private function req(string $uri = '/', array $cookies = [], array $headers = []): Request
    {
        $r = (new ServerRequestFactory())->createServerRequest('GET', $uri);
        $headers += ['User-Agent' => 'Mozilla/5.0 (Macintosh) AppleWebKit/537.36 Chrome/120 Safari/537.36'];
        foreach ($headers as $k => $v) $r = $r->withHeader($k, $v);

        return $r->withCookieParams($cookies);
    }

    /** An `ag_consent` value as a browser would send it back. */
    private function consent(?bool $p, ?bool $a, ?bool $m, int $v = CookiePrefs::VERSION): array
    {
        return [CookiePrefs::COOKIE => rawurlencode((string) json_encode(
            ['v' => $v, 'preferences' => $p, 'analytics' => $a, 'marketing' => $m]))];
    }

    private function mode(string $mode): void
    {
        DB::table('gates_settings')->where('key_name', CookiePrefs::MODE_KEY)->delete();
        DB::table('gates_settings')->insert(['key_name' => CookiePrefs::MODE_KEY, 'value' => $mode]);
    }

    private function tracker(bool $on): void
    {
        DB::table('gates_settings')->where('key_name', 'visits_enabled')->delete();
        DB::table('gates_settings')->insert(['key_name' => 'visits_enabled', 'value' => $on ? '1' : '0']);
    }

    // ══ the precedence ═══════════════════════════════════════════════════════

    public function test_a_browser_signal_beats_a_stored_yes_for_every_optional_category(): void
    {
        $yes = $this->consent(true, true, true);
        foreach (['Sec-GPC', 'DNT'] as $header) {
            foreach (CookiePrefs::OPTIONAL as $c) {
                $this->assertFalse(CookiePrefs::allows($this->req('/', $yes, [$header => '1']), $c),
                    "{$header}: 1 was overruled by a stored yes for {$c} — the rule is that anything saying no wins");
            }
            $this->assertTrue(CookiePrefs::allows($this->req('/', $yes, [$header => '1']), CookiePrefs::ESSENTIAL));
        }
        $this->assertSame('gpc', CookiePrefs::signal($this->req('/', [], ['Sec-GPC' => '1', 'DNT' => '1'])),
            'the sheet names Global Privacy Control when it was sent');
    }

    public function test_a_stored_answer_beats_the_operators_posture(): void
    {
        $this->mode(CookiePrefs::MODE_EXEMPT);
        $this->assertFalse(CookiePrefs::allows($this->req('/', $this->consent(null, false, null)), CookiePrefs::ANALYTICS));

        $this->mode(CookiePrefs::MODE_CONSENT);
        $this->assertTrue(CookiePrefs::allows($this->req('/', $this->consent(null, true, null)), CookiePrefs::ANALYTICS));
    }

    public function test_unanswered_analytics_follows_the_posture_and_unanswered_preferences_is_off(): void
    {
        $this->assertTrue(CookiePrefs::allows($this->req(), CookiePrefs::ANALYTICS),
            'the default posture counts first-party, unless refused');
        $this->mode(CookiePrefs::MODE_CONSENT);
        $this->assertFalse(CookiePrefs::allows($this->req(), CookiePrefs::ANALYTICS),
            'ask-first counted somebody before they answered');

        // Preferences stores something persistent for a reason the visitor did not just ask
        // for, so nothing is switched on in advance — in either posture.
        foreach ([CookiePrefs::MODE_EXEMPT, CookiePrefs::MODE_CONSENT] as $m) {
            $this->mode($m);
            $this->assertFalse(CookiePrefs::allows($this->req(), CookiePrefs::PREFERENCES),
                "Preferences was on before anybody allowed it ({$m})");
            $this->assertFalse(CookiePrefs::allows($this->req(), CookiePrefs::MARKETING));
        }
    }

    public function test_an_unreadable_or_foreign_answer_is_never_a_yes(): void
    {
        foreach (['garbage', '{"v":1,', '%7B%22v', '[]'] as $junk) {
            foreach (CookiePrefs::OPTIONAL as $c) {
                $this->assertFalse(CookiePrefs::allows($this->req('/', [CookiePrefs::COOKIE => $junk]), $c),
                    "'{$junk}' read as a yes to {$c}");
            }
        }

        // An answer recorded under another version keeps its refusals and drops its yeses:
        // a yes was given to a different set of categories, a no is never weakened.
        $other = $this->consent(true, false, null, CookiePrefs::VERSION + 1);
        $this->assertSame(
            ['preferences' => null, 'analytics' => false, 'marketing' => null],
            CookiePrefs::answers($this->req('/', $other)));
    }

    // ══ the retired cookie ═══════════════════════════════════════════════════

    public function test_a_no_given_under_the_old_cookie_stays_a_no(): void
    {
        // Behaviour preservation, not a compatibility alias: the answer is read once and
        // rewritten into `ag_consent`, and the old name is expired.
        $old = $this->req('/', [CookiePrefs::LEGACY => 'n']);
        $this->assertFalse(CookiePrefs::allows($old, CookiePrefs::ANALYTICS),
            'a visitor who refused the counting under the old cookie is being counted');
        $this->assertSame(['preferences' => null, 'analytics' => false, 'marketing' => null],
            CookiePrefs::answers($old), 'the old question never asked about the other two');

        $set = CookiePrefs::carryOver($old, new Response())->getHeader('Set-Cookie');
        $this->assertCount(2, $set);
        $this->assertStringStartsWith(CookiePrefs::COOKIE . '=', $set[0]);
        $doc = json_decode(rawurldecode(explode(';', substr($set[0], strlen(CookiePrefs::COOKIE) + 1))[0]), true);
        $this->assertFalse($doc['analytics'], 'the carried-over record lost the refusal');
        $this->assertSame(CookiePrefs::LEGACY . '=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax', $set[1],
            'the old cookie must be expired, never written with a value');

        // The carried record then answers on its own, without the old cookie.
        $next = $this->req('/', [CookiePrefs::COOKIE => explode(';', substr($set[0], strlen(CookiePrefs::COOKIE) + 1))[0]]);
        $this->assertFalse(CookiePrefs::allows($next, CookiePrefs::ANALYTICS));

        // A yes is carried as a yes, and only once: with ag_consent present nothing is moved.
        $this->assertTrue(CookiePrefs::allows($this->req('/', [CookiePrefs::LEGACY => 'y']), CookiePrefs::ANALYTICS));
        $both = $this->req('/', [CookiePrefs::LEGACY => 'y'] + $this->consent(null, false, null));
        $this->assertSame([], CookiePrefs::carryOver($both, new Response())->getHeader('Set-Cookie'));
        $this->assertFalse(CookiePrefs::allows($both, CookiePrefs::ANALYTICS), 'ag_consent is the record once it exists');
    }

    // ══ what may be offered ══════════════════════════════════════════════════

    public function test_marketing_is_never_offered_because_nothing_here_does_it(): void
    {
        $this->assertFalse(CookiePrefs::offered(CookiePrefs::MARKETING));
        $this->assertTrue(CookiePrefs::offered(CookiePrefs::PREFERENCES));

        // "Allow all" cannot invent a yes for it either.
        $all = CookiePrefs::fromPost($this->req(), ['answer' => 'all']);
        $this->assertNull($all['marketing'], 'a yes was stored for a category nothing uses');
        $this->assertTrue($all['preferences']);

        $s = CookiePrefs::state($this->req());
        $row = array_values(array_filter($s['rows'], static fn (array $r): bool => $r['key'] === 'marketing'))[0];
        $this->assertSame('unused', $row['kind'], 'Marketing was drawn with a switch that switches nothing');
    }

    public function test_answers_from_the_form(): void
    {
        $none = CookiePrefs::fromPost($this->req(), ['answer' => 'essential']);
        $this->assertSame(['preferences' => false, 'analytics' => false, 'marketing' => false], $none);

        // Unrecognised is a no: a request we could not understand about whether somebody
        // agreed is one in which they did not.
        $this->assertSame($none, CookiePrefs::fromPost($this->req(), ['answer' => 'yes please']));
        $this->assertSame($none, CookiePrefs::fromPost($this->req(), []));

        $mine = CookiePrefs::fromPost($this->req(), ['answer' => 'save', 'preferences' => '1']);
        $this->assertTrue($mine['preferences']);
        $this->assertFalse($mine['analytics'], 'an unticked switch must be stored as a no');
    }

    // ══ the tracker and the page must agree ══════════════════════════════════

    public function test_the_tracker_records_nothing_once_the_visitor_has_refused(): void
    {
        $before = (int) DB::table('gates_visits')->count();
        VisitTracker::record($this->req('/nominees', $this->consent(null, false, null)));
        $this->assertSame($before, (int) DB::table('gates_visits')->count(),
            'a refusal was stored and the arrival was recorded anyway');

        $_SESSION = [];
        VisitTracker::record($this->req('/nominees', [CookiePrefs::LEGACY => 'n']));
        $this->assertSame($before, (int) DB::table('gates_visits')->count(),
            'a refusal stored under the old cookie was ignored');
    }

    public function test_the_tracker_records_nothing_in_consent_mode_until_they_agree(): void
    {
        $this->mode(CookiePrefs::MODE_CONSENT);

        $before = (int) DB::table('gates_visits')->count();
        VisitTracker::record($this->req('/nominees'));
        $this->assertSame($before, (int) DB::table('gates_visits')->count(),
            'ask-first counted somebody who had not been asked');

        $_SESSION = [];
        VisitTracker::record($this->req('/nominees', $this->consent(null, true, null)));
        $this->assertSame($before + 1, (int) DB::table('gates_visits')->count(),
            'somebody agreed and was still not counted');
    }

    // ══ the notice ═══════════════════════════════════════════════════════════

    public function test_the_notice_asks_until_every_offered_category_is_answered(): void
    {
        $this->assertTrue(CookiePrefs::mustAsk($this->req()));
        // Answered for what is offered → nothing to ask, whatever Marketing says.
        $this->assertFalse(CookiePrefs::mustAsk($this->req('/', $this->consent(false, true, null))));
        // One offered category still unanswered → ask.
        $this->assertTrue(CookiePrefs::mustAsk($this->req('/', $this->consent(true, null, null))));
        // The old cookie answered Analytics only, so Preferences is still asked.
        $this->assertTrue(CookiePrefs::mustAsk($this->req('/', [CookiePrefs::LEGACY => 'n'])));

        // Not on the page that carries the full control.
        $this->assertFalse(CookiePrefs::mustAsk($this->req('/cookies')));
    }

    public function test_the_notice_is_never_put_to_a_browser_that_already_said_no(): void
    {
        // Asking somebody to agree to something we have already decided not to do is a
        // dark pattern whether or not anybody intended it.
        foreach (['Sec-GPC', 'DNT'] as $h) {
            $this->assertFalse(CookiePrefs::mustAsk($this->req('/', [], [$h => '1'])),
                "the notice asked a browser that sent {$h}");
        }
    }

    public function test_analytics_is_not_asked_about_when_the_tracker_is_off(): void
    {
        $this->tracker(false);
        $this->assertFalse(CookiePrefs::mustAsk($this->req('/', $this->consent(true, null, null))),
            'a consent question was put to somebody with nothing to consent to');

        $s = CookiePrefs::state($this->req());
        $row = array_values(array_filter($s['rows'], static fn (array $r): bool => $r['key'] === 'analytics'))[0];
        $this->assertSame('off_for_all', $row['kind']);
    }

    public function test_the_memo_is_primed_by_the_request_and_not_guessed(): void
    {
        // Unprimed (the admin console, a test rendering a template alone) → no notice.
        $this->assertNull(CookiePrefs::current());

        CookiePrefs::observe($this->req('/nominees'));
        $s = CookiePrefs::current();
        $this->assertTrue($s['ask']);
        $this->assertSame('/nominees', $s['return']);
        $this->assertFalse($s['remember'], 'the display store was told to persist without a yes');

        // The one-shot confirmation is taken when drawn, not before.
        $_SESSION['consent_saved'] = 'all';
        CookiePrefs::observe($this->req('/nominees'));
        $this->assertSame('all', CookiePrefs::current()['saved']);
        // Every reader in the same request sees it (the layout asks, then the partial)…
        $this->assertSame('all', CookiePrefs::current()['saved'], 'the partial lost what the layout took');
        // …and the next request does not.
        CookiePrefs::observe($this->req('/nominees'));
        $this->assertSame('', CookiePrefs::current()['saved'], 'the confirmation was shown twice');
    }

    // ══ the cookie ═══════════════════════════════════════════════════════════

    public function test_the_cookie_is_written_with_the_flags_the_policy_claims(): void
    {
        $set = CookiePrefs::write(new Response(), ['preferences' => true, 'analytics' => false, 'marketing' => null])
            ->getHeaderLine('Set-Cookie');

        $this->assertStringStartsWith(CookiePrefs::COOKIE . '=', $set);
        $this->assertStringContainsString('HttpOnly', $set);
        $this->assertStringContainsString('SameSite=Lax', $set);
        $this->assertStringContainsString('Path=/', $set);
        $this->assertStringContainsString('Max-Age=' . (CookiePrefs::TTL_DAYS * 86400), $set);

        // Versioned JSON, holding the three answers and nothing that identifies anybody.
        $raw = explode(';', substr($set, strlen(CookiePrefs::COOKIE) + 1))[0];
        $this->assertDoesNotMatchRegularExpression('~[",; ]~', $raw, 'a cookie value must not carry quotes, commas or spaces raw');
        $doc = json_decode(rawurldecode($raw), true);
        $this->assertSame(['v', 'preferences', 'analytics', 'marketing'], array_keys($doc));
        $this->assertSame(CookiePrefs::VERSION, $doc['v']);
    }

    public function test_the_return_path_cannot_be_turned_into_an_open_redirect(): void
    {
        foreach ([
            '//evil.example'          => '/',
            '/\\evil.example'         => '/',
            'https://evil.example'    => '/',
            'evil.example'            => '/',
            ''                        => '/',
            "/ok\nLocation: /elsewhere" => '/',
            // A credential in a query string must not travel through a form field.
            '/honour/AGI-K7M2QX4T?t=secret' => '/honour/AGI-K7M2QX4T',
            '/nominees#x'             => '/nominees',
            '/nominees/42'            => '/nominees/42',
            // A browser strips tab and newline from ANYWHERE in a URL before resolving it.
            "/\t/evil.example"        => '/',
            "/\n/evil.example"        => '/',
            "/\t\\evil.example"       => '/',
            "/\x00/evil.example"      => '/',
            "/\x7f/evil.example"      => '/',
        ] as $in => $want) {
            $this->assertSame($want, CookiePrefs::safeReturn($in), "safeReturn('{$in}')");
        }
    }
}
