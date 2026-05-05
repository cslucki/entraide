<?php

namespace App\Http\Controllers;

use App\Models\CommunityRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CommunityRequestController extends Controller
{
    public function create(): View
    {
        return view('community-requests.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'boucle_name'   => 'required|string|max:100',
            'contact_name'  => 'required|string|max:100',
            'contact_email' => 'required|email|max:150',
            'description'   => 'required|string|max:1000',
            'context'       => 'nullable|string|max:500',
        ]);

        if (auth()->check()) {
            $data['user_id']       = auth()->id();
            $data['contact_name']  = $data['contact_name']  ?: auth()->user()->name;
            $data['contact_email'] = $data['contact_email'] ?: auth()->user()->email;
        }

        CommunityRequest::create($data);

        return redirect()->route('boucles.index')
            ->with('success', "Votre demande de boucle « {$data['boucle_name']} » a bien été envoyée. Nous vous répondrons sous 48h.");
    }
}
