<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\Phone;
use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * The text somebody gets for walking in — thanks, and what is open to them.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY THE DOOR IS THE RIGHT MOMENT, AND THE HARDEST ONE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * An attendee has just paid, travelled and queued. It is the only moment in the whole
 * relationship where the platform knows for certain that this person showed up — not
 * clicked, not registered, showed up — and it is the moment they are most disposed to
 * hear what else they could be part of.
 *
 * It is also a person standing at a door with a queue behind them, which decides almost
 * every design choice below.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * QUEUED, NEVER SENT INLINE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see SmsService} is synchronous with an eight-second timeout and a retry. At a form
 * submit that is fine. At a door it is eight seconds of a steward staring at a spinner
 * with forty people waiting, and a gateway having a bad afternoon turns a check-in queue
 * into a standstill.
 *
 * So this pushes a `notify.sms` job and returns. The job type and its handler already
 * exist — {@see \AfricaGates\Support\Maintenance} registers it — so nothing new has to
 * run for this to be delivered, and nothing waits at the door for it either.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IS DELIBERATELY NOT IN IT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * No list of opportunities. An SMS is one segment before it starts costing double and
 * being cut by handsets, and three truncated titles help nobody: the message carries a
 * COUNT and one link, because the count is what makes somebody open the link.
 *
 * No opportunity is named even when there is exactly one, because the copy would then
 * change shape with the data and an operator editing it could not tell what they were
 * editing.
 *
 * And it goes to nobody who has not left a phone number, opted out, or already been
 * texted for this event — see {@see for()}.
 */
final class CheckInThanks
{
    /** Master switch. Off until an operator turns it on, like every outbound channel. */
    public const K_ENABLED = 'checkin_sms_enabled';

    /** The operator's own words. Empty means the default below. */
    public const K_TEMPLATE = 'checkin_sms_template';

    /**
     * The shipped copy.
     *
     * `{name}` is the first name only, `{count}` the number of open opportunities and
     * `{url}` the page they are on. The STOP line is not optional and is appended by
     * {@see compose()} rather than being part of this, so an operator rewriting the
     * message cannot delete the way out of it.
     */
    public const DEFAULT_TEMPLATE =
        'Thank you for being here, {name}. Africa GATES runs on people who show up. '
      . '{count} opportunities are open to you right now: {url}';

    /** Same, for a night when nothing is open. Naming zero opportunities is worse than not mentioning them. */
    public const DEFAULT_TEMPLATE_BARE =
        'Thank you for being here, {name}. Africa GATES runs on people who show up. '
      . 'What we have open is always at {url}';

    /** The job the queue already knows how to deliver. */
    private const TEMPLATE_TAG = 'checkin-thanks';

    public static function enabled(): bool
    {
        try {
            return trim((string) DB::table('gates_settings')
                ->where('key_name', self::K_ENABLED)->value('value')) === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Queue the thank-you for one registration, if it is owed one.
     *
     * Returns false for every ordinary reason not to send — that is not a failure and no
     * caller may treat it as one. The door has already admitted the person; nothing about
     * this may change what the steward sees.
     *
     * @param object $reg a `gates_event_registrations` row, already checked in
     */
    public static function queue(object $reg, ?object $event = null): bool
    {
        if (!self::enabled()) return false;

        $phone = Phone::normalize((string) ($reg->phone ?? ''));
        if ($phone === null) return false;

        // Checked here as well as inside SmsService. Not belt-and-braces: this saves
        // writing a queue row that can only ever be discarded, and a queue full of jobs
        // that will never send is a queue nobody trusts the depth of.
        if (SmsOptOut::suppressed($phone)) return false;

        $body = self::compose($reg, $event);
        if ($body === '') return false;

        try {
            (new QueueService())->push(
                SmsService::JOB_SMS,
                ['to' => $phone, 'body' => $body, 'template' => self::TEMPLATE_TAG],
                0,
                // ── ONE TEXT PER TICKET, FOREVER ────────────────────────────
                //
                // A dedupe key on the registration, not on the phone number and not on
                // the event: a family of four on one ticket is one person's number, and
                // two people who share a phone are two tickets and two texts.
                //
                // It matters because a re-scan is ordinary — a steward checks a code
                // twice, a duplicate scan races another handset — and EventTicketService
                // returns `duplicate` for those, which this is never called for. The key
                // is the belt on that: if some later path ever calls this twice, the
                // person still gets one message.
                'checkin-thanks:' . (int) $reg->id
            );

            return true;
        } catch (\Throwable) {
            // No queue is not a broken door.
            return false;
        }
    }

    /**
     * The message, with the operator's copy and the STOP line they cannot delete.
     *
     * @param object $reg a `gates_event_registrations` row
     */
    public static function compose(object $reg, ?object $event = null): string
    {
        $open = self::openCount();
        $base = rtrim((string) SiteUrl::base(), '/');

        $tpl = trim((string) self::setting(self::K_TEMPLATE));
        if ($tpl === '') {
            $tpl = $open > 0 ? self::DEFAULT_TEMPLATE : self::DEFAULT_TEMPLATE_BARE;
        }

        $body = strtr($tpl, [
            // FIRST NAME ONLY. "Thank you for being here, Dr Adaeze Nwosu-Okonkwo" reads
            // like a database, and the field holds whatever somebody typed at checkout.
            '{name}'  => self::firstName((string) ($reg->name ?? '')),
            '{count}' => (string) $open,
            '{url}'   => $base . '/opportunities',
            '{event}' => trim((string) ($event->title ?? '')),
        ]);

        // Appended, never templated. An operator rewriting the message must not be able to
        // remove the only thing that makes it stoppable — and a text with no way out is
        // the one that gets the platform's sending number blocked.
        return trim(preg_replace('/\s+/', ' ', $body) ?? '') . ' Reply STOP to end texts.';
    }

    /**
     * How many opportunities are actually open to somebody today.
     *
     * The same rule the public page uses — active, and either no deadline or one that has
     * not passed. A count that included closed rows would send somebody to a page with
     * fewer things on it than the text promised, which is worse than sending no text.
     */
    public static function openCount(): int
    {
        try {
            return DB::table('gates_opportunities')
                ->where('status', 'active')
                ->where(static fn ($q) => $q->whereNull('deadline')
                                            ->orWhere('deadline', '>=', Carbon::now()->toDateString()))
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** The part of a name a person is called by. */
    private static function firstName(string $full): string
    {
        $first = trim(explode(' ', trim($full))[0] ?? '');

        // A title is not a name. "Thank you for being here, Dr" is worse than no name.
        if (in_array(mb_strtolower(rtrim($first, '.')), ['mr', 'mrs', 'ms', 'dr', 'prof', 'chief', 'engr'], true)) {
            $parts = preg_split('/\s+/', trim($full)) ?: [];
            $first = trim($parts[1] ?? '');
        }

        return $first !== '' ? $first : 'friend';
    }

    private static function setting(string $key): string
    {
        try {
            return (string) DB::table('gates_settings')->where('key_name', $key)->value('value');
        } catch (\Throwable) {
            return '';
        }
    }
}
