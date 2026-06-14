<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ServiceRequest::with(['user:id,name,rating,avatar', 'category:id,name_b2c,name_b2b,color'])
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

        return response()->json($requests);
    }

    public function show(string $id): JsonResponse
    {
        $serviceRequest = ServiceRequest::with([
            'user:id,name,rating,avatar,location,bio,is_available',
            'category:id,name_b2c,name_b2b,color',
        ])->open()->findOrFail($id);

        return response()->json($serviceRequest);
    }
}
