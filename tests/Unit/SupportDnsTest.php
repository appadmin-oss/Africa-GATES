<?php
declare(strict_types=1);
namespace Tests\Unit;

use AfricaGates\Services\SupportTools;
use PHPUnit\Framework\TestCase;

final class SupportDnsTest extends TestCase
{
    public function test_a_failed_lookup_is_unknown_instead_of_an_invalid_domain(): void
    {
        $r = (new SupportTools(dnsLookup: static fn()=>false))->emailDomain('person@gmail.com');
        $this->assertFalse($r['ok']);
        $this->assertNull($r['deliverable']);
        $this->assertSame('dns_unavailable', $r['reason']);
        $this->assertNull($r['suggest']);
        $this->assertStringNotContainsString('person', $r['say']);
    }

    public function test_a_resolver_exception_is_also_unknown(): void
    {
        $r = (new SupportTools(dnsLookup: static function(){throw new \RuntimeException('resolver unavailable');}))->emailDomain('person@gmail.com');
        $this->assertNull($r['deliverable']);
        $this->assertSame('dns_unavailable', $r['reason']);
    }

    /** @dataProvider routingAnswers */
    public function test_successful_dns_answers_distinguish_routing_from_absence(array $records, bool $expected): void
    {
        $r = (new SupportTools(dnsLookup: static fn()=>$records))->emailDomain('person@gmail.com');
        $this->assertTrue($r['ok']);
        $this->assertSame($expected, $r['deliverable']);
        $this->assertSame($expected ? 'accepts_mail' : 'no_mx', $r['reason']);
    }

    public static function routingAnswers(): array
    {
        return [
            'MX'=>[[['type'=>'MX','target'=>'mx.example.org']],true],
            'implicit IPv4'=>[[['type'=>'A','ip'=>'192.0.2.1']],true],
            'implicit IPv6'=>[[['type'=>'AAAA','ipv6'=>'2001:db8::1']],true],
            'no records'=>[[],false],
            'null MX'=>[[['type'=>'MX','target'=>'.'],['type'=>'A','ip'=>'192.0.2.1']],false],
        ];
    }
}
