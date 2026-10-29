<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Services\Mail\SendPolicy;
use AfricaGates\Services\Newsletter\NewsletterAudience;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Africa GATES' people, pushed to the CACENTRE CRM's Audience.
 *
 * CACENTRE keeps one list of everybody the estate knows. This is this platform's half:
 * newsletter subscribers and what became of them, the bulk-mail opt-outs, bounces and
 * complaints, partner enquiries, support tickets, every confirmed gift or vote order, and
 * adult volunteers as they move from applying to active or leaving —
 * sent to CACENTRE's /api/crm-intake.php in the v1 envelope (HMAC-SHA256 over the payload
 * segment), signed with this platform's own secret.
 *
 * ── PUSHED, NEVER PULLED ───────────────────────────────────────────────────────
 * Nothing on CACENTRE can read this database. It learns what is sent, signed, and a
 * secret stolen for another site cannot forge a push from here.
 *
 * ── CURSORS MOVE ONLY ON A 200 ─────────────────────────────────────────────────
 * Each source keeps how far it has been sent, in `crm_push_state`, written only after
 * CACENTRE answered 200 with ok. A push that timed out is sent again on the next tick, and
 * the CRM's own key (site, kind, ref) makes the repeat an update, never a second person.
 *
 * ── A NEWSLETTER ROW'S STATE IS NewsletterAudience's ANSWER ────────────────────
 * Who may receive the newsletter has ONE definition and it lives there; a second reader is
 * how one of them mails somebody the other already excluded. So the state sent for a row
 * is NewsletterAudience::stateOf(), never re-derived here. An opt-out or a bounce that
 * arrives after the row was sent is caught by cursors on those two tables and re-sends the
 * newsletter row it belongs to — the stop is the update that matters most.
 *
 * ── WHAT NEVER LEAVES ──────────────────────────────────────────────────────────
 * Nominees (some are children, and a nominee did not give us their address to be
 * marketed to), who anybody voted for, message bodies and ticket transcripts, and any
 * address at a reserved domain — which is what keeps the sandbox's `@demo.invalid` rows on
 * this side, by the same rule the mail transport uses (SendPolicy::reserved()).
 */
final class CrmAudiencePush
{
    public const APP   = 'gates';
    public const BATCH = 500;
    public const DEFAULT_BASE = 'https://cacentre.afrovanguard.org.ng';
    private const STATE = 'crm_push_state';
    private const LAST  = 'crm_push_last';
    private const MAX_PUSHES = 6;
    /** The cursor before anything was sent. A real datetime, not '': strict MySQL warns on '' against a TIMESTAMP. */
    private const EPOCH = '1970-01-02 00:00:00';

    /** For tests: fn(string $url, string $body): array{code:int, body:string}. */
    public static $transport = null;

    // ══ settings — the one resolver for each ═════════════════════════════════

    /** The secret CACENTRE generated for Africa GATES: Settings first, then .env. */
    public static function secret(): string
    {
        return self::resolve('crm_intake_secret', 'CRM_INTAKE_SECRET');
    }

    /** CACENTRE's address: Settings, then .env, then the production one. */
    public static function base(): string
    {
        $v = rtrim(self::resolve('crm_intake_url', 'CACENTRE_URL'), '/');
        $p = $v !== '' ? parse_url($v) : null;
        $ok = is_array($p) && in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true)
            && !empty($p['host']) && empty($p['user']) && empty($p['query']) && empty($p['path']);
        return $ok ? $v : self::DEFAULT_BASE;
    }

    public static function url(): string { return self::base() . '/api/crm-intake.php'; }

    public static function ready(): bool { return self::secret() !== ''; }

    /** The last result, for the settings screen. Empty when it has never run. */
    public static function lastResult(): string
    {
        try { return (string) (DB::table('gates_settings')->where('key_name', self::LAST)->value('value') ?? ''); }
        catch (\Throwable) { return ''; }
    }

    private static function resolve(string $key, string $env): string
    {
        $v = null;
        try { $v = DB::table('gates_settings')->where('key_name', $key)->value('value'); }
        catch (\Throwable) { /* no database yet: the env fallback is the point of having one */ }
        $v = trim((string) ($v ?? ''));
        return $v !== '' ? $v : trim((string) \AfricaGates\Support\Env::get($env, ''));
    }

    // ══ the envelope ═════════════════════════════════════════════════════════

    /** A push, signed exactly as CACENTRE's CrmAudience::verifyPush() reads it. */
    public static function sign(array $rows, string $secret, ?int $now = null): string
    {
        $now ??= time();
        $payload = self::b64((string) json_encode(
            ['app' => self::APP, 'iat' => $now, 'exp' => $now + 300, 'nonce' => bin2hex(random_bytes(12)), 'rows' => $rows],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return 'v1.' . $payload . '.' . self::b64(hash_hmac('sha256', $payload, $secret, true));
    }

    // ══ what is sent ═════════════════════════════════════════════════════════

    /**
     * Up to $limit rows past every source's cursor, and the state they move to. Writes
     * nothing.
     *
     * @return array{rows:list<array<string,string>>, state:array<string,string>}
     */
    public static function collect(int $limit = self::BATCH): array
    {
        $state = self::state();
        $next  = $state;
        $rows  = [];
        $optOut = EmailOptOut::suppressedHashes();
        $bounce = Mail\Suppression::hashes();

        $add = static function (array $row) use (&$rows): void {
            if (($row['email'] ?? '') !== '' && SendPolicy::reserved((string) $row['email'])) return;
            $rows[] = $row;
        };
        $room = static function () use (&$rows, $limit): int { return $limit - count($rows); };

        // The newsletter, by id — and the rows a later opt-out or bounce changed.
        $refresh = [];
        foreach (DB::table('gates_email_optout')->where('id', '>', (int) ($state['optout.id'] ?? 0))
                     ->orderBy('id')->limit($limit)->get(['id', 'email_hash']) as $o) {
            $refresh[(string) $o->email_hash] = true;
            $next['optout.id'] = (string) $o->id;
        }
        foreach (DB::table('gates_mail_suppression')->where('last_at', '>', (string) ($state['suppression.at'] ?? self::EPOCH))
                     ->orderBy('last_at')->limit($limit)->get(['email_hash', 'last_at']) as $s) {
            $refresh[(string) $s->email_hash] = true;
            $next['suppression.at'] = (string) $s->last_at;
        }
        $nid = (int) ($state['newsletter.id'] ?? 0);
        $q = DB::table('gates_newsletter')->where(function ($w) use ($nid, $refresh, $state) {
            $w->where('id', '>', $nid)
              // A confirmation or a list stop on a row already sent.
              ->orWhere('confirmed_at', '>', (string) ($state['newsletter.changed'] ?? self::EPOCH))
              ->orWhere('unsubscribed_at', '>', (string) ($state['newsletter.changed'] ?? self::EPOCH));
            if ($refresh) $w->orWhereIn('email_hash', array_keys($refresh));
        })->orderBy('id')->limit(max(0, $room()));
        foreach ($q->get() as $r) {
            $isSub = NewsletterAudience::isSubscription($r->source ?? null);
            $add($isSub
                ? ['kind' => 'subscriber', 'ref' => 'news:' . $r->id, 'email' => (string) $r->email,
                   'list' => 'Africa GATES newsletter',
                   'status' => NewsletterAudience::stateOf($r, $optOut, $bounce),
                   'at' => self::at($r->confirmed_at ?? $r->subscribed_at ?? null)]
                // "Email me when the call opens" asked about ONE event; it is an enquiry, not a list.
                : ['kind' => 'enquiry', 'ref' => 'stand:' . $r->id, 'email' => (string) $r->email,
                   'list' => 'Stand call alerts', 'status' => 'active', 'at' => self::at($r->subscribed_at ?? null)]);
            $next['newsletter.id'] = (string) max((int) $r->id, (int) ($next['newsletter.id'] ?? 0));
            foreach (['confirmed_at', 'unsubscribed_at'] as $c) {
                if (($r->{$c} ?? null) !== null && (string) $r->{$c} > (string) ($next['newsletter.changed'] ?? '')) {
                    $next['newsletter.changed'] = (string) $r->{$c};
                }
            }
        }

        // Partner enquiries, by id. The message stays here.
        if ($room() > 0) {
            foreach (DB::table('gates_partner_enquiries')->where('id', '>', (int) ($state['partners.id'] ?? 0))
                         ->orderBy('id')->limit($room())->get() as $r) {
                $next['partners.id'] = (string) $r->id;
                $add(['kind' => 'enquiry', 'ref' => 'partner:' . $r->id, 'email' => (string) $r->contact_email,
                      'phone' => (string) ($r->contact_phone ?? ''), 'name' => (string) $r->contact_name,
                      'list' => 'Partner enquiries', 'status' => in_array($r->status, ['closed'], true) ? 'closed' : 'active',
                      'detail' => trim((string) $r->org_name . ' · ' . (string) ($r->partnership_type ?? ''), ' ·'),
                      'at' => self::at($r->created_at)]);
            }
        }

        // Support tickets, by id. The subject, never the transcript.
        if ($room() > 0) {
            foreach (DB::table('gates_support_tickets')->where('id', '>', (int) ($state['tickets.id'] ?? 0))
                         ->orderBy('id')->limit($room())->get(['id', 'reference', 'email', 'name', 'subject', 'created_at']) as $r) {
                $next['tickets.id'] = (string) $r->id;
                if (trim((string) ($r->email ?? '')) === '') continue;
                $add(['kind' => 'enquiry', 'ref' => 'ticket:' . $r->reference, 'email' => (string) $r->email,
                      'name' => (string) ($r->name ?? ''), 'list' => 'Support', 'status' => 'active',
                      'detail' => mb_substr((string) $r->subject, 0, 200), 'at' => self::at($r->created_at)]);
            }
        }

        // Confirmed gifts and vote orders, by when they were confirmed — and again when refunded.
        if ($room() > 0) {
            $conf = (string) ($state['donations.confirmed'] ?? self::EPOCH);
            $ref  = (string) ($state['donations.refunded'] ?? self::EPOCH);
            foreach (DB::table('gates_donations')->where('status', 'confirmed')
                         ->where(fn($w) => $w->where('confirmed_at', '>', $conf)->orWhere('refunded_at', '>', $ref))
                         ->orderBy('confirmed_at')->orderBy('id')->limit($room())->get() as $r) {
                $votes  = $r->intent_nominee_id !== null || (int) $r->bonus_votes > 0;
                $refund = $r->refunded_at !== null;
                $add(['kind' => $votes ? 'customer' : 'donor', 'ref' => 'gift:' . $r->id, 'email' => (string) $r->donor_email,
                      'phone' => (string) ($r->donor_phone ?? ''), 'name' => (string) $r->donor_name,
                      'list' => $votes ? 'Vote purchases' : 'Donors', 'status' => $refund ? 'closed' : 'active',
                      'detail' => ($votes ? 'Bought votes, ₦' : 'Gave ₦') . number_format((int) $r->amount_naira) . ($refund ? ' — refunded' : ''),
                      'at' => self::at($r->confirmed_at ?? $r->created_at)]);
                if ((string) ($r->confirmed_at ?? '') > (string) ($next['donations.confirmed'] ?? '')) $next['donations.confirmed'] = (string) $r->confirmed_at;
                if ($refund && (string) $r->refunded_at > (string) ($next['donations.refunded'] ?? '')) $next['donations.refunded'] = (string) $r->refunded_at;
            }
        }

        // Volunteers, by when their record last changed: one row per person, kept as they move
        // from applying to active, a break, or leaving, so CACENTRE updates one contact rather
        // than gathering a twin at every step. Name, address and phone, and where they are in
        // the process. Never their date of birth, referee, emergency contact, availability or
        // hours. And nobody under 18 at all: the same reason nominees never leave.
        if ($room() > 0 && \AfricaGates\Support\SchemaHas::table('gates_volunteers')) {
            $at = (string) ($state['volunteers.at'] ?? self::EPOCH);
            $aid = (int) ($state['volunteers.id'] ?? 0);
            $adult = date('Y-m-d', strtotime('-18 years'));
            foreach (DB::table('gates_volunteers as v')->join('gates_users as u', 'u.id', '=', 'v.user_id')
                         ->whereNotNull('v.updated_at')
                         ->where(fn($w) => $w->where('v.updated_at', '>', $at)->orWhere(fn($o) => $o->where('v.updated_at', $at)->where('v.id', '>', $aid)))
                         ->orderBy('v.updated_at')->orderBy('v.id')->limit($room())
                         ->get(['v.id', 'v.status', 'v.dob', 'v.display_name', 'v.updated_at', 'v.applied_at', 'u.name', 'u.email']) as $r) {
                $next['volunteers.at'] = (string) $r->updated_at;
                $next['volunteers.id'] = (string) $r->id;
                if ($r->dob === null || (string) $r->dob > $adult) continue;
                $add(['kind' => 'volunteer', 'ref' => 'vol:' . $r->id, 'email' => (string) $r->email,
                      'name' => trim((string) ($r->display_name ?: $r->name)), 'list' => 'Africa GATES volunteers',
                      'status' => in_array((string) $r->status, ['left', 'declined'], true) ? 'closed' : 'active',
                      'detail' => self::VOLUNTEER_STAGE[(string) $r->status] ?? 'Volunteer',
                      'at' => self::at($r->applied_at ?? $r->updated_at)]);
            }
        }

        return ['rows' => $rows, 'state' => $next];
    }

    /** Where a volunteer is, in the words the CRM shows beside them. */
    private const VOLUNTEER_STAGE = [
        'applicant' => 'Applied to volunteer', 'active' => 'Active volunteer', 'paused' => 'On a break',
        'left' => 'Stepped back', 'declined' => 'Application not taken forward',
    ];

    // ══ sending ══════════════════════════════════════════════════════════════

    /**
     * Send what is waiting, a batch at a time, until it is sent or a push fails. Called by
     * the maintenance tick; a platform with no secret sends nothing and is not a failure.
     *
     * @return int rows sent, or Maintenance::TASK_FAILED (-1)
     */
    public static function sweep(): int
    {
        $r = self::run();
        return $r['ok'] ? (int) $r['sent'] : -1;
    }

    /** @return array{ok:bool, sent:int, refused?:int, error?:string, skipped?:bool} */
    public static function run(): array
    {
        if (!self::ready()) return ['ok' => true, 'skipped' => true, 'sent' => 0];
        $sent = 0; $refused = 0;
        for ($i = 0; $i < self::MAX_PUSHES; $i++) {
            $before = self::state();
            $c = self::collect();
            if ($c['state'] === $before) break;
            if ($c['rows']) {
                $r = self::post($c['rows']);
                if (!$r['ok']) {
                    self::note('failed: ' . $r['error']);
                    return ['ok' => false, 'sent' => $sent, 'error' => $r['error']];
                }
                // A row CACENTRE refused BY NAME is not resent — it would be refused again
                // forever and hold everything behind it — but it is counted and said, never
                // reported as sent.
                $refused += count($r['refused']);
                if ($r['refused']) error_log('[crm-push] refused: ' . json_encode(array_slice($r['refused'], 0, 5)));
            }
            // Only now: CACENTRE has it (or nothing in the batch was ours to send).
            self::save(self::STATE, (string) json_encode($c['state']));
            $sent += count($c['rows']) - count($r['refused'] ?? []);
            if (count($c['rows']) < self::BATCH) break;
        }
        self::note('sent ' . $sent . ($refused ? ', ' . $refused . ' refused by CACENTRE (see the error log)' : ''));
        return ['ok' => true, 'sent' => $sent, 'refused' => $refused];
    }

    /** @return array{ok:bool, error?:string, refused?:list<array{ref:string,why:string}>} */
    private static function post(array $rows): array
    {
        $body = (string) json_encode(['token' => self::sign($rows, self::secret())], JSON_UNESCAPED_SLASHES);
        if (is_callable(self::$transport)) {
            $r = (self::$transport)(self::url(), $body);
        } elseif (function_exists('curl_init')) {
            $ch = curl_init(self::url());
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
            $out = curl_exec($ch);
            $r = ['code' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => is_string($out) ? $out : '', 'error' => curl_error($ch)];
            curl_close($ch);
        } else {
            return ['ok' => false, 'error' => 'This server has no curl.'];
        }
        $code = (int) ($r['code'] ?? 0);
        $j = json_decode((string) ($r['body'] ?? ''), true);
        if ($code === 200 && is_array($j) && !empty($j['ok'])) {
            return ['ok' => true, 'refused' => array_values(array_filter((array) ($j['refused'] ?? []), 'is_array'))];
        }
        $err = is_array($j) ? (string) ($j['error'] ?? '') : '';
        return ['ok' => false, 'error' => match (true) {
            $err === 'bad-signature'  => 'CACENTRE refused the signature — the secret here differs from the one it generated.',
            $err === 'not-configured' => 'CACENTRE has no secret for Africa GATES yet — generate one in its console under Audience → Sites.',
            $code === 0               => 'CACENTRE did not answer (' . (string) ($r['error'] ?? 'no connection') . ').',
            default                   => 'CACENTRE answered ' . $code . ($err !== '' ? ' (' . $err . ')' : '') . '.',
        }];
    }

    /** @return array<string,string> */
    public static function state(): array
    {
        try {
            $v = json_decode((string) (DB::table('gates_settings')->where('key_name', self::STATE)->value('value') ?? ''), true);
            return is_array($v) ? array_map('strval', $v) : [];
        } catch (\Throwable) { return []; }
    }

    private static function note(string $what): void
    {
        self::save(self::LAST, gmdate('Y-m-d H:i') . ' UTC · ' . mb_substr($what, 0, 200));
    }

    /** `updated_by` stays NULL: there is no admin 0, and MySQL's foreign key refuses one. */
    private static function save(string $key, string $value): void
    {
        try {
            DB::table('gates_settings')->updateOrInsert(['key_name' => $key],
                ['value' => $value, 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => null]);
        } catch (\Throwable $e) { error_log('[crm-push] ' . $e->getMessage()); }
    }

    private static function at($v): string
    {
        $t = $v !== null ? strtotime((string) $v) : false;
        return $t ? gmdate('Y-m-d H:i:s', $t) : '';
    }

    private static function b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
}
