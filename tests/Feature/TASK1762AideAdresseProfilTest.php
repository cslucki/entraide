<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1762 point 4 — l'aide du champ « Adresse de votre profil ».
 *
 * Trois phrases de regles sont remplacees par une seule, qui montre de VRAIES
 * adresses. La regle de forme n'est pas perdue : elle a rejoint le message
 * d'erreur, c'est-a-dire le seul moment ou elle sert.
 */
class TASK1762AideAdresseProfilTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['slug' => 'org-1762-aide', 'is_active' => true, 'locale' => 'fr']);
        app()->instance('current_organization', $this->org);

        $this->membre = User::factory()->create([
            'organization_id' => $this->org->id,
            'first_name' => 'Camille', 'name' => 'MOREAU', 'phone' => '0600000000',
        ]);
    }

    private function page(): string
    {
        return $this->actingAs($this->membre)
            ->get(route('organization.profile.edit', $this->org))
            ->assertOk()->getContent();
    }

    /** 🔴 L'ancienne litanie a disparu. */
    public function test_les_anciennes_phrases_ne_sont_plus_affichees(): void
    {
        $html = $this->page();

        $this->assertStringNotContainsString('Entre 3 et 60 caractères', $html);
        $this->assertStringNotContainsString('adresse technique', $html);
        $this->assertStringNotContainsString('aucun lien déjà partagé', $html);
    }

    /** Une seule phrase, et elle invite au lieu de reciter une regle. */
    public function test_une_seule_phrase_d_aide(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('data-profile-slug-help', $html);
        $this->assertSame(1, substr_count($html, 'data-profile-slug-help'));
        $this->assertStringContainsString('Choisissez une adresse qui vous représente', $html);
    }

    /**
     * 🔴 Les exemples portent l'adresse REELLE de l'Organization.
     *
     * Ecrits en dur, ils auraient montre `/org/main/...` a tout le monde — donc
     * une adresse fausse pour toutes les Organizations sauf une.
     */
    public function test_les_exemples_sont_construits_sur_l_organization_affichee(): void
    {
        $html = $this->page();

        $this->assertStringContainsString(
            route('organization.profile.show', ['organization' => 'org-1762-aide', 'user' => 'ofsh']),
            $html
        );
        $this->assertStringContainsString('/org/org-1762-aide/profile/camille', $html);
        $this->assertStringNotContainsString('/org/main/profile/', $html);
    }

    /**
     * 🔴 La regle de forme a bien REJOINT le message d'erreur.
     *
     * C'est la contrepartie de la simplification : si l'aide ne dit plus la
     * longueur, l'erreur doit la dire, sinon une adresse de deux lettres est
     * refusee sans raison dite.
     */
    public function test_le_message_d_erreur_porte_desormais_la_longueur(): void
    {
        $reponse = $this->actingAs($this->membre)->put(route('profile.update'), [
            'first_name' => $this->membre->first_name,
            'name' => $this->membre->name,
            'email' => $this->membre->email,
            'phone' => $this->membre->phone,
            // TASK-1762 point 17 — le slogan est desormais OBLIGATOIRE lui aussi.
            'slogan' => $this->membre->slogan ?: 'Je resous des problemes, pas des tickets.',
            'slug' => 'ab',
        ]);

        $reponse->assertSessionHasErrors('slug');

        $message = session('errors')->get('slug')[0];

        $this->assertStringContainsString('3', $message);
        $this->assertStringContainsString('60', $message);
        $this->assertSame(__('profile.slug_invalid'), $message);
    }

    // ── Point 4 bis — « Actuellement : … » ───────────────────────────────────

    /**
     * 🔴 Qui n'a RIEN choisi lit son identifiant technique en toutes lettres.
     *
     * C'est tout l'objet de la mention : « ainsi les gens comprennent plus
     * facilement qu'ils ont interet a personnaliser ». Un `Actuellement :
     * …/profile/` vide ne convaincrait personne.
     */
    public function test_sans_slug_la_mention_montre_l_identifiant_technique(): void
    {
        $this->membre->update(['slug' => null]);

        $html = $this->page();

        $this->assertStringContainsString('data-profile-slug-current', $html);
        $this->assertStringContainsString(__('profile.slug_current'), $html);
        $this->assertStringContainsString($this->membre->id, $html);
    }

    /** 🔴 Et qui a choisi lit SON adresse, pas l'identifiant. */
    public function test_avec_un_slug_la_mention_montre_le_slug(): void
    {
        app(\App\Services\UserSlugService::class)->attribuer($this->membre, 'camille-moreau');

        $html = $this->page();

        $this->assertStringContainsString('camille-moreau', $html);

        // L'identifiant technique ne doit plus apparaitre dans la mention : la
        // personne a deja fait le geste qu'on cherchait a provoquer.
        preg_match('/data-profile-slug-current[^>]*>(.*?)<\/span>\s*<\/span>/s', $html, $m);
        $this->assertNotEmpty($m, 'La mention est introuvable.');
        $this->assertStringNotContainsString($this->membre->id, $m[1]);
    }

    /**
     * 🔴 L'adresse porte la couleur du THEME, pas une couleur ecrite en dur.
     *
     * Cyril : « une des couleurs du theme [...] #8A2CFF pour le theme Zen qui
     * correspond a Accent / Loop ». `#8A2CFF` ecrit dans la vue aurait fige la
     * couleur d'UN theme sur tous les autres ; `--bp-accent` suit celui que
     * l'Organization a choisi.
     */
    public function test_l_adresse_porte_la_couleur_du_theme(): void
    {
        $html = $this->page();

        // Sur L'ELEMENT, et non sur la page : le layout injecte les variables
        // du theme, donc `#8A2CFF` figure LEGITIMEMENT dans la feuille de style.
        // Une assertion posee sur tout le document aurait rougi pour une raison
        // qui n'a rien a voir avec la mention.
        preg_match('/<span class="break-all[^>]*data-profile-slug-current>(.*?)<\/span>\s*<\/span>/s', $html, $m);

        $this->assertNotEmpty($m, 'La mention est introuvable.');
        $this->assertStringContainsString('text-[var(--bp-accent)]', $m[1]);
        $this->assertStringNotContainsString('#', $m[1], 'La couleur ne doit pas etre ecrite en dur dans la mention.');
    }

    /** La mention est a DROITE du libelle, donc dans le meme bloc que lui. */
    public function test_la_mention_est_a_cote_du_libelle(): void
    {
        $html = $this->page();

        $positionLibelle = strpos($html, 'for="slug"');
        $positionMention = strpos($html, 'data-profile-slug-current');

        $this->assertNotFalse($positionLibelle);
        $this->assertNotFalse($positionMention);
        $this->assertLessThan($positionMention, $positionLibelle, 'La mention doit suivre le libelle.');

        // Et avant le champ de saisie : elle appartient a l'en-tete du champ.
        $this->assertLessThan(strpos($html, 'id="slug"'), $positionMention);
    }

    /** Et les deux locales portent la meme phrase. */
    public function test_les_deux_locales_sont_servies(): void
    {
        foreach (['fr' => 'Choisissez une adresse', 'en' => 'Pick an address'] as $locale => $debut) {
            app()->setLocale($locale);

            $this->assertStringContainsString($debut, __('profile.slug_help', ['exemple' => 'A', 'exemple_court' => 'B']));
            $this->assertStringContainsString('3', __('profile.slug_invalid'));
        }
    }
}
