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
 * TASK-1752 (§8.4) — la reprise d'historique.
 *
 * Trois gardes, et chacune ferme un piege que le CDC a nomme :
 *
 * 1. **rejouable** — deux passes ne creent aucun doublon ;
 * 2. **antidatee honnetement** — `published_at` porte la vraie date du fait,
 *    `created_at` dit la verite sur le moment de l'ecriture, et rien n'est
 *    dans le futur ;
 * 3. **jamais destructive** — une ligne qui porte deja une reaction et un
 *    commentaire les CONSERVE apres une seconde passe. C'est la garde qui
 *    ferme le piege du §8.4.5 : un « recreer » destructif effacerait les
 *    commentaires par cascade FK et orphelinerait les reactions.
 */
class TASK1752FluxHistoryReplayTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['slug' => 'org-rep-1752', 'is_active' => true]);
        app()->instance('current_organization', $this->organization);

        $this->membre = User::factory()->create(['organization_id' => $this->organization->id]);
    }

    private function demandeAncienne(\DateTimeInterface $quand): ServiceRequest
    {
        $demande = ServiceRequest::factory()->create([
            'user_id' => $this->membre->id,
            'organization_id' => $this->organization->id,
            'category_id' => Category::factory()->create(['organization_id' => $this->organization->id])->id,
            'status' => 'open',
        ]);

        // L'emission du controleur n'a pas eu lieu (creation directe) : on
        // simule un objet deja ancien, comme la base reelle en contient.
        $demande->forceFill(['created_at' => $quand])->saveQuietly();

        return $demande->fresh();
    }

    private function reprendre(array $options = []): int
    {
        return $this->artisan('flux:reprendre-historique', array_merge([
            '--organization' => $this->organization->slug,
            '--presentations' => 'ignorer',
        ], $options))->run();
    }

    public function test_la_reprise_antidate_sur_published_at_et_dit_la_verite_sur_created_at(): void
    {
        $quand = now()->subMonths(5)->startOfDay();
        $this->demandeAncienne($quand);

        $this->reprendre();

        $ligne = FeedPost::where('type', FeedEventCatalogue::DEMANDE_PUBLIEE)->firstOrFail();

        $this->assertSame($quand->toDateString(), $ligne->published_at->toDateString(),
            'La vraie date du fait doit aller dans `published_at` — c\'est la colonne que le Flux trie.');

        $this->assertSame(now()->toDateString(), $ligne->created_at->toDateString(),
            '`created_at` doit garder le moment REEL de l\'ecriture : aucune colonne ne ment.');

        $this->assertFalse($ligne->published_at->isFuture());
    }

    public function test_deux_passes_ne_creent_aucun_doublon(): void
    {
        $this->demandeAncienne(now()->subMonths(2));

        $this->reprendre();
        $this->reprendre();

        $this->assertSame(1, FeedPost::where('type', FeedEventCatalogue::DEMANDE_PUBLIEE)->count());
    }

    /**
     * LA garde du §8.4.5 : la reprise ne detruit rien.
     *
     * Un « recreer » destructif effacerait les commentaires par cascade FK et
     * laisserait les reactions orphelines — `reactions` est un morph SANS FK.
     * Cette garde verifie qu'une seconde passe laisse les deux intacts.
     */
    public function test_une_seconde_passe_conserve_reactions_et_commentaires(): void
    {
        $this->demandeAncienne(now()->subMonth());
        $this->reprendre();

        $ligne = FeedPost::where('type', FeedEventCatalogue::DEMANDE_PUBLIEE)->firstOrFail();

        FeedPostComment::create([
            'feed_post_id' => $ligne->id,
            'organization_id' => $this->organization->id,
            'user_id' => $this->membre->id,
            'content' => 'Un commentaire a ne pas perdre.',
            'is_approved' => true,
        ]);

        Reaction::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->membre->id,
            'reactionable_id' => $ligne->id,
            'reactionable_type' => FeedPost::class,
            'reaction_type' => Reaction::REACTION_TYPES[0],
        ]);

        $this->reprendre();

        $this->assertSame(1, FeedPostComment::where('feed_post_id', $ligne->id)->count(),
            'Une seconde passe a efface un commentaire de membre.');
        $this->assertSame(1, Reaction::where('reactionable_id', $ligne->id)->count(),
            'Une seconde passe a efface une reaction.');
    }

    /** `--dry-run` n'ecrit RIEN. */
    public function test_a_blanc_n_ecrit_rien(): void
    {
        $this->demandeAncienne(now()->subMonth());

        $this->reprendre(['--dry-run' => true]);

        $this->assertSame(0, FeedPost::whereIn('type', FeedEventCatalogue::clefs())->count());
    }

    /** Sans `--organization`, la commande refuse : trois tenants, trois decisions. */
    public function test_l_organization_est_obligatoire(): void
    {
        $this->artisan('flux:reprendre-historique')->assertExitCode(1);
    }

    /**
     * Le `owner` d'une Boucle n'est pas repris comme « adhesion ».
     *
     * Sinon chaque Boucle produirait DEUX lignes : « X a cree » et
     * « X a rejoint ».
     */
    public function test_le_proprietaire_d_une_boucle_n_est_pas_repris_comme_adhesion(): void
    {
        $boucle = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'visibility' => 'public',
            'status' => 'active',
            'created_by' => $this->membre->id,
        ]);

        \App\Models\LoopMember::create([
            'loop_id' => $boucle->id,
            'user_id' => $this->membre->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now()->subMonth(),
            'organization_id' => $this->organization->id,
        ]);

        $this->reprendre();

        $this->assertSame(0, FeedPost::where('type', FeedEventCatalogue::ADHESION_BOUCLE)->count());
        $this->assertSame(1, FeedPost::where('type', FeedEventCatalogue::BOUCLE_CREEE)->count());
    }
}
