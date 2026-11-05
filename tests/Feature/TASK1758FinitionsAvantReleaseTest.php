<?php

namespace Tests\Feature;

use App\Models\Badge;
use App\Models\Category;
use App\Models\Favorite;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use App\Support\Feed\FeedEventCatalogue;
use App\Support\Feed\FeedEventEmitter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TASK-1758 — les finitions exigees avant la release du redesign entraide.
 *
 * Trois choses, toutes constatees en preparant les releases :
 *
 * 1. **Le §9.1 en visait CINQ surfaces, trois seulement l'avaient eu.** Profil,
 *    favoris et recherche disaient encore « 30 pts » quand l'annonce disait
 *    « 30 min · 30 pts ». C'est la release entraide elle-meme qui affirme
 *    « plus aucune occurrence de l'ancien vocabulaire » : la livrer ainsi
 *    contredisait sa propre promesse.
 * 2. **`Badge::$fillable`** : j'avais conclu a tort en T1757 que la colonne
 *    `organization_id` n'existait pas. Elle existe. Le « nettoyage » est
 *    ANNULE, et ce fichier fixe l'etat reel plus la tension qui va avec.
 * 3. **`feed_auto_events_enabled` n'etait expose dans AUCUN ecran.** Les
 *    evenements automatiques ecrivent dans le Flux de toute une communaute, et
 *    rien ne permettait de les arreter.
 */
class TASK1758FinitionsAvantReleaseTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1758', 'is_active' => true, 'locale' => 'fr',
        ]);
        app()->instance('current_organization', $this->organization);

        $this->membre = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->superAdmin = User::factory()->create(['is_admin' => true]);
    }

    private function annonce(int $points = 30): Service
    {
        return Service::factory()->create([
            'user_id' => $this->membre->id,
            'organization_id' => $this->organization->id,
            'category_id' => Category::factory()->create(['organization_id' => $this->organization->id])->id,
            'status' => 'active',
            'points_cost' => $points,
        ]);
    }

    // ── §9.1 : les trois surfaces oubliees ───────────────────────────────────

    /**
     * Le profil, les favoris et la recherche disent le TEMPS.
     *
     * Les trois surfaces sont mesurees dans le MEME test : ce qui compte n'est
     * pas qu'une page donnee affiche « 30 min », c'est qu'aucune des cinq
     * surfaces du §9.1 ne parle plus un autre registre que les autres. Un test
     * par page aurait pu rester vert pendant qu'une quatrieme surface
     * divergeait.
     */
    public function test_les_trois_surfaces_oubliees_disent_desormais_le_temps(): void
    {
        $annonce = $this->annonce(points: 30);
        $lecteur = User::factory()->create(['organization_id' => $this->organization->id]);

        // `favorites()` est un `HasMany` sur `Favorite`, pas une relation
        // pivot : `attach()` n'existe pas dessus.
        Favorite::create([
            'user_id' => $lecteur->id,
            'service_id' => $annonce->id,
            'organization_id' => $this->organization->id,
        ]);

        $pages = [
            'profil' => route('profile.show', $this->membre),
            'favoris' => route('favorites.index'),
            'recherche' => route('search', ['q' => $annonce->title]),
        ];

        foreach ($pages as $nom => $url) {
            $html = $this->actingAs($lecteur)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('data-entraide-temps', $html,
                "La surface « {$nom} » ne porte pas le marqueur du temps partage.");
            $this->assertStringContainsString('30 min', $html,
                "La surface « {$nom} » n'affiche pas le temps.");
            $this->assertStringNotContainsString('>30 pts<', $html,
                "La surface « {$nom} » affiche encore le prix seul.");
        }
    }

    // ── Badge : la colonne qui n'existait pas ────────────────────────────────

    /**
     * 🔴 CE QUE J'AVAIS CONCLU A TORT EN T1757.
     *
     * La fiche T1757 affirmait que `Badge::$fillable` declarait une colonne
     * inexistante. **C'etait faux** : je n'avais lu que la migration de
     * CREATION (`2026_05_01_130000`) et pas cherche de migration ulterieure.
     * `badges.organization_id` a bien ete ajoutee par la campagne
     * multi-tenant (`2026_06_02_000020_add_organization_id_to_p2_tables`),
     * nullable, avec cle etrangere et index.
     *
     * Le « nettoyage » que j'ai failli livrer etait donc une REGRESSION :
     * retirer une colonne reelle de `$fillable` et supprimer une relation qui
     * marche. Annule.
     *
     * Ce test fixe l'etat REEL, et la tension qui va avec : la colonne existe,
     * mais `key` est `unique()` GLOBALEMENT (migration de creation, jamais
     * modifiee). Deux Organizations ne peuvent donc pas avoir un badge de meme
     * cle — **les badges par communaute sont a moitie cables**. C'est ce
     * constat, et non une colonne morte, qu'une future TASK devra traiter.
     *
     * Lecon de methode : `grep` sur la migration de creation ne dit rien du
     * schema COURANT. La seule autorite est `Schema::getColumnListing()`.
     */
    public function test_la_colonne_organization_id_des_badges_existe_et_la_cle_reste_globalement_unique(): void
    {
        $this->assertTrue(Schema::hasColumn('badges', 'organization_id'),
            'La colonne existe depuis la campagne multi-tenant : ne pas la retirer de $fillable.');

        $this->assertContains('organization_id', (new Badge)->getFillable());

        // La tension : `key` unique GLOBALEMENT empeche deux Organizations
        // d'avoir un badge de meme cle. Mesure, pas supposition.
        $cle = 'badge_de_test_unicite';
        Badge::create(['key' => $cle, 'name' => 'A', 'description' => 'A', 'icon' => '🅰', 'color' => '#000000',
            'organization_id' => $this->organization->id]);

        $autre = Organization::factory()->create(['slug' => 'org-1758-bis', 'is_active' => true]);

        $this->expectException(UniqueConstraintViolationException::class);

        Badge::create(['key' => $cle, 'name' => 'B', 'description' => 'B', 'icon' => '🅱', 'color' => '#111111',
            'organization_id' => $autre->id]);
    }

    // ── L'interrupteur des evenements automatiques ───────────────────────────

    /** Le SuperAdmin peut mettre en pause, et REACTIVER. */
    public function test_le_superadmin_met_en_pause_puis_reactive_les_evenements(): void
    {
        $this->assertTrue((bool) $this->organization->fresh()->feed_auto_events_enabled,
            'Le defaut de la migration T1752 est ACTIF.');

        $this->actingAs($this->superAdmin)
            ->post(route('admin.feed.auto-events'), ['organization_id' => $this->organization->id, 'actif' => 0])
            ->assertSessionHas('success');

        $this->assertFalse((bool) $this->organization->fresh()->feed_auto_events_enabled);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.feed.auto-events'), ['organization_id' => $this->organization->id, 'actif' => 1])
            ->assertSessionHas('success');

        $this->assertTrue((bool) $this->organization->fresh()->feed_auto_events_enabled);
    }

    /**
     * 🔴 CE QUE L'INTERRUPTEUR ACHETE VRAIMENT : plus aucune emission.
     *
     * Sans cette mesure, le test precedent ne prouverait qu'une ecriture de
     * colonne. Ce qui compte est que `FeedEventEmitter` cesse d'ecrire —
     * c'est tout l'objet du bouton.
     */
    public function test_en_pause_plus_aucun_evenement_n_est_emis(): void
    {
        $emetteur = app(FeedEventEmitter::class);
        $annonce = $this->annonce();

        $this->organization->update(['feed_auto_events_enabled' => false]);

        $this->assertNull(
            $emetteur->emettre(FeedEventCatalogue::PROPOSITION_PUBLIEE, $annonce, $this->membre, $this->organization),
            'En pause, aucune ligne ne doit etre ecrite.'
        );
        $this->assertDatabaseMissing('feed_posts', ['object_id' => $annonce->id]);

        // La RECIPROQUE : reactive, l'emission repart.
        $this->organization->update(['feed_auto_events_enabled' => true]);

        $this->assertNotNull(
            $emetteur->emettre(FeedEventCatalogue::PROPOSITION_PUBLIEE, $annonce, $this->membre, $this->organization->fresh())
        );
        $this->assertDatabaseHas('feed_posts', ['object_id' => $annonce->id]);
    }

    /**
     * Mettre en pause n'efface RIEN.
     *
     * C'est ce que dit le libelle, et les deux gestes sont distincts a dessein :
     * « retirer » nettoie l'existant, l'interrupteur arrete les suivants. Si la
     * pause effacait, le libelle mentirait.
     */
    public function test_mettre_en_pause_n_efface_pas_l_historique(): void
    {
        $annonce = $this->annonce();
        app(FeedEventEmitter::class)->emettre(
            FeedEventCatalogue::PROPOSITION_PUBLIEE, $annonce, $this->membre, $this->organization
        );
        $this->assertDatabaseHas('feed_posts', ['object_id' => $annonce->id]);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.feed.auto-events'), ['organization_id' => $this->organization->id, 'actif' => 0]);

        $this->assertDatabaseHas('feed_posts', ['object_id' => $annonce->id]);
    }

    /** L'ecran dit lequel des deux etats est en cours. */
    public function test_la_page_d_admin_affiche_l_etat_et_le_bouton(): void
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.feed', ['organization_id' => $this->organization->id]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-marqueur="evenements-auto-bascule"', $html);
        $this->assertStringContainsString('ACTIFS', $html);

        $this->organization->update(['feed_auto_events_enabled' => false]);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.feed', ['organization_id' => $this->organization->id]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('EN PAUSE', $html);
    }

    /** Un membre ordinaire n'approche pas de cet interrupteur. */
    public function test_un_membre_ordinaire_ne_peut_pas_basculer_l_interrupteur(): void
    {
        $this->actingAs($this->membre)
            ->post(route('admin.feed.auto-events'), ['organization_id' => $this->organization->id, 'actif' => 0])
            ->assertForbidden();

        $this->assertTrue((bool) $this->organization->fresh()->feed_auto_events_enabled);
    }
}
