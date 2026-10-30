<?php
declare(strict_types=1);

namespace AfricaGates\Services\Volunteer;

use AfricaGates\Services\NomineeDetails;
use AfricaGates\Services\OtpService;
use AfricaGates\Support\BroadcastLog;
use AfricaGates\Support\SchemaHas;
use AfricaGates\Support\SiteUrl;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Nominee outreach: a volunteer reaches the nominees of an award they help on, one person at a
 * time, and logs what was said (owner, 30 Oct 2026). It replaced "Contacts", which logged
 * members of the public met at events — not what the work is.
 *
 * ── WHO SEES A NOMINEE'S NUMBER ─────────────────────────────────────────────────
 *
 * A nominee's phone and email are given to us by a nominator, and later by the nominee. So they
 * are shown only to a volunteer holding the `vol.nominee_outreach` role AT THAT AWARD, aged 18 or
 * over, and only while the role is live. A volunteer checking nominations or running a voter
 * drive on the same award sees none of it: the role is given by staff, per award, like every
 * other one.
 *
 * ── ONE PERSON AT A TIME, NEVER A BROADCAST ─────────────────────────────────────
 *
 * The owner: no broadcast. There is no "message everybody", no selection, no template sent to
 * a list. A volunteer writes to one nominee from that nominee's page. An email is capped per
 * volunteer per day, and a nominee gets at most one from the whole team a day, so nobody is
 * buried by six volunteers who each meant well. Calls and WhatsApp open on the volunteer's own
 * phone; the platform never dials or texts anybody.
 *
 * ── THE TEAM READS ONE HISTORY ──────────────────────────────────────────────────
 *
 * Every volunteer on the award sees every conversation with the nominee, newest first, with
 * who had it. That is what stops the second call the nominee did not need. "They asked not to be
 * contacted" is a conversation too, and from then on the contact details and every way to reach
 * them are gone for everybody on the award.
 *
 * ── THE RULES COME FIRST ────────────────────────────────────────────────────────
 *
 * The owner: the details "should only serve Africa GATES and the award only. No personal use,
 * no selling". So nothing about a nominee's contact is shown, and nothing can be logged or
 * sent, until the volunteer has agreed to TERMS for that award, and the agreement is kept with
 * its time. Change the wording and bump TERMS_VERSION: everybody agrees again to what it says
 * now, because an agreement to words nobody showed them is not an agreement.
 */
final class Outreach
{
    public const CAPABILITY = 'vol.nominee_outreach';

    /** How a conversation happened. */
    public const CHANNELS = [
        'call'      => 'Phone call',
        'whatsapp'  => 'WhatsApp',
        'sms'       => 'Text message',
        'email'     => 'Email',
        'in_person' => 'In person',
    ];

    /** How it went. `stop` holds for the whole team. */
    public const OUTCOMES = [
        'reached'      => 'We spoke',
        'no_answer'    => 'No answer',
        'left_message' => 'Left a message',
        'wrong_number' => 'Wrong number or address',
        'stop'         => 'They asked not to be contacted',
    ];

    /** The rules on a nominee's details, as [rule, why]. Shown before anything, and on every nominee page. */
    public const TERMS = [
        ['Only for Africa GATES and this award', 'Use them to help nominees with their nomination and profile. Nothing else.'],
        ['Never for yourself or anyone else', 'Not for your business, church, campaign, job, a friend or another award.'],
        ['Never sell, share or post them', 'Not to a person, a group, a list or an app.'],
        ['Don’t copy or keep them', 'Reach nominees from here. Delete any number you saved for a call once the award ends.'],
        ['Your access ends with your role', 'Misuse ends your volunteering and may be reported under Nigeria’s data protection law.'],
    ];

    /** Bump when TERMS change, so everybody agrees to the words as they now are. */
    public const TERMS_VERSION = 1;

    /** Days until the follow-up when none is picked, by outcome. The rest have none. */
    public const RETRY_DAYS = ['no_answer' => 2, 'left_message' => 2];

    /** Emails a volunteer may send in a day. A busy day of follow-ups, not a list. */
    public const EMAILS_PER_DAY = 25;

    public static function ready(): bool
    {
        return SchemaHas::table('gates_vol_outreach');
    }

    /** Has this person agreed to TERMS, as they now read, for this award? */
    public static function agreed(object $v, int $cycleId): bool
    {
        if (!SchemaHas::table('gates_vol_outreach_terms')) return false;
        return DB::table('gates_vol_outreach_terms')->where('volunteer_id', $v->id)->where('cycle_id', $cycleId)
            ->where('version', self::TERMS_VERSION)->exists();
    }

    /** Agree to TERMS for an award this person reaches nominees on. */
    public static function agree(object $v, int $cycleId): bool
    {
        if (!isset(self::awards($v)[$cycleId]) || !SchemaHas::table('gates_vol_outreach_terms')) return false;
        self::reset();
        DB::table('gates_vol_outreach_terms')->insertOrIgnore(['volunteer_id' => (int) $v->id, 'cycle_id' => $cycleId,
            'version' => self::TERMS_VERSION, 'agreed_at' => date('Y-m-d H:i:s')]);
        return true;
    }

    /**
     * The awards this person reaches nominees on: cycle id => name.
     *
     * @return array<int,string>
     */
    public static function awards(object $v): array
    {
        if (isset(self::$awards[(int) $v->id])) return self::$awards[(int) $v->id];
        $out = [];
        if (self::ready() && Meetings::adult($v) && in_array(Volunteers::role($v), ['new', 'active', 'lead'], true)) {
            foreach (Workspace::places($v) as $p) {
                if ($p['kind'] === 'cycle' && in_array(self::CAPABILITY, $p['caps'], true)) $out[(int) $p['id']] = (string) $p['name'];
            }
        }
        return self::$awards[(int) $v->id] = $out;
    }

    /**
     * Per request, per volunteer. One page asks for the list up to five times — the rail's count,
     * Today, Find, the tab's count and the list itself — and each used to be a fresh read of every
     * nominee and every conversation. Any write here clears it; tests clear it in setUp().
     *
     * @var array<int,array<int,string>>
     */
    private static array $awards = [];
    /** @var array<int,list<object>> */
    private static array $list = [];

    public static function reset(): void
    {
        self::$awards = [];
        self::$list = [];
    }

    /**
     * Nominees on those awards, follow-ups due first, then nobody-has-tried-yet, then the rest.
     * `cycle` narrows to one award; `show` is '' (all), 'due' or 'new'.
     *
     * @return list<object> each with ->cycle_id, ->award, ->category, ->last (a conversation or null), ->due (bool), ->stopped (bool)
     */
    public static function nominees(object $v, int $cycle = 0, string $q = '', string $show = ''): array
    {
        $rows = self::all($v);
        $q = mb_strtolower(trim($q));
        return array_values(array_filter($rows, static fn ($r) => (!$cycle || (int) $r->cycle_id === $cycle)
            && ($q === '' || str_contains(mb_strtolower((string) $r->name), $q))
            && ($show !== 'due' || $r->due) && ($show !== 'new' || !$r->last)));
    }

    /** Every nominee on this person's awards, dressed and in order: two queries, once a request. */
    private static function all(object $v): array
    {
        if (isset(self::$list[(int) $v->id])) return self::$list[(int) $v->id];
        $awards = self::awards($v);
        if (!$awards) return self::$list[(int) $v->id] = [];
        $rows = DB::table('gates_nominees as n')->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
            ->whereIn('c.cycle_id', array_keys($awards))->whereIn('n.status', ['approved', 'winner', 'runner_up'])->whereNull('n.merged_into')
            ->orderBy('n.name')->limit(3000)
            ->get(['n.id', 'n.name', 'n.organisation', 'c.cycle_id', 'c.title as category'])->all();
        $last = self::lastFor(array_keys($awards));
        $today = date('Y-m-d');
        foreach ($rows as $r) {
            $r->award = $awards[(int) $r->cycle_id] ?? '';
            $r->last = $last[(int) $r->id]['last'] ?? null;
            $r->stopped = $last[(int) $r->id]['stopped'] ?? false;
            $r->due = !$r->stopped && $r->last && $r->last->follow_up_on && (string) $r->last->follow_up_on <= $today;
        }
        usort($rows, static fn ($a, $b) => [$b->due, !$a->last && !$a->stopped, $a->stopped ? 1 : 0] <=> [$a->due, !$b->last && !$b->stopped, $b->stopped ? 1 : 0]
            ?: strcasecmp((string) $a->name, (string) $b->name));
        return self::$list[(int) $v->id] = $rows;
    }

    /** Follow-ups due today or earlier, across this person's awards. The rail's count. */
    public static function dueCount(object $v): int
    {
        return count(array_filter(self::nominees($v), static fn ($r) => $r->due));
    }

    /**
     * One nominee this person may reach, with their contact and the team's history; null when
     * the nominee is not on one of their awards.
     */
    public static function nominee(object $v, int $id): ?object
    {
        $awards = self::awards($v);
        if (!$awards || $id < 1) return null;
        $n = DB::table('gates_nominees as n')->join('gates_award_categories as c', 'c.id', '=', 'n.category_id')
            ->where('n.id', $id)->whereIn('c.cycle_id', array_keys($awards))->whereIn('n.status', ['approved', 'winner', 'runner_up'])->whereNull('n.merged_into')
            ->first(['n.id', 'n.name', 'n.organisation', 'n.country_code', 'n.photo_path', 'n.tagline', 'c.cycle_id', 'c.title as category']);
        if (!$n) return null;
        $n->award = $awards[(int) $n->cycle_id];
        $n->agreed = self::agreed($v, (int) $n->cycle_id);
        $n->history = self::history($id);
        $n->stopped = (bool) array_filter($n->history, static fn ($h) => $h->outcome === 'stop');
        $c = NomineeDetails::contactsFor([$id])[$id] ?? ['phones' => [], 'emails' => []];
        // Once they said stop, nobody on the award is shown a way to reach them; and nobody who
        // has not agreed to the rules for this award is shown one at all.
        $n->phones = $n->stopped || !$n->agreed ? [] : $c['phones'];
        $n->emails = $n->stopped || !$n->agreed ? [] : $c['emails'];
        $n->email_today = !$n->stopped && self::emailedToday($id);
        return $n;
    }

    /** Every conversation with a nominee, newest first, with the volunteer's name. @return list<object> */
    public static function history(int $nomineeId): array
    {
        if (!self::ready()) return [];
        $rows = DB::table('gates_vol_outreach as o')->join('gates_volunteers as v', 'v.id', '=', 'o.volunteer_id')
            ->where('o.nominee_id', $nomineeId)->orderByDesc('o.id')->limit(100)->get(['o.*', 'v.display_name', 'v.user_id'])->all();
        foreach ($rows as $r) $r->by = Volunteers::firstName($r);
        return $rows;
    }

    /**
     * Log a conversation.
     *
     * @return array{ok:bool, error?:string}
     */
    public static function log(object $v, int $nomineeId, array $f): array
    {
        $n = self::nominee($v, $nomineeId);
        if (!$n) return ['ok' => false, 'error' => 'That nominee isn’t on an award you reach out on.'];
        if (!$n->agreed) return ['ok' => false, 'error' => 'Agree to the rules for this award first.'];
        $channel = (string) ($f['channel'] ?? '');
        $outcome = (string) ($f['outcome'] ?? '');
        if (!isset(self::CHANNELS[$channel])) return ['ok' => false, 'error' => 'Say how you reached them.'];
        if (!isset(self::OUTCOMES[$outcome])) return ['ok' => false, 'error' => 'Say how it went.'];
        if ($n->stopped) return ['ok' => false, 'error' => 'They asked not to be contacted.'];
        $follow = self::followUp((string) ($f['follow_up_on'] ?? ''));
        if ($follow === false) return ['ok' => false, 'error' => 'Pick a follow-up date from today on, or leave it empty.'];
        // The outcome sets the next step, as a call log does: nobody picked up, so try again in two
        // days; a stop or a wrong number has no next step at all, whatever was typed.
        if ($follow === null && isset(self::RETRY_DAYS[$outcome])) $follow = date('Y-m-d', strtotime('+' . self::RETRY_DAYS[$outcome] . ' days'));
        if (in_array($outcome, ['stop', 'wrong_number'], true)) $follow = null;
        self::write($v, $n, $channel, $outcome, (string) ($f['note'] ?? ''), $follow);
        return ['ok' => true];
    }

    /**
     * Email one nominee, from the workspace. Logged as a conversation with what was sent.
     *
     * @param ?callable(string $to, string $subject, string $html, string $plain): bool $send for tests
     * @return array{ok:bool, error?:string}
     */
    public static function email(object $v, int $nomineeId, array $f, ?OtpService $mailer = null, ?callable $send = null): array
    {
        $n = self::nominee($v, $nomineeId);
        if (!$n) return ['ok' => false, 'error' => 'That nominee isn’t on an award you reach out on.'];
        if (!$n->agreed) return ['ok' => false, 'error' => 'Agree to the rules for this award first.'];
        if ($n->stopped) return ['ok' => false, 'error' => 'They asked not to be contacted.'];
        $to = strtolower(trim((string) ($f['to'] ?? '')));
        if (!in_array($to, $n->emails, true)) return ['ok' => false, 'error' => 'Pick one of their email addresses.'];
        $subject = trim((string) preg_replace('/\s+/u', ' ', (string) ($f['subject'] ?? '')));
        $body = trim(str_replace("\r\n", "\n", (string) ($f['body'] ?? '')));
        if ($subject === '' || mb_strlen($subject) > 150) return ['ok' => false, 'error' => 'Add a subject, up to 150 characters.'];
        if (mb_strlen($body) < 10 || mb_strlen($body) > 3000) return ['ok' => false, 'error' => 'Write the message: between 10 and 3,000 characters.'];
        if ($n->email_today) return ['ok' => false, 'error' => 'They’ve had an email from the team today. Try again tomorrow, or call.'];
        $sentToday = (int) DB::table('gates_vol_outreach')->where('volunteer_id', $v->id)->where('channel', 'email')->whereNotNull('body')
            ->where('created_at', '>=', date('Y-m-d 00:00:00'))->count();
        if ($sentToday >= self::EMAILS_PER_DAY) return ['ok' => false, 'error' => 'That’s ' . self::EMAILS_PER_DAY . ' emails today, the most in a day.'];
        $follow = self::followUp((string) ($f['follow_up_on'] ?? ''));
        if ($follow === false) return ['ok' => false, 'error' => 'Pick a follow-up date from today on, or leave it empty.'];
        $follow ??= date('Y-m-d', strtotime('+3 days'));   // time to read it and answer

        // Signed, and with the volunteer's own address so the nominee can answer a person.
        $me = Volunteers::firstName($v);
        $reply = (string) (DB::table('gates_users')->where('id', $v->user_id)->value('email') ?? '');
        $lines = array_values(array_filter(array_map('trim', explode("\n", $body)), static fn ($l) => $l !== ''));
        $sign = $me . ', Africa GATES volunteer, ' . $n->award;
        $answer = $reply !== '' ? 'To reply, write to ' . $me . ' at ' . $reply . '.' : '';
        $plain = $body . "\n\n" . $sign . ($answer !== '' ? "\n" . $answer : '');
        $html = implode('', array_map(static fn ($l) => '<p>' . htmlspecialchars($l) . '</p>', $lines))
              . '<p>' . htmlspecialchars($sign) . ($answer !== '' ? '<br>' . htmlspecialchars($answer) : '') . '</p>';

        $key = 'vol-out:' . $v->id . ':' . $nomineeId . ':' . date('Y-m-d');
        if (!BroadcastLog::claim($key, $to)) return ['ok' => false, 'error' => 'You’ve emailed them today already.'];
        $ok = false;
        try {
            $ok = $send ? (bool) $send($to, $subject, $html, $plain)
                        : ($mailer !== null && (($mailer->sendBranded($to, $subject, $html, $plain, 'Africa GATES')['success'] ?? false) === true));
        } catch (\Throwable) {}
        // A send that reported failure gave nothing to the nominee, so the volunteer may try again
        // today; one that went is settled, and the claim stops a double press sending it twice.
        $ok ? BroadcastLog::settle($key, $to, true) : BroadcastLog::release($key, $to);
        if (!$ok) return ['ok' => false, 'error' => 'It didn’t send. Nothing was logged. Try again in a minute, or call.'];
        self::write($v, $n, 'email', 'left_message', '', $follow, $subject, $body);
        return ['ok' => true];
    }

    /** A `tel:` link, and a WhatsApp one, for a stored number. */
    public static function dial(string $phone): array
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);
        return ['tel' => 'tel:+' . $digits, 'whatsapp' => 'https://wa.me/' . $digits];
    }

    private static function write(object $v, object $n, string $channel, string $outcome, string $note, ?string $follow, ?string $subject = null, ?string $body = null): void
    {
        self::reset();
        DB::table('gates_vol_outreach')->insert(['volunteer_id' => (int) $v->id, 'nominee_id' => (int) $n->id, 'cycle_id' => (int) $n->cycle_id,
            'channel' => $channel, 'outcome' => $outcome, 'note' => mb_substr(trim($note), 0, 1000) ?: null, 'follow_up_on' => $follow,
            'subject' => $subject, 'body' => $body, 'created_at' => date('Y-m-d H:i:s')]);
    }

    /** '' is no follow-up (null); a date before today is refused (false). */
    private static function followUp(string $raw): string|null|false
    {
        $raw = trim($raw);
        if ($raw === '') return null;
        $t = strtotime($raw);
        if ($t === false || date('Y-m-d', $t) < date('Y-m-d')) return false;
        return date('Y-m-d', $t);
    }

    private static function emailedToday(int $nomineeId): bool
    {
        return DB::table('gates_vol_outreach')->where('nominee_id', $nomineeId)->where('channel', 'email')->whereNotNull('body')
            ->where('created_at', '>=', date('Y-m-d 00:00:00'))->exists();
    }

    /**
     * The latest conversation with each nominee on these awards, and whether any said stop. Only
     * the newest row per nominee comes back (and the stop rows, which are few), never the history:
     * an award a year in has thousands of conversations, and a list needs one line from each.
     *
     * @param list<int> $cycles
     * @return array<int,array{last:?object, stopped:bool}>
     */
    private static function lastFor(array $cycles): array
    {
        if (!$cycles || !self::ready()) return [];
        $out = [];
        $newest = DB::table('gates_vol_outreach')->whereIn('cycle_id', $cycles)->groupBy('nominee_id')->selectRaw('MAX(id)');
        foreach (DB::table('gates_vol_outreach')->whereIn('id', $newest)
                     ->orWhere(static fn ($w) => $w->whereIn('cycle_id', $cycles)->where('outcome', 'stop'))->orderBy('id')->get() as $r) {
            $k = (int) $r->nominee_id;
            $out[$k] ??= ['last' => null, 'stopped' => false];
            $out[$k]['last'] = $r;
            if ($r->outcome === 'stop') $out[$k]['stopped'] = true;
        }
        return $out;
    }
}
