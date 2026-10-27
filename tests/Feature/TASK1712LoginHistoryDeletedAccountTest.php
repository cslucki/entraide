<?php

namespace Tests\Feature;

use App\Models\LoginLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * TASK-1712 — l'historique de connexions survit a la suppression d'un compte.
 *
 * Cyril a vu un **500** sur `/admin/stats/login-history` en PROD, et pas sur
 * `test.laravel`. La cause n'est ni le code ni la configuration : c'est l'ORDRE
 * des donnees.
 *
 * `login_logs.user_id` est nullable et sa cle etrangere est en
 * `ON DELETE SET NULL` (migration `2026_09_24_213000`) : l'historique de
 * connexion survit deliberement au compte, c'est une trace. Mais les deux vues
 * lisaient `$log->user->avatar_url`, `->fullName`, `->email` et `->id` **sans
 * aucune garde** — huit lectures, quatre par vue.
 *
 * | | PROD | DEV |
 * |---|---|---|
 * | `login_logs` | 346 | 432 |
 * | orphelins | 43 | 51 |
 * | **premier orphelin** | **ligne 2 -> page 1** | **ligne 98 -> page 4** |
 *
 * DEV n'etait pas sain, seulement plus chanceux : le tri par defaut est
 * `latest('created_at')`, et en PROD le deuxieme enregistrement le plus recent
 * etait deja orphelin. C'est aussi ce qui explique qu'un defaut vieux d'un mois
 * n'ait jamais ete vu.
 *
 * Les tests placent donc l'orphelin en **PREMIERE position**, puisque c'est la
 * position qui revele le defaut.
 */
class TASK1712LoginHistoryDeletedAccountTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $administrateur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1712',
            'is_active' => true,
        ]);

        $this->administrateur = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Un evenement d'historique dont le compte a ete supprime.
     *
     * On supprime reellement le compte plutot que de poser `user_id = null` a
     * la main : c'est `ON DELETE SET NULL` qui doit produire l'etat, sinon le
     * test mesure une fiction.
     */
    private function evenementOrphelin(): LoginLog
    {
        $partant = User::factory()->create(['organization_id' => $this->organization->id]);

        $log = LoginLog::create([
            'organization_id' => $this->organization->id,
            'user_id' => $partant->id,
            'ip_address' => '203.0.113.7',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/131.0.0.0',
        ]);

        DB::table('users')->where('id', $partant->id)->delete();

        $frais = $log->fresh();
        $this->assertNull($frais->user_id, 'ON DELETE SET NULL n\'a pas joue : le test ne mesurerait rien.');

        return $frais;
    }

    private function evenementNormal(): LoginLog
    {
        $present = User::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Membre Toujours La',
        ]);

        return LoginLog::create([
            'organization_id' => $this->organization->id,
            'user_id' => $present->id,
            'ip_address' => '198.51.100.22',
            'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/604.1',
        ]);
    }

    // ── La vue PLATEFORME ───────────────────────────────────────────────────

    /**
     * La page rend 200 avec un orphelin en PREMIERE ligne.
     *
     * Sans la garde, cette requete rendait
     * `Attempt to read property "avatar_url" on null`.
     */
    public function test_la_vue_plateforme_rend_200_avec_un_orphelin_en_premiere_ligne(): void
    {
        $normal = $this->evenementNormal();
        $orphelin = $this->evenementOrphelin();

        // L'orphelin est le PLUS RECENT, donc le premier du tri par defaut.
        $orphelin->forceFill(['created_at' => now()])->save();
        $normal->forceFill(['created_at' => now()->subHour()])->save();

        $reponse = $this->actingAs($this->administrateur)->get('/admin/stats/login-history');

        $reponse->assertOk();
        $reponse->assertSee(e(__('admin.login_history_deleted_account')), false);
    }

    /**
     * L'evenement est CONSERVE : IP, date et appareil restent lisibles.
     *
     * Masquer la ligne aurait rendu la page 200 sans rien corriger — un
     * historique dont on retire des lignes n'est plus un historique.
     */
    public function test_l_evenement_orphelin_garde_son_ip_sa_date_et_son_appareil(): void
    {
        $orphelin = $this->evenementOrphelin();

        $reponse = $this->actingAs($this->administrateur)->get('/admin/stats/login-history');

        $reponse->assertOk();
        $reponse->assertSee('203.0.113.7');
        $reponse->assertSee($orphelin->created_at->format('d/m/Y'), false);
    }

    /**
     * Aucun lien ne pointe vers un compte absent.
     *
     * `route('admin.stats.login-history.user', null)` leve : la branche doit
     * donc etre entierement depourvue de lien, pas seulement d'avatar.
     */
    public function test_aucun_lien_ne_mene_a_un_compte_absent(): void
    {
        $this->evenementOrphelin();

        $html = $this->actingAs($this->administrateur)
            ->get('/admin/stats/login-history')
            ->assertOk()
            ->getContent();

        $this->assertSame(
            1,
            preg_match('/data-login-history-deleted-account/', $html),
            'Le bloc « compte supprime » n\'est pas rendu.'
        );

        // La region du compte supprime ne contient aucun lien.
        $this->assertSame(
            1,
            preg_match('/data-login-history-deleted-account[^>]*>(.*?)<\/div>/s', $html, $region)
        );
        $this->assertStringNotContainsString('<a ', $region[1], 'Un lien mene vers un compte qui n\'existe plus.');
    }

    /**
     * Un compte PRESENT continue de s'afficher normalement.
     *
     * Sans ce volet, une garde qui masquerait tout le monde passerait les
     * precedents.
     */
    public function test_un_compte_present_s_affiche_toujours_normalement(): void
    {
        $this->evenementNormal();

        $reponse = $this->actingAs($this->administrateur)->get('/admin/stats/login-history');

        $reponse->assertOk();
        $reponse->assertSee('Membre Toujours La');
        $reponse->assertDontSee(e(__('admin.login_history_deleted_account')), false);
    }

    // ── La vue ORG-SCOPE ────────────────────────────────────────────────────

    /**
     * La meme garde, sur la seconde vue.
     *
     * Les deux fichiers portaient le defaut a l'identique. Corriger l'un et pas
     * l'autre aurait laisse la moitie du 500 en place.
     */
    public function test_la_vue_org_scope_rend_200_avec_un_orphelin(): void
    {
        $this->evenementOrphelin();

        app()->forgetInstance('current_organization');

        $reponse = $this->actingAs($this->administrateur)
            ->get('/org/'.$this->organization->slug.'/admin/stats/login-history');

        $reponse->assertOk();
        $reponse->assertSee(e(__('admin.login_history_deleted_account')), false);
        $reponse->assertSee('203.0.113.7');
    }

    public function test_la_vue_org_scope_affiche_toujours_un_compte_present(): void
    {
        $this->evenementNormal();

        app()->forgetInstance('current_organization');

        $this->actingAs($this->administrateur)
            ->get('/org/'.$this->organization->slug.'/admin/stats/login-history')
            ->assertOk()
            ->assertSee('Membre Toujours La');
    }

    // ── Les deux locales ────────────────────────────────────────────────────

    public function test_le_libelle_existe_dans_les_deux_locales(): void
    {
        foreach (['fr', 'en'] as $locale) {
            foreach (['admin.login_history_deleted_account', 'admin.login_history_deleted_account_hint'] as $clef) {
                $this->assertTrue(
                    Lang::has($clef, $locale, false),
                    "Libelle « {$clef} » manquant en « {$locale} »."
                );
            }
        }
    }
}
