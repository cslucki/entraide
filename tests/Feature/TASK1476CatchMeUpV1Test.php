<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopDecision;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\LoopRoadmapItem;
use App\Models\Organization;
use App\Models\User;
use App\Support\Loops\LoopCatchUpDigest;
use App\Support\Loops\LoopCatchUpWindow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1476 — Catch Me Up V1 : « Rattrape-moi depuis… ».
 *
 * ## Le mensonge que ce fichier existe pour empecher
 *
 * La formulation naturelle d'un rattrapage est « depuis votre derniere
 * visite ». Elle est INTERDITE ici, et pas par gout : la mesure faite avant
 * d'ecrire une ligne montre qu'**aucune position de lecture n'existe** pour le
 * contenu d'une Boucle. `messages.read_at` est la messagerie 1-a-1,
 * `member_notifications.read_at` les notifications,
 * `guest_visitors.last_seen_at` les visiteurs publics. Rien ne dit ce qu'un
 * membre a lu dans une Boucle.
 *
 * Un ecran qui promettrait « depuis votre derniere visite » inventerait donc
 * une autorite. La periode est toujours celle que l'utilisateur demande, et
 * l'ecran la NOMME.
 *
 * ## Pourquoi aucun modele n'est appele
 *
 * Le CDC interdit « d'inventer une decision a partir d'une simple phrase
 * ambigue ». Une agregation d'objets structures ne le peut pas, par
 * construction : elle ne rapporte que ce que quelqu'un a deliberement cree —
 * un message envoye, une Decision consignee, un fichier depose, une action
 * ouverte. Aucun resume, aucune interpretation, donc aucune invention
 * possible. Cout : zero.
 *
 * ## Le verrou tenant est double, et il est SABOTE ici
 *
 * Chaque requete filtre sur `loop_id` ET sur `organization_id`. Les tests de
 * la section D plantent des donnees dans une autre Boucle et dans une autre
 * Organization, et exigent qu'aucune ne traverse.
 */
class TASK1476CatchMeUpV1Test extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'is_active' => true,
            'slug' => 'org-catchup',
            'name' => 'Org CatchUp',
        ]);

        $this->member = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'preferred_locale' => 'fr',
        ]);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Boucle Rattrapage',
        ]);

        $this->joinLoop($this->loop, $this->member);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. La periode est explicite — jamais « depuis votre derniere visite »
    // =====================================================================

    public function test_the_default_window_is_seven_days_and_the_screen_says_so(): void
    {
        $html = $this->actingAs($this->member)->get($this->url())->assertOk()->getContent();

        $this->assertStringContainsString('data-catch-up-window', $html);
        $this->assertStringContainsString(e(__('loops.catch_up_window_default', [
            'since' => CarbonImmutable::now()->subDays(7)->startOfDay()->isoFormat('LL'),
            'until' => CarbonImmutable::now()->isoFormat('LL'),
        ])), $html);
    }

    /**
     * La garde centrale du produit. Elle porte sur le TEXTE RENDU, pas sur un
     * fichier de langue : une assertion qui relirait `lang/` se jugerait
     * elle-meme.
     */
    public function test_the_screen_never_claims_to_know_the_last_visit(): void
    {
        // Le modificateur `u` n'est pas decoratif : sans lui, `[eè]` est une
        // classe d'OCTETS, et le `è` de « derniere » en occupe deux. La garde FR
        // ne matchait donc jamais — elle etait morte en silence, et seule la
        // version anglaise faisait rougir quoi que ce soit.
        $forbidden = '/derni[eè]re\s+visite|last\s+visit|dernier\s+passage|since\s+you\s+were\s+last/iu';

        foreach (['fr', 'en'] as $locale) {
            $this->member->update(['preferred_locale' => $locale]);

            $html = $this->actingAs($this->member)->get($this->url())->assertOk()->getContent();

            // L'ecran DIT qu'il ne suit pas la lecture. Cette phrase contient
            // forcement les mots « derniere visite » — pour les nier. Une
            // interdiction brute de la chaine condamnerait donc la denegation
            // en meme temps que la promesse : mesure faite, elle l'a fait.
            //
            // On retire donc l'element de denegation, puis on exige que les mots
            // n'apparaissent NULLE PART ailleurs. Ecrire « Depuis votre derniere
            // visite » dans la phrase de periode rougirait immediatement.
            $this->assertSame(1, preg_match('/<p[^>]*data-catch-up-disclaimer[^>]*>(.*?)<\/p>/s', $html, $disclaimer),
                "[{$locale}] : la denegation doit etre rendue, elle est le contrat");
            $this->assertMatchesRegularExpression($forbidden, $disclaimer[1], "[{$locale}] : et elle doit bien parler de ce qu'elle nie");

            $rest = str_replace($disclaimer[0], '', $html);

            $this->assertDoesNotMatchRegularExpression(
                $forbidden,
                strip_tags($rest),
                "[{$locale}] : aucune position de lecture n'existe, l'ecran ne peut pas en invoquer une",
            );
        }
    }

    public function test_an_explicit_period_is_honoured_and_named(): void
    {
        $html = $this->actingAs($this->member)->get($this->url(['days' => 30]))->assertOk()->getContent();

        $this->assertStringContainsString(e(__('loops.catch_up_window', [
            'since' => CarbonImmutable::now()->subDays(30)->startOfDay()->isoFormat('LL'),
            'until' => CarbonImmutable::now()->isoFormat('LL'),
        ])), $html);

        // Et le preset choisi est celui qui porte l'etat.
        $this->assertSame(1, preg_match('/<button[^>]*data-catch-up-days="30"[^>]*aria-pressed="true"/', $html));
        $this->assertSame(1, preg_match('/<button[^>]*data-catch-up-days="7"[^>]*aria-pressed="false"/', $html));
    }

    /** Une date posee a la main l'emporte sur un preset : c'est le geste le plus precis. */
    public function test_an_explicit_date_wins_over_a_preset(): void
    {
        $since = CarbonImmutable::now()->subDays(3)->format('Y-m-d');

        $window = LoopCatchUpWindow::fromRequest(Request::create('/', 'GET', ['since' => $since, 'days' => 30]));

        $this->assertSame($since, $window->since->format('Y-m-d'));
        $this->assertNull($window->days, 'une date n\'est pas un preset');
        $this->assertFalse($window->isDefault);
    }

    /**
     * Une entree impossible retombe sur le defaut NOMME plutot que d'echouer :
     * un rattrapage n'est pas un formulaire a valider. Ce qui compte est que la
     * fenetre reste vraie.
     */
    public function test_impossible_periods_fall_back_to_the_named_default(): void
    {
        $cases = [
            'futur' => ['since' => CarbonImmutable::now()->addDay()->format('Y-m-d')],
            'trop ancien' => ['since' => CarbonImmutable::now()->subDays(400)->format('Y-m-d')],
            'illisible' => ['since' => 'hier'],
            'preset inconnu' => ['days' => 9999],
            'preset negatif' => ['days' => -7],
        ];

        foreach ($cases as $label => $query) {
            $window = LoopCatchUpWindow::fromRequest(Request::create('/', 'GET', $query));

            $this->assertTrue($window->isDefault, "[{$label}] doit retomber sur le defaut");
            $this->assertSame(LoopCatchUpWindow::DEFAULT_DAYS, $window->days, $label);
        }
    }

    // =====================================================================
    // B. Les quatre sections, et ce qu'elles rapportent
    // =====================================================================

    public function test_the_four_sections_report_only_structured_objects(): void
    {
        $inside = CarbonImmutable::now()->subDays(2);

        $message = $this->message(['body' => 'Le point hebdo est decale a jeudi.', 'created_at' => $inside]);
        $decision = $this->decision(['title' => 'On part sur le format court', 'created_at' => $inside]);
        $file = $this->file(['display_name' => 'Compte-rendu-mars.pdf', 'created_at' => $inside]);
        $action = $this->roadmapItem(['title' => 'Relire le budget', 'status' => 'todo', 'created_at' => $inside]);

        $html = $this->actingAs($this->member)->get($this->url())->assertOk()->getContent();

        $this->assertStringContainsString(e($message->body), $html);
        $this->assertStringContainsString(e($decision->title), $html);
        $this->assertStringContainsString(e($file->display_name), $html);
        $this->assertStringContainsString(e($action->title), $html);

        foreach (LoopCatchUpDigest::SECTIONS as $key) {
            $this->assertStringContainsString('data-catch-up-section="'.$key.'"', $html, $key);
        }
    }

    /** Ce qui est hors de la fenetre n'apparait pas : sinon la periode ne voudrait rien dire. */
    public function test_what_falls_outside_the_window_is_not_reported(): void
    {
        $old = CarbonImmutable::now()->subDays(40);

        $this->message(['body' => 'Message-trop-ancien-TASK1476', 'created_at' => $old]);
        $this->decision(['title' => 'Decision-trop-ancienne-TASK1476', 'created_at' => $old]);

        $html = $this->actingAs($this->member)->get($this->url(['days' => 7]))->assertOk()->getContent();

        $this->assertStringNotContainsString('Message-trop-ancien-TASK1476', $html);
        $this->assertStringNotContainsString('Decision-trop-ancienne-TASK1476', $html);

        // Elargir la fenetre les fait apparaitre : la borne est bien la cause.
        $wide = $this->actingAs($this->member)->get($this->url(['days' => 90]))->assertOk()->getContent();
        $this->assertStringContainsString('Message-trop-ancien-TASK1476', $wide);
        $this->assertStringContainsString('Decision-trop-ancienne-TASK1476', $wide);
    }

    /**
     * Une reponse de l'assistant n'est pas une nouvelle de la Boucle. La
     * compter gonflerait le rattrapage sans rien apprendre a personne.
     */
    public function test_ai_turns_are_not_counted_as_news(): void
    {
        $this->message(['body' => 'Reponse-IA-TASK1476', 'type' => 'ai', 'created_at' => CarbonImmutable::now()->subDay()]);

        $html = $this->actingAs($this->member)->get($this->url())->assertOk()->getContent();

        $this->assertStringNotContainsString('Reponse-IA-TASK1476', $html);
        $this->assertStringContainsString('data-catch-up-empty', $html, 'sans autre activite, la Boucle est vide');
    }

    /** Une action deja terminee n'est plus « ouverte ». */
    public function test_a_finished_action_is_not_open(): void
    {
        $this->roadmapItem(['title' => 'Action-terminee-TASK1476', 'status' => 'done', 'created_at' => CarbonImmutable::now()->subDay()]);
        $this->roadmapItem(['title' => 'Action-en-cours-TASK1476', 'status' => 'in_progress', 'created_at' => CarbonImmutable::now()->subDay()]);

        $html = $this->actingAs($this->member)->get($this->url())->assertOk()->getContent();

        $this->assertStringNotContainsString('Action-terminee-TASK1476', $html);
        $this->assertStringContainsString('Action-en-cours-TASK1476', $html);
    }

    public function test_an_empty_loop_says_so_instead_of_showing_four_empty_titles(): void
    {
        $html = $this->actingAs($this->member)->get($this->url())->assertOk()->getContent();

        $this->assertStringContainsString('data-catch-up-empty', $html);
        $this->assertStringContainsString(e(__('loops.catch_up_empty')), $html);
    }

    // =====================================================================
    // C. Chaque element porte une source, et le lien mene ou l'on a le droit
    // =====================================================================

    public function test_every_item_carries_a_source(): void
    {
        $this->message(['created_at' => CarbonImmutable::now()->subDay()]);
        $this->decision(['created_at' => CarbonImmutable::now()->subDay()]);

        $html = $this->actingAs($this->member)->get($this->url())->assertOk()->getContent();

        $items = preg_match_all('/data-catch-up-item/', $html);
        $sources = preg_match_all('/data-catch-up-source=/', $html);

        $this->assertGreaterThan(0, $items);
        $this->assertSame($items, $sources, 'chaque element rapporte doit nommer sa source');
    }

    /**
     * Le Dossier n'est deep-linke que si `DossierPolicy::view` l'autorise pour
     * CE lecteur. Etre membre de la Boucle ne donne pas acces a tout Dossier :
     * proposer un lien qui rendrait 403 serait une promesse fausse.
     */
    public function test_a_document_link_is_offered_only_when_the_existing_policy_allows_it(): void
    {
        $file = $this->file(['display_name' => 'Note-de-cadrage.pdf', 'created_at' => CarbonImmutable::now()->subDay()]);

        $html = $this->actingAs($this->member)->get($this->url())->assertOk()->getContent();

        $this->assertStringContainsString(e($file->display_name), $html);

        $readable = $this->member->can('view', $file->dossier);
        $this->assertSame(
            $readable,
            str_contains($html, 'data-catch-up-source="dossier"'),
            'le lien profond suit la politique existante, pas l\'appartenance a la Boucle',
        );
        $this->assertSame(! $readable, str_contains($html, 'data-catch-up-source="dossier-locked"'));
    }

    // =====================================================================
    // D. Tenant et viewer — les sabotages
    // =====================================================================

    /** Sabotage : le contenu d'une AUTRE Boucle du meme tenant ne doit pas traverser. */
    public function test_another_loop_of_the_same_organization_never_bleeds_in(): void
    {
        $other = Loop::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Autre Boucle']);

        LoopMessage::factory()->create([
            'loop_id' => $other->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->member->id,
            'body' => 'Message-autre-boucle-TASK1476',
            'created_at' => CarbonImmutable::now()->subDay(),
        ]);

        $html = $this->actingAs($this->member)->get($this->url())->assertOk()->getContent();

        $this->assertStringNotContainsString('Message-autre-boucle-TASK1476', $html);
    }

    /** Sabotage : une autre Organization, jamais. */
    public function test_another_organization_never_bleeds_in(): void
    {
        $otherOrg = Organization::factory()->create(['is_active' => true, 'slug' => 'org-catchup-b']);
        $otherLoop = Loop::factory()->create(['organization_id' => $otherOrg->id]);
        $stranger = User::factory()->complete()->create(['organization_id' => $otherOrg->id]);

        LoopMessage::factory()->create([
            'loop_id' => $otherLoop->id,
            'organization_id' => $otherOrg->id,
            'sender_id' => $stranger->id,
            'body' => 'Message-autre-org-TASK1476',
            'created_at' => CarbonImmutable::now()->subDay(),
        ]);

        // Le digest lu directement : meme sans passer par la route, la requete
        // elle-meme ne doit pas franchir la frontiere.
        $sections = app(LoopCatchUpDigest::class)->for($this->loop, LoopCatchUpWindow::default(), $this->member);

        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                $this->assertStringNotContainsString('Message-autre-org-TASK1476', (string) ($item['title'] ?? ''));
            }
        }

        $html = $this->actingAs($this->member)->get($this->url())->assertOk()->getContent();
        $this->assertStringNotContainsString('Message-autre-org-TASK1476', $html);
    }

    /** Une Boucle d'une autre Organization n'existe pas pour ce lecteur. */
    public function test_a_loop_of_another_organization_is_not_found(): void
    {
        $otherOrg = Organization::factory()->create(['is_active' => true, 'slug' => 'org-catchup-c']);
        $otherLoop = Loop::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($this->member)
            ->get(route('organization.loops.catch-up', ['organization' => $this->organization->slug, 'loop' => $otherLoop->id]))
            ->assertNotFound();
    }

    /**
     * Un non-membre n'obtient pas un rattrapage degrade : il n'en obtient
     * aucun. C'est `LoopPolicy::viewWorkspace` qui tranche — l'autorite
     * existante, pas une variante ecrite ici.
     */
    public function test_a_non_member_gets_nothing(): void
    {
        $outsider = User::factory()->complete()->create(['organization_id' => $this->organization->id]);

        $this->message(['body' => 'Message-reserve-TASK1476', 'created_at' => CarbonImmutable::now()->subDay()]);

        $response = $this->actingAs($outsider)->get($this->url());

        $response->assertForbidden();
        $this->assertStringNotContainsString('Message-reserve-TASK1476', $response->getContent());
    }

    /**
     * Et un membre desactive non plus : la politique le dit deja.
     *
     * La colonne est `banned_at` — `User::isDeactivated()` ne lit rien d'autre.
     * Ecrire `deactivated_at` rendait ce test vert cote base et faux cote
     * verdict : la garde ne se declenchait pas et la page rendait 200.
     */
    public function test_a_deactivated_member_gets_nothing(): void
    {
        $this->member->forceFill(['banned_at' => now()])->save();

        $this->assertTrue($this->member->fresh()->isDeactivated(), 'la garde doit reellement voir le compte desactive');

        // Le depot ecarte un compte desactive plus tot que la politique : la
        // redirection arrive avant l'autorisation. Ce qui compte n'est pas le
        // code exact mais le RESULTAT — la page n'est pas servie, et rien de la
        // Boucle ne fuit dans la reponse.
        $response = $this->actingAs($this->member->fresh())->get($this->url());

        $this->assertNotSame(200, $response->getStatusCode(), 'un compte desactive n\'obtient pas la page');
        $this->assertStringNotContainsString($this->loop->name, (string) $response->getContent());
    }

    // =====================================================================
    // E. Ce que le rattrapage ne fait PAS
    // =====================================================================

    /**
     * Lire un rattrapage ne change pas l'etat du monde : aucune position de
     * lecture posee, aucun objet metier touche. La mesure porte sur les
     * empreintes des quatre tables sources, avant et apres.
     */
    public function test_reading_a_catch_up_mutates_nothing(): void
    {
        $this->message(['created_at' => CarbonImmutable::now()->subDay()]);
        $this->decision(['created_at' => CarbonImmutable::now()->subDay()]);
        $this->roadmapItem(['created_at' => CarbonImmutable::now()->subDay()]);
        $this->file(['created_at' => CarbonImmutable::now()->subDay()]);

        $before = $this->fingerprint();

        $this->actingAs($this->member)->get($this->url())->assertOk();
        $this->actingAs($this->member)->get($this->url(['days' => 30]))->assertOk();

        $this->assertSame($before, $this->fingerprint(), 'un rattrapage se lit, il ne s\'ecrit pas');
    }

    /**
     * Aucun modele n'est appele. `Http::preventStrayRequests()` ferait echouer
     * un appel sortant non feint ; on verifie en plus qu'aucune requete n'a ete
     * emise du tout.
     */
    public function test_no_ai_provider_is_called(): void
    {
        $this->message(['created_at' => CarbonImmutable::now()->subDay()]);

        $this->actingAs($this->member)->get($this->url())->assertOk();

        Http::assertNothingSent();
    }

    /** Le code lui-meme ne connait aucun provider : la garde vit dans la structure, pas dans la discipline. */
    public function test_the_digest_does_not_reach_for_a_provider(): void
    {
        $source = (string) file_get_contents(app_path('Support/Loops/LoopCatchUpDigest.php'));

        foreach (['AiProvider', 'ProviderResolver', 'AiEconomicGuard', 'Http::', 'OpenAI'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, $forbidden.' n\'a rien a faire dans une agregation');
        }
    }

    /** Et aucune migration n'accompagne cette tranche. */
    public function test_no_migration_ships_with_this_slice(): void
    {
        $migrations = glob(database_path('migrations/*catch*up*.php')) ?: [];

        $this->assertSame([], $migrations, 'Catch Me Up V1 s\'appuie sur les tables existantes');
    }

    /**
     * L'invariant de TASK-1466 : cette page n'est pas une porte derobee vers le
     * Shell global.
     *
     * Mesure faite avant correction : le Shell s'y montait, sur une surface que
     * `AiShellPageContext` ne sait pas nommer (`unknown`). C'est exactement la
     * seconde porte que TASK-1466 avait fermee sur les Boucles. La route a donc
     * ete NOMMEE dans `LOOP_SURFACE_ROUTES` — la liste s'etend par un nom, jamais
     * par un `str_contains`.
     */
    public function test_the_catch_up_page_does_not_bring_the_global_shell_back(): void
    {
        $this->assertContains('organization.loops.catch-up', \App\Support\Ai\AiFabContext::LOOP_SURFACE_ROUTES);

        $this->actingAs($this->member)->get($this->url())
            ->assertOk()
            ->assertDontSee('data-ai-shell-panel', false)
            ->assertDontSee('data-ai-fab', false);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function url(array $query = []): string
    {
        return route('organization.loops.catch-up', array_merge([
            'organization' => $this->organization->slug,
            'loop' => $this->loop->id,
        ], $query));
    }

    private function joinLoop(Loop $loop, User $user): void
    {
        LoopMember::query()->create([
            'loop_id' => $loop->id,
            'user_id' => $user->id,
            'role' => 'member',
            'status' => 'active',
        ]);
    }

    private function message(array $attributes = []): LoopMessage
    {
        return LoopMessage::factory()->create(array_merge([
            'loop_id' => $this->loop->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->member->id,
        ], $attributes));
    }

    /**
     * `created_at` n'est pas `fillable` : passe a `create()` il serait
     * SILENCIEUSEMENT ignore, et l'objet naitrait a l'instant present. Un test
     * de fenetre temporelle bati dessus serait vert pour la mauvaise raison —
     * mesure faite, il l'etait.
     */
    private function decision(array $attributes = []): LoopDecision
    {
        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        $decision = LoopDecision::query()->create(array_merge([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'author_id' => $this->member->id,
            'title' => 'Une decision',
            'decided_on' => now()->toDateString(),
        ], $attributes));

        return $this->backdate($decision, $createdAt);
    }

    /** @template T of \Illuminate\Database\Eloquent\Model */
    private function backdate($model, $createdAt)
    {
        if ($createdAt !== null) {
            $model->forceFill(['created_at' => $createdAt])->save();
            $model->refresh();
        }

        return $model;
    }

    private function roadmapItem(array $attributes = []): LoopRoadmapItem
    {
        return LoopRoadmapItem::factory()->create(array_merge([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'created_by' => $this->member->id,
            'status' => 'todo',
        ], $attributes));
    }

    /**
     * Le Dossier d'une Boucle porte `loop_id` et **pas** `owner_id` : PostgreSQL
     * impose `dossiers_holder_xor` — `(owner_id IS NULL) <> (loop_id IS NULL)`,
     * exactement un porteur.
     *
     * La fabrique pose `owner_id` par defaut ; le laisser en place violait la
     * contrainte. Invisible en local — la suite tourne sur SQLite in-memory
     * (`phpunit.xml`) et la contrainte n'est ajoutee que sur `pgsql`. C'est la
     * CI PostgreSQL qui l'a vue.
     */
    private function file(array $attributes = []): DossierFile
    {
        $dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'owner_id' => null,
        ]);

        return DossierFile::factory()->create(array_merge([
            'organization_id' => $this->organization->id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $this->member->id,
        ], $attributes));
    }

    /** @return array<string, string> */
    private function fingerprint(): array
    {
        return [
            'messages' => (string) LoopMessage::query()->where('loop_id', $this->loop->id)->get()->toJson(),
            'decisions' => (string) LoopDecision::query()->where('loop_id', $this->loop->id)->get()->toJson(),
            'roadmap' => (string) LoopRoadmapItem::query()->where('loop_id', $this->loop->id)->get()->toJson(),
            'files' => (string) DossierFile::query()->where('organization_id', $this->organization->id)->get()->toJson(),
        ];
    }
}
