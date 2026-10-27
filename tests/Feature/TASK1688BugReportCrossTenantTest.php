<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-1688 / P0-3 — un signalement de bug ne franchit plus la frontiere
 * d'Organization.
 *
 * ## Ce qui a ete mesure AVANT tout code, en base isolee
 *
 * Un membre de l'Organization A postait sur `/org/B/bugs` : HTTP 302, et la
 * table `bug_reports` passait de **0 a 1**, avec
 * `{organization_id: B, reporter_id: <membre de A>}`.
 *
 * ## Pourquoi ce defaut pesait plus lourd que les deux P0 precedents
 *
 * Le contenu est ENTIEREMENT controle par l'appelant — `details` (2000
 * caracteres libres), `reason`, `page_url` — et atterrit sur l'ecran
 * d'ADMINISTRATION du tenant vise. Ce n'etait pas une ligne de trop : c'etait
 * une injection de contenu dans la surface d'autrui.
 *
 * ## Le piege de mesure qu'il a fallu ecarter
 *
 * Au premier passage, cette route rendait 302 **sans rien creer**, et je
 * l'avais notee « refusee ». Faux : `page_url` est valide `url`, et ma charge
 * (`/x`) echouait a la VALIDATION. C'est la validation qui refusait, pas une
 * garde. Tous les tests ci-dessous emploient donc des charges VALIDES — sans
 * quoi ils seraient verts sans rien prouver.
 *
 * ## Comment ces tests prouvent le refus
 *
 * Par l'ETAT DE LA TABLE, avant et apres. Jamais par le code HTTP : c'est un
 * **302** qui accompagnait l'ecriture fautive.
 */
class TASK1688BugReportCrossTenantTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $membreA;

    private User $membreB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'p03-a', 'is_active' => true, 'is_public' => true]);
        $this->orgB = Organization::factory()->create(['slug' => 'p03-b', 'is_active' => true, 'is_public' => true]);

        $this->membreA = User::factory()->create(['organization_id' => $this->orgA->id, 'is_admin' => false, 'email_verified_at' => now()]);
        $this->membreB = User::factory()->create(['organization_id' => $this->orgB->id, 'is_admin' => false, 'email_verified_at' => now()]);
    }

    private function bugs(): int
    {
        return DB::table('bug_reports')->count();
    }

    /** Une charge VALIDE : `page_url` doit passer la regle `url`. */
    private function charge(string $detail = 'signalement'): array
    {
        return [
            'reason' => 'other',
            'details' => $detail,
            'page_url' => 'https://test.laravel/loops',
        ];
    }

    // ────────────────────────────────────────────────────────────────────────
    // Le defaut prouve, dans les DEUX SENS
    // ────────────────────────────────────────────────────────────────────────

    public function test_a_member_of_a_cannot_report_into_b(): void
    {
        $avant = $this->bugs();

        $this->actingAs($this->membreA)
            ->post("/org/{$this->orgB->slug}/bugs", $this->charge('INJECTE DEPUIS A'))
            ->assertNotFound();

        $this->assertSame($avant, $this->bugs(), 'aucune ligne ne doit etre creee dans le tenant etranger');
        $this->assertDatabaseMissing('bug_reports', ['details' => 'INJECTE DEPUIS A']);
    }

    public function test_a_member_of_b_cannot_report_into_a(): void
    {
        $avant = $this->bugs();

        $this->actingAs($this->membreB)
            ->post("/org/{$this->orgA->slug}/bugs", $this->charge('INJECTE DEPUIS B'))
            ->assertNotFound();

        $this->assertSame($avant, $this->bugs());
    }

    // ────────────────────────────────────────────────────────────────────────
    // Aucun oracle d'existence
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Une Organization qui EXISTE et un slug qui n'existe nulle part doivent
     * rendre la MEME chose : sinon le refus devient un detecteur d'existence,
     * ce que la doctrine T1601-T1606 a explicitement refuse.
     */
    public function test_an_unknown_slug_is_indistinguishable_from_a_foreign_one(): void
    {
        $avant = $this->bugs();

        $existante = $this->actingAs($this->membreA)
            ->post("/org/{$this->orgB->slug}/bugs", $this->charge());

        $inconnue = $this->actingAs($this->membreA)
            ->post('/org/p03-aucune-org/bugs', $this->charge());

        $this->assertSame(404, $existante->status());
        $this->assertSame($existante->status(), $inconnue->status());

        foreach ([$existante, $inconnue] as $reponse) {
            $corps = $reponse->getContent() ?: '';
            $this->assertStringNotContainsString($this->orgB->name, $corps);
            $this->assertStringNotContainsString($this->orgB->slug, $corps);
        }

        $this->assertSame($avant, $this->bugs());
    }

    // ────────────────────────────────────────────────────────────────────────
    // Le SuperAdmin ne participe pas
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Doctrine TASK-1658 : « inspecter n'est pas participer ». Signaler un bug
     * est un geste de MEMBRE ; aucune exemption `is_admin` ne doit l'ouvrir sur
     * le tenant d'autrui.
     */
    public function test_a_super_admin_gets_no_implicit_participation(): void
    {
        $superAdmin = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $avant = $this->bugs();

        $this->actingAs($superAdmin)
            ->post("/org/{$this->orgB->slug}/bugs", $this->charge('SUPERADMIN'))
            ->assertNotFound();

        $this->assertSame($avant, $this->bugs(), 'le SuperAdmin ne doit pas ecrire dans un tenant etranger');
    }

    // ────────────────────────────────────────────────────────────────────────
    // Le flux LEGITIME n'a pas bouge
    // ────────────────────────────────────────────────────────────────────────

    public function test_a_member_still_reports_in_their_own_organization(): void
    {
        $avant = $this->bugs();

        $this->actingAs($this->membreA)
            ->post("/org/{$this->orgA->slug}/bugs", $this->charge('LEGITIME'))
            ->assertRedirect();

        $this->assertSame($avant + 1, $this->bugs());

        $ligne = DB::table('bug_reports')->where('details', 'LEGITIME')->first();
        $this->assertNotNull($ligne);
        $this->assertSame($this->orgA->id, $ligne->organization_id);
        $this->assertSame($this->membreA->id, $ligne->reporter_id);
    }

    /**
     * La forme NON prefixee reste ouverte. Mesure faite AVANT le correctif :
     * elle rattache deja le signalement a l'Organization de l'AUTEUR — et non
     * a celle par defaut de la plateforme — donc le premier predicat y passe.
     * Ce test fige ce comportement, pour qu'une garde future ne le ferme pas
     * par inadvertance.
     */
    public function test_the_unprefixed_form_still_works(): void
    {
        $avant = $this->bugs();

        $this->actingAs($this->membreA)
            ->post('/bugs', $this->charge('NON PREFIXEE'))
            ->assertRedirect();

        $this->assertSame($avant + 1, $this->bugs());

        $ligne = DB::table('bug_reports')->where('details', 'NON PREFIXEE')->first();
        $this->assertNotNull($ligne);
        $this->assertSame($this->orgA->id, $ligne->organization_id,
            'le signalement non prefixe doit rester rattache a l\'Organization de son auteur');
    }

    // ────────────────────────────────────────────────────────────────────────
    // Garde-fou : la validation n'est pas la garde
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Le piege qui avait rendu la premiere mesure fausse. Une charge INVALIDE
     * est refusee par la VALIDATION (302 vers l'arriere), pas par la garde de
     * tenant. Ce test fige la distinction : si la garde disparaissait, ce cas
     * resterait 302 alors que les cas ci-dessus passeraient a 404.
     */
    public function test_an_invalid_payload_is_refused_by_validation_not_by_the_guard(): void
    {
        $avant = $this->bugs();

        $reponse = $this->actingAs($this->membreA)->post(
            "/org/{$this->orgA->slug}/bugs",
            ['reason' => 'other', 'details' => 'x', 'page_url' => '/pas-une-url']
        );

        $reponse->assertRedirect();
        $reponse->assertSessionHasErrors('page_url');
        $this->assertSame($avant, $this->bugs());
    }
}
