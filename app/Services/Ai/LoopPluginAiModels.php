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

    /**
     * TASK-1622 — refus du contrat PAYANT : pas d'approbation, hors
     * shortlist, slug inconnu du catalogue, ou tarif absent du releve
     * statique. Un seul code : quelle que soit la marche manquante, le
     * verdict est le meme — cet appel ne part pas.
     */
    public const REASON_PAID_REJECTED = 'MODEL_NOT_APPROVED_OR_PRICE_UNKNOWN';

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
            ->with(['updatedBy:id,name', 'approvedBy:id,name'])
            ->get()
            ->keyBy('assistant_key');

        $sortie = [];

        // TASK-1621 — le vivant, pas le complet : un role dormant n'a pas
        // de modele a choisir.
        foreach ($this->assistants->catalogueVivant() as $key => $definition) {
            $ligne = $lignes[$key] ?? null;
            $slug = $ligne?->model_slug;
            $paye = $ligne?->isPaidApproved() ?? false;
            // TASK-1622 — le tarif statique du payant, une seule regle
            // (la meme que `assignPaid()` et que la garde d'execution).
            $tarif = ($paye && $slug !== null) ? $this->paidRateFor($slug) : null;

            $sortie[] = [
                'assistant_key' => $key,
                'label' => $this->assistants->label($key),
                'model_slug' => $slug,
                'provider' => $ligne?->provider ?? self::PROVIDER,
                'model_type' => $ligne?->model_type ?? LoopPluginAiModel::TYPE_FREE_VERIFIED,
                'verified_free_at' => $ligne?->verified_free_at,
                'approved_at' => $ligne?->approved_at,
                'approved_by' => $ligne?->approvedBy?->name,
                'paid_rate' => $tarif,
                'proof_fresh' => $this->proofIsFresh($ligne),
                // Le slug est-il ENCORE au catalogue des gratuits ?
                'still_free' => $slug !== null && array_key_exists($slug, $libres),
                'known_model' => $slug !== null && array_key_exists($slug, $libres),
                // Eligible = la MEME regle que la garde, evaluee par contrat :
                //  - FREE : preuve fraiche ET slug encore au catalogue des
                //    gratuits DEJA releve pour cet ecran — pas un second releve ;
                //  - PAYANT : approbation posee ET tarif statique present.
                'eligible' => $paye
                    ? ($ligne?->approved_at !== null && $tarif !== null)
                    : ($this->proofIsFresh($ligne)
                        && $slug !== null
                        && array_key_exists($slug, $libres)),
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
                'model_type' => LoopPluginAiModel::TYPE_FREE_VERIFIED,
                'verified_free_at' => Carbon::now(),
                // Revenir au gratuit EFFACE l'approbation payante : une ligne
                // ne porte qu'un contrat a la fois, jamais les deux.
                'approved_at' => null,
                'approved_by' => null,
                'updated_by' => $actor?->id,
            ],
        );
    }

    /**
     * TASK-1622 — approuver et affecter un modele PAYANT a un assistant.
     *
     * Quatre marches, toutes fermees par defaut, et l'ordre est celui du
     * moins cher a verifier :
     *
     *  1. l'assistant existe au catalogue des roles ;
     *  2. le slug figure a la SHORTLIST (`ai.multi_ai.paid_model_shortlist`)
     *     — un payant ne s'approuve pas en texte libre : la shortlist est
     *     petite, explicite, et versionnee avec le code ;
     *  3. son tarif figure au releve STATIQUE (`config/ai_pricing.php`),
     *     entree EXACTE, non-free — jamais `cost_status = unknown` pour un
     *     payant autorise, c'est le mandat ;
     *  4. le slug est CONNU du catalogue OpenRouter (peu importe son prix).
     *     Catalogue illisible = slug inconnu = refus.
     *
     * L'acteur est OBLIGATOIRE : une approbation sans auteur ne serait pas
     * une approbation (idiome `reviewed_by` du depot). `approved_at` /
     * `approved_by` sont l'audit trail ; `verified_free_at` est efface —
     * cette ligne ne porte plus une preuve de gratuite.
     */
    public function assignPaid(string $assistantKey, string $slug, User $actor): LoopPluginAiModel
    {
        if (! $this->assistants->exists($assistantKey)) {
            throw new \InvalidArgumentException("Assistant inconnu au catalogue : {$assistantKey}");
        }

        if (! array_key_exists($slug, $this->paidShortlist())
            || $this->paidRateFor($slug) === null
            || ! $this->catalogue->isSlugKnown($slug)) {
            throw new \InvalidArgumentException(self::REASON_PAID_REJECTED.' : '.$slug);
        }

        return LoopPluginAiModel::query()->updateOrCreate(
            ['plugin_key' => self::PLUGIN, 'assistant_key' => $assistantKey],
            [
                'provider' => self::PROVIDER,
                'model_slug' => $slug,
                'model_type' => LoopPluginAiModel::TYPE_PAID_APPROVED,
                'verified_free_at' => null,
                'approved_at' => Carbon::now(),
                'approved_by' => $actor->id,
                'updated_by' => $actor->id,
            ],
        );
    }

    /**
     * La shortlist payante : slug => libelle. Petite et explicite — elle
     * vit dans `config/ai.php`, pas en base : la proposer est une decision
     * de code, l'approuver reste une decision de SuperAdmin.
     *
     * @return array<string, string>
     */
    public function paidShortlist(): array
    {
        $shortlist = config('ai.multi_ai.paid_model_shortlist', []);

        return is_array($shortlist) ? $shortlist : [];
    }

    /**
     * Le tarif STATIQUE d'un slug payant — entree exacte, non-free — ou
     * `null`. C'est la meme question que se posent `assignPaid()`, la garde
     * d'execution et l'ecran : UNE regle, trois lecteurs.
     *
     * @return array{input_per_1m: float, output_per_1m: float}|null
     */
    public function paidRateFor(string $slug): ?array
    {
        $rate = \App\Support\Ai\AiPricingCatalog::staticRateFor(self::PROVIDER, $slug);

        if ($rate === null || $rate['free']) {
            return null;
        }

        return ['input_per_1m' => $rate['input_per_1m'], 'output_per_1m' => $rate['output_per_1m']];
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
        //
        // TASK-1622 — les lignes FREE seules : une ligne « payant approuve »
        // ne porte AUCUNE preuve de gratuite, et la laisser repondre ici
        // ferait chiffrer un modele payant a 0 au ledger. Son tarif vit au
        // releve statique, que `AiPricingCatalog` consulte AVANT cette
        // source — ici, elle n'a rien a dire.
        $ligne = LoopPluginAiModel::query()
            ->where('plugin_key', self::PLUGIN)
            ->where('model_type', LoopPluginAiModel::TYPE_FREE_VERIFIED)
            ->where('model_slug', $model)
            ->orderByDesc('verified_free_at')
            ->first();

        if (! $this->proofIsFresh($ligne)) {
            return null;
        }

        return ['input_per_1m' => 0.0, 'output_per_1m' => 0.0, 'free' => true];
    }
}
