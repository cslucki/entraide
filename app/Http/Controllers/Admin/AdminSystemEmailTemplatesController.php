<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\SystemEmailTemplate;
use Database\Seeders\SystemEmailTemplateSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminSystemEmailTemplatesController extends Controller
{
    public function index(Request $request): View
    {
        $query = SystemEmailTemplate::with('organization')->orderBy('name');

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->organization_id);
        }

        if ($request->filled('locale')) {
            $query->where('locale', $request->locale);
        }

        // TASK-1659 : permet d'arriver directement sur les gabarits d'UNE
        // fonctionnalite (ex. « Gérer le modèle d'email » depuis
        // /admin/users/bulk-create), plutot que sur la liste entiere.
        if ($request->filled('slug')) {
            $query->where('slug', $request->slug);
        }

        $templates = $query->get();
        $organizations = Organization::orderBy('name')->get();

        // TASK-1681 — arrive filtre par slug, l'ecran doit montrer que le
        // MODELE SYSTEME PAR DEFAUT existe (le repli Blade du mailer), meme
        // quand aucune surcharge DB n'a jamais ete creee : une liste vide
        // laissait croire que le modele n'existait pas. Le catalogue
        // canonique est celui du seeder, LU seulement — rien n'est seme.
        $defaultTemplate = null;

        if ($request->filled('slug') && array_key_exists($request->slug, SystemEmailTemplateSeeder::definitions())) {
            $def = SystemEmailTemplateSeeder::definitions()[$request->slug];

            $defaultTemplate = [
                'slug' => $request->slug,
                'name' => app()->getLocale() === 'en'
                    ? ($def['name_en'] ?? $def['name'])
                    : ($def['name_fr'] ?? $def['name']),
                // « Utilise actuellement » n'est vrai sans nuance que si
                // AUCUNE surcharge active n'existe pour ce slug.
                'has_enabled_override' => SystemEmailTemplate::where('slug', $request->slug)
                    ->where('enabled', true)
                    ->exists(),
            ];
        }

        return view('admin.system-email-templates.index', compact('templates', 'organizations', 'defaultTemplate'));
    }

    /**
     * TASK-1681 — creer UNE personnalisation, de maniere SURE.
     *
     * Pre-remplie depuis le catalogue canonique (styles inline compris) et
     * creee DESACTIVEE : la regression historique du 01/10 venait d'un
     * gabarit seme en HTML nu qui prenait immediatement le pas sur le repli
     * Blade style. Ici l'admin relit, modifie, puis ACTIVE explicitement —
     * tant que la surcharge est desactivee, le mailer reste sur le Blade.
     * firstOrCreate : jamais de doublon (slug, Organization, locale).
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'slug' => ['required', 'string', Rule::in(array_keys(SystemEmailTemplateSeeder::definitions()))],
            'organization_id' => ['required', 'uuid', Rule::exists('organizations', 'id')],
            'locale' => ['required', Rule::in(['fr', 'en'])],
        ]);

        $template = SystemEmailTemplate::firstOrCreate(
            [
                'organization_id' => $validated['organization_id'],
                'locale' => $validated['locale'],
                'slug' => $validated['slug'],
            ],
            SystemEmailTemplateSeeder::rowFor($validated['slug'], $validated['locale']) + ['enabled' => false],
        );

        return redirect()
            ->route('admin.system-email-templates.edit', [
                'systemEmailTemplate' => $template,
                'redirect' => route('admin.system-email-templates', ['slug' => $validated['slug']]),
            ])
            ->with(
                $template->wasRecentlyCreated ? 'success' : 'info',
                $template->wasRecentlyCreated
                    ? __('admin.system_email_customization_created')
                    : __('admin.system_email_customization_exists'),
            );
    }

    public function edit(SystemEmailTemplate $systemEmailTemplate): View
    {
        return view('admin.system-email-templates.edit', compact('systemEmailTemplate'));
    }

    public function update(Request $request, SystemEmailTemplate $systemEmailTemplate): RedirectResponse
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'content_html' => 'required|string',
            'enabled' => 'boolean',
        ]);

        $validated['enabled'] = $request->boolean('enabled');

        $systemEmailTemplate->update($validated);

        $redirect = $request->input('redirect', route('admin.system-email-templates'));

        return redirect($redirect)
            ->with('success', __('admin.emailer_updated'));
    }
}
