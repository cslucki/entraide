<?php

namespace Database\Seeders;

use App\Models\AdminAiBenchmarkPrompt;
use Illuminate\Database\Seeder;

class AiBenchmarkPromptSeeder extends Seeder
{
    public function run(): void
    {
        $prompts = [
            [
                'category' => 'clarification',
                'title' => 'Demande floue — besoin d\'aide général',
                'prompt_text' => 'J\'ai besoin d\'aide pour mon projet.',
                'expected_output_hint' => 'Doit poser des questions de clarification sur le type de projet, les délais, le budget.',
                'complexity' => 2,
                'tags' => ['vague', 'open-ended'],
            ],
            [
                'category' => 'clarification',
                'title' => 'Demande de logo sans détails',
                'prompt_text' => 'Je veux un logo pour ma nouvelle entreprise.',
                'expected_output_hint' => 'Doit demander le secteur d\'activité, les valeurs de la marque, les préférences visuelles.',
                'complexity' => 3,
                'tags' => ['logo', 'branding', 'vague'],
            ],
            [
                'category' => 'clarification',
                'title' => 'Message incomplet — "Je cherche quelqu"',
                'prompt_text' => 'Je cherche quelqu',
                'expected_output_hint' => 'Doit gérer l\'input tronqué et proposer une reformulation.',
                'complexity' => 1,
                'tags' => ['truncated', 'edge-case'],
            ],
            [
                'category' => 'supervision_content',
                'title' => 'Annonce de service web avec contenu risqué',
                'prompt_text' => 'Je propose des services de référencement Google. Je garantis la première place en 48h. Contactez-moi par WhatsApp au +33XXXXX.',
                'expected_output_hint' => 'Doit identifier les signaux de risque (promesse irréaliste, contact externe, urgence).',
                'complexity' => 4,
                'tags' => ['risque', 'supervision', 'SEO'],
            ],
            [
                'category' => 'supervision_content',
                'title' => 'Demande de charte graphique complète',
                'prompt_text' => 'J\'ai besoin d\'une charte graphique complète avec logo, palette de couleurs, typographies, et templates réseaux sociaux. Budget 500€, délai 2 semaines.',
                'expected_output_hint' => 'Doit évaluer la cohérence budget/délai, et identifier les catégories de compétences nécessaires.',
                'complexity' => 3,
                'tags' => ['charte', 'branding', 'budget'],
            ],
            [
                'category' => 'supervision_content',
                'title' => 'Contenu très court — "OK"',
                'prompt_text' => 'OK',
                'expected_output_hint' => 'Doit gérer l\'input minimal et ne pas générer d\'erreur.',
                'complexity' => 1,
                'tags' => ['minimal', 'edge-case'],
            ],
            [
                'category' => 'review',
                'title' => 'Demande de révision de site web existant',
                'prompt_text' => 'Mon site actuel est lent et pas responsive. Je voudrais une refonte complète avec un design moderne. Voici l\'URL : https://example-old.com',
                'expected_output_hint' => 'Doit évaluer la qualité de la demande, identifier les critères techniques (performance, responsive), et noter la présence d\'exemples.',
                'complexity' => 3,
                'tags' => ['review', 'refonte', 'performance'],
            ],
            [
                'category' => 'review',
                'title' => 'Demande multi-catégories — logo + site + SEO',
                'prompt_text' => 'Je lance ma startup et j\'ai besoin d\'un logo, d\'un site vitrine, et d\'une stratégie SEO. Budget total 2000€.',
                'expected_output_hint' => 'Doit identifier les trois catégories distinctes, évaluer la cohérence du budget global, et prioriser les livrables.',
                'complexity' => 4,
                'tags' => ['multi-category', 'startup', 'budget'],
            ],
            [
                'category' => 'review',
                'title' => 'Texte mal orthographié',
                'prompt_text' => 'J\'ai besoin d\'un sight pour ma boite. J\'ai un petit budje.',
                'expected_output_hint' => 'Doit tolérer les fautes d\'orthographe et extraire l\'intention malgré les erreurs.',
                'complexity' => 2,
                'tags' => ['orthographe', 'tolerance', 'français'],
            ],
            [
                'category' => 'technical',
                'title' => 'Extraction JSON — catégorisation skills',
                'prompt_text' => 'Analyse ce contenu et retourne un JSON avec les catégories de skills, le niveau de confiance, et les termes non reconnus.',
                'expected_output_hint' => 'Doit produire un JSON strict avec les clés : categories, confidence, unmatched_terms.',
                'complexity' => 5,
                'tags' => ['json', 'strict', 'extraction'],
            ],
            [
                'category' => 'technical',
                'title' => 'Formatage JSON — réponse structurée',
                'prompt_text' => 'Génère une réponse JSON contenant un titre, une description, et une liste de 3 tags.',
                'expected_output_hint' => 'Doit produire un JSON valide avec les clés : title, description, tags (array).',
                'complexity' => 4,
                'tags' => ['json', 'format', 'structure'],
            ],
            [
                'category' => 'technical',
                'title' => 'Prompt très long — spécifications détaillées',
                'prompt_text' => 'Je voudrais un site e-commerce complet avec : catalogue de 500 produits, panier, paiement Stripe, livraison Mondial Relay, intégration Instagram, blog, newsletter Mailchimp, SEO optimisé, multilingue FR/EN, back-office admin, dashboard analytics, et support chat. Budget 15 000€, délai 3 mois.',
                'expected_output_hint' => 'Doit identifier les modules techniques, évaluer la faisabilité budget/délai, et détecter les dépendances externes.',
                'complexity' => 5,
                'tags' => ['long', 'e-commerce', 'multi-module'],
            ],
            [
                'category' => 'technical',
                'title' => 'Prompt vide',
                'prompt_text' => '',
                'expected_output_hint' => 'Doit gérer l\'input vide sans planter et retourner une erreur contrôlée ou une demande de reformulation.',
                'complexity' => 1,
                'tags' => ['empty', 'edge-case', 'error-handling'],
            ],
        ];

        foreach ($prompts as $prompt) {
            AdminAiBenchmarkPrompt::create($prompt);
        }
    }
}
