<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use AfricaGates\Services\Mail\SendPolicy;
use AfricaGates\Support\EmailCanon;
use AfricaGates\Support\Phone;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\UploadedFileInterface;

/**
 * What a reviewer should know about a nomination before deciding it, that the nomination
 * itself does not say (the review list, its side peek and the desk).
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * EXACT COMPARISONS ONLY, AND NONE OF THEM DECIDES ANYTHING
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Every signal is a comparison of two stored values — an inbox, a phone number, a device,
 * a file's fingerprint, a passage of text — so it can be reproduced months later from the
 * same rows and explained to the nominator it held up. No score, no model, no threshold
 * a reviewer cannot read. ClaimIndependence takes the same position for claims, and for the
 * same reason.
 *
 * And a signal only INFORMS. Each one has an honest explanation as well as a dishonest
 * one: a daughter types her own email for her mother; a classroom nominates its teacher
 * from the school's one computer; a pastor writes the reference every member then pastes.
 * So each carries its rule — what was compared, and what the innocent reading is — and the
 * reviewer decides. The one control here that does decide is in AwardService: a nominator
 * counts once towards the "different places" rule, because a person typing three states is
 * not three places.
 *
 * ── WHAT IS DELIBERATELY NOT COMPARED ─────────────────────────────────────────
 *   · The connection (`ip_hash`). Mobile carriers here put thousands of unrelated people
 *     behind one address (carrier-grade NAT), so "same connection" is true of strangers
 *     and would teach a reviewer to ignore the panel. The DEVICE fingerprint is compared.
 *   · Anything about the nominee's identity beyond what the nominator typed. Nothing here
 *     has verified a nominee, and no signal may read as though it had.
 */
final class NominationSignals
{
    /** A reason shorter than this is too generic to call "copied" ("She is a great teacher"). */
    public const COPY_MIN_WORDS = 20;
    /** Share of three-word phrases two reasons must have in common to read as one text. */
    public const COPY_OVERLAP = 0.8;

    /**
     * The upload's fingerprint, taken before the upload service resizes or re-encodes it —
     * after that, two uploads of one photograph no longer share a byte.
     */
    public static function uploadHash(UploadedFileInterface $file): ?string
    {
        try {
            if ($file->getError() !== UPLOAD_ERR_OK) return null;
            $s = $file->getStream();
            if ($s->isSeekable()) $s->rewind();
            $ctx = hash_init('sha256');
            while (!$s->eof()) {
                $chunk = $s->read(1048576);
                if ($chunk === '') break;
                hash_update($ctx, $chunk);
            }
            if ($s->isSeekable()) $s->rewind();
            return hash_final($ctx);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The signals for each nomination, worked out against every nomination in the same
     * editions in a handful of queries.
     *
     * @param list<int> $ids
     * @return array<int, list<array{key:string, t:string, tone:string, rule:string, with:list<int>}>>
     */
    public static function forIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === []) return [];

        $cols = ['id', 'cycle_id', 'nominee_name', 'nominator_name', 'nominator_email', 'reason'];
        foreach (['nominator_phone', 'nominee_email', 'nominee_phone', 'device_fp', 'not_duplicate',
                  'reference_url', 'reference_url_2', 'reference_url_3'] as $c) {
            if (SchemaHas::column('gates_nominations', $c)) $cols[] = $c;
        }
        try {
            $cycles = DB::table('gates_nominations')->whereIn('id', $ids)->distinct()->pluck('cycle_id')->map('intval')->all();
            if ($cycles === []) return [];
            $all = [];
            foreach (DB::table('gates_nominations')->whereIn('cycle_id', $cycles)->orderBy('id')->get($cols) as $r) $all[(int) $r->id] = $r;
        } catch (\Throwable $e) {
            error_log('[nomination-signals] ' . $e->getMessage());
            return [];
        }

        // ── each nomination, reduced to what is compared ───────────────────
        $facts = [];
        $groups = [];
        foreach ($all as $id => $r) {
            // A reviewer's "Not a duplicate" ruling makes this a different person from
            // everybody else under the name, so it is compared as a group of one.
            $group = (int) ($r->not_duplicate ?? 0) === 1 ? 'id:' . $id
                : (int) $r->cycle_id . '|' . NominationTriageService::nameKey((string) $r->nominee_name);
            $words = self::words((string) ($r->reason ?? ''), (string) $r->nominee_name);
            $facts[$id] = [
                'group'   => $group,
                'name'    => trim((string) $r->nominee_name),
                'inbox'   => EmailCanon::hash((string) $r->nominator_email),
                'phone'   => Phone::normalize((string) ($r->nominator_phone ?? ''), 'NG'),
                'n_inbox' => EmailCanon::hash((string) ($r->nominee_email ?? '')),
                'n_phone' => Phone::normalize((string) ($r->nominee_phone ?? ''), 'NG'),
                'device'  => trim((string) ($r->device_fp ?? '')),
                'email'   => trim((string) $r->nominator_email),
                'self'    => NominationTriageService::nameKey((string) $r->nominator_name) !== ''
                             && NominationTriageService::nameKey((string) $r->nominator_name) === NominationTriageService::nameKey((string) $r->nominee_name),
                'shingles'=> count($words) >= self::COPY_MIN_WORDS ? self::shingles($words) : [],
                'text'    => count($words) >= self::COPY_MIN_WORDS ? hash('sha256', implode(' ', $words)) : null,
                'links'   => [],
                'files'   => [],
            ];
            foreach (['reference_url', 'reference_url_2', 'reference_url_3'] as $c) {
                $u = self::link((string) ($r->{$c} ?? ''));
                if ($u !== '') $facts[$id]['links'][$u] = true;
            }
            $groups[$group][] = $id;
        }
        self::evidence($facts);

        // Indexes across the edition: who else used this text, link or file.
        $byText = []; $byLink = []; $byFile = [];
        foreach ($facts as $id => $f) {
            if ($f['text'] !== null) $byText[$f['text']][] = $id;
            foreach ($f['links'] as $u => $_) $byLink[$u][] = $id;
            foreach ($f['files'] as $h => $_) $byFile[$h][] = $id;
        }

        $out = [];
        foreach ($ids as $id) {
            if (!isset($facts[$id])) continue;
            $f = $facts[$id];
            $mates = array_values(array_diff($groups[$f['group']] ?? [], [$id]));
            $sig = [];

            // ── the nominator and the nominee ───────────────────────────────
            if ($f['self']) {
                $sig[] = ['key' => 'self', 'tone' => 'gold', 'with' => [],
                    't' => 'The nominator has the nominee’s name',
                    'rule' => 'The nominator typed the same name for themselves as for the nominee. They may have nominated themselves, or share a name with somebody they admire. Check the edition’s rules on self-nomination.'];
            }
            $nominatorInboxes = array_filter(array_map(static fn (int $m): ?string => $facts[$m]['inbox'], array_merge([$id], $mates)));
            $nominatorPhones  = array_filter(array_map(static fn (int $m): ?string => $facts[$m]['phone'], array_merge([$id], $mates)));
            if (($f['n_inbox'] !== null && in_array($f['n_inbox'], $nominatorInboxes, true))
                || ($f['n_phone'] !== null && in_array($f['n_phone'], $nominatorPhones, true))) {
                $sig[] = ['key' => 'own_contact', 'tone' => 'gold', 'with' => [],
                    't' => 'The nominee’s contact is a nominator’s own',
                    'rule' => 'The email or phone given for the nominee belongs to somebody who nominated them. Usually a relative or colleague filled the form in with the address they had. The nominee’s form link is not sent to it on approval: add the nominee’s own address on the full record, then send the form again.'];
            }

            // ── one person, several nominations ─────────────────────────────
            if ($f['inbox'] !== null) {
                $same = array_values(array_filter($mates, static fn (int $m): bool => $facts[$m]['inbox'] === $f['inbox']));
                if ($same) {
                    $sig[] = ['key' => 'one_person', 'tone' => 'gold', 'with' => $same,
                        't' => (count($same) + 1) . ' nominations from this one nominator',
                        'rule' => 'These nominations for the same person came from one email inbox (spelled differently or not). They count once towards the “different places” rule, however many places were typed.'];
                }
            }
            if ($f['device'] !== '') {
                $shared = array_values(array_filter($mates, static fn (int $m): bool =>
                    $facts[$m]['device'] === $f['device'] && $facts[$m]['inbox'] !== $f['inbox']));
                if ($shared) {
                    $n = count($shared);
                    $sig[] = ['key' => 'one_device', 'tone' => 'gold', 'with' => $shared,
                        't' => 'Sent from the same device as ' . $n . ' other nominator' . ($n === 1 ? '' : 's'),
                        'rule' => 'Different names and addresses were typed on one device. A family or a classroom can do this honestly; a campaign does it to look like several people. The connection is not compared — mobile networks put strangers behind one address.'];
                }
            }

            // ── the same words ──────────────────────────────────────────────
            if ($f['shingles']) {
                $copied = array_values(array_filter($mates, static fn (int $m): bool =>
                    $facts[$m]['inbox'] !== $f['inbox'] && $facts[$m]['shingles'] && self::overlap($f['shingles'], $facts[$m]['shingles']) >= self::COPY_OVERLAP));
                if ($copied) {
                    $sig[] = ['key' => 'copied', 'tone' => 'gold', 'with' => $copied,
                        't' => 'Same words as ' . count($copied) . ' other nominator' . (count($copied) === 1 ? '' : 's') . ' for them',
                        'rule' => 'Most of this reason is word for word another nominator’s for the same person. Sometimes one letter is shared and everybody signs it; sometimes one person writes them all.'];
                }
            }
            if ($f['text'] !== null) {
                $elsewhere = self::others($byText[$f['text']] ?? [], $id, $f['group'], $facts);
                if ($elsewhere) {
                    $sig[] = ['key' => 'template', 'tone' => 'gold', 'with' => $elsewhere,
                        't' => 'Same words as a nomination for ' . $facts[$elsewhere[0]]['name'],
                        'rule' => 'Apart from the names, this reason is identical to one written for somebody else. A reason copied between people says nothing about either of them.'];
                }
            }

            // ── the same evidence for somebody else ─────────────────────────
            $fileWith = []; $linkWith = [];
            foreach ($f['files'] as $h => $_) $fileWith = array_merge($fileWith, self::others($byFile[$h] ?? [], $id, $f['group'], $facts));
            foreach ($f['links'] as $u => $_) $linkWith = array_merge($linkWith, self::others($byLink[$u] ?? [], $id, $f['group'], $facts));
            if ($fileWith = array_values(array_unique($fileWith))) {
                $sig[] = ['key' => 'reused_file', 'tone' => 'gold', 'with' => $fileWith,
                    't' => 'A file also sent for ' . $facts[$fileWith[0]]['name'],
                    'rule' => 'The same file, byte for byte, was uploaded with a nomination for a different person. Open both: a group photo can back two people honestly; one certificate cannot.'];
            }
            if ($linkWith = array_values(array_unique($linkWith))) {
                $sig[] = ['key' => 'reused_link', 'tone' => 'gold', 'with' => $linkWith,
                    't' => 'A link also sent for ' . $facts[$linkWith[0]]['name'],
                    'rule' => 'The same address was given as evidence for a different person. A school or newspaper page can mention two people; check it names this one.'];
            }

            // ── can we reach the nominator at all ───────────────────────────
            if ($f['email'] === '' || !filter_var($f['email'], FILTER_VALIDATE_EMAIL) || SendPolicy::reserved($f['email'])) {
                $sig[] = ['key' => 'unreachable', 'tone' => 'gold', 'with' => [],
                    't' => 'The nominator cannot be emailed',
                    'rule' => 'The address is not one mail can be delivered to, so “Ask for more” and the decision email will not reach them.'];
            }

            $out[$id] = $sig;
        }
        return $out;
    }

    /**
     * Is the email given for this nominee an inbox somebody who nominated them reads?
     *
     * Asked before the nominee's single-use form link is sent: the address was typed by a
     * nominator, and when it is their own the link — the nominee's own answers, in their
     * name, to the panel — lands with the nominator instead. ClaimIndependence asks the
     * same question before a claim; this is its sibling at approval. Every nomination for
     * the person is compared, not only this one, or a second nominator's address slips by.
     */
    public static function contactIsNominators(object $nom): bool
    {
        $mine = EmailCanon::hash((string) ($nom->nominee_email ?? ''));
        if ($mine === null) return false;
        try {
            foreach (DB::table('gates_nominations')->where('cycle_id', (int) ($nom->cycle_id ?? 0))
                         ->whereRaw("LOWER(REPLACE(nominee_name, ' ', '')) = ?", [NominationTriageService::nameKey((string) $nom->nominee_name)])
                         ->pluck('nominator_email') as $e) {
                if (EmailCanon::hash((string) $e) === $mine) return true;
            }
        } catch (\Throwable) {
            // Unreadable is not independent: hold the link rather than guess.
            return true;
        }
        return false;
    }

    /** The words that carry a reason: lower case, punctuation gone, the nominee's own name taken out. */
    private static function words(string $text, string $nominee): array
    {
        $clean = static fn (string $s): string => trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($s)));
        $drop = array_flip(array_filter(explode(' ', $clean($nominee))));
        return array_values(array_filter(explode(' ', $clean($text)), static fn (string $w): bool => $w !== '' && !isset($drop[$w])));
    }

    /** @return array<string,true> three-word phrases */
    private static function shingles(array $words): array
    {
        $out = [];
        for ($i = 0, $n = count($words) - 2; $i < $n; $i++) $out[$words[$i] . ' ' . $words[$i + 1] . ' ' . $words[$i + 2]] = true;
        return $out;
    }

    /** Share of the SMALLER text's phrases that the other has — a short reason pasted into a longer one is still copied. */
    private static function overlap(array $a, array $b): float
    {
        $small = min(count($a), count($b));
        return $small > 0 ? count(array_intersect_key($a, $b)) / $small : 0.0;
    }

    /** Nominations in $list that are for somebody ELSE (another group). @return list<int> */
    private static function others(array $list, int $id, string $group, array $facts): array
    {
        return array_values(array_filter($list, static fn (int $m): bool => $m !== $id && $facts[$m]['group'] !== $group));
    }

    /** A link as compared: scheme and host in lower case, no fragment, no trailing slash. */
    private static function link(string $u): string
    {
        $u = trim($u);
        if ($u === '' || !NominationRules::isHttpUrl($u)) return '';
        $p = parse_url($u);
        if (!is_array($p) || empty($p['host'])) return '';
        return strtolower($p['host']) . rtrim((string) ($p['path'] ?? ''), '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    }

    /** Fold each nomination's evidence rows into its facts: links by address, files by fingerprint. */
    private static function evidence(array &$facts): void
    {
        if (!SchemaHas::table('gates_nomination_evidence')) return;
        $hasSha = SchemaHas::column('gates_nomination_evidence', 'sha256');
        try {
            foreach (array_chunk(array_keys($facts), 500) as $chunk) {
                foreach (DB::table('gates_nomination_evidence')->whereIn('nomination_id', $chunk)
                             ->get(array_merge(['nomination_id', 'url'], $hasSha ? ['sha256'] : [])) as $e) {
                    $id = (int) $e->nomination_id;
                    $u = self::link((string) ($e->url ?? ''));
                    if ($u !== '') $facts[$id]['links'][$u] = true;
                    $h = (string) ($e->sha256 ?? '');
                    if ($h !== '') $facts[$id]['files'][$h] = true;
                }
            }
        } catch (\Throwable $e) {
            error_log('[nomination-signals] evidence: ' . $e->getMessage());
        }
    }
}
