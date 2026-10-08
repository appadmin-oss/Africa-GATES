<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EventSaleAlert;
use AfricaGates\Services\OtpService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * "EMAIL ME WHEN TICKETS GO ON SALE" — the coming-soon state's notify-me (owner, 4 Oct 2026).
 *
 * Double opt-in (nothing but one confirmation reaches an unconfirmed row, at most once a
 * day), announced only once sales actually open, CLAIMED before it is sent so two ticks
 * cannot mail one person twice, sent as an announcement (an unsubscribe link, so
 * Mail\SendPolicy applies the opt-out list at the transport), and stoppable by one link with
 * no account. The confirm and stop links act on POST only — mail scanners fetch every link.
 */
final class EventSaleAlertTest extends TestCase
{
    /** @var list<array<string,string>> */
    private array $sent = [];

    private function mailer(): OtpService
    {
        return new class($this->sent) extends OtpService {
            /** @param list<array<string,string>> $sink */
            public function __construct(private array &$sink) { parent::__construct([]); }
            public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '',
                                        string $category = '', string $hero = '', string $unsubscribeUrl = '',
                                        array $attachments = [], string $preheader = '', int $heroHeight = 0): array
            {
                $this->sink[] = ['to' => $to, 'subject' => $subject, 'body' => $htmlBody . ' ' . $plainBody, 'unsub' => $unsubscribeUrl];
                return ['success' => true];
            }
        };
    }

    /** @return array{0:int,1:int} event id, tier id — tickets not on sale for nine days */
    private function soon(): array
    {
        $e = (int) DB::table('gates_site_events')->insertGetId(['slug' => 'alert-ev', 'title' => 'Alert Gala',
            'event_date' => date('Y-m-d H:i:s', time() + 40 * 86400), 'status' => 'published']);
        $t = (int) DB::table('gates_event_tiers')->insertGetId(['event_id' => $e, 'slug' => 'g', 'name' => 'General',
            'price_naira' => 1000, 'is_active' => 1, 'sort_order' => 1,
            'sale_starts_at' => date('Y-m-d H:i:s', time() + 9 * 86400)]);
        return [$e, $t];
    }

    private function send(): callable
    {
        return function (string $to, string $s, string $h, string $p): bool {
            $this->sent[] = ['to' => $to, 'subject' => $s, 'body' => $h . ' ' . $p, 'unsub' => ''];
            return true;
        };
    }

    public function test_asking_sends_one_confirmation_and_nothing_else_until_it_is_pressed(): void
    {
        [$e] = $this->soon();
        EventSaleAlert::want($e, 'Ada@Mail.test ', 'ip', 'https://site.test', $this->send());
        EventSaleAlert::want($e, 'ada@mail.test', 'ip', 'https://site.test', $this->send());
        $this->assertCount(1, $this->sent, 'one confirmation a day, however often the form is posted');
        $this->assertStringContainsString('/events/alerts/', $this->sent[0]['body']);
        $this->assertSame(1, DB::table('gates_event_sale_alerts')->where('event_id', $e)->count());

        // On sale now — but the row was never confirmed, so it is never told, even while a
        // confirmed neighbour on the same event is.
        EventSaleAlert::want($e, 'other@mail.test', 'ip', 'https://site.test', $this->send());
        EventSaleAlert::confirm((string) DB::table('gates_event_sale_alerts')->where('email', 'other@mail.test')->value('token'));
        DB::table('gates_event_tiers')->where('event_id', $e)->update(['sale_starts_at' => null]);
        $this->sent = [];
        $this->assertSame(1, EventSaleAlert::sweep($this->mailer(), 'https://site.test'));
        $this->assertSame(['other@mail.test'], array_column($this->sent, 'to'));
    }

    public function test_the_reply_is_the_same_whatever_happened(): void
    {
        [$e] = $this->soon();
        $a = EventSaleAlert::want($e, 'a@mail.test', 'ip', 'https://site.test', $this->send());
        $b = EventSaleAlert::want($e, 'a@mail.test', 'ip', 'https://site.test', $this->send());
        $this->assertSame($a['message'], $b['message'], 'the form is not a lookup of who asked about what');
    }

    public function test_a_confirmed_alert_waits_for_sales_then_is_told_once(): void
    {
        [$e] = $this->soon();
        EventSaleAlert::want($e, 'b@mail.test', 'ip', 'https://site.test', $this->send());
        $tok = (string) DB::table('gates_event_sale_alerts')->where('event_id', $e)->value('token');
        $this->assertNotNull(EventSaleAlert::confirm($tok));
        $this->sent = [];

        $this->assertSame(0, EventSaleAlert::sweep($this->mailer(), 'https://site.test'), 'not on sale yet: nothing to announce');
        DB::table('gates_event_tiers')->where('event_id', $e)->update(['sale_starts_at' => date('Y-m-d H:i:s', time() - 60)]);

        $this->assertSame(1, EventSaleAlert::sweep($this->mailer(), 'https://site.test'));
        $this->assertSame(0, EventSaleAlert::sweep($this->mailer(), 'https://site.test'), 'claimed before sent: a second tick sends nothing');
        $this->assertCount(1, $this->sent);
        $this->assertNotSame('', $this->sent[0]['unsub'], 'an announcement carries an unsubscribe link, so SendPolicy holds the opt-out list');
        $this->assertStringContainsString('/events/alerts/' . $tok . '/stop', $this->sent[0]['body'], 'and its own stop link');
    }

    public function test_a_stopped_alert_is_never_sent(): void
    {
        [$e] = $this->soon();
        EventSaleAlert::want($e, 'c@mail.test', 'ip', 'https://site.test', $this->send());
        $tok = (string) DB::table('gates_event_sale_alerts')->where('event_id', $e)->value('token');
        EventSaleAlert::confirm($tok);
        EventSaleAlert::stop($tok);
        DB::table('gates_event_tiers')->where('event_id', $e)->update(['sale_starts_at' => null]);
        $this->sent = [];
        $this->assertSame(0, EventSaleAlert::sweep($this->mailer(), 'https://site.test'));
    }

    public function test_the_links_act_on_post_only(): void
    {
        [$e] = $this->soon();
        EventSaleAlert::want($e, 'd@mail.test', 'ip', 'https://site.test', $this->send());
        $tok = (string) DB::table('gates_event_sale_alerts')->where('event_id', $e)->value('token');

        $get = ChromeRender::page('/events/alerts/' . $tok . '/confirm');
        $this->assertSame(200, $get->getStatusCode());
        $this->assertNull(DB::table('gates_event_sale_alerts')->where('token', $tok)->value('confirmed_at'), 'a scanner fetching the link confirms nothing');

        ChromeRender::page('/events/alerts/' . $tok . '/confirm', [], [], 'POST', ['_token' => 'test-token']);
        $this->assertNotNull(DB::table('gates_event_sale_alerts')->where('token', $tok)->value('confirmed_at'));
        $this->assertSame(404, ChromeRender::page('/events/alerts/' . str_repeat('0', 32) . '/confirm')->getStatusCode());
    }

    public function test_the_form_posts_and_redirects_back_with_the_reply(): void
    {
        $this->soon();
        $res = ChromeRender::page('/events/alert-ev/notify', [], [], 'POST', ['_token' => 'test-token', 'email' => 'e@mail.test']);
        $this->assertSame(303, $res->getStatusCode());
        $this->assertSame('/events/alert-ev#ev-rsvp', $res->getHeaderLine('Location'));
        $this->assertSame(1, DB::table('gates_event_sale_alerts')->count());
    }

    public function test_the_sweep_runs_on_every_maintenance_tick(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Support/Maintenance.php');
        $this->assertStringContainsString("EventSaleAlert::sweep(\$this->mailer())", $src, 'a mechanism with no route in is not a mechanism');
    }
}
