<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\GuideService;
use AfricaGates\Services\HelpCentre;
use AfricaGates\Services\SupportAgentService;
use AfricaGates\Services\SupportContext;
use AfricaGates\Services\SupportIntent;
use Tests\TestCase;

/**
 * Gee also supports.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT THESE TESTS DEFEND
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Gee is on every page; the support desk is on one. So the assistant a stuck
 * person actually meets is nearly always Gee, and Gee's answer to "I paid and my
 * votes never came" used to be a link to somewhere else. Routing that person to
 * the real support agent is the change. Three things have to hold for it to be an
 * improvement rather than a new failure mode:
 *
 *   1. THE ROUTING IS RIGHT IN BOTH DIRECTIONS. A payment problem must reach the
 *      support agent, and "how does the CPI work?" must NOT — the support agent is
 *      a narrower brain that reasons about transactions and can open tickets, so
 *      pointing ordinary curiosity at it is a downgrade that also costs a tool
 *      loop. The false-positive direction is tested as carefully as the other,
 *      because it is the one nobody would notice.
 *
 *   2. THE CONVERSATION SURVIVES. "AFG-4c1…", then "yes", then "how long will
 *      that take?" — none of those, read alone, looks like support. Bouncing them
 *      back to the guide loses the thread mid-repair, which is the specific
 *      failure people describe as "the bot is useless".
 *
 *   3. THE FLOOR IS A REAL ANSWER. With no AI provider, or past the support
 *      allowance, Gee must still answer from the Help Centre. Those conditions
 *      correlate with an incident — everybody arrives at once and the budget goes
 *      first — which is the exact moment a support widget must not become a
 *      "please email us" box.
 *
 * And one boundary: identity comes from the session, through one factory, for
 * every front door. See {@see SupportContext::fromSession()}.
 */
final class GeeSupportsTest extends TestCase
{
    // ── 1. the routing, both directions ──────────────────────────────────────

    /**
     * Real sentences from the ticket queue and the support email thread.
     *
     * Written as things people actually typed, not as tidy test fixtures: the
     * misspellings and the missing punctuation are the point, because those are
     * what a keyword list quietly fails on.
     */
    public static function stuckPhrasings(): array
    {
        return [
            'the money was taken'          => ['I already paid and it has been deducted from my account'],
            'votes never arrived'          => ['i bought 50 votes but they have not appeared'],
            'nothing showing'              => ['My votes are not showing on the nominee page'],
            'no receipt'                   => ['no receipt came after payment'],
            'refund'                       => ['I want a refund please'],
            'a bare reference'             => ['AFG-PVOTE-5fa76fb70246'],
            'the otp'                      => ['my OTP has not arrived yet'],
            'locked out'                   => ["I can't log in to my account"],
            'wants a human'                => ['I need to speak to someone about this'],
            'chasing a ticket'             => ['nobody replied to my ticket'],
            'broken, unspecific'           => ['the voting page is not working'],
            'charged twice'                => ['I was charged twice for the same votes'],
        ];
    }

    /**
     * @dataProvider stuckPhrasings
     */
    public function test_a_stuck_person_reaches_the_support_brain(string $message): void
    {
        $this->assertTrue(
            SupportIntent::looksLikeSupport($message),
            "This should have gone to support: \"{$message}\"");
    }

    /**
     * Browsing questions, including Gee's OWN suggested chips.
     *
     * The chips matter most. They are one tap away on every page, so if a chip
     * routes to the support agent then a large share of all support-tier traffic
     * is people who were never in trouble — burning the allowance that the people
     * who ARE in trouble depend on.
     */
    public static function browsingPhrasings(): array
    {
        return [
            'cpi chip'        => ['How does the CPI score work?'],
            'nominate chip'   => ['How do I nominate someone?'],
            'leaderboard chip'=> ['Take me to the leaderboard'],
            'verify chip'     => ['Why do I have to verify?'],
            'otp curiosity'   => ['What is an OTP and why do you need one?'],
            'weight chip'     => ['How much do votes count?'],
            'winners chip'    => ['How are winners chosen?'],
            'proceeds chip'   => ['What do proceeds fund?'],
            'rsvp chip'       => ['How do I RSVP?'],
            'thread chip'     => ['How do I start a thread?'],
            'price question'  => ['How much is a vote?'],
            'partnering'      => ['What partnership options are there?'],
            'donations'       => ['Where does my gift go?'],
            'greeting'        => ['hello'],
        ];
    }

    /**
     * @dataProvider browsingPhrasings
     */
    public function test_browsing_stays_with_the_guide(string $message): void
    {
        $this->assertFalse(
            SupportIntent::looksLikeSupport($message),
            "This should NOT have gone to support: \"{$message}\"");
    }

    /**
     * The word "vote" is not a support signal, and neither is "payment".
     *
     * Pinned explicitly because it is the tempting shortcut. They are the two
     * commonest words on the platform and they appear in curiosity far more often
     * than in trouble; routing on them would send most of the site's chatter to
     * the support agent.
     */
    public function test_the_platforms_commonest_words_do_not_route_on_their_own(): void
    {
        foreach (['vote', 'payment', 'votes', 'help', 'support', 'nomination', 'account'] as $word) {
            $this->assertFalse(SupportIntent::looksLikeSupport($word),
                "The bare word \"{$word}\" must not route to support.");
        }
    }

    // ── 2. the conversation survives ─────────────────────────────────────────

    public function test_a_follow_up_stays_at_the_desk(): void
    {
        $history = [
            ['role' => 'user',      'text' => 'I paid for votes and nothing came through'],
            ['role' => 'assistant', 'text' => 'Send me the reference and I will re-check it.'],
        ];

        // On their own, none of these is a support message.
        foreach (['AFG-PVOTE-5fa76fb70246', 'yes', 'how long will that take?', 'ok thank you'] as $reply) {
            $this->assertTrue(
                SupportIntent::looksLikeSupport($reply, $history),
                "Mid-repair follow-up dropped out of support: \"{$reply}\"");
        }
    }

    public function test_changing_the_subject_leaves_the_desk(): void
    {
        $history = [['role' => 'user', 'text' => 'my votes have not appeared']];

        $this->assertFalse(
            SupportIntent::looksLikeSupport('Actually, how does the CPI work?', $history),
            'A person who visibly changed the subject should get the guide back.');
    }

    /**
     * Only the USER's turns make a conversation support-shaped.
     *
     * The assistant's own words are full of trouble vocabulary — it says "refund"
     * and "not credited" while explaining a process — so letting its replies vote
     * would pin the rest of the conversation to the support agent after one
     * mention of the word.
     */
    public function test_the_assistants_own_words_do_not_make_it_a_support_case(): void
    {
        $history = [
            ['role' => 'user',      'text' => 'How does voting work?'],
            ['role' => 'assistant', 'text' => 'Paid votes are credited immediately; if one is ever missed we refund it.'],
        ];

        $this->assertFalse(
            SupportIntent::looksLikeSupport('And how often does the score update?', $history),
            "The assistant saying \"refund\" must not turn browsing into a support case.");
    }

    // ── 3. the floor is a real answer ────────────────────────────────────────

    public function test_with_no_model_at_all_gee_still_answers_from_the_help_centre(): void
    {
        $out = (new GuideService())->scripted('I paid but my votes have not appeared');

        $this->assertSame('help', $out['source'],
            'A support question with no model must be answered from the written corpus.');
        $this->assertStringContainsString('/help/', $out['reply'],
            'The answer has to carry the URL so the reader can keep it.');
        // The distinguishing property: this is an ANSWER, not a redirect. The old
        // scripted tier returned one sentence pointing at /support.
        $this->assertGreaterThan(220, mb_strlen($out['reply']),
            'The fallback must quote the article, not point at it.');
    }

    public function test_the_no_model_fallback_asks_for_the_one_thing_that_unblocks_it(): void
    {
        // A sentence with no topic in it: the search correctly matches nothing.
        $out = (new GuideService())->supportFallback('zzzz qqqq');

        $this->assertStringContainsString('AFG-', $out['reply'],
            'With nothing to quote, ask for the reference rather than apologising.');
    }

    /**
     * BOTH front doors quote the same written answer when no model can be reached.
     *
     * This is the drift that was actually shipped: Gee quoted the article while
     * the support DESK replied "I cannot reach my assistant service right now" —
     * so the person who had navigated all the way to the support page, the more
     * stuck of the two, got the worse answer. Two floors is one floor too many.
     */
    public function test_the_desk_and_gee_share_one_floor_when_no_model_is_reachable(): void
    {
        $question = 'I paid but my votes have not appeared';

        // No AiService → available() is false → the no-provider branch.
        $desk = (new SupportAgentService())->ask($question, [], SupportContext::fromSession());
        $gee  = (new GuideService())->supportFallback($question);

        $written = HelpCentre::writtenAnswer($question);
        $this->assertNotNull($written, 'The corpus is supposed to answer this; the test means nothing otherwise.');

        $this->assertStringContainsString($written, $desk['reply'],
            'The support desk must quote the written answer, not apologise.');
        $this->assertStringContainsString($written, $gee['reply']);
        $this->assertStringNotContainsString('cannot reach my assistant service', $desk['reply'],
            'There IS an answer to this question — saying there is not is the bug.');
    }

    /** With nothing to quote, an apology is the honest answer — and it stays. */
    public function test_the_desk_still_says_so_when_the_corpus_has_nothing(): void
    {
        $desk = (new SupportAgentService())->ask('zzzz qqqq wwww', [], SupportContext::fromSession());

        $this->assertNull(HelpCentre::writtenAnswer('zzzz qqqq wwww'));
        $this->assertStringContainsString('cannot reach my assistant service', $desk['reply']);
    }

    public function test_a_browsing_question_is_not_answered_from_the_help_centre_tier(): void
    {
        $out = (new GuideService())->scripted('How does the CPI score work?');

        $this->assertSame('scripted', $out['source']);
        $this->assertStringContainsString('Cultural Power Index', $out['reply']);
    }

    // ── the strip both front doors render ────────────────────────────────────

    public function test_the_cards_lead_with_what_the_answer_was_built_from(): void
    {
        $cards = HelpCentre::previews('my votes are missing', ['vote-not-showing'], 3);

        $this->assertNotEmpty($cards);
        $this->assertSame('vote-not-showing', $cards[0]['slug'],
            'A cited article belongs to the answer, so it leads.');
        $this->assertTrue($cards[0]['cited'],
            '"I used this" and "you might also want" are different claims.');
        foreach ($cards as $c) {
            $this->assertNotSame('', $c['title']);
            $this->assertNotSame('', $c['summary']);
            $this->assertStringStartsWith('/help/', $c['url']);
        }
    }

    /**
     * A browsing miss shows NOTHING, and a support miss shows the common three.
     *
     * The asymmetry is the design: somebody at the support desk arrived stuck, so
     * a blank strip is a failure; somebody asking how nominations work must not
     * be shown a refunds card, which is what an unconditional fallback set would
     * do on every single miss.
     */
    public function test_the_last_resort_set_is_only_offered_to_somebody_who_is_stuck(): void
    {
        $nonsense = 'zzzz qqqq wwww';

        $this->assertSame([], HelpCentre::previews($nonsense, [], 3),
            'Browsing chatter with no match gets no strip at all.');

        $stuck = HelpCentre::previews($nonsense, [], 3, lastResort: true);
        $this->assertCount(3, $stuck,
            'Never show an empty strip to somebody who came here with a problem.');
    }

    public function test_the_cards_never_exceed_the_cap(): void
    {
        // A wall of cards under every reply is indistinguishable from an advert
        // and teaches people to ignore the strip.
        $many = HelpCentre::previews('payment vote refund receipt nomination account',
            ['paid-but-no-votes', 'vote-not-showing', 'code-did-not-arrive', 'refund-policy'], 3);

        $this->assertLessThanOrEqual(3, count($many));
    }

    /** An unknown slug is skipped, not rendered as a broken card. */
    public function test_a_stale_cited_slug_does_not_produce_a_dead_card(): void
    {
        $cards = HelpCentre::previews('my votes are missing', ['this-article-was-deleted'], 3);

        foreach ($cards as $c) {
            $this->assertNotSame('this-article-was-deleted', $c['slug']);
            $this->assertNotNull(HelpCentre::bySlug($c['slug']));
        }
    }

    public function test_cited_slugs_are_read_out_of_the_agents_own_tool_results(): void
    {
        $results = [
            ['tool' => 'help_article', 'ok' => true, 'data' => [
                'found'   => true,
                'article' => ['url' => '/help/paid-but-no-votes'],
                'other_matches' => [['url' => '/help/vote-not-showing']],
            ]],
            ['tool' => 'my_votes', 'ok' => true, 'data' => ['votes' => []]],
        ];

        $this->assertSame(['paid-but-no-votes', 'vote-not-showing'],
            SupportAgentService::citedSlugs($results));
    }

    public function test_a_help_lookup_that_found_nothing_cites_nothing(): void
    {
        $this->assertSame([], SupportAgentService::citedSlugs([
            ['tool' => 'help_article', 'ok' => true, 'data' => ['found' => false]],
        ]));
    }

    // ── the widget's linkifier, checked against real article URLs ────────────

    /**
     * A Help Centre URL must be linked WHOLE.
     *
     * ── THE BUG THIS PINS ────────────────────────────────────────────────────
     *
     * The widget linkifies the paths a reply mentions from a fixed route list.
     * That list contains `/help`, and the pattern ended each alternative with
     * `\b` — which succeeds immediately before a `/`, because `/` is not a word
     * character. So the moment Gee started quoting article URLs, the reply
     *
     *     Read it here: /help/paid-but-no-votes
     *
     * rendered as a link labelled "the Help Center" pointing at /help, followed by
     * the bare text "/paid-but-no-votes". Nothing threw, nothing logged, and the
     * reader saw a link to the wrong page next to a fragment of a URL.
     *
     * Found by rendering it in Chromium, which is the only place it was visible.
     * This test reads the REAL patterns out of the shipped script and runs them
     * against a REAL fallback reply, so removing the guard fails here rather than
     * in a screenshot somebody happens to look at.
     */
    public function test_the_widget_links_a_whole_help_article_url_and_not_its_prefix(): void
    {
        [$helpRe, $routeRe] = $this->geeLinkPatterns();

        $reply = (new GuideService())->supportFallback('I paid but my votes have not appeared')['reply'];
        $this->assertMatchesRegularExpression('~/help/[a-z0-9-]+~', $reply,
            'The fallback is supposed to carry an article URL; this test is pointless without one.');

        // Articles first, exactly as the widget does it.
        $html = preg_replace_callback($helpRe,
            static fn(array $m) => $m[1] . '<a href="' . $m[2] . '">the Help Centre answer</a>', $reply);
        $html = preg_replace_callback($routeRe,
            static fn(array $m) => $m[1] . '<a href="' . $m[2] . '">route</a>', (string) $html);

        // The whole slug ended up inside the href, and no orphan is left behind.
        $this->assertStringContainsString('<a href="/help/paid-but-no-votes">', (string) $html);
        $this->assertStringNotContainsString('>paid-but-no-votes', (string) $html,
            'Part of the slug leaked out of the link — the \b bug is back.');
        $this->assertStringNotContainsString('<a href="/help">', (string) $html,
            'The bare /help route matched inside an article URL.');
    }

    /**
     * No route may be linked as the PREFIX of a longer path.
     *
     * The `/help` case above is saved by ordering — the article rule consumes the
     * URL before the route list sees it — but every other route in the list has
     * the same shape of hazard and no rule in front of it. `/registry/some-person`
     * and `/vote/paid/start` are paths Gee can plausibly emit, and with a `\b`
     * terminator each one renders as a link to the section index followed by an
     * orphaned path fragment, which sends the reader to the wrong page.
     *
     * This is the test that fails if the terminator regresses to `\b`.
     */
    public function test_a_route_is_never_linked_as_the_prefix_of_a_longer_path(): void
    {
        [, $routeRe] = $this->geeLinkPatterns();

        foreach (['/registry/dorcas-oluwagbemiga', '/vote/paid/start', '/awards/teachers-choice',
                  '/community/channels', '/events/gala-2026'] as $deep) {
            $this->assertSame(0, preg_match($routeRe, ' ' . $deep),
                "\"{$deep}\" was matched by the route list, so it would render as a link to "
                . 'the section index with the rest of the path left outside it.');
        }

        // The plain routes must still linkify, including with trailing punctuation.
        foreach ([' /vote', ' /support.', ' /help,', '(/donate)'] as $plain) {
            $this->assertSame(1, preg_match($routeRe, $plain),
                "\"{$plain}\" should still be linkified.");
        }
    }

    /** The slug pattern must stay narrow enough that it cannot escape an href. */
    public function test_the_article_pattern_cannot_break_out_of_the_attribute(): void
    {
        [$helpRe] = $this->geeLinkPatterns();

        foreach (['/help/x" onmouseover="alert(1)', '/help/a<b>c', "/help/a'b", '/help/a:b'] as $nasty) {
            preg_match($helpRe, ' ' . $nasty, $m);
            $captured = $m[2] ?? '';
            foreach (['"', "'", '<', '>', ':'] as $bad) {
                $this->assertStringNotContainsString($bad, $captured,
                    "The slug capture must never contain {$bad} — it is interpolated into an href.");
            }
        }
    }

    /**
     * The two link patterns, lifted out of the shipped widget.
     *
     * Read from the file rather than restated here on purpose: a copy in the test
     * would keep passing after somebody edited the script.
     *
     * @return array{0:string,1:string} [help articles, routes] as PCRE
     */
    private function geeLinkPatterns(): array
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/gee.js');

        $grab = function (string $name) use ($js): string {
            $this->assertSame(1, preg_match('/var\s+' . $name . '\s*=\s*\/(.+)\/g;/', $js, $m),
                "Could not find {$name} in gee.js — if it was renamed, update this test.");
            // JS → PCRE: same syntax for everything used here, only the delimiter
            // differs, and the source escapes '/' nowhere except as '\/'.
            return '~' . str_replace('\\/', '/', $m[1]) . '~';
        };

        return [$grab('HELP_RE'), $grab('ROUTE_RE')];
    }

    // ── the boundary ─────────────────────────────────────────────────────────

    /**
     * The identity factory is the ONLY thing that decides who is being served.
     *
     * This is the test that matters most in the file. Gee is a second front door
     * onto tools that read somebody's payments, and the guarantee in
     * SupportContext's class note ("from $_SESSION and NOTHING ELSE") survives
     * only while every door builds identity the same way. A hand-written copy in
     * a second controller is how that rots — so the factory takes no identity
     * parameter at all, and there is nothing to forget to validate.
     */
    public function test_a_guest_is_a_guest_at_every_front_door(): void
    {
        $_SESSION = [];
        $guest = SupportContext::fromSession(null, '203.0.113.9');

        $this->assertFalse($guest->isMember());
        $this->assertFalse($guest->isAdmin());

        // A guest is never even TOLD the disclosing tools exist.
        $names = array_column($guest->tools(), 'name');
        foreach (['my_transactions', 'my_votes', 'lookup_reference', 'my_tickets'] as $scoped) {
            $this->assertNotContains($scoped, $names,
                "A guest reached through Gee must not be offered {$scoped}.");
        }
        // The repair actions, which hand nothing back, are open — that is the
        // whole point: acting on a payment is open, reading one is not.
        $this->assertContains('fix_payment', $names);
    }

    public function test_the_factory_accepts_no_identity_argument(): void
    {
        $r = new \ReflectionMethod(SupportContext::class, 'fromSession');
        $names = array_map(static fn(\ReflectionParameter $p) => $p->getName(), $r->getParameters());

        $this->assertSame(['limits', 'ip'], $names,
            'If this ever grows a user id or an email parameter, a caller can be '
            . 'talked into passing somebody else\'s — which is exactly what the '
            . 'session-only rule exists to prevent.');
    }

    /** The raw address never reaches the context. */
    public function test_the_client_key_is_hashed_before_it_is_stored(): void
    {
        $_SESSION = [];
        $ctx = SupportContext::fromSession(null, '198.51.100.4');

        $key = (new \ReflectionProperty(SupportContext::class, 'clientKey'))->getValue($ctx);

        $this->assertNotSame('198.51.100.4', $key);
        $this->assertSame(hash('sha256', '198.51.100.4'), $key);
    }
}
