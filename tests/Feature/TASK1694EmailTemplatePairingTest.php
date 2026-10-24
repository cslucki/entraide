<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminEmailTemplatesController as Ecran;
use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1694, bug AA — un gabarit s'identifie par (slug, organisation, langue).
 *
 * ## Les deux demandes, et pourquoi c'est UN seul geste
 *
 * 7. « Oui je veux un véritable appariement » — `relance` doit exister en FR et
 *    en EN, **sous le même slug**.
 * 8. « Un menu pour choisir l'organisation (ou global) avec des cases à cocher,
 *    pour créer un email type pour plusieurs organisations d'un coup. »
 *
 * Les deux butaient sur **la même** contrainte : `email_templates.slug` était
 * unique **globalement**. Le code la contournait déjà —
 * `CrmEmailTemplateService` fabrique des slugs namespacés (`main-propal`) pour
 * ne jamais collisionner, ce qui est le signe que la contrainte gênait.
 *
 * ## La limite, nommée
 *
 * `organization_id` et `locale` sont nullables, et deux NULL sont **distincts**
 * dans un index unique. Le garde-fou réel est donc au-dessus : la validation
 * refuse le doublon avant la base. C'est la même limite que
 * `system_email_templates` porte déjà — s'en écarter créerait deux règles pour
 * la même idée.
 */
class TASK1694EmailTemplatePairingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'org-aa-1', 'is_active' => true]);
        $this->orgB = Organization::factory()->create(['slug' => 'org-aa-2', 'is_active' => true]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        EmailTemplate::query()->delete();
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'slug' => 'relance',
            'name' => 'Relance',
            'locale' => 'fr',
            'organization_ids' => [Ecran::CIBLE_GLOBALE],
            'subject' => 'Sujet',
            'content_html' => '<p>corps</p>',
        ], $extra);
    }

    /** LE geste 7 : deux langues, MÊME slug. */
    public function test_two_language_versions_share_the_same_slug(): void
    {
        foreach (['fr', 'en'] as $locale) {
            $this->actingAs($this->superAdmin)
                ->post(route('admin.email-templates.store'), $this->payload(['locale' => $locale]))
                ->assertRedirect();
        }

        $this->assertSame(2, EmailTemplate::query()->where('slug', 'relance')->count());
        $this->assertSame(
            ['en', 'fr'],
            EmailTemplate::query()->where('slug', 'relance')->orderBy('locale')->pluck('locale')->all(),
        );
    }

    /** Et deux Organizations aussi — c'est la même contrainte qui l'interdisait. */
    public function test_two_organizations_share_the_same_slug(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.email-templates.store'), $this->payload([
                'organization_ids' => [$this->orgA->id, $this->orgB->id],
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(2, EmailTemplate::query()->where('slug', 'relance')->count());
    }

    /** LE geste 8 : plusieurs cibles d'un coup, Global compris. */
    public function test_one_submission_creates_one_row_per_target(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.email-templates.store'), $this->payload([
                'organization_ids' => [Ecran::CIBLE_GLOBALE, $this->orgA->id, $this->orgB->id],
            ]))
            ->assertRedirect();

        $this->assertSame(3, EmailTemplate::query()->where('slug', 'relance')->count());
        $this->assertSame(
            1,
            EmailTemplate::query()->where('slug', 'relance')->whereNull('organization_id')->count(),
        );
    }

    /**
     * Ce qui existe déjà n'est pas ÉCRASÉ, et c'est dit.
     *
     * Écraser en silence détruirait un texte que quelqu'un a écrit ; refuser
     * tout le lot pour une seule collision obligerait à décocher à l'aveugle.
     */
    public function test_an_existing_target_is_skipped_and_reported(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.email-templates.store'), $this->payload([
                'organization_ids' => [$this->orgA->id],
                'subject' => 'PREMIER_SUJET',
            ]));

        $this->actingAs($this->superAdmin)
            ->post(route('admin.email-templates.store'), $this->payload([
                'organization_ids' => [$this->orgA->id, $this->orgB->id],
                'subject' => 'SECOND_SUJET',
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(2, EmailTemplate::query()->where('slug', 'relance')->count());
        $this->assertSame(
            'PREMIER_SUJET',
            EmailTemplate::query()->where('organization_id', $this->orgA->id)->value('subject'),
            'le texte existant ne doit pas etre ecrase',
        );
        $this->assertSame(
            'SECOND_SUJET',
            EmailTemplate::query()->where('organization_id', $this->orgB->id)->value('subject'),
        );
    }

    /** Et si TOUT existe déjà, rien n'est créé et l'écran le dit. */
    public function test_when_everything_exists_nothing_is_created(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.email-templates.store'), $this->payload());

        $this->actingAs($this->superAdmin)
            ->post(route('admin.email-templates.store'), $this->payload())
            ->assertSessionHasErrors('organization_ids');

        $this->assertSame(1, EmailTemplate::query()->count());
    }

    /** Au moins une cible est exigée : un gabarit sans destinataire n'existe pas. */
    public function test_at_least_one_target_is_required(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.email-templates.store'), $this->payload(['organization_ids' => []]))
            ->assertSessionHasErrors('organization_ids');

        $this->assertSame(0, EmailTemplate::query()->count());
    }

    /**
     * Une cible forgée est refusée AVANT d'atteindre la base.
     *
     * Sur PostgreSQL, comparer une chaîne quelconque à une colonne `uuid` lève
     * `22P02` — un 500 là où il faut un message. Invisible sous SQLite, qui
     * compare comme du texte. Leçon TASK-1683, et c'est pourquoi la règle de
     * forme est posée à part.
     */
    public function test_a_forged_target_is_refused(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.email-templates.store'), $this->payload([
                'organization_ids' => ['pas-un-uuid'],
            ]))
            ->assertSessionHasErrors('organization_ids.0');

        $this->assertSame(0, EmailTemplate::query()->count());
    }

    /** Une Organization inconnue aussi, même bien formée. */
    public function test_an_unknown_organization_is_refused(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.email-templates.store'), $this->payload([
                'organization_ids' => ['01a16a52-ace4-73ac-8d8c-000000000000'],
            ]))
            ->assertSessionHasErrors('organization_ids.0');

        $this->assertSame(0, EmailTemplate::query()->count());
    }

    /**
     * Renommer un slug vers une collision est refusé PAR LE FORMULAIRE.
     *
     * Sans cette règle, la violation remonterait jusqu'à l'index unique : une
     * erreur de contrainte, c'est-à-dire un 500, là où il faut un message.
     */
    public function test_renaming_into_a_collision_is_refused_by_the_form(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.email-templates.store'), $this->payload([
                'slug' => 'premier', 'organization_ids' => [$this->orgA->id],
            ]));
        $this->actingAs($this->superAdmin)
            ->post(route('admin.email-templates.store'), $this->payload([
                'slug' => 'second', 'organization_ids' => [$this->orgA->id],
            ]));

        $second = EmailTemplate::query()->where('slug', 'second')->sole();

        $this->actingAs($this->superAdmin)
            ->put(route('admin.email-templates.update', $second), [
                'slug' => 'premier',
                'name' => $second->name,
                'locale' => 'fr',
                'subject' => 'Sujet',
                'content_html' => '<p>corps</p>',
            ])
            ->assertSessionHasErrors('slug');

        $this->assertSame('second', $second->fresh()->slug);
    }

    /**
     * Mais le MÊME slug dans une AUTRE langue reste permis.
     *
     * C'est l'appariement lui-même : sans cette moitié, la règle d'unicité
     * reviendrait à interdire ce que la demande 7 réclame.
     */
    public function test_renaming_into_another_language_of_the_same_slug_is_allowed(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.email-templates.store'), $this->payload([
                'slug' => 'relance', 'locale' => 'fr', 'organization_ids' => [$this->orgA->id],
            ]));
        $this->actingAs($this->superAdmin)
            ->post(route('admin.email-templates.store'), $this->payload([
                'slug' => 'autre', 'locale' => 'en', 'organization_ids' => [$this->orgA->id],
            ]));

        $autre = EmailTemplate::query()->where('slug', 'autre')->sole();

        $this->actingAs($this->superAdmin)
            ->put(route('admin.email-templates.update', $autre), [
                'slug' => 'relance',
                'name' => $autre->name,
                'locale' => 'en',
                'subject' => 'Sujet',
                'content_html' => '<p>corps</p>',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('relance', $autre->fresh()->slug);
        $this->assertSame(2, EmailTemplate::query()->where('slug', 'relance')->count());
    }

    /** L'écran porte les cases, Global compris. */
    public function test_the_form_offers_global_and_every_active_organization(): void
    {
        $inactive = Organization::factory()->create(['slug' => 'org-aa-off', 'is_active' => false]);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.email-templates.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-emailer-scope-option="global"', $html);
        $this->assertStringContainsString('data-emailer-scope-option="org-aa-1"', $html);
        $this->assertStringContainsString('data-emailer-scope-option="org-aa-2"', $html);
        $this->assertStringNotContainsString(
            'data-emailer-scope-option="org-aa-off"',
            $html,
            'une Organization desactivee n\'est pas une cible : le geste serait sans destinataire',
        );
    }
}
