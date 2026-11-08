<?php

namespace App\Livewire;

use App\Models\EntraideComment;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationEmissionConflict;
use App\Support\Notifications\NotificationEmitter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Ramsey\Uuid\Uuid;

/**
 * TASK-1786 — « Commentaires et suggestions » sous une proposition de service ou
 * une demande d'aide.
 *
 * Un composant Livewire et non un POST plein cadre, pour la raison deja retenue
 * en T1697 : un rechargement complet ramene le lecteur en haut de la fiche, et
 * `wire:poll` fait voir a B le commentaire que A vient d'ecrire.
 *
 * ## UN composant pour les DEUX types d'annonce
 *
 * Le CDC demande « un seul mecanisme d'emission commun aux deux types ». Le type
 * voyage donc en propriete, et tout le reste est commun. Deux composants jumeaux
 * auraient diverge au premier correctif.
 *
 * ## Les deux proprietes sont VERROUILLEES, et c'est la garde principale
 *
 * `#[Locked]` sur `annonceId` **et** sur `annonceType`. Sans le verrou, le client
 * reecrit la propriete et commente l'annonce d'une AUTRE Organization depuis un
 * composant monte sur la sienne. Et verrouiller l'identifiant seul ne suffirait
 * pas : `commentable_type` entre dans l'identite de la cible, donc le laisser
 * libre permettrait de viser une `ServiceRequest` portant le meme UUID qu'un
 * `Service` — improbable, mais ce n'est pas a l'improbable qu'on confie une
 * frontiere de tenant.
 *
 * ## Les gardes sont refaites a CHAQUE geste
 *
 * Une requete Livewire est une requete HTTP a part entiere : elle ne rejoue NI
 * le `abort(404)` de `ServiceController::show()`, NI celui de
 * `RequestController::show()`. `annonce()` les reproduit donc, et il est appele
 * par chaque action — jamais une seule fois au montage.
 *
 * ## Lecture et ECRITURE n'ont pas la meme regle, et c'est demande
 *
 * Le CDC §4 exige de « definir explicitement si les commentaires restent
 * consultables en lecture seule » sur une annonce fermee. Reponse :
 *
 *  - la LECTURE suit l'annonce, sans rien ajouter. `ServiceController::show()`
 *    rend deja 404 a quiconque n'est pas le proprietaire d'un service non
 *    `active` ; `RequestController::show()` ne controle aucun statut, donc une
 *    demande clôturee reste visible, et ses commentaires avec elle ;
 *  - l'ECRITURE est refusee sur une annonce qui n'accepte plus rien. Suggerer
 *    une amelioration a une annonce clôturee n'a pas d'objet, et un commentaire
 *    n'engage personne — le CDC le rappelle.
 *
 * Les statuts admis viennent des contraintes CHECK de PostgreSQL, pas d'une
 * lecture des donnees : `services` ∈ {active, paused, deleted},
 * `service_requests` ∈ {open, in_progress, closed}. Une demande `in_progress`
 * reste commentable — un echange en cours est precisement un moment ou une
 * precision sert.
 *
 * ## L'ecriture precede l'emission, et une emission ratee ne defait rien
 *
 * Le commentaire est commis AVANT que la notification soit emise (CDC §5). Un
 * `NotificationEmissionConflict` signifie que l'auteur a DEJA ete prevenu de ce
 * commentaire : c'est un rejeu, pas une anomalie. Tout autre echec est
 * journalise et le commentaire reste. L'inverse — perdre le commentaire parce
 * qu'un courriel n'est pas parti — serait absurde.
 */
class EntraideComments extends Component
{
    #[Locked]
    public string $annonceId;

    #[Locked]
    public string $annonceType;

    public string $content = '';

    /**
     * Le message de confirmation du CDC §2, et l'identifiant du commentaire
     * qu'on vient d'ecrire pour « revenir sur le commentaire publie ».
     *
     * Une propriete et non un `session()->flash()` : avec `wire:poll`, le flash
     * serait consomme par le premier rafraichissement et le message
     * disparaitrait sans que personne l'ait lu.
     */
    public string $confirmation = '';

    public ?string $dernierPublie = null;

    /** Tentatives d'ecriture par minute et par membre. */
    public const ECRITURES_PAR_MINUTE = 10;

    public function mount(Service|ServiceRequest $annonce): void
    {
        $this->annonceId = (string) $annonce->getKey();
        $this->annonceType = $annonce->getMorphClass();
    }

    /**
     * L'annonce, re-resolue et re-gardee a chaque appel.
     *
     * `withoutGlobalScopes()` puis frontiere reposee A LA MAIN, plutot que de
     * s'appuyer sur `BelongsToOrganizationScope` : cette portee echoue FERME
     * quand `current_organization` n'est pas liee, et un 404 dont la cause est
     * un contexte manquant ne se distingue pas d'un 404 legitime. Une frontiere
     * qu'on ecrit se relit ; une frontiere implicite se devine.
     */
    private function annonce(): Service|ServiceRequest
    {
        $organization = currentOrganization();

        abort_if($organization === null, 404);
        abort_unless(in_array($this->annonceType, EntraideComment::TYPES_COMMENTABLES, true), 404);

        /** @var class-string<Service|ServiceRequest> $classe */
        $classe = $this->annonceType;

        // Portee d'Organization retiree NOMMEMENT, jamais en bloc :
        // `withoutGlobalScopes()` retire AUSSI `SoftDeletingScope`, et une
        // annonce supprimee redeviendrait commentable.
        $annonce = $classe::query()
            ->withoutGlobalScope(BelongsToOrganizationScope::class)
            ->whereKey($this->annonceId)
            ->first();

        abort_if($annonce === null, 404);
        abort_if((string) $annonce->organization_id !== (string) $organization->id, 404);

        // Les MEMES refus que les deux controleurs `show()`, reproduits parce
        // qu'une requete Livewire ne les traverse pas.
        abort_if($annonce->user?->banned_at !== null, 404);

        if ($annonce instanceof Service
            && $annonce->status !== 'active'
            && (string) auth()->id() !== (string) $annonce->user_id) {
            abort(404);
        }

        return $annonce;
    }

    /** L'annonce accepte-t-elle encore des commentaires ? */
    private function ouverteAuxCommentaires(Service|ServiceRequest $annonce): bool
    {
        return $annonce instanceof Service
            ? $annonce->status === 'active'
            : in_array($annonce->status, ['open', 'in_progress'], true);
    }

    public function addComment(): void
    {
        abort_unless(auth()->check(), 403);

        $annonce = $this->annonce();

        // Une annonce fermee se lit, ne s'ecrit plus. Le formulaire est deja
        // masque dans la vue ; ceci est la garde SERVEUR, la seule qui compte.
        abort_unless($this->ouverteAuxCommentaires($annonce), 403);

        $this->validate([
            'content' => 'required|string|max:'.EntraideComment::CONTENU_MAX,
        ]);

        // CDC §4, limitation de frequence. Bornee au MEMBRE et non a l'annonce :
        // c'est la personne qu'on limite, pas la page.
        $cle = 'entraide-comment:'.auth()->id();

        if (RateLimiter::tooManyAttempts($cle, self::ECRITURES_PAR_MINUTE)) {
            $this->addError('content', __('entraide.commentaires.trop_rapide', [
                'secondes' => RateLimiter::availableIn($cle),
            ]));

            return;
        }

        RateLimiter::hit($cle, 60);

        $commentaire = $this->creer($annonce, $this->content);

        $this->content = '';
        $this->confirmation = __('entraide.commentaires.publie');
        $this->dernierPublie = (string) $commentaire->id;

        $this->dispatch('entraide-comment-added', id: (string) $commentaire->id);
    }

    public function deleteComment(string $commentId): void
    {
        abort_unless(auth()->check(), 403);

        $annonce = $this->annonce();

        $commentaire = EntraideComment::query()
            ->whereKey($commentId)
            ->where('organization_id', $annonce->organization_id)
            ->where('commentable_type', $annonce->getMorphClass())
            ->where('commentable_id', $annonce->getKey())
            ->first();

        abort_if($commentaire === null, 404);

        $estAuteur = (string) auth()->id() === (string) $commentaire->user_id;
        $estAdmin = (bool) auth()->user()->is_admin;

        abort_unless($estAuteur || $estAdmin, 403);

        // La MODERATION se distingue du retrait par l'auteur. Sans cette trace,
        // `deleted_at` seul ne dirait pas lequel des deux gestes a eu lieu — et
        // c'est la question qu'on se pose apres coup.
        if (! $estAuteur && $estAdmin) {
            $commentaire->forceFill([
                'moderated_by' => auth()->id(),
                'moderated_at' => now(),
            ])->saveQuietly();
        }

        // Supprime le COMMENTAIRE, jamais l'annonce (CDC §4). `SoftDeletes` :
        // la ligne reste, elle cesse d'etre rendue.
        $commentaire->delete();

        // Une confirmation qui survivrait a la suppression mentirait.
        if ($this->dernierPublie === (string) $commentaire->id) {
            $this->confirmation = '';
            $this->dernierPublie = null;
        }

        $this->dispatch('entraide-comment-removed');
    }

    /**
     * Ecrit le commentaire, puis previent l'auteur.
     *
     * L'ordre est le contrat du CDC §5 : « le commentaire doit etre enregistre
     * avant l'emission de sa notification ». La transaction ne couvre QUE
     * l'ecriture — envelopper l'emission dedans ferait qu'un echec de courriel
     * annulerait un commentaire valide, et qu'une notification serait emise pour
     * une ecriture encore annulable.
     */
    private function creer(Service|ServiceRequest $annonce, string $contenu): EntraideComment
    {
        $commentaire = DB::transaction(fn () => EntraideComment::create([
            // L'Organization vient de l'ANNONCE, jamais du contexte de requete.
            'organization_id' => $annonce->organization_id,
            'commentable_type' => $annonce->getMorphClass(),
            'commentable_id' => $annonce->getKey(),
            'user_id' => auth()->id(),
            'content' => $contenu,
            'is_approved' => true,
        ]));

        $this->prevenirLAuteur($annonce, $commentaire);

        return $commentaire;
    }

    /**
     * Le destinataire est l'auteur de l'annonce, et LUI SEUL.
     *
     * Le CDC le dit en creux par une liste de qui ne doit PAS etre prevenu :
     * l'auteur de son propre commentaire, tous les membres de l'Organization,
     * les participants d'une Boucle. Il n'y a donc aucune diffusion ici — un
     * seul `emit()`, un seul destinataire.
     *
     * Trois raisons de ne RIEN emettre, et aucune n'est une erreur :
     *
     *  - on commente sa propre annonce : se notifier soi-meme est du bruit ;
     *  - l'auteur n'existe plus ;
     *  - l'auteur a quitte l'Organization. Les invariants refusent un
     *    destinataire hors tenant, et le docblock de `NotificationEmitter` exige
     *    que le PRODUCTEUR resolve l'appartenance AVANT d'appeler.
     */
    private function prevenirLAuteur(Service|ServiceRequest $annonce, EntraideComment $commentaire): void
    {
        if ((string) $annonce->user_id === (string) auth()->id()) {
            return;
        }

        $auteur = User::find($annonce->user_id);

        if ($auteur === null
            || (string) $auteur->organization_id !== (string) $annonce->organization_id) {
            return;
        }

        $acteur = auth()->user();

        try {
            app(NotificationEmitter::class)->emit(
                notificationKey: NotificationCatalogue::ENTRAIDE_COMMENT_CREATED,
                organization: (string) $annonce->organization_id,
                recipient: $auteur,
                eventId: $this->identifiantEvenement($commentaire),
                objectType: NotificationCatalogue::objectTypeFor(NotificationCatalogue::ENTRAIDE_COMMENT_CREATED),
                objectId: (string) $commentaire->id,
                actor: $acteur !== null && (string) $acteur->organization_id === (string) $annonce->organization_id ? $acteur : null,
            );
        } catch (NotificationEmissionConflict) {
            // L'auteur a deja ete prevenu de CE commentaire. Un rejeu n'est pas
            // une anomalie.
        } catch (\Throwable $echec) {
            Log::warning('TASK-1786 notification de commentaire d entraide non emise', [
                'entraide_comment_id' => $commentaire->id,
                'commentable_type' => $commentaire->commentable_type,
                'commentable_id' => $commentaire->commentable_id,
                'raison' => $echec->getMessage(),
            ]);
        }
    }

    /**
     * L'identite de l'EVENEMENT, derivee de la cle et du commentaire.
     *
     * `uuid5` DETERMINISTE, meme idiome que `BlogComments` : rejouer le meme
     * producteur sur le meme fait **constate** la premiere notification au lieu
     * d'en creer une seconde. Un `Str::uuid()` frais aurait duplique a chaque
     * rejeu — c'est exactement ce que le point 18 du CDC interdit.
     *
     * La cle entre dans le nom, donc un second type de notification sur le meme
     * commentaire aurait un identifiant DIFFERENT : le contrat de
     * `NotificationEmitter` l'exige explicitement.
     */
    private function identifiantEvenement(EntraideComment $commentaire): string
    {
        return (string) Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            'bouclepro:notification:'.NotificationCatalogue::ENTRAIDE_COMMENT_CREATED.':'.$commentaire->id,
        );
    }

    /** @return Collection<int, EntraideComment> */
    private function fil(Service|ServiceRequest $annonce): Collection
    {
        return EntraideComment::query()
            ->pourAnnonce($annonce)
            ->with('user')
            ->get();
    }

    public function render()
    {
        $annonce = $this->annonce();
        $commentaires = $this->fil($annonce);

        return view('livewire.entraide-comments', [
            'annonce' => $annonce,
            'estDemande' => $annonce instanceof ServiceRequest,
            'ouverte' => $this->ouverteAuxCommentaires($annonce),
            'commentaires' => $commentaires,
            'total' => $commentaires->count(),
        ]);
    }
}
