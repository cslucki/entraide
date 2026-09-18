<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1503 — P1-a de l'audit TASK-1501 : les quatre tableaux les plus
 * utilises de l'admin d'Organisation (demandes, membres, boucles, services)
 * se replient en cartes sous `md`.
 *
 * Le mecanisme est CSS (`.bp-admin-table`, app.css) ; ce que la page doit
 * fournir, et que ces tests mesurent, c'est le CONTRAT d'attributs : sans
 * `data-label`, une cellule perd son libelle sur mobile — silencieusement.
 * Le rendu lui-meme est mesure au navigateur (fiche de captures).
 */
class TASK1503AdminTableResponsiveTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $orgAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true, 'is_public' => true, 'slug' => 'org-t1503']);
        $this->orgAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $this->org->update(['admin_id' => $this->orgAdmin->id]);
    }

    /** @return array<string, string> route name => html */
    private function pages(): array
    {
        $html = [];
        foreach (['requests', 'users', 'loops', 'services'] as $name) {
            $html[$name] = $this->actingAs($this->orgAdmin)
                ->get(route('organization.admin.'.$name, ['organization' => $this->org->slug]))
                ->assertOk()
                ->getContent();
        }

        return $html;
    }

    private function seedOneRowEach(): void
    {
        $member = User::factory()->create(['organization_id' => $this->org->id]);
        ServiceRequest::factory()->create(['organization_id' => $this->org->id, 'user_id' => $member->id]);
        Service::factory()->create(['organization_id' => $this->org->id, 'user_id' => $member->id]);
        Loop::factory()->create(['organization_id' => $this->org->id, 'created_by' => $member->id]);
    }

    public function test_every_table_is_wrapped_and_every_cell_carries_its_label(): void
    {
        $this->seedOneRowEach();

        foreach ($this->pages() as $name => $html) {
            $this->assertStringContainsString('data-admin-table', $html, "[$name] le tableau n'est pas enveloppe");

            preg_match('/<tbody.*?<\/tbody>/s', $html, $tbody);
            $this->assertNotEmpty($tbody, "[$name] tbody introuvable");
            preg_match_all('/<td\b[^>]*>/', $tbody[0], $cells);
            $this->assertGreaterThan(0, count($cells[0]), "[$name] aucune cellule rendue : la ligne de test n'apparait pas");

            foreach ($cells[0] as $cell) {
                $this->assertMatchesRegularExpression('/data-label="[^"]+"|data-empty/', $cell, "[$name] cellule sans libelle mobile : $cell");
            }

            // La premiere cellule de chaque ligne nomme la ligne ; le libelle vient de la MEME cle que l'en-tete.
            $this->assertStringContainsString('data-title', $tbody[0], "[$name] aucune cellule-titre");
            preg_match_all('/data-label="([^"]+)"/', $tbody[0], $labels);
            preg_match('/<thead.*?<\/thead>/s', $html, $thead);
            $headings = preg_replace('/\s+/', ' ', strip_tags($thead[0]));
            foreach (array_unique($labels[1]) as $label) {
                $this->assertStringContainsString($label, $headings, "[$name] le libelle « $label » n'est pas celui d'un en-tete");
            }
        }
    }

    public function test_action_cells_are_marked_and_empty_states_span_the_card(): void
    {
        foreach ($this->pages() as $name => $html) {
            if ($name === 'users') {
                // L'admin est lui-meme membre : cette page n'est jamais vide pour lui.
                $this->assertStringContainsString('data-label=', $html, '[users] la ligne de l\'admin doit etre rendue avec ses libelles');

                continue;
            }
            // Sans ligne : l'etat vide est une cellule pleine largeur.
            $this->assertMatchesRegularExpression('/<td data-empty colspan="\d+"/', $html, "[$name] etat vide non marque");
        }

        $this->seedOneRowEach();
        $pages = $this->pages();
        foreach (['requests', 'users', 'loops'] as $name) {
            $this->assertStringContainsString('data-actions', $pages[$name], "[$name] la cellule des actions n'est pas marquee");
        }
    }

    public function test_the_stylesheet_carries_the_mobile_rules(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('.bp-admin-table td[data-label]::before', $css);
        $this->assertStringContainsString('.bp-admin-table td[data-actions] a', $css);
        $this->assertMatchesRegularExpression('/@media \(max-width: 767px\) \{[^}]*\.bp-admin-table/s', $css, 'les regles mobiles ne sont pas sous le point de rupture md');
    }
}
