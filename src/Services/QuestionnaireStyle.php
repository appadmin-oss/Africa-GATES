<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Carbon;

/**
 * Which questionnaire a programme runs, and everything an interview needs to know.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * TWO STYLES, CHOSEN PER PROGRAMME
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * 'form'      — the guided questionnaire. Questions, branching, a coach beside the box.
 * 'interview' — a conversation that goes wherever the nominee takes it and still converges,
 *               because it is steered by OUTCOMES rather than a question list.
 *
 * Per programme rather than platform-wide, because the two suit different people: a research
 * institute drafting a funding case over three sittings wants a form, and a market trader
 * describing twenty years of work does not.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * SPECIFIC BEATS GLOBAL, ON EVERY ONE OF THESE TABLES
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * A row with `programme_id` set wins over one with NULL — the same rule the questions, the
 * judging criteria and the discount codes already use, so an operator learns it once and it
 * holds everywhere. For outcomes and knowledge that resolution is per SLUG, not per table: a
 * programme that overrides one outcome keeps the other seven.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * AND AN INTERVIEW WITH NOTHING AUTHORED STILL WORKS
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * {@see outcomes()} falls back to DERIVING outcomes from the programme's own questions, which
 * already carry a slug, a label, a criterion and a required flag. So switching a programme to
 * 'interview' produces a working conversation before anybody opens the builder, aimed at
 * exactly the criteria the form was aimed at.
 *
 * That fallback is not a convenience. It is what makes {@see QuestionnaireService::publishEvidence()}
 * need no change at all: evidence still lands per criterion, it just arrives from a quote in a
 * conversation rather than from a field in a form.
 */
final class QuestionnaireStyle
{
    public const FORM      = 'form';
    public const INTERVIEW = 'interview';

    /** The capability the live interview runs under — budget, audit and disclosure. */
    public const CAPABILITY = 'questionnaire.interview';

    /**
     * The interview runs on OpenAI.
     *
     * ── WHY THIS ONE FEATURE NAMES A PROVIDER WHEN NOTHING ELSE HERE DOES ────
     *
     * Every other AI capability on this platform is written to take whichever key is
     * configured, because every other one is advisory: a follow-up suggestion from a smaller
     * model is a slightly worse suggestion. This one builds the record a judging panel reads,
     * and it does it through tool calls whose quotes must be copied EXACTLY. A model that
     * quotes approximately produces a ledger of rejected calls and an interview that never
     * converges — which reads to the nominee as a conversation that is not listening.
     *
     * So the route is pinned, and {@see interviewPossible()} requires the pinned provider's key
     * specifically. No key, no interview: the programme falls back to the guided form and the
     * page says so. That is a better failure than a conversation held by a model that cannot
     * hold it.
     *
     * The pin is overridable per programme from the builder, for a deployment that has paid for
     * something else. It is a default, not a law.
     */
    public const DEFAULT_ROUTE = 'openai:gpt-4o-mini';

    /**
     * Config for a programme, with every field resolved: its own row, else the platform row,
     * else the shipped default.
     *
     * Returns an ARRAY rather than the database row, because a caller reading `$cfg->brief` on
     * a programme that has no row would get a null it then has to handle at every use site.
     * Nothing here is ever null.
     *
     * @return array<string,mixed>
     */
    public static function config(?int $programmeId): array
    {
        $rows = self::configRows();
        $own    = $programmeId !== null ? ($rows[$programmeId] ?? null) : null;
        $global = $rows[0] ?? null;

        $pick = static function (string $key, mixed $default) use ($own, $global): mixed {
            foreach ([$own, $global] as $r) {
                if ($r === null) continue;
                $v = $r->{$key} ?? null;
                if ($v === null) continue;
                if (is_string($v) && trim($v) === '') continue;
                return $v;
            }
            return $default;
        };

        return [
            'id'              => $own?->id ?? $global?->id ?? null,
            'programme_id'    => $own !== null ? $programmeId : null,
            'style'           => (string) $pick('style', self::FORM),
            'brief'           => (string) $pick('brief', ''),
            'greeting'        => (string) $pick('greeting', self::DEFAULT_GREETING),
            'persona'         => (string) $pick('persona', ''),
            'closing'         => (string) $pick('closing', ''),
            'max_turns'       => max(4, (int) $pick('max_turns', 40)),
            'token_ceiling'   => max(10_000, (int) $pick('token_ceiling', 120_000)),
            'kb_token_budget' => max(0, (int) $pick('kb_token_budget', 3_000)),
            'route'           => (string) $pick('route', self::DEFAULT_ROUTE),
            // Whether a row exists at all, so the builder can say "using the platform default"
            // rather than showing an empty form that looks unconfigured when it is inherited.
            'inherited'       => $own === null,
        ];
    }

    /**
     * The opening line, when nobody has written one.
     *
     * Written rather than generated, and that is deliberate: a first turn that costs an API
     * call is a first turn that can fail, and the worst possible moment for this feature to be
     * unavailable is the moment somebody opens it.
     */
    public const DEFAULT_GREETING =
        'Thank you for making the time. I am going to ask you about your work, in your own '
      . 'words — there are no right answers here and nothing you say reaches the judges until '
      . 'you have read it back and sent it yourself. To start: what is the work you would most '
      . 'want a panel to understand?';

    /**
     * Which style ONE programme runs right now.
     *
     * `$live` is the question a nominee's page has to ask: is the interview actually going to
     * work? A programme set to 'interview' on a deployment whose AI key has been removed must
     * degrade to the form rather than open an empty chat, and it must be able to say so.
     */
    public static function styleFor(?int $programmeId, bool $live = true): string
    {
        $style = (string) (self::config($programmeId)['style'] ?? self::FORM);
        if ($style !== self::INTERVIEW) return self::FORM;
        if (!$live) return self::INTERVIEW;
        return self::interviewPossible($programmeId) ? self::INTERVIEW : self::FORM;
    }

    /**
     * Can a live interview run at all on this deployment?
     *
     * Two questions, not one. The gateway answers the switches and the daily budget. This adds
     * the one it cannot see: is the key for the PINNED provider actually configured?
     *
     * That is deliberately stricter than "any provider is configured". A deployment holding
     * only a Gemini key looks configured to every other AI feature here and cannot carry the
     * tool calls this one is built on; a deployment holding a small fast model can carry them
     * and quotes too loosely to be useful. Either way the interview would open, hold a
     * pleasant conversation, record nothing, and never explain itself — which is the worst
     * available outcome, because it wastes a nominee's evening before a deadline.
     *
     * Failing here instead sends them to the guided form with an explanation.
     */
    public static function interviewPossible(?int $programmeId = null): bool
    {
        if (!AiGateway::available(self::CAPABILITY)) return false;
        $provider = self::routeProvider($programmeId);
        if (!in_array($provider, AiService::TOOL_PROVIDERS, true)) return false;
        try {
            return AiService::boot()->hasProvider($provider);
        } catch (\Throwable) {
            return false;
        }
    }

    /** The provider half of the configured route pin, e.g. 'openai'. */
    public static function routeProvider(?int $programmeId = null): string
    {
        $route = trim((string) (self::config($programmeId)['route'] ?? self::DEFAULT_ROUTE));
        if ($route === '') $route = self::DEFAULT_ROUTE;
        return strtolower(trim(explode(':', $route, 2)[0]));
    }

    /**
     * The knowledge the interviewer is allowed to have, in the order it should survive a
     * budget cut.
     *
     * @return list<array{id:int,title:string,body:string}>
     */
    public static function knowledge(?int $programmeId): array
    {
        return self::scoped('gates_questionnaire_knowledge', $programmeId, static fn(object $r): array => [
            'id'    => (int) $r->id,
            'title' => (string) $r->title,
            'body'  => (string) $r->body,
        ]);
    }

    /**
     * Extra instructions captured while rehearsing, appended to the brief.
     *
     * @return list<array{id:int,body:string,source:string}>
     */
    public static function rules(?int $programmeId): array
    {
        return self::scoped('gates_questionnaire_rules', $programmeId, static fn(object $r): array => [
            'id'     => (int) $r->id,
            'body'   => (string) $r->body,
            'source' => (string) ($r->source ?? 'hand'),
        ]);
    }

    /**
     * What this programme's interview must come away with.
     *
     * ── THE FALLBACK IS THE IMPORTANT HALF ───────────────────────────────────
     *
     * With nothing authored, the programme's own QUESTIONS become the outcomes: their slug,
     * their label, their help text as the description, their criterion, their required flag.
     * A programme switched to 'interview' therefore works immediately and aims at exactly the
     * criteria the form aimed at — and an administrator who then writes their own outcomes is
     * refining something that already runs rather than building from an empty screen.
     *
     * Derived outcomes are marked `derived => true` so the builder can say where they came
     * from instead of presenting them as somebody's editorial decision.
     *
     * @return list<array{slug:string,label:string,description:string,criterion_id:?int,
     *                    evidence_kind:string,required:bool,derived:bool}>
     */
    public static function outcomes(?int $programmeId): array
    {
        $stored = self::scoped('gates_questionnaire_outcomes', $programmeId, static fn(object $r): array => [
            'slug'          => (string) $r->slug,
            'label'         => (string) $r->label,
            'description'   => (string) ($r->description ?? ''),
            'criterion_id'  => ($r->criterion_id ?? null) !== null ? (int) $r->criterion_id : null,
            'evidence_kind' => (string) ($r->evidence_kind ?? 'note'),
            'required'      => (int) ($r->is_required ?? 1) === 1,
            'derived'       => false,
        ], 'slug');

        if ($stored !== []) return $stored;

        $out = [];
        foreach (QuestionnaireService::questions((int) ($programmeId ?? 0)) as $q) {
            $slug = (string) ($q['slug'] ?? '');
            if ($slug === '') continue;
            $out[] = [
                'slug'          => $slug,
                'label'         => (string) ($q['label'] ?? $slug),
                'description'   => (string) ($q['help'] ?? ''),
                'criterion_id'  => ($q['criterion_id'] ?? null) !== null ? (int) $q['criterion_id'] : null,
                'evidence_kind' => (string) ($q['evidence_kind'] ?? 'note'),
                'required'      => (int) ($q['is_required'] ?? 0) === 1,
                'derived'       => true,
            ];
        }
        return $out;
    }

    /** The outcomes keyed by slug — the set a tool call is validated against. */
    public static function outcomeSet(?int $programmeId): array
    {
        $by = [];
        foreach (self::outcomes($programmeId) as $o) $by[$o['slug']] = $o;
        return $by;
    }

    // ══ writing (the admin builder) ══════════════════════════════════════════

    /**
     * Save one programme's configuration, creating the row if it is the first edit.
     *
     * `programme_id` null writes the PLATFORM row, which is how an operator sets a default
     * without touching any programme. Deliberately the same method: two would drift.
     *
     * @param array<string,mixed> $fields
     */
    public static function saveConfig(?int $programmeId, array $fields): bool
    {
        $now  = Carbon::now()->toDateTimeString();
        $data = [];

        foreach (['brief', 'greeting', 'persona', 'closing', 'route'] as $k) {
            if (array_key_exists($k, $fields)) $data[$k] = trim((string) $fields[$k]);
        }
        if (array_key_exists('style', $fields)) {
            $data['style'] = (string) $fields['style'] === self::INTERVIEW ? self::INTERVIEW : self::FORM;
        }
        // Clamped rather than validated-and-refused: an administrator who types 5,000,000 into
        // a token ceiling has made a typo, not a decision, and refusing the whole save over it
        // would lose the brief they wrote in the same submission.
        if (array_key_exists('max_turns', $fields)) {
            $data['max_turns'] = max(4, min(200, (int) $fields['max_turns']));
        }
        if (array_key_exists('token_ceiling', $fields)) {
            $data['token_ceiling'] = max(10_000, min(2_000_000, (int) $fields['token_ceiling']));
        }
        if (array_key_exists('kb_token_budget', $fields)) {
            $data['kb_token_budget'] = max(0, min(60_000, (int) $fields['kb_token_budget']));
        }
        if ($data === []) return false;

        try {
            $existing = DB::table('gates_questionnaire_config')
                ->where(static function ($q) use ($programmeId): void {
                    $programmeId === null ? $q->whereNull('programme_id')
                                          : $q->where('programme_id', $programmeId);
                })->first();

            if ($existing) {
                DB::table('gates_questionnaire_config')->where('id', (int) $existing->id)
                    ->update($data + ['updated_at' => $now]);
            } else {
                DB::table('gates_questionnaire_config')->insert(
                    $data + ['programme_id' => $programmeId, 'created_at' => $now]);
            }
            self::$configCache = null;
            return true;
        } catch (\Throwable $e) {
            error_log('[questionnaire-style] could not save config: ' . $e->getMessage());
            return false;
        }
    }

    /** Add or update one knowledge entry. Returns its id, or 0. */
    public static function saveKnowledge(?int $programmeId, ?int $id, string $title, string $body, int $sort = 0): int
    {
        $title = trim($title);
        $body  = trim($body);
        if ($title === '' || $body === '') return 0;
        return self::upsert('gates_questionnaire_knowledge', $id, [
            'programme_id' => $programmeId, 'title' => mb_substr($title, 0, 160),
            'body' => $body, 'sort_order' => max(0, $sort),
        ]);
    }

    /** Add or update one outcome. Returns its id, or 0. */
    public static function saveOutcome(?int $programmeId, ?int $id, array $f): int
    {
        $slug = self::slug((string) ($f['slug'] ?? ''));
        $label = trim((string) ($f['label'] ?? ''));
        if ($slug === '' || $label === '') return 0;
        return self::upsert('gates_questionnaire_outcomes', $id, [
            'programme_id'  => $programmeId,
            'slug'          => $slug,
            'label'         => mb_substr($label, 0, 160),
            'description'   => trim((string) ($f['description'] ?? '')),
            'criterion_id'  => ($f['criterion_id'] ?? null) ? (int) $f['criterion_id'] : null,
            'evidence_kind' => mb_substr(trim((string) ($f['evidence_kind'] ?? 'note')), 0, 40),
            'is_required'   => !empty($f['required']) ? 1 : 0,
            'sort_order'    => max(0, (int) ($f['sort_order'] ?? 0)),
        ]);
    }

    /** Add one rule. `source` marks whether a failing turn is behind it. */
    public static function addRule(?int $programmeId, string $body, string $source = 'hand',
                                   string $note = '', ?int $adminId = null): int
    {
        $body = trim($body);
        if ($body === '') return 0;
        return self::upsert('gates_questionnaire_rules', null, [
            'programme_id' => $programmeId,
            'body'         => mb_substr($body, 0, 500),
            'source'       => $source === 'rehearsal' ? 'rehearsal' : 'hand',
            'note'         => mb_substr(trim($note), 0, 500),
            'created_by'   => $adminId,
            'sort_order'   => 0,
        ]);
    }

    /** Switch one row off. Never a DELETE: a rule somebody wrote is worth being able to undo. */
    public static function retire(string $table, int $id): bool
    {
        if (!in_array($table, ['gates_questionnaire_knowledge', 'gates_questionnaire_outcomes',
                               'gates_questionnaire_rules'], true)) {
            return false;
        }
        try {
            return DB::table($table)->where('id', $id)
                ->update(['is_active' => 0, 'updated_at' => Carbon::now()->toDateTimeString()]) > 0;
        } catch (\Throwable) { return false; }
    }

    /** Switch one row back on. */
    public static function restore(string $table, int $id): bool
    {
        if (!in_array($table, ['gates_questionnaire_knowledge', 'gates_questionnaire_outcomes',
                               'gates_questionnaire_rules'], true)) {
            return false;
        }
        try {
            return DB::table($table)->where('id', $id)
                ->update(['is_active' => 1, 'updated_at' => Carbon::now()->toDateTimeString()]) > 0;
        } catch (\Throwable) { return false; }
    }

    /**
     * Copy the derived outcomes into real rows, so they can be edited.
     *
     * Only ever from the builder, and only when the programme has none — the same rule
     * {@see QuestionnaireService::seedDefaults()} follows. Seeding on read would fill the
     * table on the first page view of a platform that may never want to change a word.
     */
    public static function seedOutcomes(?int $programmeId): int
    {
        try {
            $has = DB::table('gates_questionnaire_outcomes')
                ->where(static function ($q) use ($programmeId): void {
                    $programmeId === null ? $q->whereNull('programme_id')
                                          : $q->where('programme_id', $programmeId);
                })->count();
            if ($has > 0) return 0;
        } catch (\Throwable) { return 0; }

        $n = 0;
        foreach (self::outcomes($programmeId) as $i => $o) {
            if (self::saveOutcome($programmeId, null, $o + ['sort_order' => $i + 1]) > 0) $n++;
        }
        return $n;
    }

    // ══ internals ════════════════════════════════════════════════════════════

    /** @var array<int,object>|null */
    private static ?array $configCache = null;

    /** @return array<int,object> keyed by programme id, with 0 for the platform row */
    private static function configRows(): array
    {
        if (self::$configCache !== null) return self::$configCache;
        $by = [];
        try {
            // Ordered, so which row wins is decided rather than incidental.
            //
            // The UNIQUE on `programme_id` does not constrain NULLs — on either driver — so
            // two platform-default rows are possible, and `saveConfig()` only checks-then-
            // inserts, which a second admin saving at the same moment can slip past. Without
            // an ORDER BY, whichever the engine happened to return last became the live
            // configuration, and the interview would change character between page loads with
            // nothing to point at. Highest id wins: the most recent save is the intended one.
            foreach (DB::table('gates_questionnaire_config')->where('is_active', 1)
                        ->orderBy('id')->get() as $r) {
                $by[(int) ($r->programme_id ?? 0)] = $r;
            }
        } catch (\Throwable) {}
        return self::$configCache = $by;
    }

    /** Clear the per-request config cache. Public so a test can. */
    public static function forget(): void
    {
        self::$configCache = null;
    }

    /**
     * Active rows for a programme plus the global ones, programme-specific winning.
     *
     * When `$dedupeBy` is given the win is per that column, so a programme overriding ONE
     * outcome keeps the rest of the platform set rather than replacing it wholesale — which
     * is what a table-level "own rows if any" rule would do, and is never what anybody means.
     *
     * @param callable(object):array<string,mixed> $shape
     * @return list<array<string,mixed>>
     */
    private static function scoped(string $table, ?int $programmeId, callable $shape,
                                   ?string $dedupeBy = null): array
    {
        try {
            $rows = DB::table($table)->where('is_active', 1)
                ->where(static function ($q) use ($programmeId): void {
                    $q->whereNull('programme_id');
                    if ($programmeId !== null) $q->orWhere('programme_id', $programmeId);
                })
                ->orderBy('sort_order')->orderBy('id')->get();
        } catch (\Throwable) {
            return [];
        }

        if ($dedupeBy === null) {
            return array_values(array_map($shape, $rows->all()));
        }

        $by = [];
        foreach ($rows as $r) {
            $key = (string) ($r->{$dedupeBy} ?? '');
            if ($key === '') continue;
            if (!isset($by[$key]) || !empty($r->programme_id)) $by[$key] = $r;
        }
        return array_values(array_map($shape, array_values($by)));
    }

    /** @param array<string,mixed> $data */
    private static function upsert(string $table, ?int $id, array $data): int
    {
        $now = Carbon::now()->toDateTimeString();
        try {
            if ($id !== null && $id > 0) {
                DB::table($table)->where('id', $id)->update($data + ['updated_at' => $now]);
                return $id;
            }
            return (int) DB::table($table)->insertGetId($data + ['is_active' => 1, 'created_at' => $now]);
        } catch (\Throwable $e) {
            error_log('[questionnaire-style] could not write ' . $table . ': ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * A slug the model can name without ambiguity.
     *
     * Lowercase, underscores, nothing else. The model gets this vocabulary in its system
     * prompt and every tool call is checked against it, so a slug carrying a space or a capital
     * is a slug that will be typed back subtly differently and silently dropped.
     */
    public static function slug(string $raw): string
    {
        $s = strtolower(trim($raw));
        $s = (string) preg_replace('/[^a-z0-9]+/', '_', $s);
        return trim(mb_substr($s, 0, 60), '_');
    }
}
