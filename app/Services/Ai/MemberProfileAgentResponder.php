<?php

namespace App\Services\Ai;

use App\Models\MemberAiProfile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class MemberProfileAgentResponder
{
    public function __construct(
        private readonly SupervisionProviderResolver $resolver,
    ) {}

    public function answerWithDefaultProvider(MemberAiProfile $profile, string $question): array
    {
        $providers = $this->resolver->availableProviders();
        $provider = $this->resolver->defaultProvider() ?? array_key_first($providers);

        if (! $provider) {
            return $this->answerRuleBased($profile, $question);
        }

        $model = array_key_first($providers[$provider]['models'] ?? [])
            ?? $this->resolver->providerConfig($provider)['model'];

        try {
            return $this->answerWithProvider($profile, $question, $provider, $model);
        } catch (\Throwable) {
            return $this->answerRuleBased($profile, $question);
        }
    }

    public function answerWithProvider(MemberAiProfile $profile, string $question, string $provider, string $model): array
    {
        $config = $this->resolver->providerConfig($provider);
        $startedAt = (int) (microtime(true) * 1000);

        $answer = match ($provider) {
            'ollama' => $this->callOllama($profile, $config, $model, $question),
            'openrouter' => $this->callOpenRouter($profile, $config, $model, $question),
            default => $this->callOpenAiCompatible($profile, $config, $model, $question),
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
                'response' => 'Je peux surtout vous aider à comprendre si ce membre correspond à votre besoin. Pouvez-vous préciser ce que vous cherchez : un conseil, un avis rapide, une méthode ou un accompagnement ?',
                'fields' => [],
                'provider' => 'rule_based',
                'model' => null,
                'latency_ms' => 0,
            ];
        }

        $highlights = $this->collectHighlights($profile, $matchedFields);

        if ($highlights === []) {
            return [
                'response' => "Je n'ai pas assez d'information publiée pour répondre précisément, mais je peux vous aider à qualifier votre demande. Quel est votre contexte ou votre objectif principal ?",
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

    private function callOllama(MemberAiProfile $profile, array $config, string $model, string $question): string
    {
        $response = Http::timeout((int) ($config['timeout'] ?? 30))
            ->acceptJson()
            ->asJson()
            ->post(rtrim((string) $config['base_url'], '/').'/api/generate', [
                'model' => $model,
                'prompt' => $this->buildPrompt($profile, $question),
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

    private function callOpenRouter(MemberAiProfile $profile, array $config, string $model, string $question): string
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
                ->post(rtrim((string) $config['base_url'], '/').'/chat/completions', $this->chatPayload($profile, $config, $model, $question));
        } catch (ConnectionException $e) {
            throw $e;
        }

        if (! $response->successful()) {
            throw new \RuntimeException(sprintf('Réponse OpenRouter invalide (HTTP %d).', $response->status()));
        }

        return trim((string) ($response->json('choices.0.message.content') ?? ''));
    }

    private function callOpenAiCompatible(MemberAiProfile $profile, array $config, string $model, string $question): string
    {
        if (empty($config['api_key'])) {
            throw new \RuntimeException('Clé API du provider manquante.');
        }

        $response = Http::withToken((string) $config['api_key'])
            ->timeout((int) ($config['timeout'] ?? 30))
            ->acceptJson()
            ->asJson()
            ->post(rtrim((string) $config['base_url'], '/').'/chat/completions', $this->chatPayload($profile, $config, $model, $question));

        if (! $response->successful()) {
            throw new \RuntimeException(sprintf('Réponse IA invalide (HTTP %d).', $response->status()));
        }

        return trim((string) ($response->json('choices.0.message.content') ?? ''));
    }

    private function chatPayload(MemberAiProfile $profile, array $config, string $model, string $question): array
    {
        return [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $this->buildSystemPrompt($profile)],
                ['role' => 'user', 'content' => $question],
            ],
            'max_tokens' => (int) ($config['max_output_tokens'] ?? 650),
            'temperature' => 0.35,
        ];
    }

    private function buildPrompt(MemberAiProfile $profile, string $question): string
    {
        return $this->buildSystemPrompt($profile)."\n\nQuestion du visiteur :\n".$question;
    }

    private function buildSystemPrompt(MemberAiProfile $profile): string
    {
        $profile->loadMissing(['user', 'organization']);

        return implode("\n", [
            'Tu es l’agent IA commercial et conversationnel du profil d’un membre BouclePro.',
            'Objectif : aider le visiteur à comprendre concrètement comment ce membre peut l’aider, puis qualifier le besoin pour faciliter la mise en relation.',
            'Réponds en français, avec un ton naturel, rassurant, professionnel et orienté action.',
            'Ne te contente pas de recopier les champs : reformule, synthétise, explique la valeur et oriente le visiteur.',
            'Tu peux poser UNE question de relance pertinente à la fin pour mieux comprendre le besoin du visiteur.',
            'Reste strictement borné aux informations du profil IA. N’invente ni prestation, ni tarif, ni délai, ni disponibilité, ni coordonnées.',
            'Si la question sort du périmètre, ramène poliment vers ce que le membre peut présenter et pose une question de qualification liée au profil.',
            'Pas de promesse commerciale excessive. Pas de conversation persistante. Pas de marketplace.',
            '',
            'Profil IA du membre :',
            '- Membre : '.($profile->user?->name ?? 'Utilisateur inconnu'),
            '- Organisation : '.($profile->organization?->name ?? 'Organisation inconnue'),
            '- Résumé : '.($profile->member_profile_summary ?: 'Non renseigné'),
            '- Résumé généré : '.($profile->generated_summary ?: 'Non renseigné'),
            '- Périmètre d’aide / prestation : '.($profile->service_scope ?: 'Non renseigné'),
            '- Contexte d’expérience : '.($profile->experience_context ?: 'Non renseigné'),
            '- Compétences : '.$this->formatProfileValue($profile->skills),
            '- Types d’aide : '.$this->formatProfileValue($profile->help_types),
            '- Limites : '.$this->formatProfileValue($profile->boundaries),
            '- Public cible : '.$this->formatProfileValue($profile->target_audience),
            '- Problèmes aidés : '.$this->formatProfileValue($profile->problems_helped),
            '- Bons exemples de demande : '.$this->formatProfileValue($profile->good_request_examples),
            '- Demandes hors périmètre : '.$this->formatProfileValue($profile->bad_request_examples),
            '- Ton : '.($profile->tone ?: 'Non renseigné'),
            '- Contact préféré : '.($profile->preferred_contact_action ?: 'Non renseigné'),
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

        return "Oui. D'après son profil, ce membre peut vous aider sur ce besoin : {$summary}.\n\nPour vous orienter utilement, quel est votre objectif concret ou le problème que vous voulez résoudre en priorité ?";
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
