<?php

namespace Tests\Feature;

use App\Models\CrmContact;
use App\Models\CrmContactEvent;
use App\Models\CrmStatus;
use App\Models\Organization;
use App\Models\User;
use App\Services\Crm\CrmContactService;
use App\Services\Crm\CrmStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * TASK-1414 — CRM-2, pipeline de statuts dynamique + timeline `status_changed`.
 *
 * - le pipeline est seme UNE fois par Organization, dans SA locale, banal ;
 * - un statut d'une autre Organization est refuse partout (fail closed) ;
 * - chaque changement ecrit un evenement append-only (ancien → nouveau,
 *   auteur, horodatage) ; renommer un statut ne reecrit pas l'histoire ;
 * - un Contact nait avec le statut par defaut de son Organization.
 */
class TASK1414CrmStatusPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private CrmStatusService $statuses;

    private CrmContactService $contacts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'org-a-1414', 'is_active' => true, 'locale' => 'fr']);
        $this->orgB = Organization::factory()->create(['slug' => 'org-b-1414', 'is_active' => true, 'locale' => 'en']);
        $this->statuses = app(CrmStatusService::class);
        $this->contacts = app(CrmContactService::class);
    }

    // ── 1. Le pipeline initial ──────────────────────────────────────────────

    public function test_the_default_pipeline_is_seeded_once_in_the_organization_locale(): void
    {
        $pipeline = $this->statuses->ensureDefaultPipeline($this->orgA);

        $this->assertSame(
            ['Nouveau', 'À contacter', 'Contact en cours', 'Devis envoyé', 'Client', 'Perdu'],
            $pipeline->pluck('label')->all()
        );
        $this->assertSame([true, false, false, false, false, false], $pipeline->pluck('is_default')->all());
        $this->assertTrue($pipeline->every(fn (CrmStatus $s) => $s->is_active), '« Perdu » est ACTIF : le pipeline a une vraie sortie negative');

        $again = $this->statuses->ensureDefaultPipeline($this->orgA);
        $this->assertCount(6, $again, 'idempotent : jamais re-seme');
        $this->assertSame(6, CrmStatus::forOrganization($this->orgA)->count());
    }

    public function test_an_english_organization_gets_english_labels_and_pipelines_are_independent(): void
    {
        $fr = $this->statuses->ensureDefaultPipeline($this->orgA);
        $en = $this->statuses->ensureDefaultPipeline($this->orgB);

        $this->assertSame('New', $en->first()->label);
        $this->assertSame('Lost', $en->last()->label);
        $this->assertSame('Nouveau', $fr->first()->label);
        $this->assertSame(6, CrmStatus::forOrganization($this->orgA)->count());
        $this->assertSame(6, CrmStatus::forOrganization($this->orgB)->count());
    }

    public function test_an_organization_that_already_has_a_status_keeps_its_own_pipeline(): void
    {
        $own = CrmStatus::factory()->create(['organization_id' => $this->orgA->id, 'label' => 'Mon statut', 'is_default' => true]);

        $pipeline = $this->statuses->ensureDefaultPipeline($this->orgA);

        $this->assertCount(1, $pipeline);
        $this->assertSame($own->id, $this->statuses->defaultStatus($this->orgA)->id);
    }

    // ── 2. Un Contact nait avec le statut par defaut ────────────────────────

    public function test_a_new_contact_receives_the_default_status_and_a_found_contact_keeps_its_own(): void
    {
        $contact = $this->contacts->findOrCreate($this->orgA, ['email' => 'nouveau@example.com']);
        $this->assertSame('Nouveau', $contact->status?->label);

        $client = CrmStatus::forOrganization($this->orgA)->where('label', 'Client')->firstOrFail();
        $this->statuses->changeStatus($contact, $client);

        $found = $this->contacts->findOrCreate($this->orgA, ['email' => 'nouveau@example.com']);
        $this->assertSame($client->id, $found->status_id, 'retrouver un Contact ne le remet pas a « Nouveau »');
    }

    // ── 3. Changer de statut ecrit la timeline ──────────────────────────────

    public function test_changing_the_status_writes_an_append_only_event_with_old_new_author_and_time(): void
    {
        $admin = User::factory()->create(['organization_id' => $this->orgA->id]);
        $contact = $this->contacts->findOrCreate($this->orgA, ['email' => 'suivi@example.com']);
        $devis = CrmStatus::forOrganization($this->orgA)->where('label', 'Devis envoyé')->firstOrFail();

        $before = now()->subSecond();
        $event = $this->statuses->changeStatus($contact, $devis, $admin);

        $this->assertNotNull($event);
        $this->assertSame($devis->id, $contact->fresh()->status_id);
        $this->assertSame(CrmContactEvent::TYPE_STATUS_CHANGED, $event->type);
        $this->assertSame($admin->id, $event->author_user_id);
        $this->assertSame($this->orgA->id, $event->organization_id);
        $this->assertSame('Nouveau', $event->payload['from_label']);
        $this->assertSame('Devis envoyé', $event->payload['to_label']);
        $this->assertTrue($event->occurred_at->between($before, now()->addSecond()));

        // Append-only : ni mise a jour ni suppression d'un fait.
        try {
            $event->update(['payload' => ['to_label' => 'Client']]);
            $this->fail('un evenement ne se modifie pas');
        } catch (LogicException) {
        }
        try {
            $event->delete();
            $this->fail('un evenement ne se supprime pas');
        } catch (LogicException) {
        }
        $this->assertSame('Devis envoyé', $event->fresh()->payload['to_label']);
    }

    public function test_changing_to_the_same_status_writes_nothing(): void
    {
        $contact = $this->contacts->findOrCreate($this->orgA, ['email' => 'idem@example.com']);
        $nouveau = $this->statuses->defaultStatus($this->orgA);

        $this->assertNull($this->statuses->changeStatus($contact, $nouveau));
        $this->assertSame(0, CrmContactEvent::forOrganization($this->orgA)->count());
    }

    public function test_renaming_a_status_does_not_rewrite_history(): void
    {
        $contact = $this->contacts->findOrCreate($this->orgA, ['email' => 'histoire@example.com']);
        $client = CrmStatus::forOrganization($this->orgA)->where('label', 'Client')->firstOrFail();
        $event = $this->statuses->changeStatus($contact, $client);

        $this->statuses->rename($client, 'Client signé');

        $this->assertSame('Client signé', $client->fresh()->label);
        $this->assertSame('Client', $event->fresh()->payload['to_label']);
    }

    // ── 4. Tenant : un statut d'ailleurs est refuse partout ─────────────────

    public function test_a_status_of_another_organization_is_refused_and_nothing_is_written(): void
    {
        $contact = $this->contacts->findOrCreate($this->orgA, ['email' => 'tenant@example.com']);
        $foreign = $this->statuses->ensureDefaultPipeline($this->orgB)->last();
        $initial = $contact->status_id;

        try {
            $this->statuses->changeStatus($contact, $foreign);
            $this->fail('un statut d une autre Organization ne s applique pas');
        } catch (LogicException) {
        }

        $this->assertSame($initial, $contact->fresh()->status_id);
        $this->assertSame(0, CrmContactEvent::withoutGlobalScopes()->count());
    }

    public function test_reordering_refuses_a_foreign_id_and_writes_nothing(): void
    {
        $mine = $this->statuses->ensureDefaultPipeline($this->orgA);
        $foreign = $this->statuses->ensureDefaultPipeline($this->orgB)->first();
        $orderBefore = $mine->pluck('id')->all();

        try {
            $this->statuses->reorder($this->orgA, array_merge(array_reverse($orderBefore), [$foreign->id]));
            $this->fail('un id etranger fait echouer tout le reordonnancement');
        } catch (LogicException) {
        }

        $this->assertSame($orderBefore, CrmStatus::forOrganization($this->orgA)->ordered()->pluck('id')->all());
    }

    // ── 5. Gestion du pipeline ──────────────────────────────────────────────

    public function test_reorder_rename_create_and_unique_labels(): void
    {
        $pipeline = $this->statuses->ensureDefaultPipeline($this->orgA);
        $reversed = array_reverse($pipeline->pluck('id')->all());

        $reordered = $this->statuses->reorder($this->orgA, $reversed);
        $this->assertSame($reversed, $reordered->pluck('id')->all());

        $created = $this->statuses->create($this->orgA, '  Relance  ');
        $this->assertSame('Relance', $created->label);
        $this->assertSame(7, $created->sort_order);

        $this->expectException(LogicException::class);
        $this->statuses->create($this->orgA, 'Client');
    }

    public function test_the_default_status_cannot_be_deactivated_and_an_inactive_status_cannot_be_applied(): void
    {
        $pipeline = $this->statuses->ensureDefaultPipeline($this->orgA);
        $nouveau = $pipeline->first();
        $perdu = $pipeline->last();
        $contact = $this->contacts->findOrCreate($this->orgA, ['email' => 'inactif@example.com']);
        $this->statuses->changeStatus($contact, $perdu);

        try {
            $this->statuses->deactivate($nouveau);
            $this->fail('le statut par defaut ne se desactive pas');
        } catch (LogicException) {
        }

        $this->statuses->deactivate($perdu);
        $this->assertFalse($perdu->fresh()->is_active);
        $this->assertSame($perdu->id, $contact->fresh()->status_id, 'desactiver ne retire rien aux Contacts qui le portent');

        $other = $this->contacts->findOrCreate($this->orgA, ['email' => 'autre@example.com']);
        $this->expectException(LogicException::class);
        $this->statuses->changeStatus($other, $perdu->fresh());
    }

    public function test_set_default_keeps_exactly_one_default_per_organization(): void
    {
        $pipeline = $this->statuses->ensureDefaultPipeline($this->orgA);
        $this->statuses->ensureDefaultPipeline($this->orgB);

        $this->statuses->setDefault($pipeline->get(1));

        $this->assertSame(1, CrmStatus::forOrganization($this->orgA)->where('is_default', true)->count());
        $this->assertSame($pipeline->get(1)->id, $this->statuses->defaultStatus($this->orgA)->id);
        $this->assertSame(1, CrmStatus::forOrganization($this->orgB)->where('is_default', true)->count(), 'l autre Organization n a pas bouge');
        $this->assertSame('New', $this->statuses->defaultStatus($this->orgB)->label);
    }

    // ── Temoin d'instrument ─────────────────────────────────────────────────

    public function test_the_probe_sees_an_event_when_one_is_written(): void
    {
        $contact = $this->contacts->findOrCreate($this->orgA, ['email' => 'temoin@example.com']);
        $client = CrmStatus::forOrganization($this->orgA)->where('label', 'Client')->firstOrFail();

        $this->assertSame(0, CrmContactEvent::forOrganization($this->orgA)->count());
        $this->statuses->changeStatus($contact, $client);
        $this->assertSame(1, CrmContactEvent::forOrganization($this->orgA)->count());
        $this->assertSame(1, $contact->events()->count());
    }
}
