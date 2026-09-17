<?php
declare(strict_types=1);

namespace AfricaGates\Services;

/**
 * The published philosophy behind Africa GATES community voting.
 *
 * ── WHY THIS IS A CLASS AND NOT A TEMPLATE ───────────────────────────────────
 *
 * Because the same words have to leave the building in four shapes — the article
 * on /integrity, the plain text the Copy button puts on a clipboard, the Markdown
 * the Download button saves, and the citation a researcher pastes into a paper —
 * and a document that says one thing on the page and another in the download is
 * worse than no download at all.
 *
 * So the prose lives here ONCE, as structure, and every surface renders from it.
 * `templates/pages/integrity.twig` walks {@see sections()}; the download routes
 * call {@see markdown()} / {@see plainText()}. There is no second copy to update
 * and therefore no second copy to forget.
 *
 * ── EVERY FIGURE IS A PLACEHOLDER, NEVER A NUMBER ────────────────────────────
 *
 * The prose carries `{community_pct}`, `{judge_pct}`, `{return_pct}` and friends,
 * resolved by {@see resolve()} from values the caller reads out of
 * {@see RuleEngine} and {@see CommunityReturnService::displayRules()} — the same
 * objects the scorer and the payout consult.
 *
 * This is not a style preference. An operator can change the community/judge split
 * per programme and per cycle, and of every page on this site the one that must not
 * go on describing a system the code has stopped running is the one that explains
 * how winners are chosen. A typed "45%" here is a claim with nothing keeping it
 * true. `PhilosophyDocumentTest` asserts no section contains a bare percentage for
 * exactly that reason.
 *
 * ── ON VERSIONING ────────────────────────────────────────────────────────────
 *
 * A citable document needs a stable identity: {@see VERSION} and {@see UPDATED}
 * appear in the article header, in the download, and in every citation format. Bump
 * both together when the SUBSTANCE changes — a wording fix is not a new version, a
 * changed commitment is. The percentages moving does not bump the version, because
 * the document's claim is "the split is whatever the engine says", and that claim
 * has not changed.
 */
final class CommunityVotingPhilosophy
{
    /**
     * Substantive revision of this document. Bump with UPDATED, not on typo fixes.
     *
     * 1.0 → 1.1: four sections added (what "community" means, what standing for
     * someone asks of a nominee, what this model cannot do, recognition as a
     * record), and the document moved to its own page with /integrity carrying the
     * précis. New argument is a new version; the percentages moving is not, because
     * the document's claim is "the split is whatever the engine says".
     */
    public const VERSION = '1.1';

    /** ISO date of the last substantive revision. */
    public const UPDATED = '2026-08-08';

    /** First publication. Citations need both this and UPDATED. */
    public const PUBLISHED = '2026-08-08';

    public const TITLE = 'The Philosophy Behind Africa GATES Community Voting';
    public const SUBTITLE = 'Reimagining Recognition Through Communal Spirit';
    public const AUTHOR = 'Africa GATES Integrity Centre';
    public const PUBLISHER = 'Africa GATES — An Afrovanguard Initiative';

    /**
     * Canonical path. The absolute URL is built by the caller from SiteUrl.
     *
     * The document moved here from /integrity when it outgrew being a preamble to
     * the methodology: an argument that runs to sixteen sections was burying the
     * mechanics underneath it. /integrity now carries {@see summary()} and links
     * here for the whole of it.
     */
    public const PATH = '/philosophy';

    /**
     * The document, as structure.
     *
     * Block kinds, deliberately few — a document that can express anything ends up
     * expressing it inconsistently:
     *   ['p'      => string]            a paragraph (inline <strong>/<em>/<code> only)
     *   ['h3'     => string]            a subheading inside a section
     *   ['quote'  => string]            a pull quote; the voice of the document itself
     *   ['list'   => list<string>]      unordered points
     *   ['steps'  => list<string>]      ordered points
     *   ['note'   => array{title:string, body:list<string>}]   a boxed public notice
     *
     * @param array<string,string|int> $figures
     * @return list<array{id:string, title:string, blocks:list<array<string,mixed>>}>
     */
    public static function sections(array $figures = []): array
    {
        $s = self::raw();
        if ($figures === []) return $s;

        foreach ($s as $i => $sec) {
            $s[$i]['title']  = self::resolve($sec['title'], $figures);
            // A navigation-safe title: entities decoded, tags gone.
            //
            // The titles are authored for HTML and carry `&ldquo;`/`&rsquo;`, which the
            // body renders correctly because it prints them raw. A contents list
            // cannot: `striptags` leaves the entity as the literal text `&ldquo;`, and
            // autoescape then renders it as `&amp;ldquo;` — so the rail displayed
            // `What we mean by &ldquo;community&rdquo;`. Decoding here keeps the fix
            // out of the template and keeps the escaping on.
            $s[$i]['nav_title'] = self::text($s[$i]['title']);
            $s[$i]['blocks'] = array_map(
                static fn (array $b): array => self::resolveBlock($b, $figures),
                $sec['blocks']
            );
        }
        return $s;
    }

    /** The lede that opens the article, above the first numbered section. */
    public static function standfirst(array $figures = []): string
    {
        return self::resolve(
            'Africa GATES is founded on a simple but powerful African principle: we are '
            . 'stronger when we stand for one another. This document explains what a vote '
            . 'means here, why voting carries a token contribution, and why the public vote '
            . 'is deliberately limited to {community_pct}% of the outcome.',
            $figures
        );
    }

    /**
     * The short version, for the methodology page.
     *
     * ── WHY A SECOND RENDERING AND NOT A SECOND DOCUMENT ────────────────────
     *
     * /integrity answers "how is a winner decided". It needs the argument in front
     * of the mechanics — a reader who meets the {community_pct}/{judge_pct} split
     * without knowing why the public vote is capped at all reads the cap as a
     * hedge. But it needs it in about four hundred words, not sixteen sections,
     * which is what pushed the full essay onto its own page.
     *
     * So this is a précis, not an excerpt: written to stand alone at this length,
     * carrying the same tokens so it cannot quote a figure the engine has changed.
     * The last block hands the reader to the full document rather than trailing
     * off, because a summary that does not say what it is a summary OF reads like
     * the whole argument.
     *
     * @param array<string,string|int> $figures
     * @return list<array<string,mixed>>
     */
    public static function summary(array $figures = []): array
    {
        $blocks = [
            ['p' => 'Africa GATES begins from an African principle rather than an awards '
                  . 'convention: we are stronger when we stand for one another. Across African '
                  . 'societies a person&rsquo;s achievement has rarely been considered theirs '
                  . 'alone &mdash; families, neighbours, institutions and communities '
                  . 'contribute to the journey &mdash; and this platform is an attempt to bring '
                  . 'that into modern recognition.'],
            ['p' => 'So the public vote is not a popularity meter. The question it asks is not '
                  . '<em>how many people have heard of you</em> but <em>how many people are '
                  . 'willing to stand with you</em>. A token contribution is what makes that '
                  . 'answer mean something: it asks a supporter for a small, deliberate act '
                  . 'rather than a passing tap, and it funds the judging, verification and '
                  . 'production work the programme runs on. It buys participation in a '
                  . 'recognition process. It does not buy an award.'],
            ['quote' => 'How many people are willing to stand with you, support your journey '
                      . 'and identify with the values you represent?'],
            ['p' => 'That is also why the public vote is capped at <strong>{community_pct}%</strong> '
                  . 'and not set at 100%. Popularity is not excellence: a larger following is not '
                  . 'greater impact, a nominee with more capacity to mobilise supporters is not '
                  . 'necessarily the strongest candidate, and some of the most deserving people '
                  . 'come from communities with the fewest digital and financial resources. The '
                  . 'remaining <strong>{judge_pct}%</strong> is independent assessment, which is '
                  . 'what stops the process being decided by whoever can raise the most.'],
            ['p' => 'And because the community raises it, a share comes back: a nominee keeps '
                  . '<strong>{return_pct}%</strong> of what supporters contribute in their name, '
                  . 'win or lose, once they have gathered {return_threshold} votes of qualifying '
                  . 'support from at least {return_people} different verified people. The rest of '
                  . 'this page is how all of that is enforced in code.'],
        ];

        return $figures === []
            ? $blocks
            : array_map(static fn (array $b): array => self::resolveBlock($b, $figures), $blocks);
    }

    /**
     * @param array<string,string|int> $figures
     * @return list<array{id:string, title:string, blocks:list<array<string,mixed>>}>
     */
    private static function raw(): array
    {
        return [
            [
                'id'    => 'philosophy-communal',
                'title' => 'Recognition, reimagined through communal spirit',
                'blocks' => [
                    ['p' => 'Across African societies, communal responsibility has historically been one '
                          . 'of our greatest strengths. A person&rsquo;s achievement is rarely considered '
                          . 'theirs alone; families, neighbours, friends, institutions and communities '
                          . 'often contribute to the journey.'],
                    ['p' => 'Africa GATES seeks to bring that spirit into modern recognition. Our public '
                          . 'voting system is therefore designed not merely to determine who receives an '
                          . 'award, but to create an opportunity for communities to actively identify '
                          . 'with, celebrate, support and stand behind people who represent values worth '
                          . 'promoting.'],
                    ['p' => 'The question is not simply <em>&ldquo;How popular are you?&rdquo;</em>'],
                    ['quote' => 'How many people are willing to stand with you, support your journey and '
                              . 'identify with the values you represent?'],
                    ['p' => 'That distinction is fundamental to the Africa GATES philosophy.'],
                ],
            ],
            [
                'id'    => 'philosophy-community',
                'title' => 'What we mean by &ldquo;community&rdquo;',
                'blocks' => [
                    ['p' => 'The word does a lot of work in this document, so it is worth being '
                          . 'precise about it. We do not mean an audience, and we do not mean a '
                          . 'follower count.'],
                    ['p' => 'A nominee&rsquo;s community, as this platform counts it, is the set of '
                          . 'people who will do something small and deliberate on their behalf. '
                          . 'Sometimes that is family. Often it is the people they have actually '
                          . 'served &mdash; the women in a cooperative, the students from a '
                          . 'workshop, the neighbourhood a clinic sits in. Sometimes it is '
                          . 'strangers who recognise the work and decide it should be seen.'],
                    ['p' => 'This is a narrower thing than fame and a broader thing than family, '
                          . 'and it is measurable in a way neither is. It is also why our counting '
                          . 'rules are shaped the way they are: qualification requires support from '
                          . 'a number of <em>different verified people</em>, not a sum of money, '
                          . 'because a community is a plurality by definition. One very generous '
                          . 'person is a patron. That is a good thing to have, and it is not the '
                          . 'same thing.'],
                    ['quote' => 'A community is a plurality. One person, however generous, cannot '
                              . 'be one.'],
                ],
            ],
            [
                'id'    => 'philosophy-why-paid',
                'title' => 'Why is voting paid?',
                'blocks' => [
                    ['p' => 'The introduction of a token-based public vote is intentional. Africa GATES is '
                          . 'not designed around passive applause. We want recognition to involve '
                          . 'participation, commitment and communal responsibility.'],
                    ['p' => 'A token contribution gives supporters a practical way of saying: '
                          . '<strong>&ldquo;I see you. I value what you represent. I am willing to stand '
                          . 'with you.&rdquo;</strong>'],
                    ['p' => 'The contribution is therefore <strong>not presented as a purchase of an '
                          . 'award</strong>. It is a participatory contribution toward a community-driven '
                          . 'recognition process and the activities required to deliver the Africa GATES '
                          . 'experience.'],
                    ['p' => 'This model also helps us understand the depth of community engagement around '
                          . 'each nominee. A person may be widely known, but recognition should also '
                          . 'reveal whether people are sufficiently connected to that person&rsquo;s work '
                          . 'or values to actively support them.'],
                ],
            ],
            [
                'id'    => 'philosophy-communalism',
                'title' => 'The African idea of communal spirit',
                'blocks' => [
                    ['p' => 'Africa GATES draws from the enduring philosophy of communalism found across '
                          . 'many African societies. From Ubuntu&rsquo;s emphasis on shared humanity to '
                          . 'traditional African systems of collective responsibility, the underlying '
                          . 'principle is familiar:'],
                    ['quote' => 'Individual excellence becomes more meaningful when it contributes to the '
                              . 'advancement of the community.'],
                    ['p' => 'Africa GATES modernises this principle through technology.'],
                    ['list' => [
                        'Instead of recognition being determined exclusively behind closed doors, the '
                        . 'community receives a voice.',
                        'Instead of influence being determined entirely by political connections, the '
                        . 'public can participate.',
                        'Instead of recognition being reduced to personal popularity, nominees are '
                        . 'challenged to demonstrate the strength of the community that identifies with '
                        . 'their contribution.',
                    ]],
                ],
            ],
            [
                'id'    => 'philosophy-influence',
                'title' => 'Protecting the process from political and corrupt influence',
                'blocks' => [
                    ['p' => 'Africa GATES recognises that every recognition system can face questions '
                          . 'around influence, favouritism and interference. Our philosophy is therefore '
                          . 'deliberately structured to reduce dependence on political patronage, personal '
                          . 'connections, institutional influence or closed-door decision-making.'],
                    ['p' => 'The public voting component provides an open mechanism through which ordinary '
                          . 'supporters can participate. However, <strong>public votes do not constitute '
                          . '100% of the final recognition decision</strong>. This is important.'],
                    ['h3' => 'Public voting accounts for only {community_pct}%'],
                    ['p' => 'The Africa GATES public vote represents <strong>{community_pct}% of the '
                          . 'overall assessment</strong>. The remaining <strong>{judge_pct}%</strong> is '
                          . 'determined through other components of the Africa GATES evaluation '
                          . 'framework, including the relevant judging, eligibility, assessment and '
                          . 'organisational processes.'],
                    ['p' => 'This means:'],
                    ['list' => [
                        'Money alone cannot guarantee an award.',
                        'A nominee cannot simply &ldquo;buy&rdquo; the recognition.',
                        'A candidate with strong financial mobilisation but weak merit, conduct, impact or '
                        . 'assessment should not automatically prevail.',
                        'Likewise, a nominee with strong merit should not have their entire recognition '
                        . 'determined by popularity alone.',
                    ]],
                    ['p' => 'The {community_pct}% public-vote component is therefore intended to balance '
                          . 'community voice with independent assessment.'],
                ],
            ],
            [
                'id'    => 'philosophy-what-a-vote-is',
                'title' => 'What the vote actually represents',
                'blocks' => [
                    ['p' => 'A vote should not be interpreted as <em>&ldquo;&#8358;X = an award&rdquo;</em>. '
                          . 'Rather:'],
                    ['quote' => 'A vote is one expression of public support within a broader recognition '
                              . 'framework.'],
                    ['p' => 'The number of votes provides a measurable indication of community mobilisation '
                          . 'and support. It tells a story about the relationship between a nominee and the '
                          . 'people who identify with their contribution.'],
                    ['p' => 'That story matters. But it is only one part of the story.'],
                ],
            ],
            [
                'id'    => 'philosophy-nominee-obligation',
                'title' => 'What standing for someone asks of them',
                'blocks' => [
                    ['p' => 'Almost everything written here describes what the community owes a '
                          . 'nominee. The obligation runs the other way too, and a philosophy that '
                          . 'only described one direction would be a marketing document.'],
                    ['p' => 'When people put their names and their money behind a person, they are '
                          . 'making a claim about that person in public. Being nominated here is '
                          . 'therefore not a prize to be collected; it is a claim to be lived up '
                          . 'to. That has practical consequences we take seriously:'],
                    ['list' => [
                        'A nominee is answerable for how their campaign behaves. Supporters '
                        . 'mobilised in someone&rsquo;s name act in their name, and pressure, '
                        . 'misrepresentation or abuse by a campaign is a matter for the nominee.',
                        'Recognition is for work that can be described. Where a nominee cannot say '
                        . 'what changed because of what they did, no amount of community support '
                        . 'makes that a stronger case.',
                        'Integrity is one of the things judges score, and it is scored on conduct '
                        . 'under pressure &mdash; including conduct during the cycle itself.',
                    ]],
                    ['p' => 'None of that is a threat. It is the reason recognition is worth '
                          . 'anything at all: an award that asked nothing of the person receiving '
                          . 'it would tell you nothing about them.'],
                ],
            ],
            [
                'id'    => 'philosophy-where-money-goes',
                'title' => 'Where does the money go?',
                'blocks' => [
                    ['p' => 'Transparency is essential to the credibility of Africa GATES. The resources '
                          . 'generated through the voting process help us meet the practical requirements '
                          . 'of delivering the programme, including the logistics, technology, '
                          . 'administration, communications, verification, production and other '
                          . 'operational requirements associated with the awards and Grand Finale.'],
                    ['p' => 'Africa GATES is not simply an online voting page. Behind the platform are '
                          . 'people, technology, verification systems, communications, volunteers, '
                          . 'administration, event production and the physical requirements necessary to '
                          . 'bring nominees, judges, volunteers, supporters and guests together. The '
                          . 'voting model helps make that ecosystem possible.'],
                ],
            ],
            [
                'id'    => 'philosophy-community-return',
                'title' => 'Community return: when the community wins, the nominee benefits',
                'blocks' => [
                    ['p' => 'Africa GATES goes beyond simply collecting votes. One of the distinctive '
                          . 'principles of the programme is our commitment to <strong>community '
                          . 'return</strong>.'],
                    ['p' => 'Subject to the applicable programme rules and financial conditions, a nominee '
                          . 'keeps <strong>{return_pct}% of what supporters contribute in their name</strong> '
                          . '&mdash; win or lose &mdash; once they have gathered {return_threshold} votes of '
                          . 'qualifying support. No single supporter may supply more than {return_cap_pct}% '
                          . 'of that threshold, so qualifying takes at least {return_people} different '
                          . 'verified people. The figures on this page are read from the live programme '
                          . 'settings rather than typed, so they cannot fall out of date.'],
                    ['p' => 'This creates a different philosophy: the community supports the nominee, and '
                          . 'the success of the programme creates an opportunity for value to return to '
                          . 'the nominee. In other words:'],
                    ['quote' => 'We are not only asking the community to give; we are creating a mechanism '
                              . 'through which success can also give back.'],
                    ['p' => 'This reflects the African principle of mutual responsibility. I support you. '
                          . 'You represent us. Together, we create value. And value returns to the '
                          . 'community.'],
                    ['p' => 'The exact eligibility requirements, targets, allocation methodology and '
                          . 'applicable conditions will always be communicated transparently to nominees '
                          . 'and participants.'],
                ],
            ],
            [
                'id'    => 'philosophy-not-100',
                'title' => 'Why not make the public vote 100%?',
                'blocks' => [
                    ['p' => 'Because Africa GATES does not believe that popularity should be synonymous '
                          . 'with excellence. Public voting is powerful, but it has limitations.'],
                    ['list' => [
                        'A person may have a larger social-media following without necessarily having '
                        . 'greater impact.',
                        'A nominee may have more financial capacity to mobilise supporters without '
                        . 'necessarily being the strongest candidate.',
                        'Some deserving individuals may come from communities with fewer financial or '
                        . 'digital resources.',
                    ]],
                    ['p' => 'For this reason, Africa GATES deliberately limits public voting to '
                          . '{community_pct}%. The remaining {judge_pct}% provides room for structured '
                          . 'assessment and independent judgement. This is intended to create a healthier '
                          . 'balance between public voice, merit, impact, values and independent '
                          . 'assessment.'],
                ],
            ],
            [
                'id'    => 'philosophy-limits',
                'title' => 'What this model cannot do',
                'blocks' => [
                    ['p' => 'A document that only argued for its own design would not be worth '
                          . 'reading, and we would rather state the limits than be told them.'],
                    ['p' => 'A token contribution is a smaller barrier than a ticket and it is '
                          . 'still a barrier. Somebody who cannot spare it can vote free of charge '
                          . 'and their vote is the one that moves the score &mdash; but we are not '
                          . 'going to pretend the paid tier is neutral between a nominee whose '
                          . 'supporters have disposable income and one whose supporters do not. '
                          . 'That is the reason the paid component is capped as a proportion of a '
                          . 'nominee&rsquo;s <em>earned</em> support rather than allowed to run '
                          . 'free, and the reason it is excluded from the index entirely. It is a '
                          . 'mitigation. It is not a solution.'],
                    ['p' => 'Nor can community support tell you everything. Quiet, unglamorous, '
                          . 'long-term work mobilises fewer people than work that photographs '
                          . 'well, and there are people doing the most important work on this '
                          . 'continent whose communities will never be loud. That asymmetry is '
                          . 'precisely what the judging component exists to correct, and correcting '
                          . 'it is a matter of judgement rather than arithmetic &mdash; which means '
                          . 'it can be got wrong.'],
                    ['p' => 'What we can promise is that the method is published, the figures are '
                          . 'read from the live system rather than typed into a page, results are '
                          . 'sealed against quiet editing, and a decision can be disputed and '
                          . 'audited. A process that can be checked is not the same as a process '
                          . 'that is never wrong. It is the more honest of the two things to offer.'],
                ],
            ],
            [
                'id'    => 'philosophy-record',
                'title' => 'Recognition as a record',
                'blocks' => [
                    ['p' => 'There is one more reason to do this carefully, and it has nothing to '
                          . 'do with prizes.'],
                    ['p' => 'Africa has never lacked extraordinary people. What the continent has '
                          . 'often lacked are systems that consistently identify, document and '
                          . 'amplify them &mdash; so achievement goes unrecorded, and a generation '
                          . 'grows up able to name foreign figures more readily than the ones who '
                          . 'built what is around them.'],
                    ['p' => 'Every verified nomination, every scored ballot and every sealed result '
                          . 'is therefore also an archive entry: a dated, evidenced, checkable '
                          . 'record that this person did this work and these people stood behind '
                          . 'them. That is why nominee profiles persist after a cycle ends, why '
                          . 'standings are sealed rather than overwritten, and why the register is '
                          . 'public and searchable.'],
                    ['quote' => 'An award is an event. A record is what is still there in twenty '
                              . 'years.'],
                ],
            ],
            [
                'id'    => 'philosophy-movement',
                'title' => 'A movement, not a transaction',
                'blocks' => [
                    ['p' => 'Africa GATES should therefore not be understood merely as a competition in '
                          . 'which contestants purchase votes. It is better understood as a '
                          . '<strong>community-powered recognition movement</strong>.'],
                    ['p' => 'The vote is a mechanism. The deeper objective is to encourage people to ask:'],
                    ['list' => [
                        'Who are we celebrating?',
                        'What values do they represent?',
                        'What impact have they made?',
                        'Who stands behind them?',
                        'What kind of Africa are we encouraging through the people we recognise?',
                        'How can recognition become a catalyst for greater community participation?',
                    ]],
                    ['p' => 'Africa GATES is ultimately concerned with what our society chooses to '
                          . 'celebrate.'],
                    ['quote' => 'Because what society celebrates, society reproduces.'],
                    ['p' => 'If we celebrate integrity, service, innovation, creativity, leadership, '
                          . 'excellence and community impact, we strengthen those values.'],
                ],
            ],
            [
                'id'    => 'philosophy-disclaimer',
                'title' => 'Anti-fraud, anti-pyramid and anti-manipulation disclaimer',
                'blocks' => [
                    ['note' => [
                        'title' => 'Important public notice',
                        'body'  => [
                            'Africa GATES is <strong>not</strong> a Ponzi scheme, pyramid scheme, '
                            . 'multi-level marketing (MLM) programme, investment scheme, get-rich-quick '
                            . 'scheme or fraudulent fundraising operation.',
                            'Participants are not promised financial returns for recruiting other '
                            . 'participants. No participant earns money simply by introducing another '
                            . 'person into the programme. There is no requirement for nominees or '
                            . 'supporters to recruit people into a chain in order to receive a financial '
                            . 'return.',
                            'Payments made through the official Africa GATES voting system are '
                            . 'contributions for public voting and participation in the recognition '
                            . 'programme, subject to the programme&rsquo;s published terms and conditions.',
                            'Any community-return arrangement is a programme-based allocation subject to '
                            . 'stated eligibility requirements and does not constitute an investment '
                            . 'return, interest, profit guarantee or promise of financial appreciation.',
                            'Africa GATES also does not authorise nominees, volunteers or third parties to '
                            . 'manipulate votes, impersonate officials, create fraudulent payment '
                            . 'channels, falsify voting records or interfere corruptly with the judging '
                            . 'process.',
                            'Any suspected manipulation, fraudulent activity, impersonation or '
                            . 'unauthorised financial solicitation should be reported through the official '
                            . 'Africa GATES channels.',
                        ],
                    ]],
                ],
            ],
            [
                'id'    => 'philosophy-transparency',
                'title' => 'Our commitment to transparency',
                'blocks' => [
                    ['p' => 'For Africa GATES to earn public trust, transparency must be stronger than '
                          . 'publicity. We therefore commit to communicating clearly:'],
                    ['steps' => [
                        'How voting works.',
                        'What percentage public voting contributes to the final outcome.',
                        'The applicable voting targets and deadlines.',
                        'The conditions attached to any community-return arrangement.',
                        'The applicable judging and assessment framework.',
                        'The official channels through which votes and payments may be made.',
                        'The rules governing nominee eligibility and disqualification.',
                        'The procedures for addressing suspected fraudulent or manipulated activity.',
                    ]],
                    ['p' => 'Our objective is not simply to run an award. Our objective is to build an '
                          . 'award system that people can understand, participate in and trust. The '
                          . 'sections that follow set out the mechanics, and every figure in them is read '
                          . 'from the live scoring engine.'],
                ],
            ],
            [
                'id'    => 'philosophy-let-community-speak',
                'title' => 'Let the community speak',
                'blocks' => [
                    ['p' => 'Africa has never lacked extraordinary people. What we have often lacked are '
                          . 'systems capable of consistently identifying, celebrating, documenting and '
                          . 'amplifying them. Africa GATES seeks to become part of that solution.'],
                    ['p' => 'We believe recognition should not be restricted to those with the strongest '
                          . 'political connections, the loudest voices or the deepest pockets. It should '
                          . 'create space for merit, impact, values and community voice.'],
                    ['list' => [
                        'The public vote gives the community a voice.',
                        'The judges provide independent assessment.',
                        'The programme provides the platform.',
                        'The nominees provide the work.',
                        'And the community provides the spirit.',
                    ]],
                    ['p' => 'This is the Africa GATES idea. Stand for someone. Celebrate what is worthy. '
                          . 'Support your community. Let excellence be seen. And when the community '
                          . 'succeeds, let the value return to the people.'],
                    ['quote' => 'Africa GATES &mdash; Recognition Powered by Community.'],
                ],
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Placeholder resolution
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Replace `{token}` with the live value.
     *
     * Unknown tokens are left standing on purpose. A `{judge_pct}` visible on the
     * page is a bug report anybody can file; a silently blanked one reads as
     * finished copy that happens to be missing a number.
     *
     * @param array<string,string|int> $figures
     */
    private static function resolve(string $s, array $figures): string
    {
        if ($figures === []) return $s;
        $map = [];
        foreach ($figures as $k => $v) $map['{' . $k . '}'] = (string) $v;
        return strtr($s, $map);
    }

    /**
     * @param array<string,mixed>      $b
     * @param array<string,string|int> $figures
     * @return array<string,mixed>
     */
    private static function resolveBlock(array $b, array $figures): array
    {
        foreach ($b as $kind => $val) {
            if (is_string($val)) {
                $b[$kind] = self::resolve($val, $figures);
            } elseif ($kind === 'note' && is_array($val)) {
                $b[$kind] = [
                    'title' => self::resolve((string) ($val['title'] ?? ''), $figures),
                    'body'  => array_map(
                        static fn ($p): string => self::resolve((string) $p, $figures),
                        (array) ($val['body'] ?? [])
                    ),
                ];
            } elseif (is_array($val)) {
                $b[$kind] = array_map(
                    static fn ($p): string => self::resolve((string) $p, $figures),
                    $val
                );
            }
        }
        return $b;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Portable renderings — the Copy button and the Download routes
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Inline HTML back to text.
     *
     * `strip_tags` alone is not enough: the prose is written with entities
     * (`&rsquo;`, `&mdash;`, `&#8358;`) because it is authored for HTML, and a
     * clipboard containing a literal `&rsquo;` is a broken paste. Decode first,
     * then strip, so the text version reads as prose rather than as markup that
     * lost its tags.
     */
    private static function text(string $html): string
    {
        $s = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', strip_tags($s)) ?? '');
    }

    /** Wrap to a readable measure, so a .txt download is not one endless line. */
    private static function wrap(string $s, int $width = 78): string
    {
        return wordwrap($s, $width, "\n", false);
    }

    /**
     * The whole document as plain text — what the Copy button places on the
     * clipboard and what `/integrity.txt` serves.
     *
     * @param array<string,string|int> $figures
     */
    public static function plainText(array $figures, string $url, ?string $accessed = null): string
    {
        $out = [];
        $out[] = strtoupper(self::TITLE);
        $out[] = self::SUBTITLE;
        $out[] = '';
        $out[] = self::PUBLISHER;
        $out[] = 'Version ' . self::VERSION . ' · Updated ' . self::UPDATED;
        $out[] = $url;
        $out[] = str_repeat('=', 78);
        $out[] = '';
        $out[] = self::wrap(self::text(self::standfirst($figures)));
        $out[] = '';

        foreach (self::sections($figures) as $i => $sec) {
            $n = str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
            $out[] = '';
            $out[] = $n . '. ' . strtoupper(self::text($sec['title']));
            $out[] = str_repeat('-', 78);
            foreach ($sec['blocks'] as $b) {
                foreach ($b as $kind => $val) {
                    switch ($kind) {
                        case 'p':
                            $out[] = self::wrap(self::text((string) $val));
                            $out[] = '';
                            break;
                        case 'h3':
                            $out[] = self::text((string) $val);
                            $out[] = '';
                            break;
                        case 'quote':
                            $out[] = self::wrap('"' . self::text((string) $val) . '"', 74);
                            $out[] = '';
                            break;
                        case 'list':
                            foreach ((array) $val as $li) {
                                $out[] = self::wrap('  - ' . self::text((string) $li), 76);
                            }
                            $out[] = '';
                            break;
                        case 'steps':
                            foreach (array_values((array) $val) as $k => $li) {
                                $out[] = self::wrap('  ' . ($k + 1) . '. ' . self::text((string) $li), 76);
                            }
                            $out[] = '';
                            break;
                        case 'note':
                            $note = (array) $val;
                            $out[] = '*** ' . strtoupper(self::text((string) ($note['title'] ?? ''))) . ' ***';
                            $out[] = '';
                            foreach ((array) ($note['body'] ?? []) as $p) {
                                $out[] = self::wrap(self::text((string) $p));
                                $out[] = '';
                            }
                            break;
                    }
                }
            }
        }

        $out[] = str_repeat('=', 78);
        $out[] = 'HOW TO CITE';
        $out[] = '';
        foreach (self::citations($url, $accessed) as $c) {
            $out[] = $c['label'] . ':';
            $out[] = self::wrap($c['text']);
            $out[] = '';
        }
        return rtrim(implode("\n", $out)) . "\n";
    }

    /**
     * The whole document as Markdown — what `/integrity.md` serves.
     *
     * @param array<string,string|int> $figures
     */
    public static function markdown(array $figures, string $url, ?string $accessed = null): string
    {
        $out = [];
        $out[] = '# ' . self::text(self::TITLE);
        $out[] = '';
        $out[] = '**' . self::SUBTITLE . '**';
        $out[] = '';
        $out[] = '> ' . self::text(self::standfirst($figures));
        $out[] = '';
        $out[] = '| | |';
        $out[] = '|---|---|';
        $out[] = '| Publisher | ' . self::PUBLISHER . ' |';
        $out[] = '| Version | ' . self::VERSION . ' |';
        $out[] = '| Published | ' . self::PUBLISHED . ' |';
        $out[] = '| Updated | ' . self::UPDATED . ' |';
        $out[] = '| Canonical URL | <' . $url . '> |';
        $out[] = '';

        foreach (self::sections($figures) as $i => $sec) {
            $out[] = '## ' . ($i + 1) . '. ' . self::text($sec['title']);
            $out[] = '';
            foreach ($sec['blocks'] as $b) {
                foreach ($b as $kind => $val) {
                    switch ($kind) {
                        case 'p':
                            $out[] = self::text((string) $val);
                            $out[] = '';
                            break;
                        case 'h3':
                            $out[] = '### ' . self::text((string) $val);
                            $out[] = '';
                            break;
                        case 'quote':
                            $out[] = '> ' . self::text((string) $val);
                            $out[] = '';
                            break;
                        case 'list':
                            foreach ((array) $val as $li) $out[] = '- ' . self::text((string) $li);
                            $out[] = '';
                            break;
                        case 'steps':
                            foreach (array_values((array) $val) as $k => $li) {
                                $out[] = ($k + 1) . '. ' . self::text((string) $li);
                            }
                            $out[] = '';
                            break;
                        case 'note':
                            $note = (array) $val;
                            $out[] = '> **' . self::text((string) ($note['title'] ?? '')) . '**';
                            $out[] = '>';
                            foreach ((array) ($note['body'] ?? []) as $p) {
                                $out[] = '> ' . self::text((string) $p);
                                $out[] = '>';
                            }
                            $out[] = '';
                            break;
                    }
                }
            }
        }

        $out[] = '---';
        $out[] = '';
        $out[] = '## How to cite';
        $out[] = '';
        foreach (self::citations($url, $accessed) as $c) {
            $out[] = '**' . $c['label'] . '**';
            $out[] = '';
            $out[] = $c['id'] === 'bibtex' ? "```bibtex\n" . $c['text'] . "\n```" : $c['text'];
            $out[] = '';
        }
        return rtrim(implode("\n", $out)) . "\n";
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Citation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Citation strings for the formats a reader is most likely to be asked for.
     *
     * The punctuation lives in {@see \AfricaGates\Support\Citation}, shared with the
     * terms and the privacy policy — four documents carrying four hand-written APA
     * formats would be three chances to get the comma before the retrieval date
     * wrong. This method's job is to say what this document IS.
     *
     * `accessed` is a parameter rather than a `date()` call so a test can pin it —
     * and so the page, the .txt and the .md produced by one request all agree on
     * the date rather than each asking the clock again.
     *
     * @return list<array{id:string, label:string, text:string}>
     */
    public static function citations(string $url, ?string $accessed = null): array
    {
        return \AfricaGates\Support\Citation::formats([
            'title'     => self::text(self::TITLE),
            'author'    => self::AUTHOR,
            'publisher' => self::PUBLISHER,
            'version'   => self::VERSION,
            'published' => self::PUBLISHED,
            'updated'   => self::UPDATED,
            'url'       => $url,
            'accessed'  => $accessed ?? date('Y-m-d'),
            'key'       => 'philosophy',
        ]);
    }

    /** Filename stem for the downloads. No spaces, versioned, sorts sensibly. */
    public static function fileStem(): string
    {
        return 'africa-gates-community-voting-philosophy-v' . self::VERSION;
    }

    /**
     * Rough reading time, from the document's own word count.
     *
     * Counted rather than guessed, so adding a section updates the estimate
     * without anybody remembering to. 210 wpm is the usual figure for
     * non-technical prose.
     *
     * @param array<string,string|int> $figures
     */
    public static function readMinutes(array $figures = []): int
    {
        $words = str_word_count(self::text(self::standfirst($figures)));
        foreach (self::sections($figures) as $sec) {
            $words += str_word_count(self::text($sec['title']));
            foreach ($sec['blocks'] as $b) {
                foreach ($b as $kind => $val) {
                    if (is_string($val)) {
                        $words += str_word_count(self::text($val));
                    } elseif ($kind === 'note' && is_array($val)) {
                        foreach ((array) ($val['body'] ?? []) as $p) {
                            $words += str_word_count(self::text((string) $p));
                        }
                    } elseif (is_array($val)) {
                        foreach ($val as $p) $words += str_word_count(self::text((string) $p));
                    }
                }
            }
        }
        return max(1, (int) ceil($words / 210));
    }
}
