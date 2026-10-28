<?php
declare(strict_types=1);
namespace AfricaGates\Services;

use AfricaGates\Admin\Services\ReviewDesk;
use AfricaGates\Support\NominationStatus as NS;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Duplicate nominations, resolved BEFORE review — automatically where the record is plain
 * (owner, 28 Oct 2026: "We should be able to resolve them before review … make it smart and
 * automatic").
 *
 * ── WHAT WAS WRONG ──────────────────────────────────────────────────────────
 *
 * A second nomination for the same person is usually not a mistake: the eligibility rule asks
 * for nominations from several PLACES, so the best-supported nominees are exactly the ones
 * with several entries. But every same-name entry was flagged red "Duplicate?", which took
 * all of them out of "Ready to approve", and the desk refused to approve one ("Resolve the
 * duplicate first") while the only resolution it offered — a merge — needed the other entry
 * APPROVED first ("there is nothing to merge into yet"). So the more people backed somebody,
 * the harder they were to approve, and resolving it meant deciding one copy blind.
 *
 * ── WHAT HAPPENS NOW ────────────────────────────────────────────────────────
 *
 * A later nomination is COMBINED into the earliest open one for the same person: it stays a
 * row of its own (its nominator, reason, evidence and place all still count, and the merge is
 * reversible), it carries `merged_into`, and it leaves the queue. The reviewer sees ONE
 * nomination, "also nominated by" the others, and decides it once; the combined entries follow
 * that decision (`follow()`).
 *
 * It happens automatically when the two are plainly one person — same edition, same category,
 * the same name by the desk's own key, and the field-by-field comparison the desk already
 * draws (`ReviewDesk::compare()`, one resolver, not a second matcher) either ties them
 * (the same email or phone, or the same nominator sending twice) or finds nothing that
 * differs. Anything that DIFFERS — another email, another phone, another organisation or
 * place — is left for a person, who now has "Same person: combine" beside "Different people"
 * whatever the other entry's status. A different category is never combined: one person in
 * two categories is two nominations, not a repeat.
 *
 * Every automatic combine is written as a team note on the surviving nomination, so a
 * reviewer can see what was folded in and why, and "Separate" undoes it.
 */
final class NominationCombine
{
    /** Statuses a nomination is still open to review in. */
    public const OPEN = [NS::PENDING, NS::NEEDS_DETAILS, NS::SUBMITTED, NS::CHECKING];

    public static function ready(): bool
    {
        return SchemaHas::column('gates_nominations', 'merged_into');
    }

    /**
     * Combine this nomination into the earliest open one for the same person, if the record
     * says plainly they are one. Never throws; returns the id it was combined into, or null.
     */
    public static function autoCombine(int $id): ?int
    {
        if (!self::ready()) return null;
        try {
            $n = DB::table('gates_nominations')->where('id', $id)->first();
            if (!$n || !in_array((string) $n->status, self::OPEN, true) || !empty($n->merged_into)) return null;
            if ((int) ($n->not_duplicate ?? 0) === 1 || (int) ($n->category_id ?? 0) < 1) return null;
            $into = self::earliestMatch($n);
            if ($into === null) return null;
            $v = self::verdict($n, $into);
            if (!$v['auto']) return null;
            self::write($n, $into, null, 'Combined automatically: ' . $v['why']);
            return (int) $into->id;
        } catch (\Throwable $e) {
            error_log('[nomination-combine] ' . $e->getMessage());
            return null;
        }
    }

    /**
     * A reviewer's "Same person: combine". Works whatever the other entry's status is open to;
     * an APPROVED other entry is the existing merge (it puts this one on that ballot entry),
     * which the controller handles.
     *
     * @return array{ok:bool, error?:string}
     */
    public static function combine(int $id, int $intoId, int $adminId): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'The database needs its latest update before this works.'];
        $n = DB::table('gates_nominations')->where('id', $id)->first();
        $into = DB::table('gates_nominations')->where('id', $intoId)->first();
        if (!$n || !$into || $id === $intoId) return ['ok' => false, 'error' => 'Pick the nomination to combine it with.'];
        $into = self::root($into);
        if ((int) $into->id === $id) return ['ok' => false, 'error' => 'That nomination is already combined into this one.'];
        if ((int) $n->cycle_id !== (int) $into->cycle_id) return ['ok' => false, 'error' => 'Only nominations in the same edition can be combined.'];
        if (!in_array((string) $n->status, self::OPEN, true)) return ['ok' => false, 'error' => 'This nomination has already been decided.'];
        if (!in_array((string) $into->status, self::OPEN, true)) return ['ok' => false, 'error' => 'The other nomination has been decided; merge into it instead.'];
        // Whatever was combined into THIS one moves with it, so nothing is left pointing at a
        // row that has itself become a supporter.
        DB::table('gates_nominations')->where('merged_into', $id)->update(['merged_into' => (int) $into->id]);
        self::write($n, $into, $adminId, 'Combined by a reviewer.');
        return ['ok' => true];
    }

    /** Undo a combine: the entry goes back into the queue, ruled a different person. */
    public static function separate(int $id, int $adminId): bool
    {
        if (!self::ready()) return false;
        $n = DB::table('gates_nominations')->where('id', $id)->first();
        if (!$n || empty($n->merged_into) || !in_array((string) $n->status, self::OPEN, true)) return false;
        $row = ['merged_into' => null];
        if (SchemaHas::column('gates_nominations', 'not_duplicate')) $row['not_duplicate'] = 1;
        DB::table('gates_nominations')->where('id', $id)->update($row);
        \AfricaGates\Admin\Services\ReviewQueue::addNote((int) $n->merged_into, $adminId,
            'Separated ' . self::ref($n) . ' (' . trim((string) $n->nominator_name) . '): a different person. It is back in the queue on its own.');
        return true;
    }

    /**
     * The open nominations combined into this one, oldest first.
     *
     * @return list<object>
     */
    public static function supporters(int $primaryId): array
    {
        if (!self::ready()) return [];
        try {
            return DB::table('gates_nominations')->where('merged_into', $primaryId)->orderBy('created_at')->orderBy('id')->get()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The ONE clause that takes combined entries out of "waiting for review". The queue, the
     * rail badge and Home each count pending nominations; all go through this, so a combined
     * repeat can never be one more thing to review on one screen and nothing on another.
     *
     * @param \Illuminate\Database\Query\Builder $q
     * @param string $alias the table alias in $q ('' when unaliased)
     */
    public static function excludeCombined($q, string $alias = ''): void
    {
        if (!self::ready()) return;
        $p = $alias !== '' ? $alias . '.' : '';
        $q->where(static fn ($w) => $w->whereNull($p . 'merged_into')->orWhereNotIn($p . 'status', self::OPEN));
    }

    /** @param list<int> $ids @return array<int,int> primary id => number combined into it */
    public static function counts(array $ids): array
    {
        if (!self::ready() || $ids === []) return [];
        try {
            return DB::table('gates_nominations')->whereIn('merged_into', $ids)->whereIn('status', self::OPEN)
                ->groupBy('merged_into')->selectRaw('merged_into, COUNT(*) as n')->pluck('n', 'merged_into')
                ->map(static fn ($n) => (int) $n)->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The combined entries take the decision the reviewer made on the one they were folded
     * into. Approval puts them under the same category (the nominee is already one row: the
     * approve path joins entries by name); a rejection carries the same reason.
     */
    public static function follow(int $primaryId, string $status, ?int $categoryId = null, ?string $reason = null): int
    {
        if (!self::ready() || !in_array($status, [NS::APPROVED, NS::REJECTED], true)) return 0;
        $row = ['status' => $status];
        if ($status === NS::APPROVED && $categoryId) $row['category_id'] = $categoryId;
        if ($reason !== null && SchemaHas::column('gates_nominations', 'decision_reason')) $row['decision_reason'] = $reason;
        return DB::table('gates_nominations')->where('merged_into', $primaryId)->whereIn('status', self::OPEN)->update($row);
    }

    /**
     * Every open nomination, oldest first, combined where it plainly can be. The maintenance
     * tick runs it, so entries that were waiting before this shipped are resolved too.
     */
    public static function sweep(int $limit = 2000): int
    {
        if (!self::ready()) return 0;
        $n = 0;
        $q = DB::table('gates_nominations')->whereIn('status', self::OPEN)->whereNull('merged_into')->where('category_id', '>', 0);
        if (SchemaHas::column('gates_nominations', 'not_duplicate')) {
            $q->where(static fn ($w) => $w->whereNull('not_duplicate')->orWhere('not_duplicate', 0));
        }
        foreach ($q->orderBy('created_at')->orderBy('id')->limit($limit)->pluck('id') as $id) {
            if (self::autoCombine((int) $id) !== null) $n++;
        }
        return $n;
    }

    /**
     * Is the pair plainly one person? The desk's own comparison decides.
     *
     * @return array{auto:bool, why:string}
     */
    public static function verdict(object $a, object $b): array
    {
        $c = ReviewDesk::compare(self::shape($a), self::shapeOther($b));
        $v = array_column($c['rows'], 'v', 'k');
        if ($v['Category'] === 'differ') return ['auto' => false, 'why' => 'Different categories'];
        if ($v['Email'] === 'same' || $v['Phone'] === 'same') return ['auto' => true, 'why' => 'the same contact details are on both.'];
        if ($v['Nominated by'] === 'same') return ['auto' => true, 'why' => 'the same nominator sent it twice.'];
        foreach (['Email', 'Phone', 'Organisation', 'Place'] as $k) {
            if ($v[$k] === 'differ') return ['auto' => false, 'why' => $k . ' differs'];
        }
        return ['auto' => true, 'why' => 'the same name in the same category, and nothing on either tells them apart.'];
    }

    /** The earliest other open, uncombined nomination for the same person in this edition and category. */
    private static function earliestMatch(object $n): ?object
    {
        $key = NominationTriageService::nameKey((string) $n->nominee_name);
        if ($key === '') return null;
        $q = DB::table('gates_nominations')->where('cycle_id', $n->cycle_id)->where('category_id', $n->category_id)
            ->where('id', '!=', $n->id)->whereIn('status', self::OPEN)->whereNull('merged_into');
        if (SchemaHas::column('gates_nominations', 'not_duplicate')) {
            $q->where(static fn ($w) => $w->whereNull('not_duplicate')->orWhere('not_duplicate', 0));
        }
        foreach ($q->orderBy('created_at')->orderBy('id')->get() as $o) {
            // Only an EARLIER entry takes another in, so a pair is never combined both ways.
            $earlier = (string) $o->created_at < (string) $n->created_at
                || ((string) $o->created_at === (string) $n->created_at && (int) $o->id < (int) $n->id);
            if ($earlier && NominationTriageService::nameKey((string) $o->nominee_name) === $key) return $o;
        }
        return null;
    }

    private static function root(object $n): object
    {
        $seen = [];
        while (!empty($n->merged_into) && !isset($seen[(int) $n->id])) {
            $seen[(int) $n->id] = true;
            $up = DB::table('gates_nominations')->where('id', (int) $n->merged_into)->first();
            if (!$up) break;
            $n = $up;
        }
        return $n;
    }

    private static function write(object $n, object $into, ?int $adminId, string $why): void
    {
        DB::table('gates_nominations')->where('id', $n->id)->update(['merged_into' => (int) $into->id]);
        \AfricaGates\Admin\Services\ReviewQueue::addNote((int) $into->id, (int) $adminId,
            $why . ' ' . self::ref($n) . ' from ' . trim((string) $n->nominator_name) . ' is now part of this nomination: '
            . 'its reason, evidence and place count here. "Separate" on it undoes this.');
    }

    private static function ref(object $n): string
    {
        $r = trim((string) ($n->reference ?? ''));
        return $r !== '' ? $r : 'N-' . $n->id;
    }

    /** @return array<string,mixed> the shape ReviewDesk::compare() reads for "this" nomination */
    private static function shape(object $n): array
    {
        return ['name' => (string) $n->nominee_name, 'nominee_email' => (string) ($n->nominee_email ?? ''),
                'nominee_phone' => (string) ($n->nominee_phone ?? ''), 'org' => trim((string) ($n->nominee_org ?? '')),
                'nominee_state' => (string) ($n->nominee_state ?? ''), 'country_code' => (string) ($n->country_code ?? ''),
                'category' => (string) ($n->category_id ?? ''), 'by' => (string) ($n->nominator_name ?? ''),
                'nominator_email' => (string) ($n->nominator_email ?? '')];
    }

    /** @return array<string,mixed> the shape it reads for the "other" one */
    private static function shapeOther(object $o): array
    {
        $s = self::shape($o);
        $s['place'] = trim(implode(', ', array_filter([trim((string) ($o->nominee_state ?? '')), strtoupper(trim((string) ($o->country_code ?? '')))])));
        return $s;
    }
}
