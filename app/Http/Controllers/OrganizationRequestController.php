<?php

namespace App\Http\Controllers;

use App\Models\OrganizationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrganizationRequestController extends Controller
{
    public function create(): View
    {
        return view('organization-requests.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'boucle_name' => 'required|string|max:100',
            'contact_name' => 'required|string|max:100',
            'contact_email' => 'required|email|max:150',
            'contact_phone' => 'required|string|max:30',
            'website_url' => 'nullable|url|max:255',
            'description' => 'required|string|max:1000',
            'context' => 'nullable|string|max:500',
        ]);

        if (auth()->check()) {
            $data['user_id'] = auth()->id();
            $data['contact_name'] = $data['contact_name'] ?: auth()->user()->name;
            $data['contact_email'] = $data['contact_email'] ?: auth()->user()->email;
        }

        OrganizationRequest::create($data);

        return redirect()->route('partenaires.index')
            ->with('success', "Votre demande de partenariat « {$data['boucle_name']} » a bien été envoyée. Nous vous répondrons sous 48h.");
    }
}
