<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\ServiceRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RequestController extends Controller
{
    public function show(ServiceRequest $request): View
    {
        $request->load(['user', 'category']);
        return view('requests.show', compact('request'));
    }

    public function create(): View
    {
        $categories = Category::with('pointGuidelines')->get();
        return view('requests.create', compact('categories'));
    }

    public function store(Request $httpRequest): RedirectResponse
    {
        $data = $httpRequest->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'category_id' => 'required|uuid|exists:categories,id',
            'delivery_mode' => 'required|in:remote,onsite,both',
            'budget_min' => 'required|integer|min:1',
            'budget_max' => 'nullable|integer|gte:budget_min',
            'deadline' => 'nullable|date|after:today',
        ]);

        ServiceRequest::create(array_merge($data, [
            'user_id' => auth()->id(),
            'status' => 'open',
        ]));

        return redirect()->route('dashboard')->with('success', 'Demande publiée avec succès.');
    }

    public function destroy(ServiceRequest $request): RedirectResponse
    {
        $this->authorize('delete', $request);

        $request->update(['status' => 'closed']);

        return redirect()->route('dashboard')->with('success', 'Demande fermée.');
    }
}
