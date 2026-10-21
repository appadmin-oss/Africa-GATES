<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Services;

use AfricaGates\Services\AwardService;
use AfricaGates\Services\DoorWelcome;
use AfricaGates\Services\NominationAsk;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The review desk (AdminConsole v2, README §10b "Review desk"): one nomination at a time,
 * with the decision worked out beside it.
 *
 * Everything the desk shows about a nomination is the review list's own reading of it —
 * {@see ReviewQueue::peek()} — so the desk and the list cannot disagree about whether
 * something is a duplicate or has evidence. This class adds only what the desk asks
 * that the list does not: the earlier record beside a duplicate, a suggested decision,
 * the checks with the rule behind each, and the message an "Ask for more" starts from.
 *
 * The design's checks include "Nominator phone verified". This platform verifies no
 * nominator's phone, so that check is not drawn: a tick for a verification nobody made
 * would be the desk vouching for something it never looked at.
 */
final class ReviewDesk
{
    /**
     * The four reasons a rejection may give (README: "Reject needs one of 4 reasons,
     * which are shown to the nominator"). Key → what the reviewer picks; the same words
     * reach the nominator, as a sentence.
     */
    public const REASONS = [
        'ineligible' => 'Not eligible for this edition',
        'thin'       => 'Still not enough information after we asked',
        'category'   => 'Does not fit any category in this edition',
        'conduct'    => 'Breaks the code of conduct',
    ];

    /** The sentence the nominator reads for a reason key, or '' for an unknown one. */
    public static function reasonText(string $key): string
    {
        return isset(self::REASONS[$key]) ? self::REASONS[$key] . '.' : '';
    }

    /** @return array<string,mixed>|null */
    public static function view(int $id, ?AwardService $awards = null): ?array
    {
        $n = ReviewQueue::peek($id, $awards);
        if ($n === null) return null;

        $earlier = self::earlier($n);
        $dup = in_array('dup', $n['keys'], true);
        $e = $n['eligibility'];
        $short = ($e && $e['enabled'] && !$e['eligible']) ? max(1, (int) $e['min'] - (int) $e['distinct_locations']) : 0;

        $checks = [];
        $checks[] = $dup
            ? ['ok' => false, 'bad' => true, 't' => 'Looks like an earlier nomination',
               'rule' => 'Two entries for one person split their support. Merge a repeat into the earlier entry; if they are different people, say so and the flag goes.']
            : ['ok' => true, 't' => 'No duplicate found',
               'rule' => 'Checked against every nomination in this edition under the same name, spelled the way the desk compares names.'];
        $checks[] = $n['evidence'] > 0
            ? ['ok' => true, 't' => $n['evidence'] . ' supporting file' . ($n['evidence'] === 1 ? '' : 's') . ' or link' . ($n['evidence'] === 1 ? '' : 's'),
               'rule' => 'One photo, letter or link that shows the work is enough to approve.']
            : ['ok' => false, 't' => 'No supporting files',
               'rule' => 'One photo, letter or link that shows the work is enough to approve. Without one, ask the nominator for it.'];
        if ($e && $e['enabled']) {
            $checks[] = $short === 0
                ? ['ok' => true, 't' => 'Nominated from ' . $e['distinct_locations'] . ' place' . ($e['distinct_locations'] === 1 ? '' : 's'),
                   'rule' => 'This edition needs nominations from at least ' . $e['min'] . ' different places before a nominee is approved. Set in Settings → Nomination eligibility.']
                : ['ok' => false, 't' => 'Needs ' . $short . ' more place' . ($short === 1 ? '' : 's'),
                   'rule' => 'This edition needs nominations from at least ' . $e['min'] . ' different places before a nominee is approved, and this one has ' . $e['distinct_locations'] . '. It can be approved once more arrive.'];
        }
        if ($n['suggest']) {
            $checks[] = ['ok' => false, 't' => 'Reads closer to “' . $n['suggest']['title'] . '”',
                         'rule' => 'Worked out from the words of the reason, nothing more. Approve into the category the reason describes; you decide which that is.'];
        }

        $nominator = (string) ($n['by'] ?? '');
        $first = DoorWelcome::firstName($nominator) ?: $nominator;

        return $n + [
            'earlier'     => $earlier,
            'desk_checks' => $checks,
            'short'       => $short,
            'suggestion'  => self::suggest($n, $dup, $earlier, $short),
            'nominator_first' => $first,
            'can_ask'     => filter_var((string) ($n['nominator_email'] ?? ''), FILTER_VALIDATE_EMAIL) !== false && NominationAsk::available(),
            'ask_prefill' => self::askPrefill($first, (string) $n['name'], (int) $n['evidence']),
            'reject_reasons' => self::REASONS,
        ];
    }

    /**
     * The earliest other nomination under the same name in this edition — the record a
     * duplicate is compared with, and merged into.
     *
     * @return array<string,mixed>|null
     */
    private static function earlier(array $n): ?array
    {
        if (!$n['dup_of']) return null;
        $cols = ['n.id', 'n.nominee_name', 'n.nominator_name', 'n.status', 'n.created_at', 'n.category_id', 'c.title as category'];
        if (\AfricaGates\Support\SchemaHas::column('gates_nominations', 'nominee_org')) $cols[] = 'n.nominee_org';
        try {
            $o = DB::table('gates_nominations as n')->leftJoin('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->whereIn('n.id', $n['dup_of'])->orderBy('n.created_at')->orderBy('n.id')->first($cols);
        } catch (\Throwable) {
            return null;
        }
        if (!$o) return null;
        $at = strtotime((string) $o->created_at) ?: time();
        return [
            'id'        => (int) $o->id,
            'name'      => (string) $o->nominee_name,
            'org'       => trim((string) ($o->nominee_org ?? '')),
            'by'        => (string) ($o->nominator_name ?? ''),
            'status'    => (string) $o->status,
            'status_label' => \AfricaGates\Support\NominationStatus::label((string) $o->status),
            'when'      => ReviewQueue::ago(time() - $at, $at),
            'category'  => (string) ($o->category ?? ''),
            // A merge joins an entry that is on the ballot; anything else is decided first.
            'mergeable' => (string) $o->status === 'approved' && (int) ($o->category_id ?? 0) > 0,
            // The other entry is usually the older one, but not always (a reseeded or
            // imported row); the pane says "Earlier" only when it is.
            'older'     => (string) $o->created_at <= (string) ($n['created_at'] ?? ''),
        ];
    }

    /**
     * The "Suggested" card (README: duplicate → resolve first; something missing → ask
     * for more; category flag → move, then approve; else approve).
     *
     * @return array{tone:string, title:string, why:string, pick:string}
     */
    private static function suggest(array $n, bool $dup, ?array $earlier, int $short): array
    {
        if ($dup) {
            return ['tone' => 'red', 'title' => 'Resolve the duplicate first', 'pick' => '',
                    'why' => $earlier && $earlier['mergeable']
                        ? 'Same name as an earlier, approved nomination. Merge it if it is the same person; both nominators keep credit.'
                        : ($earlier && $earlier['status'] === 'rejected'
                            ? 'Same name as an earlier nomination that was not taken forward. Mark this a different person, or decide it on its own.'
                            : 'Same name as an earlier nomination that is not approved yet. Decide that one first, or mark this a different person.')];
        }
        if ((int) $n['evidence'] === 0) {
            return ['tone' => 'gold', 'title' => 'Ask for more', 'pick' => 'ask', 'why' => 'There is no supporting file or link.'];
        }
        if ($short > 0) {
            return ['tone' => 'gold', 'title' => 'Not yet', 'pick' => '',
                    'why' => 'It needs nominations from ' . $short . ' more place' . ($short === 1 ? '' : 's') . ' before it can be approved. Skip it for now.'];
        }
        if ((int) ($n['category_id'] ?? 0) < 1) {
            return ['tone' => 'gold', 'title' => 'Choose a category, then approve', 'pick' => 'approve', 'why' => 'It was sent without one.'];
        }
        if ($n['suggest']) {
            return ['tone' => 'gold', 'title' => 'Move, then approve', 'pick' => 'approve',
                    'why' => 'The reason reads closer to “' . $n['suggest']['title'] . '”.'];
        }
        return ['tone' => 'green', 'title' => 'Approve', 'pick' => 'approve', 'why' => 'The checks pass and there is evidence.'];
    }

    /** The message an "Ask for more" starts from. The reviewer edits it before it goes. */
    public static function askPrefill(string $first, string $nominee, int $evidence): string
    {
        $hello = $first !== '' ? 'Hello ' . $first . ', thank you' : 'Thank you';
        return $evidence === 0
            ? $hello . ' for nominating ' . $nominee . '. Before we can review it, please add one photo, letter or link that shows their work. It takes about two minutes.'
            : $hello . ' for nominating ' . $nominee . '. Before we can review it, please add a little more that shows their work: a photo, a letter or a link.';
    }
}
