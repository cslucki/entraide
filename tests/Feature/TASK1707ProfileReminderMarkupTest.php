<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * TASK-1707 F4 — le bandeau de profil incomplet affichait ses balises EN CLAIR.
 *
 * Constate en recette OFSH le 25/10, a l'inscription d'OFSH100 :
 *
 * > Les champs &lt;strong&gt;Prenom, Nom, Telephone, Ville, Pays et
 * > Presentation&lt;/strong&gt; sont obligatoires
 *
 * La cause est d'une ligne : `{{ }}` echappe TOUT ce qu'il rend, y compris le
 * `<strong>` que la vue venait elle-meme d'inserer dans le parametre `:fields`.
 *
 * ## Pourquoi un `{!! !!}` est sur ICI, et ce que ce fichier mesure
 *
 * Un echo brut merite toujours sa justification. Les deux morceaux de cette
 * phrase sont a nous — la phrase vient de `lang/`, la liste des champs aussi —
 * et la valeur inseree passe par `e()` avant de l'etre. Aucune saisie
 * d'utilisateur n'entre dans cette ligne.
 *
 * Mais « aucune saisie aujourd'hui » n'est pas une propriete du code : c'est un
 * fait sur les donnees du moment. Le second test fige donc la seule chose qui
 * rende l'echo brut defendable — le `e()` — en faisant passer une valeur
 * hostile par le fichier de langue. Retirer le `e()` laisserait le premier test
 * vert et rougirait celui-la.
 */
class TASK1707ProfileReminderMarkupTest extends TestCase
{
    use RefreshDatabase;

    private User $incomplet;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::factory()->create(['is_active' => true]);

        // Sans `bio`, le middleware `EnsureProfileComplete` renvoie sur le
        // profil avec `profile_required` en session : c'est la SEULE condition
        // d'affichage du bandeau.
        $this->incomplet = User::factory()->create([
            'organization_id' => $organization->id,
            'first_name' => 'Ofsh',
            'name' => 'Cent',
            'phone' => '0123456789',
            'city' => 'Paris',
            'country_code' => 'FR',
            'bio' => null,
        ]);
    }

    /**
     * Le bandeau tel que le membre le recoit, bandeau ISOLE du reste de la page.
     *
     * L'assertion porte sur l'ELEMENT et pas sur la page : `&lt;strong&gt;`
     * apparait legitimement ailleurs dans un formulaire de profil, et une
     * mesure posee sur le document entier serait vraie pour de mauvaises
     * raisons.
     */
    private function bandeau(): string
    {
        $redirection = $this->actingAs($this->incomplet)
            ->get(route('services.create'))
            ->assertRedirect(route('profile.edit'));

        $html = $this->followRedirects($redirection)->assertOk()->getContent();

        $this->assertSame(
            1,
            preg_match('/<div[^>]*\sdata-profile-reminder[\s>](.*?)<\/div>\s*<\/div>/s', $html, $bandeau),
            'Le bandeau de profil incomplet n\'est pas servi : la mesure ne porterait sur rien.'
        );

        return $bandeau[1];
    }

    public function test_le_bandeau_rend_un_vrai_element_strong_et_non_ses_balises(): void
    {
        $bandeau = $this->bandeau();

        $this->assertStringContainsString(
            '<strong>'.__('profile.reminder_fields').'</strong>',
            $bandeau,
            'La liste des champs n\'est pas mise en valeur : le balisage a ete echappe.'
        );

        $this->assertStringNotContainsString(
            '&lt;strong&gt;',
            $bandeau,
            'Les balises sont encore affichees en clair au membre.'
        );
    }

    /**
     * Une valeur hostile venue du fichier de langue ressort INERTE.
     *
     * C'est la contrepartie du `{!! !!}` : il rend le balisage de la phrase,
     * jamais celui de ce qu'on y insere.
     */
    public function test_la_valeur_inseree_reste_echappee(): void
    {
        // `addLines()` marque le groupe comme DEJA CHARGE : passer la seule
        // clef hostile empecherait le fichier d'etre lu, et `:fields` ne serait
        // meme plus remplace — le test serait vert sans rien mesurer. Le groupe
        // est donc reinjecte en entier, cette clef mise a part.
        foreach (['fr', 'en'] as $locale) {
            $lignes = [];
            foreach ((array) Lang::get('profile', [], $locale) as $clef => $valeur) {
                $lignes['profile.'.$clef] = $valeur;
            }
            $lignes['profile.reminder_fields'] = '<script>alert(1)</script>';

            Lang::addLines($lignes, $locale);
        }

        $bandeau = $this->bandeau();

        // Le garde-fou du garde-fou : si l'injection n'avait pas pris, les deux
        // assertions suivantes seraient vraies pour rien.
        $this->assertStringContainsString(
            __('profile.profile_reminder_title'),
            $bandeau,
            'Le groupe de traduction a ete casse par l\'injection : le bandeau ne mesure plus rien.'
        );

        $this->assertStringNotContainsString(
            '<script>alert(1)</script>',
            $bandeau,
            'La valeur inseree traverse l\'echo brut SANS etre echappee.'
        );
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $bandeau);
    }
}
