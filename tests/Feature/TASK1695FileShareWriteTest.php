<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\DossierFileShare;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1695, geste D — partager un fichier, et retirer ce partage.
 *
 * ## Qui peut partager
 *
 * `manageFiles` : ceux qui peuvent déjà renommer, déplacer, supprimer le
 * fichier. **Pas un simple lecteur** — sinon un destinataire pourrait
 * re-partager, ce que le chemin « C » exclut.
 *
 * L'ability étant une pure délégation au Dossier (geste B), le droit de
 * partager suit exactement celui de gérer le fichier : il n'y a **pas une
 * seconde règle** à tenir à jour.
 *
 * ## Avec qui
 *
 * N'importe quel membre de l'Organization — décision de Cyril, **plus large**
 * que le partage de Dossier. Noté comme tel dans le cadrage.
 */
class TASK1695FileShareWriteTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $proprietaire;

    private User $cible;

    private Dossier $dossier;

    private DossierFile $fichier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['slug' => 'org-1695d', 'is_active' => true]);
        app()->instance('current_organization', $this->organization);

        $this->proprietaire = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->cible = User::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Dupont',
            'first_name' => 'Marie',
        ]);

        $this->dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->proprietaire->id,
            'visibility' => 'private',
        ]);

        $this->fichier = DossierFile::factory()->create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->dossier->id,
            'uploaded_by' => $this->proprietaire->id,
        ]);
    }

    private function url(string $quoi, array $extra = []): string
    {
        return route('organization.dossiers.files.shares.'.$quoi, array_merge([
            'organization' => $this->organization->slug,
            'dossier' => $this->dossier->id,
            'file' => $this->fichier->id,
        ], $extra));
    }

    /** LE geste : partager ouvre la lecture au destinataire. */
    public function test_sharing_grants_read_access_to_that_person(): void
    {
        $this->actingAs($this->proprietaire)
            ->postJson($this->url('store'), ['user_id' => $this->cible->id])
            ->assertStatus(201);

        $this->assertDatabaseHas('dossier_file_shares', [
            'dossier_file_id' => $this->fichier->id,
            'user_id' => $this->cible->id,
            'organization_id' => $this->organization->id,
            'shared_by' => $this->proprietaire->id,
        ]);

        $this->assertTrue($this->cible->fresh()->can('viewFiles', $this->fichier));
    }

    /** Rejouer le geste ne crée pas de doublon, et ne casse pas. */
    public function test_sharing_twice_is_idempotent(): void
    {
        $this->actingAs($this->proprietaire)
            ->postJson($this->url('store'), ['user_id' => $this->cible->id])
            ->assertStatus(201);

        $this->actingAs($this->proprietaire)
            ->postJson($this->url('store'), ['user_id' => $this->cible->id])
            ->assertStatus(200);

        $this->assertSame(1, DossierFileShare::query()->count());
    }

    /** Retirer le partage referme l'accès. */
    public function test_removing_the_share_closes_the_access(): void
    {
        $this->actingAs($this->proprietaire)->postJson($this->url('store'), ['user_id' => $this->cible->id]);
        $share = DossierFileShare::query()->sole();

        $this->assertTrue($this->cible->fresh()->can('viewFiles', $this->fichier));

        $this->actingAs($this->proprietaire)
            ->deleteJson($this->url('destroy', ['share' => $share->id]))
            ->assertOk();

        $this->assertFalse($this->cible->fresh()->can('viewFiles', $this->fichier));
    }

    /**
     * Un simple LECTEUR ne peut pas partager.
     *
     * C'est la borne qui empêche le re-partage : le destinataire d'un fichier
     * ne doit pas pouvoir le transmettre.
     */
    public function test_a_mere_reader_cannot_share(): void
    {
        $this->actingAs($this->proprietaire)->postJson($this->url('store'), ['user_id' => $this->cible->id]);

        $tiers = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->actingAs($this->cible)
            ->postJson($this->url('store'), ['user_id' => $tiers->id])
            ->assertForbidden();

        $this->assertSame(1, DossierFileShare::query()->count());
    }

    /** Ni retirer un partage existant. */
    public function test_a_mere_reader_cannot_revoke(): void
    {
        $this->actingAs($this->proprietaire)->postJson($this->url('store'), ['user_id' => $this->cible->id]);
        $share = DossierFileShare::query()->sole();

        $this->actingAs($this->cible)
            ->deleteJson($this->url('destroy', ['share' => $share->id]))
            ->assertForbidden();

        $this->assertDatabaseHas('dossier_file_shares', ['id' => $share->id]);
    }

    /**
     * On ne partage pas avec quelqu'un d'une AUTRE Organization.
     *
     * La règle porte sur l'Organization **du fichier**, pas sur celle de la
     * page : borner sur le contexte aurait suffi tant que les deux coïncident,
     * et aurait cessé de suffire le jour où elles divergent — sans que rien ne
     * le dise.
     */
    public function test_sharing_with_a_stranger_is_refused(): void
    {
        $autreOrg = Organization::factory()->create(['slug' => 'org-1695d-autre', 'is_active' => true]);
        $etranger = User::factory()->create(['organization_id' => $autreOrg->id]);

        $this->actingAs($this->proprietaire)
            ->postJson($this->url('store'), ['user_id' => $etranger->id])
            ->assertStatus(422);

        $this->assertSame(0, DossierFileShare::query()->count());
    }

    /** Se partager à soi-même n'ouvre rien, et le dit. */
    public function test_sharing_with_oneself_is_refused(): void
    {
        $this->actingAs($this->proprietaire)
            ->postJson($this->url('store'), ['user_id' => $this->proprietaire->id])
            ->assertStatus(422);

        $this->assertSame(0, DossierFileShare::query()->count());
    }

    /**
     * Un partage d'un AUTRE fichier ne se révoque pas par ce chemin.
     *
     * Sans cette vérification, un identifiant de partage valide suffirait à
     * révoquer l'accès à un fichier qu'on ne gère pas.
     */
    public function test_a_share_of_another_file_cannot_be_revoked_here(): void
    {
        $autreFichier = DossierFile::factory()->create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->dossier->id,
            'uploaded_by' => $this->proprietaire->id,
        ]);

        $partageAilleurs = DossierFileShare::query()->create([
            'organization_id' => $this->organization->id,
            'dossier_file_id' => $autreFichier->id,
            'user_id' => $this->cible->id,
            'shared_by' => $this->proprietaire->id,
        ]);

        $this->actingAs($this->proprietaire)
            ->deleteJson($this->url('destroy', ['share' => $partageAilleurs->id]))
            ->assertNotFound();

        $this->assertDatabaseHas('dossier_file_shares', ['id' => $partageAilleurs->id]);
    }

    /** La recherche trouve un membre, par nom complet dans les deux ordres. */
    public function test_the_search_finds_a_member_by_full_name_either_way(): void
    {
        foreach (['Marie Dupont', 'Dupont Marie'] as $saisie) {
            $this->actingAs($this->proprietaire)
                ->getJson($this->url('search').'?q='.urlencode($saisie))
                ->assertOk()
                ->assertJsonPath('users.0.id', $this->cible->id);
        }
    }

    /** Et n'offre pas ceux à qui c'est DÉJÀ partagé. */
    public function test_the_search_excludes_people_already_shared_with(): void
    {
        $this->actingAs($this->proprietaire)->postJson($this->url('store'), ['user_id' => $this->cible->id]);

        $this->actingAs($this->proprietaire)
            ->getJson($this->url('search').'?q=Dupont')
            ->assertOk()
            ->assertJsonCount(0, 'users');
    }

    /** Ni soi-même. */
    public function test_the_search_excludes_oneself(): void
    {
        $reponse = $this->actingAs($this->proprietaire)
            ->getJson($this->url('search').'?q=')
            ->assertOk()
            ->json('users');

        $this->assertNotContains($this->proprietaire->id, array_column($reponse, 'id'));
    }

    /** La liste des partages en place est lisible par qui gère le fichier. */
    public function test_the_current_shares_are_listed(): void
    {
        $this->actingAs($this->proprietaire)->postJson($this->url('store'), ['user_id' => $this->cible->id]);

        $this->actingAs($this->proprietaire)
            ->getJson($this->url('index'))
            ->assertOk()
            ->assertJsonPath('shares.0.user_id', $this->cible->id);
    }

    /**
     * L'écran porte l'entrée « Partager », et seulement pour qui GÈRE.
     *
     * Le serveur porte la même garde (deux tests plus haut) : ceci n'est que
     * la poignée. Mais une poignée absente rend la porte introuvable, et une
     * poignée offerte à qui n'a pas le droit est un piège.
     */
    public function test_the_file_menu_offers_sharing_to_whoever_manages(): void
    {
        $html = $this->actingAs($this->proprietaire)
            ->get(route('organization.dossiers.show', [
                'organization' => $this->organization->slug,
                'dossier' => $this->dossier->id,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-file-share-open', $html);
        $this->assertStringContainsString('data-file-share-modal', $html);
    }

    /**
     * Et l'écran DIT ce que le partage n'ouvre pas.
     *
     * Personne ne devinerait une borne aussi étroite — lecture seule, ni le
     * Dossier, ni les voisins, ni le re-partage — et la deviner de travers
     * serait pire que de ne pas partager du tout.
     */
    public function test_the_screen_states_what_sharing_does_not_open(): void
    {
        $html = $this->actingAs($this->proprietaire)
            ->get(route('organization.dossiers.show', [
                'organization' => $this->organization->slug,
                'dossier' => $this->dossier->id,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(e(__('dossiers.file_share_hint')), $html);
    }

    /**
     * L'écran de partage n'est pas un ANNUAIRE d'adresses.
     *
     * Signalé par Cyril le 24/10, capture à l'appui : la liste des personnes
     * proposées affichait l'adresse de chacune. Trois lettres tapées rendaient
     * dix adresses — le gestionnaire d'un seul fichier pouvait moissonner
     * l'annuaire complet de l'Organization.
     *
     * L'assertion vise la **réponse JSON**, pas la page : masquer la colonne en
     * Blade aurait laissé l'adresse partir sur le réseau, lisible dans l'onglet
     * dédié du navigateur. Une donnée qu'on ne veut pas montrer ne doit pas
     * QUITTER le serveur — c'est cela que ce test tient.
     */
    public function test_the_search_never_returns_the_email_addresses(): void
    {
        $corps = $this->actingAs($this->proprietaire)
            ->getJson($this->url('search').'?q=Dupont')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Dupont', $corps, 'La personne doit bien être trouvée.');
        $this->assertStringNotContainsString($this->cible->email, $corps);
    }

    /**
     * Et la liste des partages EN PLACE ne la rend pas non plus.
     *
     * Deuxième charge utile du même écran. Sans ce test, fermer la recherche
     * seule aurait laissé la porte ouverte : il suffisait de partager une fois
     * pour relire l'adresse.
     */
    public function test_the_current_shares_never_return_the_email_addresses(): void
    {
        $this->actingAs($this->proprietaire)->postJson($this->url('store'), ['user_id' => $this->cible->id]);

        $corps = $this->actingAs($this->proprietaire)
            ->getJson($this->url('index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Dupont', $corps);
        $this->assertStringNotContainsString($this->cible->email, $corps);
    }

    /**
     * Chercher PAR adresse reste possible — arbitrage explicite de Cyril.
     *
     * Ce que cela laisse est une VÉRIFICATION (« telle adresse est-elle
     * membre ? »), une adresse à la fois et connue d'avance. Pas une
     * ÉNUMÉRATION, qui était le défaut.
     *
     * Ce test tient donc la moitié qu'il NE fallait PAS fermer : sans lui,
     * retirer `email` de la clause WHERE en même temps que de la projection
     * ne ferait rougir personne.
     */
    public function test_searching_by_email_still_finds_the_person(): void
    {
        $this->actingAs($this->proprietaire)
            ->getJson($this->url('search').'?q='.urlencode($this->cible->email))
            ->assertOk()
            ->assertJsonFragment(['id' => $this->cible->id]);
    }

    /** On ne partage pas un fichier supprimé. */
    public function test_a_soft_deleted_file_cannot_be_shared(): void
    {
        $this->fichier->delete();

        $this->actingAs($this->proprietaire)
            ->postJson($this->url('store'), ['user_id' => $this->cible->id])
            ->assertNotFound();
    }
}
