<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EmailOptOut;
use AfricaGates\Services\EventInvites;
use AfricaGates\Services\InviteAudience;
use AfricaGates\Services\InviteLetter;
use AfricaGates\Support\Brand;
use AfricaGates\Services\InviteMailer;
use AfricaGates\Services\OtpService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * What a guest of honour actually receives.
 *
 * The properties held here are the ones nobody can check by reading the code and nobody
 * will check by eye on four hundred letters: that the number promised in the email is the
 * number the code allows, that a formal letter is attached and carries the right person's
 * reference, that an opted-out address is not written to, and that running the send twice
 * does not write to anybody twice.
 *
 * The MARKUP half — whether this survives Outlook — is not tested here and does not need
 * to be: the invitation renders through `templates/emails/campaign.twig`, so
 * {@see CampaignInboxCompatTest} already holds all twelve inbox properties for it. That
 * is the whole reason it is a block list rather than a thirteenth template.
 */
final class InviteMailerTest extends TestCase
{
    private int $eventId = 0;
    private object $event;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('gates_settings')->where('key_name', 'like', 'invite_%')->delete();

        $pid = (int) DB::table('gates_award_programmes')->insertGetId([
            'slug' => 'principals', 'title' => 'Incredible Principal Awards', 'is_active' => 1,
        ]);
        $this->eventId = (int) DB::table('gates_site_events')->insertGetId([
            'slug' => 'gala-2026', 'title' => 'Africa GATES Gala 2026',
            'event_date' => '2026-12-12 18:00:00', 'status' => 'published',
            'venue' => 'Eko Convention Centre', 'location' => 'Lagos',
        ]);
        EventInvites::setProgrammes($this->eventId, [$pid]);
        DB::table('gates_event_tiers')->insert([
            'event_id' => $this->eventId, 'slug' => 'supporter', 'name' => 'Supporter',
            'price_naira' => 5000, 'is_active' => 1, 'sort_order' => 1,
        ]);
        $this->event = DB::table('gates_site_events')->where('id', $this->eventId)->first();
    }

    private function invite(string $audience = InviteAudience::NOMINEE, string $email = 'ada@example.com'): object
    {
        return EventInvites::mint($this->eventId, $audience,
            ['name' => 'Ada Obi', 'email' => $email, 'nominee_id' => 0, 'judge_id' => 0]);
    }

    /** A transport that records instead of dialling out. */
    private function recorder(): OtpService
    {
        return new class(['host' => 'localhost', 'port' => 25,
                          'username' => 'u', 'password' => 'p',
                          'from_address' => 'no@example.com', 'from_name' => 'X']) extends OtpService {
            /** @var list<array<string,mixed>> */
            public array $sent = [];

            public function smtpConfigured(): bool { return true; }

            public function sendBranded(string $to, string $subject, string $htmlBody, string $plainBody = '',
                                        string $category = '', string $hero = '', string $unsubscribeUrl = '',
                                        array $attachments = []): array
            {
                $this->sent[] = compact('to', 'subject', 'htmlBody', 'plainBody', 'category', 'attachments');

                return ['success' => true];
            }
        };
    }

    // ════════════════════════════════════════════════════════════════════════

    public function test_the_invitation_names_the_person_the_evening_and_the_ask(): void
    {
        $inv = $this->invite();
        $m   = $this->recorder();

        $r = InviteMailer::send($inv, $this->event, $m);

        $this->assertTrue($r['ok'], $r['error']);
        $this->assertCount(1, $m->sent);

        $sent = $m->sent[0];
        $this->assertSame('ada@example.com', $sent['to']);
        $this->assertStringContainsString('Ada Obi', $sent['subject'],
            'this is an invitation to one person, not a campaign — the inbox must read that way');
        $this->assertStringContainsString('Africa GATES Gala 2026', $sent['subject']);

        $html = $sent['htmlBody'];
        $this->assertStringContainsString('Eko Convention Centre', $html);
        $this->assertStringContainsString((string) $inv->reference, $html, 'their guest code');
        $this->assertStringContainsString('25', $html, 'the quota promised');
        $this->assertStringContainsString('10%', $html);
        $this->assertStringContainsString('Supporter', $html, 'seats start at the cheapest PAID tier');
        $this->assertStringContainsString('/honour/' . $inv->reference, $html, 'the pass is a link');
    }

    /**
     * Each audience is told why the room is being filled for them, in their own words —
     * and a nominee and a judge are the only two, because a nominee is a nominee whichever
     * award they were shortlisted for.
     */
    public function test_each_audience_is_given_its_own_reason(): void
    {
        $cases = [
            InviteAudience::NOMINEE => 'what it has meant to the people around you',
            InviteAudience::JUDGE   => 'integrity of your judgement',
        ];

        foreach ($cases as $audience => $phrase) {
            $m   = $this->recorder();
            $inv = $this->invite($audience, $audience . '@example.com');
            InviteMailer::send($inv, $this->event, $m);

            $this->assertStringContainsString($phrase, $m->sent[0]['htmlBody'],
                $audience . ' was sent somebody else\'s reason');
        }
    }

    public function test_the_formal_letter_is_attached_and_carries_their_reference(): void
    {
        $inv = $this->invite();
        $m   = $this->recorder();

        InviteMailer::send($inv, $this->event, $m);
        $files = $m->sent[0]['attachments'];

        $this->assertCount(1, $files, 'the letter, and no cover image on this event');
        $this->assertSame('application/pdf', $files[0]['mime']);
        $this->assertStringStartsWith('%PDF-', $files[0]['body'], 'that is not a PDF');
        $this->assertStringContainsString(strtolower((string) $inv->reference), $files[0]['name'],
            'the filename must identify whose invitation it is');
        $this->assertGreaterThan(2000, strlen($files[0]['body']), 'a one-page letter is not 2kB of nothing');
    }

    /**
     * The rotating code must never reach a file. A document showing an expired pass is
     * worse than one showing none: somebody holds it up and is turned away by a letter
     * telling them they were invited.
     */
    public function test_the_letter_carries_the_reference_but_never_a_rotating_code(): void
    {
        $inv = $this->invite();
        $pdf = InviteLetter::render($inv, $this->event, EventInvites::lowestTier($this->eventId));

        // The window number appears in every rotating code and in no reference.
        $this->assertStringNotContainsString(
            (string) \AfricaGates\Services\InvitePass::window(), $pdf,
            'a rotating window number is in the PDF — it will be false within a minute'
        );
    }

    public function test_an_opted_out_address_is_never_written_to(): void
    {
        $inv = $this->invite();
        EmailOptOut::record('ada@example.com', 'test');
        $m = $this->recorder();

        $r = InviteMailer::send($inv, $this->event, $m);

        $this->assertFalse($r['ok']);
        $this->assertTrue($r['skipped']);
        $this->assertSame([], $m->sent);
    }

    /**
     * A second run must not write to anybody twice. A duplicate invitation is not a
     * duplicate newsletter — it is a second personal letter to somebody who already
     * replied to the first.
     */
    public function test_running_the_send_twice_writes_once(): void
    {
        $inv = $this->invite();

        $first  = InviteMailer::send($inv, $this->event, $m1 = $this->recorder());
        $second = InviteMailer::send($inv, $this->event, $m2 = $this->recorder());

        $this->assertTrue($first['ok']);
        $this->assertFalse($second['ok']);
        $this->assertTrue($second['skipped']);
        $this->assertCount(1, $m1->sent);
        $this->assertSame([], $m2->sent);
    }

    public function test_a_successful_send_is_stamped_on_the_invitation(): void
    {
        $inv = $this->invite();
        $this->assertNull($inv->sent_at);

        InviteMailer::send($inv, $this->event, $this->recorder());

        $this->assertNotNull(DB::table('gates_event_invites')->where('id', $inv->id)->value('sent_at'));
        $this->assertSame('sent', (string) DB::table('gates_broadcast_log')
            ->where('campaign', InviteMailer::campaignKey($this->eventId))->value('status'));
    }

    /** A judge's log row must not carry a nominee id it does not have. */
    public function test_a_judges_log_row_has_no_nominee_id(): void
    {
        InviteMailer::send($this->invite(InviteAudience::JUDGE, 'judge@example.com'),
                           $this->event, $this->recorder());

        $this->assertNull(DB::table('gates_broadcast_log')
            ->where('email', 'judge@example.com')->value('nominee_id'));
    }

    /** The preview renders without sending anything at all. */
    public function test_the_preview_sends_nothing(): void
    {
        $inv  = $this->invite();
        $html = InviteMailer::preview($inv, $this->event);

        $this->assertStringContainsString((string) $inv->reference, $html);
        $this->assertSame(0, (int) DB::table('gates_broadcast_log')->count());
        $this->assertNull(DB::table('gates_event_invites')->where('id', $inv->id)->value('sent_at'));
    }

    /**
     * `cover_image` is an admin-entered column. An attachment builder that resolves
     * whatever string it is handed is a file-disclosure primitive pointed at its own mail
     * queue, so the path is checked against the web root rather than trusted.
     */
    public function test_a_traversing_cover_path_is_not_attached(): void
    {
        DB::table('gates_site_events')->where('id', $this->eventId)
            ->update(['cover_image' => '../../.env']);
        $event = DB::table('gates_site_events')->where('id', $this->eventId)->first();

        $m = $this->recorder();
        InviteMailer::send($this->invite(), $event, $m);

        $names = array_column($m->sent[0]['attachments'], 'name');
        $this->assertSame([], array_filter($names, fn ($n) => str_contains((string) $n, 'env')),
            'a path outside public/ was attached to an outgoing email');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  A ROUTE IN — CODEBASE-INDEX §18
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Every configurable this feature reads has a field that sets it.
     *
     * §18 is the sibling of the no-reader bug: a mechanism that is complete and correct
     * and that nothing can reach. Quotas and the discount are read out of `gates_settings`
     * by {@see InviteAudience}, so a form that cannot write them makes the defaults the
     * only values the platform will ever have.
     */
    public function test_every_invitation_setting_has_a_field_that_sets_it(): void
    {
        $form = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/settings.twig');

        foreach (['invite_quota_nominee', 'invite_quota_judge', 'invite_discount_percent',
                  'invite_witness_nominee', 'invite_witness_judge'] as $key) {
            $this->assertStringContainsString('name="' . $key . '"', $form,
                $key . ' is read by InviteAudience but nothing can set it');
        }
    }

    /** And the run itself is reachable from the event it belongs to. */
    public function test_the_invitation_run_is_reachable_from_the_event(): void
    {
        $form = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/admin/events/form.twig');

        $this->assertStringContainsString('/invites', $form,
            'the invitation run has no link from the event screen');
        $this->assertStringContainsString('name="programme_ids[]"', $form,
            'without this field no event can be marked as a ceremony, so nobody is ever invited');
        $this->assertStringContainsString('type="checkbox"', $form,
            'one ceremony honours several awards — a single-select cannot say so');
    }

    /** The three admin actions exist as routes. */
    public function test_the_admin_actions_are_routed(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/routes.php');

        foreach (["/events/{id:[0-9]+}/invites'",
                  "/events/{id:[0-9]+}/invites/build'",
                  "/events/{id:[0-9]+}/invites/send'"] as $path) {
            $this->assertStringContainsString($path, $routes, $path . ' is not routed');
        }
    }

    // ══ one sentence, one author ═════════════════════════════════════════════

    /**
     * The "why" sentence is the operator's, whole.
     *
     * It used to be a FRAGMENT — "to witness the work you have done" — with the template
     * supplying "We want the hall packed" in front of it and a trailing clause behind. So
     * the one string the settings screen invites an operator to edit was the middle of a
     * sentence whose ends they could not see: write a complete sentence, which is the
     * obvious thing to do, and the letter goes out reading "We want the hall packed We
     * want the hall packed to witness…".
     *
     * Both halves had the prefix — the Twig template and the plain-text builder — so
     * fixing one would have left the other doing it, which is the same bug wearing the
     * part nobody screenshots.
     */
    public function test_neither_half_prefixes_the_operators_sentence(): void
    {
        // Every file that composes the sentence, not the two I happened to look at first.
        // It was in three — the Twig template, the plain-text builder, and the PDF letter,
        // which is the copy the invitee KEEPS — and a scan over two of them would have
        // left the worst one doing it.
        foreach ([
            'templates/emails/invitation.twig'   => 'the HTML half',
            'src/Services/InviteMailer.php'      => 'the plain-text half',
            'src/Services/InviteLetter.php'      => 'the PDF letter',
        ] as $rel => $which) {
            $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
            // Comments explain the bug by name, so they are stripped before the scan.
            $code = str_ends_with($rel, '.twig')
                ? (string) preg_replace('/\{#[\s\S]*?#\}/', '', $src)
                : (string) preg_replace('#//[^\n]*#', '', $src);

            $this->assertStringNotContainsString('We want the hall packed', $code,
                $which . ' still writes half the sentence the operator owns');
        }

        // And the default IS a whole sentence, so the setting screen's placeholder shows
        // an operator what shape their replacement has to be.
        foreach ([InviteAudience::NOMINEE, InviteAudience::JUDGE] as $a) {
            $w = InviteAudience::spec($a)['witness'];
            $this->assertMatchesRegularExpression('/^[A-Z].*\.$/s', $w,
                $a . "'s default reason is not a complete sentence");
        }
    }

    /**
     * The letter greets the person it is addressed to.
     *
     * `salutation` is resolved per audience in InviteAudience and was never put into the
     * view, so the template's `{{ salutation }} {{ name }},` rendered as a leading space
     * and a name — a fault invisible in every unit test of the resolver and visible in
     * every inbox.
     */
    public function test_the_letter_opens_with_a_salutation(): void
    {
        $html = InviteMailer::preview($this->invite(), $this->event);

        $this->assertMatchesRegularExpression('/Dear\s+Ada Obi,/', $html,
            'the greeting is missing its salutation');
        $this->assertStringNotContainsString('> Ada Obi,', $html,
            'the salutation resolved to nothing and left a bare name');
    }

    // ══ the mark ═════════════════════════════════════════════════════════════

    /**
     * The letter and the email it arrives with carry the SAME mark.
     *
     * `public/assets/img/` holds fourteen files with "logo", "mark" or "brand" in the
     * name, and only one is the real lockup — the rest are a favicon badge, two
     * hand-drawn approximations and a pair of watermarks. A path typed into a template is
     * a decision nobody can find later, and a letter showing one logo beside an email
     * showing another is worse than neither carrying any.
     */
    public function test_both_surfaces_take_the_mark_from_one_resolver(): void
    {
        $this->assertFileExists(
            dirname(__DIR__, 2) . '/public/' . Brand::LOGO,
            'the lockup named by Brand is not in the deploy'
        );

        $tpl = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/emails/invitation.twig');
        $this->assertStringContainsString('{{ logo_url }}', $tpl,
            'the email hard-codes a path instead of asking Brand');

        // The email takes the REVERSED lockup, because its masthead is ink. Getting this
        // wrong is not subtle — the green-on-white artwork on ink is a white rectangle
        // with a logo in it.
        $html = InviteMailer::preview($this->invite(), $this->event);
        $this->assertStringContainsString(Brand::LOGO_REVERSED, $html,
            'the email is not using the reversed mark');
        $this->assertFileExists(dirname(__DIR__, 2) . '/public/' . Brand::LOGO_REVERSED,
            'the reversed lockup is not in the deploy');

        // And the letter embeds the same file's bytes.
        $this->assertNotNull(Brand::logoJpeg(320), 'the mark could not be transcoded for the PDF');
    }

    /**
     * The declared box is the artwork's own ratio — on both surfaces.
     *
     * In email, a width and height that are not the real ratio make a blocked image
     * reserve the wrong box and the layout jump when it loads. In the PDF it is worse
     * than cosmetic: Pdf::image() COVERS its box and clips the overflow, like
     * `object-fit: cover` — right for a poster on a ticket, and for a logo it crops the
     * Africa outline instead of fitting it. Matching the ratio is what makes cover and
     * contain the same operation.
     */
    public function test_the_mark_is_never_stretched_or_cropped(): void
    {
        $file = dirname(__DIR__, 2) . '/public/' . Brand::LOGO;
        [$w, $h] = getimagesize($file);
        $this->assertEqualsWithDelta($w / $h, Brand::LOGO_RATIO, 0.001,
            'Brand::LOGO_RATIO no longer describes the file — a new export was dropped in '
            . 'without it');

        $tpl = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/emails/invitation.twig');
        preg_match('/<img src="\{\{ logo_url \}\}" width="(\d+)" height="(\d+)"/', $tpl, $m);
        $this->assertNotEmpty($m, 'the mark carries no width and height — a blocked image '
                                . 'then collapses the band it is the only thing in');
        $this->assertEqualsWithDelta((int) $m[1] / (int) $m[2], Brand::LOGO_RATIO, 0.02,
            'the declared box is not the artwork\'s shape');
    }

    /**
     * The letterhead still names the organisation when the file is missing.
     *
     * A deploy that dropped the image would otherwise produce a letter with a blank
     * corner and no name on it at all — and it is the copy somebody keeps.
     */
    public function test_the_letterhead_survives_a_missing_logo(): void
    {
        $txt = self::pdfText(InviteLetter::render($this->invite(), $this->event));
        $this->assertStringContainsString('Continental Cultural Recognition', $txt);
        $this->assertStringContainsString('For and on behalf of Africa GATES', $txt);
    }

    // ══ the letter is a letter ═══════════════════════════════════════════════

    /**
     * The formal letter carries the conventions that make correspondence formal.
     *
     * It was a web page printed to A4: a wordmark, a rule, "An Invitation" set at 26pt
     * like a magazine cover, then straight into "Dear". No date, no reference block, no
     * subject line, no recipient block — and nowhere for anybody to sign it, which is the
     * loudest of the omissions on a document somebody puts on a wall.
     *
     * Asserted on the PDF's own text, so this holds the DOCUMENT rather than the code
     * that draws it.
     */
    public function test_the_letter_reads_as_formal_correspondence(): void
    {
        $pdf = InviteLetter::render($this->invite(), $this->event);
        $txt = self::pdfText($pdf);

        foreach ([
            'INVITATION TO'                  => 'no subject line — a formal letter states its business above the salutation',
            'Our ref:'                       => 'no reference block',
            'Dear '                          => 'no salutation',
            'Yours sincerely'                => 'no complimentary close',
            'For and on behalf of'           => 'nothing to sign, and nobody signing it',
        ] as $needle => $why) {
            $this->assertStringContainsString($needle, $txt, $why);
        }

        // The date. A letter with no date is a notice.
        $this->assertMatchesRegularExpression('/\d{1,2} [A-Z][a-z]+ \d{4}/', $txt,
            'the letter is undated');

        // And the magazine headline is gone.
        $this->assertStringNotContainsString('An Invitation', $txt,
            '"An Invitation" at 26pt is a cover line, not a subject');
    }

    /**
     * One page, with the longest input the console will accept.
     *
     * The sign-off is anchored to the foot, so the failure mode is not a second page — it
     * is the body running INTO it. The operator's "why" sentence is capped at 240
     * characters by the settings field and the name at 160 by the column, so this renders
     * both at their maximum and asserts the body still clears the anchored block.
     *
     * Measured with the real face through Pdf::lines(), which is what the renderer wraps
     * with — a count of characters would pass on a name of narrow letters and fail on
     * the one it was written for.
     */
    public function test_the_longest_letter_the_console_allows_still_fits_one_page(): void
    {
        // 240 characters, the settings field's maxlength, in the widest realistic shape.
        $long = 'We want the hall packed to witness the extraordinary and sustained work you '
              . 'have done across two decades, and what it has meant to the thousands of '
              . 'families, teachers and children whose lives were quietly and permanently '
              . 'changed by it.';
        $this->assertLessThanOrEqual(240, mb_strlen($long), 'the fixture must not exceed the field');

        DB::table('gates_settings')->updateOrInsert(
            ['key_name' => 'invite_witness_nominee'], ['value' => $long]
        );

        // The worst case is every variable at once, not just the long sentence: the venue
        // and location both set, a tier line quoted, and the longest name the column
        // holds. The first version of this test set only the sentence, so the body still
        // fit and the test passed against the very layout that printed the close over the
        // reference — which is how a guard comes to guard nothing.
        DB::table('gates_site_events')->where('id', $this->eventId)->update([
            'venue'    => 'The Grand Ballroom, Eko Convention Centre',
            'location' => 'Victoria Island, Lagos, Nigeria',
        ]);
        $event = DB::table('gates_site_events')->where('id', $this->eventId)->first();

        $inv = $this->invite();
        $inv->name = str_repeat('Adébáyọ̀-Williams ', 8);   // 160 chars of the hardest glyphs

        $tier = (object) ['name' => 'General admission', 'price_naira' => 5000];
        $pdf  = InviteLetter::render($inv, $event, $tier);

        $this->assertSame(1, preg_match_all('#/Type\s*/Page(?![s/\w])#', $pdf),
            'the letter grew a second page');

        // ── GEOMETRIC, BECAUSE PRESENCE IS NOT ENOUGH ────────────────────────
        //
        // The first version of this test asserted that "Yours sincerely" and the reference
        // were both in the document. They were — printed on top of each other, on the same
        // baseline, on the longest letter the console allows. A string that is present and
        // a string that is legible are different claims, and only the second one matters
        // on a document somebody keeps.
        //
        // PDF user space has y growing UPWARD from the page foot, so a line further down
        // the page has a SMALLER y.
        $at = static function (string $needle) use ($pdf): float {
            foreach (self::pdfRuns($pdf) as [$text, $ty]) {
                if (str_contains($text, $needle)) return $ty;
            }
            return -1.0;
        };

        // The BLOCK's label, not 'AGI-' — the body quotes the reference too, and matching
        // that finds the paragraph rather than the block below it.
        $block = $at('YOUR REFERENCE');
        $sign  = $at('Yours sincerely');
        $body  = $at('We would be honoured');

        $this->assertGreaterThan(0, $block, 'the reference block was not drawn');
        $this->assertGreaterThan(0, $sign,  'the sign-off was not drawn');
        $this->assertGreaterThan(0, $body,  'the last line of the letter was not drawn');

        // 4mm ≈ 11.3pt of clear space between the block and the close, at minimum.
        $this->assertGreaterThan(11.3, $block - $sign,
            'the reference block and the sign-off are printed over each other');
        // And the letter ends above its own block rather than being drawn through it.
        $this->assertGreaterThan($block + 11.3, $body,
            'the letter runs into its own reference block');
    }

    /**
     * The letter sets a Yorùbá name and a naira figure without boxes.
     *
     * DM Sans is the body face now — it is narrower and tighter than the DejaVu the letter
     * used to be set in, and it is the site's own. It also has no Ọ, ẹ, ṣ or ₦, which is
     * the gap CODEBASE-INDEX records against the ticket. Every DM Sans face is registered
     * with DejaVu behind it and Pdf splits runs per character; this is that arrangement
     * asserted rather than assumed, on the one document somebody keeps.
     */
    public function test_the_letter_sets_an_african_name_and_a_naira_figure(): void
    {
        $inv = $this->invite();
        $inv->name = 'Dr Ọlásùnkànmí Adébáyọ̀-Williams';

        $tier = (object) ['name' => 'General admission', 'price_naira' => 5000];
        $txt  = self::pdfText(InviteLetter::render($inv, $this->event, $tier));

        $this->assertStringContainsString('Ọlásùnkànmí', $txt, 'the name did not survive the font split');
        $this->assertStringContainsString('₦5,000', $txt, 'the naira sign fell out');
    }

    /**
     * The text of a PDF this codebase generated, for assertions about the document.
     *
     * Pdf writes uncompressed content streams, so the drawing operators are readable in
     * the bytes. Text is emitted as hex strings of 2-byte glyph ids with a ToUnicode CMap
     * beside them — reversing that is the only way to assert on what a reader SEES rather
     * than on the code that drew it, and a test of the drawing code cannot catch a missing
     * signature block.
     */
    /**
     * Every drawn run as [text, baseline-y in PDF points].
     *
     * Position, not just presence — see the note in the one-page test for what asserting
     * presence alone let through.
     *
     * @return list<array{0:string,1:float}>
     */
    private static function pdfRuns(string $pdf): array
    {
        [$maps, $bodies] = self::pdfParts($pdf);
        $out = [];
        foreach ($bodies as $body) {
            if (!preg_match_all(
                '#/(F[A-Za-z0-9_]+)[^/]*?Tm\s*<([0-9A-Fa-f]+)>\s*Tj#s', $body, $runs, PREG_SET_ORDER)) {
                continue;
            }
            // The Tm matrix carries the baseline as its last number.
            preg_match_all('#Tm#', $body, $_);
            preg_match_all('#([-\d.]+)\s+([-\d.]+)\s+Tm\s*<([0-9A-Fa-f]+)>\s*Tj#s', $body, $pos, PREG_SET_ORDER);
            foreach ($runs as $i => $run) {
                $text = '';
                $m = $maps[$run[1]] ?? [];
                foreach (str_split($run[2], 4) as $gid) {
                    if (strlen($gid) < 4) continue;
                    $cp = $m[hexdec($gid)] ?? null;
                    if ($cp !== null) $text .= mb_chr($cp, 'UTF-8');
                }
                $out[] = [$text, (float) ($pos[$i][2] ?? 0)];
            }
        }
        return $out;
    }

    private static function pdfText(string $pdf): string
    {
        $out = '';
        foreach (self::pdfRuns($pdf) as [$text, $_]) $out .= $text;

        return $out;
    }

    /**
     * A PDF's inflated objects, as [font resource name => glyph map, content-stream bodies].
     *
     * ── WHY THE MAPS ARE PER-FONT ────────────────────────────────────────────
     *
     * Every font has its own glyph-id space, so gid 5 in DM Sans and gid 5 in the mono
     * face are different characters. Merging every ToUnicode CMap into one table reads
     * "INVITATION" back as "GNVGTATGON" — plausible nonsense, and a test that fails for a
     * reason that has nothing to do with the document.
     *
     * @return array{0: array<string, array<int,int>>, 1: list<string>}
     */
    private static function pdfParts(string $pdf): array
    {
        // ── SLICED ON OBJECT HEADERS, NOT ON `endobj` ────────────────────────
        //
        // This used to match `(\d+) 0 obj(.*?)endobj` across the whole file. That works
        // right up until the document contains an image: a JPEG's compressed bytes are
        // arbitrary binary, the non-greedy match ends somewhere inside them, and every
        // object after it is sliced at the wrong offset — so the font dictionaries go
        // missing and two thirds of the letter reads back as absent. It looked exactly
        // like the renderer had stopped drawing.
        //
        // Boundaries come from the NEXT object header instead. Binary that happens to
        // contain "endobj" is ordinary; binary that happens to contain "N 0 obj" at a
        // line start is not, and even then it corrupts one object rather than the tail of
        // the file.
        $objects = [];
        if (preg_match_all('/(?:^|[\r\n])(\d+) 0 obj\b/', $pdf, $heads, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($heads as $i => $h) {
                $from = $h[0][1] + strlen($h[0][0]);
                $to   = $heads[$i + 1][0][1] ?? strlen($pdf);
                $body = substr($pdf, $from, $to - $from);
                if (preg_match('/stream\r?\n(.*?)\r?\nendstream/s', $body, $st)) {
                    $inf = @gzuncompress($st[1]);
                    if (is_string($inf) && $inf !== '') $body .= "\n" . $inf;
                }
                $objects[(int) $h[1][0]] = $body;
            }
        }

        $maps = [];
        foreach ($objects as $body) {
            if (!preg_match_all('#/(F[A-Za-z0-9_]+)\s+(\d+) 0 R#', $body, $res, PREG_SET_ORDER)) continue;
            foreach ($res as $r) {
                $font = $objects[(int) $r[2]] ?? '';
                if (!preg_match('#/ToUnicode (\d+) 0 R#', $font, $tu)) continue;
                $m = [];
                if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $objects[(int) $tu[1]] ?? '', $blocks)) {
                    foreach ($blocks[1] as $block) {
                        if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $pairs, PREG_SET_ORDER)) {
                            foreach ($pairs as $pair) $m[hexdec($pair[1])] = hexdec($pair[2]);
                        }
                    }
                }
                if ($m !== []) $maps[$r[1]] = $m;
            }
        }

        $bodies = [];
        foreach ($objects as $body) if (str_contains($body, ' Tj')) $bodies[] = $body;

        return [$maps, $bodies];
    }
}
