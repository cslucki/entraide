<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Allocation des identifiants TASK par `ai/scripts/create-task.sh`.
 *
 * ## Le defaut repare
 *
 * L'ancienne regle etait `max(TODO/) + 1`. `TODO/` est purge de ses taches
 * archivees, donc le compteur retombait sur des numeros deja consommes : deux
 * TASK-1131 et deux TASK-1132 en sont nes.
 *
 * ## Ce que ces tests gardent
 *
 * Chaque test s'execute dans un depot git **temporaire**, monte de toutes
 * pieces : le script ne doit jamais dependre du depot reel, et ces tests ne
 * doivent jamais ecrire dedans. C'est aussi ce qui rend l'allocation
 * observable — on choisit exactement ce que chaque source contient.
 *
 * Les quatre sources d'autorite sont eprouvees une par une, parce qu'aucune
 * n'est suffisante seule :
 * `TODO/`, les refs git, les sujets de merge, les fichiers versionnes.
 */
#[Group('scripts')]
class TASK1132TaskIdAllocationTest extends TestCase
{
    private string $depot = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_executable('/usr/bin/git') && ! shell_exec('command -v git')) {
            $this->markTestSkipped('git absent.');
        }

        // `ai/` est prive : le lockdown public le retire du depot (AGENTS.md,
        // « Internal docs, agent workflows, tests, TASK history, dumps,
        // reports and operational tooling are local/private »). Ce test garde
        // donc l'outil **en local**, la ou il est utilise, et se retire
        // proprement partout ou le script n'existe pas — plutot que de rougir
        // une CI qui n'a aucun moyen de le voir.
        if (! is_file(base_path('ai/scripts/create-task.sh'))) {
            $this->markTestSkipped('ai/scripts/create-task.sh absent (outillage prive, hors depot public).');
        }

        $this->depot = sys_get_temp_dir().'/task-alloc-'.bin2hex(random_bytes(6));
        mkdir($this->depot.'/TODO', 0777, true);
        mkdir($this->depot.'/ai/scripts', 0777, true);
        mkdir($this->depot.'/ai/tasks/templates', 0777, true);

        copy(base_path('ai/scripts/create-task.sh'), $this->depot.'/ai/scripts/create-task.sh');
        chmod($this->depot.'/ai/scripts/create-task.sh', 0755);
        file_put_contents($this->depot.'/ai/tasks/templates/TASK_TEMPLATE.md', <<<'MD'
            ---
            task_id: TASK-050
            title: Example Task
            status: TODO
            owner: null
            branch: null
            created_at: null
            updated_at: null
            lock:
              status: UNLOCKED
              agent: null
              since: null
            ---

            # Objective
            MD);

        $this->git('init -q -b develop');
        $this->git('config user.email agent@test.local');
        $this->git('config user.name Agent');
        file_put_contents($this->depot.'/README.md', "depot d essai\n");
        $this->git('add -A');
        $this->git('commit -q -m "socle"');
    }

    protected function tearDown(): void
    {
        // `setUp()` peut avoir saute avant de creer le depot (git absent, ou
        // outillage prive hors du depot public) : ne pas supposer qu'il existe.
        if ($this->depot !== '' && is_dir($this->depot)) {
            exec('rm -rf '.escapeshellarg($this->depot));
        }

        parent::tearDown();
    }

    private function git(string $args): string
    {
        return (string) shell_exec('git -C '.escapeshellarg($this->depot).' '.$args.' 2>&1');
    }

    /** Lance le script et rend [code de sortie, sortie complete]. */
    private function creer(string $titre, string $options = ''): array
    {
        $sortie = [];
        $code = 0;
        exec(
            'CREATE_TASK_BASE_DIR='.escapeshellarg($this->depot)
            .' bash '.escapeshellarg($this->depot.'/ai/scripts/create-task.sh')
            .' '.escapeshellarg($titre).' CLAUDE '.$options.' 2>&1',
            $sortie,
            $code
        );

        return [$code, implode("\n", $sortie)];
    }

    private function identifiantAlloue(string $sortie): ?string
    {
        preg_match('/Task ID: (TASK-\d+)/', $sortie, $m);

        return $m[1] ?? null;
    }

    /** Poser une trace dans une source d'autorite donnee, et une seule. */
    private function tracer(string $source, int $numero): void
    {
        match ($source) {
            'todo' => touch($this->depot."/TODO/TASK-{$numero}-tache-archivable.md"),
            'ref' => $this->git("branch TASK-{$numero}-une-branche"),
            'merge' => $this->fusionnerPuisSupprimer($numero),
            'fichier' => $this->versionner("tests/Feature/TASK{$numero}QuelqueChoseTest.php"),
        };
    }

    /**
     * Le cas que la source « merges » existe pour rattraper : une tache mergee
     * dont la branche a ensuite ete supprimee. Plus aucune ref ne la porte —
     * seul le sujet du commit de merge en garde la trace.
     */
    private function fusionnerPuisSupprimer(int $numero): void
    {
        $this->git("checkout -q -b TASK-{$numero}-une-tache");
        file_put_contents($this->depot."/tache-{$numero}.txt", "contenu\n");
        $this->git('add -A');
        $this->git('commit -q -m "travail"');
        $this->git('checkout -q develop');
        $this->git("merge --no-ff -q -m \"Merge branch 'TASK-{$numero}-une-tache' into develop\" TASK-{$numero}-une-tache");
        $this->git("branch -D TASK-{$numero}-une-tache");
    }

    private function versionner(string $chemin): void
    {
        @mkdir(dirname($this->depot.'/'.$chemin), 0777, true);
        file_put_contents($this->depot.'/'.$chemin, "<?php\n");
        $this->git('add -A');
        $this->git('commit -q -m "ajout"');
    }

    // ── L'enchainement nominal ───────────────────────────────────────────────

    public function test_a_product_task_after_1131_gets_1132(): void
    {
        $this->tracer('ref', 1131);

        [$code, $sortie] = $this->creer('Fiabiliser quelque chose');

        $this->assertSame(0, $code, $sortie);
        $this->assertSame('TASK-1132', $this->identifiantAlloue($sortie));
    }

    public function test_the_next_product_task_gets_1133(): void
    {
        $this->tracer('ref', 1131);
        $this->creer('Premiere tache');

        [$code, $sortie] = $this->creer('Seconde tache');

        $this->assertSame(0, $code, $sortie);
        $this->assertSame('TASK-1133', $this->identifiantAlloue($sortie));
    }

    public function test_a_rag_task_after_1204_gets_1205(): void
    {
        $this->tracer('ref', 1204);

        [$code, $sortie] = $this->creer('Indexer les embeddings', '--range=rag');

        $this->assertSame(0, $code, $sortie);
        $this->assertSame('TASK-1205', $this->identifiantAlloue($sortie));
    }

    public function test_an_empty_range_starts_at_its_floor(): void
    {
        [$codeP, $sortieP] = $this->creer('Premiere du produit');
        $this->assertSame(0, $codeP, $sortieP);
        $this->assertSame('TASK-1130', $this->identifiantAlloue($sortieP));

        [$codeR, $sortieR] = $this->creer('Premiere du rag', '--range=rag');
        $this->assertSame(0, $codeR, $sortieR);
        $this->assertSame('TASK-1200', $this->identifiantAlloue($sortieR));
    }

    // ── Le defaut d'origine : TODO/ purge ────────────────────────────────────

    public function test_a_purged_todo_never_goes_backwards(): void
    {
        // Le scenario exact du bug : la tache 1131 existe, mais plus aucune
        // trace dans TODO/. L'ancienne regle rendait 1131 une seconde fois.
        $this->tracer('ref', 1131);
        $this->assertSame([], glob($this->depot.'/TODO/TASK-*.md'), 'TODO/ doit etre vide.');

        [$code, $sortie] = $this->creer('Tache apres purge');

        $this->assertSame(0, $code, $sortie);
        $this->assertSame('TASK-1132', $this->identifiantAlloue($sortie));
    }

    /**
     * Chaque source, isolement, suffit a empecher un retour en arriere.
     */
    public function test_every_authority_source_is_read(): void
    {
        foreach (['todo', 'ref', 'merge', 'fichier'] as $source) {
            $this->tearDown();
            $this->setUp();
            $this->tracer($source, 1140);

            [$code, $sortie] = $this->creer('Tache temoin');

            $this->assertSame(0, $code, "source {$source} : {$sortie}");
            $this->assertSame('TASK-1141', $this->identifiantAlloue($sortie), "La source « {$source} » n'est pas lue.");
        }
    }

    // ── Fail-closed ──────────────────────────────────────────────────────────

    public function test_an_existing_branch_is_refused(): void
    {
        // En mode standard, la branche compte parmi les sources d'autorite :
        // une branche `TASK-1140-*` fait allouer 1141, il n'y a donc jamais
        // collision par un chemin sequentiel. La garde est une ceinture contre
        // une COURSE entre deux executions simultanees, et elle s'eprouve la
        // ou l'identifiant est impose : le mode --subtask.
        $this->git('branch T1140.01-t1140-01-tache-imposee');

        [$code, $sortie] = $this->creer('Tache imposee', '--subtask T1140.01');

        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString('Branch already exists', $sortie);
    }

    public function test_an_existing_task_file_is_refused(): void
    {
        touch($this->depot.'/TODO/TASK-1140-t1140-01-tache-imposee.md');

        [$code, $sortie] = $this->creer('Tache imposee', '--subtask T1140.01');

        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString('already exists', $sortie);
    }

    public function test_the_standard_mode_still_carries_both_guards(): void
    {
        // La ceinture doit exister meme si aucun scenario sequentiel ne
        // l'atteint : c'est ce qui protege deux agents lances en meme temps.
        $script = file_get_contents(base_path('ai/scripts/create-task.sh'));
        $modeStandard = substr($script, strpos($script, "# Mode standard : allocation"));

        $this->assertStringContainsString('ERROR: Task file already exists', $modeStandard);
        $this->assertStringContainsString('ERROR: Branch already exists locally', $modeStandard);
        $this->assertStringContainsString('ERROR: Branch already exists on origin', $modeStandard);
    }

    public function test_the_script_never_writes_outside_its_base_dir(): void
    {
        // Defaut trouve en ecrivant ces tests : `git checkout -b` n'etait pas
        // qualifie et creait la branche dans le repertoire COURANT. Lance
        // depuis ailleurs, le script ecrivait dans un autre depot en silence —
        // ces tests memes ont ainsi cree 14 branches parasites.
        $script = file_get_contents(base_path('ai/scripts/create-task.sh'));

        preg_match_all('/^\s*(?:if )?git (?!-C)/m', $script, $m);
        $this->assertSame([], $m[0], 'Toute commande git du script doit etre qualifiee par -C "$BASE_DIR".');
    }

    public function test_an_exhausted_range_stops_explicitly(): void
    {
        $this->tracer('ref', 1199);

        [$code, $sortie] = $this->creer('Une tache de trop');

        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString('epuisee', $sortie);
        // Et surtout : elle ne deborde pas sur la plage RAG.
        $this->assertStringNotContainsString('TASK-1200', $sortie);
    }

    public function test_an_unknown_range_is_refused(): void
    {
        [$code, $sortie] = $this->creer('Tache hors plage', '--range=zetetique');

        $this->assertSame(1, $code, $sortie);
        $this->assertStringContainsString('Plage inconnue', $sortie);
    }

    // ── Les deux plages ne se contaminent pas ────────────────────────────────

    public function test_the_two_ranges_never_influence_each_other(): void
    {
        $this->tracer('ref', 1204);

        [, $sortieProduit] = $this->creer('Cote produit');
        $this->assertSame('TASK-1130', $this->identifiantAlloue($sortieProduit), 'Le RAG ne doit pas pousser le produit.');

        $this->git('branch TASK-1150-cote-produit');

        [, $sortieRag] = $this->creer('Cote rag', '--range=rag');
        $this->assertSame('TASK-1205', $this->identifiantAlloue($sortieRag), 'Le produit ne doit pas pousser le RAG.');
    }

    public function test_the_range_can_be_declared_by_the_worktree(): void
    {
        // Le worktree RAG pose son marqueur une fois et n'y pense plus.
        file_put_contents($this->depot.'/TODO/.task-range', "rag\n");
        $this->tracer('ref', 1204);

        [$code, $sortie] = $this->creer('Tache du worktree rag');

        $this->assertSame(0, $code, $sortie);
        $this->assertSame('TASK-1205', $this->identifiantAlloue($sortie));
    }

    // ── Les taches historiques ne sont jamais touchees ───────────────────────

    public function test_legacy_tasks_are_read_but_never_modified(): void
    {
        $this->tracer('fichier', 1131);
        $empreinteAvant = md5_file($this->depot.'/tests/Feature/TASK1131QuelqueChoseTest.php');
        $journalAvant = $this->git('log --oneline');

        [$code, $sortie] = $this->creer('Tache suivante');

        $this->assertSame(0, $code, $sortie);
        $this->assertSame('TASK-1132', $this->identifiantAlloue($sortie));
        $this->assertSame($empreinteAvant, md5_file($this->depot.'/tests/Feature/TASK1131QuelqueChoseTest.php'));
        $this->assertSame($journalAvant, $this->git('log --oneline'));
    }
}
