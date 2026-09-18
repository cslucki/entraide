<?php

namespace App\Http\Controllers\Admin;

use App\Models\CrmContact;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
}
