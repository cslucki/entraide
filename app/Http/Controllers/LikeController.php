<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Models\Like;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LikeController extends Controller
{
    public function toggle(Request $request): JsonResponse
    {
        $request->validate([
            'likeable_type' => 'required|in:blog_post',
            'likeable_id' => 'required|uuid',
        ]);

        $org = $this->resolveOrganization($request);

        if (! $org) {
            return response()->json(['error' => 'No organization context'], 400);
        }

        $modelClass = match ($request->likeable_type) {
            'blog_post' => BlogPost::class,
        };

        $model = $modelClass::where('organization_id', $org->id)
            ->findOrFail($request->likeable_id);

        $existing = Like::where('user_id', auth()->id())
            ->where('likeable_type', $modelClass)
            ->where('likeable_id', $model->id)
            ->where('organization_id', $org->id)
            ->first();

        if ($existing) {
            $existing->delete();
            $liked = false;
        } else {
            Like::create([
                'user_id' => auth()->id(),
                'likeable_type' => $modelClass,
                'likeable_id' => $model->id,
                'organization_id' => $org->id,
            ]);
            $liked = true;
        }

        $count = Like::where('likeable_type', $modelClass)
            ->where('likeable_id', $model->id)
            ->where('organization_id', $org->id)
            ->count();

        return response()->json(['liked' => $liked, 'count' => $count]);
    }

    private function resolveOrganization(Request $request): ?Organization
    {
        $routeOrg = $request->route('organization');

        if ($routeOrg instanceof Organization) {
            return $routeOrg;
        }

        if (is_string($routeOrg) && $routeOrg !== '') {
            return Organization::where('slug', $routeOrg)->first();
        }

        return currentOrganization();
    }
}
