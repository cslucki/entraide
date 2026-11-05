<?php

namespace Tests\Feature;

use App\Support\Entraide\TempsPartage;
use Tests\TestCase;

/**
 * TASK-1754 (§9.1) — le formateur de temps, qui gouverne CINQ surfaces.
 *
 * Trois gardes, pas plus. Le reste (le rendu de chaque ecran) appartient a la
 * recette navigateur : `assertSee()` ne mesure pas la visibilite.
 */
class TASK1754TempsPartageTest extends TestCase
{
    /** Les paliers, et surtout le singulier — « 1 pts » est la faute qui se voit. */
    public function test_les_paliers_et_le_singulier(): void
    {
        $this->assertSame('30 min', TempsPartage::duree(30));
        $this->assertSame('1 h', TempsPartage::duree(60));
        $this->assertSame('1 h 30', TempsPartage::duree(90));
        $this->assertSame('2 h 35', TempsPartage::duree(155));

        $this->assertSame('1 min · 1 pt', TempsPartage::libelle(1));
        $this->assertSame('2 min · 2 pts', TempsPartage::libelle(2));
    }

    /**
     * Zero n'est pas « 0 min » : c'est un coup de main OFFERT (§9.3).
     *
     * Mais un SOLDE a zero est un vrai zero — « Offert » y serait absurde.
     * C'est toute la raison d'etre du drapeau, et la raison de ce test.
     */
    public function test_zero_est_un_don_sauf_quand_c_est_un_solde(): void
    {
        $this->assertSame('Offert 🎁', TempsPartage::duree(0));
        $this->assertSame('Offert 🎁', TempsPartage::libelle(0));
        $this->assertTrue(TempsPartage::estOffert(0));

        $this->assertSame('0 min · 0 pt', TempsPartage::solde(0));
        $this->assertFalse(TempsPartage::estOffert(30));
    }

    /**
     * Le formateur est TRADUIT.
     *
     * C'est le defaut que T1754 repare ailleurs (§3.4 : du francais en dur dans
     * le modele) — il ne doit pas etre reintroduit ici.
     */
    public function test_le_formateur_est_traduit(): void
    {
        $this->app->setLocale('en');

        $this->assertSame('Offered 🎁', TempsPartage::duree(0));
        $this->assertSame('0 min · 0 pts', TempsPartage::solde(0));
    }
}
