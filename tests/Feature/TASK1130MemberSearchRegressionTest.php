<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\DossierMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossiers\PersonalDocumentsRoot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * « Partager → Ajouter une personne » dans un Dossier personnel (TASK-1130).
 *
 * Regression signalee par Cyril avant merge : la recherche ne rendait plus
 * aucun resultat, par email comme par nom.
 *
 * Cause : le panneau adressait ses requetes a la **racine gouvernante**, parce
 * qu'un enfant n'a pas de `dossier_members` a lui. Des que cette racine etait
 * « Mes documents », `manageMembers` la refusait — inviter quelqu'un dans
 * l'espace personnel entier n'a pas de sens — et `members/search` repondait
 * 403. L'interface promettait un geste que le serveur refusait.
 *
 * Correction : le panneau adresse le **dossier ouvert**. Les cinq endpoints
 * membres autorisent sur ce dossier PUIS remontent au gouvernant, donc
 * l'ecriture atterrit exactement au meme endroit qu'avant.
 *
 * Ces tests gardent les deux moities de cette phrase :
 * la recherche repond sur un sous-dossier, et les membres continuent d'etre
 * ecrits sur la racine.
 */
class TASK1130MemberSearchRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $autreOrg;

    private User $proprietaire;

    private User $collegue;

    private Dossier $racinePersonnelle;

    private Dossier $sousDossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $this->autreOrg = Organization::factory()->create(['is_active' => true]);

        $this->proprietaire = User::factory()->create(['organization_id' => $this->org->id]);
        $this->collegue = User::factory()->create([
            'organization_id' => $this->org->id,
            'first_name' => 'Amandine',
            'name' => 'Berthier',
            'email' => 'amandine.berthier@example.test',
        ]);

        app()->instance('current_organization', $this->org);

        $this->racinePersonnelle = app(PersonalDocumentsRoot::class)
            ->resolve($this->org->id, $this->proprietaire->id);

        $this->sousDossier = Dossier::create([
            'organization_id' => $this->org->id,
            'parent_id' => $this->racinePersonnelle->id,
            'name' => 'Projet Marseille',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
    }

    private function chercher(Dossier $dossier, string $q, ?User $acteur = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($acteur ?? $this->proprietaire)->getJson(
            route('organization.dossiers.members.search', [
                'organization' => $this->org->slug,
                'dossier' => $dossier->getKey(),
                'q' => $q,
            ])
        );
    }

    // ── La recherche depuis un sous-dossier personnel ────────────────────────

    public function test_searching_by_email_from_a_personal_subfolder_returns_the_user(): void
    {
        $this->chercher($this->sousDossier, 'amandine.berthier@example.test')
            ->assertOk()
            ->assertJsonPath('users.0.id', $this->collegue->getKey());
    }

    public function test_searching_by_first_name_from_a_personal_subfolder_returns_the_user(): void
    {
        $this->chercher($this->sousDossier, 'Amandine')
            ->assertOk()
            ->assertJsonPath('users.0.id', $this->collegue->getKey());
    }

    public function test_searching_by_last_name_from_a_personal_subfolder_returns_the_user(): void
    {
        $this->chercher($this->sousDossier, 'Berthier')
            ->assertOk()
            ->assertJsonPath('users.0.id', $this->collegue->getKey());
    }

    public function test_searching_is_case_insensitive_and_partial(): void
    {
        $this->chercher($this->sousDossier, 'berth')
            ->assertOk()
            ->assertJsonPath('users.0.id', $this->collegue->getKey());
    }

    // ── La racine personnelle elle-meme ne se partage pas ────────────────────

    public function test_the_personal_documents_root_still_refuses_member_management(): void
    {
        // C'est la regle qui avait provoque la regression, et elle reste vraie :
        // inviter quelqu'un dans « Mes documents » partagerait tout l'espace
        // personnel, y compris ce qui n'y est pas encore.
        $this->chercher($this->racinePersonnelle, 'Amandine')
            ->assertStatus(403);
    }

    // ── Une racine personnelle ORDINAIRE reste partageable ───────────────────

    public function test_a_plain_personal_root_still_searches(): void
    {
        // Les Dossiers d'avant TASK-1130 sont restes des racines a part
        // entiere : aucun backfill ne les a nestes sous la racine systeme.
        $racineOrdinaire = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => $this->proprietaire->id,
            'name' => 'Dossier historique',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        $this->chercher($racineOrdinaire, 'Amandine')
            ->assertOk()
            ->assertJsonPath('users.0.id', $this->collegue->getKey());
    }

    // ── L'ecriture atterrit sur la racine, pas sur l'enfant ──────────────────

    public function test_adding_a_member_from_a_subfolder_writes_on_the_governing_root(): void
    {
        $this->actingAs($this->proprietaire)->postJson(
            route('organization.dossiers.members.store', [
                'organization' => $this->org->slug,
                'dossier' => $this->sousDossier->getKey(),
            ]),
            ['user_id' => $this->collegue->getKey(), 'role' => 'reader'],
        )->assertSuccessful();

        // Le modele de partage est inchange : les membres vivent sur la racine
        // gouvernante, jamais sur l'enfant.
        $this->assertSame(0, DossierMember::where('dossier_id', $this->sousDossier->getKey())->count());
        $this->assertDatabaseHas('dossier_members', [
            'dossier_id' => $this->racinePersonnelle->getKey(),
            'user_id' => $this->collegue->getKey(),
        ]);
    }

    public function test_an_existing_member_is_not_offered_again(): void
    {
        DossierMember::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $this->racinePersonnelle->id,
            'user_id' => $this->collegue->id,
            'role' => 'reader',
            'added_by' => $this->proprietaire->id,
        ]);

        $this->chercher($this->sousDossier, 'Amandine')
            ->assertOk()
            ->assertJsonCount(0, 'users');
    }

    // ── Le defaut REEL : quelle adresse la vue donne-t-elle au panneau ? ─────

    public function test_the_share_panel_addresses_the_opened_folder_not_the_root(): void
    {
        // Les tests ci-dessus eprouvent l'endpoint — qui, lui, fonctionnait
        // deja. Le defaut vivait dans la VUE : elle passait au panneau l'id de
        // la racine gouvernante, et c'est ce choix-la qui declenchait le 403.
        // Sans ce test, la regression pourrait revenir sans qu'aucun autre ne
        // bronche.
        $html = $this->actingAs($this->proprietaire)
            ->get(route('organization.dossiers.show', [
                'organization' => $this->org->slug,
                'dossier' => $this->sousDossier->getKey(),
            ]))
            ->assertOk()
            ->getContent();

        $adresse = fn (string $id) => '\u0022dossierId\u0022:\u0022'.$id.'\u0022';

        $this->assertStringContainsString(
            $adresse($this->sousDossier->getKey()),
            $html,
            'Le panneau Partager doit adresser le dossier ouvert.',
        );
        $this->assertStringNotContainsString(
            $adresse($this->racinePersonnelle->getKey()),
            $html,
            'Adresser la racine « Mes documents » fait repondre 403 a members/search.',
        );
    }

    // ── Frontiere tenant ─────────────────────────────────────────────────────

    public function test_a_user_of_another_organization_is_never_revealed(): void
    {
        User::factory()->create([
            'organization_id' => $this->autreOrg->id,
            'first_name' => 'Amandine',
            'name' => 'Ailleurs',
            'email' => 'amandine.ailleurs@example.test',
        ]);

        $reponse = $this->chercher($this->sousDossier, 'Amandine')->assertOk();

        $ids = collect($reponse->json('users'))->pluck('id')->all();
        $this->assertSame([$this->collegue->getKey()], $ids);
    }

    public function test_someone_else_cannot_search_in_this_personal_space(): void
    {
        $this->chercher($this->sousDossier, 'Amandine', $this->collegue)
            ->assertStatus(403);
    }
}
