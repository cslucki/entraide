<?php

namespace App\Services\Knowledge;

use App\Models\DerivedKnowledgeNote;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\User;
use App\Services\Dossiers\DerivedChunkEligibility;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * TASK-1543 — ce qui a change dans UNE Boucle, par LIGNAGE.
 *
 * ## HISTORY = deterministic lineage lookup
 *
 * Pas de recherche vectorielle sur les archives, et ce n'est pas une economie
 * de moyens — c'est la seule lecture correcte, pour trois raisons dont la
 * premiere suffit.
 *
 *  1. **Ce serait defaire T1541.** Deux budgets ne peuvent pas coexister dans
 *     le READ : c'est pourquoi une version remplacee perd ses vecteurs. Rendre
 *     les archives candidates reintroduirait exactement la confusion qu'on
 *     vient de mesurer et de fermer.
 *  2. **« Qu'est-ce qui a change » n'a aucun bon voisin vectoriel.** Le chunk
 *     le plus proche de « change » est un paragraphe qui PARLE de changement.
 *     C'est le constat de T1309 sur les questions panoramiques, rejoue.
 *  3. **Un delta est une relation entre deux lignes reliees par une cle.** La
 *     similarite ne la connait pas ; `superseded_by_id`, si.
 *
 * ## Le temps lu est celui des HUMAINS
 *
 * Jamais `derived_at` — l'instant ou la machine s'est reveillee n'apprend rien
 * a personne. Jamais `superseded_at` non plus, pour la meme raison : c'est
 * l'instant ou le patch a ete applique.
 *
 * Un ajout et une correction sont dates par `observed_at`, qui porte depuis
 * T1541 la date de LEUR PROPRE preuve. Une retraction n'a pas de colonne pour
 * cela : sa date se calcule depuis les messages qui l'ont justifiee
 * (`provenance.retracted_evidence`). C'est plus de travail qu'une colonne, et
 * c'est la seule valeur qui soit un temps metier.
 *
 * ## L'ACL n'a pas de variante « historique »
 *
 * L'acces a l'histoire d'une Boucle EST l'acces a la Boucle. On reutilise
 * `DerivedChunkEligibility::authorizedLoopIds()` — l'autorite unique — et on
 * ne lit rien si la Boucle n'y figure pas. Ferme par defaut : sans utilisateur,
 * aucune histoire. Un membre qui quitte la Boucle perd son histoire au tour
 * suivant, sans aucune synchronisation a rater.
 *
 * Une seconde regle d'eligibilite « pour l'historique » serait precisement le
 * defaut que T1534 a supprime en creant une autorite unique.
 */
final class LoopClaimDelta
{
    public const ADDED = 'added';

    public const UPDATED = 'updated';

    public const RETRACTED = 'retracted';

    /** Borne dure : un delta reste lisible, et son budget de contexte fini. */
    public const MAX_EVENEMENTS = 20;

    public function __construct(
        private readonly DerivedChunkEligibility $eligibility,
    ) {}

    /**
     * Les evenements de lignage de cette Boucle, du plus recent au plus ancien.
     *
     * @param  ?Carbon  $depuis  `null` = toute l'histoire connue. Jamais une
     *                           fenetre par defaut : inventer une date
     *                           repondrait a une question que personne n'a posee.
     * @return list<array{type: string, subject_key: string, claim_id: string, quand: Carbon, version: int, ancien: ?string, nouveau: ?string, raison: ?string, preuves_ancien: list<string>, preuves_nouveau: list<string>}>
     */
    public function pour(string $organizationId, Loop $loop, ?User $user, ?Carbon $depuis): array
    {
        $autorisees = $this->eligibility->authorizedLoopIds($organizationId, $user);

        if (! in_array((string) $loop->id, $autorisees, true)) {
            // Ferme par defaut. Aucune lecture, aucune fuite de l'existence
            // meme d'un changement.
            return [];
        }

        $notes = DerivedKnowledgeNote::query()
            ->where('organization_id', $organizationId)
            ->where('source_type', DerivedKnowledgeNote::SOURCE_LOOP_CONVERSATION)
            ->where('source_loop_id', $loop->id)
            ->claims()
            ->get();

        if ($notes->isEmpty()) {
            return [];
        }

        $parId = $notes->keyBy(fn (DerivedKnowledgeNote $n): string => (string) $n->id);
        $datesDesMessages = $this->datesDesMessages($notes);

        $evenements = [];

        foreach ($notes as $note) {
            $evenement = $note->isActive()
                ? $this->evenementActif($note, $notes)
                : $this->evenementArchive($note, $parId, $datesDesMessages);

            if ($evenement === null) {
                continue;
            }

            if ($depuis !== null && $evenement['quand']->lessThan($depuis)) {
                continue;
            }

            $evenements[] = $evenement;
        }

        usort($evenements, static fn (array $a, array $b): int => $b['quand'] <=> $a['quand']);

        return array_slice($evenements, 0, self::MAX_EVENEMENTS);
    }

    /**
     * Un claim ACTIF : ajout si c'est sa premiere version, correction sinon.
     *
     * @param  Collection<int, DerivedKnowledgeNote>  $notes
     * @return ?array<string, mixed>
     */
    private function evenementActif(DerivedKnowledgeNote $note, $notes): ?array
    {
        $quand = $note->observed_at;

        if ($quand === null) {
            return null;
        }

        if ((int) $note->version <= 1) {
            return [
                'type' => self::ADDED,
                'subject_key' => (string) $note->subject_key,
                'claim_id' => (string) $note->id,
                'quand' => Carbon::instance($quand),
                'version' => (int) $note->version,
                'ancien' => null,
                'nouveau' => trim((string) $note->content),
                'raison' => null,
                'preuves_ancien' => [],
                'preuves_nouveau' => $this->preuves($note),
            ];
        }

        // Le predecesseur se trouve par la CHAINE, jamais par « la version
        // juste en dessous » : une version intermediaire retiree de la base
        // casserait un tri, pas une FK.
        $predecesseur = $notes->first(
            fn (DerivedKnowledgeNote $n): bool => (string) $n->superseded_by_id === (string) $note->id,
        );

        return [
            'type' => self::UPDATED,
            'subject_key' => (string) $note->subject_key,
            'claim_id' => (string) $note->id,
            'quand' => Carbon::instance($quand),
            'version' => (int) $note->version,
            'ancien' => $predecesseur === null ? null : trim((string) $predecesseur->content),
            'nouveau' => trim((string) $note->content),
            'raison' => null,
            'preuves_ancien' => $predecesseur === null ? [] : $this->preuves($predecesseur),
            'preuves_nouveau' => $this->preuves($note),
        ];
    }

    /**
     * Une ligne archivee n'est un EVENEMENT que si rien ne l'a remplacee.
     *
     * Une version remplacee par une autre n'est pas un changement en soi :
     * c'est le dos de la correction, deja rapportee par son successeur. La
     * compter deux fois ferait dire au delta qu'il s'est passe deux choses.
     *
     * @param  Collection<string, DerivedKnowledgeNote>  $parId
     * @param  array<string, Carbon>  $datesDesMessages
     * @return ?array<string, mixed>
     */
    private function evenementArchive(DerivedKnowledgeNote $note, $parId, array $datesDesMessages): ?array
    {
        if ($note->superseded_by_id !== null) {
            return null;
        }

        $provenance = $note->provenance ?? [];
        $preuves = array_map('strval', (array) ($provenance['retracted_evidence'] ?? []));

        // Le temps METIER d'un retrait : la date du message qui l'a justifie.
        // `superseded_at` dirait quand la machine a applique le patch.
        $quand = null;

        foreach ($preuves as $id) {
            $date = $datesDesMessages[$id] ?? null;

            if ($date !== null && ($quand === null || $date->greaterThan($quand))) {
                $quand = $date;
            }
        }

        if ($quand === null) {
            // Pas de preuve lisible : on ne DATE pas au hasard. L'evenement
            // sort du delta plutot que d'y entrer avec une date inventee.
            return null;
        }

        return [
            'type' => self::RETRACTED,
            'subject_key' => (string) $note->subject_key,
            'claim_id' => (string) $note->id,
            'quand' => $quand,
            'version' => (int) $note->version,
            'ancien' => trim((string) $note->content),
            'nouveau' => null,
            'raison' => trim((string) ($provenance['retracted_reason'] ?? '')) ?: null,
            'preuves_ancien' => $this->preuves($note),
            'preuves_nouveau' => $preuves,
        ];
    }

    /** @return list<string> */
    private function preuves(DerivedKnowledgeNote $note): array
    {
        return array_map('strval', (array) (($note->provenance ?? [])['source_loop_message_ids'] ?? []));
    }

    /**
     * Les dates des messages cites en preuve de retrait, en UNE requete.
     *
     * @param  Collection<int, DerivedKnowledgeNote>  $notes
     * @return array<string, Carbon>
     */
    private function datesDesMessages($notes): array
    {
        $ids = [];

        foreach ($notes as $note) {
            foreach ((array) (($note->provenance ?? [])['retracted_evidence'] ?? []) as $id) {
                $ids[] = (string) $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $dates = [];

        foreach (LoopMessage::query()->whereIn('id', array_unique($ids))->get(['id', 'created_at', 'edited_at']) as $m) {
            $at = $m->edited_at ?? $m->created_at;

            if ($at !== null) {
                $dates[(string) $m->id] = Carbon::instance($at);
            }
        }

        return $dates;
    }
}
