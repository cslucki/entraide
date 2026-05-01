<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\User;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function index(): Response
    {
        $services = Service::active()
            ->select('id', 'updated_at')
            ->latest('updated_at')
            ->get();

        $users = User::whereNull('banned_at')
            ->select('id', 'updated_at')
            ->latest('updated_at')
            ->get();

        $xml = view('sitemap', compact('services', 'users'))->render();

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
        ]);
    }
}
