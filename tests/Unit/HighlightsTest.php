<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\Highlights as H;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\Support\ChromeRender;
use Tests\TestCase;

/**
 * Highlights (Services\Highlights): free promotion for a member's business, product, service or
 * event.
 *
 *   REVIEWED FIRST   nothing is public until a person approves it
 *   FIXED LIFE       it drops off LIVE_DAYS after approval, with nobody remembering to
 *   A FEW EACH       MAX_OPEN pending-or-live per member
 *   NOT A NOMINEE    never a link to a ballot or a nominee page (D15)
 *   SAFE LINKS       https or a site path, checked in AND out
 */
final class HighlightsTest extends TestCase
{
    private function good(array $over = []): array
    {
        return $over + ['kind' => 'business', 'title' => 'Mama Put, Yaba', 'body' => 'Home-cooked jollof and egusi, delivered across Yaba.',
                        'url' => 'https://mamaput.example.ng/menu', 'location' => 'Lagos'];
    }

    public function test_nothing_is_public_until_a_person_approves_it_and_it_then_expires(): void
    {
        $r = H::submit(41, $this->good());
        $this->assertTrue($r['ok']);
        $this->assertSame([], H::live(), 'pending is not public');

        $this->assertFalse(H::decide($r['id'], 1, false, '')['ok'], 'a refusal needs a reason');
        $this->assertTrue(H::decide($r['id'], 1, true)['ok']);
        $live = H::live();
        $this->assertCount(1, $live);
        $this->assertSame('https://mamaput.example.ng/menu', $live[0]['url']);
        $this->assertTrue($live[0]['external']);
        $this->assertFalse(H::decide($r['id'], 1, true)['ok'], 'decided once');

        DB::table('gates_highlights')->where('id', $r['id'])->update(['live_until' => date('Y-m-d H:i:s', time() - 60)]);
        $this->assertSame([], H::live(), 'past its life, gone without anybody taking it down');
    }

    public function test_a_member_has_a_few_open_at_once(): void
    {
        for ($i = 0; $i < H::MAX_OPEN; $i++) $this->assertTrue(H::submit(42, $this->good(['title' => 'Stall ' . $i . ' at Balogun']))['ok']);
        $over = H::submit(42, $this->good());
        $this->assertFalse($over['ok']);
        $this->assertArrayHasKey('form', $over['errors']);
        $mine = H::mine(42);
        $this->assertTrue(H::withdraw((int) $mine[0]->id, 42));
        $this->assertFalse(H::withdraw((int) $mine[1]->id, 99), 'only the owner withdraws');
        $this->assertTrue(H::submit(42, $this->good())['ok'], 'a withdrawn one frees a place');
    }

    public function test_a_highlight_never_points_at_a_nominee(): void
    {
        foreach (['/vote/teachers-prize/4-adaeze', 'https://afg.afrovanguard.org.ng/vote/x/1', '/claim/12', '/v/abc'] as $u) {
            $this->assertArrayHasKey('url', H::validate($this->good(['url' => $u]))['errors'], $u);
        }
        // And a row written before the rule, or by hand, is not linked on the way out.
        $id = (int) DB::table('gates_highlights')->insertGetId(['user_id' => 43, 'kind' => 'service', 'title' => 'Old row', 'body' => str_repeat('x', 30),
            'url' => '/vote/a/1', 'status' => 'live', 'live_until' => date('Y-m-d H:i:s', time() + 3600), 'reviewed_at' => date('Y-m-d H:i:s')]);
        $row = array_values(array_filter(H::live(), static fn ($r) => $r['id'] === $id))[0];
        $this->assertNull($row['url']);
    }

    public function test_only_safe_links_and_the_stated_lengths(): void
    {
        foreach (['http://x.example', 'javascript:alert(1)', '//evil.example', 'https://user:pw@x.example', 'https://localhost'] as $u) {
            $this->assertNull(H::safeUrl($u), $u);
        }
        $this->assertSame('/events/ride', H::safeUrl('/events/ride'));
        $e = H::validate(['kind' => 'nominee', 'title' => 'ab', 'body' => 'short', 'location' => str_repeat('l', 61)])['errors'];
        $this->assertSame(['kind', 'title', 'body', 'location'], array_keys($e));
        $this->assertStringContainsString((string) H::TITLE_MAX, $e['title'], 'the limit is stated, from the constant');
    }

    public function test_the_public_page_shows_approved_listings_and_never_calls_them_promoted(): void
    {
        $r = H::submit(44, $this->good(['title' => 'Kemi’s Adire, Abeokuta']));
        $html = ChromeRender::html('/highlights');
        $this->assertStringNotContainsString('Adire', $html, 'pending is not public');
        H::decide($r['id'], 1, true);
        $html = ChromeRender::html('/highlights');
        $this->assertStringContainsString('Kemi’s Adire, Abeokuta', $html);
        $this->assertMatchesRegularExpression('~href="https://mamaput\.example\.ng/menu" target="_blank" rel="noopener noreferrer nofollow ugc"~', $html);
        $this->assertStringNotContainsString('Promoted', preg_replace('~\{#.*?#\}~s', '', $html), 'a free listing is never labelled as paid');
        $this->assertStringContainsString('href="/highlights"', ChromeRender::html('/'), 'reachable from the footer');
    }

    public function test_a_member_sees_their_listings_with_the_reason_a_refused_one_was_refused(): void
    {
        $uid = (int) DB::table('gates_users')->insertGetId(['name' => 'Kemi Adebayo', 'email' => 'k-' . bin2hex(random_bytes(3)) . '@example.test',
            'phone' => '08031234567', 'points' => 0, 'status' => 'active', 'email_verified' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $r = H::submit($uid, $this->good());
        H::decide($r['id'], 1, false, 'Add a photo of the food, please.');
        $html = ChromeRender::html('/account/highlights', ['user_id' => $uid, 'member_id' => $uid, 'account_id' => $uid]);
        $this->assertStringContainsString('Not approved', $html);
        $this->assertStringContainsString('Add a photo of the food, please.', $html);
        $this->assertStringContainsString('action="/account/highlights"', $html, 'and can add another');
        $this->assertStringContainsString((string) H::BODY_MAX, $html, 'the rule is stated, from the constant');
    }
}
