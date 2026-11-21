<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\UserSlugService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1872, correctifs 01 et 02 — le formulaire de profil dit enfin la verite.
 *
 * ## Le defaut, tel que Cyril l'a vu
 *
 * « Je crois qu'on a supprime le caractere obligatoire sur certains champs. »
 * Mesure faite : T1871 n'en avait retire aucun. Mais la comparaison a montre
 * autre chose, et de pire — une contradiction ANTERIEURE entre trois endroits.
 *
 * `EnsureProfileComplete` refuse de publier un service ou une demande sans
 * `first_name`, `city`, `country_code` ni `bio`. Le controleur, lui, les
 * acceptait vides, et l'ecran ne les marquait pas. On enregistrait donc un
 * profil que le produit jugeait incomplet, puis on refusait l'action sans dire
 * quel champ manquait.
 *
 * ## Ce que ces tests tiennent
 *
 * Les deux versants, parce qu'un seul ne suffit pas : le SERVEUR refuse (c'est
 * l'autorite), et l'ECRAN le dit avant d'envoyer (c'est l'ergonomie). Un
 * formulaire qui n'a que le premier fait perdre une saisie ; un formulaire qui
 * n'a que le second se contourne avec `curl`.
 */
class TASK1872ProfilChampsObligatoiresTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['slug' => 'ofsh', 'is_active' => true]);
        $this->membre = User::factory()->create([
            'organization_id' => $this->organization->id,
            'first_name' => 'Camille',
            'name' => 'Lucki',
            'phone' => '+33600000000',
            'city' => 'Lyon',
            'country_code' => 'FR',
            'bio' => 'Une presentation deja renseignee.',
            'slogan' => 'Je resous des problemes, pas des tickets.',
            // La langue est FIXEE sur le compte, et non posee par un
            // `setLocale()` avant l'appel : `SetLocale` la recalcule a chaque
            // requete, et `preferred_locale` est ce qui l'emporte apres la
            // session. Sans cela, les assertions de message dependraient de
            // l'en-tete `Accept-Language` du client de test.
            'preferred_locale' => 'fr',
        ]);

        app()->instance('current_organization', $this->organization);
    }

    /** @param array<string, mixed> $surcharge */
    private function formulaire(array $surcharge = []): array
    {
        return array_merge([
            'first_name' => 'Camille',
            'name' => 'Lucki',
            'email' => $this->membre->email,
            'phone' => '+33600000000',
            'city' => 'Lyon',
            'country_code' => 'FR',
            'bio' => 'Une presentation de profil assez longue pour franchir le plancher de cent caracteres impose par TASK-1872.',
            'slogan' => 'Je resous des problemes, pas des tickets.',
        ], $surcharge);
    }

    private function ecran(): string
    {
        return $this->actingAs($this->membre)
            ->get(route('profile.edit'))
            ->assertOk()
            ->getContent();
    }

    /* ─────────── LE SERVEUR REFUSE, CHAMP PAR CHAMP ─────────── */

    /**
     * Les quatre champs que `EnsureProfileComplete` exigeait deja.
     *
     * Un test par champ et non un seul pour les quatre : avec un envoi vidant
     * tout, une seule regle presente suffirait a faire passer l'assertion, et
     * les trois autres pourraient manquer sans que rien ne le dise.
     */
    public function test_le_serveur_refuse_chacun_des_quatre_champs_laisse_vide(): void
    {
        foreach (['first_name', 'city', 'country_code', 'bio'] as $champ) {
            $this->actingAs($this->membre)
                ->put(route('profile.update'), $this->formulaire([$champ => '']))
                ->assertSessionHasErrors($champ);
        }
    }

    /** Et il accepte un envoi complet : sans ce temoin, tout rougirait pour rien. */
    public function test_le_serveur_accepte_un_envoi_complet(): void
    {
        $this->actingAs($this->membre)
            ->put(route('profile.update'), $this->formulaire())
            ->assertSessionHasNoErrors();

        $this->assertSame('Lyon', $this->membre->fresh()->city);
    }

    /**
     * Le message nomme le CHAMP, pas la colonne.
     *
     * Sans les libelles ajoutes dans `lang/*\/validation.php`, Laravel ecrivait
     * « Le champ country code est obligatoire » — le nom de la colonne, que la
     * personne n'a jamais lu a l'ecran.
     */
    public function test_le_message_porte_le_libelle_du_champ_et_non_le_nom_de_colonne(): void
    {
        $this->actingAs($this->membre)
            ->put(route('profile.update'), $this->formulaire(['country_code' => '', 'bio' => '']))
            // `assertInvalid` fait une recherche PARTIELLE : on vise donc le
            // libelle seul, sans figer la phrase que Laravel construit autour.
            // Sans les entrees ajoutees dans `lang/fr/validation.php`, ces
            // messages diraient « country code » et « bio ».
            ->assertInvalid([
                'country_code' => 'Le champ pays',
                'bio' => 'Le champ présentation',
            ]);
    }

    /* ─────────── CORRECTIF 10 : LE PLANCHER DE LA PRESENTATION ─────────── */

    /**
     * 100 caracteres, et le chiffre n'est pas arbitraire.
     *
     * C'est celui que `services/create` et `requests/create` imposent deja au
     * MEME editeur. Mesure sur le jumeau local avant de le retenir : 74
     * presentations renseignees, mediane a 307 caracteres, 1er quartile a
     * 114 — quinze d'entre elles (20%) devront etre rallongees. A 150 ce
     * serait 27%, a 200 31%.
     *
     * Les bornes sont testees des DEUX cotes : 99 refuse, 100 accepte. Un
     * test qui n'essaierait qu'une valeur tres courte passerait aussi avec un
     * seuil a 10.
     */
    public function test_la_presentation_exige_cent_caracteres(): void
    {
        $this->actingAs($this->membre)
            ->put(route('profile.update'), $this->formulaire(['bio' => str_repeat('a', 99)]))
            ->assertSessionHasErrors('bio');

        $this->actingAs($this->membre)
            ->put(route('profile.update'), $this->formulaire(['bio' => str_repeat('a', 100)]))
            ->assertSessionHasNoErrors();
    }

    /**
     * Le plancher est DERIVE de la regle serveur a l'ecran, pas recopie.
     *
     * `minLength` atterrit sur le `<textarea>` que TipTap masque ; le
     * navigateur annonce alors precisement ce qui manque, au `blur`, avant
     * toute soumission.
     */
    public function test_l_editeur_annonce_le_plancher_avant_la_soumission(): void
    {
        $html = $this->ecran();

        $this->assertMatchesRegularExpression(
            '/<textarea[^>]*name="bio"[^>]*minlength="100"/s',
            $html,
            'L\'editeur ne porte pas le plancher : il ne se decouvrirait qu\'au refus du serveur.'
        );
    }

    /**
     * Et la contrainte se LIT, avant d'etre rencontree.
     *
     * Decouvrir un minimum en se faisant refuser est la facon la plus
     * surement agacante de l'appliquer.
     */
    public function test_la_contrainte_est_annoncee_a_l_ecran(): void
    {
        $this->assertStringContainsString(
            e(__('profile.bio_min_hint', ['min' => 100])),
            $this->ecran(),
        );
    }

    /* ─────────── L'ECRAN LE DIT AVANT D'ENVOYER ─────────── */

    /** Les quatre champs portent `required`, donc le navigateur le sait. */
    public function test_les_quatre_champs_portent_required_sur_l_ecran(): void
    {
        $html = $this->ecran();

        foreach (['first_name', 'city', 'country_code'] as $champ) {
            $this->assertMatchesRegularExpression(
                '/<(input|select)[^>]*name="'.$champ.'"[^>]*required/',
                $html,
                "Le champ {$champ} n'est pas marque `required` sur l'ecran."
            );
        }

        // La presentation passe par l'editeur : son `required` atterrit sur le
        // `<textarea>` que TipTap masquera.
        $this->assertMatchesRegularExpression(
            '/<textarea[^>]*name="bio"[^>]*required/s',
            $html,
            'La presentation n\'est pas marquee `required`.'
        );
    }

    /**
     * `required` sur un `<select>` ne vaut que si une option a la valeur VIDE.
     *
     * Sans elle, « rien choisi » serait indetectable : le navigateur
     * considererait la premiere option comme un choix.
     */
    public function test_le_pays_garde_une_option_vide_sinon_required_ne_detecte_rien(): void
    {
        $this->assertStringContainsString('<option value="">', $this->ecran());
    }

    /* ─────────── CORRECTIF 02 : LA FORME DE L'ADRESSE ─────────── */

    /**
     * Les contraintes du slug sont DERIVEES des constantes, pas recopiees.
     *
     * L'assertion lit `UserSlugService` plutot que d'ecrire `3`, `60` et le
     * motif en clair : si la regle change un jour, ce test suit au lieu de
     * rougir, et surtout la vue ne peut pas diverger sans que ce test le voie.
     */
    public function test_l_adresse_de_profil_porte_la_forme_attendue_avant_tout_envoi(): void
    {
        $html = $this->ecran();

        $champ = $this->baliseDuSlug($html);

        $this->assertStringContainsString('minlength="'.UserSlugService::MIN.'"', $champ);
        $this->assertStringContainsString('maxlength="'.UserSlugService::MAX.'"', $champ);
        $this->assertStringContainsString(
            'pattern="'.e(trim(UserSlugService::MOTIF, '/^$')).'"',
            $champ,
            'Le motif de la vue ne derive plus de UserSlugService::MOTIF.'
        );
    }

    /**
     * Le message de forme est celui du SERVEUR, pas une seconde redaction.
     *
     * `validationMessage` du navigateur se reduit a « respectez le format »
     * pour un `pattern`, ce qui n'enonce aucune regle. Le champ porte donc la
     * phrase, et elle vient de la MEME clef que le refus serveur.
     */
    public function test_le_message_de_forme_vient_de_la_meme_clef_que_le_refus_serveur(): void
    {
        $champ = $this->baliseDuSlug($this->ecran());

        // La MEME clef que le refus serveur, dans la langue du compte : c'est
        // la provenance qu'on prouve, pas la coincidence de deux phrases.
        $attendu = trans('profile.slug_invalid', [], 'fr');

        $this->assertStringContainsString('data-message-forme="'.e($attendu).'"', $champ);
    }

    /** Vide reste permis : l'adresse technique doit pouvoir etre conservee. */
    public function test_l_adresse_de_profil_reste_facultative(): void
    {
        $this->actingAs($this->membre)
            ->put(route('profile.update'), $this->formulaire(['slug' => '']))
            ->assertSessionHasNoErrors();

        $champ = $this->baliseDuSlug($this->ecran());

        $this->assertStringNotContainsString(' required', $champ,
            'Un `required` sur l\'adresse interdirait de garder l\'adresse technique.');
    }

    /* ─────────── CORRECTIF 03 : L'EMPLACEMENT DU MESSAGE ─────────── */

    /**
     * Le message a son emplacement SOUS le champ, emis par la vue.
     *
     * Recette de Cyril : l'alerte de l'adresse s'affichait A DROITE du champ.
     * Le script la creait `afterend` de l'`<input>`, lequel vit dans un
     * conteneur `flex items-center` — elle devenait donc une colonne de la
     * rangee. L'emplacement est desormais rendu par `x-input-error`, vide et
     * masque, la ou la vue l'a pose.
     */
    public function test_chaque_champ_nomme_recoit_un_emplacement_de_message_vide(): void
    {
        $html = $this->ecran();

        foreach (['first_name', 'name', 'email', 'phone', 'city', 'country_code', 'slug'] as $champ) {
            $this->assertMatchesRegularExpression(
                '/<ul id="'.$champ.'-error" hidden/',
                $html,
                "Le champ {$champ} n'a pas d'emplacement de message : le script en inventerait un."
            );
        }
    }

    /**
     * Et cet emplacement est HORS de la rangee du champ.
     *
     * C'est la preuve de la correction, pas seulement de sa presence : entre
     * l'`<input>` et son bloc, le conteneur `flex` doit s'etre referme. Sinon
     * le message redeviendrait une colonne a cote du champ.
     */
    public function test_l_emplacement_du_message_est_hors_de_la_rangee_du_champ(): void
    {
        $html = $this->ecran();

        $debut = strpos($html, 'name="slug"');
        $fin = strpos($html, '<ul id="slug-error"');

        $this->assertNotFalse($debut, 'Le champ d\'adresse est introuvable.');
        $this->assertNotFalse($fin, 'L\'emplacement du message d\'adresse est introuvable.');
        $this->assertGreaterThan($debut, $fin, 'Le bloc de message precede le champ.');

        $this->assertStringContainsString(
            '</div>',
            substr($html, $debut, $fin - $debut),
            'Le bloc de message est reste DANS la rangee `flex` du champ : il s\'affichera a cote.'
        );
    }

    /** Le formulaire reste branche sur la validation au fil de la saisie. */
    public function test_le_formulaire_porte_toujours_le_declencheur_de_validation(): void
    {
        $this->assertStringContainsString('data-live-validation', $this->ecran());
    }

    /**
     * La balise du champ d'adresse, isolee.
     *
     * Chercher `minlength="3"` dans la page entiere ne prouverait rien : un
     * autre champ pourrait le porter. On borne donc a SA balise.
     */
    private function baliseDuSlug(string $html): string
    {
        $this->assertSame(
            1,
            preg_match('/<input[^>]*name="slug"[^>]*>/', $html, $trouve),
            'Le champ d\'adresse de profil n\'a pas ete trouve dans la page.'
        );

        return $trouve[0];
    }
}
