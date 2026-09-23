<?php

namespace App\Ai\MultiAssistant;

use App\Support\Ai\AiTurnReason;

/**
 * Ce qu'UN assistant a produit — ou pourquoi il n'a rien produit.
 * (TASK-1618)
 *
 * Structure de retour du CDC §12. Un echec ne fait PAS tomber les voisins :
 * chaque assistant rend son propre verdict, et l'orchestrateur rend les
 * trois. SLICE E decidera comment l'interface les montre ; ici on garantit
 * seulement qu'un succes obtenu n'est jamais perdu a cause d'un echec
 * ulterieur.
 */
final class AssistantOutcome
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_ERROR = 'error';

    /**
     * TASK-1621 — le modele a repondu, mais il a ete COUPE.
     *
     * Ni `success` ni `error` : il y a du texte a publier ET un defaut a dire.
     * Le ranger dans `success` reviendrait a affirmer que la reponse est
     * complete — c'est ce que faisait le code, et ce que la recette a montre
     * (« …moderniser les infrastructures federales et ree »). Le ranger dans
     * `error` reviendrait a jeter un appel deja paye qui a produit 80 % d'une
     * reponse utile.
     */
    public const STATUS_PARTIAL = 'partial';

    /**
     * TASK-1621 — la question ne se prete pas a un pour / contre.
     *
     * Ni `success`, ni `error`, ni `refused` : l'appel a eu lieu et a abouti,
     * mais il n'y a aucun camp a distribuer. Le ranger dans `error` ferait
     * afficher « n'a pas pu repondre » a un membre dont la question etait
     * simplement d'une autre nature.
     */
    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    /** Refus AVANT tout appel provider : aucune invocation, aucun cout. */
    public const STATUS_REFUSED = 'refused';

    /**
     * Le modele gratuit n'a pas pu repondre MAINTENANT (HTTP 429).
     * (TASK-1619)
     *
     * Distinct de toute autre panne, et ce n'est pas un detail de
     * classification : c'est le seul echec dont le remede soit « reessayer
     * dans un instant ». Une panne quelconque ne se reessaie pas, elle se
     * signale ; une saturation, si. Confondre les deux donnerait au membre un
     * bouton qui ne sert a rien, ou le priverait du seul qui serve.
     *
     * Le pool gratuit PARTAGE d'OpenRouter est sature regulierement — la
     * recette de TASK-1618 l'a rencontre sur les trois modeles et trois
     * fournisseurs amont differents. Ce n'est donc pas un cas rare a traiter
     * plus tard : c'est le cas nominal d'un palier gratuit.
     */
    public const ERROR_RATE_LIMITED = 'RATE_LIMITED';

    /**
     * @param  list<array<string, mixed>>  $sources
     * @param  list<string>  $followUps
     */
    private function __construct(
        public readonly string $assistantKey,
        public readonly string $status,
        public readonly ?string $answer,
        public readonly ?string $errorCode,
        public readonly array $sources,
        public readonly ?string $turnId,
        public readonly ?string $model,
        /**
         * TASK-1619 — « Pour aller plus loin », produites DANS le meme tour
         * provider que la reponse. Jamais un second appel : le precedent est
         * TASK-1595, et il tient pour la meme raison ici — une question
         * suggeree ne vaut pas une generation de plus.
         *
         * @var list<string>
         */
        public readonly array $followUps = [],
        /**
         * TASK-1622 — la reformulation proposee quand la question n'ouvre
         * aucun debat. Produite par le MEME appel provider que l'abstention :
         * il n'y a pas de second appel, et il ne doit pas y en avoir.
         *
         * Un canal DEDIE, et pas `$followUps` : une question
         * d'approfondissement prolonge une reponse, une reformulation
         * REMPLACE la question. Les ranger ensemble aurait fait porter deux
         * intentions au meme champ, et le premier lecteur distrait aurait
         * affiche l'une pour l'autre.
         *
         * `null` quand le modele n'a rien propose de fidele — l'ecran demande
         * alors une precision au lieu d'inventer une opposition.
         */
        public readonly ?string $suggestion = null,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $sources
     * @param  list<string>  $followUps
     */
    public static function success(string $key, string $answer, array $sources, string $turnId, string $model, array $followUps = []): self
    {
        return new self($key, self::STATUS_SUCCESS, $answer, null, $sources, $turnId, $model, $followUps);
    }

    public static function error(string $key, string $errorCode, ?string $turnId = null, ?string $model = null): self
    {
        return new self($key, self::STATUS_ERROR, null, $errorCode, [], $turnId, $model);
    }

    /**
     * Le tour s'abstient : rien a debattre, donc rien a publier.
     *
     * L'appel EST parti — il a sa ligne au ledger. Ce qui n'existe pas, c'est
     * un camp a defendre.
     */
    public static function notApplicable(string $key, string $turnId, string $model, ?string $suggestion = null): self
    {
        return new self($key, self::STATUS_NOT_APPLICABLE, null, AiTurnReason::TERMINAL_NO_DEBATABLE_PROPOSITION, [], $turnId, $model, [], $suggestion);
    }

    /**
     * Une reponse ECOURTEE : publiable, mais jamais comptee comme complete.
     *
     * @param  list<array<string, mixed>>  $sources
     */
    public static function partial(string $key, string $answer, string $errorCode, string $turnId, string $model, array $sources = []): self
    {
        return new self($key, self::STATUS_PARTIAL, $answer, $errorCode, $sources, $turnId, $model);
    }

    /**
     * Refuse AVANT le provider — modele absent, preuve perimee, plus gratuit,
     * budget atteint. Distinct de `error` : il n'y a eu aucun appel, donc
     * aucune ligne au ledger, et c'est une information, pas une omission.
     *
     * Le `turnId` existe quand meme : le refus est un TOUR, il porte sa trace
     * metier (assistant, correlation, raison). Sans lui, un refus serait la
     * seule chose que l'inspection ne saurait pas nommer.
     */
    public static function refused(string $key, string $errorCode, ?string $turnId = null): self
    {
        return new self($key, self::STATUS_REFUSED, null, $errorCode, [], $turnId, null);
    }

    /**
     * Le modele est sature. L'appel EST parti — il a donc sa ligne au ledger,
     * comme tout appel emis — mais rien n'est revenu.
     */
    public static function rateLimited(string $key, ?string $turnId = null, ?string $model = null): self
    {
        return new self($key, self::STATUS_ERROR, null, self::ERROR_RATE_LIMITED, [], $turnId, $model);
    }

    /**
     * Peut-on proposer « Reessayer » ?
     *
     * La saturation le merite — elle passe. Et desormais une reponse ecourtee
     * aussi : elle a une chance d'aboutir entiere au tour suivant, ce qui
     * n'est vrai d'aucun autre echec de ce moteur.
     */
    public function isRetryable(): bool
    {
        return $this->errorCode === self::ERROR_RATE_LIMITED
            || $this->status === self::STATUS_PARTIAL;
    }

    /**
     * STRICT, et c'est le point. Une reponse ecourtee n'est PAS une reussite :
     * si elle l'etait, aucune mesure ne distinguerait plus une reponse entiere
     * d'une reponse coupee, et on reviendrait exactement au defaut corrige.
     */
    public function succeeded(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * Y a-t-il un texte a mettre dans le fil ?
     *
     * C'est CETTE question que le publisher doit poser, pas « est-ce une
     * reussite ». Les deux se confondaient tant qu'il n'y avait que deux
     * etats.
     */
    public function isPublishable(): bool
    {
        return ($this->status === self::STATUS_SUCCESS || $this->status === self::STATUS_PARTIAL)
            && is_string($this->answer)
            && trim($this->answer) !== '';
    }

    /** La reponse est-elle coupee ? */
    public function isTruncated(): bool
    {
        return $this->status === self::STATUS_PARTIAL;
    }

    /** La question ne se prete-t-elle pas a un pour / contre ? */
    public function isNotApplicable(): bool
    {
        return $this->status === self::STATUS_NOT_APPLICABLE;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'assistant' => $this->assistantKey,
            'status' => $this->status,
            'answer' => $this->answer,
            'error_code' => $this->errorCode,
            'sources' => $this->sources,
            'turn_id' => $this->turnId,
            'model' => $this->model,
            'follow_ups' => $this->followUps,
            'suggestion' => $this->suggestion,
        ];
    }
}
