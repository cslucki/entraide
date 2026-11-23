<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopEvent;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use App\Support\Loops\LoopSearch;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1885 C9 (demande Cyril, 22/11) — rechercher dans MES Boucles :
 * messages, articles, dossiers/fichiers, evenements ; resultats groupes par
 * Boucle ; rien d'une Boucle dont on n'est pas membre, ni d'un contenu
 * supprime, brouillon ou annule.
 */
class TASK1885LoopSearchTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $membre;

    private User $autre;

    private Loop $mienne;

    private Loop $etrangere;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['slug' => 'org-1885-c9', 'is_active' => true, 'is_public' => true, 'loops_enabled' => true]);
        app()->instance('current_organization', $this->org);
        $this->membre = User::factory()->complete()->create(['organization_id' => $this->org->id, 'email_verified_at' => now()]);
        $this->autre = User::factory()->complete()->create(['organization_id' => $this->org->id, 'email_verified_at' => now()]);

        $service = new LoopService;
        $this->mienne = $service->createLoop($this->membre, 'Boucle jardin');
        $this->etrangere = $service->createLoop($this->autre, 'Boucle secrete');
    }

    private function url(string $q): string
    {
        return route('organization.loops.search', ['organization' => $this->org->slug, 'q' => $q]);
    }

    private function message(Loop $loop, User $de, string $texte, bool $supprime = false): LoopMessage
    {
        return LoopMessage::create([
            'loop_id' => $loop->id, 'sender_id' => $de->id, 'body' => $texte, 'type' => 'text',
            'organization_id' => $this->org->id, 'deleted_at' => $supprime ? now() : null,
        ]);
    }

    private function article(Loop $loop, string $titre, string $statut): BlogPost
    {
        $post = BlogPost::create([
            'user_id' => $this->membre->id, 'organization_id' => $this->org->id,
            'title' => $titre, 'slug' => \Illuminate\Support\Str::slug($titre), 'content' => '<p>Corps de '.$titre.'</p>',
            'status' => $statut, 'published_at' => $statut === 'published' ? now()->subDay() : null, 'listed_in_blog' => true,
        ]);
        $post->loops()->attach($loop->id);

        return $post;
    }

    private function evenement(Loop $loop, string $titre, string $statut = LoopEvent::STATUS_SCHEDULED): LoopEvent
    {
        return LoopEvent::create([
            'organization_id' => $this->org->id, 'loop_id' => $loop->id, 'created_by' => $this->membre->id,
            'status' => $statut, 'title' => $titre, 'description' => null, 'format' => LoopEvent::FORMAT_IN_PERSON,
            'starts_at' => CarbonImmutable::parse('2026-12-01 10:00')->utc(), 'ends_at' => null,
            'timezone' => 'Europe/Paris', 'location' => '', 'meeting_url' => '', 'visibility' => LoopEvent::VISIBILITY_LOOP,
            'cancelled_at' => $statut === LoopEvent::STATUS_CANCELLED ? now() : null,
        ]);
    }

    public function test_results_cover_the_four_families_of_my_loops_only(): void
    {
        $trouve = $this->message($this->mienne, $this->membre, 'On plante les TOMATES samedi');
        $supprime = $this->message($this->mienne, $this->membre, 'tomates effacees', supprime: true);
        $secret = $this->message($this->etrangere, $this->autre, 'tomates de la boucle secrete');
        $publie = $this->article($this->mienne, 'Guide des tomates', 'published');
        $brouillon = $this->article($this->mienne, 'Brouillon tomates', 'draft');
        $evenement = $this->evenement($this->mienne, 'Atelier tomates');
        $annule = $this->evenement($this->mienne, 'Tomates annule', LoopEvent::STATUS_CANCELLED);

        $racine = Dossier::query()->where('loop_id', $this->mienne->id)->first()
            ?? Dossier::factory()->create(['organization_id' => $this->org->id, 'loop_id' => $this->mienne->id, 'owner_id' => null, 'name' => 'Racine']);
        $sous = Dossier::factory()->create(['organization_id' => $this->org->id, 'parent_id' => $racine->id, 'loop_id' => null, 'owner_id' => null, 'name' => 'Semis de tomates']);
        $fichier = DossierFile::factory()->create(['organization_id' => $this->org->id, 'dossier_id' => $sous->id, 'uploaded_by' => $this->membre->id, 'display_name' => 'plan-tomates.pdf']);

        $html = $this->actingAs($this->membre)->get($this->url('tomates'))->assertOk()->getContent();

        $this->assertStringContainsString('data-loops-search-section="'.$this->mienne->id.'"', $html);
        $this->assertStringNotContainsString('data-loops-search-section="'.$this->etrangere->id.'"', $html);
        $this->assertStringContainsString('data-loops-search-message="'.$trouve->id.'"', $html);
        $this->assertStringNotContainsString($supprime->id, $html);
        $this->assertStringNotContainsString($secret->id, $html);
        $this->assertStringContainsString('data-loops-search-article="'.$publie->id.'"', $html);
        $this->assertStringNotContainsString($brouillon->id, $html);
        $this->assertStringContainsString('data-loops-search-event="'.$evenement->id.'"', $html);
        $this->assertStringNotContainsString($annule->id, $html);
        $this->assertStringContainsString('data-loops-search-dossier="'.$sous->id.'"', $html);
        $this->assertStringContainsString('data-loops-search-file="'.$fichier->id.'"', $html);
        // Lien direct vers le message, surlignage insensible a la casse.
        $this->assertStringContainsString('message='.$trouve->id, $html);
        $this->assertStringContainsString('<mark data-loop-search-hit', $html);
        $this->assertStringContainsString('card=core.events', $html);
    }

    public function test_term_is_escaped_and_wildcards_are_literal(): void
    {
        $this->message($this->mienne, $this->membre, 'Rien de special ici');
        $balise = $this->message($this->mienne, $this->membre, 'Attention <script>alert(1)</script> 100% bio');

        // `%` est cherche litteralement, pas comme joker.
        $html = $this->actingAs($this->membre)->get($this->url('100%'))->assertOk()->getContent();
        $this->assertStringContainsString('data-loops-search-message="'.$balise->id.'"', $html);
        $this->assertSame(1, substr_count($html, 'data-loops-search-message='));
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);

        $extrait = (string) LoopSearch::extrait('a <b>x</b> & "y"', '<b>');
        $this->assertStringNotContainsString('<b>', $extrait);
    }

    public function test_short_term_member_without_loop_and_index_trigger(): void
    {
        $this->actingAs($this->membre)->get($this->url('t'))->assertOk()
            ->assertSee(__('loops.search_too_short', ['min' => LoopSearch::MIN]));

        $seul = User::factory()->complete()->create(['organization_id' => $this->org->id, 'email_verified_at' => now()]);
        $this->actingAs($seul)->get($this->url('tomates'))->assertOk()
            ->assertSee('data-loops-search-no-loop', false);

        // La loupe sur le catalogue : champ pour un membre, bulle sinon.
        $this->actingAs($this->membre)->get(route('organization.loops.index', ['organization' => $this->org->slug]))
            ->assertOk()->assertSee('data-loops-search-inline-form', false)->assertDontSee('data-loops-search-no-loop-nudge', false);
        $this->actingAs($seul)->get(route('organization.loops.index', ['organization' => $this->org->slug]))
            ->assertOk()->assertSee('data-loops-search-no-loop-nudge', false)->assertDontSee('data-loops-search-inline-form', false);
    }
}
