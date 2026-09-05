<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1401 — la barre superieure mobile nomme la personne, pas sa colonne.
 *
 * ## Une regression que j'ai moi-meme introduite
 *
 * TASK-1399 a mis le nom de FAMILLE dans `users.name`, parce que
 * `User::fullName` vaut `trim(first_name.' '.name)` et que le nom complet
 * stocke la produisait « Marcus Marcus Whitfield » partout.
 *
 * Mais `mobile-topbar` lisait la colonne BRUTE. Avant TASK-1399 elle portait
 * le nom complet, et l'affichage semblait donc juste ; depuis, la barre
 * superieure d'une page profil annonce « Okafor » quand le reste de la page
 * dit « Sam Okafor ».
 *
 * ## Pourquoi je ne l'avais pas vu
 *
 * Mon balayage de TASK-1399 cherchait `user->name` dans les vues et n'avait
 * retenu que deux resultats, tous deux des CHAMPS DE FORMULAIRE legitimes.
 * Celui-ci passe par une variable intermediaire — `$routeTitle` — et a
 * echappe au motif. C'est la lecon de TASK-1381, et elle vaut une seconde
 * fois : grepper la VALEUR CIBLE, pas seulement le motif d'appel.
 *
 * ## Ce que la garde mesure
 *
 * Le RENDU, a la largeur ou l'element est visible. Le composant est
 * `md:hidden` : a 1440 il existe dans le DOM mais ne s'affiche pas, ce qui
 * explique que trois repetitions desktop n'aient rien vu. Une garde HTTP le
 * voit, elle, parce que le markup est rendu quelle que soit la largeur.
 */
class TASK1401MobileTopbarProfileTitleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $visiteur;

    private User $sujet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['locale' => 'en']);

        $this->visiteur = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'first_name' => 'Marcus',
            'name' => 'Whitfield',
        ]);

        $this->sujet = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'first_name' => 'Sam',
            'name' => 'Okafor',
        ]);

        app()->instance('current_organization', $this->organization);
    }

    /**
     * La barre mobile annonce le nom COMPLET de la personne consultee.
     *
     * La mesure porte sur le markup reellement rendu : le composant est
     * `md:hidden`, donc invisible a 1440 — c'est precisement pour cela que le
     * defaut a survecu a trois repetitions desktop.
     *
     * ATTENTION, et c'est mesure : cette garde NE DISCRIMINE PAS. Le sabotage
     * qui remet `->name` la laisse VERTE, parce que « Sam Okafor » figure de
     * toute facon ailleurs sur la page — dans le vrai titre. Elle documente
     * l'attendu ; c'est la garde suivante qui protege.
     */
    public function test_the_mobile_topbar_announces_the_full_name(): void
    {
        $reponse = $this->actingAs($this->visiteur)->get($this->url());

        $reponse->assertOk();
        $reponse->assertSee('Sam Okafor', escape: false);
    }

    /**
     * Et jamais le seul nom de famille.
     *
     * LA garde de cette tranche, et la seule qui morde : le sabotage la fait
     * rougir SEULE, les deux autres restant vertes.
     *
     * La raison tient en une phrase : « Sam Okafor » CONTIENT « Okafor ».
     * Affirmer la presence du nom complet quelque part dans la page ne dit
     * donc rien du titre. On mesure le contenu des `<h1>` eux-memes, et on
     * interdit qu'un seul d'entre eux vaille le nom de famille nu.
     */
    public function test_the_topbar_title_is_never_the_bare_surname(): void
    {
        $html = $this->actingAs($this->visiteur)->get($this->url())->getContent();

        $titres = [];
        preg_match_all('/<h1[^>]*>(.*?)<\/h1>/s', (string) $html, $matches);

        foreach ($matches[1] as $titre) {
            $titres[] = trim(html_entity_decode(strip_tags($titre)));
        }

        $this->assertNotEmpty($titres, 'La page profil doit porter au moins un titre.');
        $this->assertNotContains('Okafor', $titres, 'Un titre ne doit jamais valoir le seul nom de famille.');
        $this->assertContains('Sam Okafor', $titres);
    }

    /**
     * Une personne SANS prenom n'affiche pas un titre bancal.
     *
     * `fullName` vaut `trim(first_name.' '.name)` : sans prenom, il rend le
     * seul nom, et c'est correct. La garde precedente interdirait pourtant
     * « Okafor » — celle-ci dit ou passe la frontiere, pour qu'un futur
     * lecteur ne prenne pas l'interdit pour une regle absolue.
     */
    public function test_a_person_without_a_first_name_still_gets_a_title(): void
    {
        $sansPrenom = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'first_name' => '',
            'name' => 'Nandakumar',
        ]);

        $this->actingAs($this->visiteur)
            ->get(route('organization.profile.show', ['organization' => $this->organization, 'user' => $sansPrenom]))
            ->assertOk()
            ->assertSee('Nandakumar', escape: false);
    }

    private function url(): string
    {
        return route('organization.profile.show', [
            'organization' => $this->organization,
            'user' => $this->sujet,
        ]);
    }
}
