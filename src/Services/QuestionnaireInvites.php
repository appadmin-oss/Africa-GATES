<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\SiteUrl;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Sending the questionnaire invitation to many nominees, scoped to chosen programmes.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT WAS THERE, AND WHY IT COULD NOT BE THE FEATURE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `QuestionnairesController::inviteAll()` walked the 500 most recent submissions, skipped
 * anything already invited, and mailed the rest inline in the request. Four problems, all
 * of which show up on a real cycle rather than in testing:
 *
 *   · No scope. Every programme at once or nothing, so an organiser running one programme
 *     could not invite its nominees without touching the others.
 *   · No count before the act. The button said "invite everybody" and the number arrived
 *     afterwards. Nobody should learn how many emails they just sent from the receipt.
 *   · It could not RESEND. `if ($row['invited']) continue;` is exactly right for a first
 *     pass and makes the second pass impossible, which is the case the operator actually
 *     needs — invitations land in spam, and the whole point of chasing is repetition.
 *   · The 500-row cap was silent. A cycle with 600 nominees invited 500 of them and said
 *     so nowhere.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE SHAPE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see plan()} resolves the audience and returns the counts WITHOUT sending anything, so
 * the screen states the number before the button is pressed and restates it as the
 * selection changes. {@see sendOne()} sends and records. The split is the same one
 * {@see NomineeBroadcast} uses, for the same reason.
 *
 * Every recipient lands in exactly one bucket and the buckets are all reported. A nominee
 * with no email is not "nothing happened" — it is a person an organiser has to reach
 * another way, and the count is how they find out.
 *
 * ── IDEMPOTENCY ──────────────────────────────────────────────────────────────
 *
 * Recorded in `gates_broadcast_log` under `questionnaire:c{cycle}`, whose unique key is
 * (campaign, email_hash). So a double-clicked button, a browser retry, or a re-run after a
 * timeout resumes rather than repeats. A deliberate resend is a different thing from an
 * accidental repeat, and it is expressed by ASKING for it — `include_invited` — not by the
 * log forgetting.
 */
final class QuestionnaireInvites
{
    /**
     * How many to send per request.
     *
     * This runs on shared cPanel with no worker process, so the send happens in the
     * request that asked for it and PHP's own time limit is the real constraint. 60 is
     * roughly 60 SMTP round trips — comfortable inside a default 30–60s limit with the
     * connection reused. The screen reports what is left and the operator presses again;
     * a batch that dies at row 400 of 600 having reported nothing is worse than four
     * presses.
     */
    public const BATCH = 60;

    /**
     * The queue job one invitation becomes.
     *
     * Handled in {@see \AfricaGates\Support\Maintenance::drainJobs()}, which the cron tick
     * already runs — so this needs no worker process, which the host does not have.
     */
    public const JOB_INVITE = 'questionnaire.invite';

    /** Audience filters, most useful first. The labels are the radio group on the screen. */
    public const AUDIENCES = [
        'not_submitted' => 'Everyone who has not submitted yet',
        'never_invited' => 'Only nominees never invited',
        'all'           => 'Everyone, including nominees who have already submitted',
    ];

    public static function campaignKey(int $cycleId): string
    {
        return 'questionnaire:c' . $cycleId;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // THE PLAN
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Resolve who would be written to, and why the rest would not be. Sends nothing.
     *
     * @param list<int> $programmeIds empty means every programme in the cycle
     * @return array{
     *   rows:list<array<string,mixed>>, counts:array<string,int>,
     *   skipped:list<array<string,mixed>>, audience:string, cycle_id:int, batch:int
     * }
     */
    public static function plan(int $cycleId, array $programmeIds = [],
                               string $audience = 'not_submitted',
                               bool $includeInvited = false): array
    {
        if (!isset(self::AUDIENCES[$audience])) $audience = 'not_submitted';

        $q = DB::table('gates_nominee_submissions AS s')
            ->leftJoin('gates_nominees AS n', 'n.id', '=', 's.nominee_id')
            ->leftJoin('gates_award_programmes AS p', 'p.id', '=', 's.programme_id')
            ->where('s.cycle_id', $cycleId)
            // A test row has no nominee behind it, so it can never be a recipient — and
            // counting one would show an organiser a number that looked like a person.
            ->where(fn ($w) => $w->whereNull('s.is_test')->orWhere('s.is_test', 0));

        $programmeIds = array_values(array_filter(array_map('intval', $programmeIds)));
        if ($programmeIds !== []) $q->whereIn('s.programme_id', $programmeIds);

        if ($audience === 'not_submitted') $q->whereIn('s.status', ['draft', 'withdrawn']);
        if ($audience === 'never_invited') $q->whereNull('s.invited_at');

        $rows = $q->orderBy('s.id')
            ->get(['s.id', 's.nominee_id', 's.status', 's.invited_at', 's.submitted_at',
                   'n.name', 'p.title AS programme', 's.programme_id']);

        $already = self::alreadySent($cycleId);
        $counts  = ['nominees' => count($rows), 'sendable' => 0, 'no_email' => 0,
                    'unsubscribed' => 0, 'already' => 0, 'disqualified' => 0];

        $send = [];
        $skip = [];

        foreach ($rows as $r) {
            $name = (string) ($r->name ?? ('#' . $r->nominee_id));
            $row  = ['submission_id' => (int) $r->id, 'nominee_id' => (int) $r->nominee_id,
                     'name' => $name, 'programme' => (string) ($r->programme ?? ''),
                     'status' => (string) $r->status];

            // Disqualified is not "did not submit" — writing to ask somebody for work they
            // have already been ruled out of is the cruellest possible bug in this feature.
            if ((string) $r->status === 'disqualified') {
                $counts['disqualified']++;
                $skip[] = $row + ['reason' => 'disqualified'];
                continue;
            }

            $email = self::emailFor((int) $r->nominee_id);
            if ($email === '') {
                $counts['no_email']++;
                $skip[] = $row + ['reason' => 'no email on the nomination'];
                continue;
            }

            if (EmailOptOut::suppressed($email)) {
                $counts['unsubscribed']++;
                $skip[] = $row + ['reason' => 'unsubscribed', 'email' => $email];
                continue;
            }

            if (!$includeInvited && isset($already[EmailOptOut::hash($email)])) {
                $counts['already']++;
                $skip[] = $row + ['reason' => 'already sent', 'email' => $email];
                continue;
            }

            $counts['sendable']++;
            $send[] = $row + ['email' => $email];
        }

        return ['rows' => $send, 'counts' => $counts, 'skipped' => $skip,
                'audience' => $audience, 'cycle_id' => $cycleId, 'batch' => self::BATCH];
    }

    /** @return array<string,true> email_hash => true, for everyone already written to */
    private static function alreadySent(int $cycleId): array
    {
        try {
            $h = DB::table('gates_broadcast_log')
                ->where('campaign', self::campaignKey($cycleId))
                ->where('status', 'sent')
                ->pluck('email_hash')->all();

            return array_fill_keys(array_map('strval', $h), true);
        } catch (\Throwable) {
            // No log means nothing is known to have been sent, which is the safe reading
            // for a PREVIEW — the unique key still stops a duplicate at write time.
            return [];
        }
    }

    /** The first deliverable address on the nomination, or ''. */
    private static function emailFor(int $nomineeId): string
    {
        foreach (ClaimIndependence::contactsFor($nomineeId) as $c) {
            if (($c['channel'] ?? '') !== 'email') continue;
            $v = trim((string) ($c['value'] ?? ''));
            if (filter_var($v, FILTER_VALIDATE_EMAIL)) return $v;
        }
        return '';
    }

    // ═══════════════════════════════════════════════════════════════════════
    // THE SEND
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Send one invitation and record it.
     *
     * @param array<string,mixed> $r one row from {@see plan()}
     * @return array{ok:bool, error:string}
     */
    public static function sendOne(array $r, int $cycleId, OtpService $mailer, string $site = ''): array
    {
        $site  = rtrim($site !== '' ? $site : SiteUrl::base(), '/');
        $email = (string) $r['email'];
        $id    = (int) $r['submission_id'];

        $res = $mailer->sendRawHtml(
            $email,
            'Tell the Africa GATES judges about your work',
            self::html($r, $cycleId, $site),
            self::plain($r, $cycleId, $site),
            'questionnaire',
            // ── LIST-UNSUBSCRIBE, EVEN THOUGH THIS IS ABOUT THEIR OWN NOMINATION ──
            //
            // The message is transactional in substance. But four hundred of them leaving
            // one domain in an afternoon is a bulk pattern, and Gmail and Yahoo's
            // bulk-sender rules do not read substance — mail without a one-click
            // unsubscribe lands in Promotions or Spam. An invitation in the spam folder
            // has not been sent.
            EmailOptOut::url($site, $email)
        );

        $ok    = (bool) ($res['success'] ?? false);
        $error = (string) ($res['error'] ?? '');

        if ($ok) {
            // `invited_at` is what the queue screen and `never_invited` read, and it is
            // stamped only on an actual success. Stamping it optimistically would make a
            // failed batch look delivered and hide those people from every later pass.
            try {
                DB::table('gates_nominee_submissions')->where('id', $id)->update([
                    'invited_at' => Carbon::now()->toDateTimeString(),
                    'updated_at' => Carbon::now()->toDateTimeString(),
                ]);
            } catch (\Throwable) {
                // Nothing to undo — the mail is gone. The log row below still records it.
            }
        }

        self::log($cycleId, $email, (int) $r['nominee_id'], $ok, $error);

        return ['ok' => $ok, 'error' => $error];
    }

    /**
     * Queue the whole audience, rather than sending it inside this request.
     *
     * ── WHY THIS IS THE DEFAULT AND sendBatch() IS THE FALLBACK ──────────────
     *
     * Sending in-request works and is what shipped first: 60 SMTP round trips inside one
     * page load, with the operator pressing again for the next 60. On a cycle with four
     * hundred nominees that is seven presses, and the seventh happens twenty minutes later
     * when somebody remembers.
     *
     * `gates_jobs` already exists, and the maintenance tick already drains it, so there is
     * no worker to add — the queue is the mechanism this platform already has for "do this
     * soon, not now, and survive the request ending". Pressing send once now queues
     * everybody and the tick delivers them.
     *
     * ── THE DEDUPE KEY IS THE WHOLE SAFETY PROPERTY ─────────────────────────
     *
     * `questionnaire.invite:c{cycle}:{email_hash}` is UNIQUE in `gates_jobs`, so a
     * double-clicked button, a browser retry, or an operator who queues twice while the
     * tick is mid-flight adds nothing the second time. That matters more here than in most
     * queues: the failure mode is a nominee receiving the same request four times, which
     * reads as a broken system asking them to do the work again.
     *
     * The hash rather than the address, because a job payload is not a place to accumulate
     * a mailing list in plaintext, and because it is the same key the send log uses.
     *
     * @param list<int> $programmeIds
     * @return array{queued:int, skipped:int, counts:array<string,int>, message:string}
     */
    public static function queue(int $cycleId, array $programmeIds, string $audience,
                                 bool $includeInvited): array
    {
        $plan   = self::plan($cycleId, $programmeIds, $audience, $includeInvited);
        $q      = new QueueService();
        $queued = 0;
        $dupes  = 0;

        foreach ($plan['rows'] as $r) {
            $id = $q->push(self::JOB_INVITE, [
                'cycle_id'      => $cycleId,
                'submission_id' => (int) $r['submission_id'],
                'nominee_id'    => (int) $r['nominee_id'],
                'email'         => (string) $r['email'],
                'name'          => (string) $r['name'],
                'programme'     => (string) ($r['programme'] ?? ''),
            ], 0, self::JOB_INVITE . ':c' . $cycleId . ':' . EmailOptOut::hash((string) $r['email']));

            // push() returns 0 when the dedupe key already existed. Not an error — it is
            // the second press doing nothing, which is the point.
            $id > 0 ? $queued++ : $dupes++;
        }

        $msg = $queued . ' invitation' . ($queued === 1 ? '' : 's') . ' queued. '
             . 'They go out on the next maintenance run — usually within a few minutes.';
        if ($dupes > 0) {
            $msg .= ' ' . $dupes . ' were already queued and were not added again.';
        }
        if ($plan['counts']['no_email'] > 0) {
            $msg .= ' ' . $plan['counts']['no_email'] . ' have no email on their nomination; '
                  . 'open those and send the link yourself.';
        }

        return ['queued' => $queued, 'skipped' => $dupes,
                'counts' => $plan['counts'], 'message' => $msg];
    }

    /**
     * Deliver one queued invitation. The job handler.
     *
     * Re-checks the two things that can have changed between queueing and delivery: the
     * nominee may have submitted in the meantime, and they may have unsubscribed. Neither is
     * a failure — the job is simply no longer worth doing, and throwing would retry it five
     * times before giving up.
     *
     * @param array<string,mixed> $p the job payload
     */
    public static function deliver(array $p, ?OtpService $mailer = null): bool
    {
        // ── NO MAILER IS A THROW, NOT A FALSE ───────────────────────────────
        //
        // OtpService is container-built (it needs the SMTP config), so there is no sane
        // local default to fall back to. And returning false here would mark the job DONE:
        // a deployment whose SMTP was briefly misconfigured would have four hundred
        // invitations silently consumed and never sent, with the log saying they were
        // handled. Throwing puts the job back for retry with backoff and, if it never
        // recovers, records a real failure somebody can see.
        if ($mailer === null) {
            throw new \RuntimeException('questionnaire invite: no mailer available');
        }

        $cycleId = (int) ($p['cycle_id'] ?? 0);
        $id      = (int) ($p['submission_id'] ?? 0);
        $email   = trim((string) ($p['email'] ?? ''));

        if ($id < 1 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
        if (EmailOptOut::suppressed($email)) return false;

        $status = (string) (DB::table('gates_nominee_submissions')->where('id', $id)->value('status') ?? '');
        // Submitted in the meantime, or disqualified since. Writing to either would be a
        // request for work that is already in, or already ruled out.
        if ($status === 'submitted' || $status === 'disqualified') return false;

        return (bool) self::sendOne([
            'submission_id' => $id,
            'nominee_id'    => (int) ($p['nominee_id'] ?? 0),
            'email'         => $email,
            'name'          => (string) ($p['name'] ?? ''),
            'programme'     => (string) ($p['programme'] ?? ''),
        ], $cycleId, $mailer)['ok'];
    }

    /**
     * How many invitations are still waiting in the queue for this cycle.
     *
     * On the screen beside the delivered count, because "queued" and "sent" are different
     * facts and an operator watching nothing happen needs to know which one they are in.
     */
    public static function pending(int $cycleId): int
    {
        try {
            return (int) DB::table('gates_jobs')
                ->where('type', self::JOB_INVITE)
                ->whereIn('status', ['pending', 'running'])
                ->where('dedupe_key', 'like', self::JOB_INVITE . ':c' . $cycleId . ':%')
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Send a batch and report what happened.
     *
     * @param list<int> $programmeIds
     * @return array{sent:int, failed:int, remaining:int, counts:array<string,int>, message:string}
     */
    public static function sendBatch(int $cycleId, array $programmeIds, string $audience,
                                     bool $includeInvited, OtpService $mailer,
                                     int $limit = self::BATCH, string $site = ''): array
    {
        $plan  = self::plan($cycleId, $programmeIds, $audience, $includeInvited);
        $batch = array_slice($plan['rows'], 0, max(1, $limit));

        $sent = 0; $failed = 0; $firstError = '';
        foreach ($batch as $r) {
            $out = self::sendOne($r, $cycleId, $mailer, $site);
            if ($out['ok']) { $sent++; continue; }
            $failed++;
            if ($firstError === '' && $out['error'] !== '') $firstError = $out['error'];
        }

        // Remaining is measured against the PLAN, not the batch, so the silent-truncation
        // problem cannot come back: an operator always sees what is left.
        $remaining = max(0, count($plan['rows']) - count($batch));

        $msg = $sent . ' invitation' . ($sent === 1 ? '' : 's') . ' sent.';
        if ($failed > 0) {
            $msg .= ' ' . $failed . ' failed'
                  . ($firstError !== '' ? ' (' . mb_substr($firstError, 0, 140) . ')' : '')
                  . ' — press again to retry only those.';
        }
        if ($remaining > 0) {
            $msg .= ' ' . $remaining . ' still to go — press again to continue.';
        }
        if ($plan['counts']['no_email'] > 0) {
            $msg .= ' ' . $plan['counts']['no_email'] . ' have no email on their nomination; '
                  . 'open those and send the link yourself.';
        }

        return ['sent' => $sent, 'failed' => $failed, 'remaining' => $remaining,
                'counts' => $plan['counts'], 'message' => $msg];
    }

    private static function log(int $cycleId, string $email, int $nomineeId, bool $ok, string $error): void
    {
        try {
            DB::table('gates_broadcast_log')->updateOrInsert(
                ['campaign' => self::campaignKey($cycleId), 'email_hash' => EmailOptOut::hash($email)],
                ['email'      => $email,
                 'nominee_id' => $nomineeId,
                 'status'     => $ok ? 'sent' : 'failed',
                 'error'      => $error === '' ? null : mb_substr($error, 0, 300),
                 'sent_at'    => Carbon::now()->toDateTimeString()]
            );
        } catch (\Throwable) {
            // A logging failure must not undo a send that already happened. It does mean a
            // re-run could write to this person twice, which the tally will show.
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // THE MESSAGE
    // ═══════════════════════════════════════════════════════════════════════

    /** @param array<string,mixed> $r */
    public static function html(array $r, int $cycleId, string $site): string
    {
        // A bare Twig environment: the template uses plain variables only, so it does not
        // need the app's extensions — and not depending on them keeps it renderable from a
        // console with no HTTP request in flight.
        static $twig = null;
        $twig ??= new Environment(
            new FilesystemLoader(\dirname(__DIR__, 2) . '/templates'),
            ['autoescape' => 'html']
        );

        return $twig->render('emails/questionnaire.twig', self::vars($r, $cycleId, $site));
    }

    /** @return array<string,mixed> @param array<string,mixed> $r */
    public static function vars(array $r, int $cycleId, string $site): array
    {
        $name  = trim((string) ($r['name'] ?? ''));
        $first = trim(explode(' ', $name)[0] ?? '');

        return [
            'first_name'      => $first,
            'programme'       => (string) ($r['programme'] ?? ''),
            'category_name'   => (string) ($r['category'] ?? ''),
            'link'            => QuestionnaireService::url((int) $r['submission_id'], $site),
            'deadline'        => QuestionnairePolicy::humanFor($cycleId),
            'site_url'        => $site,
            'unsubscribe_url' => isset($r['email']) ? EmailOptOut::url($site, (string) $r['email']) : '',
            'postal_address'  => (string) \AfricaGates\Support\Env::get(
                'MAIL_POSTAL_ADDRESS', 'Afrovanguard, Lagos, Nigeria'),
        ];
    }

    /**
     * The plain-text alternative.
     *
     * Written rather than strip_tags'd. `strip_tags` on that template yields the CSS, the
     * MSO conditionals and a wall of collapsed whitespace — and the text/plain part is what
     * a screen reader in a text-only client and every spam filter that scores multipart
     * balance actually reads.
     *
     * @param array<string,mixed> $r
     */
    public static function plain(array $r, int $cycleId, string $site): string
    {
        $v    = self::vars($r, $cycleId, $site);
        $who  = $v['first_name'] !== '' ? $v['first_name'] : 'Nominee';
        $prog = $v['programme'] !== '' ? ' — ' . $v['programme'] : '';

        return "Dear {$who},\n\n"
             . "You have been nominated for an Africa GATES award{$prog}. Right now the judges "
             . "have only what the person who nominated you wrote about you — so we would like to "
             . "hear about the work from you, and to see anything you can show us.\n\n"
             . "YOUR PAGE: {$v['link']}\n\n"
             . "It is a short questionnaire, and you can add your own work: links, documents, "
             . "photographs, reports, letters, press coverage — whatever exists. You do not have "
             . "to finish it in one sitting; the page saves as you go and the link keeps working.\n\n"
             . ($v['deadline'] !== '' ? "Please send it by {$v['deadline']}.\n\n" : '')
             . "Two things worth saying plainly:\n"
             . "  - Nothing here costs money. We will never ask you to pay for a nomination, an "
             . "interview, a result or an award. If anybody does, it is not us.\n"
             . "  - Answering honestly about what has NOT worked has never cost anybody an award. "
             . "The judges are looking for real work, not a perfect record.\n\n"
             . "The Africa GATES Team\n"
             . $v['site_url'] . "\n"
             . ($v['unsubscribe_url'] !== '' ? "\nUnsubscribe: {$v['unsubscribe_url']}\n" : '');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // WHAT THE SCREEN SHOWS ABOUT PAST SENDS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * The last send for a cycle, so nobody re-sends blind.
     *
     * @return array{count:int, failed:int, last_at:?string}
     */
    public static function history(int $cycleId): array
    {
        try {
            $rows = DB::table('gates_broadcast_log')
                ->where('campaign', self::campaignKey($cycleId))
                ->get(['status', 'sent_at']);

            $sent = 0; $failed = 0; $last = null;
            foreach ($rows as $r) {
                (string) $r->status === 'sent' ? $sent++ : $failed++;
                $at = (string) ($r->sent_at ?? '');
                if ($at !== '' && ($last === null || $at > $last)) $last = $at;
            }

            return ['count' => $sent, 'failed' => $failed, 'last_at' => $last];
        } catch (\Throwable) {
            return ['count' => 0, 'failed' => 0, 'last_at' => null];
        }
    }
}
