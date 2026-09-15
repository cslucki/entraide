<?php

namespace App\Support\Ai;

use App\Services\Ai\AiShellResponder;

/**
 * TASK-1557 — W3F-min : la FRONTIERE minimale des etats d'un tour, et sa
 * projection vers l'humain.
 *
 * Ce que cette classe est : un value-object PUR. Elle lit des tableaux, ne
 * touche ni base ni provider, et rend une decision reproductible. Ce qu'elle
 * n'est PAS : un Verifier, un Distiller, un orchestrateur, un moteur. W3F-min
 * pose le contrat ; les detecteurs arrivent dans leurs propres waves.
 *
 * ## Trois axes DISTINCTS — jamais une enum globale
 *
 * La tentation permanente est d'ecrire un seul champ « etat du tour ». Elle est
 * fausse, et la doctrine V3.1 (CDC CORE §12.2) donne le contre-exemple exact :
 *
 * - un tour peut etre `answered` ET `insufficient` — il a repondu honnetement
 *   qu'il ne peut rien etayer ;
 * - un tour peut etre `answered` ET degrade — il a repondu sur un corpus ampute ;
 * - un tour `unavailable` n'a AUCUN `verification_status` — rien n'a ete
 *   affirme, il n'y a rien a verifier.
 *
 * Fusionner deux de ces axes rend l'une de ces trois situations inexprimable.
 *
 * ## Ce que la mesure a etabli avant d'ecrire cette classe
 *
 * Sur la base reelle, 114 tours assistant sur 114 portent deja `status`, et 65
 * portent `grounded`, dont 6 a `false`. **Deux des trois axes existaient donc
 * deja, sous un autre nom.** Cette classe les NOMME ; elle n'invente que le
 * troisieme, et seulement la ou la cause est deja typee.
 *
 * ## G/H est STRUCTUREL ici, pas une discipline de redaction
 *
 * Les raisons de degradation sont classees en DEUX familles, et une seule
 * franchit la frontiere :
 *
 *   PRE-BOUNDARY (ACL / tenant / Loop / source refusee)
 *       -> TRACE / admin uniquement. N'entre JAMAIS dans `rule()`.
 *   TECHNIQUE (panne provider, preparation indisponible, echec partiel)
 *       -> projetable, sans jamais nommer de code technique brut.
 *
 * La consequence est ce qui rend le gate tenable : un `insufficient` AVEC source
 * interdite et un `insufficient` SANS rendent la MEME projection, parce que la
 * raison pre-boundary n'est pas un parametre de la regle. Le compteur ne peut
 * pas fuir — il n'existe pas dans la projection.
 *
 * Tenir cela par prudence de wording aurait tenu jusqu'a la premiere phrase
 * ecrite par quelqu'un qui ne connaissait pas la regle.
 */
final class AiTurnState
{
    // ───────────────────────────── axe 1 : qu'a fait le tour ?

    /**
     * Reprend EXACTEMENT les constantes d'`AiShellResponder` — ce ne sont pas
     * des valeurs nouvelles, c'est le meme vocabulaire, nomme comme axe.
     */
    public const TURN_ANSWERED = AiShellResponder::STATUS_ANSWERED;

    public const TURN_NON_INTERACTION = AiShellResponder::STATUS_NON_INTERACTION;

    public const TURN_BLOCKED = AiShellResponder::STATUS_BLOCKED;

    public const TURN_UNAVAILABLE = AiShellResponder::STATUS_UNAVAILABLE;

    /**
     * TASK-1566 / CDC-01 V0-A — les trois issues que le produit SAIT produire
     * mais ne SAVAIT PAS DIRE.
     *
     * Elles n'existent nulle part ailleurs dans le depot : contrairement aux
     * quatre ci-dessus, empruntees au Shell, celles-ci sont nouvelles — parce
     * que les etats qu'elles nomment n'ont jamais eu de nom. Un tour qui
     * s'abstient faute de source, un refus economique pre-provider, une erreur
     * controlee : tous trois se terminent aujourd'hui sans qu'aucune ligne ne
     * dise lequel des trois s'est produit.
     *
     * Elles sont DECLAREES ici en V0-A, ou le schema du tour se fixe. Les
     * chemins qui les EMETTENT sont V0-B (arrets anticipes). Declarer avant
     * d'emettre est deliberé : c'est ce qui permet au lecteur et au test de
     * connaitre le vocabulaire complet avant que les producteurs n'arrivent,
     * plutot que de le decouvrir au fil des TASKs.
     */
    public const TURN_ABSTAINED = 'abstained';

    public const TURN_REFUSED = 'refused';

    public const TURN_FAILED = 'failed';

    // ──────────────────── axe 2 : l'affirmation est-elle etayee ?

    public const VERIFICATION_SUPPORTED = 'supported';

    public const VERIFICATION_CONTRADICTED = 'contradicted';

    public const VERIFICATION_INSUFFICIENT = 'insufficient';

    public const VERIFICATION_STALE_UNCERTAIN = 'stale_uncertain';

    /**
     * Un tour qui ne pose AUCUNE affirmation documentaire — People, Self,
     * reference, temporel. 49 tours sur 114 en base.
     *
     * Ni `supported` (faux positif : rien n'a ete etaye), ni `insufficient`
     * (faux negatif : rien n'avait a l'etre). La valeur par defaut d'un axe
     * n'est jamais sa valeur la plus rassurante.
     */
    public const VERIFICATION_NOT_APPLICABLE = 'not_applicable';

    /** @var list<string> */
    private const VERIFICATION_VALUES = [
        self::VERIFICATION_SUPPORTED,
        self::VERIFICATION_CONTRADICTED,
        self::VERIFICATION_INSUFFICIENT,
        self::VERIFICATION_STALE_UNCERTAIN,
        self::VERIFICATION_NOT_APPLICABLE,
    ];

    // ──────────────── axe 3 : pourquoi le resultat est-il amoindri ?

    /**
     * Raisons TECHNIQUES — projetables.
     */
    public const DEGRADED_PROVIDER_UNAVAILABLE = 'provider_unavailable';

    public const DEGRADED_REQUEST_PREPARATION_UNAVAILABLE = 'request_preparation_unavailable';

    public const DEGRADED_PARTIAL_FAILURE = 'partial_failure';

    /**
     * Raisons PRE-BOUNDARY — trace/admin uniquement, JAMAIS projetees.
     *
     * Elles existent ici pour etre TRACEES et DISTINGUEES, pas pour etre
     * montrees : la regle G/H demande explicitement que les causes reelles
     * restent lisibles cote admin. Les taire dans la trace ne protegerait rien
     * et rendrait le produit indiagnosticable.
     */
    public const DEGRADED_SOURCE_DENIED = 'source_denied';

    public const DEGRADED_TENANT_SCOPE = 'tenant_scope';

    public const DEGRADED_LOOP_SCOPE = 'loop_scope';

    /**
     * @var list<string>
     */
    public const PRE_BOUNDARY_REASONS = [
        self::DEGRADED_SOURCE_DENIED,
        self::DEGRADED_TENANT_SCOPE,
        self::DEGRADED_LOOP_SCOPE,
    ];

    // ─────────────────────────────────── les sept projections

    /** Ambiguite materielle non resolue -> UNE clarification. */
    public const R1_CLARIFICATION = 'R1';

    /** Indisponibilite technique empechant toute reponse -> dit honnetement. */
    public const R2_UNAVAILABLE = 'R2';

    /** Preuves autorisees contradictoires -> contradiction attribuee/datee. */
    public const R3_CONTRADICTED = 'R3';

    /** Preuves autorisees insuffisantes -> trouve + manquant. */
    public const R4_INSUFFICIENT = 'R4';

    /** Supporte mais perime/incertain -> reponse qualifiee/datee. */
    public const R5_STALE = 'R5';

    /** Supporte, mais une partie demandee est perdue par PANNE -> une note. */
    public const R6_PARTIAL = 'R6';

    /** Supporte, rien a signaler -> reponse directe. */
    public const R7_SUPPORTED = 'R7';

    /**
     * @param  string|null  $turnStatus  axe 1 ; `null` = le tour n'en declare
     *                                   pas. Mesure : 0 cas sur 114 en base,
     *                                   la garde est defensive.
     * @param  string  $verificationStatus  axe 2
     * @param  string|null  $degradedReason  axe 3 ; `null` = aucune degradation
     * @param  bool  $awaitingClarification  entree DERIVEE, PAS un quatrieme
     *                                       axe : porte par
     *                                       `clarification_questions`, un etat
     *                                       produit et non un etat de frontiere
     */
    public function __construct(
        public readonly ?string $turnStatus,
        public readonly string $verificationStatus = self::VERIFICATION_NOT_APPLICABLE,
        public readonly ?string $degradedReason = null,
        public readonly bool $awaitingClarification = false,
    ) {}

    /**
     * LA lecture canonique. Sur des TABLEAUX : aucune requete, aucun appel
     * provider, aucun effet de bord — la meme entree rend toujours la meme
     * sortie, ce qui la rend testable sans fixture.
     *
     * `$interaction` est la metadata d'`AiInteraction` quand l'appelant l'a
     * DEJA lue (le Shell porte `ai_interaction_id`). Elle n'est jamais chargee
     * ici : cette classe ne doit pas pouvoir declencher une requete.
     *
     * @param  array<string, mixed>  $turn  `AiShellMessage.metadata`
     * @param  array<string, mixed>|null  $interaction  `AiInteraction.metadata`
     */
    public static function fromTurnMetadata(array $turn, ?array $interaction = null): self
    {
        $status = is_string($turn['status'] ?? null) ? $turn['status'] : null;

        return new self(
            turnStatus: $status,
            verificationStatus: self::verificationFrom($turn),
            degradedReason: self::degradedFrom($turn, $interaction),
            awaitingClarification: self::clarificationPending($turn),
        );
    }

    /**
     * TASK-1566 / CDC-01 V0-A — la lecture du BLOC `turn`, entree neuve.
     *
     * ## Pourquoi une seconde entree, et pas une extension de la premiere
     *
     * Le mot « turn » designe DEUX choses differentes dans ce fichier, et les
     * confondre serait la premiere erreur a commettre :
     *
     *   `fromTurnMetadata($turn)`  -> `$turn` = la metadata COMPLETE d'un
     *                                 `AiShellMessage`, format historique ;
     *   `fromTurnBlock($turnBlock)` -> `$turnBlock` = le bloc `metadata['turn']`
     *                                 du schema canonique v1 (TASK-1566), un
     *                                 SOUS-ENSEMBLE d'une metadata.
     *
     * Les deux coexistent volontairement : l'ancien format reste lu tel quel
     * (compatibilite, CDC-01 §11), le nouveau est lu sans heuristique. Fusionner
     * les deux signatures obligerait a deviner, a l'execution, lequel des deux
     * formats on tient — exactement le genre d'inference que l'invariant I12
     * interdit.
     *
     * ## Ce qu'elle ne fait jamais
     *
     * Aucune derivation depuis `grounded`, aucune valeur par defaut
     * rassurante, aucune requete. Un champ absent du bloc rend l'etat le plus
     * neutre (`not_applicable` / `null`) — jamais une valeur inventee.
     *
     * @param  array<string, mixed>  $turnBlock  le contenu de `metadata['turn']`
     */
    public static function fromTurnBlock(array $turnBlock): self
    {
        $status = is_string($turnBlock['status'] ?? null) ? $turnBlock['status'] : null;
        $state = is_array($turnBlock['state'] ?? null) ? $turnBlock['state'] : [];

        $verification = $state['verification_status'] ?? null;
        $degraded = $state['degraded_reason'] ?? null;

        return new self(
            turnStatus: $status,
            // NULL RESTE NULL : un bloc qui ne declare pas son axe 2 rend
            // `not_applicable`, jamais `supported`. Aucune derivation depuis
            // `grounded` ici — c'est precisement ce que `fromTurnMetadata()`
            // fait pour l'ANCIEN format, faute de mieux. Le bloc `turn`, lui,
            // ecrit ce qu'il a mesure ou n'ecrit rien.
            verificationStatus: is_string($verification) && in_array($verification, self::VERIFICATION_VALUES, true)
                ? $verification
                : self::VERIFICATION_NOT_APPLICABLE,
            degradedReason: is_string($degraded) && $degraded !== '' ? $degraded : null,
            // AUCUNE inference : l'attente de clarification est un etat produit
            // que le bloc porte explicitement ou pas du tout.
            awaitingClarification: ($turnBlock['awaiting_clarification'] ?? null) === true,
        );
    }

    /**
     * L'axe 2, derive de ce que le repo ecrit DEJA.
     *
     * La cle `grounded` est le discriminateur documentaire reel : seuls les
     * producteurs qui ont cite des sources l'ecrivent. Son ABSENCE est donc une
     * information, pas un trou — et c'est pour cela qu'elle rend
     * `not_applicable` plutot qu'une valeur par defaut rassurante.
     *
     * @param  array<string, mixed>  $turn
     */
    private static function verificationFrom(array $turn): string
    {
        // TASK-1557 : une valeur explicite l'emporte — c'est par elle que les
        // futurs detecteurs (W3E) se brancheront, et c'est aussi par elle que
        // `contradicted` / `stale_uncertain` se testent aujourd'hui, faute de
        // producteur reel. Aucun faux Verifier n'est fabrique pour les emettre.
        $explicite = $turn['verification_status'] ?? null;

        if (is_string($explicite) && in_array($explicite, self::VERIFICATION_VALUES, true)) {
            return $explicite;
        }

        if (! array_key_exists('grounded', $turn)) {
            return self::VERIFICATION_NOT_APPLICABLE;
        }

        return $turn['grounded'] === true
            ? self::VERIFICATION_SUPPORTED
            : self::VERIFICATION_INSUFFICIENT;
    }

    /**
     * L'axe 3.
     *
     * Ordre de lecture : la raison ECRITE par le tour l'emporte sur toute
     * deduction — c'est la seule qui sache laquelle des causes s'est produite.
     * A defaut, une source refusee tracee sur l'interaction (T1554) donne une
     * raison PRE-BOUNDARY, qui sera tracee et jamais projetee.
     *
     * Ce qui n'entre PAS ici, et c'est deliberé : un zero hit, une preuve
     * insuffisante, un document absent. Ce sont des etats de l'axe 2, pas des
     * degradations — les compter comme telles fabriquerait une panne la ou il
     * n'y a qu'une absence.
     *
     * @param  array<string, mixed>  $turn
     * @param  array<string, mixed>|null  $interaction
     */
    private static function degradedFrom(array $turn, ?array $interaction): ?string
    {
        $ecrite = $turn['degraded_reason'] ?? null;

        if (is_string($ecrite) && $ecrite !== '') {
            return $ecrite;
        }

        $denied = $interaction['sources_denied'] ?? null;

        if (is_array($denied) && $denied !== []) {
            return self::DEGRADED_SOURCE_DENIED;
        }

        return null;
    }

    /** @param  array<string, mixed>  $turn */
    private static function clarificationPending(array $turn): bool
    {
        $questions = $turn['clarification_questions'] ?? null;

        return is_array($questions)
            && array_values(array_filter($questions)) !== [];
    }

    /**
     * Une raison de degradation franchit-elle la frontiere ?
     *
     * C'est LA fonction qui rend G/H structurel. Tout ce qui est pre-boundary
     * est invisible de `rule()` : aucune branche de projection ne peut donc,
     * meme par erreur d'ecriture future, faire dependre un rendu de l'existence
     * d'une source interdite.
     */
    public static function isProjectable(?string $reason): bool
    {
        return $reason !== null
            && $reason !== ''
            && ! in_array($reason, self::PRE_BOUNDARY_REASONS, true);
    }

    /**
     * La regle R1..R7. **Fonction pure**, et l'ordre des branches est le
     * contrat : la premiere qui matche gagne.
     *
     * L'ordre n'est pas arbitraire — il va du plus bloquant au plus normal :
     * une indisponibilite totale prime sur une question de clarification, qui
     * prime sur un verdict de verification, qui prime sur une note technique.
     */
    public function rule(): string
    {
        // R2 — rien n'a pu etre produit. Aucun autre axe n'a de sens ici : il
        // n'y a pas d'affirmation a verifier ni de reponse a qualifier.
        if ($this->turnStatus === self::TURN_UNAVAILABLE) {
            return self::R2_UNAVAILABLE;
        }

        // R1 — le tour a produit une question, pas une reponse. Elle passe
        // avant tout verdict : il n'y a encore rien a etayer.
        if ($this->awaitingClarification) {
            return self::R1_CLARIFICATION;
        }

        if ($this->verificationStatus === self::VERIFICATION_CONTRADICTED) {
            return self::R3_CONTRADICTED;
        }

        // R4 — et c'est ICI que G/H se joue. La regle ne consulte PAS
        // `degradedReason` : un `insufficient` avec source interdite et un
        // `insufficient` sans rendent donc rigoureusement la meme projection.
        if ($this->verificationStatus === self::VERIFICATION_INSUFFICIENT) {
            return self::R4_INSUFFICIENT;
        }

        if ($this->verificationStatus === self::VERIFICATION_STALE_UNCERTAIN) {
            return self::R5_STALE;
        }

        // R6 — une reponse tient, mais une PANNE a coute une partie de ce qui
        // etait demande. Seule une raison TECHNIQUE y mene : une source refusee
        // ne produit rien de visible, par construction.
        if (self::isProjectable($this->degradedReason)) {
            return self::R6_PARTIAL;
        }

        return self::R7_SUPPORTED;
    }

    /**
     * La projection user-facing : **au plus UNE** phrase meta globale, sous
     * forme de cle i18n. `null` = rien a dire, et c'est le cas nominal.
     *
     * Jamais un code technique, jamais un identifiant, jamais un compteur.
     * R1 et R7 ne rendent rien : la question de clarification et la reponse
     * elle-meme SONT deja la surface — ajouter une phrase au-dessus serait un
     * doublon bavard.
     */
    public function userFacingKey(): ?string
    {
        return match ($this->rule()) {
            self::R2_UNAVAILABLE => 'ai.turn_state_unavailable',
            self::R3_CONTRADICTED => 'ai.turn_state_contradicted',
            self::R4_INSUFFICIENT => 'ai.turn_state_insufficient',
            self::R5_STALE => 'ai.turn_state_stale',
            self::R6_PARTIAL => 'ai.turn_state_partial',
            default => null,
        };
    }

    /**
     * La forme TRACE / admin : les trois axes, **distinguables**, y compris les
     * raisons pre-boundary que la projection tait.
     *
     * C'est l'autre moitie de G/H, et elle va dans le sens inverse de
     * l'intuition : ecraser refus ACL, panne provider et corpus vide en un
     * « indisponible » unique rendrait le produit indiagnosticable — et la
     * fuite se produirait ailleurs, la ou personne ne regarde.
     *
     * @return array{shell_turn_status: ?string, verification_status: string, degraded_reason: ?string, rule: string}
     */
    public function toTrace(): array
    {
        return [
            'shell_turn_status' => $this->turnStatus,
            'verification_status' => $this->verificationStatus,
            'degraded_reason' => $this->degradedReason,
            'rule' => $this->rule(),
        ];
    }
}
