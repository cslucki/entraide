<?php

namespace App\Policies;

use App\Models\Dossier;
use App\Models\DossierMember;
use App\Models\LoopMember;
use App\Models\User;
use App\Support\Loops\LoopPermissionResolver;

class DossierPolicy
{
    public function viewAny(User $user): bool
    {
        $organization = currentOrganization();

        return $organization !== null
            && $user->organization_id === $organization->id
            && ! $user->banned_at;
    }

    /**
     * Reading a Dossier, across the three modalities.
     *
     * Evaluated on every read, never cached and never duplicated into a column:
     * a Loop that becomes private takes its Dossier with it on the very next
     * request, with no synchronisation to go wrong.
     */
    public function view(User $user, Dossier $dossier): bool
    {
        // Le partage se demande au Dossier LUI-MEME, avant toute remontee :
        // ses points d'ancrage sont lui et ses ancetres, et ecraser
        // `$dossier` par sa racine ferait perdre cette information
        // (TASK-1136). `isOwner()` remonte de son cote, il n'en a pas besoin.
        if ($this->isOwner($user, $dossier)) {
            return true;
        }

        // Explicitly invited people, whatever the modality.
        if ($this->isMember($user, $dossier)) {
            return true;
        }

        // TASK-1130 : pour tout le reste — regime, Boucle, visibilite — un
        // enfant ne porte ni owner_id ni loop_id : sa gouvernance se demande
        // a la racine qui le porte. Sur une racine, governingDossier() rend
        // $dossier lui-meme : rien ne change pour l'existant.
        $dossier = $dossier->governingDossier();

        // Tenant first: nothing below may cross an Organization boundary.
        $organization = currentOrganization();

        if ($organization === null
            || $dossier->organization_id !== $organization->id
            || $user->organization_id !== $organization->id
            || $user->banned_at) {
            return false;
        }

        // A root Dossier has no audience of its own: it is its Loop's.
        if ($dossier->isLoopDossier()) {
            return $dossier->loop !== null && $user->can('viewWorkspace', $dossier->loop);
        }

        if ($dossier->visibility === Dossier::VISIBILITY_ORGANIZATION) {
            return true;
        }

        if ($dossier->visibility === Dossier::VISIBILITY_LOOP && $dossier->sharedWithLoop) {
            return $user->can('viewWorkspace', $dossier->sharedWithLoop);
        }

        return false;
    }

    /**
     * Choosing the audience — refused outright on a root Dossier.
     *
     * Its visibility is its Loop's, so offering the control at all would be a
     * lie. The refusal is on the server, not merely a hidden field.
     */
    public function updateVisibility(User $user, Dossier $dossier): bool
    {
        // Un enfant n'a pas d'audience propre — il herite de celle de sa
        // racine (TASK-1130) — donc rien a choisir ici pour lui non plus.
        if ($dossier->isLoopDossier() || ! $dossier->isRoot()) {
            return false;
        }

        // « Mes documents » est prive par definition : c'est l'espace ou l'on
        // range ce qu'on ne partage pas encore. On partage un dossier qui s'y
        // trouve, jamais l'espace lui-meme.
        if ($dossier->isPersonalDocumentsRoot()) {
            return false;
        }

        return $this->isOwner($user, $dossier);
    }

    /**
     * Renommer un Dossier — distinct de `update()`, qui gouverne l'ecriture
     * DANS le Dossier (fichiers, Articles, sous-dossiers).
     *
     * La distinction existe pour « Mes documents » : son contenu est
     * parfaitement normal — on y cree, on y depose, on y range — mais son
     * identite appartient au produit. Confondre les deux verrouillerait
     * l'espace entier au lieu de son seul nom.
     */
    public function rename(User $user, Dossier $dossier): bool
    {
        if ($dossier->isPersonalDocumentsRoot()) {
            return false;
        }

        return $this->update($user, $dossier);
    }

    /**
     * Creer un Dossier a la main : plus personne (TASK-1629).
     *
     * BouclePro n'est pas un Drive. Les routes qui appelaient cette capacite
     * — le formulaire, le POST de creation et la creation rapide de l'editeur
     * — ont ete supprimees ; ce `false` est la garde de fond : si une route de
     * creation etait reintroduite un jour sans repasser par cette decision,
     * elle serait refusee au lieu d'etre autorisee par oubli.
     *
     * Le provisioning des racines (`LoopRootDocumentService`,
     * `PersonalDocumentsRoot`) n'interroge aucune policy : il ecrit le modele
     * pour le compte du produit, pas d'un utilisateur. Il n'est donc pas
     * concerne, et les tests le prouvent.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Dossier $dossier): bool
    {
        // A root Dossier has no personal owner, so isOwner() would lock
        // everyone out — including the people who run the Loop. Its editors are
        // those who may edit the Loop's identity.
        //
        // Cette porte ne consulte deliberement AUCUNE Card. Le Dossier racine
        // est une infrastructure commune : la Card Dossiers en est une vue, et
        // d'autres viendront — Article, Support de cours, Travaux a rendre, le
        // document racine, les fichiers de la Boucle. Eteindre une interface ne
        // doit pas eteindre le systeme documentaire qu'elle regarde.
        //
        // TASK-1091 avait ajoute ici un test sur `core.dossiers`. C'etait trop
        // large : cela faisait dependre l'ecriture du Dossier de la presence
        // d'une seule de ses vues.
        // TASK-1130 : le regime de gouvernance se demande a la racine.
        // TASK-1140 : le partage editor, lui, se demande sur le Dossier vise :
        // `isEditor()` applique les ancres TASK-1136 (self + ancetres
        // partageables). Ecraser la cible par sa racine avant cet appel
        // perdait le partage explicite d'un sous-dossier personnel.
        $governingDossier = $dossier->governingDossier();

        if ($governingDossier->isLoopDossier()) {
            return $governingDossier->loop !== null && $user->can('update', $governingDossier->loop);
        }

        if ($this->isOwner($user, $dossier)) {
            return true;
        }

        return $this->isEditor($user, $dossier);
    }

    public function delete(User $user, Dossier $dossier): bool
    {
        // TASK-1130 passe 4 (CAS A) : le Dossier racine d'une Boucle n'est
        // jamais supprimable d'ici — le detruire emporterait tout l'espace
        // documentaire de la Boucle, une decision de cycle de vie de la
        // Boucle elle-meme, hors de portee d'un geste Dossier. Un vrai
        // sous-dossier (parent_id non nul) EST reellement possede par sa
        // Boucle : memes droits que d'y ecrire (update()), pas un refus
        // systematique comme isOwner() seul l'aurait rendu (owner_id est
        // toujours NULL sous gouvernance Boucle).
        if ($dossier->isLoopDossier() && $dossier->isRoot()) {
            return false;
        }

        // Meme raison pour « Mes documents » : supprimer l'espace personnel
        // n'est pas un geste de rangement. Il n'a d'ailleurs pas de
        // remplacant — la primitive le recreerait a la visite suivante, vide,
        // en laissant l'ancien contenu sans destination.
        if ($dossier->isPersonalDocumentsRoot()) {
            return false;
        }

        $governing = $dossier->governingDossier();

        if ($governing->isLoopDossier()) {
            return $governing->loop !== null && $user->can('update', $governing->loop);
        }

        // Dossier personnel, racine ou enfant : seul le proprietaire reel.
        return $this->isOwner($user, $dossier);
    }

    /**
     * TASK-1695 — DESACTIVE sur decision de Cyril (24/10).
     *
     * ## Pourquoi
     *
     * La creation de Dossiers avait ete retiree il y a plus d'un mois : Dossiers
     * n'est pas un Drive. Mais retirer la CREATION n'avait pas emporte le
     * PARTAGE de ceux qui existaient deja — l'ecran restait atteignable par les
     * liens `?partage=1`, et sa recherche de membres rendait l'adresse email de
     * chaque personne proposee. Meme defaut que celui corrige sur le partage de
     * fichier, sur un ecran dont plus personne ne voulait.
     *
     * ## Ce que la mesure disait
     *
     * 11 Dossiers en base, dont 5 « Mes documents » (deja refuses ici) et 5
     * lies a une Boucle. **1 seul** etait reellement partageable, et **0** ligne
     * `dossier_members` existait : la fonction n'avait jamais servi.
     *
     * ## Pourquoi ICI et pas ailleurs
     *
     * C'est l'AUTORITE UNIQUE : les quatre gestes de `DossierMemberController`
     * la consultent, et les deux poignees de `show.blade.php` passent par
     * `@can('manageMembers', ...)`. Fermer ici ferme la porte ET retire la
     * poignee, sans qu'aucun des deux ne puisse deriver de l'autre. Les routes
     * sont commentees en plus (`routes/web.php`), de sorte qu'une URL tapee a
     * la main rende 404 plutot que 403 : une porte absente n'annonce rien.
     *
     * ## Les Boucles ne sont PAS concernees
     *
     * `isOwner()` exige `owner_id`, qu'un Dossier de Boucle n'a pas : il n'est
     * jamais passe par ici. Le partage avec une Boucle (`shared_with_loop_id`)
     * emprunte un tout autre chemin, et reste intact — c'est d'ailleurs la
     * direction que Cyril veut developper.
     *
     * ## Pour reactiver
     *
     * Retirer le `return false` ci-dessous, decommenter le corps d'origine, et
     * decommenter les cinq routes `dossiers.members.*`. Le controleur, lui, n'a
     * pas ete touche : il est intact, simplement inatteignable.
     */
    public function manageMembers(User $user, Dossier $dossier): bool
    {
        return false;

        // --- Corps d'origine, conserve tel quel pour une reactivation ---
        //
        // // Inviter quelqu'un dans « Mes documents » reviendrait a partager
        // // tout l'espace personnel d'un coup, y compris ce qui n'y est pas
        // // encore.
        // if ($dossier->isPersonalDocumentsRoot()) {
        //     return false;
        // }
        //
        // return $this->isOwner($user, $dossier);
    }

    public function attachArticle(User $user, Dossier $dossier): bool
    {
        // Un Dossier racine n'a ni owner ni lignes dossier_members : ses
        // editeurs sont ceux de la Boucle, comme pour update(). Sans cette
        // branche, personne — pas meme le proprietaire de la Boucle — ne
        // pouvait y attacher un article. Meme remontee pour un enfant.
        $governing = $dossier->governingDossier();

        if ($governing->isLoopDossier()) {
            // TASK-1313 : sous gouvernance Boucle, ATTACHER UN ARTICLE demande
            // `dossiers.create_article` au resolveur canonique — la permission
            // qui porte deja ce geste dans la matrice — et non plus `update()`.
            //
            // `update()` delegue a `LoopPolicy::update()`, qui exige strictement
            // le role `owner`. Or cette ability est le goulot de bien d'autres
            // ecritures : identite de la Boucle, invitations par e-mail,
            // document racine. L'elargir pour laisser passer un animateur lui
            // aurait donne tout le reste avec — l'inverse de la doctrine, ou
            // l'animateur anime SA Boucle sans devenir Admin Organization.
            //
            // Demander la permission PRECISE ne change rien pour l'owner ni pour
            // l'admin d'Organization (le resolveur les laisse passer plus haut),
            // ouvre a l'animateur, et refuse toujours le membre ordinaire.
            $loop = $governing->loop;

            return $loop !== null
                && app(LoopPermissionResolver::class)->can($user, $loop, 'dossiers.create_article');
        }

        if ($this->isOwner($user, $dossier)) {
            return true;
        }

        return $this->isEditor($user, $dossier);
    }

    public function detachArticle(User $user, Dossier $dossier): bool
    {
        return $this->attachArticle($user, $dossier);
    }

    public function reorderArticles(User $user, Dossier $dossier): bool
    {
        return $this->update($user, $dossier);
    }

    public function manageSeries(User $user, Dossier $dossier): bool
    {
        return $this->update($user, $dossier);
    }

    public function viewSeries(User $user, Dossier $dossier): bool
    {
        return $this->view($user, $dossier);
    }

    public function manageFiles(User $user, Dossier $dossier): bool
    {
        return $this->update($user, $dossier);
    }

    /**
     * TASK-1684 — DEPOSER un fichier, geste distinct de GERER les fichiers.
     *
     * `manageFiles` gouverne quatre ecritures : renommer, deplacer, editer le
     * markdown, et la projection « puis-je administrer ce dossier » des ecrans.
     * L'elargir pour ouvrir le simple depot aurait donne tout cela a chaque
     * membre — et contredit la doctrine T1130 (etre membre ne suffit pas).
     * Cette ability n'est donc lue QUE par `StoreDossierFileRequest` : elle
     * ouvre le depot, et rien d'autre.
     *
     * Portee volontairement etroite : le Dossier RACINE d'une Boucle, celui
     * qui porte `loop_id`. Les sous-dossiers gardent la regle proprietaire —
     * la demande etait « ajouter un fichier dans la Boucle », pas administrer
     * son arborescence.
     *
     * Qui administre deja le Dossier passe par `manageFiles` et n'est pas
     * affecte ; ce qui s'ajoute, c'est le membre ACTIF d'une Boucle VIVANTE.
     */
    public function uploadFiles(User $user, Dossier $dossier): bool
    {
        if ($this->manageFiles($user, $dossier)) {
            return true;
        }

        // `isLoopDossier()` se lit sur CETTE ligne, pas sur sa racine : un
        // sous-dossier ne doit pas heriter de l'ouverture.
        if (! $dossier->isLoopDossier()) {
            return false;
        }

        $loop = $dossier->loop;

        if ($loop === null || $user->isDeactivated()) {
            return false;
        }

        if ($user->organization_id !== $loop->organization_id) {
            return false;
        }

        // Une Boucle archivee est en lecture seule — meme borne que
        // `LoopPolicy::update`, qui refuse pour la meme raison.
        if ($loop->isArchived()) {
            return false;
        }

        $organization = $loop->organization;

        if (! $organization || ! $organization->loops_enabled) {
            return false;
        }

        $estMembreActif = LoopMember::query()
            ->where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        if (! $estMembreActif) {
            return false;
        }

        // La capacite `dossiers.upload_file` existe DEJA dans le modele de
        // permissions de la Boucle (`config/loop_permissions.php`) : elle est
        // configurable par Boucle (`locked => false`) et porte `requires_card
        // => core.dossiers`, qui ferme le depot quand la Card est eteinte.
        //
        // Premiere ecriture de cette ability : j'avais rendu `true` ici sur le
        // seul fait d'etre membre actif, ce qui CONTOURNAIT ce modele — une
        // Boucle ayant explicitement referme le depot l'aurait vu rouvert dans
        // son dos. L'ouverture demandee par le MASTER est posee la ou elle se
        // configure : le role `member` recoit la capacite par defaut, et cette
        // porte se contente de la lire.
        return app(LoopPermissionResolver::class)->can($user, $loop, 'dossiers.upload_file');
    }

    public function viewFiles(User $user, Dossier $dossier): bool
    {
        return $this->view($user, $dossier);
    }

    public function deleteFile(User $user, Dossier $dossier): bool
    {
        // Meme delegue que l'ecriture : qui administre la Boucle administre
        // les fichiers de son Dossier racine. isOwner() seul aurait interdit
        // toute suppression, owner_id etant null par doctrine.
        if ($dossier->governingDossier()->isLoopDossier()) {
            return $this->update($user, $dossier);
        }

        return $this->isOwner($user, $dossier);
    }

    /**
     * Ces quatre verifications delegue toutes a `governingDossier()` — pas
     * seulement pour un root d'article ou de fichier, mais pour n'importe
     * quel Dossier de la chaine (TASK-1130). Un enfant n'a ni owner_id ni
     * dossier_members a lui : les lire directement dessus repondrait
     * toujours non, meme au proprietaire reel.
     */
    public function isOwner(User $user, Dossier $dossier): bool
    {
        $dossier = $dossier->governingDossier();
        $organization = currentOrganization();

        return $organization !== null
            && $dossier->organization_id === $organization->id
            && $user->organization_id === $organization->id
            && $dossier->owner_id === $user->id;
    }

    /**
     * Y a-t-il un partage qui concerne CE Dossier ?
     *
     * On interroge la chaine des points d'ancrage — le Dossier lui-meme, puis
     * ses ancetres — et non le governing root. La difference n'est pas
     * theorique : lire les membres sur la racine faisait qu'un partage pose
     * sur un sous-dossier de « Mes documents » ouvrait l'espace personnel
     * entier (TASK-1136). La racine personnelle est exclue des ancres.
     */
    private function partageActif(User $user, Dossier $dossier, ?string $role = null): bool
    {
        $organization = currentOrganization();

        if ($organization === null || $dossier->organization_id !== $organization->id || $user->organization_id !== $organization->id) {
            return false;
        }

        $ancres = $dossier->sharingAnchorIds();

        if ($ancres === []) {
            return false;
        }

        return DossierMember::query()
            ->whereIn('dossier_id', $ancres)
            ->where('user_id', $user->id)
            ->when($role !== null, fn ($q) => $q->where('role', $role))
            ->exists();
    }

    public function isMember(User $user, Dossier $dossier): bool
    {
        return $this->partageActif($user, $dossier);
    }

    public function isEditor(User $user, Dossier $dossier): bool
    {
        return $this->partageActif($user, $dossier, DossierMember::ROLE_EDITOR);
    }

    public function isReader(User $user, Dossier $dossier): bool
    {
        return $this->partageActif($user, $dossier, DossierMember::ROLE_READER);
    }
}
