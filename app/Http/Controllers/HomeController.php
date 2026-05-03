<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(): View
    {
        $stats = $this->getStats();
        $featuredServices = $this->getFeaturedServices();

        return view('home', compact('stats', 'featuredServices'));
    }

    public function homeV1(): View
    {
        $stats = $this->getStats();
        $featuredServices = $this->getFeaturedServices();
        $categories = Category::withCount(['services' => function ($query) {
            $query->where('status', 'active');
        }])->get();

        return view('home_v1', compact('stats', 'featuredServices', 'categories'));
    }

    public function homeV2(): View
    {
        $stats = $this->getStats();
        $featuredServices = $this->getFeaturedServices();

        return view('home_v2', compact('stats', 'featuredServices'));
    }

    public function homeV3(): View
    {
        $stats = $this->getStats();
        $featuredServices = $this->getFeaturedServices();

        return view('home_v3', compact('stats', 'featuredServices'));
    }

    private function getStats(): array
    {
        return [
            'users'     => User::count(),
            'services'  => Service::where('status', 'active')->count(),
            'exchanges' => Transaction::where('status', 'completed')->count(),
        ];
    }

    private function getFeaturedServices()
    {
        return Service::where('status', 'active')
            ->with('category', 'user')
            ->latest()
            ->limit(6)
            ->get();
    }
}
