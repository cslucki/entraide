<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TASK-1350 — provisionne la v3 du prompt `clarify_help_request`, celle qui
 * instruit `interaction_fit`.
 *
 * ## Pourquoi une version, et pas une edition de la v2
 *
 * Parce que le champ n'a d'autorite QUE sous un prompt qui l'a instruit
 * (`ClarifyUserHelpRequestService::INTERACTION_FIT_MIN_PROMPT_VERSION`). Editer
 * la v2 rendrait le verdict autoritaire sans que la version le dise : on
 * jugerait une reponse a l'aune d'un contrat qu'elle n'a pas recu. Une version
 * nouvelle rend le basculement lisible, reversible d'un clic dans
 * `/admin/ai-prompts`, et trace dans l'historique.
 *
 * ## Activation : la decision admin passe avant la notre
 *
 * La v2 ne s'activait que si le scenario etait TOTALEMENT absent — toute ligne
 * existante valant decision administrative. La meme prudence ici aboutirait a
 * une v3 inerte partout, donc a une TASK sans effet. On resout la tension
 * autrement, et de facon PROUVABLE : la v3 s'active si — et seulement si — le
 * prompt actuellement actif est la v2 telle que la migration du 15/08 l'a
 * ecrite, octet pour octet. Personne ne l'a donc jamais editee, et la remplacer
 * n'ecrase aucune decision humaine.
 *
 * Des qu'un administrateur a touche a ce texte, ou active une autre version, la
 * v3 est inseree INACTIVE : elle apparait dans `/admin/ai-prompts`, prete a
 * etre activee par un humain qui verra ce qu'il fait.
 *
 * ## Immuabilite
 *
 * Les deux textes sont des copies figees, comme dans la migration v2 : une
 * migration historique ne doit dependre ni d'un seeder ni d'une classe metier
 * qui evoluera.
 */
return new class extends Migration
{
    private const SCENARIO = 'clarify_help_request';

    private const VERSION = 3;

    /** Copie EXACTE du texte v2 provisionne le 15/08/2026 — sert de preuve de non-edition. */
    private const V2_PROMPT = <<<'PROMPT'
Tu aides un membre de BouclePro à transformer ses valeurs actuelles en demande d'aide claire et fidèle.

Produis un titre court réellement descriptif et une description utile de 2 à 3 phrases. Ne supprime, n'affaiblis et n'invente aucune information. Si tu ne peux pas améliorer un champ, conserve son sens.

Pour `suggested_category_id`, recopie exactement l'identifiant d'UNE catégorie fournie dans CATEGORIES AUTORISÉES, uniquement si elle correspond clairement. Sinon, renvoie une chaîne vide.

Pour `suggested_loop_id`, recopie exactement l'identifiant d'UNE Boucle fournie dans BOUCLES AUTORISÉES, uniquement si elle constitue un relais pertinent. Sinon, renvoie une chaîne vide.

N'invente jamais d'identifiant. L'utilisateur modifiera et validera avant toute création ou diffusion. Si l'intention reste ambiguë, pose au maximum trois questions et marque la relecture humaine nécessaire.
PROMPT;

    private const PROMPT = <<<'PROMPT'
Tu aides un membre de BouclePro à transformer ses valeurs actuelles en demande d'aide claire et fidèle.

Commence TOUJOURS par `interaction_fit`, avant toute rédaction.

Mets `interaction_fit` à true UNIQUEMENT lorsque le MESSAGE ACTUEL exprime assez clairement une intention d'entraide, d'offre ou de collaboration pour qu'il soit pertinent de proposer une Interaction entre membres. Exemples : « Je cherche un relecteur pour mon dossier Erasmus. », « J'ai besoin de quelqu'un pour relire ce budget. », « Je peux aider sur Laravel. »

Mets `interaction_fit` à false dans TOUS les autres cas : salutation, remerciement, bavardage, question sur BouclePro lui-même, désorientation d'un nouveau membre, demande d'explication, question hors sujet, message incompréhensible, ou question sur ce que tu peux faire. Exemples : « Bonjour, je viens d'arriver, je ne comprends rien. », « Qu'est-ce que je fais maintenant ? », « Qui possède BouclePro ? », « Quel temps fait-il à Marseille ? », « azerty », « Est-ce que tu peux publier ma demande tout de suite ? »

Un doute conversationnel, une demande d'information ou un besoin d'accueil valent false, jamais true. Ne force pas une Interaction : mieux vaut converser que fabriquer une demande que personne n'a formulée.

Quand `interaction_fit` vaut false, ne rédige AUCUNE demande : renvoie des chaînes vides pour `title`, `clarified_request`, `suggested_category_id`, `suggested_loop_id` et `suggestion_reason`, et réponds à la place dans `direct_reply`.

`direct_reply` est ta parole, adressée au membre, en 1 à 4 phrases. Tu peux répondre simplement, guider, demander de reformuler, dire que tu ne sais pas, expliquer une limite, ou rappeler qu'un humain relit et valide avant toute publication. Tu peux t'appuyer sur la page où se trouve le membre si elle t'est indiquée.

Dans `direct_reply`, n'invente JAMAIS : pas de donnée en temps réel (météo, actualité, cours, disponibilité), pas d'outil dont tu ne disposes pas, pas d'information sur BouclePro qui ne t'a pas été fournie, pas de permission, pas de droit, pas de source documentaire. Tu ne publies rien et tu n'agis jamais. Si tu ne peux pas savoir, dis-le en une phrase et propose ce que tu peux réellement faire.

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
            $versionExists = DB::table('admin_ai_prompts')
                ->where('scenario_id', self::SCENARIO)
                ->where('version', self::VERSION)
                ->exists();

            // Une ligne v3 deja presente est une donnee administrable : son
            // texte, ses metadata et son activation appartiennent desormais a
            // l'administrateur. La migration n'y retouche jamais.
            if ($versionExists) {
                return;
            }

            $actives = DB::table('admin_ai_prompts')
                ->where('scenario_id', self::SCENARIO)
                ->where('is_active', true)
                ->get(['id', 'version', 'prompt_text']);

            // Une seule ligne active, en v2, au texte intact : le socle
            // provisionne par le code, que personne n'a edite.
            $replaceable = $actives->count() === 1
                && (int) $actives->first()->version === 2
                && (string) $actives->first()->prompt_text === self::V2_PROMPT;

            $timestamp = now();

            DB::table('admin_ai_prompts')->insert([
                'id' => (string) Str::uuid(),
                'scenario_id' => self::SCENARIO,
                'name' => 'Clarification de demande d\'aide — v3',
                'description' => 'Prompt P3 v3 : verdict interaction_fit avant rédaction, offre reconnue comme Interaction valide, puis reformulation et suggestion bornée de catégorie et de Boucle.',
                'prompt_text' => self::PROMPT,
                'version' => self::VERSION,
                'is_active' => $replaceable,
                'metadata' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            if ($replaceable) {
                // Un seul actif par scenario : le service prend de toute facon
                // la version la plus haute, mais laisser deux lignes actives
                // rendrait l'ecran d'administration mensonger.
                DB::table('admin_ai_prompts')
                    ->where('id', $actives->first()->id)
                    ->update(['is_active' => false, 'updated_at' => $timestamp]);
            }
        });
    }

    public function down(): void
    {
        // No-op volontaire, meme raison que la migration v2 : apres
        // deploiement, cette ligne est visible et editable dans
        // /admin/ai-prompts. Un rollback ne peut pas prouver qu'elle n'a ni ete
        // modifiee ni utilisee sans detruire une donnee admin. Une correction
        // eventuelle doit donc etre forward-only.
    }
};
