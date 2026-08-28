<?php
declare(strict_types=1);

namespace Tests\Unit;

/**
 * The same twelve inbox properties, applied to the invitation's own skeleton.
 *
 * `templates/emails/invitation.twig` is a DIFFERENT DESIGN from `campaign.twig` — the
 * first cut of the invitation rendered through that skeleton's block list and looked like
 * what it was, a newsletter chassis carrying a personal letter. What it deliberately keeps
 * is the scaffolding: the fluid-hybrid wrapper, the MSO conditionals, the VML button, the
 * styled alt text, the presentation roles, the hidden preheader, no data: URIs.
 *
 * Inheriting the assertions rather than copying them is the point. A design is worth
 * nothing if it does not arrive, and Outlook fails silently.
 *
 * ── THE ONE OVERRIDE, AND WHY ────────────────────────────────────────────────
 *
 * `test_the_deadline_is_readable_with_images_blocked` asserts the literal string
 * "Voting closes {{ closes_human }}" and looks for an `<img>` whose src is
 * `countdown_url`. Both belong to the voting broadcast: an invitation has no countdown and
 * no deadline, and adding one to satisfy a test would be the test designing the email.
 *
 * The PROPERTY behind it is what matters and it applies with full force — Outlook desktop
 * and most corporate mail block remote images by default, so the fact the message exists
 * to convey must survive with images off, and the one image it does carry must be sized
 * and its alt text styled. That is asserted below against the invitation's own hero and
 * its own critical fact.
 */
final class InviteInboxCompatTest extends EmailInboxCompatTest
{
    protected static function tpl(): string
    {
        return __DIR__ . '/../../templates/emails/invitation.twig';
    }

    private static function body(): string
    {
        return (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents(static::tpl()));
    }

    public function test_the_deadline_is_readable_with_images_blocked(): void
    {
        $m = self::body();

        // With every image stripped, the evening itself must still be there: when, where,
        // and the way in. A blocked hero must cost the reader nothing but the picture.
        $withoutImages = (string) preg_replace('/<img[^>]*>/', '', $m);

        foreach (['{{ when }}', '{{ where', '{{ event_title }}', '{{ reference }}'] as $fact) {
            $this->assertStringContainsString($fact, $withoutImages,
                'with images off, ' . $fact . ' is gone — and it is the message');
        }

        // The one image the invitation carries. Height on the tag, or a blocked remote
        // image collapses the panel it is the hero of; styled alt, or it renders as 10px
        // serif and reads as a broken attachment rather than a sentence somebody wrote.
        $img = self::matchAll('/<img[^>]*cover_url[^>]*>/s', $m)[0] ?? '';
        $this->assertNotSame('', $img, 'cover image not found');
        $this->assertStringContainsString('alt=', $img);
        $this->assertStringContainsString('font-family', $img, 'unstyled alt text renders as 10px serif');
        $this->assertStringContainsString('height=', $img,
            'a blocked image with no height collapses the panel it is the hero of');
        $this->assertStringContainsString('width=', $img,
            'no width means no reserved space, which is a layout shift in a client that reflows');
    }

    // ══ the design's own load-bearing decisions ══════════════════════════════

    /**
     * Georgia, not a web font and not Arial.
     *
     * A web font cannot be relied on in email — Outlook ignores @font-face entirely and
     * Gmail strips the block that would declare it — so the display voice has to be a face
     * that is already on the machine. Georgia is on every client that matters and has real
     * character; defaulting to Arial or Helvetica for display is what makes mail look like
     * a form letter.
     */
    public function test_the_display_voice_is_a_real_face_and_not_a_default(): void
    {
        $m = self::body();

        $this->assertStringContainsString("Georgia,'Times New Roman',serif", $m);
        $this->assertStringNotContainsString('@font-face', $m,
            'a web font in email is a font that does not arrive');
    }

    /** Monospace metadata is the house voice for a label and a reference. */
    public function test_the_micro_labels_and_the_reference_are_monospace(): void
    {
        $m = self::body();

        $this->assertStringContainsString("Consolas,'Courier New',monospace", $m);
        // The reference is read aloud at a door and typed by a steward. It must be set in
        // the face that makes it transcribable.
        $this->assertMatchesRegularExpression(
            '~Consolas[^>]*>\{\{ reference \}\}~s',
            (string) preg_replace('/\s+/', ' ', $m),
            'the reference is not set in monospace'
        );
    }

    /**
     * Two actions, and no more. An invitation with six links is a newsletter — the
     * schedule, the venue map and the programme are all one tap away on the event page and
     * are deliberately not here.
     */
    public function test_there_are_exactly_two_actions(): void
    {
        $m = self::body();

        $hrefs = self::matchAll('/href="\{\{ ([a-z_]+) \}\}"/', $m);
        sort($hrefs);
        $unique = array_values(array_unique($hrefs));

        $this->assertSame(['events_url', 'id_url', 'unsubscribe_url'], $unique,
            'the invitation grew a link — hierarchy is subtraction');
    }

    /** @return list<string> */
    private static function matchAll(string $re, string $s): array
    {
        preg_match_all($re, $s, $m);

        return array_map('strval', $m[1] ?? $m[0] ?? []);
    }
}
