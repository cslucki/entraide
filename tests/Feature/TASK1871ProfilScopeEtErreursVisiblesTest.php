<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1871 — rester dans son Organization, et voir ce qui ne va pas.
 *
 * Deux demandes de Cyril, et une precaution que MASTER a imposee.
 *
 * 1. Une redirection forcee vers la completion de profil doit aboutir a
 *    `/org/{slug}/profile/edit` quand une Organization est **reellement**
 *    identifiee — et rester sur la route nue partout ailleurs. Le helper ne
 *    devine aucun slug : c'est ce que les deux premiers cas verifient, l'un
 *    par la presence du prefixe, l'autre par son ABSENCE.
 *
 * 2. Une erreur de validation doit se voir sans defiler. Le formulaire fait
 *    plus de six cents lignes et le champ fautif cite par Cyril vit tout en
 *    bas : c'est la synthese en tete qui est mesuree ici, pas le message par
 *    champ, qui existait deja.
 *
 * La precaution : aucun de ces changements ne doit ouvrir une porte vers une
 * autre Organization. Le dernier cas s'en assure.
 */
class TASK1871ProfilScopeEtErreursVisiblesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $autreOrganization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['slug' => 'ofsh']);
        $this->autreOrganization = Organization::factory()->create(['slug' => 'ailleurs']);

        // Un profil COMPLET : les cas de redirection forcee le videront
        // eux-memes, pour que chacun dise ce qu'il mesure.
        $this->membre = User::factory()->create([
            'organization_id' => $this->organization->id,
            'first_name' => 'Camille',
            'name' => 'Camille Durand',
            'phone' => '0600000000',
            'city' => 'Nantes',
            'country_code' => 'FR',
            'bio' => 'Une presentation suffisante.',
        ]);
    }

    private function profilIncomplet(): void
    {
        $this->membre->forceFill(['bio' => null])->save();
    }

    /* ---------- 1. LA REDIRECTION FORCEE ---------- */

    public function test_depuis_une_url_organization_la_redirection_reste_dans_l_organization(): void
    {
        $this->profilIncomplet();

        $this->actingAs($this->membre)
            ->get('/org/ofsh/services/create')
            ->assertRedirect('/org/ofsh/profile/edit');
    }

    public function test_depuis_une_route_globale_la_redirection_garde_la_route_nue(): void
    {
        $this->profilIncomplet();

        // Le helper ne FABRIQUE pas de contexte : hors `/org/{slug}`, le
        // comportement d'avant cette TASK est conserve mot pour mot.
        $reponse = $this->actingAs($this->membre)->get('/services/create');

        $reponse->assertRedirect(route('profile.edit'));
        $this->assertStringNotContainsString('/org/', $reponse->headers->get('Location'));
    }

    public function test_la_redirection_forcee_ne_boucle_pas_sur_elle_meme(): void
    {
        $this->profilIncomplet();

        // L'ecran de completion doit rester ATTEIGNABLE avec un profil
        // incomplet : c'est tout l'objet de la redirection. S'il etait lui
        // aussi garde, on tournerait en rond.
        $this->actingAs($this->membre)
            ->get('/org/ofsh/profile/edit')
            ->assertOk();
    }

    /* ---------- 2. LE FORMULAIRE POSTE LA OU IL EST OUVERT ---------- */

    public function test_le_formulaire_scope_poste_vers_la_route_scopee(): void
    {
        $html = $this->actingAs($this->membre)
            ->get('/org/ofsh/profile/edit')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'action="'.route('organization.profile.update', ['organization' => 'ofsh']).'"',
            $html,
        );
    }

    public function test_le_formulaire_global_poste_toujours_vers_la_route_nue(): void
    {
        $html = $this->actingAs($this->membre)
            ->get(route('profile.edit'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('action="'.route('profile.update').'"', $html);
    }

    /* ---------- 3. L'ERREUR SE VOIT SANS DEFILER ---------- */

    public function test_un_slug_invalide_produit_une_synthese_en_tete_de_formulaire(): void
    {
        $reponse = $this->actingAs($this->membre)
            ->from('/org/ofsh/profile/edit')
            ->put(route('organization.profile.update', ['organization' => 'ofsh']), [
                // TASK-1872 correctif 01 — rendus obligatoires, cf. ProfileController::update().
                'first_name' => 'Camille',
                'bio' => 'Une presentation de profil assez longue pour franchir le plancher de cent caracteres impose par TASK-1872.',
                'name' => 'Camille Durand',
                'email' => $this->membre->email,
                'phone' => '0600000000',
                // `slogan` est REQUIS. L'omettre faisait echouer la validation
                // sur lui, et la synthese s'affichait alors pour la mauvaise
                // raison — un faux vert que l'assertion `aria-describedby`
                // du slug a rattrape.
                'slogan' => 'Une phrase courte',
                'city' => 'Nantes',
                'country_code' => 'FR',
                'slug' => 'UN SLUG INVALIDE !!',
            ]);

        // On revient bien sur la page scopee d'ou l'on venait.
        $reponse->assertRedirect('/org/ofsh/profile/edit');

        $html = $this->actingAs($this->membre)
            ->get('/org/ofsh/profile/edit')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-form-error-summary', $html);
        // `e()` : le titre porte une apostrophe, que Blade rend en `&#039;`.
        // Comparer le texte brut au HTML echappe serait une fausse rouge.
        $this->assertStringContainsString(e(__('profile.error_summary_title')), $html);

        // La synthese doit precede le champ fautif : c'est exactement ce que
        // « sans avoir a scroller » veut dire ici.
        $this->assertLessThan(
            strpos($html, 'id="slug"'),
            strpos($html, 'data-form-error-summary'),
            'la synthese d\'erreurs doit apparaitre AVANT le champ fautif',
        );

        // Et le champ se declare en faute pour les lecteurs d'ecran.
        $this->assertStringContainsString('aria-describedby="slug-error"', $html);
        $this->assertStringContainsString('id="slug-error"', $html);
    }

    public function test_les_saisies_sont_conservees_apres_une_erreur(): void
    {
        $this->actingAs($this->membre)
            ->from('/org/ofsh/profile/edit')
            ->put(route('organization.profile.update', ['organization' => 'ofsh']), [
                // TASK-1872 correctif 01 — rendus obligatoires, cf. ProfileController::update().
                'first_name' => 'Camille',
                'bio' => 'Une presentation de profil assez longue pour franchir le plancher de cent caracteres impose par TASK-1872.',
                'name' => 'Camille Durand',
                'email' => $this->membre->email,
                'phone' => '0600000000',
                // `slogan` est REQUIS. L'omettre faisait echouer la validation
                // sur lui, et la synthese s'affichait alors pour la mauvaise
                // raison — un faux vert que l'assertion `aria-describedby`
                // du slug a rattrape.
                'slogan' => 'Une phrase courte',
                'city' => 'Rennes',
                'country_code' => 'FR',
                'slug' => 'UN SLUG INVALIDE !!',
            ])
            ->assertSessionHasInput('city', 'Rennes');
    }

    public function test_aucune_synthese_quand_le_formulaire_est_sain(): void
    {
        $html = $this->actingAs($this->membre)
            ->get('/org/ofsh/profile/edit')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-form-error-summary', $html);
    }

    /* ---------- 4. AUCUNE PORTE VERS UNE AUTRE ORGANIZATION ---------- */

    public function test_un_membre_ne_peut_pas_ouvrir_le_profil_scope_d_une_autre_organization(): void
    {
        $reponse = $this->actingAs($this->membre)->get('/org/ailleurs/profile/edit');

        $this->assertContains(
            $reponse->getStatusCode(),
            [403, 404, 302],
            'l\'acces au profil scope d\'une Organization etrangere doit etre refuse',
        );

        if ($reponse->getStatusCode() === 302) {
            $this->assertStringNotContainsString('/org/ailleurs/', (string) $reponse->headers->get('Location'));
        }
    }

    /* ---------- 5. LES DEUX CORRECTIFS DEMANDES PAR MASTER ---------- */

    public function test_le_style_enrichi_ne_deborde_pas_sur_les_autres_formulaires(): void
    {
        $profil = $this->actingAs($this->membre)
            ->get('/org/ofsh/profile/edit')
            ->assertOk()
            ->getContent();

        // Le formulaire de profil demande le style enrichi...
        $this->assertStringContainsString('border-s-[3px]', $profil);

        // ...et la page Securite, qui monte les MEMES composants partages,
        // garde strictement l'habillage d'origine. C'est la demande de
        // MASTER : isoler l'evolution visuelle au seul profil.
        $sousAutreEcran = $this->actingAs($this->membre)
            ->get(route('profile.security'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('border-s-[3px]', $sousAutreEcran);
        $this->assertStringContainsString('border-gray-300', $sousAutreEcran);

        // Correctif MASTER : l'isolement porte aussi sur les ATTRIBUTS, pas
        // seulement sur les classes. Ailleurs, `x-input-error` ne recoit pas
        // `field`, donc aucun bloc ne porte d'`id` : un `aria-describedby`
        // y viserait un identifiant INEXISTANT, et un renvoi casse est pire
        // que pas de renvoi du tout.
        $this->assertStringNotContainsString('aria-describedby', $sousAutreEcran);
        $this->assertStringNotContainsString('data-server-invalid', $sousAutreEcran);
    }

    public function test_un_champ_refuse_par_le_serveur_porte_le_marqueur_qui_protege_son_message(): void
    {
        $this->actingAs($this->membre)
            ->from('/org/ofsh/profile/edit')
            ->put(route('organization.profile.update', ['organization' => 'ofsh']), [
                // TASK-1872 correctif 01 — rendus obligatoires, cf. ProfileController::update().
                'first_name' => 'Camille',
                'bio' => 'Une presentation de profil assez longue pour franchir le plancher de cent caracteres impose par TASK-1872.',
                'name' => 'Camille Durand',
                'email' => $this->membre->email,
                'phone' => '0600000000',
                'slogan' => 'Une phrase courte',
                'city' => 'Nantes',
                'country_code' => 'FR',
                'slug' => 'UN SLUG INVALIDE !!',
            ]);

        $html = $this->actingAs($this->membre)
            ->get('/org/ofsh/profile/edit')
            ->assertOk()
            ->getContent();

        // `data-server-invalid` est ce qui empeche la validation au fil de la
        // saisie d'effacer un refus que seul le serveur pouvait prononcer —
        // un slug deja pris, par exemple, que `checkValidity()` trouverait
        // parfaitement valide. Defaut releve par MASTER a la revue.
        $this->assertStringContainsString('data-server-invalid', $html);
    }
}
