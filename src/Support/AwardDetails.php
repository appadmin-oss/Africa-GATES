<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * WHAT AN AWARD SAYS ABOUT ITSELF ON ITS "AWARD DETAILS" TAB (owner, 16 Oct 2026:
 * "it is not so editable").
 *
 * The tab was four fixed paragraphs written from the rules — how nominations work, how
 * the winner is decided, voting, after the results — plus the award's description. The
 * rule sentences stay generated, because they are read from the code that enforces them
 * and a typed copy is the copy that goes stale (the settings screen once explained the
 * scoring with another basis's arithmetic). What was missing is the award's OWN voice:
 * who may be nominated, what a winner receives, anything a host needs to add to each rule,
 * and the questions people actually ask. Those are here, per award.
 *
 * One JSON document (`details_json`) for the reason `wording_json` is one: read once per
 * page for one programme, filtered by nothing, so a new section needs no migration. Every
 * value is capped and cleaned on the way OUT as well as in, and the platform's name is put
 * right in both directions (Support\Brand).
 */
final class AwardDetails
{
    /** The award's own sections, in the order the tab draws them. */
    public const FIELDS = [
        'eligibility' => ['label' => 'Who can be nominated', 'max' => 1500,
            'help' => 'Who is eligible, and who is not. A new section on the tab, after "What the award celebrates".'],
        'prize' => ['label' => 'What winners receive', 'max' => 1000,
            'help' => 'The prizes for the edition’s overall winners, ranked across every category, not per category: the trophy, the cash, the opportunities. Leave blank to say nothing.'],
        'nominations_note' => ['label' => 'Anything to add about nominations', 'max' => 1000,
            'help' => 'Drawn under the platform\'s own rules for nominating.'],
        'judging_note' => ['label' => 'Anything to add about judging', 'max' => 1000,
            'help' => 'Who sits on the panel, what they look for. Drawn under the scoring rule.'],
        'voting_note' => ['label' => 'Anything to add about voting', 'max' => 1000,
            'help' => 'Drawn under the voting rule.'],
        'after_note' => ['label' => 'Anything to add about the results', 'max' => 1000,
            'help' => 'The ceremony, the announcement. Drawn under "After the results".'],
    ];

    public const FAQ_MAX   = 8;
    public const FAQ_Q_MAX = 200;
    public const FAQ_A_MAX = 1200;
    /** Under TEXT's 65,535 bytes on MySQL, which the character caps alone do not guarantee. */
    public const MAX_JSON_BYTES = 60_000;

    /**
     * The award's sections, complete and safe to print: every key present (blank when
     * unset) and `faq` a clean list.
     *
     * @return array<string,mixed>
     */
    public static function of(?object $programme): array
    {
        $raw = is_object($programme) ? ($programme->details_json ?? null) : null;
        $doc = is_string($raw) ? json_decode($raw, true) : null;
        $doc = is_array($doc) ? $doc : [];

        $out = [];
        foreach (self::FIELDS as $k => $f) {
            $v = is_scalar($doc[$k] ?? null) ? trim((string) $doc[$k]) : '';
            $out[$k] = Brand::normalise(mb_substr(strip_tags($v), 0, $f['max']));
        }
        $out['faq'] = self::faq($doc['faq'] ?? []);
        return $out;
    }

    /**
     * Store an award's sections from the admin form.
     *
     * @param array<string,mixed> $input `details[eligibility]`… and `details[faq_q][]`/`details[faq_a][]`
     * @return string|null the refusal, or null on success
     */
    public static function save(int $programmeId, array $input): ?string
    {
        $doc = [];
        foreach (self::FIELDS as $k => $f) {
            $v = is_scalar($input[$k] ?? null) ? trim(strip_tags((string) $input[$k])) : '';
            if ($v === '') continue;
            if (mb_strlen($v) > $f['max']) return '"' . $f['label'] . '" is longer than ' . $f['max'] . ' characters.';
            $doc[$k] = Brand::normalise($v);
        }
        $qs = (array) ($input['faq_q'] ?? []);
        $as = (array) ($input['faq_a'] ?? []);
        $pairs = [];
        foreach ($qs as $i => $q) $pairs[] = ['q' => $q, 'a' => $as[$i] ?? ''];
        $faq = self::faq($pairs);
        if (count(array_filter($pairs, static fn ($p) => trim((string) $p['q']) !== '' && trim((string) $p['a']) !== '')) > self::FAQ_MAX) {
            return 'Up to ' . self::FAQ_MAX . ' questions. Remove one to add another.';
        }
        if ($faq !== []) $doc['faq'] = $faq;

        $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) return 'Those details could not be stored. Please check for unusual characters.';
        if (strlen($json) > self::MAX_JSON_BYTES) {
            return 'Those details are too long to store (' . number_format(strlen($json)) . ' bytes of '
                 . number_format(self::MAX_JSON_BYTES) . '). Please shorten them.';
        }
        try {
            DB::table('gates_award_programmes')->where('id', $programmeId)
                ->update(['details_json' => $doc === [] ? null : $json]);
        } catch (\Throwable) {
            return 'The award details could not be saved. Nothing was changed.';
        }
        return null;
    }

    /**
     * A clean list of question-and-answer pairs: both halves present, capped, named right.
     *
     * @return list<array{q:string,a:string}>
     */
    private static function faq(mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $p) {
            if (!is_array($p)) continue;
            $q = trim(strip_tags((string) ($p['q'] ?? '')));
            $a = trim(strip_tags((string) ($p['a'] ?? '')));
            if ($q === '' || $a === '') continue;
            $out[] = ['q' => Brand::normalise(mb_substr($q, 0, self::FAQ_Q_MAX)), 'a' => Brand::normalise(mb_substr($a, 0, self::FAQ_A_MAX))];
            if (count($out) >= self::FAQ_MAX) break;
        }
        return $out;
    }
}
