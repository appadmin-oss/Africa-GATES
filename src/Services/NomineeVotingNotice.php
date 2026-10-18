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
        $out      = ['due' => [], 'unreachable' => 0, 'sent' => 0, 'verified' => 0];
        $seen     = [];

        foreach (self::nominees((int) $cycle->id) as $n) {
            if (!self::verified($n, (int) $cycle->id)) continue;
            $out['verified']++;

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
                'body' => NomineeVotingLetter::render($v),
            ]],
            'view'        => $v,
        ];
    }

    /**
     * The one view both the letter and the email read.
     *
     * @param array{on:bool, signatory:string, signatory_title:string} $cfg
     * @return array<string,string>
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
            ->select('n.id', 'n.name', 'n.profile_id', 'n.category_id', 'c.title as category_title')
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
            'sent'        => $tally['sent'],
            'due'         => count($plan['due']),
            'unreachable' => $plan['unreachable'],
            'failed'      => $tally['failed'],
        ];
    }

    /**
     * The letter an operator previews: the cycle's first nominee, or a specimen when it has
     * none, so the screen can always show what will go out.
     */
    public static function previewPdf(object $cycle): string
    {
        $cfg = self::settings((int) $cycle->programme_id, (int) $cycle->id);
        $n   = self::nominees((int) $cycle->id)[0]
            ?? (object) ['id' => 0, 'name' => 'Specimen Nominee', 'category_title' => 'Specimen Category'];

        return NomineeVotingLetter::render(self::view($n, $cycle, $cfg, SiteUrl::base()));
    }
}
