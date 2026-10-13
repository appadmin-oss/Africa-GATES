<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Support\NomineeKind;

/**
 * What a nomination must contain — the one place, for both doors.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THERE ARE TWO DOORS AND THEY HAVE ALREADY DIVERGED TWICE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `POST /nominate` (the form) and `POST /api/nominations` (public, unauthenticated) both
 * reach `AwardService::submitNomination()`. Everything AFTER the insert was pulled into
 * {@see NominationAftercare} because the API door told no operator, sent no confirmation,
 * queued no triage and fired no webhook — a nomination that sat in the table until
 * somebody happened to look.
 *
 * The validation in front of the insert was never pulled out, and it had drifted just as
 * far. Read side by side before this class existed:
 *
 *   the form required  programme, nominee name (two words), country, state, LGA, reason,
 *                      nominator name (two words), email, phone, country, state, LGA,
 *                      age range
 *   the API required   programme, nominee name, country, reason, nominator name, email
 *
 * So an API nomination landed with no state, no LGA, no nominator phone and no age range
 * — every one of them a field the form calls required because an operator needs it — and
 * a one-word name the form would have refused. Same table, same review desk, two
 * different ideas of what a nomination is.
 *
 * Adding a third rule (2-to-3 categories, each with a 40-character reason) to only one
 * of those doors would have made it three. So the rules are here, both doors call
 * {@see check()}, and `NominationDoorsAgreeTest` sweeps `src/` for a second copy.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE MESSAGES ARE PART OF THE RULE, NOT DECORATION
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A nomination form is where somebody gives up on entering. Every refusal here is
 * written for the person filling it in and reaches them verbatim — `PublicFault::line()`
 * passes our own copy through and only replaces a library's exception. The 40-character
 * copy is fixed by the design handoff (§8.16) and is the same string the live counter
 * shows, because two sentences for one rule is how they come to disagree.
 */
final class NominationRules
{
    /**
     * Categories: at least two, at most three.
     *
     * TWO is the floor rather than one, and it is a judging decision rather than a form
     * preference: a nominator who can only place somebody in a single category has
     * usually not thought about where the work actually sits, and the second choice is
     * what the category-fit analysis compares the first against. The design handoff
     * §8.16 fixes both numbers.
     *
     * The DC's own error copy says "at least one", which contradicts its own heading —
     * REFERENCE §0 puts the phase file above the DC, and the phase file says two.
     * Recorded in docs/handoff/GAPS.md.
     */
    public const MIN_CATEGORIES = 2;
    public const MAX_CATEGORIES = 3;

    /** A reason, per category. Short enough to be reachable, long enough to say something. */
    public const MIN_REASON = 40;

    /** And a ceiling, so one field cannot fill a TEXT column with a paste. */
    public const MAX_REASON = 2500;

    /** Evidence: optional, and capped. §8.16. */
    public const MAX_EVIDENCE   = 5;
    public const MAX_FILE_BYTES = 10 * 1024 * 1024;

    /** What a file may be. A video is not here: §8.16 says PDF, JPG, PNG. */
    public const FILE_TYPES = ['application/pdf', 'image/jpeg', 'image/png'];

    /**
     * The two kinds of evidence, BESIDE THE CODE THAT WRITES THEM.
     *
     * `gates_nomination_evidence.kind` is an ENUM, and a value outside an ENUM is
     * `Data truncated` on MySQL rather than an error anybody notices — while SQLite
     * stores whatever it is given, so a suite that is green proves nothing about it.
     * These used to be two string literals inside `AwardService`, which is one copy
     * of the words in the migration and another in the writer with nothing comparing
     * them. `NominationSchemaWordsTest` compares them now.
     *
     * The same pattern as `NomineeClaimService::ST_*` and `CommunityService::HELD`,
     * and for the same reason: the two dashboards that read a status the writer had
     * never written both reported zero and both looked measured.
     */
    public const EVIDENCE_LINK = 'link';
    public const EVIDENCE_FILE = 'file';

    /** @var list<string> every value the column may hold. */
    public const EVIDENCE_KINDS = [self::EVIDENCE_LINK, self::EVIDENCE_FILE];

    /**
     * The one refusal a person sees about a short reason.
     *
     * Public and named, because the live counter, the server and the test all have to
     * say the same thing, and a second copy of a sentence is how a form comes to tell
     * somebody two different rules about one field.
     */
    public const SHORT_REASON = 'Tell us a little more (at least 40 characters).';

    /**
     * "How do you know them?" (NominationFlow.dc.html step 5) — key => the DC's label, in
     * its order. Stored as the key; drawn as the label on the form and in the operator brief.
     */
    public const RELATIONS = [
        'colleague' => 'Colleague',
        'student'   => 'Student or parent',
        'community' => 'Community member',
        'employee'  => 'Employee',
        'client'    => 'Client or customer',
        'family'    => 'Family',
        'other'     => 'Other',
    ];

    /** A posted relation if it is one of ours, else null — a claim, not a free-text field. */
    public static function relation(mixed $raw): ?string
    {
        $k = is_scalar($raw) ? strtolower(trim((string) $raw)) : '';
        return isset(self::RELATIONS[$k]) ? $k : null;
    }

    /**
     * Check a whole nomination. Returns the first refusal, or null.
     *
     * FIRST and not all of them, deliberately: the flow is five steps and each step
     * validates on the way out, so a person sees the problem next to the field that has
     * it. A list of five errors at the end is the thing that arrangement exists to
     * avoid.
     *
     * @param array<string,mixed> $data the request body, already trimmed of nothing
     * @param list<int>           $validCategoryIds the categories THIS award edition has,
     *                            resolved by the caller from the cycle — this class never
     *                            queries, so it is callable from a test with no database
     */
    public static function check(array $data, array $validCategoryIds): ?string
    {
        $kind = NomineeKind::resolve($data['nominee_kind'] ?? null);

        $name = trim((string) ($data['nominee_name'] ?? ''));
        if ($name === '') {
            return 'Please tell us who you are nominating.';
        }
        if (!NomineeKind::nameLooksComplete($kind, $name)) {
            return $kind === NomineeKind::PERSON
                ? "Please enter the nominee's full name — first and last name."
                : "Please enter the organisation's registered name.";
        }

        return self::checkCategories($data, $validCategoryIds)
            ?? self::checkEvidence($data);
    }

    /**
     * The categories and their reasons.
     *
     * @param array<string,mixed> $data
     * @param list<int>           $validCategoryIds
     */
    public static function checkCategories(array $data, array $validCategoryIds): ?string
    {
        $picked = self::categories($data);

        if (count($picked) < self::MIN_CATEGORIES) {
            return 'Choose ' . self::MIN_CATEGORIES . ' or ' . self::MAX_CATEGORIES
                 . ' categories — at least two, so we can see where the work fits best.';
        }
        if (count($picked) > self::MAX_CATEGORIES) {
            return 'You can choose up to ' . self::MAX_CATEGORIES . ' categories. Remove one to swap.';
        }

        foreach ($picked as $id => $reason) {
            // A category from another award, or one that does not exist. The same hole
            // `AwardService::categoryInCycle()` closes for the single-category column —
            // checked here too, because this list never reaches that method.
            if (!in_array($id, $validCategoryIds, true)) {
                return 'One of those categories is not part of this award\'s current edition. '
                     . 'Please choose from the list and try again.';
            }
            // Counted in CHARACTERS, not bytes. `strlen()` would let a 40-byte answer
            // in any Latin script pass while refusing 39 characters of Yorùbá or Arabic
            // that is visibly longer — a rule that is stricter for the people this
            // platform exists to reach.
            if (mb_strlen(trim($reason)) < self::MIN_REASON) {
                return self::SHORT_REASON;
            }
            if (mb_strlen(trim($reason)) > self::MAX_REASON) {
                return 'That reason is longer than we can store. Please shorten it a little.';
            }
        }

        return null;
    }

    /**
     * Links and files. Optional — so an empty set is fine and only the caps bite.
     *
     * @param array<string,mixed> $data
     */
    public static function checkEvidence(array $data): ?string
    {
        $links = self::links($data);
        $files = is_array($data['evidence_files'] ?? null) ? $data['evidence_files'] : [];

        if (count($links) + count($files) > self::MAX_EVIDENCE) {
            return 'You can add up to ' . self::MAX_EVIDENCE . ' pieces of evidence. '
                 . 'Remove one to add another.';
        }

        foreach ($links as $url) {
            if (!self::isHttpUrl($url)) {
                return 'One of those links does not look like a web address. '
                     . 'Please check it, or remove it — evidence is optional.';
            }
        }

        return null;
    }

    /**
     * The chosen categories as `[categoryId => reason]`.
     *
     * The wire shape is `categories[<id>] = <reason>`, so the id and the text it belongs
     * to cannot be separated. Two parallel arrays — ids in one field, reasons in another
     * — is how a reason ends up filed against the wrong category when a browser drops an
     * empty value, and nothing on any screen would show it: every reason is somebody's
     * real writing, just attached to the wrong choice.
     *
     * @param array<string,mixed> $data
     * @return array<int,string>
     */
    public static function categories(array $data): array
    {
        $raw = $data['categories'] ?? null;
        if (!is_array($raw)) return [];

        $out = [];
        foreach ($raw as $id => $reason) {
            $id = (int) $id;
            if ($id <= 0 || !is_scalar($reason)) continue;

            /* ── AN EMPTY REASON IS NOT A CHOICE ──────────────────────────────
               Every entry was kept, which is right only while something upstream
               guarantees that an unchosen category never posts — and with the wizard
               running, something does: the script disables the textarea of a category
               nobody ticked, and a disabled control is the one thing a browser will
               not submit.

               WITH NO SCRIPT there is nothing to disable it. The form ships as one
               long page, every reason box is open and empty, and all of them post —
               so a nomination naming two categories arrived as six, four of them
               blank, and was refused for exceeding a cap the person had not come
               near. The checkbox cannot settle it either: it carries no `name`, so it
               has never posted at all and the WRITING has always been the choice.

               Trimmed before the test, or a box holding a newline counts as a
               category nobody picked. */
            if (trim((string) $reason) === '') continue;

            $out[$id] = (string) $reason;
        }

        return $out;
    }

    /**
     * The evidence links, cleaned of the blanks a repeatable field always sends.
     *
     * @param array<string,mixed> $data
     * @return list<string>
     */
    public static function links(array $data): array
    {
        $raw = $data['evidence_links'] ?? null;
        if (!is_array($raw)) return [];

        $out = [];
        foreach ($raw as $u) {
            if (!is_scalar($u)) continue;
            $u = trim((string) $u);
            if ($u !== '') $out[] = $u;
        }

        return array_values(array_unique($out));
    }

    /**
     * Is this a link we will store and later put in an `href`?
     *
     * The scheme is checked, not merely the shape. `javascript:` parses perfectly well
     * as a URL, and this value is typed by a stranger, stored, and rendered on the
     * review desk — where a moderator clicks it.
     */
    public static function isHttpUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || mb_strlen($url) > 600) return false;

        $parts = parse_url($url);
        if (!is_array($parts)) return false;

        return in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && trim((string) ($parts['host'] ?? '')) !== '';
    }
}
