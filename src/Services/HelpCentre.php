<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * The written answers — one corpus, read by the Help Centre AND by the assistant.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THESE ARE NOT SIX FAQs IN A TEMPLATE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * They were. `help.twig` carried six question/answer pairs in a `{% set %}` block,
 * and {@see SupportKnowledge} carried a separate set of playbooks for the models.
 * Two hand-written sources of truth for the same questions, in different files,
 * neither aware of the other.
 *
 * That is not untidiness, it is a correctness problem with a delivery date. The
 * cutoff moved, the refund grace doubled, paid voting gained a tier ladder — and
 * each change had to be remembered in two places by whoever happened to make it.
 * A supporter who reads the Help Centre and a supporter who asks the assistant
 * are the same person asking the same question, and they were being answered from
 * different documents.
 *
 * So: ONE corpus. `/help` renders it. The assistant searches it and quotes it with
 * a URL, so its answer and the page agree by construction rather than by
 * diligence — and when the assistant is wrong, there is one place to fix it and
 * the fix reaches both.
 *
 * ── NOTHING HERE HARDCODES A NUMBER THAT CAN CHANGE ──────────────────────────
 *
 * A help article that states a price is a help article that eventually lies, and
 * a lie on a help page is worse than a gap: the reader has no way to know. So the
 * prose carries PLACEHOLDERS — `{price}`, `{cutoff}`, `{grace}` — resolved from
 * the running system at render time by {@see resolve()}. If the admin changes the
 * price of a vote, every article that mentions it is already correct.
 *
 * The same discipline as SupportKnowledge, for the same reason: everything that
 * can be read from the system is read from it, and only what is genuinely policy
 * is written down.
 *
 * ── WHAT MAKES SOMETHING AN ARTICLE ──────────────────────────────────────────
 *
 * A question real people actually ask, in the words they actually use. Not a
 * feature tour. {@see \AfricaGates\Console\Commands\SupportGapsCommand} mines the
 * live ticket queue for recurring questions and reports which have no article
 * here, so the corpus grows from evidence rather than from imagination.
 */
final class HelpCentre
{
    /**
     * Categories, in the order a stuck person scans them.
     *
     * Money first. That is not how a product team would order it — they would lead
     * with nominations, because that is the beginning of the journey — but the
     * ticket queue says otherwise: somebody who has paid and seen nothing is both
     * the commonest arrival and the most upset, and making them scroll past
     * "How to nominate" to reach their answer is a small unkindness that repeats
     * thousands of times.
     *
     * @var array<string,array{title:string,blurb:string,tint:string,fg:string,icon:string}>
     */
    public const CATEGORIES = [
        'payments' => [
            'title' => 'Payments & votes you paid for',
            'blurb' => 'Money taken, votes missing, receipts and refunds.',
            'tint'  => '#fdeaf0', 'fg' => '#b03a5b',
            'icon'  => '<rect x="2" y="5" width="20" height="14" rx="3"/><path d="M2 10h20"/>',
        ],
        'voting' => [
            'title' => 'Voting',
            'blurb' => 'Free voting, codes, limits and why a vote may not show.',
            'tint'  => '#eef7ee', 'fg' => '#1a6118',
            'icon'  => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        ],
        'nominations' => [
            'title' => 'Nominations',
            'blurb' => 'Entering someone, what happens next, and decisions.',
            'tint'  => '#fff8df', 'fg' => '#7a5600',
            'icon'  => '<circle cx="12" cy="8" r="5"/><path d="M8.2 12 7 22l5-3 5 3-1.2-10"/>',
        ],
        'results' => [
            'title' => 'Results & integrity',
            'blurb' => 'How scoring works, and how to challenge it.',
            'tint'  => '#e8f1f7', 'fg' => '#1c5a86',
            'icon'  => '<path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/>',
        ],
        'account' => [
            'title' => 'Account & profile',
            'blurb' => 'Signing in, your registry profile, your details.',
            'tint'  => '#e9efef', 'fg' => '#2b373d',
            'icon'  => '<path d="M20 21a8 8 0 1 0-16 0"/><circle cx="12" cy="7" r="4"/>',
        ],
        'privacy' => [
            'title' => 'Privacy & your data',
            'blurb' => 'What we hold, what we never hold, and your rights.',
            'tint'  => '#f0f2f2', 'fg' => '#4a5256',
            'icon'  => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        ],
    ];

    /**
     * The corpus.
     *
     * Written to be READ BY A WORRIED PERSON ON A PHONE, which drives every choice
     * of shape here: the answer is in the first paragraph, the steps are numbered,
     * and nothing opens with background. If somebody has paid ₦5,000 and seen no
     * votes, the first sentence they meet must be about their money.
     *
     * `keywords` are the words THEY use, not ours — "not reflecting", "debited",
     * "OPay" — because that is what gets typed into the search box and into the
     * assistant. Several articles carry keywords that appear nowhere in their own
     * prose for exactly that reason.
     *
     * @return list<array<string,mixed>>
     */
    public static function articles(): array
    {
        return [

        // ── PAYMENTS ─────────────────────────────────────────────────────────
        [
            'slug' => 'paid-but-no-votes',
            'cat'  => 'payments',
            'title' => 'I paid but my votes have not appeared',
            'summary' => 'Almost always fixable in under a minute, and you do not need to wait for anyone.',
            'keywords' => ['not reflecting', 'not showing', 'debited', 'deducted', 'paid no votes',
                           'money gone', 'votes missing', 'did not receive votes', 'no vote after payment'],
            'body' => [
                ['p' => 'Your money is not lost. The commonest cause is that your bank confirmed the '
                      . 'payment to us after you had already closed the page — very common if you paid '
                      . 'inside a wallet or bank app, because your phone never came back to our site to '
                      . 'tell us. The payment is real; our record simply has not caught up.'],
                ['steps' => [
                    'Find your reference. It starts with <code>AFG-</code> and is on the payment page, in '
                        . 'our email, and in the bank alert if you paid by card.',
                    'Open the <a href="/support/assistant">support assistant</a> and paste it in.',
                    'It re-checks your payment directly with the bank and adds the votes on the spot if '
                        . 'the money arrived.',
                ]],
                ['note' => 'You do not need an account, and you can do this as many times as you like — '
                         . 'it can never double-count your votes.'],
                ['p' => 'If your reference does not start with <code>AFG-</code>, see '
                      . '<a href="/help/wallet-app-reference">the reference my wallet app shows me is different</a>.'],
                ['p' => 'If the assistant says the payment did not go through but your bank has definitely '
                      . 'debited you, that money is usually a pending authorisation that reverses itself '
                      . 'within a few days. Send us the reference anyway and we will check it by hand.'],
            ],
            'related' => ['wallet-app-reference', 'refund-when-votes-cannot-count', 'no-receipt'],
        ],
        [
            'slug' => 'wallet-app-reference',
            'cat'  => 'payments',
            'title' => 'The reference my wallet app shows is different',
            'summary' => 'OPay, PalmPay, Kuda and bank apps show their own transaction number. Both are real.',
            'keywords' => ['opay', 'palmpay', 'kuda', 'moniepoint', 'transaction id', 'wrong reference',
                           'reference not found', 'bank reference'],
            'body' => [
                ['p' => 'Every payment here has two numbers. Ours begins with <code>AFG-</code>. Your bank '
                      . 'or wallet app generates its own, completely separate one for its records. Both '
                      . 'describe the same payment and neither is wrong.'],
                ['p' => 'We can only look up ours. If you give us your wallet\'s number we will tell you we '
                      . 'cannot find it, and that is not the same as saying you did not pay.'],
                ['steps' => [
                    'Look for the email we sent when you started the payment — the <code>AFG-</code> '
                        . 'reference is in it.',
                    'Or check the browser tab you paid from; it is shown on the confirmation page.',
                    'If you are signed in, the assistant can list your own recent payments without you '
                        . 'finding any reference at all — just ask it.',
                ]],
                ['note' => 'Cannot find it anywhere? Tell support the amount, the date and the email you '
                         . 'used. That is enough for a person to match it.'],
            ],
            'related' => ['paid-but-no-votes', 'no-receipt'],
        ],
        [
            'slug' => 'no-receipt',
            'cat'  => 'payments',
            'title' => 'My votes are there but no receipt arrived',
            'summary' => 'A separate problem from a stuck payment, with a one-step fix.',
            'keywords' => ['receipt', 'no email', 'confirmation email', 'invoice', 'proof of payment'],
            'body' => [
                ['p' => 'If the votes are on the nominee, the payment worked and there is nothing to '
                      . 'repair — you are simply missing the paperwork.'],
                ['steps' => [
                    'Check spam and promotions. Receipts land there more often than anywhere else.',
                    'Ask the <a href="/support/assistant">assistant</a> to resend it with your '
                        . '<code>AFG-</code> reference.',
                ]],
                ['note' => 'The receipt always goes to the address on the payment, and that cannot be '
                         . 'changed from the assistant. If you typed the address wrongly at checkout, a '
                         . 'person has to correct it — raise a ticket and we will.'],
            ],
            'related' => ['paid-but-no-votes'],
        ],
        [
            'slug' => 'card-payment-closed-early',
            'cat'  => 'payments',
            'title' => 'Why can I not pay when voting is still open?',
            'summary' => 'Card payment stops {cutoff} minutes before the ballot does. Free voting runs to the bell.',
            'keywords' => ['cannot pay', 'checkout closed', 'payment closed', 'cutoff', 'too late to pay',
                           'button disabled', 'cannot buy votes'],
            'body' => [
                ['p' => 'This is deliberate, and it is there to protect you. A card payment has to travel '
                      . 'to your bank and back, and that can take several minutes. If we took your money '
                      . 'at the very last moment, the confirmation could arrive after the ballot had '
                      . 'closed — and you would have paid for votes we could not count.'],
                ['p' => 'So card payment for a category stops <strong>{cutoff} minutes</strong> before '
                      . 'voting closes, which gives every payment already under way time to finish.'],
                ['note' => 'Free voting is still open right up to the close. If you were refused at '
                         . 'checkout, go back to the ballot and cast your free vote — it counts exactly '
                         . 'the same towards the community score.'],
            ],
            'related' => ['paid-just-before-close', 'how-free-voting-works'],
        ],
        [
            'slug' => 'paid-just-before-close',
            'cat'  => 'payments',
            'title' => 'I paid just before voting closed — do I still get my votes?',
            'summary' => 'Yes. We judge it on when you paid, not on when your bank told us.',
            'keywords' => ['paid late', 'closed before payment', 'deadline', 'too late', 'missed the deadline',
                           'confirmed after close'],
            'body' => [
                ['p' => '<strong>Yes.</strong> If you started your payment while voting was open, your '
                      . 'votes are delivered even when the confirmation reaches us afterwards — up to '
                      . '{grace} hours late. We use the moment you paid, never the moment your bank got '
                      . 'round to telling us.'],
                ['p' => 'This used to work the other way round and it was wrong. Somebody who paid at '
                      . '23:58 while the ballot was open could lose their money because the confirmation '
                      . 'landed at 00:02. That is fixed, and the fix was applied to everyone it had '
                      . 'already happened to.'],
                ['p' => 'If your votes still are not showing, run your reference through the '
                      . '<a href="/support/assistant">assistant</a> before assuming anything is lost.'],
                ['note' => 'A payment STARTED after voting closed genuinely cannot be counted. In that '
                         . 'case the money goes back automatically — see '
                         . '<a href="/help/refund-when-votes-cannot-count">refunds</a>. You do not have '
                         . 'to ask.'],
            ],
            'related' => ['refund-when-votes-cannot-count', 'card-payment-closed-early', 'paid-but-no-votes'],
        ],
        [
            'slug' => 'refund-when-votes-cannot-count',
            'cat'  => 'payments',
            'title' => 'When do you refund, and do I have to ask?',
            'summary' => 'If we took money for votes we could not count, it goes back by itself.',
            'keywords' => ['refund', 'money back', 'reversal', 'chargeback', 'cancel payment', 'my money'],
            'body' => [
                ['p' => 'There is one case we refund automatically, without anybody asking: your payment '
                      . 'went through, the votes could not be added, and they never will be — normally '
                      . 'because voting in that category had already closed.'],
                ['p' => 'We do not keep money for votes we did not count. You will get an email when it is '
                      . 'on its way. Banks usually take 5–10 working days to show it.'],
                ['p' => 'Everything else — a duplicate charge, a change of mind, the wrong nominee, the '
                      . 'wrong quantity — needs a person to look at it, because those are judgements '
                      . 'rather than arithmetic. Raise a ticket and we will.'],
                ['note' => 'Before asking for a refund, check whether one is already on its way: give your '
                         . 'reference to the <a href="/support/assistant">assistant</a> and it will tell '
                         . 'you. It cannot start one — only a person can — but it can save you the wait.'],
            ],
            'related' => ['paid-just-before-close', 'paid-but-no-votes'],
        ],
        [
            'slug' => 'what-paid-votes-do',
            'cat'  => 'payments',
            'title' => 'What do paid votes actually do?',
            'summary' => 'They show public support. They cannot move a ranking or pick a winner.',
            'keywords' => ['buy votes', 'paid votes', 'price', 'cost', 'how much', 'bulk votes',
                           'do paid votes count', 'is it fair'],
            'body' => [
                ['p' => 'A paid vote adds to a nominee\'s <strong>visible support total</strong>. It is a '
                      . 'way to back someone publicly, and it funds the programme.'],
                ['p' => 'It is deliberately excluded from the Cultural Power Index — the score that '
                      . 'decides rank and winners. That number is built from free, verified community '
                      . 'votes and independent jury scoring only. Money is visible and money is welcome; '
                      . 'money does not buy a result.'],
                ['p' => 'There is also a ceiling on how much paid support one nominee can show relative to '
                      . 'their organic backing — currently {paid_cap_pct}% — so a large purchase cannot '
                      . 'drown out a genuine following.'],
                ['p' => 'And where a race is closest, money is consulted least: a tie is broken on free '
                      . 'votes. See <a href="/help/what-happens-if-two-nominees-tie">what happens if two '
                      . 'nominees finish level</a>.'],
                ['note' => 'One vote costs ₦{price}, with discounts on larger bundles, and a single order '
                         . 'can carry up to {max_qty} votes. Current prices are always on the ballot.'],
            ],
            'related' => ['how-cpi-works', 'how-free-voting-works', 'what-happens-if-two-nominees-tie',
                          'the-community-return'],
        ],

        // ── VOTING ───────────────────────────────────────────────────────────
        [
            'slug' => 'how-free-voting-works',
            'cat'  => 'voting',
            'title' => 'How do I vote, and is it free?',
            'summary' => 'Free, open to anyone, and confirmed by a six-digit code.',
            'keywords' => ['how to vote', 'free vote', 'voting', 'who can vote', 'vote for someone'],
            'body' => [
                ['p' => 'Voting is free and open to anyone, anywhere. You do not need an account.'],
                ['steps' => [
                    'Open the nominee or category you want to back.',
                    'Enter your email address.',
                    'Enter the six-digit code we email you.',
                ]],
                ['p' => 'That records one verified vote. The code is the whole point of the system: it is '
                      . 'how we know one person voted once, rather than one script voting a thousand '
                      . 'times, and it is why the results mean anything.'],
                ['note' => 'One free vote per person per category, per cycle. You can vote in as many '
                         . 'different categories as you like.'],
            ],
            'related' => ['code-did-not-arrive', 'vote-not-showing', 'already-voted'],
        ],
        [
            'slug' => 'code-did-not-arrive',
            'cat'  => 'voting',
            'title' => 'My six-digit code never arrived',
            'summary' => 'Nearly always spam, a typo, or the code from an earlier attempt.',
            'keywords' => ['no code', 'otp', 'code not received', 'verification code', 'email not coming',
                           'did not get code'],
            'body' => [
                ['steps' => [
                    'Check spam and promotions first. This is the answer most of the time.',
                    'Check the address you typed — one wrong character and the code went to somebody else.',
                    'If you requested several codes, only the newest one works. Use the most recent email.',
                    'Wait a minute and request one more. Some providers hold mail briefly.',
                ]],
                ['note' => 'Codes expire. If you left the page and came back much later, request a fresh '
                         . 'one rather than reusing an old email.'],
                ['p' => 'Still nothing after all of that? Try a different email address if you have one — '
                      . 'some corporate and school mail servers reject automated mail outright. If that '
                      . 'also fails, tell support which address you used and we will look at the '
                      . 'delivery log.'],
            ],
            'related' => ['how-free-voting-works', 'vote-not-showing'],
        ],
        [
            'slug' => 'vote-not-showing',
            'cat'  => 'voting',
            'title' => 'I voted but the count did not change',
            'summary' => 'Four causes, in the order they actually happen.',
            'keywords' => ['vote not counted', 'count not changing', 'not reflecting', 'number did not go up',
                           'my vote disappeared'],
            'body' => [
                ['p' => 'Most votes here are free and have no payment attached, so if you did not pay for '
                      . 'anything, this is the page you want rather than the payment ones.'],
                ['steps' => [
                    '<strong>The code was never entered.</strong> A vote only exists once the six-digit '
                        . 'code is submitted. Leaving the page at that step feels exactly like having '
                        . 'voted and records nothing. This is by far the commonest cause.',
                    '<strong>You already voted in that category.</strong> The second attempt is refused on '
                        . 'purpose — one person, one free vote per category.',
                    '<strong>You are looking at a different nominee or category.</strong> A vote counts '
                        . 'where it was cast.',
                    '<strong>A cached total.</strong> Some listing pages hold their numbers for a few '
                        . 'minutes. A nominee\'s own page is always live.',
                ]],
                ['note' => 'Open the nominee\'s own page and refresh it. If your vote is genuinely missing '
                         . 'after that, tell support the nominee and the email you used.'],
            ],
            'related' => ['already-voted', 'code-did-not-arrive', 'paid-but-no-votes'],
        ],
        [
            'slug' => 'already-voted',
            'cat'  => 'voting',
            'title' => 'It says I have already voted',
            'summary' => 'That is the integrity system working, not a fault.',
            'keywords' => ['already voted', 'cannot vote again', 'duplicate vote', 'vote twice',
                           'blocked from voting'],
            'body' => [
                ['p' => 'One person gets one free vote per category, per cycle. If you have already voted '
                      . 'there, the second attempt is refused — and that refusal is exactly what makes the '
                      . 'first vote worth something.'],
                ['p' => 'You can still vote in every other category, and you can still back the same '
                      . 'nominee further by buying votes, which adds to their visible support without '
                      . 'touching the ranking.'],
                ['note' => 'Sharing a device or a home network does not block anyone: the limit is per '
                         . 'verified email address, not per household.'],
            ],
            'related' => ['how-free-voting-works', 'what-paid-votes-do'],
        ],
        [
            'slug' => 'when-does-voting-close',
            'cat'  => 'voting',
            'title' => 'When does voting open and close?',
            'summary' => 'Per category, and always shown on the ballot itself.',
            'keywords' => ['deadline', 'closing date', 'when does voting end', 'still open', 'dates',
                           'when do results come out'],
            'body' => [
                ['p' => 'Each award cycle has its own dates and each category follows its cycle. The live '
                      . 'dates are always on the category and nominee pages — we deliberately do not '
                      . 'repeat them here, because a help page that names a date is a help page that '
                      . 'eventually names the wrong one.'],
                ['p' => 'Three moments matter and they are not the same moment:'],
                ['steps' => [
                    '<strong>Card payment closes</strong> {cutoff} minutes before the ballot, so payments '
                        . 'in progress can finish.',
                    '<strong>Voting closes.</strong> Free voting runs right up to here.',
                    '<strong>Delivery ends</strong> {grace} hours later — a payment started before the '
                        . 'close still delivers its votes if the bank is slow.',
                ]],
            ],
            'related' => ['card-payment-closed-early', 'paid-just-before-close'],
        ],

        // ── NOMINATIONS ──────────────────────────────────────────────────────
        [
            'slug' => 'how-to-nominate',
            'cat'  => 'nominations',
            'title' => 'How do I nominate someone?',
            'summary' => 'Anyone can nominate anyone, including themselves.',
            'keywords' => ['nominate', 'nomination', 'enter someone', 'submit a nomination', 'apply'],
            'body' => [
                ['p' => 'Use <a href="/nominate">Nominate</a>, tell us who they are, which category fits, '
                      . 'and — the part that actually matters — <em>why</em>.'],
                ['p' => 'The reason is not a formality. It is what the review panel reads, and a specific '
                      . 'sentence about something real beats a paragraph of praise every time. "Ran a free '
                      . 'coding class for 40 girls in Alimosho every Saturday for two years" is a '
                      . 'nomination. "Very hardworking and inspirational" is not.'],
                ['note' => 'You can nominate yourself. It carries no penalty and no advantage.'],
            ],
            'related' => ['nomination-what-next', 'nomination-rejected'],
        ],
        [
            'slug' => 'nomination-what-next',
            'cat'  => 'nominations',
            'title' => 'I submitted a nomination — what happens now?',
            'summary' => 'A person reviews it. You are told either way.',
            'keywords' => ['nomination status', 'is my nomination approved', 'pending', 'how long',
                           'waiting for approval'],
            'body' => [
                ['p' => 'Every nomination is reviewed by a person before it appears publicly. We check the '
                      . 'nominee is real, that the category fits, and that the claim in your reason is '
                      . 'something that can stand up.'],
                ['p' => 'You are emailed the decision. If it is approved, the nominee appears on the '
                      . 'ballot and can be voted for.'],
                ['note' => 'Signed in? Ask the <a href="/support/assistant">assistant</a> about your own '
                         . 'nominations and it will tell you where each one stands, including the reason '
                         . 'for any decision.'],
            ],
            'related' => ['how-to-nominate', 'nomination-rejected'],
        ],
        [
            'slug' => 'nomination-rejected',
            'cat'  => 'nominations',
            'title' => 'My nomination was rejected',
            'summary' => 'Usually fixable. Rejection is about the entry, not about the person.',
            'keywords' => ['rejected', 'declined', 'not approved', 'turned down', 'why was my nomination rejected'],
            'body' => [
                ['p' => 'The reason is in the email, and it is worth reading closely, because most '
                      . 'rejections are about the <em>entry</em> rather than the nominee. The commonest '
                      . 'are: the wrong category, a duplicate of an existing nominee, or a reason with '
                      . 'nothing verifiable in it.'],
                ['p' => 'All three can be resubmitted. A duplicate is not a rejection of the person at '
                      . 'all — they are already on the ballot, and you can go and vote for them.'],
                ['note' => 'If you think the decision itself was wrong, you can appeal it through '
                         . '<a href="/support">Support &amp; appeals</a>. Appeals are read by someone who '
                         . 'did not make the original decision.'],
            ],
            'related' => ['how-to-nominate', 'dispute-a-result'],
        ],

        // ── RESULTS & INTEGRITY ──────────────────────────────────────────────
        [
            'slug' => 'how-cpi-works',
            'cat'  => 'results',
            'title' => 'How is the Cultural Power Index calculated?',
            'summary' => 'Community votes, jury scoring and documented impact — and no money.',
            'keywords' => ['cpi', 'score', 'ranking', 'how is the winner decided', 'points', 'rank'],
            'body' => [
                ['p' => 'The CPI blends verified community votes, independent jury scoring and documented '
                      . 'impact into a single score, recomputed on a fixed schedule each cycle.'],
                ['p' => 'The community component is <strong>cohort-normalised</strong>, which is a dry way '
                      . 'of saying a nominee in a small category is not punished for being in a small '
                      . 'category. Raw vote counts across categories of very different sizes would '
                      . 'otherwise measure audience size rather than merit.'],
                ['p' => 'Paid votes are excluded entirely. So are jury members\' own votes in categories '
                      . 'they judge.'],
                ['p' => 'The split is <strong>{community_pct}% community, {judge_pct}% judges</strong>, '
                      . 'and it is set per cycle rather than fixed forever. Going deeper: '
                      . '<a href="/help/why-a-small-category-is-not-a-disadvantage">why a small category '
                      . 'is not a disadvantage</a>, '
                      . '<a href="/help/what-the-judges-actually-score">what the judges actually score</a>, '
                      . 'and <a href="/help/why-the-leader-may-not-be-eligible-to-win">why the vote leader '
                      . 'may not be eligible to win</a>.'],
                ['note' => 'The full method, the weights and the audit trail are on the '
                         . '<a href="/integrity">integrity page</a>. Every recomputation is logged and can '
                         . 'be replayed from source.'],
            ],
            'related' => ['what-paid-votes-do', 'dispute-a-result',
                          'why-a-small-category-is-not-a-disadvantage', 'how-results-are-sealed'],
        ],
        [
            'slug' => 'dispute-a-result',
            'cat'  => 'results',
            'title' => 'I think a count or a ranking is wrong',
            'summary' => 'Ask for an audit. We will replay the arithmetic from source.',
            'keywords' => ['wrong count', 'dispute', 'appeal', 'unfair', 'cheating', 'rigged',
                           'votes disappeared', 'someone is buying votes'],
            'body' => [
                ['p' => 'Ask, and we will check. Every recomputation is logged, so a count can be replayed '
                      . 'from the underlying votes and the working shown to you.'],
                ['p' => 'Two things worth knowing before you do, because they explain most reports:'],
                ['steps' => [
                    'A total can go <strong>down</strong>. Votes found to be fraudulent are removed, and '
                        . 'a refunded paid vote is taken back with the money. A falling number is usually '
                        . 'the integrity system working.',
                    'Visible support and ranking are different numbers. A nominee can have more visible '
                        . 'support and rank lower, because paid votes are excluded from the score.',
                ]],
                ['note' => 'Raise it through <a href="/support">Support &amp; appeals</a> with the nominee '
                         . 'and category. Appeals go to someone independent of the original decision.'],
                ['p' => 'Two more things make an audit possible rather than merely promised: standings are '
                      . '<a href="/help/how-results-are-sealed">sealed into a chain</a> that cannot be '
                      . 'quietly edited, and every vote carries the risk assessment it was given when '
                      . '<a href="/help/how-we-spot-a-vote-that-is-not-real">it was cast</a>.'],
            ],
            'related' => ['how-cpi-works', 'what-paid-votes-do', 'how-results-are-sealed',
                          'how-we-spot-a-vote-that-is-not-real'],
        ],
        [
            'slug' => 'report-a-profile',
            'cat'  => 'results',
            'title' => 'How do I report a nominee or a profile?',
            'summary' => 'Confidentially, and you do not need proof — just what you saw.',
            'keywords' => ['report', 'fake profile', 'impersonation', 'abuse', 'fraud', 'complaint',
                           'someone stole my identity'],
            'body' => [
                ['p' => 'Report it through <a href="/support">Support &amp; appeals</a>. Tell us the '
                      . 'profile and what you saw. You do not need to prove anything — investigating is '
                      . 'our job, not yours.'],
                ['p' => 'Reports are confidential. The person reported is not told who raised it.'],
                ['note' => 'If someone has created a profile pretending to be <em>you</em>, say so '
                         . 'explicitly and we will treat it as urgent.'],
            ],
            'related' => ['dispute-a-result', 'how-we-spot-a-vote-that-is-not-real'],
        ],

        // ── RESULTS & INTEGRITY: the deep dives ──────────────────────────────
        //
        // These nine are the /integrity page taken apart. That page had grown into
        // a single long scroll that answered everything and was read by nobody:
        // somebody who wants to know why their small category is not a handicap had
        // to scan past the judging rubric, the fraud thresholds and the privacy
        // policy to find four sentences about it.
        //
        // Splitting them out is not tidying. It makes each claim ADDRESSABLE — the
        // assistant can quote "why a small category is not a disadvantage" at the
        // person who asked, support can paste one URL into a ticket, and the claim
        // gets a title somebody can disagree with. A promise buried in paragraph
        // fourteen of a methodology page is a promise nobody can hold us to.
        //
        // /integrity still says all of it, in summary, and links here for the rest.
        [
            'slug' => 'why-a-small-category-is-not-a-disadvantage',
            'cat'  => 'results',
            'title' => 'Why a small category is not a disadvantage',
            'summary' => 'Vote counts are compared inside a category, never across them.',
            'keywords' => ['normalised', 'normalized', 'small category', 'fewer votes', 'category size',
                           'compared to other categories', 'cohort', 'unfair category', 'big category',
                           'my category has fewer people'],
            'body' => [
                ['p' => 'A nominee in a category with two hundred voters is not competing with a nominee '
                      . 'in a category with twenty thousand. The community part of the score is '
                      . '<strong>normalised inside each category</strong>: a nominee is measured against '
                      . 'the strongest vote count in their own category, and that ratio — not the raw '
                      . 'number — is what enters the Cultural Power Index.'],
                ['p' => 'Without that step the index would be measuring audience size. A musician will '
                      . 'always out-poll a rural school administrator, and a raw comparison would say the '
                      . 'musician is more culturally significant purely because more people are online in '
                      . 'their direction. That is a fact about the internet, not about the work.'],
                ['p' => 'So the community component asks a narrower and more answerable question: '
                      . '<em>of the people who came to this category, how many chose you?</em> Being the '
                      . 'clearest choice in a small field scores exactly as well as being the clearest '
                      . 'choice in a large one.'],
                ['note' => 'This is also why a nominee\'s rank can move without their own total changing. '
                         . 'If somebody else in the category surges, the top of the category moves and '
                         . 'everybody\'s ratio is recomputed against it.'],
            ],
            'related' => ['how-cpi-works', 'why-the-leader-may-not-be-eligible-to-win', 'dispute-a-result'],
        ],
        [
            'slug' => 'why-the-leader-may-not-be-eligible-to-win',
            'cat'  => 'results',
            'title' => 'Why the nominee with the most votes may not be eligible to win',
            'summary' => 'Winning needs {min_judges} complete judge scorecards. Votes alone are not enough.',
            'keywords' => ['not eligible', 'ineligible', 'quorum', 'how many judges', 'judge panel',
                           'scorecard', 'leading but not winning', 'top of the leaderboard',
                           'why did they win with fewer votes', 'jury'],
            'body' => [
                ['p' => 'Leading the public vote makes a nominee the front-runner. It does not, on its '
                      . 'own, make them winner-eligible. A nominee must also have been scored by at least '
                      . '<strong>{min_judges} judges</strong>, and those scorecards must be '
                      . '<strong>complete</strong> — every criterion filled in, not a partial card left '
                      . 'open in a browser tab.'],
                ['p' => 'The rule exists because a single judge is a single opinion, and a half-finished '
                      . 'card is an opinion that was never actually formed. Awarding a title off either '
                      . 'one would make the jury component decorative.'],
                ['steps' => [
                    'A judge who has any connection to a nominee recuses themselves from that nominee, '
                        . 'and their own votes in a category they judge are excluded.',
                    'Only complete scorecards are averaged. An abandoned card counts as nothing at all — '
                        . 'not as a zero, which would be a silent penalty.',
                    'A nominee below the quorum is still ranked and still shown. They are simply not '
                        . 'eligible to be declared the winner of that category.',
                ]],
                ['note' => 'If a category ends with nobody at quorum, the award is not given. An empty '
                         . 'result is more honest than one nobody scored.'],
            ],
            'related' => ['what-the-judges-actually-score', 'how-cpi-works', 'what-happens-if-two-nominees-tie'],
        ],
        [
            'slug' => 'what-the-judges-actually-score',
            'cat'  => 'results',
            'title' => 'What the judges actually score you on',
            'summary' => 'Four criteria, equally weighted, each scored out of ten.',
            'keywords' => ['criteria', 'rubric', 'impact', 'originality', 'reach', 'what do judges look for',
                           'judging', 'how are we judged', 'scored out of'],
            'body' => [
                ['p' => 'Each judge scores each shortlisted nominee from 0 to 10 on four dimensions. The '
                      . 'four carry <strong>equal weight</strong> — there is no hidden multiplier making '
                      . 'one of them decide the card.'],
                ['steps' => [
                    '<strong>Impact</strong> — the measurable difference made for a community, an '
                        . 'industry or the continent. What is different because this person did the work?',
                    '<strong>Originality</strong> — inventiveness. Has something genuinely new been '
                        . 'introduced, or is this an existing thing done competently?',
                    '<strong>Reach</strong> — the breadth of influence: local, regional, continental, '
                        . 'global. Reach is not follower count; a policy that changed one state\'s '
                        . 'curriculum reaches further than a viral post.',
                    '<strong>Integrity</strong> — consistency of values and accountability under '
                        . 'pressure, including how the nominee behaves when it costs them something.',
                ]],
                ['p' => 'The average of the four is that judge\'s score for that nominee. The average '
                      . 'across judges becomes the jury component, which carries {judge_pct}% of the '
                      . 'final index.'],
                ['note' => 'Judges see the nominee\'s dossier, not their vote count. The public tally is '
                         . 'deliberately kept out of the scoring screen so it cannot anchor the score.'],
            ],
            'related' => ['why-the-leader-may-not-be-eligible-to-win', 'how-cpi-works'],
        ],
        [
            'slug' => 'how-we-spot-a-vote-that-is-not-real',
            'cat'  => 'results',
            'title' => 'How we decide a vote was not cast by a real person',
            'summary' => 'Every vote is scored for risk before it is recorded — and some are blocked outright.',
            'keywords' => ['fraud', 'bot', 'fake votes', 'blocked', 'vote rejected', 'risk score', 'vpn',
                           'multiple accounts', 'disposable email', 'temporary email', 'vote farm',
                           'why was my vote blocked', 'suspicious'],
            'body' => [
                ['p' => 'Every vote requires a one-time code sent to a working email address, and every '
                      . 'attempt is given a <strong>risk score from 0 to 100</strong> before anything is '
                      . 'written down. Low risk passes silently. Middling risk is recorded but flagged for '
                      . 'a person to look at. High risk is refused before the vote exists.'],
                ['p' => 'The score is built from signals that are cheap for us to read and expensive for '
                      . 'a vote farm to fake all at once: how many votes have come from this network in '
                      . 'the last hour, whether the device has been seen before, how the code was '
                      . 'requested, and how the session behaved on the way to the button.'],
                ['steps' => [
                    'Known disposable-mail domains are refused at the moment the code is requested, '
                        . 'before any database write happens at all.',
                    'One verified identity gets one vote per category. The address is stored only as a '
                        . 'SHA-256 hash, so we can tell that two votes came from the same person without '
                        . 'holding the person.',
                    'Votes later found to be fraudulent are removed from the count, which is why a total '
                        . 'can legitimately go <em>down</em>.',
                ]],
                ['note' => 'Being flagged is not an accusation. A shared office network or a campus wifi '
                         . 'produces exactly the pattern a vote farm does, which is why a flag sends the '
                         . 'vote to a human rather than to the bin. If yours was refused and you are a '
                         . 'real person, tell us through <a href="/support">support</a> and we will look.'],
            ],
            'related' => ['already-voted', 'dispute-a-result', 'vote-not-showing'],
        ],
        [
            'slug' => 'what-happens-if-two-nominees-tie',
            'cat'  => 'results',
            'title' => 'What happens if two nominees finish level',
            'summary' => 'The tiebreak is organic votes — never money.',
            'keywords' => ['tie', 'tied', 'same score', 'draw', 'dead heat', 'tiebreak', 'tie breaker',
                           'level', 'both had the same', 'joint winner'],
            'body' => [
                ['p' => 'When two nominees finish a category on the same index score, the tie is broken by '
                      . '<strong>organic votes</strong> — the free, code-verified ones. Contributions are '
                      . 'not consulted, so the closest possible race is decided by people rather than by '
                      . 'whoever was willing to spend more at the end of it.'],
                ['p' => 'This is worth stating plainly because the tempting alternative is the wrong one. '
                      . 'A tiebreak on the public tally would have quietly reintroduced pay-to-win at the '
                      . 'exact moment it mattered most: when everything else was equal, money would have '
                      . 'been the deciding factor.'],
                ['note' => 'If two nominees are level on the index <em>and</em> level on organic votes, '
                         . 'the result is recorded as a dead heat and escalated to the integrity team '
                         . 'rather than settled by an arbitrary rule such as who registered first.'],
            ],
            'related' => ['what-paid-votes-do', 'how-cpi-works', 'why-the-leader-may-not-be-eligible-to-win'],
        ],
        [
            'slug' => 'how-results-are-sealed',
            'cat'  => 'results',
            'title' => 'How you can tell a result was not edited afterwards',
            'summary' => 'Each standing is sealed into a chain, and every link is checked daily.',
            'keywords' => ['tamper', 'edited results', 'audit', 'proof', 'hash', 'snapshot', 'sealed',
                           'can you change the results', 'trust the results', 'evidence', 'chain'],
            'body' => [
                ['p' => 'Every time the standings are computed, the result is written down and '
                      . '<strong>sealed</strong>: the record carries a fingerprint of itself and of the '
                      . 'record before it. Each seal therefore depends on every seal that came before, all '
                      . 'the way back to the first.'],
                ['p' => 'The consequence is the useful part. Editing an old standing — nudging one number '
                      . 'in a table months later — breaks its fingerprint, and because the next record '
                      . 'contains that fingerprint, it breaks every record after it too. There is no quiet '
                      . 'edit available. There is only an edit that announces itself.'],
                ['steps' => [
                    'The chain is re-verified automatically every day, link by link.',
                    'A break is not a warning in a log somebody might read. It is raised as a failure, to '
                        . 'people, with the position of the broken link.',
                    'The database refuses to record two records claiming the same predecessor, so the '
                        . 'chain cannot be forked into a convenient second history.',
                ]],
                ['note' => 'This protects you from us as much as from anybody else. A platform that can '
                         . 'silently rewrite its own results has to be taken on trust; one that cannot '
                         . 'does not need to be.'],
            ],
            'related' => ['dispute-a-result', 'how-cpi-works', 'the-stages-of-an-award-cycle'],
        ],
        [
            'slug' => 'votes-we-could-not-deliver',
            'cat'  => 'results',
            'title' => 'Votes that never reached you, and how they are given back',
            'summary' => 'When our own records show a code never got out, the vote is restored — under review, and disclosed.',
            // Deliberately NOT keyed on "code did not arrive" phrasings. Somebody
            // typing that is mid-vote and wants their code resent; this article is
            // about what happens to that vote afterwards, and answering the first
            // question with the second would be technically related and useless.
            'keywords' => ['recovered votes', 'restored votes', 'votes added later', 'recovery',
                           'delivery failed', 'missed my vote', 'vote given back', 'vote reinstated'],
            'body' => [
                ['p' => 'Sometimes a voting code fails to leave our system — a mail provider rejects it, a '
                      . 'queue stalls, a domain bounces. The person did everything right and never got the '
                      . 'chance to finish. We record that failure at the moment it happens, so those cases '
                      . 'are evidence in our own logs rather than something anybody has to claim.'],
                ['p' => 'After voting closes, those specific failures can be reviewed and the votes '
                      . 'restored. Three rules keep that from becoming a back door:'],
                ['steps' => [
                    'Only codes our system recorded as <strong>undelivered</strong> are eligible. Nobody '
                        . 'can assert a vote that has no failure behind it.',
                    'A restoration is proposed by one person and approved by a <strong>different</strong> '
                        . 'one. The system refuses to let the same account do both.',
                    'The size is capped, and a suspicious cluster — many failures from one network — is '
                        . 'excluded rather than restored.',
                ]],
                ['note' => 'Restored votes are disclosed on the nominee\'s page, with the count and the '
                         . 'reason. A correction nobody can see is indistinguishable from a thumb on the '
                         . 'scale.'],
            ],
            'related' => ['code-did-not-arrive', 'dispute-a-result', 'how-results-are-sealed'],
        ],
        [
            'slug' => 'the-community-return',
            'cat'  => 'results',
            'title' => 'The community return: a share of what your supporters raised',
            'summary' => 'A nominee keeps a share of the contributions made in their name — win or lose.',
            'keywords' => ['community return', 'do nominees get paid', 'my share', 'earnings', 'payout',
                           'withdraw', 'money back to nominee', 'supporters raised', 'what do i earn',
                           'nominee earnings'],
            'body' => [
                ['p' => 'When supporters contribute in a nominee\'s name, a share of that money is set '
                      . 'aside <strong>for the nominee</strong>. It is not a prize and it does not depend '
                      . 'on winning — they raised it either way, and a nominee who mobilised a community '
                      . 'and came second mobilised a community.'],
                ['p' => 'Two conditions shape it. A nominee starts earning only once they have reached '
                      . '<strong>{return_supporters} distinct supporters</strong>, and from that point '
                      . 'the share is <strong>{return_pct}%</strong> of what is contributed in their name '
                      . 'afterwards. Crossing that line does not reach backwards: earlier contributions '
                      . 'stay unshared.'],
                ['p' => 'The threshold counts <em>people</em>, not votes, and that distinction is the '
                      . 'whole safeguard. One person can buy fifty votes in a single order; one person '
                      . 'cannot be fifty verified supporters.'],
                ['steps' => [
                    'Every entry is written to a ledger that only ever grows. Nothing is overwritten, so '
                        . 'any figure can be explained line by line.',
                    'If a contribution is refunded or charged back, the share accrued on it is reversed — '
                        . 'and the reversal stays on the record beside the original.',
                    'Nothing is payable until that cycle has ended and its results have been announced.',
                ]],
                ['note' => 'The share is a per-cycle setting and can be zero. When it is set to '
                         . '{return_pct}%, as it is now, that is exactly what accrues — the ledger simply '
                         . 'stays empty rather than quietly promising something.'],
            ],
            'related' => ['what-paid-votes-do', 'refund-when-votes-cannot-count', 'how-cpi-works'],
        ],
        [
            'slug' => 'the-stages-of-an-award-cycle',
            'cat'  => 'results',
            'title' => 'The stages of an award cycle, and what freezes at each one',
            'summary' => 'Six stages. Each one closes something so the next cannot be argued with.',
            'keywords' => ['stages', 'timeline', 'when are results announced', 'shortlist', 'frozen',
                           'what happens next', 'cycle', 'phases', 'schedule', 'archived'],
            'body' => [
                ['p' => 'A programme moves through six stages in one direction. The stage is not a label '
                      . 'on a page — the system enforces it, and each transition closes something '
                      . 'permanently.'],
                ['steps' => [
                    '<strong>Nominated</strong> — anyone can enter someone. Entries queue for review; '
                        . 'nothing is public yet.',
                    '<strong>Verified</strong> — the team checks eligibility, accuracy and completeness. '
                        . 'Rejections carry a reason.',
                    '<strong>Shortlisted</strong> — approved nominees go public and <em>the list is '
                        . 'frozen</em>. Nobody can be added to a category once people have started voting '
                        . 'in it.',
                    '<strong>Voted</strong> — code-verified, risk-scored votes are collected. Card '
                        . 'contribution closes slightly earlier than free voting so payments in flight '
                        . 'have time to land.',
                    '<strong>Judged</strong> — the panel scores the shortlist. The vote tally is not shown '
                        . 'on the scoring screen.',
                    '<strong>Sealed</strong> — a signed snapshot is taken, results are computed from it, '
                        . 'and the cycle is archived. From here the numbers are evidence, not a live feed.',
                ]],
                ['note' => 'Only once a cycle reaches its results does anything become payable or final. '
                         . 'Before that, every figure on the site is a running total and is described as '
                         . 'one.'],
            ],
            'related' => ['when-does-voting-close', 'how-results-are-sealed', 'card-payment-closed-early'],
        ],

        // ── ACCOUNT & PROFILE ────────────────────────────────────────────────
        [
            'slug' => 'do-i-need-an-account',
            'cat'  => 'account',
            'title' => 'Do I need an account?',
            'summary' => 'Not to vote, nominate or pay. An account gets you your own history.',
            'keywords' => ['account', 'sign up', 'register', 'do i need to log in', 'login'],
            'body' => [
                ['p' => 'No. Voting, nominating and buying votes all work without one — we ask for an '
                      . 'email so we can verify you and send a receipt, and that is all.'],
                ['p' => 'An account is worth having if you want your own record: your payments, your '
                      . 'votes, your nominations and your support tickets in one place. The assistant can '
                      . 'read those for you once you are signed in, which means it can answer "where is '
                      . 'my payment" without you finding a reference first.'],
                ['note' => 'Raising a support ticket does need an account, so that we have somewhere to '
                         . 'reply to. The payment repair tools do not.'],
            ],
            'related' => ['cannot-sign-in', 'claim-my-profile'],
        ],
        [
            'slug' => 'cannot-sign-in',
            'cat'  => 'account',
            'title' => 'I cannot sign in',
            'summary' => 'Usually the wrong address rather than the wrong password.',
            'keywords' => ['cannot log in', 'password', 'locked out', 'forgot password', 'access',
                           'sign in not working'],
            'body' => [
                ['steps' => [
                    'Check which email you are using. People very often have an account on one address '
                        . 'and pay with another.',
                    'Use the reset link on the sign-in page, and check spam for the email.',
                    'If the reset mail never comes, that address may not have an account at all — try '
                        . 'signing up instead.',
                ]],
                ['note' => 'Still stuck? Use <a href="/support">Support &amp; appeals</a> and choose '
                         . '"Recover access". Include the address you expect the account to be on.'],
            ],
            'related' => ['do-i-need-an-account'],
        ],
        [
            'slug' => 'claim-my-profile',
            'cat'  => 'account',
            'title' => 'Someone nominated me — can I manage my own profile?',
            'summary' => 'Yes. Claiming lets you correct your own details and add your own story.',
            'keywords' => ['claim profile', 'my profile', 'edit my details', 'i was nominated',
                           'wrong information about me', 'update photo'],
            'body' => [
                ['p' => 'A nomination is written <em>about</em> you by somebody else, so it may be thin, '
                      . 'out of date, or slightly wrong. Claiming your profile lets you fix that: your '
                      . 'own photo, your own description, your own links.'],
                ['p' => 'What the person who nominated you wrote stays visible and stays attributed to '
                      . 'them. You are adding your side, not replacing theirs — a profile where the '
                      . 'nominee can quietly delete what was said about them is not a record anyone '
                      . 'should trust.'],
                ['note' => 'Claiming is being rolled out per programme. If you cannot see the option on '
                         . 'your profile yet, contact support with the profile link and we will help '
                         . 'directly.'],
            ],
            'related' => ['do-i-need-an-account', 'remove-my-profile'],
        ],
        [
            'slug' => 'remove-my-profile',
            'cat'  => 'account',
            'title' => 'I do not want to be listed — take my profile down',
            'summary' => 'We will. Being nominated is not something you consented to.',
            'keywords' => ['remove me', 'delete profile', 'take it down', 'withdraw', 'opt out',
                           'i did not agree to this'],
            'body' => [
                ['p' => 'Somebody nominating you is not you agreeing to be listed, and we treat it that '
                      . 'way. Ask and we will withdraw the profile.'],
                ['steps' => [
                    'Contact <a href="/support">Support</a> from any address, with the profile link.',
                    'We verify you are the person on the profile — this protects you, since otherwise '
                        . 'anyone could remove anyone.',
                    'The profile comes down and stops appearing on ballots and listings.',
                ]],
                ['note' => 'This is separate from deleting personal data we hold about you, which you can '
                         . 'also request — see <a href="/help/what-data-do-you-keep">what data we keep</a>.'],
            ],
            'related' => ['claim-my-profile', 'what-data-do-you-keep'],
        ],

        // ── PRIVACY ──────────────────────────────────────────────────────────
        [
            'slug' => 'what-data-do-you-keep',
            'cat'  => 'privacy',
            'title' => 'What do you do with my email address?',
            'summary' => 'Voting emails are hashed, not stored. Payment emails are, because of receipts.',
            'keywords' => ['privacy', 'my data', 'email', 'gdpr', 'ndpa', 'delete my data', 'personal information'],
            'body' => [
                ['p' => 'When you <strong>vote</strong>, your email is turned into a one-way hash and the '
                      . 'address itself is not kept. That hash lets us tell that one person voted once '
                      . 'without us holding a list of who voted for whom. It cannot be turned back into '
                      . 'your address.'],
                ['p' => 'When you <strong>pay</strong>, we do keep the address, because a receipt has to '
                      . 'go somewhere and a refund has to be traceable to an order.'],
                ['p' => 'Your name only ever appears publicly if you typed it into the optional name field '
                      . 'at checkout — the field that says so. Leave it blank and your support is '
                      . 'anonymous.'],
                ['note' => 'You can ask what we hold about you and ask for it to be deleted. Full detail '
                         . 'is in the <a href="/privacy">privacy policy</a>.'],
            ],
            'related' => ['remove-my-profile', 'is-my-card-safe'],
        ],
        [
            'slug' => 'is-my-card-safe',
            'cat'  => 'privacy',
            'title' => 'Is my card safe? Do you store card details?',
            'summary' => 'We never see them. Your card goes straight to the payment provider.',
            'keywords' => ['card details', 'secure', 'safe', 'pci', 'card stored', 'is it safe to pay'],
            'body' => [
                ['p' => 'We never receive your card number. Payment happens entirely on the payment '
                      . 'provider\'s own page — that is why checkout takes you away from our site and back '
                      . 'again — and we are only ever told whether it succeeded and for how much.'],
                ['p' => 'Nothing that could be used to charge you is stored here.'],
                ['note' => 'If a page ever asks for your card details while still on our domain, do not '
                         . 'enter them, and please tell us immediately.'],
            ],
            'related' => ['what-data-do-you-keep', 'paid-but-no-votes'],
        ],
        ];
    }

    // ── lookup ───────────────────────────────────────────────────────────────

    /** One article by slug, with live values resolved, or null. */
    public static function bySlug(string $slug): ?array
    {
        foreach (self::articles() as $a) {
            if ($a['slug'] === $slug) return self::resolve($a);
        }
        return null;
    }

    /** Every article, resolved, in corpus order. */
    public static function all(): array
    {
        return array_map([self::class, 'resolve'], self::articles());
    }

    /** Articles in one category, resolved. */
    public static function inCategory(string $cat): array
    {
        return array_values(array_filter(self::all(), static fn(array $a) => $a['cat'] === $cat));
    }

    /**
     * Search, scored so the best answer is first rather than the earliest.
     *
     * ── WHY THE WEIGHTS ARE SHAPED LIKE THIS ─────────────────────────────────
     *
     * `keywords` outrank the title, which outranks the body. That is the opposite
     * of most naive search, and it is on purpose: the keywords are the phrases
     * REAL people type, and several of them appear nowhere in the article they
     * point at. Somebody searching "debited" must land on "I paid but my votes
     * have not appeared", an article that never uses the word.
     *
     * Body matches count least because a passing mention of "refund" in an article
     * about nominations should never beat the article that is actually about
     * refunds.
     *
     * @return list<array<string,mixed>> best first
     */
    public static function search(string $query, int $limit = 8): array
    {
        $q = mb_strtolower(trim($query));
        if ($q === '') return [];

        // Words, not the raw string: "my votes are not showing" must still find an
        // article keyed on "not showing".
        $words = array_values(array_filter(
            preg_split('/[^a-z0-9]+/', $q) ?: [],
            static fn(string $w) => mb_strlen($w) > 2
        ));

        $hits = [];
        foreach (self::all() as $a) {
            $score = 0;
            $title = mb_strtolower((string) $a['title']);
            $body  = mb_strtolower(self::plainText($a));
            $keys  = array_map('mb_strtolower', (array) ($a['keywords'] ?? []));

            foreach ($keys as $k) {
                if ($k === $q)                  $score += 60;   // typed it exactly
                elseif (str_contains($q, $k))   $score += 30;   // their sentence contains it
                elseif (str_contains($k, $q))   $score += 20;   // they typed part of it
            }
            if (str_contains($title, $q)) $score += 25;

            foreach ($words as $w) {
                foreach ($keys as $k) { if (str_contains($k, $w)) { $score += 6; break; } }
                if (str_contains($title, $w)) $score += 4;
                if (str_contains($body, $w))  $score += 1;
            }

            if ($score > 0) $hits[] = ['score' => $score] + $a;
        }

        usort($hits, static fn(array $x, array $y) => $y['score'] <=> $x['score']);
        return array_slice($hits, 0, max(1, $limit));
    }

    /**
     * The best written answer to a question, composed as a chat reply.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * THE FLOOR UNDER EVERY ASSISTANT
     * ══════════════════════════════════════════════════════════════════════════
     *
     * Both assistants need an answer for the case where no model can be reached:
     * no provider configured, the daily AI budget spent, or this visitor past
     * their allowance. Those conditions correlate with an incident — everybody
     * arrives at once and the budget goes first — which is exactly the moment a
     * support widget must not turn into a "please email us" box.
     *
     * It lives here, once, because the two front doors had drifted: Gee quoted the
     * article and the support DESK returned "I cannot reach my assistant service
     * right now" — so the person who had navigated all the way to the support page,
     * the most stuck of the two, got the worse answer.
     *
     * ── WHY THREE BLOCKS ─────────────────────────────────────────────────────
     *
     * Title, first paragraph, then the link and the offer of a person. An earlier
     * version also quoted the summary and named the runner-up article; measured in
     * a 390px chat sheet that filled the entire 284px scroll window on its own,
     * pushing the handoff button and the preview cards out of sight — and the
     * summary was being said twice, because the card beside it carries the summary
     * already.
     *
     * Returns null when nothing matches, so the caller can say something honest
     * rather than quoting an irrelevant article at somebody.
     */
    public static function writtenAnswer(string $question): ?string
    {
        $hits = self::search($question, 1);
        if ($hits === []) return null;

        $top   = $hits[0];
        $lines = ['**' . (string) $top['title'] . '**'];

        // The first substantive paragraph. The article page has the steps and the
        // caveats; this is enough to know whether to go and read them.
        foreach ((array) ($top['body'] ?? []) as $block) {
            if (!empty($block['p'])) { $lines[] = (string) $block['p']; break; }
        }
        // Worded so a linkified label reads as part of the sentence: Gee's widget
        // renders an article URL as "the Help Centre answer", so "Full answer:
        // /help/x" would have come out as "Full answer: the Help Centre answer".
        $lines[] = 'Read it here: ' . self::url((string) $top['slug'])
                 . ' — if that is not your case, say so and I will pass it to the team at /support.';

        return implode("\n\n", $lines);
    }

    /**
     * The articles worth showing beside an assistant's reply, as preview cards.
     *
     * ── WHY A URL INSIDE A SENTENCE IS NOT ENOUGH ────────────────────────────
     *
     * An assistant that cites an article writes the link into its prose. In a chat
     * bubble that is a bare blue string mid-paragraph: no title, no sense of what
     * is behind it, nothing to weigh against the effort of leaving the
     * conversation. People do not click it, so the answer we vetted goes unread
     * while they keep typing at the robot.
     *
     * A card with a title, a one-line summary and its category is a decision
     * somebody can make at a glance. Same destination, several times the traffic.
     *
     * ── TWO SOURCES, AND THE ORDER MATTERS ───────────────────────────────────
     *
     *   CITED     slugs the model actually read this turn. These belong to the
     *             answer, so they lead and are flagged as used.
     *   SEARCHED  the USER'S OWN WORDS, independently of the model.
     *
     * The second exists because the model does not always reach for the tool, and
     * when it does not, a perfectly good written answer stays invisible. Searching
     * the question directly costs nothing and does not depend on the model having
     * made a good decision — which is a thing a UI should never depend on.
     *
     * This lives here rather than in a controller because there are now two
     * assistants rendering these cards — the support desk and Gee — and a second
     * copy would drift. Differently ranked cards under the same question read as a
     * bug in whichever one the person saw second.
     *
     * @param list<string> $cited slugs the model read, best first
     * @param bool $lastResort offer the three commonest articles when nothing
     *        matched. True at the support desk, where somebody arrived stuck and a
     *        blank strip is a failure; false for Gee's ordinary browsing chatter,
     *        where "how do I nominate" must not sprout a refunds card.
     * @return list<array<string,mixed>>
     */
    public static function previews(string $message, array $cited = [], int $limit = 3,
                                    bool $lastResort = false): array
    {
        $picked = [];
        foreach ($cited as $slug) {
            $slug = trim((string) $slug);
            if ($slug !== '') $picked[$slug] = true;
        }
        foreach (self::search($message, $limit) as $hit) {
            $picked[(string) $hit['slug']] ??= true;
        }

        // Observed at the desk: a user typed "send an article I can read" and got
        // nothing at all. Nothing was wrong with the corpus — that sentence has no
        // topic in it, so it matches no keyword, so the search correctly returns
        // empty, and the person asking most explicitly for something to read got
        // the least. These three are the commonest reasons anybody is here.
        if ($picked === [] && $lastResort) {
            foreach (['paid-but-no-votes', 'vote-not-showing', 'code-did-not-arrive'] as $s) {
                $picked[$s] = true;
            }
        }

        $out = [];
        foreach (array_keys($picked) as $slug) {
            $a = self::bySlug((string) $slug);
            if ($a === null) continue;
            $cat = self::CATEGORIES[$a['cat']] ?? ['title' => '', 'tint' => '#eef2ef', 'fg' => '#39464a'];
            $out[] = [
                'slug'     => (string) $a['slug'],
                'title'    => (string) $a['title'],
                'summary'  => (string) $a['summary'],
                'url'      => self::url((string) $a['slug']),
                'category' => (string) $cat['title'],
                'tint'     => (string) $cat['tint'],
                'fg'       => (string) $cat['fg'],
                // Lets the UI say "I used this" rather than "you might also want",
                // which are different claims and should not look identical.
                'cited'    => in_array((string) $a['slug'], $cited, true),
            ];
            if (count($out) >= max(1, $limit)) break;
        }
        return $out;
    }

    /** An article's prose with markup stripped — for searching and for the model. */
    public static function plainText(array $a): string
    {
        $out = [(string) ($a['summary'] ?? '')];
        foreach ((array) ($a['body'] ?? []) as $block) {
            foreach (['p', 'note'] as $k) {
                if (isset($block[$k])) $out[] = (string) $block[$k];
            }
            foreach ((array) ($block['steps'] ?? []) as $s) $out[] = (string) $s;
        }
        return trim(strip_tags(implode(' ', array_filter($out))));
    }

    /** The public URL of an article. */
    public static function url(string $slug): string
    {
        return '/help/' . $slug;
    }

    // ── live values ──────────────────────────────────────────────────────────

    /**
     * Swap `{placeholder}` for what the running system actually says.
     *
     * An article that states a price is an article that eventually lies, and a
     * wrong help page is worse than a missing one because the reader cannot tell.
     * Every number a supporter might act on comes from the same settings the
     * checkout reads, so changing the price of a vote silently corrects the prose.
     */
    private static function resolve(array $a): array
    {
        $vals = self::liveValues();
        $swap = static function (string $s) use ($vals): string {
            return strtr($s, $vals);
        };

        $a['summary'] = $swap((string) ($a['summary'] ?? ''));
        foreach ((array) ($a['body'] ?? []) as $i => $block) {
            foreach (['p', 'note'] as $k) {
                if (isset($block[$k])) $a['body'][$i][$k] = $swap((string) $block[$k]);
            }
            foreach ((array) ($block['steps'] ?? []) as $j => $s) {
                $a['body'][$i]['steps'][$j] = $swap((string) $s);
            }
        }
        return $a;
    }

    /**
     * @return array<string,string> placeholder => current value
     */
    private static function liveValues(): array
    {
        // Every one of these is read, never assumed. A try/catch around the lot
        // because an article must still render on a database that is mid-migration
        // — a help page that 500s is the worst possible time to have a help page.
        $price = '1,000'; $maxQty = '1,000'; $cutoff = '10'; $grace = '6';
        try {
            $price  = number_format(PaidVoteService::pricePerVote());
            $maxQty = number_format(PaidVoteService::maxQtyForOrder());
            $cutoff = (string) PaidVoteService::checkoutCutoffMinutes();
            $grace  = (string) PaidVoteService::lateMintGraceHours();
        } catch (\Throwable) {}

        // ── THE SCORING RULES, FROM THE ENGINE THAT SCORES ───────────────────
        //
        // Same argument as the price, with more at stake. The integrity articles
        // publish the community/judge split, the bonus-vote ceiling and the judge
        // quorum — and RuleEngine lets an operator change every one of them per
        // programme and per cycle. An article that has MEMORISED "45/55" becomes a
        // false published claim the moment somebody sets a cycle override, and
        // nothing in the system would notice.
        //
        // /integrity already reads these from RuleEngine for exactly this reason.
        // The articles that page links out to have to read the same source, or the
        // deep dive quietly contradicts the summary that sent the reader to it.
        $communityPct = '45'; $judgePct = '55'; $paidCapPct = '50'; $minJudges = '2';
        $returnPct = '0'; $returnSupporters = '25';
        try {
            $rules = new RuleEngine();
            $w     = $rules->weights();
            $eff   = $rules->effective();

            $communityPct = (string) (int) round($w['community'] * 100);
            $judgePct     = (string) (int) round($w['judge'] * 100);
            $paidCapPct   = (string) (int) ($eff['max_paid_weight_pct'] ?? 50);
            $minJudges    = (string) (int) ($eff['min_judges_per_nominee'] ?? 2);

            // Basis points to a percentage a person can read: 3000 → "30",
            // 1250 → "12.5". Never a trailing ".0", because "30.0%" in a sentence
            // reads like a measurement rather than a rule.
            $bps = (int) ($eff['community_return_bps'] ?? 0);
            $returnPct = rtrim(rtrim(number_format($bps / 100, 2, '.', ''), '0'), '.');
            if ($returnPct === '') $returnPct = '0';

            $returnSupporters = (string) (int) ($eff['community_return_min_supporters'] ?? 25);
        } catch (\Throwable) {}

        return [
            '{price}'   => $price,
            '{max_qty}' => $maxQty,
            '{cutoff}'  => $cutoff,
            '{grace}'   => $grace,

            '{community_pct}'     => $communityPct,
            '{judge_pct}'         => $judgePct,
            '{paid_cap_pct}'      => $paidCapPct,
            '{min_judges}'        => $minJudges,
            '{return_pct}'        => $returnPct,
            '{return_supporters}' => $returnSupporters,
        ];
    }
}
