<?php
declare(strict_types=1);
namespace Tests\Unit;

use AfricaGates\Support\Name;
use PHPUnit\Framework\TestCase;

/**
 * Making a typed-in name look like a name.
 *
 * ══════════════════════════════════════════════════════════════════════════════
 * THE HALF THAT MATTERS IS WHAT IT LEAVES ALONE
 * ══════════════════════════════════════════════════════════════════════════════
 *
 * Tidying ADA OKONKWO into Ada Okonkwo is the easy half and every naive
 * implementation gets it right. The same naive implementation turns O'BRIEN into
 * O'brien, MCDONALD into Mcdonald and van der Berg into Van Der Berg — and a
 * name is the one field on a ballot that belongs to a person. Getting it wrong
 * in a way they did not ask for is worse than leaving their capitals alone.
 *
 * So the cases below are weighted towards restraint: mixed case survives,
 * particles stay down, generational suffixes stay up, and anything the rule is
 * unsure about is returned untouched.
 */
final class NameNormalisationTest extends TestCase
{
    // ── the easy half ────────────────────────────────────────────────────────

    public function test_a_shouting_keyboard_is_quietened(): void
    {
        $this->assertSame('Ada Okonkwo', Name::title('ADA OKONKWO'));
        $this->assertSame('Okun Alimosho', Name::title('OKUN ALIMOSHO'));
    }

    public function test_a_lazy_keyboard_is_raised(): void
    {
        $this->assertSame('Mr Tunde Adeyemi', Name::title('mr tunde adeyemi'));
    }

    public function test_a_name_that_is_already_right_is_unchanged(): void
    {
        // Called on every write, including the ones that need nothing.
        $this->assertSame('Ada Okonkwo', Name::title('Ada Okonkwo'));
    }

    public function test_stray_whitespace_is_collapsed(): void
    {
        // Pasted out of Word and WhatsApp, non-breaking spaces and all.
        $this->assertSame('Ada Okonkwo', Name::title("  ADA \u{00A0}  OKONKWO  "));
    }

    // ── the half that matters ────────────────────────────────────────────────

    public function test_deliberate_mixed_case_is_never_touched(): void
    {
        // A word that already carries both cases is a decision, not an accident.
        foreach (['deWayne', 'NneKa', 'MacIntyre', 'DeAndre', 'iLeke'] as $n) {
            $this->assertSame($n, Name::title($n), $n . ' is how somebody writes their own name');
        }
    }

    public function test_apostrophes_are_word_boundaries(): void
    {
        $this->assertSame("O'Brien", Name::title("O'BRIEN"));
        $this->assertSame("O'Brien", Name::title("o'brien"));
        $this->assertSame("D'Angelo Nwosu", Name::title("D'ANGELO NWOSU"));
        $this->assertSame("Sean O'Casey", Name::title("SEAN O'CASEY"));
    }

    public function test_hyphens_are_word_boundaries(): void
    {
        $this->assertSame('Ama-Serwaa Mensah', Name::title('AMA-SERWAA MENSAH'));
        $this->assertSame('Nnamdi-Okeke', Name::title('nnamdi-okeke'));
    }

    public function test_mc_is_handled_and_mac_deliberately_is_not(): void
    {
        $this->assertSame('McDonald', Name::title('MCDONALD'));
        $this->assertSame('McDonald', Name::title('mcdonald'));
        // Macaulay and Macharia are far commoner in this platform's audience than
        // MacArthur, and "MacAulay" would be a change nobody asked for.
        $this->assertSame('Macaulay', Name::title('MACAULAY'));
        $this->assertSame('Macharia', Name::title('macharia'));
    }

    public function test_particles_stay_lowercase_inside_a_name(): void
    {
        $this->assertSame('Vincent van der Berg', Name::title('VINCENT VAN DER BERG'));
        $this->assertSame('Ahmed bin al Hassan', Name::title('ahmed bin al hassan'));
        $this->assertSame('Voices of Enugu', Name::title('VOICES OF ENUGU'));
    }

    public function test_a_particle_that_is_somebodys_whole_surname_is_capitalised(): void
    {
        // The first and last words are anchors: a person surnamed "De" exists,
        // and lowercasing half of a two-word name would be absurd.
        $this->assertSame('Kwame Le', Name::title('KWAME LE'));
        $this->assertSame('De Souza', Name::title('DE SOUZA'));
    }

    public function test_generational_suffixes_stay_upper(): void
    {
        $this->assertSame('Ade Adeyemi III', Name::title('ADE ADEYEMI III'));
        $this->assertSame('Ade Adeyemi III', Name::title('ade adeyemi iii'));
    }

    public function test_initials_survive(): void
    {
        $this->assertSame('A. B. Okafor', Name::title('a. b. okafor'));
    }

    public function test_non_latin_scripts_are_left_intact(): void
    {
        // No case to normalise, and nothing should be mangled reaching for one.
        $this->assertSame('አበበ በቀለ', Name::title('አበበ በቀለ'));
        $this->assertSame('محمد الأمين', Name::title('محمد الأمين'));
    }

    // ── and it never blows up ────────────────────────────────────────────────

    public function test_empty_input_is_empty_output(): void
    {
        $this->assertSame('', Name::title(''));
        $this->assertSame('', Name::title('   '));
    }

    public function test_punctuation_only_input_survives(): void
    {
        $this->assertSame('-', Name::title('-'));
        $this->assertSame("'", Name::title("'"));
    }

    public function test_organisations_read_correctly_too(): void
    {
        // Not every nominee is a person — choirs, ensembles and schools are
        // nominated, and they go through the same field.
        $this->assertSame('Kaduna Singers', Name::title('KADUNA SINGERS'));
        $this->assertSame('Harmony Ensemble', Name::title('harmony ensemble'));
        $this->assertSame('St Mary Cathedral', Name::title('ST MARY CATHEDRAL'));
    }
}
