<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use AfricaGates\Support\Slug;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Who receives the nominee broadcast, and what each of them is sent.
 *
 * ── WHY THIS IS A SERVICE AND NOT JUST THE COMMAND ───────────────────────────
 * There are two ways to run this — `nominees:broadcast` over a shell, and the token-gated
 * /__setup/broadcast page for a deployment with no SSH — and they must not each own a copy
 * of "who gets mail". Two implementations of a recipient query drift, and the way they
 * drift on an email sender is that one of them mails somebody the other already did.
 * Resolution, rendering and the send log live here; both callers are thin.
 */
final class NomineeBroadcast
{
    /** The original file-based campaign, kept as the default so both old callers are unchanged. */
    public const CAMPAIGN = 'final-hours';
    public const SUBJECT  = 'Finish strong — voting closes soon';

    /**
     * An editable campaign to send instead of the fixed template, or null for it.
     *
     * ── WHY THIS IS A PROPERTY AND NOT A THIRD CLASS ─────────────────────────
     *
     * The class note above is the reason: this file is the single definition of "who gets
     * mail", and two implementations of a recipient query drift. The way they drift on a
     * mail sender is that one of them mails somebody the other already did.
     *
     * So the admin UI does not get its own resolver, its own suppression check or its own
     * log write. It hands a campaign to THIS object and everything else — cycles, address
     * resolution, the ambiguous/unreachable split, `EmailOptOut`, the
     * `UNIQUE(campaign, email_hash)` write that makes a resumed send safe — is the code
     * the console command and the setup endpoint already use.
     *
     * What the campaign changes is only the three things that are genuinely per-campaign:
     * the log key, the subject, and the rendered body.
     */
    private ?object $campaign = null;

    /** Send this stored campaign rather than the fixed template. */
    public function forCampaign(?object $campaign): self
    {
        $this->campaign = $campaign;
        return $this;
    }

    /**
     * The log key for this run. It is the campaign's slug, which is why a slug is fixed on
     * create and never editable: renaming one mid-send would re-mail everybody already done.
     */
    public function campaignKey(): string
    {
        $slug = trim((string) ($this->campaign->slug ?? ''));
        return $slug !== '' ? $slug : self::CAMPAIGN;
    }

    public function subject(): string
    {
        $s = trim((string) ($this->campaign->subject ?? ''));
        return $s !== '' ? $s : self::SUBJECT;
    }

    /**
     * Work out who would be mailed, and why everybody else would not be.
     *
     * Nothing here sends. Callers show this first — over a shell it is the dry run, on the
     * web page it is the screen you read before pressing anything.
     *
     * @return array{cycles:list<object>, queue:list<array<string,mixed>>, sendable:list<array<string,mixed>>,
     *               ambiguous:list<array{0:object,1:list<string>}>, unreachable:list<array{0:object,1:object}>,
     *               counts:array<string,int>}
     */
    public function plan(int $cycleId = 0, string $only = ''): array
    {
        $only   = EmailOptOut::normalise($only);
        $cycles = $this->cycles($cycleId);

        $resolved = $unreachable = $ambiguous = [];
        foreach ($cycles as $cycle) {
            foreach ($this->nominees((int) $cycle->id) as $n) {
                $found = $this->addressesFor($n, (int) $cycle->id);
                if ($found === [])      { $unreachable[] = [$n, $cycle]; continue; }
                if (count($found) > 1)  { $ambiguous[]   = [$n, $found]; continue; }
                $resolved[] = ['nominee' => $n, 'cycle' => $cycle, 'email' => $found[0]];
            }
        }

        // Read once, not once per recipient: a broadcast is thousands of rows.
        $suppressed = EmailOptOut::suppressedHashes();
        $alreadyLog = $this->alreadySent();

        $seen = $queue = [];
        foreach ($resolved as $r) {
            // One address can hold several nominations. Mail the person once.
            $h = EmailOptOut::hash($r['email']);
            if (isset($seen[$h])) continue;
            $seen[$h] = true;

            if (isset($suppressed[$h]))                  $r['skip'] = 'unsubscribed';
            elseif (isset($alreadyLog[$h]))              $r['skip'] = 'already sent';
            elseif ($only !== '' && $r['email'] !== $only) $r['skip'] = 'not the --only address';
            else                                          $r['skip'] = null;

            $queue[] = $r;
        }

        $sendable = array_values(array_filter($queue, fn($r) => $r['skip'] === null));
        $countBy  = fn(string $why) => count(array_filter($queue, fn($r) => $r['skip'] === $why));

        return [
            'cycles' => $cycles, 'queue' => $queue, 'sendable' => $sendable,
            'ambiguous' => $ambiguous, 'unreachable' => $unreachable,
            'counts' => [
                'nominees'     => count($resolved) + count($unreachable) + count($ambiguous),
                'addresses'    => count($queue),
                'unsubscribed' => $countBy('unsubscribed'),
                'already'      => $countBy('already sent'),
                'ambiguous'    => count($ambiguous),
                'unreachable'  => count($unreachable),
                'sendable'     => count($sendable),
            ],
        ];
    }

    /**
     * Send to one recipient and record it.
     *
     * @param array{nominee:object,cycle:object,email:string} $r
     * @return array{ok:bool, error:string}
     */
    public function sendOne(array $r, string $site, OtpService $mailer): array
    {
        $res = $mailer->sendRawHtml(
            $r['email'], $this->subject(),
            $this->html($r, $site), $this->plain($r, $site),
            $this->campaignKey(), EmailOptOut::url($site, $r['email'])
        );
        $ok    = (bool) ($res['success'] ?? false);
        $error = (string) ($res['error'] ?? '');
        $this->logSend($r, $ok, $error);

        return ['ok' => $ok, 'error' => $error];
    }

    /** @param array{nominee:object,cycle:object,email:string} $r */
    public function html(array $r, string $site): string
    {
        // A bare Twig environment: this template uses plain variables only — no asset()
        // or csp_nonce — so it does not need the app's extensions, and not depending on
        // them keeps it renderable from a console with no HTTP request in flight.
        static $twig = null;
        $twig ??= new Environment(
            new FilesystemLoader(\dirname(__DIR__, 2) . '/templates'),
            ['autoescape' => 'html']
        );

        $vars = $this->vars($r, $site);

        // An editable campaign renders through EmailCampaign, which owns the block
        // vocabulary and the placeholder substitution. The fixed template stays the
        // fallback so nothing that worked before this existed behaves differently.
        if ($this->campaign !== null) {
            return EmailCampaign::renderFor($this->campaign, $vars);
        }

        return $twig->render('emails/final-hours.twig', $vars);
    }

    /** @return array<string,mixed> @param array{nominee:object,cycle:object,email:string} $r */
    public function vars(array $r, string $site): array
    {
        $n     = $r['nominee'];
        $close = Carbon::parse((string) $r['cycle']->voting_close);
        $first = trim(explode(' ', trim((string) $n->name))[0] ?? '');

        return [
            // The nominee's OWN cycle. Cycles are per programme and several can be voting
            // at once, so the endpoint's "soonest closing" fallback would hand this person
            // another programme's deadline.
            'countdown_url'   => CountdownGif::urlFor($site, (int) $r['cycle']->id),
            'countdown_alt'   => 'Voting closes ' . $close->format('D j M Y, H:i T'),
            'closes_human'    => $close->format('D j M Y, H:i T'),
            'first_name'      => $first,
            'category_name'   => (string) ($n->category_title ?? ''),
            'vote_url'        => $this->votePage($site, $n),
            'events_url'      => $site . '/events',
            'site_url'        => $site,
            'unsubscribe_url' => EmailOptOut::url($site, $r['email']),
            'postal_address'  => (string) Env::get('MAIL_POSTAL_ADDRESS', 'Afrovanguard, Lagos, Nigeria'),
        ];
    }

    /**
     * A nominee's own vote page.
     *
     * /vote/{PROGRAMME-slug}/{id}-{name}, built the way VoteController::nomineeUrl builds
     * it so this cannot drift from what the router accepts. Spelled out because the route
     * parameter is named `{program}` and reads like the CATEGORY: an earlier version passed
     * the category slug and a bare id, which is a 404 in every recipient's inbox.
     */
    public function votePage(string $site, object $n): string
    {
        $prog = trim((string) ($n->programme_slug ?? ''));
        if ($prog === '') return $site . '/vote';   // the ballot beats a broken personal link

        return sprintf('%s/vote/%s/%s', $site, rawurlencode($prog),
            Slug::idSegment((int) $n->id, (string) $n->name));
    }

    /** @param array{nominee:object,cycle:object,email:string} $r */
    public function plain(array $r, string $site): string
    {
        // An editable campaign's plain text is generated FROM its blocks — see
        // EmailCampaign::plainOf() for why a hand-written alternative would go stale the
        // first time somebody edited the copy.
        if ($this->campaign !== null) {
            return EmailCampaign::plainFor($this->campaign, $this->vars($r, $site));
        }

        $n     = $r['nominee'];
        $close = Carbon::parse((string) $r['cycle']->voting_close)->format('D j M Y, H:i T');
        $first = trim(explode(' ', trim((string) $n->name))[0] ?? '');

        // Written, not strip_tags'd: a stripped campaign is style rules and link text with
        // no sentences in it, and that is the version a plain-text client shows.
        return implode("\n", [
            $first !== '' ? "$first," : 'Hello,',
            '',
            'You are in the final stretch of Africa GATES voting'
                . (($n->category_title ?? '') !== '' ? ' in ' . $n->category_title : '') . '.',
            "Voting closes $close.",
            '',
            'Two things we are asking of you:',
            '1. Mobilise your supporters — share your voting link: ' . $this->votePage($site, $n),
            '2. Be there on the night — ' . $site . '/events',
            '',
            '— Africa GATES',
            'Unsubscribe: ' . EmailOptOut::url($site, $r['email']),
        ]);
    }

    /** @return list<object> cycles in voting with a usable close date */
    public function cycles(int $only = 0): array
    {
        $q = DB::table('gates_award_cycles as cy')
            ->leftJoin('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
            ->whereNotNull('cy.voting_close')
            ->where('cy.voting_close', '>', Carbon::now()->toDateTimeString())
            ->select('cy.id', 'cy.programme_id', 'cy.voting_close', 'p.title as programme_title');

        // The rehearsal is seeded at status 'voting' with voting_close twenty days out,
        // precisely so the sandbox shows a usable ballot — which is also exactly the
        // shape this query selects. So the broadcast picked the sandbox up and mailed
        // its nominees at @demo.invalid: a domain that does not resolve, so every one
        // is a hard bounce charged against the sending domain's reputation, from a
        // send an operator only ever asked for on real cycles.
        //
        // notSandbox() rather than `p.is_active`, and the LEFT JOIN above is the whole
        // reason: `NULL != 5` is NULL in SQL, so a bare comparison would silently drop
        // every cycle whose programme row is missing — real nominees never told their
        // voting had closed, to exclude a sandbox they were never in.
        //
        // Applied BEFORE the $only branch, so naming the rehearsal's cycle id explicitly
        // does not send either. Rehearsing this particular job is not a rehearsal: the
        // addresses are real SMTP sends to a domain that does not exist, so the only
        // thing an operator could learn from it is what a bounce looks like.
        DemoSeeder::notSandbox($q, 'cy.programme_id');

        $only > 0 ? $q->where('cy.id', $only) : $q->where('cy.status', 'voting');

        return $q->orderBy('cy.voting_close')->get()->all();
    }

    /** @return list<object> approved, unmerged nominees in a cycle */
    private function nominees(int $cycleId): array
    {
        return DB::table('gates_nominees as n')
            ->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
            ->join('gates_award_cycles as cy', 'cy.id', '=', 'c.cycle_id')
            ->join('gates_award_programmes as p', 'p.id', '=', 'cy.programme_id')
            ->where('c.cycle_id', $cycleId)
            // Winners and runners-up keep their pages, but this mail is about a vote that
            // has not closed, so only the in-flight status qualifies.
            ->where('n.status', 'approved')
            ->whereNull('n.merged_into')
            ->select('n.id', 'n.name', 'n.profile_id', 'n.category_id',
                     'c.title as category_title', 'p.slug as programme_slug')
            ->orderBy('n.id')
            ->get()->all();
    }

    /**
     * Candidate addresses for a nominee, best source first.
     *
     * The reasoning moved to {@see NomineeAddress} when the invitation run needed the
     * same answer — two implementations of "who do we email" is how one sender writes to
     * somebody the other already did. This stays as the name the send loop below reads by.
     *
     * @return list<string> 0 = unreachable, >1 = ambiguous and must never be guessed
     */
    private function addressesFor(object $n, int $cycleId): array
    {
        return NomineeAddress::candidates($n, $cycleId);
    }


    /** @return array<string,true> email_hash of everything already sent for this campaign */
    private function alreadySent(): array
    {
        $out = [];
        foreach (DB::table('gates_broadcast_log')
                     ->where('campaign', $this->campaignKey())->where('status', 'sent')
                     ->pluck('email_hash') as $h) {
            $out[(string) $h] = true;
        }
        return $out;
    }

    /** @param array{nominee:object,cycle:object,email:string} $r */
    private function logSend(array $r, bool $ok, string $error): void
    {
        try {
            DB::table('gates_broadcast_log')->updateOrInsert(
                ['campaign' => $this->campaignKey(), 'email_hash' => EmailOptOut::hash($r['email'])],
                ['email'      => $r['email'],
                 'nominee_id' => (int) $r['nominee']->id,
                 'status'     => $ok ? 'sent' : 'failed',
                 'error'      => $error === '' ? null : mb_substr($error, 0, 300),
                 'sent_at'    => Carbon::now()->toDateTimeString()]
            );
        } catch (\Throwable) {
            // A logging failure must not undo a send that already happened. It does mean a
            // re-run could mail this person twice, which the caller's tally will show.
        }
    }
}
