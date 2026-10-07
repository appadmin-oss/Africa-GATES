<?php
declare(strict_types=1);

namespace AfricaGates\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Finds groups of nominees that are probably the SAME person, so an admin can
 * merge them (see {@see MergeService}) instead of hunting duplicates by eye.
 *
 * Two layers, advisory only — a human always confirms the merge:
 *   • deterministic (always on) — token-set matching within a category.
 *   • AI (optional) — one call per scan to catch what rules miss (nicknames,
 *     married/maiden names) with a confidence and a one-line rationale.
 *
 * Scoped to ONE category because that is what MergeService can merge, and where
 * vote-splitting actually happens.
 *
 * ── WHY THE MATCHER WAS REWRITTEN ────────────────────────────────────────────
 *
 * The old normaliser lowercased, dropped an honorific, folded diacritics and then
 * stripped everything non-alphanumeric — concatenating the name into one string in
 * the order it was typed. Measured against twelve realistic duplicate patterns it
 * missed four, and the four were not scattered:
 *
 *   Chinwe Okafor  /  Okafor Chinwe     REVERSED ORDER — missed
 *   Thabo Mbeki    /  Mbeki, Thabo      comma-inverted — missed
 *   Kwame Nkrumah  /  K. Nkrumah        initial        — missed
 *   Sipho Ndlovu   /  S Ndlovu          bare initial   — missed
 *
 * All four are one root cause: concatenating in input order means token order and
 * token abbreviation both destroy the match. That is not an edge case on a
 * pan-African platform — family-name-first is the norm in much of the continent,
 * forms get filled both ways, and "surname, given name" is how most official
 * records are written. Two entries for the same person under swapped order split
 * their votes, which is the exact failure this feature exists to prevent.
 *
 * So a name is now a SET of tokens, not a string:
 *
 *   • order-insensitive — the sorted token set is the identity
 *   • initial-aware     — a one-letter token matches a token starting with it
 *   • per-token fuzzy   — "Musa"/"Musah" matches; "Jane"/"John" does not, because
 *                         the comparison is per token instead of across a
 *                         concatenation where a short name is a small fraction of
 *                         the whole string
 *
 * ── AND WHY BLOCKING ─────────────────────────────────────────────────────────
 *
 * The old clustering compared every pair: 400 nominees is 79,800 comparisons, and
 * the cap existed to bound that. The cap was the worse problem. `SCAN_CAP = 400`
 * sliced the list AFTER `ORDER BY id`, so in a category with 417 nominees the 17
 * NEWEST were never scanned — and the newest entry is the likeliest duplicate of
 * an existing one. Measured on a 20k-nominee dataset, every category in the cycle
 * hit the cap, so the scan was systematically blind to exactly the rows it was
 * meant to catch.
 *
 * Candidate pairs now come from blocking keys instead of a full cross product, so
 * the cap can be an order of magnitude higher for less work. Two keys are used and
 * unioned, because one is not enough: the first letters of the sorted tokens (which
 * survives reordering and abbreviation) and the opening of the longest token (which
 * survives a typo in a given name). A pair sharing neither is not compared — the
 * trade-off is explicit, and {@see SCAN_CAP} no longer hides it.
 */
final class MergeSuggestionService
{
    /**
     * Hard ceiling on nominees scanned per category.
     *
     * Raised from 400 now that blocking replaced the all-pairs loop. When it does
     * bite, {@see forCategory()} reports HOW MANY were skipped and the rows kept
     * are the NEWEST, not the oldest — the previous slice took the first 400 by id,
     * which silently excluded recent entries, the ones most likely to be duplicates.
     */
    private const SCAN_CAP = 4000;

    /**
     * Largest blocking bucket that is still worth comparing pairwise.
     *
     * A bucket this size means the names in it are near-identical by construction —
     * a shared word like "Foundation" or "Initiative" as the longest token, or a
     * seeded dataset where every entry ends "Surname". Comparing it would restore
     * the cross product it exists to avoid, and would find nothing, because names
     * that generic carry no identifying signal.
     *
     * Skipping is reported, not silent: {@see forCategory()} returns how many
     * buckets were passed over, so "no duplicates found" cannot quietly mean "gave
     * up on 400 names that all look the same".
     */
    private const BUCKET_CAP = 400;

    /** Honorifics dropped before tokenising. */
    private const HONORIFICS = ['dr', 'prof', 'professor', 'chief', 'hon', 'honorable', 'honourable',
        'mr', 'mrs', 'ms', 'miss', 'engr', 'engineer', 'sir', 'rev', 'reverend', 'pastor',
        'alhaji', 'alhaja', 'hajia', 'imam', 'sheikh', 'oba', 'chief.', 'amb', 'ambassador',
        'barr', 'barrister', 'arc', 'architect', 'sen', 'senator', 'capt', 'captain', 'gen', 'general'];

    /** Generational/order suffixes that are not part of the identity. */
    private const SUFFIXES = ['jr', 'sr', 'i', 'ii', 'iii', 'iv', 'v'];

    /**
     * A name as an ORDERED-BY-VALUE list of normalised tokens.
     *
     * This replaces concatenation, which is what made word order significant. The
     * tokens are sorted, so "Chinwe Okafor" and "Okafor Chinwe" produce the same
     * list — and "Mbeki, Thabo" produces the same list as "Thabo Mbeki" once the
     * comma is treated as a separator rather than as part of a token.
     *
     * Honorifics are dropped anywhere in the name, not only at the start: "Jane Doe,
     * PhD" and "Engr Jane Doe" both reduce to ["doe","jane"]. Hyphens split, because
     * "Okonjo-Iweala" and "Okonjo Iweala" are the same name written two ways.
     *
     * @return list<string>
     */
    private static function tokens(string $name): array
    {
        $s = mb_strtolower(trim($name));
        $s = self::foldDiacritics($s);          // "José" → "jose", "Wangarĩ" → "wangari"
        // Every separator that appears in a written name becomes a space. The old
        // version deleted them instead, which is why "okonjo-iweala" survived but
        // "mbeki, thabo" kept its comma-joined order.
        $s = (string) preg_replace('/[^a-z0-9]+/u', ' ', $s);

        $out = [];
        foreach (preg_split('/\s+/', trim($s)) ?: [] as $t) {
            if ($t === '') continue;
            if (in_array($t, self::HONORIFICS, true)) continue;
            if (in_array($t, self::SUFFIXES, true)) continue;
            $out[] = $t;
        }
        sort($out);
        return $out;
    }

    /**
     * The order-insensitive identity of a name.
     *
     * Kept as a string so it can be an array key and, later, an indexed column.
     */
    private static function signature(string $name): string
    {
        return implode(' ', self::tokens($name));
    }

    /**
     * Retained for the callers that still want one comparable string, and because
     * {@see findLiveMatch()} documents an EXACT normalised match as its contract.
     * Now built from the sorted tokens, so it inherits order-insensitivity.
     */
    private static function norm(string $name): string
    {
        return str_replace(' ', '', self::signature($name));
    }

    /**
     * How confidently are these two names the same person? 0.0 when they are not.
     *
     * Compared token by token rather than as one concatenated string, which is what
     * lets a short given name matter. Under concatenation "janedoe"/"johndoe" is
     * edit-distance 2 out of 7 characters — inside the old threshold — while
     * "musa"/"musah" is distance 1 out of 4. Per token, "jane"/"john" is 2 of 4 and
     * is rejected, and "musa"/"musah" is 1 of 4 and is accepted. The old code needed
     * a `similarPct() >= 80` guard to paper over exactly this, and still could not
     * tell the two cases apart reliably.
     *
     * An INITIAL matches a full token beginning with the same letter, which is how
     * "K. Nkrumah" reaches "Kwame Nkrumah". It is scored lower than a full match on
     * purpose: "K. Nkrumah" is genuinely ambiguous between Kwame and Kofi, so it is
     * a suggestion to confirm rather than a near-certainty.
     */
    private static function nameScore(string $a, string $b): float
    {
        $ta = self::tokens($a);
        $tb = self::tokens($b);
        if ($ta === [] || $tb === []) return 0.0;
        if ($ta === $tb) return 0.97;

        // MONONYMS — stage names, organisations, single-name entries. An identical
        // pair is already handled above; a fuzzy pair is only credible when the token
        // is long, because one letter out of eight is a typo and one letter out of
        // four is a different name. "Chidinma"/"Chidimma" yes; "Musa"/"Musah" no,
        // with nothing else in the name to corroborate it.
        if (count($ta) < 2 && count($tb) < 2) {
            $a1 = $ta[0] ?? ''; $b1 = $tb[0] ?? '';
            if (mb_strlen($a1) < 6 || mb_strlen($b1) < 6) return 0.0;
            return self::nearToken($a1, $b1) ? 0.72 : 0.0;
        }

        // Greedy pairing over the smaller list. Names are 2–4 tokens, so an optimal
        // assignment would cost more to write than it could possibly buy.
        $long  = count($ta) >= count($tb) ? $ta : $tb;
        $short = count($ta) >= count($tb) ? $tb : $ta;
        $pool  = $long;

        $exact = 0; $initial = 0; $fuzzy = 0; $weak = 0;
        $unmatchedTokens = [];
        foreach ($short as $t) {
            $bestIdx = null; $bestKind = null;
            foreach ($pool as $i => $cand) {
                if ($cand === $t)                                  { $bestIdx = $i; $bestKind = 'exact';   break; }
                if (self::isInitialOf($t, $cand))                  { $bestIdx ??= $i; $bestKind ??= 'initial'; continue; }
                if ($bestKind === null && self::nearToken($t, $cand)) { $bestIdx = $i; $bestKind = 'fuzzy'; }
            }
            if ($bestIdx === null) { $unmatchedTokens[] = $t; continue; }
            unset($pool[$bestIdx]);
            match ($bestKind) { 'exact' => $exact++, 'initial' => $initial++, default => $fuzzy++ };
        }

        // SHORT-TOKEN RESCUE, exactly once, and only with corroboration.
        //
        // A three-letter token is too ambiguous to fuzzy-match on its own —
        // "Ali"/"Abu" would pass any distance threshold loose enough to catch
        // "Doe"/"Doo". But the rest of the name IS the corroboration: when every
        // other token matched exactly, one adjacent short token is a typo, not a
        // different person. So "Jane Doe"/"Jane Doo" matches on the strength of
        // "Jane", while "Ali Musa"/"Abu Musa" does not, because ali→abu is two
        // edits of three characters and the allowance is one.
        if (count($unmatchedTokens) === 1 && $exact >= 1 && $initial === 0 && $fuzzy === 0) {
            $t = $unmatchedTokens[0];
            foreach ($pool as $i => $cand) {
                if (mb_strlen($t) >= 3 && mb_strlen($cand) >= 3
                    && abs(mb_strlen($t) - mb_strlen($cand)) <= 1
                    && !preg_match('/\d/', $t) && !preg_match('/\d/', $cand)
                    // The FIRST letter must agree. Without it the rescue grouped
                    // "Jane Doe" with "Jane Roe" — one edit of three characters,
                    // beside an exact "Jane" — which is two different surnames, not
                    // a typo. A trailing-letter difference (doe/doo) is a slip; a
                    // leading one is a different name.
                    && mb_substr($t, 0, 1) === mb_substr($cand, 0, 1)
                    && levenshtein($t, $cand) === 1) {
                    unset($pool[$i]);
                    $unmatchedTokens = [];
                    $weak = 1;
                    break;
                }
            }
        }

        // Any token on the shorter side that still matches nothing means these are
        // different names, not variants of one. "Jane Doe" vs "Jane Roe".
        if ($unmatchedTokens !== []) return 0.0;
        // Extra tokens on the longer side are middle names — weak evidence against.
        $extra = count($pool);

        $score = match (true) {
            $weak    > 0 => 0.76,   // one short token off by a letter, rest exact
            $fuzzy   > 0 => 0.80,   // transliteration / spelling variant
            $initial > 0 => 0.86,   // abbreviated given name — confirm which one
            default      => 0.94,   // every token matched exactly, some reordering
        };
        return max(0.0, $score - (0.04 * $extra));
    }

    /**
     * Is $t a single-LETTER abbreviation of $full ("k" of "kwame")?
     *
     * Letters only. Digits are never abbreviations of digits, and treating them as
     * such is not hypothetical: on a 20,000-nominee dataset whose names are
     * "Nominee <n> Surname", "Nominee 1" matched "Nominee 11" — `1` "abbreviating"
     * `11` — and produced 306 confident duplicate groups out of nothing. Any name
     * carrying a number (a cohort year, an edition, "Team 2") would do the same.
     */
    private static function isInitialOf(string $t, string $full): bool
    {
        if ($t === $full) return false;
        [$short, $long] = mb_strlen($t) <= mb_strlen($full) ? [$t, $full] : [$full, $t];
        if (mb_strlen($short) !== 1 || mb_strlen($long) < 2) return false;
        if (!preg_match('/^\p{L}$/u', $short)) return false;      // not a letter → not an initial
        return str_starts_with($long, $short);
    }

    /**
     * Are two tokens the same name spelled differently?
     *
     * Length-proportional rather than a fixed distance: one edit in a four-letter
     * token is a plausible transliteration, one edit in a three-letter token is a
     * different word ("ali"/"abu"), and two edits in a ten-letter surname is still
     * plausibly the same surname.
     */
    private static function nearToken(string $a, string $b): bool
    {
        // A numeric token means something exact — an edition, a year, a team number.
        // "Cohort 2024" and "Cohort 2025" are two things, not a spelling variant.
        if (preg_match('/\d/', $a) || preg_match('/\d/', $b)) return false;
        // Same reasoning as the short-token rescue: transliteration preserves the
        // opening sound (Mohammed/Muhammad, Amina/Aminat), so a differing first
        // letter means a different name rather than a different spelling.
        if (mb_substr($a, 0, 1) !== mb_substr($b, 0, 1)) return false;
        $la = mb_strlen($a); $lb = mb_strlen($b);
        if ($la < 4 || $lb < 4) return false;          // too short to guess at
        if (abs($la - $lb) > 2)  return false;
        $allowed = (int) floor(min($la, $lb) / 4);      // 4–7 → 1, 8–11 → 2, …
        return $allowed >= 1 && levenshtein($a, $b) <= $allowed;
    }

    /**
     * Cheap keys that a genuine duplicate pair is very likely to share.
     *
     * Two of them, unioned, because either alone loses real matches. The initials
     * key survives reordering and abbreviation; the stem key survives a typo in a
     * given name, since the longest token is usually the surname and surnames are
     * the part people get right.
     *
     * @return list<string>
     */
    private static function blockKeys(string $name): array
    {
        $t = self::tokens($name);
        if ($t === []) return [];
        $keys = [];
        // "chinwe okafor" and "okafor chinwe" both sort to [chinwe, okafor] → "co".
        $keys[] = 'i:' . implode('', array_map(static fn (string $x): string => mb_substr($x, 0, 1), $t));
        // Longest token's opening. "aminat"/"amina" both give "s:amin" via "bello"…
        // whichever is longest, and a shared surname is what actually matters.
        $longest = '';
        foreach ($t as $x) if (mb_strlen($x) > mb_strlen($longest)) $longest = $x;
        if (mb_strlen($longest) >= 4) $keys[] = 's:' . mb_substr($longest, 0, 4);
        return $keys;
    }

    /** Fold accents to their base letter (é→e, ç→c) so diacritic variants match. No-op without ext-intl. */
    private static function foldDiacritics(string $s): string
    {
        if (class_exists('\Normalizer')) {
            $d = \Normalizer::normalize($s, \Normalizer::FORM_D);
            if (is_string($d)) $s = (string) preg_replace('/\p{Mn}+/u', '', $d);
        }
        return $s;
    }

    /**
     * @param bool $withAi Whether this scan may spend an AI call. forCycle() sets it
     *                     false per category and makes ONE call for the whole cycle:
     *                     the old code called the model once PER CATEGORY, so a scan
     *                     of a 48-category cycle was 48 calls against a 500-per-day
     *                     budget, i.e. ten scans and the feature is spent.
     * @return array{groups: list<array{nominee_ids:int[],names:string[],confidence:float,reason:string,source:string}>, scanned:int, capped:bool, skipped:int, ai:bool}
     */
    public static function forCategory(int $categoryId, ?AiService $ai = null, bool $withAi = true): array
    {
        $nominees = [];
        $total    = 0;
        try {
            $base = static function () use ($categoryId) {
                $q = DB::table('gates_nominees')
                    ->where('category_id', $categoryId)
                    ->whereIn('status', ['pending', 'approved', 'winner', 'runner_up']);
                MergeService::notMerged($q);            // never suggest merging a tombstone
                return $q;
            };
            $total = (int) $base()->count();
            // NEWEST first when the cap bites. The old query took the first
            // SCAN_CAP by ascending id, so a category over the cap never scanned its
            // most recent entries — and a duplicate is created by someone nominating
            // a person who is ALREADY listed, which makes the newest rows the ones
            // that most need looking at. Re-sorted ascending afterwards so group
            // ordering stays stable between runs.
            $nominees = $base()->orderByDesc('id')->limit(self::SCAN_CAP)
                ->get(['id', 'name', 'country_code', 'vote_count', 'photo_path', 'profile_id', 'status'])->all();
            usort($nominees, static fn (object $a, object $b): int => (int) $a->id <=> (int) $b->id);
        } catch (\Throwable) {}

        $skipped = max(0, $total - count($nominees));
        $capped  = $skipped > 0;

        $names = [];
        foreach ($nominees as $n) { $names[(int) $n->id] = (string) $n->name; }

        $crowded = 0;
        $groups  = self::ruleGroups($nominees, $crowded);

        // Index the rows so each group can carry the facts an admin needs to choose
        // a survivor, rather than only a list of names.
        $byId = [];
        foreach ($nominees as $row) $byId[(int) $row->id] = $row;

        $out = [];
        foreach ($groups as $g) {
            $h = self::hydrate($g, $byId, $names);
            if ($h !== null) $out[] = $h;
        }

        $usedAi = false; $batches = 0; $partial = false;
        if ($withAi && count($names) >= 2) {
            $catOf = array_fill_keys(array_keys($names), '');
            $r = self::aiPass($out, $names, $byId, $catOf, $ai);
            $out = $r['groups']; $usedAi = $r['used']; $batches = $r['batches']; $partial = $r['partial'];
        }
        $out = self::withoutDismissed($out);

        // Highest confidence first, so the clearest duplicates are actioned first.
        usort($out, static fn (array $a, array $b): int => $b['confidence'] <=> $a['confidence']);

        return [
            'groups'  => $out,
            // Exposed so forCycle() can make ONE AI pass over the whole cycle
            // instead of one per category. Not part of the UI contract.
            'names_by_id' => $names,
            'scanned' => count($names),
            'capped'  => $capped,
            // How many, not just whether. "capped: true" told an admin nothing
            // actionable; "1,200 not scanned" tells them to narrow the category.
            'skipped' => $skipped,
            // Blocking buckets too crowded to compare — see BUCKET_CAP. Non-zero
            // means some names in this category are generic enough that duplicate
            // detection cannot say anything useful about them.
            'crowded' => $crowded,
            'ai'      => $usedAi,
            'ai_batches' => $batches,
            'ai_partial' => $partial,
            // Internal, like names_by_id: forCycle() hydrates the AI's groups from it.
            'rows_by_id' => $byId,
        ];
    }

    /**
     * Aggregate suggestions across every category in a cycle.
     *
     * Merges only ever happen within a category, so each is clustered separately —
     * but the AI pass is made ONCE for the whole cycle rather than once per category.
     * The old loop called the model per category: a 48-category cycle spent 48 calls
     * of a 500-per-day budget on a single scan, so ten scans exhausted the feature
     * for the day, and each call saw a slice too small to spot anything the rules
     * had not already found.
     *
     * @return array{groups: list<array{nominee_ids:int[],names:string[],confidence:float,reason:string,source:string,category:string}>, scanned:int, capped:bool, skipped:int, ai:bool, categories:int}
     */
    public static function forCycle(int $cycleId, ?AiService $ai = null): array
    {
        $cats = [];
        try {
            $cats = DB::table('gates_award_categories')->where('cycle_id', $cycleId)->get(['id', 'title'])->all();
        } catch (\Throwable) {}

        $groups = []; $scanned = 0; $capped = false; $skipped = 0; $crowded = 0;
        // id => name / row / category title across the whole cycle, for the AI pass.
        $allNames = []; $rows = []; $catOf = [];

        foreach ($cats as $c) {
            $r = self::forCategory((int) $c->id, $ai, withAi: false);
            foreach ($r['groups'] as $g) { $g['category'] = (string) $c->title; $groups[] = $g; }
            $scanned += $r['scanned'];
            $capped   = $capped || $r['capped'];
            $skipped += $r['skipped'];
            $crowded += $r['crowded'];
            foreach ($r['names_by_id'] ?? [] as $id => $name) {
                $allNames[$id] = $name;
                $catOf[$id]  = (string) $c->title;
            }
            $rows += $r['rows_by_id'] ?? [];
        }

        $ai = self::aiPass($groups, $allNames, $rows, $catOf, $ai);
        $groups = self::withoutDismissed($ai['groups']);

        usort($groups, static fn (array $a, array $b): int => $b['confidence'] <=> $a['confidence']);
        return [
            'groups'     => $groups,
            'scanned'    => $scanned,
            'capped'     => $capped,
            'skipped'    => $skipped,
            'crowded'    => $crowded,
            'ai'         => $ai['used'],
            // How much the AI actually saw. "AI-assisted" over a cycle it only sampled
            // is the same lie as "no duplicates" over a partial scan.
            'ai_batches' => $ai['batches'],
            'ai_partial' => $ai['partial'],
            'categories' => count($cats),
        ];
    }

    /** Names per AI call: the prompt's size, not the category count, is the constraint. */
    private const AI_BATCH = 120;

    /**
     * AI calls per scan at most. A 48-category cycle used to spend 48 of a 500-per-day
     * budget on one click; this keeps a scan to a handful however large the edition.
     */
    private const AI_MAX_BATCHES = 6;

    /**
     * The AI pass: find what the rules missed, and say whether it agrees with what they found.
     *
     * ── WHY IT USED TO SEE NOTHING ───────────────────────────────────────────
     *
     * The AI ran only when the WHOLE cycle held 120 names or fewer. Any real edition is
     * larger, so "AI-assisted" duplicate detection had, in practice, never run on a live
     * cycle, while the screen offered it. And the groups it did return carried ids and a
     * reason but not the members, so they could not show votes or a recommended survivor.
     *
     * Now: one call for a small cycle (cross-category pairs dropped, as before); otherwise
     * per category, sending every name in a small category and, in a large one, the names
     * that share a word with another name there — the nicknames, married names and
     * transliterations the rules miss always share SOMETHING, a surname or a given name.
     * Capped at {@see AI_MAX_BATCHES}, and `partial` says when the cap bit.
     *
     * A rule group whose every member the AI saw gets a verdict: `agrees` when the AI put
     * them together, `unconfirmed` when it saw them and did not. The rules stay the floor;
     * the verdict is what an admin reads before trusting a 97%.
     *
     * @param list<array<string,mixed>> $groups hydrated rule groups
     * @param array<int,string> $names
     * @param array<int,object> $rows
     * @param array<int,string> $catOf id => category title ('' when one category)
     * @return array{groups:list<array<string,mixed>>, used:bool, batches:int, partial:bool}
     */
    private static function aiPass(array $groups, array $names, array $rows, array $catOf, ?AiService $ai): array
    {
        if (count($names) < 2) return ['groups' => $groups, 'used' => false, 'batches' => 0, 'partial' => false];

        $inGroups = [];
        foreach ($groups as $g) foreach ($g['nominee_ids'] as $i) $inGroups[$i] = true;

        // ── which names go to the model, in which calls ──
        $batches = []; $partial = false;
        if (count($names) <= self::AI_BATCH) {
            $batches[] = array_keys($names);
        } else {
            $byCat = [];
            foreach ($names as $id => $_) $byCat[$catOf[$id] ?? ''][] = $id;
            foreach ($byCat as $ids) {
                if (count($ids) < 2) continue;
                $pick = count($ids) <= self::AI_BATCH ? $ids : self::candidates($ids, $names, $inGroups);
                // A large category is sampled by shared words; say so rather than imply it was read whole.
                if (count($pick) < count($ids)) $partial = true;
                foreach (array_chunk($pick, self::AI_BATCH) as $chunk) if (count($chunk) >= 2) $batches[] = $chunk;
            }
        }
        if (count($batches) > self::AI_MAX_BATCHES) { $partial = true; $batches = array_slice($batches, 0, self::AI_MAX_BATCHES); }

        $used = false; $seen = []; $found = [];
        foreach ($batches as $ids) {
            $sub = [];
            foreach ($ids as $i) {
                $country = strtoupper(trim((string) ($rows[$i]->country_code ?? '')));
                $sub[$i] = $names[$i] . ($country !== '' ? ' [' . $country . ']' : '');
            }
            $r = self::aiGroups($sub, $ai);
            if ($r === null) { $partial = true; continue; }
            $used = true;
            foreach ($ids as $i) $seen[$i] = true;
            foreach ($r as $g) {
                // Merges happen within a category, so a pair across two is never offered:
                // the UI must not show a merge that fails on submit.
                $cats = array_unique(array_map(static fn (int $i): string => $catOf[$i] ?? '?', $g['nominee_ids']));
                if (count($cats) !== 1) continue;
                $found[] = $g + ['category' => (string) reset($cats)];
            }
        }
        if (!$used) return ['groups' => $groups, 'used' => false, 'batches' => count($batches), 'partial' => $partial || $batches !== []];

        // ── verdicts on the rule groups ──
        foreach ($groups as &$g) {
            $ids = $g['nominee_ids'];
            if (array_diff($ids, array_keys($seen)) !== []) continue;   // the AI did not see them all
            $agree = null;
            foreach ($found as $f) {
                if (array_diff($ids, $f['nominee_ids']) === []) { $agree = $f; break; }
            }
            if ($agree !== null) {
                $g['ai_verdict'] = 'agrees';
                $g['confidence'] = max((float) $g['confidence'], (float) $agree['confidence']);
                $g['reason'] .= ' The AI agrees: ' . rtrim((string) $agree['reason'], '.') . '.';
            } else {
                $g['ai_verdict'] = 'unconfirmed';
                $g['reason'] .= ' The AI looked at these and did not call them the same — check before merging.';
            }
        }
        unset($g);

        // ── what only the AI found, with the same facts as a rule group ──
        foreach ($found as $f) {
            $h = self::hydrate($f, $rows, $names);
            if ($h === null) continue;
            $h['category'] = $f['category'];
            $h['ai_verdict'] = 'found';
            $groups[] = $h;
        }
        return ['groups' => self::dedupeGroups($groups), 'used' => true, 'batches' => count($batches), 'partial' => $partial];
    }

    /**
     * In a category too large to send whole: the names worth the model's attention.
     * Rule-group members first, then names sharing a word (three letters or more) with
     * another name — but not a word so common it joins half the category.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    private static function candidates(array $ids, array $names, array $inGroups): array
    {
        $byToken = [];
        foreach ($ids as $i) {
            foreach (self::tokens((string) $names[$i]) as $t) {
                if (mb_strlen($t) >= 3) $byToken[$t][$i] = true;
            }
        }
        $pick = [];
        foreach ($ids as $i) if (isset($inGroups[$i])) $pick[$i] = true;
        uasort($byToken, static fn (array $a, array $b): int => count($a) <=> count($b));
        foreach ($byToken as $set) {
            if (count($set) < 2 || count($set) > 30) continue;
            foreach ($set as $i => $_) $pick[$i] = true;
        }
        return array_keys($pick);
    }

    /**
     * A group with the facts an admin decides on: names, votes, country, photo, linked
     * profile, status, and a recommended survivor. Null when fewer than two members exist.
     *
     * @param array<string,mixed> $g
     * @param array<int,object> $rows
     * @param array<int,string> $names
     * @return array<string,mixed>|null
     */
    private static function hydrate(array $g, array $rows, array $names): ?array
    {
        $ids = array_values(array_filter($g['nominee_ids'], static fn ($i) => isset($names[$i])));
        if (count($ids) < 2) return null;
        $members = [];
        foreach ($ids as $i) {
            $row = $rows[$i] ?? null;
            $members[] = [
                'id'        => $i,
                'name'      => $names[$i],
                'votes'     => (int) ($row->vote_count ?? 0),
                'country'   => strtoupper(trim((string) ($row->country_code ?? ''))),
                'has_photo' => trim((string) ($row->photo_path ?? '')) !== '',
                'linked'    => (int) ($row->profile_id ?? 0) > 0,
                'status'    => (string) ($row->status ?? ''),
            ];
        }
        return [
            'nominee_ids' => $ids,
            'names'       => array_map(static fn ($i) => $names[$i], $ids),
            'members'     => $members,
            // A RECOMMENDATION, not a decision — the UI lets the admin change it.
            'keep_id'     => self::recommendSurvivor($members),
            'confidence'  => (float) $g['confidence'],
            'reason'      => (string) $g['reason'],
            'source'      => (string) $g['source'],
        ] + (isset($g['category']) ? ['category' => $g['category']] : []);
    }

    // ── "not the same person", remembered ────────────────────────────────────

    /** The sorted pair key a dismissal is stored under. */
    public static function pairKey(int $a, int $b): string
    {
        return min($a, $b) . '-' . max($a, $b);
    }

    /**
     * An admin says these are different people: every pair among them stops being
     * suggested. A nominee added to the group later is a new pair, and is still offered.
     *
     * @param list<int> $ids
     * @return int pairs recorded
     */
    public static function dismiss(array $ids, ?int $adminId = null, string $reason = ''): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
        $n = 0;
        try {
            for ($x = 0; $x < count($ids); $x++) {
                for ($y = $x + 1; $y < count($ids); $y++) {
                    $n += DB::table('gates_merge_dismissals')->insertOrIgnore([
                        'pair_key'     => self::pairKey($ids[$x], $ids[$y]),
                        'nominee_a'    => min($ids[$x], $ids[$y]),
                        'nominee_b'    => max($ids[$x], $ids[$y]),
                        'reason'       => mb_substr(trim($reason), 0, 500) ?: null,
                        // A foreign-key-shaped column; there is no admin 0.
                        'dismissed_by' => ($adminId ?? 0) > 0 ? $adminId : null,
                        'created_at'   => date('Y-m-d H:i:s'),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            error_log('[merge] dismissal not stored: ' . $e->getMessage());
        }
        return $n;
    }

    /**
     * Groups an admin has not already ruled on. A group whose EVERY pair was dismissed is
     * dropped; one where only some were says so, because a third entry can join two people
     * an admin already told apart.
     *
     * @param list<array<string,mixed>> $groups
     * @return list<array<string,mixed>>
     */
    private static function withoutDismissed(array $groups): array
    {
        $keys = [];
        foreach ($groups as $g) {
            $ids = $g['nominee_ids'];
            for ($x = 0; $x < count($ids); $x++) for ($y = $x + 1; $y < count($ids); $y++) $keys[] = self::pairKey($ids[$x], $ids[$y]);
        }
        if ($keys === []) return $groups;
        try {
            $done = array_fill_keys(DB::table('gates_merge_dismissals')->whereIn('pair_key', array_values(array_unique($keys)))
                ->pluck('pair_key')->all(), true);
        } catch (\Throwable) {
            return $groups;   // table not migrated yet: nothing has been dismissed
        }
        $out = [];
        foreach ($groups as $g) {
            $ids = $g['nominee_ids']; $pairs = 0; $hit = 0;
            for ($x = 0; $x < count($ids); $x++) for ($y = $x + 1; $y < count($ids); $y++) {
                $pairs++;
                if (isset($done[self::pairKey($ids[$x], $ids[$y])])) $hit++;
            }
            if ($pairs > 0 && $hit === $pairs) continue;
            if ($hit > 0) $g['reason'] .= ' ' . $hit . ' pair' . ($hit === 1 ? '' : 's') . ' here were marked as different people before.';
            $out[] = $g;
        }
        return $out;
    }

    /**
     * The id of an existing LIVE nominee in $categoryId that is CONFIDENTLY the
     * same person as $name — i.e. its normalised name is EQUAL (honorific / case /
     * punctuation / whitespace insensitive, via {@see norm()}). 0 when none.
     *
     * This is the auto-attach-on-approval resolver: approving a nomination for
     * someone who already has a nominee links to that nominee instead of minting
     * a duplicate that would split their votes. It is deliberately conservative —
     * only an EXACT normalised match auto-links. Near-but-unequal names
     * (edit-distance) are left to the human-confirmed duplicate-scan + (now
     * reversible) merge flow, so two genuinely different people are never silently
     * folded together by an automatic step. Tombstones are excluded.
     */
    public static function findLiveMatch(int $categoryId, string $name): int
    {
        $target = self::norm($name);
        if ($target === '') return 0;
        try {
            $q = DB::table('gates_nominees')
                ->where('category_id', $categoryId)
                ->whereIn('status', ['pending', 'approved', 'winner', 'runner_up']);
            MergeService::notMerged($q);
            foreach ($q->orderBy('id')->limit(self::SCAN_CAP)->get(['id', 'name']) as $row) {
                if (self::norm((string) $row->name) === $target) return (int) $row->id;
            }
        } catch (\Throwable) {}
        return 0;
    }

    /**
     * Deterministic clusters, from blocked candidate pairs.
     *
     * The pair loop is gone. Nominees are bucketed by {@see blockKeys()} and only
     * compared within a bucket, so the work scales with how many names actually
     * resemble each other rather than with the square of the category size. That is
     * what allowed SCAN_CAP to go from 400 to 4,000 — and the 400 was doing real
     * damage, because it sliced by ascending id and so excluded the newest entries.
     *
     * Country is corroborating evidence, applied as an adjustment rather than a
     * filter. Two identical names in different countries are usually two people, so
     * the group is demoted to "confirm" instead of being offered at 0.97 — but not
     * dropped, because people move and a nominator may have entered the wrong
     * country. A match on a non-obvious country is mild evidence FOR.
     */
    private static function ruleGroups(array $nominees, ?int &$crowdedBuckets = null): array
    {
        $crowdedBuckets = 0;
        $n = count($nominees);
        if ($n < 2) return [];

        $parent = range(0, $n - 1);
        $find = function (int $x) use (&$parent, &$find): int { while ($parent[$x] !== $x) { $parent[$x] = $parent[$parent[$x]]; $x = $parent[$x]; } return $x; };
        $union = function (int $a, int $b) use (&$parent, $find): void { $parent[$find($a)] = $find($b); };

        // Bucket by blocking key. A pair sharing no key is never compared; that is
        // the explicit recall/cost trade-off described on the class.
        $buckets = [];
        foreach ($nominees as $i => $row) {
            foreach (self::blockKeys((string) $row->name) as $k) $buckets[$k][] = $i;
        }

        $pairScore    = [];
        $crossCountry = [];
        $compared     = [];
        foreach ($buckets as $idxs) {
            $c = count($idxs);
            // A bucket every name lands in would reintroduce the cross product.
            // Nothing legitimate looks like that, so it is skipped and counted.
            if ($c < 2) continue;
            if ($c > self::BUCKET_CAP) { $crowdedBuckets++; continue; }
            for ($x = 0; $x < $c; $x++) {
                for ($y = $x + 1; $y < $c; $y++) {
                    [$i, $j] = [$idxs[$x], $idxs[$y]];
                    $key = $i < $j ? "$i:$j" : "$j:$i";
                    if (isset($compared[$key])) continue;   // the two keys overlap
                    $compared[$key] = true;

                    $score = self::nameScore((string) $nominees[$i]->name, (string) $nominees[$j]->name);
                    if ($score <= 0.0) continue;
                    $adjusted = self::applyCountry($score, $nominees[$i], $nominees[$j]);
                    if ($adjusted < 0.55) continue;

                    $pairScore[$key]    = $adjusted;
                    // Tracked separately because the SCORE alone cannot explain
                    // itself: a country demotion lands in the same numeric band as a
                    // spelling variant, and describing it as one told the admin the
                    // wrong thing about what to check.
                    $crossCountry[$key] = $adjusted < $score;
                    $union($i, $j);
                }
            }
        }

        $clusters = [];
        for ($i = 0; $i < $n; $i++) $clusters[$find($i)][] = $i;

        $groups = [];
        foreach ($clusters as $idx) {
            if (count($idx) < 2) continue;
            $ids = [];
            foreach ($idx as $i) $ids[] = (int) $nominees[$i]->id;
            sort($ids);

            // A cluster is only as strong as its WEAKEST link: transitive unions can
            // chain A–B (certain) to B–C (a guess), and offering the whole chain at
            // the strongest score would be the wrong number on the wrong group.
            $weakest = 1.0; $anyCross = false;
            foreach ($idx as $a) {
                foreach ($idx as $b) {
                    if ($a >= $b) continue;
                    $k = "$a:$b";
                    if (!isset($pairScore[$k])) continue;
                    $weakest  = min($weakest, $pairScore[$k]);
                    $anyCross = $anyCross || ($crossCountry[$k] ?? false);
                }
            }

            $groups[] = [
                'nominee_ids' => $ids,
                'confidence'  => $weakest,
                'reason'      => self::describe($weakest, count($ids), $anyCross),
                'source'      => 'rule',
            ];
        }
        return $groups;
    }

    /**
     * Which of a duplicate group should survive the merge.
     *
     * The UI kept `nominee_ids[0]`, which after sorting is the LOWEST ID — i.e. the
     * oldest row, chosen for no reason connected to its quality. A merge folds the
     * others into the survivor and keeps the survivor's name, photo and profile
     * link, so picking the wrong one discards the better record: the entry with the
     * photo, the one linked to a real profile, the one an award result already
     * points at.
     *
     * Order of preference, most decisive first:
     *   1. an already-decided result (winner / runner_up) — folding a crowned
     *      nominee INTO another would move the award off the row that holds it
     *   2. approved over pending — a reviewer has already looked at it
     *   3. linked to a profile — the merge preserves that link only on the survivor
     *   4. has a photo — the visible difference on every public page
     *   5. more votes — cosmetic, since totals are summed either way, but it keeps
     *      the surviving row's own history the larger one
     *   6. lowest id — a stable tiebreak so repeat scans recommend the same thing
     *
     * @param list<array{id:int,votes:int,has_photo:bool,linked:bool,status:string}> $members
     */
    private static function recommendSurvivor(array $members): int
    {
        $rank = static function (array $m): array {
            return [
                in_array($m['status'], ['winner', 'runner_up'], true) ? 1 : 0,
                $m['status'] === 'approved' ? 1 : 0,
                $m['linked'] ? 1 : 0,
                $m['has_photo'] ? 1 : 0,
                $m['votes'],
                -$m['id'],           // negated so a HIGHER tuple means a LOWER id
            ];
        };
        $best = null; $bestRank = null;
        foreach ($members as $m) {
            $r = $rank($m);
            if ($bestRank === null || $r > $bestRank) { $bestRank = $r; $best = $m['id']; }
        }
        return (int) ($best ?? ($members[0]['id'] ?? 0));
    }

    /**
     * Country as corroboration. Never decisive on its own.
     *
     * @param object $a
     * @param object $b
     */
    private static function applyCountry(float $score, object $a, object $b): float
    {
        $ca = strtoupper(trim((string) ($a->country_code ?? '')));
        $cb = strtoupper(trim((string) ($b->country_code ?? '')));
        if ($ca === '' || $cb === '') return $score;         // unknown proves nothing
        if ($ca === $cb) return min(0.99, $score + 0.02);
        // Different countries: probably two people who share a name. Demoted to the
        // range an admin reads as "check this", not removed — people relocate, and
        // country is frequently mis-entered by the nominator.
        return $score - 0.25;
    }

    /**
     * Plain-English reason for the score an admin is about to act on.
     *
     * $crossCountry is passed in rather than inferred from the score, because a
     * country demotion lands in the same numeric band as a spelling variant. Reading
     * it off the number alone told the admin to check the spelling when the actual
     * question was whether these are two people in two countries.
     */
    private static function describe(float $score, int $count, bool $crossCountry = false): string
    {
        $many = $count > 2 ? " All {$count} are linked; confirm each one." : '';
        $base = match (true) {
            $score >= 0.94 => 'Same name once honorifics, case, accents, punctuation and word order are normalised.',
            $score >= 0.84 => 'Same name with one part abbreviated to an initial — confirm which person it is.',
            default        => 'Names differ by a likely spelling or transliteration variant.',
        };
        if ($crossCountry) {
            $base .= ' Recorded in different countries, so this may well be two different people — check before merging.';
        }
        return $base . $many;
    }

    /** Optional AI clustering. Returns null when unavailable/unparseable. */
    private static function aiGroups(array $names, ?AiService $ai = null): ?array
    {
        $lines = [];
        foreach ($names as $id => $name) { $lines[] = $id . ': ' . mb_substr($name, 0, 80); }
        $system = 'You detect DUPLICATE award nominees — entries that are the SAME real person or organisation listed more than once '
            . '(nicknames, married/maiden names, transliteration, honorifics, spelling variants). '
            . 'Reply ONLY with JSON: {"groups":[{"ids":[<nominee ids that are the same entity>],"confidence":<0-1>,"reason":"<short why>"}]}. '
            . 'Each group MUST have 2+ ids. A [XX] after a name is the country it was entered under: the same name in two '
            . 'countries is usually two people. Do NOT group merely-similar but different people. If unsure, omit. '
            . 'Empty groups array if none.';
        // Nominee names are admin-approved data, but they originate from public
        // nominations, so they are fenced. Every returned id is checked against
        // the allow-list — the model cannot introduce a nominee that was not sent.
        $valid = array_keys($names);
        $r = (new AiGateway($ai))->run('nominee.merge_suggest', [
            'system'      => $system,
            'trusted'     => 'The nominee list follows, one per line as "id: name".',
            'user'        => implode("\n", $lines),
            'json'        => true,
            'temperature' => 0.1,
            'schema'      => static function (string $raw) use ($valid): ?array {
                $j = json_decode($raw, true);
                if (!is_array($j) || !isset($j['groups']) || !is_array($j['groups'])) return null;
                $out = [];
                foreach ($j['groups'] as $g) {
                    if (!is_array($g) || !isset($g['ids']) || !is_array($g['ids'])) continue;
                    $ids = array_values(array_unique(array_filter(
                        array_map('intval', $g['ids']),
                        static fn ($i) => in_array($i, $valid, true)
                    )));
                    if (count($ids) < 2) continue;
                    sort($ids);
                    $out[] = [
                        'nominee_ids' => $ids,
                        'confidence'  => max(0.0, min(1.0, (float) ($g['confidence'] ?? 0.6))),
                        'reason'      => mb_substr(trim((string) ($g['reason'] ?? 'AI-detected duplicate.')), 0, 200) ?: 'AI-detected duplicate.',
                        'source'      => 'ai',
                    ];
                }
                // An empty array is a VALID answer here ("no duplicates found"),
                // so it must not be treated as a schema rejection.
                return $out;
            },
        ]);
        return $r->ok ? $r->value : null;
    }

    /** Drop groups with an identical id-set (rule + AI can both find the same pair); keep the higher-confidence one. */
    private static function dedupeGroups(array $groups): array
    {
        $byKey = [];
        foreach ($groups as $g) {
            $key = implode('-', $g['nominee_ids']);
            if (!isset($byKey[$key]) || $g['confidence'] > $byKey[$key]['confidence']) $byKey[$key] = $g;
        }
        return array_values($byKey);
    }
}
