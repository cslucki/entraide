<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim($request->get('q', ''));

        if ($q === '') {
            return view('search', [
                'q'        => '',
                'services' => collect(),
                'requests' => collect(),
                'users'    => collect(),
            ]);
        }

        $like = '%' . $q . '%';

        $services = Service::with(['user', 'category'])
            ->where('status', 'active')
            ->where(fn($query) =>
                $query->where('title', 'like', $like)
                      ->orWhere('description', 'like', $like)
            )
            ->latest()
            ->limit(5)
            ->get();

        $requests = ServiceRequest::with(['user', 'category'])
            ->where('status', 'open')
            ->where(fn($query) =>
                $query->where('title', 'like', $like)
                      ->orWhere('description', 'like', $like)
            )
            ->latest()
            ->limit(5)
            ->get();

        $users = User::whereNull('banned_at')
            ->where(fn($query) =>
                $query->where('name', 'like', $like)
                      ->orWhere('location', 'like', $like)
            )
            ->limit(5)
            ->get();

        return view('search', compact('q', 'services', 'requests', 'users'));
    }
}
