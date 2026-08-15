<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const SCENARIO = 'clarify_help_request';

    private const VERSION = 2;

    /**
     * Copie volontairement immuable du prompt v2 : une migration historique
     * ne doit pas dependre d'un seeder ou d'une classe metier qui evoluera.
     */
    private const PROMPT = <<<'PROMPT'
Tu aides un membre de BouclePro à transformer ses valeurs actuelles en demande d'aide claire et fidèle.

Produis un titre court réellement descriptif et une description utile de 2 à 3 phrases. Ne supprime, n'affaiblis et n'invente aucune information. Si tu ne peux pas améliorer un champ, conserve son sens.

Pour `suggested_category_id`, recopie exactement l'identifiant d'UNE catégorie fournie dans CATEGORIES AUTORISÉES, uniquement si elle correspond clairement. Sinon, renvoie une chaîne vide.

Pour `suggested_loop_id`, recopie exactement l'identifiant d'UNE Boucle fournie dans BOUCLES AUTORISÉES, uniquement si elle constitue un relais pertinent. Sinon, renvoie une chaîne vide.

N'invente jamais d'identifiant. L'utilisateur modifiera et validera avant toute création ou diffusion. Si l'intention reste ambiguë, pose au maximum trois questions et marque la relecture humaine nécessaire.
PROMPT;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $scenarioExists = DB::table('admin_ai_prompts')
                ->where('scenario_id', self::SCENARIO)
                ->exists();

            $versionExists = DB::table('admin_ai_prompts')
                ->where('scenario_id', self::SCENARIO)
                ->where('version', self::VERSION)
                ->exists();

            // Une ligne v2 est une donnee administrable. Une fois presente,
            // son texte, ses metadata et son activation appartiennent a
            // l'administrateur : la migration n'y retouche jamais.
            if ($versionExists) {
                return;
            }

            $timestamp = now();

            DB::table('admin_ai_prompts')->insert([
                'id' => (string) Str::uuid(),
                'scenario_id' => self::SCENARIO,
                'name' => 'Clarification de demande d\'aide — v2',
                'description' => 'Prompt P3 de reformulation et de suggestion bornée de catégorie et de Boucle.',
                'prompt_text' => self::PROMPT,
                'version' => self::VERSION,
                // Seul un scenario totalement absent est active
                // automatiquement. Toute ligne existante, active ou non,
                // constitue une decision administrative a respecter.
                'is_active' => ! $scenarioExists,
                'metadata' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op volontaire : apres deploiement, cette ligne est visible et
        // editable dans /admin/ai-prompts. Un rollback ne peut pas prouver
        // qu'elle n'a ni ete modifiee ni utilisee sans detruire une donnee
        // admin. Une correction eventuelle doit donc etre forward-only.
    }
};
