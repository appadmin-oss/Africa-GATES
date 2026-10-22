<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Services;

use AfricaGates\Admin\Support\Permissions;
use AfricaGates\Services\InterviewService;
use AfricaGates\Services\QuestionnaireService;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * What needs a person today, in the order it needs them.
 *
 * ── THE PROBLEM THIS SOLVES ──────────────────────────────────────────────────
 *
 * The dashboard opened on eight counts — approved profiles, total votes, open nominations,
 * legacy events, active opportunities, partner enquiries, judges, admins — and three charts.
 * Every one of those numbers is true and not one of them is a job. An operator with twenty
 * minutes read "14,203 votes", learned nothing they could act on, and went looking for the
 * work in the sidebar.
 *
 * Worse, the numbers were flattering and the silences were not. A chargeback that Paystack
 * accepts for you after sixteen hours, an interview held three weeks ago whose transcript
 * nobody published, sixty nominees who were opened a questionnaire and never told — none of
 * those appeared anywhere on the front page of the console. The dashboard was cheerful about
 * the things that did not matter and silent about the things that cost money and lose people.
 *
 * ── WHAT THIS RETURNS, AND WHAT IT DELIBERATELY DOES NOT ─────────────────────
 *
 * A list of items, each of which is A JOB: a count, the sentence explaining why it matters,
 * and the screen where it gets done. Ordered by consequence, not by section.
 *
 * An item with a count of zero IS NOT RETURNED. That is the whole design. A grid of green
 * zeroes trains an operator to stop reading the grid, and once they have stopped reading it
 * the one red number in it is invisible too. {@see items()} returning an empty array is
 * itself the useful answer — the caller says "nothing is waiting", once, in a sentence.
 *
 * ── AND IT SHOWS NOBODY A DOOR THEY CANNOT OPEN ──────────────────────────────
 *
 * A viewer offered "3 chargebacks — respond" who is then bounced off /admin/payments with
 * "your role has no access" has been given a job and had it taken away again, which is worse
 * than never being told. This codebase has already had to fix that same sidebar-versus-guard
 * disagreement twice, so {@see forRole()} does not label each item with a section by hand —
 * it asks {@see Permissions::sectionForPath()} about the item's own href, which is the exact
 * function {@see \AfricaGates\Admin\Middleware\SectionGuardMiddleware} uses to decide. The
 * board and the guard cannot disagree, because they are reading the same answer.
 *
 * It mirrors the guard's fail-closed rule too: an unmapped /admin path is superadmin-only, so
 * an item pointing at one is shown to nobody else.
 *
 * ── EVERY READ IS LOCAL ──────────────────────────────────────────────────────
 *
 * No gateway call, no model call, nothing that can time out. The disputes count comes from
 * the urgent support ticket that DisputeAlert opens locally, NOT from
 * DisputeService::queue(), which asks Paystack — a dashboard that waits on somebody else's
 * API is a dashboard that is sometimes a blank page, and this one is the first screen after
 * login. Each probe is individually wrapped, so a table a deployment has not migrated yet
 * costs that one item and not the page.
 */
final class AttentionBoard
{
    /**
     * Every job waiting, most consequential first.
     *
     * @return list<array{key:string, count:int, label:string, why:string,
     *                    href:string, cta:string, tone:string}>
     */
    public static function items(): array
    {
        $out = [];
        foreach (self::probes() as $probe) {
            try {
                $item = $probe();
            } catch (\Throwable) {
                // One unmigrated table must cost one item, never the page. This screen is the
                // first thing after login, and on this deployment "the code is uploaded but
                // /__setup/migrate has not run yet" is an ordinary state minutes wide.
                continue;
            }
            if ($item !== null && $item['count'] > 0) $out[] = $item;
        }
        return $out;
    }

    /**
     * The same list, with anything this role cannot reach removed.
     *
     * The section comes from the item's own href through the guard's own resolver, so a card
     * can never offer a screen the guard will bounce. Unmapped paths fail closed to
     * superadmin, exactly as the middleware does.
     *
     * @return list<array<string,mixed>>
     */
    public static function forRole(string $role): array
    {
        return array_values(array_filter(self::items(), static function (array $i) use ($role): bool {
            $section = Permissions::sectionForPath((string) $i['href']);
            return $section !== null
                ? Permissions::canAccess($role, $section)
                : $role === 'superadmin';
        }));
    }

    /**
     * How much is waiting in total, for the one line above the board.
     *
     * @param list<array<string,mixed>> $items
     */
    public static function total(array $items): int
    {
        $n = 0;
        foreach ($items as $i) $n += (int) $i['count'];
        return $n;
    }

    // ══ the probes, in order of consequence ═══════════════════════════════════

    /**
     * Ordered deliberately, and the order is an argument.
     *
     * MONEY WITH A DEADLINE comes first, because it is the only item where doing nothing has
     * a worse outcome than doing the wrong thing: Paystack accepts a dispute on your behalf
     * after sixteen hours and refunds from your balance.
     *
     * A PERSON WAITING comes next — an interview today, an interview already missed — because
     * the cost of that lateness is somebody's afternoon and their impression of whether this
     * organisation is serious.
     *
     * WORK ALREADY DONE AND NOT FILED after that: a transcript nobody published, a nominee
     * who was never told their questionnaire exists. These are the quiet ones, where the
     * effort has been spent and the benefit thrown away, and they never appear anywhere else.
     *
     * THINGS WITH A HUMAN ON THE OTHER END last: moderation, support, nominations, profiles.
     * They matter and they wait better.
     *
     * @return list<callable():?array<string,mixed>>
     */
    private static function probes(): array
    {
        return [
            // ── money, on a clock ────────────────────────────────────────────
            static function (): ?array {
                // From our OWN record — the urgent ticket DisputeAlert opens when a chargeback
                // webhook lands — rather than from Paystack. A dashboard that waits on
                // somebody else's API is sometimes a blank page.
                $n = (int) DB::table('gates_support_tickets')
                    ->whereIn('status', ['open', 'pending'])
                    ->where('subject', 'like', 'Chargeback%')
                    ->count();
                return self::item('disputes', $n,
                    $n === 1 ? 'chargeback awaiting a response' : 'chargebacks awaiting a response',
                    'Paystack accepts a dispute for you after '
                        . \AfricaGates\Services\DisputeService::RESPOND_WITHIN_HOURS
                        . ' hours and refunds from your balance. Doing nothing is how the money is '
                        . 'lost, and the screen attaches the receipt for you in one press.',
                    '/admin/payments/disputes', 'Respond', 'urgent');
            },

            // ── somebody is waiting ─────────────────────────────────────────
            static function (): ?array {
                $s = InterviewService::summary();
                $n = (int) ($s['overdue'] ?? 0);
                return self::item('interviews_overdue', $n,
                    $n === 1 ? 'interview whose time has passed' : 'interviews whose time has passed',
                    'The sitting was scheduled and is still not closed. Either it happened and '
                        . 'nobody recorded it, or a nominee waited on a call that never came.',
                    '/admin/interviews', 'Open the schedule', 'urgent');
            },
            static function (): ?array {
                $s = InterviewService::summary();
                $n = (int) ($s['today'] ?? 0);
                return self::item('interviews_today', $n,
                    $n === 1 ? 'interview in the next 24 hours' : 'interviews in the next 24 hours',
                    'Check each one has a Meet link and the nominee has consented to being '
                        . 'recorded — the two things that stop a sitting at the door.',
                    '/admin/interviews', 'Open the schedule', 'warn');
            },
            static function (): ?array {
                $s = InterviewService::summary();
                $n = (int) ($s['no_meet'] ?? 0);
                return self::item('interviews_no_link', $n,
                    $n === 1 ? 'sitting with no Meet link' : 'sittings with no Meet link',
                    'An invitation without a link is an appointment nobody can attend.',
                    '/admin/interviews', 'Add the links', 'warn');
            },

            // ── work done, and thrown away ──────────────────────────────────
            static function (): ?array {
                $n = count(InterviewService::unpublished());
                return self::item('transcripts', $n,
                    $n === 1 ? 'interview held but never published' : 'interviews held but never published',
                    'The panel cannot see any of it. An hour of somebody\'s time was spent and '
                        . 'the judges are still reading only the nomination.',
                    '/admin/interviews', 'Publish them', 'warn');
            },
            static function (): ?array {
                $n = (int) (QuestionnaireService::summary()['not_invited'] ?? 0);
                return self::item('quest_not_invited', $n,
                    $n === 1 ? 'nominee never told their questionnaire exists'
                             : 'nominees never told their questionnaire exists',
                    'Their dossier is still written entirely by other people. One press invites '
                        . 'everybody who has not been asked.',
                    '/admin/questionnaires', 'Invite them', 'warn');
            },
            static function (): ?array {
                $n = (int) (QuestionnaireService::summary()['silent'] ?? 0);
                return self::item('quest_silent', $n,
                    $n === 1 ? 'nominee asked and has not opened it'
                             : 'nominees asked and have not opened it',
                    'A phone call is worth more than a second email here — and it is often a link '
                        . 'that landed in a spam folder rather than somebody who is not interested.',
                    '/admin/questionnaires', 'See who', '');
            },

            // ── a human on the other end ────────────────────────────────────
            static function (): ?array {
                $n = (int) DB::table('gates_comments')->where('status', 'quarantined')->count()
                   + (int) DB::table('gates_threads')->where('status', 'quarantined')->count()
                   + count(\AfricaGates\Services\VoteMessageService::queue(200));
                return self::item('moderation', $n,
                    $n === 1 ? 'item held for a moderator' : 'items held for a moderator',
                    'Messages of support are attached to a named real person on their own page, '
                        . 'and some of those people are children. Anything held because the '
                        . 'classifier was unavailable is unjudged rather than borderline.',
                    '/admin/moderation', 'Review', 'warn');
            },
            static function (): ?array {
                $n = (int) DB::table('gates_support_tickets')
                    ->whereIn('status', ['open', 'pending'])
                    ->where(static function ($q): void {
                        // The chargeback tickets have their own card at the top; counting them
                        // twice would make the board argue with itself.
                        $q->whereNull('subject')->orWhere('subject', 'not like', 'Chargeback%');
                    })
                    ->count();
                return self::item('support', $n,
                    $n === 1 ? 'support conversation open' : 'support conversations open',
                    'Somebody wrote in and is waiting. Most are a link that did not arrive.',
                    '/admin/support', 'Answer', '');
            },
            static function (): ?array {
                $n = (int) DB::table('gates_nominations')->where('status', 'pending')->count();
                return self::item('nominations', $n,
                    $n === 1 ? 'nomination waiting on a decision' : 'nominations waiting on a decision',
                    'Nobody can be voted for, interviewed or asked about their own work until '
                        . 'their nomination is approved.',
                    '/admin/nominations', 'Go through them', '');
            },
            static function (): ?array {
                $n = (int) DB::table('gates_profiles')->where('status', 'pending')->count();
                return self::item('profiles', $n,
                    $n === 1 ? 'profile waiting for approval' : 'profiles waiting for approval',
                    'An unapproved profile is invisible on the public site.',
                    '/admin/profiles', 'Review', '');
            },
            static function (): ?array {
                $n = (int) DB::table('gates_partner_enquiries')
                    ->whereIn('status', ['new', 'in_review'])->count();
                return self::item('partners', $n,
                    $n === 1 ? 'partner enquiry unanswered' : 'partner enquiries unanswered',
                    'An organisation offered to help and has heard nothing back.',
                    '/admin/partners', 'Reply', '');
            },
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function item(string $key, int $count, string $label,
                                 string $why, string $href, string $cta, string $tone): ?array
    {
        if ($count <= 0) return null;
        return ['key' => $key, 'count' => $count, 'label' => $label,
                'why' => $why, 'href' => $href, 'cta' => $cta, 'tone' => $tone];
    }

    // ══ the quiet numbers, for below the work ════════════════════════════════

    /**
     * The counts that are worth having but are not jobs.
     *
     * Kept — an operator does want to know the platform has 14,000 votes on it — but moved
     * BELOW the board and rendered small, because the eight of them across the top were the
     * reason the actual work had nowhere to go. `votes_24h` and `nominations_7d` are here
     * because a total says how big this is and a recent figure says whether it is alive,
     * which is the more useful of the two questions on any given morning.
     *
     * @return list<array{k:string, v:int, n:string}>
     */
    public static function pulse(): array
    {
        $count = static function (callable $fn): int {
            try { return (int) $fn(); } catch (\Throwable) { return 0; }
        };
        $since = static fn (int $days): string => Carbon::now()->subDays($days)->toDateTimeString();

        return [
            ['k' => 'Votes in 24 hours',
             'v' => $count(static fn (): int => DB::table('gates_votes')
                        ->where('voted_at', '>=', $since(1))->count()),
             'n' => 'of ' . number_format($count(static fn (): int => DB::table('gates_votes')->count()))
                  . ' ever'],
            ['k' => 'Nominations this week',
             'v' => $count(static fn (): int => DB::table('gates_nominations')
                        ->where('created_at', '>=', $since(7))->count()),
             'n' => number_format($count(static fn (): int => DB::table('gates_nominations')
                        ->where('status', 'approved')->count())) . ' approved in all'],
            ['k' => 'Nominees on the public site',
             'v' => $count(static fn (): int => DB::table('gates_nominees')
                        ->where('status', 'approved')->count()),
             'n' => 'able to receive votes'],
            ['k' => 'With the judges',
             'v' => $count(static fn (): int => (int) (QuestionnaireService::summary()['submitted'] ?? 0)),
             'n' => 'questionnaires in the dossiers'],
            ['k' => 'Interviews published',
             'v' => $count(static fn (): int => (int) (InterviewService::summary()['published'] ?? 0)),
             'n' => 'transcripts a panel can read'],
            ['k' => 'Approved profiles',
             'v' => $count(static fn (): int => DB::table('gates_profiles')
                        ->where('status', 'approved')->count()),
             'n' => 'live in the directory'],
            ['k' => 'Judges',
             'v' => $count(static fn (): int => DB::table('gates_judges')->where('is_active', 1)->count()),
             'n' => 'active on a panel'],
            ['k' => 'Opportunities',
             'v' => $count(static fn (): int => DB::table('gates_opportunities')
                        ->where('status', 'active')->count()),
             'n' => 'open to applicants'],
        ];
    }
}
