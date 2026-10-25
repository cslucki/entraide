<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1695 (point 5b, bug signalé par Cyril) — le FAB « Nouveau » s'affiche
 * au MEMBRE d'une Boucle, pas seulement à son owner.
 *
 * ## Le bug, et pourquoi il était invisible aux tests
 *
 * `uploadFiles` (T1684) ouvre le dépôt au membre actif, la matrice de
 * permissions le dit (`dossiers.upload_file` est un droit de membre depuis
 * l'arbitrage MASTER), et `StoreDossierFileRequest` l'autorise : **le serveur
 * acceptait déjà le dépôt d'un membre.** Mais la page Dossiers gardait ses
 * surfaces de dépôt derrière `manageFiles`. La porte était ouverte, sans
 * poignée — l'exact inverse de T1656, et le symétrique du cas LoopChat où la
 * poignée menait à une pièce sans porte.
 *
 * T1684 teste la POLICY ; rien ne testait l'ÉCRAN. C'est l'écran que Cyril a
 * vu.
 */
class TASK1695LoopMemberFabTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private User $membre;

    private $loop;

    private Dossier $racine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->owner = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->membre = User::factory()->create(['organization_id' => $this->organization->id]);

        app()->instance('current_organization', $this->organization);

        $service = new LoopService;
        $this->loop = $service->createLoop($this->owner, 'Boucle FAB 1695', type: 'project');
        $service->addMember($this->loop, $this->membre, 'member');

        $this->racine = Dossier::query()->where('loop_id', $this->loop->id)->firstOrFail();
    }

    private function page(User $qui): string
    {
        return $this->actingAs($qui)
            ->get(route('organization.dossiers.show', [
                'organization' => $this->organization->slug,
                'dossier' => $this->racine->id,
            ]))
            ->assertOk()
            ->getContent();
    }

    /** LE bug : le membre doit avoir la poignée que le serveur lui accorde. */
    public function test_a_loop_member_sees_the_new_fab(): void
    {
        $this->assertStringContainsString('data-dossier-fab', $this->page($this->membre));
    }

    public function test_the_owner_still_sees_it(): void
    {
        $this->assertStringContainsString('data-dossier-fab', $this->page($this->owner));
    }

    /**
     * Un visiteur qui LIT ne dépose pas.
     *
     * Le Dossier d'une Boucle se visite (policy `view`) au-delà de ses
     * déposants. Sans cette assertion, remplacer la garde par `true` — ou par
     * `viewFiles` — resterait vert partout ailleurs.
     */
    public function test_a_reader_without_upload_right_has_no_fab(): void
    {
        // Un Dossier personnel d'un AUTRE utilisateur, visible par visibilité
        // d'Organization : lecteur, jamais déposant.
        $proprietaire = User::factory()->create(['organization_id' => $this->organization->id]);
        $lecteur = User::factory()->create(['organization_id' => $this->organization->id]);
        $dossierOrg = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $proprietaire->id,
            'visibility' => 'organization',
        ]);

        $html = $this->actingAs($lecteur)
            ->get(route('organization.dossiers.show', [
                'organization' => $this->organization->slug,
                'dossier' => $dossierOrg->id,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-dossier-fab', $html);
    }

    /**
     * Chaque entrée du menu porte SA garde : « Nouvel Article » n'est pas un
     * dépôt.
     *
     * Depuis que le FAB s'ouvre au membre déposant, montrer « Nouvel Article »
     * à qui n'a pas `attachArticle` serait une poignée vers une porte fermée.
     * La note markdown, elle, EST un dépôt (un `File` créé au vol passe par
     * `StoreDossierFileRequest`) : elle reste visible au membre.
     */
    public function test_new_article_entry_follows_its_own_authority(): void
    {
        $this->assertStringNotContainsString('data-fab-new-article', $this->page($this->membre));
        $this->assertStringContainsString('data-fab-new-article', $this->page($this->owner));
    }

    /**
     * Le moteur d'import REÇOIT son autorisation.
     *
     * Bug signalé par Cyril : « Importer des fichiers » n'ouvrait plus rien.
     * Le contrôleur passait bien `canUploadFiles` à la vue, mais la liste de
     * clés du composant Alpine est **écrite à la main** et je ne l'y avais pas
     * ajoutée. `config.canUploadFiles` valait `undefined`, FilePond ne
     * s'initialisait pas, et `browseFiles()` sortait **en silence** — pas
     * d'erreur, pas de message, rien.
     *
     * Mes tests du FAB ne mesuraient que la POIGNÉE. Celui-ci mesure le
     * câblage : la valeur doit arriver jusqu'au composant.
     */
    public function test_the_upload_engine_receives_its_permission(): void
    {
        foreach ([$this->owner, $this->membre] as $qui) {
            $html = $this->page($qui);

            // La forme ECHAPPEE telle que Blade la rend dans l'attribut
            // (`\u0022`, pas `&quot;`) — verifiee sur le rendu reel, pas
            // supposee. Lecon T1672 : une assertion doit viser ce qui sort.
            $this->assertStringContainsString(
                'canUploadFiles\u0022:true',
                $html,
                'Le composant doit recevoir canUploadFiles, sinon FilePond ne démarre pas.',
            );
        }
    }

    /**
     * Et le simple LECTEUR ne le reçoit pas.
     *
     * Sans cette assertion, câbler la clé en dur à `true` resterait vert.
     */
    public function test_a_reader_does_not_receive_the_upload_permission(): void
    {
        $proprietaire = User::factory()->create(['organization_id' => $this->organization->id]);
        $lecteur = User::factory()->create(['organization_id' => $this->organization->id]);
        $dossierOrg = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $proprietaire->id,
            'visibility' => 'organization',
        ]);

        $html = $this->actingAs($lecteur)
            ->get(route('organization.dossiers.show', [
                'organization' => $this->organization->slug,
                'dossier' => $dossierOrg->id,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('canUploadFiles\u0022:false', $html);
    }
}
