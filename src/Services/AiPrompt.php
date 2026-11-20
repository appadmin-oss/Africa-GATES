<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The instruction a capability gives its model, editable without a deploy.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * FIRST, THE HONEST VERSION OF "TRAIN THE MODEL"
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * This is not training and it is not fine-tuning. Fine-tuning means a training run against
 * a hosted base model, with a curated dataset, an evaluation set and a bill. This platform
 * calls four providers' inference endpoints from shared cPanel hosting; there is no GPU, no
 * pipeline, no dataset and no way to get one. Nothing here changes any model's weights.
 *
 * What it does is the thing people usually mean when they say it: change the INSTRUCTION,
 * per capability, without editing PHP. On a host with no SSH that distinction is the whole
 * feature — a prompt that can only be changed by a deploy is a prompt that never changes,
 * so the wording deciding how a nomination is triaged, or how a decision note reads to the
 * person receiving it, stays frozen at whatever was first guessed.
 *
 * The admin screen says all of this in those words. Letting somebody believe they are
 * training a model would be a lie that costs them a real decision later.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * WHAT AN EDIT CAN AND CANNOT REACH
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * CAN: the system prompt. Tone, emphasis, what to look for, what to ignore, the house
 * vocabulary. This is the useful surface and it is genuinely most of the behaviour.
 *
 * CANNOT, by construction rather than by policy:
 *
 *  · The injection fence. {@see AiGateway::assembleUser()} wraps untrusted text in markers
 *    and states the instruction hierarchy in the USER message, which no system prompt
 *    written here participates in building. An override cannot remove it, weaken it, or
 *    move the boundary.
 *  · Advisory enforcement. {@see AiCapability} declares it and the gateway enforces it. A
 *    prompt saying "you may reject this nomination" produces text that still cannot reject
 *    anything.
 *  · The budget, the pinned model, the timeout, the schema. All registry, all outside this.
 *
 * So the worst an edit can do is make a capability answer badly — which is recoverable, is
 * visible in the call log, and is exactly what the version history below is for.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * EVERY SAVE IS A NEW VERSION
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A prompt is not a setting. It is a decision whose consequences appear days later — the
 * triage instruction is widened on Tuesday, spam scores drift on Thursday, and the only
 * question that matters is "what did it say before". An UPDATE cannot answer that.
 *
 * So versions accumulate, exactly one is active per capability, and reverting means
 * activating an earlier one rather than retyping it from memory.
 */
final class AiPrompt
{
    /**
     * A body longer than this is refused.
     *
     * Not arbitrary: the system prompt is sent on EVERY call to that capability, and a
     * 20,000-character instruction on `moderation.classify` — which sits on the nomination
     * submit with a four-second timeout — is a slow form and a large bill that nobody
     * connects back to a text box they typed into once.
     */
    public const MAX_BODY = 6000;

    /** Below this it is almost certainly a mistake — a paste that went wrong, or a stray key. */
    public const MIN_BODY = 40;

    // ═══════════════════════════════════════════════════════════════════════
    // READING
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * The active override for a capability, or null when there is none.
     *
     * Null is the NORMAL state and means "use what the code says". The shipped wording lives
     * beside the parsing that depends on it, and seeding it into a row would fork the two:
     * the code changes in a release, the row does not, and the platform quietly keeps using
     * a prompt nobody can find in the repository.
     */
    public static function active(string $capability): ?array
    {
        if ($capability === '') return null;

        try {
            $row = DB::table('gates_ai_prompts')
                ->where('capability', $capability)
                ->where('is_active', 1)
                ->orderByDesc('version')
                ->first();
        } catch (\Throwable) {
            // No table yet — a deployment between an upload and a migrate. Falling back to
            // the code's own prompt is right: the feature keeps working.
            return null;
        }

        return $row ? self::hydrate($row) : null;
    }

    /**
     * What will actually be sent: the override if there is one, otherwise the caller's own.
     *
     * The single seam. {@see AiGateway::run()} calls this instead of using `$input['system']`
     * directly, so every capability inherits the editor without any of them knowing about it
     * — and a capability that does not go through the gateway (evidence analysis, which
     * talks to the Files API) is honestly outside it rather than half-wired.
     *
     * @return array{body:string, source:string, version:int}
     */
    public static function effective(string $capability, string $default): array
    {
        // Remember what the code sent, whether or not it is what gets used. This is how the
        // editor knows the shipped wording without a copy of it in the database — see
        // rememberDefault(). Done on the override path too, so an edited capability whose
        // shipped wording changes in a release still shows the CURRENT baseline to diff
        // against rather than the one from whenever it was last un-overridden.
        self::rememberDefault($capability, $default);

        $row = self::active($capability);

        if ($row === null || trim((string) $row['body']) === '') {
            return ['body' => $default, 'source' => 'code', 'version' => 0];
        }

        return ['body' => (string) $row['body'], 'source' => 'edited',
                'version' => (int) $row['version']];
    }

    /**
     * The wording that ships with the code, as last actually sent.
     *
     * ── WHY IT IS OBSERVED AND NOT DECLARED ─────────────────────────────────
     *
     * The editor has to show what it is replacing, and the diff has to have a baseline. The
     * obvious way to get one is a `defaultPrompt` field on {@see AiCapability} — and it is
     * wrong, because the real prompts are built at twenty-one call sites, several of them
     * interpolating a programme name or a criteria list. Declaring a second copy in the
     * registry forks the two: the call site changes in a release, the registry does not, and
     * the editor confidently shows an operator a prompt the platform has not sent for months.
     *
     * So it is LEARNED. Version 0 is reserved for "what the code says", is never active, and
     * is rewritten whenever the observed wording changes. That makes it self-maintaining and
     * — more importantly — impossible for it to be wrong: it is literally the last string the
     * code passed in.
     *
     * The cost is that a capability nobody has run yet has no baseline, and the screen says
     * exactly that rather than showing a blank box that looks like an empty prompt.
     */
    public static function rememberDefault(string $capability, string $body): void
    {
        $body = trim(str_replace("\r\n", "\n", $body));
        if ($capability === '' || $body === '') return;

        try {
            $seen = DB::table('gates_ai_prompts')
                ->where('capability', $capability)->where('version', 0)
                ->first(['id', 'body']);

            // Unchanged is the overwhelmingly common case — this runs on every AI call, so
            // it must not be a write on every AI call.
            if ($seen && (string) $seen->body === $body) return;

            if ($seen) {
                DB::table('gates_ai_prompts')->where('id', $seen->id)->update([
                    'body'       => $body,
                    'created_at' => Carbon::now()->toDateTimeString(),
                ]);
                return;
            }

            DB::table('gates_ai_prompts')->insert([
                'capability' => $capability,
                'version'    => 0,
                'body'       => $body,
                'note'       => 'The wording that ships with the platform.',
                'is_active'  => 0,
                'created_at' => Carbon::now()->toDateTimeString(),
            ]);
        } catch (\Throwable) {
            // A missing table, or a race between two requests inserting version 0 at once.
            // Neither is worth failing an AI call over: the editor loses a baseline, the
            // feature keeps working, and the next call writes it.
        }
    }

    /**
     * The shipped wording for a capability, or null when it has never been observed.
     *
     * Null is meaningful and the screen says so — "this feature has not run since the
     * editor was installed, so there is nothing to compare against yet" is a true and
     * actionable thing to read. A blank box would imply an empty prompt.
     */
    public static function shipped(string $capability): ?string
    {
        try {
            $b = DB::table('gates_ai_prompts')
                ->where('capability', $capability)->where('version', 0)->value('body');
        } catch (\Throwable) {
            return null;
        }

        return is_string($b) && trim($b) !== '' ? $b : null;
    }

    /**
     * Every version for a capability, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public static function history(string $capability): array
    {
        try {
            $rows = DB::table('gates_ai_prompts')
                ->where('capability', $capability)
                // Version 0 is the observed shipped wording, not an edit somebody made.
                // Listing it as "version 0" beside real versions would invite reverting TO
                // it, which is what the separate revert button does properly.
                ->where('version', '>', 0)
                ->orderByDesc('version')
                ->get();
        } catch (\Throwable) {
            return [];
        }

        return array_map([self::class, 'hydrate'], $rows->all());
    }

    /** One version by id, or null. */
    public static function find(int $id): ?array
    {
        if ($id < 1) return null;
        try {
            $row = DB::table('gates_ai_prompts')->where('id', $id)->first();
        } catch (\Throwable) {
            return null;
        }
        return $row ? self::hydrate($row) : null;
    }

    /**
     * Which capabilities are editable, with their current state.
     *
     * Driven off {@see AiCapability::all()} rather than off the rows, so a capability that
     * has never been edited still appears — the screen's job is to show what CAN be changed,
     * and a list of only the things somebody already changed is useless for finding the one
     * behaving badly.
     *
     * @return list<array<string,mixed>>
     */
    public static function overview(): array
    {
        $edited = [];
        try {
            foreach (DB::table('gates_ai_prompts')->where('is_active', 1)->get() as $r) {
                $edited[(string) $r->capability] = $r;
            }
        } catch (\Throwable) {
            $edited = [];
        }

        $counts = [];
        try {
            foreach (DB::table('gates_ai_prompts')
                       ->where('version', '>', 0)
                       ->selectRaw('capability, COUNT(*) as n')
                       ->groupBy('capability')->get() as $r) {
                $counts[(string) $r->capability] = (int) $r->n;
            }
        } catch (\Throwable) {
        }

        $out = [];
        foreach (AiCapability::all() as $cap) {
            $row = $edited[$cap->name] ?? null;
            $out[] = [
                'capability' => $cap->name,
                'purpose'    => $cap->purpose,
                'tier'       => $cap->tier,
                'model'      => $cap->model,
                'advisory'   => $cap->advisory,
                'public'     => $cap->publicContent,
                'edited'     => $row !== null,
                'version'    => $row ? (int) $row->version : 0,
                'versions'   => $counts[$cap->name] ?? 0,
                'note'       => $row ? (string) ($row->note ?? '') : '',
                'changed_at' => $row ? (string) ($row->created_at ?? '') : '',
            ];
        }

        return $out;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // WRITING
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Save a new version and make it the active one.
     *
     * @return array{ok:bool, id:int, version:int, message:string}
     */
    public static function save(string $capability, string $body, string $note, int $adminId = 0): array
    {
        $fail = ['ok' => false, 'id' => 0, 'version' => 0];

        if (AiCapability::find($capability) === null) {
            // An undeclared capability would be a row nothing ever reads — a prompt somebody
            // wrote, believed in, and that has no effect on anything.
            return $fail + ['message' => 'There is no AI capability by that name.'];
        }

        $body = trim(str_replace("\r\n", "\n", $body));
        $note = trim($note);

        if (mb_strlen($body) < self::MIN_BODY) {
            return $fail + ['message' => 'That is too short to be an instruction. Use "Revert to '
                                       . 'the shipped wording" if you meant to remove your edit.'];
        }
        if (mb_strlen($body) > self::MAX_BODY) {
            return $fail + ['message' => 'That is ' . number_format(mb_strlen($body) - self::MAX_BODY)
                                       . ' characters over the limit of ' . number_format(self::MAX_BODY)
                                       . '. This text is sent on every single call to this feature, '
                                       . 'so its length is a cost on every request.'];
        }
        if ($note === '') {
            // Required, and worth defending: an edit with no note is a change nobody can
            // explain in three months, on the one screen where "why" is the whole record.
            return $fail + ['message' => 'Say why you are changing it. In three months this note '
                                       . 'is the only record of what you were trying to fix.'];
        }

        try {
            $next = ((int) DB::table('gates_ai_prompts')->where('capability', $capability)
                        ->max('version')) + 1;

            // Deactivate first, so a crash between the two statements leaves NOTHING active
            // rather than two. Nothing active falls back to the code's own prompt, which is
            // the safe direction to fail in.
            DB::table('gates_ai_prompts')->where('capability', $capability)
                ->update(['is_active' => 0]);

            $id = (int) DB::table('gates_ai_prompts')->insertGetId([
                'capability' => $capability,
                'version'    => $next,
                'body'       => $body,
                'note'       => mb_substr($note, 0, 400),
                'is_active'  => 1,
                'created_by' => $adminId ?: null,
                'created_at' => Carbon::now()->toDateTimeString(),
            ]);

            return ['ok' => true, 'id' => $id, 'version' => $next,
                    'message' => 'Saved as version ' . $next . ' and now in use. The previous '
                               . 'versions are kept, so you can put one back.'];
        } catch (\Throwable $e) {
            error_log('[ai-prompt] could not save ' . $capability . ': ' . $e->getMessage());
            return $fail + ['message' => 'That could not be saved.'];
        }
    }

    /**
     * Put an earlier version back into use.
     *
     * Activating the existing row rather than copying its text into a new one: a revert is
     * "go back to what version 3 said", and re-saving it as version 7 makes the history read
     * as though somebody retyped it, losing the fact that it is the same wording.
     */
    public static function activate(int $id, int $adminId = 0): array
    {
        $row = self::find($id);
        if ($row === null) return ['ok' => false, 'message' => 'That version does not exist.'];

        try {
            DB::table('gates_ai_prompts')->where('capability', $row['capability'])
                ->update(['is_active' => 0]);
            DB::table('gates_ai_prompts')->where('id', $id)->update(['is_active' => 1]);
        } catch (\Throwable $e) {
            error_log('[ai-prompt] could not activate ' . $id . ': ' . $e->getMessage());
            return ['ok' => false, 'message' => 'That could not be put back.'];
        }

        return ['ok' => true, 'message' => 'Version ' . $row['version'] . ' is in use again.'];
    }

    /**
     * Stop overriding and go back to the wording that ships with the code.
     *
     * Deactivation and never deletion. The versions stay, because "we tried that in March
     * and it was worse" is exactly the thing worth being able to look up — and because a
     * revert made by deleting is a revert somebody cannot undo.
     */
    public static function revert(string $capability, int $adminId = 0): array
    {
        try {
            $n = DB::table('gates_ai_prompts')
                ->where('capability', $capability)->where('is_active', 1)
                ->update(['is_active' => 0]);
        } catch (\Throwable $e) {
            error_log('[ai-prompt] could not revert ' . $capability . ': ' . $e->getMessage());
            return ['ok' => false, 'message' => 'That could not be reverted.'];
        }

        return ['ok' => true, 'message' => $n > 0
            ? 'Back to the wording that ships with the platform. Your versions are kept.'
            : 'This feature was already using the shipped wording.'];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // COMPARING
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * A line-by-line comparison of two bodies, for the screen.
     *
     * Hand-rolled and deliberately simple — a longest-common-subsequence diff over LINES,
     * which is all a prompt needs. Pulling in a diff library for one admin screen on a host
     * with no shell is a dependency somebody has to keep updated for ever.
     *
     * @return list<array{op:string, text:string}> op is 'same' | 'add' | 'del'
     */
    public static function diff(string $before, string $after): array
    {
        $a = explode("\n", trim(str_replace("\r\n", "\n", $before)));
        $b = explode("\n", trim(str_replace("\r\n", "\n", $after)));

        $n = count($a);
        $m = count($b);

        // Guard the quadratic table. A 6,000-character prompt is a few hundred lines at
        // worst, so this never fires in practice — but an admin screen must not be the
        // thing that exhausts memory on shared hosting.
        if ($n > 800 || $m > 800) {
            return [['op' => 'del', 'text' => $before], ['op' => 'add', 'text' => $after]];
        }

        /** @var array<int,array<int,int>> */
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j]
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $out = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $out[] = ['op' => 'same', 'text' => $a[$i]];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $out[] = ['op' => 'del', 'text' => $a[$i++]];
            } else {
                $out[] = ['op' => 'add', 'text' => $b[$j++]];
            }
        }
        while ($i < $n) $out[] = ['op' => 'del', 'text' => $a[$i++]];
        while ($j < $m) $out[] = ['op' => 'add', 'text' => $b[$j++]];

        return $out;
    }

    /** @param object $r @return array<string,mixed> */
    private static function hydrate(object $r): array
    {
        return [
            'id'         => (int) $r->id,
            'capability' => (string) $r->capability,
            'version'    => (int) $r->version,
            'body'       => (string) $r->body,
            'note'       => (string) ($r->note ?? ''),
            'is_active'  => (bool) $r->is_active,
            'created_by' => (int) ($r->created_by ?? 0),
            'created_at' => (string) ($r->created_at ?? ''),
        ];
    }
}
