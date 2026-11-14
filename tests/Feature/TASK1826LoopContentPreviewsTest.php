<?php

namespace Tests\Feature;

use App\Livewire\LoopChat;
use App\Livewire\LoopRoadmapCard;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\LoopRoadmapItem;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1826 — apercus premium des contenus (Articles, Fichiers Dossiers,
 * Actions Roadmap) dans le fil de discussion d'une Boucle.
 *
 * Les trois familles reutilisent le SEUL mecanisme de cartes deja existant
 * (`LoopMessage.type` + `metadata`, rendu par `loop-chat.blade.php`,
 * precedent pose par les evenements et les sondages) : aucune de ces gardes
 * n'invente un second systeme d'annonce.
 */
class TASK1826LoopContentPreviewsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_public' => true, 'is_active' => true]);
        $this->owner = User::factory()->create([
            'organization_id' => $this->organization->id,
            'approved_at' => now(),
        ]);
        $this->loop = (new LoopService)->createLoop($this->owner, 'Entraide locale');

        app()->instance('current_organization', $this->organization);
    }

    private function rootDossier(): Dossier
    {
        return Dossier::firstOrCreate(
            ['loop_id' => $this->loop->id],
            ['organization_id' => $this->organization->id, 'owner_id' => null, 'name' => 'Racine']
        );
    }

    /** Aucune factory pour BlogPost dans ce depot : creation directe, meme idiome que BlogDossierCardTest. */
    private function article(array $surcharges = []): BlogPost
    {
        return BlogPost::create(array_merge([
            'organization_id' => $this->organization->id,
            'user_id' => $this->owner->id,
            'title' => 'Article de test',
            'content' => '<p>Contenu de test.</p>',
            'status' => 'draft',
        ], $surcharges));
    }

    // -----------------------------------------------------------------
    // ARTICLE
    // -----------------------------------------------------------------

    /** 1a. Partager un Article DEJA publie depuis le Blog -> une carte. */
    public function test_partager_un_article_deja_publie_depuis_le_blog_annonce_une_carte(): void
    {
        $post = $this->article([
            'organization_id' => $this->organization->id,
            'user_id' => $this->owner->id,
            'status' => 'published',
            'published_at' => now(),
            'listed_in_blog' => true,
        ]);

        $this->actingAs($this->owner)->postJson(
            route('organization.blog.loops.store', ['organization' => $this->organization->slug, 'post' => $post]),
            ['loop_id' => $this->loop->id],
        )->assertOk();

        $message = LoopMessage::where('loop_id', $this->loop->id)->where('type', 'blog_post')->first();
        $this->assertNotNull($message, 'le partage d\'un Article publie doit annoncer une carte');
        $this->assertSame($post->id, $message->metadata['blog_post_id']);
    }

    /** 1b. Attacher un Article DEJA publie au Dossier racine d'une Boucle (module Dossiers general) -> une carte. */
    public function test_attacher_un_article_publie_au_dossier_racine_annonce_une_carte(): void
    {
        $dossier = $this->rootDossier();
        $post = $this->article([
            'organization_id' => $this->organization->id,
            'user_id' => $this->owner->id,
            'status' => 'published',
            'published_at' => now(),
            'listed_in_blog' => true,
        ]);

        $this->actingAs($this->owner)->postJson(
            route('organization.dossiers.articles.store', ['organization' => $this->organization->slug, 'dossier' => $dossier->id]),
            ['blog_post_id' => $post->id],
        )->assertCreated();

        $message = LoopMessage::where('loop_id', $this->loop->id)->where('type', 'blog_post')->first();
        $this->assertNotNull($message, 'un Article publie attache au Dossier racine doit annoncer une carte, meme sans passer par blog_post_loop');
        $this->assertSame($post->id, $message->metadata['blog_post_id']);
    }

    /** 1c. Creer un brouillon depuis la Card Articles/Dossiers d'une Boucle, PUIS le publier -> une carte, a la publication. */
    public function test_publier_un_brouillon_deja_lie_a_la_boucle_annonce_une_carte(): void
    {
        $dossier = $this->rootDossier();
        $post = $this->article([
            'organization_id' => $this->organization->id,
            'user_id' => $this->owner->id,
            'status' => 'draft',
            'published_at' => null,
        ]);
        DossierBlogPost::create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $this->owner->id,
            'position' => 1,
        ]);
        $post->loops()->attach($this->loop->id);

        $this->assertSame(0, LoopMessage::where('loop_id', $this->loop->id)->where('type', 'blog_post')->count(),
            'un brouillon ne doit jamais annoncer de carte');

        $post->update(['status' => 'published', 'published_at' => now(), 'listed_in_blog' => true]);

        $message = LoopMessage::where('loop_id', $this->loop->id)->where('type', 'blog_post')->first();
        $this->assertNotNull($message, 'la publication d\'un Article deja lie a la Boucle doit annoncer une carte');
    }

    /** Un brouillon ne cree jamais d'annonce, meme explicitement attache. */
    public function test_un_brouillon_n_annonce_jamais_de_carte(): void
    {
        $dossier = $this->rootDossier();
        $post = $this->article([
            'organization_id' => $this->organization->id,
            'user_id' => $this->owner->id,
            'status' => 'draft',
        ]);

        $this->actingAs($this->owner)->postJson(
            route('organization.dossiers.articles.store', ['organization' => $this->organization->slug, 'dossier' => $dossier->id]),
            ['blog_post_id' => $post->id],
        )->assertCreated();

        $this->assertSame(0, LoopMessage::where('type', 'blog_post')->count());
    }

    /** Idempotence : publier un Article deja annonce (ex. partage puis republie) ne double jamais la carte. */
    public function test_republier_un_article_deja_annonce_ne_double_pas_la_carte(): void
    {
        $post = $this->article([
            'organization_id' => $this->organization->id,
            'user_id' => $this->owner->id,
            'status' => 'published',
            'published_at' => now(),
            'listed_in_blog' => true,
        ]);
        $post->loops()->attach($this->loop->id);

        app(\App\Services\LoopMessageService::class)->sendArticlePublishedMessage($this->loop, $this->owner, $post);
        $this->assertSame(1, LoopMessage::where('type', 'blog_post')->count());

        // Une republication (statut change deux fois) ne doit pas en ajouter une seconde.
        $post->update(['status' => 'draft']);
        $post->update(['status' => 'published', 'published_at' => now(), 'listed_in_blog' => true]);

        $this->assertSame(1, LoopMessage::where('loop_id', $this->loop->id)->where('type', 'blog_post')->count());
    }

    /** La carte Article affiche titre/resume/auteur et un lien reel vers l'article publie. */
    public function test_la_carte_article_affiche_le_contenu_et_le_lien_reel(): void
    {
        $post = $this->article([
            'organization_id' => $this->organization->id,
            'user_id' => $this->owner->id,
            'title' => 'Comment organiser un covoiturage',
            'summary' => 'Un petit guide pratique.',
            'status' => 'published',
            'published_at' => now(),
            'listed_in_blog' => true,
        ]);
        app(\App\Services\LoopMessageService::class)->sendArticlePublishedMessage($this->loop, $this->owner, $post);

        $url = route('organization.blog.show', ['organization' => $this->organization->slug, 'post' => $post]);

        $this->actingAs($this->owner)
            ->get(route('organization.loops.show', [$this->organization->slug, $this->loop]))
            ->assertOk()
            ->assertSee('Comment organiser un covoiturage')
            ->assertSee('Un petit guide pratique')
            ->assertSee($this->owner->publicDisplayName())
            ->assertSee($url, false);
    }

    /** Un Article supprime depuis l'annonce degrade sans fuiter son titre. */
    public function test_un_article_supprime_degrade_sans_fuiter_son_titre(): void
    {
        $post = $this->article([
            'organization_id' => $this->organization->id,
            'user_id' => $this->owner->id,
            'title' => 'Titre tres confidentiel',
            'status' => 'published',
            'published_at' => now(),
            'listed_in_blog' => true,
        ]);
        app(\App\Services\LoopMessageService::class)->sendArticlePublishedMessage($this->loop, $this->owner, $post);

        $post->delete();

        $this->actingAs($this->owner)
            ->get(route('organization.loops.show', [$this->organization->slug, $this->loop]))
            ->assertOk()
            ->assertSee(__('loops.chat_article_unavailable'))
            ->assertDontSee('Titre tres confidentiel');
    }

    /** Un Article redevenu brouillon (depublie) degrade aussi sans fuiter son titre. */
    public function test_un_article_depublie_degrade_sans_fuiter_son_titre(): void
    {
        $post = $this->article([
            'organization_id' => $this->organization->id,
            'user_id' => $this->owner->id,
            'title' => 'Encore confidentiel',
            'status' => 'published',
            'published_at' => now(),
            'listed_in_blog' => true,
        ]);
        app(\App\Services\LoopMessageService::class)->sendArticlePublishedMessage($this->loop, $this->owner, $post);

        $post->status = 'draft';
        $post->saveQuietly();

        $this->actingAs($this->owner)
            ->get(route('organization.loops.show', [$this->organization->slug, $this->loop]))
            ->assertOk()
            ->assertSee(__('loops.chat_article_unavailable'))
            ->assertDontSee('Encore confidentiel');
    }

    // -----------------------------------------------------------------
    // ROADMAP
    // -----------------------------------------------------------------

    /** Creer une action de Roadmap annonce UNE carte. */
    public function test_creer_une_action_de_roadmap_annonce_une_carte(): void
    {
        Livewire::actingAs($this->owner)
            ->test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->set('newTitle', 'Reserver la salle')
            ->call('createAction');

        $item = LoopRoadmapItem::where('loop_id', $this->loop->id)->first();
        $this->assertNotNull($item);

        $message = LoopMessage::where('loop_id', $this->loop->id)->where('type', 'roadmap_action')->first();
        $this->assertNotNull($message, 'la creation d\'une action doit annoncer une carte');
        $this->assertSame($item->id, $message->metadata['roadmap_item_id']);
    }

    /** Editer le titre ou changer le statut n'annonce JAMAIS une seconde carte. */
    public function test_editer_ou_changer_le_statut_n_annonce_jamais_une_seconde_carte(): void
    {
        $component = Livewire::actingAs($this->owner)
            ->test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->set('newTitle', 'Preparer le programme')
            ->call('createAction');

        $item = LoopRoadmapItem::where('loop_id', $this->loop->id)->first();
        $this->assertSame(1, LoopMessage::where('type', 'roadmap_action')->count());

        $component->call('setStatus', $item->id, LoopRoadmapItem::STATUS_DONE);
        $component->call('openDetail', $item->id)
            ->set('detailTitle', 'Programme finalise')
            ->call('saveDetailTitle');

        $this->assertSame(1, LoopMessage::where('loop_id', $this->loop->id)->where('type', 'roadmap_action')->count(),
            'edition et changement de statut ne doivent jamais produire de seconde carte');
    }

    /** Le statut affiche dans la carte est TOUJOURS celui, actuel, de l'action — jamais fige. */
    public function test_le_statut_affiche_est_toujours_celui_actuel_de_l_action(): void
    {
        Livewire::actingAs($this->owner)
            ->test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->set('newTitle', 'Contacter le prestataire')
            ->call('createAction');

        $item = LoopRoadmapItem::where('loop_id', $this->loop->id)->first();

        $this->actingAs($this->owner)
            ->get(route('organization.loops.show', [$this->organization->slug, $this->loop]))
            ->assertOk()
            ->assertSee(__('loops.roadmap_status_todo'));

        $item->update(['status' => LoopRoadmapItem::STATUS_DONE, 'completed_at' => now()]);

        $this->actingAs($this->owner)
            ->get(route('organization.loops.show', [$this->organization->slug, $this->loop]))
            ->assertOk()
            ->assertSee(__('loops.roadmap_status_done'))
            ->assertDontSee(__('loops.roadmap_status_todo').'</span>', false);
    }

    /** Une action archivee (soft delete) degrade sans fuiter son titre. */
    public function test_une_action_archivee_degrade_sans_fuiter_son_titre(): void
    {
        Livewire::actingAs($this->owner)
            ->test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->set('newTitle', 'Action tres confidentielle')
            ->call('createAction');

        $item = LoopRoadmapItem::where('loop_id', $this->loop->id)->first();
        $item->delete();

        $this->actingAs($this->owner)
            ->get(route('organization.loops.show', [$this->organization->slug, $this->loop]))
            ->assertOk()
            ->assertSee(__('loops.chat_roadmap_unavailable'))
            ->assertDontSee('Action tres confidentielle');
    }

    /** Ouvrir le detail depuis ChatLoop relaie bien vers la Card Roadmap. */
    public function test_ouvrir_le_detail_depuis_le_chat_relaie_vers_la_card_roadmap(): void
    {
        Livewire::actingAs($this->owner)
            ->test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->set('newTitle', 'Action ciblee')
            ->call('createAction');

        $item = LoopRoadmapItem::where('loop_id', $this->loop->id)->first();

        Livewire::actingAs($this->owner)
            ->test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('openDetailFromChat', $item->id)
            ->assertSet('detailId', $item->id);
    }

    // -----------------------------------------------------------------
    // ISOLATION TENANT / PERMISSIONS
    // -----------------------------------------------------------------

    /** Un Article d'une AUTRE Organization ne resout jamais, meme avec l'identifiant exact. */
    public function test_un_article_d_une_autre_organization_ne_resout_jamais(): void
    {
        $autreOrg = Organization::factory()->create(['is_public' => true]);
        $autreAuteur = User::factory()->create(['organization_id' => $autreOrg->id]);
        $postEtranger = $this->article([
            'organization_id' => $autreOrg->id,
            'user_id' => $autreAuteur->id,
            'title' => 'Article d\'une autre Organization',
            'status' => 'published',
            'published_at' => now(),
            'listed_in_blog' => true,
        ]);

        // Message fabrique directement (l'annonce reelle ne pourrait jamais
        // pointer hors tenant) : on verifie que la LECTURE, elle, refuse.
        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->owner->id,
            'body' => 'annonce',
            'type' => 'blog_post',
            'metadata' => ['event' => 'published', 'blog_post_id' => $postEtranger->id],
            'organization_id' => $this->loop->organization_id,
        ]);

        $this->actingAs($this->owner)
            ->get(route('organization.loops.show', [$this->organization->slug, $this->loop]))
            ->assertOk()
            ->assertDontSee('Article d\'une autre Organization')
            ->assertSee(__('loops.chat_article_unavailable'));
    }
}
