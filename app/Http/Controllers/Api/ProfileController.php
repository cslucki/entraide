<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'full_name' => $user->fullName,
            'email' => $user->email,
            'bio' => $user->bio,
            'city' => $user->city,
            'country_code' => $user->country_code,
            'phone' => $user->phone,
            'points_balance' => $user->points_balance,
            'is_available' => $user->is_available,
            'rating' => $user->rating,
            'avatar_url' => $user->avatar_url,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:500'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2', 'exists:countries,code'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'is_available' => ['sometimes', 'boolean'],
        ]);

        $request->user()->update($data);

        return $this->me($request);
    }

    public function show(User $user): JsonResponse
    {
        if ($user->banned_at !== null) {
            return response()->json(['message' => 'Profil non disponible.'], 404);
        }

        $services = $user->services()
            ->with('category:id,name_b2c,name_b2b,color')
            ->active()
            ->latest()
            ->limit(10)
            ->get(['id', 'title', 'points_cost', 'delivery_mode', 'category_id']);

        $reviews = $user->reviewsReceived()
            ->with('reviewer:id,name,avatar')
            ->latest()
            ->limit(5)
            ->get(['id', 'rating', 'comment', 'reviewer_id', 'created_at']);

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'full_name' => $user->fullName,
            'bio' => $user->bio,
            'city' => $user->city,
            'country_code' => $user->country_code,
            'is_available' => $user->is_available,
            'rating' => $user->rating,
            'avatar_url' => $user->avatar_url,
            'services' => $services,
            'reviews' => $reviews,
        ]);
    }
}
