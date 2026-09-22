<?php

namespace App\Services\Loops;

use App\Models\Loop;
use App\Models\LoopAiAssistant;
use App\Models\User;
use App\Support\Loops\LoopPluginRegistry;

/**
 * Les trois assistants d'une Boucle : Aperio, Traverse, Limen. (TASK-1616)
 *
 * Le CATALOGUE dit qui ils sont et quelle est leur posture par defaut. Ce
 * service dit ce que CETTE Boucle en a fait. Les noms sont FIXES en V0 : ce
 * n'est pas une contrainte de base, c'est la Product Spec, et c'est ici
 * qu'elle est tenue — aucune methode n'accepte une cle hors catalogue, ni
 * n'en cree une.
 *
 * ECART, pas copie. Une ligne existe seulement la ou la Boucle s'est ecartee
 * du defaut. Vider une instruction SUPPRIME l'ecart au lieu de recopier le
 * defaut en base : sinon la Boucle figerait la posture au premier
 * enregistrement et cesserait de suivre le produit. Meme regle que
 * `loop_type_settings`.
 *
 * Ces lignes ne sont JAMAIS supprimees par une extinction — ni celle du
 * plugin dans la Boucle, ni celle de la disponibilite Organization. Rallumer
 * rend ses postures, il ne les redemande pas.
 */
class LoopAiAssistants
{
    /** La cle du plugin dont ce service regle les assistants. */
    public const PLUGIN = 'multi_ai_assistants';

    public function __construct(private LoopPluginRegistry $plugins) {}

    /**
     * Les trois assistants du catalogue, dans l'ordre canonique.
     *
     * @return array<string, array<string, mixed>>
     */
    public function catalogue(): array
    {
        $definition = $this->plugins->definition(self::PLUGIN);
        $assistants = $definition['assistants'] ?? [];

        if (! is_array($assistants)) {
            return [];
        }

        uasort($assistants, static fn (array $a, array $b): int => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        return $assistants;
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->catalogue());
    }

    public function exists(?string $key): bool
    {
        return is_string($key) && $key !== '' && array_key_exists($key, $this->catalogue());
    }

    /**
     * La posture PAR DEFAUT d'un assistant — celle du catalogue.
     *
     * Repli sur la chaine vide plutot que sur la cle de traduction : un defaut
     * manquant est un oubli de langue, et afficher « loops.plugins... » a un
     * humain serait pire que de n'afficher rien.
     */
    public function defaultInstruction(string $key): string
    {
        $definition = $this->catalogue()[$key] ?? null;

        if ($definition === null) {
            return '';
        }

        if (isset($definition['instruction_key'])) {
            $traduit = __($definition['instruction_key']);

            if ($traduit !== $definition['instruction_key']) {
                return (string) $traduit;
            }
        }

        return (string) ($definition['instruction'] ?? '');
    }

    public function label(string $key): string
    {
        return (string) ($this->catalogue()[$key]['label'] ?? $key);
    }

    /**
     * Les trois assistants tels que CETTE Boucle les a regles.
     *
     * Chaque entree porte la posture EN VIGUEUR et, separement, celle que la
     * Boucle a elle-meme ecrite. L'ecran a besoin des deux : le champ affiche
     * l'ecart, le texte en dessous ce dont on heriterait en le vidant. Les
     * confondre ferait recopier le defaut dans l'ecart au premier
     * enregistrement.
     *
     * @return array<int, array<string, mixed>>
     */
    /**
     * Les assistants qu'un ecran doit PROPOSER. (TASK-1621)
     *
     * Distinct de `catalogue()`, qui reste COMPLET : `label()`, `exists()` et
     * la lecture des bulles deja publiees ont besoin de connaitre un role
     * dormant. Ce qu'on ne veut plus, c'est le proposer au reglage.
     *
     * @return array<string, array<string, mixed>>
     */
    public function catalogueVivant(): array
    {
        return array_filter(
            $this->catalogue(),
            static fn (array $definition): bool => ($definition['dormant'] ?? false) !== true,
        );
    }

    public function describeFor(Loop $loop): array
    {
        $lignes = LoopAiAssistant::query()
            ->where('loop_id', $loop->id)
            ->with('updatedBy:id,name')
            ->get()
            ->keyBy('key');

        $sortie = [];

        foreach ($this->catalogueVivant() as $key => $definition) {
            $ligne = $lignes[$key] ?? null;
            $defaut = $this->defaultInstruction($key);

            $sortie[] = [
                'key' => $key,
                'label' => $this->label($key),
                'default_instruction' => $defaut,
                // Ce que la Boucle a elle-meme ecrit — `null` si elle suit le
                // catalogue.
                'own_instruction' => $ligne?->instruction,
                // Ce qui s'applique reellement.
                'instruction' => $ligne?->instruction ?? $defaut,
                // Actif tant que personne ne l'a eteint.
                'enabled' => $ligne === null ? true : (bool) $ligne->enabled,
                'order' => $ligne?->order ?? ($definition['order'] ?? 0),
                'customised' => $ligne?->instruction !== null,
                'decision' => $ligne,
            ];
        }

        usort($sortie, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return $sortie;
    }

    /**
     * Enregistrer les trois assistants d'un coup.
     *
     * Les cles hors catalogue sont IGNOREES, pas rejetees : un formulaire
     * forge ne doit pas pouvoir creer un quatrieme assistant, et il ne doit
     * pas non plus faire echouer l'enregistrement des trois vrais.
     *
     * @param  array<string, array{instruction?: ?string, enabled?: bool}>  $reglages
     */
    public function save(Loop $loop, array $reglages, ?User $actor = null): void
    {
        foreach ($reglages as $key => $valeurs) {
            if (! $this->exists($key)) {
                continue;
            }

            $instruction = isset($valeurs['instruction']) ? trim((string) $valeurs['instruction']) : '';
            $enabled = (bool) ($valeurs['enabled'] ?? false);

            // Une posture identique au defaut n'est PAS un ecart : la stocker
            // figerait la Boucle sur le texte d'aujourd'hui.
            if ($instruction === '' || $instruction === trim($this->defaultInstruction($key))) {
                $instruction = null;
            }

            // Rien a dire : ni posture propre, ni extinction. On efface
            // l'ecart plutot que d'ecrire une ligne qui ne signifie rien.
            if ($instruction === null && $enabled) {
                LoopAiAssistant::query()
                    ->where('loop_id', $loop->id)
                    ->where('key', $key)
                    ->delete();

                continue;
            }

            LoopAiAssistant::query()->updateOrCreate(
                [
                    'loop_id' => $loop->id,
                    'key' => $key,
                ],
                [
                    'organization_id' => $loop->organization_id,
                    'instruction' => $instruction,
                    'enabled' => $enabled,
                    'updated_by' => $actor?->id,
                ],
            );
        }
    }
}
