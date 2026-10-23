<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * TASK-1694, bug L — « Envoyer un email » devient un CHOIX, pour cette personne.
 *
 * ## Ce que remplaçait le lien
 *
 * Il menait au CATALOGUE (`/admin/email-templates`) sans rien savoir de la
 * ligne : il fallait y rechoisir le destinataire. Demande de Cyril : cocher le
 * gabarit, puis un bouton d'envoi.
 *
 * ## L'envoi n'est pas réécrit
 *
 * `EmailerService::sendFromTemplate()` reste l'autorité unique — interpolation
 * des variables, `replyTo` sur l'administrateur, et la ligne d'`EmailLog` qui
 * en garde la trace. Les tests le mesurent par cette trace, pas par l'absence
 * d'exception.
 */
class TASK1694AdminSendEmailToUserTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $superAdmin;

    private User $membre;

    private EmailTemplate $gabarit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1694l',
            'is_active' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->membre = User::factory()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        $this->gabarit = EmailTemplate::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Gabarit 1694L',
            'subject' => 'Bonjour {{ first_name }}',
            'content_html' => '<p>Bonjour {{ first_name }}</p>',
        ]);

        Mail::fake();
    }

    private function envoyer(array $payload = [], ?User $cible = null)
    {
        return $this->actingAs($this->superAdmin)->post(
            route('admin.users.send-email', $cible ?? $this->membre),
            $payload + ['template_id' => $this->gabarit->id],
        );
    }

    /** LE geste demandé : le gabarit coché part à cette personne. */
    public function test_the_chosen_template_is_sent_to_that_member(): void
    {
        $this->envoyer()->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas((new EmailLog)->getTable(), [
            'template_id' => $this->gabarit->id,
            'user_id' => $this->membre->id,
            'status' => 'sent',
        ]);
    }

    /**
     * Le gabarit vient du FORMULAIRE, et il est obligatoire.
     *
     * C'est la différence avec le parcours existant, qui le porte dans l'URL :
     * sans choix, rien ne doit partir.
     */
    public function test_no_template_means_no_email(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.users.send-email', $this->membre), [])
            ->assertSessionHasErrors('template_id');

        $this->assertDatabaseCount((new EmailLog)->getTable(), 0);
    }

    /**
     * Un compte banni ne reçoit rien.
     *
     * Même borne que `sendExecute()` (`activeAccount()`), pas une politique
     * inventée ici : le bouton n'existe que dans la ligne, mais une garde de
     * vue n'est pas une protection.
     */
    public function test_a_banned_account_receives_nothing(): void
    {
        $this->membre->update(['banned_at' => now()]);

        $this->envoyer()->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseCount((new EmailLog)->getTable(), 0);
    }

    /** Le geste reste réservé au SuperAdmin plateforme. */
    public function test_a_simple_member_cannot_send(): void
    {
        $simple = User::factory()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($simple)
            ->post(route('admin.users.send-email', $this->membre), ['template_id' => $this->gabarit->id])
            ->assertForbidden();

        $this->assertDatabaseCount((new EmailLog)->getTable(), 0);
    }

    /**
     * L'écran porte le choix, dans la ligne.
     *
     * Le marqueur du bouton d'envoi est mesuré en plus des radios : un bloc de
     * choix sans bouton serait un écran mort.
     */
    public function test_the_row_carries_the_choice_and_the_send_button(): void
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.users'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-user-send-email-choice="'.$this->membre->id.'"', $html);
        $this->assertStringContainsString('data-user-send-email-submit="'.$this->membre->id.'"', $html);
        $this->assertStringContainsString('value="'.$this->gabarit->id.'"', $html);
    }

    /**
     * Et le lien SANS DESTINATAIRE a disparu de la colonne.
     *
     * Sans cette moitié, le bloc pourrait être ajouté **à côté** de l'ancien
     * lien et le test précédent passerait quand même.
     *
     * ## Pourquoi un COMPTAGE, et pas une absence
     *
     * Première version de ce test : « la page ne contient pas ce `href` ».
     * Rouge — et à juste titre : la barre latérale d'administration porte ce
     * lien, légitimement, et il doit y rester. Une absence mesurée sur la page
     * entière confondait deux liens au même `href` et deux rôles opposés.
     *
     * Le comptage les sépare : **une** occurrence, celle de la navigation.
     * Avant, il y en avait une **par ligne de membre** — et la page en porte
     * plusieurs, ce qui rend la mesure sensible au défaut.
     */
    public function test_the_context_free_catalogue_link_left_the_column(): void
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.users'))
            ->assertOk()
            ->getContent();

        $this->assertGreaterThan(
            1,
            substr_count($html, 'data-user-send-email-submit='),
            'la page doit porter plusieurs lignes, sinon le comptage ne mesure rien',
        );

        $this->assertSame(
            1,
            substr_count($html, 'href="'.route('admin.email-templates').'"'),
            'seule la navigation garde le lien vers le catalogue',
        );
    }
}
