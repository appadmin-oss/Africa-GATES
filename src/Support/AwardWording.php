<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * The words one award uses about the people it is for.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHY AN AWARD NEEDS ITS OWN NOUN
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every nomination form on this platform said "nominee". The Incredible Principal
 * Awards is for principals; the Incorruptible Awards is for public servants; the Carol
 * Awards is for choirs, which are not people at all. A form that asks "What is the
 * nominee's full name?" under a heading reading *Carol Awards* is asking a choirmaster
 * a question about a person, and the commonest way that goes wrong is not an error —
 * it is somebody typing their own name because the form seemed to be asking for it.
 *
 * So an award carries the words its own form uses, and this is the one resolver for
 * them. The house wording is the fallback and is correct for an award that has said
 * nothing, which is every award until somebody writes one.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * ONE JSON DOCUMENT, FOR THE REASON `brand_json` IS ONE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every value here is read once per page, for one programme already loaded by id or
 * slug, and nothing filters or sorts on any of it. So a new phrase needs no migration —
 * which is the same argument `OrgBrand` makes, and the same trap: **the column is the
 * constraint**. `wording_json` is TEXT, 65,535 BYTES on MySQL, while the caps below
 * count CHARACTERS. Filled with four-byte characters the same caps allow far more than
 * the column holds, so an award written in a non-Latin script could overflow it while
 * typing nothing the form called too long. Left to the database that is a throw in
 * strict mode or a TRUNCATION on a host that overrides `sql_mode`, and a truncated JSON
 * document does not parse — so {@see of()} would fall back to the house wording and the
 * award's whole form would silently revert. {@see save()} refuses above
 * {@see MAX_JSON_BYTES} with the size instead.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * EVERY VALUE IS RE-VALIDATED ON THE WAY OUT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A document survives the code that wrote it — an import, a restore, a draft written by
 * a model that has since changed. Reading a phrase out of storage and putting it in a
 * heading because "it was checked when it was saved" is trusting a past version of this
 * file. `of()` caps and cleans every value it returns.
 */
final class AwardWording
{
    /**
     * The words, and what each one is for.
     *
     * A TABLE, not seven properties, for the reason `OrgBrand::BLOCKS` is one: it drives
     * the reader, the writer and the admin form's field names at once, so a field the
     * writer does not read is impossible rather than unlikely. That failure would be
     * silent — a `nominee_noun` input posts happily, is never read, and the operator
     * saves with no error and finds the form unchanged.
     *
     * @var array<string,array{label:string,help:string,max:int,default:string}>
     */
    public const FIELDS = [
        'nominee_noun' => [
            'label'   => 'What you call someone nominated for this award',
            'help'    => 'Singular, lower case. "principal", "public servant", "choir".',
            'max'     => 40,
            'default' => 'nominee',
        ],
        'nominee_noun_plural' => [
            'label'   => 'The plural of that',
            'help'    => 'Used in counts and lists. "principals", "choirs".',
            'max'     => 40,
            'default' => 'nominees',
        ],
        'nominate_verb' => [
            'label'   => 'The word for putting one forward',
            'help'    => 'Usually "nominate". Some awards say "enter" or "put forward".',
            'max'     => 30,
            'default' => 'nominate',
        ],
        'who_question' => [
            'label'   => 'The first question the form asks',
            'help'    => 'Shown at the top of step one. Ask for exactly who this award is for.',
            'max'     => 120,
            'default' => 'Who are you nominating?',
        ],
        'who_hint' => [
            'label'   => 'The line under that question',
            'help'    => 'One sentence. Say who is eligible, in the words they would use.',
            'max'     => 200,
            'default' => 'Most nominees are new to Africa GATES — just tell us who they are.',
        ],
        'reason_question' => [
            'label'   => 'How the form asks for a reason',
            'help'    => 'Use {name} for the nominee and {category} for the category.',
            'max'     => 140,
            'default' => 'Why {name} for {category}?',
        ],
        'evidence_hint' => [
            'label'   => 'What counts as evidence for this award',
            'help'    => 'One sentence naming the kind of proof a panel here wants.',
            'max'     => 240,
            'default' => 'Links, documents or photos that show the work. Optional.',
        ],
    ];

    /**
     * Which nominee kinds this award accepts.
     *
     * Stored beside the wording because it is the same kind of fact — something this
     * award decides about its own form — and because the two are read together on every
     * render. An empty or unreadable list means ALL THREE, which is what an award that
     * has said nothing means: a new award should not silently accept nobody.
     */
    public const KINDS_KEY = 'accepts';

    /** The whole document's ceiling, in bytes, under the column's 65,535. */
    public const MAX_JSON_BYTES = 60_000;

    /**
     * The wording for one programme, complete and safe to print.
     *
     * Every key in FIELDS is present in the answer, so a template never has to write
     * `|default(...)` — which is the shape that puts a fifth copy of a default into a
     * template and takes over silently the day a controller stops passing one.
     *
     * @return array<string,mixed>
     */
    public static function of(?object $programme): array
    {
        $stored = [];
        $raw    = is_object($programme) ? ($programme->wording_json ?? null) : null;

        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) $stored = $decoded;
            } catch (\Throwable) {
                // A document that does not parse is a document that was truncated or
                // hand-edited. The house wording is right and the award still works.
                $stored = [];
            }
        }

        $out = [];
        foreach (self::FIELDS as $key => $f) {
            $v = $stored[$key] ?? null;
            $v = is_scalar($v) ? trim((string) $v) : '';
            // Re-validated on the way OUT, not only in. See the docblock.
            $out[$key] = $v !== '' ? mb_substr($v, 0, $f['max']) : $f['default'];
        }

        $out[self::KINDS_KEY] = self::kinds($stored[self::KINDS_KEY] ?? null);

        return $out;
    }

    /** The same, for a programme id — one query, for callers that hold only the id. */
    public static function forProgramme(int $programmeId): array
    {
        try {
            $row = DB::table('gates_award_programmes')->where('id', $programmeId)->first();
        } catch (\Throwable) {
            $row = null;
        }

        return self::of($row);
    }

    /**
     * The nominee kinds an award accepts, always a non-empty list.
     *
     * @return list<string>
     */
    public static function kinds(mixed $raw): array
    {
        $out = [];
        if (is_array($raw)) {
            foreach ($raw as $k) {
                if (NomineeKind::valid($k)) $out[] = NomineeKind::resolve($k);
            }
        }
        $out = array_values(array_unique($out));

        // An award that accepts nobody cannot be nominated for, and nothing on any
        // screen would say why — the kind chips would simply all be missing. All three
        // is what silence means.
        return $out !== [] ? $out : array_keys(NomineeKind::ALL);
    }

    /**
     * The reason question, with the nominee and the category in it.
     *
     * The placeholders are filled HERE rather than in the template, because a template
     * doing its own `replace` is a second implementation of this sentence — and the
     * admin form's preview, the public form and the API all have to show the same one.
     */
    public static function reasonQuestion(array $wording, string $name, string $category): string
    {
        return str_replace(
            ['{name}', '{category}'],
            [$name !== '' ? $name : 'them', $category],
            (string) ($wording['reason_question'] ?? self::FIELDS['reason_question']['default'])
        );
    }

    /**
     * Store a wording document against a programme.
     *
     * Refuses rather than lets the column decide. See the docblock for why a truncated
     * document is worse than a rejected one: it does not parse, so the award reverts to
     * the house wording with nothing on any screen to say it happened.
     *
     * @param array<string,mixed> $input raw, from the admin form or a model draft
     * @return string|null the refusal, or null on success
     */
    public static function save(int $programmeId, array $input): ?string
    {
        $doc = [];
        foreach (self::FIELDS as $key => $f) {
            $v = $input[$key] ?? '';
            $v = is_scalar($v) ? trim((string) $v) : '';
            if ($v === '') continue;                       // absent means "use the house word"
            if (mb_strlen($v) > $f['max']) {
                return '"' . $f['label'] . '" is longer than ' . $f['max'] . ' characters.';
            }
            $doc[$key] = $v;
        }

        $kinds = self::kinds($input[self::KINDS_KEY] ?? null);
        if (count($kinds) < count(NomineeKind::ALL)) {
            // Only stored when it is narrower than everything, so the common case keeps
            // the document small and an award that later gains a kind needs no edit.
            $doc[self::KINDS_KEY] = $kinds;
        }

        $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) return 'That wording could not be stored. Please check for unusual characters.';

        if (strlen($json) > self::MAX_JSON_BYTES) {
            return 'That wording is too long to store (' . number_format(strlen($json))
                 . ' bytes of ' . number_format(self::MAX_JSON_BYTES) . '). Please shorten it.';
        }

        try {
            DB::table('gates_award_programmes')->where('id', $programmeId)
                ->update(['wording_json' => $doc === [] ? null : $json]);
        } catch (\Throwable) {
            return 'That wording could not be saved. Nothing was changed.';
        }

        return null;
    }

    /** Is this award using anything other than the house words? For an admin list. */
    public static function isCustom(?object $programme): bool
    {
        $raw = is_object($programme) ? ($programme->wording_json ?? null) : null;

        return is_string($raw) && trim($raw) !== '' && trim($raw) !== '{}';
    }
}
