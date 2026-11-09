<?php

namespace App\Livewire\Admin;

use App\Models\Loop;
use App\Models\Organization;
use App\Models\RssSourceArticle;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Services\Blog\RssArticleImporter;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * TASK-1790 — la fiche individuelle : un article, une decision.
 *
 * ## Rien de ce que le navigateur annonce n'est cru (decision MASTER)
 *
 * `$rssSourceArticle` est VERROUILLE (`#[Locked]`), resolu par le binding de
 * route — meme garde que `annonceId` dans `EntraideComments`. `$organizationId`
 * et `$loopId` sont des choix LEGITIMEMENT mutables par l'utilisateur (des
 * selecteurs, pas une identite de ressource), mais `creerBrouillon()` les
 * RELIT en base avant toute ecriture, exactement comme
 * `AdminRssImportController::importer()` le fait deja pour l'ecran en lot —
 * aucune confiance dans la valeur qu'une requete Livewire tamperee pourrait
 * transporter.
 *
 * ## `publier` est TOUJOURS `false`, sans exception
 *
 * Le parcours individuel ne propose aucune case de publication : l'appel a
 * `RssArticleImporter::importer()` passe `false` en dur, jamais une valeur
 * lue depuis une propriete du composant. Decision MASTER explicite
 * (TASK-1790) : publier reste un geste de l'ecran en lot, assume et
 * confirme par deux cases, pas de ce parcours-ci.
 *
 * ## Le statut est relu a CHAQUE rendu, jamais mis en cache sur le composant
 *
 * `statutsParOrganization()` (lecture dediee, PAS `dejaImporte()` — restee
 * privee) est appelee dans `render()` : apres une creation, le nouveau
 * statut doit apparaitre immediatement, y compris pour l'Organization qui
 * vient de recevoir son brouillon.
 *
 * ## Deux gardes independantes contre une session dont le droit a change
 * (correctif post-merge TASK-1790)
 *
 * 1. `AdminMiddleware` est desormais declare a `Livewire::addPersistentMiddleware()`
 *    (`AppServiceProvider::boot()`), donc rejoue sur `POST /livewire/update` —
 *    sans cette declaration, Livewire ne rejouait QUE `Authenticate`.
 * 2. `creerBrouillon()` revalide `is_admin` EXPLICITEMENT, en plus de (1) :
 *    si la declaration ci-dessus venait un jour a disparaitre (regression,
 *    montee de version de Livewire qui changerait la liste blanche interne),
 *    cette seconde garde continuerait de refuser seule. Defense en profondeur,
 *    pas une redite : les deux dependent de mecanismes differents.
 */
#[Layout('layouts.admin')]
class RssArticleFiche extends Component
{
    #[Locked]
    public RssSourceArticle $rssSourceArticle;

    public ?string $organizationId = null;

    public ?string $loopId = null;

    /**
     * Une Organization devenue incompatible reinitialise la Boucle choisie —
     * meme regle qu'en TASK-1785 pour l'ecran en lot.
     */
    public function updatedOrganizationId(): void
    {
        $this->loopId = null;
    }

    public function creerBrouillon(RssArticleImporter $importer): void
    {
        // Garde explicite (2) — voir le docbloc de classe. Independante de
        // la garde (1) au niveau middleware.
        abort_unless(auth()->check() && auth()->user()->is_admin, 403);

        $organization = Organization::query()
            ->where('is_active', true)
            ->whereKey($this->organizationId)
            ->first();

        if ($organization === null) {
            $this->addError('organizationId', __('admin.import_rss.erreurs.organization_inconnue'));

            return;
        }

        $statuts = $importer->statutsParOrganization($this->rssSourceArticle->source_identity);

        if ($statuts->has((string) $organization->id)) {
            // Deja importe pour cette Organization — brouillon, publie OU
            // supprime, les trois bloquent. Refuse cote serveur meme si le
            // bouton aurait du etre masque : jamais de confiance dans l'etat
            // du navigateur.
            return;
        }

        $boucle = null;

        if ($this->loopId !== null) {
            $boucle = Loop::query()
                ->withoutGlobalScope(BelongsToOrganizationScope::class)
                ->whereKey($this->loopId)
                ->where('organization_id', $organization->id)
                ->first();
        }

        $resultat = $importer->importer(
            [$this->rssSourceArticle->versEntreeImport()],
            $organization,
            auth()->user(),
            $boucle,
            // FORCE a false, sans exception — voir le docbloc de classe.
            false,
        );

        if ($resultat['crees'] !== []) {
            $this->redirect(route('admin.blog.edit', ['post' => $resultat['crees'][0]['slug'], 'organization_id' => $this->organizationId]), navigate: false);

            return;
        }

        if ($resultat['doublons'] !== [] && ! $resultat['doublons'][0]['supprime']) {
            // Double clic / course concurrente : l'index unique a deja ete
            // honore par une autre requete. On rejoint l'article qui existe
            // desormais plutot que de laisser l'ecran dans un etat incoherent.
            $this->redirect(route('admin.blog.edit', ['post' => $resultat['doublons'][0]['slug'], 'organization_id' => $this->organizationId]), navigate: false);
        }
    }

    /**
     * @return Collection<int, Loop>
     */
    private function loopsDisponibles(): Collection
    {
        if ($this->organizationId === null) {
            return collect();
        }

        // Relue SANS la portee globale, bornee a la main : meme garde que
        // AdminRssImportController::boucles().
        return Loop::query()
            ->withoutGlobalScope(BelongsToOrganizationScope::class)
            ->where('organization_id', $this->organizationId)
            ->orderBy('name')
            ->get();
    }

    public function render(RssArticleImporter $importer): View
    {
        $statuts = $importer->statutsParOrganization($this->rssSourceArticle->source_identity);

        $organisationsConcernees = Organization::query()
            ->whereIn('id', $statuts->keys())
            ->get(['id', 'name'])
            ->keyBy(fn (Organization $o) => (string) $o->id);

        return view('livewire.admin.rss-article-fiche', [
            'organizations' => Organization::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'loops' => $this->loopsDisponibles(),
            'statuts' => $statuts,
            'organisationsConcernees' => $organisationsConcernees,
            // Correctif post-merge TASK-1790 : resume et contenu complet
            // affiches SEPAREMENT quand les deux existent — auparavant, un
            // seul apercu fusionnait les deux (meme logique que l'ECRITURE,
            // qui n'en garde qu'un). La lecture n'a pas cette contrainte :
            // rien n'empeche de montrer les deux. CDC §7 toujours respecte —
            // chacun passe par `contenuAssaini()`, jamais de HTML brut.
            'resumeApercu' => $this->apercu($this->rssSourceArticle->resume, $importer),
            'contenuApercu' => $this->apercu($this->rssSourceArticle->contenu_html, $importer),
            'peutCreer' => $this->organizationId !== null && ! $statuts->has($this->organizationId),
        ]);
    }

    /**
     * `null` quand le champ source est vide — pour que la vue puisse omettre
     * la section plutot que de rendre un bloc sanitise vide (`<p></p>`).
     */
    private function apercu(?string $html, RssArticleImporter $importer): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        return $importer->contenuAssaini($html, null);
    }
}
