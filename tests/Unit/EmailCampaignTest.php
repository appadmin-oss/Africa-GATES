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
 * them" — them being the twelve inbox properties in {@see EmailInboxCompatCase}. So the
 * first thing held here is that the editor CANNOT emit markup: every text field is
 * strip_tags'd on the way in and the template supplies the HTML.
 *
 * The second is that a campaign built from the starter blocks SAYS EVERYTHING the fixed
 * final-hours message says (`templates/emails/final.html.twig`). That was the acceptance
 * test for the whole change: a migration to editable copy that quietly altered the campaign
 * would be a regression dressed as a feature. The two no longer share a chassis — the fixed
 * message moved to the house shell with the handoff (19 Oct 2026), the editor keeps
 * campaign.twig — so it is held on the WORDS of the message's body, sentence by sentence.
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
     * The starter campaign must say every sentence of the fixed message's body. Compared as
     * visible text with entities decoded, because the markup differs while the words must not.
     */
    public function test_the_starter_campaign_says_everything_the_fixed_message_says(): void
    {
        $vars = EmailCampaign::sampleVars();

        $visible = static function (string $html): string {
            $html = (string) preg_replace('/<!--.*?-->/s', '', $html);
            $html = (string) preg_replace('~<head\b.*?</head>~si', '', $html);
            $html = (string) preg_replace('~<style\b.*?</style>~si', '', $html);
            $html = (string) preg_replace('~<div style="display:none.*?</div>~si', '', $html);
            return trim((string) preg_replace('/\s+/u', ' ',
                html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        };

        $fixed   = \AfricaGates\Services\Mail\Emails::compose('final', $vars);
        $starter = $visible(EmailCampaign::render('s', 'p', EmailCampaign::starter(), $vars));
        $body    = $visible('<table>' . $fixed['html'] . '</table>');
        // The h1 is "Finish strong, <name>" in one and a headline+accent pair in the other;
        // every other line is a sentence, and each must be there.
        foreach (['Two things make the difference.', 'Send your link to five people', 'Personally. A message beats a post.',
                  'Fill the room', 'the people who believed in you should be there to celebrate with you.',
                  'Vote and share your link', 'Get event tickets'] as $sentence) {
            $this->assertStringContainsString($sentence, $body, "the fixed message lost '$sentence'");
            $this->assertStringContainsString($sentence, $starter, "the editable starter does not say '$sentence'");
        }
    }

    /** The structural properties EmailInboxCompatCase holds must survive the new skeleton. */
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
            'max-width:600px'            => 'the fluid-hybrid width',
        ] as $needle => $why) {
            $this->assertStringContainsString($needle, $html, "lost {$why}");
        }
        $this->assertStringNotContainsString('nonce=', $html, 'a mail template must carry no CSP nonce');
    }

    // ══ the general-purpose layout (17 Nov 2026) ═════════════════════════════

    /** Visible text of a render, for asserting on words rather than markup. */
    private static function visible(string $html): string
    {
        $html = (string) preg_replace('/<!--.*?-->/s', '', $html);
        $html = (string) preg_replace('~<head\b.*?</head>~si', '', $html);
        $html = (string) preg_replace('~<div style="display:none.*?</div>~si', '', $html);
        return trim((string) preg_replace('/\s+/u', ' ',
            html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /**
     * With no `chrome`, a campaign keeps the shape every campaign before the redesign had:
     * "Final hours" in the masthead and the nominee's countdown, deadline repeated as text.
     */
    public function test_without_chrome_an_existing_campaign_keeps_its_countdown(): void
    {
        $vars = EmailCampaign::sampleVars();
        $html = EmailCampaign::render('s', 'p', EmailCampaign::starter(), $vars);

        $this->assertStringContainsString((string) $vars['countdown_url'], $html);
        $this->assertStringContainsString('Voting closes ' . $vars['closes_human'], self::visible($html));
        $this->assertStringContainsString('Final hours', self::visible($html));
    }

    /** A general announcement draws no countdown, no deadline and no award label. */
    public function test_chrome_can_turn_the_countdown_off_and_set_the_masthead_label(): void
    {
        $vars = EmailCampaign::sampleVars() + ['chrome' => ['tag' => 'Announcement', 'countdown' => false]];
        $html = EmailCampaign::render('s', '', [['type' => 'paragraph', 'text' => 'News.']], $vars);
        $text = self::visible($html);

        $this->assertStringNotContainsString('countdown.gif', $html);
        $this->assertStringNotContainsString('Voting closes', $html, 'no deadline in the body or the preheader fallback');
        $this->assertStringNotContainsString('Final hours', $text);
        $this->assertStringContainsString('Announcement', $text);
        $this->assertSame([], EmailInboxGuard::problems($html));
    }

    /** The footer keeps every way out and every compliance line. */
    public function test_the_footer_keeps_unsubscribe_postal_and_why(): void
    {
        $vars = EmailCampaign::sampleVars();
        $html = EmailCampaign::render('s', 'p', [['type' => 'paragraph', 'text' => 'News.']], $vars);

        $this->assertStringContainsString('href="' . htmlspecialchars((string) $vars['unsubscribe_url'], ENT_QUOTES) . '"', $html);
        $this->assertStringContainsString('>Unsubscribe</a>', $html);
        $this->assertStringContainsString($vars['site_url'] . '/privacy', $html);
        $this->assertStringContainsString('because you’re a nominee', $html);
        $this->assertStringContainsString('Afrovanguard initiative', $html);
    }

    /** A paragraph line starting "- " is a bullet; **words** are bold; both stay escaped. */
    public function test_a_paragraph_draws_bullets_and_bold_without_letting_markup_in(): void
    {
        $html = EmailCampaign::render('s', 'p', [['type' => 'paragraph',
            'text' => "Intro with **bold words** here.\n- **Date:** 12 December\n- Venue & hall"]],
            EmailCampaign::sampleVars());

        $this->assertMatchesRegularExpression('~<strong[^>]*>bold words</strong>~', $html);
        $this->assertMatchesRegularExpression('~<strong[^>]*>Date:</strong> 12 December~', $html);
        $this->assertSame(2, substr_count($html, '&bull;'), 'one bullet per "- " line');
        $this->assertStringContainsString('Venue &amp; hall', $html, 'bullet text is escaped once');
        $this->assertStringNotContainsString('**', self::visible($html));
        $this->assertStringNotContainsString('- Venue', self::visible($html), 'the marker is drawn, not printed');
    }

    /** An unmatched ** is shown as typed rather than bolding the rest of the line. */
    public function test_an_unmatched_bold_marker_is_left_as_typed(): void
    {
        $html = EmailCampaign::render('s', 'p', [['type' => 'paragraph', 'text' => 'Price 5 ** 2 today']],
            EmailCampaign::sampleVars());

        $this->assertStringNotContainsString('<strong', $html);
        $this->assertStringContainsString('Price 5 ** 2 today', $html);
    }

    /** A button with no text draws nothing, rather than an empty green pill. */
    public function test_a_button_with_no_label_is_omitted_but_its_secondary_link_stays(): void
    {
        $html = EmailCampaign::render('s', 'p', [
            ['type' => 'paragraph', 'text' => 'Hi.'],
            ['type' => 'button', 'label' => '', 'link' => 'vote_url', 'secondary_label' => 'See events', 'secondary_link' => 'events_url'],
        ], EmailCampaign::sampleVars());

        $this->assertStringNotContainsString('v:roundrect', $html);
        $this->assertStringContainsString('See events', $html);
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

    /**
     * The general placeholders (17 Nov 2026): the nominee's full name, the award (their
     * cycle's programme) and the edition — resolved per recipient by NomineeBroadcast::vars(),
     * listed on the compose screen from PLACEHOLDERS, and filled in the preview sample.
     */
    public function test_full_name_award_and_edition_resolve_per_recipient_with_fallbacks(): void
    {
        foreach (['full_name', 'award_name', 'edition_name'] as $k) {
            $this->assertArrayHasKey($k, EmailCampaign::PLACEHOLDERS, "the compose screen lists {{$k}}");
            $this->assertNotSame('', (string) (EmailCampaign::sampleVars()[$k] ?? ''), "the preview fills {{$k}}");
        }

        $r = [
            'nominee' => (object) ['id' => 7, 'name' => 'Ngozi Adaeze Okafor', 'category_title' => 'Health',
                                   'programme_slug' => 'health-awards'],
            'cycle'   => (object) ['id' => 3, 'voting_close' => '2026-12-01 23:59:00', 'year' => 2026,
                                   'edition_label' => '11th Edition', 'programme_title' => 'Africa GATES Health Awards'],
            'email'   => 'ngozi@example.test',
        ];
        $vars = (new NomineeBroadcast())->vars($r, 'https://x.test');
        $text = 'Dear {full_name}, {award_name|Africa GATES} ({edition_name}).';
        $this->assertSame('Dear Ngozi Adaeze Okafor, Africa GATES Health Awards (11th Edition).',
            EmailCampaign::fill($text, $vars));

        // No programme row, no edition label: the award falls back as written, the edition to the year.
        $r['cycle'] = (object) ['id' => 3, 'voting_close' => '2026-12-01 23:59:00', 'year' => 2026];
        $vars = (new NomineeBroadcast())->vars($r, 'https://x.test');
        $this->assertSame('Dear Ngozi Adaeze Okafor, Africa GATES (2026).', EmailCampaign::fill($text, $vars));

        // Existing drafts are untouched: the old placeholders resolve exactly as before.
        $this->assertSame('Ngozi', $vars['first_name']);
        $this->assertSame('Health', EmailCampaign::fill('{category_name}', $vars));
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
        // A heading block is added to the starter: the starter no longer carries one, and the
        // rule being held is how a heading reaches a text client, not the starter's wording.
        $txt  = EmailCampaign::plainOf(array_merge(EmailCampaign::starter(),
            [['type' => 'heading', 'text' => "Two things we're asking of you"]]), $vars);

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

    /** Not every campaign is a voting push (owner, 17 Nov 2026). */
    public function test_a_general_campaign_has_no_countdown_and_takes_a_kicker_and_a_typed_link(): void
    {
        $vars   = EmailCampaign::sampleVars();
        $in     = EmailCampaign::general();
        $in[1]['eyebrow'] = 'Summit · {award_name|Awards}';
        $in[3]['url'] = 'https://forms.example.org/rsvp?x=1';
        $blocks = EmailCampaign::clean($in);

        $this->assertSame(['tag' => 'Update', 'countdown' => false], EmailCampaign::chrome($blocks));
        $html = EmailCampaign::render('s', 'p', $blocks, $vars);
        $this->assertStringNotContainsString((string) $vars['countdown_url'], $html, 'a general message drew the voting countdown');
        $this->assertStringNotContainsString('Final hours', $html);
        $this->assertStringContainsString('forms.example.org/rsvp', $html, 'the typed address was not used');
        $this->assertStringContainsString('Summit · ', $html, 'the kicker was not drawn');
        $this->assertStringNotContainsString('Voting closes', EmailCampaign::plainOf($blocks, $vars));
        $this->assertSame([], \AfricaGates\Services\EmailInboxGuard::problems($html));

        // Switching the countdown on brings it back, and the legacy starter keeps it.
        $blocks[0]['countdown'] = 'show';
        $this->assertStringContainsString((string) $vars['countdown_url'], EmailCampaign::render('s', 'p', $blocks, $vars));
        $this->assertStringContainsString((string) $vars['countdown_url'], EmailCampaign::render('s', 'p', EmailCampaign::starter(), $vars));
    }

    public function test_a_typed_link_must_be_https_or_a_path_on_this_site(): void
    {
        foreach (['javascript:alert(1)', '//evil.example/x', 'http://plain.example', 'data:text/html,x'] as $bad) {
            $b = EmailCampaign::clean([['type' => 'button', 'label' => 'Go', 'link' => 'site_url', 'url' => $bad]]);
            $this->assertSame('', $b[0]['url'], "$bad was kept");
        }
        $b = EmailCampaign::clean([['type' => 'button', 'label' => 'Go', 'link' => 'site_url', 'url' => '/events']]);
        $html = EmailCampaign::render('s', 'p', $b, EmailCampaign::sampleVars());
        $this->assertStringContainsString(rtrim((string) EmailCampaign::sampleVars()['site_url'], '/') . '/events', $html, 'a path was not made absolute');
    }
}
