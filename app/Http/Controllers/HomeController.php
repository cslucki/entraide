<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(): View
    {
        $stats = [
            'users'     => User::count(),
            'services'  => Service::where('status', 'active')->count(),
            'exchanges' => Transaction::where('status', 'completed')->count(),
        ];

        $featuredServices = Service::where('status', 'active')
            ->with('category', 'user')
            ->inRandomOrder()
            ->limit(6)
            ->get();

        return view('home', compact('stats', 'featuredServices'));
    }
}
