<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use AfricaGates\Services\Mail\MailLog;
use AfricaGates\Support\BroadcastLog;
use AfricaGates\Support\DisplayTime;
use AfricaGates\Support\NominationStatus as NS;
use AfricaGates\Support\SchemaHas;
use AfricaGates\Support\SiteUrl;
use AfricaGates\Support\Slug;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Tell every verified nominee, formally and once, that they are on the public vote.
 *
 * ── WHEN ─────────────────────────────────────────────────────────────────────
 *
 * Two things must both be true, and either may become true second:
 *
 *   · the award is in its VOTING phase — computed by {@see CyclePolicy::phaseFor()}
 *     from the windows, never read off the status column, which is a cache;
 *   · the nominee is VERIFIED — {@see verified()}.
 *
 * So the check runs on every maintenance tick rather than at the moment voting opens: a
 * nominee who confirms on day nine of voting is told on day nine, by the same code, and
 * nobody has to remember to press anything. It stops when voting closes, because a letter
 * saying "voting is open" is false the moment it is not.
 *
 * ── ONCE ─────────────────────────────────────────────────────────────────────
 *
 * Claim-before-send on `gates_broadcast_log` ({@see BroadcastLog}), keyed per cycle: two
 * overlapping ticks cannot both send, and a row left at `sending` by a crash is never
 * retried, because missing one person is recoverable and mailing them every five minutes
 * is not. A message the send rules DEFERRED is released and tried on a later tick.
 *
 * ── TO WHOM ──────────────────────────────────────────────────────────────────
 *
 * The person who claimed the page, by their account's address, where a claim is active;
 * otherwise {@see NomineeAddress::one()}, which returns nothing rather than guess between
 * two people nominated under one name. A formal letter carrying somebody else's name and
 * reference is worse than none, and cannot be recalled.
 *
 * ── WHAT IS SETTABLE ─────────────────────────────────────────────────────────
 *
 * Through {@see RuleEngine}, so per programme and per cycle: whether the letter is sent at
 * all, and who signs it. A letter is signed by a person or an office; the default is the
 * office, because a name typed into code is a name nobody can change from the console.
 */
final class NomineeVotingNotice
{
    public const CAMPAIGN_PREFIX = 'voting-letter-';

    /** Per tick. A PDF and a send each; enough to clear a large category in an hour. */
    public const PER_TICK = 40;

    public const DEFAULT_TITLE = 'Awards Secretariat';

    public static function campaign(int $cycleId): string
    {
        return self::CAMPAIGN_PREFIX . $cycleId;
    }

    /**
     * The settings for one cycle.
     *
     * @return array{on:bool, signatory:string, signatory_title:string}
     */
    public static function settings(?int $programmeId = null, ?int $cycleId = null): array
    {
        $eff   = (new RuleEngine())->effective($programmeId, $cycleId);
        $title = trim((string) ($eff['voting_letter_signatory_title'] ?? ''));

        return [
            'on'              => !empty($eff['voting_letter_auto']),
            'signatory'       => mb_substr(trim((string) ($eff['voting_letter_signatory'] ?? '')), 0, 80),
            'signatory_title' => $title !== '' ? mb_substr($title, 0, 80) : self::DEFAULT_TITLE,
            'audience'        => ($eff['voting_letter_audience'] ?? 'ballot') === 'verified' ? 'verified' : 'ballot',
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The tick
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Send what is due, up to {@see PER_TICK}.
     *
     * @return array{sent:int, failed:int, held:int, unreachable:int}
     */
    public static function sweep(OtpService $mailer, ?Carbon $now = null): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'held' => 0, 'unreachable' => 0];
        if (!$mailer->canSend()) return $out;

        $budget = self::PER_TICK;
        foreach (self::votingCycles($now) as $cycle) {
            $cfg = self::settings((int) $cycle->programme_id, (int) $cycle->id);
            if (!$cfg['on']) continue;
            if (self::returnRuleUnchosen($cycle)) continue;

            $plan = self::plan($cycle);
            $out['unreachable'] += $plan['unreachable'];
            foreach ($plan['due'] as $r) {
                if ($budget-- <= 0) return $out;
                $res = self::send($r['nominee'], $cycle, $r['email'], $mailer, $cfg);
                $out[$res]++;
            }
        }
        return $out;
    }

    /**
     * Cycles whose voting is open now, by the computed phase, sandbox excluded.
     *
     * Narrowed in SQL to cycles that could be voting (a voting window around now, or a
     * stored `voting` for the legacy rows with no windows) and then decided by the phase
     * itself, so this cannot disagree with what the public pages say.
     *
     * @return list<object> rows with programme_id, programme_title, programme_slug
     */
    public static function votingCycles(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $at  = $now->toDateTimeString();
        $q = DB::table('gates_award_cycles as cy')
            ->leftJoin('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
            ->where(function ($w) use ($at): void {
                $w->where(function ($v) use ($at): void {
                    $v->whereNotNull('cy.voting_open')->where('cy.voting_open', '<=', $at)
                      ->where(function ($c) use ($at): void {
                          $c->whereNull('cy.voting_close')->orWhere('cy.voting_close', '>', $at);
                      });
                })->orWhere('cy.status', 'voting');
            })
            ->select('cy.*', 'p.title as programme_title', 'p.slug as programme_slug');
        // The rehearsal is seeded in `voting` with real addresses at @demo.invalid.
        DemoSeeder::notSandbox($q, 'cy.programme_id');

        return array_values(array_filter(
            $q->orderBy('cy.id')->get()->all(),
            static fn (object $c): bool => CyclePolicy::phaseFor($c, $now) === CyclePhase::Voting
        ));
    }

    /**
     * Who in this cycle is owed the letter and has not been sent it.
     *
     * @return array{due:list<array{nominee:object,email:string}>, unreachable:int, sent:int, verified:int}
     */
    public static function plan(object $cycle): array
    {
        $campaign = self::campaign((int) $cycle->id);
        $handled  = BroadcastLog::handled($campaign);
        $everyone = self::settings((int) $cycle->programme_id, (int) $cycle->id)['audience'] === 'ballot';
        $out      = ['due' => [], 'unreachable' => 0, 'sent' => 0, 'verified' => 0, 'ballot' => 0, 'unverified' => 0];
        $seen     = [];

        foreach (self::nominees((int) $cycle->id) as $n) {
            $out['ballot']++;
            // Counted either way, so the screen can say how many were left out and why.
            if (self::verified($n, (int) $cycle->id)) {
                $out['verified']++;
            } else {
                $out['unverified']++;
                if (!$everyone) continue;
            }

            $email = self::addressFor($n, (int) $cycle->id);
            if ($email === '') { $out['unreachable']++; continue; }

            $h = EmailOptOut::hash($email);
            if (isset($handled[$h])) { $out['sent']++; continue; }
            // One person standing in two categories gets the first letter this run and the
            // second never: the log is keyed on the address, per cycle, and two letters
            // claiming the same row would have the second one silently skipped anyway.
            if (isset($seen[$h])) continue;
            $seen[$h] = true;

            $out['due'][] = ['nominee' => $n, 'email' => $email];
        }
        return $out;
    }

    /**
     * Send what is due on ONE award edition now, from its admin page, rather than waiting for
     * the maintenance tick — on a host where the tick has stopped, waiting is for ever. The same
     * plan, claim and send as the sweep, so a letter can never go twice.
     *
     * @return array{sent:int, failed:int, held:int, unreachable:int, error?:string}
     */
    public static function sendNow(object $cycle, OtpService $mailer): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'held' => 0, 'unreachable' => 0];
        if (!$mailer->canSend()) return $out + ['error' => 'Email is not set up, so nothing can be sent. See Settings → Mail.'];
        if (CyclePolicy::phaseFor($cycle) !== CyclePhase::Voting) return $out + ['error' => 'Letters go only while this edition is open for voting.'];
        if (self::returnRuleUnchosen($cycle)) return $out + ['error' => self::HOLD_MESSAGE];
        $cfg = self::settings((int) $cycle->programme_id, (int) $cycle->id);
        $plan = self::plan($cycle);
        $out['unreachable'] = $plan['unreachable'];
        foreach (array_slice($plan['due'], 0, self::PER_TICK * 5) as $r) {
            $res = self::send($r['nominee'], $cycle, $r['email'], $mailer, $cfg);
            $out[$res]++;
        }
        return $out;
    }

    /**
     * Verified means the nominee THEMSELVES has answered, by either road this platform has:
     * they claimed their page (a one-time code to their own address or phone, and the claim
     * is active), or they confirmed their nomination from the link sent to them
     * (`nominee_confirmed_at`, {@see NomineeConfirmation}). A moderator's approval alone is
     * not it — that is a judgement about the form, not word from the person.
     */
    public static function verified(object $nominee, int $cycleId): bool
    {
        try {
            $claimed = DB::table('gates_nominee_claims')
                ->where('nominee_id', (int) $nominee->id)
                ->where('status', NomineeClaimService::ST_ACTIVE)->exists();
            if ($claimed) return true;
        } catch (\Throwable) {
            // No claims table on this install: fall through to the confirmation.
        }

        if (!SchemaHas::column('gates_nominations', 'nominee_confirmed_at')) return false;

        // Matched as NomineeAddress matches, by cycle and name: there is no foreign key
        // from a ballot row back to the nomination that produced it.
        return DB::table('gates_nominations')
            ->where('cycle_id', $cycleId)
            ->whereIn('status', [NS::APPROVED, NS::VERIFIED])
            ->whereNotNull('nominee_confirmed_at')
            ->whereRaw('LOWER(TRIM(nominee_name)) = ?', [mb_strtolower(trim((string) $nominee->name))])
            ->exists();
    }

    /** The address to write to, or '' for none and for an ambiguous one. */
    public static function addressFor(object $nominee, int $cycleId): string
    {
        try {
            $e = DB::table('gates_nominee_claims as k')
                ->join('gates_users as u', 'u.id', '=', 'k.user_id')
                ->where('k.nominee_id', (int) $nominee->id)
                ->where('k.status', NomineeClaimService::ST_ACTIVE)
                ->value('u.email');
            if (is_string($e) && filter_var($e, FILTER_VALIDATE_EMAIL)) return EmailOptOut::normalise($e);
        } catch (\Throwable) {
        }
        return NomineeAddress::one($nominee, $cycleId);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // One message
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Claim, send and settle one letter.
     *
     * @param array{on:bool, signatory:string, signatory_title:string} $cfg
     * @return 'sent'|'failed'|'held'
     */
    public static function send(object $nominee, object $cycle, string $email, OtpService $mailer, array $cfg): string
    {
        $campaign = self::campaign((int) $cycle->id);
        if (!BroadcastLog::claim($campaign, $email, (int) $nominee->id)) return 'held';

        try {
            $m   = self::compose($nominee, $cycle, $cfg);
            $res = $mailer->sendBranded($email, $m['subject'], $m['html'], $m['plain'], 'Nomination',
                                        '', '', $m['attachments'], $m['preheader']);
        } catch (\Throwable $e) {
            BroadcastLog::settle($campaign, $email, false, $e->getMessage());
            return 'failed';
        }

        $held = (string) ($res['held'] ?? '');
        if ($held === MailLog::DEFERRED) {
            BroadcastLog::release($campaign, $email);
            return 'held';
        }
        if ($held === MailLog::REFUSED) {
            BroadcastLog::settle($campaign, $email, false, (string) ($res['error'] ?? ''), BroadcastLog::REFUSED);
            return 'held';
        }
        $ok = (bool) ($res['success'] ?? false);
        BroadcastLog::settle($campaign, $email, $ok, (string) ($res['error'] ?? ''));
        return $ok ? 'sent' : 'failed';
    }

    /**
     * Subject, preheader, HTML, plain text and the letter, from one view.
     *
     * @param array{on:bool, signatory:string, signatory_title:string} $cfg
     * @return array{subject:string, preheader:string, html:string, plain:string,
     *               attachments:list<array{name:string,mime:string,body:string}>, view:array<string,string>}
     */
    public static function compose(object $nominee, object $cycle, array $cfg, ?string $base = null): array
    {
        $v = self::view($nominee, $cycle, $cfg, $base ?? SiteUrl::base());

        return [
            'subject'     => 'Your nomination is on the public vote: ' . $v['award'],
            'preheader'   => 'Formal notification for ' . $v['category'] . ', with your letter attached.',
            'html'        => self::html($v),
            'plain'       => self::plain($v),
            'attachments' => [[
                'name' => NomineeVotingLetter::fileName($v),
                'mime' => 'application/pdf',
                'body' => NomineeVotingLetter::render($v, (string) $v['paper']),
            ]],
            'view'        => $v,
        ];
    }

    /**
     * The one view both the letter and the email read.
     *
     * @param array{on:bool, signatory:string, signatory_title:string} $cfg
     * @return array<string,mixed> strings, and `how` from {@see howVotingWorks()}
     */
    public static function view(object $nominee, object $cycle, array $cfg, string $base): array
    {
        $award = trim((string) ($cycle->programme_title ?? 'Africa GATES'));
        $label = trim((string) ($cycle->edition_label ?? ''));
        $award .= ' ' . ($label !== '' ? $label : (string) ($cycle->year ?? ''));
        $rulesHost = (string) (parse_url($base, PHP_URL_HOST) ?: 'africagates.org');

        return [
            'name'            => trim((string) $nominee->name),
            'category'        => trim((string) ($nominee->category_title ?? '')),
            'award'           => trim($award),
            'reference'       => self::reference((int) $cycle->id, (int) $nominee->id),
            'date'            => DisplayTime::show(Carbon::now()->toDateTimeString(), 'j F Y'),
            'opens'           => DisplayTime::show((string) ($cycle->voting_open ?? Carbon::now()->toDateTimeString()), 'l j F Y'),
            'closes'          => !empty($cycle->voting_close)
                ? DisplayTime::showZoned((string) $cycle->voting_close, 'l j F Y \a\t H:i')
                : 'the close of voting',
            'vote_url'        => self::votePage($base, $nominee, (string) ($cycle->programme_slug ?? '')),
            'rules_url'       => $rulesHost . '/integrity',
            'signatory'       => $cfg['signatory'],
            'signatory_title' => $cfg['signatory_title'],
            'how'             => self::howVotingWorks((int) ($cycle->programme_id ?? 0) ?: null, (int) $cycle->id ?: null),
            'steps'           => self::steps((int) ($cycle->programme_id ?? 0) ?: null, (int) $cycle->id ?: null),
            'prizes'          => self::prizes((int) $cycle->id ?: null),
            'prizes_lead'     => \AfricaGates\Support\EditionPrizes::LEAD,
            // The condition the terms put on the prizes is stated on the award page, with the live
            // total; the letter only links to it (owner, 31 Oct 2026). Empty where none applies.
            'prizes_link'     => self::prizes((int) $cycle->id ?: null) !== [] && \AfricaGates\Support\EditionPrizes::condition((int) $cycle->id ?: null) !== null
                ? ((string) ($cycle->programme_slug ?? '') !== '' ? $base . '/awards/' . rawurlencode((string) $cycle->programme_slug) . '?tab=details' : $base . '/awards')
                : '',
            'community_return'=> self::communityReturn((int) ($cycle->programme_id ?? 0) ?: null, (int) $cycle->id ?: null),
            'paper'           => NomineeVotingLetter::paperFor((string) ($nominee->country_code ?? '')),
        ] + self::words((int) $cycle->id);
    }

    /**
     * The edition's headline and paragraphs (CycleLetter): published words, or the default.
     *
     * @return array{headline:string, letter_body:string}
     */
    public static function words(int $cycleId): array
    {
        $w = CycleLetter::current($cycleId);
        return ['headline' => $w['headline'], 'letter_body' => $w['body']];
    }

    /**
     * The letter's "How voting works" — the design's four short steps, each sentence true of
     * THIS edition. Read from the same resolvers as howVotingWorks(): an edition with no free
     * vote is not told about one, and one that sells no votes is not told about prices. The
     * vote form's own required fields are name, phone and email; the message, and showing a
     * name with it, are optional (templates/pages/vote-nominee.twig).
     *
     * @return list<string>
     */
    public static function steps(?int $programmeId = null, ?int $cycleId = null): array
    {
        $paid    = PaidVoteService::enabled();
        $freeOff = PaidVoteService::freeVotingDisabled();
        $price   = '₦' . number_format(PaidVoteService::pricePerVote());

        $out = ['Supporters open your voting page, or scan the code on your letter.'];
        $out[] = $freeOff
            ? 'In this edition votes are cast by contribution, at ' . $price . ' a vote, through our secure '
              . 'payment provider. Supporters give their name, phone number and email.'
            : 'Each person has one free vote in your category, confirmed by a code sent to their email. They '
              . 'give their name, phone number and email; a message is optional, and so is showing their name with it.';
        if ($paid && !$freeOff) {
            $out[] = 'Supporters may also make an optional contribution, at ' . $price . ' a vote, which adds votes '
                   . 'and supports the programme. No one has to pay to vote.';
        }
        $out[] = 'When voting closes, the independent panel judges the nominees, and its marks are combined with '
               . 'public support.';

        return $out;
    }

    /**
     * How the public vote works and how the outcome is decided, as this edition runs it.
     *
     * ── EVERY FIGURE IS READ, NONE IS TYPED ──────────────────────────────────
     *
     * The letter is kept for years and the rules are settings per programme and per cycle.
     * So the split between the panel and the public comes from {@see RuleEngine::weights()},
     * the people-versus-votes split from {@see CpiService::REACH_PEOPLE_SHARE} and the
     * edition's basis, the quorum from `min_judges_per_nominee`, and the vote price and
     * whether free votes exist from {@see PaidVoteService} — the same resolvers the ballot,
     * the scorer and /integrity read. A sentence that would be false for this edition is
     * left out rather than softened: an award that sells no votes is not told about prices.
     *
     * @return list<array{title:string, text:string}>
     */
    public static function howVotingWorks(?int $programmeId = null, ?int $cycleId = null): array
    {
        $rules = new RuleEngine();
        $eff   = $rules->effective($programmeId, $cycleId);
        $w     = $rules->weights($programmeId, $cycleId);
        $judge = (int) round($w['judge'] * 100);
        $comm  = 100 - $judge;
        $basis = CpiService::basis($eff['community_basis'] ?? null);
        $byPeople = in_array($basis, [CpiService::BASIS_IDEAL, CpiService::BASIS_REACH], true);
        $people   = (int) round(CpiService::REACH_PEOPLE_SHARE * 100);
        $quorum   = max(1, (int) ($eff['min_judges_per_nominee'] ?? 2));
        $paid     = PaidVoteService::enabled();
        $freeOff  = PaidVoteService::freeVotingDisabled();

        $out = [];

        $out[] = ['title' => 'Casting a vote', 'text' => $freeOff
            ? 'Members of the public vote for you on your voting page. In this edition votes are '
              . 'cast by contribution, through our secure payment provider.'
            : 'Any member of the public may vote for you on your voting page. Each person has one '
              . 'free vote in each category, confirmed by a one-time code sent to their email '
              . 'address, so that a vote cannot be cast in another person\'s name.'];

        if ($paid) {
            $out[] = ['title' => 'Contributed votes', 'text' =>
                'Supporters may ' . ($freeOff ? '' : 'also ') . 'contribute for votes at ₦'
                . number_format(PaidVoteService::pricePerVote()) . ' per vote. '
                . PaidVoteCopy::sentence($programmeId, $cycleId)
                . ' Votes from a refunded payment are removed, and every published result shows '
                . 'how much of a tally was contributed.'];
        }

        $out[] = ['title' => 'How the outcome is decided', 'text' =>
            'Your final score combines the assessment of an independent judging panel, which '
            . 'carries ' . $judge . '% of the score, with public support, which carries '
            . $comm . '%. '
            . ($byPeople
                ? 'Within public support, ' . $people . '% reflects how many separate people back '
                  . 'you, each counted once however many votes they cast, and ' . (100 - $people)
                  . '% reflects the total number of votes.'
                : 'Public support is measured by the total number of votes.')];

        $out[] = ['title' => 'The judging panel', 'text' =>
            'Each nominee is assessed by at least ' . $quorum . ' ' . ($quorum === 1 ? 'judge' : 'judges')
            . ' against the published criteria. Judges do not see votes or payments.'];

        $out[] = ['title' => 'Results', 'text' =>
            'Voting is monitored for irregular activity. Results are sealed at the moment they '
            . 'are announced and are not altered afterwards.'];

        if ($byPeople) {
            $out[] = ['title' => 'Your part', 'text' =>
                'Share your voting page with the people who know your work. The number of '
                . 'separate supporters counts for more than the number of votes.'];
        }

        return $out;
    }

    /**
     * What each place wins in this edition (EditionPrizes), as labelled rows: [place, prize].
     * Nothing typed here: an edition with no prizes set leaves the section out. The places are
     * the edition's OVERALL standing (owner, 30 Oct 2026), and every reader says so with
     * EditionPrizes::LEAD.
     *
     * @return list<array{0:string,1:string}>
     */
    public static function prizes(?int $cycleId): array
    {
        return array_map(static fn (array $z): array => [$z['label'], $z['text']], \AfricaGates\Support\EditionPrizes::for($cycleId));
    }

    /**
     * The community return, as this edition runs it, as labelled rows: [label, text]. Left out
     * where it cannot pay: paid voting off (nothing raises money in a nominee's name) or a 0%
     * share. The target pool (CommunityReturnPool) is told in full, row by row, because it is
     * the rule a nominee can work towards; the other models state their rule in one row.
     *
     * @return list<array{0:string,1:string}>
     */
    public static function communityReturn(?int $programmeId, ?int $cycleId): array
    {
        if (!CommunityReturnService::active()) return [];
        $rules = CommunityReturnMilestone::rulesForCycle($cycleId);
        if ((int) ($rules['community_return_bps'] ?? 0) <= 0) return [];
        $withdraw = ['Withdrawing', 'You need a verified account and a verified claim on your nominee page.'];
        if (CommunityReturnService::modelOf($rules) !== CommunityReturnService::MODEL_POOL) {
            return [['The rule', CommunityReturnMilestone::summary($rules)], $withdraw];
        }
        $t = CommunityReturnPool::target((int) $cycleId);
        $how = $t['each'] > 0
            // Nominees are approved one at a time and the letter goes as each is, so the figure is
            // dated and said to fall: a target printed as fixed would be wrong on every early letter.
            ? number_format($t['each']) . ' votes each on ' . DisplayTime::show(Carbon::now()->toDateTimeString(), 'j F') . ': the ₦'
              . number_format($t['milestone_naira']) . ' milestone at ₦' . number_format($t['price_naira']) . ' a vote, across '
              . number_format($t['nominees']) . ' approved ' . ($t['nominees'] === 1 ? 'nominee' : 'nominees')
              . '. It falls as more are approved; the award page shows it now.'
            : 'Every nominee has the same target: the edition’s milestone in votes, divided across the approved nominees.';
        return [
            ['What goes in', CommunityReturnLadder::pct((int) $rules['community_return_bps']) . '% of every contribution made for a nominee in this edition goes into one pool.'],
            ['The target', $how],
            ['How it is shared', 'When voting closes, the nominees who reached the target share the pool by their votes. If only one reaches it, they receive all of it.'],
            ['If nobody reaches it', 'There is no return, and the pool goes to the programme’s reserves.'],
            $withdraw,
        ];
    }

    /** "AG/VOT/{cycle}/{nominee}": stable, so a reprint and the original agree. */
    public static function reference(int $cycleId, int $nomineeId): string
    {
        return sprintf('AG/VOT/%d/%05d', $cycleId, $nomineeId);
    }

    /** Built as VoteController::nomineeUrl builds it; see NomineeBroadcast::votePage(). */
    private static function votePage(string $base, object $n, string $programmeSlug): string
    {
        if ($programmeSlug === '') return $base . '/vote';

        return sprintf('%s/vote/%s/%s', $base, rawurlencode($programmeSlug),
                       Slug::idSegment((int) $n->id, (string) $n->name));
    }

    /** @param array<string,string> $v */
    private static function html(array $v): string
    {
        static $twig = null;
        $twig ??= \AfricaGates\Support\Translator::register(new Environment(
            new FilesystemLoader(\dirname(__DIR__, 2) . '/templates'),
            ['autoescape' => 'html']
        ));

        return $twig->render('emails/nominee-voting.twig', $v + [
            'body' => NomineeVotingLetter::body($v),
            'headline_text' => CycleLetter::fill((string) ($v['headline'] ?? CycleLetter::DEFAULT_HEADLINE), $v),
            'c'    => \AfricaGates\Support\Accent::mail(),
        ]);
    }

    /** Written, not strip_tags'd: the letter's own paragraphs, then the particulars. */
    private static function plain(array $v): string
    {
        $sign = $v['signatory'] !== '' ? [$v['signatory'], $v['signatory_title']] : [$v['signatory_title']];

        return implode("\n", array_merge(
            ['Dear ' . $v['name'] . ',', ''],
            array_merge(...array_map(static fn (string $p): array => [$p, ''], NomineeVotingLetter::body($v))),
            [
                'Particulars of nomination',
                'Award: ' . $v['award'],
                'Category: ' . $v['category'],
                'Reference: ' . $v['reference'],
                'Public voting: ' . $v['opens'] . ' to ' . $v['closes'],
                'Your voting page: ' . $v['vote_url'],
                '',
                'How the public vote works',
            ],
            array_merge(...array_map(static fn (array $h, int $i): array =>
                [($i + 1) . '. ' . $h['title'] . '. ' . $h['text']], $v['how'], array_keys($v['how']))),
            ($v['prizes'] ?? []) ? array_merge(['', 'Prizes to be won', \AfricaGates\Support\EditionPrizes::LEAD], array_map(static fn (array $r): string => $r[0] . ': ' . $r[1], $v['prizes']),
                ($v['prizes_link'] ?? '') !== '' ? ['Conditions for these prizes: ' . (string) $v['prizes_link']] : []) : [],
            ($v['community_return'] ?? []) ? array_merge(['', 'Your community return'], array_map(static fn (array $r): string => $r[0] . ': ' . $r[1], $v['community_return'])) : [],
            [
                '',
                'The formal letter of notification is attached, for your records.',
                '',
                'Yours sincerely,',
            ],
            $sign,
            ['For and on behalf of Africa GATES']
        ));
    }

    /** Approved, unmerged nominees in a cycle, with what the letter names. */
    private static function nominees(int $cycleId): array
    {
        return DB::table('gates_nominees as n')
            ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
            ->where('c.cycle_id', $cycleId)
            ->where('n.status', 'approved')
            ->whereNull('n.merged_into')
            ->select('n.id', 'n.name', 'n.profile_id', 'n.category_id', 'n.country_code', 'c.title as category_title')
            ->orderBy('n.id')
            ->get()->all();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // For the console
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * What the cycle screen says: how many verified nominees, how many told, how many
     * cannot be reached, and whether it is running at all. Visible without chasing.
     *
     * @return array{on:bool, voting:bool, verified:int, sent:int, due:int, unreachable:int, failed:int}
     */
    public static function status(object $cycle): array
    {
        $cfg   = self::settings((int) $cycle->programme_id, (int) $cycle->id);
        $plan  = self::plan($cycle);
        $tally = BroadcastLog::tally(self::campaign((int) $cycle->id));

        return [
            'on'          => $cfg['on'],
            'voting'      => CyclePolicy::phaseFor($cycle) === CyclePhase::Voting,
            'verified'    => $plan['verified'],
            'ballot'      => $plan['ballot'],
            'unverified'  => $plan['unverified'],
            'audience'    => $cfg['audience'],
            'sent'        => $tally['sent'],
            'due'         => count($plan['due']),
            'unreachable' => $plan['unreachable'],
            'failed'      => $tally['failed'],
            // Why nothing is going out, when the reason is the community-return rule.
            'held'        => self::returnRuleUnchosen($cycle) ? self::HOLD_MESSAGE : '',
            // Nominees told a rule this edition no longer runs, and not yet corrected.
            'correction_due' => count(self::correctionPlan($cycle)['due']),
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // The community-return section: chosen before it is promised, corrected if it changes
    // ══════════════════════════════════════════════════════════════════════════

    public const HOLD_MESSAGE = 'Letters are on hold: this edition has no community-return rule of its own, and the letter '
        . 'states one. Choose and save it on Community return (Money), then the letters go.';

    /** The correction's campaign prefix; the rest is the cycle and the rule it corrects to. */
    public const CORRECTION_PREFIX = 'voting-letter-fix-';

    /**
     * Is this letter about to state a community-return rule nobody chose for the edition?
     *
     * The letter's "Your community return" section is a promise about money, read from the
     * edition's effective rules. An edition with no rule of its own inherits the house default
     * — Threshold — silently, and the letter promised it to live nominees on an edition set up
     * as a target pool (4 Nov 2026). So where the section would be printed, the letter waits
     * until a programme or edition has CHOSEN its rule. Where there is no section (no votes are
     * sold, or the share is nought) nothing is promised and nothing waits.
     */
    public static function returnRuleUnchosen(object $cycle): bool
    {
        $pid = (int) ($cycle->programme_id ?? 0) ?: null;
        $cid = (int) ($cycle->id ?? 0) ?: null;
        if (self::communityReturn($pid, $cid) === []) return false;
        $from = (new RuleEngine())->provenance('community_return_model', $pid, $cid)['from'];
        return !in_array($from, ['programme', 'cycle'], true);
    }

    /**
     * The keys that decide what the letter's community-return section SAYS, and nothing else.
     *
     * Hashed, so "has the rule a nominee was told changed?" is a comparison rather than a
     * reading of prose — the pool's target line carries today's date and would differ daily.
     *
     * @param array<string,mixed> $rules a RuleEngine::effective() result
     */
    public static function returnFingerprint(array $rules): string
    {
        $keys = ['community_return_model', 'community_return_bps', 'community_return_locked_bps',
                 'community_return_milestone_naira', 'community_return_vote_threshold',
                 'community_return_supporter_cap_pct', 'community_return_ladder', 'community_return_ladder_basis',
                 'community_return_ladder_locked', 'community_return_vote_price_naira'];
        $pick = [];
        foreach ($keys as $k) $pick[$k] = $rules[$k] ?? null;
        $pick['community_return_model'] = CommunityReturnService::modelOf($rules);
        return substr(hash('sha256', (string) json_encode($pick)), 0, 12);
    }

    /** The correction campaign for this edition's CURRENT rule: one correction per rule, ever. */
    public static function correctionCampaign(int $cycleId): string
    {
        return self::CORRECTION_PREFIX . $cycleId . '-'
            . self::returnFingerprint(CommunityReturnMilestone::rulesForCycle($cycleId));
    }

    /**
     * Who was sent the letter BEFORE the edition's rule last changed, and has not been sent
     * the correction to the rule as it stands now.
     *
     * Read from the letter's own send log, by the address it actually went to: nobody is
     * corrected who was not told, and nobody told after the change is told it again.
     *
     * @return array{due:list<array{nominee:object,email:string,sent_at:string}>, changed_at:?string}
     */
    public static function correctionPlan(object $cycle): array
    {
        $cycleId = (int) $cycle->id;
        $out = ['due' => [], 'changed_at' => null];
        $changed = (string) ((new RuleEngine())->provenance(CommunityReturnMilestone::CHANGED_AT,
            (int) ($cycle->programme_id ?? 0) ?: null, $cycleId)['value'] ?? '');
        if ($changed === '' || self::returnRuleUnchosen($cycle)) return $out;
        $out['changed_at'] = $changed;
        if (self::communityReturn((int) ($cycle->programme_id ?? 0) ?: null, $cycleId) === []) return $out;

        $done = BroadcastLog::handled(self::correctionCampaign($cycleId));
        $byId = [];
        foreach (self::nominees($cycleId) as $n) $byId[(int) $n->id] = $n;
        try {
            $rows = DB::table('gates_broadcast_log')
                ->where('campaign', self::campaign($cycleId))
                ->where('status', BroadcastLog::SENT)
                ->where('sent_at', '<', $changed)
                ->orderBy('id')->get(['email', 'email_hash', 'nominee_id', 'sent_at']);
        } catch (\Throwable) {
            return $out;
        }
        foreach ($rows as $r) {
            if (isset($done[(string) $r->email_hash])) continue;
            // Off the ballot since (withdrawn, merged): the letter no longer applies to them.
            $n = $byId[(int) $r->nominee_id] ?? null;
            if ($n === null) continue;
            $out['due'][] = ['nominee' => $n, 'email' => (string) $r->email, 'sent_at' => (string) $r->sent_at];
        }
        return $out;
    }

    /**
     * Send the correction to everybody {@see correctionPlan()} names, now.
     *
     * Pressed by a person, never by the tick: a correction is an admission, and the operator
     * should see the count and give a reason before it goes. Claim-before-send on its own
     * campaign, so pressing twice, or two people pressing at once, corrects each person once.
     *
     * @return array{sent:int, failed:int, held:int, error?:string}
     */
    public static function sendCorrections(object $cycle, OtpService $mailer): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'held' => 0];
        if (!$mailer->canSend()) return $out + ['error' => 'Email is not set up, so nothing can be sent. See Settings → Mail.'];
        if (self::returnRuleUnchosen($cycle)) return $out + ['error' => self::HOLD_MESSAGE];
        $plan = self::correctionPlan($cycle);
        if ($plan['due'] === []) return $out + ['error' => 'Nobody is owed a correction: every letter sent already states this edition’s rule.'];

        $campaign = self::correctionCampaign((int) $cycle->id);
        $cfg = self::settings((int) $cycle->programme_id, (int) $cycle->id);
        foreach ($plan['due'] as $r) {
            if (!BroadcastLog::claim($campaign, $r['email'], (int) $r['nominee']->id)) { $out['held']++; continue; }
            try {
                $m   = self::composeCorrection($r['nominee'], $cycle, $cfg, $r['sent_at']);
                $res = $mailer->sendBranded($r['email'], $m['subject'], $m['html'], $m['plain'], 'Nomination',
                                            '', '', $m['attachments'], $m['preheader']);
            } catch (\Throwable $e) {
                BroadcastLog::settle($campaign, $r['email'], false, $e->getMessage());
                $out['failed']++;
                continue;
            }
            $held = (string) ($res['held'] ?? '');
            if ($held === MailLog::DEFERRED) { BroadcastLog::release($campaign, $r['email']); $out['held']++; continue; }
            if ($held === MailLog::REFUSED) {
                BroadcastLog::settle($campaign, $r['email'], false, (string) ($res['error'] ?? ''), BroadcastLog::REFUSED);
                $out['held']++;
                continue;
            }
            $ok = (bool) ($res['success'] ?? false);
            BroadcastLog::settle($campaign, $r['email'], $ok, (string) ($res['error'] ?? ''));
            $out[$ok ? 'sent' : 'failed']++;
        }
        return $out;
    }

    /**
     * The correction: what was wrong, the rule that applies, and the letter reissued.
     *
     * It says plainly that the earlier letter was wrong and apologises. It does not claim
     * nothing else in the letter changed, because the reissued copy is drawn from the
     * edition as it stands today and somebody may have edited the letter's words since.
     *
     * @return array{subject:string, preheader:string, html:string, plain:string,
     *               attachments:list<array{name:string,mime:string,body:string}>}
     */
    public static function composeCorrection(object $nominee, object $cycle, array $cfg, string $sentAt, ?string $base = null): array
    {
        $v    = self::view($nominee, $cycle, $cfg, $base ?? SiteUrl::base());
        $when = DisplayTime::show($sentAt, 'j F Y');
        $lead = 'In our letter of ' . $when . ' telling you that your nomination is on the public vote, the section headed '
              . '“Your community return” described a rule this edition does not run. We are sorry for the error.';
        $after = 'The rule that applies to ' . $v['award'] . ' is set out below. A corrected copy of your letter is attached '
               . 'and replaces the one dated ' . $when . '. Your nomination, your category and your voting page are unchanged.';

        static $twig = null;
        $twig ??= \AfricaGates\Support\Translator::register(new Environment(
            new FilesystemLoader(\dirname(__DIR__, 2) . '/templates'), ['autoescape' => 'html']));
        // Where the rule is published: this award's own details, not /integrity, which states
        // only what holds for every award.
        $slug = (string) ($cycle->programme_slug ?? '');
        $rules = ($base ?? SiteUrl::base()) . ($slug !== '' ? '/awards/' . rawurlencode($slug) . '?tab=details#community-return' : '/awards');
        $html = $twig->render('emails/nominee-voting-correction.twig', [
            'name' => $v['name'], 'lead' => $lead, 'after' => $after, 'community_return' => $v['community_return'],
            'reference' => $v['reference'], 'rules_url' => $rules, 'vote_url' => $v['vote_url'],
            'signatory' => $v['signatory'], 'signatory_title' => $v['signatory_title'],
            'c' => \AfricaGates\Support\Accent::mail(),
        ]);
        $sign = $v['signatory'] !== '' ? [$v['signatory'], $v['signatory_title']] : [$v['signatory_title']];
        $plain = implode("\n", array_merge(
            ['Dear ' . $v['name'] . ',', '', $lead, '', $after, '', 'Your community return'],
            array_map(static fn (array $r): string => $r[0] . ': ' . $r[1], $v['community_return']),
            ['', 'Reference: ' . $v['reference'], 'Your voting page: ' . $v['vote_url'],
             'This award’s rules, community return included: ' . $rules, '', 'Yours sincerely,'],
            $sign, ['For and on behalf of Africa GATES']));

        return [
            'subject'     => 'Correction to your nomination letter: ' . $v['award'],
            'preheader'   => 'The community return section of our earlier letter was wrong. The correct rule, and a corrected letter.',
            'html'        => $html,
            'plain'       => $plain,
            'attachments' => [[
                'name' => NomineeVotingLetter::fileName($v),
                'mime' => 'application/pdf',
                'body' => NomineeVotingLetter::render($v, (string) $v['paper']),
            ]],
        ];
    }

    /**
     * The letter an operator previews: the cycle's first nominee, or a specimen when it has
     * none, so the screen can always show what will go out.
     */
    public static function previewPdf(object $cycle, string $paper = '', ?array $words = null): string
    {
        $v = self::previewView($cycle, $words);

        return NomineeVotingLetter::render($v, isset(NomineeVotingLetter::PAPER[$paper]) ? $paper : (string) $v['paper']);
    }

    /**
     * The view the preview and the editor's fit meter use: the cycle's first nominee (or a
     * specimen), with the words being edited in place of the published ones when given.
     *
     * @param array{headline:string, body:string}|null $words
     * @return array<string,mixed>
     */
    public static function previewView(object $cycle, ?array $words = null): array
    {
        $cfg = self::settings((int) $cycle->programme_id, (int) $cycle->id);
        $n   = self::nominees((int) $cycle->id)[0]
            ?? (object) ['id' => 0, 'name' => 'Specimen Nominee', 'category_title' => 'Specimen Category'];
        $v   = self::view($n, $cycle, $cfg, SiteUrl::base());
        if ($words !== null) {
            $v['headline'] = $words['headline'];
            $v['letter_body'] = $words['body'];
        }
        return $v;
    }

    /**
     * Whether these words fit one page at both sizes, measured the way render() lays out the
     * letter (NomineeVotingLetter::measure()), against this edition's real first nominee.
     *
     * @param array{headline:string, body:string} $words
     * @return array<string, array{fits:bool, spare_mm:float}>
     */
    public static function fit(object $cycle, array $words): array
    {
        $v = self::previewView($cycle, $words);
        $out = [];
        foreach (array_keys(NomineeVotingLetter::PAPER) as $paper) {
            $m = NomineeVotingLetter::measure($v, $paper);
            $out[$paper] = ['fits' => $m['bottom'] <= $m['limit'], 'spare_mm' => round($m['limit'] - $m['bottom'], 1)];
        }
        return $out;
    }
}
