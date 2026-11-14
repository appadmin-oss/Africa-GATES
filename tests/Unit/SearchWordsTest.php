<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\SearchWords;
use PHPUnit\Framework\TestCase;

/** How a query is read (SearchWords): folded, singular, by word, ranked, and forgiven one slip. */
final class SearchWordsTest extends TestCase
{
    public function test_accents_and_tone_marks_fold_so_a_name_typed_without_them_matches(): void
    {
        $this->assertSame('ola adebayo', SearchWords::fold('Ọlá Adébáyọ̀'));
        $this->assertTrue(SearchWords::matches('ola', 'Ọlá Adébáyọ̀'));
        $this->assertTrue(SearchWords::matches('Ṣẹ̀gun', 'Segun Ogunleye'), 'and the other way round');
    }

    public function test_a_plural_finds_the_singular_and_short_or_double_s_words_are_left_alone(): void
    {
        $this->assertTrue(SearchWords::matches('teachers', 'Teacher of the Year'));
        $this->assertTrue(SearchWords::matches('categories', 'Category winners'));
        $this->assertSame('class', SearchWords::stem('class'));
        $this->assertSame('bus', SearchWords::stem('bus'));
    }

    public function test_every_word_must_appear_somewhere(): void
    {
        $this->assertTrue(SearchWords::matches('tolu lagos', 'Tolu Bakare', 'Music', 'Lagos'));
        $this->assertFalse(SearchWords::matches('tolu kano', 'Tolu Bakare', 'Music', 'Lagos'));
    }

    public function test_the_best_name_match_scores_highest(): void
    {
        $q = 'Ada';
        $this->assertGreaterThan(SearchWords::score('Adaeze Nnaji', $q), SearchWords::score('Ada', $q));
        $this->assertGreaterThan(SearchWords::score('Nkechi Adaobi', $q), SearchWords::score('Adaeze Nnaji', $q), 'starts the name, over starts a later word');
        $this->assertGreaterThan(SearchWords::score('Chiadaobi', $q), SearchWords::score('Nkechi Adaobi', $q), 'starts a word, over buried inside one');
    }

    public function test_a_slip_is_corrected_only_to_a_word_the_site_holds(): void
    {
        $vocab = ['chiamaka' => 3, 'adaeze' => 2, 'okonkwo' => 2, 'teacher' => 5];
        $this->assertSame('chiamaka', SearchWords::correct('Chiamka', $vocab));
        $this->assertSame('adaeze okonkwo', SearchWords::correct('adaze okonkow', $vocab));
        $this->assertNull(SearchWords::correct('zzqxv', $vocab), 'nothing near: no invented correction');
        $this->assertNull(SearchWords::correct('chia', $vocab), 'somebody still typing a known word is not corrected');
        $this->assertNull(SearchWords::correct('ab', $vocab), 'two letters are not corrected at all');
    }
}
