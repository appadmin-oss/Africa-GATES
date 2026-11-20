<?php
declare(strict_types=1);

namespace AfricaGates\Admin\Services;

use AfricaGates\Support\ChatMarkdown;
use AfricaGates\Support\SchemaHas;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * The console assistant's conversations (2027_04_04_assistant_threads.php): one person's
 * own, kept on the server, grouped by when they last moved, searchable, rateable.
 *
 * Every read is scoped to the administrator asking. A conversation holds check output, and
 * the check a superadmin ran is not one a moderator's rail would show them — so a thread id
 * typed into a URL by somebody else reads as not found, never as somebody else's answers.
 *
 * And each assistant turn keeps its EVIDENCE: the result of every check it ran, shaped for
 * the panel beside the conversation (a table where the check returned rows, a list of
 * figures where it returned a summary, the text where it returned prose). An answer that
 * says "3 orders stuck" can then be laid next to the three orders.
 */
final class AssistantThreads
{
    /** How long a conversation is kept after it last moved. The evidence can name people. */
    public const RETAIN_DAYS = 90;
    /** Turns sent back to the model as history. */
    public const HISTORY = 10;
    private const MAX_ROWS = 200;
    private const MAX_COLS = 8;

    public static function ready(): bool
    {
        return SchemaHas::table('gates_assistant_threads') && SchemaHas::table('gates_assistant_messages');
    }

    // ── threads ──────────────────────────────────────────────────────────────

    /**
     * This person's conversations, newest first, each with the heading it sits under.
     *
     * @return list<array{id:int, title:string, updated_at:string, group:string}>
     */
    public static function list(int $adminId, string $q = '', int $limit = 60): array
    {
        if ($adminId < 1 || !self::ready()) return [];
        try {
            $query = DB::table('gates_assistant_threads as t')->where('t.admin_id', $adminId)
                ->where('t.updated_at', '>=', date('Y-m-d H:i:s', time() - self::RETAIN_DAYS * 86400));
            $q = trim($q);
            if ($q !== '') {
                // Title or anything said in it. Wildcards in what was typed are literal.
                $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr($q, 0, 80)) . '%';
                $query->where(static function ($w) use ($like): void {
                    $w->whereRaw("t.title LIKE ? ESCAPE '!'", [$like])
                      ->orWhereExists(static function ($e) use ($like): void {
                          $e->from('gates_assistant_messages as m')->whereColumn('m.thread_id', 't.id')->whereRaw("m.body LIKE ? ESCAPE '!'", [$like]);
                      });
                });
            }
            $rows = $query->orderByDesc('t.updated_at')->orderByDesc('t.id')->limit($limit)->get(['t.id', 't.title', 't.updated_at']);
        } catch (\Throwable $e) {
            error_log('[assistant] list: ' . $e->getMessage());
            return [];
        }
        $today = strtotime('today');
        $out = [];
        foreach ($rows as $r) {
            $at = strtotime((string) $r->updated_at) ?: time();
            $out[] = ['id' => (int) $r->id, 'title' => (string) $r->title ?: 'New conversation', 'updated_at' => (string) $r->updated_at,
                      'group' => match (true) {
                          $at >= $today              => 'Today',
                          $at >= $today - 86400      => 'Yesterday',
                          $at >= $today - 7 * 86400  => 'Past week',
                          $at >= $today - 30 * 86400 => 'Past month',
                          default                    => 'Older',
                      }];
        }
        return $out;
    }

    /** @return array{id:int,title:string}|null — only this person's */
    public static function find(int $threadId, int $adminId): ?array
    {
        if ($threadId < 1 || $adminId < 1 || !self::ready()) return null;
        try {
            $t = DB::table('gates_assistant_threads')->where('id', $threadId)->where('admin_id', $adminId)->first(['id', 'title']);
        } catch (\Throwable) {
            return null;
        }
        return $t ? ['id' => (int) $t->id, 'title' => (string) $t->title] : null;
    }

    public static function create(int $adminId, string $firstQuestion): ?int
    {
        if ($adminId < 1 || !self::ready()) return null;
        $now = date('Y-m-d H:i:s');
        try {
            return (int) DB::table('gates_assistant_threads')->insertGetId([
                'admin_id' => $adminId, 'title' => self::title($firstQuestion), 'created_at' => $now, 'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            error_log('[assistant] create: ' . $e->getMessage());
            return null;
        }
    }

    public static function delete(int $threadId, int $adminId): bool
    {
        if (self::find($threadId, $adminId) === null) return false;
        try {
            DB::table('gates_assistant_messages')->where('thread_id', $threadId)->delete();
            return DB::table('gates_assistant_threads')->where('id', $threadId)->where('admin_id', $adminId)->delete() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** A title from the first question: one line, at most 80 characters, cut at a word. */
    public static function title(string $q): string
    {
        $q = trim((string) preg_replace('/\s+/u', ' ', $q));
        if (mb_strlen($q) <= 80) return $q;
        $cut = mb_substr($q, 0, 80);
        $sp = mb_strrpos($cut, ' ');
        return rtrim($sp !== false && $sp > 40 ? mb_substr($cut, 0, $sp) : $cut, ' ,.;:') . '…';
    }

    // ── messages ─────────────────────────────────────────────────────────────

    /**
     * @param list<array{key:string,title:string,ok:bool,ms:int,output:string}> $runs
     * @param list<array{label:string,url:string}> $actions
     */
    public static function append(int $threadId, string $role, string $body, array $runs = [], array $actions = [], string $page = ''): ?int
    {
        if ($threadId < 1 || !self::ready()) return null;
        $evidence = [];
        foreach ($runs as $r) $evidence[] = self::shape((string) $r['key'], (string) $r['title'], (bool) $r['ok'], (int) $r['ms'], (string) $r['output']);
        $now = date('Y-m-d H:i:s');
        try {
            $id = (int) DB::table('gates_assistant_messages')->insertGetId([
                'thread_id' => $threadId, 'role' => $role === 'assistant' ? 'assistant' : 'user',
                'body' => mb_substr($body, 0, 20000),
                'ran_json' => $runs ? (string) json_encode(array_map(static fn (array $r): array =>
                    ['key' => (string) $r['key'], 'title' => (string) $r['title'], 'ok' => (bool) $r['ok'], 'ms' => (int) $r['ms']], $runs), JSON_UNESCAPED_UNICODE) : null,
                'actions_json'  => $actions ? (string) json_encode(array_values($actions), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'evidence_json' => $evidence ? (string) json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
                'page' => $page !== '' ? mb_substr($page, 0, 300) : null,
                'created_at' => $now,
            ]);
            DB::table('gates_assistant_threads')->where('id', $threadId)->update(['updated_at' => $now]);
            return $id;
        } catch (\Throwable $e) {
            error_log('[assistant] append: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Every turn of one conversation, as the screen draws it.
     *
     * @return list<array<string,mixed>>
     */
    public static function messages(int $threadId, int $adminId): array
    {
        if (self::find($threadId, $adminId) === null) return [];
        try {
            $rows = DB::table('gates_assistant_messages')->where('thread_id', $threadId)->orderBy('id')->limit(400)->get();
        } catch (\Throwable) {
            return [];
        }
        return array_map([self::class, 'present'], $rows->all());
    }

    /** The last turns, as the model reads its history: plain text, oldest first. @return list<array{role:string,text:string}> */
    public static function history(int $threadId, int $adminId): array
    {
        $all = self::messages($threadId, $adminId);
        return array_map(static fn (array $m): array => ['role' => $m['role'], 'text' => $m['text']], array_slice($all, -self::HISTORY));
    }

    /** One stored turn, for the screen. */
    public static function present(object $m): array
    {
        $body = (string) $m->body;
        $assistant = (string) $m->role === 'assistant';
        return [
            'id'       => (int) $m->id,
            'role'     => $assistant ? 'assistant' : 'user',
            'text'     => $assistant ? ChatMarkdown::plain($body) : $body,
            'html'     => $assistant ? ChatMarkdown::html($body) : '',
            'ran'      => json_decode((string) ($m->ran_json ?? ''), true) ?: [],
            'actions'  => array_values(array_filter(json_decode((string) ($m->actions_json ?? ''), true) ?: [], static fn ($a): bool =>
                            is_array($a) && preg_match('~^/admin(?:[/?#]|$)~', (string) ($a['url'] ?? '')) === 1)),
            'evidence' => json_decode((string) ($m->evidence_json ?? ''), true) ?: [],
            'rating'   => $m->rating !== null ? (int) $m->rating : 0,
            'at'       => (string) $m->created_at,
        ];
    }

    /** The operator's verdict on one answer of theirs: 1 helpful, -1 not, 0 withdrawn. */
    public static function rate(int $messageId, int $adminId, int $value): bool
    {
        if (!self::ready() || !in_array($value, [-1, 0, 1], true)) return false;
        try {
            $own = DB::table('gates_assistant_messages as m')->join('gates_assistant_threads as t', 't.id', '=', 'm.thread_id')
                ->where('m.id', $messageId)->where('m.role', 'assistant')->where('t.admin_id', $adminId)->exists();
            if (!$own) return false;
            DB::table('gates_assistant_messages')->where('id', $messageId)->update(['rating' => $value === 0 ? null : $value]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * How the assistant is rated, over the last 30 days — for the AI settings card.
     *
     * @return array{rated:int, helpful:int, answers:int}
     */
    public static function ratings(): array
    {
        if (!self::ready()) return ['rated' => 0, 'helpful' => 0, 'answers' => 0];
        try {
            $since = date('Y-m-d H:i:s', time() - 30 * 86400);
            $base = DB::table('gates_assistant_messages')->where('role', 'assistant')->where('created_at', '>=', $since);
            return ['answers' => (clone $base)->count(), 'rated' => (clone $base)->whereNotNull('rating')->count(),
                    'helpful' => (clone $base)->where('rating', 1)->count()];
        } catch (\Throwable) {
            return ['rated' => 0, 'helpful' => 0, 'answers' => 0];
        }
    }

    /** Daily: conversations untouched for RETAIN_DAYS go, with their evidence. Returns how many. */
    public static function prune(): int
    {
        if (!self::ready()) return 0;
        try {
            $old = DB::table('gates_assistant_threads')->where('updated_at', '<', date('Y-m-d H:i:s', time() - self::RETAIN_DAYS * 86400))->pluck('id')->all();
            foreach (array_chunk($old, 500) as $chunk) {
                DB::table('gates_assistant_messages')->whereIn('thread_id', $chunk)->delete();
                DB::table('gates_assistant_threads')->whereIn('id', $chunk)->delete();
            }
            return count($old);
        } catch (\Throwable) {
            return \AfricaGates\Support\Maintenance::TASK_FAILED;
        }
    }

    // ── evidence ─────────────────────────────────────────────────────────────

    /**
     * A check's output, shaped for the panel. Most checks print JSON (`--json`), and a list
     * of records is a table; a record is a list of figures, with its own first list of
     * records drawn as the table under them; anything else is the text as printed.
     *
     * @return array{key:string,title:string,ok:bool,ms:int,kind:string,columns:list<string>,rows:list<list<string>>,figures:list<array{k:string,v:string}>,text:string,total:int}
     */
    public static function shape(string $key, string $title, bool $ok, int $ms, string $output): array
    {
        $base = ['key' => $key, 'title' => $title, 'ok' => $ok, 'ms' => $ms, 'kind' => 'text',
                 'columns' => [], 'rows' => [], 'figures' => [], 'text' => '', 'total' => 0];
        $j = json_decode(trim($output), true);
        if (!is_array($j)) return array_replace($base, ['text' => mb_substr(trim($output), 0, 12000)]);

        $records = null;
        $figures = [];
        if (array_is_list($j)) {
            $records = $j;
        } else {
            foreach ($j as $k => $v) {
                if ($records === null && is_array($v) && $v !== [] && array_is_list($v) && is_array($v[0] ?? null)) { $records = $v; continue; }
                if (is_scalar($v) || $v === null) $figures[] = ['k' => self::label((string) $k), 'v' => self::cell($v)];
                elseif (is_array($v) && !array_is_list($v) && count($v) <= 12) {
                    foreach ($v as $k2 => $v2) if (is_scalar($v2) || $v2 === null) $figures[] = ['k' => self::label((string) $k) . ' · ' . trim((string) preg_replace('/[_\-]+/', ' ', (string) $k2)), 'v' => self::cell($v2)];
                }
            }
        }
        $columns = []; $rows = [];
        if (is_array($records)) {
            foreach ($records as $rec) {
                if (!is_array($rec)) continue;
                foreach (array_keys($rec) as $c) if (!in_array((string) $c, $columns, true) && count($columns) < self::MAX_COLS) $columns[] = (string) $c;
            }
            foreach (array_slice($records, 0, self::MAX_ROWS) as $rec) {
                if (!is_array($rec)) { $rows[] = [self::cell($rec)]; continue; }
                $rows[] = array_map(static fn (string $c): string => self::cell($rec[$c] ?? null), $columns);
            }
        }
        $kind = $rows !== [] ? 'table' : ($figures !== [] ? 'figures' : 'text');
        return array_replace($base, [
            'kind' => $kind, 'columns' => array_map([self::class, 'label'], $columns), 'rows' => $rows,
            'figures' => array_slice($figures, 0, 40), 'total' => is_array($records) ? count($records) : 0,
            'text' => $kind === 'text' ? mb_substr(trim($output), 0, 12000) : '',
        ]);
    }

    /** "pending_count" → "Pending count". */
    private static function label(string $k): string
    {
        $k = trim((string) preg_replace('/[_\-]+/', ' ', $k));
        return $k === '' ? '' : mb_strtoupper(mb_substr($k, 0, 1)) . mb_substr($k, 1);
    }

    private static function cell(mixed $v): string
    {
        return match (true) {
            $v === null  => '—',
            is_bool($v)  => $v ? 'yes' : 'no',
            is_scalar($v) => mb_substr((string) $v, 0, 300),
            default      => mb_substr((string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 300),
        };
    }
}
