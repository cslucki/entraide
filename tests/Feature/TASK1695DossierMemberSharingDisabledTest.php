<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * TASK-1695 — le partage d'un DOSSIER avec des membres est DÉSACTIVÉ.
 *
 * ## Pourquoi
 *
 * Décision de Cyril le 24/10. La création de Dossiers avait été retirée il y a
 * plus d'un mois — Dossiers n'est pas un Drive — mais retirer la **création**
 * n'avait pas emporté le **partage** de ceux qui existaient déjà. L'écran
 * restait atteignable, et sa recherche de membres rendait l'adresse email de
 * chaque personne proposée.
 *
 * ## Mesuré avant d'agir
 *
 * 11 Dossiers, dont 5 « Mes documents » et 5 liés à une Boucle. **1 seul**
 * réellement partageable, **0** ligne `dossier_members`. La fonction n'avait
 * jamais servi.
 *
 * ## Deux couches, et le test les tient séparément
 *
 * L'autorité (`manageMembers`) **et** les routes. L'une sans l'autre laisserait
 * soit une URL vivante, soit une poignée vers une porte murée.
 */
class TASK1695DossierMemberSharingDisabledTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $proprietaire;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['slug' => 'org-1695x', 'is_active' => true]);
        app()->instance('current_organization', $this->organization);

        $this->proprietaire = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->proprietaire->id,
            'visibility' => 'private',
        ]);
    }

    /**
     * L'autorité refuse, même au PROPRIÉTAIRE.
     *
     * C'est le cas qui comptait : avant la désactivation, c'est exactement lui
     * qui pouvait ouvrir l'écran et moissonner les adresses.
     */
    public function test_even_the_owner_can_no_longer_manage_members(): void
    {
        $this->assertFalse($this->proprietaire->can('manageMembers', $this->dossier));
    }

    /** Les cinq routes n'existent plus : une URL tapée à la main ne mène nulle part. */
    public function test_the_five_member_routes_are_gone(): void
    {
        foreach (['index', 'store', 'update', 'destroy', 'search'] as $geste) {
            $this->assertFalse(
                Route::has('organization.dossiers.members.'.$geste),
                "La route members.$geste existe encore.",
            );
        }
    }

    /**
     * La poignée a disparu de l'écran.
     *
     * Elle passe désormais par `@can('manageMembers', …)` : fermer l'autorité
     * la retire d'elle-même. Ce test le vérifie plutôt que de le supposer.
     *
     * ## Ce qu'il a trouvé
     *
     * La poignée était conditionnée à `$userRole === 'owner'` — un prédicat
     * **différent** de celui qui garde la porte. Fermer `manageMembers`
     * laissait donc le bouton en place, ouvrant un panneau devenu inutile. Les
     * deux avaient dérivé sans que rien ne le dise.
     *
     * ## Ce qu'il vise, et pourquoi pas le libellé
     *
     * Le DÉCLENCHEUR (`open-share-panel`), et non le texte « Partager » : ce
     * libellé sert aussi de titre au panneau, qui reste dans le balisage. Une
     * assertion sur le mot serait rouge pour une raison étrangère à la garde —
     * et m'aurait fait chercher le défaut au mauvais endroit.
     */
    public function test_the_share_handle_is_gone_from_the_screen(): void
    {
        $html = $this->actingAs($this->proprietaire)
            ->get(route('organization.dossiers.show', [
                'organization' => $this->organization->slug,
                'dossier' => $this->dossier->id,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            "new CustomEvent('open-share-panel')",
            $html,
            'Le bouton qui ouvre le panneau de partage est encore rendu.',
        );

        $this->assertStringNotContainsString('partage=1', $html);
    }

    /**
     * Le partage de FICHIER, lui, continue de fonctionner.
     *
     * Les deux mécaniques sont distinctes et ne partagent aucun code. Sans ce
     * test, une désactivation trop large emporterait la fonctionnalité que
     * cette même TASK vient de livrer — et rien ne le dirait.
     */
    public function test_file_sharing_is_untouched(): void
    {
        $this->assertTrue(Route::has('organization.dossiers.files.shares.store'));
        $this->assertTrue(Route::has('organization.dossiers.shared.preview'));
    }

    /**
     * Le partage avec une BOUCLE n'est pas concerné.
     *
     * `isOwner()` exige `owner_id`, qu'un Dossier de Boucle n'a pas : il n'est
     * jamais passé par `manageMembers`. C'est la direction que Cyril veut
     * développer — la fermer par effet de bord serait un dégât silencieux.
     */
    public function test_loop_sharing_is_untouched(): void
    {
        $this->assertTrue(Route::has('organization.dossiers.index'));
        $this->assertTrue(
            Dossier::query()->whereNotNull('shared_with_loop_id')->toBase() !== null,
            'La colonne shared_with_loop_id doit rester interrogeable.',
        );
    }
}
