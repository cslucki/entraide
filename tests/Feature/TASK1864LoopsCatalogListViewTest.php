<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-1864 — socle des deux presentations du catalogue `/loops` :
 * `grid` (cartes, par defaut) et `list` (`?view=list`).
 *
 * Ce que la liste ajoute, et ses gardes :
 * - le dernier message HUMAIN (ni IA, ni supprime) de chaque Boucle dont la
 *   personne est MEMBRE ;
 * - jamais un mot de conversation pour une Boucle dont elle n'est pas membre ;
 * - un extrait echappe ;
 * - un cout en requetes qui ne grandit pas avec le nombre de Boucles.
 */
class TASK1864LoopsCatalogListViewTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $this->org = Organization::factory()->create([
            'is_active' => true,
            'loops_enabled' => true,
        ]);

        app()->instance('current_organization', $this->org);
    }

    private function catalog(User $user, array $query = [], ?Organization $org = null): string
    {
        return $this->actingAs($user)
            ->get(route('organization.loops.index', ['organization' => ($org ?? $this->org)->slug] + $query))
            ->assertOk()
            ->getContent();
    }

    /** `created_at` hors `$fillable` : pose a part pour un ordre deterministe. */
    private function messageAt(Loop $loop, ?User $sender, string $body, Carbon $when, array $extra = []): LoopMessage
    {
        $message = new LoopMessage(array_merge([
            'loop_id' => $loop->id,
            'organization_id' => $this->org->id,
            'sender_id' => $sender?->id,
            'body' => $body,
            'type' => $sender ? 'user' : 'ai',
        ], $extra));
        $message->created_at = $when;
        $message->save();

        return $message;
    }

    public function test_grid_is_the_default_and_an_unknown_view_falls_back_to_it(): void
    {
        $owner = User::factory()->create(['organization_id' => $this->org->id]);
        $service = new LoopService;
        $loop = $service->createLoop($owner, 'Boucle A');
        $service->createLoop($owner, 'Boucle B');
        $this->messageAt($loop, $owner, 'Message secret de la grille', now());

        foreach ([[], ['view' => 'grid'], ['view' => 'n-importe-quoi']] as $query) {
            $html = $this->catalog($owner, $query);

            $this->assertStringContainsString('data-loops-view-switch', $html);
            $this->assertMatchesRegularExpression('/data-loops-view-switch-option="grid"\s+aria-current="true"/', $html);
            $this->assertStringNotContainsString('data-loops-catalog-row=', $html);
            // La grille ne charge ni n'affiche aucun apercu de message.
            $this->assertStringNotContainsString('Message secret de la grille', $html);
        }
    }

    public function test_list_view_shows_the_latest_human_message_of_each_member_loop(): void
    {
        $moi = User::factory()->create(['organization_id' => $this->org->id]);
        $autre = User::factory()->create(['organization_id' => $this->org->id, 'name' => 'Camille Autre']);

        $service = new LoopService;
        $loopA = $service->createLoop($moi, 'Boucle A');
        $service->addMemberByUserId($loopA, $autre->id);
        $loopVide = $service->createLoop($moi, 'Boucle sans message');

        $this->messageAt($loopA, $moi, 'Ancien message humain', now()->subHour());
        $this->messageAt($loopA, $autre, 'Dernier message humain', now()->subMinutes(10));
        // Plus RECENTS, mais hors regle : prouve l'exclusion par la regle, pas par la date.
        $this->messageAt($loopA, null, 'Reponse IA recente', now()->subMinutes(5));
        $this->messageAt($loopA, $autre, 'Message supprime recent', now()->subMinute(), ['deleted_at' => now()]);

        $html = $this->catalog($moi, ['view' => 'list']);

        $this->assertMatchesRegularExpression('/data-loops-view-switch-option="list"\s+aria-current="true"/', $html);
        $this->assertStringContainsString('data-loops-catalog-row="'.$loopA->id.'"', $html);
        $this->assertStringContainsString('data-loops-catalog-row="'.$loopVide->id.'"', $html);

        $this->assertStringContainsString('Dernier message humain', $html);
        $this->assertStringContainsString(e($autre->publicDisplayName()), $html);
        $this->assertStringNotContainsString('Ancien message humain', $html);
        $this->assertStringNotContainsString('Reponse IA recente', $html);
        $this->assertStringNotContainsString('Message supprime recent', $html);

        // Une seule ligne de dernier message : la Boucle vide affiche l'etat vide.
        $this->assertSame(1, substr_count($html, 'data-loops-catalog-row-last-message'));
        $this->assertStringContainsString(e(__('loops.catalog_row_no_message')), $html);
    }

    public function test_list_view_never_previews_a_conversation_the_user_is_not_a_member_of(): void
    {
        $moi = User::factory()->create(['organization_id' => $this->org->id]);
        $autre = User::factory()->create(['organization_id' => $this->org->id]);

        $service = new LoopService;
        $service->createLoop($moi, 'Ma Boucle');
        $etrangere = $service->createLoop($autre, 'Boucle des autres');
        $this->messageAt($etrangere, $autre, 'Conversation reservee aux membres', now());

        $html = $this->catalog($moi, ['view' => 'list']);

        // La Boucle reste decouvrable (TASK-1075)...
        $this->assertStringContainsString('data-loops-catalog-row="'.$etrangere->id.'"', $html);
        $this->assertStringContainsString('Boucle des autres', $html);
        // ...mais rien de sa conversation ne sort.
        $this->assertStringNotContainsString('Conversation reservee aux membres', $html);
        $this->assertSame(0, substr_count($html, 'data-loops-catalog-row-last-message'));
    }

    public function test_a_message_from_another_organization_is_never_previewed(): void
    {
        $moi = User::factory()->create(['organization_id' => $this->org->id]);
        $service = new LoopService;
        $loop = $service->createLoop($moi, 'Boucle A');
        $service->createLoop($moi, 'Boucle B');

        $autreOrg = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);

        $this->messageAt($loop, $moi, 'Message de la bonne Organization', now()->subHour());
        // Ligne incoherente (autre locataire) : la defense en profondeur l'ecarte.
        $this->messageAt($loop, $moi, 'Message d un autre locataire', now(), ['organization_id' => $autreOrg->id]);

        $html = $this->catalog($moi, ['view' => 'list']);

        $this->assertStringContainsString('Message de la bonne Organization', $html);
        $this->assertStringNotContainsString('Message d un autre locataire', $html);
    }

    public function test_the_message_excerpt_is_escaped(): void
    {
        $moi = User::factory()->create(['organization_id' => $this->org->id]);
        $service = new LoopService;
        $loop = $service->createLoop($moi, 'Boucle A');
        $service->createLoop($moi, 'Boucle B');
        $this->messageAt($loop, $moi, '<script>alert("x")</script><b>gras</b>', now());

        $html = $this->catalog($moi, ['view' => 'list']);

        $this->assertStringNotContainsString('<script>alert("x")</script>', $html);
        $this->assertStringNotContainsString('<b>gras</b>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_the_list_view_adds_a_constant_number_of_queries_whatever_the_number_of_loops(): void
    {
        // Une Organization neuve par mesure : deux catalogues sans rien en commun.
        $extraFor = function (int $loopCount): int {
            $org = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
            $this->org = $org;
            app()->instance('current_organization', $org);
            $moi = User::factory()->create(['organization_id' => $org->id]);
            $autre = User::factory()->create(['organization_id' => $org->id]);
            $service = new LoopService;

            for ($i = 0; $i < $loopCount; $i++) {
                $loop = $service->createLoop($moi, "Boucle {$loopCount}-{$i}");
                $service->addMemberByUserId($loop, $autre->id);
                $this->messageAt($loop, $moi, "Premier {$i}", now()->subMinutes(2));
                $this->messageAt($loop, $autre, "Second {$i}", now()->subMinute());
            }

            $count = function (array $query) use ($moi, $org): int {
                DB::flushQueryLog();
                DB::enableQueryLog();
                $this->catalog($moi, $query, $org);
                $n = count(DB::getQueryLog());
                DB::disableQueryLog();

                return $n;
            };

            // Requete de chauffe, non mesuree : la PREMIERE requete HTTP d'un
            // test paie des chargements de mise en page memorises ensuite
            // (liens de comptes, Organization, utilisateur). Sans elle, la
            // premiere mesuree semble couter 3 requetes de plus.
            $this->catalog($moi, [], $org);

            // Ce que la LISTE coute en plus de la grille, sur le meme catalogue.
            return $count(['view' => 'list']) - $count([]);
        };

        $extraSmall = $extraFor(2);
        $extraLarge = $extraFor(6);

        // Constant, et exactement : 1 fenetre `row_number()` (le dernier
        // message de chaque Boucle) + 1 lot d'auteurs.
        $this->assertSame($extraSmall, $extraLarge);
        $this->assertSame(2, $extraLarge);
    }
}
