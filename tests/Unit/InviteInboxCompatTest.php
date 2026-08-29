<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EventInvites;
use AfricaGates\Services\InviteAudience;
use AfricaGates\Services\InviteMailer;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The same twelve inbox properties, applied to the invitation AS IT ARRIVES.
 *
 * ── WHY THIS READS A RENDERED MESSAGE AND NOT A TEMPLATE ────────────────────
 *
 * It used to read `templates/emails/invitation.twig`, because that file used to be a
 * whole document — its own doctype, head, masthead, hero and footer. It was also being
 * handed to `sendBranded()`, which drops what it is given inside `brandWrap()`'s card. So
 * the message that reached an inbox was two documents nested: house masthead, then a
 * second `<html>` with a second masthead, then two footers. A test pointed at the
 * template asserted twelve properties about a fragment of a message nobody received.
 *
 * The invitation is a fragment now and the shell is the shell. Which moves eight of the
 * twelve properties into `OtpService::brandWrap()` — the doctype, the metas, the fluid
 * wrapper, the mso conditional, the preheader, the presentation roles, the dark-mode
 * declaration, the absolute image. Rendering is the only way to hold them where they
 * live, and it holds them for EVERY branded message on the platform at the same time,
 * which reading a template never did.
 *
 * ── THE OVERRIDES, AND WHY ──────────────────────────────────────────────────
 *
 * `test_the_deadline_is_readable_with_images_blocked` asserts the literal string
 * "Voting closes {{ closes_human }}" and looks for a countdown GIF. Both belong to the
 * voting broadcast: an invitation has no countdown and no deadline, and adding one to
 * satisfy a test would be the test designing the email.
 *
 * The PROPERTY applies with full force — Outlook desktop and most corporate mail block
 * remote images by default — so it is asserted below against the invitation's own
 * critical facts and its own two images.
 */
final class InviteInboxCompatTest extends EmailInboxCompatTest
{
    private static string $rendered = '';

    /** The event's own values, so the with-images-off assertions name real strings. */
    private const WHEN  = '12 December 2026';
    private const WHERE = 'Eko Convention Centre';
    private const TITLE = 'Africa GATES Gala 2026';

    protected function setUp(): void
    {
        parent::setUp();

        $pid = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'principals', 'title' => 'Incredible Principal Awards', 'is_active' => 1,
        ]);
        $eventId = (int) DB::table('gates_site_events')->insertGetId([
            'slug' => 'gala-2026', 'title' => self::TITLE,
            'event_date' => '2026-12-12 18:00:00', 'status' => 'published',
            'venue' => self::WHERE, 'location' => 'Lagos',
            // A cover, deliberately: the hero band is one of the twelve properties and an
            // event without artwork renders no hero at all, which would leave the
            // blocked-images assertions asserting nothing.
            'cover_image' => 'uploads/events/gala-2026.jpg',
        ]);
        EventInvites::setProgrammes($eventId, [$pid]);
        DB::table('gates_event_tiers')->insert([
            'event_id' => $eventId, 'slug' => 'supporter', 'name' => 'Supporter',
            'price_naira' => 5000, 'is_active' => 1, 'sort_order' => 1,
        ]);

        $event  = DB::table('gates_site_events')->where('id', $eventId)->first();
        $invite = EventInvites::mint($eventId, InviteAudience::NOMINEE,
            ['name' => 'Ada Obi', 'email' => 'ada@example.com', 'nominee_id' => 0, 'judge_id' => 0]);

        self::$rendered = InviteMailer::preview($invite, $event);
    }

    protected static function tpl(): string
    {
        return __DIR__ . '/../../templates/emails/invitation.twig';
    }

    /** The whole message: shell plus body, exactly as it goes on the wire. */
    protected static function markup(): string
    {
        return self::$rendered;
    }

    /** The shell's fluid card. 600px here, against the campaign skeleton's own 560. */
    protected static function fluidWrapperRe(): string
    {
        return '/class="ag-ground"[^>]*width="100%"/';
    }

    protected static function cardMaxWidth(): string
    {
        return 'max-width:600px';
    }

    public function test_the_deadline_is_readable_with_images_blocked(): void
    {
        $withoutImages = (string) preg_replace('/<img[^>]*>/s', '', self::markup());

        // With every image stripped, the evening itself must still be there: what, when,
        // where, and the way in. A blocked hero must cost the reader nothing but the
        // picture.
        foreach ([self::TITLE, self::WHEN, self::WHERE, 'AGI-', '/honour/'] as $fact) {
            $this->assertStringContainsString($fact, $withoutImages,
                'with images off, "' . $fact . '" is gone — and it is the message');
        }

        // The mark. Its alt is the organisation's name and it has to be STYLED: an
        // unstyled alt renders as 10px serif and reads as a broken attachment rather
        // than as a wordmark.
        $mark = self::img('logo-africa-gates');
        $this->assertStringContainsString('alt="Africa GATES"', $mark);
        $this->assertStringContainsString('font-family', $mark,
            'unstyled alt text renders as 10px serif');
        $this->assertStringContainsString('height=', $mark,
            'a blocked image with no height collapses the band it is the only thing in');

        // The hero. Its alt is empty ON PURPOSE — the event's name, date and venue are
        // all set as text below it, so a repeated alt would be read twice by a screen
        // reader and would say nothing new. What it does owe is a reserved box, or a
        // client that reflows jumps when the picture finally loads.
        $hero = self::img('gala-2026.jpg');
        $this->assertStringContainsString('alt=""', $hero, 'a decorative hero must not repeat the copy');
        $this->assertMatchesRegularExpression('/width="\d+"/', $hero,
            'no width means no reserved space, which is a layout shift in a client that reflows');
        $this->assertMatchesRegularExpression('/height="\d+"/', $hero,
            'a blocked image with no height collapses the band it is the only thing in');
    }

    // ══ the design's own load-bearing decisions ══════════════════════════════

    /**
     * Georgia, not a web font and not Arial.
     *
     * A web font cannot be relied on in email — Outlook ignores @font-face entirely and
     * Gmail strips the block that would declare it — so the display voice has to be a
     * face already on the machine. Playfair is named first for the handful of clients
     * that have it; Georgia is the one that actually renders, is on every client that
     * matters, and has real character. Defaulting to Arial for display is what makes
     * mail look like a form letter.
     */
    public function test_the_display_voice_is_a_real_face_and_not_a_default(): void
    {
        $m = self::markup();

        $this->assertStringContainsString('Georgia,serif', $m);
        $this->assertStringNotContainsString('@font-face', $m,
            'a web font in email is a font that does not arrive');
    }

    /** Monospace metadata is the house voice for a label and a reference. */
    public function test_the_micro_labels_and_the_reference_are_monospace(): void
    {
        $flat = (string) preg_replace('/\s+/', ' ', self::markup());

        // The reference is read aloud at a door and typed by a steward. It must be set in
        // the face that makes it transcribable.
        $this->assertMatchesRegularExpression(
            '~Consolas,\'Courier New\',monospace[^>]*>AGI-[A-Z0-9]+<~',
            $flat,
            'the reference is not set in monospace'
        );
    }

    /**
     * Two actions in the letter, and no more. An invitation with six links is a
     * newsletter — the schedule, the venue map and the programme are all one tap away on
     * the event page and are deliberately not here.
     *
     * Counted on the BODY rather than the whole message, because the shell's footer
     * carries Help, Privacy and Unsubscribe on every message the platform sends and
     * those are chrome, not asks. The hierarchy decision this holds is the one made in
     * `invitation.twig`.
     */
    public function test_there_are_exactly_two_actions(): void
    {
        preg_match_all('/href="([^"]+)"/', self::letter(), $m);
        $unique = array_values(array_unique($m[1]));
        sort($unique);

        $this->assertCount(2, $unique,
            'the invitation grew a link — hierarchy is subtraction: ' . implode(', ', $unique));
        $this->assertStringContainsString('/honour/', $unique[1] ?? $unique[0],
            'the pass is the primary action and it is missing');
        $this->assertStringContainsString('/events/', $unique[0],
            'the way for their guests to buy is missing');
    }

    /**
     * The letter itself — the shell's body cell, cut out of the rendered message.
     *
     * Sliced from the rendered output rather than read from the template, because the
     * template is Twig: counting `href="{{ id_url }}"` there counts the VARIABLES an
     * author wrote, and two of them resolving to the same URL would read as two actions
     * when the reader sees one.
     */
    private static function letter(): string
    {
        if (!preg_match('/class="ag-pad ag-body"[^>]*>(.*?)<!-- Footer -->/s', self::markup(), $m)) {
            self::fail('the shell no longer has a body cell this can be cut from');
        }

        return (string) $m[1];
    }

    /** The one image tag whose src names $needle. */
    private static function img(string $needle): string
    {
        preg_match_all('/<img\b[^>]*>/s', self::markup(), $m);
        foreach ($m[0] as $tag) {
            if (str_contains($tag, $needle)) return $tag;
        }

        return self::fail('no <img> matching "' . $needle . '" in the message');
    }
}
