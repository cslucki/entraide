<?php

namespace Tests\Feature;

use App\Models\CrmContact;
use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1694, bug Z — la langue d'un gabarit de l'Emailer, et le choix à l'envoi.
 *
 * ## La demande
 *
 * Cyril : « il faudrait ajouter un champ pour indiquer si c'est une version FR
 * ou EN [...] et du coup quand on envoie l'email depuis `/admin/users`, qu'on
 * puisse choisir FR ou EN » — puis, dans la foulée : « idem si on envoie un
 * email depuis `/org/.../admin/relations/{contact}` ».
 *
 * ## `null` est une troisième valeur, pas un trou
 *
 * « Langue non précisée » est l'état des lignes antérieures au 24/10 : personne
 * ne leur a jamais posé la question. Les déclarer françaises d'office serait une
 * supposition écrite en base.
 *
 * Conséquence voulue, et mesurée : un gabarit sans langue reste proposé dans
 * **les deux** langues. Mieux vaut montrer un gabarit dont on ignore la langue
 * que le faire disparaître d'un écran d'envoi sans rien dire.
 *
 * ## Ce que la colonne N'EST PAS
 *
 * Elle n'apparie pas deux versions d'un même gabarit : `email_templates.slug`
 * est **unique globalement** (relevé du 24/10), donc deux versions
 * linguistiques sont deux lignes à slugs distincts, sans lien entre elles.
 */
class TASK1694EmailTemplateLocaleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1694z',
            'is_active' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        EmailTemplate::query()->delete();
    }

    private function gabarit(?string $locale, string $nom): EmailTemplate
    {
        return EmailTemplate::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => $nom,
            'slug' => \Illuminate\Support\Str::slug($nom),
            'locale' => $locale,
        ]);
    }

    /** La colonne existe, et accepte les trois états. */
    public function test_a_template_can_be_french_english_or_unspecified(): void
    {
        foreach (['fr', 'en', null] as $locale) {
            $gabarit = $this->gabarit($locale, 'Gabarit '.($locale ?? 'sans'));

            $this->assertSame($locale, $gabarit->fresh()->locale);
        }
    }

    /** Le formulaire de CRÉATION porte le champ, et l'enregistre. */
    public function test_the_create_form_carries_and_saves_the_locale(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.email-templates.create'))
            ->assertOk()
            ->assertSee('data-emailer-locale', false);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.email-templates.store'), [
                'slug' => 'gabarit-1694z',
                'name' => 'Gabarit 1694Z',
                'locale' => 'en',
                'subject' => 'Sujet',
                'content_html' => '<p>corps</p>',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('email_templates', ['slug' => 'gabarit-1694z', 'locale' => 'en']);
    }

    /** Le formulaire de MODIFICATION aussi. */
    public function test_the_edit_form_carries_and_saves_the_locale(): void
    {
        $gabarit = $this->gabarit('fr', 'A modifier');

        $this->actingAs($this->superAdmin)
            ->get(route('admin.email-templates.edit', $gabarit))
            ->assertOk()
            ->assertSee('data-emailer-locale', false);

        $this->actingAs($this->superAdmin)
            ->put(route('admin.email-templates.update', $gabarit), [
                'slug' => $gabarit->slug,
                'name' => $gabarit->name,
                'locale' => 'en',
                'subject' => 'Sujet',
                'content_html' => '<p>corps</p>',
            ])
            ->assertRedirect();

        $this->assertSame('en', $gabarit->fresh()->locale);
    }

    /** Et « non précisée » peut être REPOSÉE : ce n'est pas un aller simple. */
    public function test_the_locale_can_be_cleared_again(): void
    {
        $gabarit = $this->gabarit('fr', 'A vider');

        $this->actingAs($this->superAdmin)
            ->put(route('admin.email-templates.update', $gabarit), [
                'slug' => $gabarit->slug,
                'name' => $gabarit->name,
                'locale' => '',
                'subject' => 'Sujet',
                'content_html' => '<p>corps</p>',
            ])
            ->assertRedirect();

        $this->assertNull($gabarit->fresh()->locale);
    }

    /** Une langue inconnue est refusée. */
    public function test_an_unknown_locale_is_refused(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.email-templates.store'), [
                'slug' => 'gabarit-de-vers',
                'name' => 'De',
                'locale' => 'de',
                'subject' => 'Sujet',
                'content_html' => '<p>corps</p>',
            ])
            ->assertSessionHasErrors('locale');

        $this->assertDatabaseMissing('email_templates', ['slug' => 'gabarit-de-vers']);
    }

    /**
     * LE geste demandé, surface 1 : le modal de `/admin/users` offre le choix
     * FR/EN, et chaque gabarit montre sa langue.
     */
    public function test_the_users_modal_offers_the_language_choice(): void
    {
        $this->gabarit('fr', 'Version francaise 1694Z');
        $this->gabarit('en', 'Version anglaise 1694Z');

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.users'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-user-send-email-locale', $html);
        $this->assertStringContainsString('data-user-send-email-template-locale="fr"', $html);
        $this->assertStringContainsString('data-user-send-email-template-locale="en"', $html);
    }

    /**
     * Et un gabarit sans langue y est marqué comme tel.
     *
     * Sans cette moitié, un gabarit non renseigné pourrait être affiché comme
     * français par défaut — une langue inventée.
     */
    public function test_an_unspecified_template_is_shown_as_such(): void
    {
        $this->gabarit(null, 'Sans langue 1694Z');

        // Un gabarit RENSEIGNE sur la meme page : sans lui, ce test passerait a
        // vide. Mesure faite — le sabotage qui retire `locale` de la requete
        // rend TOUT gabarit « non precise », et une assertion isolee sur la
        // chaine vide restait verte. C'est le contraste qui mesure, pas
        // l'absence.
        $this->gabarit('fr', 'Avec langue 1694Z');

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.users'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-user-send-email-template-locale=""', $html);
        $this->assertStringContainsString('data-user-send-email-template-locale="fr"', $html);
    }

    /**
     * LE geste demandé, surface 2 : la fiche Contact CRM porte la langue dans
     * le libellé de chaque option.
     *
     * Ici le choix se fait par un `<select>` natif : c'est la même commande qui
     * choisit le gabarit, et y ajouter un second contrôle alourdirait une fiche
     * déjà dense. On choisit donc « Relance (FR) » en un geste.
     */
    public function test_the_crm_contact_sheet_shows_the_language_of_each_template(): void
    {
        $this->gabarit('fr', 'Relance 1694Z');

        $contact = CrmContact::factory()->create([
            'organization_id' => $this->organization->id,
            'email' => 'contact-1694z@example.test',
        ]);

        $this->organization->update(['admin_id' => $this->superAdmin->id]);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('organization.admin.crm.contacts.show', [
                'organization' => $this->organization->slug,
                'contact' => $contact->id,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-crm-email-template-locale="fr"', $html);
        $this->assertStringContainsString('Relance 1694Z (FR)', $html);
    }
}
