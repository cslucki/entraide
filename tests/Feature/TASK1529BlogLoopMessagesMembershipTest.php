<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1529 — le droit d'ecrire l'article n'est pas un droit de lire la Boucle.
 *
 * `BlogPostLoopController::messages()` calculait deja `is_member` par Boucle
 * liee, mais ne s'en servait que comme drapeau d'affichage : les trois
 * derniers messages partaient dans la reponse quoi qu'il arrive. Un co-auteur
 * ajoute sur l'article (geste editorial ordinaire, qui n'exige aucune
 * appartenance a la Boucle) lisait donc le contenu prive d'une Boucle ou il
 * n'est jamais entre — de meme qu'un ex-membre qui l'a quittee.
 *
 * Organization = Tenant, Loop != Tenant : partager l'Organization ne donne
 * jamais acces aux messages d'une Boucle privee.
 *
 * Le contrat d'EXISTENCE de la Boucle (id, nom, slug, lien, drapeau
 * `is_member`) n'est volontairement pas modifie ici : le P0 ferme est la
 * lecture du CONTENU prive.
 */
class TASK1529BlogLoopMessagesMembershipTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $otherOrg;

    private User $author;

    private User $coAuthor;

    private User $crossOrgUser;

    private BlogPost $post;

    private LoopService $loopService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_default' => true]);
        $this->otherOrg = Organization::factory()->create();

        $this->author = User::factory()->create(['organization_id' => $this->org->id]);
        $this->coAuthor = User::factory()->create(['organization_id' => $this->org->id]);
        $this->crossOrgUser = User::factory()->create(['organization_id' => $this->otherOrg->id]);

        app()->instance('current_organization', $this->org);

        $this->post = BlogPost::create([
            'user_id' => $this->author->id,
            'organization_id' => $this->org->id,
            'title' => 'Article TASK-1529',
            'content' => 'Contenu de test pour la fuite de messages de Boucle.',
            'status' => 'draft',
        ]);

        $this->loopService = new LoopService;
    }

    /**
     * Une Boucle privee dont l'auteur est membre, portant un message au corps
     * reconnaissable (jamais une valeur Faker courte : la chaine doit pouvoir
     * etre cherchee dans la reponse brute sans faux positif).
     */
    private function loopWithMessage(User $creator, string $loopName, string $body): Loop
    {
        $loop = $this->loopService->createLoop($creator, $loopName, 'Description '.$loopName);

        LoopMessage::create([
            'loop_id' => $loop->id,
            'sender_id' => $creator->id,
            'body' => $body,
            'type' => 'user',
        ]);

        $this->post->loops()->attach($loop->id);

        return $loop;
    }

    private function makeCoAuthor(User $user): void
    {
        $this->post->coAuthors()->attach($user->id, [
            'role' => 'coauthor',
            'added_by' => $this->author->id,
        ]);
    }

    /** CAS A — auteur de l'article ET membre de la Boucle : contenu autorise. */
    public function test_author_who_is_loop_member_still_receives_messages(): void
    {
        $body = 'Message prive de la Boucle visible au membre TASK-1529';
        $this->loopWithMessage($this->author, 'Boucle de l auteur', $body);

        $this->actingAs($this->author);

        $response = $this->getJson("/blog/{$this->post->slug}/loop-messages");

        $response->assertOk();
        $response->assertJsonPath('loops.0.is_member', true);

        $messages = $response->json('loops.0.messages');
        $this->assertCount(1, $messages, 'Un membre actif doit continuer a lire les messages.');
        $this->assertSame($body, $messages[0]['body']);
        $this->assertSame($this->author->name, $messages[0]['sender_name']);
    }

    /**
     * CAS B — co-auteur de l'article, meme Organization, NON membre de la
     * Boucle : aucun corps, aucun expediteur, aucun identifiant de message.
     *
     * C'est le test que le sabotage doit faire rougir.
     */
    public function test_co_author_who_is_not_a_loop_member_receives_no_message_content(): void
    {
        $body = 'Secret de la Boucle que le co-auteur ne doit jamais lire TASK-1529';
        $loop = $this->loopWithMessage($this->author, 'Boucle privee de l auteur', $body);

        $this->makeCoAuthor($this->coAuthor);
        $this->actingAs($this->coAuthor);

        $response = $this->getJson("/blog/{$this->post->slug}/loop-messages");

        $response->assertOk();
        $response->assertJsonPath('loops.0.is_member', false);

        // Aucun message : ni corps, ni expediteur, ni id exploitable ensuite.
        $this->assertSame([], $response->json('loops.0.messages'));

        // Et rien n'a fuite par un autre chemin de la meme reponse.
        $response->assertDontSee($body, false);
        $response->assertDontSee($this->author->name, false);

        $leakedId = LoopMessage::where('loop_id', $loop->id)->value('id');
        $response->assertDontSee($leakedId, false);
    }

    /** CAS C — utilisateur d'une autre Organization : garde tenant inchangee. */
    public function test_cross_organization_user_is_refused_before_any_content(): void
    {
        $body = 'Contenu cross-org interdit TASK-1529';
        $this->loopWithMessage($this->author, 'Boucle du tenant courant', $body);

        app()->instance('current_organization', $this->otherOrg);
        $this->actingAs($this->crossOrgUser);

        $response = $this->getJson("/blog/{$this->post->slug}/loop-messages");

        $response->assertNotFound();
        $response->assertDontSee($body, false);
    }

    /**
     * CAS D — deux Boucles liees au meme article : la garde est par Boucle,
     * pas par article. Membre de L1, etranger a L2.
     */
    public function test_membership_is_resolved_per_loop_not_per_post(): void
    {
        $visibleBody = 'Message de la Boucle dont le co-auteur EST membre TASK-1529';
        $hiddenBody = 'Message de la Boucle dont le co-auteur n est PAS membre TASK-1529';

        // L1 : creee par le co-auteur, il en est donc membre actif.
        $memberLoop = $this->loopWithMessage($this->coAuthor, 'Boucle du co-auteur', $visibleBody);
        // L2 : creee par l'auteur seul.
        $foreignLoop = $this->loopWithMessage($this->author, 'Boucle fermee au co-auteur', $hiddenBody);

        $this->makeCoAuthor($this->coAuthor);
        $this->actingAs($this->coAuthor);

        $response = $this->getJson("/blog/{$this->post->slug}/loop-messages");
        $response->assertOk();

        $loops = collect($response->json('loops'))->keyBy('id');

        $this->assertTrue($loops[$memberLoop->id]['is_member']);
        $this->assertCount(1, $loops[$memberLoop->id]['messages']);
        $this->assertSame($visibleBody, $loops[$memberLoop->id]['messages'][0]['body']);

        $this->assertFalse($loops[$foreignLoop->id]['is_member']);
        $this->assertSame([], $loops[$foreignLoop->id]['messages']);

        $response->assertDontSee($hiddenBody, false);
    }

    /** CAS E — la route prefixee par l'Organization se comporte a l'identique. */
    public function test_org_prefixed_alias_applies_the_same_guard(): void
    {
        $body = 'Secret vu depuis la route org-prefixee TASK-1529';
        $this->loopWithMessage($this->author, 'Boucle privee alias org', $body);

        $this->makeCoAuthor($this->coAuthor);
        $this->actingAs($this->coAuthor);

        $response = $this->getJson("/org/{$this->org->slug}/blog/{$this->post->slug}/loop-messages");

        $response->assertOk();
        $response->assertJsonPath('loops.0.is_member', false);
        $this->assertSame([], $response->json('loops.0.messages'));
        $response->assertDontSee($body, false);
    }

    /**
     * Un ex-membre perd l'acces au contenu : l'appartenance est relue a chaque
     * requete, jamais heritee du moment ou la Boucle a ete liee a l'article.
     */
    public function test_former_member_loses_access_to_loop_content(): void
    {
        $body = 'Message poste apres le depart de l ex-membre TASK-1529';
        $loop = $this->loopWithMessage($this->author, 'Boucle quittee', $body);

        // L'auteur a lie la Boucle en tant que membre, puis la quitte.
        $loop->members()->where('user_id', $this->author->id)->update(['status' => 'left']);

        $this->actingAs($this->author);

        $response = $this->getJson("/blog/{$this->post->slug}/loop-messages");

        $response->assertOk();
        $response->assertJsonPath('loops.0.is_member', false);
        $this->assertSame([], $response->json('loops.0.messages'));
        $response->assertDontSee($body, false);
    }

    protected function tearDown(): void
    {
        Organization::where('is_default', true)->update(['is_default' => false]);
        parent::tearDown();
    }
}
