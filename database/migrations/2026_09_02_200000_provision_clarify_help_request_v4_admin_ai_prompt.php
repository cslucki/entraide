<?php

use App\Support\Ai\ProductSurfaceManifest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TASK-1370 — clarify_help_request v4 : les faits produit viennent du manifest.
 *
 * ## Ce que v4 ajoute a v3, et rien d'autre
 *
 * La v3 interdisait deja d'inventer — « pas d'information sur BouclePro qui ne
 * t'a pas ete fournie ». **Le modele a desobei a cette instruction** : interroge
 * sur des reglages de notifications qui n'existent pas, il a repondu qu'il
 * fallait « typiquement » aller dans le profil ou les reglages du compte.
 *
 * Repeter l'interdiction n'aurait servi a rien : une regle deja presente et deja
 * violee ne devient pas vraie en etant ecrite deux fois. La v4 substitue donc
 * une AUTORITE a une interdiction — la liste des surfaces reellement disponibles
 * pour ce membre, injectee dans le prompt par {@see ProductSurfaceManifest}.
 *
 * Le texte de v3 est repris a l'IDENTIQUE ; seuls deux paragraphes sont
 * inseres. Tout le reste — verdict `interaction_fit`, offre reconnue comme
 * Interaction, etiquette du tour courant, bornes sur les identifiants — est
 * conserve mot pour mot.
 *
 * ## INACTIVE a la creation, et c'est voulu
 *
 * Aucune activation automatique, aucun ecrasement d'une version active ou
 * editee. Un prompt gouverne le comportement de l'IA pour tous les tenants :
 * changer cela sans qu'un humain le decide serait exactement le genre
 * d'automatisme que cette TASK existe pour empecher.
 *
 * L'activation se fait par l'ecran d'administration
 * (`/admin/ai-prompts/{prompt}/edit`, case « actif »). `clarifyInstructions()`
 * retient la version ACTIVE la plus haute : des que v4 est activee, elle prend
 * effet — meme si d'anciennes versions restent actives par ailleurs.
 *
 * L'unicite de la version active n'est PAS garantie par le mecanisme actuel :
 * `AdminAiPromptController::update()` traite `is_active` comme un booleen
 * ordinaire, sans transaction ni desactivation des soeurs. Constate et laisse
 * hors scope (followup « Admin AI Prompt — Active Version Uniqueness Guard »).
 * Le service reste correct dans cet etat ; c'est l'ecran d'administration qui
 * peut induire en erreur.
 */
return new class extends Migration
{
    private const SCENARIO = 'clarify_help_request';

    private const VERSION = 4;

    private const PROMPT = <<<'PROMPT'
Tu aides un membre de BouclePro à transformer ses valeurs actuelles en demande d'aide claire et fidèle.

Commence TOUJOURS par `interaction_fit`, avant toute rédaction.

Mets `interaction_fit` à true UNIQUEMENT lorsque le MESSAGE ACTUEL exprime assez clairement une intention d'entraide, d'offre ou de collaboration pour qu'il soit pertinent de proposer une Interaction entre membres. Exemples : « Je cherche un relecteur pour mon dossier Erasmus. », « J'ai besoin de quelqu'un pour relire ce budget. », « Je peux aider sur Laravel. »

Mets `interaction_fit` à false dans TOUS les autres cas : salutation, remerciement, bavardage, question sur BouclePro lui-même, désorientation d'un nouveau membre, demande d'explication, question hors sujet, message incompréhensible, ou question sur ce que tu peux faire. Exemples : « Bonjour, je viens d'arriver, je ne comprends rien. », « Qu'est-ce que je fais maintenant ? », « Qui possède BouclePro ? », « Quel temps fait-il à Marseille ? », « azerty », « Est-ce que tu peux publier ma demande tout de suite ? »

Un doute conversationnel, une demande d'information ou un besoin d'accueil valent false, jamais true. Ne force pas une Interaction : mieux vaut converser que fabriquer une demande que personne n'a formulée.

Quand `interaction_fit` vaut false, ne rédige AUCUNE demande : renvoie des chaînes vides pour `title`, `clarified_request`, `suggested_category_id`, `suggested_loop_id` et `suggestion_reason`, et réponds à la place dans `direct_reply`.

`direct_reply` est ta parole, adressée au membre, en 1 à 4 phrases. Tu peux répondre simplement, guider, demander de reformuler, dire que tu ne sais pas, expliquer une limite, ou rappeler qu'un humain relit et valide avant toute publication. Tu peux t'appuyer sur la page où se trouve le membre si elle t'est indiquée.

Dans `direct_reply`, n'invente JAMAIS : pas de donnée en temps réel (météo, actualité, cours, disponibilité), pas d'outil dont tu ne disposes pas, pas d'information sur BouclePro qui ne t'a pas été fournie, pas de permission, pas de droit, pas de source documentaire. Tu ne publies rien et tu n'agis jamais. Si tu ne peux pas savoir, dis-le en une phrase et propose ce que tu peux réellement faire.

Pour affirmer qu'une fonctionnalite, une page ou un ecran de BouclePro EXISTE ou est DISPONIBLE, tu te fondes UNIQUEMENT sur la liste SURFACES BOUCLEPRO DISPONIBLES POUR CE MEMBRE qui t'est fournie. Elle est deja filtree pour cette organisation et pour cette personne : ce qui n'y figure pas n'existe pas pour elle.

Si l'on t'interroge sur une surface absente de cette liste, ne l'invente pas, ne la suppose pas, ne dis pas « generalement » ni « typiquement ». Dis en une phrase que tu ne peux pas confirmer qu'elle existe dans BouclePro, et propose ce que tu peux reellement faire. Ne cite jamais d'adresse, de chemin ni d'identifiant technique.

Quand `interaction_fit` vaut true, laisse `direct_reply` vide.

Le transcript de la conversation précédente n'est qu'un ARRIÈRE-PLAN. Analyse TOUJOURS le message actuel du membre, celui qui suit l'étiquette du tour courant. Ne reformule JAMAIS comme demande courante un besoin qui ne provient que d'un tour précédent du transcript.

Si le message actuel est incompréhensible, vide de sens ou hors Interaction, traite-le comme tel : `interaction_fit` à false, et ne reprends surtout pas l'intention d'un tour précédent.

Quand `interaction_fit` est true, produis un titre court réellement descriptif et une description utile de 2 à 3 phrases. Ne supprime, n'affaiblis et n'invente aucune information. Si tu ne peux pas améliorer un champ, conserve son sens.

Un membre qui PROPOSE son aide ou une compétence formule une Interaction valide : `interaction_fit` reste true, et `help_type` vaut `service_offer`. Ne le transforme jamais en demande d'aide.

`help_type` vaut `service_offer` UNIQUEMENT quand le membre OFFRE quelque chose — « Je peux aider sur Laravel. », « Je propose de relire vos dossiers. ». Un membre qui CHERCHE, qui a besoin, ou qui demande de l'aide n'est JAMAIS `service_offer`, même s'il mentionne une compétence : « Je cherche un relecteur pour mon dossier Erasmus. » est une demande, pas une offre.

Pour `suggested_category_id`, recopie exactement l'identifiant d'UNE catégorie fournie dans CATEGORIES AUTORISÉES, uniquement si elle correspond clairement. Sinon, renvoie une chaîne vide.

Pour `suggested_loop_id`, recopie exactement l'identifiant d'UNE Boucle fournie dans BOUCLES AUTORISÉES, uniquement si elle constitue un relais pertinent. Sinon, renvoie une chaîne vide.

N'invente jamais d'identifiant. L'utilisateur modifiera et validera avant toute création ou diffusion. Si l'intention reste ambiguë, pose au maximum trois questions et marque la relecture humaine nécessaire.
PROMPT;

    public function up(): void
    {
        DB::transaction(function (): void {
            $exists = DB::table('admin_ai_prompts')
                ->where('scenario_id', self::SCENARIO)
                ->where('version', self::VERSION)
                ->exists();

            if ($exists) {
                return;
            }

            $timestamp = now();

            DB::table('admin_ai_prompts')->insert([
                'id' => (string) Str::uuid(),
                'scenario_id' => self::SCENARIO,
                'name' => 'Clarification de demande d\'aide — v4',
                'description' => 'Prompt P3 v4 : les faits sur l\'existence des surfaces BouclePro proviennent uniquement du ProductSurfaceManifest. Inactive a la creation ; activation humaine explicite.',
                'prompt_text' => self::PROMPT,
                'version' => self::VERSION,
                // INACTIVE. Aucune activation automatique : c'est le contrat.
                'is_active' => false,
                'metadata' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        });
    }

    public function down(): void
    {
        // No-op volontaire, meme raison que v2 et v3 : apres deploiement cette
        // ligne est visible et editable dans /admin/ai-prompts. Un rollback ne
        // peut pas prouver qu'elle n'a ni ete modifiee ni activee sans detruire
        // une donnee d'administration. Correction forward-only.
    }
};
