<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\EventTime;
use AfricaGates\Support\Name;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * "Ada, you are welcome." — in a Nigerian voice, with no wait at the door.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE WHOLE DESIGN IS THE WORD "AHEAD"
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every clip is rendered HOURS BEFORE the event, by a maintenance sweep, and written to
 * disk. At the door the check response carries a URL to a file that already exists; the
 * browser plays it. Scan-time cost is one filename lookup.
 *
 * The alternative — synthesise on the scan — was never a real option and it is worth saying
 * why, because it is the obvious way to build this. A door is a queue. Adding an HTTPS round
 * trip to a datacentre puts several hundred milliseconds between the scan and the verdict,
 * on venue wifi, on a phone with one bar, in front of forty people. It would also spend the
 * character budget on every duplicate scan, and fail exactly when the network is worst —
 * which is exactly when the queue is longest.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT IT SAYS, AND WHY IT IS THAT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * "You are welcome" and not "welcome" — the first is how the greeting is actually said in
 * Nigeria, and this is a Nigerian evening. FIRST NAME only: it is warmer, it is what a
 * steward would say, and it halves the chance of mangling a surname the voice has not met.
 *
 * A guest of honour is greeted differently, because being nominated for an award is not the
 * same arrival as buying a ticket to one.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND WHY THE CLIP IS BEHIND THE DOOR TOKEN
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The file SAYS A GUEST'S NAME ALOUD. Served from a public path it would be the attendee
 * list, in audio, for anybody who could guess a filename — and the door's own promise is
 * that it "cannot show you the guest list". So playback goes through the pass, exactly like
 * a check does.
 */
final class DoorWelcome
{
    /** Rendered per tick. A gala is a few hundred names and the sweep runs hourly. */
    public const CAP = 60;

    /** How far ahead a guest list is worth rendering. */
    public const LEAD_DAYS = 3;

    /** The clip everybody gets when their own is not ready — a walk-up, a late booking. */
    public const GENERIC = 'generic';

    /** Kept small: a name is one short sentence and this host has a disk quota. */
    private const KEEP_DAYS = 14;

    // ══ is it on ═════════════════════════════════════════════════════════════

    /**
     * Greeting people by name is opt-in per deployment, and off is a valid answer.
     *
     * A silent door is a working door. Nothing here may become a reason a person who has
     * paid does not get in.
     */
    public static function enabled(): bool
    {
        try {
            $v = DB::table('gates_settings')->where('key_name', 'door_welcome_enabled')->value('value');
            return is_string($v) && trim($v) === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    public static function ready(): bool
    {
        return self::enabled() && AzureVoice::configured() && self::dir() !== null;
    }

    // ══ the words ════════════════════════════════════════════════════════════

    /**
     * The greetings, in the order a Nigerian host would actually use them.
     *
     * ── WHY MORE THAN ONE ────────────────────────────────────────────────────
     *
     * A steward hears this four hundred times in an evening, and so does everybody
     * standing near the gate. One sentence on repeat stops being a welcome by the
     * fortieth guest and becomes a turnstile noise — which is the opposite of the point.
     *
     * Chosen DETERMINISTICALLY from the name, never at random: a re-scan has to say the
     * same thing (a guest hearing a different greeting on their second pass would wonder
     * what changed), and the cache key is the text, so a random pick would mint a new clip
     * and a new bill on every scan.
     *
     * `%s` is the name and {@see AzureVoice::PAUSE} the beat after it. Every one of these
     * is a real thing a person says at a door in Lagos; none is a translation of one.
     */
    private const FORMS = [
        '%s,' . AzureVoice::PAUSE . ' you are welcome.',
        '%s,' . AzureVoice::PAUSE . ' welcome. You are most welcome.',
        'Ah, %s.' . AzureVoice::PAUSE . ' You are welcome.',
        '%s,' . AzureVoice::PAUSE . ' we have been expecting you. You are welcome.',
    ];

    /** The line for a ticket holder. '' when there is no usable name. */
    public static function line(string $name, object|array|null $event = null): string
    {
        $first = self::firstName($name);
        if ($first === '') return '';

        $said = self::saidAs($first);
        $form = self::FORMS[self::pick($first, count(self::FORMS))];

        return self::greeting($event) . sprintf($form, $said);
    }

    /**
     * The line for somebody the evening is being held for.
     *
     * Never varied and never hurried. A nominee arriving at their own ceremony gets the
     * sentence that names why they are there, and hearing a different one from the person
     * beside them in the queue is exactly right.
     */
    public static function honourLine(string $name, string $role = '', object|array|null $event = null): string
    {
        $first = self::firstName($name);
        if ($first === '') return '';

        $said = self::saidAs($first);

        return $role !== ''
            ? self::greeting($event) . $said . ',' . AzureVoice::PAUSE
              . ' you are most welcome. Our ' . $role . ' this evening.'
            : self::greeting($event) . $said . ',' . AzureVoice::PAUSE . ' you are most welcome.';
    }

    /**
     * 'Good evening, ' — or nothing.
     *
     * Taken from the EVENT's start in the EVENT's own timezone, not from the clock at
     * render time. Clips are made hours or days ahead by a maintenance sweep, so "now" is
     * the middle of the night as often as not — and a gala in Nairobi is not greeted by
     * the hour in Lagos. With no event, or no start time, it says nothing rather than
     * guessing: a wrong time of day is worse than none.
     */
    public static function greeting(object|array|null $event): string
    {
        if ($event === null) return '';
        $e     = is_array($event) ? $event : (array) $event;
        $start = trim((string) ($e['event_date'] ?? ''));
        if ($start === '') return '';

        $h = (int) EventTime::at($event, $start, 'G');
        if ($h < 4 || $h >= 22) return '';   // nobody says "good night" to somebody arriving

        return match (true) {
            $h < 12 => 'Good morning. ',
            $h < 16 => 'Good afternoon. ',
            default => 'Good evening. ',
        };
    }

    /**
     * A name as it should be SAID, where somebody has told us.
     *
     * ── WHY THIS EXISTS ──────────────────────────────────────────────────────
     *
     * The whole point of a Nigerian voice is to say a Nigerian name properly, and a voice
     * that mangles one is worse than silence: it is the platform getting a person's name
     * wrong, out loud, at their own event, in front of a queue. Azure handles most of them
     * and there is no fix for the ones it does not — no shell on this host, and nothing to
     * edit.
     *
     * So: `Written = Spoken`, one per line, in settings. An operator hears a name go wrong
     * at rehearsal, writes `Ọláṣubọ̀mi = Ola-shu-BOR-mi`, re-runs the sweep, and it is
     * right on the night. Matched case-insensitively on the first name, because that is
     * what gets spoken.
     */
    private static function saidAs(string $first): string
    {
        $map = self::dictionary();
        $key = mb_strtolower($first);

        return $map[$key] ?? $first;
    }

    /** @return array<string,string> lower-cased written form => spoken form */
    public static function dictionary(): array
    {
        try {
            $raw = (string) (DB::table('gates_settings')
                ->where('key_name', 'door_welcome_says')->value('value') ?? '');
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (!str_contains($line, '=')) continue;
            [$written, $spoken] = array_map('trim', explode('=', $line, 2));
            // Both halves required: a written form with no spoken one would silently erase
            // the name rather than correct it, which is the worst outcome available here.
            if ($written === '' || $spoken === '') continue;
            $out[mb_strtolower($written)] = mb_substr($spoken, 0, 60);
        }

        return $out;
    }

    /** A stable index for this name — same person, same greeting, every time. */
    private static function pick(string $seed, int $of): int
    {
        return $of < 1 ? 0 : (int) (hexdec(substr(sha1(mb_strtolower($seed)), 0, 6)) % $of);
    }

    /**
     * The first name, title-cased.
     *
     * Rejects anything that is not plausibly a spoken name — a booking form takes free text,
     * and sending "N/A" or an email address to a voice engine costs characters and produces
     * something nobody wants to hear at a door.
     */
    public static function firstName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || str_contains($name, '@')) return '';

        $first = explode(' ', $name)[0] ?? '';
        // Letters, and the marks that belong inside real names. No digits: a "name" with a
        // number in it is a reference somebody pasted into the wrong box.
        if (preg_match('/^[\p{L}\'’\-]{2,40}$/u', $first) !== 1) return '';

        return Name::title($first);
    }

    // ══ the cache ════════════════════════════════════════════════════════════

    /**
     * The key for a line. Voice-scoped, so changing the voice in settings re-renders rather
     * than serving an evening in the old one.
     */
    public static function keyFor(string $line): string
    {
        return sha1(AzureVoice::voice() . '|' . AzureVoice::tidy($line));
    }

    public static function dir(): ?string
    {
        $dir = dirname(__DIR__, 2) . '/var/cache/door-welcome';
        if (is_dir($dir)) return is_writable($dir) ? $dir : null;

        return (@mkdir($dir, 0775, true) || is_dir($dir)) ? $dir : null;
    }

    public static function pathFor(string $key): ?string
    {
        $dir = self::dir();
        // The key comes off a URL. Anchored to the hash shape so nothing can walk out of the
        // cache directory with a crafted one.
        if ($dir === null || preg_match('/^[a-f0-9]{40}$/', $key) !== 1) return null;

        return $dir . '/' . $key . '.mp3';
    }

    /** Is this line already on disk? The only question the door ever asks. */
    public static function have(string $line): bool
    {
        if ($line === '') return false;
        $p = self::pathFor(self::keyFor($line));

        return $p !== null && is_file($p) && filesize($p) > 0;
    }

    /**
     * The key the door should play for this line, falling back to the generic clip.
     *
     * Never renders. Returns '' when there is nothing to play at all, and the page simply
     * stays silent — which is the correct outcome and not an error.
     */
    public static function keyToPlay(string $line): string
    {
        if (!self::enabled()) return '';
        if ($line !== '' && self::have($line)) return self::keyFor($line);

        $generic = self::genericLine();

        return self::have($generic) ? self::keyFor($generic) : '';
    }

    public static function genericLine(): string
    {
        return 'You are welcome.';
    }

    // ══ rendering, which happens hours early ═════════════════════════════════

    /**
     * Render one line into the cache if it is not there. True when the file exists after.
     *
     * NOT to be called from a request a person is waiting on. See the class note.
     */
    public static function render(string $line): bool
    {
        if ($line === '' || !AzureVoice::configured()) return false;
        if (self::have($line)) return true;

        $path = self::pathFor(self::keyFor($line));
        if ($path === null) return false;

        $mp3 = AzureVoice::say($line);
        if ($mp3 === null) return false;

        // Written to a temporary name and moved into place: a sweep that dies mid-write
        // would otherwise leave a truncated file that `have()` reports as ready, and a door
        // would play half a name for the rest of the evening.
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.part';
        if (@file_put_contents($tmp, $mp3) === false) return false;
        if (!@rename($tmp, $path)) { @unlink($tmp); return false; }

        return true;
    }

    /**
     * Render what the next few days of doors will need. Returns how many clips were made.
     *
     * @return int 0 means "ran, nothing to render" — the maintenance contract
     */
    public static function sweep(?int $cap = null): int
    {
        if (!self::ready()) return 0;

        $cap  = $cap ?? self::CAP;
        $made = 0;

        // The generic clip first, always. It is the one that covers every walk-up, every
        // late booking and every name the renderer could not use — so a door with only this
        // still greets everybody, and a door with none of it is silent.
        if (self::render(self::genericLine())) $made++;
        if ($made >= $cap) return $made;

        foreach (self::soonEvents() as $eventId) {
            foreach (self::linesFor($eventId) as $line) {
                if ($made >= $cap) return $made;
                if (self::have($line)) continue;
                if (self::render($line)) $made++;
            }
        }

        return $made;
    }

    /** The event row, for its start time and its zone. */
    public static function eventOf(int $eventId): ?object
    {
        try { return DB::table('gates_site_events')->where('id', $eventId)->first(); }
        catch (\Throwable) { return null; }
    }

    /** Events close enough that somebody will be standing at their door. @return list<int> */
    private static function soonEvents(): array
    {
        try {
            return DB::table('gates_site_events')
                ->where('status', 'published')
                ->whereBetween('event_date', [
                    Carbon::now()->subDay()->toDateTimeString(),
                    Carbon::now()->addDays(self::LEAD_DAYS)->toDateTimeString(),
                ])
                ->orderBy('event_date')
                ->pluck('id')->map(fn ($v) => (int) $v)->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Every line one event's door might need, deduplicated.
     *
     * Both audiences, because both walk through the same door and a gala where the ticket
     * holders are greeted and the nominees are not would be exactly backwards.
     *
     * @return list<string>
     */
    public static function linesFor(int $eventId): array
    {
        $out = [];
        // The event itself, because the greeting depends on it. Without this the sweep
        // renders "Ada, you are welcome" and the door asks for "Good evening. Ada, you are
        // welcome" — a different key, so every clip misses and every guest gets the
        // fallback, silently, on the night.
        $event = self::eventOf($eventId);

        try {
            foreach (DB::table('gates_event_registrations')
                ->where('event_id', $eventId)->where('status', 'confirmed')
                ->orderBy('id')->limit(2000)->pluck('name') as $n) {
                $l = self::line((string) $n, $event);
                if ($l !== '') $out[$l] = true;
            }
        } catch (\Throwable) { /* a door with no names still has its generic clip */ }

        try {
            foreach (DB::table('gates_event_invites')
                ->where('event_id', $eventId)->whereNotNull('sent_at')
                ->where('reference', 'not like', 'AGI-SAMPLE%')
                ->orderBy('id')->limit(1000)->get(['name', 'audience']) as $inv) {
                $spec = InviteAudience::spec((string) $inv->audience);
                $l = self::honourLine((string) $inv->name, strtolower((string) ($spec['one'] ?? '')), $event);
                if ($l !== '') $out[$l] = true;
            }
        } catch (\Throwable) { /* likewise */ }

        return array_keys($out);
    }

    /** How much of the free tier a given event would spend. For the admin screen. */
    public static function costOf(int $eventId): array
    {
        $lines = self::linesFor($eventId);
        $chars = 0;
        $ready = 0;
        foreach ($lines as $l) {
            $chars += mb_strlen($l);
            if (self::have($l)) $ready++;
        }

        return ['lines' => count($lines), 'ready' => $ready, 'chars' => $chars];
    }

    /** Housekeeping: last month's guest list is of no use to anybody. */
    public static function prune(int $days = self::KEEP_DAYS): int
    {
        $dir = self::dir();
        if ($dir === null) return 0;

        $cut = time() - max(1, $days) * 86400;
        $n   = 0;
        foreach (glob($dir . '/*.mp3') ?: [] as $f) {
            if (@filemtime($f) < $cut && @unlink($f)) $n++;
        }
        // Half-written files from a sweep that died. Cheap to clear and confusing to leave.
        foreach (glob($dir . '/*.part') ?: [] as $f) {
            if (@filemtime($f) < time() - 3600) @unlink($f);
        }

        return $n;
    }
}
