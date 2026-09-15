<?php

namespace App\Services\Ai;

use App\Models\AiConfig;
use App\Models\AiCreditSettingChange;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1563 — le rerank Cohere se pilote depuis l'ADMINISTRATION.
 *
 * ## Ce que cette classe remplace
 *
 * TASK-1562 avait livre le mecanisme, mais son activation passait par des
 * variables d'environnement : un drapeau global, et une allowlist
 * d'identifiants ou de slugs a tenir a la main. Ouvrir un pilote demandait donc
 * un acces serveur, un redeploiement, et personne ne savait qui avait allume
 * quoi ni quand.
 *
 * Deux interrupteurs d'ecran les remplacent, et une trace signee dit qui a
 * agi.
 *
 * ## Deux verrous, toujours en serie
 *
 *     plateforme OFF                -> PERSONNE ne reranke
 *     plateforme ON  + org OFF      -> cette Organization ne reranke pas
 *     plateforme ON  + org ON       -> cette Organization peut reranker
 *
 * Le drapeau plateforme reste l'arret d'urgence : le couper eteint tout, sans
 * avoir a repasser sur chaque Organization.
 *
 * ## Qui fait autorite, de la base ou de l'environnement
 *
 * La BASE, des qu'elle a quelque chose a dire. `AI_KNOWLEDGE_RERANK_ENABLED`
 * survit uniquement comme DEFAUT D'AMORCAGE : il repond tant qu'aucun
 * administrateur n'a touche l'interrupteur, ce qui couvre le premier
 * deploiement et le cas ou la base n'aurait jamais ete ecrite.
 *
 *     env=true  + AiConfig='0'  ->  OFF     la base l'emporte
 *     env=false + AiConfig='1'  ->  ON      la base l'emporte
 *     env=true  + AiConfig absent ->  ON    l'environnement amorce
 *
 * L'allowlist d'environnement PAR ORGANIZATION, elle, n'existe plus : la
 * colonne `organization_ai_settings.rerank_enabled` est la seule autorite.
 * Deux sources pour une meme decision auraient rendu la table de verite
 * ci-dessus indefendable — une Organization listee dans l'environnement mais
 * eteinte a l'ecran aurait rerankee quand meme.
 *
 * ## Fail closed
 *
 * `ai_configs.value` est une colonne `text`. Un `(bool)` naif y serait un
 * piege : `(bool) 'false'` vaut TRUE. La lecture passe donc par une LISTE
 * BLANCHE a comparaison stricte — tout ce qui n'y figure pas ferme la porte.
 * Une valeur qu'on ne sait pas lire n'autorise rien.
 */
class AiRerankSettings
{
    public const KEY_PLATFORM_ENABLED = 'rerank_enabled';

    /**
     * Le drapeau PLATEFORME, base d'abord, environnement en amorcage.
     */
    public function platformEnabled(): bool
    {
        $stocke = AiConfig::get(self::KEY_PLATFORM_ENABLED);

        // `null` — et lui seul — signifie « aucun administrateur n'a jamais
        // tranche ». Une chaine vide, elle, est une valeur ECRITE : elle ferme.
        if ($stocke === null) {
            return (bool) config('ai.knowledge.rerank.enabled', false);
        }

        return self::truthy($stocke);
    }

    /**
     * Cette Organization est-elle autorisee a reranker ?
     *
     * La question porte sur l'Organization SEULE. Le drapeau plateforme est
     * interroge ailleurs, par la porte — de sorte qu'un ecran d'administration
     * puisse afficher « autorisee, mais la plateforme est eteinte » plutot que
     * de melanger les deux en un seul « non » muet.
     */
    public function organizationEnabled(string $organizationId): bool
    {
        $organizationId = trim($organizationId);

        if ($organizationId === '') {
            return false;
        }

        return (bool) OrganizationAiSetting::query()
            ->where('organization_id', $organizationId)
            ->value('rerank_enabled');
    }

    /**
     * Ecrit le drapeau plateforme et trace ce qui a change.
     */
    public function updatePlatform(bool $enabled, ?User $author): ?AiCreditSettingChange
    {
        $before = ['rerank_enabled' => $this->platformEnabled()];
        $after = ['rerank_enabled' => $enabled];

        return DB::transaction(function () use ($before, $after, $author): ?AiCreditSettingChange {
            // `'1'` / `'0'` en CHAINES, comme le reste du depot : la colonne
            // est `text`, et un booleen PHP s'y ecrirait en chaine vide.
            AiConfig::set(self::KEY_PLATFORM_ENABLED, $after['rerank_enabled'] ? '1' : '0');

            return $this->trace(AiCreditSettingChange::SCOPE_PLATFORM, null, $before, $after, $author);
        });
    }

    /**
     * Cette Organization peut-elle seulement etre autorisee ?
     *
     * Non si elle n'a AUCUNE configuration IA. Ce n'est pas une limite
     * technique qu'on contourne : `provider` et `model` sont `NOT NULL` sur
     * `organization_ai_settings`, donc creer une ligne pour y poser le seul
     * drapeau de rerank reviendrait a FABRIQUER une configuration IA qu'aucun
     * administrateur n'a choisie.
     *
     * Et ce serait autoriser l'impossible : sans credential tenant,
     * `resolveRerankingInstance()` rend `null` et cette Organization ne
     * rerankerait pas de toute facon. Mieux vaut le dire a l'ecran que
     * d'accepter un clic sans effet.
     */
    public function canBeEnabledFor(Organization $organization): bool
    {
        return OrganizationAiSetting::query()
            ->where('organization_id', $organization->id)
            ->exists();
    }

    /**
     * Ecrit l'autorisation d'une Organization et trace ce qui a change.
     *
     * Rend `null` sans rien ecrire si l'Organization n'a pas de configuration
     * IA : voir `canBeEnabledFor()`.
     */
    public function updateOrganization(Organization $organization, bool $enabled, ?User $author): ?AiCreditSettingChange
    {
        $setting = OrganizationAiSetting::query()
            ->where('organization_id', $organization->id)
            ->first();

        if ($setting === null) {
            return null;
        }

        $before = ['rerank_enabled' => (bool) $setting->rerank_enabled];
        $after = ['rerank_enabled' => $enabled];

        return DB::transaction(function () use ($organization, $setting, $after, $before, $author): ?AiCreditSettingChange {
            $setting->rerank_enabled = $after['rerank_enabled'];
            $setting->save();

            return $this->trace(AiCreditSettingChange::SCOPE_ORGANIZATION, $organization, $before, $after, $author);
        });
    }

    /**
     * Le dernier changement de RERANK d'un perimetre — jamais un changement de
     * credit, meme s'ils partagent la meme table.
     */
    public function lastChange(?Organization $organization): ?AiCreditSettingChange
    {
        return AiCreditSettingChange::query()
            ->where('setting_kind', AiCreditSettingChange::KIND_RERANK)
            ->with('author:id,name')
            ->when(
                $organization === null,
                static fn ($query) => $query->where('scope', AiCreditSettingChange::SCOPE_PLATFORM),
                static fn ($query) => $query->where('scope', AiCreditSettingChange::SCOPE_ORGANIZATION)->where('organization_id', $organization->id),
            )
            ->latest('created_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function trace(string $scope, ?Organization $organization, array $before, array $after, ?User $author): ?AiCreditSettingChange
    {
        $changes = [];

        foreach ($after as $field => $value) {
            if (($before[$field] ?? null) !== $value) {
                $changes[$field] = ['from' => $before[$field] ?? null, 'to' => $value];
            }
        }

        // Rien n'a bouge : on n'ecrit pas une ligne d'audit pour dire qu'un
        // administrateur a enregistre un formulaire sans rien changer.
        if ($changes === []) {
            return null;
        }

        return AiCreditSettingChange::create([
            'scope' => $scope,
            'setting_kind' => AiCreditSettingChange::KIND_RERANK,
            'organization_id' => $organization?->id,
            'changes' => $changes,
            'changed_by' => $author?->id,
        ]);
    }

    /**
     * Liste BLANCHE, comparaison stricte. Tout le reste ferme.
     *
     * Meme convention que `AiUserCreditSettings` : une valeur inattendue en
     * base ne doit jamais s'interpreter comme une autorisation.
     */
    private static function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
    }
}
