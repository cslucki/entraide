<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1694, bug AE — la disponibilité des membres, activable par Organization.
 *
 * ## La demande
 *
 * Cyril : « ajouter une case à cocher au niveau du management des organisations
 * [...]. Si elle n'est pas active, on doit supprimer l'affichage [...] dans le
 * menu utilisateur, sur le profil utilisateur, dans l'annuaire, **et tous les
 * autres endroits** où ce petit point vert ou rouge. »
 *
 * ## « Et tous les autres endroits » : l'inventaire en a trouvé CINQ
 *
 * Trois nommés, deux de plus relevés le 24/10 :
 *
 *   1. le menu utilisateur — rail gauche **et** barre mobile (deux copies) ;
 *   2. le profil public ;
 *   3. l'annuaire des membres ;
 *   4. **la fiche d'un Service**, qui affiche la pastille de son auteur ;
 *   5. **le réglage du profil**, qui laisse régler la valeur.
 *
 * Une condition recopiée cinq fois aurait fini par diverger — et c'est justement
 * la surface oubliée qui aurait gardé le point vert. D'où une lecture unique,
 * `availabilityIsShown()`, et un test qui les parcourt toutes.
 */
class TASK1694OrgAvailabilityToggleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1694ae',
            'is_active' => true,
        ]);

        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'is_available' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function actif(bool $actif): void
    {
        $this->organization->update(['availability_enabled' => $actif]);
    }

    /** @return array<string, string> url => marqueur attendu */
    private function surfaces(): array
    {
        return [
            'annuaire' => route('organization.members.index', ['organization' => $this->organization->slug]),
            'profil' => route('organization.profile.show', [
                'organization' => $this->organization->slug,
                'user' => $this->membre->profileRouteKey(),
            ]),
        ];
    }

    /** La case existe, et elle est COCHÉE par défaut. */
    public function test_the_feature_is_enabled_by_default(): void
    {
        $this->assertTrue($this->organization->fresh()->availabilityEnabled());
    }

    /**
     * C'est l'inverse du bug R, et c'est délibéré.
     *
     * La case du bug R retirait une capacité, donc naissait décochée. Celle-ci
     * gouverne une fonctionnalité qui existe et fonctionne : un défaut à « non »
     * l'aurait retirée en silence de toutes les Organizations déjà en service.
     */
    public function test_an_existing_organization_keeps_the_feature(): void
    {
        $autre = Organization::factory()->create(['slug' => 'org-1694ae-2', 'is_active' => true]);

        $this->assertTrue($autre->availabilityEnabled());
    }

    /** Active : la pastille est là, sur l'annuaire et le profil. */
    public function test_when_enabled_the_dot_is_shown(): void
    {
        $this->actif(true);

        foreach ($this->surfaces() as $nom => $url) {
            $html = $this->actingAs($this->membre)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString(
                'bg-green-500',
                $html,
                "la pastille doit etre visible sur « {$nom} »",
            );
        }
    }

    /** Désactivée : elle disparaît des mêmes surfaces. */
    public function test_when_disabled_the_dot_disappears(): void
    {
        $this->actif(false);

        foreach ($this->surfaces() as $nom => $url) {
            $html = $this->actingAs($this->membre)->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString(
                'bg-green-500',
                $html,
                "la pastille ne doit plus apparaitre sur « {$nom} »",
            );
        }
    }

    /**
     * La fiche d'un Service aussi — surface que la demande ne nommait pas.
     *
     * C'est elle qui justifie la lecture unique : écrite à la main cinq fois,
     * c'est celle-là qu'on oublie.
     */
    public function test_a_service_page_follows_the_setting(): void
    {
        $service = Service::factory()->create([
            'user_id' => $this->membre->id,
            'organization_id' => $this->organization->id,
        ]);

        $url = route('organization.services.show', [
            'organization' => $this->organization->slug,
            'service' => $service->id,
        ]);

        $this->actif(true);
        $this->assertStringContainsString(
            'bg-green-500',
            $this->actingAs($this->membre)->get($url)->assertOk()->getContent(),
        );

        $this->actif(false);
        $this->assertStringNotContainsString(
            'bg-green-500',
            $this->actingAs($this->membre)->get($url)->assertOk()->getContent(),
        );
    }

    /**
     * La bascule du menu disparaît aussi.
     *
     * Laisser un bouton qui change une valeur que plus aucun écran n'affiche
     * serait un geste sans effet visible — donc un piège.
     */
    public function test_the_menu_toggle_disappears(): void
    {
        $url = route('organization.dashboard', ['organization' => $this->organization->slug]);

        $this->actif(true);
        $this->assertStringContainsString(
            'data-availability-toggle',
            $this->actingAs($this->membre)->get($url)->assertOk()->getContent(),
        );

        $this->actif(false);
        $this->assertStringNotContainsString(
            'data-availability-toggle',
            $this->actingAs($this->membre)->get($url)->assertOk()->getContent(),
        );
    }

    /** Et le réglage du profil aussi. */
    public function test_the_profile_setting_disappears(): void
    {
        $url = route('organization.profile.edit', ['organization' => $this->organization->slug]);

        $this->actif(true);
        $this->assertStringContainsString(
            'data-availability-field',
            $this->actingAs($this->membre)->get($url)->assertOk()->getContent(),
        );

        $this->actif(false);
        $this->assertStringNotContainsString(
            'data-availability-field',
            $this->actingAs($this->membre)->get($url)->assertOk()->getContent(),
        );
    }

    /**
     * Désactiver n'EFFACE pas la donnée.
     *
     * C'est un retrait d'affichage, pas une suppression : réactiver doit rendre
     * exactement l'état d'avant. Sinon une case décochée par erreur coûterait
     * le réglage de chaque membre.
     */
    public function test_disabling_does_not_erase_the_value(): void
    {
        $this->actif(false);

        $this->assertTrue((bool) $this->membre->fresh()->is_available);

        $this->actif(true);

        $this->assertStringContainsString(
            'bg-green-500',
            $this->actingAs($this->membre)
                ->get(route('organization.members.index', ['organization' => $this->organization->slug]))
                ->assertOk()
                ->getContent(),
        );
    }
}
