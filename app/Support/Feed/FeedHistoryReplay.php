<?php

namespace App\Support\Feed;

use App\Models\FeedPost;
use App\Models\Loop;
use App\Models\LoopEvent;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;

/**
 * TASK-1752 (§8.4) — la reprise d'historique, en UN exemplaire.
 *
 * ## Pourquoi un service et pas deux implementations
 *
 * La reprise s'appelle depuis DEUX endroits : la commande Artisan
 * `flux:reprendre-historique` et le bouton de la page SuperAdmin (demande
 * Cyril — et le CDC l'avait prevu : « exposee aussi en bouton sur la page
 * SuperAdmin, par Organization — c'est la qu'on la cherchera »).
 *
 * Recopier la logique aurait garanti la divergence : le jour ou l'on corrige
 * une source — comme l'exclusion des `owner`, ou l'objet d'une adhesion qui
 * doit etre l'APPARTENANCE — on la corrigerait d'un cote seulement, et
 * l'ecran et la console ne diraient plus la meme chose.
 *
 * ## ADDITIVE, exclusivement
 *
 * Elle n'efface jamais. Un « recreer » destructif effacerait les commentaires
 * des membres par cascade FK et laisserait les reactions ORPHELINES —
 * `reactions` est un morph sans FK. `retirer()` existe et il est EXACT : il
 * ne vise que les types du registre, donc il ne peut pas emporter une annonce
 * humaine.
 *
 * ## Les scopes globaux sont retires NOMMEMENT
 *
 * Ce code tourne en console et sous `/admin`, sans Organization courante : le
 * fail-closed du scope ne trouverait rien. La frontiere de locataire est
 * reposee a la main par le `where('organization_id', …)`, qui est la vraie
 * garde.
 */
final class FeedHistoryReplay
{
    public const PRESENTATIONS_DATE_DU_COMPTE = 'date-du-compte';

    public const PRESENTATIONS_IGNORER = 'ignorer';

    /**
     * Ce qui SERAIT ecrit, sans rien ecrire.
     *
     * Pose la MEME question que l'ecriture — la ligne existe-t-elle deja ? —
     * sinon le compte annonce serait faux des la seconde passe.
     *
     * @return array<string, int>
     */
    public function compter(Organization $organization, string $presentations = self::PRESENTATIONS_DATE_DU_COMPTE): array
    {
        $comptes = [];

        foreach ($this->sources($organization, $presentations) as $libelle => $source) {
            $comptes[$libelle] = $this->traiter($organization, $source, true);
        }

        return $comptes;
    }

    /**
     * Ecrit ce qui manque. Rejouable sans danger.
     *
     * @return array<string, int>
     */
    public function reprendre(Organization $organization, string $presentations = self::PRESENTATIONS_DATE_DU_COMPTE): array
    {
        $comptes = [];

        foreach ($this->sources($organization, $presentations) as $libelle => $source) {
            $comptes[$libelle] = $this->traiter($organization, $source, false);
        }

        return $comptes;
    }

    /** Combien de lignes d'evenement existent aujourd'hui pour cette Organization. */
    public function dejaPosees(Organization $organization): int
    {
        return array_sum($this->poseesParType($organization));
    }

    /**
     * Ce qui est EN PLACE aujourd'hui, type par type.
     *
     * C'est ce que l'ecran montre pour choisir quoi retirer : on ne supprime
     * pas a l'aveugle, on voit d'abord ce qu'on vise.
     *
     * Tous les types du registre sont presents, meme a zero — une case
     * absente laisserait croire que le type n'existe pas.
     *
     * @return array<string, int>
     */
    public function poseesParType(Organization $organization): array
    {
        $comptes = FeedPost::withTrashed()
            ->where('organization_id', $organization->id)
            ->whereIn('type', FeedEventCatalogue::clefs())
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $tous = [];

        foreach (FeedEventCatalogue::clefs() as $clef) {
            $tous[$clef] = (int) ($comptes[$clef] ?? 0);
        }

        return $tous;
    }

    /**
     * Le retrait EXACT, eventuellement restreint a certains TYPES.
     *
     * ## Deux gardes, et aucune n'est decorative
     *
     * 1. **Seuls les types du REGISTRE sont vises.** Une annonce humaine ne
     *    peut pas etre emportee, quelle que soit la valeur envoyee par le
     *    formulaire : l'intersection avec `FeedEventCatalogue::clefs()` est
     *    faite ICI, pas dans la vue. Un `type` bricole a la main dans la
     *    requete ne peut donc rien atteindre d'autre.
     * 2. **Les reactions sont retirees a la main.** `reactions` est un morph
     *    SANS cle etrangere : un `forceDelete()` seul les laisserait
     *    orphelines, rattachees a un identifiant qui n'existe plus.
     *
     * Les commentaires, eux, partent par cascade FK — et c'est pour cela que
     * l'ecran doit le DIRE avant de demander confirmation.
     *
     * @param  list<string>|null  $types  `null` = tous les types d'evenement
     */
    public function retirer(Organization $organization, ?array $types = null): int
    {
        $vises = $types === null
            ? FeedEventCatalogue::clefs()
            : array_values(array_intersect($types, FeedEventCatalogue::clefs()));

        if ($vises === []) {
            return 0;
        }

        $lignes = FeedPost::withTrashed()
            ->where('organization_id', $organization->id)
            ->whereIn('type', $vises)
            ->get();

        foreach ($lignes as $ligne) {
            $ligne->reactions()->delete();
            $ligne->forceDelete();
        }

        return $lignes->count();
    }

    /**
     * Les cinq sources, chacune avec sa vraie date.
     *
     * @return array<string, \Illuminate\Support\Collection<int, array{type: string, objet: mixed, acteur: User|null, boucle: Loop|null, date: mixed}>>
     */
    private function sources(Organization $organization, string $presentations): array
    {
        $sources = [];

        $sources['loops_created'] = Loop::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->get()
            ->map(fn (Loop $loop) => [
                'type' => FeedEventCatalogue::BOUCLE_CREEE,
                'objet' => $loop,
                'acteur' => $loop->created_by ? User::find($loop->created_by) : null,
                'boucle' => $loop,
                'date' => $loop->created_at,
            ]);

        $sources['memberships'] = LoopMember::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('status', 'active')
            // `owner` exclu : sinon chaque creation produirait DEUX lignes.
            ->where('role', '!=', 'owner')
            ->get()
            ->map(function (LoopMember $m) {
                $boucle = Loop::withoutGlobalScopes()->find($m->loop_id);

                return [
                    'type' => FeedEventCatalogue::ADHESION_BOUCLE,
                    // L'APPARTENANCE, pas la Boucle : sinon une seule ligne par
                    // Boucle survivrait a la contrainte d'unicite.
                    'objet' => $m,
                    'acteur' => User::find($m->user_id),
                    'boucle' => $boucle,
                    'date' => $m->joined_at ?? $m->created_at,
                ];
            });

        $sources['services'] = Service::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->get()
            ->map(fn (Service $s) => [
                'type' => FeedEventCatalogue::PROPOSITION_PUBLIEE,
                'objet' => $s,
                'acteur' => User::find($s->user_id),
                'boucle' => null,
                'date' => $s->created_at,
            ]);

        $sources['requests'] = ServiceRequest::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->get()
            ->map(fn (ServiceRequest $d) => [
                'type' => FeedEventCatalogue::DEMANDE_PUBLIEE,
                'objet' => $d,
                'acteur' => User::find($d->user_id),
                'boucle' => null,
                'date' => $d->created_at,
            ]);

        $sources['events'] = LoopEvent::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->get()
            ->map(function (LoopEvent $e) {
                $boucle = Loop::withoutGlobalScopes()->find($e->loop_id);

                return [
                    'type' => FeedEventCatalogue::EVENEMENT_CREE,
                    'objet' => $e,
                    'acteur' => $e->created_by ? User::find($e->created_by) : null,
                    'boucle' => $boucle,
                    'date' => $e->created_at,
                ];
            });

        if ($presentations === self::PRESENTATIONS_DATE_DU_COMPTE) {
            $sources['presentations'] = User::where('organization_id', $organization->id)
                ->whereNotNull('bio')
                ->where('bio', '!=', '')
                ->get()
                ->map(fn (User $u) => [
                    'type' => FeedEventCatalogue::PRESENTATION_TERMINEE,
                    'objet' => $u,
                    'acteur' => $u,
                    'boucle' => null,
                    // APPROXIMATION ASSUMEE : « presentation terminee » est un
                    // ETAT, pas un objet date. Le depot n'a aucune trace datee,
                    // et `users.updated_at` ne vaut rien (dernier enregistrement
                    // de profil). `created_at` se trompe dans le BON sens —
                    // jamais dans le futur, jamais en pretendant que c'est
                    // recent.
                    'date' => $u->created_at,
                ]);
        }

        return $sources;
    }

    /** @param  iterable<array{type: string, objet: mixed, acteur: User|null, boucle: Loop|null, date: mixed}>  $lignes */
    private function traiter(Organization $organization, iterable $lignes, bool $aBlanc): int
    {
        $emetteur = app(FeedEventEmitter::class);
        $compte = 0;

        foreach ($lignes as $ligne) {
            if ($ligne['objet'] === null || $ligne['acteur'] === null) {
                continue;
            }

            $dejaLa = FeedPost::withTrashed()->where([
                'organization_id' => $organization->id,
                'type' => $ligne['type'],
                'object_type' => FeedEventCatalogue::classeAttendue($ligne['type']),
                'object_id' => (string) $ligne['objet']->getKey(),
            ])->exists();

            if ($dejaLa) {
                continue;
            }

            if ($aBlanc) {
                $compte++;

                continue;
            }

            if ($emetteur->emettre(
                $ligne['type'],
                $ligne['objet'],
                $ligne['acteur'],
                $organization,
                $ligne['boucle'],
                $ligne['date'],
            ) !== null) {
                $compte++;
            }
        }

        return $compte;
    }
}
