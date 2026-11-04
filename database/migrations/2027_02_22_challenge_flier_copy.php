<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as DB;

/**
 * A challenge's campaign lines, and the portrait its flier is built around.
 *
 * The flier's headline ("Know 10 people who make Alimosho proud?"), its standfirst and its
 * tagline are campaign copy, written once per challenge by whoever runs it — not rules,
 * so they stay editable after publication, as the title and the art already are. Numbers
 * in them are PLACEHOLDERS ({target}, {cap}, {prize}), filled by ChallengeCopy::flier()
 * from the row, so a headline cannot go on saying "10" after the target changes.
 *
 * VARCHAR, nullable: an empty line falls back to the challenge's own title or promise.
 * Idempotent. NEVER exit/die here (include()d in a loop).
 */

$sqlite = DB::connection()->getDriverName() === 'sqlite';
$schema = DB::schema();
if ($schema->hasTable('gates_challenges')) {
    foreach (['headline' => 160, 'standfirst' => 300, 'tagline' => 120,
              'portrait_url' => 400, 'portrait_alt' => 200] as $col => $len) {
        if ($schema->hasColumn('gates_challenges', $col)) continue;
        DB::statement($sqlite ? "ALTER TABLE gates_challenges ADD COLUMN {$col} TEXT NULL"
                              : "ALTER TABLE gates_challenges ADD COLUMN {$col} VARCHAR({$len}) NULL");
        echo "  + gates_challenges.{$col}\n";
    }
}

// ── AND THE ONE CHALLENGE THAT ALREADY HAS ITS LINES ────────────────────────
//
// The Celebrate Nigeria seed now carries its flier's headline, standfirst, tagline and
// portrait. A seed runs once per database, so where it had already run these would never
// arrive — and the flier would print the bare title over the prize.
//
// NOT by re-running the seed, forced. A second run rewrites the title, the kicker, the
// summary and the art as well, so anything an operator had corrected on that challenge in
// the builder would be silently put back to the handoff's words. Here only the five new
// columns are written, only where they are still empty, from the same values the seed
// reads (`SeedReview::values()` — the handoff's, with any edit an operator has made).
// Where the seed has not run yet there is no row, nothing happens, and the seed writes
// the lines itself when it does.
if ($schema->hasTable('gates_challenges') && $schema->hasColumn('gates_challenges', 'portrait_alt')) {
    $v = \AfricaGates\Support\SeedReview::values('2026_10_01_celebrate_nigeria');
    $lines = ['headline' => $v['headline'] ?? null, 'standfirst' => $v['standfirst'] ?? null,
              'tagline' => $v['tagline'] ?? null, 'portrait_url' => $v['portrait_url'] ?? null,
              'portrait_alt' => $v['portrait_alt'] ?? null];
    $n = 0;
    foreach (array_filter($lines, 'is_string') as $col => $value) {
        $n += DB::table('gates_challenges')->where('slug', 'celebrate-nigeria-2026')
            ->where(fn ($q) => $q->whereNull($col)->orWhere($col, ''))
            ->update([$col => $value]);
    }
    echo "  · celebrate-nigeria flier lines: {$n} filled\n";
}
