<?php

namespace App\Ai\Context;

use App\Ai\ContexteIa;
use App\Models\Organization;
use App\Models\User;
use App\Support\Ai\ProductSurfaceManifest;

/**
 * TASK-1370 — les surfaces BouclePro qui existent pour CE lecteur, en contexte
 * GOUVERNE.
 *
 * ## Pourquoi ici, et pas dans la question de l'utilisateur
 *
 * Premiere tentative : poser une ligne d'autorite dans
 * `AiShellResponder::situated()`. **Neuf tests de cinq TASKs sont tombes** —
 * T1315, T1346, T1350, T1358, T1359 asserent tous, par `assertSame`, que le
 * prompt utilisateur est OCTET-EXACT. Ce n'etait pas un accident de coverage :
 * la garde de langue de T1358 avait ete posee `ONLY_IF_DIFFERENT` exactement
 * pour preserver cet invariant.
 *
 * MASTER GLOBAL a tranche, et le diagnostic etait un etage trop haut : **le
 * texte de l'utilisateur reste le texte de l'utilisateur.** Une autorite
 * produit appartient au contexte borne — le meme canal que
 * `--- CATEGORIES AUTORISÉES ---` et `--- BOUCLES AUTORISÉES ---`, deja
 * gouverne par `allowedSources` et deja borne en caracteres.
 *
 * Consequence mesurable : `AiInteraction::prompt` ne bouge pas d'un octet, et
 * les neuf invariants restent verts SANS etre assouplis.
 *
 * ## Le vide est une DONNEE, pas une absence
 *
 * `ContextBuilder` ecarte les fragments vides. Cette source n'en produit donc
 * jamais : quand aucune surface n'est ouverte, elle emet le bloc avec une
 * mention explicite. C'est le cas qui compte le plus — « rien a affirmer » est
 * une information ; l'absence de bloc n'en est pas une, et laisserait le modele
 * exactement dans l'etat qui a produit l'incident des reglages de notifications
 * inexistants.
 *
 * ## Ce qui sort d'ici
 *
 * Des libelles, et rien d'autre. **Aucune URL, aucun chemin, aucun nom de
 * route, aucun identifiant** — contrairement aux Boucles et aux categories, qui
 * fournissent des id parce que le modele doit en RECOPIER un. Ici il n'a rien a
 * recopier : il ecrit de la prose. Emmener quelqu'un quelque part reste le
 * domaine d'`AiFabContext`, qui construit ses adresses cote serveur.
 */
class ProductSurfacesSource implements ContextSource
{
    public const NAME = 'product.surfaces';

    public function __construct(private readonly ProductSurfaceManifest $manifest) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function collect(ContexteIa $contexte, int $charBudget): SourceFragment
    {
        if ($contexte->userId === null) {
            throw new SourceDenied(self::NAME, SourceDenied::REASON_NO_USER_IN_CONTEXT);
        }

        $organization = Organization::query()->find($contexte->organizationId);
        $user = User::query()->find($contexte->userId);

        if ($organization === null || $user === null) {
            throw new SourceDenied(self::NAME, SourceDenied::REASON_NO_USER_IN_CONTEXT);
        }

        $surfaces = $this->manifest->forViewer($organization, $user);

        $lines = [];
        $provenance = [];
        $length = 0;

        foreach ($surfaces as $surface) {
            $line = '- '.$surface['label'];

            if ($length > 0 && $length + mb_strlen($line) + 1 > $charBudget) {
                break;
            }

            $lines[] = $line;
            $provenance[] = [
                'source' => self::NAME,
                'id' => (string) $surface['key'],
                'type' => 'direct',
                'extrait' => (string) $surface['label'],
            ];

            $length += mb_strlen($line) + 1;
        }

        // Le bloc est emis MEME vide : voir l'en-tete de cette classe.
        $corps = $lines === []
            ? __('ai.surfaces_context_none')
            : implode("\n", $lines);

        return new SourceFragment(
            "--- SURFACES BOUCLEPRO DISPONIBLES POUR CE MEMBRE ---\n"
                .$corps
                ."\n--- FIN DES SURFACES ---",
            $provenance,
        );
    }
}
