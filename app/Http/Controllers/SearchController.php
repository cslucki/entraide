<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SearchController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim($request->get('q', ''));

        if ($q === '') {
            return view('search', [
                'q' => '',
                'services' => collect(),
                'requests' => collect(),
                'users' => collect(),
                'posts' => collect(),
            ]);
        }

        $like = '%'.$q.'%';
        $likeOperator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $services = Service::with(['user', 'category'])
            ->where('status', 'active')
            ->where(fn ($query) => $query->where('title', $likeOperator, $like)
                ->orWhere('description', $likeOperator, $like)
            )
            ->latest()
            ->limit(5)
            ->get();

        $requests = ServiceRequest::with(['user', 'category'])
            ->where('status', 'open')
            ->where(fn ($query) => $query->where('title', $likeOperator, $like)
                ->orWhere('description', $likeOperator, $like)
            )
            ->latest()
            ->limit(5)
            ->get();

        $users = User::whereNull('banned_at')
            ->where(fn ($query) => $query->where('name', $likeOperator, $like)
                ->orWhere('location', $likeOperator, $like)
            )
            ->limit(5)
            ->get();

        $posts = BlogPost::published()
            ->with(['user', 'category', 'tags'])
            ->where(fn ($query) => $query->where('title', $likeOperator, $like)
                ->orWhere('content', $likeOperator, $like)
                ->orWhereHas('tags', fn ($q) => $q->where('name', $likeOperator, $like))
                ->orWhereHas('category', fn ($q) => $q->where('name_b2c', $likeOperator, $like)->orWhere('name_b2b', $likeOperator, $like))
            )
            ->latest('published_at')
            ->limit(5)
            ->get();

        return view('search', compact('q', 'services', 'requests', 'users', 'posts'));
    }
}
