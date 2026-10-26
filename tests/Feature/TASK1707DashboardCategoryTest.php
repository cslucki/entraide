<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1707 F3 — la colonne « Categorie » etait vide dans les deux tableaux.
 *
 * Constate en recette OFSH le 25/10 sur « Mes demandes d'aide » comme sur
 * « Mes propositions d'aide ». Verifie en base a ce moment-la : `category_id`
 * EST renseigne (la colonne est meme NOT NULL avec une cle etrangere). Le
 * defaut etait donc a l'affichage.
 *
 * ## La cause
 *
 * Les quatre vues echotaient `$x->category->name`. `Category` n'a pas
 * d'attribut `name` : elle porte `name_b2c` et `name_b2b`, et expose
 * `displayName(string $context)` qui choisit selon le reglage de
 * l'Organization. L'echo rendait donc `null`, soit une pastille de couleur
 * VIDE — et comme le `@if($x->category)` passait, rien ne signalait l'absence.
 *
 * C'est le genre de defaut qui revient en silence : rien dans PHP n'empeche de
 * lire un attribut qui n'existe pas sur un modele.
 *
 * ## Pourquoi `displayName('transactions')` et pas `name_b2c`
 *
 * Parce que c'est l'appel que `requests/show.blade.php` fait DEJA sur la fiche
 * publique de la meme demande. Prendre `name_b2c` ici donnerait deux noms
 * differents pour la meme categorie selon l'ecran, sur une Organization reglee
 * en `b2b`. Le dernier test de ce fichier est exactement cette mesure : il
 * rougirait sur `name_b2c`, et reste vert sur `displayName`.
 */
class TASK1707DashboardCategoryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private Category $categorie;

    private ServiceRequest $demande;

    private Service $proposition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1707-cat',
            'is_active' => true,
            'transactions_naming' => 'b2c',
        ]);

        $this->categorie = Category::factory()->create([
            'organization_id' => $this->organization->id,
            'name_b2c' => 'Creation de site web',
            'name_b2b' => 'Prestation numerique',
        ]);

        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        $this->demande = ServiceRequest::factory()->create([
            'user_id' => $this->membre->id,
            'organization_id' => $this->organization->id,
            'category_id' => $this->categorie->id,
            'title' => 'J ai besoin d aide pour faire mon site web',
        ]);

        $this->proposition = Service::factory()->create([
            'user_id' => $this->membre->id,
            'organization_id' => $this->organization->id,
            'category_id' => $this->categorie->id,
            'title' => 'Je vous accompagne sur votre site',
        ]);
    }

    /**
     * La pastille de categorie, ISOLEE du reste de la page.
     *
     * La mesure porte sur l'ELEMENT : le nom d'une categorie figure aussi dans
     * les filtres et les selecteurs de ces ecrans, et une assertion posee sur
     * le document entier serait vraie sans que la colonne affiche quoi que ce
     * soit (memoire « une assertion portee sur LA PAGE au lieu de L'ELEMENT »).
     */
    private function pastille(string $route, string $marqueur, array $parametres = []): string
    {
        app()->forgetInstance('current_organization');

        $html = $this->actingAs($this->membre)
            ->get(route($route, $parametres))
            ->assertOk()
            ->getContent();

        // Le marqueur est borne par `[\s>]` : sans cela `data-request-category`
        // s'apparierait aussi a un `data-request-category-absent`, et un
        // sabotage qui renomme l'attribut resterait vert.
        $this->assertSame(
            1,
            preg_match('/<span[^>]*\s'.$marqueur.'[\s>][^>]*>(.*?)<\/span>/s', $html, $pastille),
            "La pastille « {$marqueur} » n'est pas servie : la mesure ne porterait sur rien."
        );

        return trim($pastille[1]);
    }

    public function test_mes_demandes_d_aide_affiche_la_categorie(): void
    {
        $this->assertSame(
            'Creation de site web',
            $this->pastille('dashboard.requests', 'data-request-category'),
            'La colonne Categorie reste vide dans « Mes demandes d\'aide ».'
        );
    }

    public function test_mes_propositions_d_aide_affiche_la_categorie(): void
    {
        $this->assertSame(
            'Creation de site web',
            $this->pastille('dashboard.services', 'data-service-category'),
            'La colonne Categorie reste vide dans « Mes propositions d\'aide ».'
        );
    }

    /**
     * Et sur les deux fiches de detail, atteintes depuis ces tableaux.
     *
     * Elles portaient le MEME echo. Corriger la liste et laisser la fiche
     * afficher une pastille vide ne serait qu'une moitie de correctif sur le
     * meme parcours.
     */
    public function test_la_fiche_d_une_demande_affiche_la_categorie(): void
    {
        $this->assertSame(
            'Creation de site web',
            $this->pastille('dashboard.requests.detail', 'data-request-category', ['serviceRequest' => $this->demande]),
        );
    }

    public function test_la_fiche_d_une_proposition_affiche_la_categorie(): void
    {
        $this->assertSame(
            'Creation de site web',
            $this->pastille('dashboard.services.detail', 'data-service-category', ['service' => $this->proposition]),
        );
    }

    /**
     * Le reglage de l'Organization est respecte, comme sur la fiche publique.
     *
     * C'est ce volet qui distingue `displayName('transactions')` d'un
     * `name_b2c` : sans lui, les deux ecrans donneraient deux noms differents a
     * la meme categorie.
     */
    public function test_une_organization_reglee_en_b2b_voit_son_propre_nom(): void
    {
        $this->organization->update(['transactions_naming' => 'b2b']);

        $this->assertSame(
            'Prestation numerique',
            $this->pastille('dashboard.requests', 'data-request-category'),
            'Le tableau ignore le reglage `transactions_naming` de l\'Organization.'
        );

        $this->assertSame(
            'Prestation numerique',
            $this->pastille('dashboard.services', 'data-service-category'),
        );
    }
}
