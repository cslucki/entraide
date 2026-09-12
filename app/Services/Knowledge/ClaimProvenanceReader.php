<?php

namespace App\Services\Knowledge;

use App\Models\DerivedKnowledgeNote;
use App\Models\DossierChunk;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossiers\DerivedChunkEligibility;
use Carbon\Carbon;

/**
 * TASK-1549 — le lecteur standard de provenance d'une memoire durable.
 *
 * LECTURE PURE : cette classe n'ecrit rien, n'appelle aucun provider, et ne
 * decide d'aucun droit nouveau — elle REVALIDE, a chaque appel, l'eligibilite
 * existante (`DerivedChunkEligibility::authorizedLoopIds()`, la meme autorite
 * que le retrieval des notes derivees). C'est le chemin que W1.5-B reutilisera
 * pour lire une provenance sans passer par le ChatLoop.
 *
 * ## Le discriminateur que la forme publique ne porte pas
 *
 * `KnowledgeAnswer::publicSource()` expose `type = 'retrieval'` pour un
 * Article, un fichier ET une note derivee : la forme publique ne distingue pas
 * une memoire durable d'un document, et la mission interdit de l'elargir. Le
 * discriminateur vit donc ICI, cote lecture : un chunk cite est une memoire
 * durable si et seulement si sa ligne `dossier_chunks` porte
 * `derived_knowledge_note_id` — une FK, jamais une heuristique de contenu
 * (meme regle que `mapSourceRow()`, TASK-1534).
 *
 * Le discriminateur est POSITIF, et il ne juge que ce qu'il peut prouver :
 *  - chunk avec FK vers un claim => memoire durable, provenance ou refus ;
 *  - chunk sans FK               => document ordinaire, ce lecteur rend `null` ;
 *  - chunk DISPARU               => la FK est partie avec la ligne : plus rien
 *                                   ne prouve l'origine. Ce lecteur rend `null`
 *                                   et la surface ne dit RIEN — elle n'invente
 *                                   pas une memoire a partir d'une absence.
 *
 * Ce dernier cas est la dette W5/TRACE-0, conservee telle quelle : tant que la
 * trace ne porte pas l'identite de la note, une citation dont le chunk a ete
 * balaye (`DerivedKnowledgeNoteIndexer::forget()`, reindexation d'un Article)
 * est indiscernable d'un document. La compter comme une trace de MEMOIRE
 * injoignable revenait a affirmer une origine qu'on ne peut plus etablir — et
 * faisait apparaitre « Memoire de BouclePro » sur une reponse qui n'avait cite
 * que des documents (TASK-1549, remediation).
 *
 * ## Ce que ce lecteur ne rend jamais
 *
 * Ni `subject_key` (une identite choisie par le modele, qui se retrouve cote
 * serveur et ne s'affiche pas), ni score, ni chaine de pensee, ni le moindre
 * detail d'une source dont l'ACL est refusee. Toute lecture de `provenance`
 * se fait en PHP : un operateur `jsonb` serait vert sur les six voies
 * PostgreSQL de la CI et faux sur SQLite (regle `ClaimResurrectionGuard`).
 */
final class ClaimProvenanceReader
{
    /**
     * Noms publics deja resolus, par identifiant d'utilisateur — `null` compris
     * (un auteur hors tenant se resout a `null`, et cette reponse-la aussi se
     * memorise). Le lignage d'un sujet n'est VOLONTAIREMENT pas borne, et une
     * meme personne corrige souvent plusieurs fois : sans ce cache, le panneau
     * emettait une requete `users` par evenement, dans la boucle par source
     * citee.
     *
     * @var array<string, string|null>
     */
    private array $nomsPublics = [];

    public function __construct(
        private readonly DerivedChunkEligibility $eligibility,
        private readonly ClaimMemory $memory,
    ) {}

    /**
     * La note derivee derriere un chunk cite, si ce chunk est une memoire
     * durable ADRESSABLE — la SEULE porte d'entree de la section memoire.
     *
     * Rendent `null`, et se taisent donc toutes de la meme facon : un chunk de
     * document ordinaire, un chunk disparu (l'origine n'est plus prouvable),
     * et le digest conversationnel, qui est bien de la memoire mais n'est pas
     * adressable par sujet — on ne propose pas de corriger ce qu'aucun
     * `subject_key` ne designe.
     */
    public function noteFromChunk(string $organizationId, string $chunkId): ?DerivedKnowledgeNote
    {
        $noteId = DossierChunk::query()
            ->where('organization_id', $organizationId)
            ->whereKey($chunkId)
            ->value('derived_knowledge_note_id');

        if ($noteId === null) {
            return null;
        }

        $note = DerivedKnowledgeNote::query()
            ->where('organization_id', $organizationId)
            ->find($noteId);

        return $note !== null && $note->isClaim() ? $note : null;
    }

    /**
     * La ligne `dossier_chunks` citee existe-t-elle encore ?
     *
     * N'AFFIRME RIEN SUR L'ORIGINE, et c'est tout l'interet : quand la ligne a
     * disparu, la FK a disparu avec elle — memoire durable et document ordinaire
     * deviennent indiscernables. Cette sonde sert donc le TROISIEME etat du
     * panneau (« une source citee n'est plus accessible »), qui se dit en dehors
     * de la section memoire et sans nommer de famille. La lire comme une trace
     * de memoire etait le defaut corrige en remediation TASK-1549.
     */
    public function citedChunkStillExists(string $organizationId, string $chunkId): bool
    {
        return DossierChunk::query()
            ->where('organization_id', $organizationId)
            ->whereKey($chunkId)
            ->exists();
    }

    /**
     * La provenance lisible du SUJET porte par une note citee, pour CE
     * spectateur, revalidee maintenant.
     *
     * L'etat courant fait foi : la note citee peut etre `superseded` depuis la
     * reponse — ce lecteur rend alors l'enonce ACTIF du sujet (resolution
     * note -> sujet -> claim actif, `ClaimMemory::actifs()`), signale
     * `evolved_since_answer`, et rend `state = 'retracted'` quand plus aucun
     * enonce actif ne porte ce sujet. Un sujet retracte n'a plus de geste.
     *
     * ## Ce que le panneau ChatLoop peut, ou non, en faire (REMEDIATION R2)
     *
     * Ces deux etats ne sont PAS atteignables depuis « Pourquoi ? » : la seule
     * porte de sa section memoire est {@see self::noteFromChunk()}, et une
     * supersession emporte le chunk cite (`ClaimMemory::appliquer()` ->
     * `DerivedKnowledgeNoteIndexer::forget()`). Une memoire corrigee quitte
     * donc la section et se dit au ledger, sans nommer de famille. Le panneau
     * ne les affiche plus — les afficher etait une promesse qu'aucune donnee
     * ne pouvait tenir (audit Codex F3).
     *
     * Ils restent rendus ICI parce que ce lecteur ne sert pas que le ChatLoop :
     * il repond a qui tient deja la note, quel que soit l'etat de son chunk.
     * Les faire vivre dans le panneau demande que la trace porte l'identite de
     * la note — TRACE-0, hors mandat.
     *
     * @return array{
     *     state: 'denied'|'active'|'retracted',
     *     statement: ?string,
     *     observed_at: ?string,
     *     loop_name: ?string,
     *     same_loop: bool,
     *     evolved_since_answer: bool,
     *     subject_version: ?int,
     *     evidence_message_ids: list<string>,
     *     corrections: list<array{by_name: ?string, at: ?string, message_id: ?string}>,
     *     can_correct: bool,
     * }
     */
    public function provenance(Organization $organization, Loop $currentLoop, DerivedKnowledgeNote $note, User $viewer): array
    {
        $denied = [
            'state' => 'denied', 'statement' => null, 'observed_at' => null,
            'loop_name' => null, 'same_loop' => false, 'evolved_since_answer' => false,
            'subject_version' => null, 'evidence_message_ids' => [], 'corrections' => [],
            'can_correct' => false,
        ];

        // Tenant d'abord, puis l'eligibilite de la Boucle SOURCE — la meme
        // autorite que le retrieval. Une adhesion revoquee entre la reponse et
        // ce clic rend le refus generique : ni auteur, ni titre, ni contenu.
        if ((string) $note->organization_id !== (string) $organization->id
            || (string) $currentLoop->organization_id !== (string) $organization->id) {
            return $denied;
        }

        $sourceLoopId = (string) $note->source_loop_id;

        if (! in_array($sourceLoopId, $this->eligibility->authorizedLoopIds((string) $organization->id, $viewer), true)) {
            return $denied;
        }

        $sourceLoop = $note->sourceLoop;

        if ($sourceLoop === null) {
            return $denied;
        }

        $subjectKey = (string) $note->subject_key;
        $sameLoop = $sourceLoopId === (string) $currentLoop->id;

        $actif = null;

        foreach ($this->memory->actifs($organization, $sourceLoop) as $claim) {
            if ((string) $claim->subject_key === $subjectKey) {
                $actif = $claim;
                break;
            }
        }

        $lignee = $this->memory->lignee($organization, $sourceLoop, $subjectKey);
        $courant = $actif ?? (end($lignee) ?: $note);

        return [
            'state' => $actif !== null ? 'active' : 'retracted',
            'statement' => (string) $courant->content,
            'observed_at' => $this->humanDate($courant->observed_at),
            'loop_name' => (string) $sourceLoop->name,
            'same_loop' => $sameLoop,
            'evolved_since_answer' => $actif !== null && (string) $courant->id !== (string) $note->id,
            'subject_version' => $actif === null ? null : (int) $actif->version,
            'evidence_message_ids' => $sameLoop ? $this->preuvesVisibles($currentLoop, $courant) : [],
            'corrections' => $this->corrections($organization, $lignee),
            // Le geste n'est propose que la ou il ecrit : corriger depuis une
            // autre Boucle posterait un message dans une conversation que la
            // personne ne regarde pas (decision bornee T1549 §6, fail-closed).
            'can_correct' => $actif !== null && $sameLoop,
        ];
    }

    /**
     * Les preuves humaines de l'enonce courant qui sont ENCORE montrables :
     * des messages vivants de cette Boucle. Un message supprime n'offre pas de
     * navigation — il n'est pas compte non plus : la preuve d'un claim se lit
     * dans sa provenance, pas dans ce raccourci d'acces.
     *
     * @return list<string>
     */
    private function preuvesVisibles(Loop $loop, DerivedKnowledgeNote $claim): array
    {
        $ids = array_values(array_map('strval', (array) (($claim->provenance ?? [])['source_loop_message_ids'] ?? [])));

        if ($ids === []) {
            return [];
        }

        return LoopMessage::query()
            ->whereIn('id', $ids)
            ->where('loop_id', $loop->id)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * L'historique de correction du sujet — depuis la FRONTIERE
     * `provenance['human_correction']` des lignes archivees, qui est la seule
     * autorite : les champs `corrected_*` vivent sur le successeur et
     * n'existent pas apres un RETRACT. Filtre en PHP, jamais en `jsonb`.
     *
     * @param  list<DerivedKnowledgeNote>  $lignee
     * @return list<array{by_name: ?string, at: ?string, message_id: ?string}>
     */
    private function corrections(Organization $organization, array $lignee): array
    {
        $events = [];

        foreach ($lignee as $version) {
            $frontiere = ($version->provenance ?? [])['human_correction'] ?? null;

            if (! is_array($frontiere)) {
                continue;
            }

            $by = (string) ($frontiere['by'] ?? '');
            $byName = $by === '' ? null : $this->nomPublic($organization, $by);

            $messageId = (string) ($frontiere['message_id'] ?? '');

            $events[] = [
                'by_name' => $byName,
                'at' => $this->humanDate($frontiere['at'] ?? null),
                'message_id' => $messageId === '' ? null : $messageId,
            ];
        }

        return $events;
    }

    /**
     * Le nom public d'un auteur de correction, borne au tenant — meme motif que
     * `AiResponseExplanationService::requesterName()`. Memoise, `null` compris :
     * un auteur hors tenant ne se resout pas, et on ne le redemande pas.
     */
    private function nomPublic(Organization $organization, string $userId): ?string
    {
        $cle = $organization->id.'|'.$userId;

        if (! array_key_exists($cle, $this->nomsPublics)) {
            $this->nomsPublics[$cle] = User::query()
                ->whereKey($userId)
                ->where('organization_id', $organization->id)
                ->first()?->publicDisplayName();
        }

        return $this->nomsPublics[$cle];
    }

    private function humanDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $date = $value instanceof \DateTimeInterface ? Carbon::instance($value) : Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }

        return $date->locale(app()->getLocale())->translatedFormat('j F Y');
    }
}
