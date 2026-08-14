<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Aucun gitlink dans le depot (TASK-1139).
 *
 * Un worktree d'agent avait ete enregistre par accident en mode `160000` sous
 * `.claude/worktrees/` — un sous-module **sans URL**, le depot n'ayant aucun
 * `.gitmodules`. Un `git clone` frais rencontrait donc une entree qu'il ne
 * pouvait pas resoudre.
 *
 * La garde tient a une observation simple : **BouclePro n'a aucun sous-module**.
 * Tant que c'est vrai, tout mode `160000` est une erreur, et le dire ici coute
 * une commande. Le jour ou un vrai sous-module arrivera, il viendra avec son
 * `.gitmodules` — et ce test le dira, plutot que de laisser passer les deux cas.
 */
class TASK1139GitlinkGuardTest extends TestCase
{
    /** Les entrees de l'index, ou null si git n'est pas exploitable ici. */
    private function entreesIndexees(): ?string
    {
        if (! is_dir(base_path('.git'))) {
            return null;
        }

        $sortie = @shell_exec('git -C '.escapeshellarg(base_path()).' ls-files --stage 2>/dev/null');

        return is_string($sortie) && $sortie !== '' ? $sortie : null;
    }

    public function test_the_repository_declares_no_gitlink(): void
    {
        $index = $this->entreesIndexees();

        if ($index === null) {
            $this->markTestSkipped('Index git indisponible (archive ou export sans .git).');
        }

        $gitlinks = [];
        foreach (explode("\n", trim($index)) as $ligne) {
            if (str_starts_with($ligne, '160000 ')) {
                // Format : "<mode> <sha> <stage>\t<chemin>"
                $gitlinks[] = explode("\t", $ligne, 2)[1] ?? $ligne;
            }
        }

        $this->assertSame([], $gitlinks,
            "Entree(s) en mode 160000 dans l'index. BouclePro n'a aucun sous-module : ".
            "retirer l'entree avec `git rm --cached <chemin>`.");
    }

    public function test_no_submodule_file_appeared_without_notice(): void
    {
        // Le pendant du test precedent : un `.gitmodules` qui apparait sans que
        // personne l'ait decide est le meme accident, vu de l'autre cote.
        $this->assertFileDoesNotExist(base_path('.gitmodules'),
            'Un .gitmodules est apparu : si un sous-module est vraiment voulu, mettre a jour cette garde.');
    }

    public function test_the_agent_worktrees_directory_is_ignored(): void
    {
        // La cause de fond : sans regle d'exclusion, `git add .` reprend un
        // worktree d'agent. Le correctif de l'index seul ne protege de rien.
        $this->assertStringContainsString('.claude/worktrees/',
            file_get_contents(base_path('.gitignore')),
            'Regle .gitignore manquante : .claude/worktrees/ peut etre recommite.');
    }
}
