<?php

namespace App\Services\Loops;

use App\Models\Loop;
use App\Models\LoopMarketplaceLink;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Les Offres et Demandes mises en avant dans une Boucle de Reseautage.
 *
 * Cinq regles, chacune venue d'un defaut paye ailleurs dans cette serie :
 *
 * 1. **Aucun second systeme, aucune copie.** Le catalogue existe ; on y
 *    rattache, on n'y duplique pas. Le titre est lu sur l'Offre a chaque
 *    affichage.
 * 2. **Le cout ne croit pas** avec le nombre de liens : la lecture est bornee
 *    et les relations chargees en une fois.
 * 3. **Une permission ne suffit pas — la transition doit etre valide** : on ne
 *    met en avant que ce qui existe, appartient a la meme Organization, et
 *    n'est pas deja la.
 * 4. **L'ecriture est une ecriture** : elle a sa propre permission, et une
 *    Boucle archivee la refuse.
 * 5. **Retirer un lien ne touche jamais l'Offre.** Cesser de mettre en avant
 *    n'est pas supprimer.
 */
class LoopMarketplaceService
{
    /** Ce qu'une Card affiche sans devenir un annuaire. */
    private const PAGE = 30;

    /**
     * Mettre une Offre en avant dans cette Boucle.
     *
     * **Cela ne change rien a sa visibilite.** L'Offre etait deja lisible dans
     * l'Annuaire de l'Organization et l'y reste : la Boucle la rend trouvable
     * ici, elle ne la rend ni privee ni publique. Un lien qui laisserait croire
     * l'inverse serait une promesse de confidentialite que le produit ne tient
     * pas.
     */
    public function highlightOffer(Loop $loop, User $actor, Service $service, ?string $note = null): LoopMarketplaceLink
    {
        // Une Offre d'une autre Organization n'a rien a faire ici, meme si
        // l'identifiant est reel. Le cloisonnement ne se deduit pas de la
        // requete : il se lit sur les deux objets et se compare.
        if ($service->organization_id !== $loop->organization_id) {
            throw ValidationException::withMessages([
                'service_id' => __('loops.cards.marketplace.not_in_organization'),
            ]);
        }

        return $this->link($loop, $actor, ['service_id' => $service->id], $note);
    }

    /** Mettre une Demande en avant. Memes regles, autre colonne. */
    public function highlightRequest(Loop $loop, User $actor, ServiceRequest $request, ?string $note = null): LoopMarketplaceLink
    {
        if ($request->organization_id !== $loop->organization_id) {
            throw ValidationException::withMessages([
                'service_request_id' => __('loops.cards.marketplace.not_in_organization'),
            ]);
        }

        return $this->link($loop, $actor, ['service_request_id' => $request->id], $note);
    }

    /**
     * Le lien lui-meme.
     *
     * `service_id` **ou** `service_request_id` : la signature des deux methodes
     * publiques l'impose, et rien d'autre n'appelle celle-ci.
     */
    private function link(Loop $loop, User $actor, array $cible, ?string $note): LoopMarketplaceLink
    {
        $note = $this->cleanNote($note);

        return DB::transaction(function () use ($loop, $actor, $cible, $note) {
            $deja = LoopMarketplaceLink::where('loop_id', $loop->id)
                ->where(array_key_first($cible), reset($cible))
                ->first();

            // Deux mises en avant de la meme Offre la feraient lire deux fois.
            // L'unicite est tenue par la base — cette lecture n'est qu'un
            // raccourci qui evite une exception previsible.
            //
            // **Le nouveau mot s'applique quand meme** : l'ecran annonçait
            // « mis en avant » et n'ecrivait rien, ce qui donnait un succes
            // pour un geste sans effet.
            if ($deja) {
                if ($note !== null) {
                    $deja->update(['note' => $note]);
                }

                return $deja->fresh();
            }

            return LoopMarketplaceLink::create(array_merge($cible, [
                'organization_id' => $loop->organization_id,
                'loop_id' => $loop->id,
                'added_by' => $actor->id,
                'note' => $note,
            ]));
        });
    }

    /** Corriger le mot qui accompagne une mise en avant. */
    public function updateNote(LoopMarketplaceLink $link, ?string $note): LoopMarketplaceLink
    {
        $link->update(['note' => $this->cleanNote($note)]);

        return $link->fresh();
    }

    /**
     * Cesser de mettre en avant.
     *
     * **L'Offre n'est jamais touchee.** Elle reste au catalogue, avec ses
     * transactions et son historique : retirer d'une Boucle, c'est cesser de
     * la montrer la, pas la supprimer du produit.
     */
    public function remove(LoopMarketplaceLink $link): void
    {
        $link->delete();
    }

    /**
     * Ce que la Boucle met en avant, du plus recent au plus ancien.
     *
     * Les relations sont chargees **avec** : sans cela, chaque lien payait une
     * requete pour son Offre, une pour son auteur et une pour qui l'a mise en
     * avant — le cout suivait alors le nombre de liens.
     *
     * @return Collection<int, LoopMarketplaceLink>
     */
    public function linksFor(Loop $loop, int $limit = self::PAGE): Collection
    {
        $colonnes = 'id,user_id,title,description,status,organization_id';
        $auteur = 'id,first_name,name,email,organization_id,banned_at';

        // **`withoutGlobalScopes()` sur les deux relations.** `Service` et
        // `ServiceRequest` portent `BelongsToOrganizationScope`, qui filtre sur
        // l'Organization **de la requete** et non sur celle de la Boucle. Un
        // super-admin d'une autre Organization — que le resolveur autorise
        // explicitement — voyait donc des lignes vides, annoncees a tort comme
        // retirees du catalogue.
        //
        // Le cloisonnement ne s'en trouve pas affaibli : il est porte par
        // `loop_id`, et la Boucle est deja resolue dans son tenant. C'est ce
        // que fait deja l'Explorer (`app/Livewire/Explorer.php`).
        // **`withoutGlobalScope(BelongsToOrganizationScope::class)` et non
        // `withoutGlobalScopes()`** : celui-ci retire *tous* les scopes, dont
        // `SoftDeletingScope`, et faisait donc reapparaitre les Offres mises a
        // la corbeille — precisement ce que la ligne suivante veut exclure.
        $sansScope = fn ($relation) => $relation->withoutGlobalScope(BelongsToOrganizationScope::class);
        $cible = fn ($relation) => $relation
            ->withoutGlobalScope(BelongsToOrganizationScope::class)
            ->select(explode(',', $colonnes));

        return LoopMarketplaceLink::where('loop_id', $loop->id)
            // Une Offre supprimee du catalogue n'a plus rien a mettre en avant.
            // `ServiceController::destroy()` fait un **soft delete** : la ligne
            // reste, le `cascadeOnDelete` ne se declenche jamais, et la Card
            // affichait une boite sans titre que seul son auteur pouvait
            // retirer. `whereHas` exclut les corbeilles.
            ->where(fn ($q) => $q->whereHas('service', $sansScope)->orWhereHas('serviceRequest', $sansScope))
            ->with([
                "addedBy:{$auteur}",
                'service' => $cible,
                "service.user:{$auteur}",
                'serviceRequest' => $cible,
                "serviceRequest.user:{$auteur}",
            ])
            ->orderByDesc('created_at')
            // **Le departage est indispensable.** Sous PostgreSQL les colonnes
            // `timestamps()` sont a la seconde : trente-cinq mises en avant
            // faites dans la meme seconde s'ordonnaient au hasard, et les
            // trente affichees etaient les **plus anciennes**. L'identifiant
            // est un UUIDv7, donc ordonne dans le temps.
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Combien la Boucle met en avant, au total.
     *
     * La Card en montre trente : taire les autres laisserait croire qu'il n'y
     * a que ça.
     */
    public function countFor(Loop $loop): int
    {
        $sansScope = fn ($relation) => $relation->withoutGlobalScope(BelongsToOrganizationScope::class);

        return LoopMarketplaceLink::where('loop_id', $loop->id)
            ->where(fn ($q) => $q->whereHas('service', $sansScope)->orWhereHas('serviceRequest', $sansScope))
            ->count();
    }

    /**
     * Les Offres de cette personne qu'elle peut encore mettre en avant.
     *
     * **Les siennes seulement.** Mettre en avant l'Offre de quelqu'un d'autre
     * l'engagerait a repondre a une Boucle qu'il n'a pas choisie ; la Card
     * servirait alors a inscrire autrui.
     *
     * @return Collection<int, Service>
     */
    public function offerableBy(Loop $loop, User $actor): Collection
    {
        $deja = LoopMarketplaceLink::where('loop_id', $loop->id)
            ->whereNotNull('service_id')
            ->pluck('service_id');

        return Service::where('organization_id', $loop->organization_id)
            ->where('user_id', $actor->id)
            ->where('status', 'active')
            ->whereNotIn('id', $deja)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();
    }

    /**
     * Les Demandes de cette personne qu'elle peut encore mettre en avant.
     *
     * @return Collection<int, ServiceRequest>
     */
    public function requestableBy(Loop $loop, User $actor): Collection
    {
        $deja = LoopMarketplaceLink::where('loop_id', $loop->id)
            ->whereNotNull('service_request_id')
            ->pluck('service_request_id');

        return ServiceRequest::where('organization_id', $loop->organization_id)
            ->where('user_id', $actor->id)
            ->where('status', 'open')
            ->whereNotIn('id', $deja)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();
    }

    private function cleanNote(?string $note): ?string
    {
        $note = trim((string) $note);

        if ($note === '') {
            return null;
        }

        // La colonne est un `text`, donc rien ne casse — mais chaque rendu de
        // la Card relit et renvoie ce qu'on y met.
        return mb_substr($note, 0, 2000);
    }
}
