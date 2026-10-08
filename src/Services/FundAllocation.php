<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * WHAT AFRICA GATES TELLS A DONOR THEIR MONEY IS FOR.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * IT WAS THREE LINES TYPED INTO A TEMPLATE, AND TWO THINGS WERE WRONG WITH THAT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * `donate.twig` carried a `{% set ALLOC = [...] %}` naming three destinations —
 * scholarships, mentorship programmes, community grants — under the heading "Where
 * donations go", immediately above a line promising the fund is independently audited.
 *
 * **It could not be changed.** There is no shell on production, so a representation about
 * the use of charitable funds was editable only by somebody who could deploy. A programme
 * that closes, a fund that is redirected, a wording a regulator asks to change: none of it
 * could be done by the people answerable for it. Everything operational is settable from
 * `/admin/settings`, and a promise about money is the last thing that should be an
 * exception.
 *
 * **And it was rendered on other people's appeals.** The block was ungated, so
 * `/giving/{partner}` told a donor their money funds Africa GATES' scholarships — for a
 * payment that settles into the partner organisation's own subaccount and which Africa
 * GATES never holds. A false statement about somebody else's money on the page collecting
 * it. {@see forOrg()} is the gate; a partner's own case is their `OrgBrand` story.
 *
 * ── AND IT DEFAULTS TO NOTHING, NOT TO THE OLD COPY ─────────────────────────
 *
 * Carrying the three hardcoded lines as a fallback would keep exactly the problem: a
 * deployment that has never decided what it funds would go on publishing a claim nobody
 * chose, and the operator would have no way to know it was a default rather than their
 * words. An empty list renders no section at all — the page loses a heading and keeps its
 * integrity, which is the right way round.
 */
final class FundAllocation
{
    /** One settings key, one resolver. */
    public const KEY = 'fund_allocation';

    /** Enough to be a list; past this it is a page rather than an allocation. */
    public const MAX_ROWS = 8;

    public const MAX_TITLE = 80;
    public const MAX_BODY  = 220;

    /**
     * The destinations, or an empty list.
     *
     * Stored as one small JSON document rather than a table: it is read once per page for
     * one deployment, nothing filters or sorts on it, and a new row needs no migration.
     * Same reasoning as `OrgBrand`.
     *
     * @return list<array{title:string, body:string}>
     */
    public static function rows(): array
    {
        $raw = '';
        try {
            $v = DB::table('gates_settings')->where('key_name', self::KEY)->value('value');
            $raw = is_string($v) ? trim($v) : '';
        } catch (\Throwable) {
            // No settings table yet. Publishing nothing is the correct answer for a
            // deployment that cannot be asked what it funds.
        }
        if ($raw === '') return [];

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return [];

        // ── RE-VALIDATED ON THE WAY OUT, NOT ONLY IN ────────────────────────
        //
        // A stored document survives the code that wrote it — an import, a restore, an
        // earlier version of the form. Trusting it because it was checked when it was
        // saved is trusting a past version of this file, which is the rule `OrgBrand`
        // already pays for.
        $out = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) continue;

            $title = trim(mb_substr((string) ($row['title'] ?? ''), 0, self::MAX_TITLE));
            $body  = trim(mb_substr((string) ($row['body']  ?? ''), 0, self::MAX_BODY));
            if ($title === '') continue;          // a destination with no name is not one

            $out[] = ['title' => $title, 'body' => $body];
            if (count($out) >= self::MAX_ROWS) break;
        }

        return $out;
    }

    /**
     * What to publish on a page raising for $org — nothing, when there is one.
     *
     * ── THE GATE, AND WHY IT IS HERE RATHER THAN IN THE TEMPLATE ────────────
     *
     * A partner donation settles into the partner's own subaccount; Africa GATES is never
     * a custodian of it. So our allocation is not merely irrelevant on their page, it is
     * untrue of the money being collected — and a template deciding that with an `{% if %}`
     * is a decision that gets copied to the next surface without its reasoning. One
     * resolver, and the reason travels with it.
     *
     * @return list<array{title:string, body:string}>
     */
    public static function forOrg(?object $org): array
    {
        return $org === null ? self::rows() : [];
    }

    /**
     * Store what an operator typed.
     *
     * Takes parallel title/body arrays because that is what a form posts, and pairs them by
     * INDEX rather than by count: a body with no title is dropped with its title, and a
     * title with no body is a destination somebody has not described yet rather than an
     * error worth refusing a save over.
     *
     * @param list<string> $titles
     * @param list<string> $bodies
     */
    public static function save(array $titles, array $bodies): void
    {
        $rows = [];
        foreach (array_values($titles) as $i => $t) {
            $title = trim(mb_substr((string) $t, 0, self::MAX_TITLE));
            if ($title === '') continue;

            $rows[] = [
                'title' => $title,
                'body'  => trim(mb_substr((string) ($bodies[$i] ?? ''), 0, self::MAX_BODY)),
            ];
            if (count($rows) >= self::MAX_ROWS) break;
        }

        DB::table('gates_settings')->updateOrInsert(
            ['key_name' => self::KEY],
            // An empty list stores an empty list rather than deleting the row: "we have
            // decided to publish nothing" and "nobody has ever been asked" are different
            // states, and only the second should read as unconfigured on the form.
            ['value' => json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        );
    }
}
