<?php

namespace App\Services\Knowledge;

use App\Models\DerivedKnowledgeNote;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossiers\DerivedChunkEligibility;
use App\Services\LoopMessageService;
use App\Services\Loops\LoopLifecycleService;

/**
 * TASK-1548 — « c'est faux », et la memoire l'entend tout de suite.
 *
 * ## Le contrat
 *
 * Une correction humaine est prise en compte IMMEDIATEMENT, sans appel a un
 * modele, et n'est pas defaite au balayage suivant. Elle ne touche rien de
 * l'histoire humaine : elle AJOUTE un message — celui de la personne qui
 * corrige — et ARCHIVE un enonce. Aucun message n'est modifie, aucun n'est
 * supprime, aucune ligne de memoire n'est detruite.
 *
 * ## Pourquoi un vrai message, et pas un marqueur technique
 *
 * `ClaimPatch::valider()` exige qu'une operation cite des preuves reelles,
 * appartenant au tour. C'est la regle qui separe une memoire d'une rumeur, et
 * la mission interdit de l'assouplir. Un message humain visible la satisfait
 * SANS la toucher : la preuve de la correction, c'est la phrase que la personne
 * a ecrite. Elle est lisible par la Boucle, elle est datee, elle est
 * attribuee — et elle sert ensuite de frontiere a la garde anti-resurrection.
 *
 * Un message technique cache aurait coute une exception d'affichage partout, et
 * rendu la correction invisible a ceux qu'elle concerne.
 *
 * ## L'ordre des ecritures, et ce qu'il coute
 *
 * Le message est ecrit AVANT le patch, parce qu'il en est la preuve : citer un
 * identifiant qui n'existe pas encore serait une preuve inventee. Si le patch
 * perd malgre le rejeu (`base_perimee`), le message reste — la personne l'a
 * bel et bien dit, et l'effacer reecrirait l'histoire humaine pour masquer un
 * echec technique. L'appelant recoit alors un conflit EXPLICITE, jamais un
 * accuse de reception.
 *
 * Le patch n'est pas enveloppe dans une transaction exterieure, et c'est
 * delibere : `ClaimMemory::appliquer()` indexe HORS de la sienne — un aller-
 * retour reseau par enonce. Les tenir dans une transaction englobante ferait
 * garder le verrou de la Boucle pendant ces appels.
 *
 * ## Idempotence — une correction vise une VERSION, pas un sujet
 *
 * `$versionAttendue` est la version que l'appelant a LUE et croit corriger.
 * Elle est verifiee DEUX fois : avant d'ecrire le message, et de nouveau
 * apres, sous le verrou. La premiere fait qu'une action perimee — un double
 * clic, une requete relancee, un onglet resté ouvert — ne laisse AUCUNE trace,
 * pas meme un message. La seconde ferme la fenetre restante.
 *
 * Sans elle, rejouer la meme action ecrivait deux phrases identiques dans la
 * Boucle et appliquait deux mutations, la ou la personne n'a corrige qu'une
 * fois. `version_perimee` est rendu explicitement : jamais un ACK positif.
 *
 * ## L'invariant que tout ceci sert
 *
 * Une correction humaine l'emporte sur la connaissance derivee qui la precede.
 * Mais une preuve humaine POSTERIEURE l'emporte a son tour sur la correction,
 * quand elle justifie reellement une evolution. Ce second sens est porte par
 * {@see ClaimResurrectionGuard}, cote serveur — aucun prompt ne constitue la
 * garde.
 */
final class HumanClaimCorrection
{
    /**
     * Un seul rejeu. La fenetre perdante est celle ou le compiler ecrit entre
     * notre lecture et notre verrou : elle dure le temps d'une transaction.
     * Insister au-dela ferait tourner une boucle contre un systeme qui, lui,
     * ne s'arrete pas.
     */
    private const REJEUX = 1;

    public function __construct(
        private readonly ClaimMemory $memory,
        private readonly DerivedChunkEligibility $eligibility,
        private readonly LoopMessageService $messages,
        private readonly LoopLifecycleService $lifecycle,
    ) {}

    /**
     * « C'est faux » / « ce n'est plus vrai » : l'enonce sort de la memoire, et
     * AUCUN remplacant n'est fabrique.
     *
     * @return array{ok: bool, raison: ?string, message_id: ?string}
     */
    public function retracter(Organization $organization, Loop $loop, User $user, string $subjectKey, int $versionAttendue, string $texteHumain): array
    {
        return $this->corriger($organization, $loop, $user, $subjectKey, $versionAttendue, $texteHumain, ClaimPatch::OP_RETRACT, null);
    }

    /**
     * « Ce n'est plus X, c'est Y » : l'ancien est archive, le nouveau prend la
     * version suivante, et `superseded_by_id` chaine les deux.
     *
     * @return array{ok: bool, raison: ?string, message_id: ?string}
     */
    public function mettreAJour(Organization $organization, Loop $loop, User $user, string $subjectKey, int $versionAttendue, string $texteHumain, string $nouveauTexte): array
    {
        return $this->corriger($organization, $loop, $user, $subjectKey, $versionAttendue, $texteHumain, ClaimPatch::OP_UPDATE, $nouveauTexte);
    }

    /**
     * @param  int  $versionAttendue  la version que l'appelant CROIT corriger —
     *                                voir la note d'idempotence de la classe
     * @return array{ok: bool, raison: ?string, message_id: ?string}
     */
    private function corriger(
        Organization $organization,
        Loop $loop,
        User $user,
        string $subjectKey,
        int $versionAttendue,
        string $texteHumain,
        string $operation,
        ?string $nouveauTexte,
    ): array {
        $refus = fn (string $raison): array => ['ok' => false, 'raison' => $raison, 'message_id' => null];

        // ── Les gardes, revalidees ICI, au moment de l'action ──────────────
        //
        // Aucune ne s'appuie sur ce que le front affirme. Un `subject_key`
        // recu n'est pas une autorite : il doit etre RETROUVE parmi les
        // enonces actifs de CETTE Organization et de CETTE Boucle.

        if ((string) $loop->organization_id !== (string) $organization->id) {
            return $refus('loop_hors_tenant');
        }

        if ($user->isDeactivated() || (string) $user->organization_id !== (string) $organization->id) {
            return $refus('utilisateur_hors_tenant');
        }

        if (! in_array((string) $loop->id, $this->eligibility->authorizedLoopIds((string) $organization->id, $user), true)) {
            return $refus('loop_non_autorisee');
        }

        if (! $this->lifecycle->isWritable($loop)) {
            return $refus('loop_non_ecrivable');
        }

        if (trim($texteHumain) === '') {
            return $refus('texte_humain_vide');
        }

        if ($operation === ClaimPatch::OP_UPDATE && trim((string) $nouveauTexte) === '') {
            return $refus('nouveau_texte_vide');
        }

        $dossierId = $this->eligibility->rootDossierIdFor($loop);

        if ($dossierId === null) {
            return $refus('aucun_dossier_racine');
        }

        // ── Idempotence : la correction vise une VERSION ──────────────────
        //
        // Sans cette borne, rejouer la meme action — un double clic, une
        // requete relancee — ecrivait un SECOND message de correction et
        // appliquait une SECONDE mutation. Deux phrases identiques dans la
        // Boucle, deux versions la ou la personne n'a corrige qu'une fois.
        //
        // La verification vient AVANT toute ecriture : une action perimee ne
        // doit laisser aucune trace, pas meme un message.

        $claim = $this->claimActif($organization, $loop, $subjectKey);

        if ($claim === null) {
            return $refus('claim_introuvable');
        }

        if ((int) $claim->version !== $versionAttendue) {
            return $refus('version_perimee');
        }

        // ── La preuve : le message humain, ecrit une seule fois ────────────
        //
        // `sendUserMessage()` porte deja la garde d'ecriture du produit
        // (adhesion ACTIVE, meme tenant) et l'evenement que toute la Boucle
        // ecoute. On ne la double pas d'une regle locale.

        try {
            // Le marqueur d'origine est une METADONNEE, jamais un type a part :
            // le message reste un message humain ordinaire, visible et
            // editable comme les autres. Il dit seulement a qui il s'adresse —
            // a la memoire, pas a l'agent de la Boucle
            // ({@see LoopMessage::isClaimCorrection()}).
            $message = $this->messages->sendUserMessage($loop, $user, trim($texteHumain), [
                'origin' => LoopMessage::ORIGIN_CLAIM_CORRECTION,
                'corrected_subject_key' => $subjectKey,
            ]);
        } catch (\RuntimeException) {
            return $refus('ecriture_refusee');
        }

        // REMEDIATION CODEX #8 — l'acteur de la provenance est l'auteur du
        // message, et cela se VERIFIE.
        //
        // Deux identites circulent ici : celle qui signera la provenance, et
        // celle qui a reellement ecrit la preuve. Rien ne les liait. Le jour ou
        // une UI passera un `user_id` venu du front, la provenance nommerait
        // quelqu'un qui n'a rien ecrit — une correction attribuee a un tiers,
        // et une preuve qui dit le contraire.
        // La lecture porte sur l'etat PERSISTE, pas sur l'objet qu'on vient de
        // construire : un observateur ou un abonne a `LoopMessageCreated` peut
        // reassigner l'expediteur en base, et l'instance en memoire dirait
        // encore le contraire.
        $message = $message->fresh() ?? $message;

        if ((string) $message->sender_id !== (string) $user->id) {
            return ['ok' => false, 'raison' => 'acteur_incoherent', 'message_id' => (string) $message->id];
        }

        $origine = ClaimWriteOrigin::humanCorrection($user, $message);

        for ($essai = 0; $essai <= self::REJEUX; $essai++) {
            // REMEDIATION CODEX #7 — les gardes sont revalidees a CHAQUE essai,
            // au plus pres de la mutation. Un droit peut tomber entre la
            // premiere verification et le verrou : une adhesion revoquee, un
            // compte desactive, une Boucle archivee. Le residuel assume est la
            // fenetre entre ce controle et le `lockForUpdate()` de
            // `appliquer()` — fermer celle-la demanderait de faire entrer l'ACL
            // dans la primitive de memoire, donc le refactor que la mission
            // exclut.
            $loop = $loop->fresh() ?? $loop;
            $user = $user->fresh() ?? $user;

            if ($user->isDeactivated()
                || (string) $user->organization_id !== (string) $organization->id
                || ! in_array((string) $loop->id, $this->eligibility->authorizedLoopIds((string) $organization->id, $user), true)
                || ! $this->lifecycle->isWritable($loop)) {
                return ['ok' => false, 'raison' => 'droit_revoque', 'message_id' => (string) $message->id];
            }

            $claims = $this->memory->actifs($organization, $loop);
            $empreinte = $this->memory->empreinte($claims);

            $connus = array_map(
                static fn (DerivedKnowledgeNote $c): string => (string) $c->subject_key,
                $claims,
            );

            $vise = null;

            foreach ($claims as $candidat) {
                if ((string) $candidat->subject_key === $subjectKey) {
                    $vise = $candidat;
                    break;
                }
            }

            if ($vise === null) {
                // Quelqu'un l'a corrige entre-temps. Re-poser la question n'a
                // pas de sens ici : l'enonce vise n'existe plus.
                return ['ok' => false, 'raison' => 'claim_introuvable', 'message_id' => (string) $message->id];
            }

            if ((int) $vise->version !== $versionAttendue) {
                // La base a bouge sous l'action. On ne mute RIEN : corriger une
                // version qu'on n'a pas lue reviendrait a ecraser en aveugle le
                // travail de quelqu'un d'autre.
                return ['ok' => false, 'raison' => 'version_perimee', 'message_id' => (string) $message->id];
            }

            $patch = ClaimPatch::valider([[
                'op' => $operation,
                'claim_id' => $subjectKey,
                'text' => $nouveauTexte,
                'reason' => trim($texteHumain),
                'evidence' => [(string) $message->id],
            ]], $connus, [(string) $message->id]);

            if ($patch->acceptees === []) {
                // La validation structurelle a refuse. C'est un defaut
                // d'appel, pas une concurrence : le rejeu ne le changerait pas.
                return [
                    'ok' => false,
                    'raison' => (string) ($patch->rejetees[0]['raison'] ?? 'operation_invalide'),
                    'message_id' => (string) $message->id,
                ];
            }

            $bilan = $this->memory->appliquer(
                $organization, $loop, $dossierId, $patch, [$message], $empreinte, null, $origine,
            );

            if ($bilan['applique']) {
                return ['ok' => true, 'raison' => null, 'message_id' => (string) $message->id];
            }

            if ($bilan['raison'] !== 'base_perimee') {
                return ['ok' => false, 'raison' => $bilan['raison'], 'message_id' => (string) $message->id];
            }
        }

        // Le rejeu n'a pas suffi. La correction n'est PAS enregistree, et on le
        // dit — un accuse de reception ici serait un mensonge.
        return ['ok' => false, 'raison' => 'base_perimee', 'message_id' => (string) $message->id];
    }

    private function claimActif(Organization $organization, Loop $loop, string $subjectKey): ?DerivedKnowledgeNote
    {
        foreach ($this->memory->actifs($organization, $loop) as $claim) {
            if ((string) $claim->subject_key === $subjectKey) {
                return $claim;
            }
        }

        return null;
    }
}
