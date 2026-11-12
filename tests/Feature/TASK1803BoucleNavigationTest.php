<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1803 — section dediee « Boucles » dans le rail de gauche du superadmin.
 *
 * Cyril : « dans le superadmin, on va créer une section dans le rail de
 * gauche "Boucles". A l'intérieur, on va mettre : Boucles, Type de boucles,
 * Permissions boucles, Plugin de boucles. »
 *
 * Les 4 routes existaient DEJA (`admin.loops`, `admin.loop-types`,
 * `admin.loop-permissions`, `admin.loop-plugins`), noyees dans la section
 * « Échanges » : ce correctif est un REGROUPEMENT visuel, pas une nouvelle
 * surface. `AdminMiddleware` exige `is_admin` sur TOUT le groupe de routes
 * `/admin/*` (y compris `/admin/dashboard`) : en pratique, personne d'autre
 * qu'un SuperAdmin ne rend jamais ce rail, ce qui rend le filtre « 3 des 4
 * entrees reservees au SuperAdmin » invisible depuis une requete HTTP reelle
 * — il n'est donc pas teste ici separement (le re-tester ferait semblant de
 * verifier un chemin qui n'existe pas).
 */
class TASK1803BoucleNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
    }

    public function test_the_boucles_section_lists_the_four_loop_admin_links(): void
    {
        $html = $this->actingAs($this->superAdmin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('>Boucles<', $html);
        $this->assertStringContainsString(route('admin.loops'), $html);
        $this->assertStringContainsString(route('admin.loop-types'), $html);
        $this->assertStringContainsString(route('admin.loop-permissions'), $html);
        $this->assertStringContainsString(route('admin.loop-plugins'), $html);
    }

    public function test_the_bulk_sending_tool_appears_under_email_and_messages(): void
    {
        $html = $this->actingAs($this->superAdmin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(e(__('admin.emailer_bulksending')), $html);
        $this->assertStringContainsString(route('admin.email-bulksending'), $html);
    }

    /**
     * Visiter une page « Boucles » met bien en surbrillance le groupe
     * « Boucles », pas « Échanges » — preuve que `$navRoutes` connait les
     * routes deplacees (sans quoi `$isNavActive`/`$isGroupActive` se
     * trompent en silence, voir le commentaire du predicat dans
     * `admin.blade.php`).
     */
    public function test_visiting_a_loop_admin_page_highlights_the_boucles_group_open(): void
    {
        $html = $this->actingAs($this->superAdmin())
            ->get(route('admin.loop-types'))
            ->assertOk()
            ->getContent();

        // Un groupe ACTIF force `open: true` en dur plutot que de lire
        // `localStorage` (voir la ternaire du bloc rendu) : la preuve que
        // $isGroupActive($boucleItems) a bien reconnu `admin.loop-types`
        // est donc ce `true` immediatement avant le libelle « Boucles »,
        // et non l'appel localStorage (qui, lui, ne serait rendu QUE si
        // ce groupe n'etait PAS actif).
        $boucleLabelPos = strpos($html, '>Boucles</span>');
        $this->assertNotFalse($boucleLabelPos);
        $openTruePos = strrpos(substr($html, 0, $boucleLabelPos), "open: true");
        $this->assertNotFalse($openTruePos, 'Le groupe Boucles devrait etre ouvert de force (actif) sur /admin/loop-types.');
    }

    public function test_bulk_sending_routes_are_blocked_for_a_non_superadmin(): void
    {
        $member = User::factory()->create(['is_admin' => false, 'email_verified_at' => now()]);

        $this->actingAs($member)->get(route('admin.email-bulksending'))->assertForbidden();
    }
}
