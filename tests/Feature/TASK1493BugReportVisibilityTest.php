<?php

namespace Tests\Feature;

use App\Models\BugReport;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1493 — la page de suivi des bugs reste publique, mais pas au-dela de la
 * publicite de son Organization.
 *
 * ## Pourquoi cette page N'EST PAS fermee
 *
 * TASK-1489 l'avait inscrite comme defaut LATENT : 200 a un anonyme, aucune
 * fuite constatee pour la seule raison que la table etait VIDE.
 *
 * Mais contrairement au commentaire « Public organization-scoped detail routes »
 * de TASK-1488 — faux sur ses trois affirmations — l'intention publique est ici
 * REELLE et ECRITE :
 *
 * - `bugs.empty` : « Aucun bug **public** pour le moment. »
 * - `bugs.subtitle_org` : « ... et des corrections **publiees**. »
 * - la vue rend un bouton **Connexion** a l'invite : elle le prevoit.
 *
 * La page de transparence produit est donc conservee telle quelle. Ce qui
 * manquait, c'est qu'elle n'avait jamais ete confrontee a `is_public` — et
 * `details` est du texte LIBRE ecrit par des membres, jusqu'a 2000 caracteres.
 *
 * La regle appliquee est celle de TASK-1492, mot pour mot : la confidentialite
 * d'une Organization s'etend a ce qu'elle publie.
 *
 * ## Une garde que je croyais manquante, et qui existait
 *
 * J'avais annonce un second defaut : `store` faisant `$request->user()->id`
 * derriere une route sans `auth`, donc un 500 pour un invite. **Faux.** Les deux
 * routes `store` portent `Authenticate` et un throttle ; ma lecture initiale les
 * avait manquees parce que mon propre filtre d'affichage ecartait les classes
 * `Illuminate\...`, dont `Authenticate` fait partie.
 *
 * Le test reste, en mesurant le comportement REEL — un invite est redirige vers
 * la connexion et rien n'est ecrit — parce que cette garde n'etait couverte par
 * aucun test, et qu'une garde non mesuree est une garde qu'un refactor peut
 * retirer sans bruit.
 */
class TASK1493BugReportVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $private;

    private Organization $public;

    private User $privateMember;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->private = Organization::factory()->create(['is_public' => false, 'slug' => 't1493-privee', 'is_active' => true]);
        $this->public = Organization::factory()->create(['is_public' => true, 'slug' => 't1493-publique', 'is_active' => true, 'is_default' => true]);

        $this->privateMember = User::factory()->create(['organization_id' => $this->private->id, 'is_admin' => false]);
        $this->outsider = User::factory()->create(['organization_id' => $this->public->id, 'is_admin' => false]);

        $this->report($this->private, 'DETAIL CONFIDENTIEL T1493');
        $this->report($this->public, 'DETAIL VITRINE T1493');
    }

    private function report(Organization $organization, string $details): BugReport
    {
        return BugReport::create([
            'organization_id' => $organization->id,
            'reporter_id' => User::factory()->create(['organization_id' => $organization->id])->id,
            'reason' => 'Navigation',
            'details' => $details,
            'status' => 'pending',
        ]);
    }

    /**
     * Le defaut LATENT de TASK-1489 devient mesurable : la table n'est plus
     * vide, donc l'absence de fuite ne peut plus venir de l'absence de donnee.
     */
    public function test_a_guest_never_reads_the_bug_list_of_a_private_organization(): void
    {
        $response = $this->get(route('organization.bug-reports.index', [$this->private]));

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('DETAIL CONFIDENTIEL T1493', $response->getContent());
    }

    public function test_a_member_of_another_organization_never_reads_it_either(): void
    {
        $response = $this->actingAs($this->outsider)->get(route('organization.bug-reports.index', [$this->private]));

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('DETAIL CONFIDENTIEL T1493', $response->getContent());
    }

    public function test_a_member_of_the_private_organization_reads_it_normally(): void
    {
        $this->actingAs($this->privateMember)
            ->get(route('organization.bug-reports.index', [$this->private]))
            ->assertOk()
            ->assertSee('DETAIL CONFIDENTIEL T1493');
    }

    /**
     * L'intention publique est preservee, et c'est le coeur de cette TASK : la
     * page de transparence d'une Organization PUBLIQUE reste ouverte a l'invite.
     */
    public function test_the_bug_list_of_a_public_organization_stays_open_to_guests(): void
    {
        $this->get(route('organization.bug-reports.index', [$this->public]))
            ->assertOk()
            ->assertSee('DETAIL VITRINE T1493');
    }

    /**
     * Le comportement REEL, verifie et non suppose : `Authenticate` renvoie
     * l'invite vers la connexion, et rien n'est ecrit.
     */
    public function test_a_guest_cannot_submit_a_bug_report(): void
    {
        $this->post(route('organization.bug-reports.store', [$this->public]), [
            'reason' => 'Navigation',
            'details' => 'Tentative anonyme T1493',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseMissing('bug_reports', ['details' => 'Tentative anonyme T1493']);
    }

    /** Un membre continue de pouvoir signaler. */
    public function test_a_member_can_still_submit_a_bug_report(): void
    {
        $this->actingAs($this->outsider)->post(route('organization.bug-reports.store', [$this->public]), [
            'reason' => 'Navigation',
            'details' => 'Signalement legitime T1493',
        ]);

        $this->assertDatabaseHas('bug_reports', ['details' => 'Signalement legitime T1493']);
    }
}
