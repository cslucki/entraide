<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * TASK-1577 / CDC-01 V0-J — test d'architecture : tout chemin qui atteint un
 * provider generatif sur les surfaces P0 ecrit un bloc `turn`.
 *
 * Sur le modele d'`AiEconomicAuthorityIsolationTest` : une lecture statique
 * de `app/`, sans base ni conteneur. Un writer P0 est un fichier qui cree une
 * `AiInteraction` pour un tour utilisateur ; il doit composer le bloc `turn`
 * (`AiTurnTrace::compose(`) — le mode explain n'a que cela pour expliquer un
 * tour. Le test rougit si un writer P0 l'omet, ou si un NOUVEAU fichier se met
 * a ecrire des `AiInteraction` sans etre classe (P0 → doit composer ; hors P0
 * → doit etre ajoute a la liste ci-dessous, avec sa raison).
 */
class AiTurnWritersWriteATurnTest extends TestCase
{
    /**
     * Les writers P0 (CDC-01 §2.2, C7-bis) : LoopChat (3 modes + herites), les
     * pages Dossier, le Shell (general, dossier, article, continuation,
     * discovery, clarify).
     *
     * @var list<string>
     */
    private const WRITERS_P0 = [
        'app/Services/Ai/LoopKnowledgeAnswerService.php',
        'app/Services/ChatLoop/ChatLoopAiService.php',
        'app/Services/Dossiers/DossierInsightsService.php',
        'app/Services/Ai/ShellGeneralAnswerService.php',
        'app/Services/Ai/ClarifyUserHelpRequestService.php',
    ];

    /**
     * Fichiers qui creent des `AiInteraction` SANS etre des tours P0 — chacun
     * avec la raison qui le sort du perimetre. Un nouvel entrant doit etre
     * classe ici ou dans WRITERS_P0, jamais laisse implicite.
     *
     * @var array<string, string>
     */
    private const HORS_P0 = [
        'app/Services/Ai/OrganizationDoctrineSandbox.php' => 'bac a sable admin, FEATURE exclue du calcul economique (C19)',
        'app/Services/BlogAiService.php' => 'redaction editoriale, hors surfaces P0 (§2.2)',
        'app/Http/Controllers/BlogExplorerController.php' => 'explorateur de blog, hors P0',
        'app/Http/Controllers/Admin/AdminMemberAiProfileController.php' => 'admin profils IA, hors P0',
        'app/Livewire/BoundedMemberAgent.php' => 'agent membre (MemberProfileAgentResponder), hors P0',
        'app/Livewire/InlineMemberAgent.php' => 'agent membre inline, hors P0',
        'app/Services/Ai/Persistence/AdminAiInteractionPersistence.php' => 'persistance admin generique, pas un tour',
        'app/Listeners/RecordSdkEmbeddingsInvocation.php' => 'ledger des embeddings, pas un tour',
    ];

    public function test_every_p0_writer_composes_a_turn_block(): void
    {
        $root = dirname(__DIR__, 3);

        foreach (self::WRITERS_P0 as $relative) {
            $source = (string) file_get_contents($root.'/'.$relative);

            $this->assertStringContainsString('AiInteraction::create(', $source, "{$relative} n'ecrit plus d'AiInteraction : la liste P0 est perimee");
            $this->assertStringContainsString('AiTurnTrace::compose(', $source, "{$relative} ecrit des AiInteraction sans composer de bloc `turn`");
            $this->assertStringContainsString('AiTurnTrace::TURN_METADATA_KEY', $source, "{$relative} compose un tour mais ne le persiste pas sous la cle canonique");
        }
    }

    public function test_every_ai_interaction_writer_is_classified(): void
    {
        $root = dirname(__DIR__, 3);
        $writers = [];

        foreach ($this->phpFiles($root.'/app') as $path) {
            $source = (string) file_get_contents($path);

            if (str_contains($source, 'AiInteraction::create(') || str_contains($source, 'new AiInteraction(')) {
                $writers[] = 'app/'.str_replace($root.'/app/', '', $path);
            }
        }

        sort($writers);
        $classes = [...self::WRITERS_P0, ...array_keys(self::HORS_P0)];
        sort($classes);

        $this->assertSame([], array_diff($writers, $classes), 'un fichier ecrit des AiInteraction sans etre classe P0 / hors P0');
        $this->assertSame([], array_diff($classes, $writers), 'un fichier classe n\'ecrit plus d\'AiInteraction : la liste est perimee');
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
