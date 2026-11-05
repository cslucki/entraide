<?php

namespace App\Services;

use App\Models\Badge;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Entraide\BilanEntraide;
use App\Support\Entraide\CatalogueDeBadges;

class BadgeService
{
    /**
     * TASK-1757 (§9.7) — `$organization` est OPTIONNEL, et c'est deliberé.
     *
     * Les sept badges herites comptent sans borne de tenant depuis l'origine.
     * Les borner maintenant RETIRERAIT des badges deja acquis a des membres de
     * plusieurs Organizations — un changement de comportement que cette TASK
     * n'a pas mandat de faire, et qui se verrait sur des profils. Ils restent
     * donc inchangés.
     *
     * Les trois badges d'entraide, eux, sont bornes : ils passent par
     * `BilanEntraide`, qui exige une Organization explicite depuis la fuite
     * inter-tenant corrigee en T1755. Sans Organization resoluble, ils ne sont
     * simplement pas evalues — mieux vaut un badge en retard qu'un badge compte
     * sur le mauvais perimetre.
     */
    public function checkAndAward(User $user, ?Organization $organization = null): void
    {
        $earned = $user->badges()->pluck('key')->all();

        $completedCount = Transaction::where(function ($q) use ($user) {
            $q->where('buyer_id', $user->id)->orWhere('seller_id', $user->id);
        })->where('status', 'completed')->count();

        $this->maybeAward($user, $earned, 'first_exchange', $completedCount >= 1);
        $this->maybeAward($user, $earned, 'five_exchanges', $completedCount >= 5);
        $this->maybeAward($user, $earned, 'ten_exchanges', $completedCount >= 10);

        $serviceCount = $user->services()->count();
        $this->maybeAward($user, $earned, 'first_service', $serviceCount >= 1);
        $this->maybeAward($user, $earned, 'five_services', $serviceCount >= 5);

        $reviewCount = $user->reviewsReceived()->count();
        $rating = $user->rating !== null ? (float) $user->rating : 0.0;
        $this->maybeAward($user, $earned, 'top_rated', $reviewCount >= 3 && $rating >= 4.5);

        $positiveReviewsGiven = $user->reviewsGiven()->where('rating', '>=', 4)->count();
        $this->maybeAward($user, $earned, 'generous', $positiveReviewsGiven >= 3);

        $this->badgesDEntraide($user, $earned, $organization);
    }

    /**
     * TASK-1757 §9.7 — les trois badges qui parlent d'AIDE DONNEE.
     *
     * ## Pourquoi ils ne doublonnent pas les badges herites
     *
     * `first_exchange` compte les echanges dans les DEUX sens : on l'obtient en
     * se faisant aider. « Premier coup de main » n'est merite qu'en AIDANT —
     * c'est un fait different, et c'est celui que le produit veut celebrer.
     * Meme ecart entre `five_exchanges` (5 echanges) et « 5 personnes aidees »
     * (5 personnes DISTINCTES, aidees par soi).
     *
     * ## Les discussions directes n'en font pas partie
     *
     * `BilanEntraide` appelle `scopeDirectConversation()`, la definition
     * canonique. Sans cela, dire bonjour a cinq personnes aurait decerne
     * « 5 personnes aidees ».
     */
    private function badgesDEntraide(User $user, array $earned, ?Organization $organization): void
    {
        if ($organization === null) {
            return;
        }

        $bilan = BilanEntraide::pour($user, $organization);

        $this->maybeAward($user, $earned, CatalogueDeBadges::PREMIER_COUP_DE_MAIN, $bilan['personnes_aidees'] >= 1);
        $this->maybeAward($user, $earned, CatalogueDeBadges::CINQ_PERSONNES_AIDEES, $bilan['personnes_aidees'] >= 5);
        $this->maybeAward($user, $earned, CatalogueDeBadges::AIDE_UN_NOUVEAU, $this->aAideUnNouveau($user, $organization));
    }

    /**
     * A-t-il aide quelqu'un pour sa TOUTE PREMIERE entraide ?
     *
     * ## La definition, et pourquoi pas une date
     *
     * « Nouveau membre » pourrait se lire comme « inscrit depuis moins de N
     * jours ». Un tel seuil est arbitraire, et surtout il se perime : le badge
     * deviendrait inatteignable dans une communaute qui n'accueille personne
     * pendant deux mois.
     *
     * La definition retenue ne depend d'aucun calendrier : la personne aidee
     * n'avait, a ce moment-la, AUCUNE autre entraide terminee. C'est bien
     * « son premier pas », et ca reste vrai pour toujours.
     *
     * Mesure : une personne aidee dont le total d'entraides terminees vaut 1 —
     * celle que l'on vient de clore.
     */
    private function aAideUnNouveau(User $user, Organization $organization): bool
    {
        $aides = Transaction::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('status', 'completed')
            ->where('seller_id', $user->id)
            ->whereNot(fn ($q) => $q->directConversation())
            ->pluck('buyer_id')
            ->unique();

        if ($aides->isEmpty()) {
            return false;
        }

        // Combien d'entraides terminees chaque personne aidee compte-t-elle,
        // tous partenaires confondus. Une seule requete, pas une par personne.
        $totaux = Transaction::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('status', 'completed')
            ->whereNot(fn ($q) => $q->directConversation())
            ->whereIn('buyer_id', $aides->all())
            ->selectRaw('buyer_id, count(*) as total')
            ->groupBy('buyer_id')
            ->pluck('total', 'buyer_id');

        foreach ($aides as $aide) {
            if ((int) ($totaux[$aide] ?? 0) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * TASK-1757 — la ligne du badge est CREEE si elle manque.
     *
     * Avant : `Badge::where('key')->first()` et, si rien, retour silencieux.
     * Les badges n'etant poses par AUCUNE migration — seulement par
     * `BadgeSeeder` —, un environnement deja deploye n'aurait jamais attribue
     * les trois nouveaux badges : condition remplie, ligne absente, et pas un
     * mot dans les journaux.
     *
     * `firstOrCreate` depuis `CatalogueDeBadges` ferme ce trou sans migration
     * ni reprise de donnees, et reste idempotent. Une cle absente du catalogue
     * garde l'ancien comportement : on ne fabrique pas un badge qu'aucune
     * definition ne decrit.
     */
    private function maybeAward(User $user, array $earned, string $key, bool $condition): void
    {
        if (! $condition || in_array($key, $earned)) {
            return;
        }

        $definition = CatalogueDeBadges::definition($key);

        $badge = $definition === null
            ? Badge::where('key', $key)->first()
            : Badge::firstOrCreate(['key' => $key], $definition);

        if ($badge) {
            $user->badges()->attach($badge->id, ['earned_at' => now()]);
        }
    }
}
