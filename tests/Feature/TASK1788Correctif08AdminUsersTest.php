<?php

namespace Tests\Feature;

use App\Models\CrmContact;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1788, correctif 08 — la gestion des comptes par le SuperAdmin.
 *
 * Deux demandes de Cyril sur `/admin/users` :
 *
 * 1. un lien « Voir la fiche » a cote de « Saisir une note » ;
 * 2. « on ne peut pas chercher les utilisateurs par leur prenom » — verifier
 *    que nom, prenom ET adresse repondent.
 *
 * ## ⚠️ Le second n'etait PAS un champ oublie
 *
 * Les trois colonnes etaient deja interrogees. Le defaut etait la CASSE :
 * `LIKE` est insensible a la casse sous SQLite et SENSIBLE sous PostgreSQL.
 * Taper « cyril » ne rendait donc rien en production, et tout en test — un
 * defaut structurellement invisible au gate SQLite.
 *
 * Mesure sur le jumeau avant correction : `LIKE '%cyril%'` 0 ligne,
 * `ILIKE '%cyril%'` 6 lignes, `LIKE '%Cyril%'` 5 (et manquait « CYRIL »).
 *
 * Ces tests cherchent donc en MINUSCULES et en MAJUSCULES une valeur stockee
 * en casse mixte. Sous SQLite ils passeraient meme sans le correctif : c'est le
 * gate PostgreSQL qui les rend decisifs, et c'est pour cela qu'ils existent.
 */
class TASK1788Correctif08AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $superAdmin;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1788-08', 'is_active' => true, 'locale' => 'fr',
        ]);

        $this->superAdmin = User::factory()->create([
            'is_admin' => true,
            'organization_id' => $this->organization->id,
        ]);

        // Valeurs FIXES et en casse MIXTE : jamais de valeur Faker dans une
        // assertion, et la casse est precisement ce qu'on mesure.
        $this->membre = User::factory()->create([
            'organization_id' => $this->organization->id,
            'first_name' => 'Ottoline',
            'name' => 'Kaminski',
            'email' => 'Ottoline.Kaminski@example.com',
        ]);
    }

    private function recherche(string $terme): string
    {
        return $this->actingAs($this->superAdmin)
            ->get(route('admin.users', ['search' => $terme]))
            ->assertOk()
            ->getContent();
    }

    // ── 2. La recherche ──────────────────────────────────────────────────────

    /**
     * Prenom, nom et adresse, chacun dans TROIS casses.
     *
     * Le `dataProvider` aurait masque laquelle echoue dans le message ; la
     * boucle nomme le cas dans l'assertion.
     */
    public function test_on_trouve_par_prenom_nom_ou_adresse_quelle_que_soit_la_casse(): void
    {
        $cas = [
            'prenom tel quel' => 'Ottoline',
            'prenom en minuscules' => 'ottoline',
            'prenom en majuscules' => 'OTTOLINE',
            'nom tel quel' => 'Kaminski',
            'nom en minuscules' => 'kaminski',
            'nom en majuscules' => 'KAMINSKI',
            'adresse en minuscules' => 'ottoline.kaminski@example.com',
            'adresse en majuscules' => 'OTTOLINE.KAMINSKI@EXAMPLE.COM',
            'fragment de prenom' => 'ttolin',
        ];

        foreach ($cas as $nom => $terme) {
            $html = $this->recherche($terme);

            $this->assertStringContainsString('Kaminski', $html,
                "La recherche « {$nom} » ({$terme}) ne trouve pas le compte.");
        }
    }

    /** La recherche cherche toujours : elle ne rend pas tout le monde. */
    public function test_un_terme_qui_ne_correspond_a_personne_ne_rend_personne(): void
    {
        $html = $this->recherche('zzz-introuvable-zzz');

        // TEMOIN : la page EST rendue — sans lui, une erreur ferait passer
        // l'assertion d'absence.
        $this->assertStringContainsString('Aucun utilisateur trouvé', $html,
            'Temoin absent : la page ne rend pas son etat vide.');
        $this->assertStringNotContainsString('Kaminski', $html,
            'La recherche rend un compte qui ne correspond pas.');
    }

    // ── 1. Le lien « Voir la fiche » ─────────────────────────────────────────

    public function test_le_lien_vers_la_fiche_est_propose_quand_la_fiche_existe(): void
    {
        $fiche = CrmContact::query()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->membre->id,
            'first_name' => $this->membre->first_name,
            'last_name' => $this->membre->name,
            'email' => $this->membre->email,
        ]);

        $html = $this->recherche('Ottoline');

        $this->assertStringContainsString('data-crm-fiche="'.$this->membre->id.'"', $html,
            'Le lien « Voir la fiche » manque alors que la fiche existe.');
        $this->assertStringContainsString(e(__('admin.crm_fiche_action')), $html,
            'Le libelle du lien n\'est pas rendu.');

        // L'URL est celle de la fiche, construite par `route()`.
        $this->assertStringContainsString(
            e(route('admin.crm.contacts.show', [
                'organization' => $this->organization->slug,
                'contact' => $fiche->id,
            ])),
            $html,
            'Le lien ne pointe pas vers la fiche de ce contact.'
        );
    }

    /** Sans fiche, le lien n'aurait aucune cible : c'est le suivi qui la cree. */
    public function test_le_lien_n_est_pas_propose_quand_la_fiche_n_existe_pas(): void
    {
        $html = $this->recherche('Ottoline');

        // TEMOIN : la ligne du compte EST rendue, avec ses autres actions.
        $this->assertStringContainsString('Kaminski', $html,
            'Temoin absent : le compte n\'est pas dans la liste.');
        $this->assertStringContainsString('data-crm-note-trigger="'.$this->membre->id.'"', $html,
            'Temoin absent : les actions CRM de la ligne ne sont pas rendues.');

        $this->assertStringNotContainsString('data-crm-fiche="'.$this->membre->id.'"', $html,
            'Un lien « Voir la fiche » est propose alors qu\'aucune fiche n\'existe.');
    }
}
