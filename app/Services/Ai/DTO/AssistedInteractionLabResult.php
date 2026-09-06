<?php

namespace App\Services\Ai\DTO;

/**
 * TASK-1321 : `$suggestedLoop`, quand non null, a la forme
 * `{id, label, provenance: {verified: list<{type, loop_id}>, ai_wording: {text, verified: false}|null}}`.
 * `provenance.verified` est un fait reconstruit cote serveur au point de
 * validation (jamais transmis tel quel) ; `provenance.ai_wording` est le
 * texte libre du modele, toujours marque `verified: false` — jamais presente
 * comme une preuve.
 */
class AssistedInteractionLabResult
{
    public function __construct(
        public readonly string $intent,
        public readonly float $confidence,
        public readonly string $title,
        public readonly string $need,
        public readonly string $context,
        public readonly string $expectedHelpType,
        public readonly array $deadline,
        public readonly ?array $suggestedLoop,
        public readonly array $tone,
        public readonly ?string $messageDraft,
        public readonly array $fallback,
        public readonly array $humanValidation,
        public readonly array $safety,
        public readonly string $scenario,
        public readonly string $scenarioLabel,
        public readonly string $originalPhrase = '',
        public readonly ?array $suggestedCategory = null,
        public readonly string $producer = 'unknown',
        /**
         * TASK-1350 — verdict d'Interaction, DEJA arbitre par la version du
         * prompt au point de production.
         *
         *  - `true`  : un autre membre pourrait utilement contribuer ;
         *  - `false` : l'enonce est clairement hors Interaction ;
         *  - `null`  : AUCUNE autorite — prompt en v1/v2, version non
         *              exploitable, champ absent ou non booleen, ou repli
         *              deterministe. Un appelant qui lit `null` doit se
         *              comporter exactement comme avant TASK-1350.
         *
         * Le fail-open est donc porte par le TYPE : seul `false` change
         * quelque chose, et seulement chez qui choisit de le lire.
         */
        public readonly ?bool $interactionFit = null,
        /**
         * TASK-1350 — la reponse conversationnelle du modele au message
         * COURANT, quand celui-ci n'appelle pas d'Interaction collective.
         *
         * `null` quand le champ est absent, vide, ou quand le prompt actif n'a
         * pas l'autorite pour le produire (v1/v2). Un appelant qui lit `null`
         * doit disposer d'un repli sur : ce champ est un CONFORT, jamais une
         * garantie.
         */
        public readonly ?string $directReply = null,
    ) {}

    public function toArray(): array
    {
        return [
            'intent' => $this->intent,
            'confidence' => $this->confidence,
            'title' => $this->title,
            'need' => $this->need,
            'context' => $this->context,
            'expected_help_type' => $this->expectedHelpType,
            'deadline' => $this->deadline,
            'suggested_loop' => $this->suggestedLoop,
            'suggested_category' => $this->suggestedCategory,
            'tone' => $this->tone,
            'message_draft' => $this->messageDraft,
            'fallback' => $this->fallback,
            'human_validation' => $this->humanValidation,
            'safety' => $this->safety,
            '_scenario' => $this->scenario,
            '_scenario_label' => $this->scenarioLabel,
            'original_phrase' => $this->originalPhrase,
            '_producer' => $this->producer,
            'interaction_fit' => $this->interactionFit,
            'direct_reply' => $this->directReply,
        ];
    }

    public function needsFallback(): bool
    {
        return $this->fallback['needed'] ?? false;
    }

    public function isBlocked(): bool
    {
        return $this->safety['blocked'] ?? false;
    }

    public function hasSensitiveData(): bool
    {
        return $this->safety['contains_sensitive_data'] ?? false;
    }

    public function isHighConfidence(): bool
    {
        return $this->confidence >= 0.65;
    }

    public function isLowConfidence(): bool
    {
        return $this->confidence < 0.65;
    }
}
