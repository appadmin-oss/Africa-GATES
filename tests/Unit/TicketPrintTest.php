<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Printing a ticket, which happens far more often than the web pretends.
 *
 * A guest with a cracked phone, a battery that will not last the day, a door team handing
 * paper to a guest list, a sponsor's table of twelve. Paper is the fallback the whole
 * ticketing system leans on, so it is a designed artefact — and almost everything asserted
 * below is a failure mode that produces a ticket which looks fine on screen and is useless
 * once it comes off the printer.
 */
class TicketPrintTest extends TestCase
{
    private function makeEvent(array $over = []): object
    {
        $id = (int) DB::table('gates_site_events')->insertGetId($over + [
            'title'      => 'Ogidì Ọmọ',
            'slug'       => 'ogidi-' . bin2hex(random_bytes(4)),
            'event_date' => date('Y-m-d H:i:s', strtotime('+30 days')),
            'venue'      => '88 College Road',
            'location'   => 'Igando, Lagos',
            'status'     => 'published',
        ]);
        return DB::table('gates_site_events')->where('id', $id)->first();
    }

    private function makeReg(int $eventId, array $over = []): array
    {
        $ref = 'AGT-' . bin2hex(random_bytes(5));
        $id  = (int) DB::table('gates_event_registrations')->insertGetId($over + [
            'event_id'     => $eventId,
            'name'         => 'Adaeze Okonkwo',
            'email'        => 'adaeze-' . bin2hex(random_bytes(3)) . '@example.test',
            'reference'    => $ref,
            'ticket_code'  => strtoupper(bin2hex(random_bytes(4))),
            'status'       => 'confirmed',
            'tier'         => 'Standard',
            'amount_naira' => 25000,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
        return (array) DB::table('gates_event_registrations')->where('id', $id)->first();
    }

    private function container(): \Psr\Container\ContainerInterface
    {
        $b = new \DI\ContainerBuilder();
        $b->addDefinitions(dirname(__DIR__, 2) . '/config/container.php');
        return $b->build();
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_role'], $_SESSION['flash_error']);
        parent::tearDown();
    }

    // ────────────────────────── the attendee's own ticket ───────────────────

    private function ticketHtml(string $ref): string
    {
        $c   = $this->container()->get(\AfricaGates\Controllers\EventsController::class);
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/events/ticket/' . $ref);
        return (string) $c->ticket($req, new Response(), ['ref' => $ref])->getBody();
    }

    /**
     * The QR must be sized in MILLIMETRES for print.
     *
     * It is the one thing on the page whose size is a physical fact rather than a
     * typographic taste. Left in viewport units it prints at whatever the browser decides,
     * and a ticket that came off the printer unscannable is not discovered until the door.
     */
    public function test_the_printed_qr_has_a_physical_size(): void
    {
        $e   = $this->makeEvent();
        $reg = $this->makeReg((int) $e->id);

        $html = $this->ticketHtml($reg['reference']);
        $this->assertStringContainsString('@page', $html, 'Print geometry has to be declared.');
        $this->assertMatchesRegularExpression(
            '~\.tk__qr svg\{[^}]*width:\s*\d+mm~',
            $html,
            'A QR sized in anything but millimetres prints at an unpredictable size.'
        );
    }

    /**
     * The dark theme must print light.
     *
     * As-is it is either a solid black page — a cartridge per ticket — or, once the driver's
     * ink-saving default drops the fill, pale grey text on white paper, which is nothing.
     */
    public function test_the_dark_theme_is_forced_back_to_light_on_paper(): void
    {
        $e   = $this->makeEvent(['ticket_theme' => 'dark']);
        $reg = $this->makeReg((int) $e->id);

        $html  = $this->ticketHtml($reg['reference']);
        $print = substr($html, (int) strpos($html, '@media print'));
        $this->assertStringContainsString('.tk--dark{', str_replace(' ', '', $print),
            'The dark palette must be overridden inside the print block.');
    }

    /**
     * Date and venue must survive the colour dropping out.
     *
     * `print-color-adjust` is a request a driver may refuse and a user may switch off, and a
     * ticket printed for exactly the reason people print tickets — carrying no date and no
     * address — is the one document this page exists to prevent. So neither fact may live
     * ONLY in the accent band behind the title.
     *
     * ── WHY THIS NO LONGER LOOKS FOR A PRINT-ONLY CELL ───────────────────────
     *
     * It used to assert `tk__cell--print` around the address, because on the old white card
     * the address was in the coloured header on screen and revealed in the grid only on
     * paper. The rebuilt ticket puts the address and the date on the cream stub at every
     * size — the stub is a light panel in both media — so the guarantee is now stronger than
     * the mechanism this test was pinning. What is asserted is the property: the address and
     * the date are in the grid, and the grid is not hidden when the sheet is printed.
     */
    public function test_the_date_and_venue_are_repeated_on_white_for_print(): void
    {
        $e   = $this->makeEvent();
        $reg = $this->makeReg((int) $e->id);

        $html = $this->ticketHtml($reg['reference']);

        // In the grid, on the light stub — not only over the image.
        $this->assertMatchesRegularExpression(
            '~class="tk__cell[^"]*"[^>]*>\s*<small>Where</small>~s',
            $html,
            'The address has to appear somewhere that is not a coloured fill.'
        );
        $this->assertMatchesRegularExpression(
            '~class="tk__cell[^"]*"[^>]*>\s*<small>Date</small>~s',
            $html,
            'A printed ticket with no date is the document this page exists to avoid.'
        );

        // The 46mm stub cannot carry the day name and the time as two cells, so print swaps
        // in one joined line. Both halves of that swap have to be there, or the sheet prints
        // either nothing or the same fact twice.
        $this->assertStringContainsString('tk__cell--print', $html,
            'the print-only joined date cell is missing');
        $this->assertStringContainsString('tk__cell--screen', $html,
            'the screen-only date and doors cells are missing');

        $print = substr($html, (int) strpos($html, '@media print'));
        $this->assertStringContainsString('.tk__cell--screen{display:none}',
            str_replace([' ', "\n"], '', $print),
            'the screen cells must be dropped on paper, or the date prints twice');
        $this->assertStringNotContainsString('.tk__grid{display:none', str_replace(' ', '', $print),
            'the grid carries every fact that survives the accent being dropped');
    }

    /** The self-service form was documented as dropped from print, and was not. */
    public function test_the_manage_panel_does_not_print(): void
    {
        $e   = $this->makeEvent();
        $reg = $this->makeReg((int) $e->id);

        $html  = $this->ticketHtml($reg['reference']);
        $print = substr($html, (int) strpos($html, '@media print'));
        $this->assertStringContainsString('.tk__self', $print,
            'A page of form controls under a ticket somebody is about to hand over.');
    }

    /** Paper cannot be clicked, so the link that recovers a lost ticket is printed as text. */
    public function test_the_ticket_url_is_printed_as_text(): void
    {
        $e   = $this->makeEvent();
        $reg = $this->makeReg((int) $e->id);

        $html = $this->ticketHtml($reg['reference']);
        $this->assertStringContainsString('tk__url', $html);
        $this->assertStringContainsString('/events/ticket/' . $reg['reference'], $html);
    }

    // ────────────────────────────── the box office ──────────────────────────

    private function sheetPdf(int $eventId, array $query = []): string
    {
        $_SESSION['admin_id'] = 1; $_SESSION['admin_role'] = 'superadmin';
        $c   = $this->container()->get(\AfricaGates\Admin\Controllers\EventsController::class);
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/x')->withQueryParams($query);
        return (string) $c->printTickets($req, new Response(), ['id' => $eventId])->getBody();
    }

    /** Well-formed enough that a reader does not have to rebuild the cross-reference table. */
    private function assertValidPdf(string $pdf): void
    {
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringEndsWith('%%EOF', $pdf);
        $this->assertStringContainsString('/Type /Catalog', $pdf);

        // The xref offsets must actually address their objects. This is the check that caught
        // a one-off in the object numbering: every reference pointed one object past the one
        // it meant, and readers silently recovered by reconstructing the table — so it
        // rendered correctly and was still wrong.
        $this->assertSame(1, preg_match('/startxref\s+(\d+)/', $pdf, $m));
        $xref = substr($pdf, (int) $m[1]);
        $this->assertStringStartsWith('xref', $xref);

        preg_match_all('/^(\d{10}) 00000 n $/m', $xref, $rows);
        $this->assertNotEmpty($rows[1], 'The cross-reference table has no in-use entries.');
        foreach ($rows[1] as $i => $off) {
            $this->assertSame(
                ($i + 1) . ' 0 obj',
                substr($pdf, (int) $off, strlen((string) ($i + 1)) + 6),
                'xref entry ' . ($i + 1) . ' does not point at object ' . ($i + 1) . '.'
            );
        }
    }

    public function test_the_sheet_is_a_pdf_with_an_embedded_font(): void
    {
        $e = $this->makeEvent();
        $this->makeReg((int) $e->id, ['name' => 'Adaeze Okonkwo']);

        $pdf = $this->sheetPdf((int) $e->id);
        $this->assertValidPdf($pdf);
        // Embedded, not referenced. The fourteen fonts a PDF may use without embedding are
        // all WinAnsi, which cannot spell a single Yoruba name.
        $this->assertStringContainsString('/FontFile2', $pdf);
        $this->assertStringContainsString('/Identity-H', $pdf);
        // And selectable: a support call should not involve retyping a reference off a
        // screenshot.
        $this->assertStringContainsString('/ToUnicode', $pdf);
    }

    public function test_the_response_is_served_as_a_pdf(): void
    {
        $_SESSION['admin_id'] = 1; $_SESSION['admin_role'] = 'superadmin';
        $e = $this->makeEvent();
        $this->makeReg((int) $e->id);

        $c   = $this->container()->get(\AfricaGates\Admin\Controllers\EventsController::class);
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/x');
        $res = $c->printTickets($req, new Response(), ['id' => (int) $e->id]);

        $this->assertSame('application/pdf', $res->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('.pdf', $res->getHeaderLine('Content-Disposition'));
    }

    /**
     * A pending payment never becomes a document.
     *
     * Same doctrine as the attendee page, and stronger: paper carries an authority a screen
     * does not, and nobody at a gate assumes a printed ticket might still be provisional.
     */
    public function test_only_confirmed_registrations_are_printed(): void
    {
        $e = $this->makeEvent();
        $this->makeReg((int) $e->id, ['name' => 'Paid Person']);
        $this->makeReg((int) $e->id, ['name' => 'Unpaid Person', 'status' => 'pending']);
        $this->makeReg((int) $e->id, ['name' => 'Cancelled Person', 'status' => 'cancelled']);

        // One ticket on the sheet means one page; the other two were never drawn.
        $this->assertSame(1, $this->pageCount($this->sheetPdf((int) $e->id)));
    }

    private function pageCount(string $pdf): int
    {
        $this->assertSame(1, preg_match('~/Type /Pages /Kids \[(.*?)\] /Count (\d+)~', $pdf, $m));
        return (int) $m[2];
    }

    /** Seven tickets at three to a page is three sheets, not two with a ticket missing. */
    public function test_the_sheet_paginates(): void
    {
        $e = $this->makeEvent();
        for ($i = 0; $i < 7; $i++) $this->makeReg((int) $e->id, ['name' => 'Guest ' . $i]);

        $this->assertSame(3, $this->pageCount($this->sheetPdf((int) $e->id)));
        // Two to a page turns the same seven into four sheets.
        $this->assertSame(4, $this->pageCount($this->sheetPdf((int) $e->id, ['per' => '2'])));
        // Anything else falls back to three rather than dividing by whatever was in the query.
        $this->assertSame(3, $this->pageCount($this->sheetPdf((int) $e->id, ['per' => '99'])));
    }

    public function test_the_sheet_can_be_narrowed_to_one_ticket_type(): void
    {
        $e = $this->makeEvent();
        for ($i = 0; $i < 7; $i++) $this->makeReg((int) $e->id, ['tier' => 'Standard']);
        $this->makeReg((int) $e->id, ['tier' => 'VIP']);

        // Eight tickets need three pages; the one VIP needs one.
        $this->assertSame(3, $this->pageCount($this->sheetPdf((int) $e->id)));
        $this->assertSame(1, $this->pageCount($this->sheetPdf((int) $e->id, ['tier' => 'VIP'])));
    }

    /** An empty sheet is still a document, and says why rather than being a blank page. */
    public function test_an_empty_sheet_explains_itself(): void
    {
        $e = $this->makeEvent();
        $this->makeReg((int) $e->id, ['status' => 'pending']);

        $pdf = $this->sheetPdf((int) $e->id);
        $this->assertValidPdf($pdf);
        $this->assertSame(1, $this->pageCount($pdf));
    }

    /** The filename carries the count, because a truncated sheet must not read as everyone. */
    public function test_the_filename_says_how_many_of_how_many(): void
    {
        $_SESSION['admin_id'] = 1; $_SESSION['admin_role'] = 'superadmin';
        $e = $this->makeEvent();
        $this->makeReg((int) $e->id);
        $this->makeReg((int) $e->id);

        $c   = $this->container()->get(\AfricaGates\Admin\Controllers\EventsController::class);
        $res = $c->printTickets((new ServerRequestFactory())->createServerRequest('GET', '/x'),
                                new Response(), ['id' => (int) $e->id]);

        $this->assertStringContainsString('2of2', $res->getHeaderLine('Content-Disposition'));
    }

    // ───────────────────────── the attendee's own PDF ───────────────────────

    public function test_an_attendee_can_download_their_ticket_as_a_pdf(): void
    {
        $e   = $this->makeEvent();
        $reg = $this->makeReg((int) $e->id);

        $c   = $this->container()->get(\AfricaGates\Controllers\EventsController::class);
        $res = $c->ticketPdf((new ServerRequestFactory())->createServerRequest('GET', '/x'),
                             new Response(), ['ref' => $reg['reference']]);

        $this->assertSame('application/pdf', $res->getHeaderLine('Content-Type'));
        $this->assertValidPdf((string) $res->getBody());
    }

    public function test_a_pending_booking_gets_no_pdf(): void
    {
        $e   = $this->makeEvent();
        $reg = $this->makeReg((int) $e->id, ['status' => 'pending']);

        $c   = $this->container()->get(\AfricaGates\Controllers\EventsController::class);
        $res = $c->ticketPdf((new ServerRequestFactory())->createServerRequest('GET', '/x'),
                             new Response(), ['ref' => $reg['reference']]);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringContainsString('/events/ticket/', $res->getHeaderLine('Location'));
    }
}
