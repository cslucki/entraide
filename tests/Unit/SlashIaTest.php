<?php

namespace Tests\Unit;

use App\Support\Loops\SlashIa;
use PHPUnit\Framework\TestCase;

/**
 * TASK-1299 — parsing du prefixe `/ia` (brief §4).
 *
 * Invocation si et seulement si le corps COMMENCE par `/ia` suivi d'un blanc
 * ou d'une fin de chaine, insensible a la casse. `question()` rend :
 *   - null : pas une invocation (message ordinaire) ;
 *   - ''   : invocation VIDE (aide locale, brief §5) ;
 *   - le reste trime : la question adressee au modele.
 *
 * Le parseur ne DECIDE rien d'autre : le corps est persiste tel que tape par
 * l'appelant, prefixe compris — ce fichier ne teste que la detection.
 */
class SlashIaTest extends TestCase
{
    public function test_a_question_after_the_prefix_is_an_invocation(): void
    {
        $this->assertSame('Quelle synthese ?', SlashIa::question('/ia Quelle synthese ?'));
    }

    public function test_the_prefix_is_case_insensitive_and_extra_spaces_are_trimmed(): void
    {
        $this->assertSame('Quelle synthese ?', SlashIa::question('/IA  Quelle synthese ?'));
        $this->assertSame('mixte', SlashIa::question('/Ia mixte'));
    }

    public function test_the_bare_prefix_is_an_empty_invocation(): void
    {
        $this->assertSame('', SlashIa::question('/ia'));
        $this->assertSame('', SlashIa::question('/ia   '));
    }

    public function test_a_newline_separates_the_prefix_like_a_space(): void
    {
        $this->assertSame('question sur la ligne suivante', SlashIa::question("/ia\nquestion sur la ligne suivante"));
        $this->assertSame('', SlashIa::question("/ia\n"));
    }

    public function test_the_prefix_elsewhere_in_the_body_is_an_ordinary_message(): void
    {
        $this->assertNull(SlashIa::question('Regarde /ia ici'));
    }

    public function test_a_longer_word_starting_with_the_prefix_is_an_ordinary_message(): void
    {
        $this->assertNull(SlashIa::question('/iat quelque chose'));
    }

    public function test_a_doubled_slash_is_an_ordinary_message(): void
    {
        $this->assertNull(SlashIa::question('//ia comme ceci'));
    }

    public function test_a_leading_space_is_an_ordinary_message(): void
    {
        // « COMMENCE par /ia » est litteral : la position 0, pas « apres trim ».
        $this->assertNull(SlashIa::question(' /ia question'));
    }

    public function test_an_empty_body_is_an_ordinary_message(): void
    {
        $this->assertNull(SlashIa::question(''));
    }
}
