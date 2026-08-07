<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Les interactions Livewire d'une Boucle atteinte par son slug.
 *
 * **Ces tests passent par le vrai `POST` Livewire**, et c'est le seul niveau
 * qui pouvait attraper le defaut : un composant monte directement — ce que fait
 * `Livewire::test()` — ne traverse ni route, ni middleware, ni liaison de
 * modele. Toutes les Cards de tous les types de Boucle etaient cassees par
 * slug, et pas un test ne le voyait.
 *
 * Le mecanisme : Livewire rejoue les middlewares persistants de la page
 * d'origine en reconstruisant sa route, mais `request()` reste le
 * `POST /livewire/update`, qui ne porte aucun parametre `organization`. Le
 * binding lisait `request()->route('organization')` — donc `null` — et un slug
 * y tombait dans la branche « pas d'Organization », qui repond 404. Un UUID
 * passait outre, d'ou une Boucle qui marchait par UUID et cassait par slug.
 */
class TASK1103LivewireBySlugTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $membre;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['slug' => 'org-a', 'is_active' => true]);
        $this->membre = User::factory()->create(['organization_id' => $this->org->id]);

        app()->instance('current_organization', $this->org);

        $this->loop = (new LoopService)->createLoop($this->membre, 'Ma Boucle')->fresh();
    }

    /**
     * Le snapshot d'un composant, tel que le navigateur le lirait.
     *
     * Rendu **verbatim** : Livewire signe le snapshot, et le re-encoder en
     * changerait l'ordre des cles ou l'echappement, donc la somme de controle.
     */
    private function snapshot(string $html, string $composant): string
    {
        $this->assertTrue(
            (bool) preg_match_all('/wire:snapshot="([^"]*)"/', $html, $m),
            'aucun composant Livewire dans la page',
        );

        foreach ($m[1] as $brut) {
            $json = html_entity_decode($brut, ENT_QUOTES);

            if ((json_decode($json, true)['memo']['name'] ?? null) === $composant) {
                return $json;
            }
        }

        $this->fail("composant Livewire « {$composant} » introuvable dans la page");
    }

    /** Charger la page, puis declencher une vraie mise a jour Livewire. */
    private function interagir(string $cle, string $composant, ?User $qui = null): TestResponse
    {
        $chemin = "/org/{$this->org->slug}/loops/{$cle}";

        $html = $this->actingAs($qui ?? $this->membre)->get($chemin)->assertOk()->getContent();

        return $this->actingAs($qui ?? $this->membre)
            ->withHeaders(['X-Livewire' => '1', 'Referer' => $chemin])
            ->postJson(route('default-livewire.update'), [
                '_token' => csrf_token(),
                'components' => [[
                    'snapshot' => $this->snapshot($html, $composant),
                    'updates' => [],
                    'calls' => [],
                ]],
            ]);
    }

    // ── Les deux chemins sont equivalents ───────────────────────────────────

    public function test_a_livewire_interaction_works_when_the_page_was_reached_by_slug(): void
    {
        $this->interagir($this->loop->slug, 'loop-chat')->assertOk();
    }

    public function test_a_livewire_interaction_works_when_the_page_was_reached_by_uuid(): void
    {
        $this->interagir($this->loop->id, 'loop-chat')->assertOk();
    }

    public function test_a_second_component_behaves_the_same_by_slug(): void
    {
        // Un seul composant ne prouverait rien : le defaut etait dans la
        // resolution de la Boucle, donc commun a toutes les Cards.
        $this->interagir($this->loop->slug, 'loop-manifesto-card')->assertOk();
        $this->interagir($this->loop->id, 'loop-manifesto-card')->assertOk();
    }

    // ── Le cloisonnement n'a pas bouge ──────────────────────────────────────

    public function test_a_slug_of_another_organization_is_refused(): void
    {
        $autre = Organization::factory()->create(['slug' => 'org-b', 'is_active' => true]);
        $etranger = User::factory()->create(['organization_id' => $autre->id]);

        app()->instance('current_organization', $autre);
        $sienne = (new LoopService)->createLoop($etranger, 'Sa Boucle')->fresh();
        app()->instance('current_organization', $this->org);

        // Le slug de l'autre Organization, sous le prefixe de la notre.
        $this->actingAs($this->membre)
            ->get("/org/{$this->org->slug}/loops/{$sienne->slug}")
            ->assertNotFound();
    }

    public function test_a_uuid_of_another_organization_is_refused(): void
    {
        $autre = Organization::factory()->create(['slug' => 'org-c', 'is_active' => true]);
        $etranger = User::factory()->create(['organization_id' => $autre->id]);

        app()->instance('current_organization', $autre);
        $sienne = (new LoopService)->createLoop($etranger, 'Sa Boucle')->fresh();
        app()->instance('current_organization', $this->org);

        $this->actingAs($this->membre)
            ->get("/org/{$this->org->slug}/loops/{$sienne->id}")
            ->assertNotFound();
    }

    public function test_two_organizations_may_hold_the_same_slug_without_leaking(): void
    {
        $autre = Organization::factory()->create(['slug' => 'org-d', 'is_active' => true]);
        $etranger = User::factory()->create(['organization_id' => $autre->id]);

        app()->instance('current_organization', $autre);
        // **Le meme nom, donc le meme slug**, dans deux Organizations. C'est le
        // cas ou une resolution par slug sans tenant rendrait la mauvaise
        // Boucle — silencieusement, et a la mauvaise personne.
        $jumelle = (new LoopService)->createLoop($etranger, 'Ma Boucle')->fresh();
        app()->instance('current_organization', $this->org);

        $this->assertSame($this->loop->slug, $jumelle->slug, 'le montage exige deux slugs identiques');
        $this->assertNotSame($this->loop->id, $jumelle->id);

        // Chacune voit la sienne, et seulement la sienne.
        $this->actingAs($this->membre)
            ->get("/org/{$this->org->slug}/loops/{$this->loop->slug}")
            ->assertOk()
            ->assertSee($this->loop->id, false);

        $this->actingAs($etranger)
            ->get("/org/{$autre->slug}/loops/{$jumelle->slug}")
            ->assertOk()
            ->assertSee($jumelle->id, false);
    }

    public function test_a_slug_page_still_interacts_when_a_twin_exists_elsewhere(): void
    {
        $autre = Organization::factory()->create(['slug' => 'org-e', 'is_active' => true]);
        $etranger = User::factory()->create(['organization_id' => $autre->id]);

        app()->instance('current_organization', $autre);
        (new LoopService)->createLoop($etranger, 'Ma Boucle');
        app()->instance('current_organization', $this->org);

        // Ce test verifie que l'interaction **aboutit** malgre un homonyme
        // ailleurs — rien de plus.
        //
        // Une version precedente affirmait aussi prouver le cloisonnement, en
        // cherchant l'identifiant de la bonne Boucle dans la reponse. Elle ne
        // prouvait rien : cet identifiant vient du modele rehydrate depuis le
        // snapshot, jamais de la liaison de route. Un binding mal cloisonne
        // aurait rendu la meme reponse. Le cloisonnement est verifie par
        // `test_two_organizations_may_hold_the_same_slug_without_leaking`, qui
        // charge les deux pages.
        $this->interagir($this->loop->slug, 'loop-chat')->assertOk();
    }

    public function test_the_uuid_filter_is_the_only_guard_on_the_livewire_path(): void
    {
        // Sur le replay Livewire **aucun controleur ne tourne** : le filtre par
        // `organization_id` du binding est le seul garde-fou. Une revue a
        // montre qu'aucun test ne le tenait — le 404 que les autres observent
        // vient de `LoopController`, en aval, sur le chemin GET.
        //
        // Ce test l'exerce la ou il est nu : la page est chargee dans une
        // Organization, et l'UUID d'une Boucle d'une autre est glisse dans le
        // chemin du POST.
        $autre = Organization::factory()->create(['slug' => 'org-f', 'is_active' => true]);
        $etranger = User::factory()->create(['organization_id' => $autre->id]);

        app()->instance('current_organization', $autre);
        $sienne = (new LoopService)->createLoop($etranger, 'Boucle voisine')->fresh();
        app()->instance('current_organization', $this->org);

        $chemin = "/org/{$this->org->slug}/loops/{$this->loop->id}";
        $html = $this->actingAs($this->membre)->get($chemin)->assertOk()->getContent();

        // Le snapshot est legitime ; c'est le **chemin** du POST qui designe la
        // Boucle de l'autre Organization.
        $reponse = $this->actingAs($this->membre)
            ->withHeaders(['X-Livewire' => '1', 'Referer' => "/org/{$this->org->slug}/loops/{$sienne->id}"])
            ->postJson(route('default-livewire.update'), [
                '_token' => csrf_token(),
                'components' => [[
                    'snapshot' => $this->snapshot($html, 'loop-chat'),
                    'updates' => [],
                    'calls' => [],
                ]],
            ]);

        // Le contenu de la Boucle voisine ne doit apparaitre nulle part.
        $this->assertStringNotContainsString($sienne->id, $reponse->getContent());
    }

    public function test_an_unknown_slug_is_refused(): void
    {
        $this->actingAs($this->membre)
            ->get("/org/{$this->org->slug}/loops/aucune-boucle-de-ce-nom")
            ->assertNotFound();
    }

    public function test_an_unknown_organization_is_refused(): void
    {
        $this->actingAs($this->membre)
            ->get("/org/organisation-inexistante/loops/{$this->loop->slug}")
            ->assertNotFound();
    }

    public function test_a_non_member_never_reaches_a_private_loop_by_slug(): void
    {
        $etranger = User::factory()->create(['organization_id' => $this->org->id]);

        // La Boucle se resout — meme Organization — mais son contenu reste
        // ferme. Corriger la resolution ne devait rien ouvrir.
        $html = $this->actingAs($etranger)
            ->get("/org/{$this->org->slug}/loops/{$this->loop->slug}")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-loop-workspace-shell', $html);
    }

    public function test_leaving_the_loop_closes_the_reading_immediately(): void
    {
        // **La version GET de ce test ne prouvait rien.** Le cloisonnement ne
        // lache pas au chargement de la page, il lache au `POST` : `$isMember`
        // est une propriete publique, elle voyage dans le snapshot, et Livewire
        // la restituait telle qu'elle etait au chargement.
        //
        // Une personne retiree de la Boucle gardait donc `true` et continuait a
        // lire — y compris des messages postes **apres** son depart — en
        // rejouant son dernier snapshot. Celui-ci est une capacite durable :
        // ni nonce, ni expiration.
        $partant = User::factory()->create(['organization_id' => $this->org->id]);
        (new LoopService)->addMember($this->loop, $partant, 'member');

        $chemin = "/org/{$this->org->slug}/loops/{$this->loop->slug}";
        $html = $this->actingAs($partant)->get($chemin)->assertOk()->getContent();
        $snapshot = $this->snapshot($html, 'loop-chat');

        // Il part, puis quelqu'un ecrit.
        \App\Models\LoopMember::where('loop_id', $this->loop->id)
            ->where('user_id', $partant->id)
            ->delete();

        \App\Models\LoopMessage::create([
            'organization_id' => $this->org->id,
            'loop_id' => $this->loop->id,
            'sender_id' => $this->membre->id,
            'body' => 'APRES-SON-DEPART',
        ]);

        $reponse = $this->actingAs($partant)
            ->withHeaders(['X-Livewire' => '1', 'Referer' => $chemin])
            ->postJson(route('default-livewire.update'), [
                '_token' => csrf_token(),
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => [],
                    'calls' => [],
                ]],
            ]);

        $this->assertStringNotContainsString('APRES-SON-DEPART', $reponse->getContent());
    }
}
