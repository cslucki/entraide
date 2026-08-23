<?php

namespace Tests\Feature;

use App\Livewire\LoopManifestoCard;
use App\Models\BlogPost;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopRootDocumentService;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Card Manifeste : lien editeur org-scoped et CTA selon permission (TASK-1279).
 *
 * Bug manifeste 09-UT Dallas, deux symptomes releves en dogfooding :
 *
 * A — la Card construisait `route('blog.edit', ...)`, la route **courte**, qui
 *     resout l'Organization de la session : suivie depuis une Boucle d'une
 *     autre org, 404. La canonique `organization.blog.edit` existait deja.
 *     Et une fois le lien org-scope, l'editeur repondait encore 403 a l'owner :
 *     `BlogPostPolicy@update` ne connaissait que l'auteur et les co-auteurs,
 *     jamais la grille `manifesto.update` pourtant deja accordee a l'owner.
 *
 * B — le CTA d'edition s'affichait a tout lecteur (« Voir dans l'éditeur »),
 *     y compris un simple membre sans `manifesto.update`. Decision produit :
 *     une affordance d'edition ne s'affiche jamais sans la permission.
 */
class TASK1279ManifestoEditorAccessTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $autreOrg;

    private User $owner;

    private User $facilitator;

    private User $member;

    private Loop $loop;

    private BlogPost $manifesto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $this->autreOrg = Organization::factory()->create(['is_active' => true]);

        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);
        $this->facilitator = User::factory()->create(['organization_id' => $this->org->id]);
        $this->member = User::factory()->create(['organization_id' => $this->org->id]);

        app()->instance('current_organization', $this->org);

        $service = new LoopService;
        $this->loop = $service->createLoop($this->owner, 'Dallas', type: 'project');
        $service->addMember($this->loop, $this->facilitator, 'facilitator');
        $service->addMember($this->loop, $this->member, 'member');

        // createLoop() a deja cree le document racine, auteur = owner ;
        // ensureRootDocument() est idempotent et le retrouve. On re-attribue
        // l'auteur au facilitator, comme sur 09-UT Dallas : c'est ce qui prouve
        // la branche policy — l'owner n'est ni auteur, ni co-auteur, ni admin.
        $this->manifesto = app(LoopRootDocumentService::class)
            ->ensureRootDocument($this->loop, $this->facilitator);
        $this->manifesto->forceFill(['user_id' => $this->facilitator->id])->saveQuietly();
        $this->manifesto->refresh();
    }

    private function urlCanonique(): string
    {
        return route('organization.blog.edit', [
            'organization' => $this->org->slug,
            'post' => $this->manifesto->slug,
        ]);
    }

    // ── Symptome A : le lien de la Card ──────────────────────────────────────

    public function test_the_card_links_to_the_canonical_org_scoped_editor(): void
    {
        Livewire::actingAs($this->owner)
            ->test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->assertViewHas('editorUrl', $this->urlCanonique());
    }

    // ── Symptome A : la destination repond ───────────────────────────────────

    public function test_the_owner_opens_the_editor_without_404_nor_403(): void
    {
        // L'owner n'est pas l'auteur : sans la branche `manifesto.update`
        // de BlogPostPolicy, ce GET repondait 403 (mesure AVANT de la recette).
        $this->assertNotSame($this->owner->id, $this->manifesto->user_id);

        $this->actingAs($this->owner)->get($this->urlCanonique())->assertOk();
    }

    public function test_the_owner_saves_from_the_editor(): void
    {
        // « Modifiable » ne s'arrete pas au GET : l'ecriture passe aussi.
        $this->actingAs($this->owner)
            ->put(route('organization.blog.update', [
                'organization' => $this->org->slug,
                'post' => $this->manifesto->slug,
            ]), [
                'title' => $this->manifesto->title,
                // Le document racine nait `published` : summary et content
                // sont alors requis par la validation de l'editeur.
                'summary' => 'Cadre du dialogue de la Boucle Dallas.',
                'content' => '<p>Cadre revu par le proprietaire.</p>',
                'status' => $this->manifesto->status,
            ])
            ->assertRedirect();

        $this->assertStringContainsString(
            'Cadre revu par le proprietaire',
            $this->manifesto->fresh()->content
        );
    }

    // ── Symptome B : l'affordance suit la permission ─────────────────────────

    public function test_the_member_sees_the_manifesto_but_no_edit_affordance(): void
    {
        Livewire::actingAs($this->member)
            ->test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->assertViewHas('canRead', true)
            ->assertViewHas('canManage', false)
            ->assertSee($this->manifesto->title)
            // Le href de l'editeur ne se rend jamais sans `manifesto.update`.
            ->assertDontSeeHtml('/blog/rediger/');
    }

    public function test_the_owner_sees_the_edit_cta_labelled_modifier(): void
    {
        Livewire::actingAs($this->owner)
            ->test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->assertViewHas('canManage', true)
            ->assertSee(__('loops.manifesto_edit'))
            ->assertSeeHtml($this->urlCanonique());
    }

    public function test_the_editor_ai_endpoints_follow_the_same_policy(): void
    {
        // La page editeur interroge ses quotas IA au chargement
        // (`blog.ai-remaining`, garde par checkPostAccess) : un test « auteur
        // ou admin » recode en dur y repondait 403 a l'owner que la meme page
        // laisse pourtant rediger — console rouge sur un parcours nominal.
        $this->actingAs($this->owner)
            ->post(route('organization.blog.ai-remaining', [
                'organization' => $this->org->slug,
            ]), ['post_id' => $this->manifesto->id])
            ->assertOk();

        // Et le meme garde continue de refuser le simple membre.
        $this->actingAs($this->member)
            ->post(route('organization.blog.ai-remaining', [
                'organization' => $this->org->slug,
            ]), ['post_id' => $this->manifesto->id])
            ->assertForbidden();
    }

    // ── Permission : le refus reste entier pour un simple membre ─────────────

    public function test_the_member_is_refused_cleanly_on_a_forced_url(): void
    {
        // 403 lisible, pas un 500 ni une page servie : la branche policy
        // n'elargit rien — `manifesto.update` n'est pas accorde a `member`.
        $this->actingAs($this->member)->get($this->urlCanonique())->assertForbidden();
    }

    // ── Portee tenant, inchangee ─────────────────────────────────────────────

    public function test_another_organization_still_gets_a_404(): void
    {
        // Organization = Tenant : on ne confirme pas l'existence du Manifeste.
        $etranger = User::factory()->create(['organization_id' => $this->autreOrg->id]);
        app()->instance('current_organization', $this->autreOrg);

        $this->actingAs($etranger)
            ->get(route('organization.blog.edit', [
                'organization' => $this->autreOrg->slug,
                'post' => $this->manifesto->slug,
            ]))
            ->assertNotFound();
    }
}
