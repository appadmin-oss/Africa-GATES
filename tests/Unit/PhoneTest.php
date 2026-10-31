<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Phone;
use PHPUnit\Framework\TestCase;

/**
 * E.164 normalisation — the format Twilio SMS, Twilio WhatsApp and the Meta
 * WhatsApp Business Cloud API all require. Guard the boundary rules hard:
 * a phone that normalises is deliverable; anything else must return null so
 * validation can speak up instead of a provider rejecting silently later.
 */
final class PhoneTest extends TestCase
{
    public function test_nigerian_national_format_with_country(): void
    {
        $this->assertSame('+2348031234567', Phone::normalize('0803 123 4567', 'NG'));
        $this->assertSame('+2348031234567', Phone::normalize('0803-123-4567', 'ng'));
    }

    public function test_already_e164_passes_through_with_any_country(): void
    {
        $this->assertSame('+2348031234567', Phone::normalize('+234 803 123 4567', 'GH'));
        $this->assertSame('+2348031234567', Phone::normalize('+2348031234567', null));
    }

    public function test_double_zero_international_prefix(): void
    {
        $this->assertSame('+233201234567', Phone::normalize('00233 20 123 4567', null));
    }

    public function test_bare_country_prefixed_digits_with_matching_country(): void
    {
        // "2348031234567" typed without the + but starting with NG's dial code.
        $this->assertSame('+2348031234567', Phone::normalize('2348031234567', 'NG'));
    }

    public function test_bare_national_digits_without_leading_zero(): void
    {
        // Common entry: subscriber number without the trunk 0 — country supplies the code.
        $this->assertSame('+254712345678', Phone::normalize('712345678', 'KE'));
    }

    public function test_rejects_garbage_and_wrong_lengths(): void
    {
        $this->assertNull(Phone::normalize('not a phone', 'NG'));
        $this->assertNull(Phone::normalize('12345', 'NG'));              // too short
        $this->assertNull(Phone::normalize('+123456789012345678', null)); // > 15 digits
        $this->assertNull(Phone::normalize('', 'NG'));
        $this->assertNull(Phone::normalize('0803123', 'NG'));            // short national
    }

    public function test_national_zero_without_country_is_rejected(): void
    {
        // A leading-0 national number is ambiguous without a country — refuse
        // rather than guess a dial code.
        $this->assertNull(Phone::normalize('08031234567', null));
        $this->assertNull(Phone::normalize('08031234567', 'XX'));
    }

    public function test_mask_keeps_prefix_and_tail_only(): void
    {
        $masked = Phone::mask('+2348031234567');
        $this->assertStringStartsWith('+234', $masked);
        $this->assertStringEndsWith('567', $masked);
        $this->assertStringNotContainsString('80312', $masked);
        $this->assertLessThanOrEqual(24, strlen($masked)); // fits gates_messages.to_masked
    }

    public function test_is_valid_matches_normalize(): void
    {
        $this->assertTrue(Phone::isValid('+2348031234567'));
        $this->assertFalse(Phone::isValid('0803'));
    }
}
