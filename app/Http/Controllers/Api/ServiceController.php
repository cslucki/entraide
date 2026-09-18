<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Support\Api\PublicUserProjection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Service::with([PublicUserProjection::relation(), 'category:id,name_b2c,name_b2b,color', 'tags:id,name'])
            ->active();

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

        if ($minCost = $request->get('min_cost')) {
            $query->where('points_cost', '>=', (int) $minCost);
        }

        if ($maxCost = $request->get('max_cost')) {
            $query->where('points_cost', '<=', (int) $maxCost);
        }

        $services = $query->latest()->paginate(15);

        // TASK-1491 : la liste passait deja une relation etroite, mais elle la
        // passait BRUTE. Elle emprunte desormais la meme autorite que la fiche —
        // une seule reponse a « qu'est-ce qui est public d'une personne ? ».
        $services->getCollection()->transform(fn (Service $service) => PublicUserProjection::applyTo(
            $service->toArray(),
            $service->user
        ));

        return response()->json($services);
    }

    public function show(string $id): JsonResponse
    {
        // TASK-1491 (P0 privacy) — cette relation chargeait `location` et `bio`.
        // `location` est le champ LEGACY que TASK-358 Lot 3 a retire de l'interface ;
        // `bio` ne s'affiche que sur la fiche de profil, fermee aux membres par
        // TASK-1479. L'API les rendait tous deux a un anonyme.
        $service = Service::with([
            PublicUserProjection::relation(),
            'category:id,name_b2c,name_b2b,color',
            'skills:id,name',
            'tags:id,name',
            'images:id,service_id,path,order',
        ])->active()->findOrFail($id);

        return response()->json(PublicUserProjection::applyTo($service->toArray(), $service->user));
    }
}
