<?php

namespace App\Services\Ai;

use App\Models\LoopPluginAiModel;
use App\Models\User;
use App\Services\Loops\LoopAiAssistants;
use App\Support\Ai\Pricing\DynamicPricingSource;
use Illuminate\Support\Carbon;

/**
 * Quel modele sert quel assistant, et cette preuve de gratuite tient-elle
 * encore ? (TASK-1617)
 *
 * Seul lecteur et seul ecrivain de `loop_plugin_ai_models`, et par ailleurs
 * la `DynamicPricingSource` qu'`AiPricingCatalog` consulte a l'appel.
 *
 * Les deux roles tiennent dans une classe parce qu'ils repondent a la MEME
 * question — « ce slug est-il, maintenant, un modele gratuit que nous avons
 * choisi ? » — et que les separer aurait duplique la regle de fraicheur, donc
 * cree deux verdicts possibles.
 *
 * ## FERME PAR DEFAUT, a chaque etage
 *
 * Pas de ligne, `verified_free_at` nul, preuve perimee, slug inconnu,
 * assistant hors catalogue : NON eligible. Il n'existe aucun repli — ni vers
 * un autre modele gratuit, ni vers `openrouter/free`, ni vers le modele de
 * l'Organization, ni vers un modele payant. Un assistant sans modele
 * eligible ne genere pas ; c'est la SLICE D qui appliquera ce verdict, et il
 * est deja calculable ici.
 */
class LoopPluginAiModels implements DynamicPricingSource
{
    public const PLUGIN = LoopAiAssistants::PLUGIN;

    public const PROVIDER = 'openrouter';

    /**
     * Duree de validite d'une preuve de gratuite : 15 minutes.
     *
     * Ce n'est pas un cache, c'est une PEREMPTION. Un modele peut cesser
     * d'etre gratuit a tout moment ; au-dela de ce delai, la preuve n'est plus
     * recevable et il faut un nouveau releve. Sans releve possible : refus.
     */
    public const FREE_PROOF_TTL_SECONDS = 900;

    public const REASON_UNAVAILABLE = 'MODEL_UNAVAILABLE_OR_NOT_FREE';

    public function __construct(
        private OpenRouterModelCatalog $catalogue,
        private LoopAiAssistants $assistants,
    ) {}

    // ── Lecture ─────────────────────────────────────────────────────────────

    /**
     * La configuration de chaque assistant du catalogue, telle que l'ecran
     * doit la rendre.
     *
     * @return array<int, array<string, mixed>>
     */
    public function describe(bool $forceRefresh = false): array
    {
        $libres = $this->catalogue->verifiedFreeModels($forceRefresh);
        $lignes = LoopPluginAiModel::query()
            ->where('plugin_key', self::PLUGIN)
            ->with('updatedBy:id,name')
            ->get()
            ->keyBy('assistant_key');

        $sortie = [];

        // TASK-1621 — le vivant, pas le complet : un role dormant n'a pas
        // de modele a choisir.
        foreach ($this->assistants->catalogueVivant() as $key => $definition) {
            $ligne = $lignes[$key] ?? null;
            $slug = $ligne?->model_slug;

            $sortie[] = [
                'assistant_key' => $key,
                'label' => $this->assistants->label($key),
                'model_slug' => $slug,
                'provider' => $ligne?->provider ?? self::PROVIDER,
                'verified_free_at' => $ligne?->verified_free_at,
                'proof_fresh' => $this->proofIsFresh($ligne),
                // Le slug est-il ENCORE au catalogue des gratuits ?
                'still_free' => $slug !== null && array_key_exists($slug, $libres),
                'known_model' => $slug !== null && array_key_exists($slug, $libres),
                // Eligible = preuve fraiche ET slug encore au catalogue.
                // C'est la MEME regle que la garde, mais evaluee ici sur le
                // catalogue DEJA releve pour cet ecran — pas un second releve.
                'eligible' => $this->proofIsFresh($ligne)
                    && $slug !== null
                    && array_key_exists($slug, $libres),
                'catalog_entry' => $slug !== null ? ($libres[$slug] ?? null) : null,
                'updated_by' => $ligne?->updatedBy?->name,
                'updated_at' => $ligne?->updated_at,
            ];
        }

        return $sortie;
    }

    /**
     * La preuve de gratuite de cette ligne est-elle encore recevable ?
     *
     * `null` — jamais verifie — n'est pas frais. Une preuve n'est pas un
     * drapeau : elle vieillit.
     */
    public function proofIsFresh(?LoopPluginAiModel $ligne): bool
    {
        $verifie = $ligne?->verified_free_at;

        if ($verifie === null) {
            return false;
        }

        return $verifie->greaterThanOrEqualTo(Carbon::now()->subSeconds(self::FREE_PROOF_TTL_SECONDS));
    }

    /** La ligne d'un assistant, ou `null`. Lecture seule, sans reseau. */
    public function lineFor(string $assistantKey): ?LoopPluginAiModel
    {
        if (! $this->assistants->exists($assistantKey)) {
            return null;
        }

        return LoopPluginAiModel::query()
            ->where('plugin_key', self::PLUGIN)
            ->where('assistant_key', $assistantKey)
            ->first();
    }

    /** Renouveler la preuve apres un releve REUSSI. Appele par la garde seule. */
    public function renewProof(LoopPluginAiModel $ligne): void
    {
        $ligne->forceFill(['verified_free_at' => Carbon::now()])->save();
    }

    // ── Ecriture ────────────────────────────────────────────────────────────

    /**
     * Affecter un modele a un assistant.
     *
     * Le slug est confronte au catalogue COURANT avant d'etre enregistre : on
     * ne stocke pas un choix qu'on sait deja invalide. `verified_free_at` est
     * pose a cet instant — c'est la preuve, et elle commence a vieillir.
     *
     * Deux assistants peuvent partager le meme slug : rien ne l'interdit, et
     * la contrainte d'unicite porte sur (plugin, assistant), pas sur le slug.
     */
    public function assign(string $assistantKey, string $slug, ?User $actor = null): LoopPluginAiModel
    {
        if (! $this->assistants->exists($assistantKey)) {
            throw new \InvalidArgumentException("Assistant inconnu au catalogue : {$assistantKey}");
        }

        if (! $this->catalogue->isSlugVerifiedFree($slug)) {
            throw new \InvalidArgumentException(self::REASON_UNAVAILABLE.' : '.$slug);
        }

        return LoopPluginAiModel::query()->updateOrCreate(
            ['plugin_key' => self::PLUGIN, 'assistant_key' => $assistantKey],
            [
                'provider' => self::PROVIDER,
                'model_slug' => $slug,
                'verified_free_at' => Carbon::now(),
                'updated_by' => $actor?->id,
            ],
        );
    }

    // ── DynamicPricingSource ────────────────────────────────────────────────

    /**
     * Un tarif de 0 pour ce slug, UNIQUEMENT s'il est configure ET porte une
     * preuve de gratuite encore fraiche.
     *
     * ## AUCUN APPEL RESEAU. Jamais.
     *
     * Cette methode est sur le chemin de la COMPTABILISATION : elle est
     * appelee apres qu'un provider a deja repondu, pour chiffrer la ligne du
     * ledger. Y declencher un releve OpenRouter ferait dependre l'ecriture
     * d'une trace de la disponibilite d'un tiers — une panne de catalogue
     * deviendrait une perte de comptabilite, et une requete HTTP se cacherait
     * dans un calcul de cout.
     *
     * Elle lit donc EXCLUSIVEMENT la preuve PERSISTEE : le slug configure et
     * `verified_free_at`. Rafraichir cette preuve est le travail de
     * `LoopPluginModelGuard`, sur le chemin de la GENERATION, avant l'appel.
     *
     * Trois refus, et chacun compte :
     *  - un autre provider qu'OpenRouter : ce n'est pas notre sujet ;
     *  - un slug qu'aucun assistant n'utilise : nous n'avons rien verifie a
     *    son propos, et affirmer 0 serait inventer un tarif ;
     *  - un slug configure dont la preuve est absente ou perimee.
     *
     * `null` rend un cout INCONNU cote `AiPricingCatalog` — jamais 0.
     */
    public function entryFor(string $provider, ?string $model): ?array
    {
        if ($provider !== self::PROVIDER || ! is_string($model) || $model === '') {
            return null;
        }

        // `first()` sur la preuve la plus recente : deux assistants peuvent
        // partager le slug, et c'est le meme tarif pour les deux.
        $ligne = LoopPluginAiModel::query()
            ->where('plugin_key', self::PLUGIN)
            ->where('model_slug', $model)
            ->orderByDesc('verified_free_at')
            ->first();

        if (! $this->proofIsFresh($ligne)) {
            return null;
        }

        return ['input_per_1m' => 0.0, 'output_per_1m' => 0.0, 'free' => true];
    }
}
