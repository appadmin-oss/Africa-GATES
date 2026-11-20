<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Services;

use AfricaGates\Services\VoterReach;
use AfricaGates\Support\EmailCanon;
use AfricaGates\Support\Phone;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * How many PEOPLE voted, as opposed to how many voter ids the ledger holds.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THREE FIGURES, EACH A DIFFERENT CLAIM
 * ══════════════════════════════════════════════════════════════════════════════
 *
 *   recorded ids   COUNT(DISTINCT voter_email_hash) — what Analytics printed as "distinct
 *                  voters". Three of the five services that mint a vote write a RANDOM
 *                  hash per order or grant (VoterReach's docblock), so one buyer of ten
 *                  orders is ten "voters" by this count. Shown only to say why it is wrong.
 *   people         VoterReach's rule: a paid order is its buyer, a points redemption is its
 *                  member, a grant is nobody, a fraud-flagged row is nobody. The rule the
 *                  CPI pays its 70% on, so the console and the score agree.
 *   net people     people, joined where two of them are one inbox (Support\EmailCanon:
 *                  "ada+votes@" and "a.da@gmail.com" are ada@gmail.com). Certain: the
 *                  vote was confirmed by a code sent to that inbox.
 *
 * and one estimate beside them, labelled as one: people who typed the SAME phone number
 * on two addresses. A voter's number is typed, never verified, so it is evidence and not
 * proof — and a number typed by more than {@see PHONE_CAP} addresses is somebody's
 * placeholder ("08000000000"), joining strangers; those are set aside and counted.
 *
 * NOTHING HERE CHANGES WHO MAY VOTE OR WHAT A VOTE SCORES. The one-vote rule stays on
 * `voter_email_hash` and the CPI stays on VoterReach. A rule that reads "one inbox is one
 * person" into the score is a new scoring basis (CLAUDE.md: an announced standing must stay
 * reproducible), and it would be added as one, named and settable — not slipped in here.
 *
 * Device sharing is reported and never joins anyone: a family, a school lab and a cybercafé
 * all put several real people on one device.
 *
 * No voter's address or number is sent to the AI, by design: the AI is not needed to read
 * an inbox, and a voter list is the most sensitive data this platform holds.
 */
final class VoterPeople
{
    public const PHONE_CAP = 3;
    private const CHUNK = 5000;

    /**
     * @param int|null $cycleId one edition, or null for every vote on record
     * @return array<string,mixed>
     */
    public static function count(?int $cycleId = null): array
    {
        $hasCanon = SchemaHas::column('gates_votes', 'voter_canon_hash');
        $hasPhone = SchemaHas::column('gates_votes', 'voter_phone');
        $hasFraud = SchemaHas::column('gates_votes', 'fraud_flag');
        $hasDevice = SchemaHas::column('gates_votes', 'device_hash');
        $cols = ['v.id', 'v.voter_email_hash', 'v.vote_type', 'v.donation_id', 'v.nominee_country'];
        if ($hasCanon) $cols[] = 'v.voter_canon_hash';
        if ($hasPhone) $cols[] = 'v.voter_phone';
        if ($hasFraud) $cols[] = 'v.fraud_flag';
        if ($hasDevice) $cols[] = 'v.device_hash';

        $raw = []; $persons = []; $rows = 0; $fraud = 0; $grants = 0;
        $canonOf = []; $phonesOf = []; $devices = []; $donIds = [];
        $last = 0;
        try {
            while (true) {
                $q = DB::table('gates_votes as v')->where('v.id', '>', $last)->orderBy('v.id')->limit(self::CHUNK);
                if ($cycleId !== null) {
                    $q->join('gates_award_categories as c', 'c.id', '=', 'v.category_id')->where('c.cycle_id', $cycleId);
                }
                $batch = $q->get($cols);
                if ($batch->isEmpty()) break;
                $buyers = VoterReach::buyerHashes($batch);
                foreach ($batch as $r) {
                    $last = (int) $r->id;
                    $rows++;
                    $raw[(string) $r->voter_email_hash] = true;
                    if ((int) ($r->fraud_flag ?? 0) !== 0) { $fraud++; continue; }
                    $p = VoterReach::personKey($r, $buyers);
                    if ($p === null) { $grants++; continue; }
                    $persons[$p] = true;
                    if (!empty($r->voter_canon_hash)) $canonOf[$p][(string) $r->voter_canon_hash] = true;
                    $e164 = Phone::normalize($r->voter_phone ?? null, $r->nominee_country ?? null);
                    if ($e164 !== null) $phonesOf[$p][$e164] = true;
                    if (!empty($r->device_hash)) $devices[(string) $r->device_hash][$p] = true;
                    if (str_starts_with((string) $r->voter_email_hash, 'paidvote:') && (int) $r->donation_id > 0) {
                        $donIds[(int) $r->donation_id] = $p;
                    }
                }
            }
            // A buyer's inbox and number come from their order, which kept them in full.
            foreach (array_chunk(array_keys($donIds), 500, true) as $chunk) {
                foreach (DB::table('gates_donations')->whereIn('id', $chunk)->get(['id', 'donor_email', 'donor_phone']) as $d) {
                    $p = $donIds[(int) $d->id] ?? null;
                    if ($p === null) continue;
                    if ($h = EmailCanon::hash($d->donor_email)) $canonOf[$p][$h] = true;
                    if ($e = Phone::normalize($d->donor_phone ?? null, null)) $phonesOf[$p][$e] = true;
                }
            }
        } catch (\Throwable $e) {
            error_log('[voter-people] ' . $e->getMessage());
            return ['ok' => false, 'error' => 'The vote ledger could not be read.'] + self::zero();
        }

        $ids = array_keys($persons);
        $index = array_flip($ids);
        $parent = range(0, max(0, count($ids) - 1));
        $find = static function (int $x) use (&$parent): int {
            while ($parent[$x] !== $x) { $parent[$x] = $parent[$parent[$x]]; $x = $parent[$x]; }
            return $x;
        };
        $union = static function (int $a, int $b) use (&$parent, $find): bool {
            $ra = $find($a); $rb = $find($b);
            if ($ra === $rb) return false;
            $parent[max($ra, $rb)] = min($ra, $rb);
            return true;
        };
        $roots = static function () use ($ids, $find): int {
            $r = [];
            foreach ($ids as $i => $_) $r[$find($i)] = true;
            return count($r);
        };

        // 1. One inbox is one person.
        $byCanon = [];
        foreach ($canonOf as $p => $hs) foreach ($hs as $h => $_) $byCanon[$h][] = $index[$p];
        $aliasJoins = 0;
        foreach ($byCanon as $members) for ($i = 1; $i < count($members); $i++) if ($union($members[0], $members[$i])) $aliasJoins++;
        $net = $ids ? $roots() : 0;

        // 2. The estimate: one number typed on a few addresses.
        $byPhone = [];
        foreach ($phonesOf as $p => $ps) foreach ($ps as $e => $_) $byPhone[$e][] = $index[$p];
        $phoneJoins = 0; $placeholders = 0;
        foreach ($byPhone as $members) {
            if (count($members) > self::PHONE_CAP) { $placeholders++; continue; }
            for ($i = 1; $i < count($members); $i++) if ($union($members[0], $members[$i])) $phoneJoins++;
        }
        $likely = $ids ? $roots() : 0;

        $sharedDevices = 0;
        foreach ($devices as $ps) if (count($ps) > 1) $sharedDevices++;

        return [
            'ok'             => true,
            'rows'           => $rows,
            'recorded_ids'   => count($raw),
            'people'         => count($persons),
            'net'            => $net,
            'likely'         => $likely,
            'alias_joins'    => $aliasJoins,
            'phone_joins'    => $phoneJoins,
            'placeholders'   => $placeholders,
            'shared_devices' => $sharedDevices,
            'fraud_rows'     => $fraud,
            'grant_rows'     => $grants,
            // How much of the ledger the inbox rule can see: rows cast before the column
            // only kept a hash of the address as typed, and that cannot be reduced now.
            'canon_coverage' => count($persons) ? round(count($canonOf) / count($persons), 3) : 0.0,
            'canon_column'   => $hasCanon,
        ];
    }

    /** @return array<string,mixed> */
    private static function zero(): array
    {
        return ['rows' => 0, 'recorded_ids' => 0, 'people' => 0, 'net' => 0, 'likely' => 0, 'alias_joins' => 0,
                'phone_joins' => 0, 'placeholders' => 0, 'shared_devices' => 0, 'fraud_rows' => 0, 'grant_rows' => 0,
                'canon_coverage' => 0.0, 'canon_column' => false];
    }
}
