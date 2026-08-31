<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TASK-1348 : provisionne la Constitution PLATEFORME v1.
 *
 * Meme patron que les migrations de provisioning des prompts administrables
 * (`provision_*_admin_ai_prompt*`) : le texte est une constante VOLONTAIREMENT
 * IMMUABLE — une migration historique ne doit pas dependre d'une classe metier
 * qui evoluera — et la migration ne retouche JAMAIS une ligne existante : une
 * fois inscrite, elle appartient a son administrateur.
 *
 * Le texte reprend la graine de `App\Ai\Constitution::text()` au moment de
 * TASK-1348, identite canonique comprise. Les deux peuvent diverger ensuite :
 * la graine reste le repli du code, cette ligne devient l'autorite runtime.
 */
return new class extends Migration
{
    private const VERSION = 1;

    private const BODY = <<<'TEXT'
Constitution BouclePro IA — v1

BouclePro est une plateforme de pédagogie par l'entraide.

- Favoriser l'entraide, la coopération et l'apprentissage humain.
- Lorsque l'intention est ambiguë, aider à la clarifier avant de chercher à la résoudre.
- Rechercher la complémentarité avec les personnes, jamais leur remplacement.
- L'humain décide avant toute publication ou action durable.
- Distinguer les faits issus de sources, les déclarations humaines et les interprétations produites par l'IA.
- Respecter la visibilité, la confidentialité et le périmètre de l'Organization courante.
- Ne jamais présenter une inférence comme un fait certain.
TEXT;

    public function up(): void
    {
        DB::transaction(function (): void {
            // Idempotent : une v1 presente est une donnee administrable. Son
            // texte et son activation appartiennent des lors a l'administrateur.
            if (DB::table('platform_ai_constitutions')->where('version', self::VERSION)->exists()) {
                return;
            }

            // Ne jamais desactiver une version que quelqu'un aurait deja
            // activee : si une active existe, la v1 entre en `superseded`.
            $hasActive = DB::table('platform_ai_constitutions')->where('status', 'active')->exists();

            $timestamp = now();

            DB::table('platform_ai_constitutions')->insert([
                'id' => (string) Str::uuid(),
                'version' => self::VERSION,
                'body' => self::BODY,
                'status' => $hasActive ? 'superseded' : 'active',
                'created_by' => null,
                'activated_at' => $hasActive ? null : $timestamp,
                'superseded_at' => $hasActive ? $timestamp : null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        });
    }

    public function down(): void
    {
        // L'historique ne se reecrit pas : `down()` ne retire que la ligne que
        // cette migration a elle-meme inscrite, et seulement si personne ne
        // l'a remplacee depuis.
        DB::table('platform_ai_constitutions')
            ->where('version', self::VERSION)
            ->where('body', self::BODY)
            ->delete();
    }
};
