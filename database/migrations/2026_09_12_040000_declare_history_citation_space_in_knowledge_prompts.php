<?php

use App\Models\AdminAiPrompt;
use Illuminate\Database\Migrations\Migration;

/**
 * TASK-1543 — declarer le troisieme espace de citation, [Hn].
 *
 * ## Pourquoi cette retouche est livree, quand trois autres ne l'ont pas ete
 *
 * Elle ne reformule rien. Elle DECLARE une famille de sources qui existe
 * desormais dans le contexte et dont le prompt ne disait rien : il annoncait
 * « deux familles », le contexte en porte trois.
 *
 * Et elle a ete mesuree par repetition, K=4 par question et par variante, sur
 * le vrai modele du tenant de validation :
 *
 *     temoin     cite [Hn] 4/8 | ancien 8/8 | nouveau 8/8 | retrait 8/8
 *     candidate  cite [Hn] 8/8 | ancien 8/8 | nouveau 8/8 | retrait 8/8
 *
 * Le taux de citation passe de la moitie a la totalite, aucun critere de
 * contenu ne bouge, et aucune echeance de remplacement n'est inventee dans
 * l'une ou l'autre variante. C'est exactement le protocole qui a fait RETIRER
 * les retouches de T1537 (81 % → 73 %) et de T1541 (aucun deplacement) : la
 * difference n'est pas la confiance, c'est la mesure.
 *
 * ## Pourquoi une nouvelle version, et pas une edition sur place
 *
 * Le registre des prompts est versionne et `activeInstructions()` lit la
 * version active la plus haute. Une edition sur place effacerait la formulation
 * qui a servi de temoin, et il n'y aurait plus rien a comparer si la mesure
 * devait etre rejouee.
 *
 * ## Pourquoi la migration LEVE au lieu de passer
 *
 * L'insertion se fait sur des reperes exacts, accents compris. Une migration de
 * prompt qui ne trouve pas son repere et se contente de ne rien faire est un
 * no-op silencieux : le deploiement passe au vert, la consigne n'est jamais
 * posee, et la mesure suivante conclut a tort que la retouche ne sert a rien.
 * C'est arrive en T1537, avec « preambule » contre « préambule ».
 */
return new class extends Migration
{
    private const ANCRE_FAMILLES = '- les SOURCES DOCUMENTAIRES (références [S1], [S2], ...) : des extraits réels du contenu de certains de ces documents.';

    private const ANCRE_REGLE = "- Pour affirmer ce qu'un document dit ou contient, cite sa référence [Sn]";

    private const ANCRE_HYBRIDE = 'les SOURCES DOCUMENTAIRES ([S1], [S2], ...) qui sont des extraits réels du contenu de ces documents.';

    public function up(): void
    {
        $this->versionner('loop_knowledge_answer', function (string $texte): string {
            $famille = "\n- l'HISTORIQUE DE LA MÉMOIRE (références [H1], [H2], ...) : ce qui a été "
                .'ajouté, corrigé ou retiré dans ce que la Boucle a appris, avec la date à laquelle '
                ."le propos a été tenu. Une référence [Hn] atteste d'un CHANGEMENT, jamais du contenu d'un document.";

            $regle = "- Pour dire qu'un fait a été ajouté, corrigé ou retiré, cite sa référence [Hn] "
                ."juste après l'affirmation. Quand un [Hn] porte un état précédent, dis les DEUX : ce qui "
                .'était dit avant, et ce qui est vrai maintenant. Si un fait a été retiré sans remplacement, '
                ."dis-le ainsi — n'invente jamais la valeur qui manque.\n";

            $texte = $this->remplacerUneFois($texte, self::ANCRE_FAMILLES, self::ANCRE_FAMILLES.$famille);

            return $this->remplacerUneFois($texte, self::ANCRE_REGLE, $regle.self::ANCRE_REGLE);
        });

        // Le mode « IA + Dossiers » declare les memes sources autorisees : sans
        // cette declaration, il recevrait le bloc d'histoire sans savoir quoi
        // en faire, et pourrait le presenter comme une connaissance generale —
        // exactement la confusion que ce prompt existe pour empecher.
        $this->versionner('loop_hybrid_answer', function (string $texte): string {
            $ajout = self::ANCRE_HYBRIDE
                ." S'y ajoute, quand la question porte sur ce qui a changé, l'HISTORIQUE DE LA MÉMOIRE "
                .'([H1], [H2], ...) : un fait ajouté, corrigé ou retiré, daté du jour où le propos a été tenu. '
                ."Un [Hn] atteste d'un changement, jamais du contenu d'un document, et une correction se cite "
                ."en disant les DEUX états — celui d'avant et celui de maintenant.";

            return $this->remplacerUneFois($texte, self::ANCRE_HYBRIDE, $ajout);
        });
    }

    public function down(): void
    {
        foreach (['loop_knowledge_answer', 'loop_hybrid_answer'] as $scenario) {
            AdminAiPrompt::where('scenario_id', $scenario)
                ->where('name', 'like', '%[Hn]%')
                ->delete();
        }
    }

    private function versionner(string $scenario, callable $transformer): void
    {
        $actif = AdminAiPrompt::where('scenario_id', $scenario)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        if ($actif === null) {
            // Rien a versionner : cet environnement n'a pas encore ce prompt.
            // Ce n'est pas une anomalie — le seed le posera dans sa forme
            // d'origine, et cette migration aura simplement ete jouee avant.
            return;
        }

        $texte = $transformer((string) $actif->prompt_text);

        if ($texte === (string) $actif->prompt_text) {
            return;
        }

        $nouveau = $actif->replicate();
        $nouveau->version = ((int) $actif->version) + 1;
        $nouveau->name = (string) $actif->name.' — espace de citation [Hn]';
        $nouveau->prompt_text = $texte;
        $nouveau->is_active = true;
        $nouveau->save();
    }

    private function remplacerUneFois(string $texte, string $ancre, string $remplacement): string
    {
        if (! str_contains($texte, $ancre)) {
            throw new RuntimeException(
                'TASK-1543 : ancre introuvable dans le prompt — « '.mb_substr($ancre, 0, 48).'… ». '
                .'La consigne serait posee au hasard, ou pas du tout.',
            );
        }

        $position = mb_strpos($texte, $ancre);

        return mb_substr($texte, 0, $position).$remplacement
            .mb_substr($texte, $position + mb_strlen($ancre));
    }
};
