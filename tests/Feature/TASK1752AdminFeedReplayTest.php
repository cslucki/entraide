<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\FeedPost;
use App\Models\FeedPostComment;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\Reaction;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\Feed\FeedEventCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1752 (§8.4.6, demande Cyril) — l'outil de reprise dans
 * `/admin/flux`, par Organization.
 *
 * Le moteur (sources, idempotence, antidatage) est deja garde par
 * `TASK1752FluxHistoryReplayTest`, contre la commande Artisan. Ce fichier ne
 * mesure que ce que la SURFACE ADMIN ajoute : l'autorite plateforme, la
 * selection de types, et les deux gardes signalees par le CDC (§3.6 —
 * erreur de validation visible ; §3.3 — rien ne doit etre « toutes »).
 */
class TASK1752AdminFeedReplayTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['slug' => 'org-replay-1752', 'is_active' => true]);

        $this->superAdmin = User::factory()->create(['is_admin' => true]);

        $this->membre = User::factory()->create(['organization_id' => $this->organization->id]);

        ServiceRequest::factory()->create([
            'user_id' => $this->membre->id,
            'organization_id' => $this->organization->id,
            'category_id' => Category::factory()->create(['organization_id' => $this->organization->id])->id,
            'status' => 'open',
        ]);
    }

    /** Un SuperAdmin genere l'historique d'une Organization nommee. */
    public function test_generer_ecrit_les_lignes_manquantes(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.feed.replay'), [
                'organization_id' => $this->organization->id,
                'action' => 'reprendre',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, FeedPost::where('type', FeedEventCatalogue::DEMANDE_PUBLIEE)->count());
    }

    /** Relance : AUCUNE seconde ligne pour le meme fait. */
    public function test_generer_deux_fois_ne_double_rien(): void
    {
        $route = route('admin.feed.replay');
        $this->actingAs($this->superAdmin)->post($route, ['organization_id' => $this->organization->id, 'action' => 'reprendre']);
        $this->actingAs($this->superAdmin)->post($route, ['organization_id' => $this->organization->id, 'action' => 'reprendre']);

        $this->assertSame(1, FeedPost::where('type', FeedEventCatalogue::DEMANDE_PUBLIEE)->count());
    }

    /**
     * Supprimer UN SEUL type coche ne touche pas les autres.
     *
     * C'est la demande de Cyril : « sélectionner tous les évènements d'un
     * flux donné … et pouvoir tout supprimer ». Le test verifie le cas qui
     * prouve que la selection est reelle — supprimer un type et voir l'autre
     * survivre — pas seulement « tout disparait ».
     */
    public function test_retirer_un_type_coche_laisse_les_autres_intacts(): void
    {
        $boucle = Loop::factory()->create([
            'organization_id' => $this->organization->id, 'visibility' => 'public',
            'status' => 'active', 'created_by' => $this->membre->id,
        ]);

        app(\App\Support\Feed\FeedEventEmitter::class)->emettre(
            FeedEventCatalogue::BOUCLE_CREEE, $boucle, $this->membre, $this->organization,
        );

        $this->actingAs($this->superAdmin)->post(route('admin.feed.replay'), [
            'organization_id' => $this->organization->id, 'action' => 'reprendre',
        ]);

        $this->assertSame(1, FeedPost::where('type', FeedEventCatalogue::DEMANDE_PUBLIEE)->count());
        $this->assertSame(1, FeedPost::where('type', FeedEventCatalogue::BOUCLE_CREEE)->count());

        $this->actingAs($this->superAdmin)->post(route('admin.feed.replay'), [
            'organization_id' => $this->organization->id,
            'action' => 'retirer',
            'types' => [FeedEventCatalogue::DEMANDE_PUBLIEE],
        ])->assertSessionHas('success');

        $this->assertSame(0, FeedPost::where('type', FeedEventCatalogue::DEMANDE_PUBLIEE)->count(),
            'Le type coche aurait du etre retire.');
        $this->assertSame(1, FeedPost::where('type', FeedEventCatalogue::BOUCLE_CREEE)->count(),
            'Un type NON coche a ete retire — la selection n\'est pas respectee.');
    }

    /**
     * Supprimer emporte commentaires ET reactions — rien d'orphelin.
     *
     * `reactions` est un morph SANS cle etrangere : sans le retrait explicite
     * du controleur, cette garde rougirait.
     */
    public function test_retirer_emporte_commentaires_et_reactions(): void
    {
        $this->actingAs($this->superAdmin)->post(route('admin.feed.replay'), [
            'organization_id' => $this->organization->id, 'action' => 'reprendre',
        ]);

        $ligne = FeedPost::where('type', FeedEventCatalogue::DEMANDE_PUBLIEE)->firstOrFail();

        FeedPostComment::create([
            'feed_post_id' => $ligne->id, 'organization_id' => $this->organization->id,
            'user_id' => $this->membre->id, 'content' => 'un commentaire', 'is_approved' => true,
        ]);
        Reaction::create([
            'organization_id' => $this->organization->id, 'user_id' => $this->membre->id,
            'reactionable_id' => $ligne->id, 'reactionable_type' => FeedPost::class,
            'reaction_type' => Reaction::REACTION_TYPES[0],
        ]);

        $this->actingAs($this->superAdmin)->post(route('admin.feed.replay'), [
            'organization_id' => $this->organization->id,
            'action' => 'retirer',
            'types' => [FeedEventCatalogue::DEMANDE_PUBLIEE],
        ]);

        $this->assertSame(0, FeedPostComment::where('feed_post_id', $ligne->id)->count());
        $this->assertSame(0, Reaction::where('reactionable_id', $ligne->id)->count(),
            'La reaction est devenue orpheline : morph sans FK, elle devait etre retiree a la main.');
    }

    /**
     * Les annonces HUMAINES ne sont jamais vises, meme en retirant « tous ».
     *
     * Le CDC est explicite : `FeedHistoryReplay::retirer()` refait
     * l'intersection avec le registre, donc un type bricole dans la requete
     * ne peut rien atteindre d'autre.
     */
    public function test_retirer_n_emporte_jamais_une_annonce_humaine(): void
    {
        $annonce = FeedPost::create([
            'organization_id' => $this->organization->id, 'user_id' => $this->membre->id,
            'type' => FeedPost::TYPE_ANNOUNCEMENT, 'content' => 'Une vraie annonce.',
            'status' => FeedPost::STATUS_PUBLISHED, 'published_at' => now(),
        ]);

        $this->actingAs($this->superAdmin)->post(route('admin.feed.replay'), [
            'organization_id' => $this->organization->id,
            'action' => 'retirer',
            // Tentative explicite de viser un type hors registre.
            'types' => [FeedPost::TYPE_ANNOUNCEMENT],
        ]);

        $this->assertNotNull(FeedPost::find($annonce->id), 'Une annonce humaine a ete emportee par le retrait.');
    }

    /**
     * §3.6 du CDC — « aucun type coché » doit retourner une erreur VISIBLE,
     * jamais un echec silencieux.
     */
    public function test_retirer_sans_aucun_type_coche_renvoie_une_erreur_visible(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.feed.replay'), [
                'organization_id' => $this->organization->id,
                'action' => 'retirer',
                'types' => [],
            ])
            ->assertSessionHas('error');
    }

    /** §3.3 du CDC — l'Organization est OBLIGATOIRE, « toutes » n'existe pas. */
    public function test_sans_organization_la_requete_est_refusee(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.feed.replay'), ['action' => 'reprendre'])
            ->assertSessionHasErrors('organization_id');
    }

    /** Un administrateur d'Organization — pas le SuperAdmin plateforme — est refuse. */
    public function test_un_administrateur_d_organization_est_refuse(): void
    {
        $adminOrg = User::factory()->create(['organization_id' => $this->organization->id, 'is_admin' => false]);
        $this->organization->forceFill(['admin_id' => $adminOrg->id])->save();

        $this->actingAs($adminOrg)
            ->post(route('admin.feed.replay'), [
                'organization_id' => $this->organization->id,
                'action' => 'reprendre',
            ])
            ->assertForbidden();
    }

    /** La page elle-meme montre le panneau d'une Organization filtree. */
    public function test_la_page_montre_le_panneau_pour_l_organization_filtree(): void
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.feed', ['organization_id' => $this->organization->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-marqueur="reprise-historique"', $html);
        $this->assertStringContainsString('data-marqueur="reprise-types"', $html);
    }

    /** Sans filtre, l'invite remplace le panneau — pas de compte a l'aveugle sur 3 tenants. */
    public function test_la_page_sans_filtre_n_affiche_pas_le_panneau(): void
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.feed'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-marqueur="reprise-invite"', $html);
        $this->assertStringNotContainsString('data-marqueur="reprise-historique"', $html);
    }
}
