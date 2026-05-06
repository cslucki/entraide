<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Models\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LikeController extends Controller
{
    public function toggle(Request $request): JsonResponse
    {
        $request->validate([
            'likeable_type' => 'required|in:blog_post',
            'likeable_id'   => 'required|uuid',
        ]);

        $modelClass = match($request->likeable_type) {
            'blog_post' => BlogPost::class,
        };

        $model = $modelClass::findOrFail($request->likeable_id);

        $existing = Like::where('user_id', auth()->id())
            ->where('likeable_type', $modelClass)
            ->where('likeable_id', $model->id)
            ->first();

        if ($existing) {
            $existing->delete();
            $liked = false;
        } else {
            Like::create([
                'user_id'       => auth()->id(),
                'likeable_type' => $modelClass,
                'likeable_id'   => $model->id,
            ]);
            $liked = true;
        }

        $count = Like::where('likeable_type', $modelClass)->where('likeable_id', $model->id)->count();

        return response()->json(['liked' => $liked, 'count' => $count]);
    }
}
