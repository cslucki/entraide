<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Organization;
use App\Models\User;
use Tests\TestCase;

/**
 * TASK-1689 (P1) — la configuration de l'Organization de l'URL ne gouverne plus
 * le profil de l'appelant.
 *
 * ## Le defaut, tel qu'il a ete mesure
 *
 * `ProfileController::edit()` et `::update()` lisaient toutes deux
 * `currentOrganization() ?? $request->user()->organization` — l'URL D'ABORD. Or
 * cette Organization decide si `membership_value` est un champ ACCEPTE (regles
 * de validation) et s'il est RENDU, avec quel libelle.
 *
 * `organization.profile.edit` et `organization.profile.update` ne portent pas
 * `EnsureOrganizationMember` — a la difference de `organization.profile.show`.
 *
 * | contexte | `membership_enabled` lu | resultat |
 * |---|---|---|
 * | son organisation A | `false` | `membership_value` ignore |
 * | URL de l'organisation B | `true` | `membership_value` **ECRIT** |
 *
 * Un reglage que son Organization avait deliberement DESACTIVE devenait
 * accessible en changeant un segment d'URL.
 *
 * ## Ce que cette suite mesure, et comment
 *
 * Jamais un code HTTP seul : chaque cas verifie **l'etat reel des donnees**
 * apres la requete, et prouve au passage que la requete a bien ETE EXECUTEE
 * (un champ ordinaire est ecrit). Un 302 qui n'ecrirait rien ne prouverait
 * aucune protection — la lecon de TASK-1688.
 *
 * Les deux sens sont couverts : une Organization etrangere ne peut ni OUVRIR
 * un champ que la sienne a ferme, ni FERMER un champ que la sienne a ouvert.
 */
class TASK1689ProfileOrganizationGovernanceTest extends TestCase
{
    // =====================================================================
    // A. Le coeur : l'Organization etrangere ne gouverne plus rien
    // =====================================================================

    /** Le defaut lui-meme : B activait le champ, A l'avait desactive. */
    public function test_a_a_foreign_organization_cannot_open_a_field_his_own_has_closed(): void
    {
        [$a, $b] = $this->deuxOrganizations(sienneActivee: false, etrangereActivee: true);
        $membre = $this->membre($a, ['bio' => 'Bio initiale TASK1689']);

        $reponse = $this->actingAs($membre)->put(
            route('organization.profile.update', ['organization' => $b->slug]),
            $this->chargeValide($membre, [
                'membership_value' => 'INJECTE-VIA-B-TASK1689',
                'bio' => 'Requete reellement executee.',
            ]),
        );

        $reponse->assertNotFound();
        $membre->refresh();

        // Le garde tenant refuse avant le controleur : aucun champ du profil
        // ne doit etre ecrit depuis une Organization etrangere.
        $this->assertSame('Bio initiale TASK1689', $membre->bio, 'le profil ne doit pas etre modifie via un slug etranger');

        $this->assertNull($membre->membership_value,
            'la configuration de l\'Organization ETRANGERE a ouvert un champ que la sienne refuse');
    }

    /** L'autre sens : B ne peut pas neutraliser un droit que A accorde. */
    public function test_a_a_foreign_organization_cannot_close_a_field_his_own_has_opened(): void
    {
        [$a, $b] = $this->deuxOrganizations(sienneActivee: true, etrangereActivee: false);
        $membre = $this->membre($a);

        $this->actingAs($membre)->put(
            route('organization.profile.update', ['organization' => $b->slug]),
            $this->chargeValide($membre, ['membership_value' => 'VALEUR-LEGITIME-TASK1689']),
        )->assertNotFound();

        $this->assertNull($membre->refresh()->membership_value,
            'un slug etranger ne doit pas modifier le profil');
    }

    /** Dans son propre tenant : les deux etats, inchanges. */
    public function test_a_in_his_own_tenant_both_states_are_unchanged(): void
    {
        [$a] = $this->deuxOrganizations(sienneActivee: true, etrangereActivee: false);
        $membre = $this->membre($a);

        $this->actingAs($membre)->put(
            route('organization.profile.update', ['organization' => $a->slug]),
            $this->chargeValide($membre, ['membership_value' => 'VALEUR-CHEZ-SOI']),
        )->assertRedirect();

        $this->assertSame('VALEUR-CHEZ-SOI', $membre->refresh()->membership_value);

        // Et quand SON Organization ferme le champ, il reste ferme chez elle.
        $a->update(['membership_enabled' => false]);

        // `actingAs()` conserve la MEME instance `User` d'une requete a
        // l'autre, et sa relation `organization` a ete chargee — donc mise en
        // cache — par la requete precedente. Une requete HTTP reelle recharge
        // l'utilisateur depuis la session : `fresh()` modelise cela. Mesure
        // faite : sans ce `fresh()`, le controleur relisait l'etat d'AVANT
        // `update()`. Artefact de banc, pas de production.
        $membre = $membre->fresh();

        $this->actingAs($membre)->put(
            route('organization.profile.update', ['organization' => $a->slug]),
            $this->chargeValide($membre, ['membership_value' => 'TENTATIVE-APRES-FERMETURE']),
        )->assertRedirect();

        $this->assertSame('VALEUR-CHEZ-SOI', $membre->refresh()->membership_value,
            'son Organization a ferme le champ, il reste pourtant modifiable');
    }

    // =====================================================================
    // B. L'ecran : le formulaire ne rend plus le champ de l'etrangere
    // =====================================================================

    public function test_b_the_form_under_a_foreign_slug_renders_his_own_configuration(): void
    {
        [$a, $b] = $this->deuxOrganizations(sienneActivee: false, etrangereActivee: true);
        $b->update(['membership_label_fr' => 'Cotisation-de-B-TASK1689', 'membership_label_en' => 'Cotisation-de-B-TASK1689']);
        $membre = $this->membre($a);

        $html = $this->actingAs($membre)
            ->get(route('organization.profile.edit', ['organization' => $b->slug]))
            ->assertNotFound()
            ->getContent();

        $this->assertStringNotContainsString('name="membership_value"', $html,
            'le formulaire rend un champ gouverne par l\'Organization etrangere');
        $this->assertStringNotContainsString(e('Cotisation-de-B-TASK1689'), $html,
            'le LIBELLE de l\'Organization etrangere est affiche');
    }

    public function test_b_the_form_in_his_own_tenant_still_renders_his_field(): void
    {
        [$a] = $this->deuxOrganizations(sienneActivee: true, etrangereActivee: false);
        $a->update(['membership_label_fr' => 'Cotisation-de-A-TASK1689', 'membership_label_en' => 'Cotisation-de-A-TASK1689']);
        $membre = $this->membre($a);

        $html = $this->actingAs($membre)
            ->get(route('organization.profile.edit', ['organization' => $a->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="membership_value"', $html);
        $this->assertStringContainsString(e('Cotisation-de-A-TASK1689'), $html);
    }

    // =====================================================================
    // C. Route NON PREFIXEE et compte sans Organization
    // =====================================================================

    /**
     * La route courte `/profile` est celle de l'Organization PAR DEFAUT. Le
     * parcours legitime — un membre de cette Organization — reste intact.
     */
    public function test_c_the_unprefixed_route_keeps_working_for_a_member_of_the_default_organization(): void
    {
        $defaut = Organization::factory()->create([
            'slug' => 'org-1689-defaut', 'is_active' => true, 'is_public' => true,
            'is_default' => true, 'membership_enabled' => true,
        ]);
        $membre = $this->membre($defaut);

        $this->oublierOrganisation();

        $this->actingAs($membre)->put(
            route('profile.update'),
            $this->chargeValide($membre, ['membership_value' => 'VALEUR-ROUTE-COURTE']),
        )->assertRedirect();

        $this->assertSame('VALEUR-ROUTE-COURTE', $membre->refresh()->membership_value,
            'le parcours legitime de la route non prefixee a ete casse');
    }

    /**
     * Et un membre d'une AUTRE Organization n'herite pas de la configuration de
     * l'Organization par defaut par la route courte. La redirection canonique
     * de TASK-1601 est inchangee ; ce qui est mesure ici est l'ETAT DES
     * DONNEES, quel que soit le code renvoye.
     */
    public function test_c_the_unprefixed_route_does_not_lend_the_default_configuration(): void
    {
        Organization::factory()->create([
            'slug' => 'org-1689-defaut-2', 'is_active' => true, 'is_public' => true,
            'is_default' => true, 'membership_enabled' => true,
        ]);
        $sienne = Organization::factory()->create([
            'slug' => 'org-1689-sienne', 'is_active' => true, 'is_public' => true,
            'membership_enabled' => false,
        ]);
        $membre = $this->membre($sienne);

        $this->oublierOrganisation();

        $this->actingAs($membre)->put(
            route('profile.update'),
            $this->chargeValide($membre, ['membership_value' => 'INJECTE-VIA-DEFAUT']),
        );

        $this->assertNull($membre->refresh()->membership_value,
            'l\'Organization par defaut a prete sa configuration par la route courte');
    }

    /**
     * `users.organization_id` est NULLABLE. Pour un compte sans Organization,
     * le contrat existant est le bon : le champ n'est pas accepte. Ce test
     * interdit de reintroduire un repli `?? currentOrganization()`, qui
     * rouvrirait le trou precisement pour ces comptes.
     */
    public function test_c_a_user_without_organization_is_not_granted_the_field_by_the_url(): void
    {
        [, $b] = $this->deuxOrganizations(sienneActivee: false, etrangereActivee: true);

        $apatride = User::factory()->complete()->create(['organization_id' => null, 'bio' => 'Bio apatride initiale']);

        $this->actingAs($apatride)->put(
            route('organization.profile.update', ['organization' => $b->slug]),
            $this->chargeValide($apatride, [
                'membership_value' => 'INJECTE-SANS-ORG',
                'bio' => 'Requete reellement executee.',
            ]),
        )->assertNotFound();

        $apatride->refresh();

        $this->assertSame('Bio apatride initiale', $apatride->bio, 'un compte sans Organization ne doit pas etre modifie via un slug');
        $this->assertNull($apatride->membership_value,
            'un compte sans Organization herite de la configuration de l\'URL');
    }

    // =====================================================================
    // D. Rien, dans l'Organization etrangere, n'est touche
    // =====================================================================

    public function test_d_nothing_in_the_foreign_organization_is_touched(): void
    {
        [$a, $b] = $this->deuxOrganizations(sienneActivee: false, etrangereActivee: true);
        $b->update(['membership_label_fr' => 'Cotisation-de-B-intacte']);

        $membreDeB = $this->membre($b, ['membership_value' => 'VALEUR-DE-B']);
        $effectifB = User::query()->where('organization_id', $b->id)->count();

        $membre = $this->membre($a);

        $this->actingAs($membre)->put(
            route('organization.profile.update', ['organization' => $b->slug]),
            $this->chargeValide($membre, ['membership_value' => 'INJECTE-VIA-B']),
        )->assertNotFound();

        $this->assertSame('VALEUR-DE-B', $membreDeB->refresh()->membership_value, 'un membre de B a ete modifie');
        $this->assertSame($effectifB, User::query()->where('organization_id', $b->id)->count(), 'l\'effectif de B a change');

        $b->refresh();
        $this->assertTrue((bool) $b->membership_enabled, 'la configuration de B a ete modifiee');
        $this->assertSame('Cotisation-de-B-intacte', $b->membership_label_fr);

        // Et l'appelant n'a pas change de tenant au passage.
        $this->assertSame($a->id, $membre->refresh()->organization_id, 'l\'appelant a change d\'Organization');
    }

    // =====================================================================
    // E. Les champs ordinaires du profil sont preserves
    // =====================================================================

    public function test_e_ordinary_profile_fields_still_update(): void
    {
        [$a] = $this->deuxOrganizations(sienneActivee: true, etrangereActivee: false);
        $membre = $this->membre($a, ['bio' => 'Ancienne bio']);

        $this->actingAs($membre)->put(
            route('organization.profile.update', ['organization' => $a->slug]),
            $this->chargeValide($membre, [
                'first_name' => 'Cyril',
                'name' => 'Durand',
                'bio' => 'Nouvelle bio TASK-1689.',
                'city' => 'Lyon',
                'postal_code' => '69001',
                'preferred_locale' => 'en',
                'address_line1' => '12 rue Exemple',
            ]),
        )->assertRedirect();

        $membre->refresh();

        $this->assertSame('Cyril', $membre->first_name);
        $this->assertSame('Durand', $membre->name);
        $this->assertSame('Nouvelle bio TASK-1689.', $membre->bio);
        $this->assertSame('Lyon', $membre->city);
        $this->assertSame('69001', $membre->postal_code);
        $this->assertSame('en', $membre->preferred_locale);
        $this->assertSame('12 rue Exemple', $membre->address_line1);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /** @return array{0: Organization, 1: Organization} */
    private function deuxOrganizations(bool $sienneActivee, bool $etrangereActivee): array
    {
        $a = Organization::factory()->create([
            'slug' => 'org-1689-a', 'name' => 'Org 1689 A',
            'is_active' => true, 'is_public' => true,
            'membership_enabled' => $sienneActivee,
        ]);

        $b = Organization::factory()->create([
            'slug' => 'org-1689-b', 'name' => 'Org 1689 B',
            'is_active' => true, 'is_public' => true,
            'membership_enabled' => $etrangereActivee,
        ]);

        return [$a, $b];
    }

    private function membre(Organization $organization, array $attributs = []): User
    {
        return User::factory()->complete()->create(array_merge([
            'organization_id' => $organization->id,
        ], $attributs));
    }

    private function oublierOrganisation(): void
    {
        app()->forgetInstance('current_organization');
    }

    private function chargeValide(User $user, array $surcharges = []): array
    {
        return array_merge([
            'first_name' => 'Jean',
            'name' => $user->name,
            'email' => $user->email,
            'phone' => '0612345678',
            'bio' => 'Une biographie.',
            'city' => 'Paris',
            'country_code' => Country::query()->whereKey('FR')->exists() ? 'FR' : null,
            'preferred_locale' => 'fr',
            'postal_code' => '75000',
        ], $surcharges);
    }
}
