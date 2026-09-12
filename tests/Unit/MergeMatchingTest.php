<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Services\MergeSuggestionService;
use Illuminate\Database\Capsule\Manager as DB;
use Tests\TestCase;

/**
 * Duplicate detection, measured against how African names are actually written.
 *
 * The old matcher lowercased a name, dropped a leading honorific, folded diacritics
 * and concatenated the rest in the order it was typed. Measured against twelve
 * realistic duplicate patterns it missed four, and the four were not scattered:
 *
 *   Chinwe Okafor  /  Okafor Chinwe     reversed order  — missed
 *   Thabo Mbeki    /  Mbeki, Thabo      comma-inverted  — missed
 *   Kwame Nkrumah  /  K. Nkrumah        initial         — missed
 *   Sipho Ndlovu   /  S Ndlovu          bare initial    — missed
 *
 * One root cause: concatenating in input order makes token order and token
 * abbreviation destroy the match. That is not an edge case here — family-name-first
 * is the norm across much of the continent, forms get filled both ways, and
 * "surname, given name" is how official records are written. Two entries for one
 * person under swapped order split their votes, which is the entire thing this
 * feature exists to prevent.
 *
 * Both halves are tested. A matcher that groups everything would pass every
 * recall case here and be useless, so each miss-class has a paired non-match that
 * must stay separate.
 */
class MergeMatchingTest extends TestCase
{
    private int $seq = 0;

    private function nominee(string $name, int $cat = 10, array $extra = []): int
    {
        $id = ++$this->seq + 1000;
        DB::table('gates_nominees')->insert($extra + [
            'id' => $id, 'category_id' => $cat, 'name' => $name,
            'status' => 'approved', 'vote_count' => 0,
        ]);
        return $id;
    }

    /** Are these two names offered as a duplicate group? */
    private function scan(string $a, string $b, array $extraA = [], array $extraB = []): array
    {
        $cat = 10 + (++$this->seq * 100);
        $this->nominee($a, $cat, $extraA);
        $this->nominee($b, $cat, $extraB);
        return MergeSuggestionService::forCategory($cat)['groups'];
    }

    private function assertSame_(string $a, string $b, string $why): float
    {
        $g = $this->scan($a, $b);
        $this->assertCount(1, $g, "{$why}: “{$a}” and “{$b}” must be offered as duplicates");
        return (float) $g[0]['confidence'];
    }

    private function assertDifferent(string $a, string $b, string $why): void
    {
        $this->assertSame([], $this->scan($a, $b),
            "{$why}: “{$a}” and “{$b}” must NOT be offered as duplicates");
    }

    // ── The four patterns that were missed ───────────────────────────────────

    public function test_a_reversed_name_order_is_the_same_person(): void
    {
        // The most consequential miss. Family-name-first is standard in much of
        // Africa and forms are filled both ways, so this is a routine duplicate,
        // not an exotic one — and it split votes silently.
        $c = $this->assertSame_('Chinwe Okafor', 'Okafor Chinwe', 'reversed order');
        $this->assertGreaterThanOrEqual(0.9, $c, 'every token matches; only the order differs');
    }

    public function test_a_comma_inverted_name_is_the_same_person(): void
    {
        // How official records write names. The old normaliser deleted the comma
        // rather than splitting on it, so "mbekithabo" never met "thabombeki".
        $this->assertSame_('Thabo Mbeki', 'Mbeki, Thabo', 'comma-inverted');
    }

    public function test_an_abbreviated_given_name_matches_the_full_one(): void
    {
        $c = $this->assertSame_('Kwame Nkrumah', 'K. Nkrumah', 'initial');
        $this->assertLessThan(0.94, $c,
            'an initial is genuinely ambiguous — Kwame or Kofi — so it is a suggestion '
            . 'to confirm, not a near-certainty');
    }

    public function test_a_bare_initial_with_no_full_stop_also_matches(): void
    {
        $this->assertSame_('Sipho Ndlovu', 'S Ndlovu', 'bare initial');
    }

    // ── What already worked, and must keep working ───────────────────────────

    public function test_honorifics_are_ignored_wherever_they_appear(): void
    {
        $this->assertSame_('Jane Doe', 'Dr. Jane Doe', 'leading honorific');
        // Anywhere, not just at the front — the old regex was anchored to the start.
        $this->assertSame_('Amina Bello', 'Amina Bello, Barrister', 'trailing honorific');
        $this->assertSame_('Ngozi Eze', 'Prof Ngozi Eze', 'title');
    }

    public function test_diacritics_fold_to_their_base_letter(): void
    {
        $this->assertSame_('José Silva', 'Jose Silva', 'accents');
        $this->assertSame_('Wangari Maathai', 'Wangarĩ Maathai', 'African diacritic');
    }

    public function test_hyphens_and_extra_whitespace_do_not_matter(): void
    {
        $this->assertSame_('Ngozi Okonjo-Iweala', 'Ngozi Okonjo Iweala', 'hyphen');
        $this->assertSame_('Fatou Diop', 'Fatou  Diop', 'double space');
    }

    public function test_a_transliteration_variant_is_the_same_person(): void
    {
        $c = $this->assertSame_('Ali Musa', 'Ali Musah', 'transliteration');
        $this->assertLessThan(0.94, $c, 'a spelling variant is advisory');
    }

    public function test_a_short_token_off_by_one_letter_matches_when_the_rest_is_exact(): void
    {
        // Three letters is too ambiguous to fuzzy-match alone, but the rest of the
        // name is the corroboration: with "Jane" matching exactly, doe→doo is a typo.
        $this->assertSame_('Jane Doe', 'Jane Doo', 'typo beside an exact token');
    }

    // ── The other half: what must stay separate ──────────────────────────────

    public function test_two_different_people_are_not_grouped(): void
    {
        $this->assertDifferent('Jane Doe', 'John Doe', 'different given names');
        $this->assertDifferent('Ada Obi', 'Chidi Okeke', 'unrelated names');
        $this->assertDifferent('Jane Doe', 'Jane Roe', 'different surnames');
    }

    public function test_the_short_token_rescue_needs_an_exact_token_beside_it(): void
    {
        // ali→abu is two edits of three characters, so it fails even with "Musa"
        // matching. The rescue allows ONE edit, once, and only with corroboration —
        // otherwise it would be a licence to group any two short names.
        $this->assertDifferent('Ali Musa', 'Abu Musa', 'two edits in a three-letter token');
    }

    public function test_a_similar_looking_pair_of_real_words_is_not_a_duplicate(): void
    {
        // Same length, edit-distance 2. A distance threshold loose enough to catch
        // this would group half the register.
        $this->assertDifferent('Sunday', 'Monday', 'edit-distance alone is not evidence');
    }

    public function test_a_numeric_token_is_never_treated_as_an_abbreviation(): void
    {
        // Found on a 20,000-nominee dataset whose names were "Nominee <n> Surname":
        // "1" read as an abbreviation of "11", and the scan produced 306 confident
        // duplicate groups out of nothing. Any name carrying a number — a cohort
        // year, an edition, a team number — reproduces it.
        $this->assertDifferent('Nominee 1 Surname', 'Nominee 11 Surname', 'digit as initial');
        $this->assertDifferent('Cohort 2024 Award', 'Cohort 2025 Award', 'digit as typo');
    }

    public function test_a_single_shared_surname_is_not_enough(): void
    {
        // "Ndlovu" and "Ndlovu" as whole names ARE identical, so they group — but a
        // near-miss on one short mononym is not evidence of anything.
        $this->assertDifferent('Musa', 'Musah', 'short mononyms have no corroboration');
        $this->assertCount(1, $this->scan('Ndlovu', 'ndlovu'), 'an identical mononym does group');
    }

    public function test_a_long_mononym_off_by_one_letter_does_match(): void
    {
        // One letter in eight is a typo; one letter in four is a different name.
        $this->assertSame_('Chidinma', 'Chidimma', 'long mononym typo');
    }

    // ── Country as corroboration ─────────────────────────────────────────────

    public function test_the_same_name_in_two_countries_is_demoted_not_offered_as_certain(): void
    {
        // Usually two people who share a name. Demoted so an admin reads it as
        // "check this" — not dropped, because people relocate and nominators
        // mis-enter the country.
        $same = $this->assertSame_('Kofi Mensah', 'Kofi Mensah', 'same country unknown');

        $g = $this->scan('Kofi Mensah', 'Kofi Mensah',
            ['country_code' => 'GH'], ['country_code' => 'NG']);
        $this->assertCount(1, $g, 'still surfaced, because country is frequently wrong');
        $this->assertLessThan($same, (float) $g[0]['confidence'],
            'a country mismatch must lower the confidence');
        $this->assertStringContainsString('different countries', $g[0]['reason']);
    }

    public function test_a_matching_country_reads_as_mild_support(): void
    {
        $g = $this->scan('Kofi Mensah', 'Kofi Mensah',
            ['country_code' => 'GH'], ['country_code' => 'GH']);
        $this->assertCount(1, $g);
        $this->assertGreaterThanOrEqual(0.97, (float) $g[0]['confidence']);
    }

    // ── Which record survives ────────────────────────────────────────────────

    public function test_the_recommended_survivor_is_chosen_on_evidence(): void
    {
        // The UI kept nominee_ids[0] — after sorting, the LOWEST ID, i.e. the oldest
        // row, picked for no reason connected to its quality. A merge keeps the
        // survivor's name, photo and profile link, so that discarded the better
        // record whenever the good entry was the newer one.
        $cat = 9100;
        $old = $this->nominee('Ada Obi', $cat);                                    // bare
        $new = $this->nominee('Ada Obi', $cat, ['photo_path' => '/x.webp', 'profile_id' => 5]);

        $g = MergeSuggestionService::forCategory($cat)['groups'];
        $this->assertCount(1, $g);
        $this->assertSame($new, $g[0]['keep_id'],
            'the linked record with a photo must be recommended over the older bare one');
        $this->assertNotSame($old, $g[0]['keep_id']);
    }

    public function test_a_crowned_nominee_is_never_folded_into_another(): void
    {
        // Folding a winner INTO another row would move the award off the record that
        // holds it — the one outcome here that is not merely untidy.
        $cat = 9200;
        $plain  = $this->nominee('Ada Obi', $cat, ['photo_path' => '/x.webp', 'profile_id' => 7, 'vote_count' => 999]);
        $winner = $this->nominee('Ada Obi', $cat, ['status' => 'winner']);

        $g = MergeSuggestionService::forCategory($cat)['groups'];
        $this->assertCount(1, $g);
        $this->assertSame($winner, $g[0]['keep_id'],
            'a decided result outranks a photo, a profile link and a bigger vote count');
        $this->assertNotSame($plain, $g[0]['keep_id']);
    }

    public function test_the_recommendation_is_stable_between_scans(): void
    {
        // Two scans must not recommend different survivors, or an admin who reloads
        // sees the choice change under them.
        $cat = 9300;
        $this->nominee('Ada Obi', $cat);
        $this->nominee('Ada Obi', $cat);
        $this->nominee('Ada Obi', $cat);

        $a = MergeSuggestionService::forCategory($cat)['groups'][0]['keep_id'];
        $b = MergeSuggestionService::forCategory($cat)['groups'][0]['keep_id'];
        $this->assertSame($a, $b);
    }

    public function test_each_group_carries_the_facts_needed_to_choose(): void
    {
        // A radio list of names alone cannot be chosen between. The votes, country,
        // photo and profile link are what make the decision answerable.
        $cat = 9400;
        $this->nominee('Ada Obi', $cat, ['country_code' => 'NG', 'vote_count' => 12, 'photo_path' => '/a.webp']);
        $this->nominee('Obi Ada', $cat, ['country_code' => 'NG', 'vote_count' => 3]);

        $g = MergeSuggestionService::forCategory($cat)['groups'][0];
        $this->assertCount(2, $g['members']);
        foreach ($g['members'] as $m) {
            foreach (['id', 'name', 'votes', 'country', 'has_photo', 'linked', 'status'] as $k) {
                $this->assertArrayHasKey($k, $m);
            }
        }
        $votes = array_column($g['members'], 'votes');
        $this->assertContains(12, $votes);
        $this->assertContains(3, $votes);
    }

    // ── The scan's own honesty ───────────────────────────────────────────────

    public function test_a_partial_scan_says_how_much_it_skipped(): void
    {
        // "capped: true" told an admin nothing actionable, and the previous slice
        // took the first 400 rows by ascending id — so a category over the cap never
        // scanned its NEWEST entries, which are exactly the likely duplicates.
        $r = MergeSuggestionService::forCategory(10);

        $this->assertArrayHasKey('skipped', $r, 'how many, not just whether');
        $this->assertArrayHasKey('crowded', $r, 'and how many name groups were too generic to compare');
        $this->assertSame(0, $r['skipped'], 'a small category is scanned in full');
        $this->assertFalse($r['capped']);
    }

    public function test_the_api_response_is_not_a_bulk_name_export(): void
    {
        // forCycle() carries a name-for-every-nominee map so the AI pass can run
        // once for the whole cycle instead of once per category. That map must not
        // reach the browser: it would turn a duplicate report into a dump of the
        // entire cycle's register.
        $keys = array_keys(MergeSuggestionService::forCategory(10));
        $this->assertContains('names_by_id', $keys, 'used internally by forCycle()');

        $controller = file_get_contents(dirname(__DIR__, 2) . '/src/Admin/Controllers/NomineesController.php');
        $this->assertStringContainsString("unset(\$r['names_by_id'])", (string) $controller,
            'the duplicate-scan endpoint must strip it before responding');
    }

    public function test_groups_are_ordered_most_confident_first(): void
    {
        $cat = 9500;
        $this->nominee('Ada Obi', $cat);
        $this->nominee('Ada Obi', $cat);          // identical → strongest
        $this->nominee('Chidi Nwosu', $cat);
        $this->nominee('C Nwosu', $cat);          // initial → weaker

        $g = MergeSuggestionService::forCategory($cat)['groups'];
        $this->assertCount(2, $g);
        $this->assertGreaterThanOrEqual((float) $g[1]['confidence'], (float) $g[0]['confidence'],
            'an admin works down the list, so the clearest duplicates must be at the top');
    }

    public function test_a_chain_is_scored_by_its_weakest_link(): void
    {
        // Union-find joins A–B and B–C into one group. Offering the chain at the
        // strongest pair's confidence would put a certain-looking number on a group
        // that is only held together by a guess.
        $cat = 9600;
        $this->nominee('Chidi Nwosu', $cat);
        $this->nominee('chidi nwosu', $cat);      // identical to the first
        $this->nominee('C Nwosu', $cat);          // only an initial match

        $g = MergeSuggestionService::forCategory($cat)['groups'];
        $this->assertCount(1, $g, 'all three chain into one group');
        $this->assertCount(3, $g[0]['nominee_ids']);
        $this->assertLessThan(0.94, (float) $g[0]['confidence'],
            'the weakest link governs, not the strongest');
        $this->assertStringContainsString('All 3', $g[0]['reason']);
    }

    public function test_nominees_are_never_grouped_across_categories(): void
    {
        // A merge is within a category — MergeService cannot do otherwise — so
        // offering a cross-category group would be offering a merge that fails.
        $this->nominee('Ada Obi', 8100);
        $this->nominee('Ada Obi', 8200);

        $this->assertSame([], MergeSuggestionService::forCategory(8100)['groups']);
        $this->assertSame([], MergeSuggestionService::forCategory(8200)['groups']);
    }

    public function test_a_tombstoned_nominee_is_never_suggested_again(): void
    {
        $cat = 9700;
        $keep = $this->nominee('Ada Obi', $cat);
        $this->nominee('Ada Obi', $cat, ['merged_into' => $keep]);

        $this->assertSame([], MergeSuggestionService::forCategory($cat)['groups'],
            'a row already merged away must not be offered for merging');
    }
}
