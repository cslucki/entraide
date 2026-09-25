<?php

namespace App\Support\ScenarioManifest;

/**
 * Garde de securite sandbox/tenant (spec 10.1 et 18).
 *
 * Elle s'AJOUTE au rejet des champs inconnus. Un `organization_id` serait deja
 * refuse comme champ inconnu ; la spec exige neanmoins un code dedie, parce
 * qu'un SuperAdmin qui lit "champ inconnu" corrige une faute de frappe, alors
 * qu'il doit lire "ce document tente de designer un tenant" et refuser la
 * source. La distinction est un message de securite, pas un detail cosmetique.
 *
 * Les trois familles ne se comparent pas de la meme facon, et c'est la spec qui
 * l'impose :
 *  - tenant et propriete interdite : comparaison en ASCII lowercase (10.1) ;
 *  - objet exclu : comparaison NORMALISEE, parce que la section 18 interdit
 *    explicitement "une cle equivalente en snake_case, camelCase ou casse
 *    differente".
 */
final class ManifestForbiddenNames
{
    /**
     * Designer une Organization cible. Le manifeste decrit TOUJOURS une
     * nouvelle sandbox (spec 4.1).
     *
     * @var list<string>
     */
    public const TENANT_TARGETS = [
        'organization_id',
        'target_organization',
        'target_organization_id',
        'tenant',
        'tenant_id',
        'community',
        'community_id',
        'current_community',
    ];

    /**
     * Designer du code, du stockage ou un secret. `id` est autorise a la
     * RACINE seulement : c'est la stable key du pack, pas un identifiant DB.
     *
     * @var list<string>
     */
    public const FORBIDDEN_PROPERTIES = [
        'id',
        'uuid',
        'model',
        'class',
        'class_name',
        'table',
        'connection',
        'disk',
        'path',
        'command',
        'callback',
        'provider',
        'api_key',
        'secret',
        'token',
    ];

    /**
     * Objets derives, systeme ou hors scope V1 (spec 18). Les formes sont
     * donnees au singulier : la normalisation traite le pluriel.
     *
     * @var list<string>
     */
    public const EXCLUDED_OBJECTS = [
        'ai_interaction',
        'ai_trace',
        'provider_call',
        'embedding',
        'dossier_chunk',
        'derived_knowledge_note',
        'rag_result',
        'citation',
        'agent_answer',
        'notification',
        'referral',
        'scenario_pack_entity',
        'scenario_pack_load',
        'transaction',
        'point_ledger',
        'badge',
        'loop_invitation',
        'join_request',
        'custom_loop_type',
        'reaction',
        'article_series',
        'review',
        'quiz',
        // Pluriel irregulier : `quizzes` ne se derive pas de `quiz` par la
        // regle de pluralisation simple utilisee ci-dessous, et c'est la
        // forme que la spec 12.6 cite nommement.
        'quizzes',
        'course_quiz',
    ];

    /**
     * Classe un nom de propriete INCONNU du schema. Rend `null` si le nom
     * n'appartient a aucune famille interdite : c'est alors un simple champ
     * inconnu.
     */
    public static function classify(string $name, bool $isRoot = false): ?ManifestErrorCode
    {
        $lower = strtolower($name);

        if (in_array($lower, self::TENANT_TARGETS, true)) {
            return ManifestErrorCode::TENANT_TARGET_FORBIDDEN;
        }

        if ($isRoot && $lower === 'id') {
            return null;
        }

        if (in_array($lower, self::FORBIDDEN_PROPERTIES, true)) {
            return ManifestErrorCode::FORBIDDEN_PROPERTY;
        }

        if (in_array(self::normalize($name), self::excludedObjectForms(), true)) {
            return ManifestErrorCode::FORBIDDEN_OBJECT;
        }

        return null;
    }

    /**
     * Message associe a un code de garde. Centralise ici pour qu'un meme
     * defaut porte toujours la meme phrase, condition du determinisme du
     * rapport.
     */
    public static function messageFor(ManifestErrorCode $code, string $name): string
    {
        return match ($code) {
            ManifestErrorCode::TENANT_TARGET_FORBIDDEN => sprintf(
                "Property '%s' targets an existing tenant; a manifest always describes a new sandbox.",
                $name,
            ),
            ManifestErrorCode::FORBIDDEN_PROPERTY => sprintf(
                "Property '%s' is forbidden; a manifest declares data, never code, storage or credentials.",
                $name,
            ),
            ManifestErrorCode::FORBIDDEN_OBJECT => sprintf(
                "Property '%s' declares an object that is excluded from Manifest V1.",
                $name,
            ),
            default => sprintf("Unknown property '%s'.", $name),
        };
    }

    /**
     * Toutes les formes normalisees d'un objet exclu : le singulier et son
     * pluriel simple. `pointLedger`, `point_ledger` et `PointLedgers` se
     * reduisent tous a `pointledger`.
     *
     * @return list<string>
     */
    private static function excludedObjectForms(): array
    {
        static $forms = null;

        if ($forms === null) {
            $forms = [];

            foreach (self::EXCLUDED_OBJECTS as $word) {
                $normalized = self::normalize($word);
                $forms[] = $normalized;
                $forms[] = $normalized.'s';
                $forms[] = $normalized.'es';
            }

            $forms = array_values(array_unique($forms));
        }

        return $forms;
    }

    private static function normalize(string $name): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', $name));
    }
}
