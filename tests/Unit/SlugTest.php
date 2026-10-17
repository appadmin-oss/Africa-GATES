<?php
declare(strict_types=1);

namespace Tests\Unit;

use AfricaGates\Support\Slug;
use Tests\TestCase;

/**
 * URL slugs for names, on a platform built for the whole continent.
 *
 * Five places built slugs with the same expression:
 *
 *     preg_replace('/[^a-z0-9]+/i', '-', $name)
 *
 * which DELETES every accented letter rather than transliterating it. Seen on a real
 * render, printed on a nominee's own flier:
 *
 *     Ọlásùnkànmí Adébáyọ̀   ->   l-s-nk-nm-ad-b-y
 *
 * Fourteen of twenty letters gone. It never broke loudly, because the numeric id leads
 * the path segment and the route only needs that — which is exactly why it survived. The
 * failure is a link that looks like corruption in every place a nominee shares it, and on
 * a flier it is the thing people are asked to type.
 */
class SlugTest extends TestCase
{
    public function test_yoruba_tone_marks_and_subdots_fold(): void
    {
        // The case seen in production data. Every one of these letters was previously
        // deleted rather than folded.
        $this->assertSame('olasunkanmi-adebayo', Slug::make('Ọlásùnkànmí Adébáyọ̀'));
        $this->assertSame('segun-odewale', Slug::make('Ṣẹ́gun Ọdẹ́wálé'));
    }

    public function test_letters_that_are_their_own_base_character_are_mapped(): void
    {
        // Normalizer decomposes a letter into base + mark, so dropping marks handles
        // accents. It does NOT handle a letter that IS its own base: `ɔ` does not
        // decompose to `o` because it is a distinct letter, not an o with something on
        // it. Akan, Ewe and Hausa are built from those, so they need an explicit map.
        $this->assertSame('odo-nyankopon', Slug::make('Ɔdɔ Nyankopɔn'));
        $this->assertSame('balarabe-danjuma-kano', Slug::make('Ɓalarabe Ɗanjuma Ƙano'));
        $this->assertSame('nwae', Slug::make('Ŋwae'));
    }

    public function test_the_wider_latin_orthographies_this_platform_serves(): void
    {
        $this->assertSame('wangari-maathai', Slug::make('Wangarĩ Maathai'));
        $this->assertSame('aissatou-diane-cote', Slug::make('Aïssatou Diané Coté'));
        $this->assertSame('joao-conceicao', Slug::make('João Conceição'));
        $this->assertSame('ngozi-okonjo-iweala', Slug::make('Ngozi Okonjo-Iweala'));
    }

    public function test_a_name_that_folds_to_nothing_yields_an_empty_slug_not_a_fragment(): void
    {
        // And the caller must cope, which is what idSegment() is for.
        $this->assertSame('', Slug::make('   '));
        $this->assertSame('', Slug::make('!!! ???'));
        $this->assertSame('48', Slug::idSegment(48, '   '), 'the id alone still resolves');
    }

    public function test_no_slug_begins_or_ends_with_a_separator(): void
    {
        foreach (['  Ada Obi  ', '-Ada-', '…Ada Obi…', 'Ada  Obi'] as $in) {
            $s = Slug::make($in);
            $this->assertStringStartsNotWith('-', $s, $in);
            $this->assertStringEndsNotWith('-', $s, $in);
            $this->assertStringNotContainsString('--', $s, $in);
        }
    }

    public function test_truncation_lands_on_a_word_boundary(): void
    {
        // A slug ending mid-word reads as damage. Cutting at the last hyphen costs a
        // word and keeps it legible.
        $s = Slug::make('Adaeze Chukwuemeka Nwankwo Obiageli Chimamanda Ngozi Adichie', 30);

        $this->assertLessThanOrEqual(30, strlen($s));
        $this->assertStringEndsNotWith('-', $s);
        // No partial word at the end.
        $parts = explode('-', $s);
        $this->assertContains(end($parts), ['adaeze', 'chukwuemeka', 'nwankwo', 'obiageli', 'chimamanda']);
    }

    public function test_the_id_segment_matches_what_the_routes_require(): void
    {
        // Every nominee route pattern is `{slug:[0-9]+[^/]*}` — leading digits, then
        // anything. So the id must come first and the name half must be optional.
        $seg = Slug::idSegment(48, 'Ọlásùnkànmí Adébáyọ̀');

        $this->assertSame('48-olasunkanmi-adebayo', $seg);
        $this->assertMatchesRegularExpression('~^[0-9]+[^/]*$~', $seg);
        $this->assertMatchesRegularExpression('~^[0-9]+[^/]*$~', Slug::idSegment(7, ''));
    }

    public function test_it_is_the_only_slug_builder_left(): void
    {
        // The regression that matters. Five copies of the deleting expression existed;
        // one more would silently reintroduce mangled links on whichever surface added
        // it, and nothing would fail.
        $root = dirname(__DIR__, 2);
        $offenders = [];
        foreach ([$root . '/src'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!$f->isFile() || $f->getExtension() !== 'php') continue;
                $rel = str_replace($root . '/', '', $f->getPathname());
                // Slug itself documents the old expression in prose.
                if ($rel === 'src/Support/Slug.php') continue;
                $raw = (string) file_get_contents($f->getPathname());
                $body = (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $raw);
                // The specific shape that deletes accented letters: a negated ASCII class
                // replaced with a hyphen. MergeSuggestionService's `/u` variant replaces
                // with a SPACE for tokenising and folds first, so it is not this bug.
                // BOTH spellings. The first sweep matched only `[^a-z0-9]+/i` and missed
                // FlierController's `[^A-Za-z0-9]+`, which was still producing
                // `vote-l-s-nk-nm-ad-b-y.png` — a guard with a hole exactly where a copy
                // actually was.
                if (preg_match("~preg_replace\(\s*'/\[\^(a-z0-9|A-Za-z0-9|a-zA-Z0-9)\]\+/i?'\s*,\s*'-'~", $body)) {
                    $offenders[] = $rel;
                }
            }
        }
        sort($offenders);
        $this->assertSame([], $offenders,
            'use Slug::make()/idSegment() — this expression deletes accented letters '
            . 'instead of folding them, which mangles most African names');
    }

    public function test_folding_agrees_with_the_duplicate_matcher_on_characters(): void
    {
        // Slug and MergeSuggestionService both fold diacritics, for different purposes,
        // and if they disagreed about WHICH LETTERS survive then a name could match a
        // duplicate under one spelling and slug under another.
        //
        // They deliberately disagree about ORDER: the matcher sorts tokens so a reversed
        // name matches (see MergeMatchingTest), and a URL obviously must not be
        // alphabetised. So the invariant is the character multiset, not the string —
        // asserting equality would have been asserting that one of the two is wrong.
        $ref = new \ReflectionMethod(\AfricaGates\Services\MergeSuggestionService::class, 'norm');
        $ref->setAccessible(true);

        $sorted = static function (string $s): string {
            $c = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            sort($c);
            return implode('', $c);
        };

        foreach (['José Adébáyò', 'Wangarĩ Maathai', 'Aïssatou Diané', 'Ṣẹ́gun Ọdẹ́wálé'] as $name) {
            $this->assertSame(
                $sorted((string) $ref->invoke(null, $name)),
                $sorted(str_replace('-', '', Slug::make($name))),
                "the two folders keep different letters for “{$name}”"
            );
        }
    }
}
