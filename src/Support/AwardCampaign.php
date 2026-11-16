<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use AfricaGates\Services\PaidVoteService;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * AN AWARD'S CAMPAIGN — why somebody should vote, in the award's own words (owner, 16 Nov
 * 2026: "make the voting page more celebratory … the voter needs to feel a reason to truly
 * vote", with the Afrovanguard Community Wealth & Impact Framework as the brief).
 *
 * ── WHAT IS HERE, AND WHAT IS DELIBERATELY NOT ───────────────────────────────
 * The community the award belongs to ("Alimosho is celebrating its own"), its rally line and
 * promise, the share of a contributed vote that reaches a NAMED beneficiary, the benefits a
 * supporter's votes unlock, and whether the vote page shows the Supporter Spotlight.
 *
 * Every one of them is blank until an operator types it, and a blank one draws nothing. The
 * framework is explicit that thresholds, benefits and allocations need approval "against
 * actual costs before publication", and this repo has already shipped a banner whose default
 * was a claim nobody typed (Services\SiteNotice). So there is no default sentence about
 * money, no default benefit and no default beneficiary — the page says what the host chose to
 * promise and nothing else.
 *
 * ── ONLY THE BENEFICIARY'S SHARE IS STATED ───────────────────────────────────
 * "₦60 of every ₦200 vote goes to CACENTRE" is a fact the host can stand behind. The other
 * ₦140 carries event, award and nominee obligations, and the framework's first financial
 * correction is that it "cannot automatically be described as money going to the hostel".
 * There is therefore no field for what the remainder does: a second, typed claim about the
 * same ₦200 is the one this page must never make. `remainder_note` exists for the host to say
 * plainly what it covers, in their words, and is drawn as their sentence.
 *
 * One JSON document for the reason `details_json` is one: read once per page for one
 * programme, filtered by nothing, so a new field needs no migration. Cleaned on the way OUT
 * as well as in (a document outlives the code that wrote it).
 */
final class AwardCampaign
{
    /** The award's words. Every one optional. */
    public const FIELDS = [
        'community' => ['label' => 'The community this award belongs to', 'max' => 60,
            'help' => 'One place or group, e.g. "Alimosho". Shared nominees then read "Alimosho is celebrating its own: Chiamaka Eze".'],
        'rally' => ['label' => 'Rally line', 'max' => 120,
            'help' => 'The question or call at the top of the vote page, e.g. "Who will celebrate our own, if not us?"'],
        'promise' => ['label' => 'The campaign promise', 'max' => 160,
            'help' => 'One line under it, e.g. "Celebrate your own. Empower a child. Build a legacy."'],
        'beneficiary' => ['label' => 'Who receives a share of each contributed vote', 'max' => 120,
            'help' => 'A named beneficiary, e.g. "CACENTRE, Egbeda and Ayobo centres". Shown only with a share below.'],
        'remainder_note' => ['label' => 'What the rest of each vote covers', 'max' => 200,
            'help' => 'Optional, in your words, e.g. "The rest funds the concert, the awards and nominee prizes." Never describe money as going somewhere it has not been allocated.'],
        'ladder_note' => ['label' => 'The rule under the benefits', 'max' => 200,
            'help' => 'e.g. "Subject to centre capacity. Each benefit is redeemed once, by the person who voted."'],
    ];

    public const LADDER_MAX = 8;
    public const BENEFIT_MAX = 140;
    public const MAX_JSON_BYTES = 20_000;

    /**
     * The campaign, complete and safe to print.
     *
     * @return array{community:string,rally:string,promise:string,beneficiary:string,remainder_note:string,
     *               ladder_note:string,share_pct:int,spotlight:bool,ladder:list<array{votes:int,benefit:string}>}
     */
    public static function of(?object $programme): array
    {
        $raw = is_object($programme) ? ($programme->campaign_json ?? null) : null;
        $doc = is_string($raw) ? json_decode($raw, true) : null;
        $doc = is_array($doc) ? $doc : [];

        $out = [];
        foreach (self::FIELDS as $k => $f) {
            $v = is_scalar($doc[$k] ?? null) ? trim((string) $doc[$k]) : '';
            $out[$k] = Brand::normalise(mb_substr(strip_tags($v), 0, $f['max']));
        }
        $out['share_pct'] = max(0, min(100, (int) ($doc['share_pct'] ?? 0)));
        $out['spotlight'] = !empty($doc['spotlight']);
        $out['ladder']    = self::ladder($doc['ladder'] ?? []);
        return $out;
    }

    /** By programme id, for the readers that hold only an id. */
    public static function forProgramme(int $programmeId): array
    {
        if ($programmeId < 1 || !SchemaHas::column('gates_award_programmes', 'campaign_json')) return self::of(null);
        try {
            return self::of(DB::table('gates_award_programmes')->where('id', $programmeId)->first(['id', 'campaign_json']));
        } catch (\Throwable) {
            return self::of(null);
        }
    }

    /**
     * "Of every ₦200 vote, ₦60 goes to CACENTRE, Egbeda and Ayobo centres." — or '' when the
     * host has not named a beneficiary AND a share, or votes are not contributions here.
     * Rounded DOWN, so it never promises a kobo more than the share gives.
     */
    public static function impact(array $c, ?int $price = null): string
    {
        if ($c['beneficiary'] === '' || $c['share_pct'] < 1 || !PaidVoteService::enabled()) return '';
        $price ??= PaidVoteService::pricePerVote();
        if ($price < 1) return '';
        $share = intdiv($price * $c['share_pct'], 100);
        if ($share < 1) return '';
        return Translator::t('Of every ₦%price% vote, ₦%share% goes to %who%.', [
            '%price%' => number_format($price), '%share%' => number_format($share), '%who%' => $c['beneficiary'],
        ]);
    }

    /**
     * "Alimosho is celebrating its own: Chiamaka Eze" — the line a shared nominee carries, or
     * '' when the award has not said whose community it is.
     */
    public static function celebrating(array $c, string $nominee): string
    {
        $nominee = trim($nominee);
        if ($c['community'] === '' || $nominee === '') return '';
        return Translator::t('%place% is celebrating its own: %name%', ['%place%' => $c['community'], '%name%' => $nominee]);
    }

    /**
     * Where a supporter stands on the ladder: what they have, and the next step.
     *
     * @return array{have:list<array{votes:int,benefit:string}>, next:?array{votes:int,benefit:string,more:int}}
     */
    public static function standing(array $c, int $votes): array
    {
        $have = []; $next = null;
        foreach ($c['ladder'] as $step) {
            if ($votes >= $step['votes']) { $have[] = $step; continue; }
            $next = $step + ['more' => $step['votes'] - $votes];
            break;
        }
        return ['have' => $have, 'next' => $next];
    }

    /**
     * Store a campaign from the admin form.
     *
     * @param array<string,mixed> $input `campaign[community]`…, `campaign[share_pct]`,
     *                                    `campaign[spotlight]`, `campaign[ladder_votes][]`/`[ladder_benefit][]`
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

        $pct = trim((string) ($input['share_pct'] ?? ''));
        if ($pct !== '') {
            if (!ctype_digit($pct) || (int) $pct > 100) return 'The beneficiary share is a whole percentage from 0 to 100.';
            if ((int) $pct > 0) {
                if (!isset($doc['beneficiary'])) return 'Name who receives the share, or leave the share blank.';
                $doc['share_pct'] = (int) $pct;
            }
        }
        if (!empty($input['spotlight'])) $doc['spotlight'] = true;

        $vs = (array) ($input['ladder_votes'] ?? []);
        $bs = (array) ($input['ladder_benefit'] ?? []);
        $rows = []; $seen = [];
        foreach ($vs as $i => $v) {
            $v = trim((string) $v); $b = trim(strip_tags((string) ($bs[$i] ?? '')));
            if ($v === '' && $b === '') continue;
            if ($v === '' || !ctype_digit($v) || (int) $v < 1) return 'Each benefit needs the number of votes that unlocks it.';
            if ($b === '') return 'Say what ' . $v . ' votes unlock, or clear that row.';
            if (mb_strlen($b) > self::BENEFIT_MAX) return 'A benefit is at most ' . self::BENEFIT_MAX . ' characters.';
            if (isset($seen[(int) $v])) return 'Two benefits are set at ' . $v . ' votes. Give each its own number.';
            $seen[(int) $v] = true;
            $rows[] = ['votes' => (int) $v, 'benefit' => Brand::normalise($b)];
        }
        if (count($rows) > self::LADDER_MAX) return 'Up to ' . self::LADDER_MAX . ' benefits. Remove one to add another.';
        if ($rows !== []) $doc['ladder'] = self::ladder($rows);

        $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) return 'The campaign could not be stored. Please check for unusual characters.';
        if (strlen($json) > self::MAX_JSON_BYTES) return 'The campaign is too long to store. Please shorten it.';
        try {
            DB::table('gates_award_programmes')->where('id', $programmeId)
                ->update(['campaign_json' => $doc === [] ? null : $json]);
        } catch (\Throwable) {
            return 'The campaign could not be saved. Nothing was changed.';
        }
        return null;
    }

    /**
     * A clean ladder: whole positive thresholds, one benefit each, lowest first, capped.
     *
     * @return list<array{votes:int,benefit:string}>
     */
    private static function ladder(mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $r) {
            if (!is_array($r)) continue;
            $v = (int) ($r['votes'] ?? 0);
            $b = trim(strip_tags((string) ($r['benefit'] ?? '')));
            if ($v < 1 || $b === '' || isset($out[$v])) continue;
            $out[$v] = ['votes' => $v, 'benefit' => Brand::normalise(mb_substr($b, 0, self::BENEFIT_MAX))];
        }
        ksort($out);
        return array_slice(array_values($out), 0, self::LADDER_MAX);
    }
}
