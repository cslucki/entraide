<?php

namespace Tests\Feature\ScenarioManifest;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\LoopDecision;
use App\Models\LoopEvent;
use App\Models\LoopEventResponse;
use App\Models\LoopMessage;
use App\Models\LoopPoll;
use App\Models\LoopPollVote;
use App\Models\LoopRoadmapItem;
use App\Models\Organization;
use App\Models\ScenarioPackEntity;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Skill;
use App\Models\User;
use App\Support\ScenarioManifest\ScenarioManifestValidator;
use App\Support\ScenarioPacks\Manifest\ManifestSandboxLoadResult;
use App\Support\ScenarioPacks\Manifest\ManifestSandboxLoadService;
use App\Support\ScenarioPacks\Manifest\ManifestScenarioPack;
use App\Support\ScenarioPacks\Manifest\ScenarioManifest;
use App\Support\ScenarioPacks\ScenarioPackLoader;
use App\Support\ScenarioPacks\ScenarioPackRemover;
use App\Support\ScenarioPacks\ScenarioPackResetter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TASK-1643 — le monde CORE d'AMT, du chargement au retrait.
 *
 * T1642 prouvait le socle ; cette suite prouve le contenu. Les nombres
 * attendus sont ceux que la fixture VERSIONNEE declare, comptes a la main sur
 * le document : un article, deux fichiers, quatre messages, une categorie,
 * deux competences, une demande, une offre, un sondage a deux options et deux
 * votes, un evenement a deux reponses, une decision, un element de roadmap a
 * deux assignes.
 *
 * Note de lecture des comptes : `withoutGlobalScopes()` retire AUSSI le filtre
 * de suppression douce. Seuls `ServiceRequest` et `Service` portent un scope
 * tenant ; pour tous les autres modeles on interroge donc normalement, sans
 * quoi un recensement compterait les lignes soft-deleted et ferait passer un
 * reset reussi pour un echec.
 *
 * Le cycle complet compte autant que le chargement : un monde qu'on ne sait
 * pas remettre a zero ni retirer proprement n'est pas une demonstration, c'est
 * une dette.
 */
class ManifestCoreLoaderTest extends TestCase
{
    private function manifestJson(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/ScenarioManifest/amt-formation-ia.json'));
    }

    private function approvedDigest(): string
    {
        return (string) (new ScenarioManifestValidator)->validate($this->manifestJson())->digest();
    }

    private function load(): ManifestSandboxLoadResult
    {
        return app(ManifestSandboxLoadService::class)->load($this->manifestJson(), $this->approvedDigest());
    }

    private function pack(): ManifestScenarioPack
    {
        return new ManifestScenarioPack(
            ScenarioManifest::fromApprovedJson($this->manifestJson(), $this->approvedDigest())
        );
    }

    // =====================================================================
    // Le monde CORE tel que le manifeste le declare
    // =====================================================================

    public function test_the_amt_core_world_is_materialised_exactly_as_declared(): void
    {
        $organization = $this->load()->organization;
        $scope = ['organization_id' => $organization->id];

        // Trois articles au total : l'article declare par AMT, et les DEUX
        // documents racines que la primitive canonique cree avec chaque
        // Boucle. Le compte total est donc la seule forme honnete — un
        // `where('listed_in_blog', false)` les melangerait.
        $this->assertSame(3, BlogPost::query()->where($scope)->count());
        $this->assertSame(1, BlogPost::query()->where($scope)->where('title', "Charte d'usage responsable")->count());
        $this->assertSame(2, DossierFile::query()->where($scope)->count());
        $this->assertSame(4, LoopMessage::query()->where($scope)->where('type', 'user')->count());
        $this->assertSame(1, Category::query()->where($scope)->count());
        $this->assertSame(2, Skill::query()->where($scope)->count());
        $this->assertSame(1, ServiceRequest::query()->withoutGlobalScope(BelongsToOrganizationScope::class)->where($scope)->count());
        $this->assertSame(1, Service::query()->withoutGlobalScope(BelongsToOrganizationScope::class)->where($scope)->count());
        $this->assertSame(1, LoopPoll::query()->where($scope)->count());
        $this->assertSame(1, LoopEvent::query()->where($scope)->count());
        $this->assertSame(1, LoopDecision::query()->where($scope)->count());
        $this->assertSame(1, LoopRoadmapItem::query()->where($scope)->count());

        // AMT ne declare que DEUX Dossiers, tous deux racines de leur Boucle :
        // il n'y a donc aucun Dossier non racine a creer. Le compte total le
        // prouve, et fera rougir ce test si un Dossier apparaissait en trop.
        $this->assertSame(2, Dossier::query()->where($scope)->count());
    }

    public function test_the_article_lives_in_its_dossier_and_carries_its_content(): void
    {
        $organization = $this->load()->organization;

        $article = BlogPost::query()
            ->where('organization_id', $organization->id)
            ->where('title', "Charte d'usage responsable")
            ->firstOrFail();

        $this->assertSame('published', $article->status);
        $this->assertSame('loop', $article->audience);
        $this->assertFalse((bool) $article->listed_in_blog, 'A manifest article belongs to its Dossier, not to the public blog.');
        $this->assertStringContainsString('Verifier les faits', str_replace(['é', 'è'], 'e', (string) $article->content));

        // Le rattachement passe par le pivot, qui est une entite a part
        // entiere et porte son organization_id.
        $entry = DossierBlogPost::query()->where('blog_post_id', $article->id)->firstOrFail();
        $this->assertSame($organization->id, $entry->organization_id);

        // Le Dossier d'accueil est bien le Dossier RACINE de la Boucle
        // training, identifie par son `loop_id` — pas par son nom.
        //
        // Divergence connue et documentee dans le TASK file : la primitive
        // canonique nomme un Dossier racine d'apres sa Boucle, alors que le
        // manifeste declare « Supports de formation ». Renommer la ligne
        // apres coup contournerait la garde d'ownership du registrar ; c'est
        // un arbitrage produit, pas un detail de loader.
        $dossier = Dossier::query()->findOrFail($entry->dossier_id);
        $training = Loop::query()->where('organization_id', $organization->id)->where('type', 'training')->firstOrFail();
        $this->assertSame($training->id, $dossier->loop_id);
    }

    /**
     * Le manifeste ne declare qu'un NOM, un type et un contenu inline. Disque,
     * chemin, taille et SHA-256 sont derives par BouclePro (spec 7.4) : le
     * document ne designe jamais un emplacement de stockage.
     */
    public function test_files_are_stored_with_derived_path_size_and_checksum(): void
    {
        $organization = $this->load()->organization;

        $file = DossierFile::query()
            ->where('organization_id', $organization->id)
            ->where('original_name', 'guide-prompt.md')
            ->firstOrFail();

        $this->assertSame('text/markdown', $file->mime_type);
        $this->assertSame('dossier_files', $file->disk);

        // Le chemin est DERIVE : il contient l'identifiant du Dossier, jamais
        // une valeur du manifeste.
        $this->assertStringStartsWith('dossier-files/'.$file->dossier_id.'/', $file->path);
        $this->assertStringEndsWith('guide-prompt.md', $file->path);

        $content = Storage::disk('dossier_files')->get($file->path);

        $this->assertNotNull($content);
        $this->assertSame(strlen((string) $content), (int) $file->size_bytes);
        $this->assertSame(hash('sha256', (string) $content), $file->checksum_sha256);
    }

    public function test_the_chatloop_history_follows_the_declared_order_and_replies(): void
    {
        $organization = $this->load()->organization;

        $training = Loop::query()
            ->where('organization_id', $organization->id)
            ->where('type', 'training')
            ->firstOrFail();

        $messages = LoopMessage::query()
            ->where('loop_id', $training->id)
            ->orderBy('created_at')
            ->get();

        $this->assertCount(3, $messages);

        // Les offsets declares sont -10080, -8640, -8580 : l'ordre temporel
        // suit l'ordre metier, et les messages ne se tassent pas sur une
        // seule seconde.
        $this->assertTrue($messages[0]->created_at->lt($messages[1]->created_at));
        $this->assertTrue($messages[1]->created_at->lt($messages[2]->created_at));

        // La reponse pointe le message anterieur de la MEME Boucle.
        $this->assertSame($messages[0]->id, $messages[1]->reply_to_id);
        $this->assertSame($messages[1]->id, $messages[2]->reply_to_id);
    }

    public function test_the_poll_carries_its_options_and_its_votes(): void
    {
        $organization = $this->load()->organization;

        $poll = LoopPoll::query()->where('organization_id', $organization->id)->firstOrFail();

        $this->assertSame('single', $poll->selection_type);
        $this->assertSame(2, $poll->options()->count());
        $this->assertSame(2, LoopPollVote::query()->where('poll_id', $poll->id)->count());
    }

    public function test_the_event_carries_its_duration_timezone_and_responses(): void
    {
        $organization = $this->load()->organization;

        $event = LoopEvent::query()->where('organization_id', $organization->id)->firstOrFail();

        $this->assertSame('online', $event->format);
        $this->assertSame('Europe/Paris', $event->timezone);
        $this->assertNull($event->location);
        $this->assertSame('https://meet.example.test/amt-workshop-2', $event->meeting_url);

        // Le manifeste declare une DUREE de 120 minutes ; la table stocke une
        // fin. Le calcul se verifie, il ne se suppose pas.
        $this->assertSame(120, (int) $event->starts_at->diffInMinutes($event->ends_at));

        $this->assertSame(2, LoopEventResponse::query()->where('event_id', $event->id)->count());
    }

    public function test_the_decision_and_its_roadmap_item_are_linked_with_assignees(): void
    {
        $organization = $this->load()->organization;

        $decision = LoopDecision::query()->where('organization_id', $organization->id)->firstOrFail();
        $item = LoopRoadmapItem::query()->where('organization_id', $organization->id)->firstOrFail();

        $this->assertSame('in_progress', $item->status);
        $this->assertSame($decision->id, $item->loop_decision_id, 'AMT links its roadmap item to its decision.');
        $this->assertSame(2, $item->assignees()->count());
    }

    /**
     * Spec 7.7 : la presence d'un objet IMPLIQUE l'activation de sa Card
     * canonique. Sans elle, le service du produit refuse la creation — et un
     * membre ne verrait pas l'objet charge.
     */
    public function test_the_canonical_cards_of_the_loaded_families_are_enabled(): void
    {
        $organization = $this->load()->organization;

        $training = Loop::query()
            ->where('organization_id', $organization->id)
            ->where('type', 'training')
            ->firstOrFail();

        foreach (['core.polls', 'core.events', 'core.decisions', 'core.roadmap'] as $card) {
            $this->assertTrue(
                LoopCard::query()->where('loop_id', $training->id)->where('card_key', $card)->where('enabled', true)->exists(),
                sprintf('Card %s must be enabled by the presence of its objects.', $card),
            );
        }
    }

    /**
     * `highlight_in_loop` est declare par AMT et DELIBEREMENT non materialise :
     * le seul mecanisme produit existant est une projection ChatLoop, que la
     * spec 7.6 exclut explicitement ("Il ne demande pas une projection
     * ChatLoop"). Ce test FIGE ce choix pour qu'il reste une decision visible
     * et non un oubli.
     */
    public function test_highlight_in_loop_creates_no_chatloop_projection(): void
    {
        $organization = $this->load()->organization;

        // Exactement les quatre messages HUMAINS du manifeste : aucune
        // projection de demande ou d'offre ne s'y est ajoutee.
        $this->assertSame(4, LoopMessage::query()->where('organization_id', $organization->id)->count());
        $this->assertSame(4, LoopMessage::query()->where('organization_id', $organization->id)->where('type', 'user')->count());
    }

    /**
     * La visibilite `organization` doit etre stockee CANONIQUEMENT.
     *
     * `Dossier::VISIBILITY_SHARED` est une valeur historique, marquee
     * `@deprecated`, absente de `Dossier::VISIBILITIES`, et que la
     * `DossierPolicy` ne reconnait pas : un Dossier declare `organization`
     * mais stocke `shared` serait invisible pour l'Organization entiere. Le
     * monde charge ne montrerait alors pas ce que le document a promis.
     *
     * Le test porte sur un Dossier NON RACINE : un Dossier racine tient sa
     * visibilite de sa Boucle et court-circuite cette colonne.
     */
    public function test_an_organization_wide_dossier_is_stored_with_the_canonical_visibility(): void
    {
        [$json, $digest] = $this->variantWithSharedDossier();

        $organization = app(ManifestSandboxLoadService::class)->load($json, $digest)->organization;

        $dossier = Dossier::query()
            ->where('organization_id', $organization->id)
            ->where('name', 'Ressources ouvertes')
            ->firstOrFail();

        $this->assertSame(Dossier::VISIBILITY_ORGANIZATION, $dossier->visibility);
        $this->assertContains($dossier->visibility, Dossier::VISIBILITIES, 'The stored value must belong to the canonical list.');
        $this->assertNull($dossier->loop_id, 'This test must exercise a NON-root dossier.');
    }

    /**
     * La contrepartie qui compte : la valeur stockee doit reellement OUVRIR
     * l'acces a l'Organization, et le refuser au-dela. Une constante correcte
     * qui ne changerait rien pour la Policy ne prouverait rien.
     */
    public function test_the_organization_wide_dossier_is_visible_in_its_tenant_and_nowhere_else(): void
    {
        [$json, $digest] = $this->variantWithSharedDossier();

        $organization = app(ManifestSandboxLoadService::class)->load($json, $digest)->organization;

        $dossier = Dossier::query()
            ->where('organization_id', $organization->id)
            ->where('name', 'Ressources ouvertes')
            ->firstOrFail();

        // Un membre de la sandbox, qui n'est NI proprietaire du Dossier ni
        // membre explicite : seule la visibilite `organization` peut lui
        // ouvrir l'acces.
        $insider = User::query()
            ->where('organization_id', $organization->id)
            ->where('id', '!=', $dossier->owner_id)
            ->firstOrFail();

        $outsiderOrganization = Organization::create([
            'name' => 'Autre tenant',
            'slug' => 'autre-tenant',
            'is_active' => true,
            'locale' => 'fr',
        ]);

        $outsider = User::query()->create([
            'organization_id' => $outsiderOrganization->id,
            'first_name' => 'Dehors',
            'name' => 'Dehors',
            'email' => 'dehors@autre-tenant.test',
            'password' => 'x',
        ]);

        app()->instance('current_organization', $organization);

        $this->assertTrue($insider->can('view', $dossier), 'A member of the same Organization must see an organization-wide dossier.');
        $this->assertFalse($outsider->can('view', $dossier), 'A user of another Organization must never see it.');
    }

    /**
     * Une variante VALIDE d'AMT portant un Dossier NON RACINE declare
     * `organization`. AMT n'en contient aucun : ses deux Dossiers sont les
     * racines de ses deux Boucles.
     *
     * @return array{0: string, 1: string} [json, digest]
     */
    private function variantWithSharedDossier(): array
    {
        $document = json_decode($this->manifestJson(), false, 512, JSON_THROW_ON_ERROR);

        $document->dossiers[] = (object) [
            'key' => 'ressources-ouvertes',
            'name' => 'Ressources ouvertes',
            'owner' => 'trainer-1',
            'loop' => null,
            'parent' => null,
            'visibility' => 'organization',
            'root_document' => null,
        ];

        $json = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $result = (new ScenarioManifestValidator)->validate($json);

        $this->assertTrue($result->isValid(), 'The variant used by this test must itself be a VALID manifest: '.json_encode($result->toArray()['errors']));

        return [$json, (string) $result->digest()];
    }

    // =====================================================================
    // Cycle de vie
    // =====================================================================

    public function test_an_exact_replay_duplicates_nothing_of_the_core_world(): void
    {
        $first = $this->load();
        $before = $this->census($first->organization);

        $replay = $this->load();

        $this->assertTrue($replay->wasReplay);
        $this->assertSame($first->organization->id, $replay->organization->id);
        $this->assertSame($before, $this->census($replay->organization));
    }

    public function test_replaying_the_pack_in_place_duplicates_nothing_of_the_core_world(): void
    {
        $result = $this->load();
        $before = $this->census($result->organization);

        // Rejeu du PACK dans la MEME sandbox : c'est le chemin que le resetter
        // emprunte, et il ne doit rien dupliquer.
        app(ScenarioPackLoader::class)->load($this->pack(), $result->organization);

        $this->assertSame($before, $this->census($result->organization));
    }

    public function test_resetting_restores_the_core_world(): void
    {
        $result = $this->load();
        $organization = $result->organization;
        $before = $this->census($organization);

        // Deux derives posterieures au chargement.
        LoopRoadmapItem::query()->where('organization_id', $organization->id)->delete();
        DossierFile::query()->where('organization_id', $organization->id)->limit(1)->delete();

        $this->assertNotSame($before, $this->census($organization));

        app(ScenarioPackResetter::class)->reset($this->pack(), $organization);

        $this->assertSame($before, $this->census($organization), 'Reset must return the sandbox to the world the manifest describes.');
    }

    public function test_removing_the_pack_leaves_no_core_entity_behind(): void
    {
        $result = $this->load();
        $organization = $result->organization;

        app(ScenarioPackRemover::class)->remove($this->pack()->packId(), $organization);

        $scope = ['organization_id' => $organization->id];

        $this->assertSame(0, ScenarioPackEntity::query()->where($scope)->count());
        $this->assertSame(0, BlogPost::query()->where($scope)->count());
        $this->assertSame(0, DossierFile::query()->where($scope)->count());
        $this->assertSame(0, LoopMessage::query()->where($scope)->count());
        $this->assertSame(0, Category::query()->where($scope)->count());
        $this->assertSame(0, Skill::query()->where($scope)->count());
        $this->assertSame(0, ServiceRequest::query()->withoutGlobalScope(BelongsToOrganizationScope::class)->where($scope)->count());
        $this->assertSame(0, Service::query()->withoutGlobalScope(BelongsToOrganizationScope::class)->where($scope)->count());
        $this->assertSame(0, LoopPoll::query()->where($scope)->count());
        $this->assertSame(0, LoopEvent::query()->where($scope)->count());
        $this->assertSame(0, LoopDecision::query()->where($scope)->count());
        $this->assertSame(0, LoopRoadmapItem::query()->where($scope)->count());
        $this->assertSame(0, Loop::query()->where($scope)->count());
        $this->assertSame(0, User::query()->where($scope)->count());
    }

    // =====================================================================
    // Securite tenant
    // =====================================================================

    /**
     * Une Organization cliente preexistante ne doit voir AUCUNE entite CORE
     * apparaitre chez elle, meme quand elle porte le slug que le manifeste
     * propose.
     */
    public function test_a_client_organization_receives_no_core_entity(): void
    {
        $client = Organization::create([
            'name' => 'Client reel',
            'slug' => 'amt-formation-ia',
            'is_active' => true,
            'locale' => 'fr',
        ]);

        $this->load();

        $scope = ['organization_id' => $client->id];

        $this->assertSame(0, BlogPost::query()->where($scope)->count());
        $this->assertSame(0, DossierFile::query()->where($scope)->count());
        $this->assertSame(0, LoopMessage::query()->where($scope)->count());
        $this->assertSame(0, LoopPoll::query()->where($scope)->count());
        $this->assertSame(0, Category::query()->where($scope)->count());
        $this->assertSame(0, ScenarioPackEntity::query()->where($scope)->count());
        $this->assertNull($client->fresh()->scenario_sandbox_created_at);
    }

    public function test_every_core_entity_is_registered_under_the_sandbox_only(): void
    {
        $organization = $this->load()->organization;

        // Le registrar refuse toute entite hors Organization du chargement :
        // si une seule ligne du registre pointait ailleurs, le chargement
        // aurait leve. Ce test fige la propriete au lieu de la supposer.
        $foreign = ScenarioPackEntity::query()
            ->where('organization_id', '!=', $organization->id)
            ->count();

        $this->assertSame(0, $foreign);
    }

    /**
     * Recensement de toutes les familles, pour comparer deux etats d'un monde
     * sans ecrire vingt assertions a chaque fois.
     *
     * @return array<string, int>
     */
    private function census(Organization $organization): array
    {
        $scope = ['organization_id' => $organization->id];

        return [
            'users' => User::query()->where($scope)->count(),
            'loops' => Loop::query()->where($scope)->count(),
            'dossiers' => Dossier::query()->where($scope)->count(),
            'articles' => BlogPost::query()->where($scope)->count(),
            'article_placements' => DossierBlogPost::query()->where($scope)->count(),
            'files' => DossierFile::query()->where($scope)->count(),
            'messages' => LoopMessage::query()->where($scope)->count(),
            'categories' => Category::query()->where($scope)->count(),
            'skills' => Skill::query()->where($scope)->count(),
            'service_requests' => ServiceRequest::query()->withoutGlobalScope(BelongsToOrganizationScope::class)->where($scope)->count(),
            'services' => Service::query()->withoutGlobalScope(BelongsToOrganizationScope::class)->where($scope)->count(),
            'polls' => LoopPoll::query()->where($scope)->count(),
            'events' => LoopEvent::query()->where($scope)->count(),
            'decisions' => LoopDecision::query()->where($scope)->count(),
            'roadmap_items' => LoopRoadmapItem::query()->where($scope)->count(),
            'registry' => ScenarioPackEntity::query()->where($scope)->count(),
        ];
    }
}
