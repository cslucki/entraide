<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceRequest;
use App\Support\Api\PublicUserProjection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ServiceRequest::with([PublicUserProjection::relation(), 'category:id,name_b2c,name_b2b,color'])
            ->open();

        if ($search = $request->get('q')) {
            $like = '%'.$search.'%';
            $query->where(fn ($q) => $q->where('title', 'like', $like)->orWhere('description', 'like', $like));
        }

        if ($category = $request->get('category_id')) {
            $query->where('category_id', $category);
        }

        if ($mode = $request->get('delivery_mode')) {
            $query->where('delivery_mode', $mode);
        }

        $requests = $query->latest()->paginate(15);

        // TASK-1491 : meme autorite que la fiche (voir PublicUserProjection).
        $requests->getCollection()->transform(fn (ServiceRequest $serviceRequest) => PublicUserProjection::applyTo(
            $serviceRequest->toArray(),
            $serviceRequest->user
        ));

        return response()->json($requests);
    }

    public function show(string $id): JsonResponse
    {
        // TASK-1491 (P0 privacy) — meme correction que la fiche de Service :
        // `location` (legacy, retire de l'UI par TASK-358 Lot 3) et `bio`
        // (visible des MEMBRES depuis TASK-1479) etaient rendus a un anonyme.
        $serviceRequest = ServiceRequest::with([
            PublicUserProjection::relation(),
            'category:id,name_b2c,name_b2b,color',
        ])->open()->findOrFail($id);

        return response()->json(PublicUserProjection::applyTo($serviceRequest->toArray(), $serviceRequest->user));
    }
}
