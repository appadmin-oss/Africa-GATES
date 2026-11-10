<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * The service worker (public/sw.js, 10 Nov 2026): our own page when the network drops, and
 * fingerprinted files kept on the device. Measured in a browser when written: installed,
 * offline /vote showed "You're offline" styled and at the address that failed, back online
 * "Try again" loaded the real page.
 *
 * What must never happen is held here, because each would be silent: a PAGE served from a
 * cache (a ballot or a wallet from yesterday), an unfingerprinted file cached (yesterday's
 * stylesheet for a month), an upload cached (a photo a nominee removed living on), a console
 * or a payment hook intercepted, or the cached offline page holding somebody's details.
 */
final class ServiceWorkerTest extends TestCase
{
    private static function sw(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/public/sw.js');
    }

    public function test_pages_come_from_the_network_and_only_a_failure_gets_the_offline_page(): void
    {
        $js = self::sw();
        $this->assertMatchesRegularExpression("/if \\(req\\.mode === 'navigate'\\) \\{\\s*event\\.respondWith\\(fetch\\(req\\)\\.catch\\(/", $js);
        $this->assertStringContainsString("if (req.method !== 'GET') return;", $js);
        $this->assertStringContainsString("fetch(OFFLINE, { credentials: 'omit'", $js, 'the kept offline page is fetched signed out');
    }

    public function test_only_fingerprinted_files_and_fonts_are_kept_and_never_uploads_or_consoles(): void
    {
        $js = self::sw();
        preg_match('/var SKIP = (\/.*\/);/', $js, $m);
        foreach (['admin', 'judge', 'api', 'hooks', '__', 'uploads'] as $p) $this->assertStringContainsString($p, $m[1] ?? '', "/$p is never intercepted");
        $this->assertStringContainsString("var FINGERPRINT = /\\.[0-9a-f]{8,}\\.(css|js|woff2?|svg|png|webp|jpg)$|[?&]v=[0-9a-f]{6,}/;", $js);
    }

    public function test_the_offline_page_carries_nobodys_details_and_is_not_indexed(): void
    {
        $html = (string) ChromeRender::page('/offline', ['user_id' => 1])->getBody();
        $this->assertStringContainsString('You’re offline', $html);
        $this->assertStringContainsString('noindex', $html);
        foreach (['ag-consent', 'data-gee', 'class="ag-tabbar'] as $absent) $this->assertStringNotContainsString($absent, $html, "$absent on the offline page");
    }

    public function test_it_is_registered_installable_and_never_long_cached(): void
    {
        $this->assertStringContainsString("navigator.serviceWorker.register('/sw.js')", (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/shell.js'));
        $m = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/public/site.webmanifest'), true);
        $this->assertSame('standalone', $m['display'] ?? null, 'without display the site cannot be added as an app');
        $this->assertSame('/', $m['scope'] ?? null);
        $this->assertStringContainsString('<Files "sw.js">', (string) file_get_contents(dirname(__DIR__, 2) . '/public/.htaccess'));
    }
}
