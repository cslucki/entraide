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
     * TASK-1694 bug V — LE DESIGN A CHANGE, sur demande de Cyril.
     *
     * Le bug L avait mis le choix **dans la ligne**, en flux, pour échapper au
     * rognage de l'`overflow-x-auto` du tableau. Cyril, le 24/10 : « certes
     * contextualisé mais trop petit, et si on a plus de 10 messages types ce
     * sera ingérable ».
     *
     * La ligne ne porte donc plus que le **déclencheur**, et un modal unique,
     * posé hors du tableau, porte le choix. Les marqueurs par ligne
     * (`...-choice="{id}"`) n'ont plus de sens — un modal par ligne aurait
     * rendu 20 copies de la même liste sur une page de 20 membres.
     *
     * Ce que le test mesure désormais : chaque ligne porte son déclencheur
     * **avec son URL d'envoi**, et la page porte **un** modal.
     */
    public function test_each_row_triggers_the_modal_with_its_own_send_url(): void
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.users'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-user-send-email-toggle="'.$this->membre->id.'"', $html);
        $this->assertStringContainsString(
            'data-user-send-email-url="'.route('admin.users.send-email', $this->membre).'"',
            $html,
            "l'URL vient du serveur, elle n'est pas recomposee en JavaScript",
        );
    }

    /**
     * Et le modal est UNIQUE pour toute la page.
     *
     * C'est la moitié qui distingue ce design du précédent : la liste des
     * gabarits n'est rendue qu'une fois, quel que soit le nombre de lignes.
     */
    public function test_the_page_carries_exactly_one_modal(): void
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.users'))
            ->assertOk()
            ->getContent();

        $this->assertGreaterThan(
            1,
            substr_count($html, 'data-user-send-email-toggle='),
            'premisse : la page doit porter plusieurs lignes',
        );

        $this->assertSame(1, substr_count($html, 'data-user-send-email-modal'));
        $this->assertSame(1, substr_count($html, 'data-user-send-email-submit'));
        $this->assertSame(
            1,
            substr_count($html, 'value="'.$this->gabarit->id.'"'),
            'un gabarit n\'est propose QU\'UNE fois, pas une fois par ligne',
        );
    }

    /**
     * TASK-1694 bug V — le modal est posé HORS du tableau.
     *
     * C'est toute sa raison d'être, et c'est une garantie **structurelle** :
     * en TASK-1693 bug D, un popover `absolute` dans cette colonne s'était
     * révélé **rogné** par l'`overflow-x-auto` du tableau, donc inatteignable
     * sur desktop. Le bug L avait contourné en restant en flux ; celui-ci sort
     * du conteneur.
     *
     * Mesuré par la POSITION dans le document : un modal rendu à l'intérieur
     * du tableau serait visuellement cassé sans qu'aucune assertion de contenu
     * ne s'en aperçoive.
     */
    public function test_the_modal_sits_outside_the_scrolling_table(): void
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.users'))
            ->assertOk()
            ->getContent();

        $finDuTableau = strrpos($html, '</table>');
        $modal = strpos($html, 'data-user-send-email-modal');

        $this->assertNotFalse($finDuTableau);
        $this->assertNotFalse($modal);
        $this->assertGreaterThan(
            $finDuTableau,
            $modal,
            "le modal doit etre apres la fermeture du tableau, sinon l'`overflow-x-auto` le rogne",
        );
    }

    /**
     * Le filtre n'apparaît que quand il sert.
     *
     * C'est la demande même de Cyril : « si on a plus de 10 messages types, ce
     * sera ingérable ». En dessous du seuil, un champ de plus est du bruit —
     * les deux moitiés sont mesurées, sinon le seuil ne voudrait rien dire.
     */
    public function test_the_filter_appears_only_when_the_list_is_long(): void
    {
        $sans = $this->actingAs($this->superAdmin)
            ->get(route('admin.users'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-user-send-email-filter', $sans);

        EmailTemplate::factory()->count(9)->create([
            'organization_id' => $this->organization->id,
        ]);

        $avec = $this->actingAs($this->superAdmin)
            ->get(route('admin.users'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-user-send-email-filter', $avec);
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
            substr_count($html, 'data-user-send-email-toggle='),
            'la page doit porter plusieurs lignes, sinon le comptage ne mesure rien',
        );

        $this->assertSame(
            1,
            substr_count($html, 'href="'.route('admin.email-templates').'"'),
            'seule la navigation garde le lien vers le catalogue',
        );
    }
}
