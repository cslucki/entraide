<?php

namespace App\Http\Controllers;

use App\Models\Favorite;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FavoriteController extends Controller
{
    public function index(): View
    {
        $favorites = auth()->user()->favorites()
            ->with(['service.user', 'service.category', 'service.skills'])
            ->latest('created_at')
            ->paginate(15);

        return view('favorites.index', compact('favorites'));
    }

    public function toggle(Request $request, Service $service): JsonResponse|RedirectResponse
    {
        $user = auth()->user();
        $existing = Favorite::where('user_id', $user->id)
            ->where('service_id', $service->id)
            ->where('organization_id', $service->organization_id)
            ->first();

        if ($existing) {
            $existing->delete();
            $favorited = false;
        } else {
            Favorite::create([
                'user_id' => $user->id,
                'service_id' => $service->id,
                'organization_id' => $service->organization_id,
            ]);
            $favorited = true;
        }

        if ($request->wantsJson()) {
            return response()->json(['favorited' => $favorited]);
        }

        $message = $favorited ? 'Service ajouté aux favoris.' : 'Service retiré des favoris.';

        return back()->with('success', $message);
    }
}
