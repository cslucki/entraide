<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Support\Ai\AiQualityReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * TASK-1487 (AI Quality Q2) — la console « Qualite IA » cote PLATEFORME.
 *
 * Meme lecture que la console Organization, agregee sur toutes les
 * Organizations, avec un filtre. Elle ne lit aucune conversation, aucune cle,
 * aucun secret : uniquement des COMPTES par fonction.
 *
 * Le filtre est une lecture, pas un droit : le SuperAdmin a deja l'acces
 * transverse que `AdminMiddleware` accorde ; restreindre l'affichage a une
 * Organization ne lui ouvre rien de plus.
 */
class AdminAiQualityController extends Controller
{
    public function index(Request $request, AiQualityReport $report): View
    {
        $to = CarbonImmutable::now();
        $from = $to->subDays(30);

        $slug = trim((string) $request->query('organization', ''));
        $only = $slug !== '' ? Organization::query()->where('slug', $slug)->first() : null;

        return view('admin.ai-quality', [
            'quality' => $report->forPlatform($from, $to, $only),
            'organizations' => Organization::query()->where('is_active', true)->orderBy('name')->get(['id', 'slug', 'name']),
            'selected' => $only,
        ]);
    }
}
