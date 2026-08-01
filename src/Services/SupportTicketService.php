<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Env;
use AfricaGates\Support\OptionalColumn;
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
            'severity'   => self::severity($message),
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
        $to = trim((string) (Env::get('SUPPORT_EMAIL') ?: Notifier::adminEmail()));
        if ($to === '' || $this->mailer === null) return false;

        $body = "A support conversation was escalated.\n\n"
              . "Reference: {$ref}\n"
              . "Severity:  {$row['severity']}\n"
              . "From:      " . ($row['name'] ?: 'a visitor') . ' <' . ($row['email'] ?: 'not signed in') . ">\n"
              . ($row['page_url'] ? "Page:      {$row['page_url']}\n" : '')
              . ($row['tools_used'] ? "Looked up: {$row['tools_used']}\n" : '')
              . "\n— — — conversation — — —\n\n" . $row['transcript'] . "\n";

        try {
            Notifier::adminAlert($this->mailer, "[{$row['severity']}] Support escalation {$ref}: " . $row['subject'], $body);
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
                ->get(['id', 'reference', 'subject', 'severity', 'status', 'created_at']);
        } catch (\Throwable $e) {
            // Loud. An empty ticket list and a broken ticket list look identical to
            // the reader, and the second one is the one somebody has to fix.
            error_log('[support] could not list tickets: ' . $e->getMessage());
            return [];
        }

        return $rows->map(fn($r) => [
            'id' => (int) $r->id, 'reference' => (string) $r->reference,
            'subject' => (string) $r->subject, 'severity' => (string) $r->severity,
            'status' => (string) $r->status, 'created_at' => (string) $r->created_at,
        ])->all();
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
                'transcript' => (string) ($t->transcript ?? ''), 'created_at' => (string) $t->created_at,
            ],
            'messages' => $msgs->map(fn($m) => [
                'from' => (string) $m->author_type === 'staff' ? 'Support' : ((string) ($m->author_name ?: 'You')),
                'staff' => (string) $m->author_type === 'staff',
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
        if ($thread === null)            return ['ok' => false, 'message' => 'That ticket is not on this account.'];

        try {
            $t = DB::table('gates_support_tickets')->where('reference', trim($reference))->first(['id', 'subject', 'status']);
            DB::table('gates_support_messages')->insert([
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
            Notifier::adminAlert($this->mailer, "Support reply on {$reference}: " . ($t->subject ?? ''),
                "A member replied on ticket {$reference}.\n\n" . $body);
        } catch (\Throwable) {}
        try {
            WebhookService::dispatch('support.ticket_replied',
                ['reference' => trim($reference), 'from' => 'member', 'at' => date('c')]);
        } catch (\Throwable) {}

        return ['ok' => true, 'message' => 'Reply added — the team has been notified.'];
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
