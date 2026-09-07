<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Services\Crm\CrmEmailTemplateService;
use App\Services\EmailerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * TASK-1420 — CRM-7a : les modeles d'email de l'Organization.
 *
 * L'OrgAdmin liste, cree, edite et previsualise UNIQUEMENT les
 * `email_templates` dont `organization_id` est celui de la route. Jamais les
 * modeles globaux (`organization_id` NULL), jamais ceux d'une autre
 * Organization, jamais les `system_email_templates` (qui restent le domaine
 * des notifications). Un modele d'ailleurs donne 404.
 *
 * Aucun envoi ici : la preview interpole et affiche, rien d'autre. L'envoi a
 * un Contact est CRM-7b.
 */
class OrgCrmTemplateController extends Controller
{
    public function __construct(
        private readonly CrmEmailTemplateService $templates,
        private readonly EmailerService $emailer,
    ) {}

    public function index(Organization $organization): View
    {
        return view('admin.org.crm.templates.index', [
            'organization' => $organization,
            'templates' => $this->templates->forOrganization($organization)->withCount('logs')->orderBy('name')->get(),
        ]);
    }

    public function create(Organization $organization): View
    {
        return view('admin.org.crm.templates.form', [
            'organization' => $organization,
            'template' => null,
            'variables' => CrmEmailTemplateService::CONTACT_VARIABLES,
        ]);
    }

    public function store(Request $request, Organization $organization): RedirectResponse
    {
        $data = $this->validated($request);

        $template = $this->templates->create($organization, $data['name'], $data['subject'], $data['content_html']);

        return redirect()->route('organization.admin.crm.templates.edit', ['organization' => $organization->slug, 'template' => $template->id])
            ->with('success', __('crm.templates.flash_created'));
    }

    public function edit(Organization $organization, string $template): View
    {
        $template = $this->templates->resolve($organization, $template);

        return view('admin.org.crm.templates.form', [
            'organization' => $organization,
            'template' => $template,
            'variables' => CrmEmailTemplateService::CONTACT_VARIABLES,
        ]);
    }

    public function update(Request $request, Organization $organization, string $template): RedirectResponse
    {
        $template = $this->templates->resolve($organization, $template);
        $data = $this->validated($request);

        $this->templates->update($template, $data['name'], $data['subject'], $data['content_html']);

        return back()->with('success', __('crm.templates.flash_updated'));
    }

    /**
     * Lecture seule : interpolation avec un Contact d'exemple. Aucune
     * EmailLog, aucune timeline, aucun envoi.
     */
    public function preview(Organization $organization, string $template): View
    {
        $template = $this->templates->resolve($organization, $template);
        $sample = $this->templates->sampleVariables($organization);

        return view('admin.org.crm.templates.preview', [
            'organization' => $organization,
            'template' => $template,
            'subject' => $this->emailer->interpolateSubject($template->subject, $sample, CrmEmailTemplateService::EXTRA_ALLOWED_VARS),
            'html' => $this->emailer->interpolate($template->content_html, $sample, CrmEmailTemplateService::EXTRA_ALLOWED_VARS),
        ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'subject' => ['required', 'string', 'max:200'],
            'content_html' => ['required', 'string', 'max:20000'],
        ]);
    }
}
