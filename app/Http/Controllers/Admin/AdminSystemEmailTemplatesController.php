<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\SystemEmailTemplate;
use App\Services\EmailerService;
use Database\Seeders\SystemEmailTemplateSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminSystemEmailTemplatesController extends Controller
{
    /**
     * TASK-1694 bug O — la valeur qui designe « sans Organization ».
     *
     * Hors de l'espace des UUID : aucune Organization ne peut la porter, et une
     * chaine forgee ne peut donc pas se faire passer pour elle.
     */
    public const FILTRE_GLOBAL = 'global';

    /** TASK-1795 correctif 10 — la langue ouverte par defaut sur cet ecran. */
    public const LOCALE_PAR_DEFAUT = 'fr';

    public function index(Request $request): View
    {
        $query = SystemEmailTemplate::with('organization')->orderBy('name');

        // TASK-1694 bug O — « Global » est une VALEUR du filtre, pas son absence.
        //
        // Le filtre vide veut dire « toutes », ce qui ne permettait pas de
        // demander « seulement les Global » — l'ecran ne savait donc pas montrer
        // la cible visee par la decision de Cyril (n'avoir que du Global). Le
        // mot-clef `global` est hors de l'espace des UUID : aucune Organization
        // ne peut le porter.
        if ($request->input('organization_id') === self::FILTRE_GLOBAL) {
            $query->whereNull('organization_id');
        } elseif ($request->filled('organization_id')) {
            $query->where('organization_id', $request->organization_id);
        }

        // TASK-1795 correctif 10 — la LANGUE par defaut est le francais.
        //
        // Cyril : « pour "Locale" sur "FR" par défaut ». Sans defaut, l'ecran
        // ouvrait les 40 lignes — 20 slugs vus DEUX fois, une par langue — et
        // la liste se lisait comme si chaque gabarit etait en double.
        //
        // `has()` et non `filled()` : c'est la distinction qui garde « Toutes
        // les langues » ATTEIGNABLE. Cette option poste `locale=` (present, mais
        // vide) ; avec `filled()` la valeur vide serait retombee sur le defaut
        // `fr`, et l'option n'aurait jamais rien pu faire — un choix offert et
        // refuse. Absent de la requete = premiere visite = `fr` ; present et
        // vide = demande explicite de tout voir.
        //
        // Le filtre Organization, lui, n'a PAS de defaut a poser : vide vaut
        // deja « Toutes les organisations », ce que l'ecran fait depuis
        // toujours.
        $localeSelectionnee = $request->has('locale')
            ? (string) $request->input('locale')
            : self::LOCALE_PAR_DEFAUT;

        if ($localeSelectionnee !== '') {
            $query->where('locale', $localeSelectionnee);
        }

        // TASK-1659 : permet d'arriver directement sur les gabarits d'UNE
        // fonctionnalite (ex. « Gérer le modèle d'email » depuis
        // /admin/users/bulk-create), plutot que sur la liste entiere.
        if ($request->filled('slug')) {
            $query->where('slug', $request->slug);
        }

        $templates = $query->get();
        $organizations = Organization::orderBy('name')->get();

        // TASK-1694 bug AD — combien de gabarits, et combien le filtre en cache.
        //
        // Demande de Cyril : « ajouter un compteur pour indiquer le nombre de
        // messages types ». Deux nombres, pas un : le compte AFFICHE seul
        // laisserait croire que la base n'en contient pas davantage des qu'un
        // filtre est pose — exactement le genre de chiffre qu'on lit de travers.
        //
        // Le total porte sur la TABLE entiere, jamais sur le filtre courant.
        $compteurs = [
            'affiches' => $templates->count(),
            'total' => SystemEmailTemplate::query()->count(),
            'globaux' => SystemEmailTemplate::query()->whereNull('organization_id')->count(),
            'tenants' => SystemEmailTemplate::query()->whereNotNull('organization_id')->count(),
        ];

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

        return view('admin.system-email-templates.index', compact('templates', 'organizations', 'defaultTemplate', 'compteurs', 'localeSelectionnee'));
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
            // TASK-1694 bug O — FACULTATIVE : vide veut dire « Global ».
            //
            // C'etait `required`, et c'est la cause mecanique du desordre
            // constate le 23/10 : l'ecran SuperAdmin ne SAVAIT PAS creer une
            // ligne globale. Toute creation naissait donc rattachee a une
            // Organization, meme quand le texte n'avait rien de propre a elle.
            'organization_id' => ['nullable', 'uuid', Rule::exists('organizations', 'id')],
            'locale' => ['required', Rule::in(['fr', 'en'])],
        ]);

        $template = SystemEmailTemplate::firstOrCreate(
            [
                'organization_id' => $validated['organization_id'] ?? null,
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

    /**
     * TASK-1809 (micro-sequence C) — l'apercu REEL d'un gabarit, variables
     * de demonstration interpolees, habille par le MEME theme que l'envoi
     * reel.
     *
     * Avant ce correctif, l'iframe de `edit.blade.php` affichait le HTML
     * BRUT du textarea — ni habillage, ni interpolation (les `{{ token }}`
     * restaient litteraux) — un apercu qui ne ressemblait a rien de ce
     * qu'un destinataire recoit vraiment.
     *
     * Prend `subject`/`content_html` dans la REQUETE, jamais ceux deja
     * enregistres : l'administrateur doit pouvoir visualiser une
     * modification AVANT de l'enregistrer.
     *
     * N'envoie RIEN — aucun appel a `Mail`, uniquement le rendu de
     * `Illuminate\Mail\Markdown`, la meme bibliotheque que les envois
     * reels des notifications natives utilisent deja.
     */
    public function preview(Request $request, SystemEmailTemplate $systemEmailTemplate): JsonResponse
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'content_html' => 'required|string',
        ]);

        $locale = $systemEmailTemplate->locale ?: self::LOCALE_PAR_DEFAUT;
        app()->setLocale($locale);

        $cles = $systemEmailTemplate->variables ?? [];
        $demo = $this->variablesDeDemonstration($cles, $locale);

        $emailer = app(EmailerService::class);
        $subject = $emailer->interpolateSubject($validated['subject'], $demo, $cles);
        $html = $emailer->interpolate($validated['content_html'], $demo, $cles);

        $rendu = (string) app(Markdown::class)->render('emails.system-email', ['html' => $html]);

        return response()->json(['subject' => $subject, 'html' => $rendu]);
    }

    /**
     * Une valeur plausible par variable DECLAREE par le gabarit — jamais une
     * donnee reelle, jamais un envoi : c'est uniquement ce que `preview()`
     * interpole pour donner a voir un rendu credible.
     *
     * @param  list<string>  $cles
     * @return array<string, string>
     */
    private function variablesDeDemonstration(array $cles, string $locale): array
    {
        $fr = $locale !== 'en';

        $connues = [
            'app_name' => (string) config('app.name'),
            'organization_name' => $fr ? 'Entraide du Quartier' : 'Neighbourhood Mutual Aid',
            'organization' => $fr ? 'Entraide du Quartier' : 'Neighbourhood Mutual Aid',
            'organisation' => $fr ? 'Entraide du Quartier' : 'Neighbourhood Mutual Aid',
            'organization_slug' => 'entraide-du-quartier',
            'organisation_slug' => 'entraide-du-quartier',
            'recipient_name' => $fr ? 'Camille Durand' : 'Jordan Smith',
            'sender_name' => $fr ? 'Alex Martin' : 'Alex Martin',
            'aidant_name' => $fr ? 'Alex Martin' : 'Alex Martin',
            'aidant_first_name' => 'Alex',
            'name' => $fr ? 'Camille Durand' : 'Jordan Smith',
            'full_name' => $fr ? 'Camille Durand' : 'Jordan Smith',
            'first_name' => $fr ? 'Camille' : 'Jordan',
            'email' => 'camille.durand@example.com',
            'city' => $fr ? 'Lyon' : 'London',
            'loop_name' => $fr ? 'Entraide du quartier' : 'Neighbourhood Circle',
            'loop_tagline' => $fr ? 'Ensemble, on va plus loin' : 'Together we go further',
            'personal_message' => $fr ? 'Au plaisir de vous accueillir parmi nous !' : 'Looking forward to welcoming you!',
            'expires_at' => now()->addDays(7)->isoFormat('LL'),
        ];

        $demo = [];

        foreach ($cles as $cle) {
            if (isset($connues[$cle])) {
                $demo[$cle] = $connues[$cle];

                continue;
            }

            $demo[$cle] = match (true) {
                str_ends_with($cle, '_url'), $cle === 'url' => '#apercu',
                default => $fr ? 'Exemple' : 'Example',
            };
        }

        return $demo;
    }
}
