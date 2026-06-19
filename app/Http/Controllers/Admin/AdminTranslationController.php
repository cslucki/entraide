<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\TranslationService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminTranslationController extends Controller
{
    public function index(Request $request, TranslationService $service): View
    {
        $group = $request->query('group');
        $status = $request->query('status');
        $search = $request->query('search');

        $entries = $service->all();
        $groups = $service->getGroups();

        if ($group && $group !== '_all') {
            $entries = $entries->where('group', $group);
        }

        if ($status && $status !== '_all') {
            $allowed = ['OK', 'MISSING_FR', 'MISSING_EN', 'EMPTY_FR', 'EMPTY_EN', 'NESTED'];
            if (in_array($status, $allowed)) {
                $entries = $entries->where('status', $status);
            }
        }

        if ($search) {
            $entries = $entries->filter(fn ($e) => str_contains(strtolower($e['key']), strtolower($search))
                || str_contains(strtolower($e['fr'] ?? ''), strtolower($search))
                || str_contains(strtolower($e['en'] ?? ''), strtolower($search)));
        }

        $stats = [
            'total' => $service->all()->count(),
            'ok' => $service->all()->where('status', 'OK')->count(),
            'missing_fr' => $service->all()->whereIn('status', ['MISSING_FR', 'EMPTY_FR'])->count(),
            'missing_en' => $service->all()->whereIn('status', ['MISSING_EN', 'EMPTY_EN'])->count(),
        ];

        return view('admin.translations.index', [
            'entries' => $entries,
            'groups' => $groups,
            'stats' => $stats,
            'activeGroup' => $group,
            'activeStatus' => $status,
            'search' => $search,
        ]);
    }
}
