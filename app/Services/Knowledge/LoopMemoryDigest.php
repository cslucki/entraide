<?php

namespace App\Services\Knowledge;

use App\Models\DerivedKnowledgeNote;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * TASK-1550 — « Depuis cet echange, BouclePro a retenu... »
 *
 * LECTURE PURE, et surtout : AUCUN MOTEUR. Cette classe n'ecrit rien, n'appelle
 * aucun fournisseur, ne compile rien et ne rend par elle-meme aucun verdict
 * d'ACL. Elle COMPOSE trois lectures qui existaient deja :
 *
 *  - `LoopClaimDelta::pour()`        — les ecritures ADD / UPDATE / RETRACT
 *                                     d'une Boucle depuis un instant, fermees
 *                                     par defaut sur `authorizedLoopIds()` ;
 *  - `ClaimMemory::actifs()`         — de quoi relier un evenement a sa ligne ;
 *  - `ClaimProvenanceReader::provenance()` — la forme lisible d'un enonce, a
 *                                     l'ETAT COURANT, avec sa propre
 *                                     revalidation d'ACL.
 *
 * Les entrees rendues ont donc EXACTEMENT la forme de celles du panneau
 * « Pourquoi ? » de T1549 (`['ref' => ..., ...$provenance]`), a un champ pres
 * (`kind`) : c'est ce qui permet a la carte et au panneau de partager le meme
 * rendu et le meme chemin de correction, au lieu d'en avoir deux.
 *
 * ## La fenetre, et pourquoi elle n'invente aucune position de lecture
 *
 * Le produit ne porte AUCUN marqueur de lecture de Boucle — mesure refaite pour
 * cette TASK : `messages.read_at` est la messagerie privee,
 * `member_notifications.read_at` le centre de notifications,
 * `guest_visitors.last_seen_at` le visiteur anonyme. Rien sur `loop_members`.
 *
 * Plutot que de creer ce marqueur — une primitive et une migration, hors mandat
 * — la fenetre est ancree sur un fait qui existe deja : **le dernier message
 * humain de CETTE personne dans CETTE Boucle**. « Depuis cet echange » veut
 * donc dire, litteralement : depuis la derniere fois que vous avez parle ici.
 *
 * Ce que cette approximation coute est assume et nomme :
 *  - qui ne reparle jamais revoit la carte a chaque ouverture (bornee par un
 *    renvoi de session cote composant, qui ne promet aucune persistance) ;
 *  - qui n'a jamais parle ici n'a PAS de carte — sans contribution il n'y a pas
 *    « cet echange », et la carte ne doit pas devenir la notification passive
 *    d'un lecteur (CDC CORE V3 §3.3 : « pas apres un simple tour READ »).
 *
 * ## Honnetete asynchrone : le silence, pas un spinner
 *
 * Tant que la compilation n'a pas tourne, la fenetre est vide et il n'y a
 * simplement pas de carte. Aucun « BouclePro est en train d'apprendre » : ce
 * serait promettre un delai que le CDC interdit de promettre. Le temps affiche
 * reste le temps HUMAIN — `observed_at`, jamais `derived_at`.
 *
 * ## Ce qui n'entre jamais
 *
 * Ni `subject_key` (identite choisie par le modele : elle reste cote serveur,
 * et l'adresse publique est un jeton opaque, voir {@see self::jeton()}), ni
 * score, ni chaine de pensee, ni le conteneur de Boucle — `LoopClaimDelta` ne
 * lit que les enonces. Et **aucune ecriture d'origine humaine** : une
 * correction que la personne vient de faire a deja recu son accuse de reception
 * synchrone (T1549) ; la lui renvoyer en « BouclePro a retenu » brouillerait
 * l'invariant « correction explicite immediate != apprentissage automatique
 * asynchrone ».
 */
final class LoopMemoryDigest
{
    /**
     * Deux a trois elements utiles, pas un inventaire (MASTER V3 §5). La carte
     * n'est pas un gestionnaire de memoire : elle montre la tete de ce qui
     * vient d'etre appris et laisse le reste a sa place.
     */
    public const MAX_ENTREES = 3;

    public function __construct(
        private readonly LoopClaimDelta $delta,
        private readonly ClaimMemory $memory,
        private readonly ClaimProvenanceReader $claims,
    ) {}

    /**
     * La carte pour CE spectateur, dans CETTE Boucle, maintenant.
     *
     * `null` des qu'il n'y a rien a dire — et c'est le cas le plus frequent :
     * pas de contribution de cette personne, aucune compilation depuis, ou
     * aucune ecriture attribuable au compiler dans la fenetre.
     *
     * @return array{entries: list<array<string, mixed>>}|null
     */
    public function pour(Organization $organization, Loop $loop, ?User $viewer): ?array
    {
        if ($viewer === null || (string) $loop->organization_id !== (string) $organization->id) {
            return null;
        }

        $depuis = $this->ancre($loop, $viewer);

        if ($depuis === null || ! $this->compileDepuis($organization, $loop, $depuis)) {
            return null;
        }

        // L'ACL vit ICI, dans l'autorite unique : `pour()` rend un tableau vide
        // a qui n'est pas membre actif de la Boucle, sans reveler qu'un
        // changement a eu lieu.
        $evenements = $this->delta->pour((string) $organization->id, $loop, $viewer, $depuis);

        if ($evenements === []) {
            return null;
        }

        $actifs = [];

        foreach ($this->memory->actifs($organization, $loop) as $claim) {
            $actifs[(string) $claim->id] = $claim;
        }

        $entries = [];

        foreach ($evenements as $evenement) {
            if (count($entries) >= self::MAX_ENTREES) {
                break;
            }

            $entree = $this->entree($organization, $loop, $viewer, $evenement, $actifs);

            if ($entree !== null) {
                $entries[] = $entree;
            }
        }

        return $entries === [] ? null : ['entries' => $entries];
    }

    /**
     * Le `subject_key` qu'un jeton de carte designe — SI un enonce ACTIF le
     * porte encore et si cette personne a le droit de le corriger ICI.
     *
     * C'est la resolution serveur du geste `Corriger` de la carte, exactement
     * dans le role que `AiResponseExplanationService::citedMemoryNote()` tient
     * pour le panneau « Pourquoi ? » : le composant traduit, il ne decide pas.
     * `null` couvre tout le reste — jeton inconnu, enonce retracte entre-temps,
     * Boucle non autorisee — et la surface dira « rien n'a ete enregistre ».
     */
    public function sujetCorrigeable(Organization $organization, Loop $loop, ?User $viewer, string $jeton): ?string
    {
        if ($viewer === null || $jeton === '' || (string) $loop->organization_id !== (string) $organization->id) {
            return null;
        }

        foreach ($this->memory->actifs($organization, $loop) as $claim) {
            if (! hash_equals($this->jeton($loop, (string) $claim->subject_key), $jeton)) {
                continue;
            }

            // Defense en profondeur : le lecteur standard repose la question a
            // la meme autorite, et c'est LUI qui dit si le geste est offert.
            $provenance = $this->claims->provenance($organization, $loop, $claim, $viewer);

            return $provenance['state'] === 'active' && $provenance['can_correct']
                ? (string) $claim->subject_key
                : null;
        }

        return null;
    }

    /**
     * L'adresse PUBLIQUE d'un enonce dans la carte.
     *
     * Ni `subject_key`, ni identifiant de ligne : un HMAC tronque, borne a
     * cette Boucle. Trois proprietes, et chacune repare un piege paye ailleurs :
     *
     *  - **opaque** — `subject_key` est une identite choisie par le modele et ne
     *    doit pas circuler dans le snapshot Livewire (regle T1549) ;
     *  - **stable** — elle ne bouge pas entre deux rendus, contrairement a un
     *    numero de position. La reference `S1` de T1549 n'etait stable que parce
     *    que son ancre — une bulle IA — est immuable ; une carte, elle, se
     *    recalcule. Une adresse positionnelle aurait rejoue la classe de defaut
     *    de la remediation R2 : deux listes differentes, la meme adresse ;
     *  - **liee au sujet** — un jeton ne peut donc JAMAIS autoriser l'ecriture
     *    d'un autre sujet, meme si les versions se croisent. C'est ce qui rend
     *    ici structurellement impossible la confusion que le triplet de T1549
     *    devait garder.
     */
    public function jeton(Loop $loop, string $subjectKey): string
    {
        return substr(hash_hmac('sha256', $loop->id.'|'.$subjectKey, (string) config('app.key')), 0, 16);
    }

    /**
     * Le dernier message HUMAIN de cette personne dans cette Boucle.
     *
     * `type = 'user'` et non n'importe quel message : c'est la definition que
     * `LoopConversationKnowledgeDeriver::sourceMessages()` donne deja d'une
     * contribution humaine, et deux definitions concurrentes de « l'echange »
     * feraient diverger la carte du corpus reellement compile.
     */
    private function ancre(Loop $loop, User $viewer): ?Carbon
    {
        $message = LoopMessage::query()
            ->where('loop_id', $loop->id)
            ->where('organization_id', $loop->organization_id)
            ->where('sender_id', $viewer->id)
            ->where('type', 'user')
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->first(['id', 'created_at']);

        return $message?->created_at === null ? null : Carbon::instance($message->created_at);
    }

    /**
     * Une compilation a-t-elle eu lieu depuis que cette personne a parle ?
     *
     * Court-circuit de cout, pas une regle produit : cette page porte un
     * `wire:poll.3s`, et le cas de loin le plus frequent est « rien de neuf ».
     * Le conteneur de Boucle porte `derived_at` = l'instant de la derniere
     * compilation reussie (`ClaimMemory::rafraichirConteneur()`, appele apres
     * chaque `appliquer()` du compiler) : il est donc posterieur ou egal a
     * l'ecriture de tout enonce de ce run, et un conteneur plus vieux que
     * l'ancre prouve qu'aucune ecriture ne peut tomber dans la fenetre.
     *
     * Il ne bouge jamais en arriere, donc la reponse ne redevient pas fausse.
     * Une correction humaine ne le rafraichit pas — et c'est coherent : les
     * ecritures humaines sont de toute facon ecartees de la carte.
     */
    private function compileDepuis(Organization $organization, Loop $loop, Carbon $depuis): bool
    {
        $conteneur = $this->memory->conteneur($organization, $loop);

        return $conteneur?->derived_at !== null && ! $conteneur->derived_at->lessThan($depuis);
    }

    /**
     * Un evenement de lignage devenu une entree lisible — ou `null`.
     *
     * @param  array<string, mixed>  $evenement
     * @param  array<string, DerivedKnowledgeNote>  $actifs
     * @return array<string, mixed>|null
     */
    private function entree(
        Organization $organization,
        Loop $loop,
        User $viewer,
        array $evenement,
        array $actifs,
    ): ?array {
        $note = $this->ligne($organization, $loop, $evenement, $actifs);

        if ($note === null || $this->ecritParUnHumain($note, (string) $evenement['type'])) {
            return null;
        }

        $provenance = $this->claims->provenance($organization, $loop, $note, $viewer);

        // Un refus ne se compte meme pas ici : la carte parle de l'echange de
        // cette personne, dans sa Boucle. Un enonce qu'elle ne peut pas lire
        // n'a aucune raison d'y laisser une trace, pas meme un nombre.
        if ($provenance['state'] === 'denied') {
            return null;
        }

        return [
            'ref' => $this->jeton($loop, (string) $note->subject_key),
            'kind' => (string) $evenement['type'],
            ...$provenance,
        ];
    }

    /**
     * La ligne derriere un evenement.
     *
     * Un ADD ou un UPDATE laisse un enonce ACTIF : il est deja charge. Un
     * RETRACT n'en laisse aucun — sa ligne se retrouve par le lignage du sujet,
     * et `provenance()` rendra `state = 'retracted'` sans geste possible.
     *
     * @param  array<string, mixed>  $evenement
     * @param  array<string, DerivedKnowledgeNote>  $actifs
     */
    private function ligne(Organization $organization, Loop $loop, array $evenement, array $actifs): ?DerivedKnowledgeNote
    {
        $active = $actifs[(string) $evenement['claim_id']] ?? null;

        if ($active !== null) {
            return $active;
        }

        if ((string) $evenement['type'] !== LoopClaimDelta::RETRACTED) {
            return null;
        }

        $lignee = $this->memory->lignee($organization, $loop, (string) $evenement['subject_key']);

        return end($lignee) ?: null;
    }

    /**
     * L'ecriture vient-elle d'une personne plutot que du compiler ?
     *
     * Les deux marques ne vivent pas au meme endroit, et c'est mesure :
     *
     *  - ajout / modification : la ligne ECRITE porte
     *    `provenance['derived_by'] = 'human_correction'`
     *    (`ClaimWriteOrigin::provenanceFields()`) ;
     *  - retrait : la ligne archivee garde le `derived_by` de sa CREATION. La
     *    seule marque du geste est la frontiere `provenance['human_correction']`
     *    posee par `archiver()` — exactement le correctif de realite de T1549 :
     *    lire `corrected_*` rendrait un auteur vide, en silence, sur le cas
     *    RETRACT.
     */
    private function ecritParUnHumain(DerivedKnowledgeNote $note, string $type): bool
    {
        $provenance = $note->provenance ?? [];

        if ($type === LoopClaimDelta::RETRACTED) {
            return is_array($provenance['human_correction'] ?? null);
        }

        return ($provenance['derived_by'] ?? null) === ClaimWriteOrigin::HUMAN_CORRECTION;
    }
}
