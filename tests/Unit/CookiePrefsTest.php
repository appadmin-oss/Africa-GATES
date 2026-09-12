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
 * May we count this arrival? The precedence, and the two ways it must never be got wrong.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE FAULT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see VisitTracker} counted every arrival and the only way to refuse was `DNT` or
 * `Sec-GPC`. Chrome removed the Do Not Track setting and Safari removed it before that, so
 * for most of this platform's visitors — Chrome on an Android phone — there was no way to
 * say no at all, while the published policy promised "you will be asked before it runs".
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IS BEING HELD
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The RULE, in one sentence: if anything said no, the answer is no. A header beats a
 * stored yes, which is stricter than the Global Privacy Control specification requires and
 * is deliberate. The test is here so that "simplifying" it later is a red bar rather than
 * a quiet change to what a visitor's browser is worth.
 *
 * And that the tracker and the page agree. Two resolvers for one decision is how a page
 * comes to show somebody a consent question it has already counted them without.
 */
final class CookiePrefsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        // The memo is per PROCESS and the suite is one process: without this the first
        // test to run seeds every later one's answer and the assertions prove nothing.
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

    private function mode(string $mode): void
    {
        DB::table('gates_settings')->where('key_name', CookiePrefs::MODE_KEY)->delete();
        DB::table('gates_settings')->insert([
            'key_name' => CookiePrefs::MODE_KEY, 'value' => $mode,
        ]);
    }

    // ══ the precedence ═══════════════════════════════════════════════════════

    public function test_a_browser_refusal_beats_a_stored_yes(): void
    {
        // The rule, and the expensive one to get wrong. Somebody browsing with GPC on who
        // then comes here and presses "count my visits" is still not counted; the page
        // tells them so rather than silently disagreeing with the button it offered.
        foreach (['DNT', 'Sec-GPC'] as $header) {
            $this->assertFalse(
                CookiePrefs::analyticsAllowed(
                    $this->req('/', [CookiePrefs::COOKIE => CookiePrefs::YES], [$header => '1'])
                ),
                "{$header}: a stored yes overrode a browser refusal");
        }
    }

    public function test_a_stored_choice_beats_the_operators_default(): void
    {
        $this->mode(CookiePrefs::MODE_EXEMPT);
        $this->assertFalse(CookiePrefs::analyticsAllowed(
            $this->req('/', [CookiePrefs::COOKIE => CookiePrefs::NO])));

        $this->mode(CookiePrefs::MODE_CONSENT);
        $this->assertTrue(CookiePrefs::analyticsAllowed(
            $this->req('/', [CookiePrefs::COOKIE => CookiePrefs::YES])));
    }

    public function test_with_nothing_said_the_operators_posture_decides(): void
    {
        $this->assertTrue(CookiePrefs::analyticsAllowed($this->req()),
            'the default posture is to count unless told not to');

        $this->mode(CookiePrefs::MODE_CONSENT);
        $this->assertFalse(CookiePrefs::analyticsAllowed($this->req()),
            'ask-first must count nobody until they agree');
    }

    public function test_an_unrecognised_mode_reads_as_the_default_and_not_as_consent(): void
    {
        // A typo in a settings row must not be able to take the site's analytics down, and
        // must not be able to switch consent off either. `exempt` is the safe reading in
        // one direction and the page always states which posture is running, so a
        // misconfiguration is visible on /cookies rather than silent.
        $this->mode('Consent');            // capitalised: still consent
        $this->assertSame(CookiePrefs::MODE_CONSENT, CookiePrefs::mode());

        $this->mode('conset');             // a typo
        $this->assertSame(CookiePrefs::MODE_EXEMPT, CookiePrefs::mode());
    }

    public function test_an_unreadable_cookie_is_not_an_answer(): void
    {
        foreach (['', 'yes', '1', 'true', 'n0', 'yn'] as $junk) {
            $got = CookiePrefs::choice($this->req('/', [CookiePrefs::COOKIE => $junk]));
            $this->assertNull($got, "'{$junk}' was read as a decision");
        }

        // Case and surrounding space are forgiven, because a cookie value can be re-served
        // by a proxy or a browser extension with either, and refusing to read somebody's
        // own answer back would silently return them to the default.
        $this->assertTrue(CookiePrefs::choice($this->req('/', [CookiePrefs::COOKIE => 'Y'])));
        $this->assertTrue(CookiePrefs::choice($this->req('/', [CookiePrefs::COOKIE => ' y '])));
        $this->assertFalse(CookiePrefs::choice($this->req('/', [CookiePrefs::COOKIE => 'N'])));
    }

    // ══ the tracker and the page must agree ══════════════════════════════════

    public function test_the_tracker_records_nothing_once_the_visitor_has_refused(): void
    {
        // The whole point of the feature, asserted against the table rather than against
        // the resolver — a resolver that answers correctly while the tracker ignores it is
        // the exact shape of two-readers fault this class was extracted to prevent.
        $before = (int) DB::table('gates_visits')->count();

        VisitTracker::record($this->req('/nominees', [CookiePrefs::COOKIE => CookiePrefs::NO]));

        $this->assertSame($before, (int) DB::table('gates_visits')->count(),
            'a refusal was stored and the arrival was recorded anyway');
    }

    public function test_the_tracker_records_nothing_in_consent_mode_until_they_agree(): void
    {
        $this->mode(CookiePrefs::MODE_CONSENT);

        $before = (int) DB::table('gates_visits')->count();
        VisitTracker::record($this->req('/nominees'));
        $this->assertSame($before, (int) DB::table('gates_visits')->count(),
            'ask-first counted somebody who had not been asked');

        $_SESSION = [];
        VisitTracker::record($this->req('/nominees', [CookiePrefs::COOKIE => CookiePrefs::YES]));
        $this->assertSame($before + 1, (int) DB::table('gates_visits')->count(),
            'somebody agreed and was still not counted');
    }

    // ══ the notice ═══════════════════════════════════════════════════════════

    public function test_the_notice_is_shown_only_when_there_is_something_to_ask(): void
    {
        // Default posture: nothing to ask, so nothing is drawn. A banner under the exempt
        // posture would be asking permission for something we are not doing, which is the
        // argument the page makes and would be contradicting.
        $this->assertFalse(CookiePrefs::mustAsk($this->req()));

        $this->mode(CookiePrefs::MODE_CONSENT);
        $this->assertTrue(CookiePrefs::mustAsk($this->req()));

        // Already answered — either way.
        $this->assertFalse(CookiePrefs::mustAsk($this->req('/', [CookiePrefs::COOKIE => CookiePrefs::YES])));
        $this->assertFalse(CookiePrefs::mustAsk($this->req('/', [CookiePrefs::COOKIE => CookiePrefs::NO])));

        // Their browser has already answered. Asking somebody to agree to something we
        // have decided not to do is a dark pattern whether or not anybody meant it to be.
        $this->assertFalse(CookiePrefs::mustAsk($this->req('/', [], ['Sec-GPC' => '1'])));

        // And not on the page that carries the full control, two inches below.
        $this->assertFalse(CookiePrefs::mustAsk($this->req('/cookies')));
    }

    public function test_the_notice_is_not_shown_when_the_tracker_is_switched_off(): void
    {
        $this->mode(CookiePrefs::MODE_CONSENT);
        DB::table('gates_settings')->insert(['key_name' => 'visits_enabled', 'value' => '0']);

        $this->assertFalse(VisitTracker::enabled());
        $this->assertFalse(CookiePrefs::mustAsk($this->req()),
            'a consent question was put to somebody with nothing to consent to');
    }

    public function test_the_memo_is_primed_by_the_request_and_not_guessed(): void
    {
        // Null, not false, before anything observes: a notice that appeared because a memo
        // was never primed would appear on the admin console and in every render test.
        $this->assertFalse(CookiePrefs::asking(),
            'the layout would draw a notice on a request nothing observed');

        $this->mode(CookiePrefs::MODE_CONSENT);
        CookiePrefs::observe($this->req('/nominees'));

        $this->assertTrue(CookiePrefs::asking());
        $this->assertSame('/nominees', CookiePrefs::returnPath());
    }

    // ══ the cookie it writes, and the redirect it takes ══════════════════════

    public function test_the_cookie_is_written_with_the_flags_the_policy_claims(): void
    {
        $h = (new Response())->getHeaderLine('Set-Cookie');
        $this->assertSame('', $h);

        $set = CookiePrefs::apply(new Response(), true)->getHeaderLine('Set-Cookie');

        $this->assertStringStartsWith(CookiePrefs::COOKIE . '=' . CookiePrefs::YES, $set);
        $this->assertStringContainsString('Path=/', $set);
        $this->assertStringContainsString('HttpOnly', $set);
        $this->assertStringContainsString('SameSite=Lax', $set);
        $this->assertStringContainsString('Max-Age=' . (CookiePrefs::TTL_DAYS * 86400), $set);

        $no = CookiePrefs::apply(new Response(), false)->getHeaderLine('Set-Cookie');
        $this->assertStringStartsWith(CookiePrefs::COOKIE . '=' . CookiePrefs::NO, $no);
    }

    public function test_the_return_path_cannot_be_turned_into_an_open_redirect(): void
    {
        // The notice writes the path it was drawn on into a hidden field, and that field
        // ends up in a Location header. "Begins with a slash" is NOT the check:
        // `//evil.example` is a protocol-relative URL a browser follows off-site.
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
        ] as $in => $want) {
            $this->assertSame($want, CookiePrefs::safeReturn($in), "safeReturn('{$in}')");
        }
    }
}
