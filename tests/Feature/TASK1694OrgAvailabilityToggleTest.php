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

        // TASK-1694 bug AI — une Organization PAR DEFAUT existe a cote, comme
        // dans la vraie base.
        //
        // C'est ce qui manquait a ce fixture, et c'est ce qui a rendu mes tests
        // du bug AE verts sur un defaut reel : sans Organization par defaut,
        // `currentOrganization()` ne pouvait pas retomber sur une AUTRE
        // Organization que celle du test. Un fixture qui ne reproduit pas la
        // topologie reelle ne mesure pas la realite.
        Organization::factory()->create([
            'slug' => 'org-1694ae-defaut',
            'is_active' => true,
            'is_default' => true,
            'availability_enabled' => true,
        ]);

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
     * TASK-1694 bug AF — la pastille de l'AVATAR, dans le rail.
     *
     * ## Ce que mon test du bug AE ne mesurait pas
     *
     * Il vérifiait `data-availability-toggle` — le **marqueur que j'avais
     * posé**, sur la bascule du menu déroulant. Le rail portait une **seconde**
     * pastille, sur l'avatar lui-même, et elle survivait à la désactivation.
     * Cyril l'a vue d'un coup d'œil sur une capture d'écran.
     *
     * Mesurer le marqueur qu'on vient d'ajouter ne prouve rien sur les
     * indicateurs qu'on n'a pas vus. *(Même famille que le bug X : un marqueur
     * prouve qu'un élément existe, jamais ce que la page affiche.)*
     */
    public function test_the_avatar_dot_follows_the_setting(): void
    {
        $url = route('organization.dashboard', ['organization' => $this->organization->slug]);

        $this->actif(true);
        $this->assertStringContainsString(
            'data-availability-dot',
            $this->actingAs($this->membre)->get($url)->assertOk()->getContent(),
        );

        $this->actif(false);
        $this->assertStringNotContainsString(
            'data-availability-dot',
            $this->actingAs($this->membre)->get($url)->assertOk()->getContent(),
        );
    }

    /**
     * Et elle DIT la vérité — c'est le second défaut, et le plus grave.
     *
     * Avant le bug AF, cette pastille ne lisait **pas** `is_available` : elle
     * était verte **en dur**, quelle que soit la disponibilité réelle. Un
     * indicateur qui ne mesure rien, posé juste au-dessus d'une bascule qui,
     * elle, dit la vérité : les deux se contredisaient à l'écran.
     *
     * Ce test mesure la valeur portée, pas la seule présence.
     */
    public function test_the_avatar_dot_reflects_the_real_value(): void
    {
        $url = route('organization.dashboard', ['organization' => $this->organization->slug]);
        $this->actif(true);

        $this->membre->update(['is_available' => true]);
        $this->assertStringContainsString(
            'data-availability-dot="available"',
            $this->actingAs($this->membre)->get($url)->assertOk()->getContent(),
        );

        $this->membre->update(['is_available' => false]);
        $this->assertStringContainsString(
            'data-availability-dot="unavailable"',
            $this->actingAs($this->membre)->get($url)->assertOk()->getContent(),
        );
    }

    /**
     * Et plus AUCUN indicateur de disponibilité dans le rail, désactivée.
     *
     * Le filet qui aurait attrapé le défaut du bug AF : il ne nomme pas un
     * marqueur, il mesure l'absence des **couleurs** qui servent à dire
     * « disponible » — y compris sur un élément que je n'aurais pas vu.
     */
    public function test_no_availability_indicator_survives_in_the_rail(): void
    {
        $this->actif(false);

        $html = $this->actingAs($this->membre)
            ->get(route('organization.dashboard', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();

        foreach (['data-availability-dot', 'data-availability-toggle', 'dashboard.available'] as $trace) {
            $this->assertStringNotContainsString($trace, $html);
        }

        $this->assertStringNotContainsString(
            __('dashboard.available'),
            $html,
            'aucun libelle de disponibilite ne doit subsister',
        );
    }

    /**
     * TASK-1694 bug AG — « Je suis disponible pour des échanges » disparaît
     * aussi sur la page de profil HORS TENANT.
     *
     * ## Pourquoi ce test, alors que le bug AE couvrait déjà le geste
     *
     * Cyril, le 24/10 : « il faut supprimer "Je suis disponible pour des
     * échanges" si on a désactivé la notion de disponibilité ». Mesure faite
     * avant d'écrire quoi que ce soit : **c'était déjà le cas**, sur les deux
     * routes.
     *
     * Mais une seule des deux était **mesurée**. `/profile/edit` n'est pas
     * préfixée par l'Organization, et `availabilityIsShown()` y lit
     * `currentOrganization()` — qui aurait pu être nulle, donc répondre `true`
     * et laisser le champ. Elle ne l'est pas : un middleware résout
     * l'Organization même hors préfixe. **Vérifié, pas supposé.**
     *
     * Un comportement juste mais non mesuré est un comportement qui peut
     * disparaître au prochain changement de routage sans que rien ne le dise.
     */
    public function test_the_availability_field_is_gone_on_the_non_tenant_profile_page(): void
    {
        // TASK-1694 bug AI — ce test etait VERT sur le defaut que Cyril a vu.
        //
        // Il ne creait pas d'Organization par defaut : `currentOrganization()`
        // n'avait donc nulle part ou retomber, et repondait pour la bonne
        // Organization par accident. Avec la topologie reelle (une Organization
        // par defaut qui, elle, a la fonctionnalite active), il rougit tant que
        // le helper ne lit pas l'Organization de la PERSONNE.
        $this->actif(false);

        $html = $this->actingAs($this->membre)
            ->get(route('profile.edit'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-availability-field', $html);
        $this->assertStringNotContainsString(__('profile.available_label'), $html);
    }

    /** Et il revient quand la fonctionnalité est active, sur cette route aussi. */
    public function test_the_availability_field_returns_when_enabled_outside_the_tenant(): void
    {
        $this->actif(true);

        $this->actingAs($this->membre)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('data-availability-field', false);
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
