<?php

namespace App\Services\Ai;

use App\Models\AdminAiPrompt;
use App\Models\MemberAiProfile;
use App\Services\Ai\Persistence\AdminAiInteractionPersistence;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class MemberProfileAgentResponder
{
    public function __construct(
        private readonly SupervisionProviderResolver $resolver,
        private readonly AdminAiInteractionPersistence $logger,
    ) {}

    public function answerWithDefaultProvider(MemberAiProfile $profile, string $question, string $scenarioId = 'profile_agent_master'): array
    {
        $providers = $this->resolver->availableProviders();
        $provider = $this->resolver->defaultProvider() ?? array_key_first($providers);

        if (! $provider) {
            return $this->answerRuleBased($profile, $question);
        }

        $model = array_key_first($providers[$provider]['models'] ?? [])
            ?? $this->resolver->providerConfig($provider)['model'];

        try {
            return $this->answerWithProvider($profile, $question, $provider, $model, $scenarioId);
        } catch (\Throwable) {
            return $this->answerRuleBased($profile, $question);
        }
    }

    public function answerWithProvider(MemberAiProfile $profile, string $question, string $provider, string $model, string $scenarioId = 'profile_agent_master'): array
    {
        $config = $this->resolver->providerConfig($provider);
        $startedAt = (int) (microtime(true) * 1000);

        $answer = match ($provider) {
            'ollama' => $this->callOllama($profile, $config, $model, $question, $scenarioId),
            'openrouter' => $this->callOpenRouter($profile, $config, $model, $question, $scenarioId),
            default => $this->callOpenAiCompatible($profile, $config, $model, $question, $scenarioId),
        };

        return [
            'response' => $answer,
            'fields' => ['llm_profile_context'],
            'provider' => $provider,
            'model' => $model,
            'latency_ms' => (int) (microtime(true) * 1000) - $startedAt,
        ];
    }

    public function answerRuleBased(MemberAiProfile $profile, string $question): array
    {
        $question = $this->normalize($question);

        $keywordMap = [
            'competence' => ['skills', 'experience_context'],
            'savoir' => ['skills', 'experience_context'],
            'skill' => ['skills', 'experience_context'],
            'aide' => ['help_types', 'service_scope', 'member_profile_summary'],
            'help' => ['help_types', 'service_scope', 'member_profile_summary'],
            'service' => ['help_types', 'service_scope', 'member_profile_summary'],
            'prestation' => ['help_types', 'service_scope', 'member_profile_summary'],
            'offre' => ['help_types', 'service_scope', 'member_profile_summary'],
            'activite' => ['help_types', 'service_scope', 'member_profile_summary'],
            'metier' => ['help_types', 'service_scope', 'member_profile_summary'],
            'intervention' => ['help_types', 'service_scope', 'member_profile_summary'],
            'propose' => ['help_types', 'service_scope', 'member_profile_summary'],
            'limite' => ['boundaries'],
            'boundary' => ['boundaries'],
            'urgence' => ['boundaries'],
            'gratuit' => ['boundaries'],
            'contact' => ['preferred_contact_action'],
            'joindre' => ['preferred_contact_action'],
            'telephone' => ['preferred_contact_action'],
            'email' => ['preferred_contact_action'],
            'audience' => ['target_audience', 'problems_helped', 'member_profile_summary'],
            'client' => ['target_audience', 'problems_helped', 'member_profile_summary'],
            'cible' => ['target_audience', 'problems_helped', 'member_profile_summary'],
            'public' => ['target_audience', 'problems_helped', 'member_profile_summary'],
        ];

        $matchedFields = [];
        foreach ($keywordMap as $keyword => $fields) {
            if (str_contains($question, $keyword)) {
                $matchedFields = array_merge($matchedFields, $fields);
            }
        }

        $matchedFields = array_values(array_unique($matchedFields));

        if (empty($matchedFields)) {
            return [
                'response' => $this->ruleBasedClarificationResponse(),
                'fields' => [],
                'provider' => 'rule_based',
                'model' => null,
                'latency_ms' => 0,
            ];
        }

        $highlights = $this->collectHighlights($profile, $matchedFields);

        if ($highlights === []) {
            return [
                'response' => $this->ruleBasedMissingProfileResponse(),
                'fields' => $matchedFields,
                'provider' => 'rule_based',
                'model' => null,
                'latency_ms' => 0,
            ];
        }

        return [
            'response' => $this->buildCommercialFallbackAnswer($highlights),
            'fields' => $matchedFields,
            'provider' => 'rule_based',
            'model' => null,
            'latency_ms' => 0,
        ];
    }

    public function chatWithSetupPrompt(array $messages, string $provider, string $model): array
    {
        $config = $this->resolver->providerConfig($provider);
        $startedAt = (int) (microtime(true) * 1000);

        $systemPrompt = $this->resolveSetupPrompt();

        $chatMessages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $messages,
        );

        $payload = [
            'model' => $model,
            'messages' => $chatMessages,
            'max_tokens' => (int) ($config['max_output_tokens'] ?? 1000),
            'temperature' => 0.5,
        ];

        $answer = match ($provider) {
            'ollama' => $this->callOllamaChat($payload, $config),
            default => $this->callChatCompletions($payload, $config, $provider),
        };

        return [
            'response' => $answer,
            'provider' => $provider,
            'model' => $model,
            'latency_ms' => (int) (microtime(true) * 1000) - $startedAt,
        ];
    }

    private function callOllamaChat(array $payload, array $config): string
    {
        $response = Http::timeout((int) ($config['timeout'] ?? 30))
            ->acceptJson()
            ->asJson()
            ->post(rtrim((string) $config['base_url'], '/').'/api/chat', [
                'model' => $payload['model'],
                'messages' => $payload['messages'],
                'stream' => false,
                'options' => [
                    'num_predict' => $payload['max_tokens'] ?? 1000,
                    'temperature' => $payload['temperature'] ?? 0.5,
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(sprintf('Réponse Ollama invalide (HTTP %d).', $response->status()));
        }

        return trim((string) ($response->json('message.content') ?? ''));
    }

    private function callChatCompletions(array $payload, array $config, string $provider): string
    {
        if (empty($config['api_key'])) {
            $label = $provider === 'openrouter' ? 'OpenRouter' : 'du provider';

            throw new \RuntimeException("Clé API {$label} manquante.");
        }

        $http = Http::withToken((string) $config['api_key'])
            ->timeout((int) ($config['timeout'] ?? 30))
            ->acceptJson()
            ->asJson();

        if ($provider === 'openrouter') {
            $http->withHeaders([
                'HTTP-Referer' => config('ai.openrouter.site_url', config('app.url')),
                'X-Title' => config('ai.openrouter.site_name', config('app.name')),
            ]);
        }

        $response = $http->post(
            rtrim((string) $config['base_url'], '/').'/chat/completions',
            $payload,
        );

        if (! $response->successful()) {
            throw new \RuntimeException(sprintf('Réponse %s invalide (HTTP %d).', $provider, $response->status()));
        }

        return trim((string) ($response->json('choices.0.message.content') ?? ''));
    }

    private function callOllama(MemberAiProfile $profile, array $config, string $model, string $question, string $scenarioId = 'profile_agent_master'): string
    {
        $response = Http::timeout((int) ($config['timeout'] ?? 30))
            ->acceptJson()
            ->asJson()
            ->post(rtrim((string) $config['base_url'], '/').'/api/generate', [
                'model' => $model,
                'prompt' => $this->buildPrompt($profile, $question, $scenarioId),
                'stream' => false,
                'think' => false,
                'options' => [
                    'num_predict' => 650,
                    'temperature' => 0.35,
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(sprintf('Réponse Ollama invalide (HTTP %d).', $response->status()));
        }

        return trim((string) ($response->json('response') ?? $response->json('thinking') ?? ''));
    }

    private function callOpenRouter(MemberAiProfile $profile, array $config, string $model, string $question, string $scenarioId = 'profile_agent_master'): string
    {
        if (empty($config['api_key'])) {
            throw new \RuntimeException('Clé API OpenRouter manquante.');
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$config['api_key'],
                'HTTP-Referer' => config('ai.openrouter.site_url', config('app.url')),
                'X-Title' => config('ai.openrouter.site_name', config('app.name')),
            ])
                ->timeout((int) ($config['timeout'] ?? 30))
                ->acceptJson()
                ->asJson()
                ->post(rtrim((string) $config['base_url'], '/').'/chat/completions', $this->chatPayload($profile, $config, $model, $question, $scenarioId));
        } catch (ConnectionException $e) {
            throw $e;
        }

        if (! $response->successful()) {
            throw new \RuntimeException(sprintf('Réponse OpenRouter invalide (HTTP %d).', $response->status()));
        }

        return trim((string) ($response->json('choices.0.message.content') ?? ''));
    }

    private function callOpenAiCompatible(MemberAiProfile $profile, array $config, string $model, string $question, string $scenarioId = 'profile_agent_master'): string
    {
        if (empty($config['api_key'])) {
            throw new \RuntimeException('Clé API du provider manquante.');
        }

        $response = Http::withToken((string) $config['api_key'])
            ->timeout((int) ($config['timeout'] ?? 30))
            ->acceptJson()
            ->asJson()
            ->post(rtrim((string) $config['base_url'], '/').'/chat/completions', $this->chatPayload($profile, $config, $model, $question, $scenarioId));

        if (! $response->successful()) {
            throw new \RuntimeException(sprintf('Réponse IA invalide (HTTP %d).', $response->status()));
        }

        return trim((string) ($response->json('choices.0.message.content') ?? ''));
    }

    private function chatPayload(MemberAiProfile $profile, array $config, string $model, string $question, string $scenarioId = 'profile_agent_master'): array
    {
        return [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $this->buildSystemPrompt($profile, $scenarioId)],
                ['role' => 'user', 'content' => $question],
            ],
            'max_tokens' => (int) ($config['max_output_tokens'] ?? 650),
            'temperature' => 0.35,
        ];
    }

    private function buildPrompt(MemberAiProfile $profile, string $question, string $scenarioId = 'profile_agent_master'): string
    {
        $questionLabel = $this->currentLocale() === 'en' ? 'Visitor question' : 'Question du visiteur';

        return $this->buildSystemPrompt($profile, $scenarioId)."\n\n{$questionLabel} :\n".$question;
    }

    private function buildSystemPrompt(MemberAiProfile $profile, string $scenarioId = 'profile_agent_master'): string
    {
        $profile->loadMissing(['user', 'organization']);

        $instructions = $this->resolveMasterPrompt($scenarioId);

        if ($scenarioId === 'profile_agent_visitor_chat') {
            $instructions .= "\n\n".$this->visitorChatLocaleInstructions();
        }

        $profileData = $this->buildProfileDataLines($profile);

        return $instructions."\n".$profileData;
    }

    private function buildProfileDataLines(MemberAiProfile $profile): string
    {
        $profile->loadMissing(['user', 'organization']);

        $lines = [
            '',
            'Profil IA du membre :',
            '- Membre : '.($profile->user?->name ?? 'Utilisateur inconnu'),
            '- Organisation : '.($profile->organization?->name ?? 'Organisation inconnue'),
        ];

        $structured = $profile->structured_profile;

        if (is_array($structured) && $structured !== []) {
            $lines[] = '- Résumé : '.($structured['summary'] ?? $profile->member_profile_summary ?: 'Non renseigné');
            $lines[] = '- Résumé généré : '.($profile->generated_summary ?: 'Non renseigné');
            $lines[] = '- Périmètre d\'aide / prestation : '.($structured['service_scope'] ?? $profile->service_scope ?: 'Non renseigné');
            $lines[] = '- Contexte d\'expérience : '.($structured['experience_context'] ?? $profile->experience_context ?: 'Non renseigné');
            $lines[] = '- Compétences : '.$this->formatProfileValue($structured['skills'] ?? $profile->skills);
            $lines[] = '- Types d\'aide : '.$this->formatProfileValue($structured['help_types'] ?? $profile->help_types);
            $lines[] = '- Limites : '.$this->formatProfileValue($structured['boundaries'] ?? $profile->boundaries);
            $lines[] = '- Public cible : '.($structured['target_audience'] ?? $this->formatProfileValue($profile->target_audience));
            $lines[] = '- Problèmes aidés : '.($structured['problems_helped'] ?? $this->formatProfileValue($profile->problems_helped));
            $lines[] = '- Ton : '.($structured['tone'] ?? $profile->tone ?: 'Non renseigné');
            $lines[] = '- Contact préféré : '.($structured['preferred_contact_action'] ?? $profile->preferred_contact_action ?: 'Non renseigné');
        } else {
            $lines[] = '- Résumé : '.($profile->member_profile_summary ?: 'Non renseigné');
            $lines[] = '- Résumé généré : '.($profile->generated_summary ?: 'Non renseigné');
            $lines[] = '- Périmètre d\'aide / prestation : '.($profile->service_scope ?: 'Non renseigné');
            $lines[] = '- Contexte d\'expérience : '.($profile->experience_context ?: 'Non renseigné');
            $lines[] = '- Compétences : '.$this->formatProfileValue($profile->skills);
            $lines[] = '- Types d\'aide : '.$this->formatProfileValue($profile->help_types);
            $lines[] = '- Limites : '.$this->formatProfileValue($profile->boundaries);
            $lines[] = '- Public cible : '.$this->formatProfileValue($profile->target_audience);
            $lines[] = '- Problèmes aidés : '.$this->formatProfileValue($profile->problems_helped);
            $lines[] = '- Bons exemples de demande : '.$this->formatProfileValue($profile->good_request_examples);
            $lines[] = '- Demandes hors périmètre : '.$this->formatProfileValue($profile->bad_request_examples);
            $lines[] = '- Ton : '.($profile->tone ?: 'Non renseigné');
            $lines[] = '- Contact préféré : '.($profile->preferred_contact_action ?: 'Non renseigné');
        }

        return implode("\n", $lines);
    }

    private function resolveMasterPrompt(string $scenarioId = 'profile_agent_master'): string
    {
        $dbPrompt = AdminAiPrompt::active()
            ->byScenario($scenarioId)
            ->orderByDesc('version')
            ->first();

        if ($dbPrompt && filled($dbPrompt->prompt_text)) {
            return str_contains($dbPrompt->prompt_text, 'Profil du membre à présenter :') ? $this->appendProfilePlaceholder($dbPrompt->prompt_text) : $dbPrompt->prompt_text;
        }

        if ($scenarioId === 'profile_agent_visitor_chat') {
            return implode("\n", [
                "Tu es l'agent IA conversationnel et commercial d'un membre BouclePro.",
                "Ton rôle est d'aider le visiteur à formuler une demande utile et précise, sans remplacer le membre propriétaire.",
                'Respecte strictement le contexte de langue injecté dans le prompt.',
                'Présente le membre et son offre professionnelle à partir des données du profil, sans inventer d\'information.',
                'Si le visiteur exprime un besoin, pose UNE SEULE question de qualification à la fois.',
                'Qualifie progressivement selon : objectif concret, contexte, type d\'aide recherchée, urgence ou horizon, résultat attendu.',
                'Ne promets jamais disponibilité, tarif, délai, résultat garanti ou compétence non déclarée.',
                'Si la question sort du périmètre du profil, ramène poliment vers ce que le membre propose.',
                'Rappelle en fin de réponse que le membre propriétaire pourra lire l\'échange.',
                'Reste concis : privilégie des réponses courtes et actionnables.',
            ]);
        }

        return implode("\n", [
            "Tu es l'agent IA commercial et conversationnel du profil d'un membre BouclePro.",
            "Objectif : aider le visiteur à comprendre concrètement comment ce membre peut l'aider, puis qualifier le besoin pour faciliter la mise en relation.",
            'Réponds en français, avec un ton naturel, rassurant, professionnel et orienté action.',
            'Ne te contente pas de recopier les champs : reformule, synthétise, explique la valeur et oriente le visiteur.',
            'Tu peux poser UNE question de relance pertinente à la fin pour mieux comprendre le besoin du visiteur.',
            "Reste strictement borné aux informations du profil IA. N'invente ni prestation, ni tarif, ni délai, ni disponibilité, ni coordonnées.",
            'Si la question sort du périmètre, ramène poliment vers ce que le membre peut présenter et pose une question de qualification liée au profil.',
            'Pas de promesse commerciale excessive. Pas de conversation persistante. Pas de marketplace.',
        ]);
    }

    private function appendProfilePlaceholder(string $prompt): string
    {
        if (str_contains($prompt, 'Profil du membre à présenter')) {
            return $prompt;
        }

        return $prompt."\n\nProfil du membre à présenter :\n";
    }

    private function resolveSetupPrompt(): string
    {
        $dbPrompt = AdminAiPrompt::active()
            ->byScenario('profile_agent_setup')
            ->orderByDesc('version')
            ->first();

        if ($dbPrompt && filled($dbPrompt->prompt_text)) {
            return $dbPrompt->prompt_text;
        }

        return implode("\n", [
            'Tu es un assistant de création de profil IA pour la plateforme BouclePro.',
            'Guide le membre pas à pas pour construire son profil de présentation IA.',
            'Pose une question à la fois. Adapte la suivante en fonction de la réponse.',
            'À la fin, résume le profil en JSON structuré avec les clés : summary, service_scope, experience_context, skills, help_types, target_audience, problems_helped, boundaries, preferred_contact_action, tone.',
            'Demande la validation du membre avant de finaliser.',
        ]);
    }

    public function logSetupInteraction(
        string $question,
        string $response,
        array $result,
        ?MemberAiProfile $profile = null,
    ): void {
        $metadata = [];

        if ($profile) {
            $metadata['profile_id'] = $profile->id;
            $metadata['profile_status'] = $profile->status;
        }

        $this->logger->persist([
            'scenario_id' => 'profile_agent_setup',
            'provider' => $result['provider'] ?? 'unknown',
            'model' => $result['model'] ?? null,
            'status' => 'success',
            'content' => $question,
            'result_summary' => $response,
            'latency_ms' => $result['latency_ms'] ?? null,
            'metadata' => $metadata,
        ]);
    }

    public function logVisitorInteraction(
        string $question,
        string $response,
        array $result,
    ): void {
        $this->logger->persist([
            'scenario_id' => 'profile_agent_visitor_chat',
            'provider' => $result['provider'] ?? 'unknown',
            'model' => $result['model'] ?? null,
            'status' => 'success',
            'content' => $question,
            'result_summary' => $response,
            'latency_ms' => $result['latency_ms'] ?? null,
        ]);
    }

    private function collectHighlights(MemberAiProfile $profile, array $fields): array
    {
        $labels = [
            'skills' => 'ses compétences',
            'experience_context' => 'son expérience',
            'help_types' => 'le type d’aide proposé',
            'service_scope' => 'son cadre d’intervention',
            'boundaries' => 'ses limites',
            'preferred_contact_action' => 'la suite recommandée',
            'target_audience' => 'le public visé',
            'problems_helped' => 'les problèmes traités',
            'member_profile_summary' => 'son positionnement',
        ];

        $highlights = [];
        foreach ($fields as $field) {
            $value = $profile->{$field} ?? null;
            if ($value === null || (is_array($value) && $value === []) || (is_string($value) && trim($value) === '')) {
                continue;
            }
            $highlights[] = ($labels[$field] ?? $field).' : '.$this->formatProfileValue($value);
        }

        return $highlights;
    }

    private function buildCommercialFallbackAnswer(array $highlights): string
    {
        $summary = implode(' ', array_slice($highlights, 0, 3));

        if ($this->currentLocale() === 'en') {
            return "Yes. Based on this member's profile, they may be able to help with this need: {$summary}.\n\nTo guide you usefully, what is your concrete goal or the main problem you want to solve?";
        }

        return "Oui. D'après son profil, ce membre peut vous aider sur ce besoin : {$summary}.\n\nPour vous orienter utilement, quel est votre objectif concret ou le problème que vous voulez résoudre en priorité ?";
    }

    private function visitorChatLocaleInstructions(): string
    {
        $locale = $this->currentLocale();

        return implode("\n", [
            'Language context:',
            '- current_locale='.$locale,
            '- response_language='.$this->responseLanguage($locale),
            '- Respond in English when current_locale is en.',
            '- Réponds en français quand current_locale est fr.',
            '- If the visitor writes in another language, you may follow the visitor language, but never default to French when current_locale is en.',
            '- When the visitor asks about a skill or tool that is not specified in the member profile, say that the published profile does not specify this competency; do not say that the member cannot or does not offer it.',
            '- Quand le visiteur demande une compétence ou un outil non renseigné dans le profil, dis que le profil publié ne précise pas cette compétence ; ne conclus pas que le membre ne peut pas ou ne propose pas cette aide.',
            '- Then propose to qualify the request so the member can read and assess it.',
            '- Propose ensuite de qualifier la demande afin que le membre puisse la lire et l’évaluer.',
        ]);
    }

    private function responseLanguage(string $locale): string
    {
        return $locale === 'en' ? 'English' : 'French';
    }

    private function currentLocale(): string
    {
        return app()->getLocale() === 'en' ? 'en' : 'fr';
    }

    private function ruleBasedClarificationResponse(): string
    {
        if ($this->currentLocale() === 'en') {
            return 'I can mainly help you understand whether this member matches your need. Could you clarify what you are looking for: advice, a quick opinion, a method, or support?';
        }

        return 'Je peux surtout vous aider à comprendre si ce membre correspond à votre besoin. Pouvez-vous préciser ce que vous cherchez : un conseil, un avis rapide, une méthode ou un accompagnement ?';
    }

    private function ruleBasedMissingProfileResponse(): string
    {
        if ($this->currentLocale() === 'en') {
            return 'I do not have enough published information to answer precisely, but I can help qualify your request. What is your context or main goal?';
        }

        return "Je n'ai pas assez d'information publiée pour répondre précisément, mais je peux vous aider à qualifier votre demande. Quel est votre contexte ou votre objectif principal ?";
    }

    private function formatProfileValue(mixed $value): string
    {
        if (is_array($value)) {
            return $value ? implode(', ', $value) : 'Non renseigné';
        }

        return $value ? (string) $value : 'Non renseigné';
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));

        return str_replace(
            ['é', 'è', 'ê', 'ë', 'à', 'â', 'ä', 'ù', 'û', 'ü', 'ô', 'ö', 'î', 'ï', 'ç'],
            ['e', 'e', 'e', 'e', 'a', 'a', 'a', 'u', 'u', 'u', 'o', 'o', 'i', 'i', 'c'],
            $text
        );
    }
}
