<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\GoogleSheetsService;
use PHPUnit\Framework\TestCase;

/**
 * The sheet push sends the shared secret and believes the script's answer, not its status.
 *
 * Apps Script answers 200 for everything — a refused token, an unknown tab and a quota error
 * are `{"success":false}` under a 200 — so a push that checked only the status reported every
 * refusal as a row written. And with no token sent, a script with its SECRET set could not be
 * written to at all, while a script without one let anybody holding the URL write rows.
 */
final class GoogleSheetsPushTest extends TestCase
{
    protected function tearDown(): void
    {
        GoogleSheetsService::$transport = null;
        parent::tearDown();
    }

    public function test_the_token_travels_and_a_refusal_under_200_is_a_failure(): void
    {
        $sent = null;
        GoogleSheetsService::$transport = static function (string $url, string $body) use (&$sent): array {
            $sent = json_decode($body, true);
            return ['code' => 200, 'body' => '{"success":false,"message":"Bad token"}'];
        };
        $s = new GoogleSheetsService('https://script.google.com/macros/s/x/exec', null, 'the-shared-secret');
        $this->assertFalse($s->push('partners', ['org_name' => 'X']), 'a 200 carrying success:false is not a row written');
        $this->assertSame('the-shared-secret', $sent['token'] ?? null);
        $this->assertSame('partners', $sent['sheet']);

        GoogleSheetsService::$transport = static fn() => ['code' => 200, 'body' => '{"success":true,"message":"Written","row":7}'];
        $this->assertTrue($s->push('partners', ['org_name' => 'X']));
        GoogleSheetsService::$transport = static fn() => ['code' => 200, 'body' => '<html>stale deployment</html>'];
        $this->assertFalse($s->push('partners', ['org_name' => 'X']), 'an HTML error page is not a success');
    }

    public function test_the_script_guards_sheet_writes_and_refuses_unknown_tabs(): void
    {
        $gs = (string) file_get_contents(dirname(__DIR__, 2) . '/config/AfricaGATES_AppScript.gs');
        $sheetBranch = substr($gs, (int) strpos($gs, "const sheet = (body.sheet||'')") - 400, 900);
        $this->assertStringContainsString("if(SECRET && body.token !== SECRET) return respond(false,'Bad token');", $sheetBranch);
        $this->assertStringContainsString("if(!HEADERS[sheet]) return respond(false,'Unknown sheet: '+sheet);", $gs);
    }
}
