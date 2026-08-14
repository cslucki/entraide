<?php

namespace Tests\Feature;

use App\Livewire\LoopArticleCard;
use App\Models\BlogPost;
use App\Models\BlogPostInvitation;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopWritingService;
use App\Services\LoopService;
use App\Support\Loops\LoopCardRegistry;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La Card Article, et le preset Redaction.
 *
 * **Le point central est ce qui n'est PAS construit.** Les Articles sont des
 * `BlogPost` ranges dans le Dossier de la Boucle ; l'editeur TipTap, les
 * audiences, les snapshots, les co-auteurs et les Series existent depuis
 * longtemps. La Card les **lit sous un autre angle** et renvoie aux parcours
 * existants.
 *
 * Elle est distincte de Dossiers, que la matrice nomme separement : le Dossier
 * est le classeur, l'atelier repond a « qu'est-ce que j'ecris, et qu'est-ce qui
 * attend ».
 */
class TASK1111LoopArticleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $auteur;

    private User $autre;

    private Loop $loop;

    private Dossier $dossier;

    private LoopService $loops;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        $this->auteur = User::factory()->create(['organization_id' => $this->org->id]);
        $this->autre = User::factory()->create(['organization_id' => $this->org->id]);

        app()->instance('current_organization', $this->org);

        $this->loops = new LoopService;
        $this->loop = $this->loops->createLoop($this->auteur, 'Ma redaction')->fresh();
        $this->loop->forceFill(['type' => 'writing'])->save();
        $this->loops->addMember($this->loop, $this->autre, 'member');

        LoopCard::firstOrCreate(
            ['loop_id' => $this->loop->id, 'card_key' => 'core.article'],
            ['organization_id' => $this->org->id, 'enabled' => true],
        );

        $this->dossier = Dossier::firstOrCreate(
            ['loop_id' => $this->loop->id],
            [
                'organization_id' => $this->org->id,
                'owner_id' => $this->auteur->id,
                'name' => 'Dossier de la Boucle',
                'visibility' => 'loop',
            ],
        );
    }

    private function service(): LoopWritingService
    {
        return app(LoopWritingService::class);
    }

    private function card(?User $as = null)
    {
        return Livewire::actingAs($as ?? $this->auteur)->test(LoopArticleCard::class, ['loop' => $this->loop->fresh()]);
    }

    private function article(string $titre, string $statut = 'draft', ?User $de = null, bool $range = true): BlogPost
    {
        $post = BlogPost::create([
            'organization_id' => $this->org->id,
            'user_id' => ($de ?? $this->auteur)->id,
            'title' => $titre,
            'slug' => \Illuminate\Support\Str::slug($titre).'-'.\Illuminate\Support\Str::random(6),
            'content' => 'Contenu de test.',
            'status' => $statut,
            'audience' => 'loop',
            'published_at' => $statut === 'published' ? now() : null,
        ]);

        if ($range) {
            // `DossierBlogPost` et non `attach()` : le pivot porte un `id` UUID
            // que la table pivot ne genere pas toute seule.
            \App\Models\DossierBlogPost::create([
                'organization_id' => $this->org->id,
                'dossier_id' => $this->dossier->id,
                'blog_post_id' => $post->id,
                'added_by' => ($de ?? $this->auteur)->id,
                'position' => 0,
            ]);
        }

        return $post->fresh();
    }

    // ── Aucun second systeme ────────────────────────────────────────────────

    public function test_no_second_article_system_is_created(): void
    {
        // C'est la faute que cette Card pouvait commettre.
        foreach (['loop_articles', 'loop_posts', 'writing_articles', 'loop_drafts'] as $interdite) {
            $this->assertFalse(Schema::hasTable($interdite), $interdite);
        }
    }

    public function test_no_second_co_authoring_system_is_created(): void
    {
        foreach (['loop_article_authors', 'writing_invitations'] as $interdite) {
            $this->assertFalse(Schema::hasTable($interdite), $interdite);
        }

        // Les co-auteurs restent les `blog_post_invitations` existantes.
        $this->assertTrue(Schema::hasTable('blog_post_invitations'));
    }

    public function test_the_card_carries_no_editor_and_no_form(): void
    {
        // L'editeur a ses audiences, ses snapshots et ses policies. Le
        // dupliquer les ferait diverger.
        $composant = file_get_contents(app_path('Livewire/LoopArticleCard.php'));
        $vue = file_get_contents(resource_path('views/livewire/loop-article-card.blade.php'));

        $this->assertStringNotContainsString('BlogPost::create', $composant);
        $this->assertStringNotContainsString('->update(', $composant);
        $this->assertStringNotContainsString('<form', $vue);
        $this->assertStringNotContainsString('wire:model', $vue);
    }

    public function test_the_service_only_reads(): void
    {
        $source = file_get_contents(app_path('Services/Loops/LoopWritingService.php'));

        foreach (['::create(', '->save(', '->delete(', '->update('] as $ecriture) {
            $this->assertStringNotContainsString($ecriture, $source, $ecriture);
        }
    }

    // ── Le preset Redaction ─────────────────────────────────────────────────

    public function test_the_writing_preset_holds_its_three_distinctive_cards(): void
    {
        // La matrice : « Redaction | Manifeste · Membres | Article · Roadmap ·
        // Dossiers ».
        $cles = app(LoopTypeRegistry::class)->cardsFor('writing');

        foreach (['core.article', 'core.roadmap', 'core.dossiers', 'core.manifesto', 'core.members'] as $attendue) {
            $this->assertContains($attendue, $cles, $attendue);
        }
    }

    public function test_the_writing_preset_is_not_offered_yet(): void
    {
        $registre = app(LoopTypeRegistry::class);

        $this->assertFalse($registre->isAvailable('writing'));
        $this->assertNotContains('writing', $registre->availableKeys());
    }

    public function test_the_grid_is_exactly_at_its_cap(): void
    {
        $catalogue = config('loop_cards.cards');

        $enGrille = collect(app(LoopTypeRegistry::class)->cardsFor('writing'))
            ->filter(fn (string $k) => ($catalogue[$k]['placement'] ?? '') === 'grid');

        $this->assertSame(config('loop_cards.grid_slots'), $enGrille->count());
    }

    public function test_the_card_order_is_unique(): void
    {
        $ordres = collect(config('loop_cards.cards'))->pluck('order');

        $this->assertSame($ordres->count(), $ordres->unique()->count());
    }

    public function test_the_card_exists_and_depends_on_nothing(): void
    {
        $registre = app(LoopCardRegistry::class);

        $this->assertTrue($registre->exists('core.article'));
        $this->assertSame([], $registre->get('core.article')['requires']);
    }

    // ── Reprendre un brouillon ──────────────────────────────────────────────

    public function test_my_drafts_are_mine_and_only_mine(): void
    {
        $this->article('Mon brouillon');
        $this->article('Le sien', 'draft', $this->autre);

        $miens = $this->service()->myDrafts($this->loop, $this->auteur)->pluck('title');

        $this->assertContains('Mon brouillon', $miens);
        $this->assertNotContains('Le sien', $miens);
    }

    public function test_a_published_article_is_not_a_draft(): void
    {
        $this->article('Publie', 'published');

        $this->assertCount(0, $this->service()->myDrafts($this->loop, $this->auteur));
    }

    public function test_an_article_outside_the_dossier_is_never_listed(): void
    {
        // Le rattachement au Dossier **est** l'appartenance a la Boucle.
        $this->article('Ailleurs', 'draft', $this->auteur, range: false);

        $this->assertCount(0, $this->service()->myDrafts($this->loop, $this->auteur));
    }

    public function test_the_screen_offers_a_way_to_resume(): void
    {
        $brouillon = $this->article('A reprendre');

        $this->card()
            ->assertSee(__('loops.cards.article.resume'))
            ->assertSeeHtml(route('organization.blog.edit', ['organization' => $this->org->slug, 'post' => $brouillon->slug]));
    }

    public function test_the_resume_link_never_leaves_the_loops_organization(): void
    {
        // Les routes nues retombent sur l'Organization par defaut : c'est le
        // bloquant trouve sur la Card Demande-Offre, ou « Creer une Offre »
        // enregistrait dans une autre Organization.
        $brouillon = $this->article('A reprendre');

        $html = $this->card()->html();

        $this->assertStringNotContainsString('"'.route('blog.edit', ['post' => $brouillon->slug]).'"', $html);
    }

    // ── Ce qui est en cours ailleurs ────────────────────────────────────────

    public function test_others_drafts_are_announced_but_never_opened(): void
    {
        // On dit qu'un texte existe, pas ce qu'il raconte.
        $sien = $this->article('Le brouillon de l’autre', 'draft', $this->autre);
        $sien->update(['content' => 'SECRET-A-NE-PAS-MONTRER']);

        $html = $this->card()->html();

        $this->assertStringContainsString('Le brouillon de l’autre', $html);
        $this->assertStringNotContainsString('SECRET-A-NE-PAS-MONTRER', $html);
        $this->assertStringNotContainsString(
            route('organization.blog.edit', ['organization' => $this->org->slug, 'post' => $sien->slug]),
            $html,
        );
    }

    public function test_a_deactivated_author_is_not_named(): void
    {
        $this->article('Un brouillon', 'draft', $this->autre);
        $this->autre->forceFill(['banned_at' => now()])->save();

        $this->card()->assertDontSee($this->autre->fullName);
    }

    // ── Publies ─────────────────────────────────────────────────────────────

    public function test_published_articles_are_listed_with_their_visibility(): void
    {
        $this->article('Un texte publie', 'published');

        $this->card()
            ->assertSee('Un texte publie')
            ->assertSee(__('loops.cards.article.audience_label', ['audience' => 'loop']));
    }

    public function test_the_read_link_stays_inside_the_organization(): void
    {
        $publie = $this->article('Publie', 'published');

        $this->card()->assertSeeHtml(
            route('organization.blog.show', ['organization' => $this->org->slug, 'post' => $publie->slug]),
        );
    }

    // ── Les co-auteurs ──────────────────────────────────────────────────────

    public function test_pending_co_author_invitations_are_shown(): void
    {
        $article = $this->article('A quatre mains');

        BlogPostInvitation::create([
            'organization_id' => $this->org->id,
            'blog_post_id' => $article->id,
            'sender_id' => $this->auteur->id,
            'recipient_email' => 'invite@example.test',
            'recipient_name' => 'Personne invitee',
            'token' => \Illuminate\Support\Str::random(40),
            // Le vocabulaire reel est `existing_member|external` : un CHECK le tient.
            'invitation_type' => 'external',
            'status' => 'pending',
        ]);

        $this->card()
            ->assertSee(__('loops.cards.article.pending_co_authors'))
            ->assertSee('Personne invitee');
    }

    public function test_an_accepted_invitation_no_longer_waits(): void
    {
        $article = $this->article('Deja repondu');

        BlogPostInvitation::create([
            'organization_id' => $this->org->id,
            'blog_post_id' => $article->id,
            'sender_id' => $this->auteur->id,
            'recipient_email' => 'deja@example.test',
            'token' => \Illuminate\Support\Str::random(40),
            // Le vocabulaire reel est `existing_member|external` : un CHECK le tient.
            'invitation_type' => 'external',
            'status' => 'accepted',
        ]);

        $this->assertCount(0, $this->service()->pendingCoAuthors($this->loop));
    }

    // ── Droits et cloisonnement ─────────────────────────────────────────────

    public function test_composing_carries_no_read_flag(): void
    {
        $permissions = config('loop_permissions.permissions');

        $this->assertTrue($permissions['writing.view']['read']);
        $this->assertArrayNotHasKey('read', $permissions['writing.compose']);
    }

    public function test_an_archived_loop_offers_no_way_to_write(): void
    {
        // Boucle archivee = lecture historique.
        $this->article('Un brouillon');
        $this->loop->forceFill(['status' => 'archived', 'archived_at' => now()])->save();

        $this->card()
            ->assertSee('Un brouillon')
            ->assertDontSee(__('loops.cards.article.write'));
    }

    public function test_someone_without_access_sees_nothing(): void
    {
        $etranger = User::factory()->create(['organization_id' => $this->org->id]);
        $this->article('Un brouillon');

        Livewire::actingAs($etranger)
            ->test(LoopArticleCard::class, ['loop' => $this->loop])
            ->assertSee(__('loops.cards.article.no_access'))
            ->assertDontSee('Un brouillon');
    }

    public function test_an_article_of_another_organization_is_never_listed(): void
    {
        // **La premiere version ne rangeait pas l'Article dans le Dossier** :
        // elle etait un doublon verbatim du test precedent, et ne gardait rien.
        // `BlogPost` ne porte aucun scope global d'Organization : si la ligne
        // pivot existe, seule une condition explicite l'ecarte.
        $autreOrg = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        $ailleurs = User::factory()->create(['organization_id' => $autreOrg->id]);

        $leur = BlogPost::create([
            'organization_id' => $autreOrg->id,
            'user_id' => $ailleurs->id,
            'title' => 'Le leur',
            'slug' => 'le-leur-'.\Illuminate\Support\Str::random(6),
            'content' => 'x',
            'status' => 'published',
            'audience' => 'loop',
            'listed_in_blog' => true,
            'published_at' => now(),
        ]);

        // Range **dans notre Dossier**, comme une reprise de donnees fautive
        // pourrait le faire.
        \App\Models\DossierBlogPost::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $this->dossier->id,
            'blog_post_id' => $leur->id,
            'added_by' => $this->auteur->id,
            'position' => 0,
        ]);

        $this->assertCount(0, $this->service()->published($this->loop));
        $this->card()->assertDontSee('Le leur');
    }

    // ── Ce que la revue a trouve ────────────────────────────────────────────

    public function test_the_loops_root_document_is_not_an_article(): void
    {
        // **Le bloquant.** Le document racine d'une Boucle est publie et
        // parfaitement lisible, mais ce n'est pas un article de blog :
        // `listed_in_blog` est faux, et `scopePublished` existe precisement
        // pour l'ecarter. Sans lui, le Manifeste de **chaque** Boucle se lisait
        // comme un Article publie — et le preset Redaction portant aussi
        // `core.manifesto`, il s'affichait deux fois sur le meme ecran.
        $racine = BlogPost::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->auteur->id,
            'title' => 'Ligne editoriale de la Boucle',
            'slug' => 'ligne-'.\Illuminate\Support\Str::random(6),
            'content' => 'x',
            'status' => 'published',
            'audience' => 'loop',
            'listed_in_blog' => false,
            'published_at' => now(),
        ]);

        \App\Models\DossierBlogPost::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $this->dossier->id,
            'blog_post_id' => $racine->id,
            'added_by' => $this->auteur->id,
            'position' => 0,
        ]);

        $this->assertCount(0, $this->service()->published($this->loop));
        $this->card()->assertDontSee('Ligne editoriale de la Boucle');
    }

    public function test_an_article_published_in_the_future_is_not_listed_yet(): void
    {
        $futur = $this->article('Pour la semaine prochaine', 'published');
        $futur->update(['published_at' => now()->addWeek(), 'listed_in_blog' => true]);

        $this->assertCount(0, $this->service()->published($this->loop));
    }

    public function test_a_draft_never_reaches_the_published_list(): void
    {
        // La mutation « retirer le filtre de statut » ne tuait **aucun** des 28
        // tests livres : la liste des publies n'avait aucune garde sur ce
        // qu'elle exclut.
        $this->article('Un brouillon');

        $this->assertCount(0, $this->service()->published($this->loop));
    }

    public function test_no_outbound_link_is_exposed_as_a_livewire_action(): void
    {
        // Livewire expose toute methode publique comme action et resout son
        // argument par liaison implicite — donc sans lien avec cette Boucle. On
        // pouvait passer le slug d'un brouillon d'une autre Organization : la
        // methode rendait une URL valide, et un slug inconnu levait une
        // exception. Les deux reponses se distinguent : un oracle d'existence.
        //
        // **Troisieme fois dans cette serie.**
        $reflet = new \ReflectionClass(LoopArticleCard::class);
        $examinees = 0;

        foreach ($reflet->getMethods(\ReflectionMethod::IS_PUBLIC) as $methode) {
            // `mount` et `render` sont des points du cycle de vie, pas des
            // actions appelables depuis le client.
            if ($methode->getDeclaringClass()->getName() !== LoopArticleCard::class
                || in_array($methode->getName(), ['mount', 'render', 'boot', 'booted', 'hydrate', 'dehydrate'], true)) {
                continue;
            }

            foreach ($methode->getParameters() as $parametre) {
                $type = $parametre->getType();

                $this->assertFalse(
                    $type instanceof \ReflectionNamedType && str_starts_with((string) $type, 'App\\Models\\'),
                    "{$methode->getName()}() prend un modele et est exposee comme action Livewire",
                );
            }

            $examinees++;
        }

        // Sans cela le test passerait si la classe n'exposait plus rien du
        // tout — ou si le filtre ci-dessus devenait trop large.
        $this->assertGreaterThan(0, $examinees, 'aucune methode publique examinee');
    }

    public function test_an_archived_loop_offers_no_way_to_resume_either(): void
    {
        $this->article('Un brouillon');
        $this->loop->forceFill(['status' => 'archived', 'archived_at' => now()])->save();

        $this->card()
            ->assertSee('Un brouillon')
            ->assertDontSee(__('loops.cards.article.resume'));
    }

    public function test_the_pending_invitations_query_does_not_grow_its_bindings(): void
    {
        // Le `pluck` chargeait tous les identifiants du Dossier et les
        // renvoyait dans un `IN (...)` : 6 valeurs pour 2 Articles, 82 pour 40.
        // Le compteur de requetes restait plat — c'est pourquoi la sonde de
        // croissance ne voyait rien.
        $compter = function (int $combien): int {
            $this->dossier->articles()->detach();
            BlogPost::where('organization_id', $this->org->id)->forceDelete();

            for ($i = 0; $i < $combien; $i++) {
                $this->article("article {$i}");
            }

            DB::enableQueryLog();
            DB::flushQueryLog();

            $this->service()->pendingCoAuthors($this->loop);

            $n = collect(DB::getQueryLog())->sum(fn ($r) => count($r['bindings']));
            DB::flushQueryLog();

            return $n;
        };

        $petit = $compter(2);
        $grand = $compter(40);

        $this->assertSame(
            $petit,
            $grand,
            "les liaisons suivent le nombre d'Articles : {$petit} pour 2, {$grand} pour 40",
        );
    }

    public function test_a_loop_without_a_dossier_says_so_plainly(): void
    {
        $sansDossier = $this->loops->createLoop($this->auteur, 'Sans Dossier')->fresh();
        $sansDossier->forceFill(['type' => 'writing'])->save();
        Dossier::where('loop_id', $sansDossier->id)->forceDelete();
        LoopCard::firstOrCreate(
            ['loop_id' => $sansDossier->id, 'card_key' => 'core.article'],
            ['organization_id' => $this->org->id, 'enabled' => true],
        );

        Livewire::actingAs($this->auteur)
            ->test(LoopArticleCard::class, ['loop' => $sansDossier])
            ->assertSee(__('loops.cards.article.no_dossier'));
    }

    // ── Le cout ─────────────────────────────────────────────────────────────

    public function test_the_cost_does_not_follow_the_number_of_articles(): void
    {
        $mesurer = function (int $combien): int {
            $this->dossier->articles()->detach();
            BlogPost::where('organization_id', $this->org->id)->forceDelete();

            for ($i = 0; $i < $combien; $i++) {
                $this->article("brouillon {$i}", 'draft', $i % 2 === 0 ? $this->auteur : $this->autre);
                $this->article("publie {$i}", 'published', $i % 2 === 0 ? $this->auteur : $this->autre);
            }

            DB::enableQueryLog();
            DB::flushQueryLog();

            $this->card()->html();

            $n = count(DB::getQueryLog());
            DB::flushQueryLog();

            return $n;
        };

        $petite = $mesurer(2);
        $grande = $mesurer(30);

        $this->assertLessThanOrEqual(
            $petite + 2,
            $grande,
            "le cout suit le nombre d'Articles : {$petite} requetes pour 2, {$grande} pour 30",
        );
    }

    public function test_the_lists_are_bounded(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->article("brouillon {$i}");
        }

        $this->assertLessThanOrEqual(10, $this->service()->myDrafts($this->loop, $this->auteur)->count());
    }

    // ── Aucune condition sur le type ────────────────────────────────────────

    public function test_no_business_code_branches_on_the_loop_type(): void
    {
        foreach ([
            app_path('Livewire/LoopArticleCard.php'),
            app_path('Services/Loops/LoopWritingService.php'),
            resource_path('views/livewire/loop-article-card.blade.php'),
        ] as $fichier) {
            $source = file_get_contents($fichier);

            foreach (["\$loop->type ===", "\$loop->type =="] as $condition) {
                $this->assertStringNotContainsString($condition, $source, basename($fichier));
            }
        }
    }
}
