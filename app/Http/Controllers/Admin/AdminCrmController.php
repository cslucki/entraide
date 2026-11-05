<?php

namespace App\Http\Controllers\Admin;

use App\Models\CrmContact;
use App\Models\Organization;
use App\Models\User;
use App\Services\Crm\CrmTimelineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use LogicException;

/**
 * TASK-1431 — CRM CORE FIX B (decision Cyril 07/09) : le SuperAdmin administre
 * les Relations de TOUTES les Organizations depuis /admin/relations/{organization}/…
 *
 * Controleur MINCE (MASTER Q52) : il HERITE des actions de OrgCrmController
 * (validation, delegation aux services metier, redirections) et ne change que
 * le CONTEXTE — URLs /admin, layout plateforme, liens — plus la fiche d'un
 * Contact supprime pour le restaurer. L'Organization est dans chaque URL ; le
 * Contact est toujours resolu DANS cette Organization (404 sinon). Aucune
 * regle metier ici. Suppression = SoftDelete plateforme (MASTER Q50).
 */
class AdminCrmController extends OrgCrmController
{
    protected function crmUrl(Organization $organization, string $name, array $params = []): string
    {
        // « La liste » de cette Organization, vue de la plateforme : le panneau global filtre.
        if ($name === 'contacts') {
            return route('admin.crm.overview', ['organization' => $organization->id] + $params);
        }

        return route('admin.crm.'.$name, ['organization' => $organization->slug] + $params);
    }

    protected function crmView(string $name, array $data): View
    {
        return view('admin.crm.'.$name, $data);
    }

    protected function crmLinks(Organization $organization, CrmContact $contact): array
    {
        return [
            'index' => $this->crmUrl($organization, 'contacts'),
            'linked_user' => route('admin.users', ['search' => $contact->user?->email]),
            'templates_create' => route('organization.admin.crm.templates.create', ['organization' => $organization->slug]),
            'delete' => $this->crmUrl($organization, 'contacts.destroy', ['contact' => $contact->id]),
            'restore' => $this->crmUrl($organization, 'contacts.restore', ['contact' => $contact->id]),
        ];
    }

    /** La plateforme voit aussi un Contact supprime — pour le restaurer, rien d'autre. */
    protected function resolveContactForShow(Organization $organization, string $id): CrmContact
    {
        return CrmContact::withTrashed()->forOrganization($organization)->whereKey($id)->firstOrFail();
    }

    public function destroy(Request $request, Organization $organization, string $contact): RedirectResponse
    {
        $contact = $this->resolveContact($organization, $contact);

        try {
            $this->contacts->delete($contact, $request->user());
        } catch (LogicException) {
            abort(403);
        }

        return redirect()->to($this->crmUrl($organization, 'contacts.show', ['contact' => $contact->id]))
            ->with('success', __('crm.trashed.flash_deleted'));
    }

    public function restore(Request $request, Organization $organization, string $contact): RedirectResponse
    {
        // Seul un Contact SUPPRIME de CETTE Organization se restaure ; sinon 404.
        $contact = CrmContact::onlyTrashed()->forOrganization($organization)->whereKey($contact)->firstOrFail();

        try {
            $this->contacts->restore($contact, $request->user());
        } catch (LogicException) {
            abort(403);
        }

        return redirect()->to($this->crmUrl($organization, 'contacts.show', ['contact' => $contact->id]))
            ->with('success', __('crm.trashed.flash_restored'));
    }

    // ── TASK-1753 — les deux gestes de Relations depuis /admin/users ─────────────

    /**
     * « Ajouter au suivi », mais on RESTE sur /admin/users.
     *
     * ## Pourquoi cette surcharge existe
     *
     * La methode heritee est juste sur le fond et FAUSSE sur la destination :
     * `OrgCrmController::followMember()` est la SEULE de sa classe a ne pas
     * passer par `crmUrl()` — elle redirige EN DUR vers
     * `organization.admin.crm.contacts`. Cablee telle quelle, elle projetterait
     * un SuperAdmin parti de `/admin/users` dans la console OrgAdmin d'un
     * tenant, ou `OrgAdminMiddleware` deciderait de son sort.
     *
     * C'est l'option (b) du §3.2 du CDC : ne toucher QUE le contexte plateforme,
     * et laisser la surface OrgAdmin strictement inchangee. L'option (a) —
     * assainir la methode parente — aurait fait passer la redirection org par
     * `crmUrl('contacts')`, dont la version plateforme renvoie
     * `admin.crm.overview` avec un **id** et non un slug : deux comportements
     * a re-tester pour un seul besoin.
     *
     * Le peu de logique reprise du parent (le 404 de locataire, le calcul de
     * « nouvellement suivi ») est pose ici parce que cette methode a besoin du
     * `CrmContact` lui-meme — le parent ne le rend pas — pour offrir le lien
     * « voir la fiche » exige par la decision n°3 de Cyril.
     */
    public function followMember(Request $request, Organization $organization, User $user): RedirectResponse
    {
        // Un membre d'ailleurs n'existe pas ici : 404, jamais 403.
        abort_unless($user->organization_id === $organization->id, 404);

        $etaitSupprime = $this->ficheEtaitSupprimee($organization, $user);

        try {
            $contact = $this->contacts->followMember($organization, $user, $request->user());
        } catch (LogicException $e) {
            return back()->with('error', $this->classerConflitDeSuivi($e, $organization));
        }

        // Nouveau Contact, ou Contact existant que ce clic vient de RELIER :
        // dans les deux cas le membre est maintenant suivi.
        $nouveau = $contact->wasRecentlyCreated || $contact->wasChanged('user_id');

        $message = match (true) {
            $etaitSupprime => __('admin.crm_followed_and_restored'),
            $nouveau => __('crm.flash.member_followed'),
            default => __('crm.flash.member_already_followed'),
        };

        return back()
            ->with('success', $message)
            ->with('flash_action', $this->lienVersLaFiche($organization, $contact));
    }

    /**
     * « Saisir une note » sur un MEMBRE — decision N1 (§11 du CDC) : UN SEUL GESTE.
     *
     * Une note a besoin d'un `CrmContact` ; `/admin/users` n'a qu'un `User`. Plutot
     * qu'un bouton mort sur la quasi-totalite des lignes (la base de recette porte
     * UN contact pour 51 comptes), la note ajoute la personne au suivi si elle ne
     * l'est pas deja. L'ecriture implicite est annoncee DANS le pop-up, et le flash
     * dit laquelle des trois issues s'est produite.
     *
     * ## La validation est convertie en flash, et ce n'est pas un detail
     *
     * `layouts/admin.blade.php` ne rend NI `$errors` NI `@error` — seulement les
     * toasts de session. Un `$request->validate()` qui echoue renverrait donc sur
     * `/admin/users` avec un sac d'erreurs que personne n'affiche : ecran
     * d'apparence normale, note jamais ecrite, rien pour le dire. C'est le pire
     * des retours possibles. La conversion en `session('error')` est la seule
     * garde qui tienne SANS JavaScript — `required` sur le textarea ne couvre que
     * le navigateur.
     */
    public function storeMemberNote(Request $request, Organization $organization, User $user): RedirectResponse
    {
        abort_unless($user->organization_id === $organization->id, 404);

        try {
            $data = $request->validate([
                'body' => ['required', 'string', 'max:'.CrmTimelineService::MAX_NOTE_LENGTH],
                'channel' => ['nullable', 'string', Rule::in(CrmTimelineService::CHANNELS)],
            ]);
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        $etaitSupprime = $this->ficheEtaitSupprimee($organization, $user);

        try {
            $contact = $this->contacts->followMember($organization, $user, $request->user());
        } catch (LogicException $e) {
            return back()->with('error', $this->classerConflitDeSuivi($e, $organization));
        }

        $suiviAjoute = $contact->wasRecentlyCreated || $contact->wasChanged('user_id');

        // `addNote` re-verifie l'auteur (`guardAuthor`) et la longueur : un
        // SuperAdmin passe par `is_admin`, acquis de TASK-1431.
        $this->timeline->addNote($contact, $data['body'], $request->user(), $data['channel'] ?? null);

        $message = match (true) {
            $etaitSupprime => __('admin.crm_note_added_and_restored'),
            $suiviAjoute => __('admin.crm_note_added_and_followed'),
            default => __('crm.flash.note_added'),
        };

        return back()
            ->with('success', $message)
            ->with('flash_action', $this->lienVersLaFiche($organization, $contact));
    }

    /**
     * Cette personne avait-elle une fiche SUPPRIMEE, juste avant ce geste ?
     *
     * ## Pourquoi la question se pose AVANT et non apres
     *
     * `findOrCreate()` restaure en silence une fiche supprimee qui correspond
     * (`match()` scanne `withTrashed()`) — comportement voulu depuis T1413 :
     * mieux vaut restaurer que doublonner. Mais depuis `/admin/users`, personne
     * ne voit qu'une corbeille vient d'etre videe, et c'est le risque n°5 du CDC.
     *
     * La constater APRES coup est impossible : `restore()` marque bien
     * `deleted_at` comme modifie, puis `findOrCreate()` et `linkToUser()`
     * enchainent deux `save()` qui remplacent le jeu de changements. Au retour,
     * `wasChanged('deleted_at')` est deja faux.
     *
     * ## La limite, assumee et nommee
     *
     * On interroge l'EMAIL, pas le telephone. `followMember()` passe toujours
     * l'email du membre et `match()` l'essaie EN PREMIER : c'est donc le chemin
     * reel. Le repli telephonique ne joue que si aucun email ne correspond — et
     * dans ce cas rare le flash dira « ajoutee au suivi » au lieu de
     * « restauree ». Une mention en moins, jamais une affirmation fausse.
     *
     * Une requete par GESTE (jamais par ligne) : la garde de cout de la liste
     * (`TASK1640UserDeleteFromListTest`) mesure le RENDU et n'est pas concernee.
     */
    private function ficheEtaitSupprimee(Organization $organization, User $user): bool
    {
        $email = CrmContact::normalizeEmail($user->email);

        if ($email === null) {
            return false;
        }

        return CrmContact::onlyTrashed()
            ->forOrganization($organization)
            ->where('email', $email)
            ->exists();
    }

    /**
     * Le lien « voir la fiche », porte par une cle de session DISTINCTE.
     *
     * Mesure : le bandeau de `layouts/admin.blade.php` rend
     * `{{ session('success') }}` — donc ECHAPPE. Une ancre glissee dans le
     * message sortirait en texte brut. L'URL voyage donc a part, et le layout
     * en fait une vraie ancre.
     *
     * @return array{url: string, label: string}
     */
    private function lienVersLaFiche(Organization $organization, CrmContact $contact): array
    {
        return [
            'url' => $this->crmUrl($organization, 'contacts.show', ['contact' => $contact->id]),
            'label' => __('admin.crm_see_contact'),
        ];
    }
}
