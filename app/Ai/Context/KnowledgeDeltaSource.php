<?php

namespace App\Ai\Context;

use App\Ai\ContexteIa;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\Knowledge\LoopClaimDelta;

/**
 * Source RAG `knowledge.delta` (TASK-1543).
 *
 * ## Pourquoi une troisieme source, et pas un meilleur ranking
 *
 * « Qu'est-ce qui a change depuis mardi ? » n'a pas de reponse par similarite :
 * le plus proche voisin vectoriel de « change » n'est pas un changement, c'est
 * un paragraphe qui PARLE de changement. `dossier.retrieval` reste donc
 * structurellement incapable d'y repondre — ce n'est pas un defaut de
 * classement, c'est la question qui ne se pose pas en similarite de contenu.
 *
 * C'est exactement l'argument de `dossier.manifest` (T1307) pour les questions
 * d'inventaire, et cette source suit le meme patron : aucune recherche, une
 * lecture deterministe, un espace de citation propre.
 *
 * ## Trois espaces de reference, et pourquoi ils ne se melangent pas
 *
 *  - `[Mn]` — EXISTENCE : cet element fait partie de la Boucle ;
 *  - `[Sn]` — CONTENU : ce document dit que… ;
 *  - `[Hn]` — HISTOIRE : cet enonce a ete ajoute, corrige ou retire, et voici
 *    ce qu'il disait avant.
 *
 * Confondre le troisieme avec le deuxieme serait laisser croire qu'une
 * affirmation historique sort d'un document. Elle sort d'une chaine de
 * versions, dont les deux extremites portent leurs propres preuves.
 *
 * ## Cout
 *
 * Zero embedding, zero appel provider : deux requetes SQL bornees. Une
 * question qui n'est pas une question de changement ne produit rien du tout,
 * et ne coute donc meme pas ces deux requetes.
 */
final class KnowledgeDeltaSource implements ContextSource
{
    public const NAME = 'knowledge.delta';

    public function __construct(
        private readonly LoopClaimDelta $delta,
        private readonly DossierAccessScope $scope,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function collect(ContexteIa $contexte, int $charBudget): SourceFragment
    {
        if ($contexte->loopId === null || $contexte->userId === null) {
            return SourceFragment::empty();
        }

        // L'indice de forme est la SEULE autorite de declenchement. Une
        // question qui ne demande pas ce qui a change n'obtient pas d'histoire,
        // meme si l'histoire est riche — on n'elargit pas sur un doute.
        if (! TemporalQuestionShape::wantsChangeReport($contexte->query)) {
            return SourceFragment::empty();
        }

        if (! $this->scope->loopBelongsToOrganization($contexte->loopId, $contexte->organizationId)) {
            throw new SourceDenied(self::NAME, SourceDenied::REASON_LOOP_OUTSIDE_ORGANIZATION);
        }

        $user = User::query()->find($contexte->userId);

        if ($user === null || (string) $user->organization_id !== $contexte->organizationId) {
            throw new SourceDenied(self::NAME, SourceDenied::REASON_NO_USER_IN_CONTEXT);
        }

        $loop = Loop::query()
            ->where('organization_id', $contexte->organizationId)
            ->whereKey($contexte->loopId)
            ->first();

        if ($loop === null) {
            return SourceFragment::empty();
        }

        $depuis = TemporalQuestionShape::anchor($contexte->query);

        // L'ACL vit ENTIEREMENT dans le lecteur de lignage : une seconde
        // verification ici deviendrait une seconde regle, qui divergerait.
        $evenements = $this->delta->pour($contexte->organizationId, $loop, $user, $depuis);

        if ($evenements === []) {
            return SourceFragment::empty();
        }

        $locale = $this->localeDeReference($contexte->organizationId);
        $lines = [trans('ai.knowledge_delta_header', [
            'loop' => (string) $loop->name,
            'depuis' => $depuis === null
                ? trans('ai.knowledge_delta_since_always', [], $locale)
                : $depuis->locale($locale)->translatedFormat('j F Y'),
        ], $locale)];

        $provenance = [];
        $used = mb_strlen($lines[0]);

        foreach ($evenements as $evenement) {
            $ref = 'H'.(count($provenance) + 1);
            $line = "[{$ref}] ".$this->ligne($evenement, $locale);
            $projected = $used + mb_strlen($line) + 1;

            if ($projected > $charBudget) {
                break;
            }

            $lines[] = $line;
            $used = $projected;

            $provenance[] = [
                'source' => self::NAME,
                'type' => 'delta',
                'ref' => $ref,
                'id' => $evenement['claim_id'],
                'source_type' => 'knowledge_delta',
                'change_type' => $evenement['type'],
                'subject_key' => $evenement['subject_key'],
                'version' => $evenement['version'],
                'observed_at' => $evenement['quand']->toIso8601String(),
                'title' => trans('ai.knowledge_delta_source_title', [
                    'loop' => (string) $loop->name,
                    'date' => $evenement['quand']->locale($locale)->translatedFormat('j F Y'),
                ], $locale),
                // Les preuves des DEUX cotes : c'est ce qui distingue un delta
                // d'une affirmation. L'ancien etat et le nouveau renvoient
                // chacun aux messages humains qui les ont etablis.
                'evidence_before' => $evenement['preuves_ancien'],
                'evidence_after' => $evenement['preuves_nouveau'],
                'url' => DossierSourceUrl::forDerivedNote((string) $loop->id),
            ];
        }

        if ($provenance === []) {
            return SourceFragment::empty();
        }

        return new SourceFragment(implode("\n", $lines), $provenance);
    }

    /**
     * Une ligne d'histoire, lisible sans connaitre le modele de donnees.
     *
     * @param  array<string, mixed>  $e
     */
    private function ligne(array $e, string $locale): string
    {
        $date = $e['quand']->locale($locale)->translatedFormat('j F Y');

        return match ($e['type']) {
            LoopClaimDelta::ADDED => trans('ai.knowledge_delta_added',
                ['date' => $date, 'nouveau' => $e['nouveau']], $locale),
            LoopClaimDelta::UPDATED => trans('ai.knowledge_delta_updated',
                ['date' => $date, 'ancien' => $e['ancien'] ?? '—', 'nouveau' => $e['nouveau']], $locale),
            default => trans('ai.knowledge_delta_retracted', [
                'date' => $date,
                'ancien' => $e['ancien'],
                'raison' => $e['raison'] ?? trans('ai.knowledge_delta_no_reason', [], $locale),
            ], $locale),
        };
    }

    /**
     * TASK-1402 : la langue des libelles SYSTEME est celle de l'Organization,
     * jamais celle du lecteur — le modele doit recevoir un contexte d'une
     * seule langue.
     */
    private function localeDeReference(string $organizationId): string
    {
        $locale = Organization::query()->whereKey($organizationId)->value('locale');

        return in_array($locale, ['fr', 'en'], true) ? (string) $locale : 'fr';
    }
}
