<?php

namespace App\Services\Knowledge;

use App\Models\DerivedKnowledgeNote;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TASK-1540 — la memoire adressable par enonce.
 *
 * ## Aucune table neuve, et ce n'est pas une economie
 *
 * `derived_knowledge_notes` portait deja, PAR LIGNE, tout ce qu'un claim
 * reclame : une identite (`subject_key`), une version, un statut, une histoire
 * (`superseded_by_id`), deux temps distincts (`observed_at` / `derived_at`) et
 * des preuves (`provenance`). Ses index uniques incluent deja `subject_key`,
 * et `dossier_chunks.derived_knowledge_note_id` pointe deja une LIGNE.
 *
 * Un claim EST donc une ligne de cette table. Ce qui change n'est pas la
 * structure : c'est qu'on cesse de n'en ecrire qu'une seule.
 *
 * La consequence la plus utile est gratuite : un chunk par claim, topiquement
 * homogene, la ou le digest produisait un vecteur moyenne sur huit sujets que
 * le moindre Article generique devancait (mesure T1537).
 *
 * ## Le CAS de T1538, etendu
 *
 * En T1538, une compilation partie d'un etat ancien perdait si la note active
 * n'etait plus celle qu'elle avait lue. Ici la base n'est plus UNE note mais
 * l'ENSEMBLE des claims actifs : l'empreinte porte donc leurs identites et
 * leurs versions, triees. Si cet ensemble a bouge pendant l'appel au modele,
 * le patch entier perd — il ne peut ni ressusciter un claim retracte, ni
 * ecraser une correction, ni supprimer un claim ajoute depuis sa lecture.
 *
 * Tout ou rien : appliquer la moitie d'un patch batirait un etat que personne
 * n'a jamais decide.
 */
final class ClaimMemory
{
    public function __construct(
        private readonly DerivedKnowledgeNoteIndexer $indexer,
    ) {}

    /**
     * Les claims actifs d'une Boucle, du plus recemment observe au plus ancien.
     *
     * @return list<DerivedKnowledgeNote>
     */
    public function actifs(Organization $organization, Loop $loop): array
    {
        return DerivedKnowledgeNote::query()
            ->where('organization_id', $organization->id)
            ->where('source_type', DerivedKnowledgeNote::SOURCE_LOOP_CONVERSATION)
            ->where('source_loop_id', $loop->id)
            ->claims()
            ->active()
            ->orderByDesc('observed_at')
            ->get()
            ->all();
    }

    /**
     * L'empreinte de l'ETAT de la memoire — identites et versions.
     *
     * Elle repond a une seule question : « est-ce toujours la memoire que j'ai
     * lue avant d'appeler le modele ? ». Le contenu n'y entre pas : deux
     * versions differentes d'un meme claim portent deja deux numeros.
     *
     * @param  list<DerivedKnowledgeNote>  $claims
     */
    public function empreinte(array $claims): string
    {
        $parts = array_map(
            static fn (DerivedKnowledgeNote $c): string => $c->subject_key.':'.$c->version,
            $claims,
        );

        sort($parts);

        return hash('sha256', implode('|', $parts));
    }

    /**
     * Applique un patch valide, sous verrou, tout ou rien.
     *
     * @param  list<LoopMessage>  $messages  la source lue pour ce tour
     * @return array{applique: bool, raison: ?string, ajoutes: int, modifies: int, retractes: int, conserves: int}
     */
    public function appliquer(
        Organization $organization,
        Loop $loop,
        string $dossierId,
        ClaimPatch $patch,
        array $messages,
        string $empreinteDeDepart,
        ?string $correlationId,
    ): array {
        /** @var list<DerivedKnowledgeNote> $aIndexer */
        $aIndexer = [];

        $bilan = DB::transaction(function () use ($organization, $loop, $dossierId, $patch, $messages, $empreinteDeDepart, $correlationId, &$aIndexer): array {
            // Le verrou porte sur TOUS les claims actifs de la Boucle : c'est
            // l'ensemble qui constitue la base, pas une ligne isolee.
            $courants = DerivedKnowledgeNote::query()
                ->where('organization_id', $organization->id)
                ->where('source_type', DerivedKnowledgeNote::SOURCE_LOOP_CONVERSATION)
                ->where('source_loop_id', $loop->id)
                ->claims()
                ->active()
                ->lockForUpdate()
                ->get();

            if (! hash_equals($this->empreinte($courants->all()), $empreinteDeDepart)) {
                // La memoire a bouge pendant l'appel au modele. Ce patch est
                // parti d'un etat qui n'existe plus : il ne gagne pas.
                return $this->bilan(false, 'base_perimee');
            }

            $parId = $courants->keyBy(static fn (DerivedKnowledgeNote $c): string => (string) $c->subject_key);

            $observeA = $this->observeA($messages);
            $preuvesDe = fn (array $op): array => $this->preuves($op, $messages, $correlationId);

            $ajoutes = $modifies = $retractes = 0;

            foreach ($patch->operationsDe(ClaimPatch::OP_ADD) as $op) {
                $this->creer($organization, $loop, $dossierId, (string) Str::uuid(), 1, (string) $op['text'],
                    $preuvesDe($op), $observeA, $aIndexer);
                $ajoutes++;
            }

            foreach ($patch->operationsDe(ClaimPatch::OP_UPDATE) as $op) {
                $ancien = $parId->get((string) $op['claim_id']);

                if ($ancien === null) {
                    continue;
                }

                $this->archiver($ancien);
                $nouveau = $this->creer($organization, $loop, $dossierId, (string) $ancien->subject_key,
                    $this->prochaineVersion($organization, $loop, (string) $ancien->subject_key),
                    (string) $op['text'], $preuvesDe($op), $observeA, $aIndexer);

                $ancien->forceFill(['superseded_by_id' => $nouveau->id])->save();
                $this->indexer->forget($ancien);
                $modifies++;
            }

            foreach ($patch->operationsDe(ClaimPatch::OP_RETRACT) as $op) {
                $ancien = $parId->get((string) $op['claim_id']);

                if ($ancien === null) {
                    continue;
                }

                // Un RETRACT ne fabrique AUCUN remplacant. « Cette date n'est
                // plus valable » ne veut pas dire « voici la nouvelle date » :
                // inventer un successeur serait combler un trou avec une
                // certitude que personne n'a exprimee.
                $this->archiver($ancien, (string) ($op['reason'] ?? ''), $preuvesDe($op));
                $this->indexer->forget($ancien);
                $retractes++;
            }

            return $this->bilan(true, null, $ajoutes, $modifies, $retractes,
                count($patch->operationsDe(ClaimPatch::OP_KEEP)));
        });

        // L'indexation vit HORS de la transaction, et deliberement.
        //
        // `synchronize()` resout un provider et appelle le service d'embedding :
        // un aller-retour reseau par claim. Les tenir a l'interieur ferait
        // garder le verrou de la Boucle pendant N appels distants — un tour
        // lent bloquerait toute compilation concurrente, et un provider
        // injoignable ferait echouer des ecritures deja decidees.
        //
        // Les lignes sont ecrites ; leurs vecteurs suivent. Un claim non encore
        // indexe est simplement introuvable pendant un instant, ce qui est
        // exactement le comportement d'un index asynchrone — jamais une
        // incoherence.
        foreach ($aIndexer as $note) {
            $this->indexer->synchronize($note);
        }

        return $bilan;
    }

    private function creer(
        Organization $organization,
        Loop $loop,
        string $dossierId,
        string $subjectKey,
        int $version,
        string $texte,
        array $provenance,
        \DateTimeInterface $observeA,
        ?array &$aIndexer = null,
    ): DerivedKnowledgeNote {
        $note = DerivedKnowledgeNote::create([
            'organization_id' => $organization->id,
            'source_type' => DerivedKnowledgeNote::SOURCE_LOOP_CONVERSATION,
            'kind' => DerivedKnowledgeNote::KIND_CLAIM,
            'source_loop_id' => $loop->id,
            'dossier_id' => $dossierId,
            'subject_key' => $subjectKey,
            'content' => $texte,
            // L'empreinte d'un CLAIM est celle de son propre contenu : deux
            // claims d'un meme tour n'ont aucune raison de partager la leur.
            'source_fingerprint' => hash('sha256', $subjectKey.'|'.$version.'|'.$texte),
            'provenance' => $provenance,
            'observed_at' => $observeA,
            'derived_at' => now(),
            'version' => $version,
            'status' => DerivedKnowledgeNote::STATUS_ACTIVE,
        ]);

        if ($aIndexer !== null) {
            $aIndexer[] = $note;
        }

        return $note;
    }

    /**
     * @param  list<string>  $preuves
     */
    private function archiver(DerivedKnowledgeNote $claim, string $raison = '', array $preuves = []): void
    {
        $provenance = $claim->provenance ?? [];

        if ($raison !== '') {
            $provenance['retracted_reason'] = $raison;
            $provenance['retracted_evidence'] = $preuves['source_loop_message_ids'] ?? [];
        }

        $claim->forceFill([
            'status' => DerivedKnowledgeNote::STATUS_SUPERSEDED,
            'superseded_at' => now(),
            'provenance' => $provenance,
        ])->save();
    }

    private function prochaineVersion(Organization $organization, Loop $loop, string $subjectKey): int
    {
        return 1 + (int) DerivedKnowledgeNote::query()
            ->where('organization_id', $organization->id)
            ->where('source_type', DerivedKnowledgeNote::SOURCE_LOOP_CONVERSATION)
            ->where('source_loop_id', $loop->id)
            ->where('subject_key', $subjectKey)
            ->max('version');
    }

    /**
     * @param  list<LoopMessage>  $messages
     * @return array<string, mixed>
     */
    private function preuves(array $op, array $messages, ?string $correlationId): array
    {
        $ids = (array) ($op['evidence'] ?? []);
        $parId = [];

        foreach ($messages as $m) {
            $parId[(string) $m->id] = $m;
        }

        $observe = null;

        foreach ($ids as $id) {
            $m = $parId[(string) $id] ?? null;
            $at = $m?->edited_at ?? $m?->created_at;

            if ($at !== null && ($observe === null || $at->greaterThan($observe))) {
                $observe = $at;
            }
        }

        return [
            'source_loop_message_ids' => array_values(array_map('strval', $ids)),
            'source_message_count' => count($ids),
            'correlation_id' => $correlationId,
            'derived_by' => LoopConversationKnowledgeDeriver::FEATURE,
            'observed_from_evidence' => $observe?->toIso8601String(),
        ];
    }

    /**
     * @param  list<LoopMessage>  $messages
     */
    private function observeA(array $messages): \DateTimeInterface
    {
        $observe = null;

        foreach ($messages as $m) {
            $at = $m->edited_at ?? $m->created_at;

            if ($at !== null && ($observe === null || $at->greaterThan($observe))) {
                $observe = $at;
            }
        }

        return $observe ?? now();
    }

    /**
     * @return array{applique: bool, raison: ?string, ajoutes: int, modifies: int, retractes: int, conserves: int}
     */
    private function bilan(bool $applique, ?string $raison, int $a = 0, int $m = 0, int $r = 0, int $k = 0): array
    {
        return ['applique' => $applique, 'raison' => $raison,
            'ajoutes' => $a, 'modifies' => $m, 'retractes' => $r, 'conserves' => $k];
    }
}
