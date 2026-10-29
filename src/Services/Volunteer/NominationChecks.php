<?php
declare(strict_types=1);

namespace AfricaGates\Services\Volunteer;

use AfricaGates\Admin\Services\ReviewQueue;
use AfricaGates\Services\Grants;
use AfricaGates\Support\NominationStatus as NS;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Check nominations: a volunteer's half of the review desk (consoles v7 §6.2, G5).
 *
 * ── MASKED ON THE SERVER, OR NOT AT ALL ──────────────────────────────────────
 *
 * The README is plain: phone numbers, emails, scores and judges' notes are never SENT to a
 * volunteer, and masking in CSS is a failed PR. So nothing here hands on what ReviewQueue
 * reads: {@see view()} copies an allowlist of fields out of it. The nominator is never named
 * — not their name, address, phone or relation — and an uploaded file is listed by its label
 * only, because its path is an admin URL. A field added to the queue later reaches a volunteer
 * only when somebody adds it to the list here, on purpose.
 *
 * ── A RECOMMENDATION, WITH A NAME ────────────────────────────────────────────
 *
 * A check moves nothing: no status, no category, no merge. It is one row a moderator reads
 * beside the nomination (ReviewDesk), with the volunteer's name on it. One per volunteer per
 * nomination, and never edited — "I changed my mind" is a note to the moderator, not a rewrite
 * of what they may already have read.
 */
final class NominationChecks
{
    public const CAPABILITY = 'vol.nomination_check';

    /** What a flag can say (the DC's four), and what the moderator reads. */
    public const REASONS = [
        'category'  => 'Wrong category',
        'evidence'  => 'Evidence missing',
        'duplicate' => 'Possible duplicate',
        'other'     => 'Something else',
    ];

    public static function ready(): bool
    {
        return SchemaHas::table('gates_vol_nom_checks');
    }

    public static function allowed(object $v, int $cycleId): bool
    {
        return Grants::allows('member', (int) $v->user_id, self::CAPABILITY, 'cycle', $cycleId);
    }

    /**
     * Nominations in this edition waiting for their first look and not yet checked by this
     * volunteer, oldest first. Only `pending`: one a moderator has already sent back for details
     * has had its decision, and a check after it could only ever "agree". Masked: see the class
     * note.
     *
     * @return list<array{id:int, ref:string, name:string, category:string, when:string, flag:?array}>
     */
    public static function queue(object $v, int $cycleId): array
    {
        if (!self::ready() || !self::allowed($v, $cycleId)) return [];
        $mine = DB::table('gates_vol_nom_checks')->where('volunteer_id', $v->id)->pluck('nomination_id')->map(static fn ($i) => (int) $i)->all();
        $q = DB::table('gates_nominations as n')->leftJoin('gates_award_categories as c', 'c.id', '=', 'n.category_id')
            ->where('n.cycle_id', $cycleId)->where('n.status', NS::PENDING)
            ->orderBy('n.created_at')->orderBy('n.id')->limit(200);
        if ($mine) $q->whereNotIn('n.id', $mine);
        $out = [];
        foreach ($q->get(['n.id', 'n.nominee_name', 'n.created_at', 'c.title as category']) as $n) {
            $out[] = ['id' => (int) $n->id, 'ref' => 'N-' . $n->id, 'name' => (string) $n->nominee_name,
                      'category' => (string) ($n->category ?? ''), 'when' => Workspace::day((string) $n->created_at), 'flag' => null];
        }
        return $out;
    }

    /**
     * One nomination, as a volunteer may see it — or null when it is not theirs to check
     * (no live grant at its edition, or it is no longer waiting).
     *
     * @return array<string,mixed>|null
     */
    public static function view(object $v, int $cycleId, int $nominationId): ?array
    {
        if (!self::ready() || !self::allowed($v, $cycleId)) return null;
        $n = ReviewQueue::peek($nominationId);
        if ($n === null || (int) $n['cycle_id'] !== $cycleId || (string) $n['status'] !== NS::PENDING) return null;

        $reasons = array_map(static fn (array $r): array => ['title' => (string) $r['title'], 'reason' => (string) $r['reason']], $n['reasons'] ?? []);
        if (!$reasons && trim((string) ($n['reason'] ?? '')) !== '') $reasons[] = ['title' => (string) $n['category'], 'reason' => trim((string) $n['reason'])];
        $evidence = array_map(static fn (array $e): array => $e['kind'] === 'file'
            ? ['label' => (string) $e['label'], 'href' => null, 'kind' => 'File']
            : ['label' => (string) $e['label'], 'href' => (string) $e['href'], 'kind' => 'Link'], $n['evidence_list'] ?? []);
        $checks = array_map(static fn (array $c): array => ['t' => (string) $c['t'], 'ok' => (bool) $c['ok']], $n['check_list'] ?? []);

        return [
            'id'       => (int) $n['id'],
            'ref'      => 'N-' . (int) $n['id'],
            'name'     => (string) $n['name'],
            'category' => (string) $n['category'],
            'place'    => trim((string) ($n['nominee_state'] ?? '')),
            'when'     => Workspace::day((string) $n['created_at']),
            'reasons'  => $reasons,
            'evidence' => $evidence,
            'checks'   => $checks,
            'checked'  => DB::table('gates_vol_nom_checks')->where('volunteer_id', $v->id)->where('nomination_id', $nominationId)->exists(),
        ];
    }

    /**
     * Record a check. `rec` is `complete` or `flag`; a flag needs one of {@see REASONS}, and
     * "Something else" needs a note, because a flag the moderator cannot act on is noise.
     *
     * @return array{ok:bool, error?:string}
     */
    public static function check(object $v, int $cycleId, int $nominationId, string $rec, string $reason, string $note): array
    {
        if (!self::ready()) return ['ok' => false, 'error' => 'Checks are not set up yet.'];
        if (!self::allowed($v, $cycleId)) return ['ok' => false, 'error' => 'You don’t check nominations on this award.'];
        $n = DB::table('gates_nominations')->where('id', $nominationId)->first(['id', 'cycle_id', 'status']);
        if (!$n || (int) $n->cycle_id !== $cycleId) return ['ok' => false, 'error' => 'That nomination isn’t on this award.'];
        if ((string) $n->status !== NS::PENDING) return ['ok' => false, 'error' => 'A moderator has already decided that one.'];
        if (!in_array($rec, ['complete', 'flag'], true)) return ['ok' => false, 'error' => 'Say whether it looks complete or what’s wrong.'];
        $note = trim(mb_substr($note, 0, 500));
        if ($rec === 'flag') {
            if (!isset(self::REASONS[$reason])) return ['ok' => false, 'error' => 'Pick what’s wrong.'];
            if ($reason === 'other' && $note === '') return ['ok' => false, 'error' => 'Say what’s wrong in a line.'];
        } else {
            $reason = '';
        }
        $made = DB::table('gates_vol_nom_checks')->insertOrIgnore([
            'volunteer_id' => (int) $v->id, 'nomination_id' => $nominationId, 'cycle_id' => $cycleId,
            'rec' => $rec, 'reason' => $reason ?: null, 'note' => $note !== '' ? $note : null, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $made > 0 ? ['ok' => true] : ['ok' => false, 'error' => 'You’ve already checked that one.'];
    }

    /** The words a moderator and the volunteer read for a check. */
    public static function label(string $rec, ?string $reason): string
    {
        return $rec === 'complete' ? 'Looks complete' : (self::REASONS[(string) $reason] ?? 'Flagged');
    }

    /**
     * What the moderator did with a nomination this volunteer checked, as agreed, differed or
     * waiting. A "looks complete" agrees with an approval; a flag agrees with a return for
     * details or a rejection. Anything still undecided is waiting, and counts for nothing.
     */
    public static function outcome(string $rec, string $status): string
    {
        $approved = in_array($status, [NS::APPROVED, NS::VERIFIED], true);
        $refused  = in_array($status, [NS::REJECTED, NS::NEEDS_DETAILS], true);
        if (!$approved && !$refused) return 'waiting';
        return ($rec === 'complete') === $approved ? 'agreed' : 'differed';
    }

    /** @return list<array{ref:string, name:string, mine:string, theirs:string, outcome:string}> newest first */
    public static function done(object $v, int $cycleId): array
    {
        if (!self::ready()) return [];
        $out = [];
        foreach (DB::table('gates_vol_nom_checks as k')->join('gates_nominations as n', 'n.id', '=', 'k.nomination_id')
                     ->where('k.volunteer_id', $v->id)->where('k.cycle_id', $cycleId)->orderByDesc('k.id')->limit(100)
                     ->get(['k.rec', 'k.reason', 'n.id', 'n.nominee_name', 'n.status']) as $r) {
            $o = self::outcome((string) $r->rec, (string) $r->status);
            $out[] = ['ref' => 'N-' . $r->id, 'name' => (string) $r->nominee_name, 'mine' => self::label((string) $r->rec, $r->reason),
                      'theirs' => $o === 'waiting' ? 'Waiting for a moderator' : NS::label((string) $r->status), 'outcome' => $o];
        }
        return $out;
    }

    /**
     * Agreement with the moderators, over decided checks only. Null with nothing decided yet:
     * a percentage of nothing is not a figure (§8).
     *
     * @return array{decided:int, agreed:int, differed:int, pct:int}|null
     */
    public static function agreement(object $v, int $cycleId): ?array
    {
        $a = 0; $d = 0;
        foreach (self::done($v, $cycleId) as $r) {
            if ($r['outcome'] === 'agreed') $a++;
            if ($r['outcome'] === 'differed') $d++;
        }
        $n = $a + $d;
        return $n ? ['decided' => $n, 'agreed' => $a, 'differed' => $d, 'pct' => (int) round($a * 100 / $n)] : null;
    }

    /**
     * The checks on one nomination, for the moderator's desk: who, what, when.
     *
     * @return list<array{by:string, said:string, note:string, when:string}>
     */
    public static function forNomination(int $nominationId): array
    {
        if (!self::ready()) return [];
        $out = [];
        try {
            foreach (DB::table('gates_vol_nom_checks')->where('nomination_id', $nominationId)->orderBy('id')->get() as $k) {
                $vol = DB::table('gates_volunteers')->where('id', $k->volunteer_id)->first();
                $out[] = ['by' => $vol ? Volunteers::name($vol) : 'A volunteer', 'said' => self::label((string) $k->rec, $k->reason),
                          'note' => (string) ($k->note ?? ''), 'when' => Workspace::day((string) $k->created_at), 'flag' => $k->rec === 'flag'];
            }
        } catch (\Throwable) {}
        return $out;
    }
}
