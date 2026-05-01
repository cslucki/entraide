<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Service::with(['user:id,name,rating,avatar', 'category:id,name,color', 'tags:id,name'])
            ->active();

        if ($search = $request->get('q')) {
            $like = '%' . $search . '%';
            $query->where(fn($q) => $q->where('title', 'like', $like)->orWhere('description', 'like', $like));
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

        return response()->json($services);
    }

    public function show(string $id): JsonResponse
    {
        $service = Service::with([
            'user:id,name,rating,avatar,location,bio,is_available',
            'category:id,name,color',
            'skills:id,name',
            'tags:id,name',
            'images:id,service_id,path,order',
        ])->active()->whereUuid('id')->findOrFail($id);

        return response()->json($service);
    }
}
