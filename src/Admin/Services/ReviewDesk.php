<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Services;

use AfricaGates\Services\AwardService;
use AfricaGates\Services\DoorWelcome;
use AfricaGates\Services\NominationAsk;
use AfricaGates\Services\NominationTriageService;
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
 *
 * What it DOES check beyond the list (26 Oct 2026): the signals of
 * {@see \AfricaGates\Services\NominationSignals}, each with the rule that raised it; a
 * reviewer named on the nomination, who may not decide it ({@see ReviewConflict}); and a
 * near-miss spelling of an earlier entry (Muhammed / Mohammed), which the exact-name check
 * cannot see. A duplicate is shown SIDE BY SIDE, field by field, because "same name" is
 * the weakest evidence there is that two entries are one person and the contact details
 * on both are the strongest.
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
    public static function view(int $id, ?AwardService $awards = null, int $adminId = 0): ?array
    {
        $n = ReviewQueue::peek($id, $awards);
        if ($n === null) return null;

        $dup = in_array('dup', $n['keys'], true);
        // A near-miss spelling, only where the exact check found nothing and nobody has
        // ruled this entry a different person.
        $similar = [];
        if (!$dup && (int) ($n['not_duplicate'] ?? 0) !== 1) {
            try {
                $similar = array_column(NominationTriageService::duplicatesFor((object) $n)['similar'], 'id');
            } catch (\Throwable) {}
        }
        $earlier = self::earlier($n, $dup ? $n['dup_of'] : $similar);
        $conflict = ReviewConflict::of($adminId, $n);
        $e = $n['eligibility'];
        $short = ($e && $e['enabled'] && !$e['eligible']) ? max(1, (int) $e['min'] - (int) $e['distinct_locations']) : 0;

        $checks = [];
        if ($conflict !== null) {
            $checks[] = ['ok' => false, 'bad' => true, 't' => 'You are named on this nomination',
                         'rule' => $conflict . ' Your address matches the nominator’s or the nominee’s, compared as an inbox.'];
        }
        $checks[] = $dup
            ? ['ok' => false, 'bad' => true, 't' => 'Looks like an earlier nomination',
               'rule' => 'Two entries for one person split their support. Combine a repeat with the earlier entry (or merge it, once that one is approved); if they are different people, say so and the flag goes.']
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
        if ($similar && $earlier) {
            $checks[] = ['ok' => false, 't' => 'Spelled like “' . $earlier['name'] . '”',
                         'rule' => 'A name one or two letters from another entry in this edition. It may be a typo of the same person, or somebody else; compare them below.'];
        }
        foreach ($n['signals'] as $sg) $checks[] = ['ok' => false, 't' => $sg['t'], 'rule' => $sg['rule']];

        $nominator = (string) ($n['by'] ?? '');
        $first = DoorWelcome::firstName($nominator) ?: $nominator;

        // What was combined into this one: each repeat's nominator, place and reason, so the
        // reviewer decides with all of the support in front of them.
        $support = array_map(static fn (object $o): array => [
            'id' => (int) $o->id, 'by' => (string) ($o->nominator_name ?? ''), 'reason' => trim((string) ($o->reason ?? '')),
            'place' => trim(implode(', ', array_filter([trim((string) ($o->nominator_location ?? '')), strtoupper(trim((string) ($o->nominator_country ?? '')))]))),
            'ref' => trim((string) ($o->reference ?? '')) ?: 'N-' . $o->id,
        ], array_values(array_filter(\AfricaGates\Services\NominationCombine::supporters($id),
            static fn (object $o): bool => in_array((string) $o->status, \AfricaGates\Services\NominationCombine::OPEN, true))));

        return $n + [
            'supporters'  => $support,
            'earlier'     => $earlier,
            'similar'     => $similar !== [] && $earlier !== null,
            'compare'     => $earlier ? self::compare($n, $earlier) : null,
            'conflict'    => $conflict,
            'desk_checks' => $checks,
            'short'       => $short,
            'suggestion'  => self::suggest($n + ['conflict' => $conflict], $dup, $earlier, $short, $similar !== [] && $earlier !== null),
            'nominator_first' => $first,
            'can_ask'     => filter_var((string) ($n['nominator_email'] ?? ''), FILTER_VALIDATE_EMAIL) !== false && NominationAsk::available(),
            'ask_prefill' => self::askPrefill($first, (string) $n['name'], (int) $n['evidence']),
            'reject_reasons' => self::REASONS,
            'vol_checks'  => \AfricaGates\Services\Volunteer\NominationChecks::forNomination($id),
        ];
    }

    /**
     * The earliest other nomination under the same name in this edition — the record a
     * duplicate is compared with, and merged into.
     *
     * @return array<string,mixed>|null
     */
    private static function earlier(array $n, array $ids): ?array
    {
        if (!$ids) return null;
        $cols = ['n.id', 'n.nominee_name', 'n.nominator_name', 'n.nominator_email', 'n.status', 'n.created_at', 'n.category_id',
                 'n.country_code', 'n.nominee_state', 'c.title as category'];
        foreach (['nominee_org', 'nominee_email', 'nominee_phone'] as $c) {
            if (\AfricaGates\Support\SchemaHas::column('gates_nominations', $c)) $cols[] = 'n.' . $c;
        }
        try {
            $o = DB::table('gates_nominations as n')->leftJoin('gates_award_categories as c', 'c.id', '=', 'n.category_id')
                ->whereIn('n.id', $ids)->orderBy('n.created_at')->orderBy('n.id')->first($cols);
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
            'nominator_email' => (string) ($o->nominator_email ?? ''),
            'nominee_email'   => (string) ($o->nominee_email ?? ''),
            'nominee_phone'   => (string) ($o->nominee_phone ?? ''),
            'place'     => trim(implode(', ', array_filter([trim((string) ($o->nominee_state ?? '')), strtoupper(trim((string) ($o->country_code ?? '')))]))),
            // A merge joins an entry that is on the ballot.
            'mergeable' => (string) $o->status === 'approved' && (int) ($o->category_id ?? 0) > 0,
            // One still waiting for review is COMBINED instead, so a repeat is resolved before
            // either copy is decided (NominationCombine) — never "decide that one first".
            'combinable' => in_array((string) $o->status, \AfricaGates\Services\NominationCombine::OPEN, true),
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
    private static function suggest(array $n, bool $dup, ?array $earlier, int $short, bool $similar = false): array
    {
        if (!empty($n['conflict'])) {
            return ['tone' => 'red', 'title' => 'A colleague decides this one', 'pick' => '',
                    'why' => 'Your own email is on this nomination. Skip it; the next reviewer will see it.'];
        }
        if ($dup) {
            return ['tone' => 'red', 'title' => 'Resolve the duplicate first', 'pick' => '',
                    'why' => $earlier && $earlier['mergeable']
                        ? 'Same name as an earlier, approved nomination. Merge it if it is the same person; both nominators keep credit.'
                        : ($earlier && $earlier['status'] === 'rejected'
                            ? 'Same name as an earlier nomination that was not taken forward. Mark this a different person, or decide it on its own.'
                            : 'Same name as an earlier nomination that is still waiting. Combine them if it is the same person — you then decide once, with both nominators\' support — or mark this a different person.')];
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
        if ($similar && $earlier) {
            return ['tone' => 'gold', 'title' => 'Compare with “' . $earlier['name'] . '”', 'pick' => 'approve',
                    'why' => 'The names are one or two letters apart. Merge it if it is the same person, or say they are different.'];
        }
        if ($n['signals']) {
            return ['tone' => 'gold', 'title' => 'Check, then decide', 'pick' => 'approve',
                    'why' => count($n['signals']) === 1 ? 'One thing to check: ' . mb_strtolower(mb_substr($n['signals'][0]['t'], 0, 1)) . mb_substr($n['signals'][0]['t'], 1) . '.'
                                                       : count($n['signals']) . ' things to check below. None of them is a rule; each has its reason.'];
        }
        return ['tone' => 'green', 'title' => 'Approve', 'pick' => 'approve', 'why' => 'The checks pass and there is evidence.'];
    }

    /**
     * This nomination and the earlier one, field by field. Contact details decide it: the
     * same email or phone on both is a person, a different email on both is two people or
     * one with two addresses — and a name on its own decides nothing.
     *
     * @return array{rows:list<array{k:string,a:string,b:string,v:string}>, say:string, tone:string}
     */
    public static function compare(array $n, array $o): array
    {
        $inbox = static fn (string $e): ?string => \AfricaGates\Support\EmailCanon::hash($e);
        $tel   = static fn (string $p): ?string => \AfricaGates\Support\Phone::normalize($p, 'NG');
        $same  = static function (?string $a, ?string $b): string {
            if ($a === null || $a === '' || $b === null || $b === '') return 'missing';
            return $a === $b ? 'same' : 'differ';
        };
        $low = static fn (string $s): string => mb_strtolower(trim($s));
        $place = trim(implode(', ', array_filter([trim((string) ($n['nominee_state'] ?? '')), strtoupper(trim((string) ($n['country_code'] ?? '')))])));

        $rows = [
            ['k' => 'Name',         'a' => (string) $n['name'], 'b' => $o['name'], 'v' => $low($n['name']) === $low($o['name']) ? 'same' : 'differ'],
            ['k' => 'Email',        'a' => (string) ($n['nominee_email'] ?? ''), 'b' => $o['nominee_email'],
             'v' => $same($inbox((string) ($n['nominee_email'] ?? '')), $inbox($o['nominee_email']))],
            ['k' => 'Phone',        'a' => (string) ($n['nominee_phone'] ?? ''), 'b' => $o['nominee_phone'],
             'v' => $same($tel((string) ($n['nominee_phone'] ?? '')), $tel($o['nominee_phone']))],
            ['k' => 'Organisation', 'a' => (string) ($n['org'] ?? ''), 'b' => $o['org'], 'v' => $same($low((string) ($n['org'] ?? '')), $low($o['org']))],
            ['k' => 'Place',        'a' => $place, 'b' => $o['place'], 'v' => $same($low($place), $low($o['place']))],
            ['k' => 'Category',     'a' => (string) ($n['category'] ?? ''), 'b' => $o['category'], 'v' => $same($low((string) ($n['category'] ?? '')), $low($o['category']))],
            ['k' => 'Nominated by', 'a' => (string) ($n['by'] ?? ''), 'b' => $o['by'],
             'v' => $same($inbox((string) ($n['nominator_email'] ?? '')), $inbox($o['nominator_email']))],
        ];
        $v = array_column($rows, 'v', 'k');
        [$say, $tone] = match (true) {
            $v['Email'] === 'same' || $v['Phone'] === 'same'
                => ['The same contact details are on both: very likely one person.', 'green'],
            $v['Nominated by'] === 'same'
                => ['One nominator sent both: usually the same nomination sent twice.', 'green'],
            $v['Email'] === 'differ' || $v['Phone'] === 'differ'
                => ['Different contact details: check they are one person before merging.', 'gold'],
            default => ['Nothing on file ties them together or apart. Decide from the reasons.', 'grey'],
        };
        return ['rows' => $rows, 'say' => $say, 'tone' => $tone];
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
