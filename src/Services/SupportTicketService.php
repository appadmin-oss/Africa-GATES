<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use AfricaGates\Support\OptionalColumn;
use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Handing a conversation to a human.
 *
 * ── THE ORDER OF OPERATIONS IS THE DESIGN ────────────────────────────────────
 *
 * Write the row FIRST, then try to deliver it. Every delivery channel here can
 * fail in a way nobody notices — SMTP on a shared host silently drops mail, a
 * webhook endpoint 500s, a Make.com scenario is paused — and if the row is
 * written last, or only on success, a failed delivery is a person who asked for
 * help and left no trace. With the row first, the worst case is a ticket sitting
 * in the admin queue with `emailed = 0`, which is a visible, fixable state.
 *
 * ── AND WHY NEITHER FAILURE IS RAISED ────────────────────────────────────────
 *
 * The person is mid-conversation. An exception here would replace "I have passed
 * this to the team" with a 500, which is precisely the wrong response to someone
 * who already has a problem. Both deliveries are recorded and swallowed; the
 * ticket exists either way, and that is what the promise is actually made of.
 */
final class SupportTicketService
{
    private const REF_PREFIX = 'AGS';

    public function __construct(private readonly ?OtpService $mailer = null) {}

    /**
     * Open a ticket from a support conversation.
     *
     * @param list<array{role:string,content:string}> $history
     * @param list<array{tool:string,args:array,ok:bool}> $trace
     * @return string|null The reference to quote back, or null if nothing could be stored.
     */
    public function open(string $message, array $history, SupportContext $ctx, array $trace = [],
                         array $meta = []): ?string
    {
        $ref = self::REF_PREFIX . '-' . strtoupper(bin2hex(random_bytes(3)));

        $row = [
            'reference'  => $ref,
            'user_id'    => $meta['user_id'] ?? null,
            'email'      => $meta['email'] ?? null,
            'name'       => $meta['name'] ?? null,
            'subject'    => mb_substr((string) ($meta['subject_override'] ?? '') !== ''
                                ? (string) $meta['subject_override'] : self::subjectFrom($message), 0, 200),
            'transcript' => self::transcript($history, $message),
            // Tool NAMES, never their output — see the migration's note. Whoever
            // picks this up can look the data up properly and with an audit trail.
            'tools_used' => mb_substr(implode(', ', array_unique(array_column($trace, 'tool'))), 0, 250),
            // Classified from the text UNLESS the caller states it. severity() reads a
            // member's own words, which is the right default and the wrong tool for a
            // ticket this platform opened itself: a machine-written body about a
            // chargeback contains "money" and "refunded" and classifies as `high`, and
            // only `urgent` is what stops SupportAutoResolver replying to it. A caller
            // that KNOWS what it is holding should not have to smuggle a keyword into
            // its own prose to be believed. Whitelisted, so a bad value cannot become a
            // severity the queue does not understand.
            'severity'   => in_array((string) ($meta['severity'] ?? ''), ['urgent', 'high', 'normal'], true)
                                ? (string) $meta['severity'] : self::severity($message),
            'status'     => 'open',
            'page_url'   => mb_substr((string) ($meta['page_url'] ?? ''), 0, 490) ?: null,
            'user_agent' => mb_substr((string) ($meta['user_agent'] ?? ''), 0, 250) ?: null,
            'ip_hash'    => !empty($meta['ip']) ? hash('sha256', (string) $meta['ip']) : null,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        try {
            // last_activity is optional until 2026_08_01_support_messages runs, so a
            // pre-migration install still opens tickets instead of 500ing.
            $row = \AfricaGates\Support\OptionalColumn::filter('gates_support_tickets',
                $row + ['last_activity' => $row['created_at']], ['last_activity']);
            $id = (int) DB::table('gates_support_tickets')->insertGetId($row);
        } catch (\Throwable $e) {
            // Usually the migration has not been applied. Loud, because from here
            // on the escalation promise cannot be kept and someone must know.
            error_log('[support] TICKET NOT STORED (' . $e->getMessage() . '). Run db:migrate.');
            return null;
        }

        $emailed   = $this->email($ref, $row);
        $webhooked = $this->webhook($ref, $row, $ctx);

        try {
            DB::table('gates_support_tickets')->where('id', $id)
                ->update(['emailed' => $emailed ? 1 : 0, 'webhooked' => $webhooked ? 1 : 0]);
        } catch (\Throwable) { /* the ticket is already safe; the flags are bookkeeping */ }

        if (!$emailed && !$webhooked) {
            error_log('[support] ticket ' . $ref . ' reached NOBODY — check SMTP and webhooks.');
        }

        return $ref;
    }

    // ── delivery ─────────────────────────────────────────────────────────────

    private function email(string $ref, array $row): bool
    {
        $to = Notifier::supportEmail();
        if ($to === '' || $this->mailer === null) return false;

        $body = "A support conversation was escalated.\n\n"
              . "Reference: {$ref}\n"
              . "Severity:  {$row['severity']}\n"
              . "From:      " . ($row['name'] ?: 'a visitor') . ' <' . ($row['email'] ?: 'not signed in') . ">\n"
              . ($row['page_url'] ? "Page:      {$row['page_url']}\n" : '')
              . ($row['tools_used'] ? "Looked up: {$row['tools_used']}\n" : '')
              . "\n— — — conversation — — —\n\n" . $row['transcript'] . "\n";

        try {
            // Addressed to $to, NOT via Notifier::adminAlert(). adminAlert always
            // sends to the admin-alert inbox, so the SUPPORT_EMAIL resolved two
            // lines up was computed, checked for emptiness, and then thrown away —
            // every ticket went to the operations address no matter what support
            // was configured to be. The bug was invisible because both addresses
            // usually resolve to somebody who reads mail.
            $this->mailer->sendBranded($to,
                "[Africa GATES] [{$row['severity']}] Support {$ref}: " . $row['subject'],
                nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')), $body, 'Support');
            return true;
        } catch (\Throwable $e) {
            error_log('[support] escalation email failed for ' . $ref . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Fire the webhook so an operator's own automation can page a human.
     *
     * This is the channel that reaches a phone: a Make/Zapier scenario on
     * `support.escalated` can send an SMS, a WhatsApp message or a Slack ping.
     * The payload carries no transcript — a webhook goes to a third party, and
     * the conversation may contain whatever the person typed about themselves.
     * It carries the reference, and the reference is enough to open the ticket.
     */
    private function webhook(string $ref, array $row, SupportContext $ctx): bool
    {
        try {
            $payload = [
                'reference' => $ref,
                'subject'   => $row['subject'],
                'severity'  => $row['severity'],
                'signed_in' => $ctx->isMember(),
                'email'     => $row['email'],
                'page_url'  => $row['page_url'],
                'opened_at' => $row['created_at'],
            ];
            WebhookService::dispatch('support.escalated', $payload);
            WebhookService::dispatch('support.ticket_opened', $payload);
            return true;
        } catch (\Throwable $e) {
            error_log('[support] escalation webhook failed for ' . $ref . ': ' . $e->getMessage());
            return false;
        }
    }

    // ── threads ──────────────────────────────────────────────────────────────

    /**
     * A member's own tickets, newest activity first.
     *
     * Scoped by user id AND email. Both, because a ticket opened before someone
     * signed up carries their email and no id, and one opened from a session
     * where the email later changed carries the id — matching on either alone
     * loses tickets people would reasonably expect to see.
     *
     * @return list<array<string,mixed>>
     */
    public function forMember(int $userId, string $email, int $limit = 25): array
    {
        $email = mb_strtolower(trim($email));
        if ($userId < 1 && $email === '') return [];

        try {
            $rows = DB::table('gates_support_tickets')
                ->where(function ($q) use ($userId, $email) {
                    if ($userId > 0) $q->orWhere('user_id', $userId);
                    if ($email !== '') $q->orWhereRaw('LOWER(email) = ?', [$email]);
                })
                // Ordered by whichever column this database actually has. Naming
                // `last_activity` unconditionally is what turned a missing column
                // into "you have no tickets" — a SQL error swallowed by the catch
                // below, on the one page whose whole job is to prove otherwise.
                ->orderByDesc(OptionalColumn::on('gates_support_tickets', 'last_activity')
                    ? DB::raw('COALESCE(last_activity, created_at)') : 'id')
                ->limit($limit)
                ->get(OptionalColumn::filter('gates_support_tickets',
                    ['id', 'reference', 'subject', 'severity', 'status', 'created_at', 'last_activity'],
                    ['last_activity']));
        } catch (\Throwable $e) {
            // Loud. An empty ticket list and a broken ticket list look identical to
            // the reader, and the second one is the one somebody has to fix.
            error_log('[support] could not list tickets: ' . $e->getMessage());
            return [];
        }

        // One query for every row's activity rather than one per row. A member
        // with twenty-five tickets would otherwise cost fifty round trips to
        // render a list, which is the shape of slowness nobody profiles because
        // each individual query looks fine.
        $ids  = $rows->pluck('id')->all();
        $meta = $this->activity($ids);

        return $rows->map(function ($r) use ($meta) {
            $m = $meta[(int) $r->id] ?? ['replies' => 0, 'last' => null];
            return [
                'id' => (int) $r->id, 'reference' => (string) $r->reference,
                'subject' => (string) $r->subject, 'severity' => (string) $r->severity,
                'status' => (string) $r->status, 'created_at' => (string) $r->created_at,
                'last_activity' => (string) ($r->last_activity ?? $r->created_at),
                'replies' => $m['replies'],
                // "Who spoke last" is the single most useful thing on a ticket
                // list and the one nobody renders: it is the difference between
                // "they owe me an answer" and "I owe them one".
                'waiting' => $m['last'] === null || $m['last'] === 'member',
                'answered_by_assistant' => $m['last'] === 'agent',
            ];
        })->all();
    }

    /**
     * Reply count and last author for a batch of tickets.
     *
     * @param list<int> $ids
     * @return array<int, array{replies:int, last:?string}>
     */
    private function activity(array $ids): array
    {
        if ($ids === []) return [];
        try {
            $rows = DB::table('gates_support_messages')
                ->whereIn('ticket_id', $ids)->where('is_internal', 0)
                ->orderBy('id')->get(['ticket_id', 'author_type']);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $t = (int) $r->ticket_id;
            $out[$t] ??= ['replies' => 0, 'last' => null];
            $out[$t]['replies']++;
            $out[$t]['last'] = (string) $r->author_type;
        }
        return $out;
    }

    /**
     * One ticket and its visible replies — but only if it is the caller's.
     *
     * The ownership check is a WHERE clause, not an `if` after the fetch. Same
     * reason the reclaim path works that way: a row the caller may not see should
     * never be loaded into memory next to a branch someone can later get wrong.
     *
     * @return array{ticket:array<string,mixed>, messages:list<array<string,mixed>>}|null
     */
    public function threadFor(string $reference, int $userId, string $email): ?array
    {
        $email = mb_strtolower(trim($email));
        if (trim($reference) === '' || ($userId < 1 && $email === '')) return null;

        try {
            $t = DB::table('gates_support_tickets')
                ->where('reference', trim($reference))
                ->where(function ($q) use ($userId, $email) {
                    if ($userId > 0) $q->orWhere('user_id', $userId);
                    if ($email !== '') $q->orWhereRaw('LOWER(email) = ?', [$email]);
                })
                ->first();
            if (!$t) return null;

            $msgs = DB::table('gates_support_messages')
                ->where('ticket_id', $t->id)
                // Internal staff notes are never returned on the member path. This
                // is the query that decides it, so there is no template that can
                // forget to hide them.
                ->where('is_internal', 0)
                ->orderBy('id')->limit(200)
                ->get(['author_type', 'author_name', 'body', 'created_at']);
        } catch (\Throwable $e) {
            error_log('[support] could not load ticket thread: ' . $e->getMessage());
            return null;
        }

        return [
            'ticket' => [
                'reference' => (string) $t->reference, 'subject' => (string) $t->subject,
                'severity' => (string) $t->severity, 'status' => (string) $t->status,
                'transcript' => (string) ($t->transcript ?? ''),
                // The transcript parsed back into turns. See opening().
                'opening'    => self::opening((string) ($t->transcript ?? '')),
                'created_at' => (string) $t->created_at,
                'last_activity' => (string) ($t->last_activity ?? $t->created_at),
                'resolved_at'   => $t->resolved_at ?? null,
                // Whether anything was actually looked up before a human saw it.
                // Shown in the rail so a member can tell a ticket that was
                // investigated from one that was merely filed.
                'tools_used'    => (string) ($t->tools_used ?? ''),
            ],
            'messages' => $msgs->map(fn($m) => [
                'from'  => match ((string) $m->author_type) {
                    'staff' => 'Support',
                    // Labelled as itself, always. A member who is told a person
                    // read their ticket, and later works out it was a machine,
                    // has been lied to about the one thing support cannot lie
                    // about — whether anyone is actually looking.
                    'agent' => 'Support assistant',
                    default => (string) ($m->author_name ?: 'You'),
                },
                'staff' => in_array((string) $m->author_type, ['staff', 'agent'], true),
                'agent' => (string) $m->author_type === 'agent',
                'body' => (string) $m->body,
                'created_at' => (string) $m->created_at,
            ])->all(),
        ];
    }

    /**
     * Add a reply to a ticket.
     *
     * A member replying REOPENS the ticket. That is the point: a resolved ticket
     * someone writes back on is, by definition, not resolved, and leaving it
     * closed means the reply lands in a queue nobody is looking at.
     *
     * @return array{ok:bool, message:string}
     */
    public function reply(string $reference, string $body, int $userId, string $email, string $name = ''): array
    {
        $body = trim($body);
        if ($body === '')                return ['ok' => false, 'message' => 'Write something first.'];
        if (mb_strlen($body) > 5000)     $body = mb_substr($body, 0, 5000);

        $thread = $this->threadFor($reference, $userId, $email);
        if ($thread === null)            return ['ok' => false, 'message' => 'That support ticket is not on this account.'];

        try {
            $t = DB::table('gates_support_tickets')->where('reference', trim($reference))->first(['id', 'subject', 'status']);
            // insertGetId, not insert: the caller needs to bind any evidence the
            // member attached to THIS message. Guessing it afterwards by "the most
            // recent row" would be right almost always, and the exception is two
            // replies landing together — which is exactly when a screenshot ending
            // up under the wrong message is hardest to notice.
            $messageId = (int) DB::table('gates_support_messages')->insertGetId([
                'ticket_id' => $t->id, 'author_type' => 'member',
                'author_id' => $userId > 0 ? $userId : null,
                'author_name' => mb_substr($name ?: 'Member', 0, 160),
                'body' => $body, 'is_internal' => 0, 'emailed' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            DB::table('gates_support_tickets')->where('id', $t->id)->update(
                OptionalColumn::filter('gates_support_tickets', [
                    'status' => 'open', 'last_activity' => date('Y-m-d H:i:s'), 'resolved_at' => null,
                ], ['last_activity']));
        } catch (\Throwable $e) {
            error_log('[support] reply not stored for ' . $reference . ': ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Your reply could not be saved. Please email the team.'];
        }

        // Tell the team. Same order-of-operations rule as open(): the row is
        // already safe, so a mail or webhook failure loses a notification, not
        // the reply itself.
        try {
            $note = "A member replied on support ticket {$reference}.\n\n" . $body;
            $this->mailer?->sendBranded(Notifier::supportEmail(),
                "[Africa GATES] Support reply on {$reference}: " . ($t->subject ?? ''),
                nl2br(htmlspecialchars($note, ENT_QUOTES, 'UTF-8')), $note, 'Support');
        } catch (\Throwable) {}
        try {
            WebhookService::dispatch('support.ticket_replied',
                ['reference' => trim($reference), 'from' => 'member', 'at' => date('c')]);
        } catch (\Throwable) {}

        return ['ok' => true, 'message' => 'Reply added — the team has been notified.',
                'ticket_id' => (int) $t->id, 'message_id' => $messageId];
    }

    /**
     * An OPEN ticket this person already has about the same thing, or null.
     *
     * ── WHY THE QUEUE NEEDS THIS ─────────────────────────────────────────────
     *
     * "Talk to a human" is on screen from the first frame, deliberately, and it
     * gets pressed more than once. Somebody escalates on Monday, hears nothing by
     * Tuesday, comes back and presses it again — and receives a SECOND reference
     * for one problem. Two people can now pick up two tickets about one payment,
     * the member has been handed two numbers to quote, and the backlog reads as
     * twice the size it is. They did nothing wrong; the button did what it said.
     *
     * So a repeat escalation becomes a reply on the ticket that already exists,
     * and the member is given back the reference they already have — which is
     * also the one sitting in their inbox.
     *
     * MATCHING IS DELIBERATELY NARROW: same person, still open, opened within
     * three days, same normalised subject. Anything looser — fuzzy text, a longer
     * window — starts folding genuinely separate problems into one thread, and a
     * merged ticket is far worse than a duplicate one: the second problem simply
     * disappears.
     *
     * @return array{id:int, reference:string}|null
     */
    public function openTicketFor(int $userId, string $email, string $subject): ?array
    {
        $email   = mb_strtolower(trim($email));
        $subject = self::normaliseSubject($subject);
        if ($subject === '' || ($userId < 1 && $email === '')) return null;

        try {
            $rows = DB::table('gates_support_tickets')
                ->where('status', 'open')
                ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 3 * 86400))
                ->where(function ($q) use ($userId, $email) {
                    if ($userId > 0) $q->orWhere('user_id', $userId);
                    if ($email !== '') $q->orWhereRaw('LOWER(email) = ?', [$email]);
                })
                ->orderByDesc('id')->limit(10)
                ->get(['id', 'reference', 'subject']);
        } catch (\Throwable $e) {
            // Never fatal. Failing to FIND a duplicate must not block an
            // escalation — the worst case is the behaviour we had before.
            error_log('[support] duplicate check failed: ' . $e->getMessage());
            return null;
        }

        foreach ($rows as $r) {
            if (self::normaliseSubject((string) $r->subject) === $subject) {
                return ['id' => (int) $r->id, 'reference' => (string) $r->reference];
            }
        }
        return null;
    }

    /** Case, punctuation and runs of space removed — subjects are generated, not typed. */
    private static function normaliseSubject(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ',
            mb_strtolower((string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s))));
    }

    /**
     * Add this conversation to a ticket that is already open.
     *
     * Attributed to the member and it REOPENS nothing — the ticket was never
     * closed. What it does is move `last_activity`, so a chased ticket rises in
     * the queue instead of ageing quietly at the bottom of it.
     */
    public function appendEscalation(array $ticket, string $message, array $history, string $name = ''): bool
    {
        $id = (int) ($ticket['id'] ?? 0);
        if ($id < 1) return false;

        $body = "The member came back through the assistant and said:\n\n" . trim($message);
        if ($history) $body .= "\n\n— — — the conversation since — — —\n\n" . self::transcript($history, '');

        try {
            DB::table('gates_support_messages')->insert([
                'ticket_id' => $id, 'author_type' => 'member', 'author_id' => null,
                'author_name' => mb_substr($name !== '' ? $name : 'Member', 0, 160),
                'body' => mb_substr($body, 0, 5000), 'is_internal' => 0, 'emailed' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            DB::table('gates_support_tickets')->where('id', $id)->update(
                OptionalColumn::filter('gates_support_tickets',
                    ['status' => 'open', 'last_activity' => date('Y-m-d H:i:s')], ['last_activity']));
        } catch (\Throwable $e) {
            error_log('[support] could not append to ' . ($ticket['reference'] ?? '?') . ': ' . $e->getMessage());
            return false;
        }

        try {
            $note = "A member chased support ticket {$ticket['reference']}.\n\n" . $body;
            $this->mailer?->sendBranded(Notifier::supportEmail(),
                "[Africa GATES] Chased: Support {$ticket['reference']}",
                nl2br(htmlspecialchars($note, ENT_QUOTES, 'UTF-8')), $note, 'Support');
        } catch (\Throwable) {}

        return true;
    }

    /**
     * Open a ticket directly, without a conversation behind it.
     *
     * Members only, and that is enforced by the CALLER holding a session; this
     * method takes an explicit member identity rather than looking one up, so it
     * cannot be reached with a null user by accident.
     */
    public function openForMember(int $userId, string $email, string $name,
                                  string $subject, string $body, array $meta = []): ?string
    {
        $subject = trim($subject) !== '' ? trim($subject) : self::subjectFrom($body);
        return $this->open($body, [], new SupportContext($userId, $email, false), [], $meta + [
            'user_id' => $userId, 'email' => $email, 'name' => $name,
            'subject_override' => $subject,
        ]);
    }

    /**
     * A reply written by the assistant rather than by a person.
     *
     * Separate from {@see reply()} for three reasons that all matter:
     *   • it is attributed to `agent`, so the member is told what answered them;
     *   • it does NOT reopen the ticket — an automated reply is not activity from
     *     the member, and treating it as such would make every auto-answered
     *     ticket look freshly urgent to whoever triages the queue;
     *   • it can RESOLVE, which a member's reply must never do.
     *
     * The email is the point. A reply nobody sees is not support — and somebody
     * whose payment has just been repaired should learn that from their inbox,
     * not by remembering to come back and look.
     */
    public function agentReply(int $ticketId, string $body, bool $resolve = false): bool
    {
        return $this->postReply($ticketId, $body, $resolve, [
            'type'    => 'agent',
            'name'    => 'Support assistant',
            'id'      => null,
            'signoff' => 'This reply was written by the Africa GATES support assistant. If it did not '
                       . 'solve it, reply on the support ticket and a person will pick it up.',
        ])['ok'];
    }

    /**
     * A reply written by a NAMED PERSON on the support desk.
     *
     * ── WHAT WAS WRONG ───────────────────────────────────────────────────────
     *
     * The admin desk had no such method, so a staff reply was pushed through
     * agentReply() — which stamps every message `agent` / "Support assistant" and
     * signs the outgoing email "written by the Africa GATES support assistant".
     * The controller computed the admin's actual name and then discarded it.
     *
     * So a person answering a ticket by hand was published to the member as a bot.
     * On a platform whose support complaints began with people feeling talked at by
     * a machine, that is not a labelling nit: it means a human never appears to
     * arrive, however much of their day they spend replying.
     *
     * ── AND IT REPORTED SUCCESS IT HAD NOT ACHIEVED ──────────────────────────
     *
     * agentReply() returns a single bool that is true as long as the row was
     * WRITTEN. No mailer configured, no address on the ticket, or a send that threw
     * — all three end with the admin being told "Reply sent and added to the
     * member's thread." while nothing left the building. That is the difference
     * between a slow support desk and one that is silently broken, and the admin
     * had no way to tell which one they were operating.
     *
     * This returns what actually happened, so the caller can say so.
     *
     * @return array{ok:bool, emailed:bool, reason:?string, to:?string}
     */
    public function staffReply(int $ticketId, string $body, string $actorName,
                               ?int $actorId = null, bool $resolve = false): array
    {
        return $this->postReply($ticketId, $body, $resolve, [
            'type'    => 'staff',
            'name'    => mb_substr(trim($actorName) !== '' ? trim($actorName) : 'Support team', 0, 120),
            'id'      => $actorId,
            'signoff' => 'Reply on this support ticket and it comes straight back to the team.',
        ]);
    }

    /**
     * The one path both a person and the assistant travel, so the two are recorded
     * identically and neither can quietly acquire behaviour the other lacks.
     *
     * @param array{type:string,name:string,id:?int,signoff:string} $author
     * @return array{ok:bool, emailed:bool, reason:?string, to:?string}
     */
    private function postReply(int $ticketId, string $body, bool $resolve, array $author): array
    {
        $out = ['ok' => false, 'emailed' => false, 'reason' => null, 'to' => null];

        $body = trim($body);
        // Explicit, not a `+` union — see the note on the no_mailer return below.
        if ($ticketId < 1 || $body === '') {
            return ['ok' => false, 'emailed' => false, 'reason' => 'empty', 'to' => null];
        }
        $body = mb_substr($body, 0, 5000);
        $now  = date('Y-m-d H:i:s');

        try {
            $t = DB::table('gates_support_tickets')->where('id', $ticketId)
                ->first(['id', 'reference', 'subject', 'email', 'name', 'status', 'user_id']);
            if (!$t) return ['ok' => false, 'emailed' => false, 'reason' => 'no_ticket', 'to' => null];

            $messageId = (int) DB::table('gates_support_messages')->insertGetId(
                OptionalColumn::filter('gates_support_messages', [
                    'ticket_id' => $ticketId, 'author_type' => $author['type'], 'author_id' => $author['id'],
                    'author_name' => $author['name'], 'body' => $body,
                    'is_internal' => 0, 'emailed' => 0, 'created_at' => $now,
                ], ['author_id']));
            $out['message_id'] = $messageId;
            $out['ticket_id']  = $ticketId;

            $patch = ['last_activity' => $now];
            if ($resolve) { $patch['status'] = 'resolved'; $patch['resolved_at'] = $now; }
            DB::table('gates_support_tickets')->where('id', $ticketId)->update(
                OptionalColumn::filter('gates_support_tickets', $patch, ['last_activity', 'resolved_at']));
        } catch (\Throwable $e) {
            error_log('[support] reply not stored on ticket ' . $ticketId . ': ' . $e->getMessage());
            return ['ok' => false, 'emailed' => false, 'reason' => 'save_failed', 'to' => null];
        }

        $out['ok'] = true;

        // ── FIND AN ADDRESS BEFORE GIVING UP ─────────────────────────────────
        //
        // A ticket opened from a signed-in session can carry a user_id and no email
        // of its own, and the reply then went nowhere at all. The address is on the
        // account; look there before concluding there isn't one.
        $to = trim((string) ($t->email ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) && (int) ($t->user_id ?? 0) > 0) {
            try {
                $to = trim((string) DB::table('gates_users')->where('id', (int) $t->user_id)->value('email'));
            } catch (\Throwable) { $to = ''; }
        }
        $out['to'] = $to !== '' ? $to : null;

        if ($this->mailer === null) {
            error_log('[support] no mailer available; reply on ' . $t->reference . ' was NOT emailed');
            // Built explicitly, not `$out + [...]`: array union keeps the LEFT side's
            // key, and $out already carries reason => null, so the union silently
            // discarded the reason and reported an unexplained non-delivery.
            return ['ok' => true, 'emailed' => false, 'reason' => 'no_mailer', 'to' => $out['to']];
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            error_log('[support] no address on ticket ' . $t->reference . '; reply was NOT emailed');
            return ['ok' => true, 'emailed' => false, 'reason' => 'no_address', 'to' => null];
        }

        $sent = $this->deliver($t, $body, $to, $author['signoff']);
        return ['ok' => true, 'emailed' => $sent, 'reason' => $sent ? null : 'send_failed', 'to' => $to];
    }

    /**
     * Put the reply in the member's inbox. Returns whether it ACTUALLY went.
     *
     * The return value is the whole reason this is a separate method. It used to be
     * inline, wrapped in a try/catch that logged and carried on, inside a function
     * that returned an unconditional `true` — so a failed send and a delivered one
     * were the same outcome to every caller, and the desk was told "sent" either way.
     */
    private function deliver(object $t, string $body, string $to, string $signoff): bool
    {
        // ── THE LINK HAS TO WORK FOR SOMEBODY WITH NO ACCOUNT ────────────────
        //
        // This pointed at /support/tickets?ref=…, which redirects a guest straight
        // to sign-in. Paid voting takes an email and a card and creates no account,
        // so the entire unminted-vote population got a reply whose only "reply here"
        // link bounced them to a login they could never complete. From their side
        // the conversation was a monologue, which is exactly the complaint that
        // started all this.
        //
        // A scoped link works for everyone: it opens this one thread and nothing
        // else, and it goes to the address already on the ticket. Falls back to the
        // member desk when one cannot be minted, because an email with a worse link
        // still beats a reply that never goes out.
        $link = TicketLinkService::urlFor((int) $t->id, $to);
        if ($link === '') {
            $link = SiteUrl::base() . '/support/tickets?ref=' . rawurlencode((string) $t->reference);
        }

        $text = "Hi " . (trim((string) ($t->name ?? '')) ?: 'there') . ",\n\n" . $body
              . "\n\n— — —\nSupport ticket {$t->reference}: {$t->subject}\n"
              . "Reply to this support ticket at " . $link . "\n"
              . "No account needed — the link opens your conversation.\n"
              . $signoff;

        try {
            $this->mailer->sendBranded($to, "Re: [{$t->reference}] {$t->subject}",
                nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')), $text, 'Support');
        } catch (\Throwable $e) {
            error_log('[support] reply email failed on ' . $t->reference . ': ' . $e->getMessage());
            return false;
        }

        try {
            DB::table('gates_support_messages')->where('ticket_id', (int) $t->id)
                ->orderByDesc('id')->limit(1)->update(['emailed' => 1]);
        } catch (\Throwable) { /* it went out; the flag is bookkeeping */ }

        try {
            WebhookService::dispatch('support.ticket_replied',
                ['reference' => (string) $t->reference, 'at' => date('c')]);
        } catch (\Throwable) {}

        return true;
    }

    // ── shaping ──────────────────────────────────────────────────────────────

    /**
     * How urgent this is, from what was actually said.
     *
     * Three levels, because more would be a taxonomy nobody triages by. `urgent`
     * exists so an operator's automation can route money and safety differently
     * from "how do I change my name" at three in the morning.
     */
    public static function severity(string $message): string
    {
        $m = mb_strtolower($message);

        foreach (['fraud', 'scam', 'stolen', 'unauthorised', 'unauthorized', 'hacked',
                  'child', 'abuse', 'threat', 'harass', 'lawyer', 'sue', 'police'] as $w) {
            if (str_contains($m, $w)) return 'urgent';
        }
        foreach (['refund', 'charged twice', 'double charged', 'money', 'debited',
                  'payment failed', 'not received', 'missing'] as $w) {
            if (str_contains($m, $w)) return 'high';
        }
        return 'normal';
    }

    /** A subject line from the first sentence, so a queue is readable at a glance. */
    public static function subjectFrom(string $message): string
    {
        $flat = trim((string) preg_replace('/\s+/u', ' ', $message));
        if ($flat === '') return 'Support request';
        if (preg_match('/^(.{10,90}?[.!?])\s/u', $flat, $m)) return rtrim($m[1], '.!? ');
        if (mb_strlen($flat) <= 90) return $flat;
        $cut = mb_substr($flat, 0, 90);
        $sp  = mb_strrpos($cut, ' ');
        return rtrim($sp !== false && $sp > 40 ? mb_substr($cut, 0, $sp) : $cut) . '…';
    }

    /**
     * The stored transcript, read back as the conversation it was.
     *
     * {@see transcript()} freezes what was said before escalation as one blob of
     * "User: …" / "Support: …" lines — the right storage shape, because it is a
     * SNAPSHOT and must not drift as the thread grows. But rendering that blob as
     * a single message attributed to the member is wrong twice over: it prints
     * the literal word "User:" at somebody who knows perfectly well who they are,
     * and it puts the assistant's earlier replies in their mouth.
     *
     * Parsed here rather than in the template because a template that does this
     * is a template doing string surgery on stored data, and the next person to
     * change the storage format will not think to look there.
     *
     * @return list<array{staff:bool, body:string}>
     */
    public static function opening(string $transcript): array
    {
        $text = trim($transcript);
        if ($text === '') return [];

        // No labels at all — a ticket raised directly rather than escalated from a
        // conversation. One turn, theirs, exactly as they wrote it.
        if (!preg_match('/^(User|Support):\s/m', $text)) {
            return [['staff' => false, 'body' => $text]];
        }

        $turns = [];
        foreach (preg_split('/\n\n+/', $text) ?: [] as $block) {
            $block = trim($block);
            if ($block === '') continue;
            if (preg_match('/^(User|Support):\s*(.*)$/s', $block, $m)) {
                $turns[] = ['staff' => $m[1] === 'Support', 'body' => trim($m[2])];
            } elseif ($turns !== []) {
                // A continuation line of the previous turn — a paragraph break
                // inside one message, not a new speaker.
                $turns[count($turns) - 1]['body'] .= "\n\n" . $block;
            } else {
                $turns[] = ['staff' => false, 'body' => $block];
            }
        }
        return array_values(array_filter($turns, static fn($t) => $t['body'] !== ''));
    }

    /** @param list<array{role:string,content:string}> $history */
    private static function transcript(array $history, string $latest): string
    {
        $lines = [];
        foreach (array_slice($history, -20) as $h) {
            $role = ($h['role'] ?? '') === 'assistant' ? 'Support' : 'User';
            $lines[] = $role . ': ' . trim((string) ($h['content'] ?? ''));
        }
        $lines[] = 'User: ' . trim($latest);
        return mb_substr(implode("\n\n", $lines), 0, 20000);
    }
}
