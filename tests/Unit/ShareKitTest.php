<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\ShareKit;
use Tests\TestCase;

/**
 * Every nominee's share kit is the same kit (D32): the platform promotes nominees by equipping
 * each of them identically, never by placing one in front of the public. Two kits may differ by
 * the nominee's own name, category, award and link — and by nothing else.
 */
final class ShareKitTest extends TestCase
{
    private const A = ['name' => 'Adaeze Okafor', 'category' => 'Teacher of the Year', 'programme' => 'Teachers Prize', 'url' => 'https://afg.example/vote/tp/4'];
    private const B = ['name' => 'Musa Ibrahim', 'category' => 'STEM Educator', 'programme' => 'Teachers Prize', 'url' => 'https://afg.example/vote/tp/8'];

    private static function strip(array $kit, array $f): string
    {
        $s = json_encode([$kit['text'], $kit['links']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        foreach (['name', 'category', 'programme', 'url'] as $k) {
            $s = str_replace([$f[$k], rawurlencode($f[$k]), rawurlencode(str_replace(' ', '+', $f[$k]))], '{' . $k . '}', (string) $s);
        }
        // A message's own encoding of the facts, inside one encoded text.
        return (string) preg_replace('/%[0-9A-F]{2}/', '', $s);
    }

    public function test_two_kits_differ_only_by_their_own_facts(): void
    {
        $a = ShareKit::for(self::A);
        $b = ShareKit::for(self::B);
        $this->assertSame(array_column($a['links'], 'key'), array_column($b['links'], 'key'), 'the same channels for everyone');
        $this->assertSame(
            str_replace([self::A['name'], self::A['category'], self::A['url']], ['{n}', '{c}', '{u}'], $a['text']),
            str_replace([self::B['name'], self::B['category'], self::B['url']], ['{n}', '{c}', '{u}'], $b['text']),
            'the same message, with their own facts in it');
        $this->assertNotNull($a['qr'], 'a short link gets a QR code');
    }

    public function test_every_channel_is_a_link_a_phone_opens_and_carries_the_link(): void
    {
        $k = ShareKit::for(self::A, 'https://afg.example/vote/tp/4?via=d2s52avd');
        foreach ($k['links'] as $l) {
            $this->assertMatchesRegularExpression('~^(https://|sms:)~', $l['href'], $l['key']);
            $this->assertStringContainsString(rawurlencode('via=d2s52avd'), str_replace('%3D', rawurlencode('='), $l['href']) . rawurlencode($l['href']), $l['key'] . ' carries the code');
        }
        $this->assertNull(ShareKit::qr(str_repeat('x', 80)), 'too long to encode is no QR, never a broken one');
    }
}
