<?php
declare(strict_types=1);

namespace AfricaGates\Support;

/**
 * What a nomination can be about: a person, an organisation, or a business.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THREE WORDS, DECLARED ONCE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `gates_nominations.nominee_kind` is an ENUM on MySQL, and an ENUM is the one column
 * type this codebase has been bitten by repeatedly: a value outside the set is
 * `Data truncated`, not an error anybody notices, and a FILTER on a value outside the
 * set is zero rows that read as a measurement. `gates_event_invites.audience` shipped as
 * `ENUM('principal','child','judge')`, was corrected one commit later, and production
 * kept the first set for ever — green in dev the whole time, because dev's table was
 * built fresh from the corrected definition.
 *
 * So the three words live here, the migration's ENUM is written from this list, every
 * writer takes its value from here, and `NomineeKindTest` compares this list against the
 * column the database actually has.
 *
 * ── ORGANISATION AND BUSINESS ARE NOT THE SAME THING, AND THE SPLIT IS THE POINT ──
 *
 * They differ in what an award asks of them and in what a panel weighs. A non-profit
 * and a company entering the same category are judged on different evidence, and this
 * platform already treats the distinction as real elsewhere: `gates_partner_orgs` exists
 * to verify a registered body through CAC and SCUML, which a business passes differently
 * from a foundation. Collapsing them into one "not a person" value would throw away the
 * only signal the judging stage has before anybody reads a word.
 */
final class NomineeKind
{
    public const PERSON       = 'person';
    public const ORGANISATION = 'organisation';
    public const BUSINESS     = 'business';

    /**
     * The three, with the words each surface needs.
     *
     * `one` is what the form calls it on a chip ("A person"); `plural` is what an award
     * says it accepts ("people"); `name_label` is what the name field is called, because
     * "Full name" is wrong for a registered body and "Registered name" is wrong for a
     * person — `NominationFlow.dc.html` carries both and the difference is the whole
     * reason the field is not just labelled "Name".
     *
     * @var array<string,array{one:string,plural:string,name_label:string,name_hint:string}>
     */
    public const ALL = [
        self::PERSON => [
            'one'        => 'A person',
            'plural'     => 'people',
            'name_label' => 'Full name',
            'name_hint'  => 'As they would write it themselves.',
        ],
        self::ORGANISATION => [
            'one'        => 'An organisation',
            'plural'     => 'organisations',
            'name_label' => 'Registered name',
            'name_hint'  => 'The name on its registration, not its trading name.',
        ],
        self::BUSINESS => [
            'one'        => 'A business',
            'plural'     => 'businesses',
            'name_label' => 'Registered name',
            'name_hint'  => 'The name the business is registered under.',
        ],
    ];

    /** Is $kind one of the three? Never trust a form field. */
    public static function valid(mixed $kind): bool
    {
        return is_string($kind) && array_key_exists(strtolower(trim($kind)), self::ALL);
    }

    /**
     * $kind, or `person`.
     *
     * The fallback is not a shrug: every row that existed before this column did is a
     * person — the form has only ever asked for a full name, with an optional
     * organisation field beside it — so `person` is what an unreadable value MEANS
     * here, not merely what is convenient.
     */
    public static function resolve(mixed $kind): string
    {
        return self::valid($kind) ? strtolower(trim((string) $kind)) : self::PERSON;
    }

    /** The word an award uses for what it accepts: "people", "organisations", "businesses". */
    public static function plural(string $kind): string
    {
        return self::ALL[self::resolve($kind)]['plural'];
    }

    /** What the name field is called for this kind. */
    public static function nameLabel(string $kind): string
    {
        return self::ALL[self::resolve($kind)]['name_label'];
    }

    /**
     * Does a name look like a full name for this kind?
     *
     * A PERSON needs two words, which is the rule the form has always enforced and the
     * reason it exists: "Ada" is not enough for a moderator to tell two nominees apart,
     * and the approval path seeds a public profile from it.
     *
     * AN ORGANISATION OR A BUSINESS DOES NOT. "Andela", "Interswitch", "MTN" are whole
     * registered names, and demanding a second word of them is the shape of client-side
     * rule this codebase has already paid for — a browser `pattern` stricter than the
     * server, refusing a real answer in a tooltip nobody can argue with.
     */
    public static function nameLooksComplete(string $kind, string $name): bool
    {
        $name = trim($name);
        if ($name === '') return false;

        if (self::resolve($kind) !== self::PERSON) {
            // Two characters, so a stray initial is still refused.
            return mb_strlen($name) >= 2;
        }

        return count(preg_split('/\s+/', $name) ?: []) >= 2;
    }

    /** The list a template loops over. @return list<array{kind:string,one:string,plural:string}> */
    public static function options(): array
    {
        $out = [];
        foreach (self::ALL as $kind => $w) {
            $out[] = ['kind' => $kind, 'one' => $w['one'], 'plural' => $w['plural']];
        }
        return $out;
    }
}
