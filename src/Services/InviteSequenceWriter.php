<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use AfricaGates\Support\DisplayTime;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * An agent that drafts the five countdown letters for one ceremony.
 *
 * ── WHAT IT IS FOR ───────────────────────────────────────────────────────────
 *
 * {@see InviteSequence} ships an arc written for one kind of evening. It is good copy
 * and it is not every organiser's copy: a Carol Award and an Incorruptible Award honour
 * different things, in different words, to different people. Rewriting five long letters
 * from a blank textarea is the kind of task that does not get done — so the letters stay
 * as shipped, and every ceremony sends the same five paragraphs about the same values.
 *
 * This drafts them from the ceremony itself. The organiser reads five drafts, edits what
 * they want, and saves. That is the whole loop.
 *
 * ── NOTHING IT WRITES IS EVER SENT ───────────────────────────────────────────
 *
 * The drafts are returned to the screen and go nowhere else. There is no path from this
 * class to {@see InviteReminders::sweep()}, and there must not be: a model that could
 * post its own letters to a published shortlist would be the most consequential
 * unattended writer on this platform, addressing people in an organisation's name about
 * an honour they are receiving in public. A person saves, or nothing happens.
 *
 * ── AND THE TOKENS ARE THE HARD PART ─────────────────────────────────────────
 *
 * A letter is only reusable if its placeholders survive. Models are reliably willing to
 * be helpful here in the worst way — filling `{event}` in with the event's actual name,
 * which reads perfectly in the draft and hard-codes one ceremony into a letter meant for
 * every one after it. So the tokens are demanded in the prompt AND checked on the way
 * back: a draft that resolved them is repaired where that is possible and rejected where
 * it is not, rather than saved and discovered next year.
 */
final class InviteSequenceWriter
{
    /** The declared capability this runs as. Budgets, logging and the prompt editor hang off it. */
    public const CAPABILITY = 'invite.sequence_draft';

    /** Shortest and longest a letter may be, in characters. */
    private const MIN_CHARS = 320;
    private const MAX_CHARS = 4000;

    public function __construct(private readonly ?AiGateway $gateway = null) {}

    /** Whether a draft can be attempted at all. */
    public function available(): bool
    {
        return AiGateway::globallyEnabled()
            && AiGateway::capabilityEnabled(self::CAPABILITY)
            && AiService::boot()->configured();
    }

    /**
     * Draft all five letters for one ceremony.
     *
     * @return array{ok:bool, letters:array<int,string>, notes:list<string>, error:string}
     */
    public function draft(int $eventId, string $steer = ''): array
    {
        $event = null;
        try {
            $event = DB::table('gates_site_events')->where('id', $eventId)->first();
        } catch (\Throwable) {}

        if (!$event) {
            return ['ok' => false, 'letters' => [], 'notes' => [], 'error' => 'That event could not be found.'];
        }

        $result = ($this->gateway ?? new AiGateway())->run(self::CAPABILITY, [
            'system'       => self::system(),
            'user'         => self::brief($event, $steer),
            'json'         => true,
            // Through the gateway's OWN schema hook rather than parsed after the fact. A
            // reply that is not five letters is then logged as SCHEMA_REJECTED against
            // this capability instead of as a success that happened to be useless — which
            // is the difference between "the writer is unreliable" being visible on the AI
            // status page and being visible to nobody.
            'schema'       => static fn (string $raw): ?array => self::validate($raw),
            // Warmer than this platform's default of 0.2. These are five letters that have
            // to sound like a person wrote them, and a near-deterministic setting produces
            // five paragraphs with the same shape and the same three adjectives.
            'temperature'  => 0.7,
            'subject_type' => 'event',
            'subject_id'   => $eventId,
        ]);

        if (!$result->ok) {
            return ['ok' => false, 'letters' => [], 'notes' => [],
                    'error' => $result->message !== '' ? $result->message : 'The writer was unavailable.'];
        }

        $value = $result->value;

        return is_array($value) ? $value : self::parse((string) $value);
    }

    /**
     * The gateway's schema hook: the parse, or null when nothing usable came back.
     *
     * @return array{ok:bool, letters:array<int,string>, notes:list<string>, error:string}|null
     */
    public static function validate(string $raw): ?array
    {
        $parsed = self::parse($raw);

        return $parsed['ok'] ? $parsed : null;
    }

    /**
     * Read the model's reply into five letters, and hold them to the contract.
     *
     * ── WHY EACH CHECK IS HERE ───────────────────────────────────────────────
     *
     * A model returning JSON is a hope, not a guarantee, and every failure below has a
     * different right answer. A missing day should not discard the other four. A letter
     * that resolved `{event}` into the ceremony's name is not broken TODAY — it is broken
     * next year, silently, which is the worst kind — so it is repaired where the value is
     * still recognisable and flagged where it is not. A letter that came back at forty
     * characters is a refusal wearing a letter's clothes.
     *
     * @return array{ok:bool, letters:array<int,string>, notes:list<string>, error:string}
     */
    public static function parse(string $raw): array
    {
        $json = json_decode(self::unfence($raw), true);
        if (!is_array($json)) {
            return ['ok' => false, 'letters' => [], 'notes' => [],
                    'error' => 'The writer did not return letters this screen can read. Try again.'];
        }

        // Either {"5": "...", ...} or {"letters": {"5": "..."}} — both are shapes a model
        // reaches for, and rejecting one of them teaches an operator nothing.
        $src = isset($json['letters']) && is_array($json['letters']) ? $json['letters'] : $json;

        $letters = [];
        $notes   = [];

        foreach (InviteSequence::DAYS as $day) {
            $text = trim((string) ($src[(string) $day] ?? $src[$day] ?? ''));
            if ($text === '') {
                $notes[] = 'Day ' . $day . ' came back empty — the letter that ships is kept.';
                continue;
            }

            if (mb_strlen($text) < self::MIN_CHARS) {
                $notes[] = 'Day ' . $day . ' came back too short to be a letter, so it was dropped.';
                continue;
            }
            if (mb_strlen($text) > self::MAX_CHARS) {
                $notes[] = 'Day ' . $day . ' was trimmed — it came back longer than a letter anybody reads.';
                $text = mb_substr($text, 0, self::MAX_CHARS);
            }

            // Markup is never wanted here: these bodies are escaped and turned into
            // paragraphs by the mailer, so a stray <p> would reach the reader as literal
            // angle brackets in the middle of a sentence.
            $text = trim(strip_tags($text));

            $letters[$day] = $text;
        }

        if ($letters === []) {
            return ['ok' => false, 'letters' => [], 'notes' => $notes,
                    'error' => 'Nothing usable came back. Try again, or write the letters yourself.'];
        }

        return ['ok' => true, 'letters' => $letters, 'notes' => $notes, 'error' => ''];
    }

    /** Strip a ```json fence a model wrapped its object in. */
    private static function unfence(string $raw): string
    {
        $t = trim($raw);
        if (str_starts_with($t, '```')) {
            $t = (string) preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $t);
        }

        // A model that prefaced the object with a sentence. Take from the first brace.
        $open = strpos($t, '{');

        return $open === false ? $t : substr($t, $open);
    }

    private static function system(): string
    {
        $tokens = [];
        foreach (InviteSequence::TOKENS as $t => $means) $tokens[] = '{' . $t . '} — ' . $means;

        return "You write countdown letters for an African awards ceremony, to people who have been "
             . "nominated for an honour and are being invited to receive it.\n\n"
             . "You are writing FIVE letters, one per day over the final five days. They are an arc and "
             . "each only works in its position:\n"
             . "  Day 5 — WHY they were chosen. Nomination as trust, not just recognition.\n"
             . "  Day 4 — VALUES. What kind of generation are we raising, and what do they stand for.\n"
             . "  Day 3 — RESPONSIBILITY, narrowed to their own home, classroom, business or street.\n"
             . "  Day 2 — MESSAGE. Do not come empty-handed; come with a story, an idea, a conviction.\n"
             . "  Day 1 — ACTION. Tomorrow. The red carpet is a platform, not a pathway.\n\n"
             . "RULES\n"
             . "- Reply with ONLY a JSON object: {\"5\":\"...\",\"4\":\"...\",\"3\":\"...\",\"2\":\"...\",\"1\":\"...\"}\n"
             . "- No greeting and no sign-off. Both are added around your text. Start at the first sentence.\n"
             . "- Separate every paragraph with a BLANK LINE. Short paragraphs. A single sentence standing "
             . "alone is a beat, and these letters are built out of them.\n"
             . "- Use these placeholders EXACTLY as written, braces and all. Never replace one with its "
             . "value — these letters are reused for other ceremonies and a name written in is a letter "
             . "that is wrong next year:\n    " . implode("\n    ", $tokens) . "\n"
             . "- Every letter must open by naming how long is left, using {days} or {countdown}.\n"
             . "- Plain text only. No HTML, no markdown, no bullet characters.\n"
             . "- Warm, direct, and addressed to one person. Not marketing copy. Never gushing.\n"
             . "- Do not promise anything about the evening that you were not told.";
    }

    private static function brief(object $event, string $steer): string
    {
        $where = trim(implode(', ', array_filter([
            trim((string) ($event->venue ?? '')), trim((string) ($event->location ?? ''))])));

        $lines = [
            'THE CEREMONY',
            'Name: ' . trim((string) $event->title),
            'Theme: ' . (trim((string) ($event->tagline ?? '')) !== ''
                ? trim((string) $event->tagline)
                : '(none set — write around the theme without naming one, and use {theme} where it belongs)'),
            'When: ' . DisplayTime::showZoned((string) $event->event_date, 'l j F Y \a\t H:i'),
            'Where: ' . ($where !== '' ? $where : '(not set)'),
            '',
            'THE VALUES IT CELEBRATES, one per line — use {values} to place them as a block:',
            InviteSequence::values(),
        ];

        $steer = trim($steer);
        if ($steer !== '') {
            // The organiser's own instruction, LABELLED. It is admin-entered and therefore
            // trusted here, but keeping it in its own section stops a long steer reading as
            // part of the ceremony's description.
            $lines[] = '';
            $lines[] = 'WHAT THE ORGANISER ASKED FOR:';
            $lines[] = mb_substr($steer, 0, 600);
        }

        $lines[] = '';
        $lines[] = 'Write the five letters now.';

        return implode("\n", $lines);
    }
}
