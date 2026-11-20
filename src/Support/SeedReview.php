<?php
declare(strict_types=1);

namespace AfricaGates\Support;

use AfricaGates\Services\ChallengeCopy;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * A seed an operator checks — and may correct — before it runs.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE SWEEP RAN IT BEFORE ANYBODY COULD READ IT
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * The Celebrate Nigeria seed writes a PUBLISHED challenge, and `SeedRunner::sweep()` runs
 * every waiting seed each hour — so it went live the first hour an Alimosho edition was
 * open, with the handoff's numbers, and from that moment its target, cap, prize and start
 * are locked against edits because people are competing under them. There was no point
 * at which a person could say "the prize is ₦5,000 this year".
 *
 * A seed opts in by shipping `database/seeds/data/<name>.php` with `review => true` and a
 * table of fields. Then:
 *
 *   · the sweep leaves it alone until it is APPROVED ({@see ready()} — the one answer, read
 *     by the sweep and by the Run button alike);
 *   · /admin/challenges/seeds/<name> draws the fields with the handoff's values, and saving
 *     stores only what differs, so a later correction to the handoff's default still
 *     reaches a value nobody touched;
 *   · approving records who and when, and is VOID the moment the values change after it —
 *     an approval of figures nobody has seen is not an approval;
 *   · the seed reads {@see values()} and writes those.
 *
 * Validation lives here and not in the seed, because the seed's refusal is a sentence on
 * the screen AFTER somebody pressed add, and a number that cannot be right should be
 * refused while the form is still in front of them.
 */
final class SeedReview
{
    private const VALUES_PREFIX   = 'seed_values_';
    private const APPROVED_PREFIX = 'seed_approved_';

    /** @return array{review:bool, zone:string, fields:array<string,array<string,mixed>>}|null */
    public static function spec(string $seed): ?array
    {
        if (!preg_match('~^[a-z0-9_]+$~', $seed)) return null;
        $path = SeedRunner::DIR . '/data/' . $seed . '.php';
        if (!is_file($path)) return null;
        $spec = require $path;
        return is_array($spec) && isset($spec['fields']) && is_array($spec['fields']) ? $spec + ['review' => false, 'zone' => 'UTC'] : null;
    }

    /** Does this seed wait for a person before it runs? */
    public static function required(string $seed): bool
    {
        return (bool) (self::spec($seed)['review'] ?? false);
    }

    /**
     * May it run now? True for a seed that needs no review, or one approved with the
     * values it holds right now.
     */
    public static function ready(string $seed): bool
    {
        if (!self::required($seed)) return true;
        $a = self::approval($seed);
        return $a !== null && $a['hash'] === self::hash(self::values($seed));
    }

    /** @return array{at:string, by:?int, hash:string}|null */
    public static function approval(string $seed): ?array
    {
        $a = json_decode((string) self::setting(self::APPROVED_PREFIX . $seed), true);
        return is_array($a) && isset($a['hash'], $a['at'])
            ? ['at' => (string) $a['at'], 'by' => isset($a['by']) ? (int) $a['by'] : null, 'hash' => (string) $a['hash']]
            : null;
    }

    /**
     * The values the seed will write: the handoff's, with the operator's edits over them.
     *
     * @return array<string,mixed>
     */
    public static function values(string $seed): array
    {
        $spec = self::spec($seed);
        if ($spec === null) return [];
        $stored = json_decode((string) self::setting(self::VALUES_PREFIX . $seed), true);
        $stored = is_array($stored) ? $stored : [];
        $out = [];
        foreach ($spec['fields'] as $k => $f) {
            $out[$k] = array_key_exists($k, $stored) ? $stored[$k] : $f['default'];
        }
        return $out;
    }

    /** @return list<string> the fields an operator has changed from the handoff's value */
    public static function edited(string $seed): array
    {
        $stored = json_decode((string) self::setting(self::VALUES_PREFIX . $seed), true);
        return is_array($stored) ? array_keys($stored) : [];
    }

    /**
     * Save the form. Nothing is stored unless every field is valid — a half-saved form is
     * a set of figures nobody chose together.
     *
     * @param array<string,mixed> $in
     * @return array{ok:bool, errors:array<string,string>}
     */
    public static function save(string $seed, array $in, ?int $adminId = null): array
    {
        $spec = self::spec($seed);
        if ($spec === null) return ['ok' => false, 'errors' => ['' => 'That seed has nothing to edit.']];

        $clean = []; $errors = [];
        foreach ($spec['fields'] as $k => $f) {
            [$v, $err] = self::field($f, $in[$k] ?? null);
            if ($err !== null) { $errors[$k] = $err; continue; }
            $clean[$k] = $v;
        }
        if (!isset($errors['starts_at'], $errors['ends_at'])
            && isset($clean['starts_at'], $clean['ends_at']) && $clean['ends_at'] <= $clean['starts_at']) {
            $errors['ends_at'] = 'It has to close after it opens.';
        }
        if ($errors !== []) return ['ok' => false, 'errors' => $errors];

        // Only what differs from the handoff, so a corrected default still reaches every
        // field nobody touched.
        $diff = [];
        foreach ($clean as $k => $v) {
            if ($v !== $spec['fields'][$k]['default']) $diff[$k] = $v;
        }
        $before = self::values($seed);
        self::put(self::VALUES_PREFIX . $seed, (string) json_encode($diff, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $adminId);

        // A change after approval voids it: what was approved is not what would run.
        if (self::hash($before) !== self::hash(self::values($seed))) {
            DB::table('gates_settings')->where('key_name', self::APPROVED_PREFIX . $seed)->delete();
        }
        return ['ok' => true, 'errors' => []];
    }

    /** Approve the values it holds now. */
    public static function approve(string $seed, ?int $adminId = null): void
    {
        self::put(self::APPROVED_PREFIX . $seed, (string) json_encode([
            'at' => Carbon::now()->toDateTimeString(), 'by' => $adminId,
            'hash' => self::hash(self::values($seed)),
        ]), $adminId);
    }

    /** Back to the handoff's values; any approval goes with them. */
    public static function reset(string $seed): void
    {
        DB::table('gates_settings')->whereIn('key_name',
            [self::VALUES_PREFIX . $seed, self::APPROVED_PREFIX . $seed])->delete();
    }

    /**
     * A text with its numbers filled in — `{target}`, `{cap}`, `{prize}` — exactly as the
     * challenge page will fill them, because the prize phrase comes from `ChallengeCopy`.
     *
     * @param array<string,mixed> $v
     */
    public static function fill(string $text, array $v): string
    {
        $copy = ChallengeCopy::for([
            'action' => 'nominate', 'mode' => 'first', 'prize_type' => 'cash_each',
            'prize_currency' => 'NGN', 'prize_amount' => (int) ($v['prize_amount'] ?? 0),
            'target' => (int) ($v['target'] ?? 1), 'cap' => (int) ($v['cap'] ?? 0),
        ], ['state' => 'open', 'claimed' => 0]);
        return strtr($text, [
            '{target}' => (string) (int) ($v['target'] ?? 1),
            '{cap}'    => (string) (int) ($v['cap'] ?? 0),
            '{prize}'  => trim($copy['prize_big'] . ' ' . $copy['prize_unit']),
        ]);
    }

    /** A Lagos (or the spec's zone) wall-clock time as the UTC string the column holds. */
    public static function utc(string $seed, string $local): string
    {
        $zone = (string) (self::spec($seed)['zone'] ?? 'UTC');
        return (new \DateTimeImmutable($local, new \DateTimeZone($zone)))
            ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    // ══════════════════════════════════════════════════════════════════════════

    /**
     * @param array<string,mixed> $f
     * @return array{0:mixed, 1:?string} the clean value, or an error sentence
     */
    private static function field(array $f, mixed $raw): array
    {
        $max = (int) ($f['max'] ?? 0);
        switch ($f['type']) {
            case 'int':
                $s = trim(str_replace([',', ' ', '₦'], '', (string) $raw));
                if ($s === '' || !ctype_digit($s)) return [null, 'A whole number, please.'];
                $n = (int) $s;
                if ($n < (int) ($f['min'] ?? 0) || $n > (int) ($f['max'] ?? PHP_INT_MAX)) {
                    return [null, 'Between ' . number_format((int) ($f['min'] ?? 0)) . ' and ' . number_format((int) $f['max']) . '.'];
                }
                return [$n, null];
            case 'when':
                $s = trim((string) $raw);
                $dt = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $s)
                    ?: \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $s)
                    ?: \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $s);
                if ($dt === false) return [null, 'A date and a time, please.'];
                return [$dt->format('Y-m-d H:i:s'), null];
            case 'lines':
                $list = is_array($raw) ? $raw : (preg_split('/\r?\n/', (string) $raw) ?: []);
                $list = array_values(array_filter(array_map(static fn ($l) => trim((string) $l), $list), 'strlen'));
                if ($max > 0 && mb_strlen(implode("\n", $list)) > $max) return [null, "At most {$max} characters in all."];
                return [$list, null];
            default:
                $s = trim((string) $raw);
                if ($s === '') return [null, 'This cannot be empty — it is printed on the page.'];
                if ($max > 0 && mb_strlen($s) > $max) return [null, "At most {$max} characters."];
                return [$s, null];
        }
    }

    /** @param array<string,mixed> $v */
    private static function hash(array $v): string
    {
        return hash('sha256', (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function setting(string $key): ?string
    {
        try {
            $v = DB::table('gates_settings')->where('key_name', $key)->value('value');
            return $v === null ? null : (string) $v;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function put(string $key, string $value, ?int $adminId): void
    {
        DB::table('gates_settings')->updateOrInsert(['key_name' => $key], [
            'value' => $value,
            'updated_at' => Carbon::now()->toDateTimeString(),
            // `updated_by` has a foreign key to gates_admins, and there is no admin 0: a
            // sentinel written here is a row MySQL refuses. NULL when nobody is signed in.
            'updated_by' => ($adminId ?? 0) > 0 ? $adminId : null,
        ]);
    }
}
