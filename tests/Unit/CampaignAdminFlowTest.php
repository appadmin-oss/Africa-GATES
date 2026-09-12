<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Admin\Controllers\CampaignsController;
use AfricaGates\Services\EmailCampaign;
use DI\ContainerBuilder;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\TestCase;

/**
 * The screens, driven through the real container.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE ORDER OF THE SCREENS IS THE THING BEING TESTED
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Edit → preview → test-send → approve → read the plan → send. `HANDOFF.md` §6: "Nobody
 * should be able to reach 'send' without having seen who it reaches", and the blast radius
 * is every nominee's inbox with no undo. The unit tests above hold the copy rules; these
 * hold the gates, because a gate that exists in a service and not on the route is not a gate.
 */
final class CampaignAdminFlowTest extends TestCase
{
    private function ctl(): CampaignsController
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(require dirname(__DIR__, 2) . '/config/container.php');
        return $b->build()->get(CampaignsController::class);
    }

    private function req(string $method, string $path, array $post = [])
    {
        $r = (new ServerRequestFactory())->createServerRequest($method, $path);
        return $post === [] ? $r : $r->withParsedBody($post);
    }

    private function res()
    {
        return (new ResponseFactory())->createResponse();
    }

    private function asRole(string $role): void
    {
        $_SESSION['admin_id']   = 1;
        $_SESSION['admin_role'] = $role;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    // ══ access ═══════════════════════════════════════════════════════════════

    public function test_a_viewer_can_read_the_list_but_not_write(): void
    {
        $this->asRole('viewer');

        $this->assertSame(200, $this->ctl()->index($this->req('GET', '/admin/campaigns'), $this->res())->getStatusCode());

        $res = $this->ctl()->create($this->req('POST', '/admin/campaigns/new', ['name' => 'X', 'subject' => 'Y']), $this->res());
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/admin', $res->getHeaderLine('Location'));
    }

    /**
     * A moderator runs interviews but does not mail every nominee. That reach is the reason
     * this is narrower than the interviews screen next to it.
     */
    public function test_a_moderator_cannot_write_a_campaign(): void
    {
        $this->asRole('moderator');

        $res = $this->ctl()->create($this->req('POST', '/admin/campaigns/new', ['name' => 'X', 'subject' => 'Y']), $this->res());
        $this->assertSame('/admin', $res->getHeaderLine('Location'));
    }

    // ══ the flow ═════════════════════════════════════════════════════════════

    private function newCampaign(): int
    {
        $this->asRole('superadmin');
        $this->ctl()->create(
            $this->req('POST', '/admin/campaigns/new', ['name' => 'August final hours', 'subject' => 'Finish strong']),
            $this->res()
        );
        $c = EmailCampaign::all()[0] ?? null;
        $this->assertNotNull($c, 'the campaign was not created');
        return (int) $c->id;
    }

    public function test_the_edit_page_renders_with_the_plan_on_it(): void
    {
        $id = $this->newCampaign();

        $res = $this->ctl()->show($this->req('GET', '/admin/campaigns/' . $id), $this->res(), ['id' => $id]);
        $this->assertSame(200, $res->getStatusCode());

        $html = (string) $res->getBody();
        $this->assertStringContainsString('Who this reaches', $html, 'the plan must be on the page, not behind it');
        $this->assertStringContainsString('Finish strong', $html);
        // The send control exists but must not be usable yet.
        $this->assertStringContainsString('Approve', $html);
        $this->assertMatchesRegularExpression('/Send to[^<]*<\/button>/s', $html);
    }

    /** A form post becomes blocks — no JSON in a textarea between a comms person and their copy. */
    public function test_saving_the_form_updates_the_copy(): void
    {
        $id = $this->newCampaign();

        $this->ctl()->save($this->req('POST', '/admin/campaigns/' . $id, [
            'subject'   => 'A new subject',
            'preheader' => 'A new preheader',
            'blocks'    => [
                0 => ['type' => 'hero', 'headline' => 'Nearly', 'accent' => 'there', 'standfirst' => 'Hello {first_name|Friend}.'],
                1 => ['type' => 'paragraph', 'text' => 'One short paragraph.'],
            ],
        ]), $this->res(), ['id' => $id]);

        $c = EmailCampaign::find($id);
        $this->assertSame('A new subject', $c->subject);

        $blocks = EmailCampaign::blocksOf($c);
        $this->assertCount(2, $blocks);
        $this->assertSame('Nearly', $blocks[0]['headline']);
        $this->assertSame('One short paragraph.', $blocks[1]['text']);
    }

    public function test_block_order_follows_the_form_indices(): void
    {
        $id = $this->newCampaign();

        // Posted out of order, as a browser can after a row is removed.
        $this->ctl()->save($this->req('POST', '/admin/campaigns/' . $id, [
            'subject' => 'S',
            'blocks'  => [
                2 => ['type' => 'paragraph', 'text' => 'Third.'],
                0 => ['type' => 'paragraph', 'text' => 'First.'],
                1 => ['type' => 'paragraph', 'text' => 'Second.'],
            ],
        ]), $this->res(), ['id' => $id]);

        $texts = array_column(EmailCampaign::blocksOf(EmailCampaign::find($id)), 'text');
        $this->assertSame(['First.', 'Second.', 'Third.'], $texts);
    }

    // ══ the send gate ════════════════════════════════════════════════════════

    /** The one that matters: an unapproved campaign cannot be sent, from the route. */
    public function test_send_refuses_an_unapproved_campaign(): void
    {
        $id = $this->newCampaign();
        $this->assertSame('draft', EmailCampaign::find($id)->status);

        $this->ctl()->send($this->req('POST', '/admin/campaigns/' . $id . '/send'), $this->res(), ['id' => $id]);

        $this->assertStringContainsString('not approved yet', (string) ($_SESSION['flash_error'] ?? ''));
        $this->assertSame(0, EmailCampaign::sentCount('august-final-hours'), 'something was sent');
    }

    public function test_approving_then_editing_closes_the_gate_again(): void
    {
        $id = $this->newCampaign();

        $this->ctl()->approve($this->req('POST', '/admin/campaigns/' . $id . '/approve'), $this->res(), ['id' => $id]);
        $this->assertSame('approved', EmailCampaign::find($id)->status);

        $this->ctl()->save($this->req('POST', '/admin/campaigns/' . $id, [
            'subject' => 'Changed', 'blocks' => [['type' => 'paragraph', 'text' => 'Different words.']],
        ]), $this->res(), ['id' => $id]);

        $this->assertSame('draft', EmailCampaign::find($id)->status,
            'an approval is of specific words — editing them must clear it');
    }

    public function test_a_test_send_needs_a_real_address(): void
    {
        $id = $this->newCampaign();

        $this->ctl()->test($this->req('POST', '/admin/campaigns/' . $id . '/test', ['to' => 'not-an-email']),
            $this->res(), ['id' => $id]);

        $this->assertStringContainsString('does not look like an email', (string) ($_SESSION['flash_error'] ?? ''));
    }

    /**
     * A test must never mark its address as already-sent — that would quietly exclude the
     * operator from the real run.
     */
    public function test_a_test_send_writes_nothing_to_the_send_log(): void
    {
        $id = $this->newCampaign();

        $this->ctl()->test($this->req('POST', '/admin/campaigns/' . $id . '/test', ['to' => 'operator@example.test']),
            $this->res(), ['id' => $id]);

        $this->assertSame(0, EmailCampaign::sentCount('august-final-hours'));
        $this->assertSame(0, EmailCampaign::sentCount('campaign-test'));
    }

    public function test_a_missing_campaign_is_a_redirect_not_a_crash(): void
    {
        $this->asRole('superadmin');

        $res = $this->ctl()->show($this->req('GET', '/admin/campaigns/9999'), $this->res(), ['id' => 9999]);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/admin/campaigns', $res->getHeaderLine('Location'));
    }
}
