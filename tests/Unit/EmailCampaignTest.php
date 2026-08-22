<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\EmailCampaign;
use AfricaGates\Services\EmailInboxGuard;
use AfricaGates\Services\NomineeBroadcast;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Campaign copy that an operator can edit, and the rules that stop them breaking an inbox.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE PROPERTY THAT MATTERS MOST
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `HANDOFF.md` §6 warns that "a WYSIWYG editor is the fastest way to break every one of
 * them" — them being the twelve inbox properties in {@see EmailInboxCompatTest}. So the
 * first thing held here is that the editor CANNOT emit markup: every text field is
 * strip_tags'd on the way in and the template supplies the HTML.
 *
 * The second is that a campaign built from the starter blocks renders the SAME VISIBLE COPY
 * as `templates/emails/final-hours.twig`, the file it replaces. That was the acceptance
 * test for the whole change: a migration to editable copy that quietly altered the campaign
 * would be a regression dressed as a feature.
 */
final class EmailCampaignTest extends TestCase
{
    // ══ the editor cannot emit markup ════════════════════════════════════════

    public function test_pasted_html_is_stripped_rather_than_rendered(): void
    {
        $blocks = EmailCampaign::clean([[
            'type' => 'paragraph',
            'text' => 'Hello <div style="color:red">everyone</div> <script>alert(1)</script>',
        ]]);

        $this->assertSame('Hello everyone alert(1)', $blocks[0]['text']);
        $this->assertStringNotContainsString('<', $blocks[0]['text']);
    }

    public function test_an_unknown_block_type_is_dropped(): void
    {
        $this->assertSame([], EmailCampaign::clean([['type' => 'raw_html', 'text' => '<b>hi</b>']]));
    }

    public function test_a_field_the_type_does_not_declare_is_dropped(): void
    {
        $b = EmailCampaign::clean([['type' => 'heading', 'text' => 'Label', 'onclick' => 'evil()']])[0];

        $this->assertSame(['type' => 'heading', 'text' => 'Label'], $b);
    }

    /**
     * Destinations are chosen from a list, never typed. Free-text URLs in a template a
     * non-developer edits is an open redirect with a mailing list attached.
     */
    public function test_a_typed_url_cannot_become_a_link(): void
    {
        $b = EmailCampaign::clean([[
            'type' => 'button', 'label' => 'Click', 'link' => 'https://evil.example/steal',
        ]])[0];

        $this->assertSame('vote_url', $b['link'], 'an off-list destination must fall back, not pass through');
    }

    public function test_an_empty_block_is_dropped_rather_than_rendered_as_space(): void
    {
        $this->assertSame([], EmailCampaign::clean([['type' => 'paragraph', 'text' => '   ']]));
        // A divider has no fields, so it is the one type that survives being empty.
        $this->assertCount(1, EmailCampaign::clean([['type' => 'divider']]));
    }

    // ══ the acceptance test ══════════════════════════════════════════════════

    /**
     * The starter campaign must read exactly like the file it replaces. Compared as visible
     * text with entities decoded, because the markup differs (a loop instead of fixed rows)
     * while the words must not.
     */
    public function test_the_starter_campaign_reads_exactly_like_the_template_it_replaces(): void
    {
        $vars = EmailCampaign::sampleVars();

        $twig = new \Twig\Environment(
            new \Twig\Loader\FilesystemLoader(dirname(__DIR__, 2) . '/templates'),
            ['autoescape' => 'html']
        );

        $visible = static function (string $html): string {
            $html = (string) preg_replace('/<!--.*?-->/s', '', $html);
            $html = (string) preg_replace('~<head\b.*?</head>~si', '', $html);
            $html = (string) preg_replace('~<style\b.*?</style>~si', '', $html);
            $html = (string) preg_replace('~<div style="display:none.*?</div>~si', '', $html);
            return trim((string) preg_replace('/\s+/u', ' ',
                html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        };

        $this->assertSame(
            $visible($twig->render('emails/final-hours.twig', $vars)),
            $visible(EmailCampaign::render('s', 'p', EmailCampaign::starter(), $vars)),
            'the editable campaign does not say the same thing as the template it replaces'
        );
    }

    /** The structural properties EmailInboxCompatTest holds must survive the new skeleton. */
    public function test_the_rendered_campaign_keeps_the_structure_outlook_needs(): void
    {
        $html = EmailCampaign::render('s', 'p', EmailCampaign::starter(), EmailCampaign::sampleVars());

        foreach ([
            'v:roundrect'                => 'the VML button Outlook needs',
            '<w:anchorlock/>'            => 'the anchorlock that stops it being dragged',
            'role="presentation"'        => 'presentation roles on layout tables',
            'mso-hide:all'               => 'the hidden preheader',
            'prefers-color-scheme: dark' => 'a declared dark mode',
            'x-apple-data-detectors'     => 'the iOS autolink suppression',
            'max-width:560px'            => 'the fluid-hybrid width',
        ] as $needle => $why) {
            $this->assertStringContainsString($needle, $html, "lost {$why}");
        }
        $this->assertStringNotContainsString('nonce=', $html, 'a mail template must carry no CSP nonce');
    }

    // ══ placeholders ═════════════════════════════════════════════════════════

    public function test_placeholders_resolve_per_recipient(): void
    {
        $out = EmailCampaign::fill('Hello {first_name}, closing {closes_human}.',
            ['first_name' => 'Ada', 'closes_human' => 'Friday']);

        $this->assertSame('Hello Ada, closing Friday.', $out);
    }

    /**
     * A nominee's first name and category are genuinely blank sometimes. Without a fallback
     * the sentence loses a word; with one it stays grammatical and nobody has to think.
     */
    public function test_a_fallback_covers_an_empty_value(): void
    {
        $this->assertSame('Hello Friend.', EmailCampaign::fill('Hello {first_name|Friend}.', ['first_name' => '']));
        $this->assertSame('Hello Ada.',    EmailCampaign::fill('Hello {first_name|Friend}.', ['first_name' => 'Ada']));
    }

    /** An unknown token stays visible rather than silently eating a word. */
    public function test_an_unknown_placeholder_is_left_alone(): void
    {
        $this->assertSame('{bank_details}', EmailCampaign::fill('{bank_details}', ['bank_details' => 'secret']));
    }

    public function test_a_name_with_an_ampersand_is_escaped_once(): void
    {
        $html = EmailCampaign::render('s', 'p',
            [['type' => 'paragraph', 'text' => 'Hello {first_name}.']],
            ['first_name' => 'Ade & Sons'] + EmailCampaign::sampleVars());

        $this->assertStringContainsString('Ade &amp; Sons', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html, 'double-escaped');
    }

    // ══ asks are numbered by asks ════════════════════════════════════════════

    public function test_a_paragraph_between_two_asks_does_not_skip_a_number(): void
    {
        $html = EmailCampaign::render('s', 'p', [
            ['type' => 'ask', 'title' => 'First', 'text' => 'a', 'link_label' => 'go', 'link' => 'vote_url'],
            ['type' => 'paragraph', 'text' => 'An aside.'],
            ['type' => 'ask', 'title' => 'Second', 'text' => 'b', 'link_label' => 'go', 'link' => 'events_url'],
        ], EmailCampaign::sampleVars());

        $this->assertStringContainsString('>01<', $html);
        $this->assertStringContainsString('>02<', $html);
        $this->assertStringNotContainsString('>03<', $html);
    }

    // ══ the guard ════════════════════════════════════════════════════════════

    public function test_the_guard_passes_the_starter_campaign(): void
    {
        $html = EmailCampaign::render('s', 'p', EmailCampaign::starter(), EmailCampaign::sampleVars());

        $this->assertSame([], EmailInboxGuard::problems($html));
        $this->assertLessThan(EmailInboxGuard::MAX_BYTES, strlen($html));
    }

    public function test_the_guard_catches_a_data_uri_image(): void
    {
        $p = EmailInboxGuard::problems('<img src="data:image/png;base64,AAAA">');

        $this->assertNotSame([], $p);
        $this->assertStringContainsString('data: URI', $p[0]);
    }

    public function test_the_guard_catches_a_relative_image(): void
    {
        $this->assertNotSame([], EmailInboxGuard::problems('<img src="/assets/logo.png">'));
    }

    public function test_the_guard_catches_an_unsubstituted_twig_tag(): void
    {
        $p = EmailInboxGuard::problems('<p>Hello {{ first_name }}</p>');

        $this->assertNotSame([], $p);
    }

    public function test_the_guard_catches_a_dead_link(): void
    {
        $this->assertNotSame([], EmailInboxGuard::problems('<a href="#">Vote</a>'));
    }

    /**
     * What Gmail clips off a campaign is the footer — where the unsubscribe link lives. So
     * overshooting the size turns a compliant email into a non-compliant one, and the cap
     * is deliberately below Gmail's own threshold.
     */
    public function test_the_guard_catches_a_campaign_that_would_be_clipped(): void
    {
        $p = EmailInboxGuard::problems(str_repeat('x', EmailInboxGuard::MAX_BYTES + 1));

        $this->assertNotSame([], $p);
        $this->assertStringContainsString('unsubscribe', $p[0]);
        $this->assertLessThan(EmailInboxGuard::GMAIL_CLIP_BYTES, EmailInboxGuard::MAX_BYTES);
    }

    // ══ the store ════════════════════════════════════════════════════════════

    private function make(string $name = 'Final hours August'): int
    {
        $r = EmailCampaign::create($name, 'Finish strong');
        $this->assertTrue($r['ok'], $r['message']);
        return $r['id'];
    }

    public function test_a_new_campaign_starts_from_the_shipped_copy(): void
    {
        $c = EmailCampaign::find($this->make());

        $this->assertSame('final-hours-august', $c->slug);
        $this->assertSame('draft', $c->status);
        $this->assertNotSame([], EmailCampaign::blocksOf($c));
    }

    public function test_two_campaigns_cannot_share_a_send_key(): void
    {
        $this->make('Duplicate name');
        $r = EmailCampaign::create('Duplicate name', 'x');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('already exists', $r['message']);
    }

    /**
     * The save is where the inbox rules are enforced, because the person who can fix a
     * too-long campaign is the person who just wrote it — and CI never sees the row.
     */
    public function test_a_save_that_would_not_render_is_refused_with_a_reason(): void
    {
        $id = $this->make();

        // One long paragraph cannot do it: clean() caps every field, so the only route past
        // the size limit is many blocks. Worth knowing — the field caps are most of the
        // defence and the guard is the backstop.
        $long = array_fill(0, 200, ['type' => 'paragraph', 'text' => str_repeat('long copy. ', 200)]);
        $r    = EmailCampaign::save($id, 'Subject', '', $long);

        $this->assertFalse($r['ok']);
        $this->assertNotSame([], $r['problems']);
        $this->assertStringContainsString('unsubscribe', $r['problems'][0]);
    }

    /** The per-field caps are the first line of defence, before the size guard. */
    public function test_a_field_is_capped_at_its_declared_length(): void
    {
        $b = EmailCampaign::clean([['type' => 'paragraph', 'text' => str_repeat('x', 5000)]])[0];

        $this->assertSame(
            EmailCampaign::BLOCKS['paragraph']['fields']['text']['max'],
            mb_strlen($b['text'])
        );
    }

    public function test_an_empty_campaign_is_refused(): void
    {
        $this->assertFalse(EmailCampaign::save($this->make(), 'Subject', '', [])['ok']);
    }

    public function test_every_save_is_kept_so_a_send_can_be_quoted_back(): void
    {
        $id = $this->make();
        EmailCampaign::save($id, 'First subject', '', [['type' => 'paragraph', 'text' => 'One.']]);
        EmailCampaign::save($id, 'Second subject', '', [['type' => 'paragraph', 'text' => 'Two.']]);

        $v = EmailCampaign::versions($id);
        $this->assertCount(2, $v);
        $this->assertSame('Second subject', $v[0]->subject, 'newest first');
    }

    /** An approval is of specific words, so editing the words clears it. */
    public function test_an_edit_un_approves(): void
    {
        $id = $this->make();
        EmailCampaign::save($id, 'Subject', '', [['type' => 'paragraph', 'text' => 'One.']]);
        EmailCampaign::approve($id, 7);
        $this->assertSame('approved', EmailCampaign::find($id)->status);

        EmailCampaign::save($id, 'Subject', '', [['type' => 'paragraph', 'text' => 'Changed.']]);
        $this->assertSame('draft', EmailCampaign::find($id)->status);
    }

    public function test_a_sent_campaign_is_frozen(): void
    {
        $id = $this->make();
        EmailCampaign::markSent($id, 800);

        $r = EmailCampaign::save($id, 'Rewritten', '', [['type' => 'paragraph', 'text' => 'New.']]);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('record of what went out', $r['message']);
    }

    // ══ one definition of who gets mail ══════════════════════════════════════

    /**
     * §6: "A third caller must go through it too, or the three will drift into mailing
     * somebody twice." So the campaign changes only the log key, the subject and the body —
     * never the recipient query.
     */
    public function test_a_campaign_changes_the_log_key_and_nothing_about_resolution(): void
    {
        $id = $this->make();
        $c  = EmailCampaign::find($id);

        $plain = new NomineeBroadcast();
        $withC = (new NomineeBroadcast())->forCampaign($c);

        $this->assertSame(NomineeBroadcast::CAMPAIGN, $plain->campaignKey());
        $this->assertSame('final-hours-august', $withC->campaignKey());
        $this->assertSame('Finish strong', $withC->subject());

        // Identical plans: the campaign must not change who is resolved.
        $this->assertSame($plain->plan()['counts'], $withC->plan()['counts']);
    }

    /**
     * The send log is the authority on who was reached — it is the unique key per address
     * that makes an interrupted run resume rather than repeat.
     */
    public function test_the_sent_count_comes_from_the_log(): void
    {
        foreach (['a@x.test', 'b@x.test'] as $i => $e) {
            DB::table('gates_broadcast_log')->insert([
                'campaign' => 'final-hours-august', 'email_hash' => hash('sha256', $e),
                'email' => $e, 'nominee_id' => $i + 1, 'status' => 'sent',
                'sent_at' => '2026-08-22 10:00:00',
            ]);
        }
        DB::table('gates_broadcast_log')->insert([
            'campaign' => 'final-hours-august', 'email_hash' => hash('sha256', 'c@x.test'),
            'email' => 'c@x.test', 'nominee_id' => 3, 'status' => 'failed',
            'sent_at' => '2026-08-22 10:00:00',
        ]);

        $this->assertSame(2, EmailCampaign::sentCount('final-hours-august'), 'a failure is not a send');
    }

    // ══ plain text ═══════════════════════════════════════════════════════════

    /**
     * Generated from the blocks rather than hand-written, so it cannot contradict the HTML
     * after an edit — a plain-text part that says something else is worse than a clumsy one.
     */
    public function test_the_plain_text_follows_the_blocks_and_spells_out_links(): void
    {
        $vars = EmailCampaign::sampleVars();
        $txt  = EmailCampaign::plainOf(EmailCampaign::starter(), $vars);

        $this->assertStringContainsString('Finish strong', $txt);
        $this->assertStringContainsString("TWO THINGS WE'RE ASKING OF YOU", $txt);
        $this->assertStringContainsString($vars['vote_url'], $txt, 'a text client needs the URL itself');
        $this->assertStringContainsString('Unsubscribe: ', $txt, 'a plain-text part still needs a working opt-out');
        $this->assertStringNotContainsString('<', $txt);
    }

    public function test_edited_copy_reaches_the_plain_text_too(): void
    {
        $txt = EmailCampaign::plainOf([['type' => 'paragraph', 'text' => 'A brand new sentence.']],
            EmailCampaign::sampleVars());

        $this->assertStringContainsString('A brand new sentence.', $txt);
        $this->assertStringNotContainsString('final stretch', $txt, 'the old fixed prose leaked through');
    }
}
