<?php

namespace Tests\Feature\Knowledge;

use App\Models\AdminAiPrompt;
use App\Services\Knowledge\LoopConversationKnowledgeDeriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1535 — la retrouvabilite d'une reference indirecte se joue au WRITE,
 * pas au READ.
 *
 * ## Pourquoi il n'y a PAS de resolveur d'alias dans ce depot
 *
 * Le mandat demande qu'une formulation naturelle — « le projet dont Alice
 * parlait » — retrouve la bonne connaissance, et interdit dans le meme
 * mouvement de construire un Entity Resolver general. Ces deux exigences ne
 * s'opposent que si l'on cherche la solution du cote READ.
 *
 * Elle est du cote WRITE. Une conversation humaine est pleine de « ce
 * projet », « la reunion », « ils » : ces mots ne retrouvent rien, parce
 * qu'ils ne designent rien hors de leur fil. Le prompt de derivation impose
 * donc a la note de **se tenir seule** — reprendre les noms tels qu'ils ont
 * ete dits, conserver chiffres et dates. Une note ecrite ainsi est retrouvable
 * par ses propres termes, et aucun resolveur n'est necessaire.
 *
 * Cette regle est donc une piece d'ARCHITECTURE, pas une preference de style :
 * la retirer degraderait la retrouvabilite en silence, sans faire rougir le
 * moindre test de plomberie. D'ou ce test.
 *
 * Ce qu'il ne prouve pas, et qu'aucun test ne peut prouver sans appeler un
 * vrai modele : que le modele OBEIT a cette regle. Cela se mesure au banc, sur
 * du contenu reel. Ce qui se mesure ici, c'est que la regle est bien POSEE.
 */
class TASK1535RetrievabilityContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_prompt_impose_a_la_note_de_se_tenir_seule(): void
    {
        $prompt = AdminAiPrompt::query()
            ->where('scenario_id', LoopConversationKnowledgeDeriver::FEATURE)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->firstOrFail();

        $texte = (string) $prompt->prompt_text;

        // La note sera lue par quelqu'un qui n'etait pas dans la conversation.
        $this->assertStringContainsString('se tenir SEUL', $texte,
            'chaque enonce doit pouvoir etre lu hors de son fil');

        // LA regle qui remplace un resolveur d'alias.
        $this->assertStringContainsString('nomme les sujets explicitement', $texte);

        foreach (['ce projet', 'la réunion', 'ils'] as $deictique) {
            $this->assertStringContainsString($deictique, $texte,
                "le prompt doit nommer « {$deictique} » comme formulation a proscrire");
        }

        // Ce qui rend une note verifiable par son lecteur.
        $this->assertStringContainsString('conserve les chiffres, les dates et les noms', $texte);
    }

    public function test_aucun_resolveur_d_entites_n_a_ete_introduit(): void
    {
        // Le mandat l'interdit explicitement. Un grep vaut mieux qu'une
        // intention : c'est le genre de piece qu'on ajoute « juste pour ce
        // cas » et qui devient un second moteur six mois plus tard.
        $interdits = glob(base_path('app/**/*Resolver*.php'), GLOB_BRACE) ?: [];
        $interdits = array_merge($interdits, glob(base_path('app/*/*/*Resolver*.php')) ?: []);

        $suspects = array_values(array_filter(
            array_map('basename', $interdits),
            static fn (string $f): bool => str_contains($f, 'Entity') || str_contains($f, 'Temporal') || str_contains($f, 'Alias'),
        ));

        $this->assertSame([], $suspects,
            'la retrouvabilite se joue au WRITE ; un resolveur ici serait le debut d un second moteur');
    }
}
