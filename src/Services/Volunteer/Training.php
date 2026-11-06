<?php
declare(strict_types=1);
namespace AfricaGates\Services\Volunteer;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Training: short modules with a check at the end, each unlocking roles (plan §7.3 "Train").
 *
 * A module is a page of guidance and a few questions. Passing records a pass that may lapse
 * (`valid_days`), and a lapsed pass unlocks nothing — so the door role that needs Safety stops
 * the day Safety expires, with no job to run. `next()` says WHY each module matters to this
 * person (what it unlocks, what it keeps), because "Training: 3 modules" is a list nobody opens.
 */
final class Training
{
    /** How long before a pass lapses that it shows as due. */
    public const DUE_DAYS = 14;

    /** @var array<int,array<string,bool>> */
    private static array $held = [];

    public static function reset(): void
    {
        self::$held = [];
    }

    /** @return array<string,string> slug => title, for every live module */
    public static function titles(): array
    {
        try {
            return DB::table('gates_vol_modules')->where('status', 'live')->pluck('title', 'slug')->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public static function module(string $slug): ?object
    {
        return DB::table('gates_vol_modules')->where('slug', $slug)->where('status', 'live')->first() ?: null;
    }

    /** Does this person hold a pass for this module that has not lapsed? */
    public static function holds(int $volId, string $slug): bool
    {
        if (isset(self::$held[$volId][$slug])) return self::$held[$volId][$slug];
        $now = date('Y-m-d H:i:s');
        $ok = DB::table('gates_vol_passes as p')->join('gates_vol_modules as m', 'm.id', '=', 'p.module_id')
            ->where('p.volunteer_id', $volId)->where('m.slug', $slug)
            ->where(static fn ($w) => $w->whereNull('p.expires_at')->orWhere('p.expires_at', '>', $now))->exists();
        return self::$held[$volId][$slug] = $ok;
    }

    /** @return list<object> passes in date, newest first, with their module */
    public static function passed(object $v): array
    {
        return DB::table('gates_vol_passes as p')->join('gates_vol_modules as m', 'm.id', '=', 'p.module_id')
            ->where('p.volunteer_id', $v->id)->orderByDesc('p.passed_at')
            ->get(['p.*', 'm.title', 'm.slug', 'm.unlocks', 'm.questions'])->all();
    }

    /**
     * Modules to do next, each with its reason.
     *
     * @return list<object> each with ->reason, ->tone (warn|ok|info), ->label
     */
    public static function next(object $v): array
    {
        $out = [];
        $openTasks = DB::table('gates_vol_tasks')->where('status', 'open')
            ->whereNotNull('module_key')->groupBy('module_key')->selectRaw('module_key, COUNT(*) as n')->pluck('n', 'module_key')->all();
        $passes = [];
        foreach (self::passed($v) as $p) $passes[$p->slug] ??= $p;
        foreach (DB::table('gates_vol_modules')->where('status', 'live')->orderBy('id')->get() as $m) {
            $p = $passes[$m->slug] ?? null;
            if ($p && (!$p->expires_at || strtotime((string) $p->expires_at) > time() + self::DUE_DAYS * 86400)) continue;
            if ($p && $p->expires_at) {
                $days = max(0, (int) ceil((strtotime((string) $p->expires_at) - time()) / 86400));
                $m->label = 'Due';
                $m->tone = 'warn';
                $m->reason = $days > 0 ? 'Expires in ' . $days . ' ' . ($days === 1 ? 'day' : 'days') : 'Expired';
                if ($m->unlocks) $m->reason .= ' · keeps ' . self::capWords((string) $m->unlocks);
            } elseif (isset($openTasks[$m->slug])) {
                $n = (int) $openTasks[$m->slug];
                $m->label = 'Unlocks tasks';
                $m->tone = 'ok';
                $m->reason = 'Unlocks ' . $n . ' open ' . ($n === 1 ? 'task' : 'tasks');
            } elseif ($m->slug === Rules::LEAD_MODULE) {
                $m->label = 'Level 3';
                $m->tone = 'info';
                $m->reason = 'Needed for Level 3';
            } else {
                $m->label = '';
                $m->tone = 'mute';
                $m->reason = $m->unlocks ? 'Unlocks ' . self::capWords((string) $m->unlocks) : 'Good to know';
            }
            $out[] = $m;
        }
        usort($out, static fn ($a, $b) => (['warn' => 0, 'ok' => 1, 'info' => 2, 'mute' => 3][$a->tone] ?? 3) <=> (['warn' => 0, 'ok' => 1, 'info' => 2, 'mute' => 3][$b->tone] ?? 3));
        return $out;
    }

    /**
     * Take a module's check. Passing needs `pass_mark` right answers.
     *
     * @param array<int,string> $answers question index => chosen option index
     * @return array{ok:bool, passed?:bool, score?:int, of?:int, error?:string}
     */
    public static function take(object $v, string $slug, array $answers): array
    {
        $m = self::module($slug);
        if (!$m) return ['ok' => false, 'error' => 'That training is not available.'];
        $qs = json_decode((string) $m->questions, true) ?: [];
        $score = 0;
        foreach ($qs as $i => $q) if (isset($answers[$i]) && (string) $answers[$i] === (string) ($q['answer'] ?? '')) $score++;
        $passed = $score >= min((int) $m->pass_mark, count($qs));
        if ($passed) {
            DB::table('gates_vol_passes')->insert([
                'module_id' => $m->id, 'volunteer_id' => $v->id, 'score' => $score, 'passed_at' => date('Y-m-d H:i:s'),
                'expires_at' => $m->valid_days ? date('Y-m-d H:i:s', strtotime('+' . (int) $m->valid_days . ' days')) : null,
            ]);
            unset(self::$held[(int) $v->id]);
        }
        return ['ok' => true, 'passed' => $passed, 'score' => $score, 'of' => count($qs)];
    }

    /** @return array{ok:bool, errors?:list<string>} */
    public static function save(array $f): array
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-z0-9-]+/i', '-', (string) ($f['slug'] ?? '')), '-'));
        $title = trim((string) ($f['title'] ?? ''));
        $e = [];
        if ($slug === '' || $title === '') $e[] = 'A module needs a title and a short code.';
        $qs = [];
        foreach ((array) ($f['q'] ?? []) as $i => $q) {
            $q = trim((string) $q);
            $opts = array_values(array_filter(array_map('trim', explode("\n", (string) (($f['opts'] ?? [])[$i] ?? '')))));
            if ($q === '' || count($opts) < 2) continue;
            $ans = (int) (($f['answer'] ?? [])[$i] ?? 0);
            $qs[] = ['q' => $q, 'options' => $opts, 'answer' => (string) max(0, min(count($opts) - 1, $ans))];
        }
        if ($qs === []) $e[] = 'Add at least one question with two or more answers.';
        if ($e !== []) return ['ok' => false, 'errors' => $e];
        $row = ['title' => mb_substr($title, 0, 190), 'minutes' => max(1, min(120, (int) ($f['minutes'] ?? 10))),
                'body' => mb_substr(trim((string) ($f['body'] ?? '')), 0, 20000), 'questions' => json_encode($qs, JSON_UNESCAPED_UNICODE),
                'pass_mark' => max(1, min(count($qs), (int) ($f['pass_mark'] ?? count($qs)))),
                'valid_days' => (int) ($f['valid_days'] ?? 0) > 0 ? (int) $f['valid_days'] : null,
                'unlocks' => mb_substr(trim((string) ($f['unlocks'] ?? '')), 0, 255) ?: null, 'status' => 'live'];
        $have = DB::table('gates_vol_modules')->where('slug', $slug)->first();
        $have ? DB::table('gates_vol_modules')->where('id', $have->id)->update($row)
              : DB::table('gates_vol_modules')->insert($row + ['slug' => $slug, 'created_at' => date('Y-m-d H:i:s')]);
        return ['ok' => true];
    }

    private static function capWords(string $csv): string
    {
        $w = [];
        foreach (array_filter(array_map('trim', explode(',', $csv))) as $c) {
            $w[] = \AfricaGates\Services\Grants::CAPABILITIES[$c] ?? $c;
        }
        return implode(' and ', $w);
    }
}
