<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\DTO\AssistedInteractionLabResult;

class FakeAIProvider implements AiProvider
{
    protected array $scenarios;

    public function __construct()
    {
        $this->scenarios = $this->loadScenarios();
    }

    public function analyze(string $phrase): AssistedInteractionLabResult
    {
        $scenario = $this->matchScenario($phrase);

        return new AssistedInteractionLabResult(
            intent: $scenario['intent'],
            confidence: $scenario['confidence'],
            title: $scenario['title'],
            need: $scenario['need'],
            context: $scenario['context'],
            expectedHelpType: $scenario['expected_help_type'],
            deadline: $scenario['deadline'],
            suggestedLoop: $scenario['suggested_loop'],
            tone: $scenario['tone'],
            messageDraft: $scenario['message_draft'],
            fallback: $scenario['fallback'],
            humanValidation: $scenario['human_validation'],
            safety: $scenario['safety'],
            scenario: $scenario['_scenario'],
            scenarioLabel: $scenario['_scenario_label'],
        );
    }

    public function getScenarios(): array
    {
        return $this->scenarios;
    }

    protected function matchScenario(string $phrase): array
    {
        $normalized = mb_strtolower(trim($phrase));
        $bestMatch = null;
        $bestLength = 0;

        foreach ($this->scenarios as $key => $scenario) {
            if ($key === 'fallback') {
                continue;
            }

            foreach ($scenario['_triggers'] as $trigger) {
                $lowerTrigger = mb_strtolower($trigger);
                if (str_contains($normalized, $lowerTrigger)) {
                    $len = mb_strlen($lowerTrigger);
                    if ($len > $bestLength) {
                        $bestLength = $len;
                        $bestMatch = $scenario;
                    }
                }
            }
        }

        return $bestMatch ?? $this->scenarios['fallback'];
    }

    protected function loadScenarios(): array
    {
        return [
            'besoin_client_clair' => [
                '_scenario' => 'besoin_client_clair',
                '_scenario_label' => 'Besoin client clair',
                '_triggers' => ['trouver mes premiers clients', 'premiers clients', 'aider pour trouver des clients'],
                'intent' => 'help_request',
                'confidence' => 0.84,
                'title' => 'Trouver mes premiers clients',
                'need' => 'Identifier des pistes concrètes pour obtenir mes premiers clients.',
                'context' => 'La personne lance son activité et cherche des retours d\'expérience ou des contacts utiles.',
                'expected_help_type' => 'conseils, retours d\'expérience, mise en relation',
                'deadline' => ['has_deadline' => false, 'label' => null, 'date' => null],
                'suggested_loop' => [
                    'id' => 'loop-dev-commercial',
                    'label' => 'Développement commercial',
                    'reason' => 'Cette Boucle regroupe les membres qui échangent sur la prospection et les premiers clients.',
                ],
                'tone' => [
                    'label' => 'clair et accessible',
                    'rationale' => 'Le message doit rester humain, direct et non commercial.',
                ],
                'message_draft' => 'Bonjour, je cherche des conseils pour trouver mes premiers clients. Si vous avez déjà traversé cette étape, je serais preneur de retours concrets, d\'idées de démarchage ou de contacts à explorer.',
                'fallback' => ['needed' => false, 'reason' => null, 'questions' => []],
                'human_validation' => ['required' => true, 'primary_label' => 'Valider la preview', 'secondary_label' => 'Modifier le brouillon'],
                'safety' => ['contains_sensitive_data' => false, 'needs_human_review' => false, 'blocked' => false],
            ],

            'demande_trop_vague' => [
                '_scenario' => 'demande_trop_vague',
                '_scenario_label' => 'Demande trop vague',
                '_triggers' => ['je suis bloqué', 'je suis perdu', 'je ne sais pas', 'bloqué'],
                'intent' => 'unknown',
                'confidence' => 0.35,
                'title' => 'Je suis bloqué',
                'need' => 'La demande manque de précision pour être reformulée.',
                'context' => 'La phrase est trop courte ou trop vague pour déterminer un besoin précis.',
                'expected_help_type' => 'clarification',
                'deadline' => ['has_deadline' => false, 'label' => null, 'date' => null],
                'suggested_loop' => null,
                'tone' => [
                    'label' => 'neutre et aidant',
                    'rationale' => 'Le ton doit encourager à préciser la demande sans pression.',
                ],
                'message_draft' => null,
                'fallback' => [
                    'needed' => true,
                    'reason' => 'Confiance trop faible pour générer un brouillon publiable.',
                    'questions' => [
                        'Sur quel aspect précis de ton activité es-tu bloqué ?',
                        'Quel type d\'aide cherches-tu : conseil, mise en relation, relecture ?',
                    ],
                ],
                'human_validation' => ['required' => true, 'primary_label' => 'Modifier la phrase', 'secondary_label' => 'Reformuler'],
                'safety' => ['contains_sensitive_data' => false, 'needs_human_review' => false, 'blocked' => false],
            ],

            'demande_avec_deadline' => [
                '_scenario' => 'demande_avec_deadline',
                '_scenario_label' => 'Demande avec deadline',
                '_triggers' => ['avant vendredi', 'avant', 'deadline', 'urgence', 'vite'],
                'intent' => 'help_request',
                'confidence' => 0.78,
                'title' => 'Relecture d\'offre avant vendredi',
                'need' => 'Obtenir une relecture rapide et des retours constructifs sur une offre avant une échéance.',
                'context' => 'La personne a une offre à finaliser et cherche un regard extérieur dans un délai contraint.',
                'expected_help_type' => 'relecture, conseil rédactionnel',
                'deadline' => ['has_deadline' => true, 'label' => 'cette semaine', 'date' => '2026-05-22'],
                'suggested_loop' => [
                    'id' => 'loop-communication',
                    'label' => 'Communication & rédaction',
                    'reason' => 'Cette Boucle traite des sujets de rédaction, relecture et communication professionnelle.',
                ],
                'tone' => [
                    'label' => 'direct et efficace',
                    'rationale' => 'La deadline justifie un ton plus direct pour rester utile rapidement.',
                ],
                'message_draft' => 'Bonjour, j\'aurais besoin d\'une relecture rapide de mon offre avant vendredi. Si certains d\'entre vous ont un œil pour la rédaction commerciale, je suis preneur de retours concrets pour améliorer mon texte.',
                'fallback' => ['needed' => false, 'reason' => null, 'questions' => []],
                'human_validation' => ['required' => true, 'primary_label' => 'Valider la preview', 'secondary_label' => 'Modifier le brouillon'],
                'safety' => ['contains_sensitive_data' => false, 'needs_human_review' => false, 'blocked' => false],
            ],

            'mauvais_canal' => [
                '_scenario' => 'mauvais_canal',
                '_scenario_label' => 'Mauvais canal (promotion)',
                '_triggers' => ['vendre mon service', 'vendre à tout le monde', 'promouvoir', 'publipostage'],
                'intent' => 'help_request',
                'confidence' => 0.55,
                'title' => 'Présenter mon service',
                'need' => 'La personne souhaite présenter son service, mais le ton est trop promotionnel pour une demande d\'entraide.',
                'context' => 'L\'approche commerciale directe n\'est pas adaptée au format d\'entraide. Une reformulation plus ouverte est nécessaire.',
                'expected_help_type' => 'conseils, retours constructifs',
                'deadline' => ['has_deadline' => false, 'label' => null, 'date' => null],
                'suggested_loop' => null,
                'tone' => [
                    'label' => 'prudent et non promotionnel',
                    'rationale' => 'Rappeler l\'esprit d\'entraide sans bloquer la demande.',
                ],
                'message_draft' => 'Bonjour, je lance mon activité et je cherche des retours pour améliorer ma proposition de valeur. Si vous avez des conseils sur la façon de présenter mon service, je suis intéressé.',
                'fallback' => [
                    'needed' => true,
                    'reason' => 'Le ton commercial nécessite une reformulation pour respecter l\'esprit d\'entraide.',
                    'questions' => [],
                ],
                'human_validation' => ['required' => true, 'primary_label' => 'Valider la preview', 'secondary_label' => 'Modifier le brouillon'],
                'safety' => ['contains_sensitive_data' => false, 'needs_human_review' => false, 'blocked' => false],
            ],

            'donnees_sensibles' => [
                '_scenario' => 'donnees_sensibles',
                '_scenario_label' => 'Données sensibles',
                '_triggers' => ['numéro perso', 'données personnelles', 'email privé', 'téléphone', 'adresse'],
                'intent' => 'help_request',
                'confidence' => 0.3,
                'title' => 'Demande avec données sensibles',
                'need' => 'La phrase semble contenir des données personnelles ou confidentielles.',
                'context' => 'Le message contient potentiellement des informations privées qui ne devraient pas être partagées publiquement.',
                'expected_help_type' => 'non applicable',
                'deadline' => ['has_deadline' => false, 'label' => null, 'date' => null],
                'suggested_loop' => null,
                'tone' => [
                    'label' => 'avertissant',
                    'rationale' => 'Protéger la vie privée de l\'utilisateur est prioritaire.',
                ],
                'message_draft' => null,
                'fallback' => [
                    'needed' => true,
                    'reason' => 'La phrase semble contenir des données sensibles. Veuillez retirer toute information personnelle avant de publier.',
                    'questions' => [],
                ],
                'human_validation' => ['required' => true, 'primary_label' => 'Modifier la phrase', 'secondary_label' => 'Revenir en arrière'],
                'safety' => ['contains_sensitive_data' => true, 'needs_human_review' => true, 'blocked' => true],
            ],

            'loop_ambigue' => [
                '_scenario' => 'loop_ambigue',
                '_scenario_label' => 'Loop ambiguë',
                '_triggers' => ['avis sur mon site', 'site et ma stratégie', 'site internet', 'refonte'],
                'intent' => 'help_request',
                'confidence' => 0.62,
                'title' => 'Avis sur mon site et ma stratégie',
                'need' => 'Obtenir des retours croisés entre communication web et développement commercial.',
                'context' => 'Plusieurs Boucles pourraient correspondre : communication digitale, développement commercial, ou stratégie.',
                'expected_help_type' => 'retours d\'expérience, conseils croisés',
                'deadline' => ['has_deadline' => false, 'label' => null, 'date' => null],
                'suggested_loop' => null,
                'tone' => [
                    'label' => 'ouvert et constructif',
                    'rationale' => 'Plusieurs angles sont possibles, le ton doit rester ouvert.',
                ],
                'message_draft' => 'Bonjour, je cherche des avis sur mon site internet et ma stratégie globale. Des retours sur la clarté du message, le design ou la pertinence commerciale m\'aideraient à avancer.',
                'fallback' => [
                    'needed' => true,
                    'reason' => 'Plusieurs Boucles possibles, préciser pour mieux orienter.',
                    'questions' => [
                        'Souhaites-tu des retours sur le site (design, contenu) ou sur la stratégie commerciale ?',
                    ],
                ],
                'human_validation' => ['required' => true, 'primary_label' => 'Valider la preview', 'secondary_label' => 'Modifier le brouillon'],
                'safety' => ['contains_sensitive_data' => false, 'needs_human_review' => false, 'blocked' => false],
            ],

            'intention_offre' => [
                '_scenario' => 'intention_offre',
                '_scenario_label' => 'Intention offre',
                '_triggers' => ['aider à refaire', 'je peux aider', 'proposer mon aide', 'page de vente'],
                'intent' => 'offer',
                'confidence' => 0.81,
                'title' => 'Aide à la refonte de page de vente',
                'need' => 'Proposer une compétence en rédaction ou conversion aux membres qui en auraient besoin.',
                'context' => 'La personne propose son aide sur un sujet précis, ce qui correspond à une offre d\'entraide.',
                'expected_help_type' => 'proposition d\'aide, mise à disposition de compétence',
                'deadline' => ['has_deadline' => false, 'label' => null, 'date' => null],
                'suggested_loop' => [
                    'id' => 'loop-communication',
                    'label' => 'Communication & rédaction',
                    'reason' => 'Cette Boucle est la plus pertinente pour une proposition d\'aide en rédaction et conversion.',
                ],
                'tone' => [
                    'label' => 'propositif et clair',
                    'rationale' => 'Le ton doit refléter une offre utile sans être insistant.',
                ],
                'message_draft' => 'Bonjour, je peux aider ceux qui souhaitent améliorer leur page de vente ou leur site. J\'ai de l\'expérience en rédaction commerciale et optimisation de contenu. N\'hésitez pas à me contacter pour en discuter.',
                'fallback' => ['needed' => false, 'reason' => null, 'questions' => []],
                'human_validation' => ['required' => true, 'primary_label' => 'Valider la preview', 'secondary_label' => 'Modifier le brouillon'],
                'safety' => ['contains_sensitive_data' => false, 'needs_human_review' => false, 'blocked' => false],
            ],

            'hors_scope' => [
                '_scenario' => 'hors_scope',
                '_scenario_label' => 'Hors scope (juridique)',
                '_triggers' => ['stratégie juridique', 'contrat', 'conseil juridique', 'avocat', 'contentieux'],
                'intent' => 'unknown',
                'confidence' => 0.25,
                'title' => 'Conseil juridique',
                'need' => 'La personne demande un conseil juridique, ce qui sort du cadre d\'entraide de la plateforme.',
                'context' => 'Les sujets juridiques, médicaux ou financiers ne peuvent pas être traités via l\'entraide entre pairs.',
                'expected_help_type' => 'non applicable',
                'deadline' => ['has_deadline' => false, 'label' => null, 'date' => null],
                'suggested_loop' => null,
                'tone' => [
                    'label' => 'ferme et bienveillant',
                    'rationale' => 'Refuser poliment tout en redirigeant vers des ressources adaptées.',
                ],
                'message_draft' => null,
                'fallback' => [
                    'needed' => true,
                    'reason' => 'Cette demande relève d\'un conseil juridique, ce qui ne peut pas être traité via l\'entraide. Nous te recommandons de consulter un professionnel du droit.',
                    'questions' => [],
                ],
                'human_validation' => ['required' => true, 'primary_label' => 'Modifier la phrase', 'secondary_label' => 'Revenir en arrière'],
                'safety' => ['contains_sensitive_data' => false, 'needs_human_review' => true, 'blocked' => true],
            ],

            'fallback' => [
                '_scenario' => 'fallback',
                '_scenario_label' => 'Fallback (non reconnu)',
                '_triggers' => ['__fallback__'],
                'intent' => 'unknown',
                'confidence' => 0.3,
                'title' => 'Nouvelle demande',
                'need' => 'La phrase n\'a pas pu être analysée automatiquement.',
                'context' => 'Aucun scénario connu n\'a pu être associé à cette phrase.',
                'expected_help_type' => 'clarification',
                'deadline' => ['has_deadline' => false, 'label' => null, 'date' => null],
                'suggested_loop' => null,
                'tone' => [
                    'label' => 'neutre',
                    'rationale' => 'En l\'absence d\'analyse fiable, le ton reste neutre.',
                ],
                'message_draft' => null,
                'fallback' => [
                    'needed' => true,
                    'reason' => 'Je n\'ai pas pu analyser cette demande avec suffisamment de confiance. Peux-tu reformuler ou préciser ?',
                    'questions' => [
                        'Quel est l\'objectif principal de ta demande ?',
                        'Quel type d\'aide attends-tu des membres ?',
                    ],
                ],
                'human_validation' => ['required' => true, 'primary_label' => 'Modifier la phrase', 'secondary_label' => 'Reformuler'],
                'safety' => ['contains_sensitive_data' => false, 'needs_human_review' => false, 'blocked' => false],
            ],
        ];
    }
}
