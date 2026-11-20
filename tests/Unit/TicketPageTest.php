<?php
declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Database\Capsule\Manager as DB;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * THE TICKET PAGE — Phase 7 (§8.11), rebuilt from TicketPage.dc.html.
 *
 * Re-asserts the rules the destroyed `TicketPrintTest`/`TicketTierColourTest` methods held
 * (inventory: pages--events--ticket.md) against the new markup, plus §8.11's additions.
 */
final class TicketPageTest extends TestCase
{
    private function ticket(string $status, array $event = [], array $tier = [], array $reg = []): string
    {
        $e = (int) DB::table('gates_site_events')->insertGetId($event + ['slug' => 'tk-ev', 'title' => 'Ticket Gala',
            'event_date' => date('Y-m-d H:i:s', time() + 10 * 86400), 'location' => 'Lagos', 'venue' => 'Eko Hall',
            'status' => 'published', 'ticket_accent' => '#1F6FA3', 'cover_image' => '/uploads/x.jpg']);
        $t = (int) DB::table('gates_event_tiers')->insertGetId($tier + ['event_id' => $e, 'slug' => 'g', 'name' => 'Reserved',
            'price_naira' => 5000, 'is_active' => 1, 'sort_order' => 1, 'colour' => 'cool']);
        $ref = 'AFG-EVT-TK' . substr(md5(uniqid('', true)), 0, 8);
        DB::table('gates_event_registrations')->insert($reg + ['event_id' => $e, 'tier_id' => $t, 'tier' => 'Reserved', 'name' => 'Chioma Obi',
            'email' => 'c@mail.test', 'quantity' => 2, 'status' => $status, 'reference' => $ref, 'amount_naira' => 10000,
            'ticket_code' => 'KCEA7Q4M', 'created_at' => date('Y-m-d H:i:s')]);
        return $ref;
    }

    public function test_a_confirmed_ticket_has_the_qr_and_the_printed_code_always_both(): void
    {
        $html = ChromeRender::html('/events/ticket/' . $this->ticket('confirmed'));
        $this->assertStringContainsString('class="tk-qr"><svg', $html);
        $this->assertStringContainsString('<b class="tk-code">KCEA7Q4M</b>', $html);
        $this->assertStringContainsString('Valid · admits 2', $html, 'the status chip');
        $this->assertStringContainsString('in 10 days', $html, 'the countdown in the kicker, with no script');
        $this->assertStringContainsString('/ticket.pdf"', $html);
    }

    public function test_nothing_scannable_unless_confirmed(): void
    {
        $html = ChromeRender::html('/events/ticket/' . $this->ticket('pending'));
        $this->assertStringNotContainsString('tk-qr', $html);
        $this->assertStringNotContainsString('KCEA7Q4M', $html, 'a pending payment shows no code at all');
        $this->assertStringNotContainsString('/ticket.pdf"', $html);
        $this->assertStringNotContainsString('>Paid<', $html, 'priced is not paid');
        $this->assertStringNotContainsString('data-tk-manage', $html);
    }

    public function test_checked_in_says_so_and_the_manage_panel_goes(): void
    {
        $html = ChromeRender::html('/events/ticket/' . $this->ticket('confirmed', [], [], ['checked_in_at' => date('Y-m-d H:i:s')]));
        $this->assertStringContainsString('tk-chip--in', $html);
        $this->assertStringNotContainsString('data-tk-manage', $html);
    }

    public function test_the_tier_dot_is_the_events_accent_and_moves_with_it(): void
    {
        $a = ChromeRender::html('/events/ticket/' . $this->ticket('confirmed'));
        DB::table('gates_site_events')->update(['ticket_accent' => '#B4452F']);
        $b = ChromeRender::html('/events/ticket/' . DB::table('gates_event_registrations')->value('reference'));
        preg_match('~class="tk-dot"[^>]*--tk-dot:(#[0-9A-F]{6})~i', $a, $ma);
        preg_match('~class="tk-dot"[^>]*--tk-dot:(#[0-9A-F]{6})~i', $b, $mb);
        $this->assertNotEmpty($ma); $this->assertNotEmpty($mb);
        $this->assertNotSame($ma[1], $mb[1], 'the slot, not a hex: changing the accent moves the dot');
    }

    public function test_a_tier_with_no_slot_has_no_dot_but_keeps_its_name(): void
    {
        $html = ChromeRender::html('/events/ticket/' . $this->ticket('confirmed', [], ['colour' => '']));
        $this->assertStringNotContainsString('tk-dot', $html);
        $this->assertStringContainsString('Reserved', $html);
    }

    public function test_the_hero_is_not_lazy_and_the_url_is_printed_as_text(): void
    {
        $html = ChromeRender::html('/events/ticket/' . $this->ticket('confirmed'));
        $this->assertMatchesRegularExpression('~<img class="tk-hero__img"[^>]*fetchpriority="high"~', $html);
        $this->assertDoesNotMatchRegularExpression('~<img class="tk-hero__img"[^>]*loading="lazy"~', $html);
        $this->assertMatchesRegularExpression('~<span class="tk-ref">[^<]*/events/ticket/AFG-EVT-TK~', $html, 'paper cannot be clicked');
        $this->assertStringNotContainsString('add to wallet', strtolower(strip_tags($html)),
            'no wallet button: there is no pass-signing behind it');
    }

    public function test_paper_is_the_pdfs_card(): void
    {
        $css = ChromeRender::code('public/assets/css/components/ticket.css');
        $print = substr($css, (int) strpos($css, '@media print'));
        $this->assertMatchesRegularExpression('~@page\{ margin:10mm \}~', $css, 'a margin only: an invalid size is silently dropped');
        $this->assertMatchesRegularExpression('~\.tk\{[^}]*width:180mm; height:86mm~', $print, 'the same card TicketPdf::one prints');
        $this->assertStringContainsString('$w = 180.0;', (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/TicketPdf.php'));
        $this->assertStringContainsString('$h = 86.0;', (string) file_get_contents(dirname(__DIR__, 2) . '/src/Services/TicketPdf.php'));
        $this->assertMatchesRegularExpression('~\.tk-qr\{ width:30mm; height:30mm~', $print, 'the QR has a physical size');
        $this->assertMatchesRegularExpression('~\.tk-page, \.tk-page--dark\{[^}]*background:var\(--ag-surface\)~', $print, 'the dark theme prints light');
        $this->assertMatchesRegularExpression('~\.tk-hero__img\{ position:static~', $print, 'the artwork is in flow on paper');
        $this->assertMatchesRegularExpression('~\.tk-manage[^{]*\{ display:none !important \}~', $print, 'the manage panel does not print');
        $this->assertMatchesRegularExpression('~\.tk-fact--print\{ display:flex \}~', $print, 'date repeated on white for paper');
    }

    public function test_an_unknown_reference_says_nothing_about_whether_it_exists(): void
    {
        $res = ChromeRender::page('/events/ticket/AFG-EVT-NOPE0000');
        $this->assertSame(404, $res->getStatusCode());
        $this->assertStringContainsString('This ticket link is not working', (string) $res->getBody());
        $this->assertSame('noindex, nofollow', $res->getHeaderLine('X-Robots-Tag'));
    }
}
