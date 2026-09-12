<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * How to say a name, worked out by the platform instead of typed in by a person.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE DOOR COULD BE TAUGHT, AND THAT WAS THE PROBLEM
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `door_welcome_says` — the operator's `Written = Spoken` list — is the right mechanism
 * and was the wrong ONLY mechanism. Somebody had to write an entry for every guest, with
 * no way of knowing which of three hundred names the voice would get wrong. So it stayed
 * empty, and every Nigerian name was read by English rules: silent finals, long and short
 * vowels, schwa in the unstressed syllables. None of that applies to Yoruba, Igbo or Hausa,
 * where the vowels are pure and every syllable is sounded, and the result is a name its
 * owner would not answer to.
 *
 * A worklist of suggestions was the next thing tried and it is still teaching: forty rows
 * an operator has to read on the afternoon of a gala. This is the platform doing the work.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THREE ANSWERS, IN ONE ORDER, AND THE ORDER IS THE DESIGN
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   1. WHAT A PERSON SAID. `door_welcome_says` always wins. Somebody who has heard the
 *      clip and corrected it knows something no model does, and nothing here may quietly
 *      overrule them — which is exactly what a cache that outranked them would do.
 *   2. WHAT WAS WORKED OUT AND KEPT. This table. Asked once per name, ever.
 *   3. THE RULE. {@see DoorWelcome::suggest()}, offline, no dependency, and it abstains on
 *      anything that does not fit the consonant-vowel shape rather than mangling a Grace.
 *
 * Nothing is ever asked at the door. This runs in the same ahead-of-time sweep that
 * renders the audio, hours before anybody arrives — a door is a queue and a queue cannot
 * wait on a model any more than it can wait on a synthesiser.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND IT DEGRADES TO SOMETHING THAT STILL WORKS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * With no AI configured, step 2 never fills and step 3 carries the whole thing — which is
 * still better than English rules over a Yoruba name. Nothing here may become a reason the
 * greeting stops working, and no deployment has to buy anything to be greeted properly.
 */
final class NameSays
{
    /** Asked per run. One model call covers the batch; this bounds a gala, not a minute. */
    public const BATCH = 40;

    /** A respelling is a short thing. Anything longer is a sentence, and a mistake. */
    private const MAX_SAID = 60;

    // ══ reading ══════════════════════════════════════════════════════════════

    /**
     * What has been worked out for this folded key, or null.
     *
     * Never writes and never asks. {@see DoorWelcome::saidAs()} is on the path of every
     * greeting built for the door's own check response, and a lookup that could call a
     * model would put a network round trip in front of a steward with a queue.
     */
    public static function known(string $key): ?string
    {
        return self::knownWithSource($key)['said'] ?? null;
    }

    /**
     * The same lookup, and WHO WORKED IT OUT — the whole reason `source` is a column.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * THE COLUMN WAS WRITTEN BY THREE PATHS AND READ BY NOTHING
     * ══════════════════════════════════════════════════════════════════════════
     *
     * `source` distinguishes a model's answer from the offline rule's from a person's, and
     * its own migration says why it is kept apart: "so the admin screen can show where an
     * answer came from, and so a bad batch can be cleared without touching anything a
     * person wrote". Neither was true. `all()` — the one method that selected the column,
     * whose docblock says "for the settings screen" — had no caller anywhere, and there
     * was no delete-by-source path at all.
     *
     * Worse than unread: the settings screen was showing a source and it was not this one.
     * {@see DoorWelcome::nameSheet()} labelled every row it found in this table "worked
     * out", derived from WHERE IT LOOKED rather than from what was stored — so a
     * respelling a model invented and one the rule derived by letters were presented to an
     * operator as the same kind of answer, on the screen whose job is deciding which of
     * them to trust.
     *
     * One query, both facts, so a caller that needs the provenance does not pay for a
     * second lookup on a screen that is already per-name.
     *
     * @return array{said:string, source:string}|null
     */
    public static function knownWithSource(string $key): ?array
    {
        if ($key === '') return null;

        try {
            $row = DB::table('gates_name_says')->where('name_key', $key)
                ->first(['said', 'source']);
        } catch (\Throwable) {
            // No table on this deployment — the rule still answers.
            return null;
        }

        $said = trim((string) ($row->said ?? ''));
        if ($said === '') return null;

        return ['said' => $said, 'source' => self::source((string) ($row->source ?? ''))];
    }

    /**
     * Everything on record, newest first. For the settings screen.
     *
     * `name_key` travels with the row because it is the identity — `written` is whichever
     * spelling arrived first and two spellings of one name fold to one key, so a screen
     * keyed on the written form would offer to act on a row it cannot name.
     *
     * @return list<array{name_key:string, written:string, said:string, source:string}>
     */
    public static function all(int $limit = 200): array
    {
        try {
            return DB::table('gates_name_says')->orderByDesc('id')
                ->limit(max(1, min(1000, $limit)))
                ->get(['name_key', 'written', 'said', 'source'])
                ->map(static fn (object $r): array => [
                    'name_key' => (string) $r->name_key,
                    'written'  => (string) $r->written,
                    'said'     => (string) $r->said,
                    'source'   => self::source((string) ($r->source ?? '')),
                ])->all();
        } catch (\Throwable) { return []; }
    }

    public static function count(): int
    {
        try { return (int) DB::table('gates_name_says')->count(); }
        catch (\Throwable) { return 0; }
    }

    /**
     * How many answers each source has on record.
     *
     * So the button that clears a batch can say what it will clear before it is pressed,
     * and read zero rather than being offered when there is nothing to remove.
     *
     * @return array<string,int> keyed by source, every source present
     */
    public static function bySource(): array
    {
        $out = array_fill_keys(self::SOURCES, 0);

        try {
            foreach (DB::table('gates_name_says')
                ->select('source', DB::raw('COUNT(*) as n'))
                ->groupBy('source')->get() as $r) {
                $out[self::source((string) ($r->source ?? ''))] += (int) $r->n;
            }
        } catch (\Throwable) { return $out; }

        return $out;
    }

    /** Every value `source` may hold. `hand` outranks the others and is never cleared. */
    public const SOURCES = ['rule', 'ai', 'hand'];

    /**
     * One phrase per source, in the operator's words rather than the column's.
     *
     * ONE resolver. The pending-names table and the record below it are two screens
     * describing the same three values, and two lists of labels is how they come to
     * disagree about what `rule` means — the fault this codebase has shipped with an
     * interview status and with a vote's `vote_type` already.
     */
    public static function sourceLabel(string $source): string
    {
        return match (self::source($source)) {
            'ai'   => 'worked out',
            'hand' => 'a person',
            default => 'rule',
        };
    }

    /**
     * FORGET EVERY ANSWER FROM ONE SOURCE, so a bad batch can be taken back.
     *
     * ══════════════════════════════════════════════════════════════════════════
     * WHY THIS IS NEEDED AND WHY IT REFUSES `hand`
     * ══════════════════════════════════════════════════════════════════════════
     *
     * A name is asked about ONCE, ever — {@see remember()} keeps the first answer, because
     * a respelling may already have been read aloud to somebody. That is right, and it
     * means a bad run is permanent: a model answering badly for a batch of forty, a prompt
     * change, a provider swapped for a worse one, and every one of those names is said
     * wrongly at every door from then on with no way to ask again. The migration promised
     * this button; there was no delete path in the codebase at all.
     *
     * Clearing is safe in a way editing would not be: a name with no row is simply asked
     * again on the next ahead-of-time sweep, and until then the offline rule answers. So
     * the worst case of pressing this is a name read by rule for an evening.
     *
     * `hand` is refused outright. If a person's answer ever reaches this table it is the
     * one thing here that cannot be reproduced — nothing can ask a model to guess again at
     * what somebody who heard the clip decided — and a button that could sweep it away
     * would be a quiet override of the rule at the top of this class: what a person said
     * always wins.
     *
     * @return int rows forgotten; 0 for an unknown source, and for `hand`
     */
    public static function forget(string $source): int
    {
        $source = trim(strtolower($source));
        if ($source === 'hand' || !in_array($source, self::SOURCES, true)) return 0;

        try {
            return (int) DB::table('gates_name_says')->where('source', $source)->delete();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** A stored source, narrowed to a value this class knows. */
    private static function source(string $raw): string
    {
        $s = trim(strtolower($raw));

        // A row written before the column had a default, or by a deployment whose ENUM
        // took something else. Read as the rule, which is the answer that claims least.
        return in_array($s, self::SOURCES, true) ? $s : 'rule';
    }

    // ══ writing ══════════════════════════════════════════════════════════════

    /**
     * Keep one answer. Returns false when the answer was not usable.
     *
     * `INSERT` and not upsert-by-default: a name already worked out is not asked again, so
     * arriving here twice for one key means something has changed its mind, and the first
     * answer — which may have been listened to by now — is kept unless the caller says
     * otherwise.
     */
    public static function remember(string $written, string $said, string $source = 'rule',
                                    bool $replace = false): bool
    {
        $key   = DoorWelcome::fold($written);
        $said  = self::tidy($said);
        $write = trim($written);

        if ($key === '' || $said === '' || $write === '') return false;
        $source = self::source($source);

        try {
            $exists = DB::table('gates_name_says')->where('name_key', $key)->exists();
            if ($exists && !$replace) return false;

            if ($exists) {
                DB::table('gates_name_says')->where('name_key', $key)
                    ->update(['said' => $said, 'source' => $source]);
                return true;
            }

            DB::table('gates_name_says')->insert([
                'name_key' => $key, 'written' => mb_substr($write, 0, 80),
                'said' => $said, 'source' => $source,
            ]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * A respelling we are willing to say out loud, or ''.
     *
     * This is the whole safety boundary around a model's answer. Everything that comes back
     * is read to a guest at their own door, so it is bounded, stripped of anything that is
     * not a name-shaped string, and refused outright if it is a sentence, a refusal, an
     * explanation, or markup. A wrong respelling is a bad evening; an unbounded string on
     * the way to an SSML document is something else.
     */
    public static function tidy(string $said): string
    {
        $said = trim(preg_replace('/\s+/u', ' ', $said) ?? '');

        // Letters, the hyphens a respelling is built from, apostrophes and spaces. No
        // digits, no punctuation, no angle brackets — AzureVoice escapes its input, but a
        // value that needs escaping is a value that is not a pronunciation.
        if (preg_match("/^[\p{L}\p{M}' \-]{2,}$/u", $said) !== 1) return '';

        // A model that answers with a sentence has misunderstood the question, and the
        // sentence would be read aloud in place of somebody's name.
        if (mb_strlen($said) > self::MAX_SAID) return '';
        if (substr_count($said, ' ') > 2) return '';

        return $said;
    }

    // ══ working it out ═══════════════════════════════════════════════════════

    /**
     * Work out the names that have no answer yet, and keep what comes back.
     *
     * @param list<string> $names first names, as written
     * @return int how many were newly learned
     */
    public static function learn(array $names, ?AiService $ai = null): int
    {
        $ask = self::unanswered($names);
        if ($ask === []) return 0;

        $ask = array_slice($ask, 0, self::BATCH);
        $got = self::ask($ask, $ai);

        $kept = 0;
        foreach ($ask as $name) {
            $said = $got[DoorWelcome::fold($name)] ?? '';

            // The model's answer when there is one, the rule's when there is not. A name
            // that gets neither is left with NO row rather than a bad one: an empty table
            // is asked again next run, and a wrong row is permanent.
            if ($said === '') $said = DoorWelcome::suggest($name);
            if ($said === '') continue;

            if (self::remember($name, $said, isset($got[DoorWelcome::fold($name)]) ? 'ai' : 'rule')) {
                $kept++;
            }
        }

        return $kept;
    }

    /**
     * Which of these have no answer from anybody yet.
     *
     * The operator's list counts as an answer: a name they have written is a name nobody
     * needs to work out, and asking would spend a call to produce something that loses.
     *
     * @param list<string> $names
     * @return list<string> written forms, deduplicated on the folded key
     */
    public static function unanswered(array $names): array
    {
        $hand = DoorWelcome::dictionary();
        $out  = [];

        foreach ($names as $n) {
            $first = DoorWelcome::firstName((string) $n);
            if ($first === '') continue;

            $key = DoorWelcome::fold($first);
            if ($key === '' || isset($hand[$key]) || isset($out[$key])) continue;
            if (self::known($key) !== null) continue;

            $out[$key] = $first;
        }

        return array_values($out);
    }

    /**
     * Ask a model how a batch of Nigerian names is said.
     *
     * ── THROUGH THE GATEWAY, NOT AROUND IT ───────────────────────────────────
     *
     * `AiGateway` is the only door to a provider on this platform, and a direct
     * `AiService::complete()` is an unlogged, unbudgeted call — invisible in the spend
     * report, uncounted by the daily ceiling, and unaffected by the switch that stops one
     * misbehaving capability. `AiGatewayTest` fails the build on one, which is how this
     * came back through the door it was supposed to.
     *
     * It also buys the two things this particular call needs. Guest names came off a public
     * booking form, so the gateway fences them as DATA — somebody who names themselves with
     * an instruction gets it treated as text, and the worst case is one silly respelling.
     * And `door.name_pronounce` declares its own 25-second budget, which a batch of forty
     * names needs and the service's 6-second default would cut off.
     *
     * ── ONE CALL, NOT ONE PER NAME ───────────────────────────────────────────
     *
     * A gala is a few hundred guests and perhaps sixty distinct first names. Asked
     * individually that is sixty calls of a few tokens each — the shape that gets a
     * provider key rate-limited and turns a maintenance run into a long one. Batched, a
     * whole evening is one call.
     *
     * @param list<string> $names
     * @return array<string,string> folded key => respelling
     */
    private static function ask(array $names, ?AiService $ai = null): array
    {
        if ($names === []) return [];

        $system = <<<'TXT'
            You are a Nigerian name pronunciation guide for a text-to-speech system.

            For each name, return a respelling that makes an ENGLISH text-to-speech voice
            say the name the way a Nigerian would say it. Use plain English letters,
            hyphens between syllables, and CAPITALS for the stressed syllable.

            These are mostly Yoruba, Igbo and Hausa names. Their vowels are pure — a is
            "ah", e is "eh", i is "ee", o is "oh", u is "oo" — and every syllable is
            sounded, including final vowels. Most are written without tone marks; use the
            usual reading of the name.

            Rules:
            - Return ONLY a JSON object mapping each name exactly as given to its
              respelling.
            - If a name is an ordinary English name that an English voice already says
              correctly (Grace, John, Precious), return null for it.
            - A respelling is a short string of letters and hyphens. Never a sentence,
              never an explanation, never phonetic symbols.

            Example: {"Ngozi":"n-GOH-zee","Chidinma":"chee-DEEN-mah","Grace":null}
            TXT;

        $r = (new AiGateway($ai))->run('door.name_pronounce', [
            'system' => $system,
            'user'   => json_encode(array_values($names), JSON_UNESCAPED_UNICODE) ?: '[]',
            'json'   => true,
            // ── THE BOUNDARY, IN THE GATEWAY'S OWN PARSING SEAM ──────────────
            //
            // Everything that comes back is read aloud to a guest at their own door, so
            // this is where a sentence, an apology, a refusal or a fragment of markup is
            // refused — before it is stored, and long before it reaches an SSML document.
            // Keyed on the FOLD rather than on the string the model echoed: a model that
            // answers "ngozi" for "Ngozi" is not wrong, and a case-sensitive lookup would
            // silently drop every answer.
            'schema' => static function (string $raw): ?array {
                $data = json_decode(trim($raw), true);
                if (!is_array($data)) return null;

                $map = [];
                foreach ($data as $name => $said) {
                    if (!is_string($name) || !is_string($said)) continue;

                    $key  = DoorWelcome::fold($name);
                    $said = self::tidy($said);
                    if ($key === '' || $said === '') continue;

                    $map[$key] = $said;
                }

                return $map;
            },
        ]);

        return ($r->ok && is_array($r->value)) ? $r->value : [];
    }
}
