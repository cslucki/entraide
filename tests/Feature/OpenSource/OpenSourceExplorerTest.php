<?php

namespace Tests\Feature\OpenSource;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1612 — Open Source Explorer.
 *
 * Ce que ces tests tiennent, et pourquoi chacun existe :
 *
 *  - l'owner du depot et l'URL GitHub n'apparaissent NI dans la reponse de
 *    l'endpoint, NI dans le HTML du pied de page (regle de presentation) ;
 *  - un message de commit est reduit a son SUJET : le corps de ce depot
 *    porte `Co-Authored-By:` et une URL `Claude-Session:` ;
 *  - les fichiers n'ont pas de message de commit, et on n'en invente pas ;
 *  - le budget d'appels sortants tient sous le plafond configure ;
 *  - une panne GitHub ne casse rien : soit le repli date, soit l'etat
 *    « indisponible », jamais une erreur technique.
 */
class OpenSourceExplorerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'open_source.repository.owner' => 'cslucki',
            'open_source.repository.name' => 'entraide',
            'open_source.repository.branch' => 'main',
            'open_source.token' => null,
        ]);
    }

    /**
     * Le sujet reel d'un commit du depot, corps compris. C'est cette forme
     * qui a motive la coupe a la premiere ligne.
     */
    private const COMMIT_MESSAGE = "feat(flowchart): presentation mobile (TASK-1609)\n\nMobile :\n- le titre ne s'ecrit plus deux fois\n\nCo-Authored-By: Claude Opus 5 <noreply@anthropic.com>\nClaude-Session: https://claude.ai/code/session_01ABC";

    /**
     * Remet les doublures HTTP a zero.
     *
     * `Http::fake()` EMPILE : un second appel n'ecrase pas les motifs du
     * premier, il les suit dans la pile, et c'est le motif pose en PREMIER
     * qui l'emporte. Deux tests d'affilee sur la meme requete — d'abord
     * GitHub qui repond, puis GitHub en panne — auraient donc rejoue la
     * reponse saine et mesure exactement le contraire de leur intention.
     * Mesure faite : c'est ce qui les a fait rougir au premier passage.
     *
     * La garde d'isolation reseau (TASK-1280) est reposee derriere : un
     * Factory neuf ne l'a pas.
     */
    private function resetHttpFakes(): void
    {
        Http::swap(new Factory(app(Dispatcher::class)));
        Http::preventStrayRequests();
    }

    /**
     * @param  list<array{name: string, type: string}>|null  $entries
     */
    private function fakeGithub(?array $entries = null, bool $down = false): void
    {
        if ($down) {
            Http::fake(['api.github.com/*' => Http::response('', 503)]);

            return;
        }

        $entries ??= [
            ['name' => 'README.md', 'type' => 'file'],
            ['name' => 'app', 'type' => 'dir'],
            ['name' => '.github', 'type' => 'dir'],
            ['name' => 'artisan', 'type' => 'file'],
        ];

        Http::fake([
            'api.github.com/repos/*/contents*' => Http::response($entries),
            'api.github.com/repos/*/branches*' => Http::response(
                [['name' => 'main']],
                200,
                ['Link' => '<https://api.github.com/repositories/1/branches?per_page=1&page=2>; rel="next", <https://api.github.com/repositories/1/branches?per_page=1&page=4>; rel="last"'],
            ),
            // Ce depot n'a aucun tag : pas d'en-tete Link, un tableau vide.
            'api.github.com/repos/*/tags*' => Http::response([]),
            'api.github.com/repos/*/commits*' => function (Request $request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                // Sans `path`, c'est le comptage global : seul l'en-tete Link
                // compte.
                if (! isset($query['path'])) {
                    return Http::response(
                        [['sha' => 'abc']],
                        200,
                        ['Link' => '<https://api.github.com/repositories/1/commits?per_page=1&page=2>; rel="next", <https://api.github.com/repositories/1/commits?per_page=1&page=2778>; rel="last"'],
                    );
                }

                return Http::response([[
                    'sha' => 'deadbeef',
                    'commit' => [
                        'message' => self::COMMIT_MESSAGE,
                        'committer' => ['date' => '2026-09-20T15:37:01Z'],
                    ],
                    // Le passe-plat qui ne doit JAMAIS ressortir.
                    'author' => ['login' => 'cslucki', 'html_url' => 'https://github.com/cslucki'],
                ]]);
            },
            'api.github.com/repos/*' => Http::response([
                'name' => 'entraide',
                'full_name' => 'cslucki/entraide',
                'private' => false,
                'default_branch' => 'main',
                'language' => 'PHP',
                'pushed_at' => '2026-09-20T19:34:28Z',
                'html_url' => 'https://github.com/cslucki/entraide',
                'owner' => ['login' => 'cslucki'],
            ]),
        ]);
    }

    public function test_the_endpoint_serves_the_repository_root(): void
    {
        $this->fakeGithub();

        $response = $this->getJson('/open-source/repository');

        $response->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('name', 'BouclePro Core')
            ->assertJsonPath('badges.license', 'AGPL-3.0')
            ->assertJsonPath('badges.language', 'PHP')
            ->assertJsonPath('activity.default_branch', 'main')
            ->assertJsonPath('activity.branches', 4)
            ->assertJsonPath('activity.commits', 2778)
            ->assertJsonPath('activity.tags', 0);

        $entries = $response->json('entries');

        // Dossiers d'abord, alphabetiquement, puis fichiers : la racine se
        // lit comme un explorateur, pas comme l'ordre de l'API.
        $this->assertSame(
            ['.github', 'app', 'artisan', 'README.md'],
            array_column($entries, 'name'),
        );
        $this->assertSame(['dir', 'dir', 'file', 'file'], array_column($entries, 'type'));
    }

    public function test_a_commit_message_is_reduced_to_its_subject(): void
    {
        $this->fakeGithub();

        $entries = collect($this->getJson('/open-source/repository')->json('entries'))
            ->keyBy('name');

        $this->assertSame(
            'feat(flowchart): presentation mobile (TASK-1609)',
            $entries['app']['commit']['subject'],
        );
        $this->assertSame('2026-09-20T15:37:01Z', $entries['app']['commit']['at']);
    }

    public function test_files_carry_no_commit_and_none_is_invented(): void
    {
        $this->fakeGithub();

        $entries = collect($this->getJson('/open-source/repository')->json('entries'))
            ->keyBy('name');

        $this->assertNull($entries['README.md']['commit']);
        $this->assertNull($entries['artisan']['commit']);
        $this->assertNotNull($entries['app']['commit']);

        // Arbitrage MASTER : un appel `?path=` par DOSSIER, aucun par
        // fichier. Le sabotage inverse (un appel par entree) ferait passer
        // ce compte a 4.
        $pathCalls = collect(Http::recorded())
            ->filter(fn (array $pair) => str_contains($pair[0]->url(), 'path='))
            ->count();

        $this->assertSame(2, $pathCalls, 'Un appel commit par dossier, jamais par fichier.');
    }

    public function test_the_outgoing_call_budget_is_respected(): void
    {
        // Une racine large : 12 dossiers, soit plus que le budget ne peut
        // en payer une fois les 5 appels de tete consommes.
        $entries = [];
        foreach (range(1, 12) as $index) {
            $entries[] = ['name' => 'dir'.$index, 'type' => 'dir'];
        }

        $this->fakeGithub($entries);
        config(['open_source.call_budget' => 8]);

        $response = $this->getJson('/open-source/repository');

        $this->assertLessThanOrEqual(8, count(Http::recorded()));

        // Le budget TRONQUE, il ne casse pas : les 12 entrees sont la, et
        // celles qui n'ont pas eu d'appel n'ont simplement pas de message.
        $this->assertCount(12, $response->json('entries'));
        $this->assertSame(true, $response->json('available'));
    }

    public function test_the_snapshot_is_cached_server_side(): void
    {
        $this->fakeGithub();

        $this->getJson('/open-source/repository')->assertOk();
        $first = count(Http::recorded());

        $this->getJson('/open-source/repository')->assertOk();

        $this->assertSame($first, count(Http::recorded()), 'Le second appel doit etre servi par le cache.');
        $this->assertGreaterThan(0, $first);
    }

    public function test_a_github_outage_does_not_break_the_page(): void
    {
        $this->fakeGithub(down: true);

        $this->getJson('/open-source/repository')
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('name', 'BouclePro Core')
            // Les badges declares restent : ils ne dependent pas de GitHub.
            ->assertJsonPath('badges.license', 'AGPL-3.0')
            ->assertJsonPath('entries', []);
    }

    public function test_the_last_known_snapshot_survives_an_outage(): void
    {
        $this->fakeGithub();
        $this->getJson('/open-source/repository')->assertOk();

        // Le frais expire, le repli 24 h tient encore.
        Cache::forget('open_source.snapshot.v1');
        $this->resetHttpFakes();
        $this->fakeGithub(down: true);

        $this->getJson('/open-source/repository')
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('stale', true)
            ->assertJsonPath('entries.0.name', '.github');
    }

    public function test_a_repository_turned_private_stops_being_shown(): void
    {
        $this->fakeGithub();
        $this->getJson('/open-source/repository')->assertOk();

        Cache::forget('open_source.snapshot.v1');

        $this->resetHttpFakes();
        Http::fake(['api.github.com/*' => Http::response(['private' => true, 'name' => 'entraide'])]);

        // Le repli 24 h existe encore, mais le depot n'est plus public :
        // rejouer sa racine parce qu'elle etait en cache serait une fuite.
        // C'est le SEUL cas ou le repli est detruit au lieu d'etre servi.
        $this->getJson('/open-source/repository')
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('entries', []);

        $this->assertNull(Cache::get('open_source.snapshot.stale.v1'));
    }

    /* ================================================================== */
    /* Regle de presentation : ni owner, ni URL brute                      */
    /* ================================================================== */

    public function test_the_endpoint_never_leaks_the_repository_owner(): void
    {
        $this->fakeGithub();

        $payload = $this->getJson('/open-source/repository')->getContent();

        $this->assertStringNotContainsString('cslucki', $payload);
        $this->assertStringNotContainsString('github.com', $payload);
        $this->assertStringNotContainsString('entraide', $payload);
        // Le corps du commit, et donc l'URL de session, ne traverse pas.
        $this->assertStringNotContainsString('claude.ai', $payload);
        $this->assertStringNotContainsString('Co-Authored-By', $payload);
    }

    public function test_the_footer_never_exposes_the_repository_url(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('data-open-source-trigger', $html);
        $this->assertStringContainsString('/open-source/github', $html);
        $this->assertStringNotContainsString('cslucki', $html);
        $this->assertStringNotContainsString('github.com/cslucki', $html);
    }

    /* ================================================================== */
    /* La sortie                                                           */
    /* ================================================================== */

    public function test_the_exit_redirects_to_the_public_repository(): void
    {
        $this->get('/open-source/github')
            ->assertRedirect('https://github.com/cslucki/entraide');
    }

    public function test_the_exit_redirects_to_the_contributing_guide(): void
    {
        $this->get('/open-source/github?to=contributing')
            ->assertRedirect('https://github.com/cslucki/entraide/blob/main/CONTRIBUTING.md');
    }

    public function test_the_exit_is_not_an_open_redirect(): void
    {
        // La destination vient de l'URL : une liste fermee est la seule
        // chose qui empeche `/open-source/github?to=…` de devenir un
        // redirecteur ouvert.
        $this->get('/open-source/github?to=https://evil.example.com')
            ->assertRedirect('https://github.com/cslucki/entraide');
    }
}
