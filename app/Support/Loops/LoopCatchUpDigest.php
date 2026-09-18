<?php

namespace App\Support\Loops;

use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopDecision;
use App\Models\LoopMessage;
use App\Models\LoopRoadmapItem;
use App\Models\User;

/**
 * TASK-1476 — « Rattrape-moi depuis… » : un digest DETERMINISTE d'UNE Boucle.
 *
 * ## La decision de conception, et sa raison
 *
 * Ce service **n'appelle aucun provider**. Un rattrapage genere par un modele
 * pourrait inventer une decision a partir d'une phrase ambigue — ce que le CDC
 * interdit explicitement. Une agregation d'objets STRUCTURES ne le peut pas :
 * elle ne rapporte que ce que quelqu'un a deliberement cree.
 *
 * Consequence directe : zero cout, zero plafond, zero garde economique, et une
 * sortie identique a chaque appel pour une meme fenetre.
 *
 * ## D'ou vient chaque ligne
 *
 * | Section | Source | Ce qui la rend explicite |
 * |---|---|---|
 * | A savoir | `loop_messages` type `user`, `loop_event`, `poll_event` | quelqu'un a ecrit, organise ou vote |
 * | Decisions | `loop_decisions` | un objet cree a la main, avec son titre |
 * | Documents | `dossier_files` du Dossier de la Boucle | un fichier a ete depose |
 * | Ouvert | `loop_messages` type `help_request` + `loop_roadmap_items` non termines | une demande posee, une action non close |
 *
 * Aucune de ces quatre lignes ne resulte d'une interpretation de texte.
 *
 * ## Le verrou tenant, deux fois
 *
 * Chaque requete filtre sur `loop_id` **et** sur `organization_id`. La seconde
 * condition est redondante tant que les donnees sont saines — c'est
 * exactement pourquoi elle est la : une Boucle mal rattachee ne doit pas
 * suffire a faire traverser une frontiere d'Organization.
 *
 * ## Ce que ce service ne fait pas
 *
 * Il n'ecrit rien. Aucune position de lecture n'est posee, aucune notification
 * n'est emise, aucun objet metier n'est touche. Lire un rattrapage ne change
 * pas l'etat du monde.
 */
final class LoopCatchUpDigest
{
    public const SECTION_TO_KNOW = 'to_know';

    public const SECTION_DECISIONS = 'decisions';

    public const SECTION_DOCUMENTS = 'documents';

    public const SECTION_OPEN = 'open';

    /** L'ordre d'affichage, fige ici pour que la vue n'ait aucune decision a prendre. */
    public const SECTIONS = [self::SECTION_TO_KNOW, self::SECTION_DECISIONS, self::SECTION_DOCUMENTS, self::SECTION_OPEN];

    /** Au-dela, on annonce le total et on montre les plus recents : un mur de 200 lignes n'est pas un rattrapage. */
    public const MAX_ITEMS_PER_SECTION = 12;

    /**
     * Les types de message qui comptent comme « il s'est passe quelque chose ».
     * `ai` en est absent : une reponse de l'assistant n'est pas une nouvelle
     * de la Boucle, et la compter gonflerait le rattrapage sans rien apprendre.
     */
    public const ACTIVITY_TYPES = ['user', 'loop_event', 'poll_event'];

    /**
     * @return array<string, array{key: string, total: int, shown: int, items: list<array<string, mixed>>}>
     */
    public function for(Loop $loop, LoopCatchUpWindow $window, User $viewer): array
    {
        return [
            self::SECTION_TO_KNOW => $this->toKnow($loop, $window),
            self::SECTION_DECISIONS => $this->decisions($loop, $window),
            self::SECTION_DOCUMENTS => $this->documents($loop, $window, $viewer),
            self::SECTION_OPEN => $this->open($loop, $window),
        ];
    }

    /** Vrai quand les quatre sections sont vides : l'ecran doit alors le DIRE, pas afficher quatre titres nus. */
    public function isEmpty(array $sections): bool
    {
        foreach ($sections as $section) {
            if (($section['total'] ?? 0) > 0) {
                return false;
            }
        }

        return true;
    }

    // =================================================================
    // Sections
    // =================================================================

    private function toKnow(Loop $loop, LoopCatchUpWindow $window): array
    {
        $query = $this->messages($loop, $window)->whereIn('type', self::ACTIVITY_TYPES);

        $total = (clone $query)->count();

        $items = (clone $query)
            ->with('sender:id,name')
            ->orderByDesc('pinned_at')
            ->orderByDesc('created_at')
            ->limit(self::MAX_ITEMS_PER_SECTION)
            ->get()
            ->map(fn (LoopMessage $message) => [
                'id' => $message->id,
                'title' => $this->excerpt($message->body),
                'author' => $message->sender?->name,
                'at' => $message->created_at,
                'pinned' => $message->pinned_at !== null,
                'kind' => $message->type,
            ])
            ->all();

        return $this->section(self::SECTION_TO_KNOW, $total, $items);
    }

    private function decisions(Loop $loop, LoopCatchUpWindow $window): array
    {
        $query = LoopDecision::query()
            ->where('loop_id', $loop->id)
            ->where('organization_id', $loop->organization_id)
            ->whereBetween('created_at', [$window->since, $window->until]);

        $total = (clone $query)->count();

        $items = (clone $query)
            ->with('author:id,name')
            ->orderByDesc('created_at')
            ->limit(self::MAX_ITEMS_PER_SECTION)
            ->get()
            ->map(fn (LoopDecision $decision) => [
                'id' => $decision->id,
                'title' => $decision->title,
                'author' => $decision->author?->name,
                'at' => $decision->created_at,
                'decided_on' => $decision->decided_on,
                // La Decision porte le message qui l'a produite quand elle en
                // vient d'un : c'est la seule provenance disponible, et elle
                // est posee a la main, jamais deduite.
                'from_message' => $decision->loop_message_id !== null,
            ])
            ->all();

        return $this->section(self::SECTION_DECISIONS, $total, $items);
    }

    /**
     * Les fichiers des Dossiers de CETTE Boucle. Le lien profond n'est propose
     * que si la politique existante l'autorise pour CE lecteur : un membre de
     * la Boucle n'a pas automatiquement acces a chaque Dossier.
     */
    private function documents(Loop $loop, LoopCatchUpWindow $window, User $viewer): array
    {
        $dossierIds = Dossier::query()
            ->where('loop_id', $loop->id)
            ->where('organization_id', $loop->organization_id)
            ->pluck('id');

        if ($dossierIds->isEmpty()) {
            return $this->section(self::SECTION_DOCUMENTS, 0, []);
        }

        $query = DossierFile::query()
            ->whereIn('dossier_id', $dossierIds)
            ->where('organization_id', $loop->organization_id)
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$window->since, $window->until]);

        $total = (clone $query)->count();

        $items = (clone $query)
            ->with(['uploader:id,name', 'dossier:id,name,loop_id,organization_id'])
            ->orderByDesc('created_at')
            ->limit(self::MAX_ITEMS_PER_SECTION)
            ->get()
            ->map(fn (DossierFile $file) => [
                'id' => $file->id,
                'title' => $file->display_name ?: $file->original_name,
                'author' => $file->uploader?->name,
                'at' => $file->created_at,
                'dossier' => $file->dossier?->name,
                'dossier_id' => $file->dossier?->id,
                'dossier_readable' => $file->dossier !== null && $viewer->can('view', $file->dossier),
            ])
            ->all();

        return $this->section(self::SECTION_DOCUMENTS, $total, $items);
    }

    /**
     * Ce qui attend quelqu'un. Deux sources EXPLICITES, jamais une phrase
     * interpretee : une demande d'aide projetee dans la Boucle porte le type
     * `help_request`, et une action de feuille de route porte un statut.
     */
    private function open(Loop $loop, LoopCatchUpWindow $window): array
    {
        $requests = $this->messages($loop, $window)->where('type', 'help_request');

        $actions = LoopRoadmapItem::query()
            ->where('loop_id', $loop->id)
            ->where('organization_id', $loop->organization_id)
            ->whereIn('status', [LoopRoadmapItem::STATUS_TODO, LoopRoadmapItem::STATUS_IN_PROGRESS])
            ->whereBetween('created_at', [$window->since, $window->until]);

        $total = (clone $requests)->count() + (clone $actions)->count();

        $items = (clone $requests)
            ->with('sender:id,name')
            ->orderByDesc('created_at')
            ->limit(self::MAX_ITEMS_PER_SECTION)
            ->get()
            ->map(fn (LoopMessage $message) => [
                'id' => $message->id,
                'title' => $this->excerpt($message->body),
                'author' => $message->sender?->name,
                'at' => $message->created_at,
                'kind' => 'help_request',
            ])
            ->all();

        $items = array_merge($items, (clone $actions)
            ->with('creator:id,name')
            ->orderByDesc('created_at')
            ->limit(self::MAX_ITEMS_PER_SECTION)
            ->get()
            ->map(fn (LoopRoadmapItem $item) => [
                'id' => $item->id,
                'title' => $item->title,
                'author' => $item->creator?->name,
                'at' => $item->created_at,
                'kind' => 'roadmap_'.$item->status,
                'due_at' => $item->due_at,
            ])
            ->all());

        usort($items, fn ($a, $b) => ($b['at']?->getTimestamp() ?? 0) <=> ($a['at']?->getTimestamp() ?? 0));

        return $this->section(self::SECTION_OPEN, $total, array_slice($items, 0, self::MAX_ITEMS_PER_SECTION));
    }

    // =================================================================
    // Primitives
    // =================================================================

    /** Le socle commun des deux sections tirees des messages — verrou tenant compris. */
    private function messages(Loop $loop, LoopCatchUpWindow $window)
    {
        return LoopMessage::query()
            ->where('loop_id', $loop->id)
            ->where('organization_id', $loop->organization_id)
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$window->since, $window->until]);
    }

    private function section(string $key, int $total, array $items): array
    {
        return ['key' => $key, 'total' => $total, 'shown' => count($items), 'items' => $items];
    }

    /** Un extrait, jamais un resume : couper est verifiable, resumer ne l'est pas. */
    private function excerpt(?string $body): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', (string) $body) ?? '');

        return mb_strlen($clean) > 140 ? mb_substr($clean, 0, 139).'…' : $clean;
    }
}
