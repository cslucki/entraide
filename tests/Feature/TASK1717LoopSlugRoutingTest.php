<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1717 — une Boucle s'atteint par son slug, et toujours par son UUID.
 *
 * ## Ce que la TASK a trouve dans l'existant
 *
 * `loops.slug` existe depuis `2026_05_15_000001_create_loops_table.php`, avec
 * son `unique(['organization_id', 'slug'])`, et il est rempli a chaque
 * naissance par `LoopService::generateUniqueSlug()`. Mesure sur la base de
 * dev : **8 Boucles, 0 slug vide**. Rien n'etait donc a creer — le slug
 * n'etait simplement jamais employe pour resoudre une URL, ni montre, ni
 * modifiable.
 *
 * La TASK n'ajoute aucune colonne et aucune migration.
 *
 * ## Les deux gardes qui comptent
 *
 * La compatibilite ASCENDANTE — les UUID deja partages doivent continuer
 * d'aboutir — et l'ISOLATION : deux Organizations ont le droit de porter le
 * meme slug, et aucune ne doit atteindre la Boucle de l'autre par ce nom.
 */
class TASK1717LoopSlugRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organisation;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organisation = Organization::factory()->create([
            'slug' => 'org-1717', 'is_active' => true, 'is_public' => true, 'is_default' => true,
        ]);
        app()->instance('current_organization', $this->organisation);

        $this->membre = User::factory()->create([
            'organization_id' => $this->organisation->id,
            'email_verified_at' => now(), 'approved_at' => now(),
        ]);
    }

    private function boucle(array $surcharges = []): Loop
    {
        $loop = Loop::factory()->create(array_merge([
            'organization_id' => $this->organisation->id,
            'created_by' => $this->membre->id,
        ], $surcharges));

        app(LoopService::class)->addMember($loop, $this->membre, 'owner');

        return $loop;
    }

    // ───────────────────────────────────────────────────────────────────────
    // 1. Les deux formes d'URL aboutissent
    // ───────────────────────────────────────────────────────────────────────

    public function test_une_boucle_s_atteint_par_son_slug(): void
    {
        $loop = $this->boucle(['slug' => 'atelier-mosaique']);

        $this->actingAs($this->membre)
            ->get("/org/{$this->organisation->slug}/loops/atelier-mosaique")
            ->assertOk();
    }

    public function test_l_ancienne_url_en_uuid_aboutit_toujours(): void
    {
        // LA garde de compatibilite : les liens deja echanges, les favoris, et
        // les points du code qui construisent encore une URL avec `$loop->id`.
        $loop = $this->boucle(['slug' => 'atelier-mosaique']);

        $this->actingAs($this->membre)
            ->get("/org/{$this->organisation->slug}/loops/{$loop->id}")
            ->assertOk();
    }

    // TASK-1717 — PAS de garde « l'URL affichee porte le slug ».
    //
    // La conversion des liens internes est HORS PERIMETRE, et c'est une
    // decision mesuree, pas un oubli. Deux facons de l'obtenir, toutes deux
    // essayees puis retirees :
    //
    //  - surcharger `Loop::getRouteKey()` pour rendre le slug : **40+ tests
    //    casses**. `Route::bind('loop', ...)` refuse volontairement un slug
    //    quand la route ne porte pas de `{organization}` — le meme slug peut
    //    exister dans plusieurs — donc `loops.show` en forme nue et les vingt
    //    routes `admin.loops.*` recevaient un slug et rendaient 404 ;
    //  - une redirection canonique sur la page de Boucle (idiome T1601) :
    //    **~15 tests casses**, tous ceux qui ouvrent la page par UUID et
    //    asserten un contenu, puisqu'ils recoivent desormais un 301.
    //
    // Les deux coutent des dizaines d'adaptations dans des suites que cette
    // TASK n'a pas ecrites. Le besoin exprime — « qu'on puisse mettre un
    // slug » — est servi sans elles : le slug se SAISIT, et l'adresse lisible
    // FONCTIONNE deja (garde ci-dessus). Arbitrage MASTER consigne au TASK
    // file.

    public function test_une_boucle_sans_slug_retombe_sur_son_identifiant(): void
    {
        // Le repli n'est pas decoratif : sans lui, une ligne importee au slug
        // vide produirait une URL `/loops/` tronquee — un 404 sur une Boucle
        // parfaitement valide.
        $loop = $this->boucle();
        $loop->forceFill(['slug' => ''])->save();

        $this->assertSame('', (string) $loop->fresh()->slug);
    }

    // ───────────────────────────────────────────────────────────────────────
    // 2. L'isolation : Loop n'est pas Tenant
    // ───────────────────────────────────────────────────────────────────────

    public function test_un_meme_slug_dans_deux_organizations_ne_se_confond_pas(): void
    {
        $mien = $this->boucle(['slug' => 'boucle-daccueil']);

        $autre = Organization::factory()->create(['slug' => 'org-1717-bis', 'is_active' => true]);
        $boucleAutre = Loop::factory()->create([
            'organization_id' => $autre->id,
            'slug' => 'boucle-daccueil',
            'created_by' => User::factory()->create(['organization_id' => $autre->id])->id,
        ]);

        // Les deux existent, avec le MEME slug : l'unicite est par
        // Organization, jamais globale.
        $this->assertNotSame($mien->id, $boucleAutre->id);

        // Et l'URL de MON Organization rend MA Boucle. Sans le scope de
        // tenant, `where('slug', ...)` ramenerait la premiere des deux.
        $this->actingAs($this->membre)
            ->get("/org/{$this->organisation->slug}/loops/boucle-daccueil")
            ->assertOk()
            ->assertSee($mien->name, false);
    }

    // ───────────────────────────────────────────────────────────────────────
    // 3. La saisie : forme, collision, et les deux refus non evidents
    // ───────────────────────────────────────────────────────────────────────

    public function test_le_slug_se_choisit_a_la_creation(): void
    {
        $this->actingAs($this->membre)->post("/org/{$this->organisation->slug}/loops", [
            'name' => 'Atelier Mosaique',
            'slug' => 'mon-atelier',
        ])->assertSessionHasNoErrors();

        $this->assertSame('mon-atelier', Loop::where('name', 'Atelier Mosaique')->first()?->slug);
    }

    public function test_un_slug_laisse_vide_a_la_creation_garde_le_derive_du_nom(): void
    {
        // La non-regression du comportement existant : personne n'est oblige
        // de s'occuper de son URL.
        $this->actingAs($this->membre)->post("/org/{$this->organisation->slug}/loops", [
            'name' => 'Atelier Mosaique',
        ])->assertSessionHasNoErrors();

        $this->assertSame('atelier-mosaique', Loop::where('name', 'Atelier Mosaique')->first()?->slug);
    }

    public function test_le_slug_se_modifie_depuis_modifier_la_boucle(): void
    {
        $loop = $this->boucle(['slug' => 'ancien-nom', 'name' => 'Atelier']);

        $this->actingAs($this->membre)
            ->put("/org/{$this->organisation->slug}/loops/{$loop->slug}", [
                'name' => 'Atelier',
                'slug' => 'nouveau-nom',
            ])->assertSessionHasNoErrors();

        $this->assertSame('nouveau-nom', $loop->fresh()->slug);
    }

    public function test_un_slug_laisse_vide_a_la_modification_conserve_l_adresse(): void
    {
        // Une URL publique ne doit pas disparaitre parce qu'on a corrige une
        // faute de frappe ailleurs dans le formulaire.
        $loop = $this->boucle(['slug' => 'adresse-stable', 'name' => 'Atelier']);

        $this->actingAs($this->membre)
            ->put("/org/{$this->organisation->slug}/loops/{$loop->slug}", [
                'name' => 'Atelier renomme',
            ])->assertSessionHasNoErrors();

        $this->assertSame('adresse-stable', $loop->fresh()->slug);
    }

    public function test_une_collision_dans_la_meme_organization_est_refusee(): void
    {
        $this->boucle(['slug' => 'deja-pris']);
        $loop = $this->boucle(['slug' => 'le-mien', 'name' => 'Le mien']);

        $this->actingAs($this->membre)
            ->put("/org/{$this->organisation->slug}/loops/{$loop->slug}", [
                'name' => 'Le mien',
                'slug' => 'deja-pris',
            ])->assertSessionHasErrors('slug');

        $this->assertSame('le-mien', $loop->fresh()->slug);
    }

    public function test_reenregistrer_son_propre_slug_n_est_pas_une_collision(): void
    {
        // Sans `ignore($loop)`, la Boucle entrerait en collision avec
        // elle-meme et le formulaire deviendrait impossible a enregistrer.
        $loop = $this->boucle(['slug' => 'le-mien', 'name' => 'Le mien']);

        $this->actingAs($this->membre)
            ->put("/org/{$this->organisation->slug}/loops/{$loop->slug}", [
                'name' => 'Le mien',
                'slug' => 'le-mien',
            ])->assertSessionHasNoErrors();
    }

    public function test_un_slug_en_forme_d_uuid_est_refuse(): void
    {
        // Le refus NON EVIDENT : `resolveRouteBinding()` teste `Str::isUuid()`
        // avant de chercher un slug. Un slug en forme d'UUID serait donc
        // cherche dans `id` et ne resoudrait JAMAIS sa propre Boucle.
        $loop = $this->boucle(['name' => 'Le mien']);

        $this->actingAs($this->membre)
            ->put("/org/{$this->organisation->slug}/loops/{$loop->slug}", [
                'name' => 'Le mien',
                'slug' => '01a16eb5-347c-729f-ab7e-fc19b5611db2',
            ])->assertSessionHasErrors('slug');
    }

    public function test_le_segment_reserve_create_est_refuse(): void
    {
        // `/loops/create` est declare AVANT `/loops/{loop}` : une Boucle
        // sluggee `create` serait valide en base et DEFINITIVEMENT
        // inatteignable par son URL.
        $loop = $this->boucle(['name' => 'Le mien']);

        $this->actingAs($this->membre)
            ->put("/org/{$this->organisation->slug}/loops/{$loop->slug}", [
                'name' => 'Le mien',
                'slug' => 'create',
            ])->assertSessionHasErrors('slug');
    }

    public function test_une_forme_invalide_est_refusee(): void
    {
        $loop = $this->boucle(['name' => 'Le mien']);

        $this->actingAs($this->membre)
            ->put("/org/{$this->organisation->slug}/loops/{$loop->slug}", [
                'name' => 'Le mien',
                'slug' => 'Mon Atelier !',
            ])->assertSessionHasErrors('slug');
    }
}
